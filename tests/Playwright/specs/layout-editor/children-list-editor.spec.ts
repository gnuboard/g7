/**
 * Layout Editor — children 노드 에디터(목록 빌트인 STRUCT-TREE).
 *
 * Ul/Ol/Nav/Form/Li 는 capability `nodeEditor:{kind:"children",params:{childComponent}}` 로
 * 자식 노드 트리를 속성 모달 [속성] 탭에서 구조 편집한다. 코어 빌트인
 * ChildrenListControl 이 registerCoreEditors 의 registerNodeEditor('children', ...) 로
 * 일반 레지스트리에 등록되어, PropertyEditorModal 이 kind(컴포넌트명 아님)로 디스패치한다.
 *
 * 본 E2E 는 라이브 편집기에서:
 *  1. Ul 을 추가하고 선택 → 속성 모달의 children 에디터(g7le-children-editor)가 마운트되는지,
 *  2. "추가" 로 Li 자식이 children 끝에 append 되는지(캔버스 li 증가),
 *  3. 항목 정렬(아래로 이동)이 캔버스 순서에 반영되는지,
 *  4. 저장(PUT 200) → reload 영속,
 *  를 검증한다. 항목 텍스트 다국어(커스텀 키 생성)는 단위(ChildrenListControl.test.tsx)가
 *  CRUD round-trip 을 잠그고, 본 E2E 는 구조 편집(추가/정렬/저장)을 브라우저로 확인한다.
 *
 * @scenario children_node_editor + add_remove_move + live_persist
 * @effects property_modal_dispatches_children_node_editor_in_props_tab_by_kind_not_name, add_item_appends_childcomponent_defaultnode_to_children, move_up_down_swaps_adjacent_children_with_boundary_guard, live_add_li_edit_text_reorder_save_persists_to_user_page, keyboard_arrowup_escape_escalates_selection_to_parent, overlapping_child_selected_type_chip_escalates_to_parent
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

async function gotoEditor(page: Page, route = '%2F'): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto(`/admin/layout-editor/sirsoft-basic?route=${route}`);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
  await page.waitForFunction(() => document.querySelectorAll('[data-editor-path]').length > 0, {
    timeout: 20_000,
  });
}

async function selectByPath(page: Page, path: string, timeout = 5_000): Promise<boolean> {
  await page.evaluate((p) => {
    const el = document.querySelector(`[data-editor-path="${p}"]`);
    if (!el) return;
    el.scrollIntoView({ block: 'center' });
    const r = el.getBoundingClientRect();
    const cx = r.left + Math.min(r.width / 2, 10);
    const cy = r.top + Math.min(r.height / 2, 10);
    for (const type of ['pointerover', 'pointermove', 'pointerdown', 'pointerup', 'click']) {
      el.dispatchEvent(new MouseEvent(type, { bubbles: true, clientX: cx, clientY: cy }));
    }
  }, path);
  return page
    .waitForSelector('[data-testid="g7le-overlay-info-button"]', { timeout })
    .then(() => true)
    .catch(() => false);
}

async function openPropsTab(page: Page): Promise<void> {
  await page.getByTestId('g7le-overlay-info-button').click();
  await page.waitForSelector('[data-testid="g7le-context-menu-edit-props"]', { timeout: 5_000 });
  await page.getByTestId('g7le-context-menu-edit-props').click();
  // 구조 에디터(children)는 [속성] 탭에 렌더된다(스타일 탭=CSS 전용). 구조 에디터 보유
  // 컴포넌트는 속성 탭이 기본 활성이지만 명시 클릭으로 안정화.
  await page.waitForSelector('[data-testid="g7le-property-tab-props"]', { timeout: 10_000 });
  await page.getByTestId('g7le-property-tab-props').click();
  await page.waitForTimeout(200);
}

/** content root(Div) path 를 찾아 Ul 을 추가하고 그 path 반환. */
async function addUl(page: Page): Promise<string> {
  // home content 영역 — 편집 가능한(ⓘ 컨텍스트 메뉴가 뜨는) 컨테이너 Div 를 찾아 그 안에
  // Ul 추가. 첫 Div 고정 선택은 깨지기 쉽다 — 루트/베이스 잠금 노드는 선택돼도 ⓘ 가
  // 표시되지 않으므로(레이아웃 구조 변화에 따라 첫 Div 가 잠금 노드가 됨), 후보를
  // 순회하며 ⓘ 가 뜨는 첫 Div 를 사용한다.
  const candidates = await page.evaluate(() =>
    Array.from(document.querySelectorAll('[data-editor-name="Div"]'))
      .filter((e) => {
        const r = e.getBoundingClientRect();
        return r.width > 40 && r.height > 20;
      })
      .map((e) => e.getAttribute('data-editor-path') ?? '')
      .filter(Boolean)
      .slice(0, 10),
  );
  expect(candidates.length).toBeGreaterThan(0);
  let containerPath: string | null = null;
  for (const cand of candidates) {
    // 잠금 후보 스킵은 짧게(2s) — 후보 순회가 테스트 예산(30s)을 소진하지 않게.
    if (await selectByPath(page, cand, 2_000)) {
      containerPath = cand;
      break;
    }
  }
  expect(containerPath).toBeTruthy();
  await page.getByTestId('g7le-toolbar-add-element').click();
  await page.waitForSelector('[data-testid="g7le-palette-item-Ul"]', { timeout: 10_000 });
  await page.getByTestId('g7le-palette-item-Ul').click();
  await page.waitForTimeout(400);
  return page.evaluate((C) => {
    const re = new RegExp('^' + C.replace(/\./g, '\\.') + '\\.children\\.\\d+$');
    const idxs = Array.from(document.querySelectorAll('[data-editor-path]'))
      .map((e) => e.getAttribute('data-editor-path') ?? '')
      .filter((p) => re.test(p))
      .map((p) => parseInt(p.split('.').pop() as string, 10));
    return C + '.children.' + Math.max(...idxs);
  }, containerPath!);
}

