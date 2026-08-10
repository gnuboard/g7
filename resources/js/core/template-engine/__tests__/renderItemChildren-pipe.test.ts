/**
 * renderItemChildren 파이프 표현식 처리 테스트
 *
 * DataGrid 의 cellChildren, CardGrid 의 카드 children 등 반복 컨텍스트에서
 * 단일 바인딩 `{{path | pipe}}` 가 파이프 체인을 거쳐 해석되는지 검증합니다.
 *
 * 회귀 배경: 단일 바인딩 판정 후 `|` 가 포함되면 복잡 표현식으로 분류되어
 * `evaluateExpression` 으로 라우팅되었고, JS 비트 OR 로 평가되어
 * 인자 있는 파이프는 예외(값 소실), 인자 없는 파이프는 조용한 오답이 되었다.
 *
 * @since engine-v1.54.3
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import React from 'react';
import { renderItemChildren } from '../helpers/RenderHelpers';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine } from '../TranslationEngine';

// AuthManager mock
vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      login: vi.fn().mockResolvedValue({ id: 1, name: 'Test User' }),
      logout: vi.fn().mockResolvedValue(undefined),
    })),
  },
}));

// ApiClient mock
vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({
    getToken: vi.fn(),
  })),
}));

const TestSpan: React.FC<any> = ({ children, ...props }) =>
  React.createElement('span', props, children);

describe('renderItemChildren - 파이프 표현식', () => {
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;

  const componentMap: Record<string, React.ComponentType<any>> = {
    Span: TestSpan,
  };

  const render = (children: any[], context: Record<string, any>) =>
    renderItemChildren(children as any, context, componentMap, 'test', {
      bindingEngine,
      translationEngine,
      translationContext: { templateId: '', locale: 'ko' },
    });

  beforeEach(() => {
    bindingEngine = new DataBindingEngine();
    translationEngine = TranslationEngine.getInstance();
    (translationEngine as any).translations.set(':ko', {});
  });

  it('text 단일 바인딩 + 인자 있는 파이프(datetime) 가 포맷된 값을 반환한다', () => {
    const children = [
      {
        type: 'basic' as const,
        name: 'Span',
        text: "{{row.created_at | datetime('YYYY-MM-DD HH:mm')}}",
      },
    ];

    const result = render(children, { row: { created_at: '2026-07-26T05:42:00.000000Z' } });

    const element = result[0] as React.ReactElement;
    // 브라우저 타임존에 의존하지 않도록 형식만 검증
    expect(element.props.children).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/);
  });

  it('text 단일 바인딩 + 인자 없는 파이프(number) 가 천단위 구분자를 적용한다', () => {
    const children = [
      { type: 'basic' as const, name: 'Span', text: '{{row.price | number}}' },
    ];

    const result = render(children, { row: { price: 1234567 } });

    const element = result[0] as React.ReactElement;
    expect(element.props.children).toBe('1,234,567');
  });

  it('문자열 파이프(uppercase) 가 비트 연산으로 오염되지 않는다', () => {
    const children = [
      { type: 'basic' as const, name: 'Span', text: '{{row.code | uppercase}}' },
    ];

    const result = render(children, { row: { code: 'abc' } });

    const element = result[0] as React.ReactElement;
    expect(element.props.children).toBe('ABC');
  });

  it('파이프 체인이 순차 적용된다', () => {
    const children = [
      { type: 'basic' as const, name: 'Span', text: '{{row.code | uppercase | truncate(2)}}' },
    ];

    const result = render(children, { row: { code: 'abcdef' } });

    const element = result[0] as React.ReactElement;
    expect(element.props.children).toContain('AB');
  });

  it('props 값의 파이프도 해석된다', () => {
    const children = [
      {
        type: 'basic' as const,
        name: 'Span',
        text: 'x',
        props: { title: '{{row.price | number}}' },
      },
    ];

    const result = render(children, { row: { price: 12345 } });

    const element = result[0] as React.ReactElement;
    expect(element.props.title).toBe('12,345');
  });

  it('혼합 보간 형태의 파이프는 기존대로 동작한다 (회귀 보호)', () => {
    const children = [
      { type: 'basic' as const, name: 'Span', text: '[{{row.price | number}}]' },
    ];

    const result = render(children, { row: { price: 12345 } });

    const element = result[0] as React.ReactElement;
    expect(element.props.children).toBe('[12,345]');
  });

  it('파이프가 없는 단일 바인딩은 원본 타입을 유지한다 (회귀 보호)', () => {
    const children = [
      { type: 'basic' as const, name: 'Span', text: '{{row.count}}' },
    ];

    const result = render(children, { row: { count: 0 } });

    const element = result[0] as React.ReactElement;
    expect(element.props.children).toBe(0);
  });

  it('논리 OR(||) 표현식은 파이프로 오인되지 않는다 (회귀 보호)', () => {
    const children = [
      { type: 'basic' as const, name: 'Span', text: "{{row.name || '-'}}" },
    ];

    const result = render(children, { row: { name: '' } });

    const element = result[0] as React.ReactElement;
    expect(element.props.children).toBe('-');
  });
});
