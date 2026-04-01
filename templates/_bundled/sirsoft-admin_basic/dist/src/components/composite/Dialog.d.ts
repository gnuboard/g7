import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
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
export declare const Dialog: React.FC<DialogProps>;
