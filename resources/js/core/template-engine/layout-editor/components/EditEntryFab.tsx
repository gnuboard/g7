/**
 * EditEntryFab.tsx
 *
 * 운영 화면 좌측 하단 편집 진입 플로팅 액션 버튼 — 8.2.2.
 *
 * 본 컴포넌트는 다른 layout-editor/ 자산과 달리 **일반 렌더 모드** 에 마운트
 * 된다 (편집기 셸 외부). 진입 권한 보유자에게만 노출.
 *
 * 클릭 시 `/admin/layout-editor/{현재템플릿}?route={현재 path}` 로 **같은 탭** 에서
 * 이동해 보던 화면이 캔버스에 선택된 채 편집기 진입 (새 탭 진입 금지,
 * 로그인 풀려 재로그인 후 진입할 때도 같은 탭 유지).
 *
 * `translate` prop 받지 않음 — 코어 TranslationEngine 자동 해석.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { useTranslation } from '../../TranslationContext';
import { buildEditEntryUrl, shouldRenderEditEntryFab } from '../hooks/useEditEntryFab';

export interface EditEntryFabProps {
  /** 현재 운영 사이트가 부팅된 템플릿 식별자 */
  templateIdentifier: string;
  /** 현재 URL pathname */
  pathname: string;
  /** 인증 사용자 여부 */
  isAuthenticated: boolean;
  /** core.templates.layouts.edit 권한 보유 여부 */
  hasLayoutEditPermission: boolean;
  /** 현재 보고 있는 라우트 path (편집기 진입 시 ?route= 로 전달) */
  currentRoutePath: string;
  /**
   * 진입 함수 — 기본값은 같은 탭 이동 (`window.location.assign`). 테스트 주입 가능.
   *
   */
  openWindow?: (url: string) => void;
}

export function EditEntryFab(props: EditEntryFabProps): React.ReactElement | null {
  const { t } = useTranslation();

  const shouldShow = shouldRenderEditEntryFab({
    pathname: props.pathname,
    isAuthenticated: props.isAuthenticated,
    hasLayoutEditPermission: props.hasLayoutEditPermission,
  });

  if (!shouldShow) return null;

  const onClick = (): void => {
    const url = buildEditEntryUrl(props.templateIdentifier, props.currentRoutePath);
    if (props.openWindow) {
      props.openWindow(url);
    } else if (typeof window !== 'undefined') {
      // 같은 탭 이동 — 새 탭/창 금지
      window.location.assign(url);
    }
  };

  return (
    <button
      type="button"
      className="g7le-edit-entry-fab"
      data-testid="g7le-edit-entry-fab"
      onClick={onClick}
      title={t('layout_editor.chrome.fab.tooltip')}
      style={{
        position: 'fixed',
        left: 16,
        bottom: 16,
        zIndex: 8500,
        padding: '8px 14px',
        borderRadius: 999,
        border: 'none',
        background: '#0f172a',
        color: '#ffffff',
        boxShadow: '0 2px 12px rgba(15, 23, 42, 0.2)',
        cursor: 'pointer',
        fontSize: 13,
        fontWeight: 600,
      }}
    >
      ✎ {t('layout_editor.chrome.fab.label')}
    </button>
  );
}
