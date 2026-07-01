// e2e:allow 순수 파서/직렬화 유틸(React/DOM 0) — round-trip 의미 동일은 단위(expressionValueTree.test
// 골든 134+29 표본)로 전수 검증, 소비 위젯(ConditionalValueEditor)은 다국어/칩 위젯 재귀라 RTL+Chrome
// MCP 매트릭스로 검증. 파서 자체는 브라우저 비의존 순수 함수.
/**
 * expressionValueTree.ts — 표현식 값 분해 트리
 *
 * 페이지 제목·설명·전 propControl 텍스트의 값(node.text)은 7가지 형태로 존재한다:
 *
 *  A 평문            `안녕하세요`
 *  B 단일 다국어키    `$t:board.title`
 *  C 다국어+데이터칩  `$t:board.greeting|p0={{user.name}}`
 *  D 단일 바인딩      `{{product.data.name}}`
 *  E 평문+칩          `회원 {{user.id}}`
 *  F 표현식+다국어    `{{route.id ? '$t:board.edit' : '$t:board.new'}}`
 *  G 표현식+다국어+칩 `{{x ? '$t:a|count=' + n : '$t:b'}}`
 *
 * A~E 는 기존 위젯(I18nTextField / InlinePlaceholderField)이 이미 1-depth 로 처리한다.
 * **F/G(표현식+다국어)** 는 종전 raw readonly 로 디그레이드돼, 비개발자가 분기별 다국어를
 * 편집하지 못했다(`{{...}}` 코드 통째 노출). 본 모듈은 그 식을 **ValueNode 세그먼트 트리**로
 * 분해해, 조건 노드 + 분기(참/거짓)별 리프로 나눈다 — 리프는 다시 I18nTextField 가 재귀
 * 재사용한다(신규 입력기 0).
 *
 * 설계 원칙:
 *  - **통합 모델**: 값 = ValueNode 트리. A~E 는 1-depth, F/G 는 Conditional/Fallback/Concat 노드.
 *  - **조건 편집 범위**: 단순 비교(`===`/`!==`/존재여부/`??`)만 친화 조건 빌더. 복잡 조건은
 *    readonly 식("코드에서 수정") — 조건 로직은 비개발자 영역 밖, 손상 회피.
 *  - **디그레이드(손상 0)**: 파서가 못 푸는 식 → `kind:'raw'` 폴백(현행 raw readonly). 파싱
 *    실패 = 절대 손상 0. **round-trip 안 맞으면 raw 폴백**(parseExpressionValue 가 자기 검증).
 *  - **round-trip**: 트리→직렬화 시 식 의미 동일 복원. `[</> 원본 식 보기]` 상시 동기 검증.
 *
 * 본 모듈은 순수 함수 — React/DOM 의존 0. 토크나이저 + 재귀하강 파서(우선순위: 삼항 < 폴백
 * (`??`/`||`) < 이어붙이기(`+`) < 리프). 리프는 `$t:` 키(따옴표 유무 무관)·`{{바인딩}}`·문자열
 * 리터럴·단순 경로·숫자.
 *
 * @since engine-v1.50.0
 */

/**
 * SEO 단일-경로 추출 함수 이름 — `$localized(<path>)` 처럼 단순 경로 인자 1개를 감싸는 알려진
 * 함수. 이 호출은 그 인자 경로를 단일 바인딩 리프(데이터 칩)로 환원한다(parsePrimary). SEO
 * 메타값(meta_title/name 등 다국어 객체)을 현재 로케일 문자열로 추출하는 코어 SEO 표현식
 * 함수(ExpressionEvaluator) 와 정합. bindingCandidates.PATH_WRAPPER_FNS 와 동일 SSoT.
 */
const PATH_WRAPPER_FN_NAMES: ReadonlyArray<string> = ['$localized'];

/* ────────────────────────────────────────────────────────────────────────────
 * ValueNode 모델
 * ──────────────────────────────────────────────────────────────────────────── */

/** 리프 — 더 분해되지 않는 단말 값. 텍스트 입력기(I18nTextField)가 직접 다룬다. */
export interface LeafNode {
  kind: 'leaf';
  /**
   * 리프 텍스트 — I18nTextField 가 다루는 값 형태(평문 / `$t:key` / `$t:key|pN={{}}` /
   * `{{binding}}` / 평문+칩). 즉 A~E 단일 값. 직렬화 시 이 텍스트를 식 리터럴로 환원한다
   * (`$t:`·평문·칩 → 따옴표 문자열, `{{binding}}` → 보간 식 그대로).
   */
  text: string;
}

/** 조건 분기 — `cond ? then : else` */
export interface ConditionalNode {
  kind: 'conditional';
  /** 조건 — 단순 비교면 구조화(편집 가능), 아니면 readonly 식 문자열 */
  condition: Condition;
  /** 참 분기 */
  then: ValueNode;
  /** 거짓 분기 */
  else: ValueNode;
}

/** 폴백 체인 — `a ?? b` / `a || b`(왼쪽이 비면 오른쪽). 1차 분기 빌더로 표현. */
export interface FallbackNode {
  kind: 'fallback';
  /** 연산자 — `??`(널 병합) 또는 `||`(논리 OR) */
  op: '??' | '||';
  /** 기본(왼쪽) 값 */
  primary: ValueNode;
  /** 폴백(오른쪽) 값 */
  fallback: ValueNode;
}

/** 이어붙이기 — `a + ' ' + b`(문자열 결합). 조각 순서 보존. */
export interface ConcatNode {
  kind: 'concat';
  /** 이어붙일 조각들(2개 이상) */
  parts: ValueNode[];
}

/**
 * raw 폴백 — 파서가 친화적으로 못 푸는 식(함수 호출/산술/중첩 멤버 연산/임의 JS). 현행 raw
 * readonly 로 노출한다(손상 0). 직렬화 시 원문 그대로 환원.
 */
export interface RawNode {
  kind: 'raw';
  /** 원문 식(보간 안쪽 또는 리터럴 그대로) */
  source: string;
}

export type ValueNode = LeafNode | ConditionalNode | FallbackNode | ConcatNode | RawNode;

/* ────────────────────────────────────────────────────────────────────────────
 * Condition 모델 — 단순 비교만 구조화, 그 외 readonly
 * ──────────────────────────────────────────────────────────────────────────── */

/** 단순 비교 조건 — `left op right`(편집 가능: 필드 + 연산 + 값) */
export interface SimpleCondition {
  kind: 'simple';
  /** 비교 대상 식(경로 — `route.id`, `_local.isSaving` 등 원문 보존) */
  left: string;
  /** 비교 연산 — 존재여부(`truthy`)는 right 없음 */
  op: '===' | '!==' | '==' | '!=' | '>' | '<' | '>=' | '<=' | 'truthy' | 'falsy';
  /** 비교값(리터럴 식 — `'comment'`, `0`, `true` 등). truthy/falsy 면 빈 문자열. */
  right: string;
}

/** 복잡 조건 — readonly 식("코드에서 수정"). 손상 회피. */
export interface RawCondition {
  kind: 'raw';
  /** 조건 원문 식 */
  source: string;
}

export type Condition = SimpleCondition | RawCondition;

/* ────────────────────────────────────────────────────────────────────────────
 * 진입점 — parse / serialize / shape
 * ──────────────────────────────────────────────────────────────────────────── */

/** parseExpressionValue 결과 — 트리 + 분해 성공 여부 + 원문 */
export interface ParsedValue {
  /** 분해 트리(실패 시 단일 raw 노드) */
  node: ValueNode;
  /**
   * 친화 분해 성공 여부 — true 면 ConditionalValueEditor 가 트리 UI 로, false 면 현행
   * raw readonly 폴백. round-trip(직렬화→재파싱 의미 동일) 자기검증 통과 시에만 true.
   */
  decomposed: boolean;
  /** 원문 — 입력 그대로(라운드트립 비교 기준) */
  original: string;
}

/**
 * 문자열 전체가 **단일** `{{...}}` 한 쌍인지 — 안쪽 식(F/G)을 추출한다. 시작 `{{` 와 짝이 맞는
 * 닫는 `}}` 가 **문자열 끝**일 때만 단일 래핑. 짝이 문자열 중간에서 닫히면(`{{a}} - {{b}}` 다중
 * 보간) null.
 *
 * 중첩 보간 인식: 분기 문자열 리터럴 안의 칩
 * (`'$t:key|p0={{x ?? ''}}'`)처럼 `{{...}}` 가 또 끼어 있을 수 있다. 단순 `inner.includes('}}')`
 * 는 그 칩의 `}}` 를 다중 보간으로 오인했다. `{{`/`}}` 중첩 깊이를 추적해 **바깥 한 쌍의 짝**만
 * 본다(문자열 리터럴 안의 `{{...}}` 도 깊이로 정확히 흡수).
 */
