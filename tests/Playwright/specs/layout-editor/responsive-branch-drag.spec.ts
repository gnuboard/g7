/**
 * Layout Editor — 디바이스 분기(responsive 자식 교체) 노드의 드래그/삽입 정합.
 *
 * **결함 배경 (선결 결함①②)**:
 *  - 결함① 드롭 후보 DFS(`useCanvasDnd.collectContainers`)가 base children 만 순회하고
 *    `node.responsive[key].children`(분기) 는 순회하지 않아, 모바일 보기에서 분기 안에
 *    **드롭 슬롯이 0개** → 같은 분기 내 이동조차 거부됐다.
 *  - 결함② +요소 추가 plumbing 타입(`number[]`)이 분기 세그먼트를 못 받아 삽입이 base
 *    children 으로 가고(모바일 보기엔 안 보임) dirty/undo 가 미발화했다.
 *
 * **검증 계층 분리 (dnd-kit 헤드리스 한계 — drag-drop-reorder.spec.ts 와 동일)**:
 *  - dnd-kit PointerSensor 는 trusted mouse 로 **활성화**되어 드롭 슬롯 렌더까지는
 *    헤드리스에서 안정 검증 가능. 따라서 본 spec 은 **분기 안에 드롭 슬롯이 생성되는지**
 *    (결함① 의 직접 회귀 가드)를 렌더 계층에서 검증한다.
 *  - 드롭 commit(moveNode) + undo 는 헤드리스 합성 입력에서 collision 미해소로 no-op —
 *  단위(`responsiveBranchPath.test.ts` moveNode 분기 정렬)와 실브라우저(Chrome MCP PO
 *    검수)로 검증한다(feedback_chrome_mcp_dnd_kit_incompatible).
 *
 * @scenario responsive_branch_edit drag_drop
 * @effects branch_drop_slots_render + branch_node_has_drag_handle
 */
import type { Page } from '@playwright/test';
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

/** 편집기 admin_user_list 진입 + 모바일 보기 전환 + 분기 핸들 렌더까지 대기. */
async function openAdminUserListMobile(page: Page): Promise<void> {
  await page.goto('/admin/layout-editor/sirsoft-admin_basic');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  // 라우트 트리에서 admin_user_list 선택 — 직접 URL nav 는 route 미선택(트리 클릭 필요).
  // 라우트 항목은 `<div role="button">` 안에 `.g7le-route-tree__layout-path` 스팬으로
  // `layouts/admin_user_list.json` 을 표시한다. 모달도 같은 json 을 참조하므로 첫 라우트
  // 항목(트리상 라우트 본체가 모달보다 앞)을 클릭한다.
  await page.waitForFunction(
    () =>
      Array.from(document.querySelectorAll('.g7le-route-tree__layout-path')).some((s) =>
        /admin_user_list\.json$/.test((s.textContent || '').trim()),
      ),
    undefined,
    { timeout: 30_000 },
  );
  await page.evaluate(() => {
    const span = Array.from(document.querySelectorAll('.g7le-route-tree__layout-path')).find((s) =>
      /admin_user_list\.json$/.test((s.textContent || '').trim()),
    );
    let el: Element | null = span ?? null;
    while (el && el.getAttribute('role') !== 'button' && el.tagName !== 'BUTTON') {
      el = el.parentElement;
    }
    el?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
  });
  // 라우트 캔버스 준비 신호 = 드래그 핸들 렌더.
  await page.waitForSelector('[data-dnd-handle-path]', { timeout: 30_000 });
  // 모바일 보기 전환 — 분기(responsive.portable) children 렌더 활성.
  // 디바이스 토글 버튼은 로케일에 따라 "📲 모바일" / "📱 Mobile" 등. 데스크톱/태블릿/
  // 모바일/사용자지정 4버튼 중 모바일을 매칭한다(로케일 무관 — 이모지 + 라벨 둘 다 허용).
  await page.evaluate(() => {
    const btn = Array.from(document.querySelectorAll('button')).find((b) => {
      const t = b.textContent || '';
      return /모바일|Mobile|📱|📲/.test(t) && t.length < 16;
    });
    btn?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
  });
  // 분기 자식 핸들이 나타날 때까지 대기(분기 렌더 신호).
  await page.waitForFunction(
    () =>
      Array.from(document.querySelectorAll('[data-dnd-handle-path]')).some((h) =>
        (h.getAttribute('data-dnd-handle-path') || '').includes('.responsive.'),
      ),
    undefined,
    { timeout: 15_000 },
  );
}

/** trusted mouse 드래그 활성화 — down → 8px 임계 초과 이동. pointerup 은 호출자가 수행. */
async function startDrag(page: Page, from: { x: number; y: number }): Promise<void> {
  await page.mouse.move(from.x, from.y);
  await page.mouse.down();
  await page.mouse.move(from.x + 12, from.y + 12);
  await page.mouse.move(from.x + 20, from.y + 20);
  await page.waitForTimeout(60);
}

