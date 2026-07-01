/**
 * editorCacheBust.ts — 편집기 레이아웃 fetch 의 클라이언트 캐시-버스트 nonce (SSoT)
 *
 * 편집기는 레이아웃/호스트 content 를 `/api/layouts/{id}/{name}.json?v={cacheVersion}.{nonce}` 로
 * fetch 한다. `cacheVersion`(window.G7Config.cache_version)은 확장 install/activate/코어 빌드 시점에만
 * bump 되므로, **같은 세션 안에서 서버 content 가 바뀌는 동작**(저장·버전 복원)은 이 nonce 로 URL 을
 * 달리해 브라우저 HTTP 캐시 stale 응답을 우회한다.
 *
 * 종전엔 nonce 가 `useLayoutDocument` 모듈 지역 변수라 저장 경로에서만 증가했고, 확장 문서
 * (`useExtensionDocument`)와 버전 복원(reload)이 이를 공유하지 못해 복원 후 캔버스가 stale 로 남아
 * 새로고침해야만 갱신됐다. 본 모듈로 nonce 를
 * 일원화해 어느 문서 hook 이든 같은 카운터를 읽고 올린다.
 *
 * @since engine-v1.50.0
 */

let nonce = 0;

/** 현재 캐시-버스트 nonce (fetch URL `?v={cacheVersion}.{nonce}` 합성용). */
export function getCacheBustNonce(): number {
  return nonce;
}

/** nonce 를 1 증가시킨다 — 서버 content 변경 동작(저장 성공 / reload 강제 회수) 직후 호출. */
export function bumpCacheBustNonce(): void {
  nonce += 1;
}
