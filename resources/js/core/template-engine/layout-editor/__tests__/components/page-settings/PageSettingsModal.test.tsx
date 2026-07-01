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

// 편집기 상태 — selectedRoute.path + routeTree.
const mockState = {
  selectedRoute: { path: '/', layoutName: 'home' },
  routeTree: [],
  templateIdentifier: 'sirsoft-basic',
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
vi.mock('../../../components/page-settings/MetaForm', () => ({
  MetaForm: () => <div data-testid="mock-meta-form" />,
}));
// SeoForm 은 props 캡처 — 셸이 fetch 한 extensions(D-4b) + 샘플 컨텍스트(seedContext)를 흘리는지 확인.
const seoFormProps: { availableExtensions?: unknown; seedContext?: unknown } = {};
vi.mock('../../../components/page-settings/SeoForm', () => ({
  SeoForm: (props: { availableExtensions?: unknown; seedContext?: unknown }) => {
    seoFormProps.availableExtensions = props.availableExtensions;
    seoFormProps.seedContext = props.seedContext;
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
});

describe('PageSettingsModal 셸 — 8탭 라우팅/배지/패널', () => {
  it('8탭 헤더 전부 렌더 + 기본 탭(meta) 패널 = MetaForm', () => {
    renderShell();
    for (const key of ['meta', 'seo', 'init', 'overlay', 'computed', 'state', 'error', 'data']) {
      expect(screen.getByTestId(`g7le-page-settings-tab-${key}`)).toBeInTheDocument();
    }
    expect(screen.getByTestId('mock-meta-form')).toBeInTheDocument();
  });

  it('탭 클릭 → 해당 폼으로 패널 전환', () => {
    renderShell();
    fireEvent.click(screen.getByTestId('g7le-page-settings-tab-seo'));
    expect(screen.getByTestId('mock-seo-form')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-page-settings-tab-data'));
    expect(screen.getByTestId('mock-data-form')).toBeInTheDocument();
  });

  it('initialTab prop → 그 탭으로 직접 진입', () => {
    renderShell({ initialTab: 'error' });
    expect(screen.getByTestId('mock-error-form')).toBeInTheDocument();
  });

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

  it('탭바 세로 overflow=hidden — ▲▼ 세로 스크롤바/탭 사라짐 방지', () => {
    renderShell();
    const tabRow = screen.getByTestId('g7le-page-settings-tab-meta').parentElement as HTMLElement;
    // overflowX:auto 단독이면 overflowY 가 auto 로 컴퓨트돼 탭이 1~2px 넘칠 때 세로 스크롤바가
    // 생긴다 → overflowY:hidden 명시(인라인 style 단언).
    expect(tabRow.style.overflowY).toBe('hidden');
    expect(tabRow.getAttribute('role')).toBe('tablist');
  });

  it('D-N: [에러 처리] 탭 → 셸이 표준 HTTP 코드 + 병합본 키를 ErrorHandlingForm 에 주입', () => {
    mockRaw = { errorHandling: { '418': { handler: 'toast' } } };
    renderShell({ initialTab: 'error' });
    // 표준 코드(401/403/404/500/503) 항상 주입 → buildRowCodes 가 default 행을 덧붙인다.
    expect(errorFormProps.errorConfigCodes).toEqual(['401', '403', '404', '500', '503']);
    // 레이아웃이 추가 선언한 코드(418)는 templateCodes 로 합쳐져 행으로 노출.
    expect(errorFormProps.templateCodes).toContain('418');
  });

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

  it(' 로딩화면: own 이 모든 키를 덮으면 baseValue 미도출(상속 없음 → undefined)', () => {
    mockRaw = {
      transition_overlay: { enabled: true, wait_for: ['x'] },
      __editor: { original: { transition_overlay: { enabled: true, wait_for: ['x'] } } },
    };
    renderShell({ initialTab: 'overlay' });
    expect(overlayFormProps.baseValue).toBeUndefined();
  });

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

  it('고급 0 → 요약 줄 미노출', () => {
    mockRaw = {};
    renderShell();
    expect(screen.queryByTestId('g7le-page-settings-advanced-summary')).not.toBeInTheDocument();
  });

  it('모달 제목 = meta.title 해석값(평문)', () => {
    mockRaw = { meta: { title: '우리 사이트 홈' } };
    renderShell();
    expect(screen.getByTestId('g7le-page-settings-title')).toHaveTextContent('우리 사이트 홈');
  });

  it('meta.title 없으면 editor_label 폴백', () => {
    mockRaw = { meta: { editor_label: '홈 화면' } };
    renderShell();
    expect(screen.getByTestId('g7le-page-settings-title')).toHaveTextContent('홈 화면');
  });

  it('meta 전체 없으면 path 폴백', () => {
    mockRaw = {};
    renderShell();
    // t('...title', {name:'/'}) → name 에 path.
    expect(screen.getByTestId('g7le-page-settings-title')).toHaveTextContent('/');
  });

  it('D-4b: seo-candidates extensions fetch → SeoForm availableExtensions 결선', async () => {
    const exts = [{ type: 'module', id: 'sirsoft-ecommerce', name: '쇼핑몰' }];
    renderShell({ initialTab: 'seo', extensionsFetcher: async () => exts });
    // useEffect fetch 반영 대기.
    await screen.findByTestId('mock-seo-form');
    await new Promise((r) => setTimeout(r, 0));
    expect(seoFormProps.availableExtensions).toEqual(exts);
  });

  it(' SEO 미리보기: 셸이 샘플 컨텍스트를 SeoForm.seedContext 로 결선(빈 컨텍스트 호출 방지)', async () => {
    renderShell({ initialTab: 'seo' });
    await screen.findByTestId('mock-seo-form');
    // 종전엔 seedContext 미전달 → og/구조화/봇 미리보기가 seed_context:{} 로 호출돼 모듈 declaration 이
    // 샘플 상품 없이 빈 결과 반환. 이제 buildPageSampleContext 결과(product.data 등)를 흘린다.
    expect(seoFormProps.seedContext).toEqual(MOCK_SAMPLE_CONTEXT);
  });

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
    it('[자동 계산] ComputedForm.t = resolveLabel (chrome t 아님)', () => {
      renderShell();
      fireEvent.click(screen.getByTestId('g7le-page-settings-tab-computed'));
      expect(recipeFormT.computed).toBe(resolveLabel);
      expect(recipeFormT.computed).not.toBe(t);
    });

    it('[화면 동작] InitActionsForm.t = resolveLabel', () => {
      renderShell();
      fireEvent.click(screen.getByTestId('g7le-page-settings-tab-init'));
      expect(recipeFormT.init).toBe(resolveLabel);
      expect(recipeFormT.init).not.toBe(t);
    });

    it('[에러 처리] ErrorHandlingForm.t = resolveLabel', () => {
      renderShell();
      fireEvent.click(screen.getByTestId('g7le-page-settings-tab-error'));
      expect(recipeFormT.error).toBe(resolveLabel);
      expect(recipeFormT.error).not.toBe(t);
    });

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
    it('서버 응답 initActions(camel)만 있어도 화면 동작에 표시된다', () => {
      mockRaw = { initActions: [{ handler: 'loadFromLocalStorage' }, { handler: 'closeModal' }] };
      renderShell();
      fireEvent.click(screen.getByTestId('g7le-page-settings-tab-init'));
      expect(initFormProps.actions).toEqual([
        { handler: 'loadFromLocalStorage' },
        { handler: 'closeModal' },
      ]);
    });

    it('세션 패치 init_actions(snake)가 서버 initActions(camel)보다 우선한다', () => {
      mockRaw = {
        initActions: [{ handler: 'loadFromLocalStorage' }],
        init_actions: [{ handler: 'toast' }],
      };
      renderShell();
      fireEvent.click(screen.getByTestId('g7le-page-settings-tab-init'));
      expect(initFormProps.actions).toEqual([{ handler: 'toast' }]);
    });

    it('둘 다 없으면 빈 배열(폴백)', () => {
      mockRaw = {};
      renderShell();
      fireEvent.click(screen.getByTestId('g7le-page-settings-tab-init'));
      expect(initFormProps.actions).toEqual([]);
    });
  });
});
