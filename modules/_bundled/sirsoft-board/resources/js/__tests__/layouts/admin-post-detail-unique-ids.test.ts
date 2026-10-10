// e2e:allow Vitest 레이아웃 계약 — 관리자 글 상세의 중복 id 는 7.1.0 실측 K4(O-KF3-3)에서 Playwright 로 DOM 의 id 중복 수를 재측정했다.
/**
 * 관리자 게시글 상세 — 반복해서 그려지는 댓글·답글 조각의 요소 id 가 겹치지 않는다.
 *
 * @description
 * 댓글(`_comment`)은 평평한 댓글 배열의 반복 안에서, 답글 카드(`_reply_card_content`)는 답변글 배열의 반복 안에서
 * 그려진다. 두 조각의 요소 id(data-ref)가 고정 문자열이라 댓글 4개·답글 2개인 상세에 author_name_menu 5개,
 * reply_button·title_text·new_badge 각 3개 등 20종이 겹쳤다(7.1.0 실측 K4, 관측 O-KF3-3). 같은 id 가 여러 번이면
 * 화면 낭독기·aria 참조·자동화 도구가 엉뚱한 요소를 가리킨다. K3 후속(KF3-8)이 첨부 토글만 고유 id 로 바꿨고,
 * 나머지도 같은 방식(댓글은 `comment.id`, 답글은 `item.id` 를 id 에 넣음)으로 맞춘다.
 */

import { describe, it, expect } from 'vitest';
import commentPartial from '../../../layouts/admin/partials/admin_board_post_detail/_comment.json';
import replyCard from '../../../layouts/admin/partials/admin_board_post_detail/_reply_card_content.json';

/**
 * 트리의 모든 컴포넌트 노드 id 를 모은다 — 컴포넌트 노드(`type`·`name` 을 가진 것)의 id 만 DOM id 가 된다.
 * 행 메뉴 항목처럼 props 안 배열의 `id`(항목 식별자)는 DOM 에 나오지 않으므로 세지 않는다.
 *
 * @param node 시작 노드
 * @param out 결과 누적 배열
 * @returns id 목록
 */
function collectIds(node: any, out: string[] = []): string[] {
    if (!node || typeof node !== 'object') return out;
    if (Array.isArray(node)) {
        node.forEach((n) => collectIds(n, out));

        return out;
    }
    if (typeof node.id === 'string' && typeof node.type === 'string' && typeof node.name === 'string') out.push(node.id);
    Object.values(node).forEach((v) => collectIds(v, out));

    return out;
}

describe('관리자 게시글 상세 반복 조각 id', () => {
    // @scenario fragment=comment
    // @effects admin_detail_repeated_ids_unique
    it('댓글 조각의 모든 요소 id 가 댓글 식별자를 품는다', () => {
        const ids = collectIds(commentPartial);
        expect(ids.length).toBeGreaterThan(10);
        for (const id of ids) {
            expect(id, id).toContain('{{comment.id}}');
        }
    });

    // @scenario fragment=reply
    // @effects admin_detail_repeated_ids_unique
    it('답글 카드 조각의 모든 요소 id 가 답글 식별자를 품는다', () => {
        const ids = collectIds(replyCard);
        expect(ids.length).toBeGreaterThan(10);
        for (const id of ids) {
            expect(id, id).toContain('{{item.id}}');
        }
    });
});
