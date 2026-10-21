/**
 * sirsoft-admin_basic — 관리자 화면 이동 링크 (#757 링크 단계).
 *
 * 결함: 사이드바 메뉴·목록 행 메뉴·페이지 번호·설정 탭·바로가기가 클릭으로만 이동해(렌더 시점에 주소가 없음)
 *   가운데 클릭·Ctrl+클릭·우클릭 「새 탭에서 열기」가 되지 않았다. 반대로 대시보드 바로가기처럼 이미 맨 `<a href>` 인
 *   자리는 보통 클릭이 SPA 를 버리고 문서 전체를 다시 불렀다(M1 수정 전 실측).
 *
 * 이 spec 은 실제 브라우저에서 ① Ctrl+클릭·가운데 클릭이 새 탭(새 page)을 열고 지금 탭은 그대로인지
 *   ② 보통 클릭은 SPA 로 옮겨 문서를 다시 부르지 않는지(창에 심은 표식이 살아남는지) 잰다. 컴포넌트 시험은 jsdom 의
 *   합성 이벤트, 레이아웃 잠금 시험은 굽기 산출물의 href 만 보므로 브라우저 기본 동작은 여기서만 확인된다.
 *
 * 시나리오 매니페스트: `tests/scenarios/navigation-links.yaml`
 * @effects plain_click_spa_navigate, modified_click_opens_browser_default, merge_query_in_href, layout_moves_are_links, link_open_marks_notification_read
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/admin-template-auth';
import type { APIRequestContext, BrowserContext, Locator, Page } from '@playwright/test';
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));

/** 새로고침 감지 표식 — 문서를 다시 부르면 창 전역이 사라진다. */
const MARK = '__g7NavMark757';

/** 화면 진입 — 한국어로 고정하고 데이터가 다 올 때까지 기다린 뒤 표식을 심는다. */
async function open(page: Page, token: string, path: string): Promise<void> {
  await authenticatePage(page, token);
  await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
  await page.goto(path);
  await page.waitForLoadState('networkidle', { timeout: 30_000 });
  await page.evaluate((m) => ((window as any)[m] = true), MARK);
}

/** 문서를 다시 부르지 않았는지(표식이 살아 있는지) */
async function stillSameDocument(page: Page): Promise<boolean> {
  return page.evaluate((m) => (window as any)[m] === true, MARK);
}

/**
 * 수정키·가운데 클릭으로 새 탭이 열리는지 — 새 page 의 주소를 돌려주고, 지금 탭의 주소가 그대로인지 확인한다.
 *
 * @param context 브라우저 문맥
 * @param page 지금 탭
 * @param link 누를 링크
 * @param how 'ctrl' | 'middle'
 * @returns 새 탭의 주소
 */
async function openInNewTab(context: BrowserContext, page: Page, link: Locator, how: 'ctrl' | 'middle'): Promise<string> {
  const before = page.url();
  const [popup] = await Promise.all([
    context.waitForEvent('page', { timeout: 15_000 }),
    how === 'ctrl' ? link.click({ modifiers: ['ControlOrMeta'] }) : link.click({ button: 'middle' }),
  ]);
  // 수정키·가운데 클릭으로 연 탭은 처음에 about:blank 로 생긴다 — 그 상태의 load 는 곧바로 끝나므로 주소가 실제
  // 목적지로 바뀔 때까지 기다린 뒤 읽는다(M1 실행 2026-10-15: 6/6 이 'about:blank' 를 읽어 실패, 화면은 정상).
  await popup.waitForURL((u) => u.protocol !== 'about:', { timeout: 15_000 });
  await popup.waitForLoadState('domcontentloaded');
  const url = popup.url();
  await popup.close();
  expect(page.url(), '지금 탭은 그대로').toBe(before);
  expect(await stillSameDocument(page), '지금 탭은 다시 부르지 않음').toBe(true);

  return url;
}

/**
 * 그 회원에게 알림 3건(안 읽음 2·읽음 1)을 심거나(`--url` 로 이동 주소) 심은 것만 지운다 — 코어 시드 커맨드.
 *
 * @param args 커맨드 인자
 * @returns 출력의 마지막 JSON 줄
 */
