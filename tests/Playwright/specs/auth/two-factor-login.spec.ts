/**
 * 로그인 2단계 인증 종단 검증 (공개 #133).
 *
 * 2단계 인증을 켜면 서버가 로그인에 **두 가지 형태의 200** 을 돌려준다. 화면이 한 형태만
 * 가정하면 영문 오류(`Cannot read properties of undefined`)가 뜨고 로그인이 불가능해지며,
 * 관리자 로그인은 서버 오류가 되어 설정을 되돌릴 수단까지 사라진다.
 *
 * 이 spec 은 사이트 설정과 메일 설정을 **가역적으로** 바꾼다 — `test.afterAll` 이 실패해도
 * 되돌아가도록 fixture 가 원복을 담당한다. 되돌리지 못하면 이후 모든 로그인이 막히므로
 * 반드시 `mode: 'serial'` 로 한 워커에서만 실행한다.
 *
 * // @scenario controller=user,admin | two_factor=on | code=valid,invalid | resend=active
 * // @effects challenge_response_has_no_token, code_step_rendered_on_challenge, no_raw_typeerror_text, resend_cancels_previous_challenge, non_admin_two_factor_revokes_token
 */
import { test, expect, issueToken } from '../../fixtures/auth';
import {
  enableTwoFactorFixture,
  ensureTwoFactorUser,
  plantTwoFactorCode,
  purgeTwoFactorUsers,
  restoreTwoFactorFixture,
} from '../../fixtures/two-factor';

test.describe.configure({ mode: 'serial' });

const PASSWORD = 'Passw0rd!2fa';

let adminToken: string;
let fixtureRequest: import('@playwright/test').APIRequestContext | undefined;
let fixtureState: Awaited<ReturnType<typeof enableTwoFactorFixture>>;
let memberEmail: string;
let adminEmail: string;
let nonAdminEmail: string;

test.beforeAll(async ({ playwright }) => {
  adminToken = issueToken('core.settings.read', 'core.settings.update');

  memberEmail = ensureTwoFactorUser('user', { password: PASSWORD });
  adminEmail = ensureTwoFactorUser('admin', { admin: true, password: PASSWORD });
  nonAdminEmail = ensureTwoFactorUser('nonadmin', { password: PASSWORD });

  // worker 범위 request 컨텍스트 — baseURL·ignoreHTTPSErrors 는 설정에서 온다.
  fixtureRequest = await playwright.request.newContext({
    baseURL: process.env.PLAYWRIGHT_BASE_URL,
    ignoreHTTPSErrors: true,
  });

  fixtureState = await enableTwoFactorFixture(fixtureRequest, adminToken);
});

test.afterAll(async () => {
  // 되돌리지 못한 채 끝나면 사이트의 모든 로그인이 2단계 인증을 요구하게 된다.
  if (fixtureState && fixtureRequest) {
    await restoreTwoFactorFixture(fixtureRequest, adminToken, fixtureState);
  }

  await fixtureRequest?.dispose();

  // 알려진 비밀번호를 가진 계정(관리자 포함)을 남기지 않는다.
  purgeTwoFactorUsers();
});

/**
 * 로그인 응답에서 challenge_id 를 읽는다.
 *
 * @param page Playwright 페이지
 * @param urlPart 대기할 요청 경로 조각
 * @param submit 제출을 수행하는 함수
 */
async function submitAndReadChallenge(
  page: import('@playwright/test').Page,
  urlPart: string,
  submit: () => Promise<void>
): Promise<string> {
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().includes(urlPart) && r.request().method() === 'POST'),
    submit(),
  ]);

  const body = await response.json();
  expect(response.status(), '2단계 인증이 켜져 있으면 로그인은 200 챌린지를 돌려준다').toBe(200);
  expect(body?.data?.two_factor_required).toBe(true);
  // 코드 확인 전에 토큰이 실리면 2단계 인증이 없는 것과 같다.
  expect(body?.data?.token, '코드 확인 전에 토큰이 발급되었습니다').toBeFalsy();

  return String(body.data.challenge_id);
}

