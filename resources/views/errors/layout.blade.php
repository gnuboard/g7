<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') | {{ config('app.name', '그누보드7') }}</title>
    {{-- 파비콘 — 번들 계층만(DB·API 를 보지 않는다, #434) --}}
    {!! g7_favicon_tags_static() !!}
    @include('partials.error-fallback-styles')
    <style>
        .error-code {
            font-size: 5rem;
            font-weight: 800;
            color: #3b82f6;
            line-height: 1;
            margin-bottom: 0.5rem;
        }
        .error-btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background-color: #3b82f6;
            color: #ffffff;
            font-weight: 500;
            border-radius: 0.5rem;
            text-decoration: none;
            transition: background-color 0.2s;
        }
        .error-btn:hover {
            background-color: #2563eb;
        }
        @media (prefers-color-scheme: dark) {
            .error-code {
                color: #60a5fa;
            }
            .error-btn {
                background-color: #3b82f6;
            }
            .error-btn:hover {
                background-color: #2563eb;
            }
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-card">
            <div class="error-code">@yield('code')</div>
            <h1 class="error-title">@yield('title')</h1>
            <p class="error-message">@yield('message')</p>
            @hasSection('extra')
                @yield('extra')
            @endif
            {{-- 마운트 접두사는 **여기 한 곳**이 붙인다. 자식 뷰 다섯이 정하는 것은
                 「관리자면 /admin, 아니면 /」라는 목적지뿐이고, 그 판정은 접두사를 이미
                 벗긴 `request()->path()` 를 보므로 옳다. 붙이는 자리를 자식으로 내리면
                 다섯 벌이 되고 새로 추가되는 오류 페이지가 다시 사각이 된다. 이 링크는
                 라우터를 거치지 않는 날 `<a href>` 라 붙일 주체가 이 자리밖에 없다. --}}
            <a href="{{ \App\Support\AssetUrl::basePrefix().($homeUrl ?? '/') }}" class="error-btn">
                {{ __('errors.back_home') }}
            </a>
        </div>
    </div>
</body>
</html>
