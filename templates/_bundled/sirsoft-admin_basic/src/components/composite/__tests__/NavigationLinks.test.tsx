/**
 * 관리자 템플릿 컴포넌트의 화면 이동 링크 (#757 링크 단계 — L3·L4)
 *
 * 화면 이동이 `<button onClick>` + navigate 라 렌더 시점에 주소가 없어 가운데 클릭·Ctrl+클릭·우클릭 「새 탭에서 열기」가
 * 되지 않았다. 이동 자리를 `<a href>` 로 그리고, 보통 클릭만 지금처럼 SPA 로 옮긴다(나머지는 브라우저 기본 동작).
 *
 * 「보통 클릭인가」·「쿼리 합치기」는 엔진 `G7Core.navigation` 의 함수가 판정한다(W9). 보통 클릭 판정은 엔진의 실제 함수를
 * 주입한다 — 컴포넌트가 링크 요소를 넘겨야 엔진이 target·download 를 본다(도우미가 판정을 다시 쓰지 않는다).
 *
 * @scenario-manifest tests/scenarios/navigation-links.yaml
 *
 * @vitest-environment jsdom
 */

import { render, screen, fireEvent, act } from '@testing-library/react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { A } from '../../basic/A';
import { Pagination } from '../Pagination';
import { ActionMenu } from '../ActionMenu';
import { DataGrid } from '../DataGrid';
import { TabNavigation } from '../TabNavigation';
import { TabNavigationScroll } from '../TabNavigationScroll';
import { AdminSidebar } from '../AdminSidebar';
import { DropdownButton } from '../DropdownButton';
import { CardGrid } from '../CardGrid';
import { ExtensionCardGrid } from '../ExtensionCardGrid';
import { isPlainLeftClick as engineIsPlainLeftClick } from '@core/template-engine/navigationLinks';

let dispatch: ReturnType<typeof vi.fn>;

const installCore = (extra: Record<string, unknown> = {}) => {
  dispatch = vi.fn();
  (window as any).G7Core = {
    t: (key: string) => key,
    dispatch,
    navigation: {
      // 엔진의 실제 판정(수정키·가운데 단추·링크 요소의 target·download) — 링크 요소를 넘겨야 target·download 를 본다
      isPlainLeftClick: engineIsPlainLeftClick,
      mergeQueryIntoHref: (href: string) => `${href}${href.includes('?') ? '&' : '?'}page=2`,
      onComplete: (cb: () => void) => cb(),
    },
    ...extra,
  };
};

beforeEach(() => installCore());
afterEach(() => {
  delete (window as any).G7Core;
});

