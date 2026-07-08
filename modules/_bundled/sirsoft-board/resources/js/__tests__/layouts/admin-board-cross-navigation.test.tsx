/**
 * @file admin-board-cross-navigation.test.tsx
 * @description 관리자 게시판 ↔ 유저 게시판 상호 이동 크로스링크 검증 (이슈 #450)
 *
 * 검증 방식: 레이아웃 JSON 트리 직접 분석 (DOM 비의존).
 * 검증 목적:
 *   1. 관리자 게시물 리스트/상세에 "유저 화면 보기" 링크 존재 (새 탭, 조건 없음)
 *   2. 링크가 올바른 유저 라우트 href 와 target=_blank 를 사용
 */

import { describe, it, expect } from 'vitest';

import postsIndex from '../../../../resources/layouts/admin/admin_board_posts_index.json';
import postDetail from '../../../../resources/layouts/admin/admin_board_post_detail.json';

interface LayoutNode {
    id?: string;
    name?: string;
    props?: Record<string, unknown>;
    children?: LayoutNode[];
    [key: string]: unknown;
}

/** 레이아웃 트리에서 id 로 노드를 재귀 탐색 */
function findById(node: LayoutNode | LayoutNode[] | undefined, id: string): LayoutNode | null {
    if (!node) return null;
    const nodes = Array.isArray(node) ? node : [node];
    for (const n of nodes) {
        if (n && typeof n === 'object') {
            if (n.id === id) return n;
            for (const key of Object.keys(n)) {
                const child = (n as Record<string, unknown>)[key];
                if (Array.isArray(child) || (child && typeof child === 'object')) {
                    const found = findById(child as LayoutNode | LayoutNode[], id);
                    if (found) return found;
                }
            }
        }
    }
    return null;
}

describe('이슈 #450 — 관리자 게시판 → 유저 게시판 크로스링크', () => {
    describe('admin_board_posts_index.json (게시물 리스트)', () => {
        const link = findById(postsIndex as LayoutNode, 'view_user_page_link');

        it('유저 화면 보기 링크 노드가 존재한다', () => {
            expect(link).not.toBeNull();
        });

        it('A 태그로 유저 게시판 리스트 경로를 새 탭으로 연다', () => {
            expect(link?.name).toBe('A');
            expect(link?.props?.href).toBe('/board/{{route.slug}}');
            expect(link?.props?.target).toBe('_blank');
            expect(link?.props?.rel).toBe('noopener noreferrer');
        });

        it('노출 조건(if)이 없어 관리자 화면에서 항상 표시된다', () => {
            expect(link?.if).toBeUndefined();
        });
    });

    describe('admin_board_post_detail.json (게시물 상세)', () => {
        const link = findById(postDetail as LayoutNode, 'view_user_page_link');

        it('유저 화면 보기 링크 노드가 존재한다', () => {
            expect(link).not.toBeNull();
        });

        it('A 태그로 유저 게시판 상세 경로를 새 탭으로 연다', () => {
            expect(link?.name).toBe('A');
            expect(link?.props?.href).toBe('/board/{{route.slug}}/{{route?.id}}');
            expect(link?.props?.target).toBe('_blank');
        });

        it('노출 조건(if)이 없어 관리자 화면에서 항상 표시된다', () => {
            expect(link?.if).toBeUndefined();
        });
    });
});
