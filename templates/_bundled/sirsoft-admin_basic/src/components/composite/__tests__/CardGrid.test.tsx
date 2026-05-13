import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { CardGrid, CardGridCellChild } from '../CardGrid';

// renderItemChildren 모킹 함수 정의
const mockRenderItemChildren = vi.fn((children, context, componentMap, keyPrefix) => {
  return children.map((child: CardGridCellChild, index: number) => {
    const key = `${keyPrefix}-${child.id || child.name}-${index}`;
    const Component = componentMap[child.name];

    if (!Component) return null;

    let text = child.text;
    if (text && text.includes('{{row.')) {
      const match = text.match(/\{\{row\.(\w+)\}\}/);
      if (match) {
        const field = match[1];
        text = context.row[field];
      }
    }

    return (
      <Component key={key} {...child.props}>
        {text}
        {child.children && mockRenderItemChildren(child.children, context, componentMap, `${key}-child`)}
      </Component>
    );
  });
});

describe('CardGrid', () => {
  const mockCardChildren: CardGridCellChild[] = [
    {
      type: 'basic',
      name: 'Div',
      props: {
        className: 'bg-white dark:bg-gray-800 rounded-lg p-6',
      },
      children: [
        {
          type: 'basic',
          name: 'H3',
          text: '{{row.name}}',
          props: {
            className: 'text-lg font-semibold mb-2',
          },
        },
        {
          type: 'basic',
          name: 'P',
          text: '{{row.description}}',
          props: {
            className: 'text-gray-600 dark:text-gray-400',
          },
        },
      ],
    },
  ];

  const mockData = [
    { id: 1, name: '게시판 1', description: '설명 1' },
    { id: 2, name: '게시판 2', description: '설명 2' },
    { id: 3, name: '게시판 3', description: '설명 3' },
  ];

  // mockG7Core 참조 변수 (beforeEach에서 초기화)
  let mockG7Core: any;

  beforeEach(() => {
    vi.clearAllMocks();
    // 기존 G7Core를 유지하면서 renderItemChildren만 추가/덮어쓰기
    mockG7Core = {
      ...(window as any).G7Core,
      renderItemChildren: mockRenderItemChildren,
    };
    (window as any).G7Core = mockG7Core;
  });

  it('컴포넌트가 렌더링됨', () => {
    const { container } = render(
      <CardGrid data={mockData} cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]} />
    );
    expect(container.firstChild).toBeInTheDocument();
  });

  it('데이터가 undefined일 때 Skeleton이 표시됨', () => {
    const { container } = render(
      <CardGrid data={undefined} cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]} gridColumns={3} />
    );

    // Skeleton 카드가 3개 렌더링되는지 확인 (gridColumns 기반)
    // 각 스켈레톤 카드 컨테이너를 선택
    const skeletonCards = container.querySelectorAll('.bg-white.dark\\:bg-gray-800.border');
    expect(skeletonCards).toHaveLength(3);
  });

  it('데이터가 빈 배열일 때 Empty State가 표시됨', () => {
    render(<CardGrid data={[]} cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]} emptyMessage="데이터가 없습니다." />);

    expect(screen.getByText('데이터가 없습니다.')).toBeInTheDocument();
  });

  it('카드 그리드가 렌더링됨', () => {
    render(<CardGrid data={mockData} cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]} />);

    // G7Core.renderItemChildren가 데이터 개수만큼 호출되는지 확인
    expect(mockG7Core.renderItemChildren).toHaveBeenCalledTimes(mockData.length);
  });

  it('columns prop이 적용됨', () => {
    const { container } = render(
      <CardGrid data={mockData} cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]} gridColumns={2} />
    );

    const gridElement = container.querySelector('.grid');
    expect(gridElement).toHaveClass('lg:grid-cols-2');
  });

  it('gap prop이 적용됨', () => {
    const { container } = render(
      <CardGrid data={mockData} cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]} gap={6} />
    );

    const gridElement = container.querySelector('.grid');
    expect(gridElement).toHaveClass('gap-6');
  });

  it('반응형 columns 설정이 적용됨', () => {
    const { container } = render(
      <CardGrid
        data={mockData}
        cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]}
        gridColumns={3}
        responsiveColumns={{ sm: 1, md: 2, lg: 3, xl: 4 }}
      />
    );

    const gridElement = container.querySelector('.grid');
    expect(gridElement).toHaveClass('grid-cols-1');
    expect(gridElement).toHaveClass('md:grid-cols-2');
    expect(gridElement).toHaveClass('lg:grid-cols-3');
    expect(gridElement).toHaveClass('xl:grid-cols-4');
  });

  it('페이지네이션이 활성화되면 페이지 버튼이 표시됨', () => {
    const manyData = Array.from({ length: 25 }, (_, i) => ({
      id: i + 1,
      name: `게시판 ${i + 1}`,
      description: `설명 ${i + 1}`,
    }));

    render(
      <CardGrid
        data={manyData}
        cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]}
        pagination={true}
        pageSize={12}
      />
    );

    // 버튼은 aria-label로 찾기 (기본값은 ‹, › 기호)
    expect(screen.getByLabelText('이전 페이지')).toBeInTheDocument();
    expect(screen.getByLabelText('다음 페이지')).toBeInTheDocument();
    // 페이지 번호 버튼이 표시됨 (1, 2, 3)
    expect(screen.getByText('1')).toBeInTheDocument();
  });

  it('페이지네이션이 비활성화되면 모든 데이터가 표시됨', () => {
    const manyData = Array.from({ length: 25 }, (_, i) => ({
      id: i + 1,
      name: `게시판 ${i + 1}`,
      description: `설명 ${i + 1}`,
    }));

    render(
      <CardGrid
        data={manyData}
        cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]}
        pagination={false}
      />
    );

    // 모든 데이터가 렌더링됨
    expect(mockG7Core.renderItemChildren).toHaveBeenCalledTimes(25);

    // 페이지네이션 버튼이 없음
    expect(screen.queryByLabelText('이전 페이지')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('다음 페이지')).not.toBeInTheDocument();
  });

  it('다음 버튼 클릭 시 다음 페이지로 이동함', async () => {
    const user = userEvent.setup();
    const manyData = Array.from({ length: 25 }, (_, i) => ({
      id: i + 1,
      name: `게시판 ${i + 1}`,
      description: `설명 ${i + 1}`,
    }));

    render(
      <CardGrid
        data={manyData}
        cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]}
        pagination={true}
        pageSize={12}
      />
    );

    const nextButton = screen.getByLabelText('다음 페이지');
    await user.click(nextButton);

    // 페이지 2가 활성화됨 (aria-current="page" 확인)
    const page2Button = screen.getByLabelText('페이지 2');
    expect(page2Button).toHaveAttribute('aria-current', 'page');
  });

  it('이전 버튼 클릭 시 이전 페이지로 이동함', async () => {
    const user = userEvent.setup();
    const manyData = Array.from({ length: 25 }, (_, i) => ({
      id: i + 1,
      name: `게시판 ${i + 1}`,
      description: `설명 ${i + 1}`,
    }));

    render(
      <CardGrid
        data={manyData}
        cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]}
        pagination={true}
        pageSize={12}
      />
    );

    const nextButton = screen.getByLabelText('다음 페이지');
    await user.click(nextButton);
    await user.click(nextButton);

    // 페이지 3이 활성화됨
    expect(screen.getByLabelText('페이지 3')).toHaveAttribute('aria-current', 'page');

    const prevButton = screen.getByLabelText('이전 페이지');
    await user.click(prevButton);

    // 페이지 2가 활성화됨
    expect(screen.getByLabelText('페이지 2')).toHaveAttribute('aria-current', 'page');
  });

  it('첫 페이지에서 이전 버튼이 비활성화됨', () => {
    const manyData = Array.from({ length: 25 }, (_, i) => ({
      id: i + 1,
      name: `게시판 ${i + 1}`,
      description: `설명 ${i + 1}`,
    }));

    render(
      <CardGrid
        data={manyData}
        cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]}
        pagination={true}
        pageSize={12}
      />
    );

    const prevButton = screen.getByLabelText('이전 페이지');
    expect(prevButton).toBeDisabled();
  });

  it('마지막 페이지에서 다음 버튼이 비활성화됨', async () => {
    const user = userEvent.setup();
    const manyData = Array.from({ length: 25 }, (_, i) => ({
      id: i + 1,
      name: `게시판 ${i + 1}`,
      description: `설명 ${i + 1}`,
    }));

    render(
      <CardGrid
        data={manyData}
        cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]}
        pagination={true}
        pageSize={12}
      />
    );

    const nextButton = screen.getByLabelText('다음 페이지');
    await user.click(nextButton);
    await user.click(nextButton);

    // 페이지 3이 활성화됨 (마지막 페이지)
    expect(screen.getByLabelText('페이지 3')).toHaveAttribute('aria-current', 'page');
    expect(nextButton).toBeDisabled();
  });

  it('서버 사이드 페이지네이션이 작동함', () => {
    const onPageChange = vi.fn();

    render(
      <CardGrid
        data={mockData}
        cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]}
        pagination={true}
        serverSidePagination={true}
        serverCurrentPage={1}
        serverTotalPages={5}
        onPageChange={onPageChange}
      />
    );

    // 페이지 1이 활성화되어 있음
    expect(screen.getByLabelText('페이지 1')).toHaveAttribute('aria-current', 'page');
    // 총 5페이지이므로 페이지 5 버튼도 표시됨
    expect(screen.getByText('5')).toBeInTheDocument();
  });

  it('서버 사이드 페이지네이션 다음 버튼 클릭 시 onPageChange 호출됨', async () => {
    const user = userEvent.setup();
    const onPageChange = vi.fn();

    render(
      <CardGrid
        data={mockData}
        cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]}
        pagination={true}
        serverSidePagination={true}
        serverCurrentPage={1}
        serverTotalPages={5}
        onPageChange={onPageChange}
      />
    );

    const nextButton = screen.getByLabelText('다음 페이지');
    await user.click(nextButton);

    expect(onPageChange).toHaveBeenCalledWith(2);
  });

  it('alwaysShowPagination이 true면 1페이지여도 페이지네이션이 표시됨', () => {
    render(
      <CardGrid
        data={mockData}
        cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]}
        pagination={true}
        pageSize={10}
        alwaysShowPagination={true}
      />
    );

    expect(screen.getByLabelText('이전 페이지')).toBeInTheDocument();
    expect(screen.getByLabelText('다음 페이지')).toBeInTheDocument();
  });

  it('alwaysShowPagination이 false면 1페이지일 때 페이지네이션이 숨겨짐', () => {
    render(
      <CardGrid
        data={mockData}
        cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]}
        pagination={true}
        pageSize={10}
        alwaysShowPagination={false}
      />
    );

    expect(screen.queryByLabelText('이전 페이지')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('다음 페이지')).not.toBeInTheDocument();
  });

  it('className prop이 적용됨', () => {
    const { container } = render(
      <CardGrid
        data={mockData}
        cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]}
        className="custom-grid"
      />
    );
    expect(container.firstChild).toHaveClass('custom-grid');
  });

  it('cardClassName prop이 각 카드에 적용됨', () => {
    const { container } = render(
      <CardGrid
        data={mockData}
        cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]}
        cardClassName="custom-card"
      />
    );

    const cards = container.querySelectorAll('.custom-card');
    expect(cards).toHaveLength(mockData.length);
  });

  it('prevText와 nextText 커스터마이징이 적용됨', () => {
    const manyData = Array.from({ length: 25 }, (_, i) => ({
      id: i + 1,
      name: `게시판 ${i + 1}`,
      description: `설명 ${i + 1}`,
    }));

    render(
      <CardGrid
        data={manyData}
        cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]}
        pagination={true}
        pageSize={12}
        prevText="Previous"
        nextText="Next"
      />
    );

    expect(screen.getByText('Previous')).toBeInTheDocument();
    expect(screen.getByText('Next')).toBeInTheDocument();
  });

  it('emptyMessage 커스터마이징이 적용됨', () => {
    render(
      <CardGrid
        data={[]}
        cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]}
        emptyMessage="No boards available"
      />
    );

    expect(screen.getByText('No boards available')).toBeInTheDocument();
  });

  describe('스켈레톤 자동 생성', () => {
    it('cellChildren 기반으로 스켈레톤이 자동 생성됨', () => {
      const { container } = render(
        <CardGrid
          data={undefined}
          cardColumns={[{
            id: 'test',
            cellChildren: [
              { type: 'basic', name: 'H3', text: '{{row.title}}' },
              { type: 'basic', name: 'P', text: '{{row.description}}' },
            ],
          }]}
          gridColumns={2}
        />
      );

      // 스켈레톤 카드가 2개 생성됨
      const skeletonCards = container.querySelectorAll('.bg-white.dark\\:bg-gray-800');
      expect(skeletonCards).toHaveLength(2);

      // 각 카드 안에 스켈레톤 요소가 있음 (H3, P에 대응)
      const skeletonBars = container.querySelectorAll('.h-4.bg-gray-200.dark\\:bg-gray-700.rounded.w-full.animate-pulse');
      expect(skeletonBars.length).toBeGreaterThan(0);
    });

    it('skeletonCellChildren으로 커스텀 스켈레톤 사용 가능', () => {
      // 커스텀 스켈레톤을 Div 구조로 정의 (Div는 className이 유지됨)
      const customSkeleton: CardGridCellChild[] = [
        {
          type: 'basic',
          name: 'Div',
          props: { className: 'custom-skeleton-wrapper' },
          children: [
            { type: 'basic', name: 'H3', text: '제목' },
          ],
        },
      ];

      const { container } = render(
        <CardGrid
          data={undefined}
          cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]}
          skeletonCellChildren={customSkeleton}
          gridColumns={1}
        />
      );

      // 커스텀 스켈레톤 Div 구조가 사용됨
      const customWrapper = container.querySelectorAll('.custom-skeleton-wrapper');
      expect(customWrapper).toHaveLength(1);
    });

    it('showSkeleton={false}면 스켈레톤이 표시되지 않음', () => {
      const { container } = render(
        <CardGrid
          data={undefined}
          cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]}
          showSkeleton={false}
        />
      );

      // 스켈레톤 요소가 없음
      const skeletons = container.querySelectorAll('.animate-pulse');
      expect(skeletons).toHaveLength(0);

      // 빈 화면 (null 반환)
      expect(container.firstChild).toBeNull();
    });

    it('skeletonCount로 스켈레톤 개수 제어 가능', () => {
      const { container } = render(
        <CardGrid
          data={undefined}
          cardColumns={[{ id: 'test', cellChildren: mockCardChildren }]}
          gridColumns={3}
          skeletonCount={5}
        />
      );

      // skeletonCount에 지정된 개수만큼 생성 (border 클래스로 카드 컨테이너만 선택)
      const skeletonCards = container.querySelectorAll('.border.border-gray-200');
      expect(skeletonCards).toHaveLength(5);
    });

    it('iteration이 있는 요소는 1개만 표시됨', () => {
      const iterationChildren: CardGridCellChild[] = [
        {
          type: 'basic',
          name: 'Div',
          iteration: {
            source: '{{row.items}}',
            item_var: 'item',
          },
          children: [
            { type: 'basic', name: 'P', text: '{{item.name}}' },
          ],
        },
      ];

      const { container } = render(
        <CardGrid
          data={undefined}
          cardColumns={[{ id: 'test', cellChildren: iterationChildren }]}
          gridColumns={1}
        />
      );

      // iteration 요소가 1개만 생성됨
      const skeletonCards = container.querySelectorAll('.bg-white.dark\\:bg-gray-800');
      expect(skeletonCards).toHaveLength(1);
    });

    it('condition이 있는 요소는 스켈레톤에서 제외됨', () => {
      const conditionalChildren: CardGridCellChild[] = [
        { type: 'basic', name: 'H3', text: '항상 표시' },
        { type: 'basic', name: 'P', text: '조건부', condition: '{{row.isActive}}' },
        { type: 'basic', name: 'P', text: '조건부2', if: '{{row.status === "active"}}' },
      ];

      const { container } = render(
        <CardGrid
          data={undefined}
          cardColumns={[{ id: 'test', cellChildren: conditionalChildren }]}
          gridColumns={1}
        />
      );

      // H3만 스켈레톤 생성 (condition/if 요소는 제외)
      const skeletonBars = container.querySelectorAll('.h-4.bg-gray-200.dark\\:bg-gray-700.rounded.w-full.animate-pulse');
      expect(skeletonBars).toHaveLength(1);
    });

    it('Div는 구조를 유지하며 자식을 재귀적으로 처리함', () => {
      // 중첩된 Div 구조 (자식이 없는 Div는 기본 스켈레톤으로 변환됨)
      const nestedChildren: CardGridCellChild[] = [
        {
          type: 'basic',
          name: 'Div',
          props: { className: 'nested-container' },
          children: [
            {
              type: 'basic',
              name: 'Div',
              props: { className: 'nested-child-1' },
              children: [
                { type: 'basic', name: 'H3', text: '제목' },
              ],
            },
            {
              type: 'basic',
              name: 'Div',
              props: { className: 'nested-child-2' },
              children: [
                { type: 'basic', name: 'P', text: '설명' },
              ],
            },
          ],
        },
      ];

      const { container } = render(
        <CardGrid
          data={undefined}
          cardColumns={[{ id: 'test', cellChildren: nestedChildren }]}
          gridColumns={1}
        />
      );

      // 최상위 Div 구조가 유지됨
      const nestedContainer = container.querySelector('.nested-container');
      expect(nestedContainer).toBeInTheDocument();

      // 자식 Div 구조도 유지됨
      const nestedChild1 = container.querySelector('.nested-child-1');
      const nestedChild2 = container.querySelector('.nested-child-2');
      expect(nestedChild1).toBeInTheDocument();
      expect(nestedChild2).toBeInTheDocument();
    });

    it('cellChildren이 비어있으면 null을 반환함', () => {
      const { container } = render(
        <CardGrid
          data={undefined}
          cardColumns={[{ id: 'test', cellChildren: [] }]}
          gridColumns={1}
        />
      );

      // 스켈레톤 카드는 생성되지만 내부가 비어있음
      const skeletonCards = container.querySelectorAll('.bg-white.dark\\:bg-gray-800');
      expect(skeletonCards).toHaveLength(1);

      // 카드 내부에 스켈레톤 바가 없음
      const skeletonBars = skeletonCards[0].querySelectorAll('.h-4.bg-gray-200.dark\\:bg-gray-700.rounded.w-full.animate-pulse');
      expect(skeletonBars).toHaveLength(0);
    });
  });
});