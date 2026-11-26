/**
 * @file reviewImageConsumers.test.ts
 * @description 리뷰 목록 첨부 이미지 — 소비처 고정 (공개 #76)
 *
 * 회귀 배경:
 * 목록 응답에서 리뷰 첨부 이미지를 빼면서, 관리자 리뷰 목록은 "확장 행이 썸네일을 그린다" 는
 * 실측으로 유지했으나 **공개 상품상세의 리뷰 탭은 놓쳤다**. `shop/show.json` 만 열어보고
 * "리뷰 첨부는 표시하지 않는다" 고 판단했는데, 실제로 그리는 곳은 그 파일이 include 하는
 * 파셜(`partials/shop/detail/_tab_reviews.json`)이었다. 7.1.6부터는 후기 한 장을 그 탭이 다시
 * include 하는 공용 카드 조각(`templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_review_card.json`)이 그린다 — 후기 모아보기 화면도
 * 같은 조각을 쓴다. 그래서 첨부 소비 단언은 카드 조각을 보고, 탭 → 카드 연결도 함께 확인한다.
 *
 * 이 결함은 조용하다. 썸네일이 `{{(review.images ?? []).length > 0}}` 가드 뒤에 있어 배열이
 * 없으면 예외도 콘솔 경고도 없이 그냥 안 그려진다. 응답만 보는 백엔드 테스트는 "뺐다" 를
 * 확인할 뿐이고, 그 값을 화면이 쓰고 있었다는 사실은 잡지 못한다.
 *
 * 그래서 이 파일은 **화면이 무엇을 그리는가** 를 고정한다. 여기서 참조가 발견되는 한, 그
 * 엔드포인트의 목록 응답은 첨부 이미지를 실어야 한다.
 *
 * 마킹은 각 it 바로 앞에 붙인다 — 파일·describe docblock 의 마킹은 어느 시험이 재는지 가리키지 않아
 * 커버로 세지 않는다.
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

const REPO_ROOT = path.resolve(__dirname, '../../../../../../..');

const PUBLIC_REVIEW_TAB = path.join(
    REPO_ROOT,
    'templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_tab_reviews.json',
);
const PUBLIC_REVIEW_CARD_PARTIAL = 'partials/shop/detail/_review_card.json';
const PUBLIC_REVIEW_CARD = path.join(
    REPO_ROOT,
    'templates/_bundled/sirsoft-basic/layouts',
    PUBLIC_REVIEW_CARD_PARTIAL,
);
const PUBLIC_PRODUCT_DETAIL = path.join(
    REPO_ROOT,
    'templates/_bundled/sirsoft-basic/layouts/shop/show.json',
);
const ADMIN_REVIEW_INDEX = path.join(
    REPO_ROOT,
    'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_product_review_index.json',
);

/**
 * 레이아웃 파일 원문을 읽습니다.
 *
 * @param filePath 레이아웃 파일 절대 경로
 * @returns 파일 원문
 */
function readRaw(filePath: string): string {
    return fs.readFileSync(filePath, 'utf-8');
}

/**
 * 후기 탭이 그리는 후기 카드 조각 원문을 읽습니다 — 탭이 그 조각을 include 하는지 먼저 확인합니다.
 *
 * @returns 후기 카드 조각 원문
 */
function readReviewCardRawViaTab(): string {
    // 이 연결이 끊기면 아래 소비 단언들이 "쓰이지 않는 파일" 을 검사하게 된다.
    expect(readRaw(PUBLIC_REVIEW_TAB)).toContain(PUBLIC_REVIEW_CARD_PARTIAL);

    return readRaw(PUBLIC_REVIEW_CARD);
}

/**
 * 축 요약(마커 아님 — 평문): surface public_product_review_list, observation consumer_screen. 마킹은 각 it 앞에 있다.
 */
describe('공개 상품상세 리뷰 탭 — 첨부 이미지 소비 (공개 #76)', () => {
    // @scenario surface=public_product_review_list,observation=consumer_screen
    // @effects review_list_images_are_consumed_by_screen
    it('상품상세가 리뷰 탭 파셜을 실제로 포함한다', () => {
        // 이 연결이 끊기면 아래 소비 단언들이 "쓰이지 않는 파일" 을 검사하게 된다.
        expect(readRaw(PUBLIC_PRODUCT_DETAIL)).toContain(
            'partials/shop/detail/_tab_reviews.json',
        );
    });

    // @scenario surface=public_product_review_list,observation=consumer_screen
    // @effects review_list_images_are_consumed_by_screen
    it('리뷰 탭이 공개 리뷰 목록 엔드포인트를 데이터소스로 쓴다', () => {
        const detail = JSON.parse(readRaw(PUBLIC_PRODUCT_DETAIL));
        const source = (detail.data_sources ?? []).find((ds: any) => ds.id === 'reviews');

        expect(source, '리뷰 데이터소스가 있어야 한다').toBeDefined();
        expect(String(source.endpoint)).toContain('/reviews');
    });

    // @scenario surface=public_product_review_list,observation=consumer_screen
    // @effects review_list_images_are_consumed_by_screen
    it('리뷰 탭이 첨부 이미지 썸네일을 그린다 (목록에서 빼면 이 자리가 빈다)', () => {
        const raw = readReviewCardRawViaTab();

        // 썸네일 3장은 각각 배열 인덱스를 직접 읽는다.
        expect(raw).toContain('review.images[0].download_url');
        expect(raw).toContain('review.images[1].download_url');
        expect(raw).toContain('review.images[2].download_url');
    });

    // @scenario surface=public_product_review_list,observation=consumer_screen
    // @effects review_list_images_are_consumed_by_screen
    it('리뷰 탭이 이미지 모달에 첨부 배열 전체를 넘긴다', () => {
        expect(readReviewCardRawViaTab()).toContain('"images": "{{review.images ?? []}}"');
    });

    // @scenario surface=public_product_review_list,observation=consumer_screen
    // @effects review_list_images_are_consumed_by_screen
    it('첨부 개수만으로는 대체되지 않는다 (개수는 노출 조건일 뿐 그림은 배열이 그린다)', () => {
        const raw = readReviewCardRawViaTab();

        // 개수 조건이 먼저 켜지고 그 안에서 배열을 읽는다. 개수만 내려주면 컨테이너는 열리는데
        // 안이 비는 상태가 된다 — 가장 나쁜 형태의 조용한 실패다.
        expect(raw).toContain('review.image_count > 0');
        expect(raw).toContain('(review.images ?? []).length > 0');
    });
});

/**
 * 축 요약(마커 아님 — 평문): surface admin_product_review_list, observation consumer_screen. 마킹은 각 it 앞에 있다.
 */
describe('관리자 리뷰 목록 — 첨부 이미지 소비 (공개 #76)', () => {
    // @scenario surface=admin_product_review_list,observation=consumer_screen
    // @effects review_list_images_are_consumed_by_screen
    it('확장 행이 첨부 이미지를 그리고 미리보기 모달에 배열을 넘긴다', () => {
        const raw = readRaw(ADMIN_REVIEW_INDEX);

        expect(raw).toContain('"rowImages": "{{row.images ?? []}}"');
        expect(raw).toContain('"images": "{{rowImages ?? []}}"');
    });
});
