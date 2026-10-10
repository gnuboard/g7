/**
 * E2E: 본인만 보도록 제한된 위임 관리자의 보조 경로 스코프 (KVE-2026-2368 · KVE-2026-2374)
 *
 * @scenario case=e2e_manager_user_paths_scoped, case=e2e_manager_notifications_scoped, case=e2e_super_admin_unscoped
 * @effects e2e_scoped_manager_sees_only_self_on_every_user_path,
 *          e2e_scoped_manager_sees_only_party_notifications,
 *          e2e_super_admin_sees_all_users
 *
 * 회원 조회·발송 이력 조회를 self 범위로만 가진 계정(`식별자:self` 토큰)으로 주 경로(회원 목록)와
 * 보조 경로(최근 가입·uuid 검색·통계·대시보드 회원 수·대시보드 최근 알림)를 실제 서버에 요청해,
 * 보조 경로가 주 경로와 같은 범위(본인 1명·당사자 알림)를 따르는지 확인한다. 수정 전에는 최근 가입이
 * 전체 회원 10명과 그 개인정보를, 통계·대시보드가 전체 회원 수를, 대시보드 최근 알림이 다른 회원의
 * 비밀번호 재설정 안내를 내보냈다. 전체 권한 관리자는 같은 경로에서 전체를 그대로 본다.
 */
import { test, expect, issueToken, issueScopedToken, authenticatePage } from '../../fixtures/auth';
import type { APIRequestContext } from '@playwright/test';

/**
 * Bearer 토큰으로 관리자 API 를 GET 하고 JSON 을 돌려준다.
 *
 * @param request Playwright API 컨텍스트
 * @param token Sanctum 토큰
 * @param url API 경로
 * @returns 상태 코드와 JSON 본문
 */
async function getJson(request: APIRequestContext, token: string, url: string): Promise<{ status: number; body: any }> {
  const res = await request.get(url, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Accept-Language': 'ko' },
  });

  return { status: res.status(), body: await res.json() };
}

test.describe('위임 관리자 self 스코프 — 보조 경로가 주 경로와 같은 범위를 따른다', () => {
  // @scenario case=e2e_manager_user_paths_scoped
  // @effects e2e_scoped_manager_sees_only_self_on_every_user_path
  test('회원 조회 보조 경로(최근 가입·uuid 검색·통계·대시보드 회원 수)가 본인 1명만 준다', async ({ request }) => {
    const managerToken = issueScopedToken('core.users.read:self', 'core.dashboard.read');
    const superToken = issueToken();

    const me = await getJson(request, managerToken, '/api/admin/auth/user');
    expect(me.status).toBe(200);
    const myEmail: string = me.body?.data?.email;

    // 대조군 — 주 경로는 본인 1명
    const list = await getJson(request, managerToken, '/api/admin/users?per_page=100');
    expect(list.status).toBe(200);
    expect((list.body?.data?.data ?? []).map((u: any) => u.email)).toEqual([myEmail]);

    const recent = await getJson(request, managerToken, '/api/admin/users/recent');
    expect(recent.status).toBe(200);
    expect((recent.body?.data ?? []).map((u: any) => u.email)).toEqual([myEmail]);

    // 다른 회원의 uuid — 전체 권한 관리자가 본 최근 가입에서 본인이 아닌 회원을 고른다
    const superRecent = await getJson(request, superToken, '/api/admin/users/recent');
    const other = (superRecent.body?.data ?? []).find((u: any) => u.email !== myEmail);
    expect(other, '비교할 다른 회원이 있어야 한다').toBeTruthy();

    const search = await getJson(request, managerToken, `/api/admin/users/search?uuid=${other.uuid}`);
    expect(search.status).toBe(200);
    expect(search.body?.data ?? []).toHaveLength(0);

    const stats = await getJson(request, managerToken, '/api/admin/users/statistics');
    expect(stats.status).toBe(200);
    expect(stats.body?.data?.total_users).toBe(1);

    const dashboard = await getJson(request, managerToken, '/api/admin/dashboard/stats');
    expect(dashboard.status).toBe(200);
    expect(dashboard.body?.data?.total_users?.count).toBe(1);
  });

  // @scenario case=e2e_manager_notifications_scoped
  // @effects e2e_scoped_manager_sees_only_party_notifications
  test('대시보드 최근 알림이 발송 이력 목록과 같은 범위(당사자 알림만)를 준다', async ({ request }) => {
    const managerToken = issueScopedToken('core.notification-logs.read:self');

    const list = await getJson(request, managerToken, '/api/admin/notification-logs?per_page=100');
    expect(list.status).toBe(200);

    // 방금 만든 계정은 보내거나 받은 알림이 없다 — 주 경로와 보조 경로 모두 0건이어야 한다
    const recent = await getJson(request, managerToken, '/api/admin/dashboard/recent-notifications');
    expect(recent.status).toBe(200);
    expect(recent.body?.data ?? []).toHaveLength(0);
  });

  // @scenario case=e2e_super_admin_unscoped
  // @effects e2e_super_admin_sees_all_users
  test('전체 권한 관리자는 같은 경로에서 전체 회원을 그대로 본다', async ({ request }) => {
    const superToken = issueToken();

    const list = await getJson(request, superToken, '/api/admin/users?per_page=1');
    const stats = await getJson(request, superToken, '/api/admin/users/statistics');
    expect(stats.status).toBe(200);
    expect(stats.body?.data?.total_users).toBeGreaterThan(1);
    expect(stats.body?.data?.total_users).toBe(list.body?.data?.statistics?.total_users);
  });

  // @scenario case=e2e_manager_user_paths_scoped
  // @effects e2e_scoped_manager_sees_only_self_on_every_user_path
  test('회원 관리 화면이 본인 1명만 그린다', async ({ page }) => {
    const managerToken = issueScopedToken('core.users.read:self', 'core.menus.read');
    await authenticatePage(page, managerToken);

    await page.goto('/admin/users');
    await expect(page.getByText('총 1개')).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('tbody tr')).toHaveCount(1);
  });
});
