/**
 * ActionDispatcher conditions 핸들러 테스트
 *
 * conditions 핸들러를 통한 조건부 액션 실행 테스트
 *
 * @since engine-v1.10.0
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { ActionDispatcher, ActionDefinition } from '../template-engine/ActionDispatcher';
import { DataBindingEngine } from '../template-engine/DataBindingEngine';

// Mock 의존성
vi.mock('../template-engine/TranslationEngine', () => ({
  TranslationEngine: {
    getInstance: () => ({
      resolveTranslations: (text: string) => text,
      translate: (key: string) => key,
    }),
  },
}));

vi.mock('../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: () => ({
      getToken: () => null,
      isAuthenticated: () => false,
    }),
  },
  AuthType: { Bearer: 'Bearer' },
}));

vi.mock('../api/ApiClient', () => ({
  getApiClient: () => ({
    request: vi.fn().mockResolvedValue({ data: {} }),
  }),
}));

// window.G7Core mock
const mockG7Core = {
  state: {
    getGlobal: vi.fn(() => ({})),
    setGlobal: vi.fn(),
    setLocal: vi.fn(),
    getComputed: vi.fn(() => ({})),
  },
  modal: {
    open: vi.fn(),
    close: vi.fn(),
  },
  toast: {
    show: vi.fn(),
  },
  router: {
    navigate: vi.fn(),
  },
  devTools: undefined,
};

(global as any).window = {
  G7Core: mockG7Core,
  location: { href: '' },
  history: { back: vi.fn(), forward: vi.fn() },
  localStorage: {
    getItem: vi.fn(),
    setItem: vi.fn(),
  },
};

describe('ActionDispatcher conditions 핸들러', () => {
  let dispatcher: ActionDispatcher;
  let executedActions: any[];

  beforeEach(() => {
    vi.clearAllMocks();
    executedActions = [];

    dispatcher = new ActionDispatcher({
      navigate: vi.fn(),
      router: {
        navigate: vi.fn(),
      } as any,
    });

    // executeAction을 spy하여 실행된 액션 추적
    const originalExecuteAction = (dispatcher as any).executeAction.bind(dispatcher);
    vi.spyOn(dispatcher as any, 'executeAction').mockImplementation(async (action: any, context: any) => {
      executedActions.push({ handler: action.handler, params: action.params });

      // setState, toast, navigate 등 기본 핸들러는 실제 실행하지 않고 기록만
      if (['setState', 'toast', 'navigate', 'openModal', 'closeModal'].includes(action.handler)) {
        return { success: true, handler: action.handler };
      }

      // conditions는 실제 로직 실행
      if (action.handler === 'conditions') {
        return originalExecuteAction(action, context);
      }

      return { success: true };
    });
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('기본 if/else 체인', () => {
    it('첫 번째 조건이 true면 해당 브랜치의 then 액션을 실행해야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'conditions',
        conditions: [
          {
            if: "{{action === 'edit'}}",
            then: { type: 'click', handler: 'navigate', params: { path: '/edit' } },
          },
          {
            if: "{{action === 'delete'}}",
            then: { type: 'click', handler: 'openModal', params: { id: 'delete_modal' } },
          },
        ],
      };

      await dispatcher.dispatchAction(action, { data: { action: 'edit' } });

      expect(executedActions).toHaveLength(2); // conditions + navigate
      expect(executedActions[1]).toEqual({
        handler: 'navigate',
        params: { path: '/edit' },
      });
    });

    it('두 번째 조건이 true면 해당 브랜치의 then 액션을 실행해야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'conditions',
        conditions: [
          {
            if: "{{action === 'edit'}}",
            then: { type: 'click', handler: 'navigate', params: { path: '/edit' } },
          },
          {
            if: "{{action === 'delete'}}",
            then: { type: 'click', handler: 'openModal', params: { id: 'delete_modal' } },
          },
        ],
      };

      await dispatcher.dispatchAction(action, { data: { action: 'delete' } });

      expect(executedActions).toHaveLength(2); // conditions + openModal
      expect(executedActions[1]).toEqual({
        handler: 'openModal',
        params: { id: 'delete_modal' },
      });
    });

    it('모든 조건이 false면 아무 액션도 실행하지 않아야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'conditions',
        conditions: [
          {
            if: "{{action === 'edit'}}",
            then: { type: 'click', handler: 'navigate', params: { path: '/edit' } },
          },
          {
            if: "{{action === 'delete'}}",
            then: { type: 'click', handler: 'openModal', params: { id: 'delete_modal' } },
          },
        ],
      };

      await dispatcher.dispatchAction(action, { data: { action: 'view' } });

      // conditions만 실행되고 내부 액션은 실행되지 않음
      expect(executedActions).toHaveLength(1);
      expect(executedActions[0].handler).toBe('conditions');
    });

    it('else 브랜치 (if 없음)가 있으면 기본 액션으로 실행해야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'conditions',
        conditions: [
          {
            if: "{{action === 'edit'}}",
            then: { type: 'click', handler: 'navigate', params: { path: '/edit' } },
          },
          {
            // else 브랜치 (if 없음)
            then: { type: 'click', handler: 'toast', params: { message: '기본 액션' } },
          },
        ],
      };

      await dispatcher.dispatchAction(action, { data: { action: 'unknown' } });

      expect(executedActions).toHaveLength(2); // conditions + toast
      expect(executedActions[1]).toEqual({
        handler: 'toast',
        params: { message: '기본 액션' },
      });
    });
  });

  describe('$args 지원', () => {
    it('$args[0]로 전달된 값으로 조건을 평가해야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'conditions',
        conditions: [
          {
            if: "{{$args[0] === 'edit'}}",
            then: { type: 'click', handler: 'navigate', params: { path: '/edit' } },
          },
          {
            if: "{{$args[0] === 'delete'}}",
            then: { type: 'click', handler: 'openModal', params: { id: 'delete_modal' } },
          },
        ],
      };

      await dispatcher.dispatchAction(action, { data: { $args: ['edit'] } });

      expect(executedActions).toHaveLength(2);
      expect(executedActions[1]).toEqual({
        handler: 'navigate',
        params: { path: '/edit' },
      });
    });
  });

  describe('AND 그룹 조건', () => {
    it('AND 그룹의 모든 조건이 true면 브랜치를 실행해야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'conditions',
        conditions: [
          {
            if: {
              and: ['{{user.isLoggedIn}}', '{{user.hasPermission}}'],
            },
            then: { type: 'click', handler: 'navigate', params: { path: '/premium' } },
          },
          {
            then: { type: 'click', handler: 'toast', params: { type: 'error', message: '접근 권한 없음' } },
          },
        ],
      };

      await dispatcher.dispatchAction(action, {
        data: { user: { isLoggedIn: true, hasPermission: true } },
      });

      expect(executedActions).toHaveLength(2);
      expect(executedActions[1]).toEqual({
        handler: 'navigate',
        params: { path: '/premium' },
      });
    });

    it('AND 그룹의 하나라도 false면 다음 브랜치로 넘어가야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'conditions',
        conditions: [
          {
            if: {
              and: ['{{user.isLoggedIn}}', '{{user.hasPermission}}'],
            },
            then: { type: 'click', handler: 'navigate', params: { path: '/premium' } },
          },
          {
            then: { type: 'click', handler: 'toast', params: { type: 'error', message: '접근 권한 없음' } },
          },
        ],
      };

      await dispatcher.dispatchAction(action, {
        data: { user: { isLoggedIn: true, hasPermission: false } },
      });

      expect(executedActions).toHaveLength(2);
      expect(executedActions[1]).toEqual({
        handler: 'toast',
        params: { type: 'error', message: '접근 권한 없음' },
      });
    });
  });

  describe('OR 그룹 조건', () => {
    it('OR 그룹의 하나라도 true면 브랜치를 실행해야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'conditions',
        conditions: [
          {
            if: {
              or: ['{{user.isAdmin}}', '{{user.isManager}}'],
            },
            then: { type: 'click', handler: 'navigate', params: { path: '/admin' } },
          },
          {
            then: { type: 'click', handler: 'navigate', params: { path: '/user' } },
          },
        ],
      };

      await dispatcher.dispatchAction(action, {
        data: { user: { isAdmin: false, isManager: true } },
      });

      expect(executedActions).toHaveLength(2);
      expect(executedActions[1]).toEqual({
        handler: 'navigate',
        params: { path: '/admin' },
      });
    });
  });

  describe('중첩 AND/OR 조건', () => {
    it('OR 안에 AND가 중첩된 조건을 평가할 수 있어야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'conditions',
        conditions: [
          {
            if: {
              or: [
                '{{user.isSuperAdmin}}',
                {
                  and: ['{{user.isAdmin}}', "{{user.department === 'sales'}}"],
                },
              ],
            },
            then: { type: 'click', handler: 'navigate', params: { path: '/sales-dashboard' } },
          },
          {
            then: { type: 'click', handler: 'navigate', params: { path: '/home' } },
          },
        ],
      };

      // admin + sales 부서
      await dispatcher.dispatchAction(action, {
        data: { user: { isSuperAdmin: false, isAdmin: true, department: 'sales' } },
      });

      expect(executedActions[1]).toEqual({
        handler: 'navigate',
        params: { path: '/sales-dashboard' },
      });
    });
  });

  describe('then 배열 (sequence)', () => {
    it('then이 배열이면 순차적으로 실행해야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'conditions',
        conditions: [
          {
            if: "{{action === 'delete'}}",
            then: [
              { type: 'click', handler: 'setState', params: { target: '_local', deleteTargetId: '123' } },
              { type: 'click', handler: 'openModal', params: { id: 'delete_confirm_modal' } },
            ],
          },
        ],
      };

      // handleSequence를 spy
      const handleSequenceSpy = vi.spyOn(dispatcher as any, 'handleSequence');

      await dispatcher.dispatchAction(action, { data: { action: 'delete' } });

      // handleSequence가 호출되었는지 확인
      expect(handleSequenceSpy).toHaveBeenCalled();
    });
  });

  describe('에러 처리', () => {
    it('conditions가 없으면 경고를 출력하고 종료해야 함', async () => {
      const consoleWarnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'conditions',
        // conditions 없음
      };

      // 실제 executeAction 호출을 위해 spy 복원
      vi.restoreAllMocks();
      dispatcher = new ActionDispatcher({
        navigate: vi.fn(),
        router: { navigate: vi.fn() } as any,
      });

      const result = await dispatcher.dispatchAction(action, {});

      // 에러 없이 종료
      expect(result).toBeDefined();
    });

    it('conditions가 배열이 아니면 경고를 출력하고 종료해야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'conditions',
        conditions: 'invalid' as any, // 잘못된 타입
      };

      vi.restoreAllMocks();
      dispatcher = new ActionDispatcher({
        navigate: vi.fn(),
        router: { navigate: vi.fn() } as any,
      });

      const result = await dispatcher.dispatchAction(action, {});

      expect(result).toBeDefined();
    });
  });
});

describe('실제 사용 사례', () => {
  let dispatcher: ActionDispatcher;
  let executedActions: any[];

  beforeEach(() => {
    vi.clearAllMocks();
    executedActions = [];

    dispatcher = new ActionDispatcher({
      navigate: vi.fn(),
      router: { navigate: vi.fn() } as any,
    });

    const originalExecuteAction = (dispatcher as any).executeAction.bind(dispatcher);
    vi.spyOn(dispatcher as any, 'executeAction').mockImplementation(async (action: any, context: any) => {
      executedActions.push({ handler: action.handler, params: action.params });

      if (['setState', 'toast', 'navigate', 'openModal', 'closeModal', 'apiCall'].includes(action.handler)) {
        return { success: true, handler: action.handler };
      }

      if (action.handler === 'conditions') {
        return originalExecuteAction(action, context);
      }

      return { success: true };
    });
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('DataGrid 행 액션 처리 - edit/delete/view 분기', async () => {
    const action: ActionDefinition = {
      type: 'click',
      handler: 'conditions',
      conditions: [
        {
          if: "{{$args[0] === 'edit'}}",
          then: { type: 'click', handler: 'navigate', params: { path: '/products/edit/{{row.id}}' } },
        },
        {
          if: "{{$args[0] === 'delete'}}",
          then: [
            { type: 'click', handler: 'setState', params: { target: '_local', deleteTargetId: '{{row.id}}' } },
            { type: 'click', handler: 'openModal', params: { id: 'delete_confirm_modal' } },
          ],
        },
        {
          if: "{{$args[0] === 'view'}}",
          then: { type: 'click', handler: 'navigate', params: { path: '/products/view/{{row.id}}' } },
        },
        {
          then: { type: 'click', handler: 'toast', params: { type: 'warning', message: '알 수 없는 액션입니다.' } },
        },
      ],
    };

    // edit 클릭
    executedActions = [];
    await dispatcher.dispatchAction(action, { data: { $args: ['edit'], row: { id: '123' } } });
    expect(executedActions[1].handler).toBe('navigate');

    // delete 클릭
    executedActions = [];
    await dispatcher.dispatchAction(action, { data: { $args: ['delete'], row: { id: '456' } } });
    // handleSequence가 실행되므로 setState와 openModal이 순차적으로 호출될 것으로 예상

    // unknown 클릭
    executedActions = [];
    await dispatcher.dispatchAction(action, { data: { $args: ['unknown'], row: { id: '789' } } });
    expect(executedActions[1].handler).toBe('toast');
  });
});
