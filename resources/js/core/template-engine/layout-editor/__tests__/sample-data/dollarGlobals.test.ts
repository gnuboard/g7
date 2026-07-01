/**
 * dollarGlobals.test.ts — `$`-prefixed 엔진 전역 추출/제거
 *
 * 검증:
 *  - extractDollarGlobals: `$`-키만 추출 (locale 목록 등), 비-`$` 키 제외
 *  - stripDollarGlobals: `$`-키 제거한 사본 반환 (원본 불변)
 *  - 비객체/null/배열 입력 안전 처리
 */

import { describe, it, expect } from 'vitest';
import { extractDollarGlobals, stripDollarGlobals } from '../../sample-data/dollarGlobals';

describe('dollarGlobals — $-prefixed 엔진 전역 추출', () => {
  const seed = {
    $locale: 'ko',
    $locales: ['ko', 'en', 'ja'],
    currentUser: { uuid: 'x' },
    settings: { general: { site_name: '샘플' } },
  };

  it('extractDollarGlobals 는 $-키만 추출한다', () => {
    const out = extractDollarGlobals(seed);
    expect(out).toEqual({ $locale: 'ko', $locales: ['ko', 'en', 'ja'] });
    expect(out.currentUser).toBeUndefined();
    expect(out.settings).toBeUndefined();
  });

  it('extractDollarGlobals 는 $-키가 없으면 빈 객체', () => {
    expect(extractDollarGlobals({ a: 1, b: 2 })).toEqual({});
  });

  it('stripDollarGlobals 는 $-키를 제거한 사본을 반환하고 원본은 불변', () => {
    const out = stripDollarGlobals(seed);
    expect(out.$locale).toBeUndefined();
    expect(out.$locales).toBeUndefined();
    expect(out.currentUser).toEqual({ uuid: 'x' });
    // 원본 불변
    expect((seed as Record<string, unknown>).$locales).toEqual(['ko', 'en', 'ja']);
  });

  it.each([null, undefined, 42, 'str', ['a']])('비객체 입력(%s)도 안전하게 빈 객체', (input) => {
    expect(extractDollarGlobals(input as never)).toEqual({});
    expect(stripDollarGlobals(input as never)).toEqual({});
  });
});
