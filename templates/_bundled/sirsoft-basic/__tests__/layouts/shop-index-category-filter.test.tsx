/**
 * @file shop-index-category-filter.test.tsx
 * @description shop/index 카테고리 버튼 → ?category= navigate + active 표시 검증 (U6②)
 *
 * 테스트 대상: templates/.../layouts/shop/index.json
 *
 * 검증 항목:
 * - products 데이터소스가 category_id 를 query.category 에 바인딩
 * - 카테고리 버튼 navigate 가 /products + query.category = cat.id 로 이동 (slug 페이지 이동 금지)
 * - "전체" 버튼은 query 비움
 * - 선택 카테고리 active className 조건부 적용
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const indexPath = path.resolve(__dirname, '../../layouts/shop/index.json');
const indexLayout = JSON.parse(fs.readFileSync(indexPath, 'utf-8'));

/** 재귀적으로 조건에 맞는 노드를 찾는다 */
function findNodes(node: any, predicate: (n: any) => boolean, results: any[] = []): any[] {
  if (!node || typeof node !== 'object') return results;
  if (predicate(node)) results.push(node);
  for (const key of Object.keys(node)) {
    const value = node[key];
    if (Array.isArray(value)) {
      value.forEach((v) => findNodes(v, predicate, results));
    } else if (value && typeof value === 'object') {
      findNodes(value, predicate, results);
    }
  }
  return results;
}

describe('shop/index 카테고리 필터 (U6②)', () => {
  it('products 데이터소스가 category_id 를 query.category 에 바인딩해야 함', () => {
    const products = indexLayout.data_sources.find((ds: any) => ds.id === 'products');
    expect(products).toBeDefined();
    expect(products.params.category_id).toBe("{{query.category ?? ''}}");
  });

  it('iteration 카테고리 버튼이 /products 로 이동하며 query.category = cat.id 를 설정해야 함', () => {
    // iteration 버튼은 cat?.id 를 query.category 로 전달
    const navActions = findNodes(
      indexLayout,
      (n) =>
        n.handler === 'navigate' &&
        n.params?.query &&
        n.params.query.category === '{{cat?.id}}',
    );
    expect(navActions.length).toBeGreaterThan(0);

    const action = navActions[0];
    // slug 페이지(/category/{slug}) 이동이 아닌 /products 쿼리 필터여야 함
    expect(action.params.path).toContain('/products');
    expect(action.params.query.page).toBe('1');
  });

  it('카테고리 슬러그 페이지로 이동하는 dead binding 이 제거되어야 함', () => {
    const slugNavs = findNodes(
      indexLayout,
      (n) =>
        n.handler === 'navigate' &&
        typeof n.params?.path === 'string' &&
        n.params.path.includes('/category/{{cat?.slug}}'),
    );
    expect(slugNavs.length).toBe(0);
  });

  it('선택 카테고리 active className 이 query.category 와 cat.id 비교로 조건부 적용되어야 함', () => {
    // iteration 버튼 className 에 query.category == cat?.id 조건 표현식 존재
    const conditionalClassNodes = findNodes(
      indexLayout,
      (n) =>
        typeof n.className === 'string' &&
        n.className.includes('query.category == cat?.id'),
    );
    expect(conditionalClassNodes.length).toBeGreaterThan(0);

    // "전체" 버튼은 !query.category 조건으로 active
    const allButtonClassNodes = findNodes(
      indexLayout,
      (n) =>
        typeof n.className === 'string' &&
        n.className.includes('!query.category ?'),
    );
    expect(allButtonClassNodes.length).toBeGreaterThan(0);
  });
});
