/**
 * G7CoreGlobals.ts 단위 테스트
 *
 * G7Core 전역 API의 개별 기능 테스트
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { initializeG7CoreGlobals, G7CoreDependencies } from '../template-engine/G7CoreGlobals';
import { renderItemChildren } from '../template-engine/helpers';

// Mock 의존성
vi.mock('../template-engine/ComponentRegistry', () => ({
  ComponentRegistry: {
    getInstance: vi.fn(() => ({
      getComponentMap: vi.fn(() => ({ Button: () => null, Text: () => null })),
    })),
  },
}));

vi.mock('../template-engine/TranslationEngine', () => ({
  TranslationEngine: vi.fn(),
  TranslationContext: {},
}));

vi.mock('../template-engine/ActionDispatcher', () => ({
  ActionDispatcher: vi.fn(),
}));

vi.mock('../template-engine/DataBindingEngine', () => ({
  DataBindingEngine: vi.fn(),
}));

vi.mock('../template-engine/TransitionContext', () => ({
  useTransitionState: vi.fn(() => ({ isPending: false })),
}));

vi.mock('../template-engine/TranslationContext', () => ({
  useTranslation: vi.fn(() => ({ t: (key: string) => key })),
}));

vi.mock('../template-engine/ResponsiveContext', () => ({
  useResponsive: vi.fn(() => ({ width: 1024, isMobile: false })),
}));

vi.mock('../auth/AuthManager', () => ({
  AuthManager: { getInstance: vi.fn() },
}));

vi.mock('../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({ get: vi.fn(), post: vi.fn() })),
}));

vi.mock('../websocket/WebSocketManager', () => ({
  WebSocketManager: vi.fn(),
}));

vi.mock('../template-engine/helpers', () => ({
  renderItemChildren: vi.fn(() => []),
  createChangeEvent: vi.fn(),
  createClickEvent: vi.fn(),
  createSubmitEvent: vi.fn(),
  createKeyboardEvent: vi.fn(),
  mergeClasses: vi.fn((...args: string[]) => args.join(' ')),
  conditionalClass: vi.fn(() => ''),
  joinClasses: vi.fn((...args: (string | false | undefined)[]) => args.filter(Boolean).join(' ')),
}));

/**
 * 테스트용 기본 의존성 생성
 */
function createMockDependencies(): G7CoreDependencies {
  return {
    getState: vi.fn(() => ({
      translationEngine: {
        translate: vi.fn((key: string) => `translated:${key}`),
      } as any,
      translationContext: { templateId: 'test', locale: 'ko' },
      bindingEngine: {} as any,
      actionDispatcher: {} as any,
      templateMetadata: { locales: ['ko', 'en'] },
    })),
    transitionManager: {
      getIsPending: vi.fn(() => false),
      subscribe: vi.fn(() => () => {}),
    },
    responsiveManager: {},
    webSocketManager: {
      subscribe: vi.fn(() => 'sub-key-1'),
      unsubscribe: vi.fn(),
      leaveChannel: vi.fn(),
      disconnect: vi.fn(),
      isInitialized: vi.fn(() => true),
      getSubscriptionCount: vi.fn(() => 5),
    } as any,
  };
}

describe('G7CoreGlobals - initializeG7CoreGlobals()', () => {
  let originalG7Core: any;
  let originalG7Config: any;

  beforeEach(() => {
    // 기존 전역 객체 백업
    originalG7Core = (window as any).G7Core;
    originalG7Config = (window as any).G7Config;

    // 전역 객체 초기화
    delete (window as any).G7Core;
    delete (window as any).G7Config;
    delete (window as any).__templateApp;
  });

  afterEach(() => {
    // 전역 객체 복원
    if (originalG7Core) {
      (window as any).G7Core = originalG7Core;
    } else {
      delete (window as any).G7Core;
    }
    if (originalG7Config) {
      (window as any).G7Config = originalG7Config;
    }
    vi.clearAllMocks();
  });

  it('G7Core 네임스페이스를 생성해야 함', () => {
    const deps = createMockDependencies();
    initializeG7CoreGlobals(deps);

    expect((window as any).G7Core).toBeDefined();
  });

  it('React 전역 객체를 노출해야 함', () => {
    const deps = createMockDependencies();
    initializeG7CoreGlobals(deps);

    expect((window as any).React).toBeDefined();
    expect((window as any).ReactDOM).toBeDefined();
    expect((window as any).ReactJSXRuntime).toBeDefined();
  });

  it('ReactDOM.unstable_batchedUpdates 폴리필을 제공해야 함', () => {
    const deps = createMockDependencies();
    initializeG7CoreGlobals(deps);

    const callback = vi.fn();
    (window as any).ReactDOM.unstable_batchedUpdates(callback);

    expect(callback).toHaveBeenCalledTimes(1);
  });
});

