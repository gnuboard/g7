/**
 * DynamicRenderer 파이프 렌더 경로 회귀 테스트
 *
 * #87 수정(engine-v1.54.3)이 파이프 평가를 `resolveBindings(`{{...}}`)` 위임으로 처리하면서
 * 남긴 비대칭 3건을 렌더 경로에서 고정한다. 셋 다 엔진 단위 테스트로는 잡히지 않는다 —
 * 결함이 "어떤 옵션으로 어떤 메서드를 호출하는가" 라는 호출 지점에 있기 때문이다.
 *
 * - D1: `}` 가 든 파이프 식이 원본 `{{...}}` 문자열로 화면에 노출
 * - D2: `_computed` 파이프가 영구 캐시를 타 컴포넌트 간 stale 값 전파
 * - D3: 파이프 결과가 항상 문자열로 서식되어 배열/boolean 이 소실
 *
 * @since engine-v1.54.9
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';

/** prop 으로 전달된 값의 타입을 그대로 드러내는 프로브 컴포넌트 */
const TypeProbe: React.FC<{ value?: any; children?: React.ReactNode }> = ({ value }) => (
  <div
    data-testid="probe"
    data-kind={Array.isArray(value) ? 'array' : typeof value}
    data-json={JSON.stringify(value ?? null)}
  />
);

const Plain: React.FC<{ children?: React.ReactNode }> = ({ children }) => (
  <div data-testid="plain">{children}</div>
);

