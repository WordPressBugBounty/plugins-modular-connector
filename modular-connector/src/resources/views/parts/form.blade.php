<form method="post">
    {!! wp_nonce_field('_modular_connector_connection', '_wpnonce', true, false) !!}

    <h2>{{ esc_attr__('Connect your website', 'modular-connector') }}</h2>

    <p class="description">
        {{ esc_attr__('These keys will allow us to authenticate API requests securely, without storing your password. The keys can be easily overridden.', 'modular-connector') }}
    </p>

    <div class="ds-form">
        @if(\Modular\ConnectorDependencies\request()->has('success') && !\Modular\ConnectorDependencies\request()->boolean('success'))
            <div class="ds-alert ds-alert-error">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="11" stroke="#E40173" stroke-width="2" />
                    <path d="M12 7V12" stroke="#E40173" stroke-width="2" stroke-linecap="round"
                          stroke-linejoin="round" />
                    <path d="M12 16V16.5" stroke="#E40173" stroke-width="2" stroke-linecap="round"
                          stroke-linejoin="round" />
                </svg>
                <span>{{ esc_attr__('It seems that the values are not correct. Please try again.', 'modular-connector') }}</span>
            </div>
        @endif

        <div class="form-group">
            <label for="client-id">{{ esc_attr__('Public key', 'modular-connector') }}</label>
            <input
                    type="text"
                    id="client-id"
                    name="client_id"
                    value="{{ $connection->getClientId() }}"
                    class="ds-input"
                    autocomplete="off"
                    aria-describedby="Client ID for Modular Connector"
                    required
            />
        </div>

        <div class="form-group">
            <label for="client-secret">{{ esc_attr__('Secret key', 'modular-connector') }}</label>
            <input
                    type="password"
                    id="client-secret"
                    name="client_secret"
                    class="ds-input"
                    placeholder="******************"
                    required
                    autocomplete="off"
                    aria-describedby="Secret Key for Modular Connector"
            />
            <p class="description" id="secret-key-description">
                {{ esc_attr__('Treat your secret key as if it were a password. Make sure it is kept out of any version control system you may be using.', 'modular-connector') }}
            </p>
        </div>

        <div class="form-group">
            @if(\Modular\ConnectorDependencies\request()->has('success') && \Modular\ConnectorDependencies\request()->boolean('success'))
                <div class="update-nag inline">{{ esc_attr__('Data updated!', 'modular-connector') }}</div>
            @endif

            <button type="submit" id="connect" class="button button-primary">
                {{ !empty($isConnected) ? esc_attr__('Update', 'modular-connector') : esc_attr__('Save', 'modular-connector') }}
            </button>
        </div>
    </div>
</form>
