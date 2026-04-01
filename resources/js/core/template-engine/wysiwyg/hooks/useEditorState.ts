/**
 * useEditorState.ts
 *
 * 그누보드7 위지윅 레이아웃 편집기의 Zustand 상태 관리
 *
 * 역할:
 * - 편집기 전역 상태 관리
 * - 레이아웃 데이터 CRUD
 * - 히스토리 (Undo/Redo) 관리
 * - 컴포넌트 선택/조작
 */

import { create } from 'zustand';
import { immer } from 'zustand/middleware/immer';
import { devtools } from 'zustand/middleware';
import type {
  EditorState,
  EditorActions,
  EditMode,
  PreviewDevice,
  PropertyPanelTab,
  DropPosition,
  ComponentDefinition,
  LayoutData,
  DataSource,
  ModalDefinition,
  HistoryEntry,
  LayoutVersion,
  VersionDiff,
  DataSourceTestResult,
  ComponentMetadata,
  ExtensionComponent,
  ComponentCategories,
  InheritanceInfo,
  OverlayDefinition,
  ExtensionPointDefinition,
  HandlerInfo,
} from '../types/editor';
import { DEFAULT_EDITOR_CONFIG } from '../types/editor';
import {
  findComponentById,
  addComponentToLayout,
  removeComponentFromLayout,
  moveComponentInLayout,
  updateComponentInLayout,
  duplicateComponentInLayout,
  generateUniqueId,
  ensureComponentId as ensureComponentIdUtil,
  updateComponentByPath,
} from '../utils/layoutUtils';
import { createLogger } from '../../../utils/Logger';

const logger = createLogger('EditorState');

// ============================================================================
// 초기 상태
// ============================================================================

const initialState: EditorState = {
  // 레이아웃 데이터
  layoutData: null,
  originalLayoutData: null,
  templateId: null,
  templateType: null,
  layoutName: null,

  // 선택 상태
  selectedComponentId: null,
  hoveredComponentId: null,
  multiSelectedIds: [],

  // 편집 모드
  editMode: 'visual',
  previewDevice: 'desktop',
  customWidth: 1024,
  previewDarkMode: false,

  // 로딩 상태
  loadingState: 'idle',
  error: null,

  // 히스토리
  history: [],
  historyIndex: -1,
  maxHistorySize: DEFAULT_EDITOR_CONFIG.maxHistorySize,

  // 컴포넌트 레지스트리
  componentCategories: null,
  handlers: [],

  // 데이터 컨텍스트
  mockDataContext: {},

  // 버전 관리
  versions: [],
  currentVersionId: null,

  // 상속 및 확장
  inheritanceInfo: null,
  overlays: [],
  extensionPoints: [],

  // UI 상태
  activePropertyTab: 'props',
  expandedTreeNodes: [],
  clipboard: null,
  draggingComponent: null,
  dropTargetId: null,
  dropPosition: null,

  // 변경 감지
  hasChanges: false,
  lastSavedAt: null,
};

// ============================================================================
// Store 타입 정의
// ============================================================================

type EditorStore = EditorState & EditorActions;

// ============================================================================
// API 호출 함수들
// ============================================================================

/**
 * 레이아웃 데이터 로드 API
 */
async function fetchLayout(
  templateId: string,
  templateType: 'admin' | 'user',
  layoutName: string
): Promise<LayoutData> {
  const response = await fetch(
    `/api/admin/templates/${templateId}/layouts/${layoutName}`
  );

  if (!response.ok) {
    throw new Error(`Failed to fetch layout: ${response.status} ${response.statusText}`);
  }

  const result = await response.json();
  return result.data;
}

/**
 * 레이아웃 저장 API
 */
async function saveLayoutApi(
  templateId: string,
  layoutName: string,
  layoutData: LayoutData,
  description?: string
): Promise<void> {
  const response = await fetch(
    `/api/admin/templates/${templateId}/layouts/${layoutName}`,
    {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        layout_data: layoutData,
        description,
      }),
    }
  );

  if (!response.ok) {
    throw new Error(`Failed to save layout: ${response.status} ${response.statusText}`);
  }
}

