/**
 * computedRecipeEngine.ts — 자동 계산(computed) 친화 보기·3단계 틀·미리보기 평가
 *
 *
 * 페이지 설정 [자동 계산] 탭의 변환 엔진. 세 가지 책임:
 *
 *  (a) **친화 보기(프리셋)** — editor-spec `computedRecipes`(템플릿/모듈/플러그인 제공)
 *      의 `expr`(`{paramKey}` 플레이스홀더)을 입력값으로 치환해 `{{ 식 }}` 한 쌍을
 *      만들고(`buildComputedExpr`), 저장된 식을 역해석해 어느 보기인지 찾는다
 *      (`matchComputed`). conditionRecipeEngine 의 `{key}` 치환 패턴을 차용한다.
 *  (b) **3단계 고정 틀(코어 제공)** — `① 어떤 데이터 → ② 무엇을(7동사+조건) → ③ 결과
 *  이름` 조립식 빌더. computedRecipes 와 무관하게 항상 동작한다(-34). 7동사
 *  expr 매핑은 표를 SSoT 로 한다.
 *  (c) **미리보기 평가** — 저장 식을 편집기 샘플 컨텍스트로 평가해 값/타입을 돌려준다
 *      (`evaluateComputedPreview`). 신규 평가 파서 0 — 엔진 `DataBindingEngine.
 *      evaluateExpression` 을 그대로 호출한다(운영 렌더와 동일 식 해석 경로,
 * [[feedback_editor_sample_verify_computed_body_not_just_state]]).
 *
 * 모든 생성 출력은 **`{{ 식 }}` 한 쌍**이다(CLAUDE.md `if`/computed 규칙 — 중첩 보간
 * `{{ {{x}} }}` 미발생). 매칭/역해석은 식 원문을 변경하지 않는다(인식만).
 *
 * @since engine-v1.50.0
 */

import type { ComputedRecipeSpec, ConditionParamSpec } from './specTypes';
import { dataBindingEngine, type BindingContext } from '../../DataBindingEngine';

// ── 친화 보기(프리셋) — spec 주도 build/match ─────────────────────────

/** 정규화된 친화 보기 1건 */
export interface NormalizedComputedRecipe {
  /** 보기 id (computedRecipes 블록의 key) */
  id: string;
  /** 친화 명칭 (`$t:` 키 또는 평문) */
  label?: string;
  /** 입력란 정의 */
  params: ConditionParamSpec[];
  /** 식 본문 (`{{}}` 없이, `{paramKey}` 플레이스홀더) */
  expr: string;
  /** 분류 그룹 — common/more */
  group?: string;
  /** 출처 메타(로더가 부착한 `__source`) — 제공자 배지용 */
  source?: unknown;
}

/** computedRecipes 블록에서 레시피가 아닌 예약 키 */
const NON_RECIPE_KEYS = new Set(['comment']);

/**
 * editor-spec `computedRecipes` 맵을 정규화된 배열로 변환.
 *
 * `expr` 이 문자열이 아닌 항목·예약 키(`comment`)는 건너뛴다. `__source` 는 보기
 * 객체에 부착된 출처 메타로 보존한다(제공자 배지).
 *
 * @param recipes computedRecipes 맵 (병합본)
 * @return 정규화된 보기 배열
 */
export function normalizeComputedRecipes(
  recipes: Record<string, ComputedRecipeSpec> | undefined | null,
): NormalizedComputedRecipe[] {
  if (!recipes || typeof recipes !== 'object') return [];
  const out: NormalizedComputedRecipe[] = [];
  for (const [id, raw] of Object.entries(recipes)) {
    if (NON_RECIPE_KEYS.has(id)) continue;
    if (!raw || typeof raw !== 'object') continue;
    const spec = raw as ComputedRecipeSpec;
    if (typeof spec.expr !== 'string' || spec.expr.length === 0) continue;
    out.push({
      id,
      label: typeof spec.label === 'string' ? spec.label : undefined,
      params: Array.isArray(spec.params) ? spec.params : [],
      expr: spec.expr,
      group: typeof spec.group === 'string' ? spec.group : undefined,
      source: (spec as Record<string, unknown>).__source,
    });
  }
  return out;
}

