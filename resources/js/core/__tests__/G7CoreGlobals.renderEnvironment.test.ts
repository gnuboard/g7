/**
 * 회귀: G7Core 셀 렌더 래퍼가 미리보기 렌더 환경을 호스트 값보다 먼저 쓴다 (이슈 #141)
 *
 * `G7Core.renderItemChildren`·`renderComponentLayout`·`renderExpandContent` 는 종전에
 * 호스트(관리자 화면)의 번역 컨텍스트·엔진·dispatcher·컴포넌트 목록을 무조건 주입했다.
 * 레이아웃 편집기 미리보기는 이 값들을 자기 것(콘텐츠 언어·격리 dispatcher·편집 대상 템플릿
 * 레지스트리·미리보기 폭)으로 바꿔 쓰므로, 셀만 호스트 값으로 그려지는 비대칭이 생겼다.
 *
 * 우선순위: 명시 options > 렌더 환경(`options.renderEnv` 또는
 * `componentContext.renderEnvRef.current`) > 호스트 값.
 *
 * 기존 세 테스트의 `./helpers` 배럴 모킹을 건드리지 않도록 별도 파일로 둔다.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { initializeG7CoreGlobals, G7CoreDependencies } from '../template-engine/G7CoreGlobals';
import { renderItemChildren } from '../template-engine/helpers';

const HOST_MAP = { Cell: 'hostCell', HostOnly: 'hostOnly' };

vi.mock('../template-engine/ComponentRegistry', () => ({
  ComponentRegistry: { getInstance: vi.fn(() => ({ getComponentMap: vi.fn(() => ({ Cell: 'hostCell', HostOnly: 'hostOnly' })) })) },
}));
vi.mock('../template-engine/TranslationEngine', () => ({ TranslationEngine: vi.fn(), TranslationContext: {} }));
vi.mock('../template-engine/ActionDispatcher', () => ({ ActionDispatcher: vi.fn() }));
vi.mock('../template-engine/DataBindingEngine', () => ({ DataBindingEngine: vi.fn(), dataBindingEngine: {} }));
vi.mock('../template-engine/DataSourceManager', () => ({ DataSourceManager: vi.fn(), dataSourceManager: {} }));
vi.mock('../template-engine/DynamicRenderer', () => ({ default: () => null }));
vi.mock('../template-engine/ResponsiveManager', () => ({ responsiveManager: {}, BREAKPOINT_PRESETS: {} }));
vi.mock('../template-engine/TransitionContext', () => ({ useTransitionState: vi.fn(() => ({ isPending: false })) }));
vi.mock('../template-engine/TranslationContext', () => ({
  useTranslation: vi.fn(() => ({ t: (k: string) => k })),
  TranslationProvider: () => null,
  TranslationReactContext: {},
}));
vi.mock('../template-engine/ResponsiveContext', () => ({
  useResponsive: vi.fn(() => ({ width: 1024, isMobile: false })),
  ResponsiveProvider: () => null,
  ResponsiveContext: {},
}));
vi.mock('../auth/AuthManager', () => ({ AuthManager: { getInstance: vi.fn() } }));
vi.mock('../api/ApiClient', () => ({ getApiClient: vi.fn(() => ({ get: vi.fn(), post: vi.fn() })) }));
vi.mock('../websocket/WebSocketManager', () => ({ WebSocketManager: vi.fn() }));
vi.mock('../template-engine/helpers', () => ({
  renderItemChildren: vi.fn(() => []),
  createChangeEvent: vi.fn(),
  createClickEvent: vi.fn(),
  createSubmitEvent: vi.fn(),
  createKeyboardEvent: vi.fn(),
  mergeClasses: vi.fn((...a: string[]) => a.join(' ')),
  conditionalClass: vi.fn(() => ''),
  joinClasses: vi.fn((...a: (string | false | undefined)[]) => a.filter(Boolean).join(' ')),
}));

const host = {
  translationEngine: { id: 'hostTE', translate: vi.fn((k: string) => k) } as any,
  translationContext: { templateId: 'host', locale: 'ko' },
  bindingEngine: { id: 'hostBE' } as any,
  actionDispatcher: { id: 'hostAD' } as any,
  templateMetadata: { locales: ['ko'] },
};

function createMockDependencies(): G7CoreDependencies {
  return {
    getState: vi.fn(() => host),
    transitionManager: { getIsPending: vi.fn(() => false), subscribe: vi.fn(() => () => {}) },
    responsiveManager: {},
    webSocketManager: {
      subscribe: vi.fn(() => 'sub'),
      unsubscribe: vi.fn(),
      leaveChannel: vi.fn(),
      disconnect: vi.fn(),
      isInitialized: vi.fn(() => true),
      getSubscriptionCount: vi.fn(() => 0),
    } as any,
  };
}

/** 미리보기 렌더 환경 대역 */
const previewEnv = () => ({
  responsiveWidth: 390,
  translationContext: { templateId: 'edited', locale: 'en' },
  translationEngine: { id: 'envTE' } as any,
  bindingEngine: { id: 'envBE' } as any,
  actionDispatcher: { id: 'envAD' } as any,
  componentRegistry: { getComponentMap: () => ({ Cell: 'envCell', EnvOnly: 'envOnly' }) } as any,
  isEditMode: true,
});

/** 마지막 renderItemChildren 호출의 (componentMap, options) */
const lastCall = () => {
  const calls = (renderItemChildren as any).mock.calls;
  const call = calls[calls.length - 1];
  return { componentMap: call[2], options: call[4] };
};

