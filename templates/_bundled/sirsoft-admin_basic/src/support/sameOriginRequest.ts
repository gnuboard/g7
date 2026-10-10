/**
 * 요청·이동 대상의 출처 판정 (sirsoft-admin_basic 공용)
 *
 * 컴포넌트·핸들러가 `fetch` 로 직접 요청하며 로그인 토큰을 실을 때, 대상 주소가 레이아웃 prop 이나
 * 서버 응답 본문처럼 바깥에서 정해지는 값이면 교차 출처일 수 있다. 레이아웃은 주소를 표현식으로
 * 조립할 수 있어 저장측 검사가 외부 주소를 다 잡지 못하므로, 출처를 보지 않고 토큰을 실으면 레이아웃
 * 편집 권한만으로 회원·관리자 토큰을 외부로 보낼 수 있다(KVE-2026-2350).
 *
 * 판정은 코어 `G7Core.api.isSameOriginUrl`(apiCall 과 같은 기준)에 맡긴다. 그 판정을 제공하지 않는
 * 코어에서는 `fetch` 와 같은 URL 파서로 현재 문서의 출처와 비교한다.
 */

/**
 * 요청·이동 대상이 현재 문서와 같은 출처인지 판정합니다.
 *
 * 실행 스킴(`javascript:` 등)·protocol-relative(`//host`)·백슬래시 변형(`/\host`)은 같은 출처가 아니다.
 *
 * @param url 대상 주소 (절대·상대 모두)
 * @return 같은 출처면 true (문자열이 아니거나 파싱할 수 없으면 false)
 */
export function isSameOriginRequest(url: unknown): boolean {
  const api = (window as any).G7Core?.api;

  if (typeof api?.isSameOriginUrl === 'function') {
    return api.isSameOriginUrl(url) === true;
  }

  if (typeof url !== 'string') {
    return false;
  }

  try {
    return new URL(url, window.location.href).origin === window.location.origin;
  } catch {
    return false;
  }
}
