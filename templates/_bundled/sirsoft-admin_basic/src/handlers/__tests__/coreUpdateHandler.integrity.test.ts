/**
 * 코어 업데이트 착수의 패키지 무결성 거절 처리 (#916).
 *
 * 서버가 최신 릴리스를 미서명(409 `package_unsigned`)·서명 불일치(422)로 거절하면 착수 핸들러는 실패 토스트 대신
 * 거절 내용(`errors.integrity`·`message`)을 전역 `packageIntegrity`·`packageIntegrityMessage` 에 싣는다 — 업데이트 탭의
 * 공용 안내 조각이 그것을 그린다. 「미서명으로 계속」은 같은 선택에 `allow_unsigned` 만 더해 다시 착수한다.
 * 같은 409 를 쓰는 작업 잠금(`operation_in_progress`)은 종전대로 실패 토스트다.
 */

// e2e:allow 착수 요청 본문·전역 상태 분기의 순수 단위. 화면 동선은 settings-core-update-tab.spec.ts 담당.

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { startCoreUpdateHandler, __setCoreUpdateSleepForTests } from '../coreUpdateHandler';

/** HTTP 오류 모양(api.post 가 던지는 것). */
function httpError(status: number, data: Record<string, unknown>): any {
    const error: any = new Error(`Request failed with status code ${status}`);
    error.response = { status, data };

    return error;
}

const UNSIGNED = {
    status: 'unsigned',
    code: 'package_unsigned',
    can_continue: true,
    blocked_by_policy: false,
    reason: null,
    message: null,
    key_id: null,
    signer_label: null,
    key_source: null,
    package: { type: 'core', identifier: 'g7', version: '7.2.1' },
};

describe('코어 업데이트 착수 — 패키지 무결성 거절', () => {
    let get: ReturnType<typeof vi.fn>;
    let post: ReturnType<typeof vi.fn>;
    let toastError: ReturnType<typeof vi.fn>;
    let globals: Record<string, unknown>;

    beforeEach(() => {
        get = vi.fn().mockResolvedValue({ data: { run: null } });
        post = vi.fn();
        toastError = vi.fn();
        globals = {};

        (window as any).G7Core = {
            api: { get, post },
            state: {
                setGlobal: (patch: Record<string, unknown>) => Object.assign(globals, patch),
                get: () => globals,
            },
            toast: { error: toastError },
            t: (key: string) => key,
        };
        __setCoreUpdateSleepForTests(async () => undefined);
    });

    afterEach(() => {
        __setCoreUpdateSleepForTests(null);
        delete (window as any).G7Core;
    });

    /**
     * 커버 축(마커 아님 — 평문): response 409_package_unsigned
     * @scenario case=core_update_start_unsigned
     * @effects core_update_integrity_rejection_published, no_failure_toast_for_integrity
     */
    it('409 미서명 거절은 토스트 없이 거절 내용을 전역에 싣는다', async () => {
        post.mockRejectedValueOnce(httpError(409, { success: false, message: '서명되지 않은 코어 패키지입니다.', errors: { package_unsigned: true, integrity: UNSIGNED } }));

        await startCoreUpdateHandler({ params: { layout_strategy: 'overwrite' } });

        expect(toastError).not.toHaveBeenCalled();
        expect(globals.packageIntegrity).toEqual(UNSIGNED);
        expect(globals.packageIntegrityMessage).toBe('서명되지 않은 코어 패키지입니다.');
        expect(post.mock.calls[0][1]).not.toHaveProperty('allow_unsigned');
        // 서명 확인을 아는 화면의 표식 — 첫 착수부터 늘 싣는다(서버가 옛 화면과 구별, #916 옛 화면 호환)
        expect(post.mock.calls[0][1]).toHaveProperty('integrity_aware', true);
        expect(globals.coreUpdateBusy).toBe(false);
    });

    /**
     * 커버 축(마커 아님 — 평문): allow_unsigned true
     * @scenario case=core_update_start_continue_unsigned
     * @effects core_update_retry_sends_allow_unsigned, core_update_integrity_cleared_on_start
     */
    it('「미서명으로 계속」은 같은 선택에 allow_unsigned 만 더해 착수하고, 착수되면 거절 안내를 지운다', async () => {
        globals.packageIntegrity = UNSIGNED;
        post.mockResolvedValueOnce({ data: { run: { status: 'completed', next_step: null, completed_count: 1 } } });

        await startCoreUpdateHandler({ params: { update_bundled_extensions: false, rebuild_search_index: true, layout_strategy: 'keep', zip_file: '', allow_unsigned: true } });

        expect(post.mock.calls[0][1]).toEqual({
            update_bundled_extensions: false,
            rebuild_search_index: true,
            layout_strategy: 'keep',
            integrity_aware: true,
            allow_unsigned: true,
        });
        expect(globals.packageIntegrity).toBeNull();
        expect(globals.packageIntegrityMessage).toBeNull();
    });

    /**
     * 커버 축(마커 아님 — 평문): response 409_operation_in_progress
     * @scenario case=core_update_start_operation_lock
     * @effects core_update_lock_409_still_toasts
     */
    it('작업 잠금 409 는 무결성 거절이 아니라 종전대로 실패 토스트다', async () => {
        post.mockRejectedValueOnce(httpError(409, { success: false, message: '다른 업데이트가 진행 중입니다.', errors: { operation_in_progress: true } }));

        await startCoreUpdateHandler({ params: {} });

        expect(toastError).toHaveBeenCalledWith('다른 업데이트가 진행 중입니다.');
        expect(globals.packageIntegrity).toBeNull();
    });

    /**
     * 커버 축(마커 아님 — 평문): response 422_package_signature_invalid
     * @scenario case=core_update_start_signature_invalid
     * @effects core_update_integrity_rejection_published, no_failure_toast_for_integrity
     */
    it('422 서명 불일치도 거절 내용을 전역에 싣는다(계속 불가)', async () => {
        const invalid = { ...UNSIGNED, status: 'invalid', code: 'package_signature_invalid', can_continue: false, message: 'SHA-256 이 다릅니다.' };
        post.mockRejectedValueOnce(httpError(422, { success: false, message: '서명이 올바르지 않습니다.', errors: { package_signature_invalid: true, integrity: invalid } }));

        await startCoreUpdateHandler({ params: {} });

        expect(toastError).not.toHaveBeenCalled();
        expect(globals.packageIntegrity).toEqual(invalid);
    });
});
