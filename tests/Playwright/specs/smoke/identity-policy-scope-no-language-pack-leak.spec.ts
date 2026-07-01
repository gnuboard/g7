/**
 * Smoke: 환경설정 정책/언어팩 데이터소스가 같은 query.scope 를 공유해 양방향으로 422 토스트를
 *        유발하던 회귀 (#415).
 *
 * 버그:
 *   admin_settings.json 에 정책(policies)·언어팩(language_packs) 데이터소스가 공존하며 둘 다
 *   query.scope 를 읽고 if 없이 항상 fetch 되었다. scope 값 도메인이 달라(정책=route/hook/custom,
 *   언어팩=core/module/plugin/template) 한 탭의 필터가 반대쪽 데이터소스로 새어 422 토스트가
 *   양방향으로 발생했다.
 *     - 본인인증 탭 scope=hook   → 언어팩 API 422
 *     - 언어팩 탭   scope=module → 정책 API 422
 *
 * 수정 (3안 — 데이터소스 활성 탭 if):
 *   policies → if 활성탭==='identity', language_packs → if 활성탭==='language_packs'.
 *   탭 전환은 navigate(?tab=X) 를 동반하므로 if 가 재평가되어 해당 탭 데이터소스만 fetch 된다.
 *   결과적으로 반대쪽 데이터소스는 요청 자체가 발생하지 않아 scope 누출/422 가 원천 차단된다.
 *
 * 검증은 page.on('request') 로 실제 발사된 요청을 모두 캡처하고, 해당 탭의 주 데이터소스 응답을
 * waitForResponse 로 기다린 뒤 판정한다 (고정 sleep 은 토큰 주입 환경의 느린 부트스트랩에서
 * 데이터 미완료 시점에 단언될 수 있어 불안정 — 응답 대기로 대체).
 *
 * @scenario source=settings-tab-scope-filter axis=tab:identity,tab:language_packs
 * @effects no-cross-tab-422, no-error-toast, per-tab-datasource-isolation
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const SCOPE_ERROR_TEXT = '선택한 scope이(가) 올바르지 않습니다';

/** 환경설정 + 언어팩 + 본인인증 정책을 모두 다루는 권한 토큰 */
function settingsToken(): string {
  return issueToken('core.settings.read', 'core.language_packs.read', 'core.admin.identity.policies.read');
}

/** 정책 목록(per_page=10)/언어팩 API 로 나간 모든 요청 URL + 422 응답을 캡처한다. (navigate 전에 호출) */
function captureRequests(page: import('@playwright/test').Page) {
  const policyReqs: string[] = [];
  const langPackReqs: string[] = [];
  const any422: string[] = [];
  page.on('request', (req) => {
    const url = req.url();
    // adminIdentityPolicies(source_type=admin&per_page=100) 는 별도 데이터소스이므로 제외하고,
    // 화면 목록용 policies 데이터소스 요청(per_page=10)만 집계한다.
    if (url.includes('/api/admin/identity/policies') && /[?&]per_page=10\b/.test(url)) {
      policyReqs.push(url);
    }
    if (url.includes('/api/admin/language-packs')) {
      langPackReqs.push(url);
    }
  });
  page.on('response', (res) => {
    const url = res.url();
    if (
      (url.includes('/api/admin/language-packs') || url.includes('/api/admin/identity/policies')) &&
      res.status() === 422
    ) {
      any422.push(url);
    }
  });
  return { policyReqs, langPackReqs, any422 };
}

test('@smoke 본인인증 탭 scope=hook → 언어팩 누출/422/토스트 없음 + 정책 필터(scope=hook) 동작 + 언어팩 미fetch', async ({ page }) => {
  const token = settingsToken();
  await authenticatePage(page, token);
  const cap = captureRequests(page);

  // 본인인증 탭의 주 데이터소스(정책 목록, per_page=10)가 응답할 때까지 대기 → 데이터 로딩 완료 보장
  const policyResp = page.waitForResponse(
    (r) => r.url().includes('/api/admin/identity/policies') && /[?&]per_page=10\b/.test(r.url()),
    { timeout: 25_000 },
  );

  await page.goto('/admin/settings?tab=identity&sub_tab=policies&scope=hook');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  expect(page.url()).not.toMatch(/\/admin\/login/);
  await policyResp;
  await page.waitForTimeout(800); // 잔여 데이터소스 정착

  // 언어팩 데이터소스는 본인인증 탭에서 fetch 자체가 발생하면 안 됨(탭 격리) → 누출/422 원천 차단
  expect(cap.langPackReqs, `본인인증 탭에서 언어팩 API 가 호출됨: ${cap.langPackReqs.join(', ')}`).toHaveLength(0);
  expect(cap.any422, `422 응답 발생: ${cap.any422.join(', ')}`).toHaveLength(0);
  await expect(page.getByText(SCOPE_ERROR_TEXT, { exact: false })).toHaveCount(0);

  // 정책 필터는 정상 — 정책 목록 요청이 scope=hook 으로 발사됨
  expect(
    cap.policyReqs.some((u) => /[?&]scope=hook\b/.test(u)),
    `정책 API 가 scope=hook 으로 요청되어야 함(필터 보존): ${cap.policyReqs.join(', ')}`,
  ).toBe(true);
});

