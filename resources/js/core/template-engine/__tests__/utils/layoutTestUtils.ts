/**
 * @file layoutTestUtils.ts
 * @description 레이아웃 파일 렌더링 테스트 유틸리티
 *
 * 그누보드7 레이아웃 JSON 구조를 테스트하기 위한 유틸리티입니다.
 * 실제 레이아웃 JSON (slots, modals, initLocal, initGlobal 등)을 지원합니다.
 *
 * ## 주요 기능
 * - data_sources 자동 처리 (mockApi로 등록된 데이터 fetch)
 * - 슬롯 시스템 검증 (slot 속성 vs SlotContainer 매칭)
 * - 데이터 바인딩 타입 검증 (배열/객체 타입 불일치 감지)
 */

import { vi, expect, type Mock } from 'vitest';
import { render, screen, waitFor, cleanup as rtlCleanup } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';

// 엔진 임포트
import { ComponentRegistry } from '../../ComponentRegistry';
import { DataBindingEngine } from '../../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../../TranslationEngine';
import { ActionDispatcher, ActionDefinition } from '../../ActionDispatcher';
import DynamicRenderer, { ComponentDefinition } from '../../DynamicRenderer';

// 유틸리티 임포트
import {
  createMockApiRegistry,
  registerMockApi,
  registerMockApiError,
  setupGlobalFetchMock,
  clearMockApiRegistry,
  type MockApiOptions,
  type MockApiRegistry,
} from './mockApiUtils';

/**
 * 레이아웃 검증 경고 타입
 */
export interface LayoutValidationWarning {
  type: 'slot_mismatch' | 'data_type_mismatch' | 'missing_slotcontainer' | 'orphan_slot';
  message: string;
  componentId?: string;
  details?: Record<string, any>;
}


/**
 * 레이아웃 JSON 타입 정의
 */
export interface LayoutJson {
  version?: string;
  layout_name?: string;
  extends?: string;
  meta?: {
    title?: string;
    [key: string]: any;
  };
  // 새로운 slots 구조
  slots?: {
    content?: ComponentDefinition[];
    [slotName: string]: ComponentDefinition[] | undefined;
  };
  // 레거시 components 구조
  components?: ComponentDefinition[];
  // 모달 정의
  modals?: ComponentDefinition[];
  // 데이터 소스
  data_sources?: Array<{
    id: string;
    type: string;
    endpoint?: string;
    method?: string;
    auto_fetch?: boolean;
    [key: string]: any;
  }>;
  // 초기 상태
  initLocal?: Record<string, any>;
  initGlobal?: Record<string, any>;
  initIsolated?: Record<string, any>;
  // computed 값
  computed?: Record<string, string>;
  // defines (재사용 가능한 컴포넌트 정의)
  defines?: Record<string, ComponentDefinition>;
  // 초기 액션
  init_actions?: any[];
  initActions?: any[];
}

// 타입 정의
export interface LayoutTestOptions {
  // 초기 상태 (레이아웃의 initLocal/initGlobal과 병합됨)
  initialState?: {
    _local?: Record<string, any>;
    _global?: Record<string, any>;
  };

  // 라우트/쿼리 파라미터
  routeParams?: Record<string, string>;
  queryParams?: Record<string, string>;

  // 인증 설정
  auth?: {
    isAuthenticated: boolean;
    user?: { id: number; name: string; email?: string; role?: string };
    authType?: 'admin' | 'user';
  };

  // 다국어 설정
  translations?: Record<string, any>;
  locale?: 'ko' | 'en';

  // 데이터소스 초기 데이터 (API 모킹 대신 직접 데이터 주입)
  initialData?: Record<string, any>;

  // 컴포넌트 레지스트리 (기본: 실제 레지스트리)
  componentRegistry?: ComponentRegistry;

  // 템플릿 ID
  templateId?: string;

  // 렌더링할 슬롯 이름 (기본: 'content')
  slotName?: string;

  // 모달 포함 여부 (기본: false)
  includeModals?: boolean;
}

export interface LayoutTestResult {
  // 렌더링
  render: () => Promise<{ container: HTMLElement; rerender: () => void }>;
  rerender: () => Promise<void>;

  // Mock API
  mockApi: (dataSourceId: string, options: MockApiOptions | any) => Mock;
  mockApiError: (dataSourceId: string, status: number, message?: string) => Mock;

  // 상태 관리
  getState: () => { _local: any; _global: any; _isolated: Record<string, any> };
  setState: (path: string, value: any, target?: 'local' | 'global' | 'isolated') => void;
  getComputed: (key: string) => any;

  // 액션
  triggerAction: (actionDef: ActionDefinition) => Promise<void>;
  triggerEvent: (componentId: string, eventType: string, eventData?: any) => Promise<void>;

  // 모달
  openModal: (modalId: string) => void;
  closeModal: () => void;
  getModalStack: () => string[];