/**
 * 레이아웃 버전 목록 로드 API
 */
async function fetchVersions(
  templateId: string,
  layoutName: string
): Promise<LayoutVersion[]> {
  const response = await fetch(
    `/api/admin/templates/${templateId}/layouts/${layoutName}/versions`
  );

  if (!response.ok) {
    throw new Error(`Failed to fetch versions: ${response.status} ${response.statusText}`);
  }

  const result = await response.json();
  return result.data.map((v: any) => ({
    ...v,
    createdAt: new Date(v.created_at),
  }));
}

/**
 * 버전 복원 API
 */
async function restoreVersionApi(
  templateId: string,
  layoutName: string,
  versionId: string
): Promise<LayoutData> {
  const response = await fetch(
    `/api/admin/templates/${templateId}/layouts/${layoutName}/versions/${versionId}/restore`,
    {
      method: 'POST',
      headers: {
        Accept: 'application/json',
      },
    }
  );

  if (!response.ok) {
    throw new Error(`Failed to restore version: ${response.status} ${response.statusText}`);
  }

  const result = await response.json();
  return result.data;
}

/**
 * 컴포넌트 메타데이터 로드 API
 */
async function fetchComponentMetadata(templateId: string): Promise<ComponentCategories> {
  const response = await fetch(`/api/templates/${templateId}/components.json`);

  if (!response.ok) {
    throw new Error(`Failed to fetch component metadata: ${response.status}`);
  }

  const manifest = await response.json();

  return {
    basic: manifest.components.basic || [],
    composite: manifest.components.composite || [],
    layout: manifest.components.layout || [],
    extension: [], // 확장 컴포넌트는 별도 로드
  };
}

// ============================================================================
// Zustand Store 생성
// ============================================================================

