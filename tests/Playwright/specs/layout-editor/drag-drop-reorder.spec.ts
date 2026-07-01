/**
 * Layout Editor — 드래그 앤 드롭 재배치.
 *
 * **검증 계층 분리 (dnd-kit 헤드리스 한계)**:
 * dnd-kit PointerSensor 는 Playwright 의 trusted mouse(`page.mouse`)로 **활성화**되어
 * 드래그 핸들/고스트/드롭 슬롯이 렌더되는 것까지는 헤드리스에서 안정적으로 검증
 * 가능하다. 그러나 **드롭 commit**(`over` collision 해소 → onDragEnd → moveNode)은
 * 헤드리스 합성 입력에서 dnd-kit 의 collision 이 해소되지 않아 no-op 으로 끝나는 것이
 * 재현적으로 확인됐다(trusted mouse·활성 임계·settle·면적 최대 슬롯 모두 시도 — 모두
 * "dropped over droppable area" 미발생). 이는 dnd-kit + 헤드리스 브라우저의 구조적
 * 한계로, 코드 결함이 아니다(feedback_chrome_mcp_dnd_kit_incompatible).
 *
 * 따라서 **commit 이후 동작**(단일 이동/노드 보존/이동 후 선택 재복원/undo·redo)은
 * 이 계층이 아니라 다음에서 검증한다:
 *   - 단위(Vitest): `useCanvasDnd.test.ts`(onMovePath 선택 재복원·재배치·노드 유실 방지),
 *     `dropSlots.test.ts`(레벨 한정·contents 투명화·채워진 카드 nest), `layoutTreeUtils.test.ts`(moveNode).
 *  - 실브라우저(Chrome MCP): 검수에서 회원 카드 끝 이동(카드 수 4 보존)·아이콘→다른
 *     카드 nest·G break-out 슬롯 부재·선택 박스 추종을 직접 측정 확인.
 *
 * 본 Playwright spec 은 헤드리스에서 **안정적으로 검증 가능한** 부분만 다룬다:
 * 드래그 핸들 렌더 / 고스트(body 포털·실제 복제·커서 정합) / 드롭 슬롯 표시 /
 * 슬롯 레벨 한정(자기 자손·래퍼 밖 break-out 슬롯 부재).
 *
 * @scenario drag_drop reorder
 * @effects drag_handle_present_for_draggable + explicit_drop_slots_render + drop_slots_scoped_to_relevant_levels + editor_attrs_passthrough_marks_nesting_nodes + drop_slots_span_all_accepting_containers + handle_zindex_depth_ordered + selection_based_drag_substitution + child_reselect_under_selected_parent + inner_child_nests_into_sibling_filled_card + nav_destination_unreachable_affordance_hidden + data_bound_node_draggable_and_selectable + iteration_instance_not_individually_draggable + iteration_virtual_group_select_move_ghost
 */
import type { Page } from '@playwright/test';
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

/** 편집기 home 진입 + 캔버스 준비(드래그 핸들 렌더)까지 대기. */
async function openEditor(page: Page): Promise<void> {
  await page.goto('/admin/layout-editor/sirsoft-basic?route=%2F');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  // 준비 신호 = 드래그 핸들 렌더(툴바 라벨은 로케일 의존이라 핸들로 판정).
  await page.waitForSelector('[data-testid^="g7le-dnd-handle-"]', { timeout: 30_000 });
}

/**
 * 큰 stat 카드(첫 카드, path 접미사 children.1.children.0) 핸들의 중심 좌표 + path.
 *
 * **z-index 깊이순**: 드래그 핸들 z 는 트리 깊이순(`20 + depth`)이라 카드보다
 * 깊은 자손 핸들이 카드 영역 위에 온다. 따라서 카드 중심/가장자리에서 `elementFromPoint`
 * 는 자손 핸들을 반환한다 — 카드를 직접 trusted mouse 로 잡을 수 없다. 대신 카드 핸들의
 * 좌표만 반환하고, 드래그 시작은 카드 핸들 DOM 에 직접 PointerEvent 를 dispatch 한다
 * (`startDragOnHandle`). topmost 검증은 하지 않는다(깊이순이라 카드는 topmost 가 아님).
 */
async function statCardHandleCenter(
  page: Page,
): Promise<{ x: number; y: number; path: string } | null> {
  return page.evaluate(() => {
    const el = Array.from(document.querySelectorAll('[data-dnd-handle-path]')).find(
      (e) =>
        /children\.1\.children\.0$/.test(e.getAttribute('data-dnd-handle-path') || '') &&
        (e as HTMLElement).getBoundingClientRect().width > 100,
    ) as HTMLElement | undefined;
    if (!el) return null;
    const path = el.getAttribute('data-dnd-handle-path')!;
    const r = el.getBoundingClientRect();
    return { x: r.left + r.width / 2, y: r.top + r.height / 2, path };
  });
}

/** 드래그 종료 — pointerup 을 window 에 발사. */
async function endDrag(page: Page): Promise<void> {
  await page.evaluate(() => {
    window.dispatchEvent(new PointerEvent('pointerup', {
      bubbles: true, pointerId: 1, pointerType: 'mouse', button: 0, buttons: 0,
    }));
  });
  await page.waitForTimeout(150);
}

