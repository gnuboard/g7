/**
 * 쿼리만 바뀐 같은 화면에서 `mergeQuery` 링크 href 가 새 쿼리를 따라가는지 (#757 링크 단계 후속, 7.1.1 회귀)
 *
 * `mergeQuery` 링크는 렌더할 때 현재 주소의 쿼리를 href 에 합친다. 같은 화면에서 쿼리만 바꾸는 `navigate replace`
 * (TemplateApp.updateQueryParams — `history.replaceState` 뒤 다시 그리기)에서는 링크 props 가 그대로라 DynamicRenderer
 * memo·props 참조 안정화로 링크가 다시 그려지지 않아, 가운데 클릭·Ctrl+클릭·새 탭·주소 복사가 옛 쿼리로 갔다.
 * 링크는 엔진 `G7Core.navigation.subscribeLocationSearch` 로 주소 교체 알림(`notifyLocationReplaced`)을 구독해 다시 그린다.
 *
 * 엔진 함수는 실제 것을 주입한다(주소 합치기·구독·알림). 엔진의 알림 호출(updateQueryParams 의 replaceState 직후)은
 * 시험이 직접 부른다.
 * 엔진 DynamicRenderer 가 props 가 같은 링크를 다시 그리지 않는 조건은 비교 함수가 늘 같다고 답하는 memo 부모(`Frozen`)로
 * 세운다 — 이 폴더의 시험에서는 엔진과 템플릿이 서로 다른 React 사본을 읽어 DynamicRenderer 로 템플릿 훅을 그릴 수 없다.
 *
 * @scenario-manifest tests/scenarios/navigation-links.yaml
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { render, screen, fireEvent, act, cleanup } from '@testing-library/react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { A } from '../../basic/A';
import { Pagination } from '../Pagination';
import { ActionMenu } from '../ActionMenu';
import { FileTree, type FileTreeNode } from '../FileTree';
import { SortableMenuList, type MenuItem } from '../SortableMenuList';
import {
  isPlainLeftClick as engineIsPlainLeftClick,
  mergeQueryIntoHref as engineMergeQueryIntoHref,
  notifyLocationReplaced,
  subscribeLocationSearch as engineSubscribeLocationSearch,
} from '@core/template-engine/navigationLinks';

let subscribeSpy: ReturnType<typeof vi.fn>;

const installCore = () => {
  subscribeSpy = vi.fn((cb: () => void) => engineSubscribeLocationSearch(cb));
  (window as any).G7Core = {
    t: (key: string) => key,
    dispatch: vi.fn(),
    navigation: {
      isPlainLeftClick: engineIsPlainLeftClick,
      mergeQueryIntoHref: engineMergeQueryIntoHref,
      subscribeLocationSearch: subscribeSpy,
      onComplete: (cb: () => void) => cb(),
    },
  };
};

/** updateQueryParams 와 같은 순서 — replaceState 로 주소를 바꾸고 엔진이 알린다 */
const replaceQuery = (path: string) => {
  act(() => {
    window.history.replaceState(null, '', path);
    notifyLocationReplaced();
  });
};

/** props 가 같으면 다시 그리지 않는 부모 — DynamicRenderer memo 와 같은 조건 */
const Frozen = React.memo(({ children }: { children: React.ReactNode }) => <>{children}</>, () => true);

beforeEach(() => {
  installCore();
  window.history.replaceState(null, '', '/admin/x?period=7d');
});
afterEach(() => {
  cleanup();
  delete (window as any).G7Core;
  window.history.replaceState(null, '', '/');
});

