/**
 * Layout Editor — 팔레트 defaultNode 공통 디자인 className 반영 회귀 가드.
 *
 * 배경:
 *  - 팔레트에서 컴포넌트를 추가하면 editor-spec.json 의 defaultNode 가 캔버스 트리에 삽입된다.
 *  - 이전에는 defaultNode 가 placeholder(inline style / dashed border) 수준이라 추가된 요소가
 *    템플릿의 실제 Tailwind 디자인과 동떨어졌다. 본 작업에서 defaultNode.props.className 을
 *    운영 레이아웃의 대표 패턴으로 채웠다.
 *  - 본 spec 은 편집기에서 Button / Input 을 추가했을 때 캔버스에 렌더된 요소가 placeholder 가
 *    아닌 공통 디자인 className 으로 렌더되는지 검증한다. (Vitest 는 JSON+격리 렌더, 본 spec 은
 *    실제 편집기 chrome + 캔버스 통합 렌더 — feedback_wysiwyg_preview_user_page_parity)
 *
 * 두 번들 템플릿 모두 검증 (T8 양쪽 변종):
 *  - sirsoft-basic: Button → rounded-lg, Input → border 패턴
 *  - sirsoft-admin_basic: Button → bg-blue-600, Input → "input" 유틸 클래스
 *
 * @scenario palette_open + default_node_source:editorSpecDefaultNode
 * @effects palette_default_node_className_replicated_into_inserted_node
 * @effects palette_default_node_className_reflects_template_design_system_tokens
 * @effects palette_default_node_button_omits_variant_size_uses_className_only
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

type Probe = { template: string; component: string; token: string };

const probes: Probe[] = [
  { template: 'sirsoft-basic', component: 'Button', token: 'rounded-lg' },
  { template: 'sirsoft-basic', component: 'Input', token: 'border' },
  { template: 'sirsoft-admin_basic', component: 'Button', token: 'bg-blue-600' },
  { template: 'sirsoft-admin_basic', component: 'Input', token: 'input' },
];

test.describe('@layout-editor defaultNode 공통 디자인 반영', () => {
  for (const { template, component, token } of probes) {
    test(`${template} — ${component} 추가 시 캔버스에 "${token}" className 반영`, async ({ page }) => {
      const tokenAuth = issueToken('core.templates.layouts.edit');
      await authenticatePage(page, tokenAuth);

      await page.goto(`/admin/layout-editor/${template}`);
      await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

      // 로케일 비종속 — testid 로 "요소 추가" 버튼을 잡는다 (편집기 chrome 은 admin 로케일에 따라 ko/en 모두 가능)
      const addElementBtn = page.getByTestId('g7le-toolbar-add-element');
      await addElementBtn.waitFor({ state: 'visible', timeout: 30_000 });

      // 라우트 트리에서 실제 편집 가능한 leaf 라우트를 선택해야 팔레트/캔버스가 렌더된다.
      // redirect 라우트(badge-redirect 동반)는 레이아웃이 없어 "Select a route..." 안내만 뜨므로 제외.
      // route 트리 path 형식이 템플릿마다 달라 ?route= 파라미터 대신 트리 항목을 직접 클릭.
      const editableRouteItem = page
        .getByTestId('g7le-route-tree-item')
        .filter({ hasNot: page.getByTestId('g7le-route-tree-badge-redirect') })
        .first();
      await editableRouteItem.waitFor({ state: 'visible', timeout: 30_000 });
      await editableRouteItem.click();

      await expect(addElementBtn).toBeEnabled({ timeout: 30_000 });

      // 캔버스 내 해당 className 요소의 삽입 전 개수 (거짓 양성 회피 — 증가분을 검증)
      // token 은 단순 클래스명이라 CSS.escape 불필요 (테스트 본문은 Node 컨텍스트 — CSS 전역 없음)
      const countSelector = `[data-testid="g7le-preview-canvas"] .${token}`;
      const before = await page.locator(countSelector).count();

      // 팔레트 열고 대상 컴포넌트 추가
      await addElementBtn.click();
      const paletteItem = page.getByTestId(`g7le-palette-item-${component}`);
      await paletteItem.waitFor({ state: 'visible', timeout: 10_000 });
      await paletteItem.click();

      // 삽입 후 캔버스에 해당 className 요소가 증가해야 함 (defaultNode className 복제)
      await expect
        .poll(
          async () => page.locator(countSelector).count(),
          {
            message: `${component} 추가 후 "${token}" className 요소가 캔버스에서 증가해야 함 (defaultNode className 복제)`,
            timeout: 15_000,
          },
        )
        .toBeGreaterThan(before);
    });
  }
});
