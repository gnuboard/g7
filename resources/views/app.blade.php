<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', '그누보드7') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Font Awesome CDN -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

        <!-- Fallback UI 스타일 -->
        @if(empty($activeUserTemplate))
        @include('partials.error-fallback-styles')
        @endif

        <!-- 템플릿 컴포넌트 스타일 -->
        @if(!empty($activeUserTemplate))
        <link rel="stylesheet" href="/api/templates/assets/{{ $activeUserTemplate }}/css/components.css?v={{ time() }}">
        @endif
    </head>
    <body class="font-sans antialiased">
        <!-- React 렌더링 루트 -->
        <div id="app" data-template-id="{{ $activeUserTemplate ?? '' }}">
            <!-- Progressive Enhancement: 템플릿 없음 Fallback UI -->
            @if(empty($activeUserTemplate))
            @include('partials.error-fallback-ui')
            @endif
        </div>

        @if(!empty($activeUserTemplate))
        <!-- G7 설정 전역 변수 -->
        <script>
            window.G7Config = {
                settings: @json($frontendSettings ?? []),
                plugins: @json($pluginSettings ?? []),
                modules: @json($moduleSettings ?? []),
                moduleAssets: @json($moduleAssets ?? []),
                pluginAssets: @json($pluginAssets ?? []),
                appConfig: @json($appConfig ?? [])
            };
            @if(isset($errorCode) && isset($errorLayout))
            // 에러 상태 정보 (503 의존성 미충족 등)
            window.G7Error = {
                code: {{ $errorCode }},
                layout: '{{ $errorLayout }}',
                data: @json($unmetDependencies ?? [])
            };
            @endif
        </script>

        <!-- 코어 렌더링 엔진 -->
        <script src="{{ asset('build/core/template-engine.min.js') }}?v={{ filemtime(public_path('build/core/template-engine.min.js')) }}"></script>

        <!-- 템플릿 컴포넌트 번들 (IIFE) -->
        <script src="/api/templates/assets/{{ $activeUserTemplate }}/js/components.iife.js?v={{ time() }}"></script>

        <!-- 템플릿 엔진 초기화 (TemplateApp 사용) -->
        <script>
            // TemplateApp을 통한 초기화 (DOMContentLoaded 이벤트에서 자동으로 초기화됨)
            if (window.G7Core && window.G7Core.initTemplateApp) {
                window.G7Core.initTemplateApp({
                    templateId: '{{ $activeUserTemplate }}',
                    templateType: 'user',
                    locale: '{{ app()->getLocale() }}',
                    debug: {{ config('app.debug') ? 'true' : 'false' }}@if(config('broadcasting.connections.reverb.key')),
                    websocket: {
                        appKey: '{{ config('broadcasting.connections.reverb.key') }}',
                        host: '{{ config('g7.websocket.client.host', config('broadcasting.connections.reverb.options.host', 'localhost')) }}',
                        port: {{ config('g7.websocket.client.port', config('broadcasting.connections.reverb.options.port', 80)) }},
                        scheme: '{{ config('g7.websocket.client.scheme', config('broadcasting.connections.reverb.options.scheme', 'https')) }}'
                    }@endif
                });
            } else {
                console.error('[User] G7Core.initTemplateApp is not available');
            }
        </script>
        @endif
    </body>
</html>
