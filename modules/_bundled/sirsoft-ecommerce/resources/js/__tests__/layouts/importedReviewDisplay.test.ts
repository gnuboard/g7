// e2e:allow 레이아웃 JSON 소비 계약(이 파일)과 API 응답(PHPUnit ImportedProductReviewTest)으로 고정 — 이관 후기는 이관 도구가 넣는 데이터라 화면 조작 흐름이 없고, 브라우저 실측은 이관 실데이터로 별도 확인
/**
 * @file importedReviewDisplay.test.ts
 * @description 회원·주문 없이 등록된 후기(다른 쇼핑몰에서 이관) — 화면 소비 계약 고정
 *
 * 이관 후기는 `user`·`orderOption` 이 null 이고 `author_name`(작성자 표시 이름)·`is_imported` 로 온다.
 * 화면이 `row.user?.name` 만 읽으면 작성자 칸이 비고, 주문번호 링크가 `/admin/ecommerce/orders/undefined`
 * 로 걸린다. 둘 다 예외 없이 조용히 틀리므로 레이아웃이 어떤 값을 읽는지를 고정한다.
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

const REPO_ROOT = path.resolve(__dirname, '../../../../../../..');

const ADMIN_REVIEW_INDEX = path.join(
    REPO_ROOT,
    'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_product_review_index.json',
);
const PUBLIC_REVIEW_TAB = path.join(
    REPO_ROOT,
    'templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_tab_reviews.json',
);
// 후기 한 장(작성자·본문·첨부 사진)은 7.1.6(#898)부터 공용 카드 조각이 그린다 — 상품 상세 후기 탭과
// 후기 모아보기 화면(shop/reviews)이 같이 include 한다. 탭은 목록·필터만 갖고 카드는 이 조각에 있다.
const PUBLIC_REVIEW_CARD_PARTIAL = 'partials/shop/detail/_review_card.json';
const PUBLIC_REVIEW_CARD = path.join(
    REPO_ROOT,
    'templates/_bundled/sirsoft-basic/layouts',
    PUBLIC_REVIEW_CARD_PARTIAL,
);
const LANG_DIR = path.join(REPO_ROOT, 'modules/_bundled/sirsoft-ecommerce/resources/lang/partial');

/**
 * 레이아웃 JSON 을 읽습니다.
 *
 * @param filePath 레이아웃 파일 절대 경로
 * @returns 파싱한 레이아웃
 */
function readJson(filePath: string): any {
    return JSON.parse(fs.readFileSync(filePath, 'utf-8'));
}

/**
 * 조건을 만족하는 노드를 깊이 우선으로 모읍니다.
 *
 * @param node 시작 노드
 * @param predicate 판정 함수
 * @returns 만족하는 노드 목록
 */
function collect(node: any, predicate: (n: any) => boolean): any[] {
    const found: any[] = [];
    const walk = (n: any) => {
        if (Array.isArray(n)) {
            n.forEach(walk);
            return;
        }
        if (!n || typeof n !== 'object') {
            return;
        }
        if (predicate(n)) {
            found.push(n);
        }
        Object.values(n).forEach(walk);
    };
    walk(node);

    return found;
}

/**
 * DataGrid 의 열 정의를 field 로 찾습니다.
 *
 * @param layout 레이아웃
 * @param field 열 field
 * @returns 열 정의
 */
function columnOf(layout: any, field: string): any {
    const grid = collect(layout, (n) => n.name === 'DataGrid' && Array.isArray(n.props?.columns))[0];
    expect(grid, '후기 DataGrid 가 있어야 한다').toBeDefined();

    return grid.props.columns.find((c: any) => c.field === field);
}

/**
 * 조합 요약(마커 아님 — 평문): content_mode=text 와 html, existing_row=none
 * 효과 요약(마커 아님 — 평문, 시험별 표식에 있음): imported_review_screens_render_author_and_missing_order
 */
