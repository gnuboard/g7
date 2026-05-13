import { default as React } from 'react';
export interface MultilingualValue {
    [locale: string]: string;
}
export interface LocaleOption {
    code: string;
    name: string;
    nativeName?: string;
}
export type MultilingualInputType = 'text' | 'textarea';
export type MultilingualInputLayout = 'inline' | 'tabs' | 'compact';
export interface MultilingualInputProps {
    /** 다국어 값 객체 */
    value?: MultilingualValue;
    /** 값 변경 콜백 */
    onChange?: (event: any) => void;
    /** 입력 타입 (text | textarea) */
    inputType?: MultilingualInputType;
    /** 레이아웃 타입 (inline: 모든 언어 수직 표시 | tabs: 탭 전환 방식 | compact: 탭과 입력이 한 줄) */
    layout?: MultilingualInputLayout;
    /** 사용 가능한 언어 목록 */
    availableLocales?: LocaleOption[];
    /** 기본 언어 코드 */
    defaultLocale?: string;
    /** 입력창 placeholder */
    placeholder?: string;
    /** 필수 입력 여부 */
    required?: boolean;
    /** 비활성화 여부 */
    disabled?: boolean;
    /** 최대 길이 */
    maxLength?: number;
    /** textarea 행 수 (inputType이 'textarea'일 때만 사용) */
    rows?: number;
    /** 추가 className */
    className?: string;
    /** input name */
    name?: string;
    /** 모바일에서 언어 코드로 표시 (기본값: false) */
    showCodeOnMobile?: boolean;
    /** 에러 메시지 (입력 필드에 적색 테두리 표시) */
    error?: string;
}
/**
 * 다국어 입력 컴포넌트
 *
 * 두 가지 레이아웃 모드를 지원합니다:
 * - inline (기본): 카드 형태로 모든 언어를 수직으로 쌓아서 표시
 * - tabs: 탭 방식으로 언어 전환 (카드 형태)
 */
export declare const MultilingualInput: React.FC<MultilingualInputProps>;
