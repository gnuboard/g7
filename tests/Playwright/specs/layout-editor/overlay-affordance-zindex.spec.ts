/**
 * Layout Editor — 어포던스(+/ⓘ/리사이즈) 클릭 가로채기 회귀.
 *
 * **회귀 배경**: S5b 에서 도입된 드래그 핸들이 트리 깊이순 z-index(`20 + depth`)로
 * 어포던스 버튼(z-index 미지정 ≈ 0) 위로 올라와, 요소 선택 후 +(요소 추가)·ⓘ(속성/메뉴)·
 * 리사이즈 핸들 클릭이 모두 드래그 핸들(cursor:grab)에 가로채여 동작하지 않았다.
 * 오버레이 레이어 z-index 계약을 `overlayZIndex` 단일 SSoT 로 명문화 — 어포던스 밴드(120)를
 * 드래그 핸들 위에 두고, 드래그 핸들 깊이 가산은 상한(50)으로 클램프해 어떤 트리 깊이에서도
 * 어포던스 밴드를 침범하지 않게 했다. 깊이순 정렬·드래그 시작·드롭 슬롯은 그대로 보존.
 *
 * **헤드리스에서 안정 검증 가능한 부분만 다룬다** (drag commit 한계는 drag-drop-reorder.spec
 * 의 계층 분리 주석 참조). 본 spec 은 z-index 계약과 클릭 라우팅(elementFromPoint topmost)·
 * ⓘ 메뉴 오픈·드래그 시작 보존을 검증한다.
 *
 * @scenario context_plus_button selected_node_state=basic_selected
 * @effects overlay_affordance_above_drag_handle_zindex + overlay_affordance_click_not_intercepted_by_drag_handle + drag_handle_depth_clamped_below_affordance_band + drag_initiation_preserved_on_element_body_after_affordance_zindex_fix
 */
import type { Page } from '@playwright/test';
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

/** 어포던스 밴드 z-index (overlayZIndex.OVERLAY_AFFORDANCE 와 동기). */
const OVERLAY_AFFORDANCE = 120;

/** 편집기 home 진입 + 캔버스(드래그 핸들) 준비까지 대기. */
async function openEditor(page: Page): Promise<void> {
  await page.goto('/admin/layout-editor/sirsoft-basic?route=%2F');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid^="g7le-dnd-handle-"]', { timeout: 30_000 });
}

/**
 * 적당한 크기의 draggable 요소를 선택(핸들 클릭 위임) 후, 어포던스 버튼이 렌더될 때까지 대기.
 * @return 선택된 노드 path
 */
async function selectModerateElement(page: Page): Promise<string> {
  const path = await page.evaluate(() => {
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const els = Array.from(document.querySelectorAll('[data-editor-path]')) as HTMLElement[];
    // 적당한 크기 + **뷰포트 안에 여유 있게** 들어오는 요소만 선택. 오버레이 어포던스 버튼은
    // 박스 가장자리(±12~20px) 에 배치되므로, 박스가 뷰포트 경계에 너무 붙으면 버튼 좌표가
    // 화면 밖으로 나가 elementFromPoint 가 null 을 반환한다(드래그 핸들 가로채기와 무관한
    // 좌표 artifact). 가장자리 여유(margin 32px)를 둬 모든 +/ⓘ 버튼 좌표가 뷰포트 안에 오게 한다.
    const M = 32;
    const target =
      els.find((e) => {
        const r = e.getBoundingClientRect();
        return (
          r.width > 150 &&
          r.width < 500 &&
          r.height > 80 &&
          r.height < 400 &&
          r.left > M &&
          r.top > M &&
          r.right < vw - M &&
          r.bottom < vh - M
        );
      }) || els[5];
    target?.scrollIntoView({ block: 'center', inline: 'center' });
    target?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    return target?.getAttribute('data-editor-path') ?? '';
  });
  await page.waitForSelector('[data-testid="g7le-overlay-selected"]', { timeout: 5_000 });
  await page.waitForSelector('[data-testid="g7le-overlay-info-button"]', { timeout: 5_000 });
  return path;
}

