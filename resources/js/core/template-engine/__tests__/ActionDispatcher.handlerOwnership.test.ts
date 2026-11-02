/**
 * ActionDispatcher.handlerOwnership.test.ts
 *
 * 핸들러 이름의 소유자 간 간섭을 실행 중 경고로 드러내는 계약을 잠근다 (engine-v1.87.0, #816 S1).
 *
 * ## 무엇이 깨져 있었나
 *
 *   1) 핸들러는 하나의 Map 에 담기고 같은 이름이면 마지막 등록이 **조용히** 이긴다. 엔진 기본 핸들러
 *      18종(`refresh`·`refetchDataSource` …)도 확장·템플릿이 같은 이름으로 등록하면 덮인다.
 *   2) `executeAction` 의 switch 가 직접 처리하는 이름(`navigate`·`setState`·`toast` …)은 Map 을
 *      보지 않으므로, 그 이름으로 등록한 핸들러는 **영원히 호출되지 않는다** — 그래도 아무 신호가 없다.
 *   3) `unregisterHandler` 로 엔진 기본 핸들러를 누구나 지울 수 있다.
 *   4) 남의 이름공간(`sirsoft-basic.*`)에 다른 주인이 등록해도 드러나지 않는다.
 *
 * ## 이 파일이 잠그는 것 (D3·D4 — 실행 동작은 그대로, 경고만 더한다)
 *
 *   - 위 네 경우 각각 `[G7]` 접두 `console.warn` 1건 (디버그 모드 무관)
 *   - 같은 (이름, 등록자) 쌍은 1회만
 *   - 같은 주인의 재등록(로케일 전환 재등록)·같은 함수의 재등록은 무경고
 *   - 경고가 나도 등록·해제 동작은 종전과 같다
 *
 * 실물 `ActionDispatcher` 를 쓴다 — 경고 판정은 기본 등록 18종의 등록자 기억에 기대므로 모의로는 잠글 수 없다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';

/** `[G7]` 접두 경고만 골라낸다 — 다른 경고(로거 등)와 섞지 않는다. */
function g7Warnings(spy: ReturnType<typeof vi.spyOn>): string[] {
  return spy.mock.calls
    .map((args: unknown[]) => args.map((a) => String(a)).join(' '))
    .filter((msg: string) => msg.startsWith('[G7]'));
}

