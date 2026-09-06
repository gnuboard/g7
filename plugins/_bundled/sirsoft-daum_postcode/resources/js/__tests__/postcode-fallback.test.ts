/**
 * 우편번호 SDK 미확보 시의 입력 가능 상태 보장
 *
 * 종전에는 `setFieldReadOnly` 가 SDK 확보 여부와 무관하게 마운트 시점에 필드를
 * 읽기 전용으로 고정했다. 검색 버튼도 SDK 가 없으면 무반응이라, 둘이 겹치면
 * 주소를 검색할 수도 직접 입력할 수도 없어 주문을 끝낼 수 없었다.
 *
 * @scenario asset_class=service_sdk, outcome=failed
 * @effects address_fields_editable_when_sdk_missing, failed_asset_shows_retry_notice
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { setFieldReadOnlyHandler } from '../handlers/setFieldReadOnly';
import { isPostcodeSdkReady, waitForPostcodeSdk } from '../handlers/postcodeSdk';

describe('우편번호 SDK 미확보 시 주소 입력 가능성', () => {
    let notifyFailure: ReturnType<typeof vi.fn>;
    let clearFailure: ReturnType<typeof vi.fn>;

    /**
     * 우편번호·주소 입력 필드를 DOM 에 세웁니다.
     *
     * @return 생성된 입력 요소들
     */
    function mountFields(): HTMLInputElement[] {
        return ['zipcode', 'address'].map(name => {
            const input = document.createElement('input');
            input.name = name;
            document.body.appendChild(input);

            return input;
        });
    }

    beforeEach(() => {
        notifyFailure = vi.fn();
        clearFailure = vi.fn();
        (window as any).G7Core = {
            assets: { notifyFailure, clearFailure },
        };
        delete (window as any).daum;
    });

    afterEach(() => {
        document.body.innerHTML = '';
        delete (window as any).G7Core;
        delete (window as any).daum;
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    /**
     * @scenario asset_class=service_sdk, outcome=loaded
     * @effects runtime_asset_served_same_origin
     */
    it('SDK 가 확보되면 종전대로 읽기 전용을 적용한다', async () => {
        (window as any).daum = { Postcode: function () {} };
        const inputs = mountFields();

        await setFieldReadOnlyHandler(
            { handler: 'setFieldReadOnly', params: { fields: ['zipcode', 'address'], readOnly: true } },
            {} as any
        );

        expect(inputs.every(input => input.readOnly)).toBe(true);
        expect(inputs.every(input => input.classList.contains('readonly'))).toBe(true);
        expect(notifyFailure).not.toHaveBeenCalled();
    });

    it('SDK 를 끝내 못 불러오면 읽기 전용을 걸지 않고 안내를 띄운다', async () => {
        const inputs = mountFields();

        // 대기 예산을 짧게 줘서 "끝내 안 왔다" 상태를 즉시 만든다
        await setFieldReadOnlyHandler(
            {
                handler: 'setFieldReadOnly',
                params: { fields: ['zipcode', 'address'], readOnly: true, sdkWaitMs: 150 },
            },
            {} as any
        );

        expect(inputs.some(input => input.readOnly)).toBe(false);
        expect(notifyFailure).toHaveBeenCalledTimes(1);

        const [failure] = notifyFailure.mock.calls[0];

        expect(failure.id).toBe('daum-postcode-sdk');
        expect(typeof failure.retry).toBe('function');
    });

    it('읽기 전용 해제는 SDK 를 기다리지 않는다 (해제는 언제나 안전)', async () => {
        const inputs = mountFields();
        inputs.forEach(input => {
            input.readOnly = true;
            input.classList.add('readonly');
        });

        await setFieldReadOnlyHandler(
            { handler: 'setFieldReadOnly', params: { fields: ['zipcode', 'address'], readOnly: false } },
            {} as any
        );

        expect(inputs.some(input => input.readOnly)).toBe(false);
        expect(inputs.some(input => input.classList.contains('readonly'))).toBe(false);
        expect(notifyFailure).not.toHaveBeenCalled();
    });

    describe('SDK 확보 판정', () => {
        it('전역이 없으면 미확보', () => {
            expect(isPostcodeSdkReady()).toBe(false);
        });

        it('전역이 있으면 확보', () => {
            (window as any).daum = { Postcode: function () {} };

            expect(isPostcodeSdkReady()).toBe(true);
        });

        it('대기 중에 전역이 생기면 확보로 판정한다', async () => {
            const waiting = waitForPostcodeSdk(2000);

            setTimeout(() => {
                (window as any).daum = { Postcode: function () {} };
            }, 150);

            await expect(waiting).resolves.toBe(true);
        });

        it('시간 안에 오지 않으면 미확보로 판정한다', async () => {
            await expect(waitForPostcodeSdk(200)).resolves.toBe(false);
        });
    });
});
