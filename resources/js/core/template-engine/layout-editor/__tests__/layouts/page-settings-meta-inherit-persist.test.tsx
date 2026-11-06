// e2e:allow 페이지 설정 → 툴바 저장 PUT 본문 통합 단위 — 실제 문서 훅(useLayoutDocument)·모달 스택·셸·폼을 그대로 밟는다. 실서버 반영은 브라우저 실측으로 보강.
/**
 * page-settings-meta-inherit-persist.test.tsx — 상속 화면의 페이지 설정 저장이 베이스의 최상위 meta 를 화면 본문에 굳히지 않는지
 *
 * 서버는 베이스 meta 위에 화면 meta 를 얕게 덮어(`LayoutService::mergeMeta` = array_merge, seo·scroll_restoration 만 깊게)
 * 편집기에 병합본을 준다. 그래서 병합본에는 화면이 적지 않은 베이스의 title·description·is_base 등이 다 들어 있다.
 * [기본 정보]·[검색엔진] 탭은 병합본 meta 를 통째로 패치하므로, 저장 본문(__editor.original.meta)에는 이 화면이 직접 적은 키와
 * 이번에 베이스와 달라진 키만 남아야 한다 — 그래야 베이스 제목을 바꿨을 때 화면이 따라간다.
 *
 * 실제 LayoutEditorProvider → useLayoutDocument(레이아웃 GET·저장 PUT 은 fetch 가짜) → LayoutDocumentProvider →
 * EditorModalProvider/EditorModalRoot → PageSettingsModal → MetaForm/SeoForm → I18nTextField 로 화면 그대로 조립한다.
 */

import React, { useCallback } from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, act, waitFor } from '@testing-library/react';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';
import { LayoutDocumentProvider } from '../../LayoutDocumentContext';
import { EditorModalProvider, EditorModalRoot, useEditorModal } from '../../EditorModalContext';
import { useLayoutDocument } from '../../hooks/useLayoutDocument';

// ── 번역 키 자동 변환 — 응답을 붙잡았다가 하나씩 돌려준다 ──
const createdValues = new Map<string, string>();
let keySeq = 0;
const heldCommits: Array<() => void> = [];
vi.mock('../../hooks/useCustomTranslation', async (orig) => {
  const actual = (await orig()) as typeof import('../../hooks/useCustomTranslation');
  const translate = (key: string): string => createdValues.get(key) ?? '';
  return {
    ...actual,
    useCustomTranslation: () => ({
      locale: 'ko',
      templateIdentifier: 'sirsoft-basic',
      translate,
      classify: (raw: string | null | undefined) => actual.classifyCustomText(raw, translate),
      commitText: async (_current: string | null | undefined, nextValue: string) => {
        keySeq += 1;
        const key = `custom.layout.${keySeq}`;
        createdValues.set(key, nextValue);
        await new Promise<void>((r) => heldCommits.push(r));
        return { kind: 'created' as const, customKey: key, token: `$t:${key}` };
      },
    }),
  };
});
vi.mock('../../hooks/useBindingCandidates', () => ({
  useBindingCandidates: () => [],
  buildPageSampleContext: () => ({}),
}));
vi.mock('../../hooks/useSeoBindingCandidates', () => ({ useSeoBindingCandidates: () => [] }));
vi.mock('../../components/page-settings/SeoBotPreviewPanel', () => ({
  SeoBotPreviewPanel: () => <div data-testid="g7le-seo-bot-preview-stub" />,
}));

import { PageSettingsModal } from '../../components/page-settings/PageSettingsModal';

/** 베이스 `_user_base` meta (번들 원본과 같은 모양) */
const BASE_META = {
  title: '$t:user.base_layout_title',
  description: '$t:user.base_layout_description',
  is_base: true,
  seo: {
    enabled: false,
    data_sources: ['boards'],
    vars: { site_name: '$core_settings:general.site_name' },
    og: { type: 'website' },
  },
};

