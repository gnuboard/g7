/**
 * dataSourceParamsUtils.ts — 데이터소스 요청 파라미터(params) 블럭 ↔ 객체 변환
 *
 * 데이터소스 `params` 는 압도적으로 **키 → 표현식 문자열(스칼라)** 의 평탄 객체다(번들 실측
 * 230건 중 params 보유 80건 · 표현식 값 328키 · 중첩 객체 값 단 1키). 그래서 키–값 칩 에디터
 * (KeyValueChipEditor: 키 텍스트 + 값 DataChipValueInput)로 1급 편집하고, 예외(중첩 객체/배열
 * 값)는 "코드로 편집"(raw JSON) 폴백으로 보존한다.
 *
 *  - 객체 → 키–값 행 배열(키 순서 보존). 행 값은 표현식/평문/숫자/불린의 **문자열 표현**.
 *  - 행 배열 → 객체. 각 값은 JSON 리터럴(순수 숫자·true/false·null)이면 그 타입으로, 그 외(표현식
 *    `{{...}}` 포함)는 문자열로 보존한다(무손실 라운드트립). 빈 키 행은 제외.
 *  - 중첩 객체/배열 값을 가진 params 는 칩 에디터로 평탄화하면 손실되므로 `hasNestedParamValue`
 *    로 감지해 호출자가 raw JSON 폴백을 강제한다.
 *
 * @since engine-v1.50.0
 */

/** 키–값 행 한 건(키 + 값 문자열 표현). KeyValueChipEditor 의 `{ key, value }` 직렬화와 동형. */
export interface ParamRow {
  /** 파라미터 키(임의 문자열 — `filters[0][field]` 등 특수문자 허용) */
  key: string;
  /** 값의 문자열 표현(표현식 `{{...}}` · 평문 · 숫자/불린 문자열) */
  value: string;
}

/** 값 1건을 행 표시용 문자열로. 문자열은 그대로, 그 외(숫자/불린/null)는 JSON 직렬화. */
function valueToRowString(v: unknown): string {
  if (typeof v === 'string') return v;
  if (v === undefined) return '';
  return JSON.stringify(v);
}

/**
 * 행 값 문자열을 JSON 리터럴이면 해당 타입으로, 아니면 문자열로 파싱.
 *
 * 순수 숫자(`10`)·불린(`true`)·null 만 리터럴로 승격한다. 표현식(`{{x}}`)·평문은 `JSON.parse`
 * 가 실패하거나 문자열이 아니므로 문자열로 보존한다. 객체/배열 리터럴(`{...}`/`[...]`)도
 * 문자열로 둔다(중첩은 raw JSON 폴백 영역 — 칩 에디터 값으로는 다루지 않음).
 *
 * @param s 행 값 문자열
 * @return 파싱된 값(number/boolean/null/string)
 */
function rowStringToValue(s: string): unknown {
  const trimmed = s.trim();
  if (trimmed === '') return '';
  // 순수 숫자/불린/null 만 리터럴 승격(객체/배열/표현식은 문자열 유지).
  if (/^-?\d+(\.\d+)?$/.test(trimmed) || trimmed === 'true' || trimmed === 'false' || trimmed === 'null') {
    try {
      return JSON.parse(trimmed);
    } catch {
      return s;
    }
  }
  return s;
}

/**
 * params 객체를 키–값 행 배열로(키 순서 보존).
 *
 * @param params params 객체(null/비객체면 빈 배열)
 * @return 키–값 행 배열
 */
export function paramsToRows(params: unknown): ParamRow[] {
  if (!params || typeof params !== 'object' || Array.isArray(params)) return [];
  return Object.entries(params as Record<string, unknown>).map(([key, v]) => ({
    key,
    value: valueToRowString(v),
  }));
}

/**
 * 키–값 행 배열을 params 객체로(빈 키 제외, 키 순서 보존, 값 타입 복원).
 *
 * @param rows 키–값 행 배열
 * @return params 객체(빈 행만 있으면 빈 객체)
 */
export function rowsToParams(rows: ParamRow[]): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const r of rows) {
    const key = r.key.trim();
    if (key === '') continue;
    out[key] = rowStringToValue(r.value);
  }
  return out;
}

/**
 * params 객체에 중첩 객체/배열 값이 있는지 — 있으면 칩 에디터 평탄화로 손실되므로 raw JSON 폴백.
 *
 * @param params params 객체
 * @return 중첩 값 존재 여부
 */
export function hasNestedParamValue(params: unknown): boolean {
  if (!params || typeof params !== 'object' || Array.isArray(params)) return false;
  return Object.values(params as Record<string, unknown>).some(
    (v) => v !== null && typeof v === 'object',
  );
}
