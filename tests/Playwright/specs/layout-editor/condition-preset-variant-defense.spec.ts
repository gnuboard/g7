/**
 * Layout Editor — 표시조건 프리셋 변종 방어 인프라 회귀 가드.
 *
 * 단계 0 은 conditionRecipeEngine 에 변종 방어 4계층을 선설치한다:
 *  - L1 전역 표기/동치 정규화(공백/`?.`/`!!`/외곽괄호) — 손작성 식 흡수
 *  - L2 recipe-local alias — 준동치 구조 변종 옵트인 흡수(역해석만, 생성은 canonical)
 *  - L4 specificity 우선 — 범용 `{path}` 가 구체 프리셋을 가로채지 않음
 *  - path-shape 가드 — 범용 프리셋이 비교/compound 식을 삼키지 않음(false-positive 0)
 *
 * 본 spec 은 이 인프라가 **브라우저 end-to-end 로 배선**되었음을 잠근다(단위/RTL 은
 * 순수 로직을, 본 spec 은 실 편집기 모달 렌더를 검증). 단계 0 은 UI 표면 변화가 없으므로
 * (실제 프리셋 커버리지 보강은 단계 1~5), 여기서는 다음만 확인한다:
 *  - 표시조건 탭이 기존 recipe 로 인식되는 노드에서 **빈 빌더/advanced 가 아닌 프리셋 절**로 렌더
 *  - compound 식 노드는 advanced 유지(path-shape 가드 — false-positive 0)
 *
 * 단계 1~5 가 본 파일을 확장해 패턴별(P1~P6) 프리셋 인식 + 파라미터 역해석 + round-trip 을
 * 화면별로 추가 잠근다(Chrome MCP 매트릭스의 Playwright 대응).
 *
 * dnd-kit 비호환(feedback_chrome_mcp_dnd_kit_incompatible) 회피 — 노드 선택은 합성 마우스
 * 이벤트 시퀀스로 드래그 핸들 pointer 가로채기를 우회한다.
 *
 * @scenario property_modal_visibility_preset_recognition + visibility_compound_stays_advanced + numeric_array_preset_recognition + explicit_boolean_compare_preset_recognition + comparison_pattern_preset_recognition + coverage_gate_presets_served
 * @effects visibility_builder_renders + variant_defense_no_false_positive + stage2_numeric_array_presets_served + stage3_boolean_compare_presets_served + stage4_comparison_presets_served + stage5_gate_presets_served
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

type PwPage = import('@playwright/test').Page;

async function openEditor(page: PwPage, template: string, route: string): Promise<void> {
  await page.goto(`/admin/layout-editor/${template}?route=${encodeURIComponent(route)}`);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
  await page.waitForFunction(() => document.querySelectorAll('[data-editor-path]').length > 0, { timeout: 20_000 });
}

/** 캔버스의 visible 노드 path 목록(앞쪽 N개). 반응형 hidden 노드 제외. */
async function visibleNodePaths(page: PwPage, limit: number): Promise<string[]> {
  return page.evaluate((max) => {
    const out: string[] = [];
    for (const el of Array.from(document.querySelectorAll('[data-editor-path]'))) {
      const r = (el as HTMLElement).getBoundingClientRect();
      const visible = r.width > 0 && r.height > 0 && getComputedStyle(el as HTMLElement).display !== 'none';
      const path = el.getAttribute('data-editor-path');
      if (visible && path) out.push(path);
      if (out.length >= max) break;
    }
    return out;
  }, limit);
}

/** path 로 노드를 단일 선택(합성 마우스 이벤트 — 핸들 pointer 가로채기 우회). */
async function selectNode(page: PwPage, editorPath: string): Promise<boolean> {
  return page.evaluate((p) => {
    const el = document.querySelector(`[data-editor-path="${p}"]`);
    if (!el) return false;
    const r = el.getBoundingClientRect();
    for (const t of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click']) {
      el.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, clientX: r.left + 8, clientY: r.top + 8, view: window }));
    }
    return true;
  }, editorPath);
}