/** 화면 `board/boards` 가 직접 적은 meta (번들 원본과 같은 모양 — 최상위 title·description 없음) */
const SCREEN_OWN_META = {
  seo: {
    enabled: true,
    page_type: 'boards',
    priority: 0.5,
    changefreq: 'weekly',
    extensions: [{ type: 'module', id: 'sirsoft-board' }],
    data_sources: ['boardList'],
    vars: { site_name: '$core_settings:general.site_name' },
    og: { type: 'website' },
    structured_data: { '@type': 'ItemList' },
  },
};

/** 서버 mergeMeta 와 같은 규칙의 병합본(얕은 덮어쓰기 + seo 깊은 병합 + data_sources 합집합) */
function mergeMetaLikeServer(base: Record<string, any>, own: Record<string, any>): Record<string, any> {
  const merged: Record<string, any> = { ...base, ...own };
  if (base.seo && own.seo) {
    const { data_sources: baseDs = [], ...baseRest } = base.seo;
    const { data_sources: ownDs = [], ...ownRest } = own.seo;
    const deep = (a: any, b: any): any => {
      const out: Record<string, any> = { ...a };
      for (const [k, v] of Object.entries(b)) {
        out[k] = v && typeof v === 'object' && !Array.isArray(v) && out[k] && typeof out[k] === 'object' ? deep(out[k], v) : v;
      }
      return out;
    };
    merged.seo = { ...deep(baseRest, ownRest), data_sources: Array.from(new Set([...baseDs, ...ownDs])) };
  }
  return merged;
}

/** GET 응답 픽스처 — 레이아웃 이름·병합본 meta·원본 */
let fixture: { layoutName: string; meta: Record<string, any>; original: Record<string, any> };
function screenFixture(ownMeta: Record<string, any>): typeof fixture {
  return {
    layoutName: 'board/boards',
    meta: mergeMetaLikeServer(BASE_META, ownMeta),
    original: { extends: '_user_base', meta: ownMeta, slots: { content: [] } },
  };
}
function baseFixture(): typeof fixture {
  return {
    layoutName: '_user_base',
    meta: structuredClone(BASE_META),
    original: { meta: structuredClone(BASE_META), components: [] },
  };
}

let putBodies: Array<Record<string, any>> = [];
function installFetch(): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string, init?: RequestInit) => {
      const u = String(url);
      if (init?.method === 'PUT') {
        const body = JSON.parse(String(init.body));
        putBodies.push(JSON.parse(body.content));
        return { ok: true, status: 200, json: async () => ({ data: { lock_version: 2 } }) };
      }
      if (u.includes(`/api/layouts/sirsoft-basic/${fixture.layoutName}`)) {
        return {
          ok: true,
          status: 200,
          json: async () => ({
            data: {
              meta: structuredClone(fixture.meta),
              components: [],
              __editor: { original: structuredClone(fixture.original) },
              lock_version: 1,
            },
          }),
        };
      }
      return { ok: true, status: 200, json: async () => ({ data: null }) };
    }),
  );
}

function RouteSeeder({ children }: { children: React.ReactNode }): React.ReactElement {
  const { dispatch } = useLayoutEditor();
  React.useEffect(() => {
    dispatch({ type: 'SELECT_ROUTE', route: { path: '/boards', layoutName: fixture.layoutName } });
  }, [dispatch]);
  return <>{children}</>;
}

