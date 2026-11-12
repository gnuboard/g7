/**
 * @file shop-exclude-sold-out.test.ts
 * @description 상품 목록·분류·통합검색의 「품절 제외」 체크와 정렬 줄 공용 조각을 잠근다.
 *
 * 검증 항목:
 * - 전체 상품 목록과 분류 화면이 같은 정렬 줄 조각(_sort_bar)을 쓴다 (인라인 사본 없음)
 * - 정렬 줄의 품절 제외 체크가 주소 쿼리를 읽고, 같은 화면 주소로 다른 조건을 지키며 1쪽으로 이동한다
 * - 두 화면의 상품 데이터소스가 그 쿼리를 API 로 넘긴다
 * - 초기화 단추가 품절 제외도 조건으로 본다
 * - 통합검색(상품 탭)에도 같은 체크가 있고 검색 API 주소에 실린다
 *
 * @scenario-manifest tests/scenarios/shop-exclude-sold-out.yaml
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

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

describe('정렬 줄 공용 조각', () => {
  const sortBar = loadJson('partials/shop/list/_sort_bar.json');
  const filterBar = loadJson('partials/shop/list/_search_filter_bar.json');
  const category = loadJson('shop/category.json');

  // @scenario case=c01
  // @effects sort_bar_partial_is_shared
  it('전체 목록(검색·정렬 줄 조각 경유)과 분류 화면이 같은 조각을 include 하고 인라인 정렬 select 가 없다', () => {
    expect(JSON.stringify(filterBar)).toContain('partials/shop/list/_sort_bar.json');
    expect(JSON.stringify(category)).toContain('partials/shop/list/_sort_bar.json');

    const isSortSelect = (n: any) => n.name === 'Select' && n.props?.name === 'sort';
    expect(collect(filterBar, isSortSelect)).toHaveLength(0);
    expect(collect(category, isSortSelect)).toHaveLength(0);
    expect(collect(sortBar, isSortSelect)).toHaveLength(1);
  });

  // @scenario case=c02
  // @effects sort_bar_partial_is_shared
  it('정렬은 보던 화면 주소에 남아 다른 조건을 지키며 1쪽으로 이동하고 정렬 값 다섯 개가 그대로다', () => {
    const select = collect(sortBar, (n) => n.name === 'Select' && n.props?.name === 'sort')[0];
    expect(select.props.value).toBe("{{query.sort ?? 'latest'}}");
    const text = JSON.stringify(select);
    for (const value of ['latest', 'sales', 'reviews', 'price_asc', 'price_desc']) {
      expect(text).toContain(`"value":"${value}"`);
    }
    const nav = collect(select, (n) => n.handler === 'navigate')[0];
    expect(nav.params.path).toBe('{{route.path}}');
    expect(nav.params.mergeQuery).toBe(true);
    expect(nav.params.query).toEqual({ sort: '{{$event.target.value}}', page: '1' });
  });

  // @scenario case=c03
  // @effects exclude_sold_out_check_reads_and_writes_query
  it('품절 제외 체크가 주소 쿼리를 읽고 같은 화면 주소로 1쪽 이동한다', () => {
    const check = collect(sortBar, (n) => n.name === 'Checkbox' && n.props?.name === 'exclude_sold_out')[0];
    expect(check).toBeTruthy();
    expect(check.props.checked).toBe("{{query.exclude_sold_out === '1'}}");
    expect(check.props.autoBinding).toBe(false);
    const nav = collect(check, (n) => n.handler === 'navigate')[0];
    expect(nav.params.path).toBe('{{route.path}}');
    expect(nav.params.mergeQuery).toBe(true);
    expect(nav.params.query).toEqual({ exclude_sold_out: "{{$event.target.checked ? '1' : ''}}", page: '' });
    expect(JSON.stringify(sortBar)).toContain('$t:shop.exclude_sold_out');
  });

  // @scenario case=c04
  // @effects reset_button_counts_exclude_sold_out
  it('초기화 단추가 품절 제외도 조건으로 보고 보던 화면 주소로 조건을 모두 비운다', () => {
    const reset = collect(sortBar, (n) => n.name === 'Button' && String(n.if ?? '').includes('query.keyword'))[0];
    expect(reset).toBeTruthy();
    expect(reset.if).toContain("query.exclude_sold_out === '1'");
    expect(reset.if).toContain('query.category');
    const nav = collect(reset, (n) => n.handler === 'navigate')[0];
    expect(nav.params.path).toBe('{{route.path}}');
    expect(nav.params.mergeQuery).toBeUndefined();
  });

  // @scenario case=c05
  // @effects product_datasources_pass_exclude_sold_out
  it('전체 목록과 분류 화면의 상품 데이터소스가 품절 제외 쿼리를 API 로 넘긴다', () => {
    for (const file of ['shop/index.json', 'shop/category.json']) {
      const source = loadJson(file).data_sources.find((d: any) => d.id === 'products');
      expect(source.params.exclude_sold_out, file).toBe("{{query.exclude_sold_out ?? ''}}");
    }
  });
});

describe('통합검색의 품절 제외', () => {
  // @scenario case=c06
  // @effects search_products_tab_has_exclude_sold_out
  it('검색 API 주소에 실리고, 상품 탭에 체크가 있어 검색 조건을 지키며 첫 쪽으로 이동한다', () => {
    const search = loadJson('search/index.json');
    const source = search.data_sources.find((d: any) => d.id === 'searchResults');
    expect(source.endpoint).toContain("&exclude_sold_out={{query?.exclude_sold_out ?? ''}}");

    const filters = loadJson('partials/search/_search_filters.json');
    const check = collect(filters, (n) => n.name === 'Checkbox' && n.props?.name === 'exclude_sold_out')[0];
    expect(check).toBeTruthy();
    expect(check.props.checked).toBe("{{query?.exclude_sold_out === '1'}}");
    const wrapper = collect(filters, (n) => n.name === 'Label' && String(n.if ?? '').includes("query?.type === 'products'") && JSON.stringify(n).includes('exclude_sold_out'))[0];
    expect(wrapper, '상품 탭에서만 보여야 한다').toBeTruthy();
    expect(String(wrapper.if)).toContain("activeModules?.includes('sirsoft-ecommerce')");
    const nav = collect(check, (n) => n.handler === 'navigate')[0];
    expect(nav.params.path).toBe('/search');
    expect(nav.params.mergeQuery).toBe(true);
    expect(nav.params.query).toEqual({ exclude_sold_out: "{{$event.target.checked ? '1' : ''}}", page: '', cursor: '' });
  });
});
