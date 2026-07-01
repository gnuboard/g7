import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { DonutChart, DonutChartProps } from '../DonutChart';

// Chart.js canvas mock
vi.mock('react-chartjs-2', () => ({
  Doughnut: ({ data, options }: { data: unknown; options: unknown }) => (
    <div data-testid="donut-chart-mock" data-chart-data={JSON.stringify(data)} data-chart-options={JSON.stringify(options)}>
      Donut Chart Mock
    </div>
  ),
}));

describe('DonutChart', () => {
  const defaultProps: DonutChartProps = {
    data: [
      { name: 'Direct link', value: 1600, color: '#8B5CF6' },
      { name: 'Advertising', value: 1900, color: '#EC4899' },
      { name: 'Email', value: 2096, color: '#3B82F6' },
    ],
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('컴포넌트가 렌더링됨', () => {
    render(<DonutChart {...defaultProps} />);

    expect(screen.getByTestId('donut-chart-mock')).toBeInTheDocument();
  });

  it('기본 크기가 200px로 설정됨', () => {
    const { container } = render(<DonutChart {...defaultProps} />);

    const chartWrapper = container.querySelector('.relative');
    expect(chartWrapper).toHaveStyle({ width: '200px', height: '200px' });
  });

  it('커스텀 크기가 적용됨', () => {
    const { container } = render(<DonutChart {...defaultProps} size={300} />);

    const chartWrapper = container.querySelector('.relative');
    expect(chartWrapper).toHaveStyle({ width: '300px', height: '300px' });
  });

  it('className이 적용됨', () => {
    const { container } = render(<DonutChart {...defaultProps} className="custom-class" />);

    const chartContainer = container.querySelector('.donut-chart-wrapper');
    expect(chartContainer).toHaveClass('custom-class');
  });

  it('data가 차트 데이터에 전달됨', () => {
    render(<DonutChart {...defaultProps} />);

    const chartMock = screen.getByTestId('donut-chart-mock');
    const chartData = JSON.parse(chartMock.dataset.chartData || '{}');

    expect(chartData.labels).toEqual(['Direct link', 'Advertising', 'Email']);
    expect(chartData.datasets[0].data).toEqual([1600, 1900, 2096]);
    expect(chartData.datasets[0].backgroundColor).toEqual(['#8B5CF6', '#EC4899', '#3B82F6']);
  });

  it('centerLabel이 표시됨', () => {
    render(<DonutChart {...defaultProps} centerLabel="April 2025" />);

    expect(screen.getByText('April 2025')).toBeInTheDocument();
  });

  it('centerValue가 표시됨', () => {
    render(<DonutChart {...defaultProps} centerValue="$14,582.94" />);

    expect(screen.getByText('$14,582.94')).toBeInTheDocument();
  });

  it('centerLabel과 centerValue가 함께 표시됨', () => {
    render(
      <DonutChart
        {...defaultProps}
        centerLabel="April 2025"
        centerValue="$14,582.94"
      />
    );

    expect(screen.getByText('April 2025')).toBeInTheDocument();
    expect(screen.getByText('$14,582.94')).toBeInTheDocument();
  });

  it('centerLabel과 centerValue가 없으면 중앙 영역이 표시되지 않음', () => {
    const { container } = render(<DonutChart {...defaultProps} />);

    const centerOverlay = container.querySelector('.absolute.inset-0');
    expect(centerOverlay).not.toBeInTheDocument();
  });

  it('기본 cutout이 70%로 설정됨', () => {
    render(<DonutChart {...defaultProps} />);

    const chartMock = screen.getByTestId('donut-chart-mock');
    const chartOptions = JSON.parse(chartMock.dataset.chartOptions || '{}');

    expect(chartOptions.cutout).toBe('70%');
  });

  it('커스텀 cutout이 적용됨', () => {
    render(<DonutChart {...defaultProps} cutout="60%" />);

    const chartMock = screen.getByTestId('donut-chart-mock');
    const chartOptions = JSON.parse(chartMock.dataset.chartOptions || '{}');

    expect(chartOptions.cutout).toBe('60%');
  });

  it('showLegend가 false일 때 범례가 숨겨짐', () => {
    render(<DonutChart {...defaultProps} showLegend={false} />);

    const chartMock = screen.getByTestId('donut-chart-mock');
    const chartOptions = JSON.parse(chartMock.dataset.chartOptions || '{}');

    expect(chartOptions.plugins.legend.display).toBe(false);
  });

  it('showLegend가 true일 때 범례가 표시됨', () => {
    render(<DonutChart {...defaultProps} showLegend={true} />);

    const chartMock = screen.getByTestId('donut-chart-mock');
    const chartOptions = JSON.parse(chartMock.dataset.chartOptions || '{}');

    expect(chartOptions.plugins.legend.display).toBe(true);
  });

  it('반응형 옵션이 활성화됨', () => {
    render(<DonutChart {...defaultProps} />);

    const chartMock = screen.getByTestId('donut-chart-mock');
    const chartOptions = JSON.parse(chartMock.dataset.chartOptions || '{}');

    expect(chartOptions.responsive).toBe(true);
    expect(chartOptions.maintainAspectRatio).toBe(false);
  });

  it('borderWidth가 0으로 설정됨', () => {
    render(<DonutChart {...defaultProps} />);

    const chartMock = screen.getByTestId('donut-chart-mock');
    const chartData = JSON.parse(chartMock.dataset.chartData || '{}');

    expect(chartData.datasets[0].borderWidth).toBe(0);
  });

  it('hoverOffset이 4로 설정됨', () => {
    render(<DonutChart {...defaultProps} />);

    const chartMock = screen.getByTestId('donut-chart-mock');
    const chartData = JSON.parse(chartMock.dataset.chartData || '{}');

    expect(chartData.datasets[0].hoverOffset).toBe(4);
  });

  it('centerLabel에 올바른 스타일이 적용됨', () => {
    render(<DonutChart {...defaultProps} centerLabel="April 2025" />);

    const labelElement = screen.getByText('April 2025');
    expect(labelElement).toHaveClass('text-xs', 'text-slate-500');
  });

  it('centerValue에 올바른 스타일이 적용됨', () => {
    render(<DonutChart {...defaultProps} centerValue="$14,582.94" />);

    const valueElement = screen.getByText('$14,582.94');
    expect(valueElement).toHaveClass('text-lg', 'font-bold', 'text-slate-900');
  });

  it('빈 데이터 배열을 처리함', () => {
    render(<DonutChart data={[]} />);

    const chartMock = screen.getByTestId('donut-chart-mock');
    const chartData = JSON.parse(chartMock.dataset.chartData || '{}');

    expect(chartData.labels).toEqual([]);
    expect(chartData.datasets[0].data).toEqual([]);
  });

  it('단일 데이터 항목을 처리함', () => {
    const singleDataProps: DonutChartProps = {
      data: [{ name: 'Single', value: 1000, color: '#000000' }],
    };

    render(<DonutChart {...singleDataProps} />);

    const chartMock = screen.getByTestId('donut-chart-mock');
    const chartData = JSON.parse(chartMock.dataset.chartData || '{}');

    expect(chartData.labels).toEqual(['Single']);
    expect(chartData.datasets[0].data).toEqual([1000]);
  });

  // 편집기 결함 회귀 가드.
  // (1) 편집기 text 위젯이 size 를 숫자 문자열("150")로 주면 width/height CSS 가
  //     단위 없이 무시되던 결함 → px 정규화.
  // (2) data 가 undefined 일 때 data.map 크래시 → (data ?? []) 폴백.
  describe('편집기 호환 정규화/방어', () => {
    it('숫자 문자열 size("150")가 px 로 정규화되어 적용됨', () => {
      const { container } = render(<DonutChart {...defaultProps} size={'150' as unknown as number} />);
      const inner = container.querySelector('.relative');
      expect(inner).toHaveStyle({ width: '150px', height: '150px' });
    });

    it('data 가 undefined 여도 크래시 없이 렌더됨', () => {
      expect(() =>
        render(<DonutChart data={undefined as unknown as DonutChartProps['data']} />)
      ).not.toThrow();
      expect(screen.getByTestId('donut-chart-mock')).toBeInTheDocument();
    });
  });
});
