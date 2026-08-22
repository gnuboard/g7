/**
 * editorSpecLoader.ts — 편집기 스펙 로더
 *
 * Phase 1 은 부팅 호출 자리만 마련했고(빈 응답 안전 처리), Phase 3 가 템플릿
 * `nesting` 블록 로딩을, Phase 4 S6-1 이 **활성 모듈/플러그인 editor-spec.json
 * 의 fetch + 전 블록 네임스페이스 병합 + 조회 헬퍼**를 더한다.
 *
 * 병합 규칙 (5.2):
 *  - 편집 대상 템플릿 스펙 + 활성 모듈/플러그인 스펙을 단일 병합본으로 합친다.
 *  - 비활성 확장 스펙은 병합하지 않는다 (활성 식별자만 fetch).
 *  - record 형 블록(`controls`/`componentCapabilities`/`actionRecipes`/
 *    `conditionRecipes`/`sampleData.byX`)은 key 병합 — 뒤 단계가 같은 key 를
 *    덮어쓴다(템플릿이 마지막이라 최우선).
 *  - `componentPalette.groups` / `states.groups` 는 concat (라인 10853).
 *  - `nesting` 은 draggable union + containers key 병합.
 *  - `sampleGlobal` 은 본 로더에서 **병합하지 않는다** — 코어 우선 충돌 정책 +
 *    dev 경고가 필요하므로 순서 있는 소스 목록(`sampleGlobalSources`)으로 노출하고,
 *  `sampleGlobalChain.ts` 의 deep merge 체인이 코어 시드와 합성한다.
 *
 *  - 활성 확장 식별자 SSoT 는 `window.G7Config.activeModules` / `activePlugins`
 * 옵션으로 직접 주입 가능(테스트).
 *
 * @since engine-v1.50.0
 * @since engine-v1.50.0
 */

import type {
  EditorSpec,
  EditorControlSpec,
  ComponentCapabilitySpec,
  EditorStateGroupSpec,
  EditorStateLabelSpec,
  ComponentPaletteGroupSpec,
  NestingSpec,
  ActionRecipeSpec,
  ComputedRecipeSpec,
  LoadingComponentSpec,
} from './specTypes';
import { createLogger } from '../../../utils/Logger';

const logger = createLogger('EditorSpecLoader');

/** 병합 소스 종류 — 우선순위/충돌 진단에 사용 */
export type EditorSpecSourceKind = 'template' | 'module' | 'plugin';

export interface ServeEditorSpecResponse {
  success: boolean;
  data: {
    identifier: string;
    spec: EditorSpec | null;
  } | null;
  error?: string;
}

/** 한 확장/템플릿의 sampleGlobal 소스 — 코어 우선 deep merge 체인 입력 */
export interface SampleGlobalSource {
  /** 확장/템플릿 식별자 — 충돌 dev 경고 메시지에 사용 */
  id: string;
  /** 소스 종류 (template/module/plugin) */
  kind: EditorSpecSourceKind;
  /** 그 소스의 `editor-spec.json.sampleGlobal` (없으면 호출자가 필터) */
  sampleGlobal: Record<string, unknown>;
}

/** loadEditorSpec 결과 — 병합 스펙 + sampleGlobal 순서 소스 */
export interface LoadedEditorSpec {
  /**
   * sampleGlobal 을 제외한 전 블록의 네임스페이스 병합 결과.
   * 스펙이 하나도 없으면 null (편집 컨트롤 부재 — 무손실 보존만).
   */
  spec: EditorSpec | null;
  /**
   * 코어 → 모듈 → 플러그인 → 템플릿 순서의 sampleGlobal 소스 목록.
   * (코어 시드는 본 목록에 포함하지 않는다 — 체인 함수가 맨 앞에 둔다.)
   * sampleGlobal 미정의 소스는 제외된다.
   */
  sampleGlobalSources: SampleGlobalSource[];
}

