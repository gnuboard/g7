/**
 * #599 validate 결함 수정 (P7) — 이커머스 관리자(클레임 외) 레이아웃 계약
 *
 * 주문 목록·상세, 배송정책 폼, 회원 배송지, 쿠폰, 설정 화면에서 브랜치 검증이 찾은 결함을
 * **구워진 짝 `.json`** 으로 잠근다. 주석 정합(#16)처럼 JSON 에 남지 않는 것은 소스를 읽는다.
 *
 * @vitest-environment node
 */
import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const LAYOUT_ROOT = path.resolve(__dirname, '../../../layouts/admin');
const LANG_ROOT = path.resolve(__dirname, '../../../lang/partial');
const JA_ROOT = path.resolve(__dirname, '../../../../../../../lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/frontend/partial');

/**
 * 레이아웃 JSON 을 읽는다.
 *
 * @param rel admin/ 기준 상대 경로
 * @returns 파싱 결과
 */
function load(rel: string): any {
    return JSON.parse(fs.readFileSync(path.join(LAYOUT_ROOT, rel), 'utf8'));
}

/**
 * 소스 `.g7.html` 을 읽는다.
 *
 * @param rel admin/ 기준 상대 경로
 * @returns 원문
 */
function source(rel: string): string {
    return fs.readFileSync(path.join(LAYOUT_ROOT, rel), 'utf8');
}

/**
 * 개발자 주석을 걷어낸 직렬화.
 *
 * @param node 노드
 * @returns 문자열
 */
function renderJson(node: unknown): string {
    return JSON.stringify(node, (key, value) => (key === 'comment' || key === '_comment' ? undefined : value));
}

/**
 * 조건을 만족하는 객체를 깊이 순회로 모은다.
 *
 * @param node 시작 노드
 * @param match 조건
 * @returns 목록
 */
function collect(node: unknown, match: (o: Record<string, any>) => boolean): Record<string, any>[] {
    const found: Record<string, any>[] = [];
    const walk = (n: unknown): void => {
        if (Array.isArray(n)) {
            n.forEach(walk);

            return;
        }
        if (n === null || typeof n !== 'object') return;
        const obj = n as Record<string, any>;
        if (match(obj)) found.push(obj);
        Object.values(obj).forEach(walk);
    };
    walk(node);

    return found;
}

const shippingForm = load('admin_ecommerce_shipping_policy_form.json');
const addressDatagrid = load('partials/admin_ecommerce_user_address_index/_partial_address_datagrid.json');
const addressFilter = load('partials/admin_ecommerce_user_address_index/_partial_filter_section.json');
const orderDetail = load('admin_ecommerce_order_detail.json');
const cancelModal = load('partials/admin_ecommerce_order_detail/_modal_cancel_order.json');
const orderList = load('admin_ecommerce_order_list.json');
const orderFilter = load('partials/admin_ecommerce_order_list/_partial_filter_section.json');
const orderPresetManage = load('partials/admin_ecommerce_order_list/_modal_preset_manage.json');
const manualTransaction = load('partials/admin_ecommerce_mileage_transaction_index/_modal_manual_transaction.json');
const directIssue = load('partials/admin_ecommerce_promotion_coupon_list/_modal_direct_issue.json');
const claimComplete = load('partials/admin_ecommerce_claim_list/_modal_claim_complete.json');
const notificationTab = load('partials/admin_ecommerce_settings/_tab_notification_definitions.json');
const couponDatagrid = load('partials/admin_ecommerce_promotion_coupon_list/_partial_coupon_datagrid.json');
const chargeSettings = load('partials/admin_ecommerce_shipping_policy_form/_partial_charge_settings.json');
const currencyCards = load('partials/admin_ecommerce_settings/_currency_exchange_cards.json');
const paymentFailure = load('admin_ecommerce_payment_failure_history.json');

