// e2e:allow 페이지 설정 모달 영역(검색엔진 탭 구조화 데이터) — 모달/DataChipValueInput 합성 의존으로 Playwright 부적합. 단위 RTL(SeoStructuredDataEditor.test) + Chrome MCP 매트릭스(tests/scenarios/page-settings.yaml audit:allow)가 SSoT. 묶음③ autoMeta 연결 칩 동일 정책.
/**
 * SeoStructuredDataEditor.tsx — 구조화 데이터 "직접 지정" 토글 + 단일 블록 통편집
 *
 * og 와 **근본적으로 다른 통 덮어쓰기 모델**: structured_data 는 키별 cascade 가
 * 아니라 레이아웃이 선언하면 모듈 `seoStructuredData()` 를 **전부 대체**(SeoRenderer.php:287),
 * **단일 @type 블록**(배열/`@graph` 미지원). 그래서 속성별 override 가 아니라 토글 모델:
 *
 *  - 상태1 OFF(자동): 모듈 자동 블록(서버 미리보기 `autoBlock`) 읽기전용 + 통교체 사전 경고.
 *  - 상태2 ON(직접 지정): 단일 @type(공통+자유) + 점 경로 키–값(데이터칩), 평탄↔중첩 저장,
 *    "모듈 자동값 불러와 시작" 시드, 빈 값 제거 안내, 통교체 상시 경고.
 *  - 상태3 filter 잠김: 토글 비활성 + 덮는 실제 출력 블록(`filteredBlock`) 읽기전용.
 *  - 상태4 진입 시 선언 있으면 ON(상태2 동일, hasLayoutBlock).
 *
 * 저장 형태는 **중첩 객체**(`structured_data['@type']` + 중첩 속성). 편집기는 점 경로 평탄 행으로
 * 보여주고 저장 시 중첩 복원한다(`offers.price` → `{offers:{price}}`). `@context` 는 자동(미저장).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(라이브러리 중립).
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useMemo, useState } from 'react';
import { DataChipValueInput } from './DataChipValueInput';
import { ToggleSwitch } from './FormPrimitives';
import type { BindingCandidate } from '../../spec/bindingCandidates';

/** 구조화 데이터 블록(단일 @type 중첩 객체) */
export type StructuredDataBlock = Record<string, unknown>;

/** 공통 @type 후보(자유 입력 병행) — ③ */
export const STRUCTURED_TYPE_OPTIONS = [
  'WebPage',
  'WebSite',
  'ItemList',
  'BreadcrumbList',
  'Organization',
  'Person',
  'CollectionPage',
  'AboutPage',
  'ContactPage',
  'SearchAction',
] as const;

export interface SeoStructuredDataEditorProps {
  /** 레이아웃 `meta.seo.structured_data` 현재 값(중첩 객체) — 미선언이면 null/undefined */
  value: StructuredDataBlock | null | undefined;
  /** 변경 콜백 — null 이면 키 삭제(자동 모드 복귀), 객체면 통 override */
  onChange: (next: StructuredDataBlock | null) => void;
  /** 모듈 자동 블록(서버 `autoBlock`) — OFF 시 읽기전용 미리보기 + 시드 출발점 */
  autoBlock?: StructuredDataBlock | null;
  /** 자동 블록 점 경로 키별 데이터 경로 메타(연결 칩) — OFF 미리보기에 "상품 이름" 칩 표시 */
  autoMeta?: Record<string, { expr: string; label: string }>;
  /** filter_structured_data 가 실제 통 override 하는지(서버) — 상태3 */
  lockedByFilter?: boolean;
  /** 필터-후 실제 출력 블록(서버 `filteredBlock`) — 잠김 시 읽기전용 표시 */
  filteredBlock?: StructuredDataBlock | null;
  /** 현재 page_type(자동 블록 재계산 표시용) */
  pageType?: string | null;
  /** 속성 값 데이터칩 후보 풀 */
  candidates?: BindingCandidate[];
  /** 다국어 해석 t */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** data-testid 접두 */
  testidPrefix?: string;
}