describe('DynamicRenderer — 파이프 렌더 경로 비대칭 (engine-v1.54.9)', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let translationContext: TranslationContext;

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      TypeProbe: { component: TypeProbe, metadata: { name: 'TypeProbe', type: 'basic' } },
      Plain: { component: Plain, metadata: { name: 'Plain', type: 'basic' } },
    };

    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    translationContext = { templateId: 'test-template', locale: 'ko' };
  });

  const renderDef = (componentDef: ComponentDefinition, dataContext: any) =>
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

  describe('D1 — 파이프 식 내부 중괄호', () => {
    it('text 의 {{(row.meta ?? {}) | json}} 가 원본 문자열로 노출되지 않는다', () => {
      const def: ComponentDefinition = {
        id: 'd1-text',
        type: 'basic',
        name: 'Plain',
        props: {},
        text: '{{(row.meta ?? {}) | json}}',
      };

      renderDef(def, { row: { meta: { a: 1 } } });

      const el = screen.getByTestId('plain');
      expect(el).toHaveTextContent('{"a":1}');
      expect(el.textContent).not.toContain('{{');
    });

    it('prop 의 파이프 식 내부 중괄호도 동일하게 평가된다', () => {
      const def: ComponentDefinition = {
        id: 'd1-prop',
        type: 'basic',
        name: 'TypeProbe',
        props: { value: '{{(row.meta ?? {}) | json}}' },
      };

      renderDef(def, { row: {} });

      const el = screen.getByTestId('probe');
      expect(el.getAttribute('data-kind')).toBe('string');
      expect(JSON.parse(el.getAttribute('data-json')!)).toBe('{}');
    });
  });

  describe('D2 — _computed 파이프의 컴포넌트 간 stale 전파', () => {
    it('같은 엔진으로 렌더된 다른 컴포넌트가 이전 _computed 값을 재사용하지 않는다', () => {
      const first = renderDef(
        {
          id: 'd2-a',
          type: 'basic',
          name: 'TypeProbe',
          props: { value: '{{_computed.total | number}}' },
        },
        { _computed: { total: 1000 } }
      );
      expect(screen.getByTestId('probe').getAttribute('data-json')).toBe('"1,000"');
      first.unmount();

      // _computed 는 컴포넌트마다 _local 기반으로 재계산된다. 파이프 분기가
      // getComputedAwareOptions 를 우회하면 baseExpr(`_computed.total`)이 영구 캐시(30초)에
      // 저장되어 다음 컴포넌트가 앞 컴포넌트의 값을 그대로 받는다.
      renderDef(
        {
          id: 'd2-b',
          type: 'basic',
          name: 'TypeProbe',
          props: { value: '{{_computed.total | number}}' },
        },
        { _computed: { total: 2000 } }
      );
      expect(screen.getByTestId('probe').getAttribute('data-json')).toBe('"2,000"');
    });

    it('$computed alias 도 동일하게 캐시를 건너뛴다', () => {
      const first = renderDef(
        {
          id: 'd2-alias-a',
          type: 'basic',
          name: 'TypeProbe',
          props: { value: '{{$computed.total | number}}' },
        },
        { _computed: { total: 10 } }
      );
      expect(screen.getByTestId('probe').getAttribute('data-json')).toBe('"10"');
      first.unmount();

      renderDef(
        {
          id: 'd2-alias-b',
          type: 'basic',
          name: 'TypeProbe',
          props: { value: '{{$computed.total | number}}' },
        },
        { _computed: { total: 20 } }
      );
      expect(screen.getByTestId('probe').getAttribute('data-json')).toBe('"20"');
    });
  });

  describe('D3 — 파이프 결과 타입 보존', () => {
    it('배열을 반환하는 파이프가 prop 에 배열로 전달된다', () => {
      const def: ComponentDefinition = {
        id: 'd3-array',
        type: 'basic',
        name: 'TypeProbe',
        props: { value: '{{row.meta | keys}}' },
      };

      renderDef(def, { row: { meta: { a: 1, b: 2 } } });

      const el = screen.getByTestId('probe');
      expect(el.getAttribute('data-kind')).toBe('array');
      expect(JSON.parse(el.getAttribute('data-json')!)).toEqual(['a', 'b']);
    });

    it('boolean 을 반환하는 파이프가 문자열 "false" 로 바뀌지 않는다', () => {
      const def: ComponentDefinition = {
        id: 'd3-boolean',
        type: 'basic',
        name: 'TypeProbe',
        props: { value: '{{row.flags | first}}' },
      };

      renderDef(def, { row: { flags: [false, true] } });

      const el = screen.getByTestId('probe');
      expect(el.getAttribute('data-kind')).toBe('boolean');
      expect(JSON.parse(el.getAttribute('data-json')!)).toBe(false);
    });

    it('숫자를 반환하는 파이프가 number 타입으로 전달된다', () => {
      const def: ComponentDefinition = {
        id: 'd3-number',
        type: 'basic',
        name: 'TypeProbe',
        props: { value: '{{row.tags | length}}' },
      };

      renderDef(def, { row: { tags: ['a', 'b', 'c'] } });

      const el = screen.getByTestId('probe');
      expect(el.getAttribute('data-kind')).toBe('number');
      expect(JSON.parse(el.getAttribute('data-json')!)).toBe(3);
    });

    it('computed 정의식의 파이프가 적용된다 (종전에는 예외 → 이전 값 유지)', () => {
      // _computedDefinitions 는 렌더마다 최신 _local 로 재계산된다. 그 평가가
      // evaluateExpression 이라 파이프가 붙으면 예외 → catch 에서 이전 값 유지가 되어
      // computed 가 영원히 갱신되지 않았다. @since engine-v1.54.10
      const def: ComponentDefinition = {
        id: 'p1-computed',
        type: 'basic',
        name: 'TypeProbe',
        props: { value: '{{_computed.formatted}}' },
      };

      renderDef(def, {
        _local: { price: 1234567 },
        _computedDefinitions: { formatted: '{{_local.price | number}}' },
      });

      const el = screen.getByTestId('probe');
      expect(JSON.parse(el.getAttribute('data-json')!)).toBe('1,234,567');
    });

    it('문자열을 반환하는 기존 파이프(datetime/number)의 결과는 종전과 동일하다', () => {
      const def: ComponentDefinition = {
        id: 'd3-string',
        type: 'basic',
        name: 'Plain',
        props: {},
        text: "{{row.created_at | datetime('YYYY-MM-DD HH:mm')}}",
      };

      renderDef(def, { row: { created_at: '2024-01-15T14:30:00' } });

      expect(screen.getByTestId('plain')).toHaveTextContent('2024-01-15 14:30');
    });
  });
});
