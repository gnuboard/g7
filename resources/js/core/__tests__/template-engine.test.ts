/**
 * template-engine.ts 테스트
 *
 * 템플릿 엔진의 초기화, 렌더링, 데이터 업데이트, 정리 기능 테스트
 * (매니페스트 core-template-engine-entry-nu2.yaml)
 *
 * 건너뛰던 시험 5건을 현재 계약으로 살렸다. 네 건은 initTemplateEngine 이 컴포넌트를 로드하던
 * 옛 설계를 전제로 했다(지금은 TemplateApp 이 routes.json 과 병렬로 로드). 실패와 롤백은 지금도 인스턴스
 * 준비 중 예외로 일어나므로 그 지점에서 잰다. startTransition 시험은 react 를 실제 구현으로 통과시키는
 * 모의로 호출만 센다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { act } from '@testing-library/react';
import TemplateEngine, {
  initTemplateEngine,
  renderTemplate,
  updateTemplateData,
  destroyTemplate,
  getState,
} from '../template-engine';
import { ComponentRegistry } from '../template-engine/ComponentRegistry';
import { markLocalInitConsumed, resetLocalInitTracking } from '../template-engine/localInitSlot';
import { Logger } from '../utils/Logger';
import { TranslationEngine } from '../template-engine/TranslationEngine';

// startTransition 호출만 세고 실제 구현으로 통과시킨다 (template-engine.ts 는 이름으로 import 하므로 spy 를 걸 수 없다)
const { startTransitionSpy } = vi.hoisted(() => ({ startTransitionSpy: vi.fn() }));
vi.mock('react', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react')>();
  startTransitionSpy.mockImplementation((callback: () => void) => actual.startTransition(callback));
  return { ...actual, startTransition: startTransitionSpy };
});

/**
 * React 스케줄러가 pending work를 완료할 수 있도록 대기
 * React 18 concurrent rendering으로 인해 unmount 후에도 비동기 작업이 남아있을 수 있음
 */
const flushReactScheduler = async () => {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
  });
};

// ComponentRegistry mock
vi.mock('../template-engine/ComponentRegistry', () => {
  const mockInstance = {
    loadComponents: vi.fn().mockResolvedValue(undefined),
    getComponent: vi.fn().mockReturnValue(() => null),
    hasComponent: vi.fn().mockReturnValue(true),
    getInstance: vi.fn(),
  };

  mockInstance.getInstance.mockReturnValue(mockInstance);

  return {
    ComponentRegistry: {
      getInstance: vi.fn(() => mockInstance),
    },
  };
});

// DataBindingEngine mock
// G7CoreGlobals.initCoreRuntimeExports가 싱글톤 `dataBindingEngine`도 참조하므로 함께 노출
vi.mock('../template-engine/DataBindingEngine', () => {
  const DataBindingEngine = vi.fn(function(this: any) {
    this.bind = vi.fn();
    this.unbind = vi.fn();
    this.invalidateCacheByKeys = vi.fn();
  });

  return {
    DataBindingEngine,
    dataBindingEngine: new (DataBindingEngine as any)(),
  };
});

// TranslationEngine mock
vi.mock('../template-engine/TranslationEngine', () => {
  const mockInstance = {
    translate: vi.fn((key: string) => key),
    setLocale: vi.fn(),
    loadTranslations: vi.fn().mockResolvedValue({}),
    resolveTranslations: vi.fn((text: string) => text),
    clearCache: vi.fn(),
  };

  return {
    TranslationEngine: {
      getInstance: vi.fn(() => mockInstance),
      resetInstance: vi.fn(),
    },
    TranslationContext: {} as any,
  };
});

// ActionDispatcher mock
vi.mock('../template-engine/ActionDispatcher', () => ({
  ActionDispatcher: vi.fn(function(this: any) {
    this.dispatch = vi.fn();
    this.register = vi.fn();
  }),
  setActionDispatcherInstance: vi.fn(),
  getActionDispatcher: vi.fn(() => ({
    dispatch: vi.fn(),
    register: vi.fn(),
  })),
}));

