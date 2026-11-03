/**
 * ScrollRestorationForm.tsx — 페이지 설정 [스크롤] 탭 폼 (meta.scroll_restoration)
 *
 * 뒤로/앞으로 가기·새로고침으로 화면에 돌아왔을 때의 스크롤 동작을 고친다. 칸마다 「비우면 상속」이다 —
 * 적용 순서는 엔진 기본값 < template.json `scroll_restoration`(제작자 배포 초깃값, 읽기 전용) < 베이스
 * 레이아웃 meta(편집기의 「템플릿 기본값」) < 화면 레이아웃 meta < 이동 액션 `returnScroll`.
 *
 * 이 폼은 이 레이아웃이 직접 적은 값(own)만 고친다. 상속값(베이스·템플릿·엔진)은 칸마다 출처 배지와 함께
 * 보여 주고, 저장 모양(own)의 비운 키는 지워 상위 값을 따르게 한다. 셸(PageSettingsModal)이 own 을
 * `__editor.original.meta` 에, 베이스와 합친 값을 병합본 meta 에 쓴다.
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(CSS 라이브러리 비종속).
 *
 * @since engine-v1.85.0
 */

import React, { useCallback, useState } from 'react';
import { DEFAULT_SCROLL_RESTORATION } from '../../../../routing/ScrollMemory';
import { normalizeScrollOwn, type ScrollRestorationValue } from './scrollRestorationValue';

export type { ScrollRestorationValue } from './scrollRestorationValue';

/** 값 출처 — 이 레이아웃 · 베이스 레이아웃 · template.json · 엔진 기본값 */
export type ScrollValueSource = 'own' | 'base' | 'template' | 'engine';

export interface ScrollRestorationFormProps {
  /** 이 레이아웃이 직접 적은 값(병합 전). 없으면 전부 상속 */
  own: ScrollRestorationValue | undefined;
  /** 베이스 레이아웃에서 물려받은 값(병합본 − own) */
  baseValue?: ScrollRestorationValue;
  /** template.json `scroll_restoration` (제작자 배포 초깃값) */
  templateValue?: ScrollRestorationValue;
  /** 베이스 레이아웃 편집 중 — 이 값이 템플릿 기본값이 된다 */
  isBaseLayout?: boolean;
  /** own 변경 (undefined = 키 삭제 → 전부 상속) */
  onChange: (next: ScrollRestorationValue | undefined) => void;
  /** 다국어 t (layout_editor.*) */
  t: (key: string, params?: Record<string, string | number>) => string;
}

/** 선택자 모드를 뜻하는 select 값 */
const SELECTOR_KIND = '__selector__';
const FIXED_MODES = ['restore', 'top', 'none'];
const TIMEOUT_MAX = 60000;
const TTL_MAX = 86400;
const SELECTOR_MAX = 200;
const ITEM_KEY_MAX = 100;
/** 스냅샷과 함께 되돌릴 상태 경로 형식 — 엔진 ScrollMemory·서버 UpdateLayoutContentRequest 와 같다 */
export const SNAPSHOT_STATE_PATH = /^_(global|local)\.[A-Za-z0-9_$][\w$.]*$/;
const STATE_PATH_MAX = 200;

const K = 'layout_editor.scroll_restoration';

function isPlainObject(v: unknown): v is Record<string, unknown> {
  return !!v && typeof v === 'object' && !Array.isArray(v);
}

/** 칸 하나의 실효 값과 출처 */
interface Resolved<T> {
  value: T;
  source: ScrollValueSource;
}

/**
 * 스크롤 복원 설정 폼.
 *
 * @param props ScrollRestorationFormProps
 * @return 폼 엘리먼트
 */
