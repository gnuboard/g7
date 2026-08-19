/**
 * 관리자 회원 수정 폼 — 상태 '탈퇴' 저장 확인 절차 (공개이슈 #112)
 *
 * 서버에서 관리자 상태 변경 경로가 정식 탈퇴(비가역 익명화)로 통일되었으므로,
 * 이 폼의 저장이 경고 없이 회원 정보를 파기하지 않아야 한다.
 *
 * // @scenario entry_point=admin_form_ui, target_account=normal, confirm_action=cancel, confirm_action=submit
 * // @effects admin_form_shows_confirm_dialog_before_destructive_save, admin_form_cancel_leaves_user_untouched, admin_form_non_withdraw_status_saves_without_dialog
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const ADMIN_PERMISSIONS = ['core.users.read', 'core.users.update', 'core.users.create'];

/** 테스트용 회원을 생성하고 uuid 를 돌려준다. */
async function createTestUser(page: any, token: string, email: string): Promise<string> {
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };

  // 역할은 필수 입력이다. 관리자 역할을 붙이면 탈퇴 자체가 차단 대상이 되므로
  // 일반 사용자 역할을 고른다 (목록 첫 항목은 admin 이다).
  const rolesResponse = await page.request.get('/api/admin/roles/active', { headers });
  const roles = (await rolesResponse.json())?.data?.data ?? [];
  const roleId = (roles.find((role: any) => role.identifier === 'user') ?? roles[roles.length - 1])?.id;

  expect(roleId, '일반 사용자 역할을 찾지 못했습니다').toBeTruthy();

  const response = await page.request.post('/api/admin/users', {
    headers,
    data: {
      name: 'E2E 탈퇴확인',
      email,
      password: 'Password!234',
      password_confirmation: 'Password!234',
      status: 'active',
      role_ids: [roleId],
    },
  });

  expect(response.status(), `테스트 회원 생성 실패: ${await response.text()}`).toBeLessThan(300);

  const body = await response.json();

  return body?.data?.uuid ?? body?.data?.id;
}


/** 상태 드롭다운에서 라벨을 고르고, 선택이 폼에 반영될 때까지 기다린다. */
async function selectStatus(page: any, label: RegExp): Promise<void> {
  const statusSelect = page.locator('[name="status"][aria-haspopup], [name="status"]').first();
  await statusSelect.waitFor({ state: 'visible', timeout: 20_000 });

  // 폼이 API 응답으로 시드될 때까지 기다린다 — 시드 전에 저장하면 필수 항목이 빠져 422 가 된다.
  await expect(page.locator('[name="email"]')).not.toHaveValue('', { timeout: 20_000 });

  await statusSelect.click();
  await page.getByRole('option', { name: label }).first().click();
  await expect(statusSelect).toContainText(label, { timeout: 10_000 });
}

