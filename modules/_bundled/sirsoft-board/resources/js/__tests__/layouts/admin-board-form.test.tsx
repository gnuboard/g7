/**
 * 게시판 생성/수정 폼 레이아웃 JSON 구조 검증 테스트
 *
 * @description
 * - 기본 탭(_tab_basic): use_comment/use_reply 토글 제거, secret_mode→show_view_count 순서
 * - 게시글 탭(_tab_post): admin-card → row-stack 직속 평면 구조, use_reply/use_comment/use_file_upload 토글 + 조건부 필드의 if 마이그레이션
 * - 5 partial 모두 root id = `section_*_card` + row-stack 컨테이너 `section_*_fields` 패턴 (#408)
 */

import { describe, it, expect } from 'vitest';

// 레이아웃 JSON 임포트
import tabBasic from '../../../layouts/admin/partials/admin_board_form/_tab_basic.json';
import tabPost from '../../../layouts/admin/partials/admin_board_form/_tab_post.json';
import tabPermissions from '../../../layouts/admin/partials/admin_board_form/_tab_permissions.json';
import boardForm from '../../../layouts/admin/admin_board_form.json';

// 허용 확장자 안내 문구 회귀 검증용 ko lang
import koFormLang from '../../../lang/partial/ko/admin/form.json';

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
        // #408: partial root id 패턴 = section_*_card (admin-card 자산 적용)
        expect(tabBasic.id).toBe('section_basic_card');
    });

    it('기본 섹션 카드에 use_comment, use_reply 토글이 없다 (게시글 탭으로 분리됨)', () => {
        // _tab_basic.json의 section_basic_card 하위에 use_comment, use_reply 없어야 함
        const fields = findFormFields(tabBasic);
        expect(fields).not.toContain('use_comment');
        expect(fields).not.toContain('use_reply');
    });

    it('기본 탭에서 secret_mode가 show_view_count보다 앞에 위치한다', () => {
        // #408: 필드는 section_basic_card 직속이 아닌 row-stack 컨테이너 section_basic_fields 하위
        const fieldsContainer = findById(tabBasic, 'section_basic_fields');
        expect(fieldsContainer).toBeDefined();

        const children = fieldsContainer.children ?? [];
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

    // ── 이슈 #413-15: 관리자 메뉴 추가 토글 ──

    it('add_to_menu 토글이 기본 탭에 있다 (생성/수정 공용)', () => {
        const fields = findFormFields(tabBasic);
        expect(fields).toContain('add_to_menu');
    });

    it('add_to_menu Toggle은 폼 자동바인딩이며 route.id 조건이 없다 (생성 모드에서도 노출)', () => {
        const toggles = findByName(tabBasic, 'Toggle').filter(
            (t: any) => t.props?.name === 'add_to_menu'
        );
        expect(toggles).toHaveLength(1);
        // 생성/수정 공용이므로 route.id 조건부 렌더가 없어야 함
        expect(toggles[0].if).toBeUndefined();
    });

    it('기존 수동 add-to-menu 버튼(apiCall)이 제거되었다 (토글로 대체)', () => {
        function collectTargets(node: any): string[] {
            const out: string[] = [];
            if (!node) return out;
            for (const action of node.actions ?? []) {
                if (typeof action.target === 'string') out.push(action.target);
            }
            for (const child of node.children ?? []) out.push(...collectTargets(child));
            return out;
        }
        const targets = collectTargets(tabBasic);
        const addToMenuCalls = targets.filter((t) => t.includes('/add-to-menu'));
        expect(addToMenuCalls).toHaveLength(0);
    });

    // 이슈 #413-19-3: 카테고리 삭제는 차단하지 않고 '미분류'로 유지(안내문 고지).
    // 동작하지 않던 before_remove/check_category_usage/모달 잔재만 제거한다.
    it('카테고리 TagInput에 미구현 before_remove/check_category_usage 액션이 없다', () => {
        const serialized = JSON.stringify(tabBasic);
        expect(serialized).not.toContain('before_remove');
        expect(serialized).not.toContain('check_category_usage');
        expect(serialized).not.toContain('showCategoryRemoveModal');
    });

    it('카테고리 필드에 서버 검증 에러 인라인 표시(_local.errors.categories)가 있다', () => {
        const serialized = JSON.stringify(tabBasic);
        expect(serialized).toContain('_local.errors?.categories');
    });

    it('카테고리 안내문(categories_notice)이 존재한다', () => {
        const notice = findById(tabBasic, 'field_categories_notice');
        expect(notice).toBeDefined();
        expect(notice.text).toContain('categories_notice');
    });

    // ── 이슈 #413-24: 제한 키워드(blocked_keywords) 게시글 설정 탭 소속 + TagInput 통일 ──

    // @scenario field=blocked_keywords, tab=basic
    // @effects blocked_keywords_absent_from_basic_tab
    it('제한 키워드(blocked_keywords) 필드는 기본 탭에 없다 (게시글 설정 탭 소속)', () => {
        const field = findById(tabBasic, 'field_blocked_keywords');
        expect(field).toBeNull();
        const serialized = JSON.stringify(tabBasic);
        expect(serialized).not.toContain('blocked_keywords');
    });

    // @scenario field=allowed_extensions, tab=basic
    // @effects allowed_extensions_absent_from_basic_tab
    it('허용 확장자(allowed_extensions) 필드는 기본 탭에 없다 (게시글 탭 소속)', () => {
        const input = findById(tabBasic, 'allowed_extensions_input');
        expect(input).toBeNull();
        const serialized = JSON.stringify(tabBasic);
        expect(serialized).not.toContain('allowed_extensions');
    });
});

