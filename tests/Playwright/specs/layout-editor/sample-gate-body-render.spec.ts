/**
 * Layout Editor — 게이트 본체(_computed / 지연 데이터소스)가 편집기 캔버스에 노출되는지 통합 가드
 *
 *
 * 배경 — 본 브랜치에서 수정한 편집기 전용 엔진 결함 2건의 브라우저 통합 회귀 가드:
 *  1. `_computed.*` 게이트 본체 미렌더 — 편집기 PreviewCanvas 가 레이아웃 `computed` 블록을
 *     DynamicRenderer dataContext(`_computedDefinitions`)에 주입하지 않아, `if: {{_computed.xxx}}`
 *     로 게이트된 본체(배송정책 수정 폼의 국가별 설정 패널)가 캔버스에서 통째로 비어 렌더됐다.
 *     데이터·`_local` 시드는 정상인데 화면만 공백. 런타임 앱(TemplateApp)은 정상이고 편집기
 *     경로에만 누락 → PreviewCanvas 가 `document.raw.computed` 를 주입하도록 수정.
 *  2. 지연 데이터소스(`auto_fetch:false`) 게이트 본체 미렌더 — 탭 활성 시에만 런타임이 fetch
 *     하는 지연 소스(마이페이지 게시판 "내 댓글" 서브탭 `myComments`)는 정적 시뮬레이션인
 *     편집기에서 활성화 액션을 못 실행해, 페이지 상태로 게이트를 열어도 데이터가 비어 본체가
 *     미렌더됐다. DataSourceManager 가 샘플 모드(편집기)에서만 지연 소스도 샘플 주입 대상에
 *     포함하도록 수정(일반 렌더 모드는 기존 auto_fetch 필터 100% 보존).
 *
 * Vitest 는 JSON+격리 렌더로 시뮬레이터 경로를 잠그고, 본 spec 은 실제 편집기 chrome +
 * 캔버스 통합 렌더에서 게이트 본체가 실제로 보이는지(브라우저 가시 결과)를 측정한다.
 *
 * @scenario gate_body_kind:computed_if_gate + gate_body_kind:lazy_datasource_subtab
 * @effects shipping_policy_edit_country_setting_panel_renders_via_computed_gate
 * @effects mypage_board_my_comments_subtab_renders_via_lazy_datasource_sample_injection
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

test.describe('@layout-editor 게이트 본체 캔버스 노출', () => {
  // (1) _computed 게이트 — 배송정책 수정 폼의 국가별 설정 본체는 `if: {{_computed.activeCountrySetting}}`
  // 게이트 뒤에 있다. PreviewCanvas 가 computed 블록을 미주입하면 _computed.* 가 항상 undefined →
  // 국가별 설정 패널이 통째로 비어 렌더된다. 부과정책 상태로 게이트를 열어 본체 렌더를 측정.
  test('배송정책 수정 → _computed 게이트 국가별 설정 본체가 캔버스에 렌더된다', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-ecommerce');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    // 배송정책 수정 라우트 노드 선택 (admin 프리픽스 → 트리 클릭)
    await page.waitForSelector('[data-route-path="*/admin/ecommerce/shipping-policies/:id/edit"]', {
      timeout: 30_000,
    });
    await page
      .locator('[data-route-path="*/admin/ecommerce/shipping-policies/:id/edit"]')
      .first()
      .click();
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });

    const frame = page.getByTestId('g7le-preview-frame');

    // _computed.activeCountrySetting 게이트 본체가 살아있으면 국가별 설정 본체(배송방법/배송비 등)가
    // 렌더된다. 회귀(컴퓨티드 미주입)였다면 _computed 게이트 본체 전멸로 공백.
    await expect
      .poll(async () => (await frame.innerText()).includes('원') || (await frame.innerText()).length > 1500, {
        message: '배송정책 수정 캔버스에 국가별 설정 본체(배송비 등)가 렌더되어야 함 — _computed 게이트 통과 신호',
        timeout: 20_000,
      })
      .toBe(true);
  });

  // (2) 지연 데이터소스 게이트 — 마이페이지 게시판 활동의 "내 댓글" 서브탭 본체는 지연 소스
  // myComments(auto_fetch:false) 에 바인딩된다. 샘플 모드 지연 소스 주입이 없으면 서브탭 게이트를
  // 페이지 상태로 열어도 데이터가 비어 본체가 미렌더된다. my_comments 상태 전환 후 본체 렌더 측정.
  test('마이페이지 게시판 → my_comments 서브탭 지연 소스 본체가 캔버스에 렌더된다', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-basic?route=%2Fmypage%2Fboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });

    const select = page.getByTestId('g7le-state-switcher-select');
    await expect(select).toBeVisible({ timeout: 15_000 });

    const frame = page.getByTestId('g7le-preview-frame');
    const baselineLen = (await frame.innerText()).length;

    // my_comments 서브탭 상태로 전환 → 지연 소스 myComments 샘플이 주입되어 내 댓글 목록 본체가 렌더.
    await select.selectOption('my_comments');
    await expect(page.getByTestId('g7le-state-switcher')).toHaveAttribute(
      'data-active-state',
      'my_comments',
      { timeout: 5_000 },
    );

    // 본체 렌더 신호 — 내 댓글 목록이 채워지면 캔버스 텍스트 길이가 baseline 대비 늘어난다.
    // 회귀(지연 소스 미주입)였다면 게이트 본체 공백으로 길이 변화 없음.
    await expect
      .poll(async () => (await frame.innerText()).length, {
        message: 'my_comments 서브탭 본체(내 댓글 목록)가 렌더되어 캔버스 텍스트가 늘어나야 함',
        timeout: 20_000,
      })
      .toBeGreaterThan(baselineLen);
  });
});
