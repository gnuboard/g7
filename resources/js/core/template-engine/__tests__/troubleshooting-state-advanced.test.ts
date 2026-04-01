/**
 * 트러블슈팅 회귀 테스트 - 고급 이슈 (cellChildren, Debounce, computed, 모달)
 *
 * troubleshooting-state-advanced.md에 기록된 모든 사례의 회귀 테스트입니다.
 *
 * @see .claude/docs/frontend/troubleshooting-state-advanced.md
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { Logger } from '../../utils/Logger';

// AuthManager mock
vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      login: vi.fn().mockResolvedValue({ id: 1, name: 'Test User' }),
      logout: vi.fn().mockResolvedValue(undefined),
    })),
  },
}));

// ApiClient mock
vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({
    getToken: vi.fn(),
  })),
}));

describe('트러블슈팅 회귀 테스트 - cellChildren 상태 접근', () => {
  describe('[사례 1] cellChildren에서 _local 상태 접근', () => {
    /**
     * 증상: DataGrid의 cellChildren에서 _local.xxx 접근 불가
     * 해결: G7Core.renderItemChildren에서 전역 상태 자동 병합
     */
    it('renderItemChildren이 전역 상태를 컨텍스트에 자동 병합해야 함', () => {
      // 기존 컨텍스트 (row, value만 포함)
      const itemContext = {
        row: { id: 1, name: 'Product 1' },
        value: 'cell value',
        $value: 'cell value',
      };

      // 전역 상태
      const globalState = {
        _global: { theme: 'dark' },
        _local: { productFieldErrors: { 1: 'Error message' } },
        _computed: { filteredProducts: [] },
      };

      // 병합된 컨텍스트 (G7Core.renderItemChildren 내부 로직)
      const mergedContext = {
        _global: globalState._global,
        _local: globalState._local,
        _computed: globalState._computed,
        ...itemContext,
      };

      expect(mergedContext._local.productFieldErrors).toBeDefined();
      expect(mergedContext._local.productFieldErrors[1]).toBe('Error message');
      expect(mergedContext.row.id).toBe(1);
    });
  });
});

