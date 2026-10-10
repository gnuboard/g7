/**
 * 회원 «상세» 화면의 인증 앱 탭 종단 검증 (이슈 #636 트랙1, 디자인 M).
 *
 * 이 탭은 **주입이 두 군데로 갈린다** — 탭 버튼은 `user_detail_tabs` 에 `inject_props` 로,
 * 본문은 `extension_tab_content` 에 `append_child` 로 붙고, 둘을 잇는 것은 id 하나뿐이다.
 * 한쪽만 그려지면 「탭은 있는데 눌러도 빈 화면」이 되는데, 그 실패는 단위 테스트가
 * 각 조각을 따로 보는 한 드러나지 않는다 — 실제로 렌더해 봐야 안다.
 *
 * 붙는 자리가 «상세»(`/admin/users/:id`)라는 것 자체가 4차 검증이 고친 결함이다. 종전에는
 * «수정»(`/admin/users/:id/edit`)에 붙어 있어, 안내된 순서대로 목록에서 회원을
 * 눌러 들어가면 카드가 없었다. 이 spec 은 그 경로를 사람과 같은 순서로 밟는다.
 *
 * 사이트 설정을 건드리지 않는다 — 탭은 로그인한 관리자의 권한과 대상 회원의 등록 여부만
 * 보므로 2단계 인증 토글이 필요 없다. 만든 계정은 `afterAll` 이 접두사로 한정해 지운다.
 *
 * // @scenario provider=totp, enrolled=true, actor=admin
 * // @effects admin_forced_reset_is_written_to_the_activity_log
 */
import { test, expect } from '../../fixtures/auth';
import { ensureTwoFactorUser, enrollTotpFor, purgeTwoFactorUsers } from '../../fixtures/two-factor';

test.describe.configure({ mode: 'serial' });

const PASSWORD = 'Passw0rd!2fa';

/** 이 spec 이 만드는 계정의 접두사 — 정리 범위를 자기 것으로 한정한다. */
const ACCOUNT_PREFIX = 'totpmembertab';

let adminEmail: string;

test.beforeAll(() => {
  // 해제 버튼을 누를 관리자와, 해제 «당할» 등록 회원을 따로 만든다 — 자기 자신을 풀면
  // 이어지는 단언이 로그인 세션 쪽 부작용과 섞인다.
  // 전용 역할까지 붙여 두는 것은 운영에서 이 화면을 보는 사람의 모습에 맞추기 위함이다
  // (권한 자체는 admin 역할에도 딸려 온다 — 아래 API 축 주석 참조).
  adminEmail = ensureTwoFactorUser(`${ACCOUNT_PREFIX}admin`, {
    admin: true,
    password: PASSWORD,
    roles: ['sirsoft-totp.credentials'],
  });

  const targetEmail = ensureTwoFactorUser(`${ACCOUNT_PREFIX}target`, { password: PASSWORD });
  enrollTotpFor(targetEmail);
});

test.afterAll(() => {
  // 알려진 비밀번호를 가진 계정을 남기지 않는다. 형제 spec 의 계정은 건드리지 않는다.
  purgeTwoFactorUsers(ACCOUNT_PREFIX);
});

/**
 * 관리자로 로그인한다. 2단계 인증이 꺼져 있으므로 한 번에 들어간다.
 *
 * @param page Playwright 페이지
 * @returns 반환값 없음
 */
async function loginAsAdmin(
  page: import('@playwright/test').Page,
  email: string = adminEmail
): Promise<void> {
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
 * 회원 목록에서 대상 회원의 «상세» 화면으로 사람과 같은 순서로 들어간다.
 *
 * 주소를 직접 치지 않는다 — 이 결함은 「안내대로 눌러 갔더니 없더라」였으므로,
 * 누르는 경로 자체가 검증 대상이다.
 *
 * @param page Playwright 페이지
 * @returns 반환값 없음
 */
async function targetUuid(page: import('@playwright/test').Page): Promise<string> {
  // 대상 회원의 식별자는 목록 응답에서 집는다. 라우트 파라미터 이름은 `:id` 지만 실제로
  // 실리는 값은 **uuid** 다(목록의 navigate 가 `{{$args[1].uuid}}` 를 넘긴다) — 숫자를
  // 넣으면 상세 화면이 뜨지 않는다.
  // 첫 페이지에 없을 수 있으므로 한 번에 크게 받는다 — 시드 계정은 가입일 순서에서
  // 뒤로 밀릴 수 있고, 그 우연에 spec 이 흔들리면 안 된다.
  const [listResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/admin/users') && r.ok()),
    page.goto('/admin/users?per_page=100'),
  ]);

  const body = await listResponse.json();
  const rows = body.data?.data ?? body.data ?? [];
  const target = rows.find((u: { email?: string }) => (u.email ?? '').includes(`${ACCOUNT_PREFIX}target`));

  expect(target, '대상 회원이 목록에 없습니다').toBeTruthy();

  return target.uuid as string;
}