test('@smoke 언어팩 탭 scope=module → 정책 누출/422/토스트 없음 + 언어팩 필터(scope=module) 동작 + 정책 미fetch', async ({ page }) => {
  const token = settingsToken();
  await authenticatePage(page, token);
  const cap = captureRequests(page);

  // 언어팩 탭의 주 데이터소스(언어팩, scope=module)가 응답할 때까지 대기
  const langResp = page.waitForResponse(
    (r) => r.url().includes('/api/admin/language-packs') && /[?&]scope=module\b/.test(r.url()),
    { timeout: 25_000 },
  );

  await page.goto('/admin/settings?tab=language_packs&scope=module');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  expect(page.url()).not.toMatch(/\/admin\/login/);
  await langResp;
  await page.waitForTimeout(800);

  // 정책 목록 데이터소스는 언어팩 탭에서 fetch 자체가 발생하면 안 됨(탭 격리)
  expect(cap.policyReqs, `언어팩 탭에서 정책 목록 API 가 호출됨: ${cap.policyReqs.join(', ')}`).toHaveLength(0);
  expect(cap.any422, `422 응답 발생: ${cap.any422.join(', ')}`).toHaveLength(0);
  await expect(page.getByText(SCOPE_ERROR_TEXT, { exact: false })).toHaveCount(0);

  // 언어팩 필터는 정상 — 언어팩 요청이 scope=module 으로 발사됨
  expect(
    cap.langPackReqs.some((u) => /[?&]scope=module\b/.test(u)),
    `언어팩 API 가 scope=module 으로 요청되어야 함(필터 보존): ${cap.langPackReqs.join(', ')}`,
  ).toBe(true);
});

/**
 * 탭 클릭 전환(SPA replace:true navigate) 경로 회귀.
 *
 * URL 직접 진입(새로고침)은 fresh 부팅이라 데이터소스 if 가 항상 새로 평가되어 결함이 드러나지
 * 않는다. 결함은 한 탭에서 다른 탭으로 "버튼 클릭" 전환할 때만 발생했다(updateQueryParams 가
 * 직전 진입 시점 필터링 스냅샷을 재사용 → 새 탭 데이터소스 미fetch). 따라서 반드시 탭 버튼 클릭
 * 으로 전환하는 경로를 검증한다. (단위 테스트만으로 판정 금지 — 브라우저 회귀 방어)
 */
async function gotoSettled(
  page: import('@playwright/test').Page,
  url: string,
  primaryRespMatcher: (r: import('@playwright/test').Response) => boolean,
) {
  const primaryResp = page.waitForResponse(primaryRespMatcher, { timeout: 25_000 });
  await page.goto(url);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  expect(page.url()).not.toMatch(/\/admin\/login/);
  await primaryResp; // 해당 탭 주 데이터소스 응답까지 대기(텍스트 가시성보다 안정적)
  await page.waitForTimeout(500);
}

const policyListMatcher = (r: import('@playwright/test').Response) =>
  r.url().includes('/api/admin/identity/policies') && /[?&]per_page=10\b/.test(r.url());
const langPackMatcher = (r: import('@playwright/test').Response) =>
  r.url().includes('/api/admin/language-packs') && /[?&]exclude_protected/.test(r.url());

test('@smoke 본인인증 탭 → 언어팩 탭 "클릭 전환" 시 언어팩 데이터소스가 fetch 된다 (탭 전환 동적 반영)', async ({ page }) => {
  const token = settingsToken();
  await authenticatePage(page, token);
  // 탭 버튼명이 로케일에 따라 달라지므로 한국어로 고정(셀렉터 안정화).
  await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));

  // 1) 본인인증 탭으로 먼저 진입(정책 데이터소스가 선택된 스냅샷 상태)
  await gotoSettled(page, '/admin/settings?tab=identity&sub_tab=policies', policyListMatcher);

  // 2) 언어팩 탭 버튼을 실제로 클릭 → 그 시점에 언어팩 API 가 호출되어야 함
  const langPackResp = page.waitForResponse(
    (r) => r.url().includes('/api/admin/language-packs') && /[?&]exclude_protected/.test(r.url()),
    { timeout: 20_000 },
  );
  await page.getByRole('button', { name: '언어팩', exact: true }).click();
  await langPackResp; // 클릭 전환 시 언어팩 데이터소스 fetch 가 발생하지 않으면 여기서 timeout

  expect(page.url()).toMatch(/[?&]tab=language_packs/);
  await expect(page.getByText('선택한 scope이(가) 올바르지 않습니다', { exact: false })).toHaveCount(0);
});

test('@smoke 언어팩 탭 → 본인인증 탭 "클릭 전환" 시 정책 데이터소스가 fetch 된다 (탭 전환 동적 반영)', async ({ page }) => {
  const token = settingsToken();
  await authenticatePage(page, token);
  // 탭 버튼명이 로케일에 따라 달라지므로 한국어로 고정(셀렉터 안정화).
  await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));

  // 1) 언어팩 탭으로 먼저 진입(언어팩 데이터소스가 선택된 스냅샷 상태)
  await gotoSettled(page, '/admin/settings?tab=language_packs', langPackMatcher);

  // 2) 본인인증 탭 버튼을 실제로 클릭 → 그 시점에 정책 목록 API 가 호출되어야 함
  const policyResp = page.waitForResponse(
    (r) => r.url().includes('/api/admin/identity/policies') && /[?&]per_page=10\b/.test(r.url()),
    { timeout: 20_000 },
  );
  await page.getByRole('button', { name: '본인인증', exact: true }).click();
  await policyResp; // 클릭 전환 시 정책 데이터소스 fetch 가 발생하지 않으면 여기서 timeout

  expect(page.url()).toMatch(/[?&]tab=identity/);
  await expect(page.getByText('선택한 scope이(가) 올바르지 않습니다', { exact: false })).toHaveCount(0);
});
