/**
 * @file admin-notification-log-mobile-recipient.test.ts
 * @description 알림 발송 로그 — 좁은 화면 카드에서 수신자 이름·이메일이 말줄임된다 (#17 M1 관찰B)
 *
 * 수신자 칸은 행 메뉴(ActionMenu)가 감싼다. ActionMenu 루트는 `inline-block` 이라 안쪽 글이 `truncate`(줄바꿈 금지)면
 * 최소 폭이 글 전체가 되어 카드 밖으로 넘친다(390 폭 실측 right 395 > 375). 메뉴 루트를 `block max-w-full` 로
 * 카드 칸 폭에 묶어야 안쪽 `min-w-0 truncate` 가 먹는다.
 *
 * 시나리오 매니페스트: tests/scenarios/notification-log-search-filter.yaml
 */
import { describe, it, expect } from 'vitest';
import datagrid from '../../layouts/partials/admin_notification_log_list/_partial_datagrid.json';

/** 조건에 맞는 노드를 모읍니다. */
function collect(node: any, predicate: (n: any) => boolean, out: any[] = []): any[] {
    if (!node || typeof node !== 'object') return out;
    if (Array.isArray(node)) {
        node.forEach((n) => collect(n, predicate, out));
        return out;
    }
    if (predicate(node)) out.push(node);
    Object.values(node).forEach((v) => collect(v, predicate, out));
    return out;
}

describe('알림 발송 로그 수신자 칸 말줄임', () => {
    // @scenario case=mobile_recipient_truncate
    // @effects recipient_cell_fits_card
    it('truncate 글을 감싼 행 메뉴는 block max-w-full 로 칸 폭에 묶인다', () => {
        const menus = collect(datagrid, (n) => n.name === 'ActionMenu' && collect(n.children ?? [], (c) => /\btruncate\b/.test(String(c.props?.className ?? ''))).length > 0);
        expect(menus.length).toBeGreaterThan(0);
        for (const menu of menus) {
            const cls = String(menu.props?.className ?? '');
            expect(cls, menu.id ?? 'ActionMenu').toMatch(/(^|\s)block(\s|$)/);
            expect(cls).toMatch(/(^|\s)max-w-full(\s|$)/);
        }
    });
});
