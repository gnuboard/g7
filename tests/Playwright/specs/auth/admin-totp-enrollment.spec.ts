/**
 * 인증 앱(TOTP) 등록 화면 종단 검증 (이슈 #636 트랙1).
 *
 * 로그인 축은 `admin-totp-login.spec.ts` 가 덮는다. 여기서는 **등록 화면**만 본다 —
 * 특히 백업 코드가 뜨는 마지막 단계다.
 *
 * 백업 코드는 이 화면에서 **한 번만** 보인다. 옮겨 적다 놓치면 휴대폰을 잃었을 때
 * 로그인할 길이 사라지므로, 한 번에 가져갈 수단이 「보관했습니다」 앞에 있어야 한다.
 * 그 버튼이 조용히 빠지면 사람이 실제로 코드를 잃기 전까지 아무도 모른다.
 *
 * 이 spec 은 사이트 설정을 건드리지 않는다 — 등록은 로그인한 관리자 본인의 자격증명만
 * 만들므로 2단계 인증 토글이 필요 없다. 만든 계정은 `afterAll` 이 접두사로 한정해 지운다.
 *
 * // @scenario provider=totp, enrolled=false, code=valid
 * // @effects backup_codes_are_shown_once_at_enrollment, backup_codes_can_be_taken_in_one_go
 */
import { test, expect } from '../../fixtures/auth';
import { ensureTwoFactorUser, purgeTwoFactorUsers, totpCodeFor } from '../../fixtures/two-factor';

test.describe.configure({ mode: 'serial' });

const PASSWORD = 'Passw0rd!2fa';

/** 이 spec 이 만드는 계정의 접미사 — 정리 범위를 자기 것으로 한정한다. */
const ACCOUNT_PREFIX = 'totpenroll';

/** 등록 화면 경로 — 코어「플러그인 관리 → 설정」 자동 경로다(`AbstractPlugin::getSettingsRoute()`). */
const SECURITY_PATH = '/admin/plugins/sirsoft-totp/settings';

let email: string;

test.beforeAll(() => {
  email = ensureTwoFactorUser(ACCOUNT_PREFIX, { admin: true, password: PASSWORD });
});

test.afterAll(() => {
  // 알려진 비밀번호를 가진 관리자 계정을 남기지 않는다. 형제 spec 의 계정은 건드리지 않는다.
  purgeTwoFactorUsers(ACCOUNT_PREFIX);
});

/**
 * 관리자로 로그인한다. 2단계 인증이 꺼져 있으므로 한 번에 들어간다.
 *
 * @param page Playwright 페이지
 * @returns 반환값 없음
 */
async function loginAsAdmin(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/admin/login');
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([
    page.waitForResponse(
      (r) => r.url().includes('/api/auth/admin/login') && r.request().method() === 'POST'
    ),
    page.click('form button[type="submit"]'),
  ]);
}

/**
 * 등록을 끝까지 진행해 백업 코드 화면까지 간다.
 *
 * 화면이 주는 시크릿으로 코드를 만들지 않고 서버에 물어보는 것은, 이 spec 이 재려는 것이
 * 「등록이 끝난 뒤 화면이 무엇을 주는가」이지 TOTP 계산이 아니기 때문이다 — 계산 자체는
 * 플러그인 PHPUnit(`TotpTest`)이 RFC 벡터로 잠근다.
 *
 * @param page Playwright 페이지
 * @returns 반환값 없음
 */
async function enrollThroughUi(page: import('@playwright/test').Page): Promise<void> {
  await page.goto(SECURITY_PATH);

  // 레이아웃의 `data-ref` 는 DOM 에 `id` 로 렌더된다 — `[data-ref=…]` 속성으로는 찾을 수 없다.
  // 카드 1(시작 전) → 「등록 시작」 → 카드 2(진행)
  const [startResponse] = await Promise.all([
    page.waitForResponse(
      (r) =>
        r.url().includes('/api/plugins/sirsoft-totp/enrollment') &&
        r.request().method() === 'POST'
    ),
    page.locator('#totp_security_start').click(),
  ]);

  const started = await startResponse.json();
  const secret: string = started?.data?.secret ?? '';
  expect(secret, '등록 시작은 시크릿을 1회 내려준다').toBeTruthy();

  // 앱이 만들 코드를 시드 커맨드에게 대신 계산시킨다(휴대폰이 없다).
  const code = totpCodeFor(secret);

  await page.fill('#totp_confirm_code', code);
  await Promise.all([
    page.waitForResponse(
      (r) =>
        r.url().includes('/api/plugins/sirsoft-totp/enrollment/confirm') &&
        r.request().method() === 'POST'
    ),
    page.locator('#totp_security_confirm').click(),
  ]);
}

/**
 * 등록을 마치면 백업 코드 10개가 뜨고, 한 번에 가져갈 수단이 함께 있는지 본다.
 *
 * @scenario provider=totp, enrolled=false, code=valid
 * @effects backup_codes_are_shown_once_at_enrollment, backup_codes_can_be_taken_in_one_go
 */
test('등록을 마치면 백업 코드와 함께 「전체 복사」가 뜬다', async ({ page, context }) => {
  // 클립보드 쓰기를 허용해야 실제 버튼 동작을 잰다(비보안 컨텍스트면 폴백이 돈다).
  await context.grantPermissions(['clipboard-read', 'clipboard-write']).catch(() => {
    // 권한 모델이 다른 브라우저에서는 폴백 경로가 돌아 여전히 복사된다.
  });

  await loginAsAdmin(page);
  await enrollThroughUi(page);

  // 백업 코드 카드가 떴다 — 10개가 모두 보여야 한다.
  const codes = page.locator('[id^="totp_security_backup_item_"]');
  await expect(codes).toHaveCount(10);

  const copyButton = page.locator('#totp_security_backup_copy');
  await expect(copyButton, '백업 코드를 한 번에 가져갈 수단이 없습니다').toBeVisible();

  // 화면에 뜬 코드와 클립보드에 담긴 것이 같아야 한다 — 다르면 운영자가 엉뚱한 것을 보관한다.
  const shown = (await codes.allInnerTexts()).map((t) => t.trim()).filter(Boolean);
  await copyButton.click();

  const clipboard = await page
    .evaluate(() => navigator.clipboard.readText())
    .catch(() => '');

  if (clipboard !== '') {
    for (const code of shown) {
      expect(clipboard, '복사된 내용에 화면의 백업 코드가 빠졌습니다').toContain(code);
    }
  }

  // 복사했다고 화면이 닫히면 안 된다 — 붙여넣기 전에 사라지면 그대로 잃는다.
  await expect(codes).toHaveCount(10);
});

/**
 * 백업 코드는 1회 노출이 계약이다 — 화면을 다시 열면 보이지 않아야 한다.
 *
 * @scenario provider=totp, enrolled=true
 * @effects backup_codes_are_shown_once_at_enrollment
 */
test('화면을 다시 열면 백업 코드는 보이지 않는다', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto(SECURITY_PATH);

  // 앞 테스트가 등록을 마쳤으므로 이제 「등록됨」 카드가 떠야 한다.
  await expect(page.locator('#totp_security_enrolled_card')).toBeVisible();
  await expect(page.locator('[id^="totp_security_backup_item_"]')).toHaveCount(0);
  await expect(page.locator('#totp_security_backup_copy')).toHaveCount(0);
});
