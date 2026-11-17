/**
 * @file ActionDispatcher.errorHandlerReentry.test.ts
 * @description 오류 처리기 자신이 실패해도 오류 처리를 끝없이 다시 돌리지 않는다 (engine-v1.95.0)
 *
 * 실패한 동작의 오류 처리기(errorHandling·onError·레이아웃/템플릿/시스템 기본값)는 `executeAction` 으로 실행되고, 그 처리기가 실패하면
 * 같은 catch 갈래에서 다시 오류 처리기를 고른다. 처리기에 자기 정의가 없으면 시스템 기본(`toast`)이 다시 골라지고, 그 `toast` 가
 * 같은 이유로 실패하면(전역 갱신기가 던짐) 끝없이 되풀이했다. 비동기 재귀라 마이크로태스크만 돌아 타이머(시험 제한 시간 포함)가
 * 실행되지 않고 메모리만 늘었다 — 이탈 가드 시험 파일이 남긴 디바운스 쓰기가 정리 뒤 터지며 작업 프로세스가 4GB 에서 죽었다.
 *
 * 하네스는 실물 ActionDispatcher 와 기본 ErrorHandlingResolver 를 쓴다. 전역 갱신기만 시험이 정한다.
 *
 * @scenario-manifest tests/scenarios/engine-error-handler-reentry.yaml
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { Logger } from '../../utils/Logger';

/** 수정 전에도 시험이 끝나도록 갱신기는 이 횟수 뒤 던지기를 멈춘다(그 횟수까지 닿으면 끝없는 재진입이다) */
const RUNAWAY_LIMIT = 200;

describe('[case:network-error-handler-reentry-runaway] 오류 처리기 자신의 실패는 오류 처리 재진입 상한에서 끝난다 (engine-v1.95.0)', () => {
    let dispatcher: ActionDispatcher;
    let updaterCalls = 0;
    let originalG7Core: any;

    beforeEach(() => {
        updaterCalls = 0;
        originalG7Core = (window as any).G7Core;
        delete (window as any).G7Core;
        Logger.getInstance().setDebug(false);
        dispatcher = new ActionDispatcher();
        dispatcher.registerHandler('test.fail', () => {
            throw new Error('처리 실패');
        });
    });

    afterEach(() => {
        dispatcher.clearDebounceTimers();
        if (originalG7Core === undefined) delete (window as any).G7Core;
        else (window as any).G7Core = originalG7Core;
    });

    /**
     * @scenario case=default_handler_keeps_failing
     * @effects reentry_bounded, action_reports_failure
     */
    it('기본 오류 처리기(toast)가 매번 실패해도 시도는 두 번에서 멈추고 동작은 실패로 끝난다', async () => {
        // 전역 갱신기가 계속 던진다 — 기본 toast 가 전역 상태(toasts)에 쓰다 매번 실패한다
        dispatcher.setGlobalStateUpdater(() => {
            updaterCalls++;
            if (updaterCalls < RUNAWAY_LIMIT) throw new Error('전역 상태에 쓸 수 없음');
        });

        const result: any = await dispatcher.dispatchAction({ handler: 'test.fail' } as any, {} as any);

        expect(result.success).toBe(false);
        // 원래 실패의 기본 toast 1회 + 그 toast 실패의 기본 toast 1회 — 그 뒤로는 다시 고르지 않는다
        expect(updaterCalls, `toast 시도 ${updaterCalls}회`).toBe(2);
    });

    /**
     * @scenario case=error_handler_fails_once
     * @effects nested_default_still_runs
     */
    it('오류 처리기(onError)가 한 번 실패하면 그 실패의 기본 오류 처리(toast)는 종전처럼 실행된다', async () => {
        const toasts: any[] = [];
        dispatcher.setGlobalStateUpdater((updates: any) => {
            updaterCalls++;
            if (Array.isArray(updates?.toasts)) toasts.push(...updates.toasts);
        });

        const result: any = await dispatcher.dispatchAction(
            { handler: 'test.fail', onError: [{ handler: 'test.fail' }] } as any,
            {} as any
        );

        expect(result.success).toBe(false);
        // onError 의 실패가 기본 toast 로 사용자에게 알려진다(깊이 2 까지는 종전 그대로)
        expect(toasts.filter((t) => t.type === 'error')).toHaveLength(1);
    });

    /**
     * @scenario case=no_error
     * @effects nested_default_still_runs
     */
    it('대조군: 처리기가 성공하는 동작의 오류 처리는 한 번만 돈다', async () => {
        const toasts: any[] = [];
        dispatcher.setGlobalStateUpdater((updates: any) => {
            updaterCalls++;
            if (Array.isArray(updates?.toasts)) toasts.push(...updates.toasts);
        });

        const result: any = await dispatcher.dispatchAction({ handler: 'test.fail' } as any, {} as any);

        expect(result.success).toBe(false);
        expect(toasts.filter((t) => t.type === 'error')).toHaveLength(1);
        expect(updaterCalls).toBe(1);
    });
});
