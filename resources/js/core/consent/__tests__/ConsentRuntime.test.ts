/**
 * 방문자 동의 런타임 (#639, 공개 gnuboard/g7#129 요청 7가지)
 *
 * 스냅샷 조회·refresh·변경 구독·인증 변경 무효화·stale 응답 차단·최소 스키마·contractVersion 을
 * 실제 런타임 인스턴스로 잡는다. 의존성(조회·전역 쓰기·이벤트·인증)만 흉내 낸다.
 */

import { describe, expect, it, vi } from 'vitest';

import { CONSENT_LISTENER_WAIT_MS, ConsentRuntime, parseConsentPayload, unknownSnapshot, type ConsentAuthState, type ConsentRuntimeDeps, type ConsentSnapshot } from '../ConsentRuntime';

/** 응답 본문 */
function body(data: Record<string, unknown>): Record<string, unknown> {
    return { success: true, data };
}

const READY_GUEST = body({ contract_version: 1, status: 'ready', categories: { necessary: true, functional: false, analytics: false, marketing: false }, needs_renewal: false, policy_version: '1.0' });
const READY_MEMBER = body({ contract_version: 1, status: 'ready', categories: { necessary: true, functional: true, analytics: true, marketing: false }, needs_renewal: false, policy_version: '1.0' });

/** 조작 가능한 의존성 묶음 */
function harness(fetchImpl: () => Promise<unknown>, auth: ConsentAuthState = { isAuthenticated: false, type: null, user: null }) {
    const writes: ConsentSnapshot[] = [];
    const events: ConsentSnapshot[] = [];
    let authHandler: ((state: ConsentAuthState) => void) | null = null;
    let authState = auth;
    const fetchSnapshot = vi.fn(fetchImpl);

    const deps: ConsentRuntimeDeps = {
        fetchSnapshot,
        writeGlobal: (s) => writes.push(s),
        emit: async (s) => {
            events.push(s);
        },
        onAuthChange: (handler) => {
            authHandler = handler;
            return () => {
                authHandler = null;
            };
        },
        getAuthState: () => authState,
    };

    return {
        deps,
        fetchSnapshot,
        writes,
        events,
        changeAuth(next: ConsentAuthState) {
            authState = next;
            authHandler?.(next);
        },
        get subscribed() {
            return authHandler !== null;
        },
    };
}

/** 지연 응답 */
function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((r) => {
        resolve = r;
    });
    return { promise, resolve };
}

/** 대기 중인 마이크로태스크를 비운다 */
const settle = () => new Promise((r) => setTimeout(r, 0));