describe('ActionDispatcher 핸들러 소유권 경고 (engine-v1.87.0)', () => {
  let warnSpy: ReturnType<typeof vi.spyOn>;
  let dispatcher: ActionDispatcher;

  beforeEach(() => {
    warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
  });

  afterEach(() => {
    warnSpy.mockRestore();
  });

  // @scenario case=handler_owner_c01
  // @effects handler_overwrite_warned_with_owner_once
  it('엔진 기본 핸들러 등록은 경고를 내지 않는다', () => {
    expect(g7Warnings(warnSpy)).toEqual([]);
  });

  // @scenario case=handler_owner_c02
  // @effects handler_overwrite_warned_with_owner_once
  it('엔진 기본 핸들러의 등록자는 engine 으로 기억된다', () => {
    expect((dispatcher as any).getHandlerSource?.('refresh')).toBe('engine');
    expect((dispatcher as any).getHandlerSource?.('refetchDataSource')).toBe('engine');
  });

  // @scenario case=handler_owner_c03
  // @effects handler_overwrite_warned_with_owner_once
  it('엔진 이름 목록 상수 — Map 18종은 실제 기본 등록과 같고, switch 이름은 Map 과 겹치지 않는다', async () => {
    const mod: any = await import('../ActionDispatcher');
    const mapNames: ReadonlySet<string> | undefined = mod.ENGINE_MAP_HANDLERS;
    const switchNames: ReadonlySet<string> | undefined = mod.ENGINE_SWITCH_HANDLERS;

    expect(mapNames).toBeDefined();
    expect(switchNames).toBeDefined();
    expect([...mapNames!].sort()).toEqual([...dispatcher.getRegisteredHandlers()].sort());
    expect(mapNames!.size).toBe(18);
    for (const name of ['navigate', 'setState', 'toast', 'openModal', 'apiCall', 'sequence']) {
      expect(switchNames!.has(name)).toBe(true);
    }
    for (const name of switchNames!) {
      expect(mapNames!.has(name)).toBe(false);
    }
    // DevTools 메타 목록도 같은 한 곳에서 나온다 — 종전 13종(5종 누락)
    expect((mod.ENGINE_MAP_HANDLER_META ?? []).map((m: { name: string }) => m.name).sort()).toEqual([...mapNames!].sort());
  });

  // @scenario case=handler_owner_c04
  // @effects handler_overwrite_warned_with_owner_once
  it('switch 가 처리하는 이름을 등록하면 「호출되지 않는다」 경고 1건 — 등록 자체는 그대로 된다', () => {
    const fn = vi.fn();
    dispatcher.registerHandler('navigate', fn, { source: 'probe' });

    const w = g7Warnings(warnSpy);
    expect(w).toHaveLength(1);
    expect(w[0]).toContain('navigate');
    expect(w[0]).toContain('probe');
    expect(w[0]).toContain('호출되지 않');
    expect(dispatcher.getHandler('navigate')).toBe(fn);
  });

  // @scenario case=handler_owner_c05
  // @effects handler_overwrite_warned_with_owner_once
  it('엔진 기본 핸들러를 다른 등록자가 덮으면 경고 1건 — 덮어쓰기 동작은 그대로다', () => {
    const fn = vi.fn();
    dispatcher.registerHandler('refresh', fn, { source: 'probe' });

    const w = g7Warnings(warnSpy);
    expect(w).toHaveLength(1);
    expect(w[0]).toContain('refresh');
    expect(w[0]).toContain('probe');
    expect(w[0]).toContain('엔진 기본 핸들러');
    expect(dispatcher.getHandler('refresh')).toBe(fn);
  });

  // @scenario case=handler_owner_c06
  // @effects handler_overwrite_warned_with_owner_once
  it('다른 등록자가 등록한 이름을 덮으면 경고 1건 — 기존 등록자와 새 등록자를 함께 적는다', () => {
    dispatcher.registerHandler('acme.copy', vi.fn(), { source: 'acme' });
    warnSpy.mockClear();

    dispatcher.registerHandler('acme.copy', vi.fn(), { source: 'other-ext' });

    const w = g7Warnings(warnSpy);
    expect(w).toHaveLength(1);
    expect(w[0]).toContain('acme');
    expect(w[0]).toContain('other-ext');
  });

  // @scenario case=handler_owner_c07
  // @effects handler_overwrite_warned_with_owner_once
  it('남의 이름공간으로 등록하면 경고 1건 (기존 등록이 없어도)', () => {
    dispatcher.registerHandler('sirsoft-basic.newThing', vi.fn(), { source: 'acme' });

    const w = g7Warnings(warnSpy);
    expect(w).toHaveLength(1);
    expect(w[0]).toContain('sirsoft-basic');
    expect(w[0]).toContain('acme');
  });

  // @scenario case=handler_owner_c08
  // @effects handler_overwrite_warned_with_owner_once
  it('템플릿 핸들러를 다른 등록자가 덮으면 두 등록자 이름이 모두 한 건에 담긴다 (실측 T2 와 같은 모양)', () => {
    dispatcher.registerHandler('sirsoft-basic.formatCurrency', vi.fn(), { source: 'sirsoft-basic' });
    warnSpy.mockClear();

    dispatcher.registerHandler('sirsoft-basic.formatCurrency', () => {}, { source: 'probe' });

    const w = g7Warnings(warnSpy);
    expect(w).toHaveLength(1);
    expect(w[0]).toContain('sirsoft-basic');
    expect(w[0]).toContain('probe');
  });

  // @scenario case=handler_owner_c09
  // @effects handler_overwrite_warned_with_owner_once
  it('같은 (이름, 등록자) 쌍의 경고는 1회만 낸다', () => {
    dispatcher.registerHandler('sirsoft-basic.formatCurrency', vi.fn(), { source: 'sirsoft-basic' });
    warnSpy.mockClear();

    dispatcher.registerHandler('sirsoft-basic.formatCurrency', () => {}, { source: 'probe' });
    dispatcher.registerHandler('sirsoft-basic.formatCurrency', () => {}, { source: 'probe' });
    dispatcher.registerHandler('sirsoft-basic.formatCurrency', () => {}, { source: 'probe' });
    dispatcher.registerHandler('refresh', () => {}, { source: 'probe' });
    dispatcher.registerHandler('navigate', () => {}, { source: 'probe' });

    expect(g7Warnings(warnSpy)).toHaveLength(3);
  });

  // @scenario case=handler_owner_c10
  // @effects handler_overwrite_warned_with_owner_once
  it('같은 등록자가 다른 함수로 다시 등록하면 무경고 (로케일 전환·reloadPluginHandlers 재등록)', () => {
    dispatcher.registerHandler('acme.copy', vi.fn(), { source: 'acme' });
    dispatcher.registerHandler('acme.copy', vi.fn(), { source: 'acme' });

    expect(g7Warnings(warnSpy)).toEqual([]);
  });

  // @scenario case=handler_owner_c11
  // @effects handler_overwrite_warned_with_owner_once
  it('같은 함수 참조를 다른 등록자 표기로 다시 등록해도 덮어쓰기 경고는 없다 (initTemplate ↔ 코어 재등록)', () => {
    const fn = vi.fn();
    dispatcher.registerHandler('setTheme', fn, { source: 'sirsoft-basic' });
    dispatcher.registerHandler('setTheme', fn);

    expect(g7Warnings(warnSpy)).toEqual([]);
  });

  // @scenario case=handler_owner_c12
  // @effects handler_overwrite_warned_with_owner_once
  it('등록자 표기가 없는 템플릿 핸들러(점 없는 이름)는 무경고', () => {
    dispatcher.registerHandler('setTheme', vi.fn());
    dispatcher.registerHandler('initTheme', vi.fn());

    expect(g7Warnings(warnSpy)).toEqual([]);
  });

  // @scenario case=handler_owner_c13
  // @effects handler_overwrite_warned_with_owner_once
  it('엔진 이름을 engine 이 아닌 쪽이 해제하면 경고 1건 — 해제 동작은 그대로다', () => {
    dispatcher.unregisterHandler('refetchDataSource');

    const w = g7Warnings(warnSpy);
    expect(w).toHaveLength(1);
    expect(w[0]).toContain('refetchDataSource');
    expect(dispatcher.getHandler('refetchDataSource')).toBeUndefined();
  });

  // @scenario case=handler_owner_c14
  // @effects handler_overwrite_warned_with_owner_once
  it('engine 이 스스로 해제하거나 확장 이름을 해제하면 무경고', () => {
    dispatcher.registerHandler('acme.copy', vi.fn(), { source: 'acme' });
    dispatcher.unregisterHandler('acme.copy');
    dispatcher.unregisterHandler('suppress', { source: 'engine' } as any);

    expect(g7Warnings(warnSpy)).toEqual([]);
    expect(dispatcher.getHandler('acme.copy')).toBeUndefined();
  });

  // @scenario case=handler_owner_c15
  // @effects handler_overwrite_warned_with_owner_once
  it('switch 이름으로 등록한 핸들러는 실제로 호출되지 않는다 (경고 문구의 근거)', async () => {
    const fn = vi.fn();
    dispatcher.registerHandler('switch', fn, { source: 'probe' });

    await dispatcher.dispatchAction({ handler: 'switch', cases: {} } as any, {} as any).catch(() => undefined);

    expect(fn).not.toHaveBeenCalled();
  });
});
