/**
 * 관리자 주문 취소 모달 레이아웃 구조 검증 테스트
 *
 * @description
 * - _modal_cancel_order.json 파셜의 구조적 정합성 검증
 * - 취소 사유 Select 드롭다운 7개 옵션 렌더링 확인
 * - 기타 선택 시 Textarea 표시 조건 확인
 * - 환불 우선순위 라디오 조건부 렌더링 확인
 * - 환불 예정금액 섹션 구조 확인
 * - 복원 쿠폰 iteration 구조 확인
 * - 배송비 상세 iteration 구조 확인
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import modalCancelOrder from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_cancel_order.json';

// ========== 헬퍼 함수 ==========

/**
 * JSON 트리에서 조건에 맞는 모든 노드를 재귀적으로 수집합니다.
 */
function findAllNodes(node: any, predicate: (n: any) => boolean): any[] {
    const results: any[] = [];
    if (!node || typeof node !== 'object') return results;
    if (predicate(node)) results.push(node);
    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            results.push(...findAllNodes(child, predicate));
        }
    }
    return results;
}

/**
 * JSON 트리에서 조건에 맞는 첫 번째 노드를 찾습니다.
 */
function findNode(node: any, predicate: (n: any) => boolean): any | undefined {
    if (!node || typeof node !== 'object') return undefined;
    if (predicate(node)) return node;
    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            const found = findNode(child, predicate);
            if (found) return found;
        }
    }
    return undefined;
}

// ========== 테스트 ==========

describe('관리자 취소 모달 — 기본 구조', () => {
    it('모달이 올바른 id, type, name을 가져야 함', () => {
        expect(modalCancelOrder.id).toBe('modal_cancel_order');
        expect(modalCancelOrder.type).toBe('composite');
        expect(modalCancelOrder.name).toBe('Modal');
    });

    it('모달 제목이 다국어 키를 사용해야 함', () => {
        expect(modalCancelOrder.props.title).toContain('$t:');
        expect(modalCancelOrder.props.title).toContain('cancel.title');
    });

    it('상태 초기화 + 환불 예상은 모달 partial 의 onMount 가 아닌 부모 핸들러가 담당한다', () => {
        // 모달이 isolated scope partial 로 분리되어 onMount 패턴이 더 이상 동작하지
        // 않음 → 모달 열기 직전 buildOrderDetailBulkConfirmData 핸들러에서
        // setLocal + estimateRefundAmount 호출로 이전됨 (orderDetailHandlers 참조)
        const contentDiv = modalCancelOrder.children[0];
        expect(contentDiv.lifecycle?.onMount).toBeUndefined();
    });
});

describe('관리자 취소 모달 — 취소사유 Select 동적 옵션 (refundReasons 데이터소스)', () => {
    it('취소 사유 Select가 존재해야 함', () => {
        const select = findNode(modalCancelOrder, (n) =>
            n.name === 'Select' && n.props?.value?.includes('cancelReason'),
        );
        expect(select).toBeDefined();
    });

    it('Select 의 옵션이 refundReasons 데이터소스 iteration 으로 동적 렌더링된다', () => {
        // 7종 hardcoded enum (order_mistake, changed_mind, ... etc) → refundReasons
        // 데이터소스 + reason.code/localized_name iteration 으로 변경됨
        const select = findNode(modalCancelOrder, (n) =>
            n.name === 'Select' && n.props?.value?.includes('cancelReason'),
        );
        const iterationOption = findNode(select, (n: any) =>
            n.name === 'Option' && typeof n.iteration?.source === 'string'
                && n.iteration.source.includes('refundReasons'),
        );
        expect(iterationOption).toBeDefined();
        expect(iterationOption.iteration.item_var).toBe('reason');
        expect(iterationOption.props?.value).toContain('reason.code');
        expect(iterationOption.text).toContain('reason.localized_name');

        // placeholder 옵션은 정적으로 유지됨
        const placeholderOption = findNode(select, (n: any) =>
            n.name === 'Option' && n.props?.value === '',
        );
        expect(placeholderOption).toBeDefined();
    });

    it('Select의 change 액션이 setState로 cancelReason을 업데이트해야 함', () => {
        const select = findNode(modalCancelOrder, (n) =>
            n.name === 'Select' && n.props?.value?.includes('cancelReason'),
        );
        const changeAction = select.actions?.find((a: any) => a.type === 'change');
        expect(changeAction).toBeDefined();
        expect(changeAction.handler).toBe('setState');
        expect(changeAction.params.target).toBe('local');
        expect(changeAction.params.cancelReason).toContain('$event.target.value');
    });
});

