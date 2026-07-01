// e2e:allow 확장 주입 props 편집기 — 속성 모달 상단 섹션(JsonBlockField 공용 부품 사용). 합성 이벤트 부적합(편집기 코어 정책)이라 Chrome MCP 매트릭스 + 공용 부품 단위(JsonBlockField.test)로 검증.
/**
 * InjectedPropsSection.tsx — 확장이 주입한 속성 편집 섹션
 *
 * 호스트 노드(`__injectedProps` 메타 보유)의 속성 모달 상단에 "확장이 주입한 속성" 섹션을
 * 출처 배지와 함께 렌더한다. 편집 결과는 그
 * 확장 행(`layout-extensions/{id}`)으로 교차 저장한다(저장은 호스트 레이아웃이 아니라 확장).
 *
 * 주입 props 는 임의 병합 구조(`{tabs: {_append: [...]}}` 등)이므로, 본 섹션은 JSON 값을
 * 텍스트로 편집하는 안전한 범용 에디터를 제공한다(값까지 편집). 각 확장 주입마다
 * 출처 배지 + 저장 대상 안내 + [이 확장에 저장] 버튼을 둔다.
 *
 * @since engine-v1.50.0
 */

import React, { useState } from 'react';
import { JsonBlockField } from '../JsonBlockField';

/** 호스트 노드 `__injectedProps[]` 한 항목 (백엔드 buildInjectedPropsMeta) */
export interface InjectedPropsEntry {
  extensionId: number;
  extensionSourceType?: string;
  extensionIdentifier?: string;
  extensionName?: string;
  /** 주입한 props 정의 (편집 대상) */
  props: Record<string, unknown>;
}

export interface InjectedPropsSectionProps {
  /** 호스트 노드에 주입된 확장 props 목록 */
  injectedProps: InjectedPropsEntry[];
  t: (key: string, params?: Record<string, string | number>) => string;
  /**
   * 확장 주입 props 저장 — 그 확장 행으로 교차 저장.
   * 호출자(EditorCanvasOverlay)가 layout-extensions API 로 그 injection 의 props 를 갱신한다.
   */
  onSaveInjectedProps: (extensionId: number, nextProps: Record<string, unknown>) => Promise<void>;
}

const sectionWrap: React.CSSProperties = {
  borderTop: '2px solid #c7d2fe',
  background: '#eef2ff',
  padding: '10px 12px',
  display: 'flex',
  flexDirection: 'column',
  gap: 8,
};

const sectionTitle: React.CSSProperties = {
  fontSize: 13,
  fontWeight: 700,
  color: '#3730a3',
  display: 'flex',
  alignItems: 'center',
  gap: 6,
};

const originBadge: React.CSSProperties = {
  fontSize: 11,
  fontWeight: 600,
  color: '#4338ca',
  background: '#e0e7ff',
  border: '1px solid #c7d2fe',
  borderRadius: 4,
  padding: '1px 6px',
};

const helpText: React.CSSProperties = { fontSize: 11, color: '#6366f1' };

const saveBtn: React.CSSProperties = {
  alignSelf: 'flex-start',
  fontSize: 12,
  fontWeight: 600,
  color: '#fff',
  background: '#4f46e5',
  border: 'none',
  borderRadius: 4,
  padding: '4px 10px',
  cursor: 'pointer',
};

const saveBtnDisabled: React.CSSProperties = { opacity: 0.5, cursor: 'not-allowed' };

const errorText: React.CSSProperties = { fontSize: 11, color: '#dc2626' };

/** 단일 확장 주입 항목 편집기 */
function InjectedPropsEntryEditor({
  entry,
  t,
  onSave,
}: {
  entry: InjectedPropsEntry;
  t: InjectedPropsSectionProps['t'];
  onSave: (next: Record<string, unknown>) => Promise<void>;
}): React.ReactElement {
  // 편집 중 파싱값(유효 입력만 갱신)과 유효성 — JsonBlockField 가 JSON 검증을 맡고, 무효면
  // valid=false 로 저장 버튼을 잠근다(종전 save 시점 1회 parse → 인라인 즉시 검증으로 개선).
  const [draftValue, setDraftValue] = useState<Record<string, unknown>>(() => entry.props);
  const [valid, setValid] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [savedOk, setSavedOk] = useState(false);

  const sourceLabel =
    entry.extensionName ?? entry.extensionIdentifier ?? `#${entry.extensionId}`;

  const handleSave = async (): Promise<void> => {
    if (!valid) {
      setError(t('layout_editor.property_modal.injected_props.invalid_json'));
      return;
    }
    setError(null);
    setSaving(true);
    setSavedOk(false);
    try {
      await onSave(draftValue);
      setSavedOk(true);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div
      data-testid={`g7le-injected-props-entry-${entry.extensionId}`}
      style={{ display: 'flex', flexDirection: 'column', gap: 6 }}
    >
      <div style={sectionTitle}>
        <span style={originBadge} data-testid="g7le-injected-props-origin-badge">
          {t('layout_editor.property_modal.injected_props.origin', { source: sourceLabel })}
        </span>
      </div>
      <JsonBlockField
        value={draftValue}
        shape="object"
        emptyValue={{}}
        testidPrefix="g7le-injected-props-json"
        minHeight={80}
        invalidErrorKey="layout_editor.property_modal.injected_props.invalid_json"
        shapeErrorKey="layout_editor.property_modal.injected_props.invalid_json"
        t={t}
        onChange={(next) => {
          setDraftValue((next ?? {}) as Record<string, unknown>);
          setSavedOk(false);
        }}
        onValidityChange={(v) => {
          setValid(v);
          if (v) setError(null);
          setSavedOk(false);
        }}
      />
      {error && (
        <div style={errorText} data-testid="g7le-injected-props-error">
          {error}
        </div>
      )}
      {savedOk && (
        <div style={{ ...helpText, color: '#16a34a' }} data-testid="g7le-injected-props-saved">
          {t('layout_editor.property_modal.injected_props.saved')}
        </div>
      )}
      <div style={helpText}>{t('layout_editor.property_modal.injected_props.save_target')}</div>
      <button
        type="button"
        data-testid="g7le-injected-props-save"
        style={{ ...saveBtn, ...(saving || !valid ? saveBtnDisabled : null) }}
        disabled={saving || !valid}
        onClick={handleSave}
      >
        {saving
          ? t('layout_editor.property_modal.injected_props.saving')
          : t('layout_editor.property_modal.injected_props.save_button', { source: sourceLabel })}
      </button>
    </div>
  );
}

/**
 * 확장이 주입한 속성 섹션. injectedProps 가 비면 아무것도 렌더하지 않는다(디그레이드).
 */
export function InjectedPropsSection({
  injectedProps,
  t,
  onSaveInjectedProps,
}: InjectedPropsSectionProps): React.ReactElement | null {
  if (!Array.isArray(injectedProps) || injectedProps.length === 0) {
    return null;
  }

  return (
    <div style={sectionWrap} data-testid="g7le-injected-props-section">
      <div style={sectionTitle}>
        🧩 {t('layout_editor.property_modal.injected_props.title')}
      </div>
      <div style={helpText}>{t('layout_editor.property_modal.injected_props.help')}</div>
      {injectedProps.map((entry) => (
        <InjectedPropsEntryEditor
          key={entry.extensionId}
          entry={entry}
          t={t}
          onSave={(next) => onSaveInjectedProps(entry.extensionId, next)}
        />
      ))}
    </div>
  );
}
