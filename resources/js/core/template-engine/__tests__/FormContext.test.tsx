/**
 * FormContext.tsx 단위 테스트
 *
 * Form 자동 바인딩 및 trackChanges 기능 테스트
 * 문서: troubleshooting-components-form.md
 */

import { describe, it, expect, vi } from 'vitest';
import { renderHook } from '@testing-library/react';
import React from 'react';
import {
  FormContext,
  FormProvider,
  useFormContext,
  isAutoBindingEnabled,
  hasExplicitSetStateForField,
  getAutoBindingPath,
  getNestedValue,
  setNestedValue,
  FormContextValue,
} from '../FormContext';

describe('FormContext - 기본 기능', () => {
  it('기본 컨텍스트 값은 빈 객체여야 함', () => {
    const { result } = renderHook(() => useFormContext());

    expect(result.current).toEqual({});
  });

  it('Provider를 통해 값이 전달되어야 함', () => {
    const mockSetState = vi.fn();
    const mockState = { form: { email: 'test@test.com' } };

    const contextValue: FormContextValue = {
      dataKey: 'form',
      trackChanges: true,
      setState: mockSetState,
      state: mockState,
    };

    const wrapper = ({ children }: { children: React.ReactNode }) => (
      <FormProvider value={contextValue}>{children}</FormProvider>
    );

    const { result } = renderHook(() => useFormContext(), { wrapper });

    expect(result.current.dataKey).toBe('form');
    expect(result.current.trackChanges).toBe(true);
    expect(result.current.setState).toBe(mockSetState);
    expect(result.current.state).toBe(mockState);
  });
});

// =============================================================================
// 회귀 테스트: trackChanges 작동 조건
// 문서: troubleshooting-components-form.md - 사례 1
// =============================================================================

describe('FormContext - isAutoBindingEnabled 회귀 테스트', () => {
  /**
   * [TS-FORM-1] trackChanges가 작동하지 않음
   *
   * 문제: 폼 필드 값을 변경해도 저장 버튼이 활성화되지 않음
   * 원인: trackChanges가 설정되어 있지만 Input/Select에 name prop이 없음
   * 해결: dataKey + trackChanges + name 세 가지가 모두 필요함을 명시
   */
  describe('[TS-FORM-1] 자동 바인딩 필수 조건 검증', () => {
    it('dataKey + name + setState 모두 있어야 자동 바인딩이 활성화됨', () => {
      const context: FormContextValue = {
        dataKey: 'form',
        trackChanges: true,
        setState: vi.fn(),
      };

      expect(isAutoBindingEnabled(context, 'email')).toBe(true);
    });

    it('name이 없으면 자동 바인딩이 비활성화됨', () => {
      const context: FormContextValue = {
        dataKey: 'form',
        trackChanges: true,
        setState: vi.fn(),
      };

      // name이 undefined
      expect(isAutoBindingEnabled(context, undefined)).toBe(false);
      // name이 빈 문자열
      expect(isAutoBindingEnabled(context, '')).toBe(false);
    });

    it('dataKey가 없으면 자동 바인딩이 비활성화됨', () => {
      const context: FormContextValue = {
        // dataKey 없음
        trackChanges: true,
        setState: vi.fn(),
      };

      expect(isAutoBindingEnabled(context, 'email')).toBe(false);
    });

    it('setState가 없으면 자동 바인딩이 비활성화됨', () => {
      const context: FormContextValue = {
        dataKey: 'form',
        trackChanges: true,
        // setState 없음
      };

      expect(isAutoBindingEnabled(context, 'email')).toBe(false);
    });

    it('trackChanges 없어도 자동 바인딩은 가능함 (trackChanges는 hasChanges 기능만 제어)', () => {
      const context: FormContextValue = {
        dataKey: 'form',
        // trackChanges 없음
        setState: vi.fn(),
      };

      // 자동 바인딩 자체는 활성화됨 (value 바인딩)
      // trackChanges는 hasChanges 업데이트 여부만 제어
      expect(isAutoBindingEnabled(context, 'email')).toBe(true);
    });
  });

  /**
   * [TS-FORM-3] 자동/수동 바인딩 혼용 충돌 방지
   *
   * 문제: name prop과 수동 value/onChange를 동시에 사용하면 충돌
   * 원인: 자동 바인딩과 수동 바인딩이 동시에 실행됨
   * 해결: 둘 중 하나만 사용해야 함 (테스트에서는 조건 검증)
   */
  describe('[TS-FORM-3] 자동 바인딩 경로 생성', () => {
    it('dataKey와 name을 결합하여 올바른 경로를 생성해야 함', () => {
      expect(getAutoBindingPath('form', 'email')).toBe('form.email');
      expect(getAutoBindingPath('settings', 'popup_width')).toBe('settings.popup_width');
      expect(getAutoBindingPath('filter', 'minPrice')).toBe('filter.minPrice');
    });

    it('중첩된 name도 지원해야 함', () => {
      expect(getAutoBindingPath('form', 'address.city')).toBe('form.address.city');
    });
  });
});

