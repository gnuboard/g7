import { default as React } from 'react';
import { ActionMenuItem } from './ActionMenu';
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
export declare const TemplateCard: React.FC<TemplateCardProps>;
