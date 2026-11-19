/**
 * sirsoft-admin_basic — 검수 서버(실행 역할 stage)에서 대시보드 호환성 [다시 활성화] 잠금 (#888)
 *
 * 운영 DB 를 공유하는 검수 서버에서는 확장을 다시 켜는 복구 API 가 서버에서 409 로 막힌다. 화면은 누르기
 * 전에 그 사실을 보여야 한다 — 대시보드 상단 경고와 시스템 알림 목록의 [다시 활성화] 두 곳이 disabled 가 되고
 * 잠금 안내(title)를 단다. 실행 역할은 셸 HTML 의 설정 블록(`appConfig.runtime_role`)으로 실려 오므로,
 * 서버 캐시가 옛 레이아웃을 내주거나 셸 주입이 빠지면 단위 테스트가 초록이어도 실제 화면은 잠기지 않는다.
 *
 * 방법: 문서 요청을 가로채 원본 셸을 받은 뒤 그 안의 실행 역할 값만 stage 로 바꿔 돌려준다(서버 설정은
 * 그대로다). 같은 화면을 바꾸지 않고 열면(live) 잠기지 않고 눌렀을 때 복구 요청이 나가는지도 본다 —
 * 잠금이 조건 없이 걸린 것과 구분하기 위해서다.
 *
 * 호환성 알림은 알림 목록 응답을 고정해 띄우고, 복구·닫기 등 쓰기 요청은 모두 가로채 기록만 한다.
 *
 * @scenario-manifest tests/scenarios/e2e-stage-role-lifecycle-gating.yaml
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';
import type { Page, Route } from '@playwright/test';

const RECOVER_ENDPOINT = '/api/admin/extensions/plugin/zz-e2e-888/recover';
const LOCKED_HINT = '검수 서버에서는 바꿀 수 없습니다. 배포 절차로 반영하세요.';

/** 받은 쓰기 요청 한 건 */
interface Captured {
  method: string;
  path: string;
}

/**
 * 호환성 알림 두 건 — 상단 경고(warning)와 시스템 알림 목록(error) 한 곳씩.
 *
 * @returns 알림 목록 응답 본문
 */
function alertsBody(): Record<string, unknown> {
  const alert = (type: string, title: string) => ({
    type,
    icon: 'exclamation-triangle',
    title,
    message: 'E2E 코어 버전이 올라 자동으로 꺼진 플러그인입니다.',
    time: '',
    extension_type: 'plugin',
    identifier: 'zz-e2e-888',
    recover_endpoint: RECOVER_ENDPOINT,
    abilities: { can_recover: true, can_dismiss: true },
  });
  return {
    success: true,
    data: [alert('warning', 'E2E 상단 호환성 경고'), alert('error', 'E2E 시스템 호환성 알림')],
  };
}

/**
 * 쓰기 요청을 모두 가로채 기록하고, 알림 목록 응답을 고정합니다. `stage` 면 셸의 실행 역할을 바꿉니다.
 *
 * Playwright 는 나중에 등록한 route 를 먼저 본다 — 안전망(모든 쓰기)을 먼저, 구체 경로를 뒤에 건다.
 *
 * @param page Playwright page
 * @param role 셸에 실을 실행 역할 (live 면 원본 그대로)
 * @param writes 받은 쓰기 요청 기록
 * @param shell 받은 원본 셸에 live 역할이 실려 있었는지 기록
 */
async function stubRoutes(
  page: Page,
  role: 'live' | 'stage',
  writes: Captured[],
  shell: { liveSeen: boolean },
): Promise<void> {
  await page.route('**/api/**', async (route: Route) => {
    const request = route.request();
    if (request.method() === 'GET') {
      await route.fallback();
      return;
    }
    writes.push({ method: request.method(), path: new URL(request.url()).pathname });
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }) });
  });

  await page.route(
    (url) => url.pathname === '/api/admin/dashboard/alerts',
    async (route: Route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(alertsBody()) });
    },
  );

  await page.route(
    (url) => url.pathname.startsWith('/admin'),
    async (route: Route) => {
      if (route.request().resourceType() !== 'document') {
        await route.fallback();
        return;
      }
      const response = await route.fetch();
      const html = await response.text();
      if (html.includes('"runtime_role":"live"')) shell.liveSeen = true;
      const body = role === 'stage' ? html.split('"runtime_role":"live"').join('"runtime_role":"stage"') : html;
      const headers = { ...response.headers() };
      delete headers['content-length'];
      delete headers['content-encoding'];
      await route.fulfill({ status: response.status(), headers, body });
    },
  );
}