describe('10-#3 배송정책 폼 재진입 — 앞 정책의 회수지·저장 id 를 비운다', () => {
    /**
     * 같은 레이아웃 SPA 이동에서 `savedPolicyId` 가 남으면 신규 POST 가 이전 정책 PUT 으로 나간다.
     *
     * @scenario surface=shipping_policy_form, defect=reentry_keeps_claim_state
     * @effects shipping_policy_form_reentry_resets_claim_state
     */
    it('init 이 claimAddresses·claimAddressesDirty·savedPolicyId 를 초기화한다', () => {
        const resets = collect(shippingForm.init_actions, (o) => o.handler === 'setState' && o.params
            && Object.prototype.hasOwnProperty.call(o.params, 'savedPolicyId'));

        expect(resets).toHaveLength(1);
        const p = resets[0].params;
        expect(p.savedPolicyId).toBeNull();
        expect(p.claimAddressesDirty).toBe(false);
        expect(p.claimAddresses?.addresses).toEqual({ exchange: null, return: null });
        // 로딩 표식은 먼저 false 로 되돌린다 — 신규 등록만 뒤따르는 init 이 true 로 세운다(기존 계약).
        expect(p.claimAddresses?._loaded).toBe(false);
        const order = (shippingForm.init_actions as any[]).map((a) => a.params ?? {});
        const resetAt = order.findIndex((q) => Object.prototype.hasOwnProperty.call(q, 'savedPolicyId'));
        const createFlagAt = order.findIndex((q) => q['claimAddresses._loaded'] === true);
        expect(createFlagAt).toBeGreaterThan(resetAt);
    });
});

describe('10-#4 회원 배송지 PC 행 메뉴 — 회원 없는 행·관리 권한', () => {
    /**
     * member 가 null 인 행의 「이 회원으로 검색」 은 `user_uuids[]=[undefined]` → 422 → 본문 전체 오류였다.
     *
     * @scenario surface=user_address_index, defect=row_actions_without_member
     * @effects address_row_actions_disabled_without_member
     */
    it('회원 항목은 member.uuid 가 없으면 비활성, 삭제는 관리 권한이 없으면 비활성', () => {
        const grid = collect(addressDatagrid, (o) => o.name === 'DataGrid')[0];
        const items = grid.props.rowActions as any[];
        const byId = (id: string) => items.find((i) => i.id === id);

        expect(byId('view_member').disabledField).toBe('member.uuid');
        expect(byId('search_member').disabledField).toBe('member.uuid');
        expect(String(byId('delete').disabled)).toContain('_computed.canManage !== true');
    });
});

describe('10-#6 · #7 · #8 주문 상세 클레임 축', () => {
    /**
     * @scenario surface=order_detail_cancel, defect=navigate_without_claim_permission
     * @effects order_cancel_navigates_only_with_claim_access
     */
    it('취소 실행이 클레임 열람 가능 여부를 넘긴다', () => {
        const calls = collect(cancelModal, (o) => o.handler === 'sirsoft-ecommerce.executeCancelOrder');

        expect(calls).toHaveLength(1);
        expect(calls[0].params.canViewClaims).toBe('{{orderClaims?.data?.unavailable !== true}}');
    });

    /**
     * 서브탭은 서버 `counts` 를, 탭 라벨은 배열 길이를 세면 상한이 걸린 응답에서 두 숫자가 갈린다.
     *
     * @scenario surface=order_detail, defect=tab_count_from_array_length
     * @effects order_claim_tab_count_from_counts
     */
    it('클레임 탭 건수는 counts 합이다', () => {
        expect(orderDetail.computed.orderDetailTabs).toContain('_computed.claimHistoryTotal');
        expect(orderDetail.computed.orderDetailTabs).not.toContain('claims ?? []).length');
        expect(orderDetail.computed.claimHistoryTotal).toContain('orderClaims?.data?.counts?.exchange');
        expect(orderDetail.computed.claimHistoryTotal).toContain('orderClaims?.data?.counts?.refund');
    });

    /**
     * @scenario surface=order_detail, defect=order_claims_500_propagates
     * @effects order_claims_failure_stays_in_section
     */
    it('orderClaims 는 403 외 실패도 억제한다(섹션이 unavailable 을 말한다)', () => {
        const source = orderDetail.data_sources.find((s: any) => s.id === 'orderClaims');

        expect(source.errorHandling['403'].handler).toBe('suppress');
        expect(source.errorHandling.default?.handler).toBe('suppress');
    });
});

