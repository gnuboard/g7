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

  it('reloadExtensions 는 TemplateApp.reloadExtensionState 를 호출해야 합니다', async () => {
    await dispatcher.dispatchAction({ handler: 'reloadExtensions' } as ActionDefinition, context);
    expect(reloadExtensionState).toHaveBeenCalledTimes(1);
  });

  it('reloadRoutes 는 TemplateApp.reloadExtensionState 로 위임해야 합니다 (하위 호환)', async () => {
    await dispatcher.dispatchAction({ handler: 'reloadRoutes' } as ActionDefinition, context);
    expect(reloadExtensionState).toHaveBeenCalledTimes(1);
  });

  it('reloadTranslations 는 TemplateApp.reloadExtensionState 로 위임해야 합니다 (하위 호환)', async () => {
    await dispatcher.dispatchAction({ handler: 'reloadTranslations' } as ActionDefinition, context);
    expect(reloadExtensionState).toHaveBeenCalledTimes(1);
  });

  it('TemplateApp 이 초기화되지 않았으면 에러 없이 경고 후 반환해야 합니다', async () => {
    delete (globalThis as any).__templateApp;
    await expect(
      dispatcher.dispatchAction({ handler: 'reloadExtensions' } as ActionDefinition, context)
    ).resolves.not.toThrow();
    expect(reloadExtensionState).not.toHaveBeenCalled();
  });
});
