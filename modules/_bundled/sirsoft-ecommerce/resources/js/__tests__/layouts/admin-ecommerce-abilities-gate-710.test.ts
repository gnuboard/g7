/**
 * 이커머스 관리자 화면 abilities 게이트 계약 테스트 (7.1.0 출시 전 점검 결함 수정)
 *
 * @description
 * 권한이 없는 운영자에게 쓰기 컨트롤이 잠기는지(버튼 `disabled`, 행 동작 `disabledField`, 체크박스 잠금)를
 * 굽기 산출 `.json` 에서 고정한다. 잠금 키는 서버가 같은 권한으로 403 을 내는 응답 abilities 다.
 * 결함 ID 는 점검 리포트의 묶음(G·R·S)·영역 번호를 따른다.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * 모듈 루트(module.json 기준)를 위로 훑어 찾는다.
 *
 * @returns 모듈 루트 절대경로
 */
function moduleRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 10; depth++) {
        if (fs.existsSync(path.join(current, 'module.json'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('module.json 을 가진 모듈 루트를 찾지 못했습니다.');
}

/**
 * 관리자 레이아웃 `.json` 을 읽는다.
 *
 * @param relative `resources/layouts/admin/` 아래 상대 경로
 * @returns 파싱한 레이아웃
 */
function layout(relative: string): any {
    return JSON.parse(fs.readFileSync(path.join(moduleRoot(), 'resources/layouts/admin', relative), 'utf-8'));
}

/**
 * 트리에서 id 가 같은 첫 노드를 찾는다.
 *
 * @param node 탐색 시작 노드
 * @param id 찾을 id
 * @returns 찾은 노드 또는 null
 */
function byId(node: any, id: string): any {
    if (!node || typeof node !== 'object') return null;
    if (!Array.isArray(node) && node.id === id) return node;

    for (const value of Array.isArray(node) ? node : Object.values(node)) {
        const found = byId(value, id);
        if (found) return found;
    }

    return null;
}

/**
 * 노드를 문자열로 펴서 식을 검사하기 쉽게 만든다. (속성 위치가 props/최상위 어느 쪽이든)
 *
 * @param node 노드
 * @returns JSON 문자열
 */
function flat(node: any): string {
    return JSON.stringify(node ?? null);
}

/**
 * 노드 자신의 속성(자식 제외)만 문자열로 편다.
 *
 * @param node 노드
 * @returns JSON 문자열
 */
function own(node: any): string {
    if (!node) return 'null';
    const { children: _children, ...rest } = node;

    return JSON.stringify(rest);
}

describe('R1 — 취소 창 「환불까지 한 번에 처리」 는 환불 실행 권한으로 잠긴다', () => {
    const modal = layout('partials/admin_ecommerce_order_detail/_modal_cancel_order.json');

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_cancel_auto_refund_checkbox_locked
     */
    it('체크박스가 can_execute_refund 로 잠기고, 권한이 없으면 꺼진 상태다', () => {
        const checkbox = byId(modal, 'cancel_auto_advance_refund_checkbox');
        expect(checkbox).not.toBeNull();
        expect(own(checkbox)).toContain('can_execute_refund !== true');
        expect(own(checkbox)).toContain('can_execute_refund === true');
    });

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_cancel_auto_refund_checkbox_locked
     */
    it('권한 없음 안내가 같은 키로 보인다', () => {
        const notice = byId(modal, 'cancel_auto_advance_refund_no_permission');
        expect(notice).not.toBeNull();
        expect(flat(notice)).toContain('auto_advance_refund_no_permission');
        expect(own(notice)).toContain('can_execute_refund !== true');
    });

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_cancel_auto_refund_checkbox_locked
     */
    it('실행 요청에도 권한이 없으면 자동 환불을 싣지 않는다', () => {
        expect(flat(modal)).toContain('autoAdvanceRefund":"{{_local.autoAdvanceRefund === true && order.data?.abilities?.can_execute_refund === true}}');
    });
});

describe('S3 — 직권 접수 창 「접수와 함께 승인」 은 상태 변경 권한으로 잠긴다', () => {
    const modal = layout('partials/admin_ecommerce_order_detail/_modal_admin_claim_request.json');

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_claim_approve_checkbox_locked
     */
    it('체크박스가 claimRequestContext 의 can_update_status 로 잠긴다', () => {
        const label = byId(modal, 'acr_009_approve');
        expect(flat(label)).toContain('can_update_status !== true');
    });

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_claim_approve_checkbox_locked
     */
    it('권한 없음 안내와 제출 파라미터가 같은 키를 쓴다', () => {
        expect(flat(byId(modal, 'acr_009_approve_no_permission'))).toContain('approve_no_permission');
        expect(flat(byId(modal, 'acr_011_submit'))).toContain('canApprove');
    });
});

describe('G05 — 주문 상세 입금확인·현금영수증·비번 재설정은 주문 수정 권한으로 잠긴다', () => {
    const payment = layout('partials/admin_ecommerce_order_detail/_partial_payment_info.json');
    const orderInfo = layout('partials/admin_ecommerce_order_detail/_partial_order_info.json');
    const CU = 'order?.data?.abilities?.can_update !== true';

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_order_detail_write_buttons_locked
     */
    it.each([
        ['입금확인', payment, 'payment_confirm_deposit_button_{{payIdx}}'],
        ['현금영수증 발급', payment, 'payment_cash_receipt_issue_button_{{payIdx}}'],
        ['현금영수증 재발급', payment, 'payment_cash_receipt_reissue_button_{{payIdx}}'],
        ['현금영수증 발급취소', payment, 'payment_cash_receipt_cancel_button_{{payIdx}}'],
        ['비회원 비번 재설정', orderInfo, 'order_reset_guest_password_button'],
    ])('%s 버튼', (_label, tree, id) => {
        const button = byId(tree, id as string);
        expect(button).not.toBeNull();
        expect(own(button)).toContain(CU);
    });

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_order_detail_write_buttons_locked
     */
    it.each([
        ['_modal_confirm_deposit.json'],
        ['_modal_issue_cash_receipt.json'],
        ['_modal_reset_guest_password.json'],
    ])('%s 의 확인 버튼도 같은 키를 본다', (file) => {
        expect(flat(layout(`partials/admin_ecommerce_order_detail/${file}`))).toContain(CU);
    });
});

describe('G06 — 이커머스 설정 SEO 탭은 읽기 전용이면 입력과 [캐시 비우기]가 잠긴다', () => {
    const tab = layout('partials/admin_ecommerce_settings/_tab_seo.json');

    /**
     * 이름(name) 이 있는 입력 노드를 모은다.
     *
     * @param node 탐색 노드
     * @param out 결과 목록
     * @returns 입력 노드 목록
     */
    const inputs = (node: any, out: any[] = []): any[] => {
        if (!node || typeof node !== 'object') return out;
        if (!Array.isArray(node) && ['Input', 'Textarea', 'Checkbox'].includes(node.name) && typeof node.props?.name === 'string') out.push(node);
        for (const value of Array.isArray(node) ? node : Object.values(node)) inputs(value, out);

        return out;
    };

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_settings_seo_tab_locked
     */
    it('seo.* 입력 12개가 모두 _computed.isReadOnly 로 잠긴다', () => {
        const fields = inputs(tab).filter((n) => String(n.props.name).startsWith('seo.'));
        expect(fields.length).toBe(12);
        for (const field of fields) {
            expect(own(field)).toContain('_computed.isReadOnly');
        }
    });

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_settings_seo_tab_locked
     */
    it('[캐시 비우기] 오프너와 확인 버튼이 잠긴다', () => {
        expect(own(byId(tab, 'seo_clear_cache_button'))).toContain('_computed.isReadOnly');
        expect(own(byId(layout('partials/admin_ecommerce_settings/_modal_clear_seo_cache.json'), 'seo_clear_cache_confirm'))).toContain('isReadOnly');
    });
});

describe('G10 — 드래그 손잡이는 읽기 전용이면 숨는다', () => {
    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_drag_handles_hidden
     */
    it.each([
        ['partials/admin_ecommerce_settings/_payment_methods_list.json', 'payment_method_drag_handle_list', '_computed.isReadOnly'],
        ['partials/admin_ecommerce_settings/_payment_methods_cards.json', 'payment_method_drag_handle_card', '_computed.isReadOnly'],
        ['partials/admin_ecommerce_settings/_shipping_type_section.json', 'shipping_type_drag_handle', '_computed.isReadOnly'],
        ['partials/admin_ecommerce_settings/_shipping_carrier_section.json', 'shipping_carrier_drag_handle', '_computed.isReadOnly'],
        ['partials/admin_ecommerce_product_form/_partial_product_options.json', 'additional_option_drag_handle', '!_computed.isReadOnly'],
    ])('%s', (file, id, expr) => {
        const handle = byId(layout(file), id);
        expect(handle).not.toBeNull();
        expect(own(handle)).toContain(expr);
    });
});

describe('설정 #3·#5 — 알림 채널 토글과 결제수단 카드 토글', () => {
    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_settings_toggles_locked
     */
    it('알림 채널 토글은 저장 경로(이커머스 설정 수정)의 읽기 전용도 본다', () => {
        expect(flat(layout('partials/admin_ecommerce_settings/_tab_notification_definitions.json'))).toContain('_computed.isNotificationReadOnly || _computed.isReadOnly');
    });

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_settings_toggles_locked
     */
    it('결제수단 카드 토글도 고객이 고를 수 없는 수단을 잠근다 (목록과 같은 조건)', () => {
        expect(flat(layout('partials/admin_ecommerce_settings/_payment_methods_cards.json'))).toContain('$method.customer_selectable === false');
    });
});

describe('G12 — 리뷰 관리는 리뷰 수정 권한으로 잠긴다', () => {
    const page = layout('admin_ecommerce_product_review_index.json');

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_review_controls_locked
     */
    it('일괄 상태 select·[적용]·행 선택이 목록 can_update 로 잠긴다', () => {
        const text = flat(page);
        expect(text).toContain('"selectable":"{{reviews.data?.abilities?.can_update === true}}"');
        expect(text.match(/reviews\.data\?\.abilities\?\.can_update !== true/g)?.length ?? 0).toBeGreaterThanOrEqual(2);
    });

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_review_controls_locked
     */
    it.each(['review_reply_edit_button', 'review_reply_delete_button', 'review_reply_update_submit', 'review_reply_create_submit'])(
        '펼친 행의 %s 가 행 can_update 로 잠긴다',
        (id) => {
            expect(own(byId(page, id))).toContain('row.abilities?.can_update !== true');
        },
    );

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_review_controls_locked
     */
    it.each(['_modal_status_change.json', '_modal_reply_delete.json'])('%s 의 확인 버튼도 잠긴다', (file) => {
        expect(flat(layout(`partials/admin_ecommerce_product_review_index/${file}`))).toContain('reviews.data?.abilities?.can_update !== true');
    });
});

describe('G13 — 상품 문의 행 동작과 답변 등록은 각 권한으로 잠긴다', () => {
    const text = flat(layout('admin_ecommerce_inquiry_index.json'));

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_inquiry_actions_locked
     */
    it('행 동작 「답변」「삭제」가 행 abilities 로 잠긴다', () => {
        expect(text).toContain('"disabledField":"abilities.can_reply"');
        expect(text).toContain('"disabledField":"abilities.can_delete"');
    });

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_inquiry_actions_locked
     */
    it('답변 창 [등록]이 can_reply 로 잠긴다', () => {
        expect(text).toContain('inquiries?.data?.abilities?.can_reply !== true');
    });
});

describe('G14 — 장바구니·찜 행 「삭제」는 관리 권한으로 잠긴다', () => {
    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_cart_wishlist_delete_locked
     */
    it.each([
        ['admin_ecommerce_cart_index.json', 'carts?.data?.abilities?.can_manage !== true'],
        ['admin_ecommerce_wishlist_index.json', 'wishlists?.data?.abilities?.can_manage !== true'],
    ])('%s', (file, expr) => {
        expect(flat(layout(file))).toContain(`"id":"delete"`);
        const text = flat(layout(file));
        const item = text.slice(text.indexOf('"id":"delete"') - 200, text.indexOf('"id":"delete"') + 300);
        expect(item).toContain(expr);
    });
});

describe('G15 — 쿠폰 발급내역 「발급취소」는 그 쿠폰의 수정 권한으로 잠긴다', () => {
    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_coupon_issue_cancel_locked
     */
    it('발급내역 창을 여는 두 경로가 쿠폰 행 can_update 를 담는다', () => {
        const grid = flat(layout('partials/admin_ecommerce_promotion_coupon_list/_partial_coupon_datagrid.json'));
        expect(grid.match(/abilities\?\.can_update === true}}/g)?.length ?? 0).toBeGreaterThanOrEqual(2);
    });

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_coupon_issue_cancel_locked
     */
    it('발급취소 버튼과 확인 버튼이 그 값으로 잠긴다', () => {
        const history = layout('partials/admin_ecommerce_promotion_coupon_list/_modal_issue_history.json');
        expect(own(byId(history, 'issue_cancel_button'))).toContain('_global.issueHistoryCoupon?.can_update !== true');
        expect(flat(layout('partials/admin_ecommerce_promotion_coupon_list/_modal_cancel_issue_confirm.json'))).toContain('_global.issueHistoryCoupon?.can_update !== true');
    });
});

describe('G16 — 추가배송비 템플릿 창은 템플릿 동작별 권한으로 잠긴다', () => {
    const text = flat(layout('partials/admin_ecommerce_shipping_policy_form/_modal_extra_fee_template.json'));

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_extra_fee_template_actions_locked
     */
    it.each(['can_create', 'can_update', 'can_delete'])('%s 를 읽는다', (key) => {
        expect(text).toContain(`extra_fee_templates?.data?.abilities?.${key} !== true`);
    });
});

describe('배송정책 폼 promo#4·#5·#7 — 등록 모드 수정 권한 축과 행 삭제 과잉 차단', () => {
    const form = layout('admin_ecommerce_shipping_policy_form.json');

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_shipping_policy_create_mode_update_axis
     */
    it('등록 모드에서 수정 권한 출처(policy_abilities)와 lacksUpdate 계산이 있다', () => {
        const text = flat(form);
        expect(text).toContain('"id":"policy_abilities"');
        expect(text).toContain('policy_abilities?.data?.abilities?.can_update !== true');
    });

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_shipping_policy_create_mode_update_axis
     */
    it('등록 모드에서 수정 권한이 없으면 회수지 입력 묶음이 숨고, 연결 시험이 lacksUpdate 로 잠긴다', () => {
        const claim = layout('partials/admin_ecommerce_shipping_policy_form/_partial_claim_addresses.json');
        expect(own(byId(claim, 'claim_address_blocks'))).toContain('!!route.id || !_computed.lacksUpdate');
        expect(own(byId(claim, 'claim_addresses_no_update_notice'))).toContain('_computed.lacksUpdate');
        expect(own(byId(layout('partials/admin_ecommerce_shipping_policy_form/_partial_charge_settings.json'), 'api_test_call_button'))).toContain('_computed.lacksUpdate');
    });

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_shipping_policy_create_mode_update_axis
     */
    it.each([
        '_partial_charge_settings.json',
        '_partial_extra_fee.json',
        '_partial_country_basic_fields.json',
    ])('%s 의 행 삭제가 정책 응답이 아니라 읽기 전용으로 판정한다', (file) => {
        const text = flat(layout(`partials/admin_ecommerce_shipping_policy_form/${file}`));
        expect(text).not.toContain('policy?.data?.abilities?.can_update === true');
    });
});

describe('promo#8·#11 — 복사는 등록 권한, 쿠폰명 링크는 수정 권한', () => {
    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_copy_and_coupon_link_axis
     */
    it('배송정책 행 「복사」가 can_create 로 잠긴다', () => {
        const text = flat(layout('partials/admin_ecommerce_shipping_policy_list/_partial_datagrid.json'));
        const at = text.indexOf('"id":"copy"');
        expect(text.slice(at - 200, at + 250)).toContain('abilities.can_create');
    });

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_copy_and_coupon_link_axis
     */
    it('쿠폰명은 수정 권한이 있을 때만 링크다', () => {
        const grid = layout('partials/admin_ecommerce_promotion_coupon_list/_partial_coupon_datagrid.json');
        expect(own(byId(grid, 'coupon_name_link'))).toContain('row.abilities?.can_update === true');
        expect(own(byId(grid, 'coupon_name_text'))).toContain('row.abilities?.can_update !== true');
    });
});

describe('상품 영역 — 등록(can_create)·라벨·복사·DynamicFieldList·errorCondition·프리셋', () => {
    const form = layout('admin_ecommerce_product_form.json');

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_product_area_controls_locked
     */
    it('products#3 — 등록 모드 저장이 목록 can_create 로 잠긴다 (폼 읽기 전용 계산값 경유)', () => {
        expect(own(byId(form, 'footer_save_button'))).toContain('_computed.isReadOnly');
        expect(String(form.computed?.isReadOnly ?? '')).toContain('product_abilities?.data?.abilities?.can_create !== true');
    });

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_product_area_controls_locked
     */
    it('products#5 — 폼 복사·목록 복사·공통정보 복사가 등록 권한을 본다', () => {
        expect(own(byId(form, 'footer_copy_button'))).toContain('can_create === true');
        const grid = flat(layout('partials/admin_ecommerce_product_list/_partial_product_datagrid.json'));
        const at = grid.indexOf('"id":"copy"');
        expect(grid.slice(at - 200, at + 250)).toContain('products?.data?.abilities?.can_create !== true');
        expect(own(byId(layout('partials/admin_ecommerce_product_common_info_index/_panel_view.json'), 'common_info_copy_button'))).toContain('can_create !== true');
    });

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_product_area_controls_locked
     */
    it('products#4 — 라벨 추가·수정·삭제·저장이 라벨 권한을 본다', () => {
        const other = layout('partials/admin_ecommerce_product_form/_partial_other_info.json');
        expect(own(byId(other, 'label_add_button'))).toContain('product_labels?.data?.abilities?.can_create === true');
        expect(own(byId(other, 'label_edit_button'))).toContain('product_labels?.data?.abilities?.can_update === true');
        expect(own(byId(other, 'label_delete_button'))).toContain('product_labels?.data?.abilities?.can_delete === true');
        expect(own(byId(layout('partials/admin_ecommerce_product_form/_modal_label_form.json'), 'label_form_save'))).toContain('product_labels?.data?.abilities');
    });

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_product_area_controls_locked
     */
    it.each([
        ['partials/admin_ecommerce_product_notice_index/_panel_form.json', 'fields_editor'],
        ['partials/admin_ecommerce_product_form/_partial_product_notice.json', 'notice_items_editor'],
    ])('products#7 — %s 의 DynamicFieldList 에 readOnly 를 넘긴다', (file, id) => {
        expect(own(byId(layout(file), id))).toContain('"readOnly"');
    });

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_product_area_controls_locked
     */
    it.each(['admin_ecommerce_product_form.json', 'admin_ecommerce_promotion_coupon_form.json', 'admin_ecommerce_shipping_policy_form.json'])(
        'G17 — %s 의 errorCondition 은 명시적 false 만 403',
        (file) => {
            const text = flat(layout(file));
            expect(text).toContain('response?.data?.abilities?.can_update === false');
            expect(text).not.toContain('response?.data?.abilities?.can_update !== true');
        },
    );

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_product_area_controls_locked
     */
    it('G18 — 상품 목록 프리셋 저장·이름변경·삭제가 잠긴다', () => {
        const manage = layout('partials/admin_ecommerce_product_list/_modal_preset_manage.json');
        for (const id of ['preset_rename_button_{{presetIdx}}', 'preset_delete_button_{{presetIdx}}', 'preset_save_new_button']) {
            expect(own(byId(manage, id))).toContain('products?.data?.abilities?.can_update !== true');
        }
        const filter = layout('partials/admin_ecommerce_product_list/_partial_filter_section.json');
        for (const id of ['preset_save_button_desktop', 'preset_save_button_mobile']) {
            expect(own(byId(filter, id))).toContain('products?.data?.abilities?.can_update !== true');
        }
    });
});

describe('G29·G32 — 라우트 정의와 골격 화면 권한은 실재 식별자다', () => {
    /**
     * module.php 에 선언된 권한 식별자를 모은다. (카테고리 identifier + action)
     *
     * @returns 식별자 집합
     */
    const defined = (): Set<string> => {
        const lines = fs.readFileSync(path.join(moduleRoot(), 'module.php'), 'utf-8').split('\n');
        const ids = new Set<string>();
        let category: string | null = null;
        for (const line of lines) {
            const cat = line.match(/^ {20}'identifier' => '([^']+)',/);
            if (cat) category = cat[1];
            const action = line.match(/^ {28}'action' => '([^']+)',/);
            if (action && category) ids.add(`sirsoft-ecommerce.${category}.${action[1]}`);
        }

        return ids;
    };

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_route_permissions_are_defined
     */
    it('resources/routes/admin.json 의 permission 이 전부 module.php 에 있다', () => {
        const ids = defined();
        const routes = JSON.parse(fs.readFileSync(path.join(moduleRoot(), 'resources/routes/admin.json'), 'utf-8')).routes as any[];
        const missing = routes.filter((r) => r.permission && !ids.has(r.permission)).map((r) => `${r.path} → ${r.permission}`);
        expect(missing).toEqual([]);
    });

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_route_permissions_are_defined
     */
    it.each([
        ['admin_ecommerce_personal_payment.json', 'sirsoft-ecommerce.orders.read'],
        ['admin_ecommerce_personal_payment_create.json', 'sirsoft-ecommerce.orders.update'],
        ['admin_ecommerce_personal_payment_detail.json', 'sirsoft-ecommerce.orders.read'],
        ['admin_ecommerce_payment_failure_history.json', 'sirsoft-ecommerce.orders.read'],
        ['admin_ecommerce_excel_download_index.json', 'sirsoft-ecommerce.orders.read'],
        ['admin_ecommerce_main_banner_index.json', 'sirsoft-ecommerce.settings.read'],
        ['admin_ecommerce_promotion_discount_code_index.json', 'sirsoft-ecommerce.promotion-coupon.read'],
        ['admin_ecommerce_promotion_discount_code_create.json', 'sirsoft-ecommerce.promotion-coupon.create'],
        ['admin_ecommerce_deposit_index.json', 'sirsoft-ecommerce.mileage.read'],
    ])('%s 의 화면 permissions 가 %s 다', (file, permission) => {
        expect(layout(file).permissions).toEqual([permission]);
    });
});

