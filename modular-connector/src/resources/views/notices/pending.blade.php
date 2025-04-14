@extends('notices.layout')

@section('content')
    <h2 class="notice-title">{{ esc_html__('Connection to Modular DS pending', 'modular-connector') }}</h2>

    <p>{!! sprintf(
        esc_html__('You have started the connection process with ModularDS but it is not complete yet. Learn how to connect it %s.', 'modular-connector'),
        sprintf(
            '<a href="%s" target="_blank">%s</a>',
            esc_html__('https://help.modulards.com/en/article/how-to-add-a-website-to-the-modular-panel-iso2mc/', 'modular-connector'),
            esc_html__('here', 'modular-connector')
        )
    ) !!}</p>

    <p>
        {!! sprintf(
                '<a href="%s" id="modulards-connect" class="button button-primary">%s</a>',
                menu_page_url('modular-connector', false),
                esc_html__('Connect now', 'modular-connector')
            ) !!}
    </p>
@endsection
