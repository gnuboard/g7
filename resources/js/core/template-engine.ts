/**
 * 그누보드7 템플릿 엔진 진입점
 *
 * 모든 코어 엔진 모듈을 통합하고 외부에서 사용할 수 있는 공개 API를 제공합니다.
 *
 * @packageDocumentation
 */

import React, { startTransition } from 'react';
import ReactDOM from 'react-dom/client';
import { ComponentRegistry } from './template-engine/ComponentRegistry';
import { DataBindingEngine } from './template-engine/DataBindingEngine';
import { TranslationEngine, TranslationContext } from './template-engine/TranslationEngine';
import { ActionDispatcher, setActionDispatcherInstance } from './template-engine/ActionDispatcher';
import DynamicRenderer from './template-engine/DynamicRenderer';
import { LayoutLoader } from './template-engine/LayoutLoader';
import { TransitionProvider } from './template-engine/TransitionContext';
import { TranslationProvider } from './template-engine/TranslationContext';
import { transitionManager } from './template-engine/TransitionManager';
import { ResponsiveProvider } from './template-engine/ResponsiveContext';
import { responsiveManager } from './template-engine/ResponsiveManager';
import { SlotProvider } from './template-engine/SlotContext';
import { DataSourceManager } from './template-engine/DataSourceManager';
import { ModalDataSourceWrapper } from './template-engine/ModalDataSourceWrapper';
import { ParentContextProvider } from './template-engine/ParentContextProvider';
import { TemplateNotFoundError } from './template-engine/TemplateEngineError';
import { TemplateApp, initTemplateApp } from './TemplateApp';
import { createLogger } from './utils/Logger';
import { webSocketManager } from './websocket/WebSocketManager';
import { initializeG7CoreGlobals, initDevToolsAPI } from './template-engine/G7CoreGlobals';

const logger = createLogger('TemplateEngine');

/**
 * 템플릿 메타데이터 인터페이스
 */
interface TemplateMetadata {
  identifier: string;
  locales: string[];
  name: Record<string, string>;
  description: Record<string, string>;
  version: string;
  type: string;
}

/**
 * 템플릿 엔진 상태 인터페이스
 */
interface TemplateEngineState {
  templateId: string | null;
  locale: string;
  isInitialized: boolean;
  reactRoot: ReactDOM.Root | null;
  containerId: string | null;
  currentLayoutJson: any | null;
  currentDataContext: Record<string, any>;
  translationContext: TranslationContext;
  registry: ComponentRegistry | null;
  bindingEngine: DataBindingEngine | null;
  translationEngine: TranslationEngine | null;
  actionDispatcher: ActionDispatcher | null;
  templateMetadata: TemplateMetadata | null;
}

/**
 * 템플릿 엔진 초기화 옵션
 */
interface InitOptions {
  templateId: string;
  templateType?: string;
  locale?: string;
  debug?: boolean;
  /** 확장 기능 캐시 버전 (모듈/플러그인 활성화 시 갱신됨) */
  cacheVersion?: number;
}

/**
 * 렌더링 옵션
 */
interface RenderOptions {
  containerId: string;
  layoutJson: any;
  dataContext?: Record<string, any>;
  translationContext?: TranslationContext;
}

/**
 * 데이터 업데이트 옵션
 */
interface UpdateOptions {
  /**
   * true인 경우 startTransition 없이 즉시 동기적으로 렌더링합니다.
   * 드래그 앤 드롭 후 순서 변경 등 즉각적인 UI 반영이 필요한 경우 사용합니다.
   * 기본값: false (startTransition 사용)
   */
  sync?: boolean;
}

/**
 * 템플릿 엔진 공개 API
 */
interface TemplateEngineAPI {
  initTemplateEngine: (options: InitOptions) => Promise<void>;
  renderTemplate: (options: RenderOptions) => Promise<void>;
  updateTemplateData: (data: Record<string, any>) => void;
  destroyTemplate: () => void;
  getState: () => Readonly<TemplateEngineState>;
}

/**
 * 전역 상태
 */
const state: TemplateEngineState = {
  templateId: null,
  locale: 'ko',
  isInitialized: false,
  reactRoot: null,
  containerId: null,
  currentLayoutJson: null,
  currentDataContext: {},
  translationContext: {
    templateId: '',
    locale: 'ko',
  },
  registry: null,
  bindingEngine: null,
  translationEngine: null,
  actionDispatcher: null,
  templateMetadata: null,
};

/**
 * 디버그 모드 플래그 (ModalDataSourceWrapper 등에서 참조)
 */