describe('관리자 취소 모달 — 기타 선택 시 Textarea 표시', () => {
    it('Textarea가 존재하고 if 조건이 cancelReason === "etc"이어야 함', () => {
        const textarea = findNode(modalCancelOrder, (n) => n.name === 'Textarea');
        expect(textarea).toBeDefined();
        expect(textarea.if).toContain('_local.cancelReason');
        expect(textarea.if).toContain("'etc'");
    });

    it('Textarea의 input 액션이 cancelReasonDetail을 업데이트해야 함', () => {
        const textarea = findNode(modalCancelOrder, (n) => n.name === 'Textarea');
        const inputAction = textarea.actions?.find((a: any) => a.type === 'input');
        expect(inputAction).toBeDefined();
        expect(inputAction.handler).toBe('setState');
        expect(inputAction.params.target).toBe('local');
        expect(inputAction.params.cancelReasonDetail).toContain('$event.target.value');
    });

    it('Textarea에 placeholder 다국어 키가 있어야 함', () => {
        const textarea = findNode(modalCancelOrder, (n) => n.name === 'Textarea');
        expect(textarea.props.placeholder).toContain('$t:');
        expect(textarea.props.placeholder).toContain('cancel_reason_detail_placeholder');
    });
});

describe('관리자 취소 모달 — 환불우선순위 라디오 렌더링', () => {
    it('환불 우선순위 섹션이 refund_priority_label 다국어 키와 함께 정의되어 있다', () => {
        // 표시 조건이 refund_points_amount > 0 → 항상 표시 + 우선순위 핸들러 분기로 변경됨
        const json = JSON.stringify(modalCancelOrder);
        expect(json).toContain('refund_priority_label');
    });

    it('pg_first 라디오가 존재해야 함', () => {
        const pgFirstRadio = findNode(modalCancelOrder, (n) =>
            n.name === 'Input' && n.props?.type === 'radio' && n.props?.name === 'refundPriority' && n.props?.value === 'pg_first',
        );
        expect(pgFirstRadio).toBeDefined();
    });

    it('points_first 라디오가 존재해야 함', () => {
        const pointsFirstRadio = findNode(modalCancelOrder, (n) =>
            n.name === 'Input' && n.props?.type === 'radio' && n.props?.name === 'refundPriority' && n.props?.value === 'points_first',
        );
        expect(pointsFirstRadio).toBeDefined();
    });

    it('환불 우선순위 라디오의 change 핸들러가 changeRefundPriority를 호출해야 함', () => {
        const priorityRadios = findAllNodes(modalCancelOrder, (n) =>
            n.name === 'Input' && n.props?.type === 'radio' && n.props?.name === 'refundPriority',
        );
        expect(priorityRadios).toHaveLength(2);

        for (const radio of priorityRadios) {
            const changeAction = radio.actions?.find((a: any) => a.type === 'change');
            expect(changeAction).toBeDefined();
            expect(changeAction.handler).toBe('sirsoft-ecommerce.changeRefundPriority');
            expect(changeAction.params.priority).toBe(radio.props.value);
        }
    });

    it('환불 우선순위 라벨에 다국어 키가 사용되어야 함', () => {
        const json = JSON.stringify(modalCancelOrder);
        expect(json).toContain('refund_priority_label');
        expect(json).toContain('refund_priority_pg_first');
        expect(json).toContain('refund_priority_points_first');
    });
});