function seedNotifications(args: string): Record<string, any> {
  const coreRoot = process.env.G7_ROOT || resolve(__dirname, '../../../../../../../');
  const out = execSync(`php artisan playwright:seed-member-notification ${args}`, {
    cwd: coreRoot,
    encoding: 'utf-8',
    env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' },
  });
  const jsonLine = out.trim().split(/\r?\n/).filter((l) => l.trim().startsWith('{')).pop() ?? '{}';

  return JSON.parse(jsonLine);
}

/**
 * 토큰 회원의 uuid — 토큰은 시험마다 새 회원으로 발급되므로 알림을 심을 대상을 토큰으로 묻는다.
 *
 * @param request API 문맥
 * @param token 관리자 토큰
 * @returns 회원 uuid
 */
async function tokenUserUuid(request: APIRequestContext, token: string): Promise<string> {
  const res = await request.get('/api/admin/auth/user', { headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } });
  expect(res.ok(), `토큰 회원 조회 실패: ${res.status()}`).toBe(true);
  const uuid = (await res.json())?.data?.uuid;
  expect(typeof uuid === 'string' && uuid !== '', '토큰 회원 uuid').toBe(true);

  return uuid;
}

test.describe('@sirsoft-admin_basic 화면 이동 링크 (#757)', () => {
  // @scenario component=admin_sidebar
  // @effects modified_click_opens_browser_default, plain_click_spa_navigate
  test('사이드바 메뉴 — Ctrl·가운데 클릭은 새 탭, 보통 클릭은 문서 다시 부르기 없이 SPA 이동', async ({ page, context }) => {
    await open(page, issueToken('core.dashboard.read', 'core.users.read', 'core.settings.read'), '/admin/dashboard');
    const menu = page.locator('a.admin-sidebar-item[href="/admin/users"], a.admin-sidebar-subitem[href="/admin/users"]').first();
    await expect(menu).toBeVisible({ timeout: 15_000 });

    expect(new URL(await openInNewTab(context, page, menu, 'ctrl')).pathname).toBe('/admin/users');
    expect(new URL(await openInNewTab(context, page, menu, 'middle')).pathname).toBe('/admin/users');

    await menu.click();
    await page.waitForURL(/\/admin\/users(\?|$)/, { timeout: 15_000 });
    expect(await stillSameDocument(page), '보통 클릭은 SPA').toBe(true);
  });

  // @scenario component=data_grid
  // @effects modified_click_opens_browser_default, merge_query_in_href
  test('회원 목록 행 메뉴 「수정」 — Ctrl 클릭은 목록 조건을 실은 수정 화면을 새 탭으로 연다', async ({ page, context, userManageToken }) => {
    await open(page, userManageToken, '/admin/users?per_page=10');
    const trigger = page.locator('#users_data_grid [aria-haspopup="menu"]').first();
    await expect(trigger).toBeVisible({ timeout: 15_000 });
    await trigger.click();
    const edit = page.getByRole('menuitem', { name: '수정' }).first();
    await expect(edit).toHaveAttribute('href', /\/admin\/users\/[^/?]+\/edit\?.*per_page=10/);

    const url = new URL(await openInNewTab(context, page, edit, 'ctrl'));
    expect(url.pathname).toMatch(/^\/admin\/users\/[^/]+\/edit$/);
    expect(url.searchParams.get('per_page')).toBe('10');
  });

  // @scenario component=pagination
  // @effects modified_click_opens_browser_default, merge_query_in_href
  test('회원 목록 페이지 번호 — 번호가 링크이고 Ctrl 클릭은 2쪽을 새 탭으로 연다(목록 조건 유지)', async ({ page, context, userManageToken }) => {
    // 쪽이 둘 이상 나오도록 표시 개수를 1로 둔다(최고 관리자 + 시드 회원)
    await open(page, userManageToken, '/admin/users?per_page=1');
    const page2 = page.locator('#users_data_grid a[href*="page=2"]').first();
    await expect(page2).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#users_data_grid a[aria-current="page"]').first()).toBeVisible();

    const url = new URL(await openInNewTab(context, page, page2, 'ctrl'));
    expect(url.searchParams.get('page')).toBe('2');
    expect(url.searchParams.get('per_page')).toBe('1');

    await page2.click();
    await page.waitForURL(/[?&]page=2/, { timeout: 15_000 });
    expect(await stillSameDocument(page), '보통 클릭은 SPA').toBe(true);
  });

  // @scenario component=tab_navigation
  // @effects modified_click_opens_browser_default, link_roles_preserved
  test('환경설정 탭 — 탭이 role=tab 링크이고 Ctrl 클릭은 그 탭을 새 탭으로 연다', async ({ page, context }) => {
    await open(page, issueToken('core.settings.read'), '/admin/settings?tab=general');
    const mail = page.locator('a[role="tab"][href="/admin/settings?tab=mail"]').first();
    await expect(mail).toBeVisible({ timeout: 15_000 });

    const url = new URL(await openInNewTab(context, page, mail, 'ctrl'));
    expect(url.pathname).toBe('/admin/settings');
    expect(url.searchParams.get('tab')).toBe('mail');

    await mail.click();
    await page.waitForURL(/[?&]tab=mail/, { timeout: 15_000 });
    expect(await stillSameDocument(page), '보통 클릭은 SPA').toBe(true);
  });

  // @scenario component=a
  // @effects plain_click_spa_navigate, modified_click_opens_browser_default
  test('대시보드 바로가기 — 보통 클릭은 문서를 다시 부르지 않고(SPA), Ctrl 클릭은 새 탭', async ({ page, context }) => {
    await open(page, issueToken('core.dashboard.read', 'core.users.read'), '/admin/dashboard');
    const shortcut = page.locator('#qm_users');
    await expect(shortcut).toBeVisible({ timeout: 15_000 });
    await expect(shortcut).toHaveAttribute('href', '/admin/users');

    expect(new URL(await openInNewTab(context, page, shortcut, 'ctrl')).pathname).toBe('/admin/users');

    await shortcut.click();
    await page.waitForURL(/\/admin\/users(\?|$)/, { timeout: 15_000 });
    expect(await stillSameDocument(page), '문서 다시 부르기 0').toBe(true);
  });

  // @scenario component=layout_lock
  // @effects layout_moves_are_links
  test('목록 화면의 「추가」 단추는 링크 — 비활성이 아니면 href 를 갖고 Ctrl 클릭은 새 탭', async ({ page, context, userManageToken }) => {
    await open(page, userManageToken, '/admin/users?per_page=10');
    const add = page.locator('#add_user_button');
    await expect(add).toBeVisible({ timeout: 15_000 });
    await expect(add).toHaveAttribute('href', /\/admin\/users\/create\?.*per_page=10/);

    const url = new URL(await openInNewTab(context, page, add, 'ctrl'));
    expect(url.pathname).toBe('/admin/users/create');
  });

  // @scenario component=notification_center
  // @effects link_open_marks_notification_read, modified_click_opens_browser_default
  test('알림 센터 — 안 읽은 링크 알림을 Ctrl 클릭하면 새 탭으로 열고, 지금 탭·목록은 그대로 두고 그 알림을 읽음 처리한다', async ({ page, context, request }) => {
    const token = issueToken('core.dashboard.read', 'core.notifications.read', 'core.notifications.update');
    // 토큰 회원에게 이동 주소가 있는 알림을 심는다(안 읽음 2·읽음 1) — 화면 진입 전에 심어야 첫 목록에 실린다
    const uuid = await tokenUserUuid(request, token);
    const seed = seedNotifications(`--user=${uuid} --url=/admin/users --json`);
    try {
      expect(seed.unread_count, '심은 안 읽은 알림').toBe(2);
      const id = String(seed.notification_ids[0]);

      await open(page, token, '/admin/dashboard');
      await page.getByRole('button', { name: '알림' }).first().click();
      const row = page.locator(`[data-notification-id="${id}"]`).first();
      await expect(row).toHaveAttribute('data-unread', 'true', { timeout: 15_000 });
      const link = row.locator('a[href]').first();
      await expect(link).toHaveAttribute('href', '/admin/users');

      const [read, url] = await Promise.all([
        page.waitForResponse((r) => r.request().method() === 'PATCH' && r.url().includes(`/api/admin/notifications/${id}/read`), { timeout: 15_000 }),
        openInNewTab(context, page, link, 'ctrl'),
      ]);
      expect(read.ok(), '읽음 요청 성공').toBe(true);
      expect(new URL(url).pathname, '새 탭은 알림 주소').toBe('/admin/users');
      await expect(row, '목록은 열린 채').toBeVisible();
    } finally {
      seedNotifications(`--user=${uuid} --teardown --json`);
    }
  });

  // @scenario component=sortable_menu_list
  // @effects panel_select_item_is_link, modified_click_opens_browser_default
  test('메뉴 관리 — 목록 항목이 그 항목 패널 주소의 링크이고, Ctrl 클릭 새 탭·직접 주소 진입이 그 항목 보기 패널을 연다', async ({ page, context }) => {
    // 역할 이름표(/api/admin/roles/active)는 권한 없이 열린다 — 메뉴 조회 권한만 준다
    const token = issueToken('core.menus.read');
    await open(page, token, '/admin/menus');
    const item = page.locator('#sortable_menu_list a[href*="menu="]').first();
    await expect(item).toBeVisible({ timeout: 15_000 });
    const href = await item.getAttribute('href');
    const slug = new URL(href ?? '', 'http://x').searchParams.get('menu');
    expect(slug, '항목 주소의 menu 값').toBeTruthy();
    expect(new URL(href ?? '', 'http://x').searchParams.get('mode')).toBe('view');

    // Ctrl 클릭 — 새 탭이 그 항목 주소로 열리고 지금 탭은 그대로
    const url = new URL(await openInNewTab(context, page, item, 'ctrl'));
    expect(url.pathname).toBe('/admin/menus');
    expect(url.searchParams.get('menu')).toBe(slug);

    // 직접 주소 진입 — 그 항목의 보기 패널이 열린다(새 탭·새로고침·주소 공유와 같은 길)
    const direct = await context.newPage();
    await authenticatePage(direct, token);
    await direct.goto(`/admin/menus?menu=${encodeURIComponent(slug!)}&mode=view`);
    await expect(direct.locator('#view_panel')).toBeVisible({ timeout: 15_000 });
    await expect(direct.locator('#view_content')).toContainText(slug!);
    await direct.close();

    // 보통 클릭 — 문서를 다시 부르지 않고 그 항목 패널을 열며 주소에 같은 항목을 남긴다
    await item.click();
    await expect(page.locator('#view_panel')).toBeVisible({ timeout: 15_000 });
    await expect.poll(() => new URL(page.url()).searchParams.get('menu')).toBe(slug);
    expect(await stillSameDocument(page), '보통 클릭은 SPA').toBe(true);
  });

  // @scenario component=file_tree
  // @effects panel_select_item_is_link, modified_click_opens_browser_default
  test('레이아웃 편집 — 화면 주소가 있는 레이아웃 파일은 링크이고 Ctrl 클릭 새 탭이 그 레이아웃을 고른 채 열린다', async ({ page, context, layoutEditToken }) => {
    await open(page, layoutEditToken, '/admin/templates/sirsoft-basic/edit');
    const fileLink = page.locator('#file_tree_picker [role="treeitem"] a[href*="route="]').first();
    await expect(fileLink).toBeAttached({ timeout: 20_000 });
    const href = await fileLink.getAttribute('href');
    const routePath = new URL(href ?? '', 'http://x').searchParams.get('route');
    expect(routePath, '링크의 route 값').toBeTruthy();
    // 링크는 트리의 roving 초점을 깨지 않도록 탭 순서 밖이다
    await expect(fileLink).toHaveAttribute('tabindex', '-1');

    const url = new URL(await openInNewTab(context, page, fileLink, 'ctrl'));
    expect(url.pathname).toBe('/admin/templates/sirsoft-basic/edit');
    expect(url.searchParams.get('route')).toBe(routePath);

    // 직접 주소 진입 — 그 레이아웃 행이 선택된 채 열린다
    const direct = await context.newPage();
    await authenticatePage(direct, layoutEditToken);
    await direct.goto(href!);
    const selected = direct.locator('#file_tree_picker [role="treeitem"][aria-selected="true"] a[href*="route="]');
    await expect(selected).toBeAttached({ timeout: 20_000 });
    expect(new URL((await selected.getAttribute('href')) ?? '', 'http://x').searchParams.get('route')).toBe(routePath);
    await direct.close();
  });
});
