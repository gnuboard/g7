/**
 * 쿼리만 바뀐 같은 화면에서 현재 쿼리를 합치는 링크 href 가 새 쿼리를 따라가는지 (#757 링크 단계 후속, 7.1.1 회귀)
 *
 * `mergeQuery`·`pageHrefMergeQuery`·`preserveListQuery`·`tabHrefMergeQuery` 링크는 렌더할 때 현재 주소의 쿼리를 href 에
 * 합친다. 같은 화면에서 쿼리만 바꾸는 `navigate replace`(TemplateApp.updateQueryParams — `history.replaceState` 뒤 다시
 * 그리기)에서는 링크 props 가 그대로라 DynamicRenderer memo·props 참조 안정화로 링크가 다시 그려지지 않아, 가운데 클릭·
 * Ctrl+클릭·새 탭·주소 복사가 옛 쿼리로 갔다. 링크는 엔진 `G7Core.navigation.subscribeLocationSearch` 로 주소 교체
 * 알림(`notifyLocationReplaced`)을 구독해 다시 그린다.
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
import { render, screen, act, cleanup } from '@testing-library/react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { A } from '../../basic/A';
import { Pagination } from '../Pagination';
import ProductCard from '../ProductCard';
import { TabNavigation } from '../TabNavigation';
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
    useResponsive: () => ({ width: 1280, breakpoint: 'desktop' }),
    navigation: {
      isPlainLeftClick: engineIsPlainLeftClick,
      mergeQueryIntoHref: engineMergeQueryIntoHref,
      subscribeLocationSearch: subscribeSpy,
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

const product = { id: 7, product_code: 'P-7', name: '기본 티셔츠', thumbnail_url: '/t.png', selling_price: 1000 } as any;

beforeEach(() => {
  installCore();
  window.history.replaceState(null, '', '/shop?sort=new');
});
afterEach(() => {
  cleanup();
  delete (window as any).G7Core;
  window.history.replaceState(null, '', '/');
});

describe('현재 쿼리를 합치는 링크 — 같은 화면 쿼리 교체 뒤 href', () => {
  // @scenario component=a
  // @effects merge_query_href_follows_query_replace
  it('A: 부모가 다시 그리지 않아도 주소 교체 알림·뒤로/앞으로(popstate) 뒤 href 가 새 쿼리다', () => {
    render(<Frozen><A href="/board/free/5" mergeQuery>글 보기</A></Frozen>);
    const link = () => screen.getByRole('link', { name: '글 보기' });
    expect(link().getAttribute('href')).toBe('/board/free/5?sort=new');

    replaceQuery('/board/free?search=kim&page=3');
    expect(link().getAttribute('href')).toBe('/board/free/5?search=kim&page=3');

    act(() => {
      window.history.replaceState(null, '', '/board/free?page=1');
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    expect(link().getAttribute('href')).toBe('/board/free/5?page=1');
  });

  // @scenario component=pagination
  // @effects merge_query_href_follows_query_replace
  it('Pagination: pageHrefMergeQuery 쪽 번호 링크가 주소 교체 뒤 새 쿼리를 합친다', () => {
    render(
      <Frozen>
        <Pagination currentPage={1} totalPages={3} onPageChange={vi.fn()} pageHref="/shop?page={page}" pageHrefMergeQuery />
      </Frozen>,
    );
    const hrefs = () => screen.getAllByRole('link').map((l) => l.getAttribute('href'));
    expect(hrefs()).toContain('/shop?sort=new&page=2');

    replaceQuery('/shop?category=top&page=1');
    expect(hrefs()).toContain('/shop?category=top&page=2');
    expect(hrefs().some((h) => h?.includes('sort=new'))).toBe(false);
  });

  // @scenario component=product_card
  // @effects merge_query_href_follows_query_replace
  it('ProductCard: preserveListQuery 카드 링크가 주소 교체 뒤 새 쿼리를 합친다', () => {
    render(<Frozen><ProductCard product={product} shopBase="/shop" preserveListQuery /></Frozen>);
    const href = () => screen.getByRole('link', { name: /기본 티셔츠/ }).getAttribute('href');
    expect(href()).toBe('/shop/products/P-7?sort=new');

    replaceQuery('/shop?sort=price&page=2');
    expect(href()).toBe('/shop/products/P-7?sort=price&page=2');
  });

  // @scenario component=tab_navigation
  // @effects merge_query_href_follows_query_replace
  it('TabNavigation: tabHrefMergeQuery 탭 링크가 주소 교체 뒤 새 쿼리를 합친다', () => {
    render(
      <Frozen>
        <TabNavigation
          activeTabId="info"
          tabHrefMergeQuery
          tabs={[
            { id: 'info', label: '상세정보', href: '/shop/products/P1?tab=info' },
            { id: 'reviews', label: '리뷰', href: '/shop/products/P1?tab=reviews' },
          ]}
        />
      </Frozen>,
    );
    const href = () => screen.getByRole('tab', { name: '리뷰' }).getAttribute('href');
    expect(href()).toBe('/shop/products/P1?sort=new&tab=reviews');

    replaceQuery('/shop/products/P1?tab=info&review_page=4');
    expect(href()).toBe('/shop/products/P1?tab=reviews&review_page=4');
  });

  // @scenario component=a
  // @effects merge_query_href_follows_query_replace
  it('쿼리를 합치지 않는 링크·쪽 번호·카드·탭은 주소를 구독하지 않는다(일반 렌더 비용 그대로)', () => {
    render(
      <>
        <A href="/board/free">게시판</A>
        <Pagination currentPage={1} totalPages={3} onPageChange={vi.fn()} pageHref="/shop?page={page}" />
        <ProductCard product={product} shopBase="/shop" />
        <ProductCard product={product} shopBase="/shop" preserveListQuery onClick={vi.fn()} />
        <TabNavigation activeTabId="info" tabs={[{ id: 'info', label: '상세정보', href: '/shop/products/P1?tab=info' }]} />
      </>,
    );
    expect(subscribeSpy).not.toHaveBeenCalled();
  });

  // @scenario component=a
  // @effects merge_query_href_follows_query_replace
  it('구독 API 가 없는 옛 엔진에서도 그려지고, 뒤로/앞으로(popstate)에는 새 쿼리를 합친다', () => {
    delete (window as any).G7Core.navigation.subscribeLocationSearch;
    render(<Frozen><A href="/board/free/5" mergeQuery>글 보기</A></Frozen>);
    const link = () => screen.getByRole('link', { name: '글 보기' });
    expect(link().getAttribute('href')).toBe('/board/free/5?sort=new');

    act(() => {
      window.history.replaceState(null, '', '/board/free?page=9');
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    expect(link().getAttribute('href')).toBe('/board/free/5?page=9');
  });
});
