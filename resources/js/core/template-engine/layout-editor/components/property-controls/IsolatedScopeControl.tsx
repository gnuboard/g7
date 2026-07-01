/**
 * IsolatedScopeControl.tsx — 속성 패널 "격리 영역" 그룹
 *
 * 캔버스 속성 모달에서 노드에 격리 영역(`isolatedState`/`isolatedScopeId`)을 부여한다. 둘은
 * **노드 최상위 키**(props 아님) — `onPatchNode` 로 노드 최상위에 patch 한다.
 *
 *  - 켜기 toggle: OFF→ON → `node.isolatedState={}` + `node.isolatedScopeId` 자동 시드(노드 id
 *    기반). ON→OFF → 두 키 삭제(값 보존 경고).
 *  - 이름(scopeId): 검색 가능 드롭다운(후보=기존 scopeId + initIsolated 키 + 관용 패턴,
 *    `buildScopeIdCandidates`) + 자유 텍스트 병행(목록 밖 거부 안 함). 두 경로 단일 키 수렴.
 *  - 같은 scopeId 중복 시 ⓘ 안내(허용 — 의도적 공유). 빈 값이면 자동 시드 유지.
 *
 * 전 컴포넌트 노출(컨테이너 한정 아님 — 어떤 컴포넌트도 격리 스코프가 될 수 있음).
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만.
 *
 * @since engine-v1.50.0
 */

import React, { useMemo, useState } from 'react';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import { InitialStateValueEditor } from '../page-settings/InitialStateValueEditor';

export interface IsolatedScopeControlProps {
  /** 편집 대상 노드 */
  node: EditorNode;
  /** 노드 최상위 patch */
  onPatchNode: (patched: EditorNode) => void;
  /** 다국어 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** scopeId 후보(buildScopeIdCandidates 결과) */
  scopeIdCandidates?: string[];
  /** 같은 레이아웃 내 다른 노드가 쓰는 scopeId(중복 안내용) */
  usedScopeIds?: string[];
}

/** 노드 id 기반 scopeId 자동 시드 */
function seedScopeId(node: EditorNode): string {
  const id = (node.props as Record<string, unknown> | undefined)?.id ?? node.id;
  return typeof id === 'string' && id.length > 0 ? `${id}-scope` : 'scope';
}

/**
 * "격리 영역" 그룹 컨트롤.
 *
 * @param props IsolatedScopeControlProps
 * @return 격리 영역 컨트롤 엘리먼트
 */