describe('orders#12 — 미연결 시안 주문 화면의 [일괄 변경]은 잠겨 있다', () => {
    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_mockup_batch_button_locked
     */
    it('핸들러 없는 버튼이 권한 키 없이 열려 있지 않다', () => {
        expect(own(byId(layout('admin_ecommerce_order_index.json'), 'mockup_batch_change_button'))).toContain('"disabled":true');
    });
});

describe('ecom-backend#9 — 회원 상세 결제 통화·배송국가 칸은 변경 권한으로 잠긴다', () => {
    const ext = (file: string): string => fs.readFileSync(path.join(moduleRoot(), 'resources/extensions', file), 'utf-8');

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_user_preference_fields_locked
     */
    it.each([
        ['admin-user-currency-field.json', 'user?.data?.ecommerce_currency_can_manage !== true'],
        ['admin-user-shipping-country-field.json', 'user?.data?.ecommerce_shipping_country_can_manage !== true'],
    ])('%s', (file, expr) => {
        expect(ext(file)).toContain(expr);
    });
});

describe('모달의 부모 읽기 전용 값은 알 수 없으면 잠긴 쪽이다', () => {
    /**
     * `{{ ... }}` 식을 `$parent` 하나로 평가한다.
     *
     * @param raw 바인딩 식
     * @param parent `$parent` 컨텍스트
     * @param extra 그 밖의 이름
     * @returns 평가 결과
     */
    function evaluate(raw: unknown, parent: unknown, extra: Record<string, unknown> = {}): any {
        const expr = String(raw).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
        const scope: Record<string, unknown> = { $parent: parent, _global: {}, ...extra };
        // eslint-disable-next-line no-new-func
        return new Function(...Object.keys(scope), `return (${expr});`)(...Object.values(scope));
    }

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_settings_seo_tab_locked
     */
    it('SEO 캐시 비우기 확인 버튼 — 부모 계산값이 없으면 잠긴다', () => {
        const confirm = byId(layout('partials/admin_ecommerce_settings/_modal_clear_seo_cache.json'), 'seo_clear_cache_confirm');
        const disabled = confirm.props?.disabled ?? confirm.disabled;

        expect(evaluate(disabled, { _computed: { isReadOnly: true }, _local: {} })).toBe(true);
        expect(evaluate(disabled, { _computed: { isReadOnly: false }, _local: {} })).toBe(false);
        expect(evaluate(disabled, { _computed: {}, _local: {} })).toBe(true);
        expect(evaluate(disabled, { _local: {} })).toBe(true);
    });

    /**
     * @scenario surface=admin, permission=missing
     * @effects admin_extra_fee_template_actions_locked
     */
    it('추가배송비 템플릿 창 — 부모 계산값을 읽는 칸은 모두 그 값이 없으면 잠긴다', () => {
        const text = flat(layout('partials/admin_ecommerce_shipping_policy_form/_modal_extra_fee_template.json'));

        expect(text).not.toMatch(/\$parent\._computed\.isReadOnly(?!\s*!==\s*false)/);
        expect(text).toContain('$parent._computed?.isReadOnly !== false');
    });
});
