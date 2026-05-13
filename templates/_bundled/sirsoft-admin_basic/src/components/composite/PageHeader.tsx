import React from 'react';
import { Div } from '../basic/Div';
import { H1 } from '../basic/H1';
import { P } from '../basic/P';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { Breadcrumb, BreadcrumbItem } from './Breadcrumb';

/**
 * 탭 아이템 인터페이스
 */
export interface TabItem {
  id: string | number;
  label: string;
  value: string;
  active?: boolean;
  badge?: string | number;
}

/**
 * 액션 버튼 인터페이스
 */
export interface ActionButton {
  id: string | number;
  label: string;
  onClick?: () => void;
  variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
  iconName?: IconName;
  disabled?: boolean;
}

/**
 * PageHeader Props
 */
export interface PageHeaderProps {
  title: string;
  description?: string;
  breadcrumbItems?: BreadcrumbItem[];
  tabs?: TabItem[];
  onTabChange?: (value: string) => void;
  actions?: ActionButton[];
  className?: string;
  children?: React.ReactNode;
}

/**
 * PageHeader 컴포넌트
 *
 * 페이지 헤더 - admin_dashboard.json의 page_header 스타일과 동일
 * 배경색 없음, flex items-center justify-between mb-6 레이아웃
 *
 * @example
 * ```tsx
 * <PageHeader
 *   title="사용자 관리"
 *   description="시스템에 등록된 사용자 목록을 조회하고 관리합니다."
 *   actions={[
 *     { id: 1, label: '사용자 추가', variant: 'primary', iconName: 'plus' }
 *   ]}
 * />
 * ```
 */
export const PageHeader: React.FC<PageHeaderProps> = ({
  title,
  description,
  breadcrumbItems,
  tabs,
  onTabChange,
  actions,
  className = '',
  children,
}) => {
  /**
   * Variant별 버튼 스타일
   */
  const getButtonClasses = (variant: ActionButton['variant'] = 'secondary'): string => {
    const baseClasses = 'px-4 py-2 rounded-lg font-medium transition-colors flex items-center gap-2';

    const variantClasses: Record<NonNullable<ActionButton['variant']>, string> = {
      primary: 'bg-blue-600 text-white hover:bg-blue-700',
      secondary: 'bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-gray-200 hover:bg-gray-300 dark:hover:bg-gray-600',
      danger: 'bg-red-600 text-white hover:bg-red-700',
      ghost: 'p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800',
    };

    return `${baseClasses} ${variantClasses[variant]}`;
  };

  return (
    <Div className={className}>
      {/* 브레드크럼 - Breadcrumb 컴포넌트 재사용 */}
      {breadcrumbItems && breadcrumbItems.length > 0 && (
        <Div className="mb-4">
          <Breadcrumb items={breadcrumbItems} />
        </Div>
      )}

      {/* 제목 및 액션 버튼 - admin_dashboard.json 스타일 */}
      <Div className="flex items-center justify-between mb-6">
        <Div>
          <H1 className="text-xl sm:text-2xl font-semibold text-gray-900 dark:text-white">{title}</H1>
          {description && (
            <P className="text-sm text-gray-600 dark:text-gray-400 mt-1">{description}</P>
          )}
        </Div>

        {/* 액션 버튼 또는 children */}
        {children ? (
          <Div className="flex items-center gap-2">{children}</Div>
        ) : actions && actions.length > 0 ? (
          <Div className="flex items-center gap-2">
            {actions.map((action) => (
              <Button
                key={action.id}
                onClick={action.onClick}
                disabled={action.disabled}
                className={getButtonClasses(action.variant)}
              >
                {action.iconName && (
                  <Icon name={action.iconName} className="w-5 h-5" />
                )}
                {action.variant !== 'ghost' && action.label && (
                  <Div>{action.label}</Div>
                )}
              </Button>
            ))}
          </Div>
        ) : null}
      </Div>

      {/* 탭 네비게이션 */}
      {tabs && tabs.length > 0 && (
        <Div className="flex gap-6 border-b border-gray-200 dark:border-gray-700 mb-6">
          {tabs.map((tab) => (
            <Button
              key={tab.id}
              onClick={() => onTabChange?.(tab.value)}
              className={`
                px-1 py-3 border-b-2 transition-colors font-medium flex items-center gap-2 -mb-px
                ${tab.active
                  ? 'border-blue-600 text-blue-600 dark:text-blue-400'
                  : 'border-transparent text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200'
                }
              `}
            >
              <Div>{tab.label}</Div>
              {tab.badge !== undefined && (
                <Div className={`
                  px-2 py-0.5 rounded-full text-xs font-semibold
                  ${tab.active
                    ? 'bg-blue-100 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400'
                    : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400'
                  }
                `}>
                  {tab.badge}
                </Div>
              )}
            </Button>
          ))}
        </Div>
      )}
    </Div>
  );
};
