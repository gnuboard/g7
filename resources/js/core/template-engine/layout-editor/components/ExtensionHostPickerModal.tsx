/**
 * ExtensionHostPickerModal.tsx — 확장 편집 대표 호스트 레이아웃 선택 모달
 *
 * extension_point 타입 확장이 **여러 호스트 레이아웃**에 주입될 수 있을 때(예: 같은 확장점이
 * 여러 화면에 존재), 확장 편집 모드 캔버스는 어느 호스트 레이아웃 위에서 확장 조각을 합성해
 * 렌더할지 확정해야 한다(호스트 병합 렌더). 본 모달이 후보 호스트 목록을 보여주고
 * 사용자가 대표 호스트를 고르면 `onSelect(hostLayoutName)` 로 확정한다.
 *
 * overlay 타입은 `target_layout` 1개로 확정되므로 이 모달이 뜨지 않는다(host 1개 → 즉시 진입).
 *
 * 편집기 코어 규정(레이아웃 편집기 CSS 라이브러리 비종속)에 따라 Tailwind/Bootstrap 토큰을
 * 쓰지 않고 g7le-* 클래스 + 인라인 표준 CSS 로만 구성한다.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { useTranslation } from '../../TranslationContext';

export interface ExtensionHostPickerModalProps {
  /** 후보 호스트 레이아웃명 목록 (2개 이상일 때만 본 모달이 렌더됨) */
  hostLayouts: string[];
  /** 확장 라벨(출처·대상명) — 헤더 안내에 표시 (옵션) */
  extensionLabel?: string | null;
  /** 대표 호스트 확정 콜백 */
  onSelect: (hostLayoutName: string) => void;
}

/**
 * 대표 호스트 레이아웃 선택 모달. `hostLayouts` 가 2개 이상일 때만 LayoutEditorChrome 이
 * 렌더한다(needsHostPicker). 선택 시 onSelect 로 확정하면 useExtensionDocument 가 그 호스트를
 * 병합 렌더한다.
 */
export function ExtensionHostPickerModal({
  hostLayouts,
  extensionLabel,
  onSelect,
}: ExtensionHostPickerModalProps): React.ReactElement {
  const { t } = useTranslation();

  return (
    <div
      className="g7le-host-picker__backdrop"
      data-testid="g7le-host-picker"
      role="dialog"
      aria-modal="true"
      aria-label={t('layout_editor.host_picker.title')}
      style={{
        position: 'fixed',
        inset: 0,
        zIndex: 9500,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        background: 'rgba(15, 23, 42, 0.55)',
      }}
    >
      <div
        className="g7le-host-picker__panel"
        style={{
          width: 'min(520px, 92vw)',
          maxHeight: '80vh',
          overflow: 'auto',
          background: '#ffffff',
          color: '#0f172a',
          borderRadius: 12,
          boxShadow: '0 20px 48px rgba(15, 23, 42, 0.28)',
          padding: '20px 22px',
        }}
      >
        <h2
          className="g7le-host-picker__title"
          style={{ margin: '0 0 6px', fontSize: 16, fontWeight: 700 }}
        >
          {t('layout_editor.host_picker.title')}
        </h2>
        <p
          className="g7le-host-picker__desc"
          style={{ margin: '0 0 16px', fontSize: 13, color: '#475569', lineHeight: 1.6 }}
        >
          {extensionLabel
            ? t('layout_editor.host_picker.description_with_label', { label: extensionLabel })
            : t('layout_editor.host_picker.description')}
        </p>
        <ul
          className="g7le-host-picker__list"
          style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: 8 }}
        >
          {hostLayouts.map((host) => (
            <li key={host}>
              <button
                type="button"
                className="g7le-host-picker__item"
                data-testid="g7le-host-picker-item"
                data-host={host}
                onClick={() => onSelect(host)}
                style={{
                  width: '100%',
                  textAlign: 'left',
                  padding: '12px 14px',
                  border: '1px solid #e2e8f0',
                  borderRadius: 8,
                  background: '#f8fafc',
                  cursor: 'pointer',
                  fontSize: 13,
                  fontWeight: 600,
                  color: '#0f172a',
                  display: 'flex',
                  alignItems: 'center',
                  gap: 8,
                }}
              >
                <span aria-hidden style={{ fontSize: 14 }}>
                  🖼
                </span>
                <span style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis' }}>
                  {host}
                </span>
                <span aria-hidden style={{ color: '#94a3b8' }}>
                  →
                </span>
              </button>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}
