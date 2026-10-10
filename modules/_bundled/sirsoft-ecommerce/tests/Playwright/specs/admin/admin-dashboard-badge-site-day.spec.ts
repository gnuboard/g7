/**
 * 대시보드 「오늘」 배지로 연 주문 목록 = 배지와 같은 사이트 하루 (7.1.0 전수 실측 · 결정 2026-10-08)
 *
 * 배지 숫자는 사이트 표준시의 하루에 든 주문 수다(배지 링크와 같은 목록 조건으로 주문 표에서 바로 센다 — 결정
 * 2026-10-08 4차, 옵션 수량이 아니라 주문 수). 목록의 날짜 필터는 보는 사람의 하루가 원칙이라,
 * 관리자 계정 시간대가 사이트와 다르면 「결제완료 1건」 배지를 눌러 연 목록이 0건이 됐다. 이제 배지 링크가
 * `date_basis=site` 를 싣고, 목록은 그 인자가 있는 동안만 사이트 하루로 거르며 안내 문구를 보인다.
 * 날짜를 바꿔 검색하면 인자를 떼고 보는 사람의 하루로 돌아간다.
 *
 * 브라우저로만 드러나는 축:
 *  ① 배지 → 목록 이동이 실제로 `date_basis=site` 를 주소와 목록 API 에 싣고, 목록 건수가 배지 숫자와 같은가
 *     (계정 시간대 UTC · 사이트 하루 경계 안쪽/바깥쪽 주문으로 — 바깥쪽 주문은 목록에 없어야 한다. 안쪽에 수량 2 주문을
 *     섞어 배지가 수량을 세면 배지가 목록보다 커져 갈린다).
 *  ② 날짜와 무관하게 [검색]만 다시 누르면 기준이 유지되고, [오늘]로 날짜를 바꿔 검색하면 기준이 빠지고 안내가 사라지는가.
 *  ③ 영어 화면에서도 같은 흐름 — 시작일을 바꿔 검색하면 보는 사람의 하루(두 주문 모두 포함)로 돌아가는가.
 *
 * 데이터: 주문 시각을 과거(사이트 0시 경계)로 만들 수 있는 화면·API 가 없어 모듈 시드 커맨드
 * `playwright:seed-site-day-orders` 가 경계 안쪽 결제완료 주문 2건(기존 상품 옵션, 수량 1·수량 2)과 바깥쪽 1건(수량 1)을
 * 만들고 오늘·어제 집계 행을 다시 굽는다(그래프용 — 배지는 주문 표를 바로 센다). afterAll 에서 `--teardown` 으로 지우고 집계를 되굽는다.
 * 관리자 계정 시간대는 그 계정 본인의 프로필 API(`PUT /api/me`)로 UTC 로 바꾼다.
 *
 * 소유 시나리오: `admin-order-list-site-day-basis`, `admin-dashboard-badge-order-count` (축 매핑은 각 테스트에 있다)
 * @effects dashboard_badge_links_carry_site_basis, order_list_forwards_date_basis_to_api,
 *          order_list_site_basis_filters_by_site_day, order_list_shows_site_basis_notice,
 *          order_list_non_date_search_keeps_site_basis, order_list_date_change_drops_site_basis,
 *          dashboard_badge_counts_orders_not_option_quantities, dashboard_badge_equals_linked_order_list_total
 */
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { request as playwrightRequest, type Page, type Response } from '@playwright/test';
import { issueToken } from '../../../../../../../tests/Playwright/fixtures/auth';
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const DASHBOARD_URL = '/admin/dashboard';
const ORDERS_API_PATH = '/api/modules/sirsoft-ecommerce/admin/orders';
const OVERVIEW_API_PATH = '/api/modules/sirsoft-ecommerce/admin/dashboard/overview';
const BADGE = '#today_payment_complete_badge';
const NOTICE = '#date_site_basis_notice';

/** 관리자 계정에 둘 시간대 — 사이트(Asia/Seoul 등)와 달라야 배지 1건 → 목록 0건 회귀가 재현된다. */
const VIEWER_TIMEZONE = 'UTC';

/** 코어 루트 — 시드 커맨드(artisan)는 코어에 있다. */
const CORE_ROOT = process.env.G7_ROOT || resolve(dirname(fileURLToPath(import.meta.url)), '../../../../../../../');

