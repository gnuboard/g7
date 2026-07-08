<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        {!! g7_meta_generator_tag() !!}

        <title>{{ config('app.name', '그누보드7') }} - Admin</title>

        <!-- 템플릿 외부 리소스 (template.json의 externals) -->
        @include('partials.template-externals-head')

        <!-- Fallback UI 스타일 -->
        @if(empty($activeAdminTemplate))
        @include('partials.error-fallback-styles')
        @endif

        <!-- 템플릿 컴포넌트 스타일 -->
        @if(!empty($activeAdminTemplate))
        <link rel="stylesheet" href="/api/templates/assets/{{ $activeAdminTemplate }}/css/components.css?v={{ $extensionCacheVersion }}">
        @endif
    </head>
    <body>
        <!-- React 렌더링 루트 -->
        <div id="app" data-template-id="{{ $activeAdminTemplate ?? '' }}">
            <!-- Progressive Enhancement: 템플릿 없음 Fallback UI -->
            @if(empty($activeAdminTemplate))
            @include('partials.error-fallback-ui')
            @endif
        </div>

        @if(!empty($activeAdminTemplate))
        <!-- G7 설정 전역 변수 -->
        <script>
            window.G7Config = {
                settings: @json($frontendSettings ?? []),
                plugins: @json($pluginSettings ?? []),
                modules: @json($moduleSettings ?? []),
                moduleAssets: @json($moduleAssets ?? []),
                pluginAssets: @json($pluginAssets ?? []),
                bundleUrls: @json($bundleUrls ?? null),
                activeModules: @json($activeModulesMeta ?? []),
                activePlugins: @json($activePluginsMeta ?? []),
                appConfig: @json($appConfig ?? []),
                // 레이아웃 편집기 lazy 번들 URL — `/admin/layout-editor/*` 진입 시에만 런타임
                // <script> 주입으로 로드된다(초기 접속 payload 에 미포함). filemtime 캐시버스팅,
                // 미빌드 상태 대비 file_exists 가드.
                coreEditorAsset: '{{ asset('build/core/layout-editor.min.js') }}?v={{ file_exists(public_path('build/core/layout-editor.min.js')) ? filemtime(public_path('build/core/layout-editor.min.js')) : 0 }}',
                // DevTools lazy 번들 URL — 디버그 모드에서만 런타임 <script> 주입으로 로드.
                coreDevToolsAsset: '{{ asset('build/core/devtools.min.js') }}?v={{ file_exists(public_path('build/core/devtools.min.js')) ? filemtime(public_path('build/core/devtools.min.js')) : 0 }}',
                // 확장(코어/모듈/플러그인) 캐시 버전 SSoT — install/activate/deactivate/update 시 bump.
                // 클라이언트 fetch (`?v=`) 가 이 값을 동반해야 백엔드 `template.routes.{id}.v{N}`
                // 키가 새 버전으로 전환되어 routes.json/lang 변경이 즉시 가시화된다.
                // 미주입 시 클라이언트가 항상 `v0` 으로 호출 → `template:cache-clear` 가 v 와일드카드를
                // 처리하지 못해 캐시가 영구 stale 되는 결함이 발생.
                cache_version: {{ (int) ($extensionCacheVersion ?? 0) }}
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

        @include('partials.template-externals-scripts', ['position' => 'before-core'])

        <!-- 코어 렌더링 엔진 -->
        <script src="{{ asset('build/core/template-engine.min.js') }}?v={{ filemtime(public_path('build/core/template-engine.min.js')) }}"></script>

        @include('partials.template-externals-scripts', ['position' => 'before-template'])

        <!-- 템플릿 컴포넌트 번들 (IIFE) -->
        <script src="/api/templates/assets/{{ $activeAdminTemplate }}/js/components.iife.js?v={{ $extensionCacheVersion }}"></script>

        <!-- 템플릿 엔진 초기화 (TemplateApp 사용) -->
        <script>
            // TemplateApp을 통한 초기화 (DOMContentLoaded 이벤트에서 자동으로 초기화됨)
            if (window.G7Core && window.G7Core.initTemplateApp) {
                window.G7Core.initTemplateApp({
                    templateId: '{{ $activeAdminTemplate }}',
                    templateType: 'admin',
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
                console.error('[Admin] G7Core.initTemplateApp is not available');
            }
        </script>

        @include('partials.template-externals-scripts', ['position' => 'body-end'])
        @endif
    </body>
</html>