/** 중첩 객체 → 점 경로 평탄 행(`{ 'offers.price': '{{...}}' }`). `@type` 은 별도 처리(제외). */
export function flattenStructured(block: StructuredDataBlock): Array<{ key: string; value: string }> {
  const out: Array<{ key: string; value: string }> = [];
  const walk = (obj: Record<string, unknown>, prefix: string): void => {
    for (const [k, v] of Object.entries(obj)) {
      if (k === '@type' || k === '@context') continue;
      const path = prefix ? `${prefix}.${k}` : k;
      if (v !== null && typeof v === 'object' && !Array.isArray(v)) {
        walk(v as Record<string, unknown>, path);
      } else {
        out.push({ key: path, value: v == null ? '' : String(v) });
      }
    }
  };
  walk(block, '');
  return out;
}

/** 점 경로 평탄 행 + @type → 중첩 객체 복원(`offers.price` → `{offers:{price}}`). */
export function nestStructured(type: string, rows: Array<{ key: string; value: string }>): StructuredDataBlock {
  const block: StructuredDataBlock = { '@type': type };
  for (const { key, value } of rows) {
    if (key.trim() === '') continue;
    const segments = key.split('.');
    let cursor = block as Record<string, unknown>;
    for (let i = 0; i < segments.length - 1; i++) {
      const seg = segments[i];
      if (typeof cursor[seg] !== 'object' || cursor[seg] === null || Array.isArray(cursor[seg])) {
        cursor[seg] = {};
      }
      cursor = cursor[seg] as Record<string, unknown>;
    }
    cursor[segments[segments.length - 1]] = value;
  }
  return block;
}

let sdRowSeq = 0;

/**
 * 구조화 데이터 토글 + 통편집 에디터.
 *
 * @param props SeoStructuredDataEditorProps
 * @return 구조화 데이터 편집 엘리먼트
 */
