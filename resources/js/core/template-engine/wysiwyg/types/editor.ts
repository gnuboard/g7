/**
 * NOTE: @since versions (engine-v1.x.x) refer to internal template engine
 * development iterations, not G7 platform release versions.
 */
/**
 * editor.ts
 *
 * G7 위지윅 레이아웃 편집기의 타입 정의
 *
 * 역할:
 * - 편집기 상태 인터페이스 정의
 * - 편집기 관련 타입 정의
 * - 기존 템플릿 엔진 타입 확장 및 재export
 */

import type { ComponentType } from 'react';
import type { ComponentDefinition } from '../../DynamicRenderer';
import type { LayoutData, LayoutComponent, InitActionDefinition, LayoutScript } from '../../LayoutLoader';
import type { DataSource } from '../../DataSourceManager';
import type { ErrorHandlingMap } from '../../../types/ErrorHandling';
import type { ComponentMetadata, ComponentTypeEnum, ComponentManifest } from '../../ComponentRegistry';
import type { ActionDefinition, ActionType } from '../../ActionDispatcher';

// ============================================================================
// 기존 타입 재export (편의를 위해)
// ============================================================================

export type {
  ComponentDefinition,
  LayoutData,
  LayoutComponent,
  DataSource,
  InitActionDefinition,
  LayoutScript,
  ErrorHandlingMap,
  ComponentMetadata,
  ComponentTypeEnum,
  ComponentManifest,
  ActionDefinition,
  ActionType,
};

// ============================================================================
// 편집기 모드 및 상태 타입
// ============================================================================

/**
 * 편집 모드 타입
 */
export type EditMode = 'visual' | 'json' | 'split';

/**
 * 미리보기 디바이스 타입
 */
export type PreviewDevice = 'desktop' | 'tablet' | 'mobile' | 'custom';

/**
 * 로딩 상태 타입
 */
export type EditorLoadingState = 'idle' | 'loading' | 'loaded' | 'saving' | 'error';

/**
 * 드래그앤드롭 위치 타입
 */
export type DropPosition = 'before' | 'after' | 'inside' | 'first-child' | 'last-child';

/**
 * 레이아웃 버전 정보
 */
export interface LayoutVersion {
  /** 버전 ID */
  id: string;

  /** 버전 문자열 (예: 1.0.0, 1.0.1) */
  version: string;

  /** 생성 일시 */
  createdAt: Date;

  /** 생성자 */
  createdBy: string;

  /** 버전 설명 */
  description?: string;

  /** 레이아웃 데이터 스냅샷 */
  layoutData: LayoutData;

  /** 발행 여부 */
  isPublished: boolean;
}

// ============================================================================
// 컴포넌트 팔레트 타입
// ============================================================================

/**
 * 확장 컴포넌트 정보
 */
export interface ExtensionComponent {
  /** 컴포넌트 이름 */
  name: string;

  /** 확장 출처 (모듈/플러그인 식별자) */
  source: string;

  /** 확장 타입 */
  sourceType: 'module' | 'plugin' | 'template';

  /** 컴포넌트 타입 */
  type: ComponentTypeEnum;

  /** 컴포넌트 설명 */
  description?: string;

  /** 지원하는 Props 스키마 */
  propsSchema?: PropSchema[];
}

/**
 * Props 스키마 정의
 */
export interface PropSchema {
  /** 속성 이름 */
  name: string;

  /** 속성 타입 */
  type: 'text' | 'number' | 'boolean' | 'select' | 'color' | 'className' | 'expression' | 'object' | 'array' | 'icon';

  /** 속성 레이블 (표시용) */
  label: string;

  /** 속성 설명 */
  description?: string;

  /** 선택 옵션 (select 타입용) */
  options?: { label: string; value: any }[];

  /** 기본값 */
  defaultValue?: any;

  /** 필수 여부 */
  required?: boolean;

  /** 바인딩 허용 여부 */
  allowBinding?: boolean;
}

/**
 * 컴포넌트 카테고리 분류
 */
export interface ComponentCategories {
  /** 기본 컴포넌트 */
  basic: ComponentMetadata[];

