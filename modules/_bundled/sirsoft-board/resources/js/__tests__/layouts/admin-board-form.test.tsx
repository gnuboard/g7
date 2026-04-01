/**
 * 게시판 생성/수정 폼 레이아웃 JSON 구조 검증 테스트
 *
 * @description
 * - 기본 탭(_tab_basic): use_comment/use_reply 토글 제거, secret_mode→show_view_count 순서
 * - 게시글 탭(_tab_post): section_reply 분리, use_comment 서브필드 분리, condition→if 마이그레이션
 */

import { describe, it, expect } from 'vitest';

// 레이아웃 JSON 임포트
import tabBasic from '../../../layouts/admin/partials/admin_board_form/_tab_basic.json';
import tabPost from '../../../layouts/admin/partials/admin_board_form/_tab_post.json';
import tabPermissions from '../../../layouts/admin/partials/admin_board_form/_tab_permissions.json';

/**
 * JSON 트리에서 특정 ID를 가진 노드를 재귀적으로 찾습니다.
 */
function findById(node: any, id: string): any | null {
    if (!node) return null;
    if (node.id === id) return node;

    if (node.children && Array.isArray(node.children)) {
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

/**
 * JSON 트리에서 특정 name을 가진 컴포넌트를 모두 찾습니다.
 */
function findByName(node: any, name: string): any[] {
    const results: any[] = [];
    if (!node) return results;

    if (node.name === name) {
        results.push(node);
    }

    if (node.children && Array.isArray(node.children)) {
        for (const child of node.children) {
            results.push(...findByName(child, name));
        }
    }

    if (node.slots) {
        for (const slotChildren of Object.values(node.slots)) {
            if (Array.isArray(slotChildren)) {
                for (const child of slotChildren) {
                    results.push(...findByName(child as any, name));
                }
            }
        }
    }

    return results;
}

/**
 * JSON 트리에서 form name prop으로 Input/Select/Toggle를 찾습니다.
 */
function findFormFields(node: any): string[] {
    const names: string[] = [];
    if (!node) return names;

    if ((node.name === 'Input' || node.name === 'Select' || node.name === 'Toggle') && node.props?.name) {
        names.push(node.props.name);
    }

    if (node.children && Array.isArray(node.children)) {
        for (const child of node.children) {
            names.push(...findFormFields(child));
        }
    }

    if (node.slots) {
        for (const slotChildren of Object.values(node.slots)) {
            if (Array.isArray(slotChildren)) {
                for (const child of slotChildren) {
                    names.push(...findFormFields(child as any));
                }
            }
        }
    }

    return names;
}

/**
 * JSON 트리에서 deprecated `condition` 속성을 가진 노드를 모두 찾습니다.
 */
function findConditionNodes(node: any): any[] {
    const results: any[] = [];
    if (!node) return results;

    if (node.condition !== undefined) {
        results.push(node);
    }

    if (node.children && Array.isArray(node.children)) {
        for (const child of node.children) {
            results.push(...findConditionNodes(child));
        }
    }

    if (node.slots) {
        for (const slotChildren of Object.values(node.slots)) {
            if (Array.isArray(slotChildren)) {
                for (const child of slotChildren) {
                    results.push(...findConditionNodes(child as any));
                }
            }
        }
    }

    return results;
}

// ============================================================
// _tab_basic.json 테스트
// ============================================================

describe('admin_board_form/_tab_basic.json', () => {
    it('파일이 유효한 JSON 구조이다', () => {
        expect(tabBasic).toBeDefined();
        expect(tabBasic.id).toBe('tab_content_basic');
    });

    it('기본 섹션 카드에 use_comment, use_reply 토글이 없다 (게시글 탭으로 분리됨)', () => {
        // _tab_basic.json의 section_basic_card 하위에 use_comment, use_reply 없어야 함
        const fields = findFormFields(tabBasic);
        expect(fields).not.toContain('use_comment');
        expect(fields).not.toContain('use_reply');
    });

    it('기본 탭에서 secret_mode가 show_view_count보다 앞에 위치한다', () => {
        // section_basic_card 하위 children에서 순서 확인
        const card = findById(tabBasic, 'section_basic_card');
        expect(card).toBeDefined();

        const children = card.children ?? [];
        const secretIdx = children.findIndex((c: any) => c.id === 'field_secret_mode');
        const viewCountIdx = children.findIndex((c: any) => c.id === 'field_show_view_count');

        expect(secretIdx).toBeGreaterThanOrEqual(0);
        expect(viewCountIdx).toBeGreaterThanOrEqual(0);
        expect(secretIdx).toBeLessThan(viewCountIdx);
    });

    it('기본 탭에 deprecated condition 속성이 없다 (if로 마이그레이션 완료)', () => {
        const conditionNodes = findConditionNodes(tabBasic);
        expect(conditionNodes, 'condition 속성 노드가 남아있음 (if로 교체 필요)').toHaveLength(0);
    });

    it('use_report 토글이 기본 탭에 있다', () => {
        const fields = findFormFields(tabBasic);
        expect(fields).toContain('use_report');
    });
});

// ============================================================
// _tab_post.json 테스트
// ============================================================

describe('admin_board_form/_tab_post.json', () => {
    it('파일이 유효한 JSON 구조이다', () => {
        expect(tabPost).toBeDefined();
        expect(tabPost.id).toBe('tab_content_post');
    });

    it('게시글 탭에 deprecated condition 속성이 없다 (if로 마이그레이션 완료)', () => {
        const conditionNodes = findConditionNodes(tabPost);
        expect(conditionNodes, 'condition 속성 노드가 남아있음 (if로 교체 필요)').toHaveLength(0);
    });

    it('답변글 서브섹션(post_reply_section)이 존재한다', () => {
        const replySection = findById(tabPost, 'post_reply_section');
        expect(replySection).toBeDefined();
    });

    it('답변글 서브섹션에 use_reply 토글과 max_reply_depth 필드가 있다', () => {
        const replySection = findById(tabPost, 'post_reply_section');
        expect(replySection).toBeDefined();
        const fields = findFormFields(replySection);

        expect(fields).toContain('use_reply');
        expect(fields).toContain('max_reply_depth');
    });

    it('max_reply_depth 서브필드가 use_reply 토글 ON 시에만 표시된다 (if 조건)', () => {
        // max_reply_depth_field Div 컨테이너에 if 조건이 적용됨
        const maxReplyDepthField = findById(tabPost, 'max_reply_depth_field');
        expect(maxReplyDepthField).toBeDefined();
        expect(maxReplyDepthField.if).toBeDefined();
        expect(maxReplyDepthField.if).toContain('use_reply');
    });

    it('댓글 서브섹션(post_comment_section)이 존재하고 use_comment 토글을 포함한다', () => {
        const commentSection = findById(tabPost, 'post_comment_section');
        expect(commentSection).toBeDefined();
        const fields = findFormFields(commentSection);

        expect(fields).toContain('use_comment');
    });

    it('댓글 서브 필드들이 use_comment ON 시에만 표시된다 (if 조건)', () => {
        // use_comment_sub_fields 컨테이너에 if 조건이 적용됨
        const card = findById(tabPost, 'section_post_card');
        expect(card).toBeDefined();

        // use_comment를 참조하는 if 속성을 가진 Div 컨테이너 확인
        function findIfContaining(node: any, keyword: string): any[] {
            const results: any[] = [];
            if (node?.if?.includes(keyword)) results.push(node);
            for (const c of node?.children ?? []) results.push(...findIfContaining(c, keyword));
            return results;
        }

        const useCommentConditionals = findIfContaining(card, 'use_comment');
        expect(useCommentConditionals.length).toBeGreaterThan(0);
    });

    it('post_reply_section이 post_comment_section보다 앞에 위치한다', () => {
        // section_post_card 하위 children에서 순서 확인
        const card = findById(tabPost, 'section_post_card');
        expect(card).toBeDefined();
        const children = card.children ?? [];
        const replyIdx = children.findIndex((c: any) => c.id === 'post_reply_section');
        const commentIdx = children.findIndex((c: any) => c.id === 'post_comment_section');

        expect(replyIdx).toBeGreaterThanOrEqual(0);
        expect(commentIdx).toBeGreaterThanOrEqual(0);
        expect(replyIdx).toBeLessThan(commentIdx);
    });

    it('게시글 탭에 use_file_upload 토글이 있다', () => {
        const fields = findFormFields(tabPost);
        expect(fields).toContain('use_file_upload');
    });
});

// ============================================================
// _tab_permissions.json 테스트 — board_manager_ids/board_step_ids uuid 참조
// ============================================================

/**
 * JSON 트리에서 특정 id를 가진 TagInput 컴포넌트를 찾습니다.
 * (_tab_permissions.json의 TagInput은 name prop 대신 id로 식별됨)
 */
function findTagInputById(node: any, id: string): any | null {
    if (!node) return null;
    if (node.name === 'TagInput' && node.id === id) return node;

    for (const child of node.children ?? []) {
        const found = findTagInputById(child, id);
        if (found) return found;
    }
    for (const slotChildren of Object.values(node.slots ?? {})) {
        if (Array.isArray(slotChildren)) {
            for (const child of slotChildren) {
                const found = findTagInputById(child as any, id);
                if (found) return found;
            }
        }
    }
    return null;
}

/**
 * TagInput의 actions 배열에서 type: "change" 핸들러의 특정 form 필드 표현식을 반환합니다.
 */
function getChangeActionFormField(tagInput: any, fieldName: string): string | null {
    if (!tagInput || !Array.isArray(tagInput.actions)) return null;
    for (const action of tagInput.actions) {
        if (action.type === 'change' && action.params?.form?.[fieldName]) {
            return action.params.form[fieldName] as string;
        }
    }
    return null;
}

describe('admin_board_form/_tab_permissions.json', () => {
    it('파일이 유효한 JSON 구조이다', () => {
        expect(tabPermissions).toBeDefined();
        expect((tabPermissions as any).id).toBe('tab_content_permissions');
    });

    it('manager TagInput options 표현식이 u.id가 아닌 u.uuid를 value로 사용한다', () => {
        const managerInput = findTagInputById(tabPermissions, 'permissions_managers_taginput');
        expect(managerInput).not.toBeNull();
        const options: string = managerInput.props.options ?? '';
        expect(options).not.toMatch(/\bu\.id\b/);
        expect(options).toContain('u.uuid');
    });

    it('manager change 핸들러의 board_managers 표현식이 u.uuid로 필터링한다', () => {
        const managerInput = findTagInputById(tabPermissions, 'permissions_managers_taginput');
        expect(managerInput).not.toBeNull();
        const expr = getChangeActionFormField(managerInput, 'board_managers');
        expect(expr).not.toBeNull();
        expect(expr).not.toMatch(/\bu\.id\b/);
        expect(expr).toContain('u.uuid');
    });

    it('step TagInput options 표현식이 u.uuid를 value로 사용한다', () => {
        const stepInput = findTagInputById(tabPermissions, 'permissions_steps_taginput');
        expect(stepInput).not.toBeNull();
        const options: string = stepInput.props.options ?? '';
        expect(options).not.toMatch(/\bu\.id\b/);
        expect(options).toContain('u.uuid');
    });

    it('step change 핸들러의 board_steps 표현식이 u.uuid로 필터링한다', () => {
        const stepInput = findTagInputById(tabPermissions, 'permissions_steps_taginput');
        expect(stepInput).not.toBeNull();
        const expr = getChangeActionFormField(stepInput, 'board_steps');
        expect(expr).not.toBeNull();
        expect(expr).not.toMatch(/\bu\.id\b/);
        expect(expr).toContain('u.uuid');
    });
});