function singleWrapInner(s: string): string | null {
  const t = s.trim();
  if (!t.startsWith('{{') || !t.endsWith('}}') || t.length < 4) return null;
  // 시작 `{{` 와 짝이 맞는 닫는 `}}` 를 깊이 추적으로 찾는다.
  let depth = 0;
  for (let i = 0; i < t.length; i++) {
    if (t[i] === '{' && t[i + 1] === '{') { depth++; i++; continue; }
    if (t[i] === '}' && t[i + 1] === '}') {
      depth--;
      i++;
      if (depth === 0) {
        // 바깥 짝이 문자열 끝에서 닫히면 단일 래핑(그 전이면 다중 보간 → null).
        return i === t.length - 1 ? t.slice(2, -2).trim() : null;
      }
    }
  }
  return null;
}

/**
 * 값이 **단일 `{{...}}` 가 아닌 다중 세그먼트**(평문 + 여러 보간 공존)이면서, 그 보간 세그먼트
 * 중 하나 이상이 **분해 가능한 표현식**인지.
 *
 * 예: `{{route.id ? '$t:edit' : '$t:new'}} - {{form_meta?.data?.board?.name || ''}}` — 첫 보간이
 * 조건 표현식이고 평문 `-`·다른 보간과 공존. 종전엔 단일 래핑이 아니라 분해 대상에서 빠져
 * raw 노출됐다. SegmentedValueEditor 가 세그먼트별로 표현식만 트리로 그린다.
 *
 * 단일 `{{식}}`(설명 케이스)은 false — 그건 ConditionalValueEditor 가 직접 그린다(SegmentedValueEditor
 * 불필요). 평문/단일키/D/E 도 false(기존 위젯).
 *
 * @param value node.text 값
 * @returns 다중 세그먼트 + 분해 가능 표현식 세그먼트 보유 여부
 */
export function hasDecomposableExpressionSegment(value: unknown): boolean {
  if (typeof value !== 'string' || value.trim() === '') return false;
  // 단일 래핑이면 SegmentedValueEditor 대상 아님(ConditionalValueEditor 직접).
  if (singleWrapInner(value) !== null) return false;
  // 보간 토큰을 모아 그 중 분해 가능한 표현식이 있는지.
  const tokens = value.match(/\{\{(?:[^}]|\}(?!\}))*\}\}/g);
  if (!tokens || tokens.length === 0) return false;
  return tokens.some((tok) => parseExpressionValue(tok).decomposed);
}

/* ────────────────────────────────────────────────────────────────────────────
 * 접힌 미리보기 — "한 값만 해석" 토큰 생성
 * ──────────────────────────────────────────────────────────────────────────── */

/** 접힌 미리보기 토큰 — 평문(text) 또는 데이터 칩(chip). 읽기전용 한 줄 미리보기 렌더용. */
export interface PreviewToken {
  kind: 'text' | 'chip' | 'ellipsis';
  /** text=해석된 평문 / chip=친화 데이터 라벨 / ellipsis=조건 분기 생략 표시(⋯) */
  text: string;
}

/** ValueNode 한 개를 미리보기 토큰들로 — 조건은 첫(참) 분기만 + ⋯, 폴백은 기본값, 리프는 해석/칩. */
function previewNode(
  node: ValueNode,
  resolveKey: (key: string) => string,
  chipLabelOf: (binding: string) => string,
  out: PreviewToken[],
): void {
  switch (node.kind) {
    case 'leaf':
      pushLeafPreview(node.text, resolveKey, chipLabelOf, out);
      return;
    case 'conditional':
      // "한 값만 해석" — 참 분기 대표값 + ⋯(조건 분기 있음 표시).
      previewNode(node.then, resolveKey, chipLabelOf, out);
      out.push({ kind: 'ellipsis', text: '⋯' });
      return;
    case 'fallback':
      // 기본값(primary)만 미리보기(데이터 없으면 fallback 이지만 대표는 기본값).
      previewNode(node.primary, resolveKey, chipLabelOf, out);
      return;
    case 'concat':
      node.parts.forEach((p) => previewNode(p, resolveKey, chipLabelOf, out));
      return;
    case 'raw':
    default:
      out.push({ kind: 'text', text: node.source });
      return;
  }
}

/** 리프 텍스트(평문/`$t:키`/`$t:키|pN={{}}`/`{{바인딩}}`)를 미리보기 토큰으로. */
function pushLeafPreview(
  text: string,
  resolveKey: (key: string) => string,
  chipLabelOf: (binding: string) => string,
  out: PreviewToken[],
): void {
  const t = text.trim();
  // 단일 `{{바인딩}}` → 데이터 칩.
  const wrapped = /^\{\{([\s\S]*)\}\}$/.exec(t);
  if (wrapped && !t.slice(2, -2).includes('}}')) {
    out.push({ kind: 'chip', text: chipLabelOf(t) });
    return;
  }
  // `$t:키`(+`|pN={{}}` 칩) → 키 해석 평문 + (칩은 라벨). 단순화: 키 해석값만(칩은 키 값 안에 포함).
  if (t.startsWith('$t:')) {
    const key = t.slice(3).split('|')[0];
    out.push({ kind: 'text', text: resolveKey(key) || key });
    return;
  }
  // 평문(+ 인라인 `{{칩}}`) → 평문 조각 + 칩.
  for (const seg of text.split(/(\{\{(?:[^}]|\}(?!\}))*\}\})/g)) {
    if (seg === '') continue;
    if (/^\{\{[\s\S]*\}\}$/.test(seg)) out.push({ kind: 'chip', text: chipLabelOf(seg) });
    else out.push({ kind: 'text', text: seg });
  }
}

/**
 * 값(node.text)을 접힌 미리보기 토큰 배열로.
 *
 * "한 값만 해석": 조건 분기는 참 분기 대표값 + ⋯, 폴백은 기본값, `$t:` 키는 해석, 데이터
 * 바인딩은 친화 칩. 다중 세그먼트(`{{식}} 평문 {{바인딩}}`)는 세그먼트별로 이어 붙인다.
 *
 * @param value node.text 값
 * @param resolveKey `$t:` 키(접두 없이) → 현재 로케일 평문 해석기
 * @param chipLabelOf `{{바인딩}}` → 친화 데이터 라벨(bindingChipLabel)
 * @returns 미리보기 토큰 배열
 */
export function previewSegments(
  value: string,
  resolveKey: (key: string) => string,
  chipLabelOf: (binding: string) => string,
): PreviewToken[] {
  const out: PreviewToken[] = [];
  if (typeof value !== 'string' || value === '') return out;
  // 다중 세그먼트면 세그먼트별, 단일이면 통째 파싱.
  const single = singleWrapInner(value);
  if (single === null && /\{\{/.test(value)) {
    // 다중 세그먼트(평문 + 여러 보간) — 토큰별 분해.
    for (const seg of value.split(/(\{\{(?:[^}]|\}(?!\}))*\}\})/g)) {
      if (seg === undefined || seg === '') continue;
      if (/^\{\{[\s\S]*\}\}$/.test(seg)) {
        const parsed = parseExpressionValue(seg);
        previewNode(parsed.node, resolveKey, chipLabelOf, out);
      } else {
        pushLeafPreview(seg, resolveKey, chipLabelOf, out);
      }
    }
    return out;
  }
  // 단일 값(평문/키/단일식).
  const parsed = parseExpressionValue(value);
  previewNode(parsed.node, resolveKey, chipLabelOf, out);
  return out;
}

/**
 * 값(node.text) 형태 분류 — 표. UI 가 어느 위젯을 쓸지 1차 판정한다.
 *
 *  - 'simple'(A~E): 1-depth — 기존 위젯(I18nTextField)이 그대로 처리.
 *  - 'expression'(F/G 분해 성공): ConditionalValueEditor 트리 UI.
 *  - 'raw'(분해 실패 식): raw readonly 폴백.
 *
 * @param value node.text 값
 * @returns 형태 카테고리
 */
export function classifyValueShape(value: unknown): 'empty' | 'simple' | 'expression' | 'raw' {
  if (typeof value !== 'string' || value.trim() === '') return 'empty';
  const s = value.trim();
  // 전체가 단일 `{{...}}` 한 쌍인지 — 그 안이 분해 대상 식(F/G)인지 판정.
  if (singleWrapInner(s) === null) return 'simple'; // A/B/C/E — 보간 없거나 평문+다중칩(기존 위젯)
  // D(단일 경로 바인딩)는 simple(기존 readonly/칩). F/G(식)만 expression 후보.
  const parsed = parseExpressionValue(value);
  if (parsed.node.kind === 'leaf') return 'simple'; // {{단일경로}} → 리프 1개 = D
  return parsed.decomposed ? 'expression' : 'raw';
}

/**
 * 값(node.text)을 ValueNode 트리로 분해한다.
 *
 * 처리:
 *  1. 전체가 `{{...}}` 한 쌍이면 그 안쪽 식을 expression 파서로 분해.
 *  2. 그렇지 않으면(A~E) 단일 리프(text 그대로) — 기존 위젯이 처리.
 *  3. 분해 후 **직렬화→원문 비교**(의미 동일 자기검증). 라운드트립 깨지면 raw 폴백(손상 0).
 *
 * @param value node.text 값
 * @returns 분해 결과(트리 + 성공 여부 + 원문)
 */
