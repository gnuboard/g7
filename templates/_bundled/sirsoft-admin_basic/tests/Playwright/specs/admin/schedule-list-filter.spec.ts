/**
 * sirsoft-admin_basic — 스케줄 목록 필터(쿠폰 목록형) 브라우저 회귀.
 *
 * 결함: 검색어가 서버에 닿지 않았고(`search` 를 서버가 버림), 모듈 필터는 선택지가 「전체」뿐이었으며
 *   서버가 모르는 `module` 키를 보냈고, 초기화는 다른 상태까지 지웠다. 7.2.0 은 쿠폰 목록과 같은 틀로 바꿨다 —
 *   입력은 `_local.filter` 에 쌓이고 **검색 버튼·Enter 에서만** 조회하며, 소유 필터는 owners API 의 확장 이름으로
 *   좁히고, 초기화는 조건 없는 주소로 간다. 확장 설정 탭의 「스케줄 관리에서 보기」 링크가 소유 쿼리를 싣고 오면
 *   화면이 그 값을 필터 입력에 채운다. 모두 브라우저에서만 드러나는 동작이라 실제 요청을 잰다.
 *
 * 조회 전용 — 쓰는 것이 없어 원복할 것이 없다.
 *
 * 시나리오 매니페스트: schedule-admin-screen (tests/scenarios — test_files 귀속)
 * @axes case=screen_filter_submit
 * @effects schedule_screen_filters_submit_only
 */
import { test, expect, type Page, type Request } from '@playwright/test';
import { issueToken, authenticatePage } from '../../fixtures/admin-template-auth';

const LIST = '/api/admin/schedules';

/** 목록 요청(`GET /api/admin/schedules?...`)만 고른다 — owners·status·큐 요청은 뺀다. */
function isListRequest(req: Request): boolean {
  if (req.method() !== 'GET') return false;
  const url = new URL(req.url());
  return url.pathname === LIST;
}

/** 목록 요청을 세기 시작한다. 반환 함수가 지금까지의 요청 URL 목록을 준다. */
function trackListRequests(page: Page): () => URL[] {
  const seen: URL[] = [];
  page.on('request', (req) => {
    if (isListRequest(req)) seen.push(new URL(req.url()));
  });
  return () => seen;
}

