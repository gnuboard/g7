/**
 * 내보내기 창의 쓰임새 — 브라우저 점검(2026-10-16)에서 드러난 결함·개선을 화면으로 확인한다.
 *
 *  ① 「데이터 선택」에서 행 단위를 「주문상품 1건당 1행」으로 바꾸면 그 단위의 상품 항목이 함께 골라진다.
 *  ② 휴대폰 너비에서 진행 표시가 생겨도 [내보내기] 버튼과 「총 N개」가 화면 안에 보인다.
 *  ③ 예상 행 수가 0이면 [내보내기]가 막히고 이유를 알려 준다. 범위 라벨의 건수는 목록 건수임을 밝힌다.
 *  ④ 사유·기간 오류는 그 칸 아래에 보이고, 고치면 사라진다.
 *  ⑤ 목록에 쓸 수 있는 조회 기간이 없으면 「목록의 조회 기간」을 고를 수 없다.
 *  ⑥ 조건을 바꾸면 예상 행 수를 저절로 다시 계산한다.
 *  ⑦ 다운로드 이력 창의 조건 요약에 항목 수와 합계 행 포함 여부가 있다.
 *
 * 기간이 필요한 주문 목록은 「최근 1년」을 목록 조회 기간으로 넣어 연다 — 이 사이트의 시드 주문이 그 안에 있어야 행이 나온다.
 *
 * @scenario type=orders, scope=filter
 * @effects estimate_failure_blocks_request, custom_item_row_unit_selects_item_fields, export_actions_fit_on_mobile, empty_estimate_blocks_request, validation_errors_shown_inline, list_period_option_disabled_when_unusable, estimate_recalculates_automatically, download_history_shows_field_count_and_totals
 */
import { test, expect, type Page } from '@playwright/test';
import { authenticatePage, issueToken } from '../../../../../../../tests/Playwright/fixtures/auth';

const ORDER_LIST_URL = '/admin/ecommerce/orders';
const PRODUCT_LIST_URL = '/admin/ecommerce/products';
const EXPORT_INDEX_URL = '/admin/ecommerce/exports';
const EXPORT_API = '/api/modules/sirsoft-ecommerce/admin/exports';
const ESTIMATE_API = `${EXPORT_API}/estimate`;

const day = (d: Date) => d.toISOString().slice(0, 10);

/** 최근 1년을 조회 기간으로 넣은 주문 목록 주소 */
function orderListLastYear(): string {
  const today = new Date();
  return `${ORDER_LIST_URL}?date_type=ordered_at&start_date=${day(new Date(today.getTime() - 364 * 86_400_000))}&end_date=${day(today)}`;
}

/** 주문을 다룰 수 있는 운영자(개인정보 포함) 토큰 */
function orderOperator(): string {
  return issueToken(
    'sirsoft-ecommerce.orders.read',
    'sirsoft-ecommerce.products.read',
    'sirsoft-ecommerce.exports.create',
    'sirsoft-ecommerce.exports.read',
    'sirsoft-ecommerce.exports.download',
    'sirsoft-ecommerce.exports.personal_data',
  );
}

/**
 * 목록에서 [내보내기] 창을 열고 카탈로그가 그려질 때까지 기다린다.
 *
 * @param page 화면
 * @returns 창
 */
async function openExportDialog(page: Page) {
  await page.getByRole('button', { name: '내보내기', exact: true }).first().click();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByText('파일 형식')).toBeVisible({ timeout: 20_000 });
  return dialog;
}

