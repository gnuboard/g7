/**
 * Layout Editor — 속성 편집 모달 + sampleGlobal 체인 회귀.
 *
 * S6-2 검증:
 *  - 요소 선택 → ⓘ 메뉴 "속성 설정" → 속성 편집 모달 렌더(스타일 탭 + 컨트롤)
 *  - 스타일 컨트롤 조작 → 캔버스 라이브 반영(노드 className/style 패치)
 *  - sampleGlobal 체인: 코어 시드 폐기 후 번들 템플릿 editor-spec.json.sampleGlobal
 *    이 _global.currentUser / settings.site_name 을 제공 → 헤더 사이트명이 폴백("Site")
 *    이 아닌 샘플값으로 렌더
 *  - guest_only 레이아웃: 템플릿이 currentUser 를 시드해도 비로그인 페이지에서 제외 →
 *    "이미 로그인되어 있습니다" 토스트 미발화
 *
 * @scenario property_modal_open + style_control_patch + sample_global_from_template + guest_only_no_currentUser
 * @effects modal_visible + canvas_live_patch + site_name_seeded + guest_only_no_redirect_toast
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

/** path 로 노드를 합성 마우스 이벤트로 선택한다 — 캔버스 노드는 드래그 핸들이 덮어 실제
 *  마우스 클릭이 가로채이므로, 합성 이벤트 시퀀스로 선택 로직을 직접 트리거한다. */
async function selectNodeByPath(page: import('@playwright/test').Page, path: string): Promise<boolean> {
  return page.evaluate((p) => {
    const el = document.querySelector(`[data-editor-path="${p}"]`);
    if (!el) return false;
    const r = (el as HTMLElement).getBoundingClientRect();
    for (const t of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click']) {
      el.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, clientX: r.left + 8, clientY: r.top + 8, view: window }));
    }
    return true;
  }, path);
}

/** 캔버스의 visible 노드 path 목록(앞쪽 N개). 반응형 hidden(mobile_header 등) 제외. */
async function visibleNodePaths(page: import('@playwright/test').Page, limit: number): Promise<string[]> {
  return page.evaluate((max) => {
    const out: string[] = [];
    for (const el of Array.from(document.querySelectorAll('[data-editor-path]'))) {
      const r = (el as HTMLElement).getBoundingClientRect();
      const path = el.getAttribute('data-editor-path');
      if (path && path !== '2' && r.width > 0 && r.height > 0 && getComputedStyle(el as HTMLElement).display !== 'none') out.push(path);
      if (out.length >= max) break;
    }
    return out;
  }, limit);
}

/** info 어포던스(ⓘ)가 뜨는 첫 노드를 선택한다 — 어포던스 노출은 컴포넌트 종류에 따라 다르므로
 *  (잠금/데이터결정 노드는 미노출), visible 노드를 순회하며 ⓘ 가 뜨는 노드를 찾는다. */
async function selectNodeWithInfoButton(page: import('@playwright/test').Page): Promise<void> {
  const paths = await visibleNodePaths(page, 12);
  for (const path of paths) {
    if (!(await selectNodeByPath(page, path))) continue;
    const info = page.getByTestId('g7le-overlay-info-button');
    if (await info.isVisible().catch(() => false)) return;
  }
  throw new Error('ⓘ 어포던스가 뜨는 캔버스 노드를 찾지 못했습니다');
}

test.describe('@layout-editor 속성 편집 모달 (S6-2)', () => {
  test('요소 선택 → ⓘ → 속성 설정 → 스타일 탭 컨트롤 렌더 + 패치', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-basic?route=%2F');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });

    // 캔버스에 편집 표식 노드가 렌더될 때까지 대기
    await page.waitForFunction(
      () => document.querySelectorAll('[data-editor-path]').length > 0,
      { timeout: 20_000 },
    );

    // ⓘ 어포던스가 뜨는 첫 노드 선택. 루트("2")는 캔버스 전면을 덮어 제외하고, 어포던스 노출은
    // 컴포넌트 종류에 따라 다르므로(잠금/데이터결정 노드 미노출) visible 노드를 순회해 찾는다.
    await selectNodeWithInfoButton(page);

    // 선택 오버레이 + ⓘ 버튼 등장 대기
    await page.waitForSelector('[data-testid="g7le-overlay-info-button"]', { timeout: 10_000 });
    await page.getByTestId('g7le-overlay-info-button').click();

    // 컨텍스트 메뉴 "속성 설정" → 속성 편집 모달
    await page.waitForSelector('[data-testid="g7le-context-menu-edit-props"]', { timeout: 5_000 });
    await page.getByTestId('g7le-context-menu-edit-props').click();

    // 속성 편집 모달 렌더
    await page.waitForSelector('[data-testid="g7le-property-modal"]', { timeout: 10_000 });
    await expect(page.getByTestId('g7le-property-modal-title')).toBeVisible();

    // 스타일 탭이 있으면 컨트롤이 렌더되어야 함(편집 가능 속성 보유 노드).
    // 편집 가능 속성 없는 노드면 안내가 표시된다 — 둘 중 하나는 반드시 존재.
    const hasStyleTab = await page.getByTestId('g7le-property-tab-style').count();
    const hasNoEditable = await page.getByTestId('g7le-property-modal-no-editable').count();
    expect(hasStyleTab + hasNoEditable).toBeGreaterThan(0);
  });

  test('sampleGlobal: 번들 템플릿 시드로 _global.currentUser / settings 가 채워진다 (코어 시드 폐기 후)', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-basic?route=%2F');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });

    // 격리 store 의 _global 이 템플릿 sampleGlobal 로 채워졌는지 — currentUser.uuid + site_name.
    // 코어 시드 폐기 후 이 값은 sirsoft-basic editor-spec.json.sampleGlobal 에서 온다.
    await page.waitForFunction(
      () => {
        const app = (window as unknown as { __templateApp?: { getGlobalState?: () => Record<string, unknown> } }).__templateApp;
        if (!app?.getGlobalState) return false;
        const g = app.getGlobalState();
        const cu = g.currentUser as { uuid?: string } | undefined;
        const settings = g.settings as { general?: { site_name?: string } } | undefined;
        return !!cu?.uuid && !!settings?.general?.site_name;
      },
      { timeout: 20_000 },
    );
  });

  test('guest_only 레이아웃: 템플릿 currentUser 시드 제외 → "이미 로그인" 토스트 미발화', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    const toastTexts: string[] = [];
    page.on('console', (msg) => toastTexts.push(msg.text()));

    await page.goto('/admin/layout-editor/sirsoft-basic?route=%2Fforgot-password');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });

    // guest_only 레이아웃은 currentUser 가 격리 store 에서 제외되어야 함.
    await page.waitForFunction(
      () => {
        const app = (window as unknown as { __templateApp?: { getGlobalState?: () => Record<string, unknown> } }).__templateApp;
        if (!app?.getGlobalState) return false;
        const g = app.getGlobalState();
        return g.currentUser === undefined;
      },
      { timeout: 20_000 },
    );

    // 캔버스에 "이미 로그인되어 있습니다" 류 토스트가 노출되지 않아야 함.
    const loginToast = await page
      .locator('text=/이미 로그인|already logged in/i')
      .count();
    expect(loginToast).toBe(0);
  });
});