describe('G7Core 셀 렌더 래퍼 — 렌더 환경 우선', () => {
  let G7Core: any;

  beforeEach(() => {
    delete (window as any).G7Core;
    (window as any).__templateApp = { getGlobalState: () => ({}) };
    initializeG7CoreGlobals(createMockDependencies());
    G7Core = (window as any).G7Core;
    (renderItemChildren as any).mockClear();
  });

  afterEach(() => {
    delete (window as any).__templateApp;
  });

  /**
   * @scenario wrapper=renderItemChildren, render_env=options.renderEnv
   * @effects preview_cell_wrapper_uses_render_env_over_host
   */
  it('T-G1 renderItemChildren: env 의 폭·언어·dispatcher·엔진·편집 모드·레지스트리 맵이 호스트보다 우선', () => {
    const env = previewEnv();
    G7Core.renderItemChildren([{}], { row: {} }, { Cell: 'callerCell', CallerOnly: 'callerOnly' }, 'k', { renderEnv: env });

    const { componentMap, options } = lastCall();
    expect(options.translationContext).toEqual({ templateId: 'edited', locale: 'en' });
    expect(options.translationEngine).toBe(env.translationEngine);
    expect(options.bindingEngine).toBe(env.bindingEngine);
    expect(options.actionDispatcher).toBe(env.actionDispatcher);
    expect(options.responsiveWidth).toBe(390);
    expect(options.isEditMode).toBe(true);
    // 환경 레지스트리가 이기고, 호출자에만 있는 항목은 남는다
    expect(componentMap).toEqual({ Cell: 'envCell', CallerOnly: 'callerOnly', EnvOnly: 'envOnly' });
  });

  /**
   * @scenario wrapper=renderItemChildren, render_env=component_context_ref, explicit=actionDispatcher
   * @effects preview_render_env_priority_explicit_over_env_over_host
   */
  it('T-G1 renderItemChildren: componentContext.renderEnvRef 로도 받고, 명시 options 가 env 를 이긴다', () => {
    const env = previewEnv();
    const explicit = { id: 'explicitAD' };
    G7Core.renderItemChildren([{}], { row: {} }, {}, 'k', {
      componentContext: { renderEnvRef: { current: env } },
      actionDispatcher: explicit,
    });

    const { options } = lastCall();
    expect(options.actionDispatcher).toBe(explicit);
    expect(options.translationContext.locale).toBe('en');
    expect(options.responsiveWidth).toBe(390);
  });

  /**
   * @scenario wrapper=renderComponentLayout, render_env=4th_arg
   * @effects preview_cell_wrapper_uses_render_env_over_host
   */
  it('T-G2 renderComponentLayout: 4번째 인자 renderEnv 가 전달되고 레지스트리가 env 것', () => {
    const env = previewEnv();
    G7Core.renderComponentLayout([{ name: 'Cell' }], { item: {} }, 'k', { renderEnv: env });

    const { componentMap, options } = lastCall();
    expect(componentMap.Cell).toBe('envCell');
    expect(componentMap.HostOnly).toBe('hostOnly');
    expect(options.actionDispatcher).toBe(env.actionDispatcher);
    expect(options.translationContext.locale).toBe('en');
    expect(options.responsiveWidth).toBe(390);
    expect(options.isEditMode).toBe(true);
  });

  /**
   * @scenario wrapper=renderExpandContent, render_env=component_context_ref
   * @effects preview_cell_wrapper_uses_render_env_over_host
   */
  it('T-G3 renderExpandContent: componentContext.renderEnvRef.current 의 값이 전달된다', () => {
    const env = previewEnv();
    G7Core.renderExpandContent({
      children: [{ name: 'Cell' }],
      row: { id: 1 },
      keyPrefix: 'e',
      componentContext: { state: {}, renderEnvRef: { current: env } },
    });

    const { componentMap, options } = lastCall();
    expect(componentMap.Cell).toBe('envCell');
    expect(options.actionDispatcher).toBe(env.actionDispatcher);
    expect(options.bindingEngine).toBe(env.bindingEngine);
    expect(options.translationEngine).toBe(env.translationEngine);
    expect(options.translationContext.locale).toBe('en');
    expect(options.responsiveWidth).toBe(390);
    expect(options.isEditMode).toBe(true);
  });

  /**
   * @scenario wrapper=all, render_env=absent
   * @effects preview_render_env_falls_back_to_host_outside_preview
   */
  it('T-G4 env·옵션이 없으면 호스트 state 와 호스트 레지스트리를 쓴다 (편집기 밖 무변화)', () => {
    G7Core.renderItemChildren([{}], { row: {} }, { Cell: 'callerCell' }, 'k');
    let { componentMap, options } = lastCall();
    expect(componentMap).toEqual({ Cell: 'callerCell' });
    expect(options.translationContext).toEqual(host.translationContext);
    expect(options.actionDispatcher).toBe(host.actionDispatcher);
    expect(options.responsiveWidth).toBeUndefined();
    expect(options.isEditMode).toBeUndefined();

    G7Core.renderComponentLayout([{ name: 'Cell' }], { item: {} }, 'k');
    ({ componentMap, options } = lastCall());
    expect(componentMap).toEqual(HOST_MAP);
    expect(options.bindingEngine).toBe(host.bindingEngine);
    expect(options.responsiveWidth).toBeUndefined();

    G7Core.renderExpandContent({ children: [{ name: 'Cell' }], row: { id: 1 }, keyPrefix: 'e' });
    ({ componentMap, options } = lastCall());
    expect(componentMap).toEqual(HOST_MAP);
    expect(options.actionDispatcher).toBe(host.actionDispatcher);
    expect(options.translationEngine).toBe(host.translationEngine);
    expect(options.responsiveWidth).toBeUndefined();
  });
});