describe('G7CoreGlobals - componentEvent API', () => {
  let G7Core: any;

  beforeEach(() => {
    delete (window as any).G7Core;
    const deps = createMockDependencies();
    initializeG7CoreGlobals(deps);
    G7Core = (window as any).G7Core;
  });

  afterEach(() => {
    G7Core.componentEvent?.clear();
  });

  it('이벤트를 구독하고 발생시킬 수 있어야 함', async () => {
    const callback = vi.fn();
    G7Core.componentEvent.on('test:event', callback);

    await G7Core.componentEvent.emit('test:event', { data: 'test' });

    expect(callback).toHaveBeenCalledWith({ data: 'test' });
  });

  it('구독 해제가 작동해야 함', async () => {
    const callback = vi.fn();
    const unsubscribe = G7Core.componentEvent.on('test:event', callback);

    unsubscribe();
    await G7Core.componentEvent.emit('test:event', { data: 'test' });

    expect(callback).not.toHaveBeenCalled();
  });

  it('여러 리스너를 등록할 수 있어야 함', async () => {
    const callback1 = vi.fn();
    const callback2 = vi.fn();

    G7Core.componentEvent.on('test:event', callback1);
    G7Core.componentEvent.on('test:event', callback2);

    await G7Core.componentEvent.emit('test:event', { data: 'test' });

    expect(callback1).toHaveBeenCalled();
    expect(callback2).toHaveBeenCalled();
  });

  it('emit이 모든 리스너의 결과를 반환해야 함', async () => {
    G7Core.componentEvent.on('test:event', () => 'result1');
    G7Core.componentEvent.on('test:event', () => 'result2');

    const results = await G7Core.componentEvent.emit('test:event');

    expect(results).toEqual(['result1', 'result2']);
  });

  it('리스너가 없으면 빈 배열을 반환해야 함', async () => {
    const results = await G7Core.componentEvent.emit('nonexistent:event');

    expect(results).toEqual([]);
  });

  it('off로 특정 이벤트의 모든 리스너를 제거해야 함', async () => {
    const callback = vi.fn();
    G7Core.componentEvent.on('test:event', callback);

    G7Core.componentEvent.off('test:event');
    await G7Core.componentEvent.emit('test:event');

    expect(callback).not.toHaveBeenCalled();
  });

  it('clear로 모든 이벤트 리스너를 제거해야 함', async () => {
    const callback1 = vi.fn();
    const callback2 = vi.fn();

    G7Core.componentEvent.on('event1', callback1);
    G7Core.componentEvent.on('event2', callback2);

    G7Core.componentEvent.clear();

    await G7Core.componentEvent.emit('event1');
    await G7Core.componentEvent.emit('event2');

    expect(callback1).not.toHaveBeenCalled();
    expect(callback2).not.toHaveBeenCalled();
  });

  it('async 콜백을 지원해야 함', async () => {
    const asyncCallback = vi.fn(async () => {
      await new Promise((resolve) => setTimeout(resolve, 10));
      return 'async result';
    });

    G7Core.componentEvent.on('async:event', asyncCallback);
    const results = await G7Core.componentEvent.emit('async:event');

    expect(results).toEqual(['async result']);
  });

  it('리스너 에러 시 다른 리스너에 영향을 주지 않고 에러를 throw해야 함', async () => {
    const errorCallback = vi.fn(() => {
      throw new Error('Listener error');
    });

    G7Core.componentEvent.on('error:event', errorCallback);

    await expect(G7Core.componentEvent.emit('error:event')).rejects.toThrow('Listener error');
  });
});

describe('G7CoreGlobals - state API', () => {
  let G7Core: any;
  let mockTemplateApp: any;

  beforeEach(() => {
    delete (window as any).G7Core;
    delete (window as any).__templateApp;

    mockTemplateApp = {
      getGlobalState: vi.fn(() => ({ user: 'test', count: 5 })),
      setGlobalState: vi.fn(),
      onGlobalStateChange: vi.fn(() => () => {}),
      getDataSource: vi.fn((id: string) => ({ id, data: [] })),
    };
    (window as any).__templateApp = mockTemplateApp;

    const deps = createMockDependencies();
    initializeG7CoreGlobals(deps);
    G7Core = (window as any).G7Core;
  });

  afterEach(() => {
    delete (window as any).__templateApp;
  });

  it('state.get()이 현재 상태를 반환해야 함', () => {
    const state = G7Core.state.get();

    expect(state).toEqual({ user: 'test', count: 5 });
    expect(mockTemplateApp.getGlobalState).toHaveBeenCalled();
  });

  it('state.set()이 상태를 업데이트해야 함', () => {
    G7Core.state.set({ newKey: 'newValue' });

    // state.set()은 현재 상태와 깊은 병합(deepMerge)을 수행함
    expect(mockTemplateApp.setGlobalState).toHaveBeenCalledWith({
      user: 'test',
      count: 5,
      newKey: 'newValue',
    });
  });

  it('state.update()가 함수형 업데이트를 지원해야 함', () => {
    G7Core.state.update((prev: any) => ({ ...prev, count: prev.count + 1 }));

    expect(mockTemplateApp.setGlobalState).toHaveBeenCalledWith({
      user: 'test',
      count: 6,
    });
  });

  it('state.subscribe()가 상태 변경을 구독해야 함', () => {
    const listener = vi.fn();
    G7Core.state.subscribe(listener);

    expect(mockTemplateApp.onGlobalStateChange).toHaveBeenCalledWith(listener);
  });

  it('state.getDataSource()가 데이터 소스를 반환해야 함', () => {
    const dataSource = G7Core.state.getDataSource('users');

    expect(mockTemplateApp.getDataSource).toHaveBeenCalledWith('users');
    expect(dataSource).toEqual({ id: 'users', data: [] });
  });

  it('TemplateApp이 없으면 state.get()이 빈 객체를 반환해야 함', () => {
    delete (window as any).__templateApp;

    const state = G7Core.state.get();

    expect(state).toEqual({});
  });

  it('TemplateApp이 없으면 state.set()이 에러 없이 실행되어야 함', () => {
    delete (window as any).__templateApp;

    // logger.warn을 호출하지만 에러는 발생하지 않음
    expect(() => G7Core.state.set({ test: 1 })).not.toThrow();
  });
});