/**
 * 대상 회원의 «상세» 화면을 연다.
 *
 * @param page Playwright 페이지
 * @returns 대상 회원 uuid
 */
async function openTargetMemberDetail(page: import('@playwright/test').Page): Promise<string> {
  const uuid = await targetUuid(page);

  await page.goto(`/admin/users/${uuid}`);
  await page.waitForResponse((r) => r.url().includes(`/api/admin/users/${uuid}`) && r.ok());

  return uuid;
}

// @scenario provider=totp, enrolled=true, actor=admin
// @effects admin_reset_tab_is_reachable_from_member_detail
test('회원 상세에 「인증 앱」 탭이 뜨고 눌러서 본문까지 닿는다', async ({ page }) => {
  await loginAsAdmin(page);
  await openTargetMemberDetail(page);

  // 탭 버튼 — inject_props 로 기존 탭 배열에 덧붙는다.
  const tab = page.getByRole('button', { name: '인증 앱' }).first();
  await expect(tab).toBeVisible();

  await tab.click();

  // 본문 — append_child 로 붙는 별도 조각. 탭만 있고 본문이 없으면 여기서 red 다.
  await expect(page.locator('#ext_totp_security')).toBeVisible();
  await expect(page.getByRole('button', { name: /등록 해제/ })).toBeVisible();
});

// @scenario provider=totp, enrolled=true, actor=admin
// @effects admin_reset_tab_is_absent_from_member_edit
test('수정 화면에는 그 탭이 없다 — 상세로 옮긴 자리가 유지된다', async ({ page }) => {
  await loginAsAdmin(page);
  await openTargetMemberDetail(page);

  const detailUrl = page.url();
  await page.goto(`${detailUrl}/edit`);
  await page.waitForLoadState('networkidle');

  // 종전 위치다. 되돌아가면 운영 안내(목록 → 회원 클릭)와 다시 어긋난다.
  await expect(page.locator('#ext_totp_security')).toHaveCount(0);
});

// @scenario provider=totp, enrolled=true, actor=admin
// @effects admin_reset_tab_is_hidden_without_the_permission
test('강제 해제 API 는 관리자 권한 없이는 막힌다', async ({ page }) => {
  // 화면 조건으로 가리지 않는다 — 관리자 셸이 `_global.currentUser` 를 올리지 않아 그 조건은
  // 항상 거짓이 되고 본문이 통째로 사라진다(2026-09-18 실측). 권한 집행의 정본은 라우트
  // 미들웨어(`permission:admin,sirsoft-totp.credentials.delete`)이므로 그쪽을 잰다.
  //
  // 「전용 역할이 없는 admin」은 대상이 아니다 — 이 플러그인은 `getPermissions()` 에서
  // `roles: ['admin', 'sirsoft-totp.credentials']` 로 **admin 에게도 준다**(gdpr·board 와
  // 같은 저장소 관행). 그래서 admin 이 200 을 받는 것이 설계대로다. 실제로 막혀야 하는 것은
  // 관리자 권한 자체가 없는 로그인이므로 그쪽을 잰다.
  // uuid 조회 자체가 관리자 세션을 요구하므로 먼저 로그인해 값을 얻고, 그 다음 토큰을 지운다.
  await loginAsAdmin(page);
  const uuid = await targetUuid(page);

  await page.evaluate(() => { try { localStorage.clear(); sessionStorage.clear(); } catch { /* 저장소 접근 불가 환경 */ } });

  const res = await page.evaluate(async (id) => {
    const r = await fetch(`/api/plugins/sirsoft-totp/admin/credentials/${id}`, {
      method: 'DELETE',
      headers: { Accept: 'application/json' },
    });
    return r.status;
  }, uuid);

  expect([401, 403]).toContain(res);
});