test('사용자 로그인 — 인증번호 단계로 전환되고 코드 확인 후 로그인된다', async ({ page }) => {
  await page.goto('/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const challengeId = await submitAndReadChallenge(page, '/api/auth/login', async () => {
    await page.fill('input[name="email"]', memberEmail);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('form button[type="submit"]');
  });

  // 2단계 입력이 나타나고 1단계 입력은 사라진다.
  const codeInput = page.locator('input[name="two_factor_code"]');
  await expect(codeInput).toBeVisible({ timeout: 10_000 });
  await expect(page.locator('input[name="email"]')).toHaveCount(0);

  // 영문 TypeError 원문이 화면에 남으면 안 된다.
  await expect(page.locator('body')).not.toContainText('Cannot read properties of undefined');

  // 오답 → 오류 문구, 로그인 미완료
  await codeInput.fill('000000');
  await page.click('form button[type="submit"]');
  await expect(page.locator('[role="alert"]')).toBeVisible({ timeout: 10_000 });
  expect(page.url()).toContain('/login');

  // 정답 → 홈 이동
  const code = plantTwoFactorCode(challengeId);
  await codeInput.fill(code);
  await page.click('form button[type="submit"]');

  await page.waitForFunction(() => !window.location.pathname.startsWith('/login'), {
    timeout: 15_000,
  });
  const token = await page.evaluate(() => localStorage.getItem('auth_token'));
  expect(token, '로그인 완료 후 토큰이 저장되어야 합니다').toBeTruthy();
  expect(token).not.toBe('undefined');
});

test('사용자 로그인 — 인증번호 다시 받기는 새 challenge 를 발급하고 입력을 비운다', async ({ page }) => {
  await page.goto('/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const first = await submitAndReadChallenge(page, '/api/auth/login', async () => {
    await page.fill('input[name="email"]', memberEmail);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('form button[type="submit"]');
  });

  const codeInput = page.locator('input[name="two_factor_code"]');
  await expect(codeInput).toBeVisible({ timeout: 10_000 });
  await codeInput.fill('111111');

  const [resendResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/two-factor/resend') && r.request().method() === 'POST'),
    page.getByRole('button', { name: /다시|Resend|再送/ }).click(),
  ]);

  const resendBody = await resendResponse.json();
  expect(resendResponse.status()).toBe(200);
  const second = String(resendBody.data.challenge_id);
  expect(second, '재발송이 같은 challenge 를 돌려주면 새 코드가 발송되지 않은 것입니다').not.toBe(first);

  // 앞서 입력한 값이 남아 있으면 새 코드를 받았는데 옛 값으로 제출된다.
  await expect(codeInput).toHaveValue('');

  const code = plantTwoFactorCode(second);
  await codeInput.fill(code);
  await page.click('form button[type="submit"]');

  await page.waitForFunction(() => !window.location.pathname.startsWith('/login'), {
    timeout: 15_000,
  });
});

/**
 * 인증 단계 상태는 전역이라 화면을 떠나도 남는다. 그래서 되돌리는 통로가 셋 다 살아 있어야 한다 —
 * 「처음부터」 버튼 · 새로고침 · 다른 화면으로 나갔다 돌아오기. 하나라도 빠지면 사용자가 1단계로
 * 돌아오지 못한 채 이미 만료된 challenge 앞에 갇힌다.
 *
 * 새로고침 축은 잘못 저장된 토큰(`"undefined"`) 회귀도 함께 잡는다 — 그 값이 남으면 이후 요청이
 * 전부 401 이 되어 `/login?reason=session_expired` 로 튕겼다(공개 #133 의 두 번째 증상).
 *
 * // @scenario controller=user | two_factor=on | action=restart
 * // @effects restart_resets_to_credential_step, init_actions_reset_two_factor_state
 */
