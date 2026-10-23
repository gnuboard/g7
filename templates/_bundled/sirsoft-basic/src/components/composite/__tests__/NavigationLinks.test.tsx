/**
 * 사용자 템플릿 컴포넌트의 화면 이동 링크 (#757)
 *
 * 화면 이동이 `<button onClick>` + navigate 라 렌더 시점에 주소가 없어 가운데 클릭·Ctrl+클릭·우클릭 「새 탭에서 열기」가
 * 되지 않았다. 이동 자리를 `<a href>` 로 그리고, 보통 클릭만 지금처럼 SPA 로 옮긴다(나머지는 브라우저 기본 동작).
 *
 * 「보통 클릭인가」·「쿼리 합치기」는 엔진 `G7Core.navigation` 의 함수가 판정한다. 엔진 반영 전이라도 컴포넌트를
 * 검증할 수 있게 그 두 함수를 시험에서 주입한다 — 판정식은 엔진과 같은 뜻(왼쪽 단추·수정키 없음)으로 둔다.
 *
 * @scenario-manifest tests/scenarios/navigation-links.yaml
 *
 * @vitest-environment jsdom
 */

import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { A } from '../../basic/A';
import { LinkButton } from '../../../support/LinkButton';
import Header from '../Header';
import MobileNav from '../MobileNav';
import Footer from '../Footer';
import { UserInfo } from '../UserInfo';
import ProductCard from '../ProductCard';
import { Pagination } from '../Pagination';
import { TabNavigation } from '../TabNavigation';
import { NotificationCenter } from '../NotificationCenter';
import { executeImageDownload } from '../ImageGallery';
import {
  isPlainLeftClick as engineIsPlainLeftClick,
  resolveInternalHref as engineResolveInternalHref,
} from '@core/template-engine/navigationLinks';

let dispatch: ReturnType<typeof vi.fn>;

const installCore = (extra: Record<string, unknown> = {}) => {
  dispatch = vi.fn();
  (window as any).G7Core = {
    t: (key: string) => key,
    dispatch,
    navigation: {
      // 엔진의 실제 판정(수정키·가운데 단추·링크 요소의 target·download) — 링크 요소를 넘겨야 target·download 를 본다
      isPlainLeftClick: engineIsPlainLeftClick,
      // 내부 링크 판정은 코어 한 곳(engine-v1.83.0) — 실제 엔진 판정을 주입한다
      resolveInternalHref: engineResolveInternalHref,
      mergeQueryIntoHref: (href: string) => `${href}${href.includes('?') ? '&' : '?'}page=2`,
    },
    ...extra,
  };
};

/** 클릭 이벤트를 보내고 기본 동작이 막혔는지 돌려준다 */
const click = (el: Element, init: MouseEventInit = {}): boolean => {
  const ev = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
  el.dispatchEvent(ev);

  return ev.defaultPrevented;
};

beforeEach(() => installCore());
afterEach(() => {
  cleanup();
  delete (window as any).G7Core;
});

describe('A — 새 창 링크의 rel', () => {
  // @scenario component=a
  // @effects new_window_link_has_noopener
  it('target="_blank" 면 rel 에 noopener noreferrer 를 합친다(있던 값은 남긴다) · 새 창이 아니면 손대지 않는다', () => {
    render(
      <>
        <A href="/a" target="_blank">없음</A>
        <A href="/b" target="_blank" rel="nofollow">기존 값</A>
        <A href="/c" target="_blank" rel="noopener">일부</A>
        <A href="/d">보통</A>
      </>,
    );
    const tokens = (name: string) => (screen.getByRole('link', { name }).getAttribute('rel') ?? '').split(/\s+/).filter(Boolean).sort();

    expect(tokens('없음')).toEqual(['noopener', 'noreferrer']);
    expect(tokens('기존 값')).toEqual(['nofollow', 'noopener', 'noreferrer']);
    expect(tokens('일부')).toEqual(['noopener', 'noreferrer']);
    expect(screen.getByRole('link', { name: '보통' })).not.toHaveAttribute('rel');
  });

  // @scenario component=a
  // @effects new_window_link_has_noopener
  it('LinkButton 에 target="_blank" 를 넘겨도 같은 rel 이 붙는다', () => {
    render(<LinkButton href="/guide" onNavigate={() => undefined} target="_blank">안내서</LinkButton>);
    expect((screen.getByRole('link', { name: '안내서' }).getAttribute('rel') ?? '').split(/\s+/).sort()).toEqual(['noopener', 'noreferrer']);
  });
});