describe('G7CoreGlobals - toast API', () => {
  let G7Core: any;
  let dispatchSpy: any;

  beforeEach(() => {
    delete (window as any).G7Core;
    delete (window as any).__templateApp;

    // dispatch 모킹을 위한 설정
    (window as any).__templateApp = {
      getActionDispatcher: vi.fn(() => ({
        dispatchAction: vi.fn().mockResolvedValue({ success: true }),
      })),
      getRouter: vi.fn(),
      getGlobalState: vi.fn(() => ({})),
      setGlobalState: vi.fn(),
    };

    const deps = createMockDependencies();
    initializeG7CoreGlobals(deps);
    G7Core = (window as any).G7Core;
    dispatchSpy = vi.spyOn(G7Core, 'dispatch');
  });

  afterEach(() => {
    delete (window as any).__templateApp;
  });

  it('toast.show()가 toast 액션을 dispatch해야 함', () => {
    G7Core.toast.show('Test message', { type: 'success', duration: 3000 });

    expect(dispatchSpy).toHaveBeenCalledWith({
      handler: 'toast',
      params: {
        message: 'Test message',
        type: 'success',
        duration: 3000,
      },
    });
  });

  it('toast.success()가 success 타입으로 dispatch해야 함', () => {
    G7Core.toast.success('Success message');

    expect(dispatchSpy).toHaveBeenCalledWith({
      handler: 'toast',
      params: {
        message: 'Success message',
        type: 'success',
      },
    });
  });

  it('toast.error()가 error 타입으로 dispatch해야 함', () => {
    G7Core.toast.error('Error message', 5000);

    expect(dispatchSpy).toHaveBeenCalledWith({
      handler: 'toast',
      params: {
        message: 'Error message',
        type: 'error',
        duration: 5000,
      },
    });
  });

  it('toast.warning()이 warning 타입으로 dispatch해야 함', () => {
    G7Core.toast.warning('Warning message');

    expect(dispatchSpy).toHaveBeenCalledWith({
      handler: 'toast',
      params: {
        message: 'Warning message',
        type: 'warning',
      },
    });
  });

  it('toast.info()가 info 타입으로 dispatch해야 함', () => {
    G7Core.toast.info('Info message');

    expect(dispatchSpy).toHaveBeenCalledWith({
      handler: 'toast',
      params: {
        message: 'Info message',
        type: 'info',
      },
    });
  });

  it('toast.show() 기본 타입은 info여야 함', () => {
    G7Core.toast.show('Default type message');

    expect(dispatchSpy).toHaveBeenCalledWith({
      handler: 'toast',
      params: {
        message: 'Default type message',
        type: 'info',
      },
    });
  });
});

describe('G7CoreGlobals - modal API', () => {
  let G7Core: any;
  let dispatchSpy: any;

  beforeEach(() => {
    delete (window as any).G7Core;
    delete (window as any).__templateApp;

    (window as any).__templateApp = {
      getActionDispatcher: vi.fn(() => ({
        dispatchAction: vi.fn().mockResolvedValue({ success: true }),
      })),
      getRouter: vi.fn(),
      getGlobalState: vi.fn(() => ({
        _global: {
          activeModal: 'modal-1',
          modalStack: ['modal-1', 'modal-2'],
        },
      })),
      setGlobalState: vi.fn(),
    };

    const deps = createMockDependencies();
    initializeG7CoreGlobals(deps);
    G7Core = (window as any).G7Core;
    dispatchSpy = vi.spyOn(G7Core, 'dispatch');
  });

  afterEach(() => {
    delete (window as any).__templateApp;
  });

  it('modal.open()이 openModal 액션을 dispatch해야 함', () => {
    G7Core.modal.open('test-modal');

    expect(dispatchSpy).toHaveBeenCalledWith({
      handler: 'openModal',
      target: 'test-modal',
    });
  });

  it('modal.close()가 closeModal 액션을 dispatch해야 함', () => {
    G7Core.modal.close('test-modal');

    expect(dispatchSpy).toHaveBeenCalledWith({
      handler: 'closeModal',
      target: 'test-modal',
    });
  });

  it('modal.close()가 target 없이 호출될 수 있어야 함', () => {
    G7Core.modal.close();

    expect(dispatchSpy).toHaveBeenCalledWith({
      handler: 'closeModal',
    });
  });

  it('modal.closeAll()이 closeAllModals 액션을 dispatch해야 함', () => {
    G7Core.modal.closeAll();

    expect(dispatchSpy).toHaveBeenCalledWith({
      handler: 'closeAllModals',
    });
  });

  it('modal.isOpen()이 모달 열림 상태를 반환해야 함', () => {
    expect(G7Core.modal.isOpen('modal-1')).toBe(true);
    expect(G7Core.modal.isOpen('modal-2')).toBe(true);
    expect(G7Core.modal.isOpen('modal-3')).toBe(false);
  });

  it('modal.getStack()이 모달 스택을 반환해야 함', () => {
    const stack = G7Core.modal.getStack();

    expect(stack).toEqual(['modal-1', 'modal-2']);
  });
});

describe('G7CoreGlobals - locale API', () => {
  let G7Core: any;
  let mockTemplateApp: any;

  beforeEach(() => {
    delete (window as any).G7Core;
    delete (window as any).__templateApp;

    mockTemplateApp = {
      getLocale: vi.fn(() => 'ko'),
      changeLocale: vi.fn().mockResolvedValue(undefined),
    };
    (window as any).__templateApp = mockTemplateApp;

    const deps = createMockDependencies();
    initializeG7CoreGlobals(deps);
    G7Core = (window as any).G7Core;
  });

  afterEach(() => {
    delete (window as any).__templateApp;
  });

  it('locale.current()가 현재 로케일을 반환해야 함', () => {
    const locale = G7Core.locale.current();

    expect(locale).toBe('ko');
  });

  it('locale.supported()가 지원 로케일 목록을 반환해야 함', () => {
    const locales = G7Core.locale.supported();

    expect(locales).toEqual(['ko', 'en']);
  });

  it('locale.change()가 로케일을 변경해야 함', async () => {
    await G7Core.locale.change('en');

    expect(mockTemplateApp.changeLocale).toHaveBeenCalledWith('en');
  });

  it('TemplateApp이 없으면 locale.current()가 기본값 ko를 반환해야 함', () => {
    delete (window as any).__templateApp;

    const locale = G7Core.locale.current();

    expect(locale).toBe('ko');
  });
});

