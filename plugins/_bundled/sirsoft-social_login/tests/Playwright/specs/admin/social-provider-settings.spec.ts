/**
 * 관리자 제공자 설정 종단 검증 — 자격증명 저장과 마스킹, 일반 OIDC 추가와 연결 테스트와 삭제, 조회 전용 관리자의 읽기 전용 화면.
 *
 * 제공자 표 행과 플러그인 설정 파일은 `environment.php` 가 시작 전에 보관하고 끝나면 그대로 되돌린다.
 * 연결 테스트는 외부 제공자에 닿지 않도록 이 기기의 닫힌 포트(127.0.0.1:9)를 가리키는 일반 OIDC 로 한다 — 서명 키 목록을 받지 못한다는
 * 결과가 카드 아래에 떠야 한다. 개발 사이트는 자체 서명 인증서라 서버가 자기 주소의 모의 discovery 를 읽지 못하므로(cURL 60),
 * discovery 는 실패 안내만 보고 주소는 직접 입력한다(성공 경로는 PHPUnit `ProviderControllerTest::test_discover`).
 */
import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { authenticatePage, issueToken, issueScopedToken } from '../../../../../../../tests/Playwright/fixtures/auth';

test.describe.configure({ mode: 'serial' });

const fixture = fileURLToPath(new URL('../../fixtures/environment.php', import.meta.url));
const SETTINGS = '/admin/plugins/sirsoft-social_login/settings';
const MASK = '••••••••';
const CLOSED = 'https://127.0.0.1:9';
const CUSTOM_KEY = 'e2e40_corp';
let admin: string;

/**
 * 설정 보관·복원 fixture 를 실행합니다.
 *
 * @param action prepare | restore
 * @returns 출력
 */
function environment(action: string): string {
  return execFileSync('php', [fixture, action], { env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' }, encoding: 'utf8' });
}

test.beforeAll(() => {
  environment('restore');
  environment('prepare');
  admin = issueToken();
});

test.afterAll(() => {
  console.log(environment('restore'));
});

/**
 * 관리자 토큰으로 설정 화면 제공자 탭을 엽니다.
 *
 * @param page 페이지
 * @param token 토큰
 */
async function openProviders(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
  const list = page.waitForResponse((r) => r.url().includes('/api/plugins/sirsoft-social_login/admin/providers') && r.request().method() === 'GET');
  await page.goto(SETTINGS);
  expect((await list).status()).toBe(200);
  await expect(page.locator('#provider_card_kakao')).toBeVisible();
}

/**
 * 조작 뒤 나가는 플러그인 관리자 API 응답을 받습니다.
 *
 * @param page 페이지
 * @param method HTTP 메서드
 * @param path 경로 일부
 * @param act 요청을 일으키는 조작
 * @returns 응답 상태 코드
 */
async function adminCall(page: Page, method: string, path: string, act: () => Promise<void>): Promise<number> {
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().includes(`/api/plugins/sirsoft-social_login/admin/${path}`) && r.request().method() === method),
    act(),
  ]);

  return response.status();
}

/**
 * 카카오 자격증명을 저장하면 편집 칸이 닫히고, 새로고침 뒤 다시 열면 클라이언트 ID 는 그대로이고 비밀값은 마스크로만 보인다.
 * 페이지 어디에도 평문 비밀값이 없다.
 *
 * @scenario surface=provider_settings, provider=kakao, action=save_credentials
 * @effects provider_credentials_saved, provider_secret_masked_after_reload, provider_editor_closes_after_save
 */
