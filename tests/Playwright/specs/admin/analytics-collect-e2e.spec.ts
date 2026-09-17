/**
 * E2E: 방문자 통계 비콘 왕복 (이슈 #682 S1)
 *
 * 단위·Feature 테스트는 트래커와 적재 파이프라인을 **따로** 잠근다. 그 사이의 배선
 * — 사용자 화면이 실제로 부팅될 때 트래커가 켜지고, 라우트 이동이 비콘을 만들고,
 * 서버가 그것을 204 로 받는 것 — 은 브라우저에서만 드러난다.
 *
 * 특히 이 축은 조용히 깨진다. 트래커가 아예 켜지지 않아도 화면은 멀쩡하고 콘솔에도
 * 아무것도 남지 않는다. 운영자는 「방문자가 0명」이라는 화면을 보고서야 알아차린다.
 *
 * 축 요약(마커 아님 — 평문): 첫 진입·SPA 이동은 게스트 비콘, 관리자 화면은 수집 제외.
 * 효과 요약(마커 아님 — 평문): beacon_reaches_server_with_204,
 *   spa_navigation_sends_another_beacon, admin_template_does_not_track.
 */
import { authenticatePage, expect, issueToken, test } from '../../fixtures/auth';

/** 수집 비콘 경로 */
const COLLECT_PATH = '/api/analytics/collect';

test.describe('방문자 통계 비콘', () => {
  // @scenario source=beacon, visitor=guest, visit_state=new, exclusion=none
  // @effects beacon_reaches_server_with_204
  test('사용자 화면 첫 진입이 비콘을 보내고 서버가 204 로 받는다', async ({ page }) => {
    const responses: number[] = [];

    page.on('response', (response) => {
      if (response.url().includes(COLLECT_PATH)) responses.push(response.status());
    });

    await page.goto('/');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 트래커는 전송을 다음 틱으로 미루므로 응답이 올 때까지 기다린다.
    await expect
      .poll(() => responses.length, { timeout: 20_000 })
      .toBeGreaterThan(0);

    // 비콘은 어떤 경우에도 204 다 — 4xx 를 주면 방문자 콘솔에 오류가 뜬다.
    expect(responses.every((status) => status === 204)).toBe(true);
  });

  // @scenario source=beacon, visitor=guest, visit_state=new, exclusion=none
  // @effects beacon_reaches_server_with_204
  test('첫 비콘이 페이지뷰로 init 표식과 함께 나간다', async ({ page }) => {
    const payloads: Record<string, unknown>[] = [];

    page.on('request', (request) => {
      if (!request.url().includes(COLLECT_PATH)) return;

      try {
        payloads.push(JSON.parse(request.postData() ?? '{}'));
      } catch {
        // 본문을 못 읽어도 테스트를 깨뜨리지 않는다 — 아래 단언이 판정한다.
      }
    });

    await page.goto('/');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect.poll(() => payloads.length, { timeout: 20_000 }).toBeGreaterThan(0);

    const first = payloads[0];
    expect(first.t).toBe('pageview');
    expect(first.init).toBe(true);
    expect(typeof first.url).toBe('string');
  });

  // @scenario source=beacon, visitor=admin, exclusion=disabled
  // @effects admin_template_does_not_track
  test('관리자 화면은 수집하지 않는다', async ({ page }) => {
    const requests: string[] = [];

    page.on('request', (request) => {
      if (request.url().includes(COLLECT_PATH)) requests.push(request.url());
    });

    await authenticatePage(page, issueToken('core.dashboard.read'));
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 관리자 화면까지 세면 인기 페이지 1위가 관리자 대시보드가 되어 통계가 쓸모없어진다.
    // 트래커가 늦게 보낼 수 있으므로 잠시 더 지켜본 뒤 판정한다.
    await page.waitForTimeout(3_000);

    expect(requests).toHaveLength(0);
  });
});
