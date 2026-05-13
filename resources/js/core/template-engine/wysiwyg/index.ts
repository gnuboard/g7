/**
 * index.ts
 *
 * G7 위지윅 레이아웃 편집기 모듈 진입점
 *
 * 역할:
 * - 편집기 모듈의 공개 API 정의
 * - 컴포넌트, 훅, 유틸리티 export
 * - 편집 모드 초기화 함수 제공
 */

// ============================================================================
// 메인 컴포넌트
// ============================================================================

export { WysiwygEditor, type WysiwygEditorProps } from './WysiwygEditor';
export { EditorProvider, useEditorContext, type EditorContextValue } from './EditorContext';

// ============================================================================
// UI 컴포넌트
// ============================================================================

export { Toolbar, KeyboardShortcutsHelp, type ToolbarProps } from './components/Toolbar';
export { LayoutTree, useTreeExpansion, type LayoutTreeProps } from './components/LayoutTree';
export { PreviewCanvas, type PreviewCanvasProps } from './components/PreviewCanvas';
export { PropertyPanel, type PropertyPanelProps } from './components/PropertyPanel';
export { PropsEditor, type PropsEditorProps } from './components/PropertyPanel/PropsEditor';
export { EditorOverlay, type EditorOverlayProps } from './components/EditorOverlay';

// ============================================================================
// 상태 관리 훅
// ============================================================================

export {
  useEditorState,
  selectCanUndo,
  selectCanRedo,
  selectSelectedComponent,
  type EditorState,
} from './hooks/useEditorState';

export {
  useHistory,
  type UseHistoryReturn,
} from './hooks/useHistory';

export {
  useComponentMetadata,
  type ComponentMetadataReturn,
} from './hooks/useComponentMetadata';

// ============================================================================
// 유틸리티
// ============================================================================

export {
  findComponentById,
  findParentComponent,
  findComponentByName,
  addComponentToLayout,
  removeComponentFromLayout,
  updateComponentInLayout,
  moveComponentInLayout,
  duplicateComponentInLayout,
  deepCloneComponent,
  generateUniqueId,
  flattenComponents,
  filterComponentsByType,
  filterComponentsByName,
  getPathToComponent,
  collectAllIds,
  isIdDuplicate,
  calculateLayoutStats,
  createTreeNodeInfo,
  createAllTreeNodeInfos,
  type LayoutStats,
} from './utils/layoutUtils';

export {
  validateLayout,
  formatValidationResult,
  quickValidate,
} from './utils/validationUtils';

// ============================================================================
// 타입 정의
// ============================================================================

export type {
  // 레이아웃 관련
  LayoutData,
  ComponentDefinition,
  DataSource,
  ModalDefinition,
  ActionDefinition,
  LifecycleHooks,
  IterationConfig,
  ResponsiveOverride,

  // 편집기 관련
  EditMode,
  PreviewDevice,
  DropPosition,
  EditorLoadingState,
  ComponentMetadata,
  PropSchema,
  PropType,
  HandlerSchema,

  // 히스토리 관련
  HistoryEntry,

  // 확장 관련
  ExtensionSource,
  InjectedComponent,
  ExtensionPointInfo,
} from './types/editor';

// ============================================================================
// 편집 모드 초기화 유틸리티
// ============================================================================

/**
 * URL 쿼리 파라미터에서 편집 모드 여부를 확인합니다.
 *
 * @returns boolean 편집 모드 여부
 */
export function isEditMode(): boolean {
  if (typeof window === 'undefined') {
    return false;
  }

  const params = new URLSearchParams(window.location.search);
  return params.get('mode') === 'edit';
}

/**
 * 편집 모드 URL을 생성합니다.
 * 라우트 경로 기반으로 레이아웃이 자동 인식됩니다.
 *
 * @param route 라우트 경로 (예: '/', '/popular', '/shop/products')
 * @param templateId 템플릿 ID
 * @returns string 편집 모드 URL
 */
export function getEditModeUrl(route: string, templateId: string): string {
  const baseUrl = window.location.origin;
  return `${baseUrl}${route}?mode=edit&template=${encodeURIComponent(templateId)}`;
}

/**
 * 편집 모드를 종료하고 일반 페이지로 이동합니다.
 * mode와 template 파라미터를 제거하고 현재 라우트에 머무릅니다.
 */
export function exitEditMode(): void {
  if (typeof window === 'undefined') {
    return;
  }

  const params = new URLSearchParams(window.location.search);
  params.delete('mode');
  params.delete('template');

  const newUrl = `${window.location.pathname}${params.toString() ? '?' + params.toString() : ''}`;
  window.location.href = newUrl;
}

/**
 * 편집 모드로 진입합니다.
 * 라우트 경로 기반으로 레이아웃이 자동 인식됩니다.
 *
 * @param route 라우트 경로 (예: '/', '/popular', '/shop/products')
 * @param templateId 템플릿 ID
 */
export function enterEditMode(route: string, templateId: string): void {
  if (typeof window === 'undefined') {
    return;
  }

  window.location.href = getEditModeUrl(route, templateId);
}

// ============================================================================
// 버전 정보
// ============================================================================

export const WYSIWYG_VERSION = '1.0.0';
export const WYSIWYG_PHASE = 1;

// ============================================================================
// 기본 export
// ============================================================================

export { WysiwygEditor as default } from './WysiwygEditor';
