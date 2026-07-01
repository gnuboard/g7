// e2e:allow 순수 파서 유틸(React/DOM 0) — 칩 분해/param 경계 로직은 단위(inlineBindingUtils.test 96건)로 전수 검증, 소비 위젯(InlineParamChipEditor)은 contentEditable/드래그 의존으로 Playwright 부적합(동일 정책). 라이브는 Chrome MCP 매트릭스로 검증
/**
 * inlineBindingUtils.ts — 텍스트(보간) 데이터 연결 조각 추출/치환/삽입 유틸
 *
 * `text`(및 `label`/`title`/`placeholder`/`alt`) prop 처럼 "라벨·구분자 + `{{...}}` 보간"
 * 이 한 문자열에 섞여 있는 값에서, **보간 조각만** 데이터 연결 대상으로 다루기 위한 순수
 * 유틸이다. 부록6 의 `dataProps`(prop 한 칸 = 표현식 통째) 모델과 달리, 9-b 는 한 문자열
 * **안의 여러 `{{...}}` 토큰을 위치 보존**하며 조각 단위로 교체/해제/추가한다.
 *
 * 설계 (9-a 권고안 채택):
 *  - **편집 단위 = 보간 조각만**(쟁점 1). 라벨/구분자/평문은 보존, `{{...}}` 토큰만 교체.
 *  - **교체 = parseable 조각만**(쟁점 3). `parseBindingExpression` 이 단일 경로로 인지하는
 *    조각만 소스/경로 교체. 삼항/Math/필터/다중 등 복합 조각은 읽기전용 디그레이드(부록6 가드).
 *  - **신규 추가 = 끝에 추가**(쟁점 §(8) — 1차). 평문 `"안녕"` → `"안녕 {{이름}}"`(공백 1개
 *    구분, 원문이 비어 있으면 토큰만). 통째 교체/커서 위치는 후속.
 *
 * 본 모듈은 순수 함수 — React/DOM 의존 0. `parseBindingExpression`/`buildBindingExpression`
 * 은 부록6 SSoT(`bindingCandidates.ts`)를 재사용한다(저장값 형태 일관 — `?.`+`?? 폴백`).
 *
 * @since engine-v1.50.0
 * @since engine-v1.50.0 — param 값 경계 SSoT 통일(`PARAM_VALUE_BODY`)로 `||`/파이프
 *   필터/multi-param 든 named param 의 칩 분해 결함 해소, 보간 내부 중괄호(객체 리터럴) 인식,
 *   `bindingChipLabel` 복합식 끝 식별자 추출.
 */

import {
  buildBindingExpression,
  parseBindingExpression,
  type ParsedBinding,
} from './bindingCandidates';

/**
 * 텍스트 한 문자열을 라벨/보간 조각으로 분해한 결과의 한 조각.
 *
 * `kind`:
 *  - `literal`: 보간 바깥 평문(라벨/구분자/공백 등). 편집 대상 아님(보존).
 *  - `binding`: `{{...}}` 한 쌍. 편집 대상(parseable 면 교체/해제, 복합이면 읽기전용).
 */
export interface InlineSegment {
  kind: 'literal' | 'binding';
  /** 원문 그대로의 텍스트(보간이면 `{{...}}` 포함 전체) */
  raw: string;
  /** 원문 내 시작 인덱스(치환 시 위치 SSoT) */
  start: number;
  /** 원문 내 끝 인덱스(exclusive) */
  end: number;
  /** (binding 한정) 파싱 결과 — 단일 경로면 객체, 복합이면 null */
  parsed?: ParsedBinding | null;
  /** (binding 한정) 이 조각이 몇 번째 보간 토큰인지(0-base) — 행 식별/치환 anchor */
  bindingIndex?: number;
}

// `{{ ... }}` 한 쌍 매칭 — 보간 내부에 단일 `{`/`}`(객체 리터럴 `{}`/`{ k: v }`, 함수 본문,
// 문자열 `'{'` 등)가 들어가도 닫는 `}}` 까지 정확히 흡수한다. 종전 `[^{}]*` 는 내부 중괄호를
// 전면 금지해, 실재하는 `{{JSON.stringify(x ?? {}, null, 2)}}` / `{{handler('x', { … })}}` /
// `{{Object.entries(… ?? {}).map(…)}}` 류 보간을 인식하지 못했다(
// 그 텍스트가 평문으로 오분류되거나 칩 분해가 깨짐). 런타임 TranslationEngine 의 검증된 보간
// 매칭(`\{\{(?:[^}]|\}(?!\}))*\}\}`)과 동일 규칙 — "닫는 `}}` 가 아닌 한 모든 문자(단일 `}` 포함)".
const BINDING_TOKEN_RE = /\{\{(?:[^}]|\}(?!\}))*\}\}/g;

/**
 * 텍스트를 라벨/보간 조각 배열로 분해한다(위치 보존). 모든 조각의 `raw` 를 순서대로 이으면
 * 원문과 완전히 동일하다(무손실).
 *
 * @param text 분해할 문자열(text/label/… prop 값)
 * @returns 조각 배열(빈 문자열이면 빈 배열)
 */
export function splitInlineSegments(text: string): InlineSegment[] {
  const out: InlineSegment[] = [];
  if (typeof text !== 'string' || text.length === 0) return out;
  let lastIndex = 0;
  let bindingIndex = 0;
  // 정규식 상태 격리 — 모듈 전역 RE 의 lastIndex 오염 방지를 위해 매 호출 새 RE.
  const re = new RegExp(BINDING_TOKEN_RE.source, 'g');
  let m: RegExpExecArray | null;
  while ((m = re.exec(text)) !== null) {
    const start = m.index;
    const end = start + m[0].length;
    if (start > lastIndex) {
      out.push({ kind: 'literal', raw: text.slice(lastIndex, start), start: lastIndex, end: start });
    }
    out.push({
      kind: 'binding',
      raw: m[0],
      start,
      end,
      parsed: parseBindingExpression(m[0]),
      bindingIndex: bindingIndex++,
    });
    lastIndex = end;
  }
  if (lastIndex < text.length) {
    out.push({ kind: 'literal', raw: text.slice(lastIndex), start: lastIndex, end: text.length });
  }
  return out;
}

/** 텍스트 내 보간 조각만(순서대로) 추출한다. */
export function extractBindingSegments(text: string): InlineSegment[] {
  return splitInlineSegments(text).filter((s) => s.kind === 'binding');
}