  // 토스트
  getToasts: () => Array<{ type: string; message: string }>;

  // 네비게이션
  getNavigationHistory: () => string[];
  mockNavigate: Mock;

  // 유틸리티
  waitForDataSource: (dataSourceId: string) => Promise<void>;
  waitForState: (path: string, expectedValue: any) => Promise<void>;

  // 레이아웃 정보
  getDataSources: () => Array<{ id: string; endpoint?: string; [key: string]: any }>;
  getLayoutInfo: () => { name?: string; version?: string; meta?: any };

  // 검증 (NEW)
  getValidationWarnings: () => LayoutValidationWarning[];
  validateSlots: () => LayoutValidationWarning[];
  validateDataBindings: () => LayoutValidationWarning[];
  assertNoValidationErrors: () => void;

  // 정리
  cleanup: () => void;
  user: ReturnType<typeof userEvent.setup>;

  // screen API 노출
  screen: typeof screen;
}

/**
 * 레이아웃 테스트 헬퍼 생성
 *
 * @example
 * ```typescript
 * const { render, mockApi, getState } = createLayoutTest(layoutJson, {
 *   auth: { isAuthenticated: true, user: { id: 1, name: 'Admin' } },
 *   locale: 'ko',
 * });
 *
 * mockApi('products', { data: [{ id: 1, name: '상품1' }] });
 * await render();
 *
 * expect(screen.getByText('상품1')).toBeInTheDocument();
 * ```
 */
/**
 * 컴포넌트 트리에서 slot 속성을 가진 컴포넌트 ID들을 수집합니다.
 */
function collectSlotRegistrations(components: ComponentDefinition[]): Map<string, string[]> {
  const slotMap = new Map<string, string[]>(); // slotId -> componentIds[]

  function traverse(comp: ComponentDefinition | string) {
    if (typeof comp === 'string') return;
    if (comp.slot) {
      const slotId = typeof comp.slot === 'string' ? comp.slot : String(comp.slot);
      // {{}} 표현식은 동적이므로 검증에서 제외
      if (!slotId.includes('{{')) {
        const existing = slotMap.get(slotId) || [];
        existing.push(comp.id || 'unknown');
        slotMap.set(slotId, existing);
      }
    }
    if (comp.children && Array.isArray(comp.children)) {
      comp.children.forEach(traverse);
    }
  }

  components.forEach(traverse);
  return slotMap;
}

/**
 * 컴포넌트 트리에서 SlotContainer 컴포넌트의 slotId들을 수집합니다.
 */
function collectSlotContainers(components: ComponentDefinition[]): Set<string> {
  const containerSlots = new Set<string>();

  function traverse(comp: ComponentDefinition | string) {
    if (typeof comp === 'string') return;
    if (comp.name === 'SlotContainer' && comp.props?.slotId) {
      containerSlots.add(String(comp.props.slotId));
    }
    if (comp.children && Array.isArray(comp.children)) {
      comp.children.forEach(traverse);
    }
  }

  components.forEach(traverse);
  return containerSlots;
}

/**
 * 슬롯 시스템 검증: slot 속성이 있는 컴포넌트에 대응하는 SlotContainer가 있는지 확인
 */
function validateSlotSystem(components: ComponentDefinition[]): LayoutValidationWarning[] {
  const warnings: LayoutValidationWarning[] = [];
  const slotRegistrations = collectSlotRegistrations(components);
  const slotContainers = collectSlotContainers(components);

  // slot 속성으로 등록된 슬롯 ID에 대응하는 SlotContainer가 없으면 경고
  for (const [slotId, componentIds] of slotRegistrations) {
    if (!slotContainers.has(slotId)) {
      warnings.push({
        type: 'missing_slotcontainer',
        message: `슬롯 "${slotId}"에 등록된 컴포넌트가 있지만 SlotContainer가 없습니다. SlotContainer를 추가하거나 slot 속성을 제거하세요.`,
        details: {
          slotId,
          registeredComponents: componentIds,
        },
      });
    }
  }

  // SlotContainer가 있지만 등록된 컴포넌트가 없는 경우 (정보성 경고)
  for (const slotId of slotContainers) {
    if (!slotRegistrations.has(slotId)) {
      warnings.push({
        type: 'orphan_slot',
        message: `SlotContainer "${slotId}"가 있지만 해당 슬롯에 등록된 컴포넌트가 없습니다.`,
        details: { slotId },
      });
    }
  }

  return warnings;
}

/**
 * 데이터 바인딩 표현식에서 배열이 기대되는 곳에 객체가 바인딩되는지 검증
 */
