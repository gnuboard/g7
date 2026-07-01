/**
 * AccessErrorPanel.tsx — 레이아웃 로드 실패 안내 패널
 *
 * `useLayoutDocument` 가 반환하는 `LayoutLoadError` 의 `kind` 별로 분기해
 * 아이콘 + 제목 + 설명 + (필요 권한 칩) + 액션 버튼을 렌더한다.
 *
 * - `unauthorized` (401): 비로그인/세션 만료 — 즉시 로그인 페이지 자동 리다이렉션
 *   (`AccessRedirectGate` 가 effect 로 처리). 본 컴포넌트는 리다이렉트 직전의
 *   1~2 frame 동안 "로그인 페이지로 이동 중" 안내를 보여 준다.
 * - `forbidden` (403): 권한 부족 — 필요 권한 칩 노출 + "홈으로" 버튼
 * - `not_found` / `server_error` / `network` / `unknown`: 각각 다른 아이콘·문구
 *
 * 모든 문자열은 `$t:layout_editor.access_error.*` 키 — chrome 다국어 자원.
 *
 * @since engine-v1.50.0
 */

import React, { useEffect } from 'react';
import { useTranslation } from '../../TranslationContext';
import { AuthManager } from '../../../auth/AuthManager';
import type { EditorAccessError, EditorErrorKind } from '../types/editorErrors';

export interface AccessErrorPanelProps {
  error: EditorAccessError;
}

interface ErrorPresentation {
  /** 큰 이모지/심볼 — 외부 폰트 의존 없이 즉시 렌더 */
  icon: string;
  /** 카드 강조 색상 (배경 그라데이션 + 아이콘 색) */
  tone: 'amber' | 'rose' | 'slate' | 'blue';
  titleKey: string;
  messageKey: string;
  /** 필요 권한 칩 표시 여부 */
  showRequiredPermissions: boolean;
}

function presentationFor(kind: EditorErrorKind): ErrorPresentation {
  switch (kind) {
    case 'unauthorized':
      return {
        icon: '🔒',
        tone: 'blue',
        titleKey: 'layout_editor.access_error.unauthorized.title',
        messageKey: 'layout_editor.access_error.unauthorized.message',
        showRequiredPermissions: true,
      };
    case 'forbidden':
      return {
        icon: '⛔',
        tone: 'rose',
        titleKey: 'layout_editor.access_error.forbidden.title',
        messageKey: 'layout_editor.access_error.forbidden.message',
        showRequiredPermissions: true,
      };
    case 'not_found':
      return {
        icon: '🗂️',
        tone: 'slate',
        titleKey: 'layout_editor.access_error.not_found.title',
        messageKey: 'layout_editor.access_error.not_found.message',
        showRequiredPermissions: false,
      };
    case 'server_error':
      return {
        icon: '🛠️',
        tone: 'amber',
        titleKey: 'layout_editor.access_error.server_error.title',
        messageKey: 'layout_editor.access_error.server_error.message',
        showRequiredPermissions: false,
      };
    case 'network':
      return {
        icon: '📡',
        tone: 'amber',
        titleKey: 'layout_editor.access_error.network.title',
        messageKey: 'layout_editor.access_error.network.message',
        showRequiredPermissions: false,
      };
    default:
      return {
        icon: '⚠️',
        tone: 'slate',
        titleKey: 'layout_editor.access_error.unknown.title',
        messageKey: 'layout_editor.access_error.unknown.message',
        showRequiredPermissions: false,
      };
  }
}

const toneStyles: Record<ErrorPresentation['tone'], { bg: string; border: string; iconBg: string }> = {
  blue: { bg: '#eff6ff', border: '#bfdbfe', iconBg: '#dbeafe' },
  rose: { bg: '#fff1f2', border: '#fecdd3', iconBg: '#ffe4e6' },
  amber: { bg: '#fffbeb', border: '#fde68a', iconBg: '#fef3c7' },
  slate: { bg: '#f8fafc', border: '#e2e8f0', iconBg: '#f1f5f9' },
};

/**
 * 401 감지 시 로그인 페이지로 자동 이동시키는 effect-only 컴포넌트.
 *
 * `AuthManager.getLoginRedirectUrl` 가 현재 URL 을 redirect 파라미터로 인코딩하므로
 * 로그인 후 정확히 같은 편집기 화면으로 복귀한다(결정 2).
 */
function AccessRedirectGate({ error }: { error: EditorAccessError }): null {
  useEffect(() => {
    if (error.kind !== 'unauthorized') return;
    if (typeof window === 'undefined') return;

    const auth = AuthManager.getInstance();
    const returnUrl = `${window.location.pathname}${window.location.search}`;
    const redirectUrl = auth.getLoginRedirectUrl('admin', returnUrl, 'session_expired');
    // 짧은 지연 — 사용자가 "로그인 페이지로 이동 중" 안내를 볼 수 있게 한다.
    const timer = window.setTimeout(() => {
      window.location.href = redirectUrl;
    }, 600);
    return () => window.clearTimeout(timer);
  }, [error.kind]);

  return null;
}

