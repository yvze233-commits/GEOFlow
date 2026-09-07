<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CollectAiVisibilityKeywordJob;
use App\Jobs\DetectAiVisibilityCompetitorsJob;
use App\Models\AiVisibilityCompetitor;
use App\Models\AiVisibilityRun;
use App\Models\AiVisibilitySource;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Services\Admin\Analytics\AiVisibilityAnalyticsFilter;
use App\Services\Admin\Analytics\AiVisibilityAnalyticsService;
use App\Services\Admin\Analytics\AiVisibilityCompetitorDetectionService;
use App\Services\Admin\Analytics\AiVisibilityCompetitorReportService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AiVisibilityAnalyticsController extends Controller
{
    public function __construct(
        private readonly AiVisibilityAnalyticsService $analytics,
        private readonly AiVisibilityCompetitorReportService $competitorReport,
    ) {}

    public function __invoke(Request $request): View
    {
        $filter = AiVisibilityAnalyticsFilter::fromRequest($request->query());

        return view('admin.analytics.ai-visibility', [
            'pageTitle' => __('admin.analytics.pages.ai_visibility.title'),
            'activeMenu' => 'analytics',
            'analyticsPage' => 'ai-visibility',
            'adminSiteName' => AdminWeb::siteName(),
            'filters' => $filter,
            'filterOptions' => [
                'keywords' => Schema::hasTable('ai_visibility_runs')
                    ? AiVisibilityRun::query()->whereIn('provider_type', AiVisibilityRun::SAMPLE_PROVIDERS)->whereNotNull('keyword')->where('keyword', '!=', '')->distinct()->orderBy('keyword')->limit(1000)->pluck('keyword')
                    : collect(),
                'providers' => [
                    AiVisibilityRun::PROVIDER_DOUBAO_ARK_RESPONSES,
                    AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
                    AiVisibilityRun::PROVIDER_DEEPSEEK_ANALYSIS,
                ],
            ],
            'aiVisibilityOverview' => $this->analytics->overview($filter),
            'keywordLibraries' => $this->keywordLibraries(),
            'competitorReport' => Schema::hasTable('ai_visibility_competitors')
                ? $this->competitorReport->stats(30)
                : null,
            'competitors' => Schema::hasTable('ai_visibility_competitors')
                ? AiVisibilityCompetitor::query()->select('id', 'name', 'aliases', 'is_active', 'source')->lazyById(200)->collect()
                : collect(),
            'topCitedUrls' => Schema::hasTable('ai_visibility_sources')
                ? $this->topCitedUrls(30, 10)
                : collect(),
            'aiVisibilitySchedule' => Schema::hasTable('ai_visibility_schedules')
                ? DB::table('ai_visibility_schedules')->orderBy('id')->first()
                : null,
            'recentTraces' => Schema::hasTable('ai_visibility_runs')
                ? $this->recentCallTraces(10)
                : collect(),
        ]);
    }

    /**
     * 最近完成的调用记录及其调用链路细节(接口端点/模型/request id/时间/请求与响应摘要),
     * 供核对"实际调用了哪个接口、模型、何时调用"。
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function recentCallTraces(int $limit): Collection
    {
        // 从用量账本取 OpenAI 兼容调用的 request id(deepseek 分析等)
        $attemptRequestIds = [];
        try {
            $attemptRequestIds = DB::table('ai_model_usage_attempt_starts')
                ->where('business_source', 'ai_visibility_collection')
                ->pluck('request_id', 'source_id')
                ->all();
        } catch (Throwable $e) {
            // 账本表缺失时忽略,不影响展示
        }

        return AiVisibilityRun::query()
            ->where('status', 'completed')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(function (AiVisibilityRun $run) use ($attemptRequestIds): array {
                $rawRequest = is_array($run->raw_request_json) ? $run->raw_request_json : [];
                $rawResponse = is_array($run->raw_response_json) ? $run->raw_response_json : [];
                $analysis = is_array($run->analysis_json) ? $run->analysis_json : [];
                $usage = is_array($run->usage_json) ? $run->usage_json : [];

                $isSearch = $run->provider_key === 'doubao_search_custom';

                // 端点与模型
                if ($isSearch) {
                    $endpoint = (string) ($rawRequest['endpoint'] ?? '');
                    $model = '-';
                } else {
                    $endpoint = (string) ($rawRequest['provider_url'] ?? '');
                    $model = (string) ($run->model_id ?: '');
                }

                // request id: 豆包搜索取火山 log_id / ResponseMetadata.RequestId;模型调用取用量账本 request_id
                $requestId = (string) ($analysis['log_id'] ?? '')
                    ?: (string) (($rawResponse['ResponseMetadata']['RequestId'] ?? '') ?: '')
                    ?: (string) ($attemptRequestIds[(int) $run->id] ?? '');

                // 请求体摘要
                $promptExcerpt = '';
                if ($isSearch) {
                    $query = $rawRequest['payload']['Query'] ?? null;
                    $promptExcerpt = is_string($query) ? mb_substr($query, 0, 120) : '';
                } else {
                    $prompt = $rawRequest['prompt'] ?? '';
                    $promptExcerpt = is_string($prompt) ? mb_substr(preg_replace('/\s+/u', ' ', $prompt) ?: '', 0, 160) : '';
                }

                // 响应摘要
                $responseExcerpt = '';
                if ($isSearch) {
                    $responseExcerpt = sprintf('%d 条搜索结果', (int) ($analysis['result_count'] ?? 0));
                } else {
                    $text = $rawResponse['text'] ?? '';
                    $responseExcerpt = is_string($text)
                        ? mb_substr(preg_replace('/\s+/u', ' ', $text) ?: '', 0, 160)
                        : '';
                }

                $tokens = '';
                if (isset($usage['prompt_tokens']) || isset($usage['completion_tokens'])) {
                    $tokens = sprintf('in %s / out %s',
                        (string) ($usage['prompt_tokens'] ?? '0'),
                        (string) ($usage['completion_tokens'] ?? '0'),
                    );
                }

                return [
                    'id' => (int) $run->id,
                    'keyword' => (string) $run->keyword,
                    'provider_key' => (string) $run->provider_key,
                    'stage_label' => $isSearch ? __('admin.analytics.ai_visibility.traces.stage_search') : __('admin.analytics.ai_visibility.traces.stage_analysis'),
                    'endpoint' => $endpoint,
                    'model' => $model,
                    'request_id' => $requestId,
                    'started_at' => (string) ($run->started_at ?? ''),
                    'completed_at' => (string) ($run->completed_at ?? ''),
                    'latency_ms' => (int) $run->latency_ms,
                    'tokens' => $tokens,
                    'status' => (string) $run->status,
                    'prompt_excerpt' => $promptExcerpt,
                    'response_excerpt' => $responseExcerpt,
                    'usage' => $usage,
                ];
            })
            ->values();
    }

    /**
     * 从关键词库勾选关键词，派发后台队列批量采集。
     */
    public function collect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'keyword_ids' => ['required', 'array', 'min:1', 'max:50'],
            'keyword_ids.*' => ['integer'],
        ]);

        $keywords = collect(Arr::wrap($data['keyword_ids']))
            ->map(static fn ($id): string => trim((string) $id))
            ->filter(static fn (string $id): bool => preg_match('/^\d+$/', $id) === 1)
            ->values();

        $keywords = Keyword::query()
            ->whereIn('id', $keywords)
            ->pluck('keyword')
            ->map(static fn ($keyword): string => trim((string) $keyword))
            ->filter(static fn (string $keyword): bool => $keyword !== '' && mb_strlen($keyword) <= 100)
            ->unique()
            ->values();

        if ($keywords->isEmpty()) {
            return back()->withErrors(__('admin.analytics.ai_visibility.collect.empty'));
        }

        foreach ($keywords as $keyword) {
            CollectAiVisibilityKeywordJob::dispatch($keyword);
        }

        return back()->with(
            'message',
            __('admin.analytics.ai_visibility.collect.queued', ['count' => $keywords->count()]),
        );
    }

    public function storeCompetitor(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'aliases' => ['nullable', 'string', 'max:500'],
        ]);

        $aliases = collect((array) preg_split('/[,，;；\n]+/u', (string) ($data['aliases'] ?? '')))
            ->map(static fn ($alias): string => trim((string) $alias))
            ->filter(static fn (string $alias): bool => $alias !== '')
            ->unique()
            ->values()
            ->all();

        AiVisibilityCompetitor::query()->updateOrCreate(
            ['name' => Str::limit(trim($data['name']), 120, '')],
            ['aliases' => $aliases, 'is_active' => true, 'source' => 'manual'],
        );

        return back()->with('message', __('admin.analytics.ai_visibility.competitors.saved'));
    }

    public function destroyCompetitor(Request $request, int $competitor): RedirectResponse
    {
        AiVisibilityCompetitor::query()->whereKey($competitor)->delete();

        return back()->with('message', __('admin.analytics.ai_visibility.competitors.deleted'));
    }

    /**
     * 派发后台队列,用 AI 从最近的采样回答中自动识别竞品品牌。
     */
    public function detectCompetitors(AiVisibilityCompetitorDetectionService $detection): RedirectResponse
    {
        foreach ($detection->pendingRunIds(12) as $runId) {
            DetectAiVisibilityCompetitorsJob::dispatch($runId);
        }

        return back()->with('message', __('admin.analytics.ai_visibility.competitors.detect_queued'));
    }

    /**
     * 保存自动采集配置(启用开关、每天采集次数、采集关键词范围)。
     */
    public function saveSchedule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['nullable'],
            'frequency' => ['required', 'integer', 'in:1,2,3,4'],
            'keyword_ids' => ['nullable', 'array'],
            'keyword_ids.*' => ['integer'],
        ]);

        $timesByFrequency = [
            1 => ['08:00'],
            2 => ['08:00', '20:00'],
            3 => ['08:00', '14:00', '20:00'],
            4 => ['02:00', '08:00', '14:00', '20:00'],
        ];

        $keywordIds = collect(Arr::wrap($data['keyword_ids'] ?? []))
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $enabled = array_key_exists('enabled', $data);

        DB::table('ai_visibility_schedules')->updateOrInsert(
            ['id' => 1],
            [
                'enabled' => $enabled,
                'times_json' => json_encode($timesByFrequency[(int) $data['frequency']]),
                'keyword_ids_json' => $keywordIds === [] ? null : json_encode($keywordIds),
                'updated_at' => now(),
            ],
        );

        return back()->with('message', __('admin.analytics.ai_visibility.schedule.saved'));
    }

    /**
     * 近 N 天被 AI 回答引用最多的具体网址(可点击跳转)。
     *
     * @return Collection<int, array{url: string, title: string, domain: string, citations: int}>
     */
    private function topCitedUrls(int $days, int $limit): Collection
    {
        return AiVisibilitySource::query()
            ->whereHas('run', static fn ($query) => $query
                ->whereIn('provider_type', AiVisibilityRun::SAMPLE_PROVIDERS)
                ->where('status', 'completed')
                ->where('completed_at', '>=', now()->subDays($days)))
            ->where(static fn ($query) => $query->where('url', 'like', 'https://%')->orWhere('url', 'like', 'http://%'))
            ->selectRaw('url, MIN(title) as title, COUNT(*) as citations')
            ->groupBy('url')->orderByDesc('citations')->orderBy('url')->limit($limit)
            ->get()->filter(static fn ($source): bool => filter_var($source->url, FILTER_VALIDATE_URL) !== false
                && in_array(strtolower((string) parse_url($source->url, PHP_URL_SCHEME)), ['http', 'https'], true))
            ->map(static fn ($source): array => [
                'url' => (string) $source->url,
                'title' => (string) ($source->title ?: $source->url),
                'domain' => (string) parse_url($source->url, PHP_URL_HOST),
                'citations' => (int) $source->citations,
            ])->values();
    }

    /**
     * @return Collection<int, array{id: int, name: string, keywords: list<array{id: int, keyword: string}>}>
     */
    private function keywordLibraries(): Collection
    {
        if (! Schema::hasTable('keyword_libraries') || ! Schema::hasTable('keywords')) {
            return collect();
        }

        $keywords = Keyword::query()->where('keyword', '!=', '')->orderBy('id')->limit(1000)
            ->get(['id', 'library_id', 'keyword'])->groupBy('library_id');

        return KeywordLibrary::query()->whereIn('id', $keywords->keys())->orderBy('id')
            ->get(['id', 'name'])
            ->map(static fn (KeywordLibrary $library): array => [
                'id' => $library->id,
                'name' => $library->name,
                'keywords' => $keywords->get($library->id, collect())
                    ->map(static fn (Keyword $keyword): array => ['id' => $keyword->id, 'keyword' => (string) $keyword->keyword])
                    ->values()->all(),
            ])->values();
    }
}