/** 텍스트가 보간 토큰을 1개 이상 가지는지. */
export function hasInlineBinding(text: unknown): boolean {
  if (typeof text !== 'string') return false;
  // 비-stateful 검사 — 전역 RE 의 lastIndex 오염을 피하려 매 호출 새 RE(같은 SSoT 규칙).
  return new RegExp(BINDING_TOKEN_RE.source).test(text);
}

/**
 * `bindingIndex` 번째 보간 토큰을 새 표현식으로 교체한다(라벨/구분자/다른 토큰 보존).
 *
 * 신규 표현식은 호출자가 `buildBindingExpression` 으로 만든 안전 형태(`{{src?.path ?? fb}}`)를
 * 그대로 넘긴다. 위치는 `splitInlineSegments` 의 start/end 로 SSoT — 같은 경로 문자열이
 * 여러 번 나와도 인덱스로 정확히 한 조각만 바꾼다.
 *
 * @param text 원문
 * @param bindingIndex 교체할 보간 토큰의 0-base 순번
 * @param newExpression 새 `{{...}}` 표현식 전체
 * @returns 교체된 새 문자열(인덱스 범위 밖이면 원문 그대로)
 */
export function replaceBindingSegment(
  text: string,
  bindingIndex: number,
  newExpression: string,
): string {
  const seg = extractBindingSegments(text)[bindingIndex];
  if (!seg) return text;
  return text.slice(0, seg.start) + newExpression + text.slice(seg.end);
}

/**
 * `bindingIndex` 번째 보간 토큰을 제거한다(해제). 토큰만 지우고, 토큰에 인접한 여분 공백
 * 하나를 함께 정리해 `"안녕  세상"` 같은 이중 공백을 막는다(라벨/구분자는 보존).
 *
 * @param text 원문
 * @param bindingIndex 제거할 보간 토큰의 0-base 순번
 * @returns 제거된 새 문자열(인덱스 범위 밖이면 원문 그대로)
 */
export function removeBindingSegment(text: string, bindingIndex: number): string {
  const seg = extractBindingSegments(text)[bindingIndex];
  if (!seg) return text;
  let s = seg.start;
  let e = seg.end;
  // 토큰 뒤 공백 1개 흡수, 없으면 앞 공백 1개 흡수(라벨과 토큰 사이 구분 공백 제거).
  if (text[e] === ' ') e += 1;
  else if (s > 0 && text[s - 1] === ' ') s -= 1;
  return text.slice(0, s) + text.slice(e);
}

/**
 * 새 보간 토큰을 텍스트 **끝에 추가**한다(9-a §(8) 1차 — 끝에 추가, 가장 단순·비파괴).
 *
 * - 원문이 비어 있으면 토큰만(`"{{...}}"`).
 * - 원문이 공백으로 끝나면 그대로 이어 붙이고, 아니면 공백 1개로 구분(`"안녕 {{이름}}"`).
 *
 * 표현식은 부록6 `buildBindingExpression` 으로 shape 안전 형태를 만든다(데이터 미도착 시
 * 런타임 에러 방지 — CLAUDE.md fallback 필수).
 *
 * @param text 원문
 * @param sourceId 소스 식별자(data_source id 또는 상태 scope)
 * @param path scope/소스 루트 이하 점 경로
 * @param shape 데이터 shape(폴백 기본값 결정)
 * @returns 토큰이 끝에 추가된 새 문자열
 */
export function appendBindingSegment(
  text: string,
  sourceId: string,
  path: string,
  shape: 'scalar' | 'array' | 'object',
): string {
  const expr = buildBindingExpression(sourceId, path, shape);
  const base = typeof text === 'string' ? text : '';
  if (base.length === 0) return expr;
  return /\s$/.test(base) ? base + expr : `${base} ${expr}`;
}

/**
 * 새 보간 토큰을 텍스트 **임의 문자 위치**에 삽입한다.
 *
 * `appendBindingSegment`(끝 고정)의 일반화 — 앞/중간/끝 어디든 커서 위치(`charIndex`)에 안전
 * 형태 토큰을 끼운다. `appendBindingSegment` 는 본 함수의 `charIndex = text.length` 특수화로
 * 위임된다(폐기 아님). 토큰 양옆이 비공백 문자면 공백 1개로 구분해 라벨과 붙지 않게 한다.
 *
 * 실측(번들 583파일) 평문+보간 388건 중 69%(앞 19%·중간 24%·멀티 26%)가 끝이 아닌 위치라,
 * "끝에만 추가" 설계로는 다수 사례를 못 만든다 — 임의 위치 삽입이 본 기능의 핵심.
 *
 * @param text 원문
 * @param charIndex 삽입할 문자 인덱스(0~text.length, 범위 밖은 클램프)
 * @param sourceId 소스 식별자
 * @param path scope/소스 루트 이하 점 경로
 * @param shape 데이터 shape(폴백 기본값 결정)
 * @returns 토큰이 charIndex 에 삽입된 새 문자열
 */
export function insertBindingSegment(
  text: string,
  charIndex: number,
  sourceId: string,
  path: string,
  shape: 'scalar' | 'array' | 'object',
): string {
  const expr = buildBindingExpression(sourceId, path, shape);
  const base = typeof text === 'string' ? text : '';
  const idx = Math.max(0, Math.min(charIndex, base.length));
  const before = base.slice(0, idx);
  const after = base.slice(idx);
  // 앞쪽 비공백이면 공백 1개로 구분, 빈/공백이면 그대로.
  const lead = before.length > 0 && !/\s$/.test(before) ? ' ' : '';
  // 뒤쪽 비공백이면 공백 1개로 구분, 빈/공백이면 그대로.
  const trail = after.length > 0 && !/^\s/.test(after) ? ' ' : '';
  return `${before}${lead}${expr}${trail}${after}`;
}

/** param 이름(`p0`/`p1`…) 시퀀스에서 다음 빈 번호를 찾는다(불연속 허용 — 이름 기반 치환). */
export function nextParamName(existing: string[]): string {
  const used = new Set(existing.map((n) => n.trim()));
  let i = 0;
  while (used.has(`p${i}`)) i += 1;
  return `p${i}`;
}