describe('10-#10 · #11 회원 자동완성 늦은 응답 가드', () => {
    /**
     * 서버는 검색어를 trim 해 돌려준다 — 앞뒤 공백이 있는 입력과 `===` 로 견주면 결과가 영영 숨는다.
     *
     * @scenario surface=member_autocomplete, defect=keyword_compare_without_trim
     * @effects member_search_guard_compares_trimmed_keyword
     */
    it('두 창의 가드가 입력값을 trim 해 견준다', () => {
        expect(renderJson(manualTransaction)).toContain("(_global.mileageManual?.memberSearchKeyword ?? '').trim()");
        expect(renderJson(directIssue)).toContain("(_global.directIssueSearchKeyword ?? '').trim()");
    });

    /**
     * @scenario surface=user_address_index, defect=member_filter_without_stale_guard
     * @effects address_member_filter_renders_only_current_keyword
     */
    it('회원 배송지 필터가 응답 검색어로 도장하고 지금 검색어일 때만 후보를 보인다', () => {
        const dropdown = collect(addressFilter, (o) => o.id === 'member_filter_dropdown')[0];
        const text = renderJson(addressFilter);

        expect(text).toContain("memberSearchResultsKeyword\":\"{{response?.meta?.keyword ?? ''}}");
        expect(String(dropdown.props.options)).toContain("(_local.memberSearchKeyword ?? '').trim()");
    });
});

describe('10-#12 클레임 신청일 from > to — 목록 전체가 422 가 되지 않는다', () => {
    /**
     * URL 에 남은 뒤집힌 구간은 새로고침해도 그대로라, 데이터소스가 직접 바로잡아야 빠져나온다.
     *
     * @scenario surface=order_list, defect=claim_date_range_inverted_422
     * @effects order_claim_date_range_is_normalized
     */
    it('데이터소스와 검색 이동이 뒤집힌 구간을 바로잡는다', () => {
        const params = orderList.data_sources.find((s: any) => s.id === 'orders').params;
        expect(params.claim_requested_from).toContain('query.claim_requested_from > query.claim_requested_to');
        expect(params.claim_requested_to).toContain('query.claim_requested_from > query.claim_requested_to');

        const text = renderJson(orderList.named_actions);
        expect(text).toContain('_local.filter.claimRequestedFrom > _local.filter.claimRequestedTo');
    });

    /**
     * @scenario surface=order_list, defect=stale_filter_comment
     * @effects order_filter_comments_match_behavior
     */
    it('필터 주석이 "화면은 막지 않고 서버가 거부한다" 를 더 말하지 않는다', () => {
        expect(source('partials/admin_ecommerce_order_list/_partial_filter_section.g7.html'))
            .not.toContain('화면은 막지 않고 서버 검증이 요청을 거부한다');
    });
});

