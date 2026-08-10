/**
 * @file cartWishlistImageConsumers.test.ts
 * @description 카트·찜목록 — 상품 이미지 소비 경로 고정 (#518 / 공개 #76)
 *
 * 배경:
 * 카트·찜목록 조회가 `product.images` 를 전량 hydration 하고 있었다. 두 화면 모두 상품
 * 썸네일 1장만 그리므로 **컬럼을 좁혀** 로드하도록 바꿨다(`images:id,product_id,hash,
 * is_thumbnail,sort_order`). 응답에는 애초에 배열이 직렬화되지 않으므로 **응답 형태는 불변**이고
 * DB/메모리만 줄어든다.
 *
 * 이 방식이 안전한 근거는 하나다 — 화면이 배열을 읽지 않는다는 것. 그래서 그 사실을 고정한다.
 * 누군가 화면에 `product.images` 를 쓰기 시작하면 좁혀둔 컬럼으로는 부족해질 수 있고, 그때는
 * 이 테스트가 먼저 깨져 알려준다.
 *
 * 반대로 썸네일 경로(`thumbnail_url`)는 반드시 살아 있어야 한다. 서버는 이 값을 좁혀 읽은
 * 컬럼(`hash`/`is_thumbnail`/`sort_order`)으로 조립하므로, 그 컬럼 집합을 더 줄이면 대표
 * 미지정 상품의 폴백이 깨진다.
 *
 * 마킹은 아래 describe 블록마다 따로 붙인다 — 한 docblock 에서는 @scenario 가 첫 줄만 읽혀,
 * 여기에 두 화면을 나열하면 뒤엣것이 조용히 누락된다.
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

const REPO_ROOT = path.resolve(__dirname, '../../../../../../..');

const CART_ITEM = path.join(
    REPO_ROOT,
    'templates/_bundled/sirsoft-basic/layouts/partials/shop/_cart_item.json',
);
const WISHLIST_LIST = path.join(
    REPO_ROOT,
    'templates/_bundled/sirsoft-basic/layouts/partials/mypage/wishlist/_list.json',
);
const PRODUCT_CARD = path.join(
    REPO_ROOT,
    'templates/_bundled/sirsoft-basic/src/components/composite/ProductCard.tsx',
);

/**
 * 파일 원문을 읽습니다.
 *
 * @param filePath 대상 파일 절대 경로
 * @returns 파일 원문
 */
function readRaw(filePath: string): string {
    return fs.readFileSync(filePath, 'utf-8');
}

/**
 * @scenario surface=cart,observation=consumer_screen
 * @effects cart_and_wishlist_consume_thumbnail_only
 */
describe('카트 — 상품 이미지 소비 (#518 / 공개 #76)', () => {
    it('썸네일 1장만 그린다 (이미지 배열을 순회하지 않는다)', () => {
        const raw = readRaw(CART_ITEM);

        expect(raw, '카트 항목이 상품 썸네일을 그려야 한다').toContain(
            'item.product.thumbnail_url',
        );

        // 배열을 읽기 시작하면 좁혀둔 컬럼으로 부족해질 수 있다.
        expect(raw).not.toContain('item.product.images');
    });
});

/**
 * @scenario surface=wishlist,observation=consumer_screen
 * @effects cart_and_wishlist_consume_thumbnail_only
 */
describe('찜목록 — 상품 이미지 소비 (#518 / 공개 #76)', () => {
    it('상품 카드에 상품 객체를 넘기고, 배열을 직접 읽지 않는다', () => {
        const raw = readRaw(WISHLIST_LIST);

        expect(raw, '찜목록이 상품 카드로 렌더한다').toContain('"product": "{{item.product}}"');
        expect(raw).not.toContain('item.product.images');
    });

    it('상품 카드가 썸네일 필드로 이미지를 그린다', () => {
        const raw = readRaw(PRODUCT_CARD);

        // 찜목록의 이미지는 이 컴포넌트가 그린다 — 레이아웃만 보면 소비 경로가 보이지 않는다.
        expect(raw).toContain('product.thumbnail_url');
    });

    it('상품 카드가 이미지 배열을 쓰지 않는다', () => {
        const raw = readRaw(PRODUCT_CARD);

        expect(raw).not.toMatch(/product\.images\b/);
    });
});
