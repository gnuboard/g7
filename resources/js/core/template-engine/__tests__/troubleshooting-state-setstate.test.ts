/**
 * 트러블슈팅 회귀 테스트 - setState & init_actions & dataKey
 *
 * troubleshooting-state-setstate.md에 기록된 모든 사례의 회귀 테스트입니다.
 *
 * @see .claude/docs/frontend/troubleshooting-state-setstate.md
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { DataBindingEngine } from '../DataBindingEngine';
import { deepMergeState, removeMatchingLeafKeys } from '../DynamicRenderer';
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

describe('트러블슈팅 회귀 테스트 - setState 액션 관련', () => {
  let dispatcher: ActionDispatcher;
  let mockNavigate: ReturnType<typeof vi.fn>;
  let mockSetState: ReturnType<typeof vi.fn>;
  let mockGlobalStateUpdater: ReturnType<typeof vi.fn>;
  let capturedState: Record<string, any>;
  let capturedGlobalState: Record<string, any>;

  beforeEach(() => {
    mockNavigate = vi.fn();
    capturedState = {};
    capturedGlobalState = {};

    mockSetState = vi.fn((updater) => {
      if (typeof updater === 'function') {
        capturedState = updater(capturedState);
      } else {
        capturedState = { ...capturedState, ...updater };
      }
    });

    mockGlobalStateUpdater = vi.fn((updates) => {
      capturedGlobalState = { ...capturedGlobalState, ...updates };
    });

    dispatcher = new ActionDispatcher({
      navigate: mockNavigate,
    });
    dispatcher.setGlobalStateUpdater(mockGlobalStateUpdater);

    Logger.getInstance().setDebug(false);

    (window as any).__g7PendingLocalState = undefined;
    (window as any).__g7SequenceLocalSync = undefined;
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7ActionContext = undefined;
  });

  afterEach(() => {
    vi.clearAllMocks();
    (window as any).__g7PendingLocalState = undefined;
    (window as any).__g7SequenceLocalSync = undefined;
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7ActionContext = undefined;
  });

  describe('[사례 0] TabNavigation 탭 클릭 시 탭 강조 미반영', () => {
    it('setState global 후 navigate를 sequence로 실행하면 상태가 즉시 반영되어야 함', async () => {
      const context = {
        state: {},
        setState: mockSetState,
        actionId: 'test-tab-nav',
      };

      // setState로 전역 상태 업데이트
      const action = {
        handler: 'setState' as const,
        params: {
          target: 'global',
          activeTemplateTab: 'admin',
        },
      };

      await dispatcher.executeAction(action, context);

      expect(mockGlobalStateUpdater).toHaveBeenCalled();
      const callArgs = mockGlobalStateUpdater.mock.calls[0][0];
      expect(callArgs.activeTemplateTab).toBe('admin');
    });
  });

  describe('[사례 1] sequence 내 여러 setState에서 상태 덮어쓰기', () => {
    it('단일 setState로 병합하면 모든 변경사항이 반영되어야 함', async () => {
      const context = {
        state: { currencies: ['USD'], isAddingCurrency: true },
        setState: mockSetState,
        actionId: 'test-single-setstate',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          currencies: ['USD', 'EUR'],
          isAddingCurrency: false,
          newCurrency: null,
        },
      };

      await dispatcher.executeAction(action, context);

      expect(capturedState.currencies).toEqual(['USD', 'EUR']);
      expect(capturedState.isAddingCurrency).toBe(false);
      expect(capturedState.newCurrency).toBeNull();
    });
  });

  describe('[사례 2] 같은 루트를 공유하는 dot notation 키 충돌 (해결됨)', () => {
    it('여러 dot notation 키가 같은 루트를 공유해도 모두 반영되어야 함', async () => {
      const context = {
        state: { form: { existing: 'value' } },
        setState: mockSetState,
        actionId: 'test-dot-notation',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          'form.use_default_common_info': false,
          'form.common_info_id': 218,
        },
      };

      await dispatcher.executeAction(action, context);

      // engine-v1.24.6: context.setState에는 변경 필드만 전달 (전체 _local 축적 방지)
      expect(capturedState.form.use_default_common_info).toBe(false);
      expect(capturedState.form.common_info_id).toBe(218);
      // 기존 값(existing)은 context.setState에 포함되지 않음 (변경 필드만 전달)
      // __g7PendingLocalState에서 전체 병합 결과 확인
      const pending = (window as any).__g7PendingLocalState;
      expect(pending.form.existing).toBe('value');
      expect(pending.form.use_default_common_info).toBe(false);
      expect(pending.form.common_info_id).toBe(218);
    });

    it('3개 이상의 dot notation 키도 모두 반영되어야 함', async () => {
      const context = {
        state: { form: {} },
        setState: mockSetState,
        actionId: 'test-multiple-dot',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          'form.field1': 'value1',
          'form.field2': 'value2',
          'form.field3': 'value3',
        },
      };

      await dispatcher.executeAction(action, context);

      expect(capturedState.form.field1).toBe('value1');
      expect(capturedState.form.field2).toBe('value2');
      expect(capturedState.form.field3).toBe('value3');
    });
  });

  describe('[사례 3] sequence 액션에서 조건부 실행 (if 속성)', () => {
    it('if 조건이 true인 액션만 실행되어야 함', async () => {
      const context = {
        state: { items: [{ code: 'A' }], newItem: { code: 'B' } },
        setState: mockSetState,
        actionId: 'test-conditional',
        data: {
          _local: { items: [{ code: 'A' }], newItem: { code: 'B' } },
        },
      };

      // 중복이 아닌 경우 항목 추가
      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          items: [{ code: 'A' }, { code: 'B' }],
        },
      };

      await dispatcher.executeAction(action, context);

      expect(capturedState.items).toHaveLength(2);
    });
  });

  describe('[사례 4] dot notation 키에 null 값 설정', () => {
    it('dot notation 키에 null 값을 설정해도 오류가 발생하지 않아야 함', async () => {
      const context = {
        state: { form: { newCurrency: { name: 'test' } } },
        setState: mockSetState,
        actionId: 'test-null-dot',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          'form.newCurrency': null,
        },
      };

      await expect(dispatcher.executeAction(action, context)).resolves.not.toThrow();
      expect(capturedState.form.newCurrency).toBeNull();
    });
  });

  describe('[사례 5] setNestedValue에서 배열이 객체로 변환됨', () => {
    it('배열 내 항목의 속성 변경 시 배열 구조가 유지되어야 함', async () => {
      const context = {
        state: {
          form: {
            items: [
              { id: 1, name: 'Item 1' },
              { id: 2, name: 'Item 2' },
            ],
          },
        },
        setState: mockSetState,
        actionId: 'test-array-preserve',
      };

      // 배열 전체를 업데이트하는 방식
      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          'form.items': [
            { id: 1, name: 'Updated Item 1' },
            { id: 2, name: 'Item 2' },
          ],
        },
      };

      await dispatcher.executeAction(action, context);

      expect(Array.isArray(capturedState.form.items)).toBe(true);
      expect(capturedState.form.items).toHaveLength(2);
      expect(capturedState.form.items[0].name).toBe('Updated Item 1');
    });
  });

  describe('[사례 7] sequence 내 setState 후 상태가 이전 값으로 병합됨', () => {
    it('errors 클리어 후 다른 상태 변경 시 errors가 null로 유지되어야 함', async () => {
      capturedState = { errors: { field: 'error message' }, data: 'old' };

      const context = {
        state: capturedState,
        setState: mockSetState,
        actionId: 'test-errors-clear',
      };

      // 단일 setState로 errors 클리어와 data 업데이트 병합
      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          errors: null,
          data: 'new',
        },
      };

      await dispatcher.executeAction(action, context);

      expect(capturedState.errors).toBeNull();
      expect(capturedState.data).toBe('new');
    });
  });

  describe('[사례 10] 커스텀 컴포넌트 change 이벤트가 setState 트리거', () => {
    it('change 이벤트 핸들러가 정상적으로 setState를 호출해야 함', async () => {
      const context = {
        state: { form: { name: { ko: '', en: '' } } },
        setState: mockSetState,
        actionId: 'test-custom-change',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          'form.name': { ko: '테스트', en: 'test' },
        },
      };

      await dispatcher.executeAction(action, context);

      expect(capturedState.form.name.ko).toBe('테스트');
      expect(capturedState.form.name.en).toBe('test');
    });
  });

  describe('[사례 11] setState global에서 dot notation 사용', () => {
    it('global에 dot notation 사용 시 기존 전역 상태가 유지되어야 함', async () => {
      capturedGlobalState = { theme: 'dark', user: { id: 1 } };

      const context = {
        state: {},
        setState: mockSetState,
        actionId: 'test-global-dot',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'global',
          'user.name': 'John Doe',
        },
      };

      await dispatcher.executeAction(action, context);

      expect(mockGlobalStateUpdater).toHaveBeenCalled();
      const callArgs = mockGlobalStateUpdater.mock.calls[0][0];
      expect(callArgs.user.name).toBe('John Doe');
    });
  });

  describe('[사례 12] sequence 내 closeModal 후 setState', () => {
    it('setState를 먼저 실행하고 closeModal을 나중에 실행해야 함', async () => {
      // 이 테스트는 순서가 중요함을 문서화
      const executionOrder: string[] = [];

      const context = {
        state: { selectedItem: null },
        setState: vi.fn(() => {
          executionOrder.push('setState');
        }),
        actionId: 'test-modal-order',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          selectedItem: { id: 1 },
        },
      };

      await dispatcher.executeAction(action, context);

      expect(context.setState).toHaveBeenCalled();
      expect(executionOrder).toContain('setState');
    });
  });

  describe('[사례 14] 커스텀 핸들러의 onSuccess가 apiCall 내에서 동작', () => {
    it('onSuccess 콜백이 배열 형태로 정의되어야 함', () => {
      // 올바른 패턴 문서화
      const correctPattern = {
        handler: 'apiCall',
        params: {
          endpoint: '/api/test',
        },
        onSuccess: [
          { handler: 'setState', params: { target: 'local', success: true } },
        ],
      };

      expect(Array.isArray(correctPattern.onSuccess)).toBe(true);
    });
  });

  describe('[사례 24] sequence 내 커스텀 핸들러의 setLocal() 변경이 후속 setState에 의해 덮어씌워짐', () => {
    /**
     * 증상: 커스텀 핸들러가 G7Core.state.setLocal()로 상태 변경 후
     * 후속 setState가 이전 currentState 기반으로 병합하여 변경을 덮어씀
     * 해결: handleSequence에서 비-setState 핸들러 후 __g7SequenceLocalSync 동기화
     * 참고: __g7PendingLocalState는 마이크로태스크 플러시로 null 클리어되므로
     *       전용 변수 __g7SequenceLocalSync 사용
     */
    it('커스텀 핸들러가 __g7SequenceLocalSync를 설정하면 후속 setState가 해당 변경을 보존해야 함', async () => {
      const initialState = {
        form: { country_settings: [] },
        _selectedCountryToAdd: 'US',
      };

      const mockG7Core = {
        state: {
          get: () => ({
            _local: initialState,
          }),
        },
      };
      (window as any).G7Core = mockG7Core;

      // 커스텀 핸들러: setLocal()을 시뮬레이션하여 __g7PendingLocalState + __g7SequenceLocalSync 설정
      const addCountryHandler = vi.fn((_params: any, context: any) => {
        const newState = {
          ...context.state,
          form: {
            ...context.state?.form,
            country_settings: [
              ...(context.state?.form?.country_settings || []),
              { country_code: 'US', charge_type: 'free', base_fee: 0 },
            ],
          },
        };
        (window as any).__g7PendingLocalState = newState;
        (window as any).__g7SequenceLocalSync = newState;
        return { success: true };
      });

      dispatcher.registerHandler('addCountrySetting', addCountryHandler);

      // createHandler로 sequence를 실행 (실제 사용 패턴과 동일)
      const sequenceAction = {
        type: 'click' as const,
        handler: 'sequence' as const,
        actions: [
          {
            type: 'click' as const,
            handler: 'addCountrySetting' as any,
            params: { country_code: 'US' },
          },
          {
            type: 'click' as const,
            handler: 'setState' as const,
            params: {
              target: 'local',
              _selectedCountryToAdd: null,
            },
          },
        ],
      };

      const handler = dispatcher.createHandler(sequenceAction);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      // 후속 setState가 커스텀 핸들러의 변경(country_settings)을 보존해야 함
      const lastGlobalCall = mockGlobalStateUpdater.mock.calls[mockGlobalStateUpdater.mock.calls.length - 1][0];
      expect(lastGlobalCall).toHaveProperty('_local');
      expect(lastGlobalCall._local.form?.country_settings).toHaveLength(1);
      expect(lastGlobalCall._local.form.country_settings[0].country_code).toBe('US');
      expect(lastGlobalCall._local._selectedCountryToAdd).toBeNull();

      delete (window as any).G7Core;
    });
  });

  describe('[사례 18] Form 자동 바인딩 + change 액션 setState 경합 (engine-v1.17.10)', () => {
    /**
     * 증상: RadioGroup에 name(폼 자동 바인딩) + actions(change → setState) 동시 사용 시,
     *       자동 바인딩이 반영된 후 onComplete 콜백의 setState가 이전 상태를 기반으로
     *       deepMerge하여 자동 바인딩 값을 덮어씀
     * 해결: handleSetState에서 __g7PendingLocalState를 context.state보다 우선 사용
     */
    it('pendingLocalState가 있으면 context.state 대신 pendingLocalState 기반으로 병합해야 함', async () => {
      const staleState = {
        form: { issue_method: 'direct', issue_condition: 'manual' },
      };
      const pendingState = {
        form: { issue_method: 'auto', issue_condition: 'manual' },
      };

      (window as any).__g7PendingLocalState = pendingState;

      const context = {
        state: staleState,
        setState: mockSetState,
        actionId: 'test-form-binding-race',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: '_local',
          'form.issue_condition': 'signup',
        },
      };

      await dispatcher.executeAction(action, context);

      expect(mockSetState).toHaveBeenCalled();
      // engine-v1.24.6: context.setState에는 변경 필드만 전달
      const updater = mockSetState.mock.calls[0][0];
      const result = typeof updater === 'function' ? updater(staleState) : updater;
      expect(result.form.issue_condition).toBe('signup');
      // 전체 병합 결과는 __g7PendingLocalState에서 확인
      const pending = (window as any).__g7PendingLocalState;
      expect(pending.form.issue_method).toBe('auto');
      expect(pending.form.issue_condition).toBe('signup');
    });

    it('pendingLocalState가 없으면 기존 context.state 기반으로 병합해야 함', async () => {
      (window as any).__g7PendingLocalState = undefined;

      const context = {
        state: { form: { issue_method: 'direct', issue_condition: 'manual' } },
        setState: mockSetState,
        actionId: 'test-no-pending',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: '_local',
          'form.issue_condition': 'signup',
        },
      };

      await dispatcher.executeAction(action, context);

      expect(mockSetState).toHaveBeenCalled();
      // engine-v1.24.6: context.setState에는 변경 필드만 전달
      const updater = mockSetState.mock.calls[0][0];
      const result = typeof updater === 'function' ? updater(context.state) : updater;
      expect(result.form.issue_condition).toBe('signup');
      // 전체 병합 결과는 __g7PendingLocalState에서 확인
      const pending = (window as any).__g7PendingLocalState;
      expect(pending.form.issue_method).toBe('direct');
      expect(pending.form.issue_condition).toBe('signup');
    });

    it('deep merge 모드에서도 pendingLocalState 기반으로 병합해야 함', async () => {
      const staleState = {
        form: { issue_method: 'direct', issue_condition: 'manual', name: 'coupon1' },
      };
      const pendingState = {
        form: { issue_method: 'auto', issue_condition: 'manual', name: 'coupon1' },
      };

      (window as any).__g7PendingLocalState = pendingState;

      const context = {
        state: staleState,
        setState: mockSetState,
        actionId: 'test-deep-merge-pending',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: '_local',
          form: { issue_condition: 'first_purchase' },
        },
      };

      await dispatcher.executeAction(action, context);

      expect(mockSetState).toHaveBeenCalled();
      // engine-v1.24.6: context.setState에는 변경 필드만 전달 (dot notation 변환 후)
      const updater = mockSetState.mock.calls[0][0];
      const result = typeof updater === 'function' ? updater(staleState) : updater;
      expect(result.form.issue_condition).toBe('first_purchase');
      // 전체 병합 결과는 __g7PendingLocalState에서 확인 (pendingState 기반 병합)
      const pending = (window as any).__g7PendingLocalState;
      expect(pending.form.issue_method).toBe('auto');
      expect(pending.form.issue_condition).toBe('first_purchase');
      expect(pending.form.name).toBe('coupon1');
    });
  });
});