/**
 * 플레이스홀더 치환 (conditionRecipeEngine 차용 + 가변 후보 확장).
 *
 * 두 형태를 지원한다:
 *  - `{paramKey}` (단일) — 입력값으로 그대로 치환.
 *  - `{paramKey*}` (가변 후보) — 쉼표 구분 다중 입력을 ` ?? ` 체인으로 확장(first_of 등
 *    후보 2~N). 종전 first_of expr 은 `{candidates[0]} ?? {candidates[1]}` 처럼 고정 2개
 *    인덱스를 박았으나, `\w+` 토큰이 `[0]` 첨자를 못 잡아 미치환→깨진 식이 저장됐다
 * `{candidates*}` 가변 토큰 + 쉼표 분해로 후보 개수만큼 동적 생성한다.
 *    빈 입력이면 빈 문자열(호출자 expr 의 fallback 으로 폴백).
 */
function substituteParams(expr: string, params: Record<string, string>): string {
  // 1) 가변 후보 `{key*}` — 쉼표 구분 → ` ?? ` 체인. 뒤따르는 ` ?? ` 연결자까지 함께 잡아,
  //    후보가 비면(빈 프리셋 추가) `?? '{fallback}'` 처럼 식이 `?? ` 로 시작하는 깨진 형태가
  //    되지 않게 한다(빈 → 빈 문자열, 값 있으면 `각후보 ?? `). 단일 `{key}` 와 달리 가변
  //  토큰은 build 전용이라 매칭용 원문 보존이 불필요.
  let out = expr.replace(/\{(\w+)\*\}(\s*\?\?\s*)?/g, (_whole, key: string, conn: string | undefined) => {
    const v = params[key];
    const parts =
      v === undefined || v === null
        ? []
        : String(v).split(',').map((s) => s.trim()).filter(Boolean);
    if (parts.length === 0) return ''; // 후보 없음 → 뒤 `?? ` 까지 통째 제거
    // 후보 체인 + (원래 뒤에 연결자가 있었으면) ` ?? ` 복원.
    return parts.join(' ?? ') + (conn ? ' ?? ' : '');
  });
  // 2) 단일 `{key}`.
  out = out.replace(/\{(\w+)\}/g, (whole, key: string) => {
    const v = params[key];
    return v === undefined ? whole : v;
  });
  return out;
}

/** 앞뒤 `{{ }}` 한 쌍을 벗겨 내부 식만 반환 (없으면 trim 만) */
function stripOuterBraces(expr: string): string {
  const trimmed = expr.trim();
  const m = /^\{\{([\s\S]*)\}\}$/.exec(trimmed);
  return m ? m[1].trim() : trimmed;
}

/** 식 본문을 `{{ 식 }}` 한 쌍으로 감싼다 (이미 감싸져 있으면 그대로) */
function wrapBraces(body: string): string {
  const trimmed = body.trim();
  if (/^\{\{[\s\S]*\}\}$/.test(trimmed)) return trimmed;
  return `{{ ${trimmed} }}`;
}

/**
 * 친화 보기(프리셋)에서 computed 식을 생성.
 *
 * `expr` 의 `{paramKey}` 를 입력값으로 치환한 뒤 **`{{ 식 }}` 한 쌍**으로 감싼다.
 * 입력값에 이미 `{{ }}`(데이터 칩 등)가 있으면 안쪽 식만 추출해 끼운다(중첩 보간 방지).
 *
 * @param recipe 정규화된 보기 (또는 raw expr 을 가진 객체)
 * @param params 입력값 맵 (paramKey → 값)
 * @return `{{ 식 }}` 형태의 computed 식
 */
export function buildComputedExpr(
  recipe: Pick<NormalizedComputedRecipe, 'expr'>,
  params: Record<string, string>,
): string {
  // 입력값이 `{{ }}` 로 감싸져 있으면 안쪽만 — 치환 결과가 중첩 `{{ }}` 가 되지 않도록.
  const unwrapped: Record<string, string> = {};
  for (const [k, v] of Object.entries(params)) {
    unwrapped[k] = typeof v === 'string' ? stripOuterBraces(v) : v;
  }
  const body = substituteParams(recipe.expr, unwrapped);
  return wrapBraces(body);
}

