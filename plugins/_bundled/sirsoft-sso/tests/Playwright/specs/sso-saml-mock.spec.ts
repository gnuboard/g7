/**
 * 모의 SAML IdP 로그인 종단 검증 — 회사 계정 버튼에서 Redirect 바인딩 AuthnRequest 로 모의 IdP 에 가서 승인하면 ACS 로 응답이 자동 제출되고,
 * 첫 로그인 동의를 거쳐 자동 가입·연결·로그인된다. 서명·조건이 어긋난 응답과 요청하지 않은 응답은 로그인되지 않는다. SP 메타데이터는 XML 이다.
 * (계획서 §10 M2)
 *
 * SAML 은 콜백(ACS) 주소의 호스트에 밑줄이 있으면 라이브러리 주소 검사에 걸린다 — 밑줄 없는 호스트의 사이트에서 돌린다.
 */
import { test, expect, type Page } from '@playwright/test';
import { MOCK_SAML, OWNED_DOMAIN, completeConsent, decideMockSaml, dismissCookieBanner, memberState, mockOff, mockSamlOn, runId, sso } from '../fixtures/sso';

test.describe.configure({ mode: 'serial' });

const id = runId();
const jitEmail = `e2e798.saml.${id}@${OWNED_DOMAIN}`;
const jitNameId = `e2e798-saml-${id}`;
let idpBefore: Record<string, unknown>;

test.beforeAll(() => {
  sso('purge');
  mockSamlOn();
  idpBefore = JSON.parse(sso('idp-get', MOCK_SAML));
  // 이 스펙은 SP 가 시작한 흐름과 「IdP 에서 시작 꺼짐」을 잰다 — 자동 가입·동의 받음으로 맞춘다.
  sso('idp-put', MOCK_SAML, JSON.stringify({ enabled: true, provisioning_mode: 'jit', require_consent: true, idp_initiated: false }));
});

test.afterAll(() => {
  if (idpBefore) sso('idp-put', MOCK_SAML, JSON.stringify(idpBefore));
  sso('purge');
  mockOff();
});

/**
 * 로그인 화면에서 모의 SAML 버튼을 눌러 모의 승인 화면까지 갑니다.
 *
 * @param page 페이지
 */
async function startSamlLogin(page: Page): Promise<void> {
  await page.goto('/login');
  await dismissCookieBanner(page);
  const button = page.locator(`#sso_login_button_${MOCK_SAML}`);
  await expect(button).toBeVisible({ timeout: 15_000 });
  await Promise.all([page.waitForURL(/\/mock\/saml\/sso\?/), button.click()]);
}

/**
 * @scenario case=authn_request
 * @effects e2e_saml_button_sends_redirect_binding_authn_request
 */
test('SAML 버튼은 Redirect 바인딩 AuthnRequest 로 모의 IdP 에 가고, 요청 ID 와 이 사이트 ACS 주소가 승인 화면에 실린다', async ({ page }) => {
  await startSamlLogin(page);
  expect(new URL(page.url()).searchParams.get('SAMLRequest')).toBeTruthy();
  expect(await page.locator('input[name="request_id"]').inputValue()).not.toBe('');
  const acs = await page.locator('input[name="acs"]').inputValue();
  expect(new URL(acs).pathname).toBe(`/plugins/sirsoft-sso/saml/${MOCK_SAML}/acs`);
  expect(new URL(acs).host).toBe(new URL(test.info().project.use.baseURL!).host);
});

/**
 * @scenario case=jit_consent
 * @effects e2e_saml_acs_consent_creates_member_and_logs_in
 */
test('승인하면 ACS 가 응답을 받아 동의 모달로 보내고, 동의하면 비밀번호 없는 회원으로 가입·연결되어 로그인된다', async ({ page }) => {
  await startSamlLogin(page);
  const [acs] = await Promise.all([
    page.waitForResponse((r) => new URL(r.url()).pathname === `/plugins/sirsoft-sso/saml/${MOCK_SAML}/acs`),
    decideMockSaml(page, { email: jitEmail, nameId: jitNameId, name: 'SAML 직원' }),
  ]);
  expect(acs.status()).toBe(302);
  await expect(page).toHaveURL(/\/login\?sso_pending=/, { timeout: 15_000 });
  await expect(page.locator('#sso_consent_form')).toContainText('SAML 직원');
  expect(await completeConsent(page)).toEqual({ complete: 200, handoff: 200 });
  await page.waitForFunction(() => !location.pathname.startsWith('/login'), undefined, { timeout: 15_000 });

  const state = memberState(jitEmail);
  expect(state.has_password).toBe(false);
  expect(state.links).toEqual([{ idp: MOCK_SAML, linked_via: 'jit' }]);
  expect(state.consents).toBe(2);

  // 새로고침해도 같은 회원으로 로그인돼 있다.
  const before = await page.request.get('/api/auth/user', { headers: { Accept: 'application/json', Authorization: `Bearer ${await page.evaluate(() => localStorage.getItem('auth_token'))}` } });
  expect(before.status()).toBe(200);
  const [after] = await Promise.all([
    page.waitForResponse((r) => new URL(r.url()).pathname === '/api/auth/user'),
    page.reload(),
  ]);
  expect(after.status()).toBe(200);
  expect((await after.json()).data?.uuid).toBe((await before.json()).data?.uuid);
});

for (const variant of ['unsigned', 'wrong_key', 'expired', 'audience_other', 'in_response_to_mismatch']) {
  /**
   * @scenario case=response_variant
   * @effects e2e_invalid_saml_response_refused
   */
  test(`서명·조건이 어긋난 응답(${variant})은 saml_invalid 로 돌아오고 회원이 생기지 않는다`, async ({ page }) => {
    const email = `e2e798.samlbad.${variant}.${id}@${OWNED_DOMAIN}`;
    await startSamlLogin(page);
    await decideMockSaml(page, { variant, email, nameId: `e2e798-samlbad-${variant}-${id}` });
    await page.waitForURL(/\/login\?sso_error=saml_invalid/, { timeout: 15_000 });
    expect(memberState(email).exists).toBe(false);
  });
}

/**
 * @scenario case=unsolicited
 * @effects e2e_idp_initiated_refused_when_off
 */
test('「IdP 에서 시작」이 꺼져 있으면 요청하지 않은 응답은 saml_unsolicited 로 돌아온다', async ({ page }) => {
  await page.goto(`/plugins/sirsoft-sso/mock/saml/initiate?idp=${MOCK_SAML}`);
  await decideMockSaml(page, { email: jitEmail, nameId: jitNameId });
  await page.waitForURL(/\/login\?sso_error=saml_unsolicited/, { timeout: 15_000 });
  expect(await page.evaluate(() => !!localStorage.getItem('auth_token'))).toBe(false);
});

/**
 * @scenario case=sp_metadata
 * @effects e2e_sp_metadata_is_xml_with_acs
 */
test('SP 메타데이터는 XML 로 EntityID 와 ACS 주소를 낸다', async ({ request }) => {
  const response = await request.get(`/plugins/sirsoft-sso/saml/${MOCK_SAML}/metadata`);
  expect(response.status()).toBe(200);
  expect(response.headers()['content-type']).toContain('xml');
  const xml = await response.text();
  expect(xml).toContain(`entityID="${new URL(`/plugins/sirsoft-sso/saml/${MOCK_SAML}/metadata`, test.info().project.use.baseURL!).href}"`);
  expect(xml).toContain(`/plugins/sirsoft-sso/saml/${MOCK_SAML}/acs`);
});
