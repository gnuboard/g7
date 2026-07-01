/**
 * SeoVarsEditor.tsx — SEO 동적 변수(vars) 3그룹 에디터
 *
 * vars 는 제목·설명·구조화에서 `{key}` 로 치환되는 이름표다(코어 미제공, 모듈/플러그인
 * `seoVariables()` 정의). source 타입에 따라 편집 가능 여부가 갈리고, 유효 vars 목록은
 * **extensions ∧ page_type** 으로 결정된다(둘 다 필요, SeoRenderer.php:961-969). 3그룹:
 *
 *  - 자동 채움(읽기전용) — source ∈ {core_setting,setting,query,route}. SeoRenderer 가 값까지
 *    자동 채움 → 레이아웃 정의·override·삭제 불가. 🔒 + 출처 배지.
 *  - 값 채우기(data) — source=data. 확장이 정의만, 값은 레이아웃이 채움(DataChipValueInput 값 칩).
 *    required(*)면 삭제 불가 + 빈 값 경고.
 *  - 직접 추가(레이아웃) — 이름+값 정의. 자동 채움 vars 와 동일 이름 차단(정책).
 *
 * 게이팅 미충족(extensions/page_type 중 하나라도 빔) → 자동/data vars 0 + 전제 배너.
 * vars 는 filter 없음(항상 편집 가능, 자동만 source 때문 읽기전용).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(라이브러리 중립).
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useMemo, useState } from 'react';
import { DataChipValueInput } from './DataChipValueInput';
import type { BindingCandidate } from '../../spec/bindingCandidates';

/** vars 후보(백엔드) — `{ name, source, owner, required }` */
export interface SeoVarCandidate {
  /** 변수 이름 */
  name: string;
  /** source 타입 — core_setting/setting/query/route(자동) | data(값 채우기) */
  source: 'core_setting' | 'setting' | 'query' | 'route' | 'data' | string;
  /** 정의 확장 출처 */
  owner?: { type?: string; id?: string; name?: string };
  /** required(삭제 불가·빈 값 경고) */
  required?: boolean;
}

export interface SeoVarsEditorProps {
  /** 레이아웃 `meta.seo.vars` 현재 값(name → 값 표현식) */
  vars: Record<string, string> | null | undefined;
  /** 변경 콜백 — 직접 추가/값 채우기 결과 */
  onChange: (next: Record<string, string>) => void;
  /** 유효 vars 후보(extensions ∧ page_type 합집합 — 백엔드). 게이팅 미충족 시 빈 배열 */
  varCandidates?: SeoVarCandidate[];
  /** 게이팅 충족 여부(extensions ∧ page_type 둘 다) — false 면 자동/data vars 0 + 전제 배너 */
  gatingMet?: boolean;
  /** 값 데이터칩 후보 풀(SEO 컨텍스트) */
  candidates?: BindingCandidate[];
  /** 다국어 해석 t */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** data-testid 접두 */
  testidPrefix?: string;
}

const AUTO_SOURCES = new Set(['core_setting', 'setting', 'query', 'route']);

/**
 * SEO 동적 변수 3그룹 에디터.
 *
 * @param props SeoVarsEditorProps
 * @return vars 편집 엘리먼트
 */
