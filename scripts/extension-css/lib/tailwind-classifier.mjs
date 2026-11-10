/**
 * Tailwind 유틸리티 판정기 — 접두 진입 CSS 를 실제로 컴파일해 산출 선택자로 판정한다
 *
 * 이름 규칙만으로는 `bg-primary-500`(템플릿 테마 색)·`prose`(typography 플러그인)·임의값·변형 조합을
 * 정확히 가를 수 없다. 그래서 확장이 쓸 진입 CSS(접두 import + 테마 합집합 + typography + 다크 변형)를
 * `@tailwindcss/node` 의 `compile()` 로 컴파일하고, `build([후보…])` 산출에 그 후보의 클래스 선택자가
 * 실제로 나오는지로 판정한다. 표지(`group`·`peer`)는 선택자를 만들지 않으므로 호출자가 따로 다룬다.
 */
import { createRequire } from 'node:module';
import { existsSync } from 'node:fs';
import path from 'node:path';

/**
 * 확장 CSS 다크 규칙 규격 — 템플릿의 `.dark .x` 와 같은 특이도 (0,2,0) 을 유지하고,
 * 다크 안에 놓인 라이트 영역(예: 편집기 라이트 프리뷰)만 제외한다.
 */
export const DARK_IS = ':is([data-g7-color-scheme="dark"] *, [data-g7-color-scheme="dark"])';
export const DARK_NOT = ':not(:where([data-g7-color-scheme="dark"] [data-g7-color-scheme="light"] *, [data-g7-color-scheme="dark"] [data-g7-color-scheme="light"]))';
export const DARK_SUFFIX = `${DARK_IS}${DARK_NOT}`;

/**
 * CSS 식별자 이스케이프(CSSOM `CSS.escape` 규격).
 *
 * @param {string} value 원문
 * @returns {string} 이스케이프된 식별자
 */
export function cssEscape(value) {
  const str = String(value);
  let out = '';
  for (let i = 0; i < str.length; i += 1) {
    const code = str.charCodeAt(i);
    const ch = str[i];
    if (code === 0) { out += '�'; continue; }
    if ((code >= 1 && code <= 31) || code === 127
      || (i === 0 && code >= 48 && code <= 57)
      || (i === 1 && code >= 48 && code <= 57 && str.charCodeAt(0) === 45)) {
      out += `\\${code.toString(16)} `;
      continue;
    }
    if (i === 0 && str.length === 1 && code === 45) { out += `\\${ch}`; continue; }
    if (code >= 128 || code === 45 || code === 95 || (code >= 48 && code <= 57)
      || (code >= 65 && code <= 90) || (code >= 97 && code <= 122)) {
      out += ch;
      continue;
    }
    out += `\\${ch}`;
  }
  return out;
}

/**
 * CSS 선택자 이스케이프를 푼다.
 *
 * @param {string} value 이스케이프된 식별자
 * @returns {string} 원문
 */
export function cssUnescape(value) {
  return value.replace(/\\([0-9a-fA-F]{1,6})\s?|\\(.)/g, (_, hex, ch) => (hex ? String.fromCodePoint(parseInt(hex, 16)) : ch));
}

/**
 * CSS 텍스트에서 클래스 선택자 이름을 모두 뽑는다(이스케이프 해제).
 *
 * @param {string} css CSS 텍스트
 * @returns {Set<string>} 클래스 이름
 */
export function classNamesInCss(css) {
  const out = new Set();
  const re = /\.((?:-?[_a-zA-Z -￿]|\\.)(?:[\w -￿-]|\\[0-9a-fA-F]{1,6}\s?|\\.)*)/g;
  let m;
  while ((m = re.exec(css))) out.add(cssUnescape(m[1]));
  return out;
}

/**
 * typography 플러그인 경로를 찾는다(확장 → 저장소 루트 → 번들 템플릿 순).
 *
 * @param {string[]} bases 탐색 기준 디렉터리
 * @returns {string|null} 진입 파일 경로
 */
export function resolveTypography(bases) {
  for (const base of bases) {
    const pkg = path.join(base, 'package.json');
    try {
      const req = createRequire(existsSync(pkg) ? pkg : path.join(base, '__probe__.js'));
      return req.resolve('@tailwindcss/typography');
    } catch {
      // 다음 후보
    }
  }
  return null;
}

/**
 * Tailwind 진입 CSS(접두 빌드) 본문을 만든다. 생성 블록과 판정 컴파일이 같은 문자열을 쓴다.
 *
 * @param {object} opts 옵션
 * @param {string} opts.letters Tailwind 접두
 * @param {Array<[string,string]>} opts.theme `@theme` 선언 쌍
 * @param {boolean} opts.typography typography 플러그인 포함 여부
 * @param {boolean} [opts.withGenerated] 생성 의미 클래스 import 포함 여부
 * @param {boolean} [opts.withSources] `@source` 줄 포함 여부
 * @returns {string} CSS
 */
export function buildEntryCss({ letters, theme, typography, withGenerated = true, withSources = true }) {
  const lines = [
    `@import "tailwindcss/theme.css" layer(theme) prefix(${letters});`,
    `@import "tailwindcss/utilities.css" layer(utilities) prefix(${letters}) source(none);`,
  ];
  if (withGenerated) lines.push('@import "./g7-extension.generated.css";');
  if (withSources) {
    // 시험 디렉터리는 훑지 않는다 — 시험 파일 문자열의 클래스(금지 폭 접두 포함)가 빌드 CSS 에 실렸다(#869 S7)
    lines.push('@source "../layouts";', '@source "../extensions";', '@source "../js";', '@source not "../js/**/__tests__";', '@source "../../src";');
  }
  if (theme.length > 0) {
    lines.push('@theme {');
    theme.forEach(([prop, value]) => lines.push(`  ${prop}: ${value};`));
    lines.push('}');
  }
  if (typography) lines.push('@plugin "@tailwindcss/typography";');
  lines.push(`@custom-variant dark (&${DARK_SUFFIX});`);
  return `${lines.join('\n')}\n`;
}

/**
 * 판정기를 만든다.
 *
 * @param {object} opts 옵션
 * @param {string} opts.letters Tailwind 접두
 * @param {Array<[string,string]>} opts.theme 테마 합집합
 * @param {string|null} opts.typographyPath typography 진입 파일(없으면 prose 계열은 판정 불가)
 * @param {string} opts.base 컴파일 기준 디렉터리(tailwindcss 해석)
 * @returns {Promise<{ utilities(tokens: Iterable<string>): Set<string> }>} 판정기
 */
export async function createTailwindClassifier({ letters, theme, typographyPath, base }) {
  const { compile } = await import('@tailwindcss/node');
  const css = buildEntryCss({ letters, theme, typography: Boolean(typographyPath), withGenerated: false, withSources: false });
  const compiler = await compile(css, {
    base,
    onDependency() {},
    customJsResolver: async (id) => (id === '@tailwindcss/typography' && typographyPath ? typographyPath : undefined),
  });
  return {
    /**
     * 토큰 중 Tailwind 유틸리티(접두를 붙여 빌드하면 선택자가 나오는 것)를 고른다.
     *
     * @param {Iterable<string>} tokens 원 토큰(접두 없음)
     * @returns {Set<string>} 유틸리티 토큰
     */
    utilities(tokens) {
      const list = [...new Set(tokens)];
      const out = compiler.build(list.map((t) => `${letters}:${t}`));
      const names = classNamesInCss(out);
      return new Set(list.filter((t) => names.has(`${letters}:${t}`)));
    },
  };
}
