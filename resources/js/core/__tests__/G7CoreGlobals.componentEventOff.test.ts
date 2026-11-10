/**
 * @file G7CoreGlobals.componentEventOff.test.ts
 * @description `G7Core.componentEvent.off` 가 다른 주인의 구독까지 지우던 결함 (engine-v1.93.0, #816 S1 F6·D6)
 *
 * 결함:
 *   `off(name)` 은 이름 하나에 걸린 리스너 Set 을 통째로 지웠다. 같은 이벤트를 두 컴포넌트·확장이 구독하면
 *   한쪽이 자기 구독을 끊으려고 `off` 를 부르는 순간 다른 쪽 구독도 사라졌다. `clear()` 도 같다.
 *
 * 수정 (D6):
 *   - `off(name, callback)` 을 새로 받아 그 콜백만 지운다.
 *   - 콜백 없는 `off(name)` 은 종전처럼 전부 지우되, 구독이 1개 이상이면 `[G7]` 경고를 낸다(폐지 예고).
 *   - `clear()` 도 구독이 1개 이상이면 같은 취지로 경고한다.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { initializeG7CoreGlobals } from '../template-engine/G7CoreGlobals';
import type { G7CoreDependencies } from '../template-engine/G7CoreGlobals';

vi.mock('../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({ get: vi.fn() })),
}));

/** G7Core 전역을 세우는 최소 의존성 */
function deps(): G7CoreDependencies {
  return {
    getState: vi.fn(() => ({
      translationEngine: { translate: vi.fn((key: string) => key) } as any,
      translationContext: { templateId: 't', locale: 'ko' },
      bindingEngine: {} as any,
      actionDispatcher: {} as any,
      templateMetadata: { locales: ['ko'] },
    })),
    transitionManager: { getIsPending: vi.fn(() => false), subscribe: vi.fn(() => () => {}) },
    responsiveManager: {},
    webSocketManager: {} as any,
  };
}

/** `[G7]` 접두 경고만 골라낸다. */
function g7Warnings(spy: ReturnType<typeof vi.spyOn>): string[] {
  return spy.mock.calls
    .map((args: unknown[]) => args.map((a) => String(a)).join(' '))
    .filter((msg: string) => msg.startsWith('[G7]'));
}

describe('G7Core.componentEvent.off — 구독자별 해제 (engine-v1.93.0)', () => {
  let G7Core: any;
  let warnSpy: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    delete (window as any).G7Core;
    initializeG7CoreGlobals(deps());
    G7Core = (window as any).G7Core;
    warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
  });

  afterEach(() => {
    warnSpy.mockRestore();
    delete (window as any).G7Core;
  });

  // @scenario case=event_off_c01
  // @effects component_event_off_removes_only_given_callback
  it('off(name, callback) 은 그 콜백만 지우고 다른 구독자는 계속 받는다', async () => {
    const mine = vi.fn(() => 'mine');
    const theirs = vi.fn(() => 'theirs');
    G7Core.componentEvent.on('probe:e', mine);
    G7Core.componentEvent.on('probe:e', theirs);

    G7Core.componentEvent.off('probe:e', mine);
    const results = await G7Core.componentEvent.emit('probe:e');

    expect(results).toEqual(['theirs']);
    expect(mine).not.toHaveBeenCalled();
    expect(theirs).toHaveBeenCalledTimes(1);
    expect(g7Warnings(warnSpy)).toEqual([]);
  });

  // @scenario case=event_off_c02
  // @effects component_event_off_removes_only_given_callback
  it('마지막 콜백을 off(name, callback) 으로 지우면 그 이벤트는 구독자가 없다', async () => {
    const only = vi.fn();
    G7Core.componentEvent.on('probe:e', only);

    G7Core.componentEvent.off('probe:e', only);

    expect(await G7Core.componentEvent.emit('probe:e')).toEqual([]);
    expect(g7Warnings(warnSpy)).toEqual([]);
  });

  // @scenario case=event_off_c03
  // @effects component_event_off_removes_only_given_callback
  it('콜백 없는 off(name) 은 종전처럼 전부 지우되 경고 1건을 낸다 (지운 수 포함)', async () => {
    G7Core.componentEvent.on('probe:e', vi.fn());
    G7Core.componentEvent.on('probe:e', vi.fn());

    G7Core.componentEvent.off('probe:e');

    expect(await G7Core.componentEvent.emit('probe:e')).toEqual([]);
    const w = g7Warnings(warnSpy);
    expect(w).toHaveLength(1);
    expect(w[0]).toContain('probe:e');
    expect(w[0]).toContain('2');
  });

  // @scenario case=event_off_c04
  // @effects component_event_off_removes_only_given_callback
  it('구독자가 없는 이름의 콜백 없는 off 는 경고하지 않는다', () => {
    G7Core.componentEvent.off('nobody:e');

    expect(g7Warnings(warnSpy)).toEqual([]);
  });

  // @scenario case=event_off_c05
  // @effects component_event_off_removes_only_given_callback
  it('clear() 는 구독이 있으면 경고 1건 후 전부 지운다', async () => {
    const a = vi.fn();
    G7Core.componentEvent.on('e1', a);
    G7Core.componentEvent.on('e2', vi.fn());

    G7Core.componentEvent.clear();

    await G7Core.componentEvent.emit('e1');
    expect(a).not.toHaveBeenCalled();
    expect(g7Warnings(warnSpy)).toHaveLength(1);
  });

  // @scenario case=event_off_c06
  // @effects component_event_off_removes_only_given_callback
  it('구독이 없을 때 clear() 는 경고하지 않는다', () => {
    G7Core.componentEvent.clear();

    expect(g7Warnings(warnSpy)).toEqual([]);
  });

  // @scenario case=event_off_c07
  // @effects component_event_off_removes_only_given_callback
  it('on 이 돌려준 해제 함수는 종전대로 자기 콜백만 지운다', async () => {
    const mine = vi.fn(() => 1);
    const theirs = vi.fn(() => 2);
    const off = G7Core.componentEvent.on('probe:e', mine);
    G7Core.componentEvent.on('probe:e', theirs);

    off();

    expect(await G7Core.componentEvent.emit('probe:e')).toEqual([2]);
  });
});
