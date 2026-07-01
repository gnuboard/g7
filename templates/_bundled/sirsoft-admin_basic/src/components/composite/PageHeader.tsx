import React from 'react';
import { Div } from '../basic/Div';
import { H1 } from '../basic/H1';
import { P } from '../basic/P';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { Breadcrumb, BreadcrumbItem } from './Breadcrumb';
import type { EditorAttrs } from '../../types';

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
  /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
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
  id,
  editorAttrs,
}) => {
  /**
   * Variant별 버튼 스타일
   */
  const getButtonClasses = (variant: ActionButton['variant'] = 'secondary'): string => {
    const variantClasses: Record<NonNullable<ActionButton['variant']>, string> = {
      primary: 'page-action-primary',
      secondary: 'page-action-secondary',
      danger: 'page-action-danger',
      ghost: 'page-action-ghost',
    };

    return variantClasses[variant];
  };

  return (
    <Div className={className} id={id} {...editorAttrs}>
      {/* 브레드크럼 - Breadcrumb 컴포넌트 재사용 */}
      {breadcrumbItems && breadcrumbItems.length > 0 && (
        <Div className="mb-md">
          <Breadcrumb items={breadcrumbItems} />
        </Div>
      )}

      {/* 제목 및 액션 버튼 - admin_dashboard.json 스타일 */}
      <Div className="flex-between mb-lg">
        <Div>
          <H1 className="page-title">{title}</H1>
          {description && (
            <P className="page-description">{description}</P>
          )}
        </Div>

        {/* 액션 버튼 또는 children */}
        {children ? (
          <Div className="page-actions">{children}</Div>
        ) : actions && actions.length > 0 ? (
          <Div className="page-actions">
            {actions.map((action) => (
              <Button
                key={action.id}
                onClick={action.onClick}
                disabled={action.disabled}
                className={getButtonClasses(action.variant)}
              >
                {action.iconName && (
                  <Icon name={action.iconName} className="icon-md" />
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
        <Div className="tab-container">
          {tabs.map((tab) => (
            <Button
              key={tab.id}
              onClick={() => onTabChange?.(tab.value)}
              className={tab.active ? 'tab-btn-active' : 'tab-btn'}
            >
              <Div>{tab.label}</Div>
              {tab.badge !== undefined && (
                <Div className={tab.active ? 'tab-badge-active' : 'tab-badge'}>
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
