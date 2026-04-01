/**
 * classMap 테스트
 *
 * 조건부 스타일 매핑 기능 테스트
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { resolveClassMap, ClassMapDefinition } from '../helpers/RenderHelpers';
import { DataBindingEngine } from '../DataBindingEngine';

describe('resolveClassMap', () => {
  let bindingEngine: DataBindingEngine;

  beforeEach(() => {
    bindingEngine = new DataBindingEngine();
  });

  describe('기본 동작', () => {
    it('key 값에 해당하는 variant 클래스 반환', () => {
      const classMap: ClassMapDefinition = {
        variants: {
          success: 'bg-green-100 text-green-800',
          danger: 'bg-red-100 text-red-800',
          warning: 'bg-yellow-100 text-yellow-800',
        },
        key: '{{status}}',
      };

      const context = { status: 'success' };
      const result = resolveClassMap(classMap, context, bindingEngine);

      expect(result).toBe('bg-green-100 text-green-800');
    });

    it('base와 variant 클래스 결합', () => {
      const classMap: ClassMapDefinition = {
        base: 'px-2 py-1 rounded-full text-xs font-medium',
        variants: {
          success: 'bg-green-100 text-green-800',
          danger: 'bg-red-100 text-red-800',
        },
        key: '{{row.status}}',
      };

      const context = { row: { status: 'danger' } };
      const result = resolveClassMap(classMap, context, bindingEngine);

      expect(result).toBe('px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800');
    });

    it('매칭되지 않으면 default 클래스 사용', () => {
      const classMap: ClassMapDefinition = {
        base: 'badge',
        variants: {
          success: 'badge-success',
          danger: 'badge-danger',
        },
        key: '{{status}}',
        default: 'badge-default',
      };

      const context = { status: 'unknown' };
      const result = resolveClassMap(classMap, context, bindingEngine);

      expect(result).toBe('badge badge-default');
    });

    it('매칭되지 않고 default도 없으면 base만 반환', () => {
      const classMap: ClassMapDefinition = {
        base: 'badge',
        variants: {
          success: 'badge-success',
        },
        key: '{{status}}',
      };

      const context = { status: 'unknown' };
      const result = resolveClassMap(classMap, context, bindingEngine);

      expect(result).toBe('badge');
    });
  });

  describe('복잡한 key 표현식', () => {
    it('중첩 경로 지원', () => {
      const classMap: ClassMapDefinition = {
        variants: {
          active: 'text-blue-500',
          inactive: 'text-gray-500',
        },
        key: '{{user.profile.status}}',
      };

      const context = { user: { profile: { status: 'active' } } };
      const result = resolveClassMap(classMap, context, bindingEngine);

      expect(result).toBe('text-blue-500');
    });

    it('optional chaining 지원', () => {
      const classMap: ClassMapDefinition = {
        variants: {
          active: 'text-blue-500',
        },
        key: '{{user?.status}}',
        default: 'text-gray-500',
      };

      const context = { user: null };
      const result = resolveClassMap(classMap, context, bindingEngine);

      expect(result).toBe('text-gray-500');
    });

    it('삼항 연산자 표현식 지원', () => {
      const classMap: ClassMapDefinition = {
        variants: {
          true: 'visible',
          false: 'hidden',
        },
        key: "{{isVisible ? 'true' : 'false'}}",
      };

      const context = { isVisible: true };
      const result = resolveClassMap(classMap, context, bindingEngine);

      expect(result).toBe('visible');
    });
  });

  describe('다크 모드 클래스', () => {
    it('다크 모드 클래스 포함 처리', () => {
      const classMap: ClassMapDefinition = {
        base: 'px-2 py-1 rounded-full text-xs font-medium',
        variants: {
          success: 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400',
          danger: 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400',
        },
        key: '{{status}}',
        default: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
      };

      const context = { status: 'success' };
      const result = resolveClassMap(classMap, context, bindingEngine);

      expect(result).toContain('dark:bg-green-900/20');
      expect(result).toContain('dark:text-green-400');
    });
  });

  describe('iteration 컨텍스트', () => {
    it('각 row별 다른 클래스 적용', () => {
      const classMap: ClassMapDefinition = {
        base: 'status-badge',
        variants: {
          pending: 'bg-yellow-100',
          completed: 'bg-green-100',
          failed: 'bg-red-100',
        },
        key: '{{row.status}}',
      };

      const rows = [
        { status: 'pending' },
        { status: 'completed' },
        { status: 'failed' },
      ];

      const results = rows.map(row => resolveClassMap(classMap, { row }, bindingEngine));

      expect(results[0]).toBe('status-badge bg-yellow-100');
      expect(results[1]).toBe('status-badge bg-green-100');
      expect(results[2]).toBe('status-badge bg-red-100');
    });

    it('skipCache 기본 활성화 (캐싱 방지)', () => {
      const classMap: ClassMapDefinition = {
        variants: {
          a: 'class-a',
          b: 'class-b',
        },
        key: '{{item.type}}',
      };

      // 같은 표현식이지만 다른 컨텍스트
      const result1 = resolveClassMap(classMap, { item: { type: 'a' } }, bindingEngine);
      const result2 = resolveClassMap(classMap, { item: { type: 'b' } }, bindingEngine);

      // 캐싱되지 않고 각각 다른 결과 반환
      expect(result1).toBe('class-a');
      expect(result2).toBe('class-b');
    });
  });

  describe('엣지 케이스', () => {
    it('빈 key 값 처리', () => {
      const classMap: ClassMapDefinition = {
        base: 'base-class',
        variants: {
          valid: 'valid-class',
        },
        key: '{{emptyValue}}',
        default: 'default-class',
      };

      const context = { emptyValue: '' };
      const result = resolveClassMap(classMap, context, bindingEngine);

      expect(result).toBe('base-class default-class');
    });

    it('undefined key 값 처리', () => {
      const classMap: ClassMapDefinition = {
        variants: {
          valid: 'valid-class',
        },
        key: '{{undefinedValue}}',
        default: 'fallback-class',
      };

      const context = {};
      const result = resolveClassMap(classMap, context, bindingEngine);

      expect(result).toBe('fallback-class');
    });

    it('null key 값 처리', () => {
      const classMap: ClassMapDefinition = {
        variants: {
          valid: 'valid-class',
        },
        key: '{{nullValue}}',
        default: 'fallback-class',
      };

      const context = { nullValue: null };
      const result = resolveClassMap(classMap, context, bindingEngine);

      expect(result).toBe('fallback-class');
    });

    it('숫자 key 값 처리 (문자열로 변환)', () => {
      const classMap: ClassMapDefinition = {
        variants: {
          '1': 'one-class',
          '2': 'two-class',
          '3': 'three-class',
        },
        key: '{{level}}',
      };

      const context = { level: 2 };
      const result = resolveClassMap(classMap, context, bindingEngine);

      expect(result).toBe('two-class');
    });

    it('base만 있는 경우', () => {
      const classMap: ClassMapDefinition = {
        base: 'only-base',
        variants: {},
        key: '{{status}}',
      };

      const context = { status: 'anything' };
      const result = resolveClassMap(classMap, context, bindingEngine);

      expect(result).toBe('only-base');
    });

    it('base 없이 variant만 있는 경우', () => {
      const classMap: ClassMapDefinition = {
        variants: {
          active: 'active-only',
        },
        key: '{{status}}',
      };

      const context = { status: 'active' };
      const result = resolveClassMap(classMap, context, bindingEngine);

      expect(result).toBe('active-only');
    });

    it('공백 처리', () => {
      const classMap: ClassMapDefinition = {
        base: '  spaced-base  ',
        variants: {
          active: '  spaced-variant  ',
        },
        key: '{{status}}',
      };

      const context = { status: 'active' };
      const result = resolveClassMap(classMap, context, bindingEngine);

      expect(result).toBe('spaced-base spaced-variant');
    });
  });

  describe('실제 레이아웃 패턴', () => {
    it('DataGrid 상태 배지 패턴', () => {
      const classMap: ClassMapDefinition = {
        base: 'px-2 py-1 rounded-full text-xs font-medium inline-flex items-center',
        variants: {
          on_sale: 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400',
          pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400',
          out_of_stock: 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400',
          discontinued: 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-400',
        },
        key: '{{row.sales_status}}',
        default: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
      };

      const onSaleRow = { row: { sales_status: 'on_sale' } };
      expect(resolveClassMap(classMap, onSaleRow, bindingEngine)).toContain('bg-green-100');

      const pendingRow = { row: { sales_status: 'pending' } };
      expect(resolveClassMap(classMap, pendingRow, bindingEngine)).toContain('bg-yellow-100');

      const unknownRow = { row: { sales_status: 'unknown_status' } };
      expect(resolveClassMap(classMap, unknownRow, bindingEngine)).toContain('bg-gray-100');
    });

    it('탭 선택 상태 패턴', () => {
      const classMap: ClassMapDefinition = {
        base: 'px-4 py-2 text-sm font-medium border-b-2 transition-colors',
        variants: {
          true: 'border-blue-500 text-blue-600 dark:text-blue-400',
          false: 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400',
        },
        key: "{{_local.activeTab === tab.id ? 'true' : 'false'}}",
      };

      const activeContext = { _local: { activeTab: 'settings' }, tab: { id: 'settings' } };
      const inactiveContext = { _local: { activeTab: 'settings' }, tab: { id: 'profile' } };

      expect(resolveClassMap(classMap, activeContext, bindingEngine)).toContain('border-blue-500');
      expect(resolveClassMap(classMap, inactiveContext, bindingEngine)).toContain('border-transparent');
    });
  });
});
