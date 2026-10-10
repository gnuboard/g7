/**
 * @file productListThumbnailAlt.test.ts
 * @description 관리자 상품목록 썸네일 대체 텍스트 회귀
 *
 * 목록 행의 `name` 은 언어별 객체(`{ko, en}`)라 `alt="{{row.name}}"` 로 두면 브라우저가
 * `[object Object]` 를 읽는다(스크린 리더가 상품 이름 대신 이 문자열을 읽는다).
 * 행에는 관리자 언어로 고른 `name_localized` 가 함께 내려오므로 그것을 쓴다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const DATAGRID_PATH = path.resolve(
    __dirname,
    '../../../layouts/admin/partials/admin_ecommerce_product_list/_partial_product_datagrid.json'
);

/**
 * 트리를 순회하며 조건에 맞는 노드를 모읍니다.
 *
 * @param node 시작 노드
 * @param predicate 수집 조건
 * @return 일치 노드 목록
 */
function collectNodes(node: any, predicate: (n: any) => boolean): any[] {
    const found: any[] = [];
    const walk = (current: any): void => {
        if (Array.isArray(current)) {
            current.forEach(walk);

            return;
        }
        if (current && typeof current === 'object') {
            if (predicate(current)) {
                found.push(current);
            }
            Object.values(current).forEach(walk);
        }
    };
    walk(node);

    return found;
}

describe('관리자 상품목록 썸네일 alt', () => {
    const layout = JSON.parse(fs.readFileSync(DATAGRID_PATH, 'utf8'));
    const thumbnails = collectNodes(
        layout,
        (n) => typeof n?.props?.src === 'string' && n.props.src.includes('row.thumbnail_url')
    );

    /**
     * @scenario case=c01
     * @effects product_list_thumbnail_alt
     */
    it('썸네일 이미지가 있다', () => {
        expect(thumbnails.length).toBeGreaterThan(0);
    });

    /**
     * @scenario case=c02
     * @effects product_list_thumbnail_alt
     */
    it('alt 는 언어별 객체가 아니라 관리자 언어 이름을 쓴다', () => {
        for (const img of thumbnails) {
            expect(img.props.alt).not.toBe('{{row.name}}');
            expect(img.props.alt).toContain('row.name_localized');
        }
    });
});
