/**
 * 마이페이지 게시판 활동 검색칸 Enter 검색 (7.1.0 실측 O-K3-10).
 *
 * 결함: 「내가 쓴 글」「내가 쓴 댓글」「내 반응」「내 신고」 검색칸에서 Enter 를 쳐도 아무 요청이 나가지 않고
 *   옆 「검색」 버튼만 동작했다(스크랩 목록은 이미 Enter 로 검색한다).
 *
 *   실측(2026-10-08, 반영 전): 네 탭 모두 Enter 뒤 그 검색어로 나간 목록 요청 0건.
 *   반영 뒤: 탭마다 `GET …?search=<검색어>… → 200` 정확히 1건, 한글 조합 중 Enter 0건.
 *
 * 단위/정적:
 *   - templates/_bundled/sirsoft-basic/__tests__/layouts/mypage-board-search-enter.test.tsx 가 네 조각의
 *     Enter 액션이 검색 버튼과 같은 순서(쪽수 1·적용 검색어 → 같은 데이터소스 재조회)인지 잠근다.
 *   이 spec 은 브라우저 수준 — **실제 키 입력이 실제 요청 한 건으로 이어지는지**를 담당한다.
 *
 * 토큰: `G7_E2E_TOKEN` 이 있으면 그 회원으로(실측 계정 재사용), 없으면 `issueToken()` 으로 발급한다.
 *
 * @scenario tab=posts
 * @axes source=screen
 * @effects mypage_activity_enter_runs_search_button_sequence
 */
import { test, expect, type Page } from '@playwright/test';

import { issueToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

/** 하위 탭 — 버튼을 찾는 방법과 그 탭의 목록 API 경로 */
const TABS: Array<{ name: string; open: (page: Page) => Promise<void>; api: string }> = [
  { name: '내가 쓴 글', open: async () => {}, api: '/me/board-activities' },
  { name: '내가 쓴 댓글', open: async (page) => page.locator('button', { hasText: '내가 쓴 댓글' }).first().click(), api: '/me/my-comments' },
  { name: '내 반응', open: async (page) => page.getByTestId('mypage-subtab-my-reactions').click(), api: '/me/reactions' },
  { name: '내 신고', open: async (page) => page.getByTestId('mypage-subtab-my-reports').click(), api: '/me/reports' },
];

/**
 * GDPR 쿠키 동의 배너를 닫습니다(없으면 조용히 지나간다).
 *
 * @param page 대상 페이지
 */
async function dismissCookieBanner(page: Page): Promise<void> {
  const accept = page.getByRole('button', { name: '모두 동의' }).first();

  try {
    await accept.waitFor({ state: 'visible', timeout: 3000 });
  } catch {
    return;
  }

  await accept.click();
  await accept.waitFor({ state: 'hidden', timeout: 5000 });
}

test.describe('O-K3-10 마이페이지 게시판 활동 검색칸 Enter', () => {
  // 같은 회원의 전역 검색 상태를 쓰는 네 탭이라 순서대로 돈다. 탭 진입 lazy 조회가 끝날 때까지 기다리므로 시간을 넉넉히 둔다.
  test.describe.configure({ mode: 'serial', timeout: 90000 });

  for (const tab of TABS) {
    /**
     * @scenario tab=posts
     *
     * @effects mypage_activity_enter_runs_search_button_sequence
     */
    test(`${tab.name}: 검색칸 Enter 가 그 검색어로 목록을 한 번 다시 받는다`, async ({ page }) => {
      await page.setViewportSize({ width: 1280, height: 900 });
      await authenticatePage(page, process.env.G7_E2E_TOKEN || issueToken());
      await page.goto('/mypage/board');
      await dismissCookieBanner(page);
      await page.waitForLoadState('networkidle');
      await tab.open(page);
      await page.waitForLoadState('networkidle');
      // 탭 진입 직후 lazy 조회가 끝나기 전에 치면 그 응답이 입력칸을 다시 그린다
      await page.waitForTimeout(1500);

      // 헤더 통합 검색칸이 아니라, 검색 버튼과 한 줄에 놓인 탭 검색칸
      const input = page.locator('div:has(> button) > input[type="text"]:visible').first();
      await expect(input).toBeVisible({ timeout: 15000 });

      const keyword = `엔터${Date.now() % 100000}`;
      const requests: string[] = [];
      page.on('request', (r) => {
        const url = decodeURIComponent(r.url());
        if (url.includes(tab.api) && url.includes(`search=${keyword}&`)) requests.push(url);
      });

      await input.click();
      await input.fill('');
      await page.keyboard.insertText(keyword);

      const response = page.waitForResponse(
        (r) => r.url().includes(tab.api) && decodeURIComponent(r.url()).includes(`search=${keyword}&`),
        { timeout: 10000 },
      );
      await page.keyboard.press('Enter');

      expect((await response).status(), `${tab.name} 검색 응답`).toBe(200);
      await page.waitForTimeout(1000);
      expect(requests.length, `${tab.name} Enter 한 번에 나간 목록 요청 수`).toBe(1);
    });
  }
});
