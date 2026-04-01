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
    it('should create subscription key correctly', async () => {
      const webSocketManager = await getConfiguredManager();
      const callback = vi.fn();

      const key = webSocketManager.subscribe(
        'admin.dashboard',
        'dashboard.stats.updated',
        callback
      );

      expect(key).toBe('admin.dashboard:dashboard.stats.updated');
    });

    it('should not duplicate subscriptions for same channel:event', async () => {
      const webSocketManager = await getConfiguredManager();
      const callback1 = vi.fn();
      const callback2 = vi.fn();

      const key1 = webSocketManager.subscribe('test.channel', 'test.event', callback1);
      const key2 = webSocketManager.subscribe('test.channel', 'test.event', callback2);

      expect(key1).toBe(key2);
    });

    it('should use private channel by default', async () => {
      const webSocketManager = await getConfiguredManager();
      const callback = vi.fn();

      webSocketManager.subscribe('admin.dashboard', 'test.event', callback);

      expect(mockPrivate).toHaveBeenCalledWith('admin.dashboard');
    });

    it('should use public channel when specified', async () => {
      const webSocketManager = await getConfiguredManager();
      const callback = vi.fn();

      webSocketManager.subscribe('public.channel', 'test.event', callback, {
        channelType: 'public',
      });

      expect(mockChannel).toHaveBeenCalledWith('public.channel');
    });

    it('should use presence channel when specified', async () => {
      const webSocketManager = await getConfiguredManager();
      const callback = vi.fn();

      webSocketManager.subscribe('presence.channel', 'test.event', callback, {
        channelType: 'presence',
      });

      expect(mockJoin).toHaveBeenCalledWith('presence.channel');
    });

    it('should listen with dot prefix for event name', async () => {
      const webSocketManager = await getConfiguredManager();
      const callback = vi.fn();

      webSocketManager.subscribe('test.channel', 'test.event', callback);

      expect(mockListen).toHaveBeenCalledWith('.test.event', callback);
    });
  });

  describe('unsubscribe', () => {
    it('should handle non-existent subscription gracefully', async () => {
      const webSocketManager = await getConfiguredManager();

      expect(() => {
        webSocketManager.unsubscribe('non.existent:key');
      }).not.toThrow();
    });
  });

  describe('disconnect', () => {
    it('should call echo disconnect', async () => {
      const webSocketManager = await getConfiguredManager();
      const callback = vi.fn();

      // 초기화를 위해 subscribe 호출
      webSocketManager.subscribe('test.channel', 'test.event', callback);

      webSocketManager.disconnect();

      expect(mockDisconnect).toHaveBeenCalled();
    });

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
    it('should not initialize without configuration', async () => {
      const { webSocketManager } = await import('../WebSocketManager');
      const callback = vi.fn();

      // configure 호출 안함
      const key = webSocketManager.subscribe('test.channel', 'test.event', callback);

      expect(key).toBe('');
      expect(webSocketManager.isInitialized()).toBe(false);
      expect(webSocketManager.isConfigured()).toBe(false);
    });

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