export interface EditorSpecLoadOptions {
  /** 편집 대상 템플릿 식별자 */
  templateIdentifier: string;
  /**
   * 활성 모듈 식별자 배열. 미지정 시 `window.G7Config.activeModules` 에서 추출.
   */
  activeModuleIdentifiers?: string[];
  /**
   * 활성 플러그인 식별자 배열. 미지정 시 `window.G7Config.activePlugins` 에서 추출.
   */
  activePluginIdentifiers?: string[];
  /** API base URL (기본 '/api') */
  apiBaseUrl?: string;
  /** fetch 주입 — 테스트 용 */
  fetcher?: typeof fetch;
  /**
   * 코어 내장 시드 스펙 — 병합 base.
   *
   * 코어 핸들러 스펙 카탈로그(`coreActionRecipes`)를 `actionRecipes`/`initActionRecipes`
   * base 로 주입한다. 가장 먼저 병합되므로 module/plugin/template 이 같은 key 를
   * override 할 수 있다(코어 기본 → module → plugin → template). 미주입 시 코어
   * 시드 없음(테스트/하위호환). 레시피 항목에 `__source:{kind:'core'}` 부착.
   */
  coreSeed?: EditorSpec;
}

/** record 형 블록 키 — 같은 key 충돌 시 뒤 단계가 이긴다 */
const RECORD_BLOCKS = [
  'controls',
  'componentCapabilities',
  'actionRecipes',
  'conditionRecipes',
  'pageSettings',
  // 페이지 설정 4블록. module → plugin → template
  // 병합(템플릿 최우선), 코어 시드는 병합 전 base 로 주입(coreActionRecipes 등).
  'initActionRecipes',
  'errorRecipes',
  'computedRecipes',
  'loadingComponents',
] as const;

/**
 * 레시피 블록 — 각 항목(객체형)에 `__source` 출처 메타를 부착하는 대상.
 *
 * [화면 동작]/[자동 계산]/[에러 처리]/[로딩 화면] 추가 목록의 제공자 배지가 이 메타를
 * 읽는다. 문자열 단축형 레시피는 부착하지 않는다(객체로 변환하면 단축형 의미가 깨짐 —
 * 배지는 폴백). `actionRecipes` 도 컴포넌트 [동작] 탭이 공유하므로 부착 대상이다.
 */
const RECIPE_BLOCKS = [
  'actionRecipes',
  'initActionRecipes',
  'errorRecipes',
  'computedRecipes',
  'loadingComponents',
] as const;

/**
 * 레시피 출처 메타 — 어느 확장이 그 레시피를 제공했는지.
 *
 * 코어 시드 레시피는 `{ kind: 'core' }`(식별자 없음). 추가 목록의 제공자 배지가
 * `kind`/`id` 로 〔코어〕/〔이커머스〕 등을 표기한다.
 */
export interface RecipeSource {
  kind: 'core' | EditorSpecSourceKind;
  /** 확장 식별자 — core 는 없음 */
  id?: string;
}

/**
 * window.G7Config.{key} 에서 활성 확장 식별자 배열을 추출.
 */
function readActiveIdentifiers(key: 'activeModules' | 'activePlugins'): string[] {
  if (typeof window === 'undefined') return [];
  const config = (window as { G7Config?: Record<string, unknown> }).G7Config;
  const list = config?.[key];
  if (!Array.isArray(list)) return [];
  const out: string[] = [];
  for (const item of list) {
    const id = (item as { identifier?: unknown } | null)?.identifier;
    if (typeof id === 'string' && id.length > 0) out.push(id);
  }
  return out;
}

/**
 * 단일 확장/템플릿의 editor-spec 을 fetch. 미존재/실패 시 null.
 *
 * @param kind 소스 종류 — URL prefix 분기 (templates/modules/plugins)
 */
async function fetchOneSpec(
  kind: EditorSpecSourceKind,
  identifier: string,
  apiBaseUrl: string,
  fetcher: typeof fetch,
): Promise<EditorSpec | null> {
  const prefix =
    kind === 'template' ? 'templates' : kind === 'module' ? 'modules' : 'plugins';
  const url = `${apiBaseUrl}/${prefix}/${encodeURIComponent(identifier)}/editor-spec`;
  try {
    const response = await fetcher(url);
    if (!response.ok) {
      // 404 등은 정상 케이스(스펙 미작성 확장) — warn 없이 null
      if (response.status !== 404) {
        logger.warn(`fetchOneSpec: HTTP ${response.status}`, url);
      }
      return null;
    }
    const payload = (await response.json()) as ServeEditorSpecResponse;
    if (!payload?.success || !payload.data) return null;
    return payload.data.spec ?? null;
  } catch (e) {
    logger.warn('fetchOneSpec: fetch failed', url, e);
    return null;
  }
}

