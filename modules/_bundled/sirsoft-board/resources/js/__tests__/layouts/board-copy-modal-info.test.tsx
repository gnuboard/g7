/**
 * @file board-copy-modal-info.test.tsx
 * @description 게시판 복사 확인 모달 - 안내 박스(배지 박스 + 불릿 3항목) 레이아웃 JSON 구조 검증
 *
 * 이슈 #413-16 후속(PO 요청): 복사 모달 안내를 "설정·권한·관리자 복사 / 게시글·댓글 제외 /
 * 새 슬러그 확인" 3항목으로 구조화. 안내 박스가 테두리+배경 컨테이너 안에 불릿 3개를
 * 포함하는지 JSON 트리로 검증한다 (DOM 비의존 구조 검증).
 */

import { describe, it, expect } from 'vitest';

import indexLayout from '../../../layouts/admin/admin_board_index.json';

/**
 * 트리에서 id 가 일치하는 첫 노드를 찾는다.
 */
function findById(node: any, id: string): any | null {
    if (!node) return null;
    if (node.id === id) return node;
    const kids = node.children ?? node.modals ?? null;
    if (Array.isArray(kids)) {
        for (const child of kids) {
            const found = findById(child, id);
            if (found) return found;
        }
    }
    // 최상위(layout) 객체는 modals/children 두 분기를 모두 탐색
    if (Array.isArray(node.modals)) {
        for (const child of node.modals) {
            const found = findById(child, id);
            if (found) return found;
        }
    }
    return null;
}

/**
 * 트리에서 text 가 특정 패턴을 포함하는 노드를 모두 찾는다.
 */
function findAllByText(node: any, pattern: string): any[] {
    const results: any[] = [];
    if (!node) return results;
    if (typeof node.text === 'string' && node.text.includes(pattern)) results.push(node);
    const kids = node.children ?? null;
    if (Array.isArray(kids)) {
        for (const child of kids) {
            results.push(...findAllByText(child, pattern));
        }
    }
    return results;
}

describe('게시판 복사 모달 - 안내 박스 (JSON 구조 검증)', () => {
    it('안내 박스 컨테이너가 테두리+배경+다크모드 쌍을 갖는다', () => {
        const box = findById(indexLayout, 'copy_info_box');
        expect(box).not.toBeNull();
        expect(box.name).toBe('Div');

        const cls = box.props?.className ?? '';
        // 테두리 + 배경 + 다크모드 라이트/다크 쌍 (layout-json 규칙: light/dark 쌍 필수)
        expect(cls).toContain('border');
        expect(cls).toContain('bg-gray-50');
        expect(cls).toContain('dark:bg-gray-800');
        expect(cls).toContain('dark:border-gray-700');
    });

    it('안내 박스 안에 불릿 3항목(복사됨/제외/슬러그)이 모두 존재한다', () => {
        const box = findById(indexLayout, 'copy_info_box');
        expect(box).not.toBeNull();

        const copied = findById(box, 'copy_info_copied');
        const excluded = findById(box, 'copy_info_excluded');
        const slug = findById(box, 'copy_info_slug');

        expect(copied).not.toBeNull();
        expect(excluded).not.toBeNull();
        expect(slug).not.toBeNull();

        // 메모리 규칙: 운영자 안내 박스는 3항목 이하 — 정확히 3개
        expect(box.children?.length).toBe(3);
    });

    it('각 불릿이 올바른 i18n 키 텍스트를 참조한다', () => {
        const box = findById(indexLayout, 'copy_info_box');

        expect(findAllByText(box, 'modals.copy.info_copied').length).toBe(1);
        expect(findAllByText(box, 'modals.copy.info_excluded').length).toBe(1);
        expect(findAllByText(box, 'modals.copy.info_slug').length).toBe(1);
    });

    it('이전 단일 안내 키(info)는 더 이상 사용되지 않는다 (회귀 가드)', () => {
        const modal = findById(indexLayout, 'copy_confirm_modal');
        expect(modal).not.toBeNull();
        // 분리 전 단일 키 'modals.copy.info' 직접 참조가 남아있지 않아야 함
        const stale = findAllByText(modal, 'modals.copy.info"');
        expect(stale.length).toBe(0);
    });
});
