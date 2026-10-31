/**
 * SSO 연동 — policy 영역 핸들러 모음. `resources/js/handlers/index.ts` 가 합친다.
 *
 * 레이아웃에서는 `sirsoft-sso.{키}` 로 부른다(등록할 때 플러그인 식별자가 앞에 붙는다).
 */
export const policyHandlers: Record<string, (...args: never[]) => unknown> = {};
