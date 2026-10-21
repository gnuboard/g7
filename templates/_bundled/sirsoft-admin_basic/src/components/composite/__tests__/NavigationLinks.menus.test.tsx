/**
 * 관리자 템플릿 합성 컴포넌트의 화면 이동 링크 — 메뉴·알림·카드·바로가기 (#757 링크 단계 L4)
 *
 * 프로필 메뉴·알림 항목·드롭다운 메뉴 항목·카드·페이지 머리 바로가기가 클릭으로만 이동해 가운데 클릭·Ctrl+클릭·우클릭
 * 「새 탭에서 열기」가 되지 않았다. 주소는 레이아웃 prop·항목 데이터에서 오고 `<a href>` 는 컴포넌트가 그린다(D14).
 * 보통 클릭은 지금의 콜백을 그대로 한 번 부르고 기본 이동을 막으며, 수정키 클릭은 콜백을 부르지 않고 브라우저에 맡긴다.
 *
 * 「보통 클릭인가」는 엔진 `G7Core.navigation.isPlainLeftClick` 이 판정한다(W9) — 시험도 엔진의 실제 함수를 주입한다
 * (컴포넌트가 링크 요소를 넘겨야 엔진이 target·download 를 본다).
 *
 * @scenario-manifest tests/scenarios/navigation-links.yaml
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { render, screen, fireEvent, within, act } from '@testing-library/react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { UserProfile } from '../UserProfile';
import { NotificationCenter, type NotificationItem } from '../NotificationCenter';
import { DropdownMenuItem } from '../DropdownMenuItem';
import { CardGrid, type CardGridCellChild } from '../CardGrid';
import { ExtensionCardGrid, type ExtensionCardItem } from '../ExtensionCardGrid';
import { PageHeader } from '../PageHeader';
import { SortableMenuList, type MenuItem } from '../SortableMenuList';
import { FileTree, type FileTreeNode } from '../FileTree';
import { FileTreePicker } from '../FileTreePicker';
import { isPlainLeftClick as engineIsPlainLeftClick } from '@core/template-engine/navigationLinks';

let dispatch: ReturnType<typeof vi.fn>;
let originalCore: unknown;

const installCore = (extra: Record<string, unknown> = {}) => {
  dispatch = vi.fn();
  (window as any).G7Core = {
    t: (key: string) => key,
    dispatch,
    navigation: {
      isPlainLeftClick: engineIsPlainLeftClick,
      mergeQueryIntoHref: (href: string) => `${href}${href.includes('?') ? '&' : '?'}page=2`,
      onComplete: (cb: () => void) => cb(),
    },
    ...extra,
  };
};

/** 실제 클릭 이벤트를 보내고 이벤트를 돌려준다 — defaultPrevented 로 브라우저 기본 이동 여부를 본다(상태 갱신은 act 로 반영) */
const click = (el: Element, init: MouseEventInit = {}): MouseEvent => {
  const ev = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
  act(() => {
    el.dispatchEvent(ev);
  });
  return ev;
};

beforeEach(() => {
  originalCore = (window as any).G7Core;
  installCore();
});
afterEach(() => {
  (window as any).G7Core = originalCore;
});

// ─────────────────────────────────────────────────────────────────────────────
describe('L4 UserProfile — 프로필 메뉴 이동 링크', () => {
  const user = { id: 1, uuid: 'u-1', name: 'John Doe', email: 'john@example.com' };

  const openMenu = () => {
    fireEvent.click(screen.getByRole('button', { name: /John Doe/ }));
  };

  const linkByHref = (href: string): HTMLAnchorElement => {
    const link = screen.getAllByRole('link').find((el) => el.getAttribute('href') === href);
    expect(link, `href=${href} 링크`).toBeTruthy();
    return link as HTMLAnchorElement;
  };

  // @scenario component=user_profile
  // @effects link_roles_preserved
  it('사용자 정보·프로필 설정을 실제 주소를 가진 링크로 그린다(시각 클래스 유지)', () => {
    render(<UserProfile user={user} />);
    openMenu();
    const header = linkByHref('/admin/users/u-1');
    const settings = linkByHref('/admin/users/u-1/edit');
    expect(header.tagName).toBe('A');
    expect(header).toHaveClass('px-4', 'py-3', 'cursor-pointer');
    expect(settings).toHaveTextContent('Profile Settings');
    expect(settings).toHaveClass('w-full', 'px-4', 'py-2', 'flex');
    // 로그아웃은 이동이 아니라 요청이므로 단추 그대로
    expect(screen.getByRole('button', { name: /Logout/ })).toBeInTheDocument();
  });

  // @scenario component=user_profile
  // @effects plain_click_spa_navigate
  it('보통 클릭은 기본 이동을 막고 지금처럼 SPA 로 옮긴다(프로필 설정·사용자 정보)', () => {
    render(<UserProfile user={user} />);
    openMenu();
    const ev = click(linkByHref('/admin/users/u-1/edit'));
    expect(ev.defaultPrevented).toBe(true);
    expect(dispatch).toHaveBeenCalledTimes(1);
    expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/admin/users/u-1/edit' } });
    // 지금처럼 메뉴가 닫힌다
    expect(screen.queryByText('Profile Settings')).not.toBeInTheDocument();

    openMenu();
    const ev2 = click(linkByHref('/admin/users/u-1'));
    expect(ev2.defaultPrevented).toBe(true);
    expect(dispatch).toHaveBeenCalledTimes(2);
    expect(dispatch).toHaveBeenLastCalledWith({ handler: 'navigate', params: { path: '/admin/users/u-1' } });
  });

  // @scenario component=user_profile
  // @effects plain_click_spa_navigate
  it('onProfileClick 이 있으면 보통 클릭은 그 콜백만 한 번 부른다', () => {
    const onProfileClick = vi.fn();
    render(<UserProfile user={user} onProfileClick={onProfileClick} />);
    openMenu();
    const ev = click(linkByHref('/admin/users/u-1/edit'));
    expect(ev.defaultPrevented).toBe(true);
    expect(onProfileClick).toHaveBeenCalledTimes(1);
    expect(dispatch).not.toHaveBeenCalled();
  });

  // @scenario component=user_profile
  // @effects modified_click_opens_browser_default
  it('Ctrl·Meta 클릭은 막지도 콜백을 부르지도 않는다(브라우저가 새 탭)', () => {
    const onProfileClick = vi.fn();
    render(<UserProfile user={user} onProfileClick={onProfileClick} />);
    openMenu();
    for (const href of ['/admin/users/u-1/edit', '/admin/users/u-1']) {
      for (const init of [{ ctrlKey: true }, { metaKey: true }]) {
        const ev = click(linkByHref(href), init);
        expect(ev.defaultPrevented).toBe(false);
      }
    }
    expect(onProfileClick).not.toHaveBeenCalled();
    expect(dispatch).not.toHaveBeenCalled();
  });
});

