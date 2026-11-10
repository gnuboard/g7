// e2e:allow 페이지 설정 → 툴바 저장 PUT 본문 통합 단위 — 실제 문서 훅(useLayoutDocument)·모달 스택·셸·폼을 그대로 밟는다. 실서버 반영은 브라우저 실측으로 보강.
/**
 * page-settings-seo-save-roundtrip.test.tsx — [검색엔진]·[기본 정보]에 적은 값이 저장 PUT 본문에 실리는지
 *
 * 실제 LayoutEditorProvider → useLayoutDocument(레이아웃 GET·저장 PUT 은 fetch 가짜) → LayoutDocumentProvider →
 * EditorModalProvider/EditorModalRoot → PageSettingsModal → SeoForm/MetaForm → I18nTextField 로 화면 그대로 조립한다.
 * 번역 키 생성(useCustomTranslation.commitText)만 응답 시점을 붙잡을 수 있는 가짜다 — 서버 왕복(키 생성 + 사전 다시 읽기) 동안
 * 다른 칸을 입력하거나 창을 닫는 실제 순서를 재현한다.
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
        const key = `custom.board_boards.${keySeq}`;
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

/** 베이스 `_user_base` meta / 화면 `board/boards` 가 직접 적은 meta / 서버 병합본 (번들 원본과 같은 모양) */
const OWN_META = {
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
const MERGED_META = {
  title: '$t:user.base_layout_title',
  description: '$t:user.base_layout_description',
  is_base: true,
  seo: {
    enabled: true,
    vars: { site_name: '$core_settings:general.site_name' },
    og: { type: 'website' },
    page_type: 'boards',
    priority: 0.5,
    changefreq: 'weekly',
    extensions: [{ type: 'module', id: 'sirsoft-board' }],
    structured_data: { '@type': 'ItemList' },
    data_sources: ['boards', 'boardList'],
  },
};

let putBodies: Array<Record<string, any>> = [];
function installFetch(): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string, init?: RequestInit) => {
      const u = String(url);
      if (u.includes('/api/layouts/sirsoft-basic/board/boards')) {
        return {
          ok: true,
          status: 200,
          json: async () => ({
            data: {
              meta: structuredClone(MERGED_META),
              components: [],
              __editor: { original: { extends: '_user_base', meta: structuredClone(OWN_META), slots: { content: [] } } },
              lock_version: 1,
            },
          }),
        };
      }
      if (init?.method === 'PUT') {
        const body = JSON.parse(String(init.body));
        putBodies.push(JSON.parse(body.content));
        return { ok: true, status: 200, json: async () => ({ data: { lock_version: 2 } }) };
      }
      return { ok: true, status: 200, json: async () => ({ data: null }) };
    }),
  );
}

