/**
 * @file shop-reviews-feed.test.tsx
 * @description 리뷰 모아보기 화면과 메뉴, 후기 카드 조각 공용화를 잠근다.
 *
 * 검증 항목:
 * - 화면이 전체 후기 API 를 주소 쿼리(정렬·사진 후기만·별점·쪽수)로 받는다(회원·비회원 모두)
 * - 필터 세 가지와 쪽 이동이 같은 화면 주소로, 다른 조건을 지키며(mergeQuery) 이동한다
 * - 후기가 없을 때 문구가 필터 유무로 갈린다
 * - 후기 카드 조각을 상세 후기 탭과 새 화면이 같이 쓰고, 새 화면도 큰 사진 창을 선언한다
 * - 쇼핑 경로로 등록되고(쇼핑몰이 꺼지면 사라짐), 머리글·모바일 서랍·검색 로봇용 머리글에 링크가 있다
 *
 *
 * @vitest-environment jsdom
 *
 * @scenario-manifest tests/scenarios/shop-reviews-feed.yaml
 */

import React from 'react';
import { render } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const templateDir = path.resolve(__dirname, '../..');

function loadJson(relative: string): any {
  return JSON.parse(fs.readFileSync(path.join(templateDir, relative), 'utf-8'));
}

function collect(node: any, match: (n: any) => boolean, out: any[] = []): any[] {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((n) => collect(n, match, out));
    return out;
  }
  if (match(node)) out.push(node);
  Object.values(node).forEach((v) => collect(v, match, out));
  return out;
}

const layout = loadJson('layouts/shop/reviews.json');
const text = JSON.stringify(layout);

describe('리뷰 모아보기 화면', () => {
  // @scenario case=c01
  // @effects feed_reads_query_filters
  it('전체 후기 API 를 주소 쿼리로 받고 회원·비회원 모두에게 연다', () => {
    const source = layout.data_sources.find((d: any) => d.id === 'reviewFeed');
    expect(source.endpoint).toBe('/api/modules/sirsoft-ecommerce/reviews');
    expect(source.auth_mode).toBe('optional');
    expect(source.params.sort).toBe("{{query.sort ?? 'created_at_desc'}}");
    expect(source.params.photo_only).toBe("{{query.photo_only ?? ''}}");
    expect(source.params.rating).toBe("{{query.rating ?? ''}}");
    expect(source.params.page).toBe('{{query.page ?? 1}}');
  });

  // @scenario case=c02
  // @effects feed_filters_navigate_with_merge_query
  it('필터 세 가지가 같은 화면 주소로, 다른 조건을 지키며 1쪽으로 이동한다', () => {
    const navigates = collect(layout, (n) => n.handler === 'navigate' && n.params?.path === '{{route.path}}');
    const byKey = (key: string) => navigates.filter((n) => n.params?.query && key in n.params.query);

    for (const key of ['photo_only', 'rating', 'sort']) {
      const found = byKey(key);
      expect(found, key).toHaveLength(1);
      expect(found[0].params.mergeQuery).toBe(true);
      expect(found[0].params.query.page).toBe('');
    }
    expect(byKey('photo_only')[0].params.query.photo_only).toBe("{{$event.target.checked ? '1' : ''}}");
  });

  // @scenario case=c03
  // @effects feed_pagination_links_keep_filters
  it('쪽 번호가 실제 링크이고 필터를 지킨 채 이동한다', () => {
    const pagination = collect(layout, (n) => n.name === 'Pagination')[0];
    expect(pagination.props.pageHref).toBe('{{route.path}}?page={page}');
    expect(pagination.props.pageHrefMergeQuery).toBe(true);
    const pageNav = collect(pagination, (n) => n.handler === 'navigate')[0];
    expect(pageNav.params.mergeQuery).toBe(true);
    expect(pageNav.params.query.page).toBe('{{$args[0]}}');
  });

  // @scenario case=c04
  // @effects feed_empty_state_depends_on_filters
  it('후기가 없을 때 문구가 필터 유무로 갈린다', () => {
    const empty = collect(layout, (n) => typeof n.text === 'string' && n.text.startsWith('$t:shop.reviews.empty'));
    expect(empty.map((n) => n.text).sort()).toEqual(['$t:shop.reviews.empty', '$t:shop.reviews.empty_filtered']);
    const filtered = empty.find((n) => n.text === '$t:shop.reviews.empty_filtered');
    const plain = empty.find((n) => n.text === '$t:shop.reviews.empty');
    expect(filtered.if).toContain('query.photo_only');
    expect(filtered.if).toContain('query.rating');
    expect(plain.if).toContain('!query.photo_only');
  });

  // @scenario case=c05
  // @effects review_card_partial_is_shared, feed_declares_review_image_modal
  it('후기 카드 조각을 상세 후기 탭과 같이 쓰고 큰 사진 창을 선언한다', () => {
    const tab = JSON.stringify(loadJson('layouts/partials/shop/detail/_tab_reviews.json'));
    expect(text).toContain('partials/shop/detail/_review_card.json');
    expect(tab).toContain('partials/shop/detail/_review_card.json');
    // 카드 본문은 조각에만 있다 — 두 화면에 사본이 없다
    expect(tab).not.toContain('reviewForModal');
    expect(text).not.toContain('reviewForModal');
    expect(JSON.stringify(loadJson('layouts/partials/shop/detail/_review_card.json'))).toContain('review_image_modal');
    expect(text).toContain('partials/shop/detail/_modal_review_image.json');
    // 카드가 읽는 별 목록을 새 화면도 채운다
    expect(text).toContain('starList');
  });

  // @scenario case=c06
  // @effects feed_filters_navigate_with_merge_query
  it('상품 요약 링크가 상점 주소를 설정에서 읽는다', () => {
    const links = collect(layout, (n) => n.name === 'A' && String(n.props?.href ?? '').includes('review.product?.product_code'));
    expect(links).toHaveLength(1);
    expect(links[0].props.href).toBe('{{_global.shopBase}}/products/{{review.product?.product_code}}');
  });
});

