/**
 * conditionRecipeEngine.ts — 친화 조건 레시피 ↔ `if` 표현식 변환
 *
 * 속성 모달 "표시조건" 탭이 친화 조건(12.4.3 A~H) + 파라미터로 `comp.if` 표현식을
 * 합성한다. 표현식 문법 규칙(12.4.2 / CLAUDE.md `if` 평가 규칙):
 *
 *  - 레시피 `expr` 은 **`{{ }}` 없이** 식 본문만 적고, 파라미터 자리는 레시피
 *    전용 플레이스홀더 `{paramKey}`(중괄호 **1개**)를 쓴다. 런타임 `{{}}` 와 구분.
 *  - 여러 조건을 `&&`/`||` 로 결합한 뒤 **최종 결과 전체를 단일 `{{ }}` 한 쌍**
 *    으로 감싼다. 중첩 보간 `{{ {{x}} }}` 는 만들지 않는다(파서 모호성·CLAUDE.md).
 *  - 옵셔널 체이닝(`?.`) / 배열 길이 `(arr?.length ?? 0)` 형태는 레시피 작성자의
 *    `expr` 책임 — 엔진은 치환·결합만 한다.
 *
 * S6-2 의 평탄 맵 형태(`{ id: { label, expr-with-braces } }`)도 흡수한다
 * (`normalizeConditionRecipes`) — 평탄 맵의 `expr` 은 `{{ }}` 를 벗겨 본문으로 쓴다.
 *
 * 모든 함수는 순수 함수.
 *
 * @since engine-v1.50.0
 */

import type {
  ConditionRecipesSpec,
  ConditionOperatorSpec,
  ConditionParamSpec,
} from './specTypes';

/** 결합 연산자 */
export type ConditionCombinator = 'and' | 'or';

/** 빌더가 다루는 조건 1건 — operator 선택 + 파라미터 값 */
export interface ConditionClause {
  /** 선택한 operator value */
  operator: string;
  /** 파라미터 키 → 입력값 */
  params: Record<string, string>;
}

/** 정규화된 조건 후보 */
export interface NormalizedConditionOperator {
  value: string;
  label?: string;
  params: ConditionParamSpec[];
  /** `{{ }}` 없는 식 본문(canonical) — 생성에 쓰인다 */
  expr: string;
  /**
   * 매칭 전용 구조 변종(L2 recipe-local alias). `{{ }}` 벗긴 본문.
   * 역해석에서 `[expr, ...aliases]` 를 모두 시도한다. 생성에는 쓰지 않는다.
   */
  aliases: string[];
}

/** `{{ ... }}` 한 쌍을 벗겨 본문만 (평탄 맵 흡수용) */
function stripOuterBraces(expr: string): string {
  const trimmed = expr.trim();
  const m = /^\{\{([\s\S]*)\}\}$/.exec(trimmed);
  return m ? m[1].trim() : trimmed;
}

/**
 * 외곽 중복 괄호 1겹 제거 — `(x)` → `x`. 괄호가 식 전체를 감쌀 때만(짝이 맞아
 * 양끝이 동일 괄호쌍일 때) 1겹 벗긴다. `(a) && (b)` 처럼 양끝 괄호가 짝이 아닌
 * 경우는 건드리지 않는다.
 *
 * @param s 공백이 제거된 식 본문
 * @return 외곽 괄호 1겹 제거된 식
 */
function stripRedundantOuterParens(s: string): string {
  let cur = s;
  // 양끝이 ( ... ) 이고 그 괄호가 짝일 때만 1겹 제거. 여러 겹이면 반복.
  while (cur.length >= 2 && cur[0] === '(' && cur[cur.length - 1] === ')') {
    let depth = 0;
    let wrapsAll = true;
    for (let i = 0; i < cur.length; i++) {
      if (cur[i] === '(') depth++;
      else if (cur[i] === ')') {
        depth--;
        // 마지막 문자 이전에 depth 가 0 이 되면 양끝 괄호는 한 쌍이 아니다.
        if (depth === 0 && i < cur.length - 1) {
          wrapsAll = false;
          break;
        }
      }
    }
    if (!wrapsAll) break;
    cur = cur.slice(1, -1);
  }
  return cur;
}

