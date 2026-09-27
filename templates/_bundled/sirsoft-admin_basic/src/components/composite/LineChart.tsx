import React, { useEffect, useMemo, useState, memo } from 'react';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Filler,
  Title,
  Tooltip,
  Legend,
  ChartOptions,
  ChartData,
} from 'chart.js';
import { Line } from 'react-chartjs-2';
import type { EditorAttrs } from '../../types';

// Chart.js 컴포넌트 등록
ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Filler,
  Title,
  Tooltip,
  Legend
);

/**
 * 계열 색상 기본값 — 색을 주지 않은 계열은 순서대로 이 팔레트를 쓴다.
 * 방문자·방문·페이지뷰처럼 세 선이 한 차트에 놓일 때 서로 구분되는 색이어야 한다.
 */
const DEFAULT_COLORS = ['#7C3AED', '#0EA5E9', '#F59E0B', '#10B981', '#EF4444'];

/**
 * 축·격자·범례 색 — 라이트와 다크 판에서 각각 읽히는 값.
 * 캔버스는 Tailwind `dark:` 를 받지 못하므로 문서 테마를 읽어 색을 직접 고른다.
 */
const CHROME_COLORS = {
  light: { grid: '#e2e8f0', tick: '#94a3b8', legend: '#475569' },
  dark: { grid: '#334155', tick: '#94a3b8', legend: '#cbd5e1' },
} as const;

/**
 * 문서 루트의 `dark` 클래스로 지금 다크 모드인지 돌려줍니다.
 *
 * @returns 다크 모드 여부
 */
function isDocumentDark(): boolean {
  return typeof document !== 'undefined' && document.documentElement.classList.contains('dark');
}

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
 * LineChart 컴포넌트
 *
 * Chart.js 기반 선 차트 컴포넌트입니다.
 * 방문자 추이처럼 시간 축을 따라 변하는 값을 여러 계열로 그립니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "LineChart",
 *   "props": {
 *     "labels": ["09/10", "09/11", "09/12"],
 *     "datasets": [
 *       { "label": "방문자", "data": [120, 180, 150], "color": "#7C3AED", "fill": true },
 *       { "label": "페이지뷰", "data": [300, 420, 380], "color": "#0EA5E9" }
 *     ],
 *     "height": 260,
 *     "showLegend": true
 *   }
 * }
 */
const LineChartComponent: React.FC<LineChartProps> = ({
  labels,
  datasets,
  height = 260,
  showLegend = false,
  showGrid = false,
  showYGrid = true,
  showYAxis = true,
  className = '',
  id,
  editorAttrs,
}) => {
  // 테마 전환은 엔진이 루트의 `dark` 클래스를 토글하는 한 지점이라 그것만 지켜본다(CodeEditor 와 같은 방식).
  const [dark, setDark] = useState<boolean>(() => isDocumentDark());
  useEffect(() => {
    if (typeof document === 'undefined' || typeof MutationObserver === 'undefined') {
      return;
    }

    const observer = new MutationObserver(() => setDark(isDocumentDark()));
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    setDark(isDocumentDark());

    return () => observer.disconnect();
  }, []);

  const chrome = dark ? CHROME_COLORS.dark : CHROME_COLORS.light;

  const chartData: ChartData<'line'> = useMemo(() => ({
    labels: labels ?? [],
    datasets: (datasets ?? []).map((ds, index) => {
      const color = ds.color || DEFAULT_COLORS[index % DEFAULT_COLORS.length];

      return {
        label: ds.label,
        data: ds.data ?? [],
        borderColor: color,
        backgroundColor: ds.fill ? `${color}22` : color,
        fill: ds.fill ?? false,
        borderWidth: 2,
        pointRadius: 2,
        pointHoverRadius: 4,
        tension: 0.3,
        yAxisID: ds.yAxisID ?? 'y',
      };
    }),
  }), [labels, datasets]);

  // 보조 Y축('y1')을 쓰는 데이터셋이 하나라도 있으면 우측 보조 축을 렌더한다.
  const hasSecondaryAxis = useMemo(
    () => (datasets ?? []).some((ds) => ds.yAxisID === 'y1'),
    [datasets],
  );

  const options: ChartOptions<'line'> = useMemo(() => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: {
      duration: 500,
      easing: 'easeOutQuart',
    },
    transitions: {
      resize: {
        animation: {
          duration: 0,
        },
      },
    },
    interaction: {
      mode: 'index' as const,
      intersect: false,
    },
    plugins: {
      legend: {
        display: showLegend,
        position: 'top' as const,
        labels: {
          color: chrome.legend,
        },
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
          color: chrome.tick,
          font: {
            size: 11,
          },
          maxRotation: 0,
          autoSkip: true,
        },
      },
      y: {
        type: 'linear' as const,
        position: 'left' as const,
        grid: {
          display: showYGrid,
          color: chrome.grid,
        },
        border: {
          display: false,
        },
        ticks: {
          display: showYAxis,
          color: chrome.tick,
          font: {
            size: 11,
          },
          padding: 8,
          precision: 0,
        },
        beginAtZero: true,
        grace: '5%',
      },
      // 보조 Y축(우측) — 규모가 다른 계열을 독립 스케일로 그린다.
      ...(hasSecondaryAxis
        ? {
            y1: {
              type: 'linear' as const,
              position: 'right' as const,
              grid: {
                display: false,
              },
              border: {
                display: false,
              },
              ticks: {
                display: showYAxis,
                color: chrome.tick,
                font: {
                  size: 11,
                },
                padding: 8,
                precision: 0,
              },
              beginAtZero: true,
              grace: '5%',
            },
          }
        : {}),
    },
  }), [showLegend, showGrid, showYGrid, showYAxis, hasSecondaryAxis, chrome]);

  // height 정규화: 편집기 text 위젯은 숫자 문자열("280")을 주므로 px 부착 필요.
  const normalizedHeight =
    typeof height === 'number'
      ? height
      : /^\d+$/.test(String(height))
        ? `${height}px`
        : height;

  return (
    <div className={`line-chart-container ${className}`} style={{ height: normalizedHeight }} id={id} {...editorAttrs}>
      <Line data={chartData} options={options} />
    </div>
  );
};

/**
 * React.memo로 감싸서 props가 같으면 re-render 스킵
 */
export const LineChart = memo(LineChartComponent, (prevProps, nextProps) => {
  return (
    JSON.stringify(prevProps.labels) === JSON.stringify(nextProps.labels) &&
    JSON.stringify(prevProps.datasets) === JSON.stringify(nextProps.datasets) &&
    prevProps.height === nextProps.height &&
    prevProps.showLegend === nextProps.showLegend &&
    prevProps.showGrid === nextProps.showGrid &&
    prevProps.showYGrid === nextProps.showYGrid &&
    prevProps.showYAxis === nextProps.showYAxis &&
    prevProps.className === nextProps.className &&
    prevProps.id === nextProps.id &&
    prevProps.editorAttrs === nextProps.editorAttrs
  );
});
