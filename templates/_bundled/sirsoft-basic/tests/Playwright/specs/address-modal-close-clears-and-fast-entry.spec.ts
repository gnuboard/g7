/**
 * 배송지 창 — 닫으면 창 이름 공간이 비고, 「수정」 창을 닫은 뒤 「새 주소」 에 쉼 없이 입력해도 저장 본문이 온전하다
 * (7.1.1).
 * 템플릿 sirsoft-basic (유저 화면 `/mypage/addresses` 의 배송지 창 · `/shop/checkout` 의 「배송지 관리」 창, 회원).
 *
 * 결함 ①: 창 머리 X·Esc·바깥 클릭으로 닫으면 엔진 `closeModal` 로만 이어져 레이아웃 정리 동작을 타지 않아, 배송지
 *   15칸·고른 배송지가 `_global.editingAddress`·`_global.checkoutAddressModal` 에 다음 열기까지 남았다. 고침: 창이 열려 있는 동안
 *   내내 붙어 있는 노드의 unmount 에서 그 이름 공간을 비운다(취소는 직접).
 * 결함 ②: 「수정」 창을 닫고 「새 주소」 를 연 뒤 칸 사이 쉼 없이 입력하면 앞 칸 값이 빠지거나(전역 자동바인딩의
 *   렌더 시점 사본 쓰기) 앞 배송지 id·값이 섞여 PUT 으로 덮어썼다. 고침: 여는 쪽 null 선행 + 칸마다 change 점 경로 쓰기
 *   + 엔진 함수형 갱신. 이 spec 은 실제 화면에서 그 결과를 고정한다.
 *
 * 「쉼 없는 입력」 은 칸마다 click 을 거치지 않는다 — Playwright click 의 동작 가능 대기가 미뤄진 전역 다시 그리기를 먼저 흘려보내
 *   결함 조건(다음 칸 입력이 그 렌더보다 먼저 온다)이 사라진다. 칸은 접근 이름으로 찾고, 값은 한 작업(task) 안에서 네이티브 value
 *   setter + input/change 이벤트로 네 칸에 잇달아 넣는다(엔진의 실제 입력 처리 경로, 상태 직접 쓰기 아님 —
 *   engine-global-form-write-base.spec.ts 시험 1 과 같은 방식).
 * 「data-key 복원」 축: 레이아웃 응답의 마이페이지 배송지 폼에 이 브라우저에서만 `dataKey: "_global.editingAddress"` 를 되살리고
 *   이름 있는 칸의 change 점 경로 쓰기를 걷어, 자동바인딩만으로 쓰게 한다 — 엔진 고침 단독으로 결함이 막히는지 잰다.
 *   수정 전 엔진 번들(`G7_E2E_ENGINE_BUNDLE`)로 돌리면 이 축이 red 여야 한다.
 *
 * 데이터: 배송지 목록 조회(`GET user/addresses`)는 가짜 배송지 1건으로 모의하고(실제 회원 주소를 화면·캡처에 띄우지 않는다),
 *   저장·삭제 등 쓰기는 가로채 본문만 기록한다(서버 불변). 주문서 축은 그 계정에 진행 중 주문서(`GET checkout` 200)가 있을 때만
 *   돈다 — 없으면 사유와 함께 건너뛴다. 주문서 배송지 적용(`PUT checkout`)은 가로채 본문만 기록하고 앞서 받은 주문서 응답으로
 *   답한다. 계정은 실행 환경변수(`G7_E2E_TOKEN` 등)로만 받는다.
 *
 * @scenario-manifest tests/scenarios/e2e-address-modal-close-clears-and-fast-entry.yaml
 *
 * 실행(계정 필요):
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; $env:G7_E2E_TOKEN='<일회용 토큰>'
 *   npx playwright test --config=tests/Playwright/playwright.config.ts tests/Playwright/specs/address-modal-close-clears-and-fast-entry.spec.ts --workers=1 --trace=off
 * 수정 전 대조: 위에 $env:G7_E2E_ENGINE_BUNDLE='<수정 전 엔진 번들 경로>' 를 더한다(tests/Playwright/fixtures/env-account.ts).
 */
