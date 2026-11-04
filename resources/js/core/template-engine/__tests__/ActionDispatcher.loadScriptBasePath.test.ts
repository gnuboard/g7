/**
 * `loadScript` 액션 — 하위 폴더 설치의 마운트 접두사
 *
 * 레이아웃은 same-origin 스크립트 주소를 접두사 없이(`/api/plugins/assets/…`) 적는다. 사이트가 하위 폴더
 * (`https://host/g7/`)에 설치되면 그 주소를 그대로 실을 때 요청이 사이트 밖으로 나가 404 가 된다. 레이아웃
 * `scripts[]`(TemplateApp.loadLayoutScript)와 같은 규칙으로, 싣는 시점에 접두사를 붙인다(멱등).
 *
 * @scenario asset_class=vendored, outcome=loaded
 * @effects layout_script_url_carries_the_site_base_prefix
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher, ActionDefinition } from '../ActionDispatcher';

describe('loadScript — 하위 폴더 설치 마운트 접두사', () => {
  let dispatcher: ActionDispatcher;
  let appended: HTMLScriptElement[];

  beforeEach(() => {
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    appended = [];
    (ActionDispatcher as any).loadedScripts = new Set();
    (ActionDispatcher as any).loadingScripts = new Map();

    vi.spyOn(document.head, 'appendChild').mockImplementation(((node: Node) => {
      const script = node as HTMLScriptElement;
      if (script.tagName === 'SCRIPT') {
        appended.push(script);
        setTimeout(() => script.onload?.(new Event('load')), 5);
      }
      return node;
    }) as any);
    vi.spyOn(document, 'getElementById').mockReturnValue(null);
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete (window as any).G7Config;
  });

  const run = async (src: string, id?: string): Promise<void> => {
    const action: ActionDefinition = { type: 'click', handler: 'loadScript', params: id ? { src, id } : { src } };
    const handler = dispatcher.createHandler(action, {});
    await handler({ preventDefault: vi.fn(), stopPropagation: vi.fn(), target: null } as unknown as Event);
    await new Promise((resolve) => setTimeout(resolve, 20));
  };

  /**
   * @scenario asset_class=vendored, outcome=loaded
   * @effects layout_script_url_carries_the_site_base_prefix
   */
  it('하위 폴더 설치에서는 루트 상대 src 에 접두사를 붙이고, 이미 붙은 값·외부 호스트는 그대로 둔다', async () => {
    (window as any).G7Config = { trustedScriptHosts: ['t1.daumcdn.net'], basePath: '/g7' };

    await run('/api/plugins/assets/vendor-plugin/dist/vendor/sdk.js');
    await run('/g7/api/plugins/assets/vendor-plugin/dist/vendor/other.js', 'other_sdk');
    await run('//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js', 'daum_postcode_script');

    expect(appended.map((s) => s.getAttribute('src'))).toEqual([
      '/g7/api/plugins/assets/vendor-plugin/dist/vendor/sdk.js',
      '/g7/api/plugins/assets/vendor-plugin/dist/vendor/other.js',
      '//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js',
    ]);
    // 중복 판정 키는 레이아웃이 적은 주소에서 만든다
    expect(appended[0].id).toBe('script__api_plugins_assets_vendor_plugin_dist_vendor_sdk_js');
  });

  /**
   * @scenario asset_class=vendored, outcome=loaded
   * @effects layout_script_url_carries_the_site_base_prefix
   */
  it('루트 설치에서는 레이아웃이 적은 주소를 그대로 싣는다 (종전 동작)', async () => {
    (window as any).G7Config = { trustedScriptHosts: [] };

    await run('/api/plugins/assets/vendor-plugin/dist/vendor/sdk.js');

    expect(appended.map((s) => s.getAttribute('src'))).toEqual(['/api/plugins/assets/vendor-plugin/dist/vendor/sdk.js']);
  });
});