  /** 집합 컴포넌트 */
  composite: ComponentMetadata[];

  /** 레이아웃 컴포넌트 */
  layout: ComponentMetadata[];

  /** 확장 컴포넌트 (모듈/플러그인) */
  extension: ExtensionComponent[];
}

// ============================================================================
// 주입된 컴포넌트 (Overlay/Extension Point)
// ============================================================================

/**
 * 주입된 컴포넌트 정보
 */
export interface InjectedComponent {
  /** 컴포넌트 정의 */
  component: ComponentDefinition;

  /** 주입 출처 정보 */
  source: {
    /** 출처 타입 */
    type: 'module' | 'plugin' | 'template';

    /** 출처 식별자 */
    identifier: string;

    /** Overlay 파일 경로 (디버깅용) */
    overlayFile?: string;
  };

  /** 편집 가능 여부 (PO 결정: 항상 true) */
  isEditable: boolean;

  /** 주입 위치 */
  position?: 'prepend' | 'append' | 'prepend_child' | 'append_child' | 'replace';

  /** 주입 대상 ID */
  targetId?: string;

  /** 우선순위 */
  priority?: number;
}

/**
 * Overlay 정의
 */
export interface OverlayDefinition {
  /** 대상 레이아웃 */
  targetLayout: string;

  /** 주입 목록 */
  injections: {
    /** 주입 대상 컴포넌트 ID */
    targetId: string;

    /** 주입 위치 */
    position: 'prepend' | 'append' | 'prepend_child' | 'append_child' | 'replace';

    /** 주입할 컴포넌트 목록 */
    components: ComponentDefinition[];

    /** 출처 */
    source: string;

    /** 우선순위 */
    priority: number;

    /** 편집 가능 여부 */
    isEditable: boolean;
  }[];
}

/**
 * Extension Point 정의
 */
export interface ExtensionPointDefinition {
  /** 확장 포인트 이름 */
  name: string;

  /** 등록된 확장 목록 */
  registeredExtensions: {
    /** 출처 */
    source: string;

    /** 우선순위 */
    priority: number;

    /** 주입할 컴포넌트 목록 */
    components: ComponentDefinition[];
  }[];

  /** 기본 컴포넌트 (확장 없을 때) */
  defaultComponents: ComponentDefinition[];
}

// ============================================================================
// 레이아웃 상속 정보
// ============================================================================

/**
 * 상속 정보
 */
export interface InheritanceInfo {
  /** 부모 레이아웃명 */
  parentLayout: string;

  /** 부모에서 온 슬롯 목록 */
  inheritedSlots: string[];

  /** 현재 레이아웃에서 오버라이드 가능한 슬롯 */
  overridableSlots: string[];

  /** 상속 깊이 */
  depth: number;
}

// ============================================================================
// 히스토리 (Undo/Redo)
// ============================================================================

/**
 * 히스토리 항목
 */
export interface HistoryEntry {
  /** 레이아웃 데이터 스냅샷 */
  layoutData: LayoutData;

  /** 변경 설명 */
  description: string;

  /** 타임스탬프 */
  timestamp: number;
}

// ============================================================================
// 속성 패널 타입
// ============================================================================

/**
 * 속성 패널 탭 타입
 */
export type PropertyPanelTab = 'props' | 'bindings' | 'actions' | 'conditions' | 'responsive' | 'advanced';

/**
 * 조건부 렌더링 설정
 */
export interface ConditionConfig {
  /** if 조건 활성화 */
  enabled: boolean;

  /** 조건 표현식 */
  expression: string;

  /** 시각적 빌더 설정 */
  builder?: {
    leftOperand: string;
    operator: '==' | '!=' | '>' | '<' | '>=' | '<=' | '&&' | '||' | 'includes' | 'startsWith' | 'endsWith';
    rightOperand: string;
  };
}

/**
 * 반복 렌더링 설정
 */
export interface IterationConfig {
  /** 반복 활성화 */
  enabled: boolean;

  /** 소스 표현식 */
  source: string;

