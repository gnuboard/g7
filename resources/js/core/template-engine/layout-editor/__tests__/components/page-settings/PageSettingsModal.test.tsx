// e2e:allow 페이지 설정 모달 셸 RTL — 8탭 라우팅/배지/패널 전환 단위. 실데이터 결선은 각 폼 독립 RTL + Chrome MCP 매트릭스(세션 D 검증)가 SSoT.
/**
 * PageSettingsModal.test.tsx — 페이지 설정 모달 셸 RTL
 *
 * 셸의 책임만 검증한다(각 폼 본체는 폼별 독립 RTL 이 SSoT):
 *  - 8탭 헤더 렌더 + 탭 클릭 시 패널 전환
 *  - 탭별 고급 개수 배지(미환원 init/computed 항목 수) + 전체 고급 요약 줄
 *  - 모달 제목(meta.title 해석 ?? editor_label ?? path) + 닫기(✕/하단) → onClose
 *
 * Provider hook(useLayoutEditor/usePageSettings/binding)은 모킹 — 셸 라우팅 로직 격리.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';

// 편집기 상태 — selectedRoute.path + routeTree. editMode 는 [스크롤] 탭의 베이스 레이아웃 판정용.
const mockState: { selectedRoute: { path: string; layoutName: string }; routeTree: never[]; templateIdentifier: string; editMode: string } = {
  selectedRoute: { path: '/', layoutName: 'home' },
  routeTree: [],
  templateIdentifier: 'sirsoft-basic',
  editMode: 'route',
};
vi.mock('../../../LayoutEditorContext', () => ({
  useLayoutEditor: () => ({ state: mockState, dispatch: vi.fn() }),
}));

// usePageSettings — raw/getValue/patch.
let mockRaw: Record<string, unknown> = {};
const patchSpy = vi.fn();
vi.mock('../../../hooks/usePageSettings', () => ({
  usePageSettings: () => ({
    raw: mockRaw,
    getValue: <T,>(key: string, fallback?: T): T => (mockRaw[key] === undefined ? (fallback as T) : (mockRaw[key] as T)),
    patch: patchSpy,
    createI18nKey: vi.fn(),
    updateI18nKeyValue: vi.fn(),
  }),
}));

// binding hooks — 빈 후보 풀(셸 라우팅과 무관).
// buildPageSampleContext 는 data_source 샘플 컨텍스트(예: product.data)를 만든다 —
// 셸이 이걸 SeoForm.seedContext 로 흘려야 SEO 미리보기가 빈 컨텍스트로 호출되지 않는다.
const MOCK_SAMPLE_CONTEXT = { product: { data: { id: 101, name: '샘플 상품' } } };
vi.mock('../../../hooks/useBindingCandidates', () => ({
  useBindingCandidates: () => [],
  buildPageSampleContext: () => MOCK_SAMPLE_CONTEXT,
}));
vi.mock('../../../hooks/useSeoBindingCandidates', () => ({
  useSeoBindingCandidates: () => [],
}));

// 각 폼은 testid 마커만 — 셸이 올바른 폼을 마운트하는지 확인.
// MetaForm 은 patch 캡처 — 셸이 meta 패치 때 이 화면이 직접 적은 scroll_restoration 을 지키는지 확인.
const metaFormProps: { patch?: (key: string, value: unknown, ...rest: unknown[]) => void } = {};
vi.mock('../../../components/page-settings/MetaForm', () => ({
  MetaForm: (props: { patch?: (key: string, value: unknown, ...rest: unknown[]) => void }) => {
    metaFormProps.patch = props.patch;
    return <div data-testid="mock-meta-form" />;
  },
}));
// ScrollRestorationForm 은 props 캡처 — 셸이 own/baseValue/templateValue/isBaseLayout 을 도출해 흘리는지 확인.
const scrollFormProps: {
  own?: unknown;
  baseValue?: unknown;
  templateValue?: unknown;
  isBaseLayout?: unknown;
  onChange?: (next: unknown) => void;
} = {};
vi.mock('../../../components/page-settings/ScrollRestorationForm', () => ({
  ScrollRestorationForm: (props: typeof scrollFormProps) => {
    Object.assign(scrollFormProps, props);
    return <div data-testid="mock-scroll-form" />;
  },
}));
// SeoForm 은 props 캡처 — 셸이 fetch 한 extensions(D-4b) + 샘플 컨텍스트(seedContext)를 흘리는지 확인.
const seoFormProps: {
  availableExtensions?: unknown;
  seedContext?: unknown;
  patch?: (key: string, value: unknown, ...rest: unknown[]) => void;
} = {};
vi.mock('../../../components/page-settings/SeoForm', () => ({
  SeoForm: (props: { availableExtensions?: unknown; seedContext?: unknown; patch?: (key: string, value: unknown, ...rest: unknown[]) => void }) => {
    seoFormProps.availableExtensions = props.availableExtensions;
    seoFormProps.seedContext = props.seedContext;
    seoFormProps.patch = props.patch;
    return <div data-testid="mock-seo-form" />;
  },
}));
// 레시피 라벨 해석 t 캡처 — 레시피 라벨(`$t:editor.*`)은 편집 대상 사전 키라 셸이
// resolveLabel(editorAwareT)을 t 로 넘겨야 한다.
const recipeFormT: { init?: unknown; overlay?: unknown; computed?: unknown; error?: unknown } = {};
// init_actions 키 표기(camel/snake) 흡수 검증 — 셸이 InitActionsForm 에 흘리는 actions 캡처.
const initFormProps: { actions?: unknown } = {};
vi.mock('../../../components/page-settings/InitActionsForm', () => ({
  InitActionsForm: (props: { t?: unknown; actions?: unknown }) => {
    recipeFormT.init = props.t;
    initFormProps.actions = props.actions;
    return <div data-testid="mock-init-form" />;
  },
}));
// TransitionOverlayForm 은 props 캡처 — 셸이 baseValue/ownValue(병합본−own 도출)를 흘리는지 확인(로딩화면 상속).
const overlayFormProps: { value?: unknown; baseValue?: unknown; ownValue?: unknown } = {};
vi.mock('../../../components/page-settings/TransitionOverlayForm', () => ({
  TransitionOverlayForm: (props: { value?: unknown; baseValue?: unknown; ownValue?: unknown; t?: unknown }) => {
    overlayFormProps.value = props.value;
    overlayFormProps.baseValue = props.baseValue;
    overlayFormProps.ownValue = props.ownValue;
    recipeFormT.overlay = props.t;
    return <div data-testid="mock-overlay-form" />;
  },
}));
vi.mock('../../../components/page-settings/ComputedForm', () => ({
  ComputedForm: (props: { t?: unknown }) => {
    recipeFormT.computed = props.t;
    return <div data-testid="mock-computed-form" />;
  },
}));
vi.mock('../../../components/page-settings/InitialStateForm', () => ({
  InitialStateForm: () => <div data-testid="mock-state-form" />,
}));
// ErrorHandlingForm 은 props 캡처 — 셸이 표준 HTTP 코드를 errorConfigCodes 로 흘리는지 확인(D-N).
const errorFormProps: { errorConfigCodes?: unknown; templateCodes?: unknown } = {};
vi.mock('../../../components/page-settings/ErrorHandlingForm', () => ({
  ErrorHandlingForm: (props: { errorConfigCodes?: unknown; templateCodes?: unknown; t?: unknown }) => {
    errorFormProps.errorConfigCodes = props.errorConfigCodes;
    errorFormProps.templateCodes = props.templateCodes;
    recipeFormT.error = props.t;
    return <div data-testid="mock-error-form" />;
  },
}));
vi.mock('../../../components/page-settings/DataSourceTab', () => ({
  DataSourceTab: () => <div data-testid="mock-data-form" />,
}));

// 고급 카운트 — init/computed 미환원 판정을 'advanced' 로 강제(셸 배지 검증).
vi.mock('../../../spec/actionRecipeEngine', () => ({
  normalizeActionRecipes: () => [],
  resolveActionCard: () => ({ kind: 'advanced' }),
}));
vi.mock('../../../spec/computedRecipeEngine', () => ({
  normalizeComputedRecipes: () => [],
  resolveComputedCard: () => ({ kind: 'advanced' }),
}));

import { PageSettingsModal } from '../../../components/page-settings/PageSettingsModal';

const t = (k: string, p?: Record<string, string | number>) =>
  p ? `${k}:${JSON.stringify(p)}` : k;
const resolveLabel = (k: string) => k;

function renderShell(overrides: Partial<React.ComponentProps<typeof PageSettingsModal>> = {}) {
  const onClose = vi.fn();
  const utils = render(
    <PageSettingsModal
      templateIdentifier="sirsoft-basic"
      spec={null}
      t={t}
      resolveLabel={resolveLabel}
      onClose={onClose}
      extensionsFetcher={async () => []}
      {...overrides}
    />,
  );
  return { onClose, ...utils };
}

beforeEach(() => {
  cleanup();
  mockRaw = {};
  patchSpy.mockClear();
  recipeFormT.init = undefined;
  recipeFormT.overlay = undefined;
  recipeFormT.computed = undefined;
  recipeFormT.error = undefined;
  initFormProps.actions = undefined;
  mockState.editMode = 'route';
  metaFormProps.patch = undefined;
  for (const k of Object.keys(scrollFormProps)) delete (scrollFormProps as Record<string, unknown>)[k];
});

describe('PageSettingsModal 셸 — 9탭 라우팅/배지/패널', () => {
  /**
   * @effects shell_routes_nine_tabs_meta_seo_init_overlay_scroll_computed_state_error_data
   */
  it('9탭 헤더 전부 렌더(로딩 화면 다음 스크롤) + 기본 탭(meta) 패널 = MetaForm', () => {
    renderShell();
    const keys = ['meta', 'seo', 'init', 'overlay', 'scroll', 'computed', 'state', 'error', 'data'];
    for (const key of keys) {
      expect(screen.getByTestId(`g7le-page-settings-tab-${key}`)).toBeInTheDocument();
    }
    // 탭 순서·개수 고정(임의 추가/누락 차단).
    const order = screen.getAllByRole('tab').map((el) => el.getAttribute('data-testid')?.replace('g7le-page-settings-tab-', ''));
    expect(order).toEqual(keys);
    expect(screen.getByTestId('mock-meta-form')).toBeInTheDocument();
  });

  describe('[스크롤] 탭 — meta.scroll_restoration 상속 도출·패치 (engine-v1.85.0)', () => {
    /**
     * @effects shell_scroll_tab_derives_base_as_merged_minus_own_per_snapshot_key
     */
    it('병합본 − own 으로 baseValue(snapshot 하위 키 단위)를 도출하고 own 은 __editor.original.meta 에서 읽는다', () => {
      mockRaw = {
        meta: {
          title: 'x',
          scroll_restoration: { mode: 'top', wait: 'data', snapshot: { enabled: false, ttl_seconds: 60 } },
        },
        __editor: { original: { meta: { title: 'x', scroll_restoration: { wait: 'data', snapshot: { ttl_seconds: 60 } } } } },
      };
      renderShell({ initialTab: 'scroll', templateConfigFetcher: async () => undefined });
      expect(screen.getByTestId('mock-scroll-form')).toBeInTheDocument();
      expect(scrollFormProps.own).toEqual({ wait: 'data', snapshot: { ttl_seconds: 60 } });
      expect(scrollFormProps.baseValue).toEqual({ mode: 'top', snapshot: { enabled: false } });
      expect(scrollFormProps.isBaseLayout).toBe(false);
    });

    /**
     * @effects shell_scroll_patch_writes_merged_meta_and_own_only_original_meta
     */
    it('own 을 바꾸면 patch(meta, 베이스+own 병합본, own 만 담은 original meta)', () => {
      mockRaw = {
        meta: { title: 'x', scroll_restoration: { mode: 'top', snapshot: { enabled: false } } },
        __editor: { original: { meta: { title: 'x' } } },
      };
      renderShell({ initialTab: 'scroll', templateConfigFetcher: async () => undefined });
      scrollFormProps.onChange?.({ timeout_ms: 8000, snapshot: { ttl_seconds: 30 } });
      expect(patchSpy).toHaveBeenLastCalledWith(
        'meta',
        { title: 'x', scroll_restoration: { mode: 'top', timeout_ms: 8000, snapshot: { enabled: false, ttl_seconds: 30 } } },
        { title: 'x', scroll_restoration: { timeout_ms: 8000, snapshot: { ttl_seconds: 30 } } },
      );
    });

    /**
     * @effects shell_scroll_patch_writes_merged_meta_and_own_only_original_meta
     */
    it('own 을 비우면(undefined) original meta 에서 키를 지우고 병합본은 베이스 값만 남는다', () => {
      mockRaw = {
        meta: { title: 'x', scroll_restoration: { mode: 'none', on_reload: false } },
        __editor: { original: { meta: { title: 'x', scroll_restoration: { mode: 'none' } } } },
      };
      renderShell({ initialTab: 'scroll', templateConfigFetcher: async () => undefined });
      scrollFormProps.onChange?.(undefined);
      expect(patchSpy).toHaveBeenLastCalledWith(
        'meta',
        { title: 'x', scroll_restoration: { on_reload: false } },
        { title: 'x' },
      );
    });

    /**
     * @effects shell_scroll_tab_derives_base_as_merged_minus_own_per_snapshot_key, shell_scroll_patch_writes_merged_meta_and_own_only_original_meta
     */
    it('__editor.original 이 없으면(독립 문서) 병합본을 own 으로 보고 한 값으로 패치한다', () => {
      mockRaw = { meta: { scroll_restoration: { mode: 'top' } } };
      renderShell({ initialTab: 'scroll', templateConfigFetcher: async () => undefined });
      expect(scrollFormProps.own).toEqual({ mode: 'top' });
      expect(scrollFormProps.baseValue).toBeUndefined();
      scrollFormProps.onChange?.({ mode: 'restore' });
      expect(patchSpy).toHaveBeenLastCalledWith('meta', { scroll_restoration: { mode: 'restore' } });
    });

    /**
     * @effects shell_scroll_tab_marks_base_layout_edit_as_template_default
     */
    it('베이스 레이아웃 편집(editMode=base)이면 isBaseLayout=true — 이 값이 템플릿 기본값', () => {
      mockState.editMode = 'base';
      mockRaw = { meta: { is_base: true }, __editor: { original: { meta: { is_base: true } } } };
      renderShell({ initialTab: 'scroll', templateConfigFetcher: async () => undefined });
      expect(scrollFormProps.isBaseLayout).toBe(true);
    });

    /**
     * @effects shell_scroll_tab_reads_template_json_scroll_restoration_lazily
     */
    it('template.json scroll_restoration 을 읽어 templateValue 로 흘린다', async () => {
      const fetcher = vi.fn(async () => ({ timeout_ms: 7000 }));
      renderShell({ initialTab: 'scroll', templateConfigFetcher: fetcher });
      await new Promise((r) => setTimeout(r, 0));
      expect(fetcher).toHaveBeenCalledWith('sirsoft-basic');
      expect(scrollFormProps.templateValue).toEqual({ timeout_ms: 7000 });
    });

    /**
     * @effects shell_scroll_tab_reads_template_json_scroll_restoration_lazily
     */
    it('[스크롤] 탭을 열지 않으면 template.json 을 읽지 않는다', async () => {
      const fetcher = vi.fn(async () => ({}));
      renderShell({ templateConfigFetcher: fetcher });
      await new Promise((r) => setTimeout(r, 0));
      expect(fetcher).not.toHaveBeenCalled();
    });

    /**
     * @effects shell_meta_and_seo_tab_patch_keeps_only_own_scroll_restoration_in_original
     */
    it('[기본 정보] 탭의 meta 패치는 이 화면이 직접 적은 scroll_restoration 만 original 에 남긴다(상속분을 굳히지 않음)', () => {
      mockRaw = {
        meta: { title: 'x', scroll_restoration: { mode: 'top', timeout_ms: 8000 } },
        __editor: { original: { meta: { title: 'x', scroll_restoration: { timeout_ms: 8000 } } } },
      };
      renderShell();
      const merged = { title: 'y', scroll_restoration: { mode: 'top', timeout_ms: 8000 } };
      metaFormProps.patch?.('meta', merged);
      expect(patchSpy).toHaveBeenLastCalledWith('meta', merged, { title: 'y', scroll_restoration: { timeout_ms: 8000 } });
    });

    /**
     * @effects shell_meta_and_seo_tab_patch_keeps_only_own_scroll_restoration_in_original
     */
    it('[기본 정보] 탭 meta 패치 — 이 화면이 scroll_restoration 을 안 적었으면 original 에도 없다', () => {
      mockRaw = {
        meta: { title: 'x', scroll_restoration: { mode: 'top' } },
        __editor: { original: { meta: { title: 'x' } } },
      };
      renderShell();
      metaFormProps.patch?.('meta', { title: 'y', scroll_restoration: { mode: 'top' } });
      expect(patchSpy).toHaveBeenLastCalledWith('meta', { title: 'y', scroll_restoration: { mode: 'top' } }, { title: 'y' });
    });
  });

  describe('meta.seo 상속 — 베이스에서 물려받은 SEO 를 이 화면 값으로 굳히지 않는다', () => {
    /**
     * @effects shell_meta_patch_keeps_only_own_or_changed_seo_in_original
     */
    it('[기본 정보] 탭에서 제목만 바꿔 저장해도, 이 화면이 적지 않은 베이스 SEO 는 original 에 들어가지 않는다', () => {
      mockRaw = {
        meta: { title: 'x', seo: { title: '사이트 기본 제목', og: { type: 'website', site_name: '가게' } } },
        __editor: { original: { meta: { title: 'x' } } },
      };
      renderShell();
      const merged = { title: 'y', seo: { title: '사이트 기본 제목', og: { type: 'website', site_name: '가게' } } };
      metaFormProps.patch?.('meta', merged);
      expect(patchSpy).toHaveBeenLastCalledWith('meta', merged, { title: 'y' });
    });

    /**
     * @effects shell_meta_patch_keeps_only_own_or_changed_seo_in_original
     */
    it('[검색엔진] 탭에서 바꾼 SEO 는 이 화면이 적은 키·베이스와 달라진 키만 original 에 남는다(하위 키·data_sources 단위)', () => {
      mockRaw = {
        meta: {
          title: 'x',
          seo: { page_type: 'product', og: { type: 'website', site_name: '가게' }, data_sources: ['site', 'product'] },
        },
        __editor: { original: { meta: { title: 'x', seo: { page_type: 'product', data_sources: ['product'] } } } },
      };
      renderShell();
      fireEvent.click(screen.getByTestId('g7le-page-settings-tab-seo'));
      const merged = {
        title: 'x',
        seo: { page_type: 'product', og: { type: 'product', site_name: '가게' }, data_sources: ['site', 'product', 'reviews'] },
      };
      seoFormProps.patch?.('meta', merged);
      expect(patchSpy).toHaveBeenLastCalledWith('meta', merged, {
        title: 'x',
        seo: { page_type: 'product', og: { type: 'product' }, data_sources: ['product', 'reviews'] },
      });
    });

    /**
     * @effects shell_meta_patch_keeps_only_own_or_changed_seo_in_original
     */
    it('이 화면이 직접 적은 SEO 키는 베이스와 값이 같아도 남긴다(작성자가 명시한 값)', () => {
      mockRaw = {
        meta: { title: 'x', seo: { title: '같은 값', description: '베이스 설명' } },
        __editor: { original: { meta: { title: 'x', seo: { title: '같은 값' } } } },
      };
      renderShell();
      const merged = { title: 'x', seo: { title: '같은 값', description: '베이스 설명' } };
      metaFormProps.patch?.('meta', merged);
      expect(patchSpy).toHaveBeenLastCalledWith('meta', merged, { title: 'x', seo: { title: '같은 값' } });
    });
  });

  /**
   * @effects shell_tab_click_switches_active_panel
   */
  it('탭 클릭 → 해당 폼으로 패널 전환', () => {
    renderShell();
    fireEvent.click(screen.getByTestId('g7le-page-settings-tab-seo'));
    expect(screen.getByTestId('mock-seo-form')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-page-settings-tab-data'));
    expect(screen.getByTestId('mock-data-form')).toBeInTheDocument();
  });

  /**
   * @effects shell_initial_tab_prop_opens_that_tab
   */
  it('initialTab prop → 그 탭으로 직접 진입', () => {
    renderShell({ initialTab: 'error' });
    expect(screen.getByTestId('mock-error-form')).toBeInTheDocument();
  });

  /**
   * @effects shell_tab_underline_uses_longhand_only_no_stale_color
   */
  it('D-H: 탭 밑줄은 longhand 만 사용(shorthand borderBottom 미사용) — 비활성 탭 색 잔존 방지', () => {
    renderShell();
    // 활성→비활성 전환을 일으켜 본다(meta→seo→meta).
    fireEvent.click(screen.getByTestId('g7le-page-settings-tab-seo'));
    fireEvent.click(screen.getByTestId('g7le-page-settings-tab-meta'));
    const seoTab = screen.getByTestId('g7le-page-settings-tab-seo') as HTMLElement;
    const metaTab = screen.getByTestId('g7le-page-settings-tab-meta') as HTMLElement;
    // shorthand borderBottom 을 인라인으로 쓰면 React 가 직전 longhand borderBottomColor 를
    // 못 지워 비활성 탭에 색이 남는다(검은 줄). 두 탭 모두 shorthand 인라인 미사용을 단언.
    expect(seoTab.style.borderBottom).toBe('');
    expect(metaTab.style.borderBottom).toBe('');
    // longhand 로 칠해진다 — 비활성(seo)=transparent, 활성(meta)=파란색.
    expect(seoTab.style.borderBottomColor).toBe('transparent');
    expect(metaTab.style.borderBottomColor).not.toBe('transparent');
  });

  /**
   * @effects shell_tab_row_overflow_y_hidden_no_vertical_scrollbar
   */
  it('탭바 세로 overflow=hidden — ▲▼ 세로 스크롤바/탭 사라짐 방지', () => {
    renderShell();
    const tabRow = screen.getByTestId('g7le-page-settings-tab-meta').parentElement as HTMLElement;
    // overflowX:auto 단독이면 overflowY 가 auto 로 컴퓨트돼 탭이 1~2px 넘칠 때 세로 스크롤바가
    // 생긴다 → overflowY:hidden 명시(인라인 style 단언).
    expect(tabRow.style.overflowY).toBe('hidden');
    expect(tabRow.getAttribute('role')).toBe('tablist');
  });

  /**
   * @effects shell_error_tab_receives_standard_http_codes_and_declared_codes
   */
  it('D-N: [에러 처리] 탭 → 셸이 표준 HTTP 코드 + 병합본 키를 ErrorHandlingForm 에 주입', () => {
    mockRaw = { errorHandling: { '418': { handler: 'toast' } } };
    renderShell({ initialTab: 'error' });
    // 표준 코드(401/403/404/500/503) 항상 주입 → buildRowCodes 가 default 행을 덧붙인다.
    expect(errorFormProps.errorConfigCodes).toEqual(['401', '403', '404', '500', '503']);
    // 레이아웃이 추가 선언한 코드(418)는 templateCodes 로 합쳐져 행으로 노출.
    expect(errorFormProps.templateCodes).toContain('418');
  });

  /**
   * @effects shell_overlay_tab_derives_base_and_own_from_merged_minus_own
   */
  it(' 로딩화면: [로딩 화면] 탭 → 셸이 병합본−own 으로 baseValue/ownValue 도출해 주입(상속 표기 결함 수정)', () => {
    // 병합본 = base(enabled/style/target) + 자식(wait_for). own = 자식 직접 선언(wait_for 만).
    mockRaw = {
      transition_overlay: { enabled: true, style: 'spinner', target: 'main', wait_for: ['products'] },
      __editor: { original: { transition_overlay: { wait_for: ['products'] } } },
    };
    renderShell({ initialTab: 'overlay' });
    // 화면 표시는 병합본(현재 effective) 그대로.
    expect(overlayFormProps.value).toEqual({ enabled: true, style: 'spinner', target: 'main', wait_for: ['products'] });
    // own = 자식 직접 선언분(wait_for 만) — 재정의 판정 기준.
    expect(overlayFormProps.ownValue).toEqual({ wait_for: ['products'] });
    // baseValue = 병합본 − own = base 상속 키(enabled/style/target). 종전엔 미주입(undefined)이라
    // 〔상속됨〕·[이 화면만 바꾸기] 가 화면에 안 떴다.
    expect(overlayFormProps.baseValue).toEqual({ enabled: true, style: 'spinner', target: 'main' });
  });

  /**
   * @effects shell_overlay_tab_derives_base_and_own_from_merged_minus_own
   */
  it(' 로딩화면: own 이 모든 키를 덮으면 baseValue 미도출(상속 없음 → undefined)', () => {
    mockRaw = {
      transition_overlay: { enabled: true, wait_for: ['x'] },
      __editor: { original: { transition_overlay: { enabled: true, wait_for: ['x'] } } },
    };
    renderShell({ initialTab: 'overlay' });
    expect(overlayFormProps.baseValue).toBeUndefined();
  });

  /**
   * @effects shell_overlay_tab_derives_base_and_own_from_merged_minus_own
   */
  it(' 로딩화면: own 에 transition_overlay 가 아예 없으면(전부 상속) ownValue={} + baseValue=병합본 전체', () => {
    // 실데이터(shop/show) — 자식은 로딩 화면을 직접 선언 안 함, 전부 base(_user_base) 상속.
    // 종전 버그: 셸이 own 부재 시 undefined 를 흘려 폼이 병합본을 own 으로 폴백 → 상속 키가 〔재정의〕로 오판.
    mockRaw = {
      transition_overlay: { enabled: true, style: 'spinner', target: 'main' },
      __editor: { original: { meta: { title: 'x' } } }, // editorOriginal 존재하나 transition_overlay 부재.
    };
    renderShell({ initialTab: 'overlay' });
    // own 은 빈 객체(undefined 아님) — 폼이 병합본으로 폴백하지 않도록.
    expect(overlayFormProps.ownValue).toEqual({});
    // baseValue = 병합본 전체(전부 상속).
    expect(overlayFormProps.baseValue).toEqual({ enabled: true, style: 'spinner', target: 'main' });
  });

  /**
   * @effects shell_advanced_badge_counts_unreduced_init_and_computed_items, shell_advanced_summary_sums_total_when_positive
   */
  it('init_actions/computed 미환원 항목 → 탭 배지(N) + 전체 고급 요약', () => {
    mockRaw = {
      init_actions: [{ handler: 'a' }, { handler: 'b' }],
      computed: { x: '{{ 1 }}' },
    };
    renderShell();
    // init 2개 미환원 → (2), computed 1개 → (1).
    expect(screen.getByTestId('g7le-page-settings-tab-badge-init')).toHaveTextContent('(2)');
    expect(screen.getByTestId('g7le-page-settings-tab-badge-computed')).toHaveTextContent('(1)');
    // 고급 없는 탭은 배지 부재.
    expect(screen.queryByTestId('g7le-page-settings-tab-badge-meta')).not.toBeInTheDocument();
    // 전체 합계 요약(2+1=3).
    expect(screen.getByTestId('g7le-page-settings-advanced-summary')).toBeInTheDocument();
  });

  /**
   * @effects shell_advanced_summary_sums_total_when_positive
   */
  it('고급 0 → 요약 줄 미노출', () => {
    mockRaw = {};
    renderShell();
    expect(screen.queryByTestId('g7le-page-settings-advanced-summary')).not.toBeInTheDocument();
  });

  /**
   * @effects shell_title_resolves_metaTitle_else_editorLabel_else_path
   */
  it('모달 제목 = meta.title 해석값(평문)', () => {
    mockRaw = { meta: { title: '우리 사이트 홈' } };
    renderShell();
    expect(screen.getByTestId('g7le-page-settings-title')).toHaveTextContent('우리 사이트 홈');
  });

  /**
   * @effects shell_title_resolves_metaTitle_else_editorLabel_else_path
   */
  it('meta.title 없으면 editor_label 폴백', () => {
    mockRaw = { meta: { editor_label: '홈 화면' } };
    renderShell();
    expect(screen.getByTestId('g7le-page-settings-title')).toHaveTextContent('홈 화면');
  });

  /**
   * @effects shell_title_resolves_metaTitle_else_editorLabel_else_path
   */
  it('meta 전체 없으면 path 폴백', () => {
    mockRaw = {};
    renderShell();
    // t('...title', {name:'/'}) → name 에 path.
    expect(screen.getByTestId('g7le-page-settings-title')).toHaveTextContent('/');
  });

  /**
   * @effects seo_extensions_fetched_and_passed_to_availableExtensions
   */
  it('D-4b: seo-candidates extensions fetch → SeoForm availableExtensions 결선', async () => {
    const exts = [{ type: 'module', id: 'sirsoft-ecommerce', name: '쇼핑몰' }];
    renderShell({ initialTab: 'seo', extensionsFetcher: async () => exts });
    // useEffect fetch 반영 대기.
    await screen.findByTestId('mock-seo-form');
    await new Promise((r) => setTimeout(r, 0));
    expect(seoFormProps.availableExtensions).toEqual(exts);
  });

  /**
   * @effects shell_passes_page_sample_context_to_seo_form_seed_context
   */
  it(' SEO 미리보기: 셸이 샘플 컨텍스트를 SeoForm.seedContext 로 결선(빈 컨텍스트 호출 방지)', async () => {
    renderShell({ initialTab: 'seo' });
    await screen.findByTestId('mock-seo-form');
    // 종전엔 seedContext 미전달 → og/구조화/봇 미리보기가 seed_context:{} 로 호출돼 모듈 declaration 이
    // 샘플 상품 없이 빈 결과 반환. 이제 buildPageSampleContext 결과(product.data 등)를 흘린다.
    expect(seoFormProps.seedContext).toEqual(MOCK_SAMPLE_CONTEXT);
  });

  /**
   * @effects shell_close_x_and_footer_call_onClose
   */
  it('✕ / 하단 닫기 → onClose 호출', () => {
    const { onClose } = renderShell();
    fireEvent.click(screen.getByTestId('g7le-page-settings-close-x'));
    expect(onClose).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByTestId('g7le-page-settings-close'));
    expect(onClose).toHaveBeenCalledTimes(2);
  });

  // 레시피 라벨 네임스페이스 결함.
  // 레시피/스펙 라벨(`$t:editor.computed.*`/`editor.action.*`/`editor.error.*`/loadingComponents)은
  // 편집 대상 템플릿 사전 키라 chrome t(admin 컨텍스트)로는 호스트 사전에 없는 편집 대상 전용
  // 프리셋(first_of/group_items 등)이 raw 키로 노출됐다. 셸은 이 폼들에 resolveLabel(editorAwareT:
  // 편집 대상 우선 → chrome 폴백)을 t 로 넘겨야 한다. (MetaForm.fieldT / DataSourceTab.resolveLabel 과 동형)
  describe('레시피 라벨 폼은 resolveLabel(편집 대상 우선 t)을 받는다 — S10-1 라벨 네임스페이스 결함', () => {
    /**
     * @effects shell_recipe_forms_receive_resolve_label_as_t
     */
    it('[자동 계산] ComputedForm.t = resolveLabel (chrome t 아님)', () => {
      renderShell();
      fireEvent.click(screen.getByTestId('g7le-page-settings-tab-computed'));
      expect(recipeFormT.computed).toBe(resolveLabel);
      expect(recipeFormT.computed).not.toBe(t);
    });

    /**
     * @effects shell_recipe_forms_receive_resolve_label_as_t
     */
    it('[화면 동작] InitActionsForm.t = resolveLabel', () => {
      renderShell();
      fireEvent.click(screen.getByTestId('g7le-page-settings-tab-init'));
      expect(recipeFormT.init).toBe(resolveLabel);
      expect(recipeFormT.init).not.toBe(t);
    });

    /**
     * @effects shell_recipe_forms_receive_resolve_label_as_t
     */
    it('[에러 처리] ErrorHandlingForm.t = resolveLabel', () => {
      renderShell();
      fireEvent.click(screen.getByTestId('g7le-page-settings-tab-error'));
      expect(recipeFormT.error).toBe(resolveLabel);
      expect(recipeFormT.error).not.toBe(t);
    });

    /**
     * @effects shell_recipe_forms_receive_resolve_label_as_t
     */
    it('[로딩 화면] TransitionOverlayForm.t = resolveLabel', () => {
      renderShell();
      fireEvent.click(screen.getByTestId('g7le-page-settings-tab-overlay'));
      expect(recipeFormT.overlay).toBe(resolveLabel);
      expect(recipeFormT.overlay).not.toBe(t);
    });
  });

  // init_actions 키 표기 불일치(데이터 손실).
  // 서버 레이아웃 응답은 `initActions`(camel), 저장(patch)·서버 검증은 `init_actions`(snake).
  // 셸이 snake 우선 → camel 폴백으로 둘 다 읽어야 [화면 동작] 탭이 기존 동작을 표시한다.
  // 종전 snake 단독 조회는 새 로드 시 서버 camel 응답을 못 읽어 빈 목록 → 저장 시 기존 동작 삭제.
  describe('init_actions 키 표기(camel/snake) 흡수 — 기존 동작 보존', () => {
    /**
     * @effects shell_init_actions_reads_snake_then_camel_keys
     */
    it('서버 응답 initActions(camel)만 있어도 화면 동작에 표시된다', () => {
      mockRaw = { initActions: [{ handler: 'loadFromLocalStorage' }, { handler: 'closeModal' }] };
      renderShell();
      fireEvent.click(screen.getByTestId('g7le-page-settings-tab-init'));
      expect(initFormProps.actions).toEqual([
        { handler: 'loadFromLocalStorage' },
        { handler: 'closeModal' },
      ]);
    });

    /**
     * @effects shell_init_actions_reads_snake_then_camel_keys
     */
    it('세션 패치 init_actions(snake)가 서버 initActions(camel)보다 우선한다', () => {
      mockRaw = {
        initActions: [{ handler: 'loadFromLocalStorage' }],
        init_actions: [{ handler: 'toast' }],
      };
      renderShell();
      fireEvent.click(screen.getByTestId('g7le-page-settings-tab-init'));
      expect(initFormProps.actions).toEqual([{ handler: 'toast' }]);
    });

    /**
     * @effects shell_init_actions_reads_snake_then_camel_keys
     */
    it('둘 다 없으면 빈 배열(폴백)', () => {
      mockRaw = {};
      renderShell();
      fireEvent.click(screen.getByTestId('g7le-page-settings-tab-init'));
      expect(initFormProps.actions).toEqual([]);
    });
  });
});