export function AccessErrorPanel({ error }: AccessErrorPanelProps): React.ReactElement {
  const { t } = useTranslation();
  const presentation = presentationFor(error.kind);
  const tone = toneStyles[presentation.tone];

  const handleGoHome = (): void => {
    if (typeof window !== 'undefined') {
      window.location.href = '/admin';
    }
  };

  const handleRetry = (): void => {
    if (typeof window !== 'undefined') {
      window.location.reload();
    }
  };

  const handleSignIn = (): void => {
    if (typeof window === 'undefined') return;
    const auth = AuthManager.getInstance();
    const returnUrl = `${window.location.pathname}${window.location.search}`;
    window.location.href = auth.getLoginRedirectUrl('admin', returnUrl);
  };

  return (
    <div
      className="g7le-access-error"
      data-testid="g7le-access-error"
      data-error-kind={error.kind}
      style={{
        flex: 1,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 24,
        background: '#f1f5f9',
      }}
    >
      <AccessRedirectGate error={error} />
      <div
        style={{
          maxWidth: 480,
          width: '100%',
          background: tone.bg,
          border: `1px solid ${tone.border}`,
          borderRadius: 12,
          padding: '28px 32px',
          boxShadow: '0 2px 8px rgba(15, 23, 42, 0.05)',
          textAlign: 'center',
        }}
      >
        <div
          style={{
            width: 64,
            height: 64,
            margin: '0 auto 16px',
            borderRadius: '50%',
            background: tone.iconBg,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            fontSize: 32,
            lineHeight: 1,
          }}
          aria-hidden="true"
        >
          {presentation.icon}
        </div>
        <h2
          data-testid="g7le-access-error-title"
          style={{
            margin: '0 0 8px',
            fontSize: 18,
            fontWeight: 700,
            color: '#0f172a',
          }}
        >
          {t(presentation.titleKey)}
        </h2>
        <p
          data-testid="g7le-access-error-message"
          style={{
            margin: '0 0 16px',
            fontSize: 13,
            lineHeight: 1.6,
            color: '#475569',
          }}
        >
          {t(presentation.messageKey)}
        </p>
        {presentation.showRequiredPermissions && error.requiredPermissions && (
          <div
            data-testid="g7le-access-error-permissions"
            style={{
              display: 'inline-flex',
              flexWrap: 'wrap',
              gap: 6,
              justifyContent: 'center',
              padding: '8px 12px',
              marginBottom: 16,
              background: 'rgba(15, 23, 42, 0.04)',
              borderRadius: 8,
              fontSize: 12,
              color: '#475569',
            }}
          >
            <span style={{ fontWeight: 600 }}>
              {t('layout_editor.access_error.required_permission_label')}
            </span>
            {error.requiredPermissions.split(/[,\s]+/).filter(Boolean).map((perm) => (
              <code
                key={perm}
                style={{
                  padding: '2px 6px',
                  background: '#fff',
                  border: '1px solid #e2e8f0',
                  borderRadius: 4,
                  fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
                  fontSize: 11,
                  color: '#1e293b',
                }}
              >
                {perm}
              </code>
            ))}
          </div>
        )}
        {/* Collapsed raw debug message — kept low-visibility for end users while
           still exposing the underlying cause for operators / developers. */}
        {error.message && (
          <details
            data-testid="g7le-access-error-detail"
            style={{
              marginBottom: 16,
              fontSize: 11,
              color: '#94a3b8',
            }}
          >
            <summary style={{ cursor: 'pointer' }}>
              {t('layout_editor.access_error.detail_summary')}
            </summary>
            <pre
              style={{
                marginTop: 8,
                padding: 8,
                background: '#fff',
                border: '1px solid #e2e8f0',
                borderRadius: 6,
                overflowX: 'auto',
                whiteSpace: 'pre-wrap',
                wordBreak: 'break-word',
                textAlign: 'left',
                fontSize: 11,
                color: '#475569',
              }}
            >
              {error.status > 0 ? `HTTP ${error.status}\n` : ''}
              {error.message}
            </pre>
          </details>
        )}
        <div
          style={{
            display: 'flex',
            justifyContent: 'center',
            gap: 8,
            flexWrap: 'wrap',
          }}
        >
          {error.kind === 'unauthorized' && (
            <button
              type="button"
              data-testid="g7le-access-error-action-signin"
              onClick={handleSignIn}
              style={primaryButtonStyle}
            >
              {t('layout_editor.access_error.action.sign_in')}
            </button>
          )}
          {error.kind === 'forbidden' && (
            <button
              type="button"
              data-testid="g7le-access-error-action-home"
              onClick={handleGoHome}
              style={primaryButtonStyle}
            >
              {t('layout_editor.access_error.action.go_home')}
            </button>
          )}
          {(error.kind === 'not_found' ||
            error.kind === 'server_error' ||
            error.kind === 'network' ||
            error.kind === 'unknown') && (
            <button
              type="button"
              data-testid="g7le-access-error-action-retry"
              onClick={handleRetry}
              style={primaryButtonStyle}
            >
              {t('layout_editor.access_error.action.retry')}
            </button>
          )}
          {error.kind !== 'unauthorized' && (
            <button
              type="button"
              data-testid="g7le-access-error-action-home-secondary"
              onClick={handleGoHome}
              style={secondaryButtonStyle}
            >
              {t('layout_editor.access_error.action.go_admin_home')}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

const primaryButtonStyle: React.CSSProperties = {
  padding: '8px 16px',
  background: '#2563eb',
  color: '#fff',
  border: '1px solid #1d4ed8',
  borderRadius: 6,
  fontSize: 13,
  fontWeight: 600,
  cursor: 'pointer',
};

const secondaryButtonStyle: React.CSSProperties = {
  padding: '8px 16px',
  background: '#fff',
  color: '#475569',
  border: '1px solid #cbd5e1',
  borderRadius: 6,
  fontSize: 13,
  fontWeight: 500,
  cursor: 'pointer',
};
