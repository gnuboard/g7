/**
 * 표현식 컴파일 캐시(전처리·변수 추출) 회귀 테스트 — 공개 이슈 140
 *
 * `evaluateExpression` 은 호출마다 정규식 전처리 7회와 변수 추출을 처음부터 했다. 이 둘은
 * 입력 문자열만으로 결과가 정해지는 순수 함수라 문자열 키로 기억해도 된다. 컨텍스트에 따라
 * 달라지는 값(미정의 변수 주입·`$t`·`$localized`·로케일)은 캐시하지 않는다.
 *
 * computed 경로는 렌더러마다 새 엔진 인스턴스를 만들었으므로 캐시는 모듈 스코프여야 적중한다 —
 * 아래 테스트는 **서로 다른 엔진 인스턴스**로 같은 식을 평가한다.
 *
 * @since engine-v1.74.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { DataBindingEngine } from '../DataBindingEngine';
import {
  getCompiledExpression,
  getExpressionCompileCacheStats,
  __resetExpressionCompileCacheForTests,
  EXPRESSION_COMPILE_CACHE_LIMIT,
} from '../ExpressionCompileCache';
import { setEngineFlags, __resetEngineFlagsForTests } from '../EngineFlags';

describe('ExpressionCompileCache — 전처리·변수 추출 캐시', () => {
  beforeEach(() => {
    __resetExpressionCompileCacheForTests();
    __resetEngineFlagsForTests();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    __resetEngineFlagsForTests();
  });

  /**

   * @scenario layer=expression_cache

   * @effects compile_cache_reuses_preprocess_across_instances

   */
  it('같은 식을 서로 다른 엔진 인스턴스로 2회 평가해도 전처리는 1회만 돈다', () => {
    const spy = vi.spyOn(DataBindingEngine.prototype as any, 'preprocessOptionalChaining');
    const expr = "_local.form.name + '-' + (_local.form.code ?? 'none')";

    const a = new DataBindingEngine().evaluateExpression(expr, { _local: { form: { name: 'a' } } });
    const b = new DataBindingEngine().evaluateExpression(expr, { _local: { form: { name: 'b', code: 'c' } } });

    expect(a).toBe('a-none');
    expect(b).toBe('b-c');
    expect(spy).toHaveBeenCalledTimes(1);
    const stats = getExpressionCompileCacheStats();
    expect(stats.misses).toBe(1);
    expect(stats.hits).toBe(1);
  });

  /**

   * @scenario layer=expression_cache

   * @effects compile_cache_holds_no_values

   */
  it('값을 담지 않는다 — 같은 식이 컨텍스트를 따라 다른 값을 낸다', () => {
    const engine = new DataBindingEngine();
    expect(engine.evaluateExpression('user.name', { user: { name: '가' } })).toBe('가');
    expect(engine.evaluateExpression('user.name', { user: { name: '나' } })).toBe('나');
    // 첫 호출에 없던 변수가 두 번째에 들어와도 미정의 변수 주입은 매번 새로 한다
    expect(engine.evaluateExpression('missing ?? 1', {})).toBe(1);
    expect(engine.evaluateExpression('missing ?? 1', { missing: 7 })).toBe(7);
  });

  /**

   * @scenario layer=expression_cache

   * @effects compile_cache_holds_no_values

   */
  it('$t 번역·로케일은 캐시되지 않는다 — 같은 식이 로케일을 따라간다', () => {
    const engine = new DataBindingEngine();
    const ko = engine.evaluateExpression("$localized(label)", { label: { ko: '한', en: 'en' }, $locale: 'ko' });
    const en = engine.evaluateExpression("$localized(label)", { label: { ko: '한', en: 'en' }, $locale: 'en' });
    expect(ko).toBe('한');
    expect(en).toBe('en');
  });

  /**

   * @scenario layer=expression_cache

   * @effects ast_is_deep_frozen

   */
  it('결과 객체와 변수 목록은 동결된다', () => {
    const compiled = getCompiledExpression('a.b + c', (e) => ({ processed: e, variables: ['a', 'c'] }));
    expect(Object.isFrozen(compiled)).toBe(true);
    expect(Object.isFrozen(compiled.variables)).toBe(true);
  });

  /**

   * @scenario layer=expression_cache

   * @effects caches_are_bounded_fifo

   */
  it('상한을 넘으면 가장 먼저 들어온 항목부터 제거한다', () => {
    const compile = vi.fn((e: string) => ({ processed: e, variables: [] as string[] }));
    for (let i = 0; i <= EXPRESSION_COMPILE_CACHE_LIMIT; i++) {
      getCompiledExpression(`x${i}`, compile);
    }
    expect(getExpressionCompileCacheStats().size).toBe(EXPRESSION_COMPILE_CACHE_LIMIT);
    compile.mockClear();
    getCompiledExpression('x0', compile); // 제거됐으므로 다시 컴파일
    getCompiledExpression(`x${EXPRESSION_COMPILE_CACHE_LIMIT}`, compile); // 남아 있음
    expect(compile).toHaveBeenCalledTimes(1);
    expect(compile).toHaveBeenCalledWith('x0');
  });

  /**

   * @scenario layer=expression_cache

   * @effects engine_flags_off_restores_prior_path

   */
  it('스위치를 끄면 캐시를 거치지 않고 매번 전처리한다(수정 전 경로)', () => {
    setEngineFlags({ expressionCompileCache: false });
    const spy = vi.spyOn(DataBindingEngine.prototype as any, 'preprocessOptionalChaining');
    const engine = new DataBindingEngine();
    engine.evaluateExpression('x.y', { x: { y: 1 } });
    engine.evaluateExpression('x.y', { x: { y: 2 } });
    expect(spy).toHaveBeenCalledTimes(2);
    expect(getExpressionCompileCacheStats().size).toBe(0);
  });

  /**

   * @scenario layer=expression_cache

   * @effects compile_cache_reuses_preprocess_across_instances

   */
  it('computed 경로가 넘기는 3번째 인자 skipCache 는 컴파일 캐시를 우회시키지 않는다', () => {
    const spy = vi.spyOn(DataBindingEngine.prototype as any, 'preprocessOptionalChaining');
    const engine = new DataBindingEngine();
    engine.evaluateExpression('p.q', { p: { q: 1 } }, { skipCache: true });
    engine.evaluateExpression('p.q', { p: { q: 2 } }, { skipCache: true });
    expect(spy).toHaveBeenCalledTimes(1);
  });

  /**

   * @scenario layer=expression_cache

   * @effects ast_cache_rethrows_same_error_every_hit

   */
  it('평가 실패 메시지는 캐시 적중 여부와 무관하게 같다', () => {
    const engine = new DataBindingEngine();
    let first = '';
    let second = '';
    try { engine.evaluateExpression('[].reduce((a, b) => a)', {}); } catch (e) { first = (e as Error).message; }
    try { engine.evaluateExpression('[].reduce((a, b) => a)', {}); } catch (e) { second = (e as Error).message; }
    expect(first).toContain('Failed to evaluate expression');
    expect(second).toBe(first);
  });
});