export function IsolatedScopeControl({
  node,
  onPatchNode,
  t,
  scopeIdCandidates = [],
  usedScopeIds = [],
}: IsolatedScopeControlProps): React.ReactElement {
  const rec = node as Record<string, unknown>;
  const enabled = 'isolatedState' in rec && rec.isolatedState != null;
  const scopeId = typeof rec.isolatedScopeId === 'string' ? rec.isolatedScopeId : '';
  const [query, setQuery] = useState('');

  const toggleEnabled = (): void => {
    const next: EditorNode = { ...node };
    if (enabled) {
      // OFF — 두 키 삭제(값 보존 경고는 표시만).
      delete (next as Record<string, unknown>).isolatedState;
      delete (next as Record<string, unknown>).isolatedScopeId;
    } else {
      // ON — 빈 격리 상태 + scopeId 자동 시드.
      (next as Record<string, unknown>).isolatedState = {};
      (next as Record<string, unknown>).isolatedScopeId = seedScopeId(node);
    }
    onPatchNode(next);
  };

  const setScopeId = (id: string): void => {
    const next: EditorNode = { ...node };
    // 빈 값이면 자동 시드 유지(빈 문자열 저장 안 함, SZ7).
    (next as Record<string, unknown>).isolatedScopeId = id.trim() === '' ? seedScopeId(node) : id;
    onPatchNode(next);
  };

  const setIsolatedState = (value: unknown): void => {
    const next: EditorNode = { ...node };
    (next as Record<string, unknown>).isolatedState = value;
    onPatchNode(next);
  };

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    return scopeIdCandidates.filter((c) => !q || c.toLowerCase().includes(q));
  }, [scopeIdCandidates, query]);

  const duplicate = scopeId !== '' && usedScopeIds.includes(scopeId);

  return (
    <div className="g7le-isolated-scope" data-testid="g7le-isolated-scope-control" style={group}>
      <div style={groupTitle}>{t('layout_editor.core_props.isolatedState.label')}</div>

      <div style={toggleRow}>
        <button
          type="button"
          data-testid="g7le-isolated-toggle"
          role="switch"
          aria-checked={enabled}
          onClick={toggleEnabled}
          style={toggle(enabled)}
        >
          {enabled ? t('layout_editor.overlay.on') : t('layout_editor.overlay.off')}
        </button>
        {!enabled ? (
          <span data-testid="g7le-isolated-off-hint" style={hint}>
            {t('layout_editor.core_props.isolatedState.off_hint')}
          </span>
        ) : null}
      </div>

      {enabled ? (
        <>
          {/* scopeId 검색 드롭다운 + 자유 입력 */}
          <div style={field}>
            <label style={fieldLabel}>{t('layout_editor.core_props.isolatedScopeId.label')}</label>
            <input
              type="text"
              data-testid="g7le-isolated-scopeid-input"
              value={scopeId}
              placeholder={t('layout_editor.core_props.isolatedScopeId.placeholder')}
              onChange={(e) => setScopeId(e.target.value)}
              style={input}
            />
            {filtered.length > 0 ? (
              <div data-testid="g7le-isolated-scopeid-picker" style={picker}>
                <input
                  type="text"
                  data-testid="g7le-isolated-scopeid-search"
                  value={query}
                  placeholder={t('layout_editor.core_props.isolatedScopeId.search')}
                  onChange={(e) => setQuery(e.target.value)}
                  style={searchInput}
                />
                <div style={candidateList}>
                  {filtered.map((c) => (
                    <button
                      key={c}
                      type="button"
                      data-testid={`g7le-isolated-scopeid-candidate-${c}`}
                      onClick={() => { setScopeId(c); setQuery(''); }}
                      style={candidateItem}
                    >
                      {c}
                    </button>
                  ))}
                </div>
              </div>
            ) : null}
            {duplicate ? (
              <p data-testid="g7le-isolated-scopeid-dup" style={dupHint}>
                ⓘ {t('layout_editor.core_props.isolatedScopeId.duplicate')}
              </p>
            ) : null}
          </div>

          {/* 격리 시작값(isolatedState 속성 자체의 시작값) */}
          <div style={field}>
            <label style={fieldLabel}>{t('layout_editor.core_props.isolatedState.initial_label')}</label>
            <div data-testid="g7le-isolated-initial-value">
              <InitialStateValueEditor
                value={rec.isolatedState ?? {}}
                onChange={setIsolatedState}
                t={t}
                path="isolatedState"
                scope="isolated-node"
              />
            </div>
          </div>
        </>
      ) : null}
    </div>
  );
}

const group: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 8, padding: 10, border: '1px solid #e2e8f0', borderRadius: 8, minWidth: 0 };
const groupTitle: React.CSSProperties = { fontSize: 12, fontWeight: 700, color: '#0f172a' };
const toggleRow: React.CSSProperties = { display: 'flex', gap: 8, alignItems: 'center' };
const toggle = (on: boolean): React.CSSProperties => ({ padding: '4px 12px', fontSize: 11, border: `1px solid ${on ? '#3b82f6' : '#cbd5e1'}`, borderRadius: 14, background: on ? '#eff6ff' : '#fff', color: on ? '#1d4ed8' : '#64748b', cursor: 'pointer' });
const hint: React.CSSProperties = { fontSize: 11, color: '#94a3b8' };
const field: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4, minWidth: 0 };
const fieldLabel: React.CSSProperties = { fontSize: 11, color: '#64748b' };
const input: React.CSSProperties = { padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, boxSizing: 'border-box', width: '100%' };
const picker: React.CSSProperties = { border: '1px solid #e2e8f0', borderRadius: 6, padding: 4, background: '#fff' };
const searchInput: React.CSSProperties = { width: '100%', padding: '4px 6px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, marginBottom: 4, boxSizing: 'border-box' };
const candidateList: React.CSSProperties = { display: 'flex', flexDirection: 'column', maxHeight: 120, overflowY: 'auto' };
const candidateItem: React.CSSProperties = { textAlign: 'left', padding: '4px 6px', fontSize: 12, border: 'none', background: 'transparent', cursor: 'pointer', borderRadius: 4, color: '#0f172a' };
const dupHint: React.CSSProperties = { margin: 0, fontSize: 11, color: '#64748b' };
