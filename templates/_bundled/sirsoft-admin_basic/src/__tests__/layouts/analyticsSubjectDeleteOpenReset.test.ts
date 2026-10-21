/**
 * @file analyticsSubjectDeleteOpenReset.test.ts
 * @description 정보주체 삭제 창을 방문자 상세에서 열 때 앞서 정보주체 화면에서 연 기준(IP·회원·기간)이 남지 않는다 (7.1.1)
 *
 * 방문자 상세의 「삭제」 는 지울 기준 `_global.analyticsSubject` 를 `{visitor_key}` 부분 객체로 썼다. setState 기본(deep) 병합은 이번 객체에
 * 없는 키를 남기므로, 같은 세션에서 정보주체 화면(IP·기간으로 검색)에서 창을 열었던 뒤라면 IP·기간이 방문자 키와 함께 실려
 * 서버가 AND 로 묶어(`VisitRepository::findBySubject`) 삭제가 덜 지워지거나 0건이 됐다. 여는 쪽이 같은 액션 목록에서 null 로 먼저 비운다.
 *
 * @scenario-manifest tests/scenarios/analytics-subject-delete-open-reset.yaml
 */

import { describe, it, expect } from 'vitest';
import visitorDetail from '../../../layouts/admin_analytics_visitor_detail.json';
import privacyRequests from '../../../layouts/admin_analytics_privacy_requests.json';

type Action = { handler?: string; target?: string; params?: Record<string, unknown> };

/**
 * 창(modal)을 여는 액션 목록마다, 상태 key 를 객체로 쓰는 setState 앞에 같은 대상·같은 키를 null 로 쓰는 setState 가 있는지 모은다.
 *
 * @param layout 굽기 산출 JSON
 * @param modal 여는 창 id
 * @param key 상태 키
 * @returns 여는 목록마다 객체로 쓴 키 목록과 null 선행 여부
 */
function openersOf(layout: unknown, modal: string, key: string): Array<{ keys: string[]; nulledFirst: boolean }> {
    const out: Array<{ keys: string[]; nulledFirst: boolean }> = [];
    (function visit(o: unknown): void {
        if (Array.isArray(o)) {
            const list = o as Action[];
            if (list.some((a) => a?.handler === 'openModal' && a.target === modal)) {
                const at = list.findIndex((a) => a?.handler === 'setState' && !!a.params?.[key] && typeof a.params[key] === 'object');
                if (at >= 0) {
                    const target = list[at].params!.target;
                    out.push({
                        keys: Object.keys(list[at].params![key] as object),
                        nulledFirst: list.slice(0, at).some((a) => a?.handler === 'setState' && a.params?.[key] === null && a.params?.target === target),
                    });
                }
            }
            o.forEach(visit);
        } else if (o && typeof o === 'object') {
            Object.values(o).forEach(visit);
        }
    })(layout);

    return out;
}

describe('정보주체 삭제 창 — 여는 쪽이 지울 기준을 매번 새로 쓴다 (7.1.1)', () => {
    // @scenario entry=visitor_detail
    // @effects subject_criteria_reset_on_open
    it('방문자 상세의 「삭제」 는 analyticsSubject 를 null 로 먼저 비우고 방문자 키를 쓴다', () => {
        const openers = openersOf(visitorDetail, 'analytics_subject_delete_modal', 'analyticsSubject');
        expect(openers).toHaveLength(1);
        expect(openers[0].keys).toEqual(['visitor_key']);
        expect(openers[0].nulledFirst).toBe(true);
    });

    // @scenario entry=privacy_requests
    // @effects subject_criteria_reset_on_open
    it('정보주체 화면의 「삭제」 는 기준 다섯 칸을 모두 쓴다(부분 객체가 아니어서 null 선행이 없어도 남는 키가 없다)', () => {
        const openers = openersOf(privacyRequests, 'analytics_subject_delete_modal', 'analyticsSubject');
        expect(openers).toHaveLength(1);
        expect([...openers[0].keys].sort()).toEqual(['from', 'ip', 'to', 'user_id', 'visitor_key']);
    });
});
