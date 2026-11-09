/**
 * E2E: 사용자 화면 「관리자에서 수정」 단추와 관리자 「크게 보기」 미리보기 창 위치
 *
 * @scenario-manifest tests/scenarios/hero-slider.yaml
 * @effects user_screen_reflects_admin_change, test_data_cleaned_up
 *
 * 수정 단추는 공개 응답의 수정 권한(abilities.can_update)으로 그린다 — 비로그인 방문자에게는 없고, 배너 수정 권한이 있는 회원에게는
 * 슬라이더 오른쪽 위에 지금 보이는 배너의 관리자 수정 화면을 새 탭으로 여는 링크가 있다. 홈에는 다른 배너가 함께 있을 수 있으므로
 * 링크가 가리키는 배너는 화면의 활성 장에서 읽는다. 미리보기 창은 관리자 CSS 초기화와 무관하게 화면 가운데에 떠야 한다.
 */
import { test, expect, authenticatePage, createBanner, gotoUser, hidePopups, uniqueTitle, ADMIN_LIST, VIEWPORTS } from '../fixtures/hero-auth';
import type { Page } from '@playwright/test';

// 배너를 만들고 사이트·관리자 화면을 여러 번 연다 — 개발 사이트 첫 로딩이 느리다
test.setTimeout(150_000);

// 사이트 팝업이 슬라이더를 덮지 않게 비운다
test.beforeEach(async ({ page }) => {
  await hidePopups(page);
});

/**
 * 사이트 홈을 열고 사양이 만든 배너 제목이 슬라이더에 붙을 때까지 기다린다.
 *
 * @param page 페이지
 * @param title 기다릴 제목
 */
async function openHome(page: Page, title: string): Promise<void> {
  await page.setViewportSize(VIEWPORTS.desktop);
  await gotoUser(page, '/');
  await expect(page.locator('.hs-slider .hs-slider__title', { hasText: title }).first()).toBeAttached({ timeout: 60_000 });
}

// @scenario surface=admin_edit_link_for_editors
// @effects test_data_cleaned_up
test('비로그인 방문자에게는 관리자에서 수정 단추가 없다', async ({ page, request, managerToken, created }) => {
  const title = uniqueTitle('수정단추 손님');
  created.push(await createBanner(request, managerToken, { title: { ko: title } }));

  await openHome(page, title);

  await expect(page.locator('a.hs-slider__admin-edit')).toHaveCount(0);
});

// @scenario surface=admin_edit_link_for_editors
// @effects user_screen_reflects_admin_change, test_data_cleaned_up
test('배너 수정 권한이 있으면 오른쪽 위에 지금 보이는 배너의 수정 화면 링크가 새 탭으로 나온다', async ({ page, request, managerToken, created }) => {
  const title = uniqueTitle('수정단추 관리자');
  created.push(await createBanner(request, managerToken, { title: { ko: title } }));
  await authenticatePage(page, managerToken);

  await openHome(page, title);

  const link = page.locator('a.hs-slider__admin-edit');
  await expect(link).toBeVisible({ timeout: 30_000 });
  // 자동 넘김을 멈춰 활성 장과 링크를 같은 순간에 읽는다
  await page.locator('section.hs-slider').hover();
  const activeId = await page.locator('section.hs-slider .hs-slider__slide--active').first().getAttribute('data-banner-id');
  await expect(link).toHaveAttribute('href', new RegExp(`${ADMIN_LIST}/${activeId}/edit$`));
  await expect(link).toHaveAttribute('target', '_blank');

  // 오른쪽 위 — 슬라이더 오른쪽 끝 안쪽, 위쪽 끝 근처
  const box = (await page.locator('section.hs-slider').boundingBox())!;
  const at = (await link.boundingBox())!;
  expect(at.x + at.width).toBeLessThanOrEqual(box.x + box.width + 0.5);
  expect(at.x).toBeGreaterThan(box.x + box.width / 2);
  expect(at.y - box.y).toBeLessThan(60);
});

// @scenario surface=admin_edit_link_for_editors
// @effects test_data_cleaned_up
test('관리자 목록의 크게 보기 미리보기 창은 화면 가운데에 뜬다', async ({ page, request, managerToken, created }) => {
  created.push(await createBanner(request, managerToken, { title: { ko: uniqueTitle('미리보기 중앙') } }));
  await authenticatePage(page, managerToken);
  await page.setViewportSize({ width: 1905, height: 1025 });

  await page.goto(`${ADMIN_LIST}?position=home_hero`);
  await page.getByRole('button', { name: '크게 보기', exact: true }).first().click({ timeout: 60_000 });
  const dialog = page.locator('dialog.hs-preview__dialog');
  await expect(dialog).toBeVisible();

  const r = (await dialog.boundingBox())!;
  // 좌우 여백이 같다 — 왼쪽 위에 붙지 않는다
  expect(Math.abs(r.x - (1905 - r.x - r.width))).toBeLessThan(2);
  // 창이 화면보다 낮으면 위아래 여백도 같다
  if (r.height < 1025) expect(Math.abs(r.y - (1025 - r.y - r.height))).toBeLessThan(2);
});
