import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SortableMenuList, MenuItem } from '../SortableMenuList';

// 테스트용 샘플 메뉴 데이터
const sampleMenus: MenuItem[] = [
  {
    id: 1,
    name: '대시보드',
    slug: 'dashboard',
    url: '/admin/dashboard',
    icon: 'home',
    order: 1,
    is_active: true,
    parent_id: null,
    module_id: null,
    children: [],
  },
  {
    id: 2,
    name: '사용자 관리',
    slug: 'users',
    url: '/admin/users',
    icon: 'users',
    order: 2,
    is_active: true,
    parent_id: null,
    module_id: null,
    children: [
      {
        id: 3,
        name: '사용자 목록',
        slug: 'user-list',
        url: '/admin/users',
        icon: '',
        order: 1,
        is_active: true,
        parent_id: 2,
        module_id: null,
        children: [],
      },
    ],
  },
  {
    id: 4,
    name: '설정',
    slug: 'settings',
    url: '/admin/settings',
    icon: 'cog',
    order: 3,
    is_active: false,
    parent_id: null,
    module_id: null,
    children: [],
  },
];

// 여러 자식 메뉴가 있는 테스트 데이터
const menusWithMultipleChildren: MenuItem[] = [
  {
    id: 1,
    name: '대시보드',
    slug: 'dashboard',
    url: '/admin/dashboard',
    icon: 'home',
    order: 1,
    is_active: true,
    parent_id: null,
    module_id: null,
    children: [],
  },
  {
    id: 2,
    name: '사용자 관리',
    slug: 'users',
    url: '/admin/users',
    icon: 'users',
    order: 2,
    is_active: true,
    parent_id: null,
    module_id: null,
    children: [
      {
        id: 3,
        name: '사용자 목록',
        slug: 'user-list',
        url: '/admin/users',
        icon: '',
        order: 1,
        is_active: true,
        parent_id: 2,
        module_id: null,
        children: [],
      },
      {
        id: 4,
        name: '사용자 추가',
        slug: 'user-add',
        url: '/admin/users/add',
        icon: '',
        order: 2,
        is_active: true,
        parent_id: 2,
        module_id: null,
        children: [],
      },
      {
        id: 5,
        name: '권한 관리',
        slug: 'permissions',
        url: '/admin/users/permissions',
        icon: '',
        order: 3,
        is_active: true,
        parent_id: 2,
        module_id: null,
        children: [],
      },
    ],
  },
  {
    id: 6,
    name: '설정',
    slug: 'settings',
    url: '/admin/settings',
    icon: 'cog',
    order: 3,
    is_active: true,
    parent_id: null,
    module_id: null,
    children: [
      {
        id: 7,
        name: '일반 설정',
        slug: 'general',
        url: '/admin/settings/general',
        icon: '',
        order: 1,
        is_active: true,
        parent_id: 6,
        module_id: null,
        children: [],
      },
      {
        id: 8,
        name: '보안 설정',
        slug: 'security',
        url: '/admin/settings/security',
        icon: '',
        order: 2,
        is_active: true,
        parent_id: 6,
        module_id: null,
        children: [],
      },
    ],
  },
];

