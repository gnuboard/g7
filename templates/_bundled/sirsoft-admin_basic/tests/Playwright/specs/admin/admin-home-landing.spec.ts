/**
 * sirsoft-admin_basic — 관리자 첫 화면(`/admin`)이 볼 수 있는 화면으로 보낸다, 알림 권한 없는 관리자에게 알림 요청을 되풀이하지 않는다.
 *
 * 결함: 대시보드 권한(`core.dashboard.read`)이 없는 관리자(확장이 준 역할만 가진 계정 — SSO 운영자 등)가 관리자 로그인하면
 *   `/admin` → `/admin/dashboard` 로 고정 이동해 레이아웃 403 → 왼쪽 메뉴·머리 없는 「페이지 로딩 실패」 전체 화면에 갇혔다.
 *   같은 계정의 머리 종 아이콘은 화면마다 `GET /api/admin/notifications` · `/unread-count` 403 을 되풀이했다.
 * 고침: `/admin` 이 첫 화면 레이아웃(`admin_home`)을 그려 대시보드 권한이 있으면 대시보드, 없으면 사이드바 메뉴의 첫 화면으로
 *   보낸다. 로그인 관리자의 권한 목록으로 알림 조회 권한을 판정해, 없으면 종 아이콘을 숨기고 다음 화면부터 알림을 받지 않는다.
 *   판정 전인 첫 화면(새로고침·주소 직접 진입)에 나간 알림 요청의 403 은 조용히 넘긴다(종전에는 빨간 「해당 권한이 없습니다.」 토스트 두 번).
 *
 * 레이아웃 시험(`admin-home-landing.test.tsx`·`admin-base-notification-permission.test.ts`)은 판정 식과 이동 노드를 재고,
 * 이 spec 은 실제 로그인 제출 뒤 브라우저가 내린 주소·사이드바·네트워크 요청을 잰다.
 *
 * 계정: 관리자 토큰(전체 권한)으로 회원을 하나 만들고 역할 「SSO 운영자」(`sirsoft-sso.manager`)만 준다 — 화면의 「회원 추가」와
 *   같은 API 다. SSO 플러그인이 설치·활성이어야 한다(역할이 없으면 여기서 실패한다). 끝나면 그 회원을 지운다(지우기 실패는 시험 실패).
 *   관리자가 정해 준 비밀번호라 사이트 정책에 따라 첫 로그인에 「새 비밀번호를 설정해 주세요」 단계가 뜰 수 있다 — 그 단계도 로그인이 끝나는
 *   갈래(복귀 주소 없으면 `/admin`)이므로 새 비밀번호를 넣고 「변경하고 로그인」으로 넘기고, 다음 로그인부터 바뀐 비밀번호를 쓴다.
 *
 * 대기: 관리자 화면은 실시간 연결 등으로 네트워크가 조용해지지 않아 `networkidle` 을 쓰지 않는다 — 주소·화면 요소·응답으로 기다린다.
 *   서버가 느린 환경(응답 3~5초)에 맞춰 대기는 넉넉히 잡는다(실행 예: `--timeout=120000`).
 *
 * 시나리오 매니페스트: `tests/scenarios/admin-home-landing.yaml`, `tests/scenarios/admin-base-notification-permission.yaml`
 * @effects dashboard_reader_goes_to_dashboard, no_dashboard_goes_to_first_menu, notifications_not_requested_without_permission, bell_hidden_without_permission, first_screen_forbidden_silent
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/admin-template-auth';
import type { APIRequestContext, Browser, Page } from '@playwright/test';

const ROLE = 'sirsoft-sso.manager';
/** 처음 정해 준 비밀번호 — 변경 단계가 뜨면 매번 새 값으로 바꾸고 아래 `password` 를 갱신한다 */
const INITIAL_PASSWORD = 'Landing!Passw0rd#g7';
/** 주소·요소 대기 상한(ms) — 느린 서버 기준 */
const WAIT = 60_000;
/** 운영자의 첫 화면 */
const OPERATOR_HOME = '/admin/plugins/sirsoft-sso/accounts';

let password = INITIAL_PASSWORD;
let passwordSeq = 0;

let adminToken: string;
let account: { uuid: string; email: string } | null = null;

/**
 * 관리자 토큰을 실은 요청 머리.
 *
 * @returns 머리
 */
function auth(): Record<string, string> {
  return { Authorization: `Bearer ${adminToken}`, Accept: 'application/json' };
}

/**
 * 역할 하나만 가진 관리자 회원을 만든다.
 *
 * @param request API 문맥
 * @returns 만든 회원 uuid·이메일
 */
