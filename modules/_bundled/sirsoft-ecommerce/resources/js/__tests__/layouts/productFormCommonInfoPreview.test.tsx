// e2e:allow 레이아웃 조각의 구조 계약(React 가 받지 않는 dangerouslySetInnerHTML 문자열 금지 · html_content 확장점)을 잠근다 — 실제 미리보기 표시·정화·언어 탭 전환은 출시 전 화면 재실측(상품 등록 → 공통정보 선택 → 상세 펼침, 스타일 정책 끔·엄격)이 덮는다
/**
 * @file productFormCommonInfoPreview.test.tsx
 * @description 상품 등록·수정 폼 — 고른 공통정보의 본문 미리보기는 `html_content` 확장점으로 그린다
 *
 * 회귀 배경: 미리보기 블록(`common_info_content_display`)이 HTML 모드 본문을 `Div` 의 `dangerouslySetInnerHTML` 에
 * 문자열로 넘겼다. React 는 그 값을 `{ __html }` 객체로만 받으므로 HTML 로 작성한 공통정보를 고르고 상세를 펼치면
 * 미리보기 자리에 「컴포넌트 로드 실패」 오류 상자가 떴다(React error #61). 정화도 거치지 않았다.
 * 공통정보 관리 화면의 보기 패널(`_panel_view` 의 `content_display`)과 같은 `html_content` 확장점으로 바꿔,
 * 편집기 플러그인이 있으면 그 표시기가, 없으면 템플릿의 `HtmlContent`(정화 포함)가 그린다. 텍스트 모드 본문도
 * 같은 확장점이 `isHtml=false` 로 그린다(줄바꿈 보존).
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/product-form-common-info-preview.yaml
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import commonInfo from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_common_info.json';
import panelView from '../../../layouts/admin/partials/admin_ecommerce_product_common_info_index/_panel_view.json';

type Node = Record<string, any>;

/**
 * 레이아웃 트리에서 id 가 같은 노드를 찾습니다.
 *
 * @param node 탐색을 시작할 노드
 * @param id 찾을 노드 id
 * @returns 찾은 노드 또는 null
 */
function findById(node: any, id: string): Node | null {
  if (!node || typeof node !== 'object') return null;
  if (Array.isArray(node)) {
    for (const child of node) {
      const hit = findById(child, id);
      if (hit) return hit;
    }
    return null;
  }
  if (node.id === id) return node;
  for (const value of Object.values(node)) {
    const hit = findById(value, id);
    if (hit) return hit;
  }
  return null;
}

const CONTENT_EXPR = "{{(common_infos?.data?.data ?? []).find(c => c.id === _local.form.common_info_id)?.content?.[_local.ui.commonInfoLocale ?? $locale] ?? ''}}";
const IS_HTML_EXPR = "{{((common_infos?.data?.data ?? []).find(c => c.id === _local.form.common_info_id)?.content_mode ?? 'text') === 'html'}}";

describe('상품 폼 — 공통정보 본문 미리보기', () => {
  /**
   * @scenario case=layout_contract
   * @effects no_raw_inner_html_prop
   */
  it('조각 어디에도 dangerouslySetInnerHTML 을 쓰지 않는다', () => {
    expect(JSON.stringify(commonInfo)).not.toContain('dangerouslySetInnerHTML');
  });

  /**
   * @scenario case=layout_contract
   * @effects preview_uses_html_content_extension_point
   */
  it('미리보기 블록의 본문은 html_content 확장점 하나다', () => {
    const display = findById(commonInfo, 'common_info_content_display') as Node;
    expect(display).not.toBeNull();
    expect(display.props.className).toContain('max-h-96');
    expect(display.props.className).toContain('overflow-y-auto');
    expect(display.children).toHaveLength(1);

    const point = display.children[0];
    expect(point.type).toBe('extension_point');
    expect(point.name).toBe('html_content');
    expect(point.if).toBeUndefined();
  });

  /**
   * @scenario case=html_mode
   * @effects html_mode_follows_content_mode, preview_content_follows_selected_locale
   */
  it('본문은 고른 공통정보·고른 언어를 따르고, HTML 여부는 content_mode 로 정한다', () => {
    const point = (findById(commonInfo, 'common_info_content_display') as Node).children[0];

    expect(point.props.content).toBe(CONTENT_EXPR);
    expect(point.props.isHtml).toBe(IS_HTML_EXPR);
    expect(point.props.className).toBe('prose prose-sm dark:prose-invert max-w-none');
  });

  /**
   * @scenario case=text_mode
   * @effects html_mode_follows_content_mode
   */
  it('모드 값이 없는 공통정보는 텍스트로 그린다 — 식이 text 로 떨어진다', () => {
    const point = (findById(commonInfo, 'common_info_content_display') as Node).children[0];
    const expression = point.props.isHtml.slice(2, -2);
    // eslint-disable-next-line no-new-func
    const evaluate = (commonInfos: unknown, id: number) => new Function('common_infos', '_local', `return (${expression});`)(commonInfos, { form: { common_info_id: id } });

    const list = { data: { data: [{ id: 1, content_mode: 'html' }, { id: 2, content_mode: 'text' }, { id: 3 }] } };
    expect(evaluate(list, 1)).toBe(true);
    expect(evaluate(list, 2)).toBe(false);
    expect(evaluate(list, 3)).toBe(false);
    expect(evaluate(list, 99)).toBe(false);
    expect(evaluate(undefined, 1)).toBe(false);
  });

  /**
   * @scenario case=fallback_renderer
   * @effects fallback_renders_with_template_html_content
   */
  it('편집기 플러그인이 없을 때의 기본 표시는 같은 값을 받는 HtmlContent 다', () => {
    const point = (findById(commonInfo, 'common_info_content_display') as Node).children[0];

    expect(point.default).toHaveLength(1);
    const fallback = point.default[0];
    expect(fallback.type).toBe('composite');
    expect(fallback.name).toBe('HtmlContent');
    expect(fallback.props.content).toBe(CONTENT_EXPR);
    expect(fallback.props.isHtml).toBe(IS_HTML_EXPR);
    expect(fallback.props.className).toBe('prose prose-sm dark:prose-invert max-w-none');
  });

  /**
   * @scenario case=layout_contract
   * @effects preview_uses_html_content_extension_point
   */
  it('공통정보 관리 화면의 보기 패널과 같은 형태다(확장점 이름·기본 표시기)', () => {
    const reference = findById(panelView, 'content_display') as Node;
    const point = (findById(commonInfo, 'common_info_content_display') as Node).children[0];

    expect(reference.type).toBe('extension_point');
    expect(point.name).toBe(reference.name);
    expect(Object.keys(point.props).sort()).toEqual(Object.keys(reference.props).sort());
    expect(point.default[0].name).toBe(reference.default[0].name);
    expect(point.default[0].type).toBe(reference.default[0].type);
  });
});
