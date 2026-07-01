/**
 * SaveFeedbackBanner.tsx — 저장 결과 사용자 피드백 배너
 *
 * useLayoutDocument.save 가 반환하는 SaveResult kind 에 따라:
 *  - success                       → 토스트 형태 short banner (자동 닫힘)
 *  - validation_failed             → 빨강 배너 + errors 키별 메시지
 *  - concurrent_modification       → 모달 + "최신 불러오기" / "내 변경 내용 보기" / "취소"
 *  - blocked_inactive_extension    → 노랑 배너 + 차단된 path 목록
 *  - network_error                 → 빨강 배너 + message
 *  - guard_no_document             → 회색 배너 (로드 전 저장 시도)
 *
 * 마운트 위치: LayoutEditorChrome 의 EditorToolbar 직하 — PreviewCanvas 격리
 * store swap 영향 없이 chrome 직속 React state 로 관리.
 *
 * @since engine-v1.50.0
 */

import React, { useEffect } from 'react';
import type { SaveResult } from '../hooks/useLayoutDocument';
import { useTranslation } from '../../TranslationContext';

export interface SaveFeedbackBannerProps {
  /** 가장 최근 SaveResult — null 이면 표시 안 함 */
  result: SaveResult | null;
  /** 배너 닫기 (사용자 dismiss / 자동 dismiss) */
  onDismiss: () => void;
  /** concurrent_modification 시 "최신 불러오기" 클릭 */
  onLoadLatest?: () => void;
  /** concurrent_modification 시 "내 변경 내용 보기" — UI 만 닫고 dirty 유지 */
  onKeepMyChanges?: () => void;
}

export function SaveFeedbackBanner(props: SaveFeedbackBannerProps): React.ReactElement | null {
  const { result, onDismiss, onLoadLatest, onKeepMyChanges } = props;
  const { t } = useTranslation();

  // success / network_error / blocked_inactive_extension / guard_no_document
  // 는 5초 후 자동 dismiss. concurrent / validation 은 사용자가 명시적으로 닫음.
  useEffect(() => {
    if (!result) return;
    if (result.kind === 'concurrent_modification' || result.kind === 'validation_failed') return;
    const timer = setTimeout(onDismiss, 5000);
    return () => clearTimeout(timer);
  }, [result, onDismiss]);

  if (!result) return null;

  if (result.kind === 'success') {
    return (
      <BannerShell tone="success" testId="g7le-save-banner-success" onDismiss={onDismiss}>
        {t('layout_editor.save.success')}
      </BannerShell>
    );
  }

  if (result.kind === 'validation_failed') {
    const errors = result.errors ?? {};
    const fieldEntries = Object.entries(errors);
    return (
      <BannerShell tone="error" testId="g7le-save-banner-validation" onDismiss={onDismiss}>
        <strong>{t('layout_editor.save.validation_failed')}</strong>
        {fieldEntries.length > 0 && (
          <ul style={errorListStyle} data-testid="g7le-save-banner-validation-errors">
            {fieldEntries.map(([field, messages]) => (
              <li key={field}>
                <code style={fieldCodeStyle}>{field}</code>: {messages.join(', ')}
              </li>
            ))}
          </ul>
        )}
      </BannerShell>
    );
  }

  if (result.kind === 'blocked_inactive_extension') {
    return (
      <BannerShell tone="warning" testId="g7le-save-banner-blocked" onDismiss={onDismiss}>
        <strong>{t('layout_editor.save.blocked_inactive_extension')}</strong>
        {result.blockedPaths.length > 0 && (
          <ul style={errorListStyle} data-testid="g7le-save-banner-blocked-paths">
            {result.blockedPaths.map((p) => (
              <li key={p}>
                <code style={fieldCodeStyle}>{p}</code>
              </li>
            ))}
          </ul>
        )}
      </BannerShell>
    );
  }

  if (result.kind === 'network_error') {
    return (
      <BannerShell tone="error" testId="g7le-save-banner-network" onDismiss={onDismiss}>
        <strong>{t('layout_editor.save.network_error_title')}</strong>
        <div style={{ marginTop: 4, fontSize: 12 }}>{result.message}</div>
      </BannerShell>
    );
  }

  if (result.kind === 'guard_no_document') {
    return (
      <BannerShell tone="neutral" testId="g7le-save-banner-guard-no-document" onDismiss={onDismiss}>
        {t('layout_editor.save.guard_no_document')}
      </BannerShell>
    );
  }

  // concurrent_modification → 모달
  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={t('layout_editor.save.concurrent.title')}
      data-testid="g7le-save-banner-concurrent"
      style={modalBackdropStyle}
    >
      <div style={modalCardStyle}>
        <h2 style={modalTitleStyle}>{t('layout_editor.save.concurrent.title')}</h2>
        <p style={modalBodyStyle}>{t('layout_editor.save.concurrent.message')}</p>
        <div style={modalVersionStyle}>
          {t('layout_editor.save.concurrent.version_info', {
            current: String(result.currentVersion),
            yours: String(result.yourVersion),
          })}
        </div>
        <div style={modalButtonRowStyle}>
          <button
            type="button"
            data-testid="g7le-save-banner-concurrent-load-latest"
            onClick={() => {
              onLoadLatest?.();
              onDismiss();
            }}
            style={primaryButtonStyle}
          >
            {t('layout_editor.save.concurrent.load_latest')}
          </button>
          <button
            type="button"
            data-testid="g7le-save-banner-concurrent-keep-mine"
            onClick={() => {
              onKeepMyChanges?.();
              onDismiss();
            }}
            style={secondaryButtonStyle}
          >
            {t('layout_editor.save.concurrent.view_my_changes')}
          </button>
          <button
            type="button"
            data-testid="g7le-save-banner-concurrent-cancel"
            onClick={onDismiss}
            style={tertiaryButtonStyle}
          >
            {t('layout_editor.save.concurrent.cancel')}
          </button>
        </div>
      </div>
    </div>
  );
}

