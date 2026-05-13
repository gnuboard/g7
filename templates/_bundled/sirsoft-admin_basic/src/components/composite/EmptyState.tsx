import React from 'react';
import { Div } from '../basic/Div';
import { H2 } from '../basic/H2';
import { P } from '../basic/P';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { Img } from '../basic/Img';
import { IconName } from '../basic/IconTypes';

export interface EmptyStateAction {
  label: string;
  onClick: () => void;
  variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
  iconName?: IconName;
}

export interface EmptyStateProps {
  title: string;
  description?: string;
  iconName?: IconName;
  illustrationSrc?: string;
  illustrationAlt?: string;
  actions?: EmptyStateAction[];
  className?: string;
}

/**
 * EmptyState 집합 컴포넌트
 *
 * 데이터가 없거나 검색 결과가 없을 때 표시하는 빈 상태 컴포넌트.
 * icon/illustration, title, description, actions 버튼 배열 지원.
 *
 * 기본 컴포넌트 조합:
 * - Div(컨테이너) > Icon/Img + H2(제목) + P(설명) + Div(액션) > Button[]
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "EmptyState",
 *   "props": {
 *     "title": "검색 결과가 없습니다",
 *     "description": "다른 검색어로 시도해보세요.",
 *     "iconName": "Search",
 *     "actions": [
 *       {"label": "검색 초기화", "variant": "secondary"}
 *     ]
 *   }
 * }
 */
export const EmptyState: React.FC<EmptyStateProps> = ({
  title,
  description,
  iconName,
  illustrationSrc,
  illustrationAlt = 'Empty state illustration',
  actions = [],
  className = '',
}) => {
  // 버튼 variant별 스타일 매핑
  const variantClassMap: Record<'primary' | 'secondary' | 'danger' | 'ghost', string> = {
    primary: 'bg-blue-600 text-white hover:bg-blue-700 focus:ring-blue-500',
    secondary: 'bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 hover:bg-gray-300 dark:hover:bg-gray-600 focus:ring-gray-500',
    danger: 'bg-red-600 text-white hover:bg-red-700 focus:ring-red-500',
    ghost: 'bg-transparent text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 focus:ring-gray-500',
  };

  return (
    <Div
      className={`flex flex-col items-center justify-center p-8 text-center ${className}`}
      role="status"
      aria-live="polite"
    >
      {/* Icon 또는 Illustration */}
      {illustrationSrc ? (
        <Img
          src={illustrationSrc}
          alt={illustrationAlt}
          className="w-48 h-48 mb-6 object-contain"
        />
      ) : iconName ? (
        <Div className="mb-6 p-4 rounded-full bg-gray-100 dark:bg-gray-700">
          <Icon
            name={iconName}
            className="w-12 h-12 text-gray-400 dark:text-gray-500"
          />
        </Div>
      ) : null}

      {/* Title */}
      <H2 className="text-xl font-semibold text-gray-900 dark:text-white mb-2">
        {title}
      </H2>

      {/* Description */}
      {description && (
        <P className="text-gray-600 dark:text-gray-400 mb-6 max-w-md">
          {description}
        </P>
      )}

      {/* Actions */}
      {actions.length > 0 && (
        <Div className="flex flex-wrap gap-3 justify-center">
          {actions.map((action, index) => (
            <Button
              key={index}
              onClick={action.onClick}
              className={`
                px-4 py-2 rounded-md font-medium transition-colors
                focus:outline-none focus:ring-2 focus:ring-offset-2
                ${variantClassMap[action.variant || 'primary']}
              `}
            >
              {action.iconName && (
                <Icon name={action.iconName} className="w-4 h-4 mr-2 inline-block" />
              )}
              {action.label}
            </Button>
          ))}
        </Div>
      )}
    </Div>
  );
};
