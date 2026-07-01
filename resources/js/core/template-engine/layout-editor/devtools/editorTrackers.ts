/**
 * editorTrackers.ts — 레이아웃 편집기 devtools 트래커
 *
 * `LayoutEditorContext` reducer 호출을 G7DevTools 의 `editor-state` 카테고리로
 * 흘려보낸다. 별도 디버그 큐를 두지 않고 `window.__g7Devtools` 의 기존 채널을
 * 그대로 사용 — devtools 비활성 환경에서는 no-op.
 *
 * Phase 1 범위: state 스냅샷 push + 언마운트 clear. 도구 호출 (`g7-editor-state`
 * MCP) 은 본 트래커가 적재한 데이터를 그대로 읽는다.
 *
 * @since engine-v1.50.0
 */

import type { LayoutEditorState, LayoutEditorAction } from '../LayoutEditorContext';
import type { SampleMatchInfo } from '../sample-data/sampleDataProvider';

interface DevtoolsApi {
  trackEditorState?(snapshot: EditorStateSnapshot): void;
  clearEditorStateData?(): void;
  trackEditorSampleMatch?(info: SampleMatchInfo): void;
  clearEditorSampleData?(): void;
  trackEditorHistoryEntry?(entry: EditorHistoryEntry): void;
  clearEditorHistoryData?(): void;
  trackEditorSelection?(info: EditorSelectionInfo): void;
  clearEditorSelectionData?(): void;
  trackEditorDnd?(entry: EditorDndEntry): void;
  clearEditorDndData?(): void;
  trackEditorDocument?(entry: EditorDocumentEntry): void;
  clearEditorDocumentData?(): void;
  trackEditorSpecMerge?(entry: EditorSpecMergeEntry): void;
  clearEditorSpecMergeData?(): void;
  trackEditorPropertyPatch?(entry: EditorPropertyPatchEntry): void;
  clearEditorPropertyPatchData?(): void;
  trackEditorI18n?(entry: EditorI18nEntry): void;
  clearEditorI18nData?(): void;
  trackPageState?(entry: PageStateEntry): void;
  clearPageStateData?(): void;
}

export interface EditorStateSnapshot {
  templateIdentifier: string;
  editMode: LayoutEditorState['editMode'];
  selectedRoutePath: string | null;
  selectedLayoutName: string | null;
  routeTreeSize: number;
  isRouteTreeCollapsed: boolean;
  locale: string;
  previewDevice: LayoutEditorState['previewDevice'];
  previewZoom: number;
  /** 디스패치된 마지막 액션 종류 (트레이싱) */
  lastAction?: LayoutEditorAction['type'];
  timestamp: number;
}

function getDevtools(): DevtoolsApi | null {
  if (typeof window === 'undefined') return null;
  const w = window as unknown as { __g7Devtools?: DevtoolsApi };
  return w.__g7Devtools ?? null;
}

/**
 * `LayoutEditorContext` reducer 호출 직후 호출 — 액션 적용 결과 state 를 적재.
 *
 * 노드 메타(편집 중 내용물)는 적재하지 않는다 — devtools 누수 회귀 가드.
 */
export function trackEditorState(state: LayoutEditorState, lastAction?: LayoutEditorAction['type']): void {
  const devtools = getDevtools();
  if (!devtools?.trackEditorState) return;

  const snapshot: EditorStateSnapshot = {
    templateIdentifier: state.templateIdentifier,
    editMode: state.editMode,
    selectedRoutePath: state.selectedRoute?.path ?? null,
    selectedLayoutName: state.selectedRoute?.layoutName ?? null,
    routeTreeSize: state.routeTree.length,
    isRouteTreeCollapsed: state.isRouteTreeCollapsed,
    locale: state.locale,
    previewDevice: state.previewDevice,
    previewZoom: state.previewZoom,
    lastAction,
    timestamp: Date.now(),
  };

  try {
    devtools.trackEditorState(snapshot);
  } catch {
    // devtools 적재 실패는 본 흐름에 영향 없음
  }
}