test('인증번호 단계 — 처음부터·새로고침·재진입 모두 1단계로 돌아온다', async ({ page }) => {
  await page.goto('/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const enterCodeStep = async () => {
    await submitAndReadChallenge(page, '/api/auth/login', async () => {
      await page.fill('input[name="email"]', memberEmail);
      await page.fill('input[name="password"]', PASSWORD);
      await page.click('form button[type="submit"]');
    });
    await expect(page.locator('input[name="two_factor_code"]')).toBeVisible({ timeout: 10_000 });
  };

  const expectCredentialStep = async () => {
    await expect(page.locator('input[name="email"]')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('input[name="two_factor_code"]')).toHaveCount(0);
  };

  // ① 「처음부터」 버튼
  await enterCodeStep();
  await page.getByRole('button', { name: /처음부터|Start over|最初から/ }).click();
  await expectCredentialStep();

  // ② 새로고침 — 잘못된 토큰이 남았다면 여기서 session_expired 로 튕긴다.
  await enterCodeStep();
  await page.reload({ waitUntil: 'domcontentloaded' });
  expect(page.url(), '새로고침이 만료 안내로 튕기면 토큰이 잘못 저장된 것입니다').not.toContain(
    'reason=session_expired'
  );
  const staleToken = await page.evaluate(() => localStorage.getItem('auth_token'));
  expect(staleToken, '코드 확인 전에는 토큰이 저장되면 안 됩니다').not.toBe('undefined');
  await expectCredentialStep();

  // ③ 다른 화면으로 나갔다 돌아오기 — init_actions 리셋이 없으면 2단계가 그대로 남는다.
  await enterCodeStep();
  await page.goto('/register');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.goto('/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expectCredentialStep();
});

test('관리자 로그인 — 챌린지 응답이 서버 오류가 아니고 코드 확인 후 관리자 화면으로 간다', async ({ page }) => {
  await page.goto('/admin/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const challengeId = await submitAndReadChallenge(page, '/api/auth/admin/login', async () => {
    await page.fill('input[name="email"]', adminEmail);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('#login_submit_button');
  });

  const codeInput = page.locator('#two_factor_code');
  await expect(codeInput).toBeVisible({ timeout: 10_000 });
  await expect(page.locator('body')).not.toContainText('Cannot read properties of undefined');

  await codeInput.fill(plantTwoFactorCode(challengeId));
  await page.locator('#login_two_factor_submit').click();

  await page.waitForFunction(() => !window.location.pathname.startsWith('/admin/login'), {
    timeout: 15_000,
  });
});

test('관리자 로그인 — 관리자가 아닌 계정은 코드 확인에 성공해도 거부되고 토큰이 남지 않는다', async ({ page }) => {
  await page.goto('/admin/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const challengeId = await submitAndReadChallenge(page, '/api/auth/admin/login', async () => {
    await page.fill('input[name="email"]', nonAdminEmail);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('#login_submit_button');
  });

  const codeInput = page.locator('#two_factor_code');
  await expect(codeInput).toBeVisible({ timeout: 10_000 });
  await codeInput.fill(plantTwoFactorCode(challengeId));

  const [verifyResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/admin/login/two-factor') && r.request().method() === 'POST'),
    page.locator('#login_two_factor_submit').click(),
  ]);

  expect(verifyResponse.status()).toBe(403);

  // 코드 확인 시점에 발급된 토큰이 회수되지 않으면 유효한 세션이 남는다.
  const token = await page.evaluate(() => localStorage.getItem('auth_token'));
  expect(token, '거부된 로그인이 토큰을 남겼습니다').toBeFalsy();
  expect(page.url()).toContain('/admin/login');
});

test('인증번호 단계 — Enter 키는 확인만 보내고 새 challenge 를 발급하지 않는다', async ({ page }) => {
  await page.goto('/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const challengeId = await submitAndReadChallenge(page, '/api/auth/login', async () => {
    await page.fill('input[name="email"]', memberEmail);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('form button[type="submit"]');
  });

  const codeInput = page.locator('input[name="two_factor_code"]');
  await expect(codeInput).toBeVisible({ timeout: 10_000 });

  // 세 자리까지는 확인 버튼이 눌리지 않는다.
  await codeInput.fill('123');
  const verifyButton = page.locator('form button[type="submit"]');
  await expect(verifyButton).toBeDisabled();

  await codeInput.fill(plantTwoFactorCode(challengeId));
  await expect(verifyButton).toBeEnabled();

  const posts: string[] = [];
  page.on('request', (req) => {
    if (req.method() === 'POST' && req.url().includes('/api/auth/')) posts.push(req.url());
  });

  await codeInput.press('Enter');
  await page.waitForFunction(() => !window.location.pathname.startsWith('/login'), {
    timeout: 15_000,
  });

  const verifyCalls = posts.filter((u) => u.includes('/login/two-factor')).length;
  const loginCalls = posts.filter((u) => u.endsWith('/api/auth/login')).length;

  expect(verifyCalls, 'Enter 가 인증번호 확인을 보내지 않았습니다').toBe(1);
  // 상호배타 조건이 빠지면 Enter 가 새 challenge 를 발급해 흐름이 깨진다.
  expect(loginCalls, 'Enter 가 비밀번호 단계를 다시 호출했습니다').toBe(0);
});

