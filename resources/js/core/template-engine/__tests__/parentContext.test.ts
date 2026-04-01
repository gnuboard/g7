/**
 * $parent 바인딩 컨텍스트 테스트
 *
 * 모달 등 자식 레이아웃에서 부모 레이아웃의 상태에 접근하고 수정하는 기능을 테스트합니다.
 *
 * @since engine-v1.16.0
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { DataBindingEngine } from '../DataBindingEngine';

describe('$parent 바인딩 컨텍스트', () => {
  let engine: DataBindingEngine;

  beforeEach(() => {
    engine = new DataBindingEngine();
    // 전역 컨텍스트 스택 초기화
    (window as any).__g7LayoutContextStack = [];
    (window as any).__g7ActionContext = null;
    (window as any).__templateApp = null;
    (window as any).G7Core = null;
  });

  afterEach(() => {
    // 정리
    delete (window as any).__g7LayoutContextStack;
    delete (window as any).__g7ActionContext;
    delete (window as any).__templateApp;
    delete (window as any).G7Core;
  });

  describe('DataBindingEngine $parent 표현식', () => {
    it('$parent._local 경로 접근', () => {
      const context = {
        _local: { currentValue: 'child' },
        $parent: {
          _local: { form: { name: '부모 폼' } },
          _global: { appName: 'G7' },
        },
      };

      const result = engine.resolve('$parent._local.form.name', context);
      expect(result).toBe('부모 폼');
    });

    it('$parent._global 경로 접근', () => {
      const context = {
        _local: { currentValue: 'child' },
        $parent: {
          _local: { form: { name: '부모 폼' } },
          _global: { appName: 'G7' },
        },
      };

      const result = engine.resolve('$parent._global.appName', context);
      expect(result).toBe('G7');
    });

    it('$parent가 없는 경우 undefined 반환', () => {
      const context = {
        _local: { currentValue: 'child' },
      };

      const result = engine.resolve('$parent._local.form.name', context);
      expect(result).toBeUndefined();
    });

    it('$parent 중첩 객체 접근', () => {
      const context = {
        $parent: {
          _local: {
            form: {
              label_assignments: [
                { label_id: 1, label: { name: '신상품' } },
                { label_id: 2, label: { name: '인기상품' } },
              ],
            },
          },
        },
      };

      const result = engine.resolve('$parent._local.form.label_assignments[0].label.name', context);
      expect(result).toBe('신상품');
    });

    it('$parent 배열 접근', () => {
      const context = {
        $parent: {
          _local: {
            selectedItems: ['A', 'B', 'C'],
          },
        },
      };

      const result = engine.resolve('$parent._local.selectedItems[1]', context);
      expect(result).toBe('B');
    });

    it('resolveBindings에서 $parent 표현식 처리', () => {
      const context = {
        _local: { currentValue: 'child' },
        $parent: {
          _local: { productName: '테스트 상품' },
        },
      };

      const template = '상품명: {{$parent._local.productName}}';
      const result = engine.resolveBindings(template, context);
      expect(result).toBe('상품명: 테스트 상품');
    });

    it('$parent 경로는 캐시되지 않아야 함', () => {
      const context1 = {
        $parent: {
          _local: { value: 'first' },
        },
      };

      const context2 = {
        $parent: {
          _local: { value: 'second' },
        },
      };

      // 첫 번째 호출
      const result1 = engine.resolve('$parent._local.value', context1);
      expect(result1).toBe('first');

      // 두 번째 호출 - 다른 컨텍스트로 캐시되지 않은 결과 확인
      const result2 = engine.resolve('$parent._local.value', context2);
      expect(result2).toBe('second');
    });
  });

  describe('$parent._computed 접근', () => {
    it('$parent._computed 경로 접근', () => {
      const context = {
        $parent: {
          _computed: {
            totalPrice: 15000,
            formattedPrice: '15,000원',
          },
        },
      };

      const result = engine.resolve('$parent._computed.totalPrice', context);
      expect(result).toBe(15000);
    });
  });

  describe('evaluateExpression에서 $parent 접근', () => {
    it('$parent를 사용한 조건부 표현식', () => {
      const context = {
        $parent: {
          _local: {
            isEditing: true,
            form: { name: 'test' },
          },
        },
      };

      const result = engine.evaluateExpression('$parent._local.isEditing', context);
      expect(result).toBe(true);
    });

    it('$parent를 사용한 배열 find 표현식', () => {
      const context = {
        labelId: 2,
        $parent: {
          _local: {
            form: {
              label_assignments: [
                { label_id: 1, label: { name: '신상품' } },
                { label_id: 2, label: { name: '인기상품' } },
              ],
            },
          },
        },
      };

      const result = engine.evaluateExpression(
        '($parent._local.form.label_assignments ?? []).find(a => a.label_id === labelId)?.label?.name',
        context
      );
      expect(result).toBe('인기상품');
    });

    it('$parent를 사용한 filter 표현식', () => {
      const context = {
        excludeId: 1,
        $parent: {
          _local: {
            items: [
              { id: 1, name: 'A' },
              { id: 2, name: 'B' },
              { id: 3, name: 'C' },
            ],
          },
        },
      };

      const result = engine.evaluateExpression(
        '$parent._local.items.filter(item => item.id !== excludeId)',
        context
      );
      expect(result).toEqual([
        { id: 2, name: 'B' },
        { id: 3, name: 'C' },
      ]);
    });
  });

  describe('extendedDataContext에 $parent 포함', () => {
    it('$parent가 컨텍스트에 정의되면 접근 가능', () => {
      // DynamicRenderer가 parentDataContext를 extendedDataContext에 $parent로 추가하는 것을 시뮬레이션
      const parentDataContext = {
        _local: { parentForm: { name: 'parent value' } },
        _global: { appName: 'G7' },
        _computed: { derivedValue: 100 },
      };

      const extendedDataContext = {
        _local: { childValue: 'child' },
        _global: { appName: 'G7' },
        $parent: parentDataContext,
      };

      const result = engine.resolve('$parent._local.parentForm.name', extendedDataContext);
      expect(result).toBe('parent value');
    });
  });
});

describe('레이아웃 컨텍스트 스택 (setState target 지원)', () => {
  beforeEach(() => {
    (window as any).__g7LayoutContextStack = [];
  });

  afterEach(() => {
    delete (window as any).__g7LayoutContextStack;
  });

  it('컨텍스트 스택에 부모 컨텍스트 푸시', () => {
    const parentState = { form: { name: 'parent' } };
    const parentSetState = vi.fn();

    // 모달 열릴 때 부모 컨텍스트 푸시
    (window as any).__g7LayoutContextStack.push({
      state: parentState,
      setState: parentSetState,
      dataContext: {
        _local: parentState,
        _global: { appName: 'G7' },
      },
    });

    const stack = (window as any).__g7LayoutContextStack;
    expect(stack.length).toBe(1);
    expect(stack[0].state).toEqual(parentState);
    expect(stack[0].dataContext._local).toEqual(parentState);
  });

  it('부모 컨텍스트에서 setState 호출', () => {
    const parentState = { form: { name: 'parent' } };
    const parentSetState = vi.fn();

    (window as any).__g7LayoutContextStack.push({
      state: parentState,
      setState: parentSetState,
    });

    // 부모 컨텍스트 가져오기
    const stack = (window as any).__g7LayoutContextStack;
    const parentContext = stack[stack.length - 1];

    // 상태 업데이트
    parentContext.setState({ form: { name: 'updated' } });

    expect(parentSetState).toHaveBeenCalledWith({ form: { name: 'updated' } });
  });

  it('중첩 모달에서 컨텍스트 스택 관리', () => {
    const rootState = { level: 'root' };
    const parentState = { level: 'parent' };

    const rootSetState = vi.fn();
    const parentSetState = vi.fn();

    // 루트 → 첫 번째 모달
    (window as any).__g7LayoutContextStack.push({
      state: rootState,
      setState: rootSetState,
    });

    // 첫 번째 모달 → 두 번째 모달
    (window as any).__g7LayoutContextStack.push({
      state: parentState,
      setState: parentSetState,
    });

    const stack = (window as any).__g7LayoutContextStack;

    // parent (마지막)
    expect(stack[stack.length - 1].state.level).toBe('parent');

    // root (첫 번째)
    expect(stack[0].state.level).toBe('root');
  });
});
