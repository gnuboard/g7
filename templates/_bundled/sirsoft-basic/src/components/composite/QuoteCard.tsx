import React from 'react';
import { Button, Div, H3, P, Span, Table, Tbody, Td, Th, Thead, Tr } from '../basic';

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

const STATUS_LABEL: Record<string, string> = {
  draft: '초안',
  issued: '발행됨',
  accepted: '수락',
  rejected: '거절',
  expired: '만료',
};

const STATUS_STYLES: Record<string, string> = {
  draft: 'bg-gray-100 text-gray-700',
  issued: 'bg-yellow-100 text-yellow-700',
  accepted: 'bg-green-100 text-green-700',
  rejected: 'bg-red-100 text-red-700',
  expired: 'bg-gray-200 text-gray-500',
};

const formatKRW = (v: string | number | undefined): string => {
  if (v === undefined || v === null) return '-';
  const num = typeof v === 'string' ? parseInt(v, 10) : v;
  return Number.isFinite(num) ? num.toLocaleString('ko-KR') + '원' : '-';
};

const QuoteCard: React.FC<QuoteCardProps> = ({
  version,
  status,
  totalAmount,
  taxAmount,
  currency: _currency = 'KRW',
  validUntil,
  note,
  items,
  canAccept = false,
  canReject = false,
  onAccept,
  onReject,
  submitting = false,
  className = '',
}) => (
  <Div className={`bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-5 space-y-4 ${className}`}>
    <Div className="flex items-center justify-between">
      <H3 className="text-base font-semibold text-gray-900 dark:text-white">견적 #{version}</H3>
      <Span className={`px-2 py-0.5 text-xs rounded-full ${STATUS_STYLES[status] || ''} dark:bg-opacity-30`}>
        {STATUS_LABEL[status] || status}
      </Span>
    </Div>

    {items.length > 0 && (
      <Table className="w-full text-sm">
        <Thead>
          <Tr className="border-b border-gray-200 dark:border-gray-700">
            <Th className="text-left py-1.5 text-xs text-gray-500 dark:text-gray-400 font-normal">항목</Th>
            <Th className="text-right py-1.5 text-xs text-gray-500 dark:text-gray-400 font-normal">수량</Th>
            <Th className="text-right py-1.5 text-xs text-gray-500 dark:text-gray-400 font-normal">단가</Th>
            <Th className="text-right py-1.5 text-xs text-gray-500 dark:text-gray-400 font-normal">금액</Th>
          </Tr>
        </Thead>
        <Tbody>
          {items.map((it) => (
            <Tr key={it.id} className="border-b border-gray-100 dark:border-gray-700/50 last:border-b-0">
              <Td className="py-1.5 text-gray-700 dark:text-gray-300">{it.name}</Td>
              <Td className="py-1.5 text-right text-gray-700 dark:text-gray-300">{it.qty}</Td>
              <Td className="py-1.5 text-right text-gray-700 dark:text-gray-300">{formatKRW(it.unit_price)}</Td>
              <Td className="py-1.5 text-right text-gray-700 dark:text-gray-300 font-medium">{formatKRW(it.amount)}</Td>
            </Tr>
          ))}
        </Tbody>
      </Table>
    )}

    <Div className="border-t border-gray-200 dark:border-gray-700 pt-3 space-y-1">
      <Div className="flex justify-between text-sm">
        <Span className="text-gray-500 dark:text-gray-400">세금</Span>
        <Span className="text-gray-700 dark:text-gray-300">{formatKRW(taxAmount ?? 0)}</Span>
      </Div>
      <Div className="flex justify-between text-base font-semibold">
        <Span className="text-gray-900 dark:text-white">합계</Span>
        <Span className="text-gray-900 dark:text-white">{formatKRW(totalAmount)}</Span>
      </Div>
    </Div>

    {validUntil && (
      <P className="text-xs text-gray-500 dark:text-gray-400">유효기간: {validUntil}</P>
    )}
    {note && (
      <P className="text-xs text-gray-600 dark:text-gray-300 whitespace-pre-wrap">{note}</P>
    )}

    {(canAccept || canReject) && status === 'issued' && (
      <Div className="flex gap-2 pt-2">
        {canReject && (
          <Button
            type="button"
            onClick={onReject}
            disabled={submitting}
            className="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50"
          >
            거절
          </Button>
        )}
        {canAccept && (
          <Button
            type="button"
            onClick={onAccept}
            disabled={submitting}
            className="flex-1 px-3 py-2 bg-blue-600 text-white rounded-md font-medium hover:bg-blue-700 disabled:opacity-50"
          >
            {submitting ? '진행 중…' : '수락 및 결제'}
          </Button>
        )}
      </Div>
    )}
  </Div>
);

export default QuoteCard;
