import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { BarChart, BarChartProps } from '../BarChart';

// Chart.js canvas mock
vi.mock('react-chartjs-2', () => ({
  Bar: ({ data, options }: { data: unknown; options: unknown }) => (
    <div data-testid="bar-chart-mock" data-chart-data={JSON.stringify(data)} data-chart-options={JSON.stringify(options)}>
      Bar Chart Mock
    </div>
  ),
}));

describe('BarChart', () => {
  const defaultProps: BarChartProps = {
    labels: ['Jan', 'Feb', 'Mar', 'Apr'],
    datasets: [
      {
        label: 'Sales',
        data: [100, 200, 150, 300],
        backgroundColor: '#7C3AED',
      },
    ],
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('컴포넌트가 렌더링됨', () => {
    render(<BarChart {...defaultProps} />);

    expect(screen.getByTestId('bar-chart-mock')).toBeInTheDocument();
  });

  it('기본 높이가 200px로 설정됨', () => {
    const { container } = render(<BarChart {...defaultProps} />);

    const chartContainer = container.querySelector('.bar-chart-container');
    expect(chartContainer).toHaveStyle({ height: '200px' });
  });

  it('커스텀 높이가 적용됨', () => {
    const { container } = render(<BarChart {...defaultProps} height={300} />);

    const chartContainer = container.querySelector('.bar-chart-container');
    expect(chartContainer).toHaveStyle({ height: '300px' });
  });

  it('className이 적용됨', () => {
    const { container } = render(<BarChart {...defaultProps} className="custom-class" />);

    const chartContainer = container.querySelector('.bar-chart-container');
    expect(chartContainer).toHaveClass('custom-class');
  });

  it('labels가 차트 데이터에 전달됨', () => {
    render(<BarChart {...defaultProps} />);

    const chartMock = screen.getByTestId('bar-chart-mock');
    const chartData = JSON.parse(chartMock.dataset.chartData || '{}');

    expect(chartData.labels).toEqual(['Jan', 'Feb', 'Mar', 'Apr']);
  });

  it('datasets이 차트 데이터에 전달됨', () => {
    render(<BarChart {...defaultProps} />);

    const chartMock = screen.getByTestId('bar-chart-mock');
    const chartData = JSON.parse(chartMock.dataset.chartData || '{}');

    expect(chartData.datasets).toHaveLength(1);
    expect(chartData.datasets[0].label).toBe('Sales');
    expect(chartData.datasets[0].data).toEqual([100, 200, 150, 300]);
    expect(chartData.datasets[0].backgroundColor).toBe('#7C3AED');
  });

  it('여러 데이터셋을 지원함', () => {
    const multiDatasetProps: BarChartProps = {
      labels: ['Jan', 'Feb'],
      datasets: [
        { label: 'Sales', data: [100, 200], backgroundColor: '#7C3AED' },
        { label: 'Earning', data: [50, 100], backgroundColor: '#DDD6FE' },
      ],
    };

    render(<BarChart {...multiDatasetProps} />);

    const chartMock = screen.getByTestId('bar-chart-mock');
    const chartData = JSON.parse(chartMock.dataset.chartData || '{}');

    expect(chartData.datasets).toHaveLength(2);
    expect(chartData.datasets[0].label).toBe('Sales');
    expect(chartData.datasets[1].label).toBe('Earning');
  });

  it('기본 backgroundColor가 적용됨 (첫 번째 데이터셋)', () => {
    const propsWithoutColor: BarChartProps = {
      labels: ['Jan'],
      datasets: [{ label: 'Sales', data: [100] }],
    };

    render(<BarChart {...propsWithoutColor} />);

    const chartMock = screen.getByTestId('bar-chart-mock');
    const chartData = JSON.parse(chartMock.dataset.chartData || '{}');

    expect(chartData.datasets[0].backgroundColor).toBe('#7C3AED');
  });

  it('기본 backgroundColor가 적용됨 (두 번째 데이터셋)', () => {
    const propsWithoutColor: BarChartProps = {
      labels: ['Jan'],
      datasets: [
        { label: 'Sales', data: [100] },
        { label: 'Earning', data: [50] },
      ],
    };

    render(<BarChart {...propsWithoutColor} />);

    const chartMock = screen.getByTestId('bar-chart-mock');
    const chartData = JSON.parse(chartMock.dataset.chartData || '{}');

    expect(chartData.datasets[1].backgroundColor).toBe('#DDD6FE');
  });

  it('기본 borderRadius가 4로 설정됨', () => {
    render(<BarChart {...defaultProps} />);

    const chartMock = screen.getByTestId('bar-chart-mock');
    const chartData = JSON.parse(chartMock.dataset.chartData || '{}');

    expect(chartData.datasets[0].borderRadius).toBe(4);
  });

  it('커스텀 borderRadius가 적용됨', () => {
    const propsWithRadius: BarChartProps = {
      labels: ['Jan'],
      datasets: [{ label: 'Sales', data: [100], borderRadius: 8 }],
    };

    render(<BarChart {...propsWithRadius} />);

    const chartMock = screen.getByTestId('bar-chart-mock');
    const chartData = JSON.parse(chartMock.dataset.chartData || '{}');

    expect(chartData.datasets[0].borderRadius).toBe(8);
  });

  it('showLegend가 false일 때 범례가 숨겨짐', () => {
    render(<BarChart {...defaultProps} showLegend={false} />);

    const chartMock = screen.getByTestId('bar-chart-mock');
    const chartOptions = JSON.parse(chartMock.dataset.chartOptions || '{}');

    expect(chartOptions.plugins.legend.display).toBe(false);
  });

  it('showLegend가 true일 때 범례가 표시됨', () => {
    render(<BarChart {...defaultProps} showLegend={true} />);

    const chartMock = screen.getByTestId('bar-chart-mock');
    const chartOptions = JSON.parse(chartMock.dataset.chartOptions || '{}');

    expect(chartOptions.plugins.legend.display).toBe(true);
  });

  it('showGrid가 false일 때 그리드가 숨겨짐', () => {
    render(<BarChart {...defaultProps} showGrid={false} />);

    const chartMock = screen.getByTestId('bar-chart-mock');
    const chartOptions = JSON.parse(chartMock.dataset.chartOptions || '{}');

    expect(chartOptions.scales.x.grid.display).toBe(false);
    expect(chartOptions.scales.y.grid.display).toBe(false);
  });

  it('showGrid가 true일 때 그리드가 표시됨', () => {
    render(<BarChart {...defaultProps} showGrid={true} />);

    const chartMock = screen.getByTestId('bar-chart-mock');
    const chartOptions = JSON.parse(chartMock.dataset.chartOptions || '{}');

    expect(chartOptions.scales.x.grid.display).toBe(true);
    expect(chartOptions.scales.y.grid.display).toBe(false);
  });

  it('showYGrid가 true일 때 Y축 그리드가 표시됨', () => {
    render(<BarChart {...defaultProps} showYGrid={true} />);

    const chartMock = screen.getByTestId('bar-chart-mock');
    const chartOptions = JSON.parse(chartMock.dataset.chartOptions || '{}');

    expect(chartOptions.scales.x.grid.display).toBe(false);
    expect(chartOptions.scales.y.grid.display).toBe(true);
  });

  it('반응형 옵션이 활성화됨', () => {
    render(<BarChart {...defaultProps} />);

    const chartMock = screen.getByTestId('bar-chart-mock');
    const chartOptions = JSON.parse(chartMock.dataset.chartOptions || '{}');

    expect(chartOptions.responsive).toBe(true);
    expect(chartOptions.maintainAspectRatio).toBe(false);
  });

  it('Y축이 0부터 시작함', () => {
    render(<BarChart {...defaultProps} />);

    const chartMock = screen.getByTestId('bar-chart-mock');
    const chartOptions = JSON.parse(chartMock.dataset.chartOptions || '{}');

    expect(chartOptions.scales.y.beginAtZero).toBe(true);
  });

  // 코어 일괄 ID(요소 id) 회귀 가드.
  // BarChart 는 React.memo 커스텀 비교자를 쓰는데, 비교자에 id 가 빠져 있어 id 만
  // 바꾸면 memo 가 재렌더를 스킵해 DOM 에 미반영되던 결함을 정정했다(비교자에 id/
  // editorAttrs 추가). 루트 id passthrough + 비교자 id 비교를 함께 가드한다.
  it('id prop 이 루트(.bar-chart-container)에 반영됨', () => {
    const { container } = render(<BarChart {...defaultProps} id="my-bar-chart" />);
    expect(container.querySelector('.bar-chart-container')).toHaveAttribute('id', 'my-bar-chart');
  });

  it('id 만 바뀌어도 memo 가 재렌더해 DOM id 가 갱신됨', () => {
    const { container, rerender } = render(<BarChart {...defaultProps} id="id-a" />);
    expect(container.querySelector('.bar-chart-container')).toHaveAttribute('id', 'id-a');
    rerender(<BarChart {...defaultProps} id="id-b" />);
    expect(container.querySelector('.bar-chart-container')).toHaveAttribute('id', 'id-b');
  });

  // 편집기 결함 회귀 가드.
  // (1) 편집기 text 위젯은 숫자 문자열("300")을 prop 으로 주는데, 컴포넌트가
  //     style={{height}} 에 그대로 쓰면 단위 없는 CSS("height:300")라 무시되어
  //     캔버스에 미반영되던 결함 → 숫자 문자열은 px 부착하도록 정규화.
  // (2) defaultNode 가 datasets 미시드(과거 잘못된 data shape) 시 datasets.map 이
  //     undefined.map 크래시("Cannot read properties of undefined (reading 'map')")
  //     를 내던 결함 → (datasets ?? []) 폴백.
  describe('편집기 호환 정규화/방어', () => {
    it('숫자 문자열 height("300")가 px 로 정규화되어 적용됨', () => {
      const { container } = render(<BarChart {...defaultProps} height={'300' as unknown as number} />);
      expect(container.querySelector('.bar-chart-container')).toHaveStyle({ height: '300px' });
    });

    it('단위 포함 문자열 height("50%")는 그대로 적용됨', () => {
      const { container } = render(<BarChart {...defaultProps} height={'50%' as unknown as number} />);
      expect(container.querySelector('.bar-chart-container')).toHaveStyle({ height: '50%' });
    });

    it('datasets 가 undefined 여도 크래시 없이 렌더됨', () => {
      expect(() =>
        render(<BarChart labels={['A', 'B']} datasets={undefined as unknown as BarChartProps['datasets']} />)
      ).not.toThrow();
      expect(screen.getByTestId('bar-chart-mock')).toBeInTheDocument();
    });
  });

  // 보조 Y축: 단위/규모가 다른 두 계열(판매 수량 vs 매출액)을 독립 스케일로 그린다.
  // (단일 축이면 큰 값 계열에 작은 값 계열이 묻혀 막대가 보이지 않는 회귀 가드)
  describe('보조 Y축 (yAxisID)', () => {
    it('yAxisID 가 데이터셋에 전달됨', () => {
      render(
        <BarChart
          labels={['A', 'B']}
          datasets={[
            { label: '수량', data: [10, 20], yAxisID: 'y' },
            { label: '매출', data: [1000000, 2000000], yAxisID: 'y1' },
          ]}
        />,
      );

      const chartMock = screen.getByTestId('bar-chart-mock');
      const chartData = JSON.parse(chartMock.dataset.chartData || '{}');

      expect(chartData.datasets[0].yAxisID).toBe('y');
      expect(chartData.datasets[1].yAxisID).toBe('y1');
    });

    it("yAxisID 미지정 시 기본 'y' 축으로 매핑됨", () => {
      render(<BarChart {...defaultProps} />);

      const chartMock = screen.getByTestId('bar-chart-mock');
      const chartData = JSON.parse(chartMock.dataset.chartData || '{}');

      expect(chartData.datasets[0].yAxisID).toBe('y');
    });

    it("데이터셋이 'y1' 을 쓰면 options.scales 에 보조 축(우측)이 추가됨", () => {
      render(
        <BarChart
          labels={['A', 'B']}
          datasets={[
            { label: '수량', data: [10, 20], yAxisID: 'y' },
            { label: '매출', data: [1000000, 2000000], yAxisID: 'y1' },
          ]}
        />,
      );

      const chartMock = screen.getByTestId('bar-chart-mock');
      const options = JSON.parse(chartMock.dataset.chartOptions || '{}');

      expect(options.scales.y1).toBeDefined();
      expect(options.scales.y1.position).toBe('right');
      expect(options.scales.y.position).toBe('left');
    });

    it("'y1' 을 쓰는 데이터셋이 없으면 보조 축이 생성되지 않음", () => {
      render(<BarChart {...defaultProps} />);

      const chartMock = screen.getByTestId('bar-chart-mock');
      const options = JSON.parse(chartMock.dataset.chartOptions || '{}');

      expect(options.scales.y1).toBeUndefined();
    });
  });
});
