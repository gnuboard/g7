/**
 * AdvancedPropsForm.tsx — 속성 모달 `[고급]` 탭
 *
 * `componentCapabilities[name].advanced` 화이트리스트가 선언한 컴포넌트 레벨
 * 고급 속성을 친화 컨트롤로 다룬다:
 *  - `permissions` → 권한 키 TagInput (a-2)
 *  - `blur_until_loaded` → 토글
 *  - `comment` → 텍스트
 *  - (`lifecycle`/`onComponentEvent`/`slot`/`classMap` 등은 Phase 5 액션·조건
 *    프레임워크 의존 — 본 Phase 는 자리만, 미구현 항목은 "추후 지원" 안내)
 *
 * 본질적 개발자 속성·복잡 표현식(b)은 친화 컨트롤 없이 읽기 전용 목록으로
 * 나열하고 무손실 보존한다(원칙 4.4).
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import { collectAdvancedValues } from '../../utils/advancedValueUtils';
import { TagInputControl } from './TagInputControl';

export interface AdvancedPropsFormProps {
  node: EditorNode;
  /** componentCapabilities[name].advanced — 노출할 고급 속성 화이트리스트 */
  advanced: string[];
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 노드 직속 또는 props 속성 패치 — key 가 'props.X' 면 props 안, 아니면 노드 직속 */
  onPatch: (next: EditorNode) => void;
  /** 권한 키 후보 목록 (코어 + 활성 확장 권한 — a-2) */
  permissionCandidates?: Array<{ value: string; label: string }>;
}

/** Phase 4 에서 친화 컨트롤을 제공하는 고급 속성 */
const PHASE4_SUPPORTED = new Set(['permissions', 'blur_until_loaded', 'comment']);

export function AdvancedPropsForm({
  node,
  advanced,
  t,
  onPatch,
  permissionCandidates,
}: AdvancedPropsFormProps): React.ReactElement {
  const advancedValues = React.useMemo(() => collectAdvancedValues(node), [node]);

  const setNodeKey = (key: string, value: unknown): void => {
    const next: EditorNode = { ...node };
    if (value === undefined) delete (next as Record<string, unknown>)[key];
    else (next as Record<string, unknown>)[key] = value;
    onPatch(next);
  };

  return (
    <div className="g7le-advanced-props" data-testid="g7le-advanced-props" style={wrap}>
      {advanced.map((key) => {
        if (!PHASE4_SUPPORTED.has(key)) {
          return (
            <div key={key} data-testid={`g7le-advanced-deferred-${key}`} style={deferredRow}>
              {t('layout_editor.property_modal.advanced_deferred')}：{key}
            </div>
          );
        }
        if (key === 'permissions') {
          return (
            <div key={key} className="g7le-advanced-group" data-testid="g7le-advanced-permissions" style={groupStyle}>
              <div style={groupLabel}>{t('layout_editor.property_modal.advanced.permissions')}</div>
              <TagInputControl
                control={{ widget: 'tag-input' }}
                value={node.permissions}
                onChange={(v) => setNodeKey('permissions', v)}
                t={t}
                candidates={permissionCandidates}
              />
            </div>
          );
        }
        if (key === 'blur_until_loaded') {
          const on = node.blur_until_loaded === true || (node.blur_until_loaded != null && node.blur_until_loaded !== false);
          return (
            <div key={key} className="g7le-advanced-group" data-testid="g7le-advanced-blur" style={groupStyle}>
              <div style={groupLabel}>{t('layout_editor.property_modal.advanced.blur_until_loaded')}</div>
              <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12 }}>
                <input
                  type="checkbox"
                  data-testid="g7le-advanced-blur-toggle"
                  checked={!!on}
                  onChange={(e) => setNodeKey('blur_until_loaded', e.target.checked ? true : undefined)}
                />
                {t('layout_editor.property_modal.advanced.blur_hint')}
              </label>
            </div>
          );
        }
        // comment
        return (
          <div key={key} className="g7le-advanced-group" data-testid="g7le-advanced-comment" style={groupStyle}>
            <div style={groupLabel}>{t('layout_editor.property_modal.advanced.comment')}</div>
            <input
              type="text"
              data-testid="g7le-advanced-comment-input"
              value={typeof node.comment === 'string' ? node.comment : ''}
              onChange={(e) => setNodeKey('comment', e.target.value === '' ? undefined : e.target.value)}
              style={inputStyle}
            />
          </div>
        );
      })}

      {advancedValues.length > 0 && (
        <div className="g7le-advanced-preserved" data-testid="g7le-advanced-preserved" style={preservedBox}>
          <div style={preservedTitle}>
            {t('layout_editor.property_modal.advanced_preserved_title', { count: advancedValues.length })}
          </div>
          <ul style={preservedList}>
            {advancedValues.map((entry) => (
              <li key={entry.key} data-testid={`g7le-advanced-preserved-${entry.key}`} style={preservedItem}>
                <code style={{ fontSize: 11 }}>{entry.key}</code>
                <span style={{ fontSize: 11, color: '#94a3b8' }}>
                  {entry.kind === 'developer_prop'
                    ? t('layout_editor.property_modal.advanced_kind_developer')
                    : t('layout_editor.property_modal.advanced_kind_expression')}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}

const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 14 };
const groupStyle: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6 };
const groupLabel: React.CSSProperties = { fontSize: 12, fontWeight: 700, color: '#0f172a' };
const inputStyle: React.CSSProperties = { padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, width: '100%', boxSizing: 'border-box' };
const deferredRow: React.CSSProperties = { fontSize: 11, color: '#94a3b8', fontStyle: 'italic' };
const preservedBox: React.CSSProperties = { borderTop: '1px dashed #cbd5e1', paddingTop: 10, marginTop: 4 };
const preservedTitle: React.CSSProperties = { fontSize: 11, color: '#b45309', marginBottom: 6 };
const preservedList: React.CSSProperties = { listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: 4 };
const preservedItem: React.CSSProperties = { display: 'flex', justifyContent: 'space-between', gap: 8, padding: '3px 0' };