type SeedResult = {
  site_timezone: string;
  site_today: string;
  inside: string;
  inside_multi: string;
  outside: string;
};

/**
 * 시드 커맨드를 실행하고 마지막 JSON 줄을 돌려준다(3중 가드 — CLI · G7_PLAYWRIGHT_BYPASS · APP_DEBUG).
 *
 * @param args artisan 인자
 * @returns 출력의 마지막 JSON 객체
 */
function seedSiteDayOrders(args: string): Record<string, unknown> {
  const stdout = execSync(`php artisan playwright:seed-site-day-orders ${args} --json`, {
    cwd: CORE_ROOT,
    encoding: 'utf-8',
    env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' },
  });
  const lines = stdout.split(/\r?\n/).filter((line) => line.trim().startsWith('{'));

  return JSON.parse(lines[lines.length - 1]);
}

/**
 * 날짜 문자열(Y-m-d)에서 하루를 뺀다.
 *
 * @param date Y-m-d
 * @returns 하루 전 Y-m-d
 */
function previousDay(date: string): string {
  const d = new Date(`${date}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() - 1);

  return d.toISOString().slice(0, 10);
}

/**
 * 주문 목록 API 의 GET 응답을 기다리는 조건.
 *
 * @param predicate 요청 주소 추가 조건
 * @returns waitForResponse 조건 함수
 */
function ordersListResponse(predicate: (url: URL) => boolean): (r: Response) => boolean {
  return (r) => {
    if (r.request().method() !== 'GET') return false;
    const url = new URL(r.url());

    return url.pathname === ORDERS_API_PATH && predicate(url);
  };
}

/**
 * 대시보드에서 결제완료 배지 숫자를 읽고 그 배지를 눌러 주문 목록으로 간다.
 *
 * @param page 페이지
 * @param badgeLabel 배지 문구 (숫자 앞부분, 로케일별)
 * @returns 배지 숫자와 목록 API 응답 본문
 */
async function openListFromBadge(page: Page, badgeLabel: RegExp): Promise<{ badgeCount: number; listBody: any; listUrl: URL }> {
  const overviewResponse = page.waitForResponse((r) => new URL(r.url()).pathname === OVERVIEW_API_PATH, { timeout: 60_000 });
  await page.goto(DASHBOARD_URL);
  const overview = await (await overviewResponse).json();
  const badgeCount = Number(overview?.data?.payment_complete);

  const badge = page.locator(BADGE);
  await expect(badge).toBeVisible({ timeout: 30_000 });
  await expect(badge).toHaveText(badgeLabel, { timeout: 30_000 });
  await expect(badge).toContainText(String(badgeCount));

  const listResponse = page.waitForResponse(
    ordersListResponse((url) => url.searchParams.get('date_basis') === 'site'),
    { timeout: 60_000 },
  );
  await badge.click();
  await page.waitForURL(/\/admin\/ecommerce\/orders\?/, { timeout: 30_000 });
  const response = await listResponse;
  expect(response.status(), '사이트 하루 기준 목록 요청이 성공해야 한다').toBe(200);

  return { badgeCount, listBody: await response.json(), listUrl: new URL(response.url()) };
}

test.describe.configure({ mode: 'serial' });

test.describe('대시보드 배지로 연 주문 목록 — 사이트 하루 기준', () => {
  let seed: SeedResult;
  let token: string;

  test.beforeAll(async ({}, testInfo) => {
    seed = seedSiteDayOrders('') as unknown as SeedResult;
    token = issueToken('sirsoft-ecommerce.orders.read', 'sirsoft-ecommerce.dashboard.view');

    // 관리자 계정 시간대를 사이트와 다르게 둔다 — 본인 프로필 API 로(화면에서 바꾸는 것과 같은 경로).
    const api = await playwrightRequest.newContext({
      baseURL: testInfo.project.use.baseURL,
      ignoreHTTPSErrors: true,
      extraHTTPHeaders: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    try {
      const me = await (await api.get('/api/me')).json();
      const update = await api.put('/api/me', {
        data: { name: me.data.name, email: me.data.email, timezone: VIEWER_TIMEZONE },
      });
      expect(update.status(), `계정 시간대 변경 실패: ${await update.text()}`).toBe(200);
    } finally {
      await api.dispose();
    }

    expect(seed.site_timezone, '사이트 시간대가 계정 시간대와 같으면 이 회귀를 재현할 수 없다').not.toBe(VIEWER_TIMEZONE);
  });

  test.afterAll(() => {
    seedSiteDayOrders('--teardown');
  });

  // @scenario entry=dashboard_badge, basis=site
  // @effects dashboard_badge_links_carry_site_basis, order_list_forwards_date_basis_to_api, order_list_site_basis_filters_by_site_day, order_list_shows_site_basis_notice
  // @scenario metric=order_count, badge=payment_complete
  // @effects dashboard_badge_counts_orders_not_option_quantities, dashboard_badge_equals_linked_order_list_total
  test('결제완료 배지를 누르면 date_basis=site 목록이 열리고 건수가 배지와 같다', async ({ page }) => {
    test.setTimeout(120_000);
    await authenticatePage(page, token);

    const { badgeCount, listBody, listUrl } = await openListFromBadge(page, /^결제완료 \d+건$/);
    expect(badgeCount, '경계 안쪽 시드 주문 2건이 오늘 배지에 잡혀야 한다').toBeGreaterThanOrEqual(2);

    // 주소: 배지가 센 사이트 하루 + 기준 인자
    const q = new URL(page.url()).searchParams;
    expect(q.get('date_basis')).toBe('site');
    expect(q.get('start_date')).toBe(seed.site_today);
    expect(q.get('end_date')).toBe(seed.site_today);
    expect(q.getAll('order_status[]')).toEqual(['payment_complete']);

    // 목록 API 에도 같은 기준이 실린다.
    expect(listUrl.searchParams.get('start_date')).toBe(seed.site_today);

    // 건수 = 배지 — 배지도 목록도 주문 수다. 안쪽 시드에 수량 2 주문이 있어, 배지가 옵션 수량을 세면 목록보다 커진다.
    const total = listBody?.data?.pagination?.total;
    expect(total, '배지로 연 목록 건수가 배지 숫자와 다르다(배지가 주문 수가 아니라 수량을 세면 수량 2 주문 때문에 1 이상 크다)').toBe(badgeCount);
    await expect(page.getByText(`총 ${badgeCount}개`).first()).toBeVisible({ timeout: 30_000 });

    // 경계 안쪽은 있고, 바깥쪽(사이트 어제 23:59)은 없다.
    const numbers = (listBody?.data?.data ?? []).map((row: any) => row.order_number);
    expect(numbers).toContain(seed.inside);
    expect(numbers).toContain(seed.inside_multi);
    expect(numbers).not.toContain(seed.outside);
    await expect(page.getByText(seed.inside).first()).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText(seed.inside_multi).first()).toBeVisible({ timeout: 30_000 });

    // 대조 — 같은 주소에서 기준 인자만 빼면 보는 사람(UTC)의 하루라 경계 안쪽 주문이 빠진다(배지 1건 → 목록 0건 회귀의 모양).
    const withoutBasis = new URL(listUrl.toString());
    withoutBasis.searchParams.delete('date_basis');
    const viewerDay = await page.evaluate(async (path) => {
      const res = await fetch(path, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
      });
      return res.json();
    }, withoutBasis.pathname + withoutBasis.search);
    const viewerNumbers = (viewerDay?.data?.data ?? []).map((row: any) => row.order_number);
    expect(viewerNumbers, '기준 인자 없이도 안쪽 주문이 잡히면 이 spec 이 회귀를 가르지 못한다(계정·사이트 시간대 확인)').not.toContain(seed.inside);

    // 안내 — 사이트 시간대를 밝힌다.
    const notice = page.locator(NOTICE);
    await expect(notice).toBeVisible({ timeout: 30_000 });
    await expect(notice).toContainText(`사이트 표준시(${seed.site_timezone})`);
  });

  // @scenario entry=list_non_date_change, basis=site
  // @effects order_list_non_date_search_keeps_site_basis
  // @scenario entry=list_date_change, basis=viewer
  // @effects order_list_date_change_drops_site_basis
  test('날짜를 그대로 두고 검색하면 기준이 남고, [오늘]로 바꿔 검색하면 기준과 안내가 사라진다', async ({ page }) => {
    test.setTimeout(120_000);
    await authenticatePage(page, token);

    await openListFromBadge(page, /^결제완료 \d+건$/);
    await expect(page.locator(NOTICE)).toBeVisible({ timeout: 30_000 });

    // ① 날짜와 무관한 검색 — 기준 유지. 검색은 쪽수를 1로 되돌려 주소에 page=1 을 남기므로(배지 링크에는 없다)
    //    그것으로 검색이 실제로 돌았음을 확인한다.
    expect(new URL(page.url()).searchParams.has('page')).toBe(false);
    await page.locator('#f_141_search_button').click();
    await page.waitForURL((url) => url.searchParams.get('page') === '1', { timeout: 30_000 });
    expect(new URL(page.url()).searchParams.get('date_basis')).toBe('site');
    await expect(page.locator(NOTICE)).toBeVisible();

    // ② [오늘](보는 사람 UTC 의 오늘) → 검색 — 기준을 떼고 보는 사람 하루로 돌아간다.
    await page.locator('#f_014_quick_today').click();
    const dropResponse = page.waitForResponse(
      ordersListResponse((url) => !url.searchParams.has('date_basis') && url.searchParams.has('start_date')),
      { timeout: 60_000 },
    );
    await page.locator('#f_141_search_button').click();
    const dropped = await dropResponse;
    expect(dropped.status()).toBe(200);

    await expect.poll(() => new URL(page.url()).searchParams.has('date_basis'), { timeout: 30_000 }).toBe(false);
    const q = new URL(page.url()).searchParams;
    const viewerToday = new Date().toISOString().slice(0, 10); // 계정 시간대 UTC 의 오늘
    expect(q.get('start_date')).toBe(viewerToday);
    expect(q.get('end_date')).toBe(viewerToday);
    await expect(page.locator(NOTICE)).toHaveCount(0);
  });

  // @scenario entry=dashboard_badge, basis=site
  // @effects dashboard_badge_links_carry_site_basis, order_list_shows_site_basis_notice
  // @scenario metric=order_count, badge=payment_complete
  // @effects dashboard_badge_equals_linked_order_list_total
  // @scenario entry=list_date_change, basis=viewer
  // @effects order_list_date_change_drops_site_basis
  test('영어 화면 — 배지로 연 목록에 안내가 보이고, 시작일을 바꿔 검색하면 보는 사람 하루로 돌아간다', async ({ page }) => {
    test.setTimeout(120_000);
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'en'));
    await authenticatePage(page, token);

    const { badgeCount, listBody } = await openListFromBadge(page, /^Paid \d+$/);
    expect(listBody?.data?.pagination?.total, 'Paid N = 목록 Total N (주문 수)').toBe(badgeCount);
    const notice = page.locator(NOTICE);
    await expect(notice).toBeVisible({ timeout: 30_000 });
    await expect(notice).toContainText(`site time zone (${seed.site_timezone})`);

    // 시작일을 하루 앞당긴다 — 보는 사람(UTC) 기준 [어제, 오늘(사이트)] 범위는 경계 양쪽 주문을 모두 담는다.
    const yesterday = previousDay(seed.site_today);
    const viewerResponse = page.waitForResponse(
      ordersListResponse((url) => !url.searchParams.has('date_basis') && url.searchParams.get('start_date') === yesterday),
      { timeout: 60_000 },
    );
    await page.locator('#f_012_start_date').fill(yesterday);
    await page.locator('#f_141_search_button').click();
    const viewer = await viewerResponse;
    expect(viewer.status()).toBe(200);

    await expect.poll(() => new URL(page.url()).searchParams.has('date_basis'), { timeout: 30_000 }).toBe(false);
    expect(new URL(page.url()).searchParams.get('start_date')).toBe(yesterday);
    await expect(notice).toHaveCount(0);

    const numbers = ((await viewer.json())?.data?.data ?? []).map((row: any) => row.order_number);
    expect(numbers).toContain(seed.inside);
    expect(numbers).toContain(seed.inside_multi);
    expect(numbers).toContain(seed.outside);
  });
});
