/**
 * @file Img.responsiveThumbPreset.test.tsx
 * @description 이미지 썸네일 규격(`thumbPreset`)이 반응형 문법 전체에서 화면 폭대로 바뀌는지
 *
 * 레이아웃은 `responsive` 로 폭마다 다른 규격을 줄 수 있다(카드 칸이 넓어지는 휴대폰 화면은 큰 규격, 표 칸은 작은 규격).
 * 규격이 폭을 따라가지 않으면 화면은 멀쩡하고 흐린 이미지나 큰 원본 전송으로만 드러나므로, 렌더된 `<img>` 의
 * src·srcset 이 요청하는 `size` 값으로 고정한다.
 *
 * 하네스: 실물 `TemplateApp`·`renderTemplate`(루트 `ResponsiveProvider` 가 창 폭을 구독)·`ResponsiveManager`·
 * `DynamicRenderer`·`G7CoreGlobals`(`G7Core.thumbnail`·`useResponsive`) · 이 템플릿의 실물 `Img`. 이 템플릿에는 셀을 직접 그리는
 * 복합 컴포넌트가 없어 셀 렌더 경로는 관리자 템플릿 시험(같은 이름의 파일)이 본다.
 * 편집기 미리보기는 실제 미리보기 캔버스와 같은 구성(`ResponsiveProvider overrideWidth` + `DynamicRenderer isEditMode`)이다.
 * 썸네일 판정은 코어 `support/thumbnailUrl` 그대로이고 규격표·서빙 경로는 `G7Config.settings.upload` 로 준다.
 *
 * @scenario-manifest templates/_bundled/sirsoft-basic/tests/scenarios/img-thumb-preset-responsive.yaml
 *
 * @vitest-environment jsdom
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// 엔진(코어)은 저장소 루트의 react·react-dom 을, 이 템플릿의 부품은 템플릿 node_modules 의 사본을 읽는다 — 두 사본이 섞이면 훅이
// 「Invalid hook call」 로 깨지므로 이 파일에서만 템플릿 쪽 사본을 루트 사본으로 바꿔 낀다(관리자 템플릿의 같은 시험과 같은 방식)
vi.mock('react', async () => {
  const p = await import('path');
  const u = await import('url');
  const dir = p.dirname(u.fileURLToPath(import.meta.url));

  return import(/* @vite-ignore */ u.pathToFileURL(p.resolve(dir, '../../../../../../../node_modules/react/index.js')).href);
});

vi.mock('@testing-library/react', async () => {
  const p = await import('path');
  const u = await import('url');
  const dir = p.dirname(u.fileURLToPath(import.meta.url));

  return import(
    /* @vite-ignore */ u.pathToFileURL(p.resolve(dir, '../../../../../../../node_modules/@testing-library/react/dist/index.js')).href
  );
});

vi.mock('@core/api/ApiClient', () => ({
  getApiClient: () => ({
    post: vi.fn().mockResolvedValue({}),
    get: vi.fn().mockResolvedValue({}),
    removeToken: vi.fn(),
    setToken: vi.fn(),
    getToken: vi.fn().mockReturnValue(null),
    setOnUnauthorized: vi.fn(),
  }),
}));

import React from 'react';
import { render, waitFor, cleanup } from '@testing-library/react';
import { TemplateApp } from '@core/TemplateApp';
import * as engine from '@core/template-engine';
import { initializeG7CoreGlobals } from '@core/template-engine/G7CoreGlobals';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { responsiveManager } from '@core/template-engine/ResponsiveManager';
import { ResponsiveProvider } from '@core/template-engine/ResponsiveContext';
import DynamicRenderer from '@core/template-engine/DynamicRenderer';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { TranslationEngine } from '@core/template-engine/TranslationEngine';
import { ActionDispatcher } from '@core/template-engine/ActionDispatcher';
import { Img } from '../Img';

type Node = Record<string, any>;

