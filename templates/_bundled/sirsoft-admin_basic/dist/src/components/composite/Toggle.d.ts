import { default as React } from 'react';
import { EditorAttrs } from '../../types';
export interface ToggleProps {
    /** 체크 상태 */
    checked?: boolean;
    /** 체크 상태 변경 콜백 (boolean 또는 ChangeEvent 받음) */
    onChange?: ((checked: boolean) => void) | ((e: React.ChangeEvent<HTMLInputElement>) => void);
    /** 비활성화 여부 */
    disabled?: boolean;
    /** 라벨 텍스트 */
    label?: string;
    /** 설명 텍스트 */
    description?: string;
    /** 토글 크기 */
    size?: 'sm' | 'md' | 'lg';
    /** 추가 className */
    className?: string;
    /** name 속성 */
    name?: string;
    /** 값 (checked의 대체) */
    value?: boolean;
    /**
     * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
     */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
}
/**
 * Toggle 스위치 컴포넌트
 *
 * Flowbite 스타일의 토글 스위치입니다.
 * 라이트/다크 모드를 모두 지원합니다.
 * 반응형으로 prop 변경을 추적합니다.
 */
export declare const Toggle: React.FC<ToggleProps>;
