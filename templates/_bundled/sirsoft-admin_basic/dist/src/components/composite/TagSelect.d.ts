import { default as React } from 'react';
export interface TagSelectOption {
    value: string | number;
    label: string;
}
export interface TagSelectProps {
    /** 선택 가능한 옵션 목록 (라벨 매핑용) */
    options?: TagSelectOption[];
    /** 선택된 값 배열 */
    value?: (string | number)[];
    /** 값 변경 핸들러 */
    onChange?: (value: (string | number)[]) => void;
    /** placeholder (선택된 항목 없을 때) */
    placeholder?: string;
    /** 비활성화 */
    disabled?: boolean;
    /** 추가 클래스 */
    className?: string;
}
/**
 * 태그 선택 표시 컴포넌트
 *
 * 선택된 항목들을 태그(뱃지) 형태로 표시합니다.
 * 각 태그의 X 버튼으로 개별 삭제가 가능합니다.
 */
export declare const TagSelect: React.FC<TagSelectProps>;