// ─────────────────────────────────────────────────────────────────────────────
describe('L4 NotificationCenter — 링크가 있는 알림 항목', () => {
  const notifications: NotificationItem[] = [
    { id: 1, title: '새 주문', message: '주문이 들어왔습니다', time: '1분 전', read: false, url: '/admin/orders/1' },
    { id: 2, title: '공지', message: '링크 없는 알림', time: '2분 전', read: false },
    { id: 3, title: '바깥', message: '바깥 문서', time: '3분 전', read: true, url: 'https://example.com/docs' },
  ];

  const open = (props: Partial<React.ComponentProps<typeof NotificationCenter>> = {}) => {
    render(<NotificationCenter notifications={notifications} titleText="알림" {...props} />);
    fireEvent.click(screen.getByRole('button', { name: '알림' }));
  };

  // @scenario component=notification_center
  // @effects link_roles_preserved
  it('url 이 있는 알림만 링크로 그리고, 삭제 단추는 링크 안에 넣지 않는다', () => {
    open({ onDelete: vi.fn() });
    const links = screen.getAllByRole('link');
    expect(links.map((l) => l.getAttribute('href'))).toEqual(['/admin/orders/1', 'https://example.com/docs']);
    expect(links[0]).toHaveTextContent('새 주문');
    for (const link of links) {
      expect(link.querySelector('button')).toBeNull();
    }
    // 바깥 주소는 새 탭
    expect(links[1]).toHaveAttribute('target', '_blank');
    expect(links[1]).toHaveAttribute('rel', 'noopener noreferrer');
    // 링크 없는 알림은 지금처럼 링크가 아니다
    const plain = screen.getByText('링크 없는 알림').closest('[data-notification-id]') as HTMLElement;
    expect(plain.querySelector('a')).toBeNull();
  });

  // @scenario component=notification_center
  // @effects plain_click_spa_navigate
  it('보통 클릭은 기본 이동을 막고 지금의 알림 클릭 콜백을 한 번 부른 뒤 닫힌다', () => {
    const onNotificationClick = vi.fn();
    const itemOnClick = vi.fn();
    render(
      <NotificationCenter
        notifications={[{ ...notifications[0], onClick: itemOnClick }]}
        titleText="알림"
        onNotificationClick={onNotificationClick}
      />
    );
    fireEvent.click(screen.getByRole('button', { name: '알림' }));
    const ev = click(screen.getByRole('link'));
    expect(ev.defaultPrevented).toBe(true);
    expect(onNotificationClick).toHaveBeenCalledTimes(1);
    expect(onNotificationClick.mock.calls[0][0]).toMatchObject({ id: 1, url: '/admin/orders/1' });
    expect(itemOnClick).toHaveBeenCalledTimes(1);
    expect(screen.queryByText('새 주문')).not.toBeInTheDocument();
  });

  // @scenario component=notification_center
  // @effects modified_click_opens_browser_default
  it('Ctrl·Meta·Shift 클릭은 막지도 콜백을 부르지도 않는다', () => {
    const onNotificationClick = vi.fn();
    open({ onNotificationClick });
    const link = screen.getAllByRole('link')[0];
    for (const init of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }]) {
      const ev = click(link, init);
      expect(ev.defaultPrevented).toBe(false);
    }
    expect(onNotificationClick).not.toHaveBeenCalled();
  });

  // @scenario component=notification_center
  // @effects link_open_marks_notification_read
  it('수정키·가운데 단추로 새 탭에 연 안 읽은 알림은 이동 콜백 없이 읽음 콜백만 부르고, 목록은 열어 둔다', () => {
    const onNotificationClick = vi.fn();
    const onNotificationRead = vi.fn();
    open({ onNotificationClick, onNotificationRead });
    const link = screen.getAllByRole('link')[0];
    expect(click(link, { ctrlKey: true }).defaultPrevented).toBe(false);
    act(() => {
      link.dispatchEvent(new MouseEvent('auxclick', { bubbles: true, cancelable: true, button: 1 }));
    });
    expect(onNotificationRead).toHaveBeenCalledTimes(2);
    expect(onNotificationRead.mock.calls[0][0]).toMatchObject({ id: 1 });
    expect(onNotificationClick).not.toHaveBeenCalled();
    expect(screen.getByText('새 주문')).toBeInTheDocument();
  });

  // @scenario component=notification_center
  // @effects link_open_marks_notification_read
  it('바깥 주소 알림은 보통 클릭도 새 탭에 맡기고(막지 않음) 읽음 콜백을 부른다', () => {
    const onNotificationClick = vi.fn();
    const onNotificationRead = vi.fn();
    render(
      <NotificationCenter
        notifications={[{ ...notifications[2], read: false }]}
        titleText="알림"
        onNotificationClick={onNotificationClick}
        onNotificationRead={onNotificationRead}
      />
    );
    fireEvent.click(screen.getByRole('button', { name: '알림' }));
    expect(click(screen.getByRole('link')).defaultPrevented).toBe(false);
    expect(onNotificationRead).toHaveBeenCalledTimes(1);
    expect(onNotificationRead.mock.calls[0][0]).toMatchObject({ id: 3 });
    expect(onNotificationClick).not.toHaveBeenCalled();
  });

  // @scenario component=notification_center
  // @effects link_open_marks_notification_read
  it('이미 읽은 알림을 새 탭에 열면 읽음 콜백을 부르지 않는다', () => {
    const onNotificationRead = vi.fn();
    open({ onNotificationRead });
    click(screen.getAllByRole('link')[1]);
    expect(onNotificationRead).not.toHaveBeenCalled();
  });

  // @scenario component=notification_center
  // @effects plain_click_spa_navigate
  it('삭제 단추는 링크 밖에 있어 삭제만 부르고 알림 이동은 부르지 않는다', () => {
    const onNotificationClick = vi.fn();
    const onDelete = vi.fn();
    open({ onNotificationClick, onDelete });
    const row = screen.getByText('새 주문').closest('[data-notification-id]') as HTMLElement;
    fireEvent.click(within(row).getByRole('button'));
    expect(onDelete).toHaveBeenCalledTimes(1);
    expect(onNotificationClick).not.toHaveBeenCalled();
  });
});

