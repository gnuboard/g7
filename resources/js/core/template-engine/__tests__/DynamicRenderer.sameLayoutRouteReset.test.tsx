/**
 * DynamicRenderer.sameLayoutRouteReset.test.tsx
 *
 * 트러블슈팅 state-closure 「Global State 공유 충돌」 사례 5 (engine-v1.79.0) — 실물 렌더 회귀 잠금.
 *
 * 같은 레이아웃을 쓰는 두 화면(게시판 「수정」 `/admin/boards/{slug}/edit` ↔ 「등록」 `/admin/boards/create`,
 * 게시글 수정 ↔ 작성)을 SPA 로 오가면 layoutKey(= layout_name)가 같아 engine-v1.72.1 의 렌더 단계 초기화가
 * 발동하지 않는다. 상태 소유자인 `_fromBase` 루트의 저장소 A 에는 수정 화면에서 자동 바인딩이 남긴 form
 * 전체(`id`·`name.en` …)가 그대로 있고, 레이아웃이 init 에서 저장소 B 의 form 을 비워도
 * `deepMergeState(dataContext._local, dynamicState)` 가 stale A 를 그 위에 덮는다.
 *   - 화면: 등록 화면의 숨은 영어 칸에 직전 게시판 영어 이름이 보인다
 *   - 입력: 한국어 이름을 치는 순간 자동 바인딩 base(병합 컨텍스트)에 실린 `id`·`name.en` 이 form 에 되살아나
 *     저장 요청 본문으로 나간다
 *
 * 해결: 상태 소유 루트는 라우트 경로가 바뀌면(같은 레이아웃이라도) 저장소 A 를 렌더 단계에서 비운다.
 * 쿼리만 바뀌는 이동(검색·쪽 번호·탭)은 경로가 같아 초기화하지 않는다.
 *
 * fail-first: 초기화 조건에서 경로를 빼면(layoutKey 만 보면) 등록 화면의 첫 렌더부터 이전 form 이 보여 red.
 *
 * @see 트러블슈팅 state-closure 「Global State 공유 충돌」 사례 5 · 사례 4(engine-v1.72.1)
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup, act, fireEvent } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { resetLocalInitTracking } from '../localInitSlot';

vi.mock('../ResponsiveManager', () => ({
  responsiveManager: {
    getWidth: vi.fn(() => 1440),
    subscribe: vi.fn(() => () => {}),
    getMatchingKey: vi.fn(() => null),
    parseRange: vi.fn(() => null),
  },
}));

type ValueLog = { page: string; value: unknown };

const TestDiv: React.FC<Record<string, unknown>> = (props) => {
  const { children, ...rest } = props as { children?: React.ReactNode };
  const domProps: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(rest)) {
    if (typeof value !== 'object' && typeof value !== 'function') domProps[key] = value;
  }
  return <div {...(domProps as Record<string, never>)}>{children}</div>;
};

/**
 * MultilingualInput 의 자동 바인딩 계약만 남긴 모형 — 받은 `value` 를 그리고, 한국어 칸 입력은
 * 받은 값 전체에 ko 만 바꿔 `onChange` 로 되돌린다(실제 컴포넌트와 같은 방식).
 */
const makeMultilingualEcho = (log: ValueLog[]): React.FC<{ page?: string; value?: Record<string, string>; onChange?: (e: unknown) => void }> => {
  const Echo: React.FC<{ page?: string; value?: Record<string, string>; onChange?: (e: unknown) => void }> = ({ page = '?', value, onChange }) => {
    log.push({ page, value });
    return (
      <div>
        <span data-testid={`ml-value-${page}`}>{JSON.stringify(value ?? null)}</span>
        <input
          data-testid={`ml-ko-${page}`}
          value={value?.ko ?? ''}
          onChange={(e) => onChange?.({ target: { value: { ...(value ?? {}), ko: e.target.value } } })}
        />
      </div>
    );
  };
  return Echo;
};

