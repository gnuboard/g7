/**
 * bindingCandidates.ts — 데이터 연결 검색 후보 풀 빌더
 *
 * [속성] 탭 "데이터 연결" 영역의 검색형 피커가 보여줄 후보를, "이 레이아웃에서
 * 연결 가능한 데이터 전체" 를 평탄화한 단일 검색 풀로 구성한다. 각 후보는 표현식
 * (`{{<source>.<path>}}`)·소스 종류·경로·shape(scalar/array)·친화 라벨·미리보기값을
 * 가진다(부록6 §검색 후보 풀 구성).
 *
 * 후보 풀 = **편집기 샘플 데이터 SSoT**:
 *  - `data_sources` 각 소스의 샘플 응답 shape 를 walk 해 leaf(scalar) / 배열(array)
 *    경로를 후보로.
 *  - `_global`/`_local`/`route`/`query`/`_computed` 상태 트리(샘플 baseline)의 키도
 *    동일하게 walk 해 후보로.
 *
 * 친화 명칭: data_source 는 정의의 `label_key`, 상태값은 editor-spec
 * `stateLabels` 카탈로그(`getStateLabelKey`)로 `$t:` 키를 찾아 현재 로케일로 해석한다.
 * 미커버 후보는 raw 키/소스 id 로 폴백 표시한다(마지막 세그먼트 가공·추측 명칭 0).
 *
 * 본 모듈은 순수 함수 — React/DOM 의존 없음. EditorCanvasOverlay 가 docCtx /
 * sample provider / spec / t 를 묶어 호출하고 결과를 PropertyEditorModal 로 내려준다.
 *
 * @since engine-v1.50.0
 */

import type { BindingSourceKind, EditorSpec } from './specTypes';
import { getStateLabelKey } from './editorSpecLoader';

/** 검색 후보 1건 — 평탄화된 연결 가능 데이터 한 항목 */
export interface BindingCandidate {
  /** 런타임 바인딩 표현식 — `{{<source>.<path>}}` (소스 종류별 루트 prefix 포함) */
  expression: string;
  /** 소스 종류 — 필터 칩/그룹핑 */
  source: BindingSourceKind;
  /** 소스 식별자 — data_source 면 소스 id, 상태면 scope(`_global` 등) */
  sourceId: string;
  /** scope 루트 이하 점 경로 (예 `data.data`, `currentUser.data.name`, `q`) */
  path: string;
  /** 데이터 shape — scalar leaf · array · 단일 객체(object) */
  shape: 'scalar' | 'array' | 'object';
  /**
   * 친화 라벨 `$t:` 키 (있으면). 피커가 현재 로케일로 해석해 표시한다. 없으면 피커가
   * raw 키/경로 폴백 — 본 빌더는 키만 부착하고 해석은 피커(편집 대상 사전)가 한다.
   */
  labelKey?: string;
  /** 소스 그룹 라벨 `$t:` 키 (data_source label_key / state scope 라벨) */
  groupLabelKey?: string;
  /** 미리보기값 — 스칼라는 실제값(문자열화), 배열은 길이 */
  preview: string;
  /** (array 한정) 항목 객체 주요 필드 — 첫 원소 객체 키에서 도출 */
  itemFields?: string[];
}

/** data_source 한 건의 입력 — id + 해소된 샘플 응답 + 친화 명칭 키 */
export interface BindingDataSourceInput {
  id: string;
  /** sampleDataProvider 가 해소한 샘플 응답(객체) */
  sample: unknown;
  /** data_source 정의의 `label_key`(`$t:` 키) — 미지정 시 id 폴백 */
  labelKey?: string;
}

/** 상태 트리 입력 — scope 별 샘플 baseline 객체 */
export interface BindingStateInput {
  scope: Exclude<BindingSourceKind, 'data_source'>;
  /** 그 scope 의 샘플 baseline (`_global` 객체 등). 없으면 빈 객체 */
  tree: unknown;
}

/**
 * 현재 선택 노드를 감싸는 iteration 조상에서 노출되는 반복 변수 1건.
 *
 * iteration 노드(`{ iteration: { item_var, index_var } }`) 안의 자식을 편집할 때만 의미가 있다.
 * `index_var` 는 반복 인덱스(scalar, `{{$idx}}`), `item_var` 는 각 행 데이터(item) 이며
 * 행이 객체면 `itemFields` 로 주요 필드(`{{row.id}}` 등)도 후보로 펼친다.
 */
