/**
 * @file mobileReturn.test.ts
 * @description 모바일 페이지 전환 인증 복귀 처리 검증
 *
 * 복귀 시 입력값이 되살아나는지, 인증 토큰은 주소에 유지되고(레이아웃이 그 값을 요청에 실어
 * 보내는 표준 계약) 실패 코드만 제거되는지, 보관 기한이 지난 값은 되살아나지 않는지 확인한다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { handleMobileReturn, FORM_STASH_KEY } from '../index';

let dispatched: Array<Record<string, unknown>> = [];

function setupG7Core(): void {
    dispatched = [];
    (window as any).G7Core = {
        state: { get: () => ({}), getGlobal: () => ({}), set: vi.fn() },
        dispatch: vi.fn(async (action: Record<string, unknown>) => {
            dispatched.push(action);
            return true;
        }),
        identity: { markDomainNoticeShown: vi.fn() },
    };
}

/** 복귀 주소를 설정한다 (jsdom 은 replaceState 로 주소 변경을 허용한다). */
function setLocation(search: string): void {
    window.history.replaceState(null, '', '/register'.concat(search));
}

function stashFields(fields: Record<string, string>, stashedAt = Date.now()): void {
    window.sessionStorage.setItem(FORM_STASH_KEY, JSON.stringify({ fields, stashed_at: stashedAt }));
}

describe('모바일 인증 복귀 처리', () => {
    beforeEach(() => {
        setupG7Core();
        window.sessionStorage.clear();
        document.body.innerHTML = '';
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.runOnlyPendingTimers();
        vi.useRealTimers();
        vi.restoreAllMocks();
        setLocation('');
    });

    /**
     * @scenario environment=mobile,outcome=verified
     * @effects mobile_return_restores_form_values
     */
    it('인증 완료로 돌아오면 보관한 입력값을 되살린다', () => {
        document.body.innerHTML = '<form><input name="email" value="" /><input name="nickname" value="" /></form>';
        stashFields({ email: 'guest@example.test', nickname: '길동' });
        setLocation('?verification_token=tok-abc');

        handleMobileReturn();
        vi.advanceTimersByTime(300);

        expect(document.querySelector<HTMLInputElement>('[name="email"]')?.value).toBe('guest@example.test');
        expect(document.querySelector<HTMLInputElement>('[name="nickname"]')?.value).toBe('길동');
    });

    /**
     * @scenario environment=mobile,outcome=verified
     * @effects mobile_return_keeps_verification_token_in_query
     */
    it('인증 토큰은 주소에 그대로 유지된다', () => {
        stashFields({ email: 'guest@example.test' });
        setLocation('?verification_token=tok-abc');

        handleMobileReturn();

        expect(window.location.search).toContain('verification_token=tok-abc');

        const toast = dispatched.find((action) => action.handler === 'toast');
        expect((toast?.params as any).type).toBe('success');
        expect((toast?.params as any).message).toContain('modal.mobile_return_success');
    });

    /**
     * @scenario environment=mobile,outcome=failed
     * @effects mobile_return_shows_failure_notice_and_strips_error_param
     */
    it('실패로 돌아오면 사유를 안내하고 실패 코드는 주소에서 제거한다', () => {
        stashFields({ email: 'guest@example.test' });
        setLocation('?identity_error=NOT_ADULT');

        handleMobileReturn();

        expect(window.location.search).not.toContain('identity_error');

        const toast = dispatched.find((action) => action.handler === 'toast');
        expect((toast?.params as any).type).toBe('error');
        expect((toast?.params as any).message).toContain('errors.not_adult');
        // 부가 목적 미달은 코어 가드 안내와 중복되지 않도록 신호를 남긴다.
        expect((window as any).G7Core.identity.markDomainNoticeShown).toHaveBeenCalled();
    });

    /**
     * @scenario environment=mobile,outcome=failed
     * @effects mobile_return_shows_failure_notice_and_strips_error_param
     */
    it('일반 인증 실패는 코어 가드 안내를 억제하지 않는다', () => {
        setLocation('?identity_error=REMOTE_CALL_FAILED');

        handleMobileReturn();

        expect((window as any).G7Core.identity.markDomainNoticeShown).not.toHaveBeenCalled();
    });

    /**
     * @scenario environment=desktop,outcome=verified
     * @effects mobile_return_restores_form_values
     */
    it('인증 결과가 없는 일반 진입에서는 아무 것도 하지 않는다', () => {
        stashFields({ email: 'guest@example.test' });
        setLocation('');

        handleMobileReturn();

        expect(dispatched).toHaveLength(0);
        // 보관값은 다음 복귀를 위해 남아 있어야 한다.
        expect(window.sessionStorage.getItem(FORM_STASH_KEY)).not.toBeNull();
    });

    /**
     * @scenario environment=mobile,outcome=verified
     * @effects mobile_return_restores_form_values
     */
    it('보관 기한이 지난 입력값은 되살리지 않는다', () => {
        document.body.innerHTML = '<form><input name="email" value="" /></form>';
        stashFields({ email: 'stale@example.test' }, Date.now() - 11 * 60 * 1000);
        setLocation('?verification_token=tok-abc');

        handleMobileReturn();
        vi.advanceTimersByTime(300);

        expect(document.querySelector<HTMLInputElement>('[name="email"]')?.value).toBe('');
    });

    /**
     * @scenario environment=mobile,outcome=verified
     * @effects mobile_return_restores_form_values
     */
    it('보관값은 1회만 사용되고 즉시 폐기된다', () => {
        stashFields({ email: 'guest@example.test' });
        setLocation('?verification_token=tok-abc');

        handleMobileReturn();

        expect(window.sessionStorage.getItem(FORM_STASH_KEY)).toBeNull();
    });
});