let debugMode = false;

/**
 * React가 준비되기 전에 도착한 데이터 업데이트를 저장하는 큐
 * renderTemplate 완료 후 순차적으로 적용됨
 */
const pendingDataUpdates: Array<{ data: Record<string, any>; options?: UpdateOptions }> = [];

/**
 * 템플릿 메타데이터 로드
 *
 * 템플릿 컴포넌트 번들에 포함된 메타데이터를 가져옵니다.
 * API 호출 없이 전역 변수에서 직접 접근하여 성능을 개선합니다.
 *
 * @param templateId - 템플릿 ID
 * @returns 템플릿 메타데이터
 */
async function loadTemplateMetadata(templateId: string): Promise<TemplateMetadata> {
  try {
    logger.log('템플릿 메타데이터 로드 중...', templateId);

    // 템플릿 컴포넌트 번들의 전역 변수명 생성
    // 예: 'sirsoft-admin_basic' → 'SirsoftAdminBasic'
    const globalVarName = templateId
      .split('-')
      .map(part =>
        part
          .split('_')
          .map(subPart => subPart.charAt(0).toUpperCase() + subPart.slice(1))
          .join('')
      )
      .join('');

    // 전역 변수에서 메타데이터 가져오기
    const templateBundle = (window as any)[globalVarName];

    if (templateBundle?.templateMetadata) {
      logger.log('템플릿 메타데이터 로드 완료 (전역 변수)', templateBundle.templateMetadata);
      return templateBundle.templateMetadata;
    }

    // 폴백: 템플릿 번들에 메타데이터가 없는 경우 경고
    logger.warn(
      `템플릿 번들에 메타데이터가 없습니다. 전역 변수: ${globalVarName}`
    );

    // 기본값 반환
    return {
      identifier: templateId,
      locales: ['ko', 'en'],
      name: { ko: templateId, en: templateId },
      description: { ko: '', en: '' },
      version: '1.0.0',
      type: 'admin',
    };
  } catch (error) {
    logger.error('템플릿 메타데이터 로드 실패', error);
    // 기본값 반환
    return {
      identifier: templateId,
      locales: ['ko', 'en'],
      name: { ko: templateId, en: templateId },
      description: { ko: '', en: '' },
      version: '1.0.0',
      type: 'admin',
    };
  }
}

/**
 * 전역 변수 생성
 *
 * 템플릿 엔진 기획서에 명시된 전역 변수를 생성합니다:
 * - $locale: 현재 언어 코드 (예: 'ko', 'en')
 * - $locales: 지원하는 언어 목록 (예: ['ko', 'en'])
 * - $user: 현재 로그인한 사용자 정보 (추후 구현)
 * - $auth: 인증 상태 (추후 구현)
 *
 * @returns 전역 변수 객체
 */
function createGlobalVariables(): Record<string, any> {
  return {
    $locale: state.locale,
    $locales: state.templateMetadata?.locales || ['ko', 'en'],
    $templateId: state.templateId,
    // 추후 추가 예정:
    // $user: getCurrentUser(),
    // $auth: getAuthState(),
  };
}

/**
 * 템플릿 엔진 초기화
 *
 * 모든 엔진 인스턴스를 생성하고 템플릿 컴포넌트를 로드합니다.
 *
 * @param options - 초기화 옵션
 * @throws {Error} 이미 초기화된 경우 또는 초기화 실패 시
 */
