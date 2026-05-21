import { default as React } from 'react';
export interface InquiryStatusBarProps {
    /** 현재 의뢰 상태 */
    status: 'received' | 'quoted' | 'in_progress' | 'completed' | 'canceled';
    className?: string;
}
declare const InquiryStatusBar: React.FC<InquiryStatusBarProps>;
export default InquiryStatusBar;
