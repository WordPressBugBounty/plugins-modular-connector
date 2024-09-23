<div class="wrap">
    <div class="text" style="max-width: 800px">
        <h1>{{ $title }}</h1>

        @if(empty($connection->getClientId()))
            <h2>{{ esc_attr__('Automatic Mode', 'modular-connector') }}</h2>
            <p class="description">
                {!! esc_attr__('<strong>We will NOT store your username and/or password</strong>, we will only use it once to connect your site to Modular.', 'modular-connector') !!}
            </p>

            <ol>
                <li>{!! sprintf(esc_attr__('Log in to your %s account', 'modular-connector'), '<a target="_blank" href="https://app.modulards.com">Modular DS</a>') !!}</li>
                <li>{{ esc_attr__('Click on the "New Site" button.', 'modular-connector') }}</li>
                <li>{{ esc_attr__('Enter the URL, administrator\'s username and password of this website.', 'modular-connector') }}</li>
                <li>{{ esc_attr__('The system will take care of everything.', 'modular-connector') }}</li>
            </ol>

            <h2>{{ esc_attr__('Manual Mode', 'modular-connector') }}</h2>

            <ol>
                <li>{!! sprintf(esc_attr__('Log in to your %s account', 'modular-connector'), '<a target="_blank" href="https://app.modulards.com">Modular DS</a>') !!}</li>
                <li>{{ esc_attr__('Click on the "New Site" button.', 'modular-connector') }}</li>
                <li>{{ esc_attr__('Enter the name and URL of this website.', 'modular-connector') }}</li>
                <li>{{ esc_attr__('Copy the public key and secret key and return to this page.', 'modular-connector') }}</li>
                <li>{{ esc_attr__('Paste the connection keys in the form below.', 'modular-connector') }}</li>
            </ol>
        @elseif(empty($connection->getConnectedAt()))
            <h2>{{ esc_attr__('Just one more thing! We are almost done...', 'modular-connector') }}</h2>

            <ol>
                <li>{!! sprintf(esc_attr__('Return to your %s account', 'modular-connector'), '<a target="_blank" href="https://app.modulards.com">Modular DS</a>') !!}</li>
                <li>{{ esc_attr__('Open the site you have created.', 'modular-connector') }}</li>
                <li>{{ esc_attr__('Click on "Confirm connection".', 'modular-connector') }}</li>
                <li>{{ esc_attr__('From that moment on your site will be connected to Modular.', 'modular-connector') }}</li>
            </ol>
        @else
            <table class="wp-list-table widefat fixed striped table-view-list">
                <tr>
                    <th>
                        {{ esc_attr__('Public key', 'modular-connector') }}
                    </th>

                    <th>
                        {{ esc_attr__('Connected on', 'modular-connector') }}
                    </th>

                    <th>
                        {{ esc_attr__('Last used', 'modular-connector') }}
                    </th>
                </tr>

                @foreach($connections as $connection)
                    <tr>
                        <th>
                            {{ $connection->getClientId() }}
                        </th>

                        <th>
                            @if($connectedAt = $connection->getConnectedAt())
                                {{ $connectedAt->format(get_option('date_format') . ' ' . get_option('time_format')) }}
                            @endif
                        </th>

                        <th>
                            @if($usedAt = $connection->getUsedAt())
                                {{ $usedAt->format(get_option('date_format') . ' ' . get_option('time_format')) }}
                            @endif
                        </th>
                    </tr>
                @endforeach
            </table>
        @endif

        <hr/>

        <form method="post">
            {!! wp_nonce_field('_modular_connector_connection', '_wpnonce', true, false) !!}

            <h3>{{ esc_attr__('Connect your site', 'modular-connector') }}</h3>

            <p class="description">
                {{ esc_attr__('These keys will allow us to authenticate API requests securely, without providing any real passwords. The keys can be easily overridden.', 'modular-connector') }}
            </p>

            <table class="form-table" role="presentation">
                <tbody>
                <tr>
                    <th scope="row">
                        <label for="client-id">{{ esc_attr__('Public key', 'modular-connector') }}</label>
                    </th>

                    <td>
                        <input type="text"
                               id="client-id"
                               name="client_id" value="{{ $connection->getClientId() }}"
                               class="regular-text ltr" required
                        />
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="client-secret">{{ esc_attr__('Secret key', 'modular-connector') }}</label>
                    </th>

                    <td>
                        <input type="password" id="client-secret" name="client_secret" class="regular-text ltr"
                               placeholder="******************" required/>

                        <p class="description" id="timezone-description">
                            {{ esc_attr__('Treat your secret key as if it were a password. Make sure it is kept out of any version control system you may be using.', 'modular-connector') }}
                        </p>
                    </td>
                </tr>
                </tbody>
            </table>

            <p class="submit">
                <button type="submit" id="connect" class="button button-primary">
                    {{ esc_attr__('Update', 'modular-connector') }}
                </button>
            </p>
        </form>
    </div>
</div>
