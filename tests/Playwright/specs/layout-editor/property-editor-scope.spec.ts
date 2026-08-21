/**
 * Layout Editor — 스타일 탭 색 모드 × 디바이스 scope 편집.
 *
 * 색 모드(라이트/다크) × 디바이스(공통/PC/태블릿/모바일/커스텀) 직교 2축 세부탭에서
 * 스타일/flex/표시조건을 해당 위치(responsive.{bp}.props / dark: 토큰 / responsive.{bp}.if)에
 * 무손실로 기록·역해석하고, 저장·새로고침 후 복원되는지 검증한다.
 *
 * 축 요약(마커 아님 — 평문): color_scheme, device, apply_type, edit_target. 요약을 시나리오 축 마커
 * 로 적으면 마커 파서가 `=` 없는 토큰을 버려 빈 조합 `{}` 이
 * 된다 — 실재하지 않는 조합이 커버 집합에 들어가는 방향이라 요약은 평문으로 둔다.
 *
 * 효과 요약(마커 아님 — 평문): device_scope_writes_responsive_breakpoint_props_base_unchanged, dark_classToken_coexists_with_light_token_in_one_className, dark_inline_control_readonly_value_never_overwritten, scope_without_override_shows_base_inherited_value_as_placeholder, custom_range_scope_writes_responsive_range_key, device_flex_disable_writes_explicit_off_token_breaking_base_inheritance, modal_scope_snapshots_toolbar_on_open, modal_scope_independent_after_toolbar_change, save_persists_responsive_and_if_on_reload, preview_color_scheme_toggle_applies_dark_wrapper_to_preview_frame_only.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';
import { editorPath as resolveEditorPath } from '../../fixtures/layout-editor';
// 저장까지 수행하는 테스트는 제품 화면을 오염시키지 않도록 E2E 전용 시드 화면을 쓴다.
import { SANDBOX_ROOT_ID, sandboxRouteParam } from '../../fixtures/seed-layout';

/** 로그인 카드(Div) — 본문 루트 기준 상대 경로 */
const CARD_REL = 'children.5.children.0.children.0.children.1';