interface BannerShellProps {
  tone: 'success' | 'error' | 'warning' | 'neutral';
  testId: string;
  onDismiss: () => void;
  children: React.ReactNode;
}

function BannerShell(props: BannerShellProps): React.ReactElement {
  const { tone, testId, onDismiss, children } = props;
  const colors = bannerColors[tone];
  return (
    <div
      role="status"
      data-testid={testId}
      style={{
        ...bannerBaseStyle,
        background: colors.bg,
        borderColor: colors.border,
        color: colors.fg,
      }}
    >
      <div style={{ flex: 1, minWidth: 0 }}>{children}</div>
      <button
        type="button"
        onClick={onDismiss}
        aria-label="close"
        data-testid={`${testId}-close`}
        style={dismissButtonStyle}
      >
        ×
      </button>
    </div>
  );
}

const bannerColors: Record<string, { bg: string; border: string; fg: string }> = {
  success: { bg: '#dcfce7', border: '#86efac', fg: '#14532d' },
  error: { bg: '#fee2e2', border: '#fca5a5', fg: '#7f1d1d' },
  warning: { bg: '#fef3c7', border: '#fcd34d', fg: '#78350f' },
  neutral: { bg: '#f1f5f9', border: '#cbd5e1', fg: '#334155' },
};

const bannerBaseStyle: React.CSSProperties = {
  display: 'flex',
  alignItems: 'flex-start',
  gap: 12,
  padding: '12px 16px',
  border: '1px solid',
  borderRadius: 0,
  fontSize: 13,
  lineHeight: 1.5,
};

const dismissButtonStyle: React.CSSProperties = {
  background: 'transparent',
  border: 'none',
  fontSize: 18,
  cursor: 'pointer',
  lineHeight: 1,
  color: 'inherit',
  padding: '0 4px',
  flexShrink: 0,
};

const errorListStyle: React.CSSProperties = {
  margin: '8px 0 0',
  paddingLeft: 20,
  fontSize: 12,
};

const fieldCodeStyle: React.CSSProperties = {
  background: 'rgba(0, 0, 0, 0.06)',
  padding: '1px 4px',
  borderRadius: 3,
  fontFamily: 'monospace',
};

const modalBackdropStyle: React.CSSProperties = {
  position: 'fixed',
  inset: 0,
  background: 'rgba(15, 23, 42, 0.6)',
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'center',
  zIndex: 10000,
};

const modalCardStyle: React.CSSProperties = {
  background: '#fff',
  borderRadius: 8,
  padding: 24,
  width: 'min(480px, 90vw)',
  boxShadow: '0 10px 25px rgba(0,0,0,0.15)',
};

const modalTitleStyle: React.CSSProperties = {
  margin: '0 0 8px',
  fontSize: 16,
  fontWeight: 600,
  color: '#0f172a',
};

const modalBodyStyle: React.CSSProperties = {
  margin: '0 0 12px',
  color: '#334155',
  fontSize: 13,
  lineHeight: 1.5,
};

const modalVersionStyle: React.CSSProperties = {
  marginBottom: 16,
  padding: '8px 12px',
  background: '#f8fafc',
  borderRadius: 6,
  fontSize: 12,
  color: '#64748b',
  fontFamily: 'monospace',
};

const modalButtonRowStyle: React.CSSProperties = {
  display: 'flex',
  gap: 8,
  justifyContent: 'flex-end',
};

const primaryButtonStyle: React.CSSProperties = {
  padding: '8px 14px',
  background: '#2563eb',
  color: '#fff',
  border: 'none',
  borderRadius: 6,
  fontSize: 13,
  fontWeight: 600,
  cursor: 'pointer',
};

const secondaryButtonStyle: React.CSSProperties = {
  padding: '8px 14px',
  background: '#fff',
  color: '#334155',
  border: '1px solid #cbd5e1',
  borderRadius: 6,
  fontSize: 13,
  fontWeight: 500,
  cursor: 'pointer',
};

const tertiaryButtonStyle: React.CSSProperties = {
  padding: '8px 14px',
  background: 'transparent',
  color: '#64748b',
  border: 'none',
  borderRadius: 6,
  fontSize: 13,
  cursor: 'pointer',
};
