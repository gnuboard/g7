/**
 * E2E 회귀: 환경설정 > 인증 > 정책 관리 — 검색 버튼/검색 초기화
 *
 * 결함(2026-08-22 실측): 검색 버튼이 refetchDataSource 만 호출해 입력 중인 검색어가
 * URL 쿼리로 실리지 않았다 — Enter 로는 검색되는데 버튼으로는 직전 검색어로만 재조회됐다.
 * 검색 초기화는 URL·목록은 비웠지만 입력창 텍스트가 설정 폼 자동바인딩(filter.search)에
 * 남아 초기화되지 않은 것처럼 보였다.
 *
 * 고정 계약:
 *  1. 검색 버튼 클릭 = Enter 와 동일하게 입력값을 쿼리로 실어 이동 (재검색 포함)
 *  2. 검색 초기화 = URL 쿼리 + 입력창 텍스트 모두 비움
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const PERMISSIONS = [
  'core.settings.read',
  'core.settings.update',
  'core.admin.identity.policies.read',
] as const;

const SEARCH_INPUT = 'input[name="filter.search"]';

async function gotoPoliciesTab(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/admin/settings?tab=identity&sub_tab=policies');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator(SEARCH_INPUT)).toBeAttached({ timeout: 20_000 });
}

test('정책 검색 버튼이 입력 중인 검색어를 반영하고, 검색어를 바꿔 다시 눌러도 재검색된다', async ({ page }) => {
  await authenticatePage(page, issueToken(...PERMISSIONS));
  await gotoPoliciesTab(page);

  const searchButton = page
    .locator('#tab_content_identity_policies button', { hasText: '검색' })
    .first();

  await page.locator(SEARCH_INPUT).fill('회원가입');
  await searchButton.click();
  await expect(page).toHaveURL(/search=%ED%9A%8C%EC%9B%90%EA%B0%80%EC%9E%85/, { timeout: 15_000 });

  // 회귀 축: 검색어를 바꾼 뒤 버튼으로 재검색 — 종전에는 URL 이 그대로였고
  // 직전 검색어(회원가입)로만 재조회됐다.
  await page.locator(SEARCH_INPUT).fill('주문');
  await searchButton.click();
  await expect(page).toHaveURL(/search=%EC%A3%BC%EB%AC%B8/, { timeout: 15_000 });
});

test('검색 초기화가 URL 쿼리와 입력창 텍스트를 모두 비운다', async ({ page }) => {
  await authenticatePage(page, issueToken(...PERMISSIONS));
  await gotoPoliciesTab(page);

  await page.locator(SEARCH_INPUT).fill('회원가입');
  await page.locator(SEARCH_INPUT).press('Enter');
  await expect(page).toHaveURL(/search=/, { timeout: 15_000 });

  // 초기화 직전에 다른 검색어를 입력해 둔다 — 종전에는 이 텍스트가
  // 설정 폼 자동바인딩에 남아 초기화 후에도 입력창에 되살아났다.
  await page.locator(SEARCH_INPUT).fill('주문');

  await page
    .locator('#tab_content_identity_policies button', { hasText: '검색 초기화' })
    .click();

  await expect(page).not.toHaveURL(/search=/, { timeout: 15_000 });
  await expect(page.locator(SEARCH_INPUT)).toHaveValue('', { timeout: 10_000 });
});
