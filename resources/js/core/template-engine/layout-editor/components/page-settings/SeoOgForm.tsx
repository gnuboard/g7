// e2e:allow 페이지 설정 모달 영역(검색엔진 탭) — 모달/포털·I18nTextField/DataChipValueInput 합성 의존으로 Playwright 부적합. 단위 RTL(SeoOgForm.test) + Chrome MCP 매트릭스(tests/scenarios/page-settings.yaml audit:allow)가 SSoT. 묶음③ 자동값 연결 칩 동일 정책.
/**
 * SeoOgForm.tsx — Open Graph / Twitter 3계층 + 출처 표시 + override
 *
 * og/twitter 각 란의 **기본값·출처·필터잠김은 서버 미리보기가 결정**한다(편집기 추정 불가).
 * 키별 `{ key, effectiveValue, source, overriddenByLayout, lockedByFilter }`. 본 폼은:
 *
 *  - ⓐ 비었으면 코어/모듈 기본값을 **placeholder + 출처 배지**(〔코어 설정〕/〔이커머스 제공〕)로 보여줌.
 *  - ⓑ 입력하면 **레이아웃 override**(데이터칩) — 빈 값=기본값(키 미생성, 비파괴).
 *  - ⓒ `filter_og_data`/`filter_twitter_data` 가 그 키를 실제로 덮으면(서버 lockedByFilter) 🔒 읽기전용.
 *  - 텍스트형 다국어(title/description/image_alt) = I18nTextField(키화), 값형(image) = DataChipValueInput.
 *  - twitter 비우면 og.* 폴백(서버 cascade 가 effectiveValue 로 반영).
 *  - og.extra/twitter.extra = KeyValueChipEditor(배열 {property|name, content}).
 *
 * 미리보기 응답 없음(전제 미충족 — extensions ∧ page_type 둘 다 필요)이면 기본값 placeholder 미표시.
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(라이브러리 중립).
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useState } from 'react';
import { I18nTextField } from '../property-controls/I18nTextField';
import { DataChipValueInput } from './DataChipValueInput';
import { KeyValueChipEditor, type KeyValueExtraItem } from './KeyValueChipEditor';
import { OverlaySourceField } from './FormPrimitives';
import type { BindingCandidate } from '../../spec/bindingCandidates';

/** 서버 미리보기 og/twitter 키별 cascade 행 */
export interface SeoPreviewRow {
  key: string;
  effectiveValue?: string | number | null;
  /** core(코어 폴백)/module:{id}(확장 제공)/layout(이 화면 직접 입력)/inherited(공통 레이아웃 상속)/filter(필터 잠김) */
  source?: 'core' | 'layout' | 'inherited' | 'filter' | string;
  overriddenByLayout?: boolean;
  /** 병합본엔 있으나 이 레이아웃 own 엔 없음 = base(공통 레이아웃) 상속(SEO-B) */
  inheritedFromBase?: boolean;
  lockedByFilter?: boolean;
  /** 모듈 자동값의 데이터 경로 표현식(`{{product.data.name}}`) — 연결 칩. module 출처일 때만 */
  sourceExpr?: string;
  /** 모듈 자동값의 사용자용 라벨("상품 이름") — 연결 칩 표시명 */
  label?: string;
}

/** og/twitter 미리보기 행 맵 헬퍼 */
export type SeoPreviewMap = Record<string, SeoPreviewRow>;

export interface SeoOgFormProps {
  /** 현재 `meta.seo.og` 값 */
  og: Record<string, unknown> | null | undefined;
  /** 현재 `meta.seo.twitter` 값 */
  twitter: Record<string, unknown> | null | undefined;
  /** og 변경 콜백 */
  onChangeOg: (next: Record<string, unknown>) => void;
  /** twitter 변경 콜백 */
  onChangeTwitter: (next: Record<string, unknown>) => void;
  /** 서버 og 미리보기 행. 전제 미충족이면 빈 배열/undefined */
  ogPreview?: SeoPreviewRow[];
  /** 서버 twitter 미리보기 행 */
  twitterPreview?: SeoPreviewRow[];
  /** 기본값 사용 가능(extensions ∧ page_type) — false 면 placeholder 출처 배지 미표시 */
  defaultsAvailable?: boolean;
  /** 데이터칩 후보 풀(SEO 컨텍스트) */
  candidates?: BindingCandidate[];
  /** 다국어 해석 t */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** data-testid 접두 */
  testidPrefix?: string;
}

