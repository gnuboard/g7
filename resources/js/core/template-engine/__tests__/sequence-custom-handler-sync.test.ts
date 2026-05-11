/**
 * sequence 내 커스텀 핸들러 상태 동기화 테스트
 *
 * 커스텀 핸들러가 G7Core.state.setLocal()로 상태를 변경한 후,
 * 후속 setState 액션이 해당 변경을 보존하는지 검증합니다.
 *
 * @see docs/frontend/troubleshooting-state-setstate.md
 * @see resources/js/core/template-engine/ActionDispatcher.ts handleSequence
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher, ActionDefinition } from '../ActionDispatcher';
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

describe('sequence 내 커스텀 핸들러 상태 동기화', () => {
  let dispatcher: ActionDispatcher;
  let globalStateUpdater: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    dispatcher = new ActionDispatcher({
      navigate: vi.fn(),
    });

    globalStateUpdater = vi.fn();
    dispatcher.setGlobalStateUpdater(globalStateUpdater);

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
    delete (window as any).G7Core;
  });

  describe('커스텀 핸들러가 setLocal() 호출 후 후속 setState가 변경을 보존', () => {
    it('커스텀 핸들러가 __g7SequenceLocalSync를 설정하면 후속 setState가 해당 상태를 기반으로 병합해야 함', async () => {
      // 초기 상태: country_settings 빈 배열, _selectedCountryToAdd에 'US' 선택됨
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

      // 커스텀 핸들러: G7Core.state.setLocal()을 시뮬레이션하여
      // country_settings에 새 국가를 추가하고 __g7PendingLocalState + __g7SequenceLocalSync 설정
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
        // G7Core.state.setLocal()이 하는 것과 동일: 전체 스냅샷 저장
        // __g7PendingLocalState는 마이크로태스크 플러시로 클리어될 수 있으므로
        // __g7SequenceLocalSync도 함께 설정 (handleSequence에서 이 값을 읽음)
        (window as any).__g7PendingLocalState = newState;
        (window as any).__g7SequenceLocalSync = newState;
        return { success: true };
      });

      dispatcher.registerHandler('addCountrySetting', addCountryHandler);

      // sequence: 커스텀 핸들러 → setState (선택값 초기화)
      const sequenceAction: ActionDefinition = {
        type: 'click',
        handler: 'sequence',
        actions: [
          {
            type: 'click',
            handler: 'addCountrySetting' as any,
            params: { country_code: 'US' },
          },
          {
            type: 'click',
            handler: 'setState',
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

      // 검증: 후속 setState가 커스텀 핸들러의 변경(country_settings)을 보존해야 함
      const lastCall = globalStateUpdater.mock.calls[globalStateUpdater.mock.calls.length - 1][0];
      expect(lastCall).toHaveProperty('_local');

      // country_settings에 US가 포함되어야 함 (커스텀 핸들러의 변경 보존)
      expect(lastCall._local.form?.country_settings).toBeDefined();
      expect(lastCall._local.form.country_settings).toHaveLength(1);
      expect(lastCall._local.form.country_settings[0].country_code).toBe('US');

      // _selectedCountryToAdd가 null로 초기화되어야 함 (후속 setState 실행)
      expect(lastCall._local._selectedCountryToAdd).toBeNull();
    });

    it('커스텀 핸들러가 여러 키를 변경해도 후속 setState에서 모두 보존되어야 함', async () => {
      const initialState = {
        form: { items: [], total: 0 },
        isProcessing: false,
      };

      const mockG7Core = {
        state: {
          get: () => ({
            _local: initialState,
          }),
        },
      };
      (window as any).G7Core = mockG7Core;

      // 커스텀 핸들러: 여러 키를 동시에 변경
      const processItemsHandler = vi.fn((_params: any, context: any) => {
        const newState = {
          ...context.state,
          form: {
            ...context.state?.form,
            items: [{ id: 1, name: 'Item A' }, { id: 2, name: 'Item B' }],
            total: 200,
          },
          isProcessing: true,
        };
        (window as any).__g7PendingLocalState = newState;
        (window as any).__g7SequenceLocalSync = newState;
        return { success: true };
      });

      dispatcher.registerHandler('processItems', processItemsHandler);

      const sequenceAction: ActionDefinition = {
        type: 'click',
        handler: 'sequence',
        actions: [
          {
            type: 'click',
            handler: 'processItems' as any,
            params: {},
          },
          {
            type: 'click',
            handler: 'setState',
            params: {
              target: 'local',
              isProcessing: false,
            },
          },
        ],
      };

      const handler = dispatcher.createHandler(sequenceAction);
      await handler({
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event);

      const lastCall = globalStateUpdater.mock.calls[globalStateUpdater.mock.calls.length - 1][0];
      expect(lastCall._local.form.items).toHaveLength(2);
      expect(lastCall._local.form.total).toBe(200);
      // 후속 setState에 의해 isProcessing만 false로 변경
      expect(lastCall._local.isProcessing).toBe(false);
    });
  });

  describe('커스텀 핸들러가 setLocal() 호출하지 않은 경우', () => {
    it('__g7SequenceLocalSync가 없으면 동기화를 건너뛰어야 함', async () => {
      const initialState = {
        counter: 0,
      };

      const mockG7Core = {
        state: {
          get: () => ({
            _local: initialState,
          }),
        },
      };
      (window as any).G7Core = mockG7Core;

      // 커스텀 핸들러: __g7SequenceLocalSync를 설정하지 않음 (setLocal 미호출)
      const logHandler = vi.fn(() => {
        // 상태 변경 없이 로깅만 수행
        return { logged: true };
      });

      dispatcher.registerHandler('logAction', logHandler);

      const sequenceAction: ActionDefinition = {
        type: 'click',
        handler: 'sequence',
        actions: [
          {
            type: 'click',
            handler: 'logAction' as any,
            params: {},
          },
          {
            type: 'click',
            handler: 'setState',
            params: {
              target: 'local',
              counter: 1,
            },
          },
        ],
      };

      const handler = dispatcher.createHandler(sequenceAction);
      await handler({
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event);

      // 정상적으로 setState만 실행되어야 함
      const lastCall = globalStateUpdater.mock.calls[globalStateUpdater.mock.calls.length - 1][0];
      expect(lastCall._local.counter).toBe(1);
    });
  });

  describe('_computed 재계산', () => {
    it('커스텀 핸들러 후 상태가 동기화되면 _computed도 재계산되어야 함', async () => {
      const initialState = {
        form: { price: 100, quantity: 1 },
      };

      const mockG7Core = {
        state: {
          get: () => ({
            _local: initialState,
          }),
        },
      };
      (window as any).G7Core = mockG7Core;

      // 커스텀 핸들러: quantity를 변경
      const updateQuantityHandler = vi.fn((_params: any, context: any) => {
        const newState = {
          ...context.state,
          form: {
            ...context.state?.form,
            quantity: 5,
          },
        };
        (window as any).__g7PendingLocalState = newState;
        (window as any).__g7SequenceLocalSync = newState;
        return { success: true };
      });

      dispatcher.registerHandler('updateQuantity', updateQuantityHandler);

      // sequence 실행 - handleSequence 내부에서 _computed 재계산 검증
      // (실제 _computedDefinitions는 context.data에 전달되어야 하므로
      //  여기서는 동기화 자체가 올바르게 수행되는지만 검증)
      const sequenceAction: ActionDefinition = {
        type: 'click',
        handler: 'sequence',
        actions: [
          {
            type: 'click',
            handler: 'updateQuantity' as any,
            params: {},
          },
          {
            type: 'click',
            handler: 'setState',
            params: {
              target: 'local',
              'form.price': 200,
            },
          },
        ],
      };

      const handler = dispatcher.createHandler(sequenceAction);
      await handler({
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event);

      // 검증: 커스텀 핸들러의 quantity=5 + 후속 setState의 price=200 모두 반영
      const lastCall = globalStateUpdater.mock.calls[globalStateUpdater.mock.calls.length - 1][0];
      expect(lastCall._local.form.quantity).toBe(5);
      expect(lastCall._local.form.price).toBe(200);
    });
  });

  describe('기존 setState 경로 영향 없음 (회귀 방지)', () => {
    it('setState만 있는 sequence는 기존과 동일하게 동작해야 함', async () => {
      const initialState = {
        currencies: [{ code: 'KRW', name: '원화' }],
        isAddingCurrency: true,
        newCurrency: { code: 'USD', name: '달러' },
      };

      const mockG7Core = {
        state: {
          get: () => ({
            _local: initialState,
          }),
        },
      };
      (window as any).G7Core = mockG7Core;

      // 기존 사례 1: 순수 setState sequence
      const sequenceAction: ActionDefinition = {
        type: 'click',
        handler: 'sequence',
        actions: [
          {
            type: 'click',
            handler: 'setState',
            params: {
              target: 'local',
              currencies: [
                { code: 'KRW', name: '원화' },
                { code: 'USD', name: '달러' },
              ],
            },
          },
          {
            type: 'click',
            handler: 'setState',
            params: {
              target: 'local',
              isAddingCurrency: false,
              newCurrency: null,
            },
          },
        ],
      };

      const handler = dispatcher.createHandler(sequenceAction);
      await handler({
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event);

      const lastCall = globalStateUpdater.mock.calls[globalStateUpdater.mock.calls.length - 1][0];
      expect(lastCall._local.currencies).toHaveLength(2);
      expect(lastCall._local.isAddingCurrency).toBe(false);
      expect(lastCall._local.newCurrency).toBeNull();
    });

    it('setState 후 커스텀 핸들러 순서에서도 상태가 올바르게 전달되어야 함', async () => {
      const initialState = {
        status: 'idle',
        data: null,
      };

      const mockG7Core = {
        state: {
          get: () => ({
            _local: initialState,
          }),
        },
      };
      (window as any).G7Core = mockG7Core;

      // 커스텀 핸들러가 전달받은 context.state를 캡처
      let capturedContextState: any = null;

      const processHandler = vi.fn((_params: any, context: any) => {
        capturedContextState = context.state ? { ...context.state } : null;
        const newState = {
          ...context.state,
          data: { result: 'done', previousStatus: context.state?.status },
        };
        (window as any).__g7PendingLocalState = newState;
        (window as any).__g7SequenceLocalSync = newState;
        return { success: true };
      });

      dispatcher.registerHandler('process', processHandler);

      const sequenceAction: ActionDefinition = {
        type: 'click',
        handler: 'sequence',
        actions: [
          {
            type: 'click',
            handler: 'setState',
            params: {
              target: 'local',
              status: 'processing',
            },
          },
          {
            type: 'click',
            handler: 'process' as any,
            params: {},
          },
        ],
      };

      const handler = dispatcher.createHandler(sequenceAction);
      await handler({
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event);

      // 커스텀 핸들러가 실행되었는지 확인
      expect(processHandler).toHaveBeenCalled();

      // setState에서 설정한 'processing' 상태가 커스텀 핸들러의 context.state에 전달되었어야 함
      expect(capturedContextState).not.toBeNull();
      expect(capturedContextState.status).toBe('processing');

      // __g7PendingLocalState에 커스텀 핸들러의 결과가 저장되었는지 확인
      const pendingState = (window as any).__g7PendingLocalState;
      expect(pendingState).toBeDefined();
      expect(pendingState.data).toEqual({ result: 'done', previousStatus: 'processing' });
      expect(pendingState.status).toBe('processing');
    });
  });
});
