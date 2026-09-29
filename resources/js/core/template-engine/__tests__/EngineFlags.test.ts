/**
 * 엔진 안전 스위치(EngineFlags) 회귀 테스트
 *
 * 표현식 컴파일 캐시·AST 캐시·computed 스코프 물려받기는 브라우저 쪽에서만 끌 수 있다
 * (`G7Core.engineFlags.set(...)`). 기본값은 셋 다 켜짐, 그림자 검증은 꺼짐이다.
 * 첫 조회 때 `window.G7Config.engineFlags` 가 있으면 한 번 덮어쓴다(모듈 스코프 캐시는
 * 엔진 init 전에도 쓰이므로 init 시점 병합으로는 늦다).
 *
 * @since engine-v1.74.0
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import {
  getEngineFlags,
  setEngineFlags,
  __resetEngineFlagsForTests,
} from '../EngineFlags';

describe('EngineFlags — 기본값과 병합', () => {
  beforeEach(() => {
    delete (window as any).G7Config;
    __resetEngineFlagsForTests();
  });

  afterEach(() => {
    delete (window as any).G7Config;
    __resetEngineFlagsForTests();
  });

  /**

   * @scenario layer=engine_flags

   * @effects engine_flags_default_and_config_merge

   */
  it('기본값: 캐시 2종·물려받기는 켜짐, 그림자 검증은 꺼짐', () => {
    expect(getEngineFlags()).toEqual({
      expressionCompileCache: true,
      astCache: true,
      computedScopeInheritance: true,
      verifyComputedInheritance: false,
    });
  });

  /**

   * @scenario layer=engine_flags

   * @effects engine_flags_default_and_config_merge

   */
  it('첫 조회 때 G7Config.engineFlags 를 한 번 병합한다', () => {
    (window as any).G7Config = { engineFlags: { astCache: false, unknownKey: true } };
    const flags = getEngineFlags();
    expect(flags.astCache).toBe(false);
    expect(flags.expressionCompileCache).toBe(true);
    // 알 수 없는 키는 싣지 않는다
    expect((flags as any).unknownKey).toBeUndefined();

    // 병합은 1회 — 이후 G7Config 를 바꿔도 반영되지 않는다(set 으로만 바꾼다)
    (window as any).G7Config.engineFlags.astCache = true;
    expect(getEngineFlags().astCache).toBe(false);
  });

  /**

   * @scenario layer=engine_flags

   * @effects engine_flags_default_and_config_merge

   */
  it('set 은 알려진 boolean 키만 반영하고 반환 객체는 읽기 전용이다', () => {
    setEngineFlags({ computedScopeInheritance: false, verifyComputedInheritance: true, bogus: 1 } as any);
    const flags = getEngineFlags();
    expect(flags.computedScopeInheritance).toBe(false);
    expect(flags.verifyComputedInheritance).toBe(true);
    expect((flags as any).bogus).toBeUndefined();
    expect(Object.isFrozen(flags)).toBe(true);

    setEngineFlags({ computedScopeInheritance: 'no' as any });
    expect(getEngineFlags().computedScopeInheritance).toBe(false);
  });

  /**

   * @scenario layer=engine_flags

   * @effects engine_flags_default_and_config_merge

   */
  it('테스트 리셋은 기본값으로 되돌린다', () => {
    setEngineFlags({ expressionCompileCache: false });
    __resetEngineFlagsForTests();
    expect(getEngineFlags().expressionCompileCache).toBe(true);
  });

  /**
   * @scenario layer=engine_flags
   * @effects engine_flags_default_and_config_merge
   */
  it('G7Core.engineFlags 로 브라우저 쪽에서 읽고 끌 수 있다', async () => {
    const { initializeG7CoreGlobals } = await import('../G7CoreGlobals');
    const saved = (window as any).G7Core;
    delete (window as any).G7Core;
    initializeG7CoreGlobals({ getState: () => ({}) } as any);
    const G7Core = (window as any).G7Core;
    expect(G7Core.engineFlags.get()).toEqual(getEngineFlags());
    G7Core.engineFlags.set({ computedScopeInheritance: false });
    expect(getEngineFlags().computedScopeInheritance).toBe(false);
    (window as any).G7Core = saved;
  });
});
