/**
 * 마이페이지 배송지 수정 — 해외 주소 필드 표시/보존 E2E.
 * 템플릿 sirsoft-basic (유저 화면).
 *
 * @scenario address-overseas-edit
 * @effects overseas_edit_form_prefills_intl_fields,
 *          overseas_edit_save_preserves_city_state_postal_code
 *
 * 배경: 배송지 조회 응답은 해외 주소를 city/state/postal_code 로 내려주지만 주소 폼의
 *       입력칸 이름은 intl_city/intl_state/intl_postal_code 다. 수정 진입에서 조회 응답
 *       객체를 그대로 editingAddress 에 넣던 탓에 해외 도시/주/우편번호 입력칸이 빈 값으로
 *       보였고, 그대로 저장하면 서버가 그 세 값을 null 로 덮어써 조용히 유실됐다.
 *       (백엔드는 MapsAddressBookFields 가 미전송 intl_* 를 덮어쓰지 않도록 함께 수정)
 *
 * 매트릭스:
 *   T1 해외 배송지 수정 진입 → intl_city/intl_state/intl_postal_code 입력칸에 저장값이 채워져 있다
 *   T2 도시/주/우편번호를 건드리지 않고 저장 → 저장된 값이 그대로 유지된다
 *   T3 목록 카드가 해외 주소를 국내 전용 형식('[] ')이 아니라 전체 주소로 표시한다
 *
 * 라벨 매칭은 로케일 비의존으로 둔다 (사이트 기본 언어가 ko/en 어느 쪽이든 동작).
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'
 *   npx playwright test --config templates/_bundled/sirsoft-basic/tests/Playwright/playwright.config.ts mypage-address-overseas-edit
 */
import { test, expect, type APIRequestContext } from '@playwright/test';
import { issueToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

const ADDRESS_API = '/api/modules/sirsoft-ecommerce/user/addresses';

/** 검증용 해외 배송지를 API 로 생성하고 ID 를 돌려준다 */
async function createOverseasAddress(request: APIRequestContext, token: string, name: string): Promise<number> {
  const response = await request.post(ADDRESS_API, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    data: {
      name,
      recipient_name: 'John Doe',
      recipient_phone: '010-1234-5678',
      country_code: 'US',
      address_line_1: '350 5th Ave',
      address_line_2: 'Suite 1000',
      intl_city: 'New York',
      intl_state: 'NY',
      intl_postal_code: '10118',
    },
  });

  expect(response.status(), await response.text()).toBe(201);

  return response.json().then((body) => body.data.address.id);
}

/** 배송지 단건을 API 로 조회한다 */
async function fetchAddress(request: APIRequestContext, token: string, id: number): Promise<Record<string, unknown>> {
  const response = await request.get(`${ADDRESS_API}/${id}`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });

  expect(response.ok(), await response.text()).toBe(true);

  const body = await response.json();

  return body.data.address;
}

/** 로케일 비의존 라벨 매처 */
const EDIT_LABEL = /^(수정|Edit)$/;
const SAVE_LABEL = /^(저장|Save)$/;

/** 배송지명으로 카드 전체를 집는다 (카드 루트에 data-testid="address-card") */
function addressCard(page: import('@playwright/test').Page, addressName: string) {
  return page.getByTestId('address-card').filter({ hasText: addressName });
}

/** GDPR 쿠키 배너가 떠 있으면 클릭을 가로채므로 먼저 닫는다 */
async function dismissCookieNotice(page: import('@playwright/test').Page): Promise<void> {
  const necessaryOnly = page.getByRole('button', { name: /Necessary Only|필수만/ });

  if (await necessaryOnly.isVisible().catch(() => false)) {
    await necessaryOnly.click();
  }
}

test.describe('마이페이지 해외 배송지 수정', () => {
  let token: string;

  test.beforeAll(() => {
    // 유저 화면 흐름이라 관리자 권한은 필요 없다 (인증만 필요).
    token = issueToken('sirsoft-ecommerce.user.addresses.self');
  });

  test('T1 수정 진입 시 해외 도시/주/우편번호 입력칸에 저장값이 채워진다', async ({ page, request }) => {
    const addressName = `E2E 해외 ${Date.now()}`;
    await createOverseasAddress(request, token, addressName);

    await authenticatePage(page, token);
    await page.goto('/mypage/addresses');
    await dismissCookieNotice(page);

    const card = addressCard(page, addressName);

    await card.getByRole('button', { name: EDIT_LABEL }).click();

    await expect(page.locator('input[name="intl_city"]')).toHaveValue('New York');
    await expect(page.locator('input[name="intl_state"]')).toHaveValue('NY');
    await expect(page.locator('input[name="intl_postal_code"]')).toHaveValue('10118');
  });

  test('T2 도시/주/우편번호를 건드리지 않고 저장해도 값이 유지된다', async ({ page, request }) => {
    const addressName = `E2E 해외 보존 ${Date.now()}`;
    const addressId = await createOverseasAddress(request, token, addressName);

    await authenticatePage(page, token);
    await page.goto('/mypage/addresses');
    await dismissCookieNotice(page);

    const card = addressCard(page, addressName);

    await card.getByRole('button', { name: EDIT_LABEL }).click();
    await expect(page.locator('input[name="intl_city"]')).toHaveValue('New York');

    // 주소 2행만 바꾸고 저장 — 도시/주/우편번호는 손대지 않는다
    await page.locator('input[name="address_line_2"]').fill('Suite 2000');

    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes(ADDRESS_API) && r.request().method() === 'PUT'),
      page.getByRole('button', { name: SAVE_LABEL }).click(),
    ]);

    expect(response.status()).toBe(200);

    const saved = await fetchAddress(request, token, addressId);

    expect(saved.city).toBe('New York');
    expect(saved.state).toBe('NY');
    expect(saved.postal_code).toBe('10118');
    expect(saved.address_line_2).toBe('Suite 2000');
  });

  test('T3 목록 카드가 해외 주소를 전체 주소로 표시한다', async ({ page, request }) => {
    const addressName = `E2E 해외 표시 ${Date.now()}`;
    await createOverseasAddress(request, token, addressName);

    await authenticatePage(page, token);
    await page.goto('/mypage/addresses');
    await dismissCookieNotice(page);

    // 클릭 단계가 없는 검사라 목록 하이드레이션 완료를 명시적으로 기다린다
    await expect(page.getByTestId('address-card').first()).toBeVisible({ timeout: 15000 });

    const card = addressCard(page, addressName);

    await expect(card).toContainText('350 5th Ave');
    await expect(card).toContainText('New York');
    // 국내 전용 형식으로 찍히면 우편번호 자리만 남아 "[]" 가 노출된다
    await expect(card).not.toContainText('[]');
  });
});