function RouteSeeder({ children }: { children: React.ReactNode }): React.ReactElement {
  const { dispatch } = useLayoutEditor();
  React.useEffect(() => {
    dispatch({ type: 'SELECT_ROUTE', route: { path: '/boards', layoutName: 'board/boards' } });
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
  expect(putBodies.length).toBe(before + 1);
  return putBodies[putBodies.length - 1]!;
}

beforeEach(() => {
  cleanup();
  createdValues.clear();
  keySeq = 0;
  heldCommits.length = 0;
  putBodies = [];
  installFetch();
});
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('페이지 설정 → 저장 PUT 본문 — 새로 적은 검색 제목이 실린다', () => {
  /**
   * @effects seo_tab_new_search_title_persists_in_original_meta_seo
   */
  it('「검색 제목」 입력 → 응답 → 닫기 → 저장: content.meta.seo.title 이 실린다', async () => {
    await mountEditor();
    fireEvent.click(screen.getByTestId('open-seo'));
    await typeAndBlur('g7le-seo-title-preview', '전체 게시판 모아보기');
    await releaseNextCommit();
    fireEvent.click(screen.getByTestId('close-all'));
    const content = await clickSave();
    expect(content.meta?.seo?.title).toBe('$t:custom.board_boards.1');
  });

  /**
   * @effects seo_tab_new_search_title_persists_in_original_meta_seo
   */
  it('「검색 제목」 입력 → 응답 전에 창 닫기 → 응답 → 저장: content.meta.seo.title 이 실린다', async () => {
    await mountEditor();
    fireEvent.click(screen.getByTestId('open-seo'));
    await typeAndBlur('g7le-seo-title-preview', '전체 게시판 모아보기');
    fireEvent.click(screen.getByTestId('close-all'));
    await releaseNextCommit();
    const content = await clickSave();
    expect(content.meta?.seo?.title).toBe('$t:custom.board_boards.1');
  });

  /**
   * 실측 재현 — 「검색 제목」 입력 후 번역 키 생성 응답(키 생성 + 사전 다시 읽기, 실측 6~7초)이 오기 전에 💾 저장.
   * 저장이 응답을 기다리지 않으면 PUT 본문에 제목이 없고 막 만든 번역 키는 저장 순간 orphaned 가 된다.
   * @effects save_waits_inflight_i18n_key_creation_before_put
   */
  it('「검색 제목」 입력 → 응답 전에 창 닫고 저장 → 응답: 저장이 기다렸다가 content.meta.seo.title 을 싣는다', async () => {
    await mountEditor();
    fireEvent.click(screen.getByTestId('open-seo'));
    await typeAndBlur('g7le-seo-title-preview', '전체 게시판 모아보기');
    fireEvent.click(screen.getByTestId('close-all'));
    await act(async () => {
      fireEvent.click(screen.getByTestId('save'));
      await new Promise((r) => setTimeout(r, 5));
    });
    // 응답이 오기 전에는 PUT 이 나가지 않는다
    expect(putBodies.length).toBe(0);
    await releaseNextCommit();
    await waitFor(() => expect(putBodies.length).toBe(1));
    expect(putBodies[0]!.meta?.seo?.title).toBe('$t:custom.board_boards.1');
  });

  /**
   * @effects save_waits_inflight_i18n_key_creation_before_put
   */
  it('[기본 정보] 페이지 이름 입력 → 응답 전에 저장 → 응답: 저장이 기다렸다가 content.meta.title 을 싣는다', async () => {
    await mountEditor();
    fireEvent.click(screen.getByTestId('open-meta'));
    await typeAndBlur('g7le-meta-title-preview', '게시판 모음');
    await act(async () => {
      fireEvent.click(screen.getByTestId('save'));
      await new Promise((r) => setTimeout(r, 5));
    });
    expect(putBodies.length).toBe(0);
    await releaseNextCommit();
    await waitFor(() => expect(putBodies.length).toBe(1));
    expect(putBodies[0]!.meta?.title).toBe('$t:custom.board_boards.1');
  });

  /**
   * @effects seo_tab_new_search_title_persists_in_original_meta_seo, page_settings_async_i18n_commit_writes_onto_latest_meta
   */
  it('「검색 제목」 → (응답 전) 「검색 설명」 → 응답 둘 → 저장: 제목·설명 둘 다 실린다', async () => {
    await mountEditor();
    fireEvent.click(screen.getByTestId('open-seo'));
    await typeAndBlur('g7le-seo-title-preview', '전체 게시판 모아보기');
    await typeAndBlur('g7le-seo-description-preview', '게시판 설명 시험');
    await releaseNextCommit();
    await releaseNextCommit();
    const content = await clickSave();
    expect(content.meta?.seo?.title).toBe('$t:custom.board_boards.1');
    expect(content.meta?.seo?.description).toBe('$t:custom.board_boards.2');
  });

  /**
   * @effects seo_tab_new_search_title_persists_in_original_meta_seo
   */
  it('「검색 제목」 → 응답 → 「검색 설명」 → 응답 → 저장: 제목·설명 둘 다 실린다', async () => {
    await mountEditor();
    fireEvent.click(screen.getByTestId('open-seo'));
    await typeAndBlur('g7le-seo-title-preview', '전체 게시판 모아보기');
    await releaseNextCommit();
    await typeAndBlur('g7le-seo-description-preview', '게시판 설명 시험');
    await releaseNextCommit();
    const content = await clickSave();
    expect(content.meta?.seo?.title).toBe('$t:custom.board_boards.1');
    expect(content.meta?.seo?.description).toBe('$t:custom.board_boards.2');
    // 베이스에서 물려받은 값은 굳지 않는다
    expect(content.meta?.seo?.data_sources).toEqual(['boardList']);
  });
});
