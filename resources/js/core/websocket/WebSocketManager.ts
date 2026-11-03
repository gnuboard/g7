/// <reference types="vite/client" />
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { createLogger } from '../utils/Logger';
import { withBasePrefix } from '../support/assetUrl';

// Pusher를 window 객체에 등록 (Laravel Echo 필수)
declare global {
  interface Window {
    Pusher: typeof Pusher;
    Echo: Echo<'reverb'>;
  }
}

/**
 * WebSocket 채널 타입
 */
export type ChannelType = 'public' | 'private' | 'presence';

/**
 * WebSocket 구독 옵션
 */
export interface SubscriptionOptions {
  /** 채널 타입 (기본값: 'private') */
  channelType?: ChannelType;
}

/**
 * 한 `channel:event` 의 구독 엔트리 (engine-v1.87.0)
 */
interface SubscriptionEntry {
  /** Echo 채널 인스턴스 */
  channelInstance: ReturnType<Echo<'reverb'>['channel']>;
  /** 채널명 */
  channel: string;
  /** 이벤트명 (`.` 접두 없이) */
  event: string;
  /** 구독 키(`channel:event#n`) → 콜백 */
  callbacks: Map<string, (data: unknown) => void>;
}

/**
 * WebSocket 설정 인터페이스 (Blade에서 동적으로 전달)
 */
export interface WebSocketConfig {
  /** Reverb 앱 키 */
  appKey: string;
  /** WebSocket 호스트 (기본값: localhost) */
  host?: string;
  /** WebSocket 포트 (기본값: 80) */
  port?: number;
  /** 스키마 (http 또는 https, 기본값: https) */
  scheme?: 'http' | 'https';
  /** Private 채널 인증 엔드포인트 (기본값: /broadcasting/auth) */
  authEndpoint?: string;
}

/**
 * WebSocket 연결을 관리하는 싱글톤 매니저
 *
 * Laravel Reverb와 Laravel Echo를 사용하여 WebSocket 연결을 관리합니다.
 * 설정은 Blade 템플릿에서 동적으로 전달받습니다.
 */
const logger = createLogger('WebSocketManager');

class WebSocketManager {
  private echo: Echo<'reverb'> | null = null;
  /**
   * `channel:event` → { Echo 채널, 구독 키별 콜백 } (engine-v1.87.0)
   *
   * 종전에는 `channel:event` → 채널 하나라 같은 키를 두 번째로 구독하면 콜백이 연결되지 않았고,
   * 두 주인이 같은 키를 들고 있어 한쪽 해제가 다른 쪽까지 끊었다. 이제 Echo `listen` 은 키당 1회만 걸고
   * 디스패처가 등록된 콜백을 모두 부른다.
   */
  private subscriptions: Map<string, SubscriptionEntry> = new Map();
  /** 구독 키 일련번호 — `channel:event#n` 의 n */
  private subscriptionSeq = 0;
  private initialized = false;
  private config: WebSocketConfig | null = null;

  /**
   * WebSocket 설정을 지정합니다.
   * TemplateApp 초기화 시 호출됩니다.
   *
   * @param config WebSocket 설정
   */
  configure(config: WebSocketConfig): void {
    if (this.initialized) {
      logger.warn('[WebSocketManager] 이미 초기화되어 설정을 변경할 수 없습니다.');
      return;
    }
    this.config = config;
    logger.log('[WebSocketManager] 설정 완료:', {
      appKey: config.appKey ? '***' : '(없음)',
      host: config.host,
      port: config.port,
      scheme: config.scheme,
    });
  }

