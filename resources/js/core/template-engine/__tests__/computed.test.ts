/**
 * computed 속성 테스트
 *
 * 레이아웃 수준에서 재사용 가능한 계산된 값 기능 테스트
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { DataBindingEngine } from '../DataBindingEngine';

describe('computed 속성', () => {
  let engine: DataBindingEngine;

  beforeEach(() => {
    engine = new DataBindingEngine();
  });

  describe('문자열 표현식 computed', () => {
    it('단순 바인딩 표현식 평가', () => {
      const context = {
        user: { firstName: '홍', lastName: '길동' },
        _computed: {},
      };

      // 문자열 표현식 평가 (TemplateApp.calculateComputed에서 처리)
      const expression = 'user.firstName + " " + user.lastName';
      const result = engine.evaluateExpression(expression, context);

      expect(result).toBe('홍 길동');
    });

    it('조건 표현식 평가', () => {
      const context = {
        user: { role: 'admin' },
      };

      const expression = "user.role === 'admin'";
      const result = engine.evaluateExpression(expression, context);

      expect(result).toBe(true);
    });

    it('복잡한 연산 표현식 평가', () => {
      const context = {
        items: [
          { price: 100 },
          { price: 200 },
          { price: 300 },
        ],
      };

      const expression = 'items.reduce((sum, i) => sum + i.price, 0)';
      const result = engine.evaluateExpression(expression, context);

      expect(result).toBe(600);
    });
  });

  describe('$switch 형태 computed', () => {
    it('$switch computed 정의 감지', () => {
      const switchDef = {
        $switch: '{{status}}',
        $cases: {
          active: 'text-green-500',
          inactive: 'text-gray-500',
        },
        $default: 'text-gray-400',
      };

      expect(engine.isSwitchExpression(switchDef)).toBe(true);
    });

    it('$switch computed 값 해석', () => {
      const switchDef = {
        $switch: '{{status}}',
        $cases: {
          active: 'text-green-500',
          inactive: 'text-gray-500',
        },
        $default: 'text-gray-400',
      };

      const context = { status: 'active' };
      const result = engine.resolveSwitch(switchDef, context, { skipCache: true });

      expect(result).toBe('text-green-500');
    });

    it('$switch computed에서 매칭되지 않으면 $default 반환', () => {
      const switchDef = {
        $switch: '{{status}}',
        $cases: {
          active: 'text-green-500',
          inactive: 'text-gray-500',
        },
        $default: 'text-gray-400',
      };

      const context = { status: 'pending' };
      const result = engine.resolveSwitch(switchDef, context, { skipCache: true });

      expect(result).toBe('text-gray-400');
    });

    it('$switch computed에서 중첩 경로 접근', () => {
      const switchDef = {
        $switch: '{{row.data.status_variant}}',
        $cases: {
          success: 'bg-green-100 text-green-800',
          danger: 'bg-red-100 text-red-800',
          warning: 'bg-yellow-100 text-yellow-800',
        },
        $default: 'bg-gray-100 text-gray-600',
      };

      const context = {
        row: { data: { status_variant: 'success' } },
      };
      const result = engine.resolveSwitch(switchDef, context, { skipCache: true });

      expect(result).toBe('bg-green-100 text-green-800');
    });
  });

  describe('_computed 컨텍스트 접근', () => {
    it('_computed에서 값 접근', () => {
      const context = {
        _computed: {
          displayPrice: '10,000원',
          canEdit: true,
          statusClass: 'text-green-500',
        },
      };

      const result = engine.evaluateExpression('_computed.displayPrice', context);
      expect(result).toBe('10,000원');
    });

    it('_computed 값을 다른 표현식에서 사용', () => {
      const context = {
        _computed: {
          canEdit: true,
          canDelete: false,
        },
      };

      const result = engine.evaluateExpression('_computed.canEdit && !_computed.canDelete', context);
      expect(result).toBe(true);
    });

    it('_computed가 없어도 안전하게 처리', () => {
      const context = {};

      const result = engine.evaluateExpression('_computed?.displayPrice', context);
      expect(result).toBeUndefined();
    });
  });

  describe('$computed alias 지원', () => {
    it('$computed로 _computed 값에 접근 (evaluateExpression)', () => {
      const context = {
        _computed: {
          filterStartDate: '2026-01-14',
          filterMinPrice: '100',
        },
      };

      // $computed.xxx는 _computed.xxx의 alias로 동작해야 함
      const result = engine.evaluateExpression('$computed.filterStartDate', context);
      expect(result).toBe('2026-01-14');
    });

    it('$computed로 _computed 값에 접근 (resolveBindings)', () => {
      const context = {
        _computed: {
          filterStartDate: '2026-01-14',
          filterMinPrice: '100',
        },
      };

      // {{$computed.xxx}} 바인딩 표현식에서도 동작해야 함
      const result = engine.resolveBindings('{{$computed.filterMinPrice}}', context);
      expect(result).toBe('100');
    });

    it('$computed와 || 연산자 조합', () => {
      const context = {
        _computed: {
          filterStartDate: '2026-01-14',
          filterEndDate: '',
        },
      };

      // {{$computed.xxx || null}} 패턴 지원
      const result1 = engine.evaluateExpression('$computed.filterStartDate || null', context);
      expect(result1).toBe('2026-01-14');

      const result2 = engine.evaluateExpression('$computed.filterEndDate || null', context);
      expect(result2).toBeNull();
    });

    it('$computed와 다른 컨텍스트 값 조합', () => {
      const context = {
        _computed: {
          canEdit: true,
        },
        user: {
          role: 'admin',
        },
      };

      const result = engine.evaluateExpression("$computed.canEdit && user.role === 'admin'", context);
      expect(result).toBe(true);
    });

    it('$computed가 이미 존재하면 덮어쓰지 않음', () => {
      const context = {
        _computed: {
          value: 'from _computed',
        },
        $computed: {
          value: 'from $computed',
        },
      };

      // $computed가 이미 정의되어 있으면 해당 값 사용
      const result = engine.evaluateExpression('$computed.value', context);
      expect(result).toBe('from $computed');
    });
  });

  describe('실제 레이아웃 패턴', () => {
    it('다중 통화 가격 computed', () => {
      const context = {
        _global: { preferredCurrency: 'USD' },
        product: {
          multi_currency_selling_price: {
            KRW: { formatted: '10,000원' },
            USD: { formatted: '$10.00' },
          },
          selling_price_formatted: '10,000원',
        },
      };

      // $get을 사용한 computed 표현식
      const result = engine.evaluateExpression(
        "$get(product.multi_currency_selling_price, [_global.preferredCurrency, 'formatted'], product.selling_price_formatted)",
        context
      );

      expect(result).toBe('$10.00');
    });

    it('권한 기반 computed', () => {
      const context = {
        post: {
          deleted_at: null,
          permissions: { can_edit: true, can_delete: false },
        },
      };

      const canEdit = engine.evaluateExpression(
        '!post.deleted_at && post.permissions?.can_edit',
        context
      );
      expect(canEdit).toBe(true);

      const canDelete = engine.evaluateExpression(
        '!post.deleted_at && post.permissions?.can_delete',
        context
      );
      expect(canDelete).toBe(false);
    });

    it('상태 배지 클래스 computed ($switch 활용)', () => {
      const switchDef = {
        $switch: '{{row.status_variant}}',
        $cases: {
          success: 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400',
          danger: 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400',
          warning: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400',
        },
        $default: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
      };

      const testCases = [
        { status_variant: 'success', expected: 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400' },
        { status_variant: 'danger', expected: 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400' },
        { status_variant: 'unknown', expected: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' },
      ];

      for (const { status_variant, expected } of testCases) {
        const context = { row: { status_variant } };
        const result = engine.resolveSwitch(switchDef, context, { skipCache: true });
        expect(result).toBe(expected);
      }
    });
  });

  describe('defines와 computed 조합', () => {
    it('_defines 값을 computed에서 참조', () => {
      const context = {
        _defines: {
          currencyFlagMap: {
            KRW: 'kr',
            USD: 'us',
            JPY: 'jp',
          },
        },
        currency: { code: 'USD' },
      };

      const result = engine.evaluateExpression(
        "_defines.currencyFlagMap[currency.code] ?? 'xx'",
        context
      );

      expect(result).toBe('us');
    });

    it('존재하지 않는 _defines 키에서 폴백', () => {
      const context = {
        _defines: {
          currencyFlagMap: {
            KRW: 'kr',
            USD: 'us',
          },
        },
        currency: { code: 'EUR' },
      };

      const result = engine.evaluateExpression(
        "_defines.currencyFlagMap[currency.code] ?? 'xx'",
        context
      );

      expect(result).toBe('xx');
    });
  });

  describe('iteration 컨텍스트에서 computed 사용', () => {
    it('각 row별 다른 computed 결과', () => {
      const switchDef = {
        $switch: '{{row.status}}',
        $cases: {
          pending: 'clock',
          completed: 'check',
          failed: 'x',
        },
        $default: 'help-circle',
      };

      const rows = [
        { status: 'pending' },
        { status: 'completed' },
        { status: 'failed' },
        { status: 'unknown' },
      ];

      const results = rows.map(row => {
        const context = { row };
        return engine.resolveSwitch(switchDef, context, { skipCache: true });
      });

      expect(results[0]).toBe('clock');
      expect(results[1]).toBe('check');
      expect(results[2]).toBe('x');
      expect(results[3]).toBe('help-circle');
    });

    it('Stale Closure 방지 - 상태 변경 후 즉시 반영', () => {
      const switchDef = {
        $switch: '{{currentTab}}',
        $cases: {
          tab1: 'Tab 1 Content',
          tab2: 'Tab 2 Content',
        },
        $default: 'Default Content',
      };

      // 초기 상태
      const context1 = { currentTab: 'tab1' };
      expect(engine.resolveSwitch(switchDef, context1, { skipCache: true })).toBe('Tab 1 Content');

      // 상태 변경 후
      const context2 = { currentTab: 'tab2' };
      expect(engine.resolveSwitch(switchDef, context2, { skipCache: true })).toBe('Tab 2 Content');

      // 다시 변경
      const context3 = { currentTab: 'tab1' };
      expect(engine.resolveSwitch(switchDef, context3, { skipCache: true })).toBe('Tab 1 Content');
    });
  });

  describe('엣지 케이스', () => {
    it('빈 _computed 객체', () => {
      const context = {
        _computed: {},
      };

      const result = engine.evaluateExpression('_computed.nonexistent', context);
      expect(result).toBeUndefined();
    });

    it('computed에서 다른 computed 참조', () => {
      const context = {
        _computed: {
          firstName: '홍',
          lastName: '길동',
          fullName: undefined, // 순환 참조 방지를 위해 직접 계산
        },
      };

      // 실제로는 TemplateApp에서 순차적으로 계산되므로 이전 computed 값 참조 가능
      // 여기서는 단순히 _computed 접근 테스트
      const result = engine.evaluateExpression('_computed.firstName + " " + _computed.lastName', context);
      expect(result).toBe('홍 길동');
    });

    it('null/undefined 값 computed', () => {
      const context = {
        user: null,
      };

      const result = engine.evaluateExpression('user?.name ?? "Unknown"', context);
      expect(result).toBe('Unknown');
    });

    it('computed 결과가 객체인 경우', () => {
      const switchDef = {
        $switch: '{{level}}',
        $cases: {
          error: { color: 'red', icon: 'alert-circle' },
          warning: { color: 'yellow', icon: 'alert-triangle' },
        },
        $default: { color: 'gray', icon: 'info' },
      };

      const context = { level: 'error' };
      const result = engine.resolveSwitch(switchDef, context, { skipCache: true });

      expect(result).toEqual({ color: 'red', icon: 'alert-circle' });
    });

    it('computed 결과가 배열인 경우', () => {
      const context = {
        items: [1, 2, 3, 4, 5],
      };

      const result = engine.evaluateExpression('items.filter(i => i > 2)', context);
      expect(result).toEqual([3, 4, 5]);
    });
  });

  describe('_computed prop 캐시 격리', () => {
    /**
     * 버그 시나리오: 컴포넌트 간 영구 캐시 공유로 인한 stale _computed 값
     *
     * 1. 컴포넌트 A (base layout)가 먼저 렌더 → _computed.isReadOnly = false (캐시됨)
     * 2. 컴포넌트 B (settings form)가 나중 렌더 → 캐시에서 false 반환 (실제는 true)
     * 3. if 조건은 skipCache: true로 정확하지만, disabled prop은 stale cache 사용
     *
     * 수정: DynamicRenderer.tsx에서 _computed 참조 prop에 skipCache: true 적용
     */
    it('resolve()에서 _computed 경로는 skipCache로 컴포넌트별 정확한 값 반환', () => {
      // 컴포넌트 A의 컨텍스트 (_computed.isReadOnly = false)
      const contextA = {
        _computed: { isReadOnly: false },
      };

      // 컴포넌트 B의 컨텍스트 (_computed.isReadOnly = true)
      const contextB = {
        _computed: { isReadOnly: true },
      };

      // 렌더 사이클 활성화 (영구 캐시 사용 조건)
      engine.startRenderCycle();

      // 컴포넌트 A가 먼저 resolve → false가 영구 캐시에 저장
      const resultA = engine.resolve('_computed.isReadOnly', contextA);
      expect(resultA).toBe(false);

      // 컴포넌트 B가 skipCache 없이 resolve → 캐시에서 false 반환 (버그 재현)
      const resultB_cached = engine.resolve('_computed.isReadOnly', contextB);
      expect(resultB_cached).toBe(false); // 캐시로 인한 stale 값

      // skipCache로 resolve → 정확한 true 반환 (수정 후 동작)
      const resultB_skipCache = engine.resolve('_computed.isReadOnly', contextB, { skipCache: true });
      expect(resultB_skipCache).toBe(true);
    });

    it('resolve()에서 $computed 경로도 skipCache로 정확한 값 반환', () => {
      const contextA = {
        _computed: { canEdit: false },
        $computed: { canEdit: false },
      };
      const contextB = {
        _computed: { canEdit: true },
        $computed: { canEdit: true },
      };

      engine.startRenderCycle();

      // 컴포넌트 A가 먼저 캐시
      engine.resolve('$computed.canEdit', contextA);

      // skipCache로 컴포넌트 B의 정확한 값 반환
      const result = engine.resolve('$computed.canEdit', contextB, { skipCache: true });
      expect(result).toBe(true);
    });

    it('if 조건의 skipCache와 동일한 결과 보장', () => {
      const contextReadOnly = {
        _computed: { isReadOnly: true },
      };
      const contextEditable = {
        _computed: { isReadOnly: false },
      };

      engine.startRenderCycle();

      // 편집 가능 컴포넌트가 먼저 캐시
      engine.resolve('_computed.isReadOnly', contextEditable);

      // if 조건 경로 (항상 skipCache) — 정확한 true
      const ifResult = engine.resolve('_computed.isReadOnly', contextReadOnly, { skipCache: true });
      expect(ifResult).toBe(true);

      // 수정 전: disabled prop 경로 (캐시) — stale false
      const propResult_cached = engine.resolve('_computed.isReadOnly', contextReadOnly);
      expect(propResult_cached).toBe(false); // 캐시된 stale 값

      // 수정 후: disabled prop 경로 (skipCache) — 정확한 true
      const propResult_fixed = engine.resolve('_computed.isReadOnly', contextReadOnly, { skipCache: true });
      expect(propResult_fixed).toBe(true); // if 조건과 동일한 결과
    });

    it('_computed가 아닌 일반 경로는 캐시를 유지', () => {
      const contextA = { _local: { name: 'A' } };
      const contextB = { _local: { name: 'B' } };

      engine.startRenderCycle();

      // 일반 경로는 캐시 사용 (기존 동작 유지)
      const resultA = engine.resolve('_local.name', contextA);
      expect(resultA).toBe('A');

      // 캐시에서 A 반환 (일반 경로는 캐시 동작 변경 없음)
      const resultB = engine.resolve('_local.name', contextB);
      expect(resultB).toBe('A'); // 캐시된 값
    });
  });
});