/** 관리자 base 골격: `_fromBase` 루트(상태 소유자) → 슬롯 래퍼(data-key="form") → 다국어 이름 칸 */
const rootDef = (page: string, debounce?: number): ComponentDefinition =>
  ({
    id: 'admin_layout_root',
    type: 'basic',
    name: 'Div',
    _fromBase: true,
    children: [
      {
        id: 'board_form_wrapper',
        type: 'basic',
        name: 'Div',
        dataKey: 'form',
        trackChanges: true,
        ...(debounce ? { debounce } : {}),
        children: [
          {
            id: 'field_name_input',
            type: 'composite',
            name: 'MultilingualEcho',
            props: { page, name: 'name' },
          },
        ],
      },
    ],
  }) as unknown as ComponentDefinition;

describe('[case:state-closure-same-layout-route-stale-store-a] 같은 레이아웃의 다른 경로로 이동하면 보존 루트의 저장소 A 에 남은 이전 화면 폼 값이 새 화면에 섞인다 (engine-v1.79.0)', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let translationContext: TranslationContext;
  let globalState: { _local: Record<string, any>; [key: string]: any };
  let log: ValueLog[];

  const flush = async (): Promise<void> => {
    await act(async () => {
      await Promise.resolve();
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  };

  beforeEach(() => {
    resetLocalInitTracking();
    log = [];
    globalState = { _local: {} };
    (window as any).__templateApp = {
      getGlobalState: () => globalState,
      setGlobalState: (updates: any) => {
        globalState = { ...globalState, ...updates };
      },
    };
    (window as any).__g7PendingLocalState = null;
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7SetLocalOverrideKeys = undefined;
    (window as any).__g7SequenceLocalSync = undefined;
    (window as any).__g7LayoutContextStack = [];

    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
      MultilingualEcho: { component: makeMultilingualEcho(log), metadata: { name: 'MultilingualEcho', type: 'composite' } },
    };
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    actionDispatcher.setGlobalStateUpdater((updates: any) => {
      (window as any).__templateApp.setGlobalState(updates);
    });
    translationContext = { templateId: 'test-template', locale: 'ko' };
  });

  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    delete (window as any).__templateApp;
    delete (window as any).__g7PendingLocalState;
    delete (window as any).__g7ForcedLocalFields;
    delete (window as any).__g7SetLocalOverrideKeys;
    delete (window as any).__g7SequenceLocalSync;
    (window as any).__g7LayoutContextStack = [];
  });

  const renderPage = (page: string, path: string, local: Record<string, any>, query: Record<string, any> = {}, debounce?: number) => (
    <DynamicRenderer
      componentDef={rootDef(page, debounce)}
      dataContext={{ _local: local, _global: globalState, route: { path }, query }}
      translationContext={translationContext}
      registry={registry}
      bindingEngine={bindingEngine}
      translationEngine={translationEngine}
      actionDispatcher={actionDispatcher}
      isRootRenderer={false}
      layoutKey="admin_board_form"
    />
  );

  /**
   * 증상: 수정 화면에서 이름을 고친 뒤 등록 화면으로 가면 영어 칸에 직전 영어 이름이 보이고,
   *       한국어를 치는 순간 form 에 이전 게시판 id·영어 이름이 되살아난다.
   *
   * @scenario case=route_change_board_edit_to_create
   * @effects destination_first_render_has_no_previous_leaf, typing_does_not_resurrect_previous_id
   */
  it('수정 화면에서 고친 뒤 같은 레이아웃의 등록 화면으로 가면 이전 form 의 id·name.en 이 첫 렌더부터 섞이지 않는다', async () => {
    // 1) 수정 화면 — initLocal 이 저장소 B 에 서버 값을 채운 상태
    globalState._local = { form: { id: 1005, name: { ko: 'qa736 PG 가', en: 'qa736 PG Alpha' } }, hasChanges: false };
    const view = render(renderPage('edit', '/admin/boards/qa736-pg-a/edit', globalState._local));
    await flush();
    expect(view.getByTestId('ml-value-edit').textContent).toBe(JSON.stringify({ ko: 'qa736 PG 가', en: 'qa736 PG Alpha' }));

    // 2) 사용자가 한국어 이름을 고친다 — 실제 writer(자동 바인딩)가 상태 소유 루트의 저장소 A 에 form 전체를 쓴다
    fireEvent.change(view.getByTestId('ml-ko-edit'), { target: { value: 'qa736 PG 가2y' } });
    await flush();
    expect(view.getByTestId('ml-value-edit').textContent).toBe(JSON.stringify({ ko: 'qa736 PG 가2y', en: 'qa736 PG Alpha' }));

    // 3) 같은 레이아웃의 등록 화면으로 SPA 이동 — 레이아웃 init(form=null)·initLocal(replace) 이 저장소 B 를 새 폼으로 바꾼 상태
    globalState._local = { form: { name: { ko: '' } }, hasChanges: false };
    const createLocal = globalState._local;
    const before = log.length;
    view.rerender(renderPage('create', '/admin/boards/create', createLocal));
    await flush();

    const createRenders = log.slice(before).filter((entry) => entry.page === 'create');
    expect(createRenders.length).toBeGreaterThan(0);
    for (const entry of createRenders) {
      expect(entry.value).toEqual({ ko: '' });
    }

    // 4) 등록 화면에서 한국어 이름을 치면 form 에는 새 값만 있다 (이전 게시판 id·영어 이름이 되살아나지 않는다)
    fireEvent.change(view.getByTestId('ml-ko-create'), { target: { value: '새게시판' } });
    await flush();
    expect(view.getByTestId('ml-value-create').textContent).toBe(JSON.stringify({ ko: '새게시판' }));
    const pending = (window as any).__g7PendingLocalState;
    if (pending?.form) {
      expect(pending.form).not.toHaveProperty('id');
      expect(pending.form.name).not.toHaveProperty('en');
    }
  });

  /**
   * 쿼리만 바뀌는 이동(경로 같음)은 초기화하지 않는다 — 검색·쪽 번호·탭 전환 중의 미저장 입력을 보존한다.
   *
   * @scenario case=query_only_change
   * @effects store_a_kept_on_query_only_change
   */
  it('경로가 같고 쿼리만 바뀌면 저장소 A 를 비우지 않는다', async () => {
    globalState._local = { form: { id: 1005, name: { ko: '가', en: 'Alpha' } }, hasChanges: false };
    const view = render(renderPage('edit', '/admin/boards/qa736-pg-a/edit', globalState._local, {}));
    await flush();
    fireEvent.change(view.getByTestId('ml-ko-edit'), { target: { value: '가2y' } });
    await flush();

    // B 는 고친 값을 아직 모르는 스냅샷(엔진 B 동기화는 G7Core 경로 — 이 하네스에는 없다)
    view.rerender(renderPage('edit', '/admin/boards/qa736-pg-a/edit', { ...globalState._local }, { tab: 'permissions' }));
    await flush();
    expect(view.getByTestId('ml-value-edit').textContent).toBe(JSON.stringify({ ko: '가2y', en: 'Alpha' }));
  });

  /**
   * 디바운스가 걸린 폼에서 수정 화면에 친 입력이 대기 중일 때 같은 레이아웃의 등록 화면으로 옮기면, 그 타이머가
   * 새 화면에서 터지더라도 예약 때의 `_local` 전체(이전 레코드 id·영어 이름)를 다시 쓰지 않는다.
   *
   * @scenario case=debounced_auto_binding_route_change
   * @effects pending_debounce_dropped_after_route_change
   */
  it('수정 화면 디바운스 입력이 경로를 옮긴 뒤 터져도 등록 화면에 이전 폼을 되살리지 않는다', async () => {
    window.history.replaceState(null, '', '/admin/boards/qa736-pg-a/edit');
    globalState._local = { form: { id: 1005, name: { ko: '가', en: 'Alpha' } }, hasChanges: false };
    const view = render(renderPage('edit', '/admin/boards/qa736-pg-a/edit', globalState._local, {}, 60));
    await flush();
    fireEvent.change(view.getByTestId('ml-ko-edit'), { target: { value: '가2y' } });

    // 디바운스 대기 중에 등록 화면으로 이동
    window.history.replaceState(null, '', '/admin/boards/create');
    globalState._local = { form: { name: { ko: '' } }, hasChanges: false };
    view.rerender(renderPage('create', '/admin/boards/create', globalState._local, {}, 60));
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 150));
    });
    await flush();

    expect(view.getByTestId('ml-value-create').textContent).toBe(JSON.stringify({ ko: '' }));
  });
});
