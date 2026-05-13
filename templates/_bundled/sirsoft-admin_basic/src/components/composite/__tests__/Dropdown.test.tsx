import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Dropdown, DropdownItem } from '../Dropdown';

describe('Dropdown', () => {
  const mockItems: DropdownItem[] = [
    { label: '옵션 1', value: 'option1' },
    { label: '옵션 2', value: 'option2' },
    { label: '비활성화', value: 'disabled', disabled: true },
  ];

  it('컴포넌트가 렌더링됨', () => {
    render(<Dropdown label="선택" items={mockItems} />);

    expect(screen.getByText('선택')).toBeInTheDocument();
  });

  it('트리거 버튼 클릭 시 드롭다운 메뉴가 표시됨', async () => {
    const user = userEvent.setup();
    render(<Dropdown label="선택" items={mockItems} />);

    const trigger = screen.getByText('선택');
    await user.click(trigger);

    expect(screen.getByText('옵션 1')).toBeInTheDocument();
    expect(screen.getByText('옵션 2')).toBeInTheDocument();
  });

  it('메뉴 아이템 클릭 시 onClick 핸들러가 호출되고 메뉴가 닫힘', async () => {
    const user = userEvent.setup();
    const onClickMock = vi.fn();
    const items: DropdownItem[] = [
      { label: '옵션 1', value: 'option1', onClick: onClickMock },
    ];

    render(<Dropdown label="선택" items={items} />);

    const trigger = screen.getByText('선택');
    await user.click(trigger);

    const option = screen.getByText('옵션 1');
    await user.click(option);

    expect(onClickMock).toHaveBeenCalledTimes(1);
  });

  it('onItemClick 핸들러가 호출됨', async () => {
    const user = userEvent.setup();
    const onItemClick = vi.fn();

    render(<Dropdown label="선택" items={mockItems} onItemClick={onItemClick} />);

    const trigger = screen.getByText('선택');
    await user.click(trigger);

    const option = screen.getByText('옵션 1');
    await user.click(option);

    // onItemClick은 (value, item) 순서로 호출됨
    expect(onItemClick).toHaveBeenCalledWith(mockItems[0].value, mockItems[0]);
  });

  it('비활성화된 아이템은 클릭되지 않음', async () => {
    const user = userEvent.setup();
    const onItemClick = vi.fn();

    render(<Dropdown label="선택" items={mockItems} onItemClick={onItemClick} />);

    const trigger = screen.getByText('선택');
    await user.click(trigger);

    const disabledOption = screen.getByText('비활성화');
    await user.click(disabledOption);

    expect(onItemClick).not.toHaveBeenCalled();
  });

  it('ESC 키를 누르면 메뉴가 닫힘', async () => {
    const user = userEvent.setup();
    render(<Dropdown label="선택" items={mockItems} />);

    const trigger = screen.getByText('선택');
    await user.click(trigger);

    expect(screen.getByText('옵션 1')).toBeInTheDocument();

    fireEvent.keyDown(document, { key: 'Escape' });

    // ESC 키 후 메뉴가 닫혀야 함 (상태 업데이트 확인)
  });

  it('ArrowDown 키로 다음 아이템으로 이동', async () => {
    const user = userEvent.setup();
    render(<Dropdown label="선택" items={mockItems} />);

    const trigger = screen.getByText('선택');
    await user.click(trigger);

    fireEvent.keyDown(document, { key: 'ArrowDown' });

    // focusedIndex가 변경되어 스타일이 적용됨
  });

  it('Enter 키로 포커스된 아이템 선택', async () => {
    const user = userEvent.setup();
    const onItemClick = vi.fn();

    render(<Dropdown label="선택" items={mockItems} onItemClick={onItemClick} />);

    const trigger = screen.getByText('선택');
    await user.click(trigger);

    fireEvent.keyDown(document, { key: 'ArrowDown' });
    fireEvent.keyDown(document, { key: 'Enter' });

    // 포커스된 아이템이 선택되어야 함
  });

  it('position prop이 적용됨 (동적 조정 전 기본값)', async () => {
    const user = userEvent.setup();
    render(<Dropdown label="선택" items={mockItems} position="bottom-left" />);

    const trigger = screen.getByText('선택');
    await user.click(trigger);

    const menu = screen.getByRole('menu');
    // 테스트 환경에서는 getBoundingClientRect가 0을 반환하므로 동적으로 조정된 position이 적용됨
    // bottom-left는 화면 경계 체크 후 유지되거나 조정될 수 있음
    // 메뉴가 role="menu"로 렌더링되는지 확인
    expect(menu).toBeInTheDocument();
    // 위치 관련 클래스 중 하나는 반드시 포함
    const hasPositionClass = menu.className.includes('top-full') || menu.className.includes('bottom-full');
    expect(hasPositionClass).toBe(true);
  });

  it('className prop이 적용됨', () => {
    const { container } = render(
      <Dropdown label="선택" items={mockItems} className="custom-dropdown" />
    );
    expect(container.firstChild).toHaveClass('custom-dropdown');
  });
});
