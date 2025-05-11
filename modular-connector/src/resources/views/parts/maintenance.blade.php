<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="{{ $description }}">

    @if($noindex)
        <meta name="robots" content="noindex">
    @endif

    <title>{{ $title }}</title>
    <style>
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 400;
            font-display: swap;
            src: url("https://s0.wp.com/i/fonts/inter/Inter-Regular.woff2?v=3.19") format("woff2"),
            url("https://s0.wp.com/i/fonts/inter/Inter-Regular.woff?v=3.19") format("woff");
        }

        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 500;
            font-display: swap;
            src: url("https://s0.wp.com/i/fonts/inter/Inter-Medium.woff2?v=3.19") format("woff2"),
            url("https://s0.wp.com/i/fonts/inter/Inter-Medium.woff?v=3.19") format("woff");
        }

        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 600;
            font-display: swap;
            src: url("https://s0.wp.com/i/fonts/inter/Inter-SemiBold.woff2?v=3.19") format("woff2"),
            url("https://s0.wp.com/i/fonts/inter/Inter-SemiBold.woff?v=3.19") format("woff");
        }

        :root {
            --md-illustration-size: 450px;
            --md-title-font-size: 3rem;
            --md-description-font-size: 1.25rem;
            --md-padding-box: 72px 48px;
        }

        @media (max-width: 1200px) {
            :root {
                --md-illustration-size: 400px;
                --md-title-font-size: 2.75rem;
                --md-description-font-size: 1.125rem;
                --md-padding-box: 60px 40px;
            }
        }

        @media (max-width: 768px) {
            :root {
                --md-illustration-size: 300px;
                --md-title-font-size: 2.5rem;
                --md-description-font-size: 1.1rem;
                --md-padding-box: 48px 32px;
            }
        }

        @media (max-width: 576px) {
            :root {
                --md-illustration-size: 300px;
                --md-title-font-size: 2.5rem;
                --md-description-font-size: 1.2rem;
                --md-padding-box: 36px 24px;
            }
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', Arial, sans-serif;
        }

        body {
            width: 100%;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            line-height: 1.6;

            @if(!empty($background))
                background-color: {{ $background }};
            @endif
        }

        .maintenance-container {
            width: 100%;
            text-align: center;
            padding: 100px 0;
            min-height: 100dvh;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .illustration-container {
            margin: 0 auto -30px;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
            z-index: 1;
            width: var(--md-illustration-size);
            height: var(--md-illustration-size);
        }

        .illustration-container svg {
            width: 100%;
            height: 100%;
            focusable: false;
            aria-hidden="true";
        }

        .message-box {
            background-color: #21222C;
            color: #FFFFFF;
            padding: var(--md-padding-box);
            position: relative;
            z-index: 2;
        }

        h1 {
            font-size: var(--md-title-font-size);
            margin-bottom: 16px;
            font-weight: 600;
            line-height: 1.2;
        }

        p {
            font-size: var(--md-description-font-size);
            line-height: 1.5;
        }

        .branding {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.7);
            margin-top: 20px;
            margin-left: auto;
            position: absolute;
            bottom: 24px;
            right: 24px;
            padding: 6px 10px;
            border-radius: 4px;
            z-index: 10;
            text-shadow: 0 0 2px rgba(0, 0, 0, 0.7);
        }

        .branding a {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: color 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;

            > span {
                margin-right: 10px;
                line-height: 0.75;
            }
        }

        .branding a:hover, .branding a:focus {
            color: rgba(255, 255, 255, 1);
            transform: translateY(-2px);
            outline: none;
        }

        .branding a:focus-visible {
            outline: 2px solid rgba(255, 255, 255, 0.7);
            outline-offset: 2px;
        }

        .modular-logo {
            height: 18px;
            display: inline-flex;
            filter: drop-shadow(0 0 2px rgba(0, 0, 0, 0.7));
        }

        .modular-logo svg {
            height: 100%;
            width: auto;
            focusable: false;
        }
    </style>
</head>

<body>
<main id="modulards-maintenance" class="maintenance-container" role="main">
    <div class="illustration-container" aria-hidden="true">
        @include('icons.maintenance-svg')
    </div>

    <div class="message-box">
        <h1 id="maintenance-title">{{ $title }}</h1>
        <p id="maintenance-description">{{ $description }}</p>
    </div>

    @if($withBranding)
        <div class="branding">
            <a href="https://modulards.com" target="_blank" rel="noopener noreferrer" aria-label="Powered by Modular DS - Visit our website">
                <span>
                Powered by
                </span>

                <span class="modular-logo" aria-hidden="true">
                    @include('icons.modular-logo-svg')
                </span>
            </a>
        </div>
    @endif
</main>
</body>
</html>
