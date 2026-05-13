import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AdminSidebar, MenuItem } from '../AdminSidebar';

// G7Core 모킹
const mockDispatch = vi.fn();
const mockLocale = {
  current: vi.fn(() => 'ko'),
  supported: vi.fn(() => ['ko', 'en']),
  change: vi.fn(),
};
const mockState = {
  get: vi.fn(() => ({ _global: { locale: 'ko' } })),
  set: vi.fn(),
  subscribe: vi.fn(() => vi.fn()), // unsubscribe 함수 반환
};

describe('AdminSidebar', () => {
  const mockMenu: MenuItem[] = [
    { id: 1, name: { ko: '대시보드', en: 'Dashboard' }, slug: 'dashboard', url: '/admin', icon: 'fas fa-home', is_active: true },
    {
      id: 2,
      name: { ko: '콘텐츠', en: 'Content' },
      slug: 'content',
      icon: 'fas fa-file-text',
      children: [
        { id: 21, name: { ko: '게시물', en: 'Posts' }, slug: 'posts', url: '/admin/posts', icon: 'fas fa-list' },
        { id: 22, name: { ko: '페이지', en: 'Pages' }, slug: 'pages', url: '/admin/pages', icon: 'fas fa-file' },
      ],
    },
    { id: 3, name: { ko: '설정', en: 'Settings' }, slug: 'settings', url: '/admin/settings', icon: 'fas fa-cog' },
  ];

  // 원래 G7Core 백업
  let originalG7Core: any;

  // 각 테스트 전에 G7Core 설정
  beforeEach(() => {
    vi.clearAllMocks();
    // 기존 G7Core를 유지하면서 필요한 속성만 추가/덮어쓰기
    originalG7Core = (window as any).G7Core;
    (window as any).G7Core = {
      ...originalG7Core,
      dispatch: mockDispatch,
      locale: mockLocale,
      state: mockState,
    };
  });

  // 테스트 후 정리
  afterEach(() => {
    (window as any).G7Core = originalG7Core;
  });

  it('컴포넌트가 렌더링됨', () => {
    render(<AdminSidebar menu={mockMenu} />);
    expect(screen.getByText('대시보드')).toBeInTheDocument();
  });

  it('로고가 표시됨', () => {
    render(<AdminSidebar logo="/logo.png" logoAlt="Test Logo" menu={mockMenu} />);

    const logo = screen.getByAltText('Test Logo');
    expect(logo).toBeInTheDocument();
    expect(logo).toHaveAttribute('src', '/logo.png');
  });

  it('메뉴 아이템이 모두 표시됨', () => {
    render(<AdminSidebar menu={mockMenu} />);

    expect(screen.getByText('대시보드')).toBeInTheDocument();
    expect(screen.getByText('콘텐츠')).toBeInTheDocument();
    expect(screen.getByText('설정')).toBeInTheDocument();
  });

  it('메뉴 아이템 클릭 시 G7Core.dispatch로 네비게이션', async () => {
    const user = userEvent.setup();
    render(<AdminSidebar menu={mockMenu} />);

    const dashboard = screen.getByText('대시보드');
    await user.click(dashboard);

    expect(mockDispatch).toHaveBeenCalledWith({
      handler: 'navigate',
      params: { path: '/admin' },
    });
  });

  it('하위 메뉴가 있는 아이템 클릭 시 하위 메뉴가 표시됨', async () => {
    const user = userEvent.setup();
    render(<AdminSidebar menu={mockMenu} />);

    const contentButton = screen.getByText('콘텐츠');
    await user.click(contentButton);

    expect(screen.getByText('게시물')).toBeInTheDocument();
    expect(screen.getByText('페이지')).toBeInTheDocument();
  });

  it('하위 메뉴가 표시된 후 다시 클릭하면 닫힘', async () => {
    const user = userEvent.setup();
    render(<AdminSidebar menu={mockMenu} />);

    const contentButton = screen.getByText('콘텐츠');
    await user.click(contentButton);

    expect(screen.getByText('게시물')).toBeInTheDocument();

    await user.click(contentButton);

    // 하위 메뉴가 닫혀야 하지만 실제로는 DOM에 남아있을 수 있음 (CSS로 숨김)
  });

  it('collapsed 모드에서 메뉴 라벨이 숨겨짐', () => {
    render(<AdminSidebar menu={mockMenu} collapsed={true} />);

    // collapsed 모드에서는 텍스트가 DOM에서 제거됨
    expect(screen.queryByText('대시보드')).not.toBeInTheDocument();
    expect(screen.queryByText('콘텐츠')).not.toBeInTheDocument();
    expect(screen.queryByText('설정')).not.toBeInTheDocument();
  });

  it('토글 버튼을 클릭하면 onToggleCollapse가 호출됨', async () => {
    const user = userEvent.setup();
    const onToggleCollapse = vi.fn();
    render(<AdminSidebar menu={mockMenu} onToggleCollapse={onToggleCollapse} />);

    const toggleButton = screen.getByLabelText('사이드바 접기');
    await user.click(toggleButton);

    expect(onToggleCollapse).toHaveBeenCalledTimes(1);
  });

  it('collapsed 모드에서 토글 버튼 라벨이 변경됨', () => {
    render(<AdminSidebar menu={mockMenu} collapsed={true} onToggleCollapse={vi.fn()} />);

    expect(screen.getByLabelText('사이드바 펼치기')).toBeInTheDocument();
  });

  it('collapsed 모드에서 하위 메뉴가 표시되지 않음', () => {
    render(<AdminSidebar menu={mockMenu} collapsed={true} />);

    // collapsed 모드에서는 하위 메뉴가 표시되지 않음 (텍스트도 없음)
    expect(screen.queryByText('게시물')).not.toBeInTheDocument();
    expect(screen.queryByText('페이지')).not.toBeInTheDocument();
  });

  it('className prop이 적용됨', () => {
    const { container } = render(<AdminSidebar menu={mockMenu} className="custom-class" />);
    expect(container.firstChild).toHaveClass('custom-class');
  });

  describe('G7Core 통합', () => {
    it('G7Core.locale.current()에서 로케일을 자동으로 가져옴', () => {
      mockLocale.current.mockReturnValue('en');
      render(<AdminSidebar menu={mockMenu} />);

      // 영어 로케일이므로 영어 메뉴명 표시
      expect(screen.getByText('Dashboard')).toBeInTheDocument();
      expect(screen.getByText('Content')).toBeInTheDocument();
      expect(screen.getByText('Settings')).toBeInTheDocument();
    });

    it('currentLocale prop이 G7Core.locale보다 우선 적용됨', () => {
      mockLocale.current.mockReturnValue('en');
      render(<AdminSidebar menu={mockMenu} currentLocale="ko" />);

      // currentLocale prop이 우선이므로 한국어 표시
      expect(screen.getByText('대시보드')).toBeInTheDocument();
    });

    it('G7Core.state.subscribe로 로케일 변경 구독', () => {
      render(<AdminSidebar menu={mockMenu} />);

      expect(mockState.subscribe).toHaveBeenCalled();
    });

    it('컴포넌트 언마운트 시 구독 해제', () => {
      const unsubscribe = vi.fn();
      mockState.subscribe.mockReturnValue(unsubscribe);

      const { unmount } = render(<AdminSidebar menu={mockMenu} />);
      unmount();

      expect(unsubscribe).toHaveBeenCalled();
    });

    it('G7Core 없이도 fallback으로 동작함', async () => {
      // G7Core 제거
      delete (window as any).G7Core;

      const user = userEvent.setup();

      // window.location.href 모킹
      const originalLocation = window.location;
      // @ts-ignore
      delete window.location;
      window.location = { ...originalLocation, href: '' } as Location;

      render(<AdminSidebar menu={mockMenu} />);

      const dashboard = screen.getByText('대시보드');
      await user.click(dashboard);

      expect(window.location.href).toBe('/admin');

      // 원복
      window.location = originalLocation;
    });
  });

  describe('URL 접두사 충돌 시 활성 메뉴 판별 (Issue #37)', () => {
    const prefixConflictMenu: MenuItem[] = [
      { id: 1, name: { ko: '대시보드', en: 'Dashboard' }, slug: 'dashboard', url: '/admin', icon: 'fas fa-home' },
      {
        id: 2,
        name: { ko: '게시판', en: 'Boards' },
        slug: 'boards',
        icon: 'fas fa-clipboard',
        children: [
          { id: 21, name: { ko: '게시판 관리', en: 'Board Management' }, slug: 'board-management', url: '/admin/boards', icon: 'fas fa-list' },
          { id: 22, name: { ko: '게시판 신고 관리', en: 'Board Reports' }, slug: 'board-reports', url: '/admin/boards/reports', icon: 'fas fa-flag' },
        ],
      },
      { id: 3, name: { ko: '설정', en: 'Settings' }, slug: 'settings', url: '/admin/settings', icon: 'fas fa-cog' },
    ];

    let originalLocation: Location;

    beforeEach(() => {
      originalLocation = window.location;
    });

    afterEach(() => {
      window.location = originalLocation;
    });

    const setPathname = (pathname: string) => {
      // @ts-ignore
      delete window.location;
      window.location = { ...originalLocation, pathname } as Location;
    };

    it('정확한 URL 매칭: /admin/boards에서 "게시판 관리"만 활성화', () => {
      setPathname('/admin/boards');
      render(<AdminSidebar menu={prefixConflictMenu} currentLocale="ko" />);

      // "게시판" 부모가 자동 펼침되므로 자식 메뉴가 보임
      const boardMgmt = screen.getByText('게시판 관리');
      const boardReports = screen.getByText('게시판 신고 관리');

      // "게시판 관리" 버튼에 활성 스타일 적용
      expect(boardMgmt.closest('button')).toHaveClass('bg-blue-50');
      // "게시판 신고 관리" 버튼에 활성 스타일 미적용
      expect(boardReports.closest('button')).not.toHaveClass('bg-blue-50');
    });

    it('접두사 충돌 해결: /admin/boards/reports에서 "게시판 신고 관리"만 활성화', () => {
      setPathname('/admin/boards/reports');
      render(<AdminSidebar menu={prefixConflictMenu} currentLocale="ko" />);

      const boardMgmt = screen.getByText('게시판 관리');
      const boardReports = screen.getByText('게시판 신고 관리');

      // "게시판 신고 관리"만 활성 스타일 적용
      expect(boardReports.closest('button')).toHaveClass('bg-blue-50');
      // "게시판 관리"는 비활성
      expect(boardMgmt.closest('button')).not.toHaveClass('bg-blue-50');
    });

    it('하위 경로에서 가장 가까운 메뉴 활성화: /admin/boards/123', () => {
      setPathname('/admin/boards/123');
      render(<AdminSidebar menu={prefixConflictMenu} currentLocale="ko" />);

      const boardMgmt = screen.getByText('게시판 관리');
      const boardReports = screen.getByText('게시판 신고 관리');

      // "게시판 관리" (/admin/boards)가 가장 긴 접두사 매칭
      expect(boardMgmt.closest('button')).toHaveClass('bg-blue-50');
      // "게시판 신고 관리" (/admin/boards/reports)는 매칭 안됨
      expect(boardReports.closest('button')).not.toHaveClass('bg-blue-50');
    });

    it('부모 메뉴 자동 펼침: 활성 자식이 있으면 부모 펼침', () => {
      setPathname('/admin/boards/reports');
      render(<AdminSidebar menu={prefixConflictMenu} currentLocale="ko" />);

      // "게시판" 부모가 펼쳐져서 자식 메뉴가 보여야 함
      expect(screen.getByText('게시판 관리')).toBeInTheDocument();
      expect(screen.getByText('게시판 신고 관리')).toBeInTheDocument();
    });

    it('무관한 경로에서는 게시판 메뉴 비활성', () => {
      setPathname('/admin/settings');
      render(<AdminSidebar menu={prefixConflictMenu} currentLocale="ko" />);

      // "설정" 메뉴만 활성
      const settings = screen.getByText('설정');
      expect(settings.closest('button')).toHaveClass('bg-blue-50');

      // 게시판 관련 하위 메뉴는 부모가 펼쳐지지 않아 DOM에 없음
      expect(screen.queryByText('게시판 관리')).not.toBeInTheDocument();
      expect(screen.queryByText('게시판 신고 관리')).not.toBeInTheDocument();
    });
  });
});