describe('ImageGallery — 다운로드 도우미 링크의 rel', () => {
  // @scenario component=image_gallery
  // @effects new_window_link_has_noopener
  it('일반 다운로드가 만드는 새 창 링크에 noopener noreferrer 가 붙는다', async () => {
    let clicked: HTMLAnchorElement | null = null;
    const spy = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      clicked = this;
    });
    try {
      await executeImageDownload({ src: 'https://cdn.example.com/a.jpg', filename: 'a.jpg' } as any);
    } finally {
      spy.mockRestore();
    }

    expect(clicked).not.toBeNull();
    expect(clicked!.target).toBe('_blank');
    expect(clicked!.rel.split(/\s+/).sort()).toEqual(['noopener', 'noreferrer']);
  });
});

describe('A — 링크 기본 동작', () => {
  // @scenario component=a
  // @effects modified_click_opens_browser_default
  it('target="_blank"·download 링크는 보통 클릭이어도 막지 않는다 — 엔진이 링크 요소의 target·download 를 본다', () => {
    render(
      <>
        <A href="/guide" target="_blank">안내</A>
        <A href="/files/a.pdf" download>내려받기</A>
      </>,
    );
    expect(click(screen.getByRole('link', { name: '안내' }))).toBe(false);
    expect(click(screen.getByRole('link', { name: '내려받기' }))).toBe(false);
    expect(dispatch).not.toHaveBeenCalled();
  });

  // @scenario component=a
  // @effects plain_click_spa_navigate
  it('액션 없는 내부 링크의 보통 클릭은 기본 이동을 막고 SPA 로 옮긴다', () => {
    render(<A href="/shop/products">상품</A>);
    expect(click(screen.getByRole('link', { name: '상품' }))).toBe(true);
    expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/shop/products' } });
  });

  // @scenario component=a
  // @effects modified_click_opens_browser_default
  it('Ctrl·Meta·Shift 클릭과 가운데 단추는 막지도 옮기지도 않는다(브라우저가 새 탭·새 창)', () => {
    render(<A href="/shop/products">상품</A>);
    const link = screen.getByRole('link', { name: '상품' });
    for (const init of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { button: 1 }]) {
      expect(click(link, init)).toBe(false);
    }
    expect(dispatch).not.toHaveBeenCalled();
  });

  // @scenario component=a
  // @effects plain_click_spa_navigate
  it('레이아웃 액션(onClick)이 있으면 그 액션이 클릭을 맡는다(이중 이동 없음)', () => {
    const onClick = vi.fn();
    render(<A href="/shop/products" onClick={onClick}>상품</A>);
    click(screen.getByRole('link', { name: '상품' }));
    expect(onClick).toHaveBeenCalledTimes(1);
    expect(dispatch).not.toHaveBeenCalled();
  });

  // @scenario component=a
  // @effects modified_click_opens_browser_default
  it('바깥 주소·target=_blank 는 브라우저 기본 동작에 맡긴다', () => {
    render(
      <div>
        <A href="https://example.com/a">바깥</A>
        <A href="/shop/products" target="_blank">새 창</A>
      </div>,
    );
    expect(click(screen.getByRole('link', { name: '바깥' }))).toBe(false);
    expect(click(screen.getByRole('link', { name: '새 창' }))).toBe(false);
    expect(dispatch).not.toHaveBeenCalled();
  });

  // @scenario component=a
  // @effects disabled_link_has_no_href
  it('disabled 면 href 가 없고 aria-disabled·tabindex -1, 클릭해도 아무 일도 하지 않는다', () => {
    const onClick = vi.fn();
    render(<A href="/mypage/profile/edit" disabled onClick={onClick}>수정</A>);
    const link = screen.getByRole('link', { name: '수정' });
    expect(link.getAttribute('href')).toBeNull();
    expect(link).toHaveAttribute('aria-disabled', 'true');
    expect(link).toHaveAttribute('tabindex', '-1');
    fireEvent.click(link, { ctrlKey: true });
    fireEvent.click(link);
    expect(onClick).not.toHaveBeenCalled();
    expect(dispatch).not.toHaveBeenCalled();
  });

  // @scenario component=a
  // @effects merge_query_href
  it('mergeQuery 면 현재 쿼리를 합친 주소를 href 로 그린다(보통 클릭·새 탭이 같은 주소)', () => {
    render(<A href="/shop/products?sort=latest" mergeQuery>상품</A>);
    expect(screen.getByRole('link', { name: '상품' })).toHaveAttribute('href', '/shop/products?sort=latest&page=2');
  });

  // @scenario component=a
  // @effects unsafe_href_uses_fallback
  it('fallbackHref 를 주면 이 사이트 주소가 아닌 href(실행 주소·다른 사이트·//host)를 대체 주소로 바꾼다 — 같은 사이트 주소·빈 href 는 그대로', () => {
    render(
      <div>
        <A href="javascript:alert(1)" fallbackHref="/mypage/notifications">실행</A>
        <A href="https://evil.example/x" fallbackHref="/mypage/notifications">바깥</A>
        <A href="//evil.example/x" fallbackHref="/mypage/notifications">프로토콜상대</A>
        <A href="/board/free/14" fallbackHref="/mypage/notifications">경로</A>
        <A href={`${window.location.origin}/board/free/15`} fallbackHref="/mypage/notifications">전체주소</A>
        <A href={`${window.location.origin}//evil.example/x`} fallbackHref="/mypage/notifications">같은호스트이중빗금</A>
        <A href={`${window.location.origin}/\\evil.example/x`} fallbackHref="/mypage/notifications">같은호스트백슬래시</A>
        <A href="https://evil.example/y">대체없음</A>
      </div>,
    );
    expect(screen.getByText('실행').closest('a')).toHaveAttribute('href', '/mypage/notifications');
    expect(screen.getByText('바깥').closest('a')).toHaveAttribute('href', '/mypage/notifications');
    expect(screen.getByText('프로토콜상대').closest('a')).toHaveAttribute('href', '/mypage/notifications');
    expect(screen.getByText('경로').closest('a')).toHaveAttribute('href', '/board/free/14');
    expect(screen.getByText('전체주소').closest('a')).toHaveAttribute('href', `${window.location.origin}/board/free/15`);
    // 같은 출처라도 경로가 `//`(백슬래시 변형 포함)로 시작하면 엔진 navigate 가 거부한다 — href 도 대체 주소다(7.1.1)
    expect(screen.getByText('같은호스트이중빗금').closest('a')).toHaveAttribute('href', '/mypage/notifications');
    expect(screen.getByText('같은호스트백슬래시').closest('a')).toHaveAttribute('href', '/mypage/notifications');
    // 대체 주소를 적지 않은 링크는 종전 그대로(바깥 링크 허용)
    expect(screen.getByText('대체없음').closest('a')).toHaveAttribute('href', 'https://evil.example/y');
    // DOM 속성으로 새지 않는다
    expect(screen.getByText('바깥').closest('a')!.hasAttribute('fallbackhref')).toBe(false);
  });

  // @scenario component=a
  // @effects plain_click_spa_navigate
  it('판정 API 가 없는 옛 엔진에서는 종전처럼 보통 클릭으로 옮긴다', () => {
    installCore({ navigation: undefined });
    render(<A href="/shop/products" mergeQuery>상품</A>);
    const link = screen.getByRole('link', { name: '상품' });
    expect(link).toHaveAttribute('href', '/shop/products');
    expect(click(link)).toBe(true);
    expect(dispatch).toHaveBeenCalledTimes(1);
  });
});

