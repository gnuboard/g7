/**
 * 편집기 본체 대체 로드 회복 뒤 코어 실패 안내 정리
 *
 * 코어 레이아웃 스크립트 로더가 본체(`ckeditor5_script`)를 끝내 못 실으면 「편집기 불러오기에 실패했습니다」
 * (`layout-script:ckeditor5_script`)를 띄운다. 그 뒤 플러그인이 본체를 직접 실어 편집기를 세우면 그 안내는 사실이
 * 아니다 — 남겨 두면 편집기가 멀쩡한 화면에 실패 띠가 상시 뜬다. 하위 폴더 설치에서 첫 요청이 사이트 밖으로 나가
 * 실패하고 플러그인 재시도로 편집기가 뜬 뒤에도 띠가 남았다.
 *
 * @scenario asset_class=vendored, outcome=failed
 * @effects editor_vendor_recovery_withdraws_core_script_notice
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ensureCkeditorGlobal } from '../../handlers/ckeditorGlobal';

const UMD_EXT = '/api/plugins/assets/sirsoft-ckeditor5/dist/vendor/ckeditor5/43.3.1/ckeditor5.umd.js';
const CORE_ID = 'layout-script:ckeditor5_script';

/** UMD 실행을 흉내 낸다 — 첫 실행만 전역을 채운다. */
function executeUmd(): void {
    const w = window as any;
    const executedBefore = typeof w.CKEDITOR_VERSION === 'string';
    w.CKEDITOR = {};
    if (executedBefore) return;
    w.CKEDITOR_VERSION = '43.3.1';
    w.CKEDITOR.ClassicEditor = class ClassicEditor {};
}

describe('ensureCkeditorGlobal — 대체 로드 회복 뒤 코어 실패 안내', () => {
    let loadScript: ReturnType<typeof vi.fn>;
    let failures: Array<{ id: string }>;
    let clearFailure: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        loadScript = vi.fn(async () => {
            await Promise.resolve();
            executeUmd();
        });
        failures = [{ id: CORE_ID }, { id: 'other-asset' }];
        clearFailure = vi.fn((id: string) => {
            const index = failures.findIndex((f) => f.id === id);
            if (index >= 0) failures.splice(index, 1);
        });
        (window as any).G7Core = {
            asset: {
                plugin: (_id: string, path: string) => `/g7/api/plugins/assets/sirsoft-ckeditor5/${path}`,
                loadScript,
            },
            assets: { getFailures: () => [...failures], clearFailure },
        };
    });

    afterEach(() => {
        delete (window as any).G7Core;
        delete (window as any).CKEDITOR;
        delete (window as any).CKEDITOR_VERSION;
        document.querySelectorAll('script').forEach((el) => el.remove());
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    /**
     * @scenario asset_class=vendored, outcome=failed
     * @effects editor_vendor_recovery_withdraws_core_script_notice
     */
    it('코어 로더 실패 뒤 직접 실어 회복하면 같은 본체의 코어 실패 안내만 걷는다 (늦게 온 안내 포함)', async () => {
        vi.useFakeTimers();
        const pending = document.createElement('script');
        pending.setAttribute('src', UMD_EXT);
        document.head.appendChild(pending);

        const result = ensureCkeditorGlobal();
        await Promise.resolve();
        pending.dispatchEvent(new Event('error'));
        pending.remove();
        await vi.advanceTimersByTimeAsync(3000);

        await expect(result).resolves.toBe(true);
        expect(loadScript).toHaveBeenCalledTimes(1);
        expect(clearFailure).toHaveBeenCalledWith(CORE_ID);
        expect(failures.map((f) => f.id)).toEqual(['other-asset']);

        // 코어 안내가 회복 직후에 늦게 도착해도 짧은 감시 동안 걷는다
        failures.push({ id: CORE_ID });
        await vi.advanceTimersByTimeAsync(1000);
        expect(failures.map((f) => f.id)).toEqual(['other-asset']);
    });

    /**
     * @scenario asset_class=vendored, outcome=failed
     * @effects editor_vendor_recovery_withdraws_core_script_notice
     */
    it('직접 싣기도 실패하면(편집기를 못 세움) 코어 실패 안내를 그대로 둔다', async () => {
        loadScript.mockImplementation(async () => {
            throw new Error('404');
        });
        vi.spyOn(console, 'warn').mockImplementation(() => {});

        await expect(ensureCkeditorGlobal()).resolves.toBe(false);
        expect(clearFailure).not.toHaveBeenCalled();
        expect(failures.map((f) => f.id)).toContain(CORE_ID);
    });
});