async function initTemplateEngine(options: InitOptions): Promise<void> {
  try {
    logger.log('템플릿 엔진 초기화 시작', options);

    // 이미 초기화된 경우 에러
    if (state.isInitialized) {
      throw new Error('템플릿 엔진이 이미 초기화되었습니다. destroyTemplate()을 먼저 호출하세요.');
    }

    // 옵션 검증
    if (!options.templateId) {
      throw new TemplateNotFoundError();
    }

    // 디버그 모드 설정
    debugMode = options.debug ?? false;

    // G7Config에 debug 모드 설정 (DevTools에서 참조)
    if (typeof window !== 'undefined') {
      if (!(window as any).G7Config) {
        (window as any).G7Config = {};
      }
      (window as any).G7Config.debug = debugMode;
    }

    // DevTools API 초기화 (debug 옵션 설정 후)
    initDevToolsAPI();

    // 상태 업데이트
    state.templateId = options.templateId;
    state.locale = options.locale || 'ko';

    // 엔진 인스턴스 생성
    logger.log('엔진 인스턴스 생성 중...');

    state.registry = ComponentRegistry.getInstance();
    state.bindingEngine = new DataBindingEngine();
    state.translationEngine = TranslationEngine.getInstance();

    // TranslationEngine에 캐시 버전 설정
    if (options.cacheVersion !== undefined && options.cacheVersion > 0) {
      state.translationEngine.setCacheVersion(options.cacheVersion);
    }

    // TranslationContext 초기화
    state.translationContext = {
      templateId: options.templateId,
      locale: state.locale,
    };

    // ActionDispatcher에 TranslationEngine과 TranslationContext 전달
    state.actionDispatcher = new ActionDispatcher(
      {},
      state.translationEngine,
      state.translationContext
    );

    // ActionDispatcher 싱글톤 인스턴스로 설정
    // renderItemChildren 등에서 getActionDispatcher()를 통해 동일한 인스턴스를 사용할 수 있도록 함
    setActionDispatcherInstance(state.actionDispatcher);

    // 다국어 파일 병렬 로드
    logger.log('다국어 파일 로드 중...', options.templateId, state.locale);

    const fallbackLocale = 'en';
    const translationPromises = [
        state.translationEngine
            .loadTranslations(options.templateId, state.locale)
            .then(() => {
                logger.log(`다국어 파일 로드 완료: ${state.locale}`);
            })
            .catch((error) => {
                logger.warn(
                    `다국어 파일 로드 실패 (${state.locale}):`,
                    error instanceof Error ? error.message : error
                );
            }),
    ];

    // 폴백 로케일 로드 (기본 로케일과 다른 경우에만)
    if (state.locale !== fallbackLocale) {
        translationPromises.push(
            state.translationEngine
                .loadTranslations(options.templateId, fallbackLocale)
                .then(() => {
                    logger.log(`폴백 다국어 파일 로드 완료: ${fallbackLocale}`);
                })
                .catch((error) => {
                    logger.warn(
                        `폴백 다국어 파일 로드 실패 (${fallbackLocale}):`,
                        error instanceof Error ? error.message : error
                    );
                })
        );
    }

    // 모든 다국어 파일 로드 대기 (병렬 실행)
    await Promise.allSettled(translationPromises);

    // 템플릿 메타데이터 로드
    logger.log('템플릿 메타데이터 로드 중...', options.templateId);
    state.templateMetadata = await loadTemplateMetadata(options.templateId);

    // ComponentRegistry 로딩은 TemplateApp에서 병렬로 처리됨
    // (성능 최적화를 위해 routes.json과 함께 병렬 로드)

    // 초기화 완료
    state.isInitialized = true;

    logger.log('템플릿 엔진 초기화 완료 (ComponentRegistry는 별도 로드)');
  } catch (error) {
    logger.error('템플릿 엔진 초기화 실패', error);

    // 초기화 실패 시 상태 초기화
    state.isInitialized = false;
    state.registry = null;
    state.bindingEngine = null;
    state.translationEngine = null;
    state.actionDispatcher = null;

    throw error;
  }
}

/**
 * 템플릿 렌더링
 *
 * 레이아웃 JSON을 기반으로 React 컴포넌트를 렌더링합니다.
 * 편집 모드(URL에 mode=edit 파라미터 존재)인 경우 WysiwygEditor로 래핑하여 렌더링합니다.
 *
 * @param options - 렌더링 옵션
 * @throws {Error} 초기화되지 않은 경우 또는 렌더링 실패 시
 */