// ── 머리글(Header) ─────────────────────────────────────────────────────────────

/** 머리글이 쓰는 엔진 표면(데스크톱 폭·슬롯 문맥)을 더한다 */
const installHeaderCore = () => {
  installCore({
    useResponsive: () => ({ width: 1280, breakpoint: 'desktop' }),
    state: { getLocal: () => ({}), getGlobal: () => ({}) },
    getSlotContext: () => ({ getSlotComponents: () => [], subscribeToSlot: () => () => {}, isEnabled: true }),
  });
  (window as any).__slotContextValue = (window as any).G7Core.getSlotContext();
};

describe('Header — 메뉴·로고·장바구니·회원 메뉴가 링크', () => {
  const boards = [{ id: 1, name: '자유게시판', slug: 'free' }];

  beforeEach(() => installHeaderCore());
  afterEach(() => {
    delete (window as any).__slotContextValue;
  });

  // @scenario component=header
  // @effects plain_click_spa_navigate, modified_click_opens_browser_default
  it('탭 메뉴(홈·인기글·쇼핑·게시판)는 href 를 가진 링크이고 보통 클릭만 SPA 로 옮긴다', async () => {
    render(<Header siteName="우리 사이트" boards={boards as any} shopBase="/shop" />);

    const home = screen.getByTestId('nav-home');
    expect(home.tagName).toBe('A');
    expect(home).toHaveAttribute('href', '/');
    expect(screen.getByTestId('nav-popular')).toHaveAttribute('href', '/boards/popular');
    expect(screen.getByTestId('nav-shop')).toHaveAttribute('href', '/shop/products');
    expect(screen.getByRole('link', { name: '자유게시판' })).toHaveAttribute('href', '/board/free');

    expect(click(home, { ctrlKey: true })).toBe(false);
    expect(dispatch).not.toHaveBeenCalled();
    expect(click(home)).toBe(true);
    expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/' } });
  });

  // @scenario component=header
  // @effects plain_click_spa_navigate
  it('로고·장바구니·로그인·회원가입도 링크다', async () => {
    render(<Header siteName="우리 사이트" shopBase="/shop" />);

    expect(screen.getByRole('link', { name: '우리 사이트' })).toHaveAttribute('href', '/');
    expect(screen.getByRole('link', { name: 'nav.cart_with_count' })).toHaveAttribute('href', '/shop/cart');
    expect(screen.getByRole('link', { name: 'auth.login' })).toHaveAttribute('href', '/login');
    expect(screen.getByRole('link', { name: 'auth.register_link' })).toHaveAttribute('href', '/register');
  });

  // @scenario component=header
  // @effects modified_click_opens_browser_default
  it('회원 메뉴의 마이페이지·주문·찜은 링크이고, Ctrl 클릭은 메뉴 이동을 막지 않는다', async () => {
    render(<Header siteName="우리 사이트" shopBase="/shop" user={{ uuid: 'u-1', name: '홍길동' } as any} />);

    fireEvent.click(screen.getByRole('button', { name: /홍길동/ }));
    const mypage = screen.getByRole('link', { name: 'common.mypage' });
    expect(mypage).toHaveAttribute('href', '/mypage');
    expect(screen.getByRole('link', { name: 'mypage.tabs.orders' })).toHaveAttribute('href', '/mypage/orders');
    expect(screen.getByRole('link', { name: 'mypage.tabs.wishlist' })).toHaveAttribute('href', '/mypage/wishlist');
    expect(click(mypage, { metaKey: true })).toBe(false);
    expect(dispatch).not.toHaveBeenCalled();
  });
});