/** 서버 `ThumbnailPresetRegistry::forFrontend()` 기본 규격과 같은 모양 */
const PRESETS = [
  { name: 'xs', width: 64, height: 64, fit: 'cover', numeric: false },
  { name: 'sm', width: 160, height: 160, fit: 'cover', numeric: false },
  { name: 'md', width: 640, height: null, fit: 'width', numeric: true },
  { name: 'lg', width: 1280, height: null, fit: 'width', numeric: true },
  { name: 'xl', width: 1920, height: null, fit: 'width', numeric: true },
];

/** 규격별 srcset 후보 (같은 맞춤·같은 비율 중 1.5배 이상 최소 규격이 2x) */
const SRCSET: Record<string, string[]> = {
  xs: ['xs', 'sm'],
  sm: ['sm'],
  md: ['md', 'lg'],
  lg: ['lg', 'xl'],
  xl: ['xl'],
};

const SRC = '/api/attachment/abc';

/**
 * 렌더된 img 가 요청하는 규격 — src 의 size 와 srcset 후보들의 size.
 *
 * @param img 대상
 * @returns 규격 요약
 */
function sizesOf(img: Element | null): { src: string | null; srcset: string[] } {
  if (!img) return { src: null, srcset: [] };
  const sizeOf = (url: string): string => new URL(url, 'https://probe.invalid/').searchParams.get('size') ?? '(none)';
  const src = img.getAttribute('src');
  const srcset = (img.getAttribute('srcset') ?? '')
    .split(',')
    .map((part) => part.trim())
    .filter((part) => part !== '')
    .map((part) => sizeOf(part.split(/\s+/)[0]));

  return { src: src === null ? null : sizeOf(src), srcset };
}

/**
 * 규격 이름 하나에 대한 기대 요약.
 *
 * @param preset 규격 이름
 * @returns 기대 요약
 */
const expected = (preset: string) => ({ src: preset, srcset: SRCSET[preset] });

/**
 * data-testid 로 찾은 img 의 요약.
 *
 * @param id data-testid
 * @param root 탐색 루트
 * @returns 규격 요약
 */
const at = (id: string, root: ParentNode = document): ReturnType<typeof sizesOf> =>
  sizesOf(root.querySelector(`[data-testid="${id}"]`));

/**
 * 렌더된 모든 img(지정 루트 안)의 요약.
 *
 * @param selector 선택자
 * @param root 탐색 루트
 * @returns 규격 요약 목록
 */
const all = (selector: string, root: ParentNode = document) => Array.from(root.querySelectorAll(selector)).map(sizesOf);

const passthrough = (tag: string) => ({ children, text, className, ...rest }: any) => {
  const domProps: Record<string, unknown> = { className };
  for (const [k, v] of Object.entries(rest)) {
    if (k.startsWith('aria-') || k.startsWith('data-') || ['id', 'role'].includes(k)) domProps[k] = v;
  }

  return React.createElement(tag, domProps, children ?? text);
};

/**
 * 시험 레지스트리 — 감싸개는 단순 태그, 이미지는 이 템플릿의 실물.
 *
 * @returns 레지스트리 항목
 */
function registryEntries(): Record<string, { component: any; metadata: any }> {
  return {
    Div: { component: passthrough('div'), metadata: { name: 'Div', type: 'basic' } },
    Span: { component: passthrough('span'), metadata: { name: 'Span', type: 'basic' } },
    Img: { component: Img, metadata: { name: 'Img', type: 'basic' } },
  };
}

/**
 * 이미지 노드.
 *
 * @param testId data-testid
 * @param thumbPreset 기본 규격(식 가능)
 * @param extra 노드에 덧붙일 속성(responsive·if·iteration 등)
 * @param src src(식 가능)
 * @returns 노드
 */
const img = (testId: string, thumbPreset: string | undefined, extra: Node = {}, src: string = SRC): Node => ({
  id: testId,
  type: 'basic',
  name: 'Img',
  props: { src, 'data-testid': testId, ...(thumbPreset === undefined ? {} : { thumbPreset }) },
  ...extra,
});