describe('L3 A — 링크 기본 동작', () => {
  // @scenario component=a
  // @effects plain_click_spa_navigate
  it('액션 없는 내부 링크의 보통 클릭은 기본 이동을 막고 SPA 로 옮긴다', () => {
    render(<A href="/admin/users">회원</A>);
    const link = screen.getByRole('link', { name: '회원' });
    const ev = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
    link.dispatchEvent(ev);
    expect(ev.defaultPrevented).toBe(true);
    expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/admin/users' } });
  });

  // @scenario component=a
  // @effects modified_click_opens_browser_default
  it('target="_blank"·download 링크는 보통 클릭이어도 막지 않는다 — 엔진이 링크 요소의 target·download 를 본다', () => {
    render(
      <>
        <A href="/admin/help" target="_blank">안내</A>
        <A href="/admin/files/a.csv" download>내려받기</A>
      </>,
    );
    for (const name of ['안내', '내려받기']) {
      const ev = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
      screen.getByRole('link', { name }).dispatchEvent(ev);
      expect(ev.defaultPrevented).toBe(false);
    }
    expect(dispatch).not.toHaveBeenCalled();
  });

  // @scenario component=a
  // @effects new_tab_link_has_noopener
  it('target="_blank" 링크는 noopener·noreferrer 중 빠진 것만 더하고 기존 rel 값은 지킨다(같은 창 링크는 그대로, 7.1.3 코어 규칙 통일)', () => {
    render(
      <>
        <A href="/admin/a" target="_blank">맨</A>
        <A href="/admin/b" target="_blank" rel="nofollow">기존</A>
        <A href="/admin/c" target="_blank" rel="noopener">이미</A>
        <A href="/admin/d">같은 창</A>
        <A href="/admin/e" target="_blank" disabled>잠김</A>
      </>,
    );
    const rel = (name: string) => (screen.getByRole('link', { name }).getAttribute('rel') ?? '').split(/\s+/).filter(Boolean).sort();
    expect(rel('맨')).toEqual(['noopener', 'noreferrer']);
    expect(rel('기존')).toEqual(['nofollow', 'noopener', 'noreferrer']);
    // 7.1.3: 템플릿마다 갈렸던 rel 규칙을 코어 하나로 통일 — `noopener` 만 있어도 빠진 `noreferrer` 를 더한다
    expect(rel('이미')).toEqual(['noopener', 'noreferrer']);
    expect(screen.getByRole('link', { name: '같은 창' }).hasAttribute('rel')).toBe(false);
    expect(rel('잠김')).toEqual(['noopener', 'noreferrer']);
  });

  // @scenario component=a
  // @effects modified_click_opens_browser_default
  it('Ctrl·Meta·Shift 클릭과 가운데 단추는 막지도 옮기지도 않는다(브라우저가 새 탭·새 창)', () => {
    render(<A href="/admin/users">회원</A>);
    const link = screen.getByRole('link', { name: '회원' });
    for (const init of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { button: 1 }]) {
      const ev = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
      link.dispatchEvent(ev);
      expect(ev.defaultPrevented).toBe(false);
    }
    expect(dispatch).not.toHaveBeenCalled();
  });

  // @scenario component=a
  // @effects disabled_link_has_no_href
  it('disabled 면 href 가 없고 aria-disabled·tabindex -1, 클릭해도 아무 일도 하지 않는다', () => {
    const onClick = vi.fn();
    render(<A href="/admin/users/create" disabled onClick={onClick}>등록</A>);
    const link = screen.getByRole('link', { name: '등록' });
    expect(link.getAttribute('href')).toBeNull();
    expect(link).toHaveAttribute('aria-disabled', 'true');
    expect(link).toHaveAttribute('tabindex', '-1');
    fireEvent.click(link, { ctrlKey: true });
    fireEvent.click(link);
    expect(onClick).not.toHaveBeenCalled();
    expect(dispatch).not.toHaveBeenCalled();
  });

  // @scenario component=a
  // @effects merge_query_in_href
  it('mergeQuery 면 현재 주소 쿼리를 합친 href 를 그리고, mergeQuery 속성은 DOM 에 새지 않는다', () => {
    render(<A href="/admin/users/5/edit" mergeQuery>수정</A>);
    const link = screen.getByRole('link', { name: '수정' });
    expect(link.getAttribute('href')).toBe('/admin/users/5/edit?page=2');
    expect(link.hasAttribute('mergequery')).toBe(false);
    expect(link.hasAttribute('mergeQuery')).toBe(false);
  });

  // @scenario component=a
  // @effects plain_click_spa_navigate
  it('엔진 액션(onClick)이 있으면 그 액션에 맡기고 A 가 따로 옮기지 않는다', () => {
    const onClick = vi.fn();
    render(<A href="/admin/users" onClick={onClick}>회원</A>);
    fireEvent.click(screen.getByRole('link', { name: '회원' }));
    expect(onClick).toHaveBeenCalledTimes(1);
    expect(dispatch).not.toHaveBeenCalled();
  });

  // @scenario component=a
  // @effects modified_click_opens_browser_default
  it('바깥 주소·target=_blank 는 SPA 로 옮기지 않는다', () => {
    render(
      <div>
        <A href="https://example.com/docs">문서</A>
        <A href="/admin/users" target="_blank">새 창</A>
      </div>
    );
    for (const name of ['문서', '새 창']) {
      const ev = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
      screen.getByRole('link', { name }).dispatchEvent(ev);
      expect(ev.defaultPrevented).toBe(false);
    }
    expect(dispatch).not.toHaveBeenCalled();
  });

  // @scenario component=a
  // @effects plain_click_spa_navigate
  it('판정 API 가 없는 옛 엔진에서는 종전처럼 SPA 로 옮긴다', () => {
    installCore({ navigation: {} });
    render(<A href="/admin/users">회원</A>);
    fireEvent.click(screen.getByRole('link', { name: '회원' }));
    expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/admin/users' } });
  });
});

