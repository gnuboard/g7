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

  // 키보드 사용자는 역할 칸에 검색어를 치고 Enter 로 고른다. 태그 입력이 비제어 입력값(늘 빈 값)을 보고
  // Enter 를 막아 선택이 되지 않던 결함을 고정한다. 적용 직후 선택이 비면서 창 머리 문구가 「0명에게…」 로
  // 바뀌어 비치던 것도 함께 막는다 — 창이 닫히기 전 어느 순간에도 그 문구가 나오면 안 된다.
  // @scenario mode=add, target=normal, role=within_ceiling
  // @effects bulk_role_keyboard_enter_selects_role, bulk_role_result_hides_zero_count_desc
  test('역할 칸에 검색어를 치고 Enter 로 고를 수 있고, 적용 직후 창에 「0명」 안내가 비치지 않는다', async ({ page }) => {
    await page.goto(`/admin/users?filters[0][field]=all&filters[0][value]=${prefix}&filters[0][operator]=like`);
    const rows = page.locator('tbody tr').filter({ hasText: prefix });
    await expect(rows).toHaveCount(2, { timeout: 30_000 });
    for (let i = 0; i < 2; i++) await rows.nth(i).getByRole('checkbox').click();

    await page.getByRole('button', { name: '역할 추가', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText('선택한 회원 2명에게 역할을 추가합니다.');

    const combo = dialog.getByRole('combobox');
    await combo.click();
    await combo.pressSequentially(`E2E 회원역할 ${stamp % 100000}`);
    await expect(page.getByRole('option', { name: roleName, exact: true })).toBeVisible();
    await page.keyboard.press('Enter');
    await expect(dialog.locator('.tag-input__multi-value__label')).toHaveText([roleName]);

    // 창이 열린 동안 「0명」 문구가 한 번이라도 비치는지 지켜본다
    await page.evaluate(() => {
      (window as any).__zeroDescSeen = false;
      const mo = new MutationObserver(() => {
        const d = document.querySelector('[role="dialog"]');
        if (d && /0명에게/.test(d.textContent ?? '')) (window as any).__zeroDescSeen = true;
      });
      mo.observe(document.body, { subtree: true, childList: true, characterData: true });
    });
    // 메뉴를 닫으려고 창 제목을 누른다 — 제목은 머리글 태그가 아니라 `.modal-title` 이다
    // (id 는 창마다 달라 `modal-title-…` 꼴 — 겹친 창 이름 충돌 수정 #736)
    await dialog.locator('.modal-title').click();
    await dialog.getByRole('button', { name: '적용', exact: true }).click();
    await expect(dialog).toBeHidden({ timeout: 15_000 });
    expect(await page.evaluate(() => (window as any).__zeroDescSeen)).toBe(false);

    const api = apiOf(page);
    for (const uuid of userUuids) {
      const detail = await api('GET', `/api/admin/users/${uuid}`);
      expect((detail.json.data.roles ?? []).map((r: any) => r.id)).toContain(roleId);
    }
  });

  // 모바일 「일괄 작업 선택」 은 상태 4종과 같은 잠금 규칙을 역할 항목에도 건다 — 고른 회원이 없을 때
  // 눌리면 빈 대상(uuids=[])으로 요청해 422 로 끝난다.
  // @scenario mode=add, target=normal, role=within_ceiling
  // @effects bulk_role_entry_from_pc_and_mobile, bulk_role_entry_locked_without_selection
  test('모바일 「일괄 작업 선택」 의 역할 추가·제거는 고른 회원이 없으면 잠기고, 고르면 추가 창을 연다', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/admin/users?filters[0][field]=all&filters[0][value]=${prefix}&filters[0][operator]=like`);
    await expect(page.getByText(`${prefix}u1`).first()).toBeVisible({ timeout: 30_000 });

    const trigger = page.getByRole('button', { name: '일괄 작업 선택' });
    await trigger.click();
    for (const name of ['역할 추가', '역할 제거']) {
      await expect(page.getByRole('menuitem', { name, exact: true })).toHaveAttribute('aria-disabled', 'true');
    }
    await page.keyboard.press('Escape');

    // 모바일은 행이 카드로 그려진다 — 필터 영역에도 체크박스(상태 전체 등)가 있으므로 그 회원 이름이 든
    // 가장 안쪽 카드의 체크박스를 고른다(조상 → 자손 순이라 마지막 일치가 카드).
    const card = page.locator('div').filter({ hasText: `${prefix}u1` }).filter({ has: page.getByRole('checkbox') }).last();
    const box = card.getByRole('checkbox');
    await box.click();
    await expect(box).toBeChecked();
    await expect(page.getByText('1개 선택됨').first()).toBeVisible();

    await trigger.click();
    const addItem = page.getByRole('menuitem', { name: '역할 추가', exact: true });
    await expect(addItem).not.toHaveAttribute('aria-disabled', 'true');
    await addItem.click();
    await expect(page.getByRole('dialog')).toContainText('선택한 회원 1명에게 역할을 추가합니다.');
  });
});
