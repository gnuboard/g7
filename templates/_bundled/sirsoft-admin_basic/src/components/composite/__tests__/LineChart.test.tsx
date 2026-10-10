/**
 * @file LineChart.test.tsx
 * @description 방문자 추이 선 차트 — 계열·색·높이 매핑과 빈 데이터 내성 (이슈 #682 S3)
 */

/**
 * LineChart 테스트 — 방문자 추이 차트가 계열·색·높이를 계약대로 그리는지 잠근다.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { LineChart, LineChartProps } from '../LineChart';

// Chart.js canvas mock
vi.mock('react-chartjs-2', () => ({
  Line: ({ data, options }: { data: unknown; options: unknown }) => (
    <div data-testid="line-chart-mock" data-chart-data={JSON.stringify(data)} data-chart-options={JSON.stringify(options)}>
      Line Chart Mock
    </div>
  ),
}));

describe('LineChart', () => {
  const defaultProps: LineChartProps = {
    labels: ['09/10', '09/11', '09/12'],
    datasets: [
      { label: '방문자', data: [10, 20, 15], color: '#7C3AED', fill: true },
      { label: '페이지뷰', data: [30, 40, 35] },
    ],
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  // @scenario screen=component, state=normal, viewport=1440, locale=ko
  // @effects line_chart_normalizes_height
  it('컴포넌트가 렌더링되고 기본 높이가 260px 이다', () => {
    const { container } = render(<LineChart {...defaultProps} />);

    expect(screen.getByTestId('line-chart-mock')).toBeInTheDocument();
    expect(container.querySelector('.line-chart-container')).toHaveStyle({ height: '260px' });
  });

  // @scenario screen=component, state=normal, viewport=390, locale=ko
  // @effects line_chart_normalizes_height
  it('숫자 문자열 높이는 px 를 붙이고 단위 문자열은 그대로 쓴다', () => {
    const { container, rerender } = render(<LineChart {...defaultProps} height="220" />);
    expect(container.querySelector('.line-chart-container')).toHaveStyle({ height: '220px' });

    rerender(<LineChart {...defaultProps} height="50%" />);
    expect(container.querySelector('.line-chart-container')).toHaveStyle({ height: '50%' });
  });

  // @scenario screen=component, state=normal, viewport=1440, locale=ko
  // @effects line_chart_maps_datasets_to_series
  it('datasets 가 선 색·채움·곡률을 가진 chart.js 계열로 매핑된다', () => {
    render(<LineChart {...defaultProps} />);

    const chartData = JSON.parse(screen.getByTestId('line-chart-mock').dataset.chartData || '{}');

    expect(chartData.labels).toEqual(['09/10', '09/11', '09/12']);
    expect(chartData.datasets).toHaveLength(2);
    expect(chartData.datasets[0]).toMatchObject({
      label: '방문자',
      data: [10, 20, 15],
      borderColor: '#7C3AED',
      fill: true,
      yAxisID: 'y',
    });
    // 채움 계열은 배경을 옅게(알파 부착), 채우지 않는 계열은 선 색 그대로
    expect(chartData.datasets[0].backgroundColor).toBe('#7C3AED22');
    expect(chartData.datasets[1].fill).toBe(false);
    // 색을 주지 않은 두 번째 계열은 기본 팔레트의 두 번째 색
    expect(chartData.datasets[1].borderColor).toBe('#0EA5E9');
  });

  // @scenario screen=component, state=empty, viewport=1440, locale=ko
  // @effects line_chart_survives_empty_data
  it('빈 데이터에서도 깨지지 않는다 (datasets·labels undefined 폴백)', () => {
    render(<LineChart labels={undefined as unknown as string[]} datasets={undefined as unknown as LineChartProps['datasets']} />);

    const chartData = JSON.parse(screen.getByTestId('line-chart-mock').dataset.chartData || '{}');
    expect(chartData.labels).toEqual([]);
    expect(chartData.datasets).toEqual([]);
  });

  // @scenario screen=component, state=normal, viewport=1440, locale=ko
  // @effects line_chart_adds_secondary_axis_only_when_needed
  it('y1 계열이 있을 때만 보조 축이 생기고 범례·격자 옵션이 props 를 따른다', () => {
    const { rerender } = render(<LineChart {...defaultProps} showLegend={true} showGrid={true} showYGrid={false} showYAxis={false} />);

    let options = JSON.parse(screen.getByTestId('line-chart-mock').dataset.chartOptions || '{}');
    expect(options.plugins.legend.display).toBe(true);
    expect(options.scales.x.grid.display).toBe(true);
    expect(options.scales.y.grid.display).toBe(false);
    expect(options.scales.y.ticks.display).toBe(false);
    expect(options.scales.y1).toBeUndefined();

    rerender(
      <LineChart
        labels={defaultProps.labels}
        datasets={[defaultProps.datasets[0], { ...defaultProps.datasets[1], yAxisID: 'y1' }]}
      />,
    );
    options = JSON.parse(screen.getByTestId('line-chart-mock').dataset.chartOptions || '{}');
    expect(options.scales.y1).toBeDefined();
    expect(options.scales.y1.position).toBe('right');
  });

  // @scenario screen=component, state=normal, viewport=1440, locale=en
  // @effects line_chart_applies_id_and_class
  it('id 와 className 이 컨테이너에 적용된다', () => {
    const { container } = render(<LineChart {...defaultProps} id="trend_chart" className="custom-class" />);

    const el = container.querySelector('.line-chart-container');
    expect(el).toHaveAttribute('id', 'trend_chart');
    expect(el).toHaveClass('custom-class');
  });

  // @scenario screen=component, state=normal, viewport=1440, locale=ko
  // @effects line_chart_follows_dark_mode
  it('문서가 다크 모드면 격자·범례를 어두운 판에 맞는 색으로 그리고, 테마를 바꾸면 따라간다', async () => {
    // 라이트 전용 격자색(#e2e8f0)이 다크 카드 위에서 흰 줄로 튀고 범례 글자가 묻혔다(브라우저 실측 §8 M2-T8).
    const options = () => JSON.parse(screen.getByTestId('line-chart-mock').dataset.chartOptions || '{}');
    document.documentElement.classList.add('dark');
    try {
      render(<LineChart {...defaultProps} showLegend />);
      expect(options().scales.y.grid.color).toBe('#334155');
      expect(options().plugins.legend.labels.color).toBe('#cbd5e1');

      document.documentElement.classList.remove('dark');
      await waitFor(() => expect(options().scales.y.grid.color).toBe('#e2e8f0'));
      expect(options().plugins.legend.labels.color).toBe('#475569');
    } finally {
      document.documentElement.classList.remove('dark');
    }
  });
});
