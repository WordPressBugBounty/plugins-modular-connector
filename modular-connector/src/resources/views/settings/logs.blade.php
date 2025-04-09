@extends('settings.tab-layout')

@section('content')
    <div class="ds-logs-container">
        <form method="post" class="ds-logs-form">
            {!! wp_nonce_field('_modular_connector_logs', '_wpnonce', true, false) !!}

            <h2>{{ esc_html__('Stored logs:', 'modular-connector') }}</h2>

            @if(empty($logs))
                <div class="ds-empty-logs">
                    <p>{{ esc_html__('There are no logs to display.', 'modular-connector') }}</p>
                </div>
            @else
                <ul class="ds-logs-list">
                    @foreach($logs as $log)
                        <li class="ds-log-item">
                            <span class="ds-log-name">{{ $log }}</span>
                            <button type="submit" class="button button-primary button-sm" name="log_file"
                                    value="{{ $log }}">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                     fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                     stroke-linejoin="round"
                                     class="lucide lucide-download-icon lucide-download">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                    <polyline points="7 10 12 15 17 10"/>
                                    <line x1="12" x2="12" y1="15" y2="3"/>
                                </svg>
                                {{ esc_html__('Download', 'modular-connector') }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </form>

        <h2>{{ esc_html__('Queues', 'modular-connector') }}</h2>

        <ul class="ds-logs-list">
            @foreach(['default', 'backups'] as $queue)
                @foreach(['wordpress', 'database'] as $driver)
                    @php
                        try {
                            $size = \Modular\ConnectorDependencies\Illuminate\Support\Facades\Queue::connection($driver)->size($queue);
                        }    catch (\Throwable $e) {
                            $size = 0;
                        }
                    @endphp

                    <li class="ds-log-item">
                        <span class="ds-log-name">
                            {{ sprintf('%s (%s) - %d', $queue, $driver, $size) }}
                        </span>

                        <form method="post" class="ds-cache-form">
                            {!! wp_nonce_field('_modular_connector_clear', '_wpnonce', true, false) !!}

                            <input type="hidden" name="action" value="queue">
                            <input type="hidden" name="queue" value="{{ $queue }}">
                            <input type="hidden" name="driver" value="{{ $driver }}">

                            <button type="submit" class="button button-primary button-sm">
                                {{ esc_html__('Clear', 'modular-connector') }}
                            </button>
                        </form>
                    </li>
                @endforeach
            @endforeach
        </ul>

        <ul class="ds-logs-list">
            <li class="ds-log-item">
                 <span class="ds-log-name">
                    Clear all caches
                 </span>

                <form method="post" class="ds-cache-form">
                    {!! wp_nonce_field('_modular_connector_clear', '_wpnonce', true, false) !!}

                    <input type="hidden" name="action" value="cache">

                    <button type="submit" class="button button-primary button-sm">
                        {{ esc_html__('Clear cache', 'modular-connector') }}
                    </button>
                </form>
            </li>
        </ul>
    </div>
@endsection
