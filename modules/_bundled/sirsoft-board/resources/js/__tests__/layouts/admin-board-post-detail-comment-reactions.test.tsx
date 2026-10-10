/**
 * 관리자 게시글 상세 — 댓글 반응 수 표시 (#525 WP-D) 회귀.
 *
 * 이 조각이 지키는 계약:
 *  - 관리자 화면은 반응을 **읽기만** 한다. 사용자 화면(`_comment_item.g7.html`)과 달리
 *    버튼·`api-call` 이 없어야 한다 — 관리자가 회원 대신 반응을 남기면 집계가 오염된다.
 *  - 노출은 세 조건의 AND 다(`use_comment_reaction` · 활성 유형 존재 · 댓글 미삭제).
 *    한 조건만 빠져도 반응을 끈 게시판이나 삭제된 댓글에 빈 집계 줄이 남는다.
 *  - 아이콘은 업로드 이미지 → FA 폴백 2단이다(`icon_url` 우선). 두 노드의 `data-if` 가
 *    서로 배타가 아니면 같은 자리에 아이콘이 둘 뜬다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import layout from '../../../layouts/admin/partials/admin_board_post_detail/_comment.json';

const layoutJson = JSON.stringify(layout);

/** 반응 집계 줄 노드를 찾아 돌려준다. 없으면 undefined — 그 자체가 red 사유다. */
function findReactionRow(): any {
    const stack: any[] = [layout];

    while (stack.length > 0) {
        const node = stack.pop();

        if (node === null || typeof node !== 'object') {
            continue;
        }

        if (node?.props?.['data-testid'] === 'comment_reaction_counts') {
            return node;
        }

        for (const value of Object.values(node)) {
            if (value !== null && typeof value === 'object') {
                stack.push(value);
            }
        }
    }

    return undefined;
}

describe('관리자 게시글 상세 — 댓글 반응 수', () => {
    // @scenario case=admin_post_detail_shows_comment_reaction_counts
    // @effects admin_comment_reaction_counts_are_read_only
    it('댓글마다 반응 집계 줄이 있다', () => {
        expect(findReactionRow()).toBeDefined();
    });

    // @scenario case=admin_post_detail_shows_comment_reaction_counts
    // @effects admin_comment_reaction_counts_are_read_only
    it('노출 조건이 토글·활성유형·미삭제 세 가지를 모두 본다', () => {
        // 굽기가 `data-if` 를 노드 최상위 `if` 로 옮긴다 — props 에서 찾으면 항상 빈 문자열이라
        // 조건을 통째로 지워도 통과하는 공허한 단언이 된다.
        const condition = String(findReactionRow()?.if ?? '');

        expect(condition).toContain('use_comment_reaction');
        expect(condition).toContain('reaction_type_options');
        expect(condition).toContain('deleted_at');
    });

    // @scenario case=admin_post_detail_shows_comment_reaction_counts
    // @effects admin_comment_reaction_counts_are_read_only
    it('활성 반응 유형 목록을 그대로 순회한다', () => {
        const row = findReactionRow();

        expect(JSON.stringify(row)).toContain('reaction_type_options');
        // 집계는 댓글의 reaction_counts 를 유형 ID 로 찾아 읽는다.
        expect(JSON.stringify(row)).toContain('reaction_counts');
    });

    // @scenario case=admin_post_detail_shows_comment_reaction_counts
    // @effects admin_comment_reaction_icon_falls_back_to_font_icon
    it('업로드 아이콘과 FA 폴백이 서로 배타다', () => {
        const rowJson = JSON.stringify(findReactionRow());

        // 이미지가 있을 때만 img, 없을 때만 icon — 둘 다 뜨면 아이콘이 겹친다.
        expect(rowJson).toContain('!!rt.icon_url');
        expect(rowJson).toContain('!rt.icon_url');
        expect(rowJson).toContain('fa-thumbs-up');
    });

    // @scenario case=admin_post_detail_shows_comment_reaction_counts
    // @effects admin_comment_reaction_counts_are_read_only
    it('읽기 전용이다 — 반응 등록 요청 경로가 없다', () => {
        // 사용자 화면의 반응 버튼이 쓰는 경로가 관리자 조각에 섞이면 red.
        //
        // 조각 «전문»이 아니라 동작 노드의 «호출 대상»만 본다 — 조각의 설명 주석에는
        // 이 테스트 이름(`…-comment-reactions.test.tsx`)과 「/react 경로 부재로 잠근다」는
        // 근거 문구가 들어 있어, 문자열 전수 검사로는 제품 코드가 옳아도 red 가 된다.
        const actionTargets: string[] = [];
        const stack: any[] = [layout];

        while (stack.length > 0) {
            const node = stack.pop();

            if (node === null || typeof node !== 'object') {
                continue;
            }

            if (typeof node.target === 'string') {
                actionTargets.push(node.target);
            }

            for (const value of Object.values(node)) {
                if (value !== null && typeof value === 'object') {
                    stack.push(value);
                }
            }
        }

        for (const target of actionTargets) {
            expect(target).not.toContain('/react');
        }

        // testid 는 실제 조작 요소에만 붙는다 — 주석에 섞일 일이 없으므로 전문 검사로 둔다.
        expect(layoutJson).not.toContain('comment-react-');
    });
});
