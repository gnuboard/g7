/**
 * 편집기 동적 규칙 — `<style>` 요소 없이 붙는지 잠근다 (공개 #163)
 *
 * 엄격한 스타일 정책(`style-src 'self'; style-src-attr 'none'`)에서는 스크립트가 만든 `<style>` 요소가
 * 적용되지 않는다. 편집기가 붙이는 규칙(툴바, 슬롯 가시화, 빈 표 셀, 미리보기 컨테이너 지정, 권한 가드 CSS)은
 * 모두 생성형 스타일시트(`adoptedStyleSheets`)로 가야 한다.
 *
 * jsdom 은 생성형 시트를 지원하지 않아 헬퍼가 `<style>` 폴백으로 내려가므로, 여기서는
 * `CSSStyleSheet.prototype.replaceSync` 와 `document.adoptedStyleSheets` 를 주입해 지원 환경을 흉내 낸다.
 *
 * @scenario-manifest tests/scenarios/strict-style-csp-compat.yaml
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, renderHook, screen, waitFor } from '@testing-library/react';

const rewriteSheetsMock = vi.hoisted(() => vi.fn());

vi.mock('../utils/previewMediaIsolation/sheetRewriter', () => ({
  createRewriteState: () => ({}),
  restoreAll: vi.fn(),
  rewriteSheets: (...args: unknown[]) => {
    rewriteSheetsMock(...args);
    return { sheets: (args[0] as unknown[]).length, converted: 0, unsupported: 0, skipped: 0 };
  },
}));

import { LayoutEditorChrome } from '../LayoutEditorChrome';
import { TranslationProvider } from '../../TranslationContext';
import { TranslationEngine } from '../../TranslationEngine';
import { ComponentRegistry } from '../../ComponentRegistry';
import { getCssText, getSheet, has } from '../../../style/RuntimeStyleSheet';
import {
  BASE_SLOT_LABEL_VAR,
  BASE_SLOT_STYLE_CSS,
  BASE_SLOT_STYLE_KEY,
  DEVICE_ROW_STYLE_KEY,
  DEVICE_TOOLBAR_STYLE_KEY,
  EDIT_ENTRY_FAB_STYLE_CSS,
  EDIT_ENTRY_FAB_STYLE_KEY,
  EMPTY_CELL_STYLE_KEY,
  TOOLBAR_STYLE_KEY,
  applyBaseSlotStyle,
  ensureEmptyCellStyle,
  removeBaseSlotStyle,
  toCssString,
} from '../utils/editorRuntimeStyles';
import { usePreviewMediaIsolation, MEDIA_ISOLATION_STYLE_KEY } from '../hooks/usePreviewMediaIsolation';
import { useEditorTemplateAssets, editorAssetStyleKey } from '../hooks/useEditorTemplateAssets';
import { EditEntryFab } from '../components/EditEntryFab';

/** 생성형 시트 지원 환경 스텁 — 되돌리는 함수를 돌려준다 */
function installAdoptedStub(): { replaceSync: ReturnType<typeof vi.fn>; restore: () => void } {
  const g = globalThis as any;
  const originalCtor = g.CSSStyleSheet;
  const hadOwnCtor = Object.prototype.hasOwnProperty.call(g, 'CSSStyleSheet');
  const adoptedDescriptor = Object.getOwnPropertyDescriptor(document, 'adoptedStyleSheets');

  // jsdom 의 CSSStyleSheet 는 `new` 로 만들 수 없을 수 있다 — 만들 수 있는 대역으로 바꾼다
  class FakeSheet {
    public text = '';
  }
  g.CSSStyleSheet = FakeSheet;
  const replaceSync = vi.fn(function (this: FakeSheet, css: string) {
    this.text = css;
  });
  (FakeSheet.prototype as any).replaceSync = replaceSync;

  let adopted: unknown[] = [];
  Object.defineProperty(document, 'adoptedStyleSheets', {
    configurable: true,
    get: () => adopted,
    set: (next: unknown[]) => {
      adopted = next;
    },
  });

  return {
    replaceSync,
    restore: () => {
      if (hadOwnCtor) g.CSSStyleSheet = originalCtor;
      else delete g.CSSStyleSheet;
      if (adoptedDescriptor) Object.defineProperty(document, 'adoptedStyleSheets', adoptedDescriptor);
      else delete (document as any).adoptedStyleSheets;
    },
  };
}

/** 문서의 `<style>` 요소 수 */
const styleCount = (): number => document.querySelectorAll('style').length;