async function createRoleOnlyAdmin(request: APIRequestContext): Promise<{ uuid: string; email: string }> {
  const roles = await (await request.get('/api/admin/roles/active', { headers: auth() })).json();
  const rows = (roles?.data?.data ?? roles?.data ?? []) as Array<{ id: number; identifier: string }>;
  const role = rows.find((r) => r.identifier === ROLE);
  if (!role) throw new Error(`역할 ${ROLE} 이 없습니다 — SSO 플러그인을 설치·활성한 뒤 실행하세요`);

  const email = `landing-${Date.now()}@playwright.local`;
  const res = await request.post('/api/admin/users', {
    headers: auth(),
    data: { name: '첫 화면 시험', email, password: INITIAL_PASSWORD, password_confirmation: INITIAL_PASSWORD, status: 'active', role_ids: [role.id] },
  });
  const body = await res.json();
  if (!res.ok()) throw new Error(`회원 만들기 실패 ${res.status()}: ${JSON.stringify(body).slice(0, 400)}`);

  return { uuid: body?.data?.uuid ?? body?.data?.data?.uuid, email };
}

/**
 * 새 문맥에서 관리자 로그인 화면으로 로그인하고, 로그인 화면을 떠나 내린 페이지를 돌려준다.
 *
 * 비밀번호 변경 단계(관리자가 정해 준 비밀번호·만료)가 뜨면 새 비밀번호로 「변경하고 로그인」을 누른다 — 그 뒤 이동도 같은 첫 화면 판정을 탄다.
 *
 * @param browser 브라우저
 * @param email 이메일
 * @returns 로그인 뒤 페이지(문맥은 호출 측이 닫는다)
 */
async function loginAs(browser: Browser, email: string): Promise<Page> {
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();
  await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
  await page.goto('/admin/login');
  await page.locator('input[name="email"]').waitFor({ state: 'visible', timeout: WAIT });
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.click('form button[type="submit"]');

  const changeStep = page.locator('input[name="password_change_new"]');
  const left = page.waitForURL((url) => !url.pathname.startsWith('/admin/login'), { timeout: WAIT }).then(() => 'left' as const);
  const asked = changeStep.waitFor({ state: 'visible', timeout: WAIT }).then(() => 'change' as const);
  left.catch(() => undefined);
  asked.catch(() => undefined);
  const outcome = await Promise.race([left, asked]);
  if (outcome === 'change') {
    passwordSeq += 1;
    const next = `Landing!Passw0rd#g7-${Date.now()}-${passwordSeq}`;
    await changeStep.fill(next);
    await page.fill('input[name="password_change_confirm"]', next);
    await page.getByRole('button', { name: '변경하고 로그인' }).click();
    await page.waitForURL((url) => !url.pathname.startsWith('/admin/login'), { timeout: WAIT });
    password = next;
  }

  return page;
}

/**
 * 운영자의 첫 화면(회원 연결)이 사이드바 메뉴와 함께 그려질 때까지 기다린다.
 *
 * @param page 페이지
 * @returns void
 */
async function waitOperatorHome(page: Page): Promise<void> {
  await page.waitForURL((url) => url.pathname === OPERATOR_HOME, { timeout: WAIT });
  await expect(page.locator('a[href="/admin/plugins/sirsoft-sso/logs"]').first(), '사이드바 「이력」 링크').toBeVisible({ timeout: WAIT });
}

test.describe.configure({ mode: 'serial' });

