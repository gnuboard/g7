// e2e:allow 레이아웃 JSON 판독을 모양(actions 배열 첨자)에서 뜻(layoutShapeUtils.actionsOf)으로 옮긴 테스트 전용 리팩토링 — 대상 레이아웃·핸들러·런타임 동작은 바뀌지 않았고, 단언이 보는 명제도 동일하다 (U-SHAPEASSERT 판정 ⒜ · D67 ⓐ 미전환 잔여분 해소).
/**
 * 마이페이지 주문내역 레이아웃 구조 검증 테스트
 *
 * @description
 * - orders.json 데이터소스 및 API 엔드포인트 검증
 * - _list.json 주문상태 통계 UI, 필드명 바인딩, 상태 필터 검증
 * - show.json 주문 상세 엔드포인트 검증
 * - 옛 주문 취소 모달(_modal_cancel.json) 삭제 뒤 셸·partial 에 흔적이 없음을 검증
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import ordersLayout from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/mypage/orders.json';
import listPartial from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_list.json';
import showLayout from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/mypage/orders/show.json';
import itemsPartial from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_items.json';
import { actionsOf, asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

// ========================================================================
// orders.json (주문 목록 페이지)
// ========================================================================

describe('마이페이지 주문 목록 레이아웃 검증 (orders.json)', () => {
    // @scenario screen=list
    // @effects mypage_orders_layout_extends_user_base
    it('_user_base 레이아웃을 상속해야 함', () => {
        expect(ordersLayout.extends).toBe('_user_base');
    });

    // @scenario screen=list
    // @effects mypage_orders_datasource_uses_module_api_with_page_and_status
    it('데이터소스가 올바른 모듈 API 엔드포인트를 사용해야 함', () => {
        const ds = ordersLayout.data_sources[0];
        expect(ds.id).toBe('orders');
        expect(ds.endpoint).toContain('/api/modules/sirsoft-ecommerce/user/orders');
        expect(ds.method).toBe('GET');
        expect(ds.auto_fetch).toBe(true);
        expect(ds.auth_required).toBe(true);
    });

    // @scenario screen=list
    // @effects mypage_orders_datasource_uses_module_api_with_page_and_status
    it('데이터소스에 페이지네이션 및 상태 필터 params가 포함되어야 함', () => {
        const ds = ordersLayout.data_sources[0];
        expect(ds.params).toBeDefined();
        expect(ds.params.page).toContain('query.page');
        expect(ds.params.status).toContain('query.status');
    });

    // @scenario screen=list
    // @effects mypage_orders_init_actions_seed_page_and_status
    it('init_actions에서 ordersPage와 ordersStatus 초기값을 설정해야 함', () => {
        const initAction = asArray((ordersLayout as any).init_actions)[0];
        expect(initAction.handler).toBe('setState');
        expect(initAction.params.target).toBe('local');
        expect(initAction.params.ordersPage).toContain('query.page');
        expect(initAction.params.ordersStatus).toContain('query.status');
    });

    // @scenario screen=list
    // @effects mypage_orders_list_partial_is_referenced
    it('주문 목록 partial을 참조해야 함', () => {
        const content = ordersLayout.slots.content[0];
        const container = content.children[0];
        // partial은 중첩 구조 내에 있을 수 있으므로 재귀 검색
        const findPartial = (nodes: any[]): any => {
            for (const node of nodes) {
                if (node.partial && node.partial.includes('orders/_list.json')) return node;
                if (node.children) {
                    const found = findPartial(node.children);
                    if (found) return found;
                }
            }
            return undefined;
        };
        const listPartialRef = findPartial(container.children);
        expect(listPartialRef).toBeDefined();
    });
});

// ========================================================================
// _list.json (주문 목록 partial)
// ========================================================================

describe('주문 목록 partial 구조 검증 (_list.json)', () => {
    const rootChildren = listPartial.children;

    describe('주문상태 통계 섹션', () => {
        // 첫 번째 자식이 통계 섹션 — 칸 배열(키·라벨·아이콘·건수)은 서버(status_summary)가 만든다.
        const statisticsSection = rootChildren[0];
        const stepsWrapper = statisticsSection.children[0];
        const stepCell = (stepsWrapper.children ?? []).find((c: any) => c.id === 'order_summary_step_{{stepIdx}}');

        // @scenario screen=list
        // @effects mypage_orders_status_summary_reads_server_vocabulary
        it('통계 섹션이 존재해야 함', () => {
            expect(statisticsSection).toBeDefined();
            expect(statisticsSection.comment).toContain('주문상태');
            expect(stepsWrapper.id).toBe('order_summary_steps');
        });

        // @scenario screen=list
        // @effects mypage_orders_status_summary_reads_server_vocabulary
        it('상태 칸은 서버가 만든 status_summary 배열을 반복한다 (칸 수·라벨·아이콘은 서버 어휘)', () => {
            expect(stepCell).toBeDefined();
            expect(stepCell.iteration.source).toBe('{{orders.data?.status_summary ?? []}}');
            expect(stepCell.iteration.item_var).toBe('step');
            expect(stepCell.props['data-summary-key']).toBe("{{step.key ?? ''}}");
            expect(stepCell.props['data-summary-count']).toBe('{{step.count ?? 0}}');
        });

        // @scenario screen=list
        // @effects mypage_orders_status_cell_click_filters_the_list
        it('상태 칸 클릭 시 sequence 액션으로 setState + navigate가 있어야 함', () => {
            expect(stepCell.actions).toBeDefined();
            expect(actionsOf(stepCell).length).toBe(1);

            const sequenceAction = actionsOf(stepCell)[0];
            expect(sequenceAction.handler).toBe('sequence');

            const setStateAction = actionsOf(sequenceAction).find((a: any) => a.handler === 'setState');
            expect(setStateAction).toBeDefined();
            expect(setStateAction.params.target).toBe('local');
            expect(setStateAction.params.ordersStatus).toBe('{{step.key}}');

            const navAction = actionsOf(sequenceAction).find((a: any) => a.handler === 'navigate');
            expect(navAction).toBeDefined();
            expect(navAction.params.path).toContain('/mypage/orders');
            expect(navAction.params.query.status).toBe('{{step.key}}');
        });

        // @scenario screen=list
        // @effects mypage_orders_status_cell_click_filters_the_list
        it('상태 칸에 classMap이 적용되어야 함 (선택 칸·건수 0 칸 구분)', () => {
            const jsonStr = JSON.stringify(stepCell);
            expect(jsonStr).toContain('classMap');
            expect(jsonStr).toContain('_local.ordersStatus');
        });
    });

    describe('다국어 키 형식', () => {
        const listJsonStr = JSON.stringify(listPartial);

        // @scenario screen=list
        // @effects mypage_orders_status_summary_reads_server_vocabulary
        it('상태 라벨은 서버 표기(status_label)를 쓰고 옛 ecommerce.order_status 키를 쓰지 않는다', () => {
            expect(listJsonStr).toContain('status_label');
            expect(listJsonStr).not.toContain('$t:ecommerce.order_status.');
        });
    });

    describe('주문 목록 필드명 바인딩', () => {
        // 전체 JSON을 문자열로 변환하여 필드명 확인
        const listJsonStr = JSON.stringify(listPartial);

        // @scenario screen=list
        // @effects mypage_orders_list_binds_server_formatted_fields
        it('주문일시 필드가 ordered_at_formatted를 사용해야 함', () => {
            expect(listJsonStr).toContain('order.ordered_at_formatted');
            expect(listJsonStr).not.toContain('order.created_at_formatted');
        });

        // @scenario screen=list
        // @effects mypage_orders_list_binds_server_formatted_fields
        it('총 금액 필드가 total_amount_formatted를 사용해야 함', () => {
            expect(listJsonStr).toContain('order.total_amount_formatted');
            expect(listJsonStr).not.toContain('order.total_formatted');
        });

        // @scenario screen=list
        // @effects mypage_orders_list_binds_server_formatted_fields
        it('상품 썸네일이 item.thumbnail_url을 사용해야 함', () => {
            expect(listJsonStr).toContain('item.thumbnail_url');
            expect(listJsonStr).not.toContain('item.product.thumbnail');
        });

        // @scenario screen=list
        // @effects mypage_orders_list_binds_server_formatted_fields
        it('상품명이 item.product_name을 사용해야 함', () => {
            expect(listJsonStr).toContain('item.product_name');
            expect(listJsonStr).not.toContain('item.product.name');
        });

        // @scenario screen=list
        // @effects mypage_orders_list_binds_server_formatted_fields
        it('옵션명이 item.product_option_name을 사용해야 함', () => {
            expect(listJsonStr).toContain('item.product_option_name');
            expect(listJsonStr).not.toContain('item.option_text');
        });

        // @scenario screen=list
        // @effects mypage_orders_list_binds_server_formatted_fields
        it('다통화 금액이 order.mc_total_amount를 사용해야 함', () => {
            expect(listJsonStr).toContain('order.mc_total_amount');
            expect(listJsonStr).not.toContain('order.multi_currency_total');
        });

        // @scenario screen=list
        // @effects mypage_orders_list_binds_server_formatted_fields
        it('단가는 결제 통화로 굳은 unit_price_formatted 를 그대로 쓴다', () => {
            // 이미 결제가 끝난 주문의 금액은 결제 시점 통화로 고정이다. 사용자가 지금 보고 있는
            // 통화(_global.preferredCurrency)로 환산해 보여주면 실제 결제액과 달라진다.
            // 서버가 formatOrderCurrency 로 확정한 문자열을 표시만 한다. 단가 단위의
            // 다통화 필드(mc_unit_price)는 이 레이아웃에 존재하지 않는다(주문완료 화면 전용).
            expect(listJsonStr).toContain('item.unit_price_formatted');
            expect(listJsonStr).not.toContain('item.mc_unit_price');
            expect(listJsonStr).not.toContain('item.multi_currency_unit_price');
        });

        // @scenario screen=list
        // @effects mypage_orders_list_binds_server_formatted_fields
        it('옵션 소계가 item.subtotal_price_formatted를 사용해야 함', () => {
            expect(listJsonStr).toContain('item.subtotal_price_formatted');
        });

        // @scenario screen=list
        // @effects mypage_orders_list_binds_server_formatted_fields
        it('다통화 소계 목록은 결제 통화를 제외하고 나열한다', () => {
            // 소계만 다통화 병기를 남긴다 — 참고 표시이므로 결제 통화 행은 중복이라 걸러낸다.
            expect(listJsonStr).toContain('item.mc_subtotal_price');
            expect(listJsonStr).toContain('order.base_currency');
        });

        // @scenario screen=list
        // @effects mypage_orders_list_binds_server_formatted_fields
        it('배송비는 결제 통화로 굳은 total_shipping_amount_formatted 를 그대로 쓴다', () => {
            expect(listJsonStr).toContain('order.total_shipping_amount');
            expect(listJsonStr).toContain('order.total_shipping_amount_formatted');
        });

        // @scenario screen=list
        // @effects mypage_orders_list_binds_server_formatted_fields
        it('다통화 총액이 order.mc_total_amount를 사용해야 함', () => {
            // 배송비 단위의 다통화 필드(mc_total_shipping_amount)는 없다. 다통화 금액은
            // 주문 총액(mc_total_amount) 단위로만 표시된다(위 '다통화 금액' 테스트와 동일 근거).
            expect(listJsonStr).toContain('order.mc_total_amount');
            expect(listJsonStr).not.toContain('order.mc_total_shipping_amount');
        });
    });

    describe('주문 카드 UI 요소', () => {
        const listJsonStr = JSON.stringify(listPartial);

        // @scenario screen=list
        // @effects mypage_orders_card_ui_branches_on_data
        it('주문상태 배지가 basic Span + classMap으로 구현되어야 함 (StatusBadge 미사용)', () => {
            // StatusBadge composite 컴포넌트가 아닌 basic Span 사용
            expect(listJsonStr).toContain('order.status_label');
            expect(listJsonStr).toContain('order.status');

            // classMap에 주문상태별 variant가 있어야 함
            expect(listJsonStr).toContain('"pending_payment"');
            expect(listJsonStr).toContain('"cancelled"');
        });

        // @scenario screen=list
        // @effects mypage_orders_card_ui_branches_on_data
        it('상품 이미지에 thumbnail_url 존재 여부에 따른 조건부 렌더링이 있어야 함', () => {
            expect(listJsonStr).toContain('item.thumbnail_url');
            expect(listJsonStr).toContain('!item.thumbnail_url');
        });

        // @scenario screen=list
        // @effects mypage_orders_card_ui_branches_on_data
        it('배송비 무료/유료 조건 분기가 있어야 함', () => {
            expect(listJsonStr).toContain('order.total_shipping_amount');
            expect(listJsonStr).toContain('$t:mypage.orders.shipping_fee');
            expect(listJsonStr).toContain('$t:mypage.orders.free_shipping');
        });
    });

    describe('상태 필터 값', () => {
        const listJsonStr = JSON.stringify(listPartial);

        // @scenario screen=list
        // @effects mypage_orders_status_filter_uses_enum_values
        it('OrderStatusEnum 값을 사용해야 함 (pending_payment, payment_complete 등)', () => {
            expect(listJsonStr).toContain('pending_payment');
            expect(listJsonStr).toContain('payment_complete');
            expect(listJsonStr).toContain('shipping');
            expect(listJsonStr).toContain('delivered');
            expect(listJsonStr).toContain('cancelled');
        });

        // @scenario screen=list
        // @effects mypage_orders_status_filter_uses_enum_values
        it('잘못된 상태 필터값을 사용하지 않아야 함', () => {
            // 기존 잘못된 값들이 없는지 확인
            // "pending"은 pending_payment 안에 포함되므로 독립적 검증
            expect(listJsonStr).not.toContain('"paid"');
            expect(listJsonStr).not.toContain('"shipped"');
        });
    });

    describe('페이지네이션 경로', () => {
        const listJsonStr = JSON.stringify(listPartial);

        // @scenario screen=list
        // @effects mypage_orders_pagination_reads_meta_path
        it('pagination 하위 경로를 사용해야 함', () => {
            expect(listJsonStr).toContain('orders.data.pagination.last_page');
            expect(listJsonStr).toContain('orders.data.pagination.current_page');
        });
    });
});

// ========================================================================
// show.json (주문 상세 페이지)
// ========================================================================

describe('주문 상세 레이아웃 검증 (show.json)', () => {
    // @scenario screen=detail
    // @effects mypage_orders_layout_extends_user_base
    it('_user_base 레이아웃을 상속해야 함', () => {
        expect(showLayout.extends).toBe('_user_base');
    });

    // @scenario screen=detail
    // @effects mypage_order_detail_uses_module_api
    it('데이터소스가 올바른 모듈 API 엔드포인트를 사용해야 함', () => {
        const ds = showLayout.data_sources[0];
        expect(ds.id).toBe('order');
        expect(ds.endpoint).toBe('/api/modules/sirsoft-ecommerce/user/orders/{{route.order_number}}');
        expect(ds.method).toBe('GET');
        expect(ds.auto_fetch).toBe(true);
        expect(ds.auth_required).toBe(true);
    });

    // @scenario screen=detail
    // @effects mypage_order_detail_uses_module_api
    it('/api/shop/ 경로를 사용하지 않아야 함', () => {
        const ds = showLayout.data_sources[0];
        expect(ds.endpoint).not.toContain('/api/shop/');
    });
});

// ========================================================================
// 옛 주문 취소 모달 삭제 — 셸·partial 에 흔적이 남지 않는다
// ========================================================================

describe('옛 주문 취소 모달 삭제 검증 (show.json · _items.json)', () => {
    // @scenario screen=detail
    // @effects mypage_order_detail_has_no_legacy_cancel_modal
    it('show.json 은 _modal_cancel.json 을 싣지 않고 취소 모달 로컬 상태도 세우지 않는다', () => {
        const modalRef = (showLayout.modals ?? []).find(
            (m: any) => m.partial && m.partial.includes('_modal_cancel.json')
        );
        expect(modalRef).toBeUndefined();

        for (const key of ['showCancelModal', 'cancelItems', 'cancelSelectAll', 'cancelReason', 'refundEstimate', 'refundLoading', 'isCancelling', 'cancelError', 'useClaimActions']) {
            expect(showLayout.initLocal[key], key).toBeUndefined();
        }
        // 클레임 진입이 쓰는 선택 상태는 남는다.
        expect(showLayout.initLocal.selectedItemIds).toEqual([]);
        expect(showLayout.initLocal.selectAllItems).toBe(false);
    });

    // @scenario screen=detail
    // @effects mypage_order_detail_has_no_legacy_cancel_modal
    it('_items.json 의 상품 선택 축은 남고 옛 취소 버튼 핸들러 참조는 0 이다', () => {
        const itemsJsonStr = JSON.stringify(itemsPartial);
        expect(itemsJsonStr).not.toContain('sirsoft-ecommerce.initUserCancelItems');
        expect(itemsJsonStr).not.toContain('cancel_modal.');
        expect(itemsJsonStr).toContain('sirsoft-ecommerce.toggleItemSelection');
        expect(itemsJsonStr).toContain('sirsoft-ecommerce.toggleSelectAllItems');
    });
});