describe('[case:components-misc-filter-change-stale-list-veil] 후기 목록 조건 변경 — 받는 동안 앞 목록 가림', () => {
  // @scenario case=c10
  // @effects feed_filter_change_waits_for_new_list
  it('조건을 바꾸면 새 목록이 올 때까지 로딩 덮개를 한 번만 유지한다(앞 목록이 남거나 덮개가 반짝이지 않음)', () => {
    // 바탕 레이아웃의 덮개(스피너)는 기다릴 소스가 없으면 렌더 직후 걷혀, 같은 화면 쿼리 이동에서
    // 덮개가 두 번 반짝인 뒤 앞 목록이 응답 전까지 그대로 보였다 — 화면이 후기 목록 소스를 기다리게 한다
    expect(layout.transition_overlay?.wait_for).toEqual(['reviewFeed']);
    const source = layout.data_sources.find((d: any) => d.id === 'reviewFeed');
    // wait_for 는 progressive·blocking 소스만 받는다(background 는 검증이 막음)
    expect(source.loading_strategy).toBe('progressive');
    // 베이스의 덮개 설정(enabled·style·target)은 자식이 덮어쓰지 않는다 — wait_for 만 얹는다
    expect(layout.transition_overlay.enabled).toBeUndefined();
    expect(layout.transition_overlay.style).toBeUndefined();
    expect(layout.transition_overlay.target).toBeUndefined();

    // 덮개가 걷힌 뒤 새 목록이 그려지기까지의 틈(실측 약 70ms)에 앞 목록이 또렷이 비치지 않게,
    // 목록·빈 안내·쪽 번호 묶음이 같은 소스를 받는 동안 흐려진다. 필터 줄은 묶음 밖이라 흐려지지 않는다
    // 「총 N개」 글자도 같은 소스를 받는 동안 흐린다(받기 전 「총 0개」가 먼저 보였다) — 목록 묶음과 따로 걸린 한 줄이다
    const blurredAll = collect(layout, (n) => n.blur_until_loaded && typeof n.blur_until_loaded === 'object');
    const blurred = blurredAll.filter((n) => !String(n.text ?? '').startsWith('$t:shop.reviews.total_count'));
    expect(blurredAll.length - blurred.length).toBe(1);
    expect(blurred).toHaveLength(1);
    expect(blurred[0].blur_until_loaded).toEqual({ enabled: true, data_sources: 'reviewFeed' });
    const inner = JSON.stringify(blurred[0]);
    expect(inner).toContain('reviewFeed?.data?.reviews?.data ?? []');
    expect(inner).toContain('review_feed_empty');
    expect(inner).toContain('"Pagination"');
    expect(inner).not.toContain('review_feed_photo_only');
  });

  // @scenario case=c11
  // @effects detail_review_filter_change_blurs_old_list
  it('상품 상세 후기 탭도 조건을 바꾸면 새 목록이 올 때까지 앞 목록을 흐리게 둔다(조건 6곳 전부)', () => {
    // 이 탭은 주소 이동 없이 소스만 다시 받는다 — 다시 받기는 엔진의 「받는 중」 표시를 세우지 않으므로
    // (data-sources-advanced.md) 화면이 받는 동안 켜고 끄는 값으로 흐림을 건다. 종전에는 포토리뷰만 보기를
    // 누르면 단추만 바로 켜지고 앞 목록이 약 1.1초 그대로 보이다 바뀌었다(실측)
    const tab = loadJson('layouts/partials/shop/detail/_tab_reviews.json');
    const handlers = collect(tab, (n) => Array.isArray(n.actions) && JSON.stringify(n.actions).includes('"refetchDataSource"'))
      .filter((n) => n.handler !== 'sequence');
    // 옵션 필터·별점·초기화·포토리뷰·정렬·쪽 번호
    expect(handlers).toHaveLength(6);
    for (const h of handlers) {
      const list = h.actions.filter((a: any) => a.handler !== undefined);
      // 이벤트의 액션들은 서로 기다리지 않는다 — 끄기를 다시 받기 뒤에 두려면 차례 묶음이어야 한다
      expect(list, JSON.stringify(h.actions).slice(0, 120)).toHaveLength(1);
      expect(list[0].handler).toBe('sequence');
      const seq = list[0].actions;
      const refetchAt = seq.findIndex((a: any) => a.handler === 'refetchDataSource');
      expect(seq[refetchAt].params.dataSourceId).toBe('reviews');
      // 기본 다시 받기는 화면 반영을 뒤로 미뤄(startTransition) 흐림이 먼저 걷힌다 — 실측 120~145ms 동안
      // 앞 목록이 또렷이 보였다. 즉시 반영해 새 목록이 그려진 뒤에 흐림을 걷는다
      expect(seq[refetchAt].params.sync).toBe(true);
      const before = seq.slice(0, refetchAt).filter((a: any) => a.handler === 'setState');
      expect(before.some((a: any) => a.params.reviewsLoading === true)).toBe(true);
      expect(seq.slice(refetchAt + 1)).toEqual([{ handler: 'setState', params: { target: 'local', reviewsLoading: false } }]);
    }

    const blurred = collect(tab, (n) => n.blur_until_loaded !== undefined);
    expect(blurred).toHaveLength(1);
    expect(blurred[0].blur_until_loaded).toBe('{{_local.reviewsLoading ?? false}}');
    const inner = JSON.stringify(blurred[0]);
    expect(inner).toContain('reviews.data?.reviews?.data ?? []');
    expect(inner).toContain('$t:shop.product.no_reviews');
    expect(inner).toContain('"Pagination"');
    // 조건 단추·칸은 흐림 밖 — 누른 단추의 상태는 또렷하게 보인다
    expect(inner).not.toContain('photo_reviews_only');
    expect(inner).not.toContain('filter_reset');

    // 같은 레이아웃(상품 → 상품) 이동은 _local 을 유지한다 — 받는 도중 옮겨도 다음 상품 목록이 흐린 채 남지 않게 비운다
    const show = loadJson('layouts/shop/show.json');
    const initText = JSON.stringify(show.init_actions ?? show.initActions ?? show.init);
    expect(initText).toContain('"reviewsLoading":false');
  });
});

