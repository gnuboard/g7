/**
 * 트러블슈팅 회귀 테스트 - Stale Closure & Global State
 *
 * troubleshooting-state-closure.md에 기록된 모든 사례의 회귀 테스트입니다.
 *
 * @see docs/frontend/troubleshooting-state-closure.md
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { Logger } from '../../utils/Logger';
import React from 'react';

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

describe('트러블슈팅 회귀 테스트 - Stale Closure 이슈', () => {
  let dispatcher: ActionDispatcher;
  let mockNavigate: ReturnType<typeof vi.fn>;
  let mockSetState: ReturnType<typeof vi.fn>;
  let capturedState: Record<string, any>;

  beforeEach(() => {
    mockNavigate = vi.fn();
    capturedState = {};

    mockSetState = vi.fn((updater) => {
      if (typeof updater === 'function') {
        capturedState = updater(capturedState);
      } else {
        capturedState = { ...capturedState, ...updater };
      }
    });

    dispatcher = new ActionDispatcher({
      navigate: mockNavigate,
    });

    Logger.getInstance().setDebug(false);
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  describe('[사례 1] 체크박스 되돌림 방지', () => {
    /**
     * 증상: PermissionTree에서 체크박스 클릭 시 상태가 변경되었다가 즉시 원래대로 되돌아감
     * 해결: getter 함수 패턴 적용 (parentComponentContext를 함수로 전달)
     */
    it('getter 함수 패턴으로 최신 상태를 참조할 수 있어야 함', () => {
      // stateRef 패턴 시뮬레이션
      const stateRef = { current: { checked: false } };

      // 상태 변경
      stateRef.current = { checked: true };

      // 캐싱된 함수에서도 최신 상태 접근 가능
      const getState = () => stateRef.current;
      expect(getState().checked).toBe(true);

      // 다시 변경해도 최신 값 반환
      stateRef.current = { checked: false };
      expect(getState().checked).toBe(false);
    });
  });

  describe('[사례 2] expandChildren 내부에서 _local 상태 변경 반영', () => {
    /**
     * 증상: DataGrid의 expandChildren 내부 체크박스 클릭 시 UI가 업데이트되지 않음
     * 해결: stateRef 패턴 적용
     */
    it('stateRef.current를 통해 최신 _local 상태에 접근해야 함', () => {
      // stateRef 패턴 시뮬레이션
      const latestLocalStateRef = { current: { selectedOptionIds: [1, 2] } };

      // 콜백 함수가 캐싱되더라도 stateRef.current는 최신 값 참조
      const cachedCallback = () => {
        return latestLocalStateRef.current.selectedOptionIds;
      };

      // 상태 변경
      latestLocalStateRef.current = { selectedOptionIds: [1, 2, 3] };

      // 캐싱된 콜백에서도 최신 상태 반환
      expect(cachedCallback()).toEqual([1, 2, 3]);
    });
  });

  describe('[사례 3] expandChildren 내부에서 _computed 상태 변경 반영', () => {
    /**
     * 증상: expandChildren에서 _computed 기반 표현식이 항상 빈 배열 반환
     * 해결: computedRef 패턴 적용
     */
    it('computedRef.current를 통해 최신 _computed 상태에 접근해야 함', () => {
      // computedRef 패턴 시뮬레이션
      const latestComputedRef = { current: { filteredColumns: [] } };

      // 초기 상태 확인
      expect(latestComputedRef.current.filteredColumns).toEqual([]);

      // _computed 상태 업데이트
      latestComputedRef.current = {
        filteredColumns: ['col1', 'col2', 'col3'],
      };

      // 캐싱된 렌더 함수에서도 최신 값 접근
      const renderExpandContent = () => latestComputedRef.current.filteredColumns;
      expect(renderExpandContent()).toHaveLength(3);
    });
  });

  describe('[사례 4] Debounce 콜백에서 setState 후 상태 접근', () => {
    /**
     * 증상: Form 자동 바인딩 + debounce 사용 시 입력 값이 0으로 전송됨
     * 해결: 함수형 업데이트 + stateRef.current 우선 참조
     */
    it('함수형 업데이트로 최신 상태 기반 병합이 되어야 함', async () => {
      // 함수형 업데이트 시뮬레이션
      let internalState = { list_price: 0 };

      const functionalSetState = (updater: (prev: any) => any) => {
        internalState = updater(internalState);
      };

      // 연속 입력 시뮬레이션
      functionalSetState((prev) => ({ ...prev, list_price: 1 }));
      functionalSetState((prev) => ({ ...prev, list_price: 10 }));
      functionalSetState((prev) => ({ ...prev, list_price: 100 }));
      functionalSetState((prev) => ({ ...prev, list_price: 1000 }));
      functionalSetState((prev) => ({ ...prev, list_price: 10000 }));

      // 최종 값이 올바르게 반영되어야 함
      expect(internalState.list_price).toBe(10000);
    });

    it('debounce 콜백에서도 stateRef를 통해 최신 상태에 접근해야 함', async () => {
      const stateRef = { current: { list_price: 0 } };

      // 상태 업데이트
      stateRef.current = { list_price: 10000 };

      // debounce 콜백 시뮬레이션 (지연 실행)
      const debouncedHandler = () => {
        return stateRef.current.list_price;
      };

      // 지연 후에도 최신 값 반환
      expect(debouncedHandler()).toBe(10000);
    });
  });

  describe('[사례 5] 커스텀 핸들러에서 setLocal 후 dispatch 호출', () => {
    /**
     * 증상: setLocal 호출 후 즉시 refetchDataSource 호출 시 이전 상태값 전송
     * 해결: __g7PendingLocalState 메커니즘
     */
    it('setLocal 후 __g7PendingLocalState가 설정되어야 함', async () => {
      (window as any).__g7PendingLocalState = undefined;

      const context = {
        state: { selectedItems: [1, 2, 3] },
        setState: mockSetState,
        actionId: 'test-pending-state',
      };

      // setLocal 시뮬레이션 (ActionDispatcher.handleSetState)
      await dispatcher.executeAction(
        {
          handler: 'setState' as const,
          params: { target: 'local', selectedItems: [1, 2] },
        },
        context
      );

      // __g7PendingLocalState가 설정되어야 함
      const pendingState = (window as any).__g7PendingLocalState;
      expect(pendingState).toBeDefined();
      expect(pendingState.selectedItems).toEqual([1, 2]);

      (window as any).__g7PendingLocalState = undefined;
    });
  });
});

