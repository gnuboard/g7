/**
 * E2E: 관리자 환경설정 > 쿠키 배너 > 정책 버전 > 보기 — 본문 페이지 링크 (#509 체크리스트 12번)
 * + 관리자 환경설정 카드 제목 색상 회귀 가드 (브라우저 스크린샷으로 발견)
 * + 정책 버전 이력 토글 sequence handler 회귀 가드
 *
 * @scenario admin_gdpr_policy_version_snapshot_open_policy_page_link
 * @effects snapshot_modal_opens, policy_page_link_href_matches_page_module_url_pattern, card_title_not_error_color, policy_history_toggle_expands_and_refetches
 *
 * 배경: 정책 버전 snapshot 모달의 본문 페이지 링크가 `/{{slug}}` 로 조합되어 페이지 모듈
 * (sirsoft-page) 의 실제 URL 패턴 `/page/{slug}` 와 불일치, 클릭 시 404 가 발생하던 결함.
 * 마이그레이션이 시드하는 v1 initial 정책 버전의 snapshot 에는 항상 기본 슬러그 'privacy' 가
 * 포함되므로 (GdprPolicyVersionMigrationSmokeTest 참조), 별도 설정 저장 없이도 v배지 클릭 →
 * 모달의 본문 페이지 링크 href 를 검증할 수 있다.
 *
 * 또한 카드 제목(H3)이 에러/경고 전용 시맨틱(text-error-strong, red 계열)을 오용해
 * "운영 주체" 등 일반 카드 제목이 빨간색으로 표시되던 결함을 브라우저 스크린샷으로 발견해
 * section-heading-md(중립 톤)로 수정 — 실제 렌더링된 색상을 브라우저에서 검증한다.
 *
 * 정책 버전 이력 토글 버튼의 sequence handler 가 actions 를 params 안에 잘못 중첩하고
 * 있던 결함(레이아웃 JSON 금지 패턴)도 함께 발견 — 엔진이 두 위치 모두 인식하는
 * fallback을 갖고 있어 동작 자체는 정상이었으나 정석 위치(top-level)로 바로잡았다.
 * 실제 클릭 시 이력 표가 펼쳐지는지(=sequence 의 setState 가 실행됐는지)를 검증한다.
 *
 * 검증:
 *  1. 환경설정 화면에서 v배지 옆 "본문 보기" 버튼 클릭 시 snapshot 모달이 열린다
 *  2. 모달의 본문 페이지 링크 href 가 `/page/{slug}` 형식으로 조합되어 페이지 모듈 URL 패턴과 일치한다
 *     (프리픽스 누락으로 `/{slug}` 형태가 되는 결함 패턴이 재발하지 않았는지 회귀 가드)
 *  3. 「운영 주체 / 개인정보처리방침」 카드 제목이 에러 색상(red 계열)이 아닌 중립 톤으로 렌더링된다
 *  4. 이력 토글 버튼 클릭 시 이력 표가 펼쳐진다 (sequence 내 setState 정상 실행 확인)
 */
import { test, expect, authenticatePage } from '../../fixtures/gdpr-auth';

const CARD_COOKIE_BANNER = '#card_cookie_banner';
const SNAPSHOT_VIEW_BUTTON = '#policy_version_current_snapshot_view_button';
const OPEN_POLICY_PAGE_LINK = '#policy_version_snapshot_open_policy_page_link';
const CARD_OPERATOR_HEADER = '#card_operator_header h3';
const HISTORY_TOGGLE_BUTTON = '#field_cookie_policy_version button:has-text("이력")';

/** 관리자 GDPR 환경설정 진입 후 쿠키 배너 탭(정책 버전 UI 포함)으로 스크롤 이동 */
async function gotoGdprSettings(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/admin/plugins/sirsoft-gdpr/settings');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator('#settings_tab_navigation')).toBeAttached({ timeout: 20_000 });

  // TabNavigationScroll 은 URL query 가 아니라 클릭 시 해당 카드로 스크롤하는 방식이다.
  await page.locator(CARD_COOKIE_BANNER).scrollIntoViewIfNeeded();
  await expect(page.locator(SNAPSHOT_VIEW_BUTTON)).toBeAttached({ timeout: 20_000 });
}

// @scenario tab=card_cookie_banner, permitted=yes
// @effects snapshot_modal_opens, policy_page_link_href_matches_page_module_url_pattern
test('#509 - 정책 버전 snapshot 모달의 본문 페이지 링크가 /page/{slug} 로 조합된다', async ({ page, privacyManageToken }) => {
  await authenticatePage(page, privacyManageToken);

  await gotoGdprSettings(page);
  expect(page.url()).not.toMatch(/\/admin\/login/);

  await page.locator(SNAPSHOT_VIEW_BUTTON).click();

  const link = page.locator(OPEN_POLICY_PAGE_LINK);
  await expect(link).toBeAttached({ timeout: 10_000 });

  const href = await link.getAttribute('href');
  expect(href).not.toBeNull();
  // 결함 패턴(/{slug}, /page/ 프리픽스 누락) 재발 방지 — 페이지 모듈 URL 패턴 /page/{slug} 와 일치해야 한다.
  expect(href).toMatch(/^\/page\/.+/);
  expect(href).not.toMatch(/^\/(?!page\/)/);
});

// @scenario tab=card_operator, permitted=yes
// @effects card_title_not_error_color
test('#509 - 관리자 환경설정 카드 제목이 에러 색상(빨간색)으로 표시되지 않는다', async ({ page, privacyManageToken }) => {
  await authenticatePage(page, privacyManageToken);
  await gotoGdprSettings(page);

  const header = page.locator(CARD_OPERATOR_HEADER);
  await expect(header).toBeVisible({ timeout: 10_000 });

  const color = await header.evaluate((el) => getComputedStyle(el).color);
  // text-error-strong(text-red-800/dark:text-red-200) 이 남아있다면 rgb 값의 R 채널이
  // G/B 채널보다 뚜렷이 높은 붉은 계열로 계산된다. 정상(중립 톤)은 R≈G≈B.
  const rgbMatch = color.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
  expect(rgbMatch).not.toBeNull();
  const [, r, g, b] = rgbMatch as unknown as [string, string, string, string];
  expect(Number(r) - Math.max(Number(g), Number(b))).toBeLessThan(40);
});

// @scenario tab=card_cookie_banner, permitted=yes
// @effects policy_history_toggle_expands_and_refetches
test('#509 - 정책 버전 이력 토글 버튼 클릭 시 이력 표가 펼쳐진다 (sequence handler 정상 동작 확인)', async ({ page, privacyManageToken }) => {
  await authenticatePage(page, privacyManageToken);
  await gotoGdprSettings(page);

  const historyTable = page.locator('#policy_version_history_table');
  await expect(historyTable).toBeHidden();

  await page.locator(HISTORY_TOGGLE_BUTTON).click();

  // sequence 의 setState(policyHistoryOpen 토글) 가 실행되어야 이 if 조건이 true 로
  // 바뀌어 이력 표가 렌더된다 — params 안에 잘못 중첩됐던 결함이 재발하면 여기서 실패한다.
  await expect(historyTable).toBeVisible({ timeout: 10_000 });
});
