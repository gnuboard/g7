/**
 * 게시판 환경설정 레이아웃 JSON 구조 검증 테스트
 *
 * @description
 * - 메인 레이아웃: 데이터 소스, 페이지 안내, 탭 네비게이션, 저장/취소 버튼, 모달 참조
 * - 기본 설정 탭: 8개 섹션 (기본, 권한, 목록, 게시글, 댓글, 첨부파일, 알림, 일괄 적용)
 * - 신고 정책 탭: 6개 필드 (자동 숨김, 신고 기한, 일일 제한, 반려 제한, 정지 기간)
 * - 스팸/보안 탭: 쿨다운, 조회수, 캐시 초기화
 * - 일괄 적용 모달: 확인/취소, API 호출, 로딩 상태
 */

import { describe, it, expect } from 'vitest';

// 레이아웃 JSON 임포트
import mainLayout from '../../../layouts/admin/admin_board_settings.json';
import tabBoardSettingsBasic from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_basic.json';
import tabBoardSettingsPermissions from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_permissions.json';
import tabBoardSettingsList from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_list.json';
import tabBoardSettingsPost from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_post.json';
import tabBoardSettingsReply from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_reply.json';
import tabBoardSettingsComment from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_comment.json';
import tabBoardSettingsAttachment from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_attachment.json';
import tabBoardSettingsNotification from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_notification.json';
import tabBoardSettingsBulkApply from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_bulk_apply.json';
import tabReportPolicy from '../../../layouts/admin/partials/admin_board_settings/_tab_report_policy.json';
import tabSpamSecurity from '../../../layouts/admin/partials/admin_board_settings/_tab_spam_security.json';
import bulkApplyModal from '../../../layouts/admin/partials/admin_board_settings/_bulk_apply_modal.json';
import tabGeneral from '../../../layouts/admin/partials/admin_board_settings/_tab_general.json';
import tabSeo from '../../../layouts/admin/partials/admin_board_settings/_tab_seo.json';

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
 * JSON 트리에서 특정 handler를 가진 액션을 모두 찾습니다.
 */
function findActions(node: any, handlerName: string): any[] {
    const results: any[] = [];
    if (!node) return results;

    if (node.actions && Array.isArray(node.actions)) {
        for (const action of node.actions) {
            if (action.handler === handlerName) {
                results.push(action);
            }
            // sequence 내부 검색
            if (action.actions && Array.isArray(action.actions)) {
                for (const subAction of action.actions) {
                    if (subAction.handler === handlerName) {
                        results.push(subAction);
                    }
                    // onSuccess/onError 내부
                    if (subAction.onSuccess && Array.isArray(subAction.onSuccess)) {
                        for (const cb of subAction.onSuccess) {
                            if (cb.handler === handlerName) results.push(cb);
                        }
                    }
                    if (subAction.onError && Array.isArray(subAction.onError)) {
                        for (const cb of subAction.onError) {
                            if (cb.handler === handlerName) results.push(cb);
                        }
                    }
                }
            }
        }
    }

    if (node.children && Array.isArray(node.children)) {
        for (const child of node.children) {
            results.push(...findActions(child, handlerName));
        }
    }

    if (node.slots) {
        for (const slotChildren of Object.values(node.slots)) {
            if (Array.isArray(slotChildren)) {
                for (const child of slotChildren) {
                    results.push(...findActions(child as any, handlerName));
                }
            }
        }
    }

    return results;
}

/**
 * JSON 트리에서 form name prop으로 Input/Select/TagInput/Toggle를 찾습니다.
 */
