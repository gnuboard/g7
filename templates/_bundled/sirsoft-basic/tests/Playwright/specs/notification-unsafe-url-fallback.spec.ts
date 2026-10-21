/**
 * 알림 목록 — 이 사이트 밖·실행 주소인 알림은 알림 목록으로 간다 (7.1.1).
 * 템플릿 sirsoft-basic (유저 화면 `/mypage/notifications`).
 *
 * 결함: 알림의 이동 주소가 안전하지 않으면(실행 주소·다른 사이트) 엔진 navigate 가 막기만 하고 착지점이 없었다 —
 *   카드 링크에 `fallbackHref`, 클릭 navigate 에 `fallbackPath="/mypage/notifications"` 를 붙였다.
 * 결함: `fallbackHref` 판정이 `https://이사이트//evil` 처럼 같은 사이트 주소 뒤에 `//host` 가 붙은 주소를 같은 사이트로
 *   통과시켜, 링크 href 에 바깥 주소가 남았다(엔진 navigate 판정과 불일치). `navigationLink.hasProtocolRelativePath` 로 막았다.
 *
 * 데이터: 알림 목록 응답(`GET /api/user/notifications`)을 `page.route` 로 바꾼다 — 모두 읽은 알림·수정 권한 없음으로 두어
 *   읽음 처리 요청이 나가지 않고, 그 밖의 쓰기 요청도 가로챈다(서버 불변). 계정은 실행 환경변수로만 받는다.
 *
 * @scenario-manifest tests/scenarios/e2e-notification-unsafe-url-fallback.yaml
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; npm run test:e2e -- notification-unsafe-url-fallback --workers=1
 */
import { test, expect, type Page } from '@playwright/test';
import {
  ACCOUNT_SKIP_REASON,
  authenticateWith,
  dismissCookieBanner,
  envAccountToken,
  guardWrites,
  hasEnvAccount,
  waitForSpa,
} from '../fixtures/env-account';

test.describe.configure({ timeout: 90_000 });

/** 알림 목록(착지점) 경로 */
const LIST_PATH = '/mypage/notifications';

/**
 * 모의 알림 행을 만든다(읽은 알림 — 클릭해도 읽음 처리 요청이 없다).
 *
 * @param id 알림 ID
 * @param subject 제목(카드 링크의 접근 이름)
 * @param url 이동 주소
 * @returns 알림 행
 */
function row(id: string, subject: string, url: string) {
  return {
    id,
    type: 'system',
    type_label: '시스템',
    subject,
    body: `${subject} 본문`,
    url,
    read_at: '2026-10-18T00:00:00+09:00',
    created_at: '2026-10-18T00:00:00+09:00',
    created_at_human: '방금',
  };
}

/**
 * 알림 목록 응답을 모의한다.
 *
 * @param page Playwright 페이지
 * @param rows 알림 행
 */
async function stubNotifications(page: Page, rows: ReturnType<typeof row>[]): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/user/notifications',
    (route) => {
      if (route.request().method() !== 'GET') return route.fallback();

      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          data: {
            data: rows,
            current_page: 1,
            last_page: 1,
            per_page: 20,
            total: rows.length,
            abilities: { can_update: false, can_delete: false },
          },
        }),
      });
    },
  );
}

test.describe('알림 목록 — 안전하지 않은 이동 주소의 착지점', () => {
  test.skip(!hasEnvAccount(), ACCOUNT_SKIP_REASON);

  /**
   * @scenario case=unsafe_urls
   * @effects unsafe_notification_href_is_list, unsafe_notification_click_lands_on_list, no_external_open
   */
  test('실행 주소·다른 사이트·//host·/\\host 알림은 href 와 클릭 모두 알림 목록이다', async ({ page, request, baseURL }) => {
    const origin = new URL(baseURL!).origin;
    const unsafe = [
      row('e2e-f41-1', 'E2E 실행 주소 알림', 'javascript:alert(1)'),
      row('e2e-f41-2', 'E2E 다른 사이트 알림', 'https://example.com/e2e-f41'),
      row('e2e-f41-3', 'E2E 같은 사이트 이중 슬래시 알림', `${origin}//evil.example/x`),
      row('e2e-f41-4', 'E2E 같은 사이트 역슬래시 알림', `${origin}/\\evil.example/y`),
    ];
    const token = await envAccountToken(request);
    await authenticateWith(page, token!);
    await guardWrites(page);
    await stubNotifications(page, unsafe);
    let dialogs = 0;
    page.on('dialog', (d) => {
      dialogs += 1;
      void d.dismiss();
    });
    let popups = 0;
    page.context().on('page', () => {
      popups += 1;
    });

    await page.goto(LIST_PATH);
    await waitForSpa(page);
    await dismissCookieBanner(page);

    for (const n of unsafe) {
      await expect(page.getByRole('link', { name: n.subject, exact: true }), `${n.subject} 링크`).toHaveAttribute('href', LIST_PATH, { timeout: 20_000 });
    }

    let documents = 0;
    page.on('request', (r) => {
      if (r.resourceType() === 'document') documents += 1;
    });
    for (const n of unsafe) {
      await page.getByRole('link', { name: n.subject, exact: true }).click();
      await page.waitForTimeout(800);
      expect(new URL(page.url()).origin, `${n.subject} 클릭 뒤 같은 사이트`).toBe(origin);
      expect(new URL(page.url()).pathname, `${n.subject} 클릭 뒤 착지점`).toBe(LIST_PATH);
    }
    expect(documents, '문서 이동 없음').toBe(0);
    expect(popups, '새 창 없음').toBe(0);
    expect(dialogs, '실행 주소 대화상자 없음').toBe(0);
  });

  /**
   * @scenario case=safe_url_control
   * @effects safe_notification_href_kept
   */
  test('대조군 — 이 사이트 안 주소 알림의 href 는 그 주소 그대로다', async ({ page, request }) => {
    const token = await envAccountToken(request);
    await authenticateWith(page, token!);
    await guardWrites(page);
    await stubNotifications(page, [row('e2e-f41-ok', 'E2E 주문 내역 알림', '/mypage/orders')]);

    await page.goto(LIST_PATH);
    await waitForSpa(page);

    await expect(page.getByRole('link', { name: 'E2E 주문 내역 알림', exact: true })).toHaveAttribute('href', '/mypage/orders', { timeout: 20_000 });
  });
});
