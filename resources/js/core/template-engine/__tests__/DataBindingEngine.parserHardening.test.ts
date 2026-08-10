/**
 * 파서 견고화 회귀 테스트 (engine-v1.55.1)
 *
 * - 보간 문자열 안의 바인딩을 정규식이 아니라 스캐너로 찾는다 (식 안의 `}` 허용)
 * - `resolveObject` 는 key 단위로 실패를 격리한다 (한 표현식이 컴포넌트 전체를 죽이지 않음)
 * - 중첩 객체 해석에도 컴포넌트별 skipBindingKeys 가 적용된다
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { DataBindingEngine } from '../DataBindingEngine';
import { scanBindings } from '../BindingShape';

describe('DataBindingEngine — 파서 견고화', () => {
  let engine: DataBindingEngine;

  beforeEach(() => {
    engine = new DataBindingEngine();
  });

  describe('scanBindings', () => {
    it('식 안의 중괄호를 포함한 바인딩을 끝까지 찾는다', () => {
      const found = scanBindings('a {{x ?? {}}} b');
      expect(found).toHaveLength(1);
      expect(found[0].expr).toBe('x ?? {}');
    });

    it('연속된 바인딩을 각각 찾는다', () => {
      const found = scanBindings('{{a}}-{{b}}');
      expect(found.map((f) => f.expr)).toEqual(['a', 'b']);
    });

    it('따옴표 안의 중괄호는 바인딩 종료로 보지 않는다', () => {
      const found = scanBindings("{{q['a}b']}}");
      expect(found).toHaveLength(1);
      expect(found[0].expr).toBe("q['a}b']");
    });

    it('닫히지 않은 `{{` 는 바인딩이 아니다', () => {
      expect(scanBindings('{{unclosed')).toEqual([]);
      expect(scanBindings('plain text')).toEqual([]);
    });
  });

  describe('보간 문자열 안의 중괄호 식', () => {
    it('"a {{x ?? {}}} b" 가 원본 문자열로 남지 않는다', () => {
      const out = engine.resolveBindings('a {{x ?? {}}} b', {});
      expect(out).not.toContain('{{');
      expect(out).toBe('a {} b');
    });

    it('중괄호 식과 일반 바인딩이 섞여도 각각 치환된다', () => {
      const out = engine.resolveBindings('{{name}} / {{meta ?? {}}}', { name: 'g7' });
      expect(out).toBe('g7 / {}');
    });

    it('바인딩이 없는 문자열은 그대로 반환한다 (회귀 보호)', () => {
      expect(engine.resolveBindings('plain', {})).toBe('plain');
    });

    it('닫히지 않은 `{{` 는 리터럴로 남는다 (종전 정규식과 동일)', () => {
      expect(engine.resolveBindings('{{unclosed', {})).toBe('{{unclosed');
    });
  });

  describe('resolveObject 의 key 단위 예외 격리', () => {
    it('한 key 의 표현식이 던져도 나머지 key 는 정상 해석된다', () => {
      const context = {
        ok: 'fine',
        get boom(): string {
          throw new Error('evaluation failed');
        },
      };

      const out: any = engine.resolveObject(
        { a: '{{ok}}', b: '{{(boom) + 1}}', c: '{{ok}}' },
        context,
        { skipCache: true },
      );

      expect(out.a).toBe('fine');
      expect(out.b).toBeUndefined();
      expect(out.c).toBe('fine');
    });

    it('중첩 객체 안의 실패는 그 안쪽 key 로 격리된다 (재귀 호출도 같은 규칙)', () => {
      const context = {
        get boom(): string {
          throw new Error('evaluation failed');
        },
        ok: 1,
      };

      const out: any = engine.resolveObject(
        { style: { color: '{{(boom) + 1}}' }, id: '{{ok}}' },
        context,
        { skipCache: true },
      );

      expect(out.id).toBe(1);
      // 중첩 객체는 재귀 resolveObject 가 처리하므로 실패가 그 안쪽 key 에서 멈춘다.
      expect(out.style).toEqual({ color: undefined });
    });
  });

  describe('skipBindingKeys', () => {
    it('전달된 키는 중첩 깊이와 무관하게 원본 그대로 남는다', () => {
      const context = { row: { id: 7 } };
      const out: any = engine.resolveObject(
        {
          wrapper: {
            children: [{ text: '{{row.id}}' }],
            label: '{{row.id}}',
          },
        },
        context,
        { skipCache: true, skipBindingKeys: ['children'] },
      );

      expect(out.wrapper.children[0].text).toBe('{{row.id}}');
      expect(out.wrapper.label).toBe(7);
    });

    it('기본 스킵 키(cellChildren)는 옵션 없이도 유지된다 (회귀 보호)', () => {
      const context = { row: { id: 7 } };
      const out: any = engine.resolveObject(
        { columns: [{ cellChildren: [{ text: '{{row.id}}' }] }] },
        context,
        { skipCache: true },
      );
      expect(out.columns[0].cellChildren[0].text).toBe('{{row.id}}');
    });
  });

  describe('식별자가 아닌 컨텍스트 키 (engine-v1.56.2)', () => {
    // 표현식 평가는 컨텍스트 키를 `new Function` 의 파라미터로 넘긴다. 파라미터가 될 수
    // 없는 키가 하나라도 있으면 함수 생성 자체가 SyntaxError 로 실패해, 그 키를 쓰지도
    // 않는 식까지 **그 컨텍스트에서는 전부** 평가되지 못했다.
    it('대괄호가 든 키가 있어도 다른 식이 정상 평가된다', () => {
      const context: any = { 'sales_status[]': ['selling'], user: { name: '홍길동' } };

      expect(engine.evaluateExpression("user.name ?? ''", context)).toBe('홍길동');
    });

    it('하이픈이 든 키가 있어도 다른 식이 정상 평가된다', () => {
      const context: any = { 'data-id': 3, count: 5 };

      expect(engine.evaluateExpression('count * 2', context)).toBe(10);
    });

    it('예약어 키가 있어도 다른 식이 정상 평가된다', () => {
      const context: any = { class: 'x', new: 1, total: 4 };

      expect(engine.evaluateExpression('total + 1', context)).toBe(5);
    });

    it('상위 객체를 거치는 실제 작성 형태는 그대로 동작한다', () => {
      // 레이아웃의 실사용은 최상위 키가 아니라 `query['sales_status[]']` 형태다.
      const context: any = { query: { 'sales_status[]': ['selling'] } };

      expect(engine.evaluateExpression("query['sales_status[]']", context)).toEqual(['selling']);
    });

    it('같은 식이라도 컨텍스트 키 구성이 다르면 캐시가 섞이지 않는다', () => {
      expect(engine.evaluateExpression('a + 1', { a: 1 } as any)).toBe(2);
      expect(engine.evaluateExpression('a + 1', { a: 10, 'bad-key': 1 } as any)).toBe(11);
    });
  });
});