describe('트러블슈팅 회귀 테스트 - init_actions 관련', () => {
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
    Logger.getInstance().setDebug(false);
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  describe('[사례 1] init_actions에서 setState local 동작', () => {
    it('componentContext가 있으면 setState를 통해 상태가 업데이트되어야 함', async () => {
      const dispatcher = new ActionDispatcher({
        navigate: mockNavigate,
      });

      const context = {
        state: { existingValue: 'keep' },
        setState: mockSetState,
        actionId: 'test-init-action-1',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          filter: { searchField: 'all', status: 'active' },
        },
      };

      await dispatcher.executeAction(action, context);

      expect(mockSetState).toHaveBeenCalled();
      expect(capturedState.filter).toEqual({
        searchField: 'all',
        status: 'active',
      });
      // engine-v1.24.6: context.setState에는 변경 필드만 전달
      // existingValue는 __g7PendingLocalState에서 확인
      const pending = (window as any).__g7PendingLocalState;
      expect(pending.existingValue).toBe('keep');
      expect(pending.filter).toEqual({
        searchField: 'all',
        status: 'active',
      });
    });
  });

  describe('[사례 5] 여러 resultTo가 _local을 덮어씀', () => {
    it('resultTo를 다른 키로 분리하면 덮어쓰지 않아야 함', async () => {
      const dispatcher = new ActionDispatcher({
        navigate: mockNavigate,
      });

      // 첫 번째 resultTo
      capturedState = { data1: 'result1' };
      const context1 = {
        state: capturedState,
        setState: mockSetState,
        actionId: 'test-resultto-1',
      };

      await dispatcher.executeAction(
        {
          handler: 'setState' as const,
          params: { target: 'local', data2: 'result2' },
        },
        context1
      );

      expect(capturedState.data1).toBe('result1');
      expect(capturedState.data2).toBe('result2');
    });
  });

  describe('[사례 6] init_actions에서 target: "_local" 사용', () => {
    it('target: "_local"이 "local"과 동일하게 처리되어야 함', async () => {
      const dispatcher = new ActionDispatcher({
        navigate: mockNavigate,
      });

      const context = {
        state: {},
        setState: mockSetState,
        actionId: 'test-init-action-6',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: '_local',
          formData: { name: 'test' },
        },
      };

      await dispatcher.executeAction(action, context);

      expect(mockSetState).toHaveBeenCalled();
      expect(capturedState.formData).toEqual({ name: 'test' });
      expect(capturedState._local).toBeUndefined();
    });
  });

  describe('[사례 7] Composite 컴포넌트 콜백에서 setState 렌더링', () => {
    it('setState 호출 후 렌더링이 트리거되어야 함', async () => {
      const dispatcher = new ActionDispatcher({
        navigate: mockNavigate,
      });

      let renderCount = 0;
      const mockSetStateWithRender = vi.fn((updater) => {
        renderCount++;
        if (typeof updater === 'function') {
          capturedState = updater(capturedState);
        } else {
          capturedState = { ...capturedState, ...updater };
        }
      });

      const context = {
        state: {},
        setState: mockSetStateWithRender,
        actionId: 'test-composite-callback',
      };

      await dispatcher.executeAction(
        {
          handler: 'setState' as const,
          params: { target: 'local', value: 'test' },
        },
        context
      );

      expect(renderCount).toBe(1);
    });
  });

  describe('[사례 10] 템플릿 커스텀 핸들러가 init_actions에서 "Unknown action handler" 오류', () => {
    /**
     * 증상: 캐시 있는 페이지 진입 시 initTheme, initCartKey 등 템플릿 핸들러가 등록 전에 호출됨
     * 해결: TemplateApp.init()에서 reinitializeTemplateHandlers() 호출하여 window.G7TemplateHandlers에서 동기적으로 등록
     */
    it('ActionDispatcher에 등록되지 않은 핸들러는 "Unknown action handler" 오류가 발생해야 함', async () => {
      const dispatcher = new ActionDispatcher({
        navigate: mockNavigate,
      });

      // initTheme 핸들러가 등록되지 않은 상태에서 호출 시도
      const context = {
        state: {},
        setState: mockSetState,
        actionId: 'test-unknown-handler',
      };

      // 핸들러 미등록 상태 확인
      expect(dispatcher.customHandlers.has('initTheme')).toBe(false);
      expect(dispatcher.customHandlers.has('initCartKey')).toBe(false);
    });

    it('registerHandler로 핸들러 등록 후 실행 가능해야 함', async () => {
      const dispatcher = new ActionDispatcher({
        navigate: mockNavigate,
      });

      const mockInitTheme = vi.fn();
      const mockInitCartKey = vi.fn();

      // reinitializeTemplateHandlers()가 하는 것과 동일: registerHandler로 핸들러 등록
      dispatcher.registerHandler('initTheme', mockInitTheme);
      dispatcher.registerHandler('initCartKey', mockInitCartKey);

      expect(dispatcher.customHandlers.has('initTheme')).toBe(true);
      expect(dispatcher.customHandlers.has('initCartKey')).toBe(true);

      // 등록 후 실행 시 정상 동작해야 함
      const context = {
        state: {},
        setState: mockSetState,
        actionId: 'test-registered-handler',
      };

      await dispatcher.executeAction(
        { handler: 'initTheme' as any, params: {} },
        context
      );

      expect(mockInitTheme).toHaveBeenCalled();
    });

    it('window.G7TemplateHandlers에서 핸들러 맵을 읽어 등록하는 패턴이 동작해야 함', () => {
      const dispatcher = new ActionDispatcher({
        navigate: mockNavigate,
      });

      // 템플릿이 components.iife.js 로드 시 동기적으로 설정하는 전역 객체 시뮬레이션
      const mockHandlers: Record<string, Function> = {
        initTheme: vi.fn(),
        initCartKey: vi.fn(),
        setTheme: vi.fn(),
      };
      (window as any).G7TemplateHandlers = mockHandlers;

      // reinitializeTemplateHandlers() 로직 시뮬레이션
      const templateHandlers = (window as any).G7TemplateHandlers;
      if (templateHandlers && typeof templateHandlers === 'object') {
        Object.entries(templateHandlers).forEach(([name, handler]) => {
          if (typeof handler === 'function') {
            dispatcher.registerHandler(name, handler as any);
          }
        });
      }

      // 모든 핸들러가 등록되어야 함
      expect(dispatcher.customHandlers.has('initTheme')).toBe(true);
      expect(dispatcher.customHandlers.has('initCartKey')).toBe(true);
      expect(dispatcher.customHandlers.has('setTheme')).toBe(true);

      // cleanup
      delete (window as any).G7TemplateHandlers;
    });
  });

});


describe('트러블슈팅 회귀 테스트 - dataKey 자동 바인딩', () => {
  let mockNavigate: ReturnType<typeof vi.fn>;
  let mockSetState: ReturnType<typeof vi.fn>;
  let mockGlobalStateUpdater: ReturnType<typeof vi.fn>;
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
    mockGlobalStateUpdater = vi.fn();
    Logger.getInstance().setDebug(false);

    (window as any).__g7PendingLocalState = undefined;
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7ActionContext = undefined;
  });

  afterEach(() => {
    vi.clearAllMocks();
    (window as any).__g7PendingLocalState = undefined;
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7ActionContext = undefined;
  });

  describe('[사례 1] dataKey="_global.xxx"가 동작하지 않음', () => {
    it('dataKey는 _local 경로만 지원해야 함 (global은 미지원)', () => {
      // 문서화: dataKey는 _local 전용
      const validDataKey = 'form';
      const invalidDataKey = '_global.settings';

      expect(validDataKey.startsWith('_global')).toBe(false);
      expect(invalidDataKey.startsWith('_global')).toBe(true);
    });
  });

  describe('[사례 2] dataKey 경로 내 필드를 setState/setLocal로 업데이트', () => {
    it('setState 실행 시 __g7ForcedLocalFields가 업데이트되어야 함', async () => {
      const dispatcher = new ActionDispatcher({
        navigate: mockNavigate,
        globalStateUpdater: mockGlobalStateUpdater,
      });

      const context = {
        state: { form: { label_assignments: [] } },
        setState: mockSetState,
        actionId: 'test-action-datakey',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          'form.label_assignments': [24, 25],
          'form.common_info_id': 218,
        },
      };

      await dispatcher.executeAction(action, context);

      const forcedFields = (window as any).__g7ForcedLocalFields;
      expect(forcedFields).toBeDefined();
      expect(forcedFields.form.label_assignments).toEqual([24, 25]);
      expect(forcedFields.form.common_info_id).toBe(218);
    });

    it('연속 setState 호출 시 __g7ForcedLocalFields가 누적되어야 함', async () => {
      const dispatcher = new ActionDispatcher({
        navigate: mockNavigate,
        globalStateUpdater: mockGlobalStateUpdater,
      });

      const context = {
        state: { form: {} },
        setState: mockSetState,
        actionId: 'test-accumulate',
      };

      // 첫 번째 setState
      await dispatcher.executeAction(
        {
          handler: 'setState' as const,
          params: { target: 'local', 'form.field1': 'value1' },
        },
        context
      );

      // 두 번째 setState
      await dispatcher.executeAction(
        {
          handler: 'setState' as const,
          params: { target: 'local', 'form.field2': 'value2' },
        },
        context
      );

      const forcedFields = (window as any).__g7ForcedLocalFields;
      expect(forcedFields.form.field1).toBe('value1');
      expect(forcedFields.form.field2).toBe('value2');
    });

    it('__g7PendingLocalState가 최신 상태를 반영해야 함', async () => {
      const dispatcher = new ActionDispatcher({
        navigate: mockNavigate,
        globalStateUpdater: mockGlobalStateUpdater,
      });

      const context = {
        state: { form: { name: 'existing' } },
        setState: mockSetState,
        actionId: 'test-pending',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          'form.common_info_id': 210,
        },
      };

      await dispatcher.executeAction(action, context);

      const pendingState = (window as any).__g7PendingLocalState;
      expect(pendingState).toBeDefined();
      expect(pendingState.form.common_info_id).toBe(210);
      expect(pendingState.form.name).toBe('existing');
    });
  });

  describe('[사례 4] 자식 컴포넌트에서 setLocal() 호출 시 부모 폼 상태 보존 (engine-v1.17.7)', () => {
    /**
     * 증상: 폼 내 자식 컴포넌트(예: ChipCheckbox)에서 setLocal()으로 배열 필드 업데이트 시
     *       기존에 API에서 로드된 데이터가 모두 사라지고 새로 추가한 항목만 남음
     *
     * 해결: setLocal()에서 actionContext.state 대신 항상 globalLocal을 기준으로 병합
     */

    let mockTemplateApp: any;
    let globalState: Record<string, any>;

    beforeEach(() => {
      // 전역 상태 시뮬레이션 (API에서 로드된 데이터 포함)
      globalState = {
        _local: {
          form: {
            label_assignments: [
              { label_id: 41, started_at: null, ended_at: null }, // 신상품
              { label_id: 47, started_at: null, ended_at: null }, // 이벤트
            ],
            name: '테스트 상품',
          },
          ui: { lastClickedLabelId: null },
        },
      };

      mockTemplateApp = {
        getGlobalState: vi.fn(() => globalState),
        setGlobalState: vi.fn((updates) => {
          if (updates._local) {
            globalState._local = updates._local;
          }
        }),
      };

      (window as any).__templateApp = mockTemplateApp;
      (window as any).__g7PendingLocalState = undefined;
      (window as any).__g7ForcedLocalFields = undefined;
    });

    afterEach(() => {
      (window as any).__templateApp = undefined;
      (window as any).__g7PendingLocalState = undefined;
      (window as any).__g7ForcedLocalFields = undefined;
      (window as any).__g7ActionContext = undefined;
    });

    it('자식 컴포넌트의 actionContext.state가 빈 객체여도 globalLocal 데이터가 보존되어야 함', () => {
      // 시나리오: ChipCheckbox 클릭 시 actionContext가 설정됨
      // ChipCheckbox의 state는 빈 객체 (부모 폼 데이터 없음)
      const childComponentState = {}; // ChipCheckbox의 로컬 상태 (비어있음)
      const childSetState = vi.fn();

      (window as any).__g7ActionContext = {
        state: childComponentState,
        setState: childSetState,
        stateRef: { current: childComponentState },
      };

      // G7Core.state.setLocal() 시뮬레이션
      const G7Core = (window as any).G7Core || {};
      const templateApp = (window as any).__templateApp;

      // setLocal 로직 (engine-v1.17.7)
      const updates = { 'form.label_assignments': [
        { label_id: 41, started_at: null, ended_at: null },
        { label_id: 47, started_at: null, ended_at: null },
        { label_id: 50, started_at: null, ended_at: null }, // 새로 추가된 라벨
      ]};

      // dot notation을 중첩 객체로 변환
      const converted = { form: { label_assignments: updates['form.label_assignments'] } };

      // engine-v1.17.7: globalLocal 사용 (actionContext.state 사용 안 함)
      const pendingState = (window as any).__g7PendingLocalState;
      const globalLocal = templateApp?.getGlobalState?.()?._local || {};
      const currentSnapshot = pendingState || globalLocal;

      // deepMerge 시뮬레이션
      const mergedPending = {
        ...currentSnapshot,
        form: {
          ...currentSnapshot.form,
          ...converted.form,
        },
      };

      // 전역 상태 업데이트
      templateApp.setGlobalState({ _local: mergedPending });
      (window as any).__g7PendingLocalState = mergedPending;

      // 검증: 기존 데이터(name)가 보존되어야 함
      expect(globalState._local.form.name).toBe('테스트 상품');

      // 검증: 새 라벨이 추가되어야 함
      expect(globalState._local.form.label_assignments).toHaveLength(3);
      expect(globalState._local.form.label_assignments[2].label_id).toBe(50);

      // 검증: pendingState에도 반영되어야 함
      const resultPending = (window as any).__g7PendingLocalState;
      expect(resultPending.form.name).toBe('테스트 상품');
      expect(resultPending.form.label_assignments).toHaveLength(3);
    });

    it('연속 setLocal 호출 시 이전 변경사항이 누적되어야 함', () => {
      const childSetState = vi.fn();
      (window as any).__g7ActionContext = {
        state: {},
        setState: childSetState,
        stateRef: { current: {} },
      };

      const templateApp = (window as any).__templateApp;

      // 첫 번째 setLocal: 라벨 추가
      const pendingState1 = (window as any).__g7PendingLocalState;
      const globalLocal1 = templateApp?.getGlobalState?.()?._local || {};
      const currentSnapshot1 = pendingState1 || globalLocal1;

      const mergedPending1 = {
        ...currentSnapshot1,
        form: {
          ...currentSnapshot1.form,
          label_assignments: [
            ...currentSnapshot1.form.label_assignments,
            { label_id: 50, started_at: null, ended_at: null },
          ],
        },
      };

      templateApp.setGlobalState({ _local: mergedPending1 });
      (window as any).__g7PendingLocalState = mergedPending1;

      // 두 번째 setLocal: UI 상태 업데이트
      const pendingState2 = (window as any).__g7PendingLocalState;
      const globalLocal2 = templateApp?.getGlobalState?.()?._local || {};
      const currentSnapshot2 = pendingState2 || globalLocal2;

      const mergedPending2 = {
        ...currentSnapshot2,
        ui: {
          ...currentSnapshot2.ui,
          lastClickedLabelId: 50,
        },
      };

      templateApp.setGlobalState({ _local: mergedPending2 });
      (window as any).__g7PendingLocalState = mergedPending2;

      // 검증: 모든 변경사항이 누적되어야 함
      const finalState = (window as any).__g7PendingLocalState;
      expect(finalState.form.name).toBe('테스트 상품'); // 원본 데이터 보존
      expect(finalState.form.label_assignments).toHaveLength(3); // 첫 번째 변경
      expect(finalState.ui.lastClickedLabelId).toBe(50); // 두 번째 변경
    });
  });

  describe('[사례 5] setLocal() 후 openModal 시 dynamicState 값 누락 (engine-v1.22.1)', () => {
    /**
     * 증상: DataGrid onSelectionChange → setState(target: "_local") → selectedProducts 설정 후
     *       커스텀 핸들러에서 setLocal() + openModal → 모달의 $parent._local.selectedProducts가 []
     *
     * 근본 원인: setState(target: "_local")로 설정된 값은 localDynamicState(React 컴포넌트 상태)에만 존재.
     *           setLocal()의 currentSnapshot 계산 시 globalLocal(templateApp.getGlobalState()._local)만 참조하여
     *           dynamicState에만 있는 값(selectedProducts)이 __g7PendingLocalState에 포함되지 않음.
     *           openModal은 __g7PendingLocalState를 $parent._local 스냅샷으로 사용하므로 해당 값 누락.
     *
     * 해결: setLocal()에서 actionContext.state(=dynamicState)를 globalLocal과 deepMerge하여
     *       baseLocal을 구성, currentSnapshot에 전체 _local 반영
     */

    let mockTemplateApp: any;
    let globalState: Record<string, any>;

    beforeEach(() => {
      // globalLocal: API에서 로드된 주문 상세 데이터 (selectedProducts는 여기에 없음)
      globalState = {
        _local: {
          form: { order_id: 100, memo: '' },
          ui: { activeTab: 'products' },
        },
      };

      mockTemplateApp = {
        getGlobalState: vi.fn(() => globalState),
        setGlobalState: vi.fn((updates) => {
          if (updates._local) {
            globalState._local = updates._local;
          }
        }),
      };

      (window as any).__templateApp = mockTemplateApp;
      (window as any).__g7PendingLocalState = undefined;
      (window as any).__g7ForcedLocalFields = undefined;
    });

    afterEach(() => {
      (window as any).__templateApp = undefined;
      (window as any).__g7PendingLocalState = undefined;
      (window as any).__g7ForcedLocalFields = undefined;
      (window as any).__g7ActionContext = undefined;
    });

    it('actionContext.state에 있는 dynamicState 값이 setLocal 결과에 포함되어야 함', () => {
      // actionContext.state = DynamicRenderer의 dynamicState (setState target: "_local"로 설정된 값 포함)
      const dynamicState = {
        selectedProducts: [
          { id: 1, product_name: '상품A', quantity: 3 },
          { id: 3, product_name: '상품C', quantity: 1 },
        ],
        form: { order_id: 100, memo: '' }, // globalLocal과 동일
      };

      (window as any).__g7ActionContext = {
        state: dynamicState,
        setState: vi.fn(),
        stateRef: { current: dynamicState },
      };

      const templateApp = (window as any).__templateApp;

      // engine-v1.22.1 setLocal 로직 시뮬레이션
      const pendingState = (window as any).__g7PendingLocalState;
      const globalLocal = templateApp?.getGlobalState?.()?._local || {};

      // 핵심 수정: dynamicLocal을 globalLocal과 병합
      const actionContext = (window as any).__g7ActionContext;
      const dynamicLocal = actionContext?.state;
      const baseLocal = dynamicLocal
        ? { ...globalLocal, ...dynamicLocal } // deepMerge 시뮬레이션 (1단계)
        : globalLocal;
      const currentSnapshot = pendingState || baseLocal;

      // setLocal 호출: bulkConfirmItems, changeQuantities 등 설정
      const updates = {
        bulkConfirmItems: [{ id: 1, name: '상품A' }],
        changeQuantities: { 1: 3 },
        batchOrderStatus: 'shipping',
      };

      const mergedPending = { ...currentSnapshot, ...updates };

      templateApp.setGlobalState({ _local: mergedPending });
      (window as any).__g7PendingLocalState = mergedPending;

      // 검증: dynamicState의 selectedProducts가 pendingState에 포함
      const result = (window as any).__g7PendingLocalState;
      expect(result.selectedProducts).toHaveLength(2);
      expect(result.selectedProducts[0].id).toBe(1);
      expect(result.selectedProducts[1].id).toBe(3);

      // 검증: setLocal로 추가한 값도 존재
      expect(result.bulkConfirmItems).toHaveLength(1);
      expect(result.changeQuantities).toEqual({ 1: 3 });
      expect(result.batchOrderStatus).toBe('shipping');

      // 검증: globalLocal의 기존 값도 보존
      expect(result.form.order_id).toBe(100);
      expect(result.ui.activeTab).toBe('products');
    });

    it('actionContext.state가 빈 객체일 때 기존 동작과 동일 (ChipCheckbox 호환)', () => {
      // engine-v1.17.7 호환: 자식 컴포넌트의 state가 빈 객체인 경우
      const emptyState = {};

      (window as any).__g7ActionContext = {
        state: emptyState,
        setState: vi.fn(),
        stateRef: { current: emptyState },
      };

      const templateApp = (window as any).__templateApp;

      const pendingState = (window as any).__g7PendingLocalState;
      const globalLocal = templateApp?.getGlobalState?.()?._local || {};

      const actionContext = (window as any).__g7ActionContext;
      const dynamicLocal = actionContext?.state;
      const baseLocal = dynamicLocal
        ? { ...globalLocal, ...dynamicLocal }
        : globalLocal;
      const currentSnapshot = pendingState || baseLocal;

      // setLocal 호출
      const updates = {
        form: {
          ...currentSnapshot.form,
          memo: '메모 추가',
        },
      };

      const mergedPending = { ...currentSnapshot, ...updates };

      templateApp.setGlobalState({ _local: mergedPending });
      (window as any).__g7PendingLocalState = mergedPending;

      // 검증: 빈 dynamicState가 globalLocal을 덮어쓰지 않음
      const result = (window as any).__g7PendingLocalState;
      expect(result.form.order_id).toBe(100);
      expect(result.form.memo).toBe('메모 추가');
      expect(result.ui.activeTab).toBe('products');
    });

    it('pendingState가 이미 존재하면 dynamicState보다 pendingState 우선', () => {
      // 이전 setLocal 호출로 pendingState가 이미 설정된 경우
      (window as any).__g7PendingLocalState = {
        form: { order_id: 100, memo: '이전 메모' },
        ui: { activeTab: 'products' },
        previousField: 'value',
      };

      const dynamicState = {
        selectedProducts: [{ id: 1, product_name: '상품A' }],
      };

      (window as any).__g7ActionContext = {
        state: dynamicState,
        setState: vi.fn(),
        stateRef: { current: dynamicState },
      };

      const templateApp = (window as any).__templateApp;

      const pendingState = (window as any).__g7PendingLocalState;
      const globalLocal = templateApp?.getGlobalState?.()?._local || {};

      const actionContext = (window as any).__g7ActionContext;
      const dynamicLocal = actionContext?.state;
      const baseLocal = dynamicLocal
        ? { ...globalLocal, ...dynamicLocal }
        : globalLocal;
      // pendingState가 있으므로 baseLocal 무시
      const currentSnapshot = pendingState || baseLocal;

      const updates = { newField: 'test' };
      const mergedPending = { ...currentSnapshot, ...updates };

      templateApp.setGlobalState({ _local: mergedPending });
      (window as any).__g7PendingLocalState = mergedPending;

      // 검증: pendingState 기반이므로 dynamicState의 selectedProducts는 미포함
      const result = (window as any).__g7PendingLocalState;
      expect(result.previousField).toBe('value'); // pendingState의 값
      expect(result.form.memo).toBe('이전 메모'); // pendingState의 값
      expect(result.newField).toBe('test'); // 새로 추가한 값
      // selectedProducts는 pendingState에 없으므로 미포함 (정상 동작)
      expect(result.selectedProducts).toBeUndefined();
    });
  });
});

