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

    it('onMount에서 상태 초기화 및 환불 예상 호출이 있어야 함', () => {
        const contentDiv = modalCancelOrder.children[0];
        const onMount = contentDiv.lifecycle?.onMount;
        expect(onMount).toBeDefined();
        expect(onMount).toHaveLength(2);

        // setState 초기화
        const setStateAction = onMount[0];
        expect(setStateAction.handler).toBe('setState');
        expect(setStateAction.params.target).toBe('local');
        expect(setStateAction.params).toHaveProperty('cancelReason');
        expect(setStateAction.params).toHaveProperty('cancelReasonDetail');
        expect(setStateAction.params).toHaveProperty('refundPriority');
        expect(setStateAction.params).toHaveProperty('refundEstimate');
        expect(setStateAction.params).toHaveProperty('isCancelling');

        // estimateRefundAmount 핸들러 호출
        const estimateAction = onMount[1];
        expect(estimateAction.handler).toBe('sirsoft-ecommerce.estimateRefundAmount');
        expect(estimateAction.params).toHaveProperty('orderId');
        expect(estimateAction.params).toHaveProperty('cancelItems');
    });
});

describe('관리자 취소 모달 — 취소사유 Select 드롭다운 7개 옵션', () => {
    const expectedReasons = [
        'order_mistake',
        'changed_mind',
        'reorder_other',
        'delayed_delivery',
        'product_info_different',
        'admin_cancel',
        'etc',
    ];

    it('취소 사유 Select가 존재해야 함', () => {
        const select = findNode(modalCancelOrder, (n) =>
            n.name === 'Select' && n.props?.value?.includes('cancelReason'),
        );
        expect(select).toBeDefined();
    });

    it('Select에 placeholder + 7개 옵션 = 총 8개 Option이 있어야 함', () => {
        const select = findNode(modalCancelOrder, (n) =>
            n.name === 'Select' && n.props?.value?.includes('cancelReason'),
        );
        const options = findAllNodes(select, (n) => n.name === 'Option');
        expect(options).toHaveLength(8); // placeholder + 7 reasons
    });

    it.each(expectedReasons)('취소 사유 "%s" Option이 존재해야 함', (reason) => {
        const select = findNode(modalCancelOrder, (n) =>
            n.name === 'Select' && n.props?.value?.includes('cancelReason'),
        );
        const option = findNode(select, (n) => n.name === 'Option' && n.props?.value === reason);
        expect(option).toBeDefined();
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

    it('각 옵션에 다국어 라벨이 있어야 함', () => {
        for (const reason of expectedReasons) {
            const json = JSON.stringify(modalCancelOrder);
            expect(json).toContain(`cancel.reason.${reason}`);
        }
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
    it('환불 우선순위 섹션이 refund_points_amount > 0 조건으로 표시되어야 함', () => {
        const refundPrioritySection = findNode(modalCancelOrder, (n) =>
            n.if && n.if.includes('refund_points_amount') && n.if.includes('> 0'),
        );
        expect(refundPrioritySection).toBeDefined();
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
    it('환불 예정금액 제목(H4)이 다국어 키를 사용해야 함', () => {
        const h4 = findNode(modalCancelOrder, (n) => n.name === 'H4');
        expect(h4).toBeDefined();
        expect(h4.text).toContain('$t:');
        expect(h4.text).toContain('refund_estimate_title');
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

    it('상품 환불액, 최종 환불 예정액 행이 존재해야 함', () => {
        const json = JSON.stringify(modalCancelOrder);
        expect(json).toContain('refund_product_amount');
        expect(json).toContain('refund_total');
    });

    it('배송비 변동 행이 shipping_difference !== 0 조건으로 표시되어야 함', () => {
        const shippingDiffDiv = findNode(modalCancelOrder, (n) =>
            n.if && n.if.includes('shipping_difference') && n.if.includes('!== 0'),
        );
        expect(shippingDiffDiv).toBeDefined();
    });

    it('할인 조정 행이 discount_difference !== 0 조건으로 표시되어야 함', () => {
        const discountDiffDiv = findNode(modalCancelOrder, (n) =>
            n.if && n.if.includes('discount_difference') && n.if.includes('!== 0'),
        );
        expect(discountDiffDiv).toBeDefined();
    });

    it('마일리지 환불 행이 refund_points_amount > 0 조건으로 표시되어야 함', () => {
        const pointsDiv = findNode(modalCancelOrder, (n) =>
            n.if && n.if.includes('refund_points_amount') && n.if.includes('> 0') && !n.children?.some((c: any) => c.name === 'Input'),
        );
        expect(pointsDiv).toBeDefined();
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

describe('관리자 취소 모달 — 배송비상세 섹션 렌더링', () => {
    it('배송비 상세 섹션이 shipping_details.length > 0 조건으로 표시되어야 함', () => {
        const shippingSection = findNode(modalCancelOrder, (n) =>
            n.if && n.if.includes('shipping_details') && n.if.includes('length > 0'),
        );
        expect(shippingSection).toBeDefined();
    });

    it('배송비 상세 iteration이 올바른 source와 item_var를 가져야 함', () => {
        const shippingIteration = findNode(modalCancelOrder, (n) =>
            n.iteration?.source?.includes('shipping_details'),
        );
        expect(shippingIteration).toBeDefined();
        expect(shippingIteration.iteration.item_var).toBe('shippingDetail');
        expect(shippingIteration.iteration.index_var).toBe('shippingIdx');
    });

    it('기본 배송비 행이 base_difference !== 0 조건으로 표시되어야 함', () => {
        const baseDiffDiv = findNode(modalCancelOrder, (n) =>
            n.if && n.if.includes('base_difference') && n.if.includes('!== 0'),
        );
        expect(baseDiffDiv).toBeDefined();
    });

    it('추가 배송비(도서산간) 행이 extra_difference !== 0 조건으로 표시되어야 함', () => {
        const extraDiffDiv = findNode(modalCancelOrder, (n) =>
            n.if && n.if.includes('extra_difference') && n.if.includes('!== 0'),
        );
        expect(extraDiffDiv).toBeDefined();
    });

    it('배송비 상세 항목에 policy_name 바인딩이 있어야 함', () => {
        const shippingIteration = findNode(modalCancelOrder, (n) =>
            n.iteration?.source?.includes('shipping_details'),
        );
        const json = JSON.stringify(shippingIteration);
        expect(json).toContain('shippingDetail.policy_name');
        expect(json).toContain('shippingDetail.base_difference');
        expect(json).toContain('shippingDetail.extra_difference');
    });

    it('배송비 다국어 키가 기본/추가 구분되어야 함', () => {
        const json = JSON.stringify(modalCancelOrder);
        expect(json).toContain('refund_shipping_base');
        expect(json).toContain('refund_shipping_extra');
    });
});

describe('관리자 취소 모달 — validation 에러 UI', () => {
    it('onMount에서 cancelValidationErrors가 null로 초기화되어야 함', () => {
        const contentDiv = modalCancelOrder.children[0];
        const onMount = contentDiv.lifecycle?.onMount;
        const setStateAction = onMount[0];
        expect(setStateAction.params).toHaveProperty('cancelValidationErrors');
        expect(setStateAction.params.cancelValidationErrors).toBeNull();
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
    it('닫기 버튼이 closeModal 핸들러를 호출해야 함', () => {
        const footerDiv = modalCancelOrder.children[1];
        const closeBtn = footerDiv.children[0];
        const clickAction = closeBtn.actions[0];
        expect(clickAction.type).toBe('click');
        expect(clickAction.handler).toBe('closeModal');
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
