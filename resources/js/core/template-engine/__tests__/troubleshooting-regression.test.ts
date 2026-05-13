/**
 * 트러블슈팅 가이드 회귀 테스트
 *
 * troubleshooting-state-setstate.md에 기록된 사례들의 회귀 테스트입니다.
 * 각 테스트는 해당 사례가 재발하지 않도록 검증합니다.
 *
 * @see docs/frontend/troubleshooting-state-setstate.md
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { deepMergeState } from '../DynamicRenderer';
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

describe('트러블슈팅 회귀 테스트 - setState 관련', () => {
  let dispatcher: ActionDispatcher;
  let mockNavigate: ReturnType<typeof vi.fn>;
  let mockSetState: ReturnType<typeof vi.fn>;
  let mockGlobalStateUpdater: ReturnType<typeof vi.fn>;
  let capturedState: Record<string, any>;

  beforeEach(() => {
    mockNavigate = vi.fn();
    capturedState = {};

    // setState mock - 실제 handleLocalSetState 동작 시뮬레이션 (deepMergeState 사용)
    // ActionDispatcher는 context.setState()에 변경 필드만 plain object로 전달
    // 실제 DynamicRenderer의 handleLocalSetState는 deepMergeState(prev, payload)로 병합
    mockSetState = vi.fn((updater) => {
      if (typeof updater === 'function') {
        capturedState = updater(capturedState);
      } else {
        const { __mergeMode, __setStateId, ...payload } = updater;
        if (__mergeMode === 'replace') {
          capturedState = payload;
        } else if (__mergeMode === 'shallow') {
          capturedState = { ...capturedState, ...payload };
        } else {
          // default: deep merge (실제 handleLocalSetState 동작)
          capturedState = deepMergeState(capturedState, payload);
        }
      }
    });

    mockGlobalStateUpdater = vi.fn();

    dispatcher = new ActionDispatcher({
      navigate: mockNavigate,
      globalStateUpdater: mockGlobalStateUpdater,
    });

    Logger.getInstance().setDebug(false);

    // window 전역 변수 초기화
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

  describe('[TS-SETSTATE-1] sequence 내 여러 setState에서 상태 덮어쓰기 방지', () => {
    /**
     * 사례 1: sequence 내 여러 setState에서 상태 덮어쓰기
     *
     * 증상: sequence 액션에서 첫 번째 setState로 배열 추가 후,
     *       두 번째 setState가 실행되면 배열 추가가 사라짐
     *
     * 해결: 단일 setState에 모든 변경사항 병합
     */
    it('sequence 내 연속 setState에서 이전 상태가 손실되지 않아야 함', async () => {
      const context = {
        state: { currencies: ['USD'], showForm: true },
        setState: mockSetState,
        actionId: 'test-action-1',
      };

      // 첫 번째 setState: 통화 추가
      const action1 = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          currencies: ['USD', 'EUR'],
        },
      };

      await dispatcher.executeAction(action1, context);

      // 두 번째 setState: 폼 숨기기
      const action2 = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          showForm: false,
        },
      };

      // context.state를 업데이트된 값으로 설정 (sequence 시뮬레이션)
      context.state = capturedState;

      await dispatcher.executeAction(action2, context);

      // 두 변경사항 모두 유지되어야 함
      expect(capturedState.currencies).toEqual(['USD', 'EUR']);
      expect(capturedState.showForm).toBe(false);
    });
  });

  describe('[TS-SETSTATE-2] dot notation 키 충돌 해결', () => {
    /**
     * 사례 2: 같은 루트를 공유하는 dot notation 키 충돌
     *
     * 증상: form.email과 form.password를 동시에 설정하면 하나만 적용됨
     *
     * 해결: deepMergeWithState로 중첩 객체 병합
     */
    it('같은 루트를 공유하는 dot notation 키가 모두 적용되어야 함', async () => {
      // capturedState를 context.state 초기값으로 설정
      // (실제 DynamicRenderer에서는 localDynamicState가 이 역할)
      capturedState = { form: { name: 'existing' } };

      const context = {
        state: { form: { name: 'existing' } },
        setState: mockSetState,
        actionId: 'test-action-2',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          'form.email': 'test@example.com',
          'form.password': 'secret123',
        },
      };

      await dispatcher.executeAction(action, context);

      // 모든 필드가 병합되어야 함
      expect(capturedState.form.email).toBe('test@example.com');
      expect(capturedState.form.password).toBe('secret123');
      expect(capturedState.form.name).toBe('existing'); // 기존 값 유지
    });
  });

  describe('[TS-SETSTATE-4] dot notation 키에 null 값 설정', () => {
    /**
     * 사례 4: dot notation 키에 null 값 설정 시 오류
     *
     * 증상: form.field에 null을 설정하면 에러 발생
     *
     * 해결: setNestedValue에서 null 값 처리
     */
    it('dot notation 키에 null 값을 설정할 수 있어야 함', async () => {
      const context = {
        state: { form: { selectedId: 123 } },
        setState: mockSetState,
        actionId: 'test-action-4',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          'form.selectedId': null,
        },
      };

      // 에러 없이 실행되어야 함
      await expect(dispatcher.executeAction(action, context)).resolves.not.toThrow();
      expect(capturedState.form.selectedId).toBeNull();
    });
  });

  describe('[TS-SETSTATE-5] setNestedValue에서 배열 타입 보존', () => {
    /**
     * 사례 5: setNestedValue에서 배열이 객체로 변환됨
     *
     * 증상: form.items에 배열을 설정하면 객체로 변환됨
     *
     * 해결: 배열은 직접 할당, 객체만 병합
     */
    it('배열 값이 객체로 변환되지 않아야 함', async () => {
      const context = {
        state: { form: { items: [] } },
        setState: mockSetState,
        actionId: 'test-action-5',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          'form.items': [{ id: 1, name: 'Item 1' }, { id: 2, name: 'Item 2' }],
        },
      };

      await dispatcher.executeAction(action, context);

      expect(Array.isArray(capturedState.form.items)).toBe(true);
      expect(capturedState.form.items).toHaveLength(2);
      expect(capturedState.form.items[0].id).toBe(1);
    });
  });

  describe('[TS-SETSTATE-11] setState global에서 dot notation 병합 로직', () => {
    /**
     * 사례 11: setState global에서 dot notation 사용 시 기존 전역 상태 손실
     *
     * 증상: global에 user.name 설정 시 다른 전역 상태가 손실됨
     *
     * 해결: deepMergeWithState로 기존 전역 상태와 병합
     *
     * 참고: 실제 globalStateUpdater 호출 테스트는 ActionDispatcher.test.ts에서 수행
     * 여기서는 deepMergeWithState 로직만 검증
     */
    it('deepMergeWithState가 dot notation 키를 중첩 객체로 변환해야 함', async () => {
      // capturedState를 context.state 초기값으로 설정
      capturedState = { user: { id: 1 }, theme: 'dark' };

      const context = {
        state: { user: { id: 1 }, theme: 'dark' },
        setState: mockSetState,
        actionId: 'test-action-11',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          'user.name': 'John Doe',
          'user.email': 'john@example.com',
        },
      };

      await dispatcher.executeAction(action, context);

      // dot notation이 중첩 객체로 변환되어 병합되어야 함
      expect(capturedState.user.id).toBe(1); // 기존 값 유지
      expect(capturedState.user.name).toBe('John Doe'); // 새 값 추가
      expect(capturedState.user.email).toBe('john@example.com'); // 새 값 추가
      expect(capturedState.theme).toBe('dark'); // 다른 키 유지
    });
  });

  describe('[TS-SETSTATE-DATAKEY] dataKey 경로 내 필드 setState 시 __g7ForcedLocalFields 설정', () => {
    /**
     * 사례 2 (dataKey): dataKey 경로 내 필드를 setState로 업데이트 시 값이 반영되지 않음
     *
     * 증상: dataKey="form" 설정된 컴포넌트에서 form.xxx 업데이트 시 stale dynamicState가 덮어씀
     *
     * 해결: __g7ForcedLocalFields에 업데이트된 필드 저장
     */
    it('setState 실행 시 __g7ForcedLocalFields가 업데이트되어야 함', async () => {
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

      // __g7ForcedLocalFields가 설정되어야 함
      const forcedFields = (window as any).__g7ForcedLocalFields;
      expect(forcedFields).toBeDefined();
      expect(forcedFields.form).toBeDefined();
      expect(forcedFields.form.label_assignments).toEqual([24, 25]);
      expect(forcedFields.form.common_info_id).toBe(218);
    });

    it('연속 setState 시 __g7ForcedLocalFields가 누적되어야 함', async () => {
      const context = {
        state: { form: {}, ui: {} },
        setState: mockSetState,
        actionId: 'test-action-datakey-2',
      };

      // 첫 번째 setState
      const action1 = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          'form.label_assignments': [24],
        },
      };

      await dispatcher.executeAction(action1, context);

      // 두 번째 setState
      context.state = capturedState;
      const action2 = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          'form.shipping_policy_id': 33,
        },
      };

      await dispatcher.executeAction(action2, context);

      // __g7ForcedLocalFields에 두 필드 모두 있어야 함
      const forcedFields = (window as any).__g7ForcedLocalFields;
      expect(forcedFields.form.label_assignments).toEqual([24]);
      expect(forcedFields.form.shipping_policy_id).toBe(33);
    });

    it('__g7PendingLocalState도 함께 업데이트되어야 함', async () => {
      const context = {
        state: { form: { name: 'existing' } },
        setState: mockSetState,
        actionId: 'test-action-datakey-3',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          'form.common_info_id': 210,
        },
      };

      await dispatcher.executeAction(action, context);

      // __g7PendingLocalState가 설정되어야 함
      const pendingState = (window as any).__g7PendingLocalState;
      expect(pendingState).toBeDefined();
      expect(pendingState.form.common_info_id).toBe(210);
      expect(pendingState.form.name).toBe('existing'); // 기존 값 유지
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

  describe('[TS-INIT-1] init_actions에서 setState local 동작', () => {
    /**
     * 사례 1: init_actions에서 setState local이 작동하지 않음
     *
     * 증상: init_actions에서 target: "local"로 상태 설정 시 _local에 반영되지 않음
     *
     * 해결: componentContext 없을 때 globalStateUpdater({ _local: ... }) 사용
     *
     * 참고: globalStateUpdater fallback 테스트는 ActionDispatcher의 내부 구현에 의존하므로
     * 여기서는 context.setState가 있는 경우의 동작만 검증
     */
    it('componentContext가 있으면 setState를 통해 상태가 업데이트되어야 함', async () => {
      const dispatcher = new ActionDispatcher({
        navigate: mockNavigate,
      });

      // capturedState를 context.state 초기값으로 설정
      capturedState = { existingValue: 'keep' };

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
      expect(capturedState.existingValue).toBe('keep'); // 기존 값 유지
    });
  });

  describe('[TS-INIT-6] init_actions에서 target: "_local" 처리', () => {
    /**
     * 사례 6: init_actions에서 target: "_local" 사용 시 오류
     *
     * 증상: target: "_local"로 설정하면 _local._local에 저장됨
     *
     * 해결: target 정규화 (local, component, _local 모두 동일 처리)
     */
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
          target: '_local', // _local 접두사 사용
          formData: { name: 'test' },
        },
      };

      await dispatcher.executeAction(action, context);

      // _local._local이 아닌 formData로 직접 저장되어야 함
      expect(mockSetState).toHaveBeenCalled();
      expect(capturedState.formData).toEqual({ name: 'test' });
      expect(capturedState._local).toBeUndefined(); // _local 중첩 없음
    });
  });
});