// DynamicRenderer mock
vi.mock('../template-engine/DynamicRenderer', () => ({
  default: vi.fn(() => null),
}));

// ResponsiveManager mock
vi.mock('../template-engine/ResponsiveManager', () => ({
  responsiveManager: {
    getWidth: vi.fn(() => 1024),
    subscribe: vi.fn(() => () => {}),
    getMatchingKey: vi.fn(() => null),
    parseRange: vi.fn(() => null),
  },
  BREAKPOINT_PRESETS: {},
}));

// ResponsiveContext mock
// G7CoreGlobals.initCoreRuntimeExports가 Context 객체 자체도 참조하므로 함께 노출
vi.mock('../template-engine/ResponsiveContext', () => ({
  ResponsiveContext: {},
  ResponsiveProvider: ({ children }: { children: React.ReactNode }) => children,
  useResponsive: vi.fn(() => ({
    width: 1024,
    isMobile: false,
    isTablet: false,
    isDesktop: true,
    matchedPreset: 'desktop',
  })),
}));

describe('TemplateEngine - initTemplateEngine()', () => {
  beforeEach(() => {
    // 각 테스트 전에 정리
    destroyTemplate();
    vi.clearAllMocks();
  });

  afterEach(async () => {
    destroyTemplate();
    await flushReactScheduler();
  });

  // @scenario area=init
  // @effects engine_init_sets_state
  it('정상적으로 초기화되어야 함', async () => {
    await initTemplateEngine({
      templateId: 'test-template',
      locale: 'ko',
    });

    const state = getState();

    expect(state.isInitialized).toBe(true);
    expect(state.templateId).toBe('test-template');
    expect(state.locale).toBe('ko');
    expect(state.registry).not.toBeNull();
    expect(state.bindingEngine).not.toBeNull();
    expect(state.translationEngine).not.toBeNull();
    expect(state.actionDispatcher).not.toBeNull();
  });

  // @scenario area=init
  // @effects engine_init_validates_options
  it('templateId가 필수여야 함', async () => {
    // TemplateNotFoundError가 던져짐 (다국어 키 사용)
    await expect(
      initTemplateEngine({ templateId: '' })
    ).rejects.toThrow('$t:core.errors.template_not_found');

    const state = getState();
    expect(state.isInitialized).toBe(false);
  });

  // @scenario area=init
  // @effects engine_init_sets_state
  it('locale 기본값은 "ko"여야 함', async () => {
    await initTemplateEngine({ templateId: 'test-template' });

    const state = getState();
    expect(state.locale).toBe('ko');
  });

  // @scenario area=init
  // @effects engine_init_sets_state
  it('locale을 지정할 수 있어야 함', async () => {
    await initTemplateEngine({
      templateId: 'test-template',
      locale: 'en',
    });

    const state = getState();
    expect(state.locale).toBe('en');
  });

  // @scenario area=init
  // @effects engine_init_validates_options
  it('중복 초기화를 방지해야 함', async () => {
    await initTemplateEngine({ templateId: 'test-template' });

    await expect(
      initTemplateEngine({ templateId: 'test-template-2' })
    ).rejects.toThrow('템플릿 엔진이 이미 초기화되었습니다');
  });

  // ComponentRegistry.loadComponents()는 initTemplateEngine 이 부르지 않는다.
  // TemplateApp 이 routes.json 과 함께 병렬로 로드한다(성능 최적화). 엔진이 다시 부르면 이중 로드다.
  // @scenario area=init
  // @effects engine_init_does_not_load_components
  it('ComponentRegistry.loadComponents()를 직접 호출하지 않아야 함', async () => {
    const mockRegistry = ComponentRegistry.getInstance();

    await initTemplateEngine({ templateId: 'test-template' });

    expect(mockRegistry.loadComponents).not.toHaveBeenCalled();
  });

  // 인스턴스 준비 중 예외(여기서는 번역 엔진)가 나면 이미 만든 인스턴스까지 되돌린다
  // @scenario area=init
  // @effects engine_init_rolls_back_on_failure
  it('초기화 실패 시 상태를 롤백해야 함', async () => {
    vi.mocked(TranslationEngine.getInstance).mockImplementationOnce(() => {
      throw new Error('Load failed');
    });

    await expect(
      initTemplateEngine({ templateId: 'test-template' })
    ).rejects.toThrow('Load failed');

    const state = getState();
    expect(state.isInitialized).toBe(false);
    expect(state.registry).toBeNull();
    expect(state.bindingEngine).toBeNull();
    expect(state.translationEngine).toBeNull();
  });

  // @scenario area=init
  // @effects engine_init_sets_state
  it('debug 모드를 설정할 수 있어야 함', async () => {
    // DevTools 가 읽는 G7Config.debug 에 옵션이 실린다
    await initTemplateEngine({
      templateId: 'test-template',
      debug: true,
    });

    const state = getState();
    expect(state.isInitialized).toBe(true);
    expect((window as any).G7Config.debug).toBe(true);

    destroyTemplate();
    await initTemplateEngine({ templateId: 'test-template' });
    expect((window as any).G7Config.debug).toBe(false);
  });
});

