/**
 * computed 스코프 토큰 전달 규칙 구조 검사 — 공개 이슈 140
 *
 * `extendedDataContext` useMemo 에서 computed 블록은 `$parent`·`extensionPointProps` 등이
 * `result` 에 써지기 **전에** 돈다. 그래서 그 키들이 새로 써지면 토큰을 버려 직계 자식이 그 키를
 * 포함한 컨텍스트로 다시 계산하게 한다(토큰 전달 규칙). 누가 computed 블록 **뒤에** 새 컨텍스트 키를
 * 추가하면 이 규칙을 함께 갱신해야 한다 — 이 테스트가 그 누락을 red 로 드러낸다.
 *
 * @since engine-v1.74.0
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const FILE = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', 'DynamicRenderer.tsx');

/** 토큰 전달 규칙이 알고 있는, computed 블록 뒤에 써지는 키 */
const KNOWN_LATE_KEYS = ['_computed', '_isolated', '$parent', 'extensionPointProps', 'extensionPointCallbacks', '$templateId', '$locale'];

describe('DynamicRenderer — computed 블록 뒤 컨텍스트 키', () => {
  const src = fs.readFileSync(FILE, 'utf8');
  const memoStart = src.indexOf('const extendedDataContext = useMemo(');
  const blockStart = src.indexOf('COMPUTED_SCOPE', memoStart);
  const memoEnd = src.indexOf('return result;', blockStart);

  /**

   * @scenario layer=structure

   * @effects late_context_keys_locked_to_token_rule

   */
  it('extendedDataContext useMemo 와 computed 스코프 블록을 찾는다(스캐너 자체 회귀 방지)', () => {
    expect(memoStart).toBeGreaterThan(0);
    expect(blockStart).toBeGreaterThan(memoStart);
    expect(memoEnd).toBeGreaterThan(blockStart);
  });

  /**

   * @scenario layer=structure

   * @effects late_context_keys_locked_to_token_rule

   */
  it('computed 블록 뒤에서 result 에 대입하는 키 집합이 토큰 전달 규칙이 아는 집합과 같다', () => {
    const tail = src.slice(blockStart, memoEnd);
    const keys = new Set<string>();
    for (const m of tail.matchAll(/\bresult\.(\$?\w+)\s*=(?!=)/g)) keys.add(m[1]);
    expect([...keys].sort()).toEqual([...KNOWN_LATE_KEYS].sort());
  });

  /**

   * @scenario layer=structure

   * @effects late_context_keys_locked_to_token_rule

   */
  it('토큰 전달 규칙이 $parent·extensionPointProps·extensionPointCallbacks 를 모두 비교한다', () => {
    const tail = src.slice(blockStart, memoEnd + 40);
    expect(tail).toMatch(/result\.\$parent\s*!==\s*dataContext\.\$parent/);
    expect(tail).toMatch(/result\.extensionPointProps\s*!==\s*dataContext\.extensionPointProps/);
    expect(tail).toMatch(/result\.extensionPointCallbacks\s*!==\s*dataContext\.extensionPointCallbacks/);
  });
});