describe('관리자 취소 모달 — 환불예정금액 섹션 렌더링', () => {
    it('환불 비교표 제목이 comparison_title 다국어 키를 사용해야 함', () => {
        // refund_estimate_title 단일 표 → 전/후 비교표 (comparison_title)
        const json = JSON.stringify(modalCancelOrder);
        expect(json).toContain('cancel.comparison_title');
    });

    it('로딩 상태 영역이 refundLoading 조건으로 표시되어야 함', () => {
        const loadingDiv = findNode(modalCancelOrder, (n) =>
            n.if === '{{_local.refundLoading}}' && n.name === 'Div',
        );
        expect(loadingDiv).toBeDefined();

        // 로딩 스피너 아이콘 확인
        const spinner = findNode(loadingDiv, (n) => n.name === 'Icon' && n.props?.name === 'spinner');
        expect(spinner).toBeDefined();
        expect(spinner.props.className).toContain('animate-spin');
    });

    it('환불 상세 영역이 refundEstimate 존재 && !refundLoading 조건으로 표시되어야 함', () => {
        const detailDiv = findNode(modalCancelOrder, (n) =>
            n.if && n.if.includes('_local.refundEstimate') && n.if.includes('!_local.refundLoading'),
        );
        expect(detailDiv).toBeDefined();
    });

    it('상품 환불액(subtotal_amount) + 최종 환불 예정액(refund_total) 라벨 키가 존재해야 함', () => {
        // refund_product_amount → subtotal_amount 로 라벨 정규화 (mypageOrders 와 동일)
        const json = JSON.stringify(modalCancelOrder);
        expect(json).toContain('cancel.subtotal_amount');
        expect(json).toContain('cancel.refund_total');
    });

    it('배송비가 base_shipping/extra_shipping 항목으로 분리 표시된다', () => {
        // shipping_difference 단일 행 → base_shipping/extra_shipping 분리
        const json = JSON.stringify(modalCancelOrder);
        expect(json).toContain('cancel.base_shipping');
        expect(json).toContain('cancel.extra_shipping');
    });

    it('할인 항목이 product/order/code 쿠폰별로 분리 표시된다', () => {
        // discount_difference 단일 행 → 상품/주문/코드 쿠폰 별도 라인
        const json = JSON.stringify(modalCancelOrder);
        expect(json).toContain('cancel.product_coupon_discount');
        expect(json).toContain('cancel.order_coupon_discount');
        expect(json).toContain('cancel.code_discount');
    });

    it('마일리지 사용액 라벨(points_used)이 비교표 항목으로 존재한다', () => {
        // 단독 환불 행 → 비교표의 points_used 라벨 (전/후 사용액 표시)
        const json = JSON.stringify(modalCancelOrder);
        expect(json).toContain('cancel.points_used');
    });
});

describe('관리자 취소 모달 — 복원쿠폰 섹션 렌더링', () => {
    it('복원 쿠폰 섹션이 restored_coupons.length > 0 조건으로 표시되어야 함', () => {
        const couponSection = findNode(modalCancelOrder, (n) =>
            n.if && n.if.includes('restored_coupons') && n.if.includes('length > 0'),
        );
        expect(couponSection).toBeDefined();
    });

    it('복원 쿠폰 iteration이 올바른 source와 item_var를 가져야 함', () => {
        const couponIteration = findNode(modalCancelOrder, (n) =>
            n.iteration?.source?.includes('restored_coupons'),
        );
        expect(couponIteration).toBeDefined();
        expect(couponIteration.iteration.item_var).toBe('coupon');
        expect(couponIteration.iteration.index_var).toBe('couponIdx');
    });

    it('복원 쿠폰 항목에 쿠폰명과 할인금액 바인딩이 있어야 함', () => {
        const couponIteration = findNode(modalCancelOrder, (n) =>
            n.iteration?.source?.includes('restored_coupons'),
        );
        const json = JSON.stringify(couponIteration);
        expect(json).toContain('coupon.coupon_name');
        expect(json).toContain('coupon.discount_amount');
    });

    it('복원 쿠폰 라벨과 안내 문구에 다국어 키가 사용되어야 함', () => {
        const json = JSON.stringify(modalCancelOrder);
        expect(json).toContain('restored_coupons_label');
        expect(json).toContain('restored_coupons_notice');
    });
});

