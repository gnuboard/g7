/**
 * 409(동시 수정) 응답 본문의 버전 번호 판독.
 *
 * 서버(`ResponseHelper::error`)는 `current_version`/`your_version` 을 최상위가 아니라 `errors`
 * 아래에 싣는다. 최상위만 읽으면 값이 없어 -1 로 떨어지고 배너가 「최신 버전: -1」 을 표시한다.
 * 레이아웃 저장·확장 저장·inject_props 교차 저장 세 경로가 같은 판독을 쓴다.
 *
 * @since engine-v1.66.1
 */

/**
 * @param body 파싱된 응답 본문(형식 미상)
 * @param key `current_version` | `your_version`
 * @returns 숫자 또는 undefined — `errors.{key}` 우선, 최상위 폴백(구 응답 형식 호환)
 */
export function readConflictVersion(
  body: unknown,
  key: 'current_version' | 'your_version',
): number | undefined {
  const b = body as ({ errors?: Record<string, unknown> } & Record<string, unknown>) | null | undefined;
  const nested = b?.errors?.[key];
  if (typeof nested === 'number') return nested;
  const top = b?.[key];
  return typeof top === 'number' ? top : undefined;
}