test('인증번호 단계 — 모바일 폭과 일본어 로케일에서 문구·배치가 깨지지 않는다', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await page.addInitScript(() => localStorage.setItem('g7_locale', 'ja'));

  await page.goto('/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  await submitAndReadChallenge(page, '/api/auth/login', async () => {
    await page.fill('input[name="email"]', memberEmail);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('form button[type="submit"]');
  });

  await expect(page.locator('input[name="two_factor_code"]')).toBeVisible({ timeout: 10_000 });

  // 다국어 키가 해석되지 않으면 `$t:` 토큰이 그대로 화면에 남는다.
  const body = (await page.locator('body').innerText()) ?? '';
  expect(body).not.toContain('$t:');
  expect(body).not.toContain('auth.two_factor');

  // 다시 받기·처음부터 두 버튼이 좁은 폭에서도 컨테이너를 넘지 않는다.
  const buttons = page.locator('form button[type="button"]');
  const count = await buttons.count();
  expect(count).toBeGreaterThanOrEqual(2);

  for (let i = 0; i < count; i += 1) {
    const box = await buttons.nth(i).boundingBox();
    if (box) {
      expect(box.width, '버튼이 375px 화면을 넘칩니다').toBeLessThanOrEqual(375);
    }
  }
});

test('공개 본인인증 화면으로는 로그인 challenge 를 소진할 수 없다', async ({ page }) => {
  await page.goto('/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const challengeId = await submitAndReadChallenge(page, '/api/auth/login', async () => {
    await page.fill('input[name="email"]', memberEmail);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('form button[type="submit"]');
  });

  const code = plantTwoFactorCode(challengeId);

  const status = await page.evaluate(async (id) => {
    const res = await fetch(`/api/identity/challenges/${id}/verify`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ code: '135790' }),
    });
    return res.status;
  }, challengeId);

  expect(status, '로그인 challenge 가 공개 본인인증 경로로 소진되었습니다').toBe(403);

  // 거부는 상태를 바꾸지 않으므로 로그인은 그대로 완료된다.
  await page.locator('input[name="two_factor_code"]').fill(code);
  await page.click('form button[type="submit"]');
  await page.waitForFunction(() => !window.location.pathname.startsWith('/login'), {
    timeout: 15_000,
  });
});

/**
 * 인증번호를 여러 번 틀려도 그 challenge 로 계속 시도할 수 있어야 한다.
 *
 * 오답 때마다 입력을 비우면 오타 한 글자를 고치려던 사용자가 전체를 다시 친다 —
 * 입력을 남기는 것이 확정된 동작이다(레이아웃의 `loginTwoFactor` onError 는 code 를
 * 건드리지 않는다). 비우도록 바뀌면 아래 `toHaveValue` 가 red 가 된다.
 *
 * // @scenario controller=user | two_factor=on | code=invalid,valid
 * // @effects invalid_code_retries_until_success
 */
test('인증번호 단계 — 세 번 틀려도 입력이 남고 네 번째 정답으로 로그인된다', async ({ page }) => {
  await page.goto('/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const challengeId = await submitAndReadChallenge(page, '/api/auth/login', async () => {
    await page.fill('input[name="email"]', memberEmail);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('form button[type="submit"]');
  });

  const codeInput = page.locator('input[name="two_factor_code"]');
  await expect(codeInput).toBeVisible({ timeout: 10_000 });

  // 본인인증 정책 상한(max_attempts=5) 안이라 세 번까지는 challenge 가 살아 있다.
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    await codeInput.fill('000000');

    const [response] = await Promise.all([
      page.waitForResponse(
        (r) =>
          r.url().includes('/api/auth/login/two-factor') &&
          !r.url().includes('/resend') &&
          r.request().method() === 'POST'
      ),
      page.click('form button[type="submit"]'),
    ]);

    expect(response.status(), `${attempt}회째 오답이 거부되지 않았습니다`).toBe(401);
    await expect(page.locator('[role="alert"]')).toBeVisible({ timeout: 10_000 });

    // 오답 뒤에도 입력은 남는다.
    await expect(codeInput).toHaveValue('000000');
    expect(page.url()).toContain('/login');
  }

  await codeInput.fill(plantTwoFactorCode(challengeId));
  await page.click('form button[type="submit"]');

  await page.waitForFunction(() => !window.location.pathname.startsWith('/login'), {
    timeout: 15_000,
  });
});

/**
 * 로그인이 끝난 직후의 상태 — 2단계 흔적이 남으면 다음에 로그인 화면을 열었을 때
 * 비밀번호 단계가 아니라 코드 단계가 뜬다.
 *
 * // @scenario controller=user | two_factor=on | code=valid
 * // @effects session_state_cleared_after_two_factor_success
 */
