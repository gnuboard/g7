/**
 * 빌드 CSS 에서 화면 폭 변형(반응형 접두)으로 만든 클래스 이름을 뽑는다
 *
 * 화면 폭 변형: `sm:`·`md:`·`lg:`·`xl:`·`2xl:`, `max-sm:` 같은 `max-{폭}:`·`min-{폭}:`, 임의 폭 `min-[..]:`·`max-[..]:`.
 * 확장 접두 빌드의 `{접두}:md:w-20` 처럼 변형 사슬 어디에 있어도 센다. `dark:`·`hover:` 같은 상태 변형만 있는
 * 클래스와 컨테이너 질의(`@md:`)는 폭 판정 대상이 아니라 세지 않는다.
 *
 * 선택자의 클래스 이름은 CSS 이스케이프를 풀어 레이아웃에 쓰는 표기 그대로 돌려준다(`.md\:w-96` → `md:w-96`,
 * `.\32 xl\:flex` → `2xl:flex`).
 */
import postcss from 'postcss';

const BREAKPOINTS = ['sm', 'md', 'lg', 'xl', '2xl'];
const WIDTH_VARIANT = new RegExp(`^(?:(?:max|min)-)?(?:${BREAKPOINTS.join('|')})$|^(?:max|min)-\\[[^\\]]+\\]$`);

/**
 * CSS 식별자 이스케이프를 푼다.
 *
 * @param {string} raw 이스케이프된 식별자
 * @returns {string} 풀린 이름
 */
export function unescapeIdent(raw) {
  return raw.replace(/\\(?:([0-9a-fA-F]{1,6})\s?|([\s\S]))/g, (_, hex, ch) =>
    hex !== undefined ? String.fromCodePoint(parseInt(hex, 16)) : ch,
  );
}

/**
 * 클래스 이름을 변형 사슬로 나눈다(대괄호·괄호 안의 `:` 는 나누지 않는다).
 *
 * @param {string} name 클래스 이름
 * @returns {string[]} 변형들과 마지막 유틸리티
 */
export function splitVariants(name) {
  const parts = [];
  let depth = 0;
  let cur = '';
  for (const ch of name) {
    if (ch === '[' || ch === '(') depth += 1;
    else if (ch === ']' || ch === ')') depth = Math.max(0, depth - 1);
    if (ch === ':' && depth === 0) {
      parts.push(cur);
      cur = '';
    } else {
      cur += ch;
    }
  }
  parts.push(cur);
  return parts;
}

/**
 * 클래스 이름의 변형 사슬에 화면 폭 변형이 있는가.
 *
 * @param {string} name 클래스 이름
 * @returns {boolean} 폭 변형 포함 여부
 */
export function hasWidthVariant(name) {
  const parts = splitVariants(name);
  return parts.slice(0, -1).some((v) => WIDTH_VARIANT.test(v));
}

/**
 * CSS 선택자들에 나오는 클래스 이름 전체.
 *
 * @param {string} css CSS 원문
 * @returns {Set<string>} 클래스 이름
 */
export function classNamesInCss(css) {
  const names = new Set();
  const root = postcss.parse(css);
  root.walkRules((rule) => {
    const re = /\.((?:\\[0-9a-fA-F]{1,6}\s?|\\[\s\S]|[\w-])+)/g;
    let m;
    while ((m = re.exec(rule.selector))) names.add(unescapeIdent(m[1]));
  });
  return names;
}

/**
 * CSS 에서 화면 폭 변형으로 만든 클래스 이름(정렬).
 *
 * @param {string} css CSS 원문
 * @returns {string[]} 클래스 이름
 */
export function responsiveClassesInCss(css) {
  return [...classNamesInCss(css)].filter(hasWidthVariant).sort();
}
