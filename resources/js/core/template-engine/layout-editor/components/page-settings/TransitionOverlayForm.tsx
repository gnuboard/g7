/**
 * TransitionOverlayForm.tsx — [로딩 화면] 탭 본체
 *
 * 레이아웃 최상위 `transition_overlay` 를 친화 편집한다. 유저가 하는 일 = ① 켤지/끌지
 * (enabled) ② 어디를 덮을지(target — 전체/특정영역, ComponentTargetPicker) ③ 어떻게 보일지
 * (style 5종 + 스타일별 부가옵션) ④ 무엇을 기다릴지(wait_for — progressive 데이터소스만).
 *
 * 불리언 간편형(`transition_overlay: true`)은 편집 시 객체로 정규화하되 원형은 patch 의
 * originalValue 로 보존(무손실). base 상속 표기 = shallow merge 정합:
 * base 만 정의한 필드는 〔상속됨〕(회색), 자식 override 필드는 편집 활성. "[이 화면만 바꾸기]"
 * = 그 키를 자식 객체에 추가, "[모두 기본값으로]" = 자식 transition_overlay 키 전체 삭제.
 *
 * target/fallback_target picker 는 `ComponentTargetPicker`(코어 위젯) 인스턴스화.
 * 로딩 컴포넌트(skeleton/spinner.component)는 `LoadingComponentPicker`.
 *
 * 본 폼은 prop 주도 — 셸이 transition_overlay 값·base 값·후보를 주입한다.
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만.
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useState } from 'react';
import { getWidget } from '../../spec/widgetRegistry';
import { LoadingComponentPicker } from './LoadingComponentPicker';
import { ToggleSwitch, DisabledFieldset } from './FormPrimitives';
import type { LoadingComponentSpec } from '../../spec/specTypes';
import type { DataSourceOption } from '../../spec/candidatePools';
import { DataSourceChipLabel } from './DataSourceChipLabel';
import { I18nTextField } from '../property-controls/I18nTextField';
import type { BindingCandidate } from '../../spec/bindingCandidates';

/** transition_overlay 객체 shape(편집 대상 필드만) */
export interface TransitionOverlayValue {
  enabled?: boolean;
  target?: string;
  fallback_target?: string;
  style?: 'opaque' | 'blur' | 'fade' | 'skeleton' | 'spinner' | string;
  skeleton?: { component?: string; animation?: string; iteration_count?: number };
  spinner?: { component?: string; text?: string };
  wait_for?: string[];
  [key: string]: unknown;
}

export interface TransitionOverlayFormProps {
  /**
   * 편집 중 transition_overlay 값(병합본 — base 상속분 포함, 불리언 간편형 가능).
   * 화면 표시(현재 effective 값)는 이 병합본을 쓴다. usePageSettings.getValue('transition_overlay').
   */
  value: TransitionOverlayValue | boolean | undefined;
  /** 원형 보존용 — patch originalValue 에 흘림(불리언 간편형 무손실) */
  patch: (value: TransitionOverlayValue | undefined) => void;
  /** 다국어 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /**
   * base(부모)가 정의한 transition_overlay 중 이 레이아웃이 덮지 않은 키 = 상속 키.
   * 〔상속됨〕 표기·[이 화면만 바꾸기] 복사 기준. 셸이 (병합본 − own) 으로 도출해 주입한다.
   * 미전달/빈 객체면 상속 표기 없음(독립 레이아웃·부모 미정의).
   */
  baseValue?: TransitionOverlayValue;
  /**
   * 이 레이아웃이 직접 선언한 transition_overlay(base 병합 전, `__editor.original`).
   * 상속/재정의 판정(fieldInherited)의 기준 — 병합본(value)이 아닌 own 에 그 키가 있어야 재정의.
   * 미전달 시 value(병합본)를 own 으로 본다(독립 RTL 하위호환).
   */
  ownValue?: TransitionOverlayValue;
  /** 로딩 컴포넌트 후보(editorSpecLoader.getLoadingComponents) */
  loadingComponents?: LoadingComponentSpec[];
  /** progressive 데이터소스 후보(wait_for) — id + 친화명 + 확장 출처 배지 */
  progressiveDataSources?: DataSourceOption[];
  /**
   * 데이터 칩 후보 풀. spinner 텍스트
   * I18nTextField 의 `+데이터` 칩/표현식 진입에 쓴다. 미전달 시 다국어 키화·평문은 동작(칩만 숨음).
   */
  bindingCandidates?: BindingCandidate[];
}

