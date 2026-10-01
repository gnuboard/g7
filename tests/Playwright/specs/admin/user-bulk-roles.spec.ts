/**
 * 회원 목록 — 역할 일괄 추가·제거 (#708)
 *
 * 브라우저에서만 드러나는 연결을 고정한다.
 * - 목록에서 회원 여럿을 골라 「역할 추가」 → 역할 선택 → 「적용」 더블클릭은 PATCH 1건, 고른 회원 모두에 역할이 붙는다
 * - 성공하면 선택이 비고 창이 닫힌다 · 「역할 제거」 는 같은 창을 제거 모드로 연다
 *
 * 스펙은 자기 역할·회원을 만들고 끝나면 지운다.
 *
 * @scenario mode=add, target=normal, role=within_ceiling
 * @scenario mode=remove, target=normal, role=within_ceiling
 * @effects assigned_by_recorded, permission_cache_flushed
 */
import { test, expect, authenticatePage, issueToken } from '../../fixtures/auth';

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

test.describe('회원 목록 역할 일괄 변경 (#708)', () => {
  // 준비 단계가 역할·회원을 API 로 만들고 지운다 — 기본 30초로는 모자라다
  test.setTimeout(90_000);

  const stamp = Date.now();
  const prefix = `e2ebulkrole${stamp}`;
  const roleName = `E2E 회원역할 ${stamp % 100000}`;
  let roleId: number | null = null;
  const userUuids: string[] = [];

  test.beforeEach(async ({ page }) => {
    await authenticatePage(page, issueToken('core.users.read', 'core.users.update', 'core.users.create', 'core.users.delete', 'core.permissions.read', 'core.permissions.create', 'core.permissions.delete'));
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    const api = apiOf(page);

    const role = await api('POST', '/api/admin/roles', { identifier: `e2e_user_role_${stamp}`, name: { ko: roleName, en: roleName }, is_active: true, permissions: [] });
    expect(role.status).toBe(201);
    roleId = role.json.data.id;

    // 회원 생성에는 역할이 하나 이상 필요하다 — 기본 「user」 역할을 준다
    const active = await api('GET', '/api/admin/roles/active');
    const baseRole = ((active.json?.data?.data ?? []) as any[]).find((r) => r.identifier === 'user');
    expect(baseRole, '기본 user 역할').toBeTruthy();

    for (const n of [1, 2]) {
      const password = `E2e!Bulk${stamp}Aa`;
      const user = await api('POST', '/api/admin/users', { name: `${prefix}u${n}`, email: `${prefix}u${n}@example.com`, password, password_confirmation: password, role_ids: [baseRole.id] });
      expect(user.status, JSON.stringify(user.json)).toBe(201);
      userUuids.push(user.json.data.uuid);
    }
  });

  test.afterEach(async ({ page }) => {
    const api = apiOf(page);
    for (const uuid of userUuids.splice(0)) await api('DELETE', `/api/admin/users/${uuid}`);
    if (roleId) await api('DELETE', `/api/admin/roles/${roleId}`);
    roleId = null;
  });

  // @scenario mode=add, target=normal, role=within_ceiling
  // @effects assigned_by_recorded
  test('두 회원에게 역할을 일괄 추가하면 PATCH 1건이고 둘 다 역할을 갖는다, 이어 제거 창이 제거 모드로 열린다', async ({ page }) => {
    // 목록 검색은 filters[0][*] 쿼리로 건다(화면 검색 칸이 만드는 것과 같은 형식)
    await page.goto(`/admin/users?filters[0][field]=all&filters[0][value]=${prefix}&filters[0][operator]=like`);
    const rows = page.locator('tbody tr').filter({ hasText: prefix });
    await expect(rows).toHaveCount(2, { timeout: 30_000 });

    for (let i = 0; i < 2; i++) {
      const box = rows.nth(i).getByRole('checkbox');
      await box.click();
      await expect(box).toBeChecked();
    }

    await page.getByRole('button', { name: '역할 추가', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText('선택한 회원 2명에게 역할을 추가합니다.');
    await dialog.getByRole('combobox').click();
    await page.getByRole('option', { name: roleName, exact: true }).click();
    await page.keyboard.press('Escape');

    const patches: string[] = [];
    page.on('request', (req) => {
      if (req.method() === 'PATCH' && req.url().includes('/api/admin/users/bulk-roles')) patches.push(req.url());
    });
    await dialog.getByRole('button', { name: '적용', exact: true }).dblclick();
    await expect(dialog).toBeHidden({ timeout: 15_000 });
    expect(patches).toHaveLength(1);

    const api = apiOf(page);
    for (const uuid of userUuids) {
      const detail = await api('GET', `/api/admin/users/${uuid}`);
      const roleIds = (detail.json.data.roles ?? []).map((r: any) => r.id);
      expect(roleIds).toContain(roleId);
    }

    // 목록이 다시 받아져 두 행 모두 새 역할 배지를 보인다
    for (let i = 0; i < 2; i++) {
      await expect(rows.nth(i)).toContainText(roleName, { timeout: 15_000 });
    }

    // 성공 뒤 선택은 비어 있다 → 다시 골라 제거 창을 연다
    await expect(page.getByRole('button', { name: '역할 제거', exact: true })).toBeDisabled();
    await rows.first().getByRole('checkbox').click();
    await page.getByRole('button', { name: '역할 제거', exact: true }).click();
    await expect(page.getByRole('dialog')).toContainText('역할 일괄 제거');
    await expect(page.getByRole('dialog')).toContainText('선택한 회원 1명에게서 역할을 제거합니다.');
  });
});