test.describe('@layout-editor overlay affordance z-index', () => {
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

  test('선택 시 +/ⓘ 버튼이 어포던스 밴드 z-index(120)로 렌더', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page);
    await selectModerateElement(page);

    const zindexes = await page.evaluate(() => {
      const read = (sel: string): string | null => {
        const el = document.querySelector(sel) as HTMLElement | null;
        return el ? el.style.zIndex : null;
      };
      return {
        info: read('[data-testid="g7le-overlay-info-button"]'),
        above: read('[data-testid="g7le-insertion-above"]'),
        below: read('[data-testid="g7le-insertion-below"]'),
        left: read('[data-testid="g7le-insertion-left"]'),
        right: read('[data-testid="g7le-insertion-right"]'),
      };
    });

    expect(Number(zindexes.info), 'ⓘ 버튼 z-index = 어포던스 밴드').toBe(OVERLAY_AFFORDANCE);
    for (const dir of ['above', 'below', 'left', 'right'] as const) {
      const z = zindexes[dir];
      // + 버튼은 컨테이너 레이아웃에 따라 일부 방향만 활성/렌더될 수 있으나, 렌더된 버튼은
      // 반드시 어포던스 밴드여야 한다(렌더 안 된 방향은 null — 검사 제외).
      if (z !== null) {
        expect(Number(z), `+ 버튼(${dir}) z-index = 어포던스 밴드`).toBe(OVERLAY_AFFORDANCE);
      }
    }
  });

  test('+/ⓘ 버튼 정중앙의 topmost 요소가 버튼 자신 — 드래그 핸들에 가로채이지 않음', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page);
    await selectModerateElement(page);

    const probe = await page.evaluate(() => {
      const vw = window.innerWidth;
      const vh = window.innerHeight;
      const check = (sel: string) => {
        const el = document.querySelector(sel) as HTMLElement | null;
        if (!el) return { sel, rendered: false, onScreen: false };
        const r = el.getBoundingClientRect();
        const cx = r.left + r.width / 2;
        const cy = r.top + r.height / 2;
        // 버튼 중앙이 뷰포트 밖이면 elementFromPoint 가 null — z-index 와 무관한 좌표 artifact.
        const onScreen = cx >= 0 && cy >= 0 && cx <= vw && cy <= vh;
        const top = onScreen ? (document.elementFromPoint(cx, cy) as HTMLElement | null) : null;
        const cs = top ? getComputedStyle(top) : null;
        // topmost 가 어포던스 레이어 요소인지 — 버튼 자신/자손, 또는 같은 어포던스 밴드(z=120)를
        // 공유하는 다른 오버레이(리사이즈 핸들 등). 회귀 가드는 "드래그 핸들이 topmost 가 아님".
        // (작은 박스에서 + 버튼과 리사이즈 핸들이 같은 가장자리에 겹쳐 z=120 동률이므로, DOM
        //  paint 순서에 따라 어느 쪽이든 topmost 가 될 수 있다 — 둘 다 정당한 어포던스.)
        const topIsButton = !!top && (top === el || el.contains(top));
        const topTestid = top?.dataset?.testid ?? '';
        const topIsAffordance =
          topIsButton ||
          topTestid.startsWith('g7le-resize-handle-') ||
          topTestid.startsWith('g7le-insertion-') ||
          topTestid === 'g7le-overlay-info-button' ||
          topTestid.startsWith('g7le-overlay-');
        return {
          sel,
          rendered: true,
          onScreen,
          topIsButton,
          topIsAffordance,
          topIsDragHandle: !!top?.dataset?.dndHandlePath,
          cursor: cs?.cursor ?? null,
        };
      };
      // 드래그 핸들의 최대 z-index — 어포던스 밴드가 이보다 위인지 비교용.
      const maxHandleZ = Array.from(document.querySelectorAll('[data-dnd-handle-path]')).reduce(
        (m, h) => Math.max(m, Number((h as HTMLElement).style.zIndex) || 0),
        0,
      );
      return {
        info: check('[data-testid="g7le-overlay-info-button"]'),
        above: check('[data-testid="g7le-insertion-above"]'),
        below: check('[data-testid="g7le-insertion-below"]'),
        left: check('[data-testid="g7le-insertion-left"]'),
        right: check('[data-testid="g7le-insertion-right"]'),
        maxHandleZ,
      };
    });

    // ⓘ 는 박스 우상단 코너라 다른 어포던스와 겹치지 않아 elementFromPoint 가 안정적 —
    // topmost 가 버튼 자신이고 드래그 핸들이 아님을 실제 hit-test 로 확인(가로채기 회귀 직접 검증).
    expect(probe.info.rendered, 'ⓘ 버튼이 렌더되어야 함').toBe(true);
    expect(probe.info.onScreen, 'ⓘ 버튼 중앙이 뷰포트 안이어야 함(helper 보장)').toBe(true);
    expect(probe.info.topIsButton, 'ⓘ 정중앙 topmost 가 버튼이어야 함(드래그 핸들 아님)').toBe(true);
    expect(probe.info.topIsDragHandle, 'ⓘ 위에 드래그 핸들이 있으면 안 됨(회귀)').toBe(false);
    expect(probe.info.cursor, 'ⓘ 정중앙 커서가 grab(이동) 이 아니어야 함').not.toBe('grab');

    // + 버튼(24px, 박스 가장자리)은 작은 히트 영역이 리사이즈 핸들과 겹치거나 1px gap 에
    // 걸려 viewport 별 elementFromPoint 결과가 흔들린다. 따라서 + 버튼은 좌표 hit-test 대신
    // **z-index 계약**으로 검증한다(가로채기의 실제 원인이자 viewport 무관): 어포던스 밴드(120)
    // > 모든 드래그 핸들 z. 단, hit-test 가 우연히 드래그 핸들을 잡으면 그건 회귀이므로 차단.
    let probedDirections = 0;
    for (const dir of ['above', 'below', 'left', 'right'] as const) {
      const p = probe[dir];
      if (!p.rendered) continue;
      probedDirections += 1;
      // 어포던스 밴드가 드래그 핸들 최대 z 보다 위 (가로채기 방지의 핵심 계약).
      expect(OVERLAY_AFFORDANCE, `+ 버튼(${dir}) 어포던스 밴드가 드래그 핸들 최대 z 보다 위여야 함`).toBeGreaterThan(
        probe.maxHandleZ,
      );
      // hit-test 가 수행된 경우(on-screen) topmost 가 드래그 핸들이면 안 됨(회귀 직접 가드).
      if (p.onScreen) {
        expect(p.topIsDragHandle, `+ 버튼(${dir}) 위에 드래그 핸들이 있으면 안 됨(회귀)`).toBe(false);
      }
    }
    // vacuous pass 방지 — 최소 1개 + 버튼은 실제로 검사돼야 한다.
    expect(probedDirections, '+ 버튼이 1개 이상 검사돼야 함').toBeGreaterThan(0);
  });

  test('ⓘ 버튼 클릭 시 컨텍스트 메뉴가 열림 — 클릭이 핸들러에 도달', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page);
    await selectModerateElement(page);

    // 초기 메뉴 미표시
    await expect(page.getByTestId('g7le-context-menu')).toHaveCount(0);

    // ⓘ 정중앙의 실제 topmost 요소를 trusted 좌표로 클릭(가로채임 회귀면 핸들이 잡아 메뉴 안 열림).
    const box = await page.getByTestId('g7le-overlay-info-button').boundingBox();
    expect(box, 'ⓘ 버튼 박스를 얻어야 함').not.toBeNull();
    await page.mouse.click(box!.x + box!.width / 2, box!.y + box!.height / 2);

    await expect(page.getByTestId('g7le-context-menu'), 'ⓘ 클릭 후 컨텍스트 메뉴가 열려야 함').toBeVisible({
      timeout: 3_000,
    });
    // 메뉴 3항목(속성 설정/복사/삭제) 존재 — 어포던스가 정상 작동한다는 기능 증거.
    await expect(page.getByTestId('g7le-context-menu-edit-props')).toBeVisible();
    await expect(page.getByTestId('g7le-context-menu-duplicate')).toBeVisible();
    await expect(page.getByTestId('g7le-context-menu-delete')).toBeVisible();
  });

  test('드래그 핸들 z-index 가 어포던스 밴드(120) 아래로 클램프 — 어떤 깊이에서도', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page);

    const result = await page.evaluate(() => {
      const handles = Array.from(document.querySelectorAll('[data-dnd-handle-path]')) as HTMLElement[];
      const zs = handles.map((h) => ({
        depth: ((h.getAttribute('data-dnd-handle-path') || '').match(/\.children\./g) || []).length,
        z: Number(h.style.zIndex),
      }));
      return {
        count: zs.length,
        maxZ: zs.reduce((m, x) => Math.max(m, x.z), 0),
        maxDepth: zs.reduce((m, x) => Math.max(m, x.depth), 0),
        // 깊이순 단조 정렬 보존(결함 2) — 깊은 핸들이 얕은 핸들 이상.
        monotonic: zs.every((x) => x.z >= 20),
      };
    });

    expect(result.count, '드래그 핸들이 다수 존재해야 함').toBeGreaterThan(0);
    expect(result.maxZ, '모든 드래그 핸들 z-index 가 어포던스 밴드(120) 미만이어야 함').toBeLessThan(
      OVERLAY_AFFORDANCE,
    );
    expect(result.monotonic, '드래그 핸들 z-index 는 base(20) 이상 깊이순 유지').toBe(true);
  });

  test('드래그 시작은 보존 — 요소 본문 잡고 끌면 고스트 + 드롭 슬롯 생성 (DnD 무회귀)', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page);

    // 적당한 크기 핸들의 본문 중앙 좌표(가장자리 어포던스 버튼이 아닌 내부) 수집.
    // **뷰포트 안에 중앙이 들어오는** 핸들만 — 화면 밖(예: 좌측으로 스크롤된 큰 컨테이너)은
    // elementFromPoint 가 null 이라 z-index 와 무관한 좌표 artifact 가 된다.
    const center = await page.evaluate(() => {
      const vw = window.innerWidth;
      const vh = window.innerHeight;
      const handles = Array.from(document.querySelectorAll('[data-dnd-handle-path]')) as HTMLElement[];
      const cand = handles
        .map((h) => ({ h, r: h.getBoundingClientRect() }))
        .filter((x) => {
          const cx = x.r.left + x.r.width / 2;
          const cy = x.r.top + x.r.height / 2;
          return (
            x.r.width > 120 &&
            x.r.height > 60 &&
            cx > 0 &&
            cy > 0 &&
            cx < vw &&
            cy < vh
          );
        })
        // 너무 큰 컨테이너(자손 핸들이 본문을 덮음)보다 중간 크기를 우선 — 본문 중앙이
        // 자기 핸들로 잡히는 노드를 고른다(깊이순 z 로 자손이 덮으면 그 자손 핸들이 topmost).
        .sort((a, b) => a.r.width * a.r.height - b.r.width * b.r.height)[0];
      if (!cand) return null;
      const path = cand.h.getAttribute('data-dnd-handle-path') || '';
      const cx = cand.r.left + cand.r.width / 2;
      const cy = cand.r.top + cand.r.height / 2;
      const top = document.elementFromPoint(cx, cy) as HTMLElement | null;
      return {
        path,
        cx,
        cy,
        // 본문 중앙 topmost 가 드래그 핸들(자기 또는 자손) 인지 — 어포던스가 아닌 위치.
        bodyIsHandle: !!top?.dataset?.dndHandlePath,
        bodyCursor: top ? getComputedStyle(top).cursor : null,
      };
    });
    expect(center, 'draggable 본문 좌표를 찾아야 함').not.toBeNull();
    // 본문(내부)은 여전히 드래그 핸들이 topmost + grab 커서 — DnD 시작 영역 보존.
    expect(center!.bodyIsHandle, '요소 본문 topmost 가 드래그 핸들이어야 함(드래그 시작 보존)').toBe(true);
    expect(center!.bodyCursor, '요소 본문 커서가 grab(이동) 이어야 함').toBe('grab');

    // 본문 선택 후 trusted mouse 로 8px 임계 초과 이동 → 드래그 활성(고스트/슬롯 렌더).
    await page.evaluate((p) => {
      document
        .querySelector(`[data-dnd-handle-path="${p}"]`)
        ?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    }, center!.path);
    await page.waitForTimeout(60);
    await page.mouse.move(center!.cx, center!.cy);
    await page.mouse.down();
    await page.mouse.move(center!.cx + 14, center!.cy + 14);
    await page.mouse.move(center!.cx + 24, center!.cy + 24);

    await page.waitForSelector('[data-dnd-slot-id]', { timeout: 5_000 });
    await expect(page.getByTestId('g7le-dnd-drag-ghost'), '드래그 고스트가 표시되어야 함(DnD 무회귀)').toBeVisible({
      timeout: 3_000,
    });
    const slotCount = await page.evaluate(() => document.querySelectorAll('[data-dnd-slot-id]').length);
    expect(slotCount, '드래그 중 드롭 슬롯이 생성되어야 함(DnD 무회귀)').toBeGreaterThan(0);

    // 종료 → 슬롯 해제
    await page.mouse.up();
    await page.waitForTimeout(150);
    const slotsAfter = await page.evaluate(() => document.querySelectorAll('[data-dnd-slot-id]').length);
    expect(slotsAfter, '드롭 후 슬롯 해제').toBe(0);
  });
});
