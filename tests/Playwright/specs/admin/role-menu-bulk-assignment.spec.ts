/**
 * 역할↔메뉴 일괄 할당 — 역할 폼 「접근 가능 메뉴」 · 메뉴 관리 「여러 개 선택」 · 목록 역할 배지 (#708)
 *
 * 브라우저에서만 드러나는 연결을 고정한다.
 * - 역할 폼: 하위 메뉴를 체크하면 상위도 체크되고, 저장 본문의 menu_ids 가 서버에 반영된다
 * - 메뉴 관리: 선택 모드에서 메뉴를 골라 역할을 더하면 PATCH 가 1건 나가고, 목록 행 배지에 그 역할 이름이 보인다.
 *   작업을 「역할 제거」 로 바꿔 같은 메뉴에서 빼면 제거 본문이 나가고 배지에서 그 이름이 사라진다
 * - 저장·적용 버튼 더블클릭은 요청 1건 (폼 submit 은 엔진 가드, 확인 창은 디바운스)
 *
 * 스펙은 자기 역할을 만들고 끝나면 지운다.
 *
 * @scenario entry=role_form_update, actor=super_admin, menu_shape=child_only
 * @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=top_level
 * @scenario entry=menu_bulk_remove, actor=super_admin, menu_shape=top_level
 * @effects parent_auto_included, sidebar_reflects_assignment, menu_list_shows_assigned_roles
 */
import { test, expect, authenticatePage, issueToken, issueScopedToken } from '../../fixtures/auth';

type Api = (method: string, url: string, body?: unknown) => Promise<{ status: number; json: any }>;

/** 페이지 안에서 인증 헤더를 붙여 API 를 부른다. */
function apiOf(page: import('@playwright/test').Page): Api {
  return (method, url, body) =>
    page.evaluate(
      async ({ method, url, body }) => {
        const res = await fetch(url, {
          method,
          headers: {
            Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
            Accept: 'application/json',
            'Content-Type': 'application/json',
          },
          body: body === undefined ? undefined : JSON.stringify(body),
        });
        return { status: res.status, json: await res.json().catch(() => null) };
      },
      { method, url, body },
    );
}

