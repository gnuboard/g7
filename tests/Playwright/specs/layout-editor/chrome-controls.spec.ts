/**
 * Layout Editor — chrome 컨트롤 회귀 가드
 *
 * 결함 2/4/5/6/7/8 의 브라우저 통합 검증:
 *  - 결함 2: 디바이스 custom 선택 시 width 입력 노출 + 프레임 폭 반영
 *  - 결함 4: 나가기 버튼 활성 + 클릭 시 템플릿 타입별 목록(/admin/templates/{type}) 이탈
 *  - 결함 5: 코드편집 버튼 활성 + 클릭 시 선택 route 를 ?route= 로 실어 /admin/templates/{id}/edit 를 새 창으로 열고 위지윅 화면 유지
 *  - 결함 6: 저장 클릭 시 진행 스피너 노출
 *  - 결함 7/8 의 단위 가드는 overlayGeometry / ComponentPalette 단위 테스트가 커버
 *
 * 코드편집기(텍스트 편집 화면) 의 ?route= 복원은 sirsoft-admin_basic 템플릿 소유
 * 레이아웃이므로 템플릿 자체 E2E(templates/_bundled/sirsoft-admin_basic/tests/Playwright)
 * 가 커버한다 — 본 코어 spec 은 위지윅 chrome 책임만.
 *
 * @scenario preview_device + custom_width_input + toolbar_action + selected_box_size + affordance_kind + mobile_closed_drawer_clipping + route_tree_node_source_kind
 * @effects preview_custom_device_uses_clamped_input_width_as_override + toolbar_exit_navigates_to_template_list_by_type + toolbar_edit_code_opens_text_layout_editor_in_new_window_with_route + toolbar_save_shows_spinner_while_pending_then_restores + insertion_affordances_cross_layout_when_box_lt_44_no_overlap_no_clip + mobile_preview_closed_drawer_clipped_inside_frame_not_visible + route_tree_item_shows_layout_file_path_under_label_with_source_prefix
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const EDITOR_URL = '/admin/layout-editor/sirsoft-basic?route=%2F';

async function enterEditor(page: import('@playwright/test').Page): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto(EDITOR_URL);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-toolbar"]', { timeout: 30_000 });
  // 라우트/프레임 로드 완료 대기
  await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
}

test.describe('@layout-editor chrome controls', () => {
  test('결함 2 — custom 디바이스 선택 시 width 입력 노출 + 프레임 폭 반영', async ({ page }) => {
    await enterEditor(page);

    await page.getByTestId('g7le-device-custom').click();
    const input = page.getByTestId('g7le-custom-width-input');
    await expect(input).toBeVisible();

    // 500px 입력 → 프레임 폭 500 반영
    await input.fill('500');
    await input.dispatchEvent('change');
    await expect
      .poll(async () =>
        page.evaluate(() => {
          const f = document.querySelector('[data-testid="g7le-preview-frame"]') as HTMLElement | null;
          return f ? Math.round(f.getBoundingClientRect().width) : 0;
        }),
      )
      .toBe(500);
  });

  test('결함 4 — 나가기 버튼 클릭 시 user 템플릿 목록(/admin/templates/user)으로 이탈', async ({ page }) => {
    // sirsoft-basic 은 user 템플릿 → 나가기 목적지는 /admin/templates/user
    await enterEditor(page);
    const exit = page.getByTestId('g7le-toolbar-exit');
    await expect(exit).toBeEnabled();
    await exit.click();
    await page.waitForURL(/\/admin\/templates\/user(\b|$)/, { timeout: 15_000 });
    expect(page.url()).toMatch(/\/admin\/templates\/user/);
  });

  test('결함 5 — 코드편집 버튼 클릭 시 선택 route 를 ?route= 로 실어 새 창으로 연다', async ({ page, context }) => {
    // EDITOR_URL 은 ?route=%2F (홈) 선택 상태 → 코드편집기를 새 창(_blank)으로 열고
    // ?route=/ 동기화. 위지윅 편집 화면(page)은 그대로 유지된다.
    await enterEditor(page);
    const code = page.getByTestId('g7le-toolbar-edit-code');
    await expect(code).toBeEnabled();

    const popupPromise = context.waitForEvent('page', { timeout: 15_000 });
    await code.click();
    const popup = await popupPromise;
    await popup.waitForLoadState('domcontentloaded', { timeout: 15_000 });

    const url = new URL(popup.url());
    expect(url.pathname).toMatch(/\/admin\/templates\/sirsoft-basic\/edit/);
    expect(url.searchParams.get('route')).toBe('/');

    // 원래 위지윅 편집 화면은 이탈하지 않고 유지
    expect(page.url()).toMatch(/\/admin\/layout-editor\/sirsoft-basic/);
    await popup.close();
  });

  test('결함 8 — 팔레트 카드에 React 컴포넌트명 태그 배지 표시', async ({ page }) => {
    await enterEditor(page);
    // 요소 추가 팔레트 열기 (라우트 선택 상태에서 활성)
    await page.waitForFunction(
      () => !document.querySelector('[data-testid="g7le-toolbar-add-element"]')?.hasAttribute('disabled'),
      { timeout: 15_000 },
    );
    await page.getByTestId('g7le-toolbar-add-element').click();
    await page.waitForSelector('[data-testid="g7le-palette"]', { timeout: 10_000 });

    await expect(page.getByTestId('g7le-palette-item-Div-tag')).toHaveText('<Div>');
    await expect(page.getByTestId('g7le-palette-item-Button-tag')).toHaveText('<Button>');
  });

  test('후속 — 좌측 트리 라우트 노드에 레이아웃 파일 경로가 라벨 하단에 노출 (출처 prefix)', async ({ page }) => {
    // sirsoft-basic 은 템플릿 소스 → 경로 표기는 "layouts/{layoutName}.json".
    // 트리의 라우트 항목마다 라벨 아래 회색 보조 줄(g7le-route-tree-layout-path)이
    // 노출되고, 그 텍스트가 layouts/ 로 시작하는.json 파일 경로여야 한다.
    await enterEditor(page);
    await page.waitForSelector('[data-testid="g7le-route-tree-item"]', { timeout: 15_000 });

    const result = await page.evaluate(() => {
      const items = Array.from(
        document.querySelectorAll('[data-testid="g7le-route-tree-item"]'),
      ) as HTMLElement[];
      const paths = items
        .map((it) => it.querySelector('[data-testid="g7le-route-tree-layout-path"]') as HTMLElement | null)
        .filter(Boolean) as HTMLElement[];
      // 홈 라우트(/)의 경로 줄 텍스트 확인 — layouts/home.json
      const home = items.find((it) => it.getAttribute('data-route-path') === '/');
      const homePathEl = home?.querySelector('[data-testid="g7le-route-tree-layout-path"]') as HTMLElement | null;
      return {
        itemCount: items.length,
        pathCount: paths.length,
        // 표시된 경로 줄은 모두 .json 으로 끝나야 한다
        allEndJson: paths.length > 0 && paths.every((p) => (p.textContent || '').trim().endsWith('.json')),
        homePathText: (homePathEl?.textContent || '').trim(),
        // 그룹 헤더에는 경로 줄이 없어야 한다
        groupHasPath: Array.from(document.querySelectorAll('[data-testid="g7le-route-tree-group"]')).some(
          (g) => g.querySelector('[data-testid="g7le-route-tree-layout-path"]'),
        ),
      };
    });

    expect(result.itemCount).toBeGreaterThan(0);
    expect(result.pathCount).toBeGreaterThan(0);
    expect(result.allEndJson).toBe(true);
    // 홈 라우트는 layouts/home.json (sirsoft-basic 템플릿 소스, 식별자 prefix 동반)
    expect(result.homePathText).toContain('layouts/home.json');
    expect(result.groupHasPath).toBe(false);
  });

  test('결함 7 재조사 — 작은 요소 선택 시 삽입 어포던스가 박스 중심 십자로 비겹침·비잘림', async ({ page }) => {
    // 인라인 텍스트(Span) 추가 → 작은 박스(폭<44) 선택 → 4방향 + 버튼이 박스
    // 중심 기준 위/아래/좌/우 십자로 벌어져 서로/박스와 겹치지 않고, 캔버스에도
    // 잘리지 않음(오버레이 레이어 분리).
    await enterEditor(page);

    // 큰 컨테이너 선택 후 Span 추가 (삽입 대상 컨텍스트 확보)
    await page.evaluate(() => {
      const frame = document.querySelector('[data-testid="g7le-preview-frame"]')!;
      const cont = Array.from(frame.querySelectorAll('[data-editor-path]'))
        .map((e) => ({ e, r: e.getBoundingClientRect() }))
        .filter((x) => x.r.width > 200 && x.r.height > 100)[0];
      const r = cont.e.getBoundingClientRect();
      cont.e.dispatchEvent(new MouseEvent('click', { bubbles: true, clientX: r.left + 20, clientY: r.top + 20 }));
    });
    await page.getByTestId('g7le-toolbar-add-element').click();
    await page.waitForSelector('[data-testid="g7le-palette-item-Span"]', { timeout: 10_000 });
    await page.getByTestId('g7le-palette-item-Span').click();
    await page.waitForTimeout(800);

    // 가장 작은 Span 선택
    await page.evaluate(() => {
      const frame = document.querySelector('[data-testid="g7le-preview-frame"]')!;
      const spans = Array.from(frame.querySelectorAll('[data-editor-path]'))
        .filter((e) => e.tagName === 'SPAN')
        .map((e) => ({ e, r: e.getBoundingClientRect() }))
        .filter((x) => x.r.width > 0 && x.r.height > 0)
        .sort((a, b) => a.r.width * a.r.height - b.r.width * b.r.height);
      const t = spans[0];
      const r = t.e.getBoundingClientRect();
      t.e.dispatchEvent(new MouseEvent('click', { bubbles: true, clientX: r.left + r.width / 2, clientY: r.top + r.height / 2 }));
    });
    await page.waitForTimeout(400);

    const geom = await page.evaluate(() => {
      const dirs = ['above', 'below', 'left', 'right'] as const;
      const btns = dirs.map((d) => document.querySelector(`[data-testid="g7le-insertion-${d}"]`)).filter(Boolean) as HTMLElement[];
      const rect = (el: HTMLElement) => {
        const r = el.getBoundingClientRect();
        return { x: r.x, y: r.y, w: r.width, h: r.height, cx: r.x + r.width / 2, cy: r.y + r.height / 2 };
      };
      const ov = (a: any, b: any) => !(a.x + a.w <= b.x || b.x + b.w <= a.x || a.y + a.h <= b.y || b.y + b.h <= a.y);
      const rects = btns.map(rect);
      const placements = btns.map((b) => b.getAttribute('data-placement'));
      let pairOverlap = false;
      for (let i = 0; i < rects.length; i++) for (let j = i + 1; j < rects.length; j++) if (ov(rects[i], rects[j])) pairOverlap = true;
      const sel = document.querySelector('[data-testid="g7le-overlay-selected"]') as HTMLElement | null;
      const selR = sel ? rect(sel) : null;
      const canvas = document.querySelector('[data-testid="g7le-preview-canvas"]') as HTMLElement | null;
      const cR = canvas ? rect(canvas) : null;
      const withinCanvas = cR ? rects.every((r) => r.x >= cR.x && r.x + r.w <= cR.x + cR.w) : false;
      const overlapBox = selR ? rects.some((r) => ov(r, selR)) : false;
      return {
        count: btns.length,
        placements,
        pairOverlap,
        overlapBox,
        withinCanvas,
        // 십자 대칭: above/below cx 동일, left/right cy 동일
        aboveBelowSameCx: Math.round(rects[0].cx) === Math.round(rects[1].cx),
        leftRightSameCy: Math.round(rects[2].cy) === Math.round(rects[3].cy),
      };
    });

    expect(geom.count).toBe(4);
    expect(geom.placements.every((p) => p === 'outside')).toBe(true);
    expect(geom.pairOverlap).toBe(false);
    expect(geom.overlapBox).toBe(false);
    expect(geom.withinCanvas).toBe(true);
    expect(geom.aboveBelowSameCx).toBe(true);
    expect(geom.leftRightSameCy).toBe(true);
  });

  test('회귀 — 모바일 프리뷰의 닫힌 메뉴 드로어가 frame 밖으로 노출되지 않는다', async ({ page }) => {
    // 닫힌 모바일 드로어(mobile_nav_drawer)는 `position: fixed` + `translate-x-full`
    // 로 화면 밖에 밀려 있어야 한다. frame 이 자체 transform 을 잃으면 fixed 자손의
    // containing block 이 상위 래퍼로 올라가 frame 의 overflow:hidden 클리핑이
    // 무력화되고, 드로어가 편집기 우측에 펼쳐진 채 노출된다.
    await enterEditor(page);

    // 모바일 디바이스 + 줌 100% (시각 좌표 왜곡 없는 상태로 측정)
    await page.getByTestId('g7le-device-mobile').click();
    await page.waitForTimeout(500);

    const result = await page.evaluate(() => {
      const frame = document.querySelector('[data-testid="g7le-preview-frame"]') as HTMLElement | null;
      if (!frame) return { found: false, frameTransform: '', overflowOk: false, clippedOk: false };

      const cs = getComputedStyle(frame);
      const frameRect = frame.getBoundingClientRect();

      // 닫힌 드로어 후보: position:fixed + translate-x-full 클래스를 가진 자손
      const drawers = Array.from(frame.querySelectorAll<HTMLElement>('*')).filter((el) => {
        const s = getComputedStyle(el);
        return s.position === 'fixed' && (el.className || '').includes('translate-x-full');
      });

      // 드로어 후보 각각이 frame 우측 경계 밖(translate-x-full 로 밀려나 클리핑됨)인지.
      // 클리핑이 동작하면 드로어의 left 가 frame 우측 경계 이상(완전히 밖) 이어야 한다.
      const clippedOk = drawers.every((d) => {
        const r = d.getBoundingClientRect();
        return r.left >= frameRect.right - 1; // 부동소수 여유 1px
      });

      return {
        found: true,
        drawerCount: drawers.length,
        // frame 자체가 transform 을 보유해야 fixed 자손의 containing block 이 됨
        frameTransform: cs.transform,
        overflowOk: cs.overflow === 'hidden' || cs.overflowX === 'hidden',
        clippedOk,
      };
    });

    expect(result.found).toBe(true);
    // frame 은 자체 transform(identity 포함, 'none' 아님)을 보유해야 한다
    expect(result.frameTransform).not.toBe('none');
    expect(result.frameTransform).toBeTruthy();
    expect(result.overflowOk).toBe(true);
    // 닫힌 드로어가 존재한다면 모두 frame 우측 밖으로 밀려(클리핑) 노출되지 않아야 한다
    expect(result.clippedOk).toBe(true);

    // 가려진 드로어 내부 노드 자리에 dnd 핸들/점선 오버레이가 frame 밖에 그려지지
    // 않아야 한다 — overflow:hidden 은 시각만 가릴 뿐 getBoundingClientRect 좌표는
    // 그대로라, 오버레이 레이어(overflow:visible)가 가려진 노드 자리에 핸들/점선을
    // 노출시키던 회귀.
    const overlayLeak = await page.evaluate(() => {
      const frame = document.querySelector('[data-testid="g7le-preview-frame"]') as HTMLElement;
      const fr = frame.getBoundingClientRect();
      const overlay = document.querySelector('[data-testid="g7le-overlay-layer"]') as HTMLElement | null;
      if (!overlay) return { handlesRightOfFrame: 0, dashedRightOfFrame: 0 };

      // 1) dnd 핸들이 frame 우측 밖에 그려졌는가
      const handles = Array.from(overlay.querySelectorAll<HTMLElement>('[data-testid^="g7le-dnd-handle-"]'));
      const handlesRightOfFrame = handles.filter((h) => {
        const r = h.getBoundingClientRect();
        return r.width > 0 && r.height > 0 && r.left >= fr.right - 1;
      }).length;

      // 2) 점선 테두리 박스(이터레이션 묶음/슬롯 등)가 frame 우측 밖에 그려졌는가
      const dashedRightOfFrame = Array.from(overlay.querySelectorAll<HTMLElement>('*')).filter((el) => {
        const cs = getComputedStyle(el);
        if (!cs.borderStyle.includes('dashed')) return false;
        const r = el.getBoundingClientRect();
        return r.width > 4 && r.height > 4 && r.left >= fr.right - 1;
      }).length;

      return { handlesRightOfFrame, dashedRightOfFrame };
    });

    expect(overlayLeak.handlesRightOfFrame).toBe(0);
    expect(overlayLeak.dashedRightOfFrame).toBe(0);
  });
});
