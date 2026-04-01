import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { FilterGroup, Filter } from '../FilterGroup';

describe('FilterGroup', () => {
  const mockFilters: Filter[] = [
    {
      id: 'category',
      label: '카테고리',
      type: 'select',
      options: [
        { id: 1, label: '전자기기', value: 'electronics' },
        { id: 2, label: '의류', value: 'clothing' },
      ],
    },
    {
      id: 'status',
      label: '상태',
      type: 'checkbox',
      options: [
        { id: 1, label: '활성', value: 'active' },
        { id: 2, label: '비활성', value: 'inactive' },
      ],
    },
  ];

  it('컴포넌트가 렌더링됨', () => {
    render(<FilterGroup filters={mockFilters} />);

    expect(screen.getByText('필터')).toBeInTheDocument();
    expect(screen.getByText('카테고리')).toBeInTheDocument();
    expect(screen.getByText('상태')).toBeInTheDocument();
  });

  it('커스텀 title이 표시됨', () => {
    render(<FilterGroup title="고급 필터" filters={mockFilters} />);

    expect(screen.getByText('고급 필터')).toBeInTheDocument();
  });

  it('Select 타입 필터가 표시됨', () => {
    const { container } = render(<FilterGroup filters={mockFilters} />);

    expect(screen.getByText('카테고리')).toBeInTheDocument();
    const select = container.querySelector('select');
    expect(select).toBeInTheDocument();
    expect(select?.tagName).toBe('SELECT');
  });

  it('Checkbox 타입 필터가 표시됨', () => {
    render(<FilterGroup filters={mockFilters} />);

    expect(screen.getByText('활성')).toBeInTheDocument();
    expect(screen.getByText('비활성')).toBeInTheDocument();
  });

  it('Select 변경 시 onChange 핸들러가 호출됨', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    const { container } = render(<FilterGroup filters={mockFilters} onChange={onChange} />);

    const select = container.querySelector('select');
    await user.selectOptions(select!, 'electronics');

    expect(onChange).toHaveBeenCalledWith('category', 'electronics');
  });

  it('Checkbox 변경 시 onChange 핸들러가 호출됨', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    const { container } = render(<FilterGroup filters={mockFilters} onChange={onChange} />);

    const checkboxes = container.querySelectorAll('input[type="checkbox"]');
    await user.click(checkboxes[0]);

    expect(onChange).toHaveBeenCalledWith('status', ['active']);
  });

  it('초기화 버튼을 클릭하면 onReset이 호출됨', async () => {
    const user = userEvent.setup();
    const onReset = vi.fn();

    render(<FilterGroup filters={mockFilters} onReset={onReset} />);

    const resetButton = screen.getByText('초기화');
    await user.click(resetButton);

    expect(onReset).toHaveBeenCalledTimes(1);
  });

  it('showResetButton이 false면 초기화 버튼이 표시되지 않음', () => {
    render(
      <FilterGroup
        filters={mockFilters}
        onReset={vi.fn()}
        showResetButton={false}
      />
    );

    expect(screen.queryByText('초기화')).not.toBeInTheDocument();
  });

  it('여러 checkbox 선택 시 배열로 값이 전달됨', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    const filtersWithValues: Filter[] = [
      {
        id: 'status',
        label: '상태',
        type: 'checkbox',
        options: [
          { id: 1, label: '활성', value: 'active' },
          { id: 2, label: '비활성', value: 'inactive' },
        ],
        value: ['active'],
      },
    ];

    const { container } = render(<FilterGroup filters={filtersWithValues} onChange={onChange} />);

    const checkboxes = container.querySelectorAll('input[type="checkbox"]');
    await user.click(checkboxes[1]); // 두 번째 checkbox (비활성)

    expect(onChange).toHaveBeenCalledWith('status', ['active', 'inactive']);
  });

  it('선택된 checkbox를 다시 클릭하면 선택 해제됨', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    const filtersWithValues: Filter[] = [
      {
        id: 'status',
        label: '상태',
        type: 'checkbox',
        options: [
          { id: 1, label: '활성', value: 'active' },
        ],
        value: ['active'],
      },
    ];

    const { container } = render(<FilterGroup filters={filtersWithValues} onChange={onChange} />);

    const checkbox = container.querySelector('input[type="checkbox"]');
    await user.click(checkbox!);

    expect(onChange).toHaveBeenCalledWith('status', []);
  });

  it('className prop이 적용됨', () => {
    const { container } = render(
      <FilterGroup filters={mockFilters} className="custom-filter" />
    );
    expect(container.firstChild).toHaveClass('custom-filter');
  });
});
