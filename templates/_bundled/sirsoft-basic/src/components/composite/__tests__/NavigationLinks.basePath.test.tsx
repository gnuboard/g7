/**
 * 하위 폴더 배치에서 링크 컴포넌트가 그리는 href 의 사이트 마운트 접두사
 *
 * 하위 폴더(`G7Config.basePath = '/g7'`) 설치에서 게시판 쪽 번호·탭·알림 본문이 `<a href>` 에 루트 상대 주소
 * (`/board/free?page=2`)를 접두사 없이 그렸다. 보통 클릭은 엔진이 가로채 SPA 로 맞게 가지만 가운데 클릭·Ctrl+클릭·
 * 「새 탭에서 열기」·링크 복사는 이 값을 그대로 열어 사이트 밖(`http://host/board/free?page=2`, 404)으로 갔다.
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

import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { A } from '../../basic/A';
import { LinkButton } from '../../../support/LinkButton';
import { Pagination } from '../Pagination';
import { TabNavigation } from '../TabNavigation';
import { NotificationCenter } from '../NotificationCenter';
import {
  isPlainLeftClick as engineIsPlainLeftClick,
  resolveInternalHref as engineResolveInternalHref,
} from '@core/template-engine/navigationLinks';

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
    useResponsive: () => ({ width: 1280, breakpoint: 'desktop' }),
    navigation: {
      isPlainLeftClick: engineIsPlainLeftClick,
      resolveInternalHref: engineResolveInternalHref,
    },
    ...(withUrlApi ? { url: { api: coreApi } } : {}),
  };
};

/** 클릭 이벤트를 보내고 기본 동작이 막혔는지 돌려준다 */
const click = (el: Element, init: MouseEventInit = {}): boolean => {
  const ev = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
  el.dispatchEvent(ev);

  return ev.defaultPrevented;
};

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

  // @scenario component=pagination
  // @effects href_has_site_mount_prefix
  it('Pagination 쪽 번호 href 는 /g7 를 붙이고(쿼리만인 pageHref 는 그대로), 보통 클릭은 onPageChange 만 부른다', () => {
    const onPageChange = vi.fn();
    const { unmount } = render(
      <Pagination currentPage={1} totalPages={3} onPageChange={onPageChange} pageHref="/board/free?page={page}" />,
    );
    const page2 = screen.getAllByRole('link').find((l) => l.textContent === '2')!;
    expect(page2).toHaveAttribute('href', '/g7/board/free?page=2');
    expect(click(page2)).toBe(true);
    expect(onPageChange).toHaveBeenCalledWith(2);
    expect(dispatch).not.toHaveBeenCalled();
    unmount();

    render(<Pagination currentPage={1} totalPages={3} onPageChange={vi.fn()} pageHref="?page={page}" />);
    expect(screen.getAllByRole('link').find((l) => l.textContent === '2')).toHaveAttribute('href', '?page=2');
  });

  // @scenario component=tab_navigation
  // @effects href_has_site_mount_prefix
  it('TabNavigation 탭 href 는 /g7 를 붙이고, 보통 클릭은 onTabChange 만 부른다', () => {
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
    const posts = screen.getByRole('tab', { name: '게시글' });
    expect(posts).toHaveAttribute('href', '/g7/search?type=posts');
    expect(click(posts)).toBe(true);
    expect(onTabChange).toHaveBeenCalledWith('posts');
  });

  // @scenario component=notification_center
  // @effects href_has_site_mount_prefix
  it('NotificationCenter 알림 본문 링크 href 는 /g7 를 붙인다', () => {
    const onNotificationClick = vi.fn();
    render(
      <NotificationCenter
        titleText="알림"
        notifications={[{ id: 1, title: '새 댓글', message: '댓글', time: '방금', url: '/board/free/14' }]}
        onNotificationClick={onNotificationClick}
      />,
    );
    fireEvent.click(screen.getByLabelText('알림'));
    const link = screen.getByRole('link', { name: /새 댓글/ });
    expect(link).toHaveAttribute('href', '/g7/board/free/14');
    expect(click(link)).toBe(true);
    expect(onNotificationClick).toHaveBeenCalledTimes(1);
  });

  // ── 이미 `A` 를 거쳐 접두사를 붙이던 자리 — 회귀 잠금 ──

  // @scenario component=a
  // @effects href_has_site_mount_prefix
  it('A·LinkButton 은 /g7 를 붙이고(이미 붙은 값은 그대로), A 의 보통 클릭 SPA 이동은 접두사 없는 경로로 한다', () => {
    const onNavigate = vi.fn();
    render(
      <>
        <A href="/shop/products">상품</A>
        <A href="/g7/board/free">자유</A>
        <LinkButton href="/mypage" onNavigate={onNavigate}>마이페이지</LinkButton>
      </>,
    );
    const link = screen.getByRole('link', { name: '상품' });
    expect(link).toHaveAttribute('href', '/g7/shop/products');
    expect(screen.getByRole('link', { name: '자유' })).toHaveAttribute('href', '/g7/board/free');
    expect(screen.getByRole('link', { name: '마이페이지' })).toHaveAttribute('href', '/g7/mypage');
    expect(click(link)).toBe(true);
    expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/shop/products' } });
  });
});

describe('루트 설치(basePath 없음) — 그리는 href 는 종전 그대로', () => {
  beforeEach(() => {
    setBasePath(undefined);
    installCore(false);
  });

  // @scenario component=pagination
  // @effects href_has_site_mount_prefix
  it('Pagination·TabNavigation·NotificationCenter 의 href 에 접두사가 없다', () => {
    render(
      <>
        <Pagination currentPage={1} totalPages={3} onPageChange={vi.fn()} pageHref="/board/free?page={page}" />
        <TabNavigation activeTabId="all" onTabChange={vi.fn()} tabs={[{ id: 'all', label: '전체', href: '/search?type=all' }]} />
        <NotificationCenter titleText="알림" notifications={[{ id: 1, title: '새 댓글', message: '댓글', time: '방금', url: '/board/free/14' }]} />
      </>,
    );
    expect(screen.getAllByRole('link').find((l) => l.textContent === '2')).toHaveAttribute('href', '/board/free?page=2');
    expect(screen.getByRole('tab', { name: '전체' })).toHaveAttribute('href', '/search?type=all');
    fireEvent.click(screen.getByLabelText('알림'));
    expect(screen.getByRole('link', { name: /새 댓글/ })).toHaveAttribute('href', '/board/free/14');
  });
});
