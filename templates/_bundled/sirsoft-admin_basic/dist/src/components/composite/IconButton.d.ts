import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
import { EditorAttrs } from '../../types';
export interface IconButtonProps {
    iconName: IconName;
    label?: string;
    /** 글자 없는 버튼의 접근 가능한 이름(+툴팁). 비우면 아이콘별 기본 이름(새로고침·추가·닫기 등) */
    ariaLabel?: string;
    onClick?: () => void;
    variant?: 'primary' | 'secondary' | 'danger' | 'ghost' | 'outline';
    size?: 'sm' | 'md' | 'lg';
    disabled?: boolean;
    className?: string;
    style?: React.CSSProperties;
    /**
     * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
     */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
}
export declare const IconButton: React.FC<IconButtonProps>;
