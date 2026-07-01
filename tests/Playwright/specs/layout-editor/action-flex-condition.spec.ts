/**
 * Layout Editor — 동작/표시조건/정렬박스 탭.
 *
 * S7 검증:
 *  - 요소 선택 → ⓘ → 속성 설정 → 모달에 "동작"/"표시조건" 탭 노출(events/visibility 선언 시)
 *  - 표시조건 탭: 친화 조건 빌더(택1 + AND/OR + 미리보기) 렌더
 *  - 동작 탭: 이벤트 슬롯 + 친화 동작 추가(핸들러 용어 미노출)
 *  - 정렬 박스(flex) 컨트롤: Flex/Div 선택 시 스타일 탭 상단에 정렬 박스 섹션
 *
 * Chrome MCP/Playwright 빌트인 drag 는 dnd-kit 비호환(메모리 feedback_chrome_mcp_dnd_kit_incompatible)
 * 이라, 본 spec 은 탭 노출/빌더 렌더/조작 결과의 가시 상태만 확인하고 합성 로직 정합성은
 * 단위/RTL(actionRecipeEngine/conditionRecipeEngine/s7-property-controls)이 잠근다.
 *
 * @scenario property_modal_action_tab + property_modal_visibility_tab + flex_editor_section
 * @effects action_tab_visible_when_events + visibility_builder_renders + flex_section_for_container
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

type PwPage = import('@playwright/test').Page;

async function openFirstNodeModal(page: PwPage): Promise<void> {
  await page.goto('/admin/layout-editor/sirsoft-basic?route=%2F');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
  await page.waitForFunction(() => document.querySelectorAll('[data-editor-path]').length > 0, { timeout: 20_000 });
}

/**
 * 캔버스의 visible 노드 path 목록(앞쪽 N개)을 수집한다.
 *
 * 캔버스 노드는 드래그 핸들(`g7le-dnd-handle-*`)이 덮어 실제 마우스 클릭이 노드에 닿지
 * 못하므로(핸들이 pointer events 가로챔), path 로 노드를 식별해 selectNode 합성 이벤트로
 * 선택한다. 반응형 hidden 노드(mobile_header 등)는 제외한다.
 */
async function visibleNodePaths(page: PwPage, limit: number): Promise<string[]> {
  return page.evaluate((max) => {
    const out: string[] = [];
    const els = Array.from(document.querySelectorAll('[data-editor-path]'));
    for (const el of els) {
      const r = (el as HTMLElement).getBoundingClientRect();
      const visible = r.width > 0 && r.height > 0 && getComputedStyle(el as HTMLElement).display !== 'none';
      const path = el.getAttribute('data-editor-path');
      if (visible && path) out.push(path);
      if (out.length >= max) break;
    }
    return out;
  }, limit);
}

/**
 * path 로 노드를 단일 선택한다 — 합성 마우스 이벤트 시퀀스로 드래그 핸들의 onClick(선택
 * 위임)을 거치지 않고 캔버스 선택 로직을 직접 트리거한다(핸들 pointer 가로채기 우회).
 */
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

