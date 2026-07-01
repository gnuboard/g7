/**
 * registerCoreEditors.ts — 코어 빌트인 노드 에디터/캔버스 오버레이 등록
 *
 * `registerCoreWidgets` 와 평행한 부팅 1회 등록 진입점. 코어 빌트인 구조 에디터를
 * nodeEditorRegistry / canvasOverlayRegistry 에 **일반 API 로** 등록한다 — 코어가
 * 자기 빌트인을 특권 없이 템플릿 등록분과 동일 경로로 올린다(부록4-ter 원칙:
 * PropertyEditorModal/EditorCanvasOverlay 에 `if(kind==='table')` 류 분기 0).
 *
 * 단계 0 은 레지스트리/디스패치 인프라만 도입하고 빌트인 구현은 아직 없다:
 *  - `children` 노드 에디터(ChildrenListControl) — 단계 2
 *  - `table` 노드 에디터(TableEditor) — 단계 3-a
 *  - `table` 캔버스 오버레이(TableInplaceOverlay) — 단계 3-b
 *  - `array` 노드 에디터(ArrayItemsEditor) — 단계 4-a
 * 그 단계들이 본 함수에 `registerNodeEditor('children', ...)` 등 한 줄씩 추가한다.
 * 따라서 단계 0 에서 본 함수는 멱등 가드만 갖춘 no-op 이며, 빌트인 0 개를 등록한다
 * (kind 미등록 시 안전 디그레이드 — 회귀 0).
 *
 * @since engine-v1.50.0
 */

import { registerNodeEditor, getRegisteredNodeEditorKinds } from './nodeEditorRegistry';
import { registerCanvasOverlay, getRegisteredCanvasOverlayKinds } from './canvasOverlayRegistry';
import { ChildrenListControl } from '../components/property-controls/ChildrenListControl';
import { TableEditor } from '../components/property-controls/TableEditor';
import { ArrayItemsEditor } from '../components/property-controls/ArrayItemsEditor';
import { ArrayGroupEditor } from '../components/property-controls/ArrayGroupEditor';
import { ArrayCellTreeEditor } from '../components/property-controls/ArrayCellTreeEditor';
import { TableInplaceOverlay } from '../components/inplace/TableInplaceOverlay';

let registered = false;

/**
 * 코어 빌트인 노드 에디터/캔버스 오버레이를 1회 등록. 중복 호출은 no-op.
 *
 * 단계 2/3 가 본 함수 본문에 빌트인 등록 호출을 추가한다:
 * ```ts
 * registerNodeEditor('children', ChildrenListControl); // 단계 2
 * registerNodeEditor('table', TableEditor); // 단계 3-a
 * registerCanvasOverlay('table', TableInplaceOverlay); // 단계 3-b
 * ```
 */
export function registerCoreEditors(): void {
  if (registered) return;
  // children 노드 에디터(Ul/Ol/Nav/Form/Li 자식 추가/삭제/정렬 + 항목 다국어).
  registerNodeEditor('children', ChildrenListControl);
  // table 노드 에디터(행/열 추가·삭제·이동, 셀 병합/해제, 셀 테두리, 셀 텍스트 다국어).
  registerNodeEditor('table', TableEditor);
  // array 노드 에디터(props 배열 — tabs/items/columns 등 정적 항목 추가/삭제/정렬/
  // 필드편집 + i18n-text 항목 커스텀 키). 항목 스키마는 capability nodeEditor.params 가 공급.
  registerNodeEditor('array', ArrayItemsEditor);
  // array-group 노드 에디터(여러 배열 prop 동시 정적 편집 — BarChart labels+datasets).
  // ArrayItemsEditor 를 그룹마다 재사용하는 얇은 합성 래퍼(새 편집 의미 0).
  registerNodeEditor('array-group', ArrayGroupEditor);
  // array-cell-tree 노드 에디터(prop 안 중첩 노드트리 배열 — CardGrid cardColumns).
  // 컬럼 층(추가/삭제/정렬+id) + 셀 트리 층(ChildrenListControl 재사용). 새 편집 의미 0.
  registerNodeEditor('array-cell-tree', ArrayCellTreeEditor);
  // table 캔버스 인플레이스 오버레이(셀 단위 핸들 — 속성 패널 TableEditor 와
  // 동일 tableGridMutations 패치 경로 SSoT 공유). 코어가 자기 빌트인을 일반 API 로 등록.
  registerCanvasOverlay('table', TableInplaceOverlay);
  registered = true;
}

/** 등록 상태 리셋 (테스트 격리용) */
export function resetCoreEditorRegistration(): void {
  registered = false;
}

/**
 * 등록 여부 확인 (테스트/진단용). 단계 0 은 빌트인 0 개라 `registered` 플래그만 본다
 * (빌트인이 추가되는 단계 2/3 부터는 kind 개수로도 검증 가능).
 *
 * @return 코어 에디터 등록 1회 호출 여부
 */
export function isCoreEditorsRegistered(): boolean {
  return registered;
}

/** 등록된 코어 빌트인 에디터 kind 목록 (진단/테스트용) */
export function getCoreEditorKinds(): { nodeEditors: string[]; canvasOverlays: string[] } {
  return {
    nodeEditors: getRegisteredNodeEditorKinds(),
    canvasOverlays: getRegisteredCanvasOverlayKinds(),
  };
}