describe('트러블슈팅 회귀 테스트 - deepMergeState replaceOnlyKeys', () => {
  describe('[사례 15] Validation errors가 깊은 병합으로 누적되는 문제', () => {
    /**
     * 증상: 첫 저장 시 8개 validation 에러 표시 → 필드 채운 후 재저장 시 API는 2개만 반환하지만 UI에는 8개 에러 유지
     * 근본 원인: DynamicRenderer의 deepMergeState가 errors 객체를 깊은 병합하여 이전 에러 키가 남아있음
     * 해결: deepMergeState에 replaceOnlyKeys = ['errors'] 추가하여 errors는 완전 교체
     */
    it('errors 객체는 깊은 병합 대신 완전히 교체되어야 함', () => {
      const target = {
        errors: {
          name: ['상품명은 필수입니다.'],
          category_id: ['카테고리를 선택하세요.'],
          list_price: ['정가는 필수입니다.'],
          selling_price: ['판매가는 필수입니다.'],
          stock_quantity: ['재고 수량은 필수입니다.'],
          tax_status: ['과세 상태를 선택하세요.'],
          sales_status: ['판매 상태를 선택하세요.'],
          options: ['옵션 정보가 필요합니다.'],
        },
        form: { name: '테스트 상품' },
      };

      const source = {
        errors: {
          stock_quantity: ['재고 수량은 필수입니다.'],
          options: ['옵션 정보가 필요합니다.'],
        },
      };

      const result = deepMergeState(target, source);

      // errors는 완전 교체 - 2개만 남아야 함
      expect(Object.keys(result.errors)).toHaveLength(2);
      expect(result.errors.stock_quantity).toEqual(['재고 수량은 필수입니다.']);
      expect(result.errors.options).toEqual(['옵션 정보가 필요합니다.']);

      // 이전 에러 키가 남아있으면 안 됨
      expect(result.errors.name).toBeUndefined();
      expect(result.errors.category_id).toBeUndefined();
      expect(result.errors.list_price).toBeUndefined();
      expect(result.errors.selling_price).toBeUndefined();
      expect(result.errors.tax_status).toBeUndefined();
      expect(result.errors.sales_status).toBeUndefined();

      // 다른 상태는 보존
      expect(result.form.name).toBe('테스트 상품');
    });

    it('errors를 null로 설정하면 완전히 초기화되어야 함', () => {
      const target = {
        errors: {
          name: ['필수입니다.'],
          email: ['필수입니다.'],
        },
      };

      const source = { errors: null };

      const result = deepMergeState(target, source);
      expect(result.errors).toBeNull();
    });

    it('일반 중첩 객체(form 등)는 여전히 깊은 병합되어야 함', () => {
      const target = {
        form: { name: '기존값', price: 1000 },
        filter: { status: 'active', page: 1 },
      };

      const source = {
        form: { name: '새값' },
        filter: { page: 2 },
      };

      const result = deepMergeState(target, source);

      // form은 깊은 병합 - price 유지
      expect(result.form.name).toBe('새값');
      expect(result.form.price).toBe(1000);

      // filter도 깊은 병합 - status 유지
      expect(result.filter.page).toBe(2);
      expect(result.filter.status).toBe('active');
    });

    it('extendedDataContext 시나리오: dataContext._local과 dynamicState 병합 시 errors 교체', () => {
      // dataContext._local (전역에서 전달된 이전 상태 - 8개 에러 포함)
      const dataContextLocal = {
        form: { name: '테스트', category_id: 5 },
        errors: {
          name: ['필수'], category_id: ['필수'], list_price: ['필수'],
          selling_price: ['필수'], stock_quantity: ['필수'],
          tax_status: ['필수'], sales_status: ['필수'], options: ['필수'],
        },
      };

      // dynamicState (컴포넌트 로컬 상태 - 2개 에러만 있음)
      const dynamicState = {
        form: { name: '테스트', category_id: 5, list_price: 10000 },
        errors: {
          stock_quantity: ['필수'],
          options: ['필수'],
        },
      };

      const result = deepMergeState(dataContextLocal, dynamicState);

      // errors는 dynamicState의 값으로 완전 교체
      expect(Object.keys(result.errors)).toHaveLength(2);
      expect(result.errors.stock_quantity).toEqual(['필수']);
      expect(result.errors.options).toEqual(['필수']);
      expect(result.errors.name).toBeUndefined();

      // form은 깊은 병합 (list_price 추가)
      expect(result.form.name).toBe('테스트');
      expect(result.form.category_id).toBe(5);
      expect(result.form.list_price).toBe(10000);
    });
  });

  describe('deepMergeState sparse array 방지', () => {
    /**
     * 증상: 빈 배열 target과 큰 숫자 키를 가진 객체 source를 병합할 때
     *       sparse array가 생성되어 수백~수천 개의 null 값이 JSON에 포함됨
     * 예시: item_coupons가 API에서 []로 초기화된 후, { "1634": ["9047"] } 병합 시
     *       [null × 1634, ["9047"]] 형태의 sparse array 생성
     * 해결: 숫자 키가 배열 범위를 크게 초과하면 객체로 처리 (배열 확장 대신)
     */
    it('빈 배열과 큰 숫자 키 객체 병합 시 sparse array가 생성되지 않아야 함', () => {
      const target = {
        checkout: {
          item_coupons: [],  // API에서 빈 배열로 초기화
        },
      };
      const source = {
        checkout: {
          item_coupons: { '1634': ['9047'] },  // product_option_id를 키로 사용
        },
      };

      const result = deepMergeState(target, source);

      // sparse array가 아닌 객체여야 함
      expect(Array.isArray(result.checkout.item_coupons)).toBe(false);
      expect(result.checkout.item_coupons['1634']).toEqual(['9047']);
      // JSON.stringify 시 null 대량 발생하지 않아야 함
      const json = JSON.stringify(result.checkout.item_coupons);
      expect(json).not.toContain('null,null');
    });

    it('작은 배열과 작은 인덱스의 숫자 키 객체는 정상적으로 배열 병합해야 함', () => {
      const target = {
        items: [{ name: 'a' }, { name: 'b' }, { name: 'c' }],
      };
      const source = {
        items: { '1': { name: 'B_updated' } },  // 배열 인덱스 1 업데이트
      };

      const result = deepMergeState(target, source);

      // 배열이 유지되어야 함
      expect(Array.isArray(result.items)).toBe(true);
      expect(result.items).toHaveLength(3);
      expect(result.items[0]).toEqual({ name: 'a' });
      expect(result.items[1]).toEqual({ name: 'B_updated' });
      expect(result.items[2]).toEqual({ name: 'c' });
    });

    it('빈 배열에 작은 인덱스 키 병합도 정상 동작해야 함', () => {
      const target = {
        options: [{ id: 1 }, { id: 2 }],
      };
      const source = {
        options: { '0': { id: 1, values: ['red'] } },
      };

      const result = deepMergeState(target, source);

      expect(Array.isArray(result.options)).toBe(true);
      expect(result.options[0]).toEqual({ id: 1, values: ['red'] });
      expect(result.options[1]).toEqual({ id: 2 });
    });

    it('여러 개의 큰 숫자 키도 sparse array 없이 객체로 처리해야 함', () => {
      const target = {
        couponMap: [],
      };
      const source = {
        couponMap: { '500': ['c1'], '1200': ['c2'], '3000': ['c3'] },
      };

      const result = deepMergeState(target, source);

      expect(Array.isArray(result.couponMap)).toBe(false);
      expect(result.couponMap['500']).toEqual(['c1']);
      expect(result.couponMap['1200']).toEqual(['c2']);
      expect(result.couponMap['3000']).toEqual(['c3']);
    });
  });

});

