import React, { useState } from 'react';
import { Button, Div, P, Span, Textarea } from '../basic';

export interface InquiryMessage {
  id: number;
  sender_role: 'client' | 'operator' | 'system';
  body: string | null;
  meta?: { key?: string; params?: Record<string, unknown> } | null;
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

const renderSystemBody = (meta?: InquiryMessage['meta']): string => {
  if (!meta?.key) return '시스템 메시지';
  // 간단한 키 표시 — i18n 보간은 향후 강화
  const keySuffix = meta.key.split('.').pop() || '';
  const params = meta.params || {};
  switch (keySuffix) {
    case 'quote_issued':
      return `운영자가 견적을 발행했습니다 (회차 #${params.version ?? '?'}, 합계 ${params.total ?? '-'}원)`;
    case 'quote_revoked':
      return `운영자가 견적을 철회했습니다 (회차 #${params.version ?? '?'})`;
    case 'quote_rejected':
      return `의뢰자가 견적을 거절했습니다 (회차 #${params.version ?? '?'})`;
    case 'payment_confirmed':
      return '결제가 확인되었습니다';
    case 'payment_confirmed_offline':
      return '운영자가 결제를 수동 확인했습니다';
    case 'completed':
      return '의뢰가 완료되었습니다';
    case 'canceled_by_client':
      return '의뢰자가 의뢰를 취소했습니다';
    case 'canceled_by_operator':
      return '운영자가 의뢰를 취소했습니다';
    default:
      return meta.key;
  }
};

const InquiryMessageThread: React.FC<InquiryMessageThreadProps> = ({
  messages,
  myRole = 'client',
  onSend,
  submitting = false,
  placeholder = '메시지를 입력하세요',
  className = '',
}) => {
  const [draft, setDraft] = useState('');

  const handleSend = () => {
    const trimmed = draft.trim();
    if (!trimmed) return;
    onSend?.(trimmed);
    setDraft('');
  };

  return (
    <Div className={`inquiry-message-thread flex flex-col bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg ${className}`}>
      <Div className="flex-1 overflow-y-auto p-4 space-y-3">
        {messages.map((msg) => {
          if (msg.sender_role === 'system') {
            return (
              <Div key={msg.id} className="flex justify-center">
                <Span className="px-3 py-1 rounded-full bg-gray-100 dark:bg-gray-700 text-xs text-gray-600 dark:text-gray-400">
                  {renderSystemBody(msg.meta)}
                </Span>
              </Div>
            );
          }
          const mine = msg.sender_role === myRole;
          return (
            <Div
              key={msg.id}
              className={`flex ${mine ? 'justify-end' : 'justify-start'}`}
            >
              <Div
                className={`max-w-[80%] px-3 py-2 rounded-lg ${
                  mine
                    ? 'bg-blue-600 text-white dark:bg-blue-500'
                    : 'bg-gray-100 text-gray-900 dark:bg-gray-700 dark:text-gray-100'
                }`}
              >
                {msg.body && <P className="whitespace-pre-wrap break-words text-sm">{msg.body}</P>}
              </Div>
            </Div>
          );
        })}
        {messages.length === 0 && (
          <Div className="text-center text-sm text-gray-400 dark:text-gray-500 py-8">
            아직 메시지가 없습니다
          </Div>
        )}
      </Div>

      <Div className="border-t border-gray-200 dark:border-gray-700 p-3 flex items-end gap-2">
        <Textarea
          value={draft}
          onChange={(e: any) => setDraft(e.target.value)}
          placeholder={placeholder}
          rows={2}
          className="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 resize-none"
          disabled={submitting}
        />
        <Button
          type="button"
          onClick={handleSend}
          disabled={submitting || !draft.trim()}
          className="px-4 py-2 bg-blue-600 text-white rounded-md font-medium hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {submitting ? '전송 중' : '전송'}
        </Button>
      </Div>
    </Div>
  );
};

export default InquiryMessageThread;