  /** 아이템 변수명 */
  itemVar: string;

  /** 인덱스 변수명 */
  indexVar?: string;

  /** 미리보기: 예상 반복 횟수 */
  previewCount?: number;
}

/**
 * 반응형 오버라이드 설정
 */
export interface ResponsiveOverride {
  /** 브레이크포인트별 오버라이드 */
  [breakpoint: string]: {
    props?: Record<string, any>;
    children?: 'inherit' | ComponentDefinition[];
    text?: 'inherit' | string;
    if?: 'inherit' | string;
  };
}

// ============================================================================
// 데이터 바인딩 편집기 타입
// ============================================================================

/**
 * 바인딩 소스 정보
 */
export interface BindingSources {
  /** 데이터 소스 목록 */
  dataSources: DataSource[];

  /** 전역 상태 키 목록 */
  globalState: string[];

  /** 로컬 상태 키 목록 */
  localState: string[];

  /** 라우트 파라미터 목록 */
  routeParams: string[];

  /** 쿼리 파라미터 목록 */
  queryParams: string[];

  /** 정적 정의 */
  defines: Record<string, any>;

  /** 계산된 값 */
  computed: Record<string, string>;
}

/**
 * 표현식 빌더 설정
 */
export interface ExpressionBuilder {
  /** 빌더 모드 */
  mode: 'simple' | 'advanced';

  /** Simple 모드: 선택된 경로 */
  selectedPath?: string;

  /** Advanced 모드: 직접 입력 표현식 */
  expression?: string;

  /** 미리보기 값 */
  previewValue?: any;

  /** 미리보기 에러 */
  previewError?: string | null;
}

// ============================================================================
// 액션 편집기 타입
// ============================================================================

/**
 * 핸들러 파라미터 스키마
 */
export interface HandlerParamSchema {
  /** 파라미터 이름 */
  name: string;

  /** 파라미터 타입 */
  type: 'text' | 'number' | 'boolean' | 'select' | 'expression' | 'object' | 'array' | 'endpoint' | 'modal-id' | 'component-id' | 'datasource-id';

  /** 레이블 */
  label: string;

  /** 설명 */
  description?: string;

  /** 필수 여부 */
  required?: boolean;

  /** 선택 옵션 (select 타입용) */
  options?: { label: string; value: any }[];

  /** 기본값 */
  defaultValue?: any;
}

/**
 * 핸들러 정보
 */
export interface HandlerInfo {
  /** 핸들러 이름 */
  name: string;

  /** 카테고리 */
  category: 'built-in' | 'custom' | 'module';

  /** 출처 (모듈 핸들러인 경우) */
  source?: string;

  /** 설명 */
  description?: string;

  /** 파라미터 스키마 */
  params?: HandlerParamSchema[];
}

/**
 * 이벤트 타입 목록
 */
export type EditorEventType =
  | 'click'
  | 'change'
  | 'submit'
  | 'keydown'
  | 'keyup'
  | 'keypress'
  | 'focus'
  | 'blur'
  | 'mouseenter'
  | 'mouseleave'
  | 'input'
  | 'select'
  | 'scroll'
  | 'load'
  | 'error'
  | string;

// ============================================================================
// 데이터 소스 관리자 타입
// ============================================================================

/**
 * 데이터 소스 타입
 */
export type DataSourceType = 'api' | 'static' | 'route_params' | 'query_params' | 'websocket';

/**
 * 로딩 전략
 */
export type LoadingStrategy = 'blocking' | 'progressive' | 'background';

/**
 * 데이터 소스 테스트 결과
 */
export interface DataSourceTestResult {
  /** 성공 여부 */
  success: boolean;

  /** 응답 데이터 */
  data?: any;

  /** 에러 메시지 */
  error?: string;

  /** 응답 시간 (ms) */
  responseTime?: number;
}

// ============================================================================
// 모달 정의
// ============================================================================

/**
 * 모달 정의
 */
export interface ModalDefinition {
  /** 모달 ID */
  id: string;

  /** 모달 제목 */
  title?: string;