test('credentials are saved and the secret comes back masked after reload', async ({ page }) => {
  await openProviders(page, admin);
  await page.locator('#edit_provider_button_kakao').click();
  const editor = page.locator('#provider_editor_kakao');
  await expect(editor).toBeVisible();
  // 바꾸기 전에는 [저장] 이 꺼져 있다
  await expect(page.locator('#editor_save_button_kakao')).toBeDisabled();
  await editor.locator('input[name="client_id"]').fill('e2e40-kakao-rest');
  await editor.locator('input[name="client_secret"]').fill('e2e40-kakao-secret-value');
  await editor.locator('input[name="scopes_text"]').fill('profile_nickname account_email');

  expect(await adminCall(page, 'PUT', 'providers/kakao', () => page.locator('#editor_save_button_kakao').click())).toBe(200);
  await expect(editor).toHaveCount(0);

  await page.reload();
  await expect(page.locator('#provider_card_kakao')).toBeVisible();
  await page.locator('#edit_provider_button_kakao').click();
  await expect(editor.locator('input[name="client_id"]')).toHaveValue('e2e40-kakao-rest');
  await expect(editor.locator('input[name="client_secret"]')).toHaveValue(MASK);
  await expect(editor.locator('input[name="scopes_text"]')).toHaveValue('profile_nickname account_email');
  expect(await page.content()).not.toContain('e2e40-kakao-secret-value');
});

/**
 * 켜진 카카오를 끄려고 [저장]을 누르면 카카오 카드에 영향 패널과 [계속 저장]이 뜬다. 그 상태로 다른 카드 편집을 열거나 편집 칸을
 * [취소]하면 패널이 닫힌다 — 남아 있으면 [계속 저장]이 지금 열린 다른 카드 편집 칸을 저장하고 카카오는 켜진 채 남는다.
 * [영향 보기]로 연 수치 패널([계속 저장] 없음)은 다른 카드 편집을 열어도 남는다. 패널에서 바로 [계속 저장]하면 카카오가 꺼진다.
 *
 * @scenario surface=provider_settings, provider=kakao, action=disable_with_impact
 * @effects impact_panel_closes_when_editor_changes, impact_continue_saves_own_provider
 */
test('impact confirmation stays with its own provider editor', async ({ page }) => {
  await openProviders(page, admin);
  const kakaoEditor = page.locator('#provider_editor_kakao');
  const confirm = page.locator('#impact_confirm_button_kakao');
  const kakaoCard = page.locator('#provider_card_kakao');

  // 켜려면 자격증명이 있어야 한다 — 이 시험만 돌려도 서도록 여기서 넣는다
  await page.locator('#edit_provider_button_kakao').click();
  await kakaoEditor.locator('input[name="client_id"]').fill('e2e40-kakao-impact');
  await kakaoEditor.locator('input[name="client_secret"]').fill('e2e40-kakao-impact-secret');
  await kakaoEditor.locator('input[name="enabled"]').check();
  expect(await adminCall(page, 'PUT', 'providers/kakao', () => page.locator('#editor_save_button_kakao').click())).toBe(200);
  await expect(kakaoEditor).toHaveCount(0);
  // 목록을 다시 받은 뒤에 연다 — 그 전에 열면 편집 칸이 꺼진 값으로 열린다
  await expect(kakaoCard).toContainText('사용 중');

  /** 카카오 편집 칸에서 켜기를 끄고 [저장] — 영향 패널과 [계속 저장]이 뜬다. */
  const askDisable = async (): Promise<void> => {
    await page.locator('#edit_provider_button_kakao').click();
    await kakaoEditor.locator('input[name="enabled"]').uncheck();
    expect(await adminCall(page, 'GET', 'providers/kakao/impact', () => page.locator('#editor_save_button_kakao').click())).toBe(200);
    await expect(confirm).toBeVisible();
  };

  await askDisable();
  await page.locator('#edit_provider_button_google').click();
  await expect(page.locator('#provider_editor_google')).toBeVisible();
  await expect(page.locator('#provider_impact_panel_kakao')).toHaveCount(0);
  await page.locator('#editor_cancel_button_google').click();

  await askDisable();
  await page.locator('#editor_cancel_button_kakao').click();
  await expect(kakaoEditor).toHaveCount(0);
  await expect(page.locator('#provider_impact_panel_kakao')).toHaveCount(0);

  expect(await adminCall(page, 'GET', 'providers/naver/impact', () => page.locator('#impact_button_naver').click())).toBe(200);
  await page.locator('#edit_provider_button_google').click();
  await expect(page.locator('#provider_impact_panel_naver')).toBeVisible();
  await expect(page.locator('#impact_confirm_button_naver')).toHaveCount(0);
  await page.locator('#editor_cancel_button_google').click();

  await askDisable();
  expect(await adminCall(page, 'PUT', 'providers/kakao', () => confirm.click())).toBe(200);
  await expect(page.locator('#provider_impact_panel_kakao')).toHaveCount(0);
  await expect(kakaoCard).toContainText('꺼짐');
});