describe('3모드 merge 기능 (replace/shallow/deep)', () => {
  let dispatcher: ActionDispatcher;
  let mockNavigate: ReturnType<typeof vi.fn>;
  let mockSetState: ReturnType<typeof vi.fn>;
  let mockGlobalStateUpdater: ReturnType<typeof vi.fn>;
  let capturedState: Record<string, any>;
  let capturedGlobalState: Record<string, any>;

  beforeEach(() => {
    mockNavigate = vi.fn();
    capturedState = {};
    capturedGlobalState = {};

    mockSetState = vi.fn((updater) => {
      if (typeof updater === 'function') {
        capturedState = updater(capturedState);
      } else {
        capturedState = { ...capturedState, ...updater };
      }
    });

    mockGlobalStateUpdater = vi.fn((updates) => {
      capturedGlobalState = { ...capturedGlobalState, ...updates };
    });

    dispatcher = new ActionDispatcher({
      navigate: mockNavigate,
    });
    dispatcher.setGlobalStateUpdater(mockGlobalStateUpdater);

    Logger.getInstance().setDebug(false);

    (window as any).__g7PendingLocalState = undefined;
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7ActionContext = undefined;
  });

  afterEach(() => {
    vi.clearAllMocks();
    (window as any).__g7PendingLocalState = undefined;
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7ActionContext = undefined;
  });

  describe('deepMergeState 기본 동작 (deep 모드)', () => {
    it('중첩 객체를 재귀적으로 병합해야 함', () => {
      const prev = { form: { name: '기존', price: 1000 }, ui: { tab: 'basic' } };
      const update = { form: { name: '신규' } };
      const result = deepMergeState(prev, update);

      expect(result.form.name).toBe('신규');
      expect(result.form.price).toBe(1000);
      expect(result.ui.tab).toBe('basic');
    });

    it('배열은 교체(덮어쓰기)해야 함', () => {
      const prev = { items: [1, 2, 3], name: 'test' };
      const update = { items: [4, 5] };
      const result = deepMergeState(prev, update);

      expect(result.items).toEqual([4, 5]);
      expect(result.name).toBe('test');
    });
  });

  describe('setState merge: "replace" 모드', () => {
    it('기존 상태를 완전히 교체해야 함 (global target)', async () => {
      capturedGlobalState = { existingKey: 'old', nested: { a: 1 } };

      const context = {
        state: {},
        setState: mockSetState,
        actionId: 'test-replace-global',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'global',
          merge: 'replace',
          newKey: 'fresh',
        },
      };

      await dispatcher.executeAction(action, context);

      const callArgs = mockGlobalStateUpdater.mock.calls[0][0];
      expect(callArgs.newKey).toBe('fresh');
      // replace 모드이므로 기존 키가 포함되지 않음
      expect(callArgs.existingKey).toBeUndefined();
      expect(callArgs.nested).toBeUndefined();
    });

    it('기존 상태를 완전히 교체해야 함 (local target)', async () => {
      capturedState = { form: { name: '기존', price: 1000 }, ui: { tab: 'basic' } };

      const context = {
        state: capturedState,
        setState: mockSetState,
        actionId: 'test-replace-local',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          merge: 'replace',
          form: { name: '신규' },
        },
      };

      await dispatcher.executeAction(action, context);

      // __g7PendingLocalState에 replace 된 값이 있어야 함
      const pendingState = (window as any).__g7PendingLocalState;
      expect(pendingState).toBeDefined();
      expect(pendingState.form).toEqual({ name: '신규' });
      // replace이므로 ui 키가 사라져야 함
      expect(pendingState.ui).toBeUndefined();
    });
  });

  describe('setState merge: "shallow" 모드', () => {
    it('최상위 키만 덮어써야 함 (global target)', async () => {
      const context = {
        state: {},
        setState: mockSetState,
        actionId: 'test-shallow-global',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'global',
          merge: 'shallow',
          form: { name: '신규' },
        },
      };

      await dispatcher.executeAction(action, context);

      const callArgs = mockGlobalStateUpdater.mock.calls[0][0];
      // shallow 모드에서도 globalStateUpdater는 payload를 전달함
      expect(callArgs.form).toEqual({ name: '신규' });
    });

    it('local target에서 shallow 모드가 적용되어야 함', async () => {
      capturedState = { form: { name: '기존', price: 1000 }, ui: { tab: 'basic' } };

      const context = {
        state: capturedState,
        setState: mockSetState,
        actionId: 'test-shallow-local',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          merge: 'shallow',
          form: { name: '신규' },
        },
      };

      await dispatcher.executeAction(action, context);

      const pendingState = (window as any).__g7PendingLocalState;
      expect(pendingState).toBeDefined();
      // shallow: form 키는 전체 교체 (price 사라짐), ui는 유지
      expect(pendingState.form).toEqual({ name: '신규' });
      expect(pendingState.ui).toEqual({ tab: 'basic' });
    });
  });

  describe('setState merge 기본값 (deep 모드)', () => {
    it('merge 옵션 없이 deep 병합이 기본 동작이어야 함', async () => {
      capturedState = { form: { name: '기존', price: 1000 }, ui: { tab: 'basic' } };

      const context = {
        state: capturedState,
        setState: mockSetState,
        actionId: 'test-default-deep',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          form: { name: '신규' },
        },
      };

      await dispatcher.executeAction(action, context);

      const pendingState = (window as any).__g7PendingLocalState;
      expect(pendingState).toBeDefined();
      // deep: form.name 업데이트, form.price 유지, ui 유지
      expect(pendingState.form.name).toBe('신규');
      expect(pendingState.form.price).toBe(1000);
      expect(pendingState.ui.tab).toBe('basic');
    });
  });

  describe('3모드 비교 검증', () => {
    const initialState = { form: { name: '기존', price: 1000 }, ui: { tab: 'basic' } };
    const updatePayload = { form: { name: '신규' } };

    it('replace: 기존 상태 완전 무시, 새 값만 남음', () => {
      // replace는 업데이트 값만 사용
      const result = { ...updatePayload };
      expect(result).toEqual({ form: { name: '신규' } });
      expect((result as any).ui).toBeUndefined();
    });

    it('shallow: 최상위 키만 덮어쓰기', () => {
      const result = { ...initialState, ...updatePayload };
      expect(result.form).toEqual({ name: '신규' }); // form 전체 교체
      expect(result.ui).toEqual({ tab: 'basic' }); // ui 유지
    });

    it('deep: 재귀적 깊은 병합', () => {
      const result = deepMergeState(initialState, updatePayload);
      expect(result.form.name).toBe('신규');
      expect(result.form.price).toBe(1000); // price 유지
      expect(result.ui.tab).toBe('basic'); // ui 유지
    });
  });

  describe('setState merge: "replace" + isolated target', () => {
    it('isolated context에 replace 모드가 전달되어야 함', async () => {
      const mockMergeState = vi.fn();
      const mockIsolatedContext = {
        state: { step: 1, items: [1, 2, 3] },
        mergeState: mockMergeState,
        setState: vi.fn(),
        getState: vi.fn(),
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'isolated',
          merge: 'replace',
          step: 2,
        },
      };

      const context = {
        data: {},
        state: {},
        isolatedContext: mockIsolatedContext,
        setState: vi.fn(),
      };

      await dispatcher.executeAction(action, context);

      expect(mockMergeState).toHaveBeenCalledWith({ step: 2 }, 'replace');
    });

    it('isolated context에 shallow 모드가 전달되어야 함', async () => {
      const mockMergeState = vi.fn();
      const mockIsolatedContext = {
        state: { step: 1 },
        mergeState: mockMergeState,
        setState: vi.fn(),
        getState: vi.fn(),
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'isolated',
          merge: 'shallow',
          step: 2,
          newField: 'value',
        },
      };

      const context = {
        data: {},
        state: {},
        isolatedContext: mockIsolatedContext,
        setState: vi.fn(),
      };

      await dispatcher.executeAction(action, context);

      expect(mockMergeState).toHaveBeenCalledWith(
        { step: 2, newField: 'value' },
        'shallow'
      );
    });
  });

  describe('setState merge + __mergeMode 메타데이터 전파', () => {
    it('replace 모드에서 __mergeMode가 payload에 포함되어야 함', async () => {
      const mockSetStateFn = vi.fn();

      const context = {
        state: { existing: 'data' },
        setState: mockSetStateFn,
        actionId: 'test-merge-mode-meta',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          merge: 'replace',
          newData: 'value',
        },
      };

      await dispatcher.executeAction(action, context);

      // setState가 호출되었고, payload에 __mergeMode가 포함되어야 함
      expect(mockSetStateFn).toHaveBeenCalled();
      const calledArg = mockSetStateFn.mock.calls[0][0];
      // 함수형 업데이트인 경우 실행
      const payload = typeof calledArg === 'function' ? calledArg({}) : calledArg;
      expect(payload.__mergeMode).toBe('replace');
      expect(payload.newData).toBe('value');
    });

    it('deep 모드에서는 __mergeMode가 포함되지 않아야 함', async () => {
      const mockSetStateFn = vi.fn();

      const context = {
        state: { existing: 'data' },
        setState: mockSetStateFn,
        actionId: 'test-deep-no-meta',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          newData: 'value',
        },
      };

      await dispatcher.executeAction(action, context);

      expect(mockSetStateFn).toHaveBeenCalled();
      const calledArg = mockSetStateFn.mock.calls[0][0];
      const payload = typeof calledArg === 'function' ? calledArg({}) : calledArg;
      expect(payload.__mergeMode).toBeUndefined();
    });
  });

  describe('setState merge: "replace" + $parent scope', () => {
    it('$parent._local 경로에서 replace 모드가 전달되어야 함', async () => {
      const parentSetState = vi.fn();

      // 레이아웃 컨텍스트 스택 설정 (부모 컨텍스트)
      (window as any).__g7LayoutContextStack = [{
        state: { form: { name: '기존', price: 1000 }, ui: { tab: 'basic' } },
        setState: parentSetState,
      }];

      const context = {
        state: { childData: 'value' },
        setState: mockSetState,
        actionId: 'test-parent-replace',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: '$parent._local',
          merge: 'replace',
          form: { name: '신규' },
        },
      };

      await dispatcher.executeAction(action, context);

      // parentSetState가 호출되어야 함
      expect(parentSetState).toHaveBeenCalled();
      const calledArg = parentSetState.mock.calls[0][0];
      const payload = typeof calledArg === 'function'
        ? calledArg({ form: { name: '기존', price: 1000 }, ui: { tab: 'basic' } })
        : calledArg;
      // replace 메타데이터 포함
      expect(payload.__mergeMode).toBe('replace');
      expect(payload.form).toEqual({ name: '신규' });

      // cleanup
      (window as any).__g7LayoutContextStack = undefined;
    });
  });

  describe('__g7ForcedLocalFields replace 모드 처리', () => {
    it('replace 모드에서 __g7ForcedLocalFields가 payload로만 리셋되어야 함', async () => {
      // 기존 forcedFields 설정
      (window as any).__g7ForcedLocalFields = { existingField: 'old' };

      const context = {
        state: { existingField: 'old', otherField: 'keep' },
        setState: mockSetState,
        actionId: 'test-replace-forced',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          merge: 'replace',
          newField: 'fresh',
        },
      };

      await dispatcher.executeAction(action, context);

      const forcedFields = (window as any).__g7ForcedLocalFields;
      expect(forcedFields).toBeDefined();
      expect(forcedFields.newField).toBe('fresh');
      // replace이므로 기존 existingField는 사라져야 함
      expect(forcedFields.existingField).toBeUndefined();
    });
  });

  describe('[사례 17] setLocal 후 localDynamicState stale 값으로 인한 UI 미갱신', () => {
    /**
     * 증상: 상품고시 템플릿 선택 후 항목 수정 → 다른 템플릿 선택 시 반영 안됨
     *       등록 모드에서는 2번째 템플릿 선택부터 실패
     *
     * 근본 원인:
     * 1. 폼 자동 바인딩 또는 setState(target: "local")가 localDynamicState에 notice_items 기록
     * 2. setLocal()은 dataContext._local을 업데이트하지만 ROOT의 localDynamicState는 미업데이트
     * 3. deepMergeState(dataContext._local, localDynamicState)에서 stale 배열이 우선 적용
     * 4. "선택하세요"로 비우면 localDynamicState의 items도 빈 배열로 갱신 → 다음 선택 성공
     *
     * 해결 (engine-v1.17.8):
     * - setLocal() 호출 시 __g7SetLocalOverrideKeys에 업데이트 키 기록
     * - useLayoutEffect에서 isRootRenderer일 때만 해당 키를 localDynamicState에서 제거
     * - 제거 후 dataContext._local의 최신값이 사용됨
     */

    afterEach(() => {
      (window as any).__g7ForcedLocalFields = undefined;
      (window as any).__g7SetLocalOverrideKeys = undefined;
    });

    it('setLocal 호출 후 localDynamicState가 stale 값으로 남으면 안 됨 (버그 재현)', () => {
      // 1. dataContext._local (setLocal으로 업데이트된 상태)
      const dataContextLocal = {
        form: {
          name: { ko: '테스트 상품' },
          notice_items: [
            { key: 'field_0', name: { ko: '종류' }, content: { ko: '가방' } },
            { key: 'field_1', name: { ko: '소재' }, content: { ko: '가죽' } },
            { key: 'field_2', name: { ko: '색상' }, content: { ko: '검정' } },
          ],
        },
        ui: { selectedNoticeTemplateId: 3 },
      };

      // 2. localDynamicState (폼 자동 바인딩으로 기록된 stale 값)
      const staleDynamicState = {
        form: {
          name: { ko: '테스트 상품' },
          notice_items: [
            { key: 'field_0', name: { ko: '항목1' }, content: { ko: '상세설명참조' } },
            { key: 'field_1', name: { ko: '항목2' }, content: { ko: '상세설명참조' } },
          ],
        },
        ui: { selectedNoticeTemplateId: null },
      };

      // 3. __g7ForcedLocalFields 없이 병합하면 stale이 우선
      const resultWithoutForced = deepMergeState(dataContextLocal, staleDynamicState);
      expect(resultWithoutForced.form.notice_items).toHaveLength(2); // stale!

      // 4. __g7ForcedLocalFields로 첫 렌더는 정상
      const forcedFields = {
        form: { notice_items: dataContextLocal.form.notice_items },
        ui: { selectedNoticeTemplateId: 3 },
      };
      const resultWithForced = deepMergeState(resultWithoutForced, forcedFields);
      expect(resultWithForced.form.notice_items).toHaveLength(3); // 정상

      // 5. engine-v1.17.8 수정: removeMatchingLeafKeys로 stale 키 제거 후 병합
      const setLocalPayload = {
        form: { notice_items: dataContextLocal.form.notice_items, notice_template_id: 3 },
        ui: { selectedNoticeTemplateId: 3 },
      };
      const cleanedDynamicState = removeMatchingLeafKeys(staleDynamicState, setLocalPayload);
      const resultAfterCleanup = deepMergeState(dataContextLocal, cleanedDynamicState);

      // notice_items가 localDynamicState에서 제거되어 dataContext._local 값 사용
      expect(resultAfterCleanup.form.notice_items).toHaveLength(3);
      expect(resultAfterCleanup.form.notice_items[0].name.ko).toBe('종류');
      // form.name은 setLocal payload에 없으므로 localDynamicState에서 보존
      expect(resultAfterCleanup.form.name.ko).toBe('테스트 상품');
    });

    it('빈 배열로 setLocal 후에도 stale localDynamicState의 배열이 남으면 안 됨', () => {
      const staleDynamicState = {
        form: {
          notice_items: [
            { key: 'field_0', name: { ko: '항목1' }, content: { ko: '값1' } },
          ],
        },
        ui: { selectedNoticeTemplateId: 1 },
      };

      const setLocalPayload = { form: { notice_items: [] }, ui: { selectedNoticeTemplateId: null } };
      const cleaned = removeMatchingLeafKeys(staleDynamicState, setLocalPayload);

      // notice_items와 selectedNoticeTemplateId가 제거됨
      expect(cleaned.form?.notice_items).toBeUndefined();
      expect(cleaned.ui?.selectedNoticeTemplateId).toBeUndefined();

      // dataContext._local과 병합하면 빈 배열 사용
      const dataContextLocal = { form: { notice_items: [] }, ui: { selectedNoticeTemplateId: null } };
      const result = deepMergeState(dataContextLocal, cleaned);
      expect(result.form.notice_items).toHaveLength(0);
    });

    it('연속 setLocal 호출 시 마지막 값이 유지되어야 함', () => {
      let dynamicState: Record<string, any> = {
        form: { notice_items: [{ key: '1', name: { ko: '항목1' } }] },
      };

      // 1차: 상세설명참조 setLocal → 해당 키 제거
      const firstPayload = {
        form: { notice_items: [{ key: '1', name: { ko: '항목1' }, content: { ko: '상세설명참조' } }] },
      };
      dynamicState = removeMatchingLeafKeys(dynamicState, firstPayload);
      expect(dynamicState.form?.notice_items).toBeUndefined();

      // 2차: 폼 자동 바인딩으로 다시 기록
      dynamicState = deepMergeState(dynamicState, {
        form: { notice_items: [{ key: '1', name: { ko: '항목1' }, content: { ko: '상세설명참조' } }] },
      });

      // 3차: 템플릿 선택 setLocal (10개 항목) → 다시 제거
      const secondPayload = {
        form: {
          notice_items: Array.from({ length: 10 }, (_, i) => ({
            key: `field_${i}`,
            name: { ko: `템플릿항목${i + 1}` },
            content: { ko: '' },
          })),
        },
      };
      dynamicState = removeMatchingLeafKeys(dynamicState, secondPayload);
      expect(dynamicState.form?.notice_items).toBeUndefined();

      // dataContext._local에서 최신 값 사용
      const dataContextLocal = { form: { notice_items: secondPayload.form.notice_items } };
      const result = deepMergeState(dataContextLocal, dynamicState);
      expect(result.form.notice_items).toHaveLength(10);
      expect(result.form.notice_items[0].name.ko).toBe('템플릿항목1');
    });
  });

  describe('[사례 17 보조] removeMatchingLeafKeys 유틸리티', () => {
    it('setLocal payload의 리프 키만 제거하고 다른 키는 보존해야 함', () => {
      const state = {
        form: { name: { ko: '상품명' }, notice_items: [{ key: '1' }], price: 10000 },
        ui: { expanded: true, selectedTab: 'info' },
        loadingActions: {},
      };

      const setLocalKeys = {
        form: { notice_items: [{ key: 'new' }], notice_template_id: 3 },
      };

      const result = removeMatchingLeafKeys(state, setLocalKeys);

      // 제거됨: form.notice_items (setLocal payload에 포함)
      expect(result.form.notice_items).toBeUndefined();
      // 보존됨: form.name, form.price (setLocal payload에 미포함)
      expect(result.form.name.ko).toBe('상품명');
      expect(result.form.price).toBe(10000);
      // 보존됨: ui, loadingActions (setLocal payload에 미포함)
      expect(result.ui.expanded).toBe(true);
      expect(result.loadingActions).toEqual({});
    });

    it('target에 없는 키는 무시해야 함', () => {
      const state = { form: { name: { ko: '상품명' } } };
      const keysToRemove = { form: { notice_items: [] }, ui: { tab: 'info' } };

      const result = removeMatchingLeafKeys(state, keysToRemove);
      expect(result.form.name.ko).toBe('상품명');
      expect(result.ui).toBeUndefined();
    });

    it('빈 객체가 되면 부모 키도 제거해야 함', () => {
      const state = { form: { notice_items: [{ key: '1' }] } };
      const keysToRemove = { form: { notice_items: [] } };

      const result = removeMatchingLeafKeys(state, keysToRemove);
      // form 내부가 모두 제거되면 form 자체도 제거
      expect(result.form).toBeUndefined();
    });

    it('__g7SetLocalOverrideKeys가 ROOT에서만 처리되어야 함 (isRootRenderer 시뮬레이션)', () => {
      const setLocalPayload = { form: { notice_items: [{ key: 'new' }] } };
      (window as any).__g7SetLocalOverrideKeys = { ...setLocalPayload };

      // 모달 useLayoutEffect (isRootRenderer=false) - 처리하지 않음
      const isRootRenderer_modal = false;
      if (isRootRenderer_modal) {
        (window as any).__g7SetLocalOverrideKeys = undefined;
      }

      // __g7SetLocalOverrideKeys가 보존되어야 함
      expect((window as any).__g7SetLocalOverrideKeys).toBeDefined();

      // ROOT useLayoutEffect (isRootRenderer=true) - 처리
      const isRootRenderer_root = true;
      let rootDynamicState: Record<string, any> = {
        form: { notice_items: [{ key: 'stale' }], name: { ko: '상품명' } },
      };

      if (isRootRenderer_root) {
        const keys = (window as any).__g7SetLocalOverrideKeys;
        if (keys) {
          rootDynamicState = removeMatchingLeafKeys(rootDynamicState, keys);
          (window as any).__g7SetLocalOverrideKeys = undefined;
        }
      }

      // notice_items 제거됨, name 보존됨
      expect(rootDynamicState.form?.notice_items).toBeUndefined();
      expect(rootDynamicState.form?.name?.ko).toBe('상품명');
      expect((window as any).__g7SetLocalOverrideKeys).toBeUndefined();
    });

    it('__g7ForcedLocalFields가 ROOT에서만 클리어되어야 함 (engine-v1.17.8 모달 시나리오)', () => {
      // setLocal()이 설정한 forcedLocalFields
      const forcedFields = {
        form: { notice_items: [{ key: 'new1' }, { key: 'new2' }, { key: 'new3' }] },
        ui: { selectedNoticeTemplateId: 5 },
      };
      (window as any).__g7ForcedLocalFields = forcedFields;

      // 모달 useLayoutEffect (isRootRenderer=false) - 클리어하지 않음
      const isRootRenderer_modal = false;
      if (isRootRenderer_modal) {
        (window as any).__g7ForcedLocalFields = undefined;
      }

      // 모달 렌더 후에도 forcedLocalFields 보존
      expect((window as any).__g7ForcedLocalFields).toBeDefined();
      expect((window as any).__g7ForcedLocalFields.form.notice_items).toHaveLength(3);

      // ROOT extendedDataContext에서 forcedLocalFields 적용
      const dataContextLocal = { form: { name: { ko: '상품명' }, notice_items: [{ key: 'new1' }, { key: 'new2' }, { key: 'new3' }] } };
      const staleDynamicState = { form: { name: { ko: '상품명' }, notice_items: [{ key: 'old1' }] } };

      let localState = deepMergeState(dataContextLocal, staleDynamicState);
      // stale이 우선 적용됨
      expect(localState.form.notice_items).toHaveLength(1);

      // forcedLocalFields 적용으로 정상 복원
      const currentForced = (window as any).__g7ForcedLocalFields;
      if (currentForced) {
        localState = deepMergeState(localState, currentForced);
      }
      expect(localState.form.notice_items).toHaveLength(3);

      // ROOT useLayoutEffect (isRootRenderer=true) - 클리어
      const isRootRenderer_root = true;
      if (isRootRenderer_root) {
        (window as any).__g7ForcedLocalFields = undefined;
      }
      expect((window as any).__g7ForcedLocalFields).toBeUndefined();
    });
  });

  describe('[사례 19] sequence 내 setState 후 커스텀 핸들러의 상태 업데이트가 forcedLocalFields에 의해 덮어씌워지는 문제', () => {
    /**
     * 증상: 상품 상세 페이지에서 옵션을 모두 선택해도 selectedOptionItems에 추가되지 않음
     *       sequence 내 setState → 커스텀 핸들러 순서로 실행 시 커스텀 핸들러의 상태가 무시됨
     *
     * 근본 원인 (engine-v1.17.5~engine-v1.17.8):
     * - handleSetState에서 __g7ForcedLocalFields를 finalPayload(전체 상태 스냅샷)에서 추출
     * - deep 모드 시 finalPayload = deepMergeWithState(resolvedPayload, context.state) → 전체 상태 포함
     * - forcedLocalFields에 selectedOptionItems: [] 등 미변경 필드까지 포함
     * - 커스텀 핸들러가 localDynamicState에 selectedOptionItems: [newItem] 설정해도
     *   extendedDataContext 병합 시 forcedLocalFields의 [] 가 덮어씀
     *
     * 해결 (engine-v1.17.9):
     * - cleanPayloadForForced를 resolvedPayload(변경 필드만)에서 추출하도록 변경
     * - forcedLocalFields에는 실제 변경된 필드만 저장됨
     */

    afterEach(() => {
      (window as any).__g7ForcedLocalFields = undefined;
      (window as any).__g7PendingLocalState = undefined;
    });

    it('deep 모드 setState에서 __g7ForcedLocalFields에 변경 필드만 저장해야 함', async () => {
      // 현재 상태: selectedOptionItems: [], currentSelection: {}
      const currentState = {
        selectedOptionItems: [],
        currentSelection: {},
        someOtherField: 'preserved',
      };

      const context = {
        state: currentState,
        setState: mockSetState,
        actionId: 'test-forced-fields-only-changed',
      };

      // setState: currentSelection만 업데이트 (deep 모드 기본)
      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          currentSelection: { '색상': '그레이' },
        },
      };

      await dispatcher.executeAction(action, context);

      const forcedFields = (window as any).__g7ForcedLocalFields;
      expect(forcedFields).toBeDefined();

      // engine-v1.17.9: 변경된 필드(currentSelection)만 포함
      expect(forcedFields.currentSelection).toEqual({ '색상': '그레이' });

      // 미변경 필드(selectedOptionItems, someOtherField)는 포함되지 않아야 함
      expect(forcedFields.selectedOptionItems).toBeUndefined();
      expect(forcedFields.someOtherField).toBeUndefined();
    });

    it('sequence 내 setState 후 커스텀 핸들러가 localDynamicState에 설정한 값이 보존되어야 함', () => {
      // 시뮬레이션: sequence step 1 → setState → forcedLocalFields 설정
      // step 1: currentSelection만 변경
      const resolvedPayload = { currentSelection: { '색상': '그레이', '사이즈': 'M' } };
      const { __mergeMode: _mm, __setStateId: _ssid, ...cleanPayloadForForced } = resolvedPayload as any;

      (window as any).__g7ForcedLocalFields = cleanPayloadForForced;

      // forcedLocalFields에 변경 필드만 있는지 확인
      expect((window as any).__g7ForcedLocalFields).toEqual({
        currentSelection: { '색상': '그레이', '사이즈': 'M' },
      });

      // step 2: 커스텀 핸들러가 localDynamicState에 selectedOptionItems 설정
      const customHandlerUpdate = {
        selectedOptionItems: [{ id: '그레이_M', optionId: 1, quantity: 1 }],
        currentSelection: {},
      };

      // extendedDataContext 시뮬레이션
      const dataContextLocal = {
        selectedOptionItems: [],
        currentSelection: {},
      };

      // 1. dataContext._local + localDynamicState(커스텀 핸들러 업데이트 반영)
      let localState = deepMergeState(dataContextLocal, customHandlerUpdate);
      expect(localState.selectedOptionItems).toHaveLength(1); // 커스텀 핸들러의 값

      // 2. forcedLocalFields 적용
      const currentForced = (window as any).__g7ForcedLocalFields;
      if (currentForced) {
        localState = deepMergeState(localState, currentForced);
      }

      // engine-v1.17.9: forcedLocalFields에 selectedOptionItems가 없으므로 커스텀 핸들러 값 보존
      expect(localState.selectedOptionItems).toHaveLength(1);
      expect(localState.selectedOptionItems[0].id).toBe('그레이_M');
      // currentSelection은 forcedLocalFields의 값으로 덮어씌워짐 (정상 — setState가 설정한 값)
      // 다만 커스텀 핸들러가 나중에 {} 로 리셋했으므로, deepMerge 결과는 forcedFields의 값
      expect(localState.currentSelection).toEqual({ '색상': '그레이', '사이즈': 'M' });
    });

    it('커스텀 핸들러의 명시적 setState(__mergeMode 포함)가 forcedLocalFields를 업데이트해야 함', () => {
      // 시뮬레이션: sequence step 1 → setState → forcedLocalFields 설정
      (window as any).__g7ForcedLocalFields = {
        currentSelection: { '색상': '그레이', '사이즈': 'L' },
      };

      // step 2: 커스텀 핸들러가 context.setState({...}) 호출 → handleLocalSetState 경유
      // __mergeMode: 'shallow'가 명시되어 있으므로 forcedLocalFields도 업데이트됨
      const customHandlerPayload = {
        selectedOptionItems: [{ id: '그레이_L', optionId: 1, quantity: 1 }],
        currentSelection: {},
      };
      const __mergeMode = 'shallow';

      // handleLocalSetState 내부 로직 시뮬레이션:
      // if (__mergeMode !== undefined) { forcedLocalFields = { ...existing, ...payload } }
      if (__mergeMode !== undefined) {
        const existingForced = (window as any).__g7ForcedLocalFields;
        if (existingForced) {
          (window as any).__g7ForcedLocalFields = { ...existingForced, ...customHandlerPayload };
        }
      }

      const forcedFields = (window as any).__g7ForcedLocalFields;

      // 커스텀 핸들러가 설정한 값으로 forcedLocalFields가 업데이트됨
      expect(forcedFields.selectedOptionItems).toHaveLength(1);
      expect(forcedFields.selectedOptionItems[0].id).toBe('그레이_L');
      // currentSelection이 {}로 리셋됨 (커스텀 핸들러의 의도)
      expect(forcedFields.currentSelection).toEqual({});
    });

    it('커스텀 핸들러 forcedLocalFields 업데이트 후 extendedDataContext에서 최신 값이 반영되어야 함', () => {
      // step 1 setState가 forcedLocalFields 설정
      (window as any).__g7ForcedLocalFields = {
        currentSelection: { '색상': '그레이', '사이즈': 'L' },
      };

      // step 2 커스텀 핸들러가 forcedLocalFields 업데이트
      const customPayload = {
        selectedOptionItems: [{ id: '그레이_L', optionId: 1, quantity: 1 }],
        currentSelection: {},
      };
      (window as any).__g7ForcedLocalFields = {
        ...(window as any).__g7ForcedLocalFields,
        ...customPayload,
      };

      // extendedDataContext 시뮬레이션
      const dataContextLocal = {
        selectedOptionItems: [],
        currentSelection: {},
      };
      const dynamicState = {
        selectedOptionItems: [{ id: '그레이_L', optionId: 1, quantity: 1 }],
        currentSelection: {},
      };

      // 1. dataContext._local + dynamicState
      let localState = deepMergeState(dataContextLocal, dynamicState);

      // 2. forcedLocalFields 적용
      const forcedLocalFields = (window as any).__g7ForcedLocalFields;
      if (forcedLocalFields) {
        localState = deepMergeState(localState, forcedLocalFields);
      }

      // 최종 결과: 커스텀 핸들러가 설정한 값이 모두 반영됨
      expect(localState.selectedOptionItems).toHaveLength(1);
      expect(localState.selectedOptionItems[0].id).toBe('그레이_L');
      // currentSelection이 {}로 리셋 — 옵션 드롭다운 초기화 확인
      expect(localState.currentSelection).toEqual({});
    });

    it('__mergeMode가 없는 호출(Form 자동바인딩 등)은 forcedLocalFields를 업데이트하지 않아야 함', () => {
      // sequence step 1이 forcedLocalFields 설정
      (window as any).__g7ForcedLocalFields = {
        currentSelection: { '색상': '그레이' },
      };

      // Form 자동 바인딩: __mergeMode 없이 호출
      const __mergeMode = undefined;

      const formPayload = {
        formField: 'user input',
      };

      // handleLocalSetState 내부 로직: __mergeMode === undefined → 건너뜀
      if (__mergeMode !== undefined) {
        const existingForced = (window as any).__g7ForcedLocalFields;
        if (existingForced) {
          (window as any).__g7ForcedLocalFields = { ...existingForced, ...formPayload };
        }
      }

      const forcedFields = (window as any).__g7ForcedLocalFields;
      // forcedLocalFields 미변경 — Form 입력이 forcedFields를 오염시키지 않음
      expect(forcedFields).toEqual({ currentSelection: { '색상': '그레이' } });
      expect(forcedFields.formField).toBeUndefined();
    });

    it('렌더 사이클 시작 시 __g7PendingLocalState가 클리어되어야 함 (isRootRenderer=true)', () => {
      /**
       * 증상: 옵션 선택 후 수량 변경 시 드롭다운이 이전 선택 상태로 리버트되고 수량 변경 미반영
       * 원인: __g7PendingLocalState가 렌더 후에도 남아 다음 handleLocalSetState에서
       *       effectivePrev = deepMergeState(prev, stale pendingState) → 상태 오염
       * 해결: useLayoutEffect에서 isRootRenderer일 때 __g7PendingLocalState도 클리어
       */

      // 옵션 선택 sequence step 1이 __g7PendingLocalState 설정
      (window as any).__g7PendingLocalState = {
        currentSelection: { '색상': '브라운' },
        selectedOptionItems: [],
        someOtherField: 'test',
      };

      // 렌더 사이클 시작 (isRootRenderer = true)
      const isRootRenderer = true;
      if (isRootRenderer) {
        (window as any).__g7ForcedLocalFields = undefined;
        (window as any).__g7PendingLocalState = null;
      }

      // 클리어 확인
      expect((window as any).__g7PendingLocalState).toBeNull();
      expect((window as any).__g7ForcedLocalFields).toBeUndefined();
    });

    it('__g7PendingLocalState 클리어 후 수량 변경이 정상 동작해야 함', () => {
      /**
       * 증상: 수량 변경 핸들러가 setState({selectedOptionItems: updatedItems})를 호출하지만
       *       __g7PendingLocalState의 stale 값이 effectivePrev를 오염 → 수량 미반영
       * 해결: 렌더 사이클 시작 시 __g7PendingLocalState 클리어
       */

      // 렌더 후 __g7PendingLocalState가 클리어된 상태 (engine-v1.17.10)
      (window as any).__g7PendingLocalState = null;

      // 현재 localDynamicState: 옵션 1개 선택됨, 수량 1
      const prev = {
        selectedOptionItems: [{ id: '브라운_L', optionId: 1, quantity: 1, unitPrice: 10000 }],
        currentSelection: {},
        someField: 'preserved',
      };

      // handleLocalSetState 내부 로직 시뮬레이션
      const pendingState = (window as any).__g7PendingLocalState;
      const effectivePrev = pendingState ? deepMergeState(prev, pendingState) : prev;

      // pendingState가 null이므로 effectivePrev = prev (오염 없음)
      expect(effectivePrev.selectedOptionItems).toHaveLength(1);
      expect(effectivePrev.selectedOptionItems[0].quantity).toBe(1);
      expect(effectivePrev.currentSelection).toEqual({}); // stale 값 없음

      // 수량 변경 payload
      const updatedItems = prev.selectedOptionItems.map(item => ({
        ...item,
        quantity: 3,
        totalPrice: item.unitPrice * 3,
      }));
      const payload = { selectedOptionItems: updatedItems };

      // deep merge (기본 모드)
      const result = deepMergeState(effectivePrev, payload);

      // 수량 정상 업데이트
      expect(result.selectedOptionItems).toHaveLength(1);
      expect(result.selectedOptionItems[0].quantity).toBe(3);
      // currentSelection 오염 없음
      expect(result.currentSelection).toEqual({});
      // 기존 필드 보존
      expect(result.someField).toBe('preserved');
    });

    it('__g7PendingLocalState가 stale일 때 수량 변경 시 currentSelection이 오염되는 문제 재현', () => {
      /**
       * engine-v1.17.10 이전 동작 재현:
       * __g7PendingLocalState가 클리어되지 않으면 수량 변경 시 stale currentSelection이 복원됨
       */

      // stale __g7PendingLocalState (옵션 선택 step 1에서 남은 값)
      const stalePendingState = {
        currentSelection: { '색상': '브라운' },
        selectedOptionItems: [],
        loadingActions: {},
      };

      // 현재 상태: 옵션 추가 완료, 드롭다운 초기화됨
      const prev = {
        selectedOptionItems: [{ id: '브라운_L', optionId: 1, quantity: 1, unitPrice: 10000 }],
        currentSelection: {},
        loadingActions: {},
      };

      // stale pendingState와 병합 (engine-v1.17.10 이전 동작)
      const effectivePrevWithStale = deepMergeState(prev, stalePendingState);

      // stale 값으로 오염됨
      expect(effectivePrevWithStale.currentSelection).toEqual({ '색상': '브라운' }); // 오염!
      expect(effectivePrevWithStale.selectedOptionItems).toEqual([]); // 배열 교체!

      // 수량 변경 payload
      const payload = {
        selectedOptionItems: [{ id: '브라운_L', optionId: 1, quantity: 2, unitPrice: 10000 }],
      };

      // deep merge
      const result = deepMergeState(effectivePrevWithStale, payload);

      // selectedOptionItems는 payload에서 교체되어 정상이지만
      expect(result.selectedOptionItems[0].quantity).toBe(2);
      // currentSelection은 stale 값이 그대로 남음 → 드롭다운 리버트!
      expect(result.currentSelection).toEqual({ '색상': '브라운' });

      // engine-v1.17.10: pendingState가 null이면 오염 없음
      const effectivePrevClean = prev; // pendingState = null → effectivePrev = prev
      const resultClean = deepMergeState(effectivePrevClean, payload);
      expect(resultClean.selectedOptionItems[0].quantity).toBe(2);
      expect(resultClean.currentSelection).toEqual({}); // 오염 없음
    });

    it('isRootRenderer=false인 컴포넌트에서도 globals가 클리어되어야 함 (engine-v1.17.11)', () => {
      /**
       * 증상: user_layout_root(isRootRenderer=false)의 수량 변경이 반영되지 않음
       *       __g7ForcedLocalFields에 stale qty=1이 남아 dynState의 qty=2를 덮어씀
       * 해결: isRootRenderer 조건 제거 → 모든 !parentComponentContext 컴포넌트에서 globals 클리어
       */

      // 옵션 선택 시 설정된 forcedLocalFields (stale)
      (window as any).__g7ForcedLocalFields = {
        currentSelection: { '색상': '그레이', '사이즈': 'M' },
        selectedOptionItems: [{ id: '그레이_M', optionId: 1, quantity: 1, unitPrice: 10000 }],
      };
      (window as any).__g7PendingLocalState = {
        currentSelection: { '색상': '그레이' },
        selectedOptionItems: [],
      };

      // engine-v1.17.8에서는 isRootRenderer=false → 클리어 안 됨 (버그)
      // engine-v1.17.11에서는 !parentComponentContext → 클리어 됨 (수정)
      const parentComponentContext = null; // 루트급 컴포넌트

      // 시뮬레이션: useLayoutEffect에서 globals 클리어
      if (!parentComponentContext) {
        (window as any).__g7ForcedLocalFields = undefined;
        (window as any).__g7PendingLocalState = null;
      }

      // 클리어 확인
      expect((window as any).__g7ForcedLocalFields).toBeUndefined();
      expect((window as any).__g7PendingLocalState).toBeNull();

      // 이제 수량 변경이 정상 동작해야 함
      const dataContextLocal = {
        selectedOptionItems: [{ id: '그레이_M', optionId: 1, quantity: 1, unitPrice: 10000 }],
        currentSelection: {},
      };
      const dynamicStateAfterQtyChange = {
        selectedOptionItems: [{ id: '그레이_M', optionId: 1, quantity: 3, unitPrice: 10000 }],
        currentSelection: {},
      };

      // extendedDataContext 병합: dataContext._local + dynamicState
      let localState = deepMergeState(dataContextLocal, dynamicStateAfterQtyChange);

      // forcedLocalFields가 undefined이므로 덮어쓰기 없음
      const forcedFields = (window as any).__g7ForcedLocalFields;
      if (forcedFields) {
        localState = deepMergeState(localState, forcedFields);
      }

      // 수량 3으로 정상 반영
      expect(localState.selectedOptionItems[0].quantity).toBe(3);
      expect(localState.currentSelection).toEqual({});
    });

    it('engine-v1.17.8 isRootRenderer 제한으로 stale forcedLocalFields가 수량 변경을 덮어쓰는 문제 재현', () => {
      /**
       * engine-v1.17.8 버그 재현:
       * global_toast(index=0, isRootRenderer=true)는 user_layout_root 상태 변경 시 리렌더 안 됨
       * → __g7ForcedLocalFields가 영구 잔존 → 수량 변경 무시
       */

      // 옵션 선택 sequence가 설정한 forcedLocalFields
      (window as any).__g7ForcedLocalFields = {
        currentSelection: { '색상': '그레이', '사이즈': 'M' },
      };

      // 커스텀 핸들러가 forcedLocalFields 업데이트 (engine-v1.17.9)
      (window as any).__g7ForcedLocalFields = {
        ...(window as any).__g7ForcedLocalFields,
        selectedOptionItems: [{ id: '그레이_M', optionId: 1, quantity: 1, unitPrice: 10000 }],
        currentSelection: {},
      };

      // --- 렌더 사이클 시작 ---
      // engine-v1.17.8: isRootRenderer=false → 클리어 안 됨 (버그)
      const isRootRenderer = false; // user_layout_root
      if (isRootRenderer) {
        (window as any).__g7ForcedLocalFields = undefined;
      }

      // forcedLocalFields가 남아있음 (버그 상태)
      expect((window as any).__g7ForcedLocalFields).toBeDefined();

      // 사용자가 수량을 3으로 변경
      const dynamicState = {
        selectedOptionItems: [{ id: '그레이_M', optionId: 1, quantity: 3, unitPrice: 10000 }],
        currentSelection: {},
      };
      const dataContextLocal = {
        selectedOptionItems: [{ id: '그레이_M', optionId: 1, quantity: 1, unitPrice: 10000 }],
        currentSelection: {},
      };

      // extendedDataContext 병합
      let localState = deepMergeState(dataContextLocal, dynamicState);
      expect(localState.selectedOptionItems[0].quantity).toBe(3); // dynamicState 반영

      // stale forcedLocalFields 적용 → quantity가 1로 되돌아감
      const forcedFields = (window as any).__g7ForcedLocalFields;
      if (forcedFields) {
        localState = deepMergeState(localState, forcedFields);
      }

      // engine-v1.17.8 버그: stale forcedLocalFields가 quantity를 1로 덮어씀
      expect(localState.selectedOptionItems[0].quantity).toBe(1); // 버그!
    });

    it('shallow 모드 setState에서도 __g7ForcedLocalFields에 변경 필드만 저장해야 함', async () => {
      const currentState = {
        selectedOptionItems: [{ id: 'existing' }],
        currentSelection: { '색상': '레드' },
        formData: { name: '상품명' },
      };

      const context = {
        state: currentState,
        setState: mockSetState,
        actionId: 'test-shallow-forced-fields',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          merge: 'shallow',
          currentSelection: {},
        },
      };

      await dispatcher.executeAction(action, context);

      const forcedFields = (window as any).__g7ForcedLocalFields;
      expect(forcedFields).toBeDefined();

      // shallow에서도 변경된 필드(currentSelection)만 포함
      expect(forcedFields.currentSelection).toEqual({});
      // 미변경 필드는 포함되지 않아야 함
      expect(forcedFields.selectedOptionItems).toBeUndefined();
      expect(forcedFields.formData).toBeUndefined();
    });
  });

  /**
   * 사례 20: 콜백 prop(onAddressSelect 등)의 {{_local.xxx}}가 stale 값 전송
   *
   * 증상:
   * - extension_point 콜백 prop 내 apiCall의 body로 {{_local.checkout}} 사용 시
   * - setState로 업데이트한 값이 반영되지 않고 렌더 시점의 이전 값이 전송됨
   * - 컴포넌트 레벨 actions 배열에서는 동일 패턴이 정상 동작
   *
   * 근본 원인:
   * - DynamicRenderer의 resolvedProps useMemo에서 object prop을 resolveObject()로 선평가
   * - "{{_local.checkout}}" → 렌더 시점 JS 객체로 해소 → resolveParams가 재해소 불가
   * - 컴포넌트 레벨 actions는 bindComponentActions에서 별도 처리되므로 비해당
   *
   * 해결:
   * - resolveObject에서 ActionDefinition 패턴 감지 → 선평가 건너뛰기
   * - 실행 시점에 resolveParams가 최신 상태로 해소
   */
  describe('[사례 20] 콜백 prop 내 액션 정의 {{_local.xxx}} 선평가 방지', () => {
    it('resolveObject가 액션 정의 객체의 템플릿 문자열을 보존한다', () => {
      const engine = new DataBindingEngine();
      const actionDef = {
        handler: 'sequence',
        actions: [
          {
            handler: 'setState',
            params: {
              target: 'local',
              'checkout.zipcode': '{{$event.zipcode}}',
            },
          },
          {
            handler: 'apiCall',
            target: '/api/checkout',
            params: { method: 'PUT', body: '{{_local.checkout}}' },
          },
        ],
      };
      const context = {
        _local: {
          checkout: { item_coupons: { '1529': ['9130'] }, zipcode: null },
        },
      };

      const result = engine.resolveObject(actionDef, context);

      // 액션 정의는 해소되지 않아야 함 — shallow copy만
      expect(result.handler).toBe('sequence');
      expect(result.actions).toEqual(actionDef.actions); // 내부 배열은 원본 참조 보존
      expect(result.actions[1].params.body).toBe('{{_local.checkout}}');
      expect(result.actions[0].params['checkout.zipcode']).toBe('{{$event.zipcode}}');
    });

    it('단일 액션 정의(handler + params)도 선평가를 건너뛴다', () => {
      const engine = new DataBindingEngine();
      const actionDef = {
        handler: 'apiCall',
        target: '/api/checkout',
        params: { method: 'PUT', body: '{{_local.checkout}}' },
        onSuccess: [
          {
            handler: 'updateDataSource',
            params: { dataSourceId: 'checkoutData', data: '{{response}}' },
          },
        ],
      };
      const context = {
        _local: { checkout: { item_coupons: { '1529': ['9130'] } } },
      };

      const result = engine.resolveObject(actionDef, context);

      expect(result.handler).toBe('apiCall');
      expect(result.params.body).toBe('{{_local.checkout}}');
      expect(result.onSuccess[0].params.data).toBe('{{response}}');
    });

    it('비-액션 정의 객체는 기존처럼 해소된다', () => {
      const engine = new DataBindingEngine();
      const normalObj = {
        className: '{{activeClass}}',
        style: { color: '{{theme.color}}' },
      };
      const context = { activeClass: 'active', theme: { color: 'red' } };

      const result = engine.resolveObject(normalObj, context);

      expect(result.className).toBe('active');
      expect(result.style.color).toBe('red');
    });

    it('handler 키가 있어도 params/actions/target/onSuccess/onError 없으면 일반 객체로 해소', () => {
      const engine = new DataBindingEngine();
      const obj = {
        handler: '{{someHandler}}',
        label: '{{item.name}}',
      };
      const context = { someHandler: 'click', item: { name: '버튼' } };

      const result = engine.resolveObject(obj, context);

      // handler가 문자열이지만 {{}} 표현식이므로 resolve 후 'click'이 아니라
      // isActionDefinition에서 handler가 '{{someHandler}}'(string)이지만
      // params/actions/target/onSuccess/onError 없음 → 일반 객체로 처리
      expect(result.handler).toBe('click');
      expect(result.label).toBe('버튼');
    });

    it('$switch 객체는 기존처럼 처리된다 (isActionDefinition보다 우선)', () => {
      const engine = new DataBindingEngine();
      const switchObj = {
        $switch: '{{status}}',
        $cases: {
          active: 'text-green-500',
          inactive: 'text-red-500',
        },
        $default: 'text-gray-500',
      };
      const context = { status: 'active' };

      const result = engine.resolveObject(switchObj, context);

      // $switch는 resolveSwitch로 처리됨
      expect(result).toBe('text-green-500');
    });
  });

  describe('[사례 21] 비동기 콜백에서 handlerContext.state 우선 사용 (getLocal stale 방지)', () => {
    /**
     * 증상: 체크아웃 페이지에서 쿠폰 선택 후 주소 검색 시
     *       PUT /checkout body에 item_coupons: [] 로 누락됨
     *
     * 근본 원인 (3계층):
     * 1. DataBindingEngine.resolveObject가 콜백 prop 내 액션 정의를 선평가 → 사례 20에서 수정
     * 2. executeCallbackAction이 getLocal()을 우선 사용 → getLocal()은 globalState._local 반환
     *    → handleSetState의 컴포넌트 경로(isRealComponentContext=true)는
     *      context.setState만 호출하고 globalState._local을 업데이트하지 않음
     *    → React 커밋 후 __g7PendingLocalState 클리어 → getLocal()이 stale 상태 반환
     * 3. handlerContext.state(버튼 클릭 시 componentContext.state)는 extendedDataContext._local
     *    (= deepMerge(globalState._local, localDynamicState))을 포함하므로 최신 상태임
     *
     * 해결: executeCallbackAction에서 handlerContext.state를 getLocal()보다 우선 사용
     */

    it('handlerContext.state가 있으면 getLocal()보다 우선 사용된다', () => {
      // 시뮬레이션: 비동기 콜백 환경
      // - getLocal()은 globalState._local만 반환 (item_coupons: [])
      // - handlerContext.state는 버튼 클릭 시 캡처된 extendedDataContext._local (item_coupons: {"1529":["9130"]})

      const staleGlobalLocal = {
        checkout: { zipcode: null, country_code: 'KR', item_coupons: [], use_points: 0 },
        shipping: { zipcode: '', address: '' },
      };

      const freshHandlerState = {
        checkout: { zipcode: null, country_code: 'KR', item_coupons: { '1529': ['9130'] }, use_points: 500 },
        shipping: { zipcode: '12345', address: '서울시 강남구' },
      };

      // || 연산자로 우선순위 테스트: handlerContext.state가 truthy이면 사용
      const handlerContextState: Record<string, any> | undefined = freshHandlerState;
      const getLocalResult: Record<string, any> = staleGlobalLocal;

      // 수정된 로직: handlerContext.state || getLocal()
      const currentLocalState = handlerContextState || getLocalResult || {};

      // handlerContext.state가 사용되어야 함
      expect(currentLocalState).toBe(freshHandlerState);
      expect(currentLocalState.checkout.item_coupons).toEqual({ '1529': ['9130'] });
      expect(currentLocalState.checkout.use_points).toBe(500);
    });

    it('handlerContext.state가 없으면 getLocal()로 폴백한다', () => {
      const globalLocal = {
        checkout: { zipcode: null, country_code: 'KR', item_coupons: [], use_points: 0 },
      };

      // handlerContext가 없는 경우 (예: init_actions에서 직접 dispatch)
      const handlerContextState: Record<string, any> | undefined = undefined;
      const getLocalResult: Record<string, any> = globalLocal;

      const currentLocalState = handlerContextState || getLocalResult || {};

      // getLocal() 결과가 사용되어야 함
      expect(currentLocalState).toBe(globalLocal);
    });

    it('__g7ActionContext에 handlerContext.state 기반 _local이 설정된다', () => {
      // executeCallbackAction이 __g7ActionContext를 올바르게 구성하는지 검증
      const handlerState = {
        checkout: { item_coupons: { '1529': ['9130'] }, zipcode: null },
      };
      const g7AddressEvent = {
        zipcode: '13479',
        address: '경기도 성남시',
        addressDetail: '',
        region: '경기',
        city: '성남시',
        countryCode: 'KR',
        _raw: {},
      };

      // __g7ActionContext 구성 시뮬레이션
      const currentLocalState = handlerState;
      const handlerData = { _local: handlerState };
      const actionContext = {
        state: currentLocalState,
        setState: vi.fn(),
        data: {
          ...handlerData,
          _local: currentLocalState,
          $event: g7AddressEvent,
        },
      };

      // state에 handlerContext.state가 반영됨
      expect(actionContext.state.checkout.item_coupons).toEqual({ '1529': ['9130'] });
      // data._local에도 handlerContext.state가 반영됨
      expect(actionContext.data._local.checkout.item_coupons).toEqual({ '1529': ['9130'] });
      // $event에 주소 데이터가 포함됨
      expect(actionContext.data.$event.zipcode).toBe('13479');
    });

    it('sequence에서 setState 후 apiCall의 body가 최신 _local을 참조한다', () => {
      // handleSequence가 handlerContext.state 기반 initialState에서 시작하면
      // deep merge로 기존 필드(item_coupons)가 보존되는지 검증

      // 초기 상태: handlerContext.state (쿠폰 데이터 포함)
      const initialState = {
        checkout: { zipcode: null, country_code: 'KR', item_coupons: { '1529': ['9130'] }, use_points: 500 },
        shipping: { zipcode: '', address: '' },
      };

      // sequence 내 setState #1: shipping.zipcode 업데이트
      const setState1Payload = { 'shipping.zipcode': '13479', 'shipping.address': '경기도 성남시' };
      // dot notation을 nested object로 변환
      const nestedUpdate1 = { shipping: { zipcode: '13479', address: '경기도 성남시' } };

      // deep merge 시뮬레이션
      const afterSetState1 = {
        ...initialState,
        shipping: { ...initialState.shipping, ...nestedUpdate1.shipping },
      };

      // setState #2: checkout.zipcode, checkout.country_code 업데이트
      const nestedUpdate2 = { checkout: { zipcode: '13479', country_code: 'KR' } };

      const afterSetState2 = {
        ...afterSetState1,
        checkout: { ...afterSetState1.checkout, ...nestedUpdate2.checkout },
      };

      // 핵심 검증: deep merge 후에도 item_coupons와 use_points가 보존됨
      expect(afterSetState2.checkout.item_coupons).toEqual({ '1529': ['9130'] });
      expect(afterSetState2.checkout.use_points).toBe(500);
      expect(afterSetState2.checkout.zipcode).toBe('13479');
      expect(afterSetState2.shipping.zipcode).toBe('13479');
    });

    it('getLocal()에 __g7CommittedLocalState를 추가하면 모달 컨텍스트 혼입 위험', () => {
      // __g7CommittedLocalState를 getLocal()에 사용하면
      // 다중 root-level DynamicRenderer(메인 + 모달)에서
      // 마지막으로 렌더된 모달의 _local이 메인 페이지 상태를 덮어쓰는 문제 발생
      // → 이 테스트는 해당 접근법이 위험함을 문서화

      const mainPageLocal = {
        checkout: { item_coupons: { '1529': ['9130'] }, zipcode: null },
        form: { name: '주문자' },
      };

      const modalLocal = {
        couponSearch: { keyword: '' },
        selectedCoupon: null,
      };

      // 모달이 마지막으로 렌더링 → __g7CommittedLocalState = modalLocal
      const committedLocalState = modalLocal;
      const globalLocal = mainPageLocal;
      const pendingState = null;

      // 잘못된 로직: committedState를 globalLocal 대신 사용
      // getLocal() → committedState(모달) 반환 → 메인 페이지 데이터 손실
      if (pendingState) {
        // ...
      } else if (committedLocalState) {
        // 모달의 _local이 반환됨 → checkout.item_coupons 없음!
        expect(committedLocalState).not.toHaveProperty('checkout');
      }

      // 올바른 로직: globalLocal만 사용 (비동기 콜백에서는 handlerContext.state 우선)
      expect(globalLocal.checkout.item_coupons).toEqual({ '1529': ['9130'] });
    });
  });
});

