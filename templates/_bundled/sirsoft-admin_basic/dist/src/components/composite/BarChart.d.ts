import { default as React } from 'react';
import { EditorAttrs } from '../../types';
export interface BarChartDataset {
    label: string;
    data: number[];
    backgroundColor?: string;
    borderRadius?: number;
    /**
     * 사용할 Y축 ID. 'y'(기본, 좌측) 또는 'y1'(보조, 우측).
     * 단위/규모가 크게 다른 두 계열(예: 판매 수량 vs 매출액)을 한 차트에 그릴 때,
     * 한 계열을 'y1' 로 지정하면 각자 스케일로 그려져 작은 값 계열이 묻히지 않는다.
     */
    yAxisID?: 'y' | 'y1';
}
export interface BarChartProps {
    /** X축 라벨 배열 */
    labels: string[];
    /** 데이터셋 배열 */
    datasets: BarChartDataset[];
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
 * 페이지 로딩바 완료 시 불필요한 리렌더링 방지
 */
export declare const BarChart: React.NamedExoticComponent<BarChartProps>;