  /** 모달 크기 */
  size?: 'sm' | 'md' | 'lg' | 'xl' | 'full';

  /** 모달 컴포넌트 */
  components: ComponentDefinition[];

  /** 닫기 버튼 표시 */
  showCloseButton?: boolean;

  /** 배경 클릭으로 닫기 */
  closeOnBackdrop?: boolean;

  /** ESC 키로 닫기 */
  closeOnEsc?: boolean;
}

// ============================================================================
// 편집기 상태 인터페이스 (Zustand Store)
// ============================================================================

/**
 * 편집기 상태 인터페이스
 *
 * Zustand 스토어에서 관리하는 전체 편집기 상태
 */
export interface EditorState {
  // ========== 레이아웃 데이터 ==========

  /** 현재 편집 중인 레이아웃 데이터 */
  layoutData: LayoutData | null;

  /** 원본 레이아웃 데이터 (변경 감지용) */
  originalLayoutData: LayoutData | null;

  /** 템플릿 ID */
  templateId: string | null;

  /** 템플릿 타입 (admin 또는 user) */
  templateType: 'admin' | 'user' | null;

  /** 레이아웃 이름 */
  layoutName: string | null;

  // ========== 선택 상태 ==========

  /** 선택된 컴포넌트 ID */
  selectedComponentId: string | null;

  /** 호버된 컴포넌트 ID */
  hoveredComponentId: string | null;

  /** 다중 선택된 컴포넌트 ID 목록 */
  multiSelectedIds: string[];

  // ========== 편집 모드 ==========

  /** 편집 모드 */
  editMode: EditMode;

  /** 미리보기 디바이스 */
  previewDevice: PreviewDevice;

  /** 커스텀 너비 (custom 디바이스용) */
  customWidth: number;

  /** 다크 모드 미리보기 활성화 */
  previewDarkMode: boolean;

  // ========== 로딩 상태 ==========

  /** 로딩 상태 */
  loadingState: EditorLoadingState;

  /** 에러 메시지 */
  error: string | null;

  // ========== 히스토리 (Undo/Redo) ==========

  /** 히스토리 스택 */
  history: HistoryEntry[];

  /** 현재 히스토리 인덱스 */
  historyIndex: number;

  /** 최대 히스토리 크기 */
  maxHistorySize: number;

  // ========== 컴포넌트 레지스트리 ==========

  /** 컴포넌트 카테고리 (템플릿에서 로드) */
  componentCategories: ComponentCategories | null;

  /** 핸들러 목록 */
  handlers: HandlerInfo[];

  // ========== 데이터 컨텍스트 (미리보기용) ==========

  /** Mock 데이터 컨텍스트 */
  mockDataContext: Record<string, any>;

  // ========== 버전 관리 ==========

  /** 버전 목록 */
  versions: LayoutVersion[];

  /** 현재 버전 ID */
  currentVersionId: string | null;

  // ========== 상속 및 확장 ==========

  /** 상속 정보 (extends 사용 시) */
  inheritanceInfo: InheritanceInfo | null;

  /** Overlay 정의 목록 */
  overlays: OverlayDefinition[];

  /** Extension Point 정의 목록 */
  extensionPoints: ExtensionPointDefinition[];

  // ========== UI 상태 ==========

  /** 속성 패널 활성 탭 */
  activePropertyTab: PropertyPanelTab;

  /** 레이아웃 트리 확장된 노드 ID 목록 */
  expandedTreeNodes: string[];

  /** 클립보드 (복사된 컴포넌트) */
  clipboard: ComponentDefinition | null;

  /** 드래그 중인 컴포넌트 */
  draggingComponent: ComponentDefinition | ComponentMetadata | ExtensionComponent | null;

  /** 드래그 대상 ID */
  dropTargetId: string | null;

  /** 드래그 위치 */
  dropPosition: DropPosition | null;

  // ========== 변경 감지 ==========

  /** 변경 사항 존재 여부 */
  hasChanges: boolean;

  /** 마지막 저장 시간 */
  lastSavedAt: Date | null;
}

/**
 * 편집기 액션 인터페이스
 *
 * Zustand 스토어에서 사용하는 액션들
 */
