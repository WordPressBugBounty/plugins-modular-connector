@extends('settings.layout')

@section('content')
    <h2>{!! esc_attr__('Manual Mode', 'modular-connector') !!}</h2>

    <ol class="ds-styled-list">
        <li>{!! sprintf(esc_attr__('Log in to your %s account.', 'modular-connector'), '<a target="_blank" href="https://app.modulards.com">Modular DS</a>') !!}</li>
        <li>{!! esc_attr__('Click on the "New Website" button.', 'modular-connector') !!}</li>
        <li>{!! esc_attr__('Enter the name and URL of this website.', 'modular-connector') !!}</li>
        <li>{!! esc_attr__('Copy the public key and secret key and return to this page.', 'modular-connector') !!}</li>
        <li>{!! esc_attr__('Paste the connection keys in the form below and save.', 'modular-connector') !!}</li>
        <li>{!! esc_attr__('Go back to Modular DS and click "Connect".', 'modular-connector') !!}</li>
    </ol>

    <h2>{{ esc_attr__('Automatic Mode', 'modular-connector') }}</h2>
    <ol class="ds-styled-list">
        <li>{!! sprintf(esc_attr__('Log in to your %s account.', 'modular-connector'), '<a target="_blank" href="https://app.modulards.com">Modular DS</a>') !!}</li>
        <li>{!! esc_attr__('Click on the "New Website" button.', 'modular-connector') !!}</li>
        <li>{!! esc_attr__('Enter the URL, administrator\'s username and password of this website.', 'modular-connector') !!}</li>
        <li>{!! esc_attr__('The system will take care of everything.', 'modular-connector') !!}</li>
    </ol>
@endsection
