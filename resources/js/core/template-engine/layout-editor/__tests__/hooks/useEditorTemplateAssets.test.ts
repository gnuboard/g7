// e2e:allow 이슈 #486 단위 A 는 서버 라우트 이중화 전용. 이 파일 변경은 편집기 CSS
// 엔드포인트 개명(editor/components.css → component-styles.css)을 따라간 기대값 수정뿐이며
// 브라우저 거동은 불변(URL 은 서버가 생성해 내려주고 hook 은 그대로 fetch).
// 자산 URL 모드의 브라우저 시나리오 spec 은 자가 복구를 도입하는 단위 D 에서 추가한다.

/**
 * useEditorTemplateAssets 회귀 테스트
 *
 * 호스트 페이지의 ComponentRegistry 싱글톤이 점유된 상태에서, 편집 대상
 * 템플릿의 IIFE 번들 + lang dictionary 를 격리 인스턴스에 부트스트랩하는
 * hook 의 동작을 가드.
 */


import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { useEditorTemplateAssets } from '../../hooks/useEditorTemplateAssets';
import { ComponentRegistry } from '../../../ComponentRegistry';

describe('useEditorTemplateAssets', () => {
  let fetchSpy: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    ComponentRegistry.resetInstance();
    fetchSpy = vi.fn();
    (global as any).fetch = fetchSpy;
    // 호스트 페이지가 같은 globalVarName 을 점유한 경우의 회귀 케이스 차단
    if (typeof window !== 'undefined') {
      delete (window as any).SirsoftBasic;
      delete (window as any).SirsoftAdminBasic;
      window.localStorage?.clear();
    }
    // 기존 주입된 <script>/<link> 태그 정리
    if (typeof document !== 'undefined') {
      document.querySelectorAll('[data-g7le-asset]').forEach((n) => n.remove());
    }
  });

  afterEach(() => {
    ComponentRegistry.resetInstance();
  });

  it('편집 자산 매니페스트 + components.json + lang 순차 fetch', async () => {
    fetchSpy.mockImplementation(async (url: string) => {
      if (url.includes('/editor-assets')) {
        return {
          ok: true,
          json: async () => ({ data: { identifier: 'sirsoft-basic', js: [], css: [], manifest_present: true } }),
        };
      }
      if (url.includes('/components.json')) {
        return {
          ok: true,
          json: async () => ({
            version: '1.0.0',
            templateId: 'sirsoft-basic',
            components: { basic: [], composite: [], layout: [] },
          }),
        };
      }
      if (url.includes('/lang/')) {
        return { ok: true, json: async () => ({ greeting: '안녕하세요' }) };
      }
      return { ok: false, status: 404, json: async () => ({}) };
    });

    // IIFE 번들이 비어있어도 통과하도록 전역 변수에 빈 객체 미리 노출
    (window as any).SirsoftBasic = {};

    const { result } = renderHook(() => useEditorTemplateAssets('sirsoft-basic', 'ko'));

    await waitFor(
      () => {
        expect(result.current.isReady).toBe(true);
      },
      { timeout: 3000 },
    );

    expect(result.current.componentRegistry).not.toBeNull();
    expect(result.current.translationEngine).not.toBeNull();
    expect(result.current.error).toBeNull();

    // editor-assets 매니페스트는 admin 경로 사용
    const assetCall = fetchSpy.mock.calls.find((c) =>
      String(c[0]).includes('/api/admin/templates/sirsoft-basic/editor-assets'),
    );
    expect(assetCall).toBeDefined();
  });

  it('editor-assets fetch 실패 시 error 메시지 + 인스턴스 null', async () => {
    fetchSpy.mockResolvedValue({ ok: false, status: 500, statusText: 'Server Error', json: async () => ({}) });

    const { result } = renderHook(() => useEditorTemplateAssets('sirsoft-basic', 'ko'));

    await waitFor(() => {
      expect(result.current.error).not.toBeNull();
    });

    expect(result.current.isReady).toBe(false);
    expect(result.current.componentRegistry).toBeNull();
    expect(result.current.translationEngine).toBeNull();
  });

  it('Sanctum 토큰이 있으면 editor-assets 호출에 Bearer 헤더 부착', async () => {
    window.localStorage.setItem('auth_token', 'tok-abc');
    fetchSpy.mockResolvedValue({
      ok: true,
      json: async () => ({ data: { identifier: 'sirsoft-basic', js: [], css: [], manifest_present: true } }),
    });

    renderHook(() => useEditorTemplateAssets('sirsoft-basic', 'ko'));

    await waitFor(() => {
      expect(fetchSpy).toHaveBeenCalled();
    });

    const assetCall = fetchSpy.mock.calls.find((c) =>
      String(c[0]).includes('/editor-assets'),
    );
    expect(assetCall?.[1]?.headers?.Authorization).toBe('Bearer tok-abc');
  });

  //  결함C 회귀 — 권한 가드된 admin CSS 엔드포인트는 `<link>` 가 Authorization 헤더를
  // 못 실어 500(Route[login])으로 떨어지므로, Bearer fetch → `<style>` 로 주입해야 한다.
  it('권한 가드 admin CSS 는 Bearer fetch → <style> 주입 (link 아님)', async () => {
    window.localStorage.setItem('auth_token', 'tok-css');
    const adminCssUrl = '/api/admin/templates/sirsoft-basic/editor/component-styles.css?v=1';
    fetchSpy.mockImplementation(async (url: string) => {
      if (url.includes('/editor-assets')) {
        return {
          ok: true,
          json: async () => ({
            data: { identifier: 'sirsoft-basic', js: [], css: [adminCssUrl], manifest_present: true },
          }),
        };
      }
      if (url.includes('/editor/component-styles.css')) {
        return { ok: true, text: async () => '.g7le-preview-dark .x{color:red}' };
      }
      if (url.includes('/components.json')) {
        return { ok: true, json: async () => ({ version: '1.0.0', templateId: 'sirsoft-basic', components: { basic: [], composite: [], layout: [] } }) };
      }
      if (url.includes('/lang/')) return { ok: true, json: async () => ({}) };
      return { ok: false, status: 404, json: async () => ({}) };
    });
    (window as any).SirsoftBasic = {};

    const { result } = renderHook(() => useEditorTemplateAssets('sirsoft-basic', 'ko'));
    await waitFor(() => expect(result.current.isReady).toBe(true), { timeout: 3000 });

    // <style> 로 주입됨 (link 아님)
    const styleEl = document.querySelector(`style[data-g7le-asset="${adminCssUrl}"]`);
    const linkEl = document.querySelector(`link[data-g7le-asset="${adminCssUrl}"]`);
    expect(styleEl).not.toBeNull();
    expect(linkEl).toBeNull();
    expect(styleEl?.textContent).toContain('.g7le-preview-dark');

    // CSS fetch 에 Bearer 헤더 부착
    const cssCall = fetchSpy.mock.calls.find((c) => String(c[0]).includes('/editor/component-styles.css'));
    expect(cssCall?.[1]?.headers?.Authorization).toBe('Bearer tok-css');
  });

  it('공개/외부 CSS 는 <link> 로 주입 (Bearer fetch 안 함)', async () => {
    const publicCssUrl = '/api/templates/assets/sirsoft-basic/css/components.css?v=1';
    fetchSpy.mockImplementation(async (url: string) => {
      if (url.includes('/editor-assets')) {
        return {
          ok: true,
          json: async () => ({
            data: { identifier: 'sirsoft-basic', js: [], css: [publicCssUrl], manifest_present: true },
          }),
        };
      }
      if (url.includes('/components.json')) {
        return { ok: true, json: async () => ({ version: '1.0.0', templateId: 'sirsoft-basic', components: { basic: [], composite: [], layout: [] } }) };
      }
      if (url.includes('/lang/')) return { ok: true, json: async () => ({}) };
      return { ok: false, status: 404, json: async () => ({}) };
    });
    (window as any).SirsoftBasic = {};

    const { result } = renderHook(() => useEditorTemplateAssets('sirsoft-basic', 'ko'));
    await waitFor(() => expect(result.current.isReady).toBe(true), { timeout: 3000 });

    // <link> 로 주입됨 (style 아님), 공개 CSS 는 fetch 로 본문을 받지 않음
    expect(document.querySelector(`link[data-g7le-asset="${publicCssUrl}"]`)).not.toBeNull();
    expect(document.querySelector(`style[data-g7le-asset="${publicCssUrl}"]`)).toBeNull();
    const cssFetchCall = fetchSpy.mock.calls.find((c) => String(c[0]) === publicCssUrl);
    expect(cssFetchCall).toBeUndefined();
  });

  // 편집기 독립 TranslationEngine 은 cacheVersion 기본 0
  // 이라 버전 없이 lang 을 fetch 해 stale(신규 다국어 키 누락) 응답을 받던 결함.
  // config.json(비캐시)의 최신 cache_version 을 읽어 그 버전으로 lang 을 fetch 해야 한다.
  it('config.json 의 최신 cache_version 으로 lang 을 ?v= 붙여 fetch 한다 (캐시 무효화 구멍 차단)', async () => {
    const CV = 1780399682;
    fetchSpy.mockImplementation(async (url: string) => {
      if (url.includes('/editor-assets')) {
        return { ok: true, json: async () => ({ data: { identifier: 'sirsoft-basic', js: [], css: [], manifest_present: true } }) };
      }
      if (url.includes('/config.json')) {
        return { ok: true, json: async () => ({ data: { cache_version: CV } }) };
      }
      if (url.includes('/components.json')) {
        return { ok: true, json: async () => ({ version: '1.0.0', templateId: 'sirsoft-basic', components: { basic: [], composite: [], layout: [] } }) };
      }
      if (url.includes('/lang/')) {
        return { ok: true, json: async () => ({ editor: { state: { x: 'v' } } }) };
      }
      return { ok: false, status: 404, json: async () => ({}) };
    });
    (window as any).SirsoftBasic = {};

    const { result } = renderHook(() => useEditorTemplateAssets('sirsoft-basic', 'en'));
    await waitFor(() => expect(result.current.isReady).toBe(true), { timeout: 3000 });

    // config.json 을 조회했고
    const cfgCall = fetchSpy.mock.calls.find((c) => String(c[0]).includes('/config.json'));
    expect(cfgCall).toBeDefined();
    // lang fetch 가 최신 cache_version 을 ?v= 로 달았다 (stale 캐시 키 회피)
    const langCall = fetchSpy.mock.calls.find((c) => /\/lang\/en\.json/.test(String(c[0])));
    expect(langCall).toBeDefined();
    expect(String(langCall![0])).toContain(`v=${CV}`);
  });

  it('config.json 조회 실패 시 cacheVersion 없이도 디그레이드 동작 (lang fetch 는 수행)', async () => {
    fetchSpy.mockImplementation(async (url: string) => {
      if (url.includes('/editor-assets')) {
        return { ok: true, json: async () => ({ data: { identifier: 'sirsoft-basic', js: [], css: [], manifest_present: true } }) };
      }
      if (url.includes('/config.json')) return { ok: false, status: 500, json: async () => ({}) };
      if (url.includes('/components.json')) {
        return { ok: true, json: async () => ({ version: '1.0.0', templateId: 'sirsoft-basic', components: { basic: [], composite: [], layout: [] } }) };
      }
      if (url.includes('/lang/')) return { ok: true, json: async () => ({ greeting: 'hi' }) };
      return { ok: false, status: 404, json: async () => ({}) };
    });
    (window as any).SirsoftBasic = {};

    const { result } = renderHook(() => useEditorTemplateAssets('sirsoft-basic', 'en'));
    await waitFor(() => expect(result.current.isReady).toBe(true), { timeout: 3000 });
    expect(result.current.translationEngine).not.toBeNull();
    const langCall = fetchSpy.mock.calls.find((c) => /\/lang\/en\.json/.test(String(c[0])));
    expect(langCall).toBeDefined();
  });

  /**
   * 편집 자산 매니페스트가 지시하는 `js` 는 서버 응답이지만, 붙는 자리는 **관리자 document** 다.
   * 확장 자산 재로드·프리뷰 캔버스와 같은 출처 게이트를 받아야 한다 — 이 경로만 게이트가
   * 없으면 편집기 진입만으로 임의 원격 코드가 관리자 세션에서 실행된다.
   */
  describe('스크립트 주입 출처 게이트', () => {
    /**
     * 매니페스트가 주어진 js 목록을 돌려주도록 fetch 를 세운다.
     *
     * @param js 매니페스트 js 배열
     * @return void
     */
    function mockManifest(js: string[]): void {
      fetchSpy.mockImplementation(async (url: string) => {
        if (url.includes('/editor-assets')) {
          return {
            ok: true,
            json: async () => ({
              data: { identifier: 'sirsoft-basic', js, css: [], manifest_present: true },
            }),
          };
        }
        if (url.includes('/config.json')) return { ok: true, json: async () => ({ cache_version: 1 }) };
        if (url.includes('/components.json')) {
          return {
            ok: true,
            json: async () => ({
              version: '1.0.0',
              templateId: 'sirsoft-basic',
              components: { basic: [], composite: [], layout: [] },
            }),
          };
        }
        if (url.includes('/lang/')) return { ok: true, json: async () => ({}) };
        return { ok: false, status: 404, json: async () => ({}) };
      });
    }

    beforeEach(() => {
      (window as any).G7Config = { trustedScriptHosts: [] };
      // 주입된 태그가 영원히 pending 되지 않도록 즉시 load 를 발화시킨다
      vi.spyOn(document.head, 'appendChild').mockImplementation(((node: any) => {
        if (node.tagName === 'SCRIPT' || node.tagName === 'LINK') {
          document.body.appendChild(node);
          queueMicrotask(() => node.dispatchEvent(new Event('load')));
          return node;
        }
        return HTMLHeadElement.prototype.appendChild.call(document.head, node);
      }) as any);
    });

    afterEach(() => {
      vi.restoreAllMocks();
      delete (window as any).G7Config;
      document.querySelectorAll('[data-g7le-asset]').forEach((n) => n.remove());
    });

    it('미신뢰 외부 src 는 주입하지 않고 오류로 끝난다', async () => {
      mockManifest(['https://cdn.evil.com/x.js']);

      const { result } = renderHook(() => useEditorTemplateAssets('sirsoft-basic', 'en'));

      await waitFor(() => expect(result.current.error).not.toBeNull(), { timeout: 3000 });
      expect(
        document.querySelectorAll('script[data-g7le-asset="https://cdn.evil.com/x.js"]').length,
      ).toBe(0);
    });

    it('same-origin src 는 주입된다 (과차단 없음)', async () => {
      mockManifest(['/api/templates/assets/sirsoft-basic/js/components.iife.js']);

      renderHook(() => useEditorTemplateAssets('sirsoft-basic', 'en'));

      // 게이트를 통과해 실제로 태그가 붙는지만 본다 — 그 뒤 부트스트랩(전역 확보 등)은
      // 이 축의 관심사가 아니다.
      await waitFor(
        () =>
          expect(
            document.querySelectorAll(
              'script[data-g7le-asset="/api/templates/assets/sirsoft-basic/js/components.iife.js"]',
            ).length,
          ).toBe(1),
        { timeout: 3000 },
      );
    });
  });
});
