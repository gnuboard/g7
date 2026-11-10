/**
 * @file deprecatedSemanticClassesKept.test.ts
 * @description 7.1.4 에 있던 의미 클래스는 번들 레이아웃이 더는 쓰지 않아도 빌드 CSS 에 정의(폭 부분 포함)가 남는다
 *
 * 번들 레이아웃은 폭 분기를 엔진 responsive 구간 값으로 펼쳐 의미 클래스를 쓰지 않는다. 그러나 사이트가 편집기로 고쳐
 * 저장한 레이아웃은 7.1.4 의 클래스 이름을 그대로 들고 있다. 정의가 빌드 CSS 에서 빠지면 그런 화면에서 검색 줄 선택 상자의
 * 폭·배경과 헤더 사용자 이름 칸의 숨김·표시가 사라진다. 폐지 예정 정의는 다음 출시 이후에 지운다.
 *
 * 대상은 7.1.4 원본 CSS 에 있던 클래스 가운데 이번 정리에서 정의가 빠졌던 것이다.
 *   - `search-bar-select`·`search-bar-select-custom` (semantic.css) — 7.1.4 이커머스 관리자 목록 검색 줄이 쓴다.
 *   - `user_info` (layout/_admin_base.css) — 7.1.4 `_admin_base` 헤더의 사용자 이름 칸이 쓴다.
 *
 * @scenario-manifest tests/scenarios/admin-deprecated-semantic-classes-kept.yaml
 */

import fs from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';

const TEMPLATE_DIR = path.resolve(__dirname, '../../..');
const DIST_CSS = path.join(TEMPLATE_DIR, 'dist/css/components.css');

interface Expectation {
  /** 클래스 이름 */
  name: string;
  /** 폭과 무관한 기본 규칙이 가져야 할 선언 조각 */
  base: string[];
  /** 768px(48rem) 이상 구간 규칙이 가져야 할 선언 조각 */
  wide: string[];
}

const KEPT: Expectation[] = [
  {
    name: 'search-bar-select',
    base: ['width:100%', 'background-color:var(--color-gray-100)'],
    wide: ['width:calc(var(--spacing) * 32)', 'flex-shrink:0'],
  },
  {
    name: 'search-bar-select-custom',
    base: ['width:100%'],
    wide: ['width:calc(var(--spacing) * 32)', 'flex-shrink:0'],
  },
  {
    name: 'user_info',
    base: ['display:none'],
    wide: ['display:block'],
  },
];

/**
 * 빌드 CSS 에서 `.name{…}` 단일 클래스 규칙 본문을 위치와 함께 모은다.
 *
 * @param css 빌드 CSS
 * @param name 클래스 이름
 * @returns 규칙 본문과 시작 위치 목록
 */
function rulesOf(css: string, name: string): Array<{ body: string; pos: number }> {
  const out: Array<{ body: string; pos: number }> = [];
  const re = new RegExp(`(^|[{}])\\s*\\.${name.replace(/[-_]/g, (c) => `\\${c}`)}\\{([^{}]*)\\}`, 'g');
  let m: RegExpExecArray | null;
  while ((m = re.exec(css))) out.push({ body: m[2], pos: m.index + m[1].length });

  return out;
}

/**
 * 위치가 `@media(min-width:48rem){…}` 블록 안인가.
 *
 * @param css 빌드 CSS
 * @param pos 규칙 시작 위치
 * @returns 48rem 이상 구간이면 true
 */
function insideWide(css: string, pos: number): boolean {
  const re = /@media\s*\(min-width:\s*48rem\)\s*\{/g;
  let m: RegExpExecArray | null;
  while ((m = re.exec(css))) {
    if (m.index > pos) break;
    let depth = 1;
    let i = re.lastIndex;
    for (; i < css.length && depth; i += 1) {
      if (css[i] === '{') depth += 1;
      else if (css[i] === '}') depth -= 1;
    }
    if (pos > m.index && pos < i) return true;
  }

  return false;
}

/**
 * 클래스 하나의 기본 정의와 768px 이상 정의를 빌드 CSS 에서 확인한다.
 *
 * @param css 빌드 CSS
 * @param name 클래스 이름 (`KEPT` 에 있어야 한다)
 * @returns 없음
 */
function expectKept(css: string, name: string): void {
  const exp = KEPT.find((k) => k.name === name);
  expect(exp, `${name} 기대값`).toBeDefined();
  const rules = rulesOf(css, name);
  const baseBodies = rules.filter((r) => !insideWide(css, r.pos)).map((r) => r.body).join(';');
  const wideBodies = rules.filter((r) => insideWide(css, r.pos)).map((r) => r.body).join(';');

  for (const decl of exp!.base) expect(baseBodies, `.${name} 기본 규칙`).toContain(decl);
  for (const decl of exp!.wide) expect(wideBodies, `.${name} 768px 이상 규칙`).toContain(decl);
}

describe('폐지 예정 의미 클래스 정의 유지', () => {
  const css = fs.readFileSync(DIST_CSS, 'utf8');

  /**
   * @scenario class=search-bar-select
   * @effects released_semantic_class_definition_kept
   */
  it('.search-bar-select 의 기본 정의와 768px 이상 정의가 빌드 CSS 에 있다', () => {
    expectKept(css, 'search-bar-select');
  });

  /**
   * @scenario class=search-bar-select-custom
   * @effects released_semantic_class_definition_kept
   */
  it('.search-bar-select-custom 의 기본 정의와 768px 이상 정의가 빌드 CSS 에 있다', () => {
    expectKept(css, 'search-bar-select-custom');
  });

  /**
   * @scenario class=user_info
   * @effects released_semantic_class_definition_kept
   */
  it('.user_info 의 기본 정의와 768px 이상 정의가 빌드 CSS 에 있다', () => {
    expectKept(css, 'user_info');
  });
});