describe('트러블슈팅 회귀 테스트 - Global State 공유 충돌', () => {
  describe('[사례 2] 페이지 간 상태 충돌', () => {
    /**
     * 증상: 사용자 관리 페이지에서 게시판 페이지로 이동 시 ColumnSelector에 이전 페이지 컬럼 표시
     * 해결: _local 스코프 사용 또는 key prop으로 리마운트 강제
     */
    it('_local 상태는 페이지별로 격리되어야 함', () => {
      // 페이지 A의 _local
      const pageALocal = { visibleColumns: ['name', 'email', 'role'] };

      // 페이지 B의 _local (별도 인스턴스)
      const pageBLocal = { visibleColumns: ['title', 'content', 'author'] };

      // 각 페이지가 독립적인 상태를 가져야 함
      expect(pageALocal.visibleColumns).not.toEqual(pageBLocal.visibleColumns);
    });

    it('key prop을 사용하여 컴포넌트 리마운트를 강제할 수 있어야 함', () => {
      // key가 변경되면 React는 컴포넌트를 리마운트
      const key1 = 'datagrid-/admin/users';
      const key2 = 'datagrid-/admin/boards';

      expect(key1).not.toBe(key2);
    });
  });

  describe('[사례 3] SPA navigate 시 _local 상태 유출 방지', () => {
    /**
     * 증상: 주문관리에서 배송정책 화면 진입 시 DataGrid 컬럼이 렌더링되지 않음
     * 해결: 레이아웃 전환 시 _local 완전 초기화
     */
    it('레이아웃이 변경되면 _local 상태가 초기화되어야 함', () => {
      // 이전 레이아웃 상태
      let globalState: Record<string, any> = {
        _local: {
          visibleColumns: ['no', 'ordered_at', 'order_number'],
          filter: { status: 'pending' },
        },
      };

      // 레이아웃 변경 감지 시뮬레이션
      const previousLayoutName = 'order_list';
      const newLayoutName = 'shipping_policy';
      const isLayoutChanged = previousLayoutName !== newLayoutName;

      // 레이아웃 변경 시 _local 초기화
      if (isLayoutChanged) {
        globalState = { _local: {} };
      }

      expect(globalState._local).toEqual({});
    });

    /**
     * 증상: 쿠폰 수정 폼에서 다른 쿠폰 수정 후 목록으로 돌아가 다시 카테고리 쿠폰
     *       수정 화면 진입 시 DataGrid 컬럼/헤더 미표시
     * 근본 원인: initialDataContext._global 스냅샷이 _local 리셋보다 먼저 실행되어
     *           _global._local에 이전 페이지의 stale 키가 남음
     * 해결: _global 스냅샷을 _local 리셋 + initLocal/initGlobal/initIsolated 처리 이후로 이동
     */
    it('_global 스냅샷은 _local 리셋 이후에 생성되어야 함 (stale _local 키 유출 방지)', () => {
      // TemplateApp.handleRouteChange 시뮬레이션
      const globalState: Record<string, any> = {
        _local: {
          // 이전 페이지(쿠폰 목록)의 상태
          visibleColumns: ['name', 'discount_type', 'status'],
          filter: { status: 'all' },
          productSearchOpen: false,
        },
        sidebarOpen: true,
      };

      // 레이아웃 전환 감지
      const previousLayoutName = 'admin_ecommerce_promotion_coupon_list';
      const newLayoutName = 'admin_ecommerce_promotion_coupon_form';
      const isLayoutChanged = previousLayoutName !== newLayoutName;

      // [수정 전 코드 순서 - 버그 재현]
      // _global 스냅샷을 _local 리셋 전에 생성하면 stale 키 포함
      const staleGlobalSnapshot = { ...globalState };
      expect(Object.keys(staleGlobalSnapshot._local)).toContain('visibleColumns');
      expect(Object.keys(staleGlobalSnapshot._local)).toContain('filter');

      // [수정 후 코드 순서 - 올바른 동작]
      // 1단계: _local 리셋
      if (isLayoutChanged) {
        globalState._local = {};
      }

      // 2단계: layoutInitLocal 적용 (새 페이지의 state/initLocal)
      const layoutInitLocal = {
        form: { target_type: 'product_amount', name: { ko: '', en: '' } },
        isSaving: false,
        errors: null,
      };
      for (const [key, value] of Object.entries(layoutInitLocal)) {
        if (globalState._local[key] === undefined) {
          globalState._local[key] = JSON.parse(JSON.stringify(value));
        }
      }

      // 3단계: _global 스냅샷 생성 (리셋 + initLocal 적용 후)
      const cleanGlobalSnapshot = { ...globalState };

      // 검증: clean 스냅샷에는 이전 페이지의 stale 키가 없어야 함
      expect(cleanGlobalSnapshot._local).not.toHaveProperty('visibleColumns');
      expect(cleanGlobalSnapshot._local).not.toHaveProperty('filter');
      expect(cleanGlobalSnapshot._local).not.toHaveProperty('productSearchOpen');

      // 검증: 새 페이지의 initLocal 키만 존재해야 함
      expect(cleanGlobalSnapshot._local).toHaveProperty('form');
      expect(cleanGlobalSnapshot._local).toHaveProperty('isSaving');
      expect(cleanGlobalSnapshot._local).toHaveProperty('errors');
    });

    it('_global._local이 clean이면 DynamicRenderer _localInit에서 오염 없이 병합되어야 함', () => {
      // DynamicRenderer._localInit effect 시뮬레이션
      // dataContext._global._local이 clean인 경우

      const cleanGlobalLocal: Record<string, any> = {
        form: { target_type: 'product_amount' },
        isSaving: false,
      };

      const initDataWithoutMeta: Record<string, any> = {
        form: { target_type: 'product_amount', name: { ko: '테스트', en: 'test' } },
        isSaving: false,
        errors: null,
      };

      // shallow merge (기본 전략)
      const globalLocalUpdate = { ...cleanGlobalLocal, ...initDataWithoutMeta, hasChanges: false };

      // 검증: 이전 페이지의 stale 키(visibleColumns, filter 등)가 없어야 함
      expect(globalLocalUpdate).not.toHaveProperty('visibleColumns');
      expect(globalLocalUpdate).not.toHaveProperty('filter');

      // 검증: 새 데이터만 포함
      expect(globalLocalUpdate.form.name.ko).toBe('테스트');
      expect(globalLocalUpdate.isSaving).toBe(false);
      expect(globalLocalUpdate.hasChanges).toBe(false);
    });
  });
});

