/**
 * @file board-attachment-download-handler.test.tsx
 * @description 게시판 첨부 다운로드 카드가 토큰 동반 핸들러로 다운로드하는지 회귀 (이슈 #413 item 58b)
 *
 * 검증 방식: 레이아웃 JSON 트리 직접 분석 (DOM 비의존).
 *
 * 결함: 권한 있을 때 첨부 카드가 <A href="{{attachment?.download_url}}"> 브라우저 직접 링크였다.
 *   G7 은 Sanctum 토큰 전용 인증인데 <a> 네비게이션에는 토큰이 실리지 않아
 *   optional.sanctum 라우트가 guest 로 통과 → 활동이력 행위자(user_id)가 NULL 로 남았다.
 *
 * 수정: 권한 있을 때 카드를 Div + actions:[{click → custom:downloadAttachment}] 로 전환해
 *   코어 ApiClient(G7Core.api.get)를 거쳐 토큰을 동반한 요청을 보낸다.
 *   권한 없을 때 카드(download_no_permission 토스트)는 그대로 유지한다.
 *
 * @scenario card=user_post
 * @effects download_card_uses_handler_not_anchor,no_permission_toast_preserved
 */

import { describe, it, expect } from 'vitest';

import postAttachments from '../../layouts/partials/board/show/_post_attachments.json';

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

function findByComment(root: unknown, commentText: string): Node[] {
    return collectNodes(
        root,
        (n) => typeof n.comment === 'string' && (n.comment as string).includes(commentText),
    );
}

describe('이슈 #413-58b — 사용자 게시판 첨부 다운로드 핸들러 전환 (_post_attachments.json)', () => {
    it('권한 있을 때 카드는 <A href> 직접 링크가 아니다 (토큰 미동반 회귀 차단)', () => {
        const cards = findByComment(postAttachments, '권한 있을 때');
        expect(cards.length).toBeGreaterThan(0);

        for (const card of cards) {
            const props = (card.props ?? {}) as Record<string, unknown>;
            // A 태그 + href 직접 링크면 토큰이 실리지 않아 user_id 가 NULL 로 남는다.
            const isAnchorHref = card.name === 'A' && typeof props.href === 'string';
            expect(isAnchorHref).toBe(false);
        }
    });

    it('권한 있을 때 카드는 click 액션으로 downloadAttachment 핸들러를 호출한다', () => {
        const cards = findByComment(postAttachments, '권한 있을 때');
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

    it('권한 없을 때 카드의 download_no_permission 토스트 분기는 유지된다', () => {
        const cards = findByComment(postAttachments, '권한 없을 때');
        expect(cards.length).toBeGreaterThan(0);

        const toastActions = collectNodes(
            cards,
            (n) =>
                Array.isArray(n.actions) &&
                (n.actions as Array<Record<string, unknown>>).some(
                    (a) =>
                        a.handler === 'toast' &&
                        typeof (a.params as Record<string, unknown>)?.message === 'string' &&
                        ((a.params as Record<string, unknown>).message as string).includes(
                            'download_no_permission',
                        ),
                ),
        );
        expect(toastActions.length).toBeGreaterThan(0);
    });
});
