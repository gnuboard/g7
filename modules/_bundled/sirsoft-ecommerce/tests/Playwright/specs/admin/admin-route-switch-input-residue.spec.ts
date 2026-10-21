/**
 * 관리자 이커머스 상품 폼 — 입력 직후 같은 화면틀의 다른 주소(수정 → 등록)로 옮기면 새 화면 칸에 앞 화면 입력이 남지 않는다
 * (7.1.1).
 * 모듈 sirsoft-ecommerce (관리자 `/admin/ecommerce/products/{상품}/edit` ↔ `/admin/ecommerce/products/create`, 관리자 계정).
 *
 * 결함(실측 — 7.1.0 번들도 같음): `data-key="form" data-debounce="300"` 폼의 칸에 친 값이 디바운스 대기 중일 때
 *   같은 레이아웃의 다른 경로로 옮기면(flush 없는 `navigate`·브라우저 뒤로), 새 화면 상태(`getLocal().form`)는 비었는데 칸 화면에
 *   앞 화면 입력이 남았다. 그 칸에 이어 치면 남은 글자 뒤에 붙어 저장 본문까지 섞였다(「32222」 + 「7700」 → 「322227700」).
 *   엔진은 경로 이동 때 대기 쓰기를 버리지만 칸 컴포넌트의 지역 표시값을 다시 잡지 못했다(engine-v1.81.2 에서 그 칸만 다시 마운트).
 *
 * 조작(출시 전 실측 스크립트와 같다):
 *   - 수정 → 등록: 정가 칸을 비우고 「32222」 를 40ms 간격으로 친 뒤 60ms 안에 `G7Core.dispatch(navigate)` 로 등록 화면 → 칸·상태
 *     확인 → 등록 칸에 「7700」 을 치고 「등록」(abort) → 본문 list_price 가 「7700」 이고 id 가 없는지.
 *   - 등록 → 수정 → 뒤로: 등록에서 수정으로 앱 내 이동 → 정가에 「39999」 → 80ms 안에 브라우저 뒤로 → 등록 칸이 빈 칸인지.
 *
 * 데이터: 상품은 관리자 목록 API(GET)의 첫 수정 가능 항목. GET·HEAD 가 아닌 `/api/` 요청은 모두 네트워크 단에서 abort(서버 값 불변).
 *   계정은 실행 환경변수 `G7_E2E_TOKEN`(관리자 토큰)으로만 받고, 없으면 사유와 함께 건너뛴다(새 계정·역할을 만들지 않는다).
 *
 * 수정 전 대조: `G7_E2E_ENGINE_BUNDLE=<수정 전 엔진 번들 경로>` 를 주면 이 브라우저만 엔진 번들을 그 파일로 바꿔 끼운다
 *   (서버 파일 불변). 수정 전 번들에서는 두 시험이 red 다.
 *
 * @scenario-manifest tests/scenarios/e2e-admin-route-switch-input-residue.yaml
 *
 * 실행(관리자 토큰 필요):
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; $env:G7_E2E_TOKEN='<일회용 관리자 토큰>'
 *   npx playwright test --config=tests/Playwright/playwright.config.ts tests/Playwright/specs/admin/admin-route-switch-input-residue.spec.ts --workers=1 --trace=off
 */
import { test, expect, type APIRequestContext, type Page } from '@playwright/test';
import { ADMIN_TOKEN, ADMIN_TOKEN_SKIP_REASON, ECOMMERCE_ADMIN_API, prepareAdminPage } from '../../fixtures/admin-env-token';

test.describe.configure({ timeout: 240_000 });

const TOKEN = ADMIN_TOKEN;

const ADMIN_API = ECOMMERCE_ADMIN_API;

/** 등록 화면 경로 */
const CREATE_URL = '/admin/ecommerce/products/create';

/** 정가 칸 */
const PRICE = 'input[name="list_price"]';

/**
 * 관리자 상품 목록 API 의 첫 수정 가능 상품의 수정 경로(GET 만).
 *
 * @param request Playwright API 요청 컨텍스트
 * @returns 수정 화면 경로(없으면 null)
 */
async function editUrl(request: APIRequestContext): Promise<string | null> {
  const res = await request.get(`${ADMIN_API}/products?per_page=20`, { headers: { Accept: 'application/json', Authorization: `Bearer ${TOKEN}` } });
  if (!res.ok()) return null;
  const json = await res.json();
  const rows: any[] = json?.data?.data ?? json?.data ?? [];
  const row = rows.find((r) => r?.id != null && r?.abilities?.can_update !== false);

  return row ? `/admin/ecommerce/products/${String(row.product_code ?? row.id)}/edit` : null;
}

/**
 * 폼이 서고 정가 칸이 편집 가능해질 때까지 기다린다.
 *
 * @param page Playwright 페이지
 * @param pathRe 기다릴 경로
 */
async function waitForm(page: Page, pathRe: RegExp): Promise<void> {
  await page.waitForFunction((src) => new RegExp(src).test(location.pathname) && !!(window as any).G7Core?.state?.getLocal?.(), pathRe.source, { timeout: 60_000 });
  await expect(page.locator(PRICE).filter({ visible: true }).first(), '정가 칸').toBeEditable({ timeout: 60_000 });
  await page.waitForTimeout(2000); // 데이터소스 채움·계산값 정착
}

