/**
 * @file WebSocketManager.multiSubscriber.test.ts
 * @description 같은 `channel:event` 를 두 주인이 구독할 때의 결함 (engine-v1.93.0, #816 S1 F7)
 *
 * 결함:
 *   구독 키가 `channel:event` 하나뿐이라 두 번째 구독은 「이미 구독 중」 으로 같은 키만 돌려받고 콜백은 연결되지
 *   않았다. 두 주인이 같은 키를 들고 있으므로 한쪽이 해제하면 다른 쪽도 끊겼다.
 *
 * 수정:
 *   - 엔트리는 `channel:event` → { 채널, 콜백 Map } 이다. Echo `listen` 은 키당 1회만 걸고, 디스패처가 각 콜백을 부른다.
 *   - `subscribe` 는 구독마다 고유 키 `channel:event#n` 를 돌려준다.
 *   - `unsubscribe(key)` 는 그 콜백만 지우고, 마지막 콜백이 빠질 때만 `stopListening` 한다.
 *   - 레거시 키(`#` 없음)로 해제하면 그 엔트리의 콜백을 전부 지우고 `[G7]` 경고를 낸다.
 *
 * Echo 는 모의 객체다 — 채널별 리스너를 기억해 「서버가 이벤트를 보냈다」 를 흉내 낸다.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('../../utils/Logger', () => ({
  createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
}));

/** 채널 이름 → { 이벤트 → 리스너 } — 모의 Echo 채널이 기억한다 */
const channelListeners = new Map<string, Map<string, (data: unknown) => void>>();
const listenCalls: Array<{ channel: string; event: string }> = [];
const stopCalls: Array<{ channel: string; event: string }> = [];

function makeChannel(name: string) {
  const ch: any = {
    listen: vi.fn((event: string, cb: (data: unknown) => void) => {
      listenCalls.push({ channel: name, event });
      if (!channelListeners.has(name)) channelListeners.set(name, new Map());
      channelListeners.get(name)!.set(event, cb);
      return ch;
    }),
    stopListening: vi.fn((event: string) => {
      stopCalls.push({ channel: name, event });
      channelListeners.get(name)?.delete(event);
      return ch;
    }),
  };
  return ch;
}

const channels = new Map<string, any>();
const getChannel = (name: string) => {
  if (!channels.has(name)) channels.set(name, makeChannel(name));
  return channels.get(name);
};

vi.mock('laravel-echo', () => ({
  default: class MockEcho {
    channel = vi.fn((n: string) => getChannel(n));
    private = vi.fn((n: string) => getChannel(n));
    join = vi.fn((n: string) => getChannel(n));
    leave = vi.fn();
    disconnect = vi.fn();
  },
}));

vi.mock('pusher-js', () => ({
  default: class MockPusher {
    connection = { bind: vi.fn(), state: 'initialized', socket_id: 'sock' };
    connect = vi.fn();
  },
}));

/** 서버가 `channel` 에 `event` 를 보낸 것처럼 리스너를 부른다 */
function serverSends(channel: string, event: string, data: unknown): void {
  channelListeners.get(channel)?.get(`.${event}`)?.(data);
}

/** `[G7]` 접두 경고만 골라낸다. */
function g7Warnings(spy: ReturnType<typeof vi.spyOn>): string[] {
  return spy.mock.calls
    .map((args: unknown[]) => args.map((a) => String(a)).join(' '))
    .filter((msg: string) => msg.startsWith('[G7]'));
}