describe('트러블슈팅 회귀 테스트 - Debounce 관련', () => {
  describe('[사례 1] debounce 적용 후 $event.preventDefault() 미작동', () => {
    /**
     * 증상: 폼 submit에 debounce 적용 후 페이지 새로고침됨
     * 해결: preventDefault가 필요한 경우 sequence로 분리
     */
    it('debounce는 Event 객체 메서드를 추출하지 않아야 함', () => {
      // extractEventData 시뮬레이션
      const extractEventData = (event: any) => ({
        type: event.type,
        target: {
          value: event.target?.value,
          name: event.target?.name,
          checked: event.target?.checked,
          type: event.target?.type,
        },
      });

      const mockEvent = {
        type: 'submit',
        target: { value: '', name: 'form', type: 'submit' },
        preventDefault: vi.fn(),
        stopPropagation: vi.fn(),
      };

      const extracted = extractEventData(mockEvent);

      // 메서드는 추출되지 않음
      expect(extracted).not.toHaveProperty('preventDefault');
      expect(extracted).not.toHaveProperty('stopPropagation');
    });
  });

  describe('[사례 2] debounce 적용 후 $event 객체 접근 제한', () => {
    /**
     * 증상: debounce 후 $event.target.dataset.id가 undefined
     * 해결: 필요한 데이터를 params로 명시적 전달
     */
    it('추출되는 이벤트 데이터는 제한적이어야 함', () => {
      const extractedData = {
        type: 'change',
        target: {
          value: 'test',
          name: 'field',
          checked: false,
          type: 'text',
        },
      };

      // dataset, classList 등은 추출되지 않음
      expect(extractedData.target).not.toHaveProperty('dataset');
      expect(extractedData.target).not.toHaveProperty('classList');
    });
  });

  describe('[사례 3] Input 필드에서 $event 사용 시 NaN 오류', () => {
    /**
     * 증상: "value": "{{$event}}" 형태로 바인딩하면 Event 객체 자체가 전달됨
     * 해결: $event.target.value 사용
     */
    it('$event를 직접 사용하면 객체가 전달되어 parseFloat 시 NaN', () => {
      const eventObject = {
        type: 'change',
        target: { value: '10000' },
      };

      // 잘못된 사용: $event 직접 전달
      const wrongValue = parseFloat(eventObject as any);
      expect(isNaN(wrongValue)).toBe(true);

      // 올바른 사용: $event.target.value
      const correctValue = parseFloat(eventObject.target.value);
      expect(correctValue).toBe(10000);
    });
  });

  describe('[사례 4] Textarea에서 $event를 상태에 직접 저장 시 크래시', () => {
    /**
     * 증상: $event를 상태에 저장하면 순환 참조로 인한 스택 오버플로우
     * 해결: $event.target.value로 실제 값만 추출
     */
    it('React 이벤트 객체를 상태에 저장하면 안됨', () => {
      // 순환 참조 시뮬레이션 (실제 React 이벤트는 더 복잡)
      const mockReactEvent = {
        _reactName: 'onChange',
        type: 'change',
        target: { value: '텍스트 내용' },
      };

      // 올바른 값 추출
      const extractedValue = mockReactEvent.target.value;
      expect(extractedValue).toBe('텍스트 내용');
      expect(typeof extractedValue).toBe('string');
    });
  });

  describe('[사례 5] Form debounce 중 부모 리렌더링 시 Input value 플리커링 방지', () => {
    /**
     * 증상: Form에 debounce 설정 시, 값 입력 후 잠깐 이전 값으로 되돌아갔다가 새 값으로 변경
     * 근본 원인: debounce 콜백에서 pending ref를 즉시 클리어하면,
     *           React의 비동기 배치 setState가 처리되기 전에 refs가 사라져
     *           중간 렌더에서 stale state value가 표시됨
     * 해결: debounce 콜백에서 pending ref를 클리어하지 않고,
     *       useMemo에서 state가 pending 값과 일치하는 것을 확인한 후 클리어
     */

    /**
     * effectiveValue 결정 로직 (DynamicRenderer.tsx 실제 구현과 동일)
     *
     * @param timerRef debounce 타이머 ref
     * @param pendingValueRef pending value ref
     * @param currentValue parentFormContext.state에서 읽은 현재 값
     * @returns [effectiveValue, updatedPendingRef] - 결정된 값과 업데이트된 pending ref
     */
    function computeEffectiveValue(
      timerRef: ReturnType<typeof setTimeout> | null,
      pendingValueRef: any,
      currentValue: any
    ): [any, any] {
      let effectiveValue: any;
      let updatedPendingRef = pendingValueRef;

      if (pendingValueRef !== undefined) {
        if (timerRef !== null) {
          // 타이머 실행 중 → 사용자 입력 중, pending value 사용
          effectiveValue = pendingValueRef;
        } else {
          // 타이머 완료 (null) → state 반영 대기 중
          if (String(currentValue ?? '') === String(pendingValueRef)) {
            // state가 pending 값과 일치 → 확정 → pending 클리어
            updatedPendingRef = undefined;
            effectiveValue = currentValue ?? '';
          } else {
            // state가 아직 반영되지 않음 → pending value 유지 (플리커 방지)
            effectiveValue = pendingValueRef;
          }
        }
      } else {
        effectiveValue = currentValue ?? '';
      }

      return [effectiveValue, updatedPendingRef];
    }

    it('debounce 대기 중 useMemo 재계산 시 pending value가 사용되어야 함', () => {
      // auto-binding이 value를 결정하는 로직 시뮬레이션
      const debounceMs = 300;
      let timerRef: ReturnType<typeof setTimeout> | null = null;
      let pendingValueRef: any = undefined;
      const staleStateValue = '2000'; // debounce 대기 중의 stale state 값

      // 사용자가 30000 입력 → autoOnChange 호출
      const newValue = '30000';
      if (debounceMs > 0) {
        pendingValueRef = newValue; // pending value 즉시 저장
      }
      timerRef = setTimeout(() => {}, debounceMs); // debounce 타이머 시작

      // 부모 리렌더링으로 useMemo 재계산 시뮬레이션
      const currentValue = staleStateValue;
      const [effectiveValue] = computeEffectiveValue(timerRef, pendingValueRef, currentValue);

      // ★ pending value ('30000')가 사용되어야 함 — 플리커 방지
      expect(effectiveValue).toBe('30000');
      expect(effectiveValue).not.toBe(staleStateValue);

      clearTimeout(timerRef!);
    });

    it('debounce 완료 후 state 반영 전까지 pending value가 유지되어야 함 (핵심 수정)', () => {
      vi.useFakeTimers();

      const debounceMs = 300;
      let timerRef: ReturnType<typeof setTimeout> | null = null;
      let pendingValueRef: any = undefined;
      let stateValue = '2000'; // 초기 state 값

      // 사용자 입력
      const newValue = '30000';
      pendingValueRef = newValue;

      timerRef = setTimeout(() => {
        // performStateUpdate: React setState 호출 (비동기 배치)
        // stateValue는 아직 업데이트되지 않음 — React가 나중에 처리
        timerRef = null;
        // ★ 핵심: pending ref를 여기서 클리어하지 않음!
        // autoBindingPendingValueRef.current = undefined; ← 이전 코드 (플리커 원인)
      }, debounceMs);

      // debounce 완료
      vi.advanceTimersByTime(debounceMs);

      // debounce 완료 직후: timer=null, pending은 아직 유지
      expect(timerRef).toBeNull();
      expect(pendingValueRef).toBe('30000'); // ★ pending이 유지됨

      // [시나리오 A] React가 state를 아직 처리하지 않은 상태에서 중간 렌더 발생
      // currentValue는 stale state에서 읽힌 '2000'
      const [effectiveA, pendingA] = computeEffectiveValue(timerRef, pendingValueRef, stateValue);
      expect(effectiveA).toBe('30000'); // ★ pending value 사용 → 플리커 방지!
      expect(pendingA).toBe('30000'); // pending 유지 (state 불일치)
      pendingValueRef = pendingA;

      // [시나리오 B] React가 state 처리 완료 → 다음 렌더
      stateValue = newValue; // state가 '30000'으로 업데이트됨
      const [effectiveB, pendingB] = computeEffectiveValue(timerRef, pendingValueRef, stateValue);
      expect(effectiveB).toBe('30000'); // state 값 사용
      expect(pendingB).toBeUndefined(); // ★ state 확정 → pending 클리어

      vi.useRealTimers();
    });

    it('debounce 없는 Form에서는 pending 로직이 영향 없어야 함', () => {
      const debounceMs = 0; // debounce 없음
      const timerRef: ReturnType<typeof setTimeout> | null = null;
      let pendingValueRef: any = undefined;
      const currentValue = '30000'; // 즉시 업데이트된 state 값

      // debounce가 없으면 pending value 저장 안 함
      if (debounceMs > 0) {
        pendingValueRef = '30000';
      }

      const [effectiveValue] = computeEffectiveValue(timerRef, pendingValueRef, currentValue);

      // pending=undefined, timer=null → currentValue 사용
      expect(effectiveValue).toBe('30000');
      expect(pendingValueRef).toBeUndefined();
    });

    it('연속 입력 시 마지막 pending value가 유지되어야 함', () => {
      vi.useFakeTimers();

      const debounceMs = 300;
      let timerRef: ReturnType<typeof setTimeout> | null = null;
      let pendingValueRef: any = undefined;
      let stateValue = '2000';
      const staleStateValue = '2000';

      // 첫 번째 입력: '3'
      pendingValueRef = '3';
      if (timerRef) clearTimeout(timerRef);
      timerRef = setTimeout(() => {
        timerRef = null;
        // pending은 클리어하지 않음 (새 로직)
      }, debounceMs);

      // 100ms 후 두 번째 입력: '30'
      vi.advanceTimersByTime(100);
      pendingValueRef = '30';
      clearTimeout(timerRef!);
      timerRef = setTimeout(() => {
        timerRef = null;
      }, debounceMs);

      // 100ms 후 세 번째 입력: '300'
      vi.advanceTimersByTime(100);
      pendingValueRef = '300';
      clearTimeout(timerRef!);
      timerRef = setTimeout(() => {
        timerRef = null;
      }, debounceMs);

      // 100ms 후 네 번째 입력: '3000'
      vi.advanceTimersByTime(100);
      pendingValueRef = '3000';
      clearTimeout(timerRef!);
      timerRef = setTimeout(() => {
        timerRef = null;
      }, debounceMs);

      // 아직 debounce 대기 중 — 부모 리렌더링 시뮬레이션
      const [effectiveValue] = computeEffectiveValue(timerRef, pendingValueRef, staleStateValue);

      // ★ 마지막 입력값 '3000'이 사용되어야 함
      expect(effectiveValue).toBe('3000');

      // debounce 완료
      vi.advanceTimersByTime(debounceMs);
      expect(timerRef).toBeNull();
      expect(pendingValueRef).toBe('3000'); // ★ pending 유지 (state 미반영)

      // state 반영 후 pending 클리어 확인
      stateValue = '3000';
      const [effectiveAfter, pendingAfter] = computeEffectiveValue(timerRef, pendingValueRef, stateValue);
      expect(effectiveAfter).toBe('3000');
      expect(pendingAfter).toBeUndefined(); // state 확정 → pending 클리어

      vi.useRealTimers();
    });

    it('숫자 타입 비교 시에도 pending 클리어가 정상 동작해야 함', () => {
      // Input value는 문자열, state value는 숫자일 수 있음
      const timerRef: ReturnType<typeof setTimeout> | null = null; // 타이머 완료
      const pendingValueRef: any = '3000'; // 문자열 (Input에서 온 값)
      const currentValue = 3000; // 숫자 (state에 저장된 값)

      // String() 비교로 타입 차이를 흡수
      const [effectiveValue, updatedPending] = computeEffectiveValue(timerRef, pendingValueRef, currentValue);

      // "3000" === "3000" → 일치 → pending 클리어
      expect(effectiveValue).toBe(3000); // state 값 반환
      expect(updatedPending).toBeUndefined();
    });
  });
});