// ── 모바일 메뉴·꼬리말·회원 이름·상품 카드·페이지 번호·탭 ──────────────────────────────

describe('MobileNav — 서랍 메뉴 항목이 링크', () => {
  // 서랍은 1024px 미만에서만 그린다
  beforeEach(() => installCore({ useResponsive: () => ({ width: 390, breakpoint: 'mobile' }) }));

  // @scenario component=mobile_nav
  // @effects plain_click_spa_navigate, modified_click_opens_browser_default
  it('메뉴 항목은 href 를 가진 링크 — 보통 클릭은 서랍을 닫고 옮기고, Ctrl 클릭은 둘 다 하지 않는다', async () => {
    const onClose = vi.fn();
    render(<MobileNav isOpen onClose={onClose} siteName="우리 사이트" boards={[{ id: 1, name: '자유게시판', slug: 'free' }]} />);

    const board = screen.getByRole('link', { name: '자유게시판' });
    expect(board).toHaveAttribute('href', '/board/free');
    expect(screen.getByRole('link', { name: 'auth.login' })).toHaveAttribute('href', '/login');

    expect(click(board, { ctrlKey: true })).toBe(false);
    expect(onClose).not.toHaveBeenCalled();
    expect(click(board)).toBe(true);
    expect(onClose).toHaveBeenCalledTimes(1);
    expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/board/free' } });
  });
});