/**
 * 정가 칸을 비우고 값을 40ms 간격으로 친다.
 *
 * @param page Playwright 페이지
 * @param value 칠 값
 */
async function retypePrice(page: Page, value: string): Promise<void> {
  const box = page.locator(PRICE).filter({ visible: true }).first();
  await box.click();
  await page.keyboard.press('Control+A');
  await page.keyboard.press('Delete');
  await page.keyboard.type(value, { delay: 40 });
}

/**
 * 지금 화면의 `_local.form` 에서 키를 꺼낸다.
 *
 * @param page Playwright 페이지
 * @param key 키
 * @returns 값(없으면 null)
 */
async function formValue(page: Page, key: string): Promise<unknown> {
  return page.evaluate((k) => (window as any).G7Core.state.getLocal()?.form?.[k] ?? null, key);
}

test.describe('관리자 상품 폼 — 입력 직후 같은 화면틀 다른 주소로 옮겨도 앞 입력이 칸에 남지 않는다', () => {
  test.skip(!TOKEN, ADMIN_TOKEN_SKIP_REASON);

  test.afterEach(async ({ page }) => {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
  });

  /**
   * @scenario case=edit_to_create_within_debounce
   * @effects destination_field_shows_state, retyped_value_not_mixed_with_previous
   */
  test('수정 화면 정가 「32222」 를 친 직후 등록 화면으로 옮기면 등록 칸이 빈 칸이고, 이어 친 「7700」 만 등록 본문에 실린다', async ({ page, request }) => {
    const url = await editUrl(request);
    test.skip(!url, '관리자 상품 목록에 수정 가능한 상품이 없다 — 잴 수 없다');
    const { writes, served } = await prepareAdminPage(page);
    await page.goto(url!);
    await waitForm(page, /\/edit$/);
    if (process.env.G7_E2E_ENGINE_BUNDLE) expect(served.count, '바꿔 끼운 엔진 번들이 쓰였다').toBeGreaterThan(0);

    await retypePrice(page, '32222');
    await page.evaluate(
      (p) => new Promise<void>((resolve) => {
        setTimeout(() => {
          (window as any).G7Core.dispatch({ handler: 'navigate', params: { path: p } });
          resolve();
        }, 60);
      }),
      CREATE_URL,
    );
    await waitForm(page, /\/create$/);
    const shown = await page.locator(PRICE).filter({ visible: true }).first().inputValue();
    const state = await formValue(page, 'list_price');
    const id = await formValue(page, 'id');
    console.log(`[admin-route-switch-input-residue] 수정→등록 칸 「${shown}」 · getLocal list_price ${JSON.stringify(state)} · id ${JSON.stringify(id)}`);
    expect.soft(id, '등록 화면 상태에 수정 상품 id 가 없다').toBeNull();
    expect(shown, '등록 화면 정가 칸 = 상태(빈 값) — 앞 화면 입력 「32222」 가 남지 않는다').toBe(String(state ?? ''));

    await retypePrice(page, '7700');
    await page.waitForTimeout(600);
    const before = writes.length;
    await page.getByRole('button', { name: /^(저장|등록)$/ }).filter({ visible: true }).first().click();
    await expect
      .poll(() => writes.slice(before).some((w) => w.method === 'POST' && /\/admin\/products$/.test(w.path)), { message: '등록 요청이 나갔다', timeout: 30_000 })
      .toBe(true);
    const sent = writes.slice(before).find((w) => w.method === 'POST' && /\/admin\/products$/.test(w.path))!;
    const body = JSON.parse(sent.body || '{}');
    expect(String(body.list_price), '등록 본문 list_price = 이어 친 값만').toBe('7700');
    expect(body.id ?? null, '등록 본문에 id 가 없다').toBeNull();
  });

  /**
   * @scenario case=edit_then_browser_back_within_debounce
   * @effects destination_field_shows_state
   */
  test('등록 → 수정으로 옮겨 정가 「39999」 를 친 직후 브라우저 뒤로 가면 등록 칸이 빈 칸이다', async ({ page, request }) => {
    const url = await editUrl(request);
    test.skip(!url, '관리자 상품 목록에 수정 가능한 상품이 없다 — 잴 수 없다');
    await prepareAdminPage(page);
    await page.goto(CREATE_URL);
    await waitForm(page, /\/create$/);
    await page.evaluate((p) => (window as any).G7Core.dispatch({ handler: 'navigate', params: { path: p } }), url!);
    await waitForm(page, /\/edit$/);
    await page.waitForFunction(() => (window as any).G7Core.state.getLocal()?.form?.id != null, null, { timeout: 30_000 });

    await retypePrice(page, '39999');
    await page.waitForTimeout(80);
    await page.goBack();
    await waitForm(page, /\/create$/);
    const shown = await page.locator(PRICE).filter({ visible: true }).first().inputValue();
    const state = await formValue(page, 'list_price');
    console.log(`[admin-route-switch-input-residue] 수정→뒤로(등록) 칸 「${shown}」 · getLocal list_price ${JSON.stringify(state)}`);
    expect(shown, '뒤로 간 등록 화면 정가 칸 = 상태 — 「39999」 가 남지 않는다').toBe(String(state ?? ''));
  });
});
