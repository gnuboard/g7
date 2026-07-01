/**
 * LoadingComponentPicker.tsx — 로딩 컴포넌트 "요소 선택" 모달
 *
 * `transition_overlay.spinner.component`/`skeleton.component` 가 참조하는 레지스트리 이름을
 * editor-spec `loadingComponents` 후보에서 고르는 "선택"(추가 아님) 모달. 선택 결과는 캔버스에
 * 노드를 추가하지 않고 **이름 문자열만** 기록한다(이름 = 레지스트리 조회 키). 요소 추가 팔레트
 * (nesting.draggable)와 별개 경로 — 요소 추가 회귀 0.
 *
 * [🔍 선택] 버튼은 그 자리에 펼쳐지는 인라인 드롭다운이 아니라
 * **"요소 추가" 모달처럼 별도 오버레이 모달**을 띄운다(EditorModalContext.open). 트리거 행만
 * 폼에 인라인으로 남고, 검색·후보 목록은 모달 안에서 다룬다.
 *
 * role 필터: style=spinner → role∈{spinner,page}, style=skeleton → role=skeleton. 검색 + 축소
 * 미리보기(라이브 렌더는 호스트가 주입 — 본 컴포넌트는 후보 목록·선택만). 미응답 후보 0 시 안내.
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만.
 *
 * @since engine-v1.50.0
 * @since engine-v1.50.0 인라인 드롭다운 → 별도 모달
 */

import React, { useMemo, useState } from 'react';
import type { LoadingComponentSpec } from '../../spec/specTypes';
import { useEditorModal } from '../../EditorModalContext';

export interface LoadingComponentPickerProps {
  /** 후보 목록(editorSpecLoader.getLoadingComponents) */
  candidates: LoadingComponentSpec[];
  /** 현재 선택값(레지스트리 이름) */
  value?: string;
  /** 선택 확정 콜백(이름 문자열) */
  onSelect: (name: string) => void;
  /** 어느 스타일에서 노출할지 — role 필터 */
  styleKind: 'spinner' | 'skeleton';
  /** 다국어 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** testid 접두 */
  testIdPrefix?: string;
}

/** style → 허용 role */
function rolesForStyle(styleKind: 'spinner' | 'skeleton'): Set<string> {
  return styleKind === 'spinner' ? new Set(['spinner', 'page']) : new Set(['skeleton']);
}

/** `$t:` 라벨 해석 */
function label(raw: string | undefined, t: LoadingComponentPickerProps['t'], fallback: string): string {
  if (typeof raw !== 'string') return fallback;
  return raw.startsWith('$t:') ? t(raw.slice(3)) : raw;
}

/**
 * 로딩 컴포넌트 선택기 — 트리거 행(현재값 + [🔍 선택]). 클릭 시 별도 모달을 띄운다.
 *
 * @param props LoadingComponentPickerProps
 * @return 선택기 엘리먼트
 */
export function LoadingComponentPicker({
  candidates,
  value,
  onSelect,
  styleKind,
  t,
  testIdPrefix = 'g7le-loading-component-picker',
}: LoadingComponentPickerProps): React.ReactElement {
  const modal = useEditorModal();

  const openPicker = (): void => {
    const id = modal.open({
      ariaLabel: t('layout_editor.overlay.loading_picker.select'),
      width: 520,
      maxHeightRatio: 0.7,
      content: (
        <LoadingComponentPickerModalBody
          candidates={candidates}
          value={value}
          styleKind={styleKind}
          t={t}
          testIdPrefix={testIdPrefix}
          onPick={(name) => {
            onSelect(name);
            modal.close(id);
          }}
          onClose={() => modal.close(id)}
        />
      ),
    });
  };

  return (
    <div className={testIdPrefix} data-testid={testIdPrefix} style={{ minWidth: 0 }}>
      <div style={triggerRow}>
        <span data-testid={`${testIdPrefix}-current`} style={currentName}>
          {value && value.length > 0 ? value : t('layout_editor.overlay.loading_picker.none')}
        </span>
        <button type="button" data-testid={`${testIdPrefix}-open`} onClick={openPicker} style={openBtn}>
          🔍 {t('layout_editor.overlay.loading_picker.select')}
        </button>
      </div>
    </div>
  );
}

interface ModalBodyProps {
  candidates: LoadingComponentSpec[];
  value?: string;
  styleKind: 'spinner' | 'skeleton';
  t: LoadingComponentPickerProps['t'];
  testIdPrefix: string;
  onPick: (name: string) => void;
  onClose: () => void;
}

