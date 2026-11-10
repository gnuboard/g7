/**
 * 하위 폴더 배치에서 링크 컴포넌트가 그리는 href 의 사이트 마운트 접두사 (#609 D2)
 *
 * 하위 폴더(`G7Config.basePath = '/g7'`) 설치에서 탭·쪽 번호·행 메뉴·바로가기·드롭다운 메뉴 항목이 `<a href>` 에 루트 상대
 * 주소(`/admin/schedules`)를 접두사 없이 그렸다. 보통 클릭은 엔진이 가로채 SPA 로 맞게 가지만 가운데 클릭·Ctrl+클릭·
 * 「새 탭에서 열기」·링크 복사는 이 값을 그대로 열어 사이트 밖(`http://host/admin/schedules`, 404)으로 갔다.
 *
 * 계약:
 * - 그리는 href 의 루트 상대 경로는 `sitePath()` 로 접두사를 붙인다(`A` 컴포넌트와 같은 방식, 멱등).
 * - 보통 클릭이 SPA 로 넘기는 값은 접두사 없는 앱 경로 그대로다(라우터가 주소창에 쓸 때 붙인다 — 이중 접두 금지).
 * - 루트 설치(basePath 없음)에서는 종전 그대로, 바깥 주소·쿼리만인 href 는 손대지 않는다.
 *
 * `sitePath()` 는 코어가 있으면 `G7Core.url.api` 에 맡기고 없으면 `G7Config.basePath` 를 읽는다 — 두 경로를 같은 사례로 돌린다.
 *
 * @scenario-manifest tests/scenarios/navigation-links.yaml
 *
 * @vitest-environment jsdom
 */

import { render, screen, fireEvent, act, cleanup } from '@testing-library/react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { A } from '../../basic/A';
import { Pagination } from '../Pagination';
import { ActionMenu } from '../ActionMenu';
import { TabNavigation } from '../TabNavigation';
import { TabNavigationScroll } from '../TabNavigationScroll';
import { DropdownMenuItem } from '../DropdownMenuItem';
import { PageHeader } from '../PageHeader';
import { Breadcrumb } from '../Breadcrumb';
import { NotificationCenter, type NotificationItem } from '../NotificationCenter';
import { ExtensionCardGrid, type ExtensionCardItem } from '../ExtensionCardGrid';
import { isPlainLeftClick as engineIsPlainLeftClick } from '@core/template-engine/navigationLinks';

let dispatch: ReturnType<typeof vi.fn>;