describe('mergeQuery 링크 — 같은 화면 쿼리 교체 뒤 href', () => {
  // @scenario component=a
  // @effects merge_query_href_follows_query_replace
  it('A: 부모가 다시 그리지 않아도 주소 교체 알림·뒤로/앞으로(popstate) 뒤 href 가 새 쿼리다', () => {
    render(<Frozen><A href="/admin/users/5/edit" mergeQuery>수정</A></Frozen>);
    const link = () => screen.getByRole('link', { name: '수정' });
    expect(link().getAttribute('href')).toBe('/admin/users/5/edit?period=7d');

    replaceQuery('/admin/x?period=30d&page=3');
    expect(link().getAttribute('href')).toBe('/admin/users/5/edit?period=30d&page=3');

    act(() => {
      window.history.replaceState(null, '', '/admin/x?period=1d');
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    expect(link().getAttribute('href')).toBe('/admin/users/5/edit?period=1d');
  });

  // @scenario component=pagination
  // @effects merge_query_href_follows_query_replace
  it('Pagination: pageHrefMergeQuery 쪽 번호 링크가 주소 교체 뒤 새 쿼리를 합친다', () => {
    render(
      <Frozen>
        <Pagination currentPage={1} totalPages={3} onPageChange={vi.fn()} pageHref="/admin/users?page={page}" pageHrefMergeQuery />
      </Frozen>,
    );
    const hrefs = () => screen.getAllByRole('link').map((l) => l.getAttribute('href'));
    expect(hrefs()).toContain('/admin/users?period=7d&page=2');

    replaceQuery('/admin/users?search=kim&page=1');
    expect(hrefs()).toContain('/admin/users?search=kim&page=2');
    expect(hrefs().some((h) => h?.includes('period=7d'))).toBe(false);
  });

  // @scenario component=action_menu
  // @effects merge_query_href_follows_query_replace
  it('ActionMenu: 열린 메뉴의 mergeQuery 항목이 주소 교체 뒤 새 쿼리를 합친다', () => {
    render(
      <Frozen>
        <ActionMenu items={[{ id: 'edit', label: '수정', href: '/admin/users/u1/edit', mergeQuery: true }] as any} onItemClick={vi.fn()} />
      </Frozen>,
    );
    fireEvent.click(screen.getByRole('button'));
    const edit = () => screen.getByRole('menuitem', { name: '수정' });
    expect(edit().getAttribute('href')).toBe('/admin/users/u1/edit?period=7d');

    replaceQuery('/admin/users?status=active');
    expect(edit().getAttribute('href')).toBe('/admin/users/u1/edit?status=active');
  });

  // @scenario component=file_tree
  // @effects merge_query_href_follows_query_replace
  it('FileTree: hrefMergeQuery 파일 링크가 주소 교체 뒤 새 쿼리를 합친다', () => {
    const nodes: FileTreeNode[] = [{ key: 'layout:admin_users', kind: 'layout', path: 'admin_users', href: '/admin/templates/t1/edit?route=admin_users' }];
    const { container } = render(<Frozen><FileTree nodes={nodes} hrefMergeQuery /></Frozen>);
    const href = () => container.querySelector('a')?.getAttribute('href');
    expect(href()).toBe('/admin/templates/t1/edit?period=7d&route=admin_users');

    replaceQuery('/admin/templates/t1/edit?route=admin_users&mode=json');
    expect(href()).toBe('/admin/templates/t1/edit?route=admin_users&mode=json');
  });

  // @scenario component=sortable_menu_list
  // @effects merge_query_href_follows_query_replace
  it('SortableMenuList: itemHrefMergeQuery 항목 링크가 주소 교체 뒤 새 쿼리를 합친다', () => {
    (window as any).G7Core.getDataBindingEngine = () => ({
      resolveBindings: (expr: string, ctx: { row: any }) => expr.replace(/\{\{\s*row\.(\w+)\s*\}\}/g, (_m, key) => String(ctx.row[key] ?? '')),
    });
    const menus: MenuItem[] = [
      { id: 1, name: '대시보드', slug: 'dashboard', url: '/admin/dashboard', icon: 'home', order: 1, is_active: true, parent_id: null, module_id: null, children: [] } as any,
    ];
    render(<Frozen><SortableMenuList items={menus} itemHref="/admin/menus?id={{row.id}}" itemHrefMergeQuery /></Frozen>);
    const href = () => screen.getByRole('link', { name: '대시보드' }).getAttribute('href');
    expect(href()).toBe('/admin/menus?period=7d&id=1');

    replaceQuery('/admin/menus?search=set');
    expect(href()).toBe('/admin/menus?search=set&id=1');
  });

  // @scenario component=a
  // @effects merge_query_href_follows_query_replace
  it('mergeQuery 가 없는 링크·쪽 번호는 주소를 구독하지 않는다(일반 렌더 비용 그대로)', () => {
    render(
      <>
        <A href="/admin/users">회원</A>
        <Pagination currentPage={1} totalPages={3} onPageChange={vi.fn()} pageHref="/admin/users?page={page}" />
        <ActionMenu items={[{ id: 'view', label: '보기', href: '/admin/users/u1' }] as any} onItemClick={vi.fn()} />
      </>,
    );
    expect(subscribeSpy).not.toHaveBeenCalled();
  });

  // @scenario component=a
  // @effects merge_query_href_follows_query_replace
  it('구독 API 가 없는 옛 엔진에서도 그려지고, 뒤로/앞으로(popstate)에는 새 쿼리를 합친다', () => {
    delete (window as any).G7Core.navigation.subscribeLocationSearch;
    render(<Frozen><A href="/admin/users/5/edit" mergeQuery>수정</A></Frozen>);
    const link = () => screen.getByRole('link', { name: '수정' });
    expect(link().getAttribute('href')).toBe('/admin/users/5/edit?period=7d');

    act(() => {
      window.history.replaceState(null, '', '/admin/x?period=1d');
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    expect(link().getAttribute('href')).toBe('/admin/users/5/edit?period=1d');
  });
});
