/**
 * 안전 평가기 AST 캐시 회귀 테스트 — 공개 이슈 140
 *
 * `evaluateSafeExpression` 은 호출마다 토큰화·파싱을 처음부터 했다. AST 는 입력 문자열만으로
 * 정해지므로 문자열 키로 기억한다. 값은 캐시하지 않는다(같은 식이 컨텍스트를 따라 다른 값).
 * 파싱 실패도 기억하되 적중할 때마다 **같은 메시지로 새 Error 를 던진다** — 금지 구문 차단
 * 메시지를 단언하는 보안 회귀선과 "실패 시 원문 보존" 흐름이 그대로 동작해야 한다.
 * AST 는 재귀 동결 — 인터프리터가 노드를 변형하면 즉시 TypeError 로 드러난다.
 *
 * @since engine-v1.74.0
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import {
  evaluateSafeExpression,
  parseToAstCached,
  getAstCacheStats,
  __resetAstCacheForTests,
  AST_CACHE_LIMIT,
} from '../SafeExpressionEvaluator';
import { setEngineFlags, __resetEngineFlagsForTests } from '../EngineFlags';

const catchMessage = (fn: () => unknown): string => {
  try {
    fn();
  } catch (e) {
    return (e as Error).message;
  }
  return '<no throw>';
};

const isDeepFrozen = (v: unknown, seen = new Set<unknown>()): boolean => {
  if (v === null || typeof v !== 'object' || seen.has(v)) return true;
  seen.add(v);
  if (!Object.isFrozen(v)) return false;
  return Object.values(v as Record<string, unknown>).every((x) => isDeepFrozen(x, seen));
};

describe('SafeExpressionEvaluator — AST 캐시', () => {
  beforeEach(() => {
    __resetAstCacheForTests();
    __resetEngineFlagsForTests();
  });

  afterEach(() => {
    __resetEngineFlagsForTests();
  });

  /**

   * @scenario layer=ast_cache

   * @effects ast_cache_reuses_parse_without_value_caching

   */
  it('같은 식을 N회 평가하면 파싱은 1회(미스 1·적중 N-1)', () => {
    for (let i = 0; i < 5; i++) {
      expect(evaluateSafeExpression('a + b * 2', { a: i, b: 1 })).toBe(i + 2);
    }
    const s = getAstCacheStats();
    expect(s.misses).toBe(1);
    expect(s.hits).toBe(4);
  });

  /**

   * @scenario layer=ast_cache

   * @effects ast_cache_reuses_parse_without_value_caching

   */
  it('값 캐시가 아니다 — 같은 식이 컨텍스트를 따라간다', () => {
    expect(evaluateSafeExpression('items.filter(x => x > k).length', { items: [1, 2, 3], k: 1 })).toBe(2);
    expect(evaluateSafeExpression('items.filter(x => x > k).length', { items: [1, 2, 3], k: 2 })).toBe(1);
    // 배열·객체 리터럴은 평가마다 새 컨테이너 — 동결된 AST 가 값으로 새지 않는다
    const r1 = evaluateSafeExpression('[1, {a: 1}]', {}) as any[];
    const r2 = evaluateSafeExpression('[1, {a: 1}]', {}) as any[];
    expect(r1).not.toBe(r2);
    expect(Object.isFrozen(r1)).toBe(false);
    expect(Object.isFrozen(r1[1])).toBe(false);
    r1[1].a = 9;
    expect(r2[1].a).toBe(1);
  });

  /**

   * @scenario layer=ast_cache

   * @effects ast_cache_rethrows_same_error_every_hit

   */
  it('파싱 실패는 두 번 모두 같은 메시지로 던진다', () => {
    const m1 = catchMessage(() => evaluateSafeExpression('1 +', {}));
    const m2 = catchMessage(() => evaluateSafeExpression('1 +', {}));
    expect(m1).not.toBe('<no throw>');
    expect(m2).toBe(m1);
    expect(getAstCacheStats().hits).toBe(1);
  });

  /**

   * @scenario layer=ast_cache

   * @effects security_blocks_survive_cache_hits

   */
  it('금지 구문은 캐시 적중 뒤에도 매번 차단된다', () => {
    for (let i = 0; i < 2; i++) {
      expect(catchMessage(() => evaluateSafeExpression('a.__proto__', { a: {} }))).toBe('Access to "__proto__" is forbidden');
      expect(catchMessage(() => evaluateSafeExpression('1, 2', {}))).toBe('The comma/sequence operator is not allowed');
      expect(catchMessage(() => evaluateSafeExpression('a | b', { a: 1, b: 2 }))).toBe('Bitwise operators are not allowed');
      expect(catchMessage(() => evaluateSafeExpression('x += 1', {}))).toBe('Assignment operators are not allowed');
    }
  });

  /**

   * @scenario layer=ast_cache

   * @effects security_blocks_survive_cache_hits

   */
  it('런타임 차단(컨텍스트 의존)은 캐시와 무관하게 평가마다 돈다', () => {
    for (let i = 0; i < 2; i++) {
      expect(catchMessage(() => evaluateSafeExpression("''.constructor.constructor('return 1')()", {}))).toContain('forbidden');
      expect(catchMessage(() => evaluateSafeExpression('o[k]', { o: {}, k: 'constructor' }))).toContain('forbidden');
    }
  });

  /**

   * @scenario layer=ast_cache

   * @effects ast_is_deep_frozen

   */
  it('AST 는 재귀 동결된다(템플릿 리터럴 보간부 포함)', () => {
    const ast = parseToAstCached('`a${x.y}b${[1, (z) => z + 1]}` + fn(...args)');
    expect(isDeepFrozen(ast)).toBe(true);
    // 보간부 하위 식도 같은 캐시를 탄다
    expect(getAstCacheStats().size).toBeGreaterThanOrEqual(3);
  });

  /**

   * @scenario layer=ast_cache

   * @effects caches_are_bounded_fifo

   */
  it('상한을 넘으면 가장 먼저 들어온 항목부터 제거한다', () => {
    for (let i = 0; i <= AST_CACHE_LIMIT; i++) parseToAstCached(`v${i} + 1`);
    expect(getAstCacheStats().size).toBe(AST_CACHE_LIMIT);
    const before = getAstCacheStats().misses;
    parseToAstCached('v0 + 1');
    expect(getAstCacheStats().misses).toBe(before + 1);
  });

  /**

   * @scenario layer=ast_cache

   * @effects engine_flags_off_restores_prior_path

   */
  it('스위치를 끄면 매번 파싱하고 캐시에 넣지 않는다(수정 전 경로)', () => {
    setEngineFlags({ astCache: false });
    evaluateSafeExpression('a + 1', { a: 1 });
    evaluateSafeExpression('a + 1', { a: 2 });
    expect(getAstCacheStats().size).toBe(0);
    expect(getAstCacheStats().hits).toBe(0);
  });
});
