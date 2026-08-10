import { describe, it, expect } from 'vitest';
import { resolveActionFailureMessage } from '../ActionDispatcher';

/**
 * 액션 실패 문구가 **운영자에게 의미 있는 말**인지 고정한다.
 *
 * 네트워크 실패(`TypeError: Failed to fetch`)는 응답 자체가 없으므로 서버 메시지가 없다.
 * 그때 내부 식별자(`Failed to execute action: apiCall`)를 그대로 토스트에 띄우면
 * 운영자는 무슨 일이 일어났는지 알 수 없고 다국어도 적용되지 않는다.
 *
 * 확장 업데이트 모달에서 통신이 끊겼을 때 실제로 이 문구가 노출되는 것을 브라우저 실측으로
 * 확인했다(#492 검색 인덱스 실측 세션).
 *
 * @since engine-v1.54.6
 */
describe('resolveActionFailureMessage', () => {
    it('네트워크 실패에는 다국어 키를 돌려준다', () => {
        const message = resolveActionFailureMessage(
            new TypeError('Failed to fetch'),
            'apiCall',
            undefined
        );

        expect(message).toBe('$t:core.errors.network_request_failed');
    });

    it('요청 취소도 네트워크 계열로 안내한다', () => {
        const abort = new Error('The user aborted a request.');
        abort.name = 'AbortError';

        expect(resolveActionFailureMessage(abort, 'apiCall', undefined)).toBe(
            '$t:core.errors.network_request_failed'
        );
    });

    it('서버가 메시지를 주면 그대로 쓴다', () => {
        expect(
            resolveActionFailureMessage(new Error('boom'), 'apiCall', '유효하지 않은 레이아웃 전략입니다.')
        ).toBe('유효하지 않은 레이아웃 전략입니다.');
    });

    it('서버 메시지도 네트워크 실패도 아니면 기존 식별 문구를 유지한다', () => {
        expect(resolveActionFailureMessage(new Error('boom'), 'setState', undefined)).toBe(
            'Failed to execute action: setState'
        );
    });

    it('ActionError 가 이미 들고 있던 고유 메시지는 덮어쓰지 않는다', () => {
        expect(
            resolveActionFailureMessage(
                new Error('boom'),
                'ecommerce.initPreferredCurrency',
                undefined,
                'Unknown action handler: ecommerce.initPreferredCurrency'
            )
        ).toBe('Unknown action handler: ecommerce.initPreferredCurrency');
    });

    it('네트워크 실패는 고유 메시지보다 우선한다 (내부 문구가 사용자에게 보이지 않도록)', () => {
        expect(
            resolveActionFailureMessage(
                new TypeError('Failed to fetch'),
                'apiCall',
                undefined,
                'Failed to execute action: apiCall'
            )
        ).toBe('$t:core.errors.network_request_failed');
    });
});