test.describe('역할↔메뉴 일괄 할당 (#708)', () => {
  // 준비 단계가 역할·회원을 API 로 만들고 지운다 — 기본 30초로는 모자라다
  test.setTimeout(90_000);

  let roleId: number | null = null;
  const identifier = `e2e_role_menu_${Date.now()}`;
  const roleName = `E2E 메뉴역할 ${Date.now() % 100000}`;

  test.beforeEach(async ({ page }) => {
    await authenticatePage(page, issueToken('core.permissions.read', 'core.permissions.update', 'core.permissions.create', 'core.menus.read', 'core.menus.update'));
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    const created = await apiOf(page)('POST', '/api/admin/roles', { identifier, name: { ko: roleName, en: roleName }, is_active: true, permissions: [] });
    expect(created.status).toBe(201);
    roleId = created.json.data.id;
  });

  test.afterEach(async ({ page }) => {
    if (roleId) await apiOf(page)('DELETE', `/api/admin/roles/${roleId}`);
    roleId = null;
  });

  // @scenario entry=role_form_update, actor=super_admin, menu_shape=child_only
  // @effects parent_auto_included
  test('역할 폼에서 하위 메뉴를 고르면 상위도 함께 저장되고, 저장 더블클릭은 PUT 1건이다', async ({ page }) => {
    const api = apiOf(page);
    const tree = await api('GET', '/api/admin/menus?with_children=true&hierarchical=true');
    const parent = (tree.json.data.data as any[]).find((m) => (m.children ?? []).length > 0);
    test.skip(!parent, '하위 메뉴가 있는 메뉴가 없다');
    const child = parent.children[0];

    await page.goto(`/admin/roles/${roleId}/edit`);
    const count = page.getByTestId('menu-access-count');
    await expect(count).toBeVisible({ timeout: 30_000 });
    await expect(count).toHaveText(/^0\//);

    // 상위 펼치기 → 하위 체크 (체크박스와 펼치기 버튼은 같은 행 요소에 있다)
    const section = page.locator('#menus_section');
    const parentBox = section.getByRole('checkbox', { name: parent.name.ko, exact: true }).first();
    await parentBox.locator('xpath=..').getByRole('button', { name: '펼치기' }).click();
    const childBox = section.getByRole('checkbox', { name: child.name.ko, exact: true }).last();
    await childBox.click();
    await expect(childBox).toBeChecked();
    await expect(parentBox).toBeChecked();

    const puts: string[] = [];
    page.on('request', (req) => {
      if (req.method() === 'PUT' && req.url().endsWith(`/api/admin/roles/${roleId}`)) puts.push(req.url());
    });
    await page.getByRole('button', { name: /저장/ }).first().dblclick();
    await page.waitForURL(/\/admin\/roles(\?|$)/, { timeout: 30_000 });
    expect(puts).toHaveLength(1);

    const saved = await api('GET', `/api/admin/roles/${roleId}`);
    expect(saved.json.data.menu_ids).toEqual(expect.arrayContaining([parent.id, child.id]));

    // 이 역할을 받은 계정의 사이드바에 상위·하위 메뉴가 나타난다.
    // 메뉴 조회 권한만 가진 별도 계정(관리자 역할 없음)에 역할을 붙이고 그 계정으로 사이드바 메뉴를 읽는다.
    const memberContext = await page.context().browser()!.newContext({ baseURL: new URL(page.url()).origin, ignoreHTTPSErrors: true });
    const memberPage = await memberContext.newPage();
    await authenticatePage(memberPage, issueScopedToken('core.menus.read'));
    await memberPage.goto('/admin/dashboard');
    const member = apiOf(memberPage);
    const me = await member('GET', '/api/admin/auth/user');
    const memberUuid = me.json?.data?.uuid ?? me.json?.data?.user?.uuid;
    expect(memberUuid, JSON.stringify(me.json).slice(0, 200)).toBeTruthy();

    const before = await member('GET', '/api/admin/menus/active');
    const slugsOf = (res: any) => ((res.json?.data?.data ?? res.json?.data ?? []) as any[]).flatMap((m) => [m.slug, ...(m.children ?? []).map((c: any) => c.slug)]);
    expect(slugsOf(before)).not.toContain(child.slug);

    const granted = await api('PATCH', '/api/admin/users/bulk-roles', { uuids: [memberUuid], role_ids: [roleId], mode: 'add' });
    expect(granted.status).toBe(200);
    const after = await member('GET', '/api/admin/menus/active');
    expect(slugsOf(after)).toEqual(expect.arrayContaining([parent.slug, child.slug]));
    await memberContext.close();
  });

  // @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=top_level
  // @scenario entry=menu_bulk_remove, actor=super_admin, menu_shape=top_level
  // @effects menu_list_shows_assigned_roles, sidebar_reflects_assignment
  test('메뉴 관리 선택 모드로 역할을 더하면 PATCH 1건이고 목록 행 배지에 역할 이름이 보이며, 같은 화면에서 다시 빼면 배지에서 사라진다', async ({ page }) => {
    const api = apiOf(page);
    const tree = await api('GET', '/api/admin/menus?with_children=true&hierarchical=true');
    const target = (tree.json.data.data as any[]).find((m) => (m.children ?? []).length === 0);
    test.skip(!target, '하위 메뉴 없는 최상위 메뉴가 없다');

    await page.goto('/admin/menus');
    await expect(page.getByTestId('menu-item-roles').first()).toBeVisible({ timeout: 30_000 });

    await page.getByRole('button', { name: /여러 개 선택/ }).click();
    // 체크 상태는 상위 상태 갱신 뒤 다시 그려져 반영된다(제어 컴포넌트) — check() 의 즉시 검증 대신 클릭 후 단언한다
    const targetBox = page.getByRole('checkbox', { name: target.name.ko, exact: true }).first();
    await targetBox.click();
    await expect(targetBox).toBeChecked();
    await page.locator('#bulk_roles_input').click();
    await page.getByRole('option', { name: roleName, exact: true }).click();
    await page.keyboard.press('Escape');
    await page.locator('#bulk_apply_button').click();

    const patches: string[] = [];
    page.on('request', (req) => {
      if (req.method() === 'PATCH' && req.url().includes('/api/admin/menus/bulk-roles')) patches.push(req.url());
    });
    await page.getByRole('dialog').getByRole('button', { name: '적용', exact: true }).dblclick();
    await expect(page.getByRole('dialog')).toBeHidden({ timeout: 15_000 });
    expect(patches).toHaveLength(1);

    // 행 이름(첫 Span)이 대상 메뉴인 배지 줄의 title 에 새 역할 이름이 실린다
    await expect
      .poll(
        () =>
          page.evaluate((name) => {
            const rows = [...document.querySelectorAll('[data-testid="menu-item-roles"]')];
            const row = rows.find((r) => r.parentElement?.querySelector('span')?.textContent?.trim() === name);
            return row?.getAttribute('title') ?? '';
          }, target.name.ko),
        { timeout: 15_000 },
      )
      .toContain(roleName);

    // 같은 화면에서 작업을 「역할 제거」 로 바꿔 그 메뉴에서 역할을 뺀다.
    // 적용 뒤 고른 메뉴는 비워지고 고른 역할은 남아 있으므로, 메뉴만 다시 체크하면 된다.
    const removeBodies: unknown[] = [];
    page.on('request', (req) => {
      if (req.method() === 'PATCH' && req.url().includes('/api/admin/menus/bulk-roles')) removeBodies.push(req.postDataJSON());
    });
    await targetBox.click();
    await expect(targetBox).toBeChecked();
    await page.getByRole('radio', { name: '역할 제거', exact: true }).click();
    await page.locator('#bulk_apply_button').click();
    await expect(page.getByRole('dialog')).toContainText('제거합니다');
    await page.getByRole('dialog').getByRole('button', { name: '적용', exact: true }).click();
    await expect(page.getByRole('dialog')).toBeHidden({ timeout: 15_000 });
    expect(removeBodies).toEqual([{ menu_ids: [target.id], role_ids: [roleId], mode: 'remove' }]);

    await expect
      .poll(
        () =>
          page.evaluate((name) => {
            const rows = [...document.querySelectorAll('[data-testid="menu-item-roles"]')];
            const row = rows.find((r) => r.parentElement?.querySelector('span')?.textContent?.trim() === name);
            return row?.getAttribute('title') ?? '';
          }, target.name.ko),
        { timeout: 15_000 },
      )
      .not.toContain(roleName);
    const after = await api('GET', `/api/admin/roles/${roleId}`);
    expect(after.json.data.menu_ids).not.toContain(target.id);
  });
});
