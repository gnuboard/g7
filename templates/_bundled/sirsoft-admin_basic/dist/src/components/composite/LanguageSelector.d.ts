import { default as React } from 'react';
/**
 * LanguageSelector Props
 */
export interface LanguageSelectorProps {
    /** 사용 가능한 언어 목록 (template.json의 locales에서 자동 바인딩) */
    availableLocales?: string[];
    /** 언어 설정 텍스트 */
    languageText?: string;
    /** 언어 변경 API 엔드포인트 */
    apiEndpoint?: string;
    /** 언어 변경 후 콜백 */
    onLanguageChange?: (locale: string) => void;
    /** 추가 CSS 클래스 */
    className?: string;
    /** 인라인 모드 (드롭다운 메뉴 내에서 사용) */
    inline?: boolean;
}
/**
 * LanguageSelector 컴포넌트
 *
 * 언어 전환 드롭다운 제공
 * - DB에 언어 설정 저장
 * - localStorage에 로케일 저장
 * - 새로고침 없이 UI 리렌더링
 *
 * @example
 * ```tsx
 * // 독립적으로 사용
 * <LanguageSelector
 *   availableLocales={['ko', 'en']}
 *   languageText="언어"
 * />
 *
 * // 인라인 모드 (다른 드롭다운 내에서 사용)
 * <LanguageSelector
 *   availableLocales={['ko', 'en']}
 *   languageText="언어"
 *   inline
 * />
 * ```
 */
export declare const LanguageSelector: React.FC<LanguageSelectorProps>;