export function parseExpressionValue(value: unknown): ParsedValue {
  const original = typeof value === 'string' ? value : '';
  const s = original.trim();
  if (s === '') return { node: { kind: 'leaf', text: original }, decomposed: false, original };

  const inner = singleWrapInner(s);
  if (inner === null) {
    // A~E — 보간 없거나 평문+다중칩. 단일 리프(기존 위젯 처리). 분해 대상 아님.
    return { node: { kind: 'leaf', text: original }, decomposed: false, original };
  }

  let tree: ValueNode | null;
  try {
    tree = parseExpr(inner);
  } catch {
    tree = null;
  }
  if (tree === null) {
    return { node: { kind: 'raw', source: inner }, decomposed: false, original };
  }

  // 단일 경로 바인딩(D)은 leaf 1개로 환원 → 분해할 게 없으니 decomposed:false(기존 위젯).
  if (tree.kind === 'leaf' || tree.kind === 'raw') {
    return { node: tree, decomposed: false, original };
  }

  // D 변형 — `{{path ?? '리터럴'}}`(단일 바인딩 + 폴백 리터럴)은 parseBindingExpression 이 이미
  // 단일 바인딩으로 인지하는 형태(readonly/칩). 분해 트리로 띄우지 말고 단일 리프(원문 보존)로
  // 환원해 기존 D 위젯 경로를 유지한다(decomposed:false). primary=단일경로 리프 + fallback=리터럴.
  if (
    tree.kind === 'fallback' &&
    tree.op === '??' &&
    isSingleBindingLeaf(tree.primary) &&
    isLiteralLeaf(tree.fallback)
  ) {
    return { node: { kind: 'leaf', text: original }, decomposed: false, original };
  }

  // round-trip 자기검증 — 직렬화한 식이 원문과 의미 동일(공백 정규화 후)인지. 깨지면 raw 폴백.
  const reserialized = serializeValueNode(tree, true);
  if (!exprEquivalent(inner, reserialized)) {
    return { node: { kind: 'raw', source: inner }, decomposed: false, original };
  }

  return { node: tree, decomposed: true, original };
}

/**
 * ValueNode 트리를 식 문자열로 직렬화한다(round-trip 복원).
 *
 * @param node 트리
 * @param asExpr true 면 식 본문(보간 래핑 없이), false 면 node.text 값 형태(`{{...}}` 래핑 등)
 * @returns 식 문자열
 */
export function serializeValueNode(node: ValueNode, asExpr = false): string {
  const body = serializeNodeBody(node);
  if (asExpr) return body;
  // 트리 전체를 node.text 값으로 — 리프 단독이면 그 텍스트, 식이면 `{{...}}` 래핑.
  if (node.kind === 'leaf') return node.text;
  return `{{${body}}}`;
}

/* ────────────────────────────────────────────────────────────────────────────
 * 모드 전환 — 일반 이름 ↔ 표현식
 * ──────────────────────────────────────────────────────────────────────────── */

/**
 * 일반 이름(평문/단일 다국어키/`$t:키|칩`)을 **조건 분기 표현식**으로 승격한다 ("조건 분기로
 * 시작" 2026-06-13). 현재 값을 참(`then`) 분기에, 거짓(`else`) 분기는 빈 칸으로 둔 조건 노드를
 * 만들어 node.text 값(`{{...}}`)으로 직렬화한다. 사용자는 이후 분해 빌더에서 조건/거짓 분기를
 * 채운다.
 *
 *  - 빈 값 → then 도 빈 리프(`{{route.id ? '' : ''}}`).
 *  - `$t:키`/평문/`$t:키|pN={{}}`(칩) → leafToExpr 로 식 리터럴화(칩 보존).
 *  - 기본 조건은 `route.id` 존재여부(truthy) — 가장 흔한 편집/신규 화면 분기. 사용자가 빌더에서 바꾼다.
 *
 * 결과는 항상 `parseExpressionValue(result).decomposed === true`(조건 노드 + 단순 truthy 조건)라
 * 곧바로 분해 빌더가 열린다.
 *
 * @param currentValue 현재 일반 값(node.text — 평문/`$t:키`/칩)
 * @returns 조건 분기 표현식 node.text(`{{route.id ? '값' : ''}}`)
 */
export function seedExpressionFromPlain(currentValue: unknown): string {
  const text = typeof currentValue === 'string' ? currentValue : '';
  const thenNode: ValueNode = { kind: 'leaf', text };
  const elseNode: ValueNode = { kind: 'leaf', text: '' };
  const node: ConditionalNode = {
    kind: 'conditional',
    // 기준 값(조건 left)은 **빈 채로 시작** — 특정 경로(route.id 등)를 하드코딩하지 않는다.
    // 사용자가 "기준 값" 칸에 직접 원하는 조건을 입력한다(EMPTY_CONDITION_TOKEN 으로
    // 직렬화→복원).
    condition: { kind: 'simple', left: '', op: 'truthy', right: '' },
    then: thenNode,
    else: elseNode,
  };
  return serializeValueNode(node, false);
}

/**
 * 일반 값을 **폴백(`a ?? b`) 양식**으로 승격한다 ("유저는 표현식 편집기에서 가능한 모든 조합과
 * 양식을 정의할 수 있어야" 2026-06-13). 현재 값을 기본(`primary`) 분기에, 비었을 때 대신(`fallback`)
 * 분기는 빈 칸으로 둔 폴백 노드를 만들어 node.text(`{{... ?? ''}}`)로 직렬화한다.
 *
 *  - 빈 값 → primary/fallback 모두 빈 리프(`{{'' ?? ''}}` — 사용자가 양쪽을 채운다).
 *  - `$t:키`/평문/칩 → 그 값을 primary 리프로(leafToExpr 로 식 리터럴화, 칩 보존).
 *  - 데이터 바인딩(`{{x.y}}`) → primary 가 바인딩 리프(데이터 없을 때 fallback 분기 편집).
 *
 * @param currentValue 현재 일반 값(node.text — 평문/`$t:키`/칩/바인딩)
 * @returns 폴백 양식 node.text(`{{<값> ?? ''}}`)
 */
export function seedFallbackFromValue(currentValue: unknown): string {
  const text = typeof currentValue === 'string' ? currentValue : '';
  const node: FallbackNode = {
    kind: 'fallback',
    op: '??',
    primary: { kind: 'leaf', text },
    fallback: { kind: 'leaf', text: '' },
  };
  return serializeValueNode(node, false);
}

/**
 * 일반 값을 **이어붙이기(`a + b`) 양식**으로 승격한다. 현재 값을 첫
 * 조각에, 빈 둘째 조각을 더한 concat 노드를 만든다. 사용자가 빌더에서 조각을 더 추가/편집한다.
 *
 * @param currentValue 현재 일반 값(node.text)
 * @returns 이어붙이기 양식 node.text(`{{<값> + ''}}`)
 */
export function seedConcatFromValue(currentValue: unknown): string {
  const text = typeof currentValue === 'string' ? currentValue : '';
  const node: ConcatNode = {
    kind: 'concat',
    parts: [
      { kind: 'leaf', text },
      { kind: 'leaf', text: '' },
    ],
  };
  return serializeValueNode(node, false);
}

/**
 * 표현식 트리에서 **첫 결과 분기의 리프 텍스트**를 뽑는다 ("한 값만 해석"의 원본 보존판 —
 * `previewSegments` 가 해석된 평문을 만드는 것과 달리, 되돌리기는 원본 리프 텍스트(`$t:키`/평문/
 * 칩)를 보존해야 일반 이름 위젯이 그대로 이어받는다). "첫 결과를 뽑아 확인 후 적용" 2026-06-13.
 *
 *  - leaf → 그 text.
 *  - conditional → then(참 분기) 재귀.
 *  - fallback → primary(기본값) 재귀.
 *  - concat → 각 조각 추출값을 이어붙임(리프 text 연결 — 단일 값 환원).
 *  - raw → 원문(보간 래핑해 되돌림 — 일반 위젯에선 D/바인딩으로 표시).
 *
 * @param node 표현식 트리
 * @returns 첫 결과 분기의 일반 값 텍스트(node.text 형태)
 */
export function extractFirstLeafText(node: ValueNode): string {
  switch (node.kind) {
    case 'leaf':
      return node.text;
    case 'conditional':
      return extractFirstLeafText(node.then);
    case 'fallback':
      return extractFirstLeafText(node.primary);
    case 'concat':
      return node.parts.map(extractFirstLeafText).join('');
    case 'raw':
    default:
      return node.kind === 'raw' ? `{{${node.source}}}` : '';
  }
}

