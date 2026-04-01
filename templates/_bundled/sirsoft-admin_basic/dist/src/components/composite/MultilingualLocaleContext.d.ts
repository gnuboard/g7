/**
 * MultilingualLocaleContext
 *
 * MultilingualTabPanel 내부에서 현재 활성 로케일을 전파하는 Context입니다.
 * Stale Closure 방지를 위해 getter 함수를 제공합니다.
 */
/**
 * Context 값 인터페이스
 */
export interface MultilingualLocaleContextValue {
    /** 현재 활성 로케일 (렌더링용) */
    activeLocale: string;
    /** Stale Closure 방지용 getter - 이벤트 핸들러에서 사용 */
    getActiveLocale: () => string;
    /** 지원되는 로케일 목록 */
    supportedLocales: string[];
}
/**
 * useCurrentMultilingualLocale 훅 반환 타입
 */
export interface UseCurrentMultilingualLocaleResult extends MultilingualLocaleContextValue {
    /** MultilingualTabPanel 내부에서 사용 중인지 여부 */
    isInsideMultilingualTabPanel: boolean;
}
/**
 * MultilingualLocaleContext
 *
 * MultilingualTabPanel에서 Provider로 값을 제공합니다.
 */
export declare const MultilingualLocaleContext: import('react').Context<MultilingualLocaleContextValue | null>;
/**
 * MultilingualTabPanel 내부에서 현재 활성 로케일 접근
 *
 * @returns activeLocale, getActiveLocale (Stale Closure 방지), supportedLocales
 * @fallback Context 외부에서는 G7Core.locale.current() 사용
 *
 * @example
 * ```tsx
 * const { activeLocale, getActiveLocale } = useCurrentMultilingualLocale();
 *
 * // 렌더링에서는 activeLocale 직접 사용
 * const displayValue = value?.[activeLocale] || '';
 *
 * // 이벤트 핸들러에서는 getActiveLocale() 사용 (Stale Closure 방지)
 * const handleChange = (newValue: string) => {
 *   const currentLocale = getActiveLocale();
 *   onChange({ ...value, [currentLocale]: newValue });
 * };
 * ```
 */
export declare const useCurrentMultilingualLocale: () => UseCurrentMultilingualLocaleResult;
export default MultilingualLocaleContext;