  /**
   * Echo 인스턴스를 초기화합니다.
   */
  initialize(): void {
    if (this.initialized) {
      return;
    }

    // 설정 확인
    if (!this.config || !this.config.appKey) {
      logger.warn('[WebSocketManager] WebSocket 설정이 없습니다. initTemplateApp에서 websocket 옵션을 전달해주세요.');
      return;
    }

    const { appKey, host = 'localhost', port = 80, scheme = 'https', authEndpoint: configuredAuthEndpoint } = this.config;

    // 하위 폴더 배치에서 사이트 밖을 가리키지 않도록 접두사를 거친다 (헬퍼는 멱등이고
    // 절대 URL 은 그대로 둔다). 운영자가 지정한 값도 같은 규칙을 탄다.
    const authEndpoint = withBasePrefix(configuredAuthEndpoint ?? '/api/broadcasting/auth');
    const numPort = Number(port) || 80;
    const useTLS = scheme === 'https';

    logger.log('[WebSocketManager] 연결 설정:', { host, port: numPort, scheme, useTLS, authEndpoint });

    window.Pusher = Pusher;

    // Pusher 클라이언트를 직접 생성하여 전달
    // Pusher 8.x에서는 enabledTransports/disabledTransports 설정 시 충돌 가능
    // Sanctum 토큰 가져오기
    const authToken = localStorage.getItem('auth_token');

    const pusherOptions = {
      wsHost: host,
      wsPort: numPort,
      wssPort: numPort,
      forceTLS: useTLS,
      disableStats: true,
      enabledTransports: ['ws', 'wss'] as const,
      cluster: 'mt1', // 필수 옵션이지만 Reverb에서는 무시됨
      // Private 채널 인증 설정
      authEndpoint: authEndpoint,
      auth: {
        headers: {
          'Authorization': authToken ? `Bearer ${authToken}` : '',
          'Accept': 'application/json',
        },
      },
    };

    logger.log('[WebSocketManager] Pusher 옵션:', pusherOptions);

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const pusherClient = new Pusher(appKey, pusherOptions as any);

    // 연결 상태 디버깅을 위한 이벤트 리스너
    pusherClient.connection.bind('connecting', () => {
      logger.log('[WebSocketManager] 연결 시도 중...');
    });

    pusherClient.connection.bind('connected', () => {
      logger.log('[WebSocketManager] 연결 성공! Socket ID:', pusherClient.connection.socket_id);
    });

    pusherClient.connection.bind('failed', () => {
      logger.error('[WebSocketManager] 연결 실패 - WebSocket을 사용할 수 없습니다.');
    });

    pusherClient.connection.bind('error', (error: unknown) => {
      logger.error('[WebSocketManager] 연결 오류:', error);
    });

    pusherClient.connection.bind('state_change', (states: { previous: string; current: string }) => {
      logger.log(`[WebSocketManager] 상태 변경: ${states.previous} → ${states.current}`);
    });

    pusherClient.connection.bind('unavailable', () => {
      logger.error('[WebSocketManager] WebSocket 사용 불가 - 연결할 수 없습니다.');
    });

    pusherClient.connection.bind('disconnected', () => {
      logger.warn('[WebSocketManager] 연결이 끊어졌습니다.');
    });

    // 초기 연결 상태 로깅
    logger.log('[WebSocketManager] 초기 연결 상태:', pusherClient.connection.state);

    this.echo = new Echo({
      broadcaster: 'reverb',
      client: pusherClient,
    });

    // 명시적으로 연결 시작
    logger.log('[WebSocketManager] 연결 시작 시도...');
    pusherClient.connect();

    window.Echo = this.echo;
    this.initialized = true;
    logger.log('[WebSocketManager] Echo 초기화 완료');
  }

  /**
   * 채널을 구독하고 이벤트를 리스닝합니다.
   *
   * 같은 `channel:event` 를 여러 주인이 구독해도 각자 수신한다. Echo `listen` 은 그 키에 1회만 걸고,
   * 이벤트가 오면 등록된 콜백을 모두 부른다 (engine-v1.87.0).
   *
   * @param channel 채널명 (예: 'admin.dashboard')
   * @param event 이벤트명 (예: 'dashboard.stats.updated')
   * @param callback 이벤트 수신 시 호출될 콜백
   * @param options 구독 옵션
   * @returns 이 구독의 고유 키 (`channel:event#n`, 해제 시 사용). Echo 가 없으면 빈 문자열
   */
  subscribe(
    channel: string,
    event: string,
    callback: (data: unknown) => void,
    options: SubscriptionOptions = {}
  ): string {
    this.initialize();

    if (!this.echo) {
      logger.warn('[WebSocketManager] Echo가 초기화되지 않았습니다.');
      return '';
    }

    const { channelType = 'private' } = options;
    const entryKey = `${channel}:${event}`;
    const subscriptionKey = `${entryKey}#${++this.subscriptionSeq}`;

    const existing = this.subscriptions.get(entryKey);
    if (existing) {
      existing.callbacks.set(subscriptionKey, callback);
      logger.log(`[WebSocketManager] 같은 채널·이벤트에 구독 추가: ${subscriptionKey} (구독 ${existing.callbacks.size}개)`);
      return subscriptionKey;
    }

    let channelInstance: ReturnType<Echo<'reverb'>['channel']>;
    switch (channelType) {
      case 'public':
        channelInstance = this.echo.channel(channel);
        break;
      case 'presence':
        channelInstance = this.echo.join(channel);
        break;
      default:
        channelInstance = this.echo.private(channel);
    }

    const entry: SubscriptionEntry = {
      channelInstance,
      channel,
      event,
      callbacks: new Map([[subscriptionKey, callback]]),
    };

    // Reverb 이벤트는 .으로 시작해야 함
    // 디스패처가 등록된 콜백을 모두 부른다 — 한 콜백의 예외가 다른 구독자 수신을 막지 않는다
    channelInstance.listen(`.${event}`, (data: unknown) => {
      Array.from(entry.callbacks.values()).forEach((cb) => {
        try {
          cb(data);
        } catch (error) {
          logger.error(`[WebSocketManager] 구독 콜백 오류: ${entryKey}`, error);
        }
      });
    });
    this.subscriptions.set(entryKey, entry);

    logger.log(`[WebSocketManager] 구독 완료: ${subscriptionKey} (${channelType})`);

    return subscriptionKey;
  }