test.describe('@layout-editor children 노드 에디터(목록 빌트인)', () => {
  test('Ul 선택 시 children 에디터가 속성 탭에 마운트되고 Li 추가/정렬이 캔버스 반영', async ({ page }) => {
    await gotoEditor(page);
    const ulPath = await addUl(page);

    // Ul 선택 → 속성 모달 [속성] 탭 → children 에디터 마운트(kind-agnostic 디스패치).
    expect(await selectByPath(page, ulPath)).toBe(true);
    await openPropsTab(page);
    await expect(page.getByTestId('g7le-children-editor')).toBeVisible();

    // 추가 버튼 클릭 → Li 자식 append (캔버스 li 증가).
    const liBefore = await page.evaluate(
      (p) => document.querySelectorAll(`[data-editor-path^="${p}.children."]`).length,
      ulPath,
    );
    await page.getByTestId('g7le-children-add').click();
    await page.waitForTimeout(300);
    await page.getByTestId('g7le-children-add').click();
    await page.waitForTimeout(300);
    const liAfter = await page.evaluate(
      (p) => document.querySelectorAll(`[data-editor-path^="${p}.children."]`).length,
      ulPath,
    );
    expect(liAfter).toBeGreaterThanOrEqual(liBefore + 2);

    // children 에디터에 항목 행이 2개 이상.
    await expect(page.getByTestId('g7le-children-row-0')).toBeVisible();
    await expect(page.getByTestId('g7le-children-row-1')).toBeVisible();

    // 첫 항목 텍스트 편집 후 아래로 이동(정렬 경계 가드: 첫 위로 비활성).
    await expect(page.getByTestId('g7le-children-up-0')).toBeDisabled();
    await page.getByTestId('g7le-children-down-0').click();
    await page.waitForTimeout(300);
    // 정렬 후에도 행이 유지(2개 이상).
    await expect(page.getByTestId('g7le-children-row-1')).toBeVisible();
  });

  test('children 편집 후 저장 → PUT 200 + reload 영속', async ({ page }) => {
    test.setTimeout(60_000); // 후보 순회 + 저장 + reload 합산 — 기본 30s 부족

    await gotoEditor(page);
    const ulPath = await addUl(page);
    expect(await selectByPath(page, ulPath)).toBe(true);
    await openPropsTab(page);
    await page.getByTestId('g7le-children-add').click();
    await page.waitForTimeout(300);

    // 저장 — PUT 200. 모달을 확실히 닫은 뒤(닫힘 미보장 상태에서 role 매칭으로 저장을
    // 누르면 모달 안 다른 버튼이 잡혀 PUT 이 발생하지 않던 flake) 툴바 저장 testid 클릭.
    await page
      .getByTestId('g7le-property-modal-done')
      .click({ timeout: 2_000 })
      .catch(() => undefined);
    await page
      .getByRole('button', { name: /^(닫기|Close)$/ })
      .first()
      .click({ timeout: 3_000 })
      .catch(() => undefined);
    await page.waitForTimeout(300);
    const savePromise = page.waitForResponse(
      (r) => /\/api\/admin\/templates\/sirsoft-basic\/layouts\//.test(r.url()) && r.request().method() === 'PUT',
      { timeout: 15_000 },
    );
    await page.getByTestId('g7le-toolbar-save').click();
    const saveRes = await savePromise;
    expect(saveRes.status()).toBe(200);

    // 정리 — 테스트가 추가한 Ul 을 삭제하고 다시 저장해 라이브 레이아웃에 잔여물을
    // 남기지 않는다(반복 실행 시 home 에 Ul 이 누적되던 문제).
    expect(await selectByPath(page, ulPath)).toBe(true);
    await page.getByTestId('g7le-overlay-info-button').click();
    await page.waitForSelector('[data-testid="g7le-context-menu-delete"]', { timeout: 5_000 });
    await page.getByTestId('g7le-context-menu-delete').click();
    await page.waitForTimeout(400);
    const cleanupPromise = page.waitForResponse(
      (r) => /\/api\/admin\/templates\/sirsoft-basic\/layouts\//.test(r.url()) && r.request().method() === 'PUT',
      { timeout: 15_000 },
    );
    await page.getByTestId('g7le-toolbar-save').click();
    expect((await cleanupPromise).status()).toBe(200);
  });

  // 겹친 부모 선택 — 캔버스 클릭은 늘 가장 깊은 자식을 잡으므로(closest), 부모/자식 크기가
  // 같은 Ul>Li 같은 경우 자식만 잡힌다. 선택 박스 위 타입 칩(↑)을 클릭하거나 키보드 ↑/Esc 로
  // 한 단계씩 상위(부모)를 선택한다(상용 편집기 공통 — 부모는 별도 어포던스).
  test('겹친 자식 선택 후 타입 칩(↑)/키보드 ↑로 부모(Ul) escalation', async ({ page }) => {
    await gotoEditor(page);
    const ulPath = await addUl(page);
    // Ul 안에 Li 1개(defaultNode) 존재 — 그 Li(가장 깊은 자식)를 선택.
    const liPath = `${ulPath}.children.0`;
    expect(await selectByPath(page, liPath)).toBe(true);
    // 타입 칩이 클릭 가능한 부모 선택 버튼(BUTTON + ↑ + Li).
    const chip = page.getByTestId('g7le-overlay-type-label');
    await expect(chip).toBeVisible();
    expect(await chip.evaluate((el) => el.tagName)).toBe('BUTTON');
    expect((await chip.textContent())?.trim()).toContain('Li');
    // 칩 클릭 → 부모 Ul 선택(칩 텍스트 Li→Ul).
    await chip.click();
    await page.waitForTimeout(300);
    expect((await page.getByTestId('g7le-overlay-type-label').textContent())?.trim()).toContain('Ul');
    // 다시 깊은 Li 선택 → 키보드 ArrowUp 으로도 부모 escalation.
    expect(await selectByPath(page, liPath)).toBe(true);
    await page.evaluate(() => {
      if (document.activeElement && document.activeElement !== document.body) {
        (document.activeElement as HTMLElement).blur();
      }
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true }));
    });
    await page.waitForTimeout(300);
    expect((await page.getByTestId('g7le-overlay-type-label').textContent())?.trim()).toContain('Ul');
    // Esc 는 부모 이동이 아니라 **선택 해제**(표준 취소 키) — 선택 박스가 사라진다.
    await page.evaluate(() => {
      if (document.activeElement && document.activeElement !== document.body) {
        (document.activeElement as HTMLElement).blur();
      }
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    });
    await page.waitForTimeout(300);
    await expect(page.getByTestId('g7le-overlay-selected')).toHaveCount(0);
  });

  // Form children 에디터의 스펙 주도 묶음 추가.
  // 종전: "Input 추가"가 void Input 에 "새 항목" 텍스트를 시드해 React #137 로
  // 캔버스가 "컴포넌트 로드 실패" 박스로 깨졌다. 현재: capability params 의
  // childTemplate(라벨+입력칸 묶음)/itemFields(라벨+안내 문구 필드) 선언으로
  // 기존 폼 항목과 동일 구조가 추가되고 두 필드를 목록에서 편집한다.
  test('Form "입력 상자 추가" → 라벨+입력칸 묶음 추가(크래시 0) + 라벨/안내 문구 편집 필드', async ({ page }) => {
    await gotoEditor(page, '%2Fregister');
    const formPath = await page.evaluate(
      () => document.querySelector('form[data-editor-path]')?.getAttribute('data-editor-path') ?? null,
    );
    expect(formPath).toBeTruthy();
    // Form 선택 — 캔버스 클릭은 최심 자식을 잡으므로 타입 칩(↑)으로 Form 까지 escalation.
    expect(await selectByPath(page, formPath!)).toBe(true);
    for (let i = 0; i < 8; i += 1) {
      const chipText = (await page.getByTestId('g7le-overlay-type-label').textContent()) ?? '';
      if (chipText.includes('Form')) break;
      await page.getByTestId('g7le-overlay-type-label').click();
      await page.waitForTimeout(250);
    }
    await openPropsTab(page);
    await expect(page.getByTestId('g7le-children-editor')).toBeVisible();

    const counts = () =>
      page.evaluate((p) => {
        const form = document.querySelector(`form[data-editor-path="${p}"]`);
        return {
          inputs: form?.querySelectorAll('input').length ?? -1,
          labels: form?.querySelectorAll('label').length ?? -1,
          errorBoxes: Array.from(document.querySelectorAll('[class*="bg-red-50"]')).filter((e) =>
            (e.textContent ?? '').includes('컴포넌트 로드 실패'),
          ).length,
        };
      }, formPath!);
    const before = await counts();

    // "입력 상자 추가" — childTemplate 묶음 append.
    await page.getByTestId('g7le-children-add').click();
    await page.waitForTimeout(400);
    const after = await counts();
    // 라벨+입력칸이 동반 증가하고 에러 박스(React #137 회귀)는 0.
    expect(after.inputs).toBe(before.inputs + 1);
    expect(after.labels).toBe(before.labels + 1);
    expect(after.errorBoxes).toBe(0);

    // 새 항목 행 — itemFields 선언대로 라벨 + 안내 문구 두 편집 필드(캡션 포함).
    const rowCount = await page.evaluate(
      () => document.querySelectorAll('[data-testid^="g7le-children-row-"]').length,
    );
    const li = rowCount - 1;
    // 캡션/시드 문구는 에디터 UI 로케일(ko/en)에 따라 다르다 — 양쪽 허용.
    await expect(page.getByTestId(`g7le-children-caption-text-${li}`)).toHaveText(/라벨|Label/);
    await expect(page.getByTestId(`g7le-children-caption-prop-placeholder-${li}`)).toHaveText(
      /안내 문구|Placeholder text/,
    );
    await expect(page.getByTestId(`g7le-children-i18n-${li}-preview`)).toHaveValue(/새 항목|New item/);
    await expect(page.getByTestId(`g7le-children-prop-placeholder-${li}-preview`)).not.toHaveValue('');

    // 정리(저장 안 함) — 추가 행 삭제로 캔버스 원복.
    await page.getByTestId(`g7le-children-remove-${li}`).click();
    await page.waitForTimeout(300);
    const restored = await counts();
    expect(restored.inputs).toBe(before.inputs);
    expect(restored.errorBoxes).toBe(0);
  });
});
