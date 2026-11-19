// e2e:allow PG 결제창 진입 결과 판정 순수 함수 — 실제 결제창 실패는 Chrome MCP 모바일 실측(#855 T4-c)으로 확인한다.
/**
 * 클레임 추가결제의 PG 결제창 진입 결과 판정 (#855)
 *
 * 신청서 핸들러는 결제 플러그인 핸들러를 `G7Core.dispatch` 로 부르고, 디스패처는 핸들러 오류를 던지지 않고
 * `{ success: false, error: ActionError }` 로 돌려준다. 사용자 문구는 감싼 오류가 아니라 `originalError` 에 있다.
 *
 * 시나리오: modules/_bundled/sirsoft-ecommerce/tests/scenarios/user-claim-list-detail-edit.yaml
 *
 * @vitest-environment jsdom
 */
import { describe, expect, it } from 'vitest';
import { claimPgDispatchAction, claimPgDispatchFailureMessage } from '../../utils/claimPgDispatch';

describe('claimPgDispatchAction', () => {
    // @scenario claim_type=return, screen=detail, entry=none
    // @effects claim_pay_pg_entry_failure_releases_the_button
    it('결제 플러그인 핸들러에 결제창 파라미터를 넘기고, 디스패처의 기본 오류 토스트는 suppress 로 막는다', () => {
        expect(claimPgDispatchAction('x.requestPayment', { order_number: 'CP-1' })).toEqual({
            handler: 'x.requestPayment',
            params: { pgPaymentData: { order_number: 'CP-1' } },
            errorHandling: { default: { handler: 'suppress' } },
        });
    });
});

describe('[case:state-advanced-dispatch-failure-is-a-result] claimPgDispatchFailureMessage', () => {
    // @scenario claim_type=return, screen=detail, entry=none
    // @effects claim_pay_pg_entry_failure_releases_the_button
    it('성공·결과 없음은 null 이다 (결제창이 열렸다)', () => {
        expect(claimPgDispatchFailureMessage({ success: true }, '대체')).toBeNull();
        expect(claimPgDispatchFailureMessage(undefined, '대체')).toBeNull();
        expect(claimPgDispatchFailureMessage(null, '대체')).toBeNull();
    });

    // @scenario claim_type=return, screen=detail, entry=none
    // @effects claim_pay_pg_entry_failure_releases_the_button
    it('실패면 원래 오류의 문구를 쓰고, 감싼 오류의 내부 문구는 쓰지 않는다', () => {
        expect(claimPgDispatchFailureMessage({
            success: false,
            error: { message: 'Failed to execute action: x.requestPayment', originalError: { message: '승인키를 받지 못했습니다.' } },
        }, '대체')).toBe('승인키를 받지 못했습니다.');
    });

    // @scenario claim_type=return, screen=detail, entry=none
    // @effects claim_pay_pg_entry_failure_releases_the_button
    it('실패인데 원래 문구가 비었거나 없으면 대체 문구다', () => {
        expect(claimPgDispatchFailureMessage({ success: false, error: { message: 'Failed', originalError: { message: '  ' } } }, '대체')).toBe('대체');
        expect(claimPgDispatchFailureMessage({ success: false, error: { message: 'Failed' } }, '대체')).toBe('대체');
        expect(claimPgDispatchFailureMessage({ success: false }, '대체')).toBe('대체');
    });
});
