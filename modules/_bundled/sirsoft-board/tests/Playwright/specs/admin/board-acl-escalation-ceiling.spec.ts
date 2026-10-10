/**
 * E2E: 게시판 권한(ACL)·담당자 지정의 권한 부여 상한 (KVE-2026-2367 + 담당자 경로)
 *
 * @scenario case=e2e_board_acl_self_grant_blocked, case=e2e_board_manager_self_assign_blocked, case=e2e_board_super_admin_allowed, case=e2e_default_permissions_self_grant_blocked, case=e2e_bulk_apply_rejection_keeps_modal
 * @effects e2e_delegate_acl_grant_rejected_and_unchanged,
 *          e2e_delegate_self_assign_rejected_and_unchanged,
 *          e2e_super_admin_acl_grant_applies,
 *          e2e_delegate_default_permissions_grant_rejected_and_unchanged,
 *          e2e_bulk_apply_rejection_shows_reason_and_keeps_targets
 *
 * 게시판 수정·생성 권한만 가진 위임 계정으로 실제 서버에 요청해, 게시판 관리(admin.manage)·비밀글
 * 열람 권한에 역할을 더 붙이는 저장, 자기를 담당자로 넣는 저장, 자기를 관리자로 넣는 게시판 생성이
 * 모두 상한 메시지와 함께 403 으로 끝나고 저장된 상태가 그대로인지 확인한다. 수정 전에는 셋 다
 * 200 으로 저장돼 위임 계정이 타인 글을 블라인드할 수 있었다. 권한을 바꾸지 않는 평소 저장과
 * 전체 권한 관리자의 같은 저장은 그대로 된다. 만든 게시판은 끝나면 지운다.
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';
import { issueToken, issueScopedToken } from '../../../../../../../tests/Playwright/fixtures/auth';
import type { APIRequestContext } from '@playwright/test';

const BOARDS = '/api/modules/sirsoft-board/admin/boards';
const CEILING_MESSAGE = '본인이 보유하지 않았거나 더 넓은 범위의 권한은 부여할 수 없습니다.';

/**
 * Bearer 토큰으로 관리자 API 를 호출한다.
 *
 * @param request Playwright API 컨텍스트
 * @param token Sanctum 토큰
 * @param method HTTP 메서드
 * @param url API 경로
 * @param data 요청 본문
 * @returns 상태 코드와 JSON 본문
 */
async function call(
  request: APIRequestContext,
  token: string,
  method: 'GET' | 'POST' | 'PUT' | 'DELETE',
  url: string,
  data?: Record<string, unknown>,
): Promise<{ status: number; body: any }> {
  const res = await request.fetch(url, {
    method,
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Accept-Language': 'ko' },
    data,
  });
  const text = await res.text();

  return { status: res.status(), body: text ? JSON.parse(text) : null };
}

/**
 * 게시판 상세 응답에서 권한 키의 역할 식별자 목록을 뽑는다.
 *
 * @param detail 게시판 상세 JSON
 * @param key 프론트 권한 키 (admin_manage 등)
 * @returns 역할 식별자 목록
 */
function roles(detail: any, key: string): string[] {
  return (detail?.data?.permissions?.[key]?.roles ?? []).map((r: any) => (typeof r === 'string' ? r : r.identifier));
}

