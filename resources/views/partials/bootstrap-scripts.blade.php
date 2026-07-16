{{--
    코어 엔진 + 템플릿 컴포넌트 번들 부트스트랩 (재시도 + 폴백 UI)

    두 번들은 `<script src>` 로 로드되므로 엔진의 fetch 재시도 래퍼가 닿지 않는다.
    코어 번들이 없으면 재시도할 JS 자체가 없다(닭-달걀). 따라서 재시도를 **순수 인라인
    JS** 로 구현한다. 외부 의존성 없이 동작해야 한다.

    로드 실패 시 종전에는 콘솔 에러만 남고 화면은 백지였다. 3회 시도 후에도 실패하면
    사용자에게 보이는 정적 폴백(새로고침 버튼)을 심는다.

    성능 계약 — `<script src>` 는 **정적 태그로 유지한다**:
    브라우저의 프리로드 스캐너는 HTML 파싱 중에 정적 `<script src>` 를 미리 발견해
    번들을 선행 로드한다. 이를 `document.createElement('script')` 로 바꾸면 스캐너가
    보지 못해 **인라인 JS 가 실행된 뒤에야** 요청이 시작되고, 그만큼 렌더가 늦어진다
    (실측: HTML 응답 완료 314ms → 코어 번들 요청 시작 568ms, 약 250ms 손실).
    따라서 정상 경로는 정적 태그 그대로 두고, **실패했을 때만** `onerror` 에서 동적
    재시도를 건다. 재시도는 예외 경로이므로 스캐너 이점을 잃어도 무방하다.

    'before-template' 외부 스크립트는 코어 뒤 · 템플릿 컴포넌트 앞이라는 순서 계약을
    갖는다. 정적 태그를 유지하므로 그 사이에 그대로 @include 하면 순서가 지켜진다.

    @param $templateType   'user' | 'admin'
    @param $coreEngineSrc  코어 엔진 번들 URL
    @param $componentsSrc  템플릿 컴포넌트 번들 URL
    @param $initConfig     initTemplateApp 에 넘길 설정 (JSON 직렬화 가능)
--}}
<script>
(function () {
    'use strict';

    var MAX_ATTEMPTS = 3;    // 총 3시도 (재시도 2회)
    var BASE_DELAY_MS = 300; // 지수 백오프 기준 (모바일 커넥션 재수립 시간 고려)

    var LABEL = @json($templateType === 'admin' ? '[Admin]' : '[User]');

    // 문서 이탈 추적 — 버려지는 문서에 에러 화면을 그리지 않기 위함
    window.__g7Unloading = false;
    window.addEventListener('pagehide', function () { window.__g7Unloading = true; });
    window.addEventListener('pageshow', function (e) { if (e.persisted) window.__g7Unloading = false; });

    // 부트스트랩 상태 — 정적 <script> 의 onerror/onload 가 여기에 기록한다
    var bootstrap = window.__g7Bootstrap = {
        failed: false,
        pending: 0,

        /**
         * 정적 <script> 로드 실패 시 동적 재시도를 시작한다.
         *
         * <script> 의 onerror 는 실패 사유를 주지 않으므로(404 인지 네트워크 유실인지
         * 구분 불가) 모든 실패를 재시도한다. 404 라면 3회 후 실패로 끝나므로 안전하다.
         *
         * @param src      재시도할 스크립트 URL
         * @param attempt  현재 시도 번호 (1부터)
         */
        retry: function (src, attempt) {
            attempt = attempt || 1;

            if (window.__g7Unloading) return;

            if (attempt >= MAX_ATTEMPTS) {
                console.error(LABEL + ' Failed to load after ' + MAX_ATTEMPTS + ' attempts: ' + src);
                bootstrap.failed = true;
                bootstrap.renderFallback();
                return;
            }

            var delay = BASE_DELAY_MS * Math.pow(2, attempt - 1);
            console.warn(LABEL + ' Script load failed (attempt ' + attempt + '/' + MAX_ATTEMPTS + '), retrying in ' + delay + 'ms: ' + src);

            setTimeout(function () {
                var script = document.createElement('script');
                script.src = src;
                script.async = false; // 삽입 순서대로 실행 (코어 → 컴포넌트 순서 보장)
                script.onload = function () {
                    bootstrap.pending -= 1;
                    bootstrap.tryInit();
                };
                script.onerror = function () {
                    if (script.parentNode) script.parentNode.removeChild(script);
                    bootstrap.retry(src, attempt + 1);
                };
                document.head.appendChild(script);
            }, delay);
        },

        /**
         * 부트스트랩 최종 실패 시 사용자에게 보이는 정적 폴백을 심는다.
         *
         * 코어 번들이 없을 수 있으므로 템플릿 엔진에 의존하지 않는 순수 DOM + 인라인 스타일.
         * 종전에는 콘솔에만 기록되어 사용자에게는 영구 백지로 보였다.
         */
        renderFallback: function () {
            if (window.__g7Unloading) return;

            var app = document.getElementById('app');
            if (!app) return;
            if (app.childElementCount > 0) return; // 이미 렌더된 화면은 덮지 않는다

            app.innerHTML =
                '<div style="min-height:60vh;display:flex;align-items:center;justify-content:center;padding:24px;' +
                'font-family:system-ui,-apple-system,\'Segoe UI\',sans-serif;">' +
                '<div style="text-align:center;max-width:420px;">' +
                '<div style="font-size:40px;line-height:1;margin-bottom:16px;">&#9888;&#65039;</div>' +
                '<h1 style="font-size:18px;font-weight:600;margin:0 0 8px;color:#111827;">' +
                @json(__('errors.bootstrap.title')) +
                '</h1>' +
                '<p style="font-size:14px;line-height:1.6;margin:0 0 20px;color:#6b7280;">' +
                @json(__('errors.bootstrap.message')) +
                '</p>' +
                '<button type="button" onclick="window.location.reload()" ' +
                'style="border:0;border-radius:6px;background:#2563eb;color:#fff;font-size:14px;font-weight:500;' +
                'padding:10px 20px;cursor:pointer;">' +
                @json(__('errors.bootstrap.reload')) +
                '</button>' +
                '</div></div>';
        },

        /**
         * 두 번들이 모두 로드된 뒤 엔진을 초기화한다.
         *
         * 재시도로 늦게 도착한 경우에도 정확히 1회만 초기화되도록 pending 카운터로 게이트한다.
         */
        tryInit: function () {
            if (bootstrap.pending > 0 || bootstrap.failed || bootstrap.initialized) return;
            bootstrap.initialized = true;

            if (window.G7Core && window.G7Core.initTemplateApp) {
                window.G7Core.initTemplateApp(@json($initConfig));
                return;
            }

            // 번들은 받았으나 전역이 없다 = 번들 자체가 손상됨
            console.error(LABEL + ' G7Core.initTemplateApp is not available');
            bootstrap.renderFallback();
        },
    };

    // 정적 <script> 2개(코어 + 컴포넌트)가 완료돼야 초기화한다
    bootstrap.pending = 2;
})();
</script>

{{-- 코어 렌더링 엔진 — 정적 태그 유지 (프리로드 스캐너가 선행 로드) --}}
<script
    src="{{ $coreEngineSrc }}"
    onload="window.__g7Bootstrap.pending -= 1; window.__g7Bootstrap.tryInit();"
    onerror="window.__g7Bootstrap.retry(this.src, 1);"
></script>

@include('partials.template-externals-scripts', ['position' => 'before-template'])

{{-- 템플릿 컴포넌트 번들 (IIFE) — 정적 태그 유지 --}}
<script
    src="{{ $componentsSrc }}"
    onload="window.__g7Bootstrap.pending -= 1; window.__g7Bootstrap.tryInit();"
    onerror="window.__g7Bootstrap.retry(this.src, 1);"
></script>