/**
 * `LayoutEditorChrome` 언마운트 시 호출 — 적재된 편집기 state 데이터를 지운다.
 * 노드 메타 미누수 회귀 가드.
 */
export function clearEditorStateData(): void {
  const devtools = getDevtools();
  if (!devtools?.clearEditorStateData) return;
  try {
    devtools.clearEditorStateData();
  } catch {
    // 정리 실패는 본 흐름에 영향 없음
  }
}

/**
 * 샘플 데이터 매칭 결과를 devtools 의 `editor-sample-data` 카테고리에 적재한다
 * `sampleDataProvider.resolve` 직후 호출되어 매칭 우선순위/프리셋
 * 키/fetch 발생 여부를 추적.
 *
 * 본 트래커가 실패해도 편집기 본체 동작은 영향 없음 (degrade safety).
 *
 * @since engine-v1.50.0
 */
export function trackSampleMatch(info: SampleMatchInfo): void {
  const devtools = getDevtools();
  if (!devtools?.trackEditorSampleMatch) return;
  try {
    devtools.trackEditorSampleMatch(info);
  } catch {
    // devtools 적재 실패는 본 흐름에 영향 없음
  }
}

/**
 * `LayoutEditorChrome` 언마운트 시 호출 — `editor-sample-data` 누적 기록 정리.
 *
 * @since engine-v1.50.0
 */