test.describe('@sirsoft-admin_basic 관리자 첫 화면 /admin', () => {
  test.beforeAll(async ({ playwright }) => {
    adminToken = issueToken('core.users.read', 'core.users.create', 'core.users.delete', 'core.permissions.read');
    const request = await playwright.request.newContext({ ignoreHTTPSErrors: true, baseURL: test.info().project.use.baseURL });
    account = await createRoleOnlyAdmin(request);
    await request.dispose();
  });

  test.afterAll(async ({ playwright }) => {
    if (!account?.uuid) return;
    const request = await playwright.request.newContext({ ignoreHTTPSErrors: true, baseURL: test.info().project.use.baseURL });
    const res = await request.delete(`/api/admin/users/${account.uuid}`, { headers: auth() });
    const gone = await request.get(`/api/admin/users/${account.uuid}`, { headers: auth() });
    await request.dispose();
    expect(res.ok(), `시험 계정 지우기 ${res.status()}`).toBe(true);
    expect(gone.status(), '지운 계정 조회').toBe(404);
  });

  // @scenario role=dashboard_reader
  // @effects dashboard_reader_goes_to_dashboard
  test('대시보드 권한이 있는 관리자가 /admin 을 열면 종전대로 대시보드로 간다', async ({ page }) => {
    await authenticatePage(page, issueToken('core.dashboard.read'));
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
    await page.goto('/admin');
    await page.waitForURL((url) => url.pathname === '/admin/dashboard', { timeout: WAIT });
  });

  // @scenario role=extension_role_only
  // @effects no_dashboard_goes_to_first_menu
  test('SSO 운영자는 로그인 직후 메뉴가 있는 첫 화면(SSO 연동 › 회원 연결)으로 간다', async ({ browser }) => {
    const page = await loginAs(browser, account!.email);
    try {
      await waitOperatorHome(page);
      await expect(page.getByText('페이지 로딩 실패'), '메뉴 없는 전체 오류 화면이 아니다').toHaveCount(0);
    } finally {
      await page.context().close();
    }
  });

  // @scenario role=extension_role_only
  // @effects no_dashboard_goes_to_first_menu
  test('SSO 운영자가 /admin 을 직접 열어도 같은 첫 화면으로 간다', async ({ browser }) => {
    const page = await loginAs(browser, account!.email);
    try {
      await waitOperatorHome(page);
      await page.goto('/admin');
      await waitOperatorHome(page);
    } finally {
      await page.context().close();
    }
  });

  // @scenario readable=false
  // @effects notifications_not_requested_without_permission, bell_hidden_without_permission
  test('알림 권한이 없는 SSO 운영자는 종 아이콘이 없고, 화면을 옮겨도 알림 요청을 보내지 않는다', async ({ browser }) => {
    const page = await loginAs(browser, account!.email);
    try {
      await waitOperatorHome(page);
      // 로그인 관리자를 받아 판정이 서면 종 아이콘이 사라진다(판정 전에는 그려 둔다)
      await expect(page.getByRole('button', { name: '알림', exact: true }), '종 아이콘').toHaveCount(0, { timeout: WAIT });

      const notificationCalls: string[] = [];
      page.on('request', (req) => {
        if (new URL(req.url()).pathname.startsWith('/api/admin/notifications')) notificationCalls.push(req.url());
      });
      // 화면을 옮기면 베이스 소스(로그인 관리자·메뉴 등)를 한 묶음으로 다시 받는다 — 알림 소스가 화면에 있으면 그 묶음에서 함께
      // 요청된다. 그래서 새 화면의 로그인 관리자 응답을 받은 뒤 조금 더 지켜보면 알림 요청 여부가 정해진다.
      for (const path of ['/admin/plugins/sirsoft-sso/logs', OPERATOR_HOME]) {
        const userLoaded = page.waitForResponse((res) => new URL(res.url()).pathname === '/api/admin/auth/user', { timeout: WAIT });
        await page.locator(`a[href="${path}"]`).first().click();
        await page.waitForURL((url) => url.pathname === path, { timeout: WAIT });
        await userLoaded;
        await page.waitForTimeout(2_000);
      }
      expect(notificationCalls, '화면 이동 뒤 알림 요청').toEqual([]);
    } finally {
      await page.context().close();
    }
  });

  // @scenario readable=unknown
  // @effects first_screen_forbidden_silent
  test('알림 권한이 없는 SSO 운영자가 관리자 화면을 새로 열어도(새로고침·주소 직접) 「해당 권한이 없습니다.」 알림이 뜨지 않는다', async ({ browser }) => {
    const page = await loginAs(browser, account!.email);
    try {
      await waitOperatorHome(page);
      // 토스트는 몇 초 뒤 사라지므로 화면 요소를 그때그때 기록한다(새로 연 문서마다 다시 심긴다)
      await page.addInitScript(() => {
        const seen: string[] = [];
        (window as any).__g7Alerts = seen;
        new MutationObserver(() => {
          document.querySelectorAll('[role="alert"]').forEach((el) => {
            const text = (el.textContent ?? '').trim();
            if (text && !seen.includes(text)) seen.push(text);
          });
        }).observe(document, { childList: true, subtree: true, characterData: true });
      });
      const forbidden: string[] = [];
      page.on('response', (res) => {
        if (new URL(res.url()).pathname.startsWith('/api/admin/notifications') && res.status() === 403) forbidden.push(new URL(res.url()).pathname);
      });

      const opens: Array<[string, () => Promise<unknown>]> = [
        ['새로고침', () => page.reload()],
        ['주소 직접 진입', () => page.goto('/admin/plugins/sirsoft-sso/logs')],
      ];
      for (const [label, open] of opens) {
        const userLoaded = page.waitForResponse((res) => new URL(res.url()).pathname === '/api/admin/auth/user', { timeout: WAIT });
        await open();
        await userLoaded;
        await expect(page.locator('a[href="/admin/plugins/sirsoft-sso/logs"]').first(), `${label} 뒤 사이드바`).toBeVisible({ timeout: WAIT });
        // 판정 전에 나간 알림 요청의 응답과 그 처리(토스트)가 끝날 때까지 지켜본다
        await page.waitForTimeout(5_000);
        const alerts = (await page.evaluate(() => (window as any).__g7Alerts ?? [])) as string[];
        expect(alerts.filter((text) => text.includes('권한이 없습니다')), `${label} 뒤 권한 오류 알림`).toEqual([]);
      }
      test.info().annotations.push({ type: 'notification-403', description: `판정 전 알림 403 ${forbidden.length}건(조용히 넘김): ${forbidden.join(', ')}` });
      await expect(page.getByRole('button', { name: '알림', exact: true }), '종 아이콘').toHaveCount(0, { timeout: WAIT });
    } finally {
      await page.context().close();
    }
  });
});