// ============================================================
// admin_board_form.json — 푸터 취소 버튼 (이슈 #413-15 후속)
// ============================================================

describe('admin_board_form.json 취소 버튼', () => {
    it('취소 버튼은 navigateBack 이 아니라 navigate 로 게시판 목록(/admin/boards)으로 이동한다', () => {
        const cancelBtn = findById(boardForm, 'footer_cancel_button');
        expect(cancelBtn).toBeDefined();

        const actions = cancelBtn.actions ?? [];
        expect(actions.length).toBeGreaterThan(0);

        const clickAction = actions.find((a: any) => a.type === 'click');
        expect(clickAction).toBeDefined();
        // navigateBack(브라우저 뒤로가기)은 직접 진입 시 목록으로 가지 않으므로 금지
        expect(clickAction.handler).toBe('navigate');
        expect(clickAction.handler).not.toBe('navigateBack');
        expect(clickAction.params?.path).toBe('/admin/boards');
    });

    // 이슈 #413-19-3: 동작하지 않던 category_remove 모달 제거
    it('미구현 category_remove 모달이 제거되었다', () => {
        const modals = (boardForm as any).modals ?? [];
        const categoryModal = modals.find((m: any) => m.id === 'category_remove_modal');
        expect(categoryModal).toBeUndefined();
    });

    it('미사용 showCategoryRemoveModal state가 제거되었다', () => {
        const state = (boardForm as any).state ?? {};
        expect(state).not.toHaveProperty('showCategoryRemoveModal');
        expect(state).not.toHaveProperty('categoryToRemove');
    });
});

// ============================================================
// _tab_post.json 테스트
// ============================================================