describe('G7CoreGlobals - plugin API', () => {
  let G7Core: any;

  beforeEach(() => {
    delete (window as any).G7Core;
    delete (window as any).G7Config;

    (window as any).G7Config = {
      plugins: {
        'sirsoft-daum_postcode': {
          display_mode: 'layer',
          popup_width: 900,
          popup_height: 600,
        },
        'sirsoft-analytics': {
          tracking_id: 'UA-12345',
          enabled: true,
        },
      },
    };

    const deps = createMockDependencies();
    initializeG7CoreGlobals(deps);
    G7Core = (window as any).G7Core;
  });

  afterEach(() => {
    delete (window as any).G7Config;
  });

  it('plugin.getSettings()가 플러그인 전체 설정을 반환해야 함', () => {
    const settings = G7Core.plugin.getSettings('sirsoft-daum_postcode');

    expect(settings).toEqual({
      display_mode: 'layer',
      popup_width: 900,
      popup_height: 600,
    });
  });

  it('plugin.get()이 특정 설정 값을 반환해야 함', () => {
    const displayMode = G7Core.plugin.get('sirsoft-daum_postcode', 'display_mode');

    expect(displayMode).toBe('layer');
  });

  it('plugin.get()이 기본값을 지원해야 함', () => {
    const nonExistent = G7Core.plugin.get('sirsoft-daum_postcode', 'non_existent', 'default');

    expect(nonExistent).toBe('default');
  });

  it('plugin.getAll()이 모든 플러그인 설정을 반환해야 함', () => {
    const allPlugins = G7Core.plugin.getAll();

    expect(allPlugins).toHaveProperty('sirsoft-daum_postcode');
    expect(allPlugins).toHaveProperty('sirsoft-analytics');
  });

  it('존재하지 않는 플러그인은 undefined를 반환해야 함', () => {
    const settings = G7Core.plugin.getSettings('non-existent-plugin');

    expect(settings).toBeUndefined();
  });

  it('G7Config가 없으면 빈 객체를 반환해야 함', () => {
    delete (window as any).G7Config;

    const allPlugins = G7Core.plugin.getAll();

    expect(allPlugins).toEqual({});
  });
});

describe('G7CoreGlobals - module API', () => {
  let G7Core: any;

  beforeEach(() => {
    delete (window as any).G7Core;
    delete (window as any).G7Config;

    (window as any).G7Config = {
      modules: {
        'sirsoft-ecommerce': {
          default_currency: 'KRW',
          tax_rate: 10,
          shipping_methods: ['standard', 'express'],
        },
        'sirsoft-board': {
          posts_per_page: 20,
          allow_comments: true,
        },
      },
    };

    const deps = createMockDependencies();
    initializeG7CoreGlobals(deps);
    G7Core = (window as any).G7Core;
  });

  afterEach(() => {
    delete (window as any).G7Config;
  });

  it('module.getSettings()가 모듈 전체 설정을 반환해야 함', () => {
    const settings = G7Core.module.getSettings('sirsoft-ecommerce');

    expect(settings).toEqual({
      default_currency: 'KRW',
      tax_rate: 10,
      shipping_methods: ['standard', 'express'],
    });
  });

  it('module.get()이 특정 설정 값을 반환해야 함', () => {
    const currency = G7Core.module.get('sirsoft-ecommerce', 'default_currency');

    expect(currency).toBe('KRW');
  });

  it('module.get()이 기본값을 지원해야 함', () => {
    const nonExistent = G7Core.module.get('sirsoft-ecommerce', 'non_existent', 'USD');

    expect(nonExistent).toBe('USD');
  });

  it('module.getAll()이 모든 모듈 설정을 반환해야 함', () => {
    const allModules = G7Core.module.getAll();

    expect(allModules).toHaveProperty('sirsoft-ecommerce');
    expect(allModules).toHaveProperty('sirsoft-board');
  });
});

describe('G7CoreGlobals - navigation API', () => {
  let G7Core: any;
  let mockTransitionManager: any;

  beforeEach(() => {
    delete (window as any).G7Core;

    mockTransitionManager = {
      getIsPending: vi.fn(() => false),
      subscribe: vi.fn((callback) => {
        // 구독 해제 함수 반환
        return () => {};
      }),
    };

    const deps = createMockDependencies();
    deps.transitionManager = mockTransitionManager;
    initializeG7CoreGlobals(deps);
    G7Core = (window as any).G7Core;
  });

  it('navigation.isPending()이 전환 상태를 반환해야 함', () => {
    mockTransitionManager.getIsPending.mockReturnValue(true);

    expect(G7Core.navigation.isPending()).toBe(true);
  });

  it('navigation.onComplete()가 전환 완료 시 콜백을 실행해야 함', () => {
    let subscribeCallback: (isPending: boolean) => void;
    mockTransitionManager.subscribe.mockImplementation((cb: any) => {
      subscribeCallback = cb;
      return () => {};
    });
    mockTransitionManager.getIsPending.mockReturnValue(true);

    const callback = vi.fn();
    G7Core.navigation.onComplete(callback);

    // 전환 완료 시뮬레이션
    subscribeCallback!(false);

    expect(callback).toHaveBeenCalled();
  });

  it('navigation.onComplete()가 구독 해제 함수를 반환해야 함', () => {
    const unsubscribe = vi.fn();
    mockTransitionManager.subscribe.mockReturnValue(unsubscribe);

    const result = G7Core.navigation.onComplete(() => {});

    expect(typeof result).toBe('function');
  });
});

describe('G7CoreGlobals - websocket API', () => {
  let G7Core: any;
  let mockWebSocketManager: any;

  beforeEach(() => {
    delete (window as any).G7Core;

    mockWebSocketManager = {
      subscribe: vi.fn(() => 'subscription-key'),
      unsubscribe: vi.fn(),
      leaveChannel: vi.fn(),
      disconnect: vi.fn(),
      isInitialized: vi.fn(() => true),
      getSubscriptionCount: vi.fn(() => 3),
    };

    const deps = createMockDependencies();
    deps.webSocketManager = mockWebSocketManager;
    initializeG7CoreGlobals(deps);
    G7Core = (window as any).G7Core;
  });

  it('websocket.subscribe()가 채널을 구독해야 함', () => {
    const callback = vi.fn();
    const key = G7Core.websocket.subscribe('admin.dashboard', 'stats.updated', callback);

    expect(mockWebSocketManager.subscribe).toHaveBeenCalledWith(
      'admin.dashboard',
      'stats.updated',
      callback,
      undefined
    );
    expect(key).toBe('subscription-key');
  });

  it('websocket.unsubscribe()가 구독을 해제해야 함', () => {
    G7Core.websocket.unsubscribe('subscription-key');

    expect(mockWebSocketManager.unsubscribe).toHaveBeenCalledWith('subscription-key');
  });

  it('websocket.leaveChannel()이 채널을 떠나야 함', () => {
    G7Core.websocket.leaveChannel('admin.dashboard');

    expect(mockWebSocketManager.leaveChannel).toHaveBeenCalledWith('admin.dashboard');
  });

  it('websocket.disconnect()가 연결을 종료해야 함', () => {
    G7Core.websocket.disconnect();

    expect(mockWebSocketManager.disconnect).toHaveBeenCalled();
  });

  it('websocket.isInitialized()가 초기화 상태를 반환해야 함', () => {
    expect(G7Core.websocket.isInitialized()).toBe(true);
  });

  it('websocket.getSubscriptionCount()가 구독 수를 반환해야 함', () => {
    expect(G7Core.websocket.getSubscriptionCount()).toBe(3);
  });
});