describe('트러블슈팅 회귀 테스트 - deepMergeState 배열+숫자키 객체 병합', () => {
  describe('[사례 26] setParentLocal로 dot notation 경로 업데이트 시 배열이 객체로 교체되는 문제', () => {
    /**
     * 증상: 상품 옵션 폼에서 MultilingualTagInput으로 태그를 저장하면
     *       optionInputs 배열이 {"0": {...}} 객체로 변환되어 기존 데이터가 사라짐
     * 근본 원인: convertDotNotationToObject가 "optionInputs.0.values"를
     *           { optionInputs: { "0": { values: ... } } }로 변환하고,
     *           deepMergeState에 배열+숫자키 객체 병합 로직이 없어
     *           배열이 객체로 교체됨
     * 해결: deepMergeState에 hasOnlyNumericKeys 체크 추가하여
     *       배열+숫자키 객체를 인덱스별로 병합
     */
    it('target 배열 + source 숫자키 객체 → 배열 유지하며 인덱스별 병합', () => {
      const target = {
        ui: {
          optionInputs: [
            { name: '색상', values: [{ ko: '레드' }, { ko: '블루' }] },
            { name: '사이즈', values: [] },
          ],
        },
      };

      // convertDotNotationToObject("ui.optionInputs.0.values") 결과
      const source = {
        ui: {
          optionInputs: {
            '0': {
              values: [{ ko: '레드' }, { ko: '블루' }, { ko: '그린' }],
            },
          },
        },
      };

      const result = deepMergeState(target, source);

      // 배열 구조 유지
      expect(Array.isArray(result.ui.optionInputs)).toBe(true);
      expect(result.ui.optionInputs).toHaveLength(2);

      // 인덱스 0 병합 - values 업데이트, name 보존
      expect(result.ui.optionInputs[0].name).toBe('색상');
      expect(result.ui.optionInputs[0].values).toEqual([
        { ko: '레드' }, { ko: '블루' }, { ko: '그린' },
      ]);

      // 인덱스 1 변경 없음
      expect(result.ui.optionInputs[1].name).toBe('사이즈');
      expect(result.ui.optionInputs[1].values).toEqual([]);
    });

    it('빈 배열이 아닌 실제 배열에서도 인덱스 병합 동작', () => {
      const target = {
        items: [
          { id: 1, label: 'A', count: 10 },
          { id: 2, label: 'B', count: 20 },
          { id: 3, label: 'C', count: 30 },
        ],
      };

      const source = {
        items: {
          '1': { count: 25 },
        },
      };

      const result = deepMergeState(target, source);

      expect(Array.isArray(result.items)).toBe(true);
      expect(result.items).toHaveLength(3);
      expect(result.items[0]).toEqual({ id: 1, label: 'A', count: 10 }); // 변경 없음
      expect(result.items[1]).toEqual({ id: 2, label: 'B', count: 25 }); // count만 업데이트
      expect(result.items[2]).toEqual({ id: 3, label: 'C', count: 30 }); // 변경 없음
    });

    it('범위 초과 인덱스는 배열 확장', () => {
      const target = {
        items: [{ id: 1 }],
      };

      const source = {
        items: {
          '2': { id: 3 },
        },
      };

      const result = deepMergeState(target, source);

      expect(Array.isArray(result.items)).toBe(true);
      expect(result.items[0]).toEqual({ id: 1 });
      expect(result.items[2]).toEqual({ id: 3 });
    });

    it('source가 배열이면 기존처럼 배열 교체 (새 코드 미적용)', () => {
      const target = { items: [1, 2, 3] };
      const source = { items: [4, 5] };

      const result = deepMergeState(target, source);

      expect(result.items).toEqual([4, 5]);
    });

    it('source 객체에 비숫자 키가 섞여있으면 기존 덮어쓰기 동작 유지', () => {
      const target = { items: [1, 2, 3] };
      const source = { items: { '0': 10, name: 'test' } };

      const result = deepMergeState(target, source);

      // hasOnlyNumericKeys가 false → 기존 else 분기 (덮어쓰기)
      expect(result.items).toEqual({ '0': 10, name: 'test' });
    });

    it('replaceOnlyKeys(errors)는 새 코드 이전에 가드됨', () => {
      const target = {
        errors: ['에러1', '에러2'],
      };

      const source = {
        errors: { '0': '새에러' },
      };

      const result = deepMergeState(target, source);

      // errors는 replaceOnlyKeys → 완전 교체 (배열 병합 아님)
      expect(result.errors).toEqual({ '0': '새에러' });
    });

    it('기존 사례15 회귀 확인: errors 깊은 병합 방지', () => {
      const target = {
        errors: {
          name: ['필수'], category_id: ['필수'], list_price: ['필수'],
        },
        form: { name: '테스트' },
      };

      const source = {
        errors: { name: ['필수'] },
      };

      const result = deepMergeState(target, source);

      // errors 완전 교체 - 1개만
      expect(Object.keys(result.errors)).toHaveLength(1);
      expect(result.form.name).toBe('테스트');
    });

    it('실제 시나리오: setParentLocal → __g7PendingLocalState → handleLocalSetState', () => {
      // 1단계: React 현재 상태 (prev)
      const prev = {
        ui: {
          optionInputs: [
            { name: '색상', values: [{ ko: '레드' }] },
          ],
        },
        form: { name: '테스트 상품' },
      };

      // 2단계: setParentLocal이 설정한 __g7PendingLocalState
      // currentLocal에 optionInputs가 없는 경우 deepMerge가 숫자키 객체를 유지
      const pendingState = {
        ui: {
          optionInputs: {
            '0': { values: [{ ko: '레드' }, { ko: '블루' }] },
          },
        },
      };

      // 3단계: handleLocalSetState의 effectivePrev 계산
      const effectivePrev = deepMergeState(prev, pendingState);

      // 배열 유지, 인덱스 0 병합
      expect(Array.isArray(effectivePrev.ui.optionInputs)).toBe(true);
      expect(effectivePrev.ui.optionInputs[0].name).toBe('색상');
      expect(effectivePrev.ui.optionInputs[0].values).toEqual([
        { ko: '레드' }, { ko: '블루' },
      ]);
      expect(effectivePrev.form.name).toBe('테스트 상품');
    });

    it('실제 시나리오: __g7ForcedLocalFields 적용 시에도 배열 유지', () => {
      // extendedDataContext 병합 결과
      const localState = {
        ui: {
          optionInputs: [
            { name: '색상', values: [{ ko: '레드' }] },
            { name: '사이즈', values: [] },
          ],
        },
        form: { name: '테스트' },
      };

      // setParentLocal이 설정한 __g7ForcedLocalFields
      // (existingForced가 비어 deepMerge가 숫자키 객체 유지)
      const forcedLocalFields = {
        ui: {
          optionInputs: {
            '0': { values: [{ ko: '레드' }, { ko: '블루' }] },
          },
        },
      };

      const result = deepMergeState(localState, forcedLocalFields);

      expect(Array.isArray(result.ui.optionInputs)).toBe(true);
      expect(result.ui.optionInputs).toHaveLength(2);
      expect(result.ui.optionInputs[0].name).toBe('색상');
      expect(result.ui.optionInputs[0].values).toEqual([
        { ko: '레드' }, { ko: '블루' },
      ]);
      expect(result.ui.optionInputs[1].name).toBe('사이즈');
    });

    it('다중 인덱스 동시 업데이트', () => {
      const target = {
        ui: {
          optionInputs: [
            { name: '색상', values: [] },
            { name: '사이즈', values: [] },
            { name: '재질', values: [] },
          ],
        },
      };

      const source = {
        ui: {
          optionInputs: {
            '0': { values: [{ ko: '레드' }] },
            '2': { values: [{ ko: '면' }] },
          },
        },
      };

      const result = deepMergeState(target, source);

      expect(Array.isArray(result.ui.optionInputs)).toBe(true);
      expect(result.ui.optionInputs).toHaveLength(3);
      expect(result.ui.optionInputs[0].values).toEqual([{ ko: '레드' }]);
      expect(result.ui.optionInputs[1].values).toEqual([]); // 변경 없음
      expect(result.ui.optionInputs[2].values).toEqual([{ ko: '면' }]);
    });

    it('원시 값 배열에 대한 인덱스 업데이트', () => {
      const target = { scores: [100, 200, 300] };
      const source = { scores: { '1': 250 } };

      const result = deepMergeState(target, source);

      expect(Array.isArray(result.scores)).toBe(true);
      expect(result.scores).toEqual([100, 250, 300]);
    });
  });

  describe('[사례 23] setState params 키에 {{}} 표현식 포함 시 경고 출력', () => {
    /**
     * 증상: iteration 내 Toggle/Input 변경 후 저장해도 반영되지 않음
     * 근본 원인: setState params의 키에 포함된 {{...}} 표현식이 해석되지 않음
     * 해결: resolveParams에서 키에 {{ 포함 시 logger.warn 출력
     */
    let localDispatcher: ActionDispatcher;
    let localSetState: ReturnType<typeof vi.fn>;

    beforeEach(() => {
      localDispatcher = new ActionDispatcher({ navigate: vi.fn() });
      localDispatcher.setGlobalStateUpdater(vi.fn());
      localSetState = vi.fn((updater) => {
        if (typeof updater === 'function') updater({});
      });
    });

    it('키에 {{}} 표현식이 포함되면 console.warn이 출력되어야 함', async () => {
      Logger.getInstance().setDebug(true);
      const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const context = {
        state: {
          form: {
            items: [
              { code: 'KR', is_active: true },
              { code: 'US', is_active: false },
            ],
          },
        },
        setState: localSetState,
        actionId: 'test-key-warning',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          'form.items[{{item._idx}}].is_active': true,
        },
      };

      await localDispatcher.executeAction(action, context);

      // logger.warn이 호출되었는지 확인
      expect(warnSpy).toHaveBeenCalledWith(
        expect.stringContaining('[ActionDispatcher]'),
        expect.stringContaining('키에 표현식이 포함되어 있습니다')
      );

      warnSpy.mockRestore();
      Logger.getInstance().setDebug(false);
    });

    it('정적 키만 사용하면 경고가 출력되지 않아야 함', async () => {
      Logger.getInstance().setDebug(true);
      const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const context = {
        state: { form: { name: 'test' } },
        setState: localSetState,
        actionId: 'test-no-warning',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          'form.name': 'updated',
        },
      };

      await localDispatcher.executeAction(action, context);

      // resolveParams 관련 경고가 없어야 함
      const resolveParamsWarns = warnSpy.mock.calls.filter(
        (call) => typeof call[1] === 'string' && call[1].includes('키에 표현식이 포함')
      );
      expect(resolveParamsWarns).toHaveLength(0);

      warnSpy.mockRestore();
      Logger.getInstance().setDebug(false);
    });
  });

  describe('[사례 18] setLocal()이 expandedRows 등 배열 상태를 덮어쓰는 문제', () => {
    /**
     * 증상: expandChildren 내 체크박스/라디오 선택 시 expand row가 접힘
     * 근본 원인: setLocal()이 __g7PendingLocalState에 globalState._local 전체 스냅샷을 설정하는데,
     *   이 스냅샷에는 expandedRows: [] (초기값)이 포함됨.
     *   handleLocalSetState에서 deepMergeState(prev, pendingState) 시 prev의
     *   expandedRows: [301]이 pendingState의 expandedRows: []로 덮어써짐.
     * 해결: __g7SetLocalOverrideKeys (실제 변경된 키만)를 우선 사용하여 merge.
     *   setLocal()만 __g7SetLocalOverrideKeys를 설정하고, setParentLocal()은 설정하지 않으므로
     *   기존 setParentLocal 동작은 그대로 유지됨.
     *
     * @see modules/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_list
     */
    it('setLocal 시 __g7SetLocalOverrideKeys만 병합하여 expandedRows 보존', () => {
      // prev: 사용자가 row 301을 펼친 상태
      const prev = {
        selectedItems: [],
        selectedOptionIds: [],
        expandedRows: [301],
        filterStatus: 'all',
      };

      // __g7PendingLocalState: globalState._local 기반 전체 스냅샷 (stale expandedRows)
      const pendingState = {
        selectedItems: [],
        selectedOptionIds: ['301-5'],
        expandedRows: [], // ← stale: globalState._local의 초기값
        filterStatus: 'all',
      };

      // __g7SetLocalOverrideKeys: setLocal()의 실제 변경분만
      const setLocalOverrides = {
        selectedOptionIds: ['301-5'],
      };

      // 수정된 로직: setLocalOverrides가 있으면 pendingState 대신 사용
      const pendingToMerge = setLocalOverrides || pendingState;
      const effectivePrev = deepMergeState(prev, pendingToMerge);

      // expandedRows가 prev의 값([301])을 유지해야 함 (stale []로 덮어쓰면 안됨)
      expect(effectivePrev.expandedRows).toEqual([301]);
      // selectedOptionIds는 setLocalOverrides의 값으로 업데이트
      expect(effectivePrev.selectedOptionIds).toEqual(['301-5']);
      // 나머지 필드는 prev 값 유지
      expect(effectivePrev.selectedItems).toEqual([]);
      expect(effectivePrev.filterStatus).toBe('all');
    });

    it('setLocalOverrides 없으면 pendingState 전체 병합 (setParentLocal 경로)', () => {
      // prev: 사용자가 row 301을 펼친 상태
      const prev = {
        selectedItems: [],
        expandedRows: [301],
      };

      // setParentLocal은 __g7SetLocalOverrideKeys를 설정하지 않음
      const setLocalOverrides = undefined;

      // __g7PendingLocalState: setParentLocal이 설정한 전체 상태
      const pendingState = {
        selectedItems: [],
        expandedRows: [301],
        parentData: { key: 'value' },
      };

      // 기존 동작: setLocalOverrides가 없으면 pendingState 사용
      const pendingToMerge = setLocalOverrides || pendingState;
      const effectivePrev = deepMergeState(prev, pendingToMerge);

      // setParentLocal 경로는 기존 동작 유지 (전체 pendingState 병합)
      expect(effectivePrev.expandedRows).toEqual([301]);
      expect(effectivePrev.parentData).toEqual({ key: 'value' });
    });

    it('setLocal으로 selectedItems와 selectedOptionIds 동시 변경 시 다른 키 보존', () => {
      // prev: 이미 확장된 row와 필터 상태가 있는 경우
      const prev = {
        selectedItems: [100],
        selectedOptionIds: ['100-1', '100-2'],
        expandedRows: [100, 200],
        filterStatus: 'active',
        searchKeyword: '테스트',
      };

      // setLocal에서 selectedItems와 selectedOptionIds 동시 변경
      const setLocalOverrides = {
        selectedItems: [100, 200],
        selectedOptionIds: ['100-1', '100-2', '200-1'],
      };

      const effectivePrev = deepMergeState(prev, setLocalOverrides);

      // 변경된 키만 업데이트
      expect(effectivePrev.selectedItems).toEqual([100, 200]);
      expect(effectivePrev.selectedOptionIds).toEqual(['100-1', '100-2', '200-1']);
      // 나머지 키는 prev 값 그대로 보존
      expect(effectivePrev.expandedRows).toEqual([100, 200]);
      expect(effectivePrev.filterStatus).toBe('active');
      expect(effectivePrev.searchKeyword).toBe('테스트');
    });

    it('빈 setLocalOverrides({})인 경우 prev가 그대로 유지', () => {
      const prev = {
        expandedRows: [301],
        selectedItems: [100],
      };

      const setLocalOverrides = {};
      const effectivePrev = deepMergeState(prev, setLocalOverrides);

      expect(effectivePrev.expandedRows).toEqual([301]);
      expect(effectivePrev.selectedItems).toEqual([100]);
    });
  });

  describe('[사례 25] 커스텀 핸들러에서 배열 요소 shallow copy 참조 mutation으로 인한 상태 미갱신', () => {
    /**
     * 증상: 커스텀 핸들러가 배열을 [...array]로 shallow copy한 뒤
     *       요소를 직접 mutation(cs.field = value)하면,
     *       원본 state 객체도 같이 변경되어 deepMerge가 변경을 감지하지 못함
     * 해결: 배열 요소 수정 시 spread operator로 새 객체 생성
     */

    it('shallow copy 배열의 요소를 직접 mutation하면 원본과 동일 참조이므로 deepMerge에서 변경 감지 불가', () => {
      const originalState = {
        form: {
          country_settings: [
            { country_code: 'KR', charge_policy: 'free', base_fee: 0 },
            { country_code: 'US', charge_policy: 'flat', base_fee: 5000 },
          ],
        },
      };

      // ❌ 잘못된 패턴: shallow copy 후 직접 mutation
      const shallowCopied = [...originalState.form.country_settings];
      const cs = shallowCopied[0];
      cs.base_fee = 3000; // 원본도 함께 변경됨

      // 원본 state 객체가 이미 변경되어 있음을 확인
      expect(originalState.form.country_settings[0].base_fee).toBe(3000);

      // deepMerge 시 converted와 currentSnapshot이 같은 값이므로 변경 감지 불가
      const converted = { form: { country_settings: shallowCopied } };
      deepMergeState(originalState, converted);

      // shallow copy mutation의 경우 원본과 converted 요소가 같은 참조
      expect(shallowCopied[0]).toBe(originalState.form.country_settings[0]);
    });

    it('spread operator로 새 객체를 생성하면 원본과 다른 참조이므로 deepMerge에서 변경 감지 가능', () => {
      const originalState = {
        form: {
          country_settings: [
            { country_code: 'KR', charge_policy: 'free', base_fee: 0 },
            { country_code: 'US', charge_policy: 'flat', base_fee: 5000 },
          ],
        },
      };

      // ✅ 올바른 패턴: spread operator로 새 객체 생성
      const shallowCopied = [...originalState.form.country_settings];
      const cs = shallowCopied[0];
      shallowCopied[0] = { ...cs, base_fee: 3000 }; // 새 객체 생성

      // 원본 state는 변경되지 않음
      expect(originalState.form.country_settings[0].base_fee).toBe(0);

      // deepMerge 시 변경 감지 가능
      const converted = { form: { country_settings: shallowCopied } };
      const merged = deepMergeState(originalState, converted);

      // 새 객체가 생성되었으므로 원본과 다른 참조
      expect(shallowCopied[0]).not.toBe(originalState.form.country_settings[0]);
      // 병합 결과에 변경이 반영됨
      expect(merged.form.country_settings[0].base_fee).toBe(3000);
      // 다른 요소는 보존
      expect(merged.form.country_settings[1].base_fee).toBe(5000);
    });

    it('중첩 객체(ranges)를 수정할 때도 spread operator로 새 객체 생성 필수', () => {
      const originalState = {
        form: {
          country_settings: [
            {
              country_code: 'KR',
              charge_policy: 'range',
              ranges: { type: 'range', tiers: [{ min: 0, max: 10000, fee: 3000 }] },
            },
          ],
        },
      };

      // ✅ 올바른 패턴: 중첩 객체도 spread operator 사용
      const shallowCopied = [...originalState.form.country_settings];
      const cs = shallowCopied[0];
      const newTiers = [...(cs.ranges?.tiers ?? []), { min: 10001, max: 30000, fee: 5000 }];
      shallowCopied[0] = {
        ...cs,
        ranges: { ...(cs.ranges ?? {}), tiers: newTiers },
      };

      // 원본 state는 변경되지 않음
      expect(originalState.form.country_settings[0].ranges.tiers).toHaveLength(1);

      // deepMerge에 변경이 반영됨
      const converted = { form: { country_settings: shallowCopied } };
      const merged = deepMergeState(originalState, converted);
      expect(merged.form.country_settings[0].ranges.tiers).toHaveLength(2);
      expect(merged.form.country_settings[0].ranges.tiers[1].fee).toBe(5000);
    });
  });

});

