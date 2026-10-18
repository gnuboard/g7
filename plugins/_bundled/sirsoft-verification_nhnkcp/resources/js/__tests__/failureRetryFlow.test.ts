/**
 * @file failureRetryFlow.test.ts
 * @description 인증 실패 후 안내 창 유지 · 재시도 · 실패 사유 안내 검증
 *
 * 실패했다고 안내 창을 닫아 버리면 사용자는 하던 작업(가입 등)을 처음부터 다시 해야 한다.
 * 안내 창은 남고, 다시 시작하면 새 거래로 인증이 열리며, 우리말 안내가 없는 KCP 코드는
 * 코드와 원문이 그대로 표시되어야 한다 (와이어프레임 §4.3 · 실패 안내 규약 §3.7).
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { startAuthHandler, resolveFailureMessage, MODAL_ID } from '../index';

const CALL_URL = 'https://testcert.kcp.co.kr/certGateway.do';
const NEXT_CALL_URL = 'https://testcert.kcp.co.kr/certGateway.do?next=1';
/** 서버가 챌린지 payload 로 내려주는 사이트 origin — 결과 창 출처 검증 기준 */
const SITE_ORIGIN = 'https://shop.example.test';
/** 가장 최근에 연 인증 팝업 — 결과 메시지의 발신 창 */
let lastPopup: unknown = null;

interface SubmittedForm {
    action: string;
    target: string;
    fields: Record<string, string>;
}

let submitted: SubmittedForm[] = [];
let dispatched: Array<Record<string, unknown>> = [];
let toasts: Array<Record<string, unknown>> = [];
let globalState: Record<string, any> = {};
let challengeSeq = 0;

/**
 * 테스트마다 다른 challenge 식별자를 쓴다 — 사용 완료 표시가 모듈 수준에 남아 다음 테스트에
 * 번지지 않도록 (실제로도 challenge 는 매 흐름마다 새로 발급된다).
 */
function nextChallengeId(): string {
    challengeSeq += 1;
    return `challenge-${challengeSeq}`;
}

let currentChallengeId = 'challenge-0';

/** 코어 API 클라이언트의 post 모의 — 재발급·취소 통보가 모두 여기로 온다 */
let apiPost = vi.fn();

/**
 * 새 거래 발급 응답을 정한다. 취소 통보 등 다른 요청은 성공으로 답한다.
 *
 * @param reissue 새 거래 발급 응답 (예외를 던지게 하려면 Error)
 */
function answerReissue(reissue: Record<string, unknown> | Error): void {
    apiPost.mockImplementation(async (url: string) => {
        if (url !== '/api/identity/challenges') return { success: true };
        if (reissue instanceof Error) throw reissue;

        return reissue;
    });
}

/** 새 거래 발급 요청만 골라낸다 */
function reissueCalls(): Array<[string, Record<string, unknown>]> {
    return (apiPost.mock.calls as unknown as Array<[string, Record<string, unknown>]>)
        .filter(([url]) => url === '/api/identity/challenges');
}

function setupG7Core(): void {
    apiPost = vi.fn(async () => ({ success: true }));
    dispatched = [];
    toasts = [];
    globalState = {
        identityChallenge: {
            provider_id: 'nhnkcp',
            purpose: 'signup',
            challenge_id: currentChallengeId,
            public_payload: { call_url: CALL_URL, reg_cert_key: 'CERTKEY000000001', siteOrigin: SITE_ORIGIN },
        },
    };

    (window as any).G7Core = {
        state: {
            get: () => globalState,
            getGlobal: () => globalState,
            set: (updates: Record<string, any>) => {
                globalState = {
                    ...globalState,
                    identityChallenge: {
                        ...(globalState.identityChallenge ?? {}),
                        ...(updates.identityChallenge ?? {}),
                    },
                };
            },
        },
        dispatch: vi.fn(async (action: Record<string, unknown>) => {
            dispatched.push(action);
            if (action.handler === 'toast') toasts.push(action.params as Record<string, unknown>);
            return true;
        }),
        identity: { markDomainNoticeShown: vi.fn() },
        api: { post: apiPost },
    };
}