/**
 * 일반 OIDC — discovery 를 못 읽으면 오류 안내만 뜨고, 주소를 직접 넣어 추가하면 꺼진 카드가 생긴다. 연결 테스트는 서명 키 목록을
 * 받지 못했다고 카드 아래에 알리고, 삭제하면 카드가 사라진다.
 *
 * @scenario surface=provider_settings, provider=custom_oidc, discovery=unreachable
 * @effects custom_provider_added_disabled, custom_provider_test_reports_jwks_unreachable, custom_provider_deleted, discovery_failure_reported, provider_editor_state_not_carried_over, add_form_cleared_after_close
 */
test('custom OIDC provider can be added, tested and deleted', async ({ page }) => {
  await openProviders(page, admin);
  await page.locator('#toggle_add_button').click();
  const form = page.locator('#provider_add_form');
  await expect(form).toBeVisible();
  await form.locator('input[name="key"]').fill(CUSTOM_KEY);
  // 표시 이름은 다국어 입력(언어마다 `label[{로케일}]`) — 사이트 기본 언어 칸만 채워도 추가된다.
  const label = form.locator('input[name="label[ko]"]');
  await label.fill('E2E 사내 계정');
  await label.blur();
  await form.locator('input[name="client_id"]').fill('e2e40-corp-client');
  await form.locator('input[name="client_secret"]').fill('e2e40-corp-secret');

  await form.locator('input[name="discovery_url"]').fill(`${CLOSED}/.well-known/openid-configuration`);
  expect(await adminCall(page, 'POST', 'providers/discover', () => page.locator('#add_discover_button').click())).toBe(422);
  await expect(page.getByText('discovery 문서를 받지 못했습니다', { exact: false }).first()).toBeVisible();

  await form.locator('input[name="discovery_url"]').fill('');
  await form.locator('input[name="issuer"]').fill(CLOSED);
  await form.locator('input[name="authorize_url"]').fill(`${CLOSED}/authorize`);
  await form.locator('input[name="token_url"]').fill(`${CLOSED}/token`);
  await form.locator('input[name="userinfo_url"]').fill(`${CLOSED}/userinfo`);
  await form.locator('input[name="jwks_url"]').fill(`${CLOSED}/jwks`);
  expect(await adminCall(page, 'POST', 'providers', () => page.locator('#add_submit_button').click())).toBe(201);

  const card = page.locator(`#provider_card_${CUSTOM_KEY}`);
  await expect(card).toBeVisible();
  await expect(card).toContainText('E2E 사내 계정');
  await expect(card).toContainText('꺼짐');
  await expect(form).toHaveCount(0);

  // 추가 폼은 추가 성공, [취소], [추가] 단추로 닫기 뒤 비어 있다 — 남아 있으면 다음 제공자가 앞 제공자의 비밀값·주소로 저장된다.
  const addFormValues = async (): Promise<string[]> =>
    Promise.all(['key', 'label[ko]', 'client_id', 'client_secret', 'issuer', 'token_url'].map((name) => form.locator(`input[name="${name}"]`).inputValue()));
  const empty = ['', '', '', '', '', ''];
  await page.locator('#toggle_add_button').click();
  expect(await addFormValues()).toEqual(empty);
  await form.locator('input[name="key"]').fill('e2e40_draft');
  await form.locator('input[name="client_secret"]').fill('e2e40-draft-secret');
  await form.locator('input[name="client_secret"]').blur();
  await form.getByRole('button', { name: '취소' }).click();
  await page.locator('#toggle_add_button').click();
  expect(await addFormValues()).toEqual(empty);
  await form.locator('input[name="key"]').fill('e2e40_draft');
  await form.locator('input[name="key"]').blur();
  await page.locator('#toggle_add_button').click();
  await expect(form).toHaveCount(0);
  await page.locator('#toggle_add_button').click();
  expect(await addFormValues()).toEqual(empty);
  await page.locator('#toggle_add_button').click();
  await expect(form).toHaveCount(0);

  // 앞서 연 카드의 값이 다음 편집 칸에 남지 않는다 — 카카오(이름 ko·en·ja)를 열었다 닫은 뒤 이 카드(ko 만)를 고쳐 저장하면
  // 이 카드의 이름만 간다(편집 칸 상태가 깊은 병합이면 카카오의 en·ja 이름이 섞여 저장된다).
  await page.locator('#edit_provider_button_kakao').click();
  await expect(page.locator('#provider_editor_kakao')).toBeVisible();
  await page.locator('#editor_cancel_button_kakao').click();
  await page.locator(`#edit_provider_button_${CUSTOM_KEY}`).click();
  const editor = page.locator(`#provider_editor_${CUSTOM_KEY}`);
  await expect(editor).toBeVisible();
  await editor.locator('input[name="client_id"]').fill('e2e40-corp-client-2');
  const [put] = await Promise.all([
    page.waitForRequest((r) => r.url().includes(`/api/plugins/sirsoft-social_login/admin/providers/${CUSTOM_KEY}`) && r.method() === 'PUT'),
    page.locator(`#editor_save_button_${CUSTOM_KEY}`).click(),
  ]);
  const sent = put.postDataJSON();
  expect([sent.label?.en ?? '', sent.label?.ja ?? '', sent.label?.ko]).toEqual(['', '', 'E2E 사내 계정']);
  await expect(editor).toHaveCount(0);

  expect(await adminCall(page, 'POST', `providers/${CUSTOM_KEY}/test`, () => page.locator(`#test_provider_button_${CUSTOM_KEY}`).click())).toBe(200);
  await expect(page.locator(`#provider_test_result_${CUSTOM_KEY}`)).toContainText('서명 키 목록(JWKS)을 받지 못했습니다.');

  page.once('dialog', (dialog) => dialog.accept());
  expect(await adminCall(page, 'DELETE', `providers/${CUSTOM_KEY}`, () => page.locator(`#delete_provider_button_${CUSTOM_KEY}`).click())).toBe(200);
  await expect(card).toHaveCount(0);
});

