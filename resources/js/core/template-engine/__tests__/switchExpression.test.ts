/**
 * $switch 표현식 테스트
 *
 * 선언적 분기 표현식 기능 테스트
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { DataBindingEngine } from '../DataBindingEngine';

describe('$switch 표현식', () => {
  let engine: DataBindingEngine;

  beforeEach(() => {
    engine = new DataBindingEngine();
  });

  describe('isSwitchExpression', () => {
    it('유효한 $switch 객체 감지', () => {
      expect(engine.isSwitchExpression({
        $switch: '{{tab}}',
        $cases: { a: 'A', b: 'B' },
      })).toBe(true);
    });

    it('$default 있어도 유효', () => {
      expect(engine.isSwitchExpression({
        $switch: '{{status}}',
        $cases: { active: 'active-class' },
        $default: 'default-class',
      })).toBe(true);
    });

    it('$switch 없으면 false', () => {
      expect(engine.isSwitchExpression({
        $cases: { a: 'A' },
        $default: 'D',
      })).toBe(false);
    });

    it('$cases 없으면 false', () => {
      expect(engine.isSwitchExpression({
        $switch: '{{tab}}',
        $default: 'D',
      })).toBe(false);
    });

    it('배열은 false', () => {
      expect(engine.isSwitchExpression([{ $switch: '{{a}}', $cases: {} }])).toBe(false);
    });

    it('null은 false', () => {
      expect(engine.isSwitchExpression(null)).toBe(false);
    });

    it('일반 컴포넌트 정의는 false', () => {
      expect(engine.isSwitchExpression({
        type: 'basic',
        name: 'Div',
        props: {},
      })).toBe(false);
    });
  });

  describe('resolveSwitch 기본 동작', () => {
    it('매칭되는 case 값 반환', () => {
      const switchDef = {
        $switch: '{{tab}}',
        $cases: {
          products: 'shopping-bag',
          contents: 'book-open',
          policies: 'file-check',
        },
        $default: 'file-text',
      };

      const context = { tab: 'products' };
      const result = engine.resolveSwitch(switchDef, context);

      expect(result).toBe('shopping-bag');
    });

    it('다른 case 값 반환', () => {
      const switchDef = {
        $switch: '{{tab}}',
        $cases: {
          products: 'shopping-bag',
          contents: 'book-open',
          policies: 'file-check',
        },
        $default: 'file-text',
      };

      const context = { tab: 'contents' };
      const result = engine.resolveSwitch(switchDef, context);

      expect(result).toBe('book-open');
    });

    it('매칭 안되면 $default 반환', () => {
      const switchDef = {
        $switch: '{{tab}}',
        $cases: {
          products: 'shopping-bag',
          contents: 'book-open',
        },
        $default: 'file-text',
      };

      const context = { tab: 'unknown' };
      const result = engine.resolveSwitch(switchDef, context);

      expect(result).toBe('file-text');
    });

    it('$default 없고 매칭 안되면 undefined 반환', () => {
      const switchDef = {
        $switch: '{{tab}}',
        $cases: {
          products: 'shopping-bag',
        },
      };

      const context = { tab: 'unknown' };
      const result = engine.resolveSwitch(switchDef, context);

      expect(result).toBeUndefined();
    });
  });

  describe('복잡한 $switch 키 표현식', () => {
    it('중첩 경로 지원', () => {
      const switchDef = {
        $switch: '{{row.data.status}}',
        $cases: {
          active: 'green',
          inactive: 'gray',
        },
      };

      const context = { row: { data: { status: 'active' } } };
      const result = engine.resolveSwitch(switchDef, context);

      expect(result).toBe('green');
    });

    it('optional chaining 지원', () => {
      const switchDef = {
        $switch: '{{user?.status}}',
        $cases: {
          active: 'user-active',
        },
        $default: 'user-unknown',
      };

      const context = { user: null };
      const result = engine.resolveSwitch(switchDef, context);

      expect(result).toBe('user-unknown');
    });

    it('삼항 연산자로 키 동적 생성', () => {
      const switchDef = {
        $switch: "{{isDeleted ? 'deleted' : status}}",
        $cases: {
          deleted: 'badge-red',
          active: 'badge-green',
          pending: 'badge-yellow',
        },
        $default: 'badge-gray',
      };

      const context1 = { isDeleted: true, status: 'active' };
      expect(engine.resolveSwitch(switchDef, context1)).toBe('badge-red');

      const context2 = { isDeleted: false, status: 'pending' };
      expect(engine.resolveSwitch(switchDef, context2)).toBe('badge-yellow');
    });
  });

  describe('case 값에 바인딩 표현식', () => {
    it('case 값에 바인딩 표현식 해석', () => {
      const switchDef = {
        $switch: '{{type}}',
        $cases: {
          user: '{{user.name}}님',
          guest: '게스트',
        },
        $default: '알 수 없음',
      };

      const context = { type: 'user', user: { name: '홍길동' } };
      const result = engine.resolveSwitch(switchDef, context);

      expect(result).toBe('홍길동님');
    });

    it('$default에 바인딩 표현식', () => {
      const switchDef = {
        $switch: '{{status}}',
        $cases: {
          known: 'Known Value',
        },
        $default: '{{fallbackValue}}',
      };

      const context = { status: 'unknown', fallbackValue: 'Default from context' };
      const result = engine.resolveSwitch(switchDef, context);

      expect(result).toBe('Default from context');
    });
  });

  describe('case 값에 객체/중첩 구조', () => {
    it('case 값이 객체인 경우', () => {
      const switchDef = {
        $switch: '{{level}}',
        $cases: {
          error: { color: 'red', icon: 'alert-circle' },
          warning: { color: 'yellow', icon: 'alert-triangle' },
          info: { color: 'blue', icon: 'info' },
        },
        $default: { color: 'gray', icon: 'circle' },
      };

      const context = { level: 'error' };
      const result = engine.resolveSwitch(switchDef, context);

      expect(result).toEqual({ color: 'red', icon: 'alert-circle' });
    });

    it('case 객체 내부 바인딩 해석', () => {
      const switchDef = {
        $switch: '{{type}}',
        $cases: {
          dynamic: {
            label: '{{dynamicLabel}}',
            count: '{{count}}',
          },
        },
        $default: { label: 'Static', count: 0 },
      };

      const context = { type: 'dynamic', dynamicLabel: 'Dynamic Label', count: 42 };
      const result = engine.resolveSwitch(switchDef, context);

      // 단일 바인딩 {{count}}는 원본 타입(숫자)을 유지
      expect(result).toEqual({ label: 'Dynamic Label', count: 42 });
    });
  });

  describe('resolveObject 통합', () => {
    it('resolveObject가 $switch 객체를 자동 처리', () => {
      const obj = {
        icon: {
          $switch: '{{tab}}',
          $cases: {
            products: 'shopping-bag',
            contents: 'book-open',
          },
          $default: 'file-text',
        },
        label: '{{label}}',
      };

      const context = { tab: 'products', label: 'Products' };
      const result = engine.resolveObject(obj, context);

      expect(result.icon).toBe('shopping-bag');
      expect(result.label).toBe('Products');
    });

    it('중첩된 $switch 처리', () => {
      const obj = {
        style: {
          color: {
            $switch: '{{status}}',
            $cases: {
              success: 'green',
              error: 'red',
            },
            $default: 'gray',
          },
        },
      };

      const context = { status: 'success' };
      const result = engine.resolveObject(obj, context);

      expect(result.style.color).toBe('green');
    });
  });

  describe('iteration 컨텍스트 (캐싱 방지)', () => {
    it('각 row별 다른 결과 반환', () => {
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

      const results = rows.map(row => engine.resolveSwitch(switchDef, { row }));

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
      expect(engine.resolveSwitch(switchDef, context1)).toBe('Tab 1 Content');

      // 상태 변경 후
      const context2 = { currentTab: 'tab2' };
      expect(engine.resolveSwitch(switchDef, context2)).toBe('Tab 2 Content');

      // 다시 변경
      const context3 = { currentTab: 'tab1' };
      expect(engine.resolveSwitch(switchDef, context3)).toBe('Tab 1 Content');
    });
  });

  describe('엣지 케이스', () => {
    it('빈 $switch 키', () => {
      const switchDef = {
        $switch: '{{emptyValue}}',
        $cases: {
          valid: 'valid-result',
        },
        $default: 'fallback',
      };

      const context = { emptyValue: '' };
      const result = engine.resolveSwitch(switchDef, context);

      expect(result).toBe('fallback');
    });

    it('숫자 키 값 (문자열로 변환)', () => {
      const switchDef = {
        $switch: '{{level}}',
        $cases: {
          '1': 'Level One',
          '2': 'Level Two',
          '3': 'Level Three',
        },
        $default: 'Unknown Level',
      };

      const context = { level: 2 };
      const result = engine.resolveSwitch(switchDef, context);

      expect(result).toBe('Level Two');
    });

    it('boolean 키 값', () => {
      const switchDef = {
        $switch: '{{isActive}}',
        $cases: {
          true: 'Active',
          false: 'Inactive',
        },
      };

      const context = { isActive: true };
      const result = engine.resolveSwitch(switchDef, context);

      expect(result).toBe('Active');
    });

    it('undefined 컨텍스트 값', () => {
      const switchDef = {
        $switch: '{{missingKey}}',
        $cases: {
          value: 'Has Value',
        },
        $default: 'No Value',
      };

      const context = {};
      const result = engine.resolveSwitch(switchDef, context);

      expect(result).toBe('No Value');
    });

    it('null 컨텍스트 값', () => {
      const switchDef = {
        $switch: '{{nullKey}}',
        $cases: {
          value: 'Has Value',
        },
        $default: 'Null Value',
      };

      const context = { nullKey: null };
      const result = engine.resolveSwitch(switchDef, context);

      expect(result).toBe('Null Value');
    });
  });

  describe('실제 레이아웃 패턴', () => {
    it('탭 아이콘 패턴', () => {
      const switchDef = {
        $switch: '{{tab}}',
        $cases: {
          products: 'shopping-bag',
          contents: 'book-open',
          policies: 'file-check',
        },
        $default: 'file-text',
      };

      expect(engine.resolveSwitch(switchDef, { tab: 'products' })).toBe('shopping-bag');
      expect(engine.resolveSwitch(switchDef, { tab: 'policies' })).toBe('file-check');
      expect(engine.resolveSwitch(switchDef, { tab: 'other' })).toBe('file-text');
    });

    it('상태 배지 색상 패턴', () => {
      const switchDef = {
        $switch: '{{row.status_variant}}',
        $cases: {
          success: 'bg-green-100 text-green-800',
          danger: 'bg-red-100 text-red-800',
          warning: 'bg-yellow-100 text-yellow-800',
          info: 'bg-blue-100 text-blue-800',
        },
        $default: 'bg-gray-100 text-gray-600',
      };

      expect(engine.resolveSwitch(switchDef, { row: { status_variant: 'success' } }))
        .toBe('bg-green-100 text-green-800');
      expect(engine.resolveSwitch(switchDef, { row: { status_variant: 'danger' } }))
        .toBe('bg-red-100 text-red-800');
      expect(engine.resolveSwitch(switchDef, { row: { status_variant: 'unknown' } }))
        .toBe('bg-gray-100 text-gray-600');
    });

    it('권한 기반 라벨 패턴', () => {
      const switchDef = {
        $switch: "{{row.deleted_at ? 'deleted' : (row.permissions?.can_edit ? 'editable' : 'readonly')}}",
        $cases: {
          deleted: '삭제됨',
          editable: '편집 가능',
          readonly: '읽기 전용',
        },
      };

      const deletedRow = { row: { deleted_at: '2024-01-01', permissions: { can_edit: true } } };
      expect(engine.resolveSwitch(switchDef, deletedRow)).toBe('삭제됨');

      const editableRow = { row: { deleted_at: null, permissions: { can_edit: true } } };
      expect(engine.resolveSwitch(switchDef, editableRow)).toBe('편집 가능');

      const readonlyRow = { row: { deleted_at: null, permissions: { can_edit: false } } };
      expect(engine.resolveSwitch(switchDef, readonlyRow)).toBe('읽기 전용');
    });
  });
});