describe('[case:state-global-consent-stale-after-login] ConsentRuntime', () => {
    // @scenario surface=runtime, change=boot, response=ok
    // @effects runtime_loads_snapshot_into_global_and_notifies
    it('첫 값 unknown 에서 조회 뒤 ready 로 바뀌고 전역 상태와 이벤트에 한 번씩 반영된다', async () => {
        const h = harness(async () => READY_MEMBER);
        const runtime = new ConsentRuntime(h.deps);

        const result = await runtime.start();

        expect(h.writes[0].status, '첫 값을 먼저 싣는다').toBe('unknown');
        expect(result.status).toBe('ready');
        expect(h.writes.at(-1)).toMatchObject({ contractVersion: 1, status: 'ready', needsRenewal: false, policyVersion: '1.0' });
        expect(h.writes.at(-1)?.categories).toEqual({ necessary: true, functional: true, analytics: true, marketing: false });
        expect(h.events).toHaveLength(1);
        expect(runtime.isPending()).toBe(false);
    });

    // @scenario surface=runtime, change=boot, response=error
    // @effects runtime_fails_closed_on_bad_response
    it('500·형식 오류·모르는 계약 버전은 unknown 이다', async () => {
        for (const fetchImpl of [
            async () => {
                throw new Error('500');
            },
            async () => body({ status: 'ready' }),
            async () => body({ contract_version: 2, status: 'ready', categories: {}, needs_renewal: false, policy_version: null }),
            async () => body({ contract_version: 1, status: 'ready', categories: { analytics: 'yes' }, needs_renewal: false, policy_version: null }),
        ]) {
            const h = harness(fetchImpl);
            const runtime = new ConsentRuntime(h.deps, parseConsentPayload(READY_MEMBER));

            const result = await runtime.refresh();

            expect(result.status).toBe('unknown');
            expect(result.categories.analytics).toBe(false);
            expect(result.categories.necessary).toBe(true);
        }
    });

    // @scenario surface=runtime, change=refresh, response=ok
    // @effects runtime_shares_inflight_request
    it('동시에 refresh 를 세 번 불러도 요청은 하나다', async () => {
        const pending = deferred<unknown>();
        const h = harness(() => pending.promise);
        const runtime = new ConsentRuntime(h.deps);

        const calls = [runtime.refresh(), runtime.refresh(), runtime.refresh()];
        pending.resolve(READY_GUEST);
        await Promise.all(calls);

        expect(h.fetchSnapshot).toHaveBeenCalledTimes(1);
    });

    // @scenario surface=runtime, change=login, response=ok
    // @effects runtime_drops_stale_response_after_auth_change
    it('게스트 조회가 늦게 오는 사이 로그인하면 늦은 게스트 응답은 버리고 회원 응답이 남는다', async () => {
        const guest = deferred<unknown>();
        const member = deferred<unknown>();
        let call = 0;
        const h = harness(() => (++call === 1 ? guest.promise : member.promise));
        const runtime = new ConsentRuntime(h.deps);

        void runtime.start();
        h.changeAuth({ isAuthenticated: true, type: 'user', user: { uuid: 'm-1' } });
        await settle();

        member.resolve(READY_MEMBER);
        await settle();
        guest.resolve(READY_GUEST);
        await settle();

        expect(h.fetchSnapshot).toHaveBeenCalledTimes(2);
        expect(runtime.getSnapshot().categories.analytics, '회원 기준').toBe(true);
        expect(h.writes.at(-1)?.categories.analytics).toBe(true);
    });

    // @scenario surface=runtime, change=login, response=ok
    // @effects runtime_resets_to_unknown_and_refetches_on_auth_change
    it('인증이 바뀌면 unknown 으로 비우고 다시 조회하며, 그 사이에는 판정 대기다', async () => {
        const responses = [READY_GUEST, READY_MEMBER];
        const h = harness(async () => responses.shift());
        const runtime = new ConsentRuntime(h.deps);
        await runtime.start();
        const settled = vi.fn();
        runtime.onSettled(settled);

        h.changeAuth({ isAuthenticated: true, type: 'user', user: { uuid: 'm-1' } });
        expect(runtime.isPending()).toBe(true);
        await settle();
        await settle();

        expect(h.events.map((e) => e.status)).toEqual(['ready', 'unknown', 'ready']);
        expect(runtime.getSnapshot().categories.analytics).toBe(true);
        expect(runtime.isPending()).toBe(false);
        expect(settled).toHaveBeenCalledTimes(1);
    });

    // @scenario surface=runtime, change=same_auth, response=ok
    // @effects runtime_ignores_same_auth_reemission
    it('같은 사람의 인증 재발행(checkAuth 등)은 무시한다', async () => {
        const h = harness(async () => READY_MEMBER, { isAuthenticated: true, type: 'user', user: { uuid: 'm-1' } });
        const runtime = new ConsentRuntime(h.deps);
        await runtime.start();

        h.changeAuth({ isAuthenticated: true, type: 'user', user: { uuid: 'm-1' } });
        await settle();

        expect(h.fetchSnapshot).toHaveBeenCalledTimes(1);
    });

    // @scenario surface=runtime, change=refresh, response=same
    // @effects runtime_notifies_only_on_change
    it('값이 같으면 전역 상태도 이벤트도 건드리지 않지만 판정 완료는 알린다', async () => {
        const h = harness(async () => READY_GUEST);
        const runtime = new ConsentRuntime(h.deps);
        await runtime.start();
        const writesBefore = h.writes.length;
        const settled = vi.fn();
        runtime.onSettled(settled);

        await runtime.refresh();

        expect(h.writes).toHaveLength(writesBefore);
        expect(h.events).toHaveLength(1);
        expect(settled).toHaveBeenCalledTimes(1);
    });

    // @scenario surface=runtime, change=boot, response=none
    // @effects runtime_keeps_none_when_no_provider
    it('서버가 none 을 인라인하면 첫 값이 none 이고 조회 결과도 none 이면 이벤트가 없다', async () => {
        const none = body({ contract_version: 1, status: 'none', categories: { necessary: true, functional: false, analytics: false, marketing: false }, needs_renewal: false, policy_version: null });
        const h = harness(async () => none);
        const runtime = new ConsentRuntime(h.deps, parseConsentPayload(none.data));

        await runtime.start();

        expect(h.writes[0].status).toBe('none');
        expect(h.events).toHaveLength(0);
        expect(runtime.getSnapshot().status).toBe('none');
    });

    // @scenario surface=runtime, change=refresh, response=ok
    // @effects runtime_survives_listener_errors
    it('구독자가 예외를 던져도 refresh 는 끝나고 스냅샷은 반영된다', async () => {
        const h = harness(async () => READY_MEMBER);
        h.deps.emit = async () => {
            throw new Error('listener boom');
        };
        const runtime = new ConsentRuntime(h.deps);

        await expect(runtime.start()).resolves.toMatchObject({ status: 'ready' });
    });

    // @scenario surface=runtime, change=refresh, response=ok
    // @effects runtime_survives_listener_errors
    it('구독자가 끝나지 않아도 정해진 시간 뒤 refresh 는 끝나고 다음 조회도 막히지 않는다', async () => {
        vi.useFakeTimers();
        try {
            const h = harness(async () => READY_MEMBER);
            h.deps.emit = () => new Promise<void>(() => undefined);
            const runtime = new ConsentRuntime(h.deps);

            let done = false;
            void runtime.start().then(() => {
                done = true;
            });
            await vi.advanceTimersByTimeAsync(CONSENT_LISTENER_WAIT_MS + 50);

            expect(done, '멈춘 구독자를 시한 없이 기다리면 refresh 가 영영 끝나지 않는다').toBe(true);
            expect(runtime.getSnapshot().status).toBe('ready');
            expect(runtime.isPending()).toBe(false);
        } finally {
            vi.useRealTimers();
        }
    });

    // @scenario surface=runtime, change=boot, response=ok
    // @effects runtime_snapshot_exposes_no_identifiers
    it('스냅샷은 공개 5키뿐이고 사본이다', async () => {
        const h = harness(async () => body({ ...(READY_MEMBER.data as object), user_id: 7, provider_id: 'x' }));
        const runtime = new ConsentRuntime(h.deps);
        await runtime.start();

        const snapshot = runtime.getSnapshot();
        expect(Object.keys(snapshot).sort()).toEqual(['categories', 'contractVersion', 'needsRenewal', 'policyVersion', 'status']);

        snapshot.categories.analytics = false;
        expect(runtime.getSnapshot().categories.analytics).toBe(true);
        expect(unknownSnapshot().status).toBe('unknown');
    });

    // @scenario surface=runtime, change=boot, response=ok
    // @effects runtime_subscribes_auth_once
    it('두 번 시작해도 인증 구독은 하나이고, stop 하면 풀린다', async () => {
        const h = harness(async () => READY_GUEST);
        const runtime = new ConsentRuntime(h.deps);
        await runtime.start();
        await runtime.start();

        expect(h.subscribed).toBe(true);
        runtime.stop();
        expect(h.subscribed).toBe(false);
    });

    // @scenario surface=runtime, change=stale_global, response=ok
    // @effects runtime_restores_global_after_stale_overwrite
    it('다른 쓰기가 _global 사본 전체로 consent 를 옛 값으로 되돌리면 런타임이 다음 틱에 되싣는다', async () => {
        // `dataKey="_global.xxx"` 폼 자동 바인딩은 렌더 시점의 `_global` 사본 전체를 다시 쓴다 — 그 사이 런타임이
        // consent 를 바꿨다면 옛 값이 덮인다(엔진 회귀 사례 대조 setstate 사례 1).
        let watcher: ((consent: unknown) => void) | null = null;
        const h = harness(async () => READY_MEMBER);
        h.deps.watchGlobal = (listener) => {
            watcher = listener;
            return () => {
                watcher = null;
            };
        };
        const runtime = new ConsentRuntime(h.deps);
        await runtime.start();
        const writesBefore = h.writes.length;

        watcher!(unknownSnapshot());
        expect(h.writes).toHaveLength(writesBefore);
        await settle();

        expect(h.writes).toHaveLength(writesBefore + 1);
        expect(h.writes.at(-1)?.status).toBe('ready');

        // 런타임 자신의 값과 같으면 아무 일도 하지 않는다 (되싣기 루프 없음)
        watcher!(runtime.getSnapshot());
        await settle();
        expect(h.writes).toHaveLength(writesBefore + 1);

        runtime.stop();
        expect(watcher).toBeNull();
    });

    // @scenario surface=runtime, change=login, response=none
    // @effects runtime_keeps_none_when_no_provider
    it('제공자가 없는(none) 사이트는 로그인해도 unknown 으로 비우지 않는다 (재렌더 절감)', async () => {
        const none = body({ contract_version: 1, status: 'none', categories: {}, needs_renewal: false, policy_version: null });
        const h = harness(async () => none);
        const runtime = new ConsentRuntime(h.deps, parseConsentPayload(none.data));
        await runtime.start();
        const writesBefore = h.writes.length;

        h.changeAuth({ isAuthenticated: true, type: 'user', user: { uuid: 'm-1' } });
        await settle();

        expect(h.writes).toHaveLength(writesBefore);
        expect(h.events).toHaveLength(0);
        expect(h.fetchSnapshot).toHaveBeenCalledTimes(2);
        expect(runtime.isPending()).toBe(false);
    });
});
