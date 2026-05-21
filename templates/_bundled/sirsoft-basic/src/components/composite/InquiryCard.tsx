import React from 'react';
import { A, Div, H3, Span } from '../basic';

export interface InquiryCardProps {
  uuid: string;
  title: string;
  status: 'received' | 'quoted' | 'in_progress' | 'completed' | 'canceled';
  category?: string;
  receivedAt?: string;
  unreadCount?: number;
  className?: string;
}

const STATUS_STYLES: Record<string, string> = {
  received: 'bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-300',
  quoted: 'bg-yellow-50 text-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-300',
  in_progress: 'bg-purple-50 text-purple-700 dark:bg-purple-900/20 dark:text-purple-300',
  completed: 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-300',
  canceled: 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300',
};

const STATUS_LABEL: Record<string, string> = {
  received: '접수',
  quoted: '견적',
  in_progress: '진행',
  completed: '완료',
  canceled: '취소',
};

const InquiryCard: React.FC<InquiryCardProps> = ({
  uuid,
  title,
  status,
  category,
  receivedAt,
  unreadCount = 0,
  className = '',
}) => (
  <A
    href={`/inquiry/${uuid}`}
    className={`block bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:shadow-md transition-shadow ${className}`}
  >
    <Div className="flex items-start justify-between gap-3 mb-2">
      <H3 className="text-base font-semibold text-gray-900 dark:text-white line-clamp-1">{title}</H3>
      <Span
        className={`shrink-0 px-2 py-0.5 rounded-full text-xs font-medium ${STATUS_STYLES[status] || ''}`}
      >
        {STATUS_LABEL[status] || status}
      </Span>
    </Div>
    <Div className="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
      {category && <Span>{category}</Span>}
      {receivedAt && <Span>{new Date(receivedAt).toLocaleDateString('ko-KR')}</Span>}
      {unreadCount > 0 && (
        <Span className="ml-auto inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300 font-medium">
          새 메시지 {unreadCount}
        </Span>
      )}
    </Div>
  </A>
);

export default InquiryCard;
