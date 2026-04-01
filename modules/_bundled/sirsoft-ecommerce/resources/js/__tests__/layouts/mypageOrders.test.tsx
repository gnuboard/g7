/**
 * 마이페이지 주문내역 레이아웃 구조 검증 테스트
 *
 * @description
 * - orders.json 데이터소스 및 API 엔드포인트 검증
 * - _list.json 주문상태 통계 UI, 필드명 바인딩, 상태 필터 검증
 * - show.json 주문 상세 엔드포인트 검증
 * - _modal_cancel.json 취소 모달 엔드포인트 검증
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import ordersLayout from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/mypage/orders.json';
import listPartial from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_list.json';
import showLayout from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/mypage/orders/show.json';
import cancelModalPartial from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_modal_cancel.json';
import itemsPartial from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_items.json';

// ========================================================================
// orders.json (주문 목록 페이지)
// ========================================================================

describe('마이페이지 주문 목록 레이아웃 검증 (orders.json)', () => {
    it('_user_base 레이아웃을 상속해야 함', () => {
        expect(ordersLayout.extends).toBe('_user_base');
    });

    it('데이터소스가 올바른 모듈 API 엔드포인트를 사용해야 함', () => {
        const ds = ordersLayout.data_sources[0];
        expect(ds.id).toBe('orders');
        expect(ds.endpoint).toContain('/api/modules/sirsoft-ecommerce/user/orders');
        expect(ds.method).toBe('GET');
        expect(ds.auto_fetch).toBe(true);
        expect(ds.auth_required).toBe(true);
    });

    it('데이터소스에 페이지네이션 및 상태 필터 params가 포함되어야 함', () => {
        const ds = ordersLayout.data_sources[0];
        expect(ds.params).toBeDefined();
        expect(ds.params.page).toContain('query.page');
        expect(ds.params.status).toContain('query.status');
    });

    it('init_actions에서 ordersPage와 ordersStatus 초기값을 설정해야 함', () => {
        const initAction = ordersLayout.init_actions[0];
        expect(initAction.handler).toBe('setState');
        expect(initAction.params.target).toBe('local');
        expect(initAction.params.ordersPage).toContain('query.page');
        expect(initAction.params.ordersStatus).toContain('query.status');
    });

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
        // 첫 번째 자식이 통계 섹션
        const statisticsSection = rootChildren[0];

        it('통계 섹션이 존재해야 함', () => {
            expect(statisticsSection).toBeDefined();
            expect(statisticsSection.comment).toContain('주문상태');
        });

        it('6개 상태 항목이 있어야 함 (입금대기, 결제완료, 배송준비중, 배송중, 배송완료, 구매확정)', () => {
            // 통계 섹션의 flex 컨테이너 → 상태 아이템 + 화살표 구분자
            const flexContainer = statisticsSection.children[0];
            const statusItems = flexContainer.children.filter(
                (c: any) => c.comment && !c.comment.includes('화살표')
            );
            expect(statusItems.length).toBe(6);
        });

        it('상태 항목 클릭 시 sequence 액션으로 setState + navigate가 있어야 함', () => {
            const flexContainer = statisticsSection.children[0];
            const firstStatusItem = flexContainer.children.find(
                (c: any) => c.comment && !c.comment.includes('화살표')
            );
            expect(firstStatusItem.actions).toBeDefined();
            expect(firstStatusItem.actions.length).toBe(1);

            // sequence 핸들러로 setState + navigate 묶임
            const sequenceAction = firstStatusItem.actions[0];
            expect(sequenceAction.handler).toBe('sequence');
            expect(sequenceAction.actions).toBeDefined();

            const setStateAction = sequenceAction.actions.find(
                (a: any) => a.handler === 'setState'
            );
            expect(setStateAction).toBeDefined();
            expect(setStateAction.params.target).toBe('local');

            const navAction = sequenceAction.actions.find(
                (a: any) => a.handler === 'navigate'
            );
            expect(navAction).toBeDefined();
            expect(navAction.params.path).toContain('/mypage/orders');
            expect(navAction.params.query).toBeDefined();
        });

        it('통계 건수가 올바른 데이터 경로를 참조해야 함', () => {
            const flexContainer = statisticsSection.children[0];
            const statusItems = flexContainer.children.filter(
                (c: any) => c.comment && !c.comment.includes('화살표')
            );

            // 각 상태 항목의 건수 표시 Span을 찾아 올바른 statistics 경로 확인
            const statisticsKeys = [
                'pending_payment',
                'payment_complete',
                'preparing',
                'shipping',
                'delivered',
                'confirmed',
            ];

            statusItems.forEach((item: any, index: number) => {
                const jsonStr = JSON.stringify(item);
                expect(jsonStr).toContain(`orders.data.statistics.${statisticsKeys[index]}`);
            });
        });

        it('화살표 구분자가 5개 있어야 함 (6개 상태 사이)', () => {
            const flexContainer = statisticsSection.children[0];
            const arrows = flexContainer.children.filter(
                (c: any) => c.comment && c.comment.includes('화살표')
            );
            expect(arrows.length).toBe(5);
        });

        it('상태 아이템에 classMap이 적용되어야 함 (활성 상태 스타일)', () => {
            const flexContainer = statisticsSection.children[0];
            const firstStatusItem = flexContainer.children.find(
                (c: any) => c.comment && !c.comment.includes('화살표')
            );

            // classMap이 하위 어딘가에 존재하는지 JSON으로 확인
            const jsonStr = JSON.stringify(firstStatusItem);
            expect(jsonStr).toContain('classMap');
            expect(jsonStr).toContain('_local.ordersStatus');
        });
    });

    describe('다국어 키 형식', () => {
        const listJsonStr = JSON.stringify(listPartial);

        it('상태 라벨이 sirsoft-ecommerce.enums.order_status 키를 사용해야 함', () => {
            expect(listJsonStr).toContain('$t:sirsoft-ecommerce.enums.order_status.');
            expect(listJsonStr).not.toContain('$t:ecommerce.order_status.');
        });
    });

    describe('주문 목록 필드명 바인딩', () => {
        // 전체 JSON을 문자열로 변환하여 필드명 확인
        const listJsonStr = JSON.stringify(listPartial);

        it('주문일시 필드가 ordered_at_formatted를 사용해야 함', () => {
            expect(listJsonStr).toContain('order.ordered_at_formatted');
            expect(listJsonStr).not.toContain('order.created_at_formatted');
        });

        it('총 금액 필드가 total_amount_formatted를 사용해야 함', () => {
            expect(listJsonStr).toContain('order.total_amount_formatted');
            expect(listJsonStr).not.toContain('order.total_formatted');
        });

        it('상품 썸네일이 item.thumbnail_url을 사용해야 함', () => {
            expect(listJsonStr).toContain('item.thumbnail_url');
            expect(listJsonStr).not.toContain('item.product.thumbnail');
        });

        it('상품명이 item.product_name을 사용해야 함', () => {
            expect(listJsonStr).toContain('item.product_name');
            expect(listJsonStr).not.toContain('item.product.name');
        });

        it('옵션명이 item.product_option_name을 사용해야 함', () => {
            expect(listJsonStr).toContain('item.product_option_name');
            expect(listJsonStr).not.toContain('item.option_text');
        });

        it('다통화 금액이 order.mc_total_amount를 사용해야 함', () => {
            expect(listJsonStr).toContain('order.mc_total_amount');
            expect(listJsonStr).not.toContain('order.multi_currency_total');
        });

        it('다통화 단가가 item.mc_unit_price를 사용해야 함', () => {
            expect(listJsonStr).toContain('item.mc_unit_price');
            expect(listJsonStr).not.toContain('item.multi_currency_unit_price');
        });

        it('옵션 소계가 item.subtotal_price_formatted를 사용해야 함', () => {
            expect(listJsonStr).toContain('item.subtotal_price_formatted');
        });

        it('다통화 소계가 item.mc_subtotal_price를 사용해야 함', () => {
            expect(listJsonStr).toContain('item.mc_subtotal_price');
        });

        it('배송비가 order.total_shipping_amount를 사용해야 함', () => {
            expect(listJsonStr).toContain('order.total_shipping_amount');
        });

        it('다통화 배송비가 order.mc_total_shipping_amount를 사용해야 함', () => {
            expect(listJsonStr).toContain('order.mc_total_shipping_amount');
        });
    });

    describe('주문 카드 UI 요소', () => {
        const listJsonStr = JSON.stringify(listPartial);

        it('주문상태 배지가 basic Span + classMap으로 구현되어야 함 (StatusBadge 미사용)', () => {
            // StatusBadge composite 컴포넌트가 아닌 basic Span 사용
            expect(listJsonStr).toContain('order.status_label');
            expect(listJsonStr).toContain('order.status');

            // classMap에 주문상태별 variant가 있어야 함
            expect(listJsonStr).toContain('"pending_payment"');
            expect(listJsonStr).toContain('"cancelled"');
        });

        it('상품 이미지에 thumbnail_url 존재 여부에 따른 조건부 렌더링이 있어야 함', () => {
            expect(listJsonStr).toContain('item.thumbnail_url');
            expect(listJsonStr).toContain('!item.thumbnail_url');
        });

        it('배송비 무료/유료 조건 분기가 있어야 함', () => {
            expect(listJsonStr).toContain('order.total_shipping_amount');
            expect(listJsonStr).toContain('$t:mypage.orders.shipping_fee');
            expect(listJsonStr).toContain('$t:mypage.orders.free_shipping');
        });
    });

    describe('상태 필터 값', () => {
        const listJsonStr = JSON.stringify(listPartial);

        it('OrderStatusEnum 값을 사용해야 함 (pending_payment, payment_complete 등)', () => {
            expect(listJsonStr).toContain('pending_payment');
            expect(listJsonStr).toContain('payment_complete');
            expect(listJsonStr).toContain('shipping');
            expect(listJsonStr).toContain('delivered');
            expect(listJsonStr).toContain('cancelled');
        });

        it('잘못된 상태 필터값을 사용하지 않아야 함', () => {
            // 기존 잘못된 값들이 없는지 확인
            // "pending"은 pending_payment 안에 포함되므로 독립적 검증
            expect(listJsonStr).not.toContain('"paid"');
            expect(listJsonStr).not.toContain('"shipped"');
        });
    });

    describe('페이지네이션 경로', () => {
        const listJsonStr = JSON.stringify(listPartial);

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
    it('_user_base 레이아웃을 상속해야 함', () => {
        expect(showLayout.extends).toBe('_user_base');
    });

    it('데이터소스가 올바른 모듈 API 엔드포인트를 사용해야 함', () => {
        const ds = showLayout.data_sources[0];
        expect(ds.id).toBe('order');
        expect(ds.endpoint).toBe('/api/modules/sirsoft-ecommerce/user/orders/{{route.order_number}}');
        expect(ds.method).toBe('GET');
        expect(ds.auto_fetch).toBe(true);
        expect(ds.auth_required).toBe(true);
    });

    it('/api/shop/ 경로를 사용하지 않아야 함', () => {
        const ds = showLayout.data_sources[0];
        expect(ds.endpoint).not.toContain('/api/shop/');
    });

    it('취소 모달 partial을 포함해야 함', () => {
        const modalRef = showLayout.modals?.find(
            (m: any) => m.partial && m.partial.includes('_modal_cancel.json')
        );
        expect(modalRef).toBeDefined();
    });
});

// ========================================================================
// _modal_cancel.json (주문 취소 모달 - 부분취소/전체취소 지원)
// ========================================================================

describe('주문 취소 모달 검증 (_modal_cancel.json)', () => {
    const cancelJsonStr = JSON.stringify(cancelModalPartial);

    it('모달이 openModal/closeModal 패턴으로 제어되어야 함', () => {
        expect(cancelModalPartial.id).toBe('modal_cancel_order');
        expect(cancelModalPartial.props.title).toBeDefined();
    });

    it('Modal 컴포넌트 타입이어야 함', () => {
        expect(cancelModalPartial.type).toBe('composite');
        expect(cancelModalPartial.name).toBe('Modal');
    });

    describe('상품 선택 영역', () => {
        it('전체 선택 체크박스가 있어야 함', () => {
            expect(cancelJsonStr).toContain('_local.cancelSelectAll');
            expect(cancelJsonStr).toContain('sirsoft-ecommerce.toggleUserCancelSelectAll');
        });

        it('cancelItems에 대한 iteration이 있어야 함', () => {
            // JSON.stringify는 공백 없이 직렬화하므로 colon 뒤 공백 없음
            expect(cancelJsonStr).toContain('"source":"_local.cancelItems"');
            expect(cancelJsonStr).toContain('"item_var":"cancelItem"');
            expect(cancelJsonStr).toContain('"index_var":"cancelIdx"');
        });

        it('개별 상품 선택 체크박스가 있어야 함', () => {
            expect(cancelJsonStr).toContain('cancelItem.selected');
            expect(cancelJsonStr).toContain('sirsoft-ecommerce.toggleUserCancelItem');
        });

        it('취소 수량 입력이 있어야 함', () => {
            expect(cancelJsonStr).toContain('cancelItem.cancel_quantity');
            expect(cancelJsonStr).toContain('sirsoft-ecommerce.updateUserCancelQuantity');
        });

        it('상품 정보 (이름, 옵션명, 단가, 썸네일)가 표시되어야 함', () => {
            expect(cancelJsonStr).toContain('cancelItem.product_name');
            expect(cancelJsonStr).toContain('cancelItem.product_option_name');
            expect(cancelJsonStr).toContain('cancelItem.unit_price');
            expect(cancelJsonStr).toContain('cancelItem.thumbnail_url');
        });
    });

    describe('환불 예정금액 영역', () => {
        it('환불 예정금액 제목이 있어야 함', () => {
            expect(cancelJsonStr).toContain('$t:mypage.order_detail.cancel_modal.refund_estimate_title');
        });

        it('로딩 상태 표시가 있어야 함', () => {
            expect(cancelJsonStr).toContain('_local.refundLoading');
            expect(cancelJsonStr).toContain('$t:mypage.order_detail.cancel_modal.refund_loading');
        });

        it('환불 금액 항목이 표시되어야 함', () => {
            expect(cancelJsonStr).toContain('refundEstimate?.refund_amount');
            expect(cancelJsonStr).toContain('$t:mypage.order_detail.cancel_modal.refund_product_amount');
        });

        it('배송비 변동이 조건부로 표시되어야 함', () => {
            expect(cancelJsonStr).toContain('refundEstimate?.shipping_difference');
            expect(cancelJsonStr).toContain('$t:mypage.order_detail.cancel_modal.refund_shipping_diff');
        });

        it('할인 조정이 조건부로 표시되어야 함', () => {
            expect(cancelJsonStr).toContain('refundEstimate?.discount_difference');
            expect(cancelJsonStr).toContain('$t:mypage.order_detail.cancel_modal.refund_discount_diff');
        });

        it('최종 환불 예정액이 표시되어야 함', () => {
            expect(cancelJsonStr).toContain('$t:mypage.order_detail.cancel_modal.refund_total');
        });
    });

    describe('취소 사유 선택', () => {
        it('환불 우선순위 라디오 버튼이 2개 있어야 함', () => {
            const radioMatches = cancelJsonStr.match(/"type":\s*"radio"/g);
            expect(radioMatches).toBeDefined();
            expect(radioMatches!.length).toBe(2);
        });

        it('4가지 취소 사유 옵션이 Select에 있어야 함', () => {
            expect(cancelJsonStr).toContain('"order_mistake"');
            expect(cancelJsonStr).toContain('"changed_mind"');
            expect(cancelJsonStr).toContain('"reorder_other"');
            expect(cancelJsonStr).toContain('"etc"');
        });

        it('사유 선택 시 cancelReason 상태가 변경되어야 함', () => {
            expect(cancelJsonStr).toContain('"cancelReason"');
            expect(cancelJsonStr).toContain('cancelReason');
        });
    });

    describe('모달 액션 버튼', () => {
        it('취소 실행 버튼이 커스텀 핸들러를 사용해야 함', () => {
            expect(cancelJsonStr).toContain('sirsoft-ecommerce.executeUserCancelOrder');
        });

        it('취소 버튼이 비활성 조건을 가져야 함 (사유 미선택 또는 처리 중)', () => {
            expect(cancelJsonStr).toContain('cancelReason');
            expect(cancelJsonStr).toContain('_local.isCancelling');
        });

        it('닫기 버튼이 모달 상태를 초기화해야 함', () => {
            expect(cancelJsonStr).toContain('"showCancelModal":false');
        });
    });

    describe('에러 표시 영역', () => {
        it('취소 에러 메시지 영역이 있어야 함', () => {
            expect(cancelJsonStr).toContain('_local.cancelError');
            expect(cancelJsonStr).toContain('$t:mypage.order_detail.cancel_modal.error_title');
        });
    });
});

// ========================================================================
// show.json 취소 관련 initLocal 상태 검증
// ========================================================================

describe('주문 상세 취소 관련 initLocal 검증 (show.json)', () => {
    const initLocal = showLayout.initLocal;

    it('취소 모달 상태 초기값이 있어야 함', () => {
        expect(initLocal.showCancelModal).toBe(false);
        expect(initLocal.cancelItems).toEqual([]);
        expect(initLocal.cancelSelectAll).toBe(false);
        expect(initLocal.cancelReason).toBe('');
    });

    it('환불 관련 상태 초기값이 있어야 함', () => {
        expect(initLocal.refundEstimate).toBeNull();
        expect(initLocal.refundLoading).toBe(false);
        expect(initLocal.isCancelling).toBe(false);
        expect(initLocal.cancelError).toBeNull();
    });

    it('취소 버튼이 initUserCancelItems 커스텀 핸들러를 사용해야 함', () => {
        const itemsJsonStr = JSON.stringify(itemsPartial);
        expect(itemsJsonStr).toContain('sirsoft-ecommerce.initUserCancelItems');
    });
});
