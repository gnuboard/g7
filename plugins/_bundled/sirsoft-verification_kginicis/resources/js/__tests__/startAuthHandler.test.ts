/**
 * startAuth handler 단위 테스트.
 *
 * 검증 범위:
 *  - postMessage 수신 → resolveIdentityChallenge + closeModal sequence dispatch (모달 닫힘 결함 fix)
 *  - popup 직접 닫음 → resolveIdentityChallenge dispatch 안 됨, providerInProgress 만 false (재시도 가능)
 *  - popup blocker → toast 발행, providerInProgress 미set
 *  - public_payload 누락 → toast 발행, popup 미진입
 *  - origin 불일치 postMessage 무시
 *  - listener / polling 모든 분기에서 cleanup
 *
 * 외부 부수효과 (window.open / form.submit / postMessage 채널) 는 vi.spy 로 대체.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { startAuthHandler, MODAL_ID } from '../index';

describe('startAuthHandler', () => {
    let dispatchMock: ReturnType<typeof vi.fn>;
    let stateSetMock: ReturnType<typeof vi.fn>;
    let markDomainNoticeShownMock: ReturnType<typeof vi.fn>;
    let popupCloseMock: ReturnType<typeof vi.fn>;
    let popupClosedFlag: boolean;
    let mockPopup: any;
    let originalOpen: typeof window.open;

    const validChallenge = {
        provider_id: 'inicis',
        public_payload: {
            mid: 'INIiasTest',
            mtxid: 'mtx-1',
            authHash: 'hash-1',
            reqSvcCd: '03',
            flgFixedUser: 'N',
            reservedMsg: 'isUseToken=Y',
        },
    };

    beforeEach(() => {
        dispatchMock = vi.fn().mockResolvedValue(undefined);
        stateSetMock = vi.fn();
        markDomainNoticeShownMock = vi.fn();
        popupCloseMock = vi.fn(() => { popupClosedFlag = true; });
        popupClosedFlag = false;
        mockPopup = {
            close: popupCloseMock,
            get closed() { return popupClosedFlag; },
        };

        (window as any).G7Core = {
            dispatch: dispatchMock,
            state: {
                set: stateSetMock,
                getGlobal: () => ({ identityChallenge: validChallenge }),
                get: () => ({ identityChallenge: validChallenge }),
            },
            identity: {
                markDomainNoticeShown: markDomainNoticeShownMock,
            },
        };

        originalOpen = window.open;
        (window as any).open = vi.fn(() => mockPopup);

        vi.useFakeTimers();
    });

    afterEach(() => {
        delete (window as any).G7Core;
        delete (window as any).__SirsoftVerificationKginicis;
        (window as any).open = originalOpen;
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    function lastSequenceDispatch(): any {
        const calls = dispatchMock.mock.calls;
        return calls.find((c) => c[0]?.handler === 'sequence')?.[0];
    }

    function postBridgeMessage(data: Record<string, unknown>): void {
        const ev = new MessageEvent('message', {
            data,
            origin: window.location.origin,
        });
        window.dispatchEvent(ev);
    }

    it('popup 열림 직후 providerInProgress 가 true 로 set 됨', async () => {
        await startAuthHandler();

        expect(stateSetMock).toHaveBeenCalledWith(
            expect.objectContaining({
                identityChallenge: expect.objectContaining({ providerInProgress: true }),
            }),
        );
    });

    it('postMessage(verified) 수신 시 sequence(resolveIdentityChallenge + closeModal) dispatch + providerInProgress=false', async () => {
        await startAuthHandler();

        postBridgeMessage({
            type: 'identity_result',
            verification_token: 'tok-abc',
        });

        const seq = lastSequenceDispatch();
        expect(seq).toBeDefined();
        expect(seq.params.actions).toEqual([
            { handler: 'resolveIdentityChallenge', params: { result: 'verified', token: 'tok-abc' } },
            { handler: 'closeModal', target: MODAL_ID },
        ]);
        expect(stateSetMock).toHaveBeenCalledWith(
            expect.objectContaining({
                identityChallenge: expect.objectContaining({ providerInProgress: false }),
            }),
        );
        expect(popupCloseMock).toHaveBeenCalled();
    });

    it('postMessage(failed) 수신 시 sequence(resolveIdentityChallenge=failed + closeModal) dispatch', async () => {
        await startAuthHandler();

        postBridgeMessage({
            type: 'identity_result',
            identity_error: 'PROVIDER_ERROR',
        });

        const seq = lastSequenceDispatch();
        expect(seq.params.actions[0]).toEqual({
            handler: 'resolveIdentityChallenge',
            params: { result: 'failed', failureCode: 'PROVIDER_ERROR' },
        });
        expect(seq.params.actions[1]).toEqual({ handler: 'closeModal', target: MODAL_ID });
    });

    // @scenario purpose=adult,is_adult=false
    // @effects verify_failure_dispatches_error_toast_with_i18n_key
    it('postMessage(failed=NOT_ADULT) 수신 시 안내 toast 가 $t: 다국어 키로 발행됨', async () => {
        await startAuthHandler();

        postBridgeMessage({
            type: 'identity_result',
            identity_error: 'NOT_ADULT',
        });

        // 미성년자 차단 시 사용자 안내 toast 가 떠야 한다.
        const toastCall = dispatchMock.mock.calls.find((c) => c[0]?.handler === 'toast');
        expect(toastCall, 'verify 실패 시 안내 toast 가 발행되어야 한다').toBeDefined();
        expect(toastCall?.[0].params.type).toBe('error');
        // 다국어 필수 — 하드코딩 금지, $t: 키로 전달되어 코어가 번역
        expect(String(toastCall?.[0].params.message)).toMatch(/^\$t:sirsoft-verification_kginicis\.errors\.not_adult$/);
    });

    // @scenario purpose=adult,is_adult=false
    // @effects verify_failure_dispatches_error_toast_with_i18n_key
    it('postMessage(failed=기타코드) 수신 시 해당 코드의 $t: 안내 toast 발행 (미노출 다건)', async () => {
        await startAuthHandler();

        postBridgeMessage({
            type: 'identity_result',
            identity_error: 'DECRYPT_FAILED',
        });

        const toastCall = dispatchMock.mock.calls.find((c) => c[0]?.handler === 'toast');
        expect(toastCall, 'verify 실패(기타 코드)도 안내 toast 가 발행되어야 한다').toBeDefined();
        expect(toastCall?.[0].params.type).toBe('error');
        expect(String(toastCall?.[0].params.message)).toMatch(/^\$t:sirsoft-verification_kginicis\.errors\./);
    });

    // @scenario identity_core=missing
    // @effects verify_failure_dispatches_error_toast_with_i18n_key
    it('postMessage(failed=INCOMPLETE_IDENTITY) 수신 시 incomplete_identity $t: 안내 toast 발행 (신원 가드)', async () => {
        await startAuthHandler();

        postBridgeMessage({
            type: 'identity_result',
            identity_error: 'INCOMPLETE_IDENTITY',
        });

        const toastCall = dispatchMock.mock.calls.find((c) => c[0]?.handler === 'toast');
        expect(toastCall, '신원 핵심값 부재 차단 시 안내 toast 가 발행되어야 한다').toBeDefined();
        expect(toastCall?.[0].params.type).toBe('error');
        expect(String(toastCall?.[0].params.message)).toMatch(
            /^\$t:sirsoft-verification_kginicis\.errors\.incomplete_identity$/
        );
    });

    // @scenario purpose=adult,is_adult=true
    // @effects verified_result_does_not_dispatch_toast
    it('postMessage(verified) 수신 시 toast 는 발행되지 않음 (성공 경로 회귀 방지)', async () => {
        await startAuthHandler();

        postBridgeMessage({
            type: 'identity_result',
            verification_token: 'tok-ok',
        });

        const toastCall = dispatchMock.mock.calls.find((c) => c[0]?.handler === 'toast');
        expect(toastCall).toBeUndefined();
    });

    // @scenario purpose=adult,is_adult=false
    // @effects supplementary_failure_marks_domain_notice_to_suppress_guard_toast
    it('postMessage(failed=NOT_ADULT) 수신 시 markDomainNoticeShown 호출 (부가목적 미달 → 코어 가드 토스트 억제 신호)', async () => {
        await startAuthHandler();

        postBridgeMessage({
            type: 'identity_result',
            identity_error: 'NOT_ADULT',
        });

        // 성인인증 실패는 고유 사유를 표출하므로 코어 generic 가드 토스트를 억제하도록 신호
        expect(markDomainNoticeShownMock).toHaveBeenCalledOnce();
    });

    // @scenario purpose=adult,is_adult=missing
    // @effects general_identity_failure_does_not_mark_domain_notice
    it('postMessage(failed=INCOMPLETE_IDENTITY) 수신 시 markDomainNoticeShown 미호출 (일반 본인확인 실패 → 가드 토스트 유지)', async () => {
        await startAuthHandler();

        postBridgeMessage({
            type: 'identity_result',
            identity_error: 'INCOMPLETE_IDENTITY',
        });

        // 본인확인 자체 실패는 코어 generic 가드 토스트("본인 확인이 필요합니다")가 유지되어야 함
        expect(markDomainNoticeShownMock).not.toHaveBeenCalled();
    });

    // @scenario purpose=non_adult,is_adult=true
    // @effects general_identity_failure_does_not_mark_domain_notice
    it('postMessage(failed=PROVIDER_ERROR) 수신 시 markDomainNoticeShown 미호출 (일반 실패류 무영향)', async () => {
        await startAuthHandler();

        postBridgeMessage({
            type: 'identity_result',
            identity_error: 'PROVIDER_ERROR',
        });

        expect(markDomainNoticeShownMock).not.toHaveBeenCalled();
    });

    // @scenario purpose=adult,is_adult=true
    // @effects general_identity_failure_does_not_mark_domain_notice
    it('postMessage(verified) 수신 시 markDomainNoticeShown 미호출 (성공 경로 무영향)', async () => {
        await startAuthHandler();

        postBridgeMessage({
            type: 'identity_result',
            verification_token: 'tok-ok',
        });

        expect(markDomainNoticeShownMock).not.toHaveBeenCalled();
    });

    it('postMessage(데이터 없음) 수신 시 cancelled 로 sequence dispatch', async () => {
        await startAuthHandler();

        postBridgeMessage({ type: 'identity_result' });

        const seq = lastSequenceDispatch();
        expect(seq.params.actions[0]).toEqual({
            handler: 'resolveIdentityChallenge',
            params: { result: 'cancelled' },
        });
    });

    it('window.location.origin 외 origin 의 postMessage 는 무시됨', async () => {
        await startAuthHandler();

        const ev = new MessageEvent('message', {
            data: { type: 'identity_result', verification_token: 'evil' },
            origin: 'https://evil.example.com',
        });
        window.dispatchEvent(ev);

        // sequence dispatch 가 일어나지 않았어야 함
        expect(lastSequenceDispatch()).toBeUndefined();
    });

    it('popup.closed 감지 시 resolveIdentityChallenge 미dispatch + providerInProgress 만 false', async () => {
        await startAuthHandler();

        // 팝업 직접 닫음 시뮬레이션
        popupClosedFlag = true;
        // polling tick 진행
        vi.advanceTimersByTime(1000);

        // sequence dispatch 가 없어야 함 — 모달 유지로 재시도 가능
        expect(lastSequenceDispatch()).toBeUndefined();
        // providerInProgress=false 로 set 되어 시작 버튼 재활성화
        const lastCall = stateSetMock.mock.calls[stateSetMock.mock.calls.length - 1][0];
        expect(lastCall.identityChallenge.providerInProgress).toBe(false);
    });

    it('postMessage 도착 후 popup.closed 폴링이 중단되어 이중 dispatch 가 발생하지 않음', async () => {
        await startAuthHandler();

        postBridgeMessage({
            type: 'identity_result',
            verification_token: 'tok-xyz',
        });

        const seqCallsBefore = dispatchMock.mock.calls.filter((c) => c[0]?.handler === 'sequence').length;

        // 폴링 tick — 추가 dispatch 가 발생하면 안 됨
        popupClosedFlag = true;
        vi.advanceTimersByTime(2000);

        const seqCallsAfter = dispatchMock.mock.calls.filter((c) => c[0]?.handler === 'sequence').length;
        expect(seqCallsAfter).toBe(seqCallsBefore);
    });

    it('window.open 이 null 반환 시 toast 발행 + providerInProgress 미set', async () => {
        (window as any).open = vi.fn(() => null);

        await startAuthHandler();

        // toast dispatch 발생
        const toastCall = dispatchMock.mock.calls.find((c) => c[0]?.handler === 'toast');
        expect(toastCall).toBeDefined();
        expect(toastCall?.[0].params.type).toBe('error');

        // providerInProgress 는 set 안 됨 (popup 자체가 안 열렸으므로)
        const inProgressCall = stateSetMock.mock.calls.find(
            (c) => c[0]?.identityChallenge?.providerInProgress === true,
        );
        expect(inProgressCall).toBeUndefined();
    });

    it('public_payload 의 mid/mtxid/authHash 누락 시 toast 발행 + window.open 미호출', async () => {
        (window as any).G7Core.state.getGlobal = () => ({
            identityChallenge: {
                provider_id: 'inicis',
                public_payload: { mid: 'X' /* mtxid/authHash 누락 */ },
            },
        });

        await startAuthHandler();

        // window.open 안 불림
        expect((window as any).open).not.toHaveBeenCalled();
        // toast 발행
        expect(dispatchMock).toHaveBeenCalledWith(
            expect.objectContaining({ handler: 'toast' }),
        );
    });

    it('_global.identityChallenge 자체가 미설정이면 조기 반환', async () => {
        (window as any).G7Core.state.getGlobal = () => ({});
        (window as any).G7Core.state.get = () => ({});

        await startAuthHandler();

        expect((window as any).open).not.toHaveBeenCalled();
        expect(dispatchMock).not.toHaveBeenCalled();
        expect(stateSetMock).not.toHaveBeenCalled();
    });
});
