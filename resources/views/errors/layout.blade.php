<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') | {{ config('app.name', '그누보드7') }}</title>
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
            <a href="{{ $homeUrl ?? '/' }}" class="error-btn">
                {{ __('errors.back_home') }}
            </a>
        </div>
    </div>
</body>
</html>