import { test, expect, type ElementHandle, type Locator, type Page } from '@playwright/test';
import {
  ACCOUNT_SKIP_REASON,
  authenticateWith,
  dismissCookieBanner,
  envAccountToken,
  guardWrites,
  hasEnvAccount,
  waitForSpa,
} from '../fixtures/env-account';

test.describe.configure({ timeout: 180_000 });

/** 모의 배송지 — 「수정」 으로 열 앞 배송지(가짜 값) */
const FAKE_ADDRESS = {
  id: 990185,
  name: '[E2E] 별장',
  recipient_name: '김수정',
  recipient_phone: '010-9999-0000',
  country_code: 'KR',
  zipcode: '99999',
  address: 'E2E 시 가짜로 1',
  address_detail: 'E2E 수정 상세',
  city: '',
  address_line_1: '',
  address_line_2: '',
  state: '',
  postal_code: '',
  is_default: true,
  abilities: { can_update: true, can_delete: true },
};

/** 새 배송지로 칠 값(칸 접근 이름 → 값) */
const NEW_VALUES: Array<{ label: string; key: string; value: string }> = [
  { label: '배송지명', key: 'name', value: 'E2E 새집' },
  { label: '받는 분', key: 'recipient_name', value: '이새로' },
  { label: '연락처', key: 'recipient_phone', value: '010-1234-5678' },
  { label: '상세 주소', key: 'address_detail', value: '101동 202호' },
];

/** 앞 배송지에서 새 본문에 섞이면 안 되는 값 */
const PREVIOUS_MARKERS = [String(FAKE_ADDRESS.id), FAKE_ADDRESS.name, FAKE_ADDRESS.recipient_name, FAKE_ADDRESS.recipient_phone, FAKE_ADDRESS.address_detail, FAKE_ADDRESS.zipcode];

type WriteRecord = { method: string; path: string; body: Record<string, unknown> | null };

/**
 * 로그인·쓰기 가로채기·배송지 목록 모의를 건다.
 *
 * @param page Playwright 페이지
 * @param token 계정 토큰
 * @returns 배송지 쓰기 기록
 */
async function prepare(page: Page, token: string): Promise<WriteRecord[]> {
  await authenticateWith(page, token);
  await guardWrites(page);
  const writes: WriteRecord[] = [];
  await page.route(
    (url) => url.pathname.startsWith('/api/modules/sirsoft-ecommerce/user/addresses'),
    (route) => {
      const req = route.request();
      if (req.method() === 'GET') {
        return route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ success: true, data: { addresses: { data: [FAKE_ADDRESS], meta: { total: 1 } } } }),
        });
      }
      let body: Record<string, unknown> | null = null;
      try {
        body = req.postDataJSON();
      } catch {
        body = null;
      }
      writes.push({ method: req.method(), path: new URL(req.url()).pathname, body });

      return route.fallback(); // guardWrites 가 가짜 200 으로 답한다
    },
  );
  await page.setViewportSize({ width: 1280, height: 900 });

  return writes;
}

/**
 * 열린 창(대화상자).
 *
 * @param page Playwright 페이지
 * @returns 대화상자 locator
 */
function dialog(page: Page): Locator {
  return page.getByRole('dialog').last();
}

/**
 * 창을 주어진 갈래로 닫는다.
 *
 * @param page Playwright 페이지
 * @param way 닫는 갈래
 */
async function closeBy(page: Page, way: 'x' | 'escape' | 'backdrop' | 'cancel'): Promise<void> {
  if (way === 'x') await dialog(page).getByRole('button', { name: '모달 닫기' }).click();
  else if (way === 'escape') await page.keyboard.press('Escape');
  else if (way === 'backdrop') await page.mouse.click(8, 8);
  else await dialog(page).getByRole('button', { name: '취소', exact: true }).click();
  await expect(page.getByRole('dialog')).toHaveCount(0, { timeout: 10_000 });
}