async function renderTemplate(options: RenderOptions): Promise<void> {
  try {
    logger.log('템플릿 렌더링 시작', options);

    // 초기화 확인
    if (!state.isInitialized) {
      throw new Error('템플릿 엔진이 초기화되지 않았습니다. initTemplateEngine()을 먼저 호출하세요.');
    }

    // 옵션 검증
    if (!options.containerId) {
      throw new Error('containerId는 필수입니다.');
    }

    if (!options.layoutJson) {
      throw new Error('layoutJson은 필수입니다.');
    }

    // DOM 컨테이너 확인
    const container = document.getElementById(options.containerId);
    if (!container) {
      throw new Error(`컨테이너를 찾을 수 없습니다: #${options.containerId}`);
    }

    // 전역 변수 생성
    const globalVariables = createGlobalVariables();

    // 상태 업데이트 (전역 변수를 dataContext에 병합)
    state.containerId = options.containerId;
    state.currentLayoutJson = options.layoutJson;
    state.currentDataContext = {
      ...globalVariables,           // 전역 변수 (낮은 우선순위)
      ...(options.dataContext || {}), // 사용자 제공 데이터 (높은 우선순위)
    };
    state.translationContext = options.translationContext || {
      templateId: state.templateId || '',
      locale: state.locale,
    };

    // React Root 생성 또는 재사용
    if (!state.reactRoot) {
      logger.log('React Root 생성');
      state.reactRoot = ReactDOM.createRoot(container);
    }

    // 편집 모드 감지 (URL 쿼리 파라미터 확인)
    const isEditMode = checkEditMode();
    logger.log('편집 모드:', isEditMode);

    // 편집 모드인 경우 WysiwygEditor로 렌더링
    if (isEditMode) {
      await renderWithWysiwygEditor(options);
      return;
    }

    // 레이아웃 JSON의 components 배열 가져오기
    const components = options.layoutJson.components || [];

    if (components.length === 0) {
      logger.warn('렌더링할 컴포넌트가 없습니다.');
      return;
    }

    // 레이아웃 JSON의 modals 배열 가져오기
    const modals = options.layoutJson.modals || [];
    logger.log('modals 배열:', modals);
    logger.log('modals 개수:', modals.length);

    // 루트 컴포넌트 렌더링 (TransitionProvider, ResponsiveProvider로 래핑하여 전환/반응형 상태 전파)
    logger.log('DynamicRenderer로 렌더링 시작');

    state.reactRoot.render(
      React.createElement(
        TranslationProvider,
        {
          translationEngine: state.translationEngine!,
          translationContext: state.translationContext,
        },
        React.createElement(
          TransitionProvider,
          null,
          React.createElement(
            ResponsiveProvider,
            null,
            React.createElement(
              SlotProvider,
              null,
              [
                // 일반 컴포넌트 렌더링
                ...components.map((componentDef: any, index: number) => {
                  // @since engine-v1.24.5 레이아웃 이름을 key에 포함하여
                  // SPA 네비게이션 시 동일 base layout 공유 페이지 간 React 강제 remount
                  // @since engine-v1.24.8 _fromBase 컴포넌트는 stable key → 보존(update)
                  const layoutKey = state.currentLayoutJson?.layout_name || '';
                  return React.createElement(DynamicRenderer, {
                    key: (layoutKey && !componentDef._fromBase) ? `${componentDef.id}_${layoutKey}` : componentDef.id,
                    componentDef,
                    dataContext: state.currentDataContext,
                    translationContext: state.translationContext,
                    registry: state.registry!,
                    bindingEngine: state.bindingEngine!,
                    translationEngine: state.translationEngine!,
                    actionDispatcher: state.actionDispatcher!,
                    isRootRenderer: index === 0,
                    layoutKey,
                  });
                }),
                // 모달 렌더링 (ParentContextProvider로 감싸서 $parent._local 변경 시 모달만 리렌더링)
                // modalStack 상태에 따라 isOpen 및 onClose 자동 주입
                // 멀티 모달(중첩 모달) 지원: 스택에 있는 모든 모달이 렌더링됨
                // data_sources가 정의된 모달은 ModalDataSourceWrapper로 감싸서 열릴 때 API 호출
                React.createElement(
                  ParentContextProvider,
                  { key: '__modal_parent_context_provider' },
                  modals.map((modalDef: any) => {
                  const modalStack = state.currentDataContext._global?.modalStack || [];
                const isInStack = modalStack.includes(modalDef.id);
                // 하위 호환성: modalStack이 없으면 activeModal 사용
                const isOpen = isInStack || state.currentDataContext._global?.activeModal === modalDef.id;
                // z-index는 스택 내 위치에 따라 동적으로 결정
                const stackIndex = modalStack.indexOf(modalDef.id);
                const zIndex = stackIndex >= 0 ? 50 + stackIndex : 50;

                // 부모 컨텍스트 가져오기 ($parent 바인딩 지원)
                // 모달 스택에서 현재 모달의 위치를 기반으로 부모 컨텍스트를 찾음
                const layoutContextStack: Array<{
                  state: Record<string, any>;
                  setState: (updates: any) => void;
                  dataContext?: Record<string, any>;
                }> = (window as any).__g7LayoutContextStack || [];
                // 스택의 마지막 항목이 모달을 연 시점의 부모 컨텍스트
                const parentContextEntry = layoutContextStack[layoutContextStack.length - 1];
                const parentDataContext = parentContextEntry?.dataContext;

                // 모달 렌더러 생성
                const modalRenderer = React.createElement(DynamicRenderer, {
                  key: `modal_${modalDef.id}_renderer`,
                  componentDef: {
                    ...modalDef,
                    props: {
                      ...modalDef.props,
                      isOpen,
                      // z-index를 스택 위치에 따라 설정
                      style: {
                        ...modalDef.props?.style,
                        zIndex,
                      },
                      // onClose는 closeModal 액션으로 연결
                      onClose: state.actionDispatcher?.createHandler(
                        { type: 'click', handler: 'closeModal' },
                        state.currentDataContext
                      ),
                    },
                  },
                  dataContext: state.currentDataContext,
                  translationContext: state.translationContext,
                  registry: state.registry!,
                  bindingEngine: state.bindingEngine!,
                  translationEngine: state.translationEngine!,
                  actionDispatcher: state.actionDispatcher!,
                  // $parent 바인딩을 위해 부모 데이터 컨텍스트 전달
                  parentDataContext,
                });

                // data_sources가 있으면 ModalDataSourceWrapper로 감싸기
                if (modalDef.data_sources && modalDef.data_sources.length > 0) {
                  return React.createElement(ModalDataSourceWrapper, {
                    key: `modal_${modalDef.id}`,
                    isOpen,
                    modalId: modalDef.id,
                    dataSources: modalDef.data_sources,
                    dataContext: state.currentDataContext,
                    globalStateUpdater: state.actionDispatcher?.getGlobalStateUpdater(),
                    bindingEngine: state.bindingEngine!,
                    debug: debugMode,
                    children: modalRenderer,
                  });
                }

                return modalRenderer;
              })
              ),  // ParentContextProvider 종료
              ]
            )  // SlotProvider 종료
          )  // ResponsiveProvider 종료
        )  // TransitionProvider 종료
      )  // TranslationProvider 종료
    );

    logger.log('템플릿 렌더링 완료');

    // React 준비 전에 큐에 저장된 데이터 업데이트 적용
    if (pendingDataUpdates.length > 0) {
      logger.log(`대기 중인 데이터 업데이트 ${pendingDataUpdates.length}건 적용`);
      const updates = [...pendingDataUpdates];
      pendingDataUpdates.length = 0; // 큐 비우기

      // 큐에 있는 모든 데이터를 순차적으로 적용
      for (const { data, options: updateOptions } of updates) {
        updateTemplateData(data, updateOptions);
      }
    }
  } catch (error) {
    logger.error('템플릿 렌더링 실패', error);
    throw error;
  }
}