  /**
   * 구독을 해제합니다.
   *
   * 그 구독 키의 콜백만 지운다. 같은 `channel:event` 의 마지막 콜백이 빠질 때만 Echo 리스너를
   * 명시 해제(`stopListening`)한다 — Echo 는 같은 채널을 재사용하므로 해제를 빠뜨리면 재구독 시 리스너가
   * 중복 누적된다.
   *
   * 레거시 키(`channel:event`, `#` 없음)로 부르면 그 키의 콜백을 전부 지우고 `[G7]` 경고를 낸다
   * (engine-v1.87.0 — 다른 주인의 구독까지 끊기 때문).
   *
   * @param subscriptionKey `subscribe` 가 돌려준 구독 키 (형식: "channel:event#n")
   */
  unsubscribe(subscriptionKey: string): void {
    const hash = subscriptionKey.lastIndexOf('#');
    const isLegacyKey = hash < 0;
    const entryKey = isLegacyKey ? subscriptionKey : subscriptionKey.substring(0, hash);
    const entry = this.subscriptions.get(entryKey);
    if (!entry) {
      return;
    }

    if (isLegacyKey) {
      console.warn(
        `[G7] WebSocket 구독 해제에 채널·이벤트 키("${subscriptionKey}")를 넘겨 그 키의 구독 ${entry.callbacks.size}개를 모두 지웠습니다. ` +
          '다른 화면·확장의 구독도 함께 끊깁니다 — subscribe 가 돌려준 구독 키를 넘기세요.'
      );
      entry.callbacks.clear();
    } else if (!entry.callbacks.delete(subscriptionKey)) {
      return;
    }

    if (entry.callbacks.size > 0) {
      logger.log(`[WebSocketManager] 구독 해제: ${subscriptionKey} (남은 구독 ${entry.callbacks.size}개)`);
      return;
    }

    try {
      // Reverb 이벤트는 '.'으로 시작해야 함 (subscribe와 동일)
      entry.channelInstance.stopListening(`.${entry.event}`);
    } catch (e) {
      logger.warn(`[WebSocketManager] stopListening 실패: ${subscriptionKey}`, e);
    }

    this.subscriptions.delete(entryKey);
    logger.log(`[WebSocketManager] 구독 해제: ${subscriptionKey}`);
  }

  /**
   * 특정 채널의 모든 구독을 해제합니다.
   *
   * @param channel 채널명
   */
  leaveChannel(channel: string): void {
    if (!this.echo) {
      return;
    }

    this.echo.leave(channel);

    // 해당 채널의 모든 구독 엔트리 제거
    const keysToDelete: string[] = [];
    this.subscriptions.forEach((entry, key) => {
      if (entry.channel === channel) {
        keysToDelete.push(key);
      }
    });

    keysToDelete.forEach((key) => this.subscriptions.delete(key));
    logger.log(`[WebSocketManager] 채널 구독 해제: ${channel}`);
  }

  /**
   * 모든 구독을 해제하고 연결을 종료합니다.
   */
  disconnect(): void {
    if (this.echo) {
      this.echo.disconnect();
      this.subscriptions.clear();
      this.initialized = false;
      logger.log('[WebSocketManager] 연결 종료');
    }
  }

  /**
   * Echo 인스턴스를 반환합니다.
   *
   * @returns Echo 인스턴스 또는 null
   */
  getEcho(): Echo<'reverb'> | null {
    return this.echo;
  }

  /**
   * 초기화 여부를 반환합니다.
   *
   * @returns 초기화 여부
   */
  isInitialized(): boolean {
    return this.initialized;
  }

  /**
   * 설정 여부를 반환합니다.
   *
   * @returns 설정 여부
   */
  isConfigured(): boolean {
    return this.config !== null && !!this.config.appKey;
  }

  /**
   * 현재 활성 구독 수를 반환합니다.
   *
   * 구독(콜백) 단위로 센다 — 같은 `channel:event` 를 둘이 구독하면 2다 (engine-v1.87.0).
   *
   * @returns 구독 수
   */
  getSubscriptionCount(): number {
    let count = 0;
    this.subscriptions.forEach((entry) => {
      count += entry.callbacks.size;
    });
    return count;
  }
}

export const webSocketManager = new WebSocketManager();
export { WebSocketManager };