export function ScrollRestorationForm({
  own,
  baseValue,
  templateValue,
  isBaseLayout = false,
  onChange,
  t,
}: ScrollRestorationFormProps): React.ReactElement {
  const ownObj: ScrollRestorationValue = isPlainObject(own) ? own : {};
  const ownSnap = isPlainObject(ownObj.snapshot) ? ownObj.snapshot : {};
  // 선택자 모드를 골랐지만 아직 입력 전 — mode 키는 쓰지 않고(상속 유지) 입력칸만 연다.
  const [selectorPicked, setSelectorPicked] = useState(false);

  /** 최상위 키의 상속 실효 값(own 제외) */
  const resolveInherited = useCallback(
    <T,>(key: string): Resolved<T> => {
      if (isPlainObject(baseValue) && baseValue[key] !== undefined) {
        return { value: baseValue[key] as T, source: 'base' };
      }
      if (isPlainObject(templateValue) && templateValue[key] !== undefined) {
        return { value: templateValue[key] as T, source: 'template' };
      }
      return { value: (DEFAULT_SCROLL_RESTORATION as unknown as Record<string, unknown>)[key] as T, source: 'engine' };
    },
    [baseValue, templateValue],
  );

  /** snapshot 하위 키의 상속 실효 값(own 제외) */
  const resolveSnapInherited = useCallback(
    <T,>(key: string): Resolved<T> => {
      const baseSnap = isPlainObject(baseValue?.snapshot) ? (baseValue!.snapshot as Record<string, unknown>) : undefined;
      if (baseSnap && baseSnap[key] !== undefined) return { value: baseSnap[key] as T, source: 'base' };
      const tplSnap = isPlainObject(templateValue?.snapshot) ? (templateValue!.snapshot as Record<string, unknown>) : undefined;
      if (tplSnap && tplSnap[key] !== undefined) return { value: tplSnap[key] as T, source: 'template' };
      return {
        value: (DEFAULT_SCROLL_RESTORATION.snapshot as unknown as Record<string, unknown>)[key] as T,
        source: 'engine',
      };
    },
    [baseValue, templateValue],
  );

  /** 최상위 키 1건 설정/삭제 */
  const setKey = useCallback(
    (key: string, value: unknown): void => {
      const next: ScrollRestorationValue = { ...ownObj };
      if (value === undefined) delete next[key];
      else next[key] = value;
      onChange(normalizeScrollOwn(next));
    },
    [ownObj, onChange],
  );

  /** snapshot 하위 키 1건 설정/삭제 */
  const setSnapKey = useCallback(
    (key: string, value: unknown): void => {
      const snap: Record<string, unknown> = { ...ownSnap };
      if (value === undefined) delete snap[key];
      else snap[key] = value;
      onChange(normalizeScrollOwn({ ...ownObj, snapshot: snap as ScrollRestorationValue['snapshot'] }));
    },
    [ownObj, ownSnap, onChange],
  );

  const sourceLabel = (source: ScrollValueSource): string => t(`${K}.source.${source}`);

  const modeLabel = (mode: unknown): string => {
    if (typeof mode !== 'string' || mode === '') return '';
    return FIXED_MODES.includes(mode) ? t(`${K}.mode_${mode}`) : mode;
  };
  const boolLabel = (v: unknown): string => (v === true ? t(`${K}.on`) : v === false ? t(`${K}.off`) : '');
  const waitLabel = (v: unknown): string => (v === 'height' || v === 'data' ? t(`${K}.wait_${v}`) : String(v ?? ''));
  const inheritOption = (display: string): string => t(`${K}.inherit_option`, { value: display });

  // ── mode ──
  const ownMode = typeof ownObj.mode === 'string' ? ownObj.mode : undefined;
  const modeKind =
    ownMode === undefined ? (selectorPicked ? SELECTOR_KIND : '') : FIXED_MODES.includes(ownMode) ? ownMode : SELECTOR_KIND;
  const inheritedMode = resolveInherited<string>('mode');

  const onModeKind = (kind: string): void => {
    if (kind === SELECTOR_KIND) {
      setSelectorPicked(true);
      // 이미 선택자 값이면 유지, 고정 모드였으면 지우고 입력을 기다린다(빈 선택자는 저장하지 않음).
      if (ownMode !== undefined && FIXED_MODES.includes(ownMode)) setKey('mode', undefined);
      return;
    }
    setSelectorPicked(false);
    setKey('mode', kind === '' ? undefined : kind);
  };

  // ── 숫자 칸 ──
  const parseBounded = (raw: string, max: number): number | undefined | null => {
    if (raw.trim() === '') return undefined;
    const n = Number(raw);
    if (!Number.isFinite(n)) return null;
    return Math.min(max, Math.max(0, Math.round(n)));
  };

  // ── 목록 칸(containers · snapshot.state) — own 이 있으면 편집, 없으면 상속 목록 읽기 전용 ──
  const ownContainers = Array.isArray(ownObj.containers) ? (ownObj.containers as string[]) : undefined;
  const inheritedContainers = resolveInherited<string[]>('containers');
  const ownStates = Array.isArray(ownSnap.state) ? (ownSnap.state as string[]) : undefined;
  const inheritedStates = resolveSnapInherited<string[]>('state');

  const hasOwn = Object.keys(ownObj).length > 0;

  return (
    <div className="g7le-scroll-form" data-testid="g7le-scroll-form" style={form}>
      <p style={introText}>{t(`${K}.intro`)}</p>
      {isBaseLayout ? (
        <p data-testid="g7le-scroll-banner-base" style={banner}>
          ⓘ {t(`${K}.banner_base`)}
        </p>
      ) : (
        <p data-testid="g7le-scroll-banner-route" style={banner}>
          ⓘ {t(`${K}.banner_route`)}
        </p>
      )}

      {/* 돌아왔을 때 동작 */}
      <Field
        label={t(`${K}.mode_label`)}
        testidKey="mode"
        source={ownMode !== undefined ? 'own' : inheritedMode.source}
        sourceLabel={sourceLabel}
        isBaseLayout={isBaseLayout}
        t={t}
        hint={t(`${K}.mode_hint`)}
      >
        <select
          data-testid="g7le-scroll-mode"
          value={modeKind}
          onChange={(e) => onModeKind(e.target.value)}
          style={selectStyle}
        >
          <option value="">{inheritOption(modeLabel(inheritedMode.value))}</option>
          <option value="restore">{t(`${K}.mode_restore`)}</option>
          <option value="top">{t(`${K}.mode_top`)}</option>
          <option value="none">{t(`${K}.mode_none`)}</option>
          <option value={SELECTOR_KIND}>{t(`${K}.mode_selector`)}</option>
        </select>
        {modeKind === SELECTOR_KIND ? (
          <input
            type="text"
            data-testid="g7le-scroll-mode-selector"
            value={ownMode !== undefined && !FIXED_MODES.includes(ownMode) ? ownMode : ''}
            maxLength={SELECTOR_MAX}
            placeholder={t(`${K}.mode_selector_placeholder`)}
            onChange={(e) => {
              const v = e.target.value.trim();
              setKey('mode', v === '' ? undefined : v);
            }}
            style={textInput}
          />
        ) : null}
      </Field>

      {/* 새로고침 때도 */}
      <Field
        label={t(`${K}.on_reload_label`)}
        testidKey="on_reload"
        source={typeof ownObj.on_reload === 'boolean' ? 'own' : resolveInherited('on_reload').source}
        sourceLabel={sourceLabel}
        isBaseLayout={isBaseLayout}
        t={t}
        hint={t(`${K}.on_reload_hint`)}
      >
        <BoolSelect
          testid="g7le-scroll-on-reload"
          value={typeof ownObj.on_reload === 'boolean' ? ownObj.on_reload : undefined}
          inheritText={inheritOption(boolLabel(resolveInherited('on_reload').value))}
          onLabel={t(`${K}.on`)}
          offLabel={t(`${K}.off`)}
          onChange={(v) => setKey('on_reload', v)}
        />
      </Field>

      {/* 복원 시점 */}
      <Field
        label={t(`${K}.wait_label`)}
        testidKey="wait"
        source={ownObj.wait === 'height' || ownObj.wait === 'data' ? 'own' : resolveInherited('wait').source}
        sourceLabel={sourceLabel}
        isBaseLayout={isBaseLayout}
        t={t}
        hint={t(`${K}.wait_hint`)}
      >
        <select
          data-testid="g7le-scroll-wait"
          value={ownObj.wait === 'height' || ownObj.wait === 'data' ? ownObj.wait : ''}
          onChange={(e) => setKey('wait', e.target.value === '' ? undefined : e.target.value)}
          style={selectStyle}
        >
          <option value="">{inheritOption(waitLabel(resolveInherited('wait').value))}</option>
          <option value="height">{t(`${K}.wait_height`)}</option>
          <option value="data">{t(`${K}.wait_data`)}</option>
        </select>
      </Field>

      {/* 최대 대기 */}
      <Field
        label={t(`${K}.timeout_label`)}
        testidKey="timeout_ms"
        source={typeof ownObj.timeout_ms === 'number' ? 'own' : resolveInherited('timeout_ms').source}
        sourceLabel={sourceLabel}
        isBaseLayout={isBaseLayout}
        t={t}
        hint={t(`${K}.timeout_hint`, { max: TIMEOUT_MAX })}
      >
        <input
          type="number"
          data-testid="g7le-scroll-timeout"
          min={0}
          max={TIMEOUT_MAX}
          step={500}
          value={typeof ownObj.timeout_ms === 'number' ? String(ownObj.timeout_ms) : ''}
          placeholder={String(resolveInherited<number>('timeout_ms').value ?? '')}
          onChange={(e) => {
            const n = parseBounded(e.target.value, TIMEOUT_MAX);
            if (n !== null) setKey('timeout_ms', n);
          }}
          style={numberInput}
        />
      </Field>

      {/* 함께 복원할 내부 스크롤 영역 */}
      <Field
        label={t(`${K}.containers_label`)}
        testidKey="containers"
        source={ownContainers !== undefined ? 'own' : inheritedContainers.source}
        sourceLabel={sourceLabel}
        isBaseLayout={isBaseLayout}
        t={t}
        hint={t(`${K}.containers_hint`)}
      >
        <ListEditor
          testidItem="g7le-scroll-container"
          testidGroup="g7le-scroll-containers"
          own={ownContainers}
          inherited={Array.isArray(inheritedContainers.value) ? inheritedContainers.value : []}
          maxLength={SELECTOR_MAX}
          placeholder={t(`${K}.containers_add_placeholder`)}
          onChange={(next) => setKey('containers', next)}
          t={t}
        />
      </Field>

      {/* 더보기 목록 스냅샷 */}
      <fieldset data-testid="g7le-scroll-snapshot" style={fieldset}>
        <legend style={legend}>{t(`${K}.snapshot_title`)}</legend>
        <p style={hintText}>ⓘ {t(`${K}.snapshot_hint`)}</p>

        <Field
          label={t(`${K}.snapshot_enabled_label`)}
          testidKey="snapshot_enabled"
          source={typeof ownSnap.enabled === 'boolean' ? 'own' : resolveSnapInherited('enabled').source}
          sourceLabel={sourceLabel}
          isBaseLayout={isBaseLayout}
          t={t}
        >
          <BoolSelect
            testid="g7le-scroll-snapshot-enabled"
            value={typeof ownSnap.enabled === 'boolean' ? ownSnap.enabled : undefined}
            inheritText={inheritOption(boolLabel(resolveSnapInherited('enabled').value))}
            onLabel={t(`${K}.on`)}
            offLabel={t(`${K}.off`)}
            onChange={(v) => setSnapKey('enabled', v)}
          />
        </Field>

        <Field
          label={t(`${K}.snapshot_ttl_label`)}
          testidKey="snapshot_ttl_seconds"
          source={typeof ownSnap.ttl_seconds === 'number' ? 'own' : resolveSnapInherited('ttl_seconds').source}
          sourceLabel={sourceLabel}
          isBaseLayout={isBaseLayout}
          t={t}
          hint={t(`${K}.snapshot_ttl_hint`, { max: TTL_MAX })}
        >
          <input
            type="number"
            data-testid="g7le-scroll-snapshot-ttl"
            min={0}
            max={TTL_MAX}
            step={60}
            value={typeof ownSnap.ttl_seconds === 'number' ? String(ownSnap.ttl_seconds) : ''}
            placeholder={String(resolveSnapInherited<number>('ttl_seconds').value ?? '')}
            onChange={(e) => {
              const n = parseBounded(e.target.value, TTL_MAX);
              if (n !== null) setSnapKey('ttl_seconds', n);
            }}
            style={numberInput}
          />
        </Field>

        <Field
          label={t(`${K}.snapshot_revalidate_label`)}
          testidKey="snapshot_revalidate"
          source={typeof ownSnap.revalidate === 'boolean' ? 'own' : resolveSnapInherited('revalidate').source}
          sourceLabel={sourceLabel}
          isBaseLayout={isBaseLayout}
          t={t}
          hint={t(`${K}.snapshot_revalidate_hint`)}
        >
          <BoolSelect
            testid="g7le-scroll-snapshot-revalidate"
            value={typeof ownSnap.revalidate === 'boolean' ? ownSnap.revalidate : undefined}
            inheritText={inheritOption(boolLabel(resolveSnapInherited('revalidate').value))}
            onLabel={t(`${K}.on`)}
            offLabel={t(`${K}.off`)}
            onChange={(v) => setSnapKey('revalidate', v)}
          />
        </Field>

        <Field
          label={t(`${K}.snapshot_item_key_label`)}
          testidKey="snapshot_item_key"
          source={typeof ownSnap.item_key === 'string' ? 'own' : resolveSnapInherited('item_key').source}
          sourceLabel={sourceLabel}
          isBaseLayout={isBaseLayout}
          t={t}
          hint={t(`${K}.snapshot_item_key_hint`)}
        >
          <input
            type="text"
            data-testid="g7le-scroll-snapshot-item-key"
            value={typeof ownSnap.item_key === 'string' ? ownSnap.item_key : ''}
            maxLength={ITEM_KEY_MAX}
            placeholder={String(resolveSnapInherited<string>('item_key').value ?? '')}
            onChange={(e) => {
              const v = e.target.value.trim();
              setSnapKey('item_key', v === '' ? undefined : v);
            }}
            style={textInput}
          />
        </Field>

        <Field
          label={t(`${K}.snapshot_state_label`)}
          testidKey="snapshot_state"
          source={ownStates !== undefined ? 'own' : inheritedStates.source}
          sourceLabel={sourceLabel}
          isBaseLayout={isBaseLayout}
          t={t}
          hint={t(`${K}.snapshot_state_hint`)}
        >
          <ListEditor
            testidItem="g7le-scroll-state"
            testidGroup="g7le-scroll-states"
            own={ownStates}
            inherited={Array.isArray(inheritedStates.value) ? inheritedStates.value : []}
            maxLength={STATE_PATH_MAX}
            placeholder={t(`${K}.snapshot_state_placeholder`)}
            validate={(v) => (SNAPSHOT_STATE_PATH.test(v) ? null : t(`${K}.snapshot_state_invalid`))}
            onChange={(next) => setSnapKey('state', next)}
            t={t}
          />
        </Field>
      </fieldset>

      {hasOwn ? (
        <button
          type="button"
          data-testid="g7le-scroll-reset-all"
          onClick={() => {
            setSelectorPicked(false);
            onChange(undefined);
          }}
          style={resetBtn}
        >
          ⟲ {t(isBaseLayout ? `${K}.reset_all_base` : `${K}.reset_all`)}
        </button>
      ) : null}
    </div>
  );
}

