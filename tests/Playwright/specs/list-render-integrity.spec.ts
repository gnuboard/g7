/**
 * E2E: 목록이 "행은 있는데 화면에는 없거나 값이 비는" 결함 회귀 (#492 6차 브라우저 실측)
 *
 * 세 결함 모두 콘솔 에러 없이 조용히 나타나므로 API 응답만 봐서는 드러나지 않는다.
 * 응답과 화면을 같은 시점에 대조해야만 잡힌다.
 *
 *   (1) iteration 배열 키를 `source` 아닌 이름으로 적으면 행이 **하나도** 렌더되지 않는다
 *       — 엔진은 `iteration.source` 만 읽고, 배열이 아니면 조용히 null 을 반환한다
 *   (2) 목록 `per_page` 에 상·하한이 없으면 0/음수/과대값이 500·전량조회로 새어 나간다
 *
 * 미읽음 건수 라벨 결함(D-31)은 미읽음 1건 이상인 계정이 있어야 비어 있지 않은 판정이 되어
 * 환경 의존이 크다. 바인딩 경로는 레이아웃 선언이므로
 * templates/_bundled/sirsoft-basic/__tests__/layouts/mypage-notifications-unread-binding.test.ts
 * 에서 정적으로 고정한다.
 *
 * @scenario case=list_render_matches_response
 *
 * @effects iteration_renders_all_rows, per_page_out_of_range_rejected
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage, issueToken } from '../fixtures/auth';

/**
 * 화면 진입 공통 대기.
 *
 * `networkidle` 은 알림 폴링이 있는 화면에서 idle 이 되지 않으므로 쓰지 않는다.
 *
 * @param page 대상 페이지
 * @param path 이동할 경로
 * @returns void
 */
async function gotoAndSettle(page: Page, path: string): Promise<void> {
  await page.goto(path);
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(1200);
}

test.describe('목록 렌더 정합', () => {
  test('반복 렌더 목록이 응답 건수만큼 화면에 나온다', async ({ page }) => {
    await authenticatePage(page, issueToken('gnuboard7-hello_module.memos.read'));

    // 고정 대기 대신 응답 자체를 기다린다 — 고정 대기는 느린 회차에서 조용히 skip 으로 새어
    // "초록인데 아무것도 검사하지 않은" 결과를 만든다.
    const responsePromise = page
      .waitForResponse(
        (response) =>
          response.url().includes('/gnuboard7-hello_module/admin/memos') && response.status() === 200,
        { timeout: 20000 }
      )
      .catch(() => null);

    await page.goto('/admin/memos');
    await page.waitForLoadState('domcontentloaded');
    const response = await responsePromise;

    test.skip(response === null, '관리자 메모 목록 응답을 받지 못했습니다 (모듈 미활성 가능)');

    const body = await response!.json();
    const apiCount = (body?.data?.data ?? []).length;

    test.skip(apiCount === 0, '메모 데이터가 없어 렌더 건수를 대조할 수 없습니다');

    // 응답 도착 후 렌더가 끝나기를 기다린다
    await page.waitForSelector('#memo_list_wrapper h2', { timeout: 15000 });

    // iteration 키가 틀리면 응답에 행이 있어도 화면 렌더 건수가 0 이 된다
    const rendered = await page.locator('#memo_list_wrapper h2').count();
    expect(rendered, 'API 응답 건수와 화면 렌더 건수가 다릅니다').toBe(apiCount);

    // 반복 렌더된 요소의 HTML id 는 행마다 달라야 한다
    const ids = await page.locator('#memo_list_wrapper [id^="memo_items_"]').evaluateAll(
      (nodes) => nodes.map((n) => n.id)
    );
    expect(new Set(ids).size, '반복 렌더 요소의 id 가 중복됩니다').toBe(ids.length);
  });

  test('목록 per_page 는 허용 범위를 벗어나면 거부된다', async ({ page }) => {
    const token = issueToken();
    await authenticatePage(page, token);

    const endpoints = [
      '/api/modules/sirsoft-ecommerce/wishlist',
      '/api/modules/sirsoft-ecommerce/user/inquiries',
    ];

    for (const endpoint of endpoints) {
      for (const value of ['0', '-5', 'abc', '99999']) {
        const response = await page.request.get(`${endpoint}?per_page=${value}`, {
          headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
        });
        expect(
          response.status(),
          `${endpoint} 가 per_page=${value} 를 거부하지 않았습니다`
        ).toBe(422);
      }

      const ok = await page.request.get(`${endpoint}?per_page=10`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
      expect(ok.status(), `${endpoint} 가 정상 per_page 를 거부했습니다`).toBe(200);
    }
  });
});
