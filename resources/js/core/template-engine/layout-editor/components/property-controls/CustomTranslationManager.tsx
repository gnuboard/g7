/**
 * CustomTranslationManager.tsx — 커스텀 다국어 키 관리 모달.
 *
 * 현재 레이아웃의 커스텀 다국어 키(`template_custom_translations`)를 목록으로
 * 보여 주고 번역 편집/삭제/일괄 삭제를 제공한다. 배경 이미지 관리
 * (LayoutAttachmentManager)와 동형 진입(상단 🌐 툴바 버튼)이며, 인라인 편집으로
 * 생성된 키와 화면 이탈로 연결이 끊긴 좀비(orphaned) 키를 한 곳에서 정리한다.
 *
 * 좀비 표시는 레이아웃 저장 시 백엔드(MarkOrphanedCustomTranslations)가 자동
 * 전이하므로, 본 모달은 그 status 를 배지로 노출하고 수동 일괄 삭제만 담당한다
 * (자동 영구 삭제 없음 — 오삭제 회피).
 *
 * 모든 요청은 공용 `customTranslations` 클라이언트(Authorization: Bearer)로 한다.
 * 편집기 코어 위젯이므로 `g7le-*` + 인라인 스타일만(CSS 라이브러리 비종속).
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  listCustomTranslations,
  updateCustomTranslation,
  deleteCustomTranslation,
  bulkDeleteCustomTranslations,
  type CustomTranslation,
} from '../../utils/customTranslations';
import { getPendingValue } from '../../hooks/pendingCustomTranslations';
import { localeDisplayLabel } from '../LocaleSwitcher';
import { PlaceholderChipInput } from './PlaceholderChipInput';
import { paramPlaceholderTokens } from '../../spec/inlineBindingUtils';

export interface CustomTranslationManagerProps {
  /** 편집 대상 템플릿 식별자 */
  templateIdentifier: string;
  /** 현재 레이아웃 이름 (목록 필터) */
  layoutName: string;
  /** 다국어 해석 함수 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /**
   * 현재 편집 중인 캔버스(저장 전 포함)에서 참조되는 커스텀 키 집합.
   * 저장된 status(영속)와 별개로 "현재 캔버스 사용중/미사용"을 실시간 표시한다
   * 미전달 시 라이브 배지는 생략.
   */
  referencedKeys?: Set<string>;
  /** 모달 닫기 */
  onClose: () => void;
}

type LoadState = 'idle' | 'loading' | 'error';
type FilterMode = 'all' | 'active' | 'orphaned';

/** textarea 높이를 내용에 맞춰 조절 (긴 텍스트가 칸을 넘치지 않도록 세로 확장) */
function resizeTextarea(el: HTMLTextAreaElement | null): void {
  if (!el) return;
  el.style.height = 'auto';
  el.style.height = `${Math.max(el.scrollHeight, 28)}px`;
}

/** 마운트 시 1회 내용 맞춤 높이 적용 (초기 긴 값도 잘리지 않게) */
function autoSize(el: HTMLTextAreaElement | null): void {
  resizeTextarea(el);
}

/** 활성 로케일 목록 — 행의 values 키 합집합에서 추출(테스트/런타임 모두 데이터 기반) */
function localesOf(items: CustomTranslation[]): string[] {
  const set = new Set<string>();
  for (const it of items) {
    for (const loc of Object.keys(it.values ?? {})) set.add(loc);
  }
  return Array.from(set).sort();
}