/**
 * 템플릿 데이터 업데이트
 *
 * 현재 렌더링된 템플릿의 데이터 컨텍스트를 업데이트하고 재렌더링합니다.
 *
 * @param data - 업데이트할 데이터
 * @param options - 업데이트 옵션 (sync: true면 startTransition 없이 즉시 렌더링)
 * @throws {Error} 초기화되지 않은 경우 또는 렌더링되지 않은 경우
 */
function updateTemplateData(data: Record<string, any>, options?: UpdateOptions): void {
  try {
    logger.log('템플릿 데이터 업데이트 시작', Object.keys(data));

    // 편집 모드일 때는 일반 렌더링 업데이트 건너뛰기
    // WysiwygEditor가 자체적으로 상태를 관리하므로 updateTemplateData가 간섭하면 안 됨
    if (checkEditMode()) {
      logger.log('편집 모드에서는 updateTemplateData를 건너뜁니다.');
      return;
    }

    // 초기화 확인
    if (!state.isInitialized) {
      throw new Error('템플릿 엔진이 초기화되지 않았습니다.');
    }

    // 렌더링 확인 - React가 준비되지 않았으면 큐에 저장
    if (!state.reactRoot || !state.currentLayoutJson) {
      logger.log('React 미준비 - 데이터 업데이트 큐잉:', Object.keys(data));
      pendingDataUpdates.push({ data, options });
      return;
    }

    // 전역 변수 재생성
    const globalVariables = createGlobalVariables();

    // 데이터 병합 (전역 변수 유지)
    // _global 객체는 명시적으로 깊은 병합 수행
    const mergedGlobalState = {
      ...(state.currentDataContext._global || {}),
      ...(data._global || {}),
    };

    state.currentDataContext = {
      ...globalVariables,           // 전역 변수 (낮은 우선순위)
      ...state.currentDataContext,  // 기존 데이터
      ...data,                       // 새 데이터 (높은 우선순위)
      _global: mergedGlobalState,   // _global은 명시적으로 깊은 병합된 값 사용
    };

    // _local 및 _computed는 _global의 값을 canonical source로 동기화
    // SPA 네비게이션 시 이전 페이지의 stale _local/_computed가 top-level에 잔존하는 것을 방지
    // (renderTemplate → updateTemplateData 트리 구조 통일 후 React가 컴포넌트를 보존하면서 발생)
    if (mergedGlobalState._local !== undefined) {
      state.currentDataContext._local = mergedGlobalState._local;
    }
    if (mergedGlobalState._computed !== undefined) {
      state.currentDataContext._computed = mergedGlobalState._computed;
    }

    const components = state.currentLayoutJson.components || [];
    const modals = state.currentLayoutJson.modals || [];
    logger.log('updateTemplateData - modals 배열:', modals);
    logger.log('updateTemplateData - modals 개수:', modals.length);
    logger.log('updateTemplateData - activeModal:', state.currentDataContext._global?.activeModal);

    // 렌더링 함수 정의 (startTransition 유무에 따라 재사용)
    const doRender = () => {
      state.reactRoot!.render(
        React.createElement(
          TranslationProvider,
          {
            translationEngine: state.translationEngine!,
            translationContext: state.translationContext,
          },
          React.createElement(
            TransitionProvider,
            null,
            React.createElement(
              ResponsiveProvider,
              null,
              React.createElement(
                SlotProvider,
                null,
                [
                  // 일반 컴포넌트 렌더링
                  ...components.map((componentDef: any, index: number) => {
                    // @since engine-v1.24.5 레이아웃 이름을 key에 포함하여
                    // SPA 네비게이션 시 동일 base layout 공유 페이지 간 React 강제 remount
                    // @since engine-v1.24.8 _fromBase 컴포넌트는 stable key → 보존(update)
                    const layoutKey = state.currentLayoutJson?.layout_name || '';
                    return React.createElement(DynamicRenderer, {
                      key: (layoutKey && !componentDef._fromBase) ? `${componentDef.id}_${layoutKey}` : componentDef.id,
                      componentDef,
                      dataContext: state.currentDataContext,
                      translationContext: state.translationContext,
                      registry: state.registry!,
                      bindingEngine: state.bindingEngine!,
                      translationEngine: state.translationEngine!,
                      actionDispatcher: state.actionDispatcher!,
                      isRootRenderer: index === 0,
                      layoutKey,
                    });
                  }),
                  // 모달 렌더링 (ParentContextProvider로 감싸서 $parent._local 변경 시 모달만 리렌더링)
                  // modalStack 상태에 따라 isOpen 및 onClose 자동 주입
                  // 멀티 모달(중첩 모달) 지원: 스택에 있는 모든 모달이 렌더링됨
                  // data_sources가 정의된 모달은 ModalDataSourceWrapper로 감싸서 열릴 때 API 호출
                  React.createElement(
                    ParentContextProvider,
                    { key: '__modal_parent_context_provider_update' },
                    modals.map((modalDef: any) => {
                    const modalStack = state.currentDataContext._global?.modalStack || [];
                    const isInStack = modalStack.includes(modalDef.id);
                    // 하위 호환성: modalStack이 없으면 activeModal 사용
                    const isOpen = isInStack || state.currentDataContext._global?.activeModal === modalDef.id;
                    // z-index는 스택 내 위치에 따라 동적으로 결정
                    const stackIndex = modalStack.indexOf(modalDef.id);
                    const zIndex = stackIndex >= 0 ? 50 + stackIndex : 50;

                    // 부모 컨텍스트 가져오기 ($parent 바인딩 지원)
                    const layoutContextStack2: Array<{
                      state: Record<string, any>;
                      setState: (updates: any) => void;
                      dataContext?: Record<string, any>;
                    }> = (window as any).__g7LayoutContextStack || [];
                    const parentContextEntry2 = layoutContextStack2[layoutContextStack2.length - 1];
                    const parentDataContext2 = parentContextEntry2?.dataContext;

                    // 모달 렌더러 생성
                    const modalRenderer = React.createElement(DynamicRenderer, {
                      key: `modal_${modalDef.id}_renderer`,
                      componentDef: {
                        ...modalDef,
                        props: {
                          ...modalDef.props,
                          isOpen,
                          // z-index를 스택 위치에 따라 설정
                          style: {
                            ...modalDef.props?.style,
                            zIndex,
                          },
                          // onClose는 closeModal 액션으로 연결
                          onClose: state.actionDispatcher?.createHandler(
                            { type: 'click', handler: 'closeModal' },
                            state.currentDataContext
                          ),
                        },
                      },
                      dataContext: state.currentDataContext,
                      translationContext: state.translationContext,
                      registry: state.registry!,
                      bindingEngine: state.bindingEngine!,
                      translationEngine: state.translationEngine!,
                      actionDispatcher: state.actionDispatcher!,
                      // $parent 바인딩을 위해 부모 데이터 컨텍스트 전달
                      parentDataContext: parentDataContext2,
                    });

                    // data_sources가 있으면 ModalDataSourceWrapper로 감싸기
                    if (modalDef.data_sources && modalDef.data_sources.length > 0) {
                      return React.createElement(ModalDataSourceWrapper, {
                        key: `modal_${modalDef.id}`,
                        isOpen,
                        modalId: modalDef.id,
                        dataSources: modalDef.data_sources,
                        dataContext: state.currentDataContext,
                        globalStateUpdater: state.actionDispatcher?.getGlobalStateUpdater(),
                        bindingEngine: state.bindingEngine!,
                        debug: debugMode,
                        children: modalRenderer,
                      });
                    }

                    return modalRenderer;
                  })
                ),  // ParentContextProvider 종료
              ]
            )  // SlotProvider 종료
          )  // ResponsiveProvider 종료
        )  // TransitionProvider 종료
      )  // TranslationProvider 종료
      );
    };

    // sync 옵션에 따라 렌더링 방식 선택
    if (options?.sync) {
      // 동기 모드: startTransition 없이 즉시 렌더링 (드래그 앤 드롭 등 즉각적인 UI 반영 필요 시)
      logger.log('재렌더링 시작 (sync mode - 즉시 렌더링)');
      doRender();
    } else {
      // 기본 모드: startTransition으로 래핑하여 깜빡임 방지
      logger.log('재렌더링 시작 (with startTransition)');
      startTransition(() => {
        doRender();
      });
    }

    logger.log('템플릿 데이터 업데이트 완료');
  } catch (error) {
    logger.error('템플릿 데이터 업데이트 실패', error);
    throw error;
  }
}

