/**
 * @file productCopySourceCode.test.ts
 * @description 상품 복사 등록 화면 「원본 상품」 칸의 상품코드 회귀
 *
 * 복사 데이터 API 는 새 상품에 쓸 코드를 미리 채번해 `product_code` 로 내려준다. 원본 칸이 이 값을
 * 찍으면 원본이 아닌 새 코드가 「원본 상품의 상품코드」로 보인다. 원본 코드는 진입 주소의
 * `copy_id`(복사 창이 원본 상품코드로 채운다)에서 읽는다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const FORM_PATH = path.resolve(__dirname, '../../../layouts/admin/admin_ecommerce_product_form.json');

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

describe('상품 복사 등록 — 원본 상품 칸 상품코드', () => {
    const layout = JSON.parse(fs.readFileSync(FORM_PATH, 'utf8'));
    const section = collectNodes(layout, (n) => n?.id === 'copy_source_info_section')[0];
    const codeTexts = collectNodes(
        section,
        (n) => typeof n?.text === 'string' && n.text.includes('fields.product_code')
    );

    /**
     * @scenario case=c01
     * @effects product_copy_source_code
     */
    it('원본 칸에 상품코드 줄이 있다', () => {
        expect(section).toBeDefined();
        expect(codeTexts.length).toBe(1);
    });

    /**
     * @scenario case=c02
     * @effects product_copy_source_code
     */
    it('새로 채번한 코드가 아니라 원본 코드(copy_id)를 찍는다', () => {
        expect(codeTexts[0].text).not.toContain('copy_source?.data?.product_code');
        expect(codeTexts[0].text).toContain('query.copy_id');
    });
});
