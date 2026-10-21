/**
 * @file pluginSettingsChannelOpenReset.test.ts
 * @description 마케팅 채널 추가·편집 창을 열 때 앞 채널의 이름 문구가 남지 않는다 (7.1.1)
 *
 * 두 여는 자리가 `_global.editingChannel` 을 쓰는데, setState 기본(deep) 병합은 이름(label) 다국어 객체를 합쳐 앞서 연 채널에만
 * 있던 언어 문구(예: ja)를 새 채널·다른 채널에 남겼다 — 확정하면 그 문구가 다른 채널에 저장된다. 여는 쪽이 같은 액션 목록에서
 * 편집 대상을 null 로 먼저 비운다(「채널 추가」 는 객체, 행 「편집」 은 식 `{{{ ...channel }}}` 으로 쓴다).
 *
 * @scenario-manifest tests/scenarios/plugin-settings-channel-open-reset.yaml
 */

import { describe, it, expect } from 'vitest';
import pluginSettings from '../../../layouts/admin/plugin_settings.json';

type Action = { handler?: string; target?: string; params?: Record<string, unknown> };

/**
 * 편집 창을 여는 액션 목록마다, editingChannel 을 값으로 쓰는 setState 앞에 같은 키를 null 로 쓰는 setState 가 있는지 모은다.
 *
 * @returns 여는 목록마다 쓴 값의 종류와 null 선행 여부
 */
function channelOpeners(): Array<{ kind: 'object' | 'expression'; nulledFirst: boolean }> {
    const out: Array<{ kind: 'object' | 'expression'; nulledFirst: boolean }> = [];
    (function visit(o: unknown): void {
        if (Array.isArray(o)) {
            const list = o as Action[];
            if (list.some((a) => a?.handler === 'openModal' && a.target === 'channel_edit_modal')) {
                const at = list.findIndex((a) => a?.handler === 'setState' && a.params?.editingChannel !== undefined && a.params.editingChannel !== null);
                if (at >= 0) {
                    out.push({
                        kind: typeof list[at].params!.editingChannel === 'string' ? 'expression' : 'object',
                        nulledFirst: list.slice(0, at).some((a) => a?.handler === 'setState' && a.params?.editingChannel === null && a.params?.target === 'global'),
                    });
                }
            }
            o.forEach(visit);
        } else if (o && typeof o === 'object') {
            Object.values(o).forEach(visit);
        }
    })(pluginSettings);

    return out;
}

describe('채널 편집 창 — 여는 쪽이 편집 대상을 null 로 먼저 비운다 (7.1.1)', () => {
    // @scenario entry=add_and_edit
    // @effects channel_label_not_carried_over
    it('「채널 추가」(객체)·행 「편집」(식) 모두 null 선행', () => {
        const openers = channelOpeners();
        expect(openers.map((o) => o.kind).sort()).toEqual(['expression', 'object']);
        expect(openers.every((o) => o.nulledFirst)).toBe(true);
    });
});
