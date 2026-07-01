/**
 * Smoke: 관리자 대시보드 진입 검증 (인증 필수).
 *
 * - core.templates.layouts.edit 권한 토큰을 발급하고 localStorage 주입
 * - /admin/dashboard 진입 시 401/403 으로 거부되지 않고 페이지 마운트 완료 확인
 * - PlaywrightIssueToken artisan 커맨드 + auth fixture 의 end-to-end smoke
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

test('@smoke 권한 보유 토큰으로 /admin/dashboard 진입 시 페이지가 마운트된다', async ({ page }) => {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);

  await page.goto('/admin/dashboard');

  // SPA 부트스트랩 + AuthManager 토큰 검증 + 레이아웃 마운트 대기.
  // networkidle 은 Reverb WebSocket 등 지속 연결로 인해 타임아웃이 발생하므로
  // URL 안정화 + DOM 렌더 완료(load 이벤트) 기준으로 검증한다.
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  // 인증 실패 시 AuthManager 가 /admin/login 으로 리다이렉트 — 충분히 대기
  await page.waitForFunction(
    () => !window.location.pathname.includes('/admin/login') || document.readyState === 'complete',
    { timeout: 15_000 },
  );

  expect(page.url(), '권한 보유 토큰임에도 /admin/login 으로 리다이렉트되었습니다').not.toMatch(/\/admin\/login/);
  expect(page.url(), 'URL 이 /admin/dashboard 가 아닙니다').toMatch(/\/admin\/dashboard/);
});

test('@smoke 전체회원수 stats 카드가 대시보드에 표시되지 않는다', async ({ page }) => {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);

  await page.goto('/admin/dashboard');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  // 로그인으로 튕기지 않고 대시보드가 마운트되었는지 먼저 확인
  await page.waitForFunction(
    () => !window.location.pathname.includes('/admin/login') || document.readyState === 'complete',
    { timeout: 15_000 },
  );
  expect(page.url(), '/admin/login 으로 리다이렉트되었습니다').not.toMatch(/\/admin\/login/);

  // 대시보드 콘텐츠 마운트 신호로 "대시보드" 헤더(또는 빠른메뉴) 가 보일 때까지 대기
  await page.waitForSelector('text=대시보드', { timeout: 15_000 }).catch(() => undefined);

  // 전체회원수 카드는 제거됨 — 라벨이 렌더되지 않아야 한다
  await expect(
    page.getByText('전체회원수', { exact: true }),
    '전체회원수 카드가 여전히 렌더됩니다',
  ).toHaveCount(0);
});
