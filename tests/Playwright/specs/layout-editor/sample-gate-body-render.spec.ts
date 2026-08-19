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
 * 축 요약(마커 아님 — 평문): gate_body_kind:computed_if_gate, gate_body_kind:lazy_datasource_subtab.
 * 효과 요약(마커 아님 — 평문): shipping_policy_edit_country_setting_panel_renders_via_computed_gate.
 * 효과 요약(마커 아님 — 평문): mypage_board_my_comments_subtab_renders_via_lazy_datasource_sample_injection.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

test.describe('@layout-editor 게이트 본체 캔버스 노출', () => {
  // (1) _computed 게이트 — 배송정책 수정 폼의 국가별 설정 본체는 `if: {{_computed.activeCountrySetting}}`
  // 게이트 뒤에 있다. PreviewCanvas 가 computed 블록을 미주입하면 _computed.* 가 항상 undefined →
  // 국가별 설정 패널이 통째로 비어 렌더된다. 부과정책 상태로 게이트를 열어 본체 렌더를 측정.
  /** @effects shipping_policy_edit_country_setting_panel_renders_via_computed_gate */
  test('배송정책 수정 → _computed 게이트 국가별 설정 본체가 캔버스에 렌더된다', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    // URL 의 마지막 세그먼트는 **템플릿 식별자** 자리다(편집기 진입점 `_tab_admin.json` 의
    // `/admin/layout-editor/{{row.identifier}}` — 템플릿 목록 행). `sirsoft-ecommerce` 는 모듈이라
    // 템플릿으로 존재하지 않아 라우트 트리가 비고, 아래 `data-route-path` 가 영원히 나타나지 않았다.
    // 모듈 admin 라우트는 admin 템플릿 트리에 병합되므로 admin 템플릿으로 진입한다.
    await page.goto('/admin/layout-editor/sirsoft-admin_basic');
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
  /** @effects mypage_board_my_comments_subtab_renders_via_lazy_datasource_sample_injection */
  test('마이페이지 게시판 → my_comments 서브탭 지연 소스 본체가 캔버스에 렌더된다', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-basic?route=%2Fmypage%2Fboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });

    const select = page.getByTestId('g7le-state-switcher-select');
    await expect(select).toBeVisible({ timeout: 15_000 });

    const frame = page.getByTestId('g7le-preview-frame');

    // my_comments 서브탭 상태로 전환 → 지연 소스 myComments 샘플이 주입되어 내 댓글 목록 본체가 렌더.
    await select.selectOption('my_comments');
    await expect(page.getByTestId('g7le-state-switcher')).toHaveAttribute(
      'data-active-state',
      'my_comments',
      { timeout: 5_000 },
    );

    // 본체 렌더 판정은 **내용**으로 한다.
    //
    // 이전에는 "캔버스 텍스트 길이가 baseline 대비 늘어난다" 를 신호로 썼는데 그 전제가 틀렸다 —
    // 실측(2026-07-30): 본체가 정상 렌더돼도 길이는 881 → 669 로 **줄어든다**. 내 댓글 항목이
    // 내 글 항목보다 짧아 서브탭을 바꾸면 총 길이가 감소한다. 길이 증감은 본체 렌더 여부와
    // 무관한 지표이므로, 샘플 myComments 데이터의 고유 문구가 캔버스에 나타나는지로 판정한다
    // (회귀 = 지연 소스 미주입이면 이 문구들이 하나도 렌더되지 않는다).
    await expect
      .poll(async () => (await frame.innerText()).includes('NoInfer'), {
        message: 'my_comments 서브탭 본체(내 댓글 목록)에 지연 소스 샘플 항목이 렌더되어야 함',
        timeout: 20_000,
      })
      .toBe(true);

    // 단일 문구 우연 일치를 배제 — 샘플 항목 다수가 함께 렌더되어야 목록 본체가 살아 있는 것이다.
    const rendered = await frame.innerText();
    expect(rendered).toContain('모니터 암');
    expect(rendered).toContain('재택근무 환경 셋업');
  });
});