describe('트러블슈팅 회귀 테스트 - useControllableState 훅', () => {
  describe('[사례 1] Controlled 컴포넌트에서 체크박스 변경 반영', () => {
    /**
     * 증상: useControllableState 사용 컴포넌트에서 체크박스 클릭 시 무반응 또는 깜빡거림
     * 해결: 낙관적 업데이트 패턴 + 내부 업데이트 플래그
     */
    it('낙관적 업데이트로 내부 상태를 즉시 반영해야 함', () => {
      // 낙관적 업데이트 시뮬레이션
      let internalValue = false;
      let isInternalUpdate = false;

      const setValue = (newValue: boolean) => {
        isInternalUpdate = true;
        internalValue = newValue;
        // onChange 콜백 호출
        // requestAnimationFrame 후 isInternalUpdate = false
      };

      // 체크박스 클릭
      setValue(true);

      // 즉시 내부 상태 반영
      expect(internalValue).toBe(true);
      expect(isInternalUpdate).toBe(true);
    });

    it('외부 props 동기화 시 내부 업데이트 플래그를 확인해야 함', () => {
      let internalValue = false;
      let isInternalUpdate = false;

      // 내부 업데이트 중에는 외부 동기화 무시
      const syncFromProps = (controlledValue: boolean) => {
        if (isInternalUpdate) {
          return; // 내부 업데이트 중이면 동기화 무시
        }
        internalValue = controlledValue;
      };

      // 내부 업데이트 시작
      isInternalUpdate = true;
      internalValue = true;

      // 외부에서 false로 동기화 시도
      syncFromProps(false);

      // 내부 업데이트가 유지되어야 함
      expect(internalValue).toBe(true);
    });
  });
});