/**
 * 표시조건 식의 **의미 보존 정규화** — 손작성 식(`_local.isSaving`)과 recipe
 * 템플릿(`_local?.isSaving`)이 의미상 같으면 같은 정규형으로 수렴시켜 역해석 매칭
 * (matchClauseBody/matchSinglePreset)이 성공하도록 한다. 정규형은 **비교 전용**이며
 * 저장값에는 영향이 없다(무손실 — writeIf 는 사용자/원본 식을 그대로 보존).
 *
 * 적용 변환(조건 평가에서 동치인 것만):
 *  - 모든 공백 제거 (`a === b` → `a===b`)
 *  - 옵셔널 체이닝 흡수 (`?.` → `.`) — 멤버 접근 한정, truthy 판정에서 동치
 *  - 외곽 truthy 캐스팅 흡수 (`!!x` → `x`) — `if` 평가는 truthy 캐스팅이므로 동치.
 *    `!x`(단항 부정)는 의미가 다르므로 흡수하지 않는다.
 *  - 외곽 중복 괄호 1겹 제거 (`(x)` → `x`)
 *
 * 흡수하지 않는 것(의미가 달라질 수 있음): `=== true` / `!= null` 등 명시 비교,
 * 항 순서, 단항 부정.
 *
 * L1 경계: 본 전역 정규화에는 **항상-동치** 변환만 둔다.
 * `X && X.length>0` → `X.length>0` 같은 **준동치(런타임 의미가 달라질 수 있는) 환원은
 * 넣지 않는다** — 전역 정규화가 제3자 템플릿 식 의미를 바꾸면 통제 불가 회귀. 그런 구조
 * 변종은 recipe-local `aliases`(L2)로 그 템플릿 범위에서만 옵트인 흡수한다.
 *
 * @param expr 식 본문(`{{ }}` 없이) 또는 그 일부
 * @return 정규형
 */
export function normalizeConditionExpr(expr: string): string {
  let s = expr.replace(/\s+/g, ''); // 공백 전부 제거
  s = s.replace(/\?\./g, '.'); // 옵셔널 체이닝 → 일반 멤버 접근
  // 외곽 truthy 캐스팅 `!!` 흡수 — 짝수 개의 선행 `!!` 쌍은 truthy 캐스팅이므로 제거.
  // 단항 부정 `!`(홀수) 은 보존한다.
  while (s.startsWith('!!')) s = s.slice(2);
  s = stripRedundantOuterParens(s);
  return s;
}

/**
 * conditionRecipes 블록을 정규화 operator 목록으로.
 *
 * 표준형(`{ operators: [...] }`) 과 S6-2 평탄 맵(`{ id: { label, expr } }`) 을
 * 모두 흡수한다. 평탄 맵의 `expr` 은 `{{ }}` 를 벗겨 본문으로 정규화한다.
 *
 * @param recipes editor-spec 의 conditionRecipes 블록
 * @return 정규화 operator 목록 (선언 순서 보존)
 */
export function normalizeConditionRecipes(
  recipes: ConditionRecipesSpec | Record<string, unknown> | undefined | null,
): NormalizedConditionOperator[] {
  if (!recipes || typeof recipes !== 'object') return [];

  // 표준형 — operators 배열
  const operators = (recipes as ConditionRecipesSpec).operators;
  if (Array.isArray(operators)) {
    return operators
      .filter((o): o is ConditionOperatorSpec => !!o && typeof o.value === 'string' && typeof o.expr === 'string')
      .map((o) => ({
        value: o.value,
        label: typeof o.label === 'string' ? o.label : undefined,
        params: Array.isArray(o.params) ? o.params : [],
        expr: stripOuterBraces(o.expr),
        aliases: normalizeAliasList(o.aliases),
      }));
  }

  // S6-2 평탄 맵 — { id: { label, expr } }
  const out: NormalizedConditionOperator[] = [];
  for (const [id, raw] of Object.entries(recipes as Record<string, unknown>)) {
    if (id === 'comment' || id === 'operators') continue;
    if (!raw || typeof raw !== 'object') continue;
    const o = raw as { label?: unknown; expr?: unknown; params?: unknown; aliases?: unknown };
    if (typeof o.expr !== 'string') continue;
    out.push({
      value: id,
      label: typeof o.label === 'string' ? o.label : undefined,
      params: Array.isArray(o.params) ? (o.params as ConditionParamSpec[]) : [],
      expr: stripOuterBraces(o.expr),
      aliases: normalizeAliasList(o.aliases),
    });
  }
  return out;
}

