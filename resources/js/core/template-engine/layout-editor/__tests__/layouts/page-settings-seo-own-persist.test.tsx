// e2e:allow 페이지 설정 [기본 정보]·[검색엔진] 입력 → 저장 영속값(original.meta) 라운드트립 단위 — 실 저장 PUT 본문은 브라우저 실측으로 보강.
/**
 * page-settings-seo-own-persist.test.tsx — 페이지 설정에 새로 적은 SEO·메타 값이 저장 영속값에 남는지
 *
 * 실제 셸(PageSettingsModal) → 실제 폼(MetaForm·SeoForm·SeoOgForm) → 실제 I18nTextField(평문 입력 → blur →
 * 번역 키 생성 → `$t:custom.*` 토큰) 경로와 실제 usePageSettings 를 그대로 밟고, 문서는 patchDocumentRaw 와 같은 규칙
 * (함수형 갱신 — raw[key] + __editor.original[key])으로 갱신되며 갱신마다 다시 그려진다. 저장 PUT 본문은 `__editor.original` 을 골격으로 만들어지므로
 * (stripInheritedFromLayoutContent) 여기 남은 값이 곧 저장되는 값이다.
 *
 * 함께 잠그는 것: 베이스에서 물려받은 SEO 는 이 화면 값으로 굳지 않는다(engine-v1.85.0 의 원래 목적).
 */

import React, { useCallback, useMemo, useState } from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, act } from '@testing-library/react';
import { LayoutDocumentProvider } from '../../LayoutDocumentContext';
import type { UseLayoutDocumentResult } from '../../hooks/useLayoutDocument';

// ── 라이브 문서 — 실제 usePageSettings 가 읽는 LayoutDocumentProvider 에 상태형 문서를 내린다.
//    patchDocumentRaw 는 실제(useLayoutDocument)와 같은 규칙: 함수형 갱신으로 raw[key] = value,
//    __editor.original[key] = 영속값(3번째 인자, 없으면 value). 시험은 liveRaw 로 최신 문서를 읽는다.
let liveRaw: Record<string, unknown> = {};
const patchCalls: Array<{ key: string; value: unknown; own?: unknown; hasOwn: boolean }> = [];
function applyRawPatch(prev: Record<string, unknown>, key: string, value: unknown, persist: unknown): Record<string, unknown> {
  const next: Record<string, unknown> = { ...prev, [key]: value };
  const editor = prev.__editor as { original?: Record<string, unknown> } | undefined;
  if (editor && editor.original && typeof editor.original === 'object') {
    next.__editor = { ...editor, original: { ...editor.original, [key]: persist } };
  }
  return next;
}
function DocHost({ children }: { children: React.ReactNode }): React.ReactElement {
  const [raw, setRaw] = useState<Record<string, unknown>>(() => liveRaw);
  const patchDocumentRaw = useCallback((key: string, value: unknown, ...rest: [unknown?]): void => {
    const hasOwn = rest.length >= 1;
    patchCalls.push({ key, value, own: rest[0], hasOwn });
    setRaw((prev) => {
      const next = applyRawPatch(prev, key, value, hasOwn ? rest[0] : value);
      liveRaw = next;
      return next;
    });
  }, []);
  const value = useMemo(
    () => ({ document: { raw }, patchDocumentRaw }) as unknown as UseLayoutDocumentResult,
    [raw, patchDocumentRaw],
  );
  return <LayoutDocumentProvider value={value}>{children}</LayoutDocumentProvider>;
}

vi.mock('../../LayoutEditorContext', () => ({
  useLayoutEditor: () => ({
    state: {
      selectedRoute: { path: '/boards', layoutName: 'board/boards' },
      routeTree: [],
      templateIdentifier: 'sirsoft-basic',
      locale: 'ko',
      editMode: 'route',
    },
    dispatch: vi.fn(),
  }),
}));
vi.mock('../../hooks/useBindingCandidates', () => ({
  useBindingCandidates: () => [],
  buildPageSampleContext: () => ({}),
}));
vi.mock('../../hooks/useSeoBindingCandidates', () => ({ useSeoBindingCandidates: () => [] }));

