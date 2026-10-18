/**
 * 기본 사용자 템플릿(sirsoft-basic) 쇼핑·마이페이지 화면의 abilities 게이트 계약 테스트 (7.1.0 출시 전 점검 결함 수정)
 *
 * @description
 * 이 모듈의 사용자 API 가 싣는 abilities 로 템플릿 화면의 신청·구매 버튼이 잠기는지 굽기 산출 `.json` 에서 고정한다.
 * 서버는 같은 권한으로 403 을 낸다. 결함 ID 는 점검 리포트의 묶음·영역 번호를 따른다.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * 저장소 루트(artisan 파일 기준)를 위로 훑어 찾는다.
 *
 * @returns 저장소 루트 절대경로
 */
function repoRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 12; depth++) {
        if (fs.existsSync(path.join(current, 'artisan'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('저장소 루트를 찾지 못했습니다.');
}

/**
 * sirsoft-basic 레이아웃 `.json` 을 문자열로 읽는다.
 *
 * @param relative `layouts/` 아래 상대 경로
 * @returns 파일 내용
 */
function basicLayout(relative: string): string {
    return fs.readFileSync(path.join(repoRoot(), 'templates/_bundled/sirsoft-basic/layouts', relative), 'utf-8');
}

describe.each([
    ['회원 주문 상세', 'mypage/orders/show.json'],
    ['비회원 주문 조회', 'shop/guest_order_show.json'],
])('R3 — %s 의 클레임 신청 버튼', (_label, file) => {
    const source = basicLayout(file);

    /**
     * @scenario surface=user, permission=missing
     * @effects user_claim_buttons_locked_by_abilities
     */
    it('세 버튼 모두 신청 권한(can_request)으로 잠긴다', () => {
        expect(source.match(/claimable\?\.data\?\.abilities\?\.can_request !== true/g)?.length ?? 0).toBeGreaterThanOrEqual(3);
    });

    /**
     * @scenario surface=user, permission=missing
     * @effects user_claim_buttons_locked_by_abilities
     */
    it('[취소·환불 신청]은 바로 취소되는 상품을 고르면 주문 취소 권한(can_cancel)도 본다', () => {
        expect(source).toContain("refund_branch === 'auto') && claimable?.data?.abilities?.can_cancel !== true");
    });

    /**
     * @scenario surface=user, permission=missing
     * @effects user_claim_buttons_locked_by_abilities
     */
    it('권한 때문에 잠긴 이유를 안내한다', () => {
        expect(source).toContain('$t:claim.apply.no_request_permission');
        expect(source).toContain('$t:claim.apply.no_cancel_permission');
    });
});

describe('G08 — 다음 우편번호 [주소 검색] 버튼은 호스트의 읽기 전용 상태로 잠긴다', () => {
    const read = (relative: string): string => fs.readFileSync(path.join(repoRoot(), relative), 'utf-8');

    /**
     * @scenario surface=user, permission=missing
     * @effects address_search_button_follows_host_readonly
     */
    it('주입 조각이 호스트가 넘긴 disabled 로 버튼을 잠근다', () => {
        const fragment = read('plugins/_bundled/sirsoft-daum_postcode/resources/extensions/ecommerce-address-search.json');
        expect(fragment).toContain("String(extensionPointProps.disabled ?? false) === 'true'");
    });

    /**
     * @scenario surface=user, permission=missing
     * @effects address_search_button_follows_host_readonly
     */
    it.each([
        ['이커머스 설정 기본정보 탭', 'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_tab_basic_info.json', '_computed.isReadOnly'],
        ['주문 상세 배송지', 'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_detail/_partial_order_info.json', 'order?.data?.abilities?.can_update !== true'],
    ])('%s 확장점이 읽기 전용 상태를 disabled 로 넘긴다', (_label, file, expr) => {
        const source = JSON.parse(read(file));
        const find = (node: any): any => {
            if (!node || typeof node !== 'object') return null;
            if (!Array.isArray(node) && node.type === 'extension_point' && node.name === 'address_search_slot') return node;
            for (const value of Array.isArray(node) ? node : Object.values(node)) {
                const found = find(value);
                if (found) return found;
            }
            return null;
        };
        const slot = find(source);
        expect(slot).not.toBeNull();
        expect(JSON.stringify(slot.props ?? {})).toContain(`"disabled":"{{${expr}}}"`);
    });
});

describe('G31 — 회원 클레임 [결제하기]는 결제 권한(can_pay)으로 잠긴다', () => {
    /**
     * @scenario surface=user, permission=missing
     * @effects user_claim_pay_button_locked
     */
    it('신청서 상세의 결제 버튼이 can_pay 를 본다 ([철회]·[수정]은 서버 플래그에 권한이 접혀 있다)', () => {
        expect(basicLayout('partials/mypage/claims/_detail_claim_info.json')).toContain('claim.data?.claim?.can_pay !== true');
    });
});

describe('basic-shop#3 — 구매 버튼은 주문 권한(can_order)으로 잠긴다', () => {
    /**
     * @scenario surface=user, permission=missing
     * @effects user_purchase_buttons_locked
     */
    it.each([
        ['partials/shop/detail/_purchase_card.json', 'product.data?.abilities?.can_order !== true'],
        ['partials/shop/_cart_summary.json', 'cartItems.data?.abilities?.can_order !== true'],
        ['partials/shop/_checkout_summary.json', 'checkoutData.data?.abilities?.can_order !== true'],
    ])('%s', (file, expr) => {
        const source = basicLayout(file);
        expect(source).toContain(expr);
        expect(source).toContain('$t:shop.order_permission_denied');
    });
});

describe('basic-shop#5 — 주문서 배송지 모달의 수정·삭제는 서버 abilities 를 본다', () => {
    /**
     * @scenario surface=user, permission=missing
     * @effects checkout_address_modal_follows_abilities
     */
    it('기본 배송지 여부를 화면이 다시 해석하지 않는다', () => {
        const source = basicLayout('partials/shop/_modal_address_manage.json');
        expect(source).toContain('addr.abilities?.can_update === true');
        expect(source).toContain('addr.abilities?.can_delete === true');
        expect(source).not.toContain('"if":"{{!addr.is_default}}"');
    });
});
