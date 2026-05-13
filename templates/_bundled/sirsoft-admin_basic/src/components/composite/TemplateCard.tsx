import React from 'react';
import { Div } from '../basic/Div';
import { Img } from '../basic/Img';
import { H3 } from '../basic/H3';
import { P } from '../basic/P';
import { Span } from '../basic/Span';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { StatusBadge, StatusType } from './StatusBadge';
import { ActionMenu, ActionMenuItem } from './ActionMenu';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

/**
 * 템플릿 상태
 */
export type TemplateStatus = 'active' | 'inactive' | 'pending' | 'error';

/**
 * TemplateCard Props
 */
export interface TemplateCardProps {
  image?: string;
  imageAlt?: string;
  vendor: string;
  name: string;
  version: string;
  status: TemplateStatus;
  updateAvailable?: boolean;
  latestVersion?: string;
  dependencies?: string[];
  showLayoutEditButton?: boolean;
  actions?: ActionMenuItem[];
  onLayoutEditClick?: () => void;
  className?: string;
}

/**
 * TemplateCard 컴포넌트
 *
 * 템플릿 카드 - 상태 뱃지, 미리보기 이미지, 퀵 액션 버튼(ActionMenu) 포함
 *
 * @example
 * ```tsx
 * <TemplateCard
 *   image="/preview.png"
 *   vendor="sirsoft"
 *   name="admin_basic"
 *   version="1.0.0"
 *   status="active"
 *   updateAvailable={true}
 *   latestVersion="1.1.0"
 *   showLayoutEditButton={true}
 *   onLayoutEditClick={() => console.log('Edit layouts')}
 *   actions={[
 *     { id: 'info', label: '정보 보기', iconName: IconName.InfoCircle, onClick: () => {} },
 *     { id: 'update', label: '업데이트', iconName: IconName.Download, onClick: () => {} },
 *     { id: 'remove', label: '제거', iconName: IconName.Trash, variant: 'danger', onClick: () => {} }
 *   ]}
 * />
 * ```
 */
export const TemplateCard: React.FC<TemplateCardProps> = ({
  image,
  imageAlt,
  vendor,
  name,
  version,
  status,
  updateAvailable = false,
  latestVersion,
  dependencies = [],
  showLayoutEditButton = false,
  actions = [],
  onLayoutEditClick,
  className = '',
}) => {
  /**
   * 상태를 StatusType으로 매핑
   */
  const getStatusType = (status: TemplateStatus): StatusType => {
    const statusMap: Record<TemplateStatus, StatusType> = {
      active: 'success',
      inactive: 'default',
      pending: 'pending',
      error: 'error',
    };
    return statusMap[status];
  };

  /**
   * 상태 라벨 (다국어 지원)
   */
  const getStatusLabel = (status: TemplateStatus): string => {
    const labelMap: Record<TemplateStatus, string> = {
      active: t('common.status_active'),
      inactive: t('common.status_inactive'),
      pending: t('common.status_pending'),
      error: t('common.status_error'),
    };
    return labelMap[status];
  };

  return (
    <Div
      className={`
        bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden hover:shadow-lg transition-shadow
        ${className}
      `}
    >
      {/* 미리보기 이미지 */}
      {image && (
        <Div className="relative">
          <Img
            src={image}
            alt={imageAlt || t('common.template_preview', { vendor, name })}
            className="w-full h-48 object-cover"
          />
          {/* 상태 뱃지 (이미지 위) */}
          <Div className="absolute top-3 right-3">
            <StatusBadge
              status={getStatusType(status)}
              label={getStatusLabel(status)}
              showIcon={true}
            />
          </Div>
          {/* 업데이트 가능 뱃지 */}
          {updateAvailable && (
            <Div className="absolute top-3 left-3">
              <Span className="flex items-center gap-1 px-2 py-1 bg-yellow-50 dark:bg-yellow-900/20 text-yellow-700 dark:text-yellow-400 border border-yellow-200 dark:border-yellow-800 rounded-md text-xs font-semibold">
                <Icon name={IconName.ExclamationTriangle} className="w-3 h-3" />
                <Span>{t('common.update_available')}</Span>
              </Span>
            </Div>
          )}
        </Div>
      )}

      {/* 카드 본문 */}
      <Div className="p-4">
        {/* 헤더: 템플릿 정보 + 액션 메뉴 */}
        <Div className="flex items-start justify-between mb-3">
          <Div className="flex-1">
            <H3 className="text-lg font-bold text-gray-900 dark:text-white">
              {vendor}/{name}
            </H3>
            <Div className="flex items-center gap-2 mt-1 text-sm text-gray-600 dark:text-gray-400">
              <Span>v{version}</Span>
              {updateAvailable && latestVersion && (
                <>
                  <Span>→</Span>
                  <Span className="text-yellow-600 dark:text-yellow-400 font-semibold">v{latestVersion}</Span>
                </>
              )}
            </Div>
            {/* 이미지가 없을 때 상태 뱃지 표시 */}
            {!image && (
              <Div className="mt-2">
                <StatusBadge
                  status={getStatusType(status)}
                  label={getStatusLabel(status)}
                  showIcon={true}
                />
              </Div>
            )}
          </Div>

          {/* 액션 메뉴 (ActionMenu 컴포넌트 재사용) */}
          {actions.length > 0 && (
            <ActionMenu
              items={actions}
              triggerIconName={IconName.EllipsisVertical}
              position="left"
            />
          )}
        </Div>

        {/* 의존성 정보 */}
        {dependencies.length > 0 && (
          <Div className="mb-3">
            <P className="text-xs text-gray-500 dark:text-gray-400 mb-1">{t('common.dependencies')}:</P>
            <Div className="flex flex-wrap gap-1">
              {dependencies.map((dep, index) => (
                <Span
                  key={index}
                  className="px-2 py-0.5 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-xs rounded"
                >
                  {dep}
                </Span>
              ))}
            </Div>
          </Div>
        )}

        {/* 레이아웃 편집 버튼 */}
        {showLayoutEditButton && onLayoutEditClick && (
          <Button
            onClick={onLayoutEditClick}
            className="w-full px-4 py-2 bg-blue-600 dark:bg-blue-500 text-white rounded-md hover:bg-blue-700 dark:hover:bg-blue-600 transition-colors flex items-center justify-center gap-2 font-medium"
          >
            <Icon name={IconName.Edit} className="w-4 h-4" />
            <Span>{t('common.edit_layout')}</Span>
          </Button>
        )}
      </Div>
    </Div>
  );
};