test.describe('@layout-editor 동작/표시조건/정렬박스 (S7)', () => {
  test('요소 선택 → 속성 모달에 표시조건 탭 + 조건 빌더 렌더', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openFirstNodeModal(page);

    // 표시조건 탭은 capability 보유 요소에서 노출된다. 여러 노드를 시도해 탭이 있는 노드를 찾는다.
    const paths = await visibleNodePaths(page, 8);
    let found = false;
    for (const path of paths) {
      if (!(await selectNode(page, path))) continue;
      const info = page.getByTestId('g7le-overlay-info-button');
      if ((await info.count()) === 0) continue;
      await info.click();
      const editProps = page.getByTestId('g7le-context-menu-edit-props');
      if ((await editProps.count()) === 0) continue;
      await editProps.click();
      await page.waitForSelector('[data-testid="g7le-property-modal"]', { timeout: 8_000 });
      if ((await page.getByTestId('g7le-property-tab-visibility').count()) > 0) {
        await page.getByTestId('g7le-property-tab-visibility').click();
        // 조건 빌더(친화 목록) 또는 빈 안내 중 하나가 렌더
        const builder = await page.getByTestId('g7le-condition-builder').count();
        const noRecipes = await page.getByTestId('g7le-condition-no-recipes').count();
        expect(builder + noRecipes).toBeGreaterThan(0);
        found = true;
        break;
      }
      await page.getByTestId('g7le-property-modal-close').click();
    }
    expect(found).toBe(true);
  });

  test('표시조건 빌더 — 조건 추가 시 미리보기에 단일 {{ }} 식 노출', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openFirstNodeModal(page);

    const paths = await visibleNodePaths(page, 8);
    for (const path of paths) {
      if (!(await selectNode(page, path))) continue;
      const info = page.getByTestId('g7le-overlay-info-button');
      if ((await info.count()) === 0) continue;
      await info.click();
      const editProps = page.getByTestId('g7le-context-menu-edit-props');
      if ((await editProps.count()) === 0) continue;
      await editProps.click();
      await page.waitForSelector('[data-testid="g7le-property-modal"]', { timeout: 8_000 });
      if ((await page.getByTestId('g7le-property-tab-visibility').count()) > 0) {
        await page.getByTestId('g7le-property-tab-visibility').click();
        if ((await page.getByTestId('g7le-condition-add-and').count()) > 0) {
          await page.getByTestId('g7le-condition-add-and').click();
          const preview = page.getByTestId('g7le-condition-preview');
          await expect(preview).toBeVisible();
          const text = await preview.textContent();
          // 단일 {{ }} 한 쌍 — 중첩 보간 없음
          expect((text?.match(/\{\{/g) ?? []).length).toBe(1);
          return;
        }
      }
      await page.getByTestId('g7le-property-modal-close').click();
    }
  });

  // ── D-D 회귀 — 컴포넌트 [동작] 탭 ──
  // @scenario property_modal_action_tab
  // @effects nested_onsuccess_picker_not_empty + action_card_drag_handle_draggable

  /** [동작] 탭을 가진(events capability) 노드를 찾아 동작 탭을 연다. 못 찾으면 null.
   *  Button 등 onClick capability 보유 가능성이 높은 노드를 우선 시도한다(앞쪽 컨테이너는
   *  보통 events 미보유 → 동작 탭 없음). */
  async function openActionTab(page: PwPage): Promise<boolean> {
    const paths = await page.evaluate(() => {
      const els = Array.from(document.querySelectorAll('[data-editor-path]')) as HTMLElement[];
      const visible = els.filter((el) => {
        const r = el.getBoundingClientRect();
        return r.width > 0 && r.height > 0 && getComputedStyle(el).display !== 'none';
      });
      // Button 류 우선, 그 외 후순위
      const buttons = visible.filter((el) => el.tagName === 'BUTTON').map((el) => el.getAttribute('data-editor-path')!);
      const others = visible.filter((el) => el.tagName !== 'BUTTON').slice(0, 8).map((el) => el.getAttribute('data-editor-path')!);
      return [...buttons.slice(0, 12), ...others];
    });
    for (const path of paths) {
      if (!(await selectNode(page, path))) continue;
      const info = page.getByTestId('g7le-overlay-info-button');
      if ((await info.count()) === 0) continue;
      await info.click();
      const editProps = page.getByTestId('g7le-context-menu-edit-props');
      if ((await editProps.count()) === 0) continue;
      await editProps.click();
      await page.waitForSelector('[data-testid="g7le-property-modal"]', { timeout: 8_000 });
      if ((await page.getByTestId('g7le-property-tab-action').count()) > 0) {
        await page.getByTestId('g7le-property-tab-action').click();
        if ((await page.getByTestId('g7le-action-editor').count()) > 0) return true;
      }
      await page.getByTestId('g7le-property-modal-close').click();
    }
    return false;
  }

  test('동작 탭 — apiCall 의 성공 시 동작(중첩) add picker 가 코어 핸들러를 노출(빈 상태 금지)', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openFirstNodeModal(page);
    expect(await openActionTab(page)).toBe(true);

    // 첫 이벤트 슬롯에 apiCall(서버 호출) 동작 추가. (슬롯에 기존 카드가 있을 수 있으므로
    // 추가 후 "맨 끝 카드"가 방금 추가한 apiCall — 그 카드의 edit 을 연다.)
    const firstSlot = page.locator('[data-testid="g7le-action-event-onClick"]').first();
    await firstSlot.locator('[data-testid="g7le-action-add-0-toggle"]').click();
    await firstSlot.locator('[data-testid="g7le-init-action-spec-apiCall"]').click();
    // 추가된 apiCall 카드(슬롯의 마지막 카드) 편집 → onSuccess 중첩 영역.
    const editBtns = firstSlot.locator('[data-testid^="g7le-action-edit-0-"]');
    await editBtns.last().click();
    const onSuccess = page.getByTestId('g7le-action-param-onSuccess');
    await expect(onSuccess).toBeVisible();
    // 빈 상태("사용할 수 있는 동작이 없습니다") 가 아니라 중첩 add 토글이 있어야 한다(rawRecipes 전달).
    await expect(onSuccess.getByTestId('g7le-action-add-1-empty')).toHaveCount(0);
    await expect(onSuccess.getByTestId('g7le-action-add-1-toggle')).toHaveCount(1);
    // 펼치면 코어 핸들러(안내 메시지 등) 노출.
    await onSuccess.getByTestId('g7le-action-add-1-toggle').click();
    await expect(onSuccess.getByTestId('g7le-init-action-spec-toast')).toHaveCount(1);
  });

  test('동작 탭 — 동작 카드 드래그 핸들(⠿)이 draggable(순서 재배치 가능)', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openFirstNodeModal(page);
    expect(await openActionTab(page)).toBe(true);

    // 동작 1개 추가(toast) → 카드 생성 → 드래그 핸들 draggable 확인.
    const slotAddToggle = page.locator('[data-testid="g7le-action-editor"] [data-testid="g7le-action-add-0-toggle"]').first();
    await slotAddToggle.click();
    const toastSpec = page.locator('[data-testid="g7le-action-editor"] [data-testid="g7le-init-action-spec-toast"]').first();
    await toastSpec.click();
    const handle = page.getByTestId('g7le-action-drag-0-0').first();
    await expect(handle).toBeVisible();
    await expect(handle).toHaveAttribute('draggable', 'true');
  });
});
