/**
 * 알림 채널 「취소」 단추가 임시 채널 값을 실제로 비우는지 (7.1.1 출시 전)
 *
 * setState 기본 병합(deep)은 값이 빈 객체 `{}` 면 기존 객체에 아무 키도 지우지 않는다(엔진 계약 — 중첩 객체 보존).
 * 그래서 `gdprChannels: {}` 만 쓰던 취소 단추는 `{ [정의 id]: [...] }` 로 고친 임시 값을 남겨, 스위치가 저장값으로
 * 돌아가지 않았다. 같은 동작 묶음에서 먼저 `null` 로 덮고 다시 `{}` 로 쓴다.
 *
 * 실제 레이아웃 JSON 의 단추 동작을 실제 ActionDispatcher 로 실행해 확인한다.
 */

import { afterEach, describe, expect, it } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import settings from '../../../layouts/admin/plugin_settings.json';
import { findById, type AnyNode } from './helpers';

describe('plugin_settings — 알림 채널 취소 단추', () => {
    let cleanup: (() => void) | null = null;

    afterEach(() => {
        cleanup?.();
        cleanup = null;
    });

    /**
     * @scenario surface=notification_channels_card
     * @effects channel_cancel_clears_pending_values, channel_cancel_keeps_other_local_state
     */
    it('고친 채널 값을 비워 저장값(def.channels)으로 되돌린다', async () => {
        const button = findById(settings as unknown as AnyNode, 'gdpr_cancel_channels_{{defIdx}}');
        expect(button, '취소 단추').not.toBeNull();

        const test = createLayoutTest({ version: '1.0.0', layout_name: 'gdpr_channels_cancel_probe', components: [] } as any, {
            initialState: { _local: { gdprChannels: { 'gdpr.annual_notice': ['mail'] }, keep: 1 } },
        });
        cleanup = test.cleanup;
        await test.render();

        for (const action of (button!.actions ?? []) as any[]) {
            await test.triggerAction(action);
        }

        // 컴포넌트 setState 는 저장소 A 에 변경분만 넘기고, 병합 결과(deep)는 저장소 B(_global._local)와
        // __g7PendingLocalState 에 싣는다 — 실제 병합 규칙이 보이는 쪽은 이 둘이다.
        const merged = (window as any).__g7PendingLocalState;
        const canonical = test.getState()._global._local;
        for (const local of [merged, canonical]) {
            expect(local?.gdprChannels?.['gdpr.annual_notice']).toBeUndefined();
            expect(local?.gdprChannels).toEqual({});
            expect(local?.keep).toBe(1);
        }
    });
});
