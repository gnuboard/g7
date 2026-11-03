/**
 * E2E: 뒤로/앞으로 가기·새로고침 스크롤 위치 복원 (배포 번들 + 실서버 기준, engine-v1.85.0)
 *
 * 배경: 엔진에 스크롤 기억·복원이 없어 브라우저 기본 복원(popstate 직후 1회)에 맡겼다. 돌아온 화면이 데이터를 다시
 * 받느라 그 순간 짧으면 맨 위에 머물렀다(모바일 홈 2,742px → 상품 → 뒤로 = 0). 이제 라우터가 항목별 위치를 기억하고
 * 화면을 다시 그린 뒤 문서가 자라면 되돌린다.
 *
 * 판정 함수·설정 층·스냅샷은 Router.scrollRestoration.test.ts · TemplateApp.scrollRestoration.test.ts 가 고정하고,
 * 이 스펙은 실제 브라우저·배포 번들에서 관찰되는 결과(돌아온 뒤 scrollY, 브라우저 기본 복원 꺼짐)를 잠근다.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/client-router-scroll-restoration.yaml 참조.
 */
import type { Page } from '@playwright/test';
import { test, expect } from '../fixtures/auth';

/**
 * 상품 목록을 열고 라우터·목록이 준비될 때까지 기다린다. 문서가 충분히 길지 않으면 건너뛴다.
 *
 * @param page Playwright 페이지
 * @returns 목록에서 고른 상품 상세 경로
 */
async function openLongList(page: Page): Promise<string> {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/shop/products');
  await page.waitForFunction(() => Boolean((window as any).__templateApp?.router));
  await page.waitForLoadState('networkidle');
  const info = await page.evaluate(() => {
    const links = Array.from(document.querySelectorAll<HTMLAnchorElement>('a[href*="/shop/products/"]'));
    return {
      height: document.documentElement.scrollHeight,
      href: links.length > 0 ? new URL(links[links.length - 1].href).pathname : null,
    };
  });
  test.skip(info.height < 2400 || info.href === null, '상품 목록이 짧거나 상품이 없다 — 시험 데이터 필요');

  return info.href as string;
}

test.describe('뒤로/앞으로 가기 스크롤 복원', () => {
  // @scenario entry=popstate, content_timing=grows_late, config_layer=engine_default
  // @effects scroll_position_restored_after_late_content
  test('목록을 내린 뒤 상품으로 갔다가 뒤로 가면 떠날 때 위치로 돌아온다', async ({ page }) => {
    const href = await openLongList(page);
    const target = await page.evaluate(() => Math.round(Math.min(1500, document.documentElement.scrollHeight - innerHeight - 50)));
    await page.evaluate((y) => window.scrollTo({ top: y, behavior: 'instant' as ScrollBehavior }), target);
    await page.waitForTimeout(200);

    expect(await page.evaluate(() => history.scrollRestoration)).toBe('manual');

    await page.evaluate((path) => (window as any).__templateApp.router.navigate(path), href);
    await page.waitForFunction((path) => location.pathname === path, href);
    await page.waitForLoadState('networkidle');

    await page.goBack();
    await page.waitForFunction(() => location.pathname === '/shop/products');

    await expect.poll(() => page.evaluate(() => Math.round(window.scrollY)), { timeout: 6000 }).toBeGreaterThanOrEqual(target - 2);
    expect(Math.abs((await page.evaluate(() => Math.round(window.scrollY))) - target)).toBeLessThanOrEqual(2);
  });

  // @scenario entry=reload, content_timing=grows_late, config_layer=engine_default
  // @effects reload_restores_from_session_storage
  test('새로고침해도 보던 위치로 돌아온다', async ({ page }) => {
    await openLongList(page);
    const target = await page.evaluate(() => Math.round(Math.min(1200, document.documentElement.scrollHeight - innerHeight - 50)));
    await page.evaluate((y) => window.scrollTo({ top: y, behavior: 'instant' as ScrollBehavior }), target);
    await page.waitForTimeout(200);

    await page.reload();
    await page.waitForFunction(() => Boolean((window as any).__templateApp?.router));

    await expect.poll(() => page.evaluate(() => Math.round(window.scrollY)), { timeout: 6000 }).toBeGreaterThanOrEqual(target - 2);
  });

  // @scenario entry=navigate, content_timing=already_tall, config_layer=engine_default
  // @effects new_navigation_does_not_restore
  test('새 화면으로 가는 이동은 맨 위에서 시작한다', async ({ page }) => {
    const href = await openLongList(page);
    await page.evaluate(() => window.scrollTo({ top: 1000, behavior: 'instant' as ScrollBehavior }));
    await page.waitForTimeout(200);

    await page.evaluate((path) => (window as any).G7Core.dispatch({ handler: 'navigate', params: { path } }), href);
    await page.waitForFunction((path) => location.pathname === path, href);
    await page.waitForLoadState('networkidle');

    expect(await page.evaluate(() => Math.round(window.scrollY))).toBe(0);
  });
});
