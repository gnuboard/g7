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

/**
 * 채널 토글 저장 + 하단 서브탭 필터 회귀 가드 (#28)
 *
 * 회귀 시나리오:
 *  1) 토글 저장이 `.map()` 만 쓰면(기존 엔트리만 변형) 설정에 없던 확장 채널(sms/alimtalk)은
 *     토글해도 배열에 추가되지 않아 저장에서 누락 → 백엔드가 미저장=활성으로 판정해 항상 발송.
 *     → 저장 표현식은 엔트리 부재 시 새로 추가(upsert)하는 `.some(...) ? .map(...) : [...arr, {id...}]`
 *        형태여야 한다.
 *  2) 하단 서브탭 필터가 `is_active !== false` 만 보면 미저장 확장 채널이 탭에 계속 노출됨
 *     (사이트내 알림처럼 사라지지 않음). 코어 기본 채널(source==='core')은 미저장 시 노출 유지,
 *     확장 채널은 미저장 시 숨김이어야 하므로 `c.source === 'core'` 분기가 있어야 한다.
 */
describe('채널 토글 저장 upsert + 하단 서브탭 필터 (#28)', () => {
    // 표현식은 text 뿐 아니라 setState params 키 값 / iteration.source 등에도 있으므로
    // 레이아웃 전체를 직렬화해 표현식 문자열을 검사한다.
    const tabJson = JSON.stringify(notifTab);

    it('채널 토글 저장이 upsert 형태다 (미저장 채널은 새 엔트리로 추가)', () => {
        // 엔트리 존재 판별용 .some(...) — 있으면 map 으로 토글, 없으면 spread 로 새 엔트리 추가
        expect(/\.some\(c => c\.id === ch\.id\)/.test(tabJson)).toBe(true);
        // 엔트리 부재 시 새 객체를 배열에 추가하는 spread + 객체 리터럴
        expect(/\[\.\.\.\(_local\.form\?\.notifications\?\.channels \?\? \[\]\), \{id: ch\.id, is_active: true/.test(tabJson)).toBe(
            true,
        );
    });

    it('토글 저장이 map-only(기존 엔트리만 변형) 형태가 아니다', () => {
        // 옛 표현식: {{(...channels ?? []).map(...)}} 단독. some/spread-add 가 없으면 회귀.
        const hasUpsert = /\.some\(c => c\.id === ch\.id\)/.test(tabJson);
        expect(hasUpsert, 'map-only 저장이면 upsert(.some) 표현식이 없다 → 회귀').toBe(true);
    });

    it('하단 서브탭 필터가 코어기본/확장 채널을 source 로 분기한다', () => {
        // 코어 기본 채널(source==='core')은 미저장 시 노출 유지, 확장 채널은 명시적 활성일 때만 노출
        expect(/c\.source === 'core'/.test(tabJson)).toBe(true);
        expect(/is_active !== false/.test(tabJson)).toBe(true);
        expect(/is_active === true/.test(tabJson)).toBe(true);
    });
});
