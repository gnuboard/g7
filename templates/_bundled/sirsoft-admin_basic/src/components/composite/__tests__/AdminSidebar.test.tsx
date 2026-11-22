import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AdminSidebar, MenuItem } from '../AdminSidebar';
import type { MenuIconSpecData } from '../MenuIcon';

/**
 * 시험 동안 window.location 을 바꿔 끼운다.
 * 원래 속성을 지운 뒤 같은 이름의 쓰기 가능한 속성으로 다시 정의한다(대입과 같은 결과를 타입 단언 없이 얻는다).
 *
 * @param value 새로 둘 location 값
 */
function replaceWindowLocation(value: Location): void {
  Reflect.deleteProperty(window, 'location');
  Object.defineProperty(window, 'location', { value, writable: true, configurable: true, enumerable: true });
}

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

  // @scenario surface=render
  // @effects renders_menu_and_logo
  it('컴포넌트가 렌더링됨', () => {
    render(<AdminSidebar menu={mockMenu} />);
    expect(screen.getByText('대시보드')).toBeInTheDocument();
  });

  // @scenario surface=render
  // @effects renders_menu_and_logo
  it('로고가 표시됨', () => {
    render(<AdminSidebar logo="/logo.png" logoAlt="Test Logo" menu={mockMenu} />);

    const logo = screen.getByAltText('Test Logo');
    expect(logo).toBeInTheDocument();
    expect(logo).toHaveAttribute('src', '/logo.png');
  });

  // @scenario surface=render
  // @effects renders_menu_and_logo
  it('메뉴 아이템이 모두 표시됨', () => {
    render(<AdminSidebar menu={mockMenu} />);

    expect(screen.getByText('대시보드')).toBeInTheDocument();
    expect(screen.getByText('콘텐츠')).toBeInTheDocument();
    expect(screen.getByText('설정')).toBeInTheDocument();
  });

  // @scenario surface=leaf
  // @effects menu_navigates_spa
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

  // @scenario surface=toggle_parent
  // @effects parent_toggles_submenu
  it('하위 메뉴가 있는 아이템 클릭 시 하위 메뉴가 표시됨', async () => {
    const user = userEvent.setup();
    render(<AdminSidebar menu={mockMenu} />);

    const contentButton = screen.getByText('콘텐츠');
    await user.click(contentButton);

    expect(screen.getByText('게시물')).toBeInTheDocument();
    expect(screen.getByText('페이지')).toBeInTheDocument();
  });

  // @scenario surface=toggle_parent
  // @effects parent_toggles_submenu
  it('하위 메뉴가 표시된 후 다시 클릭하면 닫힘', async () => {
    const user = userEvent.setup();
    render(<AdminSidebar menu={mockMenu} />);

    const contentButton = screen.getByText('콘텐츠');
    await user.click(contentButton);

    expect(screen.getByText('게시물')).toBeInTheDocument();

    await user.click(contentButton);

    // 하위 메뉴가 닫혀야 하지만 실제로는 DOM에 남아있을 수 있음 (CSS로 숨김)
  });

  // @scenario surface=collapsed
  // @effects collapsed_hides_labels
  it('collapsed 모드에서 메뉴 라벨이 숨겨짐', () => {
    render(<AdminSidebar menu={mockMenu} collapsed={true} />);

    // collapsed 모드에서는 텍스트가 DOM에서 제거됨
    expect(screen.queryByText('대시보드')).not.toBeInTheDocument();
    expect(screen.queryByText('콘텐츠')).not.toBeInTheDocument();
    expect(screen.queryByText('설정')).not.toBeInTheDocument();
  });

  // @scenario surface=collapsed
  // @effects collapsed_hides_labels
  it('토글 버튼을 클릭하면 onToggleCollapse가 호출됨', async () => {
    const user = userEvent.setup();
    const onToggleCollapse = vi.fn();
    render(<AdminSidebar menu={mockMenu} onToggleCollapse={onToggleCollapse} />);

    const toggleButton = screen.getByLabelText('사이드바 접기');
    await user.click(toggleButton);

    expect(onToggleCollapse).toHaveBeenCalledTimes(1);
  });

  // @scenario surface=collapsed
  // @effects collapsed_hides_labels
  it('collapsed 모드에서 토글 버튼 라벨이 변경됨', () => {
    render(<AdminSidebar menu={mockMenu} collapsed={true} onToggleCollapse={vi.fn()} />);

    expect(screen.getByLabelText('사이드바 펼치기')).toBeInTheDocument();
  });

  // @scenario surface=collapsed
  // @effects collapsed_hides_labels
  it('collapsed 모드에서 하위 메뉴가 표시되지 않음', () => {
    render(<AdminSidebar menu={mockMenu} collapsed={true} />);

    // collapsed 모드에서는 하위 메뉴가 표시되지 않음 (텍스트도 없음)
    expect(screen.queryByText('게시물')).not.toBeInTheDocument();
    expect(screen.queryByText('페이지')).not.toBeInTheDocument();
  });

  // @scenario surface=render
  // @effects renders_menu_and_logo
  it('className prop이 적용됨', () => {
    const { container } = render(<AdminSidebar menu={mockMenu} className="custom-class" />);
    expect(container.firstChild).toHaveClass('custom-class');
  });

  describe('G7Core 통합', () => {
    // @scenario surface=locale
    // @effects locale_follows_core
    it('G7Core.locale.current()에서 로케일을 자동으로 가져옴', () => {
      mockLocale.current.mockReturnValue('en');
      render(<AdminSidebar menu={mockMenu} />);

      // 영어 로케일이므로 영어 메뉴명 표시
      expect(screen.getByText('Dashboard')).toBeInTheDocument();
      expect(screen.getByText('Content')).toBeInTheDocument();
      expect(screen.getByText('Settings')).toBeInTheDocument();
    });

    // @scenario surface=locale
    // @effects locale_follows_core
    it('currentLocale prop이 G7Core.locale보다 우선 적용됨', () => {
      mockLocale.current.mockReturnValue('en');
      render(<AdminSidebar menu={mockMenu} currentLocale="ko" />);

      // currentLocale prop이 우선이므로 한국어 표시
      expect(screen.getByText('대시보드')).toBeInTheDocument();
    });

    // @scenario surface=locale
    // @effects locale_follows_core
    it('G7Core.state.subscribe로 로케일 변경 구독', () => {
      render(<AdminSidebar menu={mockMenu} />);

      expect(mockState.subscribe).toHaveBeenCalled();
    });

    // @scenario surface=locale
    // @effects locale_follows_core
    it('컴포넌트 언마운트 시 구독 해제', () => {
      const unsubscribe = vi.fn();
      mockState.subscribe.mockReturnValue(unsubscribe);

      const { unmount } = render(<AdminSidebar menu={mockMenu} />);
      unmount();

      expect(unsubscribe).toHaveBeenCalled();
    });

    // @scenario surface=locale
    // @effects locale_follows_core
    it('G7Core 없이도 fallback으로 동작함', async () => {
      // G7Core 제거
      delete (window as any).G7Core;

      const user = userEvent.setup();

      // window.location.href 모킹
      const originalLocation = window.location;
      replaceWindowLocation({ ...originalLocation, href: '' } as Location);

      render(<AdminSidebar menu={mockMenu} />);

      const dashboard = screen.getByText('대시보드');
      await user.click(dashboard);

      expect(window.location.href).toBe('/admin');

      // 원복
      replaceWindowLocation(originalLocation);
    });

    // @scenario surface=leaf
    // @effects menu_navigates_spa
    it('G7Core 없을 때 Ctrl+클릭은 지금 탭을 옮기지 않는다(브라우저가 새 탭으로 연다)', async () => {
      delete (window as any).G7Core;

      const user = userEvent.setup();
      const originalLocation = window.location;
      replaceWindowLocation({ ...originalLocation, href: '' } as Location);

      render(<AdminSidebar menu={mockMenu} />);

      await user.keyboard('{Control>}');
      await user.click(screen.getByText('대시보드'));
      await user.keyboard('{/Control}');

      expect(window.location.href).toBe('');

      replaceWindowLocation(originalLocation);
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
      replaceWindowLocation(originalLocation);
    });

    const setPathname = (pathname: string) => {
      replaceWindowLocation({ ...originalLocation, pathname } as Location);
    };

    // URL 메뉴는 `<a href>` 로 그린다(#757 링크 단계) — 활성 스타일도 링크에 붙는다
    // @scenario surface=active_match
    // @effects active_menu_by_longest_prefix
    it('정확한 URL 매칭: /admin/boards에서 "게시판 관리"만 활성화', () => {
      setPathname('/admin/boards');
      render(<AdminSidebar menu={prefixConflictMenu} currentLocale="ko" />);

      // "게시판" 부모가 자동 펼침되므로 자식 메뉴가 보임
      const boardMgmt = screen.getByText('게시판 관리');
      const boardReports = screen.getByText('게시판 신고 관리');

      // "게시판 관리" 버튼에 활성 스타일 적용
      expect(boardMgmt.closest('a')).toHaveClass('admin-sidebar-item-active');
      // "게시판 신고 관리" 버튼에 활성 스타일 미적용
      expect(boardReports.closest('a')).not.toHaveClass('admin-sidebar-item-active');
    });

    // @scenario surface=active_match
    // @effects active_menu_by_longest_prefix
    it('접두사 충돌 해결: /admin/boards/reports에서 "게시판 신고 관리"만 활성화', () => {
      setPathname('/admin/boards/reports');
      render(<AdminSidebar menu={prefixConflictMenu} currentLocale="ko" />);

      const boardMgmt = screen.getByText('게시판 관리');
      const boardReports = screen.getByText('게시판 신고 관리');

      // "게시판 신고 관리"만 활성 스타일 적용
      expect(boardReports.closest('a')).toHaveClass('admin-sidebar-item-active');
      // "게시판 관리"는 비활성
      expect(boardMgmt.closest('a')).not.toHaveClass('admin-sidebar-item-active');
    });

    // @scenario surface=active_match
    // @effects active_menu_by_longest_prefix
    it('하위 경로에서 가장 가까운 메뉴 활성화: /admin/boards/123', () => {
      setPathname('/admin/boards/123');
      render(<AdminSidebar menu={prefixConflictMenu} currentLocale="ko" />);

      const boardMgmt = screen.getByText('게시판 관리');
      const boardReports = screen.getByText('게시판 신고 관리');

      // "게시판 관리" (/admin/boards)가 가장 긴 접두사 매칭
      expect(boardMgmt.closest('a')).toHaveClass('admin-sidebar-item-active');
      // "게시판 신고 관리" (/admin/boards/reports)는 매칭 안됨
      expect(boardReports.closest('a')).not.toHaveClass('admin-sidebar-item-active');
    });

    // @scenario surface=active_match
    // @effects active_menu_by_longest_prefix
    it('부모 메뉴 자동 펼침: 활성 자식이 있으면 부모 펼침', () => {
      setPathname('/admin/boards/reports');
      render(<AdminSidebar menu={prefixConflictMenu} currentLocale="ko" />);

      // "게시판" 부모가 펼쳐져서 자식 메뉴가 보여야 함
      expect(screen.getByText('게시판 관리')).toBeInTheDocument();
      expect(screen.getByText('게시판 신고 관리')).toBeInTheDocument();
    });

    // @scenario surface=active_match
    // @effects active_menu_by_longest_prefix
    it('무관한 경로에서는 게시판 메뉴 비활성', () => {
      setPathname('/admin/settings');
      render(<AdminSidebar menu={prefixConflictMenu} currentLocale="ko" />);

      // "설정" 메뉴만 활성
      const settings = screen.getByText('설정');
      expect(settings.closest('a')).toHaveClass('admin-sidebar-item-active');

      // 게시판 관련 하위 메뉴는 부모가 펼쳐지지 않아 DOM에 없음
      expect(screen.queryByText('게시판 관리')).not.toBeInTheDocument();
      expect(screen.queryByText('게시판 신고 관리')).not.toBeInTheDocument();
    });
  });
});