export interface BindingIterationVar {
  /** 변수명 — index_var(예 `$idx`) 또는 item_var(예 `row`) */
  name: string;
  /** 종류 — index(반복 인덱스) / item(행 데이터) */
  kind: 'index' | 'item';
  /** (item 한정) 행 객체 주요 필드 — `{{row.id}}` 처럼 펼칠 후보 (샘플 행에서 도출) */
  itemFields?: string[];
}

export interface BuildBindingCandidatesOptions {
  /** 연결 가능 data_sources (id + 샘플 + label_key) */
  dataSources?: BindingDataSourceInput[];
  /** 상태 트리들 (_global/_local/route/query/_computed 샘플 baseline) */
  states?: BindingStateInput[];
  /**
   * 현재 선택 노드를 감싸는 iteration 조상의 반복 변수들 (바깥→안 순서). 반복 목록 안의
   * 자식 노드를 편집할 때만 채워진다. id 등 식별자 칸에서 `{{$idx}}`/`{{row.id}}` 를
   * 데이터 칩 후보로 노출해 항목별 고유 식별자를 만들 수 있게 한다.
   */
  iterationVars?: BindingIterationVar[];
  /** 병합 editor-spec — stateLabels 카탈로그 조회용 */
  spec?: EditorSpec | null;
  /** 경로 walk 최대 깊이 (무한 중첩 방어). 기본 4 */
  maxDepth?: number;
}

/** 값이 일반 객체(배열/null 아님)인지 */
function isPlainObject(v: unknown): v is Record<string, unknown> {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}

/** 스칼라 미리보기 문자열 — 너무 길면 자른다 */
function scalarPreview(v: unknown): string {
  if (v === null) return 'null';
  if (typeof v === 'string') return v.length > 40 ? `${v.slice(0, 40)}…` : v;
  if (typeof v === 'number' || typeof v === 'boolean') return String(v);
  return '';
}

/** 배열 첫 원소가 객체면 그 키 목록(주요 필드 미리보기) */
function deriveItemFields(arr: unknown[]): string[] | undefined {
  const first = arr.find((x) => isPlainObject(x));
  if (!isPlainObject(first)) return undefined;
  return Object.keys(first).slice(0, 8);
}

/** 객체의 주요 필드 목록 — object 후보 미리보기/힌트(첫 8키) */
function deriveObjectFields(obj: Record<string, unknown>): string[] {
  return Object.keys(obj).slice(0, 8);
}

/**
 * 객체 노드가 "단일 도메인 객체"(object 후보 자격)인지 —.
 *
 * 컴포넌트가 통째로 바라보는 객체(ProductCard.product, Avatar.author, UserProfile.user)는
 * 직접 스칼라 leaf 를 하나 이상 가진 비-빈 객체다(예 `{id,name,email}`). 순수 컨테이너
 * (예 `{data:{...}}`)는 직접 스칼라 leaf 가 없어 object 후보에서 제외 → 피커 범람 방지.
 */
function isEntityObject(obj: Record<string, unknown>): boolean {
  const entries = Object.entries(obj);
  if (entries.length === 0) return false;
  return entries.some(([, v]) => v === null || (typeof v !== 'object'));
}

/**
 * 한 값 트리를 walk 해 scalar/array/object 후보를 누적한다.
 *
 * - 배열 → `array` 후보 1건(그 경로). 배열 내부는 더 내려가지 않는다(항목 필드는
 *   itemFields 로 요약 — 행 단위 바인딩은 iteration 영역이지 데이터 prop 바인딩 아님).
 * - 스칼라(문자열/숫자/불리언/null) → `scalar` 후보 1건.
 * - 객체 → 자식으로 재귀(깊이 제한). 더해 그 객체가 단일 도메인 객체(직접 스칼라 leaf 보유)
 *  면 `object` 후보 1건도 emit. 루트
 *   객체(path 빈 문자열)는 후보 아님(scope/소스 루트 자체).
 */