function validateDataBindingTypes(
  components: ComponentDefinition[],
  dataContext: Record<string, any>,
  bindingEngine: DataBindingEngine
): LayoutValidationWarning[] {
  const warnings: LayoutValidationWarning[] = [];

  // DataGrid, Table 등 배열이 필요한 컴포넌트와 props
  const arrayProps: Record<string, string[]> = {
    DataGrid: ['data', 'rows'],
    Table: ['data', 'rows'],
    Select: ['options'],
    RichSelect: ['options'],
    CheckboxGroup: ['options'],
    RadioGroup: ['options'],
  };

  function traverse(comp: ComponentDefinition | string) {
    if (typeof comp === 'string') return;
    const expectedArrayProps = arrayProps[comp.name || ''];
    if (expectedArrayProps && comp.props) {
      for (const propName of expectedArrayProps) {
        const propValue = comp.props[propName];
        if (typeof propValue === 'string' && propValue.includes('{{')) {
          try {
            // {{expression}} 에서 expression 추출
            const exprMatch = propValue.match(/^\{\{(.+)\}\}$/);
            if (!exprMatch) continue;
            const expression = exprMatch[1].trim();

            // evaluateExpression으로 실제 값 평가
            const resolved = bindingEngine.evaluateExpression(expression, dataContext);

            // 값이 존재하고, 배열이 아니면서 객체인 경우 경고
            if (resolved !== null && resolved !== undefined && !Array.isArray(resolved) && typeof resolved === 'object') {
              warnings.push({
                type: 'data_type_mismatch',
                message: `컴포넌트 "${comp.id || comp.name}"의 "${propName}" prop에 배열이 필요하지만 객체가 바인딩됨`,
                componentId: comp.id,
                details: {
                  propName,
                  expression: propValue,
                  resolvedType: 'object',
                  expectedType: 'array',
                  resolvedValue: resolved,
                },
              });
            }
          } catch {
            // 바인딩 오류는 무시 (다른 곳에서 처리됨)
          }
        }
      }
    }

    if (comp.children && Array.isArray(comp.children)) {
      comp.children.forEach(traverse);
    }
  }

  components.forEach(traverse);
  return warnings;
}

/**
 * data_sources를 처리하고 fetch mock을 통해 데이터를 로드합니다.
 *
 * API 응답 구조 처리:
 * - mockApi('cartItems', { response: { items: [...] } }) 설정 시
 * - createMockResponse는 { success: true, data: { items: [...] } }를 반환
 * - 이 함수는 data 필드만 추출하여 cartItems = { items: [...] }로 저장
 * - 따라서 레이아웃에서 cartItems.items로 접근 가능
 *
 * 또는 data 필드 내에 중첩된 data가 있는 경우 (페이지네이션):
 * - mockApi('products', { response: { data: [...], meta: {} } }) 설정 시
 * - products = { data: [...], meta: {} }로 저장
 * - 레이아웃에서 products.data로 접근 가능
 */
async function processDataSources(
  dataSources: LayoutJson['data_sources'],
  mockApiRegistry: MockApiRegistry,
  routeParams: Record<string, string> = {},
  queryParams: Record<string, string> = {}
): Promise<Record<string, any>> {
  const result: Record<string, any> = {};

  if (!dataSources || dataSources.length === 0) {
    return result;
  }

  for (const ds of dataSources) {
    // auto_fetch가 false가 아닌 경우에만 처리 (기본값 true)
    if (ds.auto_fetch === false) {
      continue;
    }

    if ((!ds.type || ds.type === 'api') && ds.endpoint) {
      try {
        // 1. 먼저 ds.id로 직접 mock 조회 (URL 추출 불일치 방지)
        const directMock = mockApiRegistry.mocks.get(ds.id);
        if (directMock) {
          if (directMock.error) {
            result[ds.id] = ds.fallback ?? null;
            continue;
          }
          if (directMock.delay) {
            await new Promise(resolve => setTimeout(resolve, directMock.delay));
          }
          // mockApi('order', { response: { data: {...} } }) 설정 시
          // directMock.response = { data: {...} }
          // result[ds.id] = { data: {...} }
          result[ds.id] = directMock.response;
          continue;
        }

        // 2. 직접 mock이 없으면 기존 URL 기반 fetchSpy 사용
        // endpoint에서 route 파라미터 치환
        let endpoint = ds.endpoint;
        for (const [key, value] of Object.entries(routeParams)) {
          endpoint = endpoint.replace(`{${key}}`, value);
          endpoint = endpoint.replace(`:${key}`, value);
        }

        // 쿼리 파라미터 추가
        if (Object.keys(queryParams).length > 0) {
          const url = new URL(endpoint, 'http://localhost');
          for (const [key, value] of Object.entries(queryParams)) {
            url.searchParams.set(key, value);
          }
          endpoint = url.pathname + url.search;
        }

        // fetch 호출 (mockApiRegistry의 fetchSpy가 처리)
        const response = await mockApiRegistry.fetchSpy(endpoint, {
          method: ds.method || 'GET',
        });

        const json = await response.json();

        // API 래핑 구조 해제: { success: true, data: ... } → data만 저장
        if (json && typeof json === 'object' && 'data' in json) {
          result[ds.id] = json.data;
        } else {
          result[ds.id] = json;
        }
      } catch (error) {
        // 에러 시 fallback 데이터 사용 또는 null
        const fallback = ds.fallback;
        result[ds.id] = fallback ?? null;
      }
    }
  }

  return result;
}

