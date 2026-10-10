/**
 * E2E: 회원관리 목록 필터 — 적용·복원·검색편집·초기화·목록 문맥·확장 필터 (#705)
 *
 * 회원관리 필터를 쿠폰·상품·주문과 같은 슬롯 기반(기본/상세 + 검색편집)으로 바꿨다. 단위 테스트는
 * 레이아웃 선언과 서버 필터를 각각 잠그지만, "화면에서 고른 조건 → URL → 서버 요청 → 새로고침 복원" 의
 * 연결은 한 화면에서 이어 봐야만 드러난다. 브라우저 실측에서 슬롯으로 옮겨 그려진 행이 `_defines` 를
 * 받지 못해 상태 체크박스가 통째로 사라졌던 결함도 이 흐름에서만 보였다.
 *
 * 로케일은 ko 로 고정되어 있으므로 체크박스는 라벨 문구(차단·휴면 등)로 고른다. 결과 건수는 개발 DB
 * 데이터에 따라 달라지므로 숫자를 박지 않고 "요청과 화면이 같은 조건을 가리키는가" 를 본다.
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage, issueToken } from '../../fixtures/auth';

/**
 * 회원 목록에 들어가 필터 카드가 뜰 때까지 기다린다.
 *
 * @param page 대상 페이지
 * @param path 이동 경로
 */
async function gotoUsers(page: Page, path = '/admin/users'): Promise<void> {
  // 첫 목록 응답까지 기다린다 — 고정 대기만 두면 느린 환경에서 진입 요청이 늦게 도착해
  // 뒤따르는 검색 요청 대기(usersRequest)가 그 응답을 검색 결과로 오인한다.
  const firstList = page.waitForResponse(
    (r) => r.url().includes('/api/admin/users?') && r.request().method() === 'GET',
    { timeout: 60000 }
  );
  await page.goto(path);
  await firstList;
  await page.waitForSelector('#filter_card', { timeout: 30000 });
  await page.waitForTimeout(800);
}

/**
 * 조작 뒤 나가는 회원 목록 요청 URL(디코드)을 받는다.
 *
 * @param page 대상 페이지
 * @param act 요청을 일으키는 조작
 * @returns 요청 URL 과 상태 코드
 */
async function usersRequest(page: Page, act: () => Promise<void>): Promise<{ url: string; status: number }> {
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/admin/users?') && r.request().method() === 'GET'),
    act(),
  ]);
  await page.waitForTimeout(800);

  return { url: decodeURIComponent(response.url()), status: response.status() };
}

/**
 * 첫 행의 행 메뉴(…)를 열고 항목을 누른다.
 *
 * 목록 응답이 늦게 도착해 행이 다시 그려지면 열린 메뉴가 닫히므로, 항목이 보일 때까지 다시 연다.
 *
 * @param page 대상 페이지
 * @param item 누를 메뉴 항목 문구
 */
async function clickRowMenuItem(page: Page, item: string): Promise<void> {
  await expect(async () => {
    await page.locator('#users_data_grid__body tbody tr').first().locator('td').last().locator('button').click();
    await expect(page.getByText(item, { exact: true })).toBeVisible({ timeout: 3000 });
  }).toPass({ timeout: 30_000 });
  await page.getByText(item, { exact: true }).click();
}

/**
 * 체크된 계정 상태 라벨 목록.
 *
 * @param page 대상 페이지
 * @returns 라벨 문구 목록
 */
async function checkedStatuses(page: Page): Promise<string[]> {
  return page.evaluate(() =>
    [...document.querySelectorAll<HTMLInputElement>('input[name="status"]')]
      .filter((i) => i.checked)
      .map((i) => i.closest('label')?.textContent?.trim() ?? '')
  );
}