const OG_TYPE_OPTIONS = ['website', 'article', 'product', 'profile'];
const TW_CARD_OPTIONS = ['summary', 'summary_large_image', 'app', 'player'];

/** 출처 → 배지 라벨 키 */
function sourceLabelKey(source: string | undefined): string | null {
  switch (source) {
    case 'core':
      return 'layout_editor.page_settings.seo.og_source_core';
    case 'inherited':
      // base(공통 레이아웃)에서 상속 — 이 화면이 직접 안 채운 값(SEO-B).
      return 'layout_editor.page_settings.seo.og_source_inherited';
    case 'filter':
      return 'layout_editor.page_settings.seo.og_source_filter';
    case 'layout':
      return null; // 이 화면 직접 입력(override) — 배지 없음(편집칸에 값).
    default:
      return source ? 'layout_editor.page_settings.seo.og_source_module' : null;
  }
}

/** 미리보기 배열 → 키 맵 */
function toMap(rows: SeoPreviewRow[] | undefined): SeoPreviewMap {
  const m: SeoPreviewMap = {};
  for (const r of rows ?? []) m[r.key] = r;
  return m;
}

/**
 * 이 행이 모듈 자동값(연결 칩 표시 대상)인가
 * 출처가 module 이고 sourceExpr 메타가 있고, 이 화면이 직접 덮지/잠그지 않았을 때만.
 * 그 경우 입력칸 대신 연결 칩("상품 이름")을 보여주고 "다른 데이터로 바꾸기"로 override 진입.
 */
function isAutoChip(row: SeoPreviewRow | undefined, defaultsAvailable: boolean): boolean {
  return (
    !!row &&
    defaultsAvailable &&
    typeof row.sourceExpr === 'string' &&
    row.sourceExpr !== '' &&
    !row.overriddenByLayout &&
    !row.lockedByFilter
  );
}

/**
 * 모듈 자동값 연결 칩 — "어느 데이터에서 왔는지"(라벨)를 칩으로 보여주고, 그 자리에서 다른
 * 데이터로 교체(override 진입)하게 한다. 교체를 누르면 호출자가 sourceExpr 을 레이아웃
 * 값으로 채워(setter) 다음 미리보기에서 source='layout' → DataChipValueInput 편집칸으로 전환된다.
 *
 * @param props 자동 칩 표시/교체 콜백
 * @return 연결 칩 + "다른 데이터로 바꾸기" 엘리먼트
 */
function AutoChipField({
  label,
  sourceExpr,
  onReplace,
  t,
  testid,
}: {
  label: string;
  sourceExpr: string;
  onReplace: () => void;
  t: (key: string, params?: Record<string, string | number>) => string;
  testid: string;
}): React.ReactElement {
  return (
    <div data-testid={testid} style={autoChipWrap}>
      <span data-testid={`${testid}-chip`} style={autoChip} title={sourceExpr}>
        🔗 {label || sourceExpr}
      </span>
      <span data-testid={`${testid}-badge`} style={autoChipBadge}>
        {t('layout_editor.page_settings.seo.auto_chip_source')}
      </span>
      <button
        type="button"
        data-testid={`${testid}-replace`}
        onClick={onReplace}
        style={autoChipReplaceBtn}
      >
        {t('layout_editor.page_settings.seo.auto_chip_replace')}
      </button>
    </div>
  );
}

/**
 * og/twitter 3계층 override 폼.
 *
 * @param props SeoOgFormProps
 * @return og/twitter 편집 엘리먼트
 */