/**
 * 되돌리기로 남길 첫 결과를 그대로 보존한다 — 첫 결과가 **순수 데이터 바인딩**(`{{board.name}}`)이면
 * 그 바인딩을 그대로 남겨 데이터 칩으로 복구되게 한다("첫 항목이 데이터라면 데이터로
 * 복구되어야 한다" 2026-06-14). 종전엔 단일 바인딩을 빈 값으로 떨궈 데이터 연결이 비가역 소실됐고,
 * 되돌리기 **미리보기는 그 데이터 칩(🔗 ...)을 보여 주는데 적용은 빈 값**이라 미리보기와도 불일치했다.
 *
 * 값 칸(og.title/description 등)·텍스트 칸 공통 — 첫 결과가 데이터면 칩, 평문/`$t:키`면 그 값.
 * 단일 바인딩(`{{path}}`)은 그대로 반환되어 DataChipValueInput/I18nTextField 가 칩으로 렌더한다.
 */
function plainOrEmpty(text: string): string {
  // 첫 결과는 평문/`$t:키`/칩(단일 바인딩) 그대로 보존(빈 값으로 떨구지 않음).
  return text;
}

/**
 * 표현식 값(node.text)을 "일반 이름"으로 되돌릴 때 남길 값을 계산한다 ("첫 결과를 뽑아 확인 후
 * 적용" + "첫 조각의 대표값만 남김" 2026-06-13). **첫 조각의 대표값 하나만** 반환한다 — 결과는
 * `{{}}` 데이터 바인딩이 섞이지 않은 순수 일반 이름(평문/`$t:키`/칩)이어야 한다.
 *
 *  - 단일 식 → 첫 분기 리프(extractFirstLeafText). 그 리프가 순수 데이터 바인딩이면 빈 값.
 *  - 다중 세그먼트(`{{식}} 평문 {{바인딩}}`) → **첫 세그먼트만** 환원하고 뒤(평문 연결·데이터 조각)는
 *    전부 버린다. 첫 세그먼트가 평문이면 그 평문, 표현식이면 첫 분기 리프(바인딩이면 빈 값).
 *
 * 종전엔 세그먼트를 이어붙여 바인딩 조각이 남아 결과가 여전히 `{{}}` 포함 = 표현식으로 재취급되던
 * 결함. "표현식을 다 제거하고 일반 이름으로" 의도와 일치하도록 첫 대표값만 남긴다.
 *
 * @param value 표현식 node.text 값
 * @returns 일반 이름으로 환원한 값(node.text — `$t:키`/평문/칩, `{{}}` 없음)
 */
export function reduceExpressionToPlain(value: unknown): string {
  if (typeof value !== 'string' || value.trim() === '') return '';
  const single = singleWrapInner(value);
  if (single === null && /\{\{/.test(value)) {
    // 다중 세그먼트 — **첫 세그먼트의 대표값만** (뒤 평문·데이터 조각 제거).
    for (const seg of value.split(/(\{\{(?:[^}]|\}(?!\}))*\}\})/g)) {
      if (seg === undefined || seg === '') continue;
      if (/^\{\{[\s\S]*\}\}$/.test(seg)) {
        // 첫 조각이 표현식 — 첫 분기 리프(바인딩이면 빈 값).
        return plainOrEmpty(extractFirstLeafText(parseExpressionValue(seg).node));
      }
      // 첫 조각이 평문(인라인 칩 포함 가능) — 그 평문만.
      return seg;
    }
    return '';
  }
  // 단일 식/평문 — 첫 분기 리프(바인딩이면 빈 값).
  return plainOrEmpty(extractFirstLeafText(parseExpressionValue(value).node));
}

/* ────────────────────────────────────────────────────────────────────────────
 * 직렬화 — ValueNode → 식 본문
 * ──────────────────────────────────────────────────────────────────────────── */

/** 리프가 단일 경로 바인딩(`{{path}}`)인지 — D 변형 판정용. */
function isSingleBindingLeaf(node: ValueNode): boolean {
  if (node.kind !== 'leaf') return false;
  const m = /^\{\{([\s\S]*)\}\}$/.exec(node.text.trim());
  if (!m) return false;
  return isSimplePath(m[1].trim());
}

/**
 * 리프가 순수 리터럴(빈 문자열/평문/숫자/불리언/null/빈배열·객체)인지 — D 변형 폴백 판정용.
 *
 * `$t:키`(다국어 키)는 리터럴이 아니다 — `{{path ?? '$t:key'}}` 는 "데이터 없으면 다국어 키
 * 표시"라는 **편집 가치 있는 F-케이스**(폴백 분기를 번역 편집)이므로 D 로 접지 않고 분해한다.
 */