export function clearEditorSampleData(): void {
  const devtools = getDevtools();
  if (!devtools?.clearEditorSampleData) return;
  try {
    devtools.clearEditorSampleData();
  } catch {
    // 정리 실패는 본 흐름에 영향 없음
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// editor-history
// 추가/삭제/이동/속성 변경/인라인 텍스트 편집 5종 액션의 push/undo/redo 추적.
// 스냅샷 자체는 적재하지 않고 액션 유형/요약 라벨만 보낸다 (메타 누수 회귀 가드).
// ─────────────────────────────────────────────────────────────────────────────

export type EditorHistoryActionKind =
  | 'insert'
  | 'remove'
  | 'move'
  | 'property_change'
  | 'inline_text_edit';

export interface EditorHistoryEntry {
  /** push / undo / redo 중 어느 호출이었는지 */
  op: 'push' | 'undo' | 'redo' | 'clear';
  /** 어떤 종류의 편집 액션인지 */
  actionKind?: EditorHistoryActionKind;
  /** 사람이 읽을 수 있는 짧은 라벨 (e.g. "insert Button at /0/2") */
  label?: string;
  /** push 이후 스택 크기 — undo/redo 후 가용 플래그 확인 */
  stackSize: number;
  /** cursor 위치 (0-based) */
  cursor: number;
  /** undo/redo 가용 */
  canUndo: boolean;
  canRedo: boolean;
  timestamp: number;
}

export function trackEditorHistory(entry: EditorHistoryEntry): void {
  const devtools = getDevtools();
  if (!devtools?.trackEditorHistoryEntry) return;
  try {
    devtools.trackEditorHistoryEntry(entry);
  } catch {
    // devtools 적재 실패는 본 흐름에 영향 없음
  }
}

export function clearEditorHistoryData(): void {
  const devtools = getDevtools();
  if (!devtools?.clearEditorHistoryData) return;
  try {
    devtools.clearEditorHistoryData();
  } catch {
    // 정리 실패는 본 흐름에 영향 없음
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// editor-selection
// hover / 선택 / 잠금 판정 결과 추적. 노드 메타·텍스트·props 값은 적재 금지.
// ─────────────────────────────────────────────────────────────────────────────

export interface EditorSelectionInfo {
  op: 'select' | 'hover' | 'clear';
  /** 선택/hover 대상의 componentPath (data-editor-path 와 동일 표기) */
  componentPath: string | null;
  /** 컴포넌트 이름 (Div, Button 등) — 메타 키만 */
  componentName: string | null;
  /** 잠금 종류 분류 */
  lockKind: 'none' | 'base' | 'partial' | 'extension' | 'data_bound' | 'extension_point';
  /** 네비게이션 가능 어포던스 종류 */
  navAffordance: 'none' | 'route_in_tree' | 'route_not_in_tree' | 'external_url' | 'dynamic_path';
  /**
   * 리사이즈 핸들 활성 축 — 선택 노드의 width/height 컨트롤
   * 선언에 따라 활성화되는 핸들. 진행 중 드래그 축은 `resizingAxis`.
   */
  resizeHandles?: { width: boolean; height: boolean };
  /** 진행 중 리사이즈 드래그 축 (`null` = 비드래그) */
  resizingAxis?: 'width' | 'height' | 'both' | null;
  /**
   * 선택 노드의 확장 출처 PK. `__source.kind === 'extension'` 이면
   * 그 확장 PK, inject_props 호스트 노드면 첫 주입 확장 PK. 그 외 null. 편집기 디버깅 시
   * "이 노드가 어느 확장에서 왔는지" 식별.
   */
  sourceExtensionId?: number | null;
  timestamp: number;
}

export function trackEditorSelection(info: EditorSelectionInfo): void {
  const devtools = getDevtools();
  if (!devtools?.trackEditorSelection) return;
  try {
    devtools.trackEditorSelection(info);
  } catch {
    // devtools 적재 실패는 본 흐름에 영향 없음
  }
}

export function clearEditorSelectionData(): void {
  const devtools = getDevtools();
  if (!devtools?.clearEditorSelectionData) return;
  try {
    devtools.clearEditorSelectionData();
  } catch {
    // 정리 실패는 본 흐름에 영향 없음
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// editor-property-patch — 속성 편집 모달/리사이즈의 패치 분기 추적.
// 패치 대상 속성 키(메타)만 적재 — 값/내용물 적재 금지(누수 가드).
// ─────────────────────────────────────────────────────────────────────────────

export interface EditorPropertyPatchEntry {
  /** 패치 소스 — 스타일 컨트롤 / 설정 폼 / 고급 폼 / 모서리 리사이즈 */
  source: 'style_control' | 'composite_setting' | 'advanced' | 'resize';
  /** 대상 노드 componentPath */
  componentPath: string | null;
  /** 컴포넌트 이름 (메타 키만) */
  componentName: string | null;
  /** 패치된 컨트롤/속성 키 (`textAlign` / `width` 등 — 값 미적재) */
  patchKey: string | null;
  /** 리사이즈 소스일 때 축 */
  resizeAxis?: 'width' | 'height' | 'both';
  timestamp: number;
}

/**
 * 속성 패치 1건을 devtools 에 적재 (메타만). devtools 비활성 시 no-op.
 *
 * @param entry 패치 메타
 */
export function trackEditorPropertyPatch(entry: EditorPropertyPatchEntry): void {
  const devtools = getDevtools();
  if (!devtools?.trackEditorPropertyPatch) return;
  try {
    devtools.trackEditorPropertyPatch(entry);
  } catch {
    // devtools 적재 실패는 본 흐름에 영향 없음
  }
}

export function clearEditorPropertyPatchData(): void {
  const devtools = getDevtools();
  if (!devtools?.clearEditorPropertyPatchData) return;
  try {
    devtools.clearEditorPropertyPatchData();
  } catch {
    // 정리 실패는 본 흐름에 영향 없음
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// editor-dnd
// 드래그/+ 버튼 위치 계산/팔레트 삽입 시도 결과를 같은 카테고리로 추적.
// S5a-2 에서는 팔레트/+ 버튼 삽입 + computed style 결정만 적재.
// ─────────────────────────────────────────────────────────────────────────────

export type EditorDndDecision =
  | 'allowed'
  | 'denied_no_draggable'
  | 'denied_no_accepts'
  | 'denied_data_bound'
  | 'denied_base_locked'
  | 'denied_extension_locked'
  | 'denied_sortable'
  /** 디바이스 분기(responsive) 경계 — base↔분기/분기간 이동은 1차 거부 */
  | 'denied_responsive_branch_boundary';

export interface EditorDndEntry {
  source: 'palette' | 'context_plus_button' | 'duplicate' | 'drag';
  draggedComponentName: string | null;
  targetContainerName: string | null;
  targetContainerPath: string | null;
  /** 부모 computed display/flex-direction/flex-wrap — + 버튼 4방향 결정 근거 */
  parentDisplay?: string | null;
  parentFlexDirection?: string | null;
  parentFlexWrap?: string | null;
  decision: EditorDndDecision;
  insertionIndex?: number;
  result: 'completed' | 'cancelled' | 'denied';
  timestamp: number;
}

export function trackEditorDnd(entry: EditorDndEntry): void {
  const devtools = getDevtools();
  if (!devtools?.trackEditorDnd) return;
  try {
    devtools.trackEditorDnd(entry);
  } catch {
    // devtools 적재 실패는 본 흐름에 영향 없음
  }
}

export function clearEditorDndData(): void {
  const devtools = getDevtools();
  if (!devtools?.clearEditorDndData) return;
  try {
    devtools.clearEditorDndData();
  } catch {
    // 정리 실패는 본 흐름에 영향 없음
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// editor-document
// useLayoutDocument 의 load/patch/save/saveGuardResult 흐름 추적.
// 노드 메타·텍스트 값은 적재하지 않고 endpoint/상태/응답 코드만 보낸다.
// ─────────────────────────────────────────────────────────────────────────────

export interface EditorDocumentEntry {
  op: 'load' | 'patch' | 'save' | 'save_guard_result' | 'save_response';
  layoutName: string | null;
  editMode: 'route' | 'base' | 'modal' | 'extension' | 'iteration_item';
  saveTarget?: 'layout' | 'layout_extension' | 'host_layout_modal_patch';
  endpoint?: string;
  /** save_response 의 HTTP status code */
  statusCode?: number;
  /** save 시 dirty 여부 */
  isDirty?: boolean;
  /** 409 Conflict 시 lock_version 정보 */
  conflict?: { currentVersion?: number; yourVersion?: number };
  /** 활성 확장 재검증 가드 결과  */
  guardBlocked?: boolean;
  /** 가드 차단 시 영향받는 노드 path 목록 */
  guardBlockedPaths?: string[];
  timestamp: number;
}

export function trackEditorDocument(entry: EditorDocumentEntry): void {
  const devtools = getDevtools();
  if (!devtools?.trackEditorDocument) return;
  try {
    devtools.trackEditorDocument(entry);
  } catch {
    // devtools 적재 실패는 본 흐름에 영향 없음
  }
}

export function clearEditorDocumentData(): void {
  const devtools = getDevtools();
  if (!devtools?.clearEditorDocumentData) return;
  try {
    devtools.clearEditorDocumentData();
  } catch {
    // 정리 실패는 본 흐름에 영향 없음
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// editor-spec-merge
// 활성 확장 editor-spec.json 병합 결과 + sampleGlobal deep merge 체인의 코어
// 충돌(코어 우선 무시) 발화를 추적한다. 스펙 내용물(레시피 본문 등)은 적재하지
// 않고 소스 식별자/블록별 키 개수/충돌 경로만 보낸다 (메타 누수 회귀 가드).
// ─────────────────────────────────────────────────────────────────────────────

export interface EditorSpecMergeEntry {
  /** 편집 대상 템플릿 식별자 */
  templateIdentifier: string;
  /** 병합에 참여한 소스 — 모듈/플러그인/템플릿 식별자 목록 (순서 = 우선순위) */
  mergedSources: Array<{ kind: 'template' | 'module' | 'plugin'; id: string }>;
  /** 병합 후 블록별 항목 수 (키 개수만 — 내용물 미적재) */
  blockCounts: {
    controls: number;
    componentCapabilities: number;
    actionRecipes: number;
    conditionRecipes: number;
    paletteGroups: number;
    stateGroups: number;
    sampleDataIds: number;
  };
  /** sampleGlobal 소스 수 (코어 시드 제외 — 확장/템플릿 소스만) */
  sampleGlobalSourceCount: number;
  /** sampleGlobal 코어 충돌(코어 우선으로 무시된) 경로 — `<id>:<path>` 형식 */
  sampleGlobalConflicts: string[];
  timestamp: number;
}

/**
 * editor-spec 병합 + sampleGlobal 체인 결과를 devtools `editor-spec-merge` 에 적재.
 *
 * 스펙 로더 완성 시점(loadEditorSpecBundle 직후 + sampleGlobal 체인 빌드 직후)에
 * 호출된다. devtools 비활성 환경에서는 no-op.
 *
 * @since engine-v1.50.0
 */
export function trackEditorSpecMerge(entry: EditorSpecMergeEntry): void {
  const devtools = getDevtools();
  if (!devtools?.trackEditorSpecMerge) return;
  try {
    devtools.trackEditorSpecMerge(entry);
  } catch {
    // devtools 적재 실패는 본 흐름에 영향 없음
  }
}

/**
 * `LayoutEditorChrome` 언마운트 시 호출 — `editor-spec-merge` 누적 기록 정리.
 *
 * @since engine-v1.50.0
 */
export function clearEditorSpecMergeData(): void {
  const devtools = getDevtools();
  if (!devtools?.clearEditorSpecMergeData) return;
  try {
    devtools.clearEditorSpecMergeData();
  } catch {
    // 정리 실패는 본 흐름에 영향 없음
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// editor-i18n
// 콘텐츠 로케일 전환 + 인라인 텍스트 편집(평문→커스텀 키 생성 / 기존 키 값 수정) +
// 번역 탭 일괄 편집 + MergeCustomTranslations 적용 결과를 추적한다.
//
// 민감 정보 차단: 사용자가 타이핑한 평문은 트래커에 전문 저장 금지 —
// 길이(`valueLength`)와 비암호 해시(`valueHash`)만 적재한다(원본은 `_local.inlineEditing`
// 에 있어 g7-state 로 이미 노출되므로 중복 보존 불필요). 다국어 키/로케일/소스 상태
// 같은 비민감 메타만 평문 저장.
// ─────────────────────────────────────────────────────────────────────────────

export type EditorI18nOp =
  | 'locale_switch'
  | 'inline_edit_enter'
  | 'inline_edit_create_key'
  | 'inline_edit_update_value'
  | 'inline_edit_cancel'
  | 'inline_edit_blocked_binding'
  | 'translation_field_update'
  | 'custom_keys_merged'
  // e2e:allow 부록7 7-a — 트래커 op 추가만(브라우저 검증은 7-b Chrome MCP/E2E 세션).
  // 부록7 7-a — 속성 패널 텍스트 propControl/label_key 동적 다국어(인라인 편집과 동일 모델).
  | 'prop_i18n_create_key'
  | 'prop_i18n_update_value'
  | 'prop_i18n_field_update';

/**
 * 인라인 편집 진입 시 노드 텍스트 소스 분류.
 *  - `plain_text`: 순수 평문(보간 0).
 *  - `custom_key`: 단일 `$t:custom.*` 키 텍스트.
 *  - `binding_expression`: 편집할 평문/라벨이 전혀 없는 보간 전용 text(`{{x}}` 단독) — 인라인 미대상.
 *  - `plain_with_binding`: 평문(또는 lang키 라벨) + `{{...}}` 보간 혼합 (D-44 "공통
 *    문구 + 데이터") — 평문/라벨만 인라인 편집(commit 이 이 화면 전용 커스텀 키로 키화), 보간 토큰 보존.
 */
export type EditorI18nSourceState =
  | 'plain_text'
  | 'custom_key'
  | 'binding_expression'
  | 'plain_with_binding';

export interface EditorI18nEntry {
  op: EditorI18nOp;
  /** 콘텐츠 로케일 전환 시 이전/다음 로케일 */
  fromLocale?: string;
  toLocale?: string;
  /** 인라인 편집 대상 노드의 텍스트 소스 분류 */
  sourceState?: EditorI18nSourceState;
  /** 대상 노드 componentPath (메타 키만) */
  componentPath?: string | null;
  /** 생성/수정된 다국어 키 (`custom.{layout}.{seq}`) — 비민감 식별자 */
  translationKey?: string | null;
  /** 입력 평문 길이 (전문 미적재) */
  valueLength?: number;
  /** 입력 평문 비암호 해시 (전문 미적재) */
  valueHash?: number;
  /** 번역 탭 일괄 편집 시 변경된 로케일 목록 */
  changedLocales?: string[];
  /** MergeCustomTranslations 가 응답 트리에 병합한 커스텀 키 개수 (custom_keys_merged op) */
  mergedKeyCount?: number;
  timestamp: number;
}

/**
 * 평문의 비암호 해시 — 트래커가 전문 대신 보관할 식별값. djb2 변형(비보안, 충돌 허용).
 * 민감 정보를 트래커에 싣지 않으면서 "같은 값인지" 정도만 식별 가능하게 한다.
 *
 * @param value 해시할 문자열
 * @return 32-bit 부호 없는 해시
 */
export function hashInlineText(value: string): number {
  let hash = 5381;
  for (let i = 0; i < value.length; i++) {
    hash = ((hash << 5) + hash + value.charCodeAt(i)) >>> 0;
  }
  return hash;
}

/**
 * editor-i18n 이벤트 1건을 devtools 에 적재. devtools 비활성 시 no-op.
 * 본 트래커가 실패해도 편집기 본체 동작은 영향 없음 (degrade safety).
 *
 * @param entry i18n 이벤트 메타 (평문은 length/hash 만)
 *
 * @since engine-v1.50.0
 */
export function trackEditorI18n(entry: EditorI18nEntry): void {
  const devtools = getDevtools();
  if (!devtools?.trackEditorI18n) return;
  try {
    devtools.trackEditorI18n(entry);
  } catch {
    // devtools 적재 실패는 본 흐름에 영향 없음
  }
}

/**
 * `LayoutEditorChrome` 언마운트 시 호출 — `editor-i18n` 누적 기록 정리.
 *
 * @since engine-v1.50.0
 */
export function clearEditorI18nData(): void {
  const devtools = getDevtools();
  if (!devtools?.clearEditorI18nData) return;
  try {
    devtools.clearEditorI18nData();
  } catch {
    // 정리 실패는 본 흐름에 영향 없음
  }
}

/**
 * `editor-state` 카테고리의 페이지 상태 토글 이벤트.
 *
 * `change` — PageStateSwitcher 토글로 활성 상태 변경. `applyPatch` — 캔버스가
 * 활성 상태의 initialState/formErrors/sampleOverride 를 시뮬레이션 적용. 평문 메시지는
 * 싣지 않고 유무 플래그만 적재.
 */
export interface PageStateEntry {
  kind: 'change' | 'applyPatch';
  activeStateId: string | null;
  routePath: string | null;
  /** initialState(local/global) 패치 보유 여부 */
  hasInitialPatch?: boolean;
  /** formErrors 시뮬레이션 보유 여부 */
  hasFormErrors?: boolean;
  /** sampleDataOverrides 보유 여부 */
  hasSampleOverride?: boolean;
  /** change 이벤트 — 매칭된 상태 후보 개수 */
  availableCount?: number;
}

/**
 * 페이지 상태 토글 이벤트 1건을 devtools `editor-state` 카테고리에 적재. devtools
 * 비활성 시 no-op. 본 트래커 실패는 편집기 본체 동작에 영향 없음.
 *
 * @param entry 페이지 상태 이벤트 메타
 *
 * @since engine-v1.50.0
 */
export function trackPageState(entry: PageStateEntry): void {
  const devtools = getDevtools();
  if (!devtools?.trackPageState) return;
  try {
    devtools.trackPageState(entry);
  } catch {
    // devtools 적재 실패는 본 흐름에 영향 없음
  }
}

/**
 * `LayoutEditorChrome` 언마운트 시 호출 — 페이지 상태 누적 기록 정리.
 *
 * @since engine-v1.50.0
 */
export function clearPageStateData(): void {
  const devtools = getDevtools();
  if (!devtools?.clearPageStateData) return;
  try {
    devtools.clearPageStateData();
  } catch {
    // 정리 실패는 본 흐름에 영향 없음
  }
}