const plain = () => new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
const ctrl = () => new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ctrlKey: true });

describe('L4 Pagination — pageHref', () => {
  // @scenario component=pagination
  // @effects link_roles_preserved
  it('pageHref 가 있으면 번호·이전·다음이 {page} 를 채운 링크이고 현재 쪽은 aria-current', () => {
    render(<Pagination currentPage={2} totalPages={5} onPageChange={vi.fn()} pageHref="/admin/users?page={page}" />);
    const links = screen.getAllByRole('link');
    expect(links.map((l) => l.getAttribute('href'))).toContain('/admin/users?page=3');
    expect(links.find((l) => l.getAttribute('aria-current') === 'page')?.getAttribute('href')).toBe('/admin/users?page=2');
  });

  // @scenario component=pagination
  // @effects plain_click_spa_navigate
  it('보통 클릭은 onPageChange 1회·기본 이동 막음, Ctrl 클릭은 콜백 0·기본 동작', () => {
    const onPageChange = vi.fn();
    render(<Pagination currentPage={1} totalPages={3} onPageChange={onPageChange} pageHref="/admin/users?page={page}" />);
    const next = screen.getAllByRole('link').find((l) => l.getAttribute('aria-label') === 'common.next_page')!;
    expect(next.getAttribute('href')).toBe('/admin/users?page=2');
    const e1 = ctrl();
    next.dispatchEvent(e1);
    expect(e1.defaultPrevented).toBe(false);
    expect(onPageChange).not.toHaveBeenCalled();
    const e2 = plain();
    next.dispatchEvent(e2);
    expect(e2.defaultPrevented).toBe(true);
    expect(onPageChange).toHaveBeenCalledWith(2);
  });

  // @scenario component=pagination
  // @effects disabled_link_has_no_href
  it('갈 수 없는 이전(1쪽)은 href 없이 aria-disabled, pageHrefMergeQuery 면 현재 쿼리를 합친다', () => {
    render(<Pagination currentPage={1} totalPages={3} onPageChange={vi.fn()} pageHref="/p?page={page}" pageHrefMergeQuery />);
    const prev = screen.getAllByRole('link').find((l) => l.getAttribute('aria-label') === 'common.prev_page')!;
    expect(prev.getAttribute('href')).toBeNull();
    expect(prev).toHaveAttribute('aria-disabled', 'true');
    // 시험 주입 함수는 page=2 를 덧붙인다 — 합치기 함수를 거쳤는지만 본다
    expect(screen.getAllByRole('link').map((l) => l.getAttribute('href'))).toContain('/p?page=2&page=2');
  });

  // @scenario component=pagination
  // @effects plain_click_spa_navigate
  it('pageHref 가 없으면 종전처럼 단추', () => {
    render(<Pagination currentPage={1} totalPages={3} onPageChange={vi.fn()} />);
    expect(screen.queryAllByRole('link')).toHaveLength(0);
  });
});