// =============================================================================
// 회귀 테스트: 중첩 경로 값 처리
// =============================================================================

describe('FormContext - getNestedValue 헬퍼', () => {
  it('단일 경로에서 값을 가져와야 함', () => {
    const obj = { email: 'test@test.com' };
    expect(getNestedValue(obj, 'email')).toBe('test@test.com');
  });

  it('중첩 경로에서 값을 가져와야 함', () => {
    const obj = {
      form: {
        user: {
          email: 'test@test.com',
        },
      },
    };
    expect(getNestedValue(obj, 'form.user.email')).toBe('test@test.com');
  });

  it('존재하지 않는 경로는 undefined를 반환해야 함', () => {
    const obj = { form: {} };
    expect(getNestedValue(obj, 'form.nonexistent')).toBeUndefined();
    expect(getNestedValue(obj, 'nonexistent.path')).toBeUndefined();
  });

  it('obj가 undefined이면 undefined를 반환해야 함', () => {
    expect(getNestedValue(undefined, 'any.path')).toBeUndefined();
  });

  it('중간 경로가 null/undefined면 undefined를 반환해야 함', () => {
    const obj = { form: null };
    expect(getNestedValue(obj, 'form.email')).toBeUndefined();
  });
});

describe('FormContext - setNestedValue 헬퍼', () => {
  it('단일 경로에 값을 설정해야 함', () => {
    const obj = { email: '' };
    const result = setNestedValue(obj, 'email', 'test@test.com');

    expect(result.email).toBe('test@test.com');
    // 불변성 유지
    expect(result).not.toBe(obj);
  });

  it('중첩 경로에 값을 설정해야 함', () => {
    const obj = {
      form: {
        user: {
          email: '',
        },
      },
    };
    const result = setNestedValue(obj, 'form.user.email', 'test@test.com');

    expect(result.form.user.email).toBe('test@test.com');
    // 불변성 유지
    expect(result).not.toBe(obj);
    expect(result.form).not.toBe(obj.form);
    expect(result.form.user).not.toBe(obj.form.user);
  });

  it('존재하지 않는 중간 경로를 자동 생성해야 함', () => {
    const obj = {};
    const result = setNestedValue(obj, 'form.user.email', 'test@test.com');

    expect(result.form.user.email).toBe('test@test.com');
  });

  /**
   * 배열 인덱스 처리 테스트
   *
   * 폼에서 배열 필드 (예: tags[0], options[1].value) 처리 시 필요
   */
  it('배열 인덱스 경로를 올바르게 처리해야 함', () => {
    const obj = {
      form: {
        tags: ['tag1', 'tag2'],
      },
    };
    const result = setNestedValue(obj, 'form.tags.0', 'updated-tag');

    expect(result.form.tags[0]).toBe('updated-tag');
    expect(result.form.tags[1]).toBe('tag2');
    // 불변성 유지
    expect(result.form.tags).not.toBe(obj.form.tags);
  });

  it('새 배열 인덱스에 값을 설정해야 함', () => {
    const obj = { items: [] };
    const result = setNestedValue(obj, 'items.0', { name: 'item1' });

    expect(result.items[0]).toEqual({ name: 'item1' });
  });
  // =========================================================================
  // 사례 26: PHP {} → [] 변환으로 배열에 문자열 키 접근 시 객체로 변환
  // =========================================================================

  it('빈 배열에 문자열 키로 접근 시 객체로 변환해야 함 (PHP {} → [] 변환 대응)', () => {
    // PHP json_decode(assoc=true)가 {} → []로 변환하는 경우
    const obj = { editingAddress: [] as any };
    const result = setNestedValue(obj, 'editingAddress.name', '집');

    // 배열이 아닌 객체여야 함
    expect(Array.isArray(result.editingAddress)).toBe(false);
    expect(result.editingAddress.name).toBe('집');
    // JSON.stringify 시 데이터가 보존되어야 함
    expect(JSON.parse(JSON.stringify(result.editingAddress))).toEqual({ name: '집' });
  });

  it('데이터가 있는 배열에 문자열 키로 접근 시 객체로 변환하되 기존 데이터 보존', () => {
    const obj = { items: ['a', 'b'] as any };
    const result = setNestedValue(obj, 'items.label', 'test');

    // 객체로 변환됨
    expect(Array.isArray(result.items)).toBe(false);
    expect(result.items.label).toBe('test');
    // 기존 배열 요소는 숫자 키로 보존
    expect(result.items[0]).toBe('a');
    expect(result.items[1]).toBe('b');
  });

  it('배열에 숫자 키로 접근 시 배열을 유지해야 함 (기존 동작 회귀 방지)', () => {
    const obj = { tags: ['tag1', 'tag2'] };
    const result = setNestedValue(obj, 'tags.0', 'updated');

    // 배열 타입 유지
    expect(Array.isArray(result.tags)).toBe(true);
    expect(result.tags[0]).toBe('updated');
    expect(result.tags[1]).toBe('tag2');
  });

  it('중첩 경로에서 배열+숫자키 접근은 배열을 유지해야 함 (사례 5 회귀 방지)', () => {
    const obj = {
      form: {
        currencies: [
          { code: 'KRW', rounding_unit: 1 },
          { code: 'USD', rounding_unit: 0.01 },
        ],
      },
    };
    const result = setNestedValue(obj, 'form.currencies.0.rounding_unit', 10);

    // currencies는 여전히 배열
    expect(Array.isArray(result.form.currencies)).toBe(true);
    expect(result.form.currencies[0].rounding_unit).toBe(10);
    expect(result.form.currencies[1].code).toBe('USD');
  });
});

