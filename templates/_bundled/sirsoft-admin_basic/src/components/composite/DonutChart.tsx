// e2e:allow편집기 결함#7 수정(data undefined 폴백 + size 숫자문자열 px 정규화 + defaultNode name/color shape). 라이브 검증은 Chrome MCP T1~T7(에디터 추가/중앙라벨·크기편집→캔버스반영/저장200/reload/게스트 사용자화면)로 수행, 단위 회귀는 DonutChart.test.tsx verify-4 describe.
import React, { useMemo } from 'react';
import {
  Chart as ChartJS,
  ArcElement,
  Tooltip,
  Legend,
  ChartOptions,
  ChartData,
} from 'chart.js';
import { Doughnut } from 'react-chartjs-2';
import type { EditorAttrs } from '../../types';

// Chart.js 컴포넌트 등록
ChartJS.register(ArcElement, Tooltip, Legend);

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
export const DonutChart: React.FC<DonutChartProps> = ({
  data,
  centerLabel,
  centerValue,
  size = 200,
  cutout = '70%',
  showLegend = false,
  className = '',
  id,
  editorAttrs,
}) => {
  const chartData: ChartData<'doughnut'> = useMemo(() => ({
    labels: (data ?? []).map(item => item.name),
    datasets: [
      {
        data: (data ?? []).map(item => item.value),
        backgroundColor: (data ?? []).map(item => item.color),
        borderWidth: 0,
        hoverOffset: 4,
      },
    ],
  }), [data]);

  const options: ChartOptions<'doughnut'> = useMemo(() => ({
    responsive: true,
    maintainAspectRatio: false,
    cutout,
    plugins: {
      legend: {
        display: showLegend,
      },
      tooltip: {
        backgroundColor: '#1e293b',
        titleColor: '#fff',
        bodyColor: '#fff',
        padding: 12,
        cornerRadius: 8,
        callbacks: {
          label: (context) => {
            const value = context.parsed;
            return ` $${value.toLocaleString()}`;
          },
        },
      },
    },
  }), [cutout, showLegend]);

  // size 정규화: 편집기 text 위젯은 숫자 문자열("200")을 주므로 px 부착 필요.
  const normalizedSize =
    typeof size === 'number'
      ? size
      : /^\d+$/.test(String(size))
        ? `${size}px`
        : size;

  return (
    <div className={`donut-chart-wrapper ${className}`} id={id} {...editorAttrs}>
      <div className="relative" style={{ width: normalizedSize, height: normalizedSize }}>
        <Doughnut data={chartData} options={options} />
        {(centerLabel || centerValue) && (
          <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
            {centerLabel && (
              <span className="text-xs text-slate-500 dark:text-slate-400">
                {centerLabel}
              </span>
            )}
            {centerValue && (
              <span className="text-lg font-bold text-slate-900 dark:text-white">
                {centerValue}
              </span>
            )}
          </div>
        )}
      </div>
    </div>
  );
};
