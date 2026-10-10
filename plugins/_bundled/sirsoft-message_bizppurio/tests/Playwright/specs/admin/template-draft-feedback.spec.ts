/**
 * E2E: 알림톡 템플릿 초안 저장(편집 창 하단 「저장」), 검수 신청 실패 안내, 삭제 완료 안내, 플러그인 정보 창의 제공 훅
 *
 * 검증 대상(브라우저에서만 잡히는 축):
 *  1. 알림 설정 [편집] 창 하단 「저장」이 카테고리·템플릿명이 빈 알림톡 섹션을 초안으로 남긴다(draft 표시)
 *  2. 같은 창 「저장 후 검수 신청」은 빠진 필수값을 항목별 목록으로 보여 준다
 *  3. 「알림 템플릿 관리」 탭 목록의 [검수 신청] 실패 안내가 빠진 항목을 모두 잇는다(templateManageFeedback 의 대상)
 *  4. 카카오에 등록되지 않은 행의 삭제 완료 안내는 상태 제약 문구가 아니다(templateManageFeedback 의 대상)
 *  5. 확장 관리 > 플러그인 정보 창 「훅」 칸에 이 플러그인이 제공하는 잔액 부족 훅이 보인다
 *
 * 카카오 검수·분류 조회 같은 외부 호출은 서버에서 나가므로 이 spec 은 그 성공 경로를 다루지 않는다. 검수 신청은
 * 서버 검증(422)에서 끝나 외부로 나가지 않고, 삭제 대상은 카카오 코드가 없는 초안이라 외부 호출 없이 지워진다.
 * 대상 알림(휴면 전환 완료)에 이미 비즈뿌리오 행이 있으면 운영 데이터를 건드리지 않도록 건너뛴다.
 */
import { test, expect, authenticatePage } from '../../fixtures/bizppurio-auth';

const SETTINGS_URL = '/admin/settings?tab=notification_definitions';
const MANAGE_URL = '/admin/plugins/sirsoft-message_bizppurio/settings?tab=templates';
const API = '/api/plugins/sirsoft-message_bizppurio/admin';
const TYPE = 'dormant_converted';
const TITLE = '휴면 전환 완료';

/**
 * 대상 알림의 비즈뿌리오 행을 찾는다.
 *
 * @param request Playwright 요청 컨텍스트
 * @param token 관리자 토큰
 * @returns 행 요약 또는 null
 */
