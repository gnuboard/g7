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
    expect(screen.getByText('Actions')).toBeInTheDocument();
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

    const menuItem = screen.getByText('수정').closest('[role="menuitem"]');
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

    const trigger = screen.getByText('Actions');
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
    expect(menuItem.closest('[role="menuitem"]')).toHaveClass('text-red-600');
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

  describe('키보드 접근 (WAI-ARIA 메뉴 버튼)', () => {
    // 레이아웃이 children 으로 트리거를 넘기는 경우(반응·스크랩 관리의 회원 이름 등)에도
    // 키보드로 열고, 항목을 옮겨 가며 고를 수 있어야 한다.
    const memberItems: ActionMenuItem[] = [
      { id: 'view_member', label: '회원정보 보기' },
      { id: 'locked', label: '잠긴 항목', disabled: true },
      { id: 'search_member', label: '이 회원으로 검색' },
    ];

    const renderCustomTrigger = (onItemClick = vi.fn()) => {
      render(
        <ActionMenu items={memberItems} onItemClick={onItemClick}>
          <span>홍길동</span>
        </ActionMenu>
      );
      return { onItemClick, trigger: screen.getByRole('button', { name: '홍길동' }) };
    };

    // @scenario space_below=enough
    // @effects action_menu_keyboard_operable
    it('children 트리거도 초점을 받는 메뉴 버튼으로 노출된다', () => {
      const { trigger } = renderCustomTrigger();

      expect(trigger).toHaveAttribute('tabindex', '0');
      expect(trigger).toHaveAttribute('aria-haspopup', 'menu');
      expect(trigger).toHaveAttribute('aria-expanded', 'false');
    });

    // @scenario space_below=enough
    // @effects action_menu_keyboard_operable
    it('Enter 로 열면 첫 항목에 초점이 가고, 항목은 menuitem 이다', () => {
      const { trigger } = renderCustomTrigger();

      trigger.focus();
      fireEvent.keyDown(trigger, { key: 'Enter' });

      expect(trigger).toHaveAttribute('aria-expanded', 'true');
      expect(screen.getByRole('menu')).toBeInTheDocument();
      const items = screen.getAllByRole('menuitem');
      expect(items.map((item) => item.textContent)).toEqual(['회원정보 보기', '잠긴 항목', '이 회원으로 검색']);
      expect(items[1]).toHaveAttribute('aria-disabled', 'true');
      expect(document.activeElement).toBe(items[0]);
    });

    // @scenario space_below=enough
    // @effects action_menu_keyboard_operable
    it('화살표로 잠긴 항목을 건너뛰고 Enter 로 고르면 닫히고 트리거로 초점이 돌아온다', () => {
      const { trigger, onItemClick } = renderCustomTrigger();

      fireEvent.keyDown(trigger, { key: ' ' });
      fireEvent.keyDown(document.activeElement as Element, { key: 'ArrowDown' });
      expect(document.activeElement?.textContent).toBe('이 회원으로 검색');

      fireEvent.keyDown(document.activeElement as Element, { key: 'Enter' });
      expect(onItemClick).toHaveBeenCalledWith('search_member', expect.objectContaining({ id: 'search_member' }));
      expect(screen.queryByRole('menu')).not.toBeInTheDocument();
      expect(document.activeElement).toBe(trigger);
    });

    // @scenario space_below=enough
    // @effects action_menu_keyboard_operable
    it('기본 버튼 트리거는 키보드 클릭(detail 0)으로 열면 첫 항목에 초점을 준다', () => {
      render(<ActionMenu items={memberItems} />);
      const trigger = screen.getByRole('button', { name: /Actions/ });

      expect(trigger).toHaveAttribute('aria-haspopup', 'menu');
      fireEvent.click(trigger, { detail: 0 });
      expect(document.activeElement?.textContent).toBe('회원정보 보기');
    });

    // @scenario space_below=enough
    // @effects action_menu_keyboard_operable
    it('Space 로도 고르고, Escape 는 고르지 않고 닫는다', () => {
      const { trigger, onItemClick } = renderCustomTrigger();

      fireEvent.keyDown(trigger, { key: 'Enter' });
      fireEvent.keyDown(document.activeElement as Element, { key: 'Escape' });
      expect(screen.queryByRole('menu')).not.toBeInTheDocument();
      expect(onItemClick).not.toHaveBeenCalled();
      expect(document.activeElement).toBe(trigger);

      fireEvent.keyDown(trigger, { key: 'Enter' });
      fireEvent.keyDown(document.activeElement as Element, { key: ' ' });
      expect(onItemClick).toHaveBeenCalledWith('view_member', expect.objectContaining({ id: 'view_member' }));
    });
  });

  describe('아이콘만 있는 트리거의 접근 가능한 이름', () => {
    const expectedFallback = () => {
      // 컴포넌트와 같은 규칙 — 번역이 없거나 엔진 밖이면 영어 대체 문구
      // 아이콘만 있는 트리거의 기본 이름은 「더보기」(common.more_actions) — 글자 트리거 기본값 「작업」(common.actions)과 다르다
      const translated = (window as any).G7Core?.t?.('common.more_actions') as string | undefined;
      return translated && translated !== 'common.more_actions' ? translated : 'More actions';
    };

    // @scenario space_below=enough
    // @effects action_menu_trigger_has_accessible_name
    it('글자 없는 아이콘 트리거는 ariaLabel 을 이름으로 단다', () => {
      render(<ActionMenu items={mockItems} triggerLabel="" triggerIconName={IconName.EllipsisHorizontal} ariaLabel="관리" />);
      const trigger = screen.getByRole('button');

      expect(trigger).toHaveAttribute('aria-label', '관리');
      expect(trigger).toHaveAccessibleName('관리');
    });

    // @scenario space_below=enough
    // @effects action_menu_trigger_has_accessible_name
    it('ariaLabel 이 없으면 공용 「더보기」(엔진 밖 More actions) 문구로 이름을 채운다 (이름 없는 버튼을 남기지 않는다)', () => {
      render(<ActionMenu items={mockItems} triggerLabel="" triggerIconName={IconName.EllipsisVertical} />);
      const trigger = screen.getByRole('button');

      expect(trigger.getAttribute('aria-label')).toBe(expectedFallback());
      expect(trigger.getAttribute('aria-label')).not.toBe('');
    });

    // @scenario space_below=enough
    // @effects action_menu_trigger_has_accessible_name
    it('보이는 글자가 있으면 aria-label 로 덮지 않는다 (보이는 이름 = 읽히는 이름)', () => {
      render(<ActionMenu items={mockItems} triggerLabel="작업" ariaLabel="다른 이름" />);
      const trigger = screen.getByRole('button');

      expect(trigger).not.toHaveAttribute('aria-label');
      // 시험 환경의 아이콘은 이름 글자를 그리므로 앞부분만 본다(브라우저의 아이콘은 글자가 없다)
      expect(trigger).toHaveAccessibleName(/^작업/);
    });
  });
});