export function SeoOgForm({
  og,
  twitter,
  onChangeOg,
  onChangeTwitter,
  ogPreview,
  twitterPreview,
  defaultsAvailable = false,
  candidates,
  t,
  testidPrefix = 'g7le-seo-og',
}: SeoOgFormProps): React.ReactElement {
  const ogVals = (og && typeof og === 'object' ? og : {}) as Record<string, unknown>;
  const twVals = (twitter && typeof twitter === 'object' ? twitter : {}) as Record<string, unknown>;
  // 고급 이미지 옵션 접이식(와이어프레임 L1179 — 세로·형식·보안 URL).
  // 이미 값이 있으면 펼침(놓치지 않게).
  const [ogImgAdvOpen, setOgImgAdvOpen] = useState<boolean>(
    ogVals.image_height != null || ogVals.image_type != null || ogVals.image_secure_url != null,
  );
  const ogMap = toMap(ogPreview);
  const twMap = toMap(twitterPreview);

  const setOg = useCallback(
    (key: string, value: unknown): void => {
      const next = { ...ogVals };
      if (value === undefined || value === '' || value === null) delete next[key];
      else next[key] = value;
      onChangeOg(next);
    },
    [ogVals, onChangeOg],
  );
  const setTw = useCallback(
    (key: string, value: unknown): void => {
      const next = { ...twVals };
      if (value === undefined || value === '' || value === null) delete next[key];
      else next[key] = value;
      onChangeTwitter(next);
    },
    [twVals, onChangeTwitter],
  );

  /** 잠김(🔒) 표시 — 칸 아래 별도 줄(편집 불가 안내라 칸 안에 못 녹임). 출처 배지는 #4 로 칸 안 이관. */
  const renderMeta = (prefix: string, key: string, row: SeoPreviewRow | undefined): React.ReactNode => {
    if (!row || !defaultsAvailable) return null;
    if (!row.lockedByFilter) return null;
    return (
      <div style={{ display: 'flex', gap: 6, alignItems: 'center', marginTop: 2 }}>
        <span data-testid={`${prefix}-locked-${key}`} style={lockBadge}>🔒 {t('layout_editor.page_settings.seo.og_locked')}</span>
      </div>
    );
  };

  /** 출처 칩 라벨(칸 안 — #4). 이 화면이 직접 덮지 않았을 때(코어/모듈/상속) + 출처 있을 때만. 잠김/직접입력 시 미표시. */
  const sourceChipLabel = (row: SeoPreviewRow | undefined): React.ReactNode => {
    if (!row || !defaultsAvailable || row.lockedByFilter || row.overriddenByLayout) return undefined;
    const labelKey = sourceLabelKey(row.source);
    return labelKey ? t(labelKey) : undefined;
  };

  /**
   * 이 화면이 직접 채웠는가(= 칸에 값을 입력해 base/코어/모듈을 덮음). true 면 출처칩 대신
   * 되돌리기 버튼. 미리보기 행이 있으면 서버 판정(overriddenByLayout)을 신뢰한다 — 병합본 값이
   * base 상속이어도 그 칸을 "내가 채웠다"로 오판하지 않게(SEO-B). 미리보기 미충족(행 없음)
   * 이면 종전대로 현재 값 유무로 판정.
   */
  const isOwnFilled = (row: SeoPreviewRow | undefined, cur: string): boolean => {
    if (row && defaultsAvailable) return row.overriddenByLayout === true;
    return cur !== '';
  };

  /** I18nTextField 행(title/description/image_alt) — 잠김 시 읽기전용 표시 */
  const i18nRow = (
    prefix: string,
    key: string,
    labelKey: string,
    vals: Record<string, unknown>,
    setter: (k: string, v: unknown) => void,
    map: SeoPreviewMap,
  ): React.ReactNode => {
    const row = map[key];
    const locked = !!row?.lockedByFilter;
    const cur = typeof vals[key] === 'string' ? (vals[key] as string) : '';
    return (
      <div data-testid={`${prefix}-${key}`} style={fieldRow}>
        <label style={fieldLabel}>{t(labelKey)}</label>
        {locked ? (
          <div data-testid={`${prefix}-locked-value-${key}`} style={lockedValue}>
            {String(row?.effectiveValue ?? '')}
          </div>
        ) : isAutoChip(row, defaultsAvailable) ? (
          <AutoChipField
            label={row!.label ?? ''}
            sourceExpr={row!.sourceExpr!}
            onReplace={() => setter(key, row!.sourceExpr!)}
            t={t}
            testid={`${prefix}-${key}-auto`}
          />
        ) : (
          <I18nTextField
            value={cur}
            onChange={(v) => setter(key, v ?? '')}
            t={t}
            candidates={candidates}
            placeholder={defaultsAvailable && row?.effectiveValue != null ? String(row.effectiveValue) : undefined}
            testidPrefix={`${prefix}-${key}-field`}
            enableExpressionTree
            expressionTreeCollapsible
          />
        )}
        {renderMeta(prefix, key, row)}
      </div>
    );
  };

  /** 데이터칩 값 행(image) — 키화 없음 */
  const chipRow = (
    prefix: string,
    key: string,
    labelKey: string,
    vals: Record<string, unknown>,
    setter: (k: string, v: unknown) => void,
    map: SeoPreviewMap,
  ): React.ReactNode => {
    const row = map[key];
    const locked = !!row?.lockedByFilter;
    const cur = typeof vals[key] === 'string' ? (vals[key] as string) : '';
    return (
      <div data-testid={`${prefix}-${key}`} style={fieldRow}>
        <label style={fieldLabel}>{t(labelKey)}</label>
        {isAutoChip(row, defaultsAvailable) ? (
          <AutoChipField
            label={row!.label ?? ''}
            sourceExpr={row!.sourceExpr!}
            onReplace={() => setter(key, row!.sourceExpr!)}
            t={t}
            testid={`${prefix}-${key}-auto`}
          />
        ) : (
          <DataChipValueInput
            value={cur}
            onChange={(v) => setter(key, v)}
            t={t}
            candidates={candidates}
            readOnly={locked}
            placeholder={defaultsAvailable && row?.effectiveValue != null ? String(row.effectiveValue) : undefined}
            testidPrefix={`${prefix}-${key}-field`}
          />
        )}
        {renderMeta(prefix, key, row)}
      </div>
    );
  };

  /** text 행(site_name/locale/twitter site/creator) */
  const textRow = (
    prefix: string,
    key: string,
    labelKey: string,
    vals: Record<string, unknown>,
    setter: (k: string, v: unknown) => void,
    map: SeoPreviewMap,
  ): React.ReactNode => {
    const row = map[key];
    const locked = !!row?.lockedByFilter;
    const cur = typeof vals[key] === 'string' ? (vals[key] as string) : '';
    const ownFilled = isOwnFilled(row, cur);
    // 상속/코어/모듈 출처(미덮음)면 칸은 비우고 effective 값을 placeholder 로(출처칩과 일관).
    // 직접 입력(ownFilled)이면 값을 표시 + 되돌리기. base 병합값을 "내가 채움"으로 오인 방지.
    const shownValue = ownFilled ? cur : '';
    return (
      <div data-testid={`${prefix}-${key}`} style={fieldRow}>
        <label style={fieldLabel}>{t(labelKey)}</label>
        <OverlaySourceField
          filled={ownFilled}
          sourceLabel={sourceChipLabel(row)}
          onRevert={locked ? undefined : () => setter(key, undefined)}
          revertLabel={t('layout_editor.page_settings.seo.revert_default')}
          testid={`${prefix}-${key}-overlay`}
        >
          {/* 사이트명/로케일 등 보조 텍스트 칸도 데이터 칩(단순 데이터
              연동 + 표현식 + 평문). 상속/출처칩/되돌리기(OverlaySourceField)·필터 잠금(readOnly)·기본값
              placeholder 래핑은 그대로 유지. 평문은 종전처럼 그대로 입력(키화 0). */}
          <div data-testid={`${prefix}-${key}-input`} style={{ minWidth: 0 }}>
            <DataChipValueInput
              value={shownValue}
              onChange={(v) => setter(key, v)}
              t={t}
              candidates={candidates}
              readOnly={locked}
              placeholder={defaultsAvailable && row?.effectiveValue != null ? String(row.effectiveValue) : undefined}
              testidPrefix={`${prefix}-${key}-chip`}
            />
          </div>
        </OverlaySourceField>
        {renderMeta(prefix, key, row)}
      </div>
    );
  };

  /** select 행(og.type/twitter.card) */
  const selectRow = (
    prefix: string,
    key: string,
    labelKey: string,
    options: string[],
    vals: Record<string, unknown>,
    setter: (k: string, v: unknown) => void,
    map: SeoPreviewMap,
  ): React.ReactNode => {
    const row = map[key];
    const cur = typeof vals[key] === 'string' ? (vals[key] as string) : '';
    const ownFilled = isOwnFilled(row, cur);
    const srcLabel = sourceChipLabel(row);
    // 직접 선택(ownFilled)이 아니면 select 는 "기본값 사용"(빈값)으로 두고 라벨 옆 출처를 표기.
    // base 병합값(예: type=website 상속)을 select 에 박아 "내가 고름"으로 오인 방지(SEO-B).
    const shownValue = ownFilled ? cur : '';
    return (
      <div data-testid={`${prefix}-${key}`} style={fieldRow}>
        {/* select 는 native 화살표가 우측을 차지 → 출처를 라벨 옆에 옅게(칸 안 칩 대신). */}
        <div style={labelRow}>
          <label style={fieldLabel}>{t(labelKey)}</label>
          {srcLabel ? (
            <span data-testid={`${prefix}-${key}-source-inline`} style={inlineSourceLabel}>· {srcLabel}</span>
          ) : null}
        </div>
        <select
          data-testid={`${prefix}-${key}-select`}
          value={shownValue}
          onChange={(e) => setter(key, e.target.value)}
          style={textInput}
        >
          <option value="">
            {defaultsAvailable && row?.effectiveValue != null && row.effectiveValue !== ''
              ? `${t('layout_editor.page_settings.seo.use_default')} (${String(row.effectiveValue)})`
              : t('layout_editor.page_settings.seo.use_default')}
          </option>
          {options.map((o) => (
            <option key={o} value={o}>{o}</option>
          ))}
        </select>
        {renderMeta(prefix, key, row)}
      </div>
    );
  };

  return (
    <div data-testid={testidPrefix} style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
      {/* ── Open Graph ── */}
      <div data-testid={`${testidPrefix}-og-section`} style={section}>
        <div style={sectionTitle}>{t('layout_editor.page_settings.seo.og_section')}</div>
        {/* 안내줄(② 와이어프레임 L1167) — 빈 칸=기본값(출처 표시), 입력=이 화면 override. */}
        <p data-testid={`${testidPrefix}-intro`} style={introNote}>ⓘ {t('layout_editor.page_settings.seo.og_intro')}</p>
        {selectRow(testidPrefix, 'type', 'layout_editor.page_settings.seo.og_type', OG_TYPE_OPTIONS, ogVals, setOg, ogMap)}
        {i18nRow(testidPrefix, 'title', 'layout_editor.page_settings.seo.og_title', ogVals, setOg, ogMap)}
        {i18nRow(testidPrefix, 'description', 'layout_editor.page_settings.seo.og_description', ogVals, setOg, ogMap)}
        {chipRow(testidPrefix, 'image', 'layout_editor.page_settings.seo.og_image', ogVals, setOg, ogMap)}
        {i18nRow(testidPrefix, 'image_alt', 'layout_editor.page_settings.seo.og_image_alt', ogVals, setOg, ogMap)}
        {/* 고급 이미지 옵션 접이식(W1 L1179 — 세로·형식·보안 URL) */}
        <div style={fieldRow}>
          <button
            type="button"
            data-testid={`${testidPrefix}-image-adv-toggle`}
            aria-expanded={ogImgAdvOpen}
            onClick={() => setOgImgAdvOpen((v) => !v)}
            style={advToggle}
          >
            {ogImgAdvOpen ? '▾' : '▸'} {t('layout_editor.page_settings.seo.og_image_adv')}
          </button>
          {ogImgAdvOpen ? (
            <div data-testid={`${testidPrefix}-image-adv`} style={advBody}>
              {textRow(testidPrefix, 'image_height', 'layout_editor.page_settings.seo.og_image_height', ogVals, setOg, ogMap)}
              {textRow(testidPrefix, 'image_type', 'layout_editor.page_settings.seo.og_image_type', ogVals, setOg, ogMap)}
              {textRow(testidPrefix, 'image_secure_url', 'layout_editor.page_settings.seo.og_image_secure_url', ogVals, setOg, ogMap)}
            </div>
          ) : null}
        </div>
        {textRow(testidPrefix, 'site_name', 'layout_editor.page_settings.seo.og_site_name', ogVals, setOg, ogMap)}
        {textRow(testidPrefix, 'locale', 'layout_editor.page_settings.seo.og_locale', ogVals, setOg, ogMap)}
        <div data-testid={`${testidPrefix}-extra`} style={fieldRow}>
          <label style={fieldLabel}>{t('layout_editor.page_settings.seo.og_extra')}</label>
          <KeyValueChipEditor
            value={Array.isArray(ogVals.extra) ? (ogVals.extra as KeyValueExtraItem[]) : []}
            onChange={(next) => setOg('extra', next.length > 0 ? next : undefined)}
            keyField="property"
            t={t}
            candidates={candidates}
            testidPrefix={`${testidPrefix}-extra-editor`}
          />
        </div>
      </div>

      {/* ── Twitter ── */}
      <div data-testid={`${testidPrefix}-tw-section`} style={section}>
        <div style={sectionTitle}>{t('layout_editor.page_settings.seo.tw_section')}</div>
        <p style={{ margin: '0 0 6px', fontSize: 11, color: '#94a3b8' }}>{t('layout_editor.page_settings.seo.tw_fallback_note')}</p>
        {selectRow('g7le-seo-tw', 'card', 'layout_editor.page_settings.seo.tw_card', TW_CARD_OPTIONS, twVals, setTw, twMap)}
        {textRow('g7le-seo-tw', 'site', 'layout_editor.page_settings.seo.tw_site', twVals, setTw, twMap)}
        {textRow('g7le-seo-tw', 'creator', 'layout_editor.page_settings.seo.tw_creator', twVals, setTw, twMap)}
        {i18nRow('g7le-seo-tw', 'title', 'layout_editor.page_settings.seo.tw_title', twVals, setTw, twMap)}
        {i18nRow('g7le-seo-tw', 'description', 'layout_editor.page_settings.seo.tw_description', twVals, setTw, twMap)}
        {chipRow('g7le-seo-tw', 'image', 'layout_editor.page_settings.seo.tw_image', twVals, setTw, twMap)}
        {i18nRow('g7le-seo-tw', 'image_alt', 'layout_editor.page_settings.seo.tw_image_alt', twVals, setTw, twMap)}
        <div data-testid="g7le-seo-tw-extra" style={fieldRow}>
          <label style={fieldLabel}>{t('layout_editor.page_settings.seo.tw_extra')}</label>
          <KeyValueChipEditor
            value={Array.isArray(twVals.extra) ? (twVals.extra as KeyValueExtraItem[]) : []}
            onChange={(next) => setTw('extra', next.length > 0 ? next : undefined)}
            keyField="name"
            t={t}
            candidates={candidates}
            testidPrefix="g7le-seo-tw-extra-editor"
          />
        </div>
      </div>
    </div>
  );
}