describe('리뷰 모아보기 경로와 메뉴', () => {
  // @scenario case=c07
  // @effects feed_route_requires_ecommerce
  it('쇼핑 경로로 등록되고 쇼핑몰 모듈을 요구한다', () => {
    const routes = loadJson('routes.json');
    const list = Array.isArray(routes) ? routes : routes.routes;
    const route = list.find((r: any) => r.layout === 'shop/reviews');
    expect(route).toBeTruthy();
    expect(route.path).toMatch(/\/reviews$/);
    expect(route.path).toContain("basic_info?.route_path ?? 'shop'");
    expect(route.requires).toEqual({ modules: ['sirsoft-ecommerce'] });
    expect(route.meta.title).toBe('$t:user.shop.reviews_title');
  });

  // @scenario case=c08
  // @effects feed_menu_links_exist
  it('모바일 서랍의 쇼핑 구역과 검색 로봇용 머리글에 링크가 있다', () => {
    const base = loadJson('layouts/_user_base.json');
    const section = collect(base, (n) => n.id === 'mobile_drawer_shop_section' || n.props?.['data-ref'] === 'mobile_drawer_shop_section')[0]
      ?? collect(base, (n) => JSON.stringify(n.if ?? '').includes('sirsoft-ecommerce') && JSON.stringify(n).includes('user.nav.reviews'))[0];
    expect(section).toBeTruthy();
    const sectionText = JSON.stringify(section);
    expect(sectionText).toContain('{{_global.shopBase}}/reviews');
    expect(sectionText).toContain('$t:user.nav.reviews');
    expect(JSON.stringify(section.if ?? '')).toContain("activeModules?.includes('sirsoft-ecommerce')");

    const header = collect(base, (n) => n.name === 'Header' && n.props?.shopProductsHref)[0];
    expect(header.props.shopReviewsHref).toBe(String(header.props.shopProductsHref).replace('/products', '/reviews'));

    const seo = fs.readFileSync(path.join(templateDir, 'seo-config.json'), 'utf-8');
    expect(seo).toContain('"href": "{shopReviewsHref}"');
  });

  // @scenario case=c09
  // @effects feed_menu_links_exist
  it('머리글 탭 줄에 리뷰 링크가 있고 그 화면에서 활성으로 표시된다', async () => {
    (window as any).G7Core = {
      t: (key: string) => key,
      state: { get: () => ({}), subscribe: () => () => undefined },
      useLocationSearch: undefined,
    };
    const { default: Header } = await import('../../src/components/composite/Header');
    const originalPath = window.location.pathname;
    window.history.pushState({}, '', '/shop/reviews');

    const { container, unmount } = render(<Header shopBase="/shop" shopEnabled boards={[]} />);
    const link = container.querySelector('[data-testid="nav-reviews"]') as HTMLAnchorElement;
    expect(link).toBeTruthy();
    expect(link.getAttribute('href')).toBe('/shop/reviews');
    expect(link.getAttribute('aria-current')).toBe('page');
    expect(container.querySelector('[data-testid="nav-shop"]')?.getAttribute('aria-current')).toBeNull();
    unmount();

    const off = render(<Header shopBase="/shop" shopEnabled={false} boards={[]} />);
    expect(off.container.querySelector('[data-testid="nav-reviews"]')).toBeNull();
    off.unmount();

    window.history.pushState({}, '', originalPath);
    vi.restoreAllMocks();
  });
});