/**
 * 레이아웃 JSON에서 컴포넌트 배열을 추출합니다.
 * slots.content, components, 또는 배열 자체를 지원합니다.
 */
function extractComponents(layoutJson: LayoutJson, slotName: string = 'content'): ComponentDefinition[] {
  // 1. slots 구조 확인
  if (layoutJson.slots && layoutJson.slots[slotName]) {
    return layoutJson.slots[slotName] || [];
  }

  // 2. 레거시 components 구조 확인
  if (layoutJson.components && Array.isArray(layoutJson.components)) {
    return layoutJson.components;
  }

  // 3. 배열이 직접 전달된 경우
  if (Array.isArray(layoutJson)) {
    return layoutJson as unknown as ComponentDefinition[];
  }

  return [];
}

/**
 * 깊은 병합을 수행합니다.
 * 레이아웃의 initLocal/initGlobal과 사용자 제공 initialState를 병합합니다.
 */
function deepMerge(target: Record<string, any>, source: Record<string, any>): Record<string, any> {
  const result = { ...target };

  for (const key of Object.keys(source)) {
    const sourceValue = source[key];
    const targetValue = target[key];

    if (sourceValue === null || sourceValue === undefined) {
      result[key] = sourceValue;
    } else if (Array.isArray(sourceValue)) {
      result[key] = sourceValue;
    } else if (
      typeof sourceValue === 'object' &&
      typeof targetValue === 'object' &&
      !Array.isArray(targetValue)
    ) {
      result[key] = deepMerge(targetValue, sourceValue);
    } else {
      result[key] = sourceValue;
    }
  }

  return result;
}

