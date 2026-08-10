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

function setupG7Core(): void {
    dispatched = [];
    toasts = [];
    globalState = {
        identityChallenge: {
            provider_id: 'nhnkcp',
            purpose: 'signup',
            challenge_id: currentChallengeId,
            public_payload: { call_url: CALL_URL, reg_cert_key: 'CERTKEY000000001' },
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
        api: { getToken: () => null },
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

/** 브리지가 부모 창으로 보내는 결과 메시지를 재현한다. */
function emitBridgeResult(data: Record<string, unknown>): void {
    window.dispatchEvent(
        new MessageEvent('message', {
            data: { type: 'identity_result', ...data },
            origin: window.location.origin,
        }),
    );
}

describe('인증 실패 후 흐름', () => {
    beforeEach(() => {
        currentChallengeId = nextChallengeId();
        setupG7Core();
        captureFormSubmits();
        window.sessionStorage.clear();
        vi.stubGlobal('open', vi.fn(() => ({ closed: false, close: vi.fn() })));
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

        const fetchMock = vi.fn(async () => ({
            ok: true,
            json: async () => ({
                success: true,
                data: {
                    id: 'challenge-reissued',
                    expires_at: '2026-07-28T15:00:00+09:00',
                    public_payload: { call_url: NEXT_CALL_URL, reg_cert_key: 'CERTKEY000000002' },
                },
            }),
        }));
        vi.stubGlobal('fetch', fetchMock as unknown as typeof fetch);

        await startAuthHandler();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe('/api/identity/challenges');
        expect(JSON.parse(String(init.body))).toMatchObject({ purpose: 'signup', provider_id: 'nhnkcp' });

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

        const fetchMock = vi.fn(async () => ({
            ok: true,
            json: async () => ({
                success: true,
                data: {
                    id: 'challenge-reissued-2',
                    expires_at: '2026-07-28T15:00:00+09:00',
                    public_payload: { call_url: NEXT_CALL_URL, reg_cert_key: 'CERTKEY000000003' },
                },
            }),
        }));
        vi.stubGlobal('fetch', fetchMock as unknown as typeof fetch);

        await startAuthHandler();

        expect(fetchMock).toHaveBeenCalledTimes(1);
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
        vi.stubGlobal('open', vi.fn(() => popup));

        await startAuthHandler();
        popup.closed = true;
        vi.advanceTimersByTime(500);
        vi.useRealTimers();

        const fetchMock = vi.fn(async () => ({
            ok: true,
            json: async () => ({
                success: true,
                data: {
                    id: 'challenge-reissued-3',
                    expires_at: '2026-07-28T15:00:00+09:00',
                    public_payload: { call_url: NEXT_CALL_URL, reg_cert_key: 'CERTKEY000000004' },
                },
            }),
        }));
        vi.stubGlobal('fetch', fetchMock as unknown as typeof fetch);
        vi.stubGlobal('open', vi.fn(() => ({ closed: false, close: vi.fn() })));

        await startAuthHandler();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(submitted[submitted.length - 1].fields.reg_cert_key).toBe('CERTKEY000000004');
        expect(globalState.identityChallenge.challenge_id).toBe('challenge-reissued-3');
    });

    // @scenario environment=desktop,outcome=failed
    // @effects retry_after_failure_issues_new_transaction
    it('새 거래 발급이 실패하면 안내만 하고 인증창을 열지 않는다', async () => {
        await startAuthHandler();
        emitBridgeResult({ identity_error: 'REMOTE_CALL_FAILED', challenge_id: currentChallengeId });
        const submittedBefore = submitted.length;

        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({ ok: false, json: async () => ({ success: false }) })) as unknown as typeof fetch,
        );

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
