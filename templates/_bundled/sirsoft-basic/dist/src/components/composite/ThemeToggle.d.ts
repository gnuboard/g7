import { default as React } from 'react';
import { EditorAttrs } from '../../types';
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
    /** DOM id 속성 (레이아웃 편집기 코어 일괄 ID) */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
}
export declare const ThemeToggle: React.FC<ThemeToggleProps>;
export default ThemeToggle;