export function createLayoutTest(
  layoutJson: LayoutJson,
  options: LayoutTestOptions = {}
): LayoutTestResult {
  // 레이아웃에서 초기 상태 추출
  const layoutInitLocal = layoutJson.initLocal || {};
  const layoutInitGlobal = layoutJson.initGlobal || {};
  const layoutInitIsolated = layoutJson.initIsolated || {};

  // 내부 상태 (레이아웃 정의 + 사용자 제공 상태 병합)
  let state = {
    _local: deepMerge(layoutInitLocal, options.initialState?._local || {}),
    _global: deepMerge(layoutInitGlobal, options.initialState?._global || {}),
    _isolated: { ...layoutInitIsolated } as Record<string, any>,
  };

  const toasts: Array<{ type: string; message: string }> = [];
  const modalStack: string[] = [];
  const navigationHistory: string[] = [];
  const validationWarnings: LayoutValidationWarning[] = [];
  let fetchedDataContext: Record<string, any> = {};

  // Mock 레지스트리
  const mockApiRegistry = createMockApiRegistry();
  let cleanupFetch: (() => void) | null = null;

  // 엔진 인스턴스
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let renderResult: ReturnType<typeof render> | null = null;

  // Mock 함수들
  const mockNavigate = vi.fn((path: string) => {
    navigationHistory.push(path);
  });

  const mockSetState = vi.fn((updates: Record<string, any>, target: string = 'local') => {
    if (target === 'local') {
      state._local = { ...state._local, ...updates };
    } else if (target === 'global') {
      state._global = { ...state._global, ...updates };
    }
  });

  // 초기화 함수
  function initialize() {
    // ComponentRegistry
    registry = options.componentRegistry || ComponentRegistry.getInstance();

    // DataBindingEngine
    bindingEngine = new DataBindingEngine();

    // TranslationEngine
    TranslationEngine.resetInstance();
    translationEngine = TranslationEngine.getInstance();

    // 번역 데이터 주입
    if (options.translations) {
      const templateId = options.templateId || 'test-template';
      const locale = options.locale || 'ko';
      (translationEngine as any).translations.set(
        `${templateId}:${locale}`,
        options.translations
      );
    }

    // ActionDispatcher
    actionDispatcher = new ActionDispatcher({
      navigate: mockNavigate,
    });

    // 커스텀 핸들러 등록: toast
    actionDispatcher.registerHandler('toast', (params: any) => {
      toasts.push({ type: params.type || 'info', message: params.message });
    });

    // 커스텀 핸들러 등록: openModal
    actionDispatcher.registerHandler('openModal', (params: any) => {
      modalStack.push(params.target);
    });

    // 커스텀 핸들러 등록: closeModal
    actionDispatcher.registerHandler('closeModal', () => {
      modalStack.pop();
    });

    // setState 핸들러에 globalStateUpdater 연결
    actionDispatcher.setGlobalStateUpdater?.((updates: Record<string, any>) => {
      state._global = { ...state._global, ...updates };
    });

    // 글로벌 fetch 모킹
    cleanupFetch = setupGlobalFetchMock(mockApiRegistry);

    // G7Core 글로벌 객체 모킹
    (window as any).G7Core = {
      state: {
        get: () => ({ _global: state._global, _local: state._local }),
        set: (updates: any) => { state._global = { ...state._global, ...updates }; },
        getLocal: () => state._local,
        setLocal: (updates: any) => { state._local = { ...state._local, ...updates }; },
      },
      toast: {
        success: (msg: string) => toasts.push({ type: 'success', message: msg }),
        error: (msg: string) => toasts.push({ type: 'error', message: msg }),
        warning: (msg: string) => toasts.push({ type: 'warning', message: msg }),
        info: (msg: string) => toasts.push({ type: 'info', message: msg }),
      },
      modal: {
        open: (id: string) => modalStack.push(id),
        close: () => modalStack.pop(),
        getStack: () => modalStack,
      },
    };
  }

  // 렌더링 함수
  async function doRender() {
    initialize();

    // 레이아웃 JSON에서 초기 상태 적용 (state → _local, global_state → _global)
    if (layoutJson.state) {
      state._local = { ...state._local, ...layoutJson.state };
    }
    if (layoutJson.global_state) {
      state._global = { ...state._global, ...layoutJson.global_state };
    }

    // init_actions에서 setState 핸들러 처리
    const initActions = layoutJson.init_actions || layoutJson.initActions || [];
    for (const action of initActions) {
      if (action.handler === 'setState' && action.params) {
        const { target, ...stateValues } = action.params;
        if (target === 'global') {
          state._global = { ...state._global, ...stateValues };
        } else {
          // 기본값은 local
          state._local = { ...state._local, ...stateValues };
        }
      }
    }

    // 인증 정보를 상태에 추가
    if (options.auth) {
      state._global.auth = {
        isAuthenticated: options.auth.isAuthenticated,
        user: options.auth.user || null,
        authType: options.auth.authType || 'user',
      };
    }

    // data_sources 처리: mockApi로 등록된 데이터를 fetch mock을 통해 로드
    const dataSourceData = await processDataSources(
      layoutJson.data_sources,
      mockApiRegistry,
      options.routeParams || {},
      options.queryParams || {}
    );


    // initialData는 fallback으로 사용 (mockApi가 없는 경우)
    fetchedDataContext = {
      ...options.initialData, // fallback 데이터
      ...dataSourceData,      // fetch된 데이터가 우선
    };

    // dataContext 구성
    const dataContext = {
      ...fetchedDataContext,
      _local: state._local,
      _global: state._global,
      route: options.routeParams || {},
      query: options.queryParams || {},
    };

    const translationContext: TranslationContext = {
      templateId: options.templateId || 'test-template',
      locale: options.locale || 'ko',
    };

    // 레이아웃 JSON에서 컴포넌트 추출
    const slotName = options.slotName || 'content';
    const components = extractComponents(layoutJson, slotName);

    // 모달 포함 옵션
    let allComponents = [...components];
    if (options.includeModals && layoutJson.modals) {
      allComponents = [...allComponents, ...layoutJson.modals];
    }

    // 슬롯 시스템 검증
    const slotWarnings = validateSlotSystem(allComponents);
    validationWarnings.push(...slotWarnings);

    // 데이터 바인딩 타입 검증
    const bindingWarnings = validateDataBindingTypes(allComponents, dataContext, bindingEngine);
    validationWarnings.push(...bindingWarnings);

    // ComponentDefinition으로 변환
    const componentDef: ComponentDefinition = {
      id: 'root',
      type: 'layout',
      name: 'Fragment',
      children: allComponents,
    };

    renderResult = render(
      React.createElement(DynamicRenderer, {
        componentDef,
        dataContext,
        translationContext,
        registry,
        bindingEngine,
        translationEngine,
        actionDispatcher,
      })
    );

    // 데이터소스 로딩 대기
    await waitFor(() => {}, { timeout: 100 });

    return {
      container: renderResult.container,
      rerender: () => doRerender(),
    };
  }

  // 리렌더링 함수
  async function doRerender() {
    if (!renderResult) {
      throw new Error('render()를 먼저 호출하세요');
    }

    const dataContext = {
      ...options.initialData,   // fallback 데이터
      ...fetchedDataContext,    // fetch된 데이터 (API 응답)
      _local: state._local,
      _global: state._global,
      route: options.routeParams || {},
      query: options.queryParams || {},
    };

    const translationContext: TranslationContext = {
      templateId: options.templateId || 'test-template',
      locale: options.locale || 'ko',
    };

    // 레이아웃 JSON에서 컴포넌트 추출
    const slotName = options.slotName || 'content';
    const components = extractComponents(layoutJson, slotName);

    // 모달 포함 옵션
    let allComponents = [...components];
    if (options.includeModals && layoutJson.modals) {
      allComponents = [...allComponents, ...layoutJson.modals];
    }

    // ComponentDefinition으로 변환
    const componentDef: ComponentDefinition = {
      id: 'root',
      type: 'layout',
      name: 'Fragment',
      children: allComponents,
    };

    renderResult.rerender(
      React.createElement(DynamicRenderer, {
        componentDef,
        dataContext,
        translationContext,
        registry,
        bindingEngine,
        translationEngine,
        actionDispatcher,
      })
    );

    await waitFor(() => {}, { timeout: 50 });
  }

  // 정리 함수
  function doCleanup() {
    cleanupFetch?.();
    clearMockApiRegistry(mockApiRegistry);
    rtlCleanup();
    vi.restoreAllMocks();

    // 상태 초기화
    state = {
      _local: {},
      _global: {},
      _isolated: {},
    };
    toasts.length = 0;
    modalStack.length = 0;
    navigationHistory.length = 0;
    validationWarnings.length = 0;
    fetchedDataContext = {};
  }

  // userEvent 인스턴스
  const user = userEvent.setup();

  return {
    // 렌더링
    render: doRender,
    rerender: doRerender,

    // Mock API
    mockApi: (dataSourceId, opts) => registerMockApi(mockApiRegistry, dataSourceId, opts),
    mockApiError: (dataSourceId, status, message) =>
      registerMockApiError(mockApiRegistry, dataSourceId, status, message),

    // 상태 관리
    getState: () => ({ ...state }),
    setState: (path, value, target = 'local') => {
      const parts = path.split('.');
      const root = parts[0];

      if (target === 'local') {
        if (parts.length === 1) {
          state._local[root] = value;
        } else {
          state._local[root] = state._local[root] || {};
          let current = state._local[root];
          for (let i = 1; i < parts.length - 1; i++) {
            current[parts[i]] = current[parts[i]] || {};
            current = current[parts[i]];
          }
          current[parts[parts.length - 1]] = value;
        }
      } else if (target === 'global') {
        if (parts.length === 1) {
          state._global[root] = value;
        } else {
          state._global[root] = state._global[root] || {};
          let current = state._global[root];
          for (let i = 1; i < parts.length - 1; i++) {
            current[parts[i]] = current[parts[i]] || {};
            current = current[parts[i]];
          }
          current[parts[parts.length - 1]] = value;
        }
      } else if (target === 'isolated') {
        state._isolated[path] = value;
      }
    },
    getComputed: (key) => {
      // computed 값은 layoutJson의 computed 섹션에서 계산
      const computed = layoutJson.computed?.[key];
      if (computed && bindingEngine) {
        return bindingEngine.resolve(computed, {
          _local: state._local,
          _global: state._global,
        });
      }
      return undefined;
    },

    // 액션
    triggerAction: async (actionDef) => {
      if (actionDispatcher) {
        const handler = actionDispatcher.createHandler(
          actionDef,
          { _local: state._local, _global: state._global },
          { state: state._local, setState: mockSetState }
        );
        const mockEvent = {
          preventDefault: vi.fn(),
          type: 'click',
          target: null,
        } as unknown as Event;
        await handler(mockEvent);
      }
    },
    triggerEvent: async (componentId, eventType, eventData) => {
      // 컴포넌트 요소 찾기 및 이벤트 발생
      const element = document.querySelector(`[data-component-id="${componentId}"]`);
      if (element) {
        const event = new CustomEvent(eventType, { detail: eventData });
        element.dispatchEvent(event);
      }
    },

    // 모달
    openModal: (modalId) => modalStack.push(modalId),
    closeModal: () => modalStack.pop(),
    getModalStack: () => [...modalStack],

    // 토스트
    getToasts: () => [...toasts],

    // 네비게이션
    getNavigationHistory: () => [...navigationHistory],
    mockNavigate,

    // 유틸리티
    waitForDataSource: async (dataSourceId) => {
      await waitFor(() => {
        // 데이터소스 로딩 완료 확인
        const calls = mockApiRegistry.fetchSpy.mock.calls;
        const hasCall = calls.some((call: any[]) =>
          call[0]?.includes(dataSourceId)
        );
        if (!hasCall) {
          throw new Error(`데이터소스 ${dataSourceId} 로딩 대기 중`);
        }
      }, { timeout: 5000 });
    },
    waitForState: async (path, expectedValue) => {
      await waitFor(() => {
        const parts = path.split('.');
        let current: any = state;
        for (const part of parts) {
          current = current?.[part];
        }
        expect(current).toEqual(expectedValue);
      }, { timeout: 5000 });
    },

    // 레이아웃 정보
    getDataSources: () => layoutJson.data_sources || [],
    getLayoutInfo: () => ({
      name: layoutJson.layout_name,
      version: layoutJson.version,
      meta: layoutJson.meta,
    }),

    // 검증 (NEW)
    getValidationWarnings: () => [...validationWarnings],
    validateSlots: () => {
      const slotName = options.slotName || 'content';
      const components = extractComponents(layoutJson, slotName);
      let allComponents = [...components];
      if (options.includeModals && layoutJson.modals) {
        allComponents = [...allComponents, ...layoutJson.modals];
      }
      return validateSlotSystem(allComponents);
    },
    validateDataBindings: () => {
      const slotName = options.slotName || 'content';
      const components = extractComponents(layoutJson, slotName);
      let allComponents = [...components];
      if (options.includeModals && layoutJson.modals) {
        allComponents = [...allComponents, ...layoutJson.modals];
      }
      return validateDataBindingTypes(
        allComponents,
        { ...fetchedDataContext, _local: state._local, _global: state._global },
        bindingEngine
      );
    },
    assertNoValidationErrors: () => {
      const errors = validationWarnings.filter(
        w => w.type === 'data_type_mismatch' || w.type === 'missing_slotcontainer'
      );
      if (errors.length > 0) {
        const messages = errors.map(e => `[${e.type}] ${e.message}`).join('\n');
        throw new Error(`레이아웃 검증 오류:\n${messages}`);
      }
    },

    // 정리
    cleanup: doCleanup,
    user,

    // screen API 노출
    screen,
  };
}

