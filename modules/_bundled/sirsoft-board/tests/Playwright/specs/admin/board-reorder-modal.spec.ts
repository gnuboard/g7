/**
 * 관리자 게시판 목록 — 「순서 변경」 모달 브라우저 흐름 (#142).
 *
 * 단위(Vitest admin-board-reorder-modal)는 레이아웃 JSON 의 바인딩 식을 값으로 평가하고, Feature(BoardReorderApiTest)는
 * 서버 저장·422·403 을 검증한다. 이 spec 은 실제 브라우저에서 단추 → 모달 → ▼ 로 옮기기 → 저장 활성 → 취소 → 다시 열면
 * 서버 순서 그대로인지를 본다. 공유 개발 사이트의 순서를 바꾸지 않도록 **저장하지 않는 경로**만 탄다.
 * 넓은 화면은 끌기 손잡이가 보이고, 390px 에서는 손잡이를 숨기고 ▲▼ 로만 옮긴다.
 *
 * 실행 뒤 `php artisan playwright:cleanup` 으로 발급 토큰·시험 계정을 지운다.
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const LIST_URL = '/admin/boards';

/**
 * 모달 행의 slug 를 화면 순서대로 읽는다.
 *
 * @param page Playwright 페이지
 * @returns slug 목록
 */
async function rowSlugs(page: import('@playwright/test').Page): Promise<string[]> {
  return page
    .locator('[data-testid^="board-reorder-row-"]')
    .evaluateAll((rows) => rows.map((row) => (row.getAttribute('data-testid') ?? '').replace('board-reorder-row-', '')));
}

test.describe('관리자 게시판 목록 — 순서 변경 모달 (#142)', () => {
  // @scenario case=e2e_reorder_modal_flow
  // @effects e2e_reorder_modal_opens_moves_cancels
  test('단추로 모달을 열고 ▼ 로 옮기면 저장이 켜지며, 취소 후 다시 열면 서버 순서 그대로다', async ({ page, boardManageToken }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(LIST_URL);

    const button = page.getByTestId('board-reorder-button');
    await expect(button).toBeVisible({ timeout: 30_000 });
    await button.click();

    const rows = page.locator('[data-testid^="board-reorder-row-"]');
    await expect(rows.first()).toBeVisible({ timeout: 15_000 });
    const original = await rowSlugs(page);
    expect(original.length).toBeGreaterThan(1);

    const save = page.getByTestId('board-reorder-save');
    await expect(save).toBeDisabled();
    await expect(page.getByTestId(`board-reorder-up-${original[0]}`)).toBeDisabled();
    await expect(page.getByTestId(`board-reorder-down-${original[original.length - 1]}`)).toBeDisabled();
    await expect(page.getByTestId(`board-reorder-drag-${original[0]}`)).toBeVisible();

    await page.getByTestId(`board-reorder-down-${original[0]}`).click();
    await expect.poll(async () => (await rowSlugs(page)).slice(0, 2)).toEqual([original[1], original[0]]);
    await expect(save).toBeEnabled();

    await page.getByTestId('board-reorder-cancel').click();
    await expect(rows).toHaveCount(0);

    await button.click();
    await expect(rows.first()).toBeVisible({ timeout: 15_000 });
    await expect.poll(() => rowSlugs(page)).toEqual(original);
    await page.getByTestId('board-reorder-cancel').click();
  });

  // @scenario case=e2e_reorder_modal_flow
  // @effects e2e_reorder_modal_opens_moves_cancels
  test('옮긴 뒤 Esc·✕ 로 닫아도 저장하지 않고, 다시 열면 서버 순서 그대로다', async ({ page, boardManageToken }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(LIST_URL);

    const button = page.getByTestId('board-reorder-button');
    await expect(button).toBeVisible({ timeout: 30_000 });
    const rows = page.locator('[data-testid^="board-reorder-row-"]');
    const orderRequests: string[] = [];
    page.on('request', (req) => {
      if (req.method() === 'PUT' && req.url().includes('/admin/boards/order')) orderRequests.push(req.url());
    });

    // Esc 로 닫기
    await button.click();
    await expect(rows.first()).toBeVisible({ timeout: 15_000 });
    const original = await rowSlugs(page);
    await page.getByTestId(`board-reorder-down-${original[0]}`).click();
    await expect.poll(async () => (await rowSlugs(page)).slice(0, 2)).toEqual([original[1], original[0]]);
    await page.keyboard.press('Escape');
    await expect(rows).toHaveCount(0);

    await button.click();
    await expect(rows.first()).toBeVisible({ timeout: 15_000 });
    await expect.poll(() => rowSlugs(page)).toEqual(original);

    // ✕(모달 닫기) 로 닫기
    await page.getByTestId(`board-reorder-down-${original[0]}`).click();
    await page.getByRole('dialog').getByRole('button', { name: /모달 닫기|Close/ }).click();
    await expect(rows).toHaveCount(0);

    await button.click();
    await expect(rows.first()).toBeVisible({ timeout: 15_000 });
    await expect.poll(() => rowSlugs(page)).toEqual(original);
    await page.getByTestId('board-reorder-cancel').click();

    expect(orderRequests).toEqual([]);
  });

  // @scenario case=e2e_reorder_modal_flow
  // @effects e2e_reorder_modal_opens_moves_cancels
  test('390px 화면에서는 끌기 손잡이를 숨기고 ▲▼ 로 옮긴다', async ({ page, boardManageToken }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await authenticatePage(page, boardManageToken);
    await page.goto(LIST_URL);

    await page.getByTestId('board-reorder-button').click();
    const rows = page.locator('[data-testid^="board-reorder-row-"]');
    await expect(rows.first()).toBeVisible({ timeout: 15_000 });
    const original = await rowSlugs(page);

    await expect(page.getByTestId(`board-reorder-drag-${original[0]}`)).toBeHidden();
    const box = await page.getByTestId(`board-reorder-down-${original[0]}`).boundingBox();
    expect(box?.width ?? 0).toBeGreaterThanOrEqual(40);
    expect(box?.height ?? 0).toBeGreaterThanOrEqual(40);

    await page.getByTestId(`board-reorder-up-${original[1]}`).click();
    await expect.poll(async () => (await rowSlugs(page)).slice(0, 2)).toEqual([original[1], original[0]]);
    await page.getByTestId('board-reorder-cancel').click();
  });
});