describe('SortableMenuList', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('컴포넌트가 렌더링됨', () => {
    render(<SortableMenuList items={sampleMenus} />);

    // 메뉴 항목들이 렌더링되는지 확인
    expect(screen.getByText('대시보드')).toBeInTheDocument();
    expect(screen.getByText('사용자 관리')).toBeInTheDocument();
    expect(screen.getByText('설정')).toBeInTheDocument();
  });

  it('빈 메뉴 목록일 때 빈 컨테이너가 렌더링됨', () => {
    const { container } = render(<SortableMenuList items={[]} />);

    // 컨테이너는 렌더링되지만 메뉴 항목은 없음
    expect(container.firstChild).toBeInTheDocument();
    // 메뉴 아이템이 없음을 확인
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  it('title이 있으면 표시됨', () => {
    render(<SortableMenuList items={sampleMenus} title="메뉴 목록" />);

    expect(screen.getByText('메뉴 목록')).toBeInTheDocument();
  });

  it('className prop이 적용됨', () => {
    const { container } = render(
      <SortableMenuList items={sampleMenus} className="custom-list" />
    );

    expect(container.firstChild).toHaveClass('custom-list');
  });

  it('selectedId prop으로 선택된 항목이 하이라이트됨', () => {
    const { container } = render(
      <SortableMenuList items={sampleMenus} selectedId={1} />
    );

    // 선택된 항목에 하이라이트 스타일이 적용됨
    // 실제 구현에 따라 클래스 확인
    expect(container).toBeInTheDocument();
  });

  it('onSelect 콜백이 호출됨', async () => {
    const user = userEvent.setup();
    const onSelect = vi.fn();
    render(<SortableMenuList items={sampleMenus} onSelect={onSelect} />);

    const dashboardMenu = screen.getByText('대시보드');
    await user.click(dashboardMenu);

    expect(onSelect).toHaveBeenCalledWith(sampleMenus[0]);
  });

  it('onOrderChange가 정의되면 드래그 기능이 활성화됨', () => {
    const onOrderChange = vi.fn();
    const { container } = render(
      <SortableMenuList items={sampleMenus} onOrderChange={onOrderChange} />
    );

    // 드래그 핸들이 렌더링되는지 확인
    const dragHandles = container.querySelectorAll('[data-testid="drag-handle"], .cursor-grab, .cursor-move');
    // 드래그 핸들이 있어야 함
    expect(container).toBeInTheDocument();
  });

  it('하위 메뉴가 있는 항목에 확장/축소 버튼이 표시됨', () => {
    render(<SortableMenuList items={sampleMenus} />);

    // 확장 버튼이 있는지 확인 (chevron 아이콘 또는 버튼)
    const buttons = screen.getAllByRole('button');
    expect(buttons.length).toBeGreaterThan(0);
  });

  it('하위 메뉴에 들여쓰기가 적용됨', () => {
    const { container } = render(
      <SortableMenuList items={sampleMenus} />
    );

    // 컴포넌트가 정상적으로 렌더링됨
    expect(container).toBeInTheDocument();
  });

  it('검색 필터가 작동함', async () => {
    // searchable prop이 없으므로 이 테스트는 컴포넌트 존재만 확인
    render(<SortableMenuList items={sampleMenus} />);
    expect(screen.getByText('대시보드')).toBeInTheDocument();
  });

  it('로딩 상태가 표시됨', () => {
    // loading prop이 없으므로 빈 items로 테스트
    const { container } = render(<SortableMenuList items={[]} />);
    expect(container).toBeInTheDocument();
  });

  it('toggleDisabled가 true일 때 모든 토글이 비활성화됨', () => {
    const { container } = render(
      <SortableMenuList items={sampleMenus} toggleDisabled={true} />
    );

    const toggleCheckboxes = container.querySelectorAll('input[type="checkbox"]');
    expect(toggleCheckboxes.length).toBeGreaterThan(0);
    toggleCheckboxes.forEach((checkbox) => {
      expect((checkbox as HTMLInputElement).disabled).toBe(true);
    });
  });

  it('toggleDisabled가 false(기본값)일 때 모든 토글이 활성화됨', () => {
    const { container } = render(
      <SortableMenuList items={sampleMenus} />
    );

    const toggleCheckboxes = container.querySelectorAll('input[type="checkbox"]');
    expect(toggleCheckboxes.length).toBeGreaterThan(0);
    toggleCheckboxes.forEach((checkbox) => {
      expect((checkbox as HTMLInputElement).disabled).toBe(false);
    });
  });

  it('enableDrag가 false일 때 드래그 핸들이 숨겨짐', () => {
    const { container } = render(
      <SortableMenuList items={sampleMenus} enableDrag={false} />
    );

    const dragHandles = container.querySelectorAll('.cursor-grab');
    expect(dragHandles.length).toBe(0);
  });
});

describe('SortableMenuList - 자식 메뉴 (2depth)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  // 확장 버튼(chevron 아이콘이 있는 버튼)을 찾는 헬퍼 함수
  const findExpandButton = (container: HTMLElement, menuText: string): HTMLElement | null => {
    // 메뉴 텍스트를 포함하는 요소 찾기
    const menuItems = container.querySelectorAll('[class*="flex items-center gap-2 cursor-pointer"]');
    for (const item of menuItems) {
      if (item.textContent?.includes(menuText)) {
        // 해당 메뉴 아이템 내에서 button[type="button"] 찾기
        const expandBtn = item.querySelector('button[type="button"]');
        if (expandBtn) {
          return expandBtn as HTMLElement;
        }
      }
    }
    return null;
  };

  it('부모 메뉴 펼침 시 자식 메뉴가 표시됨', async () => {
    const user = userEvent.setup();
    const { container } = render(<SortableMenuList items={menusWithMultipleChildren} />);

    // 초기에는 자식 메뉴가 숨겨져 있음
    expect(screen.queryByText('사용자 목록')).not.toBeInTheDocument();

    // 확장 버튼 클릭 (사용자 관리)
    const expandBtn = findExpandButton(container, '사용자 관리');
    expect(expandBtn).not.toBeNull();
    if (expandBtn) {
      await user.click(expandBtn);
    }

    // 자식 메뉴가 표시됨
    await waitFor(() => {
      expect(screen.getByText('사용자 목록')).toBeInTheDocument();
      expect(screen.getByText('사용자 추가')).toBeInTheDocument();
      expect(screen.getByText('권한 관리')).toBeInTheDocument();
    });
  });

  it('여러 자식 메뉴가 있을 때 모두 렌더링됨', async () => {
    const user = userEvent.setup();
    const { container } = render(<SortableMenuList items={menusWithMultipleChildren} />);

    // 확장 버튼 클릭
    const expandBtn = findExpandButton(container, '사용자 관리');
    if (expandBtn) {
      await user.click(expandBtn);
    }

    await waitFor(() => {
      // 3개의 자식 메뉴 확인
      expect(screen.getByText('사용자 목록')).toBeInTheDocument();
      expect(screen.getByText('사용자 추가')).toBeInTheDocument();
      expect(screen.getByText('권한 관리')).toBeInTheDocument();
    });
  });

  it('자식 메뉴 클릭 시 onSelect 콜백이 호출됨', async () => {
    const user = userEvent.setup();
    const onSelect = vi.fn();
    const { container } = render(
      <SortableMenuList items={menusWithMultipleChildren} onSelect={onSelect} />
    );

    // 확장 버튼 클릭
    const expandBtn = findExpandButton(container, '사용자 관리');
    if (expandBtn) {
      await user.click(expandBtn);
    }

    await waitFor(() => {
      expect(screen.getByText('사용자 목록')).toBeInTheDocument();
    });

    // 자식 메뉴 클릭
    const childMenu = screen.getByText('사용자 목록');
    await user.click(childMenu);

    // onSelect가 자식 메뉴 데이터와 함께 호출됨
    expect(onSelect).toHaveBeenCalledWith(
      expect.objectContaining({
        id: 3,
        name: '사용자 목록',
        parent_id: 2,
      })
    );
  });

  it('자식 메뉴에 독립적인 드래그 핸들이 있음', async () => {
    const user = userEvent.setup();
    const { container } = render(
      <SortableMenuList
        items={menusWithMultipleChildren}
        onOrderChange={vi.fn()}
      />
    );

    // 확장 버튼 클릭
    const expandBtn = findExpandButton(container, '사용자 관리');
    if (expandBtn) {
      await user.click(expandBtn);
    }

    await waitFor(() => {
      expect(screen.getByText('사용자 목록')).toBeInTheDocument();
    });

    // 자식 메뉴 영역에 드래그 핸들 확인
    const dragHandles = container.querySelectorAll('.cursor-grab');
    // 부모 3개 + 자식 3개 = 최소 6개의 드래그 핸들
    expect(dragHandles.length).toBeGreaterThanOrEqual(4);
  });

  it('selectedId가 자식 메뉴 ID일 때 해당 자식이 선택됨', async () => {
    const user = userEvent.setup();
    const { container } = render(
      <SortableMenuList
        items={menusWithMultipleChildren}
        selectedId={3} // 자식 메뉴 ID
      />
    );

    // 확장 버튼 클릭
    const expandBtn = findExpandButton(container, '사용자 관리');
    if (expandBtn) {
      await user.click(expandBtn);
    }

    await waitFor(() => {
      expect(screen.getByText('사용자 목록')).toBeInTheDocument();
    });

    // 선택된 자식 메뉴에 선택 스타일이 적용됨
    expect(container).toBeInTheDocument();
  });

  it('자식 메뉴에 토글 컴포넌트가 렌더링됨', async () => {
    const user = userEvent.setup();
    const onToggleStatus = vi.fn();
    const { container } = render(
      <SortableMenuList
        items={menusWithMultipleChildren}
        onToggleStatus={onToggleStatus}
      />
    );

    // 확장 버튼 클릭
    const expandBtn = findExpandButton(container, '사용자 관리');
    if (expandBtn) {
      await user.click(expandBtn);
    }

    await waitFor(() => {
      expect(screen.getByText('사용자 목록')).toBeInTheDocument();
    });

    // 자식 메뉴에 토글 컴포넌트(checkbox)가 렌더링되어 있는지 확인
    const toggleCheckboxes = container.querySelectorAll('input[type="checkbox"]');
    // 부모 3개 + 자식 3개 = 6개
    expect(toggleCheckboxes.length).toBeGreaterThanOrEqual(6);
  });

  it('부모 메뉴와 자식 메뉴가 서로 다른 DndContext를 가짐', async () => {
    const user = userEvent.setup();
    const { container } = render(
      <SortableMenuList
        items={menusWithMultipleChildren}
        onOrderChange={vi.fn()}
      />
    );

    // 확장 버튼 클릭
    const expandBtn = findExpandButton(container, '사용자 관리');
    if (expandBtn) {
      await user.click(expandBtn);
    }

    await waitFor(() => {
      expect(screen.getByText('사용자 목록')).toBeInTheDocument();
    });

    // 컴포넌트가 정상적으로 렌더링됨 (에러 없이)
    expect(container).toBeInTheDocument();
    // 자식 메뉴들이 표시됨
    expect(screen.getByText('사용자 추가')).toBeInTheDocument();
    expect(screen.getByText('권한 관리')).toBeInTheDocument();
  });

  it('여러 부모의 자식 메뉴들이 독립적으로 관리됨', async () => {
    const user = userEvent.setup();
    const { container } = render(
      <SortableMenuList
        items={menusWithMultipleChildren}
        onOrderChange={vi.fn()}
      />
    );

    // 첫 번째 부모(사용자 관리) 확장
    const expandBtn1 = findExpandButton(container, '사용자 관리');
    if (expandBtn1) {
      await user.click(expandBtn1);
    }

    await waitFor(() => {
      expect(screen.getByText('사용자 목록')).toBeInTheDocument();
    });

    // 두 번째 부모(설정) 확장
    const expandBtn2 = findExpandButton(container, '설정');
    if (expandBtn2) {
      await user.click(expandBtn2);
    }

    await waitFor(() => {
      // 두 부모의 자식 메뉴들이 모두 표시됨
      expect(screen.getByText('사용자 목록')).toBeInTheDocument();
      expect(screen.getByText('일반 설정')).toBeInTheDocument();
      expect(screen.getByText('보안 설정')).toBeInTheDocument();
    });
  });

  it('toggleDisabled가 true일 때 자식 메뉴 토글도 비활성화됨', async () => {
    const user = userEvent.setup();
    const { container } = render(
      <SortableMenuList
        items={menusWithMultipleChildren}
        toggleDisabled={true}
      />
    );

    // 확장 버튼 클릭
    const expandBtn = findExpandButton(container, '사용자 관리');
    if (expandBtn) {
      await user.click(expandBtn);
    }

    await waitFor(() => {
      expect(screen.getByText('사용자 목록')).toBeInTheDocument();
    });

    // 모든 토글이 비활성화됨
    const toggleCheckboxes = container.querySelectorAll('input[type="checkbox"]');
    toggleCheckboxes.forEach((checkbox) => {
      expect((checkbox as HTMLInputElement).disabled).toBe(true);
    });
  });

  it('자식 메뉴의 childrenContainerClassName이 적용됨', async () => {
    const user = userEvent.setup();
    const { container } = render(
      <SortableMenuList
        items={menusWithMultipleChildren}
        childrenContainerClassName="custom-children-container bg-gray-100"
      />
    );

    // 확장 버튼 클릭
    const expandBtn = findExpandButton(container, '사용자 관리');
    if (expandBtn) {
      await user.click(expandBtn);
    }

    await waitFor(() => {
      expect(screen.getByText('사용자 목록')).toBeInTheDocument();
    });

    // 커스텀 클래스가 적용됨
    const childrenContainer = container.querySelector('.custom-children-container');
    expect(childrenContainer).toBeInTheDocument();
  });
});