function walkValue(
  value: unknown,
  path: string,
  depth: number,
  maxDepth: number,
  emit: (
    path: string,
    shape: 'scalar' | 'array' | 'object',
    preview: string,
    fields?: string[],
  ) => void,
): void {
  if (Array.isArray(value)) {
    emit(path, 'array', `[${value.length}]`, deriveItemFields(value));
    return;
  }
  if (isPlainObject(value)) {
    // 단일 도메인 객체면 object 후보로도 노출(루트 제외 — 루트는 scope/소스 자체).
    if (path && isEntityObject(value)) {
      const fields = deriveObjectFields(value);
      emit(path, 'object', `{${fields.length}}`, fields);
    }
    if (depth >= maxDepth) return;
    for (const [k, v] of Object.entries(value)) {
      const next = path ? `${path}.${k}` : k;
      walkValue(v, next, depth + 1, maxDepth, emit);
    }
    return;
  }
  // 스칼라 leaf
  emit(path, 'scalar', scalarPreview(value));
}

/** iteration 노드의 반복 변수 선언 (item_var/index_var) — 트리 walk 시 읽는 최소 shape */
interface IterationNodeShape {
  iteration?: {
    source?: unknown;
    item_var?: unknown;
    index_var?: unknown;
  } | null;
  children?: unknown;
}

/**
 * 선택 노드 경로를 따라 내려가며 iteration 조상의 반복 변수를 수집한다(바깥→안 순서).
 *
 * 반복 목록 안의 자식(예: id 칸)을 편집할 때, 그 노드를 감싸는 모든 iteration 노드의
 * `index_var`(반복 인덱스)와 `item_var`(행 데이터)를 데이터 칩 후보로 노출하기 위함이다.
 * item 의 주요 필드(`{{row.id}}` 등)는 `arrayItemFields`(이미 빌드된 array 후보의
 * itemFields 룩업: 표현식 → 필드목록)에서 iteration source 표현식으로 찾아 펼친다.
 *
 * @param root 컴포넌트 트리 루트(`{ children }`)
 * @param path 선택 노드의 인덱스 경로(number[])
 * @param arrayItemFields iteration source 표현식 → 항목 필드목록 (array 후보에서 도출)
 * @returns 반복 변수 목록(경로상 iteration 노드 순서대로)
 */
export function collectIterationVars(
  root: IterationNodeShape,
  path: number[],
  arrayItemFields?: Map<string, string[]>,
): BindingIterationVar[] {
  const out: BindingIterationVar[] = [];
  let node: IterationNodeShape | undefined = root;
  for (const idx of path) {
    if (!node) break;
    const children = Array.isArray(node.children) ? (node.children as IterationNodeShape[]) : [];
    node = children[idx];
    if (!node) break;
    const it = node.iteration;
    if (!it || it.source === undefined) continue;
    if (typeof it.index_var === 'string' && it.index_var.length > 0) {
      out.push({ name: it.index_var, kind: 'index' });
    }
    if (typeof it.item_var === 'string' && it.item_var.length > 0) {
      // iteration source 표현식으로 array 후보의 itemFields 룩업(없으면 빈 — 인덱스만 유효).
      const srcExpr = typeof it.source === 'string' ? it.source : '';
      const fields = (srcExpr && arrayItemFields?.get(normalizeSourceExpr(srcExpr))) || [];
      out.push({ name: it.item_var, kind: 'item', itemFields: fields });
    }
  }
  return out;
}

/** iteration source 표현식을 itemFields 룩업 키로 정규화 — `{{}}` 껍질/공백 제거. */
function normalizeSourceExpr(expr: string): string {
  return expr.replace(/^\{\{|\}\}$/g, '').trim();
}

/**
 * 빌드된 후보 중 array 후보의 (표현식 → itemFields) 룩업 맵을 만든다.
 * collectIterationVars 가 item_var 의 주요 필드를 펼칠 때 쓴다.
 *
 * @param candidates buildBindingCandidates 결과
 * @return 표현식(정규화) → itemFields
 */
export function buildArrayItemFieldsLookup(candidates: BindingCandidate[]): Map<string, string[]> {
  const map = new Map<string, string[]>();
  for (const c of candidates) {
    if (c.shape === 'array' && c.itemFields && c.itemFields.length > 0) {
      map.set(normalizeSourceExpr(c.expression), c.itemFields);
    }
  }
  return map;
}

/**
 * 연결 가능 데이터 후보 풀을 구성한다.
 *
 * @param options 데이터소스 + 상태 트리 + spec
 * @return 평탄 후보 배열(데이터소스 먼저, 그 다음 상태 — 검색은 피커가 필터)
 */