test('인증 완료 직후 — 2단계 상태가 지워지고 사용자·토큰이 자리 잡는다', async ({ page }) => {
  await page.goto('/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const challengeId = await submitAndReadChallenge(page, '/api/auth/login', async () => {
    await page.fill('input[name="email"]', memberEmail);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('form button[type="submit"]');
  });

  await page.locator('input[name="two_factor_code"]').fill(plantTwoFactorCode(challengeId));
  await page.click('form button[type="submit"]');
  await page.waitForFunction(() => !window.location.pathname.startsWith('/login'), {
    timeout: 15_000,
  });

  const state = await page.evaluate(() => {
    const w = window as unknown as Record<string, any>;
    const globalState = w.__templateApp?.getGlobalState?.() ?? w.G7Core?.state?.get?.() ?? {};

    return {
      twoFactor: globalState.twoFactor ?? null,
      isLoggingIn: globalState.isLoggingIn ?? null,
      uuid: String(globalState.currentUser?.uuid ?? ''),
      email: String(globalState.currentUser?.email ?? ''),
      token: String(localStorage.getItem('auth_token') ?? ''),
    };
  });

  expect(state.twoFactor, '로그인이 끝났는데 2단계 상태가 남아 있습니다').toBeNull();
  expect(state.isLoggingIn, '로딩 표시가 켜진 채 남았습니다').toBe(false);
  expect(state.uuid.length, '로그인한 사용자가 전역 상태에 실리지 않았습니다').toBeGreaterThan(0);
  expect(state.email).toBe(memberEmail);
  // `"undefined"` 가 저장되던 결함(#133)은 길이 9 라 존재 검사만으로는 통과한다.
  expect(state.token.length, '저장된 토큰이 실제 토큰이 아닙니다').toBeGreaterThan(20);
});

/**
 * 확인 버튼을 빠르게 두 번 눌러도 인증 요청은 한 번만 나가야 한다.
 *
 * 두 번 나가면 두 번째가 이미 소진된 challenge 를 확인하려다 실패해, 로그인은 됐는데
 * 오류 문구가 함께 뜨는 상태가 된다.
 *
 * // @scenario controller=user | two_factor=on | code=valid
 * // @effects double_submit_sends_single_verify_request
 */
test('인증번호 단계 — 확인 버튼을 두 번 눌러도 확인 요청은 한 번만 나간다', async ({ page }) => {
  await page.goto('/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const challengeId = await submitAndReadChallenge(page, '/api/auth/login', async () => {
    await page.fill('input[name="email"]', memberEmail);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('form button[type="submit"]');
  });

  const codeInput = page.locator('input[name="two_factor_code"]');
  await expect(codeInput).toBeVisible({ timeout: 10_000 });
  await codeInput.fill(plantTwoFactorCode(challengeId));

  const verifyCalls: string[] = [];
  page.on('request', (req) => {
    if (
      req.method() === 'POST' &&
      req.url().includes('/api/auth/login/two-factor') &&
      !req.url().includes('/resend')
    ) {
      verifyCalls.push(req.url());
    }
  });

  const verifyButton = page.locator('form button[type="submit"]');
  const startedAt = Date.now();
  await verifyButton.click();

  // 두 번째 클릭이 도달하기 전에 버튼이 잠겨야 한다. 잠금은 제출 시퀀스가 세우는
  // `isLoggingIn` 이 화면에 반영될 때 걸리므로, 그 반영이 사람의 두 번째 클릭보다
  // 빨라야 한다는 뜻이다.
  await expect(verifyButton).toBeDisabled({ timeout: 1_000 });
  const lockedAfterMs = Date.now() - startedAt;
  expect(
    lockedAfterMs,
    `확인 버튼이 잠기기까지 ${lockedAfterMs}ms 가 걸렸습니다 — 사람의 두 번째 클릭이 그 사이에 들어옵니다`
  ).toBeLessThan(200);

  // 잠긴 뒤의 두 번째 클릭은 브라우저가 비활성 버튼에 전달하지 않는다.
  await verifyButton.click({ force: true, timeout: 2_000 }).catch(() => undefined);

  await page.waitForFunction(() => !window.location.pathname.startsWith('/login'), {
    timeout: 15_000,
  });

  expect(verifyCalls.length, '확인 요청이 두 번 나갔습니다').toBe(1);
});
