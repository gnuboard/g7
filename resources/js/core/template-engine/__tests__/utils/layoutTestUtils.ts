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
import { ParentContextProvider } from '../../ParentContextProvider';
import {
  isPlainLeftClick,
  mergeQueryIntoHref,
  mergeRelForTarget,
  resolveInternalHref,
  subscribeLocationSearch,
  useLocationSearch,
} from '../../navigationLinks';
import { evaluateComputedDefinitions } from '../../helpers/ComputedEvaluator';
// 데이터소스 initLocal·initGlobal·initIsolated 를 실제 앱과 같은 규칙으로 적용하려고 TemplateApp 의 처리 함수를 그대로 쓴다(7.1.3 WP11)
import { TemplateApp } from '../../../TemplateApp';

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
  // 재사용 액션 정의 (actionRef 로 참조)
  named_actions?: Record<string, any>;
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
  /** 현재 경로 — 실제 앱처럼 `route.path` 로 싣는다 (기본 '/') */
  routePath?: string;
  /** 시스템 활성 언어 목록 — `$locales` (기본 ['ko', 'en']) */
  locales?: string[];
  /** 템플릿이 지원하는 언어 목록 — `$templateLocales` (기본 ['ko', 'en']) */
  templateLocales?: string[];

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
 * 데이터소스 받기 결과 — 실제 앱처럼 성공한 값과 실패 기록(`_dataSourceErrors` 의 원본)을 나눈다.
 */
interface ProcessedDataSources {
  /** 데이터소스 id → 응답 본문(실패면 fallback 또는 null) */
  data: Record<string, any>;
  /** 데이터소스 id → { message, status } — 실제 앱 `_dataSourceErrors` 와 같은 모양 */
  errors: Record<string, { message: string; status: number }>;
  /** 받기를 시도한 데이터소스 id(직접 모의 포함) — waitForDataSource 판정용 */
  attempted: string[];
}

/**
 * data_sources 를 모의 API 로 받습니다.
 *
 * 응답 규칙은 하나다(7.1.3 WP11 T6): 데이터소스 id 로 직접 등록한 모의든 URL 로 찾은 모의든 `mockApi(id, { response })` 의
 * `response` 가 곧 실제 앱 데이터 컨텍스트에 실리는 **응답 본문**이다(실제 앱은 API 응답 본문 `{ data, meta, … }` 을 통째로
 * 싣는다 — 레이아웃은 `{{id.data.…}}` 로 읽는다). URL 모의는 모의 fetch 가 감싼 `{ success, data }` 를 벗겨 같은 값을 얻는다.
 *
 * 실패는 실제 앱과 같이 `{ message, status }` 로 기록하고(T5), 값은 선언한 fallback 이나 null 이다.
 *
 * @param dataSources 레이아웃 data_sources
 * @param mockApiRegistry 모의 API 레지스트리
 * @param routeParams 경로 파라미터(엔드포인트 치환)
 * @param queryParams 쿼리 파라미터
 * @returns 받은 값·실패 기록·시도한 id
 */
