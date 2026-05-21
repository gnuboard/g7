import { default as React } from 'react';
export interface InquiryMessage {
    id: number;
    sender_role: 'client' | 'operator' | 'system';
    body: string | null;
    meta?: {
        key?: string;
        params?: Record<string, unknown>;
    } | null;
    created_at?: string;
}
export interface InquiryMessageThreadProps {
    messages: InquiryMessage[];
    /** 현재 사용자 역할 (대개 'client') */
    myRole?: 'client' | 'operator';
    /** 메시지 전송 콜백 — layout JSON 에서 onSend 액션으로 바인딩 */
    onSend?: (body: string) => void;
    /** 전송 중 비활성화 */
    submitting?: boolean;
    /** placeholder 텍스트 */
    placeholder?: string;
    className?: string;
}
declare const InquiryMessageThread: React.FC<InquiryMessageThreadProps>;
export default InquiryMessageThread;
