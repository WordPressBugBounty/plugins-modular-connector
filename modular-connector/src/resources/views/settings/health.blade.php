@extends('settings.tab-layout')

@section('content')
    <div class="ds-logs-container">
        <h2>{{ esc_html__('Queues', 'modular-connector') }}</h2>

        <ul class="ds-logs-list">
            @foreach(['default', 'backups', 'optimizations'] as $queue)
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

        <h2>{{ esc_html__('Cache', 'modular-connector') }}</h2>

        <ul class="ds-logs-list">
            @foreach(['file', 'database'] as $driver)
                <li class="ds-log-item">
                    <span class="ds-log-name">
                        {{ sprintf('%s', $driver) }}
                    </span>

                    <form method="post" class="ds-cache-form">
                        {!! wp_nonce_field('_modular_connector_clear', '_wpnonce', true, false) !!}

                        <input type="hidden" name="action" value="cache">
                        <input type="hidden" name="driver" value="{{ $driver }}">

                        <button type="submit" class="button button-primary button-sm">
                            {{ esc_html__('Clear', 'modular-connector') }}
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>

        <h2>{{ esc_html__('Reset all settings', 'modular-connector') }}</h2>

        <ul class="ds-logs-list">
            <li class="ds-log-item">
                <form method="post" class="ds-cache-form">
                    {!! wp_nonce_field('_modular_connector_clear', '_wpnonce', true, false) !!}
                    <input type="hidden" name="action" value="reset">

                    <button type="submit" class="button button-primary button-sm">
                        {{ esc_html__('Reset all', 'modular-connector') }}
                    </button>
                </form>
            </li>
        </ul>
    </div>
@endsection
