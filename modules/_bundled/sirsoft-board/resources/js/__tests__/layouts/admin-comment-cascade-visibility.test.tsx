/**
 * 관리자 게시글 상세 - cascade 댓글 노출 분기 회귀 테스트 (#413-69-②)
 *
 * @description
 * 게시글 삭제로 함께 숨겨진(cascade) 댓글이 사용자 직접 삭제 댓글과 동일하게
 * "삭제된 댓글입니다" 로 마스킹되던 회귀의 방지.
 *
 * 정책(PO 확정): 관리 권한자에게 cascade 댓글은 마스킹 없이 원문을 노출한다.
 * 백엔드(CommentResource)가 is_cascade_deleted 플래그를 내려주고,
 * 레이아웃은 그 플래그로 분기를 결정한다.
 *
 * 분기 계약:
 *   - deleted_comment_content (마스킹): deleted_at && !is_cascade_deleted
 *       → user 선삭제분만 마스킹, cascade 는 제외
 *   - normal_comment_content (원문):   status!=='blinded' && (!deleted_at || is_cascade_deleted)
 *       → cascade 댓글은 일반 댓글처럼 원문 노출
 */

import { describe, it, expect } from 'vitest';

import commentPartial from '../../../layouts/admin/partials/admin_board_post_detail/_comment.json';

/**
 * JSON 트리에서 주어진 id 의 노드를 찾습니다.
 *
 * @param node 탐색 시작 노드
 * @param id 찾을 노드 id
 * @returns 일치하는 노드 또는 null
 */
function findById(node: any, id: string): any {
    if (!node || typeof node !== 'object') return null;
    if (node.id === id) return node;

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

describe('관리자 댓글 - cascade 노출 분기 (#413-69-②)', () => {
    const deletedNode = findById(commentPartial, 'deleted_comment_content');
    const normalNode = findById(commentPartial, 'normal_comment_content');

    it('마스킹 분기(deleted_comment_content)는 cascade 댓글을 제외한다', () => {
        expect(deletedNode, '삭제 댓글 마스킹 노드가 존재해야 한다').toBeTruthy();
        expect(deletedNode.if).toContain('comment?.deleted_at');
        expect(
            deletedNode.if,
            'cascade 댓글은 마스킹 분기에서 제외되어야 한다 (!is_cascade_deleted)'
        ).toContain('!comment?.is_cascade_deleted');
    });

    it('일반 분기(normal_comment_content)는 cascade 댓글의 원문을 노출한다', () => {
        expect(normalNode, '일반 댓글 노드가 존재해야 한다').toBeTruthy();
        expect(normalNode.if).toContain("comment?.status !== 'blinded'");
        expect(
            normalNode.if,
            'cascade 댓글은 deleted_at 이 있어도 일반 분기로 노출되어야 한다'
        ).toContain('comment?.is_cascade_deleted');
    });

    it('일반 분기는 cascade 댓글 본문을 comment.content 로 직접 렌더한다 (마스킹 없음)', () => {
        expect(normalNode.text).toBe('{{comment.content}}');
    });
});