describe('Footer — 링크 그룹 항목이 링크', () => {
  beforeEach(() => installHeaderCore());

  // @scenario component=footer
  // @effects plain_click_spa_navigate
  it('linkGroups 의 항목은 href 를 가진 링크다', async () => {
    render(<Footer siteName="우리 사이트" linkGroups={[{ title: '안내', links: [{ label: '이용약관', href: '/page/terms' }] }]} />);

    const terms = screen.getByRole('link', { name: '이용약관' });
    expect(terms).toHaveAttribute('href', '/page/terms');
    // 수정키 클릭은 브라우저에 맡긴다(SPA 이동 없음)
    expect(click(terms, { shiftKey: true })).toBe(false);
    expect(dispatch).not.toHaveBeenCalled();
    // 보통 클릭 → 기본 이동을 막고 SPA 이동
    expect(click(terms)).toBe(true);
    expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/page/terms' } });
  });
});

describe('UserInfo — 프로필로 가는 이름과 주소 메뉴가 링크', () => {
  // @scenario component=user_info
  // @effects plain_click_spa_navigate, modified_click_opens_browser_default
  it('드롭다운 없이 프로필로 가는 이름은 링크이고, 감싼 행으로 클릭이 올라가지 않는다(수정키 클릭 포함)', async () => {
    // 코어에는 G7Core.navigate 가 없다 — 이동은 엔진 navigate 핸들러(dispatch)로 가야 한다
    const onRow = vi.fn();
    render(
      <div onClick={onRow}>
        <UserInfo userId="u-1" name="홍길동" showDropdown={false} clickable stopPropagation profilePath="/users/{userId}" />
      </div>,
    );

    const name = screen.getByRole('link', { name: '홍길동' });
    expect(name).toHaveAttribute('href', '/users/u-1');
    expect(click(name, { ctrlKey: true })).toBe(false);
    expect(dispatch).not.toHaveBeenCalled();
    expect(click(name)).toBe(true);
    expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/users/u-1' } });
    expect(onRow).not.toHaveBeenCalled();
  });

  // @scenario component=user_info
  // @effects plain_click_spa_navigate
  it('드롭다운의 주소 항목은 링크, 동작 항목(onClick)은 단추 그대로', async () => {
    render(
      <UserInfo
        userId="u-1"
        name="홍길동"
        showDropdown
        menuItems={[
          { key: 'posts', label: '작성글', path: '/users/{{userId}}/posts' },
          { key: 'report', label: '신고', onClick: () => undefined },
        ]}
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: '홍길동' }));

    expect(screen.getByTestId('menu-item-posts')).toHaveAttribute('href', '/users/u-1/posts');
    expect(screen.getByTestId('menu-item-report').tagName).toBe('BUTTON');

    // 주소 항목 보통 클릭 → SPA 이동(전체 다시 부르기 아님)
    expect(click(screen.getByTestId('menu-item-posts'))).toBe(true);
    expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/users/u-1/posts' } });
  });
});