/** 불리언 간편형 → 객체 정규화 */
function normalize(value: TransitionOverlayValue | boolean | undefined): TransitionOverlayValue {
  if (value === true) return { enabled: true };
  if (value === false || value === undefined) return {};
  return value;
}

const STYLES: Array<TransitionOverlayValue['style']> = ['opaque', 'blur', 'fade', 'skeleton', 'spinner'];

/**
 * [로딩 화면] 탭 폼.
 *
 * @param props TransitionOverlayFormProps
 * @return 로딩 화면 폼 엘리먼트
 */
export function TransitionOverlayForm({
  value,
  patch,
  t,
  baseValue,
  ownValue,
  loadingComponents = [],
  progressiveDataSources = [],
  bindingCandidates,
}: TransitionOverlayFormProps): React.ReactElement {
  const ov = normalize(value);
  const enabled = ov.enabled ?? false;
  const hasInheritance = !!baseValue && Object.keys(baseValue).length > 0;
  const TargetPicker = getWidget('component-target-picker');
  // wait_for 는 후방·접이식(와이어프레임 ▸). 선택값이 있으면 기본 펼침(놓치지 않게).
  const [waitForOpen, setWaitForOpen] = useState<boolean>((ov.wait_for ?? []).length > 0);

  /** transition_overlay 의 한 필드 패치(원형 보존) */
  const patchField = useCallback(
    (mut: (next: TransitionOverlayValue) => void): void => {
      const next: TransitionOverlayValue = { ...normalize(value) };
      mut(next);
      patch(next);
    },
    [value, patch],
  );

  // 필드별 상속 판정(와이어프레임 L1979 — 〔상속됨〕/〔재정의〕 + [이 화면만 바꾸기]).
  // base 가 그 필드를 정의했고 자식 원본(value)에 그 키가 없으면 = 상속(회색·읽기전용).
  // 자식이 override 했으면 = 재정의(편집 활성). [이 화면만 바꾸기]는 base 값을 자식에 복사.
  // 재정의 판정 기준 = 이 레이아웃 own(병합 전). 미전달 시 병합본(value)으로 폴백(하위호환).
  const childRaw = (ownValue && typeof ownValue === 'object'
    ? ownValue
    : (value && typeof value === 'object' ? value : {})) as TransitionOverlayValue;
  const fieldInherited = useCallback(
    (key: string): boolean => hasInheritance && baseValue !== undefined && key in baseValue && !(key in childRaw),
    [hasInheritance, baseValue, childRaw],
  );
  /** [이 화면만 바꾸기] — base 값을 자식 키로 복사해 override 시작 */
  const overrideField = useCallback(
    (key: string): void => {
      if (!baseValue) return;
      patchField((next) => { (next as Record<string, unknown>)[key] = baseValue[key]; });
    },
    [baseValue, patchField],
  );

  const setEnabled = useCallback(
    (on: boolean): void => patchField((next) => { next.enabled = on; }),
    [patchField],
  );

  // 덮기 범위 — `target` **키 존재 여부**로 판정한다(D-I). 특정 영역을 고르면 target 키를
  // 빈 문자열로라도 세팅하고(아직 ID 미선택 단계), 전체로 되돌리면 target 키를 삭제한다.
  // 종전엔 `target !== ''` 로 판정해 특정 영역 클릭이 `target=''` 를 세팅해도 scope 가
  // 여전히 'full' 로 남아 picker 가 안 나타나던 결함(특정 영역 무반응).
  const scope = ov.target !== undefined ? 'region' : 'full';

  return (
    <div className="g7le-overlay-form" data-testid="g7le-overlay-form" style={form}>
      {hasInheritance ? (
        <p data-testid="g7le-overlay-inherit-banner" style={inheritBanner}>
          ⓘ {t('layout_editor.overlay.inherit_banner')}
        </p>
      ) : null}

      {/* 전역 활성 토글 */}
      <div style={fieldWrap}>
        <FieldInheritRow
          label={t('layout_editor.overlay.enabled_label')}
          inherited={fieldInherited('enabled')}
          hasInheritance={hasInheritance}
          onOverride={() => overrideField('enabled')}
          testidKey="enabled"
          t={t}
        />
        <DisabledFieldset disabled={fieldInherited('enabled')}>
          <ToggleSwitch
            checked={enabled}
            onChange={setEnabled}
            testid="g7le-overlay-enabled"
            ariaLabel={t('layout_editor.overlay.enabled_label')}
            label={enabled ? t('layout_editor.overlay.on') : t('layout_editor.overlay.off')}
          />
        </DisabledFieldset>
        <p style={fieldHint}>ⓘ {t('layout_editor.overlay.enabled_hint')}</p>
      </div>

      {/* OFF 일 때도 숨기지 않고 회색 비활성으로 항상 표시(D-M). */}
      <DisabledFieldset disabled={!enabled} testid="g7le-overlay-body" style={{ display: 'flex', flexDirection: 'column', gap: 16, minWidth: 0 }}>
        <>
          {/* 덮기 범위 */}
          <div style={fieldWrap}>
            <FieldInheritRow
              label={t('layout_editor.overlay.scope_label')}
              inherited={fieldInherited('target')}
              hasInheritance={hasInheritance}
              onOverride={() => overrideField('target')}
              testidKey="target"
              t={t}
            />
            <div data-testid="g7le-overlay-scope" style={segment}>
              <SegBtn active={scope === 'full'} onClick={() => patchField((n) => { delete n.target; delete n.fallback_target; })} testid="g7le-overlay-scope-full">
                {t('layout_editor.overlay.scope_full')}
              </SegBtn>
              <SegBtn active={scope === 'region'} onClick={() => patchField((n) => { n.target = n.target ?? ''; })} testid="g7le-overlay-scope-region">
                {t('layout_editor.overlay.scope_region')}
              </SegBtn>
            </div>
            {/* picker 도 숨김 금지 — scope 가 region 이 아니면 회색 비활성(D-M). */}
            <DisabledFieldset disabled={scope !== 'region'} testid="g7le-overlay-region-box" style={regionBox}>
              <>
                {/* 표시 위치 라벨 + picker(직접 입력칸 + 🎯). 라벨은 와이어프레임 L1946. */}
                <SubField label={t('layout_editor.overlay.target_label')}>
                  <div data-testid="g7le-overlay-target-picker">
                    {TargetPicker ? (
                      <TargetPicker
                        control={{ label: t('layout_editor.overlay.target_label') }}
                        value={ov.target ?? ''}
                        onChange={(id) => patchField((n) => { n.target = typeof id === 'string' ? id : ''; })}
                        t={t}
                      />
                    ) : (
                      <input
                        type="text"
                        data-testid="g7le-overlay-target-input"
                        value={ov.target ?? ''}
                        onChange={(e) => patchField((n) => { n.target = e.target.value; })}
                        style={textInput}
                      />
                    )}
                  </div>
                </SubField>
                {/* 대체 위치 라벨 + picker. */}
                <SubField label={t('layout_editor.overlay.fallback_label')}>
                  <div data-testid="g7le-overlay-fallback-picker">
                    {TargetPicker ? (
                      <TargetPicker
                        control={{ label: t('layout_editor.overlay.fallback_label') }}
                        value={ov.fallback_target ?? ''}
                        onChange={(id) => patchField((n) => {
                          const v = typeof id === 'string' ? id : '';
                          if (v === '') delete n.fallback_target;
                          else n.fallback_target = v;
                        })}
                        t={t}
                      />
                    ) : null}
                  </div>
                </SubField>
                <p style={fieldHint}>ⓘ {t('layout_editor.overlay.fallback_hint')}</p>
              </>
            </DisabledFieldset>
          </div>

          {/* 스타일 */}
          <div style={fieldWrap}>
            <FieldInheritRow
              label={t('layout_editor.overlay.style_label')}
              inherited={fieldInherited('style')}
              hasInheritance={hasInheritance}
              onOverride={() => overrideField('style')}
              testidKey="style"
              t={t}
            />
            <div data-testid="g7le-overlay-style" style={segment}>
              {STYLES.map((s) => (
                <SegBtn
                  key={s}
                  active={(ov.style ?? 'opaque') === s}
                  onClick={() => patchField((n) => { n.style = s; })}
                  testid={`g7le-overlay-style-${s}`}
                >
                  {t(`layout_editor.overlay.style_${s}`)}
                </SegBtn>
              ))}
            </div>

            {/* 스타일별 부가옵션 */}
            {ov.style === 'skeleton' ? (
              <div style={subBox}>
                <SubField label={t('layout_editor.overlay.skeleton_component')}>
                  <LoadingComponentPicker
                    candidates={loadingComponents}
                    value={ov.skeleton?.component}
                    styleKind="skeleton"
                    onSelect={(name) => patchField((n) => { n.skeleton = { ...n.skeleton, component: name }; })}
                    t={t}
                    testIdPrefix="g7le-overlay-skeleton-component"
                  />
                </SubField>
                <SubField label={t('layout_editor.overlay.skeleton_animation')}>
                  <div data-testid="g7le-overlay-skeleton-anim" style={segment}>
                    {['wave', 'pulse', 'none'].map((a) => (
                      <SegBtn
                        key={a}
                        active={(ov.skeleton?.animation ?? 'wave') === a}
                        onClick={() => patchField((n) => { n.skeleton = { ...n.skeleton, animation: a }; })}
                        testid={`g7le-overlay-skeleton-anim-${a}`}
                      >
                        {t(`layout_editor.overlay.anim_${a}`)}
                      </SegBtn>
                    ))}
                  </div>
                </SubField>
                <SubField label={t('layout_editor.overlay.skeleton_count')}>
                  <input
                    type="number"
                    data-testid="g7le-overlay-skeleton-count"
                    value={ov.skeleton?.iteration_count ?? ''}
                    onChange={(e) => patchField((n) => {
                      const v = parseInt(e.target.value, 10);
                      n.skeleton = { ...n.skeleton, iteration_count: Number.isNaN(v) ? undefined : v };
                    })}
                    style={numberInput}
                  />
                </SubField>
              </div>
            ) : null}

            {ov.style === 'spinner' ? (
              <div style={subBox}>
                <SubField label={t('layout_editor.overlay.spinner_component')}>
                  <LoadingComponentPicker
                    candidates={loadingComponents}
                    value={ov.spinner?.component}
                    styleKind="spinner"
                    onSelect={(name) => patchField((n) => { n.spinner = { ...n.spinner, component: name }; })}
                    t={t}
                    testIdPrefix="g7le-overlay-spinner-component"
                  />
                </SubField>
                <SubField label={t('layout_editor.overlay.spinner_text')}>
                  {/* 로딩 표시 문구는 사용자에게 보이는 텍스트라 다국어(키화) +
                      데이터 칩 + 표현식. 제목/설명과 동일 I18nTextField(접힌 미리보기 + [수정]). 평문은
                      `$t:custom.*` 자동 키화, `{{...}}` 데이터·표현식은 칩/분해 트리. */}
                  <div data-testid="g7le-overlay-spinner-text" style={{ minWidth: 0 }}>
                    <I18nTextField
                      value={ov.spinner?.text ?? ''}
                      onChange={(v) => patchField((n) => {
                        n.spinner = { ...n.spinner, text: v === undefined || v === '' ? undefined : v };
                      })}
                      t={t}
                      candidates={bindingCandidates}
                      enableExpressionTree
                      expressionTreeCollapsible
                      testidPrefix="g7le-overlay-spinner-text-field"
                    />
                  </div>
                </SubField>
              </div>
            ) : null}
          </div>

          {/* 대기 데이터(wait_for) — 후방·접이식(▸). 와이어프레임 L1964. */}
          <div style={fieldWrap}>
            <button
              type="button"
              data-testid="g7le-overlay-waitfor-toggle"
              aria-expanded={waitForOpen}
              onClick={() => setWaitForOpen((v) => !v)}
              style={collapseToggle}
            >
              {waitForOpen ? '▾' : '▸'} {t('layout_editor.overlay.waitfor_label')}
            </button>
            {waitForOpen ? (
              <div data-testid="g7le-overlay-waitfor-body" style={collapseBody}>
                <p data-testid="g7le-overlay-waitfor-hint" style={fieldHint}>
                  ⓘ {t('layout_editor.overlay.waitfor_hint')}
                </p>
                {progressiveDataSources.length === 0 ? (
                  <p data-testid="g7le-overlay-waitfor-empty" style={fieldHint}>
                    {t('layout_editor.overlay.waitfor_empty')}
                  </p>
                ) : (
                  <div data-testid="g7le-overlay-waitfor" style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
                    {progressiveDataSources.map((ds) => {
                      const checked = (ov.wait_for ?? []).includes(ds.id);
                      return (
                        <label key={ds.id} data-testid={`g7le-overlay-waitfor-${ds.id}`} style={waitChip(checked)}>
                          <input
                            type="checkbox"
                            checked={checked}
                            onChange={() => patchField((n) => {
                              const cur = new Set(n.wait_for ?? []);
                              if (cur.has(ds.id)) cur.delete(ds.id);
                              else cur.add(ds.id);
                              const arr = Array.from(cur);
                              if (arr.length === 0) delete n.wait_for;
                              else n.wait_for = arr;
                            })}
                          />
                          <DataSourceChipLabel option={ds} testIdPrefix={`g7le-overlay-waitfor-${ds.id}`} />
                        </label>
                      );
                    })}
                  </div>
                )}
              </div>
            ) : null}
          </div>

          {hasInheritance ? (
            <button
              type="button"
              data-testid="g7le-overlay-reset-inherit"
              onClick={() => patch(undefined)}
              style={resetBtn}
            >
              ⟲ {t('layout_editor.overlay.reset_inherit')}
            </button>
          ) : null}
        </>
      </DisabledFieldset>
    </div>
  );
}

