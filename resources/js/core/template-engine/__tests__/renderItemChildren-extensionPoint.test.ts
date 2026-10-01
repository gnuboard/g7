/**
 * renderItemChildren 확장점(extension_point) 처리 테스트
 *
 * DataGrid 의 cellChildren 처럼 반복 컨텍스트 안에 모듈이 열어 둔 확장점이 있으면, 백엔드가 이미
 * 주입한 children 을 `DynamicRenderer` 의 확장점과 같은 규칙(래퍼 `<div>` + 허용 DOM 속성만)으로
 * 그려야 한다.
 *
 * 회귀 배경(#703 B2): 관리자 주문 목록 결제수단 셀에 이커머스가 연 확장점
 * `admin_order_list_payment_method_badges` 에 결제 플러그인이 테스트 결제 배지를 주입했는데,
 * `renderItemChildren` 이 확장점을 컴포넌트 이름으로 찾다가 「컴포넌트를 찾을 수 없습니다」 경고와
 * 함께 통째로 버려 배지가 한 번도 그려지지 않았다.
 *
 * @since engine-v1.73.0
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { renderItemChildren } from '../helpers/RenderHelpers';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine } from '../TranslationEngine';

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      login: vi.fn().mockResolvedValue({ id: 1, name: 'Test User' }),
      logout: vi.fn().mockResolvedValue(undefined),
    })),
  },
}));

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({
    getToken: vi.fn(),
  })),
}));

const TestSpan: React.FC<any> = ({ children, ...props }) =>
  React.createElement('span', props, children);

describe('renderItemChildren - 확장점', () => {
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;

  const componentMap: Record<string, React.ComponentType<any>> = { Span: TestSpan };

  const html = (children: any[], context: Record<string, any>) =>
    renderToStaticMarkup(
      React.createElement(
        React.Fragment,
        null,
        renderItemChildren(children as any, context, componentMap, 'cell', {
          bindingEngine,
          translationEngine,
          translationContext: { templateId: '', locale: 'ko' },
        }),
      ),
    );

  beforeEach(() => {
    bindingEngine = new DataBindingEngine();
    translationEngine = TranslationEngine.getInstance();
    (translationEngine as any).translations.set(':ko', {});
  });

  // @scenario surface=injected_children
  // @effects repeat_context_extension_point_renders_injected_children_per_row
  it('[확장점] 주입된 children 을 행 컨텍스트로 평가해 그린다', () => {
    const point = {
      type: 'extension_point',
      name: 'admin_order_list_payment_method_badges',
      children: [
        { type: 'basic', name: 'Span', if: '{{map?.[row.no] === true}}', text: '테스트' },
        { type: 'basic', name: 'Span', text: '{{row.no}}' },
      ],
    };

    expect(html([point], { row: { no: 'A-1' }, map: { 'A-1': true } }))
      .toBe('<div><span>테스트</span><span>A-1</span></div>');
    expect(html([point], { row: { no: 'B-2' }, map: { 'A-1': true } }))
      .toBe('<div><span>B-2</span></div>');
  });

  // @scenario surface=host_attributes
  // @effects repeat_context_extension_point_host_keeps_only_dom_attributes
  it('[확장점] 호스트에는 className 과 허용 DOM 속성만 남기고 확장 페이로드 props 는 싣지 않는다', () => {
    const point = {
      type: 'extension_point',
      name: 'x',
      props: { className: 'mt-1', 'data-slot': 'badges', targetFields: { a: 1 }, rows: 10 },
      children: [{ type: 'basic', name: 'Span', text: 'ok' }],
    };

    expect(html([point], {})).toBe('<div class="mt-1" data-slot="badges"><span>ok</span></div>');
  });

  // @scenario surface=empty_point
  // @effects empty_repeat_context_extension_point_renders_without_warning
  it('[확장점] 주입이 없는 확장점은 경고 없이 빈 래퍼만 남긴다', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

    expect(html([{ type: 'extension_point', name: 'empty_point' }], {})).toBe('<div></div>');
    expect(warn.mock.calls.flat().join(' ')).not.toContain('컴포넌트를 찾을 수 없습니다');

    warn.mockRestore();
  });

  // @scenario surface=point_condition
  // @effects repeat_context_extension_point_respects_own_condition
  it('[확장점] 확장점 자체의 if 가 거짓이면 그리지 않는다', () => {
    const point = {
      type: 'extension_point',
      name: 'x',
      if: '{{false}}',
      children: [{ type: 'basic', name: 'Span', text: 'hidden' }],
    };

    expect(html([point], {})).toBe('');
  });
});
