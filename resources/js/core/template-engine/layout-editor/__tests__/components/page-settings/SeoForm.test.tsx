// e2e:allow 페이지 설정 [검색엔진] 탭 호스트(RTL) — I18nTextField/데이터칩/봇 미리보기 합성 의존, Chrome MCP 매트릭스(세션 D) + 단위로 검증
/**
 * SeoForm.test.tsx — [검색엔진] 탭 기본 노출 RTL
 *
 * 검증:
 *  ① enabled 토글 → ②③④⑤ 항상 표시(OFF 시 회색 비활성 D-M)
 *  ② page_type select(백엔드 후보)·후보 0 시 자유텍스트 폴백·라벨에 확장명
 *  ③ extensions 칩 추가/제거 → 기본값 전제 배너 토글
 *  ④ toggle_setting 검색 드롭다운(키+lang 라벨, 비울 수 있음)
 *  ⑤ data_sources 멀티선택
 *  ⑥ priority 슬라이더 0–1 / changefreq select
 *  ⑦ 검색 제목/설명 I18nTextField(mock)
 *  ⑧ 확장 미선택∨page_type 미설정 → 기본값 없음 배너 + missing 표시
 *  ⑩ canonical/robots 컨트롤 부재
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, waitFor, act } from '@testing-library/react';

vi.mock('../../../components/property-controls/I18nTextField', () => ({
  I18nTextField: ({ value, onChange, testidPrefix }: { value: string; onChange: (v: string | undefined) => void; testidPrefix: string }) => (
    <input data-testid={`${testidPrefix}-mock`} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />
  ),
}));
// 봇 미리보기 패널은 자체 테스트 — fetch 발화 회피용 경량 stub.
vi.mock('../../../components/page-settings/SeoBotPreviewPanel', () => ({
  SeoBotPreviewPanel: () => <div data-testid="g7le-seo-bot-preview-stub" />,
}));

import { SeoForm, type SeoCandidatesResponse, type SeoOgPreviewResponse, type SeoFormProps } from '../../../components/page-settings/SeoForm';

const t = (k: string) => k;

function makeStore(initialMeta: Record<string, unknown> = {}) {
  const raw: Record<string, unknown> = { meta: initialMeta };
  const patch = vi.fn((key: string, value: unknown) => {
    if (value === undefined) delete raw[key];
    else raw[key] = value;
  });
  const getValue = <T,>(key: string, fb?: T): T => (raw[key] === undefined ? fb : raw[key]) as T;
  return { raw, patch, getValue };
}

const emptyCandidates: SeoCandidatesResponse = { page_types: [], toggle_settings: [], vars: [] };
const emptyPreview: SeoOgPreviewResponse = { defaultsAvailable: false, missing: ['extensions', 'page_type'], og: [], twitter: [], structured: {} };

/** SeoForm 렌더 후 비동기 후보/미리보기 effect 를 flush(act 경고 방지). */
async function renderForm(ui: React.ReactElement): Promise<void> {
  render(ui);
  // 주입 fetcher(Promise.all → setState 2건)를 macrotask 로 settle → act 안에서 소화.
  await act(async () => {
    await new Promise((r) => setTimeout(r, 0));
  });
}

const baseProps = {
  t,
  templateIdentifier: 'sirsoft-basic',
  candidatesFetcher: vi.fn(async () => emptyCandidates),
  ogPreviewFetcher: vi.fn(async () => emptyPreview),
};

beforeEach(() => cleanup());
afterEach(() => cleanup());

