<div class="ds-connections-list {{ $class ?? '' }}">
    <hr class="ds-separator">

    <div class="ds-connection-item">
        <div class="ds-connection-field">
            <span class="ds-connection-label">{{ esc_attr__('Connected on', 'modular-connector') }}</span>
            <span class="ds-connection-value">
                    @if($connectedAt = $connection->getConnectedAt())
                    {{ $connectedAt->format(get_option('date_format') . ' ' . get_option('time_format')) }}
                @else
                    {{ esc_attr__('N/A', 'modular-connector') }}
                @endif
                </span>
        </div>

        <div class="ds-connection-field">
            <span class="ds-connection-label">{{ esc_attr__('Last used', 'modular-connector') }}</span>
            <span class="ds-connection-value">
                    @if($usedAt = $connection->getUsedAt())
                    {{ $usedAt->format(get_option('date_format') . ' ' . get_option('time_format')) }}
                @else
                    {{ esc_attr__('N/A', 'modular-connector') }}
                @endif
                </span>
        </div>
    </div>
</div>
