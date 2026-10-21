/**
 * 엔진 상태 쓰기 두 가지를 브라우저로 고정한다 (7.1.1 엔진 대안).
 * 템플릿 sirsoft-basic (유저 화면 홈 — 레이아웃 응답에 시험용 노드를 주입, 비회원).
 *
 * 결함 ①(`d392dd42cd` 부터의 구조): `data-key="_global.*"` 폼의 자동바인딩이 칸마다 **렌더 시점 `_global` 사본**에 그 칸만
 *   얹어 전역 전체를 덮어썼다. 전역 다시 그리기는 미뤄지므로, 다음 칸 입력이 그 렌더보다 먼저 오면 앞 칸 값이 없는 사본이
 *   앞 칸을 지웠다(주문서 4칸 중 1칸만 남음). 고침: 쓰기 시점 상태를 받는 함수형 갱신(`DynamicRenderer.performStateUpdate`).
 * 결함 ②(엔진 대안): setState 기본 깊은 병합에서 빈 객체 `{}` 가 기존 값을 지우지 못했다(`{색상:블랙}` + `{}` = `{색상:블랙}`).
 *   번들 레이아웃은 `null` → `{}` 2단계로 피해 왔다. 고침: setState 액션의 빈 객체 값은 그 자리를 비운다(`ActionDispatcher`).
 *
 * 데이터: 홈 레이아웃 응답(`GET /api/layouts/{템플릿}/home.json`)을 받아 이 브라우저에서만 노드를 앞에 덧붙인다(서버·DB 불변).
 *   ① 은 `data-key="_global.e2eG19Form"` Div 안 입력칸 4개, ② 는 지역·전역 setState 단추 쌍과 결과 표시 Span.
 *   쓰기 요청은 가로챈다. 레이아웃은 번들 우회(`null` 선행)를 쓰지 않으므로 엔진 동작만 갈린다.
 *
 * 수정 전 대조: `G7_E2E_ENGINE_BUNDLE=<수정 전 번들 경로>` 로 돌리면 이 브라우저만 그 번들로 바꿔 끼운다(`tests/Playwright/fixtures/env-account.ts`).
 *
 * @scenario-manifest tests/scenarios/e2e-engine-global-form-write-base.yaml
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; npx playwright test --config=tests/Playwright/playwright.config.ts tests/Playwright/specs/engine-global-form-write-base.spec.ts --workers=1
 */
import { test, expect, type Page } from '@playwright/test';
import { guardWrites, waitForSpa } from '../fixtures/env-account';

/** 전역 폼 칸 — 배송지 등록 폼과 같은 이름 */
const FIELDS = ['name', 'recipient_name', 'recipient_phone', 'address_detail'] as const;

/** 칸마다 넣을 값 */
const VALUES: Record<(typeof FIELDS)[number], string> = {
  name: '회사',
  recipient_name: '홍길동',
  recipient_phone: '010-1234-5678',
  address_detail: '3층 301호',
};

/**
 * 주입할 노드 — ① 전역 폼, ② setState 단추 쌍(지역·전역)과 결과 표시.
 *
 * @returns 홈 components 앞에 붙일 노드 목록
 */
function probeNodes(): unknown[] {
  const setState = (target: 'local' | 'global', key: string, value: unknown) => [
    { type: 'click', handler: 'setState', params: { target, [key]: value } },
  ];

  // 한 감싸개 Div 안에 둔다 — 레이아웃 맨 위 형제 노드는 각자 뿌리 렌더러라 지역 상태 표시가 다시 그려지지 않을 수 있다(실제 화면도 뿌리 Div 아래에 둔다)
  const children = [
    {
      id: 'e2e_g19_form',
      type: 'basic',
      name: 'Div',
      dataKey: '_global.e2eG19Form',
      props: { 'data-testid': 'e2e-g19-form' },
      children: FIELDS.map((f) => ({
        id: `e2e_g19_${f}`,
        type: 'basic',
        name: 'Input',
        props: { type: 'text', name: f, 'aria-label': `e2e-${f}`, 'data-testid': `e2e-g19-${f}` },
      })),
    },
    { id: 'e2e_g19_local_fill', type: 'basic', name: 'Button', props: { type: 'button', 'data-testid': 'e2e-local-fill' }, text: 'local-fill', actions: setState('local', 'e2eFilters', { 색상: '블랙', 크기: 'L' }) },
    { id: 'e2e_g19_local_clear', type: 'basic', name: 'Button', props: { type: 'button', 'data-testid': 'e2e-local-clear' }, text: 'local-clear', actions: setState('local', 'e2eFilters', {}) },
    { id: 'e2e_g19_local_view', type: 'basic', name: 'Span', props: { 'data-testid': 'e2e-local-view' }, text: '{{JSON.stringify(_local.e2eFilters ?? null)}}' },
    { id: 'e2e_g19_global_fill', type: 'basic', name: 'Button', props: { type: 'button', 'data-testid': 'e2e-global-fill' }, text: 'global-fill', actions: setState('global', 'e2eGFilters', { 색상: '블랙' }) },
    { id: 'e2e_g19_global_clear', type: 'basic', name: 'Button', props: { type: 'button', 'data-testid': 'e2e-global-clear' }, text: 'global-clear', actions: setState('global', 'e2eGFilters', {}) },
    { id: 'e2e_g19_global_view', type: 'basic', name: 'Span', props: { 'data-testid': 'e2e-global-view' }, text: '{{JSON.stringify(_global.e2eGFilters ?? null)}}' },
  ];

  return [{ id: 'e2e_g19_probe', type: 'basic', name: 'Div', props: { 'data-testid': 'e2e-g19-probe' }, children }];
}