describe('L4 ActionMenu — 항목 href', () => {
  const open = () => fireEvent.click(screen.getByRole('button'));

  // @scenario component=action_menu
  // @effects link_roles_preserved
  it('href 가 있는 항목은 <a role="menuitem" href>, 없는 항목(삭제)은 단추 그대로', () => {
    render(<ActionMenu items={[{ id: 'edit', label: '수정', href: '/admin/users/u1/edit' }, { id: 'delete', label: '삭제' }] as any} onItemClick={vi.fn()} />);
    open();
    const edit = screen.getByRole('menuitem', { name: '수정' });
    expect(edit.tagName).toBe('A');
    expect(edit.getAttribute('href')).toBe('/admin/users/u1/edit');
    expect(screen.getByRole('menuitem', { name: '삭제' }).tagName).toBe('BUTTON');
  });

  // @scenario component=action_menu
  // @effects modified_click_opens_browser_default
  it('보통 클릭은 onItemClick 1회, Ctrl 클릭은 0·기본 동작', () => {
    const onItemClick = vi.fn();
    render(<ActionMenu items={[{ id: 'edit', label: '수정', href: '/admin/users/u1/edit' }] as any} onItemClick={onItemClick} />);
    open();
    const e1 = ctrl();
    screen.getByRole('menuitem', { name: '수정' }).dispatchEvent(e1);
    expect(e1.defaultPrevented).toBe(false);
    expect(onItemClick).not.toHaveBeenCalled();
    if (!screen.queryByRole('menuitem', { name: '수정' })) open();
    const e2 = plain();
    screen.getByRole('menuitem', { name: '수정' }).dispatchEvent(e2);
    expect(e2.defaultPrevented).toBe(true);
    expect(onItemClick).toHaveBeenCalledTimes(1);
  });

  // @scenario component=action_menu
  // @effects merge_query_in_href
  it('항목 mergeQuery 면 현재 주소 쿼리를 href 에 합친다(목록 조건을 새 탭에도), mergeQuery 는 DOM 에 새지 않는다', () => {
    render(<ActionMenu items={[{ id: 'edit', label: '수정', href: '/admin/users/u1/edit', mergeQuery: true }, { id: 'view', label: '보기', href: '/admin/users/u1' }] as any} onItemClick={vi.fn()} />);
    open();
    const edit = screen.getByRole('menuitem', { name: '수정' });
    expect(edit.getAttribute('href')).toBe('/admin/users/u1/edit?page=2');
    expect(edit.hasAttribute('mergequery')).toBe(false);
    expect(screen.getByRole('menuitem', { name: '보기' }).getAttribute('href')).toBe('/admin/users/u1');
  });

  // @scenario component=action_menu
  // @effects action_menu_survives_pending_scroll
  it('열기 직전 스크롤의 늦게 온 scroll 이벤트로는 닫히지 않는다 — 트리거가 실제로 움직였을 때만 닫는다', () => {
    // 표 오른쪽 열처럼 가로 스크롤 안쪽의 단추: 브라우저가 단추를 보이게 스크롤한 뒤 클릭이 오고, 그 스크롤의 scroll
    // 이벤트는 다음 프레임에 늦게 온다(실측 2026-10-15 쿠폰 목록 「작업」 — mousedown 66ms · click 67ms · scroll 72ms → 닫힘)
    render(<ActionMenu items={[{ id: 'edit', label: '수정', href: '/admin/x' }] as any} onItemClick={vi.fn()} />);
    const trigger = screen.getByRole('button');
    let rect = { top: 100, bottom: 132, left: 900, right: 932, width: 32, height: 32, x: 900, y: 100, toJSON: () => ({}) };
    trigger.getBoundingClientRect = () => rect as DOMRect;
    open();
    expect(trigger).toHaveAttribute('aria-expanded', 'true');

    // 위치가 그대로인 채 온 scroll 이벤트(열기 전에 끝난 스크롤) — 열린 채
    act(() => {
      document.querySelector('.overflow-x-auto')?.dispatchEvent(new Event('scroll'));
      window.dispatchEvent(new Event('scroll'));
    });
    expect(trigger).toHaveAttribute('aria-expanded', 'true');

    // 열린 뒤 실제로 스크롤돼 트리거가 움직이면 — 메뉴가 엉뚱한 자리에 떠 있지 않게 닫는다
    rect = { ...rect, left: 700, right: 732, x: 700 };
    act(() => {
      window.dispatchEvent(new Event('scroll'));
    });
    expect(trigger).toHaveAttribute('aria-expanded', 'false');
  });

  // @scenario component=action_menu
  // @effects modified_click_opens_browser_default
  it('항목 target="_blank" 면 새 창 링크(rel noopener) — 보통 클릭도 막지 않고 항목 동작을 부르지 않으며 메뉴를 닫는다', () => {
    const onItemClick = vi.fn();
    render(<ActionMenu items={[{ id: 'member', label: '회원 보기', href: '/admin/users/u1', target: '_blank' }] as any} onItemClick={onItemClick} />);
    open();
    const item = screen.getByRole('menuitem', { name: '회원 보기' });
    expect(item.getAttribute('target')).toBe('_blank');
    expect(item.getAttribute('rel') ?? '').toContain('noopener');
    const ev = plain();
    act(() => {
      item.dispatchEvent(ev);
    });
    expect(ev.defaultPrevented).toBe(false);
    expect(onItemClick).not.toHaveBeenCalled();
    expect(screen.queryByRole('menuitem', { name: '회원 보기' })).toBeNull();
  });
});

