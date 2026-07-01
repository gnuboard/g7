// e2e:allow 페이지 설정 모달 영역([검색엔진] 탭 호스트) — 모달/포털·서버 미리보기 fetch 의존으로 Playwright 부적합. 단위 RTL(SeoForm.test) + Chrome MCP 매트릭스(tests/scenarios/page-settings.yaml audit:allow)가 SSoT. 묶음③ autoMeta 결선 동일 정책.
/**
 * SeoForm.tsx — [검색엔진] 독립 탭 호스트
 *
 * SSoT 정합: ① 기본 노출(enabled/page_type/extensions cascade/toggle_setting/data_sources/
 * priority/changefreq/title/description) → ② 소셜 공유(SeoOgForm) → ③ 구조화 데이터
 * (SeoStructuredDataEditor) → ④ 동적 변수(SeoVarsEditor) → ⑤ 봇 미리보기(SeoBotPreviewPanel).
 * `seo.enabled` 끔이면 ②③④⑤ 접힘.
 *
 * 편집 대상 = `meta.seo` 서브트리(도메인 스키마 ownership 준수). page_type/toggle_setting/vars
 * 후보는 **백엔드 가드 엔드포인트**(seo-candidates.json), og/twitter/structured 기본값·출처·
 * 잠김은 **서버 미리보기**(seo-og-preview). 둘 다 extensions 또는 page_type 변경 시 재호출
 * (이전 기본값 무효화). 후보는 fetch 주입(테스트) 또는 내부 Bearer fetch.
 *
 * 본 컴포넌트는 셸(PageSettingsModal — 세션 D)이 `getValue`/`patch`(usePageSettings) 와
 * `candidates`(useSeoBindingCandidates) 를 흘려보내 마운트한다. 독립 RTL 검증 가능.
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(라이브러리 중립).
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { buildAuthHeaders } from '../../utils/authToken';
import { ToggleSwitch, DisabledFieldset } from './FormPrimitives';
import { I18nTextField } from '../property-controls/I18nTextField';
import { SeoOgForm, type SeoPreviewRow } from './SeoOgForm';
import { SeoStructuredDataEditor, type StructuredDataBlock } from './SeoStructuredDataEditor';
import { SeoVarsEditor, type SeoVarCandidate } from './SeoVarsEditor';
import { SeoBotPreviewPanel } from './SeoBotPreviewPanel';
import type { BindingCandidate } from '../../spec/bindingCandidates';
import type { DataSourceOption } from '../../spec/candidatePools';
import { DataSourceChipLabel } from './DataSourceChipLabel';

/** seo.extensions 칩 — `{type,id}` */
export interface SeoExtensionRef {
  type: 'module' | 'plugin' | string;
  id: string;
  name?: string;
}

/** page_type 후보(백엔드) */
export interface SeoPageTypeCandidate {
  value: string;
  label?: string;
  owner?: { type?: string; id?: string; name?: string };
}

/** toggle_setting 후보(백엔드) */
export interface SeoToggleCandidate {
  ref: string;
  label?: string;
  owner?: { type?: string; id?: string; name?: string };
}

/** seo-candidates.json data shape */
export interface SeoCandidatesResponse {
  identifier?: string;
  page_types: SeoPageTypeCandidate[];
  toggle_settings: SeoToggleCandidate[];
  vars: SeoVarCandidate[];
}

/** seo-og-preview data shape */
export interface SeoOgPreviewResponse {
  defaultsAvailable: boolean;
  missing: string[];
  og: SeoPreviewRow[];
  twitter: SeoPreviewRow[];
  structured: {
    autoBlock?: StructuredDataBlock | null;
    /** 자동 블록 점 경로 키별 데이터 경로 메타(연결 칩) */
    autoMeta?: Record<string, { expr: string; label: string }>;
    hasLayoutBlock?: boolean;
    lockedByFilter?: boolean;
    filteredBlock?: StructuredDataBlock | null;
  };
}

const CHANGEFREQ_OPTIONS = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];