describe('10-#14 · #15 · #16 · #19 표기·주석 정합', () => {
    /**
     * @scenario surface=order_detail_cancel, defect=undefined_class_text_caption
     * @effects cancel_modal_uses_defined_text_class
     */
    it('취소 모달에 text-caption 이 없다', () => {
        expect(renderJson(cancelModal)).not.toContain('text-caption');
    });

    /**
     * `<prop name="comment">` 는 props.comment 로 구워져 컴포넌트에 모르는 prop 으로 넘어간다.
     *
     * @scenario surface=admin_modals, defect=comment_prop_leaks
     * @effects comment_is_node_comment_not_prop
     */
    it('comment 가 컴포넌트 props 로 새지 않는다', () => {
        for (const layout of [claimComplete, notificationTab]) {
            expect(collect(layout, (o) => o.props && typeof o.props === 'object' && 'comment' in o.props)).toHaveLength(0);
        }
    });

    /**
     * 필터 노출 저장은 FilterVisibilitySelector 가 `g7_filters_{id}_{사용자}` 키로 직접 한다.
     *
     * @scenario surface=admin_list_filters, defect=stale_filter_comment
     * @effects order_filter_comments_match_behavior
     */
    it('필터 머리 주석이 사라진 저장 키·동반 저장을 말하지 않는다', () => {
        for (const rel of [
            'partials/admin_ecommerce_order_list/_partial_filter_section.g7.html',
            'partials/admin_ecommerce_product_list/_partial_filter_section.g7.html',
            'partials/admin_ecommerce_promotion_coupon_list/_partial_filter_section.g7.html',
        ]) {
            const text = source(rel);
            expect(text, rel).not.toMatch(/g7_(order|product|coupon)_filter_visible_filters/);
            expect(text, rel).not.toContain('상태 변경과 저장이 나란히');
        }
    });

    /**
     * 쿠폰 목록의 좁은 화면 카드 블록은 아무도 채우지 않는 `_global.isPortable` 에 묶여 한 번도 보이지
     * 않았다. 좁은 화면은 DataGrid 의 카드 보기가 맡으므로 블록을 걷어냈고(2026-10-04 결정 3), 그 블록만
     * 쓰던 `mobile.*` 라벨 키도 ko·en·ja 에서 함께 지웠다(예전 콜론 키 결함도 이 블록 안에 있었다).
     *
     * @scenario surface=coupon_list, defect=unreachable_mobile_cards
     * @effects coupon_list_has_no_unreachable_mobile_cards
     */
    it('쿠폰 목록에 보이지 않던 카드 블록과 그 블록만 쓰던 라벨 키가 남지 않는다', () => {
        const text = renderJson(couponDatagrid);
        expect(text).not.toContain('_global.isPortable');
        expect(text).not.toContain('mobile_card_view');
        expect(text).not.toMatch(/promotion_coupon\.mobile\./);

        for (const file of [
            path.join(LANG_ROOT, 'ko/admin/promotion_coupon.json'),
            path.join(LANG_ROOT, 'en/admin/promotion_coupon.json'),
            path.join(JA_ROOT, 'admin/promotion_coupon.json'),
        ]) {
            expect(JSON.parse(fs.readFileSync(file, 'utf8')).mobile, file).toBeUndefined();
        }
    });

    /**
     * 회원 배송지 목록도 같은 카드 블록을 `_global.isPortable` 에 묶어 두었다 — 같은 결정으로 걷어냈다.
     *
     * @scenario surface=user_address_index, defect=unreachable_mobile_cards
     * @effects address_list_has_no_unreachable_mobile_cards
     */
    it('회원 배송지 목록에도 보이지 않던 카드 블록이 남지 않는다', () => {
        const text = renderJson(addressDatagrid);
        expect(text).not.toContain('_global.isPortable');
        expect(text).not.toContain('mobile_card_view');
    });
});

describe('선행 audit 신호 — 손댄 김에 정리', () => {
    /**
     * @scenario surface=shipping_policy_form, defect=undefined_class_input_sm
     * @effects admin_layouts_use_defined_classes
     */
    it('input-sm 이 없다', () => {
        expect(renderJson(chargeSettings)).not.toContain('input-sm');
    });

    /**
     * @scenario surface=settings_currency, defect=unstyled_text_input
     * @effects currency_inputs_are_styled
     */
    it('통화 카드의 소수 자릿수 입력이 input 외형을 갖는다', () => {
        const inputs = collect(currencyCards, (o) => o.name === 'Input' && /decimal_places/.test(String(o.props?.name ?? '')));

        expect(inputs.length).toBeGreaterThanOrEqual(2);
        for (const input of inputs) {
            expect(String(input.props.className).split(/\s+/)).toContain('input');
        }
    });

    /**
     * @scenario surface=admin_lists, defect=darkmode_pair_missing
     * @effects admin_layouts_have_dark_pairs
     */
    it('결제 실패 이력·주문 프리셋의 연한 ring/배경에 dark 짝이 있다', () => {
        const light = /(?:^|\s)(ring|bg)-(gray|blue|cyan|orange|yellow|red)-(\d{2,3})(?=\s|'|$)/g;
        for (const layout of [paymentFailure, orderPresetManage]) {
            for (const node of collect(layout, (o) => typeof o.props?.className === 'string')) {
                const cls = node.props.className as string;
                for (const m of cls.matchAll(light)) {
                    const [, kind, , shade] = m;
                    if (kind === 'ring' && Number(shade) >= 500) continue;
                    expect(cls, cls).toMatch(new RegExp(`dark:${kind}-`));
                }
            }
        }
        const presetRow = collect(orderFilter, (o) => typeof o.id === 'string' && o.id.startsWith('preset_buttons_dynamic'));
        expect(presetRow.length).toBeGreaterThan(0);
        expect(String(presetRow[0].props.className)).toContain('dark:ring-gray-500');
    });
});
