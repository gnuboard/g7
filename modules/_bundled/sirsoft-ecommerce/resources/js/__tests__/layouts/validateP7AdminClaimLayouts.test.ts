/**
 * #599 validate 결함 수정 (P7) — 관리자 클레임 화면 레이아웃 계약
 *
 * 브랜치 검증이 관리자 클레임 목록·상세에서 찾은 결함을 **구워진 짝 `.json`**(런타임이 먹는 값)
 * 으로 잠근다. 소스 `.g7.html` 만 고치고 굽기를 빼먹으면 여기서 red 가 된다.
 *
 * 부재 단언 앞에는 앵커의 **존재**를 먼저 확정한다 — 경로가 바뀌면 "없음" 이 공허하게 참이다.
 *
 * @vitest-environment node
 */
import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const LAYOUT_ROOT = path.resolve(__dirname, '../../../layouts/admin');

/**
 * 레이아웃 JSON 을 읽는다. 없으면 null — 신규 파일의 부재를 red 로 드러내기 위해 import 대신 읽는다.
 *
 * @param rel admin/ 기준 상대 경로
 * @returns 파싱 결과 또는 null
 */
function load(rel: string): any {
    const file = path.join(LAYOUT_ROOT, rel);

    return fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : null;
}

/**
 * 개발자 주석을 걷어낸 뒤 직렬화한다 — 주석이 계약 이름을 담아 부재 단언을 깨지 않게 한다.
 *
 * @param node 레이아웃 노드
 * @returns 직렬화 문자열
 */
function renderJson(node: unknown): string {
    return JSON.stringify(node, (key, value) => (key === 'comment' || key === '_comment' ? undefined : value));
}

/**
 * 트리를 깊이 순회하며 조건을 만족하는 객체를 모은다.
 *
 * @param node 시작 노드
 * @param match 조건
 * @returns 일치 객체 목록
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

/**
 * id 로 노드 하나를 찾는다(없으면 테스트 실패).
 *
 * @param layout 레이아웃
 * @param id 노드 id(data-ref)
 * @returns 노드
 */
function byId(layout: unknown, id: string): Record<string, any> {
    const nodes = collect(layout, (o) => o.id === id);
    expect(nodes, `node ${id}`).toHaveLength(1);

    return nodes[0];
}

const claimList = load('admin_ecommerce_claim_list.json');
const claimDetail = load('admin_ecommerce_claim_detail.json');
const claimShipping = load('partials/admin_ecommerce_claim_detail/_partial_claim_shipping.json');
const claimItems = load('partials/admin_ecommerce_claim_detail/_partial_claim_items.json');
const claimPayment = load('partials/admin_ecommerce_claim_detail/_partial_claim_payment.json');
const claimReason = load('partials/admin_ecommerce_claim_detail/_partial_claim_reason.json');
const claimActivityLog = load('partials/admin_ecommerce_claim_detail/_partial_claim_activity_log.json');
const refundExecuteModal = load('partials/admin_ecommerce_claim_detail/_modal_claim_refund_execute.json');
const imagesClearModal = load('partials/admin_ecommerce_claim_detail/_modal_claim_images_clear.json');
const claimFilter = load('partials/admin_ecommerce_claim_list/_partial_filter_section.json');
const claimDatagrid = load('partials/admin_ecommerce_claim_list/_partial_claim_datagrid.json');
const orderList = load('admin_ecommerce_order_list.json');
const orderDetail = load('admin_ecommerce_order_detail.json');
const orderFilter = load('partials/admin_ecommerce_order_list/_partial_filter_section.json');
const orderDatagrid = load('partials/admin_ecommerce_order_list/_partial_order_datagrid.json');
const couponDatagrid = load('partials/admin_ecommerce_promotion_coupon_list/_partial_coupon_datagrid.json');
const couponIssueHistory = load('partials/admin_ecommerce_promotion_coupon_list/_modal_issue_history.json');

const CLAIM_MODAL_FILES = [
    'partials/admin_ecommerce_claim_list/_modal_bulk_confirm.json',
    'partials/admin_ecommerce_claim_list/_modal_claim_complete.json',
    'partials/admin_ecommerce_claim_list/_modal_preset_save.json',
    'partials/admin_ecommerce_claim_list/_modal_preset_edit.json',
    'partials/admin_ecommerce_claim_list/_modal_preset_delete_confirm.json',
    'partials/admin_ecommerce_claim_list/_modal_preset_manage.json',
];

