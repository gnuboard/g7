{{--
    자산 URL 모드 자가 복구 — 공용 헬퍼 (이슈 #486 §5)

    `<head>` 의 CSS `<link>` 보다 **먼저** 로드되어야 한다. CSS 링크의 onerror 가
    이 헬퍼를 호출하기 때문이며, 부트스트랩 스크립트(`<body>`)도 같은 헬퍼를 재사용한다.

    코어 번들 로드 전에 실행되므로 외부 의존성 없는 순수 인라인 JS 여야 한다
    (닭-달걀: 코어가 없으면 코어를 받아올 코드도 없다).

    ## 배경

    nginx 의 정적 최적화 블록은 정규식 location 이라 프리픽스 location 보다 먼저
    매칭된다. 그 안에 PHP 핸들러가 없으면 확장자 붙은 동적 엔드포인트가 PHP 에
    도달하지 못한 채 404 가 된다. 관리자 화면조차 뜨지 않으므로 "설정을 바꾸세요"
    안내는 순환 참조 — 브라우저가 스스로 복구해야 한다.

    ## 불변식 (계획서 §12)

    L1 전환은 단방향(extension → extensionless) 1회. 역방향 폴백을 만들지 않는다.
    L3 자동 location.reload() 금지.
    L5 서버 설정은 건드리지 않는다 (미인증 클라이언트의 전역 설정 변경 금지).
    L7 localStorage 캐시는 cache_version 을 키에 포함하고 TTL 을 둔다.
--}}
<script>
(function () {
    'use strict';

    var MODE_EXTENSIONLESS = 'extensionless';
    var FILE_QUERY_PARAM = 'file';
    var STORAGE_TTL_MS = 24 * 60 * 60 * 1000;

    // cache_version 은 blade 에서 직접 주입한다. 이 파샬은 <head> 에서 실행되어
    // window.G7Config(<body> 에서 정의) 가 아직 없기 때문 — 참조하면 항상 0 이 되어
    // 캐시 키가 어긋난다.
    var CACHE_VERSION = {{ (int) ($extensionCacheVersion ?? 0) }};

    function storageKey() {
        return 'g7_asset_url_mode:' + CACHE_VERSION;
    }

    /**
     * 확장자 형태 API URL 을 확장자 없는 형태로 변환한다.
     *
     * `resources/js/core/support/assetUrl.ts::convertToCurrentMode` 와 **동일 규칙**이어야
     * 한다. 여기는 코어 번들 로드 전이라 import 가 불가능해 인라인으로 둔다 — 드리프트는
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
     * 모드 전환을 1회 확정한다 (L1 — 단방향 1회).
     *
     * 서버 설정은 바꾸지 않는다(L5). 전역 플래그와 localStorage 캐시만 갱신하며,
     * 이후 엔진의 모든 fetch 가 이 값을 읽어 올바른 형태를 쓴다.
     *
     * @returns 이번 호출로 전환되었으면 true
     */
    function switchToExtensionless() {
        if (window.__g7AssetUrlMode === MODE_EXTENSIONLESS) return false;

        // 독립 전역에 기록한다. <body> 의 `window.G7Config = {...}` 대입이 이 파샬보다
        // 나중에 실행되어 객체를 통째로 교체하므로, G7Config 에만 쓰면 덮여 사라진다.
        // G7Config 는 이 값을 초기값으로 읽어간다(app/admin blade 참조).
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

        var converted = toExtensionless(link.getAttribute('href') || '');
        if (!converted) return;

        switchToExtensionless();
        link.href = converted;
    }

    restoreCachedMode();

    window.__g7AssetUrl = {
        MODE_EXTENSIONLESS: MODE_EXTENSIONLESS,
        toExtensionless: toExtensionless,
        switchToExtensionless: switchToExtensionless,
        restoreCachedMode: restoreCachedMode,
        recoverStylesheet: recoverStylesheet
    };
})();
</script>