/**
 * 대시보드를 열고 두 알림의 [다시 활성화] 단추가 그려질 때까지 기다립니다.
 *
 * @param page Playwright page
 * @param token 인증 토큰
 */
async function openDashboard(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.goto('/admin/dashboard');
  await expect(page.locator('#top_alert_recover_button_0')).toBeVisible({ timeout: 60_000 });
  await expect(page.locator('#alert_recover_button_0')).toBeVisible({ timeout: 30_000 });
}

/** 두 곳의 [다시 활성화] 단추 */
function recoverButtons(page: Page) {
  return [page.locator('#top_alert_recover_button_0'), page.locator('#alert_recover_button_0')];
}

test.describe('@sirsoft-admin_basic 검수 서버 — 대시보드 [다시 활성화] 잠금', () => {
  test.describe.configure({ timeout: 120_000 });

  /**
   * @scenario case=stage
   * @effects stage_shell_locks_dashboard_recover_buttons, stage_recover_click_sends_no_request, stage_banner_rendered_from_shell_role
   */
  test('셸의 실행 역할이 stage 면 두 [다시 활성화]가 disabled + 잠금 안내이고 눌러도 요청이 없다', async ({
    page,
    adminDashboardToken,
  }) => {
    const writes: Captured[] = [];
    const shell = { liveSeen: false };
    await stubRoutes(page, 'stage', writes, shell);

    await openDashboard(page, adminDashboardToken);

    // 원본 셸이 live 를 실어 왔고 그것을 stage 로 바꿨다 — 바꾼 값이 화면 전역까지 닿았는지 확인한다
    expect(shell.liveSeen).toBe(true);
    await expect.poll(() => page.evaluate(() => (window as any).G7Config?.appConfig?.runtime_role)).toBe('stage');
    await expect(page.locator('#stage_role_banner')).toBeVisible();

    for (const button of recoverButtons(page)) {
      await expect(button).toContainText('다시 활성화');
      await expect(button).toBeDisabled();
      await expect(button).toHaveAttribute('title', LOCKED_HINT);
      // 시각 비활성 꾸밈 — disabled 만 있고 모양이 그대로면 눌러지는 단추로 읽힌다
      await expect(button).toHaveCSS('opacity', '0.5');
      await expect(button).toHaveCSS('cursor', 'not-allowed');
      await button.click({ force: true });
    }
    // 알림 닫기는 수명주기 동작이 아니므로 잠그지 않는다
    await expect(page.locator('#top_alert_dismiss_button_0')).toBeEnabled();

    await page.waitForTimeout(500);
    expect(writes.filter((w) => w.path === RECOVER_ENDPOINT)).toEqual([]);
  });

  /**
   * @scenario case=live
   * @effects live_shell_keeps_dashboard_recover_enabled
   */
  test('셸을 바꾸지 않으면(live) 잠기지 않고 눌렀을 때 복구 요청이 나간다', async ({ page, adminDashboardToken }) => {
    const writes: Captured[] = [];
    const shell = { liveSeen: false };
    await stubRoutes(page, 'live', writes, shell);

    await openDashboard(page, adminDashboardToken);

    expect(shell.liveSeen).toBe(true);
    await expect.poll(() => page.evaluate(() => (window as any).G7Config?.appConfig?.runtime_role)).toBe('live');
    await expect(page.locator('#stage_role_banner')).toHaveCount(0);

    for (const button of recoverButtons(page)) {
      await expect(button).toBeEnabled();
      await expect(button).not.toHaveAttribute('title', LOCKED_HINT);
      await expect(button).toHaveCSS('opacity', '1');
    }

    await page.locator('#top_alert_recover_button_0').click();
    await expect
      .poll(() => writes.filter((w) => w.path === RECOVER_ENDPOINT && w.method === 'POST').length, { timeout: 20_000 })
      .toBe(1);
  });
});
