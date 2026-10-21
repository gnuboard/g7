/**
 * 알림 채널 「취소」 단추가 임시 채널 값을 실제로 비우는지 (7.1.1 출시 전)
 *
 * setState 기본 병합(deep)은 값이 빈 객체 `{}` 면 기존 객체에 아무 키도 지우지 않는다(엔진 계약 — 중첩 객체 보존).
 * 그래서 `koreaeximChannels: {}` 만 쓰던 취소 단추는 `{ [정의 id]: [...] }` 로 고친 임시 값을 남겨, 스위치가
 * 저장값으로 돌아가지 않았다. 같은 동작 묶음에서 먼저 `null` 로 덮고 다시 `{}` 로 쓴다.
 *
 * 실제 조각 JSON 의 단추 동작을 실제 ActionDispatcher 로 실행해, 병합 결과가 실리는 저장소 B(`_global._local`)와
 * `__g7PendingLocalState` 를 확인한다.
 */

import { afterEach, describe, expect, it } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import card from '../../../layouts/admin/partials/plugin_settings/_notifications_card.json';

type AnyNode = { id?: string; actions?: unknown[]; children?: AnyNode[]; [key: string]: unknown };

/**
 * 트리에서 id 가 같은 노드를 찾습니다.
 *
 * @param node 탐색 시작 노드
 * @param id 찾을 id
 * @returns 찾은 노드 (없으면 null)
 */
function findById(node: unknown, id: string): AnyNode | null {
    if (!node || typeof node !== 'object') return null;
    if ((node as AnyNode).id === id) return node as AnyNode;
    for (const value of Object.values(node as Record<string, unknown>)) {
        const items = Array.isArray(value) ? value : value && typeof value === 'object' ? [value] : [];
        for (const item of items) {
            const found = findById(item, id);
            if (found) return found;
        }
    }
    return null;
}

describe('_notifications_card — 알림 채널 취소 단추', () => {
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
        const button = findById(card, 'koreaexim_cancel_channels_{{defIdx}}');
        expect(button, '취소 단추').not.toBeNull();

        const test = createLayoutTest({ version: '1.0.0', layout_name: 'koreaexim_channels_cancel_probe', components: [] } as any, {
            initialState: { _local: { koreaeximChannels: { 'koreaexim.rate_alert': ['mail'] }, keep: 1 } },
        });
        cleanup = test.cleanup;
        await test.render();

        for (const action of (button!.actions ?? []) as any[]) {
            await test.triggerAction(action);
        }

        const merged = (window as any).__g7PendingLocalState;
        const canonical = test.getState()._global._local;
        for (const local of [merged, canonical]) {
            expect(local?.koreaeximChannels?.['koreaexim.rate_alert']).toBeUndefined();
            expect(local?.koreaeximChannels).toEqual({});
            expect(local?.keep).toBe(1);
        }
    });
});