/** 레시피 블록 여부 — `__source` 부착 대상인지 */
function isRecipeBlock(block: (typeof RECORD_BLOCKS)[number]): boolean {
  return (RECIPE_BLOCKS as readonly string[]).includes(block);
}

/**
 * 객체형 레시피 항목에 `__source` 출처 메타를 부착한 사본을 반환.
 *
 * 문자열 단축형은 그대로 둔다(객체 변환 시 단축형 의미 손실 — 배지는 폴백). 이미
 * `__source` 가 있으면 덮어쓴다(뒤 단계가 같은 key 를 override 하면 그 출처로 갱신).
 */
function stampRecipeSource(value: unknown, source: RecipeSource): unknown {
  if (value == null || typeof value !== 'object') return value;
  return { ...(value as Record<string, unknown>), __source: source };
}

/**
 * record 블록을 dst 에 key 병합 (뒤 단계 우선).
 *
 * 레시피 블록(`RECIPE_BLOCKS`)이면 src 항목에 `source` 출처 메타를 부착해 병합한다.
 * 비레시피 블록(controls/capabilities 등)은 메타 부착 없이 단순 key 병합.
 */
function mergeRecordBlock(
  dst: EditorSpec,
  src: EditorSpec,
  block: (typeof RECORD_BLOCKS)[number],
  source?: RecipeSource,
): void {
  const srcBlock = src[block] as Record<string, unknown> | undefined;
  if (!srcBlock || typeof srcBlock !== 'object') return;
  const dstBlock = (dst[block] as Record<string, unknown> | undefined) ?? {};
  if (source && isRecipeBlock(block)) {
    const stamped: Record<string, unknown> = {};
    for (const [key, val] of Object.entries(srcBlock)) {
      stamped[key] = stampRecipeSource(val, source);
    }
    (dst as Record<string, unknown>)[block] = { ...dstBlock, ...stamped };
    return;
  }
  (dst as Record<string, unknown>)[block] = { ...dstBlock, ...srcBlock };
}

/** nesting 병합 — draggable union + containers key 병합 */
function mergeNesting(dst: EditorSpec, src: EditorSpec): void {
  if (!src.nesting) return;
  const target: NestingSpec = dst.nesting ?? {};
  const draggable = new Set<string>(target.draggable ?? []);
  for (const name of src.nesting.draggable ?? []) draggable.add(name);
  target.draggable = Array.from(draggable);
  target.containers = { ...(target.containers ?? {}), ...(src.nesting.containers ?? {}) };
  dst.nesting = target;
}

/** componentPalette 병합 — groups concat + entries key 병합 */
function mergePalette(dst: EditorSpec, src: EditorSpec): void {
  if (!src.componentPalette) return;
  const target = dst.componentPalette ?? {};
  const groups: ComponentPaletteGroupSpec[] = [
    ...(target.groups ?? []),
    ...(src.componentPalette.groups ?? []),
  ];
  target.groups = groups;
  target.entries = { ...(target.entries ?? {}), ...(src.componentPalette.entries ?? {}) };
  dst.componentPalette = target;
}

/** states 병합 — groups concat  */
function mergeStates(dst: EditorSpec, src: EditorSpec): void {
  if (!src.states) return;
  const target = dst.states ?? {};
  const groups: EditorStateGroupSpec[] = [
    ...(target.groups ?? []),
    ...(src.states.groups ?? []),
  ];
  target.groups = groups;
  dst.states = target;
}

/** stateLabels 병합 — concat. 같은 key+scope 충돌 시 뒤 단계(템플릿) 우선. */
function mergeStateLabels(dst: EditorSpec, src: EditorSpec): void {
  if (!Array.isArray(src.stateLabels) || src.stateLabels.length === 0) return;
  const merged: EditorStateLabelSpec[] = [...(dst.stateLabels ?? []), ...src.stateLabels];
  // 같은 key+scope 가 여러 번 오면 마지막(템플릿) 항목을 남긴다 — Map 으로 dedup.
  const byKey = new Map<string, EditorStateLabelSpec>();
  for (const entry of merged) {
    if (!entry || typeof entry.key !== 'string' || typeof entry.scope !== 'string') continue;
    byKey.set(`${entry.scope}:${entry.key}`, entry);
  }
  dst.stateLabels = Array.from(byKey.values());
}