/**
 * 홈 레이아웃 응답에 시험 노드를 주입하고 홈을 연다.
 *
 * @param page Playwright 페이지
 */
async function openProbeHome(page: Page): Promise<void> {
  await guardWrites(page);
  let injected = 0;
  await page.route(
    (url) => /^\/api\/layouts\/[^/]+\/home\.json$/.test(url.pathname),
    async (route) => {
      const res = await route.fetch();
      const body = await res.json();
      const layout = body?.data ?? body;
      if (Array.isArray(layout?.components)) {
        layout.components = [...probeNodes(), ...layout.components];
        injected += 1;
      }

      return route.fulfill({ response: res, json: body });
    },
  );
  await page.goto('/');
  await waitForSpa(page);
  await expect.poll(() => injected, { message: '홈 레이아웃 응답에 주입했다', timeout: 20_000 }).toBeGreaterThan(0);
  await expect(page.getByTestId('e2e-g19-name')).toBeVisible({ timeout: 20_000 });
}

/**
 * 전역 폼 값(`_global.e2eG19Form`)을 읽는다.
 *
 * @param page Playwright 페이지
 * @returns 폼 값(없으면 null)
 */
async function readGlobalForm(page: Page): Promise<Record<string, unknown> | null> {
  return page.evaluate(() => (window as any).G7Core.state.get()?.e2eG19Form ?? null);
}

test.describe('엔진 — 전역 data-key 폼 쓰기 base', () => {
  /**
   * @scenario case=global_form_same_tick_fields
   * @effects global_form_keeps_every_field, global_form_screen_matches_state
   */
  test('네 칸을 다시 그리기 전에 잇달아 채워도 네 칸이 모두 전역 상태에 남는다', async ({ page }) => {
    await openProbeHome(page);

    // 칸 사이 쉼 0 — 한 작업(task) 안에서 네 칸에 입력 이벤트를 차례로 보낸다(미뤄진 전역 다시 그리기보다 항상 먼저)
    await page.evaluate(
      ({ fields, values }) => {
        const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!;
        for (const f of fields) {
          const el = document.querySelector<HTMLInputElement>(`[data-testid="e2e-g19-${f}"]`)!;
          el.focus();
          setter.call(el, values[f]);
          el.dispatchEvent(new Event('input', { bubbles: true }));
        }
      },
      { fields: [...FIELDS], values: VALUES },
    );

    await expect.poll(() => readGlobalForm(page), { message: '전역 폼 네 칸', timeout: 5_000 }).toMatchObject(VALUES);
    // 화면 = 상태
    for (const f of FIELDS) {
      await expect(page.getByTestId(`e2e-g19-${f}`)).toHaveValue(VALUES[f]);
    }
  });

  /**
   * @scenario case=global_form_keyboard_tab
   * @effects global_form_keeps_every_field, global_form_screen_matches_state
   */
  test('키보드로 칸마다 쉼 없이 치고 Tab 으로 넘겨도 네 칸이 모두 남는다', async ({ page }) => {
    await openProbeHome(page);

    await page.getByTestId('e2e-g19-name').click();
    for (let i = 0; i < FIELDS.length; i += 1) {
      await page.keyboard.type(VALUES[FIELDS[i]], { delay: 0 });
      if (i < FIELDS.length - 1) await page.keyboard.press('Tab');
    }

    await expect.poll(() => readGlobalForm(page), { message: '전역 폼 네 칸', timeout: 5_000 }).toMatchObject(VALUES);
    for (const f of FIELDS) {
      await expect(page.getByTestId(`e2e-g19-${f}`)).toHaveValue(VALUES[f]);
    }
  });
});

test.describe('엔진 — setState 빈 객체 교체 (엔진 대안)', () => {
  /**
   * @scenario case=setstate_empty_object_local
   * @effects empty_object_replaces_previous_value
   */
  test('지역 상태 — 채운 객체 키에 {} 를 쓰면 {} 가 된다(null 선행 없이)', async ({ page }) => {
    await openProbeHome(page);

    await page.getByTestId('e2e-local-fill').click();
    await expect(page.getByTestId('e2e-local-view')).toHaveText('{"색상":"블랙","크기":"L"}');
    await page.getByTestId('e2e-local-clear').click();
    await expect(page.getByTestId('e2e-local-view')).toHaveText('{}');
    expect(await page.evaluate(() => (window as any).G7Core.state.getLocal()?.e2eFilters)).toEqual({});
  });

  /**
   * @scenario case=setstate_empty_object_global
   * @effects empty_object_replaces_previous_value
   */
  test('전역 상태 — 채운 객체 키에 {} 를 쓰면 {} 가 된다(null 선행 없이)', async ({ page }) => {
    await openProbeHome(page);

    await page.getByTestId('e2e-global-fill').click();
    await expect(page.getByTestId('e2e-global-view')).toHaveText('{"색상":"블랙"}');
    await page.getByTestId('e2e-global-clear').click();
    await expect(page.getByTestId('e2e-global-view')).toHaveText('{}');
    expect(await page.evaluate(() => (window as any).G7Core.state.get()?.e2eGFilters)).toEqual({});
  });
});