/**
 * 템플릿 정리
 *
 * React Root를 언마운트하고 모든 상태를 초기화합니다.
 */
function destroyTemplate(): void {
  try {
    logger.log('템플릿 정리 시작');

    // React Root 언마운트
    if (state.reactRoot) {
      logger.log('React Root 언마운트');
      state.reactRoot.unmount();
      state.reactRoot = null;
    }

    // 상태 초기화
    state.templateId = null;
    state.locale = 'ko';
    state.isInitialized = false;
    state.containerId = null;
    state.currentLayoutJson = null;
    state.currentDataContext = {};
    state.translationContext = {
      templateId: '',
      locale: 'ko',
    };
    state.registry = null;
    state.bindingEngine = null;
    state.translationEngine = null;
    state.actionDispatcher = null;
    state.templateMetadata = null;

    logger.log('템플릿 정리 완료');
  } catch (error) {
    logger.error('템플릿 정리 실패', error);
    throw error;
  }
}

/**
 * 현재 상태 조회
 *
 * 읽기 전용 상태 객체를 반환합니다.
 *
 * @returns 현재 템플릿 엔진 상태
 */
function getState(): Readonly<TemplateEngineState> {
  return Object.freeze({ ...state });
}

/**
 * ActionDispatcher 인스턴스 조회
 *
 * @returns ActionDispatcher 인스턴스 또는 null
 */