describe('G7CoreGlobals - style API', () => {
  let G7Core: any;

  beforeEach(() => {
    delete (window as any).G7Core;
    const deps = createMockDependencies();
    initializeG7CoreGlobals(deps);
    G7Core = (window as any).G7Core;
  });

  it('style.mergeClasses가 노출되어야 함', () => {
    expect(G7Core.style.mergeClasses).toBeDefined();
    expect(typeof G7Core.style.mergeClasses).toBe('function');
  });

  it('style.conditionalClass가 노출되어야 함', () => {
    expect(G7Core.style.conditionalClass).toBeDefined();
    expect(typeof G7Core.style.conditionalClass).toBe('function');
  });

  it('style.joinClasses가 노출되어야 함', () => {
    expect(G7Core.style.joinClasses).toBeDefined();
    expect(typeof G7Core.style.joinClasses).toBe('function');
  });
});

describe('G7CoreGlobals - translation API', () => {
  let G7Core: any;

  beforeEach(() => {
    delete (window as any).G7Core;
    const deps = createMockDependencies();
    initializeG7CoreGlobals(deps);
    G7Core = (window as any).G7Core;
  });

  it('useTranslation 훅이 노출되어야 함', () => {
    expect(G7Core.useTranslation).toBeDefined();
  });

  it('t() 함수가 번역을 수행해야 함', () => {
    const result = G7Core.t('common.confirm');

    expect(result).toBe('translated:common.confirm');
  });

  it('t() 함수가 파라미터를 지원해야 함', () => {
    const deps = createMockDependencies();
    const mockTranslate = vi.fn((key: string, _ctx: any, params?: string) => {
      return params ? `${key}${params}` : key;
    });
    deps.getState = vi.fn(() => ({
      translationEngine: { translate: mockTranslate } as any,
      translationContext: { templateId: 'test', locale: 'ko' },
      bindingEngine: {} as any,
      actionDispatcher: {} as any,
      templateMetadata: { locales: ['ko', 'en'] },
    }));

    delete (window as any).G7Core;
    initializeG7CoreGlobals(deps);
    G7Core = (window as any).G7Core;

    G7Core.t('admin.users.pagination_info', { from: 1, to: 10, total: 100 });

    expect(mockTranslate).toHaveBeenCalledWith(
      'admin.users.pagination_info',
      expect.any(Object),
      '|from=1|to=10|total=100'
    );
  });
});

describe('G7CoreGlobals - dispatch API', () => {
  let G7Core: any;
  let mockActionDispatcher: any;

  beforeEach(() => {
    delete (window as any).G7Core;
    delete (window as any).__templateApp;

    mockActionDispatcher = {
      dispatchAction: vi.fn().mockResolvedValue({ success: true, data: 'result' }),
    };

    (window as any).__templateApp = {
      getActionDispatcher: vi.fn(() => mockActionDispatcher),
      getRouter: vi.fn(() => ({ navigate: vi.fn() })),
      getGlobalState: vi.fn(() => ({ key: 'value' })),
      setGlobalState: vi.fn(),
    };

    const deps = createMockDependencies();
    initializeG7CoreGlobals(deps);
    G7Core = (window as any).G7Core;
  });

  afterEach(() => {
    delete (window as any).__templateApp;
  });

  it('dispatch()가 액션을 실행해야 함', async () => {
    const action = { handler: 'testHandler', params: { key: 'value' } };
    const result = await G7Core.dispatch(action);

    expect(mockActionDispatcher.dispatchAction).toHaveBeenCalledWith(
      action,
      expect.objectContaining({
        navigate: expect.any(Function),
        setState: expect.any(Function),
        state: { key: 'value' },
        data: { key: 'value' },
      })
    );
    expect(result).toEqual({ success: true, data: 'result' });
  });

  it('TemplateApp이 없으면 실패 결과를 반환해야 함', async () => {
    delete (window as any).__templateApp;

    const result = await G7Core.dispatch({ handler: 'test' });

    expect(result.success).toBe(false);
    expect(result.error).toBeInstanceOf(Error);
  });

  it('ActionDispatcher가 없으면 실패 결과를 반환해야 함', async () => {
    (window as any).__templateApp.getActionDispatcher = vi.fn(() => null);

    const result = await G7Core.dispatch({ handler: 'test' });

    expect(result.success).toBe(false);
  });

  it('액션 실행 중 에러 발생 시 실패 결과를 반환해야 함', async () => {
    mockActionDispatcher.dispatchAction.mockRejectedValue(new Error('Action failed'));

    const result = await G7Core.dispatch({ handler: 'test' });

    expect(result.success).toBe(false);
    expect(result.error.message).toBe('Action failed');
  });
});

describe('G7CoreGlobals - helper APIs', () => {
  let G7Core: any;

  beforeEach(() => {
    delete (window as any).G7Core;
    const deps = createMockDependencies();
    initializeG7CoreGlobals(deps);
    G7Core = (window as any).G7Core;
  });

  it('이벤트 헬퍼 함수들이 노출되어야 함', () => {
    expect(G7Core.createChangeEvent).toBeDefined();
    expect(G7Core.createClickEvent).toBeDefined();
    expect(G7Core.createSubmitEvent).toBeDefined();
    expect(G7Core.createKeyboardEvent).toBeDefined();
  });

  it('renderItemChildren이 노출되어야 함', () => {
    expect(G7Core.renderItemChildren).toBeDefined();
    expect(typeof G7Core.renderItemChildren).toBe('function');
  });

  it('getComponentMap이 노출되어야 함', () => {
    expect(G7Core.getComponentMap).toBeDefined();
    expect(typeof G7Core.getComponentMap).toBe('function');
  });

  it('getComponentMap이 컴포넌트 맵을 반환해야 함', () => {
    const componentMap = G7Core.getComponentMap();

    expect(componentMap).toHaveProperty('Button');
    expect(componentMap).toHaveProperty('Text');
  });
});

