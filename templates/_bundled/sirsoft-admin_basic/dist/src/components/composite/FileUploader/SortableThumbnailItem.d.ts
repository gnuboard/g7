import { default as React } from 'react';
import { Attachment, PendingFile } from './types';
export interface SortableThumbnailItemProps {
    file: PendingFile | (Attachment & {
        status?: string;
    });
    onRemove: () => void;
    onRetry?: () => void;
    /** 이미지 클릭 시 갤러리 열기 */
    onImageClick?: () => void;
    /** 비이미지 파일 클릭/다운로드 시 호출 */
    onDownload?: () => void;
    /** 대표 이미지 여부 */
    isPrimary?: boolean;
    /** 대표 이미지 설정 클릭 핸들러 */
    onPrimaryClick?: () => void;
    /** 비활성화 여부 */
    disabled?: boolean;
}
export declare const SortableThumbnailItem: React.FC<SortableThumbnailItemProps>;
export default SortableThumbnailItem;