/**
 * recipe 의 `aliases` 필드를 매칭용 본문 목록으로 정규화(L2).
 *
 * 문자열 배열만 받아들이며, 각 alias 는 `{{ }}` 를 벗겨 본문으로 둔다(canonical
 * `expr` 과 동일 형식 — `{paramKey}` 플레이스홀더 보존). 비문자열/빈 문자열은 제외.
 *
 * @param raw recipe 의 `aliases` 원시 값
 * @return 정규화된 alias 본문 목록 (없으면 빈 배열)
 */
function normalizeAliasList(raw: unknown): string[] {
  if (!Array.isArray(raw)) return [];
  const out: string[] = [];
  for (const item of raw) {
    if (typeof item !== 'string') continue;
    const body = stripOuterBraces(item);
    if (body.trim().length > 0) out.push(body);
  }
  return out;
}

/**
 * 매칭 우선순위(L4 specificity) 점수.
 *
 * 한 식이 여러 recipe 에 매칭될 때 **더 구체적인 것**을 우선해야 범용 `{path}` truthy
 * 가 구체 프리셋(`isSaving` 등)을 가로채지 않는다. 배열 선언 순서 의존을 제거하기 위해
 * 매칭 단계에서 점수 내림차순으로 시도한다(선언 순서는 동점 tie-break).
 *
 *  - 파라미터가 **적을수록** 구체적(파라미터 0개 = 고정 프리셋 = 최우선).
 *  - 고정 토큰(리터럴 문자)이 **많을수록** 구체적(`expr` 에서 `{key}` 를 제외한 길이).
 *
 * @param op 정규화 operator
 * @return specificity 점수 (높을수록 우선)
 */
function specificityScore(op: NormalizedConditionOperator): number {
  // 파라미터 1개당 큰 페널티 — 파라미터 0개를 항상 앞에 둔다.
  const paramPenalty = op.params.length * 1000;
  // 고정 토큰 길이 — `{key}` 플레이스홀더를 제거한 리터럴 길이.
  const literalLen = normalizeConditionExpr(op.expr).replace(/\{\w+\}/g, '').length;
  return literalLen - paramPenalty;
}

/**
 * operator 목록을 specificity 내림차순으로 정렬한 **매칭 전용** 사본을 반환(L4).
 *
 * 원본 배열(선언 순서)은 보존한다 — UI 드롭다운/기본 절 추가는 선언 순서를 쓰므로
 * 매칭 순서만 점수화한다. 동점은 선언 순서(stable)로 tie-break.
 *
 * @param operators 정규화 operator 목록(선언 순서)
 * @return specificity 내림차순 정렬 사본
 */
function operatorsByMatchPriority(
  operators: NormalizedConditionOperator[],
): NormalizedConditionOperator[] {
  return operators
    .map((op, idx) => ({ op, idx, score: specificityScore(op) }))
    .sort((a, b) => (b.score - a.score) || (a.idx - b.idx))
    .map((x) => x.op);
}

/** 단일 조건의 식 본문 합성 — `{paramKey}`(중괄호 1개) 치환 */
function substituteParams(expr: string, params: Record<string, string>): string {
  return expr.replace(/\{(\w+)\}/g, (whole, key: string) => {
    const v = params[key];
    return v === undefined ? whole : v;
  });
}

/**
 * 한 절을 식 본문(중괄호 없음)으로. operator 미발견 시 null.
 *
 * @param clause 조건 절
 * @param operators 정규화 operator 목록
 * @return 식 본문 또는 null
 */
export function buildClauseExpr(
  clause: ConditionClause,
  operators: NormalizedConditionOperator[],
): string | null {
  const op = operators.find((o) => o.value === clause.operator);
  if (!op) return null;
  return substituteParams(op.expr, clause.params ?? {});
}