export interface EditorActions {
  // ========== 레이아웃 관리 ==========

  /** 레이아웃 로드 */
  loadLayout: (templateId: string, templateType: 'admin' | 'user', layoutName: string) => Promise<void>;

  /** 레이아웃 저장 */
  saveLayout: (description?: string) => Promise<void>;

  /** 레이아웃 발행 */
  publishLayout: () => Promise<void>;

  /** 레이아웃 리셋 (원본으로) */
  resetLayout: () => void;

  /** 레이아웃 데이터 직접 설정 (외부에서 전달된 경우) */
  setLayoutData: (layoutData: LayoutData, templateId: string) => void;

  /** 레이아웃 메타데이터 업데이트 (permissions, version 등) */
  updateLayoutMeta: (updates: Partial<Pick<LayoutData, 'permissions' | 'version' | 'layout_name' | 'extends'>>) => void;

  // ========== 컴포넌트 조작 ==========

  /** 컴포넌트 선택 */
  selectComponent: (id: string | null) => void;

  /** 컴포넌트 호버 */
  hoverComponent: (id: string | null) => void;

  /** 컴포넌트 호버 ID 설정 (hoverComponent 별칭) */
  setHoveredComponentId: (id: string | null) => void;

  /** 컴포넌트 추가 */
  addComponent: (component: ComponentDefinition, targetId: string, position: DropPosition) => void;

  /** 컴포넌트 삭제 */
  deleteComponent: (id: string) => void;

  /** 컴포넌트 복제 */
  duplicateComponent: (id: string) => void;

  /** 컴포넌트 이동 */
  moveComponent: (sourceId: string, targetId: string, position: DropPosition) => void;

  /** 컴포넌트 속성 업데이트 */
  updateComponentProps: (id: string, props: Record<string, any>) => void;

  /** 컴포넌트 전체 업데이트 */
  updateComponent: (id: string, updates: Partial<ComponentDefinition>) => void;

  /**
   * 경로를 사용하여 컴포넌트에 ID가 없으면 자동 생성하고 반환
   * ID 없는 컴포넌트 편집 시 자동 ID 부여에 사용
   * @since engine-v1.13.0
   */
  ensureComponentId: (path: string) => string | null;

  /**
   * 경로 기반 컴포넌트에 ID를 부여하고 이동 (원자적 연산)
   * Auto ID 컴포넌트 드래그 앤 드롭에 사용
   * @param path 소스 컴포넌트 경로
   * @param targetId 타겟 컴포넌트 ID
   * @param position 드롭 위치
   * @returns 성공 여부
   * @since engine-v1.13.0
   */
  ensureComponentIdAndMove: (
    path: string,
    targetId: string,
    position: DropPosition
  ) => boolean;

  /**
   * 경로 기반으로 소스와 타겟 모두 처리하여 이동 (원자적 연산)
   * 소스와 타겟 모두 Auto ID인 경우 사용
   * @param sourcePath 소스 컴포넌트 경로
   * @param targetPath 타겟 컴포넌트 경로
   * @param position 드롭 위치
   * @returns 성공 여부
   * @since engine-v1.13.0
   */
  moveComponentByPaths: (
    sourcePath: string,
    targetPath: string,
    position: DropPosition
  ) => boolean;

  /**
   * 경로를 사용하여 컴포넌트 업데이트
   * ID가 없는 컴포넌트도 경로로 직접 업데이트 가능
   * @since engine-v1.13.0
   */
  updateComponentByPath: (path: string, updates: Partial<ComponentDefinition>) => void;

  // ========== 히스토리 ==========

  /** Undo */
  undo: () => void;

  /** Redo */
  redo: () => void;

  /** 히스토리에 스냅샷 추가 */
  pushHistory: (description: string) => void;

  // ========== 편집 모드 ==========

  /** 편집 모드 변경 */
  setEditMode: (mode: EditMode) => void;

  /** 미리보기 디바이스 변경 */
  setPreviewDevice: (device: PreviewDevice, customWidth?: number) => void;