/**
 * actionChipCandidates 병합 — 컨텍스트별(response/error/payload) 배열 concat.
 *
 * 코어 기본 후보 뒤에 확장 후보가 붙는다(record key 병합이 아닌 concat — 확장이 같은 컨텍스트에
 * 도메인 응답 필드를 더한다). 같은 path 중복은 호출자(buildActionContextCandidates)가 제거한다.
 */
function mergeActionChipCandidates(dst: EditorSpec, src: EditorSpec): void {
  if (!src.actionChipCandidates) return;
  const target = dst.actionChipCandidates ?? {};
  for (const ctx of ['response', 'error', 'payload'] as const) {
    const add = src.actionChipCandidates[ctx];
    if (!Array.isArray(add) || add.length === 0) continue;
    target[ctx] = [...(target[ctx] ?? []), ...add];
  }
  dst.actionChipCandidates = target;
}

/** sampleData 병합 — byDataSourceId / byEndpointPattern 각각 key 병합 */
function mergeSampleData(dst: EditorSpec, src: EditorSpec): void {
  if (!src.sampleData) return;
  const target = dst.sampleData ?? {};
  target.byDataSourceId = {
    ...(target.byDataSourceId ?? {}),
    ...(src.sampleData.byDataSourceId ?? {}),
  };
  target.byEndpointPattern = {
    ...(target.byEndpointPattern ?? {}),
    ...(src.sampleData.byEndpointPattern ?? {}),
  };
  dst.sampleData = target;
}

/**
 * 한 소스 스펙을 누적 대상(dst)에 네임스페이스 병합.
 * sampleGlobal 은 병합하지 않는다(체인 함수 책임).
 *
 * @param source 레시피 블록에 부착할 출처 메타(코어 시드/확장). 미지정 시 미부착.
 */
function mergeInto(dst: EditorSpec, src: EditorSpec, source?: RecipeSource): void {
  for (const block of RECORD_BLOCKS) mergeRecordBlock(dst, src, block, source);
  mergeNesting(dst, src);
  mergePalette(dst, src);
  mergeStates(dst, src);
  mergeStateLabels(dst, src);
  mergeActionChipCandidates(dst, src);
  mergeSampleData(dst, src);
  // version 은 템플릿(마지막 소스) 값을 우선 보존
  if (src.version) dst.version = src.version;
}

/**
 * 편집기 스펙을 fetch 하고 활성 확장과 네임스페이스 병합한 결과 반환.
 *
 * 백엔드가 `null` 을 돌려주는 스펙은 건너뛴다. 모든 스펙이 부재하면 spec=null
 * (편집 컨트롤 부재 — 무손실 보존만, 원칙 4.4 / 4.6 / 11.3.4).
 *
 * @param options 로드 옵션
 * @return 병합 스펙 + sampleGlobal 순서 소스
 */