export function SeoStructuredDataEditor({
  value,
  onChange,
  autoBlock,
  autoMeta,
  lockedByFilter = false,
  filteredBlock,
  pageType,
  candidates,
  t,
  testidPrefix = 'g7le-seo-sd',
}: SeoStructuredDataEditorProps): React.ReactElement {
  const hasLayoutBlock = !!value && typeof value === 'object';
  // 진입 시 선언 있으면 ON(상태4). 토글 상태는 로컬(value 유무 + 사용자 토글).
  const [manualOn, setManualOn] = useState<boolean>(hasLayoutBlock);

  // ON 편집 행(점 경로 + 값) — value 또는 빈 블록에서 도출(편집 중 안정 위해 로컬).
  const initialType = hasLayoutBlock && typeof value!['@type'] === 'string' ? (value!['@type'] as string) : 'WebPage';
  const [type, setType] = useState<string>(initialType);
  const [rows, setRows] = useState<Array<{ id: number; key: string; value: string }>>(
    () => (hasLayoutBlock ? flattenStructured(value!).map((r) => ({ id: sdRowSeq++, ...r })) : []),
  );

  const emitFromRows = useCallback(
    (nextType: string, nextRows: Array<{ id: number; key: string; value: string }>): void => {
      setType(nextType);
      setRows(nextRows);
      onChange(nestStructured(nextType, nextRows.map((r) => ({ key: r.key, value: r.value }))));
    },
    [onChange],
  );

  // 모듈 자동 블록을 편집 행으로 시드한다 — **autoMeta 있는 키는 평문(autoBlock 값) 대신 데이터
  // 경로(expr)를 시드값으로** 쓴다. autoBlock 은
  // resolve 된 평문("베이직 오버핏…")이라 그대로 시드하면 production 에서 모든 상품에 그 글자가 박힌다.
  // 데이터 경로로 시드하면 DataChipValueInput 이 칩으로 그리고, 실제 상품 데이터로 렌더된다. 메타
  // 없는 키(파생값 등)는 종전대로 autoBlock 평문 유지(하위호환).
  const seedRowsFromAuto = useCallback((): { type: string; rows: Array<{ id: number; key: string; value: string }> } => {
    const block = autoBlock as Record<string, unknown>;
    const seededType = typeof block['@type'] === 'string' ? (block['@type'] as string) : type;
    const seededRows = flattenStructured(block).map((r) => ({
      id: sdRowSeq++,
      key: r.key,
      value: autoMeta?.[r.key]?.expr ?? r.value,
    }));
    return { type: seededType, rows: seededRows };
  }, [autoBlock, autoMeta, type]);

  const handleToggle = useCallback(
    (on: boolean): void => {
      setManualOn(on);
      if (on) {
        // ON 전환 — 통 override(자동 정보 전부 사라짐).
        // 편집할 레이아웃 행이 아직 없고(rows 비어 있음) 모듈 자동 블록이 있으면, 빈 WebPage 대신
        // 모듈 자동값을 출발점으로 자동 시드한다(③ 개선 — 종전엔 "모듈값 불러오기"
        // 버튼을 따로 눌러야 했다). 이미 레이아웃 행이 있으면(상태4 재진입 등) 그 값을 보존한다.
        if (rows.length === 0 && autoBlock && typeof autoBlock === 'object') {
          const seeded = seedRowsFromAuto();
          emitFromRows(seeded.type, seeded.rows);
          return;
        }
        onChange(nestStructured(type, rows.map((r) => ({ key: r.key, value: r.value }))));
      } else {
        // OFF — 레이아웃 선언 제거(모듈 자동 복귀).
        onChange(null);
      }
    },
    [type, rows, autoBlock, seedRowsFromAuto, emitFromRows, onChange],
  );

  const seedFromModule = useCallback((): void => {
    if (!autoBlock) return;
    const seeded = seedRowsFromAuto();
    emitFromRows(seeded.type, seeded.rows);
  }, [autoBlock, seedRowsFromAuto, emitFromRows]);

  const addRow = useCallback((): void => {
    emitFromRows(type, [...rows, { id: sdRowSeq++, key: '', value: '' }]);
  }, [type, rows, emitFromRows]);

  const removeRow = useCallback(
    (id: number): void => {
      emitFromRows(type, rows.filter((r) => r.id !== id));
    },
    [type, rows, emitFromRows],
  );

  const patchRowKey = useCallback(
    (id: number, key: string): void => {
      emitFromRows(type, rows.map((r) => (r.id === id ? { ...r, key } : r)));
    },
    [type, rows, emitFromRows],
  );

  const patchRowValue = useCallback(
    (id: number, val: string): void => {
      emitFromRows(type, rows.map((r) => (r.id === id ? { ...r, value: val } : r)));
    },
    [type, rows, emitFromRows],
  );

  const autoRows = useMemo(() => (autoBlock ? flattenStructured(autoBlock) : []), [autoBlock]);
  const filteredRows = useMemo(() => (filteredBlock ? flattenStructured(filteredBlock) : []), [filteredBlock]);
  const hasEmptyValue = rows.some((r) => r.key.trim() !== '' && r.value.trim() === '');

  // ── 상태3 — filter 잠김(토글 비활성 + 덮는 데이터 표시) ──
  if (lockedByFilter) {
    const filteredType = filteredBlock && typeof filteredBlock['@type'] === 'string' ? (filteredBlock['@type'] as string) : '';
    return (
      <div data-testid={testidPrefix} style={wrap}>
        <div style={toggleRow}>
          <span style={lockIcon}>🔒</span>
          <span style={{ fontSize: 13, fontWeight: 600 }}>{t('layout_editor.page_settings.seo.sd_mode')}</span>
          <span data-testid={`${testidPrefix}-locked-badge`} style={mutedBadge}>{t('layout_editor.page_settings.seo.sd_filter_locked')}</span>
        </div>
        <div data-testid={`${testidPrefix}-locked`} style={lockedBox}>
          <p style={{ margin: '0 0 6px', fontSize: 12, color: '#475569' }}>
            ⓘ {t('layout_editor.page_settings.seo.sd_filter_locked_note')}
          </p>
          <div data-testid={`${testidPrefix}-filtered-block`}>
            {filteredType ? <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 4 }}>@type: {filteredType}</div> : null}
            {filteredRows.map((r) => (
              <div key={r.key} data-testid={`${testidPrefix}-filtered-row-${r.key}`} style={readonlyRow}>
                <code style={readonlyKey}>{r.key}</code>
                <span style={readonlyVal}>{r.value}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
    );
  }

  return (
    <div data-testid={testidPrefix} style={wrap}>
      <div style={toggleRow}>
        <ToggleSwitch
          checked={manualOn}
          onChange={handleToggle}
          testid={`${testidPrefix}-mode`}
          label={t('layout_editor.page_settings.seo.sd_manual_toggle')}
        />
      </div>

      {/* 상태1 OFF — 모듈 자동 블록 읽기전용 미리보기 + 사전 경고 */}
      {!manualOn && (
        <div data-testid={`${testidPrefix}-auto`} style={{ marginTop: 8 }}>
          <div data-testid={`${testidPrefix}-auto-preview`} style={autoBox}>
            <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 6 }}>
              {t('layout_editor.page_settings.seo.sd_auto_title')}
              {autoBlock && typeof autoBlock['@type'] === 'string' ? `: ${autoBlock['@type']}` : ''}
              {pageType ? <span style={{ marginLeft: 6, color: '#94a3b8', fontWeight: 400 }}>({pageType})</span> : null}
            </div>
            {autoRows.length === 0 ? (
              <p style={{ margin: 0, fontSize: 12, color: '#94a3b8' }}>{t('layout_editor.page_settings.seo.sd_auto_empty')}</p>
            ) : (
              autoRows.map((r) => {
                // 이 속성이 모듈 데이터 경로 메타를 가지면 "상품 이름" 연결 칩으로(평문 대신).
                const meta = autoMeta?.[r.key];
                return (
                  <div key={r.key} data-testid={`${testidPrefix}-auto-row-${r.key}`} style={readonlyRow}>
                    <code style={readonlyKey}>{r.key}</code>
                    {meta ? (
                      <span data-testid={`${testidPrefix}-auto-chip-${r.key}`} style={autoChipReadonly} title={meta.expr}>
                        🔗 {meta.label || meta.expr}
                      </span>
                    ) : (
                      <span style={readonlyVal}>{r.value}</span>
                    )}
                  </div>
                );
              })
            )}
          </div>
          <p data-testid={`${testidPrefix}-replace-warn`} style={preWarn}>
            ⓘ {t('layout_editor.page_settings.seo.sd_replace_pre_warn')}
          </p>
        </div>
      )}

      {/* 상태2/4 ON — 단일 @type 통편집 */}
      {manualOn && (
        <div data-testid={`${testidPrefix}-manual`} style={{ marginTop: 8 }}>
          <p data-testid={`${testidPrefix}-replace-warn`} style={warnBanner}>
            ⚠ {t('layout_editor.page_settings.seo.sd_replace_warn')}
          </p>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            <label style={{ fontSize: 12, color: '#475569' }}>{t('layout_editor.page_settings.seo.sd_type')}</label>
            <input
              type="text"
              data-testid={`${testidPrefix}-type`}
              list={`${testidPrefix}-type-options`}
              value={type}
              onChange={(e) => emitFromRows(e.target.value, rows)}
              style={typeInput}
            />
            <datalist id={`${testidPrefix}-type-options`}>
              {STRUCTURED_TYPE_OPTIONS.map((o) => (
                <option key={o} value={o} />
              ))}
            </datalist>
          </div>

          <div style={{ marginTop: 8, display: 'flex', flexDirection: 'column', gap: 6 }}>
            {rows.map((row) => (
              <div key={row.id} data-testid={`${testidPrefix}-prop-${row.id}`} style={propRow}>
                <input
                  type="text"
                  data-testid={`${testidPrefix}-prop-key-${row.id}`}
                  value={row.key}
                  placeholder={t('layout_editor.page_settings.seo.sd_prop_key')}
                  onChange={(e) => patchRowKey(row.id, e.target.value)}
                  style={{ width: 160, padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, minWidth: 0 }}
                />
                <div style={{ flex: 1, minWidth: 0 }}>
                  <DataChipValueInput
                    value={row.value}
                    onChange={(v) => patchRowValue(row.id, v)}
                    t={t}
                    candidates={candidates}
                    placeholder={t('layout_editor.page_settings.seo.sd_prop_value')}
                    testidPrefix={`${testidPrefix}-prop-value-${row.id}`}
                  />
                </div>
                <button
                  type="button"
                  data-testid={`${testidPrefix}-prop-remove-${row.id}`}
                  onClick={() => removeRow(row.id)}
                  title={t('layout_editor.page_settings.seo.remove')}
                  aria-label={t('layout_editor.page_settings.seo.remove')}
                  style={removeBtn}
                >
                  ✕
                </button>
              </div>
            ))}
          </div>

          <div style={{ marginTop: 8, display: 'flex', gap: 8 }}>
            <button type="button" data-testid={`${testidPrefix}-add`} onClick={addRow} style={addBtn}>
              + {t('layout_editor.page_settings.seo.sd_add_prop')}
            </button>
            {autoBlock ? (
              <button type="button" data-testid={`${testidPrefix}-seed-from-module`} onClick={seedFromModule} style={addBtn}>
                ↩ {t('layout_editor.page_settings.seo.sd_seed_from_module')}
              </button>
            ) : null}
          </div>

          <p data-testid={`${testidPrefix}-empty-note`} style={emptyNote}>
            ⓘ {t('layout_editor.page_settings.seo.sd_empty_note')}
            {hasEmptyValue ? <span style={{ color: '#b45309' }}> {t('layout_editor.page_settings.seo.sd_has_empty')}</span> : null}
          </p>
        </div>
      )}
    </div>
  );
}