describe('관리자 취소 모달 — 배송비 표시 (전/후 비교표 통합)', () => {
    // shipping_details iteration 섹션이 별도 카드 → 전/후 비교표의 base_shipping
    // / extra_shipping 라벨로 통합됨
    it('배송비 상세 iteration 섹션이 더 이상 존재하지 않는다 (비교표로 통합)', () => {
        const shippingIteration = findNode(modalCancelOrder, (n) =>
            n.iteration?.source?.includes('shipping_details'),
        );
        expect(shippingIteration).toBeUndefined();
    });

    it('배송비 라벨이 비교표 안에 base_shipping/extra_shipping 으로 표시된다', () => {
        const json = JSON.stringify(modalCancelOrder);
        expect(json).toContain('cancel.base_shipping');
        expect(json).toContain('cancel.extra_shipping');
    });
});

describe('관리자 취소 모달 — validation 에러 UI', () => {
    it('cancelValidationErrors 는 부모 핸들러(buildOrderDetailBulkConfirmData)에서 초기화된다', () => {
        // 모달 partial 의 lifecycle.onMount → 부모의 buildOrderDetailBulkConfirmData
        // 핸들러로 이전됨. 본 partial 자체에는 onMount 가 없음
        const contentDiv = modalCancelOrder.children[0];
        expect(contentDiv.lifecycle?.onMount).toBeUndefined();
    });

    it('validation 에러 요약 블록이 cancelValidationErrors 조건으로 표시되어야 함', () => {
        const errorSummary = findNode(modalCancelOrder, (n) =>
            n.comment === 'Validation 에러 요약' && n.if?.includes('cancelValidationErrors'),
        );
        expect(errorSummary).toBeDefined();
    });

    it('validation 에러 요약에 triangle-exclamation 아이콘과 다국어 제목이 있어야 함', () => {
        const errorSummary = findNode(modalCancelOrder, (n) =>
            n.comment === 'Validation 에러 요약',
        );
        const icon = findNode(errorSummary, (n) => n.name === 'Icon' && n.props?.name === 'triangle-exclamation');
        expect(icon).toBeDefined();

        const json = JSON.stringify(errorSummary);
        expect(json).toContain('validation_error_title');
    });

    it('validation 에러 목록이 flatMap iteration으로 렌더링되어야 함', () => {
        const errorSummary = findNode(modalCancelOrder, (n) =>
            n.comment === 'Validation 에러 요약',
        );
        const li = findNode(errorSummary, (n) => n.name === 'Li' && n.iteration);
        expect(li).toBeDefined();
        expect(li.iteration.source).toContain('flatMap');
        expect(li.iteration.item_var).toBe('errMsg');
    });

    it('취소 사유 Select에 cancelValidationErrors?.reason 기반 적색 테두리가 적용되어야 함', () => {
        const select = findNode(modalCancelOrder, (n) =>
            n.name === 'Select' && n.props?.value?.includes('cancelReason'),
        );
        expect(select.props.className).toContain('cancelValidationErrors?.reason');
        expect(select.props.className).toContain('border-red-500');
    });

    it('취소 사유 필드 에러 Span이 reason 에러 조건으로 표시되어야 함', () => {
        const reasonError = findNode(modalCancelOrder, (n) =>
            n.comment === '취소 사유 필드 에러' && n.if?.includes('cancelValidationErrors?.reason'),
        );
        expect(reasonError).toBeDefined();
        expect(reasonError.props.className).toContain('text-red-500');
    });

    it('Textarea에 cancelValidationErrors?.reason_detail 기반 적색 테두리가 적용되어야 함', () => {
        const textarea = findNode(modalCancelOrder, (n) => n.name === 'Textarea');
        expect(textarea.props.className).toContain('cancelValidationErrors?.reason_detail');
        expect(textarea.props.className).toContain('border-red-500');
    });

    it('상세 사유 필드 에러 Span이 reason_detail 에러 조건으로 표시되어야 함', () => {
        const detailError = findNode(modalCancelOrder, (n) =>
            n.comment === '상세 사유 필드 에러' && n.if?.includes('cancelValidationErrors?.reason_detail'),
        );
        expect(detailError).toBeDefined();
        expect(detailError.props.className).toContain('text-red-500');
    });

    it('Select change 시 cancelValidationErrors가 null로 초기화되어야 함', () => {
        const select = findNode(modalCancelOrder, (n) =>
            n.name === 'Select' && n.props?.value?.includes('cancelReason'),
        );
        const changeAction = select.actions?.find((a: any) => a.type === 'change');
        expect(changeAction.params).toHaveProperty('cancelValidationErrors');
        expect(changeAction.params.cancelValidationErrors).toBeNull();
    });

    it('Textarea input 시 cancelValidationErrors가 null로 초기화되어야 함', () => {
        const textarea = findNode(modalCancelOrder, (n) => n.name === 'Textarea');
        const inputAction = textarea.actions?.find((a: any) => a.type === 'input');
        expect(inputAction.params).toHaveProperty('cancelValidationErrors');
        expect(inputAction.params.cancelValidationErrors).toBeNull();
    });
});

