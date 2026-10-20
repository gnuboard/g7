/**
 * @file board-deleted-comment-tree.test.tsx
 * @description 부모 댓글 삭제 시 tombstone 표시 + 자식 트리 보존 (이슈 #413-56-2)
 *
 * 검증 방식: 레이아웃 JSON 트리 직접 분석 (DOM 비의존).
 *
 * 정책(#413-56-2):
 *   - 부모 댓글이 삭제되어도 살아있는 자식이 있으면 부모는 tombstone("삭제된 댓글입니다")으로
 *     트리에 유지된다(백엔드 CommentRepository 가 tombstone 부모를 조회 트리에 복구).
 *   - tombstone 안내는 deleted_at 만 게이트로 하며 content 존재에 의존하지 않는다
 *     (비권한자는 content 가 "삭제된 댓글입니다" 문구라도 안내는 보여야 함).
 *   - 원문은 블라인드와 동일하게 관리자(can_manage)만 토글 열람 (정책 확정 — 버튼 유지).
 *   - 자식 댓글 렌더는 부모의 삭제 상태와 독립적으로 동작한다.
 *
 * 회귀 배경: 과거 일반 조회에서 삭제 부모가 빠지며 자식까지 트리에서 누락 → 자식 동반 실종.
 *   백엔드 복구 + 본 표시 계약 고정으로 방어한다.
 *
 * 시나리오 매핑은 테스트마다 둔다(파일 레벨 마커는 메서드가 없어도 커버로 집계돼 쓰지 않는다).
 *   - 소유 매니페스트: modules/_bundled/sirsoft-board/tests/scenarios/board-comment-deleted-parent-child-tree.yaml(viewer 축),
 *     modules/_bundled/sirsoft-board/tests/scenarios/deleted-post-detail-visibility.yaml(comment_trigger 축),
 *     tests/scenarios/board-comment-reply-unknown-author.yaml(author_name 축 — 7.1.1)
 */

import { describe, it, expect } from 'vitest';

import commentItem from '../../layouts/partials/board/show/_comment_item.json';
import { TranslationEngine } from '@core/template-engine/TranslationEngine';
import boardKo from '../../lang/partial/ko/board.json';

type Node = Record<string, unknown> & { children?: unknown };

/**
 * 트리를 재귀 순회하며 조건을 만족하는 모든 노드를 수집한다.
 */
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

/**
 * comment(주석) 필드로 노드를 찾는다.
 */
function findByComment(root: unknown, commentText: string): Node[] {
    return collectNodes(root, (n) => typeof n.comment === 'string' && (n.comment as string).includes(commentText));
}