describe('TemplateEngine - renderTemplate()', () => {
  beforeEach(async () => {
    destroyTemplate();
    vi.clearAllMocks();

    // DOM 환경 준비
    document.body.innerHTML = '<div id="app"></div>';

    // 초기화
    await initTemplateEngine({ templateId: 'test-template' });
  });

  afterEach(async () => {
    destroyTemplate();
    await flushReactScheduler();
    document.body.innerHTML = '';
  });

  // @scenario area=render
  // @effects engine_render_sets_root_and_layout
  it('정상적으로 렌더링되어야 함', async () => {
    const layoutJson = {
      version: '1.0.0',
      components: [
        {
          id: 'comp-1',
          type: 'Text',
          props: { text: 'Hello' },
          children: [],
        },
      ],
    };

    await renderTemplate({
      containerId: 'app',
      layoutJson,
    });

    const state = getState();
    expect(state.reactRoot).not.toBeNull();
    expect(state.containerId).toBe('app');
    expect(state.currentLayoutJson).toEqual(layoutJson);
  });

  // @scenario area=render
  // @effects engine_render_validates_input
  it('초기화 없이 렌더링 시 에러를 던져야 함', async () => {
    destroyTemplate();

    await expect(
      renderTemplate({
        containerId: 'app',
        layoutJson: { components: [] },
      })
    ).rejects.toThrow('템플릿 엔진이 초기화되지 않았습니다');
  });

  // @scenario area=render
  // @effects engine_render_validates_input
  it('containerId가 필수여야 함', async () => {
    await expect(
      renderTemplate({
        containerId: '',
        layoutJson: { components: [] },
      })
    ).rejects.toThrow('containerId는 필수입니다');
  });

  // @scenario area=render
  // @effects engine_render_validates_input
  it('layoutJson이 필수여야 함', async () => {
    await expect(
      renderTemplate({
        containerId: 'app',
        layoutJson: null as any,
      })
    ).rejects.toThrow('layoutJson은 필수입니다');
  });

  // @scenario area=render
  // @effects engine_render_validates_input
  it('존재하지 않는 containerId 시 에러를 던져야 함', async () => {
    await expect(
      renderTemplate({
        containerId: 'non-existent',
        layoutJson: { components: [] },
      })
    ).rejects.toThrow('컨테이너를 찾을 수 없습니다: #non-existent');
  });

  // @scenario area=render
  // @effects engine_render_builds_contexts
  it('dataContext를 전달할 수 있어야 함', async () => {
    const dataContext = { userName: 'John', age: 30 };

    await renderTemplate({
      containerId: 'app',
      layoutJson: { components: [] },
      dataContext,
    });

    const state = getState();
    // 전역 변수($locale, $locales)도 함께 포함됨
    expect(state.currentDataContext.userName).toBe('John');
    expect(state.currentDataContext.age).toBe(30);
    expect(state.currentDataContext.$locale).toBe('ko');
  });

  // @scenario area=render
  // @effects engine_render_builds_contexts
  it('dataContext 기본값에 전역 변수가 포함되어야 함', async () => {
    await renderTemplate({
      containerId: 'app',
      layoutJson: { components: [] },
    });

    const state = getState();
    // 전역 변수($locale, $locales)가 자동으로 추가됨
    expect(state.currentDataContext.$locale).toBe('ko');
    expect(state.currentDataContext.$locales).toBeDefined();
  });

  // @scenario area=render
  // @effects engine_render_builds_contexts
  it('translationContext를 전달할 수 있어야 함', async () => {
    const translationContext = {
      templateId: 'custom-template',
      locale: 'en',
    };

    await renderTemplate({
      containerId: 'app',
      layoutJson: { components: [] },
      translationContext,
    });

    const state = getState();
    expect(state.translationContext).toEqual(translationContext);
  });

  // @scenario area=render
  // @effects engine_render_builds_contexts
  it('translationContext 기본값은 templateId와 locale이어야 함', async () => {
    await renderTemplate({
      containerId: 'app',
      layoutJson: { components: [] },
    });

    const state = getState();
    expect(state.translationContext).toEqual({
      templateId: 'test-template',
      locale: 'ko',
    });
  });

  // @scenario area=render
  // @effects engine_render_warns_empty_components
  it('components가 비어있으면 경고를 출력해야 함', async () => {
    // Logger 디버그 모드 활성화
    Logger.getInstance().setDebug(true);
    const consoleWarnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

    await renderTemplate({
      containerId: 'app',
      layoutJson: { components: [] },
    });

    expect(consoleWarnSpy).toHaveBeenCalledWith('[TemplateEngine]', '렌더링할 컴포넌트가 없습니다.');

    consoleWarnSpy.mockRestore();
    Logger.getInstance().setDebug(false);
  });
});

