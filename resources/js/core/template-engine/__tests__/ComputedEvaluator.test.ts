/**
 * computed 공용 평가기 회귀 테스트 — 공개 이슈 140
 *
 * computed 를 계산하는 지점(렌더·액션 sequence 2곳·라우트 진입·편집기 미리보기)이 서로 다른
 * 규칙을 썼다: 렌더는 trim 없음·computed 간 참조가 한 박자 늦음, 액션은 `$switch`·리터럴 키를
 * 떨어뜨림, 진입은 파이프·`$t` 미처리. 공용 평가기로 한 규칙을 쓴다 —
 * 정의 순서대로 평가하고 앞에 정의된 computed 는 최신 값, 뒤에 정의된 키를 읽으면 직전 값.
 *
 * @since engine-v1.74.0
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { DataBindingEngine } from '../DataBindingEngine';
import {
  evaluateComputedEntry,
  evaluateComputedDefinitions,
  isInheritableDefinitions,
} from '../helpers/ComputedEvaluator';
import { Logger } from '../../utils/Logger';

describe('ComputedEvaluator — 평가 규칙', () => {
  let engine: DataBindingEngine;

  beforeEach(() => {
    engine = new DataBindingEngine();
  });

  /**

   * @scenario layer=computed_evaluator

   * @effects computed_definition_order_reference

   */
  it('정의 순서 참조: 앞에 정의된 computed 는 같은 계산에서 최신 값', () => {
    const out = evaluateComputedDefinitions(
      { base: '{{_local.n * 10}}', mid: '{{_computed.base + 1}}', alias: '{{$computed.mid + 1}}' },
      { _local: { n: 2 } },
      engine,
      { previous: { base: 10, mid: 11, alias: 12 }, onError: 'keep-previous' }
    );
    expect(out).toEqual({ base: 20, mid: 21, alias: 22 });
  });

  /**

   * @scenario layer=computed_evaluator

   * @effects computed_later_key_reads_previous

   */
  it('뒤에 정의된 키를 읽으면 previous 값(직전 값)을 본다', () => {
    const out = evaluateComputedDefinitions(
      { total: '{{($computed.sub ?? 0) + 3000}}', sub: '{{_local.qty * 1000}}' },
      { _local: { qty: 2 } },
      engine,
      { previous: { sub: 1000 }, onError: 'keep-previous' }
    );
    expect(out).toEqual({ total: 4000, sub: 2000 });
  });

  /**

   * @scenario layer=computed_evaluator

   * @effects computed_switch_pipe_literal_trim_rules

   */
  it('$switch 객체 정의를 평가한다($cases 가 없어도 $default)', () => {
    const out = evaluateComputedDefinitions(
      {
        s1: { $switch: '{{_local.mode}}', $cases: { a: 'A', b: 'B' }, $default: 'X' },
        s2: { $switch: '{{_local.mode}}', $default: 'D' },
      },
      { _local: { mode: 'b' } },
      engine,
      { onError: 'undefined' }
    );
    expect(out).toEqual({ s1: 'B', s2: 'D' });
  });

  /**

   * @scenario layer=computed_evaluator

   * @effects computed_switch_pipe_literal_trim_rules

   */
  it('파이프 정의는 파이프로 평가한다(비트 OR 로 보지 않는다)', () => {
    const out = evaluateComputedDefinitions(
      { up: "{{_local.name | default('none') | uppercase}}" },
      { _local: { name: 'abc' } },
      engine,
      { onError: 'undefined' }
    );
    expect(out.up).toBe('ABC');
  });

  /**

   * @scenario layer=computed_evaluator

   * @effects computed_switch_pipe_literal_trim_rules

   */
  it('{{}} 가 아닌 문자열은 원문 그대로, 그 밖의 타입은 키를 만들지 않는다', () => {
    const out = evaluateComputedDefinitions(
      { lit: 'profile', num: 3 as any, nil: null as any, arr: ['a'] as any },
      {},
      engine,
      { onError: 'undefined' }
    );
    expect(out).toEqual({ lit: 'profile' });
  });

  /**

   * @scenario layer=computed_evaluator

   * @effects computed_switch_pipe_literal_trim_rules

   */
  it('앞뒤 공백을 떼고 {{}} 이면 식으로 평가한다', () => {
    const out = evaluateComputedDefinitions({ ws: ' {{_local.v + 1}} \n' }, { _local: { v: 1 } }, engine, { onError: 'undefined' });
    expect(out.ws).toBe(2);
  });

  /**

   * @scenario layer=computed_evaluator

   * @effects computed_failure_policy_per_site

   */
  it('혼합 문자열 "{{a}} {{b}}" 는 단일 바인딩이 아니므로 실패 처리(종전 파싱 실패와 같은 결과)', () => {
    const failureLog = vi.fn();
    const out = evaluateComputedDefinitions(
      { mixed: '{{_local.a}} {{_local.b}}' },
      { _local: { a: 1, b: 2 } },
      engine,
      { previous: { mixed: 'prev' }, onError: 'keep-previous', failureLog }
    );
    expect(out.mixed).toBe('prev');
    expect(failureLog).toHaveBeenCalledTimes(1);
  });

  /**

   * @scenario layer=computed_evaluator

   * @effects computed_failure_policy_per_site

   */
  it('실패 정책: keep-previous 는 직전 값, undefined 는 undefined — 다른 키는 정상 평가', () => {
    const defs = { bad: '{{[].reduce((a, b) => a)}}', ok: '{{1 + 1}}' };
    const log1 = vi.fn();
    const kept = evaluateComputedDefinitions(defs, {}, engine, { previous: { bad: 'old' }, onError: 'keep-previous', failureLog: log1 });
    expect(kept).toEqual({ bad: 'old', ok: 2 });
    expect(log1).toHaveBeenCalledWith('bad', expect.any(Error));

    const log2 = vi.fn();
    const undef = evaluateComputedDefinitions(defs, {}, engine, { previous: { bad: 'old' }, onError: 'undefined', failureLog: log2 });
    expect(undef.bad).toBeUndefined();
    expect('bad' in undef).toBe(true);
    expect(undef.ok).toBe(2);
    expect(log2).toHaveBeenCalledTimes(1);
  });

  /**

   * @scenario layer=computed_evaluator

   * @effects computed_later_key_reads_previous

   */
  it('previous 객체를 변형하지 않고 새 객체를 돌려준다', () => {
    const previous = Object.freeze({ a: 1 });
    const out = evaluateComputedDefinitions({ a: '{{2}}' }, {}, engine, { previous, onError: 'keep-previous' });
    expect(out).not.toBe(previous);
    expect(previous.a).toBe(1);
    expect(out.a).toBe(2);
  });

  /**

   * @scenario layer=computed_evaluator

   * @effects computed_failure_policy_per_site

   */
  it('track 콜백은 키마다 호출된다(DevTools 용)', () => {
    const track = vi.fn();
    evaluateComputedDefinitions({ a: '{{1}}', b: '{{[].reduce((x, y) => x)}}' }, {}, engine, { onError: 'undefined', track });
    expect(track).toHaveBeenCalledTimes(2);
    expect(track.mock.calls[1][3]).toEqual(expect.stringContaining('Reduce'));
  });

  describe('액션 경로 의미론(수정 전 sequence 재계산은 결과를 {} 에서 새로 만들었다)', () => {
    /**
     * @scenario layer=computed_evaluator
     * @effects sequence_computed_keeps_switch_and_literal_keys
     */
    it('$switch computed 키가 sequence setState 뒤에도 남는다', () => {
      const out = evaluateComputedDefinitions(
        { label: { $switch: '{{_local.tab}}', $cases: { a: 'A' }, $default: 'Z' } },
        { _local: { tab: 'a' } },
        engine,
        { previous: { label: 'Z' }, onError: 'keep-previous' }
      );
      expect(out.label).toBe('A');
    });

    /**

     * @scenario layer=computed_evaluator

     * @effects sequence_computed_keeps_switch_and_literal_keys

     */
    it('{{}} 없는 리터럴 computed 키(currentTab 등)가 남는다', () => {
      const out = evaluateComputedDefinitions({ currentTab: 'profile' }, {}, engine, { previous: {}, onError: 'keep-previous' });
      expect(out.currentTab).toBe('profile');
    });

    /**

     * @scenario layer=computed_evaluator

     * @effects computed_later_key_reads_previous

     */
    it('뒤에 정의된 키 참조는 undefined 가 아니라 직전 값', () => {
      const out = evaluateComputedDefinitions(
        { a: '{{$computed.b}}', b: '{{5}}' },
        {},
        engine,
        { previous: { b: 4 }, onError: 'keep-previous' }
      );
      expect(out.a).toBe(4);
    });
  });

  describe('evaluateComputedEntry — 정의 1개', () => {
    /**
     * @scenario layer=computed_evaluator
     * @effects computed_switch_pipe_literal_trim_rules
     */
    it('식·파이프·$switch·리터럴을 같은 규칙으로 평가하고 실패는 throw', () => {
      expect(evaluateComputedEntry('{{a + 1}}', { a: 1 }, engine)).toBe(2);
      expect(evaluateComputedEntry("{{s | uppercase}}", { s: 'x' }, engine)).toBe('X');
      expect(evaluateComputedEntry({ $switch: '{{k}}', $cases: { y: 1 } }, { k: 'y' }, engine)).toBe(1);
      expect(evaluateComputedEntry('plain', {}, engine)).toBe('plain');
      expect(() => evaluateComputedEntry('{{[].reduce((a, b) => a)}}', {}, engine)).toThrow();
    });
  });
});