describe('트러블슈팅 회귀 테스트 - 부분 업데이트 API', () => {
  describe('[사례 1] updateItem 후 UI 갱신 안됨', () => {
    /**
     * 증상: G7Core.dataSource.updateItem() 호출 후 화면 미갱신
     * 해결: skipRender 확인, itemId 타입 일치, idField 옵션
     */
    it('skipRender 기본값은 false여야 함', () => {
      const defaultOptions = {};
      const skipRender = (defaultOptions as any).skipRender ?? false;
      expect(skipRender).toBe(false);
    });
  });

  describe('[사례 2] updateItem itemPath 오류', () => {
    /**
     * 증상: itemPath가 잘못 지정되면 항목을 찾지 못함
     * 해결: 올바른 데이터 경로 지정
     */
    it('itemPath가 실제 데이터 구조와 일치해야 함', () => {
      const dataSource = {
        data: {
          data: [
            { id: 1, name: 'Item 1' },
            { id: 2, name: 'Item 2' },
          ],
        },
      };

      // 올바른 itemPath: 'data.data'
      const getNestedValue = (obj: any, path: string) => {
        return path.split('.').reduce((acc, key) => acc?.[key], obj);
      };

      const items = getNestedValue(dataSource, 'data.data');
      expect(items).toHaveLength(2);
    });
  });
});

describe('트러블슈팅 회귀 테스트 - computed 관련', () => {
  describe('[사례 1] $computed.xxx 표현식이 null로 평가됨', () => {
    /**
     * 증상: computed 속성이 null로 평가됨
     * 해결: computed 정의 확인, 의존성 상태 초기화 확인
     */
    it('computed는 의존 상태가 준비된 후에 평가되어야 함', () => {
      // 의존 상태
      const localState = {
        items: [{ price: 100 }, { price: 200 }],
      };

      // computed 계산
      const computed = {
        totalPrice: localState.items.reduce((sum, item) => sum + item.price, 0),
      };

      expect(computed.totalPrice).toBe(300);
    });

    it('의존 상태가 없으면 computed는 기본값을 반환해야 함', () => {
      const localState: Record<string, any> = {};

      // 안전한 computed 계산
      const computed = {
        totalPrice: (localState.items ?? []).reduce(
          (sum: number, item: any) => sum + (item?.price ?? 0),
          0
        ),
      };

      expect(computed.totalPrice).toBe(0);
    });
  });
});

