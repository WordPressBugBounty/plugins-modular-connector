@extends('notices.layout')

@section('content')
    <h2 class="notice-title">{{ esc_html__('Connection pending', 'modular-connector') }}</h2>
    <p>{{ esc_html__('You have started the connection process with ModularDS but it is not complete yet.', 'modular-connector') }}</p>
    <p>
        {!! sprintf(
                '<a href="%s" id="modulards-connect" class="button button-primary">%s</a>',
                menu_page_url('modular-connector', false),
                esc_html__('Connect now', 'modular-connector')
            ) !!}
    </p>
@endsection