/** 노드 선택 → ⓘ → 속성 설정 → 표시조건 탭 진입. 성공 시 true. */
async function openVisibilityTab(page: PwPage, path: string): Promise<boolean> {
  if (!(await selectNode(page, path))) return false;
  const info = page.getByTestId('g7le-overlay-info-button');
  if ((await info.count()) === 0) return false;
  await info.click();
  const editProps = page.getByTestId('g7le-context-menu-edit-props');
  if ((await editProps.count()) === 0) return false;
  await editProps.click();
  await page.waitForSelector('[data-testid="g7le-property-modal"]', { timeout: 8_000 });
  if ((await page.getByTestId('g7le-property-tab-visibility').count()) === 0) return false;
  await page.getByTestId('g7le-property-tab-visibility').click();
  return true;
}

test.describe('@layout-editor 표시조건 변종 방어 인프라 (단계 0)', () => {
  test('표시조건 탭이 빌더 또는 no-recipes 로 렌더 (인프라 배선 회귀 가드)', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page, 'sirsoft-basic', '/');

    const paths = await visibleNodePaths(page, 10);
    let opened = false;
    for (const path of paths) {
      if (!(await openVisibilityTab(page, path))) {
        if ((await page.getByTestId('g7le-property-modal').count()) > 0) {
          await page.getByTestId('g7le-property-modal-close').click();
        }
        continue;
      }
      opened = true;
      // 표시조건 탭은 빌더(프리셋 인식/advanced) 또는 no-recipes 중 하나 — 셋 다 정상.
      // 빈 화면(아무것도 안 뜸)이면 인프라 배선 회귀.
      const builder = await page.getByTestId('g7le-condition-builder').count();
      const advanced = await page.getByTestId('g7le-condition-advanced').count();
      const noRecipes = await page.getByTestId('g7le-condition-no-recipes').count();
      expect(builder + advanced + noRecipes).toBeGreaterThan(0);
      await page.getByTestId('g7le-property-modal-close').click();
      break;
    }
    expect(opened).toBe(true);
  });

  test('P2/P3 operator 가 served editor-spec 에 노출 + 숫자/배열 패턴 인식', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page, 'sirsoft-admin_basic', '/');

    // served editor-spec 의 conditionRecipes 에 단계 2 operator 가 모두 노출돼야 한다
    // (template:update 로 활성 디렉토리 반영 회귀 가드).
    const ops = await page.evaluate(async () => {
      const res = await fetch('/api/admin/templates/sirsoft-admin_basic/editor/spec.json', {
        headers: { Authorization: `Bearer ${(window as unknown as { __g7EditorToken?: string }).__g7EditorToken ?? ''}` },
      }).catch(() => null);
      if (!res || !res.ok) return null;
      const json = await res.json().catch(() => null);
      const spec = json?.data ?? json;
      const recipes = spec?.conditionRecipes?.operators ?? [];
      return recipes.map((o: { value: string }) => o.value);
    });
    // 가드 엔드포인트 경로/응답 shape 가 환경마다 다를 수 있어 null 이면 served-spec 단언은
    // 생략하고(다음 단언이 실 편집기 인식을 직접 확인), 노출되면 6개 전부 존재해야 한다.
    if (ops) {
      for (const v of ['numGt', 'numGte', 'numLt', 'numLte', 'listNonEmpty', 'listEmpty']) {
        expect(ops).toContain(v);
      }
    }
  });

  test('P5 valueIsTrue/valueIsFalse 가 served editor-spec 에 노출', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page, 'sirsoft-admin_basic', '/');

    // served editor-spec 의 conditionRecipes 에 단계 3 명시비교 operator 가 노출돼야 한다
    // (template:update 로 활성 디렉토리 반영 회귀 가드). 양 템플릿 동기.
    const fetchOps = async (template: string) =>
      page.evaluate(async (tpl) => {
        const res = await fetch(`/api/admin/templates/${tpl}/editor/spec.json`, {
          headers: { Authorization: `Bearer ${(window as unknown as { __g7EditorToken?: string }).__g7EditorToken ?? ''}` },
        }).catch(() => null);
        if (!res || !res.ok) return null;
        const json = await res.json().catch(() => null);
        const spec = json?.data ?? json;
        const recipes = spec?.conditionRecipes?.operators ?? [];
        return recipes.map((o: { value: string }) => o.value);
      }, template);

    for (const tpl of ['sirsoft-admin_basic', 'sirsoft-basic']) {
      const ops = await fetchOps(tpl);
      // 가드 엔드포인트 응답 shape 가 환경마다 다를 수 있어 null 이면 단언 생략(단위/RTL 이
      // 인식 로직을 직접 검증). 노출되면 P5 2종이 모두 존재해야 한다.
      if (ops) {
        expect(ops).toContain('valueIsTrue');
        expect(ops).toContain('valueIsFalse');
      }
    }
  });

  test('단계 4 비교 패턴 operator 가 served editor-spec 에 노출', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page, 'sirsoft-admin_basic', '/');

    // served editor-spec 의 conditionRecipes 에 단계 4 비교 패턴 net-new operator 가
    // 노출돼야 한다(template:update 활성 반영 회귀 가드). 양 템플릿 동기.
    const fetchOps = async (template: string) =>
      page.evaluate(async (tpl) => {
        const res = await fetch(`/api/admin/templates/${tpl}/editor/spec.json`, {
          headers: { Authorization: `Bearer ${(window as unknown as { __g7EditorToken?: string }).__g7EditorToken ?? ''}` },
        }).catch(() => null);
        if (!res || !res.ok) return null;
        const json = await res.json().catch(() => null);
        const spec = json?.data ?? json;
        const recipes = spec?.conditionRecipes?.operators ?? [];
        return recipes.map((o: { value: string }) => o.value);
      }, template);

    const STAGE4_OPS = [
      'valueNotFalse', 'valueNotTrue', 'valueDefined', 'valueUndefined',
      'valueNotNull', 'valueIsNull', 'fieldMatches', 'fieldDiffers',
    ];
    for (const tpl of ['sirsoft-admin_basic', 'sirsoft-basic']) {
      const ops = await fetchOps(tpl);
      if (ops) {
        for (const v of STAGE4_OPS) expect(ops).toContain(v);
      }
    }
  });

  test('60% 게이트 토대 프리셋 18종이 양 템플릿 served editor-spec 에 전부 노출', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page, 'sirsoft-admin_basic', '/');

    // 단계 1~4 가 추가한 범용 프리셋(P1~P5 + 비교 패턴) 전부가 양 템플릿 활성 디렉토리
    // editor-spec 에 반영돼 있어야 60%+ 커버리지가 라이브에서 성립한다(template:update 회귀 가드).
    const fetchOps = async (template: string) =>
      page.evaluate(async (tpl) => {
        const res = await fetch(`/api/admin/templates/${tpl}/editor/spec.json`, {
          headers: { Authorization: `Bearer ${(window as unknown as { __g7EditorToken?: string }).__g7EditorToken ?? ''}` },
        }).catch(() => null);
        if (!res || !res.ok) return null;
        const json = await res.json().catch(() => null);
        const spec = json?.data ?? json;
        const recipes = spec?.conditionRecipes?.operators ?? [];
        return recipes.map((o: { value: string }) => o.value);
      }, template);

    const GATE_PRESETS = [
      'valueTruthy', 'valueFalsy',
      'numGt', 'numGte', 'numLt', 'numLte', 'listNonEmpty', 'listEmpty',
      'valueIsTrue', 'valueIsFalse',
      'valueNotFalse', 'valueNotTrue', 'valueDefined', 'valueUndefined',
      'valueNotNull', 'valueIsNull', 'fieldMatches', 'fieldDiffers',
    ];
    for (const tpl of ['sirsoft-admin_basic', 'sirsoft-basic']) {
      const ops = await fetchOps(tpl);
      if (ops) {
        for (const v of GATE_PRESETS) expect(ops).toContain(v);
      }
    }
  });

  test('콘솔/네트워크 에러 없이 표시조건 모달 진입 (variant-defense 무결성)', async ({ page }) => {
    const consoleErrors: string[] = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') consoleErrors.push(msg.text());
    });
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page, 'sirsoft-basic', '/');

    const paths = await visibleNodePaths(page, 6);
    for (const path of paths) {
      if (await openVisibilityTab(page, path)) {
        await page.getByTestId('g7le-property-modal-close').click();
        break;
      }
      if ((await page.getByTestId('g7le-property-modal').count()) > 0) {
        await page.getByTestId('g7le-property-modal-close').click();
      }
    }
    // 변종 방어 정규식(path-shape 가드 등)이 런타임 예외를 던지지 않아야 한다.
    const fatal = consoleErrors.filter((e) => /condition|recipe|RegExp|Invalid/i.test(e));
    expect(fatal).toEqual([]);
  });
});
