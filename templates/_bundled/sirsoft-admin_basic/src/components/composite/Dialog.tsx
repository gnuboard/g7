import React from 'react';
import { Modal } from './Modal';
import { Div } from '../basic/Div';
import { H2 } from '../basic/H2';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

export interface DialogAction {
  label: string;
  onClick: () => void;
  variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
  iconName?: IconName;
  disabled?: boolean;
}

export interface DialogProps {
  isOpen: boolean;
  onClose: () => void;
  title: string;
  content: React.ReactNode;
  actions?: DialogAction[];
  width?: string;
  closeOnOverlay?: boolean;
  showCloseButton?: boolean;
  className?: string;
  style?: React.CSSProperties;
}

/**
 * Dialog 집합 컴포넌트
 *
 * Modal의 특화 버전으로 제목 + 본문 + 액션 영역 구조를 표준화한 컴포넌트.
 * Modal보다 더 구조화된 레이아웃 제공.
 *
 * 기본 컴포넌트 조합:
 * - Modal > Div(header) > H2(title) + Button(close)
 * - Div(body) > {content}
 * - Div(footer) > Button[](actions)
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "Dialog",
 *   "props": {
 *     "isOpen": "{{dialogs.confirm}}",
 *     "title": "확인",
 *     "content": "정말 삭제하시겠습니까?",
 *     "actions": [
 *       {"label": "취소", "variant": "secondary"},
 *       {"label": "삭제", "variant": "danger"}
 *     ]
 *   }
 * }
 */
export const Dialog: React.FC<DialogProps> = ({
  isOpen,
  onClose,
  title,
  content,
  actions = [],
  width = '500px',
  closeOnOverlay = true,
  showCloseButton = true,
  className = '',
  style,
}) => {
  const handleOverlayClick = () => {
    if (closeOnOverlay) {
      onClose();
    }
  };

  // 버튼 variant별 스타일 매핑
  const variantClassMap: Record<'primary' | 'secondary' | 'danger' | 'ghost', string> = {
    primary: 'bg-blue-600 text-white hover:bg-blue-700 focus:ring-blue-500',
    secondary: 'bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 hover:bg-gray-300 dark:hover:bg-gray-600 focus:ring-gray-500',
    danger: 'bg-red-600 text-white hover:bg-red-700 focus:ring-red-500',
    ghost: 'bg-transparent text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 focus:ring-gray-500',
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={handleOverlayClick}
      width={width}
      className={className}
      style={style}
    >
      {/* Header */}
      <Div className="flex items-center justify-between mb-4">
        <H2 className="text-xl font-semibold text-gray-900 dark:text-white">
          {title}
        </H2>
        {showCloseButton && (
          <Button
            onClick={onClose}
            className="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none"
            aria-label={t('common.close_dialog')}
          >
            <Icon name={IconName.Times} className="w-5 h-5" />
          </Button>
        )}
      </Div>

      {/* Body */}
      <Div className="mb-6 text-gray-700 dark:text-gray-300">
        {content}
      </Div>

      {/* Footer (Actions) */}
      {actions.length > 0 && (
        <Div className="flex justify-end gap-3">
          {actions.map((action, index) => (
            <Button
              key={index}
              onClick={action.onClick}
              disabled={action.disabled}
              className={`
                px-4 py-2 rounded-md font-medium transition-colors
                focus:outline-none focus:ring-2 focus:ring-offset-2
                disabled:opacity-50 disabled:cursor-not-allowed
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
    </Modal>
  );
};
