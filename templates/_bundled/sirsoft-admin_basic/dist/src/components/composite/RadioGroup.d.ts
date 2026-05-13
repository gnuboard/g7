import { default as React } from 'react';
export interface RadioOption {
    /** 라디오 버튼의 값 */
    value: string;
    /** 라디오 버튼의 라벨 */
    label: string;
    /** 비활성화 여부 */
    disabled?: boolean;
}
export interface RadioGroupProps {
    /** 그룹 이름 (form 전송 시 사용) */
    name: string;
    /** 현재 선택된 값 */
    value?: string;
    /** 라디오 옵션 배열 */
    options: RadioOption[];
    /** 값 변경 콜백 */
    onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
    /** 비활성화 여부 */
    disabled?: boolean;
    /** 인라인 표시 여부 (기본값: false, 수직 배치) */
    inline?: boolean;
    /** 추가 className */
    className?: string;
    /** 라디오 버튼 크기 */
    size?: 'sm' | 'md' | 'lg';
    /** 그룹 라벨 */
    label?: string;
    /** 에러 메시지 */
    error?: string;
}
/**
 * RadioGroup 컴포넌트
 *
 * 여러 라디오 버튼을 그룹으로 묶어 관리합니다.
 * Flowbite 스타일을 따르며, 라이트/다크 모드를 모두 지원합니다.
 */
export declare const RadioGroup: React.FC<RadioGroupProps>;