export async function loadEditorSpecBundle(
  options: EditorSpecLoadOptions,
): Promise<LoadedEditorSpec> {
  const apiBaseUrl = options.apiBaseUrl ?? '/api';
  const fetcher = options.fetcher ?? (typeof fetch !== 'undefined' ? fetch : undefined);
  if (!fetcher) {
    logger.warn('loadEditorSpecBundle: fetch unavailable, returning null spec');
    return { spec: null, sampleGlobalSources: [] };
  }

  const moduleIds = options.activeModuleIdentifiers ?? readActiveIdentifiers('activeModules');
  const pluginIds = options.activePluginIdentifiers ?? readActiveIdentifiers('activePlugins');

  // 병합 순서: 모듈 → 플러그인 → 템플릿 (템플릿이 마지막 = 최우선).
  // sampleGlobal 충돌 정책은 코어가 맨 앞이므로, 소스 순서도 module → plugin →
  // template 으로 둔다(코어 시드는 체인 함수가 앞에 붙임).
  const ordered: Array<{ kind: EditorSpecSourceKind; id: string }> = [
    ...moduleIds.map((id) => ({ kind: 'module' as const, id })),
    ...pluginIds.map((id) => ({ kind: 'plugin' as const, id })),
    { kind: 'template' as const, id: options.templateIdentifier },
  ];

  const fetched = await Promise.all(
    ordered.map((o) => fetchOneSpec(o.kind, o.id, apiBaseUrl, fetcher)),
  );

  let merged: EditorSpec | null = null;
  const sampleGlobalSources: SampleGlobalSource[] = [];
  // 방안 B: 각 출처 스펙의 byDataSourceId 를 출처별로 보존.
  // 키 = template 은 'template', module/plugin 은 '{kind}:{id}'. 해소 시점에 그
  // 데이터소스의 __source 로 분기해 전역 id 충돌(같은 id, 다른 shape)을 해소한다.
  const bySource: Record<string, Record<string, unknown>> = {};

  // 코어 시드 — 가장 먼저 병합(base). 이후 module/plugin/template 이 같은 key override.
  // (coreActionRecipes 등 코어 핸들러 스펙 카탈로그. `__source:{kind:'core'}` 부착.)
  if (options.coreSeed) {
    merged = {};
    mergeInto(merged, options.coreSeed, { kind: 'core' });
  }

  ordered.forEach((o, i) => {
    const spec = fetched[i];
    if (!spec) return;
    if (spec.sampleGlobal && typeof spec.sampleGlobal === 'object') {
      sampleGlobalSources.push({ id: o.id, kind: o.kind, sampleGlobal: spec.sampleGlobal });
    }
    const byId = spec.sampleData?.byDataSourceId;
    if (byId && typeof byId === 'object') {
      const key = o.kind === 'template' ? 'template' : `${o.kind}:${o.id}`;
      bySource[key] = { ...(bySource[key] ?? {}), ...byId };
    }
    if (merged === null) merged = {};
    mergeInto(merged, spec, { kind: o.kind, id: o.id });
  });

  // 출처별 보존 맵을 병합 결과의 sampleData 에 부착(평탄 byDataSourceId 는 폴백으로 유지).
  if (merged !== null && Object.keys(bySource).length > 0) {
    const m: EditorSpec = merged;
    m.sampleData = { ...(m.sampleData ?? {}), bySource };
  }

  return { spec: merged, sampleGlobalSources };
}

/**
 * 하위 호환 진입점 — 병합 스펙만 반환 (sampleGlobal 소스 미사용 호출자).
 *
 * 기존 LayoutEditorChrome 의 `loadEditorSpec({ templateIdentifier })` 호출 시그니처를
 * 유지하기 위한 래퍼. 신규 호출자는 `loadEditorSpecBundle` 을 사용한다.
 *
 * @param options 로드 옵션
 * @return 병합 EditorSpec 또는 null
 */
export async function loadEditorSpec(
  options: EditorSpecLoadOptions,
): Promise<EditorSpec | null> {
  const { spec } = await loadEditorSpecBundle(options);
  return spec;
}

// ── 조회 헬퍼 ──────────────────────────────────────────────

/** 컴포넌트 이름의 편집 역량 조회 (없으면 null) */
export function getComponentCapability(
  spec: EditorSpec | null | undefined,
  componentName: string,
): ComponentCapabilitySpec | null {
  return spec?.componentCapabilities?.[componentName] ?? null;
}

/** 컨트롤 정의 조회 (없으면 null) */
export function getControl(
  spec: EditorSpec | null | undefined,
  controlId: string,
): EditorControlSpec | null {
  return spec?.controls?.[controlId] ?? null;
}

/** 액션 레시피 조회 (없으면 null) */
export function getActionRecipe(
  spec: EditorSpec | null | undefined,
  recipeId: string,
): unknown | null {
  return spec?.actionRecipes?.[recipeId] ?? null;
}

/** 조건 레시피 조회 (없으면 null) */
export function getConditionRecipe(
  spec: EditorSpec | null | undefined,
  recipeId: string,
): unknown | null {
  return spec?.conditionRecipes?.[recipeId] ?? null;
}

