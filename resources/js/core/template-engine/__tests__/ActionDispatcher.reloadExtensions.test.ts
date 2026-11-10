/**
 * ActionDispatcher.reloadExtensions 테스트 (engine-v1.19.0)
 *
 * 확장 라이프사이클(install/activate/deactivate/uninstall) 직후 호출되는
 * `reloadExtensions` 통합 핸들러 및 하위 호환 핸들러(`reloadRoutes`, `reloadTranslations`)의
 * TemplateApp.reloadExtensionState() 위임 동작을 검증합니다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher, ActionDefinition, ActionContext } from '../ActionDispatcher';

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      login: vi.fn(),
      logout: vi.fn(),
    })),
  },
}));

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({ getToken: vi.fn() })),
}));

describe('ActionDispatcher.reloadExtensions (engine-v1.19.0)', () => {
  let dispatcher: ActionDispatcher;
  let reloadExtensionState: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    reloadExtensionState = vi.fn().mockResolvedValue(undefined);

    (globalThis as any).window = globalThis as any;
    (globalThis as any).__templateApp = {
      reloadExtensionState,
      getRouter: vi.fn(() => ({ loadRoutes: vi.fn() })),
      getConfig: vi.fn(() => ({ templateId: 'sirsoft-admin_basic', locale: 'ko' })),
    };
  });

  afterEach(() => {
    delete (globalThis as any).__templateApp;
    vi.restoreAllMocks();
  });

  const context: ActionContext = { props: {}, event: null as any };

  /**
   * @scenario case=reload_extensions_delegation
   * @effects extension_state_resync_delegates_to_template_app
   */
  it('reloadExtensions 는 TemplateApp.reloadExtensionState 를 호출해야 합니다', async () => {
    await dispatcher.dispatchAction({ handler: 'reloadExtensions' } as ActionDefinition, context);
    expect(reloadExtensionState).toHaveBeenCalledTimes(1);
  });

  /**
   * @scenario case=reload_extensions_delegation
   * @effects extension_state_resync_delegates_to_template_app
   */
  it('reloadRoutes 는 TemplateApp.reloadExtensionState 로 위임해야 합니다 (하위 호환)', async () => {
    await dispatcher.dispatchAction({ handler: 'reloadRoutes' } as ActionDefinition, context);
    expect(reloadExtensionState).toHaveBeenCalledTimes(1);
  });

  /**
   * @scenario case=reload_extensions_delegation
   * @effects extension_state_resync_delegates_to_template_app
   */
  it('reloadTranslations 는 TemplateApp.reloadExtensionState 로 위임해야 합니다 (하위 호환)', async () => {
    await dispatcher.dispatchAction({ handler: 'reloadTranslations' } as ActionDefinition, context);
    expect(reloadExtensionState).toHaveBeenCalledTimes(1);
  });

  /**
   * 확장을 켠 직후 그 확장의 JS·CSS 를 다시 붙이는 일은 보조 작업이다 — 실패해도(곧바로 다시 꺼짐·라우트 캐시를 다시 굽는
   * 사이의 404·500 등) 켜기 자체는 끝났으므로 로그만 남긴다. 종전에는 위임을 executeAction 으로 해서, 바깥 try/catch 가
   * 삼키기 전에 기본 오류 처리가 내부 문구 「Failed to execute action: reloadPluginHandlers」를 토스트로 띄웠다(#882 실측).
   *
   * @scenario case=card_toggle_pending
   * @effects extension_asset_reload_failure_is_silent
   */
  it.each([
    ['pluginInfo', 'reloadPluginHandlers'],
    ['moduleInfo', 'reloadModuleHandlers'],
  ])('%s 자산 재로드가 실패해도 토스트를 띄우지 않고 reloadExtensions 는 끝까지 간다', async (infoKey, handlerName) => {
    const failing = vi.fn().mockRejectedValue(new Error('Failed to load stylesheet: /api/plugins/assets/x/dist/css/plugin.css'));
    dispatcher.registerHandler(handlerName, failing);
    const toast = vi.spyOn(dispatcher as any, 'handleToast');

    const result = await dispatcher.executeAction(
      { handler: 'reloadExtensions', params: { [infoKey]: { identifier: 'acme-x' }, action: 'add' } } as ActionDefinition,
      context
    );

    expect(failing).toHaveBeenCalledTimes(1);
    expect(reloadExtensionState).toHaveBeenCalledTimes(1);
    expect(toast).not.toHaveBeenCalled();
    expect(result.success).toBe(true);
  });

  /**
   * @scenario case=reload_extensions_delegation
   * @effects extension_state_resync_delegates_to_template_app
   */
  it('TemplateApp 이 초기화되지 않았으면 에러 없이 경고 후 반환해야 합니다', async () => {
    delete (globalThis as any).__templateApp;
    await expect(
      dispatcher.dispatchAction({ handler: 'reloadExtensions' } as ActionDefinition, context)
    ).resolves.not.toThrow();
    expect(reloadExtensionState).not.toHaveBeenCalled();
  });
});