/** 창 폭을 정한다 — 실제 resize 처리기와 같은 값(window.innerWidth)을 함께 맞춘다 */
function setWindowWidth(width: number): void {
  Object.defineProperty(window, 'innerWidth', { value: width, configurable: true, writable: true });
  responsiveManager._setWidthForTesting(width);
}

interface Mounted {
  app: any;
}

let mounted: Mounted | null = null;
let originalFetch: typeof fetch;
let originalInnerWidth: number;

/**
 * 실제 앱 구조로 화면을 띄운다.
 *
 * @param children 페이지 노드
 * @param global 초기 전역 상태
 * @returns 띄운 앱
 */
async function mount(children: Node[], global: Record<string, any> = {}): Promise<Mounted> {
  document.body.innerHTML = '<div id="app"></div>';
  const app = new TemplateApp({ templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko', debug: false } as any);
  (window as any).__templateApp = app;
  initializeG7CoreGlobals({
    getState: () => engine.getState() as any,
    transitionManager: { getIsPending: () => false, subscribe: () => () => {} },
    responsiveManager: {},
    webSocketManager: {} as any,
  } as any);
  await engine.initTemplateEngine({ templateId: 'sirsoft-basic', locale: 'ko' } as any);
  (ComponentRegistry.getInstance() as any).registry = registryEntries();
  const dispatcher = engine.getActionDispatcher()!;
  dispatcher.setGlobalStateUpdater((updates: any, opts?: { render?: boolean }) => app.setGlobalState(updates, opts));

  app.setGlobalState(global, { render: false });
  const g = app.getGlobalState();
  await engine.renderTemplate({
    containerId: 'app',
    layoutJson: {
      version: '1.0.0',
      layout_name: 'test/img-thumb-preset-responsive',
      components: [{ id: 'page_root', type: 'basic', name: 'Div', props: { 'data-testid': 'page' }, children }],
    } as any,
    dataContext: { _global: g, _local: g._local, _globalSetState: (updates: any) => app.setGlobalState(updates) },
  } as any);
  await waitFor(() => expect(document.querySelector('[data-testid="page"]')).not.toBeNull());
  mounted = { app };

  return mounted;
}

function unmount(): void {
  try {
    engine.destroyTemplate();
  } catch {
    /* 정리 */
  }
  cleanup();
  delete (window as any).__templateApp;
  delete (window as any).G7Core;
  (window as any).__g7PendingLocalState = null;
  (window as any).__g7ForcedLocalFields = undefined;
  (window as any).__g7SetLocalOverrideKeys = undefined;
  (window as any).__g7LastSetLocalSnapshot = undefined;
  (window as any).__g7LayoutContextStack = [];
  document.body.innerHTML = '';
  mounted = null;
}

/**
 * 편집기 미리보기 캔버스와 같은 구성으로 노드를 그린다 (`PreviewCanvas` — ResponsiveProvider overrideWidth + isEditMode).
 *
 * 관리자 앱(G7Core·창 폭 구독)이 떠 있는 상태에서 부른다 — 실제 편집기도 관리자 화면 안의 영역이다.
 *
 * @param nodes 미리보기에 그릴 노드
 * @param deviceWidth 미리보기 기기 폭
 * @param dataContext 미리보기 데이터 컨텍스트
 * @returns 다시 그리기 함수와 미리보기 루트
 */
function renderPreview(nodes: Node[], deviceWidth: number, dataContext: Record<string, any> = {}) {
  const registry = ComponentRegistry.getInstance();
  const bindingEngine = new DataBindingEngine();
  const translationEngine = TranslationEngine.getInstance();
  const actionDispatcher = new ActionDispatcher({ navigate: vi.fn() } as any);
  const tree = (width: number) =>
    React.createElement(
      'div',
      { 'data-testid': 'preview-frame' },
      React.createElement(ResponsiveProvider, {
        overrideWidth: width,
        children: nodes.map((node, index) =>
          React.createElement(DynamicRenderer as any, {
            key: node.id ?? `preview-${index}`,
            componentDef: node,
            dataContext,
            translationContext: { templateId: 'sirsoft-basic', locale: 'ko' },
            registry,
            bindingEngine,
            translationEngine,
            actionDispatcher,
            isEditMode: true,
            isRootRenderer: index === 0,
            componentPath: `${index}`,
          }),
        ),
      }),
    );
  const view = render(tree(deviceWidth));
  const frame = () => view.container.querySelector('[data-testid="preview-frame"]') as HTMLElement;

  return { rerender: (width: number) => view.rerender(tree(width)), frame };
}

beforeEach(() => {
  originalInnerWidth = window.innerWidth;
  originalFetch = globalThis.fetch;
  globalThis.fetch = vi.fn(() =>
    Promise.resolve({ ok: true, status: 200, statusText: 'OK', json: async () => ({ data: {} }) } as any),
  ) as any;
  (window as any).G7Config = {
    settings: {
      upload: {
        thumbnail_presets_public: PRESETS,
        thumbnail_url_patterns: ['/api/attachment/*'],
        thumbnail_content_preset: 'lg',
      },
    },
  };
});

afterEach(() => {
  unmount();
  globalThis.fetch = originalFetch;
  delete (window as any).G7Config;
  setWindowWidth(originalInnerWidth);
});

// ---------------------------------------------------------------------------
// 1. 프리셋 4개와 경계값
// ---------------------------------------------------------------------------

describe('프리셋 구간 — mobile·tablet·desktop·portable 과 경계값', () => {
  const nodes = [
    img('three', 'md', {
      responsive: {
        mobile: { props: { thumbPreset: 'xs' } },
        tablet: { props: { thumbPreset: 'sm' } },
        desktop: { props: { thumbPreset: 'lg' } },
      },
    }),
    img('portable', 'xl', { responsive: { portable: { props: { thumbPreset: 'md' } } } }),
    // 프리셋끼리 겹치면 좁은 쪽(mobile 0-767)이 넓은 쪽(portable 0-1023)을 이긴다
    img('overlap', 'xl', {
      responsive: { portable: { props: { thumbPreset: 'md' } }, mobile: { props: { thumbPreset: 'xs' } } },
    }),
  ];

  /**
   * @scenario grammar=preset_breakpoint
   * @effects thumb_preset_follows_preset_breakpoints, thumb_preset_breakpoint_boundaries_exact, thumb_preset_narrower_preset_wins_over_portable
   */
  it.each([
    [320, 'xs', 'md', 'xs'],
    [767, 'xs', 'md', 'xs'],
    [768, 'sm', 'md', 'md'],
    [1023, 'sm', 'md', 'md'],
    [1024, 'lg', 'xl', 'xl'],
    [1920, 'lg', 'xl', 'xl'],
  ])('창 %ipx — mobile/tablet/desktop 노드 %s, portable 노드 %s, portable+mobile 노드 %s', async (width, three, portable, overlap) => {
    setWindowWidth(width);
    await mount(nodes);

    expect(at('three')).toEqual(expected(three));
    expect(at('portable')).toEqual(expected(portable));
    expect(at('overlap')).toEqual(expected(overlap));
  });
});

// ---------------------------------------------------------------------------
// 2. 커스텀 구간 3형식과 우선순위
// ---------------------------------------------------------------------------

describe('커스텀 구간 — `min-max`·`-max`·`min-` 과 우선순위', () => {
  const nodes = [
    img('custom', 'md', {
      responsive: {
        '-599': { props: { thumbPreset: 'xs' } },
        '600-899': { props: { thumbPreset: 'sm' } },
        '1200-': { props: { thumbPreset: 'xl' } },
      },
    }),
    // 같은 범위면 커스텀이 프리셋을 이긴다 (선언 순서는 상관없다)
    img('customOverPreset', 'md', {
      responsive: { '1024-': { props: { thumbPreset: 'xl' } }, desktop: { props: { thumbPreset: 'lg' } } },
    }),
    // 커스텀은 더 넓어도 프리셋을 이긴다 (0-1023 커스텀 > mobile 프리셋)
    img('wideCustomOverNarrowPreset', 'lg', {
      responsive: { mobile: { props: { thumbPreset: 'xs' } }, '0-1023': { props: { thumbPreset: 'sm' } } },
    }),
    // 커스텀끼리는 좁은 범위가 이긴다
    img('narrowCustom', 'md', {
      responsive: { '1024-': { props: { thumbPreset: 'lg' } }, '1024-1279': { props: { thumbPreset: 'sm' } } },
    }),
  ];

  /**
   * @scenario grammar=custom_range
   * @effects thumb_preset_follows_custom_ranges, thumb_preset_custom_range_wins_over_preset, thumb_preset_narrower_custom_range_wins
   */
  it.each([
    [500, 'xs', 'md', 'sm', 'md'],
    [599, 'xs', 'md', 'sm', 'md'],
    [600, 'sm', 'md', 'sm', 'md'],
    [899, 'sm', 'md', 'sm', 'md'],
    [900, 'md', 'md', 'sm', 'md'],
    [1023, 'md', 'md', 'sm', 'md'],
    [1024, 'md', 'xl', 'lg', 'sm'],
    [1199, 'md', 'xl', 'lg', 'sm'],
    [1200, 'xl', 'xl', 'lg', 'sm'],
    [1280, 'xl', 'xl', 'lg', 'lg'],
  ])('창 %ipx — 3형식 %s, 커스텀>프리셋 %s, 넓은 커스텀>좁은 프리셋 %s, 좁은 커스텀 %s', async (width, custom, over, wide, narrow) => {
    setWindowWidth(width);
    await mount(nodes);

    expect(at('custom')).toEqual(expected(custom));
    expect(at('customOverPreset')).toEqual(expected(over));
    expect(at('wideCustomOverNarrowPreset')).toEqual(expected(wide));
    expect(at('narrowCustom')).toEqual(expected(narrow));
  });
});

// ---------------------------------------------------------------------------
// 3. 식 바인딩 — 전역 상태로 바뀌는 규격, responsive 안의 식
// ---------------------------------------------------------------------------

describe('식 바인딩 — `thumbPreset="{{...}}"` 과 responsive 안의 식', () => {
  /**
   * @scenario grammar=expression_binding
   * @effects thumb_preset_expression_rebinds_on_global_state_change
   */
  it('전역 상태를 바꾸면 같은 화면에서 규격이 다시 계산된다', async () => {
    setWindowWidth(1280);
    const m = await mount([img('bound', '{{_global.thumbSize}}')], { thumbSize: 'sm' });
    expect(at('bound')).toEqual(expected('sm'));

    m.app.setGlobalState({ thumbSize: 'lg' });
    await waitFor(() => expect(at('bound')).toEqual(expected('lg')));
  });

  /**
   * @scenario grammar=expression_binding
   * @effects thumb_preset_expression_inside_responsive_evaluated
   */
  it('responsive 안의 식은 그 구간에서만 평가되고 전역 상태 변경을 따른다', async () => {
    const nodes = [
      img('bound', '{{_global.wideSize}}', {
        responsive: { mobile: { props: { thumbPreset: "{{_global.narrowSize ?? 'xs'}}" } } },
      }),
    ];

    setWindowWidth(500);
    const m = await mount(nodes, { wideSize: 'lg' });
    expect(at('bound')).toEqual(expected('xs'));

    m.app.setGlobalState({ narrowSize: 'sm' });
    await waitFor(() => expect(at('bound')).toEqual(expected('sm')));

    unmount();
    setWindowWidth(1280);
    await mount(nodes, { wideSize: 'lg', narrowSize: 'sm' });
    expect(at('bound')).toEqual(expected('lg'));
  });
});

// ---------------------------------------------------------------------------
// 4. children 교체·반복·if 오버라이드
// ---------------------------------------------------------------------------

describe('노드 문법 — responsive children 교체·iteration·if 오버라이드', () => {
  const items = [{ url: '/api/attachment/a1' }, { url: '/api/attachment/a2' }];

  const nodes = [
    // responsive 의 children 교체 안에 든 Img (교체된 자식도 다시 responsive 를 가진다)
    {
      id: 'swap',
      type: 'basic',
      name: 'Div',
      props: { 'data-testid': 'swap' },
      children: [img('swapBase', 'lg')],
      responsive: {
        portable: {
          children: [img('swapChild', 'md', { responsive: { mobile: { props: { thumbPreset: 'xs' } } } })],
        },
      },
    },
    // 반복 안의 Img — 자식 Img 가 responsive 를 가진다
    {
      id: 'loop',
      type: 'basic',
      name: 'Div',
      props: { 'data-testid': 'loop' },
      children: [
        {
          id: 'loopItem',
          type: 'basic',
          name: 'Div',
          iteration: { source: '{{_global.items}}', item_var: 'it', index_var: 'i' },
          children: [img('loopImg', 'lg', { responsive: { mobile: { props: { thumbPreset: 'xs' } } } }, '{{it.url}}')],
        },
      ],
    },
    // 반복하는 노드 자신이 responsive 를 가진 Img
    {
      id: 'selfLoop',
      type: 'basic',
      name: 'Div',
      props: { 'data-testid': 'selfLoop' },
      children: [
        img(
          'selfLoopImg',
          'lg',
          {
            iteration: { source: '{{_global.items}}', item_var: 'it', index_var: 'i' },
            responsive: { tablet: { props: { thumbPreset: 'sm' } }, mobile: { props: { thumbPreset: 'xs' } } },
          },
          '{{it.url}}',
        ),
      ],
    },
    // responsive 가 iteration 자체를 바꾼다 (휴대폰은 첫 장만)
    {
      id: 'iterSwap',
      type: 'basic',
      name: 'Div',
      props: { 'data-testid': 'iterSwap' },
      children: [
        img(
          'iterSwapImg',
          'lg',
          {
            iteration: { source: '{{_global.items}}', item_var: 'it', index_var: 'i' },
            responsive: {
              mobile: {
                iteration: { source: '{{_global.items.slice(0, 1)}}', item_var: 'it', index_var: 'i' },
                props: { thumbPreset: 'xs' },
              },
            },
          },
          '{{it.url}}',
        ),
      ],
    },
    // if 오버라이드 — 넓은 화면용과 좁은 화면용 이미지를 구간마다 하나만
    img('wideOnly', 'lg', { if: '{{true}}', responsive: { portable: { if: '{{false}}' } } }),
    img('narrowOnly', 'xs', { if: '{{false}}', responsive: { portable: { if: '{{true}}' } } }),
  ];

  /**
   * @scenario grammar=responsive_children, grammar=iteration, grammar=if_override
   * @effects thumb_preset_in_responsive_children_override, thumb_preset_in_iteration_follows_width, thumb_preset_if_override_swaps_image
   */
  it.each([
    [500, { swap: 'xs', loop: 'xs', self: 'xs', iterSwap: ['xs'], wide: false, narrow: true }],
    [900, { swap: 'md', loop: 'lg', self: 'sm', iterSwap: ['lg', 'lg'], wide: false, narrow: true }],
    [1280, { swap: 'lg', loop: 'lg', self: 'lg', iterSwap: ['lg', 'lg'], wide: true, narrow: false }],
  ])('창 %ipx', async (width, want) => {
    setWindowWidth(width);
    await mount(nodes, { items });

    const swap = document.querySelector('[data-testid="swap"]')!;
    const swapImgs = all('img', swap);
    expect(swapImgs).toEqual([expected(want.swap)]);
    expect(swap.querySelector(`[data-testid="${width <= 1023 ? 'swapChild' : 'swapBase'}"]`)).not.toBeNull();

    expect(all('img', document.querySelector('[data-testid="loop"]')!)).toEqual([expected(want.loop), expected(want.loop)]);
    expect(all('img', document.querySelector('[data-testid="selfLoop"]')!)).toEqual([expected(want.self), expected(want.self)]);
    expect(all('img', document.querySelector('[data-testid="iterSwap"]')!)).toEqual(want.iterSwap.map(expected));

    expect(document.querySelector('[data-testid="wideOnly"]') !== null).toBe(want.wide);
    expect(document.querySelector('[data-testid="narrowOnly"]') !== null).toBe(want.narrow);
    if (want.wide) expect(at('wideOnly')).toEqual(expected('lg'));
    if (want.narrow) expect(at('narrowOnly')).toEqual(expected('xs'));
  });
});

// ---------------------------------------------------------------------------
// 5. 편집기 미리보기 폭
// ---------------------------------------------------------------------------

describe('편집기 미리보기 — 미리보기 기기 폭을 따른다', () => {
  const nodes = [
    img('preview', 'md', {
      responsive: {
        mobile: { props: { thumbPreset: 'xs' } },
        tablet: { props: { thumbPreset: 'sm' } },
        desktop: { props: { thumbPreset: 'lg' } },
      },
    }),
  ];

  /**
   * @scenario grammar=editor_preview
   * @effects thumb_preset_editor_preview_follows_device_width
   */
  it('창 1440px 에서 미리보기 기기를 390→820→1280 으로 바꾸면 xs→sm→lg', async () => {
    setWindowWidth(1440);
    await mount([]);

    const preview = renderPreview(nodes, 390);
    expect(at('preview', preview.frame())).toEqual(expected('xs'));

    preview.rerender(820);
    await waitFor(() => expect(at('preview', preview.frame())).toEqual(expected('sm')));

    preview.rerender(1280);
    await waitFor(() => expect(at('preview', preview.frame())).toEqual(expected('lg')));
  });

  /**
   * @scenario grammar=editor_preview
   * @effects thumb_preset_editor_preview_follows_device_width
   */
  it('미리보기 1280px 이면 창을 500px 로 줄여도 lg 그대로다', async () => {
    setWindowWidth(1440);
    await mount([]);

    const preview = renderPreview(nodes, 1280);
    expect(at('preview', preview.frame())).toEqual(expected('lg'));

    setWindowWidth(500);
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(at('preview', preview.frame())).toEqual(expected('lg'));
  });
});

// ---------------------------------------------------------------------------
// 6. 창 크기 변경
// ---------------------------------------------------------------------------

describe('창 크기 변경 — resize 뒤 다시 그려져 규격이 바뀐다', () => {
  /**
   * 실제 resize 이벤트를 보낸다 — ResponsiveManager 는 150ms 디바운스 뒤 window.innerWidth 를 읽는다.
   *
   * @param width 새 창 폭
   */
  async function resizeTo(width: number): Promise<void> {
    Object.defineProperty(window, 'innerWidth', { value: width, configurable: true, writable: true });
    window.dispatchEvent(new Event('resize'));
    await new Promise((resolve) => setTimeout(resolve, 220));
  }

  /** 반복 안 자식 Img 가 responsive 를 가진 묶음 */
  const childLoop = (): Node => ({
    id: 'childLoop',
    type: 'basic',
    name: 'Div',
    props: { 'data-testid': 'childLoop' },
    children: [
      {
        id: 'childLoopItem',
        type: 'basic',
        name: 'Div',
        iteration: { source: '{{_global.items}}', item_var: 'it', index_var: 'i' },
        children: [img('childLoopImg', 'lg', { responsive: { '-767': { props: { thumbPreset: 'xs' } } } }, '{{it.url}}')],
      },
    ],
  });

  /** 반복하는 노드 자신이 responsive 로 규격을 바꾸는 묶음 */
  const selfLoop = (): Node => ({
    id: 'selfLoop',
    type: 'basic',
    name: 'Div',
    props: { 'data-testid': 'selfLoop' },
    children: [
      img(
        'selfLoopImg',
        'lg',
        {
          iteration: { source: '{{_global.items}}', item_var: 'it', index_var: 'i' },
          responsive: { '-767': { props: { thumbPreset: 'xs' } } },
        },
        '{{it.url}}',
      ),
    ],
  });

  const items = [{ url: '/api/attachment/a1' }, { url: '/api/attachment/a2' }];

  /**
   * @scenario grammar=window_resize
   * @effects thumb_preset_rerenders_on_window_resize
   */
  it('1280→500→900→1280 으로 줄였다 늘리면 일반 노드·반복 안 자식이 모두 따라 바뀐다', async () => {
    setWindowWidth(1280);
    await mount(
      [
        img('plain', 'md', {
          responsive: {
            mobile: { props: { thumbPreset: 'xs' } },
            tablet: { props: { thumbPreset: 'sm' } },
            desktop: { props: { thumbPreset: 'lg' } },
          },
        }),
        childLoop(),
      ],
      { items },
    );

    const snapshot = () => ({
      plain: at('plain'),
      loop: all('img', document.querySelector('[data-testid="childLoop"]')!),
    });
    const want = (plain: string, loop: string) => ({
      plain: expected(plain),
      loop: [expected(loop), expected(loop)],
    });

    expect(snapshot()).toEqual(want('lg', 'lg'));

    await resizeTo(500);
    await waitFor(() => expect(snapshot()).toEqual(want('xs', 'xs')));

    await resizeTo(900);
    await waitFor(() => expect(snapshot()).toEqual(want('sm', 'lg')));

    await resizeTo(1280);
    await waitFor(() => expect(snapshot()).toEqual(want('lg', 'lg')));
  });

  /**
   * 반복하는 노드 자신의 responsive props 는 첫 렌더 폭에서는 맞지만(위 「노드 문법」 묶음), 폭이 바뀐 뒤에도 따라가야 한다.
   *
   * @scenario grammar=window_resize, grammar=iteration
   * @effects thumb_preset_rerenders_on_window_resize, thumb_preset_in_iteration_follows_width
   */
  it('반복하는 Img 자신의 responsive 규격도 resize 뒤 바뀐다 (1280→500→1280)', async () => {
    setWindowWidth(1280);
    await mount([selfLoop()], { items });
    const loop = () => all('img', document.querySelector('[data-testid="selfLoop"]')!);
    expect(loop()).toEqual([expected('lg'), expected('lg')]);

    await resizeTo(500);
    await waitFor(() => expect(loop()).toEqual([expected('xs'), expected('xs')]));

    await resizeTo(1280);
    await waitFor(() => expect(loop()).toEqual([expected('lg'), expected('lg')]));
  });

  /**
   * @scenario grammar=editor_preview, grammar=iteration
   * @effects thumb_preset_editor_preview_follows_device_width, thumb_preset_in_iteration_follows_width
   */
  it('편집기 미리보기 기기를 1280→390 으로 바꾸면 반복하는 Img 자신의 responsive 규격도 바뀐다', async () => {
    setWindowWidth(1440);
    await mount([]);

    const preview = renderPreview([selfLoop()], 1280, { _global: { items }, _local: {} });
    const loop = () => all('img', preview.frame().querySelector('[data-testid="selfLoop"]')!);
    expect(loop()).toEqual([expected('lg'), expected('lg')]);

    preview.rerender(390);
    await waitFor(() => expect(loop()).toEqual([expected('xs'), expected('xs')]));
  });
});