describe('이슈 #413-56-2 — 삭제 댓글 tombstone 표시 게이트 (_comment_item.json)', () => {
    /**
     * @scenario comment_trigger=user
     * @effects deleted_parent_with_live_child_shown_as_tombstone
     */
    it('삭제 댓글 tombstone 블록은 deleted_at 만 게이트로 하며 노출된다', () => {
        const blocks = findByComment(commentItem, '삭제된 댓글');
        expect(blocks.length).toBeGreaterThan(0);

        // tombstone 안내 블록(자식 노드가 아닌 최상위 삭제 블록)을 찾는다
        const tombstone = blocks.find((b) => typeof b.if === 'string' && (b.if as string).includes('deleted_at'));
        expect(tombstone).toBeDefined();
        expect(tombstone!.if as string).toContain('comment?.deleted_at');
    });

    /**
     * 비권한자에게는 content 가 걸러져 오지만(백엔드 — 그 걸러짐 자체는 CommentDeletedParentChildTreeTest 가 판정) 안내 블록은
     * content 를 보지 않아 그대로 뜬다.
     *
     * @scenario viewer=regular
     */
    /**
     * @scenario viewer=guest
     */
    it('tombstone 안내는 content 존재에 의존하지 않는다 (비권한자도 안내는 노출)', () => {
        const blocks = findByComment(commentItem, '삭제된 댓글');
        const tombstone = blocks.find((b) => typeof b.if === 'string' && (b.if as string).includes('deleted_at'));
        expect(tombstone).toBeDefined();

        // tombstone 컨테이너 자체의 if 는 content 를 참조하지 않아야 함
        // (블라인드와 달리, 삭제 안내는 content 와 무관하게 항상 표시)
        expect(tombstone!.if as string).not.toContain('content');
    });

    /**
     * @scenario viewer=manager
     * @effects manager_sees_tombstone_original_content
     */
    it('삭제 댓글 원문 보기 버튼은 관리자(can_manage)만 노출된다', () => {
        const buttons = findByComment(commentItem, '관리자용 원문 보기 버튼');
        expect(buttons.length).toBeGreaterThan(0);

        for (const btn of buttons) {
            expect(typeof btn.if).toBe('string');
            expect(btn.if as string).toContain('can_manage');
        }
    });

    /**
     * @scenario viewer=manager
     * @effects manager_sees_tombstone_original_content
     */
    it('삭제 댓글 원문 내용 블록은 관리자 토글(showDeletedContent)에 의존한다', () => {
        const blocks = findByComment(commentItem, '관리자용 원문 내용');
        expect(blocks.length).toBeGreaterThan(0);

        for (const block of blocks) {
            expect(typeof block.if).toBe('string');
            expect(block.if as string).toContain('showDeletedContent');
        }
    });

    /**
     * @scenario comment_trigger=user
     */
    it('블라인드와 삭제 표시 블록이 deleted_at 으로 상호 배타적으로 분리된다', () => {
        // 블라인드 블록은 !deleted_at, 삭제 블록은 deleted_at — 동시에 켜지지 않음
        const blinded = findByComment(commentItem, '블라인드 처리된 댓글');
        const deleted = findByComment(commentItem, '삭제된 댓글').find(
            (b) => typeof b.if === 'string' && (b.if as string).includes('comment?.deleted_at'),
        );

        expect(blinded.length).toBeGreaterThan(0);
        expect(deleted).toBeDefined();

        const blindedBlock = blinded.find((b) => typeof b.if === 'string' && (b.if as string).includes('blinded'));
        expect(blindedBlock).toBeDefined();
        // 블라인드 블록은 삭제가 아닐 때만 노출 (deleted_at 우선)
        expect(blindedBlock!.if as string).toContain('!comment?.deleted_at');
    });

    /**
     * @scenario comment_trigger=user
     */
    it('읽기 모드 본문 블록도 deleted_at 이 아닐 때만 노출되어 tombstone 과 충돌하지 않는다', () => {
        const readBlocks = findByComment(commentItem, '댓글 내용 (읽기 모드)');
        expect(readBlocks.length).toBeGreaterThan(0);

        for (const block of readBlocks) {
            expect(typeof block.if).toBe('string');
            expect(block.if as string).toContain('!comment?.deleted_at');
        }
    });

    /**
     * @scenario comment_trigger=cascade
     * @effects cascade_comment_content_visible
     */
    it('cascade 댓글(is_cascade_deleted)은 tombstone(마스킹)이 아닌 읽기 모드 본문으로 원문 노출된다', () => {
        // 게시글 삭제로 함께 숨겨진 cascade 댓글은 tombstone 마스킹에서 제외되고 원문이 노출되어야 함.
        // 마스킹(삭제) 블록: deleted_at && !is_cascade_deleted → cascade 댓글은 이 분기에서 빠진다.
        const maskBlock = findByComment(commentItem, '삭제된 댓글').find(
            (b) => typeof b.if === 'string' && (b.if as string).includes('comment?.deleted_at'),
        );
        expect(maskBlock).toBeDefined();
        expect(maskBlock!.if as string).toContain('!comment?.is_cascade_deleted');

        // 읽기 모드 본문 블록: (!deleted_at || is_cascade_deleted) → cascade 댓글은 삭제되어도 원문 노출.
        const readBlocks = findByComment(commentItem, '댓글 내용 (읽기 모드)');
        expect(readBlocks.length).toBeGreaterThan(0);
        for (const block of readBlocks) {
            expect(block.if as string).toContain('comment?.is_cascade_deleted');
        }
    });
});

describe('계정 없는 작성자 댓글의 답변 안내 (7.1.1)', () => {
    // 하드 삭제된 회원의 댓글은 author.name 이 null 이라 「{{name}}님에게 답변 작성 중...」 의 자리표시자가 그대로 보였다.
    // 이름이 없으면 「알 수 없음」(board.unknown_author) 으로 채운다.
    /**
     * 한 단언 안에서 이름 있음(「홍길동」)·없음(name null, author null)을 함께 푼다 — 마커 파서가 주석 블록 하나에서 시나리오 조합을 하나만 읽으므로 둘로 둔다.
     *
     * @scenario author_name=present
     * @effects reply_hint_shows_author_name
     */
    /**
     * @scenario author_name=missing
     * @effects reply_hint_falls_back_to_unknown_author
     */
    it('답변 안내의 name 값은 이름이 없을 때 board.unknown_author 로 채운다', () => {
        const texts: string[] = [];
        (function walk(cur: unknown): void {
            if (Array.isArray(cur)) { cur.forEach(walk); return; }
            if (cur && typeof cur === 'object') {
                const t = (cur as Record<string, unknown>).text;
                if (typeof t === 'string' && t.includes('board.replying_to')) texts.push(t);
                Object.values(cur as Record<string, unknown>).forEach(walk);
            }
        })(commentItem);
        expect(texts).toHaveLength(1);
        expect(texts[0]).toContain("comment?.author?.name || $t('board.unknown_author')");

        // 실제 번역 엔진으로 풀어 본다 — 이름이 있으면 그 이름, 없으면 「알 수 없음」
        const engine = TranslationEngine.getInstance();
        (engine as any).translations.set('sirsoft-basic:ko', { board: boardKo });
        const resolve = (author: unknown) => engine.resolveTranslations(texts[0], { templateId: 'sirsoft-basic', locale: 'ko' }, { comment: { author }, $templateId: 'sirsoft-basic', $locale: 'ko' } as any);
        expect(resolve({ name: '홍길동' })).toBe('홍길동님에게 답변 작성 중...');
        expect(resolve({ name: null })).toBe('알 수 없음님에게 답변 작성 중...');
        expect(resolve(null)).not.toContain('{{name}}');
    });
});