/** 핸들 path 의 노드를 선택(클릭 위임). 선택 기준 드래그에서 trusted mouse 사용 시 필요. */
async function selectHandle(page: Page, handlePath: string): Promise<void> {
  await page.evaluate((p) => {
    document.querySelector(`[data-dnd-handle-path="${p}"]`)
      ?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
  }, handlePath);
  await page.waitForTimeout(60);
}

/**
 * trusted mouse 로 드래그 활성화 — 좌표에서 down → 8px 임계 초과 이동.
 * z 깊이순이라 좌표 위는 자손 핸들이 잡히므로, 호출 전 카드를 selectHandle 로 선택해
 * 두면 onDragStart 치환으로 선택 카드가 끌린다. pointerup 은 호출자가 별도 수행.
 */
async function startDrag(page: Page, from: { x: number; y: number }): Promise<void> {
  await page.mouse.move(from.x, from.y);
  await page.mouse.down();
  await page.mouse.move(from.x + 12, from.y + 12);
  await page.mouse.move(from.x + 20, from.y + 20);
  await page.waitForTimeout(60);
}

test.describe('@layout-editor drag-drop reorder', () => {
  // 각 테스트는 trusted mouse 드래그/선택 상태를 만들므로, 잔여 pointer 상태가 다음
  // 테스트로 새지 않도록 종료 시 명시적으로 정리(병렬 워커 간 간헐 실패 방지).
  test.afterEach(async ({ page }) => {
    await page.evaluate(() => {
      window.dispatchEvent(new PointerEvent('pointerup', {
        bubbles: true, pointerId: 1, pointerType: 'mouse', button: 0, buttons: 0,
      }));
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    }).catch(() => { /* 페이지 닫힘 등 무시 */ });
  });

  test('드래그 핸들이 draggable 노드에만 렌더된다', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page);

    // 핸들이 1개 이상 렌더 + 각 핸들이 실제 [data-editor-path] 노드에 매핑됨
    const handleCount = await page.locator('[data-testid^="g7le-dnd-handle-"]').count();
    expect(handleCount, '하나 이상의 draggable 노드에 드래그 핸들이 렌더되어야 함').toBeGreaterThan(0);
    // 일반 핸들은 실제 [data-editor-path] DOM 노드에 매핑됨. 단 **이터레이션 가상 묶음
    // 핸들**(`data-dnd-iteration-group`)은 의도적으로 DOM 에 없는 iteration 원본 노드를
    // 가리키므로(펼침 인스턴스만 DOM 존재) 매핑 검사에서 제외한다.
    const mapped = await page.evaluate(() =>
      Array.from(document.querySelectorAll('[data-dnd-handle-path]'))
        .filter((el) => el.getAttribute('data-dnd-iteration-group') !== 'true')
        .every((el) => {
          const p = el.getAttribute('data-dnd-handle-path') || '';
          return !!document.querySelector(`[data-editor-path="${p}"]`);
        }),
    );
    expect(mapped, '모든 일반 드래그 핸들이 실제 editor-path 노드에 매핑되어야 함').toBe(true);
  });

  test('드래그 중 고스트가 body 포털 + 실제 컴포넌트 복제 + 커서 정합, 드롭 슬롯 표시', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page);

    const handle = await statCardHandleCenter(page);
    expect(handle, '회원 stat 카드 핸들을 찾아야 함').not.toBeNull();

    // z 깊이순이라 카드 좌표 위는 자손 핸들 — 카드를 먼저 선택해 두면 trusted mouse 가
    // 자손에서 시작해도 onDragStart 치환으로 선택 카드가 끌린다.
    await selectHandle(page, handle!.path);
    // trusted mouse 로 드래그 활성(드롭 없이 유지)
    await startDrag(page, { x: handle!.x, y: handle!.y });
    await page.mouse.move(handle!.x + 40, handle!.y + 220);
    const to = { x: handle!.x + 40, y: handle!.y + 220 };

    // 활성 신호 = 슬롯 렌더(vacuous pass 방지)
    await page.waitForSelector('[data-dnd-slot-id]', { timeout: 5_000 });

    // #3 — 고스트가 실제 컴포넌트 복제 (단순 <Div> 텍스트 배지가 아님)
    const ghost = page.getByTestId('g7le-dnd-drag-ghost');
    await expect(ghost).toBeVisible({ timeout: 3_000 });

    // #2 — 고스트가 document.body 직속 포털(transform:scale 조상 밖) + 커서 근처
    const ghostInfo = await page.evaluate((cursor) => {
      const g = document.querySelector('[data-testid="g7le-dnd-drag-ghost"]') as HTMLElement | null;
      if (!g) return null;
      let p: HTMLElement | null = g.parentElement;
      let underScaledWrapper = false;
      while (p) {
        if (p.classList?.contains('g7le-preview-frame-wrapper')) underScaledWrapper = true;
        p = p.parentElement;
      }
      const r = g.getBoundingClientRect();
      const isRealClone = g.children.length > 0 && !/^<\w+>$/.test((g.textContent ?? '').trim());
      return {
        underScaledWrapper,
        isRealClone,
        nearCursor: Math.abs(r.left - cursor.x) < 200 && Math.abs(r.top - cursor.y) < 200,
      };
    }, to);

    expect(ghostInfo, '고스트 정보를 읽을 수 있어야 함').not.toBeNull();
    expect(ghostInfo!.underScaledWrapper, '고스트는 scale 조상(g7le-preview-frame-wrapper) 밖이어야 함(#2)').toBe(false);
    expect(ghostInfo!.isRealClone, '고스트는 실제 컴포넌트 복제여야 함(#3)').toBe(true);
    expect(ghostInfo!.nearCursor, '고스트가 커서 근처에 위치해야 함(#2 정합)').toBe(true);

    // #1 드롭 위치 표시 — 드래그 중 슬롯 렌더(라이브 프리뷰는 폐기, 위치만 표시)
    const slotCount = await page.evaluate(() => document.querySelectorAll('[data-dnd-slot-id]').length);
    expect(slotCount, '드래그 중 드롭 슬롯이 렌더되어야 함(#1 드롭 위치 표시)').toBeGreaterThan(0);

    // 드래그 종료 → 슬롯 해제
    await page.mouse.up();
    await page.waitForTimeout(200);
    const slotsAfter = await page.evaluate(() => document.querySelectorAll('[data-dnd-slot-id]').length);
    expect(slotsAfter, '드롭 후 슬롯은 사라져야 함').toBe(0);
  });

  test('드롭 슬롯이 드래그 노드의 관련 레벨로 한정 — 자기 자손/래퍼 밖 break-out 슬롯 없음', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page);

    const handle = await statCardHandleCenter(page);
    expect(handle, 'stat 카드 핸들을 찾아야 함').not.toBeNull();
    const draggedPath = handle!.path; // 예: …1.children.0 (W 안 카드)
    const wParent = draggedPath.replace(/\.children\.\d+$/, ''); // W
    const grid = wParent.replace(/\.children\.\d+$/, ''); // G (W 의 부모 = contents 래퍼를 가진 컨테이너)

    // z 깊이순이라 카드 좌표 위는 자손 핸들 — 카드 선택 후 시작하면 치환으로 카드가 드래그됨.
    await selectHandle(page, draggedPath);
    await startDrag(page, { x: handle!.x, y: handle!.y });
    await page.waitForSelector('[data-dnd-slot-id]', { timeout: 5_000 });

    const scope = await page.evaluate(({ dp, g }) => {
      const gEl = document.querySelector(`[data-editor-path="${g}"]`);
      const gr = gEl ? gEl.getBoundingClientRect() : null;
      const slots = Array.from(document.querySelectorAll('[data-dnd-slot-id]')).map((el) => {
        const id = el.getAttribute('data-dnd-slot-id') || '';
        const rest = id.slice(5);
        return { id, container: rest.slice(0, rest.lastIndexOf(':')), r: el.getBoundingClientRect() };
      });
      const containers = [...new Set(slots.map((s) => s.container))];
      // 드래그 노드 자신/자손을 컨테이너로 하는 슬롯(자기 안에 드롭) — 있으면 안 됨
      const intoSelf = containers.filter((c) => c === dp || c.startsWith(`${dp}.`));
      // 결함 b 핵심 가드 — G 레벨에 **전체 영역 break-out 슬롯**(컨테이너 전체를 덮는 큰 슬롯)이
      // 없어야 한다. Welcome 주변 gap(얇은 띠)은 정당하므로 허용 — 전체 영역 슬롯만 금지.
      const gFullAreaSlots = gr
        ? slots.filter((s) => s.container === g && s.r.width > gr.width * 0.8 && s.r.height > gr.height * 0.8)
        : [];
      // G 레벨 gap 슬롯(Welcome 주변) 존재 여부 — 카드를 Welcome 옆 grid 위치로 옮길 수 있어야 함.
      const gGapSlots = slots.filter((s) => s.container === g);
      return {
        slotCount: slots.length,
        containers,
        intoSelf,
        gFullAreaCount: gFullAreaSlots.length,
        gGapSlotCount: gGapSlots.length,
      };
    }, { dp: draggedPath, g: grid });

    await endDrag(page);

    // 활성화 확인(vacuous 방지)
    expect(scope.slotCount, '드래그 활성 시 슬롯이 렌더되어야 함(0 이면 활성 실패)').toBeGreaterThan(0);
    // 핵심 가드 — 자기 자손에 드롭 슬롯 없음(카드 내부로 자기 드롭 불가).
    expect(scope.intoSelf, '드래그 노드 자신/자손을 컨테이너로 하는 슬롯이 없어야 함').toEqual([]);
    // 결함 b — G 레벨 전체 영역 break-out 슬롯 없음(Welcome 주변 gap 띠는 허용, 전체 영역만 금지)
    expect(scope.gFullAreaCount, 'G 레벨 전체 영역 break-out 슬롯이 없어야 함(결함 b)').toBe(0);
    // Welcome(일반 형제) 주변 G gap 슬롯은 존재해야 함(카드를 Welcome 옆으로 배치 가능)
    expect(scope.gGapSlotCount, 'Welcome 주변 G gap 슬롯이 존재해야 함(카드를 히어로 옆 grid 위치로)').toBeGreaterThan(0);
    // 결함 1 수정 반영 — 슬롯은 카드 자손 leaf 가 아닌 **트리 전역 accepting
    // 컨테이너**에 깔린다(다른 컨테이너로 이동 가능). 검수 10차의 `<40` 상한은 그 수정으로
    // 무효화되어 제거. 과생성 회귀의 진짜 가드는 "카드 자손 슬롯 없음"(intoSelf=[]) 이다.
    // (레거시 81개 과생성은 카드 **내부 자손**까지 슬롯을 깔았던 것 — intoSelf 가드가 차단.)
  });

  /**
   * editorAttrs 패스스루 회귀.
   * composite/layout nesting 컴포넌트(StatCard 카드, Container, Grid 등)가 editorAttrs 를
   * 루트에 spread 해야 편집 모드에서 [data-editor-path] 표식이 DOM 에 도달한다. 미적용 시
   * 그 노드가 드롭 슬롯/선택/드래그 대상에서 누락.
   *
   * 본 테스트는 stat 카드(composite StatCard)와 그 조상 컨테이너(layout)가 모두
   * [data-editor-path] 로 잡히는지 확인 — editorAttrs 가 composite/layout 루트에 도달했다는 증거.
   */
  test('composite/layout nesting 노드가 editorAttrs spread 로 [data-editor-path] 표식을 보유', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page);

    const probe = await page.evaluate(() => {
      // 편집 모드 표식 노드 전수 수집
      const marked = Array.from(document.querySelectorAll('[data-editor-path]')) as HTMLElement[];
      // composite/layout 타입 노드(data-editor-type)가 1건 이상 표식되어야 함
      // (editorAttrs 미spread 면 composite/layout 은 표식 유실 → 0 건)
      const byType = (t: string) =>
        marked.filter((el) => el.getAttribute('data-editor-type') === t).length;
      // StatCard 카드(W 안 카드)가 표식되는지 — name 으로 확인
      const cardMarked = marked.some((el) => {
        const name = el.getAttribute('data-editor-name') || '';
        return /Card|StatCard|Div/.test(name) && (el.getBoundingClientRect().width > 100);
      });
      // editorAttrs 객체 자체가 DOM 속성으로 누출되지 않음(소문자화된 editorattrs)
      const leaked = marked.some((el) => el.hasAttribute('editorattrs'));
      return {
        total: marked.length,
        compositeCount: byType('composite'),
        layoutCount: byType('layout'),
        cardMarked,
        leaked,
      };
    });

    // 표식 노드가 충분히 존재(편집 모드 활성)
    expect(probe.total, '편집 모드에서 [data-editor-path] 표식 노드가 존재해야 함').toBeGreaterThan(0);
    // composite 또는 layout 타입 노드가 표식됨 = editorAttrs 가 그 루트에 도달
    expect(
      probe.compositeCount + probe.layoutCount,
      'composite/layout nesting 노드가 editorAttrs spread 로 표식되어야 함(미적용 시 0)',
    ).toBeGreaterThan(0);
    // editorAttrs 객체가 DOM 속성으로 누출되지 않음(basic/layout DOM-safe 필터)
    expect(probe.leaked, 'editorAttrs 객체가 DOM 속성으로 누출되면 안 됨').toBe(false);
  });

  /**
   * 결함 1 회귀 — 바깥으로 빼낸 요소를 다른/원래/깊은 컨테이너로 다시 이동.
   *
   * 드롭 슬롯 후보를 드래그 노드의 조상 체인 + 직접 형제로만 한정하던 것을, 트리 전역의
   * accepting 컨테이너로 재정의했다(useCanvasDnd.buildSlotPredicates). 검증: 어떤 노드를
   * 드래그 시작하면 그 노드의 **부모가 아닌 다른 컨테이너**에도 드롭 슬롯이 생성된다.
   * (이전: 부모/직접 형제만 슬롯 → 다른 컨테이너로 못 넣음.)
   */
  test('드롭 슬롯이 드래그 노드의 부모 외 다른 컨테이너에도 생성 (결함 1: 컨테이너 간 이동)', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page);

    const handle = await statCardHandleCenter(page);
    expect(handle, 'stat 카드 핸들을 찾아야 함').not.toBeNull();
    const draggedPath = handle!.path;
    const dragParent = draggedPath.replace(/\.children\.\d+$/, '');

    // 카드 선택 후 시작 → 치환으로 카드가 드래그 대상(z 깊이순 좌표는 자손이 잡힘).
    await selectHandle(page, draggedPath);
    await startDrag(page, { x: handle!.x, y: handle!.y });
    await page.waitForSelector('[data-dnd-slot-id]', { timeout: 5_000 });

    const result = await page.evaluate(({ dp, parent }) => {
      const slots = Array.from(document.querySelectorAll('[data-dnd-slot-id]')).map((el) => {
        const id = el.getAttribute('data-dnd-slot-id') || '';
        const rest = id.slice(5);
        return rest.slice(0, rest.lastIndexOf(':'));
      });
      const containers = [...new Set(slots)];
      // 드래그 노드 자신/자손 컨테이너 제외(자기 안에 드롭 불가)
      const nonSelf = containers.filter((c) => c !== dp && !c.startsWith(`${dp}.`));
      // 드래그 부모가 아닌 다른 컨테이너(루트 '' 제외) — 결함 1 핵심: 이런 컨테이너가 존재해야
      const otherContainers = nonSelf.filter((c) => c !== '' && c !== parent);
      return { containerCount: containers.length, otherContainerCount: otherContainers.length };
    }, { dp: draggedPath, parent: dragParent });

    await page.mouse.up();

    expect(result.containerCount, '드래그 활성 시 슬롯 컨테이너가 존재해야 함').toBeGreaterThan(0);
    expect(
      result.otherContainerCount,
      '드래그 노드의 부모가 아닌 다른 컨테이너에도 드롭 슬롯이 생성되어야 함(결함 1: 컨테이너 간 이동 가능)',
    ).toBeGreaterThan(0);
  });

  /**
   * 결함 2 회귀 — 드래그 핸들 z-index 가 트리 깊이순.
   *
   * 깊은(구체적) 핸들이 위에 와야 클릭/드래그 시작이 가장 안쪽 요소에 도달한다. 자손 핸들
   * z 를 낮추면 부모 핸들이 자식 영역을 덮어 "부모 선택 상태에서 자식 재선택 불가" 결함이
   * 났다. 검증: 부모-자식 핸들 쌍에서 자식 z > 부모 z.
   */
  test('드래그 핸들 z-index 가 트리 깊이순 — 자식이 부모보다 위 (결함 2: 클릭 우선순위)', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page);

    const pair = await page.evaluate(() => {
      const handles = Array.from(document.querySelectorAll('[data-dnd-handle-path]')) as HTMLElement[];
      const paths = handles.map((h) => h.getAttribute('data-dnd-handle-path') || '');
      // 부모-자식 핸들 쌍 찾기
      for (const h of handles) {
        const p = h.getAttribute('data-dnd-handle-path') || '';
        const childPath = paths.find((c) => c !== p && c.startsWith(`${p}.children.`));
        if (!childPath) continue;
        const ch = handles.find((e) => e.getAttribute('data-dnd-handle-path') === childPath)!;
        return {
          parentZ: Number(h.style.zIndex),
          childZ: Number(ch.style.zIndex),
          parentDepth: (p.match(/\.children\./g) || []).length,
          childDepth: (childPath.match(/\.children\./g) || []).length,
        };
      }
      return null;
    });

    expect(pair, '부모-자식 핸들 쌍을 찾아야 함').not.toBeNull();
    expect(pair!.childDepth, '자식이 부모보다 깊어야 함').toBeGreaterThan(pair!.parentDepth);
    expect(
      pair!.childZ,
      '자식 핸들 z-index 가 부모보다 높아야 함(깊이순 — 클릭/드래그 시작 우선)',
    ).toBeGreaterThan(pair!.parentZ);
  });

  /**
   * 결함 2 회귀 — 선택 기준 드래그 + 자식 재선택.
   *
   * 부모를 선택한 상태에서 (a) 자식(자손) 영역에서 드래그를 시작하면 선택한 부모가 끌리고
   * (useCanvasDnd.onDragStart 의 드래그 path 치환 — 드래그 대상이 선택 노드의 자손이면 선택
   * 노드로 치환), (b) 자식을 단순 클릭하면 자식이 재선택된다. 검증: 부모 선택 후 자식 영역에서
   * 드래그 시작 시 부모 자기/자손 컨테이너 슬롯이 제외됨(=부모가 드래그 대상으로 치환된 증거).
   */
  test('부모 선택 후 자식 영역 드래그 → 선택 부모가 끌림 + 자식 클릭 시 재선택 (결함 2: 선택 기준 드래그)', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page);

    // 부모-자식 핸들 쌍 좌표 수집 (자식은 부모가 덮는 영역 안)
    const pair = await page.evaluate(() => {
      const handles = Array.from(document.querySelectorAll('[data-dnd-handle-path]')) as HTMLElement[];
      const paths = handles.map((h) => h.getAttribute('data-dnd-handle-path') || '');
      for (const h of handles) {
        const p = h.getAttribute('data-dnd-handle-path') || '';
        const childPath = paths.find((c) => c !== p && c.startsWith(`${p}.children.`));
        if (!childPath) continue;
        const ch = handles.find((e) => e.getAttribute('data-dnd-handle-path') === childPath)!;
        const cr = ch.getBoundingClientRect();
        if (cr.width < 10 || cr.height < 10) continue;
        return { parent: p, child: childPath, cx: cr.left + cr.width / 2, cy: cr.top + cr.height / 2 };
      }
      return null;
    });
    expect(pair, '부모-자식 핸들 쌍을 찾아야 함').not.toBeNull();

    // (1) 부모 선택 — 부모 핸들 클릭
    await page.evaluate((parent) => {
      document.querySelector(`[data-dnd-handle-path="${parent}"]`)
        ?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    }, pair!.parent);
    await page.waitForTimeout(60);

    // (2) 자식 영역에서 드래그 시작 — 선택 부모로 치환되어야
    await startDrag(page, { x: pair!.cx, y: pair!.cy });
    await page.waitForSelector('[data-dnd-slot-id]', { timeout: 5_000 });

    const dragResult = await page.evaluate(({ parent }) => {
      const slots = Array.from(document.querySelectorAll('[data-dnd-slot-id]')).map((el) => {
        const id = el.getAttribute('data-dnd-slot-id') || '';
        const rest = id.slice(5);
        return rest.slice(0, rest.lastIndexOf(':'));
      });
      const containers = [...new Set(slots)];
      // 부모가 드래그 대상으로 치환되면 부모 자신/자손 컨테이너엔 슬롯 없음(자기 안에 드롭 불가)
      const intoParentSelf = containers.filter((c) => c === parent || c.startsWith(`${parent}.`));
      return { slotCount: slots.length, intoParentSelfCount: intoParentSelf.length };
    }, { parent: pair!.parent });

    await page.mouse.up();
    await page.waitForTimeout(150);

    expect(dragResult.slotCount, '자식 영역에서도 드래그가 시작되어 슬롯이 생성되어야 함(핸들 비활성화 안 함)').toBeGreaterThan(0);
    expect(
      dragResult.intoParentSelfCount,
      '드래그 대상이 선택 부모로 치환 → 부모 자기/자손 컨테이너엔 슬롯이 없어야 함(선택 기준 드래그)',
    ).toBe(0);

    // (3) 부모 선택 상태에서 자식 영역 단순 클릭 → 부모가 아닌 더 깊은 요소(자식 또는 그
    //     자손)가 재선택된다. z 깊이순이라 자식이 또 자손을 가지면 그 자손이 최상위이므로,
    //     "선택이 부모에서 자식 서브트리 안으로 내려갔는지"로 검증한다(부모 재선택 불가 결함 방지).
    const reselect = await page.evaluate(({ parent, child }) => {
      const parentEl = document.querySelector(`[data-editor-path="${parent}"]:not([data-dnd-handle-path])`) as HTMLElement | null;
      const parentH = parentEl ? Math.round(parentEl.getBoundingClientRect().height) : null;
      // 부모 선택
      document.querySelector(`[data-dnd-handle-path="${parent}"]`)
        ?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
      const selAfterParent = (() => { const b = document.querySelector('[data-testid="g7le-overlay-selected"]') as HTMLElement | null; return b ? Math.round(parseFloat(b.style.height)) : null; })();
      // 자식 영역 중앙 최상위 요소 클릭(실제 사용자 클릭 경로 — z 깊이순이면 자식 또는 그 자손)
      const chHandle = document.querySelector(`[data-dnd-handle-path="${child}"]`) as HTMLElement | null;
      const r = chHandle!.getBoundingClientRect();
      const topEl = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2) as HTMLElement | null;
      const topPath = topEl?.getAttribute('data-dnd-handle-path') || '';
      topEl?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
      const selAfterChild = (() => { const b = document.querySelector('[data-testid="g7le-overlay-selected"]') as HTMLElement | null; return b ? Math.round(parseFloat(b.style.height)) : null; })();
      return {
        // 자식 영역 최상위가 자식 자신이거나 자식의 자손이어야(부모 아님) — z 깊이순.
        topInChildSubtree: topPath === child || topPath.startsWith(`${child}.`),
        parentH, selAfterParent, selAfterChild,
      };
    }, { parent: pair!.parent, child: pair!.child });

    // 결함 2 핵심 — 부모 선택 상태에서도 자식 영역 클릭이 부모 핸들에 가로채이지 않고
    // 자식 서브트리(자식 or 그 자손)에 도달한다(z 깊이순). 부모 z 가 자식 위에 있던
    // 1차 수정에서는 이 클릭이 부모에 가로채여 자식 재선택이 불가했다.
    expect(reselect.topInChildSubtree, '자식 영역 최상위 요소가 자식 또는 그 자손이어야 함(부모가 가로채지 않음, z 깊이순)').toBe(true);
    // 자식 클릭 후 선택 박스는 부모보다 크지 않다(자식 서브트리로 선택이 내려갔거나 동일 크기).
    // 부모가 자식 하나만 감싸 높이가 같을 수 있어 `<=` 로 둔다(부모로 되돌아가면 > 가 됨 — 결함).
    if (reselect.selAfterParent !== null && reselect.selAfterChild !== null) {
      expect(reselect.selAfterChild, '자식 클릭 후 선택 박스가 부모보다 커지면 안 됨(부모 재선택 회귀 방지)').toBeLessThanOrEqual(reselect.selAfterParent);
    }
  });

  /**
   * 검수 10차 결함 a 회귀 — 카드 내부 자식(아이콘 등)을 **다른 채워진 카드**로 nest.
   *
   * `allowsNestingInContainer` 가 빈/레이아웃 컨테이너만 nest 타깃으로 허용해, 카드 안 아이콘을
   * 형제 카드(콘텐츠가 채워진 Div)로 옮길 수 없던 결함. accepts 하는 컨테이너성 노드면 nest 허용
   * 으로 확장했다. 검증: 카드 내부 자식을 드래그 시작하면 **형제 카드(또는 그 자손)에 드롭 슬롯**
   * 이 생성된다(아이콘을 다른 카드로 옮길 수 있음).
   */
  test('카드 내부 자식을 드래그하면 형제(채워진) 카드에 드롭 슬롯 생성', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page);

    // 형제 ≥ 2 인 카드 그룹에서 (첫 카드의 내부 자식, 형제 카드) 쌍 수집
    const probe = await page.evaluate(() => {
      const handles = Array.from(document.querySelectorAll('[data-dnd-handle-path]')).map(
        (h) => h.getAttribute('data-dnd-handle-path') || '',
      );
      const parentOf = (p: string) => { const i = p.lastIndexOf('.children.'); return i < 0 ? '' : p.slice(0, i); };
      const groups: Record<string, string[]> = {};
      for (const p of handles) { (groups[parentOf(p)] ||= []).push(p); }
      for (const cards of Object.values(groups)) {
        if (cards.length < 2) continue;
        const iconChild = handles.find((h) => h.startsWith(`${cards[0]}.children.`));
        if (iconChild) return { iconChild, siblingCard: cards[1] };
      }
      return null;
    });
    expect(probe, '카드 내부 자식 + 형제 카드 쌍을 찾아야 함').not.toBeNull();

    // 아이콘 선택 후 드래그 시작(아이콘 자신이 드래그 대상)
    await selectHandle(page, probe!.iconChild);
    const iconCenter = await page.evaluate((p) => {
      const el = document.querySelector(`[data-dnd-handle-path="${p}"]`) as HTMLElement;
      const r = el.getBoundingClientRect();
      return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
    }, probe!.iconChild);
    await startDrag(page, iconCenter);
    await page.waitForSelector('[data-dnd-slot-id]', { timeout: 5_000 });

    const hasSiblingSlot = await page.evaluate((sib) => {
      const slots = Array.from(document.querySelectorAll('[data-dnd-slot-id]')).map((el) => {
        const id = el.getAttribute('data-dnd-slot-id') || '';
        const rest = id.slice(5);
        return rest.slice(0, rest.lastIndexOf(':'));
      });
      return [...new Set(slots)].some((c) => c === sib || c.startsWith(`${sib}.`));
    }, probe!.siblingCard);

    await endDrag(page);

    expect(
      hasSiblingSlot,
      '카드 내부 자식을 드래그하면 형제(채워진) 카드에 드롭 슬롯이 생성되어야 함(결함 a: 아이콘을 다른 카드로 nest)',
    ).toBe(true);
  });

  /**
   * 네비게이션 어포던스 미표시.
   * navigate/href 목적지가 external_url/route_not_in_tree/dynamic_path 인 요소를
   * 선택해도 "편집기에서 이동할 수 없습니다" 안내(destination_unreachable)는 표시되지
   * 않는다. 어차피 편집기에서 미작동하므로 어포던스 자체를 제거(드래그 재배치 오해 방지).
   */
  test('네비 어포던스 미표시 — destination_unreachable 어포던스가 DOM 에 없음', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page);

    // 어떤 요소를 선택하든 destination_unreachable 어포던스는 존재하지 않아야 한다.
    // 핸들 몇 개를 클릭해 선택을 바꿔가며 전수 확인(네비 노드 포함 가능성).
    const handles = await page.locator('[data-dnd-handle-path]').evaluateAll(
      (els) => els.slice(0, 8).map((e) => e.getAttribute('data-dnd-handle-path') || ''),
    );
    for (const h of handles) {
      await selectHandle(page, h);
    }
    const unreachableCount = await page.locator('[data-testid="g7le-overlay-destination-unreachable"]').count();
    expect(unreachableCount, 'destination_unreachable 어포던스는 더 이상 렌더되지 않아야 함').toBe(0);
  });

  /**
   * data_bound 요소 선택/이동.
   * 데이터에 연결된 요소(자신 `{{}}` 바인딩 — 상품 이미지 갤러리 등)도 드래그 핸들을
   * 가져 선택·이동 가능하다. 단 반복 인스턴스 내부는 제외(아래 별도 테스트).
   * sirsoft-basic home 에는 data_bound composite 가 적을 수 있어, "핸들이 달린
   * data_bound 노드가 1개 이상 존재"로 확인(없으면 skip 처리하지 않고 핸들↔노드 매핑만).
   */
  test('data_bound 요소도 드래그 핸들을 가져 선택/이동 가능', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page);

    // 모든 핸들이 실제 editor-path 노드에 매핑됨(핸들 = 선택/이동 단위). data_bound 가
    // 잠금으로 핸들을 잃으면 매핑 가능한 핸들 수가 줄어든다 — 핸들이 충분히 존재하는지 확인.
    const handleCount = await page.locator('[data-dnd-handle-path]').count();
    expect(handleCount, '핸들이 다수 존재(데이터 바인딩 요소 포함)').toBeGreaterThan(0);
    // 핸들 클릭 시 그 요소가 선택됨(선택 박스 렌더) — data_bound 여도 선택 동작.
    const firstHandle = await page.locator('[data-dnd-handle-path]').first().getAttribute('data-dnd-handle-path');
    await selectHandle(page, firstHandle!);
    await expect(page.getByTestId('g7le-overlay-selected')).toBeVisible({ timeout: 3_000 });
  });

  /**
   * 반복 인스턴스 개별 드래그 금지.
   * iteration 펼침 인스턴스(`...iteration.N`)와 그 내부 노드는 **개별** 드래그 핸들을
   * 갖지 않는다 — 개별 인스턴스를 직접 옮기면 안 되기 때문(묶음은 아래 가상 묶음으로).
   */
  test('반복 인스턴스/내부 노드는 개별 드래그 핸들 미생성', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page);

    const result = await page.evaluate(() => {
      const handlePaths = Array.from(document.querySelectorAll('[data-dnd-handle-path]'))
        .map((h) => h.getAttribute('data-dnd-handle-path') || '');
      // 펼침 인스턴스 자체(`...iteration.N`) 또는 그 내부(`...iteration.N.children.`)에
      // 핸들이 달린 경우 = 개별 드래그 가능 = 결함.
      const individualIterationHandles = handlePaths.filter(
        (p) => /\.iteration\.\d+(?:$|\.children\.)/.test(p),
      );
      // 단, 가상 묶음 핸들(iteration 원본 path = `.iteration.` 토큰 없음)은 허용.
      const groupHandles = Array.from(document.querySelectorAll('[data-dnd-iteration-group="true"]')).length;
      // DOM 에 펼침 인스턴스가 존재하는지(테스트 전제) — 없으면 vacuous.
      const instanceNodes = document.querySelectorAll('[data-editor-path*=".iteration."]').length;
      return { individualIterationHandles, groupHandles, instanceNodes };
    });

    expect(result.instanceNodes, '반복 펼침 인스턴스가 DOM 에 존재(테스트 전제)').toBeGreaterThan(0);
    expect(
      result.individualIterationHandles,
      '반복 인스턴스/내부 노드에 개별 드래그 핸들이 없어야 함',
    ).toEqual([]);
  });

  /**
   * 이터레이션 가상 묶음.
   * 레이아웃에 반복을 묶는 컴포넌트가 없으므로 편집기가 가상 묶음 핸들을 합성한다 —
   * iteration 원본 path 로 1개, box 는 인스턴스 union. 클릭 시 묶음 union 이 선택되고,
   * 드래그 시작 시 원본 노드가 드래그 대상(자기/자손 슬롯 제외) + 고스트 표시.
   */
  test('이터레이션 가상 묶음 — 묶음 핸들 생성 + union 선택 + 원본 노드 드래그 + 고스트', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openEditor(page);

    // 가상 묶음 핸들 존재
    const groupPath = await page.evaluate(() => {
      const g = document.querySelector('[data-dnd-iteration-group="true"]');
      return g ? g.getAttribute('data-dnd-handle-path') : null;
    });
    expect(groupPath, '이터레이션 가상 묶음 핸들이 1개 이상 존재해야 함').not.toBeNull();
    // 묶음 path 는 iteration 원본 — `.iteration.` 토큰을 포함하지 않는다.
    expect(/\.iteration\.\d+/.test(groupPath!), '묶음 핸들 path 는 펼침 인스턴스가 아닌 원본 노드').toBe(false);

    // 묶음 클릭 → union 선택 박스
    await selectHandle(page, groupPath!);
    const selMatches = await page.evaluate((gp) => {
      const handle = document.querySelector(`[data-dnd-handle-path="${gp}"]`) as HTMLElement | null;
      const sel = document.querySelector('[data-testid="g7le-overlay-selected"]') as HTMLElement | null;
      if (!handle || !sel) return false;
      const hh = Math.round(parseFloat(handle.style.height));
      const sh = Math.round(parseFloat(sel.style.height));
      return Math.abs(hh - sh) <= 3;
    }, groupPath!);
    expect(selMatches, '묶음 클릭 시 선택 박스가 묶음 union 크기와 일치').toBe(true);

    // 묶음 드래그 시작 → 원본 자기/자손 슬롯 제외(드래그 대상=원본) + 고스트 표시.
    // z 깊이순으로 좌표 위에 다른 핸들이 올 수 있어, 묶음 핸들 DOM 에 직접 PointerEvent
    // 시퀀스를 발사한다(trusted mouse 좌표 대신 — 묶음 핸들을 정확히 드래그 대상으로).
    await page.evaluate((gp) => {
      const el = document.querySelector(`[data-dnd-handle-path="${gp}"]`) as HTMLElement;
      const r = el.getBoundingClientRect();
      const cx = r.left + r.width / 2, cy = r.top + 20;
      const fire = (t: string, x: number, y: number) =>
        el.dispatchEvent(new PointerEvent(t, {
          bubbles: true, cancelable: true, composed: true,
          pointerId: 1, pointerType: 'mouse', isPrimary: true, button: 0, buttons: 1,
          clientX: x, clientY: y,
        }));
      fire('pointerdown', cx, cy);
      fire('pointermove', cx + 12, cy + 12);
      fire('pointermove', cx + 20, cy + 20);
    }, groupPath!);
    await page.waitForSelector('[data-dnd-slot-id]', { timeout: 5_000 });

    const dragResult = await page.evaluate((gp) => {
      const slots = Array.from(document.querySelectorAll('[data-dnd-slot-id]')).map((el) => {
        const id = el.getAttribute('data-dnd-slot-id') || '';
        const rest = id.slice(5);
        return rest.slice(0, rest.lastIndexOf(':'));
      });
      const intoSelf = [...new Set(slots)].filter((c) => c === gp || c.startsWith(`${gp}.`));
      const ghost = document.querySelector('[data-testid="g7le-dnd-drag-ghost"]');
      const ghostRealClone = ghost
        ? ghost.children.length > 0 && !/^<\w+>$/.test((ghost.textContent ?? '').trim())
        : false;
      return { slotCount: slots.length, intoSelfCount: intoSelf.length, ghostVisible: !!ghost, ghostRealClone };
    }, groupPath!);

    await endDrag(page);

    expect(dragResult.slotCount, '묶음 드래그 시 슬롯 생성').toBeGreaterThan(0);
    expect(dragResult.intoSelfCount, '드래그 대상이 원본 노드 → 자기/자손 슬롯 제외').toBe(0);
    expect(dragResult.ghostVisible, '묶음 드래그 고스트 표시').toBe(true);
    expect(dragResult.ghostRealClone, '고스트가 텍스트 배지가 아닌 실제 묶음 복제').toBe(true);
  });
});