test.describe('회원관리 목록 필터', () => {
  // 한 테스트가 목록 진입·새로고침·다른 화면 왕복을 여러 번 한다 — 진입 한 번의 응답 대기가 도우미에서
  // 60초까지 잡혀 있어 기본 30초 예산으로는 느린 개발 환경에서 흐름 중간에 끊긴다.
  test.describe.configure({ timeout: 90_000 });

  test.beforeEach(async ({ page }) => {
    const token = issueToken('core.users.read');
    await authenticatePage(page, token);
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects admin_user_list_filters_restored_after_reload, admin_user_list_filter_axes_narrow_results
  test('조건을 걸면 URL·요청에 실리고 새로고침 뒤 입력값이 복원된다', async ({ page }) => {
    // 2쪽에서 출발 — 검색이 쪽수를 떨궈(`page=""`, list-context 규정) 첫 쪽으로 돌아오는지 함께 본다.
    await gotoUsers(page, '/admin/users?page=2');

    // 상태 체크박스 6종이 실제로 그려진다 — 슬롯 투영 행의 선택지 회귀 가드
    await expect(page.locator('input[name="status"]')).toHaveCount(6);

    await page.locator('[id^="status_filter_row"] label', { hasText: '차단' }).locator('input').check();
    await page.locator('[id^="created_date_filter_row"] .btn-date').nth(4).click(); // 6개월
    const dates = await page.locator('[id^="created_date_filter_row"] input[type="date"]').evaluateAll((els) => els.map((e) => (e as HTMLInputElement).value));
    expect(dates[0]).toMatch(/^\d{4}-\d{2}-\d{2}$/);

    const req = await usersRequest(page, () => page.click('#search_button'));
    expect(req.status).toBe(200);
    expect(req.url).toContain('status[]=blocked');
    expect(req.url).toContain(`start_date=${dates[0]}`);
    expect(decodeURIComponent(page.url())).toContain('status[]=blocked');
    expect(new URL(page.url()).searchParams.get('page')).toBeNull();
    expect(req.url).not.toContain('page=2');

    await page.reload();
    await page.waitForSelector('#filter_card');
    await page.waitForTimeout(1500);
    expect(await checkedStatuses(page)).toEqual(['차단']);
    const restored = await page.locator('[id^="created_date_filter_row"] input[type="date"]').evaluateAll((els) => els.map((e) => (e as HTMLInputElement).value));
    expect(restored).toEqual(dates);
  });

  // 역할이 수십 개인 설치에서 체크박스를 늘어놓으면 행이 화면 몇 개 높이로 자랐다.
  // 회원 폼과 같은 「검색 + 태그」 한 칸 — 이름을 쳐서 고르고, 고른 태그는 URL 에서 되읽혀 새로고침 뒤에도 남는다.
  // @scenario filter_axis=role, outcome=narrows
  // @effects admin_user_list_filters_restored_after_reload, admin_user_list_filter_axes_narrow_results
  test('권한 그룹은 검색해서 태그로 고르고, 요청·새로고침 뒤에도 태그가 남는다', async ({ page }) => {
    const token = issueToken('core.users.read');
    await authenticatePage(page, token);
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
    const roles = (await (await page.request.get('/api/admin/roles/active', { headers })).json())?.data?.data ?? [];
    const role = roles.find((r: { identifier: string }) => r.identifier === 'admin') ?? roles[0];
    expect(role, '활성 역할이 하나도 없습니다').toBeTruthy();

    await gotoUsers(page);
    const row = page.locator('[id^="role_filter_row"]');

    // 역할 수만큼 체크박스를 그리지 않는다 — 행 높이가 입력 한 줄 수준이다.
    await expect(row.locator('input[type="checkbox"][name]')).toHaveCount(0);
    expect((await row.boundingBox())!.height).toBeLessThan(80);

    // 역할 목록이 오기 전에는 입력이 잠겨 있다(id 대신 「…」 태그를 두는 동안) — 풀릴 때까지 기다린다.
    await expect(row.locator('.tag-input__input-container input')).toBeEnabled({ timeout: 20_000 });
    await row.locator('.tag-input__input-container input').fill(role.name);
    await page.locator('.tag-input__option', { hasText: new RegExp(`^${role.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`) }).first().click();
    await expect(row.locator('.tag-input__multi-value')).toHaveCount(1);

    const req = await usersRequest(page, () => page.click('#search_button'));
    expect(req.status).toBe(200);
    expect(req.url).toContain(`role_ids[]=${role.id}`);
    expect(decodeURIComponent(page.url())).toContain(`role_ids[]=${role.id}`);

    await page.reload();
    await page.waitForSelector('#filter_card');
    await page.waitForTimeout(1500);
    await expect(page.locator('[id^="role_filter_row"] .tag-input__multi-value')).toHaveCount(1);
    // 역할 목록은 목록보다 늦게 온다(냉간 수 초) — 그 전에는 id 대신 「…」 태그다. 이름이 붙을 때까지 기다린다.
    await expect(page.locator('[id^="role_filter_row"] .tag-input__multi-value')).toContainText(role.name, { timeout: 15_000 });
    await expect(page.locator('[id^="role_filter_row"] .tag-input__multi-value')).not.toHaveText(String(role.id));
  });

  // @scenario filter_axis=last_login, outcome=narrows
  // @effects admin_user_list_edit_mode_layout_persists
  test('검색편집으로 상세 필터를 기본으로 옮기면 새로고침 뒤에도 유지된다', async ({ page }) => {
    await gotoUsers(page);
    await expect(page.locator('#basic_filters_section .filter-row')).toHaveCount(3);

    await page.click('#edit_search_button_pc');
    await page.locator('[id^="last_login_filter_row"] > input[type="checkbox"]').check();
    await page.waitForTimeout(500);

    const stored = await page.evaluate(() =>
      Object.keys(localStorage).filter((k) => k.startsWith('g7_filters_admin_user_filters')).map((k) => localStorage.getItem(k))
    );
    expect(stored.join('')).toContain('lastLoginAt');

    await page.reload();
    await page.waitForSelector('#filter_card');
    await page.waitForTimeout(1500);
    const basic = await page.locator('#basic_filters_section .filter-row').evaluateAll((els) => els.map((e) => e.id.replace(/__.*$/, '')));
    expect(basic).toContain('last_login_filter_row');

    // 원복 — 다음 실행을 오염시키지 않는다
    await page.evaluate(() => Object.keys(localStorage).filter((k) => k.startsWith('g7_filters_admin_user_filters')).forEach((k) => localStorage.removeItem(k)));
  });

  // @scenario filter_axis=admin_scope, outcome=narrows
  // @effects admin_user_list_reset_clears_url
  test('필터 초기화는 걸린 조건을 URL 에서 모두 지운다', async ({ page }) => {
    await gotoUsers(page, '/admin/users?status[]=blocked&admin_scope=member&language=ko');
    expect(await checkedStatuses(page)).toEqual(['차단']);

    await page.click('#reset_button_pc');
    await page.waitForTimeout(2000);

    expect(new URL(page.url()).search).toBe('');
    expect(await checkedStatuses(page)).toEqual([]);
    await expect(page.locator('input[name="statusAll"]')).toBeChecked();
  });

  // @scenario filter_axis=role, outcome=narrows
  // @effects admin_user_list_context_preserved
  test('등록 폼·회원 상세에 갔다가 취소·뒤로 버튼으로 돌아오면 조건·정렬이 그대로다', async ({ page }) => {
    const query = '?status[]=active&sort_by=name&sort_order=desc&per_page=20';
    await gotoUsers(page, '/admin/users' + query);

    // ① 등록 폼 → 폼의 「취소」 버튼
    await page.click('#add_user_button');
    await page.waitForURL(/\/admin\/users\/create/);
    expect(decodeURIComponent(new URL(page.url()).search)).toBe(query);

    await page.click('#footer_cancel_button');
    await page.waitForURL(/\/admin\/users(\?|$)/);
    await page.waitForSelector('#filter_card');
    await page.waitForTimeout(1500);
    expect(decodeURIComponent(new URL(page.url()).search)).toBe(query);
    expect(await checkedStatuses(page)).toEqual(['활성']);

    // ② 행 메뉴 「상세보기」 → 상세 화면의 「뒤로」 버튼
    await clickRowMenuItem(page, '상세보기');
    await page.waitForURL(/\/admin\/users\/[0-9a-f-]{36}(\?|$)/);
    expect(decodeURIComponent(new URL(page.url()).search)).toBe(query);

    await page.click('#header_back_button');
    await page.waitForURL(/\/admin\/users(\?|$)/);
    await page.waitForSelector('#filter_card');
    await page.waitForTimeout(1500);
    expect(decodeURIComponent(new URL(page.url()).search)).toBe(query);
    expect(await checkedStatuses(page)).toEqual(['활성']);

    // ③ 브라우저 뒤로가기도 같은 결과
    await page.click('#add_user_button');
    await page.waitForURL(/\/admin\/users\/create/);
    await page.goBack();
    await page.waitForSelector('#filter_card');
    await page.waitForTimeout(1500);
    expect(decodeURIComponent(new URL(page.url()).search)).toBe(query);
    expect(await checkedStatuses(page)).toEqual(['활성']);
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects admin_user_list_restored_after_back_navigation
  test('조건을 건 뒤 다른 화면으로 갔다가 브라우저 뒤로가기로 돌아와도 조건과 결과가 복원된다', async ({ page }) => {
    await gotoUsers(page);
    await page.locator('[id^="status_filter_row"] label', { hasText: '차단' }).locator('input').check();
    const [applied] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/api/admin/users?') && decodeURIComponent(r.url()).includes('status[]=blocked')),
      page.click('#search_button'),
    ]);
    const filteredTotal = (await applied.json())?.data?.pagination?.total;
    expect(typeof filteredTotal).toBe('number');
    await expect(page.getByText(`총 ${filteredTotal}개`)).toBeVisible({ timeout: 20_000 });

    // `/admin` 은 `/admin/dashboard` 로 넘어가며 기록을 하나 더 쌓아 뒤로가기가 다시 대시보드로 튕긴다 —
    // 그 전환과 무관하게 「다른 화면에 갔다가 돌아오기」만 보려고 대시보드 주소로 바로 가고, 다 그려진 뒤 돌아간다.
    await page.goto('/admin/dashboard');
    await expect(page.getByRole('heading', { name: '대시보드', level: 1 })).toBeVisible({ timeout: 60_000 });
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 돌아오면 조건과 결과가 함께 살아 있어야 한다 — 체크박스만 살고 표가 전체 목록이면 안 된다.
    // (브라우저가 페이지를 캐시에서 되살리면 요청이 다시 나가지 않으므로 요청이 아니라 화면의 건수를 본다.)
    await page.goBack();
    await page.waitForSelector('#filter_card', { timeout: 60_000 });
    expect(decodeURIComponent(page.url())).toContain('status[]=blocked');
    await expect(page.getByText(`총 ${filteredTotal}개`)).toBeVisible({ timeout: 30_000 });
    expect(await checkedStatuses(page)).toEqual(['차단']);
  });

  // @scenario filter_axis=keyword, outcome=narrows
  // @effects admin_user_list_row_actions_unchanged
  test('필터 영역을 바꾼 뒤에도 행 메뉴의 수정·삭제가 그대로 동작한다', async ({ page }) => {
    // 이 테스트만 쓰기 권한이 필요하다 — 버리는 회원을 만들어 지운다(개발 DB 의 기존 회원은 건드리지 않는다).
    const token = issueToken('core.users.read', 'core.users.create', 'core.users.update', 'core.users.delete');
    await authenticatePage(page, token);
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
    const tag = `e2erow${Date.now().toString(36)}`;

    const roles = (await (await page.request.get('/api/admin/roles/active', { headers })).json())?.data?.data ?? [];
    const roleId = (roles.find((r: { identifier: string }) => r.identifier === 'user') ?? roles[roles.length - 1])?.id;
    const created = await page.request.post('/api/admin/users', {
      headers,
      data: { name: `E2E행메뉴 ${tag}`, email: `${tag}@example.com`, password: 'Password!234', password_confirmation: 'Password!234', status: 'active', role_ids: [roleId] },
    });
    expect(created.status(), await created.text()).toBeLessThan(300);
    const uuid = (await created.json())?.data?.uuid;

    const findRow = async () => {
      await gotoUsers(page);
      await page.locator('#search_input').fill(tag);
      await usersRequest(page, () => page.locator('#search_input').press('Enter'));
      await expect(page.locator('#users_data_grid__body tbody tr')).toHaveCount(1, { timeout: 20_000 });
    };
    // 수정 — 편집 화면으로 이동하고 목록 조건(검색어)을 달고 간다
    await findRow();
    await clickRowMenuItem(page, '수정');
    await page.waitForURL(new RegExp(`/admin/users/${uuid}/edit`));
    expect(decodeURIComponent(page.url())).toContain(`filters[0][value]=${tag}`);

    // 삭제 — 확인 창을 거쳐 DELETE 가 나가고 목록에서 사라진다
    await findRow();
    await clickRowMenuItem(page, '삭제');
    const [deleted] = await Promise.all([
      page.waitForResponse((r) => r.url().includes(`/api/admin/users/${uuid}`) && r.request().method() === 'DELETE'),
      page.locator('.modal-footer-buttons button.bg-red-600').click(),
    ]);
    expect(deleted.status()).toBe(200);
    await expect(page.locator('#users_data_grid__body tbody tr', { hasText: tag })).toHaveCount(0, { timeout: 20_000 });
  });

  // @scenario filter_axis=country, outcome=narrows
  // @effects admin_user_list_extension_filters_sent
  test('확장 필터(이커머스 마일리지·배송지)가 ext_filters[] 로 나가고 새로고침 뒤 복원된다', async ({ page }) => {
    await gotoUsers(page);
    await page.click('#detail_search_toggle');
    await page.waitForTimeout(800);

    const mileage = page.locator('input[name="ecommerceMileageMin"]');
    test.skip((await mileage.count()) === 0, '이커머스 모듈 비활성 — 확장 필터 행이 없다');

    await mileage.fill('1000');
    await mileage.blur();
    const hasAddressYes = page.locator('[id^="ext_ecommerce_address_filter_row"] label', { hasText: /^있음$/ }).locator('input');
    await hasAddressYes.check();

    const req = await usersRequest(page, () => page.click('#search_button'));
    expect(req.status).toBe(200);
    expect(req.url).toContain('ext_filters[]=sirsoft-ecommerce.mileage_min=1000');
    expect(req.url).toContain('ext_filters[]=sirsoft-ecommerce.has_address=1');

    await page.reload();
    await page.waitForSelector('#filter_card');
    await page.waitForTimeout(1500);
    await expect(page.locator('#detail_filters_section')).toBeVisible();
    await expect(page.locator('input[name="ecommerceMileageMin"]')).toHaveValue('1000');
    await expect(page.locator('[id^="ext_ecommerce_address_filter_row"] label', { hasText: /^있음$/ }).locator('input')).toBeChecked();
  });
});