/**
 * 전역 상태의 한 키를 읽는다.
 *
 * @param page Playwright 페이지
 * @param key 전역 키
 * @returns 값(없으면 null)
 */
async function globalKey(page: Page, key: string): Promise<unknown> {
  return page.evaluate((k) => (window as any).G7Core.state.get()?.[k] ?? null, key);
}

/**
 * 창 안 네 칸을 칸 사이 쉼 없이 채운다.
 *
 * 칸은 접근 이름으로 먼저 모두 찾아 두고(이 사이에는 입력을 보내지 않는다), 값은 `page.evaluate` 한 번 — 한 작업(task) — 안에서
 * 네이티브 value setter + input/change 이벤트로 네 칸에 차례로 보낸다. 미뤄진 전역 다시 그리기보다 항상 먼저라, 렌더 시점 사본으로
 * 쓰는 결함이 있으면 결정적으로 앞 칸이 지워진다. 상태(G7Core)는 쓰지 않는다 — 엔진의 입력 처리 경로를 그대로 탄다.
 *
 * @param page Playwright 페이지
 */
async function typeNewAddressFast(page: Page): Promise<void> {
  const handles: ElementHandle<HTMLElement | SVGElement>[] = [];
  for (const f of NEW_VALUES) {
    const input = dialog(page).getByRole('textbox', { name: f.label, exact: true });
    await expect(input, `${f.label} 칸`).toBeEditable();
    const handle = await input.elementHandle();
    if (!handle) throw new Error(`${f.label} 칸을 찾지 못했다`);
    handles.push(handle);
  }
  try {
    await page.evaluate(
      ({ els, values }) => {
        const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!;
        els.forEach((node, i) => {
          const el = node as HTMLInputElement;
          el.focus();
          setter.call(el, values[i]);
          el.dispatchEvent(new Event('input', { bubbles: true }));
          el.dispatchEvent(new Event('change', { bubbles: true }));
        });
      },
      { els: handles, values: NEW_VALUES.map((f) => f.value) },
    );
  } finally {
    await Promise.all(handles.map((h) => h.dispose()));
  }
}

/**
 * 쉼 없이 넣은 네 칸이 창 이름 공간에 모두 남았는지 본다(저장 전에 — 빠진 칸이 있으면 어느 칸인지 여기서 드러난다).
 *
 * @param read 편집 대상 배송지를 읽는 함수
 */
async function expectEveryFieldInState(read: () => Promise<unknown>): Promise<void> {
  const expected = Object.fromEntries(NEW_VALUES.map((f) => [f.key, f.value]));
  await expect.poll(read, { message: '창 이름 공간에 네 칸', timeout: 5_000 }).toMatchObject(expected);
}

/**
 * 마이페이지 배송지 레이아웃 응답의 배송지 폼에 `data-key` 자동바인딩을 되살린다 — 이 브라우저에서만(서버·DB 불변).
 *
 * `editingAddress.*` 를 쓰는 Form 에 `dataKey: "_global.editingAddress"` 를 달고, 그 안에서 이름(`name`) 있는 칸의
 * change 점 경로 쓰기(`editingAddress.{name}`)를 걷어 낸다 — 이 칸들은 자동바인딩만으로 전역에 쓴다(이전 레이아웃 구조).
 * 이름 없는 칸(배송지명)은 종전에도 change 쓰기였으므로 그대로 둔다.
 *
 * @param page Playwright 페이지
 * @returns 되살린 폼 수·걷어 낸 칸 쓰기 수를 돌려주는 함수
 */
