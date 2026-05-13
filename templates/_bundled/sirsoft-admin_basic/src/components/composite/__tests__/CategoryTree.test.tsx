import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { CategoryTree, CategoryNode } from '../CategoryTree';

const mockTreeData: CategoryNode[] = [
  {
    id: 1,
    name: 'Electronics',
    localized_name: '전자기기',
    products_count: 42,
    children: [
      {
        id: 2,
        name: 'Phones',
        localized_name: '스마트폰',
        products_count: 20,
        children: [],
      },
      {
        id: 3,
        name: 'Laptops',
        localized_name: '노트북',
        products_count: 15,
        children: [],
      },
    ],
  },
  {
    id: 4,
    name: 'Clothing',
    localized_name: '의류',
    products_count: 30,
    children: [],
  },
];

describe('CategoryTree', () => {
  it('트리 데이터가 렌더링됨', () => {
    render(<CategoryTree data={mockTreeData} />);

    expect(screen.getByTestId('category-node-1')).toBeInTheDocument();
    expect(screen.getByText('전자기기')).toBeInTheDocument();
    expect(screen.getByText('의류')).toBeInTheDocument();
  });

  it('빈 데이터일 때 빈 상태 메시지 표시', () => {
    render(<CategoryTree data={[]} />);

    expect(screen.getByText('데이터가 없습니다.')).toBeInTheDocument();
  });

  it('data 미전달 시 빈 상태 메시지 표시', () => {
    render(<CategoryTree />);

    expect(screen.getByText('데이터가 없습니다.')).toBeInTheDocument();
  });

  it('localized_name이 없으면 name으로 표시', () => {
    const data: CategoryNode[] = [
      { id: 1, name: 'Fallback Name', children: [] },
    ];
    render(<CategoryTree data={data} />);

    expect(screen.getByText('Fallback Name')).toBeInTheDocument();
  });

  it('자식 노드가 있으면 토글 버튼 표시', () => {
    render(<CategoryTree data={mockTreeData} />);

    // 전자기기(id=1)는 자식이 있으므로 토글 버튼 존재
    expect(screen.getByTestId('toggle-1')).toBeInTheDocument();
    // 의류(id=4)는 자식이 없으므로 토글 버튼 없음
    expect(screen.queryByTestId('toggle-4')).not.toBeInTheDocument();
  });

  it('접힌 상태에서 자식 노드가 보이지 않음', () => {
    render(<CategoryTree data={mockTreeData} expandedIds={[]} />);

    // 자식 노드 컨테이너가 없어야 함
    expect(screen.queryByTestId('children-1')).not.toBeInTheDocument();
    expect(screen.queryByText('스마트폰')).not.toBeInTheDocument();
  });

  it('펼친 상태에서 자식 노드가 보임', () => {
    render(<CategoryTree data={mockTreeData} expandedIds={[1]} />);

    expect(screen.getByTestId('children-1')).toBeInTheDocument();
    expect(screen.getByText('스마트폰')).toBeInTheDocument();
    expect(screen.getByText('노트북')).toBeInTheDocument();
  });

  it('토글 클릭 시 onToggle 콜백 호출 (펼치기)', async () => {
    const user = userEvent.setup();
    const onToggle = vi.fn();

    render(
      <CategoryTree data={mockTreeData} expandedIds={[]} onToggle={onToggle} />
    );

    await user.click(screen.getByTestId('toggle-1'));

    expect(onToggle).toHaveBeenCalledWith([1]);
  });

  it('토글 클릭 시 onToggle 콜백 호출 (접기)', async () => {
    const user = userEvent.setup();
    const onToggle = vi.fn();

    render(
      <CategoryTree
        data={mockTreeData}
        expandedIds={[1]}
        onToggle={onToggle}
      />
    );

    await user.click(screen.getByTestId('toggle-1'));

    expect(onToggle).toHaveBeenCalledWith([]);
  });

  it('카테고리 이름 클릭 시 토글 (자식 있는 경우)', async () => {
    const user = userEvent.setup();
    const onToggle = vi.fn();

    render(
      <CategoryTree data={mockTreeData} expandedIds={[]} onToggle={onToggle} />
    );

    await user.click(screen.getByText('전자기기'));

    expect(onToggle).toHaveBeenCalledWith([1]);
  });

  it('selectable=true일 때 체크박스 표시', () => {
    render(<CategoryTree data={mockTreeData} selectable />);

    expect(screen.getByTestId('checkbox-1')).toBeInTheDocument();
    expect(screen.getByTestId('checkbox-4')).toBeInTheDocument();
  });

  it('selectable=false일 때 체크박스 미표시', () => {
    render(<CategoryTree data={mockTreeData} />);

    expect(screen.queryByTestId('checkbox-1')).not.toBeInTheDocument();
  });

  it('체크박스 클릭 시 onSelectionChange 콜백 호출 (선택)', async () => {
    const user = userEvent.setup();
    const onSelectionChange = vi.fn();

    render(
      <CategoryTree
        data={mockTreeData}
        selectable
        selectedIds={[]}
        onSelectionChange={onSelectionChange}
      />
    );

    const checkbox = screen.getByTestId('checkbox-1');
    await user.click(checkbox);

    expect(onSelectionChange).toHaveBeenCalledWith([1]);
  });

  it('체크박스 클릭 시 onSelectionChange 콜백 호출 (선택 해제)', async () => {
    const user = userEvent.setup();
    const onSelectionChange = vi.fn();

    render(
      <CategoryTree
        data={mockTreeData}
        selectable
        selectedIds={[1, 4]}
        onSelectionChange={onSelectionChange}
      />
    );

    const checkbox = screen.getByTestId('checkbox-1');
    await user.click(checkbox);

    expect(onSelectionChange).toHaveBeenCalledWith([4]);
  });

  it('showProductCount=true일 때 상품 수 표시', () => {
    render(<CategoryTree data={mockTreeData} showProductCount />);

    expect(screen.getByText('42')).toBeInTheDocument();
    expect(screen.getByText('30')).toBeInTheDocument();
  });

  it('showProductCount=false일 때 상품 수 미표시', () => {
    render(<CategoryTree data={mockTreeData} />);

    expect(screen.queryByText('42')).not.toBeInTheDocument();
  });

  it('searchKeyword로 매칭되는 텍스트가 하이라이트됨', () => {
    const { container } = render(
      <CategoryTree data={mockTreeData} searchKeyword="전자" />
    );

    // 하이라이트된 span이 bg-yellow-200 클래스를 가짐
    const highlighted = container.querySelector('.bg-yellow-200');
    expect(highlighted).toBeInTheDocument();
    expect(highlighted?.textContent).toBe('전자');
  });

  it('searchKeyword로 매칭되지 않는 노드가 숨겨짐', () => {
    render(<CategoryTree data={mockTreeData} searchKeyword="스마트폰" />);

    // 스마트폰은 전자기기의 자식이므로, 전자기기 노드는 보임 (서브트리에 매칭)
    expect(screen.getByTestId('category-node-1')).toBeInTheDocument();
    // 의류는 매칭되지 않으므로 숨김
    expect(screen.queryByTestId('category-node-4')).not.toBeInTheDocument();
  });

  it('className prop이 적용됨', () => {
    const { container } = render(
      <CategoryTree data={mockTreeData} className="custom-class" />
    );

    expect(container.firstElementChild).toHaveClass('custom-class');
  });
});
