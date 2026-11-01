/*
 * G7 셸 — <head> 단계 (외부 자산 실패 신호 + 자산 URL 자가 복구)
 *
 * admin·app 블레이드의 `partials/template-externals-head` 가 externals `<link>`·템플릿 CSS
 * `<link>` 보다 **먼저** 이 파일을 정적 `<script src>` 로 싣는다. 종전에는 두 파샬의 인라인
 * `<script>` 였으나 strict CSP(`script-src 'self'`, unsafe-inline 없음)에서 실행되지 않아
 * 이 파일로 옮겼다(#158). 동작은 인라인일 때와 같다.
 *
 * 손작성 ES5 — 빌드하지 않는다. 엔진 번들(es2020)을 못 읽는 구형 브라우저에서도 이 계층은
 * 살아 있어야 한다(화살표 함수·const·let·템플릿 리터럴·옵셔널 체이닝 금지 —
 * `BootstrapFallbackDiagnosisTest` 가 잠근다).
 *
 * 서버 값은 직전의 비실행 데이터 블록 `<script type="application/json" id="g7-shell-head-data">`
 * 에서 읽는다(`cacheVersion`).
 *
 * 인라인 `onerror` 속성 대신 document 캡처 단계 `error` 리스너 하나로 받는다. `error` 는
 * 버블링하지 않지만 캡처 단계에서는 document 를 지난다.
 *   - `link[data-g7-template-css]` → `__g7AssetUrl.recoverStylesheet(el)`
 *   - `[data-g7-external]`         → `__g7ExternalAssetFailed(el, data-g7-external-label)`
 */