describe('WebSocketManager 같은 키 다중 구독 (engine-v1.93.0)', () => {
  let warnSpy: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    vi.resetModules();
    channelListeners.clear();
    channels.clear();
    listenCalls.length = 0;
    stopCalls.length = 0;
    warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
  });

  afterEach(() => {
    warnSpy.mockRestore();
  });

  async function manager() {
    const { webSocketManager } = await import('../WebSocketManager');
    webSocketManager.configure({ appKey: 'k', host: 'localhost', port: 8080, scheme: 'http' });
    return webSocketManager;
  }

  // @scenario case=ws_multi_c01
  // @effects same_channel_event_subscribers_independent
  it('같은 channel:event 를 두 번 구독하면 키가 서로 다르고 둘 다 수신한다 — Echo listen 은 1회', async () => {
    const m = await manager();
    const dashboard = vi.fn();
    const probe = vi.fn();

    const k1 = m.subscribe('core.admin.dashboard', 'dashboard.stats.updated', dashboard);
    const k2 = m.subscribe('core.admin.dashboard', 'dashboard.stats.updated', probe);

    expect(k1).not.toBe(k2);
    expect(k1.startsWith('core.admin.dashboard:dashboard.stats.updated')).toBe(true);
    expect(k2.startsWith('core.admin.dashboard:dashboard.stats.updated')).toBe(true);
    expect(listenCalls).toHaveLength(1);

    serverSends('core.admin.dashboard', 'dashboard.stats.updated', { n: 1 });

    expect(dashboard).toHaveBeenCalledWith({ n: 1 });
    expect(probe).toHaveBeenCalledWith({ n: 1 });
  });

  // @scenario case=ws_multi_c02
  // @effects same_channel_event_subscribers_independent
  it('한쪽을 해제해도 남은 쪽은 계속 수신하고, stopListening 은 마지막 해제 때만 부른다', async () => {
    const m = await manager();
    const dashboard = vi.fn();
    const probe = vi.fn();
    const k1 = m.subscribe('core.admin.dashboard', 'dashboard.stats.updated', dashboard);
    const k2 = m.subscribe('core.admin.dashboard', 'dashboard.stats.updated', probe);

    m.unsubscribe(k2);
    expect(stopCalls).toHaveLength(0);

    serverSends('core.admin.dashboard', 'dashboard.stats.updated', { n: 2 });
    expect(dashboard).toHaveBeenCalledWith({ n: 2 });
    expect(probe).not.toHaveBeenCalled();

    m.unsubscribe(k1);
    expect(stopCalls).toEqual([{ channel: 'core.admin.dashboard', event: '.dashboard.stats.updated' }]);
    expect(g7Warnings(warnSpy)).toEqual([]);
  });

  // @scenario case=ws_multi_c03
  // @effects same_channel_event_subscribers_independent
  it('구독 수는 구독(콜백) 단위로 센다', async () => {
    const m = await manager();
    const k1 = m.subscribe('c', 'e', vi.fn());
    m.subscribe('c', 'e', vi.fn());
    expect(m.getSubscriptionCount()).toBe(2);

    m.unsubscribe(k1);
    expect(m.getSubscriptionCount()).toBe(1);
  });

  // @scenario case=ws_multi_c04
  // @effects same_channel_event_subscribers_independent
  it('레거시 키(channel:event)로 해제하면 그 키의 콜백을 전부 지우고 경고 1건을 낸다', async () => {
    const m = await manager();
    const a = vi.fn();
    const b = vi.fn();
    m.subscribe('c', 'e', a);
    m.subscribe('c', 'e', b);

    m.unsubscribe('c:e');

    serverSends('c', 'e', {});
    expect(a).not.toHaveBeenCalled();
    expect(b).not.toHaveBeenCalled();
    expect(stopCalls).toHaveLength(1);
    expect(m.getSubscriptionCount()).toBe(0);
    const w = g7Warnings(warnSpy);
    expect(w).toHaveLength(1);
    expect(w[0]).toContain('c:e');
  });

  // @scenario case=ws_multi_c05
  // @effects same_channel_event_subscribers_independent
  it('이미 해제한 키를 다시 해제해도 다른 구독에 영향이 없다', async () => {
    const m = await manager();
    const a = vi.fn();
    const k1 = m.subscribe('c', 'e', vi.fn());
    m.subscribe('c', 'e', a);

    m.unsubscribe(k1);
    m.unsubscribe(k1);

    serverSends('c', 'e', { ok: true });
    expect(a).toHaveBeenCalledWith({ ok: true });
  });

  // @scenario case=ws_multi_c06
  // @effects same_channel_event_subscribers_independent
  it('한 콜백이 예외를 던져도 같은 키의 다른 콜백은 받는다', async () => {
    const m = await manager();
    const ok = vi.fn();
    m.subscribe('c', 'e', () => {
      throw new Error('boom');
    });
    m.subscribe('c', 'e', ok);

    serverSends('c', 'e', 1);
    expect(ok).toHaveBeenCalledWith(1);
  });

  // @scenario case=ws_multi_c07
  // @effects same_channel_event_subscribers_independent
  it('해제 뒤 같은 키를 다시 구독하면 Echo listen 을 다시 건다', async () => {
    const m = await manager();
    const k1 = m.subscribe('c', 'e', vi.fn());
    m.unsubscribe(k1);

    const again = vi.fn();
    m.subscribe('c', 'e', again);
    expect(listenCalls).toHaveLength(2);

    serverSends('c', 'e', 3);
    expect(again).toHaveBeenCalledWith(3);
  });

  // @scenario case=ws_multi_c08
  // @effects same_channel_event_subscribers_independent
  it('leaveChannel 은 그 채널의 구독을 전부 지운다 (종전 의미 유지)', async () => {
    const m = await manager();
    m.subscribe('c', 'e1', vi.fn());
    m.subscribe('c', 'e1', vi.fn());
    m.subscribe('c', 'e2', vi.fn());
    m.subscribe('other', 'e', vi.fn());

    m.leaveChannel('c');

    expect(m.getSubscriptionCount()).toBe(1);
  });
});
