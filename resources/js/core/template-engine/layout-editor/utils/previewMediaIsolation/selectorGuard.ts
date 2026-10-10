/**
 * 미리보기 매체 격리 — 선택자 분리·가드 삽입 (순수 함수)
 *
 * 폭 `@media` 원본 규칙의 선택자 끝에 가드를 붙여 미리보기 프레임 밖에서만 적용되게 한다.
 * 선택자를 문자 단위로 훑되 이스케이프(`\:`, `\32 `)·따옴표·괄호·대괄호 깊이를 추적한다 —
 * Tailwind 의 `md\:flex`·`.\32 xl\:p-4`·`[&>*]:p-2` 같은 이름을 잘못 쪼개면 규칙이 통째로 무효가 된다.
 *
 * @since engine-v1.75.2
 */

/** 옛 문법 가짜 요소 — 콜론 하나로 써도 가짜 요소다 */
const LEGACY_PSEUDO_ELEMENTS = ['before', 'after', 'first-line', 'first-letter'];

/** 가드를 붙이면 의미가 깨지는 선택자 — 건너뛴다 */
const SKIP_PATTERN = /(^|[^\\]):host\b|::slotted\b/;

/**
 * 이스케이프 시퀀스의 길이(백슬래시 포함)를 돌려준다.
 *
 * CSS 이스케이프는 `\` + 16진수 1~6자리(+공백 1칸) 또는 `\` + 임의 문자 1개다.
 *
 * @param text 선택자
 * @param index 백슬래시 위치
 * @returns 건너뛸 길이
 */
function escapeLength(text: string, index: number): number {
  const hex = /^[0-9a-fA-F]{1,6}/.exec(text.slice(index + 1));
  if (hex) {
    let length = 1 + hex[0].length;
    const next = text[index + length];
    if (next === ' ' || next === '\t' || next === '\n') {
      length += 1;
    }
    return length;
  }
  return Math.min(2, text.length - index);
}

/**
 * 선택자를 훑으며 최상위(괄호·대괄호·따옴표 밖) 문자마다 콜백을 부른다.
 *
 * @param text 선택자
 * @param visit (문자, 위치) — true 를 돌려주면 순회를 멈춘다
 */
function scanTopLevel(text: string, visit: (char: string, index: number) => boolean | void): void {
  let depth = 0;
  let quote: string | null = null;

  for (let i = 0; i < text.length; i++) {
    const char = text[i];

    if (char === '\\') {
      i += escapeLength(text, i) - 1;
      continue;
    }

    if (quote) {
      if (char === quote) quote = null;
      continue;
    }

    if (char === '"' || char === "'") {
      quote = char;
      continue;
    }

    if (char === '(' || char === '[') {
      depth++;
      continue;
    }

    if (char === ')' || char === ']') {
      depth = Math.max(0, depth - 1);
      continue;
    }

    if (depth === 0 && visit(char, i) === true) {
      return;
    }
  }
}

/**
 * 선택자 목록을 최상위 쉼표로만 나눈다.
 *
 * @param list 선택자 목록(`selectorText`)
 * @returns 앞뒤 공백을 뗀 선택자 배열
 */
export function splitSelectorList(list: string): string[] {
  const parts: string[] = [];
  let start = 0;

  scanTopLevel(list, (char, index) => {
    if (char === ',') {
      parts.push(list.slice(start, index).trim());
      start = index + 1;
    }
  });

  parts.push(list.slice(start).trim());

  return parts.filter((part) => part.length > 0);
}

/**
 * 가짜 요소가 시작하는 위치(최상위)를 찾는다. 없으면 -1.
 *
 * @param selector 단일 선택자
 * @returns 위치
 */
function findPseudoElementStart(selector: string): number {
  let found = -1;

  scanTopLevel(selector, (char, index) => {
    if (char !== ':') return false;

    if (selector[index + 1] === ':') {
      found = index;
      return true;
    }

    // 옛 문법 `:before` 류 — 바로 뒤가 이름으로 끝나야 한다(`:before-x` 같은 이름은 아님)
    const rest = selector.slice(index + 1);
    for (const name of LEGACY_PSEUDO_ELEMENTS) {
      if (rest.toLowerCase().startsWith(name) && !/[\w-]/.test(rest.charAt(name.length))) {
        found = index;
        return true;
      }
    }

    return false;
  });

  return found;
}

/**
 * 단일 선택자의 마지막 복합 선택자에 가드를 붙인다.
 *
 * 가짜 요소(`::before`·`::placeholder`·`::part()`·옛 문법 `:before`)가 있으면 그 앞에 붙인다 —
 * 가짜 요소 뒤에는 의사 클래스 대부분을 둘 수 없어 선택자가 무효가 된다.
 *
 * @param selector 단일 선택자
 * @param guard 붙일 가드(`OUTSIDE_GUARD`·`INSIDE_GUARD`)
 * @returns 가드를 붙인 선택자. `:host`·`::slotted` 가 있으면 null(건너뜀)
 */
export function appendGuard(selector: string, guard: string): string | null {
  const trimmed = selector.trim();

  if (trimmed.includes(guard)) {
    return trimmed;
  }

  if (SKIP_PATTERN.test(trimmed)) {
    return null;
  }

  const pseudoStart = findPseudoElementStart(trimmed);
  if (pseudoStart >= 0) {
    return `${trimmed.slice(0, pseudoStart)}${guard}${trimmed.slice(pseudoStart)}`;
  }

  return `${trimmed}${guard}`;
}

/**
 * 선택자 목록의 항목마다 가드를 붙인다.
 *
 * @param list 선택자 목록
 * @param guard 붙일 가드
 * @returns 가드를 붙인 목록. 한 항목이라도 건너뛰기 대상이면 null
 */
export function guardSelectorList(list: string, guard: string): string | null {
  const guarded: string[] = [];

  for (const part of splitSelectorList(list)) {
    const next = appendGuard(part, guard);
    if (next === null) {
      return null;
    }
    guarded.push(next);
  }

  return guarded.join(', ');
}