export const useEditorState = create<EditorStore>()(
  devtools(
    immer((set, get) => ({
      ...initialState,

      // ========== 레이아웃 관리 ==========

      loadLayout: async (templateId, templateType, layoutName) => {
        set((state) => {
          state.loadingState = 'loading';
          state.error = null;
        });

        try {
          // 레이아웃 데이터 로드
          const layoutData = await fetchLayout(templateId, templateType, layoutName);

          // 컴포넌트 메타데이터 로드
          const componentCategories = await fetchComponentMetadata(templateId);

          set((state) => {
            state.layoutData = layoutData;
            state.originalLayoutData = JSON.parse(JSON.stringify(layoutData));
            state.templateId = templateId;
            state.templateType = templateType;
            state.layoutName = layoutName;
            state.componentCategories = componentCategories;
            state.loadingState = 'loaded';
            state.hasChanges = false;

            // 상속 정보 처리
            if (layoutData.extends) {
              state.inheritanceInfo = {
                parentLayout: layoutData.extends as string,
                inheritedSlots: [],
                overridableSlots: Object.keys(layoutData.slots || {}),
                depth: 1,
              };
            }

            // 히스토리 초기화
            state.history = [];
            state.historyIndex = -1;
          });

          logger.log('Layout loaded:', layoutName);
        } catch (error) {
          const errorMessage = error instanceof Error ? error.message : 'Unknown error';
          set((state) => {
            state.loadingState = 'error';
            state.error = errorMessage;
          });
          logger.error('Failed to load layout:', errorMessage);
          throw error;
        }
      },

      saveLayout: async (description) => {
        const { templateId, layoutName, layoutData } = get();

        if (!templateId || !layoutName || !layoutData) {
          throw new Error('No layout loaded');
        }

        set((state) => {
          state.loadingState = 'saving';
        });

        try {
          await saveLayoutApi(templateId, layoutName, layoutData, description);

          set((state) => {
            state.originalLayoutData = JSON.parse(JSON.stringify(layoutData));
            state.hasChanges = false;
            state.lastSavedAt = new Date();
            state.loadingState = 'loaded';
          });

          logger.log('Layout saved:', layoutName);
        } catch (error) {
          const errorMessage = error instanceof Error ? error.message : 'Unknown error';
          set((state) => {
            state.loadingState = 'error';
            state.error = errorMessage;
          });
          logger.error('Failed to save layout:', errorMessage);
          throw error;
        }
      },

      publishLayout: async () => {
        // 발행은 저장 후 캐시 리프레시 추가
        await get().saveLayout('Published');

        // 캐시 리프레시 API 호출
        try {
          await fetch('/api/admin/cache/refresh', { method: 'POST' });
          logger.log('Cache refreshed after publish');
        } catch (error) {
          logger.warn('Cache refresh failed:', error);
        }
      },

      resetLayout: () => {
        const { originalLayoutData } = get();
        if (originalLayoutData) {
          set((state) => {
            state.layoutData = JSON.parse(JSON.stringify(originalLayoutData));
            state.hasChanges = false;
            state.selectedComponentId = null;
            state.hoveredComponentId = null;
          });
        }
      },

      setLayoutData: (layoutData, templateId) => {
        logger.log('Setting layout data directly:', {
          layoutName: layoutData.layout_name,
          componentsCount: layoutData.components?.length || 0,
          templateId,
        });

        set((state) => {
          state.layoutData = layoutData;
          state.originalLayoutData = JSON.parse(JSON.stringify(layoutData));
          state.templateId = templateId;
          state.loadingState = 'loaded';
          state.hasChanges = false;

          // 상속 정보 처리
          if (layoutData.extends) {
            state.inheritanceInfo = {
              parentLayout: layoutData.extends as string,
              inheritedSlots: [],
              overridableSlots: Object.keys(layoutData.slots || {}),
              depth: 1,
            };
          }

          // 히스토리 초기화
          state.history = [];
          state.historyIndex = -1;
        });
      },

      /**
       * 레이아웃 메타데이터 업데이트 (permissions, version 등)
       * @param updates 업데이트할 메타데이터 필드
       */
      updateLayoutMeta: (updates: Partial<Pick<LayoutData, 'permissions' | 'version' | 'layout_name' | 'extends'>>) => {
        const { layoutData } = get();
        if (!layoutData) return;

        logger.log('Updating layout meta:', updates);

        set((state) => {
          if (!state.layoutData) return;

          // 각 필드 업데이트
          if (updates.permissions !== undefined) {
            state.layoutData.permissions = updates.permissions;
          }
          if (updates.version !== undefined) {
            state.layoutData.version = updates.version;
          }
          if (updates.layout_name !== undefined) {
            state.layoutData.layout_name = updates.layout_name;
          }
          if (updates.extends !== undefined) {
            state.layoutData.extends = updates.extends;
          }

          state.hasChanges = true;
        });
      },

      // ========== 컴포넌트 조작 ==========

      selectComponent: (id) => {
        set((state) => {
          let effectiveId = id;

          // auto_path: 형식인 경우 영구 ID로 정규화
          // 정책: hasChanges에 반영하지 않음, 히스토리에 기록하지 않음
          if (id?.startsWith('auto_path:') && state.layoutData) {
            const path = id.substring('auto_path:'.length);
            const result = ensureComponentIdUtil(
              state.layoutData.components as (ComponentDefinition | string)[],
              path
            );

            if (result) {
              // 컴포넌트 배열이 수정된 경우에만 업데이트 (ID가 새로 생성된 경우)
              if (result.components !== state.layoutData.components) {
                state.layoutData.components = result.components;
                // hasChanges는 변경하지 않음 (정책: ID 자동 부여를 변경으로 취급하지 않음)
                // pushHistory도 호출하지 않음 (정책: Undo 대상에서 제외)
                logger.log('Auto-assigned component ID:', result.id, 'at path:', path);
              }
              effectiveId = result.id;
            }
          }

          state.selectedComponentId = effectiveId;

          // 선택 시 해당 노드까지 트리 확장
          if (effectiveId && state.layoutData) {
            const searchResult = findComponentById(state.layoutData.components, effectiveId);
            if (searchResult) {
              searchResult.path.forEach((pathId) => {
                if (!state.expandedTreeNodes.includes(pathId)) {
                  state.expandedTreeNodes.push(pathId);
                }
              });
            }
          }
        });
      },

      hoverComponent: (id) => {
        set((state) => {
          state.hoveredComponentId = id;
        });
      },

      // hoverComponent 별칭
      setHoveredComponentId: (id) => {
        set((state) => {
          state.hoveredComponentId = id;
        });
      },

      addComponent: (component, targetId, position) => {
        const { layoutData } = get();
        if (!layoutData) return;

        // 히스토리 저장
        get().pushHistory('Add component');

        set((state) => {
          if (!state.layoutData) return;

          const newComponent: ComponentDefinition = {
            ...component,
            id: generateUniqueId(component.name),
          };

          state.layoutData.components = addComponentToLayout(
            state.layoutData.components,
            newComponent,
            targetId,
            position
          );
          state.hasChanges = true;
          state.selectedComponentId = newComponent.id;
        });
      },

      deleteComponent: (id) => {
        const { layoutData } = get();
        if (!layoutData) return;

        // 히스토리 저장
        get().pushHistory('Delete component');

        set((state) => {
          if (!state.layoutData) return;

          state.layoutData.components = removeComponentFromLayout(
            state.layoutData.components,
            id
          );
          state.hasChanges = true;

          // 삭제된 컴포넌트가 선택되어 있었다면 선택 해제
          if (state.selectedComponentId === id) {
            state.selectedComponentId = null;
          }
        });
      },

      duplicateComponent: (id) => {
        const { layoutData } = get();
        if (!layoutData) return;

        // 히스토리 저장
        get().pushHistory('Duplicate component');

        set((state) => {
          if (!state.layoutData) return;

          const result = duplicateComponentInLayout(state.layoutData.components, id);
          if (result) {
            state.layoutData.components = result.components;
            state.hasChanges = true;
            state.selectedComponentId = result.newId;
          }
        });
      },

      moveComponent: (sourceId, targetId, position) => {
        const { layoutData } = get();
        if (!layoutData) return;

        // 히스토리 저장
        get().pushHistory('Move component');

        set((state) => {
          if (!state.layoutData) return;

          state.layoutData.components = moveComponentInLayout(
            state.layoutData.components,
            sourceId,
            targetId,
            position
          );
          state.hasChanges = true;
        });
      },

      updateComponentProps: (id, props) => {
        const { layoutData } = get();
        if (!layoutData) return;

        // 히스토리 저장
        get().pushHistory('Update props');

        set((state) => {
          if (!state.layoutData) return;

          state.layoutData.components = updateComponentInLayout(
            state.layoutData.components,
            id,
            { props: { ...props } }
          );
          state.hasChanges = true;
        });
      },

      updateComponent: (id, updates) => {
        const { layoutData } = get();
        if (!layoutData) return;

        // 히스토리 저장
        get().pushHistory('Update component');

        set((state) => {
          if (!state.layoutData) return;

          state.layoutData.components = updateComponentInLayout(
            state.layoutData.components,
            id,
            updates
          );
          state.hasChanges = true;
        });
      },

      /**
       * 경로를 사용하여 컴포넌트에 ID가 없으면 자동 생성하고 반환
       * @param path 컴포넌트 경로 (예: "0.children.2.children.0")
       * @returns 생성되거나 기존 ID, 실패 시 null
       */
      ensureComponentId: (path: string): string | null => {
        const { layoutData } = get();
        if (!layoutData) return null;

        const result = ensureComponentIdUtil(layoutData.components, path);
        if (!result) {
          logger.warn('ensureComponentId failed: component not found at path', path);
          return null;
        }

        // ID가 새로 생성된 경우에만 상태 업데이트
        if (result.components !== layoutData.components) {
          // 히스토리 저장
          get().pushHistory('Auto-assign component ID');

          set((state) => {
            if (!state.layoutData) return;
            state.layoutData.components = result.components;
            state.hasChanges = true;
          });

          logger.log('Component ID auto-assigned:', result.id, 'at path:', path);
        }

        return result.id;
      },

      /**
       * 경로 기반 컴포넌트에 ID를 부여하고 이동 (원자적 연산)
       * Auto ID 컴포넌트 드래그 앤 드롭에 사용
       * @param path 소스 컴포넌트 경로
       * @param targetId 타겟 컴포넌트 ID
       * @param position 드롭 위치
       * @returns 성공 여부
       */
      ensureComponentIdAndMove: (path: string, targetId: string, position): boolean => {
        const { layoutData } = get();
        if (!layoutData) return false;

        logger.log('ensureComponentIdAndMove called:', { path, targetId, position });

        // 1. 경로에서 컴포넌트 찾아서 ID 부여
        const result = ensureComponentIdUtil(layoutData.components, path);
        if (!result) {
          logger.warn('ensureComponentIdAndMove failed: component not found at path', path);
          return false;
        }

        const sourceId = result.id;
        logger.log('ensureComponentIdAndMove: sourceId =', sourceId);

        // 자기 자신으로 이동 방지
        if (sourceId === targetId) {
          logger.log('ensureComponentIdAndMove: source and target are the same, skipping');
          return false;
        }

        // 타겟이 소스의 자손인지 확인
        const targetResult = findComponentById(result.components, targetId);
        logger.log('ensureComponentIdAndMove: targetResult =', targetResult ? 'found' : 'NOT FOUND');

        if (!targetResult) {
          logger.warn('ensureComponentIdAndMove: target not found in components', targetId);
          return false;
        }

        // 히스토리 저장
        get().pushHistory('Move component');

        // 2. 하나의 set() 호출 안에서 ID 부여 + 이동 수행
        set((state) => {
          if (!state.layoutData) return;

          // ID가 부여된 components를 사용하여 이동
          const movedComponents = moveComponentInLayout(
            result.components,  // ID가 이미 부여된 배열 사용
            sourceId,
            targetId,
            position
          );

          logger.log('ensureComponentIdAndMove: move result components count =', movedComponents.length);

          state.layoutData.components = movedComponents;
          state.hasChanges = true;
        });

        logger.log('ensureComponentIdAndMove success:', sourceId, '->', targetId, position);
        return true;
      },

      /**
       * 경로 기반으로 소스와 타겟 모두 처리하여 이동 (원자적 연산)
       * 소스와 타겟 모두 Auto ID인 경우 사용
       * @param sourcePath 소스 컴포넌트 경로
       * @param targetPath 타겟 컴포넌트 경로
       * @param position 드롭 위치
       * @returns 성공 여부
       */
      moveComponentByPaths: (sourcePath: string, targetPath: string, position): boolean => {
        const { layoutData } = get();
        if (!layoutData) return false;

        logger.log('moveComponentByPaths called:', { sourcePath, targetPath, position });

        // 같은 경로면 이동 불필요
        if (sourcePath === targetPath) {
          logger.log('moveComponentByPaths: source and target paths are the same, skipping');
          return false;
        }

        // 타입 단언: LayoutComponent[]는 ComponentDefinition[]과 호환됨
        const components = layoutData.components as (ComponentDefinition | string)[];

        // 1. 소스 컴포넌트에 ID 부여
        const sourceResult = ensureComponentIdUtil(components, sourcePath);
        if (!sourceResult) {
          logger.warn('moveComponentByPaths: source not found at path', sourcePath);
          return false;
        }
        const sourceId = sourceResult.id;

        // 2. 타겟 컴포넌트에 ID 부여 (소스 ID가 부여된 배열 사용)
        const targetResult = ensureComponentIdUtil(sourceResult.components, targetPath);
        if (!targetResult) {
          logger.warn('moveComponentByPaths: target not found at path', targetPath);
          return false;
        }
        const targetId = targetResult.id;

        logger.log('moveComponentByPaths: sourceId =', sourceId, ', targetId =', targetId);

        // 자기 자신으로 이동 방지
        if (sourceId === targetId) {
          logger.log('moveComponentByPaths: source and target are the same component, skipping');
          return false;
        }

        // 히스토리 저장
        get().pushHistory('Move component');

        // 3. 하나의 set() 호출 안에서 이동 수행
        set((state) => {
          if (!state.layoutData) return;

          const movedComponents = moveComponentInLayout(
            targetResult.components,  // 소스와 타겟 모두 ID가 부여된 배열
            sourceId,
            targetId,
            position
          );

          logger.log('moveComponentByPaths: move result components count =', movedComponents.length);

          state.layoutData.components = movedComponents;
          state.hasChanges = true;
        });

        logger.log('moveComponentByPaths success:', sourceId, '->', targetId, position);
        return true;
      },

      /**
       * 경로를 사용하여 컴포넌트 업데이트
       * ID가 없는 컴포넌트도 경로로 직접 업데이트 가능
       * @param path 컴포넌트 경로
       * @param updates 업데이트할 속성
       */
      updateComponentByPath: (path: string, updates: Partial<ComponentDefinition>) => {
        const { layoutData } = get();
        if (!layoutData) return;

        // 히스토리 저장
        get().pushHistory('Update component by path');

        set((state) => {
          if (!state.layoutData) return;

          state.layoutData.components = updateComponentByPath(
            state.layoutData.components,
            path,
            updates
          );
          state.hasChanges = true;
        });
      },

      // ========== 히스토리 ==========

      undo: () => {
        set((state) => {
          // undo 가능: historyIndex가 0 이상 (최소한 하나의 히스토리 있음)
          if (state.historyIndex < 0 || state.history.length === 0) return;

          // 현재 상태가 마지막 히스토리보다 뒤에 있으면
          // 현재 상태를 히스토리에 저장 (redo를 위해)
          if (state.historyIndex === state.history.length - 1 && state.layoutData) {
            const currentEntry = {
              layoutData: JSON.parse(JSON.stringify(state.layoutData)),
              description: 'Current state',
              timestamp: Date.now(),
            };
            state.history.push(currentEntry);
          }

          // 이전 상태로 복원
          const entry = state.history[state.historyIndex];
          if (entry) {
            state.layoutData = JSON.parse(JSON.stringify(entry.layoutData));
            state.historyIndex -= 1;
            state.hasChanges = true;
          }
        });
      },

      redo: () => {
        set((state) => {
          // redo 가능: historyIndex가 history.length - 2 이하 (undo 후 상태)
          if (state.historyIndex >= state.history.length - 1) return;

          state.historyIndex += 1;
          // redo는 historyIndex+1 위치의 상태를 복원
          const nextEntry = state.history[state.historyIndex + 1];
          if (nextEntry) {
            state.layoutData = JSON.parse(JSON.stringify(nextEntry.layoutData));
            state.hasChanges = true;
          }
        });
      },

      pushHistory: (description) => {
        set((state) => {
          if (!state.layoutData) return;

          // 현재 위치 이후의 히스토리 제거 (redo 불가능하게)
          state.history = state.history.slice(0, state.historyIndex + 1);

          // 새 히스토리 항목 추가
          const entry: HistoryEntry = {
            layoutData: JSON.parse(JSON.stringify(state.layoutData)),
            description,
            timestamp: Date.now(),
          };

          state.history.push(entry);
          state.historyIndex = state.history.length - 1;

          // 최대 크기 초과 시 오래된 항목 제거
          while (state.history.length > state.maxHistorySize) {
            state.history.shift();
            state.historyIndex -= 1;
          }
        });
      },

      // ========== 편집 모드 ==========

      setEditMode: (mode) => {
        set((state) => {
          state.editMode = mode;
        });
      },

      setPreviewDevice: (device, customWidth) => {
        set((state) => {
          state.previewDevice = device;
          if (customWidth !== undefined) {
            state.customWidth = customWidth;
          }
        });
      },

      togglePreviewDarkMode: () => {
        set((state) => {
          state.previewDarkMode = !state.previewDarkMode;
        });
      },

      // ========== 버전 관리 ==========

      loadVersions: async () => {
        const { templateId, layoutName } = get();
        if (!templateId || !layoutName) return;

        try {
          const versions = await fetchVersions(templateId, layoutName);
          set((state) => {
            state.versions = versions;
          });
        } catch (error) {
          logger.error('Failed to load versions:', error);
        }
      },

      restoreVersion: async (versionId) => {
        const { templateId, layoutName } = get();
        if (!templateId || !layoutName) return;

        try {
          const layoutData = await restoreVersionApi(templateId, layoutName, versionId);
          set((state) => {
            state.layoutData = layoutData;
            state.hasChanges = true;
          });
          logger.log('Version restored:', versionId);
        } catch (error) {
          logger.error('Failed to restore version:', error);
          throw error;
        }
      },

      compareVersions: async (v1, v2) => {
        // TODO: 버전 비교 구현
        return [];
      },

      // ========== 데이터 소스 ==========

      addDataSource: (dataSource) => {
        get().pushHistory('Add data source');

        set((state) => {
          if (!state.layoutData) return;

          if (!state.layoutData.data_sources) {
            state.layoutData.data_sources = [];
          }
          state.layoutData.data_sources.push(dataSource);
          state.hasChanges = true;
        });
      },

      updateDataSource: (id, dataSource) => {
        get().pushHistory('Update data source');

        set((state) => {
          if (!state.layoutData?.data_sources) return;

          const index = state.layoutData.data_sources.findIndex((ds: any) => ds.id === id);
          if (index !== -1) {
            state.layoutData.data_sources[index] = {
              ...state.layoutData.data_sources[index],
              ...dataSource,
            };
            state.hasChanges = true;
          }
        });
      },

      deleteDataSource: (id) => {
        get().pushHistory('Delete data source');

        set((state) => {
          if (!state.layoutData?.data_sources) return;

          state.layoutData.data_sources = state.layoutData.data_sources.filter(
            (ds: any) => ds.id !== id
          );
          state.hasChanges = true;
        });
      },

      testDataSource: async (id) => {
        // TODO: 데이터 소스 테스트 구현
        return { success: true, data: {} };
      },

      setMockData: (dataSourceId, data) => {
        set((state) => {
          state.mockDataContext[dataSourceId] = data;
        });
      },

      // ========== 모달 ==========

      addModal: (modal) => {
        get().pushHistory('Add modal');

        set((state) => {
          if (!state.layoutData) return;

          if (!state.layoutData.modals) {
            state.layoutData.modals = [];
          }
          state.layoutData.modals.push(modal);
          state.hasChanges = true;
        });
      },

      updateModal: (id, modal) => {
        get().pushHistory('Update modal');

        set((state) => {
          if (!state.layoutData?.modals) return;

          const index = state.layoutData.modals.findIndex((m: any) => m.id === id);
          if (index !== -1) {
            state.layoutData.modals[index] = {
              ...state.layoutData.modals[index],
              ...modal,
            };
            state.hasChanges = true;
          }
        });
      },

      deleteModal: (id) => {
        get().pushHistory('Delete modal');

        set((state) => {
          if (!state.layoutData?.modals) return;

          state.layoutData.modals = state.layoutData.modals.filter(
            (m: any) => m.id !== id
          );
          state.hasChanges = true;
        });
      },

      // ========== UI 상태 ==========

      setActivePropertyTab: (tab) => {
        set((state) => {
          state.activePropertyTab = tab;
        });
      },

      toggleTreeNode: (id) => {
        set((state) => {
          const index = state.expandedTreeNodes.indexOf(id);
          if (index === -1) {
            state.expandedTreeNodes.push(id);
          } else {
            state.expandedTreeNodes.splice(index, 1);
          }
        });
      },

      copyToClipboard: (component) => {
        set((state) => {
          state.clipboard = JSON.parse(JSON.stringify(component));
        });
      },

      pasteFromClipboard: (targetId, position) => {
        const { clipboard } = get();
        if (!clipboard) return;

        const newComponent: ComponentDefinition = {
          ...JSON.parse(JSON.stringify(clipboard)),
          id: generateUniqueId(clipboard.name),
        };

        get().addComponent(newComponent, targetId, position);
      },

      // ========== 드래그앤드롭 ==========

      startDrag: (component) => {
        set((state) => {
          state.draggingComponent = component;
        });
      },

      dragOver: (targetId, position) => {
        set((state) => {
          state.dropTargetId = targetId;
          state.dropPosition = position;
        });
      },

      endDrag: () => {
        set((state) => {
          state.draggingComponent = null;
          state.dropTargetId = null;
          state.dropPosition = null;
        });
      },

      // ========== 확장 오버라이드 ==========

      saveExtensionOverride: async (extensionId, modifiedComponents) => {
        // TODO: 확장 오버라이드 저장 구현
        logger.log('Save extension override:', extensionId);
      },

      restoreExtensionOverride: async (extensionId) => {
        // TODO: 확장 오버라이드 복원 구현
        logger.log('Restore extension override:', extensionId);
      },
    })),
    { name: 'WysiwygEditorState' }
  )
);