test.describe('게시판 권한·담당자 저장의 권한 부여 상한', () => {
  // 한 게시판과 전역 설정(게시판 기본 권한)을 테스트들이 나눠 쓰므로 순서대로 돈다 — 병렬로 돌면
  // 한 테스트가 기본 권한에 넣은 역할을 다른 테스트가 「변경 없음」으로 읽어 결과가 흔들린다.
  test.describe.configure({ mode: 'serial' });

  const slug = `e2e-kve2367-${Date.now()}`;
  let superToken = '';
  let superUuid = '';
  let boardId = 0;

  test.beforeAll(async ({ request }) => {
    superToken = issueToken();
    superUuid = (await call(request, superToken, 'GET', '/api/admin/auth/user')).body?.data?.uuid;

    const created = await call(request, superToken, 'POST', BOARDS, {
      name: { ko: 'E2E 상한 게시판', en: 'E2E ceiling board' },
      slug,
      type: 'basic',
      description: { ko: 'E2E', en: 'E2E' },
      show_view_count: true,
      use_report: false,
      board_manager_ids: [superUuid],
    });
    expect(created.status, JSON.stringify(created.body)).toBe(201);
    boardId = created.body?.data?.id;
  });

  test.afterAll(async ({ request }) => {
    if (boardId) {
      await call(request, superToken, 'DELETE', `${BOARDS}/${boardId}`, { force_delete: true });
    }
  });

  // @scenario case=e2e_board_acl_self_grant_blocked
  // @effects e2e_delegate_acl_grant_rejected_and_unchanged
  test('위임 계정이 admin.manage·비밀글 열람에 역할을 더 붙이면 403 이고 같은 요청의 이름 변경도 저장되지 않는다', async ({ request }) => {
    const delegate = issueScopedToken('sirsoft-board.boards.read', 'sirsoft-board.boards.update');
    const before = await call(request, delegate, 'GET', `${BOARDS}/${boardId}`);
    expect(before.status).toBe(200);

    const put = await call(request, delegate, 'PUT', `${BOARDS}/${boardId}`, {
      name: { ko: '바뀐 이름', en: 'Renamed' },
      permissions: {
        admin_manage: { roles: [...roles(before.body, 'admin_manage'), 'manager'] },
        'posts_read-secret': { roles: [...roles(before.body, 'posts_read-secret'), 'manager'] },
      },
    });
    expect(put.status).toBe(403);
    expect(put.body?.message).toBe(CEILING_MESSAGE);

    const after = await call(request, delegate, 'GET', `${BOARDS}/${boardId}`);
    expect(roles(after.body, 'admin_manage')).toEqual(roles(before.body, 'admin_manage'));
    expect(roles(after.body, 'posts_read-secret')).toEqual(roles(before.body, 'posts_read-secret'));
    expect(after.body?.data?.name?.ko ?? after.body?.data?.name).not.toBe('바뀐 이름');

    // 과차단 회귀 축 — 권한을 그대로 다시 보내는 평소 저장은 된다
    const same = await call(request, delegate, 'PUT', `${BOARDS}/${boardId}`, {
      name: { ko: 'E2E 상한 게시판 저장', en: 'E2E ceiling board saved' },
      permissions: { admin_manage: { roles: roles(before.body, 'admin_manage') } },
    });
    expect(same.status).toBe(200);
  });

  // @scenario case=e2e_board_manager_self_assign_blocked
  // @effects e2e_delegate_self_assign_rejected_and_unchanged
  // 새 게시판에 생성자 본인 한 명만 관리자로 두는 것은 허용이다(K4F-2, 결정 2026-10-08) — 다른 회원을 함께 넣으면 403
  test('위임 계정이 기존 게시판 담당자에 자기를 넣거나, 다른 회원을 관리자로 게시판을 만들면 403 이다', async ({ request }) => {
    const delegate = issueScopedToken('sirsoft-board.boards.read', 'sirsoft-board.boards.update', 'sirsoft-board.boards.create');
    const delegateUuid = (await call(request, delegate, 'GET', '/api/admin/auth/user')).body?.data?.uuid;

    const assign = await call(request, delegate, 'PUT', `${BOARDS}/${boardId}`, {
      board_manager_ids: [superUuid, delegateUuid],
    });
    expect(assign.status).toBe(403);
    expect(assign.body?.message).toBe(CEILING_MESSAGE);

    const detail = await call(request, delegate, 'GET', `${BOARDS}/${boardId}`);
    expect(detail.body?.data?.board_manager_ids).toEqual([superUuid]);

    const createSlug = `${slug}-self`;
    const create = await call(request, delegate, 'POST', BOARDS, {
      name: { ko: 'E2E 자기지정', en: 'E2E self' },
      slug: createSlug,
      type: 'basic',
      description: { ko: 'E2E', en: 'E2E' },
      show_view_count: true,
      use_report: false,
      board_manager_ids: [delegateUuid, superUuid],
    });
    expect(create.status).toBe(403);
    expect(create.body?.message).toBe(CEILING_MESSAGE);

    const list = await call(request, superToken, 'GET', `${BOARDS}?per_page=100&search=${createSlug}`);
    expect(JSON.stringify(list.body)).not.toContain(createSlug);
  });

  // @scenario case=e2e_default_permissions_self_grant_blocked
  // @effects e2e_delegate_default_permissions_grant_rejected_and_unchanged
  test('설정 수정 권한만 가진 계정이 게시판 기본 권한의 게시판 관리에 역할을 넣으면 403 이고 저장된 기본값이 그대로다', async ({ request }) => {
    const delegate = issueScopedToken('sirsoft-board.settings.read', 'sirsoft-board.settings.update');
    const before = await call(request, superToken, 'GET', '/api/modules/sirsoft-board/admin/settings');
    expect(before.status).toBe(200);
    const basic = before.body?.data?.basic_defaults ?? {};
    const defaults = basic.default_board_permissions ?? {};
    // 이미 들어 있는 역할을 고르면 「변경 없음」 저장이 돼 검사가 헛돈다
    const extra = ['manager', 'user', 'guest'].find((r) => !(defaults['admin.manage'] ?? []).includes(r));

    // 카테고리 전체를 보낸다 — 일부만 보내면 저장이 통과했을 때 빠진 boolean 이 false 로 채워진다
    const put = await call(request, delegate, 'PUT', '/api/modules/sirsoft-board/admin/settings', {
      basic_defaults: {
        ...basic,
        default_board_permissions: { ...defaults, 'admin.manage': [...(defaults['admin.manage'] ?? []), extra] },
      },
    });
    expect(put.status).toBe(403);
    expect(put.body?.message).toBe(CEILING_MESSAGE);

    const after = await call(request, superToken, 'GET', '/api/modules/sirsoft-board/admin/settings');
    expect(after.body?.data?.basic_defaults?.default_board_permissions).toEqual(defaults);
  });

  // @scenario case=e2e_bulk_apply_rejection_keeps_modal
  // @effects e2e_bulk_apply_rejection_shows_reason_and_keeps_targets
  test('환경설정 일괄 적용이 상한에 걸리면 사유 토스트가 뜨고 확인 창은 시도한 항목·게시판을 그대로 보여 준다', async ({ page, request }) => {
    test.setTimeout(120_000);
    const delegate = issueScopedToken('sirsoft-board.settings.read', 'sirsoft-board.settings.update', 'sirsoft-board.boards.read');
    const settingsUrl = '/api/modules/sirsoft-board/admin/settings';
    const before = await call(request, superToken, 'GET', settingsUrl);
    const basic = before.body?.data?.basic_defaults;
    const boardName = (await call(request, superToken, 'GET', `${BOARDS}/${boardId}`)).body?.data?.name;
    const boardLabel = typeof boardName === 'string' ? boardName : boardName?.ko;

    // 전제 — 위임 계정이 이 게시판에서 갖지 않은 역할을 최고관리자가 기본 권한에 넣어 둔다.
    // 카테고리 전체를 다시 보내야 빠진 boolean 이 false 로 채워지지 않는다.
    const current = basic.default_board_permissions['admin.manage'] ?? [];
    const extra = ['manager', 'user', 'guest'].find((r) => !current.includes(r));
    const seeded = await call(request, superToken, 'PUT', settingsUrl, {
      basic_defaults: {
        ...basic,
        default_board_permissions: { ...basic.default_board_permissions, 'admin.manage': [...current, extra] },
      },
    });
    expect(seeded.status).toBe(200);

    try {
      await authenticatePage(page, delegate);
      await page.goto('/admin/boards/settings?tab=basic_defaults');
      await page.getByRole('checkbox', { name: /^게시판 관리 \(관리자\)/ }).check({ timeout: 60_000 });
      // 권한을 좁힌 계정이라 메뉴·알림 같은 공용 데이터 소스가 403 토스트를 띄운다 — 화면을 가리지 않게 닫는다
      for (const close of await page.getByRole('button', { name: '알림 닫기' }).all()) {
        await close.click().catch(() => {});
      }
      await page.getByRole('radio').last().click();
      await page.getByRole('combobox').last().click();
      await page.getByRole('option', { name: boardLabel }).click();
      await page.keyboard.press('Escape');
      await page.getByRole('button', { name: '일괄 적용', exact: true }).click();

      const modal = page.getByRole('dialog', { name: '일괄 적용 확인' });
      await expect(modal).toBeVisible();
      const bulk = page.waitForResponse((r) => r.url().includes('/admin/settings/bulk-apply'));
      await modal.getByRole('button', { name: '저장 및 적용' }).click();
      expect((await bulk).status()).toBe(403);

      await expect(page.getByRole('alert').filter({ hasText: CEILING_MESSAGE }).first()).toBeVisible();
      // 실패 뒤에도 창은 방금 시도한 항목·게시판을 보여 준다(수정 전에는 「0개 · 전체 게시판」으로 바뀌었다)
      await expect(modal).toContainText('게시판 관리 (관리자)');
      await expect(modal).toContainText(boardLabel);

      const after = await call(request, superToken, 'GET', `${BOARDS}/${boardId}`);
      expect(roles(after.body, 'admin_manage')).not.toContain(extra);
    } finally {
      const restored = await call(request, superToken, 'PUT', settingsUrl, { basic_defaults: basic });
      expect(restored.status).toBe(200);
    }
  });

  // @scenario case=e2e_board_super_admin_allowed
  // @effects e2e_super_admin_acl_grant_applies
  test('전체 권한 관리자는 같은 저장을 그대로 할 수 있다', async ({ request }) => {
    const before = await call(request, superToken, 'GET', `${BOARDS}/${boardId}`);
    const original = roles(before.body, 'admin_manage');

    const grant = await call(request, superToken, 'PUT', `${BOARDS}/${boardId}`, {
      permissions: { admin_manage: { roles: [...original, 'manager'] } },
    });
    expect(grant.status).toBe(200);
    expect(roles((await call(request, superToken, 'GET', `${BOARDS}/${boardId}`)).body, 'admin_manage')).toContain('manager');

    const revert = await call(request, superToken, 'PUT', `${BOARDS}/${boardId}`, {
      permissions: { admin_manage: { roles: original } },
    });
    expect(revert.status).toBe(200);
  });
});
