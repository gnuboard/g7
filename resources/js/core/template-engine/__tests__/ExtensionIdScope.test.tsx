/**
 * ExtensionIdScope.test.tsx — 확장점 주입 조각의 정적 노드 id DOM 스코프 (engine-v1.99.0)
 *
 * 조합 요약(마커 아님 — 평문): 정적 id 단일 호스트, 보간 id, 명시 props.id, 중첩 호스트, 호스트 id 없음, 호스트 3개, 슬롯 겹침, 셀 경로, 스코프 함수 계약
 *
 * 같은 확장 조각이 확장점 여러 곳에 주입되면(게시글 본문·답글의 html_content, 알림 정의 행의 row_footer,
 * 회원 항목 반복의 address_search_slot) 조각 안 정적 노드 id 가 화면에 여러 번 나왔다(실측: 관리자 게시글
 * 상세 ckeditor5_content_css_injector ×4). 엔진이 호스트 아래 정적 id 를 `{id}__{호스트 DOM id}` 로 바꿔
 * 출력하는지 실제 DynamicRenderer·renderItemChildren 렌더로 잠근다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render } from '@testing-library/react';
import { renderToStaticMarkup } from 'react-dom/server';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { renderItemChildren } from '../helpers/RenderHelpers';
import { ExtensionIdScopeContext, scopeExtensionNodeId } from '../ExtensionIdScope';

const TestDiv: React.FC<any> = ({ className, children, ...rest }) => (
  <div className={className} {...rest}>
    {children}
  </div>
);
const TestSpan: React.FC<any> = ({ children, ...rest }) => <span {...rest}>{children}</span>;

/** 확장점 하나를 만든다 — 백엔드가 children 에 조각을 주입한 형태 */
const host = (id: string | undefined, children: ComponentDefinition[]): ComponentDefinition =>
  ({ ...(id ? { id } : {}), type: 'extension_point', name: 'html_content', props: {}, children } as any);

/** ckeditor5 html-content 조각을 흉내 낸 주입 노드 */
const fragment = (): ComponentDefinition =>
  ({ id: 'ckeditor5_content_css_injector', type: 'basic', name: 'Div', props: {} } as any);