export function SeoVarsEditor({
  vars,
  onChange,
  varCandidates,
  gatingMet = false,
  candidates,
  t,
  testidPrefix = 'g7le-seo-vars',
}: SeoVarsEditorProps): React.ReactElement {
  const currentVars = useMemo<Record<string, string>>(
    () => (vars && typeof vars === 'object' ? { ...vars } : {}),
    [vars],
  );
  const cands = useMemo<SeoVarCandidate[]>(() => (Array.isArray(varCandidates) ? varCandidates : []), [varCandidates]);

  // 자동 채움(읽기전용) — 게이팅 충족 시에만 노출.
  const autoVars = useMemo(() => (gatingMet ? cands.filter((c) => AUTO_SOURCES.has(c.source)) : []), [cands, gatingMet]);
  // 값 채우기(data) — 게이팅 충족 시.
  const dataVars = useMemo(() => (gatingMet ? cands.filter((c) => c.source === 'data') : []), [cands, gatingMet]);

  // 자동 채움 이름 집합(직접 추가 차단·data 와 구분).
  const autoNames = useMemo(() => new Set(autoVars.map((c) => c.name)), [autoVars]);
  const dataNames = useMemo(() => new Set(dataVars.map((c) => c.name)), [dataVars]);
  const reservedNames = useMemo(() => {
    // 게이팅 무관하게 모든 후보 이름 예약(자동 vars 동일 이름 차단은 후보가 자동이면 항상 적용).
    const s = new Set<string>();
    for (const c of cands) {
      if (AUTO_SOURCES.has(c.source)) s.add(c.name);
    }
    return s;
  }, [cands]);

  // 직접 추가 vars — currentVars 중 data 후보가 아닌 키.
  const customNames = useMemo(
    () => Object.keys(currentVars).filter((name) => !dataNames.has(name)),
    [currentVars, dataNames],
  );

  const setVar = useCallback(
    (name: string, value: string | undefined): void => {
      const next = { ...currentVars };
      if (value === undefined) {
        delete next[name];
      } else {
        next[name] = value;
      }
      onChange(next);
    },
    [currentVars, onChange],
  );

  // 직접 추가 — 신규 이름 입력 상태.
  const [newName, setNewName] = useState('');
  const newNameReserved = newName.trim() !== '' && reservedNames.has(newName.trim());
  const newNameDuplicate = newName.trim() !== '' && Object.prototype.hasOwnProperty.call(currentVars, newName.trim());
  //  vars ⑦ — 목록 밖 이름(어떤 확장도 제공하지 않는 직접 정의 변수) 정보성 안내.
  //   거부 아님(직접 추가는 임의 이름 허용이 원칙) — reserved/duplicate 가 아니면서
  //   확장 제공 data vars(dataNames) 후보에도 없을 때 "어떤 확장도 제공 안 함" ⓘ 안내만.
  const newNameNotProvided =
    newName.trim() !== '' && !newNameReserved && !newNameDuplicate && !dataNames.has(newName.trim());

  const addCustom = useCallback((): void => {
    const name = newName.trim();
    if (name === '' || reservedNames.has(name) || Object.prototype.hasOwnProperty.call(currentVars, name)) return;
    onChange({ ...currentVars, [name]: '' });
    setNewName('');
  }, [newName, reservedNames, currentVars, onChange]);

  return (
    <div data-testid={testidPrefix} style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
      {/* vars 유효 목록 게이트 마커(④ g7le-seo-var-list) — 자동/data 후보 집계 컨텍스트 */}
      <span data-testid="g7le-seo-var-list" hidden data-gating={gatingMet ? '1' : '0'} />
      {/* 게이팅 미충족 — 전제 배너(자동/data vars 0) */}
      {!gatingMet && (
        <div data-testid={`${testidPrefix}-precondition`} style={precondBanner}>
          ⚠ {t('layout_editor.page_settings.seo.vars_precondition')}
        </div>
      )}

      {/* 그룹1 — 자동 채움(읽기전용) */}
      {autoVars.length > 0 && (
        <div data-testid={`${testidPrefix}-auto-group`} style={groupBox}>
          <div style={groupTitle}>{t('layout_editor.page_settings.seo.vars_auto_group')}</div>
          {autoVars.map((c) => (
            <div key={c.name} data-testid={`g7le-seo-var-auto-${c.name}`} style={readonlyRow}>
              <span style={lockIcon}>🔒</span>
              <code style={varName}>{c.name}</code>
              <span style={sourceBadge}>〔{t('layout_editor.page_settings.seo.vars_auto')}·{c.source}〕</span>
              {c.owner?.name ? <span style={ownerLabel}>{c.owner.name}</span> : null}
            </div>
          ))}
        </div>
      )}

      {/* 그룹2 — 값 채우기(data) */}
      {dataVars.length > 0 && (
        <div data-testid={`${testidPrefix}-data-group`} style={groupBox}>
          <div style={groupTitle}>{t('layout_editor.page_settings.seo.vars_data_group')}</div>
          {dataVars.map((c) => {
            const val = currentVars[c.name] ?? '';
            const isEmpty = val.trim() === '';
            return (
              <div key={c.name} data-testid={`g7le-seo-var-data-${c.name}`} style={editRow}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 4 }}>
                  <code style={varName}>{c.name}</code>
                  {c.required ? (
                    <span data-testid={`g7le-seo-var-required-${c.name}`} style={requiredMark}>*</span>
                  ) : null}
                </div>
                {/* SEO 동적변수는 값 전용 칸이라 DataChipValueInput(데이터/설정참조
                    칩 + 검색 + 표현식 + [✎수정→칩 편집기→✓완료]). I18nTextField(키화 입력기)는 $*_settings:
                    설정참조를 칩으로 못 그려 raw 노출됐다 → 값 전용 입력기로 교체(키화 0). */}
                <DataChipValueInput
                  value={val}
                  onChange={(v) => setVar(c.name, v ?? '')}
                  t={t}
                  candidates={candidates}
                  testidPrefix={`g7le-seo-var-data-field-${c.name}`}
                />
                {c.required && isEmpty ? (
                  <p data-testid={`g7le-seo-var-empty-warn-${c.name}`} style={emptyWarn}>
                    ⚠ {t('layout_editor.page_settings.seo.vars_required_empty')}
                  </p>
                ) : null}
              </div>
            );
          })}
        </div>
      )}

      {/* 그룹3 — 직접 추가(레이아웃) */}
      <div data-testid={`${testidPrefix}-custom-group`} style={groupBox}>
        <div style={groupTitle}>{t('layout_editor.page_settings.seo.vars_custom_group')}</div>
        {customNames.map((name) => (
          <div key={name} data-testid={`g7le-seo-var-custom-${name}`} style={editRow}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 4 }}>
              <code style={varName}>{name}</code>
              <button
                type="button"
                data-testid={`g7le-seo-var-custom-remove-${name}`}
                onClick={() => setVar(name, undefined)}
                title={t('layout_editor.page_settings.seo.remove')}
                aria-label={t('layout_editor.page_settings.seo.remove')}
                style={{ marginLeft: 'auto', padding: '2px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#64748b', cursor: 'pointer' }}
              >
                ✕
              </button>
            </div>
            {/* 직접 추가 변수도 값 전용 → DataChipValueInput(설정참조/데이터 칩). */}
            <DataChipValueInput
              value={currentVars[name] ?? ''}
              onChange={(v) => setVar(name, v ?? '')}
              t={t}
              candidates={candidates}
              testidPrefix={`g7le-seo-var-custom-field-${name}`}
            />
          </div>
        ))}

        {/* 변수 추가 행 */}
        <div data-testid="g7le-seo-var-add" style={{ display: 'flex', flexDirection: 'column', gap: 4, marginTop: 6 }}>
          <div style={{ display: 'flex', gap: 6 }}>
            <input
              type="text"
              data-testid="g7le-seo-var-add-name"
              value={newName}
              placeholder={t('layout_editor.page_settings.seo.vars_add_name')}
              onChange={(e) => setNewName(e.target.value)}
              style={{ flex: 1, padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, minWidth: 0 }}
            />
            <button
              type="button"
              data-testid="g7le-seo-var-add-confirm"
              onClick={addCustom}
              disabled={newName.trim() === '' || newNameReserved || newNameDuplicate}
              style={addBtn}
            >
              + {t('layout_editor.page_settings.seo.vars_add')}
            </button>
          </div>
          {newNameReserved ? (
            <p data-testid="g7le-seo-var-add-reserved-warn" style={emptyWarn}>
              ⚠ {t('layout_editor.page_settings.seo.vars_name_reserved', { name: newName.trim() })}
            </p>
          ) : null}
          {newNameDuplicate && !newNameReserved ? (
            <p data-testid="g7le-seo-var-add-duplicate-warn" style={emptyWarn}>
              ⚠ {t('layout_editor.page_settings.seo.vars_name_duplicate', { name: newName.trim() })}
            </p>
          ) : null}
          {/*  vars ⑦ — 목록 밖 이름 안내(정보성, 거부 아님: 직접 추가 임의 이름 허용) */}
          {newNameNotProvided ? (
            <p data-testid="g7le-seo-var-add-not-provided-hint" style={{ margin: 0, fontSize: 11, color: '#94a3b8' }}>
              ⓘ {t('layout_editor.page_settings.seo.vars_name_not_provided', { name: newName.trim() })}
            </p>
          ) : null}
        </div>
      </div>

      <p style={{ margin: 0, fontSize: 11, color: '#94a3b8' }}>
        ⓘ {t('layout_editor.page_settings.seo.vars_value_hint')}
      </p>
    </div>
  );
}