describe('트러블슈팅 회귀 테스트 - G7Core.state.setLocal 관련', () => {
  beforeEach(() => {
    // window 전역 변수 초기화
    (window as any).__g7PendingLocalState = undefined;
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7ActionContext = undefined;
  });

  afterEach(() => {
    (window as any).__g7PendingLocalState = undefined;
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7ActionContext = undefined;
  });

  describe('[TS-SETLOCAL-DATAKEY] setLocal에서 dataKey 경로 필드 업데이트', () => {
    /**
     * 커스텀 핸들러에서 G7Core.state.setLocal() 호출 시
     * __g7ForcedLocalFields가 업데이트되어야 함
     *
     * 이 테스트는 G7CoreGlobals.test.ts의 setLocal 테스트와 함께
     * __g7ForcedLocalFields 업데이트 로직을 검증합니다.
     *
     * 실제 G7Core.state.setLocal() 호출 테스트는 G7CoreGlobals.test.ts에서 수행됩니다.
     * 여기서는 ActionDispatcher.handleSetState에서 __g7ForcedLocalFields가
     * 올바르게 설정되는지 검증합니다.
     */
    it('handleSetState 실행 후 __g7ForcedLocalFields가 설정되어야 함 (ActionDispatcher 경로)', async () => {
      // 이 테스트는 [TS-SETSTATE-DATAKEY] 테스트에서 이미 검증됨
      // ActionDispatcher.handleSetState에서 __g7ForcedLocalFields 설정 확인
      const mockSetState = vi.fn();
      const dispatcher = new ActionDispatcher({
        navigate: vi.fn(),
      });

      const context = {
        state: { form: { label_assignments: [] } },
        setState: mockSetState,
        actionId: 'test-setlocal-datakey',
      };

      const action = {
        handler: 'setState' as const,
        params: {
          target: 'local',
          'form.label_assignments': [24, 25],
          hasChanges: true,
        },
      };

      await dispatcher.executeAction(action, context);

      // __g7ForcedLocalFields 확인
      const forcedFields = (window as any).__g7ForcedLocalFields;
      expect(forcedFields).toBeDefined();
      expect(forcedFields.form).toBeDefined();
      expect(forcedFields.form.label_assignments).toEqual([24, 25]);
      expect(forcedFields.hasChanges).toBe(true);

      // __g7PendingLocalState 확인
      const pendingState = (window as any).__g7PendingLocalState;
      expect(pendingState).toBeDefined();
      expect(pendingState.form.label_assignments).toEqual([24, 25]);
    });
  });
});
