/**
 * @file couponDeleteConfirmWording.test.ts
 * @description 관리자 쿠폰 삭제 확인 창 안내문 회귀
 *
 * 확인 창이 「쿠폰과 모든 게시글이 영구적으로 삭제됩니다」라고 안내했다 — 게시판 삭제 창 문구를 옮겨 온 것으로,
 * 쿠폰에는 게시글이 없다. 실제 삭제는 쿠폰과 그 쿠폰의 적용 상품·카테고리 지정을 지운다
 * (CouponService::deleteCoupon). 세 언어(ko·en·ja 언어팩) 안내문이 게시글을 말하지 않고
 * 적용 상품·카테고리 지정을 말하는지 확인한다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const MODULE_ROOT = path.resolve(__dirname, '../../../..');
const REPO_ROOT = path.resolve(MODULE_ROOT, '../../..');

const LOCALES = [
    {
        locale: 'ko',
        file: path.join(MODULE_ROOT, 'resources/lang/partial/ko/admin/promotion_coupon.json'),
        forbidden: /게시글/,
        expected: /적용 상품·카테고리/,
    },
    {
        locale: 'en',
        file: path.join(MODULE_ROOT, 'resources/lang/partial/en/admin/promotion_coupon.json'),
        forbidden: /posts?\b/i,
        expected: /product and category/i,
    },
    {
        locale: 'ja',
        file: path.join(REPO_ROOT, 'lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/frontend/partial/admin/promotion_coupon.json'),
        forbidden: /投稿/,
        expected: /対象商品・カテゴリ/,
    },
];

/**
 * 객체에서 점 경로의 값을 꺼냅니다.
 *
 * @param obj 대상 객체
 * @param dotted 점으로 이은 키 경로
 * @return 찾은 값(없으면 undefined)
 */
function pick(obj: any, dotted: string): unknown {
    return dotted.split('.').reduce((acc, key) => (acc == null ? undefined : acc[key]), obj);
}

/**
 * 파일 안에서 쿠폰 삭제 확인 창(`delete.title/message/warning`)을 담은 객체를 찾습니다.
 *
 * @param node 시작 노드
 * @return 삭제 확인 창 문구 객체(없으면 null)
 */
function findDeleteModal(node: any): Record<string, string> | null {
    if (!node || typeof node !== 'object') {
        return null;
    }
    const candidate = node.delete;
    if (candidate && typeof candidate === 'object' && typeof candidate.message === 'string' && typeof candidate.warning === 'string') {
        return candidate;
    }
    for (const value of Object.values(node)) {
        const found = findDeleteModal(value);
        if (found) {
            return found;
        }
    }

    return null;
}

describe.each(LOCALES)('쿠폰 삭제 확인 창 안내문 — $locale', ({ file, forbidden, expected }) => {
    const json = JSON.parse(fs.readFileSync(file, 'utf8'));
    const modal = findDeleteModal(pick(json, 'modals') ?? json);

    /**
     * @scenario case=c01
     * @effects coupon_delete_wording_matches_effect
     */
    it('삭제 확인 창 문구가 있다', () => {
        expect(modal).not.toBeNull();
    });

    /**
     * @scenario case=c02
     * @effects coupon_delete_wording_matches_effect
     */
    it('게시글을 말하지 않고 적용 상품·카테고리 지정이 함께 지워짐을 알린다', () => {
        expect(modal!.message).not.toMatch(forbidden);
        expect(modal!.message).toMatch(expected);
    });
});
