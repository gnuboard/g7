/**
 * E2E: 환경설정 > 일반 — 자산 URL 방식 "자동 감지" 버튼 (#486)
 *
 * @scenario admin_settings_asset_url_mode_detect
 * @effects detect_button_styled, detect_result_inline
 *
 * 배경: 자동 감지 버튼이 다른 버튼(모달 취소·푸터 저장)과 달리 btn 베이스 클래스가 빠져
 * 평문처럼 보였고, 감지 결과가 토스트로 떠 사라져 버렸다. 버튼을 다른 버튼과 동일한 박스
 * 스타일로 맞추고, 결과를 필드 하단 인라인 안내로 상시 노출하도록 바꾼 것을 브라우저에서 확인한다.
 *
 * 검증:
 *  1. 감지 버튼이 btn 베이스 클래스를 갖고, 실제 렌더 박스가 관리자 다른 버튼과 동형이다
 *  2. 버튼을 누르면 결과가 필드 하단 인라인 안내로 뜨고(토스트가 아니라), $t: 원문이 아닌 번역문이다
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const FIELD = '#field_asset_url_mode';
const DETECT_BUTTON = '#asset_url_mode_detect_button';
const RESULT_IDS = [
  '#asset_url_mode_msg_extension',
  '#asset_url_mode_msg_extensionless',
  '#asset_url_mode_msg_unavailable',
];

/** 관리자 환경설정 일반 탭 진입 */
async function gotoGeneralTab(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/admin/settings?tab=general');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator(DETECT_BUTTON)).toBeAttached({ timeout: 20_000 });
}

// @scenario tab=general, permitted=yes
// @effects detect_button_styled
test('#486 - 자동 감지 버튼이 관리자 다른 버튼과 동일한 btn 박스 스타일이다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoGeneralTab(page);
  expect(page.url()).not.toMatch(/\/admin\/login/);

  const button = page.locator(DETECT_BUTTON);
  await expect(button).toBeAttached();

  // 에셋 서빙 방식은 언어/시간대와 무관한 인프라 설정이므로 지역화 카드가 아니라
  // 전용 카드(#card_asset_serving)에 있어야 한다 — 배치 회귀 가드.
  await expect(page.locator('#card_asset_serving')).toBeAttached();
  await expect(page.locator(`#card_asset_serving ${FIELD}`)).toBeAttached();
  await expect(page.locator(`#card_localization ${FIELD}`)).toHaveCount(0);

  // btn 베이스 클래스 누락이 이 이슈의 원인이었다 (평문처럼 보임) — 클래스 회귀 가드.
  await expect(button).toHaveClass(/\bbtn\b/);
  await expect(button).toHaveClass(/btn-secondary/);

  // 렌더 박스가 실제로 버튼처럼 보이는지: 테두리 또는 배경이 있어야 한다 (평문 텍스트가 아님).
  const looksLikeButton = await button.evaluate((el) => {
    const s = getComputedStyle(el as HTMLElement);
    const hasBorder = s.borderTopWidth !== '0px' && s.borderTopStyle !== 'none';
    const hasBg = s.backgroundColor !== 'rgba(0, 0, 0, 0)' && s.backgroundColor !== 'transparent';
    const hasPad = parseFloat(s.paddingLeft) > 0 && parseFloat(s.paddingTop) > 0;
    return (hasBorder || hasBg) && hasPad;
  });
  expect(looksLikeButton, '감지 버튼이 박스(테두리/배경/패딩) 없이 평문처럼 렌더된다').toBe(true);
});

// @scenario tab=general, permitted=yes
// @effects detect_result_inline
test('#486 - 감지 결과가 토스트가 아니라 필드 하단 인라인 안내로 뜬다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoGeneralTab(page);

  // 감지 전에는 어떤 결과 안내도 떠 있지 않다 (상시 노출이 아니라 감지 후에만).
  for (const id of RESULT_IDS) {
    await expect(page.locator(id)).toHaveCount(0);
  }

  await page.locator(DETECT_BUTTON).click();

  // 결과 안내가 자산 URL 필드 안에(= 필드 하단 인라인) 뜬다. 환경에 따라 판정이 다르므로
  // 세 결과 중 하나가 필드 내부에 나타나는 것으로 확인한다.
  const inlineResult = page.locator(RESULT_IDS.map((id) => `${FIELD} ${id}`).join(', '));
  await expect(inlineResult.first()).toBeVisible({ timeout: 15_000 });

  // 인라인 문구는 다국어 키가 아니라 번역문이어야 한다 (회귀 가드).
  const text = (await inlineResult.first().innerText()).trim();
  expect(text).not.toContain('$t:');
  expect(text.length).toBeGreaterThan(0);

  // 결과가 토스트로는 발화되지 않는다 — 토스트 호스트에 감지 문구가 실리지 않는다.
  const toastHost = page.locator('#global_toast, [data-testid="toast"], .toast');
  if (await toastHost.count()) {
    await expect(toastHost).not.toContainText(text);
  }
});