async function processDataSources(
  dataSources: LayoutJson['data_sources'],
  mockApiRegistry: MockApiRegistry,
  routeParams: Record<string, string> = {},
  queryParams: Record<string, string> = {}
): Promise<ProcessedDataSources> {
  const result: ProcessedDataSources = { data: {}, errors: {}, attempted: [] };

  if (!dataSources || dataSources.length === 0) {
    return result;
  }

  for (const ds of dataSources) {
    // auto_fetch가 false가 아닌 경우에만 처리 (기본값 true)
    if (ds.auto_fetch === false) {
      continue;
    }

    if ((!ds.type || ds.type === 'api') && ds.endpoint) {
      result.attempted.push(ds.id);
      const fail = (status: number, message: string) => {
        result.errors[ds.id] = { message, status };
        result.data[ds.id] = ds.fallback ?? null;
      };

      try {
        // 1. 데이터소스 id 로 직접 등록한 모의 (URL 추출 불일치 방지)
        const directMock = mockApiRegistry.mocks.get(ds.id);
        if (directMock) {
          if (directMock.error) {
            fail(directMock.error.status, directMock.error.message);
            continue;
          }
          if (directMock.delay) {
            await new Promise(resolve => setTimeout(resolve, directMock.delay));
          }
          result.data[ds.id] = directMock.response;
          continue;
        }

        // 2. 직접 mock이 없으면 URL 기반 fetchSpy 사용 — endpoint 에서 route 파라미터 치환
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

        const response = await mockApiRegistry.fetchSpy(endpoint, {
          method: ds.method || 'GET',
        });
        const json = await response.json();

        if (!response.ok) {
          fail(response.status, json?.message ?? 'Error');
          continue;
        }

        // 모의 fetch 가 감싼 `{ success, data }` 를 벗긴다 — 직접 모의의 `response` 와 같은 값
        result.data[ds.id] = json && typeof json === 'object' && 'data' in json ? json.data : json;
      } catch (error) {
        fail(0, error instanceof Error ? error.message : String(error));
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

  // 직접 부른 G7Core.toast.* 기록 — 액션 `toast` 는 엔진 내장 처리가 `_global.toasts` 에 쓴다(getToasts 가 함께 읽는다)
  const toasts: Array<{ type: string; message: string }> = [];
  const componentEventListeners = new Map<string, Set<(data?: any) => any>>();
  const navigationHistory: string[] = [];
  const validationWarnings: LayoutValidationWarning[] = [];
  let fetchedDataContext: Record<string, any> = {};
  let dataSourceErrors: Record<string, { message: string; status: number }> = {};
  let attemptedDataSources: string[] = [];
  let localInit: Record<string, any> = {};
  let isolatedInit: Record<string, any> = {};

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

  const mockSetState = vi.fn((updates: Record<string, any> | ((prev: Record<string, any>) => Record<string, any>), target: string = 'local') => {
    // 함수형 갱신 — 실제 writer(렌더러 handleLocalSetState·setGlobalState)처럼 최신 상태를 넘기고 결과를 쓴다.
    // apiCall 로딩 플래그(engine-v1.81.2)·setLocal shallow/replace 가 이 모양으로 쓴다
    if (typeof updates === 'function') {
      if (target === 'global') {
        state._global = updates(state._global);
      } else {
        state._local = updates(state._local);
      }
      return;
    }
    if (target === 'local') {
      state._local = { ...state._local, ...updates };
    } else if (target === 'global') {
      state._global = { ...state._global, ...updates };
    }
  });

  /**
   * 실제 앱 `TemplateApp.setGlobalState` 와 같은 쓰기 — 얕은 병합(또는 함수형 갱신). `_local` 키는 실제 앱처럼
   * `_global._local`(저장소 B)에 두고, 실제 앱에서 그 값이 곧 화면의 로컬 상태이므로 도구의 로컬 상태에도 싣는다
   * (컴포넌트 문맥 없는 setState local — init_actions 등 — 이 이 경로로 쓴다).
   *
   * @param updates 병합할 값 또는 이전 값을 받아 새 값을 돌려주는 함수
   */
  const applyGlobalUpdate = (updates: Record<string, any> | ((prev: Record<string, any>) => Record<string, any>)) => {
    const hadLocalKey = Object.prototype.hasOwnProperty.call(state._global, '_local');
    // 실제 앱의 globalState._local 은 화면 로컬 상태 전체다 — 도구 _global 에 그 키가 없으면 도구 로컬 상태를 기준으로 넘긴다
    const base = hadLocalKey ? state._global : { ...state._global, _local: state._local };
    let next: Record<string, any>;
    let wroteLocal: boolean;
    if (typeof updates === 'function') {
      next = { ...(updates(base) ?? base) };
      wroteLocal = next._local !== base._local;
      if (!hadLocalKey && !wroteLocal) {
        delete next._local;
      }
    } else {
      next = { ...state._global, ...updates };
      wroteLocal = Object.prototype.hasOwnProperty.call(updates ?? {}, '_local');
    }
    state._global = next;
    if (wroteLocal && next._local && typeof next._local === 'object') {
      state._local = next._local;
    }
  };

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

    // ActionDispatcher — toast·openModal·closeModal 은 엔진 내장 처리가 `_global.toasts`·`_global.modalStack` 에 쓴다.
    // 종전 도구는 같은 이름의 사용자 핸들러를 등록했지만 내장 처리가 먼저 돌아 죽은 코드였다(7.1.3 WP11 T10).
    actionDispatcher = new ActionDispatcher({
      navigate: mockNavigate,
    });

    // setState(global)·toast·openModal 등 엔진이 쓰는 전역 상태 갱신기 — 실제 앱 setGlobalState 와 같은 규칙
    actionDispatcher.setGlobalStateUpdater?.((updates: any) => applyGlobalUpdate(updates));

    // 레이아웃 최상위 named_actions 등록 (engine-v1.19.0+)
    // 미등록 시 actionRef 가 해석되지 않아 named_actions 를 쓰는 레이아웃은
    // 렌더링 테스트에서 액션이 통째로 무반응이 된다 (경고만 남고 실패하지 않는다).
    if (layoutJson.named_actions) {
      actionDispatcher.setNamedActions?.(layoutJson.named_actions);
    }

    // 글로벌 fetch 모킹
    cleanupFetch = setupGlobalFetchMock(mockApiRegistry);

    // G7Core 글로벌 객체 모킹
    (window as any).G7Core = {
      state: {
        // 운영과 같은 모양으로 돌려준다 — `G7Core.state.get()` 의 반환값이 **곧 `_global` 의 내용물**이고
        // (`TemplateApp.getGlobalState()` → `{...globalState}`), `_local` 은 그 안의 한 키다.
        // 종전 `{ _global, _local }` 모양은 engine-v1.70.0 이 `._global` 접미를 떼기 전의 계약이라,
        // ActionDispatcher 의 stale-closure 방어가 `_global` 자리에 `{_global,_local}` 을 통째로
        // 꽂아 액션 params 안의 `{{_global.*}}` 가 전부 undefined 로 평가됐다.
        // 정본: `resources/js/core/devtools/DiagnosticEngine.ts:65` 「반환값이 곧 _global 이다 — ._global 을 덧붙이지 말 것」.
        get: () => ({ ...state._global, _local: state._local }),
        set: (updates: any) => applyGlobalUpdate(updates),
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
        open: (id: string) => openModalById(id),
        close: () => closeTopModal(),
        getStack: () => [...(state._global.modalStack ?? [])],
      },
      // 링크 API — 실제 앱 G7Core.navigation 과 같은 함수(engine-v1.83.0). 템플릿 링크 컴포넌트는 코어 훅을 부르므로
      // 엔진 렌더러와 같은 React 로 그려진다(템플릿 시험 폴더의 React 사본과 섞이지 않는다)
      navigation: {
        isPlainLeftClick,
        mergeQueryIntoHref,
        subscribeLocationSearch,
        useLocationSearch,
        resolveInternalHref,
        mergeRelForTarget,
      },
      // 컴포넌트 이벤트 버스 — 운영 G7CoreGlobals.initComponentEventSystem 과 같은 계약(on → 해제 함수,
      // emit → 리스너 결과 배열, 리스너가 throw 하면 reject). 없으면 `<on listen>` 이 구독조차 하지 않아
      // emitEvent ↔ onComponentEvent 흐름을 레이아웃 테스트로 잴 수 없었다(#753).
      componentEvent: {
        on: (eventName: string, callback: (data?: any) => any) => {
          if (!componentEventListeners.has(eventName)) {
            componentEventListeners.set(eventName, new Set());
          }
          componentEventListeners.get(eventName)!.add(callback);
          return () => {
            componentEventListeners.get(eventName)?.delete(callback);
          };
        },
        emit: async (eventName: string, data?: any): Promise<any[]> => {
          const listeners = componentEventListeners.get(eventName);
          if (!listeners || listeners.size === 0) {
            return [];
          }
          return Promise.all(Array.from(listeners).map(async (callback) => callback(data)));
        },
        off: (eventName: string) => {
          componentEventListeners.delete(eventName);
        },
        clear: () => {
          componentEventListeners.clear();
        },
        listenerCount: (eventName: string) => componentEventListeners.get(eventName)?.size ?? 0,
      },
    };
  }

  /** 모달 열기 — 실제 엔진처럼 `_global.modalStack` 에 쌓는다 */
  function openModalById(modalId: string) {
    const stack: string[] = state._global.modalStack ?? [];
    state._global = { ...state._global, modalStack: stack.includes(modalId) ? stack : [...stack, modalId] };
  }

  /** 맨 위 모달 닫기 */
  function closeTopModal() {
    const stack: string[] = state._global.modalStack ?? [];
    state._global = { ...state._global, modalStack: stack.slice(0, -1) };
  }

  /**
   * 실제 앱(TemplateApp 의 첫 렌더 컨텍스트 + template-engine 전역 변수)과 같은 모양의 데이터 컨텍스트를 만든다(7.1.3 WP11 T1~T8).
   *
   * @returns 렌더·액션에 넘길 데이터 컨텍스트
   */
  function buildDataContext(): Record<string, any> {
    const routeParams = options.routeParams || {};
    const definesData = layoutJson.defines || {};

    const context: Record<string, any> = {
      // template-engine createGlobalVariables (낮은 우선순위)
      $locale: options.locale || 'ko',
      $locales: options.locales || ['ko', 'en'],
      $templateLocales: options.templateLocales || ['ko', 'en'],
      $templateId: options.templateId || 'test-template',
      ...options.initialData, // fallback 데이터
      ...routeParams,
      ...fetchedDataContext, // fetch된 데이터가 우선
      route: { ...routeParams, path: options.routePath || '/' },
      query: options.queryParams || {},
      _global: {
        ...state._global,
        // 레이아웃 경고 — 베이스 레이아웃의 LayoutWarnings 컴포넌트가 쓴다
        layoutWarnings: (layoutJson as any).warnings || [],
      },
      _local: state._local,
      _globalSetState: (updates: any) => applyGlobalUpdate(updates),
      _dataSourceErrors: Object.keys(dataSourceErrors).length > 0 ? dataSourceErrors : undefined,
      _localInit: Object.keys(localInit).length > 0 ? localInit : undefined,
      _isolatedInit: Object.keys(isolatedInit).length > 0 ? isolatedInit : undefined,
      _defines: Object.keys(definesData).length > 0 ? definesData : undefined,
    };

    // computed — 렌더·액션 경로와 같은 공용 평가기(TemplateApp.calculateComputed 와 같은 호출)
    if (layoutJson.computed && Object.keys(layoutJson.computed).length > 0) {
      context._computed = evaluateComputedDefinitions(layoutJson.computed, context, new DataBindingEngine(), {
        previous: {},
        onError: 'undefined',
      });
      context._computedDefinitions = layoutJson.computed;
    }

    return context;
  }

  /**
   * 데이터소스 initLocal·initGlobal·initIsolated 를 실제 앱 함수(TemplateApp.processInitOptions)로 적용한다(T7).
   */
  function applyDataSourceInit() {
    localInit = {};
    isolatedInit = {};
    const dataSources = layoutJson.data_sources || [];
    if (dataSources.length > 0) {
      // 처리 함수는 `this.globalState`(initGlobal 대상)만 쓴다 — 저장소 B(`_global._local`)는 있던 그대로 둔다
      const hadLocalKey = Object.prototype.hasOwnProperty.call(state._global, '_local');
      const app = Object.create(TemplateApp.prototype) as any;
      app.globalState = hadLocalKey ? { ...state._global } : { ...state._global, _local: state._local };
      app.processInitOptions(dataSources, fetchedDataContext, localInit, isolatedInit, state._local);
      const nextGlobal = { ...app.globalState };
      if (!hadLocalKey) {
        delete nextGlobal._local;
      }
      state._global = nextGlobal;
    }

    // 실제 앱: 데이터소스 initLocal 값은 `_localInit` 으로 렌더러에 넘어가 로컬 상태에 병합된다
    const { _forceLocalInit: _ignoredForce, ...localValues } = localInit;
    if (Object.keys(localValues).length > 0) {
      state._local = { ...state._local, ...localValues };
    }

    // 레이아웃 initIsolated 는 데이터소스 값이 없는 키만 채운다(TemplateApp 과 같은 순서)
    for (const [key, value] of Object.entries(layoutInitIsolated)) {
      if (isolatedInit[key] === undefined) {
        isolatedInit[key] = JSON.parse(JSON.stringify(value));
      }
    }
  }

  /**
   * 받기 실패한 데이터소스의 errorHandling(상태 코드별·default)·onError 를 실행한다(T5).
   *
   * @param dataContext 실행 문맥
   */
  async function runDataSourceErrorHandlers(dataContext: Record<string, any>) {
    for (const ds of layoutJson.data_sources || []) {
      const failure = dataSourceErrors[ds.id];
      if (!failure) continue;
      const handling = (ds as any).errorHandling;
      const handlerDef = handling?.[String(failure.status)] ?? handling?.default ?? (ds as any).onError;
      if (!handlerDef) continue;
      const defs = Array.isArray(handlerDef) ? handlerDef : [handlerDef];
      for (const def of defs) {
        const handler = actionDispatcher.createHandler(
          { type: 'click', ...def } as any,
          { ...dataContext, error: failure },
        );
        await handler(new Event('error'));
      }
    }
  }

  /**
   * init_actions 를 실제 ActionDispatcher 로 실행한다(T9) — TemplateApp.executeInitActions 와 같이 항목마다 최신 상태로 문맥을 갱신.
   *
   * @param baseContext 시작 문맥
   */
  async function runInitActions(baseContext: Record<string, any>) {
    const initActions = layoutJson.initActions || layoutJson.init_actions || [];
    let currentContext = { ...baseContext };
    for (const initAction of initActions) {
      try {
        const handler = actionDispatcher.createHandler({ ...initAction, type: 'click' } as any, currentContext);
        await handler(new Event('init'));
      } catch {
        // 실제 앱도 init 항목 하나의 실패로 화면 진입을 막지 않는다(로그만)
      }
      currentContext = { ...currentContext, _global: { ...state._global }, _local: state._local };
    }
  }

  /**
   * 렌더 트리 — 실제 앱처럼 일반 컴포넌트 다음에 모달을 ParentContextProvider 로 감싸 그리고, `_global.modalStack` 으로 열림을 정한다(T10).
   *
   * @param dataContext 데이터 컨텍스트
   * @returns 렌더할 React 요소
   */
  function buildTree(dataContext: Record<string, any>): React.ReactElement {
    const translationContext: TranslationContext = {
      templateId: options.templateId || 'test-template',
      locale: options.locale || 'ko',
    };
    const slotName = options.slotName || 'content';
    const components = extractComponents(layoutJson, slotName);
    const rendererProps = { translationContext, registry, bindingEngine, translationEngine, actionDispatcher };

    const modalStack: string[] = dataContext._global?.modalStack || [];
    const layoutContextStack: Array<{ dataContext?: Record<string, any> }> = (window as any).__g7LayoutContextStack || [];
    const parentDataContext = layoutContextStack[layoutContextStack.length - 1]?.dataContext;
    const modals = options.includeModals && layoutJson.modals ? layoutJson.modals : [];

    return React.createElement(
      React.Fragment,
      null,
      React.createElement(DynamicRenderer, {
        key: 'root',
        componentDef: { id: 'root', type: 'layout', name: 'Fragment', children: components } as ComponentDefinition,
        dataContext,
        isRootRenderer: true,
        ...rendererProps,
      } as any),
      React.createElement(
        ParentContextProvider,
        { key: '__modal_parent_context_provider' },
        modals.map((modalDef: any) => {
          const stackIndex = modalStack.indexOf(modalDef.id);
          return React.createElement(DynamicRenderer, {
            key: `modal_${modalDef.id}_renderer`,
            componentDef: {
              ...modalDef,
              props: {
                ...modalDef.props,
                isOpen: stackIndex >= 0 || dataContext._global?.activeModal === modalDef.id,
                style: { ...modalDef.props?.style, zIndex: stackIndex >= 0 ? 50 + stackIndex : 50 },
                onClose: actionDispatcher.createHandler({ type: 'click', handler: 'closeModal' } as any, dataContext),
              },
            },
            dataContext,
            parentDataContext,
            ...rendererProps,
          } as any);
        }),
      ),
    );
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

    // 인증 정보를 상태에 추가
    if (options.auth) {
      state._global.auth = {
        isAuthenticated: options.auth.isAuthenticated,
        user: options.auth.user || null,
        authType: options.auth.authType || 'user',
      };
    }

    // data_sources 처리: mockApi로 등록된 데이터를 받는다 (실패는 `_dataSourceErrors` 원본으로 기록)
    const processed = await processDataSources(
      layoutJson.data_sources,
      mockApiRegistry,
      options.routeParams || {},
      options.queryParams || {}
    );
    fetchedDataContext = processed.data;
    dataSourceErrors = processed.errors;
    attemptedDataSources = processed.attempted;

    // 실행 순서(TemplateApp): 레이아웃 init* → 데이터소스 init* → 오류 처리기 → init_actions → 렌더
    applyDataSourceInit();
    await runDataSourceErrorHandlers(buildDataContext());
    await runInitActions(buildDataContext());

    const dataContext = buildDataContext();
    const allComponents = [
      ...extractComponents(layoutJson, options.slotName || 'content'),
      ...(options.includeModals && layoutJson.modals ? layoutJson.modals : []),
    ];

    // 슬롯 시스템 검증
    validationWarnings.push(...validateSlotSystem(allComponents));

    // 데이터 바인딩 타입 검증
    validationWarnings.push(...validateDataBindingTypes(allComponents, dataContext, bindingEngine));

    renderResult = render(buildTree(dataContext));

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

    renderResult.rerender(buildTree(buildDataContext()));

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
    componentEventListeners.clear();
    navigationHistory.length = 0;
    validationWarnings.length = 0;
    fetchedDataContext = {};
    dataSourceErrors = {};
    attemptedDataSources = [];
    localInit = {};
    isolatedInit = {};
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
      // 실제 앱과 같은 문맥(데이터소스·route·query·_local·_global)으로 계산한 `_computed` 값(T1)
      if (!layoutJson.computed?.[key]) {
        return undefined;
      }
      return buildDataContext()._computed?.[key];
    },

    // 액션 — 실제 디스패치처럼 렌더 데이터 컨텍스트 전체를 넘긴다
    triggerAction: async (actionDef) => {
      if (actionDispatcher) {
        const handler = actionDispatcher.createHandler(
          actionDef,
          buildDataContext(),
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

    // 모달 — 실제 엔진과 같은 `_global.modalStack`
    openModal: (modalId) => openModalById(modalId),
    closeModal: () => closeTopModal(),
    getModalStack: () => [...(state._global.modalStack ?? [])],

    // 토스트 — 액션 toast(엔진 내장 → `_global.toasts`) + 직접 부른 G7Core.toast.*
    getToasts: () => [
      ...(state._global.toasts ?? []).map((t: { type?: string; message?: string }) => ({ type: t.type ?? 'info', message: t.message ?? '' })),
      ...toasts,
    ],

    // 네비게이션
    getNavigationHistory: () => [...navigationHistory],
    mockNavigate,

    // 유틸리티
    waitForDataSource: async (dataSourceId) => {
      await waitFor(() => {
        // 데이터소스 로딩 완료 확인 — 직접 모의(id)도 받기 시도로 친다(T6)
        const calls = mockApiRegistry.fetchSpy.mock.calls;
        const hasCall = attemptedDataSources.includes(dataSourceId) || calls.some((call: any[]) =>
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
      return validateDataBindingTypes(allComponents, buildDataContext(), bindingEngine);
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
