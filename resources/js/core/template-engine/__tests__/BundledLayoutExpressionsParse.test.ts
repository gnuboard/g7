/**
 * 번들 레이아웃의 모든 바인딩 식이 식 평가기로 해석되는지 검증한다 (#703 브라우저 실측).
 *
 * 쇼핑몰 환경설정 저장 본문 식에 구조분해 매개변수(`(({ a, ...rest }) => rest)(c)`)가 들어가자 평가기가
 * 「Expected ")" but found "=>"」로 해석에 실패했고, 실패한 식의 원문 문자열이 그대로 요청 본문이 되어
 * 모든 탭 저장이 아무것도 저장하지 못했다. 문자열 포함 검사나 new Function 평가로는 이 실패가 보이지 않는다
 * — 엔진이 실제로 쓰는 평가기로 번들 전체(템플릿·모듈·플러그인의 layouts·extensions)를 해석한다.
 *
 * 해석(문법)만 본다. 빈 문맥에서의 실행 오류(없는 값 접근)는 대상이 아니다. 엔진이 평가 전에 치환하는
 * `$t:키` 토큰과 `raw:` 표식은 같은 방식으로 먼저 걷어 낸다. `comment` 필드는 사람이 읽는 설명이라 제외한다.
 *
 * 시나리오 매니페스트: tests/scenarios/bundled-layout-expressions-parse.yaml
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, resolve, relative } from 'node:path';
import { evaluateSafeExpression } from '../SafeExpressionEvaluator';

const ROOT = resolve(process.cwd());
const SYNTAX_ERROR = /Expected|Unexpected|Unterminated|Invalid|not supported|unsupported/i;

/** 번들 디렉터리에서 layouts·extensions 아래 JSON 을 모은다. */
function collect(dir: string, out: string[]): void {
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) {
      if (!/^(node_modules|__tests__|dist|tests)$/.test(name)) collect(path, out);
    } else if (path.endsWith('.json') && /[\\/](layouts|extensions)[\\/]/.test(path)) {
      out.push(path);
    }
  }
}

/** 문자열 하나에서 바인딩 식을 뽑는다 — 통째로 `{{…}}` 인 값은 중첩 `}}` 가 있어도 한 식으로 본다. */
function expressionsOf(value: string): string[] {
  const trimmed = value.trim();
  if (trimmed.startsWith('{{') && trimmed.endsWith('}}') && trimmed.indexOf('{{', 2) === -1) {
    return [trimmed.slice(2, -2)];
  }
  return [...value.matchAll(/\{\{([\s\S]*?)\}\}(?!\})/g)].map((m) => m[1]);
}

describe('번들 레이아웃 바인딩 식 해석', () => {
  // @scenario scope=all_bundled_layouts
  // @effects every_bundled_expression_parses
  it('모든 식이 엔진 평가기로 해석된다(문법 오류 0건)', () => {
    const files: string[] = [];
    for (const base of ['templates/_bundled', 'modules/_bundled', 'plugins/_bundled']) collect(join(ROOT, base), files);
    expect(files.length, '검사 대상 레이아웃이 없습니다(경로 해석 확인).').toBeGreaterThan(100);

    let scanned = 0;
    const failures: string[] = [];
    for (const file of files) {
      let data: unknown;
      try {
        data = JSON.parse(readFileSync(file, 'utf-8'));
      } catch {
        continue;
      }
      const visit = (node: unknown, key?: string): void => {
        if (typeof node === 'string') {
          if (key === 'comment') return;
          for (const raw of expressionsOf(node)) {
            const expr = raw.replace(/^\s*raw:/, '').replace(/\$t:(defer:)?[A-Za-z0-9_.:\-]+/g, '"t"');
            scanned++;
            try {
              evaluateSafeExpression(expr, {});
            } catch (error) {
              const message = String((error as Error)?.message ?? error);
              if (SYNTAX_ERROR.test(message)) {
                failures.push(`${relative(ROOT, file)} — ${message.slice(-100)}`);
              }
            }
          }
        } else if (Array.isArray(node)) {
          node.forEach((child) => visit(child));
        } else if (node && typeof node === 'object') {
          for (const [k, v] of Object.entries(node)) visit(v, k);
        }
      };
      visit(data);
    }

    expect(scanned, '해석한 식이 없습니다.').toBeGreaterThan(1000);
    expect(failures).toEqual([]);
  });
});