function findFormFields(node: any): string[] {
    const names: string[] = [];
    if (!node) return names;

    if ((node.name === 'Input' || node.name === 'Select' || node.name === 'TagInput' || node.name === 'Toggle' || node.name === 'RadioGroup' || node.name === 'Textarea') && node.props?.name) {
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

// =============================================
// 1. 메인 레이아웃 (admin_board_settings.json)
// =============================================
describe('admin_board_settings.json - 메인 레이아웃', () => {
    it('레이아웃 메타데이터가 올바르다', () => {
        expect(mainLayout.version).toBe('1.0.0');
        expect(mainLayout.layout_name).toBe('admin_board_settings');
        expect(mainLayout.extends).toBe('_admin_base');
        expect(mainLayout.permissions).toContain('sirsoft-board.settings.read');
        expect(mainLayout.meta.title).toContain('$t:sirsoft-board.admin.settings.title');
    });

    it('데이터 소스가 올바르게 정의되어 있다', () => {
        const { data_sources } = mainLayout;
        expect(data_sources.length).toBeGreaterThanOrEqual(4);

        // settings 데이터 소스
        const settings = data_sources.find((ds: any) => ds.id === 'settings');
        expect(settings).toBeDefined();
        expect(settings.endpoint).toBe('/api/modules/sirsoft-board/admin/settings');
        expect(settings.method).toBe('GET');
        expect(settings.auto_fetch).toBe(true);
        expect(settings.auth_required).toBe(true);
        expect(settings.initLocal).toBe('form');
        expect(settings.refetchOnMount).toBe(true);

        // boards_list 데이터 소스 (일괄 적용용)
        const boardsList = data_sources.find((ds: any) => ds.id === 'boards_list');
        expect(boardsList).toBeDefined();
        expect(boardsList.endpoint).toContain('/api/modules/sirsoft-board/admin/boards');
        expect(boardsList.auto_fetch).toBe(true);

        // roles 데이터 소스 (권한 설정용 - 전체 조회)
        const roles = data_sources.find((ds: any) => ds.id === 'roles');
        expect(roles).toBeDefined();
        expect(roles.endpoint).toBe('/api/admin/roles/active');
        expect(roles.auto_fetch).toBe(true);

        // board_types 데이터 소스 (유형 관리용)
        const boardTypes = data_sources.find((ds: any) => ds.id === 'board_types');
        expect(boardTypes).toBeDefined();
        expect(boardTypes.endpoint).toContain('board-types');
        expect(boardTypes.auto_fetch).toBe(true);
    });

    it('computed에 필수 값들이 정의되어 있다', () => {
        const { computed } = mainLayout;
        expect(computed).toBeDefined();

        // 필수 computed 확인
        const requiredComputed = [
            'boardOptions',
            'adminPermissions',
            'userPermissions',
        ];

        for (const key of requiredComputed) {
            expect(computed, `computed should have ${key}`).toHaveProperty(key);
        }

        // roleOptions computed가 roles?.data?.data 배열을 매핑 (active 엔드포인트는 { data: [...], abilities: {...} } 구조)
        expect(computed).toHaveProperty('roleOptions');
        expect(computed.roleOptions).toContain('roles?.data?.data');

        // 이전 permissionOptions는 삭제되었어야 함
        expect(computed).not.toHaveProperty('permissionOptions');

        // Select options는 정적 배열로 변경되어 computed에 없어야 함
        const removedComputed = [
            'typeOptions',
            'secretModeOptions',
            'orderByOptions',
            'orderDirectionOptions',
            'commentOrderOptions',
            'autoHideTargetOptions',
            'viewCountMethodOptions',
        ];
        for (const key of removedComputed) {
            expect(computed).not.toHaveProperty(key);
        }
    });

    it('TabNavigation에 필수 탭이 정의되어 있다', () => {
        const content = mainLayout.slots.content[0];
        const tabNav = findById(content, 'tab_navigation');
        expect(tabNav).toBeDefined();
        expect(tabNav.name).toBe('TabNavigation');

        const tabs = tabNav.props.tabs;
        expect(tabs.length).toBeGreaterThanOrEqual(3);
        const tabIds = tabs.map((t: any) => t.id);
        expect(tabIds).toContain('basic_defaults');
        expect(tabIds).toContain('report_policy');
        expect(tabIds).toContain('spam_security');
    });

    it('탭 변경 시 글로벌 상태와 URL을 업데이트한다', () => {
        const content = mainLayout.slots.content[0];
        const tabNav = findById(content, 'tab_navigation');
        const tabAction = tabNav.actions[0];

        expect(tabAction.handler).toBe('sequence');
        expect(tabAction.actions).toHaveLength(3);

        // setState - errors 초기화
        expect(tabAction.actions[0].handler).toBe('setState');
        expect(tabAction.actions[0].params.target).toBe('local');
        expect(tabAction.actions[0].params.errors).toBeNull();

        // setState - 글로벌 탭 상태
        expect(tabAction.actions[1].handler).toBe('setState');
        expect(tabAction.actions[1].params.target).toBe('global');

        // replaceUrl - URL 업데이트
        expect(tabAction.actions[2].handler).toBe('replaceUrl');
        expect(tabAction.actions[2].params.query.tab).toBeDefined();
    });

    it('sticky 헤더 구조가 올바르다', () => {
        const content = mainLayout.slots.content[0];
        const stickyHeader = findById(content, 'sticky_header');
        expect(stickyHeader).toBeDefined();
        expect(stickyHeader.props.className).toContain('sticky');
        expect(stickyHeader.props.className).toContain('top-0');
        expect(stickyHeader.props.className).toContain('z-40');

        // 하위 탭 네비게이션이 basic_defaults 탭에서만 표시 (TabNavigationScroll - 스크롤 방식)
        const subTabNav = findById(content, 'sub_tab_navigation');
        expect(subTabNav).toBeDefined();
        expect(subTabNav.name).toBe('TabNavigationScroll');
        expect(subTabNav.if).toContain('basic_defaults');
        expect(subTabNav.props.enableScrollSpy).toBe(true);
        expect(subTabNav.props.sectionIdPrefix).toBe('');

        const subTabs = subTabNav.props.tabs;
        const subTabIds = subTabs.map((t: any) => t.id);
        expect(subTabIds).toContain('basic');
        expect(subTabIds).toContain('permissions');
        expect(subTabIds).toContain('list');
        expect(subTabIds).toContain('post');
        expect(subTabIds).toContain('reply');
        expect(subTabIds).toContain('comment');
        expect(subTabIds).toContain('attachment');
        expect(subTabIds).toContain('notification');
        expect(subTabIds).toContain('bulk_apply');
    });

    it('저장 버튼이 sticky 헤더 안에 있다', () => {
        const content = mainLayout.slots.content[0];
        const stickyHeader = findById(content, 'sticky_header');
        const saveBtn = findById(stickyHeader, 'save_button');
        expect(saveBtn).toBeDefined();

        // 하단 footer_buttons가 없어야 함
        const footerButtons = findById(content, 'footer_buttons');
        expect(footerButtons).toBeNull();
    });

    it('저장 버튼이 올바른 apiCall 구조를 가진다', () => {
        const content = mainLayout.slots.content[0];
        const saveBtn = findById(content, 'save_button');
        expect(saveBtn).toBeDefined();
        expect(saveBtn.props.type).toBe('button');
        expect(saveBtn.props.disabled).toContain('hasChanges');

        const clickAction = saveBtn.actions[0];
        expect(clickAction.handler).toBe('sequence');

        // 1단계: setState isSaving=true
        const setStateAction = clickAction.actions[0];
        expect(setStateAction.handler).toBe('setState');
        expect(setStateAction.params.isSaving).toBe(true);

        // 2단계: apiCall PUT
        const apiCallAction = clickAction.actions[1];
        expect(apiCallAction.handler).toBe('apiCall');
        expect(apiCallAction.target).toBe('/api/modules/sirsoft-board/admin/settings');
        expect(apiCallAction.params.method).toBe('PUT');
        expect(apiCallAction.auth_required).toBe(true);

        // onSuccess: isSaving=false, hasChanges=false, refetchDataSource x4, toast
        expect(apiCallAction.onSuccess).toHaveLength(6);
        expect(apiCallAction.onSuccess[0].params.isSaving).toBe(false);
        expect(apiCallAction.onSuccess[0].params.hasChanges).toBe(false);
        expect(apiCallAction.onSuccess[1].handler).toBe('refetchDataSource');
        expect(apiCallAction.onSuccess[1].params.dataSourceId).toBe('settings');
        expect(apiCallAction.onSuccess[2].handler).toBe('refetchDataSource');
        expect(apiCallAction.onSuccess[2].params.dataSourceId).toBe('boards_list');
        expect(apiCallAction.onSuccess[3].handler).toBe('refetchDataSource');
        expect(apiCallAction.onSuccess[3].params.dataSourceId).toBe('roles');
        expect(apiCallAction.onSuccess[4].handler).toBe('refetchDataSource');
        expect(apiCallAction.onSuccess[4].params.dataSourceId).toBe('board_types');
        expect(apiCallAction.onSuccess[5].handler).toBe('toast');

        // onError: isSaving=false, errors set, toast
        expect(apiCallAction.onError).toHaveLength(2);
        expect(apiCallAction.onError[0].params.isSaving).toBe(false);
        expect(apiCallAction.onError[1].handler).toBe('toast');
    });

    it('validation_error 배너가 조건부 렌더링된다', () => {
        const content = mainLayout.slots.content[0];
        const errorBanner = findById(content, 'validation_error');
        expect(errorBanner).toBeDefined();
        expect(errorBanner.if).toContain('_local.errors');
    });

    it('Partial 참조가 올바르다 (8개 하위 탭 partial 포함)', () => {
        const content = mainLayout.slots.content[0];
        const mainContent = findById(content, 'main_content');
        expect(mainContent).toBeDefined();
        const partials = mainContent.children.filter((c: any) => c.partial);
        expect(partials.length).toBeGreaterThanOrEqual(10);
        const partialPaths = partials.map((p: any) => p.partial);

        // 기본 탭들
        expect(partialPaths.some((p: string) => p.includes('_tab_general.json'))).toBe(true);
        expect(partialPaths.some((p: string) => p.includes('_tab_report_policy.json'))).toBe(true);
        expect(partialPaths.some((p: string) => p.includes('_tab_spam_security.json'))).toBe(true);

        // 게시판설정 8개 하위 탭 partial
        expect(partialPaths.some((p: string) => p.includes('_tab_board_settings_basic.json'))).toBe(true);
        expect(partialPaths.some((p: string) => p.includes('_tab_board_settings_permissions.json'))).toBe(true);
        expect(partialPaths.some((p: string) => p.includes('_tab_board_settings_list.json'))).toBe(true);
        expect(partialPaths.some((p: string) => p.includes('_tab_board_settings_post.json'))).toBe(true);
        expect(partialPaths.some((p: string) => p.includes('_tab_board_settings_reply.json'))).toBe(true);
        expect(partialPaths.some((p: string) => p.includes('_tab_board_settings_comment.json'))).toBe(true);
        expect(partialPaths.some((p: string) => p.includes('_tab_board_settings_attachment.json'))).toBe(true);
        expect(partialPaths.some((p: string) => p.includes('_tab_board_settings_notification.json'))).toBe(true);
        expect(partialPaths.some((p: string) => p.includes('_tab_board_settings_bulk_apply.json'))).toBe(true);

        // 기존 _tab_basic_defaults.json은 더 이상 사용하지 않음
        expect(partialPaths.some((p: string) => p.includes('_tab_basic_defaults.json'))).toBe(false);
    });

    it('모달 Partial 참조가 올바르다', () => {
        expect(mainLayout.modals.length).toBeGreaterThanOrEqual(2);
        expect(mainLayout.modals.some((m: any) => m.partial?.includes('_bulk_apply_modal.json'))).toBe(true);
        expect(mainLayout.modals.some((m: any) => m.partial?.includes('_board_type_manage_modal.json'))).toBe(true);
    });

    it('settings_content에 trackChanges와 dataKey가 설정되어 있다', () => {
        const content = mainLayout.slots.content[0];
        expect(content.dataKey).toBe('form');
        expect(content.trackChanges).toBe(true);
    });

});

// =============================================
// 2. 게시판설정 하위 탭 - 기본 (_tab_board_settings_basic.json)
// =============================================
describe('_tab_board_settings_basic.json - 기본 하위 탭', () => {
    it('Partial 메타데이터가 올바르다', () => {
        expect(tabBoardSettingsBasic.meta.is_partial).toBe(true);
        expect(tabBoardSettingsBasic.if).toContain("'basic_defaults'");
        expect(tabBoardSettingsBasic.id).toBe('basic');
    });

    it('페이지 안내 배너(page_info_banner)가 섹션 목록 상단에 존재한다', () => {
        const banner = findById(tabBoardSettingsBasic, 'page_info_banner');
        expect(banner).toBeDefined();
        expect(banner.props.className).toContain('bg-blue-50');
        expect(banner.props.className).toContain('dark:bg-blue-900');
        const texts = findByName(banner, 'P');
        expect(texts.some((p: any) => p.text?.includes('page_info'))).toBe(true);
    });

    it('SectionLayout을 사용한다', () => {
        const sections = findByName(tabBoardSettingsBasic, 'SectionLayout');
        expect(sections.length).toBeGreaterThanOrEqual(1);
        for (const section of sections) {
            expect(section.props.className).toContain('bg-white');
            expect(section.props.className).toContain('dark:bg-gray-800');
        }
    });

    it('기본 설정 섹션에 필수 폼 필드가 있다', () => {
        const sectionBasic = findById(tabBoardSettingsBasic, 'section_basic');
        const fields = findFormFields(sectionBasic);

        expect(fields).toContain('basic_defaults.type');
        expect(fields).toContain('basic_defaults.secret_mode');
        expect(fields).toContain('basic_defaults.show_view_count');
        expect(fields).toContain('basic_defaults.use_report');
    });

    it('각 필드에 일괄 적용 체크박스가 있고 Label 클릭으로 토글된다', () => {
        const fieldType = findById(tabBoardSettingsBasic, 'field_type');
        expect(fieldType).toBeDefined();

        const checkboxes = findByName(fieldType, 'Input').filter(
            (c: any) => c.props?.type === 'checkbox' && c.props?.className?.includes('checkbox')
        );
        expect(checkboxes.length).toBeGreaterThanOrEqual(1);
        expect(checkboxes[0].actions).toBeUndefined();

        const labels = findByName(fieldType, 'Label');
        const labelWithClick = labels.find(
            (l: any) => l.actions?.some((a: any) => a.type === 'click' && a.handler === 'setState')
        );
        expect(labelWithClick).toBeDefined();
        const clickAction = labelWithClick!.actions.find((a: any) => a.type === 'click');
        expect(clickAction.params.bulkApplyFields).toBeDefined();
    });
});

// =============================================
// 3. 게시판설정 하위 탭 - 권한 (_tab_board_settings_permissions.json)
// =============================================
describe('_tab_board_settings_permissions.json - 권한 하위 탭', () => {
    it('Partial 메타데이터가 올바르다', () => {
        expect(tabBoardSettingsPermissions.meta.is_partial).toBe(true);
        expect(tabBoardSettingsPermissions.if).toContain("'basic_defaults'");
        expect(tabBoardSettingsPermissions.id).toBe('permissions');
    });

    it('권한 섹션이 iteration 기반 TagInput 패턴을 사용한다', () => {
        const sectionPermissions = findById(tabBoardSettingsPermissions, 'section_permissions');
        expect(sectionPermissions).toBeDefined();

        const adminSection = findById(tabBoardSettingsPermissions, 'permissions_admin_section');
        expect(adminSection).toBeDefined();
        const adminIterDiv = findByName(adminSection, 'Div').find(
            (d: any) => d.iteration?.source?.includes('adminPermissions')
        );
        expect(adminIterDiv).toBeDefined();
        expect(adminIterDiv.iteration.item_var).toBe('perm');

        const adminTagInputs = findByName(adminSection, 'TagInput');
        expect(adminTagInputs.length).toBeGreaterThanOrEqual(1);
        expect(adminTagInputs[0].props.defaultVariant).toBe('purple');

        const userSection = findById(tabBoardSettingsPermissions, 'permissions_user_section');
        expect(userSection).toBeDefined();
        const userIterDiv = findByName(userSection, 'Div').find(
            (d: any) => d.iteration?.source?.includes('userPermissions')
        );
        expect(userIterDiv).toBeDefined();
        expect(userIterDiv.iteration.item_var).toBe('perm');

        const userTagInputs = findByName(userSection, 'TagInput');
        expect(userTagInputs.length).toBeGreaterThanOrEqual(1);
        expect(userTagInputs[0].props.defaultVariant).toBe('blue');
    });

    it('권한 TagInput이 value prop과 perm?.[0] optional chaining을 사용한다', () => {
        const sectionPermissions = findById(tabBoardSettingsPermissions, 'section_permissions');
        const tagInputs = findByName(sectionPermissions, 'TagInput');
        for (const tagInput of tagInputs) {
            expect(tagInput.props.name).toBeUndefined();
            expect(tagInput.props.value).toContain('default_board_permissions');
            expect(tagInput.props.value).toContain('perm?.[0]');
        }
    });
});

// =============================================
// 4. 게시판설정 하위 탭 - 목록 (_tab_board_settings_list.json)
// =============================================
describe('_tab_board_settings_list.json - 목록 하위 탭', () => {
    it('Partial 메타데이터가 올바르다', () => {
        expect(tabBoardSettingsList.meta.is_partial).toBe(true);
        expect(tabBoardSettingsList.if).toContain("'basic_defaults'");
        expect(tabBoardSettingsList.id).toBe('list');
    });

    it('목록 설정 필수 폼 필드가 있다', () => {
        const fields = findFormFields(tabBoardSettingsList);
        expect(fields).toContain('basic_defaults.per_page');
        expect(fields).toContain('basic_defaults.per_page_mobile');
        expect(fields).toContain('basic_defaults.order_by');
        expect(fields).toContain('basic_defaults.order_direction');
    });
});

// =============================================
// 5. 게시판설정 하위 탭 - 게시글 (_tab_board_settings_post.json)
// =============================================
describe('_tab_board_settings_post.json - 게시글 하위 탭', () => {
    it('Partial 메타데이터가 올바르다', () => {
        expect(tabBoardSettingsPost.meta.is_partial).toBe(true);
        expect(tabBoardSettingsPost.if).toContain("'basic_defaults'");
        expect(tabBoardSettingsPost.id).toBe('post');
    });

    it('게시글 설정 필수 폼 필드가 있다', () => {
        const fields = findFormFields(tabBoardSettingsPost);
        expect(fields).toContain('basic_defaults.min_title_length');
        expect(fields).toContain('basic_defaults.max_title_length');
        expect(fields).toContain('basic_defaults.min_content_length');
        expect(fields).toContain('basic_defaults.max_content_length');
        expect(fields).toContain('basic_defaults.blocked_keywords');
        expect(fields).toContain('basic_defaults.new_display_hours');
    });
});

// =============================================
// 6. 게시판설정 하위 탭 - 답변글 (_tab_board_settings_reply.json)
// =============================================
describe('_tab_board_settings_reply.json - 답변글 하위 탭', () => {
    it('Partial 메타데이터가 올바르다', () => {
        expect(tabBoardSettingsReply.meta.is_partial).toBe(true);
        expect(tabBoardSettingsReply.if).toContain("'basic_defaults'");
        expect(tabBoardSettingsReply.id).toBe('reply');
    });

    it('답변글 설정 필수 폼 필드가 있다', () => {
        const fields = findFormFields(tabBoardSettingsReply);
        expect(fields).toContain('basic_defaults.use_reply');
        expect(fields).toContain('basic_defaults.max_reply_depth');
    });

    it('답변글 깊이 필드가 항상 표시된다 (if 조건 없음)', () => {
        const maxReplyDepth = findById(tabBoardSettingsReply, 'field_max_reply_depth');
        expect(maxReplyDepth).toBeDefined();
        expect(maxReplyDepth.if).toBeUndefined();
    });
});

// =============================================
// 7. 게시판설정 하위 탭 - 댓글 (_tab_board_settings_comment.json)
// =============================================
describe('_tab_board_settings_comment.json - 댓글 하위 탭', () => {
    it('Partial 메타데이터가 올바르다', () => {
        expect(tabBoardSettingsComment.meta.is_partial).toBe(true);
        expect(tabBoardSettingsComment.if).toContain("'basic_defaults'");
        expect(tabBoardSettingsComment.id).toBe('comment');
    });

    it('댓글 설정 필수 폼 필드가 있다', () => {
        const fields = findFormFields(tabBoardSettingsComment);
        expect(fields).toContain('basic_defaults.use_comment');
        expect(fields).toContain('basic_defaults.max_comment_depth');
        expect(fields).toContain('basic_defaults.comment_order');
        expect(fields).toContain('basic_defaults.min_comment_length');
        expect(fields).toContain('basic_defaults.max_comment_length');
    });

    it('댓글 설정 필드가 항상 표시된다 (if 조건 없음)', () => {
        const commentOrder = findById(tabBoardSettingsComment, 'field_comment_order');
        expect(commentOrder).toBeDefined();
        expect(commentOrder.if).toBeUndefined();

        const minCommentLen = findById(tabBoardSettingsComment, 'field_min_comment_length');
        expect(minCommentLen).toBeDefined();
        expect(minCommentLen.if).toBeUndefined();
    });
});

// =============================================
// 8. 게시판설정 하위 탭 - 첨부파일 (_tab_board_settings_attachment.json)
// =============================================
describe('_tab_board_settings_attachment.json - 첨부파일 하위 탭', () => {
    it('Partial 메타데이터가 올바르다', () => {
        expect(tabBoardSettingsAttachment.meta.is_partial).toBe(true);
        expect(tabBoardSettingsAttachment.if).toContain("'basic_defaults'");
        expect(tabBoardSettingsAttachment.id).toBe('attachment');
    });

    it('첨부파일 설정 필수 폼 필드가 있다', () => {
        const fields = findFormFields(tabBoardSettingsAttachment);
        expect(fields).toContain('basic_defaults.use_file_upload');
        expect(fields).toContain('basic_defaults.max_file_size');
        expect(fields).toContain('basic_defaults.max_file_count');
        expect(fields).toContain('basic_defaults.allowed_extensions');
    });

    it('첨부파일 서브 필드가 항상 표시된다 (if 조건 없음)', () => {
        const maxFileSize = findById(tabBoardSettingsAttachment, 'field_max_file_size');
        expect(maxFileSize).toBeDefined();
        expect(maxFileSize.if).toBeUndefined();

        const maxFileCount = findById(tabBoardSettingsAttachment, 'field_max_file_count');
        expect(maxFileCount).toBeDefined();
        expect(maxFileCount.if).toBeUndefined();

        const allowedExt = findById(tabBoardSettingsAttachment, 'field_allowed_extensions');
        expect(allowedExt).toBeDefined();
        expect(allowedExt.if).toBeUndefined();
    });
});

// =============================================
// 9. 게시판설정 하위 탭 - 알림 (_tab_board_settings_notification.json)
// =============================================
describe('_tab_board_settings_notification.json - 알림 하위 탭', () => {
    it('Partial 메타데이터가 올바르다', () => {
        expect(tabBoardSettingsNotification.meta.is_partial).toBe(true);
        expect(tabBoardSettingsNotification.if).toContain("'basic_defaults'");
        expect(tabBoardSettingsNotification.id).toBe('notification');
    });

    it('알림 설정 필수 폼 필드가 있다', () => {
        const fields = findFormFields(tabBoardSettingsNotification);
        expect(fields).toContain('basic_defaults.notify_author');
        expect(fields).toContain('basic_defaults.notify_admin_on_post');
    });
});

// =============================================
// 10. 게시판설정 하위 탭 - 일괄 적용 (_tab_board_settings_bulk_apply.json)
// =============================================
describe('_tab_board_settings_bulk_apply.json - 일괄 적용 하위 탭', () => {
    it('Partial 메타데이터가 올바르다', () => {
        expect(tabBoardSettingsBulkApply.meta.is_partial).toBe(true);
        expect(tabBoardSettingsBulkApply.if).toContain("'basic_defaults'");
        expect(tabBoardSettingsBulkApply.id).toBe('bulk_apply');
    });

    it('일괄 적용 섹션(section_bulk_apply)이 존재한다', () => {
        const bulkApply = findById(tabBoardSettingsBulkApply, 'section_bulk_apply');
        expect(bulkApply).toBeDefined();

        const noBoardsNotice = findById(tabBoardSettingsBulkApply, 'no_boards_notice');
        expect(noBoardsNotice).toBeDefined();
        expect(noBoardsNotice.if).toContain('boards_list');

        const targetArea = findById(tabBoardSettingsBulkApply, 'bulk_apply_target');
        expect(targetArea).toBeDefined();
        expect(targetArea.if).toContain('boards_list');
        expect(targetArea.if).toContain('length > 0');
    });

    it('일괄 적용 버튼이 openModal을 target으로 호출한다', () => {
        const bulkApply = findById(tabBoardSettingsBulkApply, 'section_bulk_apply');
        const openModalActions = findActions(bulkApply, 'openModal');
        expect(openModalActions).toHaveLength(1);
        expect(openModalActions[0].target).toBe('bulk_apply_confirm_modal');
    });
});

// =============================================
// 11. 신고 정책 탭 (_tab_report_policy.json)
// =============================================
describe('_tab_report_policy.json - 신고 정책 탭', () => {
    it('Partial 메타데이터가 올바르다', () => {
        expect(tabReportPolicy.meta.is_partial).toBe(true);
        expect(tabReportPolicy.id).toBe('tab_content_report_policy');
        expect(tabReportPolicy.if).toContain("'report_policy'");
    });

    it('SectionLayout을 사용한다', () => {
        const sections = findByName(tabReportPolicy, 'SectionLayout');
        expect(sections.length).toBeGreaterThanOrEqual(1);
        expect(sections[0].props.className).toContain('bg-white');
        expect(sections[0].props.className).toContain('dark:bg-gray-800');
    });

    it('신고 정책 필드가 모두 존재한다', () => {
        const fields = findFormFields(tabReportPolicy);

        expect(fields).toContain('report_policy.auto_hide_threshold');
        expect(fields).toContain('report_policy.auto_hide_target');
        expect(fields).toContain('report_policy.daily_report_limit');
        expect(fields).toContain('report_policy.rejection_limit_count');
        expect(fields).toContain('report_policy.rejection_limit_days');
        expect(fields).not.toContain('report_policy.suspension_days');
    });

    it('알림 설정 필드가 존재한다 (이슈 #35 Phase 1+2)', () => {
        const fields = findFormFields(tabReportPolicy);

        // Phase 2: 신고 접수 관리자 알림 토글 + 이메일 채널 TagInput
        expect(fields).toContain('report_policy.notify_admin_on_report');
        expect(fields).toContain('report_policy.notify_admin_on_report_channels');

        // Phase 1: 신고 처리 알림 토글 + 이메일 채널 TagInput
        expect(fields).toContain('report_policy.notify_author_on_report_action');
        expect(fields).toContain('report_policy.notify_author_on_report_action_channels');
    });

    it('Phase 3: notify_admin_on_report_scope RadioGroup가 존재한다', () => {
        const fields = findFormFields(tabReportPolicy);
        expect(fields).toContain('report_policy.notify_admin_on_report_scope');

        // RadioGroup 컴포넌트로 구현 (Select → RadioGroup으로 변경됨)
        const scopeField = findById(tabReportPolicy, 'field_notify_admin_on_report_scope');
        expect(scopeField).toBeDefined();

        const radioGroups = findByName(scopeField, 'RadioGroup');
        expect(radioGroups).toHaveLength(1);
        expect(radioGroups[0].type).toBe('composite');
        expect(radioGroups[0].props.name).toBe('report_policy.notify_admin_on_report_scope');

        // per_case / per_report 옵션 포함
        const options = radioGroups[0].props.options;
        expect(Array.isArray(options)).toBe(true);
        const values = (options as any[]).map((o: any) => o.value);
        expect(values).toContain('per_case');
        expect(values).toContain('per_report');
    });

    it('Phase 3: 신고 관리 권한 섹션(section_report_permissions)이 존재한다', () => {
        const section = findById(tabReportPolicy, 'section_report_permissions');
        expect(section).toBeDefined();
        expect(section.name).toBe('SectionLayout');
    });

    it('Phase 3: 신고 관리 권한 TagInput × 2 (view_roles, manage_roles)가 존재한다', () => {
        const fields = findFormFields(tabReportPolicy);
        expect(fields).toContain('report_permissions.view_roles');
        expect(fields).toContain('report_permissions.manage_roles');

        // view_roles TagInput
        const viewField = findById(tabReportPolicy, 'field_report_view_roles');
        expect(viewField).toBeDefined();
        const viewTagInputs = findByName(viewField, 'TagInput');
        expect(viewTagInputs).toHaveLength(1);
        expect(viewTagInputs[0].props.name).toBe('report_permissions.view_roles');
        // roles computed (_computed.roleOptions) 사용 — section_report_permissions는 computed 경유
        expect(viewTagInputs[0].props.options).toContain('roleOptions');

        // manage_roles TagInput
        const manageField = findById(tabReportPolicy, 'field_report_manage_roles');
        expect(manageField).toBeDefined();
        const manageTagInputs = findByName(manageField, 'TagInput');
        expect(manageTagInputs).toHaveLength(1);
        expect(manageTagInputs[0].props.name).toBe('report_permissions.manage_roles');
        expect(manageTagInputs[0].props.options).toContain('roleOptions');
    });

    it('필드에 유효한 min/max 제약이 있다', () => {
        const autoHide = findById(tabReportPolicy, 'field_auto_hide_threshold');
        const autoHideInput = findByName(autoHide, 'Input').find(
            (c: any) => c.props?.name === 'report_policy.auto_hide_threshold'
        );
        expect(autoHideInput.props.min).toBe(1);
        expect(autoHideInput.props.max).toBe(100);

    });

    it('Select가 composite 타입이다', () => {
        const selects = findByName(tabReportPolicy, 'Select');
        for (const select of selects) {
            expect(select.type).toBe('composite');
        }
    });

    it('Input에 게시판 폼 스타일 클래스가 적용되어 있다', () => {
        const inputs = findByName(tabReportPolicy, 'Input').filter(
            (c: any) => c.props?.type === 'number'
        );
        expect(inputs.length).toBeGreaterThan(0);

        for (const input of inputs) {
            expect(input.props.className).toContain('border');
            expect(input.props.className).toContain('rounded-md');
            expect(input.props.className).toContain('dark:bg-gray-700');
        }
    });
});

// =============================================
// 11. 스팸/보안 탭 (_tab_spam_security.json)
// =============================================
describe('_tab_spam_security.json - 스팸/보안 탭', () => {
    it('Partial 메타데이터가 올바르다', () => {
        expect(tabSpamSecurity.meta.is_partial).toBe(true);
        expect(tabSpamSecurity.id).toBe('tab_content_spam_security');
        expect(tabSpamSecurity.if).toContain("'spam_security'");
    });

    it('SectionLayout을 사용한다', () => {
        const sections = findByName(tabSpamSecurity, 'SectionLayout');
        expect(sections.length).toBeGreaterThanOrEqual(2);
    });

    it('스팸/보안 필드가 모두 존재한다 (blocked_keywords 제외)', () => {
        const fields = findFormFields(tabSpamSecurity);

        expect(fields).toContain('spam_security.post_cooldown_seconds');
        expect(fields).toContain('spam_security.comment_cooldown_seconds');
        expect(fields).toContain('spam_security.view_count_cache_ttl');

        // blocked_keywords는 기본 설정 탭으로 이동했으므로 여기에 없어야 함
        expect(fields).not.toContain('spam_security.blocked_keywords');
    });

    it('쿨다운 안내 정보 배너가 있다', () => {
        const infoBanner = findById(tabSpamSecurity, 'spam_security_identifier_info');
        expect(infoBanner).toBeDefined();

        const icon = findByName(infoBanner, 'Icon');
        expect(icon.length).toBeGreaterThan(0);
        expect(icon[0].props.name).toBe('circle-info');
    });

    it('캐시 초기화 버튼이 올바른 apiCall을 가진다', () => {
        const clearCacheBtn = findById(tabSpamSecurity, 'clear_cache_button');
        expect(clearCacheBtn).toBeDefined();
        expect(clearCacheBtn.props.type).toBe('button');

        const apiCallActions = findActions(clearCacheBtn, 'apiCall');
        expect(apiCallActions).toHaveLength(1);
        expect(apiCallActions[0].target).toBe('/api/modules/sirsoft-board/admin/settings/clear-cache');
        expect(apiCallActions[0].params.method).toBe('POST');
    });

    it('Select가 composite 타입이다', () => {
        const selects = findByName(tabSpamSecurity, 'Select');
        for (const select of selects) {
            expect(select.type).toBe('composite');
        }
    });
});

// =============================================
// 12. 일괄 적용 모달 (_bulk_apply_modal.json)
// =============================================
describe('_bulk_apply_modal.json - 일괄 적용 확인 모달', () => {
    it('Partial 메타데이터가 올바르다', () => {
        expect(bulkApplyModal.meta.is_partial).toBe(true);
        expect(bulkApplyModal.id).toBe('bulk_apply_confirm_modal');
        expect(bulkApplyModal.type).toBe('composite');
        expect(bulkApplyModal.name).toBe('Modal');
    });

    it('모달에 적용 범위 amber 배너가 있다', () => {
        const paragraphs = findByName(bulkApplyModal, 'P');
        // message_all 또는 message 키를 포함하는 P가 있어야 함
        const messageAll = paragraphs.find((p: any) => p.text?.includes('bulk_apply_modal.message_all'));
        const message = paragraphs.find((p: any) => p.text?.includes('bulk_apply_modal.message') && !p.text?.includes('message_all'));
        expect(messageAll ?? message).toBeDefined();
    });

    it('취소 버튼이 closeModal을 호출하고 로딩 시 비활성화된다', () => {
        const buttons = findByName(bulkApplyModal, 'Button');
        const cancelBtn = buttons.find(
            (b: any) => !b.id && b.actions?.some((a: any) => a.handler === 'closeModal')
        );
        expect(cancelBtn).toBeDefined();
        expect(cancelBtn.props.disabled).toContain('isBulkApplying');

        const closeAction = cancelBtn.actions.find((a: any) => a.handler === 'closeModal');
        // closeModal은 params 없이 호출해야 함
        expect(closeAction.params).toBeUndefined();
    });

    it('확인 버튼이 올바른 API 호출 sequence를 가진다', () => {
        const confirmBtn = findById(bulkApplyModal, 'bulk_apply_confirm_button');
        expect(confirmBtn).toBeDefined();
        expect(confirmBtn.props.disabled).toContain('isBulkApplying');

        const clickAction = confirmBtn.actions[0];
        expect(clickAction.handler).toBe('sequence');

        // 1: setState - isBulkApplying=true (스냅샷은 모달 열기 전 탭 버튼 클릭 시 이미 _global에 저장됨)
        const loadingAction = clickAction.actions[0];
        expect(loadingAction.handler).toBe('setState');
        expect(loadingAction.params.target).toBe('$parent._local');
        expect(loadingAction.params.isBulkApplying).toBe(true);

        // 2: apiCall - 저장 (PUT)
        const saveApiCall = clickAction.actions[1];
        expect(saveApiCall.handler).toBe('apiCall');
        expect(saveApiCall.target).toBe('/api/modules/sirsoft-board/admin/settings');
        expect(saveApiCall.params.method).toBe('PUT');
        // body는 _global.bulkApplySnapshot을 사용
        expect(saveApiCall.params.body).toContain('bulkApplySnapshot');

        // 저장 성공 시: hasChanges=false 후 bulk-apply API 호출
        expect(saveApiCall.onSuccess[0].handler).toBe('setState');
        expect(saveApiCall.onSuccess[0].params.target).toBe('$parent._local');
        expect(saveApiCall.onSuccess[0].params.hasChanges).toBe(false);

        const bulkApiCall = saveApiCall.onSuccess[1];
        expect(bulkApiCall.handler).toBe('apiCall');
        expect(bulkApiCall.target).toBe('/api/modules/sirsoft-board/admin/settings/bulk-apply');
        expect(bulkApiCall.params.method).toBe('POST');
        // body는 _global.bulkApplySnapshot을 사용
        expect(bulkApiCall.params.body).toContain('bulkApplySnapshot');

        // bulk-apply 성공: setState(isBulkApplying=false, fields 초기화) → setState(snapshot=null) → closeModal → toast
        expect(bulkApiCall.onSuccess).toHaveLength(4);
        expect(bulkApiCall.onSuccess[0].handler).toBe('setState');
        expect(bulkApiCall.onSuccess[0].params.isBulkApplying).toBe(false);
        expect(bulkApiCall.onSuccess[1].handler).toBe('setState');
        expect(bulkApiCall.onSuccess[1].params.target).toBe('global');
        expect(bulkApiCall.onSuccess[1].params.bulkApplySnapshot).toBeNull();
        expect(bulkApiCall.onSuccess[2].handler).toBe('closeModal');
        expect(bulkApiCall.onSuccess[3].handler).toBe('toast');

        // bulk-apply 실패: setState(isBulkApplying=false) → setState(snapshot=null) → toast
        expect(bulkApiCall.onError).toHaveLength(3);
        expect(bulkApiCall.onError[0].handler).toBe('setState');
        expect(bulkApiCall.onError[0].params.isBulkApplying).toBe(false);
        expect(bulkApiCall.onError[1].handler).toBe('setState');
        expect(bulkApiCall.onError[1].params.bulkApplySnapshot).toBeNull();
        expect(bulkApiCall.onError[2].handler).toBe('toast');

        // 저장 실패: setState(isBulkApplying=false, bulkApplySaveError=true) → setState(snapshot=null)
        expect(saveApiCall.onError).toHaveLength(2);
        expect(saveApiCall.onError[0].handler).toBe('setState');
        expect(saveApiCall.onError[0].params.isBulkApplying).toBe(false);
        expect(saveApiCall.onError[0].params.bulkApplySaveError).toBe(true);
        expect(saveApiCall.onError[1].handler).toBe('setState');
        expect(saveApiCall.onError[1].params.bulkApplySnapshot).toBeNull();
    });

    it('모달 상태 접근이 $parent._local 또는 global 패턴을 사용한다', () => {
        const confirmBtn = findById(bulkApplyModal, 'bulk_apply_confirm_button');
        expect(confirmBtn.props.disabled).toContain('$parent._local');

        // setState 액션은 target이 $parent._local 또는 global이어야 함
        const stateActions = findActions(bulkApplyModal, 'setState');
        for (const action of stateActions) {
            expect(['$parent._local', 'global']).toContain(action.params.target);
        }
    });

    it('저장 후 적용 내용이 amber 배너 메시지에 포함된다', () => {
        // message 키가 amber 배너에 사용되는지 JSON으로 검증 (message_all 제거, message로 통합)
        const json = JSON.stringify(bulkApplyModal);
        expect(json).toContain('bulk_apply_modal.message');
    });

    it('저장 실패 에러 영역이 조건부로 표시된다', () => {
        // bulkApplySaveError가 true일 때 에러 메시지가 표시되어야 함
        const json = JSON.stringify(bulkApplyModal);
        expect(json).toContain('bulkApplySaveError');
        expect(json).toContain('bulk_apply_modal.save_error');
    });
});

// =============================================
// 13. 크로스 파일 검증
// =============================================
describe('크로스 파일 검증', () => {
    it('모든 하위 탭 Partial의 조건이 basic_defaults 탭 ID를 포함한다', () => {
        const subTabPartials = [
            tabBoardSettingsBasic,
            tabBoardSettingsPermissions,
            tabBoardSettingsList,
            tabBoardSettingsPost,
            tabBoardSettingsReply,
            tabBoardSettingsComment,
            tabBoardSettingsAttachment,
            tabBoardSettingsNotification,
            tabBoardSettingsBulkApply,
        ];
        for (const partial of subTabPartials) {
            expect((partial as any).if).toContain('basic_defaults');
        }
        expect((tabReportPolicy as any).if).toContain('report_policy');
        expect((tabSpamSecurity as any).if).toContain('spam_security');
    });

    it('모달 ID가 openModal target과 일치한다', () => {
        expect(bulkApplyModal.id).toBe('bulk_apply_confirm_modal');

        // openModal target - section_bulk_apply는 일괄 적용 하위 탭에 존재
        const bulkApply = findById(tabBoardSettingsBulkApply, 'section_bulk_apply');
        const openModalActions = findActions(bulkApply, 'openModal');
        expect(openModalActions[0].target).toBe('bulk_apply_confirm_modal');
    });

    it('모든 레이아웃에서 다크 모드 클래스가 사용된다', () => {
        const layouts = [
            mainLayout,
            tabBoardSettingsBasic,
            tabBoardSettingsPermissions,
            tabBoardSettingsList,
            tabBoardSettingsPost,
            tabBoardSettingsReply,
            tabBoardSettingsComment,
            tabBoardSettingsAttachment,
            tabBoardSettingsNotification,
            tabBoardSettingsBulkApply,
            tabReportPolicy,
            tabSpamSecurity,
            bulkApplyModal,
        ];

        for (const layout of layouts) {
            const json = JSON.stringify(layout);
            const classNames = json.match(/"className":"[^"]+"/g) ?? [];
            for (const cls of classNames) {
                const value = cls.replace(/"className":"/, '').replace(/"$/, '');
                if (value.includes('bg-white') && !value.includes('bg-white/')) {
                    expect(value).toContain('dark:bg-');
                }
                if (value.includes('text-gray-') && !value.startsWith('{{')) {
                    expect(value).toContain('dark:text-');
                }
            }
        }
    });

    it('모든 Select 컴포넌트에 정적 options 배열 또는 computed fallback이 있다', () => {
        const layouts = [
            tabBoardSettingsBasic,
            tabBoardSettingsList,
            tabBoardSettingsComment,
            tabReportPolicy,
            tabSpamSecurity,
        ];

        for (const layout of layouts) {
            const selects = findByName(layout, 'Select');
            for (const select of selects) {
                if (select.props?.options) {
                    if (typeof select.props.options === 'string') {
                        expect(select.props.options).toContain('?? []');
                    } else {
                        expect(Array.isArray(select.props.options)).toBe(true);
                        expect(select.props.options.length).toBeGreaterThan(0);
                    }
                }
            }
        }
    });

    it('저장 body에 notification_channels 포함 로직이 있다', () => {
        const content = mainLayout.slots.content[0];
        const saveBtn = findById(content, 'save_button');
        const apiCallAction = saveBtn.actions[0].actions[1];

        const body = apiCallAction.params.body;
        expect(body).toContain('notification_channels');
        expect(body).toContain('basic_defaults');
    });

    it('blocked_keywords가 게시글 하위 탭의 section_post에 존재한다', () => {
        const sectionPost = findById(tabBoardSettingsPost, 'section_post');
        const postFields = findFormFields(sectionPost);
        expect(postFields).toContain('basic_defaults.blocked_keywords');

        // 기본 탭 section_basic에는 없어야 함
        const sectionBasic = findById(tabBoardSettingsBasic, 'section_basic');
        const basicFields = findFormFields(sectionBasic);
        expect(basicFields).not.toContain('basic_defaults.blocked_keywords');

        // 스팸/보안 탭에도 없어야 함
        const spamFields = findFormFields(tabSpamSecurity);
        expect(spamFields).not.toContain('spam_security.blocked_keywords');
    });
});

describe('기본 설정 탭 (general) - 날짜 표시 방식', () => {
    it('Partial 메타 정보가 올바르다', () => {
        expect((tabGeneral as any).meta?.is_partial).toBe(true);
    });

    it('general 탭 조건부 렌더링이 설정되어 있다', () => {
        const ifExpr = (tabGeneral as any).if as string;
        expect(ifExpr).toContain('general');
    });

    it('general_section이 존재한다', () => {
        const section = findById(tabGeneral, 'general_section');
        expect(section).not.toBeNull();
    });

    it('date_display_format_field가 존재한다', () => {
        const field = findById(tabGeneral, 'date_display_format_field');
        expect(field).not.toBeNull();
    });

    it('standard 옵션 라디오 버튼이 존재한다', () => {
        const option = findById(tabGeneral, 'date_format_option_standard');
        expect(option).not.toBeNull();

        // click 액션으로 date_display_format: "standard" 설정
        const clickAction = option.actions?.find((a: any) => a.type === 'click');
        expect(clickAction).toBeDefined();
        expect(clickAction.handler).toBe('setState');
        expect(clickAction.params?.form?.display?.date_display_format).toBe('standard');
    });

    it('relative 옵션 라디오 버튼이 존재한다', () => {
        const option = findById(tabGeneral, 'date_format_option_relative');
        expect(option).not.toBeNull();

        // click 액션으로 date_display_format: "relative" 설정
        const clickAction = option.actions?.find((a: any) => a.type === 'click');
        expect(clickAction).toBeDefined();
        expect(clickAction.handler).toBe('setState');
        expect(clickAction.params?.form?.display?.date_display_format).toBe('relative');
    });

    it('라디오 버튼 name이 display.date_display_format이다', () => {
        const inputs = findByName(tabGeneral, 'Input');
        const radioInputs = inputs.filter((i: any) => i.props?.type === 'radio');
        expect(radioInputs.length).toBeGreaterThan(0);
        expect(radioInputs[0].props?.name).toBe('display.date_display_format');
    });

    it('다국어 키가 모듈 네임스페이스를 포함한다', () => {
        const allTexts: string[] = [];
        function collectTexts(node: any): void {
            if (!node) return;
            if (typeof node.text === 'string' && node.text.startsWith('$t:')) {
                allTexts.push(node.text);
            }
            if (Array.isArray(node.children)) node.children.forEach(collectTexts);
            if (node.slots) {
                Object.values(node.slots).forEach((s: any) => {
                    if (Array.isArray(s)) s.forEach(collectTexts);
                });
            }
        }
        collectTexts(tabGeneral);

        for (const text of allTexts) {
            expect(text).toMatch(/^\$t:sirsoft-board\./);
        }
    });

    it('main 레이아웃에 general 탭이 TabNavigation에 포함되어 있다', () => {
        // TabNavigation 컴포넌트에서 general 탭 항목 확인
        const tabNavList = findByName(mainLayout, 'TabNavigation');
        expect(tabNavList.length).toBeGreaterThan(0);
        const tabNav = tabNavList[0];
        const tabs = tabNav?.props?.tabs as any[];
        expect(tabs).toBeDefined();
        const generalTab = tabs.find((t: any) => t.id === 'general' || t.value === 'general');
        expect(generalTab).toBeDefined();
    });

    it('저장 body에 general 탭 분기 로직이 있다', () => {
        const content = mainLayout.slots.content[0];
        const saveBtn = findById(content, 'save_button');
        const apiCallAction = saveBtn.actions[0].actions[1];
        const body = apiCallAction.params.body as string;

        // general 탭에서 display 카테고리 전송
        expect(body).toContain('general');
        expect(body).toContain('display');
    });
});

// =============================================
// N. SEO 탭 (_tab_seo.json)
// =============================================
describe('_tab_seo.json - SEO 설정 탭', () => {
    it('Partial 메타데이터가 올바르다', () => {
        expect(tabSeo.meta.is_partial).toBe(true);
        expect(tabSeo.if).toContain("'seo'");
        expect(tabSeo.id).toBe('tab_content_seo');
    });

    it('탭 가시성 조건이 올바르다', () => {
        // activeBoardSettingsTab 또는 query.tab이 'seo'일 때만 표시
        expect(tabSeo.if).toContain('activeBoardSettingsTab');
        expect(tabSeo.if).toContain('query.tab');
        expect(tabSeo.if).toContain("'general'");
    });

    it('메타 설정 카드가 존재한다', () => {
        const metaCard = findById(tabSeo, 'meta_settings_card');
        expect(metaCard).toBeDefined();
        expect(metaCard.name).toBe('Div');
    });

    it('아코디언 3개가 올바르게 정의되어 있다', () => {
        const accordions = [
            'accordion_boards_page',
            'accordion_board_page',
            'accordion_post_page',
        ];
        for (const id of accordions) {
            const node = findById(tabSeo, id);
            expect(node, `${id} 아코디언이 없다`).toBeDefined();
            expect(node.props.className).toContain('card-accordion');
        }
    });

    it('메타 제목/설명 입력 필드 6개가 올바른 name을 가진다', () => {
        const fields = findFormFields(tabSeo);
        const expectedNames = [
            'seo.meta_boards_title',
            'seo.meta_boards_description',
            'seo.meta_board_title',
            'seo.meta_board_description',
            'seo.meta_post_title',
            'seo.meta_post_description',
        ];
        for (const name of expectedNames) {
            expect(fields, `폼 필드 '${name}'이 없다`).toContain(name);
        }
    });

    it('SEO 제공 페이지 체크박스 3개가 올바른 name을 가진다', () => {
        const fields = findFormFields(tabSeo);
        const checkboxNames = ['seo.seo_boards', 'seo.seo_board', 'seo.seo_post_detail'];
        for (const name of checkboxNames) {
            expect(fields, `체크박스 '${name}'이 없다`).toContain(name);
        }
    });

    it('캐시 초기화 버튼이 존재하고 type="button"이다', () => {
        const buttons = findByName(tabSeo, 'Button');
        const cacheBtn = buttons.find((b: any) => b.props?.type === 'button');
        expect(cacheBtn).toBeDefined();
        // 로딩 중 비활성화
        expect(cacheBtn.props.disabled).toContain('clearingCache');
    });

    it('캐시 초기화 액션이 sequence → setState → apiCall 체이닝 구조다', () => {
        const buttons = findByName(tabSeo, 'Button');
        const cacheBtn = buttons.find((b: any) => b.props?.type === 'button');
        expect(cacheBtn.actions).toHaveLength(1);

        const clickAction = cacheBtn.actions[0];
        expect(clickAction.type).toBe('click');
        expect(clickAction.handler).toBe('sequence');

        // 1단계: clearingCache = true
        const setStateAction = clickAction.actions[0];
        expect(setStateAction.handler).toBe('setState');
        expect(setStateAction.params.target).toBe('local');
        expect(setStateAction.params.clearingCache).toBe(true);

        // 2단계: 첫 번째 apiCall (board/boards 캐시 삭제)
        const firstApiCall = clickAction.actions[1];
        expect(firstApiCall.handler).toBe('apiCall');
        expect(firstApiCall.auth_required).toBe(true);
        expect(firstApiCall.target).toBe('/api/admin/seo/clear-cache');
        expect(firstApiCall.params.body.layout).toBe('board/boards');
    });

    it('캐시 초기화 onSuccess 체이닝이 3개 레이아웃을 순차 삭제한다', () => {
        const buttons = findByName(tabSeo, 'Button');
        const cacheBtn = buttons.find((b: any) => b.props?.type === 'button');
        const firstApiCall = cacheBtn.actions[0].actions[1];

        // board/boards → board/index onSuccess
        expect(firstApiCall.onSuccess.handler).toBe('apiCall');
        expect(firstApiCall.onSuccess.params.body.layout).toBe('board/index');

        // board/index → board/show onSuccess
        expect(firstApiCall.onSuccess.onSuccess.handler).toBe('apiCall');
        expect(firstApiCall.onSuccess.onSuccess.params.body.layout).toBe('board/show');

        // board/show onSuccess: clearingCache=false + 성공 토스트
        const finalSuccess = firstApiCall.onSuccess.onSuccess.onSuccess;
        expect(Array.isArray(finalSuccess)).toBe(true);
        const finalSetState = finalSuccess.find((a: any) => a.handler === 'setState');
        expect(finalSetState.params.clearingCache).toBe(false);
        const finalToast = finalSuccess.find((a: any) => a.handler === 'toast');
        expect(finalToast.params.type).toBe('success');
    });

    it('캐시 초기화 onError에서 clearingCache를 false로 리셋하고 에러 토스트를 표시한다', () => {
        const buttons = findByName(tabSeo, 'Button');
        const cacheBtn = buttons.find((b: any) => b.props?.type === 'button');
        const firstApiCall = cacheBtn.actions[0].actions[1];

        // 각 단계의 onError 확인 (3곳)
        for (const onError of [
            firstApiCall.onError,
            firstApiCall.onSuccess.onError,
            firstApiCall.onSuccess.onSuccess.onError,
        ]) {
            expect(Array.isArray(onError)).toBe(true);
            const setStateErr = onError.find((a: any) => a.handler === 'setState');
            expect(setStateErr.params.clearingCache).toBe(false);
            const toastErr = onError.find((a: any) => a.handler === 'toast');
            expect(toastErr.params.type).toBe('error');
        }
    });

    it('모든 다국어 키가 sirsoft-board 네임스페이스를 사용한다', () => {
        const allTexts: string[] = [];
        function collectTexts(node: any) {
            if (!node) return;
            if (typeof node.text === 'string' && node.text.startsWith('$t:')) {
                allTexts.push(node.text);
            }
            if (Array.isArray(node.children)) {
                node.children.forEach(collectTexts);
            }
        }
        collectTexts(tabSeo);
        expect(allTexts.length).toBeGreaterThan(0);
        for (const text of allTexts) {
            expect(text, `잘못된 다국어 키: ${text}`).toMatch(/^\$t:sirsoft-board\./);
        }
    });

    it('메인 레이아웃 TabNavigation에 seo 탭이 포함되어 있다', () => {
        const tabNavList = findByName(mainLayout, 'TabNavigation');
        expect(tabNavList.length).toBeGreaterThan(0);
        const tabs = tabNavList[0]?.props?.tabs as any[];
        const seoTab = tabs.find((t: any) => t.id === 'seo');
        expect(seoTab).toBeDefined();
        expect(seoTab.label).toContain('$t:sirsoft-board.');
    });

    it('메인 레이아웃 Partial 목록에 _tab_seo.json이 포함되어 있다', () => {
        const content = mainLayout.slots.content[0];
        const mainContent = findById(content, 'main_content');
        const partialPaths = mainContent.children
            .filter((c: any) => c.partial)
            .map((p: any) => p.partial as string);
        expect(partialPaths.some((p) => p.includes('_tab_seo.json'))).toBe(true);
    });

    it('저장 body에 seo 탭 분기 로직이 있다', () => {
        const content = mainLayout.slots.content[0];
        const saveBtn = findById(content, 'save_button');
        const apiCallAction = saveBtn.actions[0].actions[1];
        const body = apiCallAction.params.body as string;
        expect(body).toContain("'seo'");
        expect(body).toContain('seo:');
    });
});