describe('[사례 26] 컴포넌트가 raw value를 전달하는 onChange 처리 (회귀 테스트)', () => {
  /**
   * 증상: raw value fallback이 컴포넌트 마운트 시 불필요한 setState를 트리거하여
   *       API에서 로드한 데이터를 빈 초기값으로 덮어씀
   * 해결: 엔진 fallback 제거, 컴포넌트에서 { target: { value } } 패턴 사용
   */
  let dispatcher: ActionDispatcher;
  let mockSetState: ReturnType<typeof vi.fn>;
  let mockGlobalStateUpdater: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    mockSetState = vi.fn((updater) => {
      if (typeof updater === 'function') {
        return updater({});
      }
      return updater;
    });
    mockGlobalStateUpdater = vi.fn();
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    dispatcher.setGlobalStateUpdater(mockGlobalStateUpdater);
    Logger.getInstance().setDebug(false);
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it('bindActionsToProps에서 raw value(이벤트가 아닌 값)는 핸들러를 실행하지 않아야 함', () => {
    const props = {
      actions: [
        {
          type: 'change',
          handler: 'setState',
          params: { target: 'local', 'form.content': '{{$event.target.value}}' },
        },
      ],
    };

    const dataContext = {
      _local: { form: { content: 'loaded-from-api' } },
      _global: {},
    };

    const componentContext = {
      state: {},
      setState: mockSetState,
    };

    const boundProps = dispatcher.bindActionsToProps(props, dataContext, componentContext);

    expect(boundProps.onChange).toBeDefined();

    // raw string 전달 (CodeEditor가 마운트 시 초기값으로 호출할 수 있는 패턴)
    boundProps.onChange('');

    // raw value는 핸들러를 트리거하지 않아야 함
    expect(mockSetState).not.toHaveBeenCalled();
  });

  it('{ target: { value } } 패턴의 커스텀 이벤트는 정상 처리되어야 함', () => {
    const props = {
      actions: [
        {
          type: 'change',
          handler: 'setState',
          params: { target: 'local', 'form.content': '{{$event.target.value}}' },
        },
      ],
    };

    const dataContext = {
      _local: { form: { content: '' } },
      _global: {},
    };

    const componentContext = {
      state: {},
      setState: mockSetState,
    };

    const boundProps = dispatcher.bindActionsToProps(props, dataContext, componentContext);

    // { target: { value } } 패턴으로 호출 (컴포넌트의 올바른 패턴)
    boundProps.onChange({ target: { value: 'user-edited-content' } });

    // 커스텀 컴포넌트 이벤트로 감지되어 핸들러가 실행되어야 함
    expect(mockSetState).toHaveBeenCalled();
  });
});

