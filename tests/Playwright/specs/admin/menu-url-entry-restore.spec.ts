/**
 * 메뉴 관리 — URL 직접 진입 시 선택/편집 상태 복원 (#518 / 공개 #76)
 *
 * 결함: `initMenuFromUrl` 이 목록 데이터소스에서 행 배열을 꺼낼 때 `dataSource.data` 가 배열이라고
 *   가정했는데, 실제 형태는 응답 envelope 를 그대로 담은 `{success, message, data:{data:[...]}}` 다.
 *   그래서 이 핸들러는 3초를 대기하다 조용히 종료했고, `?menu=...&mode=edit` 로 들어와도 패널이
 *   열리지 않았다. 예외도 콘솔 경고도 없어 화면만 비어 있었다.
 *
 * 하위 메뉴는 목록 응답이 역할을 싣지 않으므로(#76 프루닝), 이 경로가 죽어 있으면 URL 로 들어와
 * 저장할 때 역할이 통째로 해제될 수 있다 — 그래서 브라우저에서 고정한다.
 *
 * @scenario surface=admin_menu_list,entry=url_direct
 * @effects menu_url_entry_restores_selection, menu_url_entry_seeds_roles
 */
import { test, expect, authenticatePage, issueToken } from '../../fixtures/auth';

const MENU_API = '/api/admin/menus';

test.describe('메뉴 관리 URL 직접 진입 복원 (#76)', () => {
  test('하위 메뉴 slug 로 들어오면 편집 패널이 열리고 역할이 폼에 시드된다', async ({ page }) => {
    const token = issueToken('core.menus.read', 'core.menus.update');
    await authenticatePage(page, token);

    // 역할이 걸린 하위 메뉴를 찾는다 (단건 조회가 역할을 공급하는지까지 봐야 하므로 필수 조건).
    await page.goto('/admin/menus');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const target = await page.evaluate(async (api) => {
      const res = await fetch(`${api}?with_children=true&hierarchical=true`, {
        headers: {
          Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
          Accept: 'application/json',
        },
      });
      const body = await res.json();
      const parents = body?.data?.data ?? [];

      for (const parent of parents) {
        for (const child of parent.children ?? []) {
          const detailRes = await fetch(`${api}/${child.id}`, {
            headers: {
              Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
              Accept: 'application/json',
            },
          });
          const detail = await detailRes.json();
          const roleIds = (detail?.data?.roles ?? []).map((role: any) => role.id);
          if (roleIds.length > 0) {
            return { slug: child.slug, id: child.id, roleIds };
          }
        }
      }

      return null;
    }, MENU_API);

    test.skip(!target, '역할이 걸린 하위 메뉴가 없다');

    await page.goto(`/admin/menus?menu=${encodeURIComponent(target!.slug)}&mode=edit`);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 존재를 먼저 확정한 뒤 값을 단언한다.
    await expect
      .poll(
        async () => page.evaluate(() => (window as any).G7Core?.state?.get?.()?.panelMode ?? null),
        { timeout: 20_000 },
      )
      .toBe('edit');

    const state = await page.evaluate(() => {
      const st = (window as any).G7Core?.state?.get?.() ?? {};
      return {
        selectedId: st.selectedMenu?.id ?? null,
        formRoles: st.formData?.roles ?? null,
      };
    });

    expect(state.selectedId).toBe(target!.id);
    // 목록 응답에는 하위 메뉴의 역할이 없다 — 단건 보강이 동작해야만 채워진다.
    expect(state.formRoles).toEqual(target!.roleIds);
  });

  test('편집 패널의 접근 역할 칩이 화면에 렌더된다', async ({ page }) => {
    const token = issueToken('core.menus.read', 'core.menus.update');
    await authenticatePage(page, token);

    await page.goto('/admin/menus');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const target = await page.evaluate(async (api) => {
      const res = await fetch(`${api}?with_children=true&hierarchical=true`, {
        headers: {
          Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
          Accept: 'application/json',
        },
      });
      const body = await res.json();
      for (const parent of body?.data?.data ?? []) {
        for (const child of parent.children ?? []) {
          const detailRes = await fetch(`${api}/${child.id}`, {
            headers: {
              Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
              Accept: 'application/json',
            },
          });
          const detail = await detailRes.json();
          const roles = detail?.data?.roles ?? [];
          if (roles.length > 0) {
            return { slug: child.slug, roleName: roles[0]?.name?.ko ?? roles[0]?.name };
          }
        }
      }
      return null;
    }, MENU_API);

    test.skip(!target, '역할이 걸린 하위 메뉴가 없다');

    await page.goto(`/admin/menus?menu=${encodeURIComponent(target!.slug)}&mode=edit`);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect(page.getByText('접근 역할')).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText(String(target!.roleName), { exact: true }).first()).toBeVisible({
      timeout: 20_000,
    });
  });
});