export interface SeoFormProps {
  /** 최상위 키 읽기(usePageSettings.getValue) */
  getValue: <T = unknown>(key: string, fallback?: T) => T;
  /** 최상위 키 패치(usePageSettings.patch) */
  patch: (key: string, value: unknown, originalValue?: unknown) => void;
  /** 다국어 해석 t */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 편집 대상 템플릿 식별자(미리보기 fetch) */
  templateIdentifier: string;
  /** 데이터칩 후보(useSeoBindingCandidates) */
  candidates?: BindingCandidate[];
  /** 선택 가능 확장 후보(`{type,id,name}[]`) — extensions 칩 추가용 */
  availableExtensions?: SeoExtensionRef[];
  /** 현재 레이아웃 data_sources 멀티선택 옵션 — id + 친화명 + 확장 출처 배지 */
  dataSourceOptions?: DataSourceOption[];
  /** 봇 미리보기 url/locale/seedContext */
  previewUrl?: string;
  previewLocale?: string;
  seedContext?: Record<string, unknown>;
  /** 후보 fetch 주입(테스트). 미전달 시 내부 Bearer fetch */
  candidatesFetcher?: (pageType: string, extensions: SeoExtensionRef[]) => Promise<SeoCandidatesResponse | null>;
  /** og 미리보기 fetch 주입(테스트) */
  ogPreviewFetcher?: (seo: Record<string, unknown>) => Promise<SeoOgPreviewResponse | null>;
  /** 봇 미리보기 fetch 주입(테스트) */
  botFetchImpl?: typeof fetch;
  /** data-testid 접두 */
  testidPrefix?: string;
}

/**
 * [검색엔진] 탭 호스트.
 *
 * @param props SeoFormProps
 * @return 검색엔진 탭 엘리먼트
 */