export function buildBindingCandidates(
  options: BuildBindingCandidatesOptions,
): BindingCandidate[] {
  const maxDepth = options.maxDepth ?? 4;
  const out: BindingCandidate[] = [];

  // 1. data_sources — 소스 id 가 표현식 루트(`{{<id>.<path>}}`)
  for (const ds of options.dataSources ?? []) {
    if (!ds || typeof ds.id !== 'string' || ds.id.length === 0) continue;
    walkValue(ds.sample, '', 0, maxDepth, (path, shape, preview, itemFields) => {
      // 루트가 스칼라(path 빈 문자열)면 표현식은 소스 id 자체 — 드문 케이스지만 허용.
      const expr = path ? `{{${ds.id}.${path}}}` : `{{${ds.id}}}`;
      out.push({
        expression: expr,
        source: 'data_source',
        sourceId: ds.id,
        path,
        shape,
        groupLabelKey: ds.labelKey?.startsWith('$t:') ? ds.labelKey.slice(3) : ds.labelKey,
        preview,
        itemFields,
      });
    });
  }

  // 2. 상태 트리 — scope 가 표현식 루트(`{{_global.<path>}}`)
  for (const st of options.states ?? []) {
    if (!st || typeof st.scope !== 'string') continue;
    walkValue(st.tree, '', 0, maxDepth, (path, shape, preview, itemFields) => {
      if (!path) return; // scope 루트 자체는 후보 아님(항상 객체)
      const labelKey = getStateLabelKey(options.spec, st.scope, path) ?? undefined;
      out.push({
        expression: `{{${st.scope}.${path}}}`,
        source: st.scope,
        sourceId: st.scope,
        path,
        shape,
        labelKey: labelKey?.startsWith('$t:') ? labelKey.slice(3) : labelKey,
        preview,
        itemFields,
      });
    });
  }

  // 3. iteration 변수 — 현재 노드를 감싸는 반복 조상의 인덱스/행 변수.
  //    인덱스는 scalar 칩(`{{$idx}}`), 행(item)은 객체면 주요 필드를 scalar 칩으로 펼친다
  //    (`{{row.id}}` 등 — 행 자체 `{{row}}` 는 통째 바인딩이 드물어 제외, 필드만 노출).
  for (const iv of options.iterationVars ?? []) {
    if (!iv || typeof iv.name !== 'string' || iv.name.length === 0) continue;
    if (iv.kind === 'index') {
      out.push({
        expression: `{{${iv.name}}}`,
        source: 'iteration',
        sourceId: iv.name,
        path: '',
        shape: 'scalar',
        preview: '0',
      });
      continue;
    }
    // item — 행 객체 주요 필드를 후보로 (고유 식별자 후보: id 류)
    for (const field of iv.itemFields ?? []) {
      out.push({
        expression: `{{${iv.name}.${field}}}`,
        source: 'iteration',
        sourceId: iv.name,
        path: field,
        shape: 'scalar',
        preview: '',
      });
    }
  }

  return out;
}

/** 바인딩 표현식 파싱 결과 — reverseResolve(미리채움)용 */
export interface ParsedBinding {
  /** 소스 종류 (`data_source` 면 data_source, 그 외 상태 scope) */
  source: BindingSourceKind;
  /** 소스 식별자 — data_source id 또는 scope */
  sourceId: string;
  /** scope/소스 루트 이하 점 경로 (없으면 빈 문자열) */
  path: string;
  /**
   * 원본에 옵셔널 체이닝(`?.`)이 쓰였는지 — 재기입 시 안전 형태 보존용.
   * G7 의 표준 바인딩은 `{{products?.data?.data ?? []}}` 처럼 `?.` + `?? 폴백` 을 쓴다.
   */
  optional?: boolean;
  /**
   * 원본의 널 병합 폴백 식 — `?? []` 의 `[]`, `?? ''` 의 `''` 등(있으면). 재기입 시 동일
   * 폴백을 유지해 데이터 미도착 시 런타임 에러를 막는다(CLAUDE.md fallback 필수 규칙).
   */
  fallback?: string;
  /**
   * SEO 다국어 추출 함수 래퍼 이름(있으면 `$localized`). `$localized(product.data.meta_title)`
   * 처럼 단일 경로 인자를 감싼 알려진 추출 함수는 그 인자 경로를 단일 바인딩으로 인지하되,
   * 재기입 시 함수 래핑을 보존하려고 이름을 기억한다. SEO 메타값(meta_title/name 등 다국어 객체)을
   * 현재 로케일 문자열로 추출하는 코어 SEO 표현식 함수(ExpressionEvaluator) 와 정합.
   */
  localeFn?: string;
}