describe('#3 설정 게이트 목록 소스 — claims.read 만 가진 운영자에게 403 토스트를 띄우지 않는다', () => {
    /**
     * 사유 카탈로그·쇼핑몰 설정은 `settings.read` 게이트다. 화면은 `claims.read` 라서 기본 역할
     * manager 는 두 소스 모두 403 을 받고, 억제가 없으면 진입·탭 전환마다 오류 토스트가 뜬다.
     *
     * @scenario surface=claim_list, defect=settings_gate_403_toast
     * @effects claim_list_settings_gated_sources_suppress_403
     */
    it('settings.read 게이트인 ecommerce_settings 만 403 을 suppress 한다', () => {
        const settings = (claimList.data_sources ?? []).find((s: any) => s.id === 'ecommerce_settings');
        expect(settings?.errorHandling?.['403']?.handler).toBe('suppress');

        // 사유 카탈로그는 `settings.read | claims.read` 게이트로 열렸다(P7 후속) — 이 화면을 여는
        // 운영자는 늘 통과하므로 억제를 두지 않는다. 남기면 게이트가 다시 좁혀졌을 때 필터가 조용히 빈다.
        const reasons = (claimList.data_sources ?? []).find((s: any) => s.id === 'claim_reasons');
        expect(reasons).toBeTruthy();
        expect(reasons.errorHandling?.['403']).toBeUndefined();
    });
});

describe('#4 첨부 일괄 정리 — 되돌릴 수 없는 삭제는 확인 창을 거친다', () => {
    /**
     * @scenario surface=claim_detail, defect=bulk_image_clear_without_confirm
     * @effects claim_image_bulk_clear_requires_confirm_modal
     */
    it('정리 버튼은 삭제를 직접 부르지 않고 확인 창을 연다', () => {
        const button = byId(claimReason, 'cr_007_image_clear');
        const direct = collect(button, (o) => o.handler === 'sirsoft-ecommerce.clearClaimImages');
        const opens = collect(button, (o) => o.handler === 'openModal' && o.target === 'modal_claim_images_clear');

        expect(direct).toHaveLength(0);
        expect(opens).toHaveLength(1);
    });

    /**
     * @scenario surface=claim_detail, defect=bulk_image_clear_without_confirm
     * @effects claim_image_bulk_clear_requires_confirm_modal
     */
    it('확인 창이 셸에 등록되고 그 창의 확인 버튼이 삭제를 부른다', () => {
        expect(imagesClearModal).not.toBeNull();
        expect(JSON.stringify(claimDetail.modals)).toContain('partials/admin_ecommerce_claim_detail/_modal_claim_images_clear.json');

        const calls = collect(imagesClearModal, (o) => o.handler === 'sirsoft-ecommerce.clearClaimImages');
        expect(calls).toHaveLength(1);
        // 문맥은 여는 쪽이 _global 로 넘긴다 — 모달은 부모 `_local` 을 해석하지 못한다
        expect(JSON.stringify(calls[0].params)).toContain('_global.claimImagesClearContext');
    });
});