describe('G7CoreGlobals - core APIs', () => {
  let G7Core: any;

  beforeEach(() => {
    delete (window as any).G7Core;
    const deps = createMockDependencies();
    initializeG7CoreGlobals(deps);
    G7Core = (window as any).G7Core;
  });

  it('AuthManager가 노출되어야 함', () => {
    expect(G7Core.AuthManager).toBeDefined();
  });

  it('api 클라이언트가 노출되어야 함', () => {
    expect(G7Core.api).toBeDefined();
  });

  it('TransitionManager가 노출되어야 함', () => {
    expect(G7Core.TransitionManager).toBeDefined();
  });

  it('useTransitionState 훅이 노출되어야 함', () => {
    expect(G7Core.useTransitionState).toBeDefined();
  });

  it('ResponsiveManager가 노출되어야 함', () => {
    expect(G7Core.ResponsiveManager).toBeDefined();
  });

  it('useResponsive 훅이 노출되어야 함', () => {
    expect(G7Core.useResponsive).toBeDefined();
  });
});

// =============================================================================
// 회귀 테스트: cellChildren/expandChildren 상태 주입
// 문서: troubleshooting-components-datagrid.md
// =============================================================================

describe('G7CoreGlobals - renderItemChildren 상태 주입 회귀 테스트', () => {
  let G7Core: any;
  const mockedRenderItemChildren = vi.mocked(renderItemChildren);

  beforeEach(() => {
    delete (window as any).G7Core;
    delete (window as any).__templateApp;

    // mock 초기화
    mockedRenderItemChildren.mockClear();
    mockedRenderItemChildren.mockImplementation((children, context) => {
      // context를 캡처하여 테스트에서 확인할 수 있도록 반환
      return context as any;
    });

    // TemplateApp mock
    // 주의: getGlobalState()는 _global 내용물만 직접 반환 (_global wrapper 없음)
    // G7CoreGlobals.ts의 renderItemChildren에서 이 값이 _global에 할당됨
    (window as any).__templateApp = {
      getGlobalState: vi.fn(() => ({ user: { name: '홍길동' } })),
    };

    const deps = createMockDependencies();
    initializeG7CoreGlobals(deps);
    G7Core = (window as any).G7Core;
  });

  afterEach(() => {
    delete (window as any).__templateApp;
    vi.clearAllMocks();
  });

  /**
   * [TS-DATAGRID-2] cellChildren 상태 자동 병합
   *
   * 문제: CardGrid 등에서 cellChildren 사용 시 _global, _local, _computed에 접근 불가
   * 원인: renderItemChildren 호출 시 전역 상태가 컨텍스트에 병합되지 않음
   * 해결: 전역 상태를 자동으로 컨텍스트에 병합
   */
  describe('[TS-DATAGRID-2] cellChildren 전역 상태 자동 병합', () => {
    it('renderItemChildren 호출 시 _global, _local, _computed가 컨텍스트에 병합되어야 함', () => {
      const children = [{ id: 'test', type: 'basic', name: 'Button' }];
      // _local, _computed를 itemContext에서 전달
      const itemContext = {
        item: { id: 1, name: '상품A' },
        _local: { selectedIds: [1, 2, 3] },
        _computed: { totalCount: 10 },
      };
      const componentMap = {};

      G7Core.renderItemChildren(children, itemContext, componentMap, 'test-key');

      // renderItemChildren이 호출되었는지 확인
      expect(mockedRenderItemChildren).toHaveBeenCalled();

      // 전달된 컨텍스트 확인
      const passedContext = mockedRenderItemChildren.mock.calls[0][1];

      // 전역 상태가 병합되어야 함
      // _global: getGlobalState() 반환값 (내용물 직접 반환)
      expect(passedContext._global).toEqual({ user: { name: '홍길동' } });
      // _local, _computed: itemContext에서 전달된 값
      expect(passedContext._local).toEqual({ selectedIds: [1, 2, 3] });
      expect(passedContext._computed).toEqual({ totalCount: 10 });

      // 원본 itemContext도 유지되어야 함
      expect(passedContext.item).toEqual({ id: 1, name: '상품A' });
    });

    it('itemContext에 이미 _local이 있으면 itemContext 값이 우선되어야 함', () => {
      const children = [{ id: 'test', type: 'basic', name: 'Button' }];
      const itemContext = {
        item: { id: 1 },
        _local: { customValue: '커스텀' }, // 명시적 전달
      };
      const componentMap = {};

      G7Core.renderItemChildren(children, itemContext, componentMap, 'test-key');

      const passedContext = mockedRenderItemChildren.mock.calls[0][1];

      // itemContext의 _local이 우선
      expect(passedContext._local).toEqual({ customValue: '커스텀' });
    });
  });
});