export function SeoForm({
  getValue,
  patch,
  t,
  templateIdentifier,
  candidates,
  availableExtensions = [],
  dataSourceOptions = [],
  previewUrl = '/',
  previewLocale = 'ko',
  seedContext,
  candidatesFetcher,
  ogPreviewFetcher,
  botFetchImpl,
  testidPrefix = 'g7le-seo',
}: SeoFormProps): React.ReactElement {
  const seo = (getValue('meta', {}) as Record<string, unknown>).seo as Record<string, unknown> | undefined;
  const seoVals = (seo && typeof seo === 'object' ? seo : {}) as Record<string, unknown>;

  // meta.seo 서브트리 1건 패치(meta 최상위 키 라운드트립 유지).
  const patchSeo = useCallback(
    (key: string, value: unknown): void => {
      const meta = { ...(getValue('meta', {}) as Record<string, unknown>) };
      const nextSeo = { ...((meta.seo as Record<string, unknown>) ?? {}) };
      if (value === undefined || value === null || value === '') delete nextSeo[key];
      else nextSeo[key] = value;
      meta.seo = nextSeo;
      patch('meta', meta);
    },
    [getValue, patch],
  );

  const enabled = seoVals.enabled === true;
  const pageType = typeof seoVals.page_type === 'string' ? seoVals.page_type : '';
  const extensions = Array.isArray(seoVals.extensions) ? (seoVals.extensions as SeoExtensionRef[]) : [];
  const selectedDataSources = Array.isArray(seoVals.data_sources) ? (seoVals.data_sources as string[]) : [];
  const gatingMet = extensions.length > 0 && pageType !== '';

  // ── 후보/미리보기 상태 ──
  const [candidatesData, setCandidatesData] = useState<SeoCandidatesResponse | null>(null);
  const [ogPreview, setOgPreview] = useState<SeoOgPreviewResponse | null>(null);

  // 내부 Bearer fetch — 주입 미전달 시.
  const fetchCandidates = useCallback(
    async (pt: string, exts: SeoExtensionRef[]): Promise<SeoCandidatesResponse | null> => {
      if (candidatesFetcher) return candidatesFetcher(pt, exts);
      if (typeof fetch === 'undefined') return null;
      // page_type + extensions 둘 다 query 로 전송한다. 백엔드(SeoCandidateController)는
      // declaredExtensions 로 vars 후보를 게이팅하므로(extensions ∧ page_type), extensions 를
      // 빼면 vars 가 항상 빈 배열로 와 자동/data 그룹이 통째로 사라진다.
      const params = new URLSearchParams();
      if (pt) params.set('page_type', pt);
      if (Array.isArray(exts) && exts.length > 0) {
        params.set('extensions', JSON.stringify(exts.map((e) => ({ type: e.type, id: e.id }))));
      }
      const qs = params.toString() ? `?${params.toString()}` : '';
      const res = await fetch(
        `/api/admin/templates/${encodeURIComponent(templateIdentifier)}/editor/seo-candidates.json${qs}`,
        { credentials: 'same-origin', headers: buildAuthHeaders() },
      );
      const body = await res.json().catch(() => null);
      return res.ok ? ((body as { data?: SeoCandidatesResponse })?.data ?? null) : null;
    },
    [candidatesFetcher, templateIdentifier],
  );

  // 이 레이아웃이 직접 선언한 meta.seo(base 병합 전, `__editor.original.meta.seo`). 병합본에는
  // 있으나 own 에 없는 og/twitter 키 = base 상속 → 미리보기가 source='inherited' 로 분류(SEO-B).
  const ownSeo = useMemo<Record<string, unknown> | undefined>(() => {
    const original = (getValue<Record<string, unknown>>('__editor', {}) ?? {}).original as Record<string, unknown> | undefined;
    const originalMeta = original?.meta as Record<string, unknown> | undefined;
    const originalSeo = originalMeta?.seo;
    return originalSeo && typeof originalSeo === 'object' ? (originalSeo as Record<string, unknown>) : undefined;
  }, [getValue]);
  const ownSeoRef = useRef(ownSeo);
  ownSeoRef.current = ownSeo;

  const fetchOgPreview = useCallback(
    async (seoSnapshot: Record<string, unknown>): Promise<SeoOgPreviewResponse | null> => {
      if (ogPreviewFetcher) return ogPreviewFetcher(seoSnapshot);
      if (typeof fetch === 'undefined') return null;
      const res = await fetch(
        `/api/admin/templates/${encodeURIComponent(templateIdentifier)}/editor/seo-og-preview`,
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: buildAuthHeaders({ 'Content-Type': 'application/json' }),
          body: JSON.stringify({
            seo: seoSnapshot,
            own_seo: ownSeoRef.current ?? null,
            seed_context: seedContext ?? {},
            route_params: {},
          }),
        },
      );
      const body = await res.json().catch(() => null);
      return res.ok ? ((body as { data?: SeoOgPreviewResponse })?.data ?? null) : null;
    },
    [ogPreviewFetcher, templateIdentifier, seedContext],
  );

  // extensions/page_type 변경 → 후보 + og 미리보기 재호출(이전 기본값 무효화).
  const extSig = useMemo(() => JSON.stringify(extensions), [extensions]);
  const seoSnapshotRef = useRef(seoVals);
  seoSnapshotRef.current = seoVals;
  // 후보(page_types/toggle/vars)는 page_type/extensions 에만 종속 → 그 둘 변경 시 재호출.
  useEffect(() => {
    let cancelled = false;
    void (async () => {
      const cd = await fetchCandidates(pageType, extensions);
      if (!cancelled) setCandidatesData(cd);
    })();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pageType, extSig]);

  // og/twitter 미리보기(키별 출처·override·필터잠김)는 page_type/extensions 뿐 아니라
  // og/twitter/structured_data 값 자체에도 종속한다(SEO-A). 종전엔 dep 이
  // [pageType, extSig] 뿐이라 og 한 칸을 채우거나 비워도 미리보기가 재계산되지 않아
  // "어느 출처(코어/모듈/상속)로 돌아가는지"가 stale 했다. og/twitter/structured_data
  // 시그니처를 dep 에 포함하되, 키 입력마다 호출하지 않도록 디바운스(180ms)한다.
  const ogPreviewSig = useMemo(
    () => JSON.stringify({ og: seoVals.og ?? null, twitter: seoVals.twitter ?? null, structured_data: seoVals.structured_data ?? null }),
    [seoVals.og, seoVals.twitter, seoVals.structured_data],
  );
  // 첫 실행(탭 진입)은 즉시 호출(빈 미리보기 깜빡임 방지), 이후 값 변경분만 디바운스.
  const ogPreviewMountedRef = useRef(false);
  useEffect(() => {
    let cancelled = false;
    const run = (): void => {
      void (async () => {
        const og = await fetchOgPreview(seoSnapshotRef.current);
        if (!cancelled) setOgPreview(og);
      })();
    };
    if (!ogPreviewMountedRef.current) {
      ogPreviewMountedRef.current = true;
      run();
      return () => {
        cancelled = true;
      };
    }
    const timer = setTimeout(run, 180);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
    // 탭 진입 + page_type/extensions/og/twitter/structured_data 변경 시 재호출.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pageType, extSig, ogPreviewSig]);

  const pageTypeCands = candidatesData?.page_types ?? [];
  const toggleCands = candidatesData?.toggle_settings ?? [];
  const varCands = candidatesData?.vars ?? [];

  // 봇 미리보기 시그니처(설정 변경 디바운스 트리거).
  const botSignature = useMemo(() => JSON.stringify(seoVals), [seoVals]);
  const botLayout = useMemo(() => getValue('meta') ? { meta: getValue('meta'), components: getValue('components', []) } : { meta: {}, components: [] }, [getValue]);

  // ── extensions 칩 ──
  const addExtension = useCallback(
    (ref: SeoExtensionRef): void => {
      if (extensions.some((e) => e.type === ref.type && e.id === ref.id)) return;
      patchSeo('extensions', [...extensions, { type: ref.type, id: ref.id }]);
    },
    [extensions, patchSeo],
  );
  const removeExtension = useCallback(
    (ref: SeoExtensionRef): void => {
      const next = extensions.filter((e) => !(e.type === ref.type && e.id === ref.id));
      patchSeo('extensions', next.length > 0 ? next : undefined);
    },
    [extensions, patchSeo],
  );

  const [extPickerOpen, setExtPickerOpen] = useState(false);

  // toggle_setting 검색 드롭다운.
  const [toggleKeyword, setToggleKeyword] = useState('');
  const filteredToggles = useMemo(() => {
    const kw = toggleKeyword.trim().toLowerCase();
    if (!kw) return toggleCands;
    return toggleCands.filter((c) => `${c.ref} ${c.label ?? ''}`.toLowerCase().includes(kw));
  }, [toggleCands, toggleKeyword]);
  const currentToggle = typeof seoVals.toggle_setting === 'string' ? (seoVals.toggle_setting as string) : '';

  const toggleDataSource = useCallback(
    (id: string): void => {
      const has = selectedDataSources.includes(id);
      const next = has ? selectedDataSources.filter((d) => d !== id) : [...selectedDataSources, id];
      patchSeo('data_sources', next.length > 0 ? next : undefined);
    },
    [selectedDataSources, patchSeo],
  );

  return (
    <div data-testid={testidPrefix} style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
      {/* ① 검색엔진 노출 토글 */}
      <div style={field}>
        <ToggleSwitch
          checked={enabled}
          onChange={(on) => patchSeo('enabled', on)}
          testid={`${testidPrefix}-enabled`}
          label={t('layout_editor.page_settings.seo.enabled')}
        />
        {!enabled ? (
          <p data-testid={`${testidPrefix}-disabled-note`} style={mutedNote}>
            ⓘ {t('layout_editor.page_settings.seo.disabled_note')}
          </p>
        ) : null}
      </div>

      {/* OFF 일 때도 숨기지 않고 회색 비활성으로 항상 표시(D-M). */}
      <DisabledFieldset disabled={!enabled} testid={`${testidPrefix}-body`} style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
        <>
          {/* 페이지 종류 — 후보 select 또는 자유 텍스트 폴백 */}
          <div style={field}>
            <label style={fieldLabel}>{t('layout_editor.page_settings.seo.page_type')}</label>
            {pageTypeCands.length > 0 ? (
              <select
                data-testid={`${testidPrefix}-page-type`}
                value={pageType}
                onChange={(e) => patchSeo('page_type', e.target.value)}
                style={input}
              >
                <option value="">{t('layout_editor.page_settings.seo.page_type_none')}</option>
                {pageTypeCands.map((c) => (
                  <option key={c.value} value={c.value}>
                    {c.label ?? c.value}
                    {c.owner?.name ? ` (${c.owner.name})` : ''}
                  </option>
                ))}
              </select>
            ) : (
              <>
                <input
                  type="text"
                  data-testid={`${testidPrefix}-page-type-free`}
                  value={pageType}
                  placeholder={t('layout_editor.page_settings.seo.page_type_free_placeholder')}
                  onChange={(e) => patchSeo('page_type', e.target.value)}
                  style={input}
                />
                <p data-testid={`${testidPrefix}-page-type-free-note`} style={mutedNote}>
                  ⓘ {t('layout_editor.page_settings.seo.page_type_free_note')}
                </p>
              </>
            )}
          </div>

          {/* 확장 SEO 연동 칩 */}
          <div style={field}>
            <label style={fieldLabel}>★ {t('layout_editor.page_settings.seo.extensions')}</label>
            <div data-testid={`${testidPrefix}-extensions`} style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
              {extensions.map((ext) => (
                <span key={`${ext.type}:${ext.id}`} data-testid={`${testidPrefix}-extension-${ext.type}-${ext.id}`} style={chip}>
                  {ext.name ?? `${ext.type}:${ext.id}`}
                  <button
                    type="button"
                    data-testid={`${testidPrefix}-extension-remove-${ext.type}-${ext.id}`}
                    onClick={() => removeExtension(ext)}
                    style={chipRemove}
                  >
                    ✕
                  </button>
                </span>
              ))}
              <button type="button" data-testid={`${testidPrefix}-extension-add`} onClick={() => setExtPickerOpen((v) => !v)} style={chipAdd}>
                + {t('layout_editor.page_settings.seo.add_extension')}
              </button>
            </div>
            {extPickerOpen && (
              <div data-testid={`${testidPrefix}-extension-picker`} style={pickerBox}>
                {availableExtensions.length === 0 ? (
                  <p style={mutedNote}>{t('layout_editor.page_settings.seo.no_extension_candidate')}</p>
                ) : (
                  availableExtensions.map((ref) => (
                    <button
                      key={`${ref.type}:${ref.id}`}
                      type="button"
                      data-testid={`${testidPrefix}-extension-option-${ref.type}-${ref.id}`}
                      onClick={() => {
                        addExtension(ref);
                        setExtPickerOpen(false);
                      }}
                      style={pickerOption}
                    >
                      {ref.name ?? `${ref.type}:${ref.id}`}
                    </button>
                  ))
                )}
              </div>
            )}
          </div>

          {/* 기본값 전제 배너(extensions ∧ page_type) */}
          {!gatingMet && (
            <div data-testid={`${testidPrefix}-defaults-precondition`} style={precondBanner}>
              ⚠ {t('layout_editor.page_settings.seo.defaults_precondition')}
              <div data-testid={`${testidPrefix}-defaults-missing`} style={{ fontSize: 11, marginTop: 4 }}>
                {extensions.length === 0 ? t('layout_editor.page_settings.seo.missing_extensions') : ''}
                {pageType === '' ? ` ${t('layout_editor.page_settings.seo.missing_page_type')}` : ''}
              </div>
            </div>
          )}

          {/* 노출 스위치 설정 연동(검색 드롭다운) */}
          <div style={field}>
            <label style={fieldLabel}>{t('layout_editor.page_settings.seo.toggle_setting')}</label>
            <div data-testid={`${testidPrefix}-toggle-setting`}>
              {currentToggle ? (
                <div style={{ display: 'flex', gap: 6, alignItems: 'center', marginBottom: 4 }}>
                  <code style={chip}>{currentToggle}</code>
                  <button type="button" data-testid={`${testidPrefix}-toggle-setting-clear`} onClick={() => patchSeo('toggle_setting', undefined)} style={chipRemove}>✕</button>
                </div>
              ) : null}
              <input
                type="text"
                data-testid={`${testidPrefix}-toggle-setting-search`}
                value={toggleKeyword}
                placeholder={t('layout_editor.page_settings.seo.toggle_setting_search')}
                onChange={(e) => setToggleKeyword(e.target.value)}
                style={input}
              />
              {toggleKeyword.trim() !== '' && (
                <div data-testid={`${testidPrefix}-toggle-setting-results`} style={pickerBox}>
                  {filteredToggles.length === 0 ? (
                    <p style={mutedNote}>{t('layout_editor.inline_binding.no_results')}</p>
                  ) : (
                    filteredToggles.map((c) => (
                      <button
                        key={c.ref}
                        type="button"
                        data-testid={`${testidPrefix}-toggle-setting-option-${c.ref}`}
                        onClick={() => {
                          patchSeo('toggle_setting', c.ref);
                          setToggleKeyword('');
                        }}
                        style={pickerOption}
                      >
                        {c.label ?? c.ref}
                      </button>
                    ))
                  )}
                </div>
              )}
            </div>
          </div>

          {/* SEO 연동 데이터(data_sources 멀티선택) — [데이터] 탭과 같은 표기(친화명+id+출처 배지)
              칩으로 노출. 종전엔 raw id 만 붙은 작은 체크박스가 줄바꿈 없이 늘어서 어느 데이터인지·
              출처가 어디인지 알 수 없었다. */}
          <div style={field}>
            <label style={fieldLabel}>{t('layout_editor.page_settings.seo.data_sources')}</label>
            <p style={mutedNote}>{t('layout_editor.page_settings.seo.data_sources_hint')}</p>
            <div data-testid={`${testidPrefix}-data-sources`} style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
              {dataSourceOptions.length === 0 ? (
                <span style={mutedNote}>{t('layout_editor.page_settings.seo.data_sources_empty')}</span>
              ) : (
                dataSourceOptions.map((opt) => {
                  const checked = selectedDataSources.includes(opt.id);
                  return (
                    <label
                      key={opt.id}
                      data-testid={`${testidPrefix}-data-source-${opt.id}`}
                      style={dataSourceRow(checked)}
                    >
                      <input
                        type="checkbox"
                        data-testid={`${testidPrefix}-data-source-check-${opt.id}`}
                        checked={checked}
                        onChange={() => toggleDataSource(opt.id)}
                      />
                      <DataSourceChipLabel option={opt} testIdPrefix={`${testidPrefix}-data-source-${opt.id}`} />
                    </label>
                  );
                })
              )}
            </div>
          </div>

          {/* sitemap 우선순위 슬라이더 */}
          <div style={field}>
            <label style={fieldLabel}>{t('layout_editor.page_settings.seo.priority')}</label>
            <input
              type="range"
              data-testid={`${testidPrefix}-priority`}
              min={0}
              max={1}
              step={0.1}
              value={typeof seoVals.priority === 'number' ? (seoVals.priority as number) : 0.5}
              onChange={(e) => patchSeo('priority', Number(e.target.value))}
            />
            <span data-testid={`${testidPrefix}-priority-value`} style={{ fontSize: 12, color: '#475569' }}>
              {typeof seoVals.priority === 'number' ? (seoVals.priority as number) : 0.5}
            </span>
          </div>

          {/* 갱신 주기 */}
          <div style={field}>
            <label style={fieldLabel}>{t('layout_editor.page_settings.seo.changefreq')}</label>
            <select
              data-testid={`${testidPrefix}-changefreq`}
              value={typeof seoVals.changefreq === 'string' ? (seoVals.changefreq as string) : ''}
              onChange={(e) => patchSeo('changefreq', e.target.value)}
              style={input}
            >
              <option value="">{t('layout_editor.page_settings.seo.use_default')}</option>
              {CHANGEFREQ_OPTIONS.map((o) => (
                <option key={o} value={o}>{o}</option>
              ))}
            </select>
          </div>

          {/* 검색 제목/설명 */}
          <div style={field}>
            <label style={fieldLabel}>{t('layout_editor.page_settings.seo.title')}</label>
            <I18nTextField
              value={typeof seoVals.title === 'string' ? (seoVals.title as string) : ''}
              onChange={(v) => patchSeo('title', v ?? '')}
              t={t}
              candidates={candidates}
              testidPrefix={`${testidPrefix}-title`}
              enableExpressionTree
              expressionTreeCollapsible
            />
          </div>
          <div style={field}>
            <label style={fieldLabel}>{t('layout_editor.page_settings.seo.description')}</label>
            <I18nTextField
              value={typeof seoVals.description === 'string' ? (seoVals.description as string) : ''}
              onChange={(v) => patchSeo('description', v ?? '')}
              t={t}
              candidates={candidates}
              testidPrefix={`${testidPrefix}-description`}
              enableExpressionTree
              expressionTreeCollapsible
            />
          </div>

          {/* ② 소셜 공유 */}
          <SeoOgForm
            og={seoVals.og as Record<string, unknown> | undefined}
            twitter={seoVals.twitter as Record<string, unknown> | undefined}
            onChangeOg={(next) => patchSeo('og', Object.keys(next).length > 0 ? next : undefined)}
            onChangeTwitter={(next) => patchSeo('twitter', Object.keys(next).length > 0 ? next : undefined)}
            ogPreview={ogPreview?.og}
            twitterPreview={ogPreview?.twitter}
            defaultsAvailable={ogPreview?.defaultsAvailable ?? false}
            candidates={candidates}
            t={t}
          />

          {/* ③ 구조화 데이터 */}
          <div style={field}>
            <label style={fieldLabel}>{t('layout_editor.page_settings.seo.structured_data')}</label>
            <SeoStructuredDataEditor
              value={seoVals.structured_data as StructuredDataBlock | undefined}
              onChange={(next) => patchSeo('structured_data', next ?? undefined)}
              autoBlock={ogPreview?.structured?.autoBlock}
              autoMeta={ogPreview?.structured?.autoMeta}
              lockedByFilter={ogPreview?.structured?.lockedByFilter}
              filteredBlock={ogPreview?.structured?.filteredBlock}
              pageType={pageType}
              candidates={candidates}
              t={t}
            />
          </div>

          {/* ④ 동적 변수 */}
          <div style={field}>
            <label style={fieldLabel}>{t('layout_editor.page_settings.seo.vars')}</label>
            <SeoVarsEditor
              vars={seoVals.vars as Record<string, string> | undefined}
              onChange={(next) => patchSeo('vars', Object.keys(next).length > 0 ? next : undefined)}
              varCandidates={varCands}
              gatingMet={gatingMet}
              candidates={candidates}
              t={t}
            />
          </div>

          {/* ⑤ 봇 미리보기 */}
          <SeoBotPreviewPanel
            templateIdentifier={templateIdentifier}
            layout={botLayout}
            url={previewUrl}
            locale={previewLocale}
            seedContext={seedContext}
            settingsSignature={botSignature}
            t={t}
            fetchImpl={botFetchImpl}
          />
        </>
      </DisabledFieldset>
    </div>
  );
}

const field: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4 };
const fieldLabel: React.CSSProperties = { fontSize: 12, fontWeight: 600, color: '#334155' };
const input: React.CSSProperties = { padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, width: '100%', minWidth: 0, boxSizing: 'border-box' };
const mutedNote: React.CSSProperties = { margin: '4px 0 0', fontSize: 11, color: '#94a3b8' };
/** SEO 연동 데이터 선택 행 — 체크 시 강조(테두리/배경) */
const dataSourceRow = (checked: boolean): React.CSSProperties => ({
  display: 'flex',
  alignItems: 'center',
  gap: 8,
  padding: '6px 10px',
  fontSize: 12,
  border: `1px solid ${checked ? '#3b82f6' : '#e2e8f0'}`,
  borderRadius: 8,
  background: checked ? '#eff6ff' : '#fff',
  cursor: 'pointer',
  minWidth: 0,
});
const precondBanner: React.CSSProperties = { fontSize: 12, color: '#b45309', background: '#fffbeb', border: '1px solid #fcd34d', borderRadius: 6, padding: '8px 10px' };
const chip: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11, padding: '3px 8px', borderRadius: 999, background: '#eef2ff', color: '#3730a3' };
const chipRemove: React.CSSProperties = { border: 'none', background: 'transparent', cursor: 'pointer', color: '#6366f1', fontSize: 11, padding: 0 };
const chipAdd: React.CSSProperties = { fontSize: 11, padding: '3px 10px', borderRadius: 999, border: '1px dashed #cbd5e1', background: '#f8fafc', color: '#475569', cursor: 'pointer' };
const pickerBox: React.CSSProperties = { marginTop: 4, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', padding: 6, display: 'flex', flexDirection: 'column', gap: 2 };
const pickerOption: React.CSSProperties = { textAlign: 'left', border: 'none', background: 'transparent', cursor: 'pointer', padding: '5px 8px', borderRadius: 4, fontSize: 12, color: '#0f172a' };
