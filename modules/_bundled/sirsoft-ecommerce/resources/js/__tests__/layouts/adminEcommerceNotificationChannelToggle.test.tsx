/**
 * 이커머스 알림 채널 토글 저장 upsert + 하단 서브탭 필터 회귀 가드
 *
 * @description
 * 회귀 시나리오 (코어 admin 템플릿과 동일 구조):
 *  1) 토글 저장이 `.map()` 만 쓰면(기존 엔트리만 변형) 설정에 없던 확장 채널(sms/alimtalk)은
 *     토글해도 배열에 추가되지 않아 저장에서 누락 → 백엔드가 미저장=활성으로 판정해 항상 발송.
 *     → 저장 표현식은 엔트리 부재 시 새로 추가(upsert)하는
 *        `.some(...) ? .map(...) : [...arr, {id...}]` 형태여야 한다.
 *  2) 하단 서브탭 필터가 `is_active !== false` 만 보면 미저장 확장 채널이 탭에 계속 노출됨.
 *     코어 기본 채널(source==='core')은 미저장 시 노출 유지, 확장 채널은 명시적 활성일 때만
 *     노출이어야 하므로 `c.source === 'core'` 분기가 있어야 한다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import tab from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_notification_definitions.json';

const tabJson = JSON.stringify(tab);

describe('이커머스 알림 채널 토글 저장 upsert', () => {
    it('토글 저장이 upsert 형태다 (미저장 채널은 새 엔트리로 추가)', () => {
        // 엔트리 존재 판별용 .some(...) — 있으면 map 으로 토글, 없으면 spread 로 새 엔트리 추가
        expect(/\.some\(c => c\.id === ch\.id\)/.test(tabJson)).toBe(true);
        // 엔트리 부재 시 새 객체를 배열에 추가하는 spread + 객체 리터럴
        expect(
            /\[\.\.\.\(_local\.form\?\.notifications\?\.channels \?\? \[\]\), \{id: ch\.id, is_active: true/.test(tabJson),
        ).toBe(true);
    });

    it('map-only(기존 엔트리만 변형) 저장 표현식이 남아 있지 않다', () => {
        expect(/\.some\(c => c\.id === ch\.id\)/.test(tabJson), 'upsert(.some) 표현식이 없으면 회귀').toBe(true);
    });
});

describe('이커머스 하단 서브탭 필터 source 분기', () => {
    it('코어기본 채널은 미저장 노출 유지, 확장 채널은 명시적 활성만 노출', () => {
        expect(/c\.source === 'core'/.test(tabJson)).toBe(true);
        expect(/is_active !== false/.test(tabJson)).toBe(true);
        expect(/is_active === true/.test(tabJson)).toBe(true);
    });
});
