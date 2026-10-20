/**
 * 상품 상세 상품평 옵션 필터 — 다른 상품으로 SPA 이동하거나 「필터 초기화」 를 누르면 필터가 빈다 (7.1.1).
 * 템플릿 sirsoft-basic (유저 화면 `/shop/products/{상품 코드}?tab=reviews`, 비회원).
 *
 * 결함: setState 기본 깊은 병합에서 빈 객체 `{}` 가 기존 값을 지우지 못해, 상품 A 에서 고른 옵션 필터(「색상=블랙」)가
 *   상품 B 로 옮겨 가도 남아 B 의 상품평 요청이 걸러진 채 나갔다. 레이아웃은 `null` → `{}` 2단계로, 엔진은 setState 의 빈 객체
 *   값이 그 자리를 비우게 고쳤다. 이 spec 은 실제 화면에서 두 고침이 함께 지키는 결과를 고정한다(회귀 가드 — 레이아웃의
 *   2단계 덕분에 수정 전 엔진 번들에서도 통과한다). 세 번째 시험은 이 브라우저에서만 레이아웃의 `null` 선행 쓰기를 걷어 내
 *   엔진 고침 하나로도 비워지는지 본다 — 수정 전 엔진 번들(`G7_E2E_ENGINE_BUNDLE`)에서는 붉다.
 *
 * 데이터: 상품 2개는 공개 상품 목록 API 에서 고른다. 상품평 응답의 `option_filters` 만 이 브라우저에서 같은 값으로 바꿔 화면에
 *   옵션 선택칸이 늘 뜨게 한다(서버 불변). 쓰기 요청은 가로챈다. 계정 불필요.
 *
 * @scenario-manifest tests/scenarios/e2e-product-review-filter-reset-spa.yaml
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; npx playwright test --config=tests/Playwright/playwright.config.ts tests/Playwright/specs/product-review-filter-reset-spa.spec.ts --workers=1
 */
import { test, expect, type APIRequestContext, type Page } from '@playwright/test';
import { dismissCookieBanner, guardWrites, spaNavigate, waitForSpa } from '../fixtures/env-account';

test.describe.configure({ timeout: 120_000 });

/** 주입할 옵션 필터 — 키는 선택칸 접근 이름이 된다 */
const OPTION_FILTERS = [{ key: '색상', values: [{ value: '블랙', count: 2 }, { value: '화이트', count: 1 }] }];

/**
 * 공개 상품 목록에서 상품 코드 2개를 고른다.
 *
 * @param request Playwright API 요청 컨텍스트
 * @returns 서로 다른 상품 코드 2개(모자라면 빈 배열)
 */
async function twoProductCodes(request: APIRequestContext): Promise<string[]> {
  const res = await request.get('/api/modules/sirsoft-ecommerce/products?per_page=10', { headers: { Accept: 'application/json' } });
  if (!res.ok()) return [];
  const list = ((await res.json())?.data?.data ?? []) as Array<{ product_code?: string }>;
  const codes = [...new Set(list.map((p) => p.product_code).filter((c): c is string => !!c))];

  return codes.length >= 2 ? codes.slice(0, 2) : [];
}

/**
 * 상품평 응답의 option_filters 를 바꾸고, 나간 상품평 요청의 option_filters 값을 상품 코드별로 모은다.
 *
 * @param page Playwright 페이지
 * @returns 상품 코드 → 요청마다의 option_filters 쿼리 값
 */
async function routeReviews(page: Page): Promise<Map<string, string[]>> {
  const seen = new Map<string, string[]>();
  await page.route(
    (url) => /^\/api\/modules\/sirsoft-ecommerce\/products\/[^/]+\/reviews$/.test(url.pathname),
    async (route) => {
      const url = new URL(route.request().url());
      const code = url.pathname.split('/')[5];
      seen.set(code, [...(seen.get(code) ?? []), url.searchParams.get('option_filters') ?? '']);
      const res = await route.fetch();
      const body = await res.json();
      if (body?.data) body.data.option_filters = OPTION_FILTERS;

      return route.fulfill({ response: res, json: body });
    },
  );

  return seen;
}

