/**
 * CKEditor5 동봉 자산 URL 해석
 *
 * CKEditor5 본체·CSS·번역은 종전에 `cdn.ckeditor.com` 에서 실시간으로 받아왔다.
 * CDN 순단·방화벽·광고차단기로 실패하면 에러 표시 없이 입력창 자리만 비어 글을
 * 작성할 수 없었고, 자체 서버 로그에는 흔적이 남지 않았다. 이제 플러그인이 자산을
 * 동봉하고 same-origin 으로 서빙한다.
 *
 * URL 을 문자열로 조립하지 않고 `G7Core.asset.plugin()` 을 거치는 이유: 확장자를
 * 정적 location 이 가로채는 서버에서는 자산 URL 이 `?file=` 쿼리 형태여야 한다.
 * 문자열 조립은 그 서버에서만 404 가 되며, 그것은 지금 CDN 이 죽었을 때와 같은 증상이다.
 *
 * @module ckeditorAssets
 */

/** 동봉한 CKEditor5 버전 (`dist/vendor/ckeditor5/{VERSION}/`) */
export const CKEDITOR5_VERSION = '43.3.1';

/** 플러그인 식별자 */
const PLUGIN_ID = 'sirsoft-ckeditor5';

/** 동봉 자산 루트 (플러그인 루트 기준 — 플러그인 자산 경로는 `dist/` 를 포함한다) */
const VENDOR_ROOT = `dist/vendor/ckeditor5/${CKEDITOR5_VERSION}`;

/** CKEditor5 CSS 로드 상태 추적용 엘리먼트 ID */
export const CKEDITOR5_CSS_ID = 'ckeditor5-vendor-css';

/**
 * 코어 자산 URL API 를 가져옵니다.
 *
 * 확장 IIFE 번들은 코어의 `assetUrl.ts` 를 import 할 수 없어 전역을 통한다.
 * API 가 없으면 **URL 을 직접 조립하지 않고 throw** 한다 — 조립한 URL 은 자산 URL
 * 이중 모드에서 조용히 404 가 되므로, 실패를 여기서 드러내 폴백이 받게 하는 편이 낫다.
 *
 * @return 코어 자산 URL 생성기
 * @throws Error 코어 자산 API 가 없을 때 (코어 7.0.10 미만)
 */
function assetApi(): { plugin: (id: string, path: string) => string } {
    const api = (window as any)?.G7Core?.asset;

    if (!api || typeof api.plugin !== 'function') {
        throw new Error(
            'G7Core.asset is unavailable — core 7.0.10 or later is required to resolve bundled CKEditor5 assets'
        );
    }

    return api;
}

/**
 * CKEditor5 본체(UMD) URL 을 돌려줍니다.
 *
 * 레이아웃 확장(`resources/extensions/html-editor.json`)이 이 스크립트를 로드하므로
 * 런타임에서 이 함수를 쓰는 곳은 재시도 경로뿐이다.
 *
 * @return string UMD 스크립트 URL
 */
export function ckeditorScriptUrl(): string {
    return assetApi().plugin(PLUGIN_ID, `${VENDOR_ROOT}/ckeditor5.umd.js`);
}

/**
 * CKEditor5 CSS URL 을 돌려줍니다.
 *
 * @return string CSS URL
 */
export function ckeditorCssUrl(): string {
    return assetApi().plugin(PLUGIN_ID, `${VENDOR_ROOT}/ckeditor5.css`);
}

/**
 * CKEditor5 번역 파일 URL 을 돌려줍니다.
 *
 * @param locale 로케일 코드 (예: `ko`)
 * @return string 번역 스크립트 URL
 */
export function ckeditorTranslationUrl(locale: string): string {
    return assetApi().plugin(PLUGIN_ID, `${VENDOR_ROOT}/translations/${locale}.umd.js`);
}

/**
 * 코어 자산 URL API 사용 가능 여부를 돌려줍니다.
 *
 * 호출부가 throw 를 기다리지 않고 미리 분기해야 할 때 쓴다.
 *
 * @return bool 사용 가능하면 true
 */
export function hasAssetApi(): boolean {
    return typeof (window as any)?.G7Core?.asset?.plugin === 'function';
}