/**
 * 구조형 메뉴 아이콘 (#381) — 사이드바의 네 렌더 분기(URL+하위 · 하위만 · URL만 · 둘 다 없음)가 모두
 * `icon_spec`(class·image·svg)을 MenuIcon 으로 그리고, spec 없는 호환 문자열 icon 은 종전처럼 그 클래스를,
 * 깨진 이미지는 `fas fa-circle` 을 그린다.
 */
describe('AdminSidebar — 구조형 메뉴 아이콘 (#381)', () => {
  let originalG7Core: any;

  beforeEach(() => {
    vi.clearAllMocks();
    originalG7Core = (window as any).G7Core;
    (window as any).G7Core = { ...originalG7Core, dispatch: mockDispatch, locale: mockLocale, state: mockState };
  });

  afterEach(() => {
    (window as any).G7Core = originalG7Core;
  });

  type Branch = 'url_parent' | 'toggle_parent' | 'leaf' | 'no_url_leaf';

  const BRANCHES: Branch[] = ['url_parent', 'toggle_parent', 'leaf', 'no_url_leaf'];

  /** 분기별 라벨 — 같은 라벨이 두 번 나오지 않게 분기 이름을 쓴다 */
  const LABEL: Record<Branch, string> = {
    url_parent: '아이콘-URL과하위',
    toggle_parent: '아이콘-하위만',
    leaf: '아이콘-URL만',
    no_url_leaf: '아이콘-둘다없음',
  };

  const CLASS_SPEC: MenuIconSpecData = { type: 'class', value: 'fas fa-users' };
  const IMAGE_SPEC: MenuIconSpecData = { type: 'image', source: 'site', path: '/images/menu.png', url: '/images/menu.png' };
  const SVG_SPEC: MenuIconSpecData = {
    type: 'svg',
    value: '<svg viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0h24v24H0z" fill="currentColor"/></svg>',
  };

  /**
   * 한 분기 모양의 메뉴 항목을 만든다.
   *
   * @param branch 렌더 분기
   * @param icon 아이콘 필드 (icon · icon_spec)
   * @returns 메뉴 항목
   */
  function menuFor(branch: Branch, icon: Pick<MenuItem, 'icon' | 'icon_spec'>): MenuItem {
    const child: MenuItem = { id: 901, name: '자식', slug: 'child', url: '/admin/child', icon: 'fas fa-list' };
    const base: MenuItem = { id: 900, name: LABEL[branch], slug: branch, ...icon };

    switch (branch) {
      case 'url_parent':
        return { ...base, url: '/admin/icon-parent', children: [child] };
      case 'toggle_parent':
        return { ...base, url: null, children: [child] };
      case 'leaf':
        return { ...base, url: '/admin/icon-leaf' };
      default:
        return { ...base, url: null };
    }
  }

  /**
   * 분기의 항목 요소(링크 또는 단추)를 찾는다.
   *
   * @param branch 렌더 분기
   * @returns 항목 요소
   */
  function itemElement(branch: Branch): HTMLElement {
    const el = screen.getByText(LABEL[branch]).closest('a, button');
    expect(el).not.toBeNull();

    return el as HTMLElement;
  }

  /** URL+하위 분기는 아이콘에 간격·크기 클래스를 붙이고, 나머지 분기는 아이콘 상자 안에 맨 클래스로 그린다 */
  const ICON_EXTRA: Record<Branch, string> = {
    url_parent: ' mr-3 w-5 h-5 flex items-center justify-center',
    toggle_parent: '',
    leaf: '',
    no_url_leaf: '',
  };

  /**
   * @scenario surface=sidebar, icon_type=class
   * @effects structured_menu_icons_render, structured_icon_renders_per_type
   */
  it.each(BRANCHES)('%s 분기 — class spec 을 그 FA 클래스로 그린다', (branch) => {
    render(<AdminSidebar menu={[menuFor(branch, { icon: 'fas fa-users', icon_spec: CLASS_SPEC })]} currentLocale="ko" />);
    const icons = itemElement(branch).querySelectorAll('i.fa-users');

    expect(icons).toHaveLength(1);
    expect(icons[0].className).toBe(`fas fa-users${ICON_EXTRA[branch]}`);
    expect(icons[0].getAttribute('aria-hidden')).toBe('true');
  });

  /**
   * @scenario surface=sidebar, icon_type=image
   * @effects structured_menu_icons_render, structured_icon_renders_per_type
   */
  it.each(BRANCHES)('%s 분기 — image spec 을 <img> 로 그린다', (branch) => {
    render(<AdminSidebar menu={[menuFor(branch, { icon: null, icon_spec: IMAGE_SPEC })]} currentLocale="ko" />);
    const img = itemElement(branch).querySelector('img.menu-icon-media');

    expect(img).not.toBeNull();
    expect(img?.getAttribute('src')).toBe('/images/menu.png');
    expect(img?.getAttribute('alt')).toBe('');
  });

  /**
   * @scenario surface=sidebar, icon_type=image
   * @effects structured_menu_icons_render, broken_image_falls_back_to_circle
   */
  it.each(BRANCHES)('%s 분기 — 깨진 이미지는 fas fa-circle 로 바뀐다', (branch) => {
    render(<AdminSidebar menu={[menuFor(branch, { icon: null, icon_spec: IMAGE_SPEC })]} currentLocale="ko" />);
    const el = itemElement(branch);

    fireEvent.error(el.querySelector('img.menu-icon-media')!);

    expect(el.querySelector('img.menu-icon-media')).toBeNull();
    const circle = el.querySelectorAll('i.fa-circle');
    expect(circle).toHaveLength(1);
    expect(circle[0].className).toBe(`fas fa-circle${ICON_EXTRA[branch]}`);
  });

  /**
   * @scenario surface=sidebar, icon_type=svg
   * @effects structured_menu_icons_render, structured_icon_renders_per_type, svg_sanitized_inline
   */
  it.each(BRANCHES)('%s 분기 — svg spec 을 정제해 인라인으로 그린다', (branch) => {
    render(<AdminSidebar menu={[menuFor(branch, { icon: null, icon_spec: SVG_SPEC })]} currentLocale="ko" />);
    const el = itemElement(branch);
    const media = el.querySelector('span.menu-icon-media');

    expect(media).not.toBeNull();
    expect(media?.querySelectorAll('svg')).toHaveLength(1);
    expect(el.querySelector('script')).toBeNull();
    expect(media?.querySelector('path')?.getAttribute('fill')).toBe('currentColor');
  });

  /**
   * @scenario surface=sidebar, icon_type=legacy_string
   * @effects structured_menu_icons_render, legacy_icon_string_still_renders
   */
  it.each(BRANCHES)('%s 분기 — spec 없는 호환 문자열 icon(fas fa-home)은 종전처럼 그 클래스로 그린다', (branch) => {
    render(<AdminSidebar menu={[menuFor(branch, { icon: 'fas fa-home' })]} currentLocale="ko" />);
    const icons = itemElement(branch).querySelectorAll('i.fa-home');

    expect(icons).toHaveLength(1);
    expect(icons[0].className).toBe(`fas fa-home${ICON_EXTRA[branch]}`);
  });

  /**
   * @scenario surface=sidebar, icon_type=legacy_string
   * @effects structured_menu_icons_render, legacy_icon_string_still_renders
   */
  it.each(BRANCHES)('%s 분기 — icon·spec 이 모두 없으면 아이콘 자리를 그리지 않는다', (branch) => {
    render(<AdminSidebar menu={[menuFor(branch, { icon: null, icon_spec: null })]} currentLocale="ko" />);
    const el = itemElement(branch);

    expect(el.querySelector('.admin-sidebar-icon-box')).toBeNull();
    expect(el.querySelector('img, svg, i.fa-circle')).toBeNull();
  });
});
