/**
 * 관리자 이커머스 디바운스 폼 — 입력 직후 「저장」 해도 본문에 마지막 입력값이 실리고, 칸이 옛 값으로 되돌아가지 않는다
 * (7.1.1).
 * 모듈 sirsoft-ecommerce (관리자 `/admin/ecommerce/products/{상품}/edit` 「정가」 · `/admin/ecommerce/promotion-coupons/{id}/edit`
 *   「최소주문금액」, 관리자 계정).
 *
 * 결함(실측): `data-key="form" data-debounce="300"` 폼에서 칸을 치고 300ms 안에 「저장」 을 누르면, 키 입력은
 *   `G7Core.state.getLocal().form` 에 바로 반영되는데 저장 apiCall 이 만든 본문(`_local.form`)에는 디바운스 전 값이 실렸다
 *   (상품 정가 5/5 · 쿠폰 최소주문금액 3/3 옛 값). 저장 뒤 칸 표시도 옛 값으로 되돌아갔다(상품 1/5 · 쿠폰 3/3). 디바운스가 끝난
 *   뒤 저장하면 정상이다. 원인은 엔진의 지역 상태 두 저장소와 디바운스 반영 시점(engine-v1.81.2 에서 엔진이 고쳤다).
 *
 * 조작(출시 전 실측 스크립트와 같다): 칸을 비우고 새 값을 40ms 간격으로 친다 → 「저장」 단추를 미리 찾아 두었다가 입력이 끝나면 페이지
 *   안에서 바로 누른다(Playwright click 의 동작 가능 대기가 디바운스를 흘려보내지 않게). 입력 끝에서 클릭까지의 간격을 페이지 안에서
 *   재어 디바운스(300ms)보다 짧았는지 먼저 확인한다. 대조 시험은 입력 1초 뒤에 누른다.
 *
 * 데이터: 상품·쿠폰은 관리자 목록 API(GET)의 첫 수정 가능 항목을 고른다. 저장 PUT 을 포함해 GET·HEAD 가 아닌 `/api/` 요청은
 *   모두 네트워크 단에서 abort 한다(서버 도달 0 — 상품·쿠폰 값 불변). 계정은 실행 환경변수 `G7_E2E_TOKEN`(관리자 토큰)으로만 받고,
 *   없으면 사유와 함께 건너뛴다(새 계정·역할을 만들지 않는다).
 *
 * 수정 전 대조: `G7_E2E_ENGINE_BUNDLE=<수정 전 엔진 번들 경로>` 를 주면 이 브라우저만 엔진 번들을 그 파일로 바꿔 끼운다(서버 파일
 *   불변 — basic 템플릿 fixture `env-account.ts` 와 같은 방식, 이 파일 안에 둔다). 수정 전 번들에서는 디바운스 안 저장 두 시험이 red 다.
 *
 * @scenario-manifest tests/scenarios/e2e-admin-debounce-form-save-latest.yaml
 *
 * 실행(관리자 토큰 필요):
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; $env:G7_E2E_TOKEN='<일회용 관리자 토큰>'
 *   npx playwright test --config=tests/Playwright/playwright.config.ts tests/Playwright/specs/admin/admin-debounce-form-save-latest.spec.ts --workers=1 --trace=off
 */
import { test, expect, type APIRequestContext, type Locator, type Page } from '@playwright/test';
import { ADMIN_TOKEN, ADMIN_TOKEN_SKIP_REASON, ECOMMERCE_ADMIN_API, firstEditable, prepareAdminPage } from '../../fixtures/admin-env-token';

// 시험끼리 상태를 나누지 않으므로 직렬로 묶지 않는다 — 하나가 환경 사유로 실패해도 나머지 갈래가 돈다
test.describe.configure({ timeout: 240_000 });

const TOKEN = ADMIN_TOKEN;