/**
 * 레이아웃 JSON 파일 로드 (테스트 픽스처용)
 *
 * @example
 * ```typescript
 * const layoutJson = await loadLayoutFixture('admin_ecommerce_product_list');
 * const { render } = createLayoutTest(layoutJson);
 * ```
 */
export async function loadLayoutFixture(layoutName: string): Promise<any> {
  // 테스트 환경에서는 직접 import 사용
  // 실제 구현에서는 파일 시스템에서 로드
  try {
    // fixtures 디렉토리에서 로드 시도
    const fixture = await import(`../fixtures/${layoutName}.json`);
    return fixture.default || fixture;
  } catch {
    throw new Error(`레이아웃 픽스처를 찾을 수 없습니다: ${layoutName}`);
  }
}

/**
 * Mock 컴포넌트 레지스트리 생성
 *
 * 테스트에서 사용할 가상의 ComponentRegistry를 생성합니다.
 * DynamicRenderer가 사용하는 메서드들을 mock으로 제공합니다.
 *
 * @example
 * ```typescript
 * const registry = createMockComponentRegistry();
 *
 * // 컴포넌트 등록
 * registry.register('basic', 'Div', ({ children, className }) => <div className={className}>{children}</div>);
 * registry.register('composite', 'TabNavigation', TabNavigationMock);
 *
 * // 테스트에서 사용
 * const { render } = createLayoutTest(layoutJson, { componentRegistry: registry });
 * ```
 */
