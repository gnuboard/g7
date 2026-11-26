/**
 * 홈 상품 섹션 — 쇼핑몰만 켠 사이트의 첫 화면
 *
 * @description
 * 게시판을 끈 사이트는 홈의 통계·최근 글·인기 게시판·게시판 요약이 모두 빠져 환영 카드만 남는다. 쇼핑몰이 켜져 있으면
 * 「인기 상품」「신상품」(각 8개) 섹션을 게시판 섹션 아래·방문자 수 위에 둔다. 쇼핑 목록 화면(shop/index)과 같은
 * 데이터소스 id·endpoint 를 써서 그 화면의 상품 줄 조각을 그대로 include 한다 — id 가 다르면 조각이 빈 값을 읽는다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

type Node = Record<string, any>;

const LAYOUT_ROOT = path.resolve(__dirname, '../../layouts');
const SHOP = "_global.activeModules?.includes('sirsoft-ecommerce') ?? true";
// 템플릿 설정 — 홈 상품 개수(`home.product_count`, 기본 8)와 상품 섹션 표시(`home.show_shop_sections`, 기본 켜짐)
const PRODUCT_COUNT = '{{_global.templateSettings?.home?.product_count ?? 8}}';
const SHOW_SHOP = '_global.templateSettings?.home?.show_shop_sections ?? true';

const load = (rel: string): Node => JSON.parse(fs.readFileSync(path.join(LAYOUT_ROOT, `${rel}.json`), 'utf8'));

/** 트리에서 노드(이름 또는 partial 이 있는 객체)를 문서 순서대로 모은다. */
function nodes(tree: unknown, out: Node[] = []): Node[] {
    if (Array.isArray(tree)) {
        tree.forEach((child) => nodes(child, out));
        return out;
    }
    if (tree && typeof tree === 'object') {
        const obj = tree as Node;
        if (typeof obj.name === 'string' || typeof obj.partial === 'string') out.push(obj);
        Object.values(obj).forEach((value) => nodes(value, out));
    }
    return out;
}

describe('홈 상품 섹션', () => {
    const home = load('home');
    const shopIndex = load('shop/index');

    // @scenario surface=home
    // @effects home_shop_sections
    it.each(['popularProducts', 'newProducts'])('%s 는 쇼핑 목록 화면과 같은 endpoint 이고 쇼핑몰 가드·401/403/404 삼킴을 단다', (id) => {
        const ds = home.data_sources.find((d: Node) => d.id === id);
        const original = shopIndex.data_sources.find((d: Node) => d.id === id);
        // 쇼핑 목록 화면과 같은 endpoint — 개수만 템플릿 설정을 따른다(기본 8 은 목록 화면 값과 같다)
        expect(ds.endpoint).toBe(original.endpoint.replace('limit=8', `limit=${PRODUCT_COUNT}`));
        expect(original.endpoint).toMatch(/^\/api\/modules\/sirsoft-ecommerce\/products\/(popular|new)\?limit=8$/);
        // 섹션을 끄면 받지도 않는다(템플릿 설정 `home.show_shop_sections`)
        expect(ds.if).toBe(`{{(${SHOP}) && (${SHOW_SHOP})}}`);
        expect(ds.fallback).toEqual({ data: [] });
        ['401', '403', '404'].forEach((code) => expect(ds.errorHandling?.[code]?.handler, code).toBe('suppress'));
    });

    // @scenario surface=home
    // @effects home_shop_sections
    it('상품 섹션은 쇼핑 목록 화면의 두 줄 조각을 그대로 include 하고 쇼핑몰 가드를 단다', () => {
        const section = nodes(home.slots.content).find((n) => n.id === 'home_shop_sections')!;
        expect(section.if).toBe(`{{(${SHOP}) && (${SHOW_SHOP})}}`);
        expect(nodes(section.children).map((n) => n.partial)).toEqual([
            'partials/shop/list/_popular_products.json',
            'partials/shop/list/_new_products.json',
        ]);
    });

    // @scenario surface=home
    // @effects home_shop_sections
    it('상품 섹션은 게시판 요약 줄 아래·방문자 수 위에 있다', () => {
        const order = nodes(home.slots.content)
            .map((n) => n.id ?? n.partial)
            .filter((key) => ['home_board_summaries', 'home_shop_sections', 'partials/_visitor_counter.json'].includes(key));
        expect(order).toEqual(['home_board_summaries', 'home_shop_sections', 'partials/_visitor_counter.json']);
    });

    // @scenario surface=home
    // @effects home_shop_sections
    it('두 상품 줄 조각은 데이터가 없으면 스스로 숨는다(빈 섹션 제목이 남지 않는다)', () => {
        expect(load('partials/shop/list/_popular_products').if).toContain('popularProducts.data.length > 0');
        expect(load('partials/shop/list/_new_products').if).toContain('newProducts.data.length > 0');
    });
});