// ── 페이지 설정 레시피 조회 헬퍼 ──────────

/**
 * [화면 동작] 친화 핸들러 스펙 맵 조회.
 *
 * 코어 시드 + 활성 확장 병합본. 각 항목 객체에 `__source` 출처 메타가 부착돼 있다.
 * 미정의 시 빈 객체(디그레이드 — 탭은 코어 스펙·직접 만들기만 노출).
 *
 * @param spec 병합 스펙
 * @return recipeId → `ActionRecipeSpec | string`
 */
export function getInitActionRecipes(
  spec: EditorSpec | null | undefined,
): Record<string, ActionRecipeSpec | string> {
  return spec?.initActionRecipes ?? {};
}

/**
 * [자동 계산] 친화 보기 스펙 맵 조회.
 *
 * 활성 확장 병합본(코어는 9종 보기를 제공하지 않음 — "직접 만들기" 3단계 틀만 코어
 * 고정). 각 항목에 `__source` 부착. 미정의 시 빈 객체(직접 만들기는 코어 제공이라 잔존).
 *
 * @param spec 병합 스펙
 * @return recipeId → `ComputedRecipeSpec`
 */
export function getComputedRecipes(
  spec: EditorSpec | null | undefined,
): Record<string, ComputedRecipeSpec> {
  return spec?.computedRecipes ?? {};
}

/**
 * [에러 처리] 친화 동작 스펙 맵 조회.
 *
 * @param spec 병합 스펙
 * @return recipeId → `ActionRecipeSpec | string`
 */
export function getErrorRecipes(
  spec: EditorSpec | null | undefined,
): Record<string, ActionRecipeSpec | string> {
  return spec?.errorRecipes ?? {};
}

/**
 * [로딩 화면] 로딩 컴포넌트 후보 목록 조회.
 *
 * 레지스트리 이름 + 역할(role) + 라벨. 미정의 시 빈 배열(엔진 기본 CSS 스피너
 * 디그레이드). `role` 로 style 별(spinner→spinner/page, skeleton→skeleton) 필터링은
 * 호출자(LoadingComponentPicker) 책임.
 *
 * @param spec 병합 스펙
 * @return LoadingComponentSpec 배열(병합 순서 보존)
 */
export function getLoadingComponents(
  spec: EditorSpec | null | undefined,
): LoadingComponentSpec[] {
  const block = spec?.loadingComponents;
  if (!block || typeof block !== 'object') return [];
  return Object.values(block).filter(
    (v): v is LoadingComponentSpec =>
      v != null && typeof v === 'object' && typeof (v as LoadingComponentSpec).name === 'string',
  );
}

/**
 * 주어진 scope 에 매칭되는 states 그룹들을 반환.
 *
 * @param spec 병합 스펙
 * @param scope 매칭 기준 (route path / base 식별자 / modal id)
 */
export function getStateGroupsForScope(
  spec: EditorSpec | null | undefined,
  scope: { kind: 'route' | 'base' | 'modal'; match: string },
): EditorStateGroupSpec[] {
  const groups = spec?.states?.groups ?? [];
  return groups.filter(
    (g) => g.scope?.kind === scope.kind && g.scope?.match === scope.match,
  );
}

/**
 * 상태값 명칭 카탈로그에서 `scope`+`key` 에 매칭되는 `label_key` 를 조회.
 *
 * 데이터 연결 검색 피커가 상태값 후보의 친화 명칭을 결선할 때 사용한다. 정확 일치만
 * (접두사 매칭 아님 — 명시한 leaf 만 명명). 미매칭이면 null → 피커가 raw 키 폴백.
 *
 * @param spec 병합 스펙
 * @param scope 상태 스코프(_global/_local/route/query/_computed/data_source)
 * @param key scope 루트 이하 점 경로
 * @return `$t:` 키(label_key) 또는 null
 */
export function getStateLabelKey(
  spec: EditorSpec | null | undefined,
  scope: string,
  key: string,
): string | null {
  const list = spec?.stateLabels;
  if (!Array.isArray(list)) return null;
  for (const entry of list) {
    if (entry?.scope === scope && entry?.key === key) {
      return typeof entry.label_key === 'string' ? entry.label_key : null;
    }
  }
  return null;
}
