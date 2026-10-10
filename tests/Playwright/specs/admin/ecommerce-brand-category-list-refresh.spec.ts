/**
 * 이커머스 브랜드·카테고리 관리 — 등록 패널을 연 채 목록을 새로고침해도 입력 중인 폼이 그대로다
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/brand-category-list-initlocal.yaml
 * @effects list_refetch_keeps_form_name,
 *          no_console_error_on_list_refresh,
 *          edit_panel_fills_name
 *
 * 배경(#736 브라우저 실측 2026-10-05, 트러블슈팅 state-global 사례 10): 목록 데이터 소스의 init-local 이
 * form.name.ko = "" 를 빈 기본값으로 착각해 적었는데, 데이터 소스 initLocal 의 값은 응답 경로라 목록
 * 응답 전체가 이름 칸에 들어갔다. 새로고침하면 다국어 입력이 문자열이 아닌 값을 받아 콘솔에
 * `trim is not a function` 이 나고 입력하던 이름이 덮였다.
 *
 * 저장은 하지 않는다.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

/** 콘솔 오류 수집 (권한·검증 응답 4xx 는 제외) */
function collectErrors(page: Page): string[] {
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => {
    if (m.type() === 'error' && !/status of (401|403|404|422)/.test(m.text())) errors.push(m.text());
  });

  return errors;
}

/**
 * 등록 패널을 열고 slug 를 친 뒤 목록을 새로고침한다.
 *
 * @param page 인증된 페이지
 * @param url 화면 주소
 * @param addLabel 등록 버튼 이름
 * @returns 새로고침 뒤 폼 상태
 */
async function openAddTypeAndRefresh(page: Page, url: string, addLabel: string): Promise<any> {
  await page.goto(url);
  await page.getByRole('button', { name: addLabel }).first().click();
  const slug = page.locator('input[name="slug"]:visible').first();
  await expect(slug).toBeVisible({ timeout: 20_000 });
  await slug.click();
  await slug.pressSequentially('e2e-probe', { delay: 20 });

  await page.getByRole('button', { name: '새로고침' }).first().click();
  await page.waitForTimeout(2_500);

  return await page.evaluate(() => (window as any).G7Core.state.getLocal()?.form ?? null);
}

test.describe('브랜드·카테고리 목록 새로고침', () => {
  /**
   * @scenario screen=brand
   *
   * @effects list_refetch_keeps_form_name, no_console_error_on_list_refresh
   */
  test('브랜드: 등록 패널을 연 채 새로고침해도 이름 칸이 비어 있고 slug 가 남는다', async ({ page }) => {
    await authenticatePage(page, issueToken());
    const errors = collectErrors(page);

    const form = await openAddTypeAndRefresh(page, '/admin/ecommerce/brands', '브랜드 추가');

    expect(form?.name).toEqual({ ko: '', en: '' });
    expect(form?.slug).toBe('e2e-probe');
    expect(errors.filter((e) => /trim is not a function/.test(e))).toEqual([]);
  });

  /**
   * @scenario screen=category
   *
   * @effects list_refetch_keeps_form_name, no_console_error_on_list_refresh
   */
  test('카테고리: 등록 패널을 연 채 새로고침해도 이름·설명 칸이 비어 있고 slug 가 남는다', async ({ page }) => {
    await authenticatePage(page, issueToken());
    const errors = collectErrors(page);

    const form = await openAddTypeAndRefresh(page, '/admin/ecommerce/categories', '카테고리 추가');

    expect(form?.name).toEqual({ ko: '', en: '' });
    expect(form?.description).toEqual({ ko: '', en: '' });
    expect(form?.slug).toBe('e2e-probe');
    expect(errors.filter((e) => /trim is not a function/.test(e))).toEqual([]);
  });

  /**
   * @scenario screen=brand
   *
   * @effects edit_panel_fills_name
   */
  test('브랜드 편집 패널은 고른 브랜드의 이름을 채운다 (목록 매핑 제거 뒤 회귀 대조군)', async ({ page }) => {
    const token = issueToken();
    await authenticatePage(page, token);
    const res = await page.request.get('/api/modules/sirsoft-ecommerce/admin/brands', {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    expect(res.ok()).toBe(true);
    const first = (await res.json())?.data?.data?.[0];
    expect(first?.name?.ko).toBeTruthy();

    await page.goto('/admin/ecommerce/brands');
    await page.getByText(first.name.ko, { exact: true }).first().click();
    await page.locator('button:visible', { hasText: /^편집$/ }).first().click();

    await expect
      .poll(async () => await page.evaluate(() => (window as any).G7Core.state.getLocal()?.form?.name), { timeout: 10_000 })
      .toEqual(first.name);
  });
});
