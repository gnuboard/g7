import { default as React } from 'react';
/**
 * MultilingualTabPanel Props
 */
export interface MultilingualTabPanelProps {
    /** 자식 요소 (DynamicFieldList 등) */
    children?: React.ReactNode;
    /** 추가 CSS 클래스 */
    className?: string;
    /** 인라인 스타일 */
    style?: React.CSSProperties;
    /** 탭 스타일 variant */
    variant?: 'default' | 'pills' | 'underline';
    /** 기본 선택 로케일 */
    defaultLocale?: string;
    /** 로케일 변경 시 콜백 */
    onLocaleChange?: (locale: string) => void;
    /** 표시할 로케일 목록 (미지정 시 그누보드7 지원 로케일 전체 표시) */
    locales?: string[];
}
/**
 * MultilingualTabPanel
 *
 * @example
 * ```tsx
 * <MultilingualTabPanel variant="underline">
 *   <DynamicFieldList
 *     dataKey="fields"
 *     columns={[
 *       { key: "name", type: "multilingual" },
 *       { key: "content", type: "multilingual" }
 *     ]}
 *   />
 * </MultilingualTabPanel>
 * ```
 */
export declare const MultilingualTabPanel: React.FC<MultilingualTabPanelProps>;
export default MultilingualTabPanel;
