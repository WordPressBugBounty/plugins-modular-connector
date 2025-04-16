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
    </div>
@endsection
