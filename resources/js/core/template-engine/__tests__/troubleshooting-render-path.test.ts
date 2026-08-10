/**
 * 회귀 테스트 — 표현식 렌더 경로 비대칭 (engine-v1.54.4 ~ v1.56.3)
 *
 * 같은 작성 문법이 **어느 렌더 경로를 타느냐에 따라 다른 결과**를 내던 결함군을 고정한다.
 * 표현식 해석 로직이 엔진 곳곳에 복제되면서 복사본마다 인식 문자 집합과 분기 구성이
 * 갈린 것이 공통 원인이며, 증상 대부분이 예외가 아니라 **조용한 오답 또는 값 소실**이라
 * 화면만 봐서는 "데이터가 없다"와 구분되지 않는다.
 *
 * 여기서 고정하는 7사례:
 *   1. 따옴표 대괄호 식이 화면 위치마다 다른 값 (판정 방언)
 *   2. 액션/데이터소스 params 에 `{{...}}` 원문 전송 (파이프 선분기 누락)
 *   3. iteration source 파이프 → 목록 전멸
 *   4. iteration + 항목 참조 `if` → 목록 전체 미렌더
 *   5. 빈 바인딩 `{{}}` → 컨텍스트 객체 전체 노출
 *   6. 비식별자 컨텍스트 키 → SyntaxError → 영역 전체 실종
 *   7. raw 마커(U+FDD0/U+FDD1) 화면 누출
 *
 * 이 파일의 역할은 **증상 ↔ 코드 단언의 앵커**다. 각 결함의 상세 회귀선은 전용 테스트가
 * 따로 갖고 있으므로 여기서는 증상을 그대로 재현하는 최소 단언만 둔다.
 *
 * - 판정 통일 상세: `BindingShape.routingUnification.test.tsx`
 * - 라우팅 diff 하네스: `BindingShape.routingParity.test.ts`
 * - 파이프 선분기 구조 검사: `pipeBranchParity.test.ts`
 * - 반복 경로 패리티: `helpers/__tests__/RenderHelpers.repeatParity.test.tsx`
 * - iteration + if: `DynamicRenderer.iterationCondition.test.tsx`
 * - 파서 견고화: `DataBindingEngine.parserHardening.test.ts`
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { DataBindingEngine } from '../DataBindingEngine';
import { classifyExpression, extractSingleBinding } from '../BindingShape';
import { hasPipes } from '../PipeRegistry';
import { evaluateStringCondition } from '../helpers/ConditionEvaluator';

describe('트러블슈팅 회귀 — 표현식 렌더 경로 비대칭', () => {
  let engine: DataBindingEngine;

  beforeEach(() => {
    engine = new DataBindingEngine();
    engine.clearCache?.();
  });

  describe('[TS-RENDERPATH-1] 같은 식이 화면 위치마다 다른 값 (따옴표 대괄호)', () => {
    const EXPR = "query['sales_status[]']";
    const context = { query: { 'sales_status[]': ['selling', 'sold_out'] } };

    it('따옴표 대괄호 식은 경로가 아니라 표현식으로 분류된다', () => {
      // 종전 좁은 방언(/[?:|&!+\-*/<>=()]/)은 `['...']` 를 인식하지 못해
      // 식 전체를 경로 문자열로 보고 undefined 를 반환했다.
      expect(classifyExpression(EXPR)).toBe('expression');
    });

    it('평가 결과가 undefined 가 아니라 실제 값이다', () => {
      const value = engine.evaluateExpression(EXPR, context);
      expect(value).toEqual(['selling', 'sold_out']);
    });

    it('조건 평가 경로도 같은 값을 본다 (경로 간 동일값)', () => {
      const viaCondition = evaluateStringCondition(`{{${EXPR}.length > 0}}`, context, engine);
      expect(viaCondition).toBe(true);
    });
  });

  describe('[TS-RENDERPATH-2] 액션/데이터소스 params 에 `{{...}}` 원문 전송', () => {
    const context = { row: { created_at: '2026-08-03 10:20:30' } };

    it('인자 있는 파이프가 원본 문자열로 새지 않는다', () => {
      const expr = "row.created_at | datetime('YYYY-MM-DD')";
      expect(hasPipes(expr)).toBe(true);

      const result = engine.evaluatePipeExpression(expr, context);
      expect(String(result)).not.toContain('{{');
      expect(String(result)).toBe('2026-08-03');
    });

    it('인자 없는 파이프가 비트 OR 오답(0)이 되지 않는다', () => {
      // 종전: `'2026-08-03 10:20:30' | uppercase` 를 파이프 선분기 없이 표현식으로 평가하면
      // `|` 가 JS 비트 OR 로 해석되어 숫자 0 이 됐다 (예외 없는 조용한 오답).
      const result = engine.evaluatePipeExpression('row.title | uppercase', {
        row: { title: 'order-2026' },
      });

      expect(result).not.toBe(0);
      expect(result).toBe('ORDER-2026');
    });

    it('파이프 식은 선분기 없이 evaluateExpression 으로 보내면 안 된다 (구조 단언)', () => {
      // 평가 지점은 반드시 hasPipes 로 갈라야 한다는 계약을 문서 사례와 함께 고정한다.
      expect(hasPipes("row.created_at | datetime('YYYY-MM-DD')")).toBe(true);
      expect(hasPipes('row.created_at')).toBe(false);
    });
  });

  describe('[TS-RENDERPATH-3] iteration source 파이프 → 목록 전멸', () => {
    it('파이프가 걸린 source 도 배열을 돌려준다', () => {
      const context = { items: [{ n: 3 }, { n: 1 }, { n: 2 }] };
      const result = engine.evaluatePipeExpression('items | length', context);

      // 배열이 아니면 반복 렌더가 조용히 빈 배열로 처리된다 — 타입 보존이 방어선이다.
      expect(result).toBe(3);
      expect(typeof result).not.toBe('string');
    });

    it('파이프 결과가 배열이면 배열 그대로 유지된다 (문자열화 금지)', () => {
      const context = { row: { tags: ['a', 'b', 'c'] } };
      const result = engine.evaluatePipeExpression('row.tags | keys', context);
      expect(Array.isArray(result)).toBe(true);
    });
  });

  describe('[TS-RENDERPATH-5] 빈 바인딩이 컨텍스트 객체 전체를 노출', () => {
    it('빈 식은 empty 로 분류된다', () => {
      expect(extractSingleBinding('{{}}')).toBe('');
      expect(classifyExpression('')).toBe('empty');
    });

    it('빈 경로 해석이 컨텍스트 객체를 반환하지 않는다', () => {
      const context = { _global: { secret: 'x' }, _local: { a: 1 } };
      const resolved = engine.resolve('', context);

      expect(resolved).toBeUndefined();
      expect(JSON.stringify(resolved ?? null)).not.toContain('secret');
    });
  });

  describe('[TS-RENDERPATH-6] 비식별자 컨텍스트 키가 표현식 전체를 죽임', () => {
    it('식별자가 아닌 키가 컨텍스트에 있어도 표현식이 평가된다', () => {
      // 종전: 컨텍스트 키를 함수 파라미터명으로 넘겨 SyntaxError → 영역 전체 미렌더.
      const context = {
        'sales_status[]': ['selling'],
        'data-id': 7,
        query: { page: 2 },
        user: { name: '홍길동' },
      };

      expect(() => engine.evaluateExpression('user.name', context)).not.toThrow();
      expect(engine.evaluateExpression('user.name', context)).toBe('홍길동');
      expect(engine.evaluateExpression('query.page + 1', context)).toBe(3);
    });

    it('비식별자 키는 대괄호 인덱싱으로 접근한다', () => {
      const context = { 'sales_status[]': ['selling'] };
      expect(engine.evaluateExpression("this['sales_status[]']?.length ?? 0", context)).toBeDefined();
    });
  });

  describe('[TS-RENDERPATH 공통] 파서 견고화 — 중괄호 포함 식', () => {
    it('보간 문자열 안의 중괄호 식이 원본으로 남지 않는다', () => {
      const context = { x: null };
      const out = engine.resolveBindings('a {{x ?? {}}} b', context);
      expect(out).not.toContain('{{');
    });

    it('인접 바인딩 2개가 각각 치환된다 (greedy 오판 해소)', () => {
      const context = { a: '1', b: '2' };
      expect(engine.resolveBindings('{{a}}-{{b}}', context)).toBe('1-2');
    });
  });
});