/**
 * 키 값(로케일 문장)의 **임의 문자 위치**에 `{pN}` 자리표시를 삽입한다.
 *
 * 새 칩(데이터 소스) 삽입 시 **편집 중인 로케일**에서, 사용자가 커서를 둔 위치에 자리표시를
 * 넣는다. 양옆 비공백이면 공백 1개로 구분한다(평문과 붙지 않게).
 *
 * @param keyValue 키 값(로케일 문장)
 * @param charIndex 삽입할 문자 인덱스(범위 밖은 클램프)
 * @param paramName 삽입할 param 이름(`p0`/`p1`…)
 * @returns 자리표시가 삽입된 키 값
 */
export function insertPlaceholderAt(
  keyValue: string,
  charIndex: number,
  paramName: string,
): string {
  const base = typeof keyValue === 'string' ? keyValue : '';
  const idx = Math.max(0, Math.min(charIndex, base.length));
  const before = base.slice(0, idx);
  const after = base.slice(idx);
  const lead = before.length > 0 && !/\s$/.test(before) ? ' ' : '';
  const trail = after.length > 0 && !/^\s/.test(after) ? ' ' : '';
  return `${before}${lead}{${paramName}}${trail}${after}`;
}

/**
 * 키 값(로케일 문장) **끝**에 `{pN}` 자리표시를 추가한다.
 *
 * 새 칩 삽입 시 **미편집 로케일**에서, 자리표시를 문장 끝에 자동 추가한다(번역가가 올바른 자리로
 * 옮기도록 유도). 이미 그 자리표시가 있으면(중복 방지) 원문 그대로.
 *
 * @param keyValue 키 값(로케일 문장)
 * @param paramName 추가할 param 이름
 * @returns 자리표시가 끝에 추가된 키 값
 */
export function appendPlaceholder(keyValue: string, paramName: string): string {
  const base = typeof keyValue === 'string' ? keyValue : '';
  if (paramPlaceholderTokens(base).includes(paramName)) return base;
  if (base.length === 0) return `{${paramName}}`;
  return /\s$/.test(base) ? `${base}{${paramName}}` : `${base} {${paramName}}`;
}

/**
 * 키 값(로케일 문장) 안의 `{pN}` 자리표시를 **새 위치로 이동**한다.
 *
 * 기존 `{pN}` 를 제거한 뒤(공백 정리), 제거로 인덱스가 당겨진 것을 보정해 `newCharIndex` 에
 * 다시 삽입한다. 자리표시는 로케일별 독립이라 본 함수는 **단일 로케일 키 값**만 다룬다(다른
 * 로케일 불변). 자리표시가 없으면 원문 그대로.
 *
 * @param keyValue 키 값(로케일 문장)
 * @param paramName 이동할 param 이름
 * @param newCharIndex 이동 목표 문자 인덱스(원문 기준 — 제거 보정은 내부 처리)
 * @returns 자리표시가 이동된 키 값
 */
export function movePlaceholder(
  keyValue: string,
  paramName: string,
  newCharIndex: number,
): string {
  const base = typeof keyValue === 'string' ? keyValue : '';
  // 원문에서 자리표시 1개 위치를 찾는다(이중/단일 모두). 없으면 원문 그대로.
  const tokenRe = new RegExp(`\\{\\{?${paramName}\\}?\\}`);
  const m = tokenRe.exec(base);
  if (!m) return base;
  const tokenStart = m.index;
  const tokenEnd = tokenStart + m[0].length;
  // 토큰 제거(인접 여분 공백 1개 정리) — removePlaceholderFromKeyValue 와 동일 규칙이되 위치 추적.
  let s = tokenStart;
  let e = tokenEnd;
  if (base[e] === ' ') e += 1;
  else if (s > 0 && base[s - 1] === ' ') s -= 1;
  const removed = base.slice(0, s) + base.slice(e);
  // 목표 인덱스 보정 — 제거 구간(s..e)보다 뒤를 가리키면 제거 길이만큼 당긴다.
  const removedLen = e - s;
  let target = Math.max(0, Math.min(newCharIndex, base.length));
  if (target >= e) target -= removedLen;
  else if (target > s) target = s;
  return insertPlaceholderAt(removed, target, paramName);
}

/**
 * 텍스트에 박힌 보간 조각들의 데이터 연결 관점 요약 — InlineBindingSection 행 모델.
 *
 * @param text 분석할 문자열
 * @returns 보간 조각 각각의 표시/편집 정보
 */
export interface InlineBindingRow {
  /** 0-base 보간 순번(치환/해제 anchor) */
  bindingIndex: number;
  /** 원문 토큰(`{{...}}` 전체) */
  expression: string;
  /** 단일 경로 인지 결과 — null 이면 복합(읽기전용 디그레이드) */
  parsed: ParsedBinding | null;
  /** 복합(코드 편집) 여부 — parsed===null && 비어있지 않음 */
  isComplex: boolean;
}

/** 텍스트의 보간 조각을 행 모델로 변환한다(InlineBindingSection 소비). */
export function toInlineBindingRows(text: string): InlineBindingRow[] {
  return extractBindingSegments(text).map((seg) => ({
    bindingIndex: seg.bindingIndex ?? 0,
    expression: seg.raw,
    parsed: seg.parsed ?? null,
    isComplex: (seg.parsed ?? null) === null,
  }));
}

/**
 * 텍스트에서 `{{...}}` 보간 토큰을 모두 제거하고 평문만 남긴다.
 *
 * 평문+보간 혼합 text 의 **평문 조각만** 인라인 편집/키화할 때, 사용자에게 보여 줄 편집용
 * 평문을 만든다. 토큰 제거로 생기는 이중 공백·앞뒤 공백을 정리한다(라벨 가독성). 보간이
 * 박혀 있던 자리 정보는 별도(`extractBindingSegments`)로 보존되며, 본 함수는 표시용 평문만 만든다.
 *
 * @param text 원문
 * @returns 보간 제거·공백 정리된 평문
 */
