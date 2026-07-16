/**
 * 상품 목록 no_category / no_brand boolean 바인딩 검증 (A19②)
 *
 * @description
 * - named_actions.searchProducts 와 products 데이터소스 params 의 no_category/no_brand 바인딩이
 *   문자열 'true' 가 아닌 실제 boolean(true/null)으로 평가되는 표현식을 사용하는지 검증.
 * - 회귀: 문자열 'true' 가 Laravel boolean rule 에서 거부되어 422 가 나던 결함 가드.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';

import productList from '../../../layouts/admin/admin_ecommerce_product_list.json';

describe('상품 목록 no_category/no_brand boolean 바인딩 (A19②)', () => {
    it('searchProducts named_action 의 no_category 표현식이 boolean true/null 로 평가되어야 함', () => {
        const query = (productList as any).named_actions.searchProducts.params.query;

        // 문자열 'true' 리터럴을 결과로 쓰지 않아야 함 (? true : null 형태)
        expect(query.no_category).toContain('? true : null');
        expect(query.no_category).not.toContain("? 'true' : null");
        expect(query.no_brand).toContain('? true : null');
        expect(query.no_brand).not.toContain("? 'true' : null");
    });

    it('products 데이터소스 params 의 no_category 가 boolean 으로 변환되어야 함', () => {
        const products = (productList as any).data_sources.find(
            (ds: any) => ds.id === 'products',
        );
        expect(products).toBeDefined();

        // query.no_category === 'true' ? true : null  (raw {{query.no_category}} 문자열 패스스루 금지)
        expect(products.params.no_category).toContain("=== 'true' ? true : null");
        expect(products.params.no_category).not.toBe('{{query.no_category}}');
        expect(products.params.no_brand).toContain("=== 'true' ? true : null");
        expect(products.params.no_brand).not.toBe('{{query.no_brand}}');
    });
});