/** 코어 `withBasePrefix` 와 같은 결과를 내는 시험용 위임 대상 — 코어 경로(`G7Core.url.api`)를 탈 때 쓴다 */
const coreApi = (path: string): string => {
  const p = path.trim();
  if (!p.startsWith('/') || p.startsWith('//')) return p;
  if (/^\/g7(?=$|[/?#])/.test(p)) return p;

  return `/g7${p}`;
};

const installCore = (withUrlApi: boolean) => {
  dispatch = vi.fn();
  (window as any).G7Core = {
    t: (key: string) => key,
    dispatch,
    navigation: {
      isPlainLeftClick: engineIsPlainLeftClick,
      onComplete: (cb: () => void) => cb(),
    },
    ...(withUrlApi ? { url: { api: coreApi } } : {}),
  };
};

const click = (el: Element, init: MouseEventInit = {}): MouseEvent => {
  const ev = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
  act(() => {
    el.dispatchEvent(ev);
  });

  return ev;
};

/** 쪽 번호의 「다음」 링크(1쪽에서 2쪽) */
const nextLink = (): HTMLElement => screen.getAllByRole('link').find((l) => l.getAttribute('aria-label') === 'common.next_page')!;

const setBasePath = (value: string | undefined) => {
  const cfg = ((globalThis as any).G7Config ??= {});
  if (value === undefined) delete cfg.basePath;
  else cfg.basePath = value;
};

afterEach(() => {
  cleanup();
  setBasePath(undefined);
  delete (window as any).G7Core;
});

const MODES: Array<[string, boolean]> = [
  ['G7Config.basePath 폴백', false],
  ['G7Core.url.api 위임', true],
];

describe.each(MODES)('하위 폴더(/g7) — 그리는 href 에 접두사 (%s)', (_label, withUrlApi) => {
  beforeEach(() => {
    setBasePath('/g7');
    installCore(withUrlApi);
  });

  // @scenario component=tab_navigation
  // @effects href_has_site_mount_prefix
  it('TabNavigation 탭 href 는 /g7 를 붙이고, 보통 클릭은 onTabChange 만 부른다', () => {
    const onTabChange = vi.fn();
    render(
      <TabNavigation
        tabs={[
          { id: 'schedules', label: '스케줄 작업', href: '/admin/schedules' },
          { id: 'queue', label: '큐', href: '/admin/schedules/queue?view=pending' },
        ] as any}
        activeTabId="queue"
        onTabChange={onTabChange}
      />,
    );
    const tab = screen.getByRole('tab', { name: '스케줄 작업' });
    expect(tab.getAttribute('href')).toBe('/g7/admin/schedules');
    expect(screen.getByRole('tab', { name: '큐' }).getAttribute('href')).toBe('/g7/admin/schedules/queue?view=pending');
    expect(click(tab).defaultPrevented).toBe(true);
    expect(onTabChange).toHaveBeenCalledWith('schedules');
  });

  // @scenario component=tab_navigation
  // @effects href_has_site_mount_prefix
  it('TabNavigationScroll 탭 href 도 /g7 를 붙인다', () => {
    (window as any).IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
    render(<TabNavigationScroll tabs={[{ id: 'a', label: '기본', href: '/admin/settings?tab=a' }] as any} activeTabId="a" />);
    expect(screen.getByRole('tab', { name: '기본' }).getAttribute('href')).toBe('/g7/admin/settings?tab=a');
  });

  // @scenario component=pagination
  // @effects href_has_site_mount_prefix
  it('Pagination 쪽 번호 href 는 /g7 를 붙이고(쿼리만인 pageHref 는 그대로), 보통 클릭은 onPageChange 만 부른다', () => {
    const onPageChange = vi.fn();
    const { unmount } = render(
      <Pagination currentPage={1} totalPages={3} onPageChange={onPageChange} pageHref="/admin/users?page={page}" />,
    );
    const page2 = nextLink();
    expect(page2.getAttribute('href')).toBe('/g7/admin/users?page=2');
    expect(click(page2).defaultPrevented).toBe(true);
    expect(onPageChange).toHaveBeenCalledWith(2);
    expect(dispatch).not.toHaveBeenCalled();
    unmount();

    render(<Pagination currentPage={1} totalPages={3} onPageChange={vi.fn()} pageHref="?page={page}" />);
    expect(nextLink().getAttribute('href')).toBe('?page=2');
  });

  // @scenario component=action_menu
  // @effects href_has_site_mount_prefix
  it('ActionMenu 항목 href 는 /g7 를 붙이고 바깥 주소는 그대로', () => {
    render(
      <ActionMenu
        items={[
          { id: 'edit', label: '수정', href: '/admin/users/u1/edit' },
          { id: 'doc', label: '문서', href: 'https://example.com/docs' },
        ] as any}
        onItemClick={vi.fn()}
      />,
    );
    fireEvent.click(screen.getByRole('button'));
    expect(screen.getByRole('menuitem', { name: '수정' }).getAttribute('href')).toBe('/g7/admin/users/u1/edit');
    expect(screen.getByRole('menuitem', { name: '문서' }).getAttribute('href')).toBe('https://example.com/docs');
  });

  // @scenario component=dropdown_menu_item
  // @effects href_has_site_mount_prefix
  it('DropdownMenuItem href 는 /g7 를 붙이고, 보통 클릭의 SPA 이동은 접두사 없는 경로로 한다', () => {
    render(<DropdownMenuItem label="수정" href="/admin/users/5/edit" />);
    const item = screen.getByRole('menuitem', { name: '수정' });
    expect(item.getAttribute('href')).toBe('/g7/admin/users/5/edit');
    expect(click(item).defaultPrevented).toBe(true);
    expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/admin/users/5/edit' } });
  });

  // @scenario component=page_header
  // @effects href_has_site_mount_prefix
  it('PageHeader 바로가기 href 는 /g7 를 붙이고, 보통 클릭의 SPA 이동은 접두사 없는 경로로 한다', () => {
    render(<PageHeader title="회원" actions={[{ id: 1, label: '회원 등록', href: '/admin/users/create' }]} />);
    const link = screen.getByRole('link', { name: '회원 등록' });
    expect(link.getAttribute('href')).toBe('/g7/admin/users/create');
    expect(click(link).defaultPrevented).toBe(true);
    expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/admin/users/create' } });
  });

  // ── 이미 `A` 를 거쳐 접두사를 붙이던 자리 — 회귀 잠금 ──

  // @scenario component=a
  // @effects href_has_site_mount_prefix
  it('A 는 /g7 를 붙이고(이미 붙은 값은 그대로), 보통 클릭의 SPA 이동은 접두사 없는 경로로 한다', () => {
    render(
      <>
        <A href="/admin/users">회원</A>
        <A href="/g7/admin/roles">역할</A>
        <A href="#top">위로</A>
      </>,
    );
    const link = screen.getByRole('link', { name: '회원' });
    expect(link.getAttribute('href')).toBe('/g7/admin/users');
    expect(screen.getByRole('link', { name: '역할' }).getAttribute('href')).toBe('/g7/admin/roles');
    expect(screen.getByRole('link', { name: '위로' }).getAttribute('href')).toBe('#top');
    expect(click(link).defaultPrevented).toBe(true);
    expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/admin/users' } });
  });

  // @scenario component=breadcrumb
  // @effects href_has_site_mount_prefix
  it('Breadcrumb 항목 href 는 /g7 를 붙인다', () => {
    render(<Breadcrumb items={[{ label: '사용자 관리', href: '/admin/users' }, { label: '목록' }]} />);
    expect(screen.getByRole('link', { name: '사용자 관리' }).getAttribute('href')).toBe('/g7/admin/users');
  });

  // @scenario component=notification_center
  // @effects href_has_site_mount_prefix
  it('NotificationCenter 알림 링크 href 는 /g7 를 붙이고 바깥 주소는 그대로', () => {
    const notifications: NotificationItem[] = [
      { id: 1, title: '새 주문', message: '주문', time: '1분 전', read: false, url: '/admin/orders/1' },
      { id: 2, title: '바깥', message: '문서', time: '2분 전', read: true, url: 'https://example.com/docs' },
    ];
    render(<NotificationCenter notifications={notifications} titleText="알림" />);
    fireEvent.click(screen.getByRole('button', { name: '알림' }));
    expect(screen.getAllByRole('link').map((l) => l.getAttribute('href'))).toEqual([
      '/g7/admin/orders/1',
      'https://example.com/docs',
    ]);
  });

  // @scenario component=extension_card_grid
  // @effects href_has_site_mount_prefix
  it('ExtensionCardGrid 카드 링크·제어 단추 링크 href 는 /g7 를 붙인다', () => {
    const item: ExtensionCardItem = {
      id: 'sirsoft-ckeditor5',
      row: { identifier: 'sirsoft-ckeditor5' },
      name: 'CKEditor5',
      href: '/admin/plugins/sirsoft-ckeditor5',
      primaryAction: { label: '설정', href: '/admin/plugins/sirsoft-ckeditor5/settings' },
    } as ExtensionCardItem;
    const { container } = render(<ExtensionCardGrid items={[item]} />);
    expect(container.querySelector('[data-ref="ext_card_link"]')?.getAttribute('href')).toBe('/g7/admin/plugins/sirsoft-ckeditor5');
    expect(screen.getByRole('link', { name: '설정' }).getAttribute('href')).toBe('/g7/admin/plugins/sirsoft-ckeditor5/settings');
  });
});

describe('루트 설치(basePath 없음) — 그리는 href 는 종전 그대로', () => {
  beforeEach(() => {
    setBasePath(undefined);
    installCore(false);
  });

  // @scenario component=tab_navigation
  // @effects href_has_site_mount_prefix
  it('TabNavigation·TabNavigationScroll·Pagination·ActionMenu·DropdownMenuItem·PageHeader 의 href 에 접두사가 없다', () => {
    (window as any).IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
    render(
      <>
        <TabNavigation tabs={[{ id: 's', label: '스케줄', href: '/admin/schedules' }] as any} activeTabId="s" onTabChange={vi.fn()} />
        <TabNavigationScroll tabs={[{ id: 'a', label: '기본', href: '/admin/settings?tab=a' }] as any} activeTabId="a" />
        <Pagination currentPage={1} totalPages={3} onPageChange={vi.fn()} pageHref="/admin/users?page={page}" />
        <DropdownMenuItem label="수정" href="/admin/users/5/edit" />
        <PageHeader title="회원" actions={[{ id: 1, label: '회원 등록', href: '/admin/users/create' }]} />
      </>,
    );
    expect(screen.getByRole('tab', { name: '스케줄' }).getAttribute('href')).toBe('/admin/schedules');
    expect(screen.getByRole('tab', { name: '기본' }).getAttribute('href')).toBe('/admin/settings?tab=a');
    expect(nextLink().getAttribute('href')).toBe('/admin/users?page=2');
    expect(screen.getByRole('menuitem', { name: '수정' }).getAttribute('href')).toBe('/admin/users/5/edit');
    expect(screen.getByRole('link', { name: '회원 등록' }).getAttribute('href')).toBe('/admin/users/create');
    cleanup();

    render(<ActionMenu items={[{ id: 'edit', label: '행 수정', href: '/admin/users/u1/edit' }] as any} onItemClick={vi.fn()} />);
    fireEvent.click(screen.getByRole('button'));
    expect(screen.getByRole('menuitem', { name: '행 수정' }).getAttribute('href')).toBe('/admin/users/u1/edit');
  });
});
