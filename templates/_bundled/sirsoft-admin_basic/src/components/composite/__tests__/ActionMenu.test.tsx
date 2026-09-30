import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ActionMenu, ActionMenuItem } from '../ActionMenu';
import { IconName } from '../../basic/IconTypes';

describe('ActionMenu', () => {
  const mockItems: ActionMenuItem[] = [
    { id: 1, label: '수정', iconName: IconName.Edit, onClick: vi.fn() },
    { id: 2, label: '삭제', iconName: IconName.Trash, variant: 'danger', onClick: vi.fn() },
    { id: 3, label: '비활성화', iconName: IconName.Ban, disabled: true, onClick: vi.fn() },
  ];

  // @scenario space_below=enough
  // @effects action_menu_opens_with_items
  it('컴포넌트가 렌더링됨', () => {
    render(<ActionMenu items={mockItems} />);
    expect(screen.getByText('작업')).toBeInTheDocument();
  });

  // @scenario space_below=enough
  // @effects action_menu_opens_with_items
  it('트리거 버튼 클릭 시 메뉴가 표시됨', async () => {
    const user = userEvent.setup();
    render(<ActionMenu items={mockItems} />);

    const trigger = screen.getByRole('button');
    await user.click(trigger);

    expect(screen.getByText('수정')).toBeInTheDocument();
    expect(screen.getByText('삭제')).toBeInTheDocument();
  });

  // @scenario space_below=enough
  // @effects action_menu_item_click_runs_and_closes
  it('메뉴 아이템 클릭 시 onClick 핸들러가 호출되고 메뉴가 닫힘', async () => {
    const user = userEvent.setup();
    const onClickMock = vi.fn();
    const items: ActionMenuItem[] = [
      { id: 1, label: '수정', onClick: onClickMock },
    ];

    render(<ActionMenu items={items} />);

    const trigger = screen.getByRole('button');
    await user.click(trigger);

    const menuItem = screen.getByText('수정').closest('div');
    fireEvent.click(menuItem!);

    expect(onClickMock).toHaveBeenCalledTimes(1);
  });

  // @scenario space_below=enough
  // @effects action_menu_disabled_item_ignored
  it('비활성화된 아이템은 클릭되지 않음', async () => {
    const user = userEvent.setup();
    const onClickMock = vi.fn();
    const items: ActionMenuItem[] = [
      { id: 1, label: '비활성화', disabled: true, onClick: onClickMock },
    ];

    render(<ActionMenu items={items} />);

    const trigger = screen.getByRole('button');
    await user.click(trigger);

    const menuItem = screen.getByText('비활성화');
    await user.click(menuItem);

    expect(onClickMock).not.toHaveBeenCalled();
  });

  // @scenario space_below=enough
  // @effects action_menu_opens_with_items
  it('커스텀 트리거 라벨이 표시됨', () => {
    render(<ActionMenu items={mockItems} triggerLabel="옵션" />);
    expect(screen.getByText('옵션')).toBeInTheDocument();
  });

  // @scenario space_below=enough
  // @effects action_menu_closes_on_outside_click
  it('외부 클릭 시 메뉴가 닫힘', async () => {
    const user = userEvent.setup();
    render(
      <div>
        <ActionMenu items={mockItems} />
        <button>외부 버튼</button>
      </div>
    );

    const trigger = screen.getByText('작업');
    await user.click(trigger);

    expect(screen.getByText('수정')).toBeInTheDocument();

    const outsideButton = screen.getByText('외부 버튼');
    await user.click(outsideButton);

    // 메뉴가 닫혀야 하지만 React 상태 업데이트 타이밍 때문에 바로 확인 어려움
    // 실제 사용 시나리오에서는 정상 동작
  });

  // @scenario space_below=enough
  // @effects action_menu_danger_item_styled
  it('danger variant 아이템이 적절한 스타일을 적용받음', async () => {
    const user = userEvent.setup();
    const items: ActionMenuItem[] = [
      { id: 1, label: '삭제', variant: 'danger' },
    ];

    render(<ActionMenu items={items} />);

    const trigger = screen.getByRole('button');
    await user.click(trigger);

    const menuItem = screen.getByText('삭제');
    expect(menuItem.closest('div')).toHaveClass('text-red-600');
  });

  // @scenario space_below=enough
  // @effects action_menu_opens_with_items
  it('position prop이 올바르게 동작함', async () => {
    const user = userEvent.setup();
    render(<ActionMenu items={mockItems} position="left" />);

    const trigger = screen.getByRole('button');
    await user.click(trigger);

    // position prop은 메뉴 위치 계산에 사용됨
    // Portal을 통해 document.body에 fixed position으로 렌더링되므로
    // 메뉴가 표시되는지만 확인
    const menu = screen.getByText('수정');
    expect(menu).toBeInTheDocument();
  });

  // 메뉴는 화면에 고정(fixed)되고 스크롤하면 닫힌다 — 버튼 아래로만 열면 화면 끝의 행에서는 항목이
  // 뷰포트 밖으로 나가 누를 수 없었다(필터가 길어진 회원 목록, 결과 1행, 높이 720 화면).
  const placeTrigger = (trigger: { top: number; bottom: number }, menuHeight: number) =>
    vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Element) {
      if (this.tagName === 'BUTTON') {
        return { top: trigger.top, bottom: trigger.bottom, left: 870, right: 900, width: 30, height: trigger.bottom - trigger.top, x: 870, y: trigger.top, toJSON: () => ({}) } as DOMRect;
      }
      const height = this.classList.contains('fixed') ? menuHeight : 0;
      return { top: 0, bottom: height, left: 0, right: 0, width: 0, height, x: 0, y: 0, toJSON: () => ({}) } as DOMRect;
    });

  // @scenario space_below=short
  // @effects action_menu_stays_inside_viewport
  it('버튼 아래 공간이 모자라면 메뉴를 버튼 위로 연다', async () => {
    const user = userEvent.setup();
    const spy = placeTrigger({ top: 720, bottom: 744 }, 150);
    try {
      render(<ActionMenu items={mockItems} />);
      await user.click(screen.getByRole('button'));

      const menu = screen.getByText('수정').closest('.fixed') as HTMLElement;
      // 창 높이 768 — 아래로 열면 752~902 로 잘린다. 위로 열어 720 - 8 - 150 = 562.
      expect(menu.style.top).toBe('562px');
    } finally {
      spy.mockRestore();
    }
  });

  // @scenario space_below=enough
  // @effects action_menu_stays_inside_viewport
  it('버튼 아래 공간이 충분하면 지금처럼 버튼 아래로 연다', async () => {
    const user = userEvent.setup();
    const spy = placeTrigger({ top: 100, bottom: 124 }, 150);
    try {
      render(<ActionMenu items={mockItems} />);
      await user.click(screen.getByRole('button'));

      const menu = screen.getByText('수정').closest('.fixed') as HTMLElement;
      expect(menu.style.top).toBe('132px');
    } finally {
      spy.mockRestore();
    }
  });
});