describe('TemplateEngine - updateTemplateData()', () => {
  beforeEach(async () => {
    destroyTemplate();
    vi.clearAllMocks();
    // DynamicRenderer가 모킹되어 _localInit 슬롯을 소비하지 않으므로 테스트 간 격리 필요
    resetLocalInitTracking();

    document.body.innerHTML = '<div id="app"></div>';

    await initTemplateEngine({ templateId: 'test-template' });
    await renderTemplate({
      containerId: 'app',
      layoutJson: { components: [] },
      dataContext: { a: 1, b: 2 },
    });
  });

  afterEach(async () => {
    destroyTemplate();
    await flushReactScheduler();
    document.body.innerHTML = '';
  });

  // @scenario area=update
  // @effects engine_update_merges_data
  it('데이터를 병합하고 재렌더링해야 함', () => {
    updateTemplateData({ b: 3, c: 4 });

    const state = getState();
    // 전역 변수($locale, $locales)도 함께 유지됨
    expect(state.currentDataContext.a).toBe(1);
    expect(state.currentDataContext.b).toBe(3);
    expect(state.currentDataContext.c).toBe(4);
    expect(state.currentDataContext.$locale).toBe('ko');
  });

  // @scenario area=update
  // @effects engine_call_order_errors
  it('초기화 없이 업데이트 시 에러를 던져야 함', () => {
    destroyTemplate();

    expect(() => updateTemplateData({ a: 1 })).toThrow(
      '템플릿 엔진이 초기화되지 않았습니다'
    );
  });

  // @scenario area=update
  // @effects engine_update_queues_until_rendered
  it('렌더링 없이 업데이트 시 예외 없이 pendingDataUpdates 큐에 저장되어야 함', async () => {
    // engine 변경: 렌더링 전 updateTemplateData 호출은 throw 하지 않고
    // pendingDataUpdates 큐에 저장하여 reactRoot 준비 후 자동 반영됨
    destroyTemplate();
    await initTemplateEngine({ templateId: 'test-template' });

    expect(() => updateTemplateData({ queued: 1 })).not.toThrow();
    expect(getState().currentDataContext.queued).toBeUndefined();

    // 렌더링이 끝나면 큐의 업데이트가 반영된다 (컴포넌트가 없으면 렌더가 일찍 끝나 큐를 적용하지 않으므로 하나 둔다)
    await renderTemplate({
      containerId: 'app',
      layoutJson: { components: [{ id: 'q-1', type: 'basic', name: 'Div' }] },
    });
    expect(getState().currentDataContext.queued).toBe(1);
  });

  // @scenario area=update
  // @effects engine_update_merges_data
  it('기존 데이터를 유지해야 함', () => {
    updateTemplateData({ c: 3 });

    const state = getState();
    expect(state.currentDataContext.a).toBe(1);
    expect(state.currentDataContext.b).toBe(2);
    expect(state.currentDataContext.c).toBe(3);
  });

  // @scenario area=update
  // @effects engine_update_merges_data
  it('빈 객체로 업데이트해도 작동해야 함', () => {
    updateTemplateData({});

    const state = getState();
    // 기존 데이터와 전역 변수가 유지됨
    expect(state.currentDataContext.a).toBe(1);
    expect(state.currentDataContext.b).toBe(2);
    expect(state.currentDataContext.$locale).toBe('ko');
  });

  // @scenario area=local_init
  // @effects engine_local_init_slot_merge
  it('_localInit 데이터를 데이터 컨텍스트에 저장해야 함', () => {
    updateTemplateData({
      _localInit: {
        form: {
          name: 'Test Role',
          permission_ids: [1, 2, 3],
        },
      },
    });

    const state = getState();
    expect(state.currentDataContext._localInit).toBeDefined();
    expect(state.currentDataContext._localInit.form).toEqual({
      name: 'Test Role',
      permission_ids: [1, 2, 3],
    });
  });

  /**
   * 다중 progressive 데이터소스의 _localInit 상호 덮어쓰기 회귀
   *
   * 증상: initLocal을 가진 progressive 데이터소스가 한 레이아웃에 둘 이상이면,
   *       React commit 전에 두 번의 updateTemplateData가 연달아 들어올 때
   *       나중 payload가 _localInit 슬롯을 통째로 교체하여 앞선 소스의 초기값이 유실됨.
   * 해결: 아직 어떤 렌더러도 관측하지 않은(unconsumed) 슬롯은 교체하지 않고 누적 병합.
   *
   * @see troubleshooting-state-global.md
   */
  // @scenario area=local_init
  // @effects engine_local_init_slot_merge
  it('소비되지 않은 _localInit 슬롯은 후속 payload와 누적 병합되어야 함', () => {
    // settings 소스 응답 (refetchOnMount → _forceLocalInit 보유)
    updateTemplateData({
      settings: { shipping: {} },
      _localInit: { form: { name: 'shipping' }, _forceLocalInit: 1700000000000 },
    });

    // notification 소스 응답 (같은 commit 이전에 도착)
    updateTemplateData({
      notificationDefinitions: { data: [] },
      _localInit: { notificationDefinitionCurrentPage: 1 },
    });

    const state = getState();
    // 먼저 도착한 settings의 form이 살아있어야 함
    expect(state.currentDataContext._localInit.form).toEqual({ name: 'shipping' });
    // 나중에 도착한 notification 값도 함께 있어야 함
    expect(state.currentDataContext._localInit.notificationDefinitionCurrentPage).toBe(1);
    // refetchOnMount 타임스탬프가 보존되어야 함
    expect(state.currentDataContext._localInit._forceLocalInit).toBe(1700000000000);
  });

  // @scenario area=local_init
  // @effects engine_local_init_slot_merge
  it('이미 소비된 _localInit 슬롯은 교체되어 stale 재적용이 없어야 함', () => {
    updateTemplateData({ _localInit: { form: { name: 'shipping' } } });

    // 렌더러가 슬롯을 관측 (DynamicRenderer의 _localInit useEffect가 하는 일)
    markLocalInitConsumed(getState().currentDataContext._localInit);

    // 사용자가 화면을 쓰는 도중 다른 소스를 refetch
    updateTemplateData({ _localInit: { notificationDefinitionCurrentPage: 2 } });

    const state = getState();
    // 소비가 끝난 form은 재적용 대상이 아니어야 함 (폼 편집 결과 되돌림 방지)
    expect(state.currentDataContext._localInit.form).toBeUndefined();
    expect(state.currentDataContext._localInit.notificationDefinitionCurrentPage).toBe(2);
  });

  // @scenario area=update
  // @effects engine_update_merges_data
  it('sync 옵션으로 업데이트해도 정상 작동해야 함', () => {
    updateTemplateData({ c: 5 }, { sync: true });

    const state = getState();
    expect(state.currentDataContext.a).toBe(1);
    expect(state.currentDataContext.b).toBe(2);
    expect(state.currentDataContext.c).toBe(5);
  });

  // @scenario area=local_init
  // @effects engine_local_init_slot_merge
  it('_localInit과 sync 옵션을 함께 사용해도 정상 작동해야 함', () => {
    updateTemplateData(
      {
        _localInit: {
          form: {
            name: 'Clone Role',
            description: 'Cloned description',
            permission_ids: [10, 20, 30],
          },
        },
      },
      { sync: true }
    );

    const state = getState();
    expect(state.currentDataContext._localInit).toBeDefined();
    expect(state.currentDataContext._localInit.form.name).toBe('Clone Role');
    expect(state.currentDataContext._localInit.form.permission_ids).toEqual([10, 20, 30]);
  });

  // 파일 머리의 react 모의가 실제 startTransition 으로 통과시키며 호출만 센다
  // @scenario area=update
  // @effects engine_update_uses_transition
  it('startTransition으로 렌더링을 래핑해야 함', () => {
    startTransitionSpy.mockClear();

    updateTemplateData({ b: 5 });
    expect(startTransitionSpy).toHaveBeenCalledTimes(1);
    expect(startTransitionSpy).toHaveBeenCalledWith(expect.any(Function));

    // sync 옵션은 startTransition 없이 즉시 그린다
    updateTemplateData({ b: 6 }, { sync: true });
    expect(startTransitionSpy).toHaveBeenCalledTimes(1);
  });
});