describe('L4 DataGrid — rowActions href·pageHref', () => {
  // @scenario component=data_grid
  // @effects link_roles_preserved
  it('rowActions 의 href 를 행마다 평가해 행 메뉴 링크로 그린다', () => {
    installCore({
      getDataBindingEngine: () => ({
        resolveBindings: (tpl: string, ctx: any) =>
          tpl.replace(/\{\{\s*row\.(\w+)\s*\}\}/g, (_m: string, k: string) => String(ctx.row?.[k] ?? '')),
      }),
    });
    render(
      <DataGrid
        columns={[{ field: 'name', header: '이름' }]}
        data={[{ id: 1, uuid: 'u-1', name: '홍길동' }]}
        idField="id"
        rowActions={[{ id: 'edit', label: '수정', href: '/admin/users/{{row.uuid}}/edit' }, { id: 'delete', label: '삭제' }] as any}
        onRowAction={vi.fn()}
      />
    );
    fireEvent.click(screen.getAllByRole('button').find((b) => b.getAttribute('aria-haspopup') === 'menu')!);
    expect(screen.getByRole('menuitem', { name: '수정' }).getAttribute('href')).toBe('/admin/users/u-1/edit');
  });

  // @scenario component=data_grid
  // @effects link_roles_preserved
  it('pageHref 를 내장 페이지 번호에 넘긴다', () => {
    render(
      <DataGrid
        columns={[{ field: 'name', header: '이름' }]}
        data={[{ id: 1, name: 'a' }]}
        idField="id"
        pagination
        serverSidePagination
        serverCurrentPage={1}
        serverTotalPages={3}
        onPageChange={vi.fn()}
        pageHref="/admin/users?page={page}"
        {...({} as any)}
      />
    );
    expect(screen.getAllByRole('link').map((l) => l.getAttribute('href'))).toContain('/admin/users?page=2');
  });
});