describe('NotificationCenter — 주소가 있는 알림 본문이 링크', () => {
  // @scenario component=notification_center
  // @effects plain_click_spa_navigate, modified_click_opens_browser_default, nested_button_does_not_navigate
  it('알림 url 이 있으면 본문이 href 링크이고 보통 클릭만 알림 클릭으로 처리한다 · 삭제 단추는 링크 밖', () => {
    const onNotificationClick = vi.fn();
    const onDelete = vi.fn();
    render(
      <NotificationCenter
        titleText="알림"
        notifications={[{ id: 1, title: '새 댓글', message: '내 글에 댓글이 달렸습니다', time: '방금', url: '/board/free/14' }]}
        onNotificationClick={onNotificationClick}
        onDelete={onDelete}
        canDelete
      />,
    );
    fireEvent.click(screen.getByLabelText('알림'));

    const link = screen.getByRole('link', { name: /새 댓글/ });
    expect(link).toHaveAttribute('href', '/board/free/14');
    expect(link.querySelector('button')).toBeNull();

    expect(click(link, { ctrlKey: true })).toBe(false);
    expect(onNotificationClick).not.toHaveBeenCalled();
    expect(click(link)).toBe(true);
    expect(onNotificationClick).toHaveBeenCalledTimes(1);
  });

  // @scenario component=notification_center
  // @effects unsafe_href_uses_fallback
  it('바깥 주소·실행 주소 알림은 링크로 그리지 않는다 — 줄 클릭이 알림 클릭 콜백을 불러 레이아웃의 fallbackPath 로 간다', () => {
    const onNotificationClick = vi.fn();
    render(
      <NotificationCenter
        titleText="알림"
        notifications={[
          { id: 1, title: '바깥 알림', message: '다른 사이트', time: '방금', url: 'https://evil.example/x' },
          { id: 2, title: '실행 알림', message: '실행 주소', time: '방금', url: 'javascript:alert(1)' },
          { id: 3, title: '상대 알림', message: '프로토콜 상대', time: '방금', url: '//evil.example/x' },
          { id: 4, title: '이중빗금 알림', message: '같은 호스트 // 경로', time: '방금', url: `${window.location.origin}//evil.example/x` },
        ]}
        onNotificationClick={onNotificationClick}
      />,
    );
    fireEvent.click(screen.getByLabelText('알림'));

    // 종전에는 바깥 주소가 target=_blank 링크라 보통 클릭도 바깥 사이트를 새 창으로 열고 콜백을 건너뛰었다
    expect(screen.queryAllByRole('link')).toEqual([]);
    fireEvent.click(screen.getByText('바깥 알림'));
    expect(onNotificationClick).toHaveBeenCalledTimes(1);
    expect(onNotificationClick.mock.calls[0][0]).toMatchObject({ id: 1 });
  });
});

describe('ProductCard — 상세로 가는 카드가 링크', () => {
  const product = { id: 7, product_code: 'P-7', name: '기본 티셔츠', thumbnail_url: '/t.png', selling_price: 1000 } as any;

  // @scenario component=product_card
  // @effects plain_click_spa_navigate, merge_query_href
  it('카드는 상세 주소 href 를 가진 링크이고, 목록 문맥 승계면 현재 쿼리를 href 에도 싣는다', async () => {
    const { rerender } = render(<ProductCard product={product} shopBase="/shop" />);
    expect(screen.getByRole('link', { name: /기본 티셔츠/ })).toHaveAttribute('href', '/shop/products/P-7');

    rerender(<ProductCard product={product} shopBase="/shop" preserveListQuery />);
    const card = screen.getByRole('link', { name: /기본 티셔츠/ });
    expect(card).toHaveAttribute('href', '/shop/products/P-7?page=2');
    expect(click(card, { metaKey: true })).toBe(false);
    expect(dispatch).not.toHaveBeenCalled();

    // 보통 클릭 → SPA 이동(목록 문맥 승계)
    expect(click(card)).toBe(true);
    expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/shop/products/P-7', mergeQuery: true, query: {} } });
  });

  // @scenario component=product_card
  // @effects nested_button_does_not_navigate
  it('레이아웃이 onClick 으로 따로 처리하는 카드는 주소를 모르므로 단추 그대로다', async () => {
    const onClick = vi.fn();
    render(<ProductCard product={product} shopBase="/shop" onClick={onClick} />);
    expect(screen.queryByRole('link', { name: /기본 티셔츠/ })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: /기본 티셔츠/ }));
    expect(onClick).toHaveBeenCalledWith(7);
  });
});