describe('TemplateEngine - destroyTemplate()', () => {
  beforeEach(async () => {
    destroyTemplate();
    vi.clearAllMocks();

    document.body.innerHTML = '<div id="app"></div>';
  });

  afterEach(async () => {
    await flushReactScheduler();
    document.body.innerHTML = '';
  });

  // @scenario area=destroy
  // @effects engine_destroy_resets_state
  it('모든 상태를 초기화해야 함', async () => {
    await initTemplateEngine({ templateId: 'test-template' });
    await renderTemplate({
      containerId: 'app',
      layoutJson: { components: [] },
    });

    destroyTemplate();

    const state = getState();
    expect(state.isInitialized).toBe(false);
    expect(state.templateId).toBeNull();
    expect(state.locale).toBe('ko');
    expect(state.reactRoot).toBeNull();
    expect(state.containerId).toBeNull();
    expect(state.currentLayoutJson).toBeNull();
    expect(state.currentDataContext).toEqual({});
    expect(state.translationContext).toEqual({
      templateId: '',
      locale: 'ko',
    });
    expect(state.registry).toBeNull();
    expect(state.bindingEngine).toBeNull();
    expect(state.translationEngine).toBeNull();
    expect(state.actionDispatcher).toBeNull();
  });

  // @scenario area=destroy
  // @effects engine_destroy_resets_state
  it('초기화하지 않고 정리해도 에러가 발생하지 않아야 함', () => {
    expect(() => destroyTemplate()).not.toThrow();
  });

  // @scenario area=destroy
  // @effects engine_destroy_resets_state
  it('정리 후 재초기화가 가능해야 함', async () => {
    await initTemplateEngine({ templateId: 'test-template-1' });
    destroyTemplate();

    await initTemplateEngine({ templateId: 'test-template-2' });

    const state = getState();
    expect(state.isInitialized).toBe(true);
    expect(state.templateId).toBe('test-template-2');
  });
});

