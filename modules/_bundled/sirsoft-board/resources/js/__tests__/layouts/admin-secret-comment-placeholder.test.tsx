/**
 * 관리자 게시글 상세 - 가려진 비밀 댓글 안내 회귀 테스트 (KVE-2026-2371 후속)
 *
 * @description
 * 서버(CommentResource)는 비밀 댓글을 열람 권한 없는 요청에 content=null + is_secret=true 로 보낸다.
 * 관리자 화면에서 그 요청자는 「비밀글 조회(관리자)」·「게시판 관리(관리자)」 없이 「게시글 조회(관리자)」만
 * 가진 역할이다. 안내 분기가 없으면 그 댓글 자리가 자물쇠도 문구도 없는 빈 칸으로 보인다 — 사용자
 * 화면(sirsoft-basic)에 안내를 넣은 것과 같은 이유다.
 *
 * 분기 계약:
 *   - secret_comment_content (안내): 블라인드·직접 삭제가 아니고 is_secret && content === null
 *   - normal_comment_content (원문): 위 조합을 제외 — 권한자(content 가 실려 옴)는 종전대로 원문
 *
 * @scenario viewer=restricted_admin
 * @effects admin_secret_comment_placeholder_rendered
 */

import { describe, it, expect } from 'vitest';

import commentPartial from '../../../layouts/admin/partials/admin_board_post_detail/_comment.json';
import koPosts from '../../../lang/partial/ko/admin/posts.json';
import enPosts from '../../../lang/partial/en/admin/posts.json';

const MASKED = 'comment?.is_secret && comment?.content === null';
const NOTICE_KEY = '$t:sirsoft-board.admin.posts.detail.secret_comment_notice';

/**
 * JSON 트리에서 주어진 id 의 노드를 찾습니다.
 *
 * @param node 탐색 시작 노드
 * @param id 찾을 노드 id
 * @returns 일치하는 노드 또는 null
 */
function findById(node: any, id: string): any {
    if (!node || typeof node !== 'object') return null;
    // 댓글 조각은 반복 안에서 그려져 id 에 댓글 식별자 접미(`_{{comment.id}}`)가 붙는다(7.1.0 실측 K4 O-KF3-3)
    if (node.id === id || node.id === `${id}_{{comment.id}}`) return node;

    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            const found = findById(child, id);
            if (found) return found;
        }
    }
    if (node.slots) {
        for (const slotChildren of Object.values(node.slots)) {
            if (Array.isArray(slotChildren)) {
                for (const child of slotChildren) {
                    const found = findById(child as any, id);
                    if (found) return found;
                }
            }
        }
    }
    return null;
}

describe('관리자 댓글 - 가려진 비밀 댓글 안내 (KVE-2026-2371 후속)', () => {
    const secretNode = findById(commentPartial, 'secret_comment_content');
    const normalNode = findById(commentPartial, 'normal_comment_content');

    // @scenario viewer=restricted_admin
    // @effects admin_secret_comment_placeholder_rendered
    it('안내 노드는 is_secret + content===null 일 때만 그려지고 자물쇠와 안내 문구 키를 쓴다', () => {
        expect(secretNode, '비밀 댓글 안내 노드가 존재해야 한다').toBeTruthy();
        expect(secretNode.if).toContain(MASKED);
        expect(secretNode.if, '블라인드 댓글은 블라인드 안내가 맡는다').toContain("comment?.status !== 'blinded'");
        expect(secretNode.if, '직접 삭제된 댓글은 삭제 안내가 맡는다').toContain('comment?.is_cascade_deleted');

        const children = secretNode.children ?? [];
        expect(children.some((c: any) => c.name === 'Icon' && c.props?.name === 'lock')).toBe(true);
        expect(children.some((c: any) => c.text === NOTICE_KEY)).toBe(true);
    });

    // @scenario viewer=restricted_admin
    // @effects admin_secret_comment_placeholder_rendered
    it('원문 노드는 가려진 비밀 댓글을 제외한다 (빈 줄 대신 안내만 남게)', () => {
        expect(normalNode, '일반 댓글 노드가 존재해야 한다').toBeTruthy();
        expect(normalNode.if).toContain(`!(${MASKED})`);
    });

    // @scenario viewer=restricted_admin
    // @effects admin_secret_comment_placeholder_rendered
    it('안내 문구 키가 ko/en 에 있다', () => {
        expect(typeof koPosts.detail.secret_comment_notice).toBe('string');
        expect(koPosts.detail.secret_comment_notice.length).toBeGreaterThan(0);
        expect(typeof enPosts.detail.secret_comment_notice).toBe('string');
        expect(enPosts.detail.secret_comment_notice.length).toBeGreaterThan(0);
    });
});
