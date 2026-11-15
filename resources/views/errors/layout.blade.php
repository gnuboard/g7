<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') | {{ config('app.name', '그누보드7') }}</title>
    @include('partials.error-fallback-styles')
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