async function restoreMypageFormDataKey(page: Page): Promise<() => { forms: number; fields: number }> {
  const ns = 'editingAddress';
  const count = { forms: 0, fields: 0 };
  const writesNs = (node: unknown): boolean =>
    JSON.stringify(node ?? null).includes(`"${ns}.`);
  const stripFieldWrites = (node: unknown): void => {
    if (Array.isArray(node)) {
      node.forEach(stripFieldWrites);

      return;
    }
    if (!node || typeof node !== 'object') return;
    const n = node as { props?: Record<string, unknown>; actions?: Array<{ type?: string; handler?: string; params?: Record<string, unknown> }> };
    const name = typeof n.props?.name === 'string' ? n.props.name : '';
    if (name && Array.isArray(n.actions)) {
      const before = n.actions.length;
      n.actions = n.actions.filter(
        (a) => !(a?.type === 'change' && a?.handler === 'setState' && a.params && `${ns}.${name}` in a.params),
      );
      count.fields += before - n.actions.length;
    }
    for (const v of Object.values(node)) stripFieldWrites(v);
  };
  const walk = (node: unknown): void => {
    if (Array.isArray(node)) {
      node.forEach(walk);

      return;
    }
    if (!node || typeof node !== 'object') return;
    const n = node as { name?: string; dataKey?: string; children?: unknown };
    if (n.name === 'Form' && !n.dataKey && writesNs(n.children)) {
      n.dataKey = `_global.${ns}`;
      count.forms += 1;
      stripFieldWrites(n.children);

      return;
    }
    for (const v of Object.values(node)) walk(v);
  };
  await page.route(
    (url) => /^\/api\/layouts\/[^/]+\/mypage\/addresses\.json$/.test(url.pathname),
    async (route) => {
      const res = await route.fetch();
      const body = await res.json();
      walk(body);

      return route.fulfill({ response: res, json: body });
    },
  );

  return () => ({ ...count });
}

/**
 * 저장 본문이 새 값 네 칸을 모두 담고 앞 배송지 값이 섞이지 않았는지 본다.
 *
 * @param writes 배송지 쓰기 기록
 */
function expectCleanCreateBody(writes: WriteRecord[]): void {
  const creates = writes.filter((w) => w.method === 'POST');
  expect(writes.filter((w) => w.method === 'PUT'), '앞 배송지를 PUT 으로 덮어쓰지 않는다').toEqual([]);
  expect(creates, '등록 POST 1회').toHaveLength(1);
  const body = creates[0].body ?? {};
  for (const f of NEW_VALUES) expect(body[f.key], `본문 ${f.key}`).toBe(f.value);
  expect(body.id, '본문에 앞 배송지 id 없음').toBeUndefined();
  const text = JSON.stringify(body);
  expect(PREVIOUS_MARKERS.filter((m) => text.includes(m)), '앞 배송지 값 섞임 0').toEqual([]);
}