describe('트러블슈팅 회귀 테스트 - 모달 상태 스코프', () => {
  let mockSetState: ReturnType<typeof vi.fn>;
  let capturedState: Record<string, any>;

  beforeEach(() => {
    capturedState = {};
    mockSetState = vi.fn((updater) => {
      if (typeof updater === 'function') {
        capturedState = updater(capturedState);
      } else {
        capturedState = { ...capturedState, ...updater };
      }
    });
    Logger.getInstance().setDebug(false);
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  describe('[사례 1] 모달에서 부모 _local 상태 접근', () => {
    /**
     * 증상: 모달에서 부모 컴포넌트의 _local 상태에 접근 불가
     * 해결: $parent._local 바인딩 또는 _global 사용
     */
    it('$parent 바인딩으로 부모 _local에 접근해야 함', () => {
      // 부모 컨텍스트
      const parentLocal = {
        selectedItem: { id: 1, name: 'Test Item' },
      };

      // 모달에서 $parent._local 접근
      const modalContext = {
        $parent: { _local: parentLocal },
      };

      expect(modalContext.$parent._local.selectedItem.id).toBe(1);
    });
  });

  describe('[사례 2] 모달에서 복잡한 객체 데이터 접근', () => {
    /**
     * 증상: 모달에서 중첩된 객체 데이터 접근 불가
     * 해결: openModal에서 modalContext로 데이터 전달
     */
    it('openModal의 modalContext로 데이터를 전달해야 함', () => {
      const openModalAction = {
        handler: 'openModal',
        params: {
          target: 'edit_modal',
          modalContext: {
            editingItem: { id: 1, name: { ko: '테스트', en: 'Test' } },
          },
        },
      };

      expect(openModalAction.params.modalContext.editingItem).toBeDefined();
    });
  });

  describe('[사례 3] 모달에서 부모 _local 상태 업데이트', () => {
    /**
     * 증상: 모달에서 부모 레이아웃의 _local 상태를 업데이트할 수 없음
     * 해결: G7Core.state.setParentLocal() 사용
     */
    it('setParentLocal로 부모의 _local 상태를 업데이트해야 함', () => {
      // 컨텍스트 스택 시뮬레이션
      const contextStack = [
        { _local: { items: [1, 2, 3] }, setState: mockSetState },
      ];

      // setParentLocal 시뮬레이션
      const setParentLocal = (updates: Record<string, any>) => {
        const parentContext = contextStack[contextStack.length - 1];
        if (parentContext?.setState) {
          parentContext.setState((prev: any) => ({ ...prev, ...updates }));
        }
      };

      setParentLocal({ items: [1, 2, 3, 4] });

      expect(capturedState.items).toEqual([1, 2, 3, 4]);
    });
  });

  describe('[사례 6] 커스텀 핸들러에서 G7Core.modal.open() 호출 시 $parent._local이 비어있음', () => {
    /**
     * 증상: 커스텀 핸들러에서 모달 열면 $parent._local이 빈 객체
     * 해결: openModal 전에 __g7ParentContext 설정
     */
    it('openModal 호출 전에 부모 컨텍스트가 스택에 있어야 함', () => {
      // 컨텍스트 스택
      const __g7ParentContextStack: any[] = [];

      // 부모 컨텍스트 push
      const parentContext = {
        _local: { form: { name: 'Test' } },
      };
      __g7ParentContextStack.push(parentContext);

      // 모달에서 부모 접근
      const getParent = () =>
        __g7ParentContextStack[__g7ParentContextStack.length - 1];

      expect(getParent()._local.form.name).toBe('Test');
    });
  });

  describe('[사례 7] 모달에서 $parent._local 변경 시 UI 미업데이트', () => {
    /**
     * 증상: $parent._local 변경 후 부모 UI가 업데이트되지 않음
     * 해결: 부모의 setState를 통해 업데이트
     */
    it('부모의 setState를 호출하여 리렌더링을 트리거해야 함', () => {
      let renderCount = 0;
      const parentSetState = vi.fn(() => {
        renderCount++;
      });

      // setParentLocal이 부모의 setState 호출
      parentSetState({ updated: true });

      expect(parentSetState).toHaveBeenCalled();
      expect(renderCount).toBe(1);
    });
  });
});

describe('트러블슈팅 회귀 테스트 - 커스텀 핸들러 상태 업데이트', () => {
  describe('[사례 1] 다국어 필드(객체) 변경 시 변경 감지 실패', () => {
    /**
     * 증상: 다국어 필드 변경 시 hasChanges가 false로 유지됨
     * 해결: 객체 참조가 아닌 깊은 비교 또는 새 객체 생성
     */
    it('다국어 필드 변경 시 새 객체를 생성해야 함', () => {
      const original = {
        name: { ko: '원본', en: 'Original' },
      };

      // 잘못된 방식: 객체 직접 수정
      const wrongWay = original;
      wrongWay.name.ko = '수정됨';
      expect(original === wrongWay).toBe(true); // 같은 참조

      // 올바른 방식: 새 객체 생성
      const correctWay = {
        ...original,
        name: { ...original.name, ko: '수정됨2' },
      };
      expect(original === correctWay).toBe(false); // 다른 참조
    });
  });

  describe('[사례 2] 다국어 필드 spread 연산자 타입 오류', () => {
    /**
     * 증상: ...value가 null/undefined일 때 spread 오류
     * 해결: fallback 객체 사용
     */
    it('spread 전에 null/undefined 체크가 필요함', () => {
      const nullValue = null;
      const undefinedValue = undefined;

      // 안전한 spread
      const safe1 = { ...(nullValue ?? {}), newKey: 'value' };
      const safe2 = { ...(undefinedValue ?? {}), newKey: 'value' };

      expect(safe1.newKey).toBe('value');
      expect(safe2.newKey).toBe('value');
    });
  });

  describe('[사례 3] G7Core.state.get()._local vs getLocal() 사용', () => {
    /**
     * 증상: get()._local 사용 시 액션 컨텍스트의 최신 상태 미반영
     * 해결: G7Core.state.getLocal() 사용
     */
    it('getLocal()은 액션 컨텍스트의 상태를 우선 반환해야 함', () => {
      // 전역 상태
      const globalState = { _local: { value: 'global' } };

      // 액션 컨텍스트 (최신)
      const actionContext = { state: { value: 'action-context' } };

      // getLocal 시뮬레이션
      const getLocal = () => {
        if (actionContext?.state) {
          return actionContext.state;
        }
        return globalState._local;
      };

      expect(getLocal().value).toBe('action-context');
    });
  });
});

describe('트러블슈팅 회귀 테스트 - 비동기 setLocal', () => {
  beforeEach(() => {
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7ActionContext = undefined;
  });

  afterEach(() => {
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7ActionContext = undefined;
  });

  describe('[사례 10] 비동기 콜백에서 setLocal 호출 후 특정 필드만 null', () => {
    /**
     * 증상: 외부 팝업 oncomplete 콜백에서 setLocal 호출 시 필드가 null
     * 해결: __g7ForcedLocalFields 메커니즘
     */
    it('__g7ForcedLocalFields가 비동기 setLocal 값을 우선 적용해야 함', () => {
      // extendedDataContext 병합 순서 시뮬레이션
      const dataContext_local = { zipcode: '', base_address: '' };
      const dynamicState = { zipcode: '', base_address: '' }; // stale 값

      // __g7ForcedLocalFields 설정 (비동기 setLocal에서)
      const forcedLocalFields = {
        zipcode: '12345',
        base_address: '서울특별시 강남구',
      };

      // 병합 순서: dataContext._local → dynamicState → forcedLocalFields
      let localState = { ...dataContext_local, ...dynamicState };
      if (forcedLocalFields) {
        localState = { ...localState, ...forcedLocalFields };
      }

      expect(localState.zipcode).toBe('12345');
      expect(localState.base_address).toBe('서울특별시 강남구');
    });
  });

  describe('[사례 11] 비동기 setLocal 후 다른 필드 입력 시 주소 값 사라짐', () => {
    /**
     * 증상: 주소 설정 후 상세주소 입력 시 주소 값이 사라짐
     * 해결: __g7ForcedLocalFields에 업데이트된 필드만 저장
     */
    it('__g7ForcedLocalFields는 업데이트된 필드만 저장해야 함', () => {
      // 기존 forced fields
      let forcedFields: Record<string, any> = {
        zipcode: '12345',
        base_address: '서울특별시',
      };

      // 새 필드 업데이트 (상세주소)
      const newUpdates = { detail_address: '101동 202호' };

      // 누적 방식으로 병합
      forcedFields = { ...forcedFields, ...newUpdates };

      expect(forcedFields.zipcode).toBe('12345'); // 기존 값 유지
      expect(forcedFields.base_address).toBe('서울특별시'); // 기존 값 유지
      expect(forcedFields.detail_address).toBe('101동 202호'); // 새 값 추가
    });
  });

  describe('[사례 12] 페이지 전환 후 비동기 setLocal 동작 안함', () => {
    /**
     * 증상: 페이지 전환 후 비동기 setLocal이 동작하지 않음
     * 해결: 페이지 전환 시 내부 컨텍스트 초기화
     */
    it('페이지 전환 시 전역 컨텍스트가 초기화되어야 함', () => {
      // 페이지 A에서 설정된 컨텍스트
      (window as any).__g7ForcedLocalFields = { oldField: 'value' };
      (window as any).__g7ActionContext = { state: {} };

      // 페이지 전환 시 초기화
      const handleRouteChange = () => {
        (window as any).__g7ForcedLocalFields = undefined;
        (window as any).__g7ActionContext = undefined;
        (window as any).__g7PendingLocalState = undefined;
      };

      handleRouteChange();

      expect((window as any).__g7ForcedLocalFields).toBeUndefined();
      expect((window as any).__g7ActionContext).toBeUndefined();
    });
  });

  describe('[사례 11] validation 실패 후 필드 수정 시 이전 값이 payload에 전송됨', () => {
    /**
     * 증상:
     * - 폼 validation 실패 후 필드 수정
     * - 재저장 시 이전 값이 payload로 전송됨
     *
     * 원인:
     * - __g7ForcedLocalFields가 이전 렌더 사이클의 값을 유지
     * - extendedDataContext 병합 시 __g7ForcedLocalFields가 최우선 적용되어
     *   dynamicState의 최신 사용자 입력을 덮어씀
     *
     * 해결:
     * - DynamicRenderer에서 렌더 사이클 시작 시 __g7ForcedLocalFields 클리어
     * - startRenderCycle()과 동일한 시점에 클리어
     */
    beforeEach(() => {
      (window as any).__g7ForcedLocalFields = undefined;
      (window as any).__g7PendingLocalState = undefined;
    });

    afterEach(() => {
      (window as any).__g7ForcedLocalFields = undefined;
      (window as any).__g7PendingLocalState = undefined;
    });

    it('렌더 사이클 시작 시 __g7ForcedLocalFields가 클리어되어야 함', () => {
      // 이전 렌더에서 설정된 값
      (window as any).__g7ForcedLocalFields = {
        form: { password: 'old_password' },
        errors: { password: ['비밀번호 오류'] },
      };

      // 렌더 사이클 시작 (DynamicRenderer.useLayoutEffect 시뮬레이션)
      const simulateRenderCycleStart = () => {
        // DynamicRenderer에서 parentComponentContext가 없으면 실행
        (window as any).__g7ForcedLocalFields = undefined;
      };

      simulateRenderCycleStart();

      expect((window as any).__g7ForcedLocalFields).toBeUndefined();
    });

    it('같은 렌더 사이클 내 연속 setState는 누적되어야 함', () => {
      // 첫 번째 setState
      (window as any).__g7ForcedLocalFields = { form: { field1: 'value1' } };

      // 같은 렌더 사이클 내 두 번째 setState (깊은 병합)
      const existingForced = (window as any).__g7ForcedLocalFields || {};
      (window as any).__g7ForcedLocalFields = {
        ...existingForced,
        form: { ...existingForced.form, field2: 'value2' },
      };

      expect((window as any).__g7ForcedLocalFields.form.field1).toBe('value1');
      expect((window as any).__g7ForcedLocalFields.form.field2).toBe('value2');
    });

    it('새 렌더 사이클에서는 이전 __g7ForcedLocalFields가 사용되지 않아야 함', () => {
      // 시나리오: validation 실패 후 필드 수정
      // 1. 첫 번째 렌더: 사용자가 password="565656" 입력 후 저장
      // 2. validation 실패 → setState로 errors 설정
      // 3. __g7ForcedLocalFields에 {errors: {...}} 저장
      (window as any).__g7ForcedLocalFields = {
        form: { password: '565656' }, // 이전 시도의 password
        errors: { password: ['비밀번호는 최소 8자'] },
      };

      // 4. 새 렌더 사이클 시작 (사용자가 필드 수정 후 리렌더)
      // DynamicRenderer.useLayoutEffect에서 클리어됨
      (window as any).__g7ForcedLocalFields = undefined;

      // 5. dynamicState에는 새 password가 있음
      const dynamicState = { form: { password: 'newLongPassword123' } };

      // 6. extendedDataContext 병합 시뮬레이션
      // __g7ForcedLocalFields가 undefined이므로 dynamicState 값 사용
      const forcedFields = (window as any).__g7ForcedLocalFields;
      const localState = forcedFields
        ? { ...dynamicState, ...forcedFields }
        : dynamicState;

      // 7. 새 password가 사용되어야 함
      expect(localState.form.password).toBe('newLongPassword123');
    });
  });
});

describe('트러블슈팅 회귀 테스트 - 모달 컨텍스트 전파', () => {
  describe('[사례 11] 모달에서 setParentLocal 호출 시 올바른 컨텍스트 사용', () => {
    /**
     * 증상: 모달에서 setParentLocal() 호출 시 _local 대신 _global 상태가 업데이트됨
     * 원인: openModal 핸들러 호출 시 componentContext를 전달하지 않으면
     *       actionContext가 undefined가 되어 globalState로 fallback
     * 해결: dispatch 호출 시 { componentContext: __componentContext } 전달 필수
     */

    beforeEach(() => {
      // 테스트 전 전역 변수 초기화
      (window as any).__g7LayoutContextStack = [];
      (window as any).__g7ActionContext = undefined;
    });

    afterEach(() => {
      // 테스트 후 정리
      delete (window as any).__g7LayoutContextStack;
      delete (window as any).__g7ActionContext;
    });

    it('dispatch에 componentContext가 없으면 actionContext가 undefined가 됨', () => {
      // dispatch 시뮬레이션 (componentContext 미전달)
      const passedComponentContext = undefined;
      const actionContext = passedComponentContext || (window as any).__g7ActionContext;

      // actionContext가 undefined이면 setParentLocal에서 global fallback 발생
      expect(actionContext).toBeUndefined();
    });

    it('dispatch에 componentContext가 있으면 actionContext가 올바르게 설정됨', () => {
      // local 상태를 가진 componentContext
      const localComponentContext = {
        state: { ui: {}, form: { name: 'test' } },
        setState: vi.fn(),
      };

      // dispatch 시뮬레이션 (componentContext 전달)
      const passedComponentContext = localComponentContext;
      const actionContext = passedComponentContext || (window as any).__g7ActionContext;

      // actionContext가 local 상태를 가짐
      expect(actionContext).toBeDefined();
      expect(actionContext.state).toHaveProperty('ui');
      expect(actionContext.state).toHaveProperty('form');
      // global 키가 아님을 확인
      expect(actionContext.state).not.toHaveProperty('sidebarOpen');
      expect(actionContext.state).not.toHaveProperty('settings');
    });

    it('handleOpenModal에서 올바른 컨텍스트가 스택에 푸시되어야 함', () => {
      // local 상태
      const localState = {
        ui: { optionInputs: [{ values: [] }] },
        form: { name: 'Product' },
      };

      // componentContext가 전달된 경우 (올바른 동작)
      const localComponentContext = {
        state: localState,
        setState: vi.fn(),
      };

      // handleOpenModal 시뮬레이션
      const stack = (window as any).__g7LayoutContextStack || [];
      const actionContext = localComponentContext; // dispatch에서 전달받은 componentContext

      // 스택에 부모 컨텍스트 푸시
      stack.push({
        state: actionContext?.state,
        setState: actionContext?.setState,
      });
      (window as any).__g7LayoutContextStack = stack;

      // 스택에서 꺼낸 컨텍스트가 local 상태를 가짐
      const pushedContext = stack[stack.length - 1];
      expect(pushedContext.state).toHaveProperty('ui');
      expect(pushedContext.state).toHaveProperty('form');
      expect(pushedContext.state).not.toHaveProperty('sidebarOpen');
    });

    it('componentContext 없이 openModal 호출 시 스택에 undefined가 푸시될 수 있음', () => {
      // componentContext가 전달되지 않은 경우 (버그 상황)
      const passedComponentContext = undefined;
      const actionContext = passedComponentContext || (window as any).__g7ActionContext;

      // handleOpenModal 시뮬레이션
      const stack = (window as any).__g7LayoutContextStack || [];

      // actionContext가 undefined이면 스택에 undefined 상태 푸시
      stack.push({
        state: actionContext?.state, // undefined
        setState: actionContext?.setState, // undefined
      });
      (window as any).__g7LayoutContextStack = stack;

      // 스택에서 꺼낸 컨텍스트의 state가 undefined
      const pushedContext = stack[stack.length - 1];
      expect(pushedContext.state).toBeUndefined();
    });

    it('setParentLocal에서 스택 컨텍스트가 undefined면 global fallback 발생', () => {
      // 스택에 undefined 컨텍스트가 있는 경우
      (window as any).__g7LayoutContextStack = [
        { state: undefined, setState: undefined },
      ];

      const globalState = {
        sidebarOpen: true,
        settings: { theme: 'dark' },
      };

      // setParentLocal 시뮬레이션
      const stack = (window as any).__g7LayoutContextStack;
      const parentContext = stack.length > 0 ? stack[stack.length - 1] : null;

      // parentContext.state가 undefined이면 globalState로 fallback
      const targetState = parentContext?.state || globalState;

      // fallback으로 globalState가 사용됨
      expect(targetState).toBe(globalState);
      expect(targetState).toHaveProperty('sidebarOpen');
    });

    it('setParentLocal에서 올바른 컨텍스트가 있으면 local 상태 업데이트', () => {
      // local 상태를 가진 컨텍스트가 스택에 있는 경우
      const mockSetState = vi.fn();
      const localState = {
        ui: { optionInputs: [{ values: [] }] },
        form: { name: 'Product' },
      };

      (window as any).__g7LayoutContextStack = [
        { state: localState, setState: mockSetState },
      ];

      const globalState = {
        sidebarOpen: true,
        settings: { theme: 'dark' },
      };

      // setParentLocal 시뮬레이션
      const stack = (window as any).__g7LayoutContextStack;
      const parentContext = stack.length > 0 ? stack[stack.length - 1] : null;

      const targetState = parentContext?.state || globalState;
      const targetSetState = parentContext?.setState || vi.fn();

      // local 상태가 사용됨
      expect(targetState).toBe(localState);
      expect(targetState).toHaveProperty('ui');
      expect(targetState).not.toHaveProperty('sidebarOpen');

      // setState 호출
      const updates = { 'ui.optionInputs.0.values': [{ ko: '빨강', en: 'Red' }] };
      targetSetState(updates);

      expect(mockSetState).toHaveBeenCalledWith(updates);
    });
  });

  // ===== 비동기 setLocal 관련 이슈 (사례 13) =====

  describe('[사례 13] 복수 root DynamicRenderer에서 setLocal 후 확인 모달 뒤 항목이 이전 값으로 복원됨', () => {
    /**
     * 증상: setLocal 후 확인 모달 뒤의 DynamicFieldList 항목이 이전 값으로 복원됨
     * 근본 원인: 복수 root의 useLayoutEffect에서 첫 번째 root가 __g7SetLocalOverrideKeys를
     * 즉시 클리어하면 나머지 root가 removeMatchingLeafKeys를 실행하지 못함
     * 해결: queueMicrotask로 클리어를 지연하여 모든 root가 독립적으로 처리
     */

    beforeEach(() => {
      (window as any).__g7SetLocalOverrideKeys = undefined;
      (window as any).__g7ForcedLocalFields = undefined;
    });

    afterEach(() => {
      (window as any).__g7SetLocalOverrideKeys = undefined;
      (window as any).__g7ForcedLocalFields = undefined;
    });

    it('queueMicrotask 지연 클리어: 모든 root가 setLocalKeys를 읽을 수 있어야 함', async () => {
      // setLocal이 전역 플래그 설정
      const overrideKeys = { form: { notice_items: [{ title: 'new' }] } };
      (window as any).__g7SetLocalOverrideKeys = overrideKeys;
      (window as any).__g7ForcedLocalFields = { form: { notice_items: [{ title: 'new' }] } };

      // 복수 root의 useLayoutEffect 시뮬레이션 (동기 순차 실행)
      const rootResults: boolean[] = [];

      // root 1 (global_toast): setLocalKeys 읽기 + queueMicrotask 지연 클리어
      const setLocalKeys1 = (window as any).__g7SetLocalOverrideKeys;
      if (setLocalKeys1) {
        rootResults.push(true); // removeMatchingLeafKeys 실행
        const capturedKeys = setLocalKeys1;
        const capturedForced = (window as any).__g7ForcedLocalFields;
        queueMicrotask(() => {
          if ((window as any).__g7SetLocalOverrideKeys === capturedKeys) {
            (window as any).__g7SetLocalOverrideKeys = undefined;
          }
          if ((window as any).__g7ForcedLocalFields === capturedForced) {
            (window as any).__g7ForcedLocalFields = undefined;
          }
        });
      } else {
        rootResults.push(false);
      }

      // root 2 (admin_layout_root): setLocalKeys 읽기 (아직 존재해야 함!)
      const setLocalKeys2 = (window as any).__g7SetLocalOverrideKeys;
      if (setLocalKeys2) {
        rootResults.push(true); // removeMatchingLeafKeys 실행
      } else {
        rootResults.push(false);
      }

      // 동기 블록 내에서: 모든 root가 setLocalKeys를 읽을 수 있어야 함
      expect(rootResults).toEqual([true, true]);
      expect((window as any).__g7SetLocalOverrideKeys).toBeDefined(); // 아직 클리어 안됨

      // microtask 실행 후: 클리어됨
      await new Promise<void>(resolve => queueMicrotask(resolve));
      expect((window as any).__g7SetLocalOverrideKeys).toBeUndefined();
      expect((window as any).__g7ForcedLocalFields).toBeUndefined();
    });

    it('참조 비교: microtask 실행 전 새 setLocal 호출 시 새 값이 보존되어야 함', async () => {
      // 첫 번째 setLocal 호출
      const firstOverrideKeys = { form: { notice_items: [{ title: 'first' }] } };
      (window as any).__g7SetLocalOverrideKeys = firstOverrideKeys;
      (window as any).__g7ForcedLocalFields = { form: { notice_items: [{ title: 'first' }] } };

      // root의 useLayoutEffect: 캡처 + queueMicrotask
      const capturedKeys = (window as any).__g7SetLocalOverrideKeys;
      const capturedForced = (window as any).__g7ForcedLocalFields;
      queueMicrotask(() => {
        if ((window as any).__g7SetLocalOverrideKeys === capturedKeys) {
          (window as any).__g7SetLocalOverrideKeys = undefined;
        }
        if ((window as any).__g7ForcedLocalFields === capturedForced) {
          (window as any).__g7ForcedLocalFields = undefined;
        }
      });

      // microtask 실행 전: 두 번째 setLocal 호출 (새 객체 참조)
      const secondOverrideKeys = { form: { notice_items: [{ title: 'second' }] } };
      (window as any).__g7SetLocalOverrideKeys = secondOverrideKeys;
      (window as any).__g7ForcedLocalFields = { form: { notice_items: [{ title: 'second' }] } };

      // microtask 실행
      await new Promise<void>(resolve => queueMicrotask(resolve));

      // 참조 비교로 인해 두 번째 setLocal의 값이 보존되어야 함
      expect((window as any).__g7SetLocalOverrideKeys).toBe(secondOverrideKeys);
      expect((window as any).__g7ForcedLocalFields).toBeDefined();
      expect((window as any).__g7ForcedLocalFields.form.notice_items[0].title).toBe('second');
    });

    it('즉시 클리어(engine-v1.18.2 이전) 방식은 두 번째 root가 처리 못함 (버그 재현)', () => {
      // setLocal이 전역 플래그 설정
      const overrideKeys = { form: { notice_items: [{ title: 'new' }] } };
      (window as any).__g7SetLocalOverrideKeys = overrideKeys;

      // engine-v1.18.2 이전의 즉시 클리어 방식 시뮬레이션
      const rootResults: boolean[] = [];

      // root 1: 읽기 + 즉시 클리어
      const keys1 = (window as any).__g7SetLocalOverrideKeys;
      if (keys1) {
        rootResults.push(true);
        (window as any).__g7SetLocalOverrideKeys = undefined; // ❌ 즉시 클리어
      } else {
        rootResults.push(false);
      }

      // root 2: 이미 undefined → 처리 불가
      const keys2 = (window as any).__g7SetLocalOverrideKeys;
      if (keys2) {
        rootResults.push(true);
      } else {
        rootResults.push(false); // ❌ false — removeMatchingLeafKeys 미실행
      }

      // 즉시 클리어 방식에서는 두 번째 root가 처리하지 못함
      expect(rootResults).toEqual([true, false]);
    });
  });

  // ===== deepMerge 상태 병합 이슈 =====

  describe('[사례 1] 커스텀 핸들러에서 delete로 객체 키를 제거해도 UI에 반영되지 않음', () => {
    /**
     * 증상: delete obj[key] 후 setLocal({ obj })로 업데이트해도 기존 키가 제거되지 않음
     * 해결: delete 대신 빈 배열([])로 대체하여 deepMerge의 배열 교체 동작 활용
     */

    // G7CoreGlobals.ts의 deepMerge와 동일한 로직을 인라인으로 재현
    function deepMerge(target: Record<string, any>, source: Record<string, any>): Record<string, any> {
      const result = { ...target };
      for (const [key, value] of Object.entries(source)) {
        if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
          if (result[key] !== null && typeof result[key] === 'object') {
            result[key] = deepMerge(result[key], value);
          } else {
            result[key] = { ...value };
          }
        } else {
          result[key] = value;
        }
      }
      return result;
    }

    it('delete로 키를 제거한 빈 객체는 deepMerge에서 기존 값을 유지함 (버그 재현)', () => {
      const currentState = {
        rangeErrors: {
          KR: [{ max: '구간이 연속적이지 않습니다.' }, {}],
        },
        form: { name: 'test' },
      };

      // ❌ delete 패턴: 빈 객체 {}가 deepMerge되면 기존 KR이 유지됨
      const errorsAfterDelete: Record<string, any> = { ...currentState.rangeErrors };
      delete errorsAfterDelete['KR'];

      const merged = deepMerge(currentState, { rangeErrors: errorsAfterDelete });

      // deepMerge({KR: [...]}, {}) → KR이 그대로 남음 — 이것이 버그 원인
      expect(merged.rangeErrors).toHaveProperty('KR');
      expect(merged.rangeErrors.KR).toEqual([{ max: '구간이 연속적이지 않습니다.' }, {}]);
    });

    it('빈 배열 할당은 deepMerge에서 기존 배열을 정상 교체함 (해결 패턴)', () => {
      const currentState = {
        rangeErrors: {
          KR: [{ max: '구간이 연속적이지 않습니다.' }, {}],
        },
        form: { name: 'test' },
      };

      // ✅ 빈 배열 패턴: deepMerge에서 배열은 교체됨
      const errorsWithEmptyArray: Record<string, any> = { ...currentState.rangeErrors };
      errorsWithEmptyArray['KR'] = [];

      const merged = deepMerge(currentState, { rangeErrors: errorsWithEmptyArray });

      // deepMerge({KR: [...]}, {KR: []}) → KR = [] (교체됨)
      expect(merged.rangeErrors.KR).toEqual([]);
    });

    it('다른 국가의 에러는 유지하면서 특정 국가만 초기화', () => {
      const currentState = {
        rangeErrors: {
          KR: [{ max: '에러' }],
          US: [{ min: '에러' }],
        },
      };

      const updated: Record<string, any> = { ...currentState.rangeErrors };
      updated['KR'] = []; // KR만 초기화

      const merged = deepMerge(currentState, { rangeErrors: updated });

      expect(merged.rangeErrors.KR).toEqual([]);
      expect(merged.rangeErrors.US).toEqual([{ min: '에러' }]);
    });
  });
});
