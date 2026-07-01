/**
 * authToken.ts — 편집기 공용 Sanctum 토큰 헬퍼
 *
 * 편집기의 모든 인증 fetch(레이아웃 로드/저장 + 레이아웃 첨부 이미지 업로드/목록/
 * 삭제)는 동일한 `Authorization: Bearer <token>` 을 첨부해야 한다. 종전에는
 * `useLayoutDocument` 내부 `readSanctumToken` 만 토큰을 읽고, `ImagePickerControl`
 * 의 업로드 fetch 는 토큰을 누락해 `auth:sanctum` 401 을 받았다(항목3-2).
 *
 * 본 유틸로 토큰 읽기를 단일 진실 공급원화하고, 인증 헤더 빌더를 함께 제공해
 * 호출처가 `headers` 객체를 직접 만들지 않게 한다. 브라우저 외 환경(SSR/테스트)
 * 에서도 안전하게 null 을 돌려준다.
 *
 * @since engine-v1.50.0
 */

/**
 * localStorage 의 `auth_token` 을 안전하게 읽는다. 브라우저 외 환경이나
 * localStorage 접근이 차단된 환경(시크릿/iframe sandbox)에서는 null.
 *
 * @return Sanctum 토큰 문자열 또는 null
 */
export function readSanctumToken(): string | null {
  if (typeof window === 'undefined') {
    return null;
  }
  try {
    return window.localStorage?.getItem('auth_token') ?? null;
  } catch {
    return null;
  }
}

/**
 * 인증 헤더를 빌드한다. 토큰이 있으면 `Authorization: Bearer <token>` 첨부.
 *
 * `Accept: application/json` 은 항상 포함하고, 호출자가 `extra` 로
 * `Content-Type` 등을 합칠 수 있다. multipart/form-data 업로드는 브라우저가
 * boundary 를 포함한 Content-Type 을 자동 설정하므로 `Content-Type` 을
 * 명시하지 않는다(명시 시 boundary 누락으로 서버 파싱 실패).
 *
 * @param extra 추가 헤더 (Content-Type 등)
 * @return fetch headers 객체
 */
export function buildAuthHeaders(extra?: Record<string, string>): Record<string, string> {
  const headers: Record<string, string> = { Accept: 'application/json', ...(extra ?? {}) };
  const token = readSanctumToken();
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }
  return headers;
}