test.describe('마이페이지 배송지 창', () => {
  test.skip(!hasEnvAccount(), ACCOUNT_SKIP_REASON);

  /**
   * 「새 주소 추가」 창에 네 칸을 쉼 없이 넣고 저장한다 — 저장 성공은 창을 닫고, 폼 unmount 가 이름 공간을 비운다.
   *
   * @param page Playwright 페이지
   * @param writes 배송지 쓰기 기록
   */
  async function fastEntryAndSave(page: Page, writes: WriteRecord[]): Promise<void> {
    await page.getByRole('button', { name: '새 주소 추가' }).first().click();
    await expect(dialog(page).getByRole('textbox', { name: '받는 분', exact: true })).toHaveValue('');
    await typeNewAddressFast(page);
    await expectEveryFieldInState(() => globalKey(page, 'editingAddress'));
    await dialog(page).getByRole('button', { name: '새 주소 추가' }).click();
    await expect.poll(() => writes.filter((w) => w.method === 'POST').length, { message: '등록 요청' }).toBe(1);
    expectCleanCreateBody(writes);

    // 저장 성공 갈래 — 창이 닫히고(close-modal) 폼 unmount 가 편집 대상 배송지를 비운다(modal-usage.md 「창을 닫을 때 이름 공간 비우기」)
    await expect(page.getByRole('dialog'), '저장 성공 뒤 창 닫힘').toHaveCount(0, { timeout: 10_000 });
    await expect.poll(() => globalKey(page, 'editingAddress'), { message: '저장 성공 뒤 editingAddress' }).toBeNull();
  }

  /**
   * @scenario case=mypage_edit_close_then_new_fast
   * @effects create_body_has_every_field, create_body_no_previous_address, modal_namespace_null_after_save
   */
  test('「수정」 창을 X 로 닫고 「새 주소 추가」 에 쉼 없이 넣어 저장하면 본문이 새 값 네 칸뿐이고 저장 뒤 editingAddress 가 null 이다', async ({ page, request }) => {
    const writes = await prepare(page, (await envAccountToken(request))!);
    await page.goto('/mypage/addresses');
    await waitForSpa(page);
    await dismissCookieBanner(page);

    await page.getByTestId('address-card').getByRole('button', { name: '수정', exact: true }).click();
    await expect(dialog(page).getByRole('textbox', { name: '받는 분', exact: true })).toHaveValue(FAKE_ADDRESS.recipient_name);
    await closeBy(page, 'x');

    await fastEntryAndSave(page, writes);
  });

  /**
   * @scenario case=mypage_new_fast_datakey_restored
   * @effects create_body_has_every_field, modal_namespace_null_after_save
   */
  test('레이아웃에 data-key 자동바인딩을 되살려도(엔진 고침만으로) 「새 주소 추가」 에 쉼 없이 넣은 네 칸이 본문에 모두 실린다', async ({ page, request }) => {
    const writes = await prepare(page, (await envAccountToken(request))!);
    const restored = await restoreMypageFormDataKey(page);
    await page.goto('/mypage/addresses');
    await waitForSpa(page);
    await dismissCookieBanner(page);
    // 레이아웃 응답은 SPA 부팅 뒤에 올 수 있다 — 한 번 재지 않고 기다린다
    await expect.poll(() => restored().forms, { message: '배송지 폼에 data-key 를 되살렸다', timeout: 30_000 }).toBe(1);
    // 받는 분·연락처·상세 주소 — 자동바인딩만으로 쓰는 칸(배송지명은 이름이 없어 종전에도 change 쓰기)
    expect(restored().fields, '걷어 낸 칸 change 쓰기').toBeGreaterThanOrEqual(3);

    await fastEntryAndSave(page, writes);
  });

  for (const way of ['x', 'escape', 'backdrop', 'cancel'] as const) {
    /**
     * @scenario case=mypage_close_x
     * @effects modal_namespace_null_after_close
     */
    /**
     * @scenario case=mypage_close_escape
     * @effects modal_namespace_null_after_close
     */
    /**
     * @scenario case=mypage_close_backdrop
     * @effects modal_namespace_null_after_close
     */
    /**
     * @scenario case=mypage_close_cancel
     * @effects modal_namespace_null_after_close
     */
    test(`입력 중인 창을 ${way} 로 닫으면 _global.editingAddress 가 null 이다`, async ({ page, request }) => {
      await prepare(page, (await envAccountToken(request))!);
      await page.goto('/mypage/addresses');
      await waitForSpa(page);
      await dismissCookieBanner(page);

      await page.getByRole('button', { name: '새 주소 추가' }).first().click();
      const recipient = dialog(page).getByRole('textbox', { name: '받는 분', exact: true });
      await recipient.click();
      await page.keyboard.type('닫기 전 입력', { delay: 0 });
      await expect.poll(() => globalKey(page, 'editingAddress')).toMatchObject({ recipient_name: '닫기 전 입력' });

      await closeBy(page, way);
      await expect.poll(() => globalKey(page, 'editingAddress'), { message: `${way} 뒤 editingAddress` }).toBeNull();
    });
  }
});

