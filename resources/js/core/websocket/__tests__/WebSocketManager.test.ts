import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

// Logger 모킹
vi.mock('../../utils/Logger', () => ({
  Logger: {
    getInstance: () => ({
      debug: vi.fn(),
      warn: vi.fn(),
      error: vi.fn(),
      info: vi.fn(),
      log: vi.fn(),
      isDebugEnabled: () => false,
    }),
  },
  createLogger: () => ({
    log: vi.fn(),
    warn: vi.fn(),
    error: vi.fn(),
  }),
}));

// Echo와 Pusher 모킹
const mockListen = vi.fn().mockReturnThis();
const mockChannel = vi.fn().mockReturnValue({ listen: mockListen });
const mockPrivate = vi.fn().mockReturnValue({ listen: mockListen });
const mockJoin = vi.fn().mockReturnValue({ listen: mockListen });
const mockLeave = vi.fn();
const mockDisconnect = vi.fn();

// Mock Echo 클래스
class MockEcho {
  channel = mockChannel;
  private = mockPrivate;
  join = mockJoin;
  leave = mockLeave;
  disconnect = mockDisconnect;
}

vi.mock('laravel-echo', () => ({
  default: MockEcho,
}));

// Mock Pusher 클래스 with connection
const mockConnectionBind = vi.fn();
const mockConnect = vi.fn();

vi.mock('pusher-js', () => ({
  default: class MockPusher {
    connection = {
      bind: mockConnectionBind,
      state: 'initialized',
      socket_id: 'test-socket-id',
    };
    connect = mockConnect;
  },
}));