// ─────────────────────────────────────────────────────────────────────────────
describe('L4 DropdownMenuItem — href 항목', () => {
  // @scenario component=dropdown_menu_item
  // @effects link_roles_preserved
  it('href 가 있으면 role=menuitem 링크로 그리고, 없으면 지금처럼 링크가 아니다', () => {
    render(
      <div>
        <DropdownMenuItem label="수정" icon="pencil" href="/admin/users/5/edit" />
        <DropdownMenuItem label="삭제" icon="trash" variant="danger" />
      </div>
    );
    const item = screen.getByRole('menuitem', { name: '수정' });
    expect(item.tagName).toBe('A');
    expect(item).toHaveAttribute('href', '/admin/users/5/edit');
    expect(item).toHaveClass('flex', 'items-center', 'px-4', 'py-3', 'cursor-pointer');
    expect(screen.queryByRole('menuitem', { name: '삭제' })).toBeNull();
    expect(screen.queryAllByRole('link')).toHaveLength(0);
  });

  // @scenario component=dropdown_menu_item
  // @effects plain_click_spa_navigate
  it('보통 클릭은 기본 이동을 막고 지금의 onClick 을 한 번 부른다', () => {
    const onClick = vi.fn();
    render(<DropdownMenuItem label="수정" href="/admin/users/5/edit" onClick={onClick} />);
    const ev = click(screen.getByRole('menuitem', { name: '수정' }));
    expect(ev.defaultPrevented).toBe(true);
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  // @scenario component=dropdown_menu_item
  // @effects plain_click_spa_navigate
  it('onClick 이 없는 내부 링크의 보통 클릭은 SPA 로 옮긴다', () => {
    render(<DropdownMenuItem label="수정" href="/admin/users/5/edit" />);
    const ev = click(screen.getByRole('menuitem', { name: '수정' }));
    expect(ev.defaultPrevented).toBe(true);
    expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/admin/users/5/edit' } });
  });

  // @scenario component=dropdown_menu_item
  // @effects modified_click_opens_browser_default
  it('Ctrl·Meta 클릭은 막지도 onClick 을 부르지도 않는다', () => {
    const onClick = vi.fn();
    render(<DropdownMenuItem label="수정" href="/admin/users/5/edit" onClick={onClick} />);
    const item = screen.getByRole('menuitem', { name: '수정' });
    for (const init of [{ ctrlKey: true }, { metaKey: true }]) {
      const ev = click(item, init);
      expect(ev.defaultPrevented).toBe(false);
    }
    expect(onClick).not.toHaveBeenCalled();
    expect(dispatch).not.toHaveBeenCalled();
  });

  // @scenario component=dropdown_menu_item
  // @effects disabled_link_has_no_href
  it('disabled 면 href 없이 aria-disabled 메뉴 항목이고 클릭해도 아무 일도 하지 않는다', () => {
    const onClick = vi.fn();
    render(<DropdownMenuItem label="수정" href="/admin/users/5/edit" disabled onClick={onClick} />);
    const item = screen.getByRole('menuitem', { name: '수정' });
    expect(item.getAttribute('href')).toBeNull();
    expect(item).toHaveAttribute('aria-disabled', 'true');
    click(item);
    click(item, { ctrlKey: true });
    expect(onClick).not.toHaveBeenCalled();
    expect(dispatch).not.toHaveBeenCalled();
  });
});

// ─────────────────────────────────────────────────────────────────────────────
describe('L4 CardGrid — cardHref 카드 링크', () => {
  const innerAction = vi.fn();
  const data = [
    { id: 1, slug: 'notice', name: '공지사항' },
    { id: 2, slug: 'free', name: '자유게시판' },
  ];
  const cellChildren: CardGridCellChild[] = [{ type: 'basic', name: 'H3', text: '{{row.name}}' }];

  beforeEach(() => {
    innerAction.mockReset();
    installCore({
      renderItemChildren: (_children: unknown, ctx: { row: any }) => (
        <>
          <h3>{ctx.row.name}</h3>
          <button type="button" onClick={innerAction}>
            {`${ctx.row.name} 설정`}
          </button>
        </>
      ),
      evaluateCondition: () => true,
      getDataBindingEngine: () => ({
        resolveBindings: (expr: string, ctx: { row: any }) =>
          expr.replace(/\{\{\s*row\.(\w+)\s*\}\}/g, (_m, key) => String(ctx.row[key] ?? '')),
      }),
    });
  });

  const renderGrid = (props: Record<string, unknown> = {}) =>
    render(
      <CardGrid
        data={data}
        cardColumns={[{ id: 'card', cellChildren }]}
        cardClassName="rounded-lg p-6 space-y-4"
        pagination={false}
        {...props}
      />
    );

  /** 링크 안에 다른 상호작용 요소가 들어 있으면 HTML 위반(링크 안 단추·스위치) */
  const nestedInteractive = (root: HTMLElement) =>
    root.querySelectorAll('a a, a button, a input, a select, a textarea, a [role="switch"], a [role="button"], a [role="menuitem"]');

  // @scenario component=card_grid
  // @effects card_link_does_not_nest_interactive
  it('cardHref 면 카드는 div 그대로(클래스 유지)이고, 카드 전체를 덮는 링크 하나를 행마다 그린다 — 링크 안에 단추가 없다', () => {
    const { container } = renderGrid({ cardHref: '/admin/boards/{{row.slug}}/edit' });
    const links = screen.getAllByRole('link');
    expect(links.map((l) => l.getAttribute('href'))).toEqual(['/admin/boards/notice/edit', '/admin/boards/free/edit']);
    const card = links[0].parentElement as HTMLElement;
    expect(card.tagName).toBe('DIV');
    expect(card).toHaveClass('rounded-lg', 'p-6', 'space-y-4', 'relative');
    expect(links[0]).toHaveClass('absolute', 'inset-0');
    // 링크 이름 — 이름 식이 없으면 카드 내용 글자
    expect(links[0]).toHaveAccessibleName(expect.stringContaining('공지사항'));
    expect(nestedInteractive(container)).toHaveLength(0);
    expect(screen.getByRole('button', { name: '공지사항 설정' }).closest('a')).toBeNull();
  });

  // @scenario component=card_grid
  // @effects card_link_does_not_nest_interactive
  it('cardLinkLabel 을 주면 카드마다 그 행으로 풀어 덮는 링크의 이름으로 쓴다', () => {
    renderGrid({ cardHref: '/admin/boards/{{row.slug}}/edit', cardLinkLabel: '{{row.name}} 편집' });
    expect(screen.getByRole('link', { name: '공지사항 편집' })).toHaveAttribute('href', '/admin/boards/notice/edit');
    expect(screen.getByRole('link', { name: '자유게시판 편집' })).toBeInTheDocument();
  });

  // @scenario component=card_grid
  // @effects link_roles_preserved
  it('cardHref 가 없으면 지금처럼 링크가 아니다', () => {
    renderGrid();
    expect(screen.queryAllByRole('link')).toHaveLength(0);
  });

  // @scenario component=card_grid
  // @effects plain_click_spa_navigate
  it('카드 링크 보통 클릭은 기본 이동을 막고 SPA 로 한 번 옮긴다', () => {
    renderGrid({ cardHref: '/admin/boards/{{row.slug}}/edit' });
    const ev = click(screen.getAllByRole('link')[0]);
    expect(ev.defaultPrevented).toBe(true);
    expect(dispatch).toHaveBeenCalledTimes(1);
    expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/admin/boards/notice/edit' } });
  });

  // @scenario component=card_grid
  // @effects modified_click_opens_browser_default
  it('카드 링크 Ctrl·Meta 클릭은 막지도 옮기지도 않는다', () => {
    renderGrid({ cardHref: '/admin/boards/{{row.slug}}/edit' });
    for (const init of [{ ctrlKey: true }, { metaKey: true }]) {
      const ev = click(screen.getAllByRole('link')[0], init);
      expect(ev.defaultPrevented).toBe(false);
    }
    expect(dispatch).not.toHaveBeenCalled();
  });

  // @scenario component=card_grid
  // @effects card_link_does_not_nest_interactive
  it('카드 안쪽 단추 클릭은 그 단추만 동작하고 카드 링크로 옮기지 않는다(단추는 링크 밖 위층)', () => {
    renderGrid({ cardHref: '/admin/boards/{{row.slug}}/edit' });
    click(screen.getByRole('button', { name: '공지사항 설정' }));
    expect(innerAction).toHaveBeenCalledTimes(1);
    expect(dispatch).not.toHaveBeenCalled();
  });
});

// ─────────────────────────────────────────────────────────────────────────────
describe('L4 ExtensionCardGrid — 항목 href 카드 링크', () => {
  const item = (over: Partial<ExtensionCardItem> = {}): ExtensionCardItem => ({
    id: 'sirsoft-ckeditor5',
    row: { identifier: 'sirsoft-ckeditor5' },
    name: 'CKEditor5',
    href: '/admin/plugins/sirsoft-ckeditor5',
    primaryAction: { label: '설정' },
    ...over,
  });

  const card = (container: HTMLElement) => container.querySelector('[data-ref="ext_card"]') as HTMLElement;
  const cardLink = (container: HTMLElement) => container.querySelector('[data-ref="ext_card_link"]') as HTMLElement;
  const nestedInteractive = (root: HTMLElement) =>
    root.querySelectorAll('a a, a button, a input, a select, a textarea, a [role="switch"], a [role="button"], a [role="menuitem"]');

  // @scenario component=extension_card_grid
  // @effects card_link_does_not_nest_interactive
  it('href 가 있으면 갤러리·목록 카드는 div 그대로이고 이름이 카드 전체를 덮는 링크다 — 링크 안에 단추·스위치가 없다', () => {
    const withControls = item({ toggle: { checked: true, label: '사용' } as any, actions: [{ id: 'x', label: '삭제' }] as any });
    for (const viewMode of ['gallery', 'list'] as const) {
      const { container, unmount } = render(<ExtensionCardGrid items={[withControls]} viewMode={viewMode} />);
      expect(card(container).tagName).toBe('DIV');
      expect(card(container)).not.toHaveAttribute('role');
      expect(card(container)).toHaveClass('relative', 'rounded-xl', 'p-4', 'cursor-pointer');
      expect(cardLink(container).tagName).toBe('A');
      expect(cardLink(container)).toHaveAttribute('href', '/admin/plugins/sirsoft-ckeditor5');
      expect(cardLink(container)).toHaveTextContent('CKEditor5');
      expect(cardLink(container)).toHaveClass('after:absolute', 'after:inset-0');
      // 제어부는 덮개 위층
      expect(container.querySelector('[data-ref="ext_controls"]')).toHaveClass('relative', 'z-10');
      expect(nestedInteractive(container)).toHaveLength(0);
      unmount();
    }

    const plain = render(<ExtensionCardGrid items={[item({ href: undefined })]} />);
    expect(card(plain.container).tagName).toBe('DIV');
    expect(card(plain.container)).toHaveAttribute('role', 'button');
    expect(cardLink(plain.container)).toBeNull();
  });

  // @scenario component=extension_card_grid
  // @effects plain_click_spa_navigate
  it('카드 링크 보통 클릭은 기본 이동을 막고 지금의 onItemClick 을 원본 행으로 한 번 부른다', () => {
    const onItemClick = vi.fn();
    const { container } = render(<ExtensionCardGrid items={[item()]} onItemClick={onItemClick} />);
    const ev = click(cardLink(container));
    expect(ev.defaultPrevented).toBe(true);
    expect(onItemClick).toHaveBeenCalledTimes(1);
    expect(onItemClick).toHaveBeenCalledWith({ identifier: 'sirsoft-ckeditor5' });
    expect(dispatch).not.toHaveBeenCalled();
  });

  // @scenario component=extension_card_grid
  // @effects modified_click_opens_browser_default
  it('Ctrl·Meta 클릭은 막지도 onItemClick 을 부르지도 않는다', () => {
    const onItemClick = vi.fn();
    const { container } = render(<ExtensionCardGrid items={[item()]} onItemClick={onItemClick} />);
    for (const init of [{ ctrlKey: true }, { metaKey: true }]) {
      const ev = click(cardLink(container), init);
      expect(ev.defaultPrevented).toBe(false);
    }
    expect(onItemClick).not.toHaveBeenCalled();
  });

  // @scenario component=extension_card_grid
  // @effects card_link_does_not_nest_interactive
  it('카드 안 제어 단추는 링크 밖 위층이라 그 단추만 동작하고 카드 링크로 옮기지 않는다', () => {
    const onItemClick = vi.fn();
    const onPrimaryAction = vi.fn();
    const { container } = render(
      <ExtensionCardGrid items={[item()]} onItemClick={onItemClick} onPrimaryAction={onPrimaryAction} />
    );
    const button = within(card(container)).getByRole('button', { name: '설정' });
    expect(button.closest('a')).toBeNull();
    click(button);
    expect(onPrimaryAction).toHaveBeenCalledTimes(1);
    expect(onItemClick).not.toHaveBeenCalled();
  });

  // @scenario component=extension_card_grid
  // @effects plain_click_spa_navigate
  it('보조 단추(secondaryAction)에 href 가 있으면 링크 — 보통 클릭은 onSecondaryAction 1회(카드 클릭 아님), Ctrl 클릭은 0·기본 동작', () => {
    const onSecondaryAction = vi.fn();
    const onItemClick = vi.fn();
    render(
      <ExtensionCardGrid
        items={[item({ href: undefined, secondaryAction: { label: '레이아웃 편집', href: '/admin/templates/sirsoft-basic/edit' } as any })]}
        onSecondaryAction={onSecondaryAction}
        onItemClick={onItemClick}
      />
    );
    const link = screen.getByRole('link', { name: '레이아웃 편집' });
    expect(link).toHaveAttribute('href', '/admin/templates/sirsoft-basic/edit');
    const modified = click(link, { ctrlKey: true });
    expect(modified.defaultPrevented).toBe(false);
    expect(onSecondaryAction).not.toHaveBeenCalled();
    const plain = click(link);
    expect(plain.defaultPrevented).toBe(true);
    expect(onSecondaryAction).toHaveBeenCalledTimes(1);
    expect(onItemClick).not.toHaveBeenCalled();
  });

  // @scenario component=extension_card_grid
  // @effects modified_click_opens_browser_default
  it('주 단추(primaryAction)에 href·target="_blank" 면 새 창 링크 — 보통 클릭도 막지 않고 onPrimaryAction 을 부르지 않는다', () => {
    const onPrimaryAction = vi.fn();
    render(
      <ExtensionCardGrid
        items={[item({ href: undefined, primaryAction: { label: '레이아웃 편집기', href: '/admin/layout-editor/sirsoft-basic', target: '_blank' } as any })]}
        onPrimaryAction={onPrimaryAction}
      />
    );
    const link = screen.getByRole('link', { name: '레이아웃 편집기' });
    expect(link).toHaveAttribute('href', '/admin/layout-editor/sirsoft-basic');
    expect(link).toHaveAttribute('target', '_blank');
    expect(link.getAttribute('rel') ?? '').toContain('noopener');
    expect(click(link).defaultPrevented).toBe(false);
    expect(onPrimaryAction).not.toHaveBeenCalled();
  });

  // @scenario component=extension_card_grid
  // @effects plain_click_spa_navigate
  it('주 단추에 href 가 없으면 종전 단추(설치 등) — 보통 클릭은 onPrimaryAction', () => {
    const onPrimaryAction = vi.fn();
    render(<ExtensionCardGrid items={[item({ href: undefined, primaryAction: { label: '설치' } as any })]} onPrimaryAction={onPrimaryAction} />);
    fireEvent.click(screen.getByRole('button', { name: '설치' }));
    expect(onPrimaryAction).toHaveBeenCalledTimes(1);
  });

  // @scenario component=extension_card_grid
  // @effects card_link_does_not_nest_interactive
  it('카드가 링크여도 보조 단추 href 는 링크(중첩 없음), 비활성이면 종전 단추', () => {
    const off = render(<ExtensionCardGrid items={[item({ href: undefined, secondaryAction: { label: '레이아웃 편집', href: '/admin/x', disabled: true } as any })]} />);
    expect(screen.getByRole('button', { name: '레이아웃 편집' })).toBeDisabled();
    expect(screen.queryByRole('link', { name: '레이아웃 편집' })).toBeNull();
    off.unmount();

    const { container } = render(<ExtensionCardGrid items={[item({ secondaryAction: { label: '레이아웃 편집', href: '/admin/x' } as any })]} />);
    expect(screen.getByRole('link', { name: '레이아웃 편집' })).toHaveAttribute('href', '/admin/x');
    expect(nestedInteractive(container)).toHaveLength(0);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
describe('L4 PageHeader — 바로가기 href', () => {
  // @scenario component=page_header
  // @effects link_roles_preserved
  it('href 가 있는 바로가기는 링크로, 없는 것은 지금처럼 단추로 그린다(클래스 유지)', () => {
    render(
      <PageHeader
        title="회원"
        actions={[
          { id: 1, label: '회원 등록', variant: 'primary', href: '/admin/users/create' },
          { id: 2, label: '내보내기', variant: 'secondary', onClick: vi.fn() },
        ]}
      />
    );
    const link = screen.getByRole('link', { name: '회원 등록' });
    expect(link).toHaveAttribute('href', '/admin/users/create');
    expect(link).toHaveClass('page-action-primary');
    expect(screen.getByRole('button', { name: '내보내기' })).toHaveClass('page-action-secondary');
  });

  // @scenario component=page_header
  // @effects plain_click_spa_navigate
  it('보통 클릭은 기본 이동을 막고 onClick 이 있으면 그것만, 없으면 SPA 로 옮긴다', () => {
    const onClick = vi.fn();
    render(
      <PageHeader
        title="회원"
        actions={[
          { id: 1, label: '회원 등록', href: '/admin/users/create' },
          { id: 2, label: '설정', href: '/admin/settings', onClick },
        ]}
      />
    );
    const ev = click(screen.getByRole('link', { name: '회원 등록' }));
    expect(ev.defaultPrevented).toBe(true);
    expect(dispatch).toHaveBeenCalledTimes(1);
    expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/admin/users/create' } });

    const ev2 = click(screen.getByRole('link', { name: '설정' }));
    expect(ev2.defaultPrevented).toBe(true);
    expect(onClick).toHaveBeenCalledTimes(1);
    expect(dispatch).toHaveBeenCalledTimes(1);
  });

  // @scenario component=page_header
  // @effects modified_click_opens_browser_default
  it('Ctrl·Meta 클릭은 막지도 콜백을 부르지도 않는다', () => {
    const onClick = vi.fn();
    render(<PageHeader title="회원" actions={[{ id: 1, label: '설정', href: '/admin/settings', onClick }]} />);
    const link = screen.getByRole('link', { name: '설정' });
    for (const init of [{ ctrlKey: true }, { metaKey: true }]) {
      const ev = click(link, init);
      expect(ev.defaultPrevented).toBe(false);
    }
    expect(onClick).not.toHaveBeenCalled();
    expect(dispatch).not.toHaveBeenCalled();
  });

  // @scenario component=page_header
  // @effects disabled_link_has_no_href
  it('disabled 바로가기는 href 없이 지금처럼 비활성 단추다', () => {
    render(
      <PageHeader title="회원" actions={[{ id: 1, label: '회원 등록', href: '/admin/users/create', disabled: true }]} />
    );
    expect(screen.queryByRole('link')).toBeNull();
    expect(screen.getByRole('button', { name: '회원 등록' })).toBeDisabled();
  });
});

// ─────────────────────────────────────────────────────────────────────────────
describe('L4 SortableMenuList — itemHref 패널 선택 링크', () => {
  const menus: MenuItem[] = [
    { id: 1, name: '대시보드', slug: 'dashboard', url: '/admin/dashboard', icon: 'home', order: 1, is_active: true, parent_id: null, module_id: null, children: [] },
    { id: 2, name: '설정', slug: 'settings', url: '/admin/settings', icon: 'cog', order: 2, is_active: false, parent_id: null, module_id: null, children: [] },
  ];
  const nestedInteractive = (root: HTMLElement) =>
    root.querySelectorAll('a a, a button, a input, a select, a [role="switch"], a [role="button"], a [aria-roledescription="sortable"]');

  beforeEach(() => {
    installCore({
      getDataBindingEngine: () => ({
        resolveBindings: (expr: string, ctx: { row: any }) =>
          expr.replace(/\{\{\s*row\.(\w+)\s*\}\}/g, (_m, key) => String(ctx.row[key] ?? '')),
      }),
    });
  });

  // @scenario component=sortable_menu_list
  // @effects panel_select_item_is_link
  it('itemHref 를 항목마다 풀어 이름을 링크로 그리고(끌기 금지), 끌기 손잡이·스위치는 링크 밖 위층이다', () => {
    const { container } = render(<SortableMenuList items={menus} itemHref="/admin/menus?id={{row.id}}&mode=edit" />);
    const link = screen.getByRole('link', { name: '대시보드' });
    expect(link).toHaveAttribute('href', '/admin/menus?id=1&mode=edit');
    expect(link).toHaveAttribute('draggable', 'false');
    expect(link).toHaveClass('after:absolute', 'after:inset-0');
    expect(screen.getByRole('link', { name: '설정' })).toHaveAttribute('href', '/admin/menus?id=2&mode=edit');
    expect(nestedInteractive(container)).toHaveLength(0);
    const handle = container.querySelector('[aria-roledescription="sortable"]') as HTMLElement;
    expect(handle).toHaveClass('relative', 'z-10');
    expect(screen.getAllByRole('switch')[0].closest('a')).toBeNull();
  });

  // @scenario component=sortable_menu_list
  // @effects plain_click_spa_navigate
  it('보통 클릭은 기본 이동을 막고 지금의 onSelect 를 그 항목으로 한 번 부른다(행 클릭과 겹쳐 두 번 부르지 않는다)', () => {
    const onSelect = vi.fn();
    render(<SortableMenuList items={menus} itemHref="/admin/menus?id={{row.id}}&mode=edit" onSelect={onSelect} />);
    const ev = click(screen.getByRole('link', { name: '설정' }));
    expect(ev.defaultPrevented).toBe(true);
    expect(onSelect).toHaveBeenCalledTimes(1);
    expect(onSelect.mock.calls[0][0]).toMatchObject({ id: 2 });
    expect(dispatch).not.toHaveBeenCalled();
  });

  // @scenario component=sortable_menu_list
  // @effects modified_click_opens_browser_default
  it('Ctrl·Meta·Shift 클릭은 막지도 onSelect 를 부르지도 않는다(새 탭에서 그 항목 편집 화면)', () => {
    const onSelect = vi.fn();
    render(<SortableMenuList items={menus} itemHref="/admin/menus?id={{row.id}}&mode=edit" onSelect={onSelect} />);
    const link = screen.getByRole('link', { name: '대시보드' });
    for (const init of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }]) {
      expect(click(link, init).defaultPrevented).toBe(false);
    }
    expect(onSelect).not.toHaveBeenCalled();
  });

  // @scenario component=sortable_menu_list
  // @effects merge_query_in_href
  it('itemHrefMergeQuery 면 현재 주소 쿼리를 항목 주소에 합친다', () => {
    render(<SortableMenuList items={menus} itemHref="/admin/menus?id={{row.id}}&mode=edit" itemHrefMergeQuery />);
    expect(screen.getByRole('link', { name: '대시보드' })).toHaveAttribute('href', '/admin/menus?id=1&mode=edit&page=2');
  });

  // @scenario component=sortable_menu_list
  // @effects link_roles_preserved
  it('itemHref 가 없거나 여러 개 선택 모드면 지금처럼 링크가 아니다', () => {
    const { unmount } = render(<SortableMenuList items={menus} />);
    expect(screen.queryAllByRole('link')).toHaveLength(0);
    unmount();
    render(<SortableMenuList items={menus} itemHref="/admin/menus?id={{row.id}}&mode=edit" selectionMode selectedIds={[]} />);
    expect(screen.queryAllByRole('link')).toHaveLength(0);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
describe('L4 FileTree — 주소 있는 파일 항목 링크', () => {
  const nodes: FileTreeNode[] = [
    { key: 'layout:admin_users', kind: 'layout', path: 'admin_users', href: '/admin/templates/t1/edit?route=*/admin/users' },
    { key: 'layout:partials/admin_users/_form', kind: 'layout', path: 'partials/admin_users/_form' },
    { key: 'ext:7', kind: 'extension', path: 'admin_users', label: '확장 조각', group: '게시판', groupKind: 'module' },
  ];

  // @scenario component=file_tree
  // @effects panel_select_item_is_link
  it('href 가 있는 파일만 이름이 행을 덮는 링크(탭 순서 밖)이고, 주소 없는 부분 레이아웃·확장 조각은 링크가 아니다 — 이름 바꾸기·삭제는 링크 밖', () => {
    const { container } = render(<FileTree nodes={nodes} canManage onRename={vi.fn()} onDelete={vi.fn()} />);
    // 링크는 주소 있는 항목 하나뿐 — 부분 레이아웃(접힌 폴더 안)·확장 조각은 href 가 없어 링크로 그리지 않는다
    const links = container.querySelectorAll('a');
    expect(links).toHaveLength(1);
    expect(links[0]).toHaveAttribute('href', '/admin/templates/t1/edit?route=*/admin/users');
    expect(links[0]).toHaveAttribute('tabindex', '-1');
    expect(links[0]).toHaveClass('after:absolute', 'after:inset-0');
    expect(links[0].closest('[role="treeitem"]')).not.toBeNull();
    expect(container.querySelectorAll('a [role="button"], a button')).toHaveLength(0);
  });

  // @scenario component=file_tree
  // @effects plain_click_spa_navigate
  it('링크 보통 클릭은 기본 이동을 막고 지금의 onSelect 를 그 키로 한 번 부른다', () => {
    const onSelect = vi.fn();
    render(<FileTree nodes={nodes} onSelect={onSelect} />);
    const ev = click(screen.getByRole('link'));
    expect(ev.defaultPrevented).toBe(true);
    expect(onSelect).toHaveBeenCalledTimes(1);
    expect(onSelect).toHaveBeenCalledWith('layout:admin_users');
  });

  // @scenario component=file_tree
  // @effects modified_click_opens_browser_default
  it('Ctrl·Meta·Shift 클릭은 막지도 onSelect 를 부르지도 않는다(새 탭에서 그 레이아웃 편집)', () => {
    const onSelect = vi.fn();
    render(<FileTree nodes={nodes} onSelect={onSelect} />);
    for (const init of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }]) {
      expect(click(screen.getByRole('link'), init).defaultPrevented).toBe(false);
    }
    expect(onSelect).not.toHaveBeenCalled();
  });

  // @scenario component=file_tree
  // @effects merge_query_in_href
  it('FileTreePicker hrefMergeQuery 면 현재 주소 쿼리를 항목 주소에 합친다', () => {
    render(<FileTreePicker nodes={nodes} mode="inline" hrefMergeQuery />);
    expect(screen.getByRole('link')).toHaveAttribute('href', '/admin/templates/t1/edit?route=*/admin/users&page=2');
  });
});
