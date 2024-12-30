@extends('settings.layout')

@section('content')
    <h2>{!! esc_attr__('Just one more thing! We are almost done...', 'modular-connector') !!}</h2>

    <ol class="ds-styled-list">
        <li>{!! sprintf(esc_attr__('Return to your %s account.', 'modular-connector'), '<a target="_blank" href="https://app.modulards.com">Modular DS</a>') !!}</li>
        <li>{!! esc_attr__('Open the website you are connecting.', 'modular-connector') !!}</li>
        <li>{!! esc_attr__('Click on "Connect".', 'modular-connector') !!}</li>
    </ol>
@endsection