function getActionDispatcher(): ActionDispatcher | null {
  return state.actionDispatcher;
}

// ============================================================================
// 위지윅 편집 모드 관련 함수
// ============================================================================

/**
 * URL 쿼리 파라미터에서 편집 모드 여부를 확인합니다.
 *
 * @returns boolean 편집 모드 여부
 */
function checkEditMode(): boolean {
  if (typeof window === 'undefined') {
    return false;
  }

  const params = new URLSearchParams(window.location.search);
  const mode = params.get('mode');
  const template = params.get('template');

  // mode=edit 파라미터와 template 파라미터가 모두 있어야 편집 모드
  return mode === 'edit' && !!template;
}

/**
 * URL 쿼리 파라미터에서 템플릿 ID를 가져옵니다.
 *
 * @returns string | null 템플릿 ID
 */
function getTemplateIdFromUrl(): string | null {
  if (typeof window === 'undefined') {
    return null;
  }

  const params = new URLSearchParams(window.location.search);
  return params.get('template');
}

/**
 * 위지윅 편집기로 렌더링합니다.
 * 편집 모드일 때 WysiwygEditor를 동적으로 import하여 렌더링합니다.
 *
 * @param options - 렌더링 옵션
 */
async function renderWithWysiwygEditor(options: RenderOptions): Promise<void> {
  logger.log('위지윅 편집기 렌더링 시작');

  try {
    // WysiwygEditor 동적 import
    const { WysiwygEditor } = await import('./template-engine/wysiwyg');

    // URL에서 템플릿 ID 추출
    const templateId = getTemplateIdFromUrl() || state.templateId || 'unknown';

    // 편집 모드 닫기 핸들러 (mode 파라미터 제거)
    const handleClose = () => {
      const params = new URLSearchParams(window.location.search);
      params.delete('mode');
      params.delete('template');

      const newUrl = `${window.location.pathname}${params.toString() ? '?' + params.toString() : ''}`;
      window.location.href = newUrl;
    };

    // 저장 완료 핸들러
    const handleSaveComplete = (layoutData: any) => {
      logger.log('레이아웃 저장 완료:', layoutData);
      // TODO: API 호출로 레이아웃 저장
    };

    logger.log('WysiwygEditor 렌더링', { templateId, layoutName: options.layoutJson?.layout_name });

    // WysiwygEditor 렌더링
    state.reactRoot!.render(
      React.createElement(
        TranslationProvider,
        {
          translationEngine: state.translationEngine!,
          translationContext: state.translationContext,
        },
        React.createElement(WysiwygEditor, {
          layoutData: options.layoutJson,
          templateId,
          onClose: handleClose,
          onSaveComplete: handleSaveComplete,
          initialEditMode: 'visual',
          readOnly: false,
        })
      )
    );

    logger.log('위지윅 편집기 렌더링 완료');
  } catch (error) {
    logger.error('위지윅 편집기 로드 실패:', error);

    // 편집기 로드 실패 시 에러 메시지 표시
    state.reactRoot!.render(
      React.createElement('div', {
        className: 'flex items-center justify-center h-screen bg-gray-100 dark:bg-gray-900',
      },
        React.createElement('div', {
          className: 'text-center p-8 bg-white dark:bg-gray-800 rounded-lg shadow-lg',
        },
          React.createElement('h1', {
            className: 'text-xl font-bold text-red-600 dark:text-red-400 mb-4',
          }, '위지윅 편집기 로드 실패'),
          React.createElement('p', {
            className: 'text-gray-600 dark:text-gray-400 mb-4',
          }, '편집기를 불러오는 중 오류가 발생했습니다.'),
          React.createElement('button', {
            className: 'px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700',
            onClick: () => window.location.reload(),
          }, '새로고침')
        )
      )
    );

    throw error;
  }
}