/** 시험마다 되풀이할 횟수(새 페이지마다 한 번 저장) */
const REPEAT = Math.max(1, Number(process.env.G7_E2E_DEBOUNCE_REPEAT ?? 1) || 1);

/** 입력 끝에서 「저장」 까지 — 디바운스 안 갈래(페이지 안 setTimeout, 마지막 키를 뗀 뒤 지연이 더해진다) */
const FAST_DELAY_MS = 20;

/** 입력 끝에서 「저장」 까지 — 디바운스 뒤 대조 갈래 */
const SLOW_DELAY_MS = 1000;

/** 폼의 디바운스(ms) — 이보다 짧게 눌렀는지 먼저 확인한다 */
const FORM_DEBOUNCE_MS = 300;

const ADMIN_API = ECOMMERCE_ADMIN_API;

/** 잴 폼 하나 */
interface FormTarget {
  /** 편집 화면 경로 */
  url: string;
  /** 저장 요청 경로(이 경로로 나가는 PUT 본문을 본다) */
  savePath: string;
  /** 칸 — 접근 이름 또는 name 속성 */
  field: (page: Page) => Locator;
  /** 저장 단추 */
  save: (page: Page) => Locator;
  /** 본문·`_local.form` 의 키 */
  key: string;
  /** 칠 값의 바닥(검증을 통과하는 값 — 상품 정가는 판매가 이상) */
  floor: number;
}

/**
 * 상품 수정 폼 「정가」.
 *
 * @param request Playwright API 요청 컨텍스트
 * @returns 잴 폼(상품이 없으면 null)
 */
async function productTarget(request: APIRequestContext): Promise<FormTarget | null> {
  const row = await firstEditable(request, `${ADMIN_API}/products?per_page=20`);
  if (!row) return null;
  const seg = String(row.product_code ?? row.id); // 목록의 「수정」 링크와 같은 식별자

  return {
    url: `/admin/ecommerce/products/${seg}/edit`,
    savePath: `${ADMIN_API}/products/${seg}`,
    field: (page) => page.getByRole('spinbutton', { name: /^정가$/ }).or(page.locator('input[name="list_price"]')).filter({ visible: true }).first(),
    save: (page) => page.getByTestId('product-save').filter({ visible: true }).first(),
    key: 'list_price',
    floor: Math.max(Number(row.list_price ?? 0), Number(row.selling_price ?? 0)),
  };
}

/**
 * 쿠폰 수정 폼 「최소주문금액」.
 *
 * @param request Playwright API 요청 컨텍스트
 * @returns 잴 폼(쿠폰이 없으면 null)
 */
async function couponTarget(request: APIRequestContext): Promise<FormTarget | null> {
  const row = await firstEditable(request, `${ADMIN_API}/promotion-coupons?per_page=20`);
  if (!row) return null;

  return {
    url: `/admin/ecommerce/promotion-coupons/${row.id}/edit`,
    savePath: `${ADMIN_API}/promotion-coupons/${row.id}`,
    field: (page) => page.getByRole('spinbutton', { name: /^최소주문금액$/ }).or(page.locator('input[name="min_order_amount"]')).filter({ visible: true }).first(),
    save: (page) => page.getByRole('button', { name: /^(저장|수정|수정하기|저장하기)$/ }).filter({ visible: true }).first(),
    key: 'min_order_amount',
    floor: Number(row.min_order_amount ?? 0),
  };
}

/**
 * 저장 본문에서 키 값을 꺼낸다(JSON, 아니면 multipart 의 같은 이름 칸).
 *
 * @param body 요청 본문
 * @param key 키
 * @returns 값 문자열(없으면 null)
 */
function bodyValue(body: string, key: string): string | null {
  try {
    const json = JSON.parse(body);
    const v = json?.[key];

    return v === undefined || v === null ? null : String(v);
  } catch {
    const m = body.match(new RegExp(`name="${key}"\\r?\\n\\r?\\n([^\\r\\n]*)`));

    return m ? m[1] : null;
  }
}

