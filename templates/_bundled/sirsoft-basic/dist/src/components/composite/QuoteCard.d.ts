import { default as React } from 'react';
export interface QuoteItem {
    id: number;
    name: string;
    description?: string;
    qty: number | string;
    unit_price: number | string;
    amount: number | string;
}
export interface QuoteCardProps {
    version: number;
    status: 'draft' | 'issued' | 'accepted' | 'rejected' | 'expired';
    totalAmount: string | number;
    taxAmount?: string | number;
    currency?: string;
    validUntil?: string;
    note?: string;
    items: QuoteItem[];
    canAccept?: boolean;
    canReject?: boolean;
    onAccept?: () => void;
    onReject?: () => void;
    submitting?: boolean;
    className?: string;
}
declare const QuoteCard: React.FC<QuoteCardProps>;
export default QuoteCard;