/** 세그먼트 버튼 */
function SegBtn({ active, onClick, children, testid }: { active: boolean; onClick: () => void; children: React.ReactNode; testid: string }): React.ReactElement {
  return (
    <button type="button" data-testid={testid} aria-pressed={active} onClick={onClick} style={{ ...segBtn, ...(active ? segBtnActive : {}) }}>
      {children}
    </button>
  );
}

/**
 * 필드별 상속 표기 행(L1979) — 라벨 + 〔상속됨〕/〔재정의〕 배지 + [이 화면만 바꾸기].
 * 상속 중이면 [이 화면만 바꾸기] 버튼, 재정의 중이면 〔재정의〕 배지만.
 */
function FieldInheritRow({
  label,
  inherited,
  hasInheritance,
  onOverride,
  testidKey,
  t,
}: {
  label: string;
  inherited: boolean;
  hasInheritance: boolean;
  onOverride: () => void;
  testidKey: string;
  t: TransitionOverlayFormProps['t'];
}): React.ReactElement {
  return (
    <div style={inheritRow}>
      <label style={fieldLabel}>{label}</label>
      {hasInheritance ? (
        inherited ? (
          <>
            <span data-testid={`g7le-overlay-inherited-${testidKey}`} style={inheritedBadge}>
              〔{t('layout_editor.overlay.inherited_badge')}〕
            </span>
            <button
              type="button"
              data-testid={`g7le-overlay-override-${testidKey}`}
              onClick={onOverride}
              style={overrideBtn}
            >
              {t('layout_editor.overlay.override_field')}
            </button>
          </>
        ) : (
          <span data-testid={`g7le-overlay-overridden-${testidKey}`} style={overriddenBadge}>
            〔{t('layout_editor.overlay.overridden_badge')}〕
          </span>
        )
      ) : null}
    </div>
  );
}

