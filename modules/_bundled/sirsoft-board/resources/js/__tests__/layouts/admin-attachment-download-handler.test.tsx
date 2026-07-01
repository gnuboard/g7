/**
 * @file admin-attachment-download-handler.test.tsx
 * @description 관리자 게시글/답변 첨부 다운로드 카드가 토큰 동반 핸들러로 다운로드하는지 회귀 (이슈 #413 item 58b)
 *
 * 검증 방식: 레이아웃 JSON 트리 직접 분석 (DOM 비의존).
 *
 * 결함: 관리자 게시글/답변 첨부 카드(id: "attachment_card")가
 *   <A href="{{attachment.download_url}}"> 브라우저 직접 링크였다. download_url 은 사용자
 *   라우트(optional.sanctum)를 가리키며, <a> 네비게이션에는 토큰이 실리지 않아
 *   활동이력 행위자(user_id)가 NULL 로 남았다(사용자 카드와 동일 원인).
 *
 * 수정: 카드를 Div + actions:[{click → custom:downloadAttachment}] 로 전환해
 *   코어 ApiClient(G7Core.api.get)를 거쳐 관리자 회원 ID 가 활동이력에 기록되도록 한다.
 *
 * @scenario card=admin_post
 * @scenario card=admin_reply
 * @effects download_card_uses_handler_not_anchor
 */

import { describe, it, expect } from 'vitest';

import postCard from '../../../layouts/admin/partials/admin_board_post_detail/_post_card_content.json';
import replyCard from '../../../layouts/admin/partials/admin_board_post_detail/_reply_card_content.json';

type Node = Record<string, unknown> & { children?: unknown; actions?: unknown };

function collectNodes(node: unknown, predicate: (n: Node) => boolean): Node[] {
    const result: Node[] = [];
    const walk = (cur: unknown): void => {
        if (Array.isArray(cur)) {
            cur.forEach(walk);
            return;
        }
        if (cur && typeof cur === 'object') {
            const obj = cur as Node;
            if (predicate(obj)) {
                result.push(obj);
            }
            Object.values(obj).forEach(walk);
        }
    };
    walk(node);
    return result;
}

function findAttachmentCards(root: unknown): Node[] {
    return collectNodes(root, (n) => n.id === 'attachment_card');
}

// @scenario card=admin_post
// @scenario card=admin_reply
describe.each([
    ['게시글', postCard],
    ['답변', replyCard],
])('이슈 #413-58b — 관리자 %s 첨부 다운로드 핸들러 전환', (_label, layout) => {
    it('첨부 카드는 <A href> 직접 링크가 아니다 (토큰 미동반 회귀 차단)', () => {
        const cards = findAttachmentCards(layout);
        expect(cards.length).toBeGreaterThan(0);

        for (const card of cards) {
            const props = (card.props ?? {}) as Record<string, unknown>;
            const isAnchorHref = card.name === 'A' && typeof props.href === 'string';
            expect(isAnchorHref).toBe(false);
        }
    });

    it('첨부 카드는 click 액션으로 downloadAttachment 핸들러를 호출한다', () => {
        const cards = findAttachmentCards(layout);
        expect(cards.length).toBeGreaterThan(0);

        const card = cards[0];
        const actions = (card.actions ?? []) as Array<Record<string, unknown>>;
        const clickAction = actions.find((a) => a.type === 'click');

        expect(clickAction).toBeDefined();
        // 짧은 이름으로 등록된 핸들러는 handler 에 핸들러명을 직접 둔다
        // (redirectToLoginWithReturn 과 동일 형태). custom+name 은 prefix 풀네임 등록용.
        expect(clickAction?.handler).toBe('downloadAttachment');

        const params = (clickAction?.params ?? {}) as Record<string, unknown>;
        expect(params.url).toContain('download_url');
        expect(params.filename).toContain('original_filename');
    });
});
