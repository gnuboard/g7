/**
 * @file search-product-item-list-thumbnail.test.tsx
 * @description 통합검색 상품 리스트형 아이템 썸네일 키 정정 회귀 (공개 #22 부수 — 별건 P3-A)
 *
 * 회귀 배경: `_item_list.json` 이 生産측(SearchProductsListener)이 방출하지 않는
 * `product.thumbnail` 키를 바인딩해 리스트형 썸네일 src 가 항상 비었다 (카드형은 정상).
 * 정정: `product.thumbnail_url` 바인딩 + if 가드 + 부재 시 placeholder.
 *
 * 검증 방식: 실제 partial JSON 을 iteration 래퍼에 넣어 DynamicRenderer 로 렌더링,
 * thumbnail_url 존재/부재 두 케이스의 DOM 을 단언한다.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

import itemListPartial from '../../layouts/partials/search/products/_item_list.json';

// ========== 테스트용 컴포넌트 ==========

const TestDiv: React.FC<{ className?: string; children?: React.ReactNode }> = ({ className, children }) => (
    <div className={className}>{children}</div>
);

const TestSpan: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> = ({ className, children, text }) => (
    <span className={className}>{children || text}</span>
);

const TestButton: React.FC<{ className?: string; children?: React.ReactNode }> = ({ className, children }) => (
    <button type="button" className={className}>{children}</button>
);

const TestImg: React.FC<{ src?: string; alt?: string; className?: string }> = ({ src, alt, className }) => (
    <img data-testid="product-thumb" src={src} alt={alt} className={className} />
);

const TestIcon: React.FC<{ name?: string; className?: string }> = ({ name, className }) => (
    <i data-testid={`icon-${name}`} className={className} />
);

const TestHtmlContent: React.FC<{ content?: string; className?: string }> = ({ content, className }) => (
    <div className={className}>{content}</div>
);

const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

function setupTestRegistry(): void {
    const registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
        Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
        Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
        Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
        Img: { component: TestImg, metadata: { name: 'Img', type: 'basic' } },
        Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
        HtmlContent: { component: TestHtmlContent, metadata: { name: 'HtmlContent', type: 'composite' } },
        // iteration 렌더는 Fragment 래퍼를 요구한다 — 미등록 시 반복 전체가 조용히 빈 출력
        Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    };
}

/**
 * 실제 partial 을 iteration 래퍼에 넣은 테스트 레이아웃을 만든다.
 *
 * @param products 검색 결과 상품 배열 (SearchProductsListener 방출 형태)
 */
const testLayout = {
    version: '1.0.0',
    layout_name: 'test/search-item-list',
    components: [
        {
            type: 'basic',
            name: 'Div',
            iteration: {
                source: 'search_products.data',
                item_var: 'product',
                index_var: 'product_index',
            },
            children: [itemListPartial],
        },
    ],
};

/**
 * 검색 결과 상품 배열을 initialData 컨텍스트로 넘겨 테스트 유틸을 만든다.
 *
 * @param products 검색 결과 상품 배열 (SearchProductsListener 방출 형태)
 */
function createUtils(products: Array<Record<string, unknown>>) {
    return createLayoutTest(testLayout as any, {
        componentRegistry: ComponentRegistry.getInstance(),
        initialData: { search_products: { data: products } },
    } as any);
}

describe('통합검색 상품 리스트형 썸네일 (partials/search/products/_item_list.json)', () => {
    let cleanup: (() => void) | null = null;

    afterEach(() => {
        cleanup?.();
        cleanup = null;
    });

    it('thumbnail_url 이 있으면 그 값으로 Img 가 렌더된다 (死키 thumbnail 미의존)', async () => {
        setupTestRegistry();
        const utils = createUtils([
            {
                id: 1,
                name: '검색 상품',
                category_name: '카테고리',
                thumbnail_url: '/api/plugins/sirsoft-ckeditor5/images/abc123',
                selling_price_formatted: '10,000원',
            },
        ]);
        cleanup = () => utils.cleanup();

        await utils.render();

        const img = screen.getByTestId('product-thumb');
        expect(img.getAttribute('src')).toBe('/api/plugins/sirsoft-ckeditor5/images/abc123');
        expect(screen.queryByTestId('icon-image')).toBeNull();
    });

    it('thumbnail_url 이 없으면 Img 대신 placeholder 아이콘이 렌더된다', async () => {
        setupTestRegistry();
        const utils = createUtils([
            {
                id: 2,
                name: '이미지 없는 상품',
                category_name: '카테고리',
                thumbnail_url: null,
                selling_price_formatted: '5,000원',
            },
        ]);
        cleanup = () => utils.cleanup();

        await utils.render();

        expect(screen.queryByTestId('product-thumb')).toBeNull();
        expect(screen.getByTestId('icon-image')).toBeTruthy();
    });
});