describe('SeoForm — 기본 노출', () => {
  it('① enabled OFF → 본문 숨기지 않고 회색 비활성으로 항상 표시(D-M)', async () => {
    const store = makeStore({ seo: { enabled: false } });
    await renderForm(<SeoForm getValue={store.getValue} patch={store.patch} {...baseProps} />);
    expect(screen.getByTestId('g7le-seo-enabled')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-seo-disabled-note')).toBeInTheDocument();
    // 숨김 금지 — 본문은 DOM 에 존재하되 회색 비활성(data-disabled=true).
    const body = screen.getByTestId('g7le-seo-body');
    expect(body).toHaveAttribute('data-disabled', 'true');
    expect(screen.getByTestId('g7le-seo-priority')).toBeInTheDocument();
  });

  it('① enabled 토글 → seo.enabled 패치', async () => {
    const store = makeStore({ seo: { enabled: false } });
    await renderForm(<SeoForm getValue={store.getValue} patch={store.patch} {...baseProps} />);
    fireEvent.click(screen.getByTestId('g7le-seo-enabled'));
    expect(store.patch).toHaveBeenLastCalledWith('meta', { seo: { enabled: true } });
  });

  it('⑧ 확장 미선택∨page_type 미설정 → 기본값 전제 배너 + missing 표시', async () => {
    const store = makeStore({ seo: { enabled: true } });
    await renderForm(<SeoForm getValue={store.getValue} patch={store.patch} {...baseProps} />);
    expect(screen.getByTestId('g7le-seo-defaults-precondition')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-seo-defaults-missing')).toBeInTheDocument();
  });

  it('② page_type 후보 0 → 자유 텍스트 폴백', async () => {
    const store = makeStore({ seo: { enabled: true } });
    await renderForm(<SeoForm getValue={store.getValue} patch={store.patch} {...baseProps} />);
    await waitFor(() => expect(screen.getByTestId('g7le-seo-page-type-free')).toBeInTheDocument());
    fireEvent.change(screen.getByTestId('g7le-seo-page-type-free'), { target: { value: 'recipe' } });
    expect(store.patch).toHaveBeenLastCalledWith('meta', { seo: { enabled: true, page_type: 'recipe' } });
  });

  it('② page_type 후보 있으면 select + 라벨에 확장명', async () => {
    const store = makeStore({ seo: { enabled: true } });
    const candidatesFetcher = vi.fn(async (): Promise<SeoCandidatesResponse> => ({
      page_types: [{ value: 'product', label: '상품', owner: { name: '이커머스' } }],
      toggle_settings: [],
      vars: [],
    }));
    await renderForm(<SeoForm getValue={store.getValue} patch={store.patch} {...baseProps} candidatesFetcher={candidatesFetcher} />);
    await waitFor(() => expect(screen.getByTestId('g7le-seo-page-type')).toBeInTheDocument());
    expect(screen.getByText('상품 (이커머스)')).toBeInTheDocument();
  });

  it('③ extensions 칩 추가 → seo.extensions 패치', async () => {
    const store = makeStore({ seo: { enabled: true } });
    const availableExtensions = [{ type: 'module' as const, id: 'sirsoft-ecommerce', name: '이커머스' }];
    await renderForm(<SeoForm getValue={store.getValue} patch={store.patch} {...baseProps} availableExtensions={availableExtensions} />);
    fireEvent.click(screen.getByTestId('g7le-seo-extension-add'));
    fireEvent.click(screen.getByTestId('g7le-seo-extension-option-module-sirsoft-ecommerce'));
    expect(store.patch).toHaveBeenLastCalledWith('meta', {
      seo: { enabled: true, extensions: [{ type: 'module', id: 'sirsoft-ecommerce' }] },
    });
  });

  it('④ toggle_setting 검색 드롭다운 → 선택 시 ref 패치', async () => {
    const store = makeStore({ seo: { enabled: true } });
    const candidatesFetcher = vi.fn(async (): Promise<SeoCandidatesResponse> => ({
      page_types: [],
      toggle_settings: [{ ref: '$module_settings:sirsoft-ecommerce:seo.seo_product_detail', label: '이커머스: 상품 상세 SEO' }],
      vars: [],
    }));
    await renderForm(<SeoForm getValue={store.getValue} patch={store.patch} {...baseProps} candidatesFetcher={candidatesFetcher} />);
    await waitFor(() => expect(screen.getByTestId('g7le-seo-toggle-setting-search')).toBeInTheDocument());
    fireEvent.change(screen.getByTestId('g7le-seo-toggle-setting-search'), { target: { value: '상품' } });
    fireEvent.click(screen.getByTestId('g7le-seo-toggle-setting-option-$module_settings:sirsoft-ecommerce:seo.seo_product_detail'));
    expect(store.patch).toHaveBeenLastCalledWith('meta', {
      seo: { enabled: true, toggle_setting: '$module_settings:sirsoft-ecommerce:seo.seo_product_detail' },
    });
  });

  it('⑤ data_sources 멀티선택 → seo.data_sources 패치', async () => {
    const store = makeStore({ seo: { enabled: true } });
    await renderForm(
      <SeoForm
        getValue={store.getValue}
        patch={store.patch}
        {...baseProps}
        dataSourceOptions={[
          { id: 'product', friendly: '상품', source: null },
          { id: 'reviews', friendly: null, source: '플러그인: 리뷰 (sirsoft-review)' },
        ]}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-seo-data-source-check-product'));
    expect(store.patch).toHaveBeenLastCalledWith('meta', { seo: { enabled: true, data_sources: ['product'] } });
  });

  it('⑤-b data_sources 칩이 친화명·보조 id·확장 출처 배지를 노출', async () => {
    const store = makeStore({ seo: { enabled: true } });
    await renderForm(
      <SeoForm
        getValue={store.getValue}
        patch={store.patch}
        {...baseProps}
        dataSourceOptions={[
          { id: 'product', friendly: '상품', source: null },
          { id: 'reviews', friendly: null, source: '플러그인: 리뷰 (sirsoft-review)' },
        ]}
      />,
    );
    // 친화명 있으면 제목=친화명 + 보조 id 동반.
    expect(screen.getByTestId('g7le-seo-data-source-product-title').textContent).toBe('상품');
    expect(screen.getByTestId('g7le-seo-data-source-product-id').textContent).toBe('product');
    // 친화명 없으면 제목=id, 보조 id 미노출.
    expect(screen.getByTestId('g7le-seo-data-source-reviews-title').textContent).toBe('reviews');
    expect(screen.queryByTestId('g7le-seo-data-source-reviews-id')).not.toBeInTheDocument();
    // 확장 출처 배지.
    expect(screen.getByTestId('g7le-seo-data-source-reviews-source').textContent).toBe('플러그인: 리뷰 (sirsoft-review)');
    expect(screen.queryByTestId('g7le-seo-data-source-product-source')).not.toBeInTheDocument();
  });

  it('⑥ priority 슬라이더 + changefreq select 패치', async () => {
    const store = makeStore({ seo: { enabled: true } });
    await renderForm(<SeoForm getValue={store.getValue} patch={store.patch} {...baseProps} />);
    fireEvent.change(screen.getByTestId('g7le-seo-priority'), { target: { value: '0.8' } });
    expect(store.patch).toHaveBeenLastCalledWith('meta', { seo: { enabled: true, priority: 0.8 } });
    fireEvent.change(screen.getByTestId('g7le-seo-changefreq'), { target: { value: 'daily' } });
    expect(store.patch).toHaveBeenLastCalledWith('meta', expect.objectContaining({ seo: expect.objectContaining({ changefreq: 'daily' }) }));
  });

  it('⑦ 검색 제목 I18nTextField → seo.title 패치', async () => {
    const store = makeStore({ seo: { enabled: true } });
    await renderForm(<SeoForm getValue={store.getValue} patch={store.patch} {...baseProps} />);
    fireEvent.change(screen.getByTestId('g7le-seo-title-mock'), { target: { value: '추천 상품' } });
    expect(store.patch).toHaveBeenLastCalledWith('meta', { seo: { enabled: true, title: '추천 상품' } });
  });

  it('⑩ canonical/robots 컨트롤 부재', async () => {
    const store = makeStore({ seo: { enabled: true } });
    await renderForm(<SeoForm getValue={store.getValue} patch={store.patch} {...baseProps} />);
    expect(screen.queryByTestId('g7le-seo-canonical')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-seo-robots')).not.toBeInTheDocument();
  });

  // SEO-A — og/twitter 값 변경 시 미리보기(출처 cascade) 재계산.
  // 종전엔 useEffect dep 이 [pageType, extensions] 뿐이라 og.type 을 비우거나 채워도
  // seo-og-preview 가 재호출되지 않아 "어느 출처로 돌아가는지"(코어/모듈/상속)가 stale.
  // patch 가 실제 재렌더를 일으켜야 seoVals 가 갱신되므로(운영 usePageSettings 정합)
  // stateful 호스트로 감싼다(plain mutation store 는 재렌더 미발생).
  it('SEO-A: og 값 변경(type) → 미리보기 재계산(ogPreviewFetcher 재호출)', async () => {
    const ogPreviewFetcher = vi.fn(async (): Promise<SeoOgPreviewResponse> => ({
      defaultsAvailable: true,
      missing: [],
      og: [{ key: 'type', effectiveValue: 'product', source: 'layout', overriddenByLayout: true, lockedByFilter: false }],
      twitter: [],
      structured: {},
    }));
    await renderForm(
      <StatefulSeoForm
        initialMeta={{ seo: { enabled: true, page_type: 'product', extensions: [{ type: 'module', id: 'sirsoft-ecommerce' }], og: { type: 'product' } } }}
        ogPreviewFetcher={ogPreviewFetcher}
      />,
    );
    const initialCalls = ogPreviewFetcher.mock.calls.length;
    expect(initialCalls).toBeGreaterThanOrEqual(1); // 진입 시 1회

    // og.type 을 비움 → 미리보기가 type 출처를 layout→module/core 로 재계산해야 함.
    await act(async () => {
      fireEvent.change(screen.getByTestId('g7le-seo-og-type-select'), { target: { value: '' } });
      await new Promise((r) => setTimeout(r, 250)); // 디바운스 settle
    });

    expect(ogPreviewFetcher.mock.calls.length).toBeGreaterThan(initialCalls);
  });

  //  vars 근본원인 A — candidates fetch 가 extensions 를 query 로 전송해야 백엔드가
  // vars 후보를 게이팅 통과시킨다. 종전엔 ?page_type= 만 보내 declaredExtensions=[] →
  // vars:[] → 자동/data 그룹이 통째로 사라지고 레이아웃 vars 가 직접추가로 강등됐다.
  it(' vars-A: 내부 candidates fetch URL 에 page_type + extensions 둘 다 포함', async () => {
    const calls: string[] = [];
    const realFetch = globalThis.fetch;
    globalThis.fetch = vi.fn(async (url: RequestInfo | URL) => {
      const u = String(url);
      calls.push(u);
      const data = u.includes('seo-candidates')
        ? { page_types: [], toggle_settings: [], vars: [] }
        : { defaultsAvailable: false, missing: [], og: [], twitter: [], structured: {} };
      return { ok: true, json: async () => ({ data }) } as Response;
    }) as typeof fetch;
    try {
      const store = makeStore({
        seo: { enabled: true, page_type: 'product', extensions: [{ type: 'module', id: 'sirsoft-ecommerce' }] },
      });
      // candidatesFetcher 주입 없이 → 내부 fetch 경로 사용.
      await renderForm(
        <SeoForm getValue={store.getValue} patch={store.patch} t={t} templateIdentifier="sirsoft-basic" />,
      );
      const candUrl = calls.find((u) => u.includes('seo-candidates'));
      expect(candUrl).toBeDefined();
      expect(candUrl).toContain('page_type=product');
      expect(candUrl).toContain('extensions=');
      expect(decodeURIComponent(candUrl!)).toContain('sirsoft-ecommerce');
    } finally {
      globalThis.fetch = realFetch;
    }
  });

  it('SEO-A: twitter 값 변경(card) → 미리보기 재계산', async () => {
    const ogPreviewFetcher = vi.fn(async (): Promise<SeoOgPreviewResponse> => ({
      defaultsAvailable: true,
      missing: [],
      og: [],
      twitter: [{ key: 'card', effectiveValue: 'summary', source: 'core', overriddenByLayout: false, lockedByFilter: false }],
      structured: {},
    }));
    await renderForm(
      <StatefulSeoForm
        initialMeta={{ seo: { enabled: true, page_type: 'product', extensions: [{ type: 'module', id: 'sirsoft-ecommerce' }] } }}
        ogPreviewFetcher={ogPreviewFetcher}
      />,
    );
    const initialCalls = ogPreviewFetcher.mock.calls.length;
    await act(async () => {
      fireEvent.change(screen.getByTestId('g7le-seo-tw-card-select'), { target: { value: 'summary' } });
      await new Promise((r) => setTimeout(r, 250));
    });
    expect(ogPreviewFetcher.mock.calls.length).toBeGreaterThan(initialCalls);
  });
});

/** patch → setState 로 재렌더 시키는 stateful 호스트(운영 usePageSettings 정합). */
function StatefulSeoForm({
  initialMeta,
  ogPreviewFetcher,
}: {
  initialMeta: Record<string, unknown>;
  ogPreviewFetcher: SeoFormProps['ogPreviewFetcher'];
}): React.ReactElement {
  const [raw, setRaw] = React.useState<Record<string, unknown>>({ meta: initialMeta });
  const getValue = <T,>(key: string, fb?: T): T => (raw[key] === undefined ? fb : raw[key]) as T;
  const patch = (key: string, value: unknown): void => {
    setRaw((prev) => {
      const next = { ...prev };
      if (value === undefined) delete next[key];
      else next[key] = value;
      return next;
    });
  };
  return (
    <SeoForm
      getValue={getValue}
      patch={patch}
      t={t}
      templateIdentifier="sirsoft-basic"
      candidatesFetcher={async () => emptyCandidates}
      ogPreviewFetcher={ogPreviewFetcher}
    />
  );
}