describe('L4 CardGrid · ExtensionCardGrid — pageHref (쪽 번호 링크)', () => {
  // @scenario component=card_grid
  // @effects merge_query_in_href
  it('CardGrid 는 pageHref 를 쪽 번호에 넘기고, 보통 클릭은 onPageChange 1회·Ctrl 클릭은 0', () => {
    const onPageChange = vi.fn();
    render(
      <CardGrid
        data={[{ id: 1, name: 'a' }]}
        cardColumns={[{ field: 'name' } as any]}
        pagination
        serverSidePagination
        serverCurrentPage={1}
        serverTotalPages={3}
        onPageChange={onPageChange}
        {...({ pageHref: '/admin/boards?page={page}', pageHrefMergeQuery: true } as any)}
      />
    );
    const hrefs = screen.getAllByRole('link').map((l) => l.getAttribute('href'));
    expect(hrefs).toContain('/admin/boards?page=2&page=2');
    const page3 = screen.getAllByRole('link').find((l) => l.getAttribute('href')?.startsWith('/admin/boards?page=3'))!;
    fireEvent.click(page3, { ctrlKey: true });
    expect(onPageChange).not.toHaveBeenCalled();
    fireEvent.click(page3);
    expect(onPageChange).toHaveBeenCalledTimes(1);
    expect(onPageChange).toHaveBeenCalledWith(3);
  });

  // @scenario component=extension_card_grid
  // @effects merge_query_in_href
  it('ExtensionCardGrid 도 pageHref 를 쪽 번호에 넘긴다', () => {
    render(
      <ExtensionCardGrid
        items={[{ id: 'm1', name: '모듈' }]}
        pagination
        currentPage={1}
        totalPages={3}
        onPageChange={vi.fn()}
        {...({ pageHref: '/admin/modules?page={page}' } as any)}
      />
    );
    expect(screen.getAllByRole('link').map((l) => l.getAttribute('href'))).toContain('/admin/modules?page=2');
  });
});

describe('L4 TabNavigation · TabNavigationScroll — 탭 href', () => {
  // @scenario component=tab_navigation
  // @effects link_roles_preserved
  it('href 가 있는 탭은 <a role="tab" href>, 보통 클릭은 onTabChange 1회, Ctrl 클릭은 0', () => {
    const onTabChange = vi.fn();
    render(
      <TabNavigation
        tabs={[{ id: 'general', label: '일반', href: '/admin/settings?tab=general' }, { id: 'mail', label: '메일', href: '/admin/settings?tab=mail' }] as any}
        activeTabId="general"
        onTabChange={onTabChange}
      />
    );
    const mail = screen.getByRole('tab', { name: '메일' });
    expect(mail.tagName).toBe('A');
    expect(mail.getAttribute('href')).toBe('/admin/settings?tab=mail');
    const e1 = ctrl();
    mail.dispatchEvent(e1);
    expect(e1.defaultPrevented).toBe(false);
    expect(onTabChange).not.toHaveBeenCalled();
    const e2 = plain();
    mail.dispatchEvent(e2);
    expect(e2.defaultPrevented).toBe(true);
    expect(onTabChange).toHaveBeenCalledWith('mail');
  });

  // @scenario component=tab_navigation
  // @effects link_roles_preserved
  it('TabNavigationScroll 도 href 가 있는 탭은 링크', () => {
    (window as any).IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
    render(<TabNavigationScroll tabs={[{ id: 'a', label: '기본', href: '/admin/x?tab=a' }] as any} activeTabId="a" />);
    expect(screen.getByRole('tab', { name: '기본' }).getAttribute('href')).toBe('/admin/x?tab=a');
  });

  // @scenario component=tab_navigation
  // @effects modified_click_opens_browser_default
  it('TabNavigationScroll 탭 링크 보통 클릭은 onTabChange 1회·기본 이동 막음, Ctrl·Meta 클릭은 콜백 0·기본 동작', () => {
    (window as any).IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
    const onTabChange = vi.fn();
    render(
      <TabNavigationScroll
        tabs={[{ id: 'a', label: '기본', href: '/admin/x?tab=a' }, { id: 'b', label: '고급', href: '/admin/x?tab=b' }] as any}
        activeTabId="a"
        onTabChange={onTabChange}
      />
    );
    const tab = screen.getByRole('tab', { name: '고급' });
    for (const init of [{ ctrlKey: true }, { metaKey: true }]) {
      const ev = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
      tab.dispatchEvent(ev);
      expect(ev.defaultPrevented).toBe(false);
    }
    expect(onTabChange).not.toHaveBeenCalled();
    const ev = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
    tab.dispatchEvent(ev);
    expect(ev.defaultPrevented).toBe(true);
    expect(onTabChange).toHaveBeenCalledTimes(1);
    expect(onTabChange).toHaveBeenCalledWith('b');
  });
});