/** 단일 경로 인자를 감싸는 알려진 SEO 추출 함수 — 인자 경로를 단일 바인딩 칩으로 노출한다. */
const PATH_WRAPPER_FNS: ReadonlyArray<string> = ['$localized'];

/**
 * `$localized( <inner> )` 처럼 알려진 단일-경로 추출 함수 래핑을 벗겨 인자식과 함수명을 돌려준다.
 * 래핑이 아니면 `{ fn: null, inner: s }`. 인자가 정확히 1개(top-level 콤마 0)일 때만 인지한다
 * (다인자 호출은 단일 경로 칩 대상이 아님 → 복합식 디그레이드).
 */
function unwrapPathWrapperFn(s: string): { fn: string | null; inner: string } {
  const t = s.trim();
  const open = t.indexOf('(');
  if (open === -1 || !t.endsWith(')')) return { fn: null, inner: t };
  const name = t.slice(0, open).trim();
  if (!PATH_WRAPPER_FNS.includes(name)) return { fn: null, inner: t };
  // 함수명 직후 `(` ~ 마지막 `)` 사이가 인자식. 괄호 균형 + top-level 콤마 0 확인.
  const argSrc = t.slice(open + 1, -1);
  let depth = 0;
  let inStr: string | null = null;
  for (let i = 0; i < argSrc.length; i++) {
    const ch = argSrc[i];
    if (inStr) {
      if (ch === '\\') { i++; continue; }
      if (ch === inStr) inStr = null;
      continue;
    }
    if (ch === "'" || ch === '"' || ch === '`') { inStr = ch; continue; }
    if (ch === '(' || ch === '[') depth++;
    else if (ch === ')' || ch === ']') { depth--; if (depth < 0) return { fn: null, inner: t }; }
    else if (depth === 0 && ch === ',') return { fn: null, inner: t }; // 다인자 — 단일 경로 칩 대상 아님.
  }
  if (depth !== 0) return { fn: null, inner: t };
  return { fn: name, inner: argSrc.trim() };
}

/** 알려진 상태 scope 루트 — 표현식 첫 세그먼트가 이 중 하나면 그 scope */
const STATE_SCOPES: ReadonlyArray<Exclude<BindingSourceKind, 'data_source'>> = [
  '_global',
  '_local',
  'route',
  'query',
  '_computed',
];

/**
 * 외곽 괄호 1겹을 제거한다(균형 잡힌 경우만). `(x)` → `x`, `(a ?? b)` → `a ?? b`.
 * conditionRecipeEngine 의 stripRedundantOuterParens 와 동형(의미 보존).
 */
function stripOuterParens(s: string): string {
  let cur = s.trim();
  while (cur.startsWith('(') && cur.endsWith(')')) {
    let depth = 0;
    let balanced = true;
    for (let i = 0; i < cur.length; i++) {
      if (cur[i] === '(') depth++;
      else if (cur[i] === ')') {
        depth--;
        if (depth === 0 && i < cur.length - 1) {
          balanced = false;
          break;
        }
      }
    }
    if (!balanced) break;
    cur = cur.slice(1, -1).trim();
  }
  return cur;
}