/**
 * 상품 상세 레이아웃 응답에서 `reviewOptionFilters` 의 `null` 선행 쓰기(레이아웃 우회)를 걷어 낸다 — 이 브라우저에서만.
 * 같은 setState 의 다른 키(`currentSelection` 등)는 남기고, 남는 키가 target 뿐이면 그 동작을 뺀다.
 *
 * @param page Playwright 페이지
 * @returns 걷어 낸 자리 수를 돌려주는 함수
 */
async function stripLayoutNullPrewrite(page: Page): Promise<() => number> {
  let stripped = 0;
  const walk = (node: unknown): unknown => {
    if (Array.isArray(node)) {
      return node
        .map((item) => {
          const params = (item as { handler?: string; params?: Record<string, unknown> })?.params;
          if ((item as { handler?: string })?.handler === 'setState' && params && params.reviewOptionFilters === null) {
            const { reviewOptionFilters: _drop, ...rest } = params;
            stripped += 1;
            if (Object.keys(rest).filter((k) => k !== 'target' && k !== 'scope').length === 0) return undefined;

            return { ...(item as object), params: rest };
          }

          return walk(item);
        })
        .filter((item) => item !== undefined);
    }
    if (node && typeof node === 'object') {
      for (const [k, v] of Object.entries(node)) (node as Record<string, unknown>)[k] = walk(v);
    }

    return node;
  };
  await page.route(
    (url) => /^\/api\/layouts\/[^/]+\/shop\/show\.json$/.test(url.pathname),
    async (route) => {
      const res = await route.fetch();
      const body = walk(await res.json());

      return route.fulfill({ response: res, json: body });
    },
  );

  return () => stripped;
}

/**
 * 지역 상태의 상품평 옵션 필터를 읽는다.
 *
 * @param page Playwright 페이지
 * @returns `_local.reviewOptionFilters`
 */
async function readFilters(page: Page): Promise<unknown> {
  return page.evaluate(() => (window as any).G7Core.state.getLocal()?.reviewOptionFilters ?? null);
}

/**
 * 상품평 「색상」 선택칸 — basic Select 는 목록형 단추(aria-haspopup=listbox)라 접근 이름이 「색상 {현재 값}」 이다.
 *
 * @param page Playwright 페이지
 * @returns 선택칸 단추
 */
function colorSelect(page: Page) {
  return page.getByRole('button', { name: /^색상/ }).first();
}

/**
 * 「색상」 선택칸에서 값을 고른다.
 *
 * @param page Playwright 페이지
 * @param label 고를 항목 이름(예: 「블랙 (2)」)
 */
async function pickColor(page: Page, label: string): Promise<void> {
  await colorSelect(page).click();
  await page.getByRole('option', { name: label, exact: true }).click();
}

