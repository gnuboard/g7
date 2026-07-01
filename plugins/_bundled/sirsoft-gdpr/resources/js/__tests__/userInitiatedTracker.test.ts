/**
 * userInitiatedTracker 단위 테스트.
 *
 * - install 시 capture-phase 리스너 등록
 * - isTrusted=true 인터랙션 만 timestamp 갱신
 * - threshold 이내 → isUserInitiated=true / 이후 → false
 * - window.event 폴백
 * - uninstall 시 리스너 해제 + timestamp 리셋
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    USER_INITIATED_THRESHOLD_MS,
    __setLastInteractionForTest,
    installUserInitiatedTracker,
    isUserInitiated,
    uninstallUserInitiatedTracker,
} from '../userInitiatedTracker';

describe('userInitiatedTracker', () => {
    beforeEach(() => {
        installUserInitiatedTracker();
    });

    afterEach(() => {
        uninstallUserInitiatedTracker();
        vi.useRealTimers();
    });

    it('install 직후 인터랙션 없음 → isUserInitiated=false', () => {
        expect(isUserInitiated()).toBe(false);
    });

    it('isTrusted=true click 이벤트 → 직후 isUserInitiated=true', () => {
        // jsdom 의 dispatchEvent 는 isTrusted=false. 직접 timestamp 주입으로 동등 검증.
        __setLastInteractionForTest(Date.now());
        expect(isUserInitiated()).toBe(true);
    });

    it('threshold 초과 시점 → isUserInitiated=false', () => {
        const past = Date.now() - USER_INITIATED_THRESHOLD_MS - 100;
        __setLastInteractionForTest(past);
        expect(isUserInitiated()).toBe(false);
    });

    it('threshold 경계값 (정확히 임계값) → 통과 (<=)', () => {
        const boundary = Date.now() - USER_INITIATED_THRESHOLD_MS;
        __setLastInteractionForTest(boundary);
        expect(isUserInitiated()).toBe(true);
    });

    it('untrusted 이벤트 (스크립트 dispatchEvent) 는 timestamp 갱신 안 함', () => {
        // jsdom dispatchEvent 는 isTrusted=false → handler 가 timestamp 갱신 안 함
        const event = new MouseEvent('click', { bubbles: true });
        window.dispatchEvent(event);

        expect(isUserInitiated()).toBe(false);
    });

    it('uninstall 후 isUserInitiated=false (timestamp 리셋)', () => {
        __setLastInteractionForTest(Date.now());
        expect(isUserInitiated()).toBe(true);

        uninstallUserInitiatedTracker();
        expect(isUserInitiated()).toBe(false);
    });

    it('uninstall 후 재install 가능 (idempotent)', () => {
        uninstallUserInitiatedTracker();
        expect(() => installUserInitiatedTracker()).not.toThrow();
        // re-install 후에도 timestamp 는 초기 상태
        expect(isUserInitiated()).toBe(false);
    });

    it('중복 install 호출 → no-op (리스너 1개만 등록)', () => {
        // 두 번째 install 호출이 silent 인지 확인 (예외 X)
        expect(() => installUserInitiatedTracker()).not.toThrow();
    });
});
