/**
 * 마이페이지 비밀번호 변경 종단 검증 (#736).
 *
 * - 현재 비밀번호가 틀리면 그 칸 아래에만 오류가 오고, 재사용 오류는 오지 않는다(본인 증명 전 비교 금지 — 맞혀 보기 방지).
 * - 현재 비밀번호가 맞고 최근 비밀번호를 다시 쓰면 새 비밀번호 칸 아래에 재사용 오류가 온다(토스트로 뭉개지 않는다).
 * - 성공하면 다른 기기 로그아웃 안내가 보이고, 다른 기기 토큰은 401·이 기기는 유지된다.
 *
 * 비밀번호 변경 본인인증 정책(`core.profile.password_change`)은 이 spec 동안만 끄고 되돌린다 — 본인인증 창 흐름은 이 spec 의
 * 대상이 아니다(코어 PHPUnit `ProfilePasswordViaUpdateTest`·본인인증 E2E 가 덮는다). 보안 설정은 스냅샷 후 복원(serial).
 *
 * // @scenario surface=mypage | status=expiring | response=ok,422
 * // @effects mypage_inline_field_errors, mypage_success_announces_other_devices_logout, mypage_shows_password_status
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import { restorePasswordExpiryFixture, runSeedPasswordExpiry, seedAccount } from '../../fixtures/password-expiry';

test.describe.configure({ mode: 'serial' });

const NEW_PASSWORD = 'N3w-Passw0rd!my';
const POLICY_KEY = 'core.profile.password_change';

let adminToken: string;
let request: import('@playwright/test').APIRequestContext | undefined;
let policyId: string | number | null = null;
let policyWasEnabled = false;

/** 관리자 API 머리글 */
const headers = () => ({ Authorization: `Bearer ${adminToken}`, Accept: 'application/json' });

/**
 * 비밀번호 변경 본인인증 정책의 켜짐 상태를 바꾼다.
 *
 * @param enabled 켤지 여부
 */
async function setPolicyEnabled(enabled: boolean): Promise<void> {
  if (policyId === null) return;
  const response = await request!.put(`/api/admin/identity/policies/${policyId}`, { headers: headers(), data: { enabled } });
  expect(response.ok(), await response.text()).toBe(true);
}

test.beforeAll(async ({ playwright }, testInfo) => {
  adminToken = issueToken(
    'core.settings.read', 'core.settings.update',
    'core.admin.identity.policies.read', 'core.admin.identity.policies.update',
  );
  request = await playwright.request.newContext({ baseURL: testInfo.project.use.baseURL, ignoreHTTPSErrors: true });
  runSeedPasswordExpiry(['--snapshot-settings']);

  // 재사용 금지 켜기(최근 3개) — 탭 저장은 필수 키를 함께 요구하므로 현재 값에 덮는다.
  const read = await request.get('/api/admin/settings?tab=security', { headers: headers() });
  const current = ((await read.json())?.data?.security ?? {}) as Record<string, unknown>;
  const previous = Object.fromEntries(Object.entries(current).filter(([key]) => !key.startsWith('_')));
  const saved = await request.post('/api/admin/settings', {
    headers: headers(),
    data: { _tab: 'security', security: { ...previous, password_reuse_prevention_enabled: true, password_reuse_count: 3 } },
  });
  expect(saved.status(), await saved.text()).toBe(200);

  const list = await request.get('/api/admin/identity/policies?per_page=100', { headers: headers() });
  const rows = ((await list.json())?.data?.data ?? (await list.json())?.data ?? []) as Array<{ id: string | number; key: string; enabled: boolean }>;
  const policy = rows.find((row) => row.key === POLICY_KEY);
  if (policy) {
    policyId = policy.id;
    policyWasEnabled = !!policy.enabled;
    if (policyWasEnabled) await setPolicyEnabled(false);
  }
});

test.afterAll(async () => {
  try {
    if (policyWasEnabled) await setPolicyEnabled(true);
  } finally {
    await request?.dispose();
    restorePasswordExpiryFixture();
  }
});

/**
 * @scenario surface=mypage, status=expiring, response=422
 * @effects mypage_inline_field_errors, mypage_success_announces_other_devices_logout, mypage_shows_password_status
 */
test('틀린 현재 비밀번호 → 그 칸 오류만, 재사용 → 새 비밀번호 칸 오류, 정상 → 다른 기기 로그아웃 안내', async ({ page }) => {
  const account = seedAccount('fresh', { withHistory: true, issueToken: true });
  // 이 기기 토큰(로그인 상태)과 다른 기기 토큰을 따로 둔다.
  const thisDevice = seedAccount('fresh', { withHistory: true, issueToken: true });
  expect(thisDevice.email).toBe(account.email);
  await authenticatePage(page, thisDevice.otherDeviceToken!);

  await page.goto('/mypage/change-password');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  const current = page.locator('input[name="current_password"]');
  await expect(current).toBeVisible({ timeout: 20_000 });
  // 머리 아래 상태 상자 — 마지막 변경일
  await expect(page.locator('body')).toContainText(/마지막 변경|Last changed/, { timeout: 10_000 });

  const submit = async () => {
    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/api/me/password') && r.request().method() === 'PUT'),
      page.click('form button[type="submit"]'),
    ]);
    return response;
  };

  // ① 현재 비밀번호 틀림 + 직전 비밀번호 — 재사용 오류는 오지 않아야 한다(A15).
  await current.fill('Wrong-Passw0rd!');
  await page.fill('input[name="password"]', 'OldPassw0rd!1');
  await page.fill('input[name="password_confirmation"]', 'OldPassw0rd!1');
  const wrong = await submit();
  expect(wrong.status()).toBe(422);
  const wrongErrors = (await wrong.json())?.errors ?? {};
  expect(Object.keys(wrongErrors)).toContain('current_password');
  expect(Object.keys(wrongErrors)).not.toContain('password');

  // ② 현재 비밀번호 맞음 + 직전 비밀번호 — 새 비밀번호 칸 아래 재사용 오류.
  await current.fill(account.password);
  const reused = await submit();
  expect(reused.status()).toBe(422);
  const reusedErrors = (await reused.json())?.errors ?? {};
  expect(Object.keys(reusedErrors)).toContain('password');
  await expect(page.locator('body')).toContainText(String(reusedErrors.password[0]), { timeout: 10_000 });

  // ③ 정상 — 성공 안내와 다른 기기 로그아웃
  await page.fill('input[name="password"]', NEW_PASSWORD);
  await page.fill('input[name="password_confirmation"]', NEW_PASSWORD);
  const ok = await submit();
  expect(ok.status()).toBe(200);
  await expect(page.locator('body')).toContainText(/다른 기기에서는 로그아웃|logged out on other devices/, { timeout: 10_000 });

  const other = await request!.get('/api/auth/user', { headers: { Authorization: `Bearer ${account.otherDeviceToken}`, Accept: 'application/json' } });
  expect(other.status()).toBe(401);
  const mine = await request!.get('/api/auth/user', { headers: { Authorization: `Bearer ${thisDevice.otherDeviceToken}`, Accept: 'application/json' } });
  expect(mine.status()).toBe(200);
});