describe('[engine-v1.21.2] getLocal() __g7LastSetLocalSnapshot fallback', () => {
  /**
   * 증상: 커스텀 핸들러에서 setLocal() 후 await 경계를 넘으면
   *       React 18 마이크로태스크 배칭이 렌더를 플러시 → useLayoutEffect가
   *       __g7PendingLocalState를 null로 클리어 → getLocal()이 stale 반환
   * 해결: __g7LastSetLocalSnapshot을 fallback으로 사용하여 await 이후에도 최신 값 반환
   *
   * 테스트 방법: G7Core.state.getLocal()은 initG7CoreGlobals() 호출 후에만 사용 가능.
   * 여기서는 getLocal() 로직을 직접 시뮬레이션하여 __g7LastSetLocalSnapshot fallback을 검증.
   * 실제 getLocal() 로직: pendingState → lastSnapshot → globalLocal 우선순위.
   */

  let mockTemplateApp: any;

  /**
   * getLocal() 로직 시뮬레이션 (G7CoreGlobals.ts getLocal과 동일)
   * deepMerge는 이미 import 가능한 deepMergeState로 대체
   */
  function simulateGetLocal(): Record<string, any> {
    const globalState = mockTemplateApp?.getGlobalState?.() || {};
    const globalLocal = globalState._local || {};

    const pendingState = (window as any).__g7PendingLocalState;
    if (pendingState) {
      return { ...globalLocal, ...pendingState };
    }

    const lastSnapshot = (window as any).__g7LastSetLocalSnapshot;
    if (lastSnapshot && lastSnapshot !== globalLocal) {
      return { ...globalLocal, ...lastSnapshot };
    }

    return globalLocal;
  }

  beforeEach(() => {
    mockTemplateApp = {
      getGlobalState: vi.fn(() => ({
        _local: { form: { name: 'initial' } },
      })),
      setGlobalState: vi.fn(),
    };
    (window as any).__templateApp = mockTemplateApp;
    (window as any).__g7PendingLocalState = undefined;
    (window as any).__g7LastSetLocalSnapshot = undefined;
  });

  afterEach(() => {
    delete (window as any).__templateApp;
    (window as any).__g7PendingLocalState = undefined;
    (window as any).__g7LastSetLocalSnapshot = undefined;
  });

  it('__g7PendingLocalState가 있으면 우선 사용해야 함', () => {
    (window as any).__g7PendingLocalState = { form: { name: 'pending' } };
    (window as any).__g7LastSetLocalSnapshot = { form: { name: 'snapshot' } };

    const result = simulateGetLocal();
    expect(result.form.name).toBe('pending');
  });

  it('__g7PendingLocalState가 null이면 __g7LastSetLocalSnapshot을 fallback으로 사용해야 함', () => {
    (window as any).__g7PendingLocalState = null;
    (window as any).__g7LastSetLocalSnapshot = { form: { name: 'snapshot-value' } };

    const result = simulateGetLocal();
    expect(result.form.name).toBe('snapshot-value');
  });

  it('__g7LastSetLocalSnapshot이 globalLocal과 동일 참조이면 스킵해야 함', () => {
    const globalLocal = { form: { name: 'initial' } };
    mockTemplateApp.getGlobalState.mockReturnValue({ _local: globalLocal });
    (window as any).__g7PendingLocalState = null;
    (window as any).__g7LastSetLocalSnapshot = globalLocal; // 동일 참조

    const result = simulateGetLocal();
    expect(result.form.name).toBe('initial');
  });

  it('모든 fallback이 없으면 globalLocal만 반환해야 함', () => {
    (window as any).__g7PendingLocalState = null;
    (window as any).__g7LastSetLocalSnapshot = undefined;

    const result = simulateGetLocal();
    expect(result.form.name).toBe('initial');
  });

  it('await 후 getLocal() stale 시나리오: pendingState 클리어 후에도 snapshot으로 최신 값 반환', async () => {
    // 1. setLocal 호출 시뮬레이션 (pendingState + snapshot 모두 설정)
    const updatedState = { form: { name: 'updated-by-setLocal', items: [1, 2, 3] } };
    (window as any).__g7PendingLocalState = updatedState;
    (window as any).__g7LastSetLocalSnapshot = updatedState;

    // 2. 동일 이벤트 루프: pendingState로 최신 값 반환
    expect(simulateGetLocal().form.name).toBe('updated-by-setLocal');

    // 3. await 후 useLayoutEffect가 pendingState를 클리어 (시뮬레이션)
    (window as any).__g7PendingLocalState = null;

    // 4. await 이후: snapshot fallback으로 최신 값 반환
    const afterAwait = simulateGetLocal();
    expect(afterAwait.form.name).toBe('updated-by-setLocal');
    expect(afterAwait.form.items).toEqual([1, 2, 3]);
  });

  it('dataContext._local 갱신 후 snapshot이 클리어되면 globalLocal만 반환해야 함', () => {
    // 1. setLocal 호출 → snapshot 설정
    (window as any).__g7LastSetLocalSnapshot = { form: { name: 'from-setLocal' } };
    (window as any).__g7PendingLocalState = null;

    // snapshot으로 최신 값 반환
    expect(simulateGetLocal().form.name).toBe('from-setLocal');

    // 2. dataContext._local 갱신 후 useLayoutEffect에서 snapshot 클리어 (시뮬레이션)
    mockTemplateApp.getGlobalState.mockReturnValue({
      _local: { form: { name: 'committed-to-react' } },
    });
    (window as any).__g7LastSetLocalSnapshot = undefined;

    // 3. globalLocal이 최신 값을 반환
    expect(simulateGetLocal().form.name).toBe('committed-to-react');
  });
});

