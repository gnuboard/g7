/**
 * @file cartOptionChangeErrors.test.tsx
 * @description 장바구니 옵션 변경 실패 시 오류 칸(optionErrors)에 담기는 값.
 *
 * 서버의 도메인 거절(재고 부족·판매 중지 등)은 errors 에 `code`·`message`·`unavailable_items`(객체 배열)·`has_*`(참거짓)를
 * 함께 준다. 이걸 통째로 필드 오류 묶음처럼 펼치면 「cart_unavailable」 같은 내부 코드가 목록에 보이고, 객체 항목을
 * 그리다 「[Li] 데이터를 표시할 수 없습니다」로 깨졌다. 도메인 거절은 사유 문장 하나만, 필드 검증 오류는 종전대로 담는다.
 */
import { describe, it, expect } from 'vitest';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import modalJson from '../../layouts/partials/shop/_modal_cart_option_change.json';

const engine = new DataBindingEngine();

/**
 * 레이아웃 트리에서 조건에 맞는 노드를 모두 찾습니다.
 *
 * @param node 탐색 시작 노드
 * @param pred 판정 함수
 * @param acc 누적 배열
 * @returns 찾은 노드 배열
 */
function findAll(node: any, pred: (n: any) => boolean, acc: any[] = []): any[] {
    if (!node || typeof node !== 'object') return acc;
    if (pred(node)) acc.push(node);
    for (const v of Object.values(node)) {
        if (Array.isArray(v)) v.forEach((c) => findAll(c, pred, acc));
        else findAll(v, pred, acc);
    }
    return acc;
}

const failSetter = findAll(modalJson, (n) => n?.handler === 'setState' && typeof n?.params?.optionErrors === 'string' && n.params.optionErrors.includes('error'))[0];
const evalErrors = (error: Record<string, unknown>) =>
    engine.evaluateExpression(String(failSetter.params.optionErrors).slice(2, -2), { error });

describe('장바구니 옵션 변경 실패 오류 칸', () => {
    /**
     * @scenario rejection=domain_with_reason
     * @effects domain_rejection_shows_reason_only
     */
    it('도메인 거절(code 있음)은 사유 문장 하나만 일반 오류로 담는다', () => {
        expect(failSetter, '실패 시 optionErrors 를 채우는 액션을 찾지 못했다').toBeDefined();
        const result = evalErrors({
            message: '구매할 수 없는 상품이 있습니다.',
            errors: {
                code: 'cart_unavailable',
                message: 'e130s4 상품A 기본: 재고가 부족합니다. (요청: 2개, 가용: 1개)',
                unavailable_items: [{ product_id: 133, reason: 'stock' }],
                has_stock_issue: true,
                has_status_issue: false,
            },
        });
        expect(result).toEqual({ _general: 'e130s4 상품A 기본: 재고가 부족합니다. (요청: 2개, 가용: 1개)' });
    });

    /**
     * @scenario rejection=domain_without_reason
     * @effects domain_rejection_falls_back_to_message
     */
    it('사유 문장이 없는 도메인 거절은 최상위 메시지를 쓴다', () => {
        expect(evalErrors({ message: '상품 옵션을 찾을 수 없습니다.', errors: { code: 'option_not_found' } }))
            .toEqual({ _general: '상품 옵션을 찾을 수 없습니다.' });
    });

    /**
     * @scenario rejection=field_validation
     * @effects field_errors_kept_per_field
     */
    it('필드 검증 오류는 종전대로 필드별로 담는다', () => {
        expect(evalErrors({ message: '입력값을 확인하세요.', errors: { quantity: ['수량은 1 이상이어야 합니다.'] } }))
            .toEqual({ quantity: ['수량은 1 이상이어야 합니다.'] });
    });
});
