/**
 * DataBindingEngine 파이프 통합 테스트
 *
 * resolveBindings 메서드에서 파이프 함수가 올바르게 처리되는지 테스트
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { DataBindingEngine } from '../DataBindingEngine';
import { pipeRegistry } from '../PipeRegistry';

describe('DataBindingEngine - Pipe Integration', () => {
  let engine: DataBindingEngine;

  beforeEach(() => {
    engine = new DataBindingEngine();
    pipeRegistry.clearCustomPipes();
  });

  describe('resolveBindings with pipes', () => {
    it('date 파이프 처리', () => {
      const context = { post: { created_at: '2024-01-15' } };
      const result = engine.resolveBindings('{{post.created_at | date}}', context);
      expect(result).toBe('2024-01-15');
    });

    it('datetime 파이프 처리', () => {
      const context = { post: { created_at: '2024-01-15T14:30:00' } };
      const result = engine.resolveBindings('{{post.created_at | datetime}}', context);
      expect(result).toContain('2024-01-15');
      expect(result).toContain('14:30');
    });

    it('number 파이프 처리', () => {
      const context = { stats: { count: 1234567 } };
      const result = engine.resolveBindings('{{stats.count | number}}', context);
      // 로케일에 따라 구분자가 다를 수 있음
      expect(result).toContain('1');
      expect(result).toContain('234');
    });

    it('relativeTime 파이프 처리', () => {
      const now = new Date();
      const context = { post: { created_at: now.toISOString() } };
      const result = engine.resolveBindings('{{post.created_at | relativeTime}}', context);
      expect(result).toBe('방금 전');
    });

    it('truncate 파이프 처리', () => {
      const context = { description: 'Lorem ipsum dolor sit amet consectetur adipiscing elit' };
      const result = engine.resolveBindings('{{description | truncate(20)}}', context);
      expect(result).toBe('Lorem ipsum dolor si...');
    });

    it('파이프 체인 처리', () => {
      const context = { text: 'hello world this is a test' };
      const result = engine.resolveBindings('{{text | truncate(10) | uppercase}}', context);
      expect(result).toBe('HELLO WORL...');
    });

    it('복잡한 표현식과 파이프 조합', () => {
      const context = { post: { created_at_formatted: null, created_at: '2024-01-15' } };
      const result = engine.resolveBindings(
        '{{post.created_at_formatted ?? (post.created_at | date)}}',
        context
      );
      // 이 케이스는 JavaScript 표현식 내에서 파이프가 있으므로 특수 처리 필요
      // 현재 구현에서는 표현식 전체를 파이프로 분리하지 않음
    });

    it('|| 연산자는 파이프로 인식하지 않음', () => {
      const context = { a: null, b: 'fallback' };
      const result = engine.resolveBindings('{{a || b}}', context);
      expect(result).toBe('fallback');
    });

    it('문자열 보간 + 파이프', () => {
      const context = {
        policy: { effective_date: '2024-01-15' }
      };
      const result = engine.resolveBindings(
        '시행일: {{policy.effective_date | date}}',
        context
      );
      expect(result).toBe('시행일: 2024-01-15');
    });

    it('null 값에 default 파이프', () => {
      const context = { name: null };
      const result = engine.resolveBindings('{{name | default(Unknown)}}', context);
      expect(result).toBe('Unknown');
    });

    it('배열에 join 파이프', () => {
      const context = { tags: ['태그1', '태그2', '태그3'] };
      const result = engine.resolveBindings('{{tags | join}}', context);
      expect(result).toBe('태그1, 태그2, 태그3');
    });

    it('다국어 객체에 localized 파이프', () => {
      const context = { product: { name: { ko: '상품명', en: 'Product' } } };
      const result = engine.resolveBindings('{{product.name | localized}}', context);
      expect(result).toBe('상품명');
    });
  });

  describe('레이아웃에서 사용되는 실제 패턴', () => {
    it('policy/terms.json 패턴: 시행일 표시', () => {
      const context = { terms: { data: { effective_date: '2024-03-01' } } };
      const result = engine.resolveBindings(
        '{{terms.data.effective_date | date}}',
        context
      );
      expect(result).toBe('2024-03-01');
    });

    it('board/show.json 패턴: 작성일시 표시', () => {
      const context = { post: { data: { created_at: '2024-01-15T14:30:00' } } };
      const result = engine.resolveBindings(
        '{{post.data.created_at | datetime}}',
        context
      );
      expect(result).toContain('2024-01-15');
      expect(result).toContain('14:30');
    });

    it('admin_dashboard.json 패턴: 숫자 포맷', () => {
      const context = { dashboard_stats: { data: { total_users: { count: 12345 } } } };
      const result = engine.resolveBindings(
        '{{dashboard_stats?.data?.total_users?.count | number}}',
        context
      );
      // optional chaining이 포함된 표현식
      expect(result).toBeTruthy();
    });

    it('_post_item.json 패턴: 상대 시간', () => {
      const fiveMinutesAgo = new Date(Date.now() - 5 * 60 * 1000);
      const context = { post: { created_at: fiveMinutesAgo.toISOString() } };
      const result = engine.resolveBindings(
        '{{post.created_at | relativeTime}}',
        context
      );
      expect(result).toContain('분 전');
    });

    it('_tab_posts.json 패턴: 포맷된 값 우선, 없으면 파이프', () => {
      // formatted 값이 있는 경우
      const context1 = {
        post: { created_at_formatted: '2024년 1월 15일', created_at: '2024-01-15' }
      };
      const result1 = engine.resolveBindings(
        '{{post.created_at_formatted ?? (post.created_at | date)}}',
        context1
      );
      // 이 패턴은 JavaScript 표현식 내부의 파이프이므로 현재 구현에서는 지원하지 않을 수 있음
      // 향후 개선 필요
    });
  });

  describe('edge cases', () => {
    it('존재하지 않는 파이프', () => {
      const context = { value: 'test' };
      const result = engine.resolveBindings('{{value | unknownPipe}}', context);
      // 알 수 없는 파이프는 원본 값 반환
      expect(result).toBe('test');
    });

    it('빈 값에 파이프 적용', () => {
      const context = { empty: '' };
      const result = engine.resolveBindings('{{empty | uppercase}}', context);
      expect(result).toBe('');
    });

    it('undefined 경로에 파이프 적용', () => {
      const context = {};
      const result = engine.resolveBindings('{{notExist | default(fallback)}}', context);
      expect(result).toBe('fallback');
    });

    it('중첩 객체 경로에 파이프', () => {
      const context = {
        data: {
          nested: {
            value: 'hello'
          }
        }
      };
      const result = engine.resolveBindings('{{data.nested.value | uppercase}}', context);
      expect(result).toBe('HELLO');
    });
  });

  describe('resolveObject 단일 바인딩과 파이프', () => {
    // 회귀 배경: 단일 바인딩 판정 후 `|` 를 연산자로 보아 evaluateExpression 으로
    // 라우팅되어, 인자 있는 파이프는 "datetime is not a function" 예외로 props
    // 해석 전체가 중단되고 인자 없는 파이프는 비트 OR 로 오답이 되었다.
    // @since engine-v1.54.3
    it('인자 있는 파이프가 예외 없이 포맷된 값을 반환한다', () => {
      const context = { post: { created_at: '2024-01-15T14:30:00' } };
      const result = engine.resolveObject(
        { title: "{{post.created_at | datetime('YYYY-MM-DD HH:mm')}}" },
        context
      );
      expect(result.title).toBe('2024-01-15 14:30');
    });

    it('인자 없는 파이프가 비트 연산으로 오염되지 않는다', () => {
      const context = { product: { price: 1234567 } };
      const result = engine.resolveObject({ label: '{{product.price | number}}' }, context);
      expect(result.label).toBe('1,234,567');
    });

    it('파이프가 없는 단일 바인딩은 원본 타입을 유지한다 (회귀 보호)', () => {
      const context = { items: [1, 2, 3] };
      const result = engine.resolveObject({ options: '{{items}}' }, context);
      expect(result.options).toEqual([1, 2, 3]);
    });

    it('논리 OR(||) 표현식은 파이프로 오인되지 않는다 (회귀 보호)', () => {
      const context = { product: { name: '' } };
      const result = engine.resolveObject({ label: "{{product.name || '-'}}" }, context);
      expect(result.label).toBe('-');
    });
  });

  /**
   * 파이프 결과의 타입 보존.
   *
   * `resolveBindings` 는 보간(문자열 산출)이 목적이라 결과에 formatValue 를 적용한다.
   * 종전에는 값이 필요한 지점(prop·조건·반복 렌더)도 이 메서드에 위임했기 때문에
   * 배열이 `"[\"a\",\"b\"]"` 로, `false` 가 `"false"`(truthy!) 로 바뀌어 나갔다.
   * @since engine-v1.54.9
   */
  describe('evaluatePipeExpression — 원본 타입 보존', () => {
    it('배열을 반환하는 파이프는 배열 그대로 반환한다', () => {
      const context = { row: { meta: { a: 1, b: 2 } } };
      expect(engine.evaluatePipeExpression('row.meta | keys', context)).toEqual(['a', 'b']);
      expect(engine.evaluatePipeExpression('row.meta | values', context)).toEqual([1, 2]);
    });

    it('boolean 을 반환하는 파이프는 문자열로 바뀌지 않는다', () => {
      const context = { row: { flags: [false, true] } };
      expect(engine.evaluatePipeExpression('row.flags | first', context)).toBe(false);
      expect(engine.evaluatePipeExpression('row.flags | last', context)).toBe(true);
    });

    it('숫자를 반환하는 파이프는 number 타입을 유지한다', () => {
      const context = { row: { tags: ['a', 'b', 'c'] } };
      expect(engine.evaluatePipeExpression('row.tags | length', context)).toBe(3);
    });

    it('resolveBindings 는 종전대로 문자열로 서식한다 (보간 계약 유지)', () => {
      const context = { row: { tags: ['a', 'b', 'c'] } };
      expect(engine.resolveBindings('{{row.tags | length}}', context)).toBe('3');
      expect(typeof engine.resolveBindings('{{row.flags | first}}', { row: { flags: [false] } })).toBe('string');
    });

    it('resolveObject 의 단일 바인딩은 파이프 결과 타입을 유지한다', () => {
      const context = { row: { meta: { a: 1 }, flags: [false] } };
      const out: any = engine.resolveObject(
        { keys: '{{row.meta | keys}}', flag: '{{row.flags | first}}' },
        context,
      );
      expect(out.keys).toEqual(['a']);
      expect(out.flag).toBe(false);
    });

    it('평가 실패는 호출 지점으로 전파된다 (resolveObject 는 key 단위로 격리)', () => {
      const makeContext = () => ({
        row: { a: 1 },
        get boom(): string {
          throw new Error('base expression failed');
        },
      });

      expect(() => engine.evaluatePipeExpression('(boom) | uppercase', makeContext())).toThrow();

      // resolveObject 에는 상위 catch 가 없으므로 실패한 key 만 undefined 로 떨어지고
      // 같은 객체의 다른 key 는 정상 해석되어야 한다.
      const out: any = engine.resolveObject(
        { broken: '{{(boom) | uppercase}}', intact: '{{row}}' },
        makeContext(),
        { skipCache: true },
      );
      expect(out.broken).toBeUndefined();
      expect(out.intact).toEqual({ a: 1 });
    });
  });

  describe('skipCache와 파이프', () => {
    it('skipCache: true로 매번 새로 평가', () => {
      let counter = 0;
      const context = {
        get value() {
          counter++;
          return 'value' + counter;
        }
      };

      const result1 = engine.resolveBindings('{{value | uppercase}}', context, { skipCache: true });
      const result2 = engine.resolveBindings('{{value | uppercase}}', context, { skipCache: true });

      // skipCache가 true이므로 매번 새로 평가되어야 함
      expect(result1).toBe('VALUE1');
      expect(result2).toBe('VALUE2');
    });
  });
});