/**
 * 플러그인 설정 조회 권한만 있는 관리자는 제공자 카드를 볼 수만 있다 — 안내 띠가 뜨고 편집·연결 테스트 단추가 꺼져 있으며, 조회인 영향
 * 보기는 쓸 수 있다. 화면은 쓰는 두 GET 과 같은 권한(`settings.view` + 코어 `core.plugins.read`)으로 열린다 — 변경 권한
 * (`settings.update`, 코어 `core.plugins.update`)이 하나도 없는 관리자다. 정책 탭도 안내 띠와 함께 저장이 꺼진다.
 *
 * @scenario surface=provider_settings, actor=viewer
 * @effects provider_settings_read_only_for_viewer, settings_opens_read_only_for_view_permission
 */
test('settings viewer sees provider cards read only', async ({ page }) => {
  const viewer = issueScopedToken('sirsoft-social_login.settings.view', 'core.plugins.read');
  await openProviders(page, viewer);
  await expect(page.locator('#providers_read_only_banner')).toContainText('설정 변경 권한이 없어 볼 수만 있습니다.');
  await expect(page.locator('#edit_provider_button_kakao')).toBeDisabled();
  await expect(page.locator('#test_provider_button_kakao')).toBeDisabled();
  await expect(page.locator('#toggle_add_button')).toBeDisabled();
  await expect(page.locator('#impact_button_kakao')).toBeEnabled();
  expect(await adminCall(page, 'GET', 'providers/kakao/impact', () => page.locator('#impact_button_kakao').click())).toBe(200);

  await page.goto(`${SETTINGS}?tab=policy`);
  await expect(page.locator('#plugin_read_only_banner')).toContainText('이 설정을 바꿀 권한이 없습니다. 값만 볼 수 있습니다.');
  await expect(page.locator('select[name="email_conflict_policy"]')).toBeDisabled();
  await expect(page.locator('#save_button')).toBeDisabled();
});