const precondBanner: React.CSSProperties = { fontSize: 12, color: '#b45309', background: '#fffbeb', border: '1px solid #fcd34d', borderRadius: 6, padding: '6px 8px' };
const groupBox: React.CSSProperties = { border: '1px solid #e2e8f0', borderRadius: 8, padding: 10, display: 'flex', flexDirection: 'column', gap: 6 };
const groupTitle: React.CSSProperties = { fontSize: 12, fontWeight: 600, color: '#475569' };
const readonlyRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6, padding: '2px 0' };
const editRow: React.CSSProperties = { display: 'flex', flexDirection: 'column', border: '1px solid #f1f5f9', borderRadius: 6, padding: 8 };
const lockIcon: React.CSSProperties = { fontSize: 13 };
const varName: React.CSSProperties = { fontSize: 12, color: '#0f172a', background: '#f1f5f9', padding: '2px 6px', borderRadius: 4 };
const sourceBadge: React.CSSProperties = { fontSize: 11, color: '#64748b' };
const ownerLabel: React.CSSProperties = { fontSize: 11, color: '#94a3b8' };
const requiredMark: React.CSSProperties = { color: '#dc2626', fontWeight: 700 };
const emptyWarn: React.CSSProperties = { margin: '4px 0 0', fontSize: 11, color: '#b45309' };
const addBtn: React.CSSProperties = { padding: '5px 10px', fontSize: 12, border: '1px dashed #cbd5e1', borderRadius: 6, background: '#f8fafc', color: '#475569', cursor: 'pointer', whiteSpace: 'nowrap' };
