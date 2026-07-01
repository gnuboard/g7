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
    const adminCssUrl = '/api/admin/templates/sirsoft-basic/editor/components.css?v=1';
    fetchSpy.mockImplementation(async (url: string) => {
      if (url.includes('/editor-assets')) {
        return {
          ok: true,
          json: async () => ({
            data: { identifier: 'sirsoft-basic', js: [], css: [adminCssUrl], manifest_present: true },
          }),
        };
      }
      if (url.includes('/editor/components.css')) {
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
    const cssCall = fetchSpy.mock.calls.find((c) => String(c[0]).includes('/editor/components.css'));
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
});
