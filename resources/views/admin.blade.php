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

        {{-- 자산 URL 자가 복구 헬퍼 — 실행 코드는 template-externals-head 가 싣는 g7-shell-head.js 에 있다 --}}
        @include('partials.asset-url-recovery')

        <!-- 템플릿 컴포넌트 스타일 -->
        @if(!empty($activeAdminTemplate))
        {{-- 로드 실패 시 정적 최적화 서버에서 확장자 붙은 CSS 가 가로채였다고 보고 확장자 없는
             형태로 1회 교체한다(무스타일 화면 방지). 링크당 1회. 실패는 인라인 onerror 가 아니라
             g7-shell-head.js 의 캡처 리스너가 `data-g7-template-css` 표식으로 받는다(#158). --}}
        {{-- 템플릿이 선언하고(template.json assets.css) 산출물이 있는 CSS 만 링크한다(Composer 가 고름).
             CSS 를 선언하지 않은 템플릿에 없는 파일 링크를 넣으면 화면마다 요청 실패가 남는다. --}}
        @foreach($templateStylesheets ?? [] as $templateStylesheet)
        <link rel="stylesheet"
              href="{{ \App\Support\AssetUrl::templateAsset($activeAdminTemplate, $templateStylesheet, $extensionCacheVersion) }}"
              data-g7-template-css>
        @endforeach
        @include('partials.shell-stall-notice', ['part' => 'style'])
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
        {{-- G7 설정 — 비실행 JSON 데이터 블록. window.G7Config / window.G7Error 대입은
             g7-shell-boot.js 가 한다(#158 — strict CSP 에서 인라인 대입 <script> 가 실행되지 않아
             전 화면이 백지가 됐다). 키 이름·값은 종전 인라인 대입과 같다(엔진·확장 계약). --}}
        @php
            $g7EditorBundle = public_path('build/core/layout-editor.min.js');
            $g7DevToolsBundle = public_path('build/core/devtools.min.js');
            $g7Config = [
                'settings' => (object) ($frontendSettings ?? []),
                'plugins' => (object) ($pluginSettings ?? []),
                'modules' => (object) ($moduleSettings ?? []),
                'moduleAssets' => $moduleAssets ?? [],
                'pluginAssets' => $pluginAssets ?? [],
                'bundleUrls' => $bundleUrls ?? null,
                'customAssets' => $customAssets ?? [],
                // 이번 렌더가 `?custom=off` 로 자산을 껐는지. URL 은 SPA 부팅이 다시 쓰면서
                // 쿼리를 잃을 수 있으므로, 화면은 URL 이 아니라 **서버가 실제로 한 일**을 본다.
                'customAssetsDisabled' => $customAssetsDisabled ?? false,
                'activeModules' => $activeModulesMeta ?? null,
                'activePlugins' => $activePluginsMeta ?? null,
                'trustedScriptHosts' => $trustedScriptHosts ?? [],
                // 전역 상태 공개 계약 (코어 예약 + 활성 확장 선언) — G7Core.stateContracts 가 읽는다
                'stateContracts' => (object) ($stateContracts ?? []),
                'appConfig' => (object) ($appConfig ?? []),
                // 레이아웃 편집기 lazy 번들 URL — `/admin/layout-editor/*` 진입 시에만 런타임
                // <script> 주입으로 로드된다(초기 접속 payload 에 미포함). filemtime 캐시버스팅,
                // 미빌드 상태 대비 file_exists 가드.
                'coreEditorAsset' => asset('build/core/layout-editor.min.js').'?v='.(file_exists($g7EditorBundle) ? filemtime($g7EditorBundle) : 0),
                // DevTools lazy 번들 URL — 디버그 모드에서만 런타임 <script> 주입으로 로드.
                'coreDevToolsAsset' => asset('build/core/devtools.min.js').'?v='.(file_exists($g7DevToolsBundle) ? filemtime($g7DevToolsBundle) : 0),
                // 확장(코어/모듈/플러그인) 캐시 버전 SSoT — install/activate/deactivate/update 시 bump.
                // 클라이언트 fetch (`?v=`) 가 이 값을 동반해야 백엔드 `template.routes.{id}.v{N}`
                // 키가 새 버전으로 전환되어 routes.json/lang 변경이 즉시 가시화된다.
                // 미주입 시 클라이언트가 항상 `v0` 으로 호출 → `template:cache-clear` 가 v 와일드카드를
                // 처리하지 못해 캐시가 영구 stale 되는 결함이 발생.
                'cache_version' => (int) ($extensionCacheVersion ?? 0),
            ];
            // 정적 게시(bake) 베이스 — 게이트(프로덕션·kill-switch·게시 완료) 통과 시에만
            // 주입된다. 프론트 로더가 routes/lang/components 를 이 경로에서 우선 수신 (#122).
            if (($staticExtBase = \App\Support\AssetUrl::staticExtBase()) !== null) {
                $g7Config['staticBase'] = $staticExtBase;
            }
            // 자산 URL 모드 — 'extension'(기본) | 'extensionless'. 서버 기본값만 싣는다.
            // <head> 의 자가 복구가 이미 전환을 확정했다면(window.__g7AssetUrlMode)
            // g7-shell-boot.js 가 그 값을 우선한다 — G7Config 객체를 통째로 교체하므로
            // 독립 전역을 읽지 않으면 복원/전환 결과가 덮여 사라진다.
            $g7Config['assetUrlMode'] = \App\Support\AssetUrl::mode();
        @endphp
        <script type="application/json" id="g7-config">@json($g7Config)</script>
        @if(isset($errorCode) && isset($errorLayout))
        @php
            // 에러 상태 정보 (503 의존성 미충족 등)
            $g7Error = ['code' => (int) $errorCode, 'layout' => (string) $errorLayout, 'data' => $unmetDependencies ?? []];
        @endphp
        <script type="application/json" id="g7-error">@json($g7Error)</script>
        @endif
        @include('partials.shell-stall-notice', ['part' => 'notice'])

        {{-- 코어 엔진 + 템플릿 컴포넌트 번들 로드 → 초기화 (재시도 + 폴백 UI) --}}
        @include('partials.bootstrap-scripts', [
            'templateType' => 'admin',
            {{-- 코어 엔진 번들은 public/ 의 실물 정적 파일이라 자산 URL 이중 모드 대상이 아니다.
                 미빌드 상태에서 filemtime() 이 warning 을 내지 않도록 file_exists 가드
                 (coreEditorAsset/coreDevToolsAsset 와 동일 패턴). --}}
            'coreEngineSrc' => asset('build/core/template-engine.min.js') . '?v=' . (file_exists(public_path('build/core/template-engine.min.js')) ? filemtime(public_path('build/core/template-engine.min.js')) : 0),
            'componentsSrc' => \App\Support\AssetUrl::templateAsset($activeAdminTemplate, 'js/components.iife.js', $extensionCacheVersion),
            'initConfig' => array_filter([
                'templateId' => $activeAdminTemplate,
                'templateType' => 'admin',
                'locale' => app()->getLocale(),
                'debug' => (bool) config('app.debug'),
                'websocket' => config('broadcasting.connections.reverb.key') ? [
                    'appKey' => config('broadcasting.connections.reverb.key'),
                    'host' => config('g7.websocket.client.host', config('broadcasting.connections.reverb.options.host', 'localhost')),
                    'port' => (int) config('g7.websocket.client.port', config('broadcasting.connections.reverb.options.port', 80)),
                    'scheme' => config('g7.websocket.client.scheme', config('broadcasting.connections.reverb.options.scheme', 'https')),
                ] : null,
            ], fn ($value) => $value !== null),
        ])

        @include('partials.template-externals-scripts', ['position' => 'body-end'])
        @endif
    </body>
</html>