describe('관리자 상품 후기 관리 — 회원·주문 없는 후기 표시', () => {
    const layout = readJson(ADMIN_REVIEW_INDEX);

    // @scenario content_mode=text, existing_row=none
    // @effects imported_review_screens_render_author_and_missing_order
    it('작성자 칸이 회원이 없으면 작성자 표시 이름과 「이관」 표시를 그린다', () => {
        const raw = JSON.stringify(columnOf(layout, 'reviewer'));

        expect(raw).toContain('row.author_name');
        expect(raw).toContain('$t:sirsoft-ecommerce.admin.product_review.table.imported');
        expect(raw).toContain('row.is_imported');
    });

    // @scenario content_mode=text, existing_row=none
    // @effects imported_review_screens_render_author_and_missing_order
    it('주문번호 칸은 주문이 없으면 링크 대신 「—」 를 그린다', () => {
        const column = columnOf(layout, 'order_number');
        const links = collect(column, (n) => n.name === 'A');
        const dashes = collect(column, (n) => n.text === '—');

        expect(links).toHaveLength(1);
        expect(String(links[0].if)).toContain('row.orderOption?.order_number');
        expect(dashes).toHaveLength(1);
        expect(String(dashes[0].if)).toContain('!row.orderOption?.order_number');
    });

    // @scenario content_mode=html, existing_row=none
    // @effects imported_review_screens_render_author_and_missing_order
    it('목록의 내용 칸은 태그를 걷어 낸 본문을 쓴다', () => {
        const raw = JSON.stringify(columnOf(layout, 'content'));

        expect(raw).toContain('row.content_plain');
    });

    // @scenario content_mode=html, existing_row=none
    // @effects imported_review_screens_render_author_and_missing_order
    it('상세 본문은 HTML 모드일 때 HtmlContent 로 그린다', () => {
        const html = collect(layout, (n) => n.name === 'HtmlContent' && String(n.if ?? '').includes("row.content_mode === 'html'"));

        expect(html).toHaveLength(1);
        expect(html[0].props?.isHtml).toBe(true);
    });

    // @scenario content_mode=text, existing_row=none
    // @effects imported_review_screens_render_author_and_missing_order
    it('이미지 미리보기 창에 넘기는 작성자 이름이 회원 없는 후기에서도 채워진다', () => {
        const userNames = collect(layout, (n) => typeof n.userName === 'string').map((n) => n.userName);

        expect(userNames.length).toBeGreaterThan(0);
        userNames.forEach((value) => expect(value).toContain('row.author_name'));
    });

    // @scenario content_mode=text, existing_row=none
    // @effects imported_review_screens_render_author_and_missing_order
    it('「이관」 번역 키가 ko·en 에 있다', () => {
        for (const locale of ['ko', 'en']) {
            const lang = readJson(path.join(LANG_DIR, locale, 'admin/product_review.json'));
            expect(typeof lang.table?.imported, `${locale} table.imported`).toBe('string');
            expect(lang.table.imported.length).toBeGreaterThan(0);
        }
    });
});

/**
 * 조합 요약(마커 아님 — 평문): content_mode=text 와 html, existing_row=none
 * 효과 요약(마커 아님 — 평문, 시험별 표식에 있음): imported_review_screens_render_author_and_missing_order
 */
describe('공개 상품 상세 후기 탭 — 회원·주문 없는 후기 표시', () => {
    const tab = readJson(PUBLIC_REVIEW_TAB);
    // 탭이 실제로 카드 조각을 include 하는지 — 이 연결이 끊기면 아래 단언이 쓰이지 않는 파일을 보게 된다
    const tabIncludesCard = collect(tab, (n) => n.partial === PUBLIC_REVIEW_CARD_PARTIAL).length > 0;
    // 탭과 카드 조각을 한 화면으로 본다 — 후기 한 장의 표시는 어느 쪽에 있든 같은 탭 화면에 그려진다
    const layout = [tab, readJson(PUBLIC_REVIEW_CARD)];
    const raw = JSON.stringify(layout);

    // @scenario content_mode=text, existing_row=none
    // @effects imported_review_screens_render_author_and_missing_order
    it('작성자 이름은 작성자 표시 이름을 먼저 읽는다', () => {
        expect(tabIncludesCard, '후기 탭이 후기 카드 조각을 include 해야 한다').toBe(true);
        expect(raw).toContain('review.author_name ?? review.user?.name');
        expect(raw).not.toMatch(/"\{\{review\.user\?\.name\}\}"/);
    });

    // @scenario content_mode=html, existing_row=none
    // @effects imported_review_screens_render_author_and_missing_order
    it('HTML 본문은 HtmlContent 로, 텍스트 본문은 기존 문단으로 그린다', () => {
        expect(tabIncludesCard, '후기 탭이 후기 카드 조각을 include 해야 한다').toBe(true);
        const html = collect(layout, (n) => n.name === 'HtmlContent' && String(n.if ?? '').includes("review.content_mode === 'html'"));
        const text = collect(layout, (n) => n.name === 'P' && String(n.if ?? '').includes("review.content_mode !== 'html'"));

        expect(html).toHaveLength(1);
        expect(text).toHaveLength(1);
    });
});
