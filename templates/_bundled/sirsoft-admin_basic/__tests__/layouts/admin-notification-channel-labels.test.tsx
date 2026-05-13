/**
 * @file admin-notification-channel-labels.test.tsx
 * @description 알림 채널 라벨 노출 회귀 가드
 *
 * 회귀 시나리오: 백엔드(NotificationChannelService::getAvailableChannels)는 활성 locale 기준으로
 *   해석된 string 을 `name` / `description` / `source_label` 로 반환한다 (registry payload name_key 계약, 7.0.0-beta.4+).
 *   레이아웃이 stale 한 다국어 객체 가정(`ch.name?.[$locale] ?? ch.name?.ko`)으로 바인딩하면
 *   string 에 `?.ko` 등이 적용되어 모두 undefined → fallback 식별자(mail / database) 노출.
 *
 * 가드 대상 (코어 admin 템플릿):
 *   - admin_notification_log_list.json (채널 탭)
 *   - partials/admin_settings/_tab_notification_definitions.json (채널 토글 + 서브탭)
 *   - partials/admin_settings/_modal_notification_template_form.json (편집 모달 헤더)
 */

import { describe, it, expect } from 'vitest';

const notifLogList = require('../../layouts/admin_notification_log_list.json');
const notifTab = require('../../layouts/partials/admin_settings/_tab_notification_definitions.json');
const notifModal = require('../../layouts/partials/admin_settings/_modal_notification_template_form.json');

function collectTextStrings(node: any): string[] {
    const result: string[] = [];
    const visit = (n: any) => {
        if (!n || typeof n !== 'object') return;
        if (Array.isArray(n)) {
            n.forEach(visit);
            return;
        }
        if (typeof n.text === 'string') result.push(n.text);
        if (n.children) visit(n.children);
        if (n.actions) visit(n.actions);
        if (n.cellChildren) visit(n.cellChildren);
        if (n.expandChildren) visit(n.expandChildren);
        if (n.params) visit(n.params);
        if (n.onSuccess) visit(n.onSuccess);
        if (n.onError) visit(n.onError);
        if (n.slots) visit(Object.values(n.slots));
        if (n.modals) visit(n.modals);
    };
    visit(node);
    return result;
}

const layouts = [
    { name: 'admin_notification_log_list', layout: notifLogList },
    { name: '_tab_notification_definitions', layout: notifTab },
    { name: '_modal_notification_template_form', layout: notifModal },
];

describe('알림 채널 라벨 — registry payload string contract 회귀 가드', () => {
    for (const { name, layout } of layouts) {
        describe(name, () => {
            const texts = collectTextStrings(layout);

            it('stale 다국어 객체 접근 패턴(?.[$locale])이 채널 필드에 사용되지 않는다', () => {
                const offenders = texts.filter((t) =>
                    /\bch\.(name|source_label|description)\s*\?\.\s*\[\s*\$locale\s*\]/.test(t),
                );
                expect(offenders).toEqual([]);
            });

            it('stale 다국어 객체 접근 패턴(?.ko fallback)이 채널 필드에 사용되지 않는다', () => {
                const offenders = texts.filter((t) =>
                    /\bch\.(name|source_label|description)\s*\?\.\s*ko\b/.test(t),
                );
                expect(offenders).toEqual([]);
            });

            it('availableChannels/notificationChannels 항목 .name 접근에도 stale 패턴 없음', () => {
                const offenders = texts.filter((t) =>
                    /\)\s*\?\.name\s*\?\.\s*\[\s*\$locale\s*\]/.test(t),
                );
                expect(offenders).toEqual([]);
            });
        });
    }

    it('채널 라벨 바인딩이 string 직접 사용 패턴으로 표현된다 (ch.name ?? ch.id)', () => {
        const allTexts = layouts.flatMap(({ layout }) => collectTextStrings(layout));
        const stringContractBindings = allTexts.filter((t) => /\bch\.name\s*\?\?\s*ch\.id\b/.test(t));
        expect(stringContractBindings.length).toBeGreaterThan(0);
    });
});