test.describe('상품평 옵션 필터 비우기', () => {
  // 시험이 끝난 뒤 늦게 오는 상품평 요청이 닫힌 페이지에서 route.fetch 오류를 내지 않게 한다
  test.afterEach(async ({ page }) => {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
  });

  /**
   * @scenario case=spa_move_to_other_product
   * @effects review_filter_empty_after_spa_move, review_request_not_filtered_after_move
   */
  test('A 에서 「색상=블랙」 을 고른 뒤 B 로 SPA 이동하면 B 의 필터가 비어 있다', async ({ page, request }) => {
    const codes = await twoProductCodes(request);
    test.skip(codes.length < 2, '공개 상품이 2개 미만이라 잴 수 없다');
    const [a, b] = codes;
    await guardWrites(page);
    const seen = await routeReviews(page);

    await page.goto(`/shop/products/${a}?tab=reviews`);
    await waitForSpa(page);
    await dismissCookieBanner(page);
    await expect(colorSelect(page)).toBeVisible({ timeout: 30_000 });
    await pickColor(page, '블랙 (2)');
    await expect(colorSelect(page)).toContainText('블랙');
    await expect.poll(() => readFilters(page), { message: 'A 필터 = 블랙' }).toEqual({ 색상: '블랙' });
    await expect.poll(() => (seen.get(a) ?? []).some((v) => v.includes('블랙')), { message: 'A 의 상품평 요청이 블랙으로 걸러짐' }).toBe(true);

    await spaNavigate(page, `/shop/products/${b}?tab=reviews`);
    await expect.poll(() => (seen.get(b) ?? []).length, { message: 'B 의 상품평 요청', timeout: 30_000 }).toBeGreaterThan(0);
    await expect(colorSelect(page)).toBeVisible({ timeout: 20_000 });
    await expect(colorSelect(page)).not.toContainText('블랙');
    await expect.poll(() => readFilters(page), { message: 'B 필터 = 빈 값' }).toEqual({});
    expect(seen.get(b)!.filter((v) => v.includes('블랙')), 'B 의 상품평 요청에 A 의 필터가 없다').toEqual([]);
  });

  /**
   * @scenario case=reset_button_same_product
   * @effects review_filter_empty_after_reset, review_request_not_filtered_after_move
   */
  test('같은 상품에서 「필터 초기화」 를 누르면 옵션 필터가 빈다', async ({ page, request }) => {
    const codes = await twoProductCodes(request);
    test.skip(codes.length < 1, '공개 상품이 없어 잴 수 없다');
    const [a] = codes;
    await guardWrites(page);
    const seen = await routeReviews(page);

    await page.goto(`/shop/products/${a}?tab=reviews`);
    await waitForSpa(page);
    await dismissCookieBanner(page);
    await expect(colorSelect(page)).toBeVisible({ timeout: 30_000 });
    await pickColor(page, '블랙 (2)');
    await expect(colorSelect(page)).toContainText('블랙');
    await expect.poll(() => readFilters(page)).toEqual({ 색상: '블랙' });

    const before = (seen.get(a) ?? []).length;
    await page.getByRole('button', { name: '필터 초기화', exact: true }).click();
    await expect(colorSelect(page)).not.toContainText('블랙');
    await expect.poll(() => readFilters(page), { message: '초기화 뒤 필터 = 빈 값' }).toEqual({});
    await expect.poll(() => (seen.get(a) ?? []).length, { message: '초기화 뒤 다시 받기' }).toBeGreaterThan(before);
    expect((seen.get(a) ?? []).slice(before).filter((v) => v.includes('블랙')), '초기화 뒤 요청에 블랙 없음').toEqual([]);
  });

  /**
   * @scenario case=spa_move_engine_only
   * @effects review_filter_empty_after_spa_move, review_request_not_filtered_after_move
   */
  test('레이아웃의 null 선행을 걷어도(엔진 고침만으로) B 로 옮기면 필터가 비어 있다', async ({ page, request }) => {
    const codes = await twoProductCodes(request);
    test.skip(codes.length < 2, '공개 상품이 2개 미만이라 잴 수 없다');
    const [a, b] = codes;
    await guardWrites(page);
    const seen = await routeReviews(page);
    const strippedCount = await stripLayoutNullPrewrite(page);

    await page.goto(`/shop/products/${a}?tab=reviews`);
    await waitForSpa(page);
    await dismissCookieBanner(page);
    // 레이아웃 응답은 SPA 부팅 뒤에 올 수 있다 — 한 번 재지 않고 기다린다
    await expect.poll(() => strippedCount(), { message: '레이아웃의 null 선행 쓰기를 걷어 냈다', timeout: 30_000 }).toBeGreaterThan(0);
    await expect(colorSelect(page)).toBeVisible({ timeout: 30_000 });
    await pickColor(page, '블랙 (2)');
    await expect.poll(() => readFilters(page), { message: 'A 필터 = 블랙' }).toEqual({ 색상: '블랙' });

    await spaNavigate(page, `/shop/products/${b}?tab=reviews`);
    await expect.poll(() => (seen.get(b) ?? []).length, { message: 'B 의 상품평 요청', timeout: 30_000 }).toBeGreaterThan(0);
    await expect(colorSelect(page)).toBeVisible({ timeout: 20_000 });
    await expect.poll(() => readFilters(page), { message: 'B 필터 = 빈 값(엔진 단독)' }).toEqual({});
    await expect(colorSelect(page)).not.toContainText('블랙');
    expect(seen.get(b)!.filter((v) => v.includes('블랙')), 'B 의 상품평 요청에 A 의 필터가 없다').toEqual([]);
  });
});