/** 칸 한 줄 — 라벨 + 출처 배지 + 입력 + 안내 */
function Field({
  label,
  testidKey,
  source,
  sourceLabel,
  isBaseLayout,
  t,
  hint,
  children,
}: {
  label: string;
  testidKey: string;
  source: ScrollValueSource;
  sourceLabel: (s: ScrollValueSource) => string;
  isBaseLayout: boolean;
  t: ScrollRestorationFormProps['t'];
  hint?: string;
  children: React.ReactNode;
}): React.ReactElement {
  const badgeText =
    source === 'own'
      ? t(isBaseLayout ? `${K}.own_badge_base` : `${K}.own_badge`)
      : t(`${K}.inherited_badge`, { source: sourceLabel(source) });
  return (
    <div data-testid={`g7le-scroll-field-${testidKey}`} style={fieldWrap}>
      <div style={labelRow}>
        <span style={fieldLabel}>{label}</span>
        <span
          data-testid={`g7le-scroll-source-${testidKey}`}
          data-source={source}
          style={source === 'own' ? ownBadge : inheritedBadge}
        >
          〔{badgeText}〕
        </span>
      </div>
      <div style={controlCol}>{children}</div>
      {hint ? <p style={hintText}>ⓘ {hint}</p> : null}
    </div>
  );
}

