/**
 * 내보내기 — 주문 목록의 [내보내기] 창에서 요청하고 「이커머스 › 내보내기 관리」에서 확인한다.
 *
 * 브라우저로만 드러나는 축:
 *  ① 버튼은 「내보내기 요청」 권한과 그 목록의 조회 권한이 둘 다 있을 때 목록 머리 줄에 보이고, 창은 세 목록이 함께 쓰는 공용 창이다.
 *  ② 창은 별도 컨텍스트라 목록의 검색 조건·선택을 전역으로 넘겨받는다 — 넘김이 빠지면 요청 본문의 범위가 비어 422 가 난다.
 *  ③ 중복 방지 키가 요청 헤더에 실려야 한다 — 빠지면 서버가 422 로 거절한다.
 *     개인정보 열(주문자)이 기본으로 골라져 있으면 사유 칸이 열리고, 사유도 함께 실려야 접수된다.
 *  ④ [내보내기] 버튼은 「내보내기 요청」 권한과 주문 조회 권한이 함께 있어야 보인다 — 주문 조회만 있는 운영자에게는 없다.
 *  ⑤ 요청한 작업이 「내보내기 관리」 목록에 나타난다.
 *  ⑥ 상품 목록·마일리지 내역에도 버튼이 끼워지고 그 유형의 창이 카탈로그를 받아 열린다.
 *     세 목록 모두 버튼은 목록 위 「정렬 · 쪽당 개수」 줄의 오른쪽 끝에 있다.
 *  ⑦ 파일명을 누르면 일회용 주소를 발급받아 브라우저가 그 주소에서 직접 받는다 — 받은 뒤 다시 부른 목록에 횟수가 반영된다.
 *
 * @scenario type=orders, scope=filter
 * @effects order_list_export_request_accepted, export_button_hidden_without_permission, export_manage_lists_requested_job, export_button_shown_on_every_list
 */
