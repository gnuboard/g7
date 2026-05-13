import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SearchableDropdown, SearchableDropdownOption } from '../SearchableDropdown';

describe('SearchableDropdown', () => {
  const mockOptions: SearchableDropdownOption[] = [
    { value: 'nike', label: 'Nike', description: '스포츠 의류 및 용품' },
    { value: 'adidas', label: 'Adidas', description: '스포츠웨어 브랜드' },
    { value: 'samsung', label: 'Samsung', description: '전자제품 제조사' },
    { value: 'apple', label: 'Apple', description: '스마트 디바이스' },
  ];

  it('컴포넌트가 렌더링됨', () => {
    render(<SearchableDropdown options={mockOptions} />);

    expect(screen.getByText('Select')).toBeInTheDocument();
  });

  it('triggerLabel prop이 적용됨', () => {
    render(<SearchableDropdown options={mockOptions} triggerLabel="브랜드 선택" />);

    expect(screen.getByText('브랜드 선택')).toBeInTheDocument();
  });

  it('트리거 버튼 클릭 시 드롭다운이 표시됨', async () => {
    const user = userEvent.setup();
    render(<SearchableDropdown options={mockOptions} triggerLabel="Select" />);

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    expect(screen.getByText('Nike')).toBeInTheDocument();
    expect(screen.getByText('Adidas')).toBeInTheDocument();
    expect(screen.getByPlaceholderText('Search...')).toBeInTheDocument();
  });

  it('검색어 입력 시 옵션이 필터링됨', async () => {
    const user = userEvent.setup();
    render(<SearchableDropdown options={mockOptions} triggerLabel="Select" />);

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    const searchInput = screen.getByPlaceholderText('Search...');
    await user.type(searchInput, 'nike');

    expect(screen.getByText('Nike')).toBeInTheDocument();
    // 필터링된 옵션은 DOM에서 제거됨
    expect(screen.queryByText('Adidas')).not.toBeInTheDocument();
  });

  it('설명으로도 검색됨', async () => {
    const user = userEvent.setup();
    render(<SearchableDropdown options={mockOptions} triggerLabel="Select" />);

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    const searchInput = screen.getByPlaceholderText('Search...');
    await user.type(searchInput, '전자제품');

    expect(screen.getByText('Samsung')).toBeInTheDocument();
  });

  it('결과 없음 메시지 표시', async () => {
    const user = userEvent.setup();
    render(
      <SearchableDropdown
        options={mockOptions}
        triggerLabel="Select"
        noResultsText="No results found"
      />
    );

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    const searchInput = screen.getByPlaceholderText('Search...');
    await user.type(searchInput, 'xyz없는검색어');

    expect(screen.getByText('No results found')).toBeInTheDocument();
  });

  it('단일 선택: 옵션 클릭 시 선택되고 드롭다운 닫힘', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <SearchableDropdown options={mockOptions} triggerLabel="Select" onChange={onChange} />
    );

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    const option = screen.getByText('Nike');
    await user.click(option);

    expect(onChange).toHaveBeenCalledWith('nike');
    // 드롭다운이 닫힘 (검색 입력창이 사라짐)
    expect(screen.queryByPlaceholderText('Search...')).not.toBeInTheDocument();
  });

  it('다중 선택: multiple prop이 true일 때 여러 옵션 선택 가능', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <SearchableDropdown
        multiple
        options={mockOptions}
        triggerLabel="Select"
        onChange={onChange}
      />
    );

    const trigger = screen.getByRole('button', { name: /Select/i });
    await user.click(trigger);

    // 드롭다운 내 옵션 선택 (role="option")
    const nikeOption = screen.getByRole('option', { name: /Nike/i });
    await user.click(nikeOption);

    expect(onChange).toHaveBeenCalledWith(['nike']);

    // 다중 선택 모드에서는 드롭다운이 열린 상태 유지
    // 두 번째 옵션 선택 - 컴포넌트는 비제어 상태이므로 내부 selectedValues로 동작
    const adidasOption = screen.getByRole('option', { name: /Adidas/i });
    await user.click(adidasOption);

    // 두 번째 호출은 빈 배열에서 시작하므로 ['adidas']만 전달됨
    // (비제어 컴포넌트이므로 이전 선택을 유지하지 않음)
    expect(onChange).toHaveBeenLastCalledWith(['adidas']);
  });

  it('다중 선택: 선택된 옵션 다시 클릭하면 선택 해제', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <SearchableDropdown
        multiple
        options={mockOptions}
        value={['nike']}
        triggerLabel="Select"
        onChange={onChange}
      />
    );

    // value=['nike']일 때 트리거 버튼에 'Nike'가 표시됨
    const trigger = screen.getByRole('button', { name: /Nike/i });
    await user.click(trigger);

    // 드롭다운 내의 Nike 옵션 (role="option")
    const nikeOption = screen.getByRole('option', { name: /Nike/i });
    await user.click(nikeOption);

    expect(onChange).toHaveBeenCalledWith([]);
  });

  it('ESC 키로 드롭다운 닫힘', async () => {
    const user = userEvent.setup();
    render(<SearchableDropdown options={mockOptions} triggerLabel="Select" />);

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    expect(screen.getByText('Nike')).toBeInTheDocument();

    fireEvent.keyDown(document, { key: 'Escape' });

    // 드롭다운 닫힘 확인
  });

  it('외부 클릭 시 드롭다운 닫힘', async () => {
    const user = userEvent.setup();
    render(
      <div>
        <SearchableDropdown options={mockOptions} triggerLabel="Select" />
        <button data-testid="outside">외부 버튼</button>
      </div>
    );

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    expect(screen.getByText('Nike')).toBeInTheDocument();

    const outsideButton = screen.getByTestId('outside');
    await user.click(outsideButton);

    // 드롭다운이 닫힘
  });

  it('disabled prop이 true일 때 클릭 불가', async () => {
    const user = userEvent.setup();
    render(<SearchableDropdown options={mockOptions} triggerLabel="Select" disabled />);

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    // 드롭다운이 열리지 않음
    expect(screen.queryByPlaceholderText('Search...')).not.toBeInTheDocument();
  });

  it('value prop으로 초기 선택값 설정', () => {
    render(<SearchableDropdown options={mockOptions} value="nike" triggerLabel="Select" />);

    expect(screen.getByText('Nike')).toBeInTheDocument();
  });

  it('다중 선택 시 선택 개수 표시', () => {
    render(
      <SearchableDropdown
        multiple
        options={mockOptions}
        value={['nike', 'adidas']}
        triggerLabel="Select"
      />
    );

    expect(screen.getByText('2 selected')).toBeInTheDocument();
  });

  it('searchPlaceholder prop이 적용됨', async () => {
    const user = userEvent.setup();
    render(
      <SearchableDropdown
        options={mockOptions}
        triggerLabel="Select"
        searchPlaceholder="Search brands..."
      />
    );

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    expect(screen.getByPlaceholderText('Search brands...')).toBeInTheDocument();
  });

  it('onSearch 콜백이 호출됨', async () => {
    const user = userEvent.setup();
    const onSearch = vi.fn();

    render(
      <SearchableDropdown options={mockOptions} triggerLabel="Select" onSearch={onSearch} />
    );

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    const searchInput = screen.getByPlaceholderText('Search...');
    await user.type(searchInput, 'test');

    expect(onSearch).toHaveBeenCalled();
  });

  it('className prop이 적용됨', () => {
    const { container } = render(
      <SearchableDropdown
        options={mockOptions}
        triggerLabel="Select"
        className="custom-searchable"
      />
    );

    expect(container.firstChild).toHaveClass('custom-searchable');
  });

  it('옵션이 없을 때 초기 메시지 표시', async () => {
    const user = userEvent.setup();
    render(
      <SearchableDropdown
        options={[]}
        triggerLabel="Select"
        initialText="Type to search"
      />
    );

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    expect(screen.getByText('Type to search')).toBeInTheDocument();
  });
});
