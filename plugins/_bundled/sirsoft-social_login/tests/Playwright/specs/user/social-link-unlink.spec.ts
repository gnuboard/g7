/**
 * 마이페이지 연결 관리 종단 검증 — 비밀번호 없는 회원이 네이버를 연결하고, 연결을 해제하고, 마지막 남은 연결을 해제하려 하면
 * 서버가 막고(409) 카드가 비밀번호를 먼저 정하라고 안내한다.
 */
import { test, expect } from '@playwright/test';
import { authenticatePage } from '../../../../../../../tests/Playwright/fixtures/auth';
import { approveMock, dismissCookieBanner, members, mockOff, mockOn, runId } from '../../fixtures/mock-provider';

test.describe.configure({ mode: 'serial' });

const id = runId();
const email = `e2e40.link.${id}@example.test`;
let token: string;

test.beforeAll(() => {
  members('purge');
  members('member', email);
  members('link', email, 'mock_kakao', `e2e-link-kakao-${id}`);
  token = members('token', email);
  mockOn();
});

test.afterAll(() => {
  members('purge');
  mockOff();
});

/**
 * 연결 해제 확인 창을 거쳐 해제를 요청하고 응답을 돌려줍니다.
 *
 * @param page 페이지
 * @param provider 제공자 키
 * @returns 해제 응답
 */
async function unlink(page: import('@playwright/test').Page, provider: string) {
  await dismissCookieBanner(page);
  await page.locator(`#social_account_unlink_${provider}`).click();
  const dialog = page.getByRole('dialog').filter({ hasText: '소셜 계정 연결 해제' });
  await expect(dialog).toBeVisible();
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().includes(`/me/accounts/${provider}`) && r.request().method() === 'DELETE'),
    dialog.getByRole('button', { name: '연결 해제' }).click(),
  ]);
  // 닫히는 창이 남아 있으면 다음 해제가 그 창의 버튼을 누른다 — 닫힘까지 기다린다.
  await expect(dialog).toBeHidden();

  return response;
}

/**
 * @scenario provider=mock_naver, password=none, links=1|2
 * @effects e2e_link_adds_row, e2e_unlink_removes_row, e2e_last_method_409_notice, e2e_row_shows_provider_symbol
 */
test('네이버 연결 → 해제 → 마지막 연결 해제는 막히고 비밀번호 설정 안내가 보인다', async ({ page }) => {
  await authenticatePage(page, token);
  await page.goto('/mypage/profile');
  const card = page.locator('#social_accounts_card');
  await expect(card.locator('li')).toHaveCount(1, { timeout: 15_000 });
  await dismissCookieBanner(page);

  await card.locator('button[data-social-provider="mock_naver"]').click();
  await approveMock(page, { subject: `e2e-link-naver-${id}` });
  await expect(page).toHaveURL(/\/mypage\/profile\?social=linked&provider=mock_naver/);
  await expect(card.locator('li')).toHaveCount(2, { timeout: 15_000 });

  // 연결 줄마다 로그인 버튼과 같은 공식 심볼이 제공자 색 원 안에 보인다(모의 제공자도 원 제공자 계열).
  const naverMark = card.locator('li', { has: page.locator('#social_account_unlink_mock_naver') }).locator('[data-social-provider-mark="naver"]');
  await expect(naverMark.locator('svg')).toBeVisible();
  expect(await naverMark.evaluate((el) => getComputedStyle(el).backgroundColor)).toBe('rgb(3, 169, 77)');
  await expect(card.locator('li', { has: page.locator('#social_account_unlink_mock_kakao') }).locator('[data-social-provider-mark="kakao"] svg')).toBeVisible();

  expect((await unlink(page, 'mock_naver')).status()).toBe(200);
  await expect(card.locator('li')).toHaveCount(1, { timeout: 10_000 });

  const last = await unlink(page, 'mock_kakao');
  expect(last.status()).toBe(409);
  expect((await last.json()).errors.code).toBe('last_method');
  await expect(page.locator('#social_accounts_last_method')).toBeVisible();
  await expect(page.locator('#social_accounts_set_password')).toBeEnabled();
  await expect(card.locator('li')).toHaveCount(1);
});