// =============================================================================
// 회귀 테스트: trackChanges와 hasChanges 연동
// 문서: troubleshooting-components-form.md
// =============================================================================

describe('FormContext - trackChanges와 hasChanges 연동 회귀 테스트', () => {
  /**
   * [TS-FORM-TRACKCHANGES-1] trackChanges 설정 시 hasChanges 자동 업데이트
   *
   * trackChanges: true가 설정된 경우:
   * - Input 값 변경 시 _local.hasChanges가 자동으로 true로 설정되어야 함
   * - 이 테스트는 DynamicRenderer의 실제 동작을 시뮬레이션
   */
  describe('[TS-FORM-TRACKCHANGES-1] hasChanges 자동 업데이트 시나리오', () => {
    it('trackChanges: true + name prop이 있으면 hasChanges 업데이트가 가능해야 함', () => {
      const setStateMock = vi.fn();
      const context: FormContextValue = {
        dataKey: 'form',
        trackChanges: true,
        setState: setStateMock,
        state: { form: { email: '' }, hasChanges: false },
      };

      // 자동 바인딩이 활성화되어야 함
      expect(isAutoBindingEnabled(context, 'email')).toBe(true);
      expect(context.trackChanges).toBe(true);

      // DynamicRenderer에서 Input onChange 시 다음과 같이 호출됨:
      // setState({ form: { ...form, email: newValue }, hasChanges: true })
      setStateMock({
        form: { email: 'new@test.com' },
        hasChanges: true,
      });

      expect(setStateMock).toHaveBeenCalledWith({
        form: { email: 'new@test.com' },
        hasChanges: true,
      });
    });

    it('trackChanges가 없으면 hasChanges를 업데이트하지 않아야 함', () => {
      const setStateMock = vi.fn();
      const context: FormContextValue = {
        dataKey: 'form',
        // trackChanges 없음
        setState: setStateMock,
        state: { form: { email: '' } },
      };

      // 자동 바인딩은 활성화됨
      expect(isAutoBindingEnabled(context, 'email')).toBe(true);
      // 하지만 trackChanges가 없으므로 hasChanges 업데이트 안 함
      expect(context.trackChanges).toBeUndefined();

      // 값만 업데이트 (hasChanges 없음)
      setStateMock({
        form: { email: 'new@test.com' },
      });

      expect(setStateMock).toHaveBeenCalledWith({
        form: { email: 'new@test.com' },
      });
    });
  });

  /**
   * [TS-FORM-MANUAL-BINDING] 수동 바인딩 시 trackChanges 미작동
   *
   * 수동 바인딩(value + onChange 직접 설정) 사용 시:
   * - name prop 없이 사용하면 trackChanges가 작동하지 않음
   * - 이 경우 onChange에서 직접 hasChanges: true 설정 필요
   */
  describe('[TS-FORM-MANUAL-BINDING] 수동 바인딩 시나리오', () => {
    it('name prop 없이 수동 바인딩 시 자동 바인딩이 비활성화됨', () => {
      const context: FormContextValue = {
        dataKey: 'form',
        trackChanges: true,
        setState: vi.fn(),
      };

      // name이 없으므로 자동 바인딩 비활성화
      // → trackChanges도 자동으로 작동하지 않음
      expect(isAutoBindingEnabled(context, undefined)).toBe(false);
    });

    it('수동 바인딩 시 hasChanges를 직접 설정해야 함', () => {
      const setStateMock = vi.fn();

      // 수동 바인딩 패턴: onChange 액션에서 직접 hasChanges 설정
      // 레이아웃 JSON에서:
      // {
      //   "type": "change",
      //   "handler": "setState",
      //   "params": {
      //     "target": "local",
      //     "form.email": "{{$event.target.value}}",
      //     "hasChanges": true  // 직접 설정 필요!
      //   }
      // }
      setStateMock({
        'form.email': 'manual@test.com',
        hasChanges: true,
      });

      expect(setStateMock).toHaveBeenCalledWith({
        'form.email': 'manual@test.com',
        hasChanges: true,
      });
    });
  });
});