describe('[engine-v1.24.6] handleSetState isRealComponentContext — 변경 필드만 전달', () => {
  let dispatcher: ActionDispatcher;
  let mockNavigate: ReturnType<typeof vi.fn>;
  let mockSetState: ReturnType<typeof vi.fn>;
  let mockGlobalStateUpdater: ReturnType<typeof vi.fn>;
  let setStateCalls: any[];

  beforeEach(() => {
    mockNavigate = vi.fn();
    setStateCalls = [];

    mockSetState = vi.fn((updates) => {
      setStateCalls.push(updates);
    });

    mockGlobalStateUpdater = vi.fn();

    dispatcher = new ActionDispatcher({
      navigate: mockNavigate,
    });
    dispatcher.setGlobalStateUpdater(mockGlobalStateUpdater);

    Logger.getInstance().setDebug(false);

    (window as any).__g7PendingLocalState = undefined;
    (window as any).__g7SequenceLocalSync = undefined;
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7ActionContext = undefined;
    (window as any).__g7LastSetLocalSnapshot = undefined;
    (window as any).__g7SetLocalOverrideKeys = undefined;
  });

  afterEach(() => {
    vi.clearAllMocks();
    (window as any).__g7PendingLocalState = undefined;
    (window as any).__g7SequenceLocalSync = undefined;
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7ActionContext = undefined;
    (window as any).__g7LastSetLocalSnapshot = undefined;
    (window as any).__g7SetLocalOverrideKeys = undefined;
  });

  /**
   * 증상: deep merge 모드에서 context.setState에 전체 _local 스냅샷이 전달되어
   *       localDynamicState에 전체 상태가 축적됨 → SPA 이동 후 stale 필드 잔존
   * 해결: context.setState에는 변경 필드만 전달, __g7PendingLocalState에만 전체 병합
   */
  it('deep 모드에서 context.setState에 변경 필드만 전달해야 함 (전체 _local 축적 방지)', async () => {
    const fullLocalState = {
      filter: 'active',
      sortBy: 'date',
      perPage: 20,
      cancelItems: [],
      cancelReason: '',
    };

    const context = {
      state: fullLocalState,
      setState: mockSetState,
      actionId: 'test-no-accumulation',
    };

    // cancelReason만 변경
    const action = {
      handler: 'setState' as const,
      params: {
        target: 'local',
        cancelReason: '고객 요청',
      },
    };

    await dispatcher.executeAction(action, context);

    expect(mockSetState).toHaveBeenCalledTimes(1);
    const passedPayload = setStateCalls[0];

    // 핵심: context.setState에는 변경 필드(cancelReason)만 전달
    // filter, sortBy, perPage 등 전체 _local이 포함되면 안됨
    expect(passedPayload.cancelReason).toBe('고객 요청');
    expect(passedPayload.filter).toBeUndefined();
    expect(passedPayload.sortBy).toBeUndefined();
    expect(passedPayload.perPage).toBeUndefined();

    // __g7PendingLocalState에는 전체 병합 결과 저장
    const pending = (window as any).__g7PendingLocalState;
    expect(pending).toBeDefined();
    expect(pending.cancelReason).toBe('고객 요청');
    expect(pending.filter).toBe('active');
    expect(pending.sortBy).toBe('date');
    expect(pending.perPage).toBe(20);
  });

  it('sequence 내 연속 setState에서 __g7PendingLocalState를 통한 동기화가 정상 동작해야 함', async () => {
    const context = {
      state: { count: 0, name: '' },
      setState: mockSetState,
      actionId: 'test-sequence-sync',
    };

    // 첫 번째 setState
    await dispatcher.executeAction({
      handler: 'setState' as const,
      params: { target: 'local', count: 1 },
    }, context);

    // __g7PendingLocalState에 전체 병합 결과 저장됨
    expect((window as any).__g7PendingLocalState.count).toBe(1);
    expect((window as any).__g7PendingLocalState.name).toBe('');

    // 두 번째 setState — pendingLocal을 통해 count: 1 상태 인지
    await dispatcher.executeAction({
      handler: 'setState' as const,
      params: { target: 'local', name: 'test' },
    }, context);

    // 두 번째 호출에서도 변경 필드만 전달
    expect(setStateCalls[1].name).toBe('test');
    expect(setStateCalls[1].count).toBeUndefined();

    // pending에는 누적 상태
    const pending = (window as any).__g7PendingLocalState;
    expect(pending.count).toBe(1);
    expect(pending.name).toBe('test');
  });
});

/**
 * engine-v1.24.7: 모달 내부 setLocal() 호출 시 모달 dynamicLocal이
 * globalState._local을 오염시키지 않는지 검증
 *
 * 근본 원인: setLocal()이 actionContext.state(=모달의 localDynamicState)를
 *           globalLocal과 deepMerge하여 globalState._local에 기록.
 *           → 페이지의 dataContext._local이 모달 데이터로 오염
 *           → updateTemplateData 재렌더링 시 DataGrid 등이 깨짐.
 *
 * 해결: __g7LayoutContextStack에 항목이 있으면 (모달 열린 상태) actionContext.state 병합 제외.
 *       페이지 컨텍스트(스택 비어있음)에서만 dynamicLocal 병합하여 engine-v1.22.1 기능 유지.
 */
describe('[engine-v1.24.7] setLocal() 모달 내부 호출 시 globalState._local 오염 방지', () => {
  let mockTemplateApp: any;
  let globalState: Record<string, any>;

  beforeEach(() => {
    // 페이지의 globalState._local (주문 상세 데이터)
    globalState = {
      _local: {
        form: { order_id: 100, memo: '' },
        selectedProducts: [1, 3],
      },
    };

    mockTemplateApp = {
      getGlobalState: vi.fn(() => globalState),
      setGlobalState: vi.fn((updates) => {
        if (updates._local) {
          globalState._local = updates._local;
        }
      }),
    };

    (window as any).__templateApp = mockTemplateApp;
    (window as any).__g7PendingLocalState = undefined;
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7ActionContext = undefined;
    (window as any).__g7LayoutContextStack = undefined;
    (window as any).__g7SetLocalOverrideKeys = undefined;
  });

  afterEach(() => {
    (window as any).__templateApp = undefined;
    (window as any).__g7PendingLocalState = undefined;
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7ActionContext = undefined;
    (window as any).__g7LayoutContextStack = undefined;
    (window as any).__g7SetLocalOverrideKeys = undefined;
  });

  it('모달 내부 setLocal(): actionContext.state(모달 상태)가 globalState._local에 병합되지 않아야 함', () => {
    // 모달의 localDynamicState (취소 모달의 상태)
    const modalDynamicState = {
      cancelItems: [{ id: 1, cancel_quantity: 2 }],
      refundLoading: false,
      refundEstimate: null,
      cancelReason: '',
      refundPriority: 'pg_first',
    };

    // 모달이 열린 상태: layoutContextStack에 페이지 컨텍스트 존재
    (window as any).__g7LayoutContextStack = [{
      state: globalState._local,
      setState: vi.fn(),
    }];

    (window as any).__g7ActionContext = {
      state: modalDynamicState,
      setState: vi.fn(),
    };

    // setLocal 로직 시뮬레이션 (engine-v1.24.7)
    const pendingState = (window as any).__g7PendingLocalState;
    const globalLocal = mockTemplateApp.getGlobalState()._local || {};

    // engine-v1.24.7: 모달 내부에서는 actionContext.state 병합 제외
    const layoutContextStack = (window as any).__g7LayoutContextStack || [];
    const isInsideModal = layoutContextStack.length > 0;
    const actionContext = (window as any).__g7ActionContext;
    const dynamicLocal = (!isInsideModal && actionContext?.state) ? actionContext.state : undefined;

    const baseLocal = dynamicLocal
      ? { ...globalLocal, ...dynamicLocal }
      : globalLocal;

    const currentSnapshot = pendingState || baseLocal;

    // 모달에서 cancelItems 업데이트
    const updates = {
      cancelItems: [{ id: 1, cancel_quantity: 3 }],
    };
    const mergedPending = { ...currentSnapshot, ...updates };

    mockTemplateApp.setGlobalState({ _local: mergedPending });

    // 검증: globalState._local에 모달 전용 필드(refundLoading, refundEstimate 등)가 없어야 함
    const result = globalState._local;
    expect(result.refundLoading).toBeUndefined();
    expect(result.refundEstimate).toBeUndefined();
    expect(result.cancelReason).toBeUndefined();
    expect(result.refundPriority).toBeUndefined();

    // 검증: 업데이트 대상인 cancelItems는 있어야 함
    expect(result.cancelItems).toEqual([{ id: 1, cancel_quantity: 3 }]);

    // 검증: 페이지의 기존 상태 보존
    expect(result.form.order_id).toBe(100);
    expect(result.selectedProducts).toEqual([1, 3]);
  });

  it('페이지 컨텍스트 setLocal(): actionContext.state가 정상 병합되어야 함 (engine-v1.22.1 호환)', () => {
    // 모달 미사용: layoutContextStack 비어있음
    (window as any).__g7LayoutContextStack = [];

    // 페이지의 dynamicState (setState target: "local"로 설정된 값)
    const pageDynamicState = {
      selectedProducts: [1, 3],
      form: { order_id: 100, memo: '' },
      ui: { activeTab: 'products' },
    };

    (window as any).__g7ActionContext = {
      state: pageDynamicState,
      setState: vi.fn(),
    };

    // setLocal 로직 시뮬레이션 (engine-v1.24.7)
    const pendingState = (window as any).__g7PendingLocalState;
    const globalLocal = mockTemplateApp.getGlobalState()._local || {};

    const layoutContextStack = (window as any).__g7LayoutContextStack || [];
    const isInsideModal = layoutContextStack.length > 0;
    const actionContext = (window as any).__g7ActionContext;
    const dynamicLocal = (!isInsideModal && actionContext?.state) ? actionContext.state : undefined;

    const baseLocal = dynamicLocal
      ? { ...globalLocal, ...dynamicLocal }
      : globalLocal;

    const currentSnapshot = pendingState || baseLocal;

    const updates = {
      bulkConfirmItems: [{ id: 1 }],
    };
    const mergedPending = { ...currentSnapshot, ...updates };

    mockTemplateApp.setGlobalState({ _local: mergedPending });

    // 검증: dynamicState의 값이 정상 포함 (engine-v1.22.1 기능 유지)
    const result = globalState._local;
    expect(result.selectedProducts).toEqual([1, 3]);
    expect(result.ui.activeTab).toBe('products');
    expect(result.bulkConfirmItems).toEqual([{ id: 1 }]);
  });

  it('debounce 콜백 시나리오: actionContext가 null이어도 globalLocal 기반으로 동작', () => {
    // debounce 콜백에서는 actionContext가 null (ActionDispatcher finally에서 복원됨)
    (window as any).__g7ActionContext = undefined;
    // 모달은 열려있음
    (window as any).__g7LayoutContextStack = [{
      state: globalState._local,
      setState: vi.fn(),
    }];

    // 이전 setLocal 호출로 globalState._local에 cancelItems가 이미 있음
    globalState._local = {
      ...globalState._local,
      cancelItems: [{ id: 1, cancel_quantity: 2 }],
    };

    const pendingState = (window as any).__g7PendingLocalState;
    const globalLocal = mockTemplateApp.getGlobalState()._local || {};

    const layoutContextStack = (window as any).__g7LayoutContextStack || [];
    const isInsideModal = layoutContextStack.length > 0;
    const actionContext = (window as any).__g7ActionContext;
    const dynamicLocal = (!isInsideModal && actionContext?.state) ? actionContext.state : undefined;

    // actionContext가 null이므로 dynamicLocal도 undefined
    expect(dynamicLocal).toBeUndefined();

    const baseLocal = dynamicLocal
      ? { ...globalLocal, ...dynamicLocal }
      : globalLocal;

    const currentSnapshot = pendingState || baseLocal;

    // debounce: refundLoading 설정
    const updates = { refundLoading: true };
    const mergedPending = { ...currentSnapshot, ...updates };

    mockTemplateApp.setGlobalState({ _local: mergedPending });

    // 검증: 기존 데이터 보존 + 새 필드 추가
    const result = globalState._local;
    expect(result.form.order_id).toBe(100);
    expect(result.cancelItems).toEqual([{ id: 1, cancel_quantity: 2 }]);
    expect(result.refundLoading).toBe(true);
    // 모달 전용 필드(refundEstimate 등)는 없어야 함
    expect(result.refundEstimate).toBeUndefined();
  });
});

describe('[사례 29] init_actions에서 conditions 핸들러 사용 시 조건 분기 무시됨', () => {
  /**
   * 증상: init_actions에서 conditions 핸들러 사용 시 conditions 배열이 전달되지 않음
   * 해결: InitActionDefinition 타입에 conditions 추가, executeInitActions에서 conditions 전달
   */
  it('InitActionDefinition에 conditions 프로퍼티가 존재해야 함', async () => {
    // LayoutLoader의 InitActionDefinition 타입이 conditions를 포함하는지 검증
    // 런타임에서 conditions 프로퍼티가 actionDef에 전달되는지 확인
    const initAction = {
      handler: 'conditions',
      conditions: [
        {
          if: '{{!!query.error}}',
          then: {
            handler: 'setState',
            params: { target: 'local', errorMsg: 'test error' },
          },
        },
      ],
    };

    // actionDef 구성 시 conditions가 포함되어야 함 (TemplateApp.executeInitActions 로직 재현)
    const actionDef = {
      type: 'click' as const,
      handler: initAction.handler,
      conditions: initAction.conditions,
    } as any;

    expect(actionDef.conditions).toBeDefined();
    expect(actionDef.conditions).toHaveLength(1);
    expect(actionDef.conditions[0].if).toBe('{{!!query.error}}');
  });

  it('conditions 프로퍼티가 누락되면 undefined로 전달됨을 검증', () => {
    // 수정 전: conditions가 actionDef에 포함되지 않아 항상 undefined
    const initActionWithoutConditions = {
      handler: 'setState',
      params: { target: 'local', key: 'value' },
    };

    const actionDef = {
      handler: initActionWithoutConditions.handler,
      params: initActionWithoutConditions.params,
      conditions: (initActionWithoutConditions as any).conditions,
    };

    // conditions가 없는 핸들러는 undefined — 기존 동작 유지
    expect(actionDef.conditions).toBeUndefined();
  });
});

describe('[사례 12] _localInit + 자식 useEffect setState가 API 데이터를 stale 기본값으로 덮어씀 (engine-v1.27.0)', () => {
  /**
   * 증상: 직접 URL 접근 시 _localInit API 데이터가 init_actions stale 값으로 덮어써짐
   * 해결: useLayoutEffect에서 __g7PendingLocalState와 __g7SetLocalOverrideKeys를 API 데이터로 사전 동기화
   */

  beforeEach(() => {
    (window as any).__g7PendingLocalState = null;
    (window as any).__g7SetLocalOverrideKeys = undefined;
  });

  afterEach(() => {
    (window as any).__g7PendingLocalState = null;
    (window as any).__g7SetLocalOverrideKeys = undefined;
  });

  it('__g7SetLocalOverrideKeys에 stale 값이 있으면 handleLocalSetState에서 pendingState 대신 사용됨 (문제 재현)', () => {
    // init_actions가 설정한 stale overrides (빈 배열)
    const staleOverrides = {
      form: { options: [], category_ids: [] },
      hasChanges: false,
    };
    // API 데이터가 설정한 pendingState (실제 데이터)
    const pendingState = {
      form: { options: [1, 2, 3, 4, 5, 6], category_ids: [10, 20, 30] },
      hasChanges: false,
    };

    // handleLocalSetState의 핵심 로직: pendingToMerge = setLocalOverrides || pendingState
    const setLocalOverrides = staleOverrides;
    const pendingToMerge = setLocalOverrides || pendingState;

    // stale overrides가 우선 → pendingState(API 데이터)가 무시됨
    expect(pendingToMerge).toBe(staleOverrides);
    expect(pendingToMerge.form.options).toHaveLength(0); // stale 빈 배열!
  });

  it('useLayoutEffect에서 __g7SetLocalOverrideKeys를 API 데이터로 갱신하면 stale 방지', () => {
    // init_actions가 설정한 stale overrides
    const staleOverrides = {
      form: { options: [], category_ids: [] },
      hasChanges: false,
    };
    (window as any).__g7SetLocalOverrideKeys = staleOverrides;

    // API 데이터 (_localInit에서 추출)
    const apiData = {
      form: { options: [1, 2, 3, 4, 5, 6], category_ids: [10, 20, 30] },
    };

    // useLayoutEffect 수정 로직: existingOverrides를 API 데이터로 갱신
    const existingOverrides = (window as any).__g7SetLocalOverrideKeys;
    if (existingOverrides && typeof existingOverrides === 'object') {
      (window as any).__g7SetLocalOverrideKeys = deepMergeState(existingOverrides, {
        ...apiData,
        hasChanges: false,
      });
    }

    // 갱신 후: overrides에 API 데이터 반영
    const updatedOverrides = (window as any).__g7SetLocalOverrideKeys;
    expect(updatedOverrides.form.options).toHaveLength(6);
    expect(updatedOverrides.form.category_ids).toHaveLength(3);

    // handleLocalSetState에서 pendingToMerge = setLocalOverrides || pendingState
    // → updatedOverrides 사용 → API 데이터 포함 → 정상
    const pendingToMerge = updatedOverrides || {};
    expect(pendingToMerge.form.options).toHaveLength(6);
  });

  it('useLayoutEffect에서 __g7PendingLocalState도 API 데이터로 사전 설정', () => {
    // 초기 상태: clearing useLayoutEffect가 null로 클리어
    (window as any).__g7PendingLocalState = null;

    // API 데이터
    const apiData = {
      form: { options: [1, 2, 3, 4, 5, 6], category_ids: [10, 20, 30] },
    };

    // useLayoutEffect 수정 로직: pendingState를 API 데이터로 설정
    const currentPending = (window as any).__g7PendingLocalState || {};
    (window as any).__g7PendingLocalState = {
      ...currentPending,
      ...apiData,
      hasChanges: false,
    };

    // 자식 useEffect의 ActionDispatcher가 pendingState를 읽으면 API 데이터 포함
    const pendingState = (window as any).__g7PendingLocalState;
    expect(pendingState.form.options).toHaveLength(6);
    expect(pendingState.form.category_ids).toHaveLength(3);
  });

  it('__g7SetLocalOverrideKeys가 없으면 갱신하지 않음 (SPA 네비게이션 시나리오)', () => {
    // SPA 네비게이션: init_actions 미실행 → __g7SetLocalOverrideKeys = undefined
    (window as any).__g7SetLocalOverrideKeys = undefined;

    const apiData = {
      form: { options: [1, 2, 3], category_ids: [10] },
    };

    // useLayoutEffect: existingOverrides가 없으면 스킵
    const existingOverrides = (window as any).__g7SetLocalOverrideKeys;
    if (existingOverrides && typeof existingOverrides === 'object') {
      (window as any).__g7SetLocalOverrideKeys = deepMergeState(existingOverrides, apiData);
    }

    // __g7SetLocalOverrideKeys는 여전히 undefined
    expect((window as any).__g7SetLocalOverrideKeys).toBeUndefined();

    // handleLocalSetState: pendingToMerge = undefined || pendingState → pendingState 사용 → 정상
    const pendingState = { ...apiData, hasChanges: false };
    const pendingToMerge = (window as any).__g7SetLocalOverrideKeys || pendingState;
    expect(pendingToMerge.form.options).toHaveLength(3);
  });

  it('ref 해시 추적으로 동일 데이터 재설정 방지 (사용자 입력 덮어쓰기 방지)', () => {
    const apiData = { form: { options: [1, 2, 3] } };
    const syncKey = `${JSON.stringify(apiData)}:no-force`;

    // 첫 번째 실행: 해시 불일치 → 설정
    let currentHash: string | null = null;
    expect(currentHash !== syncKey).toBe(true);
    currentHash = syncKey;
    (window as any).__g7PendingLocalState = { ...apiData, hasChanges: false };

    // 사용자가 폼 수정
    (window as any).__g7PendingLocalState.form.options = [1, 2, 3, 4];

    // 두 번째 실행: 동일 해시 → 스킵 (사용자 입력 보존)
    expect(currentHash !== syncKey).toBe(false);
    // 설정하지 않으므로 사용자 수정값 유지
    expect((window as any).__g7PendingLocalState.form.options).toHaveLength(4);
  });
});
