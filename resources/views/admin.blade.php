<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"{!! g7_template_html_attributes('admin') !!}>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        {!! g7_meta_generator_tag() !!}

        <title>{{ config('app.name', '그누보드7') }} - Admin</title>
        {{-- 파비콘 — 활성 관리자 템플릿 설정 > 일반 탭 파비콘 > 번들 기본 (#434). 템플릿 없음 화면에서도 낸다 --}}
        {!! g7_favicon_tags('admin') !!}

        <!-- 템플릿 외부 리소스 (template.json의 externals) -->
        @include('partials.template-externals-head')

        {{-- 코어 런타임 스타일 — 템플릿 CSS 앞에 싣는다(뷰 안 스타일 블록 없이, #163) --}}
        @include('partials.core-styles', ['sheet' => 'core'])

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
        @endif

        {{-- 확장 CSS 의미 클래스 칸 — 템플릿 CSS 링크 **뒤**에 선언한다. 템플릿의 층 규칙(예: Tailwind 초기화)보다
             위, 층 없는 규칙(확장 유틸리티·템플릿 층 없는 CSS·템플릿 확장 CSS 덮기 파일)보다 아래에 놓인다.
             앞에 두면 템플릿 초기화(`*{margin:0}` 등)가 확장 의미 클래스를 이긴다.
             `g7-preview` 는 레이아웃 편집기 프리뷰 CSS(사용자 템플릿 CSS 를 평탄화해 이 층에 담아 서빙)의 자리다 —
             관리자 템플릿 층 위·확장 의미 칸 아래라서 프리뷰에서도 「템플릿 층 < 확장 의미 칸」 관계가 사용자 화면과 같다. --}}
        <style>@layer g7-preview, g7-extension;</style>
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
                // 사이트가 마운트되는 경로 접두사 (루트 설치면 빈 문자열, 하위 폴더면 `/g7`).
                // 서버의 `AssetUrl::basePrefix()` 를 **그대로 부른다** — 자산 URL 을 만드는
                // 쪽과 모드를 변환하는 쪽이 규칙을 공유하지 않으면, 하위 폴더 배치에서
                // R33 자가 복구가 그 URL 을 몰라보고 통째로 무력해진다. 종전에는 같은 식을
                // 여기 손으로 한 벌 더 적어 두고 주석으로만 「같은 값이어야 한다」고 선언했다.
                'basePath' => \App\Support\AssetUrl::basePrefix(),
                'settings' => (object) ($frontendSettings ?? []),
                'plugins' => (object) ($pluginSettings ?? []),
                // 활성 템플릿 공개 설정 (#434) — 엔진이 `_global.templateSettings` 로 싣는다
                'templateSettings' => (object) ($templateSettings ?? []),
                'modules' => (object) ($moduleSettings ?? []),
                'moduleAssets' => $moduleAssets ?? [],
                'pluginAssets' => $pluginAssets ?? [],
                'bundleUrls' => $bundleUrls ?? null,
                // 설치된 템플릿(꺼진 것 포함)이 공급한 설정 화면 부품 묶음 — 관리자 화면만 플러그인 묶음 뒤에 싣는다 (#434)
                'templateSettingsAssets' => $templateSettingsAssets ?? [],
                'customAssets' => $customAssets ?? [],
                // 템플릿 다크 방식 선언 — 엔진이 `<html data-g7-color-scheme>` 중립 표식으로 옮긴다(확장 CSS 는 표식만 본다)
                'colorScheme' => $colorScheme ?? ['strategy' => 'none'],
                // 템플릿이 선언한 확장 CSS 덮기 파일 중 대상이 활성이고 버전이 맞는 것 — 확장 번들 CSS 뒤에 붙는다
                'extensionCssOverrides' => $extensionCssOverrides ?? [],
                // 활성 확장의 CSS 접두 빌드 앞글자 — className 을 해석하는 코드가 앞글자와 변형(md: 등)을 가른다
                'extensionClassPrefixes' => $extensionClassPrefixes ?? [],
                // 이번 렌더가 `?custom=off` 로 자산을 껐는지. URL 은 SPA 부팅이 다시 쓰면서
                // 쿼리를 잃을 수 있으므로, 화면은 URL 이 아니라 **서버가 실제로 한 일**을 본다.
                'customAssetsDisabled' => $customAssetsDisabled ?? false,
                'activeModules' => $activeModulesMeta ?? null,
                'activePlugins' => $activePluginsMeta ?? null,
                'trustedScriptHosts' => $trustedScriptHosts ?? [],
                // 전역 상태 공개 계약 (코어 예약 + 활성 확장 선언) — G7Core.stateContracts 가 읽는다
                'stateContracts' => (object) ($stateContracts ?? []),
                'appConfig' => (object) ($appConfig ?? []),
                // routes/custom.php 로 등록된 개발자 화면 GET 주소(#792) — 엔진 라우터가 이 주소로는 전체 페이지 이동한다
                'customRoutes' => $customRoutes ?? ['user' => [], 'admin' => []],
                // 브라우저 탭 제목 계산 값(사이트 이름 + 언어별 관리자 접미사) — 엔진 DocumentTitle 이 「{화면 제목} - {사이트 이름} 관리자」로 쓴다
                'seoTitle' => $seoTitle ?? null,
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
            // 템플릿 설정 중 template.json `expose` 에 제작자가 선언한 키만 — 엔진이 첫 화면 전 config API 를 부르지 않는다.
            // 선언이 없는 템플릿은 싣지 않고 엔진이 종전처럼 API 를 부른다 (#879)
            if (($exposedTemplateConfig ?? null) !== null) {
                $g7Config['templateConfig'] = (object) $exposedTemplateConfig;
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
        @include('partials.shell-stall-notice')

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
                'locale' => $frontendLocale ?? app()->getLocale(),
                'debug' => (bool) config('app.debug'),
                {{-- 공개 설정 API 와 같은 함수(FrontendConfigService::websocket)로 만든다 --}}
                'websocket' => $websocketConfig ?? null,
            ], fn ($value) => $value !== null),
        ])

        @include('partials.template-externals-scripts', ['position' => 'body-end'])
        @endif
    </body>
</html>
