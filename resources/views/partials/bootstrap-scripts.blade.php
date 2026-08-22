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

    // ─────────────────────────────────────────────────────────────────────────
    // 자산 URL 모드 자가 복구 (이슈 #486)
    //
    // nginx 의 정적 최적화 블록(`location ~* \.(js|css|json)$`)은 정규식 location 이라
    // 프리픽스 location 보다 먼저 매칭되고, 그 안에 PHP 핸들러가 없으면 확장자 붙은
    // 동적 엔드포인트가 PHP 에 도달하지 못한 채 404 가 된다. 이 경우 관리자 화면조차
    // 뜨지 않으므로 "설정을 바꾸세요" 안내는 순환 참조다 — 브라우저가 스스로 복구한다.
    //
    // 불변식 (계획서 §12):
    //   L1 전환은 단방향(extension → extensionless) 1회. 역방향 폴백을 만들지 않는다.
    //      양쪽 다 실패하는 상황(PHP 다운·WAF)에서 무한 왕복이 되기 때문.
    //   L2 전환 시도는 기존 MAX_ATTEMPTS 예산 안에서 소비한다(별도 예산 신설 금지).
    //   L3 자동 location.reload() 금지. 새로고침은 폴백 UI 의 사용자 클릭만.
    //   L4 모든 실패 경로는 renderFallback() 으로 수렴하고 종료한다.
    //   L5 서버 설정은 건드리지 않는다(미인증 클라이언트의 전역 설정 변경 금지).
    //   L8 pending 카운터 — 실패 element 제거 후 교체, onload 는 정확히 1회만 감소.
    // ─────────────────────────────────────────────────────────────────────────

    // 자산 URL 모드 자가 복구 헬퍼 — `partials/asset-url-recovery` 가 <head> 에서
    // 미리 정의한다(CSS <link> 의 onerror 가 그것을 먼저 쓰기 때문). 여기서는 재사용만 한다.
    // 헬퍼가 없으면(부분 include 등) 자가 복구 없이 기존 재시도 로직으로만 동작한다.
    var assetUrl = window.__g7AssetUrl || null;

    // URL 을 문서 기준 절대 URL 로 정규화한다 (ES5 — `new URL` 은 구형 브라우저에 없다).
    function absoluteUrl(src) {
        var a = document.createElement('a');
        a.href = src;
        return a.href;
    }

    // 부트스트랩 상태 — 정적 <script> 의 onerror/onload 가 여기에 기록한다
    var bootstrap = window.__g7Bootstrap = {
        failed: false,
        pending: 0,

        /**
         * 번들이 파싱 단계에서 거부됐는지 (SyntaxError 관측 결과).
         *
         * 다운로드는 성공했는데 전역이 없는 상황은 두 가지다 — ① 브라우저가 번들 문법을
         * 모른다(지원 범위 밖) ② 번들 자체가 손상됐다. 둘은 안내 문구가 달라야 하므로
         * 실제 오류를 관측해 가른다.
         */
        syntaxError: false,

        /** 부팅에 필요한 번들 URL (절대 URL). 재시도로 바뀐 URL 도 여기에 누적된다. */
        bundleSrcs: [absoluteUrl(@json($coreEngineSrc)), absoluteUrl(@json($componentsSrc))],

        /** 재시도·모드전환으로 새 URL 을 쓰게 되면 관측 대상에 추가한다. */
        trackBundleSrc: function (src) {
            var abs = absoluteUrl(src);
            if (bootstrap.bundleSrcs.indexOf(abs) === -1) bootstrap.bundleSrcs.push(abs);
        },

        /** 모드 전환을 이미 시도했는지 (L1 — 페이지 수명당 1회) */
        modeSwitched: false,

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
                bootstrap.renderFallback('network');
                return;
            }

            // 자산 URL 모드 전환 (L1·L2) — 확장자 형태가 실패했고 아직 전환 전이라면,
            // 지연 재시도 대신 확장자 없는 형태로 **즉시 1회** 시도한다.
            // 이 시도는 기존 예산(attempt)을 그대로 소비하므로 총 시도 횟수는 불변이다.
            var converted = (bootstrap.modeSwitched || !assetUrl) ? null : assetUrl.toExtensionless(src);
            if (converted && converted !== src) {
                bootstrap.modeSwitched = true;
                assetUrl.switchToExtensionless();
                console.warn(LABEL + ' Retrying with extensionless URL: ' + converted);

                bootstrap.replaceScript(converted, attempt);
                return;
            }

            var delay = BASE_DELAY_MS * Math.pow(2, attempt - 1);
            console.warn(LABEL + ' Script load failed (attempt ' + attempt + '/' + MAX_ATTEMPTS + '), retrying in ' + delay + 'ms: ' + src);

            bootstrap.trackBundleSrc(src);

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
         * 스크립트를 지연 없이 즉시 교체 삽입한다 (모드 전환 재시도용).
         *
         * L8 — onload 는 pending 을 정확히 1회만 감소시키고, 실패한 element 는
         * 제거한 뒤 다음 경로로 넘어간다. 실패 시에도 attempt 를 증가시켜
         * MAX_ATTEMPTS 예산을 공유하므로 총 네트워크 시도 횟수가 늘지 않는다(L2).
         *
         * @param src      삽입할 스크립트 URL
         * @param attempt  현재 시도 번호
         */
        replaceScript: function (src, attempt) {
            if (window.__g7Unloading) return;

            bootstrap.trackBundleSrc(src);

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
        },

        /**
         * 부트스트랩 최종 실패 시 사용자에게 보이는 정적 폴백을 심는다.
         *
         * 코어 번들이 없을 수 있으므로 템플릿 엔진에 의존하지 않는 순수 DOM + 인라인 스타일.
         * 종전에는 콘솔에만 기록되어 사용자에게는 영구 백지로 보였다.
         *
         * 사유별로 문구가 갈린다 — 새로고침이 도움이 되는 실패와 그렇지 않은 실패를
         * 같은 문구로 안내하면 사용자가 자기 회선을 탓하며 영원히 새로고침하게 된다.
         *
         * @param reason 'network'(다운로드 실패) | 'incompatible'(브라우저가 실행 못 함)
         *               | 'corrupt'(받았으나 전역 부재, 사유 미상). 미지정 시 'network'.
         */
        renderFallback: function (reason) {
            if (window.__g7Unloading) return;

            var app = document.getElementById('app');
            if (!app) return;
            if (app.childElementCount > 0) return; // 이미 렌더된 화면은 덮지 않는다

            var incompatible = reason === 'incompatible';

            var title = incompatible
                ? @json(__('errors.bootstrap.incompatible_title'))
                : @json(__('errors.bootstrap.title'));
            var message = incompatible
                ? @json(__('errors.bootstrap.incompatible_message'))
                : @json(__('errors.bootstrap.message'));

            // 새로고침해도 낫지 않는 상황에서 버튼을 두면 그것 자체가 다시 거짓 안내가 된다.
            var button = incompatible
                ? ''
                : '<button type="button" onclick="window.location.reload()" ' +
                  'style="border:0;border-radius:6px;background:#2563eb;color:#fff;font-size:14px;font-weight:500;' +
                  'padding:10px 20px;cursor:pointer;">' +
                  @json(__('errors.bootstrap.reload')) +
                  '</button>';

            app.innerHTML =
                '<div data-g7-bootstrap-fallback="' + (incompatible ? 'incompatible' : 'network') + '" ' +
                'style="min-height:60vh;display:flex;align-items:center;justify-content:center;padding:24px;' +
                'font-family:system-ui,-apple-system,\'Segoe UI\',sans-serif;">' +
                '<div style="text-align:center;max-width:420px;">' +
                '<div style="font-size:40px;line-height:1;margin-bottom:16px;">&#9888;&#65039;</div>' +
                '<h1 style="font-size:18px;font-weight:600;margin:0 0 8px;color:#111827;">' +
                title +
                '</h1>' +
                '<p style="font-size:14px;line-height:1.6;margin:0 0 20px;color:#6b7280;">' +
                message +
                '</p>' +
                button +
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

            // 번들은 받았으나 전역이 없다 = 실행되지 않았다.
            // SyntaxError 를 관측했다면 브라우저가 문법을 모르는 것(지원 범위 밖)이고,
            // 그렇지 않으면 사유 미상(번들 손상 등)이므로 기존 문구를 유지한다.
            console.error(LABEL + ' G7Core.initTemplateApp is not available'
                + (bootstrap.syntaxError ? ' (bundle rejected at parse time)' : ''));
            bootstrap.renderFallback(bootstrap.syntaxError ? 'incompatible' : 'corrupt');
        },
    };

    // 번들의 파싱 실패 관측 (ES5) — 파싱 오류는 <script> 의 error 가 아니라 load 를
    // 발생시키므로 element 이벤트로는 구분할 수 없다. window 의 error 이벤트로만 보인다.
    window.addEventListener('error', function (e) {
        if (!e || !e.filename) return;
        if (bootstrap.bundleSrcs.indexOf(absoluteUrl(e.filename)) === -1) return;

        var message = e.message ? String(e.message) : '';
        if (message.indexOf('SyntaxError') !== -1 || (e.error && e.error.name === 'SyntaxError')) {
            bootstrap.syntaxError = true;
        }
    }, true);

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