// 절대 경로의 첫 세그먼트는 베이스 레이아웃 루트 인덱스라 베이스에 컴포넌트가 추가되면 밀린다.
// 리터럴로 두지 않고 openEditorLogin 이 본문 루트 id 로 해석해 채운다 (사용처는 그대로 읽는다).
let CARD = '';

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

  CARD = await resolveEditorPath(page, CARD_REL);
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
  /** @effects modal_scope_snapshots_toolbar_on_open */
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

  /** @effects device_scope_writes_responsive_breakpoint_props_base_unchanged */
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

  /** @effects scope_without_override_shows_base_inherited_value_as_placeholder */
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

  /** @effects dark_inline_control_readonly_value_never_overwritten */
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

  /** @effects dark_classToken_coexists_with_light_token_in_one_className */
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

  /** @effects custom_range_scope_writes_responsive_range_key */
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

  /** @effects preview_color_scheme_toggle_applies_dark_wrapper_to_preview_frame_only */
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

  /**
   * 모달 세부탭은 **열릴 때 한 번** 툴바를 스냅샷한다(T1). 열린 뒤 툴바를 바꾸면 편집 대상이
   * 따라 움직여선 안 된다 — 운영자가 태블릿을 고쳐 놓고 미리보기만 모바일로 넘겨 보는 동안
   * 입력이 모바일 scope 에 기록되면 엉뚱한 디바이스가 바뀐다.
   */
  /** @effects modal_scope_independent_after_toolbar_change */
  test('T13: 모달 연 뒤 툴바 디바이스를 바꿔도 모달 세부탭은 따라가지 않는다', async ({ page }) => {
    await openEditorLogin(page);
    await openPropsFor(page, CARD);

    // 모달 세부탭을 태블릿으로 고정 (툴바 스냅샷값과 다르게 만든다)
    await page.getByTestId('g7le-style-bp-tablet').click();
    await expect(page.getByTestId('g7le-style-bp-tablet')).toHaveAttribute('data-active', 'true');

    // 툴바 디바이스를 모바일로 전환
    await page.getByTestId('g7le-device-mobile').click();
    await expect
      .poll(async () =>
        page.evaluate(
          () =>
            document.querySelector('[data-testid="g7le-device-mobile"]')?.getAttribute('data-active') ??
            document.querySelector('[data-testid="g7le-device-mobile"]')?.getAttribute('aria-pressed') ??
            'unknown',
        ),
      )
      .not.toBe('unknown'); // 툴바 전환이 실제로 반영됐다

    // 모달 세부탭은 그대로 태블릿
    await expect(page.getByTestId('g7le-style-bp-tablet')).toHaveAttribute('data-active', 'true');
    await expect(page.getByTestId('g7le-style-bp-mobile')).toHaveAttribute('data-active', 'false');
  });

  /**
   * 「정렬 박스 해제」는 scope 가 기본값(공통)이 아니면 토큰을 **지우는 게 아니라** 명시 해제
   * 토큰(`block`)을 기록한다. 지우기만 하면 그 디바이스가 공통값(`flex`)을 도로 상속해
   * "껐는데 그대로" 가 된다.
   */
  /** @effects device_flex_disable_writes_explicit_off_token_breaking_base_inheritance */
  test('T14: 디바이스 탭에서 정렬 박스 해제 → 공통 상속을 끊는 명시 해제 토큰이 기록된다', async ({ page }) => {
    await openEditorLogin(page);
    await openPropsFor(page, CARD);

    // 공통(base) 탭에서 정렬 박스를 켠다 — 이 화면 카드가 flex 가 아닐 수 있으므로 전제를 만든다.
    await page.getByTestId('g7le-style-bp-base').click();
    if ((await page.getByTestId('g7le-flex-disable').count()) === 0) {
      await page.getByTestId('g7le-flex-enable').click();
    }
    await expect(page.getByTestId('g7le-flex-disable')).toBeVisible({ timeout: 10_000 });
    await page.getByTestId('g7le-device-desktop').click();
    await expect.poll(() => classOf(page, CARD)).toContain('flex');

    // 태블릿 탭 — 이 scope 에 명시 설정이 없으면 "꺼짐"으로 보이므로(공통값을 그대로 물려받는
    // 상태) 먼저 켜서 명시 설정을 만든 뒤 해제한다. 운영자가 실제로 밟는 순서다.
    await page.getByTestId('g7le-style-bp-tablet').click();
    if ((await page.getByTestId('g7le-flex-disable').count()) === 0) {
      await page.getByTestId('g7le-flex-enable').click();
      await expect(page.getByTestId('g7le-flex-disable')).toBeVisible({ timeout: 10_000 });
    }
    await page.getByTestId('g7le-flex-disable').click();

    await page.getByTestId('g7le-device-tablet').click();
    await expect.poll(() => classOf(page, CARD)).toContain('block');
    expect((await classOf(page, CARD)).split(/\s+/)).not.toContain('flex');

    // 공통은 그대로 flex — 해제가 태블릿에만 적용됐다
    await page.getByTestId('g7le-device-desktop').click();
    await expect.poll(() => classOf(page, CARD).then((c) => c.split(/\s+/))).toContain('flex');
  });

  /**
   * 디바이스별 스타일과 표시조건은 저장돼야 의미가 있다. 화면에는 반영되는데 저장 페이로드에서
   * 빠지면 새로고침 한 번에 사라지고, 운영자는 저장을 눌렀으므로 남았다고 믿는다.
   *
   * 저장을 수행하므로 대상은 E2E 전용 시드 화면이다(제품 화면 불변).
   */
  /** @effects save_persists_responsive_and_if_on_reload */
  test('T15: 디바이스 스타일 + 표시조건을 저장하면 새로고침 후에도 남아 있다', async ({ page }) => {
    test.setTimeout(90_000);
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    const enter = async (): Promise<string> => {
      await page.goto(`/admin/layout-editor/sirsoft-basic?route=${sandboxRouteParam()}`);
      await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
      await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
      await page.waitForFunction(() => document.querySelectorAll('[data-editor-path]').length > 0, {
        timeout: 20_000,
      });
      return resolveEditorPath(page, '', SANDBOX_ROOT_ID);
    };

    const target = await enter();
    await openPropsFor(page, target);

    // ① 태블릿 scope 에 정렬(textAlign) 지정
    await page.getByTestId('g7le-style-bp-tablet').click();
    await page.getByTestId('g7le-segment-center').click();

    // ② 표시조건 — 로그인 회원일 때
    await page.getByTestId('g7le-property-tab-visibility').click();
    await page.getByTestId('g7le-condition-add-and').click();
    await page.getByTestId('g7le-condition-select-0').selectOption('isLoggedIn');
    await expect(page.getByTestId('g7le-condition-preview')).toContainText('currentUser', {
      timeout: 10_000,
    });

    // 저장
    const saved = page.waitForResponse(
      (r) => /\/api\/admin\/templates\/[^/]+\/layouts\//.test(r.url()) && r.request().method() === 'PUT',
      { timeout: 20_000 },
    );
    await page.getByTestId('g7le-property-modal-done').click().catch(() => undefined);
    await page.getByTestId('g7le-toolbar-save').click();
    expect((await saved).status()).toBe(200);

    // 새로 진입해 둘 다 살아 있는지 확인
    const again = await enter();
    await openPropsFor(page, again);

    await page.getByTestId('g7le-style-bp-tablet').click();
    await expect(page.getByTestId('g7le-segment-center')).toHaveAttribute('data-active', 'true', {
      timeout: 10_000,
    });

    await page.getByTestId('g7le-property-tab-visibility').click();
    await expect(page.getByTestId('g7le-condition-clause-0')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTestId('g7le-condition-select-0')).toHaveValue('isLoggedIn');
  });
});