test('@smoke 관리자 회원 수정 폼: 상태 탈퇴 저장은 확인을 거치고, 취소하면 회원 정보가 그대로 남는다', async ({ page }) => {
  const token = issueToken(...ADMIN_PERMISSIONS);
  await authenticatePage(page, token);

  const email = `g7fix112.e2e.${Date.now()}@example.com`;
  const uuid = await createTestUser(page, token, email);

  await page.goto(`/admin/users/${uuid}/edit`);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  expect(page.url(), '로그인으로 리다이렉트되었습니다').not.toMatch(/\/admin\/login/);

  await selectStatus(page, /탈퇴|Withdraw/i);

  // 저장 → 확인 다이얼로그가 먼저 뜨고, 저장 요청은 나가지 않는다.
  let saveRequests = 0;
  page.on('request', (req) => {
    if (req.url().includes(`/api/admin/users/${uuid}`) && req.method() === 'PUT') {
      saveRequests += 1;
    }
  });

  await page.locator('#footer_save_button').click();

  const dialog = page.getByRole('dialog');
  await dialog.waitFor({ state: 'visible', timeout: 10_000 });

  // 문구가 비가역성과 파기 대상을 알린다
  await expect(dialog).toContainText(/되돌릴 수 없|cannot be undone/i);
  await expect(dialog).toContainText(/익명화|anonymiz/i);

  expect(saveRequests, '확인 전에 저장 요청이 나갔습니다').toBe(0);

  // 취소 → 회원 정보 불변
  await dialog.getByRole('button').first().click();
  await expect(page.getByRole('dialog')).toHaveCount(0, { timeout: 10_000 });
  expect(saveRequests, '취소했는데 저장 요청이 나갔습니다').toBe(0);

  const stillOriginal = await page.request.get(`/api/admin/users/${uuid}`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  const stillBody = await stillOriginal.json();
  expect(stillBody?.data?.email, '취소했는데 이메일이 바뀌었습니다').toBe(email);

  // 재시도 후 확인 → 저장되고 익명화가 반영된다
  // 다이얼로그 언마운트 직후의 재클릭은 낡은 폼 스냅샷으로 제출될 수 있어 렌더가 가라앉기를 기다린다
  await page.waitForTimeout(1500);
  await page.locator('#footer_save_button').click();
  await expect(page.getByRole('dialog')).toHaveCount(1, { timeout: 15_000 });

  const reopened = page.getByRole('dialog');

  const savePromise = page.waitForResponse(
    (res) => res.url().includes(`/api/admin/users/${uuid}`) && res.request().method() === 'PUT',
    { timeout: 20_000 },
  );
  await reopened.getByRole('button').last().click();
  const saveResponse = await savePromise;

  expect(saveResponse.status(), '탈퇴 저장이 실패했습니다').toBe(200);

  const after = await page.request.get(`/api/admin/users/${uuid}`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  const afterBody = await after.json();

  expect(afterBody?.data?.status).toBe('withdrawn');
  expect(afterBody?.data?.email, '탈퇴했는데 이메일이 익명화되지 않았습니다').not.toBe(email);
  expect(String(afterBody?.data?.email)).toContain('_deleted_');
});

test('관리자 회원 수정 폼: 탈퇴 외 상태 저장은 확인 없이 즉시 저장된다', async ({ page }) => {
  const token = issueToken(...ADMIN_PERMISSIONS);
  await authenticatePage(page, token);

  const email = `g7fix112.e2e.keep.${Date.now()}@example.com`;
  const uuid = await createTestUser(page, token, email);

  await page.goto(`/admin/users/${uuid}/edit`);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  await selectStatus(page, /비활성|Inactive/i);

  const savePromise = page.waitForResponse(
    (res) => res.url().includes(`/api/admin/users/${uuid}`) && res.request().method() === 'PUT',
    { timeout: 20_000 },
  );
  await page.locator('#footer_save_button').click();
  const saveResponse = await savePromise;

  expect(saveResponse.status()).toBe(200);

  // 저장이 확정된 뒤에 다이얼로그 부재를 단언한다 (부재 단언은 렌더 전에도 통과한다)
  await expect(page.getByRole('dialog')).toBeHidden();

  const after = await page.request.get(`/api/admin/users/${uuid}`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  const afterBody = await after.json();

  expect(afterBody?.data?.status).toBe('inactive');
  expect(afterBody?.data?.email, '탈퇴가 아닌데 이메일이 익명화되었습니다').toBe(email);
});

test('관리자 회원 수정 폼: 회원 정보가 채워지기 전에는 저장할 수 없다', async ({ page }) => {
  const token = issueToken(...ADMIN_PERMISSIONS);
  await authenticatePage(page, token);

  const email = `g7fix112.e2e.seed.${Date.now()}@example.com`;
  const uuid = await createTestUser(page, token, email);

  const saveRequests: string[] = [];
  page.on('request', (req) => {
    if (req.method() === 'PUT' && req.url().includes(`/api/admin/users/${uuid}`)) {
      saveRequests.push(String(req.postData()).slice(0, 80));
    }
  });

  await page.goto(`/admin/users/${uuid}/edit`);

  // 버튼이 보이자마자 누른다 — 폼이 API 응답으로 채워지기 전 시점이다.
  const save = page.locator('#footer_save_button');
  await save.waitFor({ state: 'visible', timeout: 20_000 });
  await save.click({ force: true }).catch(() => undefined);
  await page.waitForTimeout(2_000);

  // 빈 본문 저장이 나가면 화면에는 값이 보이는데 "이름은 필수입니다" 로 거절된다.
  expect(saveRequests, `시드 전에 저장 요청이 나갔습니다: ${saveRequests.join(' | ')}`).toHaveLength(0);

  // 시드가 끝나면 정상적으로 저장할 수 있다 (버튼이 영구 비활성으로 굳지 않는다).
  await expect(page.locator('[name="email"]')).toHaveValue(email, { timeout: 20_000 });
  await expect(save).toBeEnabled({ timeout: 10_000 });
});