describe('#5 Select 는 placeholder 를 그리지 않는다 — 빈 선택지를 options 맨 앞에 둔다', () => {
    /**
     * 택배사를 한 번 고르면 비울 수 없어 「둘 다 비우고 저장하면 송장 삭제」가 화면에서 불가능했다.
     *
     * @scenario surface=claim_detail, defect=select_placeholder_ignored
     * @effects claim_selects_lead_with_empty_option
     */
    it.each([
        // 송장 입력은 상품 칸 선택 바 하나다(송장은 상품 행마다 — #599). 방향은 옆 셀렉트가 정한다.
        ['item carrier', () => byId(claimItems, 'ci_007_carrier')],
        ['item bulk status', () => byId(claimItems, 'ci_002_item_status')],
        ['charge method', () => byId(claimPayment, 'cst_003_charge_method')],
    ])('%s', (_label, get) => {
        const node = get();

        expect(node.props.placeholder).toBeUndefined();
        expect(String(node.props.options)).toMatch(/^\{\{\[\{ value: '', label: \$t\('sirsoft-ecommerce\./);
    });

    /**
     * @scenario surface=claim_detail, defect=select_placeholder_ignored
     * @effects claim_carrier_empty_option_is_selectable
     */
    it('택배사 빈 선택지는 고를 수 있다(disabled 아님) — 비워야 송장이 지워진다', () => {
        // 「송장만 저장」 에서 둘 다 비우면 그 행의 송장이 지워진다(R7) — 빈 선택지를 고를 수 있어야 한다.
        expect(String(byId(claimItems, 'ci_007_carrier').props.options)).not.toContain('disabled: true');
    });
});

describe('#6 · #7 정의 없는 클래스·prop 을 걷어낸다', () => {
    /**
     * @scenario surface=claim_list, defect=btn_square_undefined
     * @effects list_back_button_uses_defined_classes
     */
    it('btn-square 가 클레임·주문 목록에 없다', () => {
        for (const layout of [claimList, orderList]) {
            byId(layout, 'mb_001_back_button');
            expect(renderJson(layout)).not.toContain('btn-square');
        }
    });

    /**
     * DataGrid 에는 `loading`·`columnSelectorId` prop 이 없다(조용히 버려진다).
     *
     * @scenario surface=admin_datagrids, defect=datagrid_undefined_props
     * @effects datagrids_carry_only_defined_props
     */
    it('DataGrid 에 loading·columnSelectorId 를 넘기지 않는다', () => {
        for (const layout of [claimDatagrid, orderDatagrid, couponDatagrid, couponIssueHistory]) {
            const grids = collect(layout, (o) => o.name === 'DataGrid');
            expect(grids.length).toBeGreaterThan(0);
            for (const grid of grids) {
                expect(grid.props).not.toHaveProperty('loading');
                expect(grid.props).not.toHaveProperty('columnSelectorId');
            }
        }
    });
});

describe('#8 검색어 칸 최소 폭 — 빌드된 클래스 + 좁은 화면 오버라이드', () => {
    /**
     * `min-w-64` 는 관리자 빌드 CSS 에 없고, 좁은 화면에서 `w-80` 셀렉트와 합쳐 375px 를 넘긴다.
     *
     * @scenario surface=claim_list, defect=search_row_overflow_375
     * @effects claim_search_row_fits_portable
     */
    it.each([
        ['claim', () => claimFilter],
        ['order', () => orderFilter],
    ])('%s 목록 검색 줄', (_label, get) => {
        const layout = get();
        const keyword = byId(layout, 'f_002_search_keyword_input');
        const field = byId(layout, 'f_001_search_field_select');

        expect(keyword.props.className).toContain('min-w-[280px]');
        expect(keyword.props.className).not.toContain('min-w-64');
        expect(keyword.responsive?.portable?.props?.className).toContain('min-w-0');
        expect(field.responsive?.portable?.props?.className).toContain('w-24');
    });
});

describe('#9 상세 재진입 — 신청서 단위 전이 선택을 비운다', () => {
    /**
     * @scenario surface=claim_detail, defect=transition_choice_survives_reentry
     * @effects claim_detail_init_resets_transition_choice
     */
    it('init 과 initLocal 이 claimBulkStatus·claimRejectReason 을 초기화한다', () => {
        const resets = collect(claimDetail.init_actions, (o) => o.handler === 'setState');
        const params = resets.map((r) => r.params ?? {});

        expect(params.some((p) => p.claimBulkStatus === '' && p.claimRejectReason === '')).toBe(true);
        expect(claimDetail.initLocal.claimBulkStatus).toBe('');
        expect(claimDetail.initLocal.claimRejectReason).toBe('');
    });
});

describe('#10 프리셋 적용 — 페이지 크기 셀렉트와 실제 조회 크기가 같다', () => {
    /**
     * @scenario surface=claim_list, defect=preset_drops_per_page
     * @effects claim_preset_keeps_per_page
     */
    it('프리셋 이동이 per_page 를 싣는다', () => {
        const navs = collect(claimFilter, (o) => o.handler === 'navigate' && o.params?.query?.preset_id !== undefined);

        expect(navs).toHaveLength(1);
        expect(navs[0].params.query).toHaveProperty('per_page');
    });
});

describe('#14 환불 합계 — 부동소수 덧셈을 그대로 그리지 않는다', () => {
    /**
     * `0.1 + 0.2` 가 `0.30000000000000004` 로 찍히던 자리다.
     *
     * @scenario surface=claim_detail, defect=float_refund_total
     * @effects claim_refund_total_rounded
     */
    it('상세 입력 합계는 자릿수를 고정한 computed 를 읽는다', () => {
        const total = byId(claimPayment, 'cre_011_total');
        const text = renderJson(total);

        expect(text).toContain('_computed.refundInputTotal');
        expect(claimDetail.computed.refundInputTotal).toContain('Math.round');
    });

    /**
     * @scenario surface=claim_detail, defect=float_refund_total
     * @effects claim_refund_total_rounded
     */
    it('실행 확인 창 합계는 핸들러가 반올림해 넘긴 값을 읽는다', () => {
        const total = byId(refundExecuteModal, 'crm_005_total');

        expect(renderJson(total)).toContain('claimRefundExecuteContext?.refundTotal');
    });
});

describe('#15 환불 계좌 은행 — 자유 입력이 아니라 은행 목록에서 고른다', () => {
    /**
     * @scenario surface=claim_detail, defect=bank_code_free_text
     * @effects claim_refund_bank_code_is_select
     */
    it('은행 칸이 공개 결제 설정의 은행 목록을 선택지로 쓰는 Select 다', () => {
        const bank = byId(refundExecuteModal, 'crm_009_bank_code');

        expect(bank.name).toBe('Select');
        expect(String(bank.props.options)).toContain('paymentSettings.data?.order_settings?.banks');
        const source = (claimDetail.data_sources ?? []).find((s: any) => s.id === 'paymentSettings');
        expect(source?.endpoint).toBe('/api/modules/sirsoft-ecommerce/settings/payment');
    });
});

describe('#16 다크모드 짝 — hover 배경의 dark 짝은 dark:hover 다', () => {
    /**
     * `hover:bg-gray-50 dark:bg-gray-800` 는 다크에서만 배경이 늘 켜진다.
     *
     * @scenario surface=claim_screens, defect=dark_hover_pair
     * @effects claim_dark_hover_pairs_are_hover
     */
    it('클레임 화면에 hover 짝이 base 로 박힌 클래스가 없다', () => {
        const layouts = [claimActivityLog, ...CLAIM_MODAL_FILES.map(load)];
        for (const layout of layouts) {
            expect(layout).not.toBeNull();
            const text = renderJson(layout);
            expect(text).not.toMatch(/hover:bg-gray-50 dark:bg-gray-800/);
            expect(text).not.toMatch(/hover:bg-gray-100 dark:bg-gray-700/);
        }
    });
});

describe('#17 수동 송금 안내 — 버튼 줄 밖에 선다', () => {
    /**
     * @scenario surface=claim_detail, defect=manual_transfer_notice_in_button_row
     * @effects claim_manual_transfer_notice_above_buttons
     */
    it('안내 박스와 [환불하기] 버튼이 같은 부모에 있지 않다', () => {
        byId(claimPayment, 'cre_013b_manual_transfer_notice');
        const parents = collect(claimPayment, (o) => Array.isArray(o.children)
            && o.children.some((c: any) => c?.id === 'cre_014_execute_button'));

        expect(parents).toHaveLength(1);
        expect(parents[0].children.some((c: any) => c?.id === 'cre_013b_manual_transfer_notice')).toBe(false);
    });
});

describe('#18 탭 스크롤 내비 — 컴포넌트가 모르는 onChange 를 걸지 않는다', () => {
    /**
     * `TabNavigationScroll` 은 `onChange` 를 노출하지 않는다. `onTabChange` 를 걸면 내부 활성 탭
     * 갱신이 꺼져 클릭한 탭 표시가 따라오지 않으므로(activeTabId 는 초기값 전용), 형제
     * 선례(상품·게시판 폼)처럼 컴포넌트에 맡긴다.
     *
     * @scenario surface=claim_detail, defect=dead_tab_change_binding
     * @effects detail_tab_nav_has_no_dead_binding
     */
    it.each([
        ['claim detail', () => byId(claimDetail, 'claim_tab_navigation')],
        ['order detail', () => byId(orderDetail, 'tab_navigation')],
    ])('%s', (_label, get) => {
        const nav = get();

        expect(nav.name).toBe('TabNavigationScroll');
        expect((nav.actions ?? []).filter((a: any) => a.event !== 'onTabChange')).toHaveLength(0);
    });
});

describe('#20 · #21 사유 카드 폴백', () => {
    /**
     * 설정이 비면 `maxSize` 가 0 이 되어 업로더가 모든 파일을 거절했다.
     *
     * @scenario surface=claim_detail, defect=uploader_max_size_zero
     * @effects claim_uploader_max_size_from_server_mb
     */
    it('업로더 상한은 서버 MB 값을 읽고 0 으로 떨어지지 않는다', () => {
        const uploader = byId(claimReason, 'cr_006_images_uploader');

        expect(String(uploader.props.maxSize)).toContain('image_max_size_mb');
        expect(String(uploader.props.maxSize)).not.toContain('?? 0');
    });

    /**
     * @scenario surface=claim_detail, defect=empty_reason_detail
     * @effects claim_empty_reason_detail_shows_placeholder
     */
    it('빈 문자열 사유 상세도 안내 문구로 떨어진다', () => {
        const detail = byId(claimReason, 'cr_004_reason_detail');

        expect(renderJson(detail)).toContain("reason_detail || '$t:sirsoft-ecommerce.admin.claim.detail.reason.no_detail'");
    });
});

describe('#22 활동 로그 ID 라벨 — 다국어 키', () => {
    /**
     * @scenario surface=claim_detail, defect=hardcoded_id_label
     * @effects claim_activity_log_id_label_translated
     */
    it('ID: 를 하드코딩하지 않는다', () => {
        const text = renderJson(claimActivityLog);

        expect(text).toContain('$t:sirsoft-ecommerce.admin.claim.detail.activity_log.changes.model_id|id={{group.model_id}}');
        expect(text).not.toContain('ID: {{group.model_id}}');
    });
});

const claimComparison = load('partials/admin_ecommerce_claim_detail/_partial_claim_comparison.json');

describe('P7 후속 — 서버가 확정한 응답 필드로 옮긴다', () => {
    /**
     * 관리자 회원 화면은 uuid 로 연다 — 숫자 id 링크는 404 였다(11-#1). 응답이 `requester_user_uuid` 를 싣는다.
     *
     * @scenario surface=claim_detail, defect=requester_link_numeric_id
     * @effects claim_requester_link_uses_uuid
     */
    it('신청자 링크가 requester_user_uuid 로 조건·경로를 만든다', () => {
        const link = byId(claimDetail, 'cd_008_requester');
        const plain = byId(claimDetail, 'cd_008_requester_plain');
        const text = renderJson(link);

        expect(link.if).toBe('{{!!claim?.data?.claim?.requester_user_uuid}}');
        expect(plain.if).toBe('{{!claim?.data?.claim?.requester_user_uuid}}');
        expect(text).toContain('/admin/users/{{claim?.data?.claim?.requester_user_uuid}}');
        expect(text).not.toContain('requester_user_id');
    });

    /**
     * 적립 포인트 표기는 서버 formatted(「1,000P」)다 — 화면이 `| number`·`toLocaleString()` + 'P' 로 만들면
     * 로케일·단위가 화면마다 갈린다. 필드가 없는 옛 응답만 종전 표기로 떨어진다.
     *
     * @scenario surface=claim_detail, defect=client_formatted_points
     * @effects claim_earned_points_render_server_formatted
     */
    it('결제 카드와 비교표가 적립 포인트 formatted 를 먼저 읽는다', () => {
        const payment = renderJson(claimPayment);
        expect(payment).toContain('"if":"{{claim?.data?.order?.total_earned_points_amount_formatted != null}}"');
        expect(payment).toContain('"text":"{{claim?.data?.order?.total_earned_points_amount_formatted}}"');

        const comparison = renderJson(claimComparison);
        expect(comparison).toContain('calculation_comparison?.original?.total_earned_points_amount_formatted ??');
        expect(comparison).toContain('calculation_comparison?.recalculated?.total_earned_points_amount_formatted ??');
    });

    /**
     * @scenario surface=claim_refund_execute_modal, defect=dead_lang_key
     * @effects claim_refund_bank_placeholder_key_removed
     */
    it('쓰이지 않는 refund_bank_code_placeholder 키가 ko·en·ja 에서 빠졌다', () => {
        const files = [
            path.resolve(LAYOUT_ROOT, '../../lang/partial/ko/admin/claim.json'),
            path.resolve(LAYOUT_ROOT, '../../lang/partial/en/admin/claim.json'),
            path.resolve(LAYOUT_ROOT, '../../../../../../lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/frontend/partial/admin/claim.json'),
        ];
        for (const file of files) {
            expect(fs.readFileSync(file, 'utf8'), file).not.toContain('refund_bank_code_placeholder');
        }
        expect(renderJson(refundExecuteModal)).not.toContain('refund_bank_code_placeholder');
    });
});