export function stripBindingTokens(text: string): string {
  if (typeof text !== 'string') return '';
  return text
    // 보간 제거 — 내부 중괄호(객체 리터럴 등) 포함 보간도 통째 제거(BINDING_TOKEN_RE 와 동일 SSoT).
    .replace(new RegExp(BINDING_TOKEN_RE.source, 'g'), ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

/** `$t:` lang 키 토큰 — 평문(라벨) 영역에 박힌 비-custom 키. `custom.*` 는 제외(이미 동적 키). */
const LANG_KEY_TOKEN_RE = /\$t:([a-zA-Z0-9._-]+)/g;
/** 커스텀 키 prefix — `resolveLangLiterals` 가 건드리지 않는 동적 키. */
const CUSTOM_KEY_PREFIX_FOR_RESOLVE = 'custom.';

/**
 * 텍스트의 **평문(라벨) 영역**에 박힌 `$t:<비-custom 키>` 토큰을 그 로케일 번역값(평문)으로
 * 치환한다. `{{...}}` 보간 토큰과 `$t:custom.*` 동적 키는 보존한다.
 *
 * "공통 문구 + 데이터"(Shape A, 예: `"$t:policy.published_at: {{date}}"`)를 이 화면 전용 커스텀
 * 키로 승격(키화)할 때, 키 **값**에 raw `$t:` 가 박히는 것을 막기 위한 평문화다. 평문화하지
 * 않으면 키 값이 `"$t:policy.published_at: {p0}"` 가 되고(=lang 키 참조), 그 노드를 다시 편집하면
 * 그 키 값을 또 키화해 `$t:custom.X|p0={p0}` 가 무한 증식한다. 평문화하면
 * 키 값이 `"발행일: {p0}"` 가 되어 더는 lang 키를 참조하지 않으므로 재귀가 끊긴다.
 *
 * 번역 콜백이 키를 해석하지 못하면(빈 문자열/키 자체 반환) 그 토큰은 **그대로 둔다** — 잘못된
 * 평문화로 라벨을 비우는 것보다 원문 보존이 안전(다음 편집/사전 로드 시 재시도).
 *
 * @param text 원문(평문+lang키+보간 혼합 가능)
 * @param translate `$t:` 키(접두 없이) → 현재 로케일 평문 해석기. 미해석 시 빈 문자열/키 반환.
 * @returns lang 키 토큰이 평문으로 치환된 텍스트(보간/custom 키/위치 보존)
 */
export function resolveLangLiterals(
  text: string,
  translate?: (key: string) => string,
): string {
  if (typeof text !== 'string' || text.length === 0) return typeof text === 'string' ? text : '';
  if (!translate) return text;
  // 보간 토큰은 건드리지 않도록 literal 조각에서만 치환한다.
  return splitInlineSegments(text)
    .map((seg) => {
      if (seg.kind !== 'literal') return seg.raw;
      return seg.raw.replace(LANG_KEY_TOKEN_RE, (whole, key: string) => {
        if (key.startsWith(CUSTOM_KEY_PREFIX_FOR_RESOLVE)) return whole; // 동적 키 보존.
        const resolved = translate(key);
        // 해석 실패(빈 문자열 또는 키 자체) → 원문 토큰 보존(잘못된 평문화 회피).
        return resolved && resolved !== key ? resolved : whole;
      });
    })
    .join('');
}

/**
 * 데이터 든 텍스트의 칩 모델 — 인라인 칩 편집기가 데이터를 칩으로 보이고, 내용 변경 시 키화하기
 * 위한 통합 표현. `deriveChipModel` 의 결과.
 */
export interface ChipModel {
  /** 칩 편집기 표시 값 — `{pN}` 자리표시 문장(`"남은 시도: {p0}회"`). */
  chipValue: string;
  /** 각 `{pN}` 의 원본 `{{...}}` 보간 표현식(키화 시 `|pN={{}}` 부착 순서 SSoT). */
  bindings: string[];
  /** 각 `{pN}` → 데이터 친화 라벨(칩 표시명, 바인딩 경로). */
  paramLabels: Record<string, string>;
  /** 키화 가능 여부 — 데이터(보간)가 1개 이상이고 라벨 평문화가 성공했는가. */
  keyifiable: boolean;
}

// param 값 경계 SSoT — `[^|]*`(다음 `|` 전까지)는 param 값 보간이 파이프(`||` 논리 OR, `| date`/
// `| number` 필터)를 포함하면 그 첫 `|` 를 토큰 구분자로 오인해 값을 잘라낸다(미키화
// named param `|count={{(_global.x || []).length}}` 가 `{{(_global.x ` 까지만 캡처돼 칩이 깨지고
// 뒤 param 이 통째 누락 / `extractParamBindings` 의 키화 경로에서는 D 로 이미 동일 증상
// 발생). 진짜 토큰 경계는 임의의 `|` 가 아니라 **다음 `|<이름>=`** 패턴이다. 부정 lookahead 로
// 다음 param 토큰 시작이 아닌 한 모든 문자를 값으로 삼아 보간 내부 파이프를 보존한다.
// deriveChipModel(미키화) 와 extractParamBindings(키화) 두 경로가 이 SSoT 를 공유해야 정합.
const PARAM_VALUE_BODY = '(?:(?!\\|[a-zA-Z0-9_]+=).)*';
/** `$t:<키>` (옵션 `|name={{}}` named param 들)로 시작하는 텍스트 분해 정규식. */
const LEADING_T_KEY_RE = new RegExp(
  `^\\s*\\$t:([a-zA-Z0-9._-]+)((?:\\|[a-zA-Z0-9_]+=${PARAM_VALUE_BODY})*)`,
);
/** named param 토큰(`|name={{보간}}`) 추출 — 값은 다음 `|<이름>=` 토큰 전까지(보간 내부 파이프 보존). */
const NAMED_PARAM_RE = new RegExp(`\\|([a-zA-Z0-9_]+)=(${PARAM_VALUE_BODY})`, 'g');

/**
 * 보간 표현식(`{{...}}`)에서 칩에 표시할 **친화 라벨**(데이터 경로)을 추출한다.
 *
 * 칩 라벨은 "이 데이터가 무엇인지"를 사람이 알아보게 하는 표시용 문자열이다 — 소스 교체 가능성
 * 판정(`parseBindingExpression`)과는 다른 목적이다. published_at 같은 데이터는 흔히 표시 변환
 * **파이프 필터**(`| date`/`| number`)를 동반하는데(`{{termsContent?.data?.published_at | date}}`),
 * `parseBindingExpression` 은 파이프 보간을 복합식으로 보고 null 을 반환한다(소스 교체 불가는 맞다).
 * 종전엔 그 null 일 때 칩 라벨로 **raw 표현식 전체**를 박아(`{{termsContent?.data?.published_at
 * | date}}`), 칩이 깨져 보이고 contentEditable 렌더에서 글자 사이 커서 삽입 시 `{{te rmsContent…`
 * 로 박히는 결함이 났다. 라벨은 파이프 **앞** 경로만 보면 되므로:
 *
 *  1. 단일 경로(파이프 없음) → `parseBindingExpression` 의 path(또는 sourceId).
 *  2. 파이프 필터 동반 → 첫 `|` 앞부분을 잘라 경로 추출(옵셔널 체이닝/폴백 흡수 후 마지막 세그먼트
 *     기준 경로). data_source 면 `data.X` 형태, 상태 scope 면 scope 제외 경로.
 *  3. 진짜 복합식(함수/연산/삼항) → 경로 추출 불가 → 중괄호/공백만 정리한 식(차선, raw `{{` 미노출).
 *
 * @param expression `{{...}}` 보간 토큰 전체
 * @returns 칩 표시용 친화 라벨(raw `{{`/`?.`/`|` 미노출)
 */
export function bindingChipLabel(expression: string): string {
  if (typeof expression !== 'string') return '';
  // 1) 단일 경로(파이프/연산 없음)는 parseBindingExpression 으로 정확히.
  const parsed = parseBindingExpression(expression);
  if (parsed) return parsed.path || parsed.sourceId;
  // 2) 파이프 필터 동반 — 첫 top-level `|` (이중 `||` 제외) 앞부분만 떼서 단일 경로로 재시도.
  const inner = expression.trim().replace(/^\{\{\s*/, '').replace(/\s*\}\}$/, '');
  let pipeIdx = -1;
  for (let i = 0; i < inner.length; i++) {
    if (inner[i] === '|' && inner[i + 1] !== '|' && inner[i - 1] !== '|') {
      pipeIdx = i;
      break;
    }
  }
  if (pipeIdx > 0) {
    const head = inner.slice(0, pipeIdx).trim();
    const headParsed = parseBindingExpression(`{{${head}}}`);
    if (headParsed) return headParsed.path || headParsed.sourceId;
    // 파이프 앞이 그래도 복합이면 그 경로만 정리(체이닝/폴백 흡수).
    const cleaned = head.replace(/\?\./g, '.').replace(/\s*\?\?.*$/, '').trim();
    if (/^[A-Za-z_$][\w$.]*$/.test(cleaned)) {
      const segs = cleaned.split('.').filter(Boolean);
      // 상태 scope 머리는 제거(경로만), data_source 는 전체 경로.
      return segs.length > 1 ? segs.slice(1).join('.') || cleaned : cleaned;
    }
  }
  // 3) 진짜 복합식(함수/연산/삼항) — 식 끝이 `.identifier` 접근(예: `(_global.x || []).length`,
  //  `foo?.bar?.total`)이면 그 **마지막 식별자**를 칩 라벨로 뽑는다(`(_global.x || []).length`
  //    가 칩 라벨로 통째 박혀 깨져 보이던 결함). 식별자로 끝나지 않는 식(함수 호출 `)` 종료,
  //    리터럴 등)은 종전대로 중괄호/공백 정리한 식을 차선 라벨로(raw `{{` 미노출 보장).
  const cleanedInner = inner.replace(/\s+/g, ' ').trim();
  // trailing 폴백(`?? 0` / `|| []` / `|| 0`)을 먼저 떼면 식 끝이 경로 접근으로 드러나는 경우가
  // 많다(`foo?.bar?.total || 0` → `foo?.bar?.total`). 단 `(x || []).length` 처럼 폴백이 괄호 안에
  // 있으면 식 끝(`.length`)이 이미 식별자라 이 제거는 무영향(top-level 폴백만 흡수).
  const withoutTailFallback = cleanedInner.replace(/\s*(\?\?|\|\|)\s*(\[\s*\]|\{\s*\}|'[^']*'|"[^"]*"|\d+|null|undefined)\s*$/, '').trim();
  const tailIdent = /\.([A-Za-z_$][\w$]*)\s*$/.exec(withoutTailFallback);
  if (tailIdent) return tailIdent[1];
  return cleanedInner;
}

/**
 * 설정 참조 토큰 — `$core_settings:`/`$module_settings:`/`$plugin_settings:` (+명시 ID).
 * 보간(`{{...}}`) 이 아니라 설정값 직접 참조다(SeoVarsEditor/og.site_name 등 값 칸에서 사용).
 * DataChipValueInput 과 I18nTextField 가 공유하는 칩 시각화 SSoT(I18nTextField
 * 가 설정참조를 평문으로 떨궈 raw `$core_settings:general.site_name` 가 노출되던 결함 근본 해소).
 */
export const SETTINGS_REF_RE = /\$(?:core|module|plugin)_settings:[a-zA-Z0-9._:-]+/g;

/**
 * 값에 설정 참조(`$*_settings:`)가 들어 있는지.
 *
 * @param value 값 문자열
 * @return 설정 참조 포함 여부
 */
export function hasSettingsRef(value: unknown): boolean {
  return typeof value === 'string' && new RegExp(SETTINGS_REF_RE.source).test(value);
}

/**
 * 설정 참조 토큰의 친화 라벨 — 마지막 경로 세그먼트(`$core_settings:general.site_name` → `site_name`).
 * 명시 ID 형식(`$module_settings:sirsoft-ecommerce:shop.name`)은 콜론/점 중 마지막 식별자.
 *
 * @param ref 설정 참조 토큰(예: `$core_settings:general.site_name`)
 * @return 친화 라벨
 */
export function settingsRefLabel(ref: string): string {
  if (typeof ref !== 'string') return '';
  const body = ref.replace(/^\$(?:core|module|plugin)_settings:/, '');
  const seg = body.split(/[.:]/).filter(Boolean).pop();
  return seg || ref;
}

/** 값 칩 시각화 토큰 — 평문(text) 또는 데이터/설정 칩(chip). */
export interface ValueChipToken {
  kind: 'text' | 'chip';
  /** text=원문 평문, chip=친화 라벨 */
  label: string;
}

/**
 * 값 문자열을 칩/평문 토큰으로 분해한다(읽기 시각화 SSoT). `{{바인딩}}` 은 친화 칩(bindingChipLabel),
 * `$*_settings:` 설정 참조도 칩(settingsRefLabel), 그 외 평문은 텍스트. 키화·편집 없이 "무엇이 들어
 * 있는지" 보여 주기 전용 — DataChipValueInput(값 칸)·I18nTextField(설정참조 칩) 공유.
 *
 * @param value 값 문자열
 * @return 칩/평문 토큰 배열
 */
export function toValueChipTokens(value: string): ValueChipToken[] {
  const out: ValueChipToken[] = [];
  for (const seg of splitInlineSegments(value)) {
    if (seg.kind === 'binding') {
      out.push({ kind: 'chip', label: bindingChipLabel(seg.raw) });
      continue;
    }
    // literal 조각 안의 `$*_settings:` 설정 참조도 칩으로(나머지는 평문).
    let last = 0;
    const text = seg.raw;
    const re = new RegExp(SETTINGS_REF_RE.source, 'g');
    let m: RegExpExecArray | null;
    while ((m = re.exec(text)) !== null) {
      if (m.index > last) out.push({ kind: 'text', label: text.slice(last, m.index) });
      out.push({ kind: 'chip', label: settingsRefLabel(m[0]) });
      last = m.index + m[0].length;
    }
    if (last < text.length) out.push({ kind: 'text', label: text.slice(last) });
  }
  return out;
}

/** 값에 데이터 칩(`{{}}`) 또는 설정 참조(`$*_settings:`)가 섞여 있는지(칩 시각화 대상 판정 SSoT). */
export function hasValueChipContent(value: unknown): boolean {
  if (typeof value !== 'string') return false;
  if (/\{\{/.test(value)) return true;
  return hasSettingsRef(value);
}

/**
 * 데이터 든 텍스트(전 Shape)를 칩 모델로 분해한다 ("한두 건 통과 선언 금지"
 * 후 번들 전수조사로 확정한 전 형태 커버).
 *
 * 지원 Shape:
 *  1. 순수 평문 + 보간: `"회원 {{user.id}}"` → chipValue `"회원 {p0}"`.
 *  2. lang키 + 구분자 + 보간: `"$t:policy.published_at: {{date}}"` → lang키 평문화 후 `"발행일: {p0}"`.
 *  3/4. lang키 + 이름있는 param: `"$t:...remaining_attempts|count={{Math.max(...)}}"` →
 *       lang키 값(`"남은 시도: {{count}}회"`)의 `{{count}}` 를 그 param 의 보간으로 매핑해
 *       `"남은 시도: {p0}회"`. (28건 — 종전 buildParamizedKeyValue 단순 변환이 lang값 {{count}}
 *       와 노드 |count={{}} 를 이중으로 {p0}/{p1} 화해 칩이 깨지던 결함의 근본 해결.)
 *  - custom 키(`$t:custom.X|...`)는 이미 키화됨 — 본 함수 대상 아님(extractParamBindings 경로).
 *
 * 처리: ① 선두 `$t:<non-custom 키>` + named param 분해 → 키 값 평문화 + `{{name}}`→`{pN}` 매핑.
 * ② 그 외 inline 보간(키 바깥, Shape 1/2)은 등장 순서대로 `{pN}` 치환. 두 경로의 `{pN}` 번호는
 * 통합 순번(named param 먼저, 그다음 inline). bindings 는 같은 순번의 보간 표현식.
 *
 * @param text 노드 text
 * @param translate `$t:` 키 → 현재 로케일 평문(이름 자리표시 `{{name}}` 포함 가능) 해석기
 * @returns 칩 모델. 데이터 0 또는 평문화 실패 시 keyifiable:false.
 */
export function deriveChipModel(
  text: string,
  translate?: (key: string) => string,
): ChipModel {
  const empty: ChipModel = { chipValue: '', bindings: [], paramLabels: {}, keyifiable: false };
  if (typeof text !== 'string' || text.length === 0) return empty;

  const bindings: string[] = [];
  const paramLabels: Record<string, string> = {};
  // 보간 표현식을 다음 칩 번호로 등록하고 자리표시 토큰(`{pN}`)을 돌려준다.
  const registerBinding = (expr: string): string => {
    const name = `p${bindings.length}`;
    bindings.push(expr);
    // 칩 친화 라벨 — bindingChipLabel 이 파이프 필터(`| date`) 보간도 경로(`data.published_at`)로
    // 추출.
    paramLabels[name] = bindingChipLabel(expr);
    return `{${name}}`;
  };

  let working = text;
  let labelFromLangValue = '';

  // ① 선두 lang키(+named param) 분해. custom 키는 제외(이미 키화 — 본 함수 비대상).
  const m = LEADING_T_KEY_RE.exec(text);
  if (m && !m[1].startsWith(CUSTOM_KEY_PREFIX_FOR_RESOLVE)) {
    const langKey = m[1];
    const paramsStr = m[2] ?? '';
    const rest = text.slice(m[0].length); // 키/param 뒤 나머지(구분자 + inline 보간 등).
    // named param 보간을 이름→표현식 으로 수집.
    const namedBindings = new Map<string, string>();
    const pre = new RegExp(NAMED_PARAM_RE.source, 'g');
    let pm: RegExpExecArray | null;
    while ((pm = pre.exec(paramsStr)) !== null) {
      if (pm[2] && pm[2].includes('{{')) namedBindings.set(pm[1], pm[2]);
    }
    // lang키 값 평문화(이름 자리표시 `{{name}}`/`{name}` 포함 가능).
    const langValue = translate ? translate(langKey) : '';
    if (langValue && langValue !== langKey && !langValue.startsWith('$t:')) {
      // 값 안의 `{{name}}`/`{name}` 를 그 param 의 보간 → `{pN}` 칩으로 치환.
      labelFromLangValue = langValue.replace(/\{\{?([a-zA-Z0-9_]+)\}?\}/g, (whole, name: string) => {
        const expr = namedBindings.get(name);
        return expr ? registerBinding(expr) : whole;
      });
    } else {
      // lang 키 평문화 실패(사전 미로드) — 라벨이 빠지면 칩 문장이 빈약하거나 raw 키가 노출된다.
      // 칩 편집기로 띄우지 말고 평문 편집기 폴백(keyifiable:false). 선두 lang 키가 있었으므로 폴백.
      return empty;
    }
    working = rest; // 나머지(구분자+inline 보간)만 ② 단계로.
  }

  // ② 나머지(또는 Shape 1 전체) inline 보간을 순서대로 `{pN}` 치환(평문 보존). lang 키 영역 평문화도 함께.
  const restResolved = resolveLangLiterals(working, translate);
  const inlinePart = splitInlineSegments(restResolved)
    .map((seg) => (seg.kind === 'binding' ? registerBinding(seg.raw) : seg.raw))
    .join('');

  // 칩 값 = lang 값 평문(이름 자리표시 치환됨) + 나머지 inline 부분.
  const chipValue = `${labelFromLangValue}${inlinePart}`;
  // 평문화 후에도 raw `$t:` 가 남으면(미해석) 칩 불가 → 폴백.
  const hasUnresolved = /\$t:[a-zA-Z0-9._-]+/.test(chipValue);
  if (hasUnresolved || bindings.length === 0) return empty;
  return { chipValue, bindings, paramLabels, keyifiable: true };
}

/**
 * 칩 모델 기반 키화 텍스트 빌더 — 키 토큰 뒤에 칩 모델의 보간들을 `|pN={{}}` 로 부착한다
 * `buildParamizedKeyText`(원본 text 의 보간 추출)와 달리,
 * 이미 칩 모델로 정규화된 bindings 순서를 그대로 쓴다(named param lang키처럼 원본 보간 순서와
 * 칩 순서가 다른 경우 정확).
 *
 * @param keyToken `$t:custom.*` 키 토큰
 * @param model deriveChipModel 결과
 * @returns param 부착 키 텍스트. 보간 0 이면 keyToken 그대로.
 */
export function buildKeyTextFromChipModel(keyToken: string, model: ChipModel): string {
  if (!model.bindings.length) return keyToken;
  return keyToken + model.bindings.map((b, i) => `|p${i}=${b}`).join('');
}

/**
 * param 정규화 — 키 텍스트 빌더.
 *
 * 평문+보간 혼합 노드를 인라인 편집해 평문을 `$t:custom.*` 키로 승격할 때, 원본에 박혀 있던
 * 보간을 **param** 으로 키 뒤에 부착한다 — `"$t:custom.X|p0={{보간0}}|p1={{보간1}}..."`.
 * 이는 `TranslationEngine` 이 이미 완비한 `$t:key|p0={{..}}` 파라미터 치환 경로(검증된 param형
 * 239건이 동작 중)와 동일 형식이라 **엔진 무변경**으로 동작한다.
 *
 * 키 값 템플릿(`buildParamizedKeyValue`)이 `{p0}`/`{p1}` 자리표시로 보간 위치를 보존하므로,
 * 번역가는 키를 문장 통째로 번역하며 자리만 유지하면 된다(어순 자유 + 위치/개수 완전 보존).
 *
 * @param keyToken `$t:custom.*` 형태의 키 토큰(접두 `$t:` 포함)
 * @param originalText 보간을 추출할 원본 text
 * @returns param 부착 키 텍스트. 보간이 0 이면 keyToken 그대로.
 */
export function buildParamizedKeyText(keyToken: string, originalText: string): string {
  const tokens = extractBindingSegments(originalText).map((s) => s.raw);
  if (tokens.length === 0) return keyToken;
  // 등장 순서대로 |p{i}={{보간}} 부착. PARAM_PATTERN(/([^=|&]+)=([^|&]+)/) 와 placeholder
  // 보호로 토큰 내부 |/& 는 안전(TranslationEngine.parseParams).
  const params = tokens.map((tok, i) => `|p${i}=${tok}`).join('');
  return `${keyToken}${params}`;
}

/**
 * param 정규화 — 키 값 템플릿 빌더.
 *
 * 원본 text 의 평문은 그대로 두고, 각 보간을 등장 순서대로 `{p0}`/`{p1}` 자리표시로 치환한
 * 문장을 만든다 — 이 값이 `createCustomKey` 의 초기 로케일 값(번역가가 번역할 문장)이다.
 * 런타임에 `TranslationEngine.replaceParams` 가 `{p0}` 를 보간 평가값으로 치환한다.
 *
 * 예: `"{{a}} 작성 {{b}}"` → `"{p0} 작성 {p1}"`. 보간 0 이면 원본 평문 그대로.
 *
 * @param originalText 원본 text(평문+보간 혼합)
 * @returns 자리표시 문장(키 값 템플릿)
 */
export function buildParamizedKeyValue(originalText: string): string {
  if (typeof originalText !== 'string') return '';
  const segs = splitInlineSegments(originalText);
  let bindingSeq = 0;
  return segs
    .map((s) => (s.kind === 'binding' ? `{p${bindingSeq++}}` : s.raw))
    .join('');
}

/** param 정규화 — 키 + param 보간 목록 역분해 결과의 한 param. */
export interface ExtractedParam {
  /** param 이름(`p0`/`p1`…) */
  name: string;
  /** param 값 — `{{...}}` 보간 토큰 전체 */
  expression: string;
  /** 단일 경로 파싱 결과 — null 이면 복합(읽기전용 디그레이드) */
  parsed: ParsedBinding | null;
}

/** param 부착 키 텍스트 판정 — `$t:...|p0=...` 형태(접두 공백 허용, 값 보간 내 파이프 허용). */
const PARAMIZED_KEY_RE = new RegExp(
  `^\\s*\\$t:([a-zA-Z0-9._-]+)((?:\\|[a-zA-Z0-9_]+=${PARAM_VALUE_BODY})+)\\s*$`,
);
/** 단일 param 토큰 분해 — `|p0={{보간}}`. 값은 다음 `|<이름>=` 토큰 전까지(보간 내부 파이프 보존). */
const PARAM_TOKEN_RE = new RegExp(`\\|([a-zA-Z0-9_]+)=(${PARAM_VALUE_BODY})`, 'g');

/**
 * param 부착 키 텍스트를 키 + param 목록으로 역분해한다.
 *
 * `"$t:custom.X|p0={{a}}|p1={{b}}"` → `{ key: 'custom.X', params: [{name:'p0', expression:'{{a}}', …}] }`.
 * 데이터 연결 영역(InlineBindingSection)이 param 값(`{{...}}`)을 행으로 노출해 교체/해제하는 데 쓴다.
 * `TranslationEngine.PARAM_PATTERN` 의 분해 규칙과 정합(같은 `|key=value` 모델).
 *
 * param 부착 키가 아니면 null.
 *
 * @param keyParamText 검사할 text
 * @returns 키 + param 목록 또는 null
 */
export function extractParamBindings(
  keyParamText: string,
): { key: string; params: ExtractedParam[] } | null {
  if (typeof keyParamText !== 'string') return null;
  const m = PARAMIZED_KEY_RE.exec(keyParamText);
  if (!m) return null;
  const key = m[1];
  const paramsStr = m[2];
  const params: ExtractedParam[] = [];
  const re = new RegExp(PARAM_TOKEN_RE.source, 'g');
  let pm: RegExpExecArray | null;
  while ((pm = re.exec(paramsStr)) !== null) {
    const name = pm[1];
    const expression = pm[2];
    params.push({ name, expression, parsed: parseBindingExpression(expression) });
  }
  return { key, params };
}

/** 텍스트가 param 부착 키(`$t:...|p..=..`)인지. */
export function isParamizedKeyText(text: unknown): boolean {
  return typeof text === 'string' && PARAMIZED_KEY_RE.test(text);
}

/**
 * 키 값(자리표시 문장)에서 `{p0}`/`{{p0}}` 자리표시 집합을 추출한다.
 *
 * 번역 탭 자리표시 불변 가드용 — 번역가가 로케일 값을 자유 편집해도 `{p0}` 자리표시 멀티셋은
 * 보존돼야 한다(param 치환이 깨지면 보간이 사라짐). `{p0}`(단일)·`{{p0}}`(이중) 둘 다 인지하되
 * 비교는 param 이름(`p0`) 멀티셋으로 정규화한다(중괄호 개수 무관).
 *
 * @param keyValue 키 값(로케일 번역 문장)
 * @returns 자리표시 param 이름 멀티셋(정렬)
 */
export function paramPlaceholderTokens(keyValue: string): string[] {
  if (typeof keyValue !== 'string') return [];
  const out: string[] = [];
  // {{p0}} 와 {p0} 모두 매칭 — 이중을 먼저 흡수하도록 단일 정규식으로 이름만 추출.
  const re = /\{\{?(p\d+)\}?\}/g;
  let m: RegExpExecArray | null;
  while ((m = re.exec(keyValue)) !== null) {
    out.push(m[1]);
  }
  return out.sort();
}

/** 두 자리표시 문장의 `{p0}` 자리표시 집합이 동일한지 — 번역 탭 자리표시 불변 가드. */
export function sameParamPlaceholderSet(a: string, b: string): boolean {
  const ta = paramPlaceholderTokens(a);
  const tb = paramPlaceholderTokens(b);
  if (ta.length !== tb.length) return false;
  return ta.every((tok, i) => tok === tb[i]);
}

/**
 * param 부착 키 텍스트에서 지정 param 의 보간 값을 새 표현식으로 교체한다.
 *
 * `"$t:custom.X|p0={{a}}|p1={{b}}"` 에서 `p1` 을 `{{c}}` 로 → `"$t:custom.X|p0={{a}}|p1={{c}}"`.
 * 키·다른 param·자리표시는 보존(데이터 연결 영역의 소스 교체). param 부착 키가 아니거나 해당
 * param 이 없으면 원문 그대로.
 *
 * @param keyParamText param 부착 키 텍스트
 * @param paramName 교체할 param 이름(`p0`/`p1`…)
 * @param newExpression 새 `{{...}}` 표현식
 * @returns 교체된 텍스트
 */
export function replaceParamBinding(
  keyParamText: string,
  paramName: string,
  newExpression: string,
): string {
  if (typeof keyParamText !== 'string') return keyParamText;
  // `|p1=...`(다음 `|<이름>=` 토큰 전까지)만 정확히 치환. 이름 경계(`=`)로 부분 일치 방지.
  // 값 경계는 PARAM_VALUE_BODY 와 동일 — 보간 내부 파이프(`| date`)를 토큰 구분자로 오인하지 않는다.
  const re = new RegExp(`(\\|${paramName}=)${PARAM_VALUE_BODY}`);
  if (!re.test(keyParamText)) return keyParamText;
  return keyParamText.replace(re, `$1${newExpression}`);
}

/**
 * param 부착 키 텍스트에서 지정 param 을 제거하고, 키 값의 `{pN}` 자리표시도 함께 제거할 수
 * 있도록 제거된 param 이름을 알린다.
 *
 * text 에서 `|pN={{..}}` 토큰만 제거한다(키·다른 param 보존). 키 값(로케일 문장)의 `{pN}`
 * 자리표시 제거는 호출자가 `removePlaceholderFromKeyValue` 로 별도 수행한다(text 와 키 값은
 * 다른 저장소 — text=레이아웃 JSON, 키 값=custom_translations). 남은 param 이 0 이면 키 단독.
 *
 * @param keyParamText param 부착 키 텍스트
 * @param paramName 제거할 param 이름
 * @returns 제거된 텍스트
 */
export function removeParamBinding(keyParamText: string, paramName: string): string {
  if (typeof keyParamText !== 'string') return keyParamText;
  // 값 경계는 PARAM_VALUE_BODY 와 동일 — 보간 내부 파이프(`| date`)를 토큰 구분자로 오인하지 않는다.
  const re = new RegExp(`\\|${paramName}=${PARAM_VALUE_BODY}`);
  return keyParamText.replace(re, '');
}

/**
 * 키 값(로케일 문장)에서 지정 `{pN}` 자리표시를 제거한다.
 *
 * param 을 해제하면 그 보간이 더는 평가되지 않으므로, 키 값에 남은 `{pN}`/`{{pN}}` 자리표시는
 * raw 로 노출된다. 자리표시 토큰과 인접 여분 공백 하나를 정리해 제거한다(라벨/문장 보존).
 *
 * @param keyValue 키 값(로케일 문장)
 * @param paramName 제거할 param 이름(`p0`/`p1`…)
 * @returns 자리표시 제거된 키 값
 */
export function removePlaceholderFromKeyValue(keyValue: string, paramName: string): string {
  if (typeof keyValue !== 'string') return keyValue;
  return keyValue
    .replace(new RegExp(`\\s*\\{\\{?${paramName}\\}?\\}\\s*`, 'g'), ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

/**
 * 두 문자열의 보간 토큰 **집합(순서 무관 멀티셋)**이 동일한지 — 번역 탭 토큰 불변 가드(쟁점 5).
 *
 * 번역 탭에서 사용자가 로케일 값을 자유 편집할 때 `{{...}}` 토큰을 실수로 변형/삭제하면
 * 바인딩이 깨진다. 저장 전 "원본 대비 토큰 멀티셋 불변"을 검증하는 데 쓴다(최소 가드).
 *
 * @param a 기준 문자열
 * @param b 비교 문자열
 * @returns 토큰 멀티셋이 같으면 true
 */
export function sameBindingTokenSet(a: string, b: string): boolean {
  const norm = (s: string): string[] =>
    extractBindingSegments(typeof s === 'string' ? s : '')
      .map((seg) => seg.raw.replace(/\s+/g, ''))
      .sort();
  const ta = norm(a);
  const tb = norm(b);
  if (ta.length !== tb.length) return false;
  return ta.every((tok, i) => tok === tb[i]);
}