test.describe('주문서 「배송지 관리」 창', () => {
  test.skip(!hasEnvAccount(), ACCOUNT_SKIP_REASON);

  /**
   * 진행 중 주문서가 있는지 — 없으면 이 축은 잴 수 없다(만들려면 장바구니 쓰기가 필요하다).
   * 주문서 배송지 적용(`PUT checkout`)은 서버에 보내지 않고 본문만 기록한 뒤 방금 받은 주문서 응답으로 답한다
   * (가짜 `{data:{}}` 로 답하면 주문서 데이터 소스가 빈 값으로 바뀐다).
   *
   * @param page Playwright 페이지
   * @param token 계정 토큰
   * @returns 가로챈 `PUT checkout` 본문 기록
   */
  async function openCheckoutOrSkip(page: Page, token: string): Promise<Array<Record<string, unknown> | null>> {
    const res = await page.request.get('/api/modules/sirsoft-ecommerce/checkout', {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    test.skip(res.status() !== 200, `진행 중 주문서 없음(GET checkout ${res.status()}) — 그 계정으로 「주문하기」 까지 간 뒤 실행한다`);
    const checkoutBody = await res.text();
    const checkoutPuts: Array<Record<string, unknown> | null> = [];
    await page.route(
      (url) => url.pathname === '/api/modules/sirsoft-ecommerce/checkout',
      (route) => {
        const req = route.request();
        if (req.method() !== 'PUT') return route.fallback();
        let body: Record<string, unknown> | null = null;
        try {
          body = req.postDataJSON();
        } catch {
          body = null;
        }
        checkoutPuts.push(body);

        return route.fulfill({ status: 200, contentType: 'application/json', body: checkoutBody });
      },
    );
    await page.goto('/shop/checkout');
    await waitForSpa(page);
    await dismissCookieBanner(page);
    await expect(page.getByRole('button', { name: '배송지 관리' }).first()).toBeVisible({ timeout: 30_000 });

    return checkoutPuts;
  }

  /**
   * @scenario case=checkout_edit_close_then_new_fast
   * @effects create_body_has_every_field, create_body_no_previous_address, checkout_editing_null_after_save_modal_stays
   */
  test('「수정」 폼을 X 로 닫고 다시 열어 「새 배송지」 에 쉼 없이 넣어 저장하면 본문이 새 값 네 칸뿐이고 창은 목록으로 남아 editing 이 null 이다', async ({ page, request }) => {
    const token = (await envAccountToken(request))!;
    const writes = await prepare(page, token);
    await openCheckoutOrSkip(page, token);

    await page.getByRole('button', { name: '배송지 관리' }).first().click();
    await dialog(page).getByRole('button', { name: '수정', exact: true }).first().click();
    await expect(dialog(page).getByRole('textbox', { name: '받는 분', exact: true })).toHaveValue(FAKE_ADDRESS.recipient_name);
    await closeBy(page, 'x');

    await page.getByRole('button', { name: '배송지 관리' }).first().click();
    await dialog(page).getByRole('button', { name: '새 배송지' }).click();
    await expect(dialog(page).getByRole('textbox', { name: '받는 분', exact: true })).toHaveValue('');
    await typeNewAddressFast(page);
    await expectEveryFieldInState(async () => ((await globalKey(page, 'checkoutAddressModal')) as { editing?: unknown } | null)?.editing ?? null);
    await dialog(page).getByRole('button', { name: '새 주소 추가' }).click();
    await expect.poll(() => writes.filter((w) => w.method === 'POST').length, { message: '등록 요청' }).toBe(1);
    expectCleanCreateBody(writes);

    // 저장 성공 갈래 — 이 창은 닫지 않고 목록으로 돌아가며 쓰던 배송지(editing)만 비운다(_modal_address_manage 성공 갈래)
    await expect.poll(
      async () => {
        const m = (await globalKey(page, 'checkoutAddressModal')) as { mode?: unknown; editing?: unknown; isSaving?: unknown } | null;

        return m && { mode: m.mode ?? null, editing: m.editing ?? null, isSaving: m.isSaving ?? null };
      },
      { message: '저장 성공 뒤 checkoutAddressModal' },
    ).toEqual({ mode: 'list', editing: null, isSaving: false });
    await expect(page.getByRole('dialog'), '저장 성공 뒤에도 창은 열려 있다').toHaveCount(1);
    await expect(dialog(page).getByRole('button', { name: '새 배송지' }), '목록 얼굴로 돌아왔다').toBeVisible();
  });

  /**
   * @scenario case=checkout_close_select
   * @effects modal_namespace_null_after_close, select_hands_address_to_checkout
   */
  test('목록에서 배송지를 골라 「선택」 으로 닫으면 주문서에 그 배송지가 넘어가고 _global.checkoutAddressModal 이 null 이다', async ({ page, request }) => {
    const token = (await envAccountToken(request))!;
    await prepare(page, token);
    const checkoutPuts = await openCheckoutOrSkip(page, token);

    await page.getByRole('button', { name: '배송지 관리' }).first().click();
    // 카드의 정보 영역 단추(aria-pressed) — 접근 이름에 배송지명이 든다
    await dialog(page).getByRole('button', { name: FAKE_ADDRESS.name }).click();
    await expect.poll(() => globalKey(page, 'checkoutAddressModal')).toMatchObject({ selectedAddressId: FAKE_ADDRESS.id, selectedAddressObj: { id: FAKE_ADDRESS.id } });
    const putsBefore = checkoutPuts.length;

    await dialog(page).getByRole('button', { name: '선택', exact: true }).click();
    await expect(page.getByRole('dialog'), '「선택」 뒤 창 닫힘').toHaveCount(0, { timeout: 10_000 });
    await expect.poll(() => globalKey(page, 'checkoutAddressModal'), { message: '「선택」 뒤 checkoutAddressModal' }).toBeNull();
    // 이름 공간을 비우기 전에 이벤트로 넘긴 배송지가 주문서에 닿았다 — 주문서가 그 우편번호로 배송비를 다시 받는다
    await expect.poll(() => checkoutPuts.length, { message: '주문서 배송지 적용 요청', timeout: 10_000 }).toBeGreaterThan(putsBefore);
    expect(checkoutPuts[checkoutPuts.length - 1]?.zipcode, '적용 요청의 우편번호 = 고른 배송지').toBe(FAKE_ADDRESS.zipcode);
  });

  for (const way of ['x', 'escape', 'backdrop', 'cancel'] as const) {
    /**
     * @scenario case=checkout_close_x
     * @effects modal_namespace_null_after_close
     */
    /**
     * @scenario case=checkout_close_escape
     * @effects modal_namespace_null_after_close
     */
    /**
     * @scenario case=checkout_close_backdrop
     * @effects modal_namespace_null_after_close
     */
    /**
     * @scenario case=checkout_close_cancel
     * @effects modal_namespace_null_after_close
     */
    test(`「수정」 폼까지 연 창을 ${way === 'cancel' ? '목록의 취소' : way} 로 닫으면 _global.checkoutAddressModal 이 null 이다`, async ({ page, request }) => {
      const token = (await envAccountToken(request))!;
      await prepare(page, token);
      await openCheckoutOrSkip(page, token);

      await page.getByRole('button', { name: '배송지 관리' }).first().click();
      if (way !== 'cancel') {
        // 「취소」 단추는 목록 얼굴에만 있다 — 나머지 갈래는 수정 폼(배송지 개인정보가 실린 상태)에서 닫는다
        await dialog(page).getByRole('button', { name: '수정', exact: true }).first().click();
        await expect.poll(() => globalKey(page, 'checkoutAddressModal')).toMatchObject({ mode: 'form', editing: { id: FAKE_ADDRESS.id } });
      } else {
        await expect.poll(() => globalKey(page, 'checkoutAddressModal')).toMatchObject({ mode: 'list' });
      }

      await closeBy(page, way);
      await expect.poll(() => globalKey(page, 'checkoutAddressModal'), { message: `${way} 뒤 checkoutAddressModal` }).toBeNull();
    });
  }
});