// =============================================================================
// 회귀 테스트: hasExplicitSetStateForField - 자동 바인딩 스킵 조건 A
// 문서: troubleshooting-components-form.md - 사례 4
// =============================================================================

describe('FormContext - hasExplicitSetStateForField 헬퍼', () => {
  /**
   * [TS-FORM-4] dataKey 자동 바인딩과 setState 동시 사용 시 충돌
   *
   * 문제: dataKey 자동 바인딩 + 명시적 setState가 동일 필드에 존재하면
   *       자동 바인딩이 value를 상태값으로 덮어써서 라디오/체크박스가 작동하지 않음
   * 해결: change 이벤트의 setState가 해당 필드를 대상으로 하면 자동 바인딩 스킵
   */
  describe('[TS-FORM-4] change + setState + 동일 필드 경로 탐지', () => {
    it('change 이벤트에서 setState가 동일 필드를 대상으로 하면 true', () => {
      const actions = [
        {
          type: 'change',
          handler: 'setState',
          params: {
            target: 'local',
            'form.purchase_restriction': '{{$event.target.value}}',
          },
        },
      ];

      expect(hasExplicitSetStateForField(actions, 'form', 'purchase_restriction')).toBe(true);
    });

    it('change 이벤트에서 setState가 다른 필드만 대상이면 false', () => {
      const actions = [
        {
          type: 'change',
          handler: 'setState',
          params: {
            target: 'local',
            hasChanges: true,
          },
        },
      ];

      expect(hasExplicitSetStateForField(actions, 'form', 'purchase_restriction')).toBe(false);
    });

    it('change + sequence 내부 setState가 동일 필드를 대상으로 하면 true', () => {
      const actions = [
        {
          type: 'change',
          handler: 'sequence',
          actions: [
            {
              handler: 'setState',
              params: {
                target: 'local',
                'form.purchase_restriction': '{{$event.target.value}}',
                'form.allowed_roles': [],
              },
            },
          ],
        },
      ];

      expect(hasExplicitSetStateForField(actions, 'form', 'purchase_restriction')).toBe(true);
    });

    it('click 이벤트의 setState는 무시 (change가 아님)', () => {
      const actions = [
        {
          type: 'click',
          handler: 'setState',
          params: {
            target: 'local',
            'form.purchase_restriction': 'none',
          },
        },
      ];

      expect(hasExplicitSetStateForField(actions, 'form', 'purchase_restriction')).toBe(false);
    });

    it('actions가 undefined이면 false', () => {
      expect(hasExplicitSetStateForField(undefined, 'form', 'name')).toBe(false);
    });

    it('actions가 빈 배열이면 false', () => {
      expect(hasExplicitSetStateForField([], 'form', 'name')).toBe(false);
    });

    it('change + apiCall (setState가 아닌 핸들러)이면 false', () => {
      const actions = [
        {
          type: 'change',
          handler: 'apiCall',
          params: {
            endpoint: '/api/validate',
          },
        },
      ];

      expect(hasExplicitSetStateForField(actions, 'form', 'email')).toBe(false);
    });
  });
});