describe('L4 AdminSidebar — 메뉴 링크', () => {
  // @scenario component=admin_sidebar
  // @effects plain_click_spa_navigate
  it('URL 있는 메뉴는 <a href>, 보통 클릭은 SPA navigate, Ctrl 클릭은 navigate 0·기본 동작', () => {
    render(<AdminSidebar menu={[{ id: 1, name: '회원 관리', slug: 'users', url: '/admin/users' }]} />);
    const link = screen.getByRole('link', { name: '회원 관리' });
    expect(link.getAttribute('href')).toBe('/admin/users');
    const e1 = ctrl();
    link.dispatchEvent(e1);
    expect(e1.defaultPrevented).toBe(false);
    expect(dispatch).not.toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/admin/users' } });
    const e2 = plain();
    link.dispatchEvent(e2);
    expect(e2.defaultPrevented).toBe(true);
    expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/admin/users' } });
  });

  // @scenario component=admin_sidebar
  // @effects link_roles_preserved
  it('현재 주소의 메뉴(하위 메뉴 포함)만 aria-current="page" 다', () => {
    window.history.pushState({}, '', '/admin/users');
    try {
      render(
        <AdminSidebar
          menu={[
            { id: 1, name: '회원', slug: 'members', url: null, children: [
              { id: 2, name: '회원 목록', slug: 'users', url: '/admin/users' },
              { id: 3, name: '역할', slug: 'roles', url: '/admin/roles' },
            ] },
            { id: 4, name: '설정', slug: 'settings', url: '/admin/settings' },
          ] as any}
        />
      );
      expect(screen.getByRole('link', { name: '회원 목록' })).toHaveAttribute('aria-current', 'page');
      expect(screen.getByRole('link', { name: '역할' })).not.toHaveAttribute('aria-current');
      expect(screen.getByRole('link', { name: '설정' })).not.toHaveAttribute('aria-current');
    } finally {
      window.history.pushState({}, '', '/');
    }
  });

  // @scenario component=admin_sidebar
  // @effects modified_click_opens_browser_default
  it('실행 스킴 주소는 링크로 그리지 않고, 바깥 https 는 새 탭 링크', () => {
    render(
      <AdminSidebar
        menu={[
          { id: 1, name: '나쁜 메뉴', slug: 'x', url: 'javascript:alert(1)' },
          { id: 2, name: '도움말', slug: 'help', url: 'https://example.com/help' },
        ]}
      />
    );
    expect(screen.queryByRole('link', { name: '나쁜 메뉴' })).toBeNull();
    const help = screen.getByRole('link', { name: '도움말' });
    expect(help).toHaveAttribute('target', '_blank');
    expect(help.getAttribute('rel')).toContain('noopener');
  });
});

// ─────────────────────────────────────────────────────────────────────────────
describe('DropdownButton — 늦게 온 scroll 이벤트', () => {
  // @scenario component=dropdown_button
  // @effects action_menu_survives_pending_scroll
  it('열기 직전 스크롤의 늦은 scroll 이벤트로는 닫히지 않고, 트리거가 움직이면 닫힌다(ActionMenu 와 같은 규칙)', () => {
    render(<DropdownButton label="더보기"><span>메뉴 내용</span></DropdownButton>);
    const trigger = screen.getByRole('button', { name: '더보기' });
    let rect = { top: 100, bottom: 132, left: 900, right: 980, width: 80, height: 32, x: 900, y: 100, toJSON: () => ({}) };
    trigger.getBoundingClientRect = () => rect as DOMRect;
    fireEvent.click(trigger);
    expect(screen.getByText('메뉴 내용')).toBeInTheDocument();
    act(() => {
      window.dispatchEvent(new Event('scroll'));
    });
    expect(screen.queryByText('메뉴 내용')).toBeInTheDocument();
    rect = { ...rect, top: 40, bottom: 72, y: 40 };
    act(() => {
      window.dispatchEvent(new Event('scroll'));
    });
    expect(screen.queryByText('메뉴 내용')).toBeNull();
  });
});