describe('WebSocketManager', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    // 모듈 캐시 초기화 (싱글톤 리셋)
    vi.resetModules();
  });

  afterEach(() => {
    vi.unstubAllEnvs();
  });

  /**
   * WebSocketManager에 설정을 적용하고 반환하는 헬퍼 함수
   */
  async function getConfiguredManager() {
    const { webSocketManager } = await import('../WebSocketManager');
    webSocketManager.configure({
      appKey: 'test-key',
      host: 'localhost',
      port: 8080,
      scheme: 'http',
    });
    return webSocketManager;
  }

  describe('configure', () => {
    // @scenario case=ws_manager_c01
    // @effects websocket_manager_subscription_contract
    it('should set configuration correctly', async () => {
      const { webSocketManager } = await import('../WebSocketManager');

      expect(webSocketManager.isConfigured()).toBe(false);

      webSocketManager.configure({
        appKey: 'test-key',
        host: 'localhost',
        port: 8080,
        scheme: 'http',
      });

      expect(webSocketManager.isConfigured()).toBe(true);
    });

    // @scenario case=ws_manager_c02
    // @effects websocket_manager_subscription_contract
    it('should not allow reconfiguration after initialization', async () => {
      const webSocketManager = await getConfiguredManager();
      const callback = vi.fn();

      // 초기화 트리거
      webSocketManager.subscribe('test.channel', 'test.event', callback);
      expect(webSocketManager.isInitialized()).toBe(true);

      // 재설정 시도 (무시되어야 함)
      webSocketManager.configure({
        appKey: 'new-key',
        host: 'new-host',
        port: 9090,
        scheme: 'https',
      });

      // 여전히 초기화된 상태 유지
      expect(webSocketManager.isInitialized()).toBe(true);
    });
  });

  describe('subscribe', () => {
    // @scenario case=ws_manager_c03
    // @effects websocket_manager_subscription_contract
    it('should create subscription key correctly', async () => {
      const webSocketManager = await getConfiguredManager();
      const callback = vi.fn();

      const key = webSocketManager.subscribe(
        'admin.dashboard',
        'dashboard.stats.updated',
        callback
      );

      // engine-v1.87.0: 구독마다 고유 키 `channel:event#n` — 같은 키를 두 주인이 나눠 들지 않는다
      expect(key).toMatch(/^admin\.dashboard:dashboard\.stats\.updated#\d+$/);
    });

    // @scenario case=ws_manager_c04
    // @effects websocket_manager_subscription_contract
    it('should listen once per channel:event but give each subscriber its own key', async () => {
      const webSocketManager = await getConfiguredManager();
      const callback1 = vi.fn();
      const callback2 = vi.fn();

      const key1 = webSocketManager.subscribe('test.channel', 'test.event', callback1);
      const key2 = webSocketManager.subscribe('test.channel', 'test.event', callback2);

      // engine-v1.87.0: 종전에는 key1 === key2 이고 callback2 가 연결되지 않았다(F7)
      expect(key1).not.toBe(key2);
      expect(mockListen).toHaveBeenCalledTimes(1);
    });

    // @scenario case=ws_manager_c05
    // @effects websocket_manager_subscription_contract
    it('should use private channel by default', async () => {
      const webSocketManager = await getConfiguredManager();
      const callback = vi.fn();

      webSocketManager.subscribe('admin.dashboard', 'test.event', callback);

      expect(mockPrivate).toHaveBeenCalledWith('admin.dashboard');
    });

    // @scenario case=ws_manager_c06
    // @effects websocket_manager_subscription_contract
    it('should use public channel when specified', async () => {
      const webSocketManager = await getConfiguredManager();
      const callback = vi.fn();

      webSocketManager.subscribe('public.channel', 'test.event', callback, {
        channelType: 'public',
      });

      expect(mockChannel).toHaveBeenCalledWith('public.channel');
    });

    // @scenario case=ws_manager_c07
    // @effects websocket_manager_subscription_contract
    it('should use presence channel when specified', async () => {
      const webSocketManager = await getConfiguredManager();
      const callback = vi.fn();

      webSocketManager.subscribe('presence.channel', 'test.event', callback, {
        channelType: 'presence',
      });

      expect(mockJoin).toHaveBeenCalledWith('presence.channel');
    });

    // @scenario case=ws_manager_c08
    // @effects websocket_manager_subscription_contract
    it('should listen with dot prefix for event name', async () => {
      const webSocketManager = await getConfiguredManager();
      const callback = vi.fn();

      webSocketManager.subscribe('test.channel', 'test.event', callback);

      // engine-v1.87.0: listen 에는 구독자들을 부르는 디스패처가 걸린다
      expect(mockListen).toHaveBeenCalledWith('.test.event', expect.any(Function));
      const dispatcher = mockListen.mock.calls[0][1] as (data: unknown) => void;
      dispatcher({ ok: 1 });
      expect(callback).toHaveBeenCalledWith({ ok: 1 });
    });
  });

  describe('unsubscribe', () => {
    // @scenario case=ws_manager_c09
    // @effects websocket_manager_subscription_contract
    it('should handle non-existent subscription gracefully', async () => {
      const webSocketManager = await getConfiguredManager();

      expect(() => {
        webSocketManager.unsubscribe('non.existent:key');
      }).not.toThrow();
    });
  });

  describe('disconnect', () => {
    // @scenario case=ws_manager_c10
    // @effects websocket_manager_subscription_contract
    it('should call echo disconnect', async () => {
      const webSocketManager = await getConfiguredManager();
      const callback = vi.fn();

      // 초기화를 위해 subscribe 호출
      webSocketManager.subscribe('test.channel', 'test.event', callback);

      webSocketManager.disconnect();

      expect(mockDisconnect).toHaveBeenCalled();
    });

    // @scenario case=ws_manager_c11
    // @effects websocket_manager_subscription_contract
    it('should reset initialized state after disconnect', async () => {
      const webSocketManager = await getConfiguredManager();
      const callback = vi.fn();

      webSocketManager.subscribe('test.channel', 'test.event', callback);
      expect(webSocketManager.isInitialized()).toBe(true);

      webSocketManager.disconnect();
      expect(webSocketManager.isInitialized()).toBe(false);
    });
  });

  describe('getSubscriptionCount', () => {
    // @scenario case=ws_manager_c12
    // @effects websocket_manager_subscription_contract
    it('should return correct subscription count', async () => {
      const webSocketManager = await getConfiguredManager();
      const callback = vi.fn();

      expect(webSocketManager.getSubscriptionCount()).toBe(0);

      webSocketManager.subscribe('channel1', 'event1', callback);
      expect(webSocketManager.getSubscriptionCount()).toBe(1);

      webSocketManager.subscribe('channel2', 'event2', callback);
      expect(webSocketManager.getSubscriptionCount()).toBe(2);
    });
  });

  describe('initialization', () => {
    // @scenario case=ws_manager_c13
    // @effects websocket_manager_subscription_contract
    it('should not initialize without configuration', async () => {
      const { webSocketManager } = await import('../WebSocketManager');
      const callback = vi.fn();

      // configure 호출 안함
      const key = webSocketManager.subscribe('test.channel', 'test.event', callback);

      expect(key).toBe('');
      expect(webSocketManager.isInitialized()).toBe(false);
      expect(webSocketManager.isConfigured()).toBe(false);
    });

    // @scenario case=ws_manager_c14
    // @effects websocket_manager_subscription_contract
    it('should not initialize with empty appKey', async () => {
      const { webSocketManager } = await import('../WebSocketManager');

      webSocketManager.configure({
        appKey: '',
        host: 'localhost',
        port: 8080,
        scheme: 'http',
      });

      const callback = vi.fn();
      const key = webSocketManager.subscribe('test.channel', 'test.event', callback);

      expect(key).toBe('');
      expect(webSocketManager.isInitialized()).toBe(false);
      expect(webSocketManager.isConfigured()).toBe(false);
    });
  });
});
