// e2e:allow편집기 결함#7 수정(datasets undefined 폴백 + height 숫자문자열 px 정규화). 라이브 검증은 Chrome MCP T1~T7(에디터 추가/높이편집→캔버스반영/저장200/reload/게스트 사용자화면)로 수행, 단위 회귀는 BarChart.test.tsx verify-4 describe.
import React, { useMemo, memo } from 'react';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  Title,
  Tooltip,
  Legend,
  ChartOptions,
  ChartData,
} from 'chart.js';
import { Bar } from 'react-chartjs-2';
import type { EditorAttrs } from '../../types';

// Chart.js 컴포넌트 등록
ChartJS.register(
  CategoryScale,
  LinearScale,
  BarElement,
  Title,
  Tooltip,
  Legend
);

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
 * BarChart 컴포넌트
 *
 * Chart.js 기반 바 차트 컴포넌트입니다.
 * 월별 매출, 카테고리별 데이터 등을 시각화합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "BarChart",
 *   "props": {
 *     "labels": ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug"],
 *     "datasets": [
 *       {
 *         "label": "Sales",
 *         "data": [120, 180, 150, 220, 190, 160, 200, 180],
 *         "backgroundColor": "#7C3AED"
 *       },
 *       {
 *         "label": "Earning",
 *         "data": [80, 120, 100, 150, 130, 110, 140, 120],
 *         "backgroundColor": "#DDD6FE"
 *       }
 *     ],
 *     "height": 200
 *   }
 * }
 */
const BarChartComponent: React.FC<BarChartProps> = ({
  labels,
  datasets,
  height = 200,
  showLegend = false,
  showGrid = false,
  showYGrid = false,
  showYAxis = false,
  className = '',
  id,
  editorAttrs,
}) => {
  const chartData: ChartData<'bar'> = useMemo(() => ({
    labels: labels ?? [],
    datasets: (datasets ?? []).map((ds, index) => ({
      label: ds.label,
      data: ds.data,
      backgroundColor: ds.backgroundColor || (index === 0 ? '#7C3AED' : '#DDD6FE'),
      borderRadius: ds.borderRadius ?? 4,
      barThickness: 16,
      maxBarThickness: 20,
      yAxisID: ds.yAxisID ?? 'y',
    })),
  }), [labels, datasets]);

  // 보조 Y축('y1')을 쓰는 데이터셋이 하나라도 있으면 우측 보조 축을 렌더한다.
  const hasSecondaryAxis = useMemo(
    () => (datasets ?? []).some((ds) => ds.yAxisID === 'y1'),
    [datasets],
  );

  const options: ChartOptions<'bar'> = useMemo(() => ({
    responsive: true,
    maintainAspectRatio: false,
    // 아래에서 위로 자라나는 애니메이션
    animation: {
      duration: 750,
      easing: 'easeOutQuart',
    },
    // X축 애니메이션 비활성화, Y축만 활성화 (아래에서 위로 자라남)
    animations: {
      x: {
        duration: 0,
      },
      y: {
        from: (ctx) => {
          if (ctx.type === 'data') {
            return ctx.chart.scales.y.getPixelForValue(0);
          }
          return undefined;
        },
      },
    },
    transitions: {
      active: {
        animation: {
          duration: 200,
        },
      },
      resize: {
        animation: {
          duration: 0,
        },
      },
    },
    plugins: {
      legend: {
        display: showLegend,
        position: 'top' as const,
      },
      tooltip: {
        backgroundColor: '#1e293b',
        titleColor: '#fff',
        bodyColor: '#fff',
        padding: 12,
        cornerRadius: 8,
      },
    },
    scales: {
      x: {
        grid: {
          display: showGrid,
        },
        border: {
          display: false,
        },
        ticks: {
          color: '#94a3b8',
          font: {
            size: 11,
          },
        },
      },
      y: {
        type: 'linear' as const,
        position: 'left' as const,
        grid: {
          display: showYGrid,
          color: '#e2e8f0',
        },
        border: {
          display: false,
        },
        ticks: {
          display: showYAxis,
          color: '#94a3b8',
          font: {
            size: 11,
          },
          padding: 8,
        },
        beginAtZero: true,
        grace: '5%',
      },
      // 보조 Y축(우측) — 단위/규모가 다른 계열을 독립 스케일로 그린다.
      ...(hasSecondaryAxis
        ? {
            y1: {
              type: 'linear' as const,
              position: 'right' as const,
              grid: {
                // 보조 축 그리드는 좌측 축과 겹치지 않도록 표시하지 않는다.
                display: false,
              },
              border: {
                display: false,
              },
              ticks: {
                display: showYAxis,
                color: '#94a3b8',
                font: {
                  size: 11,
                },
                padding: 8,
              },
              beginAtZero: true,
              grace: '5%',
            },
          }
        : {}),
    },
  }), [showLegend, showGrid, showYGrid, showYAxis, hasSecondaryAxis]);

  // height 정규화: 편집기 text 위젯은 숫자 문자열("280")을 주므로 px 부착 필요.
  // 단위 포함 문자열("50%"·"20rem")이나 숫자는 그대로 사용.
  const normalizedHeight =
    typeof height === 'number'
      ? height
      : /^\d+$/.test(String(height))
        ? `${height}px`
        : height;

  return (
    <div className={`bar-chart-container ${className}`} style={{ height: normalizedHeight }} id={id} {...editorAttrs}>
      <Bar data={chartData} options={options} />
    </div>
  );
};

/**
 * React.memo로 감싸서 props가 같으면 re-render 스킵
 * 페이지 로딩바 완료 시 불필요한 리렌더링 방지
 */
export const BarChart = memo(BarChartComponent, (prevProps, nextProps) => {
  return (
    JSON.stringify(prevProps.labels) === JSON.stringify(nextProps.labels) &&
    JSON.stringify(prevProps.datasets) === JSON.stringify(nextProps.datasets) &&
    prevProps.height === nextProps.height &&
    prevProps.showLegend === nextProps.showLegend &&
    prevProps.showGrid === nextProps.showGrid &&
    prevProps.showYGrid === nextProps.showYGrid &&
    prevProps.showYAxis === nextProps.showYAxis &&
    prevProps.className === nextProps.className &&
    // 요소 id / 편집기 주입 속성 변경도 재렌더 트리거(id 미비교 시
    // 코어 일괄 ID 편집이 memo 스킵으로 DOM 미반영되던 결함 정정)
    prevProps.id === nextProps.id &&
    prevProps.editorAttrs === nextProps.editorAttrs
  );
});