(function () {
    'use strict';

    function readData(id) {
        var el = document.getElementById(id);
        if (!el) return {};
        try {
            return JSON.parse(el.textContent || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    var headData = readData('g7-shell-head-data');

    // ─────────────────────────────────────────────────────────────────────────
    // 자산 실패 신호 부트스트랩
    //
    // externals 는 서버가 HTML 에 직접 심으므로 브라우저가 자산에 도달하지 못해도
    // 자바스크립트에는 아무 신호가 오지 않는다. 실패는 "아이콘이 안 보인다" 로만 나타나고
    // 자체 서버 로그에도 흔적이 남지 않는다. 각 태그의 error 가 여기로 들어온다.
    //
    // 엔진 번들보다 먼저 실행되므로 대기열에 쌓아 두고, 엔진이 뜨면 배너로 흘려보낸다.
    // ─────────────────────────────────────────────────────────────────────────
    (function () {
        var queue = window.__g7ExternalAssetFailures = window.__g7ExternalAssetFailures || [];

        window.__g7RetryExternalAsset = function (el, url) {
            return new Promise(function (resolve, reject) {
                var fresh = document.createElement(el.tagName);
                var bust = url + (url.indexOf('?') === -1 ? '?' : '&') + '_g7retry=' + Date.now();

                if (el.tagName === 'LINK') {
                    fresh.rel = el.rel || 'stylesheet';
                    if (el.media) { fresh.media = el.media; }
                    if (el.crossOrigin) { fresh.crossOrigin = el.crossOrigin; }
                    fresh.href = bust;
                } else {
                    if (el.crossOrigin) { fresh.crossOrigin = el.crossOrigin; }
                    fresh.src = bust;
                }

                fresh.onload = function () {
                    if (el.parentNode) { el.parentNode.removeChild(el); }
                    resolve();
                };
                fresh.onerror = function () {
                    if (fresh.parentNode) { fresh.parentNode.removeChild(fresh); }
                    reject(new Error('external asset retry failed'));
                };

                (el.parentNode || document.head).appendChild(fresh);
            });
        };

        window.__g7ExternalAssetFailed = function (el, label) {
            if (!el) { return; }

            var url = el.tagName === 'LINK' ? el.href : el.src;
            var id = 'template-external:' + url;

            for (var i = 0; i < queue.length; i++) {
                if (queue[i].id === id) { return; }
            }

            var entry = {
                id: id,
                label: label || url,
                url: url,
                retry: function () { return window.__g7RetryExternalAsset(el, url); }
            };

            queue.push(entry);

            if (typeof window.__g7ExternalAssetSink === 'function') {
                window.__g7ExternalAssetSink(entry);
            }
        };
    })();

    // ─────────────────────────────────────────────────────────────────────────
    // 자산 URL 모드 자가 복구 — 공용 헬퍼 (이슈 #486 §5)
    //
    // CSS `<link>` 의 error 가 이 헬퍼를 호출하며, 부트스트랩(`g7-shell-boot.js`)도 같은
    // 헬퍼를 재사용한다. 코어 번들 로드 전에 실행되므로 외부 의존성이 없어야 한다
    // (닭-달걀: 코어가 없으면 코어를 받아올 코드도 없다).
    //
    // 배경: nginx 의 정적 최적화 블록은 정규식 location 이라 프리픽스 location 보다 먼저
    // 매칭된다. 그 안에 PHP 핸들러가 없으면 확장자 붙은 동적 엔드포인트가 PHP 에
    // 도달하지 못한 채 404 가 된다. 관리자 화면조차 뜨지 않으므로 "설정을 바꾸세요"
    // 안내는 순환 참조 — 브라우저가 스스로 복구해야 한다.
    //
    // 불변식 (계획서 §12):
    //   L1 전환은 단방향(extension → extensionless) 1회. 역방향 폴백을 만들지 않는다.
    //   L3 자동 location.reload() 금지.
    //   L5 서버 설정은 건드리지 않는다 (미인증 클라이언트의 전역 설정 변경 금지).
    //   L7 localStorage 캐시는 cache_version 을 키에 포함하고 TTL 을 둔다.
    //
    // 정적 게시(bake) 역변환 (#122 F15): `/build/ext/{v}/…` 정적 게시본을 참조하는 태그
    // 자산이 서빙 시점에 404 가 되면(브라우저에 캐시된 구 HTML 이 GC 된 구버전 자산을
    // 참조하는 등) 종전 `/api/…` URL 로 1회 전환한다. `/build/core/**` 는 실물 정적 파일이라
    // 계속 변환 제외. 역변환 규칙은 `resources/js/core/support/assetUrl.ts::staticToLegacy`
    // 와 1:1 — 드리프트는 `assetUrlRecovery.test.ts` 의 대조 케이스가 잡는다.
    //
    // 역변환 결과는 언제나 확장자 형태이므로, 이미 확장자 없는 형태로 확정된 모드에서는
    // 그 결과를 다시 `toExtensionless` 로 넘긴다. 그러지 않으면 위 배경의 서버에서
    // 404 를 404 로 바꾸는 셈이고, CSS `<link>` 는 교체 예산이 1회라 두 번째 기회가 없다
    // (`<script>` 는 재시도 예산이 남아 다음 시도에서 모드 전환 분기가 발동한다).
    // ─────────────────────────────────────────────────────────────────────────
    (function () {
        var MODE_EXTENSIONLESS = 'extensionless';
        var FILE_QUERY_PARAM = 'file';
        var STORAGE_TTL_MS = 24 * 60 * 60 * 1000;

        // cache_version 은 서버가 데이터 블록으로 넘긴다. 이 파일은 <head> 에서 실행되어
        // window.G7Config(<body> 에서 정의) 가 아직 없기 때문 — 참조하면 항상 0 이 되어
        // 캐시 키가 어긋난다.
        var CACHE_VERSION = parseInt(headData.cacheVersion, 10) || 0;

        function storageKey() {
            return 'g7_asset_url_mode:' + CACHE_VERSION;
        }

        /**
         * 확장자 형태 API URL 을 확장자 없는 형태로 변환한다.
         *
         * `resources/js/core/support/assetUrl.ts::convertToCurrentMode` 와 **동일 규칙**이어야
         * 한다. 여기는 코어 번들 로드 전이라 import 가 불가능해 사본을 둔다 — 드리프트는
         * `assetUrl.test.ts` 의 대조 케이스가 잡는다.
         *
         * 변환 대상이 아니면 null. 코어 엔진 번들(`/build/...`)은 public/ 의 실물 정적
         * 파일이라 변환하면 오히려 깨지므로 대상에서 제외된다.
         *
         * @param url 원본 URL
         * @returns 변환된 URL 또는 null
         */
        function toExtensionless(url) {
            if (!url) return null;

            // `<script>.src` / `<link>.href` 는 **절대 URL** 을 돌려준다
            // (`https://host/api/...`). same-origin 이면 경로만 남긴다 — 이 정규화를
            // 빠뜨리면 부트스트랩 재시도 경로에서 변환이 항상 null 이 되어 자가 복구가
            // 통째로 죽는다(외형은 "3회 재시도 후 폴백 UI" 라 원인이 드러나지 않는다).
            var origin = window.location && window.location.origin;
            if (origin && url.indexOf(origin) === 0) {
                url = url.slice(origin.length);
            }

            if (url.indexOf('/api/') !== 0) return null;

            var qi = url.indexOf('?');
            var path = qi === -1 ? url : url.slice(0, qi);
            var query = qi === -1 ? '' : url.slice(qi + 1);

            var bundle = path.match(/^\/api\/(modules|plugins)\/bundle\.(js|css)$/);
            if (bundle) {
                return '/api/' + bundle[1] + '/bundle/' + bundle[2] + (query ? '?' + query : '');
            }

            var asset = path.match(/^\/api\/(templates|modules|plugins)\/assets\/([^/]+)\/(.+)$/);
            if (asset) {
                return '/api/' + asset[1] + '/assets/' + asset[2] +
                    '?' + FILE_QUERY_PARAM + '=' + encodeURIComponent(decodeURIComponent(asset[3])) +
                    (query ? '&' + query : '');
            }

            var suffixed = path.match(/^(\/api\/.+)\.(json|js|css)$/);
            if (suffixed) {
                return suffixed[1] + (query ? '?' + query : '');
            }

            return null;
        }

        /**
         * 실패한 정적 게시(bake) URL 을 종전 API URL 로 역변환한다 (#122 F15).
         *
         * `resources/js/core/support/assetUrl.ts::staticToLegacy` 와 **동일 규칙**이어야
         * 한다 — 드리프트는 `assetUrlRecovery.test.ts` 의 대조 케이스가 잡는다.
         * 변환 대상이 아니면(정적 게시 경로가 아니면) null. `/build/core/**` 등
         * 다른 정적 파일은 대상이 아니다.
         *
         * @param url 실패한 URL
         * @returns 종전 API URL 또는 null
         */
        function staticToLegacy(url) {
            if (!url) return null;

            var origin = window.location && window.location.origin;
            if (origin && url.indexOf(origin) === 0) {
                url = url.slice(origin.length);
            }

            var match = url.match(/^\/build\/ext\/(\d+)\/(.+)$/);
            if (!match) return null;

            var version = match[1];
            var rest = match[2].split('?')[0];

            // 세 확장 타입이 같은 형태로 게시된다 (템플릿 dist/**, 모듈·플러그인 custom/**).
            var extensionAsset = rest.match(/^(templates|modules|plugins)\/([^/]+)\/assets\/(.+)$/);
            if (extensionAsset) {
                // audit:allow asset-url-builder-required reason: 코어 번들 로드 전 셸 복구기 —
                // import 불가라 규칙 사본을 직접 조립한다. 드리프트는 assetUrlRecovery.test.ts 대조가 잠근다.
                return '/api/' + extensionAsset[1] + '/assets/' + extensionAsset[2] + '/' + extensionAsset[3] + '?v=' + version;
            }

            var bundle = rest.match(/^bundles\/(modules|plugins)\.(js|css)$/);
            if (bundle) {
                return '/api/' + bundle[1] + '/bundle.' + bundle[2] + '?v=' + version;
            }

            var templateFetch = rest.match(/^templates\/([^/]+)\/(routes\.json|components\.json|lang\/([A-Za-z0-9-]+)\.json)$/);
            if (templateFetch) {
                return '/api/templates/' + templateFetch[1] + '/' +
                    templateFetch[2].replace(/\.json$/, '') + '.json?v=' + version;
            }

            return null;
        }

        /**
         * 모드 전환을 1회 확정한다 (L1 — 단방향 1회).
         *
         * 서버 설정은 바꾸지 않는다(L5). 전역 플래그와 localStorage 캐시만 갱신하며,
         * 이후 엔진의 모든 fetch 가 이 값을 읽어 올바른 형태를 쓴다.
         *
         * @returns 이번 호출로 전환되었으면 true
         */
        function switchToExtensionless() {
            if (window.__g7AssetUrlMode === MODE_EXTENSIONLESS) return false;

            // 독립 전역에 기록한다. `g7-shell-boot.js` 의 G7Config 대입이 이 파일보다
            // 나중에 실행되어 객체를 통째로 교체하므로, G7Config 에만 쓰면 덮여 사라진다.
            // G7Config 는 이 값을 초기값으로 읽어간다.
            window.__g7AssetUrlMode = MODE_EXTENSIONLESS;
            if (window.G7Config) window.G7Config.assetUrlMode = MODE_EXTENSIONLESS;

            try {
                window.localStorage.setItem(storageKey(), JSON.stringify({
                    mode: MODE_EXTENSIONLESS,
                    at: Date.now()
                }));
            } catch (e) { /* 저장 실패는 치명적이지 않다 */ }

            return true;
        }

        /**
         * 이전 방문에서 확정된 모드를 복원한다 (L7).
         *
         * cache_version 을 키에 포함하고 TTL 을 둬서 서버가 정상화된 뒤에도
         * 클라이언트가 옛 모드에 영구 고착되지 않도록 한다.
         */
        function restoreCachedMode() {
            try {
                var raw = window.localStorage.getItem(storageKey());
                if (!raw) return false;

                var parsed = JSON.parse(raw);
                if (!parsed || parsed.mode !== MODE_EXTENSIONLESS) return false;

                if (typeof parsed.at !== 'number' || Date.now() - parsed.at > STORAGE_TTL_MS) {
                    window.localStorage.removeItem(storageKey());
                    return false;
                }

                window.__g7AssetUrlMode = MODE_EXTENSIONLESS;
                if (window.G7Config) window.G7Config.assetUrlMode = MODE_EXTENSIONLESS;

                return true;
            } catch (e) {
                return false;
            }
        }

        /**
         * CSS `<link>` 로드 실패 시 확장자 없는 형태로 1회 교체한다.
         *
         * 스타일 부재는 앱을 죽이지 않으므로(엔진의 CSS 로더도 실패를 resolve 로 처리)
         * 재교체가 실패해도 조용히 끝낸다 — 폴백 UI 를 띄우지 않는다(과잉 적용 경계).
         * 교체는 링크당 1회만 (`data-g7-recovered` 마킹).
         *
         * @param link 실패한 <link> 요소
         */
        function recoverStylesheet(link) {
            if (!link || link.getAttribute('data-g7-recovered') === '1') return;
            link.setAttribute('data-g7-recovered', '1');

            var href = link.getAttribute('href') || '';

            // 정적 게시(bake) URL 실패 → 종전 API URL 로 1회 전환 (#122 F15).
            // 모드 전환과 무관한 폴백이므로 switchToExtensionless 를 호출하지 않는다.
            var legacy = staticToLegacy(href);
            if (legacy) {
                // 역변환 결과는 언제나 **확장자 형태**다. 그런데 이 헬퍼가 존재하는 이유가
                // 바로 "확장자 형태를 404 로 돌려주는 서버"(#486)이므로, 그런 서버에서는
                // 이 교체가 404 를 404 로 바꿀 뿐이다. 교체는 링크당 1회(L1)라 두 번째
                // 기회가 없어 CSS 가 영구히 붙지 않는다 — 이미 확장자 없는 형태로
                // 확정된 모드면 한 번의 교체로 서빙 가능한 형태까지 보낸다.
                if (window.__g7AssetUrlMode === MODE_EXTENSIONLESS) {
                    legacy = toExtensionless(legacy) || legacy;
                }

                link.href = legacy;
                return;
            }

            var converted = toExtensionless(href);
            if (!converted) return;

            switchToExtensionless();
            link.href = converted;
        }

        restoreCachedMode();

        window.__g7AssetUrl = {
            MODE_EXTENSIONLESS: MODE_EXTENSIONLESS,
            toExtensionless: toExtensionless,
            staticToLegacy: staticToLegacy,
            switchToExtensionless: switchToExtensionless,
            restoreCachedMode: restoreCachedMode,
            recoverStylesheet: recoverStylesheet
        };
    })();

    // ─────────────────────────────────────────────────────────────────────────
    // 인라인 onerror 대체 — document 캡처 리스너 (#158)
    //
    // 서버가 심는 태그에 표식만 남기고 실패 처리는 여기서 한다. 재시도로 새로 만든 요소는
    // 표식이 없으므로(프로퍼티 핸들러로 따로 처리) 이 리스너가 다시 잡지 않는다.
    // ─────────────────────────────────────────────────────────────────────────
    document.addEventListener('error', function (event) {
        var el = event && event.target;
        if (!el || el === window || !el.getAttribute) return;

        if (el.tagName === 'LINK' && el.hasAttribute('data-g7-template-css')) {
            if (window.__g7AssetUrl) window.__g7AssetUrl.recoverStylesheet(el);
            return;
        }

        if (el.hasAttribute('data-g7-external')) {
            window.__g7ExternalAssetFailed(el, el.getAttribute('data-g7-external-label') || '');
        }
    }, true);
})();