/**
 * 템플릿 엔진 공개 API
 */
const TemplateEngine: TemplateEngineAPI = {
  initTemplateEngine,
  renderTemplate,
  updateTemplateData,
  destroyTemplate,
  getState,
};

/**
 * 전역 객체에 노출
 *
 * G7Core 전역 API는 G7CoreGlobals.ts 모듈로 분리되어 있습니다.
 */
if (typeof window !== 'undefined') {
  // G7Core 네임스페이스가 없으면 생성 (TemplateApp.ts에서 이미 생성했을 수 있음)
  if (!(window as any).G7Core) {
    (window as any).G7Core = {};
  }

  // TemplateEngine 노출
  (window as any).G7Core.TemplateEngine = TemplateEngine;

  // G7Core 전역 API 초기화
  initializeG7CoreGlobals({
    getState: () => ({
      translationEngine: state.translationEngine,
      translationContext: state.translationContext,
      bindingEngine: state.bindingEngine,
      actionDispatcher: state.actionDispatcher,
      templateMetadata: state.templateMetadata,
    }),
    transitionManager,
    responsiveManager,
    webSocketManager,
  });

  logger.log('전역 객체 window.G7Core.TemplateEngine에 노출됨');
}

/**
 * ESM export
 */
export default TemplateEngine;
export {
  TemplateEngine,
  initTemplateEngine,
  renderTemplate,
  updateTemplateData,
  destroyTemplate,
  getState,
  getActionDispatcher,
  LayoutLoader,
  DataSourceManager,
};

export type {
  TemplateEngineAPI,
  TemplateEngineState,
  InitOptions,
  RenderOptions,
  UpdateOptions,
};

export type { LayoutData, LayoutComponent } from './template-engine/LayoutLoader';
export type { DataSource, DataSourceType, ConditionContext } from './template-engine/DataSourceManager';

// TemplateApp export (이미 상단에서 import됨)
export { TemplateApp, initTemplateApp };
export type { TemplateAppConfig } from './TemplateApp';
export type { Route } from './routing/Router';

// TemplateApp.ts 모듈에서 이미 전역 노출 처리함 (Object.defineProperty getter 사용)
