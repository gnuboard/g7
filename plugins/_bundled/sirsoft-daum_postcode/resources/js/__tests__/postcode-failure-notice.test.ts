/**
 * 우편번호 SDK 실패 안내가 플러그인 문구 한 건으로 나온다
 *
 * SDK 스크립트는 레이아웃 조각의 `<script id="daum_postcode_script">` 로 실리므로, 끝내 못 불러오면 코어
 * 레이아웃 스크립트 로더도 실패를 따로 알린다(이름 = 스크립트 id 원문). 두 건이 겹치면 코어 안내가 묶음 문구
 * 「2개 항목을 불러오지 못했습니다. daum_postcode_script, 주소 검색」으로 바뀌어 내부 id 가 보이고, 플러그인의
 * 「주소를 직접 입력해 주세요」 문장이 사라졌다(7.1.0 실측 M1-O13). 플러그인 안내가 같은 자산(SDK)을 다시
 * 불러오는 재시도까지 맡으므로, 코어의 같은 자산 안내는 걷는다 — 먼저 와 있든 나중에 오든.
 *
 * @effects postcode_sdk_failure_shows_single_plugin_notice
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { clearPostcodeSdkFailure, notifyPostcodeSdkFailure } from '../handlers/postcodeSdk';

const CORE_ID = 'layout-script:daum_postcode_script';

describe('[case:network-layout-script-duplicate-failure-notice] 우편번호 SDK 실패 안내 — 코어 같은 자산 안내와 겹치지 않는다', () => {
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
        clearPostcodeSdkFailure();
        vi.runOnlyPendingTimers();
        vi.useRealTimers();
        delete (window as any).G7Core;
    });

    /**
     * @scenario core_notice_order=before
     * @effects postcode_sdk_failure_shows_single_plugin_notice
     */
    it('코어 안내가 먼저 와 있으면 걷고 플러그인 안내 한 건만 남긴다', () => {
        failures.set(CORE_ID, { id: CORE_ID, label: 'daum_postcode_script' });

        notifyPostcodeSdkFailure(() => {});

        expect(Array.from(failures.keys())).toEqual(['daum-postcode-sdk']);
        expect(failures.get('daum-postcode-sdk').message).toContain('직접 입력');
    });

    /**
     * @scenario core_notice_order=after
     * @effects postcode_sdk_failure_shows_single_plugin_notice
     */
    it('코어 안내가 나중에 와도 걷는다(코어 재시도 예산이 끝나는 시점이 플러그인 대기와 엇갈린다)', () => {
        notifyPostcodeSdkFailure(() => {});
        failures.set(CORE_ID, { id: CORE_ID, label: 'daum_postcode_script' });

        vi.advanceTimersByTime(1000);

        expect(Array.from(failures.keys())).toEqual(['daum-postcode-sdk']);
    });

    /**
     * @scenario core_notice_order=after
     * @effects postcode_sdk_failure_shows_single_plugin_notice
     */
    it('플러그인 안내를 해제한 뒤에는 코어 안내를 건드리지 않는다', () => {
        notifyPostcodeSdkFailure(() => {});
        clearPostcodeSdkFailure();
        failures.set(CORE_ID, { id: CORE_ID, label: 'daum_postcode_script' });

        vi.advanceTimersByTime(60000);

        expect(failures.has(CORE_ID)).toBe(true);
    });
});
