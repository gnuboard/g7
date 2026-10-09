/**
 * 편집기 본체 실패 안내가 플러그인 문구 한 건으로 나온다
 *
 * 편집기 본체는 조각의 `<script id="ckeditor5_script">` 로 실리므로, 끝내 못 불러오면 코어 레이아웃 스크립트
 * 로더가 `layout-script:ckeditor5_script` 로 실패를 따로 알린다. 플러그인도 임시 입력창으로 내려가며
 * `ckeditor5-editor:{컨테이너}` 로 알리므로 두 건이 겹치면 배너가 「2개 항목을 불러오지 못했습니다. 편집기, 편집기」
 * 묶음 문구가 되고 「임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다.」 문장이 사라진다.
 * 플러그인 안내의 재시도가 같은 본체를 다시 확보하므로 코어의 같은 자산 안내는 걷는다 — 먼저 와 있든 나중에 오든.
 *
 * @effects editor_script_failure_shows_single_plugin_notice
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { notifyEditorAssetFailure } from '../handlers/editorFailureNotice';

const CORE_ID = 'layout-script:ckeditor5_script';
const EDITOR_ID = 'ckeditor5-editor:ckeditor5-content';
const FALLBACK_NOTICE = '편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다.';

describe('[case:network-layout-script-duplicate-failure-notice] 편집기 본체 실패 안내 — 코어 같은 자산 안내와 겹치지 않는다', () => {
    let failures: Map<string, any>;

    beforeEach(() => {
        vi.useFakeTimers();
        failures = new Map();
        (window as any).G7Core = {
            assets: {
                notifyFailure: vi.fn((f: any) => failures.set(f.id, f)),
                clearFailure: vi.fn((id: string) => failures.delete(id)),
                getFailures: () => Array.from(failures.values()),
            },
        };
    });

    afterEach(() => {
        failures.clear();
        vi.runOnlyPendingTimers();
        vi.useRealTimers();
        delete (window as any).G7Core;
    });

    /**
     * @scenario core_notice_order=before
     * @effects editor_script_failure_shows_single_plugin_notice
     */
    it('코어 안내가 먼저 와 있으면 걷고 플러그인 안내 한 건만 남긴다', () => {
        failures.set(CORE_ID, { id: CORE_ID, label: '편집기' });

        notifyEditorAssetFailure(EDITOR_ID, '편집기', () => {}, FALLBACK_NOTICE);

        expect(Array.from(failures.keys())).toEqual([EDITOR_ID]);
        expect(failures.get(EDITOR_ID).message).toBe(FALLBACK_NOTICE);
    });

    /**
     * @scenario core_notice_order=after
     * @effects editor_script_failure_shows_single_plugin_notice
     */
    it('코어 안내가 나중에 와도 걷는다(코어 재시도 예산이 끝나는 시점이 플러그인 대기와 엇갈린다)', () => {
        notifyEditorAssetFailure(EDITOR_ID, '편집기', () => {}, FALLBACK_NOTICE);
        failures.set(CORE_ID, { id: CORE_ID, label: '편집기' });

        vi.advanceTimersByTime(1000);

        expect(Array.from(failures.keys())).toEqual([EDITOR_ID]);
    });

    /**
     * @scenario core_notice_order=after
     * @effects editor_script_failure_shows_single_plugin_notice
     */
    it('플러그인 안내가 해제된 뒤에는 코어 안내를 건드리지 않는다', () => {
        notifyEditorAssetFailure(EDITOR_ID, '편집기', () => {}, FALLBACK_NOTICE);
        failures.delete(EDITOR_ID);
        vi.advanceTimersByTime(1000);
        failures.set(CORE_ID, { id: CORE_ID, label: '편집기' });

        vi.advanceTimersByTime(60000);

        expect(failures.has(CORE_ID)).toBe(true);
    });

    /**
     * @scenario core_notice_order=before
     * @effects editor_script_failure_shows_single_plugin_notice
     */
    it('편집기 스타일 실패 안내는 본체 스크립트 안내를 걷지 않는다(다른 자산)', () => {
        failures.set(CORE_ID, { id: CORE_ID, label: '편집기' });

        notifyEditorAssetFailure('ckeditor5-css', '편집기 스타일', () => {});

        expect(failures.has(CORE_ID)).toBe(true);
    });
});