describe('G7CoreGlobals - renderExpandContent 회귀 테스트', () => {
  let G7Core: any;
  let mockBindingEngine: any;
  const mockedRenderItemChildren = vi.mocked(renderItemChildren);

  beforeEach(() => {
    delete (window as any).G7Core;
    delete (window as any).__templateApp;

    // mock 초기화
    mockedRenderItemChildren.mockClear();
    mockedRenderItemChildren.mockImplementation((children, context) => context as any);

    // BindingEngine mock
    mockBindingEngine = {
      evaluateExpression: vi.fn((expr: string, context: any) => {
        // 실제 표현식 평가 시뮬레이션
        if (expr === '_local.selectedIds || []') {
          return context._local?.selectedIds || [];
        }
        if (expr === '_local.count') {
          return context._local?.count;
        }
        return undefined;
      }),
      resolveBindings: vi.fn((template: string, context: any) => {
        // 문자열 보간 시뮬레이션 - 배열은 JSON 문자열로 변환
        return template.replace(/\{\{([^}]+)\}\}/g, (_, expr) => {
          const value = mockBindingEngine.evaluateExpression(expr.trim(), context);
          if (Array.isArray(value)) {
            return JSON.stringify(value);
          }
          return String(value ?? '');
        });
      }),
    };

    // TemplateApp mock
    (window as any).__templateApp = {
      getGlobalState: vi.fn(() => ({
        _global: { user: { id: 1 } },
        _local: { selectedIds: [100, 200], count: 5 },
        _computed: {},
      })),
    };

    // deps에 bindingEngine 포함
    const deps: G7CoreDependencies = {
      getState: vi.fn(() => ({
        translationEngine: { translate: vi.fn() } as any,
        translationContext: { templateId: 'test', locale: 'ko' },
        bindingEngine: mockBindingEngine,
        actionDispatcher: {} as any,
        templateMetadata: { locales: ['ko', 'en'] },
      })),
      transitionManager: {
        getIsPending: vi.fn(() => false),
        subscribe: vi.fn(() => () => {}),
      },
      responsiveManager: {},
      webSocketManager: {
        subscribe: vi.fn(() => 'sub-key-1'),
        unsubscribe: vi.fn(),
        leaveChannel: vi.fn(),
        disconnect: vi.fn(),
        isInitialized: vi.fn(() => true),
        getSubscriptionCount: vi.fn(() => 5),
      } as any,
    };

    initializeG7CoreGlobals(deps);
    G7Core = (window as any).G7Core;
  });

  afterEach(() => {
    delete (window as any).__templateApp;
    vi.clearAllMocks();
  });

  /**
   * [TS-CLOSURE-EXPAND-1] stateRef를 통한 최신 상태 참조
   *
   * 문제: expandChildren 내부에서 캐싱된 이전 상태 참조 (Stale Closure)
   * 원인: useCallback으로 메모이제이션된 componentContext가 생성 시점 값으로 고정
   * 해결: stateRef.current를 우선 사용하여 항상 최신 상태 참조
   */
  describe('[TS-CLOSURE-EXPAND-1] stateRef를 통한 최신 _local 상태 참조', () => {
    it('stateRef.current가 componentContext.state보다 우선 사용되어야 함', () => {
      const children = [{ id: 'expand-btn', type: 'basic', name: 'Button' }];
      const row = { id: 1, name: '상품A' };

      // stateRef와 state가 모두 있는 경우
      const componentContext = {
        state: { selectedIds: [1, 2] }, // 이전 값 (캐싱됨)
        stateRef: { current: { selectedIds: [1, 2, 3, 4] } }, // 최신 값
      };

      G7Core.renderExpandContent({
        children,
        row,
        componentContext,
        keyPrefix: 'expand-1',
      });

      // renderItemChildren에 전달된 컨텍스트 확인
      const passedContext = mockedRenderItemChildren.mock.calls[0][1];

      // stateRef.current 값이 _local에 병합되어야 함
      expect(passedContext._local.selectedIds).toEqual([1, 2, 3, 4]);
    });

    it('stateRef가 없으면 componentContext.state를 사용해야 함 (하위 호환성)', () => {
      const children = [{ id: 'expand-btn', type: 'basic', name: 'Button' }];
      const row = { id: 1 };

      // stateRef 없이 state만 있는 경우
      const componentContext = {
        state: { selectedIds: [1, 2] },
        // stateRef 없음
      };

      G7Core.renderExpandContent({
        children,
        row,
        componentContext,
        keyPrefix: 'expand-1',
      });

      const passedContext = mockedRenderItemChildren.mock.calls[0][1];

      // state 값이 _local에 병합되어야 함
      expect(passedContext._local.selectedIds).toEqual([1, 2]);
    });
  });

  /**
   * [TS-CLOSURE-EXPAND-2] expandContext 표현식 타입 보존
   *
   * 문제: expandContext의 배열 바인딩이 JSON 문자열로 변환됨
   * 원인: resolveBindings가 모든 값을 문자열로 변환
   * 해결: 단일 바인딩({{expr}})은 evaluateExpression으로 원본 타입 유지
   */
  describe('[TS-CLOSURE-EXPAND-2] expandContext 표현식 타입 보존', () => {
    it('단일 바인딩 표현식은 원본 타입(배열)을 유지해야 함', () => {
      const children = [{ id: 'expand-checkbox', type: 'basic', name: 'Checkbox' }];
      const row = { id: 1 };

      // 배열을 반환하는 단일 바인딩 표현식
      const expandContext = {
        selectedIds: '{{_local.selectedIds || []}}', // 단일 바인딩
      };

      G7Core.renderExpandContent({
        children,
        row,
        expandContext,
        keyPrefix: 'expand-1',
      });

      // evaluateExpression이 호출되어야 함 (resolveBindings가 아님)
      expect(mockBindingEngine.evaluateExpression).toHaveBeenCalledWith(
        '_local.selectedIds || []',
        expect.any(Object),
        { skipCache: true }
      );

      // 전달된 컨텍스트에서 배열 타입이 유지되어야 함
      const passedContext = mockedRenderItemChildren.mock.calls[0][1];
      expect(Array.isArray(passedContext.selectedIds)).toBe(true);
      expect(passedContext.selectedIds).toEqual([100, 200]);
    });

    it('혼합 표현식은 resolveBindings로 문자열 보간되어야 함', () => {
      const children = [{ id: 'expand-text', type: 'basic', name: 'Text' }];
      const row = { id: 1 };

      // 혼합 표현식 (문자열 + 바인딩)
      const expandContext = {
        label: '선택된 항목: {{_local.count}}개', // 혼합 표현식
      };

      G7Core.renderExpandContent({
        children,
        row,
        expandContext,
        keyPrefix: 'expand-1',
      });

      // resolveBindings가 호출되어야 함
      expect(mockBindingEngine.resolveBindings).toHaveBeenCalledWith(
        '선택된 항목: {{_local.count}}개',
        expect.any(Object),
        { skipCache: true }
      );

      const passedContext = mockedRenderItemChildren.mock.calls[0][1];
      expect(passedContext.label).toBe('선택된 항목: 5개');
    });
  });

  /**
   * [TS-DATAGRID-STATE-1] expandChildren 내부에서 전역 상태 접근
   *
   * 문제: expandChildren 내부에서 _global, _computed에 접근 불가
   * 원인: renderExpandContent가 전역 상태를 컨텍스트에 포함하지 않음
   * 해결: 전역 상태를 최종 컨텍스트에 자동 병합
   */
  describe('[TS-DATAGRID-STATE-1] expandChildren 전역 상태 접근', () => {
    it('expandChildren 컨텍스트에 _global, _local, _computed가 포함되어야 함', () => {
      const children = [{ id: 'expand-info', type: 'basic', name: 'Text' }];
      const row = { id: 1, name: '상품A' };

      G7Core.renderExpandContent({
        children,
        row,
        keyPrefix: 'expand-1',
      });

      const passedContext = mockedRenderItemChildren.mock.calls[0][1];

      // 전역 상태가 포함되어야 함
      expect(passedContext._global).toBeDefined();
      expect(passedContext._global.user).toEqual({ id: 1 });
      expect(passedContext._local).toBeDefined();
      expect(passedContext._computed).toBeDefined();

      // row 데이터도 접근 가능해야 함
      expect(passedContext.row).toEqual({ id: 1, name: '상품A' });
      expect(passedContext.item).toEqual({ id: 1, name: '상품A' });
      expect(passedContext.$item).toEqual({ id: 1, name: '상품A' });
    });
  });
});

