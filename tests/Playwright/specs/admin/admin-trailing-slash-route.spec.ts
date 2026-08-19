/**
 * E2E: 끝 슬래시가 붙은 관리자 경로(`/admin/`) 라우팅
 *
 * 회귀: 클라이언트 라우터가 라우트 패턴을 끝 슬래시 없는 형태(언어 prefix 형태
 * 포함)로만 정의하는데, `match()` 가 들어온 경로의 끝 슬래시를 정규화하지 않아 `/admin/`
 * (끝 슬래시)가 어떤 라우트에도 매칭되지 않고 404 로 떨어졌다. 특히 로그인이
 * 풀린 상태로 `/admin/` 에 접근하면 대시보드 → 로그인 리다이렉트 흐름 대신 404 가
 * 노출됐다. 수정: `Router.match()` 진입 시 끝 슬래시를 정규화한다.
 *
 * 단위 테스트(Router.test.ts)가 매칭 로직을 잠그지만, 실제 브라우저에서
 * 재현한 증상이므로(단위 green + 브라우저 깨짐 방지) 브라우저 레벨로도 잠근다.
 *
 * 축 요약(마커 아님 — 평문): admin_trailing_slash_unauthenticated, admin_no_trailing_slash_unauthenticated.
 * 효과 요약(마커 아님 — 평문): redirect_to_login_not_404.
 */
import { test, expect } from '../../fixtures/auth';

/** 404 에러 페이지 특유의 안내 문구 — 이 문구가 보이면 라우팅 실패다. */
const NOT_FOUND_TEXT = '페이지를 찾을 수 없습니다';

test.describe('관리자 경로 끝 슬래시 정규화', () => {
  // @scenario admin_trailing_slash_unauthenticated
  // @effects redirect_to_login_not_404
  test('미인증 상태로 /admin/ (끝 슬래시) 접근 시 404 가 아니라 로그인으로 이동한다', async ({ page }) => {
    // authenticatePage 미호출 — 로그인 풀린 상태 모사(localStorage 에 auth_token 없음).
    await page.goto('/admin/');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 미인증 대시보드 진입 → 로그인 화면으로 리다이렉트. 끝 슬래시 미정규화 시
    // 여기서 404 페이지가 떴다.
    await expect(page).toHaveURL(/\/admin\/login/, { timeout: 20_000 });
    await expect(page.getByText(NOT_FOUND_TEXT)).toHaveCount(0);
  });

  // @scenario admin_no_trailing_slash_unauthenticated
  // @effects redirect_to_login_not_404
  test('미인증 상태로 /admin (끝 슬래시 없음) 접근도 동일하게 로그인으로 이동한다', async ({ page }) => {
    await page.goto('/admin');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect(page).toHaveURL(/\/admin\/login/, { timeout: 20_000 });
    await expect(page.getByText(NOT_FOUND_TEXT)).toHaveCount(0);
  });
});
