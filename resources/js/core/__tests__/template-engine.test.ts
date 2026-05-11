/**
 * template-engine.ts 테스트
 *
 * 템플릿 엔진의 초기화, 렌더링, 데이터 업데이트, 정리 기능 테스트
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
import { Logger } from '../utils/Logger';

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
vi.mock('../template-engine/DataBindingEngine', () => ({
  DataBindingEngine: vi.fn(function(this: any) {
    this.bind = vi.fn();
    this.unbind = vi.fn();
  }),
}));

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
}));

// ResponsiveContext mock
vi.mock('../template-engine/ResponsiveContext', () => ({
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

  it('templateId가 필수여야 함', async () => {
    // TemplateNotFoundError가 던져짐 (다국어 키 사용)
    await expect(
      initTemplateEngine({ templateId: '' })
    ).rejects.toThrow('$t:core.errors.template_not_found');

    const state = getState();
    expect(state.isInitialized).toBe(false);
  });

  it('locale 기본값은 "ko"여야 함', async () => {
    await initTemplateEngine({ templateId: 'test-template' });

    const state = getState();
    expect(state.locale).toBe('ko');
  });

  it('locale을 지정할 수 있어야 함', async () => {
    await initTemplateEngine({
      templateId: 'test-template',
      locale: 'en',
    });

    const state = getState();
    expect(state.locale).toBe('en');
  });

  it('중복 초기화를 방지해야 함', async () => {
    await initTemplateEngine({ templateId: 'test-template' });

    await expect(
      initTemplateEngine({ templateId: 'test-template-2' })
    ).rejects.toThrow('템플릿 엔진이 이미 초기화되었습니다');
  });

  // NOTE: ComponentRegistry.loadComponents()는 initTemplateEngine에서 호출되지 않음
  // TemplateApp에서 routes.json과 함께 병렬로 로드됨 (성능 최적화)
  it.skip('ComponentRegistry.loadComponents()를 호출해야 함', async () => {
    const mockRegistry = ComponentRegistry.getInstance();

    await initTemplateEngine({ templateId: 'test-template' });

    expect(mockRegistry.loadComponents).toHaveBeenCalledWith('test-template', 'admin');
    expect(mockRegistry.loadComponents).toHaveBeenCalledTimes(1);
  });

  // NOTE: ComponentRegistry.loadComponents()는 initTemplateEngine에서 호출되지 않음
  // TemplateApp에서 별도로 로드됨
  it.skip('초기화 실패 시 상태를 롤백해야 함', async () => {
    const mockRegistry = ComponentRegistry.getInstance();
    (mockRegistry.loadComponents as any).mockRejectedValueOnce(
      new Error('Load failed')
    );

    await expect(
      initTemplateEngine({ templateId: 'test-template' })
    ).rejects.toThrow('Load failed');

    const state = getState();
    expect(state.isInitialized).toBe(false);
    expect(state.registry).toBeNull();
    expect(state.bindingEngine).toBeNull();
  });

  it('debug 모드를 설정할 수 있어야 함', async () => {
    // debug: true로 초기화 (로그 출력 확인은 수동)
    await initTemplateEngine({
      templateId: 'test-template',
      debug: true,
    });

    const state = getState();
    expect(state.isInitialized).toBe(true);
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

  it('초기화 없이 렌더링 시 에러를 던져야 함', async () => {
    destroyTemplate();

    await expect(
      renderTemplate({
        containerId: 'app',
        layoutJson: { components: [] },
      })
    ).rejects.toThrow('템플릿 엔진이 초기화되지 않았습니다');
  });

  it('containerId가 필수여야 함', async () => {
    await expect(
      renderTemplate({
        containerId: '',
        layoutJson: { components: [] },
      })
    ).rejects.toThrow('containerId는 필수입니다');
  });

  it('layoutJson이 필수여야 함', async () => {
    await expect(
      renderTemplate({
        containerId: 'app',
        layoutJson: null as any,
      })
    ).rejects.toThrow('layoutJson은 필수입니다');
  });

  it('존재하지 않는 containerId 시 에러를 던져야 함', async () => {
    await expect(
      renderTemplate({
        containerId: 'non-existent',
        layoutJson: { components: [] },
      })
    ).rejects.toThrow('컨테이너를 찾을 수 없습니다: #non-existent');
  });

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

  it('데이터를 병합하고 재렌더링해야 함', () => {
    updateTemplateData({ b: 3, c: 4 });

    const state = getState();
    // 전역 변수($locale, $locales)도 함께 유지됨
    expect(state.currentDataContext.a).toBe(1);
    expect(state.currentDataContext.b).toBe(3);
    expect(state.currentDataContext.c).toBe(4);
    expect(state.currentDataContext.$locale).toBe('ko');
  });

  it('초기화 없이 업데이트 시 에러를 던져야 함', () => {
    destroyTemplate();

    expect(() => updateTemplateData({ a: 1 })).toThrow(
      '템플릿 엔진이 초기화되지 않았습니다'
    );
  });

  it('렌더링 없이 업데이트 시 예외 없이 pendingDataUpdates 큐에 저장되어야 함', async () => {
    // engine 변경: 렌더링 전 updateTemplateData 호출은 throw 하지 않고
    // pendingDataUpdates 큐에 저장하여 reactRoot 준비 후 자동 반영됨
    destroyTemplate();
    await initTemplateEngine({ templateId: 'test-template' });

    expect(() => updateTemplateData({ a: 1 })).not.toThrow();
  });

  it('기존 데이터를 유지해야 함', () => {
    updateTemplateData({ c: 3 });

    const state = getState();
    expect(state.currentDataContext.a).toBe(1);
    expect(state.currentDataContext.b).toBe(2);
    expect(state.currentDataContext.c).toBe(3);
  });

  it('빈 객체로 업데이트해도 작동해야 함', () => {
    updateTemplateData({});

    const state = getState();
    // 기존 데이터와 전역 변수가 유지됨
    expect(state.currentDataContext.a).toBe(1);
    expect(state.currentDataContext.b).toBe(2);
    expect(state.currentDataContext.$locale).toBe('ko');
  });

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

  it('sync 옵션으로 업데이트해도 정상 작동해야 함', () => {
    updateTemplateData({ c: 5 }, { sync: true });

    const state = getState();
    expect(state.currentDataContext.a).toBe(1);
    expect(state.currentDataContext.b).toBe(2);
    expect(state.currentDataContext.c).toBe(5);
  });

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

  // NOTE: vi.doMock으로 이미 로드된 react 모듈을 동적 모킹하면 ESM 환경에서 정상 작동하지 않음
  // 실제 startTransition 호출은 template-engine.ts의 updateTemplateData 구현에서 확인 가능
  it.skip('startTransition으로 렌더링을 래핑해야 함', async () => {
    // React의 startTransition을 모킹
    const mockStartTransition = vi.fn((callback) => callback());

    // React 모듈을 동적으로 모킹
    vi.doMock('react', () => ({
      default: vi.fn(),
      startTransition: mockStartTransition,
      useDeferredValue: vi.fn(),
    }));

    // 새로운 환경에서 template-engine을 재로드
    const { updateTemplateData: updateFn } = await import('../template-engine');

    // 테스트 환경 재설정
    destroyTemplate();
    document.body.innerHTML = '<div id="app"></div>';
    await initTemplateEngine({ templateId: 'test-template' });
    await renderTemplate({
      containerId: 'app',
      layoutJson: { components: [] },
      dataContext: { a: 1 },
    });

    // 모킹 초기화
    mockStartTransition.mockClear();

    // 데이터 업데이트 실행
    updateFn({ b: 2 });

    // startTransition이 호출되었는지 검증
    expect(mockStartTransition).toHaveBeenCalledTimes(1);
    expect(mockStartTransition).toHaveBeenCalledWith(expect.any(Function));

    // 모킹 해제
    vi.doUnmock('react');
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

  it('초기화하지 않고 정리해도 에러가 발생하지 않아야 함', () => {
    expect(() => destroyTemplate()).not.toThrow();
  });

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

  it('현재 상태를 반환해야 함', async () => {
    await initTemplateEngine({ templateId: 'test-template', locale: 'en' });

    const state = getState();

    expect(state).toHaveProperty('isInitialized');
    expect(state).toHaveProperty('templateId');
    expect(state).toHaveProperty('locale');
    expect(state.templateId).toBe('test-template');
    expect(state.locale).toBe('en');
  });

  it('읽기 전용 상태를 반환해야 함', () => {
    const state = getState();

    // Object.freeze()로 인해 수정 불가
    expect(() => {
      (state as any).templateId = 'hacked';
    }).toThrow();
  });

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
  it('window.G7Core.TemplateEngine이 존재해야 함', () => {
    expect(window.G7Core).toBeDefined();
    expect(window.G7Core.TemplateEngine).toBeDefined();
  });

  it('전역 객체와 ESM export가 동일해야 함', () => {
    expect(window.G7Core.TemplateEngine).toBe(TemplateEngine);
  });

  it('모든 공개 함수가 노출되어야 함', () => {
    expect(window.G7Core.TemplateEngine.initTemplateEngine).toBe(
      initTemplateEngine
    );
    expect(window.G7Core.TemplateEngine.renderTemplate).toBe(renderTemplate);
    expect(window.G7Core.TemplateEngine.updateTemplateData).toBe(
      updateTemplateData
    );
    expect(window.G7Core.TemplateEngine.destroyTemplate).toBe(destroyTemplate);
    expect(window.G7Core.TemplateEngine.getState).toBe(getState);
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

  // NOTE: ComponentRegistry.loadComponents()는 initTemplateEngine에서 호출되지 않음
  // TemplateApp에서 별도로 로드됨
  it.skip('ComponentRegistry 로드 실패 시 적절한 에러를 던져야 함', async () => {
    const mockRegistry = ComponentRegistry.getInstance();
    const loadError = new Error('Network error');
    (mockRegistry.loadComponents as any).mockRejectedValueOnce(loadError);

    await expect(
      initTemplateEngine({ templateId: 'test-template' })
    ).rejects.toThrow('Network error');

    const state = getState();
    expect(state.isInitialized).toBe(false);
  });

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

  // NOTE: ComponentRegistry.loadComponents()는 initTemplateEngine에서 호출되지 않음
  // TemplateApp에서 별도로 로드됨
  it.skip('초기화 실패 후 재시도가 가능해야 함', async () => {
    const mockRegistry = ComponentRegistry.getInstance();

    // 첫 번째 시도 실패
    (mockRegistry.loadComponents as any).mockRejectedValueOnce(
      new Error('First attempt failed')
    );

    await expect(
      initTemplateEngine({ templateId: 'test-template' })
    ).rejects.toThrow('First attempt failed');

    // 두 번째 시도 성공
    (mockRegistry.loadComponents as any).mockResolvedValueOnce(undefined);

    await initTemplateEngine({ templateId: 'test-template' });

    const state = getState();
    expect(state.isInitialized).toBe(true);
  });
});