/**
 * 문자열 목록 칸 — own 이 없으면 상속 목록을 읽기 전용으로 보이고 「따로 정하기」로 복사해 편집을 시작한다.
 * own 이 있으면 추가·삭제·되돌리기(키 삭제). 빈 목록도 「이 레이아웃은 없음」이라는 지정이다.
 */
function ListEditor({
  testidItem,
  testidGroup,
  own,
  inherited,
  maxLength,
  placeholder,
  validate,
  onChange,
  t,
}: {
  testidItem: string;
  testidGroup: string;
  own: string[] | undefined;
  inherited: string[];
  maxLength: number;
  placeholder: string;
  /** 입력값 검사 — 오류 문구 또는 null */
  validate?: (value: string) => string | null;
  onChange: (next: string[] | undefined) => void;
  t: ScrollRestorationFormProps['t'];
}): React.ReactElement {
  const [draft, setDraft] = useState('');
  const [error, setError] = useState<string | null>(null);
  const shown = own ?? inherited;

  const add = (): void => {
    const value = draft.trim().slice(0, maxLength);
    if (value === '') return;
    const problem = validate ? validate(value) : null;
    if (problem) {
      setError(problem);
      return;
    }
    setError(null);
    const list = own ?? [];
    if (!list.includes(value)) onChange([...list, value]);
    setDraft('');
  };

  return (
    <>
      {shown.length === 0 ? (
        <span data-testid={`${testidGroup}-empty`} style={emptyText}>
          {t(`${K}.list_empty`)}
        </span>
      ) : (
        <ul style={chipList}>
          {shown.map((item, i) => (
            <li key={`${item}-${i}`} data-testid={`${testidItem}-${i}`} style={chip(own !== undefined)}>
              <code style={chipCode}>{item}</code>
              {own !== undefined ? (
                <button
                  type="button"
                  data-testid={`${testidItem}-remove-${i}`}
                  aria-label={t(`${K}.list_remove`)}
                  title={t(`${K}.list_remove`)}
                  onClick={() => onChange(own.filter((_, j) => j !== i))}
                  style={chipRemove}
                >
                  ✕
                </button>
              ) : null}
            </li>
          ))}
        </ul>
      )}
      {own !== undefined ? (
        <>
          <div style={row}>
            <input
              type="text"
              data-testid={`${testidItem}-input`}
              value={draft}
              maxLength={maxLength}
              placeholder={placeholder}
              aria-invalid={error ? true : undefined}
              onChange={(e) => {
                setDraft(e.target.value);
                if (error) setError(null);
              }}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault();
                  add();
                }
              }}
              style={textInput}
            />
            <button type="button" data-testid={`${testidItem}-add`} onClick={add} style={smallBtn}>
              {t(`${K}.list_add`)}
            </button>
            <button type="button" data-testid={`${testidGroup}-revert`} onClick={() => onChange(undefined)} style={smallBtn}>
              ↩ {t(`${K}.revert`)}
            </button>
          </div>
          {error ? (
            <p data-testid={`${testidItem}-error`} role="alert" style={errorText}>
              {error}
            </p>
          ) : null}
        </>
      ) : (
        <button
          type="button"
          data-testid={`${testidGroup}-override`}
          onClick={() => onChange([...shown])}
          style={smallBtn}
        >
          {t(`${K}.list_override`)}
        </button>
      )}
    </>
  );
}