export interface MockComponentRegistry {
  /**
   * 컴포넌트 등록
   * @param type 컴포넌트 타입 ('basic' | 'composite' | 'layout')
   * @param name 컴포넌트 이름
   * @param component React 컴포넌트
   */
  register: (type: string, name: string, component: React.ComponentType<any>) => void;

  /**
   * 컴포넌트 조회 (DynamicRenderer가 사용)
   */
  getComponent: (name: string) => React.ComponentType<any> | null;

  /**
   * 컴포넌트 존재 여부 확인
   */
  hasComponent: (name: string) => boolean;

  /**
   * 컴포넌트 메타데이터 조회
   */
  getMetadata: (name: string) => { name: string; type: string; props?: string[] } | null;

  /**
   * 타입별 컴포넌트 목록 조회
   */
  getComponentsByType: (type: string) => string[];

  /**
   * 전체 컴포넌트 목록 조회
   */
  getAllComponents: () => string[];
}

export function createMockComponentRegistry(): MockComponentRegistry {
  const components = new Map<string, { component: React.ComponentType<any>; type: string }>();

  return {
    register(type: string, name: string, component: React.ComponentType<any>) {
      components.set(name, { component, type });
    },

    getComponent(name: string) {
      return components.get(name)?.component || null;
    },

    hasComponent(name: string) {
      return components.has(name);
    },

    getMetadata(name: string) {
      const entry = components.get(name);
      if (!entry) return null;
      return { name, type: entry.type };
    },

    getComponentsByType(type: string) {
      const result: string[] = [];
      for (const [name, entry] of components) {
        if (entry.type === type) {
          result.push(name);
        }
      }
      return result;
    },

    getAllComponents() {
      return Array.from(components.keys());
    },
  };
}

