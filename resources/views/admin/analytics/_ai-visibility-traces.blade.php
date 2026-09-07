@php
    $aiVisibilityTraces = $recentTraces ?? collect();
@endphp

<section class="mt-6 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
    <div class="p-5">
        <h3 class="text-lg font-semibold text-gray-950">{{ __('admin.analytics.ai_visibility.traces.panel_title') }}</h3>
        <p class="mt-1 text-sm text-gray-500">{{ __('admin.analytics.ai_visibility.traces.panel_desc') }}</p>
    </div>

    @if ($aiVisibilityTraces->isEmpty())
        <p class="px-5 pb-5 text-sm text-gray-500">{{ __('admin.analytics.ai_visibility.traces.empty') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-5 py-2.5 text-left font-semibold text-gray-600">{{ __('admin.analytics.ai_visibility.traces.stage_search') }} / {{ __('admin.analytics.ai_visibility.traces.stage_analysis') }}</th>
                        <th class="px-5 py-2.5 text-left font-semibold text-gray-600">{{ __('admin.analytics.ai_visibility.traces.time') }}</th>
                        <th class="px-5 py-2.5 text-left font-semibold text-gray-600">{{ __('admin.analytics.ai_visibility.traces.endpoint') }}</th>
                        <th class="px-5 py-2.5 text-left font-semibold text-gray-600">{{ __('admin.analytics.ai_visibility.traces.model') }}</th>
                        <th class="px-5 py-2.5 text-right font-semibold text-gray-600"><span class="sr-only">{{ __('admin.analytics.ai_visibility.traces.copy') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($aiVisibilityTraces as $trace)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-2.5">
                                <p class="font-medium text-gray-900">{{ $trace['keyword'] }}</p>
                                <span class="mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium {{ $trace['provider_key'] === 'doubao_search_custom' ? 'bg-sky-50 text-sky-700' : 'bg-violet-50 text-violet-700' }}">{{ $trace['stage_label'] }}</span>
                            </td>
                            <td class="whitespace-nowrap px-5 py-2.5 font-mono text-xs tabular-nums text-gray-600">{{ $trace['started_at'] }}</td>
                            <td class="max-w-xs px-5 py-2.5">
                                <p class="truncate text-xs text-gray-700" title="{{ $trace['endpoint'] }}">{{ $trace['endpoint'] ?: '-' }}</p>
                            </td>
                            <td class="px-5 py-2.5 font-mono text-xs text-gray-700">{{ $trace['model'] ?: '-' }}</td>
                            <td class="px-5 py-2.5 text-right">
                                <button type="button" class="inline-flex min-h-8 items-center rounded-md border border-gray-200 px-2 text-xs font-medium text-gray-600 hover:border-violet-300 hover:text-violet-700" data-ai-visibility-trace-copy="{{ $loop->index }}">{{ __('admin.analytics.ai_visibility.traces.copy') }}</button>
                            </td>
                        </tr>
                        <tr class="border-0 bg-gray-50/60">
                            <td colspan="5" class="px-5 py-0">
                                <details class="group">
                                    <summary class="cursor-pointer py-2 text-xs font-semibold text-violet-700 hover:underline">{{ __('admin.analytics.ai_visibility.traces.panel_title') }} {{ $trace['id'] }}</summary>
                                    <div class="mb-3 grid grid-cols-1 gap-x-6 gap-y-2 rounded-md border border-gray-200 bg-white p-4 text-xs md:grid-cols-2" data-ai-visibility-trace-body="{{ $loop->index }}">
                                        <div><p class="font-semibold text-gray-400">{{ __('admin.analytics.ai_visibility.traces.request_id') }}</p><p class="mt-0.5 font-mono text-gray-800">{{ $trace['request_id'] ?: '-' }}</p></div>
                                        <div><p class="font-semibold text-gray-400">{{ __('admin.analytics.ai_visibility.traces.latency') }}</p><p class="mt-0.5 font-mono text-gray-800">{{ $trace['latency_ms'] > 0 ? number_format($trace['latency_ms'] / 1000, 1).' s ('.number_format($trace['latency_ms']).' ms)' : '-' }}</p></div>
                                        <div><p class="font-semibold text-gray-400">{{ __('admin.analytics.ai_visibility.traces.time') }}</p><p class="mt-0.5 font-mono text-gray-800">{{ $trace['started_at'] }} → {{ $trace['completed_at'] }}</p></div>
                                        <div><p class="font-semibold text-gray-400">{{ __('admin.analytics.ai_visibility.traces.tokens') }}</p><p class="mt-0.5 font-mono text-gray-800">{{ $trace['tokens'] ?: '-' }}</p></div>
                                        <div class="md:col-span-2"><p class="font-semibold text-gray-400">{{ __('admin.analytics.ai_visibility.traces.prompt') }}</p><p class="mt-0.5 break-words text-gray-700">{{ $trace['prompt_excerpt'] ?: '-' }}</p></div>
                                        <div class="md:col-span-2"><p class="font-semibold text-gray-400">{{ __('admin.analytics.ai_visibility.traces.response') }}</p><p class="mt-0.5 break-words text-gray-700">{{ $trace['response_excerpt'] ?: '-' }}</p></div>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<script>
    document.querySelectorAll('[data-ai-visibility-trace-copy]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var idx = btn.getAttribute('data-ai-visibility-trace-copy');
            var body = document.querySelector('[data-ai-visibility-trace-body="' + idx + '"]');
            if (! body) { return; }
            var lines = body.innerText.split('
').map(function (l) { return l.trim(); }).filter(Boolean);
            var text = lines.join('
');
            if (text) {
                try { await navigator.clipboard.writeText(text); } catch (e) { /* ignore */ }
            }
        });
    });
</script>