/**
 * 선택 모달 본문 — 헤더(제목·✕) + 검색 + 후보 목록. 검색 state 는 모달 수명 내에서만 유지.
 *
 * @param props ModalBodyProps
 * @return 모달 본문 엘리먼트
 */
function LoadingComponentPickerModalBody({
  candidates,
  value,
  styleKind,
  t,
  testIdPrefix,
  onPick,
  onClose,
}: ModalBodyProps): React.ReactElement {
  const [query, setQuery] = useState('');
  const allowedRoles = rolesForStyle(styleKind);
  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    return candidates
      .filter((c) => allowedRoles.has(c.role))
      .filter((c) => {
        if (!q) return true;
        const lbl = label(c.label, t, c.name).toLowerCase();
        return lbl.includes(q) || c.name.toLowerCase().includes(q);
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [candidates, styleKind, query, t]);

  return (
    <div data-testid={`${testIdPrefix}-modal`} style={modalShell}>
      <div style={modalHeader}>
        <span style={modalTitle}>🔍 {t('layout_editor.overlay.loading_picker.select')}</span>
        <button
          type="button"
          data-testid={`${testIdPrefix}-close`}
          onClick={onClose}
          aria-label={t('layout_editor.overlay.loading_picker.select')}
          style={closeX}
        >
          ✕
        </button>
      </div>
      <input
        type="text"
        data-testid={`${testIdPrefix}-search`}
        value={query}
        placeholder={t('layout_editor.overlay.loading_picker.search')}
        onChange={(e) => setQuery(e.target.value)}
        style={searchInput}
        autoFocus
      />
      {filtered.length === 0 ? (
        <p data-testid={`${testIdPrefix}-empty`} style={emptyHint}>
          {t('layout_editor.overlay.loading_picker.empty')}
        </p>
      ) : (
        <ul style={list}>
          {filtered.map((c) => (
            <li key={c.name}>
              <button
                type="button"
                data-testid={`${testIdPrefix}-item-${c.name}`}
                onClick={() => onPick(c.name)}
                style={{ ...item, ...(value === c.name ? itemSelected : {}) }}
              >
                <span style={itemLabel}>{label(c.label, t, c.name)}</span>
                <code style={itemName}>{c.name}</code>
                <span style={roleBadge}>{c.role}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

const triggerRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6, minWidth: 0 };
const currentName: React.CSSProperties = { flex: 1, minWidth: 0, fontSize: 12, color: '#0f172a', fontFamily: 'monospace' };
const openBtn: React.CSSProperties = { padding: '4px 10px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, background: '#f8fafc', color: '#475569', cursor: 'pointer', whiteSpace: 'nowrap' };
const modalShell: React.CSSProperties = { display: 'flex', flexDirection: 'column', minHeight: 0, padding: 16, boxSizing: 'border-box', fontFamily: 'system-ui, -apple-system, sans-serif' };
const modalHeader: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8, paddingBottom: 8, borderBottom: '1px solid #e2e8f0', marginBottom: 10 };
const modalTitle: React.CSSProperties = { flex: 1, fontSize: 14, fontWeight: 700, color: '#0f172a', minWidth: 0 };
const closeX: React.CSSProperties = { border: 'none', background: 'transparent', color: '#64748b', cursor: 'pointer', fontSize: 15, lineHeight: 1, padding: 4 };
const searchInput: React.CSSProperties = { width: '100%', padding: '6px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, marginBottom: 8, boxSizing: 'border-box' };
const list: React.CSSProperties = { listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: 2, overflowY: 'auto', minHeight: 0 };
const item: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6, width: '100%', textAlign: 'left', padding: '8px 10px', fontSize: 12, border: '1px solid transparent', background: 'transparent', cursor: 'pointer', borderRadius: 6 };
const itemSelected: React.CSSProperties = { border: '1px solid #3b82f6', background: '#eff6ff' };
const itemLabel: React.CSSProperties = { flex: 1, minWidth: 0, color: '#0f172a' };
const itemName: React.CSSProperties = { fontSize: 10, color: '#94a3b8', fontFamily: 'monospace' };
const roleBadge: React.CSSProperties = { fontSize: 10, color: '#64748b', background: '#f1f5f9', borderRadius: 4, padding: '0 4px' };
const emptyHint: React.CSSProperties = { margin: 0, fontSize: 12, color: '#94a3b8', padding: '8px 4px' };
