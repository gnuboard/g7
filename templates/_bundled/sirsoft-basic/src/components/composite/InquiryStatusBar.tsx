import React from 'react';
import { Div, Span } from '../basic';

export interface InquiryStatusBarProps {
  /** 현재 의뢰 상태 */
  status: 'received' | 'quoted' | 'in_progress' | 'completed' | 'canceled';
  className?: string;
}

const STEPS: Array<{ key: string; label: string }> = [
  { key: 'received', label: '접수' },
  { key: 'quoted', label: '견적' },
  { key: 'in_progress', label: '진행' },
  { key: 'completed', label: '완료' },
];

const stepIndex = (status: string): number => {
  if (status === 'canceled') return -1;
  return STEPS.findIndex((s) => s.key === status);
};

const InquiryStatusBar: React.FC<InquiryStatusBarProps> = ({ status, className = '' }) => {
  const current = stepIndex(status);
  const canceled = status === 'canceled';

  return (
    <Div className={`inquiry-status-bar w-full ${className}`}>
      {canceled ? (
        <Div className="px-4 py-2 rounded-md bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 font-medium">
          취소된 의뢰
        </Div>
      ) : (
        <Div className="flex items-center gap-2">
          {STEPS.map((step, i) => {
            const active = i <= current;
            const isCurrent = i === current;
            return (
              <React.Fragment key={step.key}>
                <Div
                  className={`flex items-center gap-2 px-3 py-1.5 rounded-full text-sm ${
                    active
                      ? 'bg-blue-600 text-white dark:bg-blue-500'
                      : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'
                  } ${isCurrent ? 'ring-2 ring-blue-300 dark:ring-blue-700' : ''}`}
                >
                  <Span className="font-semibold">{i + 1}</Span>
                  <Span>{step.label}</Span>
                </Div>
                {i < STEPS.length - 1 && (
                  <Div
                    className={`flex-1 h-0.5 ${
                      i < current ? 'bg-blue-600 dark:bg-blue-500' : 'bg-gray-200 dark:bg-gray-700'
                    }`}
                  />
                )}
              </React.Fragment>
            );
          })}
        </Div>
      )}
    </Div>
  );
};

export default InquiryStatusBar;