function captureFormSubmits(): void {
    submitted = [];
    HTMLFormElement.prototype.submit = function (this: HTMLFormElement) {
        const fields: Record<string, string> = {};
        this.querySelectorAll('input').forEach((input) => {
            fields[input.name] = input.value;
        });
        submitted.push({
            action: this.getAttribute('action') ?? '',
            target: this.getAttribute('target') ?? '',
            fields,
        });
    };
}

/** 브리지가 부모 창으로 보내는 결과 메시지를 재현한다(사이트 origin·열린 팝업이 보낸 것으로). */
function emitBridgeResult(data: Record<string, unknown>): void {
    const ev = new MessageEvent('message', {
        data: { type: 'identity_result', ...data },
        origin: SITE_ORIGIN,
    });
    Object.defineProperty(ev, 'source', { value: lastPopup });
    window.dispatchEvent(ev);
}

/** 인증 팝업을 흉내 내고 마지막으로 연 팝업을 기억한다. */
function openPopupStub(popup: { closed: boolean; close: () => void } = { closed: false, close: vi.fn() }) {
    return vi.fn(() => {
        lastPopup = popup;

        return popup;
    });
}

describe('인증 실패 후 흐름', () => {
    beforeEach(() => {
        currentChallengeId = nextChallengeId();
        setupG7Core();
        captureFormSubmits();
        window.sessionStorage.clear();
        vi.stubGlobal('open', openPopupStub());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    // @scenario environment=desktop,outcome=failed
    // @effects failed_result_keeps_panel_open_for_retry
    it('실패로 돌아오면 안내 창을 닫지 않는다', async () => {
        await startAuthHandler();

        emitBridgeResult({ identity_error: 'REMOTE_CALL_FAILED', challenge_id: currentChallengeId });

        const closed = dispatched.some(
            (a) => a.handler === 'sequence' || a.target === MODAL_ID || a.handler === 'closeModal',
        );
        expect(closed).toBe(false);
        expect(globalState.identityChallenge.providerInProgress).toBe(false);
        expect(toasts.length).toBe(1);
    });

    // @scenario environment=desktop,outcome=verified
    // @effects verified_result_resolves_and_closes_panel
    it('인증에 성공하면 안내 창을 닫고 결과를 알린다', async () => {
        await startAuthHandler();

        emitBridgeResult({ verification_token: 'token-1', challenge_id: currentChallengeId });
        await Promise.resolve();

        const sequence = dispatched.find((a) => a.handler === 'sequence');
        expect(sequence).toBeTruthy();
        const actions = (sequence?.params as any).actions as Array<Record<string, unknown>>;
        expect(actions[0].handler).toBe('resolveIdentityChallenge');
        expect(actions[1].handler).toBe('closeModal');
    });

    // @scenario environment=desktop,outcome=failed
    // @effects retry_after_failure_issues_new_transaction
    it('실패 후 다시 시작하면 새 거래를 발급받아 인증창을 연다', async () => {
        await startAuthHandler();
        emitBridgeResult({ identity_error: 'REMOTE_CALL_FAILED', challenge_id: currentChallengeId });

        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy as unknown as typeof fetch);
        answerReissue({
            success: true,
            data: {
                id: 'challenge-reissued',
                expires_at: '2026-07-28T15:00:00+09:00',
                public_payload: { call_url: NEXT_CALL_URL, reg_cert_key: 'CERTKEY000000002', siteOrigin: SITE_ORIGIN },
            },
        });

        await startAuthHandler();

        // 코어 API 클라이언트로 보낸다 — Bearer·401·로케일은 코어가 맡고 raw fetch 는 쓰지 않는다
        expect(fetchSpy).not.toHaveBeenCalled();
        expect(reissueCalls()).toHaveLength(1);
        expect(reissueCalls()[0][1]).toMatchObject({ purpose: 'signup', provider_id: 'nhnkcp' });

        // 새 거래 정보로 표준창이 열려야 한다 (이전 거래키 재사용 금지)
        expect(submitted[submitted.length - 1].action).toBe(NEXT_CALL_URL);
        expect(submitted[submitted.length - 1].fields.reg_cert_key).toBe('CERTKEY000000002');
        expect(globalState.identityChallenge.challenge_id).toBe('challenge-reissued');
    });

    /**
     * 실패 결과에 challenge_id 가 실려 오지 않아도 진행 중이던 거래는 끝난 것이 확실하다.
     * 이 경우를 놓치면 죽은 거래키로 인증창을 다시 열어 "이미 종료된 거래" 로 실패하고,
     * 사용자는 몇 번을 눌러도 진행하지 못한다 (실제 발생했던 증상).
     */
    // @scenario environment=desktop,outcome=failed
    // @effects retry_after_failure_issues_new_transaction
    it('실패 결과에 challenge_id 가 없어도 재시도 시 새 거래를 발급받는다', async () => {
        await startAuthHandler();
        emitBridgeResult({ identity_error: 'DUPLICATE' });

        answerReissue({
            success: true,
            data: {
                id: 'challenge-reissued-2',
                expires_at: '2026-07-28T15:00:00+09:00',
                public_payload: { call_url: NEXT_CALL_URL, reg_cert_key: 'CERTKEY000000003', siteOrigin: SITE_ORIGIN },
            },
        });

        await startAuthHandler();

        expect(reissueCalls()).toHaveLength(1);
        expect(submitted[submitted.length - 1].fields.reg_cert_key).toBe('CERTKEY000000003');
    });

    /**
     * 사용자가 인증창을 그냥 닫았다가 다시 시작하는 경우. 그 거래는 이미 표준창에 제출됐고
     * KCP 거래키에는 유효 시간이 있어, 같은 거래를 다시 쓰면 한참 뒤 재시도가 아무 안내 없이
     * 실패할 수 있다. 다시 시작할 때는 새 거래를 받아야 한다.
     */
    // @scenario environment=desktop,outcome=cancelled
    // @effects retry_after_popup_close_issues_new_transaction
    it('인증창을 닫았다가 다시 시작하면 새 거래를 발급받는다', async () => {
        vi.useFakeTimers();
        const popup = { closed: false, close: vi.fn() };
        vi.stubGlobal('open', openPopupStub(popup));

        await startAuthHandler();
        popup.closed = true;
        vi.advanceTimersByTime(500);
        vi.useRealTimers();

        answerReissue({
            success: true,
            data: {
                id: 'challenge-reissued-3',
                expires_at: '2026-07-28T15:00:00+09:00',
                public_payload: { call_url: NEXT_CALL_URL, reg_cert_key: 'CERTKEY000000004', siteOrigin: SITE_ORIGIN },
            },
        });
        vi.stubGlobal('open', openPopupStub());

        await startAuthHandler();

        expect(reissueCalls()).toHaveLength(1);
        expect(submitted[submitted.length - 1].fields.reg_cert_key).toBe('CERTKEY000000004');
        expect(globalState.identityChallenge.challenge_id).toBe('challenge-reissued-3');
    });

    // @scenario environment=desktop,outcome=failed
    // @effects retry_after_failure_issues_new_transaction
    it('새 거래 발급이 실패하면 안내만 하고 인증창을 열지 않는다', async () => {
        await startAuthHandler();
        emitBridgeResult({ identity_error: 'REMOTE_CALL_FAILED', challenge_id: currentChallengeId });
        const submittedBefore = submitted.length;

        // 4xx·5xx 는 API 클라이언트가 예외로 던진다
        answerReissue(Object.assign(new Error('HTTP 422'), { response: { status: 422, data: { success: false } } }));

        await startAuthHandler();

        expect(submitted.length).toBe(submittedBefore);
        expect(toasts.length).toBeGreaterThanOrEqual(2);
    });
});

describe('실패 사유 안내 문구', () => {
    it('우리말 안내가 준비된 사유는 다국어 키로 안내한다', () => {
        expect(resolveFailureMessage('NOT_ADULT')).toBe('$t:sirsoft-verification_nhnkcp.errors.not_adult');
        expect(resolveFailureMessage('9999')).toBe('$t:sirsoft-verification_nhnkcp.errors.cancelled');
    });

    it('안내가 없는 KCP 코드는 코드와 원문을 그대로 보여준다', () => {
        expect(resolveFailureMessage('CS16', '거래등록이 올바르지 않습니다.')).toBe(
            '[CS16] 거래등록이 올바르지 않습니다.',
        );
    });

    it('원문도 없으면 일반 실패 안내로 떨어진다', () => {
        expect(resolveFailureMessage('CS99')).toBe('$t:sirsoft-verification_nhnkcp.errors.verify_failed');
        expect(resolveFailureMessage('CS99', '   ')).toBe('$t:sirsoft-verification_nhnkcp.errors.verify_failed');
    });
});