function Toolbar({ save }: { save: () => void }): React.ReactElement {
  const modal = useEditorModal();
  const open = useCallback(
    (tab: 'meta' | 'seo') => {
      const id = modal.open({
        ariaLabel: 'page-settings',
        content: (
          <PageSettingsModal
            templateIdentifier="sirsoft-basic"
            spec={null}
            t={(k: string) => k}
            resolveLabel={(k: string) => k}
            onClose={() => modal.close(id)}
            initialTab={tab}
            extensionsFetcher={async () => []}
            templateConfigFetcher={async () => undefined}
          />
        ),
      });
    },
    [modal],
  );
  return (
    <div>
      <button type="button" data-testid="open-seo" onClick={() => open('seo')}>seo</button>
      <button type="button" data-testid="open-meta" onClick={() => open('meta')}>meta</button>
      <button type="button" data-testid="close-all" onClick={() => modal.closeAll()}>close</button>
      <button type="button" data-testid="save" onClick={save}>save</button>
    </div>
  );
}

function Shell(): React.ReactElement {
  const doc = useLayoutDocument();
  return (
    <EditorModalProvider>
      <LayoutDocumentProvider value={doc}>
        <span data-testid="doc-ready">{doc.document ? 'ready' : 'loading'}</span>
        <Toolbar save={() => void doc.save()} />
        <EditorModalRoot />
      </LayoutDocumentProvider>
    </EditorModalProvider>
  );
}

async function mountEditor(): Promise<void> {
  render(
    <LayoutEditorProvider templateIdentifier="sirsoft-basic" initialLocale="ko">
      <RouteSeeder>
        <Shell />
      </RouteSeeder>
    </LayoutEditorProvider>,
  );
  await waitFor(() => expect(screen.getByTestId('doc-ready').textContent).toBe('ready'));
}

async function typeAndBlur(testid: string, value: string): Promise<void> {
  const input = (await screen.findByTestId(testid)) as HTMLInputElement;
  fireEvent.focus(input);
  fireEvent.change(input, { target: { value } });
  await act(async () => {
    fireEvent.blur(input);
    await Promise.resolve();
  });
}

async function releaseNextCommit(): Promise<void> {
  const release = heldCommits.shift();
  if (!release) throw new Error('붙잡힌 키 생성 응답이 없다');
  await act(async () => {
    release();
    await new Promise((r) => setTimeout(r, 5));
  });
}

async function clickSave(): Promise<Record<string, any>> {
  const before = putBodies.length;
  await act(async () => {
    fireEvent.click(screen.getByTestId('save'));
    await new Promise((r) => setTimeout(r, 5));
  });
  await waitFor(() => expect(putBodies.length).toBe(before + 1));
  return putBodies[putBodies.length - 1]!;
}