function isLiteralLeaf(node: ValueNode): boolean {
  if (node.kind !== 'leaf') return false;
  const t = node.text.trim();
  if (/\{\{/.test(t)) return false; // 바인딩 포함이면 리터럴 아님.
  if (t.startsWith('$t:')) return false; // 다국어 키는 리터럴 아님(분해 가치 — 폴백 분기 번역).
  // 빈 문자열(`''`→text '')·평문·숫자·불리언·null·빈 컬렉션 — 순수 리터럴.
  return true;
}

/** 평문 조각을 작은따옴표 문자열 리터럴로(내부 백슬래시/작은따옴표 이스케이프). */
function quoteLiteral(text: string): string {
  return `'${text.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

/**
 * 평문+데이터칩 혼합 값(`$t:` 키 아님, 예 `/api/.../{{route.id}}` · `회원 {{user.name}} 님`)을
 * **이어붙이기 식**(`'평문' + route.id`)으로 분해한다.
 *
 * 종전 `leafToExpr` 는 이런 값을 통째로 작은따옴표 문자열(`'/api/.../{{route.id}}'`)로 감쌌다.
 * 그 결과 칩의 `{{route.id}}` 가 문자열 리터럴 **안에** 박혀, 조건/폴백 양식으로 감쌀 때
 * `{{false ? '/api/.../{{route.id}}' : ''}}` 처럼 **중첩 `{{}}`** 가 생겨 파서가 raw(편집 잠금)로
 * 떨궜다. 칩은 문자열이 아니라 표현식 항으로 살려야 한다("칩을 표현식
 * 항으로 보존"). 평문 조각은 작은따옴표 리터럴, `{{...}}` 칩은 보간 안쪽 식으로 떼어 `+` 로 잇는다.
 *
 * 분해 결과는 `parseExpr` 가 다시 `concat[리프(평문), 리프({{binding}})]` 트리로 복원하므로
 * round-trip 자기검증(`exprEquivalent`)을 통과하고 칩이 그대로 보존된다.
 *
 * @param text 평문+칩 혼합 값(전체가 단일 `{{}}` 도, `$t:` 키도 아님)
 * @returns 이어붙이기 식 본문(`'평문' + path` …)
 */
function mixedLeafToConcatExpr(text: string): string {
  const parts: string[] = [];
  let last = 0;
  let i = 0;
  const n = text.length;
  while (i < n) {
    if (text[i] === '{' && text[i + 1] === '{') {
      // 평문 선행 조각.
      if (i > last) parts.push(quoteLiteral(text.slice(last, i)));
      // 짝이 맞는 닫는 `}}` 까지 흡수(단일 `}` 허용 — 런타임 보간 규칙과 동일).
      let j = i + 2;
      while (j < n && !(text[j] === '}' && text[j + 1] === '}')) j++;
      if (j >= n) break; // 미닫힘 — 폴백(아래에서 통째 문자열).
      const inner = text.slice(i + 2, j).trim();
      parts.push(inner);
      i = j + 2;
      last = i;
      continue;
    }
    i++;
  }
  if (last < n) parts.push(quoteLiteral(text.slice(last)));
  // 미닫힘 보간 등으로 칩을 못 뗐으면(parts 가 비었으면) 통째 문자열 폴백.
  if (parts.length === 0) return quoteLiteral(text);
  return parts.join(' + ');
}

/**
 * 리프 텍스트를 식 리터럴로 환원.
 *
 *  - 전체가 단일 `{{...}}` → 보간 안쪽 식 그대로(따옴표 없이).
 *  - `$t:` 다국어 키(`$t:키` / `$t:키|pN={{}}` 칩) → 작은따옴표 문자열 통째(키 시스템의 `{{}}` 는
 *    문자열 안 보존 — 토크나이저 string 분기가 흡수, round-trip 정상).
 *  - **평문 + 데이터칩 혼합**(`$t:` 키 아님, `{{}}` 포함) → 이어붙이기(`'평문' + path`)로 분해해
 *  칩을 표현식 항으로 보존(중첩 `{{}}` 손상 차단).
 *  - 순수 평문 → 작은따옴표 문자열.
 */
function leafToExpr(text: string): string {
  const t = text.trim();
  // 전체가 단일 `{{...}}` → 보간 안쪽 식 그대로(따옴표 없이).
  const m = /^\{\{([\s\S]*)\}\}$/.exec(t);
  if (m) return m[1].trim();
  // 평문 + 데이터칩 혼합(`$t:` 키 아님 + `{{}}` 포함) → 이어붙이기로 칩 보존(중첩 `{{}}` 손상 차단).
  // `$t:` 키(`$t:x` / `$t:x|pN={{}}`)는 통째 문자열로(키 시스템 SSoT — 위 string 분기가 흡수 복원).
  if (!t.startsWith('$t:') && /\{\{/.test(t)) {
    return mixedLeafToConcatExpr(text);
  }
  // 평문/`$t:키`/`$t:키|pN={{}}`(칩) — 작은따옴표 문자열 리터럴로.
  return quoteLiteral(text);
}

/**
 * 빈 조건(기준 값 left 미입력) 의 식 표현 — **데이터 비종속 중립값** `false`(아직 조건 미정 → 기본
 * else 분기). 새 조건 분기를 추가하거나 일반 이름을 표현식으로 승격할 때, 기준 값을 특정 경로
 * (`route.id` 등)로 하드코딩하지 않고 빈 채로 시작하기 위함(route.id 하드코딩
 * 금지). parseConditionFromExpr 가 이 토큰을 다시 빈 SimpleCondition 으로 복원해, ConditionBuilder 의
 * "기준 값" 입력칸이 빈 채로 노출되고 사용자가 직접 채운다.
 */
const EMPTY_CONDITION_TOKEN = 'false';

/** 조건을 식 문자열로 직렬화 */
function serializeCondition(cond: Condition): string {
  if (cond.kind === 'raw') return cond.source;
  // 기준 값(left) 미입력 — 데이터 비종속 중립 토큰(round-trip 으로 빈 조건 복원).
  if (cond.left.trim() === '') return EMPTY_CONDITION_TOKEN;
  if (cond.op === 'truthy') return cond.left;
  if (cond.op === 'falsy') return `!${needsParensForNot(cond.left) ? `(${cond.left})` : cond.left}`;
  // 비교 우변이 비어 있으면(연산자만 막 바꾼 편집 중 상태) 안전한 빈 문자열 리터럴 `''` 을 우변에
  // 둔다. `route.id > `(우변 없음)는 유효한 JS 식이 아니라 round-trip 재파싱에서 RawCondition(코드에서
  // 수정 잠금)으로 떨어졌다. `route.id > ''` 는
  // 유효식이고 parseConditionFromExpr 가 빈 리터럴 우변을 다시 right:'' 로 복원해 SimpleCondition 을
  // 유지한다(우변 입력칸 노출, 사용자가 채울 수 있음).
  const right = cond.right.trim() === '' ? "''" : cond.right;
  return `${cond.left} ${cond.op} ${right}`;
}

/** `!` 적용 시 괄호 필요한지(공백/연산자 포함 식) */
function needsParensForNot(left: string): boolean {
  return /[\s()?:|&+]/.test(left.trim()) && !/^[A-Za-z_$][\w$.?[\]]*$/.test(left.trim());
}

/** 노드 본문(보간 래핑 없는 식) 직렬화 */
function serializeNodeBody(node: ValueNode): string {
  switch (node.kind) {
    case 'leaf':
      return leafToExpr(node.text);
    case 'raw':
      return node.source;
    case 'concat':
      return node.parts.map((p) => serializeChild(p, 'concat')).join(' + ');
    case 'fallback':
      return `${serializeChild(node.primary, 'fallback')} ${node.op} ${serializeChild(node.fallback, 'fallback')}`;
    case 'conditional':
      return `${serializeCondition(node.condition)} ? ${serializeChild(node.then, 'conditional')} : ${serializeChild(node.else, 'conditional')}`;
    default:
      return '';
  }
}

/**
 * 자식 노드 직렬화 — 부모 컨텍스트에 따라 괄호 보호.
 *
 * JS 우선순위: 삼항(`?:`) < 논리/널병합(`||`/`??`) < 산술(`+`). 부모보다 낮은 우선순위
 * 자식은 괄호로 감싼다(의미 보존).
 */
function serializeChild(node: ValueNode, parent: 'conditional' | 'fallback' | 'concat'): string {
  const body = serializeNodeBody(node);
  if (node.kind === 'leaf' || node.kind === 'raw') return body;
  // 자식이 conditional 이면 거의 항상 괄호 필요(삼항이 최저 우선순위).
  if (node.kind === 'conditional') {
    // concat/fallback 자식의 conditional 은 괄호. conditional 의 then/else 분기는 괄호 불필요
    // (`a ? b : c ? d : e` 우결합), 단 명료성 위해 중첩 conditional 만 그대로.
    if (parent === 'conditional') return body; // then/else 위치 — 우결합 자연.
    return `(${body})`;
  }
  if (node.kind === 'fallback') {
    // concat 부모의 fallback 자식은 괄호(`+` 가 `??`보다 높음).
    if (parent === 'concat') return `(${body})`;
    return body;
  }
  if (node.kind === 'concat') {
    // fallback/conditional 부모의 concat 자식은 괄호 불필요(`+` 가 더 높음).
    return body;
  }
  return body;
}

/* ────────────────────────────────────────────────────────────────────────────
 * 토크나이저
 * ──────────────────────────────────────────────────────────────────────────── */

type TokKind =
  | 'string' // 따옴표 문자열 리터럴
  | 'binding' // {{...}}
  | 'ident' // 식별자/경로 토큰(점/괄호 접근 포함은 path 빌드 단계에서)
  | 'number'
  | 'op' // 연산자(? : ?? || + === 등)
  | 'lparen'
  | 'rparen'
  | 'lbracket'
  | 'rbracket'
  | 'dot'
  | 'tkey' // 따옴표 없는 $t:key 토큰(분기 안 베어 키)
  | 'other'; // 기타(파서가 못 다루는 토큰 → raw 유발)

interface Tok {
  kind: TokKind;
  value: string;
  start: number;
  end: number;
}

const MULTI_OPS = ['===', '!==', '??', '||', '&&', '==', '!=', '>=', '<='];

/**
 * 식을 토큰 배열로 분해한다. 문자열 리터럴/`{{보간}}`/`$t:key`(베어)는 통째 한 토큰.
 * 함수 호출(`f(`)·산술(`* / -`)·임의 멤버 연산은 'other' 토큰을 만들어 파서가 raw 로 떨군다.
 *
 * @throws 토큰화 불가(미닫힌 문자열/보간) 시 throw → 호출자 raw 폴백.
 */
function tokenize(src: string): Tok[] {
  const toks: Tok[] = [];
  let i = 0;
  const n = src.length;
  while (i < n) {
    const ch = src[i];
    if (/\s/.test(ch)) { i++; continue; }

    // 문자열 리터럴 — ' " ` 모두. 백틱 템플릿 내 ${...} 는 미지원(other 로 다룸).
    if (ch === "'" || ch === '"' || ch === '`') {
      const quote = ch;
      let j = i + 1;
      let buf = quote;
      while (j < n) {
        if (src[j] === '\\') { buf += src[j] + (src[j + 1] ?? ''); j += 2; continue; }
        // 문자열 안의 `{{...}}` 보간(G 형태 칩 — `'$t:key|p0={{x ?? ''}}'`)은 통째 보존한다. 보간
        // 내부의 따옴표(`?? ''` 폴백 등)가 바깥 문자열의 닫는 따옴표로 오인돼 경계가 깨지던 결함
        // 회피. 닫는 `}}` 까지 한 번에 흡수(따옴표 무시).
        if (src[j] === '{' && src[j + 1] === '{') {
          let k = j + 2;
          while (k < n && !(src[k] === '}' && src[k + 1] === '}')) k++;
          if (k >= n) throw new Error('unterminated-binding-in-string');
          buf += src.slice(j, k + 2);
          j = k + 2;
          continue;
        }
        if (src[j] === quote) { buf += quote; j++; break; }
        // 백틱 템플릿 보간(`${`)이 있으면 단순 문자열 아님 → other.
        if (quote === '`' && src[j] === '$' && src[j + 1] === '{') {
          throw new Error('template-literal-interpolation-unsupported');
        }
        buf += src[j]; j++;
      }
      if (j > n || buf[buf.length - 1] !== quote) throw new Error('unterminated-string');
      toks.push({ kind: 'string', value: buf, start: i, end: j });
      i = j;
      continue;
    }

    // {{ 보간 }} — 균형 닫힘까지 통째.
    if (ch === '{' && src[i + 1] === '{') {
      let j = i + 2;
      // 런타임 보간 매칭과 동일: 닫는 `}}` 전까지(단일 `}` 허용).
      while (j < n) {
        if (src[j] === '}' && src[j + 1] === '}') { j += 2; break; }
        j++;
      }
      if (src[j - 1] !== '}' || src[j - 2] !== '}') throw new Error('unterminated-binding');
      toks.push({ kind: 'binding', value: src.slice(i, j), start: i, end: j });
      i = j;
      continue;
    }

    // 베어 $t:key — 따옴표 없는 다국어 키(분기 안). `$t:` + 키문자. 키 본문에서 `:` 는 제외한다
    // (삼항 분리자 `:` 흡수 방지 — `cond ? $t:a : $t:b` 에서 `$t:a` 가 ` : $t:b` 까지 먹지 않도록).
    // 내부 콜론 든 키(`$t:shop.shipping_fee:`)는 실데이터에서 모두 따옴표 문자열이라 string 분기가 처리.
    if (ch === '$' && src.startsWith('$t:', i)) {
      let j = i + 3;
      while (j < n && /[a-zA-Z0-9._-]/.test(src[j])) j++;
      // 베어 키 뒤에 named param(`|count=...`)이 붙는 경우(예: `$t:x|count=' + n`)는 키만 베어 토큰,
      // 파이프 이하는 이어붙이기로 파서가 처리(파이프는 other → 보수적 raw). 1차: 키만.
      toks.push({ kind: 'tkey', value: src.slice(i, j), start: i, end: j });
      i = j;
      continue;
    }

    // 다중문자 연산자
    let matchedOp = '';
    for (const op of MULTI_OPS) {
      if (src.startsWith(op, i)) { matchedOp = op; break; }
    }
    if (matchedOp) {
      toks.push({ kind: 'op', value: matchedOp, start: i, end: i + matchedOp.length });
      i += matchedOp.length;
      continue;
    }

    // 단일문자 토큰
    if (ch === '?') { toks.push({ kind: 'op', value: '?', start: i, end: i + 1 }); i++; continue; }
    if (ch === ':') { toks.push({ kind: 'op', value: ':', start: i, end: i + 1 }); i++; continue; }
    if (ch === '+') { toks.push({ kind: 'op', value: '+', start: i, end: i + 1 }); i++; continue; }
    if (ch === '>' || ch === '<') { toks.push({ kind: 'op', value: ch, start: i, end: i + 1 }); i++; continue; }
    if (ch === '!') { toks.push({ kind: 'op', value: '!', start: i, end: i + 1 }); i++; continue; }
    if (ch === '(') { toks.push({ kind: 'lparen', value: '(', start: i, end: i + 1 }); i++; continue; }
    if (ch === ')') { toks.push({ kind: 'rparen', value: ')', start: i, end: i + 1 }); i++; continue; }
    if (ch === '[') { toks.push({ kind: 'lbracket', value: '[', start: i, end: i + 1 }); i++; continue; }
    if (ch === ']') { toks.push({ kind: 'rbracket', value: ']', start: i, end: i + 1 }); i++; continue; }
    if (ch === '.') { toks.push({ kind: 'dot', value: '.', start: i, end: i + 1 }); i++; continue; }

    // 숫자
    if (/[0-9]/.test(ch)) {
      let j = i;
      while (j < n && /[0-9.]/.test(src[j])) j++;
      toks.push({ kind: 'number', value: src.slice(i, j), start: i, end: j });
      i = j;
      continue;
    }

    // 식별자(경로 머리) — 옵셔널 체이닝(`?.`)은 path 단계에서 흡수.
    if (/[A-Za-z_$]/.test(ch)) {
      let j = i;
      while (j < n && /[A-Za-z0-9_$]/.test(src[j])) j++;
      toks.push({ kind: 'ident', value: src.slice(i, j), start: i, end: j });
      i = j;
      continue;
    }

    // 그 외(`* / - % & ~` 등) — 파서가 못 다루는 토큰. other 로 표시(파서가 raw 로).
    toks.push({ kind: 'other', value: ch, start: i, end: i + 1 });
    i++;
  }
  return toks;
}