/**
 * 저장된 식을 정규화된 보기들과 역해석 매칭.
 *
 * 각 보기의 `expr` 을 `{paramKey}` → 캡처 그룹 정규식으로 바꿔 저장 식과 대조한다.
 * 가장 먼저 매칭되는 보기를 반환(목록 순서 = 우선순위). 미매칭이면 null.
 *
 * @param exprValue 저장된 computed 식 (`{{ }}` 포함 가능)
 * @param recipes 정규화된 보기 배열
 * @return `{ recipeId, params }` 또는 null
 */
export function matchComputed(
  exprValue: string,
  recipes: NormalizedComputedRecipe[],
): { recipeId: string; params: Record<string, string> } | null {
  if (typeof exprValue !== 'string') return null;
  const body = normalizeWhitespace(stripOuterBraces(exprValue));
  for (const recipe of recipes) {
    const matched = matchExprTemplate(body, recipe.expr, recipe.params);
    if (matched) return { recipeId: recipe.id, params: matched };
  }
  return null;
}

/** 공백 정규화 — 매칭 시 식의 공백 차이를 흡수 */
function normalizeWhitespace(s: string): string {
  return s.replace(/\s+/g, ' ').trim();
}

/** 정규식 특수문자 이스케이프 */
function escapeRegExp(s: string): string {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * `{paramKey}` 플레이스홀더 식 템플릿을 캡처 정규식으로 바꿔 본문과 매칭.
 *
 * 템플릿의 리터럴 부분은 이스케이프하고 공백은 `\s*` 로, `{key}` 는 비탐욕 캡처
 * `(.+?)` 로 바꾼다. 매칭 시 각 캡처를 paramKey 에 대응시킨다.
 *
 * @param body 저장 식 본문(공백 정규화됨, `{{ }}` 제거됨)
 * @param tmplExpr 보기 expr 템플릿(`{paramKey}` 포함)
 * @param params 보기 params (key 순서 = 캡처 순서)
 * @return paramKey→값 또는 null
 */
function matchExprTemplate(
  body: string,
  tmplExpr: string,
  params: ConditionParamSpec[],
): Record<string, string> | null {
  const keys: string[] = [];
  // 가변 후보 토큰(`{key*}`)인 키 집합 — 캡처를 ` ?? ` 체인으로 보고 쉼표로 환원.
  const variadicKeys = new Set<string>();
  // 템플릿을 토큰화: `{key}` / `{key*}` 와 리터럴 조각.
  let pattern = '';
  // 가변 토큰(`{key*}`)은 build 가 뒤따르는 ` ?? ` 연결자까지 함께 생성/제거하므로(빈 후보면
  // 통째 제거), match 도 그 연결자를 토큰에 흡수해 옵셔널로 둔다. `{key*}\s*\?\?\s*` 를 한 토큰으로
  // 잡고, 캡처는 0개 후보(빈 추가)까지 허용하는 `(.*?)` + 연결자 옵셔널.
  const tokenRe = /\{(\w+)(\*?)\}(\s*\?\?\s*)?/g;
  let lastIndex = 0;
  let m: RegExpExecArray | null;
  const normTmpl = normalizeWhitespace(tmplExpr);
  while ((m = tokenRe.exec(normTmpl)) !== null) {
    const literal = normTmpl.slice(lastIndex, m.index);
    pattern += escapeRegExp(literal).replace(/\\ /g, '\\s*');
    const isVariadic = m[2] === '*';
    const hasConn = !!m[3];
    if (isVariadic) {
      variadicKeys.add(m[1]);
      // 가변: 0개 후보(빈) 허용 `(.*?)` + (뒤 연결자가 템플릿에 있었으면) ` ?? ` 옵셔널.
      pattern += '(.*?)';
      if (hasConn) pattern += '(?:\\s*\\?\\?\\s*)?';
    } else {
      pattern += '(.+?)';
      // 단일 토큰 뒤 연결자는 리터럴로 복원(템플릿에 있었으면).
      if (hasConn) pattern += '\\s*\\?\\?\\s*';
    }
    keys.push(m[1]);
    lastIndex = tokenRe.lastIndex;
  }
  pattern += escapeRegExp(normTmpl.slice(lastIndex)).replace(/\\ /g, '\\s*');
  // 공백 유연 매칭: 리터럴 사이 공백은 0개 이상.
  let re: RegExp;
  try {
    re = new RegExp(`^${pattern}$`);
  } catch {
    return null;
  }
  const result = re.exec(body);
  if (!result) return null;
  // 매칭된 캡처가 비어 있거나 닫는 괄호 불균형이면 거부(과탐 방지).
  const out: Record<string, string> = {};
  keys.forEach((key, i) => {
    let captured = (result[i + 1] ?? '').trim();
    // 가변 후보: 캡처된 ` ?? ` 체인을 쉼표 구분으로 환원(편집 패널 binding-list 표시값).
    if (variadicKeys.has(key)) {
      captured = captured
        .split(/\s*\?\?\s*/)
        .map((s) => s.trim())
        .filter(Boolean)
        .join(', ');
    }
    out[key] = captured;
  });
  // 알려진 paramKey 만 노출(템플릿에 없는 캡처 제거) — params 가 비면 캡처 키 그대로.
  if (params.length > 0) {
    const known = new Set(params.map((p) => p.key));
    for (const key of Object.keys(out)) {
      if (!known.has(key)) delete out[key];
    }
  }
  return out;
}

// ── 3단계 고정 틀(코어 제공) — 7동사 build/match ──────────────────────

/** 3단계 틀의 연산 동사 — 표 (7동사) */
export type CustomOp = 'count' | 'sum' | 'filter' | 'toOptions' | 'nth' | 'firstOf' | 'literal';

/** 비교 연산자 — 조건 한 줄 */
export type CustomCmp = '=' | '!=' | '>' | '<' | '>=' | '<=' | 'includes';

/** 조건 한 줄 — `필드 비교 값` */
export interface CustomCond {
  field: string;
  cmp: CustomCmp;
  value: string;
}

/**
 * 3단계 틀 모델 — `① 어떤 데이터 → ② 무엇을(동사+조건) → ③ 결과 이름`.
 *
 * 각 동사가 읽는 필드는 다르다: count/filter 는 conditions, sum 은
 * sumField+conditions, toOptions 는 valueField+labelField, nth 는 index+prop,
 * firstOf 는 candidates+fallback, literal 은 literalValue+literalKind.
 */
export interface CustomComputedModel {
  /** 결과 키 이름(③) — computed 의 key */
  key: string;
  /** ① 어떤 데이터 — 바인딩 식 본문(`{{}}` 없이, 예 `products.data.data`) */
  source?: string;
  /** ② 동사 */
  op: CustomOp;
  /** count/filter/sum 의 조건(AND 다중) */
  conditions?: CustomCond[];
  /** sum 의 합산 필드 */
  sumField?: string;
  /** toOptions 의 value/label 필드 */
  valueField?: string;
  labelField?: string;
  /** nth 의 인덱스 + 속성 경로 */
  index?: string;
  prop?: string;
  /** firstOf 의 후보(2~N) + 기본값 */
  candidates?: string[];
  fallback?: string;
  /** literal 값 + 종류 */
  literalValue?: string;
  literalKind?: 'string' | 'number' | 'boolean';
}

/** 비교 연산자 → JS 식 매핑 (includes 는 메서드 호출) */
function condToJs(cond: CustomCond): string {
  const lhs = `x.${cond.field}`;
  const rhs = formatCondValue(cond.value);
  switch (cond.cmp) {
    case '=':
      return `${lhs} === ${rhs}`;
    case '!=':
      return `${lhs} !== ${rhs}`;
    case '>':
      return `${lhs} > ${rhs}`;
    case '<':
      return `${lhs} < ${rhs}`;
    case '>=':
      return `${lhs} >= ${rhs}`;
    case '<=':
      return `${lhs} <= ${rhs}`;
    case 'includes':
      return `(${lhs} ?? []).includes(${rhs})`;
    default:
      return `${lhs} === ${rhs}`;
  }
}

/** 조건 값 포맷 — 숫자/불리언은 리터럴, 그 외는 따옴표 문자열 */
function formatCondValue(value: string): string {
  const v = value.trim();
  if (v === 'true' || v === 'false') return v;
  if (v !== '' && !Number.isNaN(Number(v))) return v;
  // 이미 따옴표/식이면 그대로(데이터 칩 등)
  if (/^['"].*['"]$/.test(v) || /[.([{]/.test(v)) return v;
  return `'${v.replace(/'/g, "\\'")}'`;
}

/** 조건 배열을 `&&` 결합한 화살표 술어 본문으로 (조건 없으면 `true`) */
function condsToPredicate(conditions: CustomCond[] | undefined): string {
  if (!conditions || conditions.length === 0) return 'true';
  return conditions.map(condToJs).join(' && ');
}

/**
 * 3단계 틀 모델에서 computed 식 생성.
 *
 * 동사별 expr 매핑은 표를 SSoT 로 한다. 최종 출력은 **`{{ 식 }}` 한 쌍**.
 * source 가 비면 빈 배열(`[]`)로 폴백(엔진 평가 안전).
 *
 * @param model 3단계 틀 모델
 * @return `{{ 식 }}` 형태의 computed 식
 */
export function buildCustomComputedExpr(model: CustomComputedModel): string {
  const src = (model.source ?? '').trim() || '[]';
  const arr = `(${src} ?? [])`;
  let body: string;
  switch (model.op) {
    case 'count':
      body = `${arr}.filter(x => ${condsToPredicate(model.conditions)}).length`;
      break;
    case 'sum': {
      const field = (model.sumField ?? '').trim() || 'value';
      body = `${arr}.filter(x => ${condsToPredicate(model.conditions)}).reduce((s, x) => s + (x.${field} ?? 0), 0)`;
      break;
    }
    case 'filter':
      body = `${arr}.filter(x => ${condsToPredicate(model.conditions)})`;
      break;
    case 'toOptions': {
      const vf = (model.valueField ?? '').trim() || 'value';
      const lf = (model.labelField ?? '').trim() || 'label';
      body = `${arr}.map(x => ({ value: x.${vf}, label: x.${lf} }))`;
      break;
    }
    case 'nth': {
      const idx = (model.index ?? '').trim() || '0';
      const prop = (model.prop ?? '').trim();
      body = `${arr}[${idx}]${prop ? `?.${prop}` : ''}`;
      break;
    }
    case 'firstOf': {
      const cands = (model.candidates ?? []).map((c) => c.trim()).filter(Boolean);
      const chain = cands.length > 0 ? cands.join(' ?? ') : "''";
      const fb = (model.fallback ?? '').trim();
      body = fb ? `${chain} ?? '${fb.replace(/'/g, "\\'")}'` : chain;
      break;
    }
    case 'literal': {
      const kind = model.literalKind ?? 'string';
      const v = (model.literalValue ?? '').trim();
      if (kind === 'number') body = v === '' ? '0' : v;
      else if (kind === 'boolean') body = v === 'true' ? 'true' : 'false';
      else body = `'${v.replace(/'/g, "\\'")}'`;
      break;
    }
    default:
      body = '[]';
  }
  return wrapBraces(body);
}

/**
 * 저장된 식을 3단계 틀로 역해석.
 *
 * 동사별 정규식으로 source/조건/필드를 추출한다. 틀로 표현 불가한 식(중첩 cascade·
 * reduce 트리·IIFE 등)은 null → `resolveComputedCard` 가 `advanced` 로 분류.
 * `key` 는 호출자가 채운다(식만으론 결과 이름 불명 — 0 으로 둠).
 *
 * @param exprValue 저장된 computed 식 (`{{ }}` 포함 가능)
 * @return CustomComputedModel(key='') 또는 null
 */
export function matchCustomComputed(exprValue: string): CustomComputedModel | null {
  if (typeof exprValue !== 'string') return null;
  const body = stripOuterBraces(exprValue).trim();

  // count: (SRC ?? []).filter(x => COND).length
  let m = /^\((.+?)\s*\?\?\s*\[\]\)\.filter\(x => (.+)\)\.length$/.exec(body);
  if (m) return { key: '', op: 'count', source: m[1].trim(), conditions: parsePredicate(m[2]) };

  // sum: (SRC ?? []).filter(x => COND).reduce((s, x) => s + (x.F ?? 0), 0)
  m = /^\((.+?)\s*\?\?\s*\[\]\)\.filter\(x => (.+)\)\.reduce\(\(s, x\) => s \+ \(x\.(\w+(?:\.\w+)*) \?\? 0\), 0\)$/.exec(
    body,
  );
  if (m)
    return {
      key: '',
      op: 'sum',
      source: m[1].trim(),
      conditions: parsePredicate(m[2]),
      sumField: m[3],
    };

  // filter: (SRC ?? []).filter(x => COND)
  m = /^\((.+?)\s*\?\?\s*\[\]\)\.filter\(x => (.+)\)$/.exec(body);
  if (m) return { key: '', op: 'filter', source: m[1].trim(), conditions: parsePredicate(m[2]) };

  // toOptions: (SRC ?? []).map(x => ({ value: x.VF, label: x.LF }))
  m =
    /^\((.+?)\s*\?\?\s*\[\]\)\.map\(x => \(\{ value: x\.(\w+(?:\.\w+)*), label: x\.(\w+(?:\.\w+)*) \}\)\)$/.exec(
      body,
    );
  if (m)
    return { key: '', op: 'toOptions', source: m[1].trim(), valueField: m[2], labelField: m[3] };

  // nth: (SRC ?? [])[IDX] 또는 (SRC ?? [])[IDX]?.PROP
  m = /^\((.+?)\s*\?\?\s*\[\]\)\[(.+?)\](?:\?\.(\w+(?:\.\w+)*))?$/.exec(body);
  if (m) return { key: '', op: 'nth', source: m[1].trim(), index: m[2].trim(), prop: m[3] };

  // 그 외(firstOf/literal 은 모호 — 프리셋/직접입력 충돌 회피 위해 틀 역해석 제외)
  // → null(고급 또는 프리셋 매칭에 위임)
  return null;
}

/**
 * 화살표 술어 본문(`A && B && ...`)을 조건 배열로 역해석 (best-effort).
 *
 * `x.field === 'v'` / `x.field > 3` / `(x.field ?? []).includes('v')` 형태를 인식.
 * 인식 못 하는 절은 건너뛴다(틀 밖 → 호출자가 고급 판단).
 */
function parsePredicate(pred: string): CustomCond[] {
  const trimmed = pred.trim();
  if (trimmed === 'true') return [];
  const out: CustomCond[] = [];
  for (const clause of splitTopLevelAnd(trimmed)) {
    const c = parseClause(clause.trim());
    if (c) out.push(c);
  }
  return out;
}

/** 최상위 `&&` 로 분할(괄호 깊이 고려) */
function splitTopLevelAnd(expr: string): string[] {
  const parts: string[] = [];
  let depth = 0;
  let start = 0;
  for (let i = 0; i < expr.length; i++) {
    const ch = expr[i];
    if (ch === '(' || ch === '[' || ch === '{') depth++;
    else if (ch === ')' || ch === ']' || ch === '}') depth--;
    else if (depth === 0 && ch === '&' && expr[i + 1] === '&') {
      parts.push(expr.slice(start, i));
      i++;
      start = i + 1;
    }
  }
  parts.push(expr.slice(start));
  return parts;
}

/** 단일 비교절 역해석 */
function parseClause(clause: string): CustomCond | null {
  // includes: (x.field ?? []).includes(V)
  let m = /^\(x\.(\w+(?:\.\w+)*) \?\? \[\]\)\.includes\((.+)\)$/.exec(clause);
  if (m) return { field: m[1], cmp: 'includes', value: unquote(m[2]) };
  // 비교: x.field OP V
  m = /^x\.(\w+(?:\.\w+)*)\s*(===|!==|>=|<=|>|<)\s*(.+)$/.exec(clause);
  if (m) {
    const cmpMap: Record<string, CustomCmp> = {
      '===': '=',
      '!==': '!=',
      '>': '>',
      '<': '<',
      '>=': '>=',
      '<=': '<=',
    };
    return { field: m[1], cmp: cmpMap[m[2]] ?? '=', value: unquote(m[3]) };
  }
  return null;
}

/** 따옴표 제거(있으면) — 역해석 표시값 */
function unquote(v: string): string {
  const t = v.trim();
  const m = /^'(.*)'$/.exec(t) || /^"(.*)"$/.exec(t);
  return m ? m[1] : t;
}

// ── 카드 해석(우선순위) ──────────────────────────────────────────────

/** resolveComputedCard 결과 — 프리셋/직접만들기(custom)/고급 */
export type ComputedCard =
  | { kind: 'preset'; recipeId: string; params: Record<string, string> }
  | { kind: 'custom'; model: CustomComputedModel }
  | { kind: 'advanced' };

/**
 * 저장 식을 카드 종류로 해석 — 프리셋 → 3단계 틀 → 고급.
 *
 * @param exprValue 저장된 computed 식
 * @param recipes 정규화된 보기 배열
 * @return ComputedCard
 */
export function resolveComputedCard(
  exprValue: string,
  recipes: NormalizedComputedRecipe[],
): ComputedCard {
  const preset = matchComputed(exprValue, recipes);
  if (preset) return { kind: 'preset', recipeId: preset.recipeId, params: preset.params };
  const custom = matchCustomComputed(exprValue);
  if (custom) return { kind: 'custom', model: custom };
  return { kind: 'advanced' };
}

// ── 미리보기 평가 ────────────────────────────────────────────────────

/** evaluateComputedPreview 결과 타입 라벨 */
export type ComputedPreviewType = 'number' | 'string' | 'boolean' | 'list' | 'object' | 'null';

/** evaluateComputedPreview 결과 */
export type ComputedPreviewResult =
  | { ok: true; value: unknown; type: ComputedPreviewType }
  | { ok: false; reason: string };

/** 평가값 → 미리보기 타입 라벨 */
function classifyType(value: unknown): ComputedPreviewType {
  if (value === null || value === undefined) return 'null';
  if (Array.isArray(value)) return 'list';
  switch (typeof value) {
    case 'number':
      return 'number';
    case 'boolean':
      return 'boolean';
    case 'string':
      return 'string';
    default:
      return 'object';
  }
}

/**
 * 저장 식을 편집기 샘플 컨텍스트로 평가해 미리보기 값/타입 반환.
 *
 * 신규 평가 파서 0 — 엔진 `DataBindingEngine.evaluateExpression` 을 그대로 호출한다
 * (운영 렌더와 동일 식 해석 경로). 평가 실패는 `{ ok:false, reason }` 로 돌려주고
 * **저장 식은 보존**한다(미리보기 에러 ≠ 저장 차단-37).
 *
 * `sampleContext` 는 `useBindingCandidates` 가 빌드하는 풀과 동일(sampleData /
 * sampleGlobal._local / states[].initialState / raw.computed). `_computed` 상호
 * 참조(P6)는 호출자가 위상 정렬로 선평가한 값을 컨텍스트에 넣어 전달한다.
 *
 * @param exprValue 저장된 computed 식 (`{{ }}` 포함 가능)
 * @param sampleContext 평가 컨텍스트(샘플 풀)
 * @return 평가 결과(값+타입 또는 실패 사유)
 */
export function evaluateComputedPreview(
  exprValue: string,
  sampleContext: BindingContext,
): ComputedPreviewResult {
  if (typeof exprValue !== 'string' || exprValue.trim() === '') {
    return { ok: false, reason: 'empty' };
  }
  const inner = stripOuterBraces(exprValue);
  try {
    const value = dataBindingEngine.evaluateExpression(inner, sampleContext ?? {}, {
      skipCache: true,
    });
    return { ok: true, value, type: classifyType(value) };
  } catch (e) {
    // 미리보기 평가 실패는 정상 경로(샘플 컨텍스트에 그 데이터가 없을 수 있음) —
    // 같은 자리에 에러 안내만 전환하고 저장 식은 보존(-37). 로그 노이즈 회피.
    const reason = e instanceof Error ? e.message : String(e);
    return { ok: false, reason };
  }
}