describe('편집기 동적 규칙 — 생성형 시트 환경에서 <style> 요소 0', () => {
  let stub: ReturnType<typeof installAdoptedStub>;
  let baseline = 0;
  const originalCSS = (globalThis as any).CSS;
  const originalFetch = (globalThis as any).fetch;

  beforeEach(() => {
    (window as any).__g7RuntimeStyles?.clear();
    document.head.querySelectorAll('[data-g7-runtime-style]').forEach((n) => n.remove());
    stub = installAdoptedStub();
    baseline = styleCount();
    rewriteSheetsMock.mockClear();
  });

  afterEach(() => {
    (window as any).__g7RuntimeStyles?.clear();
    document.head.querySelectorAll('[data-g7-runtime-style]').forEach((n) => n.remove());
    document.documentElement.style.removeProperty(BASE_SLOT_LABEL_VAR);
    stub.restore();
    (globalThis as any).CSS = originalCSS;
    (globalThis as any).fetch = originalFetch;
    delete (window as any).__g7lePreviewMediaIsolation;
  });

  /**
   * @scenario case=layout_editor_no_style_element
   * @effects editor_toolbar_rules_via_runtime_sheet
   */
  it('툴바 규칙: 셸을 그려도 <style> 이 늘지 않고 표식 <meta> 가 붙는다', () => {
    const engine = new TranslationEngine();
    const { unmount } = render(
      <TranslationProvider translationEngine={engine} translationContext={{ templateId: 'test', locale: 'ko' }}>
        <LayoutEditorChrome templateIdentifier="sirsoft-admin_basic" initialLocale="ko" />
      </TranslationProvider>,
    );

    expect(screen.getByTestId('g7le-toolbar').querySelector('style')).toBeNull();
    expect(styleCount()).toBe(baseline);
    // 기기 줄·기기 툴바 압착 차단 규칙(7.2.0 「두 줄로 감기」)도 런타임 시트로 붙는다
    for (const key of [TOOLBAR_STYLE_KEY, DEVICE_ROW_STYLE_KEY, DEVICE_TOOLBAR_STYLE_KEY]) {
      const marker = document.getElementById(key);
      expect(marker?.tagName).toBe('META');
      expect(marker?.getAttribute('data-g7-runtime-style')).toBe('adopted');
      expect((document as any).adoptedStyleSheets).toContain(getSheet(key));
    }

    unmount();
    for (const key of [TOOLBAR_STYLE_KEY, DEVICE_ROW_STYLE_KEY, DEVICE_TOOLBAR_STYLE_KEY]) {
      expect(has(key)).toBe(false);
      expect(document.getElementById(key)).toBeNull();
    }
    expect(styleCount()).toBe(baseline);
  });

  /**
   * @scenario case=layout_editor_no_style_element
   * @effects editor_slot_label_via_css_variable
   */
  it('슬롯 라벨: 문구는 규칙 본문이 아니라 CSS 변수로 들어간다', () => {
    applyBaseSlotStyle('자식 "콘텐츠" 영역');

    expect(styleCount()).toBe(baseline);
    expect(document.getElementById(BASE_SLOT_STYLE_KEY)?.tagName).toBe('META');
    // 규칙은 정적 상수 — 문구가 섞이지 않는다
    expect(getCssText(BASE_SLOT_STYLE_KEY)).toBe(BASE_SLOT_STYLE_CSS);
    expect(BASE_SLOT_STYLE_CSS).toContain(`content:var(${BASE_SLOT_LABEL_VAR}, "") " · " attr(data-editor-slot)`);
    expect(getCssText(BASE_SLOT_STYLE_KEY)).not.toContain('콘텐츠');
    expect(document.documentElement.style.getPropertyValue(BASE_SLOT_LABEL_VAR)).toBe('"자식 \\"콘텐츠\\" 영역"');
    // style 속성 문자열 대입(setAttribute)이 아니라 CSSOM 대입 경로다
    expect(toCssString("it's")).toBe('"it\'s"');
    expect(toCssString('a\nb')).toBe('"a\\a b"');

    // 문구만 바뀌면 시트를 다시 만들지 않는다
    applyBaseSlotStyle('Slot area');
    expect(stub.replaceSync).toHaveBeenCalledTimes(1);
    expect(document.documentElement.style.getPropertyValue(BASE_SLOT_LABEL_VAR)).toBe('"Slot area"');

    removeBaseSlotStyle();
    expect(has(BASE_SLOT_STYLE_KEY)).toBe(false);
    expect(document.documentElement.style.getPropertyValue(BASE_SLOT_LABEL_VAR)).toBe('');
  });

  /**
   * @scenario case=layout_editor_no_style_element
   * @effects editor_empty_cell_rules_attached_once
   */
  it('빈 표 셀 규칙: 여러 번 불러도 한 번만 붙는다', () => {
    ensureEmptyCellStyle();
    ensureEmptyCellStyle();
    ensureEmptyCellStyle();

    expect(stub.replaceSync).toHaveBeenCalledTimes(1);
    expect(styleCount()).toBe(baseline);
    expect(document.querySelectorAll(`[id="${EMPTY_CELL_STYLE_KEY}"]`).length).toBe(1);
    expect((document as any).adoptedStyleSheets.length).toBe(1);
  });

  /**
   * @scenario case=layout_editor_no_style_element
   * @effects editor_entry_fab_rules_via_runtime_sheet
   */
  it('편집 진입 단추(운영 화면): 보이는 동안만 런타임 시트로 규칙을 붙이고 <style> 을 만들지 않는다, 숨으면 뗀다', () => {
    const engine = new TranslationEngine();
    const fab = (canEdit: boolean) => (
      <TranslationProvider translationEngine={engine} translationContext={{ templateId: 'test', locale: 'ko' }}>
        <EditEntryFab
          templateIdentifier="sirsoft-basic"
          pathname="/"
          isAuthenticated
          hasLayoutEditPermission={canEdit}
          currentRoutePath="/"
          openWindow={() => {}}
        />
      </TranslationProvider>
    );
    const { rerender, unmount } = render(fab(true));

    expect(screen.getByTestId('g7le-edit-entry-fab').className).toBe('g7le-edit-entry-fab');
    expect(styleCount()).toBe(baseline);
    const marker = document.getElementById(EDIT_ENTRY_FAB_STYLE_KEY);
    expect(marker?.tagName).toBe('META');
    expect((document as any).adoptedStyleSheets).toContain(getSheet(EDIT_ENTRY_FAB_STYLE_KEY));
    expect(getCssText(EDIT_ENTRY_FAB_STYLE_KEY)).toBe(EDIT_ENTRY_FAB_STYLE_CSS);
    // 고정 위치·층·인쇄 숨김·다크 규칙이 시트에 있다(종전 <style> 은 엄격한 정책에서 막혀 단추가 문서 흐름에 그려졌다)
    expect(EDIT_ENTRY_FAB_STYLE_CSS).toContain('.g7le-edit-entry-fab{position:fixed;z-index:39;');
    expect(EDIT_ENTRY_FAB_STYLE_CSS).toContain('@media print{.g7le-edit-entry-fab{display:none !important}}');
    expect(EDIT_ENTRY_FAB_STYLE_CSS).toContain('[data-g7-color-scheme="dark"]');

    // 권한이 사라져 단추가 숨으면 규칙도 뗀다
    rerender(fab(false));
    expect(screen.queryByTestId('g7le-edit-entry-fab')).toBeNull();
    expect(has(EDIT_ENTRY_FAB_STYLE_KEY)).toBe(false);
    expect(document.getElementById(EDIT_ENTRY_FAB_STYLE_KEY)).toBeNull();
    unmount();
    expect(styleCount()).toBe(baseline);
  });

  /**
   * @scenario case=layout_editor_no_style_element
   * @effects editor_media_isolation_excludes_own_sheet
   */
  it('미리보기 컨테이너 지정: 자기 시트는 격리 대상에서 빠지고 남의 생성형 시트는 남는다', () => {
    (globalThis as any).CSS = { supports: () => true };
    const foreign = { foreign: true };
    (document as any).adoptedStyleSheets = [foreign];

    const { unmount } = renderHook(() => usePreviewMediaIsolation());

    expect(styleCount()).toBe(baseline);
    const marker = document.getElementById(MEDIA_ISOLATION_STYLE_KEY);
    expect(marker?.tagName).toBe('META');
    expect(marker?.hasAttribute('data-g7le-media-isolation')).toBe(true);

    const own = getSheet(MEDIA_ISOLATION_STYLE_KEY);
    expect(own).not.toBeNull();
    expect((document as any).adoptedStyleSheets).toContain(own);
    expect(rewriteSheetsMock).toHaveBeenCalled();
    for (const call of rewriteSheetsMock.mock.calls) {
      const sheets = call[0] as unknown[];
      expect(sheets).not.toContain(own);
      expect(sheets).toContain(foreign);
    }

    unmount();
    // 남의 시트는 보존하고 자기 시트만 뗀다
    expect((document as any).adoptedStyleSheets).toEqual([foreign]);
    expect(document.getElementById(MEDIA_ISOLATION_STYLE_KEY)).toBeNull();
  });

  /**
   * @scenario case=layout_editor_no_style_element
   * @effects editor_guarded_css_via_runtime_sheet
   */
  it('권한 가드 CSS: 본문을 생성형 시트로 붙이고 <style> 을 만들지 않는다', async () => {
    ComponentRegistry.resetInstance();
    const adminCssUrl = '/api/admin/templates/sirsoft-basic/editor/component-styles.css?v=7';
    const fetchSpy = vi.fn(async (url: string) => {
      if (url.includes('/editor-assets')) {
        return {
          ok: true,
          json: async () => ({ data: { identifier: 'sirsoft-basic', js: [], css: [adminCssUrl, adminCssUrl], manifest_present: true } }),
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
    (globalThis as any).fetch = fetchSpy;
    (window as any).SirsoftBasic = {};

    const { result } = renderHook(() => useEditorTemplateAssets('sirsoft-basic', 'ko'));
    await waitFor(() => expect(result.current.isReady).toBe(true), { timeout: 3000 });

    const key = editorAssetStyleKey(adminCssUrl);
    expect(styleCount()).toBe(baseline);
    expect(document.querySelector(`link[data-g7le-asset="${adminCssUrl}"]`)).toBeNull();
    expect(document.getElementById(key)?.tagName).toBe('META');
    expect(getCssText(key)).toContain('.g7le-preview-dark');
    // 같은 주소가 두 번 와도 한 번만 받는다
    expect(fetchSpy.mock.calls.filter((c) => String(c[0]).includes('/editor/component-styles.css')).length).toBe(1);

    delete (window as any).SirsoftBasic;
    ComponentRegistry.resetInstance();
  });
});
