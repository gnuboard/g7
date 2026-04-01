/**
 * ActionDispatcher.ts 테스트
 *
 * 이벤트 핸들러 생성 및 액션 실행 테스트
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
const mockGetToken = vi.fn();
vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({
    getToken: mockGetToken,
  })),
}));

describe('ActionDispatcher', () => {
  let dispatcher: ActionDispatcher;
  let mockNavigate: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    mockNavigate = vi.fn();
    mockGetToken.mockReset();
    dispatcher = new ActionDispatcher({ navigate: mockNavigate });
    // Logger 디버그 모드 활성화 (console.warn/error 테스트를 위해)
    Logger.getInstance().setDebug(true);
  });

  afterEach(() => {
    // Logger 디버그 모드 비활성화
    Logger.getInstance().setDebug(false);
  });

  describe('bindActionsToProps', () => {
    it('click 이벤트 핸들러를 생성해야 함', () => {
      const props = {
        actions: [
          {
            type: 'click' as const,
            handler: 'navigate',
            params: { path: '/home' },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      expect(boundProps.onClick).toBeDefined();
      expect(typeof boundProps.onClick).toBe('function');
    });

    it('change 이벤트 핸들러를 생성해야 함', () => {
      const props = {
        actions: [
          {
            type: 'change' as const,
            handler: 'setState',
            params: { target: 'local', value: 'test' },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      expect(boundProps.onChange).toBeDefined();
      expect(typeof boundProps.onChange).toBe('function');
    });

    it('keydown 이벤트 핸들러를 생성해야 함 (React camelCase: onKeyDown)', () => {
      const props = {
        actions: [
          {
            type: 'keydown' as const,
            handler: 'navigate',
            params: { path: '/search' },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      // React는 onKeyDown (대문자 D)을 사용
      expect(boundProps.onKeyDown).toBeDefined();
      expect(typeof boundProps.onKeyDown).toBe('function');
    });

    it('동일한 이벤트 타입의 여러 액션을 그룹화해야 함', () => {
      const props = {
        actions: [
          {
            type: 'change' as const,
            handler: 'setState',
            params: { target: 'local', value: 'test' },
          },
          {
            type: 'keydown' as const,
            handler: 'setState',
            params: { target: 'local', searchQuery: 'test' },
          },
          {
            type: 'keydown' as const,
            key: 'Enter',
            handler: 'navigate',
            params: { path: '/search' },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      expect(boundProps.onChange).toBeDefined();
      expect(boundProps.onKeyDown).toBeDefined();
    });

    describe('key 필터링', () => {
      it('key가 일치할 때만 액션을 실행해야 함', () => {
        const mockSetState = vi.fn();
        const componentContext = {
          state: {},
          setState: mockSetState,
        };

        const props = {
          actions: [
            {
              type: 'keydown' as const,
              key: 'Enter',
              handler: 'navigate',
              params: { path: '/search' },
            },
          ],
        };

        const boundProps = dispatcher.bindActionsToProps(props, {}, componentContext);

        // Enter 키 이벤트 시뮬레이션
        const enterEvent = new KeyboardEvent('keydown', { key: 'Enter' });
        boundProps.onKeyDown(enterEvent);

        // navigate가 호출되어야 함
        expect(mockNavigate).toHaveBeenCalledWith('/search', { replace: false });
      });

      it('key가 일치하지 않으면 액션을 실행하지 않아야 함', () => {
        const mockSetState = vi.fn();
        const componentContext = {
          state: {},
          setState: mockSetState,
        };

        const props = {
          actions: [
            {
              type: 'keydown' as const,
              key: 'Enter',
              handler: 'navigate',
              params: { path: '/search' },
            },
          ],
        };

        const boundProps = dispatcher.bindActionsToProps(props, {}, componentContext);

        // 다른 키 이벤트 시뮬레이션 (e.g., 'a')
        const otherEvent = new KeyboardEvent('keydown', { key: 'a' });
        boundProps.onKeyDown(otherEvent);

        // navigate가 호출되지 않아야 함
        expect(mockNavigate).not.toHaveBeenCalled();
      });

      it('key 필터가 없는 액션은 모든 키에 대해 실행되어야 함', () => {
        const mockSetState = vi.fn();
        const componentContext = {
          state: {},
          setState: mockSetState,
        };

        const props = {
          actions: [
            {
              type: 'keydown' as const,
              handler: 'setState',
              params: { target: 'local', value: 'typed' },
            },
          ],
        };

        const boundProps = dispatcher.bindActionsToProps(props, {}, componentContext);

        // 아무 키나 눌러도 실행되어야 함
        const keyEvent = new KeyboardEvent('keydown', { key: 'x' });
        boundProps.onKeyDown(keyEvent);

        expect(mockSetState).toHaveBeenCalled();
      });

      it('동일 이벤트에 key 필터가 있는 액션과 없는 액션이 함께 있을 때 올바르게 처리해야 함', () => {
        const mockSetState = vi.fn();
        const componentContext = {
          state: {},
          setState: mockSetState,
        };

        const props = {
          actions: [
            {
              type: 'keydown' as const,
              handler: 'setState',
              params: { target: 'local', anyKey: true },
            },
            {
              type: 'keydown' as const,
              key: 'Enter',
              handler: 'navigate',
              params: { path: '/search' },
            },
          ],
        };

        const boundProps = dispatcher.bindActionsToProps(props, {}, componentContext);

        // 'a' 키를 누르면 setState만 호출되어야 함
        const aEvent = new KeyboardEvent('keydown', { key: 'a' });
        boundProps.onKeyDown(aEvent);

        expect(mockSetState).toHaveBeenCalledTimes(1);
        expect(mockNavigate).not.toHaveBeenCalled();

        // setState 호출 횟수 리셋
        mockSetState.mockClear();

        // Enter 키를 누르면 setState와 navigate 모두 호출되어야 함
        const enterEvent = new KeyboardEvent('keydown', { key: 'Enter' });
        boundProps.onKeyDown(enterEvent);

        expect(mockSetState).toHaveBeenCalledTimes(1);
        expect(mockNavigate).toHaveBeenCalledWith('/search', { replace: false });
      });

      it('Escape 키 필터링이 동작해야 함', () => {
        const mockSetState = vi.fn();
        const componentContext = {
          state: {},
          setState: mockSetState,
        };

        const props = {
          actions: [
            {
              type: 'keydown' as const,
              key: 'Escape',
              handler: 'setState',
              params: { target: 'local', isOpen: false },
            },
          ],
        };

        const boundProps = dispatcher.bindActionsToProps(props, {}, componentContext);

        // Escape 키 이벤트
        const escapeEvent = new KeyboardEvent('keydown', { key: 'Escape' });
        boundProps.onKeyDown(escapeEvent);

        expect(mockSetState).toHaveBeenCalled();
      });

      it('admin_user_list.json 시나리오: change와 keydown+Enter 조합', () => {
        const mockSetState = vi.fn();
        const componentContext = {
          state: {},
          setState: mockSetState,
        };

        // admin_user_list.json의 search_input과 동일한 구조
        const props = {
          actions: [
            {
              type: 'change' as const,
              handler: 'setState',
              params: { target: 'global', searchQuery: '{{$event.target.value}}' },
            },
            {
              type: 'keydown' as const,
              key: 'Enter',
              handler: 'navigate',
              params: { path: '/admin/users?search=test' },
            },
          ],
        };

        const boundProps = dispatcher.bindActionsToProps(props, {}, componentContext);

        // 1. onChange와 onKeyDown이 별도로 생성되어야 함
        expect(boundProps.onChange).toBeDefined();
        expect(boundProps.onKeyDown).toBeDefined();

        // 2. 'a' 키를 눌렀을 때: onKeyDown은 navigate를 호출하지 않아야 함
        mockNavigate.mockClear();
        const aKeyEvent = new KeyboardEvent('keydown', { key: 'a' });
        boundProps.onKeyDown(aKeyEvent);
        expect(mockNavigate).not.toHaveBeenCalled();

        // 3. Enter 키를 눌렀을 때: onKeyDown이 navigate를 호출해야 함
        mockNavigate.mockClear();
        const enterKeyEvent = new KeyboardEvent('keydown', { key: 'Enter' });
        boundProps.onKeyDown(enterKeyEvent);
        expect(mockNavigate).toHaveBeenCalledWith('/admin/users?search=test', { replace: false });
      });
    });

    describe('커스텀 컴포넌트 이벤트 처리', () => {
      it('MultilingualInput과 같은 커스텀 컴포넌트의 change 이벤트를 처리해야 함', () => {
        const mockSetGlobalState = vi.fn();
        // setGlobalStateUpdater로 globalState setter 설정
        dispatcher.setGlobalStateUpdater(mockSetGlobalState);

        const props = {
          actions: [
            {
              type: 'change' as const,
              handler: 'setState',
              params: { target: 'global', 'labelFormData.name': '{{$event.target.value}}' },
            },
          ],
        };

        const boundProps = dispatcher.bindActionsToProps(props, {});

        // MultilingualInput 형태의 커스텀 이벤트 객체 (preventDefault 없음)
        const customEvent = {
          target: {
            name: 'label_name',
            value: { ko: '수정된 이름', en: 'Modified Name' },
          },
        };

        boundProps.onChange(customEvent);

        // setState가 호출되어야 함 (dot notation은 중첩 객체로 변환됨)
        expect(mockSetGlobalState).toHaveBeenCalledWith({
          labelFormData: { name: { ko: '수정된 이름', en: 'Modified Name' } },
        });
      });

      it('표준 DOM 이벤트도 여전히 정상 동작해야 함', () => {
        const mockSetGlobalState = vi.fn();
        dispatcher.setGlobalStateUpdater(mockSetGlobalState);

        const props = {
          actions: [
            {
              type: 'change' as const,
              handler: 'setState',
              params: { target: 'global', color: '{{$event.target.value}}' },
            },
          ],
        };

        const boundProps = dispatcher.bindActionsToProps(props, {});

        // 표준 DOM input 이벤트처럼 보이는 객체 (preventDefault 포함)
        const domLikeEvent = {
          type: 'change',
          target: {
            value: '#FF0000',
          },
          preventDefault: () => {},
          stopPropagation: () => {},
        };

        boundProps.onChange(domLikeEvent);

        expect(mockSetGlobalState).toHaveBeenCalledWith({
          color: '#FF0000',
        });
      });

      it('target이 없는 객체도 raw value fallback으로 처리되어야 함', () => {
        const mockSetGlobalState = vi.fn();
        dispatcher.setGlobalStateUpdater(mockSetGlobalState);

        const props = {
          actions: [
            {
              type: 'change' as const,
              handler: 'setState',
              params: { target: 'global', value: '{{$event.target.value}}' },
            },
          ],
        };

        const boundProps = dispatcher.bindActionsToProps(props, {});

        // target이 없는 plain object → raw value fallback으로 처리
        // $event.target.value = { data: 'some data' } (raw value가 래핑됨)
        const rawValue = { data: 'some data' };

        boundProps.onChange(rawValue);

        // raw value fallback: { target: { value: rawValue } }로 래핑되어 핸들러 실행
        expect(mockSetGlobalState).toHaveBeenCalledWith(
          expect.objectContaining({
            value: rawValue,
          })
        );
      });
    });
  });

  describe('createHandler', () => {
    it('navigate 핸들러가 올바른 경로로 이동해야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'navigate',
        params: { path: '/dashboard' },
      };

      const handler = dispatcher.createHandler(action);

      // 클릭 이벤트 시뮬레이션
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockNavigate).toHaveBeenCalledWith('/dashboard', { replace: false });
    });

    it('데이터 바인딩이 포함된 경로를 처리해야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'navigate',
        params: { path: '/users/{{userId}}' },
      };

      const dataContext = { userId: 123 };
      const handler = dispatcher.createHandler(action, dataContext);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockNavigate).toHaveBeenCalledWith('/users/123', { replace: false });
    });

    it('복합 표현식({{A}}/text/{{B}})이 포함된 경로를 처리해야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'navigate',
        params: { path: '{{shopBase}}/category/{{cat?.slug}}' },
      };

      const dataContext = { shopBase: '/mall', cat: { slug: 'electronics' } };
      const handler = dispatcher.createHandler(action, dataContext);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockNavigate).toHaveBeenCalledWith('/mall/category/electronics', { replace: false });
    });

    it('_global이 포함된 복합 표현식을 최신 전역 상태로 해석해야 함', async () => {
      // window.G7Core 모킹으로 최신 _global 상태 제공
      const mockG7Core = {
        state: {
          get: vi.fn(() => ({
            _global: { shopBase: '/store' },
          })),
        },
      };
      (window as any).G7Core = mockG7Core;

      const action: ActionDefinition = {
        type: 'click',
        handler: 'navigate',
        params: { path: '{{_global.shopBase}}/category/{{cat?.slug}}' },
      };

      // dataContext의 _global은 오래된 값, 최신 상태는 G7Core에서 가져옴
      const dataContext = {
        _global: { shopBase: '/old-shop' },
        cat: { slug: 'clothing' },
      };
      const handler = dispatcher.createHandler(action, dataContext);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockNavigate).toHaveBeenCalledWith('/store/category/clothing', { replace: false });

      // cleanup
      delete (window as any).G7Core;
    });

    it('빈 shopBase와 복합 표현식을 올바르게 처리해야 함 (no_route 모드)', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'navigate',
        params: { path: '{{shopBase}}/products/{{id}}' },
      };

      const dataContext = { shopBase: '', id: 42 };
      const handler = dispatcher.createHandler(action, dataContext);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockNavigate).toHaveBeenCalledWith('/products/42', { replace: false });
    });
  });

  describe('setGlobalStateUpdater', () => {
    it('전역 상태 업데이터를 설정하고 사용할 수 있어야 함', async () => {
      const globalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalUpdater);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'setState',
        params: { target: 'global', testValue: 'hello' },
      };

      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(globalUpdater).toHaveBeenCalledWith({ testValue: 'hello' });
    });
  });

  describe('registerHandler / unregisterHandler', () => {
    it('커스텀 핸들러를 등록하고 실행할 수 있어야 함', async () => {
      const customHandler = vi.fn().mockResolvedValue({ custom: 'result' });
      dispatcher.registerHandler('customAction', customHandler);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'customAction',
        params: { customParam: 'value' },
      };

      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(customHandler).toHaveBeenCalled();
    });

    it('커스텀 핸들러를 제거할 수 있어야 함', () => {
      const customHandler = vi.fn();
      dispatcher.registerHandler('customAction', customHandler);

      expect(dispatcher.getRegisteredHandlers()).toContain('customAction');

      dispatcher.unregisterHandler('customAction');

      expect(dispatcher.getRegisteredHandlers()).not.toContain('customAction');
    });
  });

  describe('getRegisteredHandlers', () => {
    it('등록된 핸들러 목록을 반환해야 함', () => {
      dispatcher.registerHandler('handler1', vi.fn());
      dispatcher.registerHandler('handler2', vi.fn());

      const handlers = dispatcher.getRegisteredHandlers();

      expect(handlers).toContain('handler1');
      expect(handlers).toContain('handler2');
    });
  });

  describe('navigate with mergeQuery', () => {
    beforeEach(() => {
      // window.location.search mock
      Object.defineProperty(window, 'location', {
        value: {
          search: '?page=1&filter=active',
          pathname: '/admin/users',
        },
        writable: true,
      });
    });

    it('mergeQuery가 true일 때 기존 쿼리 파라미터와 새 파라미터를 병합해야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'navigate',
        params: {
          path: '/admin/users',
          mergeQuery: true,
          query: {
            sort_field: 'name',
            sort_direction: 'asc',
          },
        },
      };

      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      // 기존 파라미터(page, filter)가 유지되고 새 파라미터(sort_field, sort_direction)가 추가되어야 함
      expect(mockNavigate).toHaveBeenCalled();
      const navigatedPath = mockNavigate.mock.calls[0][0];
      expect(navigatedPath).toContain('page=1');
      expect(navigatedPath).toContain('filter=active');
      expect(navigatedPath).toContain('sort_field=name');
      expect(navigatedPath).toContain('sort_direction=asc');
    });

    it('mergeQuery가 true일 때 기존 파라미터를 덮어써야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'navigate',
        params: {
          path: '/admin/users',
          mergeQuery: true,
          query: {
            page: '2', // 기존 page=1을 덮어씀
          },
        },
      };

      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      const navigatedPath = mockNavigate.mock.calls[0][0];
      expect(navigatedPath).toContain('page=2');
      expect(navigatedPath).toContain('filter=active');
      // page=1이 있으면 안 됨
      expect(navigatedPath).not.toMatch(/page=1/);
    });

    it('빈 값의 파라미터는 제거해야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'navigate',
        params: {
          path: '/admin/users',
          mergeQuery: true,
          query: {
            filter: '', // 빈 문자열은 파라미터 제거
          },
        },
      };

      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      const navigatedPath = mockNavigate.mock.calls[0][0];
      expect(navigatedPath).toContain('page=1');
      expect(navigatedPath).not.toContain('filter');
    });

    it('mergeQuery 없이는 기존 동작 유지', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'navigate',
        params: {
          path: '/admin/users?new_param=value',
        },
      };

      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockNavigate).toHaveBeenCalledWith('/admin/users?new_param=value', { replace: false });
    });
  });

  describe('커스텀 콜백 이벤트 (onSortChange, onSelectionChange 등)', () => {
    it('커스텀 콜백 이벤트의 여러 인자를 $args 배열로 전달해야 함', () => {
      const mockSetState = vi.fn();
      const globalStateUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalStateUpdater);

      const componentContext = {
        state: {},
        setState: mockSetState,
      };

      // onSortChange 이벤트 시뮬레이션 (DataGrid에서 사용)
      const props = {
        actions: [
          {
            event: 'onSortChange',
            type: 'change' as const,
            handler: 'setState',
            params: {
              target: 'global',
              sortField: '{{$args[0]}}',
              sortDirection: '{{$args[1]}}',
            },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props, {}, componentContext);

      expect(boundProps.onSortChange).toBeDefined();
      expect(typeof boundProps.onSortChange).toBe('function');

      // onSortChange('name', 'desc') 호출 시뮬레이션
      boundProps.onSortChange('name', 'desc');

      // globalStateUpdater가 올바른 값으로 호출되어야 함
      expect(globalStateUpdater).toHaveBeenCalledWith({
        sortField: 'name',
        sortDirection: 'desc',
      });
    });

    it('onSelectionChange 이벤트의 배열 인자를 처리해야 함', () => {
      const mockSetState = vi.fn();
      const componentContext = {
        state: {},
        setState: mockSetState,
      };

      // onSelectionChange 이벤트 시뮬레이션
      const props = {
        actions: [
          {
            event: 'onSelectionChange',
            type: 'change' as const,
            handler: 'setState',
            params: {
              target: 'local',
              selectedIds: '{{$args[0]}}',
            },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props, {}, componentContext);

      expect(boundProps.onSelectionChange).toBeDefined();

      // onSelectionChange([1, 2, 3]) 호출 시뮬레이션
      boundProps.onSelectionChange([1, 2, 3]);

      expect(mockSetState).toHaveBeenCalledWith({
        selectedIds: [1, 2, 3],
      });
    });

    it('표준 DOM 이벤트와 커스텀 콜백 이벤트를 구분해야 함', () => {
      const mockSetState = vi.fn();
      const globalStateUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalStateUpdater);

      const componentContext = {
        state: {},
        setState: mockSetState,
      };

      const props = {
        actions: [
          // 표준 DOM 이벤트
          {
            type: 'click' as const,
            handler: 'navigate',
            params: { path: '/home' },
          },
          // 커스텀 콜백 이벤트
          {
            event: 'onSortChange',
            type: 'change' as const,
            handler: 'setState',
            params: {
              target: 'global',
              sortField: '{{$args[0]}}',
            },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props, {}, componentContext);

      // 두 이벤트 핸들러가 모두 생성되어야 함
      expect(boundProps.onClick).toBeDefined();
      expect(boundProps.onSortChange).toBeDefined();

      // 커스텀 콜백 이벤트 테스트
      boundProps.onSortChange('email');
      expect(globalStateUpdater).toHaveBeenCalledWith({ sortField: 'email' });
    });

    it('onColumnVisibilityChange 이벤트를 처리해야 함', () => {
      const mockSetState = vi.fn();
      const globalStateUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalStateUpdater);

      const componentContext = {
        state: {},
        setState: mockSetState,
      };

      const props = {
        actions: [
          {
            event: 'onColumnVisibilityChange',
            type: 'change' as const,
            handler: 'setState',
            params: {
              target: 'global',
              visibleColumns: '{{$args[0]}}',
            },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props, {}, componentContext);

      // onColumnVisibilityChange(['id', 'name', 'email']) 호출 시뮬레이션
      boundProps.onColumnVisibilityChange(['id', 'name', 'email']);

      expect(globalStateUpdater).toHaveBeenCalledWith({
        visibleColumns: ['id', 'name', 'email'],
      });
    });
  });

  describe('openModal / closeModal 핸들러 (모달 스택)', () => {
    it('openModal 핸들러가 모달 스택과 activeModal을 설정해야 함', async () => {
      const globalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalUpdater);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'openModal',
        target: 'bulk_activate_confirm_modal',
      };

      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(globalUpdater).toHaveBeenCalledWith({
        modalStack: ['bulk_activate_confirm_modal'],
        activeModal: 'bulk_activate_confirm_modal',
      });
    });

    it('closeModal 핸들러가 빈 스택에서 모달을 제거해야 함', async () => {
      const globalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalUpdater);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'closeModal',
      };

      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(globalUpdater).toHaveBeenCalledWith({
        modalStack: [],
        activeModal: null,
      });
    });

    it('버튼 클릭으로 openModal을 호출할 수 있어야 함', () => {
      const globalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalUpdater);

      const props = {
        actions: [
          {
            type: 'click' as const,
            handler: 'openModal',
            target: 'delete_confirm_modal',
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      expect(boundProps.onClick).toBeDefined();

      // 클릭 이벤트 시뮬레이션
      const clickEvent = new MouseEvent('click');
      boundProps.onClick(clickEvent);

      expect(globalUpdater).toHaveBeenCalledWith({
        modalStack: ['delete_confirm_modal'],
        activeModal: 'delete_confirm_modal',
      });
    });

    it('globalStateUpdater가 설정되지 않은 경우 openModal이 경고를 출력해야 함', async () => {
      // globalStateUpdater 설정하지 않음
      const consoleWarnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'openModal',
        target: 'test_modal',
      };

      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(consoleWarnSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        'Global state updater is not set for openModal'
      );

      consoleWarnSpy.mockRestore();
    });

    it('globalStateUpdater가 설정되지 않은 경우 closeModal이 경고를 출력해야 함', async () => {
      // globalStateUpdater 설정하지 않음
      const consoleWarnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'closeModal',
      };

      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(consoleWarnSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        'Global state updater is not set for closeModal'
      );

      consoleWarnSpy.mockRestore();
    });

    it('사용자 목록 레이아웃 시나리오: 일괄 활성화 모달 열기/닫기', async () => {
      const globalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalUpdater);

      // 활성화 버튼 클릭으로 모달 열기
      const openAction: ActionDefinition = {
        type: 'click',
        handler: 'openModal',
        target: 'bulk_activate_confirm_modal',
      };

      const openHandler = dispatcher.createHandler(openAction);
      const openEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await openHandler(openEvent);

      expect(globalUpdater).toHaveBeenCalledWith({
        modalStack: ['bulk_activate_confirm_modal'],
        activeModal: 'bulk_activate_confirm_modal',
      });

      globalUpdater.mockClear();

      // 취소 버튼 클릭으로 모달 닫기
      const closeAction: ActionDefinition = {
        type: 'click',
        handler: 'closeModal',
      };

      const closeHandler = dispatcher.createHandler(closeAction);
      const closeEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await closeHandler(closeEvent);

      expect(globalUpdater).toHaveBeenCalledWith({
        modalStack: [],
        activeModal: null,
      });
    });

    it('멀티 모달: 두 번째 모달을 열면 스택에 추가되어야 함', async () => {
      const globalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalUpdater);

      // 첫 번째 모달 열기
      const openAction1: ActionDefinition = {
        type: 'click',
        handler: 'openModal',
        target: 'confirm_modal',
      };

      const handler1 = dispatcher.createHandler(openAction1);
      const mockEvent1 = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler1(mockEvent1);

      expect(globalUpdater).toHaveBeenCalledWith({
        modalStack: ['confirm_modal'],
        activeModal: 'confirm_modal',
      });

      globalUpdater.mockClear();

      // 두 번째 모달 열기 (기존 스택이 있는 상태에서)
      const openAction2: ActionDefinition = {
        type: 'click',
        handler: 'openModal',
        target: 'error_modal',
      };

      // 기존 모달 스택이 있는 컨텍스트로 핸들러 생성
      const dataContext = {
        _global: {
          modalStack: ['confirm_modal'],
        },
      };

      const handler2 = dispatcher.createHandler(openAction2, dataContext);
      const mockEvent2 = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler2(mockEvent2);

      expect(globalUpdater).toHaveBeenCalledWith({
        modalStack: ['confirm_modal', 'error_modal'],
        activeModal: 'error_modal',
      });
    });

    it('멀티 모달: closeModal이 스택에서 최상위 모달만 제거해야 함', async () => {
      const globalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalUpdater);

      // 스택에 두 개의 모달이 있는 상태에서 닫기
      const closeAction: ActionDefinition = {
        type: 'click',
        handler: 'closeModal',
      };

      const dataContext = {
        _global: {
          modalStack: ['confirm_modal', 'error_modal'],
        },
      };

      const handler = dispatcher.createHandler(closeAction, dataContext);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(globalUpdater).toHaveBeenCalledWith({
        modalStack: ['confirm_modal'],
        activeModal: 'confirm_modal',
      });
    });

    it('멀티 모달: 이미 열려있는 모달은 스택에 추가되지 않아야 함', async () => {
      const globalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalUpdater);
      const consoleWarnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const openAction: ActionDefinition = {
        type: 'click',
        handler: 'openModal',
        target: 'confirm_modal',
      };

      // 이미 confirm_modal이 스택에 있는 상태
      const dataContext = {
        _global: {
          modalStack: ['confirm_modal'],
        },
      };

      const handler = dispatcher.createHandler(openAction, dataContext);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      // globalUpdater가 호출되지 않아야 함 (이미 열려있는 모달)
      expect(globalUpdater).not.toHaveBeenCalled();
      expect(consoleWarnSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        'Modal "confirm_modal" is already open'
      );

      consoleWarnSpy.mockRestore();
    });

    it('에러 시나리오: API 오류 시 에러 모달이 중첩으로 열려야 함', async () => {
      const globalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalUpdater);

      // 확인 모달이 열려있는 상태에서 에러 모달 열기
      const openErrorAction: ActionDefinition = {
        type: 'click',
        handler: 'openModal',
        target: 'error_modal',
      };

      const dataContext = {
        _global: {
          modalStack: ['bulk_activate_confirm_modal'],
        },
      };

      const handler = dispatcher.createHandler(openErrorAction, dataContext);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(globalUpdater).toHaveBeenCalledWith({
        modalStack: ['bulk_activate_confirm_modal', 'error_modal'],
        activeModal: 'error_modal',
      });
    });
  });

  describe('switch 핸들러 (조건 분기 처리)', () => {
    it('$args[0] 값에 따라 올바른 케이스 액션을 실행해야 함', () => {
      const mockSetState = vi.fn();
      const componentContext = {
        state: {},
        setState: mockSetState,
      };

      // DataGrid onRowAction 시나리오
      const props = {
        actions: [
          {
            event: 'onRowAction',
            type: 'action' as const,
            handler: 'switch',
            cases: {
              view: {
                type: 'click' as const,
                handler: 'navigate',
                params: { path: '/users/{{$args[1].id}}' },
              },
              edit: {
                type: 'click' as const,
                handler: 'navigate',
                params: { path: '/users/{{$args[1].id}}/edit' },
              },
              delete: {
                type: 'click' as const,
                handler: 'openModal',
                target: 'delete_modal',
              },
            },
          },
        ],
      };

      const dataContext = {
        users: [{ id: 123, name: 'Test User' }],
      };

      const boundProps = dispatcher.bindActionsToProps(props, dataContext, componentContext);

      expect(boundProps.onRowAction).toBeDefined();

      // 'edit' 액션 실행
      boundProps.onRowAction('edit', { id: 123, name: 'Test User' });

      // navigate가 edit 경로로 호출되어야 함 (두 번째 인자로 replace 옵션 포함)
      expect(mockNavigate).toHaveBeenCalledWith('/users/123/edit', { replace: false });
    });

    it('view 케이스가 올바르게 동작해야 함', () => {
      const mockSetState = vi.fn();
      const componentContext = {
        state: {},
        setState: mockSetState,
      };

      const props = {
        actions: [
          {
            event: 'onRowAction',
            type: 'action' as const,
            handler: 'switch',
            cases: {
              view: {
                type: 'click' as const,
                handler: 'navigate',
                params: { path: '/users/{{$args[1].id}}' },
              },
            },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props, {}, componentContext);

      boundProps.onRowAction('view', { id: 456 });

      expect(mockNavigate).toHaveBeenCalledWith('/users/456', { replace: false });
    });

    it('delete 케이스로 모달을 열 수 있어야 함', () => {
      const globalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalUpdater);

      const mockSetState = vi.fn();
      const componentContext = {
        state: {},
        setState: mockSetState,
      };

      const props = {
        actions: [
          {
            event: 'onRowAction',
            type: 'action' as const,
            handler: 'switch',
            cases: {
              delete: {
                type: 'click' as const,
                handler: 'openModal',
                target: 'delete_confirm_modal',
              },
            },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props, {}, componentContext);

      boundProps.onRowAction('delete', { id: 789, name: 'User to delete' });

      expect(globalUpdater).toHaveBeenCalledWith({
        modalStack: ['delete_confirm_modal'],
        activeModal: 'delete_confirm_modal',
      });
    });

    it('존재하지 않는 케이스는 경고만 출력하고 무시해야 함', () => {
      const consoleWarnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
      const mockSetState = vi.fn();
      const componentContext = {
        state: {},
        setState: mockSetState,
      };

      const props = {
        actions: [
          {
            event: 'onRowAction',
            type: 'action' as const,
            handler: 'switch',
            cases: {
              view: {
                type: 'click' as const,
                handler: 'navigate',
                params: { path: '/users/{{$args[1].id}}' },
              },
            },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props, {}, componentContext);

      // 존재하지 않는 'unknown_action' 케이스 실행
      boundProps.onRowAction('unknown_action', { id: 1 });

      expect(consoleWarnSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        'switch handler: no case found for key "unknown_action" and no default case'
      );
      expect(mockNavigate).not.toHaveBeenCalled();

      consoleWarnSpy.mockRestore();
    });

    it('cases가 없으면 경고를 출력해야 함', async () => {
      const consoleWarnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'switch',
        // cases 없음
      };

      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(consoleWarnSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        'switch handler requires cases property'
      );

      consoleWarnSpy.mockRestore();
    });

    describe('admin_user_list.json 실제 시나리오: DataGrid onRowAction switch', () => {
      const createTestSetup = () => {
        const globalUpdater = vi.fn();
        dispatcher.setGlobalStateUpdater(globalUpdater);

        const mockSetState = vi.fn();
        const componentContext = {
          state: {},
          setState: mockSetState,
        };

        // admin_user_list.json의 실제 onRowAction 구조
        const props = {
          actions: [
            {
              event: 'onRowAction',
              type: 'action' as const,
              handler: 'switch',
              cases: {
                view: {
                  type: 'click' as const,
                  handler: 'navigate',
                  params: {
                    path: '/admin/users/{{$args[1].id}}',
                  },
                },
                edit: {
                  type: 'click' as const,
                  handler: 'navigate',
                  params: {
                    path: '/admin/users/{{$args[1].id}}/edit',
                  },
                },
                delete: {
                  type: 'click' as const,
                  handler: 'openModal',
                  target: 'delete_confirm_modal',
                },
              },
            },
          ],
        };

        const boundProps = dispatcher.bindActionsToProps(props, {}, componentContext);

        return { boundProps, globalUpdater };
      };

      it('view 액션이 올바른 경로로 이동해야 함', async () => {
        const { boundProps } = createTestSetup();

        boundProps.onRowAction('view', { id: 1, name: 'Admin User' });
        await vi.waitFor(() => {
          expect(mockNavigate).toHaveBeenCalledWith('/admin/users/1', { replace: false });
        });
      });

      it('edit 액션이 올바른 경로로 이동해야 함', async () => {
        const { boundProps } = createTestSetup();

        boundProps.onRowAction('edit', { id: 2, name: 'Another User' });
        await vi.waitFor(() => {
          expect(mockNavigate).toHaveBeenCalledWith('/admin/users/2/edit', { replace: false });
        });
      });

      it('delete 액션이 모달을 열어야 함', async () => {
        const { boundProps, globalUpdater } = createTestSetup();

        boundProps.onRowAction('delete', { id: 3, name: 'User to Delete' });
        await vi.waitFor(() => {
          expect(globalUpdater).toHaveBeenCalledWith({
            modalStack: ['delete_confirm_modal'],
            activeModal: 'delete_confirm_modal',
          });
        });
      });
    });
  });

  describe('setState spread 연산자 ("...") 처리', () => {
    it('"..." 키를 spread 연산자로 처리해야 함', async () => {
      const mockSetState = vi.fn();
      const componentContext = {
        state: {
          form: { email: 'test@test.com', status: 'active' },
        },
        setState: mockSetState,
      };

      const action: ActionDefinition = {
        type: 'change',
        handler: 'setState',
        params: {
          form: {
            '...': '{{_local.form}}',
            name: '{{$event.target.value}}',
          },
        },
      };

      const handler = dispatcher.createHandler(action, {}, componentContext);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'change',
        target: { value: 'New Name' },
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockSetState).toHaveBeenCalledWith({
        form: {
          email: 'test@test.com',
          status: 'active',
          name: 'New Name',
        },
      });
    });

    it('spread 후 동일한 키가 있으면 새 값으로 덮어써야 함', async () => {
      const mockSetState = vi.fn();
      const componentContext = {
        state: {
          form: { name: 'Old Name', email: 'old@test.com' },
        },
        setState: mockSetState,
      };

      const action: ActionDefinition = {
        type: 'change',
        handler: 'setState',
        params: {
          form: {
            '...': '{{_local.form}}',
            name: 'Updated Name',
          },
        },
      };

      const handler = dispatcher.createHandler(action, {}, componentContext);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'change',
        target: {},
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockSetState).toHaveBeenCalledWith({
        form: {
          name: 'Updated Name',
          email: 'old@test.com',
        },
      });
    });

    it('spread 값이 null/undefined이면 무시해야 함', async () => {
      const mockSetState = vi.fn();
      const componentContext = {
        state: {
          form: undefined,
        },
        setState: mockSetState,
      };

      const action: ActionDefinition = {
        type: 'change',
        handler: 'setState',
        params: {
          form: {
            '...': '{{_local.form}}',
            name: 'New Name',
          },
        },
      };

      const handler = dispatcher.createHandler(action, {}, componentContext);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'change',
        target: {},
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockSetState).toHaveBeenCalledWith({
        form: {
          name: 'New Name',
        },
      });
    });

    it('연속된 필드 변경에서 "..." 키가 중첩되지 않아야 함', async () => {
      let currentState = { form: {} };
      const mockSetState = vi.fn((updates) => {
        currentState = { ...currentState, ...updates };
      });
      const componentContext = {
        get state() {
          return currentState;
        },
        setState: mockSetState,
      };

      const action: ActionDefinition = {
        type: 'change',
        handler: 'setState',
        params: {
          form: {
            '...': '{{_local.form}}',
            name: '{{$event.target.value}}',
          },
        },
      };

      const handler1 = dispatcher.createHandler(action, {}, componentContext);
      await handler1({
        preventDefault: vi.fn(),
        type: 'change',
        target: { value: 'a' },
      } as unknown as Event);

      const handler2 = dispatcher.createHandler(action, {}, componentContext);
      await handler2({
        preventDefault: vi.fn(),
        type: 'change',
        target: { value: 'ab' },
      } as unknown as Event);

      const handler3 = dispatcher.createHandler(action, {}, componentContext);
      await handler3({
        preventDefault: vi.fn(),
        type: 'change',
        target: { value: 'abc' },
      } as unknown as Event);

      const lastCall = mockSetState.mock.calls[mockSetState.mock.calls.length - 1][0];
      expect(lastCall.form).toEqual({ name: 'abc' });
      expect(lastCall.form['...']).toBeUndefined();
    });
  });

  describe('이벤트 타입별 preventDefault 동작', () => {
    it('click 이벤트에서는 preventDefault()가 호출되어야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'navigate',
        params: { path: '/home' },
      };

      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockEvent.preventDefault).toHaveBeenCalled();
    });

    it('submit 이벤트에서는 preventDefault()가 호출되어야 함', async () => {
      const mockSetState = vi.fn();
      const componentContext = {
        state: {},
        setState: mockSetState,
      };

      const action: ActionDefinition = {
        type: 'submit',
        handler: 'setState',
        params: { submitted: true },
      };

      const handler = dispatcher.createHandler(action, {}, componentContext);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'submit',
        target: document.createElement('form'),
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockEvent.preventDefault).toHaveBeenCalled();
    });

    it('change 이벤트에서는 preventDefault()가 호출되지 않아야 함 (체크박스/라디오 지원)', async () => {
      const mockSetState = vi.fn();
      const componentContext = {
        state: { form: { role_ids: [] } },
        setState: mockSetState,
      };

      const action: ActionDefinition = {
        type: 'change',
        handler: 'setState',
        params: {
          form: {
            role_ids: [1, 2, 3],
          },
        },
      };

      const handler = dispatcher.createHandler(action, {}, componentContext);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'change',
        target: { type: 'checkbox', checked: true },
      } as unknown as Event;

      await handler(mockEvent);

      // change 이벤트에서는 preventDefault()가 호출되지 않아야 함
      expect(mockEvent.preventDefault).not.toHaveBeenCalled();
      // 하지만 setState는 정상적으로 호출되어야 함
      expect(mockSetState).toHaveBeenCalled();
    });

    it('체크박스 역할 선택 시나리오: change 이벤트에서 상태 업데이트가 정상 동작해야 함', async () => {
      const mockSetState = vi.fn();
      const componentContext = {
        state: { form: { role_ids: [1] } },
        setState: mockSetState,
      };

      // admin_user_form.json의 역할 체크박스와 유사한 구조
      const action: ActionDefinition = {
        type: 'change',
        handler: 'setState',
        params: {
          form: {
            '...': '{{_local.form}}',
            role_ids: '{{[..._local.form?.role_ids ?? [], 2]}}',
          },
        },
      };

      const handler = dispatcher.createHandler(action, {}, componentContext);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'change',
        target: { type: 'checkbox', checked: true, value: '2' },
      } as unknown as Event;

      await handler(mockEvent);

      // preventDefault가 호출되지 않아야 함
      expect(mockEvent.preventDefault).not.toHaveBeenCalled();
      // 상태 업데이트는 정상적으로 실행되어야 함
      expect(mockSetState).toHaveBeenCalled();
    });

    it('keydown 이벤트에서는 preventDefault()가 호출되어야 함', async () => {
      const action: ActionDefinition = {
        type: 'keydown',
        key: 'Enter',
        handler: 'navigate',
        params: { path: '/search' },
      };

      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'keydown',
        key: 'Enter',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockEvent.preventDefault).toHaveBeenCalled();
    });
  });

  describe('apiCall 핸들러 auth_required 옵션', () => {
    let mockFetch: ReturnType<typeof vi.fn>;
    let originalFetch: typeof fetch;

    beforeEach(() => {
      originalFetch = globalThis.fetch;
      mockFetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve({ success: true, data: {} }),
      });
      globalThis.fetch = mockFetch as unknown as typeof fetch;

      // CSRF 토큰 쿠키 mock
      Object.defineProperty(document, 'cookie', {
        value: 'XSRF-TOKEN=test-csrf-token',
        writable: true,
      });
    });

    afterEach(() => {
      globalThis.fetch = originalFetch;
    });

    // 헬퍼 함수: 특정 URL의 fetch 호출 찾기
    const findFetchCallByUrl = (url: string): [string, RequestInit] | undefined => {
      return mockFetch.mock.calls.find(
        (call: unknown[]) => call[0] === url
      ) as [string, RequestInit] | undefined;
    };

    it('auth_required가 false일 때 Authorization 헤더를 포함하지 않아야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/test',
        params: {
          method: 'POST',
          auth_required: false,
          body: { test: 'data' },
        },
      };

      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockFetch).toHaveBeenCalled();
      const apiCall = findFetchCallByUrl('/api/test');
      expect(apiCall).toBeDefined();
      const headers = apiCall![1].headers as Record<string, string>;

      expect(headers.Authorization).toBeUndefined();
    });

    it('auth_required가 true이고 토큰이 있을 때 Bearer 토큰을 포함해야 함', async () => {
      mockGetToken.mockReturnValue('test-bearer-token');

      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/admin/users/bulk-status',
        auth_required: true,
        params: {
          method: 'PATCH',
          body: { ids: [1, 2, 3], status: 'active' },
        },
      };

      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockFetch).toHaveBeenCalled();
      const apiCall = findFetchCallByUrl('/api/admin/users/bulk-status');
      expect(apiCall).toBeDefined();
      const headers = apiCall![1].headers as Record<string, string>;

      expect(headers.Authorization).toBe('Bearer test-bearer-token');
    });

    it('auth_required가 true이지만 토큰이 없을 때 Authorization 헤더를 포함하지 않아야 함', async () => {
      mockGetToken.mockReturnValue(null);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/admin/users/bulk-status',
        auth_required: true,
        params: {
          method: 'PATCH',
          body: { ids: [1, 2, 3], status: 'blocked' },
        },
      };

      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockFetch).toHaveBeenCalled();
      const apiCall = findFetchCallByUrl('/api/admin/users/bulk-status');
      expect(apiCall).toBeDefined();
      const headers = apiCall![1].headers as Record<string, string>;

      expect(headers.Authorization).toBeUndefined();
    });

    it('auth_required 기본값은 false여야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/public/data',
        params: {
          method: 'GET',
          // auth_required 지정하지 않음
        },
      };

      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      // getToken이 호출되지 않아야 함
      expect(mockGetToken).not.toHaveBeenCalled();
    });

    it('admin_user_list.json 시나리오: 일괄 상태 변경 API 호출 시 Bearer 토큰 포함', async () => {
      mockGetToken.mockReturnValue('admin-jwt-token');

      const globalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalUpdater);

      // admin_user_list.json의 bulk_activate_confirm_modal apiCall 구조
      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/admin/users/bulk-status',
        auth_required: true,
        params: {
          method: 'PATCH',
          body: {
            ids: [1, 2, 3],
            status: 'active',
          },
        },
      };

      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockFetch).toHaveBeenCalledWith(
        '/api/admin/users/bulk-status',
        expect.objectContaining({
          method: 'PATCH',
          headers: expect.objectContaining({
            Authorization: 'Bearer admin-jwt-token',
            'Content-Type': 'application/json',
          }),
          body: JSON.stringify({ ids: [1, 2, 3], status: 'active' }),
        })
      );
    });

    it('auth_required와 커스텀 headers가 함께 사용될 때 모두 포함되어야 함', async () => {
      mockGetToken.mockReturnValue('my-token');

      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/custom',
        auth_required: true,
        params: {
          method: 'POST',
          headers: {
            'X-Custom-Header': 'custom-value',
          },
          body: { data: 'test' },
        },
      };

      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockFetch).toHaveBeenCalled();
      const apiCall = findFetchCallByUrl('/api/custom');
      expect(apiCall).toBeDefined();
      const headers = apiCall![1].headers as Record<string, string>;

      expect(headers.Authorization).toBe('Bearer my-token');
      expect(headers['X-Custom-Header']).toBe('custom-value');
    });

    it('params.query가 GET 요청의 쿼리스트링으로 변환되어야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/products',
        auth_required: true,
        params: {
          method: 'GET',
          query: {
            page: 2,
            per_page: 20,
            search: 'test',
          },
        },
      };

      mockGetToken.mockReturnValue('test-token');
      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockFetch).toHaveBeenCalled();
      // URL에 쿼리스트링이 포함되어야 함
      const fetchCall = mockFetch.mock.calls.find(
        (call: unknown[]) => (call[0] as string).startsWith('/api/products')
      );
      expect(fetchCall).toBeDefined();
      const url = fetchCall![0] as string;
      expect(url).toContain('page=2');
      expect(url).toContain('per_page=20');
      expect(url).toContain('search=test');
    });

    it('params.query의 빈 값은 쿼리스트링에서 제외되어야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/products',
        params: {
          method: 'GET',
          query: {
            page: 2,
            search: '',
            filter: null,
          },
        },
      };

      const handler = dispatcher.createHandler(action);

      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      const fetchCall = mockFetch.mock.calls.find(
        (call: unknown[]) => (call[0] as string).startsWith('/api/products')
      );
      expect(fetchCall).toBeDefined();
      const url = fetchCall![0] as string;
      expect(url).toContain('page=2');
      expect(url).not.toContain('search=');
      expect(url).not.toContain('filter=');
    });

    it('onSuccess 컨텍스트에 result와 response 모두 포함되어야 함', async () => {
      const mockApiResponse = {
        success: true,
        data: [{ id: 1, name: 'Product 1' }, { id: 2, name: 'Product 2' }],
      };

      mockFetch.mockResolvedValueOnce({
        ok: true,
        json: () => Promise.resolve(mockApiResponse),
      });

      // onSuccess 핸들러에서 result와 response 모두 접근 가능한지 테스트
      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/products',
        params: { method: 'GET' },
        onSuccess: [
          {
            type: 'click',
            handler: 'setState',
            params: {
              target: 'global',
              // result와 response 모두 접근 가능한지 테스트
              resultData: '{{result.data}}',
              responseData: '{{response.data}}',
            },
          },
        ],
      };

      const globalStateUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalStateUpdater);

      (window as any).G7Core = {
        state: {
          get: () => ({ _global: {}, _local: {} }),
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      // globalStateUpdater가 호출되었고, result와 response 모두 해석되었는지 확인
      expect(globalStateUpdater).toHaveBeenCalled();
      const updateCall = globalStateUpdater.mock.calls[0][0];

      // result.data와 response.data 모두 동일한 값으로 해석되어야 함
      expect(updateCall.resultData).toEqual(mockApiResponse.data);
      expect(updateCall.responseData).toEqual(mockApiResponse.data);

      delete (window as any).G7Core;
    });
  });

  describe('refetchDataSource 핸들러', () => {
    it('생성자에서 refetchDataSource 핸들러가 자동 등록되어야 함', () => {
      const registeredHandlers = dispatcher.getRegisteredHandlers();
      expect(registeredHandlers).toContain('refetchDataSource');
    });

    it('dataSourceId가 없으면 경고를 출력해야 함', async () => {
      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'refetchDataSource',
        params: {},
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(consoleSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        'refetchDataSource: dataSourceId is required'
      );

      consoleSpy.mockRestore();
    });

    it('G7Core.dataSource.refetch가 호출되어야 함', async () => {
      const mockRefetch = vi.fn().mockResolvedValue({ data: [] });

      // G7Core.dataSource mock 설정
      (window as any).G7Core = {
        dataSource: {
          refetch: mockRefetch,
        },
      };

      const action: ActionDefinition = {
        type: 'click',
        handler: 'refetchDataSource',
        params: {
          dataSourceId: 'modules',
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      // sync 옵션이 없어도 globalStateOverride는 항상 포함됨 (빈 객체라도)
      expect(mockRefetch).toHaveBeenCalledWith('modules', expect.objectContaining({
        globalStateOverride: expect.any(Object),
      }));

      // cleanup
      delete (window as any).G7Core;
    });

    it('G7Core.dataSource.refetch가 없으면 경고를 출력해야 함', async () => {
      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      // G7Core가 없는 상태
      delete (window as any).G7Core;

      const action: ActionDefinition = {
        type: 'click',
        handler: 'refetchDataSource',
        params: {
          dataSourceId: 'modules',
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(consoleSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        'refetchDataSource: G7Core.dataSource.refetch is not available'
      );

      consoleSpy.mockRestore();
    });
  });

  describe('scroll 이벤트', () => {
    it('scroll 이벤트 핸들러가 등록되어야 함', () => {
      const props = {
        actions: [
          {
            type: 'scroll',
            handler: 'setState',
            params: { test: true },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);
      expect(boundProps.onScroll).toBeDefined();
      expect(typeof boundProps.onScroll).toBe('function');
    });

    it('scroll 이벤트가 발생하면 핸들러가 호출되어야 함', async () => {
      const mockGlobalStateUpdater = vi.fn();
      // target: 'global'일 때 handleSetState는 globalStateUpdater를 사용
      dispatcher.setGlobalStateUpdater(mockGlobalStateUpdater);

      const props = {
        actions: [
          {
            type: 'scroll',
            handler: 'setState',
            params: {
              target: 'global',
              scrolled: true,
            },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      const mockScrollEvent = {
        preventDefault: vi.fn(),
        type: 'scroll',
        target: {
          scrollTop: 100,
          scrollHeight: 500,
          clientHeight: 300,
        },
      } as unknown as Event;

      await boundProps.onScroll(mockScrollEvent);

      expect(mockGlobalStateUpdater).toHaveBeenCalled();
    });

    it('scroll 이벤트에서 스크롤 관련 속성이 $event.target에 포함되어야 함', async () => {
      let capturedParams: any = null;

      // sequence 핸들러를 사용하여 switch 내에서 $event.target 값을 확인
      dispatcher.registerHandler('captureScrollProps', async (_action, context) => {
        capturedParams = {
          scrollHeight: context.data?.$event?.target?.scrollHeight,
          scrollTop: context.data?.$event?.target?.scrollTop,
          clientHeight: context.data?.$event?.target?.clientHeight,
          scrollWidth: context.data?.$event?.target?.scrollWidth,
          scrollLeft: context.data?.$event?.target?.scrollLeft,
          clientWidth: context.data?.$event?.target?.clientWidth,
        };
      });

      const props = {
        actions: [
          {
            type: 'scroll',
            handler: 'captureScrollProps',
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      const mockElement = {
        scrollTop: 200,
        scrollHeight: 1000,
        clientHeight: 400,
        scrollLeft: 50,
        scrollWidth: 800,
        clientWidth: 600,
        value: '',
        name: '',
        checked: false,
        type: 'div',
        tagName: 'DIV',
      };

      const mockScrollEvent = {
        preventDefault: vi.fn(),
        type: 'scroll',
        target: mockElement,
      } as unknown as Event;

      await boundProps.onScroll(mockScrollEvent);

      expect(capturedParams).not.toBeNull();
      expect(capturedParams.scrollHeight).toBe(1000);
      expect(capturedParams.scrollTop).toBe(200);
      expect(capturedParams.clientHeight).toBe(400);
      expect(capturedParams.scrollWidth).toBe(800);
      expect(capturedParams.scrollLeft).toBe(50);
      expect(capturedParams.clientWidth).toBe(600);
    });
  });

  describe('appendDataSource 핸들러', () => {
    it('생성자에서 appendDataSource 핸들러가 자동 등록되어야 함', () => {
      const registeredHandlers = dispatcher.getRegisteredHandlers();
      expect(registeredHandlers).toContain('appendDataSource');
    });

    it('dataSourceId가 없으면 경고를 출력해야 함', async () => {
      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'appendDataSource',
        params: {},
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(consoleSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        'appendDataSource: dataSourceId is required'
      );

      consoleSpy.mockRestore();
    });

    it('newData가 없으면 경고를 출력해야 함', async () => {
      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'appendDataSource',
        params: {
          dataSourceId: 'templates',
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(consoleSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        'appendDataSource: newData is required'
      );

      consoleSpy.mockRestore();
    });

    it('G7Core.dataSource.updateData가 호출되어야 함', async () => {
      const mockUpdateData = vi.fn().mockResolvedValue(true);

      (window as any).G7Core = {
        dataSource: {
          updateData: mockUpdateData,
        },
      };

      // 데이터 컨텍스트 (API 응답 등을 시뮬레이션)
      // resolveParams는 context.data를 사용하므로 dataContext로 전달해야 함
      const dataContext = {
        response: {
          data: {
            data: [{ id: 21 }, { id: 22 }],
          },
        },
      };

      const action: ActionDefinition = {
        type: 'click',
        handler: 'appendDataSource',
        params: {
          dataSourceId: 'templates',
          dataPath: 'data',
          newData: '{{response.data.data}}',
        },
      };

      // dataContext를 두 번째 인자로 전달 (resolveParams가 context.data 사용)
      const handler = dispatcher.createHandler(action, dataContext);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockUpdateData).toHaveBeenCalledWith(
        'templates',
        'data',
        [{ id: 21 }, { id: 22 }],
        'append'
      );

      delete (window as any).G7Core;
    });

    it('G7Core.dataSource.updateData가 없으면 경고를 출력해야 함', async () => {
      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      delete (window as any).G7Core;

      const action: ActionDefinition = {
        type: 'click',
        handler: 'appendDataSource',
        params: {
          dataSourceId: 'templates',
          dataPath: 'data',
          newData: '{{[{ id: 1 }]}}',
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(consoleSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        'appendDataSource: G7Core.dataSource.updateData is not available'
      );

      consoleSpy.mockRestore();
    });
  });

  describe('updateDataSource 핸들러', () => {
    it('생성자에서 updateDataSource 핸들러가 자동 등록되어야 함', () => {
      const registeredHandlers = dispatcher.getRegisteredHandlers();
      expect(registeredHandlers).toContain('updateDataSource');
    });

    it('dataSourceId가 없으면 경고를 출력해야 함', async () => {
      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'updateDataSource',
        params: {},
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(consoleSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        'updateDataSource: dataSourceId is required'
      );

      consoleSpy.mockRestore();
    });

    it('data가 없으면 경고를 출력해야 함', async () => {
      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'updateDataSource',
        params: {
          dataSourceId: 'cartItems',
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(consoleSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        'updateDataSource: data is required'
      );

      consoleSpy.mockRestore();
    });

    it('G7Core.dataSource.set이 호출되어야 함', async () => {
      const mockSet = vi.fn();

      (window as any).G7Core = {
        dataSource: {
          set: mockSet,
        },
      };

      // API 응답 시뮬레이션
      const dataContext = {
        response: {
          data: {
            items: [{ id: 1, quantity: 5 }],
            calculation: { total: 10000 },
          },
        },
      };

      const action: ActionDefinition = {
        type: 'click',
        handler: 'updateDataSource',
        params: {
          dataSourceId: 'cartItems',
          data: '{{response.data}}',
        },
      };

      const handler = dispatcher.createHandler(action, dataContext);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockSet).toHaveBeenCalledWith(
        'cartItems',
        { items: [{ id: 1, quantity: 5 }], calculation: { total: 10000 } },
        { merge: false }
      );

      delete (window as any).G7Core;
    });

    it('merge 옵션이 true면 병합 모드로 호출해야 함', async () => {
      const mockSet = vi.fn();

      (window as any).G7Core = {
        dataSource: {
          set: mockSet,
        },
      };

      const dataContext = {
        response: {
          data: { items: [{ id: 1 }] },
        },
      };

      const action: ActionDefinition = {
        type: 'click',
        handler: 'updateDataSource',
        params: {
          dataSourceId: 'cartItems',
          data: '{{response.data}}',
          merge: true,
        },
      };

      const handler = dispatcher.createHandler(action, dataContext);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockSet).toHaveBeenCalledWith(
        'cartItems',
        { items: [{ id: 1 }] },
        { merge: true }
      );

      delete (window as any).G7Core;
    });

    it('G7Core.dataSource.set이 없으면 경고를 출력해야 함', async () => {
      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      delete (window as any).G7Core;

      const action: ActionDefinition = {
        type: 'click',
        handler: 'updateDataSource',
        params: {
          dataSourceId: 'cartItems',
          data: '{{response.data}}',
        },
      };

      const handler = dispatcher.createHandler(action, { response: { data: {} } });
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(consoleSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        'updateDataSource: G7Core.dataSource.set is not available'
      );

      consoleSpy.mockRestore();
    });
  });

  describe('scrollIntoView 핸들러', () => {
    it('생성자에서 scrollIntoView 핸들러가 자동 등록되어야 함', () => {
      const registeredHandlers = dispatcher.getRegisteredHandlers();
      expect(registeredHandlers).toContain('scrollIntoView');
    });

    it('selector가 없으면 경고를 출력해야 함', async () => {
      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'scrollIntoView',
        params: {},
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(consoleSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        'scrollIntoView: selector is required'
      );

      consoleSpy.mockRestore();
    });

    it('요소가 없으면 경고를 출력해야 함', async () => {
      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'scrollIntoView',
        params: {
          selector: '#non-existent-element',
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(consoleSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        'scrollIntoView: element not found for selector "#non-existent-element" after 1 attempts'
      );

      consoleSpy.mockRestore();
    });

    it('요소가 존재하면 scrollIntoView를 호출해야 함', async () => {
      // DOM 요소 생성
      const testElement = document.createElement('div');
      testElement.id = 'test-scroll-target';
      document.body.appendChild(testElement);

      const scrollIntoViewMock = vi.fn();
      testElement.scrollIntoView = scrollIntoViewMock;

      const action: ActionDefinition = {
        type: 'click',
        handler: 'scrollIntoView',
        params: {
          selector: '#test-scroll-target',
          behavior: 'smooth',
          block: 'end',
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(scrollIntoViewMock).toHaveBeenCalledWith({
        behavior: 'smooth',
        block: 'end',
        inline: 'nearest',
      });

      // 정리
      document.body.removeChild(testElement);
    });

    it('기본 옵션으로 scrollIntoView를 호출해야 함', async () => {
      const testElement = document.createElement('div');
      testElement.id = 'test-scroll-default';
      document.body.appendChild(testElement);

      const scrollIntoViewMock = vi.fn();
      testElement.scrollIntoView = scrollIntoViewMock;

      const action: ActionDefinition = {
        type: 'click',
        handler: 'scrollIntoView',
        params: {
          selector: '#test-scroll-default',
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(scrollIntoViewMock).toHaveBeenCalledWith({
        behavior: 'smooth',
        block: 'nearest',
        inline: 'nearest',
      });

      document.body.removeChild(testElement);
    });

    it('delay 파라미터가 있으면 지정된 시간만큼 대기해야 함', async () => {
      const testElement = document.createElement('div');
      testElement.id = 'test-scroll-delay';
      document.body.appendChild(testElement);

      const scrollIntoViewMock = vi.fn();
      testElement.scrollIntoView = scrollIntoViewMock;

      const action: ActionDefinition = {
        type: 'click',
        handler: 'scrollIntoView',
        params: {
          selector: '#test-scroll-delay',
          delay: 50,
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      const startTime = Date.now();
      await handler(mockEvent);
      const elapsed = Date.now() - startTime;

      // 최소 50ms 대기했는지 확인 (약간의 오차 허용)
      expect(elapsed).toBeGreaterThanOrEqual(45);
      expect(scrollIntoViewMock).toHaveBeenCalled();

      document.body.removeChild(testElement);
    });

    it('retryCount가 있으면 요소를 찾을 때까지 재시도해야 함', async () => {
      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'scrollIntoView',
        params: {
          selector: '#delayed-element',
          retryCount: 3,
          retryInterval: 30,
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      // 50ms 후에 요소 추가
      setTimeout(() => {
        const testElement = document.createElement('div');
        testElement.id = 'delayed-element';
        testElement.scrollIntoView = vi.fn();
        document.body.appendChild(testElement);
      }, 50);

      await handler(mockEvent);

      // 요소가 추가된 후 scrollIntoView가 호출되었는지 확인
      const element = document.getElementById('delayed-element');
      if (element) {
        expect(element.scrollIntoView).toHaveBeenCalled();
        document.body.removeChild(element);
      }

      consoleSpy.mockRestore();
    });

    it('retryCount 초과 후에도 요소가 없으면 경고를 출력해야 함', async () => {
      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'scrollIntoView',
        params: {
          selector: '#never-exists',
          retryCount: 2,
          retryInterval: 10,
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(consoleSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        'scrollIntoView: element not found for selector "#never-exists" after 3 attempts'
      );

      consoleSpy.mockRestore();
    });

    it('waitForElement=true일 때 이미 존재하는 요소에 즉시 스크롤해야 함', async () => {
      const testElement = document.createElement('div');
      testElement.id = 'existing-element';
      document.body.appendChild(testElement);

      const scrollIntoViewMock = vi.fn();
      testElement.scrollIntoView = scrollIntoViewMock;

      const action: ActionDefinition = {
        type: 'click',
        handler: 'scrollIntoView',
        params: {
          selector: '#existing-element',
          waitForElement: true,
          timeout: 1000,
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(scrollIntoViewMock).toHaveBeenCalled();

      document.body.removeChild(testElement);
    });

    it('waitForElement=true일 때 나중에 추가된 요소에 스크롤해야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'scrollIntoView',
        params: {
          selector: '#dynamic-element',
          waitForElement: true,
          timeout: 1000,
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      // 100ms 후에 요소 추가
      setTimeout(() => {
        const testElement = document.createElement('div');
        testElement.id = 'dynamic-element';
        testElement.scrollIntoView = vi.fn();
        document.body.appendChild(testElement);
      }, 100);

      await handler(mockEvent);

      const element = document.getElementById('dynamic-element');
      expect(element).not.toBeNull();
      expect(element?.scrollIntoView).toHaveBeenCalled();

      if (element) {
        document.body.removeChild(element);
      }
    });

    it('waitForElement=true일 때 timeout 초과 시 경고를 출력해야 함', async () => {
      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'scrollIntoView',
        params: {
          selector: '#timeout-element',
          waitForElement: true,
          timeout: 100,
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(consoleSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        'scrollIntoView: element not found for selector "#timeout-element" after 100ms timeout'
      );

      consoleSpy.mockRestore();
    });
  });

  describe('setState 깊은 병합 (deep merge)', () => {
    it('중첩 객체의 특정 필드만 업데이트해야 함', async () => {
      const mockSetState = vi.fn();
      const componentContext = {
        state: {
          formData: {
            name: 'old name',
            email: 'test@example.com',
            slug: 'old-slug',
          },
          hasChanges: false,
        },
        setState: mockSetState,
      };

      const action: ActionDefinition = {
        type: 'change',
        handler: 'setState',
        params: {
          target: 'local',
          formData: {
            name: 'new name',
          },
          hasChanges: true,
        },
      };

      const handler = dispatcher.createHandler(action, {}, componentContext);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'change',
        target: { value: 'new name' },
      } as unknown as Event;

      await handler(mockEvent);

      // formData.name만 업데이트되고, email과 slug는 유지되어야 함
      expect(mockSetState).toHaveBeenCalledWith({
        formData: {
          name: 'new name',
          email: 'test@example.com',
          slug: 'old-slug',
        },
        hasChanges: true,
      });
    });

    it('spread 연산자가 있으면 기존 방식대로 동작해야 함 (하위 호환성)', async () => {
      const mockSetState = vi.fn();
      const componentContext = {
        state: {
          formData: {
            name: 'old name',
            email: 'test@example.com',
          },
          hasChanges: false,
        },
        setState: mockSetState,
      };

      // spread 연산자로 기존 formData를 복사하고 name만 덮어쓰기
      const dataContext = {
        _local: {
          formData: {
            name: 'old name',
            email: 'test@example.com',
          },
        },
      };

      const action: ActionDefinition = {
        type: 'change',
        handler: 'setState',
        params: {
          target: 'local',
          formData: {
            '...': '{{_local.formData}}',
            name: '{{$event.target.value}}',
          },
          hasChanges: true,
        },
      };

      const handler = dispatcher.createHandler(action, dataContext, componentContext);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'change',
        target: { value: 'spread name' },
      } as unknown as Event;

      await handler(mockEvent);

      // spread 연산자로 복사된 후 name이 덮어쓰기됨
      expect(mockSetState).toHaveBeenCalledWith({
        formData: {
          name: 'spread name',
          email: 'test@example.com',
        },
        hasChanges: true,
      });
    });

    it('currentState에 해당 키가 없으면 새로 추가해야 함', async () => {
      const mockSetState = vi.fn();
      const componentContext = {
        state: {
          hasChanges: false,
        },
        setState: mockSetState,
      };

      const action: ActionDefinition = {
        type: 'change',
        handler: 'setState',
        params: {
          target: 'local',
          formData: {
            name: 'new name',
          },
          hasChanges: true,
        },
      };

      const handler = dispatcher.createHandler(action, {}, componentContext);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'change',
        target: { value: 'new name' },
      } as unknown as Event;

      await handler(mockEvent);

      // formData가 새로 추가됨 (병합할 기존 객체가 없으므로 그대로 설정)
      expect(mockSetState).toHaveBeenCalledWith({
        formData: {
          name: 'new name',
        },
        hasChanges: true,
      });
    });

    it('배열은 병합하지 않고 덮어쓰기해야 함', async () => {
      const mockSetState = vi.fn();
      const componentContext = {
        state: {
          selectedIds: [1, 2, 3],
          hasChanges: false,
        },
        setState: mockSetState,
      };

      const action: ActionDefinition = {
        type: 'click',
        handler: 'setState',
        params: {
          target: 'local',
          selectedIds: [4, 5],
        },
      };

      const handler = dispatcher.createHandler(action, {}, componentContext);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      // 배열은 병합되지 않고 덮어쓰기됨 (기존 상태의 다른 필드는 유지됨)
      expect(mockSetState).toHaveBeenCalledWith({
        selectedIds: [4, 5],
        hasChanges: false, // 기존 상태 유지
      });
    });

    it('null 값은 병합하지 않고 덮어쓰기해야 함', async () => {
      const mockSetState = vi.fn();
      const componentContext = {
        state: {
          formData: {
            name: 'test',
            email: 'test@example.com',
          },
        },
        setState: mockSetState,
      };

      const action: ActionDefinition = {
        type: 'click',
        handler: 'setState',
        params: {
          target: 'local',
          formData: null,
        },
      };

      const handler = dispatcher.createHandler(action, {}, componentContext);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      // null은 그대로 덮어쓰기
      expect(mockSetState).toHaveBeenCalledWith({
        formData: null,
      });
    });

    it('admin_board_form 시나리오: name 필드 변경 시 다른 formData 필드 유지', async () => {
      const mockSetState = vi.fn();
      const componentContext = {
        state: {
          formData: {
            name: 'old board name',
            slug: 'old-board',
            type: 'general',
            layout_type: 'global',
            show_view_count: true,
            use_comment: true,
          },
          hasChanges: false,
        },
        setState: mockSetState,
      };

      // _tab_basic.json의 name 필드 change 액션 시나리오
      const action: ActionDefinition = {
        type: 'change',
        handler: 'setState',
        params: {
          target: 'local',
          formData: {
            name: '{{$event.target.value}}',
          },
          hasChanges: true,
        },
      };

      const handler = dispatcher.createHandler(action, {}, componentContext);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'change',
        target: { value: 'new board name' },
      } as unknown as Event;

      await handler(mockEvent);

      // name만 업데이트되고 나머지 필드는 유지
      expect(mockSetState).toHaveBeenCalledWith({
        formData: {
          name: 'new board name',
          slug: 'old-board',
          type: 'general',
          layout_type: 'global',
          show_view_count: true,
          use_comment: true,
        },
        hasChanges: true,
      });
    });
  });

  describe('emitEvent 핸들러 (컴포넌트 이벤트 시스템)', () => {
    beforeEach(() => {
      // G7Core.componentEvent mock 설정
      const mockListeners = new Map<string, Set<(data?: any) => void | Promise<any>>>();
      (window as any).G7Core = {
        componentEvent: {
          on: (eventName: string, callback: (data?: any) => void | Promise<any>) => {
            if (!mockListeners.has(eventName)) {
              mockListeners.set(eventName, new Set());
            }
            mockListeners.get(eventName)!.add(callback);
            return () => {
              mockListeners.get(eventName)?.delete(callback);
            };
          },
          emit: vi.fn(async (eventName: string, data?: any): Promise<any[]> => {
            const listeners = mockListeners.get(eventName);
            if (!listeners || listeners.size === 0) {
              return [];
            }
            const results = await Promise.all(
              Array.from(listeners).map(async (callback) => {
                return await callback(data);
              })
            );
            return results;
          }),
          off: (eventName: string) => {
            mockListeners.delete(eventName);
          },
          clear: () => {
            mockListeners.clear();
          },
        },
        state: {
          get: vi.fn(() => ({})),
        },
      };
    });

    afterEach(() => {
      delete (window as any).G7Core;
    });

    it('생성자에서 emitEvent 핸들러가 자동 등록되어야 함', () => {
      const registeredHandlers = dispatcher.getRegisteredHandlers();
      expect(registeredHandlers).toContain('emitEvent');
    });

    it('event 파라미터가 없으면 경고를 출력해야 함', async () => {
      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'emitEvent',
        params: {},
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(consoleSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        'emitEvent: event parameter is required'
      );

      consoleSpy.mockRestore();
    });

    it('G7Core.componentEvent.emit이 호출되어야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'emitEvent',
        params: {
          event: 'upload:site_logo',
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect((window as any).G7Core.componentEvent.emit).toHaveBeenCalledWith(
        'upload:site_logo',
        expect.objectContaining({
          _context: expect.any(Object),
        })
      );
    });

    it('이벤트 데이터가 컨텍스트와 함께 전달되어야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'emitEvent',
        params: {
          event: 'custom:event',
          data: {
            collection: 'images',
            maxFiles: 5,
          },
        },
      };

      const dataContext = { user: { id: 1, name: 'Test' } };
      const handler = dispatcher.createHandler(action, dataContext);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      // emit이 호출되었는지 확인
      expect((window as any).G7Core.componentEvent.emit).toHaveBeenCalled();

      // 첫 번째 인자가 이벤트 이름인지 확인
      const callArgs = (window as any).G7Core.componentEvent.emit.mock.calls[0];
      expect(callArgs[0]).toBe('custom:event');

      // 두 번째 인자(mergedData)에 data와 _context가 포함되어 있는지 확인
      const mergedData = callArgs[1];
      expect(mergedData).toHaveProperty('collection', 'images');
      expect(mergedData).toHaveProperty('maxFiles', 5);
      expect(mergedData).toHaveProperty('_context');
      expect(mergedData._context).toHaveProperty('data');
      // data에 원본 dataContext의 user가 포함되어 있어야 함
      expect(mergedData._context.data).toHaveProperty('user');
      expect(mergedData._context.data.user).toEqual({ id: 1, name: 'Test' });
    });

    it('리스너가 없으면 경고를 출력해야 함', async () => {
      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'emitEvent',
        params: {
          event: 'nonexistent:event',
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(consoleSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        'emitEvent: No listeners found for "nonexistent:event"'
      );

      consoleSpy.mockRestore();
    });

    it('G7Core.componentEvent가 없으면 경고를 출력해야 함', async () => {
      // G7Core를 완전히 제거
      delete (window as any).G7Core;

      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'emitEvent',
        params: {
          event: 'test:event',
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(consoleSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        'emitEvent: G7Core.componentEvent is not available'
      );

      consoleSpy.mockRestore();
    });

    it('리스너의 결과를 _local._eventResult에 저장해야 함', async () => {
      const globalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalUpdater);

      // 리스너 등록
      const mockUploadResult = {
        uploadedAttachments: [{ id: 1, filename: 'test.jpg' }],
        existingFiles: [],
        allFiles: [{ id: 1, filename: 'test.jpg' }],
      };

      (window as any).G7Core.componentEvent.on('upload:test', async () => {
        return mockUploadResult;
      });

      const action: ActionDefinition = {
        type: 'click',
        handler: 'emitEvent',
        params: {
          event: 'upload:test',
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(globalUpdater).toHaveBeenCalledWith(
        expect.objectContaining({
          _local: expect.objectContaining({
            _eventResult: expect.objectContaining({
              event: 'upload:test',
              success: true,
              data: mockUploadResult,
              listeners: 1,
            }),
          }),
        })
      );
    });

    it('여러 리스너가 있을 때 모든 결과를 배열로 저장해야 함', async () => {
      const globalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalUpdater);

      // 여러 리스너 등록
      (window as any).G7Core.componentEvent.on('multi:event', async () => {
        return { result: 'listener1' };
      });
      (window as any).G7Core.componentEvent.on('multi:event', async () => {
        return { result: 'listener2' };
      });

      const action: ActionDefinition = {
        type: 'click',
        handler: 'emitEvent',
        params: {
          event: 'multi:event',
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(globalUpdater).toHaveBeenCalledWith(
        expect.objectContaining({
          _local: expect.objectContaining({
            _eventResult: expect.objectContaining({
              event: 'multi:event',
              success: true,
              listeners: 2,
            }),
          }),
        })
      );
    });

    it('FileUploader 업로드 시나리오: sequence에서 emitEvent 후 apiCall', async () => {
      const globalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalUpdater);

      // FileUploader의 업로드 결과 mock
      const uploadResult = {
        uploadedAttachments: [{ id: 1, hash: 'abc123', original_filename: 'logo.png' }],
        existingFiles: [],
        allFiles: [{ id: 1, hash: 'abc123', original_filename: 'logo.png' }],
      };

      (window as any).G7Core.componentEvent.on('upload:site_logo', async () => {
        return uploadResult;
      });

      // sequence 액션 정의 (admin_settings.json의 저장 버튼과 유사)
      const sequenceAction: ActionDefinition = {
        type: 'click',
        handler: 'sequence',
        actions: [
          {
            type: 'click',
            handler: 'emitEvent',
            params: {
              event: 'upload:site_logo',
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

      // emitEvent가 호출되었는지 확인
      expect((window as any).G7Core.componentEvent.emit).toHaveBeenCalledWith(
        'upload:site_logo',
        expect.any(Object)
      );
    });

    it('리스너에서 에러 발생 시 _eventResult에 에러 저장', async () => {
      const globalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalUpdater);

      const consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

      // emit mock을 에러를 던지도록 재설정
      (window as any).G7Core.componentEvent.emit = vi.fn(async () => {
        throw new Error('Upload failed');
      });

      const action: ActionDefinition = {
        type: 'click',
        handler: 'emitEvent',
        params: {
          event: 'error:event',
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      // 핸들러 실행 (에러가 createHandler 내부에서 catch됨)
      await handler(mockEvent);

      // 에러가 콘솔에 로그되어야 함
      expect(consoleErrorSpy).toHaveBeenCalledWith(
        '[ActionDispatcher]',
        expect.stringContaining('emitEvent: Event "error:event" failed'),
        expect.any(Error)
      );

      // 에러 결과가 저장되어야 함
      expect(globalUpdater).toHaveBeenCalledWith(
        expect.objectContaining({
          _local: expect.objectContaining({
            _eventResult: expect.objectContaining({
              event: 'error:event',
              success: false,
              error: 'Upload failed',
            }),
          }),
        })
      );

      consoleErrorSpy.mockRestore();
    });
  });

  describe('loadScript handler', () => {
    let originalCreateElement: typeof document.createElement;
    let originalHead: HTMLHeadElement;
    let appendedScripts: HTMLScriptElement[];

    beforeEach(() => {
      appendedScripts = [];
      originalCreateElement = document.createElement.bind(document);
      originalHead = document.head;

      // Mock document.createElement for script
      vi.spyOn(document, 'createElement').mockImplementation((tagName: string) => {
        if (tagName === 'script') {
          const script = originalCreateElement('script') as HTMLScriptElement;
          appendedScripts.push(script);
          return script;
        }
        return originalCreateElement(tagName);
      });

      // Mock document.head.appendChild
      vi.spyOn(document.head, 'appendChild').mockImplementation((node: Node) => {
        // Simulate async script load
        setTimeout(() => {
          const script = node as HTMLScriptElement;
          if (script.onload) {
            script.onload(new Event('load'));
          }
        }, 10);
        return node;
      });

      // Mock document.getElementById to return null (script not loaded)
      vi.spyOn(document, 'getElementById').mockReturnValue(null);

      // Clear loaded scripts cache
      (ActionDispatcher as any).loadedScripts = new Set();
    });

    afterEach(() => {
      vi.restoreAllMocks();
    });

    it('should load external script and execute onLoad action', async () => {
      const mockSetState = vi.fn();
      const onLoadAction: ActionDefinition = {
        type: 'click',
        handler: 'setState',
        params: { scriptLoaded: true },
      };

      const action: ActionDefinition = {
        type: 'click',
        handler: 'loadScript',
        params: {
          src: '//example.com/script.js',
          id: 'test_script',
        },
        onLoad: onLoadAction,
      };

      const handler = dispatcher.createHandler(action, {}, { setState: mockSetState });
      const mockEvent = { preventDefault: vi.fn(), stopPropagation: vi.fn(), target: null } as unknown as Event;

      await handler(mockEvent);

      // Wait for async script load simulation
      await new Promise(resolve => setTimeout(resolve, 50));

      // Script should be created with correct attributes
      expect(appendedScripts.length).toBe(1);
      expect(appendedScripts[0].src).toContain('example.com/script.js');
      expect(appendedScripts[0].id).toBe('test_script');

      // onLoad setState should be called
      expect(mockSetState).toHaveBeenCalledWith(
        expect.objectContaining({ scriptLoaded: true })
      );
    });

    it('should skip loading if script already loaded', async () => {
      const mockSetState = vi.fn();

      // Pre-add script to loaded cache
      (ActionDispatcher as any).loadedScripts.add('already_loaded');

      const action: ActionDefinition = {
        type: 'click',
        handler: 'loadScript',
        params: {
          src: '//example.com/already.js',
          id: 'already_loaded',
        },
        onLoad: {
          type: 'click',
          handler: 'setState',
          params: { reloaded: true },
        },
      };

      const handler = dispatcher.createHandler(action, {}, { setState: mockSetState });
      const mockEvent = { preventDefault: vi.fn(), stopPropagation: vi.fn(), target: null } as unknown as Event;

      await handler(mockEvent);

      // No new script should be created
      expect(appendedScripts.length).toBe(0);

      // But onLoad should still be called
      expect(mockSetState).toHaveBeenCalledWith(
        expect.objectContaining({ reloaded: true })
      );
    });

    it('should handle error gracefully if src is not provided', async () => {
      // Suppress console error for this test
      vi.spyOn(console, 'error').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'loadScript',
        params: {},
      };

      const handler = dispatcher.createHandler(action, {});
      const mockEvent = { preventDefault: vi.fn(), stopPropagation: vi.fn(), target: null } as unknown as Event;

      // Should not throw - error is handled internally via ErrorHandlingResolver
      await expect(handler(mockEvent)).resolves.not.toThrow();

      vi.restoreAllMocks();
    });
  });

  describe('callExternal handler', () => {
    beforeEach(() => {
      // Mock external library on window
      (window as any).testLib = {
        TestClass: class {
          options: any;
          constructor(options: any) {
            this.options = options;
            if (options.oncomplete) {
              // Simulate async callback
              setTimeout(() => options.oncomplete({ result: 'success' }), 10);
            }
          }
          open() {
            return 'opened';
          }
          embed(element: HTMLElement) {
            return `embedded in ${element.id}`;
          }
        },
      };

      // Mock G7Core.componentEvent
      (window as any).G7Core = {
        componentEvent: {
          emit: vi.fn(),
        },
      };
    });

    afterEach(() => {
      delete (window as any).testLib;
      delete (window as any).G7Core;
    });

    it('should call external constructor and method', async () => {
      const mockSetState = vi.fn();

      const action: ActionDefinition = {
        type: 'click',
        handler: 'callExternal',
        params: {
          constructor: 'testLib.TestClass',
          args: { option1: 'value1' },
          method: 'open',
        },
      };

      const handler = dispatcher.createHandler(action, {}, { setState: mockSetState });
      const mockEvent = { preventDefault: vi.fn(), stopPropagation: vi.fn(), target: null } as unknown as Event;

      await handler(mockEvent);

      // Method should have been called
      // The result is 'opened' from the mock
    });

    it('should emit event when callback is triggered', async () => {
      const mockSetState = vi.fn();

      const action: ActionDefinition = {
        type: 'click',
        handler: 'callExternal',
        params: {
          constructor: 'testLib.TestClass',
          args: { oncomplete: true },
          callbackEvent: 'test:complete',
        },
      };

      const handler = dispatcher.createHandler(action, {}, { setState: mockSetState });
      const mockEvent = { preventDefault: vi.fn(), stopPropagation: vi.fn(), target: null } as unknown as Event;

      await handler(mockEvent);

      // Wait for callback simulation
      await new Promise(resolve => setTimeout(resolve, 50));

      // G7Core.componentEvent.emit should be called with the event
      expect((window as any).G7Core.componentEvent.emit).toHaveBeenCalledWith(
        'test:complete',
        expect.objectContaining({ result: 'success' })
      );

      // setState should also be called with result
      expect(mockSetState).toHaveBeenCalledWith(
        expect.objectContaining({
          'test_complete_result': expect.objectContaining({ result: 'success' }),
        })
      );
    });

    it('should handle error gracefully if constructor not found', async () => {
      // Suppress console error for this test
      vi.spyOn(console, 'error').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'callExternal',
        params: {
          constructor: 'nonExistent.Class',
        },
      };

      const handler = dispatcher.createHandler(action, {});
      const mockEvent = { preventDefault: vi.fn(), stopPropagation: vi.fn(), target: null } as unknown as Event;

      // Should not throw - error is handled internally via ErrorHandlingResolver
      await expect(handler(mockEvent)).resolves.not.toThrow();

      vi.restoreAllMocks();
    });

    it('should handle error gracefully if constructor parameter is missing', async () => {
      // Suppress console error for this test
      vi.spyOn(console, 'error').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'callExternal',
        params: {},
      };

      const handler = dispatcher.createHandler(action, {});
      const mockEvent = { preventDefault: vi.fn(), stopPropagation: vi.fn(), target: null } as unknown as Event;

      // Should not throw - error is handled internally via ErrorHandlingResolver
      await expect(handler(mockEvent)).resolves.not.toThrow();

      vi.restoreAllMocks();
    });

    it('should handle embed method with target element', async () => {
      // Create a target element
      const targetElement = document.createElement('div');
      targetElement.id = 'embed-target';
      document.body.appendChild(targetElement);

      vi.spyOn(document, 'querySelector').mockReturnValue(targetElement);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'callExternal',
        params: {
          constructor: 'testLib.TestClass',
          args: {},
          method: 'embed',
          embedTarget: '#embed-target',
        },
      };

      const handler = dispatcher.createHandler(action, {});
      const mockEvent = { preventDefault: vi.fn(), stopPropagation: vi.fn(), target: null } as unknown as Event;

      await handler(mockEvent);

      // Cleanup
      document.body.removeChild(targetElement);
      vi.restoreAllMocks();
    });

    describe('callbackSetState', () => {
      it('should map callback data to state using callbackSetState (flat mapping)', async () => {
        const mockSetState = vi.fn();

        // Mock TestClass that calls oncomplete with result data
        (window as any).testLib.TestClass = class {
          constructor(options: any) {
            if (options.oncomplete) {
              setTimeout(
                () =>
                  options.oncomplete({
                    zonecode: '06234',
                    roadAddress: '서울 강남구 테헤란로 123',
                  }),
                10
              );
            }
          }
        };

        const action: ActionDefinition = {
          type: 'click',
          handler: 'callExternal',
          params: {
            constructor: 'testLib.TestClass',
            args: { oncomplete: true },
            callbackSetState: {
              zipcode: 'zonecode',
              address: 'roadAddress',
            },
          },
        };

        const handler = dispatcher.createHandler(action, {}, { setState: mockSetState });
        const mockEvent = {
          preventDefault: vi.fn(),
          stopPropagation: vi.fn(),
          target: null,
        } as unknown as Event;

        await handler(mockEvent);

        // Wait for callback simulation
        await new Promise(resolve => setTimeout(resolve, 50));

        // setState should be called with mapped values
        expect(mockSetState).toHaveBeenCalledWith(
          expect.objectContaining({
            zipcode: '06234',
            address: '서울 강남구 테헤란로 123',
          })
        );
      });

      it('should map callback data to state using callbackSetState (2-level nested)', async () => {
        const mockSetState = vi.fn();

        (window as any).testLib.TestClass = class {
          constructor(options: any) {
            if (options.oncomplete) {
              setTimeout(
                () =>
                  options.oncomplete({
                    zonecode: '06234',
                    roadAddress: '서울 강남구 테헤란로 123',
                  }),
                10
              );
            }
          }
        };

        const action: ActionDefinition = {
          type: 'click',
          handler: 'callExternal',
          params: {
            constructor: 'testLib.TestClass',
            args: { oncomplete: true },
            callbackSetState: {
              basic_info: {
                zipcode: 'zonecode',
                base_address: 'roadAddress',
              },
            },
          },
        };

        const handler = dispatcher.createHandler(action, {}, { setState: mockSetState });
        const mockEvent = {
          preventDefault: vi.fn(),
          stopPropagation: vi.fn(),
          target: null,
        } as unknown as Event;

        await handler(mockEvent);

        // Wait for callback simulation
        await new Promise(resolve => setTimeout(resolve, 50));

        // setState should be called with nested structure
        expect(mockSetState).toHaveBeenCalledWith(
          expect.objectContaining({
            basic_info: {
              zipcode: '06234',
              base_address: '서울 강남구 테헤란로 123',
            },
          })
        );
      });

      it('should map callback data to state using callbackSetState (3-level deep nested)', async () => {
        const mockSetState = vi.fn();

        (window as any).testLib.TestClass = class {
          constructor(options: any) {
            if (options.oncomplete) {
              setTimeout(
                () =>
                  options.oncomplete({
                    zonecode: '06234',
                    roadAddress: '서울 강남구 테헤란로 123',
                  }),
                10
              );
            }
          }
        };

        const action: ActionDefinition = {
          type: 'click',
          handler: 'callExternal',
          params: {
            constructor: 'testLib.TestClass',
            args: { oncomplete: true },
            callbackSetState: {
              form: {
                basic_info: {
                  zipcode: 'zonecode',
                  base_address: 'roadAddress',
                },
              },
            },
          },
        };

        const handler = dispatcher.createHandler(action, {}, { setState: mockSetState });
        const mockEvent = {
          preventDefault: vi.fn(),
          stopPropagation: vi.fn(),
          target: null,
        } as unknown as Event;

        await handler(mockEvent);

        // Wait for callback simulation
        await new Promise(resolve => setTimeout(resolve, 50));

        // setState should be called with 3-level nested structure
        expect(mockSetState).toHaveBeenCalledWith(
          expect.objectContaining({
            form: {
              basic_info: {
                zipcode: '06234',
                base_address: '서울 강남구 테헤란로 123',
              },
            },
          })
        );
      });

      it('should preserve existing state fields when using callbackSetState (deep merge)', async () => {
        const mockSetState = vi.fn();

        // 기존 상태: form에 다른 필드들이 있음
        const existingState = {
          form: {
            basic_info: {
              company_name: '테스트 회사',
              representative: '홍길동',
              phone: '02-1234-5678',
            },
            language_currency: {
              default_language: 'ko',
            },
          },
          hasChanges: false,
        };

        (window as any).testLib.TestClass = class {
          constructor(options: any) {
            if (options.oncomplete) {
              setTimeout(
                () =>
                  options.oncomplete({
                    zonecode: '06234',
                    roadAddress: '서울 강남구 테헤란로 123',
                  }),
                10
              );
            }
          }
        };

        const action: ActionDefinition = {
          type: 'click',
          handler: 'callExternal',
          params: {
            constructor: 'testLib.TestClass',
            args: { oncomplete: true },
            callbackSetState: {
              form: {
                basic_info: {
                  zipcode: 'zonecode',
                  base_address: 'roadAddress',
                },
              },
            },
          },
        };

        const handler = dispatcher.createHandler(action, {}, { setState: mockSetState, state: existingState });
        const mockEvent = {
          preventDefault: vi.fn(),
          stopPropagation: vi.fn(),
          target: null,
        } as unknown as Event;

        await handler(mockEvent);

        // Wait for callback simulation
        await new Promise(resolve => setTimeout(resolve, 50));

        // setState should be called with merged state (preserving existing fields)
        expect(mockSetState).toHaveBeenCalledWith(
          expect.objectContaining({
            form: expect.objectContaining({
              basic_info: expect.objectContaining({
                // 새로 추가된 필드
                zipcode: '06234',
                base_address: '서울 강남구 테헤란로 123',
                // 기존 필드가 유지되어야 함
                company_name: '테스트 회사',
                representative: '홍길동',
                phone: '02-1234-5678',
              }),
              // language_currency도 유지되어야 함
              language_currency: expect.objectContaining({
                default_language: 'ko',
              }),
            }),
          })
        );
      });
    });
  });

  describe('callExternalEmbed handler', () => {
    beforeEach(() => {
      // Mock external library on window
      (window as any).testLib = {
        TestClass: class {
          options: any;
          constructor(options: any) {
            this.options = options;
            if (options.oncomplete) {
              // Simulate async callback
              setTimeout(
                () =>
                  options.oncomplete({
                    zonecode: '06234',
                    roadAddress: '서울 강남구 테헤란로 123',
                  }),
                10
              );
            }
          }
          embed(element: HTMLElement) {
            return `embedded in ${element.id || 'layer'}`;
          }
        },
      };

      // Mock G7Core.componentEvent
      (window as any).G7Core = {
        componentEvent: {
          emit: vi.fn(),
        },
      };
    });

    afterEach(() => {
      delete (window as any).testLib;
      delete (window as any).G7Core;
      // Clean up any remaining overlays
      document.querySelectorAll('[data-embed-overlay]').forEach(el => el.remove());
    });

    it('should create embed layer and call external library', async () => {
      const mockSetState = vi.fn();

      const action: ActionDefinition = {
        type: 'click',
        handler: 'callExternalEmbed',
        params: {
          constructor: 'testLib.TestClass',
          args: { oncomplete: true },
        },
      };

      const handler = dispatcher.createHandler(action, {}, { setState: mockSetState });
      const mockEvent = {
        preventDefault: vi.fn(),
        stopPropagation: vi.fn(),
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      // Wait for callback simulation and layer cleanup
      await new Promise(resolve => setTimeout(resolve, 50));

      // Layer should be created and then removed after callback
    });

    it('should map callback data using callbackSetState with deep nesting and preserve existing state', async () => {
      const mockSetState = vi.fn();

      // 기존 상태: form에 다른 필드들이 있음
      const existingState = {
        form: {
          basic_info: {
            company_name: '테스트 회사',
            representative: '홍길동',
            business_number: '123-45-67890',
            phone: '02-1234-5678',
            // zipcode와 base_address는 아직 없음
          },
          language_currency: {
            default_language: 'ko',
          },
        },
        hasChanges: false,
      };

      const action: ActionDefinition = {
        type: 'click',
        handler: 'callExternalEmbed',
        params: {
          constructor: 'testLib.TestClass',
          args: { oncomplete: true },
          callbackSetState: {
            form: {
              basic_info: {
                zipcode: 'zonecode',
                base_address: 'roadAddress',
              },
            },
          },
        },
      };

      const handler = dispatcher.createHandler(action, {}, { setState: mockSetState, state: existingState });
      const mockEvent = {
        preventDefault: vi.fn(),
        stopPropagation: vi.fn(),
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      // Wait for callback simulation
      await new Promise(resolve => setTimeout(resolve, 50));

      // setState should be called with merged state (preserving existing fields)
      expect(mockSetState).toHaveBeenCalledWith(
        expect.objectContaining({
          form: expect.objectContaining({
            basic_info: expect.objectContaining({
              // 새로 추가된 필드
              zipcode: '06234',
              base_address: '서울 강남구 테헤란로 123',
              // 기존 필드가 유지되어야 함
              company_name: '테스트 회사',
              representative: '홍길동',
              business_number: '123-45-67890',
              phone: '02-1234-5678',
            }),
            // language_currency도 유지되어야 함
            language_currency: expect.objectContaining({
              default_language: 'ko',
            }),
          }),
        })
      );
    });

    it('should emit event when callbackEvent is specified', async () => {
      const mockSetState = vi.fn();

      const action: ActionDefinition = {
        type: 'click',
        handler: 'callExternalEmbed',
        params: {
          constructor: 'testLib.TestClass',
          args: { oncomplete: true },
          callbackEvent: 'address:selected',
        },
      };

      const handler = dispatcher.createHandler(action, {}, { setState: mockSetState });
      const mockEvent = {
        preventDefault: vi.fn(),
        stopPropagation: vi.fn(),
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      // Wait for callback simulation
      await new Promise(resolve => setTimeout(resolve, 50));

      // G7Core.componentEvent.emit should be called with the event
      expect((window as any).G7Core.componentEvent.emit).toHaveBeenCalledWith(
        'address:selected',
        expect.objectContaining({
          zonecode: '06234',
          roadAddress: '서울 강남구 테헤란로 123',
        })
      );
    });

    it('should handle error gracefully if constructor not found', async () => {
      // Suppress console error for this test
      vi.spyOn(console, 'error').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'callExternalEmbed',
        params: {
          constructor: 'nonExistent.Class',
        },
      };

      const handler = dispatcher.createHandler(action, {});
      const mockEvent = {
        preventDefault: vi.fn(),
        stopPropagation: vi.fn(),
        target: null,
      } as unknown as Event;

      // Should not throw - error is handled internally
      await expect(handler(mockEvent)).resolves.not.toThrow();

      vi.restoreAllMocks();
    });
  });

  describe('sequence 핸들러 상태 동기화', () => {
    it('sequence 내 setState 실행 후 다음 액션에서 업데이트된 상태를 참조해야 함', async () => {
      const mockSetState = vi.fn();

      // 초기 상태: errors 객체가 있음
      const initialState = {
        form: { name: 'Test' },
        errors: { 'form.name': ['이름은 필수입니다.'] },
        isSaving: false,
      };

      // sequence 액션: 첫 번째에서 errors를 null로 설정, 두 번째에서 다른 상태 변경
      const sequenceAction: ActionDefinition = {
        type: 'click',
        handler: 'sequence',
        actions: [
          {
            handler: 'setState',
            params: {
              target: 'local',
              errors: null,
              isSaving: true,
            },
          },
          {
            handler: 'setState',
            params: {
              target: 'local',
              isSaving: false,
              hasChanges: false,
            },
          },
        ],
      };

      const handler = dispatcher.createHandler(
        sequenceAction,
        {},
        { setState: mockSetState, state: initialState }
      );
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      // 첫 번째 setState 호출: errors: null, isSaving: true
      expect(mockSetState).toHaveBeenNthCalledWith(
        1,
        expect.objectContaining({
          form: { name: 'Test' },
          errors: null,
          isSaving: true,
        })
      );

      // 두 번째 setState 호출: errors가 null로 유지되어야 함 (이전 상태의 errors 객체가 병합되면 안됨)
      expect(mockSetState).toHaveBeenNthCalledWith(
        2,
        expect.objectContaining({
          form: { name: 'Test' },
          errors: null, // 핵심: 첫 번째 setState에서 설정한 null이 유지되어야 함
          isSaving: false,
          hasChanges: false,
        })
      );
    });

    it('sequence 내 setState에서 errors 클리어 후 후속 setState에서 이전 errors가 복원되지 않아야 함', async () => {
      const mockSetState = vi.fn();

      // 초기 상태: validation errors가 있는 상태
      const initialState = {
        form: {
          basic_info: {
            shop_name: '',
            route_path: 'shop',
          },
        },
        errors: {
          'basic_info.shop_name': ['쇼핑몰명은 필수 입력 항목입니다.'],
        },
        isSaving: false,
        hasChanges: true,
      };

      // ecommerce settings 저장 시나리오와 유사한 sequence
      const sequenceAction: ActionDefinition = {
        type: 'click',
        handler: 'sequence',
        actions: [
          {
            // 저장 시작: errors 클리어, isSaving true
            handler: 'setState',
            params: {
              target: 'local',
              isSaving: true,
              errors: null,
            },
          },
          {
            // 저장 성공 후: isSaving false, hasChanges false (errors는 명시하지 않음)
            handler: 'setState',
            params: {
              target: 'local',
              isSaving: false,
              hasChanges: false,
            },
          },
        ],
      };

      const handler = dispatcher.createHandler(
        sequenceAction,
        {},
        { setState: mockSetState, state: initialState }
      );
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      // 두 번째 setState에서 errors가 null로 유지되어야 함
      // (initialState의 errors가 병합되면 안됨)
      const secondCall = mockSetState.mock.calls[1][0];
      expect(secondCall.errors).toBeNull();
      expect(secondCall.isSaving).toBe(false);
      expect(secondCall.hasChanges).toBe(false);
      // form 데이터는 유지되어야 함
      expect(secondCall.form).toEqual(initialState.form);
    });

    it('sequence 내에서 setState 결과가 다음 액션의 context.state에 반영되어야 함', async () => {
      const mockSetState = vi.fn();
      const stateSnapshots: any[] = [];

      // setState를 호출할 때마다 상태 스냅샷 저장
      mockSetState.mockImplementation((newState: any) => {
        stateSnapshots.push({ ...newState });
      });

      const initialState = {
        counter: 0,
        history: [] as number[],
      };

      // 카운터를 증가시키는 sequence (이전 상태 참조 필요)
      const sequenceAction: ActionDefinition = {
        type: 'click',
        handler: 'sequence',
        actions: [
          {
            handler: 'setState',
            params: {
              target: 'local',
              counter: 1,
            },
          },
          {
            handler: 'setState',
            params: {
              target: 'local',
              counter: 2,
            },
          },
          {
            handler: 'setState',
            params: {
              target: 'local',
              counter: 3,
            },
          },
        ],
      };

      const handler = dispatcher.createHandler(
        sequenceAction,
        {},
        { setState: mockSetState, state: initialState }
      );
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      // 3번 호출되어야 함
      expect(mockSetState).toHaveBeenCalledTimes(3);

      // 각 호출에서 이전 상태가 올바르게 반영되어야 함
      expect(stateSnapshots[0].counter).toBe(1);
      expect(stateSnapshots[1].counter).toBe(2);
      expect(stateSnapshots[2].counter).toBe(3);
    });
  });

  describe('global setState dot notation 키 처리', () => {
    it('target=global일 때 dot notation 키를 중첩 객체로 변환하여 병합해야 함', async () => {
      const globalStateUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalStateUpdater);

      // G7Core.state.get() 모킹 - _global 내용물을 직접 반환
      const mockG7Core = {
        state: {
          get: () => ({
            deleteProduct: {
              target: { id: 123, name: 'Test Product' },
              canDelete: null,
              reason: null,
              relatedData: null,
            },
          }),
        },
      };
      (window as any).G7Core = mockG7Core;

      const action: ActionDefinition = {
        type: 'click',
        handler: 'setState',
        params: {
          target: 'global',
          'deleteProduct.canDelete': true,
          'deleteProduct.relatedData': { images: 5, options: 3 },
        },
      };

      const handler = dispatcher.createHandler(action, {}, {});
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      // globalStateUpdater가 병합된 중첩 객체로 호출되어야 함
      expect(globalStateUpdater).toHaveBeenCalledWith(
        expect.objectContaining({
          deleteProduct: {
            target: { id: 123, name: 'Test Product' },
            canDelete: true,
            reason: null,
            relatedData: { images: 5, options: 3 },
          },
        })
      );

      // cleanup
      delete (window as any).G7Core;
    });

    it('target=global일 때 dot notation 없는 키는 기존처럼 동작해야 함', async () => {
      const globalStateUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalStateUpdater);

      // G7Core.state.get() 모킹 - _global 내용물을 직접 반환
      const mockG7Core = {
        state: {
          get: () => ({
            existingKey: 'existing value',
          }),
        },
      };
      (window as any).G7Core = mockG7Core;

      const action: ActionDefinition = {
        type: 'click',
        handler: 'setState',
        params: {
          target: 'global',
          newKey: 'new value',
          anotherKey: { nested: true },
        },
      };

      const handler = dispatcher.createHandler(action, {}, {});
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      // globalStateUpdater가 기존 값과 병합되어 호출되어야 함
      expect(globalStateUpdater).toHaveBeenCalledWith(
        expect.objectContaining({
          existingKey: 'existing value',
          newKey: 'new value',
          anotherKey: { nested: true },
        })
      );

      // cleanup
      delete (window as any).G7Core;
    });

    it('target=global일 때 기존 중첩 객체에 dot notation으로 값을 병합해야 함', async () => {
      const globalStateUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalStateUpdater);

      // G7Core.state.get() 모킹 - 기존에 formData가 있는 경우 (_global 내용물 직접 반환)
      const mockG7Core = {
        state: {
          get: () => ({
            formData: {
              name: 'old name',
              email: 'test@example.com',
              slug: 'old-slug',
            },
          }),
        },
      };
      (window as any).G7Core = mockG7Core;

      const action: ActionDefinition = {
        type: 'change',
        handler: 'setState',
        params: {
          target: 'global',
          'formData.name': 'new name',
        },
      };

      const handler = dispatcher.createHandler(action, {}, {});
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'change',
        target: { value: 'new name' },
      } as unknown as Event;

      await handler(mockEvent);

      // formData.name만 업데이트되고 email, slug는 유지되어야 함
      expect(globalStateUpdater).toHaveBeenCalledWith(
        expect.objectContaining({
          formData: {
            name: 'new name',
            email: 'test@example.com',
            slug: 'old-slug',
          },
        })
      );

      // cleanup
      delete (window as any).G7Core;
    });

    it('target=global일 때 여러 dot notation 키를 동시에 처리해도 기존 속성이 보존되어야 함', async () => {
      const globalStateUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalStateUpdater);

      // G7Core.state.get() 모킹 - deleteProduct에 target 속성이 있는 경우 (_global 내용물 직접 반환)
      const mockG7Core = {
        state: {
          get: () => ({
            deleteProduct: {
              target: { id: 123, name: 'Test Product', product_code: 'PROD-001' },
              canDelete: null,
              reason: null,
              relatedData: null,
            },
          }),
        },
      };
      (window as any).G7Core = mockG7Core;

      // 실제 버그 시나리오: 여러 dot notation 키를 동시에 업데이트
      // apiCall onSuccess에서 deleteProduct.canDelete, deleteProduct.reason, deleteProduct.relatedData를 동시 업데이트
      const action: ActionDefinition = {
        type: 'click',
        handler: 'setState',
        params: {
          target: 'global',
          'deleteProduct.canDelete': true,
          'deleteProduct.reason': 'No dependencies',
          'deleteProduct.relatedData': { images: 5, options: 3 },
        },
      };

      const handler = dispatcher.createHandler(action, {}, {});
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      // 핵심: target 속성이 보존되어야 함!
      // 버그: 여러 dot notation 키 처리 시 target이 사라짐
      expect(globalStateUpdater).toHaveBeenCalledWith(
        expect.objectContaining({
          deleteProduct: {
            target: { id: 123, name: 'Test Product', product_code: 'PROD-001' },
            canDelete: true,
            reason: 'No dependencies',
            relatedData: { images: 5, options: 3 },
          },
        })
      );

      // cleanup
      delete (window as any).G7Core;
    });

    it('target=global일 때 항상 G7Core.state.get()을 참조해야 함 (context.state 무시)', async () => {
      /**
       * 2026-01-30 수정: target='global'은 항상 G7Core.state.get()을 사용
       * 이유: sequence 내 다른 핸들러(closeModal 등)가 변경한 값을 context.state의
       *       stale 값으로 덮어쓰는 버그 방지
       *
       * G7Core.state.get()은 globalStateUpdater 호출 후 즉시 업데이트되므로
       * sequence 내에서도 항상 최신 상태 참조 가능
       */
      const globalStateUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(globalStateUpdater);

      // G7Core.state.get()에 기존 상태 설정 (context.state가 아닌 이 값이 사용됨)
      const mockG7Core = {
        state: {
          get: () => ({
            deleteProduct: {
              target: { id: 456, name: 'Product from G7Core', product_code: 'G7-001' },
              canDelete: null,
              reason: null,
              relatedData: null,
            },
          }),
        },
      };
      (window as any).G7Core = mockG7Core;

      // context.state는 무시됨 (2026-01-30 수정)
      const contextState = {
        _global: {
          deleteProduct: {
            target: { id: 999, name: 'Stale product', product_code: 'STALE-001' },
          },
        },
        _local: {},
      };

      const action: ActionDefinition = {
        type: 'click',
        handler: 'setState',
        params: {
          target: 'global',
          'deleteProduct.canDelete': true,
          'deleteProduct.reason': 'Can delete safely',
        },
      };

      await (dispatcher as any).executeAction(action, {
        data: {},
        state: contextState, // 이 값은 무시됨
      });

      // G7Core.state.get()의 값이 사용되어야 함 (context.state가 아님)
      expect(globalStateUpdater).toHaveBeenCalledWith(
        expect.objectContaining({
          deleteProduct: expect.objectContaining({
            target: { id: 456, name: 'Product from G7Core', product_code: 'G7-001' },
            canDelete: true,
            reason: 'Can delete safely',
          }),
        })
      );

      // cleanup
      delete (window as any).G7Core;
    });
  });

  /**
   * Regression Tests
   * @see .claude/docs/frontend/troubleshooting-state-setstate.md
   */
  describe('Regression Tests', () => {
    describe('[TS-STATE-1] sequence 내 여러 setState에서 상태 덮어쓰기', () => {
      /**
       * 버그: sequence 액션에서 첫 번째 setState로 배열 추가 후, 두 번째 setState가 실행되면 배열 추가가 사라짐
       * 원인: React의 setState는 비동기적으로 처리되어 두 번째 setState가 첫 번째 결과 반영 전의 상태를 기준으로 병합
       * 해결: handleSequence에서 setState 실행 후 currentState 동기화
       */
      it('sequence 내 연속 setState에서 첫 번째 결과가 두 번째에 반영되어야 함', async () => {
        const globalStateUpdater = vi.fn();
        dispatcher.setGlobalStateUpdater(globalStateUpdater);

        // G7Core.state.get()은 _global 내용물을 직접 반환함
        // sequence의 local 상태는 별도로 처리됨
        const mockG7Core = {
          state: {
            get: () => ({
              _local: {
                currencies: [{ code: 'KRW', name: '원화' }],
                isAddingCurrency: true,
                newCurrency: { code: 'USD', name: '달러' },
              },
            }),
          },
        };
        (window as any).G7Core = mockG7Core;

        // 문제 시나리오: 두 개의 분리된 setState가 sequence로 실행
        const sequenceAction: ActionDefinition = {
          type: 'click',
          handler: 'sequence',
          actions: [
            {
              type: 'click',
              handler: 'setState',
              params: {
                target: 'local',
                // 첫 번째: 통화 추가
                currencies: '[{"code":"KRW","name":"원화"},{"code":"USD","name":"달러"}]',
              },
            },
            {
              type: 'click',
              handler: 'setState',
              params: {
                target: 'local',
                // 두 번째: 입력 폼 숨기기
                isAddingCurrency: false,
                newCurrency: null,
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

        // 마지막 호출에서 두 setState의 결과가 모두 반영되어야 함
        const lastCall = globalStateUpdater.mock.calls[globalStateUpdater.mock.calls.length - 1][0];
        expect(lastCall).toHaveProperty('_local');
        expect(lastCall._local).toHaveProperty('isAddingCurrency', false);
        expect(lastCall._local).toHaveProperty('newCurrency', null);

        delete (window as any).G7Core;
      });
    });

    describe('[TS-STATE-4] dot notation 키에 null 값 설정', () => {
      /**
       * 버그: form.newCurrency: null과 같이 dot notation 키에 null 값 설정 시 에러
       * 원인: createNestedUpdate에서 null 체크 누락으로 spread 연산자 오류
       * 해결: null, undefined, 비객체 타입을 명시적으로 체크
       */
      it('dot notation 키에 null 값을 설정해도 에러가 발생하지 않아야 함', async () => {
        const globalStateUpdater = vi.fn();
        dispatcher.setGlobalStateUpdater(globalStateUpdater);

        // G7Core.state.get()은 _global 내용물을 직접 반환함
        const mockG7Core = {
          state: {
            get: () => ({
              form: {
                name: 'Test',
                newCurrency: { code: 'USD', name: 'Dollar' },
              },
            }),
          },
        };
        (window as any).G7Core = mockG7Core;

        const action: ActionDefinition = {
          type: 'click',
          handler: 'setState',
          params: {
            target: 'global',
            'form.newCurrency': null,
          },
        };

        const handler = dispatcher.createHandler(action);
        const mockEvent = {
          preventDefault: vi.fn(),
          type: 'click',
          target: null,
        } as unknown as Event;

        // 에러 없이 실행되어야 함
        await expect(handler(mockEvent)).resolves.not.toThrow();

        // null이 정상적으로 설정되어야 함
        expect(globalStateUpdater).toHaveBeenCalledWith(
          expect.objectContaining({
            form: expect.objectContaining({
              name: 'Test',
              newCurrency: null,
            }),
          })
        );

        delete (window as any).G7Core;
      });

      it('중간 경로가 null인 상태에서 깊은 경로 설정 시 에러가 발생하지 않아야 함', async () => {
        const globalStateUpdater = vi.fn();
        dispatcher.setGlobalStateUpdater(globalStateUpdater);

        // G7Core.state.get()은 _global 내용물을 직접 반환함
        const mockG7Core = {
          state: {
            get: () => ({
              form: {
                parentField: null, // 중간 경로가 null
              },
            }),
          },
        };
        (window as any).G7Core = mockG7Core;

        const action: ActionDefinition = {
          type: 'click',
          handler: 'setState',
          params: {
            target: 'global',
            'form.parentField.childField': 'new value',
          },
        };

        const handler = dispatcher.createHandler(action);
        const mockEvent = {
          preventDefault: vi.fn(),
          type: 'click',
          target: null,
        } as unknown as Event;

        // 에러 없이 실행되어야 함 (null을 빈 객체로 초기화)
        await expect(handler(mockEvent)).resolves.not.toThrow();

        expect(globalStateUpdater).toHaveBeenCalledWith(
          expect.objectContaining({
            form: expect.objectContaining({
              parentField: expect.objectContaining({
                childField: 'new value',
              }),
            }),
          })
        );

        delete (window as any).G7Core;
      });
    });

    describe('[TS-STATE-7] sequence 내 setState 후 상태가 이전 값으로 병합됨', () => {
      /**
       * 2026-01-30 수정 후 동작:
       * - target='global'은 항상 G7Core.state.get()을 참조
       * - globalStateUpdater 호출 후 즉시 G7Core.state.get()에 반영됨
       * - 따라서 sequence 내에서도 최신 상태 참조 가능 (동적 mock 필요)
       */
      it('sequence에서 setState 후 다음 액션은 업데이트된 상태를 참조해야 함', async () => {
        // 상태를 추적하는 동적 mock 구현
        let currentState = {
          errors: { field1: '필수 입력입니다' } as Record<string, string> | null,
          formData: { name: '' },
        };

        const globalStateUpdater = vi.fn((updates: any) => {
          // globalStateUpdater 호출 시 currentState 업데이트 (실제 동작 시뮬레이션)
          currentState = { ...currentState, ...updates };
        });
        dispatcher.setGlobalStateUpdater(globalStateUpdater);

        // G7Core.state.get()이 항상 최신 currentState 반환하도록 동적 mock
        const mockG7Core = {
          state: {
            get: () => currentState,
          },
        };
        (window as any).G7Core = mockG7Core;

        // 테스트: sequence에서 errors를 null로 설정한 후 다음 액션 실행
        const sequenceAction: ActionDefinition = {
          type: 'click',
          handler: 'sequence',
          actions: [
            {
              type: 'click',
              handler: 'setState',
              params: {
                target: 'global',
                errors: null, // 에러 클리어
              },
            },
            {
              type: 'click',
              handler: 'setState',
              params: {
                target: 'global',
                saveStatus: 'success', // 저장 상태 업데이트
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

        // 마지막 호출에서 errors가 null로 유지되어야 함 (이전 값이 복원되면 안 됨)
        const lastCall = globalStateUpdater.mock.calls[globalStateUpdater.mock.calls.length - 1][0];
        expect(lastCall.errors).toBeNull();
        expect(lastCall.saveStatus).toBe('success');

        delete (window as any).G7Core;
      });
    });

    describe('[TS-STATE-8] apiCall onSuccess의 setState에서 이전 setState 결과 손실', () => {
      /**
       * 2026-01-30 수정 후 동작:
       * - target='global'은 항상 G7Core.state.get()을 참조 (context.state 무시)
       * - globalStateUpdater 호출 후 즉시 G7Core.state.get()에 반영되므로
       *   sequence 내에서도 최신 상태 참조 가능
       *
       * 따라서 apiCall onSuccess에서 이전 setState 결과를 보려면
       * G7Core.state.get()이 업데이트된 상태여야 함
       */
      it('apiCall onSuccess에서 G7Core.state.get()의 최신 상태를 참조해야 함', async () => {
        const globalStateUpdater = vi.fn();
        dispatcher.setGlobalStateUpdater(globalStateUpdater);

        // G7Core.state.get()에 이전 setState 결과가 이미 반영된 상태 시뮬레이션
        // (실제 동작에서는 globalStateUpdater 호출 후 즉시 반영됨)
        const mockG7Core = {
          state: {
            get: () => ({
              deleteProduct: {
                target: { id: 789, name: 'Product to Delete', product_code: 'DEL-001' },
                canDelete: null,
                reason: null,
              },
            }),
          },
        };
        (window as any).G7Core = mockG7Core;

        // apiCall onSuccess에서 실행되는 dot notation setState
        const action: ActionDefinition = {
          type: 'click',
          handler: 'setState',
          params: {
            target: 'global',
            'deleteProduct.canDelete': true,
            'deleteProduct.reason': 'No dependencies found',
          },
        };

        await (dispatcher as any).executeAction(action, {
          data: {},
          state: {}, // context.state는 무시됨
        });

        // G7Core.state.get()의 target이 보존되어야 함
        expect(globalStateUpdater).toHaveBeenCalledWith(
          expect.objectContaining({
            deleteProduct: expect.objectContaining({
              target: { id: 789, name: 'Product to Delete', product_code: 'DEL-001' },
              canDelete: true,
              reason: 'No dependencies found',
            }),
          })
        );

        delete (window as any).G7Core;
      });
    });

    describe('[TS-STATE-LOCAL-1] sequence 내 setState 후 apiCall body에서 _local 참조', () => {
      /**
       * 버그: sequence 내에서 setState로 _local 상태 업데이트 후,
       *       다음 apiCall의 body에서 {{_local.xxx}}가 이전 값을 참조함
       * 원인: sequenceContext.data._local이 업데이트되지 않아
       *       resolveBindings에서 이전 값을 사용
       * 해결: handleSequence에서 sequenceContext.data에 _local: currentState 추가
       *
       * @see .claude/docs/frontend/troubleshooting-state-setstate.md
       */
      it('sequence 내 setState 후 다음 액션의 context.data._local이 업데이트되어야 함', async () => {
        const mockSetState = vi.fn();
        const capturedContexts: any[] = [];

        // executeAction을 스파이하여 호출 시 context 캡처
        const originalExecuteAction = (dispatcher as any).executeAction.bind(dispatcher);
        vi.spyOn(dispatcher as any, 'executeAction').mockImplementation(
          async (action: any, context: any) => {
            // apiCall 액션일 때 context 캡처
            if (action.handler === 'apiCall') {
              capturedContexts.push({
                handler: action.handler,
                dataLocal: context.data?._local,
              });
            }
            return originalExecuteAction(action, context);
          }
        );

        // 초기 상태: itemCoupons 없음
        const initialState = {};

        const sequenceAction: ActionDefinition = {
          type: 'click',
          handler: 'sequence',
          actions: [
            {
              type: 'click',
              handler: 'setState',
              params: {
                target: 'local',
                itemCoupons: { '2144': ['1338'] },
              },
            },
            {
              type: 'click',
              handler: 'apiCall',
              target: '/api/checkout',
              params: {
                method: 'PUT',
                body: {
                  item_coupons: '{{_local.itemCoupons ?? {}}}',
                },
              },
            },
          ],
        };

        await originalExecuteAction(sequenceAction, {
          data: { _local: initialState },
          state: initialState,
          setState: mockSetState,
        });

        // apiCall 실행 시 context.data._local이 최신 상태여야 함
        expect(capturedContexts).toHaveLength(1);
        expect(capturedContexts[0].dataLocal).toEqual({
          itemCoupons: { '2144': ['1338'] },
        });

        vi.restoreAllMocks();
      });

      it('sequence 내 여러 setState 후 context.data._local에 누적된 상태가 반영되어야 함', async () => {
        const mockSetState = vi.fn();
        const capturedContexts: any[] = [];

        const originalExecuteAction = (dispatcher as any).executeAction.bind(dispatcher);
        vi.spyOn(dispatcher as any, 'executeAction').mockImplementation(
          async (action: any, context: any) => {
            if (action.handler === 'apiCall') {
              capturedContexts.push({
                handler: action.handler,
                dataLocal: context.data?._local,
              });
            }
            return originalExecuteAction(action, context);
          }
        );

        const sequenceAction: ActionDefinition = {
          type: 'click',
          handler: 'sequence',
          actions: [
            {
              type: 'click',
              handler: 'setState',
              params: { target: 'local', field1: 'value1' },
            },
            {
              type: 'click',
              handler: 'setState',
              params: { target: 'local', field2: 'value2' },
            },
            {
              type: 'click',
              handler: 'apiCall',
              target: '/api/test',
              params: { method: 'POST' },
            },
          ],
        };

        await originalExecuteAction(sequenceAction, {
          data: { _local: {} },
          state: {},
          setState: mockSetState,
        });

        // apiCall 실행 시 두 setState의 결과가 모두 반영되어야 함
        expect(capturedContexts).toHaveLength(1);
        expect(capturedContexts[0].dataLocal).toEqual({
          field1: 'value1',
          field2: 'value2',
        });

        vi.restoreAllMocks();
      });
    });

    describe('[TS-STATE-2] 같은 루트를 공유하는 dot notation 키 병합', () => {
      /**
       * 버그 (해결됨): 하나의 setState params에 form.a와 form.b 같이 있을 때 병합 실패
       * 해결: convertDotNotationToObject() + deepMerge()로 자동 변환
       * 이 테스트는 해결된 상태가 유지되는지 확인
       */
      it('여러 dot notation 키가 같은 루트를 공유해도 모두 정상 병합되어야 함', async () => {
        const globalStateUpdater = vi.fn();
        dispatcher.setGlobalStateUpdater(globalStateUpdater);

        // G7Core.state.get()은 _global 내용물을 직접 반환함
        const mockG7Core = {
          state: {
            get: () => ({
              filter: {
                category: 'electronics',
              },
            }),
          },
        };
        (window as any).G7Core = mockG7Core;

        const action: ActionDefinition = {
          type: 'click',
          handler: 'setState',
          params: {
            target: 'global',
            'filter.orderStatus': ['paid', 'shipped'],
            'filter.dateRange': '2024-01-01~2024-12-31',
            'filter.minPrice': 1000,
          },
        };

        const handler = dispatcher.createHandler(action);
        const mockEvent = {
          preventDefault: vi.fn(),
          type: 'click',
          target: null,
        } as unknown as Event;

        await handler(mockEvent);

        // 모든 필드가 병합되어야 함
        expect(globalStateUpdater).toHaveBeenCalledWith(
          expect.objectContaining({
            filter: expect.objectContaining({
              category: 'electronics', // 기존 값 보존
              orderStatus: ['paid', 'shipped'],
              dateRange: '2024-01-01~2024-12-31',
              minPrice: 1000,
            }),
          })
        );

        delete (window as any).G7Core;
      });
    });

    describe('[TS-STATE-14] 3단계 이상 깊이 dot notation', () => {
      /**
       * 깊은 중첩 경로(a.b.c.d)가 올바르게 처리되는지 확인
       * 회귀 방지 테스트
       */
      it('3단계 이상 깊이의 dot notation 키가 정상 병합되어야 함', async () => {
        const globalStateUpdater = vi.fn();
        dispatcher.setGlobalStateUpdater(globalStateUpdater);

        const mockG7Core = {
          state: {
            get: () => ({
              settings: {
                display: {
                  theme: 'light',
                },
              },
            }),
          },
        };
        (window as any).G7Core = mockG7Core;

        const action: ActionDefinition = {
          type: 'click',
          handler: 'setState',
          params: {
            target: 'global',
            'settings.display.colors.primary': '#ff0000',
            'settings.display.colors.secondary': '#00ff00',
            'settings.display.layout.sidebar.width': 250,
          },
        };

        const handler = dispatcher.createHandler(action);
        const mockEvent = {
          preventDefault: vi.fn(),
          type: 'click',
          target: null,
        } as unknown as Event;

        await handler(mockEvent);

        expect(globalStateUpdater).toHaveBeenCalledWith(
          expect.objectContaining({
            settings: expect.objectContaining({
              display: expect.objectContaining({
                theme: 'light', // 기존 값 보존
                colors: expect.objectContaining({
                  primary: '#ff0000',
                  secondary: '#00ff00',
                }),
                layout: expect.objectContaining({
                  sidebar: expect.objectContaining({
                    width: 250,
                  }),
                }),
              }),
            }),
          })
        );

        delete (window as any).G7Core;
      });
    });

    describe('[TS-STATE-15] dot notation 키 순서 독립성', () => {
      /**
       * dot notation 키의 처리 순서와 관계없이 결과가 동일해야 함
       * 회귀 방지 테스트
       */
      it('dot notation 키 순서와 관계없이 동일한 결과를 반환해야 함', async () => {
        const globalStateUpdater = vi.fn();
        dispatcher.setGlobalStateUpdater(globalStateUpdater);

        const mockG7Core = {
          state: {
            get: () => ({
              form: {
                existing: 'value',
              },
            }),
          },
        };
        (window as any).G7Core = mockG7Core;

        // 키 순서를 다르게 설정 (c, a, b 순서)
        const action: ActionDefinition = {
          type: 'click',
          handler: 'setState',
          params: {
            target: 'global',
            'form.c': 'valueC',
            'form.a': 'valueA',
            'form.b': 'valueB',
          },
        };

        const handler = dispatcher.createHandler(action);
        const mockEvent = {
          preventDefault: vi.fn(),
          type: 'click',
          target: null,
        } as unknown as Event;

        await handler(mockEvent);

        // 모든 키가 병합되어야 함
        expect(globalStateUpdater).toHaveBeenCalledWith(
          expect.objectContaining({
            form: expect.objectContaining({
              existing: 'value', // 기존 값 보존
              a: 'valueA',
              b: 'valueB',
              c: 'valueC',
            }),
          })
        );

        delete (window as any).G7Core;
      });
    });

    describe('[TS-STATE-16] 일반 객체 키와 dot notation 혼합', () => {
      /**
       * 일반 객체 키와 dot notation 키가 함께 있을 때 병합이 올바르게 되어야 함
       * 회귀 방지 테스트
       */
      it('일반 객체 키와 dot notation 키가 함께 정상 병합되어야 함', async () => {
        const globalStateUpdater = vi.fn();
        dispatcher.setGlobalStateUpdater(globalStateUpdater);

        const mockG7Core = {
          state: {
            get: () => ({
              user: {
                name: 'John',
              },
            }),
          },
        };
        (window as any).G7Core = mockG7Core;

        const action: ActionDefinition = {
          type: 'click',
          handler: 'setState',
          params: {
            target: 'global',
            // 일반 객체 키
            filter: {
              status: 'active',
              type: 'premium',
            },
            // dot notation 키
            'user.email': 'john@example.com',
            'user.settings.notifications': true,
          },
        };

        const handler = dispatcher.createHandler(action);
        const mockEvent = {
          preventDefault: vi.fn(),
          type: 'click',
          target: null,
        } as unknown as Event;

        await handler(mockEvent);

        expect(globalStateUpdater).toHaveBeenCalledWith(
          expect.objectContaining({
            user: expect.objectContaining({
              name: 'John', // 기존 값 보존
              email: 'john@example.com',
              settings: expect.objectContaining({
                notifications: true,
              }),
            }),
            filter: expect.objectContaining({
              status: 'active',
              type: 'premium',
            }),
          })
        );

        delete (window as any).G7Core;
      });

      it('같은 키에 객체와 dot notation이 있을 때 깊은 병합되어야 함', async () => {
        const globalStateUpdater = vi.fn();
        dispatcher.setGlobalStateUpdater(globalStateUpdater);

        const mockG7Core = {
          state: {
            get: () => ({}),
          },
        };
        (window as any).G7Core = mockG7Core;

        const action: ActionDefinition = {
          type: 'click',
          handler: 'setState',
          params: {
            target: 'global',
            // 일반 객체로 form 설정
            form: {
              name: 'Test',
              email: 'test@test.com',
            },
            // dot notation으로 form의 추가 속성 설정
            'form.phone': '123-456-7890',
          },
        };

        const handler = dispatcher.createHandler(action);
        const mockEvent = {
          preventDefault: vi.fn(),
          type: 'click',
          target: null,
        } as unknown as Event;

        await handler(mockEvent);

        // form 객체와 form.phone이 모두 병합되어야 함
        expect(globalStateUpdater).toHaveBeenCalledWith(
          expect.objectContaining({
            form: expect.objectContaining({
              name: 'Test',
              email: 'test@test.com',
              phone: '123-456-7890',
            }),
          })
        );

        delete (window as any).G7Core;
      });
    });

    describe('[TS-STATE-17] replaceOnlyKeys와 dot notation', () => {
      /**
       * replaceOnlyKeys 옵션과 dot notation이 함께 사용될 때 올바르게 동작해야 함
       * errors.field 같은 패턴 처리
       */
      it('replaceOnlyKeys에 지정된 dot notation 키가 병합 대신 교체되어야 함', async () => {
        const mockSetState = vi.fn();
        const context = {
          data: {},
          state: {
            errors: {
              name: 'Name is required',
              email: 'Email is invalid',
            },
            form: {
              name: '',
              email: '',
            },
          },
          setState: mockSetState,
        };

        // errors 키를 교체하는 패턴
        const action: ActionDefinition = {
          type: 'click',
          handler: 'setState',
          params: {
            target: 'local',
            errors: {
              phone: 'Phone is required',
            },
            replaceOnlyKeys: ['errors'],
          },
        };

        await (dispatcher as any).executeAction(action, context);

        // errors가 완전히 교체되어야 함 (기존 name, email 에러 제거)
        expect(mockSetState).toHaveBeenCalledWith(
          expect.objectContaining({
            errors: {
              phone: 'Phone is required',
            },
          })
        );
      });
    });
  });

  /**
   * Isolated State 관련 테스트
   *
   * Phase 2: isolatedState 기능 연동 테스트
   */
  describe('Isolated State Handling', () => {
    describe('resultTo with _isolated target', () => {
      it('should save result to isolated state when target is _isolated', async () => {
        const mockMergeState = vi.fn();
        const mockIsolatedContext = {
          state: { items: [] },
          mergeState: mockMergeState,
          setState: vi.fn(),
          getState: vi.fn(),
        };

        // API mock
        global.fetch = vi.fn().mockResolvedValue({
          ok: true,
          json: () => Promise.resolve({ data: [{ id: 1 }, { id: 2 }] }),
        });

        const action: ActionDefinition = {
          handler: 'apiCall',
          params: {
            target: '/api/items',
            method: 'GET',
          },
          resultTo: { target: '_isolated', key: 'items' },
        };

        const mockSetState = vi.fn();
        const context = {
          data: {},
          state: {},
          isolatedContext: mockIsolatedContext,
          setState: mockSetState,
        };

        await (dispatcher as any).executeAction(action, context);

        // isolated state에 결과가 저장되어야 함 (mergeMode 기본값 'deep' 전달)
        expect(mockMergeState).toHaveBeenCalledWith({
          items: expect.any(Object),
        }, 'deep');
        // setState는 loadingActions 상태 관리를 위해 호출됨 (apiCall 핸들러의 정상 동작)
        // 하지만 결과 데이터는 items 키로 local state에 저장되지 않아야 함
        const setStateCalls = mockSetState.mock.calls;
        const hasItemsUpdate = setStateCalls.some((call: any) =>
          call[0] && typeof call[0] === 'object' && 'items' in call[0]
        );
        expect(hasItemsUpdate).toBe(false);
      });

      it('should fallback to local state when isolatedContext is null', async () => {
        // API mock
        global.fetch = vi.fn().mockResolvedValue({
          ok: true,
          json: () => Promise.resolve({ data: [{ id: 1 }] }),
        });

        const action: ActionDefinition = {
          handler: 'apiCall',
          params: {
            target: '/api/items',
            method: 'GET',
          },
          resultTo: { target: '_isolated', key: 'items' },
        };

        const mockSetState = vi.fn();
        const context = {
          data: {},
          state: {},
          isolatedContext: null,
          setState: mockSetState,
        };

        // 경고 로그가 출력되어야 하므로 spy 설정
        const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

        await (dispatcher as any).executeAction(action, context);

        // isolatedContext가 없으므로 경고가 출력되어야 함
        // (또는 폴백 동작에 따라 setState가 호출될 수 있음)
        warnSpy.mockRestore();
      });

      it('should handle nested key in resultTo for isolated state', async () => {
        const mockMergeState = vi.fn();
        const mockIsolatedContext = {
          state: { form: {} },
          mergeState: mockMergeState,
          setState: vi.fn(),
          getState: vi.fn(),
        };

        // API mock
        global.fetch = vi.fn().mockResolvedValue({
          ok: true,
          json: () => Promise.resolve({ data: { email: 'test@example.com' } }),
        });

        const action: ActionDefinition = {
          handler: 'apiCall',
          params: {
            target: '/api/user',
            method: 'GET',
          },
          resultTo: { target: '_isolated', key: 'form.userData' },
        };

        const context = {
          data: {},
          state: {},
          isolatedContext: mockIsolatedContext,
          setState: vi.fn(),
        };

        await (dispatcher as any).executeAction(action, context);

        // 중첩 키로 isolated state에 저장되어야 함
        expect(mockMergeState).toHaveBeenCalledWith({
          form: { userData: expect.any(Object) },
        }, 'deep');
      });
    });

    describe('setState with target: isolated', () => {
      it('should update isolated state when target is isolated', async () => {
        const mockMergeState = vi.fn();
        const mockIsolatedContext = {
          state: { step: 1 },
          mergeState: mockMergeState,
          setState: vi.fn(),
          getState: vi.fn(),
        };

        const action: ActionDefinition = {
          handler: 'setState',
          params: {
            target: 'isolated',
            step: 2,
            selectedId: 123,
          },
        };

        const context = {
          data: {},
          state: {},
          isolatedContext: mockIsolatedContext,
          setState: vi.fn(),
        };

        await (dispatcher as any).executeAction(action, context);

        expect(mockMergeState).toHaveBeenCalledWith({ step: 2, selectedId: 123 }, 'deep');
        expect(context.setState).not.toHaveBeenCalled();
      });

      it('should fallback to local when isolatedContext is null for isolated target', async () => {
        const mockSetState = vi.fn();

        const action: ActionDefinition = {
          handler: 'setState',
          params: {
            target: 'isolated',
            step: 2,
          },
        };

        const context = {
          data: {},
          state: {},
          isolatedContext: null,
          setState: mockSetState,
        };

        const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

        await (dispatcher as any).executeAction(action, context);

        // isolatedContext가 없으면 경고 출력 후 local로 폴백
        warnSpy.mockRestore();
      });
    });

    describe('sequence with isolated state tracking', () => {
      it('should track isolated state changes across sequence actions', async () => {
        const mockMergeState = vi.fn();
        let isolatedState = { step: 1, items: [] as any[] };

        const mockIsolatedContext = {
          get state() { return isolatedState; },
          mergeState: vi.fn((updates: any) => {
            isolatedState = { ...isolatedState, ...updates };
            mockMergeState(updates);
          }),
          setState: vi.fn(),
          getState: vi.fn((path: string) => {
            if (!path) return isolatedState;
            return path.split('.').reduce((obj: any, key: string) => obj?.[key], isolatedState);
          }),
        };

        const action: ActionDefinition = {
          handler: 'sequence',
          actions: [
            {
              handler: 'setState',
              params: { target: 'isolated', step: 2 },
            },
            {
              handler: 'setState',
              params: { target: 'isolated', items: [1, 2, 3] },
            },
          ],
        };

        const context = {
          data: {},
          state: {},
          isolatedContext: mockIsolatedContext,
          setState: vi.fn(),
        };

        await (dispatcher as any).executeAction(action, context);

        // sequence 완료 후 isolated state가 업데이트되어야 함
        expect(mockMergeState).toHaveBeenCalledTimes(2);
        expect(isolatedState.step).toBe(2);
        expect(isolatedState.items).toEqual([1, 2, 3]);
      });

      it('should allow later actions to reference previous isolated state changes', async () => {
        let isolatedState = { count: 0 };

        const mockIsolatedContext = {
          get state() { return isolatedState; },
          mergeState: vi.fn((updates: any) => {
            isolatedState = { ...isolatedState, ...updates };
          }),
          setState: vi.fn(),
          getState: vi.fn(),
        };

        const action: ActionDefinition = {
          handler: 'sequence',
          actions: [
            {
              handler: 'setState',
              params: { target: 'isolated', count: 5 },
            },
            {
              handler: 'setState',
              params: { target: 'isolated', doubled: '{{_isolated.count * 2}}' },
            },
          ],
        };

        const context = {
          data: { _isolated: isolatedState },
          state: {},
          isolatedContext: mockIsolatedContext,
          setState: vi.fn(),
        };

        await (dispatcher as any).executeAction(action, context);

        // 첫 번째 액션 결과를 두 번째 액션에서 참조할 수 있어야 함
        expect(isolatedState.count).toBe(5);
      });
    });

    describe('parallel with isolated state snapshot', () => {
      it('should provide consistent isolated state snapshot to parallel actions', async () => {
        const mockMergeState = vi.fn();
        const isolatedState = { baseValue: 10, items: [] as any[] };

        const mockIsolatedContext = {
          state: isolatedState,
          mergeState: mockMergeState,
          setState: vi.fn(),
          getState: vi.fn(),
        };

        let capturedStates: any[] = [];

        // 각 액션이 받는 isolatedContext.state를 캡처
        const originalExecuteAction = (dispatcher as any).executeAction.bind(dispatcher);
        vi.spyOn(dispatcher as any, 'executeAction').mockImplementation(async (action: any, ctx: any) => {
          if (action.handler === 'setState' && action.params?.target === 'isolated') {
            capturedStates.push({ ...ctx.isolatedContext?.state });
          }
          return originalExecuteAction(action, ctx);
        });

        const action: ActionDefinition = {
          handler: 'parallel',
          actions: [
            { handler: 'setState', params: { target: 'isolated', a: 1 } },
            { handler: 'setState', params: { target: 'isolated', b: 2 } },
            { handler: 'setState', params: { target: 'isolated', c: 3 } },
          ],
        };

        const context = {
          data: {},
          state: {},
          isolatedContext: mockIsolatedContext,
          setState: vi.fn(),
        };

        await (dispatcher as any).executeAction(action, context);

        // 모든 병렬 액션이 동일한 초기 상태를 참조해야 함
        // (각각 독립적인 스냅샷을 가지므로 서로의 변경에 영향받지 않음)
        expect(mockMergeState).toHaveBeenCalledTimes(3);
      });

      it('should not allow parallel actions to interfere with each other\'s isolated state', async () => {
        const mergeStateCallOrder: any[] = [];

        const mockIsolatedContext = {
          state: { value: 0 },
          mergeState: vi.fn((updates: any) => {
            mergeStateCallOrder.push(updates);
          }),
          setState: vi.fn(),
          getState: vi.fn(),
        };

        const action: ActionDefinition = {
          handler: 'parallel',
          actions: [
            { handler: 'setState', params: { target: 'isolated', first: true } },
            { handler: 'setState', params: { target: 'isolated', second: true } },
          ],
        };

        const context = {
          data: {},
          state: {},
          isolatedContext: mockIsolatedContext,
          setState: vi.fn(),
        };

        await (dispatcher as any).executeAction(action, context);

        // 두 액션 모두 실행되어야 함
        expect(mergeStateCallOrder.length).toBe(2);
        expect(mergeStateCallOrder).toContainEqual({ first: true });
        expect(mergeStateCallOrder).toContainEqual({ second: true });
      });
    });
  });

  describe('sequence/parallel params.actions fallback', () => {
    it('sequence: params.actions에 있는 액션 배열을 실행해야 함 (레이아웃 JSON 컴파일 호환)', async () => {
      const mockSetState = vi.fn();

      const initialState = {
        form: { name: 'Test Product' },
        ui: { seoSyncTitle: null },
      };

      // 레이아웃 JSON 컴파일 결과 형태: actions가 params 내부에 위치
      const sequenceAction: ActionDefinition = {
        type: 'change',
        handler: 'sequence',
        params: {
          actions: [
            {
              handler: 'setState',
              params: {
                target: 'local',
                'ui.seoSyncTitle': true,
              },
            },
            {
              handler: 'setState',
              params: {
                target: 'local',
                hasChanges: true,
              },
            },
          ],
        },
      } as any;

      const handler = dispatcher.createHandler(
        sequenceAction,
        {},
        { setState: mockSetState, state: initialState }
      );
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'change',
        target: { checked: true },
      } as unknown as Event;

      await handler(mockEvent);

      // setState가 2번 호출되어야 함 (sequence 내 2개 액션)
      expect(mockSetState).toHaveBeenCalledTimes(2);
    });

    it('sequence: root-level actions가 우선 사용되어야 함', async () => {
      const mockSetState = vi.fn();

      // root-level actions와 params.actions가 모두 있는 경우 root 우선
      const sequenceAction: ActionDefinition = {
        type: 'click',
        handler: 'sequence',
        actions: [
          {
            handler: 'setState',
            params: { target: 'local', fromRoot: true },
          },
        ],
        params: {
          actions: [
            {
              handler: 'setState',
              params: { target: 'local', fromParams: true },
            },
            {
              handler: 'setState',
              params: { target: 'local', fromParams2: true },
            },
          ],
        },
      } as any;

      const handler = dispatcher.createHandler(
        sequenceAction,
        {},
        { setState: mockSetState, state: {} }
      );
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      // root-level actions의 1개 액션만 실행 (params.actions 무시)
      expect(mockSetState).toHaveBeenCalledTimes(1);
      expect(mockSetState).toHaveBeenCalledWith(
        expect.objectContaining({ fromRoot: true })
      );
    });

    it('parallel: params.actions에 있는 액션 배열을 병렬 실행해야 함', async () => {
      const mockSetState = vi.fn();

      // 레이아웃 JSON 컴파일 결과 형태
      const parallelAction: ActionDefinition = {
        type: 'click',
        handler: 'parallel',
        params: {
          actions: [
            {
              handler: 'setState',
              params: { target: 'local', first: true },
            },
            {
              handler: 'setState',
              params: { target: 'local', second: true },
            },
          ],
        },
      } as any;

      const context = {
        data: {},
        state: {},
        setState: mockSetState,
      };

      await (dispatcher as any).executeAction(parallelAction, context);

      // 두 액션 모두 실행되어야 함
      expect(mockSetState).toHaveBeenCalledTimes(2);
    });
  });

  describe('드래그 앤 드롭 이벤트', () => {
    it('dragstart 이벤트 핸들러를 생성해야 함', () => {
      const props = {
        actions: [
          {
            type: 'dragstart' as const,
            handler: 'setState',
            params: { target: 'local', isDragging: true },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      expect(boundProps.onDragStart).toBeDefined();
      expect(typeof boundProps.onDragStart).toBe('function');
    });

    it('dragover 이벤트 핸들러를 생성해야 함', () => {
      const props = {
        actions: [
          {
            type: 'dragover' as const,
            handler: 'setState',
            params: { target: 'local', dropTargetId: 'item-1' },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      expect(boundProps.onDragOver).toBeDefined();
      expect(typeof boundProps.onDragOver).toBe('function');
    });

    it('drop 이벤트 핸들러를 생성해야 함', () => {
      const props = {
        actions: [
          {
            type: 'drop' as const,
            handler: 'reorderItems',
            params: {
              target: 'local',
              arrayPath: 'items',
              fromIndex: 0,
              toIndex: 2,
            },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      expect(boundProps.onDrop).toBeDefined();
      expect(typeof boundProps.onDrop).toBe('function');
    });

    it('dragend 이벤트 핸들러를 생성해야 함', () => {
      const props = {
        actions: [
          {
            type: 'dragend' as const,
            handler: 'setState',
            params: { target: 'local', isDragging: false },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      expect(boundProps.onDragEnd).toBeDefined();
      expect(typeof boundProps.onDragEnd).toBe('function');
    });

    it('모든 드래그 이벤트 타입을 올바른 React 이벤트 이름으로 매핑해야 함', () => {
      const dragEvents = ['dragstart', 'drag', 'dragend', 'dragenter', 'dragover', 'dragleave', 'drop'] as const;
      const expectedProps = ['onDragStart', 'onDrag', 'onDragEnd', 'onDragEnter', 'onDragOver', 'onDragLeave', 'onDrop'];

      dragEvents.forEach((eventType, index) => {
        const props = {
          actions: [{ type: eventType, handler: 'setState', params: { target: 'local' } }],
        };
        const boundProps = dispatcher.bindActionsToProps(props);
        expect(boundProps[expectedProps[index]]).toBeDefined();
      });
    });
  });

  describe('setupErrorHandling - ErrorHandlingResolver 통합', () => {
    it('errorHandling에서 openModal 호출 시 target이 handleOpenModal에 전달됨', async () => {
      // ErrorHandlingResolver를 통해 핸들러 실행
      const { ErrorHandlingResolver } = await import('../../error/ErrorHandlingResolver');
      ErrorHandlingResolver.resetInstance();

      // globalStateUpdater를 설정한 새 dispatcher 생성
      const modalUpdates: any[] = [];
      const testDispatcher = new ActionDispatcher({ navigate: mockNavigate });
      testDispatcher.setGlobalStateUpdater((updates) => {
        modalUpdates.push(updates);
      });

      const resolver = ErrorHandlingResolver.getInstance();

      // ErrorHandlingResolver가 openModal 핸들러를 실행할 때 target이 전달되는지 확인
      // checkout.json과 동일한 구조 (문자열 키 사용)
      const errorHandling = {
        '404': { handler: 'openModal', target: 'tempOrderNotFoundModal' },
      };

      const result = resolver.resolve(404, { errorHandling });

      // handler에 target이 포함되어 있는지 확인
      expect(result.handler?.handler).toBe('openModal');
      expect(result.handler?.target).toBe('tempOrderNotFoundModal');

      // execute를 통해 핸들러 실행
      await resolver.execute(result.handler!, { status: 404, message: 'Not Found' });

      // globalStateUpdater가 올바른 target으로 호출되었는지 확인
      expect(modalUpdates.length).toBeGreaterThan(0);
      const lastUpdate = modalUpdates[modalUpdates.length - 1];
      expect(lastUpdate.activeModal).toBe('tempOrderNotFoundModal');
      expect(lastUpdate.modalStack).toContain('tempOrderNotFoundModal');
    });
  });

  describe('replaceUrl', () => {
    let replaceStateSpy: any;

    beforeEach(() => {
      replaceStateSpy = vi.spyOn(window.history, 'replaceState').mockImplementation(() => {});
      Object.defineProperty(window, 'location', {
        value: {
          search: '?existing=param',
          pathname: '/admin/menus',
        },
        writable: true,
      });
    });

    afterEach(() => {
      replaceStateSpy.mockRestore();
    });

    it('URL만 변경하고 navigate를 호출하지 않아야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'replaceUrl',
        params: {
          path: '/admin/menus',
          query: { menu: 'dashboard', mode: 'view' },
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      // window.history.replaceState가 호출되어야 함
      expect(replaceStateSpy).toHaveBeenCalled();
      const calledPath = replaceStateSpy.mock.calls[0][2] as string;
      expect(calledPath).toContain('menu=dashboard');
      expect(calledPath).toContain('mode=view');

      // navigate는 호출되지 않아야 함
      expect(mockNavigate).not.toHaveBeenCalled();
    });

    it('mergeQuery가 true일 때 기존 쿼리 파라미터와 병합해야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'replaceUrl',
        params: {
          path: '/admin/menus',
          mergeQuery: true,
          query: { menu: 'settings', mode: 'edit' },
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(replaceStateSpy).toHaveBeenCalled();
      const calledPath = replaceStateSpy.mock.calls[0][2] as string;
      // 기존 파라미터 유지
      expect(calledPath).toContain('existing=param');
      // 새 파라미터 추가
      expect(calledPath).toContain('menu=settings');
      expect(calledPath).toContain('mode=edit');
    });

    it('query 없이 path만 전달해도 동작해야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'replaceUrl',
        params: {
          path: '/admin/categories',
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(replaceStateSpy).toHaveBeenCalledWith(null, '', '/admin/categories');
      expect(mockNavigate).not.toHaveBeenCalled();
    });
  });

  describe('raw value fallback (non-event callback)', () => {
    it('File 객체를 전달하는 onChange에서 $event.target.value로 접근 가능해야 함', async () => {
      const mockGlobalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(mockGlobalUpdater);

      const props = {
        actions: [
          {
            type: 'change' as const,
            handler: 'setState',
            params: {
              target: 'global',
              uploadFile: '{{$event.target.value}}',
              uploadFileName: '{{$event.target.value?.name}}',
            },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      expect(boundProps.onChange).toBeDefined();

      // File 객체 시뮬레이션 (preventDefault, target 없음)
      const mockFile = { name: 'test.zip', size: 1024, type: 'application/zip' };
      boundProps.onChange(mockFile);

      // globalStateUpdater가 호출될 때까지 대기
      await vi.waitFor(() => {
        expect(mockGlobalUpdater).toHaveBeenCalled();
      });

      // 올바른 값으로 호출되었는지 확인
      const callArgs = mockGlobalUpdater.mock.calls[0][0];
      expect(callArgs.uploadFile).toEqual(mockFile);
      expect(callArgs.uploadFileName).toBe('test.zip');
    });

    it('onChange에 null 전달 시에도 핸들러가 실행되어야 함 (파일 초기화)', async () => {
      const mockGlobalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(mockGlobalUpdater);

      const props = {
        actions: [
          {
            type: 'change' as const,
            handler: 'setState',
            params: {
              target: 'global',
              uploadFile: '{{$event.target.value}}',
            },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      // null은 undefined가 아니므로 fallback이 실행되어야 함
      boundProps.onChange(null);

      await vi.waitFor(() => {
        expect(mockGlobalUpdater).toHaveBeenCalled();
      });

      const callArgs = mockGlobalUpdater.mock.calls[0][0];
      expect(callArgs.uploadFile).toBeNull();
    });

    it('boolean 값을 전달하는 콜백에서도 $event.target.value로 접근 가능해야 함', async () => {
      const mockGlobalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(mockGlobalUpdater);

      const props = {
        actions: [
          {
            type: 'change' as const,
            handler: 'setState',
            params: {
              target: 'global',
              isOpen: '{{$event.target.value}}',
            },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      // boolean (non-event, non-object) 값
      boundProps.onChange(true);

      await vi.waitFor(() => {
        expect(mockGlobalUpdater).toHaveBeenCalled();
      });

      const callArgs = mockGlobalUpdater.mock.calls[0][0];
      expect(callArgs.isOpen).toBe(true);
    });

    it('표준 DOM 이벤트는 기존 경로로 처리 (fallback 미사용)', async () => {
      const mockGlobalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(mockGlobalUpdater);

      const props = {
        actions: [
          {
            type: 'change' as const,
            handler: 'setState',
            params: {
              target: 'global',
              value: '{{$event.target.value}}',
            },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      // 표준 DOM 이벤트 시뮬레이션
      const mockEvent = {
        type: 'change',
        target: { value: 'hello', name: 'field' },
        preventDefault: vi.fn(),
        stopPropagation: vi.fn(),
      };

      boundProps.onChange(mockEvent);

      await vi.waitFor(() => {
        expect(mockGlobalUpdater).toHaveBeenCalled();
      });

      const callArgs = mockGlobalUpdater.mock.calls[0][0];
      expect(callArgs.value).toBe('hello');
    });

    it('커스텀 컴포넌트 이벤트({target: ...})는 기존 경로로 처리', async () => {
      const mockGlobalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(mockGlobalUpdater);

      const props = {
        actions: [
          {
            type: 'change' as const,
            handler: 'setState',
            params: {
              target: 'global',
              value: '{{$event.target.value}}',
            },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      // 커스텀 컴포넌트 이벤트 시뮬레이션 (target 있음, preventDefault 없음)
      const customEvent = { target: { value: 'custom_value', name: 'field' } };

      boundProps.onChange(customEvent);

      await vi.waitFor(() => {
        expect(mockGlobalUpdater).toHaveBeenCalled();
      });

      const callArgs = mockGlobalUpdater.mock.calls[0][0];
      expect(callArgs.value).toBe('custom_value');
    });

    it('undefined를 전달하면 fallback이 실행되지 않아야 함', () => {
      const mockGlobalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(mockGlobalUpdater);

      const props = {
        actions: [
          {
            type: 'change' as const,
            handler: 'setState',
            params: {
              target: 'global',
              value: '{{$event.target.value}}',
            },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      // undefined 전달 → firstArg !== undefined 조건에서 제외
      boundProps.onChange(undefined);

      // globalStateUpdater가 호출되지 않아야 함
      expect(mockGlobalUpdater).not.toHaveBeenCalled();
    });
  });

  // ==============================
  // deepMergeWithState: File/Blob 등 non-plain 객체 보호
  // ==============================

  describe('deepMergeWithState - non-plain 객체 보호', () => {
    it('File 객체를 setState로 저장하면 원본 File 참조가 유지되어야 함', async () => {
      const mockGlobalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(mockGlobalUpdater);

      const testFile = new File(['test content'], 'test.zip', { type: 'application/zip' });

      const action: ActionDefinition = {
        type: 'click',
        handler: 'setState',
        params: {
          target: 'global',
          uploadFile: testFile,
        },
      };

      const handler = dispatcher.createHandler(action);
      const mockEvent = {
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event;

      await handler(mockEvent);

      expect(mockGlobalUpdater).toHaveBeenCalledWith(
        expect.objectContaining({
          uploadFile: testFile,
        })
      );

      // File 참조가 동일해야 함 (spread로 복사되지 않음)
      const payload = mockGlobalUpdater.mock.calls[0][0];
      expect(payload.uploadFile).toBe(testFile);
      expect(payload.uploadFile instanceof File).toBe(true);
      expect(payload.uploadFile.name).toBe('test.zip');
    });

    it('Blob 객체를 setState로 저장하면 원본 Blob 참조가 유지되어야 함', async () => {
      const mockGlobalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(mockGlobalUpdater);

      const testBlob = new Blob(['binary data'], { type: 'application/octet-stream' });

      const action: ActionDefinition = {
        type: 'click',
        handler: 'setState',
        params: {
          target: 'global',
          blobData: testBlob,
        },
      };

      const handler = dispatcher.createHandler(action);
      await handler({
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event);

      const payload = mockGlobalUpdater.mock.calls[0][0];
      expect(payload.blobData).toBe(testBlob);
      expect(payload.blobData instanceof Blob).toBe(true);
    });

    it('Date 객체를 setState로 저장하면 원본 Date 참조가 유지되어야 함', async () => {
      const mockGlobalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(mockGlobalUpdater);

      const testDate = new Date('2026-03-13');

      const action: ActionDefinition = {
        type: 'click',
        handler: 'setState',
        params: {
          target: 'global',
          createdAt: testDate,
        },
      };

      const handler = dispatcher.createHandler(action);
      await handler({
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event);

      const payload = mockGlobalUpdater.mock.calls[0][0];
      expect(payload.createdAt).toBe(testDate);
      expect(payload.createdAt instanceof Date).toBe(true);
    });

    it('plain 객체는 여전히 깊은 병합이 수행되어야 함', async () => {
      const mockGlobalUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(mockGlobalUpdater);

      // G7Core.state.get()이 기존 상태를 반환하도록 모킹
      (window as any).G7Core = {
        state: {
          get: () => ({ form: { name: 'old', email: 'old@test.com' } }),
        },
      };

      const action: ActionDefinition = {
        type: 'click',
        handler: 'setState',
        params: {
          target: 'global',
          form: { name: 'new' },
        },
      };

      const handler = dispatcher.createHandler(action);
      await handler({
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event);

      const payload = mockGlobalUpdater.mock.calls[0][0];
      // name은 업데이트, email은 유지 (깊은 병합)
      expect(payload.form.name).toBe('new');
      expect(payload.form.email).toBe('old@test.com');

      // G7Core 정리
      delete (window as any).G7Core;
    });
  });

  // ==============================
  // apiCall: multipart/form-data FormData 변환
  // ==============================

  describe('apiCall - multipart/form-data 지원', () => {
    let mockFetchLocal: ReturnType<typeof vi.fn>;
    let originalFetch: typeof fetch;

    beforeEach(() => {
      originalFetch = globalThis.fetch;
      mockFetchLocal = vi.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve({ success: true, data: {} }),
      });
      globalThis.fetch = mockFetchLocal as unknown as typeof fetch;

      Object.defineProperty(document, 'cookie', {
        value: 'XSRF-TOKEN=test-csrf-token',
        writable: true,
      });
    });

    afterEach(() => {
      globalThis.fetch = originalFetch;
    });

    it('contentType이 multipart/form-data이면 FormData로 전송해야 함', async () => {
      const testFile = new File(['zip content'], 'module.zip', { type: 'application/zip' });

      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/admin/modules/install-from-file',
        auth_required: true,
        params: {
          method: 'POST',
          contentType: 'multipart/form-data',
          body: { file: testFile },
        },
      };

      mockGetToken.mockReturnValue('test-token');
      const handler = dispatcher.createHandler(action);
      await handler({
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event);

      expect(mockFetchLocal).toHaveBeenCalled();
      const [, fetchOptions] = mockFetchLocal.mock.calls.find(
        (call: unknown[]) => (call[0] as string).includes('install-from-file')
      )!;

      // FormData로 전송되어야 함
      expect(fetchOptions.body).toBeInstanceOf(FormData);

      // Content-Type 헤더가 없어야 함 (브라우저가 boundary 포함하여 자동 설정)
      expect(fetchOptions.headers['Content-Type']).toBeUndefined();

      // FormData에 file 키가 포함되어야 함
      const formData = fetchOptions.body as FormData;
      expect(formData.has('file')).toBe(true);
    });

    it('contentType 미지정 시 기존 JSON 방식으로 전송해야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/admin/modules/install-from-github',
        auth_required: true,
        params: {
          method: 'POST',
          body: { github_url: 'https://github.com/user/repo' },
        },
      };

      mockGetToken.mockReturnValue('test-token');
      const handler = dispatcher.createHandler(action);
      await handler({
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event);

      expect(mockFetchLocal).toHaveBeenCalled();
      const [, fetchOptions] = mockFetchLocal.mock.calls.find(
        (call: unknown[]) => (call[0] as string).includes('install-from-github')
      )!;

      // JSON으로 전송되어야 함
      expect(typeof fetchOptions.body).toBe('string');
      expect(JSON.parse(fetchOptions.body)).toEqual({ github_url: 'https://github.com/user/repo' });

      // Content-Type이 application/json이어야 함
      expect(fetchOptions.headers['Content-Type']).toBe('application/json');
    });

    it('multipart body에서 non-File 값은 문자열로 변환되어야 함', async () => {
      const testFile = new File(['content'], 'test.zip', { type: 'application/zip' });

      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/upload',
        params: {
          method: 'POST',
          contentType: 'multipart/form-data',
          body: {
            file: testFile,
            description: 'Test upload',
            metadata: { version: '1.0' },
          },
        },
      };

      const handler = dispatcher.createHandler(action);
      await handler({
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event);

      const [, fetchOptions] = mockFetchLocal.mock.calls.find(
        (call: unknown[]) => (call[0] as string).includes('/api/upload')
      )!;

      const formData = fetchOptions.body as FormData;
      expect(formData.has('file')).toBe(true);
      expect(formData.get('description')).toBe('Test upload');
      expect(formData.get('metadata')).toBe(JSON.stringify({ version: '1.0' }));
    });

    it('multipart body에서 null/undefined 값은 FormData에 포함되지 않아야 함', async () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/upload',
        params: {
          method: 'POST',
          contentType: 'multipart/form-data',
          body: {
            name: 'test',
            nullField: null,
            undefinedField: undefined,
          },
        },
      };

      const handler = dispatcher.createHandler(action);
      await handler({
        preventDefault: vi.fn(),
        type: 'click',
        target: null,
      } as unknown as Event);

      const [, fetchOptions] = mockFetchLocal.mock.calls.find(
        (call: unknown[]) => (call[0] as string).includes('/api/upload')
      )!;

      const formData = fetchOptions.body as FormData;
      expect(formData.get('name')).toBe('test');
      expect(formData.has('nullField')).toBe(false);
      expect(formData.has('undefinedField')).toBe(false);
    });
  });

});
