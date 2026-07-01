/**
 * TagInputControl.tsx — `tag-input` 위젯
 *
 * 후보 목록에서 택다(多)해 칩으로 누적하는 멀티 선택 입력. 권한 키(컴포넌트
 * `permissions` / 레이아웃 최상위 `permissions`)가 이 위젯을 공유한다. 부여된
 * 항목을 칩으로 나열하고 ✕ 로 제거, "+ 추가"로 후보 목록에서 새 항목을 더한다.
 *
 * 후보 목록(`candidates`)은 호출자가 공급한다(권한 키 = 코어 + 활성 확장 권한).
 * 실재 후보 중 선택이 기본이며 자유 입력은 받지 않는다(권한 키는 오타/미존재
 * 방지 — a-2).
 *
 * 값은 문자열 배열(권한 키 배열, AND 로직). 빈 배열/undefined = 제약 없음.
 *
 * @since engine-v1.50.0
 */

import React, { useState } from 'react';
import type { WidgetProps } from '../../spec/widgetRegistry';

export function TagInputControl({ value, onChange, candidates, t }: WidgetProps): React.ReactElement {
  const selected: string[] = Array.isArray(value) ? (value as string[]) : [];
  const list = candidates ?? [];
  const [picking, setPicking] = useState(false);

  const labelFor = (key: string): string => list.find((c) => c.value === key)?.label ?? key;
  // 칩 라벨이 식별자와 다르면 식별자도 칩에 함께 노출(권한 식별자 가시성).
  const showIdFor = (key: string): boolean => labelFor(key) !== key;

  const remove = (key: string): void => {
    const next = selected.filter((k) => k !== key);
    onChange(next.length === 0 ? undefined : next);
  };

  const add = (key: string): void => {
    if (selected.includes(key)) return;
    onChange([...selected, key]);
    setPicking(false);
  };

  const available = list.filter((c) => !selected.includes(c.value));

  return (
    <div className="g7le-widget g7le-widget--tag-input" data-testid="g7le-widget-tag-input" style={wrap}>
      <div style={chipRow}>
        {selected.map((key) => (
          <span key={key} data-testid={`g7le-tag-chip-${key}`} style={chip}>
            <span style={chipLabel}>{labelFor(key)}</span>
            {showIdFor(key) && (
              <code data-testid={`g7le-tag-chip-id-${key}`} style={chipId}>
                {key}
              </code>
            )}
            <button
              type="button"
              aria-label={`remove ${key}`}
              data-testid={`g7le-tag-remove-${key}`}
              onClick={() => remove(key)}
              style={chipRemove}
            >
              ✕
            </button>
          </span>
        ))}
        <button
          type="button"
          data-testid="g7le-tag-add"
          onClick={() => setPicking((v) => !v)}
          disabled={available.length === 0}
          style={addBtn}
        >
          {t('layout_editor.control.tag_add')}
        </button>
      </div>

      {picking && available.length > 0 && (
        <div data-testid="g7le-tag-candidates" style={candidateList}>
          {available.map((c) => {
            // 식별자(value)가 라벨과 다르면 식별자도 함께 노출 — 어떤 권한 식별자인지
            // 사용자가 분명히 알 수 있도록. 권한 후보는 value=식별자, label=친화명.
            const showId = c.value !== c.label;
            return (
              <button
                key={c.value}
                type="button"
                data-testid={`g7le-tag-candidate-${c.value}`}
                onClick={() => add(c.value)}
                style={candidateItem}
              >
                <span style={candidateLabel}>{c.label}</span>
                {showId && (
                  <code data-testid={`g7le-tag-candidate-id-${c.value}`} style={candidateId}>
                    {c.value}
                  </code>
                )}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}

const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6 };
const chipRow: React.CSSProperties = { display: 'flex', flexWrap: 'wrap', gap: 4, alignItems: 'center' };
const chip: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', gap: 4, padding: '2px 6px', fontSize: 12, background: '#e0e7ff', color: '#1d4ed8', borderRadius: 12 };
const chipLabel: React.CSSProperties = { fontSize: 12 };
const chipId: React.CSSProperties = { fontSize: 10, color: '#4f46e5', background: '#c7d2fe', borderRadius: 4, padding: '0 4px', fontFamily: 'monospace' };
const chipRemove: React.CSSProperties = { border: 'none', background: 'transparent', color: '#1d4ed8', cursor: 'pointer', fontSize: 10, padding: 0, lineHeight: 1 };
const addBtn: React.CSSProperties = { padding: '2px 8px', fontSize: 12, border: '1px dashed #94a3b8', borderRadius: 12, background: '#fff', cursor: 'pointer', color: '#475569' };
const candidateList: React.CSSProperties = { display: 'flex', flexDirection: 'column', maxHeight: 180, overflowY: 'auto', border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff' };
const candidateItem: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 1, textAlign: 'left', padding: '6px 10px', fontSize: 12, border: 'none', borderBottom: '1px solid #f1f5f9', background: 'transparent', cursor: 'pointer', color: '#0f172a' };
const candidateLabel: React.CSSProperties = { fontSize: 12, color: '#0f172a' };
const candidateId: React.CSSProperties = { fontSize: 10, color: '#64748b', fontFamily: 'monospace' };
