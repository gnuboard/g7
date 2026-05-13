import React, { useCallback } from 'react';
import { Div } from '../basic/Div';
import { A } from '../basic/A';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';

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
}) => {
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
    <Div
      className={`
        bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 px-6 py-4
        ${className}
      `}
    >
      <Div className="flex flex-col md:flex-row items-center justify-between gap-4">
        {/* 왼쪽: 저작권 및 버전 정보 */}
        <Div className="flex items-center gap-4 text-sm text-gray-600 dark:text-gray-400">
          <Span
            className={copyrightModalId ? 'cursor-pointer hover:text-blue-600 dark:hover:text-blue-400 transition-colors' : ''}
            onClick={copyrightModalId ? handleCopyrightClick : undefined}
          >
            {copyright}
          </Span>
          {version && (
            <>
              <Span className="hidden md:inline">•</Span>
              <Span
                className={`flex items-center gap-2 ${changelogModalId ? 'cursor-pointer hover:text-blue-600 dark:hover:text-blue-400 transition-colors' : ''}`}
                onClick={changelogModalId ? handleVersionClick : undefined}
              >
                <Icon name={IconName.Tag} className="w-4 h-4" />
                <Span>v{version}</Span>
              </Span>
            </>
          )}
        </Div>

        {/* 오른쪽: 빠른 링크 */}
        {quickLinks.length > 0 && (
          <Div className="flex items-center gap-4">
            {quickLinks.map((link) => (
              <A
                key={link.id}
                href={link.url}
                className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 transition-colors"
              >
                {link.iconName && (
                  <Icon name={link.iconName} className="w-4 h-4" />
                )}
                <Span>{link.label}</Span>
              </A>
            ))}
          </Div>
        )}
      </Div>
    </Div>
  );
};