describe('[case:components-misc-duplicate-dom-id-per-screen] ExtensionIdScope — 확장점 주입 조각 정적 id 스코프', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let translationContext: TranslationContext;

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
      Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    };
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    translationContext = { templateId: 'test-template', locale: 'ko' };
  });

  const renderDef = (componentDef: ComponentDefinition, dataContext: any = {}) =>
    render(
      <DynamicRenderer
        componentDef={componentDef}
        dataContext={dataContext}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={actionDispatcher}
      />
    );

  const ids = (container: HTMLElement) => Array.from(container.querySelectorAll('[id]')).map((el) => el.id);

  /**
   * @scenario case=static_single_host
   * @effects static_fragment_id_scoped_with_host_id
   */
  it('① 호스트 아래 정적 id 가 {id}__{호스트 id} 가 되고 호스트 자신은 그대로다', () => {
    const { container } = renderDef(host('ext_html_content_body', [fragment()]));
    expect(ids(container)).toEqual(['ext_html_content_body', 'ckeditor5_content_css_injector__ext_html_content_body']);
    expect(container.querySelector('#ckeditor5_content_css_injector')).toBeNull();
  });

  /**
   * @scenario case=interpolated_id
   * @effects interpolated_id_not_scoped
   */
  it('② 보간 id 는 해석값 그대로다', () => {
    const { container } = renderDef(
      host('ext_row', [{ id: 'row_badge_{{post.id}}', type: 'basic', name: 'Div', props: {} } as any]),
      { post: { id: 7 } },
    );
    expect(ids(container)).toContain('row_badge_7');
    expect(ids(container).some((i) => i.startsWith('row_badge_7__'))).toBe(false);
  });

  /**
   * @scenario case=explicit_props_id
   * @effects explicit_props_id_not_scoped
   */
  it('③ 명시 props.id 는 그대로다 (ckeditor5 편집기 ckeditor5-content 계약)', () => {
    const { container } = renderDef(
      host('ext_html_editor', [{ id: 'ckeditor5_container', type: 'basic', name: 'Div', props: { id: 'ckeditor5-content' } } as any]),
    );
    expect(ids(container)).toEqual(['ext_html_editor', 'ckeditor5-content']);
  });

  /**
   * @scenario case=nested_hosts
   * @effects nearest_host_wins_and_host_id_scoped_by_outer
   */
  it('④ 중첩 호스트에서는 가장 가까운 호스트가 이기고, 안쪽 호스트 id 도 바깥 scope 로 스코프된다', () => {
    const inner = host('ext_inner', [{ id: 'leaf', type: 'basic', name: 'Div', props: {} } as any]);
    const { container } = renderDef(
      host('ext_outer', [inner, { id: 'sibling', type: 'basic', name: 'Div', props: {} } as any]),
    );
    expect(ids(container)).toEqual([
      'ext_outer',
      'ext_inner__ext_outer',
      'leaf__ext_inner__ext_outer',
      'sibling__ext_outer',
    ]);
  });

  /**
   * @scenario case=missing_host_id
   * @effects missing_host_id_leaves_id_unscoped_without_error
   */
  it('⑤ 호스트 id 가 없으면 스코프하지 않고 오류도 없다', () => {
    const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    const { container } = renderDef(host(undefined, [fragment()]));
    expect(ids(container)).toEqual(['ckeditor5_content_css_injector']);
    expect(errorSpy).not.toHaveBeenCalled();
    errorSpy.mockRestore();
  });

  /**
   * @scenario case=repeated_hosts
   * @effects same_fragment_in_three_hosts_yields_three_distinct_ids
   */
  it('⑥ 같은 조각을 호스트 3개에 넣으면 DOM id 3개가 모두 다르다', () => {
    const page: ComponentDefinition = {
      id: 'page',
      type: 'basic',
      name: 'Div',
      props: {},
      children: [host('ext_html_content_post', [fragment()]), host('ext_html_content_reply_1', [fragment()]), host('ext_html_content_reply_2', [fragment()])],
    } as any;
    const { container } = renderDef(page);
    const injected = Array.from(container.querySelectorAll('[id^="ckeditor5_content_css_injector"]')).map((el) => el.id);
    expect(injected).toHaveLength(3);
    expect(new Set(injected).size).toBe(3);
  });

  /**
   * @scenario case=slot_scoped
   * @effects slot_scope_and_extension_scope_chain
   */
  it('⑦ SlotContainer 스코프(scopeSlotChildDef 가 정의 id 를 {id}__{slot} 로 바꿈)와 겹치면 {id}__{slot}__{host} 다', () => {
    // SlotContainer.scopeSlotChildDef 는 렌더 전에 정의의 id 를 `${id}__${containerId}` 로 바꾼다 — 그 결과 정의를 그대로 넣는다
    const slotScoped = { id: 'currency_selector__header_slot', type: 'basic', name: 'Div', props: {} } as any;
    const { container } = renderDef(host('ext_header', [slotScoped]));
    expect(ids(container)).toContain('currency_selector__header_slot__ext_header');
  });

  /**
   * @scenario case=cell_path
   * @effects cell_path_extension_host_inherits_scope
   */
  it('⑧ 셀 경로(renderItemChildren) 확장점 호스트가 상속 scope 를 받고, 보간 호스트는 그대로다', () => {
    const cellChildren = [
      { id: 'cell_static_host', type: 'extension_point', name: 'admin_order_list_payment_method_badges', props: {}, children: [{ type: 'basic', name: 'Span', text: 'A' }] },
      { id: 'cell_host_{{row.no}}', type: 'extension_point', name: 'admin_order_list_payment_method_badges', props: {}, children: [{ type: 'basic', name: 'Span', text: 'B' }] },
    ];
    const markup = (scope: string | undefined) =>
      renderToStaticMarkup(
        <ExtensionIdScopeContext.Provider value={scope}>
          {renderItemChildren(cellChildren as any, { row: { no: 'A-1' } }, { Span: TestSpan }, 'cell', {
            bindingEngine,
            translationEngine,
            translationContext,
          })}
        </ExtensionIdScopeContext.Provider>,
      );
    expect(markup('ext_outer')).toContain('id="cell_static_host__ext_outer"');
    expect(markup('ext_outer')).toContain('id="cell_host_A-1"');
    expect(markup(undefined)).toContain('id="cell_static_host"');
  });

  /**
   * @scenario case=scope_function
   * @effects scope_function_contract
   */
  it('scopeExtensionNodeId — 빈 문자열·비문자열·보간·scope 없음은 그대로', () => {
    expect(scopeExtensionNodeId('a', 'h')).toBe('a__h');
    expect(scopeExtensionNodeId('', 'h')).toBe('');
    expect(scopeExtensionNodeId(undefined, 'h')).toBeUndefined();
    expect(scopeExtensionNodeId('a_{{i}}', 'h')).toBe('a_{{i}}');
    expect(scopeExtensionNodeId('a', undefined)).toBe('a');
  });
});