/* ────────────────────────────────────────────────────────────────────────────
 * 재귀하강 파서 — 우선순위: 삼항 < 폴백(??/||) < 이어붙이기(+) < 단항/리프
 * ──────────────────────────────────────────────────────────────────────────── */

class Parser {
  private toks: Tok[];
  private pos = 0;
  private src: string;

  constructor(src: string) {
    this.src = src;
    this.toks = tokenize(src);
  }

  parse(): ValueNode {
    const node = this.parseTernary();
    if (this.pos < this.toks.length) throw new Error('trailing-tokens');
    return node;
  }

  private peek(): Tok | undefined { return this.toks[this.pos]; }
  private next(): Tok | undefined { return this.toks[this.pos++]; }

  /**
   * 삼항 — `cond ? then : else`(우결합).
   *
   * 조건(`cond`)은 값 문법(폴백/이어붙이기/리프)으로 안 잡히는 비교 연산자(`===`/`>` 등)와
   * 단항 부정(`!`)을 포함할 수 있다. 그래서 조건 위치는 **top-level `?` 직전까지 토큰을
   * 통째 떼어** 그 원문을 `parseConditionFromExpr` 로 해석한다(괄호/문자열 깊이 추적,
   * 중첩 삼항/`?.` 오인 방지). `?` 가 없으면 그냥 값(폴백 결과)을 돌려준다.
   */
  private parseTernary(): ValueNode {
    const startPos = this.pos;
    // 1) top-level `?` 위치 탐색(조건 span 경계). 없으면 일반 값 파싱.
    const qPos = this.findTopLevelQuestion(startPos);
    if (qPos === -1) {
      return this.parseFallback();
    }
    // 2) 조건 span(start..qPos) 원문 재구성 → 조건 해석.
    const condSrc = this.sliceSource(startPos, qPos);
    // 3) `?` 소비 → then(우결합 재귀) → `:` → else(우결합 재귀).
    this.pos = qPos + 1; // consume up to and including `?`
    const then = this.parseTernary();
    const colon = this.next();
    if (!colon || colon.kind !== 'op' || colon.value !== ':') throw new Error('expected-colon');
    const els = this.parseTernary();
    return {
      kind: 'conditional',
      condition: parseConditionFromExpr(condSrc.trim()),
      then,
      else: els,
    };
  }

  /**
   * `from` 토큰부터 같은 깊이(괄호·삼항 중첩 0)의 첫 `?`(삼항 물음표) 인덱스를 찾는다.
   * `?.`(옵셔널 체이닝)·`??`(널 병합)는 별도 토큰이라 `?` 단독 op 만 본다. 없으면 -1.
   */
  private findTopLevelQuestion(from: number): number {
    let depth = 0;
    for (let i = from; i < this.toks.length; i++) {
      const tk = this.toks[i];
      if (tk.kind === 'lparen' || tk.kind === 'lbracket') depth++;
      else if (tk.kind === 'rparen' || tk.kind === 'rbracket') {
        if (depth === 0) return -1; // 닫는 괄호가 먼저 = 이 레벨에 삼항 없음(상위 호출 span 끝).
        depth--;
      } else if (depth === 0 && tk.kind === 'op' && tk.value === '?') {
        // `?.`(옵셔널 체이닝)은 `?` op + `.` dot 으로 토큰화 — 삼항 물음표 아님(건너뜀).
        if (this.toks[i + 1]?.kind === 'dot') continue;
        return i;
      } else if (depth === 0 && tk.kind === 'op' && tk.value === ':') {
        return -1; // 콜론이 물음표보다 먼저 = 우리는 then/else span 내부 — 이 레벨 삼항 아님.
      }
    }
    return -1;
  }

  /** 토큰 [from, to) 범위의 원문 소스를 재구성한다(start/end 오프셋 기반). */
  private sliceSource(from: number, to: number): string {
    if (from >= to) return '';
    const startOff = this.toks[from].start;
    const endOff = this.toks[to - 1].end;
    return this.src.slice(startOff, endOff);
  }

  /** 폴백 — `a ?? b` / `a || b`(좌결합). 한 체인에 같은 op 만 묶고 다른 op 섞이면 우측 중첩. */
  private parseFallback(): ValueNode {
    let left = this.parseConcat();
    let t = this.peek();
    while (t && t.kind === 'op' && (t.value === '??' || t.value === '||')) {
      const op = t.value as '??' | '||';
      this.next();
      const right = this.parseConcat();
      left = { kind: 'fallback', op, primary: left, fallback: right };
      t = this.peek();
    }
    return left;
  }

  /** 이어붙이기 — `a + b + c`(좌결합, 2개 이상이면 concat 노드로 평탄화). */
  private parseConcat(): ValueNode {
    const first = this.parseUnaryOrPrimary();
    const parts: ValueNode[] = [first];
    let t = this.peek();
    while (t && t.kind === 'op' && t.value === '+') {
      this.next();
      parts.push(this.parseUnaryOrPrimary());
      t = this.peek();
    }
    if (parts.length === 1) return first;
    return { kind: 'concat', parts };
  }

  /** 단항(`!x`)은 조건 컨텍스트에서만 의미 — 여기선 primary 로 위임(조건 변환 시 처리). */
  private parseUnaryOrPrimary(): ValueNode {
    return this.parsePrimary();
  }

