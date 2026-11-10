/**
 * SpecLoadErrorBanner.tsx — 편집기 스펙 묶음을 받지 못했을 때의 알림과 다시 시도 (#869, 설계 확정 2026-11-09)
 *
 * `GET /api/templates/{id}/editor-spec-bundle` 이 실패하면(4xx·5xx·네트워크) 팔레트·스타일 컨트롤·상태 전환
 * 토글이 빈다. 종전에는 아무 표시가 없어 편집기가 정상인 것처럼 보였다. 이 알림이 그 사실을 알리고, 다시 시도는
 * 묶음을 다시 요청한다(개별 엔드포인트로 되돌아가지 않는다 — 고르지 않은 안).
 *
 * 마운트 위치: LayoutEditorChrome 의 EditorToolbar 직하(SaveFeedbackBanner 와 같은 줄). 닫기 단추는 두지 않는다 —
 * 스펙 없이 편집하면 컨트롤이 비어 있는 상태가 이어지므로 다시 받기 전까지 알림이 남는다.
 *
 * 색은 밝은 색 고정이다. 편집기 화면 틀이 다크 모드를 따르지 않기 때문이다 — 셸 바탕과 글자(LayoutEditorChrome.tsx
 * `background: '#f8fafc'`, `color: '#0f172a'`), 툴바 펼침 상자(EditorToolbar.tsx `background: '#ffffff'`)가 고정이고,
 * 편집기 안의 다크 처리는 미리보기 내용(PreviewCanvas.tsx `.g7le-preview-dark`)에만 있다. 틀이 다크를 따르게 되면 함께 짝을 넣는다.
 *
 * @since engine-v1.89.0
 */

import React from 'react';
import { useTranslation } from '../../TranslationContext';

export interface SpecLoadErrorBannerProps {
  /** 묶음 요청이 실패했는지 — 거짓이면 아무것도 그리지 않는다 */
  failed: boolean;
  /** 다시 받는 중인지 — 단추를 잠그고 문구를 바꾼다 */
  retrying: boolean;
  /** 다시 시도 */
  onRetry: () => void;
}

export function SpecLoadErrorBanner(props: SpecLoadErrorBannerProps): React.ReactElement | null {
  const { t } = useTranslation();
  if (!props.failed) return null;

  return (
    <div
      role="alert"
      data-testid="g7le-spec-load-error"
      className="g7le-spec-load-error"
      style={{
        display: 'flex',
        flexWrap: 'wrap',
        alignItems: 'center',
        gap: 8,
        padding: '8px 12px',
        background: '#fef2f2',
        color: '#991b1b',
        borderBottom: '1px solid #fecaca',
        fontSize: 13,
      }}
    >
      <span aria-hidden="true">⚠</span>
      <span style={{ flex: '1 1 240px', minWidth: 0 }}>
        <strong>{t('layout_editor.chrome.spec_load_error.title')}</strong>{' '}
        {t('layout_editor.chrome.spec_load_error.message')}
      </span>
      <button
        type="button"
        data-testid="g7le-spec-load-error-retry"
        onClick={props.onRetry}
        disabled={props.retrying}
        aria-busy={props.retrying}
        style={{
          padding: '4px 12px',
          borderRadius: 6,
          border: '1px solid #b91c1c',
          background: props.retrying ? '#fee2e2' : '#ffffff',
          color: '#991b1b',
          fontWeight: 600,
          cursor: props.retrying ? 'wait' : 'pointer',
        }}
      >
        {props.retrying ? t('layout_editor.chrome.spec_load_error.retrying') : t('layout_editor.chrome.spec_load_error.retry')}
      </button>
    </div>
  );
}