/** 한 번 저장한 결과 */
interface SaveRun {
  typed: string;
  gapMs: number;
  bodyValue: string | null;
  localValue: string;
  shownAfter: string;
}

/**
 * 폼을 열고 칸에 새 값을 40ms 간격으로 친 뒤, `delayMs` 뒤에 페이지 안에서 「저장」 을 누르고 나간 PUT 을 본다.
 *
 * @param page Playwright 페이지
 * @param target 잴 폼
 * @param delayMs 입력 끝에서 클릭까지 페이지 안 지연
 * @param round 회차(칠 값을 회차마다 달리한다)
 * @returns 저장 결과
 */
async function typeThenSave(page: Page, target: FormTarget, delayMs: number, round: number): Promise<SaveRun> {
  const { writes, served } = await prepareAdminPage(page);
  await page.goto(target.url);
  await page.waitForFunction(() => typeof (window as any).G7Core?.dispatch === 'function', null, { timeout: 60_000 });
  if (process.env.G7_E2E_ENGINE_BUNDLE) expect(served.count, '바꿔 끼운 엔진 번들이 쓰였다').toBeGreaterThan(0);

  const field = target.field(page);
  await expect(field, `${target.key} 칸`).toBeEditable({ timeout: 60_000 });
  // 데이터소스가 폼을 채울 때까지 — 빈 칸에 먼저 치면 뒤늦은 채움이 값을 덮는다
  if (target.floor > 0) await expect(field).not.toHaveValue('', { timeout: 30_000 });
  const save = target.save(page);
  await expect(save, '저장 단추').toBeEnabled({ timeout: 30_000 });
  await page.waitForTimeout(1500); // 폼 초기화·계산값 정착

  const typed = String(Math.floor(Math.max(target.floor, 0) / 100) * 100 + 1000 + ((Date.now() + round * 137) % 8000) + 1);
  await save.evaluate((el) => {
    (window as any).__e2eSave = el;
  });
  await field.click();
  await page.keyboard.press('Control+A');
  await page.keyboard.press('Delete');
  await field.evaluate((el) => {
    (window as any).__e2eLastInput = 0;
    el.addEventListener('input', () => {
      (window as any).__e2eLastInput = performance.now();
    });
  });
  await page.keyboard.type(typed, { delay: 40 });
  const writesBefore = writes.length;
  const gapMs = await page.evaluate(
    (wait) =>
      new Promise<number>((resolve) => {
        setTimeout(() => {
          const now = performance.now();
          ((window as any).__e2eSave as HTMLElement).click();
          resolve(now - (window as any).__e2eLastInput);
        }, wait);
      }),
    delayMs,
  );

  await expect
    .poll(() => writes.slice(writesBefore).some((w) => /^(PUT|PATCH)$/.test(w.method) && w.path === target.savePath), {
      message: `저장 요청(${target.savePath})이 나갔다`,
      timeout: 30_000, // 개발 서버 부하 때 저장 클릭 → 요청까지 10초를 넘긴 전례
    })
    .toBe(true);
  const sent = writes.slice(writesBefore).find((w) => /^(PUT|PATCH)$/.test(w.method) && w.path === target.savePath)!;
  await page.waitForTimeout(1500); // 저장 실패(abort) 뒤 화면 정착
  const localValue = await page.evaluate((k) => String((window as any).G7Core.state.getLocal()?.form?.[k] ?? ''), target.key);

  return { typed, gapMs, bodyValue: bodyValue(sent.body, target.key), localValue, shownAfter: await field.inputValue() };
}

/**
 * 같은 폼을 N 회 재고, 본문·칸 표시가 마지막 입력값인지 판정한다.
 *
 * @param page Playwright 페이지(첫 회차)
 * @param target 잴 폼
 * @param delayMs 입력 끝에서 클릭까지
 * @param withinDebounce 디바운스 안에 눌러야 하는 갈래인가
 */
