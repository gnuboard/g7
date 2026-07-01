/**
 * CompositeSettingsForm.tsx — 속성 모달 `[설정]` 탭
 *
 * 집합 컴포넌트(최신글/게시판 목록 등)의 전용 설정 폼. `components.json` 의
 * `settings.groups` 를 순회해 그룹·필드를 친화적 폼으로 렌더(raw props 편집 아님).
 * 필드 변경 → 해당 `props[field.key]` 를 패치 → 캔버스 라이브 반영.
 *
 * 설정값은 그 컴포넌트 인스턴스의 `props` 로 저장된다(별도 설정 테이블 없음 —
 *  b). 기본값은 설정 스펙의 각 필드 `default` (스펙이 기본값 SSoT).
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import type { CompositeSettingsSpec, CompositeSettingsField } from '../ComponentPalette';

export interface CompositeSettingsFormProps {
  spec: CompositeSettingsSpec;
  node: EditorNode;
  t: (key: string, params?: Record<string, string | number>) => string;
  /** props[key] 변경 → 노드 패치 */
  onPatchProp: (key: string, value: unknown) => void;
}

function label(text: string | undefined, fallback: string, t: CompositeSettingsFormProps['t']): string {
  if (typeof text === 'string') return text.startsWith('$t:') ? t(text.slice(3)) : text;
  return fallback;
}

function currentValue(node: EditorNode, field: CompositeSettingsField): unknown {
  const props = (node.props ?? {}) as Record<string, unknown>;
  return field.key in props ? props[field.key] : field.default;
}

export function CompositeSettingsForm({
  spec,
  node,
  t,
  onPatchProp,
}: CompositeSettingsFormProps): React.ReactElement {
  const groups = spec.groups ?? [];

  return (
    <div className="g7le-composite-settings" data-testid="g7le-composite-settings" style={wrap}>
      {groups.map((group, gi) => (
        <div key={gi} className="g7le-settings-group" style={groupStyle}>
          <div style={groupLabel}>
            {label(group.label, t('layout_editor.property_modal.settings_group', { index: gi + 1 }), t)}
          </div>
          {(group.fields ?? []).map((field) => (
            <div key={field.key} className="g7le-settings-field" data-testid={`g7le-setting-${field.key}`} style={fieldRow}>
              <span style={fieldLabel}>{label(field.label, field.key, t)}</span>
              <div style={{ flex: 1 }}>{renderField(field, node, t, onPatchProp)}</div>
            </div>
          ))}
        </div>
      ))}
    </div>
  );
}

function renderField(
  field: CompositeSettingsField,
  node: EditorNode,
  t: CompositeSettingsFormProps['t'],
  onPatchProp: CompositeSettingsFormProps['onPatchProp'],
): React.ReactElement {
  const value = currentValue(node, field);

  switch (field.type) {
    case 'number':
      return (
        <input
          type="number"
          data-testid={`g7le-setting-input-${field.key}`}
          value={typeof value === 'number' ? value : ''}
          min={field.min}
          max={field.max}
          onChange={(e) => onPatchProp(field.key, e.target.value === '' ? undefined : Number(e.target.value))}
          style={inputStyle}
        />
      );
    case 'text':
      return (
        <input
          type="text"
          data-testid={`g7le-setting-input-${field.key}`}
          value={typeof value === 'string' ? value : ''}
          onChange={(e) => onPatchProp(field.key, e.target.value === '' ? undefined : e.target.value)}
          style={inputStyle}
        />
      );
    case 'toggle':
      return (
        <input
          type="checkbox"
          data-testid={`g7le-setting-input-${field.key}`}
          checked={value === true}
          onChange={(e) => onPatchProp(field.key, e.target.checked)}
        />
      );
    case 'checkbox-group': {
      const selected: unknown[] = Array.isArray(value) ? value : [];
      return (
        <div style={checkGroup} data-testid={`g7le-setting-input-${field.key}`}>
          {(field.options ?? []).map((opt) => {
            const checked = selected.includes(opt.value);
            return (
              <label key={String(opt.value)} style={checkItem}>
                <input
                  type="checkbox"
                  checked={checked}
                  onChange={(e) => {
                    const next = e.target.checked
                      ? [...selected, opt.value]
                      : selected.filter((v) => v !== opt.value);
                    onPatchProp(field.key, next);
                  }}
                />
                {label(opt.label, String(opt.value), t)}
              </label>
            );
          })}
        </div>
      );
    }
    case 'select':
    case 'board-select':
    case 'datasource-select':
    default:
      return (
        <select
          data-testid={`g7le-setting-input-${field.key}`}
          value={value === undefined || value === null ? '' : String(value)}
          onChange={(e) => {
            const raw = e.target.value;
            if (raw === '') {
              onPatchProp(field.key, undefined);
              return;
            }
            const opt = (field.options ?? []).find((o) => String(o.value) === raw);
            onPatchProp(field.key, opt ? opt.value : raw);
          }}
          style={inputStyle}
        >
          <option value="">—</option>
          {(field.options ?? []).map((opt) => (
            <option key={String(opt.value)} value={String(opt.value)}>
              {label(opt.label, String(opt.value), t)}
            </option>
          ))}
        </select>
      );
  }
}

const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 16 };
const groupStyle: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6 };
const groupLabel: React.CSSProperties = { fontSize: 12, fontWeight: 700, color: '#0f172a', borderBottom: '1px solid #e2e8f0', paddingBottom: 4 };
const fieldRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 12, minHeight: 30 };
const fieldLabel: React.CSSProperties = { fontSize: 12, color: '#475569', minWidth: 96 };
const inputStyle: React.CSSProperties = { padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, width: '100%', boxSizing: 'border-box' };
const checkGroup: React.CSSProperties = { display: 'flex', flexWrap: 'wrap', gap: 10 };
const checkItem: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 12, color: '#0f172a' };