/**
 * 데이터 prop 현재값(바인딩 표현식)을 파싱해 소스/경로로 분해한다 (6-a,
 * 의미 보존 정규화는 6-b 후속).
 *
 * BindingEditor 가 기존 바인딩을 열 때 검색 피커에 소스/경로를 미리채우는 데 쓴다.
 *
 * **의미 보존 정규화(conditionRecipeEngine.normalizeConditionExpr 선례와
 * 동형)**: G7 의 표준 바인딩은 `{{products?.data?.data ?? []}}` 처럼 옵셔널 체이닝(`?.`)과
 * 널 병합 폴백(`?? []`/`?? ''`)을 쓴다. 이들은 "단순 경로 접근의 안전 변형"일 뿐이므로
 * 비교/추출 전에 흡수한다:
 *  - `?.` → `.`(옵셔널 체이닝 → 멤버 접근, 경로 추출에서 동치)
 *  - 외곽 `?? <폴백>` 분리(폴백 식은 `fallback` 으로 보존 — 재기입 시 동일 폴백 유지)
 *  - 외곽 괄호 1겹 제거(`(products?.data ?? [])` 형태)
 *  - 공백 제거
 *
 * 정규화 후에도 단순 점 경로(`a.b.c` / `a.b[0].c`)가 아니면(연산자·함수·삼항·다중 바인딩·
 * 폴백이 또 다른 표현식인 경우) null → 호출자가 "복합 바인딩(코드 편집)" 디그레이드
 * (부록6 가드). 첫 세그먼트가 알려진 상태 scope 면 그 scope, 아니면 data_source.
 *
 * 무손실 원칙(선례 L1 경계): 본 정규화는 **읽기(역해석) 전용** — 저장값을 바꾸지 않는다.
 * 재기입은 `buildBindingExpression` 이 파싱된 소스/경로 + 보존된 fallback/optional 로
 * G7 표준 안전 형태를 다시 만든다.
 *
 * @param value props[propKey] 또는 iteration.source 현재값
 * @return 파싱 결과 또는 null
 */
export function parseBindingExpression(value: unknown): ParsedBinding | null {
  if (typeof value !== 'string') return null;
  const trimmed = value.trim();
  // 정확히 `{{ ... }}` 한 쌍만(앞뒤 보간 텍스트/다중 바인딩 배제).
  const m = /^\{\{\s*([^{}]+?)\s*\}\}$/.exec(trimmed);
  if (!m) return null;
  let inner = stripOuterParens(m[1].trim());

  // 외곽 널 병합 폴백 분리 — `<expr> ?? <fallback>`. top-level `??` 만(괄호 깊이 0).
  let fallback: string | undefined;
  {
    let depth = 0;
    for (let i = 0; i < inner.length - 1; i++) {
      const ch = inner[i];
      if (ch === '(' || ch === '[') depth++;
      else if (ch === ')' || ch === ']') depth--;
      else if (depth === 0 && ch === '?' && inner[i + 1] === '?') {
        fallback = inner.slice(i + 2).trim();
        inner = inner.slice(0, i).trim();
        break;
      }
    }
  }
  inner = stripOuterParens(inner);

  // SEO 단일-경로 추출 함수 래핑(`$localized(<path>)`) 흡수 — 인자 경로를 단일 바인딩으로
  // 인지하되 함수명은 보존(재기입 시 래핑 복원). SEO 메타값(meta_title/name 등 다국어 객체)을
  // 현재 로케일 문자열로 추출하는 코어 SEO 표현식 함수와 정합. 래핑이 아니면 inner 불변.
  const unwrapped = unwrapPathWrapperFn(inner);
  const localeFn = unwrapped.fn ?? undefined;
  if (localeFn) inner = stripOuterParens(unwrapped.inner);

  // 옵셔널 체이닝 흡수 — 경로 추출에서 동치. 원본에 `?.` 가 있었는지 기억(재기입 보존).
  const optional = inner.includes('?.');
  const normalized = inner.replace(/\?\./g, '.');

  // 정규화 후 단순 점 경로만 단일 바인딩으로 인정. 폴백이 단순 리터럴(`[]`/`''`/`""`/
  // `{}`/숫자/불리언/null)이 아닌 또 다른 표현식이면 복합식 → null(디그레이드).
  if (!/^[A-Za-z_$][\w$]*(\.[A-Za-z_$][\w$]*|\[\d+\])*$/.test(normalized)) return null;
  if (fallback !== undefined && !/^(\[\s*\]|\{\s*\}|''|""|`+`|-?\d+(\.\d+)?|true|false|null)$/.test(fallback)) {
    return null;
  }

  const dot = normalized.indexOf('.');
  const head = dot === -1 ? normalized : normalized.slice(0, dot);
  const rest = dot === -1 ? '' : normalized.slice(dot + 1);
  const base = STATE_SCOPES.includes(head as Exclude<BindingSourceKind, 'data_source'>)
    ? { source: head as BindingSourceKind, sourceId: head, path: rest }
    : // data_source — 첫 세그먼트가 소스 id, 나머지가 경로
      { source: 'data_source' as BindingSourceKind, sourceId: head, path: rest };
  return { ...base, optional, fallback, localeFn };
}