/**
 * 여러 절을 결합해 **단일 `{{ }}`** 로 감싼 최종 `if` 식을 합성한다.
 *
 * 결합 규칙: 동일 결합자(`and`/`or`)를 한 단계로 보고 절을 `&&`/`||` 로 잇는다.
 * 절이 2개 이상이면 각 절을 괄호로 감싸 우선순위 모호성을 없앤다. 빈 절/미발견
 * operator 는 제외. 유효 절이 없으면 빈 문자열(= if 제거).
 *
 * 중첩 보간 방지: 각 절 식 본문에는 `{{` 가 없으므로(레시피 `expr` 규칙), 최종
 * 한 번만 `{{ }}` 로 감싼다 → `{{ {{x}} }}` 미발생(CLAUDE.md `if` 규칙).
 *
 * @param clauses 조건 절 목록
 * @param combinator 결합자 (and/or)
 * @param operators 정규화 operator 목록
 * @return `{{ ... }}` 또는 빈 문자열
 */
export function combineConditions(
  clauses: ConditionClause[],
  combinator: ConditionCombinator,
  operators: NormalizedConditionOperator[],
): string {
  const bodies = clauses
    .map((c) => buildClauseExpr(c, operators))
    .filter((b): b is string => typeof b === 'string' && b.trim().length > 0);

  if (bodies.length === 0) return '';
  if (bodies.length === 1) return `{{ ${bodies[0]} }}`;

  const joiner = combinator === 'or' ? ' || ' : ' && ';
  const combined = bodies.map((b) => `(${b})`).join(joiner);
  return `{{ ${combined} }}`;
}

/**
 * 한 식 본문(괄호 없음)을 operator + 파라미터로 역해석.
 *
 * operator 의 `expr` 템플릿과 본문을 대조해 `{paramKey}` 위치의 실제 값을 추출한다.
 * 정규식 특수문자는 이스케이프하고, `{key}` 자리는 캡처 그룹으로 바꾼다. 파라미터가
 * 작은따옴표로 감싸진 경우(`=== '{value}'`)는 따옴표 안 값을 추출한다.
 *
 * @return { operator, params } 또는 null(미매칭)
 */
function matchClauseBody(
  body: string,
  operators: NormalizedConditionOperator[],
): ConditionClause | null {
  // 의미 보존 정규화 후 비교 — 손작성 식(`_local.isSaving`)과 recipe 템플릿
  // (`_local?.isSaving`)의 `?.`/`!!`/공백/외곽괄호 표기 차이를 흡수한다.
  const normBody = normalizeConditionExpr(body);
  // L4 — specificity 내림차순으로 시도(범용 `{path}` truthy 가 구체 프리셋을 가로채지
  // 않도록). 선언 순서 의존을 제거하고 매칭 우선순위만 점수화한다.
  for (const op of operatorsByMatchPriority(operators)) {
    if (op.params.length === 0) {
      // L2 — canonical `expr` + 구조 변종 `aliases` 를 모두 시도(역해석은 다변종 흡수).
      for (const cand of [op.expr, ...op.aliases]) {
        if (normalizeConditionExpr(cand) === normBody) return { operator: op.value, params: {} };
      }
      continue;
    }
    // 파라미터 템플릿 — {key} 자리는 보존하며 그 외 리터럴만 정규화한 뒤 정규식으로.
    // 정규화가 {key} 를 건드리지 않도록 placeholder 를 잠시 보호한 후 normalize.
    // canonical + alias 템플릿을 순서대로 시도(L2 — 배열길이 3변종 등).
    for (const tmpl of [op.expr, ...op.aliases]) {
      const result = matchParamTemplate(op, tmpl, normBody);
      if (result) return result;
    }
  }
  return null;
}

/**
 * 파라미터 보유 operator 의 한 템플릿(`expr` 또는 alias)을 정규화 본문과 대조해 params 추출.
 *
 * `{key}` 플레이스홀더를 토큰 단위로 보호한 채 리터럴 부분만 의미 정규화한 뒤,
 * 정규식(`{key}` → 캡처)으로 정규화 본문을 매칭한다. 따옴표 안 값(`=== '{value}'`)은
 * 따옴표 제외 캡처. 정규화로 공백이 제거되므로 양측 모두 공백 없는 형태에서 비교된다.
 *
 * @param op 파라미터 보유 정규화 operator (반환 operator value 와 키 검증용)
 * @param tmplExpr 대조할 템플릿 본문(canonical `expr` 또는 alias)
 * @param normBody 이미 정규화된 식 본문
 * @return { operator, params } 또는 null
 */
