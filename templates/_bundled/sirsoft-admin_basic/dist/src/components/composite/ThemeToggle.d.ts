import { default as React } from 'react';
/**
 * 테마 모드 타입
 */
export type ThemeMode = 'auto' | 'light' | 'dark';
/**
 * ThemeToggle Props
 */
export interface ThemeToggleProps {
    /** 테마 변경 콜백 */
    onThemeChange?: (theme: ThemeMode) => void;
    /** 추가 CSS 클래스 */
    className?: string;
    /** 자동 모드 텍스트 (다국어 키 사용 권장) */
    autoText?: string;
    /** 라이트 모드 텍스트 (다국어 키 사용 권장) */
    lightText?: string;
    /** 다크 모드 텍스트 (다국어 키 사용 권장) */
    darkText?: string;
}
export declare const ThemeToggle: React.FC<ThemeToggleProps>;