test.describe('내보내기 창 쓰임새', () => {
  // @scenario type=orders, row_unit=order_option
  // @effects custom_item_row_unit_selects_item_fields
  test('데이터 선택에서 주문상품 단위로 바꾸면 상품 항목이 함께 골라진다', async ({ page }) => {
    test.setTimeout(120_000);
    await authenticatePage(page, orderOperator());
    await page.goto(orderListLastYear());
    const dialog = await openExportDialog(page);

    await dialog.getByRole('radio', { name: '데이터 선택' }).click();
    await expect(dialog.getByRole('checkbox', { name: '대표 상품명', exact: true })).toBeChecked();
    await dialog.getByRole('button', { name: '주문 1건당 1행' }).click();
    await page.getByRole('option', { name: '주문상품 1건당 1행' }).click();

    // 화면의 「상품정보」 컬럼이 주문상품 단위에서 덮는 항목들이 골라진다
    for (const name of ['상품명', '옵션 조합명', '수량', '판매 단가']) {
      await expect(dialog.getByRole('checkbox', { name, exact: true }), name).toBeChecked();
    }
    // 다시 주문 단위로 돌아가면 대표 상품 항목이 돌아온다
    await dialog.getByRole('button', { name: '주문상품 1건당 1행' }).click();
    await page.getByRole('option', { name: '주문 1건당 1행' }).click();
    await expect(dialog.getByRole('checkbox', { name: '대표 상품명', exact: true })).toBeChecked();
  });

  // @scenario type=products, viewport=mobile
  // @effects export_actions_fit_on_mobile
  test('휴대폰 너비에서 진행 표시가 생겨도 버튼과 총 건수가 화면 안에 있다', async ({ page }) => {
    test.setTimeout(120_000);
    await page.setViewportSize({ width: 390, height: 844 });
    await authenticatePage(page, orderOperator());
    await page.goto(PRODUCT_LIST_URL);
    const dialog = await openExportDialog(page);
    await expect(dialog.getByText(/예상 [1-9][\d,]*행/)).toBeVisible({ timeout: 20_000 });
    await dialog.getByRole('button', { name: '내보내기', exact: true }).click();

    const status = page.getByRole('status').filter({ hasText: /파일을 만드는 중|파일 준비됨|파일을 만들지 못했습니다/ });
    await expect(status).toBeVisible({ timeout: 20_000 });

    const viewport = page.viewportSize()!.width;
    const button = page.getByRole('button', { name: '내보내기', exact: true }).first();
    await button.scrollIntoViewIfNeeded();
    const buttonBox = (await button.boundingBox())!;
    expect(buttonBox.x + buttonBox.width, '[내보내기] 버튼이 화면 밖으로 밀렸다').toBeLessThanOrEqual(viewport);
    expect(buttonBox.height, '[내보내기] 글자가 두 줄로 쪼개졌다').toBeLessThan(48);
    const statusBox = (await status.boundingBox())!;
    expect(statusBox.x + statusBox.width, '진행 표시가 화면 밖으로 밀렸다').toBeLessThanOrEqual(viewport);
    const totalBox = (await page.getByText(/^총 [\d,]+개$/).first().boundingBox())!;
    expect(totalBox.width, '「총 N개」가 찌그러졌다').toBeGreaterThan(30);
  });

  // @scenario type=orders, scope=filter
  // @effects empty_estimate_blocks_request
  test('예상 행 수가 0이면 내보내기가 막히고 이유를 알려 준다', async ({ page }) => {
    test.setTimeout(120_000);
    await authenticatePage(page, orderOperator());
    await page.goto(`${ORDER_LIST_URL}?date_type=ordered_at&start_date=2000-01-01&end_date=2000-01-31`);
    const dialog = await openExportDialog(page);

    // 범위 라벨의 건수는 목록 건수다
    await expect(dialog.getByText(/현재 검색 조건 \(목록 [\d,]+건\)/)).toBeVisible();
    await expect(dialog.getByText('예상 0행')).toBeVisible({ timeout: 20_000 });
    await expect(dialog.getByText(/내보낼 행이 없습니다/)).toBeVisible();
    await expect(dialog.getByRole('button', { name: '내보내기', exact: true })).toBeDisabled();
  });

  // @scenario type=orders, permission=personal_data
  // @effects validation_errors_shown_inline, reason_field_announces_required_and_invalid
  test('사유 없이 요청하면 사유 칸 아래에 오류가 보이고, 적으면 사라진다', async ({ page }) => {
    test.setTimeout(120_000);
    await authenticatePage(page, orderOperator());
    await page.goto(orderListLastYear());
    const dialog = await openExportDialog(page);
    await expect(dialog.getByText(/예상 [1-9][\d,]*행/)).toBeVisible({ timeout: 20_000 });

    const responsePromise = page.waitForResponse((res) => res.url().endsWith(EXPORT_API) && res.request().method() === 'POST');
    await dialog.getByRole('button', { name: '내보내기', exact: true }).click();
    expect((await responsePromise).status()).toBe(422);

    const reasonInput = dialog.getByRole('textbox', { name: '다운로드 사유' });
    const message = dialog.getByText('개인정보가 담긴 항목을 내보내려면 사유를 입력하세요.');
    // 창 위에 모아 보이지 않고 사유 칸 바로 아래 한 곳에만 보인다
    await expect(message).toHaveCount(1);
    const inputBox = (await reasonInput.boundingBox())!;
    const messageBox = (await message.boundingBox())!;
    expect(messageBox.y, '오류가 사유 칸 아래에 있지 않다').toBeGreaterThan(inputBox.y);
    await expect(reasonInput).toHaveClass(/input-error/);
    // 보조기술에도 필수·오류 상태를 알린다
    await expect(reasonInput).toHaveJSProperty('required', true);
    await expect(reasonInput).toHaveAttribute('aria-invalid', 'true');

    await reasonInput.fill('E2E 사유');
    await reasonInput.blur();
    await expect(message).toHaveCount(0);
    await expect(reasonInput).toHaveAttribute('aria-invalid', 'false');
  });

  // @scenario type=orders, scope=filter
  // @effects estimate_failure_blocks_request
  test('기간이 잘못되면 예상 칸이 이유를 알리고 내보내기가 막힌다', async ({ page }) => {
    test.setTimeout(120_000);
    await authenticatePage(page, orderOperator());
    await page.goto(orderListLastYear());
    const dialog = await openExportDialog(page);
    await expect(dialog.getByText(/예상 [1-9][\d,]*행/)).toBeVisible({ timeout: 20_000 });

    await dialog.getByRole('radio', { name: '직접 지정' }).click();
    const dates = dialog.locator('input[type="date"]');
    await dates.nth(0).fill(day(new Date(Date.now() - 10 * 86_400_000)));
    await dates.nth(1).fill(day(new Date(Date.now() - 20 * 86_400_000)));

    await expect(dialog.getByText('종료일은 시작일보다 빠를 수 없습니다.')).toBeVisible({ timeout: 10_000 });
    await expect(dialog.getByText(/고치면 예상 행 수를 다시 계산합니다/)).toBeVisible();
    await expect(dialog.getByText(/조건을 바꿨습니다/)).toHaveCount(0);
    await expect(dialog.getByRole('button', { name: '내보내기', exact: true })).toBeDisabled();
  });

  // @scenario type=orders, scope=filter
  // @effects list_period_option_disabled_when_unusable
  test('목록에 조회 기간이 없으면 「목록의 조회 기간」을 고를 수 없다', async ({ page }) => {
    test.setTimeout(120_000);
    await authenticatePage(page, orderOperator());
    await page.goto(ORDER_LIST_URL);
    const dialog = await openExportDialog(page);

    await expect(dialog.getByRole('radio', { name: '목록의 조회 기간' })).toBeDisabled();
    await expect(dialog.getByRole('radio', { name: '직접 지정' })).toBeChecked();
  });

  // @scenario type=orders, scope=filter
  // @effects estimate_recalculates_automatically
  test('조건을 바꾸면 예상 행 수를 저절로 다시 계산한다', async ({ page }) => {
    test.setTimeout(120_000);
    await authenticatePage(page, orderOperator());
    await page.goto(orderListLastYear());
    const dialog = await openExportDialog(page);
    await expect(dialog.getByText(/예상 [\d,]+행/)).toBeVisible({ timeout: 20_000 });

    await dialog.getByRole('radio', { name: '직접 지정' }).click();
    const estimate = page.waitForRequest((req) => req.url().endsWith(ESTIMATE_API) && req.method() === 'POST', { timeout: 10_000 });
    const start = day(new Date(Date.now() - 30 * 86_400_000));
    await dialog.locator('input[type="date"]').nth(0).fill(start);
    const body = (await estimate).postDataJSON();
    expect(body?.period?.start_date).toBe(start);
    await expect(dialog.getByText(/예상 [\d,]+행/)).toBeVisible({ timeout: 10_000 });
  });

  // @scenario type=products, action=download
  // @effects download_history_shows_field_count_and_totals
  test('다운로드 이력 창의 조건에 항목 수와 합계 행 포함 여부가 있다', async ({ page }) => {
    test.setTimeout(240_000);
    const token = orderOperator();
    const created = await page.request.post(EXPORT_API, {
      headers: { Authorization: `Bearer ${token}`, 'Idempotency-Key': `e2e-history-${Date.now()}`, Accept: 'application/json' },
      data: {
        type: 'products', format: 'csv', column_mode: 'custom', fields: ['product.name', 'product.selling_price'], row_unit: 'product', scope: 'all',
        period: { mode: 'current' }, locale_mode: 'locale', include_totals: true,
      },
    });
    expect(created.status(), await created.text()).toBe(202);
    const exportId = Number((await created.json())?.data?.id);
    let fileName = '';
    // 파일은 큐 워커가 만든다 — 워커가 1분 간격(cron)이면 완료까지 1분 넘게 걸리므로 넉넉히 기다린다(sync 큐면 즉시)
    await expect.poll(async () => {
      const data = (await (await page.request.get(`${EXPORT_API}/${exportId}`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } })).json())?.data;
      fileName = String(data?.file_name ?? '');
      return data?.status;
    }, { timeout: 150_000, intervals: [2_000] }).toMatch(/completed|failed/);

    await authenticatePage(page, token);
    await page.goto(EXPORT_INDEX_URL);
    const row = page.getByRole('row').filter({ hasText: fileName }).first();
    await expect(row).toBeVisible({ timeout: 20_000 });
    const historyItem = page.getByRole('menuitem', { name: '다운로드 이력' });
    await expect(async () => {
      await row.getByRole('button', { name: '작업' }).click();
      await expect(historyItem).toBeVisible({ timeout: 2_000 });
    }).toPass({ timeout: 20_000 });
    await historyItem.click();

    const dialog = page.getByRole('dialog', { name: '다운로드 이력' });
    await expect(dialog.getByText(/항목 수 2개/)).toBeVisible({ timeout: 10_000 });
    await expect(dialog.getByText(/합계 행 포함/)).toBeVisible();
  });

  // @scenario type=products, totals=on
  // @effects field_change_recalculates_estimate, totals_hint_when_nothing_summable
  test('데이터 선택을 비웠다 다시 고르면 예상 행 수를 저절로 다시 받고, 더할 열이 없으면 합계 안내가 보인다', async ({ page }) => {
    test.setTimeout(120_000);
    await authenticatePage(page, orderOperator());
    await page.goto(PRODUCT_LIST_URL);
    const dialog = await openExportDialog(page);
    await expect(dialog.getByText(/예상 [1-9][\d,]*행/)).toBeVisible({ timeout: 20_000 });

    await dialog.getByRole('radio', { name: '데이터 선택' }).click();
    await dialog.getByRole('button', { name: '전체 해제', exact: true }).click();
    // 비우면 그 자리에서 이유를 알리고 요청을 막는다 — [다시 계산]을 누르지 않아도 된다
    await expect(dialog.getByText('내보낼 항목을 하나 이상 고르세요.')).toBeVisible({ timeout: 10_000 });
    await expect(dialog.getByRole('button', { name: '내보내기', exact: true })).toBeDisabled();

    const codeBox = dialog.getByRole('checkbox', { name: '상품코드', exact: true });
    // 체크 상자는 상태를 바꾼 뒤 다시 그려진다 — 눌러 두고 체크 표시가 나타날 때까지 기다린다
    await codeBox.click();
    await expect(codeBox).toBeChecked();
    await expect(dialog.getByText(/예상 [1-9][\d,]*행/)).toBeVisible({ timeout: 10_000 });
    await expect(dialog.getByText('내보낼 항목을 하나 이상 고르세요.')).toHaveCount(0);
    await expect(dialog.getByRole('button', { name: '내보내기', exact: true })).toBeEnabled();

    // 상품코드만으로는 더할 열이 없다 — 합계를 켜면 합계 행이 생기지 않는다고 미리 알린다
    const totalsHint = dialog.getByText(/더할 수 있는 열\(금액·수량\)이 없어 합계 행이 생기지 않습니다/);
    const totalsBox = dialog.getByRole('checkbox', { name: '마지막 줄에 합계 행 넣기', exact: true });
    await totalsBox.click();
    await expect(totalsBox).toBeChecked();
    await expect(totalsHint).toBeVisible();
    const stockBox = dialog.getByRole('checkbox', { name: '재고 수량', exact: true });
    await stockBox.click();
    await expect(stockBox).toBeChecked();
    await expect(totalsHint).toHaveCount(0);
  });
});