describe('Pagination — pageHref 를 주면 번호·이전·다음이 링크', () => {
  // @scenario component=pagination
  // @effects plain_click_spa_navigate, modified_click_opens_browser_default, disabled_link_has_no_href
  it('번호 링크는 {page} 자리에 번호를 넣은 href, 현재 쪽은 aria-current, 갈 수 없는 이전은 href 없는 aria-disabled', async () => {
    const onPageChange = vi.fn();
    render(<Pagination currentPage={1} totalPages={3} onPageChange={onPageChange} pageHref="/board/free?page={page}" />);

    const links = screen.getAllByRole('link');
    const page2 = links.find((l) => l.textContent === '2')!;
    expect(page2).toHaveAttribute('href', '/board/free?page=2');
    expect(links.find((l) => l.textContent === '1')).toHaveAttribute('aria-current', 'page');

    const prev = links.find((l) => l.getAttribute('aria-label') === 'common.prev_page')!;
    expect(prev.getAttribute('href')).toBeNull();
    expect(prev).toHaveAttribute('aria-disabled', 'true');

    expect(click(page2, { ctrlKey: true })).toBe(false);
    expect(onPageChange).not.toHaveBeenCalled();
    expect(click(page2)).toBe(true);
    expect(onPageChange).toHaveBeenCalledWith(2);
  });

  // @scenario component=pagination
  // @effects merge_query_href
  it('pageHrefMergeQuery 면 현재 쿼리를 합친 주소, pageHref 가 없으면 종전 단추', async () => {
    const { rerender } = render(<Pagination currentPage={1} totalPages={3} onPageChange={vi.fn()} pageHref="/search?q=a&page={page}" pageHrefMergeQuery />);
    expect(screen.getAllByRole('link').find((l) => l.textContent === '3')).toHaveAttribute('href', '/search?q=a&page=3&page=2');

    rerender(<Pagination currentPage={1} totalPages={3} onPageChange={vi.fn()} />);
    expect(screen.queryAllByRole('link')).toHaveLength(0);
  });
});

describe('TabNavigation — href 를 가진 탭이 링크', () => {
  beforeEach(() => installCore({ useResponsive: () => ({ width: 1280, breakpoint: 'desktop' }) }));

  // @scenario component=tab_navigation
  // @effects plain_click_spa_navigate, modified_click_opens_browser_default
  it('탭은 <a role="tab" href> 이고 보통 클릭만 onTabChange 를 부른다', async () => {
    const onTabChange = vi.fn();
    render(
      <TabNavigation
        activeTabId="all"
        onTabChange={onTabChange}
        tabs={[
          { id: 'all', label: '전체', href: '/search?type=all' },
          { id: 'posts', label: '게시글', href: '/search?type=posts' },
        ]}
      />,
    );

    expect(screen.getByRole('tablist')).toBeInTheDocument();
    const posts = screen.getByRole('tab', { name: '게시글' });
    expect(posts.tagName).toBe('A');
    expect(posts).toHaveAttribute('href', '/search?type=posts');
    expect(screen.getByRole('tab', { name: '전체' })).toHaveAttribute('aria-selected', 'true');

    expect(click(posts, { button: 1 })).toBe(false);
    expect(onTabChange).not.toHaveBeenCalled();
    expect(click(posts)).toBe(true);
    expect(onTabChange).toHaveBeenCalledWith('posts');
  });

  // @scenario component=tab_navigation
  // @effects merge_query_href
  it('tabHrefMergeQuery 면 탭 주소에 현재 쿼리를 합친다(같은 화면 안 탭이 목록 문맥을 이고 간다)', () => {
    render(
      <TabNavigation
        activeTabId="info"
        tabHrefMergeQuery
        tabs={[
          { id: 'info', label: '상세정보', href: '/shop/products/P1?tab=info' },
          { id: 'reviews', label: '리뷰', href: '/shop/products/P1?tab=reviews' },
        ]}
      />,
    );

    expect(screen.getByRole('tab', { name: '리뷰' })).toHaveAttribute('href', '/shop/products/P1?tab=reviews&page=2');
  });
});
