/**
 * @file pluginSettingsChannelLabelLocale.test.ts
 * @description 마케팅 설정 화면 — 시스템 채널 이름이 언어팩으로 늘어난 화면 언어에서도 그 언어로 보인다
 *
 * 설정 화면의 채널 목록은 저장된 `label` 맵을 그대로 읽는다. 시스템 채널(email_subscription)의 저장 맵은 저장
 * 당시 로케일(ko/en)만 담고 있어, ja 언어팩을 켠 관리자 화면에서 「광고성 이메일 수신」(한국어)으로 남았다
 * (2026-10-07 실측). 시스템 채널은 플러그인 번역(`sirsoft-marketing.{key}`)을 보강으로 쓴다 — 운영자가 만든
 * 채널은 키가 번역 키와 우연히 겹칠 수 있으므로 저장 맵만 쓴다.
 */

import { describe, expect, it } from 'vitest';
import pluginSettings from '../../../layouts/admin/plugin_settings.json';
import { flattenNodes } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

/** 화면에서 채널 이름을 그리는 노드의 텍스트 전량 */
const labelTexts = flattenNodes(pluginSettings)
    .map((node: any) => String(node?.text ?? ''))
    .filter((text) => text.includes('channel.label') || text.includes('blockedChannel.label'));

describe('설정 화면 채널 이름 — 추가된 화면 언어', () => {
    /**
     * @scenario case=saved
     * @effects system_channel_label_follows_added_locale
     */
    it('채널 목록 행이 시스템 채널은 플러그인 번역으로 보강한다', () => {
        const row = labelTexts.find((text) => text.includes('channel.label') && !text.includes('blockedChannel'));
        expect(row).toBeDefined();
        expect(row).toContain("$localized(channel.label, 'sirsoft-marketing.' + channel.key)");
        expect(row).toContain('channel.is_system');
    });

    /**
     * @scenario case=channel_has_consents
     * @effects system_channel_label_follows_added_locale
     */
    it('「채널을 삭제할 수 없습니다」 창의 채널 이름도 같은 규칙을 따른다', () => {
        const blocked = labelTexts.find((text) => text.includes('blockedChannel.label'));
        expect(blocked).toBeDefined();
        expect(blocked).toContain("$localized(blockedChannel.label, 'sirsoft-marketing.' + blockedChannel.key)");
    });
});