function matchParamTemplate(
  op: NormalizedConditionOperator,
  tmplExpr: string,
  normBody: string,
): ConditionClause | null {
  const tmpl = tmplExpr.trim();
  const keys: string[] = [];
  // 템플릿을 [리터럴 | {key}] 세그먼트로 분해 → 리터럴은 normalize+escape, {key} 는 캡처.
  // {key} 를 텍스트 정규화에 통과시키지 않아 placeholder 가 훼손되지 않는다. 리터럴
  // 세그먼트만 normalizeConditionExpr 로 공백제거·`?.`→`.`·`!!`흡수해 정규화 본문과
  // 같은 형태로 맞춘다. 외곽괄호 제거는 식 전체 기준이라 세그먼트 단위로는 적용되지
  // 않으므로, `({key}...)` 의 바깥 괄호는 리터럴로 보존된다(정규화 본문도 단일 식이라
  // 외곽 괄호가 식 전체를 감싸지 않는 한 유지되어 정합).
  let pattern = '';
  let i = 0;
  while (i < tmpl.length) {
    const m = /^\{(\w+)\}/.exec(tmpl.slice(i));
    if (m) {
      keys.push(m[1]);
      pattern += '(.+?)';
      i += m[0].length;
    } else {
      // 다음 {key} 직전까지의 리터럴 청크를 모아 한 번에 정규화 후 escape.
      let lit = '';
      while (i < tmpl.length && !/^\{(\w+)\}/.test(tmpl.slice(i))) {
        lit += tmpl[i];
        i += 1;
      }
      const normLit = normalizeConditionExpr(lit);
      for (const ch of normLit) pattern += ch.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
  }
  const re = new RegExp('^' + pattern + '$');
  const matched = re.exec(normBody);
  if (!matched) return null;
  const params: Record<string, string> = {};
  // 동일 키가 템플릿에 2회 이상 나오면(예: alias `{path} && {path}.length > 0`) 각 위치의
  // 캡처가 모두 같아야 그 키의 단일 값으로 성립한다. 정규식 `(.+?)` 는 각 위치를 독립
  // 캡처하므로 backreference 검증이 없으면 `!a && b.length > 0`(서로 다른 두 식)을 한 절로
  // 잘못 삼킨다(false-positive). 같은 키의 캡처값이 하나라도 다르면 매칭을 거부한다.
  for (let idx = 0; idx < keys.length; idx++) {
    const k = keys[idx];
    const v = matched[idx + 1];
    if (k in params && params[k] !== v) return null;
    params[k] = v;
  }
  // 어느 키가 따옴표로 감싸였는지 — `'{value}'` / `"{value}"` 형태면 그 키는 자유
  // 리터럴(임의 문자열)이라 path-shape 가드를 적용하지 않는다.
  const quotedKeys = collectQuotedKeys(tmpl);
  for (const [k, v] of Object.entries(params)) {
    // 탐욕적 캡처 false-positive 차단 — 부울 결합자(`&&`/`||`)는 어떤 캡처에도 올 수 없다.
    // compound 식(`A && B !== 'x'`)을 단일 절로 잘못 삼킨 것이므로 매칭을 거부해 호출자가
    // 결합식 분해(parseConditionExpr) 또는 advanced 로 처리하게 한다.
    if (/&&|\|\|/.test(v)) return null;
    // path-shape 가드 — 따옴표 없는 캡처는 필드 경로·데이터소스 id·
    // 숫자 리터럴이므로 비교 연산자(`===`/`!==`/`==`/`!=`/`>`/`<`)·삼항(`?`/`:`)·그룹 괄호·
    // 단항 부정(`!`)을 포함할 수 없다. 포함하면 범용 `{path}` truthy 류 프리셋이 비교식·
    // compound 를 통째로 삼킨 것이므로 거부 → advanced 유지. 범용 프리셋 도입 전 안전망.
    // `!` 추가: 느슨 비교 alias(`{a} == {b}`)가 `r !== s` 를 `a=r!`,`b=s` 로
    // 오삼키는 substring 중첩(`==` ⊂ `!==`)을 차단한다 — 경로에 `!` 가 들어갈 일은 없다.
    if (!quotedKeys.has(k) && /===|!==|==|!=|>=|<=|>|<|\?|:|\(|\)|!/.test(v)) return null;
  }
  return { operator: op.value, params };
}

/**
 * 템플릿에서 따옴표로 감싸인 `{key}` 의 키 집합을 수집(path-shape 가드 예외용).
 *
 * `'{value}'` / `"{value}"` 처럼 직전·직후가 동일 따옴표면 그 키는 자유 리터럴
 * 파라미터로 간주한다. 그 외(따옴표 없는 `{path}`)는 path-shape 검증 대상.
 *
 * @param tmpl 템플릿 본문(trim 됨)
 * @return 따옴표로 감싸인 키 집합
 */
function collectQuotedKeys(tmpl: string): Set<string> {
  const out = new Set<string>();
  const re = /(['"])\{(\w+)\}\1/g;
  let m: RegExpExecArray | null;
  while ((m = re.exec(tmpl)) !== null) out.add(m[2]);
  return out;
}

/**
 * `combineConditions` 가 만든 `if` 식을 절 목록 + 결합자로 역해석한다.
 *
 * 본 엔진이 생성한 형식(`{{ (b1) && (b2) }}` / `{{ b }}`)을 파싱한다. 최상위
 * `&&`/`||` 로 분리하고 각 `(body)` 를 operator+params 로 역해석한다. 임의 손작성
 * 식이거나 한 절이라도 매칭 실패하면 null — 호출자가 "직접 작성된 조건"(고급)으로
 * 표시한다(원칙 4.4 — 무손실). 모달 remount 후 빌더 상태를 노드에서 재구성하는 데 쓴다.
 *
 * @param ifExpr 노드의 현재 `if`
 * @param operators 정규화 operator 목록
 * @return { clauses, combinator } 또는 null
 */
export function parseConditionExpr(
  ifExpr: unknown,
  operators: NormalizedConditionOperator[],
): { clauses: ConditionClause[]; combinator: ConditionCombinator } | null {
  if (typeof ifExpr !== 'string' || ifExpr.trim() === '') return null;
  const body = stripOuterBraces(ifExpr);

  // 단일 절(괄호로 감싸지 않음) — 결합자 없음.
  const single = matchClauseBody(body, operators);
  if (single) return { clauses: [single], combinator: 'and' };

  // 결합식 — 최상위 `) && (` 또는 `) || (` 로 분리. 본 엔진은 각 절을 () 로 감싼다.
  const combinator: ConditionCombinator = body.includes(') || (') ? 'or' : body.includes(') && (') ? 'and' : 'and';
  const joiner = combinator === 'or' ? ') || (' : ') && (';
  if (!body.startsWith('(') || !body.endsWith(')') || !body.includes(joiner)) return null;
  // 양끝 괄호 1개씩 벗기고 joiner 로 분리.
  const inner = body.slice(1, -1);
  const parts = inner.split(joiner);
  const clauses: ConditionClause[] = [];
  for (const part of parts) {
    const c = matchClauseBody(part.trim(), operators);
    if (!c) return null; // 한 절이라도 미매칭 → 전체 고급 취급
    clauses.push(c);
  }
  return { clauses, combinator };
}

/**
 * 기존 `if` 식이 단일 operator 프리셋(파라미터 없음)인지 역해석.
 *
 * AND/OR 결합식·파라미터 치환식은 역해석이 모호하므로(원본 입력 복원 불가),
 * 파라미터 없는 단일 프리셋만 정확 매칭한다. 그 외는 null — 호출자가 "직접 작성된
 * 조건"(고급)으로 표시하고 식 원문을 보여준다(원칙 4.4 — 무손실).
 *
 * @param ifExpr 노드의 현재 `if` 값
 * @param operators 정규화 operator 목록
 * @return matched operator value 또는 null
 */
export function matchSinglePreset(
  ifExpr: unknown,
  operators: NormalizedConditionOperator[],
): string | null {
  if (typeof ifExpr !== 'string') return null;
  // 의미 보존 정규화 후 비교(matchClauseBody 와 동일) — `?.`/`!!`/공백/외곽괄호 차이 흡수.
  const normBody = normalizeConditionExpr(stripOuterBraces(ifExpr));
  // L4 — specificity 우선(matchClauseBody 와 동일 순서). 파라미터 없는 프리셋만 대상.
  for (const op of operatorsByMatchPriority(operators)) {
    if (op.params.length > 0) continue; // 파라미터 있는 조건은 모호 — 제외
    // L2 — canonical + alias 모두 시도(구조 변종 흡수, 생성은 canonical).
    for (const cand of [op.expr, ...op.aliases]) {
      if (normalizeConditionExpr(cand) === normBody) return op.value;
    }
  }
  return null;
}