/** 상속/켬/끔 3상태 선택 — 비우면 키를 지운다 */
function BoolSelect({
  testid,
  value,
  inheritText,
  onLabel,
  offLabel,
  onChange,
}: {
  testid: string;
  value: boolean | undefined;
  inheritText: string;
  onLabel: string;
  offLabel: string;
  onChange: (v: boolean | undefined) => void;
}): React.ReactElement {
  return (
    <select
      data-testid={testid}
      value={value === true ? 'true' : value === false ? 'false' : ''}
      onChange={(e) => onChange(e.target.value === '' ? undefined : e.target.value === 'true')}
      style={selectStyle}
    >
      <option value="">{inheritText}</option>
      <option value="true">{onLabel}</option>
      <option value="false">{offLabel}</option>
    </select>
  );
}

const form: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 14, minWidth: 0 };
const introText: React.CSSProperties = { margin: 0, fontSize: 12, color: '#475569' };
const banner: React.CSSProperties = { margin: 0, fontSize: 12, color: '#1d4ed8', background: '#eff6ff', padding: '8px 10px', borderRadius: 6 };
const fieldWrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6, minWidth: 0 };
const labelRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', minWidth: 0 };
const fieldLabel: React.CSSProperties = { fontSize: 13, fontWeight: 600, color: '#0f172a' };
const controlCol: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6, minWidth: 0 };
const hintText: React.CSSProperties = { margin: 0, fontSize: 11, color: '#94a3b8' };
const emptyText: React.CSSProperties = { fontSize: 12, color: '#94a3b8' };
const errorText: React.CSSProperties = { margin: 0, fontSize: 11, color: '#b91c1c' };
const inheritedBadge: React.CSSProperties = { fontSize: 11, color: '#64748b', background: '#f1f5f9', borderRadius: 4, padding: '1px 6px' };
const ownBadge: React.CSSProperties = { fontSize: 11, color: '#0369a1', background: '#e0f2fe', borderRadius: 4, padding: '1px 6px' };
const selectStyle: React.CSSProperties = { maxWidth: 360, padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff' };
const textInput: React.CSSProperties = { flex: 1, minWidth: 0, maxWidth: 360, padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, boxSizing: 'border-box' };
const numberInput: React.CSSProperties = { width: 120, padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6 };
const row: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap', minWidth: 0 };
const chipList: React.CSSProperties = { display: 'flex', flexWrap: 'wrap', gap: 6, margin: 0, padding: 0, listStyle: 'none' };
const chip = (editable: boolean): React.CSSProperties => ({
  display: 'inline-flex',
  alignItems: 'center',
  gap: 4,
  padding: '2px 8px',
  fontSize: 12,
  border: `1px solid ${editable ? '#93c5fd' : '#e2e8f0'}`,
  borderRadius: 12,
  background: editable ? '#eff6ff' : '#f8fafc',
  color: editable ? '#1e3a8a' : '#64748b',
});
const chipCode: React.CSSProperties = { fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace', fontSize: 11 };
const chipRemove: React.CSSProperties = { border: 'none', background: 'transparent', color: '#64748b', cursor: 'pointer', fontSize: 11, padding: 0, lineHeight: 1 };
const smallBtn: React.CSSProperties = { alignSelf: 'flex-start', padding: '4px 10px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer', whiteSpace: 'nowrap' };
const fieldset: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 12, margin: 0, padding: '10px 12px', border: '1px solid #e2e8f0', borderRadius: 8, minWidth: 0 };
const legend: React.CSSProperties = { fontSize: 13, fontWeight: 700, color: '#0f172a', padding: '0 4px' };
const resetBtn: React.CSSProperties = { alignSelf: 'flex-start', padding: '6px 12px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