  /** 리프/괄호 — 분해 가능한 단말. 못 다루면 throw(raw 폴백). */
  private parsePrimary(): ValueNode {
    const t = this.peek();
    if (!t) throw new Error('unexpected-end');

    // 괄호 — `( expr )`. 안쪽을 재귀 파싱.
    if (t.kind === 'lparen') {
      this.next();
      const inner = this.parseTernary();
      const close = this.next();
      if (!close || close.kind !== 'rparen') throw new Error('expected-rparen');
      return inner;
    }

    // 문자열 리터럴 — `'$t:key'` / `'평문'`. 리프(따옴표 벗겨 text 로).
    if (t.kind === 'string') {
      this.next();
      return { kind: 'leaf', text: unquote(t.value) };
    }

    // 베어 $t:key — `$t:board.edit`(따옴표 없음). 리프.
    if (t.kind === 'tkey') {
      this.next();
      return { kind: 'leaf', text: t.value };
    }

    // {{보간}} — 리프(text 에 보간 통째). 단, 이 경로는 식 안쪽에 또 보간이 박힌 경우(드묾).
    if (t.kind === 'binding') {
      this.next();
      return { kind: 'leaf', text: t.value };
    }

    // SEO 단일-경로 추출 함수 호출 — `$localized(product.data.meta_title)`. 인자가 단순 경로 1개인
    // 알려진 추출 함수는 그 호출 전체를 단일 바인딩 리프(`{{$localized(path)}}`)로 환원한다. SEO
    // 메타값(meta_title/name 등 다국어 객체)을 현재 로케일 문자열로 추출하는 코어 SEO 표현식
    // 함수와 정합 — 편집기 데이터 칩이 인자 경로(meta_title)를 친화 라벨로 보이게 한다. 그 외 함수
    // 호출은 종전대로 parsePath 에서 call-expression throw → raw 폴백(손상 0).
    if (t.kind === 'ident' && PATH_WRAPPER_FN_NAMES.includes(t.value) && this.toks[this.pos + 1]?.kind === 'lparen') {
      const leaf = this.tryParsePathWrapperCall(t.value);
      if (leaf !== null) return leaf;
      // 인자가 단순 경로가 아니면(다인자/연산/리터럴) 분해 대상 아님 → raw 폴백.
      throw new Error('path-wrapper-non-simple-arg');
    }

    // 식별자로 시작하는 경로 — `route.id`, `_local.isSaving?.x` 등. path 누적 후 리프(binding text).
    if (t.kind === 'ident') {
      const path = this.parsePath();
      // 경로는 그 자체로 값 리프(런타임 평가) — `{{path}}` 보간으로 환원.
      return { kind: 'leaf', text: `{{${path}}}` };
    }

    // 숫자 리터럴 — 리프(평문).
    if (t.kind === 'number') {
      this.next();
      return { kind: 'leaf', text: t.value };
    }

    // 부정(`!x`) 단독은 조건에서만 — 값 위치에선 raw.
    throw new Error(`unparseable-primary:${t.kind}`);
  }

  /**
   * 경로 토큰 누적 — `a.b?.c[0].d`. 함수 호출(`(`)·연산자가 끼면 throw(raw). 옵셔널
   * 체이닝(`?.`)은 `?` op + `.` dot 으로 토큰화되므로 흡수한다.
   */
  private parsePath(): string {
    let path = '';
    const head = this.next();
    if (!head || head.kind !== 'ident') throw new Error('expected-ident');
    path += head.value;
    for (;;) {
      const t = this.peek();
      if (!t) break;
      // 옵셔널 체이닝 `?.` — `?` op 다음 `.` dot.
      if (t.kind === 'op' && t.value === '?' && this.toks[this.pos + 1]?.kind === 'dot') {
        this.next(); this.next(); // ? .
        const id = this.next();
        if (!id || id.kind !== 'ident') throw new Error('expected-ident-after-optional');
        path += '?.' + id.value;
        continue;
      }
      if (t.kind === 'dot') {
        this.next();
        const id = this.next();
        if (!id || id.kind !== 'ident') throw new Error('expected-ident-after-dot');
        path += '.' + id.value;
        continue;
      }
      if (t.kind === 'lbracket') {
        // `[숫자]` 또는 `['키']` 인덱스 접근만 허용. 그 외(변수 인덱스 등)는 raw.
        this.next();
        const idx = this.next();
        const close = this.next();
        if (!close || close.kind !== 'rbracket') throw new Error('expected-rbracket');
        if (idx && (idx.kind === 'number' || idx.kind === 'string')) {
          path += `[${idx.value}]`;
          continue;
        }
        throw new Error('non-literal-index');
      }
      // 함수 호출 `(` — path 가 함수면 분해 불가(raw).
      if (t.kind === 'lparen') throw new Error('call-expression');
      break;
    }
    return path;
  }

  /**
   * SEO 단일-경로 추출 함수 호출(`$localized( <path> )`)을 단일 바인딩 리프로 파싱한다.
   * 함수명(ident) + `(` 가 이미 확인된 상태로 호출된다. 인자가 **단순 경로 1개**이고 그 뒤
   * `)` 로 정확히 닫히면 `{{$localized(path)}}` 리프를 돌려준다. 인자가 다인자/연산/리터럴이거나
   * 닫는 괄호 뒤에 토큰이 남으면 null(호출자가 raw 폴백).
   */
  private tryParsePathWrapperCall(fnName: string): ValueNode | null {
    const save = this.pos;
    this.next(); // ident(fnName)
    const open = this.next();
    if (!open || open.kind !== 'lparen') { this.pos = save; return null; }
    // 인자 = 단순 경로 1개. parsePath 가 `.`/`?.`/`[idx]` 를 흡수하고 함수/연산 만나면 throw.
    let argPath: string;
    try {
      if (this.peek()?.kind !== 'ident') { this.pos = save; return null; }
      argPath = this.parsePath();
    } catch {
      this.pos = save;
      return null;
    }
    const close = this.next();
    if (!close || close.kind !== 'rparen') { this.pos = save; return null; }
    return { kind: 'leaf', text: `{{${fnName}(${argPath})}}` };
  }

}

/** 식 본문을 ValueNode 트리로(진입). 실패 시 throw. */
function parseExpr(src: string): ValueNode {
  return new Parser(src).parse();
}

/* ────────────────────────────────────────────────────────────────────────────
 * 조건 파서 — `left op right` 단순 비교만 구조화
 * ──────────────────────────────────────────────────────────────────────────── */

const COMPARE_OPS = ['===', '!==', '==', '!=', '>=', '<=', '>', '<'];

/**
 * 조건 식을 SimpleCondition(편집 가능) 또는 RawCondition(readonly)으로 분해한다.
 *
 *  - `route.id === 'x'` → simple(left, op, right)
 *  - `route.id`(단독) → simple truthy(존재여부)
 *  - `!route.id` → simple falsy
 *  - `a && b`, `(x).filter(...).length > 0` 등 복합 → raw(코드에서 수정)
 *
 * @param exprBody 조건 식 본문(보간 래핑 없음)
 * @returns 조건
 */
export function parseConditionFromExpr(exprBody: string, _full?: string): Condition {
  const s = exprBody.trim();
  if (s === '') return { kind: 'raw', source: s };

  // 빈 조건 중립 토큰(`false`) → 빈 SimpleCondition 복원(기준 값 미입력 편집 중 상태). 사용자가
  // "기준 값" 칸을 채우기 전까지 이 상태를 유지한다(route.id 등 하드코딩 회피).
  if (s === EMPTY_CONDITION_TOKEN) return { kind: 'simple', left: '', op: 'truthy', right: '' };

  // 부정 — `!path`(단순 경로만 falsy 로). `!(복합)` 은 raw.
  if (s.startsWith('!')) {
    const rest = s.slice(1).trim();
    const path = stripOuter(rest);
    if (isSimplePath(path)) return { kind: 'simple', left: path, op: 'falsy', right: '' };
    return { kind: 'raw', source: s };
  }

  // top-level 비교 연산자 1개로 분리(괄호/문자열 깊이 0).
  const split = splitTopLevelCompare(s);
  if (split) {
    const { left, op, right } = split;
    const lp = left.trim();
    const rp = right.trim();
    // 빈 문자열 리터럴 우변(`route.id > ''`) → 우변 미입력(편집 중) 으로 복원. 비교 연산자를 막
    // 바꿔 우변을 아직 안 채운 상태로, RawCondition(잠금)으로 떨어지지 않게 한다
    // (비교 연산자 전환 시 잠금). 좌변이 단순 경로면 SimpleCondition(right:'') 유지.
    if (isSimplePath(lp) && (rp === "''" || rp === '""')) {
      return { kind: 'simple', left: lp, op: op as SimpleCondition['op'], right: '' };
    }
    // 좌변이 단순 경로여야 편집 가능(우변은 리터럴 식 보존).
    if (isSimplePath(lp) && isLiteralOrPath(rp)) {
      return { kind: 'simple', left: lp, op: op as SimpleCondition['op'], right: rp };
    }
    return { kind: 'raw', source: s };
  }

  // 비교 없음 — 단순 경로 단독이면 존재여부(truthy).
  const path = stripOuter(s);
  if (isSimplePath(path)) return { kind: 'simple', left: path, op: 'truthy', right: '' };

  // 그 외(논리 연산/함수/체인) — 코드에서 수정.
  return { kind: 'raw', source: s };
}

