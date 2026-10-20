// e2e:allow 레이아웃 정적 구조(회원 검색 요청의 query 인자) 단언 전용 — 서버 거르기는 코어 PHPUnit(`exclude_withdrawn`)이, 브라우저 축은 7.1.1 출시 전 캠페인 재실측(알림 문안 편집 창 「특정 회원」 에서 탈퇴 회원 이름으로 검색해 결과에 없음)이 덮는다.
/**
 * @file notificationRecipientExcludesWithdrawn.test.ts
 * @description 관리자 환경설정 → 알림 문안 편집 창의 「특정 회원」 수신자 고르기는 탈퇴 회원을 검색하지 않는다 (7.1.1, 코어 exclude_withdrawn)
 *
 * 창은 코어 회원 검색(`GET /api/admin/users/search`)으로 수신자를 고른다. 검색은 기본으로 탈퇴 회원도 돌려주는데, 탈퇴 회원은
 * 연락처가 익명화되고 바깥 채널 발송이 막혀 골라도 받지 못한다. 그래서 고르는 자리에서 뺀다(`exclude_withdrawn=1`).
 * 반대로 활동 로그 「행위자」 필터는 탈퇴 회원의 기록을 찾는 자리라 빼지 않는다 — 함께 잠근다.
 *
 * @scenario-manifest tests/scenarios/notification-recipient-excludes-withdrawn.yaml
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import pickerLayout from '../../../layouts/partials/admin_settings/_modal_notification_template_form.json';
import filterLayout from '../../../layouts/partials/admin_activity_log_list/_partial_filter.json';

/**
 * 레이아웃 JSON 에서 코어 회원 검색 요청의 query 를 모은다(apiCall 액션·데이터소스 모두).
 *
 * @param tree 레이아웃 JSON
 * @returns query 객체 목록
 */
function searchQueries(tree: unknown): Array<Record<string, any>> {
    const out: Array<Record<string, any>> = [];
    (function visit(o: any): void {
        if (Array.isArray(o)) return o.forEach(visit);
        if (!o || typeof o !== 'object') return;
        const target = String(o.target ?? o.endpoint ?? '');
        if (target.includes('/api/admin/users/search')) out.push(o.params?.query ?? o.query ?? o.params ?? {});
        Object.values(o).forEach(visit);
    })(tree);

    return out;
}

describe('알림 수신자 고르기는 탈퇴 회원을 검색하지 않는다', () => {
    /**
     * @scenario surface=recipient_picker
     * @effects notification_recipient_picker_excludes_withdrawn
     */
    it('알림 문안 편집 창의 회원 검색은 exclude_withdrawn=1 을 싣는다', () => {
        const queries = searchQueries(pickerLayout);
        expect(queries.length).toBeGreaterThan(0);
        expect(queries.map((q) => String(q.exclude_withdrawn))).toEqual(queries.map(() => '1'));
    });

    /**
     * @scenario surface=record_filter
     * @effects notification_recipient_picker_excludes_withdrawn
     */
    it('활동 로그 「행위자」 필터는 탈퇴 회원의 기록도 찾아야 하므로 빼지 않는다', () => {
        const queries = searchQueries(filterLayout);
        expect(queries.length).toBeGreaterThan(0);
        expect(queries.every((q) => q.exclude_withdrawn === undefined)).toBe(true);
    });
});
