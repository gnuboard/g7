import { default as React } from 'react';
export interface InquiryCardProps {
    uuid: string;
    title: string;
    status: 'received' | 'quoted' | 'in_progress' | 'completed' | 'canceled';
    category?: string;
    receivedAt?: string;
    unreadCount?: number;
    className?: string;
}
declare const InquiryCard: React.FC<InquiryCardProps>;
export default InquiryCard;