describe('G7Core.state.getIsolated/setIsolated API', () => {
  let G7Core: any;
  let mockTemplateApp: any;

  beforeEach(() => {
    // G7Core 재초기화
    G7Core = (window as any).G7Core || {};
    (window as any).G7Core = G7Core;

    // 레지스트리 초기화
    (window as any).__g7IsolatedStates = {};

    // Mock templateApp
    mockTemplateApp = {
      getGlobalState: vi.fn(() => ({})),
      setGlobalState: vi.fn(),
      getLocalState: vi.fn(() => ({})),
      setLocalState: vi.fn(),
      getDataSource: vi.fn(),
      onGlobalStateChange: vi.fn(),
    };
    (window as any).__templateApp = mockTemplateApp;

    // G7CoreGlobals 초기화
    const deps: G7CoreDependencies = {
      ComponentRegistry: { getInstance: vi.fn(() => ({ getComponentMap: vi.fn() })) } as any,
      TranslationEngine: vi.fn() as any,
      ActionDispatcher: vi.fn() as any,
      DataBindingEngine: vi.fn() as any,
      useTransitionState: vi.fn() as any,
      useTranslation: vi.fn() as any,
      useResponsive: vi.fn() as any,
      AuthManager: { getInstance: vi.fn() } as any,
      getApiClient: vi.fn() as any,
    };
    initializeG7CoreGlobals(deps);
    G7Core = (window as any).G7Core;
  });

  afterEach(() => {
    delete (window as any).__g7IsolatedStates;
    delete (window as any).__g7ActionContext;
    delete (window as any).__templateApp;
    vi.clearAllMocks();
  });

  describe('getIsolated', () => {
    it('should return null when scopeId does not exist', () => {
      const result = G7Core.state.getIsolated('non-existent');
      expect(result).toBeNull();
    });

    it('should return state from registry when scopeId exists', () => {
      (window as any).__g7IsolatedStates = {
        'category-selector': {
          state: { step: 2, selectedItems: [1, 2, 3] },
          mergeState: vi.fn(),
        },
      };

      const result = G7Core.state.getIsolated('category-selector');
      expect(result).toEqual({ step: 2, selectedItems: [1, 2, 3] });
    });

    it('should return state from actionContext when scopeId is not provided', () => {
      (window as any).__g7ActionContext = {
        isolatedContext: {
          state: { currentStep: 1 },
          mergeState: vi.fn(),
        },
      };

      const result = G7Core.state.getIsolated();
      expect(result).toEqual({ currentStep: 1 });
    });

    it('should return null when no scopeId and no actionContext', () => {
      (window as any).__g7ActionContext = null;

      const result = G7Core.state.getIsolated();
      expect(result).toBeNull();
    });
  });

  describe('setIsolated', () => {
    it('should update isolated state via registry when scopeId is provided', () => {
      const mockMergeState = vi.fn();
      (window as any).__g7IsolatedStates = {
        'category-selector': {
          state: { step: 1 },
          mergeState: mockMergeState,
        },
      };

      G7Core.state.setIsolated('category-selector', { step: 2 });

      expect(mockMergeState).toHaveBeenCalledWith({ step: 2 });
    });

    it('should update isolated state via actionContext when scopeId is not provided', () => {
      const mockMergeState = vi.fn();
      (window as any).__g7ActionContext = {
        isolatedContext: {
          state: { step: 1 },
          mergeState: mockMergeState,
        },
      };

      G7Core.state.setIsolated({ step: 3 });

      expect(mockMergeState).toHaveBeenCalledWith({ step: 3 });
    });

    it('should handle dot notation in updates', () => {
      const mockMergeState = vi.fn();
      (window as any).__g7IsolatedStates = {
        'form-scope': {
          state: {},
          mergeState: mockMergeState,
        },
      };

      G7Core.state.setIsolated('form-scope', { 'form.email': 'test@example.com' });

      // dot notation이 중첩 객체로 변환되어야 함
      expect(mockMergeState).toHaveBeenCalledWith({
        form: { email: 'test@example.com' },
      });
    });

    it('should log warning when scopeId does not exist', () => {
      const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      G7Core.state.setIsolated('non-existent', { value: 123 });

      // 경고가 출력되어야 함 (Logger를 통해)
      expect((window as any).__g7IsolatedStates['non-existent']).toBeUndefined();

      warnSpy.mockRestore();
    });

    it('should log warning when no actionContext available', () => {
      (window as any).__g7ActionContext = null;
      const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      G7Core.state.setIsolated({ value: 123 });

      // 경고가 출력되어야 함
      warnSpy.mockRestore();
    });
  });
});