test.describe('@sirsoft-admin_basic 스케줄 목록 필터', () => {
  test.beforeEach(async ({ page }) => {
    const token = issueToken('core.schedules.read', 'core.queue_jobs.read');
    await authenticatePage(page, token);
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
  });

  // @scenario case=screen_filter_submit
  // @effects schedule_screen_filters_submit_only
  test('필터를 여러 번 바꿔도 검색 전에는 조회하지 않고, 검색 한 번에 모든 조건이 실린다 (M6)', async ({ page }) => {
    await page.goto('/admin/schedules');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });
    const requests = trackListRequests(page);

    // 기본 필터 둘 + 상세 필터 하나를 바꾼다 — 라디오 클릭만으로는 요청이 나가면 안 된다.
    await page.locator('input[type=radio][name="type"][value="artisan"]').check();
    await page.locator('input[type=radio][name="status"][value="active"]').check();
    await page.locator('#detail_search_toggle').click();
    await page.locator('input[type=radio][name="dispatchMode"][value="sync"]').check();
    await page.waitForTimeout(500);
    console.log(`requests_before_search=${requests().length}`);
    expect(requests()).toHaveLength(0);

    const searched = page.waitForRequest(isListRequest, { timeout: 15_000 });
    await page.locator('#search_button').click();
    const url = new URL((await searched).url());
    console.log(`search_params=${url.searchParams.toString()}`);
    expect(url.searchParams.get('type')).toBe('artisan');
    expect(url.searchParams.get('status')).toBe('active');
    expect(url.searchParams.get('dispatch_mode')).toBe('sync');
    expect(url.searchParams.get('page')).toBe('1');
    // 옛 키는 더 이상 나가지 않는다.
    expect(url.searchParams.has('search')).toBe(false);
    expect(url.searchParams.has('module')).toBe(false);
    await expect(page).toHaveURL(/type=artisan/);
  });

  // @scenario case=keyword_search
  // @effects schedule_screen_filters_submit_only
  test('검색 대상 「명령」 + 키워드는 Enter 에서 search_field·search_keyword 로 나가고 응답 행이 키워드를 담는다 (M4)', async ({ page }) => {
    await page.goto('/admin/schedules');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    await page.locator('#search_field_select').selectOption('command').catch(async () => {
      // 커스텀 Select 면 버튼을 눌러 선택지를 고른다.
      await page.locator('#search_field_select').click();
      await page.getByRole('option', { name: '명령' }).click();
    });
    const input = page.locator('#search_input');
    await input.fill('cache');
    const searched = page.waitForResponse((res) => isListRequest(res.request()), { timeout: 15_000 });
    await input.press('Enter');
    const response = await searched;
    const url = new URL(response.url());
    expect(url.searchParams.get('search_field')).toBe('command');
    expect(url.searchParams.get('search_keyword')).toBe('cache');
    expect(response.status()).toBe(200);
    const body = (await response.json()) as { data: { data: Array<{ command: string }> } };
    const rows = body.data.data;
    console.log(`keyword_rows=${rows.length}`);
    for (const row of rows) expect(row.command.toLowerCase()).toContain('cache');
  });

  // @scenario case=owner_filter
  // @effects schedule_screen_filters_submit_only
  test('소유 「모듈」 → 확장 선택은 owners API 의 확장 이름이고, 검색하면 extension_type·extension_identifier 가 나간다 (M5)', async ({ page }) => {
    await page.goto('/admin/schedules');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });
    const owners = await page.evaluate(async () => {
      const res = await fetch('/api/admin/schedules/owners', {
        headers: { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
      });
      return ((await res.json())?.data ?? []) as Array<{ type: string; identifier: string | null; name: string; schedule_count: number }>;
    });
    const module = owners.find((o) => o.type === 'module');
    console.log(`owners=${owners.map((o) => `${o.type}:${o.identifier}`).join(',')}`);
    expect(module, '모듈 소유 스케줄이 하나도 없다 — 번들 모듈 동기화(module:update)를 먼저 확인').toBeTruthy();

    const dropdown = page.locator('#owner_identifier_dropdown');
    // 소유가 「전체」일 때 확장 선택은 보이지 않는다(비활성으로 남기지 않는다).
    await expect(dropdown).toHaveCount(0);
    await page.locator('input[type=radio][name="ownerType"][value="module"]').check();
    await expect(dropdown.locator('[aria-haspopup="listbox"]')).toBeEnabled();
    await dropdown.locator('[aria-haspopup="listbox"]').click();
    // 선택지 라벨은 식별자가 아니라 확장 이름(현재 언어)이다.
    await page.getByRole('option', { name: new RegExp(module!.name) }).first().click();

    const searched = page.waitForRequest(isListRequest, { timeout: 15_000 });
    await page.locator('#search_button').click();
    const url = new URL((await searched).url());
    expect(url.searchParams.get('extension_type')).toBe('module');
    expect(url.searchParams.get('extension_identifier')).toBe(module!.identifier);

    // 소유 종류를 바꾸면 고른 확장이 비워진다(없는 조합이 남지 않게).
    await page.locator('input[type=radio][name="ownerType"][value="plugin"]').check();
    const again = page.waitForRequest(isListRequest, { timeout: 15_000 });
    await page.locator('#search_button').click();
    const url2 = new URL((await again).url());
    expect(url2.searchParams.get('extension_type')).toBe('plugin');
    expect(url2.searchParams.has('extension_identifier')).toBe(false);
  });

  // @scenario case=screen_filter_submit
  // @effects schedule_screen_filters_submit_only
  test('초기화는 입력과 URL 조건을 모두 비우고 조건 없는 목록을 다시 받는다', async ({ page }) => {
    await page.goto('/admin/schedules?type=artisan&status=inactive&search_field=name&search_keyword=zzz');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });
    // 들어온 URL 조건이 입력칸에 채워져 있다(init 되읽기).
    await expect(page.locator('#search_input')).toHaveValue('zzz');
    await expect(page.locator('input[type=radio][name="type"][value="artisan"]')).toBeChecked();

    const reset = page.waitForRequest(isListRequest, { timeout: 15_000 });
    await page.locator('#reset_button_pc').click();
    const url = new URL((await reset).url());
    for (const key of ['type', 'status', 'search_field', 'search_keyword']) {
      expect(url.searchParams.has(key), `${key} 가 남았다`).toBe(false);
    }
    await expect(page).toHaveURL(/\/admin\/schedules$/);
    await expect(page.locator('#search_input')).toHaveValue('');
    await expect(page.locator('input[type=radio][name="type"][value="all"]')).toBeChecked();
  });

  // @scenario case=owner_filter
  // @effects schedule_screen_filters_submit_only
  test('확장 설정 탭 링크(소유 쿼리)로 들어오면 소유 라디오와 확장 선택이 그 값으로 채워진다 (M20·M21)', async ({ page }) => {
    const requests = trackListRequests(page);
    await page.goto('/admin/schedules?extension_type=module&extension_identifier=sirsoft-board');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });
    await expect(page.locator('input[type=radio][name="ownerType"][value="module"]')).toBeChecked();
    await expect(page.locator('#owner_identifier_dropdown [aria-haspopup="listbox"]')).toBeEnabled();
    const first = requests()[0];
    expect(first, '목록 요청이 없다').toBeTruthy();
    expect(first.searchParams.get('extension_type')).toBe('module');
    expect(first.searchParams.get('extension_identifier')).toBe('sirsoft-board');
  });

  // @scenario case=screen_tabs
  // @effects schedule_screen_tabs_scoped_data_sources
  test('스케줄 탭 조건은 큐 탭을 다녀와도 유지되고, 큐 탭에서는 목록 API 를 부르지 않는다 (M18)', async ({ page }) => {
    await page.goto('/admin/schedules?type=artisan');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });
    const requests = trackListRequests(page);

    const queueRequest = page.waitForRequest((r) => r.url().includes('/api/admin/schedules/queue/pending'), { timeout: 15_000 });
    await page.getByRole('tab', { name: /큐/ }).click();
    await queueRequest;
    await expect(page).toHaveURL(/\/admin\/schedules\/queue/);
    expect(requests()).toHaveLength(0);

    await page.getByRole('tab', { name: /스케줄/ }).click();
    await expect(page).toHaveURL(/\/admin\/schedules\?.*type=artisan/);
    await expect(page.locator('input[type=radio][name="type"][value="artisan"]')).toBeChecked();
  });
});