describe('관리자 취소 모달 — 액션 버튼', () => {
    it('닫기 버튼이 sequence(상태 정리 + closeModal) 패턴을 사용해야 함', () => {
        // closeModal 단독 → sequence(setState 정리 + closeModal) 로 변경
        // (모달 partial 분리 후 부모 _local 정리가 필요함)
        const footerDiv = modalCancelOrder.children[1];
        const closeBtn = footerDiv.children[0];
        const clickAction = closeBtn.actions[0];
        expect(clickAction.type).toBe('click');
        expect(clickAction.handler).toBe('sequence');
        const closeAction = (clickAction.params?.actions ?? clickAction.actions ?? []).find(
            (a: any) => a.handler === 'closeModal',
        );
        expect(closeAction).toBeDefined();
    });

    it('취소 실행 버튼이 executeCancelOrder 핸들러를 호출해야 함', () => {
        const footerDiv = modalCancelOrder.children[1];
        const cancelBtn = footerDiv.children[1];
        const clickAction = cancelBtn.actions[0];
        expect(clickAction.type).toBe('click');
        expect(clickAction.handler).toBe('sirsoft-ecommerce.executeCancelOrder');
        expect(clickAction.params).toHaveProperty('orderId');
        expect(clickAction.params).toHaveProperty('cancelItems');
        expect(clickAction.params).toHaveProperty('cancelReason');
        expect(clickAction.params).toHaveProperty('cancelReasonDetail');
        expect(clickAction.params).toHaveProperty('cancelPg');
        expect(clickAction.params).toHaveProperty('refundPriority');
    });

    it('취소 실행 버튼이 isCancelling 또는 refundLoading 중 비활성화되어야 함', () => {
        const footerDiv = modalCancelOrder.children[1];
        const cancelBtn = footerDiv.children[1];
        expect(cancelBtn.props.disabled).toContain('isCancelling');
        expect(cancelBtn.props.disabled).toContain('refundLoading');
    });

    it('취소 실행 버튼에 로딩 스피너가 있어야 함', () => {
        const footerDiv = modalCancelOrder.children[1];
        const cancelBtn = footerDiv.children[1];
        const spinner = findNode(cancelBtn, (n) => n.name === 'Icon' && n.props?.name === 'spinner');
        expect(spinner).toBeDefined();
        expect(spinner.if).toContain('isCancelling');
        expect(spinner.props.className).toContain('animate-spin');
    });
});
