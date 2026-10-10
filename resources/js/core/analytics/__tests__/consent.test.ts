/**
 * 동의 브리지 — 코어 동의 런타임의 소비자 판정.
 *
 * 동의 여부를 모르는 상태에서 보내면 안 되고, 확인할 곳이 아예 없으면 막아서도
 * 안 된다(서버가 익명 모드로 내려가 개인정보 없이 수집한다). 판정 출처는 코어 동의 런타임이며
 * (#639), 브리지는 자체 조회·자체 이벤트를 두지 않는다.
 *
 * @scenario mode=consent
 * @effects consent_bridge_blocks_sending_until_consent_is_known,
 *   consent_bridge_does_not_block_when_there_is_no_consent_source,
 *   consent_bridge_updates_on_consent_change_events
 */

import { describe, expect, it, vi } from 'vitest';

import { ConsentBridge, consentGateRequired, permitsAnalytics, type ConsentSnapshotLike, type ConsentSource } from '../consent';

/**
 * 코어 동의 런타임 흉내.
 *
 * @param snapshot 첫 스냅샷
 * @param pending 판정 대기 여부
 * @returns 동의 출처와 판정 완료 함수
 */
function source(snapshot: ConsentSnapshotLike, pending = false): ConsentSource & { settle: (next: ConsentSnapshotLike) => void } {
  let current = snapshot;
  let waiting = pending;
  const listeners = new Set<(s: ConsentSnapshotLike) => void>();

  return {
    getSnapshot: () => current,
    isPending: () => waiting,
    onSettled: (listener) => {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
    settle: (next) => {
      current = next;
      waiting = false;
      listeners.forEach((listener) => listener(next));
    },
  };
}

const READY = (analytics: boolean, needsRenewal = false): ConsentSnapshotLike => ({
  status: 'ready',
  categories: { necessary: true, analytics },
  needsRenewal,
});

describe('consentGateRequired', () => {
  // @scenario mode=full|anonymous|consent
  // @effects consent_bridge_does_not_block_when_there_is_no_consent_source, consent_gate_applies_only_in_consent_mode
  it('동의 게이트는 동의 관리 제공자가 있고 수집 모드가 동의 연동일 때만 선다', () => {
    // IP 저장·익명 모드는 동의를 게이트로 쓰지 않는다 — 제공자가 있어도 막으면
    // 미동의 방문자(기본값)의 화면 이동이 통째로 빠진다.
    expect(consentGateRequired(true, 'full')).toBe(false);
    expect(consentGateRequired(true, 'anonymous')).toBe(false);
    expect(consentGateRequired(true, undefined)).toBe(false);
    expect(consentGateRequired(false, 'consent')).toBe(false);
    expect(consentGateRequired(true, 'consent')).toBe(true);
  });
});

describe('ConsentBridge', () => {
  // @scenario mode=consent
  // @effects consent_bridge_does_not_block_when_there_is_no_consent_source
  it('게이트가 서지 않으면 막지 않는다', async () => {
    const bridge = new ConsentBridge(false);
    await bridge.start();

    // 서버가 익명 모드로 내려가므로 여기서 막으면 수집이 통째로 0 이 된다.
    expect(bridge.allowsSending()).toBe(true);
    expect(bridge.isPending()).toBe(false);
    expect(bridge.current().source).toBe('none');
  });

  // @scenario mode=consent
  // @effects consent_bridge_does_not_block_when_there_is_no_consent_source
  it('런타임이 「동의 관리 제공자 없음(none)」으로 답하면 막지 않는다', () => {
    const bridge = new ConsentBridge(true, source({ status: 'none', categories: { necessary: true }, needsRenewal: false }));

    expect(bridge.allowsSending()).toBe(true);
    expect(bridge.current()).toEqual({ source: 'none', granted: null });
  });

  // @scenario mode=consent, exclusion=consent
  // @effects consent_bridge_blocks_sending_until_consent_is_known
  it('판정 대기 중에는 보내지 않고 대기로 알린다', () => {
    const bridge = new ConsentBridge(true, source({ status: 'unknown', categories: { necessary: true }, needsRenewal: false }, true));

    expect(bridge.isPending()).toBe(true);
    expect(bridge.allowsSending()).toBe(false);
    expect(bridge.current()).toEqual({ source: 'provider', granted: null });
  });

  // @scenario mode=consent, exclusion=consent
  // @effects consent_bridge_blocks_sending_until_consent_is_known
  it('분석 동의는 ready + 재동의 대상 아님 + analytics 다 (서버 파생 규칙과 같다)', () => {
    expect(permitsAnalytics(READY(true))).toBe(true);
    expect(permitsAnalytics(READY(false))).toBe(false);
    expect(permitsAnalytics(READY(true, true))).toBe(false);
    expect(permitsAnalytics({ status: 'unknown', categories: { analytics: true }, needsRenewal: false })).toBe(false);

    expect(new ConsentBridge(true, source(READY(true))).allowsSending()).toBe(true);
    expect(new ConsentBridge(true, source(READY(false))).allowsSending()).toBe(false);
  });

  // @scenario mode=consent, exclusion=consent
  // @effects consent_bridge_blocks_sending_until_consent_is_known
  it('런타임이 없으면 게이트가 선 상태에서 보내지 않는다 (판정할 곳이 없다)', () => {
    const bridge = new ConsentBridge(true, null);

    expect(bridge.allowsSending()).toBe(false);
    expect(bridge.isPending()).toBe(false);
  });

  // @scenario mode=consent
  // @effects consent_bridge_updates_on_consent_change_events
  it('판정이 끝날 때마다 알리고, 이미 끝나 있으면 시작하자마자 알린다', async () => {
    const onSettled = vi.fn();
    const pending = source({ status: 'unknown', categories: {}, needsRenewal: false }, true);
    const bridge = new ConsentBridge(true, pending, onSettled);

    await bridge.start();
    expect(onSettled).not.toHaveBeenCalled();

    pending.settle(READY(true));
    expect(onSettled).toHaveBeenCalledTimes(1);
    expect(bridge.allowsSending()).toBe(true);

    bridge.stop();
    pending.settle(READY(false));
    expect(onSettled, '해제 뒤에는 알리지 않는다').toHaveBeenCalledTimes(1);

    const settledAlready = vi.fn();
    await new ConsentBridge(true, source(READY(true)), settledAlready).start();
    expect(settledAlready).toHaveBeenCalledTimes(1);
  });

  // @scenario mode=consent, visitor=member
  // @effects consent_lookup_identifies_the_logged_in_member
  it('회원 판별은 런타임이 맡는다 — 브리지는 토큰을 읽거나 직접 조회하지 않는다', () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch' as never);

    const bridge = new ConsentBridge(true, source(READY(true)));
    bridge.allowsSending();

    expect(fetchSpy).not.toHaveBeenCalled();
    fetchSpy.mockRestore();
  });
});
