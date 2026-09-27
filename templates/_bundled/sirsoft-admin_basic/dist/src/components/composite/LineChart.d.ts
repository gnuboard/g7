import { default as React } from 'react';
import { EditorAttrs } from '../../types';
export interface LineChartDataset {
    label: string;
    data: number[];
    /** 선 색상 (hex). 없으면 순서대로 기본 팔레트 */
    color?: string;
    /** 선 아래를 옅게 채울지 여부 */
    fill?: boolean;
    /**
     * 사용할 Y축 ID. 'y'(기본, 좌측) 또는 'y1'(보조, 우측).
     * 규모가 크게 다른 두 계열(예: 방문자 vs 페이지뷰)을 한 차트에 그릴 때 한쪽을 'y1' 로 둔다.
     */
    yAxisID?: 'y' | 'y1';
}
export interface LineChartProps {
    /** X축 라벨 배열 */
    labels: string[];
    /** 데이터셋 배열 */
    datasets: LineChartDataset[];
    /** 차트 높이 (px 숫자, 또는 단위 포함 문자열 — 편집기 text 위젯은 숫자 문자열 전달) */
    height?: number | string;
    /** 범례 표시 여부 */
    showLegend?: boolean;
    /** X축 그리드(세로선) 표시 여부 */
    showGrid?: boolean;
    /** Y축 그리드(가로선) 표시 여부 */
    showYGrid?: boolean;
    /** Y축 눈금 표시 여부 */
    showYAxis?: boolean;
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
 * React.memo로 감싸서 props가 같으면 re-render 스킵
 */
export declare const LineChart: React.NamedExoticComponent<LineChartProps>;