async function measure(page: Page, target: FormTarget, delayMs: number, withinDebounce: boolean): Promise<void> {
  const runs: SaveRun[] = [];
  for (let round = 1; round <= REPEAT; round += 1) {
    const p = round === 1 ? page : await page.context().newPage();
    try {
      runs.push(await typeThenSave(p, target, delayMs, round));
    } finally {
      if (round > 1) await p.close();
    }
  }
  const summary = runs.map((r, i) => `${i + 1}회차 입력 ${r.typed} · 간격 ${Math.round(r.gapMs)}ms · 본문 ${r.bodyValue} · getLocal ${r.localValue} · 저장 뒤 칸 ${r.shownAfter}`);
  test.info().annotations.push({ type: '저장 결과', description: `${target.url} ${target.key} — ${summary.join(' / ')}` });
  console.log(`[admin-debounce-form-save-latest] ${target.url} ${target.key}\n  ${summary.join('\n  ')}`);

  for (const [i, r] of runs.entries()) {
    if (withinDebounce) expect(r.gapMs, `${i + 1}회차 조건 — 입력 끝에서 저장까지 디바운스(${FORM_DEBOUNCE_MS}ms)보다 짧다`).toBeLessThan(FORM_DEBOUNCE_MS - 50);
    else expect(r.gapMs, `${i + 1}회차 조건 — 디바운스가 끝난 뒤 눌렀다`).toBeGreaterThan(FORM_DEBOUNCE_MS + 200);
  }
  expect
    .soft(runs.map((r) => r.bodyValue), '저장 본문의 값 = 마지막 입력값')
    .toEqual(runs.map((r) => r.typed));
  expect
    .soft(runs.map((r) => r.shownAfter), '저장 뒤 칸 표시 = 마지막 입력값(옛 값으로 되돌아가지 않는다)')
    .toEqual(runs.map((r) => r.typed));
}

test.describe('관리자 디바운스 폼 — 입력 직후 저장 본문 최신값', () => {
  test.skip(!TOKEN, ADMIN_TOKEN_SKIP_REASON);

  test.afterEach(async ({ page }) => {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
  });

  /**
   * @scenario case=product_list_price_within_debounce
   * @effects save_body_has_latest_value, field_keeps_typed_value_after_save
   */
  test('상품 수정 「정가」 — 40ms 간격으로 친 직후 저장해도 PUT 본문 list_price 가 마지막 입력값이고 칸이 그 값으로 남는다', async ({ page, request }) => {
    const target = await productTarget(request);
    test.skip(!target, '관리자 상품 목록에 수정 가능한 상품이 없다 — 잴 수 없다');
    await measure(page, target!, FAST_DELAY_MS, true);
  });

  /**
   * @scenario case=coupon_min_order_amount_within_debounce
   * @effects save_body_has_latest_value, field_keeps_typed_value_after_save
   */
  test('쿠폰 수정 「최소주문금액」 — 친 직후 저장해도 PUT 본문 min_order_amount 가 마지막 입력값이고 칸이 그 값으로 남는다', async ({ page, request }) => {
    const target = await couponTarget(request);
    test.skip(!target, '관리자 쿠폰 목록에 수정 가능한 쿠폰이 없다 — 잴 수 없다');
    await measure(page, target!, FAST_DELAY_MS, true);
  });

  /**
   * @scenario case=product_list_price_after_debounce
   * @effects save_body_has_latest_value, field_keeps_typed_value_after_save
   */
  test('대조 — 상품 「정가」 를 친 뒤 1초 기다려 저장하면 PUT 본문·칸이 마지막 입력값이다', async ({ page, request }) => {
    const target = await productTarget(request);
    test.skip(!target, '관리자 상품 목록에 수정 가능한 상품이 없다 — 잴 수 없다');
    await measure(page, target!, SLOW_DELAY_MS, false);
  });
});