const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column' };
const toggleRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8 };
const lockIcon: React.CSSProperties = { fontSize: 14 };
const mutedBadge: React.CSSProperties = { fontSize: 11, padding: '2px 6px', borderRadius: 4, background: '#f1f5f9', color: '#64748b' };
const lockedBox: React.CSSProperties = { marginTop: 8, border: '1px solid #fcd34d', borderRadius: 8, padding: 10, background: '#fffbeb' };
const autoBox: React.CSSProperties = { border: '1px solid #e2e8f0', borderRadius: 8, padding: 10, background: '#f8fafc' };
const readonlyRow: React.CSSProperties = { display: 'flex', gap: 8, alignItems: 'center', padding: '2px 0' };
const readonlyKey: React.CSSProperties = { fontSize: 11, color: '#475569', background: '#fff', padding: '2px 6px', borderRadius: 4, minWidth: 120 };
const readonlyVal: React.CSSProperties = { fontSize: 11, color: '#0f172a', fontFamily: 'monospace', wordBreak: 'break-all' };
// 자동 블록 연결 칩 — 평문 대신 "상품 이름" 데이터 출처 칩.
const autoChipReadonly: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', padding: '2px 8px', fontSize: 11, color: '#1d4ed8', background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 6 };
const preWarn: React.CSSProperties = { margin: '6px 0 0', fontSize: 12, color: '#64748b' };
const warnBanner: React.CSSProperties = { margin: '0 0 8px', fontSize: 12, color: '#b45309', background: '#fffbeb', border: '1px solid #fcd34d', borderRadius: 6, padding: '6px 8px' };
const typeInput: React.CSSProperties = { padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, width: '100%', minWidth: 0 };
const propRow: React.CSSProperties = { display: 'flex', gap: 6, alignItems: 'flex-start', border: '1px solid #e2e8f0', borderRadius: 8, padding: 8 };
const removeBtn: React.CSSProperties = { padding: '4px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#64748b', cursor: 'pointer' };
const addBtn: React.CSSProperties = { padding: '5px 10px', fontSize: 12, border: '1px dashed #cbd5e1', borderRadius: 6, background: '#f8fafc', color: '#475569', cursor: 'pointer' };
const emptyNote: React.CSSProperties = { margin: '8px 0 0', fontSize: 11, color: '#94a3b8' };
