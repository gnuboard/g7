import React from 'react';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

export type SpinnerSize = 'sm' | 'md' | 'lg' | 'xl';

export interface LoadingSpinnerProps {
  id?: string;
  size?: SpinnerSize;
  color?: string;
  fullscreen?: boolean;
  text?: string;
  className?: string;
}

/**
 * LoadingSpinner 집합 컴포넌트
 *
 * 로딩 상태를 시각적으로 표시하는 스피너 컴포넌트.
 * size, color, fullscreen 모드, text 라벨 옵션 제공.
 *
 * 기본 컴포넌트 조합:
 * - Div(컨테이너) > Div(스피너) + Span(텍스트, 옵션)
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "LoadingSpinner",
 *   "props": {
 *     "size": "lg",
 *     "text": "데이터를 불러오는 중...",
 *     "fullscreen": false
 *   }
 * }
 */
export const LoadingSpinner: React.FC<LoadingSpinnerProps> = ({
  id,
  size = 'md',
  color = 'text-blue-600',
  fullscreen = false,
  text,
  className = '',
}) => {
  // size별 스피너 크기 매핑
  const sizeClassMap: Record<SpinnerSize, string> = {
    sm: 'w-4 h-4 border-2',
    md: 'w-8 h-8 border-2',
    lg: 'w-12 h-12 border-3',
    xl: 'w-16 h-16 border-4',
  };

  // size별 텍스트 크기 매핑
  const textSizeClassMap: Record<SpinnerSize, string> = {
    sm: 'text-xs',
    md: 'text-sm',
    lg: 'text-base',
    xl: 'text-lg',
  };

  const spinnerContent = (
    <Div className={`flex flex-col items-center justify-center gap-3 ${className}`}>
      {/* Spinner */}
      <Div
        className={`
          ${sizeClassMap[size]}
          border-gray-200 dark:border-gray-700 border-t-current
          rounded-full animate-spin
          ${color}
        `}
        role="status"
        aria-label={t('common.loading')}
      />

      {/* Text Label */}
      {text && (
        <Span className={`${textSizeClassMap[size]} ${color} font-medium`}>
          {text}
        </Span>
      )}
    </Div>
  );

  if (fullscreen) {
    return (
      <Div
        id={id}
        className="fixed inset-0 z-50 flex items-center justify-center bg-white dark:bg-gray-900 bg-opacity-90 dark:bg-opacity-90"
        aria-live="polite"
        aria-busy="true"
      >
        {spinnerContent}
      </Div>
    );
  }

  return (
    <Div
      id={id}
      className="flex items-center justify-center p-4"
      aria-live="polite"
      aria-busy="true"
    >
      {spinnerContent}
    </Div>
  );
};
