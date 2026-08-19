/**
 * Layout Editor — 동작 리스트 순서 변경 드래그.
 *
 * 페이지 설정 [화면 동작] 탭(InitActionsForm)의 동작 카드 순서 변경을 표현식 편집기와 동일하게
 * 통일한 회귀를 잠근다:
 *  - 순서 변경 ▲▼ 버튼 제거(드래그 전용)
 *  - ⠿ 핸들 HTML5 드래그로 임의 위치 재배치
 *  - 드래그 중 드롭 예정 지점에 삽입선(DropLine, data-active=true) 표시
 *  - 같은 공용 부품(useListDragReorder + DropLine)을 컴포넌트 동작(ActionRecipeEditor)·
 *    데이터/에러 처리 동작(ActionListBuilder)도 공유 — 그쪽 드래그/삽입선/▲▼부재는 RTL 단위
 *    (ActionRecipeEditor.test / ActionListBuilder.test / useListDragReorder.test)가 잠근다.
 *
 * 동작 카드 드래그는 HTML5 native drag(dnd-kit 아님)이라 onDragStart/onDragOver/onDrop 이 직접
 * 결선돼 Playwright dispatchEvent 로 검증 가능하다(캔버스 노드 재배치의 dnd-kit 비호환과 별개).
 *
 * @scenario page_settings_init_actions_reorder
 * 효과 요약(마커 아님 — 평문): reorder_buttons_removed, drag_handle_reorders, dropline_shows_target.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

type PwPage = import('@playwright/test').Page;

/** 페이지 설정 모달 → [화면 동작] 탭을 연다. */
async function openInitActionsTab(page: PwPage): Promise<void> {
  await page.goto('/admin/layout-editor/sirsoft-basic?route=%2Flogin');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
  await page.click('[data-testid="g7le-toolbar-page-settings"]');
  await page.click('[data-testid="g7le-page-settings-tab-init"]');
  await page.waitForSelector('[data-testid="g7le-init-actions-form"]', { timeout: 10_000 });
}

/**
 * 화면 동작 2개(setState, toast)를 추가하고 **추가 전 항목 수**를 돌려준다.
 *
 * 대상 레이아웃에는 이미 화면 동작이 있으므로 "항목은 2개" 로 고정하지 않는다 — 카드 인덱스는
 * 전체 목록 기준이라 기존 항목 뒤에 쌓인다.
 *
 * @param page Playwright page
 * @returns 추가 전 항목 수 (= 첫 번째로 추가된 항목의 인덱스)
 */
async function addTwoActions(page: PwPage): Promise<number> {
  const base = (await cardTitles(page)).length;

  await page.click('[data-testid="g7le-init-action-self-add-picker-toggle"]');
  await page.click('[data-testid="g7le-init-action-spec-setState"]');
  await page.click('[data-testid="g7le-init-action-self-add-picker-toggle"]');
  await page.click('[data-testid="g7le-init-action-spec-toast"]');
  await expect(page.locator(`[data-testid="g7le-init-action-self-item-${base + 1}"]`)).toBeAttached();

  return base;
}

/** 카드(fromIdx)를 카드(overIdx) 아래 절반으로 HTML5 드래그&드롭한다. */
async function dragActionCard(page: PwPage, fromIdx: number, overIdx: number): Promise<void> {
  await page.evaluate(
    async ({ from, over }) => {
      const handle = document.querySelector(`[data-testid="g7le-init-action-self-drag-${from}"]`)!;
      const card = document.querySelector(`[data-testid="g7le-init-action-self-item-${over}"]`)! as HTMLElement;
      const dt = new DataTransfer();
      const r = card.getBoundingClientRect();
      handle.dispatchEvent(new DragEvent('dragstart', { bubbles: true, cancelable: true, dataTransfer: dt }));
      // React 상태(dragIndex) 반영 대기 후 over → drop.
      await new Promise((res) => setTimeout(res, 60));
      card.dispatchEvent(
        new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt, clientY: r.top + r.height * 0.75 }),
      );
      await new Promise((res) => setTimeout(res, 60));
      card.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }));
    },
    { from: fromIdx, over: overIdx },
  );
}