  /** 다크 모드 미리보기 토글 */
  togglePreviewDarkMode: () => void;

  // ========== 버전 관리 ==========

  /** 버전 목록 로드 */
  loadVersions: () => Promise<void>;

  /** 특정 버전으로 복원 */
  restoreVersion: (versionId: string) => Promise<void>;

  /** 버전 비교 */
  compareVersions: (v1: string, v2: string) => Promise<VersionDiff[]>;

  // ========== 데이터 소스 ==========

  /** 데이터 소스 추가 */
  addDataSource: (dataSource: DataSource) => void;

  /** 데이터 소스 수정 */
  updateDataSource: (id: string, dataSource: Partial<DataSource>) => void;

  /** 데이터 소스 삭제 */
  deleteDataSource: (id: string) => void;

  /** 데이터 소스 테스트 */
  testDataSource: (id: string) => Promise<DataSourceTestResult>;

  /** Mock 데이터 설정 */
  setMockData: (dataSourceId: string, data: any) => void;

  // ========== 모달 ==========

  /** 모달 추가 */
  addModal: (modal: ModalDefinition) => void;

  /** 모달 수정 */
  updateModal: (id: string, modal: Partial<ModalDefinition>) => void;

  /** 모달 삭제 */
  deleteModal: (id: string) => void;

  // ========== UI 상태 ==========

  /** 속성 패널 탭 변경 */
  setActivePropertyTab: (tab: PropertyPanelTab) => void;

  /** 트리 노드 확장/축소 */
  toggleTreeNode: (id: string) => void;

  /** 클립보드에 복사 */
  copyToClipboard: (component: ComponentDefinition) => void;

  /** 클립보드에서 붙여넣기 */
  pasteFromClipboard: (targetId: string, position: DropPosition) => void;

  // ========== 드래그앤드롭 ==========

  /** 드래그 시작 */
  startDrag: (component: ComponentDefinition | ComponentMetadata | ExtensionComponent) => void;

  /** 드래그 오버 */
  dragOver: (targetId: string, position: DropPosition) => void;

  /** 드래그 종료 */
  endDrag: () => void;

  // ========== 확장 오버라이드 ==========

  /** 확장 컴포넌트 오버라이드 저장 */
  saveExtensionOverride: (extensionId: string, modifiedComponents: ComponentDefinition[]) => Promise<void>;

  /** 확장 오버라이드 복원 (원본으로) */
  restoreExtensionOverride: (extensionId: string) => Promise<void>;
}

/**
 * 버전 비교 결과
 */
export interface VersionDiff {
  /** 변경 타입 */
  type: 'added' | 'removed' | 'modified';

  /** 경로 */
  path: string;

  /** 이전 값 */
  oldValue?: any;

  /** 새 값 */
  newValue?: any;
}

// ============================================================================
// 컴포넌트 Props 인터페이스
// ============================================================================

/**
 * PreviewCanvas Props
 */
export interface PreviewCanvasProps {
  /** 레이아웃 데이터 */
  layoutData: LayoutData;

  /** 미리보기 디바이스 */
  device: PreviewDevice;

  /** 커스텀 너비 */
  customWidth?: number;

  /** 다크 모드 활성화 */
  darkMode?: boolean;

  /** 편집 오버레이 표시 */
  showOverlay: boolean;

  /** 선택된 컴포넌트 ID */
  selectedId: string | null;

  /** 호버된 컴포넌트 ID */
  hoveredId: string | null;

  /** 컴포넌트 선택 핸들러 */
  onComponentSelect: (id: string) => void;

  /** 컴포넌트 호버 핸들러 */
  onComponentHover: (id: string | null) => void;

  /** 컴포넌트 드롭 핸들러 */
  onComponentDrop: (targetId: string, position: DropPosition) => void;

  /** 컴포넌트 이동 핸들러 */
  onComponentMove: (sourceId: string, targetId: string, position: DropPosition) => void;
}

/**
 * LayoutTree Props
 */
export interface LayoutTreeProps {
  /** 컴포넌트 목록 */
  components: ComponentDefinition[];

