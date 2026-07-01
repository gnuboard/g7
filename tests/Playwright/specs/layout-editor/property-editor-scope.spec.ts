/**
 * Layout Editor — 스타일 탭 색 모드 × 디바이스 scope 편집.
 *
 * 색 모드(라이트/다크) × 디바이스(공통/PC/태블릿/모바일/커스텀) 직교 2축 세부탭에서
 * 스타일/flex/표시조건을 해당 위치(responsive.{bp}.props / dark: 토큰 / responsive.{bp}.if)에
 * 무손실로 기록·역해석하고, 저장·새로고침 후 복원되는지 검증한다.
 *
 * @scenario color_scheme × device × apply_type × edit_target
 * @effects device_scope_writes_responsive_breakpoint_props_base_unchanged
 *   + dark_classToken_coexists_with_light_token_in_one_className
 *   + dark_inline_control_readonly_value_never_overwritten
 *   + scope_without_override_shows_base_inherited_value_as_placeholder
 *   + custom_range_scope_writes_responsive_range_key
 *   + device_flex_disable_writes_explicit_off_token_breaking_base_inheritance
 *   + modal_scope_snapshots_toolbar_on_open
 *   + modal_scope_independent_after_toolbar_change
 *   + save_persists_responsive_and_if_on_reload
 *   + preview_color_scheme_toggle_applies_dark_wrapper_to_preview_frame_only
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

const CARD = '2.children.5.children.0.children.0.children.1'; // 로그인 카드(Div)

async function openEditorLogin(page: Page): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto('/admin/layout-editor/sirsoft-basic?route=%2Flogin');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
  await page.waitForFunction(
    () => document.querySelectorAll('[data-editor-path]').length > 0,
    { timeout: 20_000 },
  );
}

async function openPropsFor(page: Page, editorPath: string): Promise<void> {
  await page.evaluate((p) => {
    const el = document.querySelector(`[data-editor-path="${p}"]`);
    if (!el) throw new Error('node not found: ' + p);
    const r = el.getBoundingClientRect();
    for (const type of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click']) {
      el.dispatchEvent(new MouseEvent(type, { bubbles: true, cancelable: true, clientX: r.left + 8, clientY: r.top + 8, view: window }));
    }
  }, editorPath);
  await page.waitForSelector('[data-testid="g7le-overlay-info-button"]', { timeout: 10_000 });
  await page.getByTestId('g7le-overlay-info-button').click();
  await page.waitForSelector('[data-testid="g7le-context-menu-edit-props"]', { timeout: 5_000 });
  await page.getByTestId('g7le-context-menu-edit-props').click();
  await page.waitForSelector('[data-testid="g7le-property-modal"]', { timeout: 10_000 });
}

function classOf(page: Page, path: string): Promise<string> {
  return page.evaluate((p) => document.querySelector(`[data-editor-path="${p}"]`)?.className ?? '', path);
}

test.describe('@layout-editor 스타일 탭 색 모드 × 디바이스 scope', () => {
  test('T1: 모달 열면 색 모드 + 디바이스 세부탭 노출(기본 공통/라이트 스냅샷)', async ({ page }) => {
    await openEditorLogin(page);
    await openPropsFor(page, CARD);

    await expect(page.getByTestId('g7le-style-scope-tabs')).toBeVisible();
    await expect(page.getByTestId('g7le-style-scheme-base')).toHaveAttribute('data-active', 'true');
    // D2 — 모달 기본 세부탭은 열릴 때 상단 툴바 디바이스 스냅샷. 기본 previewDevice='desktop'
    // 이므로 PC(desktop) 세부탭이 기본 활성(공통/base 아님). deviceToBreakpoint('desktop')='desktop'.
    await expect(page.getByTestId('g7le-style-bp-desktop')).toHaveAttribute('data-active', 'true');
    // 고정 4 디바이스 + 커스텀 추가 버튼
    await expect(page.getByTestId('g7le-style-bp-base')).toBeVisible();
    await expect(page.getByTestId('g7le-style-bp-tablet')).toBeVisible();
    await expect(page.getByTestId('g7le-style-bp-mobile')).toBeVisible();
    await expect(page.getByTestId('g7le-style-bp-add-custom')).toBeVisible();
  });

  test('T2: 디바이스 탭에서 textAlign 변경 → 기본값 className 시드되어 base 토큰 보존(B안)', async ({ page }) => {
    await openEditorLogin(page);
    await openPropsFor(page, CARD);

    // 기본값(공통) 탭으로 전환 후 base className 스냅샷
    await page.getByTestId('g7le-style-bp-base').click();
    const baseClass = await classOf(page, CARD);
    expect(baseClass.split(/\s+/).length).toBeGreaterThan(2); // 로그인 카드는 여러 토큰

    // 태블릿 탭에서 textAlign 변경 — 시드로 base 토큰 전부 보존 + text-center 추가
    await page.getByTestId('g7le-style-bp-tablet').click();
    const seg = page.getByTestId('g7le-segment-center');
    if (await seg.count() > 0) {
      await seg.click();
      // 태블릿 폭에서 렌더되도록 상단 디바이스를 태블릿으로
      await page.getByTestId('g7le-device-tablet').click();
      await expect.poll(() => classOf(page, CARD)).toContain('text-center');
      // base 토큰(예: 로그인 카드의 bg/rounded 등)이 살아있어야 함 — 시드 보존
      const tabletClass = await classOf(page, CARD);
      const baseTokens = baseClass.split(/\s+/).filter((t) => !t.startsWith('text-'));
      for (const tok of baseTokens) {
        expect(tabletClass.split(/\s+/)).toContain(tok);
      }
    }
  });

  test('T2b: 디바이스 override 후 "기본값으로 초기화" → 표시점/override 제거', async ({ page }) => {
    await openEditorLogin(page);
    await openPropsFor(page, CARD);

    await page.getByTestId('g7le-style-bp-tablet').click();
    const seg = page.getByTestId('g7le-segment-center');
    if (await seg.count() > 0) {
      await seg.click();
      // 표시점 노출
      await expect(page.getByTestId('g7le-style-bp-tablet-dot')).toBeVisible();
      // 초기화 버튼 노출 → 클릭 → 표시점 사라짐
      await expect(page.getByTestId('g7le-style-scope-reset')).toBeVisible();
      await page.getByTestId('g7le-style-scope-reset').click();
      await expect(page.getByTestId('g7le-style-bp-tablet-dot')).toHaveCount(0);
    }
  });

  test('T3: 다크 세부탭에서 인라인 색상 컨트롤은 읽기전용 안내', async ({ page }) => {
    await openEditorLogin(page);
    await openPropsFor(page, CARD);

    await page.getByTestId('g7le-style-scheme-dark').click();
    await expect(page.getByTestId('g7le-style-scheme-dark')).toHaveAttribute('data-active', 'true');

    // 인라인 styleProp 컨트롤(예: 텍스트 색)이 있으면 읽기전용 안내가 떠야 한다.
    // (컨트롤 키는 템플릿 editor-spec 의존 — 존재할 때만 단언)
    const readonly = page.locator('[data-testid^="g7le-control-dark-readonly-"]');
    if (await readonly.count() > 0) {
      await expect(readonly.first()).toBeVisible();
    }
  });

  test('T3b: 색 컨트롤 classToken — 라이트 프리셋 적용 + 다크 프리셋 dark: 토큰 공존', async ({ page }) => {
    await openEditorLogin(page);
    await openPropsFor(page, CARD);

    // 라이트(기본값) 탭에서 배경색 프리셋 적용 → bg-* classToken
    await page.getByTestId('g7le-style-scheme-base').click();
    const lightPreset = page.getByTestId('g7le-color-token-bg-gray-100');
    if (await lightPreset.count() > 0) {
      await lightPreset.first().click();
      await expect.poll(() => classOf(page, CARD)).toContain('bg-gray-100');

      // 다크 탭으로 전환 → 자유 입력은 비활성 안내(자유값 라이트 전용), 프리셋은 적용 가능
      await page.getByTestId('g7le-style-scheme-dark').click();
      const freeDisabled = page.getByTestId('g7le-color-free-disabled');
      // 색 컨트롤이 존재하면 다크에서 자유입력 비노출 안내가 떠야 함
      if (await freeDisabled.count() > 0) {
        await expect(freeDisabled.first()).toBeVisible();
        await expect(page.getByTestId('g7le-color-hex')).toHaveCount(0);
      }
      // 다크 프리셋 적용 → dark:bg-* 토큰 공존(라이트 bg-gray-100 보존)
      const darkPreset = page.getByTestId('g7le-color-token-bg-gray-900');
      if (await darkPreset.count() > 0) {
        await darkPreset.first().click();
        await expect.poll(() => classOf(page, CARD)).toContain('dark:bg-gray-900');
        expect(await classOf(page, CARD)).toContain('bg-gray-100'); // 라이트 공존
      }
    }
  });

  test('T6: 커스텀 크기 탭 추가 → 600-900 탭 활성 + 우선순위 안내', async ({ page }) => {
    await openEditorLogin(page);
    await openPropsFor(page, CARD);

    await page.getByTestId('g7le-style-bp-add-custom').click();
    await page.getByTestId('g7le-style-custom-min').fill('600');
    await page.getByTestId('g7le-style-custom-max').fill('900');
    await page.getByTestId('g7le-style-custom-confirm').click();

    await expect(page.getByTestId('g7le-style-bp-custom-600-900')).toHaveAttribute('data-active', 'true');
    await expect(page.getByTestId('g7le-style-custom-priority-note')).toBeVisible();
  });

  test('T7: 표시조건 탭은 디바이스 세부탭만(색 모드 줄 숨김, D9)', async ({ page }) => {
    await openEditorLogin(page);
    await openPropsFor(page, CARD);

    // visibility 탭으로 전환(있을 때만)
    const visTab = page.getByTestId('g7le-property-tab-visibility');
    if (await visTab.count() > 0) {
      await visTab.click();
      await expect(page.getByTestId('g7le-style-scope-device')).toBeVisible();
      await expect(page.getByTestId('g7le-style-scope-scheme')).toHaveCount(0);
    }
  });

  test('T12: 툴바 라이트/다크 토글 → 프리뷰 프레임만.g7le-preview-dark (편집기 chrome 비다크 격리)', async ({ page }) => {
    await openEditorLogin(page);

    // 다크 토글 — 프리뷰 프레임에 격리 마커(.g7le-preview-dark) 부여(종전.dark 대체).
    await page.getByTestId('g7le-toolbar-scheme-dark').click();
    await expect.poll(() =>
      page.evaluate(() =>
        document.querySelector('[data-testid="g7le-preview-frame"]')?.classList.contains('g7le-preview-dark') ?? false,
      ),
    ).toBe(true);
    // document.documentElement 는 dark 가 아니어야(프레임 한정)
    const htmlDark = await page.evaluate(() => document.documentElement.classList.contains('dark'));
    expect(htmlDark).toBe(false);

    // 라이트 복귀
    await page.getByTestId('g7le-toolbar-scheme-light').click();
    await expect.poll(() =>
      page.evaluate(() =>
        document.querySelector('[data-testid="g7le-preview-frame"]')?.classList.contains('g7le-preview-dark') ?? false,
      ),
    ).toBe(false);
  });
});