/**
 * 기본 HTML 래퍼 컴포넌트들을 포함한 Mock 레지스트리 생성
 *
 * basic 컴포넌트 (Div, Span, Button 등)와 layout 컴포넌트 (Fragment)가
 * 미리 등록된 레지스트리를 반환합니다.
 *
 * @example
 * ```typescript
 * const registry = createMockComponentRegistryWithBasics();
 *
 * // 추가 컴포넌트만 등록
 * registry.register('composite', 'TabNavigation', TabNavigationMock);
 * registry.register('composite', 'DataGrid', DataGridMock);
 * ```
 */
export function createMockComponentRegistryWithBasics(): MockComponentRegistry {
  const registry = createMockComponentRegistry();

  // Basic 컴포넌트들 (HTML 래퍼)
  const basicComponents: Record<string, React.FC<any>> = {
    Div: ({ children, className, style, ...rest }) =>
      React.createElement('div', { className, style, ...rest }, children),
    Span: ({ children, className, text, ...rest }) =>
      React.createElement('span', { className, ...rest }, children || text),
    P: ({ children, className, text, ...rest }) =>
      React.createElement('p', { className, ...rest }, children || text),
    H1: ({ children, className, text, ...rest }) =>
      React.createElement('h1', { className, ...rest }, children || text),
    H2: ({ children, className, text, ...rest }) =>
      React.createElement('h2', { className, ...rest }, children || text),
    H3: ({ children, className, text, ...rest }) =>
      React.createElement('h3', { className, ...rest }, children || text),
    H4: ({ children, className, text, ...rest }) =>
      React.createElement('h4', { className, ...rest }, children || text),
    Button: ({ children, className, text, onClick, type, disabled, ...rest }) =>
      React.createElement('button', { className, onClick, type, disabled, ...rest }, children || text),
    Input: ({ className, type, value, onChange, placeholder, disabled, readOnly, ...rest }) =>
      React.createElement('input', { className, type, value, onChange, placeholder, disabled, readOnly, ...rest }),
    Label: ({ children, className, text, htmlFor, ...rest }) =>
      React.createElement('label', { className, htmlFor, ...rest }, children || text),
    Form: ({ children, className, onSubmit, ...rest }) =>
      React.createElement('form', { className, onSubmit, ...rest }, children),
    Table: ({ children, className, ...rest }) =>
      React.createElement('table', { className, ...rest }, children),
    Thead: ({ children, className, ...rest }) =>
      React.createElement('thead', { className, ...rest }, children),
    Tbody: ({ children, className, ...rest }) =>
      React.createElement('tbody', { className, ...rest }, children),
    Tr: ({ children, className, ...rest }) =>
      React.createElement('tr', { className, ...rest }, children),
    Th: ({ children, className, text, ...rest }) =>
      React.createElement('th', { className, ...rest }, children || text),
    Td: ({ children, className, text, ...rest }) =>
      React.createElement('td', { className, ...rest }, children || text),
    Ul: ({ children, className, ...rest }) =>
      React.createElement('ul', { className, ...rest }, children),
    Li: ({ children, className, ...rest }) =>
      React.createElement('li', { className, ...rest }, children),
    A: ({ children, className, href, text, ...rest }) =>
      React.createElement('a', { className, href, ...rest }, children || text),
    Img: ({ className, src, alt, ...rest }) =>
      React.createElement('img', { className, src, alt, ...rest }),
    Nav: ({ children, className, ...rest }) =>
      React.createElement('nav', { className, ...rest }, children),
    Header: ({ children, className, ...rest }) =>
      React.createElement('header', { className, ...rest }, children),
    Footer: ({ children, className, ...rest }) =>
      React.createElement('footer', { className, ...rest }, children),
    Section: ({ children, className, ...rest }) =>
      React.createElement('section', { className, ...rest }, children),
    Article: ({ children, className, ...rest }) =>
      React.createElement('article', { className, ...rest }, children),
    Select: ({ children, className, value, onChange, disabled, ...rest }) =>
      React.createElement('select', { className, value, onChange, disabled, ...rest }, children),
    Option: ({ children, value, disabled, ...rest }) =>
      React.createElement('option', { value, disabled, ...rest }, children),
    Textarea: ({ className, value, onChange, placeholder, disabled, readOnly, rows, ...rest }) =>
      React.createElement('textarea', { className, value, onChange, placeholder, disabled, readOnly, rows, ...rest }),
  };

  // Basic 컴포넌트 등록
  for (const [name, component] of Object.entries(basicComponents)) {
    registry.register('basic', name, component);
  }

  // Layout 컴포넌트 등록
  registry.register('layout', 'Fragment', ({ children }) => React.createElement(React.Fragment, null, children));

  return registry;
}

// Re-export for convenience
export { screen, waitFor, fireEvent } from '@testing-library/react';
export { vi } from 'vitest';