// ── 번역 키 자동 변환 — 평문 blur 시 서버가 만든 `custom.board_boards.N` 키를 돌려준 것처럼 ──
const createdValues = new Map<string, string>();
let keySeq = 0;
// holdCommits 이면 키 생성 응답을 붙잡아 두었다가 releaseNextCommit() 으로 하나씩 돌려준다(느린 서버 왕복 재현)
let holdCommits = false;
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
        // 서버 왕복(키 생성 + 사전 다시 읽기)만큼 한 박자 늦게 돌아온다
        if (holdCommits) await new Promise<void>((r) => heldCommits.push(r));
        else await new Promise((r) => setTimeout(r, 0));
        return { kind: 'created' as const, customKey: key, token: `$t:${key}` };
      },
    }),
  };
});

// 봇 미리보기는 자체 시험이 있다 — 네트워크 회피용 stub
vi.mock('../../components/page-settings/SeoBotPreviewPanel', () => ({
  SeoBotPreviewPanel: () => <div data-testid="g7le-seo-bot-preview-stub" />,
}));

import { PageSettingsModal } from '../../components/page-settings/PageSettingsModal';

const t = (k: string) => k;
const resolveLabel = (k: string) => k;

/** 베이스 `_user_base` 의 meta (번들 원본과 같은 모양) */
const BASE_META = {
  title: '$t:user.base_layout_title',
  description: '$t:user.base_layout_description',
  seo: {
    enabled: false,
    data_sources: ['boards'],
    vars: { site_name: '$core_settings:general.site_name' },
    og: { type: 'website' },
  },
};
/** 화면 `board/boards` 가 직접 적은 meta (번들 원본과 같은 모양 — seo.title·description 없음) */
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
/** 서버 병합본(LayoutService: meta 얕은 병합 + seo 깊은 병합·data_sources 합집합) */
const MERGED_META = {
  title: BASE_META.title,
  description: BASE_META.description,
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

function renderShell(initialTab: 'meta' | 'seo') {
  return render(
    <DocHost>
      <PageSettingsModal
        templateIdentifier="sirsoft-basic"
        spec={null}
        t={t}
        resolveLabel={resolveLabel}
        onClose={vi.fn()}
        initialTab={initialTab}
        extensionsFetcher={async () => []}
        templateConfigFetcher={async () => undefined}
      />
    </DocHost>,
  );
}

/** 칸에 평문을 입력하고 Tab 으로 빠져나간다(blur → 번역 키 생성 → 토큰 기록) */
async function typeAndBlur(testid: string, value: string): Promise<void> {
  const input = screen.getByTestId(testid) as HTMLInputElement;
  fireEvent.focus(input);
  fireEvent.change(input, { target: { value } });
  await act(async () => {
    fireEvent.blur(input);
    await new Promise((r) => setTimeout(r, 5));
  });
}

/** 칸에 평문을 입력하고 Tab 으로 빠져나가되, 번역 키 생성 응답은 아직 오지 않은 상태로 둔다 */
async function typeAndBlurHeld(testid: string, value: string): Promise<void> {
  const input = screen.getByTestId(testid) as HTMLInputElement;
  fireEvent.focus(input);
  fireEvent.change(input, { target: { value } });
  await act(async () => {
    fireEvent.blur(input);
    await Promise.resolve();
  });
}

/** 붙잡아 둔 키 생성 응답 하나를 돌려주고 그 뒤 기록·다시 그리기를 끝까지 흘린다 */
async function releaseNextCommit(): Promise<void> {
  const release = heldCommits.shift();
  if (!release) throw new Error('붙잡힌 키 생성 응답이 없다');
  await act(async () => {
    release();
    await new Promise((r) => setTimeout(r, 5));
  });
}

function ownMeta(): Record<string, any> {
  return ((liveRaw.__editor as any)?.original?.meta ?? {}) as Record<string, any>;
}

beforeEach(() => {
  cleanup();
  patchCalls.length = 0;
  createdValues.clear();
  keySeq = 0;
  holdCommits = false;
  heldCommits.length = 0;
  // SeoForm 의 후보·미리보기 조회 — 빈 응답
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => ({ ok: true, json: async () => ({ data: null }) })),
  );
});
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('페이지 설정 — 새로 적은 값이 저장 영속값(original.meta)에 남는다', () => {
  describe('화면 레이아웃(베이스에 seo.title·description 없음)', () => {
    beforeEach(() => {
      liveRaw = {
        meta: structuredClone(MERGED_META),
        __editor: { original: { extends: '_user_base', meta: structuredClone(OWN_META) } },
      };
    });

    /**
     * @effects seo_tab_new_search_title_persists_in_original_meta_seo
     */
    it('[검색엔진] 「검색 제목」만 입력 → original.meta.seo.title 이 번역 키 토큰으로 남는다', async () => {
      renderShell('seo');
      await typeAndBlur('g7le-seo-title-preview', '전체 게시판 모아보기');
      expect((liveRaw.meta as any).seo.title).toBe('$t:custom.board_boards.1');
      expect(ownMeta().seo?.title).toBe('$t:custom.board_boards.1');
    });

    /**
     * @effects seo_tab_new_search_title_persists_in_original_meta_seo
     */
    it('[검색엔진] 「검색 제목」→「검색 설명」 연속 입력 → 둘 다 남는다', async () => {
      renderShell('seo');
      await typeAndBlur('g7le-seo-title-preview', '전체 게시판 모아보기');
      await typeAndBlur('g7le-seo-description-preview', '게시판 설명 시험');
      expect(ownMeta().seo?.title).toBe('$t:custom.board_boards.1');
      expect(ownMeta().seo?.description).toBe('$t:custom.board_boards.2');
    });

    /**
     * @effects seo_tab_new_search_title_persists_in_original_meta_seo
     */
    it('[검색엔진] 「검색 설명」만 입력 → original.meta.seo.description 이 남는다', async () => {
      renderShell('seo');
      await typeAndBlur('g7le-seo-description-preview', '게시판 설명 시험');
      expect(ownMeta().seo?.description).toBe('$t:custom.board_boards.1');
    });

    /**
     * 실측 재현 — 「검색 제목」 입력 후 Tab, 그 키 생성 응답이 오기 전에 「검색 설명」을 입력하고 Tab.
     * 설명 칸이 기록할 때 쓰는 meta 가 제목 기록 이전 것이면 제목이 지워진다.
     * @effects seo_tab_new_search_title_persists_in_original_meta_seo, page_settings_async_i18n_commit_writes_onto_latest_meta
     */
    it('[검색엔진] 제목 키 생성 응답이 오기 전에 설명을 입력해도 제목·설명 둘 다 남는다', async () => {
      holdCommits = true;
      renderShell('seo');
      await typeAndBlurHeld('g7le-seo-title-preview', '전체 게시판 모아보기');
      await typeAndBlurHeld('g7le-seo-description-preview', '게시판 설명 시험');
      await releaseNextCommit(); // 제목 토큰 기록
      expect(ownMeta().seo?.title).toBe('$t:custom.board_boards.1');
      await releaseNextCommit(); // 설명 토큰 기록
      expect(ownMeta().seo?.description).toBe('$t:custom.board_boards.2');
      expect(ownMeta().seo?.title).toBe('$t:custom.board_boards.1');
      expect((liveRaw.meta as any).seo.title).toBe('$t:custom.board_boards.1');
    });

    /**
     * @effects page_settings_async_i18n_commit_writes_onto_latest_meta
     */
    it('[검색엔진] 설명 응답이 먼저 와도(순서 뒤바뀜) 제목·설명 둘 다 남는다', async () => {
      holdCommits = true;
      renderShell('seo');
      await typeAndBlurHeld('g7le-seo-title-preview', '전체 게시판 모아보기');
      await typeAndBlurHeld('g7le-seo-description-preview', '게시판 설명 시험');
      // 두 번째(설명) 응답을 먼저 돌려준다
      const [first, second] = heldCommits.splice(0, 2);
      heldCommits.push(second!, first!);
      await releaseNextCommit();
      await releaseNextCommit();
      expect(ownMeta().seo?.title).toBe('$t:custom.board_boards.1');
      expect(ownMeta().seo?.description).toBe('$t:custom.board_boards.2');
    });

    /**
     * @effects page_settings_async_i18n_commit_writes_onto_latest_meta
     */
    it('[기본 정보] 페이지 이름 응답이 오기 전에 설명을 입력해도 둘 다 남는다', async () => {
      holdCommits = true;
      renderShell('meta');
      await typeAndBlurHeld('g7le-meta-title-preview', '게시판 모음');
      await typeAndBlurHeld('g7le-meta-description-preview', '게시판 모음 설명');
      await releaseNextCommit();
      await releaseNextCommit();
      expect(ownMeta().title).toBe('$t:custom.board_boards.1');
      expect(ownMeta().description).toBe('$t:custom.board_boards.2');
    });

    /**
     * @effects page_settings_async_i18n_commit_writes_onto_latest_meta
     */
    it('[검색엔진] OG 제목 응답이 오기 전에 OG 설명을 입력해도 둘 다 남는다', async () => {
      holdCommits = true;
      renderShell('seo');
      await typeAndBlurHeld('g7le-seo-og-title-field-preview', 'OG 제목');
      await typeAndBlurHeld('g7le-seo-og-description-field-preview', 'OG 설명');
      await releaseNextCommit();
      await releaseNextCommit();
      expect(ownMeta().seo?.og?.title).toBe('$t:custom.board_boards.1');
      expect(ownMeta().seo?.og?.description).toBe('$t:custom.board_boards.2');
    });

    /**
     * @effects page_settings_async_i18n_commit_writes_onto_latest_meta
     */
    it('[검색엔진] Twitter 제목 응답이 오기 전에 Twitter 설명을 입력해도 둘 다 남는다', async () => {
      holdCommits = true;
      renderShell('seo');
      await typeAndBlurHeld('g7le-seo-tw-title-field-preview', 'TW 제목');
      await typeAndBlurHeld('g7le-seo-tw-description-field-preview', 'TW 설명');
      await releaseNextCommit();
      await releaseNextCommit();
      expect(ownMeta().seo?.twitter?.title).toBe('$t:custom.board_boards.1');
      expect(ownMeta().seo?.twitter?.description).toBe('$t:custom.board_boards.2');
    });

    /**
     * @effects seo_tab_new_og_twitter_text_persists_in_original_meta_seo
     */
    it('[검색엔진] OG 제목·설명, Twitter 제목·설명 입력 → original.meta.seo.og/twitter 에 남는다', async () => {
      renderShell('seo');
      await typeAndBlur('g7le-seo-og-title-field-preview', 'OG 제목');
      await typeAndBlur('g7le-seo-og-description-field-preview', 'OG 설명');
      await typeAndBlur('g7le-seo-tw-title-field-preview', 'TW 제목');
      await typeAndBlur('g7le-seo-tw-description-field-preview', 'TW 설명');
      const seo = ownMeta().seo ?? {};
      expect(seo.og?.title).toBe('$t:custom.board_boards.1');
      expect(seo.og?.description).toBe('$t:custom.board_boards.2');
      expect(seo.twitter?.title).toBe('$t:custom.board_boards.3');
      expect(seo.twitter?.description).toBe('$t:custom.board_boards.4');
    });

    /**
     * @effects meta_tab_new_page_title_description_persists_in_original_meta
     */
    it('[기본 정보] 페이지 이름·설명 입력 → original.meta.title·description 에 남는다', async () => {
      renderShell('meta');
      await typeAndBlur('g7le-meta-title-preview', '게시판 모음');
      await typeAndBlur('g7le-meta-description-preview', '게시판 모음 설명');
      expect(ownMeta().title).toBe('$t:custom.board_boards.1');
      expect(ownMeta().description).toBe('$t:custom.board_boards.2');
    });

    /**
     * @effects shell_meta_patch_keeps_only_own_or_changed_seo_in_original
     */
    it('새 값을 적어도 베이스에서 물려받은 SEO(enabled=false 의 반대값 아닌 키·data_sources boards)는 굳지 않는다', async () => {
      renderShell('seo');
      await typeAndBlur('g7le-seo-title-preview', '전체 게시판 모아보기');
      const seo = ownMeta().seo ?? {};
      // 베이스 전용 data_sources 'boards' 는 이 화면 값으로 들어가지 않는다
      expect(seo.data_sources).toEqual(['boardList']);
      // 이 화면이 원래 적은 값은 그대로
      expect(seo.page_type).toBe('boards');
      expect(seo.enabled).toBe(true);
    });
  });

  describe('베이스 레이아웃 자신(_user_base)', () => {
    beforeEach(() => {
      liveRaw = {
        meta: structuredClone(BASE_META),
        __editor: { original: { meta: structuredClone(BASE_META) } },
      };
    });

    /**
     * @effects seo_tab_new_search_title_persists_in_original_meta_seo
     */
    it('[검색엔진] 「검색 제목」·「검색 설명」 입력 → original.meta.seo 에 둘 다 남는다', async () => {
      renderShell('seo');
      await typeAndBlur('g7le-seo-title-preview', '사이트 제목');
      await typeAndBlur('g7le-seo-description-preview', '사이트 설명');
      expect(ownMeta().seo?.title).toBe('$t:custom.board_boards.1');
      expect(ownMeta().seo?.description).toBe('$t:custom.board_boards.2');
    });
  });
});
