import React, { useCallback } from 'react';
import { Div } from '../basic/Div';
import { A } from '../basic/A';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import type { EditorAttrs } from '../../types';
import { useRenderWidth } from '../../hooks/useRenderWidth';
import { classAtWidth } from '../layout/widthBreakpoints';
import { keyboardActivationProps, FOCUS_VISIBLE_RING } from '../../support/keyboardActivation';

/**
 * 빠른 링크 인터페이스
 */
export interface QuickLink {
  id: string | number;
  label: string;
  url: string;
  iconName?: IconName;
}

/**
 * AdminFooter Props
 */
export interface AdminFooterProps {
  copyright?: string;
  version?: string;
  quickLinks?: QuickLink[];
  className?: string;
  copyrightModalId?: string;
  changelogModalId?: string;
  /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
}

/**
 * AdminFooter 컴포넌트
 *
 * 관리자 푸터 - 버전 정보, 빠른 링크
 *
 * @example
 * ```tsx
 * <AdminFooter
 *   copyright="© 2026 G7"
 *   version="1.0.0"
 *   quickLinks={[
 *     { id: 1, label: '문서', url: '/docs', iconName: IconName.FileText },
 *     { id: 2, label: '지원', url: '/support', iconName: IconName.HelpCircle }
 *   ]}
 * />
 * ```
 */
export const AdminFooter: React.FC<AdminFooterProps> = ({
  copyright = '© 2026 G7',
  version,
  quickLinks = [],
  className = '',
  changelogModalId,
  copyrightModalId,
  id,
  editorAttrs,
}) => {
  // 줄 방향·구분점을 고르는 렌더 폭 — Provider 폭 우선(편집기 미리보기에서는 기기 폭)
  const renderWidth = useRenderWidth();
  const handleVersionClick = useCallback(() => {
    if (changelogModalId) {
      const G7Core = (window as any).G7Core;

      G7Core?.dispatch?.({
        handler: 'sequence',
        actions: [
          {
            handler: 'apiCall',
            auth_required: true,
            target: '/api/admin/changelog',
            params: {
              method: 'GET',
            },
            onSuccess: [
              {
                handler: 'setState',
                params: {
                  target: 'global',
                  coreChangelogContent: '{{response.data.content}}',
                },
              },
              {
                handler: 'openModal',
                target: changelogModalId,
              },
            ],
            onError: [
              {
                handler: 'openModal',
                target: changelogModalId,
              },
            ],
          },
        ],
      });
    }
  }, [changelogModalId]);

  const handleCopyrightClick = useCallback(() => {
    if (copyrightModalId) {
      const G7Core = (window as any).G7Core;

      G7Core?.dispatch?.({
        handler: 'sequence',
        actions: [
          {
            handler: 'apiCall',
            auth_required: true,
            target: '/api/admin/license',
            params: {
              method: 'GET',
            },
            onSuccess: [
              {
                handler: 'setState',
                params: {
                  target: 'global',
                  coreLicenseContent: '{{response.data.content}}',
                },
              },
              {
                handler: 'openModal',
                target: copyrightModalId,
              },
            ],
            onError: [
              {
                handler: 'openModal',
                target: copyrightModalId,
              },
            ],
          },
        ],
      });
    }
  }, [copyrightModalId]);

  return (
    <Div className={`admin-footer ${className}`} id={id} {...editorAttrs}>
      {/* 768 이상 가로 배치 — 폭 미디어가 없는 바탕(`admin-footer-bar`)에 렌더 폭으로 `flex-row` 를 더한다 */}
      <Div className={`admin-footer-bar ${classAtWidth(renderWidth, 'md', 'flex-row')}`.trim()}>
        {/* 왼쪽: 저작권 및 버전 정보 */}
        <Div className="admin-footer-group">
          <Span
            className={copyrightModalId ? `admin-footer-link-hover rounded ${FOCUS_VISIBLE_RING}` : ''}
            onClick={copyrightModalId ? handleCopyrightClick : undefined}
            // 창을 여는 칸은 키보드로도 연다(키보드 접근)
            {...keyboardActivationProps(copyrightModalId ? handleCopyrightClick : undefined)}
          >
            {copyright}
          </Span>
          {version && (
            <>
              <Span className={classAtWidth(renderWidth, 'md', 'inline', 'hidden')}>•</Span>
              <Span
                className={`flex-center gap-2 ${changelogModalId ? `admin-footer-link-hover rounded ${FOCUS_VISIBLE_RING}` : ''}`}
                onClick={changelogModalId ? handleVersionClick : undefined}
                {...keyboardActivationProps(changelogModalId ? handleVersionClick : undefined)}
              >
                <Icon name={IconName.Tag} className="w-4 h-4" />
                <Span>v{version}</Span>
              </Span>
            </>
          )}
        </Div>

        {/* 오른쪽: 빠른 링크 */}
        {quickLinks.length > 0 && (
          <Div className="flex-center gap-4">
            {quickLinks.map((link) => (
              <A key={link.id} href={link.url} className="admin-footer-link">
                {link.iconName && <Icon name={link.iconName} className="w-4 h-4" />}
                <Span>{link.label}</Span>
              </A>
            ))}
          </Div>
        )}
      </Div>
    </Div>
  );
};