/**
 * 파싱된 바인딩(소스/경로 + 보존된 optional/fallback)으로 G7 표준 바인딩 표현식을 만든다
 *
 *
 * 검색 피커에서 후보를 선택하면 그 후보의 `expression`(단순 형태)을 그대로 쓰지 않고,
 * shape 에 맞는 안전 형태로 다시 만든다 — `?.` 체이닝 + shape 별 폴백(array `?? []`,
 * scalar `?? ''`). 이렇게 해야 데이터 미도착 시 런타임 에러(`undefined.map`/`.length`)를
 * 막는다(CLAUDE.md "fallback 필수" 규칙).
 *
 * SEO 단일-경로 추출 함수(`$localized`)로 래핑된 바인딩을 다시 쓸 때는 `localeFn` 을 넘긴다.
 * `$localized(<src>.<path>)` 형태로 만들고 폴백/옵셔널 체이닝은 붙이지 않는다 — `$localized` 는
 * 다국어 객체(또는 undefined)를 받아 현재 로케일 문자열을 반환하므로 함수 자체가 안전 폴백
 * (`?.`/`?? ''` 불필요)이며, 인자에 옵셔널 체이닝을 넣으면 코어 SEO ExpressionEvaluator 가
 * 인자 경로를 다국어 객체로 받지 못한다.
 *
 * @param sourceId 소스 식별자(data_source id 또는 scope)
 * @param path scope/소스 루트 이하 점 경로
 * @param shape 데이터 shape — 폴백 기본값 결정(array=`[]`, scalar=`''`, object=`{}`)
 * @param localeFn SEO 추출 함수명(있으면 `$localized`) — 지정 시 함수 래핑 형태로 만든다
 * @return 안전 바인딩 표현식 `{{<src>?.<path> ?? <fallback>}}` (localeFn 시 `{{$localized(<src>.<path>)}}`)
 */
export function buildBindingExpression(
  sourceId: string,
  path: string,
  shape: 'scalar' | 'array' | 'object',
  localeFn?: string,
): string {
  const segs = path ? path.split('.').filter(Boolean) : [];
  if (localeFn && PATH_WRAPPER_FNS.includes(localeFn)) {
    // 추출 함수 래핑 — 인자는 멤버 접근(`.`)으로(옵셔널 체이닝 금지), 폴백 없음.
    const arg = [sourceId, ...segs].join('.');
    return `{{${localeFn}(${arg})}}`;
  }
  // `<src>?.<a>?.<b>` — 마지막을 제외한 접근을 옵셔널 체이닝으로(중간 undefined 안전).
  const chain = [sourceId, ...segs].join('?.');
  const fallback = shape === 'array' ? '[]' : shape === 'object' ? '{}' : "''";
  return `{{${chain} ?? ${fallback}}}`;
}

/**
 * 후보 풀을 shape 로 필터링한다(데이터 prop 의 shape 에 맞는 후보만 노출).
 *
 * @param candidates 전체 후보
 * @param shape 데이터 prop 의 shape
 * @return shape 일치 후보
 */
export function filterCandidatesByShape(
  candidates: BindingCandidate[],
  shape: 'scalar' | 'array' | 'object',
): BindingCandidate[] {
  return candidates.filter((c) => c.shape === shape);
}

/**
 * 키워드로 후보를 필터링한다(친화 명칭·소스명·경로·미리보기값 매칭).
 *
 * 친화 명칭은 호출자(피커)가 해석한 문자열을 `resolvedLabel` 로 부착해 넘긴다 —
 * 본 함수는 라벨 해석을 하지 않는다(`$t:` 사전 의존 회피, 순수 함수 유지).
 *
 * @param candidates shape 필터된 후보(+resolvedLabel)
 * @param keyword 검색어(소문자 비교, 공백 제거)
 * @return 매칭 후보
 */
export function searchCandidates<T extends BindingCandidate & { resolvedLabel?: string }>(
  candidates: T[],
  keyword: string,
): T[] {
  const kw = keyword.trim().toLowerCase();
  if (kw === '') return candidates;
  return candidates.filter((c) => {
    const haystack = [c.resolvedLabel ?? '', c.sourceId, c.path, c.preview]
      .join(' ')
      .toLowerCase();
    return haystack.includes(kw);
  });
}