// ============================================================================
// 선택자 (Selectors)
// ============================================================================

/**
 * Undo 가능 여부
 */
export const selectCanUndo = (state: EditorStore) => state.historyIndex >= 0;

/**
 * Redo 가능 여부
 */
export const selectCanRedo = (state: EditorStore) =>
  state.historyIndex < state.history.length - 1;

/**
 * 선택된 컴포넌트
 */
export const selectSelectedComponent = (state: EditorStore) => {
  if (!state.layoutData || !state.selectedComponentId) return null;
  const result = findComponentById(state.layoutData.components, state.selectedComponentId);
  return result?.component || null;
};

/**
 * 선택된 컴포넌트의 메타데이터
 */
export const selectSelectedComponentMetadata = (state: EditorStore) => {
  const component = selectSelectedComponent(state);
  if (!component || !state.componentCategories) return null;

  const allComponents = [
    ...state.componentCategories.basic,
    ...state.componentCategories.composite,
    ...state.componentCategories.layout,
  ];

  return allComponents.find((c) => c.name === component.name) || null;
};

/**
 * 바인딩 소스 정보
 */
export const selectBindingSources = (state: EditorStore) => {
  const layoutData = state.layoutData;
  if (!layoutData) {
    return {
      dataSources: [],
      globalState: [],
      localState: [],
      routeParams: [],
      queryParams: [],
      defines: {},
      computed: {},
    };
  }

  return {
    dataSources: layoutData.data_sources || [],
    globalState: Object.keys(state.mockDataContext._global || {}),
    localState: Object.keys(state.mockDataContext._local || {}),
    routeParams: ['id', 'slug'], // 일반적인 라우트 파라미터
    queryParams: ['page', 'per_page', 'search', 'sort', 'order'], // 일반적인 쿼리 파라미터
    defines: layoutData.defines || {},
    computed: layoutData.computed || {},
  };
};

export default useEditorState;