describe('ComputedEvaluator — isInheritableDefinitions (D12)', () => {
  beforeEach(() => {
    Logger.getInstance().setDebug(false);
  });

  /**

   * @scenario layer=computed_evaluator

   * @effects computed_inheritance_disabled_by_back_reference

   */
  it('정순 참조만 있으면 true', () => {
    expect(isInheritableDefinitions({ a: '{{_local.x}}', b: '{{$computed.a + _computed.a}}', c: '{{_computed?.b}}' })).toBe(true);
  });

  /**

   * @scenario layer=computed_evaluator

   * @effects computed_inheritance_disabled_by_back_reference

   */
  it('뒤쪽 키 참조는 false', () => {
    expect(isInheritableDefinitions({ a: '{{$computed.b}}', b: '{{1}}' })).toBe(false);
  });

  /**

   * @scenario layer=computed_evaluator

   * @effects computed_inheritance_disabled_by_back_reference

   */
  it('자기 참조는 false', () => {
    expect(isInheritableDefinitions({ a: '{{(_computed.a ?? 0) + 1}}' })).toBe(false);
  });

  /**

   * @scenario layer=computed_evaluator

   * @effects computed_inheritance_disabled_by_back_reference

   */
  it('옵셔널 체이닝 뒤쪽 참조는 false', () => {
    expect(isInheritableDefinitions({ a: '{{_computed?.b}}', b: '{{1}}' })).toBe(false);
  });

  /**

   * @scenario layer=computed_evaluator

   * @effects computed_inheritance_disabled_by_back_reference

   */
  it("브래킷 표기 _computed['K'] 뒤쪽 참조는 false, 앞쪽은 true", () => {
    expect(isInheritableDefinitions({ a: "{{_computed['b']}}", b: '{{1}}' })).toBe(false);
    expect(isInheritableDefinitions({ a: '{{1}}', b: '{{$computed["a"]}}' })).toBe(true);
  });

  /**

   * @scenario layer=computed_evaluator

   * @effects computed_inheritance_disabled_by_back_reference

   */
  it('대상을 특정할 수 없는 _computed[k] 는 false', () => {
    expect(isInheritableDefinitions({ a: '{{1}}', b: '{{_computed[_local.key]}}' })).toBe(false);
  });

  /**

   * @scenario layer=computed_evaluator

   * @effects computed_inheritance_disabled_by_back_reference

   */
  it('$switch 객체 안의 참조도 검출한다', () => {
    expect(isInheritableDefinitions({ a: { $switch: '{{$computed.b}}', $cases: {} }, b: '{{1}}' })).toBe(false);
  });

  /**

   * @scenario layer=computed_evaluator

   * @effects computed_inheritance_disabled_by_back_reference

   */
  it('결과는 정의 객체당 1회 계산하고, 디버그 경고도 정의 객체당 1회', () => {
    Logger.getInstance().setDebug(true);
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const defs = { a: '{{$computed.b}}', b: '{{1}}' };
    isInheritableDefinitions(defs);
    isInheritableDefinitions(defs);
    const hits = warn.mock.calls.filter((c) => c.some((x) => typeof x === 'string' && x.includes('[computed]')));
    expect(hits.length).toBe(1);
    warn.mockRestore();
    Logger.getInstance().setDebug(false);
  });
});