/**
 * 동작/에러 컨텍스트 데이터칩 후보 — 정적 5종/N종.
 *
 * 데이터소스 onSuccess(`response.*`)·onError(`error.*`)·onReceive(`message.*`)·errorHandling
 * (`error.*`) 의 동작 param·메시지·path 칸이 표현식 칩(DataChipValueInput)으로 그 컨텍스트
 * 변수를 검색·삽입할 수 있도록, 컨텍스트별 정적 칩을 BindingCandidate 로 만든다. `expression`
 * 이 정본(`{{error.message}}` 등)이고 `source` 는 그룹핑용이라 기존 종류(`_local`)를 빌린다
 * (런타임은 expression 만 평가 — source 종류는 표시 분류일 뿐).
 *
 * 확장(모듈/템플릿)이 editor-spec `actionChipCandidates` 로 선언한 도메인 응답 필드는
 * `extra` 로 받아 코어 기본 후보 뒤에 이어 붙인다(코어는 도메인 중립 루트만 안다 — PG 결제
 * 응답 필드 같은 도메인 칩은 그 확장이 제공). 같은 path 중복은 코어 우선으로 1회만 노출한다.
 *
 * @param context 동작/에러 컨텍스트 종류
 * @param t 다국어 해석(라벨 — 미해석 시 path 폴백)
 * @param extra 확장 editor-spec 의 actionChipCandidates (컨텍스트별 배열). 미지정 시 코어만.
 * @return 컨텍스트 칩 후보 배열
 */
export function buildActionContextCandidates(
  context: 'response' | 'error' | 'payload',
  t: (key: string, params?: Record<string, string | number>) => string,
  extra?: import('./specTypes').EditorActionChipCandidatesSpec | null,
): BindingCandidate[] {
  // 컨텍스트별 루트 변수 + 하위 경로(친화 라벨 키). 런타임 ActionDispatcher 가 제공하는
  // 컨텍스트 변수명과 일치(response/error/message). 코어는 도메인 중립 루트만 안다 —
  // 도메인 응답 필드(PG 결제의 data.pg_payment_handler 등)는 확장이 extra 로 더한다.
  const SPECS: Record<typeof context, Array<{ path: string; labelKey: string; shape: BindingCandidate['shape'] }>> = {
    response: [
      { path: 'data', labelKey: 'layout_editor.action_chip.response_data', shape: 'object' },
      { path: 'message', labelKey: 'layout_editor.action_chip.response_message', shape: 'scalar' },
    ],
    error: [
      { path: 'status', labelKey: 'layout_editor.action_chip.error_status', shape: 'scalar' },
      { path: 'message', labelKey: 'layout_editor.action_chip.error_message', shape: 'scalar' },
      { path: 'errors', labelKey: 'layout_editor.action_chip.error_errors', shape: 'object' },
      { path: 'data', labelKey: 'layout_editor.action_chip.error_data', shape: 'object' },
      { path: 'statusText', labelKey: 'layout_editor.action_chip.error_status_text', shape: 'scalar' },
    ],
    payload: [
      { path: 'data', labelKey: 'layout_editor.action_chip.payload_data', shape: 'object' },
    ],
  };
  const root = context === 'response' ? 'response' : context === 'error' ? 'error' : 'message';
  // 코어 기본 + 확장 후보 concat. 같은 path 는 코어 우선으로 1회만(확장이 코어 필드를 덮지 않음).
  const seen = new Set<string>();
  const merged: Array<{ path: string; labelKey: string; shape: BindingCandidate['shape'] }> = [];
  for (const s of SPECS[context]) {
    if (seen.has(s.path)) continue;
    seen.add(s.path);
    merged.push(s);
  }
  const extraList = extra?.[context];
  if (Array.isArray(extraList)) {
    for (const s of extraList) {
      if (!s || typeof s.path !== 'string' || typeof s.labelKey !== 'string' || seen.has(s.path)) continue;
      seen.add(s.path);
      merged.push({ path: s.path, labelKey: s.labelKey, shape: s.shape ?? 'scalar' });
    }
  }
  return merged.map((s) => ({
    expression: `{{${root}.${s.path}}}`,
    source: '_local',
    sourceId: root,
    path: s.path,
    shape: s.shape,
    labelKey: s.labelKey,
    preview: t(s.labelKey),
  }));
}
