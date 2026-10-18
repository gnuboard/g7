/**
 * TranslationEngine.replaceParams 정규식 재사용 회귀 테스트 — 공개 이슈 140
 *
 * 파라미터마다 `new RegExp` 2개를 매 호출 새로 만들었다. 키별 정규식 쌍을 모듈 스코프에
 * 보관해 재사용한다. 키를 정규식 이스케이프하지 않는 **현행 동작은 그대로** 두고,
 * `new RegExp` 가 던지는 키는 캐시에 넣지 않아 현행과 같은 예외가 난다.
 *
 * @since engine-v1.74.0
 */

import { describe, it, expect, beforeEach } from 'vitest';
import {
  TranslationEngine,
  getReplaceParamsRegexCacheStats,
  __resetReplaceParamsRegexCacheForTests,
  REPLACE_PARAMS_REGEX_CACHE_LIMIT,
} from '../TranslationEngine';

describe('TranslationEngine.replaceParams — 정규식 캐시', () => {
  let engine: TranslationEngine;
  const replace = (text: string, params: Record<string, unknown>): string => (engine as any).replaceParams(text, params);

  beforeEach(() => {
    engine = new TranslationEngine();
    __resetReplaceParamsRegexCacheForTests();
  });

  /**

   * @scenario layer=translation

   * @effects replace_params_regex_reused_behavior_preserved

   */
  it('같은 키는 정규식을 재사용한다(미스 1·적중 N-1)', () => {
    for (let i = 0; i < 4; i++) {
      expect(replace('총 {count}개 / {{count}}건', { count: i })).toBe(`총 ${i}개 / ${i}건`);
    }
    const s = getReplaceParamsRegexCacheStats();
    expect(s.misses).toBe(1);
    expect(s.hits).toBe(3);
  });

  /**

   * @scenario layer=translation

   * @effects replace_params_regex_reused_behavior_preserved

   */
  it('접두 관계 키 {a}·{ab} 가 서로 오염시키지 않는다', () => {
    expect(replace('{a}-{ab}-{{a}}-{{ab}}', { a: 1, ab: 2 })).toBe('1-2-1-2');
    expect(replace('{ab}-{a}', { ab: 'X', a: 'Y' })).toBe('X-Y');
  });

  /**

   * @scenario layer=translation

   * @effects replace_params_regex_reused_behavior_preserved

   */
  it('정규식 메타문자 키의 현행 결과를 보존한다(이스케이프하지 않음)', () => {
    // `.` 은 임의 문자와 매치 — 현행 동작 그대로
    expect(replace('{a.b} {aXb}', { 'a.b': 'v' })).toBe('v v');
    // 같은 입력을 다시 넣어도 같은 결과(lastIndex 잔류 없음)
    expect(replace('{a.b} {aXb}', { 'a.b': 'v' })).toBe('v v');
  });

  /**

   * @scenario layer=translation

   * @effects replace_params_regex_reused_behavior_preserved

   */
  it("정규식으로 만들 수 없는 키 '(x' 는 현행과 같은 예외를 내고 캐시에 남지 않는다", () => {
    const run = () => replace('{(x}', { '(x': 1 });
    expect(run).toThrow(SyntaxError);
    expect(run).toThrow(SyntaxError);
    expect(getReplaceParamsRegexCacheStats().size).toBe(0);
  });

  /**

   * @scenario layer=translation

   * @effects caches_are_bounded_fifo

   */
  it('상한을 넘으면 가장 먼저 들어온 키부터 제거한다', () => {
    for (let i = 0; i <= REPLACE_PARAMS_REGEX_CACHE_LIMIT; i++) replace(`{k${i}}`, { [`k${i}`]: i });
    expect(getReplaceParamsRegexCacheStats().size).toBe(REPLACE_PARAMS_REGEX_CACHE_LIMIT);
    const misses = getReplaceParamsRegexCacheStats().misses;
    expect(replace('{k0}', { k0: 'z' })).toBe('z');
    expect(getReplaceParamsRegexCacheStats().misses).toBe(misses + 1);
  });

  /**

   * @scenario layer=translation

   * @effects replace_params_regex_reused_behavior_preserved

   */
  it('치환 값의 $ 패턴 처리는 현행(String.prototype.replace 문자열 치환) 그대로', () => {
    expect(replace('{p}', { p: '$&!' })).toBe('{p}!');
  });
});
