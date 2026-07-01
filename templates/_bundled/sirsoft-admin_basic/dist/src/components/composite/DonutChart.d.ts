import { default as React } from 'react';
import { EditorAttrs } from '../../types';
export interface DonutChartDataItem {
    /** 항목 이름 */
    name: string;
    /** 값 */
    value: number;
    /** 색상 (hex) */
    color: string;
}
export interface DonutChartProps {
    /** 데이터 배열 */
    data: DonutChartDataItem[];
    /** 중앙 라벨 (예: "April 2025") */
    centerLabel?: string;
    /** 중앙 값 (예: "$14,582.94") */
    centerValue?: string;
    /** 차트 크기 (px 숫자, 또는 단위 포함 문자열 — 편집기 text 위젯은 숫자 문자열 전달) */
    size?: number | string;
    /** 도넛 두께 비율 (0-1) */
    cutout?: string;
    /** 범례 표시 여부 */
    showLegend?: boolean;
    /** 추가 CSS 클래스 */
    className?: string;
    /**
     * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
     */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
}
/**
 * DonutChart 컴포넌트
 *
 * Chart.js 기반 도넛 차트 컴포넌트입니다.
 * 중앙에 총액이나 기간을 표시할 수 있습니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "DonutChart",
 *   "props": {
 *     "data": [
 *       { "name": "Direct link", "value": 1600, "color": "#8B5CF6" },
 *       { "name": "Advertising", "value": 1900, "color": "#EC4899" },
 *       { "name": "Email", "value": 2096, "color": "#3B82F6" }
 *     ],
 *     "centerLabel": "April 2025",
 *     "centerValue": "$14,582.94",
 *     "size": 200
 *   }
 * }
 */
export declare const DonutChart: React.FC<DonutChartProps>;