/** 부가옵션 라벨 행 */
function SubField({ label, children }: { label: string; children: React.ReactNode }): React.ReactElement {
  return (
    <div style={subFieldWrap}>
      <span style={subFieldLabel}>{label}</span>
      <div style={{ flex: 1, minWidth: 0 }}>{children}</div>
    </div>
  );
}

const form: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 16, minWidth: 0 };
const inheritBanner: React.CSSProperties = { margin: 0, fontSize: 12, color: '#1d4ed8', background: '#eff6ff', padding: '8px 10px', borderRadius: 6 };
const fieldWrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6, minWidth: 0 };
const fieldLabel: React.CSSProperties = { fontSize: 13, fontWeight: 600, color: '#0f172a' };
const fieldHint: React.CSSProperties = { margin: 0, fontSize: 11, color: '#94a3b8' };
const segment: React.CSSProperties = { display: 'flex', gap: 4, flexWrap: 'wrap' };
const segBtn: React.CSSProperties = { padding: '4px 10px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
const segBtnActive: React.CSSProperties = { border: '1px solid #3b82f6', background: '#eff6ff', color: '#1d4ed8' };
const regionBox: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 8, padding: 8, border: '1px dashed #e2e8f0', borderRadius: 8 };
const subBox: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 8, padding: 8, border: '1px dashed #e2e8f0', borderRadius: 8 };
const subFieldWrap: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8, minWidth: 0 };
const subFieldLabel: React.CSSProperties = { fontSize: 11, color: '#64748b', minWidth: 90 };
const textInput: React.CSSProperties = { width: '100%', padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, boxSizing: 'border-box' };
const numberInput: React.CSSProperties = { width: 80, padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6 };
const waitChip = (checked: boolean): React.CSSProperties => ({ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '4px 8px', fontSize: 12, border: `1px solid ${checked ? '#3b82f6' : '#cbd5e1'}`, borderRadius: 12, background: checked ? '#eff6ff' : '#fff', cursor: 'pointer' });
const resetBtn: React.CSSProperties = { alignSelf: 'flex-start', padding: '6px 12px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
const collapseToggle: React.CSSProperties = { alignSelf: 'flex-start', padding: '4px 0', fontSize: 13, fontWeight: 600, color: '#0f172a', background: 'transparent', border: 'none', cursor: 'pointer' };
const collapseBody: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6, paddingLeft: 14, minWidth: 0 };
const inheritRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', minWidth: 0 };
const inheritedBadge: React.CSSProperties = { fontSize: 11, color: '#64748b', background: '#f1f5f9', borderRadius: 4, padding: '1px 6px' };
const overriddenBadge: React.CSSProperties = { fontSize: 11, color: '#0369a1', background: '#e0f2fe', borderRadius: 4, padding: '1px 6px' };
const overrideBtn: React.CSSProperties = { padding: '2px 8px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
