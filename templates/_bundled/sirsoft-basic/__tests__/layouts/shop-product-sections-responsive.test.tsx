/**
 * @file shop-product-sections-responsive.test.tsx
 * @description 인기·신상품·최근 본·관련 상품 줄이 같은 가로 줄 컨테이너(ProductRail)를 쓰는지 잠근다.
 *
 * 테스트 대상:
 * - templates/.../partials/shop/list/_popular_products.json
 * - templates/.../partials/shop/list/_new_products.json
 * - templates/.../partials/shop/list/_recent_products.json
 * - templates/.../partials/shop/list/_related_products.json
 * - templates/.../shop/show.json (관련 상품 데이터소스·줄 순서)
 *
 * 검증 항목:
 * - 네 조각의 루트가 ProductRail composite 이고, 자식이 ProductCard 반복이다
 * - 옛 단추형 구조(격리 상태·scrollIntoView·카드 id·grid 트랙)가 레이아웃에 남아 있지 않다
 *   (넘기기와 한 화면 카드 수는 컴포넌트가 맡는다 — ProductRail.test.tsx)
 * - 줄이 비면 통째로 그리지 않는다 (루트 조건)
 * - 상품 상세가 관련 상품을 회원·비회원 모두에게, 인기 줄 앞에 싣는다
 *
 *
 * @vitest-environment jsdom
 *
 * @scenario-manifest tests/scenarios/shop-product-rail.yaml
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const layoutsDir = path.resolve(__dirname, '../../layouts');

function loadJson(relative: string): any {
  return JSON.parse(fs.readFileSync(path.join(layoutsDir, relative), 'utf-8'));
}

const RAILS: Array<{ file: string; source: string; title: string }> = [
  { file: '_popular_products.json', source: 'popularProducts', title: '$t:shop.popular_products' },
  { file: '_new_products.json', source: 'newProducts', title: '$t:shop.new_products' },
  { file: '_recent_products.json', source: 'recentProducts', title: '$t:shop.recent_products' },
  { file: '_related_products.json', source: 'relatedProducts', title: '$t:shop.related_products' },
];

/**
 * 노드 트리에서 조건에 맞는 노드를 모두 모은다.
 */
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

describe.each(RAILS)('상품 줄 조각 $file', ({ file, source, title }) => {
  const partial = loadJson(`partials/shop/list/${file}`);
  const rails = collect(partial, (n) => n.name === 'ProductRail');

  // @scenario case=c06
  // @effects rails_share_product_rail_container
  it('루트가 ProductRail 이고 제목·넘김 단추 이름을 넘긴다', () => {
    expect(rails).toHaveLength(1);
    const rail = rails[0];
    expect(rail.type).toBe('composite');
    expect(rail.props.title).toBe(title);
    expect(rail.props.prevLabel).toBe('$t:common.prev');
    expect(rail.props.nextLabel).toBe('$t:common.next');
    expect(rail.props.roleDescription).toBe('$t:shop.product_rail.roledescription');
  });

  // @scenario case=c07
  // @effects rails_share_product_rail_container
  it('자식이 그 줄 데이터의 ProductCard 반복이다', () => {
    const rail = rails[0];
    const repeated = collect(rail.children, (n) => !!n.iteration);
    expect(repeated).toHaveLength(1);
    expect(JSON.stringify(repeated[0].iteration)).toContain(`${source}.data`);
    const cards = collect(repeated[0], (n) => n.name === 'ProductCard');
    expect(cards).toHaveLength(1);
    expect(cards[0].props.product).toBe('{{product}}');
    expect(cards[0].props.shopBase).toContain('_global.shopBase');
  });

  // @scenario case=c08
  // @effects rails_hide_when_empty
  it('줄이 비면 통째로 그리지 않는다', () => {
    const rail = rails[0];
    expect(String(rail.if ?? '')).toContain(`${source}.data`);
    expect(String(rail.if ?? '')).toContain('length > 0');
  });

  // @scenario case=c09
  // @effects rails_have_no_legacy_button_scroll
  it('옛 단추형 구조가 레이아웃에 남아 있지 않다', () => {
    const text = JSON.stringify(partial);
    expect(text).not.toContain('isolatedState');
    expect(text).not.toContain('_isolated');
    expect(text).not.toContain('scrollIntoView');
    expect(text).not.toContain('grid-flow-col');
    expect(collect(partial, (n) => typeof n.props?.id === 'string' && /-product-/.test(n.props.id))).toHaveLength(0);
  });
});

describe('상품 상세의 관련 상품 줄', () => {
  const show = loadJson('shop/show.json');

  // @scenario case=c10
  // @effects related_rail_loaded_on_product_detail
  it('관련 상품 데이터소스가 회원·비회원 모두에게 8개까지 받고 실패해도 빈 목록이다', () => {
    const source = (show.data_sources ?? []).find((d: any) => d.id === 'relatedProducts');
    expect(source).toBeTruthy();
    expect(source.endpoint).toBe('/api/modules/sirsoft-ecommerce/products/{{route.product_code}}/related?limit=8');
    expect(source.auth_mode).toBe('optional');
    expect(source.loading_strategy).toBe('progressive');
    expect(source.fallback).toEqual({ data: [] });
  });

  // @scenario case=c11
  // @effects related_rail_loaded_on_product_detail
  it('검색 로봇용 렌더에도 관련 상품이 실리고, 관련 줄이 인기 줄 앞에 온다', () => {
    expect(show.meta.seo.data_sources).toContain('relatedProducts');

    const text = JSON.stringify(show);
    const related = text.indexOf('_related_products');
    const popular = text.indexOf('_popular_products');
    expect(related).toBeGreaterThan(-1);
    expect(popular).toBeGreaterThan(related);
  });

  // @scenario case=c12
  // @effects rails_have_no_legacy_button_scroll
  it('쓰이지 않던 계산값(popularItems)을 지웠다', () => {
    expect(JSON.stringify(show)).not.toContain('popularItems');
  });
});
