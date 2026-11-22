/**
 * @file shop-display-polish.test.ts
 * @description 기본 템플릿 상점 화면의 표시 다듬기 여섯 가지를 잠근다.
 *
 * 검증 항목:
 * - 상품 목록의 쪽 이동이 다른 목록과 같은 쪽 번호 컴포넌트(실제 링크)다
 * - 상품 상세의 분류 경로가 그 분류 화면으로 가는 링크다(주소를 모르면 글자)
 * - 주문 상세·비회원 주문 조회의 송장이 조회 주소가 있으면 새 창 링크다
 * - 쿠폰 받기 창의 정률 할인율에 소수 끝 0 이 붙지 않는다
 * - 주문서 적립금 입력에 「상품 합계 기준 미달」 안내가 있다
 * - 마이페이지 주문 목록에 송장이 있는 주문만 「배송 조회」 링크가 보인다
 *
 * @scenario-manifest tests/scenarios/shop-display-polish.yaml
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';
import { rangeClassName } from './support/responsiveClass';

const layoutsDir = path.resolve(__dirname, '../../layouts');

function loadJson(relative: string): any {
  return JSON.parse(fs.readFileSync(path.join(layoutsDir, relative), 'utf-8'));
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

describe('상점 화면 표시 다듬기', () => {
  // @scenario case=c01
  // @effects product_list_uses_pagination_component
  it('상품 목록의 쪽 이동이 쪽 번호 컴포넌트이고 다른 조건을 지킨 채 이동한다', () => {
    const grid = loadJson('partials/shop/list/_product_grid.json');
    const paginations = collect(grid, (n) => n.name === 'Pagination');
    expect(paginations).toHaveLength(1);
    const p = paginations[0];
    expect(p.props.pageHref).toBe('{{route.path}}?page={page}');
    expect(p.props.pageHrefMergeQuery).toBe(true);
    expect(p.props.currentPage).toContain('products?.data?.pagination?.current_page');
    expect(p.props.totalPages).toContain('last_page');
    expect(p.props.hasMorePages).toContain('has_more_pages');
    // 총 건수가 상한에 걸려 마지막 쪽을 모를 때도 쪽 이동이 사라지지 않는다
    expect(String(p.if)).toContain('has_more_pages');
    const nav = collect(p, (n) => n.handler === 'navigate')[0];
    expect(nav.params.path).toBe('{{route.path}}');
    expect(nav.params.mergeQuery).toBe(true);
    expect(nav.params.query).toEqual({ page: '{{$args[0]}}' });
    // 옛 이전·다음 화살표 링크가 남아 있지 않다
    expect(JSON.stringify(grid)).not.toContain('current_page ?? 1) - 1');
  });

  // @scenario case=c02
  // @effects product_category_path_is_link
  it('상품 상세의 분류 경로가 대표 분류 화면으로 가는 링크이고, 주소를 모르면 글자로 남는다', () => {
    const header = loadJson('partials/shop/detail/_header.json');
    const nav = collect(header, (n) => n.name === 'Nav')[0];
    expect(nav).toBeTruthy();
    expect(nav.props['aria-label']).toBe('$t:shop.product.category_path');
    const link = collect(nav, (n) => n.name === 'A')[0];
    expect(link.props.href).toContain('{{_global.shopBase}}/category/');
    expect(link.props.href).toContain('is_primary');
    expect(String(link.if)).toContain('slug');
    const fallback = collect(nav, (n) => n.name === 'Span' && String(n.if ?? '').includes('slug'))[0];
    expect(fallback).toBeTruthy();
    expect(JSON.stringify(fallback)).toContain('category_name');
  });

  // @scenario case=c03
  // @effects order_item_tracking_is_link_when_url_known
  it('주문 품목의 송장이 조회 주소가 있으면 새 창 링크, 없으면 글자다 (좁은 화면·넓은 화면 두 곳)', () => {
    const items = loadJson('partials/mypage/orders/_items.json');
    const links = collect(items, (n) => n.name === 'A' && n.props?.href === '{{item.tracking_url}}');
    expect(links).toHaveLength(2);
    for (const link of links) {
      expect(link.props.target).toBe('_blank');
      expect(link.props.rel).toBe('noopener noreferrer');
      expect(String(link.if)).toContain('item.tracking_url');
    }
    const plain = collect(items, (n) => n.name === 'Span' && String(n.if ?? '').includes('!item.tracking_url'));
    expect(plain).toHaveLength(2);
  });

  // @scenario case=c04
  // @effects coupon_modal_rate_has_no_trailing_zero
  it('쿠폰 받기 창의 정률 할인율을 숫자로 바꿔 소수 끝 0 을 떼어 낸다', () => {
    const text = JSON.stringify(loadJson('partials/shop/_modal_coupon_download.json'));
    expect(text).toContain('{{Number(coupon.discount_value)}}%');
    expect(text).not.toContain('{{coupon.discount_value}}%');
  });

  // @scenario case=c05
  // @effects checkout_mileage_shows_min_product_total_notice
  it('주문서 적립금 입력에 상품 합계가 기준에 못 미칠 때만 안내 줄이 보인다', () => {
    const mileage = loadJson('partials/shop/_checkout_mileage.json');
    const notice = collect(mileage, (n) => typeof n.text === 'string' && n.text.startsWith('$t:shop.checkout.mileage_min_product_total'))[0];
    expect(notice).toBeTruthy();
    expect(String(notice.if)).toContain('usage_policy?.min_product_total_for_use');
    expect(String(notice.if)).toContain('summary?.subtotal');
    expect(String(notice.if)).toContain('> 0');
    expect(String(notice.props?.className ?? '')).toMatch(/dark:/);
  });

  // @scenario case=c06
  // @effects order_list_has_track_shipping_link
  it('주문 목록에 송장 조회 주소가 있는 주문만 새 창으로 여는 배송 조회 링크가 있다', () => {
    const list = loadJson('partials/mypage/orders/_list.json');
    const links = collect(list, (n) => n.name === 'A' && n.props?.href === '{{order.shipping?.tracking_url}}');
    expect(links).toHaveLength(1);
    expect(String(links[0].if)).toContain('order.shipping?.tracking_url');
    expect(links[0].props.target).toBe('_blank');
    expect(links[0].props.rel).toBe('noopener noreferrer');
    expect(JSON.stringify(links[0])).toContain('$t:mypage.orders.track_shipping');
    expect(String(links[0].props.className)).toMatch(/dark:/);
    // 모바일·PC 두 모양 — 옆 「상세」 단추처럼 좁은 화면에서는 작은 모양으로 바뀐다
    // 좁은 화면 값은 구간 class-merge 를 기본 className 위에 엔진 규칙으로 덧붙인 실효값이다 (#399)
    const portable = rangeClassName(links[0], 'portable');
    const portableTokens = portable.split(/\s+/);
    expect(portableTokens).toContain('text-xs');
    expect(portableTokens).toContain('py-1.5');
    expect(portableTokens).not.toContain('text-sm');
    expect(portableTokens).not.toContain('py-2');
    expect(portable).toMatch(/dark:/);
  });
});
