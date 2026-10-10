/**
 * 미리보기 매체 격리 — `vw` 단위 치환 (순수 함수)
 *
 * `vw`(·`svw`·`lvw`·`dvw`)는 언제나 브라우저 창 폭 기준이다. 미리보기 안 복제본에서는 프레임 폭/100 을
 * 담은 CSS 변수로 바꾼다: `85vw` → `calc(85 * var(--g7le-vw))`.
 *
 * `cqw` 를 쓰지 않는 이유: `cqw` 는 이름과 무관하게 **가장 가까운** 컨테이너 기준이라, Tailwind
 * `@container` 유틸리티로 중첩 컨테이너가 생기면 값이 틀어진다. 높이 계열(`vh`)·`vmin`·`vmax` 는
 * 바꾸지 않는다(폭 판정이 아니거나 높이가 섞여 있다). 문자열과 `url()` 안은 건드리지 않는다.
 *
 * @since engine-v1.75.2
 */

import { PREVIEW_VW_VAR } from './constants';

/** 숫자 + (s|l|d)?vw — 앞이 식별자·숫자·점이면 단위가 아니다 */
const VW_PATTERN = /(?<![\w.#$\\-])(-?)(\d+(?:\.\d+)?|\.\d+)(?:s|l|d)?vw(?![\w-])/gi;

/**
 * 선언 텍스트를 문자열·url() 구간과 나머지 구간으로 나눠 나머지 구간에만 변환을 적용한다.
 *
 * @param text 선언 텍스트
 * @param transform 일반 구간 변환
 * @returns 결과
 */
function mapOutsideLiterals(text: string, transform: (segment: string) => string): string {
  let result = '';
  let segmentStart = 0;
  let i = 0;

  const flush = (end: number): void => {
    result += transform(text.slice(segmentStart, end));
  };

  while (i < text.length) {
    const char = text[i];

    if (char === '"' || char === "'") {
      flush(i);
      let j = i + 1;
      while (j < text.length && text[j] !== char) {
        j += text[j] === '\\' ? 2 : 1;
      }
      result += text.slice(i, j + 1);
      i = j + 1;
      segmentStart = i;
      continue;
    }

    if ((char === 'u' || char === 'U') && /^url\(/i.test(text.slice(i, i + 4))) {
      flush(i);
      let j = i + 4;
      let depth = 1;
      while (j < text.length && depth > 0) {
        if (text[j] === '(') depth++;
        else if (text[j] === ')') depth--;
        j++;
      }
      result += text.slice(i, j);
      i = j;
      segmentStart = i;
      continue;
    }

    i++;
  }

  flush(text.length);

  return result;
}

/**
 * 선언 텍스트의 `vw` 계열 단위를 프레임 폭 변수로 바꾼다.
 *
 * @param declText 선언 텍스트(`style.cssText`)
 * @returns 바꾼 텍스트
 */
export function rewriteViewportWidthUnits(declText: string): string {
  return mapOutsideLiterals(declText, (segment) =>
    segment.replace(VW_PATTERN, (_match, sign: string, number: string) => `calc(${sign}${number} * var(${PREVIEW_VW_VAR}))`),
  );
}

/**
 * 선언 텍스트가 `vw` 계열 단위를 쓰는지 (문자열·url() 제외).
 *
 * @param declText 선언 텍스트
 * @returns 사용 여부
 */
export function hasViewportWidthUnits(declText: string): boolean {
  // 대부분의 규칙은 `vw` 글자 자체가 없다 — 문자 단위 분석 전에 걸러 낸다(편집기 첫 실행 비용의 대부분)
  if (!/vw/i.test(declText)) return false;

  let found = false;
  mapOutsideLiterals(declText, (segment) => {
    VW_PATTERN.lastIndex = 0;
    if (VW_PATTERN.test(segment)) found = true;
    VW_PATTERN.lastIndex = 0;
    return segment;
  });
  return found;
}