/** top-level 비교 연산자 1개로 좌/우 분리(없으면 null). 문자열/괄호/대괄호 깊이 무시. */
function splitTopLevelCompare(s: string): { left: string; op: string; right: string } | null {
  let depth = 0;
  let inStr: string | null = null;
  for (let i = 0; i < s.length; i++) {
    const ch = s[i];
    if (inStr) {
      if (ch === '\\') { i++; continue; }
      if (ch === inStr) inStr = null;
      continue;
    }
    if (ch === "'" || ch === '"' || ch === '`') { inStr = ch; continue; }
    if (ch === '(' || ch === '[') { depth++; continue; }
    if (ch === ')' || ch === ']') { depth--; continue; }
    if (depth !== 0) continue;
    // 논리 연산자(`&&`/`||`/`??`)가 top-level 에 있으면 단순 비교 아님 → 분해 거부(raw).
    if ((ch === '&' && s[i + 1] === '&') || (ch === '|' && s[i + 1] === '|') || (ch === '?' && s[i + 1] === '?')) {
      return null;
    }
    for (const op of COMPARE_OPS) {
      if (s.startsWith(op, i)) {
        // `=>` (화살표 함수)·`?.` 오인 방지 — COMPARE_OPS 엔 없으니 안전.
        return { left: s.slice(0, i), op, right: s.slice(i + op.length) };
      }
    }
  }
  return null;
}

/** 외곽 괄호 1겹 균형 제거(의미 보존) — bindingCandidates.stripOuterParens 와 동형. */
function stripOuter(s: string): string {
  let cur = s.trim();
  while (cur.startsWith('(') && cur.endsWith(')')) {
    let depth = 0;
    let balanced = true;
    for (let i = 0; i < cur.length; i++) {
      if (cur[i] === '(') depth++;
      else if (cur[i] === ')') {
        depth--;
        if (depth === 0 && i < cur.length - 1) { balanced = false; break; }
      }
    }
    if (!balanced) break;
    cur = cur.slice(1, -1).trim();
  }
  return cur;
}

/**
 * 단순 경로인지 — `a.b?.c[0].d`(연산자/함수/공백 없음). **선형 스캐너**(정규식 백트래킹 0).
 *
 * 종전 정규식(`(\?\.|\.)?(...|\?\.|\.)*`)은 중첩 수량자 + 중복 분기(`\?\.`/`\.`)로 near-miss
 * 입력(`(_global.x ?? false)` 류 조건 식)에서 catastrophic backtracking(9초+ hang)을 일으켰다.
 * 한 글자씩 상태머신으로 훑어 선형 시간에 판정한다.
 */
function isSimplePath(s: string): boolean {
  const t = s.trim();
  if (t === '') return false;
  let i = 0;
  const n = t.length;
  // 머리 식별자 1개 필수.
  if (!/[A-Za-z_$]/.test(t[0])) return false;
  i++;
  while (i < n && /[\w$]/.test(t[i])) i++;
  // 이후 `.ident` / `?.ident` / `[숫자]` / `['키']` / `["키"]` 반복.
  while (i < n) {
    if (t[i] === '?' && t[i + 1] === '.') {
      i += 2;
      if (i >= n || !/[A-Za-z_$]/.test(t[i])) return false;
      i++;
      while (i < n && /[\w$]/.test(t[i])) i++;
      continue;
    }
    if (t[i] === '.') {
      i++;
      if (i >= n || !/[A-Za-z_$]/.test(t[i])) return false;
      i++;
      while (i < n && /[\w$]/.test(t[i])) i++;
      continue;
    }
    if (t[i] === '[') {
      const close = t.indexOf(']', i);
      if (close === -1) return false;
      const idx = t.slice(i + 1, close).trim();
      // 숫자 인덱스 또는 따옴표 키만 허용(변수 인덱스 → 복합).
      if (!/^\d+$/.test(idx) && !/^'[^']*'$/.test(idx) && !/^"[^"]*"$/.test(idx)) return false;
      i = close + 1;
      continue;
    }
    // 그 외 문자(연산자/괄호/공백) → 단순 경로 아님.
    return false;
  }
  return true;
}

/** 리터럴(문자열/숫자/불리언/null) 또는 단순 경로인지 — 비교 우변 허용 범위. */
function isLiteralOrPath(s: string): boolean {
  const t = s.trim();
  if (/^'[^']*'$/.test(t) || /^"[^"]*"$/.test(t)) return true;
  if (/^-?\d+(\.\d+)?$/.test(t)) return true;
  if (/^(true|false|null|undefined)$/.test(t)) return true;
  return isSimplePath(t);
}

/* ────────────────────────────────────────────────────────────────────────────
 * 보조 — unquote / round-trip 동등성
 * ──────────────────────────────────────────────────────────────────────────── */

/** 따옴표 문자열 리터럴 → 원문(이스케이프 복원). */
function unquote(lit: string): string {
  const t = lit.trim();
  if (t.length >= 2 && (t[0] === "'" || t[0] === '"' || t[0] === '`')) {
    const inner = t.slice(1, -1);
    return inner.replace(/\\(['"`\\])/g, '$1');
  }
  return t;
}

/**
 * 두 식이 의미상 동등한지 — 공백/따옴표 정규화 후 비교(round-trip 자기검증).
 *
 * 직렬화는 `'$t:x'`(작은따옴표)로 통일하지만 원문은 작은/큰따옴표·여분 괄호·공백이 섞여
 * 있을 수 있다. 토큰 시퀀스를 정규화해 비교한다 — 토큰열이 같으면 의미 동일로 본다.
 *
 * @param a 원문 식
 * @param b 직렬화 식
 * @returns 의미 동등 여부
 */
export function exprEquivalent(a: string, b: string): boolean {
  try {
    return normalizeTokens(a) === normalizeTokens(b);
  } catch {
    return false;
  }
}

/**
 * 식을 토큰 시퀀스 정규화 문자열로 — 따옴표 통일(베어 `$t:` ≡ `'$t:'`)·공백 제거·괄호 제거·
 * 보간(`{{...}}`) 안쪽 재귀 정규화. round-trip 자기검증 전용(원문 ↔ 자기 재직렬화 비교).
 *
 * 괄호 제거 근거: 본 비교는 **한 원문 식 ↔ 그 식의 파싱→재직렬화** 만 비교한다(서로 다른 두
 * 식 비교 아님). 우리 문법엔 곱셈류 연산자가 없고 `?:` 구조는 `?`/`:` 토큰 위치로 보존되므로,
 * 같은 파스에서 나온 양측의 괄호를 모두 제거해도 토큰 시퀀스 동일성이 의미 동일성과 일치한다
 * (직렬화가 추가한 안전 괄호 ↔ 원문의 잉여 괄호 차이를 흡수).
 *
 * 보간 재귀: 전체가 `{{...}}` 한 쌍이면 안쪽을 다시 정규화한다(전체 node.text 비교 시 베어/
 * 따옴표 `$t:` 차이를 안쪽까지 흡수). 식 안 박힌 리프 바인딩(`{{path}}`)도 안쪽 경로를 정규화.
 */
function normalizeTokens(src: string): string {
  // 전체가 단일 `{{...}}`(다중 보간 아님) → 안쪽 식만 재귀 정규화(따옴표/베어 차이 흡수).
  const inner = singleWrapInner(src);
  if (inner !== null) return normalizeTokens(inner);

  const toks = tokenize(src);
  return toks
    .map((tk) => {
      if (tk.kind === 'string') return `S:${unquote(tk.value)}`;
      // 리프 바인딩(`{{path}}`) — 안쪽을 재귀 정규화(공백/체이닝 차이 흡수).
      if (tk.kind === 'binding') {
        const inner = /^\{\{([\s\S]*)\}\}$/.exec(tk.value);
        return `B:${inner ? normalizeTokens(inner[1].trim()) : tk.value.replace(/\s+/g, '')}`;
      }
      if (tk.kind === 'tkey') return `S:${tk.value}`; // 베어 $t:key ≡ 문자열 '$t:key'
      if (tk.kind === 'op') return `O:${tk.value}`;
      if (tk.kind === 'ident' || tk.kind === 'number') return `I:${tk.value}`;
      if (tk.kind === 'dot') return '.';
      if (tk.kind === 'lbracket') return '[';
      if (tk.kind === 'rbracket') return ']';
      // 괄호 제거(위 근거) — 안전 괄호 ↔ 잉여 괄호 차이 흡수.
      if (tk.kind === 'lparen' || tk.kind === 'rparen') return '';
      return `X:${tk.value}`;
    })
    .join('');
}
