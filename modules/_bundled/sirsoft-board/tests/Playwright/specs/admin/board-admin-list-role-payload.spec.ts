/**
 * 관리자 게시판 목록 역할 페이로드 프루닝 — 목록이 역할·소속 회원 명단을 싣지 않는지 (#518 / 공개 #76).
 *
 * 결함: 목록 Collection 이 행마다 `Role::whereIn()->with('users')` 를 돌려 N+1 을 만들고,
 *   매니저·스텝 역할에 소속된 **모든 사용자의 uuid·이름·이메일**을 응답에 실었다. 화면은
 *   그 값을 그리지 않는다 — 게시판 이름/식별자/유형/활성여부/분류만 그린다.
 *
 * 이 spec 이 브라우저 수준을 담당하는 이유: 서버 단언(BoardManagementTest)은 응답 형태만 본다.
 *   목록 표현을 경량 경로로 갈아끼우면서 화면이 쓰던 필드를 함께 떨구면 열이 조용히 비는데,
 *   그건 응답 단언으로는 드러나지 않는다. 여기서는 실제 렌더된 행을 확인한다.
 *
 * 시드 의존: 샘플 시드의 게시판 목록(최소 1건).
 *
 * @scenario board-admin-list-role-payload-pruning
 * @axes endpoint=admin_list endpoint=detail
 * @effects admin_list_omits_role_member_payload admin_list_never_exposes_member_email admin_list_keeps_display_fields detail_still_provides_role_assignments
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const LIST_URL = '/admin/boards';
const LIST_API = /\/api\/modules\/sirsoft-board\/admin\/boards(\?|$)/;

/** 목록에서 빠져야 하는 역할 페이로드 키 */
const ROLE_KEYS = [
  'board_managers',
  'board_steps',
  'board_manager_ids',
  'board_step_ids',
];

test.describe('관리자 게시판 목록 역할 페이로드 (#518)', () => {
  // @scenario endpoint=admin_list
  // @effects admin_list_omits_role_member_payload
  test('목록 응답에 역할 배열과 역할 ID 목록이 없다', async ({ page, boardManageToken }) => {
    await authenticatePage(page, boardManageToken);

    const listResponse = page.waitForResponse(
      (res) => LIST_API.test(res.url()) && res.request().method() === 'GET',
      { timeout: 30_000 },
    );

    await page.goto(LIST_URL);
    const payload = await (await listResponse).json();

    const rows = payload?.data?.data ?? [];
    expect(rows.length, '게시판이 최소 1건은 있어야 판정할 수 있다').toBeGreaterThan(0);

    for (const row of rows) {
      for (const key of ROLE_KEYS) {
        expect(row, `목록 행에 ${key} 가 실리면 안 된다`).not.toHaveProperty(key);
      }
    }
  });

  // @scenario endpoint=admin_list
  // @effects admin_list_never_exposes_member_email
  test('목록 응답 본문에 회원 이메일이 등장하지 않는다', async ({ page, boardManageToken }) => {
    await authenticatePage(page, boardManageToken);

    const listResponse = page.waitForResponse(
      (res) => LIST_API.test(res.url()) && res.request().method() === 'GET',
      { timeout: 30_000 },
    );

    await page.goto(LIST_URL);
    const body = await (await listResponse).text();

    // 키 이름이 아니라 본문 전체를 본다 — 역할 페이로드가 다른 키 이름으로 되살아나도 잡힌다.
    expect(body, '게시판 목록에 회원 이메일이 실리면 안 된다').not.toMatch(
      /"email"\s*:\s*"[^"]+@/,
    );
    expect(body, '게시판 목록에 회원 uuid 가 실리면 안 된다').not.toMatch(/"uuid"\s*:/);
  });

  // @scenario endpoint=admin_list
  // @effects admin_list_keeps_display_fields
  test('목록이 그리는 열(이름·식별자·유형·활성여부)이 그대로 렌더된다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);

    const listResponse = page.waitForResponse(
      (res) => LIST_API.test(res.url()) && res.request().method() === 'GET',
      { timeout: 30_000 },
    );

    await page.goto(LIST_URL);
    const payload = await (await listResponse).json();
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const first = payload?.data?.data?.[0];
    expect(first, '첫 행이 있어야 한다').toBeTruthy();

    // 응답 계약 — 화면이 순회하는 6필드
    for (const key of ['name', 'slug', 'type', 'is_active', 'categories', 'abilities']) {
      expect(first, `목록이 그리는 필드 ${key} 가 빠졌다`).toHaveProperty(key);
    }

    // 렌더 결과 — 존재를 먼저 확정한 뒤 값을 본다
    const rows = page.locator('tbody tr');
    await expect(rows.first()).toBeVisible({ timeout: 30_000 });

    const slug = String(first.slug);
    await expect(
      page.locator('tbody').getByText(slug, { exact: false }).first(),
      '식별자 열이 비면 경량 전환이 화면을 깎은 것이다',
    ).toBeVisible();
  });

  // @scenario endpoint=detail
  // @effects detail_still_provides_role_assignments
  test('단건 조회는 역할 정보를 그대로 제공한다 (대체 경로 보존)', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);

    const listResponse = page.waitForResponse(
      (res) => LIST_API.test(res.url()) && res.request().method() === 'GET',
      { timeout: 30_000 },
    );
    await page.goto(LIST_URL);
    const payload = await (await listResponse).json();
    const slug = payload?.data?.data?.[0]?.slug;
    expect(slug, '단건 조회 대상 slug 가 필요하다').toBeTruthy();

    // 인증은 Bearer 토큰이다 — 쿠키 세션이 아니므로 헤더를 명시해야 401 이 나지 않는다.
    const detail = await page.evaluate(
      async ({ boardSlug, token }) => {
        const res = await fetch(`/api/modules/sirsoft-board/admin/boards/${boardSlug}`, {
          headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
          },
        });

        return { status: res.status, body: await res.json() };
      },
      { boardSlug: slug, token: boardManageToken },
    );

    expect(detail.status).toBe(200);
    // 목록에서 뺀 값은 단건이 공급한다 — 없애는 것이 아니라 옮기는 것이다.
    expect(detail.body?.data).toHaveProperty('board_manager_ids');
    expect(detail.body?.data).toHaveProperty('board_step_ids');
  });
});