describe('TemplateEngine - getState()', () => {
  beforeEach(() => {
    destroyTemplate();
  });

  afterEach(async () => {
    destroyTemplate();
    await flushReactScheduler();
  });

  // @scenario area=get_state
  // @effects engine_state_readable
  it('현재 상태를 반환해야 함', async () => {
    await initTemplateEngine({ templateId: 'test-template', locale: 'en' });

    const state = getState();

    expect(state).toHaveProperty('isInitialized');
    expect(state).toHaveProperty('templateId');
    expect(state).toHaveProperty('locale');
    expect(state.templateId).toBe('test-template');
    expect(state.locale).toBe('en');
  });

  // @scenario area=get_state
  // @effects engine_state_readable
  it('읽기 전용 상태를 반환해야 함', () => {
    const state = getState();

    // Object.freeze()로 인해 수정 불가
    expect(() => {
      (state as any).templateId = 'hacked';
    }).toThrow();
  });

  // @scenario area=get_state
  // @effects engine_state_readable
  it('초기화 전 상태를 반환해야 함', () => {
    const state = getState();

    expect(state.isInitialized).toBe(false);
    expect(state.templateId).toBeNull();
    expect(state.locale).toBe('ko');
  });
});

describe('TemplateEngine - 전체 라이프사이클 통합 테스트', () => {
  beforeEach(() => {
    destroyTemplate();
    vi.clearAllMocks();
    document.body.innerHTML = '<div id="integration-test"></div>';
  });

  afterEach(async () => {
    destroyTemplate();
    await flushReactScheduler();
    document.body.innerHTML = '';
  });

  // @scenario area=lifecycle
  // @effects engine_full_lifecycle
  it('초기화 → 렌더링 → 업데이트 → 정리 흐름이 정상 작동해야 함', async () => {
    // 1. 초기화
    await initTemplateEngine({
      templateId: 'integration-template',
      locale: 'ko',
      debug: false,
    });

    let state = getState();
    expect(state.isInitialized).toBe(true);
    expect(state.templateId).toBe('integration-template');

    // 2. 렌더링
    await renderTemplate({
      containerId: 'integration-test',
      layoutJson: {
        version: '1.0.0',
        components: [
          { id: 'text-1', type: 'Text', props: { text: '{{ message }}' } },
        ],
      },
      dataContext: { message: 'Hello' },
    });

    state = getState();
    expect(state.reactRoot).not.toBeNull();
    expect(state.currentDataContext.message).toBe('Hello');

    // 3. 데이터 업데이트
    updateTemplateData({ message: 'Updated' });

    state = getState();
    expect(state.currentDataContext.message).toBe('Updated');

    // 4. 정리
    destroyTemplate();

    state = getState();
    expect(state.isInitialized).toBe(false);
    expect(state.reactRoot).toBeNull();
  });

  // @scenario area=lifecycle
  // @effects engine_root_reused
  it('여러 번 렌더링해도 동일한 React Root를 재사용해야 함', async () => {
    await initTemplateEngine({ templateId: 'test-template' });

    await renderTemplate({
      containerId: 'integration-test',
      layoutJson: { components: [] },
    });

    const firstRoot = getState().reactRoot;

    await renderTemplate({
      containerId: 'integration-test',
      layoutJson: { components: [] },
    });

    const secondRoot = getState().reactRoot;

    expect(firstRoot).toBe(secondRoot);
  });
});