const section: React.CSSProperties = { border: '1px solid #e2e8f0', borderRadius: 8, padding: 12, display: 'flex', flexDirection: 'column', gap: 10 };
const sectionTitle: React.CSSProperties = { fontSize: 13, fontWeight: 600, color: '#0f172a' };
const introNote: React.CSSProperties = { margin: 0, fontSize: 11, color: '#64748b', background: '#f8fafc', borderRadius: 6, padding: '6px 8px' };
const fieldRow: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4 };
const fieldLabel: React.CSSProperties = { fontSize: 12, color: '#475569' };
const labelRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6 };
const inlineSourceLabel: React.CSSProperties = { fontSize: 11, color: '#94a3b8' };
const textInput: React.CSSProperties = { padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, width: '100%', minWidth: 0, boxSizing: 'border-box' };
const lockedValue: React.CSSProperties = { padding: '5px 8px', fontSize: 12, border: '1px solid #e2e8f0', borderRadius: 6, background: '#f1f5f9', color: '#64748b' };
const sourceBadge: React.CSSProperties = { fontSize: 11, color: '#64748b' };
const lockBadge: React.CSSProperties = { fontSize: 11, color: '#7c3aed' };
const advToggle: React.CSSProperties = { alignSelf: 'flex-start', padding: '2px 0', fontSize: 12, fontWeight: 600, color: '#475569', background: 'transparent', border: 'none', cursor: 'pointer' };
const advBody: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 8, paddingLeft: 14, marginTop: 4, minWidth: 0 };
// 자동값 연결 칩 — "상품 이름" 같은 데이터 출처 칩 + 출처 배지 + 다른 데이터로 바꾸기.
const autoChipWrap: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', minWidth: 0 };
const autoChip: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', maxWidth: '100%', padding: '3px 8px', fontSize: 12, color: '#1d4ed8', background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 6, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' };
const autoChipBadge: React.CSSProperties = { fontSize: 11, color: '#94a3b8' };
const autoChipReplaceBtn: React.CSSProperties = { padding: '2px 8px', fontSize: 11, color: '#475569', background: '#fff', border: '1px solid #cbd5e1', borderRadius: 6, cursor: 'pointer' };