async function findRow(request: import('@playwright/test').APIRequestContext, token: string) {
  const res = await request.get(`${API}/templates/map`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
  const body = await res.json();
  return body?.data?.templates?.[TYPE] ?? null;
}

test.describe('알림톡 템플릿 초안·검수 신청·삭제 안내', () => {
  // @scenario surface=core_edit_modal, transition=draft_save
  // @scenario surface=core_edit_modal, transition=draft_to_requested
  // @scenario surface=plugin_settings_row
  // @scenario surface=plugin_settings_delete
  // @effects edit_modal_save_keeps_alimtalk_as_draft, edit_modal_request_validates_fully, inspection_errors_listed_per_field, delete_toast_follows_kakao_skip_reason
  test('[편집] 창 하단 저장은 초안으로 남기고, 검수 신청과 목록 [검수 신청]은 빠진 항목을 모두 알리며, 미등록 행 삭제는 상태 제약 문구가 아니다', async ({ page, messagingManageToken }) => {
    // 편집 창을 두 번 열고 관리 탭까지 오가는 긴 흐름이라 기본 30초로는 모자란다
    test.setTimeout(180_000);
    const existing = await findRow(page.request, messagingManageToken);
    test.skip(!!existing?.id, `대상 알림(${TYPE})에 이미 비즈뿌리오 행이 있어 운영 데이터를 건드리지 않는다`);

    try {
      await authenticatePage(page, messagingManageToken);
      await page.goto(SETTINGS_URL);
      await page.waitForLoadState('networkidle', { timeout: 30_000 });
      await page.locator('#channel_sub_tabs button', { hasText: '비즈뿌리오' }).first().click();
      await page.waitForLoadState('networkidle', { timeout: 30_000 });

      const openEdit = async () => {
        const card = page.locator('div', { has: page.locator('h3,h4', { hasText: new RegExp(`^${TITLE}$`) }) })
          .filter({ has: page.getByRole('button', { name: '편집', exact: true }) }).last();
        await card.getByRole('button', { name: '편집', exact: true }).first().click();
        const dialog = page.getByRole('dialog');
        await expect(dialog.locator('#bizppurio_tpl_sections')).toBeVisible({ timeout: 15_000 });
        return dialog;
      };

      // 1) 템플릿명을 비우고(카테고리도 빈칸) 하단 「저장」 → draft 표시를 실은 생성 요청 201
      let dialog = await openEdit();
      await dialog.locator('input[name="bz_template_name"]').fill('');
      const saveRequest = page.waitForRequest((r) => r.method() === 'POST' && r.url().endsWith(`${API}/templates`));
      const saveResponse = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith(`${API}/templates`));
      await dialog.getByRole('button', { name: '저장', exact: true }).click();
      expect(JSON.parse((await saveRequest).postData() ?? '{}').draft).toBe(true);
      expect((await saveResponse).status()).toBe(201);
      await expect(page.getByRole('dialog')).toHaveCount(0, { timeout: 15_000 });

      // 2) 다시 [편집] → 「저장 후 검수 신청」 → 422, 창에 빠진 항목 2줄
      dialog = await openEdit();
      const requestResponse = page.waitForResponse((r) => r.request().method() === 'PUT' && /\/admin\/templates\/\d+$/.test(r.url()));
      await dialog.getByRole('button', { name: '저장 후 검수 신청', exact: true }).click();
      expect((await requestResponse).status()).toBe(422);
      await expect(dialog.locator('li', { hasText: '템플릿명' })).toHaveCount(1);
      await expect(dialog.locator('li', { hasText: '카테고리' })).toHaveCount(1);
      await dialog.getByRole('button', { name: '취소', exact: true }).click();

      // 3) 관리 탭 목록 [검수 신청] → 422, 안내가 두 항목을 모두 잇는다
      await page.goto(MANAGE_URL);
      await page.waitForLoadState('networkidle', { timeout: 30_000 });
      const row = page.locator('div', { hasText: TITLE }).filter({ has: page.getByRole('button', { name: '검수 신청', exact: true }) }).last();
      const inspection = page.waitForResponse((r) => r.request().method() === 'POST' && /\/admin\/templates\/\d+\/request$/.test(r.url()));
      await row.getByRole('button', { name: '검수 신청', exact: true }).click();
      expect((await inspection).status()).toBe(422);
      const toast = page.getByText(/템플릿명 필드는 필수입니다[\s\S]*카테고리 필드는 필수입니다/).first();
      await expect(toast).toBeVisible({ timeout: 10_000 });

      // 4) [삭제] → 확인 → 200 not_registered → 상태 제약 문구가 아닌 완료 안내
      const deleteRow = page.locator('div', { hasText: TITLE }).filter({ has: page.getByRole('button', { name: '삭제', exact: true }) }).last();
      await deleteRow.getByRole('button', { name: '삭제', exact: true }).click();
      const confirmDialog = page.getByRole('dialog');
      const deletion = page.waitForResponse((r) => r.request().method() === 'DELETE' && /\/admin\/templates\/\d+$/.test(r.url()));
      await confirmDialog.getByRole('button', { name: '삭제', exact: true }).click();
      const deleted = await deletion;
      expect(deleted.status()).toBe(200);
      expect((await deleted.json()).data?.kakao_skip_reason).toBe('not_registered');
      await expect(page.getByText('템플릿 설정을 삭제했습니다.', { exact: true }).first()).toBeVisible({ timeout: 10_000 });
      await expect(page.getByText('상태 제약으로 남아 있습니다')).toHaveCount(0);
    } finally {
      // 중간에 실패해도 이 spec 이 만든 초안은 남기지 않는다
      const left = await findRow(page.request, messagingManageToken);
      if (left?.id && !existing?.id) {
        await page.request.delete(`${API}/templates/${left.id}`, { headers: { Authorization: `Bearer ${messagingManageToken}`, Accept: 'application/json' } });
      }
    }
  });

  // @scenario surface=extension_detail_hooks
  // @effects published_hooks_are_declared
  test('플러그인 정보 창 「훅」 칸에 제공하는 잔액 부족 훅과 칸 설명이 보인다', async ({ page, messagingManageToken }) => {
    await authenticatePage(page, messagingManageToken);
    await page.goto('/admin/plugins');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const icon = page.locator('img[src*="/plugin/sirsoft-message_bizppurio/icon"]').first();
    await icon.scrollIntoViewIfNeeded();
    await icon.click();
    const dialog = page.getByRole('dialog');
    const hooks = dialog.locator('[data-component-id="hooks_section"], #hooks_section').first();
    await expect(hooks).toBeVisible({ timeout: 15_000 });
    await expect(hooks).toContainText('이 플러그인이 다른 확장에 제공하는 액션 훅 목록입니다.');
    await expect(hooks).toContainText('sirsoft-message_bizppurio.balance.low');
    await expect(hooks).not.toContainText('제공하는 훅이 없습니다.');
  });
});