async function selectHandle(page: Page, handlePath: string): Promise<void> {
  await page.evaluate((p) => {
    document
      .querySelector(`[data-dnd-handle-path="${p}"]`)
      ?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
  }, handlePath);
  await page.waitForTimeout(60);
}

async function endDrag(page: Page): Promise<void> {
  await page.evaluate(() => {
    window.dispatchEvent(
      new PointerEvent('pointerup', {
        bubbles: true,
        pointerId: 1,
        pointerType: 'mouse',
        button: 0,
        buttons: 0,
      }),
    );
  });
  await page.waitForTimeout(150);
}

test.describe('@layout-editor responsive 분기 드래그/슬롯', () => {
  test.afterEach(async ({ page }) => {
    await page
      .evaluate(() => {
        window.dispatchEvent(
          new PointerEvent('pointerup', {
            bubbles: true,
            pointerId: 1,
            pointerType: 'mouse',
            button: 0,
            buttons: 0,
          }),
        );
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      })
      .catch(() => {
        /* 페이지 닫힘 등 무시 */
      });
  });

  test('모바일 보기에서 responsive 분기 자식이 드래그 핸들을 가진다', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openAdminUserListMobile(page);

    const branchHandleCount = await page.evaluate(
      () =>
        Array.from(document.querySelectorAll('[data-dnd-handle-path]')).filter((h) =>
          (h.getAttribute('data-dnd-handle-path') || '').includes('.responsive.'),
        ).length,
    );
    expect(
      branchHandleCount,
      '모바일 분기 자식에 드래그 핸들이 렌더되어야 함(분기 노드 선택/이동 가능)',
    ).toBeGreaterThan(0);
  });

  test('분기 안 노드를 드래그하면 같은 분기 안에 드롭 슬롯이 생성된다 (결함① 회귀 가드)', async ({
    page,
  }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openAdminUserListMobile(page);

    // 형제 ≥ 2 인 분기 컨테이너에서 (분기 안 자식, 그 분기 컨테이너 path) 수집.
    const probe = await page.evaluate(() => {
      const handles = Array.from(document.querySelectorAll('[data-dnd-handle-path]')).map(
        (h) => h.getAttribute('data-dnd-handle-path') || '',
      );
      // 분기 직속 자식 = `…responsive.{key}.children.N` (그 뒤 .children 없음).
      const branchChildren = handles.filter((p) => /\.responsive\.[^.]+\.children\.\d+$/.test(p));
      const containerOf = (p: string) => p.replace(/\.children\.\d+$/, '');
      const groups: Record<string, string[]> = {};
      for (const p of branchChildren) (groups[containerOf(p)] ||= []).push(p);
      for (const [container, kids] of Object.entries(groups)) {
        if (kids.length >= 2) return { dragPath: kids[0], branchContainer: container };
      }
      // 형제 2개인 분기 컨테이너가 없으면 첫 분기 자식이라도 반환(슬롯 ≥ 1 검증용).
      return branchChildren.length > 0
        ? { dragPath: branchChildren[0], branchContainer: containerOf(branchChildren[0]) }
        : null;
    });
    expect(probe, '분기 안 자식 핸들을 찾아야 함').not.toBeNull();

    await selectHandle(page, probe!.dragPath);
    const center = await page.evaluate((p) => {
      const el = document.querySelector(`[data-dnd-handle-path="${p}"]`) as HTMLElement;
      const r = el.getBoundingClientRect();
      return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
    }, probe!.dragPath);
    await startDrag(page, center);
    await page.waitForSelector('[data-dnd-slot-id]', { timeout: 5_000 });

    // 결함① 핵심: 같은 분기 컨테이너(`…responsive.{key}`) 안에 드롭 슬롯이 생성돼야 한다.
    const hasBranchSlot = await page.evaluate((branchContainer) => {
      const slotContainers = Array.from(document.querySelectorAll('[data-dnd-slot-id]')).map(
        (el) => {
          const id = el.getAttribute('data-dnd-slot-id') || '';
          const rest = id.slice(5); // 'slot:' 제거
          return rest.slice(0, rest.lastIndexOf(':'));
        },
      );
      // 분기 컨테이너 자신 또는 그 자손 컨테이너에 슬롯이 있으면 통과.
      return [...new Set(slotContainers)].some(
        (c) => c === branchContainer || c.startsWith(`${branchContainer}.`),
      );
    }, probe!.branchContainer);

    await endDrag(page);

    expect(
      hasBranchSlot,
      '분기 안 노드 드래그 시 같은 분기 안에 드롭 슬롯이 생성되어야 함(결함①: collectContainers 분기 children 순회)',
    ).toBe(true);
  });
});