export function CustomTranslationManager({
  templateIdentifier,
  layoutName,
  t,
  referencedKeys,
  onClose,
}: CustomTranslationManagerProps): React.ReactElement {
  const [items, setItems] = useState<CustomTranslation[]>([]);
  const [loadState, setLoadState] = useState<LoadState>('idle');
  const [error, setError] = useState<string | null>(null);
  const [filter, setFilter] = useState<FilterMode>('all');
  const [selected, setSelected] = useState<Set<number>>(new Set());
  // 행별 편집 중 values 초안 (id → 로케일별 값). 미편집 행은 부재.
  const [drafts, setDrafts] = useState<Record<number, Record<string, string>>>({});
  const [savingId, setSavingId] = useState<number | null>(null);
  const [rowError, setRowError] = useState<Record<number, string>>({});

  const reload = useCallback(async (): Promise<void> => {
    setLoadState('loading');
    setError(null);
    const res = await listCustomTranslations(templateIdentifier, layoutName);
    if (res.ok) {
      setItems(res.data);
      setDrafts({});
      setSelected(new Set());
      setRowError({});
      setLoadState('idle');
    } else {
      setError(res.message);
      setLoadState('error');
    }
  }, [templateIdentifier, layoutName]);

  useEffect(() => {
    void reload();
  }, [reload]);

  const locales = useMemo(() => localesOf(items), [items]);

  const visible = useMemo(
    () =>
      items
        .filter((it) => (filter === 'all' ? true : it.status === filter))
        // 최근 업데이트 순 정렬 (updated_at desc) — 동률/누락 시 id desc 폴백.
        .slice()
        .sort((a, b) => {
          const ta = a.updated_at ? Date.parse(a.updated_at) : 0;
          const tb = b.updated_at ? Date.parse(b.updated_at) : 0;
          if (tb !== ta) return tb - ta;
          return b.id - a.id;
        }),
    [items, filter],
  );

  const orphanedItems = useMemo(() => items.filter((it) => it.status === 'orphaned'), [items]);

  // 표시/편집 기준값 — 우선순위: (1) 모달 내 사용자 편집 draft → (2) 저장-지연 버퍼(pending) →
  // (3) 서버 값. 인라인 칩 이동/추가는 즉시 PUT 하지 않고 pending 버퍼 + 엔진 seed 로만 반영되므로
  // (레이아웃 [저장] 시 flush), 서버 값만 보면 본 모달이 저장 전까지 `{pN}` 누락된 stale 값을
  // 보여 준다. 서버 값 위에 pending 로케일
  // 값을 덮어 캔버스/엔진과 동일한 라이브 값을 표시한다.
  const pendingFor = useCallback((it: CustomTranslation): Record<string, string> => {
    const base = { ...(it.values ?? {}) };
    for (const loc of Object.keys(base)) {
      const pv = getPendingValue(it.translation_key, loc);
      if (pv !== undefined) base[loc] = pv;
    }
    // 서버에 없던 로케일이 pending 에만 있을 수도 있으나, values 키 집합 기준으로 충분(전 로케일 seed).
    return base;
  }, []);

  const draftFor = useCallback(
    (it: CustomTranslation): Record<string, string> => drafts[it.id] ?? pendingFor(it),
    [drafts, pendingFor],
  );

  const handleFieldChange = useCallback((id: number, locale: string, value: string): void => {
    setDrafts((prev) => {
      const base = prev[id] ?? items.find((i) => i.id === id)?.values ?? {};
      return { ...prev, [id]: { ...base, [locale]: value } };
    });
  }, [items]);

  const handleSave = useCallback(
    async (it: CustomTranslation): Promise<void> => {
      setSavingId(it.id);
      setRowError((prev) => ({ ...prev, [it.id]: '' }));
      const values = draftFor(it);
      const res = await updateCustomTranslation(templateIdentifier, it.id, values, it.lock_version);
      if (res.ok) {
        setItems((prev) => prev.map((p) => (p.id === it.id ? res.data : p)));
        setDrafts((prev) => {
          const next = { ...prev };
          delete next[it.id];
          return next;
        });
      } else {
        const msg =
          res.status === 409
            ? t('layout_editor.translation_manager.conflict')
            : res.message;
        setRowError((prev) => ({ ...prev, [it.id]: msg }));
      }
      setSavingId(null);
    },
    [templateIdentifier, draftFor, t],
  );

  const confirmMsg = useCallback(
    (count: number): boolean => {
      const ok =
        typeof window !== 'undefined' && typeof window.confirm === 'function'
          ? window.confirm(t('layout_editor.translation_manager.delete_confirm', { count }))
          : true;
      return ok;
    },
    [t],
  );

  const handleDelete = useCallback(
    async (it: CustomTranslation): Promise<void> => {
      if (!confirmMsg(1)) return;
      const res = await deleteCustomTranslation(templateIdentifier, it.id);
      if (res.ok) {
        setItems((prev) => prev.filter((p) => p.id !== it.id));
        setSelected((prev) => {
          const next = new Set(prev);
          next.delete(it.id);
          return next;
        });
      } else {
        setError(res.message);
      }
    },
    [templateIdentifier, confirmMsg],
  );

  const runBulkDelete = useCallback(
    async (ids: number[]): Promise<void> => {
      if (ids.length === 0) return;
      if (!confirmMsg(ids.length)) return;
      const res = await bulkDeleteCustomTranslations(templateIdentifier, ids);
      if (res.ok) {
        const removed = new Set(ids);
        setItems((prev) => prev.filter((p) => !removed.has(p.id)));
        setSelected(new Set());
      } else {
        setError(res.message);
      }
    },
    [templateIdentifier, confirmMsg],
  );

  const toggleSelect = useCallback((id: number): void => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }, []);

  return (
    <div className="g7le-translation-manager" data-testid="g7le-translation-manager" style={wrap}>
      <div data-modal-drag-handle style={header}>
        <span style={{ fontSize: 14, fontWeight: 700 }} data-testid="g7le-translation-manager-title">
          {t('layout_editor.translation_manager.title')}
        </span>
        <button type="button" aria-label="close" onClick={onClose} style={closeBtn} data-testid="g7le-translation-manager-close">
          ✕
        </button>
      </div>

      <div style={toolbar}>
        <div style={{ display: 'inline-flex', gap: 4 }} role="tablist" aria-label={t('layout_editor.translation_manager.filter_label')}>
          {(['all', 'active', 'orphaned'] as FilterMode[]).map((mode) => (
            <button
              key={mode}
              type="button"
              role="tab"
              aria-selected={filter === mode}
              data-active={filter === mode ? 'true' : 'false'}
              onClick={() => setFilter(mode)}
              style={filter === mode ? filterBtnActive : filterBtn}
              data-testid={`g7le-translation-filter-${mode}`}
            >
              {t(`layout_editor.translation_manager.filter_${mode}`)}
            </button>
          ))}
        </div>
        <div style={{ flex: 1 }} />
        {selected.size > 0 && (
          <button
            type="button"
            onClick={() => void runBulkDelete(Array.from(selected))}
            style={bulkBtn}
            data-testid="g7le-translation-bulk-delete"
          >
            🗑 {t('layout_editor.translation_manager.delete_selected', { count: selected.size })}
          </button>
        )}
        {orphanedItems.length > 0 && (
          <button
            type="button"
            onClick={() => void runBulkDelete(orphanedItems.map((i) => i.id))}
            style={bulkBtn}
            data-testid="g7le-translation-purge-orphaned"
          >
            🧹 {t('layout_editor.translation_manager.purge_orphaned', { count: orphanedItems.length })}
          </button>
        )}
      </div>

      {error && (
        <div style={errorBar} data-testid="g7le-translation-manager-error">
          {error}
        </div>
      )}

      <div style={body}>
        {loadState === 'loading' ? (
          <div style={hint} data-testid="g7le-translation-manager-loading">
            {t('layout_editor.translation_manager.loading')}
          </div>
        ) : visible.length === 0 && loadState !== 'error' ? (
          <div style={hint} data-testid="g7le-translation-manager-empty">
            {t('layout_editor.translation_manager.empty')}
          </div>
        ) : (
          <div data-testid="g7le-translation-list" style={listWrap}>
            {visible.map((it) => {
              const values = draftFor(it);
              const isOrphaned = it.status === 'orphaned';
              // 현재 캔버스(저장 전 포함) 실시간 참조 여부 — referencedKeys 전달 시에만 표시.
              const liveReferenced = referencedKeys ? referencedKeys.has(it.translation_key) : null;
              return (
                <div key={it.id} style={row} data-testid={`g7le-translation-row-${it.id}`}>
                  <div style={rowHead}>
                    <input
                      type="checkbox"
                      checked={selected.has(it.id)}
                      onChange={() => toggleSelect(it.id)}
                      aria-label={t('layout_editor.translation_manager.select_aria', { key: it.translation_key })}
                      data-testid={`g7le-translation-select-${it.id}`}
                    />
                    <code style={keyCode}>{it.translation_key}</code>
                    {/* 저장된 status 배지 (영속) */}
                    {isOrphaned && (
                      <span style={orphanBadge} data-testid={`g7le-translation-badge-orphaned-${it.id}`}>
                        {t('layout_editor.translation_manager.badge_orphaned')}
                      </span>
                    )}
                    {/* 현재 캔버스 실시간 참조 배지 (저장 전이라도 정확) */}
                    {liveReferenced === true && (
                      <span style={liveInUseBadge} data-testid={`g7le-translation-badge-live-inuse-${it.id}`}>
                        {t('layout_editor.translation_manager.badge_live_in_use')}
                      </span>
                    )}
                    {liveReferenced === false && (
                      <span style={liveUnusedBadge} data-testid={`g7le-translation-badge-live-unused-${it.id}`}>
                        {t('layout_editor.translation_manager.badge_live_unused')}
                      </span>
                    )}
                    <div style={{ flex: 1 }} />
                    <button
                      type="button"
                      onClick={() => void handleDelete(it)}
                      style={delBtn}
                      data-testid={`g7le-translation-delete-${it.id}`}
                    >
                      🗑
                    </button>
                  </div>
                  <div style={localeFields}>
                    {locales.map((loc) => {
                      const localeValue = values[loc] ?? '';
                      // `{pN}` 자리표시가 든 키 값은 raw textarea 가 아니라
                      // **칩 합성 위젯**으로 보여 준다(인라인/번역탭과 동형). 자리표시는 원자 칩, 평문만
                      // 편집·칩 드래그로 그 로케일 값의 `{pN}` 순서만 변경(node.text `|pN=` 불변).
                      // 새 칩 삽입(`+데이터`)은 후보 풀이 없는 본 모달에선 미노출(키 컨텍스트 밖).
                      // 평문/바인딩 값(자리표시 0)은 기존 textarea 유지(자유 편집).
                      const isParamValue = paramPlaceholderTokens(localeValue).length > 0;
                      return (
                        <div key={loc} style={fieldRow} data-testid={`g7le-translation-field-${it.id}-${loc}`}>
                          <label style={localeLabel} title={loc}>
                            {localeDisplayLabel(loc, t)}
                            <span style={localeCode}>({loc})</span>
                          </label>
                          {isParamValue ? (
                            <div style={{ flex: 1 }} data-testid={`g7le-translation-chip-${it.id}-${loc}`}>
                              <PlaceholderChipInput
                                value={localeValue}
                                onChange={(next) => handleFieldChange(it.id, loc, next)}
                                t={t}
                                testIdSuffix={`${it.id}-${loc}`}
                              />
                            </div>
                          ) : (
                            <textarea
                              value={localeValue}
                              rows={1}
                              ref={autoSize}
                              onChange={(e) => {
                                handleFieldChange(it.id, loc, e.currentTarget.value);
                                resizeTextarea(e.currentTarget);
                              }}
                              style={localeInput}
                              data-testid={`g7le-translation-input-${it.id}-${loc}`}
                            />
                          )}
                        </div>
                      );
                    })}
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                      <button
                        type="button"
                        disabled={savingId === it.id}
                        onClick={() => void handleSave(it)}
                        style={saveBtn}
                        data-testid={`g7le-translation-save-${it.id}`}
                      >
                        {savingId === it.id
                          ? t('layout_editor.translation_manager.saving')
                          : t('layout_editor.translation_manager.save')}
                      </button>
                      {rowError[it.id] ? (
                        <span style={{ fontSize: 11, color: '#dc2626' }} data-testid={`g7le-translation-row-error-${it.id}`}>
                          {rowError[it.id]}
                        </span>
                      ) : null}
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>

      <div style={footer}>
        <button type="button" onClick={onClose} style={footerCloseBtn} data-testid="g7le-translation-manager-footer-close">
          {t('layout_editor.translation_manager.close')}
        </button>
      </div>
    </div>
  );
}

const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', minHeight: 0, flex: 1 };
const header: React.CSSProperties = { display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 16px', borderBottom: '1px solid #e2e8f0' };
const closeBtn: React.CSSProperties = { border: 'none', background: 'transparent', fontSize: 16, color: '#94a3b8', cursor: 'pointer' };
const toolbar: React.CSSProperties = { padding: '10px 16px', borderBottom: '1px solid #f1f5f9', display: 'flex', gap: 8, alignItems: 'center' };
const filterBtn: React.CSSProperties = { padding: '4px 10px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
const filterBtnActive: React.CSSProperties = { ...filterBtn, borderColor: '#2563eb', background: '#eff6ff', color: '#1d4ed8', fontWeight: 600 };
const bulkBtn: React.CSSProperties = { padding: '4px 10px', fontSize: 12, border: '1px solid #fecaca', borderRadius: 6, background: '#fff', color: '#dc2626', cursor: 'pointer' };
const errorBar: React.CSSProperties = { padding: '8px 16px', fontSize: 12, color: '#b91c1c', background: '#fef2f2' };
const body: React.CSSProperties = { padding: 16, overflow: 'auto', flex: 1, minHeight: 120, display: 'flex', flexDirection: 'column', gap: 12 };
const hint: React.CSSProperties = { fontSize: 13, color: '#94a3b8', textAlign: 'center', padding: '32px 0' };
// 키 카드 사이 간격 — 한 다국어 키 쌍 단위가 시각적으로 분리되도록 카드 간 여백 부여.
const listWrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 20 };
const row: React.CSSProperties = { border: '1px solid #e2e8f0', borderRadius: 8, padding: 10, display: 'flex', flexDirection: 'column', gap: 6 };
const rowHead: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8 };
const keyCode: React.CSSProperties = { fontSize: 11, background: '#f1f5f9', padding: '2px 6px', borderRadius: 4, color: '#0f172a' };
const orphanBadge: React.CSSProperties = { fontSize: 10, color: '#b45309', background: '#fef3c7', padding: '1px 6px', borderRadius: 4, fontWeight: 600 };
const liveInUseBadge: React.CSSProperties = { fontSize: 10, color: '#15803d', background: '#dcfce7', padding: '1px 6px', borderRadius: 4, fontWeight: 600 };
const liveUnusedBadge: React.CSSProperties = { fontSize: 10, color: '#9f1239', background: '#ffe4e6', padding: '1px 6px', borderRadius: 4, fontWeight: 600 };
const localeFields: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6 };
const fieldRow: React.CSSProperties = { display: 'flex', alignItems: 'flex-start', gap: 8 };
const localeLabel: React.CSSProperties = { fontSize: 12, color: '#475569', minWidth: 88, fontWeight: 600, paddingTop: 4, display: 'flex', flexDirection: 'column', lineHeight: 1.2 };
const localeCode: React.CSSProperties = { fontSize: 10, color: '#94a3b8', fontWeight: 400 };
const localeInput: React.CSSProperties = { flex: 1, padding: '4px 8px', fontSize: 13, border: '1px solid #cbd5e1', borderRadius: 4, color: '#0f172a', resize: 'vertical', overflow: 'hidden', minHeight: 28, lineHeight: 1.4, fontFamily: 'inherit', boxSizing: 'border-box' };
const saveBtn: React.CSSProperties = { padding: '5px 12px', fontSize: 12, border: 'none', borderRadius: 6, background: '#2563eb', color: '#fff', cursor: 'pointer' };
const delBtn: React.CSSProperties = { padding: '4px 8px', fontSize: 12, border: '1px solid #fecaca', borderRadius: 6, background: '#fff', color: '#dc2626', cursor: 'pointer' };
const footer: React.CSSProperties = { padding: '10px 16px', borderTop: '1px solid #e2e8f0', display: 'flex', justifyContent: 'flex-end' };
const footerCloseBtn: React.CSSProperties = { padding: '6px 14px', fontSize: 13, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