  /** 선택된 컴포넌트 ID */
  selectedId: string | null;

  /** 확장된 노드 ID 목록 */
  expandedNodes: string[];

  /** 주입된 컴포넌트 맵 (ID -> 출처) */
  injectedComponents?: Map<string, InjectedComponent>;

  /** 컴포넌트 선택 핸들러 */
  onSelect: (id: string) => void;

  /** 노드 확장/축소 핸들러 */
  onToggle: (id: string) => void;

  /** 드래그앤드롭 정렬 핸들러 */
  onReorder: (dragId: string, dropId: string, position: DropPosition) => void;

  /** 컨텍스트 메뉴 핸들러 */
  onContextMenu: (id: string, action: 'copy' | 'paste' | 'delete' | 'duplicate') => void;
}

/**
 * LayoutTree 노드 정보
 */
export interface LayoutTreeNodeInfo {
  /** 컴포넌트 ID */
  id: string;

  /** 컴포넌트 이름 */
  name: string;

  /** 컴포넌트 타입 */
  type: 'basic' | 'composite' | 'layout' | 'extension_point';

  /** if 조건 존재 여부 */
  hasCondition: boolean;

  /** iteration 존재 여부 */
  hasIteration: boolean;

  /** 확장에서 주입된 컴포넌트인지 */
  isFromExtension: boolean;

  /** 확장 출처 (주입된 경우) */
  extensionSource?: string;

  /** 자식 컴포넌트 개수 */
  childCount: number;

  /** 깊이 레벨 */
  depth: number;
}

/**
 * PropertyPanel Props
 */
export interface PropertyPanelProps {
  /** 선택된 컴포넌트 */
  component: ComponentDefinition | null;

  /** 컴포넌트 메타데이터 */
  componentMetadata: ComponentMetadata | null;

  /** 활성 탭 */
  activeTab: PropertyPanelTab;

  /** 바인딩 소스 정보 */
  bindingSources: BindingSources;

  /** 핸들러 목록 */
  handlers: HandlerInfo[];

  /** 탭 변경 핸들러 */
  onTabChange: (tab: PropertyPanelTab) => void;

  /** 속성 변경 핸들러 */
  onChange: (updates: Partial<ComponentDefinition>) => void;
}

/**
 * EditorOverlay Props
 */
export interface EditorOverlayProps {
  /** 선택된 컴포넌트 ID */
  selectedId: string | null;

  /** 호버된 컴포넌트 ID */
  hoveredId: string | null;

  /** 드래그 대상 ID */
  dropTargetId: string | null;

  /** 드래그 위치 */
  dropPosition: DropPosition | null;

  /** 컴포넌트 선택 핸들러 */
  onSelect: (id: string) => void;

  /** 컴포넌트 호버 핸들러 */
  onHover: (id: string | null) => void;

  /** 드래그 오버 핸들러 */
  onDragOver: (id: string, position: DropPosition) => void;

  /** 드롭 핸들러 */
  onDrop: () => void;
}

/**
 * ComponentPalette Props
 */
export interface ComponentPaletteProps {
  /** 컴포넌트 카테고리 */
  categories: ComponentCategories;

  /** 검색 쿼리 */
  searchQuery: string;

  /** 검색 쿼리 변경 핸들러 */
  onSearchChange: (query: string) => void;

  /** 드래그 시작 핸들러 */
  onDragStart: (component: ComponentMetadata | ExtensionComponent) => void;
}

/**
 * Toolbar Props
 */
export interface ToolbarProps {
  /** 편집 모드 */
  editMode: EditMode;

  /** 미리보기 디바이스 */
  previewDevice: PreviewDevice;

  /** 다크 모드 활성화 */
  previewDarkMode: boolean;

  /** Undo 가능 여부 */
  canUndo: boolean;

  /** Redo 가능 여부 */
  canRedo: boolean;

  /** 변경 사항 존재 여부 */
  hasChanges: boolean;

  /** 로딩 상태 */
  loadingState: EditorLoadingState;

  /** 편집 모드 변경 핸들러 */
  onEditModeChange: (mode: EditMode) => void;