/** 동작 카드 타이틀 순서를 읽는다. */
async function cardTitles(page: PwPage): Promise<string[]> {
  return page.evaluate(() =>
    Array.from(document.querySelectorAll('[data-testid^="g7le-init-action-self-item-"]')).map(
      (e) => (e.querySelector('[style*="font-weight"]')?.textContent ?? '').trim(),
    ),
  );
}

test.describe('@layout-editor 화면 동작 순서 변경 드래그 (S10-1 후속)', () => {
  /** @effects reorder_buttons_removed */
  test('순서 변경 ▲▼ 버튼 부재 + ⠿ 드래그 핸들 존재 + 삽입선 비활성(초기)', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openInitActionsTab(page);
    await addTwoActions(page);

    // ▲▼ 순서 변경 버튼은 제거됐다.
    await expect(page.locator('[data-testid="g7le-init-action-self-up-0"]')).toHaveCount(0);
    await expect(page.locator('[data-testid="g7le-init-action-self-down-0"]')).toHaveCount(0);
    // ⠿ 드래그 핸들은 draggable 로 존재한다.
    await expect(page.locator('[data-testid="g7le-init-action-self-drag-0"]')).toHaveAttribute('draggable', 'true');
    await expect(page.locator('[data-testid="g7le-init-action-self-drag-1"]')).toHaveAttribute('draggable', 'true');
    // 삽입선은 비드래그 시 비활성.
    await expect(page.locator('[data-testid="g7le-init-action-self-dropline-end"]')).toHaveAttribute('data-active', 'false');
  });

  /** @effects drag_handle_reorders, dropline_shows_target */
  test('⠿ 드래그로 순서 변경 + 드롭 예정 지점 삽입선 활성', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openInitActionsTab(page);
    const base = await addTwoActions(page);

    const before = await cardTitles(page);
    expect(before.length).toBe(base + 2);

    // 드래그 중 끝 삽입선이 활성화되는지 — dragstart → over 사이를 별도로 측정.
    const activeDuringOver = await page.evaluate(async (b) => {
      const handle = document.querySelector(`[data-testid="g7le-init-action-self-drag-${b}"]`)!;
      const card = document.querySelector(`[data-testid="g7le-init-action-self-item-${b + 1}"]`)! as HTMLElement;
      const dt = new DataTransfer();
      const r = card.getBoundingClientRect();
      handle.dispatchEvent(new DragEvent('dragstart', { bubbles: true, cancelable: true, dataTransfer: dt }));
      await new Promise((res) => setTimeout(res, 60));
      card.dispatchEvent(
        new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt, clientY: r.top + r.height * 0.75 }),
      );
      await new Promise((res) => setTimeout(res, 60));
      const active = Array.from(document.querySelectorAll('[data-testid^="g7le-init-action-self-dropline-"]'))
        .filter((d) => d.getAttribute('data-active') === 'true')
        .map((d) => d.getAttribute('data-testid'));
      card.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }));
      return active;
    }, base);
    // 내가 추가한 두 번째 카드 아래 절반 → 끝(end) 삽입 지점이 활성.
    expect(activeDuringOver).toContain('g7le-init-action-self-dropline-end');

    // 드롭 결과 — 마지막 두 카드가 서로 바뀌고 기존 항목은 그대로.
    const swapped = [...before.slice(0, base), before[base + 1], before[base]];
    await expect
      .poll(async () => (await cardTitles(page)).join('|'))
      .toBe(swapped.join('|'));

    // 드래그 종료 후 삽입선은 모두 비활성으로 복원.
    await expect(page.locator('[data-testid="g7le-init-action-self-dropline-end"]')).toHaveAttribute('data-active', 'false');
  });

  /** @effects drag_handle_reorders */
  test('연속 드래그 — 누적 순서 정합(다시 원래 순서로)', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openInitActionsTab(page);
    const base = await addTwoActions(page);

    const initial = await cardTitles(page);
    const swapped = [...initial.slice(0, base), initial[base + 1], initial[base]];
    // 1차: 추가한 첫 카드 → 끝(뒤집힘).
    await dragActionCard(page, base, base + 1);
    await expect.poll(async () => (await cardTitles(page)).join('|')).toBe(swapped.join('|'));
    // 2차: 같은 자리로 다시 → 원래 순서로 복원 — 누적 정합.
    await dragActionCard(page, base, base + 1);
    await expect.poll(async () => (await cardTitles(page)).join('|')).toBe(initial.join('|'));
  });
});
