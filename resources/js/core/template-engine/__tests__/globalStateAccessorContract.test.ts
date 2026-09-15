/**
 * 전역 상태 접근자 계약 — `state.get()` 결과에 `._global` 을 덧붙이지 않는다.
 *
 * ## 왜 소스를 훑는가
 *
 * `G7Core.state.get()` 은 `TemplateApp.getGlobalState()` 를 그대로 돌려주고, 그 값이 **곧
 * `_global` 의 내용물**이다(`GlobalState` 에 `_global` 키는 없다). 그런데 네 자리가 그 결과에
 * `._global` 을 한 번 더 붙여 읽고 있었고, 값이 언제나 `undefined`/`{}` 라:
 *
 *  - `evaluateExpression` 의 stale closure 방어가 `if (latestGlobal)` 가드에 걸려 **한 번도 돌지 않았다**
 *  - DataGrid `cellChildren`·`expandChildren` 문맥은 `_global` 이 **통째로 빈 객체**였다
 *
 * 둘 다 예외도 경고도 없이 조용히 틀린다. 그리고 **단위 테스트가 이 결함을 green 으로 고정하고
 * 있었다** — 목이 `{_global: {...}}` 를 돌려주도록 쓰여 어긋난 접근자와 짝이 맞았기 때문이다.
 * 그래서 목을 고치는 것만으로는 부족하다: 목은 또 틀릴 수 있고, 그때 프로덕션과 다시 짝이 맞는다.
 * 판정을 **소스 문자열**로 옮겨야 그 공모가 성립하지 않는다.
 *
 * ## 무엇을 잡나
 *
 * `state.get()` / `getGlobalState()` 호출 **직후**에 오는 `._global` 접근만 잡는다. `_global` 이라는
 * 이름 자체는 데이터 문맥의 정당한 키이므로(`dataContext._global`) 이름만으로는 판정하지 않는다.
 *
 * @since engine-v1.70.0
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ENGINE_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const CORE_ROOT = path.resolve(ENGINE_ROOT, '..');

/** `…state.get()…._global` · `…getGlobalState()…._global` — 옵셔널 체이닝·공백 허용. */
const WRONG_ACCESSOR =
  /(?:state\s*\??\.\s*get\s*\??\.?\s*\(\s*\)|getGlobalState\s*\??\.?\s*\(\s*\))\s*\??\.\s*_global\b/;

/**
 * 엔진·DevTools 소스 파일 목록 (테스트 제외).
 *
 * @param dir 훑을 디렉토리
 * @param out 누적 결과
 * @returns 파일 경로 목록
 */
function sourceFiles(dir: string, out: string[] = []): string[] {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (entry.name === '__tests__' || entry.name === 'node_modules') continue;
      sourceFiles(full, out);
      continue;
    }
    if (/\.tsx?$/.test(entry.name)) out.push(full);
  }

  return out;
}

describe('전역 상태 접근자 계약', () => {
  /**
   * @scenario accessor=global_state_get, surface=engine_source
   *
   * @effects global_state_getter_result_is_not_dereferenced_by_global_again
   */
  it('`state.get()` 결과에 `._global` 을 덧붙이는 자리가 없다', () => {
    const offenders: string[] = [];

    for (const file of sourceFiles(CORE_ROOT)) {
      const lines = fs.readFileSync(file, 'utf8').split('\n');

      lines.forEach((line, i) => {
        if (!WRONG_ACCESSOR.test(line)) return;

        // 부정 예시는 **일부러** 틀린 표기를 싣는 자리다 — DevTools 진단이 개발자에게
        // "이렇게 쓰면 언제나 undefined" 를 보여주는 코드 예시가 그렇다. 같은 줄 또는
        // 바로 위 줄의 `❌` 표지로 가른다(문서 검사가 쓰는 것과 같은 규칙).
        const near = `${lines[i - 1] ?? ''}\n${line}`;
        if (near.includes('❌')) return;

        offenders.push(`${path.relative(CORE_ROOT, file).replace(/\\/g, '/')}:${i + 1}  ${line.trim()}`);
      });
    }

    expect(
      offenders,
      '`state.get()`/`getGlobalState()` 는 이미 `_global` 의 내용물이다 — `._global` 을 덧붙이면 '
        + '언제나 비어 예외 없이 조용히 틀린다:\n' + offenders.join('\n'),
    ).toEqual([]);
  });

  /**
   * 계량기가 죽어 공허하게 통과하지 않는지 — 합성 표본으로 자기시험한다.
   *
   * @scenario accessor=global_state_get, surface=synthetic_sample
   *
   * @effects accessor_guard_detects_a_planted_violation
   */
  it('가드가 심어 둔 위반을 실제로 잡는다', () => {
    const planted = [
      'const latestGlobal = G7Core?.state?.get()?._global;',
      'const g = templateApp.getGlobalState()._global;',
      '  _global: globalState._global || {},', // 변수 경유는 잡지 못한다 — 아래 기대값이 그 한계를 적는다
    ];

    expect(planted.map(l => WRONG_ACCESSOR.test(l))).toEqual([true, true, false]);
  });
});