import { test, expect, type Locator, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { authenticatePage, issueToken, issueScopedToken, purgeTrackedExports } from '../../fixtures/export-cleanup';
import { chooseRowMenuItem } from '../../fixtures/export-row-menu';

const CORE_ROOT = process.env.G7_ROOT || resolve(dirname(fileURLToPath(import.meta.url)), '../../../../../../../');
const SERVICE_CLASS = '\\Modules\\Sirsoft\\Ecommerce\\Services\\ExportService';
const ORDER_LIST_URL = '/admin/ecommerce/orders';
const EXPORT_INDEX_URL = '/admin/ecommerce/exports';
const PRODUCT_LIST_URL = '/admin/ecommerce/products';
const MILEAGE_LIST_URL = '/admin/ecommerce/mileage-transactions';
const EXPORT_API = '/api/modules/sirsoft-ecommerce/admin/exports';

/**
 * 최근 1년을 조회 기간으로 넣은 주문 목록 주소 — 예상 행 수가 0이면 창이 요청을 막으므로, 화면으로 요청하는 사양은
 * 시드 주문이 들어 있는 기간으로 연다.
 *
 * @returns 주소
 */
function orderListLastYear(): string {
  const day = (d: Date) => d.toISOString().slice(0, 10);
  const today = new Date();
  return `${ORDER_LIST_URL}?date_type=ordered_at&start_date=${day(new Date(today.getTime() - 364 * 86_400_000))}&end_date=${day(today)}`;
}

/**
 * [내보내기] 버튼이 목록 위 「정렬 · 쪽당 개수」 줄의 오른쪽 끝에 있는지 본다 — 같은 줄의 선택 상자들보다 오른쪽이어야 한다.
 * 선택 상자는 화면에 따라 기본 select 또는 목록 단추(aria-haspopup="listbox")로 그려진다.
 *
 * @param page 화면
 * @param button [내보내기] 버튼
 */
async function expectAtRightOfSortRow(page: Page, button: Locator): Promise<void> {
  const box = await button.boundingBox();
  expect(box, '[내보내기] 버튼이 그려지지 않았다').not.toBeNull();
  const centerY = box!.y + box!.height / 2;
  const sameRow: { box: NonNullable<Awaited<ReturnType<Locator['boundingBox']>>>; text: string }[] = [];
  for (const select of await page.locator('select:visible, [aria-haspopup="listbox"]:visible').all()) {
    const b = await select.boundingBox();
    if (b !== null && Math.abs(b.y + b.height / 2 - centerY) < 20) {
      const text = await select.evaluate((el) => (el instanceof HTMLSelectElement ? el.value : el.textContent ?? ''));
      sameRow.push({ box: b, text });
    }
  }
  // 쪽당 개수(기본 20) 선택 상자가 같은 줄에 있어야 한다 — 일괄 처리 막대 같은 다른 줄의 선택 상자로는 통과하지 않는다
  expect(sameRow.some((s) => /\b20\b/.test(s.text)), '같은 줄에 쪽당 개수 선택 상자가 없다 — 버튼이 다른 줄에 있다').toBe(true);
  for (const { box: b } of sameRow) {
    expect(box!.x, '[내보내기] 버튼이 정렬·쪽당 개수보다 왼쪽에 있다').toBeGreaterThanOrEqual(b.x + b.width);
  }
}

/**
 * 새로고침 없이 화면을 옮긴다 — 운영자가 메뉴·뒤로 가기로 움직일 때처럼 전역 상태가 그대로 남는다.
 *
 * @param page 화면
 * @param url 옮겨 갈 주소
 */
async function spaNavigate(page: Page, url: string): Promise<void> {
  await page.evaluate((target) => {
    window.history.pushState({}, '', target);
    window.dispatchEvent(new PopStateEvent('popstate', { state: {} }));
  }, url);
}

/**
 * [내보내기]를 눌러 창이 보내는 예상 건수 요청의 목록 조건(filters)을 돌려준다.
 *
 * 그 목록 유형의 요청만 받는다 — 앞 화면 창이 늦게 보낸 요청이나, 화면이 바뀌기 전 버튼을 누른 요청을 섞어 읽지 않는다.
 *
 * @param page 화면
 * @param type 이 목록의 내보내기 유형(orders·products·mileage)
 * @returns 예상 건수 요청 본문의 filters
 */
async function openExportAndReadFilters(page: Page, type: string): Promise<Record<string, unknown>> {
  const estimate = page.waitForRequest(
    (req) => req.url().endsWith(`${EXPORT_API}/estimate`) && req.method() === 'POST' && req.postDataJSON()?.type === type,
    { timeout: 30_000 },
  );
  const button = page.getByRole('button', { name: '내보내기', exact: true }).first();
  await expect(button).toBeVisible({ timeout: 20_000 });
  await button.click();
  const filters = ((await estimate).postDataJSON()?.filters ?? {}) as Record<string, unknown>;
  const dialog = page.getByRole('dialog');
  await dialog.getByRole('button', { name: '닫기', exact: true }).click();
  await expect(dialog).toHaveCount(0, { timeout: 10_000 });

  return filters;
}

test.describe.configure({ mode: 'serial' });

// 이 사양이 발급한 시험 계정이 만든 내보내기 작업·파일을 지운다 — `playwright:cleanup` 은 계정만 회수한다
test.afterAll(() => {
  purgeTrackedExports();
});


test.describe('내보내기 요청·관리', () => {
  // @scenario type=orders, scope=filter, permission=personal_data
  // @effects order_list_export_request_accepted, export_manage_lists_requested_job
  test('주문 목록에서 내보내기를 요청하면 202 로 접수되고 관리 화면에 나타난다', async ({ page }) => {
    test.setTimeout(120_000);
    const token = issueToken(
      'sirsoft-ecommerce.orders.read',
      'sirsoft-ecommerce.exports.create',
      'sirsoft-ecommerce.exports.read',
      'sirsoft-ecommerce.exports.download',
      'sirsoft-ecommerce.exports.delete',
    );
    await authenticatePage(page, token);
    // 주문은 기간이 필수다 — 목록의 조회 기간(최근 1년)을 그대로 쓴다
    await page.goto(orderListLastYear());

    const exportButton = page.getByRole('button', { name: '내보내기', exact: true }).first();
    await expect(exportButton).toBeVisible({ timeout: 20_000 });
    await expectAtRightOfSortRow(page, exportButton);
    await exportButton.click();

    // 카탈로그가 도착해 형식 선택이 그려지고 예상 행 수가 나올 때까지 기다린다
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('파일 형식')).toBeVisible({ timeout: 20_000 });
    await expect(dialog.getByText(/예상 [1-9][\d,]*행/)).toBeVisible({ timeout: 20_000 });

    // 목록의 기본 화면 컬럼에 주문자(개인정보)가 있어 사유 칸이 열린다 — 비우면 서버가 422 로 거절한다
    const reasonInput = dialog.getByRole('textbox', { name: '다운로드 사유' });
    await expect(reasonInput).toBeVisible();
    await reasonInput.fill('E2E 내보내기 요청 확인');

    const requestPromise = page.waitForRequest((req) => req.url().endsWith(EXPORT_API) && req.method() === 'POST');
    const responsePromise = page.waitForResponse((res) => res.url().endsWith(EXPORT_API) && res.request().method() === 'POST');
    await dialog.getByRole('button', { name: '내보내기', exact: true }).click();

    const request = await requestPromise;
    expect(request.headers()['idempotency-key'], '중복 방지 키 헤더가 없다').toBeTruthy();
    const response = await responsePromise;
    expect(response.status(), await response.text()).toBe(202);
    const exportId = (await response.json())?.data?.id;
    expect(exportId).toBeTruthy();

    await page.goto(EXPORT_INDEX_URL);
    await expect(page.getByText('내보내기 관리').first()).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText('주문내역').first()).toBeVisible({ timeout: 20_000 });
  });

  // @scenario type=orders, scope=all
  // @effects order_list_export_request_accepted, export_period_capped_at_one_year
  test('「조건 없이 전체」도 목록의 조회 기간으로 요청된다', async ({ page }) => {
    test.setTimeout(120_000);
    const token = issueToken(
      'sirsoft-ecommerce.orders.read',
      'sirsoft-ecommerce.exports.create',
      'sirsoft-ecommerce.exports.read',
      'sirsoft-ecommerce.exports.personal_data',
    );
    await authenticatePage(page, token);
    const day = (d: Date) => d.toISOString().slice(0, 10);
    const today = new Date();
    await page.goto(`${ORDER_LIST_URL}?date_type=ordered_at&start_date=${day(new Date(today.getTime() - 364 * 86_400_000))}&end_date=${day(today)}&search_field=all&search_keyword=e2e-no-such-order`);

    await page.getByRole('button', { name: '내보내기', exact: true }).first().click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('파일 형식')).toBeVisible({ timeout: 20_000 });
    await dialog.getByText('조건 없이 전체').click();
    await expect(dialog.getByText('목록의 조회 기간')).toBeVisible();
    await expect(dialog.getByText(/예상 [1-9][\d,]*행/)).toBeVisible({ timeout: 20_000 });
    const reasonInput = dialog.getByRole('textbox', { name: '다운로드 사유' });
    if (await reasonInput.isVisible()) {
      await reasonInput.fill('E2E 전체 범위 확인');
    }

    const requestPromise = page.waitForRequest((req) => req.url().endsWith(EXPORT_API) && req.method() === 'POST');
    const responsePromise = page.waitForResponse((res) => res.url().endsWith(EXPORT_API) && res.request().method() === 'POST');
    await dialog.getByRole('button', { name: '내보내기', exact: true }).click();

    const body = (await requestPromise).postDataJSON();
    expect(body.scope).toBe('all');
    expect(body.filters?.start_date, '「전체」에서 목록의 조회 기간이 빠졌다').toBeTruthy();
    const response = await responsePromise;
    expect(response.status(), await response.text()).toBe(202);
    // 서버가 기간 밖의 조건(검색어)을 버리는 것은 ExportEdgeCaseTest 가 저장값으로 확인한다
  });

  // @scenario type=orders, scope=filter
  // @effects missing_list_period_defaults_to_recent_month
  test('조회 기간 없이 주문 목록에서 열면 최근 1개월이 채워지고 기간 오류 없이 예상 행 수가 나온다', async ({ page }) => {
    test.setTimeout(120_000);
    await authenticatePage(page, issueToken(
      'sirsoft-ecommerce.orders.read',
      'sirsoft-ecommerce.exports.create',
      'sirsoft-ecommerce.exports.read',
    ));
    await page.goto(ORDER_LIST_URL);

    await page.getByRole('button', { name: '내보내기', exact: true }).first().click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('파일 형식')).toBeVisible({ timeout: 20_000 });

    // 기간이 필수인데 목록 기간이 없다는 오류 대신, 직접 지정에 최근 1개월이 채워지고 안내가 보인다
    await expect(dialog.getByText(/최근 1개월로 채웠습니다/)).toBeVisible({ timeout: 10_000 });
    await expect(dialog.getByText('기간을 선택하세요.')).toHaveCount(0);
    const dateInputs = dialog.locator('input[type="date"]');
    const start = await dateInputs.nth(0).inputValue();
    const end = await dateInputs.nth(1).inputValue();
    expect(start, '시작일이 채워지지 않았다').not.toBe('');
    expect(end, '종료일이 채워지지 않았다').not.toBe('');
    const days = (Date.parse(end) - Date.parse(start)) / 86_400_000;
    expect(days, '최근 1개월이 아니다').toBeGreaterThanOrEqual(28);
    expect(days, '최근 1개월이 아니다').toBeLessThanOrEqual(31);

    // 채운 기간으로 예상 행 수가 계산된다(기간 오류 없음). 그 기간에 행이 없으면 0행이라 요청은 막힌다 — 그 동작은
    // admin-export-modal-usability 사양이 본다
    await expect(dialog.getByText(/예상 [\d,]+행/)).toBeVisible({ timeout: 20_000 });
    await expect(dialog.getByText('기간을 선택하세요.')).toHaveCount(0);
  });

  // @scenario type=orders, action=download
  // @effects request_progress_shown_on_list
  test('요청한 뒤 목록 버튼 옆에 진행 상황이 보이고, 준비되면 그 자리에서 받는다', async ({ page }) => {
    test.setTimeout(240_000);
    await authenticatePage(page, issueToken(
      'sirsoft-ecommerce.orders.read',
      'sirsoft-ecommerce.exports.create',
      'sirsoft-ecommerce.exports.read',
      'sirsoft-ecommerce.exports.download',
      'sirsoft-ecommerce.exports.personal_data',
    ));
    await page.goto(orderListLastYear());

    await page.getByRole('button', { name: '내보내기', exact: true }).first().click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('파일 형식')).toBeVisible({ timeout: 20_000 });
    await expect(dialog.getByText(/예상 [1-9][\d,]*행/)).toBeVisible({ timeout: 20_000 });
    const reasonInput = dialog.getByRole('textbox', { name: '다운로드 사유' });
    if (await reasonInput.isVisible()) {
      await reasonInput.fill('E2E 진행 표시 확인');
    }
    // 작업이 끝나면 새로고침 없이 종 모양 알림 개수를 다시 부른다(실시간 전송이 꺼진 사이트도 알림이 보이게)
    const bellRefetch = page.waitForResponse(
      (res) => res.url().includes('/api/admin/notifications/unread-count') && res.request().method() === 'GET',
      { timeout: 150_000 },
    );
    await dialog.getByRole('button', { name: '내보내기', exact: true }).click();

    // 토스트가 완료 알림을 알려 주고, 창이 닫힌 뒤 목록 버튼 옆에 진행 표시가 생긴다
    await expect(page.getByText(/알림\(종 모양\)으로 알려 드리며/)).toBeVisible({ timeout: 10_000 });
    const status = page.getByRole('status').filter({ hasText: /파일을 만드는 중|파일 준비됨|파일을 만들지 못했습니다/ });
    await expect(status).toBeVisible({ timeout: 10_000 });

    // 파일이 준비되면 「받기」가 보이고 누르면 일회용 주소로 받는다. 파일은 큐 워커가 만들므로 워커가 1분 간격(cron)이면
    // 1분 넘게 걸린다 — 진행 표시가 5초마다 상태를 다시 읽는다(sync 큐면 즉시)
    const downloadButton = status.getByRole('button', { name: '받기', exact: true });
    await expect(downloadButton).toBeVisible({ timeout: 150_000 });
    expect((await bellRefetch).status()).toBe(200);
    const download = page.waitForEvent('download');
    await downloadButton.click();
    const file = await download;
    expect(file.url()).toMatch(/\/api\/modules\/sirsoft-ecommerce\/exports\/file\/[A-Za-z0-9]{64}$/);

    // 닫기를 누르면 표시가 사라진다
    await status.getByRole('button', { name: '진행 표시 닫기' }).click();
    await expect(status).toHaveCount(0);

    // 완료 알림(종 모양)을 누르면 내보내기 관리로 간다 — 전체 주소였을 때는 첫 화면으로 떨어졌다
    await page.reload();
    await page.locator('button[aria-label="알림"]:visible').first().click();
    await page.locator('[data-notification-id]:visible').filter({ hasText: '내보내기 파일이 준비되었습니다' }).first().click();
    await expect(page).toHaveURL(/\/admin\/ecommerce\/exports$/, { timeout: 10_000 });
  });

  // @scenario type=products
  // @effects export_button_shown_on_every_list
  test('상품 목록과 마일리지 내역에도 [내보내기]가 보이고 그 유형의 창이 열린다', async ({ page }) => {
    test.setTimeout(120_000);
    const token = issueScopedToken(
      'sirsoft-ecommerce.products.read',
      'sirsoft-ecommerce.mileage.read',
      'sirsoft-ecommerce.exports.create',
    );
    await authenticatePage(page, token);

    for (const [url, title] of [[PRODUCT_LIST_URL, '상품내역 내보내기'], [MILEAGE_LIST_URL, '마일리지 내역 내보내기']] as const) {
      await page.goto(url);
      const button = page.getByRole('button', { name: '내보내기', exact: true }).first();
      await expect(button, url).toBeVisible({ timeout: 20_000 });
      await expectAtRightOfSortRow(page, button);
      await button.click();
      const dialog = page.getByRole('dialog', { name: title });
      await expect(dialog).toBeVisible({ timeout: 20_000 });
      // 카탈로그를 받아야 형식 선택이 그려진다 — 창을 연 뒤 다시 받는 데이터 소스가 동작하는지 본다
      await expect(dialog.getByText('파일 형식')).toBeVisible({ timeout: 20_000 });
      await dialog.getByRole('button', { name: '닫기', exact: true }).click();
    }
  });

  // @scenario type=orders, action=download
  // @effects download_streams_via_one_time_link, download_records_history
  test('파일명을 누르면 일회용 주소로 파일을 받고 다운로드 횟수가 1회로 바뀐다', async ({ page }) => {
    test.setTimeout(120_000);
    const token = issueToken(
      'sirsoft-ecommerce.orders.read',
      'sirsoft-ecommerce.exports.create',
      'sirsoft-ecommerce.exports.read',
      'sirsoft-ecommerce.exports.download',
    );

    // 요청은 API 로, 생성은 큐 작업 대신 서비스로 바로 만든다 — 이 사양이 보는 것은 관리 화면의 다운로드다
    const today = new Date();
    const day = (d: Date) => d.toISOString().slice(0, 10);
    const created = await page.request.post(EXPORT_API, {
      headers: { Authorization: `Bearer ${token}`, 'Idempotency-Key': `e2e-download-${Date.now()}`, Accept: 'application/json' },
      data: {
        type: 'orders', format: 'csv', column_mode: 'custom', fields: ['order.order_number'], row_unit: 'order', scope: 'all',
        period: { mode: 'custom', date_type: 'ordered_at', start_date: day(new Date(today.getTime() - 30 * 86_400_000)), end_date: day(today) },
        locale_mode: 'locale', include_totals: false,
      },
    });
    expect(created.status(), await created.text()).toBe(202);
    const exportId = Number((await created.json())?.data?.id);
    execFileSync('php', ['artisan', 'tinker', `--execute=app(${SERVICE_CLASS}::class)->generate(${exportId});`], { cwd: CORE_ROOT, stdio: 'pipe' });

    const shown = await page.request.get(`${EXPORT_API}/${exportId}`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
    const fileName = String((await shown.json())?.data?.file_name ?? '');
    expect(fileName, '파일이 만들어지지 않았다').not.toBe('');

    await authenticatePage(page, token);
    await page.goto(EXPORT_INDEX_URL);
    const fileButton = page.getByRole('button', { name: fileName, exact: true }).first();
    await expect(fileButton).toBeVisible({ timeout: 20_000 });

    const linkResponse = page.waitForResponse((res) => res.url().endsWith(`${EXPORT_API}/${exportId}/download-link`));
    const download = page.waitForEvent('download');
    await fileButton.click();

    expect((await linkResponse).status()).toBe(200);
    const file = await download;
    expect(file.url(), '일회용 주소가 아닌 곳에서 받았다').toMatch(/\/api\/modules\/sirsoft-ecommerce\/exports\/file\/[A-Za-z0-9]{64}$/);
    expect(file.suggestedFilename()).toBe(fileName);
    const content = readFileSync(await file.path() as string);
    expect(content.subarray(0, 3).toString('hex'), 'CSV 가 BOM 으로 시작하지 않는다').toBe('efbbbf');

    // 핸들러가 기록을 기다린 뒤 목록을 다시 불러 같은 행의 횟수가 1회가 된다
    const row = page.getByRole('row').filter({ has: page.getByRole('button', { name: fileName, exact: true }) }).first();
    await expect(row.getByRole('button', { name: '1회', exact: true })).toBeVisible({ timeout: 10_000 });
  });

  // @scenario action=retry, permission=personal_data
  // @effects retry_of_others_personal_job_needs_own_reason
  test('다른 운영자의 개인정보 작업을 다시 요청하면 사유 칸이 열리고 그 사유가 실린다', async ({ page }) => {
    test.setTimeout(240_000);
    const perms = [
      'sirsoft-ecommerce.orders.read',
      'sirsoft-ecommerce.exports.create',
      'sirsoft-ecommerce.exports.read',
      'sirsoft-ecommerce.exports.personal_data',
    ];
    const ownerToken = issueToken(...perms);
    const day = (d: Date) => d.toISOString().slice(0, 10);
    const created = await page.request.post(EXPORT_API, {
      headers: { Authorization: `Bearer ${ownerToken}`, 'Idempotency-Key': `e2e-retry-${Date.now()}`, Accept: 'application/json' },
      data: {
        type: 'orders', format: 'csv', column_mode: 'custom', fields: ['order.order_number', 'order.ip_address'], row_unit: 'order', scope: 'all',
        // 다시 요청은 지금 내보낼 행이 있어야 받는다 — 시드 주문이 들어 있는 최근 1년으로 둔다
        period: { mode: 'custom', date_type: 'ordered_at', start_date: day(new Date(Date.now() - 364 * 86_400_000)), end_date: day(new Date()) },
        locale_mode: 'locale', include_totals: false, reason: '원래 요청자의 사유',
      },
    });
    expect(created.status(), await created.text()).toBe(202);
    const exportId = Number((await created.json())?.data?.id);
    // 큐가 sync 면 응답 뒤에 파일을 만든다 — 생성이 끝날 때까지 기다린다(실패로 바꾸는 것이 생성과 겹치지 않게)
    let fileName = '';
    await expect.poll(async () => {
      const data = (await (await page.request.get(`${EXPORT_API}/${exportId}`, { headers: { Authorization: `Bearer ${ownerToken}`, Accept: 'application/json' } })).json())?.data;
      fileName = String(data?.file_name ?? '');
      return data?.status;
    }, { timeout: 150_000, intervals: [2_000] }).toMatch(/completed|failed/);
    // 실패한 작업으로 만든다 — 큐가 sync 면 이미 완료됐으므로 파일을 지우고 상태를 바꾼다(이 사양이 보는 것은 다시 요청 창이다)
    execFileSync('php', ['artisan', 'tinker', `--execute=$e=\\Modules\\Sirsoft\\Ecommerce\\Models\\Export::find(${exportId}); if ($e->file_path) { app(\\App\\Extension\\ModuleManager::class)->getModule('sirsoft-ecommerce')->getStorage()->delete(${SERVICE_CLASS}::STORAGE_CATEGORY, $e->file_path); } $e->forceFill(['status' => 'failed', 'error_code' => 'timeout', 'file_path' => null])->save();`], { cwd: CORE_ROOT, stdio: 'pipe' });

    await authenticatePage(page, issueToken(...perms));
    await page.goto(EXPORT_INDEX_URL);
    const row = page.getByRole('row').filter({ hasText: fileName }).first();
    await expect(row).toBeVisible({ timeout: 20_000 });
    await chooseRowMenuItem(page, row, '다시 요청');

    const dialog = page.getByRole('dialog', { name: '다시 요청' });
    const reason = dialog.getByRole('textbox');
    await expect(reason).toBeVisible({ timeout: 10_000 });
    await reason.fill('다시 요청한 사람의 사유');

    const requestPromise = page.waitForRequest((req) => req.url().endsWith(`${EXPORT_API}/${exportId}/retry`));
    const responsePromise = page.waitForResponse((res) => res.url().endsWith(`${EXPORT_API}/${exportId}/retry`));
    await dialog.getByRole('button', { name: '확인' }).click();
    expect((await requestPromise).postDataJSON()?.reason).toBe('다시 요청한 사람의 사유');
    const response = await responsePromise;
    expect(response.status(), await response.text()).toBe(202);
    expect((await response.json())?.data?.reason).toBe('다시 요청한 사람의 사유');
  });

  // @scenario type=orders, permission=no_domain_read
  // @effects export_button_hidden_without_permission
  test('주문 조회만 있는 운영자에게는 [내보내기] 버튼이 없다', async ({ page }) => {
    // 권한 경계를 보는 토큰이라 사이트 전체 역할이 끼지 않는 범위 토큰으로 발급한다
    await authenticatePage(page, issueScopedToken('sirsoft-ecommerce.orders.read'));
    await page.goto(ORDER_LIST_URL);

    await expect(page.getByRole('heading').first()).toBeVisible({ timeout: 20_000 });
    await expect(page.getByRole('button', { name: '내보내기', exact: true })).toHaveCount(0);
  });

  // @scenario action=requester_menu
  // @effects requester_menu_opens_member_and_filters_list, requester_member_item_is_new_window_link
  test('요청자 이름을 누르면 메뉴가 열리고 회원 정보는 새 창으로, 요청자 거르기는 이 목록에서 동작한다', async ({ page }) => {
    test.setTimeout(120_000);
    const token = issueToken(
      'sirsoft-ecommerce.orders.read',
      'sirsoft-ecommerce.exports.create',
      'sirsoft-ecommerce.exports.read',
    );

    // 이 토큰 주인의 작업 하나 — 생성은 하지 않는다(요청자 칸은 대기 중에도 그려진다)
    const today = new Date();
    const day = (d: Date) => d.toISOString().slice(0, 10);
    const created = await page.request.post(EXPORT_API, {
      headers: { Authorization: `Bearer ${token}`, 'Idempotency-Key': `e2e-requester-${Date.now()}`, Accept: 'application/json' },
      data: {
        type: 'orders', format: 'csv', column_mode: 'custom', fields: ['order.order_number'], row_unit: 'order', scope: 'all',
        period: { mode: 'custom', date_type: 'ordered_at', start_date: day(new Date(today.getTime() - 7 * 86_400_000)), end_date: day(today) },
        locale_mode: 'locale', include_totals: false,
      },
    });
    expect(created.status(), await created.text()).toBe(202);
    const exportId = Number((await created.json())?.data?.id);
    const shown = await page.request.get(`${EXPORT_API}/${exportId}`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
    const requester = (await shown.json())?.data?.requester ?? {};
    expect(requester.uuid, '요청자가 회원이 아니다').toBeTruthy();
    const name = String(requester.name ?? '');

    await authenticatePage(page, token);
    await page.goto(EXPORT_INDEX_URL);
    // 머리 줄의 로그인 사용자 이름과 섞이지 않게 표의 행 안에서 찾는다
    const nameCell = page.getByRole('row').filter({ hasText: name }).first().getByText(name, { exact: true }).first();
    await expect(nameCell).toBeVisible({ timeout: 20_000 });

    // 회원 정보 보기 — 목록 조건을 잃지 않게 새 창으로 연다. 실제 주소를 가진 링크라 가운데 클릭·Ctrl+클릭으로도 열린다
    await nameCell.click();
    const memberItem = page.getByRole('menuitem', { name: '회원 정보 보기' });
    await expect(memberItem).toHaveAttribute('href', new RegExp(`/admin/users/${requester.uuid}$`));
    await expect(memberItem).toHaveAttribute('target', '_blank');
    const popupPromise = page.waitForEvent('popup');
    await memberItem.click();
    const popup = await popupPromise;
    await expect(popup).toHaveURL(new RegExp(`/admin/users/${requester.uuid}$`), { timeout: 10_000 });
    await popup.close();

    // 이 요청자의 내보내기만 보기 — 같은 화면에서 검색 조건만 바뀌고 필터 칸에도 그대로 담긴다.
    // 실제 거르기는 회원 고유ID 로 한다(이름 포함 검색이면 이름이 겹치는 다른 요청자의 작업이 섞인다)
    await nameCell.click();
    const filtered = page.waitForResponse((res) => res.url().includes(EXPORT_API) && res.url().includes('requester_uuid='));
    await page.getByText('이 요청자의 내보내기만 보기', { exact: true }).click();
    await expect(page).toHaveURL(/search_field=requester/, { timeout: 10_000 });
    expect(decodeURIComponent(new URL(page.url()).searchParams.get('search_keyword') ?? '')).toBe(name);
    expect(new URL(page.url()).searchParams.get('requester_uuid')).toBe(requester.uuid);
    await expect(page.locator('input[name="search"]:visible').first()).toHaveValue(name);
    const listed = await (await filtered).json();
    const rows: Array<{ requester?: { uuid?: string } }> = listed?.data?.data ?? [];
    expect(rows.length).toBeGreaterThan(0);
    expect(rows.every((row) => row.requester?.uuid === requester.uuid), '다른 요청자의 작업이 섞였다').toBe(true);
  });

  // @scenario type=orders, scope=filter
  // @effects export_context_replaced_on_every_open
  test('검색을 지우거나 다른 목록으로 옮겨 다시 열면 전에 담긴 검색 조건이 남지 않는다', async ({ page }) => {
    test.setTimeout(150_000);
    await authenticatePage(page, issueToken('sirsoft-ecommerce.orders.read', 'sirsoft-ecommerce.products.read', 'sirsoft-ecommerce.exports.create'));

    // ① 검색한 주문 목록 — 창이 그 검색어를 조건으로 싣는다
    await page.goto(`${orderListLastYear()}&search_field=order_number&search_keyword=E2E-STALE-KEYWORD`);
    const searched = await openExportAndReadFilters(page, 'orders');
    expect(searched.search_keyword).toBe('E2E-STALE-KEYWORD');

    // ② 같은 목록에서 검색을 지우고 다시 연다 — 지운 검색어가 남으면 화면은 전체인데 파일만 걸러진다
    await spaNavigate(page, orderListLastYear());
    await expect(page).not.toHaveURL(/search_keyword/);
    const cleared = await openExportAndReadFilters(page, 'orders');
    expect(cleared, '지운 검색 조건이 남았다').not.toHaveProperty('search_keyword');
    expect(cleared).not.toHaveProperty('search_field');

    // ③ 상품 목록으로 옮겨 연다 — 주문 목록의 조건(날짜 종류 등)이 섞이면 상품 검증이 422 를 낸다
    await page.goto(`${orderListLastYear()}&search_field=order_number&search_keyword=E2E-STALE-KEYWORD`);
    await openExportAndReadFilters(page, 'orders');
    await spaNavigate(page, PRODUCT_LIST_URL);
    await expect(page.getByRole('heading', { name: '상품 관리', level: 1 })).toBeVisible({ timeout: 20_000 });
    const product = await openExportAndReadFilters(page, 'products');
    for (const key of ['search_keyword', 'search_field', 'date_type', 'start_date', 'end_date']) {
      expect(product, `주문 목록의 ${key} 가 상품 조건에 섞였다`).not.toHaveProperty(key);
    }
  });
});
