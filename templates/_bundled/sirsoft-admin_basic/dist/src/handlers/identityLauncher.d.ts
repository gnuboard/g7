/**
 * IDV Modal Launcher (sirsoft-admin_basic)
 *
 * 코어 `IdentityGuardInterceptor` 가 428 응답을 가로채면 호출하는 launcher 입니다.
 *
 * 코어 진입점은 `window.G7Core.identity.*` 를 통해 사용 — 템플릿 IIFE 번들이 코어 모듈을
 * 중복 포함하면서 정적 클래스 상태가 분리되는 사고를 방지합니다 (다음 우편번호 / CKEditor5 와 동일 패턴).
 *
 * sirsoft-basic 의 launcher 와 동일한 기본 흐름을 사용하지만 admin 컨텍스트 차이:
 * - 사용자는 항상 로그인 상태(관리자) → target.email 은 세션에서 자동 도출됨 → 클라이언트 추출 불필요
 * - 풀페이지 폴백 경로가 `/admin/identity/challenge`
 * - 기본 purpose 추정값이 `sensitive_action` (admin 정책은 대부분 민감 작업)
 */
/**
 * 부트스트랩 시 코어 인터셉터에 launcher 를 등록합니다.
 */
export declare function registerSirsoftAdminBasicIdentityLauncher(): void;