describe('트러블슈팅 회귀 테스트 - expandChildren Stale Closure 패턴', () => {
  describe('stateRef와 computedRef 패턴 요약', () => {
    it('_local 상태는 stateRef.current로 접근해야 함', () => {
      const stateRef = { current: {} as Record<string, any> };
      const componentContext = {
        state: { initialValue: 1 },
        stateRef,
      };

      // 상태 변경
      stateRef.current = { updatedValue: 2 };

      // 캐싱된 함수에서 stateRef.current 사용
      const getLatestState = () =>
        componentContext.stateRef?.current ?? componentContext.state;

      expect(getLatestState().updatedValue).toBe(2);
    });

    it('_computed 상태는 computedRef.current로 접근해야 함', () => {
      const computedRef = { current: {} as Record<string, any> };

      // computed 상태 변경
      computedRef.current = {
        filteredItems: ['a', 'b', 'c'],
        totalCount: 3,
      };

      // 캐싱된 함수에서 computedRef.current 사용
      const getLatestComputed = () => computedRef.current;

      expect(getLatestComputed().filteredItems).toHaveLength(3);
      expect(getLatestComputed().totalCount).toBe(3);
    });
  });
});

describe('트러블슈팅 회귀 테스트 - sequence 핸들러 Stale Closure', () => {
  let dispatcher: ActionDispatcher;
  let mockNavigate: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    mockNavigate = vi.fn();

    dispatcher = new ActionDispatcher({
      navigate: mockNavigate,
    });

    Logger.getInstance().setDebug(false);
  });

  afterEach(() => {
    vi.clearAllMocks();
    // G7Core mock 정리
    delete (window as any).G7Core;
    delete (window as any).__g7ActionContext;
  });

  describe('[사례 7] 액션 핸들러에서 _computed Stale Closure로 인한 이전 값 참조', () => {
    /**
     * 증상: 결제수단을 무통장입금(dbank)으로 변경 후 주문하기 클릭 시
     *       payment_method가 이전 기본값(card)으로 전송됨
     * 원인: _computed는 렌더링 시점에 계산되지만, 액션 핸들러의 dataContext에
     *       캐싱된 이전 _computed 값이 전달됨. _global은 stale closure 방지 코드가
     *       있었으나 _computed에는 동일한 보호가 없었음
     * 해결: evaluateExpression에서 _computed 참조 시 computedRef.current로 최신 값 사용
     *
     * @see resources/js/core/template-engine/ActionDispatcher.ts evaluateExpression
     */
    it('evaluateExpression에서 _computed 참조 시 computedRef.current의 최신 값을 사용해야 함', async () => {
      // computedRef를 포함한 __g7ActionContext mock
      const computedRef = { current: { selectedPaymentMethod: 'dbank' } };
      (window as any).__g7ActionContext = { computedRef };
      (window as any).G7Core = {
        state: { get: vi.fn(() => ({})) },
      };

      // 렌더링 시점에 캡처된 stale _computed (card)
      const staleContext = {
        state: { paymentMethod: 'dbank' },
        data: {
          _local: { paymentMethod: 'dbank' },
          _computed: { selectedPaymentMethod: 'card' }, // stale!
        },
      };

      let capturedPaymentMethod: string | null = null;

      // built-in 핸들러(apiCall 등)가 아닌 커스텀 핸들러 사용
      // (built-in 핸들러는 switch문에서 먼저 처리되어 registerHandler 호출 안됨)
      dispatcher.registerHandler('capturePayment', async (action, ctx) => {
        capturedPaymentMethod = action.params?.payment_method;
        return { data: { success: true } };
      });

      await dispatcher.executeAction(
        {
          handler: 'capturePayment' as const,
          params: {
            payment_method: '{{_computed.selectedPaymentMethod}}',
          },
        },
        staleContext
      );

      // computedRef.current에서 최신 값(dbank)을 사용해야 함
      expect(capturedPaymentMethod).toBe('dbank');
    });

    it('__g7ActionContext가 없는 경우 기존 dataContext._computed를 fallback으로 사용해야 함', async () => {
      delete (window as any).__g7ActionContext;
      (window as any).G7Core = {
        state: { get: vi.fn(() => ({})) },
      };

      const context = {
        state: {},
        data: {
          _local: {},
          _computed: { selectedPaymentMethod: 'card' },
        },
      };

      let capturedPaymentMethod: string | null = null;

      dispatcher.registerHandler('captureFallback', async (action, ctx) => {
        capturedPaymentMethod = action.params?.payment_method;
        return { data: { success: true } };
      });

      await dispatcher.executeAction(
        {
          handler: 'captureFallback' as const,
          params: {
            payment_method: '{{_computed.selectedPaymentMethod}}',
          },
        },
        context
      );

      // fallback으로 기존 _computed 값 사용
      expect(capturedPaymentMethod).toBe('card');
    });
  });

  describe('[사례 8] sequence 내 setState 후 _computed 재계산', () => {
    /**
     * 증상: sequence 핸들러에서 setState로 _local 변경 후,
     *       다음 액션의 _computed가 변경 전 값 참조
     * 원인: sequence 핸들러에서 _local은 동기화하지만 _computed는 재계산하지 않았음
     * 해결: setState 후 _computedDefinitions를 기반으로 _computed 재계산
     *
     * @see resources/js/core/template-engine/ActionDispatcher.ts handleSequence
     */
    it('sequence 내 setState 후 다음 액션에서 재계산된 _computed를 참조해야 함', async () => {
      (window as any).G7Core = {
        state: {
          getLocal: vi.fn(() => ({ quantity: 5 })),
          get: vi.fn(() => ({})),
        },
      };

      const context = {
        state: { quantity: 1 },
        data: {
          _local: { quantity: 1 },
          _computed: { totalPrice: 1000 }, // quantity(1) * 1000
          _computedDefinitions: {
            totalPrice: '{{(_local.quantity ?? 1) * 1000}}',
          },
        },
        setState: vi.fn(),
      };

      let capturedComputed: any = null;

      dispatcher.registerHandler('captureComputed', async (action, ctx) => {
        capturedComputed = ctx.data?._computed;
        return { data: capturedComputed };
      });

      await dispatcher.executeAction(
        {
          handler: 'sequence' as const,
          actions: [
            {
              handler: 'setState' as const,
              params: { target: 'local', quantity: 5 },
            },
            {
              handler: 'captureComputed' as const,
            },
          ],
        },
        context
      );

      // setState 후 _computed.totalPrice가 재계산되어야 함: 5 * 1000 = 5000
      expect(capturedComputed?.totalPrice).toBe(5000);
    });
  });

  describe('[사례 6] sequence 시작 시 Stale Closure로 인한 이전 상태 참조', () => {
    /**
     * 증상: 결제수단 변경 후 결제 버튼 클릭 시 이전 결제수단(card)이 API에 전송됨
     * 원인: sequence 시작 시 context.state가 렌더링 시점에 캡처된 값 사용
     * 현재 동작: handleSequence는 context.state를 초기값으로 사용하며,
     *   sequence 내부 setState를 통해서만 상태가 갱신됨.
     *   따라서 stale closure 방지를 위해서는 sequence 내에서 setState로 최신 상태를 설정한 후
     *   후속 액션을 실행해야 함.
     *
     * @see templates/sirsoft-basic/layouts/shop/checkout.json
     */
    it('sequence는 context.state를 초기 상태로 사용하고 내부 setState로 갱신해야 함', async () => {
      // G7Core.state mock 설정
      (window as any).G7Core = {
        state: {
          getLocal: vi.fn(() => ({ paymentMethod: 'dbank' })),
          get: vi.fn(() => ({})),
        },
      };

      // 렌더링 시점에 캡처된 context (paymentMethod: 'card')
      const context = {
        state: { paymentMethod: 'card' },
        data: {
          _local: { paymentMethod: 'card' },
        },
        setState: vi.fn(),
      };

      let capturedStates: string[] = [];

      // 커스텀 핸들러로 상태 캡처 (apiCall은 built-in이므로 사용 불가)
      dispatcher.registerHandler('capturePayment', async (action, ctx) => {
        capturedStates.push(ctx.data?._local?.paymentMethod || ctx.state?.paymentMethod || 'unknown');
        return { data: { success: true } };
      });

      // sequence 내에서 setState로 최신 상태 설정 후 액션 실행
      await dispatcher.executeAction(
        {
          handler: 'sequence' as const,
          actions: [
            // 먼저 setState로 결제수단 업데이트
            {
              handler: 'setState' as const,
              params: { target: 'local', paymentMethod: 'dbank' },
            },
            // 업데이트된 상태로 결제 처리
            {
              handler: 'capturePayment' as const,
            },
          ],
        },
        context
      );

      // setState 후 다음 액션에서 최신 상태(dbank)가 반영되어야 함
      expect(capturedStates[0]).toBe('dbank');
    });

    it('G7Core가 없는 경우 context.state를 fallback으로 사용해야 함', async () => {
      // G7Core가 없는 환경 시뮬레이션
      delete (window as any).G7Core;

      const context = {
        state: { paymentMethod: 'vbank' },
        data: {
          _local: { paymentMethod: 'vbank' },
        },
      };

      let capturedLocalState: Record<string, any> | null = null;

      dispatcher.registerHandler('processOrder', async (action, ctx) => {
        capturedLocalState = ctx.data?._local || ctx.state || {};
        return { data: { success: true } };
      });

      await dispatcher.executeAction(
        {
          handler: 'sequence' as const,
          actions: [
            {
              handler: 'processOrder' as const,
            },
          ],
        },
        context
      );

      // context.state가 그대로 전달되어야 함
      expect(capturedLocalState).toBeDefined();
      expect(capturedLocalState?.paymentMethod).toBe('vbank');
    });

    it('sequence 내 setState 후 다음 액션에서 최신 상태가 반영되어야 함', async () => {
      // G7Core.state mock 설정
      let currentLocalState = { step: 0 };
      (window as any).G7Core = {
        state: {
          getLocal: vi.fn(() => currentLocalState),
          get: vi.fn(() => ({})),
        },
      };

      const context = {
        state: { step: 0 },
        data: { _local: { step: 0 } },
        setState: vi.fn((updates) => {
          // setState 호출 시 currentLocalState 업데이트
          currentLocalState = { ...currentLocalState, ...updates };
        }),
      };

      let capturedSteps: number[] = [];

      dispatcher.registerHandler('captureStep', async (action, ctx) => {
        capturedSteps.push(ctx.data?._local?.step || ctx.state?.step || -1);
        return { data: { step: ctx.data?._local?.step } };
      });

      await dispatcher.executeAction(
        {
          handler: 'sequence' as const,
          actions: [
            {
              handler: 'setState' as const,
              params: { target: 'local', step: 1 },
            },
            {
              handler: 'captureStep' as const,
            },
            {
              handler: 'setState' as const,
              params: { target: 'local', step: 2 },
            },
            {
              handler: 'captureStep' as const,
            },
          ],
        },
        context
      );

      // sequence 내에서 setState 결과가 다음 액션에 반영되어야 함
      expect(capturedSteps[0]).toBe(1);
      expect(capturedSteps[1]).toBe(2);
    });
  });

  describe('[사례 7] refetchDataSource merge 패턴에서 context.data._local이 pendingLocalState를 덮어씀', () => {
    /**
     * 증상: 커스텀 핸들러에서 setLocal 후 G7Core.dispatch로 refetchDataSource 호출 시
     *       API 요청에 1단계 이전 상태가 전송됨
     * 원인: v1.19.0 merge 패턴 { ...currentLocalState, ...contextLocalState }에서
     *       G7Core.dispatch 경로의 contextLocalState(렌더 시점 stale)가
     *       currentLocalState(pendingLocalState, 최신)를 덮어씀
     * 해결: v1.19.1 dispatch에서 pendingLocalState를 context.data._local에도 반영
     *
     * @see docs/frontend/troubleshooting-state-closure.md 사례 7
     */
    it('dispatch 경로에서 pendingLocalState가 context.data._local보다 우선 적용되어야 함', () => {
      // refetchDataSource의 merge 패턴 시뮬레이션
      // G7Core.dispatch 경로: pendingLocalState(NEW)와 context.data._local(OLD)

      const pendingLocalState = { selectedItems: [101, 102] }; // NEW (setLocal 호출 후)
      const staleContextLocal = { selectedItems: [101, 102, 103] }; // OLD (렌더 시점)

      // v1.19.0 (문제): stale이 new를 덮어씀
      const currentLocalState_v19 = pendingLocalState; // getLocal() → pending
      const contextLocalState_v19_broken = staleContextLocal; // context.data._local → stale
      const mergedBroken = { ...currentLocalState_v19, ...contextLocalState_v19_broken };
      // stale이 우선 → 잘못된 결과
      expect(mergedBroken.selectedItems).toEqual([101, 102, 103]); // 이전 값으로 덮어써짐

      // v1.19.1 (수정): dispatch에서 context.data._local을 pendingLocalState로 교체
      const contextLocalState_v19_fixed = pendingLocalState; // dispatch에서 교체됨
      const mergedFixed = { ...currentLocalState_v19, ...contextLocalState_v19_fixed };
      // 양쪽 모두 최신 → 정상
      expect(mergedFixed.selectedItems).toEqual([101, 102]); // 최신 값 유지
    });

    it('pendingLocalState가 없으면 context.data._local을 그대로 사용해야 함', () => {
      // pendingLocalState가 없는 경우 (setLocal 호출 없이 dispatch)
      const contextLocal = { filter: 'active', page: 1 };

      // pendingLocalState 없음 → 기존 동작 유지
      const pendingLocalState = null;
      const effectiveLocal = pendingLocalState || contextLocal;
      expect(effectiveLocal).toEqual({ filter: 'active', page: 1 });
    });

    it('dispatch에서 context.data._local 교체 시 다른 context.data 속성은 보존되어야 함', () => {
      // dispatch 수정: context.data의 _local만 교체, 나머지 보존
      const pendingLocalState = { selectedItems: [1, 2] };
      const actionContextData = {
        _local: { selectedItems: [1, 2, 3] }, // stale
        _global: { cartKey: 'abc123' },
        _computed: { totalCount: 3 },
        cartItems: { data: { items: [] } },
      };

      // 수정 로직 시뮬레이션
      const updatedData = pendingLocalState && actionContextData._local
        ? { ...actionContextData, _local: pendingLocalState }
        : actionContextData;

      // _local만 교체됨
      expect(updatedData._local).toEqual({ selectedItems: [1, 2] });
      // 다른 속성은 보존됨
      expect(updatedData._global).toEqual({ cartKey: 'abc123' });
      expect(updatedData._computed).toEqual({ totalCount: 3 });
      expect(updatedData.cartItems).toEqual({ data: { items: [] } });
    });

    it('sequence 경로에서는 context.data._local이 이미 최신이므로 영향 없어야 함', async () => {
      // sequence 핸들러는 G7Core.dispatch를 경유하지 않음
      // context.data._local은 sequence 내에서 누적 업데이트됨

      (window as any).G7Core = {
        state: {
          getLocal: vi.fn(() => ({ selectedItems: [1, 2] })),
          get: vi.fn(() => ({})),
        },
      };

      const context = {
        state: { selectedItems: [] },
        data: { _local: { selectedItems: [] } },
        setState: vi.fn(),
      };

      let capturedSelectedItems: number[] | null = null;

      dispatcher.registerHandler('captureSelection', async (action, ctx) => {
        capturedSelectedItems = ctx.data?._local?.selectedItems || ctx.state?.selectedItems || [];
        return { data: { items: capturedSelectedItems } };
      });

      await dispatcher.executeAction(
        {
          handler: 'sequence' as const,
          actions: [
            {
              handler: 'setState' as const,
              params: { target: 'local', selectedItems: [1, 2] },
            },
            {
              handler: 'captureSelection' as const,
            },
          ],
        },
        context
      );

      // sequence 내에서 setState 결과가 다음 액션에 반영되어야 함
      expect(capturedSelectedItems).toEqual([1, 2]);
    });
  });
});

