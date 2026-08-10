/**
 * 판정 통일 회귀 테스트 (engine-v1.55.0)
 *
 * 같은 식이 렌더 경로마다 다른 결과를 내던 비대칭을 고정한다.
 * - 따옴표 키 인덱싱(`query['sales_status[]']`)이 prop·text·if·conditions 4경로에서 동일
 * - 숫자 인덱싱(`items[0]`)은 경로 이동(resolve → evaluateExpression) 후에도 결과 동치
 * - 빈 바인딩(`{{}}`)은 컨텍스트 객체 전체가 아니라 undefined
 * - `"{{a}}-{{b}}"` 는 단일 바인딩이 아니다 (greedy 정규식 오판 해소)
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { evaluateStringCondition } from '../helpers/ConditionEvaluator';
import { evaluateIfCondition } from '../helpers/RenderHelpers';
import {
  classifyExpression,
  extractSingleBinding,
  isComplexExpression,
  resolveSingleBindingValue,
} from '../BindingShape';

const Probe: React.FC<{ value?: any; children?: React.ReactNode }> = ({ value, children }) => (
  <div data-testid="probe" data-json={JSON.stringify(value ?? null)}>
    {children}
  </div>
);

describe('BindingShape — 판정 통일', () => {
  describe('extractSingleBinding 정본', () => {
    it('식 안의 객체 리터럴을 단일 바인딩으로 인식한다', () => {
      expect(extractSingleBinding('{{error.errors ?? {}}}')).toBe('error.errors ?? {}');
    });

    it('따옴표 안의 중괄호는 균형 계산에서 제외한다', () => {
      expect(extractSingleBinding("{{query['a}b']}}")).toBe("query['a}b']");
    });

    it('여러 바인딩이 이어진 문자열은 단일 바인딩이 아니다 (greedy 오판 해소)', () => {
      expect(extractSingleBinding('{{a}}-{{b}}')).toBeNull();
      expect(extractSingleBinding('{{_global.shopBase}}/category/{{cat?.slug}}')).toBeNull();
    });

    it('앞뒤 공백은 제거된다', () => {
      expect(extractSingleBinding('{{  user.name  }}')).toBe('user.name');
    });

    it('빈 바인딩은 빈 문자열로 인식된다 (null 아님)', () => {
      expect(extractSingleBinding('{{}}')).toBe('');
      expect(classifyExpression('')).toBe('empty');
    });

    it('바인딩이 아닌 문자열은 null', () => {
      expect(extractSingleBinding('hello {{name}}')).toBeNull();
      expect(extractSingleBinding('plain')).toBeNull();
    });
  });

  describe('isComplexExpression 정본 (최대집합)', () => {
    it('따옴표 키 인덱싱을 표현식으로 본다', () => {
      expect(isComplexExpression("query['sales_status[]']")).toBe(true);
    });

    it('배열/객체 리터럴을 표현식으로 본다', () => {
      expect(isComplexExpression("['a','b']")).toBe(true);
      expect(isComplexExpression('[{ id: 1 }]')).toBe(true);
    });

    it('순수 점 경로는 경로로 본다', () => {
      expect(isComplexExpression('user.profile.name')).toBe(false);
      expect(classifyExpression('user.profile.name')).toBe('path');
    });
  });

  describe('빈 바인딩 봉인', () => {
    let engine: DataBindingEngine;
    beforeEach(() => {
      engine = new DataBindingEngine();
    });

    it('resolveObject 의 빈 바인딩은 undefined (컨텍스트 객체 미반환)', () => {
      const context = { secret: 'do-not-leak', _global: { token: 'x' } };
      const out: any = engine.resolveObject({ v: '{{}}' }, context);
      expect(out.v).toBeUndefined();
    });

    it('빈 경로가 resolve 로 들어가도 컨텍스트 전체를 반환하지 않는다', () => {
      const context = { secret: 'do-not-leak' };
      expect(engine.resolve('', context, { skipCache: true })).toBeUndefined();
      expect(engine.resolve('   ', context, { skipCache: true })).toBeUndefined();
    });

    it('조건에서의 빈 바인딩은 false 로 판정된다', () => {
      const context = { secret: 'do-not-leak' };
      expect(evaluateStringCondition('{{}}', context, engine)).toBe(false);
      expect(evaluateIfCondition('{{}}', context, engine, 'test')).toBe(false);
    });
  });

  describe('숫자 인덱싱 경로 이동 동치성 (4 컨텍스트)', () => {
    let engine: DataBindingEngine;
    beforeEach(() => {
      engine = new DataBindingEngine();
    });

    const cases: { name: string; context: any; expr: string }[] = [
      { name: '존재', context: { rows: [{ id: 'a' }, { id: 'b' }] }, expr: 'rows[1]' },
      { name: '미존재(범위 밖)', context: { rows: [{ id: 'a' }] }, expr: 'rows[3]' },
      { name: 'null', context: { rows: null }, expr: 'rows[0]' },
      { name: '중첩 미정의', context: {}, expr: 'missing.deep[0]' },
    ];

    for (const c of cases) {
      it(`${c.name}: resolve 와 evaluateExpression 결과가 같다`, () => {
        const viaResolve = engine.resolve(c.expr, c.context, { skipCache: true });
        let viaEval: unknown;
        try {
          viaEval = engine.evaluateExpression(c.expr, c.context);
        } catch {
          viaEval = undefined;
        }
        expect(viaEval).toEqual(viaResolve);
      });
    }
  });

  describe('따옴표 키 인덱싱 — prop / text / if / conditions 4경로 동일값', () => {
    let registry: ComponentRegistry;
    let bindingEngine: DataBindingEngine;
    let translationEngine: TranslationEngine;
    let actionDispatcher: ActionDispatcher;
    let translationContext: TranslationContext;

    const context = { query: { 'sales_status[]': ['selling', 'soldout'] } };
    const EXPR = "query['sales_status[]']";

    beforeEach(() => {
      registry = ComponentRegistry.getInstance();
      (registry as any).registry = {
        Probe: { component: Probe, metadata: { name: 'Probe', type: 'basic' } },
      };
      bindingEngine = new DataBindingEngine();
      translationEngine = new TranslationEngine();
      actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
      translationContext = { templateId: 'test-template', locale: 'ko' };
    });

    const renderDef = (componentDef: ComponentDefinition) =>
      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={context}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

    it('prop 경로에서 배열이 전달된다', () => {
      const view = renderDef({
        id: 'prop', type: 'basic', name: 'Probe', props: { value: `{{${EXPR}}}` },
      });
      expect(JSON.parse(screen.getByTestId('probe').getAttribute('data-json')!)).toEqual([
        'selling',
        'soldout',
      ]);
      view.unmount();
    });

    it('text 경로에서 값이 비지 않는다', () => {
      const view = renderDef({
        id: 'text', type: 'basic', name: 'Probe', props: {}, text: `{{${EXPR}}}`,
      });
      expect(screen.getByTestId('probe').textContent).toContain('selling');
      view.unmount();
    });

    it('if 경로에서 truthy 로 판정된다', () => {
      expect(evaluateIfCondition(`{{${EXPR}}}`, context, bindingEngine, 'test')).toBe(true);
    });

    it('conditions 경로에서 truthy 로 판정된다', () => {
      expect(evaluateStringCondition(`{{${EXPR}}}`, context, bindingEngine)).toBe(true);
    });

    it('네 경로가 모두 같은 값을 본다 (엔진 직접 해석과 동치)', () => {
      const direct = resolveSingleBindingValue(EXPR, context, bindingEngine, { skipCache: true });
      expect(direct).toEqual(['selling', 'soldout']);
    });
  });
});
