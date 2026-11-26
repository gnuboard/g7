/**
 * 코어 업데이트 진행 중 번들 선택 (7.2.1).
 *
 * 명령줄 `core:update` 는 코어 파일을 적용한 뒤 새로 들어온 공식 번들을 배치할지, 번들 확장·언어팩 중 무엇을 어떤
 * 레이아웃 전략으로 올릴지 묻는다. 웹은 일괄(전부 아니면 전부)만 됐다. 여기서 잠그는 것은 셋이다:
 *
 * 1. 착수 요청은 「진행 중 선택을 그릴 수 있다」는 표식(`ask_bundled_selection`)을 늘 싣는다.
 * 2. 서버가 선택을 기다리면(`run.awaiting`) 다음 단계를 부르지 않고 멈춘다.
 * 3. [계속] 은 바꾸지 않은 항목을 기본값(업데이트는 올림·항목 전략, 미배치 번들은 배치 안 함)으로 채워 보내고 다시 몬다.
 */

// e2e:allow 반복 호출 정지·선택 직렬화의 순수 단위. 화면 동선은 settings-core-update-tab.spec.ts 담당.

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
    startCoreUpdateHandler,
    submitCoreUpdateSelectionHandler,
    __setCoreUpdateSleepForTests,
} from '../coreUpdateHandler';

/** 번들 업데이트 선택을 기다리는 상태. */
function awaitingUpdates(): any {
    return {
        run: {
            status: 'running',
            next_step: 'bundled',
            completed_count: 10,
            log_seq: 20,
            claim: null,
            awaiting: {
                kind: 'bundled_updates',
                default_strategy: 'keep',
                items: [
                    { key: 'plugin:sirsoft-popup', type: 'plugin', identifier: 'sirsoft-popup', current_version: '1.0.0', latest_version: '1.0.1', has_layouts: true, strategy: 'keep' },
                    { key: 'lang_pack:g7-core-ja', type: 'lang_pack', identifier: 'g7-core-ja', current_version: '1.2.0', latest_version: '1.2.1', has_layouts: false, strategy: null },
                ],
            },
        },
        time_budget: { request_seconds: 300, client_wait_seconds: 330 },
    };
}

/** 완료 상태. */
function completed(): any {
    return { run: { status: 'completed', next_step: null, completed_count: 12, claim: null, awaiting: null }, time_budget: { request_seconds: 300, client_wait_seconds: 330 } };
}

describe('코어 업데이트 — 진행 중 번들 선택', () => {
    let get: ReturnType<typeof vi.fn>;
    let post: ReturnType<typeof vi.fn>;
    let globals: Record<string, any>;

    beforeEach(() => {
        get = vi.fn();
        post = vi.fn();
        globals = {};
        (window as any).G7Core = {
            api: { get, post },
            state: {
                setGlobal: (patch: Record<string, unknown>) => Object.assign(globals, patch),
                get: () => globals,
            },
            toast: { error: vi.fn() },
        };
        __setCoreUpdateSleepForTests(async () => {});
    });

    afterEach(() => {
        __setCoreUpdateSleepForTests(null);
        delete (window as any).G7Core;
    });

    /**
     * @scenario client=asks, selection=bundled_updates
     * @effects start_declares_selection_capable_screen, drive_stops_while_awaiting_selection
     */
    it('착수는 선택 표식을 싣고, 서버가 선택을 기다리면 다음 단계를 부르지 않는다', async () => {
        post.mockImplementation(async (url: string) => (url.endsWith('/start') ? { data: { run: { status: 'running', next_step: 'preflight', completed_count: 0, claim: null } } } : { data: awaitingUpdates() }));

        await startCoreUpdateHandler({ params: {} });

        const startBody = post.mock.calls.find(([url]) => String(url).endsWith('/start'))?.[1];
        expect(startBody.ask_bundled_selection).toBe(true);
        const advances = post.mock.calls.filter(([url]) => String(url).endsWith('/advance'));
        expect(advances).toHaveLength(1);
        expect(globals.coreUpdateStatus.run.awaiting.kind).toBe('bundled_updates');
    });

    /**
     * @scenario client=asks, selection=bundled_updates
     * @effects continue_sends_defaults_for_untouched_items, continue_resumes_driving
     */
    it('[계속] 은 바꾼 항목은 그 값으로, 바꾸지 않은 항목은 기본값으로 보내고 다시 몬다', async () => {
        globals.coreUpdateStatus = awaitingUpdates();
        globals.coreUpdateChoices = { 'lang_pack:g7-core-ja': { selected: false } };
        post.mockImplementation(async (url: string) => (url.endsWith('/selection') ? { data: { ...awaitingUpdates(), run: { ...awaitingUpdates().run, awaiting: null } } } : { data: completed() }));

        await submitCoreUpdateSelectionHandler();

        const [url, body] = post.mock.calls[0];
        expect(String(url)).toContain('/api/admin/core-update/selection');
        expect(body).toEqual({
            kind: 'bundled_updates',
            items: [
                { key: 'plugin:sirsoft-popup', selected: true, strategy: 'keep' },
                { key: 'lang_pack:g7-core-ja', selected: false, strategy: 'keep' },
            ],
        });
        expect(globals.coreUpdateChoices).toBeNull();
        expect(post.mock.calls.some(([u]) => String(u).endsWith('/advance'))).toBe(true);
        expect(globals.coreUpdateStatus.run.status).toBe('completed');
    });

    /**
     * @scenario client=asks, selection=bundled_sources
     * @effects unplaced_bundles_default_to_not_placed
     */
    it('새로 들어온 미배치 번들은 고르지 않으면 배치하지 않는다', async () => {
        globals.coreUpdateStatus = {
            run: { status: 'running', next_step: 'plan', completed_count: 5, claim: null, awaiting: { kind: 'bundled_sources', items: [{ key: 'plugin:sirsoft-new', type: 'plugin', identifier: 'sirsoft-new', name: '새 플러그인', version: '1.0.0' }] } },
        };
        post.mockImplementation(async (url: string) => (url.endsWith('/selection') ? { data: completed() } : { data: completed() }));

        await submitCoreUpdateSelectionHandler();

        expect(post.mock.calls[0][1]).toEqual({ kind: 'bundled_sources', items: [{ key: 'plugin:sirsoft-new', selected: false, strategy: 'overwrite' }] });
    });
});