describe('TemplateEngine - 전역 객체 노출 검증', () => {
  // @scenario area=global_exposure
  // @effects engine_global_api_exposed
  it('window.G7Core.TemplateEngine이 존재해야 함', () => {
    expect(window.G7Core).toBeDefined();
    expect(window.G7Core?.TemplateEngine).toBeDefined();
  });

  // @scenario area=global_exposure
  // @effects engine_global_api_exposed
  it('전역 객체와 ESM export가 동일해야 함', () => {
    expect(window.G7Core?.TemplateEngine).toBe(TemplateEngine);
  });

  // @scenario area=global_exposure
  // @effects engine_global_api_exposed
  it('모든 공개 함수가 노출되어야 함', () => {
    const exposed = window.G7Core?.TemplateEngine;
    expect(exposed?.initTemplateEngine).toBe(initTemplateEngine);
    expect(exposed?.renderTemplate).toBe(renderTemplate);
    expect(exposed?.updateTemplateData).toBe(updateTemplateData);
    expect(exposed?.destroyTemplate).toBe(destroyTemplate);
    expect(exposed?.getState).toBe(getState);
  });
});

describe('TemplateEngine - 에러 처리 시나리오', () => {
  beforeEach(() => {
    destroyTemplate();
    vi.clearAllMocks();
  });

  afterEach(async () => {
    destroyTemplate();
    await flushReactScheduler();
  });

  // 컴포넌트 로드는 TemplateApp 몫이라, 엔진에서 레지스트리 실패는 인스턴스 준비 실패로 난다
  // @scenario area=error_handling
  // @effects engine_init_rolls_back_on_failure
  it('ComponentRegistry 준비 실패 시 그 에러를 그대로 던져야 함', async () => {
    vi.mocked(ComponentRegistry.getInstance).mockImplementationOnce(() => {
      throw new Error('Network error');
    });

    await expect(
      initTemplateEngine({ templateId: 'test-template' })
    ).rejects.toThrow('Network error');

    const state = getState();
    expect(state.isInitialized).toBe(false);
  });

  // @scenario area=error_handling
  // @effects engine_render_validates_input
  it('잘못된 layoutJson으로 렌더링 시 에러를 던져야 함', async () => {
    document.body.innerHTML = '<div id="app"></div>';

    await initTemplateEngine({ templateId: 'test-template' });

    await expect(
      renderTemplate({
        containerId: 'app',
        layoutJson: undefined as any,
      })
    ).rejects.toThrow('layoutJson은 필수입니다');
  });

  // @scenario area=error_handling
  // @effects engine_call_order_errors
  it('순서가 잘못된 호출 시 명확한 에러 메시지를 제공해야 함', async () => {
    // renderTemplate before init
    await expect(
      renderTemplate({
        containerId: 'app',
        layoutJson: { components: [] },
      })
    ).rejects.toThrow('템플릿 엔진이 초기화되지 않았습니다');

    // updateTemplateData before init
    expect(() => updateTemplateData({ a: 1 })).toThrow(
      '템플릿 엔진이 초기화되지 않았습니다'
    );

    // updateTemplateData before render → pending 큐에 저장 (예외 없음)
    await initTemplateEngine({ templateId: 'test' });
    expect(() => updateTemplateData({ a: 1 })).not.toThrow();
  });

  // 실패가 「이미 초기화됨」 상태를 남기지 않아야 다시 부를 수 있다
  // @scenario area=error_handling
  // @effects engine_init_retry_after_failure
  it('초기화 실패 후 재시도가 가능해야 함', async () => {
    // 첫 번째 시도 실패
    vi.mocked(TranslationEngine.getInstance).mockImplementationOnce(() => {
      throw new Error('First attempt failed');
    });

    await expect(
      initTemplateEngine({ templateId: 'test-template' })
    ).rejects.toThrow('First attempt failed');

    // 두 번째 시도 성공
    await initTemplateEngine({ templateId: 'test-template' });

    const state = getState();
    expect(state.isInitialized).toBe(true);
  });
});