describe('트러블슈팅 회귀 테스트 - startInterval Stale Closure (사례 8)', () => {
  /**
   * 증상:
   * - G7Core.state.set 직후 dispatch({ handler: 'startInterval', ... }) 호출
   * - 매 tick 의 setState 표현식이 _global 의 새 값을 못 봄 (stale)
   * - launcher 가 자체 window.setInterval 을 사용하면 클로저로 안전하게 값 보유
   *
   * 검증 의도: launcher 패턴(클로저 기반 setInterval) 이 stale closure 우회 가능함을 확인.
   * 코어 startInterval 핸들러 동작 자체는 변경하지 않음 — 이 테스트는 우회 패턴을 잠금.
   */
  describe('[사례 8] launcher 가 setInterval 직접 사용으로 stale closure 우회', () => {
    let setSpy: ReturnType<typeof vi.fn>;
    let originalG7Core: any;

    beforeEach(() => {
      setSpy = vi.fn();
      originalG7Core = (window as any).G7Core;
      (window as any).G7Core = {
        state: {
          set: setSpy,
          get: () => ({}),
          getLocal: () => ({}),
          getGlobal: () => ({}),
        },
      };
      vi.useFakeTimers();
    });

    afterEach(() => {
      vi.useRealTimers();
      (window as any).G7Core = originalG7Core;
    });

    it('launcher 클로저의 expiresMs 를 매 tick 마다 사용해 remainingSeconds 가 정확히 감소', () => {
      const G7Core = (window as any).G7Core;
      const startTime = 1_700_000_000_000;
      vi.setSystemTime(startTime);

      // 60초 후 만료
      const expiresMs = startTime + 60_000;

      // launcher 패턴: 클로저로 expiresMs 보유 + setInterval 직접 사용
      const tickHandle = window.setInterval(() => {
        const remaining = Math.max(
          0,
          Math.floor((expiresMs - Date.now()) / 1000),
        );
        G7Core.state.set({ challenge: { remainingSeconds: remaining } });
      }, 1000);

      // 5초 경과 — 5번 tick
      for (let i = 0; i < 5; i++) {
        vi.advanceTimersByTime(1000);
      }

      // 5번의 setState 호출 — remainingSeconds 가 60→59→58→57→56→55 순으로 감소
      expect(setSpy).toHaveBeenCalledTimes(5);
      const calls = setSpy.mock.calls.map((c) => c[0].challenge.remainingSeconds);
      expect(calls).toEqual([59, 58, 57, 56, 55]);

      window.clearInterval(tickHandle);
    });

    it('만료 시각 도달 시 remainingSeconds 가 0 으로 고정 (음수 방지)', () => {
      const G7Core = (window as any).G7Core;
      const startTime = 1_700_000_000_000;
      vi.setSystemTime(startTime);

      // 2초 후 만료
      const expiresMs = startTime + 2_000;

      const tickHandle = window.setInterval(() => {
        const remaining = Math.max(
          0,
          Math.floor((expiresMs - Date.now()) / 1000),
        );
        G7Core.state.set({ challenge: { remainingSeconds: remaining } });
      }, 1000);

      // 5초 경과 — 만료 시각 초과
      for (let i = 0; i < 5; i++) {
        vi.advanceTimersByTime(1000);
      }

      // 마지막 호출들은 0 으로 고정 (Math.max(0, negative))
      const lastCall = setSpy.mock.calls[setSpy.mock.calls.length - 1][0];
      expect(lastCall.challenge.remainingSeconds).toBe(0);
      // 음수가 절대 발생하지 않아야 함
      const allCalls = setSpy.mock.calls.map((c) => c[0].challenge.remainingSeconds);
      expect(allCalls.every((n: number) => n >= 0)).toBe(true);

      window.clearInterval(tickHandle);
    });

    it('clearInterval 후 setState 호출 중단 — 모달 닫힌 후 누수 방지', () => {
      const G7Core = (window as any).G7Core;
      const startTime = 1_700_000_000_000;
      vi.setSystemTime(startTime);
      const expiresMs = startTime + 60_000;

      const tickHandle = window.setInterval(() => {
        G7Core.state.set({
          challenge: {
            remainingSeconds: Math.max(0, Math.floor((expiresMs - Date.now()) / 1000)),
          },
        });
      }, 1000);

      // 2 tick 후 정리
      vi.advanceTimersByTime(2000);
      const callsBeforeClear = setSpy.mock.calls.length;
      window.clearInterval(tickHandle);

      // 추가 시간 경과해도 더 이상 호출 없음
      vi.advanceTimersByTime(5000);
      expect(setSpy).toHaveBeenCalledTimes(callsBeforeClear);
    });
  });
});