  /** 미리보기 디바이스 변경 핸들러 */
  onPreviewDeviceChange: (device: PreviewDevice) => void;

  /** 다크 모드 토글 핸들러 */
  onToggleDarkMode: () => void;

  /** Undo 핸들러 */
  onUndo: () => void;

  /** Redo 핸들러 */
  onRedo: () => void;

  /** 저장 핸들러 */
  onSave: () => void;

  /** 발행 핸들러 */
  onPublish: () => void;

  /** 버전 관리 핸들러 */
  onVersions: () => void;

  /** 닫기 핸들러 */
  onClose: () => void;
}

// ============================================================================
// 유틸리티 타입
// ============================================================================

/**
 * 컴포넌트 ID로 검색 결과
 */
export interface ComponentSearchResult {
  /** 컴포넌트 정의 */
  component: ComponentDefinition;

  /** 부모 컴포넌트 ID */
  parentId: string | null;

  /** 부모의 children 배열 내 인덱스 */
  index: number;

  /** 경로 (루트부터 현재까지 ID 배열) */
  path: string[];
}

/**
 * 레이아웃 유효성 검사 결과
 */
export interface ValidationResult {
  /** 유효 여부 */
  valid: boolean;

  /** 에러 목록 */
  errors: ValidationError[];

  /** 경고 목록 */
  warnings: ValidationWarning[];
}

/**
 * 유효성 검사 에러
 */
export interface ValidationError {
  /** 에러 코드 */
  code: string;

  /** 에러 메시지 */
  message: string;

  /** 에러 경로 (컴포넌트 ID 또는 필드 경로) */
  path: string;

  /** 심각도 */
  severity: 'error';
}

/**
 * 유효성 검사 경고
 */
export interface ValidationWarning {
  /** 경고 코드 */
  code: string;

  /** 경고 메시지 */
  message: string;

  /** 경고 경로 */
  path: string;

  /** 심각도 */
  severity: 'warning';
}

// ============================================================================
// 편집기 설정
// ============================================================================

/**
 * 편집기 설정
 */
export interface EditorConfig {
  /** 자동 저장 활성화 */
  autoSave: boolean;

  /** 자동 저장 간격 (ms) */
  autoSaveInterval: number;

  /** 최대 히스토리 크기 */
  maxHistorySize: number;

  /** 그리드 스냅 활성화 */
  snapToGrid: boolean;

  /** 그리드 크기 (px) */
  gridSize: number;

  /** 키보드 단축키 활성화 */
  keyboardShortcuts: boolean;

  /** 접근성 모드 */
  accessibilityMode: boolean;
}

/**
 * 기본 편집기 설정
 */
export const DEFAULT_EDITOR_CONFIG: EditorConfig = {
  autoSave: false,
  autoSaveInterval: 30000,
  maxHistorySize: 50,
  snapToGrid: false,
  gridSize: 8,
  keyboardShortcuts: true,
  accessibilityMode: false,
};

// ============================================================================
// 미리보기 디바이스 프리셋
// ============================================================================

/**
 * 디바이스 프리셋 정의
 */
export interface DevicePreset {
  /** 디바이스 이름 */
  name: string;

  /** 너비 */
  width: number;

  /** 높이 (선택적) */
  height?: number;

  /** 레이블 */
  label: string;
}

/**
 * 기본 디바이스 프리셋
 */
export const DEVICE_PRESETS: Record<PreviewDevice, DevicePreset> = {
  desktop: { name: 'desktop', width: 1920, label: 'Desktop' },
  tablet: { name: 'tablet', width: 768, label: 'Tablet' },
  mobile: { name: 'mobile', width: 375, label: 'Mobile' },
  custom: { name: 'custom', width: 1024, label: 'Custom' },
};

/**
 * 반응형 브레이크포인트 정의
 */
export const RESPONSIVE_BREAKPOINTS = {
  portable: { min: 0, max: 1023, label: 'Portable (Mobile + Tablet)' },
  desktop: { min: 1024, max: Infinity, label: 'Desktop' },
} as const;