beforeEach(() => {
  cleanup();
  createdValues.clear();
  keySeq = 0;
  heldCommits.length = 0;
  putBodies = [];
  fixture = screenFixture(structuredClone(SCREEN_OWN_META));
  installFetch();
});
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('상속 화면 페이지 설정 저장 — 베이스의 최상위 meta 를 화면 본문에 굳히지 않는다', () => {
  /**
   * @effects meta_tab_patch_keeps_only_own_or_changed_top_level_meta_in_original
   */
  it('[기본 정보] 페이지 이름만 바꿔 저장: PUT meta 에 새 title 은 있고 베이스 description·is_base 는 없다', async () => {
    await mountEditor();
    fireEvent.click(screen.getByTestId('open-meta'));
    await typeAndBlur('g7le-meta-title-preview', '게시판 모음');
    await releaseNextCommit();
    const content = await clickSave();
    expect(content.meta?.title).toBe('$t:custom.layout.1');
    expect(content.meta).not.toHaveProperty('description');
    expect(content.meta).not.toHaveProperty('is_base');
    // 이 화면이 원래 적은 키는 그대로
    expect(content.meta?.seo?.page_type).toBe('boards');
    expect(content.meta?.seo?.data_sources).toEqual(['boardList']);
  });

  /**
   * @effects meta_tab_patch_keeps_only_own_or_changed_top_level_meta_in_original
   */
  it('[기본 정보] 페이지 설명만 바꿔 저장: PUT meta 에 새 description 은 있고 베이스 title·is_base 는 없다', async () => {
    await mountEditor();
    fireEvent.click(screen.getByTestId('open-meta'));
    await typeAndBlur('g7le-meta-description-preview', '게시판을 모아 봅니다');
    await releaseNextCommit();
    const content = await clickSave();
    expect(content.meta?.description).toBe('$t:custom.layout.1');
    expect(content.meta).not.toHaveProperty('title');
    expect(content.meta).not.toHaveProperty('is_base');
  });

  /**
   * @effects meta_tab_patch_keeps_only_own_or_changed_top_level_meta_in_original
   */
  it('[검색엔진] 검색 제목만 바꿔 저장: PUT meta 에 베이스 title·description·is_base 가 없다', async () => {
    await mountEditor();
    fireEvent.click(screen.getByTestId('open-seo'));
    await typeAndBlur('g7le-seo-title-preview', '전체 게시판 모아보기');
    await releaseNextCommit();
    const content = await clickSave();
    expect(content.meta?.seo?.title).toBe('$t:custom.layout.1');
    expect(content.meta).not.toHaveProperty('title');
    expect(content.meta).not.toHaveProperty('description');
    expect(content.meta).not.toHaveProperty('is_base');
  });

  /**
   * @effects meta_tab_patch_keeps_only_own_or_changed_top_level_meta_in_original
   */
  it('화면 원본이 베이스와 같은 값을 직접 적은 키는 유지한다(의도적 고정) — 다른 상속 키는 여전히 빠진다', async () => {
    fixture = screenFixture({
      ...structuredClone(SCREEN_OWN_META),
      description: '$t:user.base_layout_description',
      editor_label: '게시판 목록',
    });
    await mountEditor();
    fireEvent.click(screen.getByTestId('open-meta'));
    await typeAndBlur('g7le-meta-title-preview', '게시판 모음');
    await releaseNextCommit();
    const content = await clickSave();
    expect(content.meta?.title).toBe('$t:custom.layout.1');
    expect(content.meta?.description).toBe('$t:user.base_layout_description');
    expect(content.meta?.editor_label).toBe('게시판 목록');
    expect(content.meta).not.toHaveProperty('is_base');
  });

  /**
   * @effects meta_tab_patch_keeps_only_own_or_changed_top_level_meta_in_original
   */
  it('[기본 정보] 페이지 이름 → [검색엔진] 검색 제목 연달아 저장해도 베이스 description·is_base 는 끝까지 없다', async () => {
    await mountEditor();
    fireEvent.click(screen.getByTestId('open-meta'));
    await typeAndBlur('g7le-meta-title-preview', '게시판 모음');
    await releaseNextCommit();
    fireEvent.click(screen.getByTestId('close-all'));
    fireEvent.click(screen.getByTestId('open-seo'));
    await typeAndBlur('g7le-seo-title-preview', '전체 게시판 모아보기');
    await releaseNextCommit();
    const content = await clickSave();
    expect(content.meta?.title).toBe('$t:custom.layout.1');
    expect(content.meta?.seo?.title).toBe('$t:custom.layout.2');
    expect(content.meta).not.toHaveProperty('description');
    expect(content.meta).not.toHaveProperty('is_base');
  });

  /**
   * @effects meta_tab_patch_on_base_layout_persists_whole_meta
   */
  it('베이스 레이아웃 자신을 편집하면 종전대로 전부 저장한다(is_base·description 유지, title 은 새 값)', async () => {
    fixture = baseFixture();
    await mountEditor();
    fireEvent.click(screen.getByTestId('open-meta'));
    await typeAndBlur('g7le-meta-title-preview', '우리 사이트');
    await releaseNextCommit();
    const content = await clickSave();
    expect(content.meta?.title).toBe('$t:custom.layout.1');
    expect(content.meta?.description).toBe('$t:user.base_layout_description');
    expect(content.meta?.is_base).toBe(true);
    expect(content.meta?.seo?.data_sources).toEqual(['boards']);
  });
});
