/**
 * 레이아웃 문자열 번역 `$t:키|이름={{값}}` 의 값 자리 보호
 *
 * 렌더러는 문자열의 `{{…}}` 를 먼저 값으로 바꾼 뒤 `$t:` 를 번역한다. 번역 엔진이 인자를 나눌 때는 값이 이미
 * 글자로 들어가 있어, 값에 든 `&`·`|`·`=`·끝 ` /` 를 인자 구분자·문장 꼬리로 읽었다(상품명 「Tom & Jerry」 →
 * 「Tom 선택」). 바인딩 해석이 `$t:` 인자 자리에 넣은 값을 보호 표식으로 감싸 두고, 번역 엔진은 인자를 나누기
 * 전에 표식 구간을 자리 번호로 바꿨다가 인자 값으로 되돌린다.
 *
 * 표식은 유니코드 비문자(U+FDD3·U+FDD4)다 — 번역 면제 표식(rawMarkers, U+FDD0~U+FDD2)과 겹치지 않는다.
 * 번역 엔진(`TranslationEngine.resolveTranslations`)이 결과에서 표식을 모두 걷으므로, 보호를 요청하는 쪽은
 * 결과를 반드시 번역 엔진에 넘기는 렌더·액션 경로뿐이다(`BindingOptions.protectTranslationParams`).
 *
 * @since engine-v1.80.1
 */

/** 보호 구간 시작 표식 */
export const TRANSLATION_VALUE_START = '﷓';

/** 보호 구간 끝 표식 */
export const TRANSLATION_VALUE_END = '﷔';

/** 보호 구간(표식 포함) — 안쪽에는 표식이 없다 */
const PROTECTED_REGION_PATTERN = /﷓([^﷓﷔]*)﷔/g;

/** 자리 번호로 바꾼 보호 구간 */
const PROTECTED_SLOT_PATTERN = /﷓(\d+)﷔/g;

/** 남은 표식 글자 */
const MARKER_CHAR_PATTERN = /[﷓﷔]/g;

/**
 * `$t:` 토큰과 인자 구역 — 번역 엔진의 TRANSLATION_PATTERN 과 같은 범위(다음 `$t:`·줄 끝·문자열 끝까지)
 * 1: `defer:` 접두, 2: 인자 구역(`|…`)
 */
const TOKEN_WITH_PARAMS_PATTERN = /\$t:(defer:)?[a-zA-Z0-9._-]+(\|(?:(?!\$t:).)+)?/g;

/**
 * 문자열에서 `$t:` 인자 구역(`|` 부터 구역 끝까지)의 범위를 구합니다.
 *
 * `{{…}}` 식 안의 글자(`'$t:a'` 등)가 구역 경계로 읽히지 않도록 바인딩 구간을 같은 길이의 중립 글자로
 * 가린 문자열에서 찾는다. `$t:defer:` 의 인자는 반복 렌더가 항목 문맥으로 따로 풀기 때문에(값이 아직 없다)
 * 보호 대상에서 뺀다.
 *
 * @param template 원본 문자열
 * @param bindings 바인딩 구간 목록 (`start`·`end`)
 * @returns `[시작, 끝)` 범위 목록
 */
export function findTranslationParamZones(
  template: string,
  bindings: ReadonlyArray<{ start: number; end: number }>,
): Array<[number, number]> {
  let masked = template;
  if (bindings.length > 0) {
    let out = '';
    let cursor = 0;
    for (const binding of bindings) {
      out += template.slice(cursor, binding.start) + 'x'.repeat(binding.end - binding.start);
      cursor = binding.end;
    }
    masked = out + template.slice(cursor);
  }

  const zones: Array<[number, number]> = [];
  for (const match of masked.matchAll(TOKEN_WITH_PARAMS_PATTERN)) {
    const params = match[2];
    if (!params || match[1]) continue;
    const end = (match.index ?? 0) + match[0].length;
    zones.push([end - params.length, end]);
  }

  return zones;
}

/**
 * 위치가 인자 구역 안인지 판정합니다.
 *
 * @param zones findTranslationParamZones 결과
 * @param position 문자열 위치
 * @returns 구역 안이면 true
 */
export function isInTranslationParamZone(zones: ReadonlyArray<[number, number]>, position: number): boolean {
  return zones.some(([start, end]) => position >= start && position < end);
}

/**
 * 값을 보호 구간으로 감쌉니다. 값에 든 표식 글자는 지운다(구간 경계가 흔들리지 않도록).
 *
 * @param value 바인딩 해석 결과 글자
 * @returns 표식으로 감싼 글자
 */
export function wrapTranslationParamValue(value: string): string {
  return TRANSLATION_VALUE_START + value.replace(MARKER_CHAR_PATTERN, '') + TRANSLATION_VALUE_END;
}

/**
 * 문자열에 보호 표식이 있는지 확인합니다.
 *
 * @param text 대상 문자열
 * @returns 표식이 있으면 true
 */
export function hasTranslationParamMarker(text: string): boolean {
  return text.includes(TRANSLATION_VALUE_START) || text.includes(TRANSLATION_VALUE_END);
}

/**
 * 보호 구간을 자리 번호(`표식 + 번호 + 표식`)로 바꾸고 값 목록을 돌려줍니다.
 *
 * 자리 번호에는 `&`·`|`·`=`·공백·`$t:`·줄바꿈이 없어 인자 분리·문장 꼬리 판정·`$t:` 구역 판정에 끼지 않는다.
 *
 * @param text 대상 문자열
 * @returns 자리 번호로 바꾼 문자열과 값 목록
 */
export function extractTranslationParamValues(text: string): { text: string; values: string[] } {
  const values: string[] = [];
  const replaced = text.replace(PROTECTED_REGION_PATTERN, (_m: string, value: string) => {
    values.push(value);
    return `${TRANSLATION_VALUE_START}${values.length - 1}${TRANSLATION_VALUE_END}`;
  });

  return { text: replaced, values };
}

/**
 * 자리 번호를 값으로 되돌리고 남은 표식 글자를 걷습니다.
 *
 * @param text 자리 번호가 든 문자열
 * @param values 값 목록
 * @returns 값으로 되돌린 문자열 (표식 없음)
 */
export function restoreTranslationParamValues(text: string, values: readonly string[]): string {
  return text
    .replace(PROTECTED_SLOT_PATTERN, (m: string, idx: string) => values[Number(idx)] ?? m)
    .replace(MARKER_CHAR_PATTERN, '');
}

/**
 * 보호 표식만 걷습니다 (번역하지 않고 돌려주는 경로용 — 값 글자는 그대로 남는다).
 *
 * @param text 대상 문자열
 * @returns 표식을 걷은 문자열
 */
export function stripTranslationParamMarkers(text: string): string {
  return hasTranslationParamMarker(text) ? text.replace(MARKER_CHAR_PATTERN, '') : text;
}