describe('admin_board_form/_tab_post.json', () => {
    it('파일이 유효한 JSON 구조이다', () => {
        expect(tabPost).toBeDefined();
        // #408: partial root id 패턴 = section_*_card (admin-card 자산 적용)
        expect(tabPost.id).toBe('section_post_card');
    });

    it('게시글 탭에 deprecated condition 속성이 없다 (if로 마이그레이션 완료)', () => {
        const conditionNodes = findConditionNodes(tabPost);
        expect(conditionNodes, 'condition 속성 노드가 남아있음 (if로 교체 필요)').toHaveLength(0);
    });

    it('row-stack 컨테이너 section_post_fields 에 use_reply 토글과 max_reply_depth 필드가 있다', () => {
        const fieldsContainer = findById(tabPost, 'section_post_fields');
        expect(fieldsContainer).toBeDefined();
        const fields = findFormFields(fieldsContainer);

        expect(fields).toContain('use_reply');
        expect(fields).toContain('max_reply_depth');
    });

    it('max_reply_depth_field 가 use_reply 토글 ON 시에만 표시된다 (if 조건)', () => {
        // 평면화 후 max_reply_depth_field 가 row-stack 직속 자식으로 if 조건을 직접 보유
        const maxReplyDepthField = findById(tabPost, 'max_reply_depth_field');
        expect(maxReplyDepthField).toBeDefined();
        expect(maxReplyDepthField.if).toBeDefined();
        expect(maxReplyDepthField.if).toContain('use_reply');
    });

    it('row-stack 컨테이너 section_post_fields 에 use_comment 토글이 있다', () => {
        const fieldsContainer = findById(tabPost, 'section_post_fields');
        expect(fieldsContainer).toBeDefined();
        const fields = findFormFields(fieldsContainer);
        expect(fields).toContain('use_comment');
    });

    it('댓글 서브 필드들이 use_comment ON 시에만 표시된다 (if 조건)', () => {
        // 평면화 후 post_comment_length_group / max_comment_depth_field / comment_order_field 모두 각자 if 보유
        const card = findById(tabPost, 'section_post_card');
        expect(card).toBeDefined();

        function findIfContaining(node: any, keyword: string): any[] {
            const results: any[] = [];
            if (node?.if?.includes(keyword)) results.push(node);
            for (const c of node?.children ?? []) results.push(...findIfContaining(c, keyword));
            return results;
        }

        const useCommentConditionals = findIfContaining(card, 'use_comment');
        // post_comment_length_group / max_comment_depth_field / comment_order_field 3 개
        expect(useCommentConditionals.length).toBeGreaterThanOrEqual(3);
    });

    it('row-stack 직속 자식 순서: 답변글 토글이 댓글 토글보다 앞에 위치한다', () => {
        // 평면화 후 sub-section wrapper 가 사라졌으므로 토글 자체의 순서로 검증
        const fieldsContainer = findById(tabPost, 'section_post_fields');
        expect(fieldsContainer).toBeDefined();
        const children = fieldsContainer.children ?? [];
        const replyToggleIdx = children.findIndex((c: any) => c.id === 'use_reply_toggle');
        const commentToggleIdx = children.findIndex((c: any) => c.id === 'use_comment_toggle');

        expect(replyToggleIdx).toBeGreaterThanOrEqual(0);
        expect(commentToggleIdx).toBeGreaterThanOrEqual(0);
        expect(replyToggleIdx).toBeLessThan(commentToggleIdx);
    });

    it('게시글 탭에 use_file_upload 토글이 있다', () => {
        const fields = findFormFields(tabPost);
        expect(fields).toContain('use_file_upload');
    });

    it('첨부파일 서브 필드들이 use_file_upload ON 시에만 표시된다 (if 조건)', () => {
        // 평면화 후 file_settings_group / allowed_extensions_field 모두 각자 if 보유
        const card = findById(tabPost, 'section_post_card');
        expect(card).toBeDefined();

        function findIfContaining(node: any, keyword: string): any[] {
            const results: any[] = [];
            if (node?.if?.includes(keyword)) results.push(node);
            for (const c of node?.children ?? []) results.push(...findIfContaining(c, keyword));
            return results;
        }

        const useFileConditionals = findIfContaining(card, 'use_file_upload');
        // file_settings_group / allowed_extensions_field 2 개
        expect(useFileConditionals.length).toBeGreaterThanOrEqual(2);
    });

    // ── 이슈 #413-24: 제한 키워드 게시글 설정 탭 소속 + TagInput 통일 ──

    // @scenario field=blocked_keywords, tab=post
    // @effects blocked_keywords_renders_as_taginput_on_post_tab
    it('제한 키워드(blocked_keywords) 입력이 게시글 탭에 TagInput(배열)으로 있다 (Textarea 아님)', () => {
        const input = findById(tabPost, 'blocked_keywords_input');
        expect(input).toBeDefined();
        expect(input.name).toBe('TagInput');
        expect(input.props?.name).toBe('blocked_keywords');
        expect(input.props?.creatable).toBe(true);
    });

    it('제한 키워드 필드에 서버 검증 에러 인라인 표시(_local.errors.blocked_keywords)가 있다', () => {
        const serialized = JSON.stringify(tabPost);
        expect(serialized).toContain('_local.errors?.blocked_keywords');
    });

    // @scenario field=allowed_extensions, tab=post
    // @effects allowed_extensions_renders_as_taginput_on_post_tab
    it('허용 확장자(allowed_extensions) 입력이 TagInput(배열)이다 (Input 아님)', () => {
        const input = findById(tabPost, 'allowed_extensions_input');
        expect(input).toBeDefined();
        expect(input.name).toBe('TagInput');
        expect(input.props?.name).toBe('allowed_extensions');
        expect(input.props?.creatable).toBe(true);
    });

    it('허용 확장자 안내 문구가 description i18n 키를 참조한다 (회귀)', () => {
        const descNode = findById(tabPost, 'allowed_extensions_description');
        expect(descNode).toBeDefined();
        expect(descNode.text).toBe('$t:sirsoft-board.admin.form.fields.allowed_extensions.description');
    });

    it('허용 확장자 안내 ko 문구가 "최소 1개" 의미로 갱신되었다 (회귀)', () => {
        // 빈 값 저장 차단 정책에 맞춰 안내 문구도 "최소 1개 입력"이어야 하며,
        // 정반대 의미의 옛 문구("빈 값 ... 모든 확장자")가 남아 있으면 안 된다.
        const desc = (koFormLang as any).fields.allowed_extensions.description;
        expect(desc).toContain('최소 1개');
        expect(desc).not.toContain('모든 확장자');
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
        // #408: partial root id 패턴 = section_*_card (admin-card 자산 적용)
        expect((tabPermissions as any).id).toBe('section_permissions_card');
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
