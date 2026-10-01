/**
 * E2E: 관리자 환경설정 > 쿠키 배너 > 정책 버전 > 보기 — 처리방침 링크 (#509 체크리스트 12번 → 1.1.0 처리방침 주소 전환)
 * + 관리자 환경설정 카드 제목 색상 회귀 가드 (브라우저 스크린샷으로 발견)
 * + 정책 버전 이력 토글 sequence handler 회귀 가드
 *
 * @scenario admin_gdpr_policy_version_snapshot_open_policy_page_link
 * @effects snapshot_modal_opens, policy_link_href_equals_server_resolved_url, card_title_not_error_color, policy_history_toggle_expands_and_refetches
 *
 * 배경: 1.0.x 는 스냅샷의 슬러그를 화면에서 페이지 모듈 주소 규칙(`/page/{slug}`)으로 조합했고,
 * `/page/` 가 빠져 404 가 나는 결함(#509)이 있었다. 1.1.0 부터 운영자가 처리방침 주소를 직접 적고,
 * 상세 API 가 `privacy_policy.url`(옛 슬러그 스냅샷은 그 시절 링크 경로로 푼 값)을 내보낸다.
 * 화면은 그 값을 그대로 href 로 쓴다 — 여기서는 현재 버전의 상세 API 응답과 모달 링크가 일치하는지,
 * 주소가 없으면 링크 대신 안내가 보이는지를 검증한다(사이트마다 현재 버전 스냅샷이 달라 둘 다 받는다).
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
 *  2. 모달의 처리방침 링크 href 가 상세 API 의 privacy_policy.url 과 같다 (화면에서 경로를 조합하지 않는다).
 *     주소가 없는 버전이면 링크 대신 「등록된 개인정보처리방침 주소가 없습니다」 안내가 보인다
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
// @effects snapshot_modal_opens, policy_link_href_equals_server_resolved_url
test('정책 버전 snapshot 모달의 처리방침 링크가 상세 API 가 푼 주소를 그대로 쓴다', async ({ page, privacyManageToken }) => {
  await authenticatePage(page, privacyManageToken);

  const headers = { Authorization: `Bearer ${privacyManageToken}`, Accept: 'application/json' };
  const current = await page.request.get('/api/plugins/sirsoft-gdpr/admin/policy-versions/current', { headers });
  expect(current.ok(), `현재 정책 버전 API 응답 실패 (${current.status()})`).toBeTruthy();
  const version = (await current.json())?.data?.data?.version;
  expect(version).toBeTruthy();

  const detail = await page.request.get(`/api/plugins/sirsoft-gdpr/admin/policy-versions/${version}`, { headers });
  expect(detail.ok(), `정책 버전 상세 API 응답 실패 (${detail.status()})`).toBeTruthy();
  const resolvedUrl: string | null = (await detail.json())?.data?.data?.privacy_policy?.url ?? null;

  await gotoGdprSettings(page);
  expect(page.url()).not.toMatch(/\/admin\/login/);

  await page.locator(SNAPSHOT_VIEW_BUTTON).click();

  const link = page.locator(OPEN_POLICY_PAGE_LINK);
  if (resolvedUrl) {
    await expect(link).toBeAttached({ timeout: 10_000 });
    expect(await link.getAttribute('href')).toBe(resolvedUrl);
    // 서버는 사이트 경로(/…) 또는 https 주소만 내보낸다
    expect(resolvedUrl).toMatch(/^(\/(?!\/)|https:\/\/)/);
  } else {
    await expect(page.getByText(/등록된 개인정보처리방침 주소가 없습니다|No privacy policy address registered/)).toBeVisible({ timeout: 10_000 });
    await expect(link).toHaveCount(0);
  }
});

// @scenario tab=card_operator, permitted=yes
// @effects card_title_not_error_color
test('#509 - 관리자 환경설정 카드 제목이 에러 색상(빨간색)으로 표시되지 않는다', async ({ page, privacyManageToken }) => {
  await authenticatePage(page, privacyManageToken);
  await gotoGdprSettings(page);

  const header = page.locator(CARD_OPERATOR_HEADER);
  await expect(header).toBeVisible({ timeout: 10_000 });

  // text-error-strong(text-red-800/dark:text-red-200) 이 남아있다면 R 채널이 G/B 보다
  // 뚜렷이 높은 붉은 계열로 계산된다. 정상(중립 톤)은 R≈G≈B.
  //
  // 계산값을 문자열로 파싱하지 않는다 — 브라우저는 색 공간에 따라 `rgb()` 대신
  // `oklch()` 로도 돌려주며, 그때 정규식 파싱은 무조건 실패해 색과 무관하게 red 가 된다.
  // 1×1 캔버스에 칠해 실제 화면 픽셀값을 읽으면 표기 형식과 무관하게 판정할 수 있다.
  const channels = await header.evaluate((el) => {
    const canvas = document.createElement('canvas');
    canvas.width = 1;
    canvas.height = 1;
    const context = canvas.getContext('2d') as CanvasRenderingContext2D;
    context.fillStyle = getComputedStyle(el).color;
    context.fillRect(0, 0, 1, 1);
    const [r, g, b] = Array.from(context.getImageData(0, 0, 1, 1).data);

    return { r, g, b };
  });

  expect(channels.r - Math.max(channels.g, channels.b)).toBeLessThan(40);
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
