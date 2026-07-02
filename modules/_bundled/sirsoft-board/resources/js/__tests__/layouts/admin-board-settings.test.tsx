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

// 허용 확장자 안내 문구 회귀 검증용 ko lang
import koSettingsLang from '../../../lang/partial/ko/admin/settings.json';

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
 * JSON 트리에서 술어를 만족하는 첫 노드를 찾습니다 (children + responsive.portable 순회).
 */
function findFirst(node: any, predicate: (n: any) => boolean): any | null {
    if (!node) return null;
    if (predicate(node)) return node;
    const kids = [
        ...(node.children ?? []),
        ...(node.responsive?.portable?.children ?? []),
    ];
    for (const child of kids) {
        const found = findFirst(child, predicate);
        if (found) return found;
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
        // sequence 하위 액션은 params.actions 에 위치 (엔진 규약)
        const innerActions = tabAction.params.actions;
        expect(innerActions).toHaveLength(4);

        // setState - errors 초기화
        expect(innerActions[0].handler).toBe('setState');
        expect(innerActions[0].params.target).toBe('local');
        expect(innerActions[0].params.errors).toBeNull();

        // setState - 글로벌 탭 상태
        expect(innerActions[1].handler).toBe('setState');
        expect(innerActions[1].params.target).toBe('global');

        // replaceUrl - URL 업데이트
        expect(innerActions[2].handler).toBe('replaceUrl');
        expect(innerActions[2].params.query.tab).toBeDefined();

        // scrollIntoView - 탭 변경 후 본문 상단으로 스크롤 (#369)
        expect(innerActions[3].handler).toBe('scrollIntoView');
        // _admin_base 슬롯 #main_content 와 중복 회피 — 페이지 안쪽 탭 컨텐츠 컨테이너로 변경 (#408)
        expect(innerActions[3].params.selector).toBe('#tab_content');
    });

    it('sticky 헤더 구조가 올바르다', () => {
        const content = mainLayout.slots.content[0];
        const stickyHeader = findById(content, 'sticky_header');
        expect(stickyHeader).toBeDefined();
        // sirsoft-admin_basic 의 .sticky-tab-nav-responsive 자산 사용 (responsive padding 화면 전용 — #408)
        // 자산 정의: sticky top-0 z-40 -mx-{4,6,8} px-{4,6,8} border-b bg-gray-50 dark:bg-gray-900
        expect(stickyHeader.props.className).toContain('sticky-tab-nav-responsive');

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

    it('저장 버튼이 하단 sticky footer 안에 있다', () => {
        const content = mainLayout.slots.content[0];
        // 액션 버튼은 페이지 상단 sticky_header 가 아닌 하단 sticky footer_buttons 컨테이너에 있다 (#369)
        // sirsoft-admin_basic 의 .sticky-footer-buttons 자산 사용 (sticky bottom-0 z-10 ... bg-gray-50 dark:bg-gray-800)
        const footerButtons = findById(content, 'footer_buttons');
        expect(footerButtons).toBeDefined();
        expect(footerButtons.props.className).toContain('sticky-footer-buttons');

        const saveBtn = findById(footerButtons, 'footer_save_button');
        expect(saveBtn).toBeDefined();
    });

    it('저장 버튼이 올바른 apiCall 구조를 가진다', () => {
        const content = mainLayout.slots.content[0];
        const saveBtn = findById(content, 'footer_save_button');
        expect(saveBtn).toBeDefined();
        expect(saveBtn.props.type).toBe('button');
        expect(saveBtn.props.disabled).toContain('hasChanges');

        const clickAction = saveBtn.actions[0];
        expect(clickAction.handler).toBe('sequence');
        // sequence 하위 액션은 params.actions 에 위치
        const innerActions = clickAction.params.actions;

        // 1단계: setState isSaving=true
        const setStateAction = innerActions[0];
        expect(setStateAction.handler).toBe('setState');
        expect(setStateAction.params.isSaving).toBe(true);

        // 2단계: apiCall PUT
        const apiCallAction = innerActions[1];
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
        // _admin_base 슬롯 #main_content 와 중복 회피 — 페이지 안쪽 탭 컨텐츠 컨테이너 id 는 tab_content (#408)
        const tabContent = findById(content, 'tab_content');
        expect(tabContent).toBeDefined();
        const partials = tabContent.children.filter((c: any) => c.partial);
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

    it('페이지 안내 배너(page_info_banner)가 섹션 목록 상단에 존재한다 (#408 alert-info)', () => {
        const banner = findById(tabBoardSettingsBasic, 'page_info_banner');
        expect(banner).toBeDefined();
        expect(banner.props.className).toContain('alert-info');
        const texts = findByName(banner, 'P');
        expect(texts.some((p: any) => p.text?.includes('page_info'))).toBe(true);
    });

    it('SectionLayout을 사용한다', () => {
        const sections = findByName(tabBoardSettingsBasic, 'SectionLayout');
        expect(sections.length).toBeGreaterThanOrEqual(1);
        // sirsoft-admin_basic 의 .admin-card 자산 사용 (#408)
        // 자산 정의: bg-white border border-gray-200 rounded-lg dark:bg-gray-800 dark:border-gray-700
        for (const section of sections) {
            expect(section.props.className).toContain('admin-card');
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

    it('각 필드에 일괄 적용 체크박스가 있고 Input change 액션으로 토글된다 — controlled checkbox 표준 (#304)', () => {
        const fieldType = findById(tabBoardSettingsBasic, 'field_type');
        expect(fieldType).toBeDefined();

        const checkboxes = findByName(fieldType, 'Input').filter(
            (c: any) => c.props?.type === 'checkbox' && c.props?.className?.includes('checkbox')
        );
        expect(checkboxes.length).toBeGreaterThanOrEqual(1);

        // 체크박스 Input 토글 시 change 이벤트로 setState 발화 — React controlled checkbox 표준
        const checkboxChangeAction = checkboxes[0].actions?.find(
            (a: any) => a.type === 'change' && a.handler === 'setState'
        );
        expect(checkboxChangeAction).toBeDefined();
        expect(checkboxChangeAction.params.target).toBe('local');
        expect(checkboxChangeAction.params.bulkApplyFields).toContain("includes('type')");

        // 라벨에는 click 액션이 없어야 한다 (HTML 표준: Label 클릭 시 자식 Input의 change 자동 발화)
        const labels = findByName(fieldType, 'Label');
        for (const label of labels) {
            const labelClick = label.actions?.find((a: any) => a.type === 'click');
            expect(labelClick, 'Label에 click 액션 없음 (이중 발화 방지)').toBeUndefined();
        }
    });

    it('basic 탭의 모든 일괄적용 필드(4개) Input에 change 액션이 적용되어 있고 라벨에는 click 액션이 없다 (#304)', () => {
        const fieldIds = ['field_type', 'field_secret_mode', 'field_show_view_count', 'field_use_report'];

        for (const fieldId of fieldIds) {
            const field = findById(tabBoardSettingsBasic, fieldId);
            expect(field, `${fieldId} 노드 존재`).toBeDefined();

            const checkboxes = findByName(field, 'Input').filter(
                (c: any) => c.props?.type === 'checkbox' && c.props?.className?.includes('checkbox')
            );
            expect(checkboxes.length, `${fieldId} 체크박스 1개 이상`).toBeGreaterThanOrEqual(1);

            const checkboxChange = checkboxes[0].actions?.find(
                (a: any) => a.type === 'change' && a.handler === 'setState'
            );
            expect(checkboxChange, `${fieldId} 체크박스 Input change 액션 존재`).toBeDefined();
            expect(checkboxChange.params.target, `${fieldId} target=local`).toBe('local');
            expect(checkboxChange.params.bulkApplyFields, `${fieldId} bulkApplyFields 토글 표현식`).toBeDefined();

            // 라벨에 click 액션이 없는지 (이중 발화 방지)
            const labels = findByName(field, 'Label');
            for (const label of labels) {
                const labelClick = label.actions?.find((a: any) => a.type === 'click');
                expect(labelClick, `${fieldId} Label에 click 액션 없음`).toBeUndefined();
            }
        }
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

    it('권한 행은 Label로 체크박스+텍스트를 감싸고 Input change로 토글한다 — basic 탭과 동일 패턴 (#304)', () => {
        const sectionIds = ['permissions_admin_section', 'permissions_user_section'];

        for (const sectionId of sectionIds) {
            const section = findById(tabBoardSettingsPermissions, sectionId);
            expect(section, `${sectionId} 노드 존재`).toBeDefined();

            const iterDiv = findByName(section, 'Div').find(
                (d: any) => d.iteration?.source?.includes('Permissions')
            );
            expect(iterDiv, `${sectionId} iteration Div 존재`).toBeDefined();

            // iteration Div에는 click 액션이 없어야 한다 (HTML Label이 자식 Input change를 자동 발화)
            const parentClick = iterDiv.actions?.find((a: any) => a.type === 'click');
            expect(parentClick, `${sectionId} iteration Div에 click 액션 없음`).toBeUndefined();

            // iteration Div의 직계 자식에 Label이 있고, Label이 체크박스를 감싼다
            const labelChild = iterDiv.children?.find((c: any) => c.name === 'Label');
            expect(labelChild, `${sectionId} 직계 자식 Label 존재`).toBeDefined();

            const labelCheckbox = findByName(labelChild, 'Input').find(
                (c: any) => c.props?.type === 'checkbox'
            );
            expect(labelCheckbox, `${sectionId} Label 안 체크박스 존재`).toBeDefined();
            const checkboxChange = labelCheckbox.actions?.find(
                (a: any) => a.type === 'change' && a.handler === 'setState'
            );
            expect(checkboxChange, `${sectionId} 체크박스 Input에 change 액션 존재`).toBeDefined();

            // 자식 컨테이너의 className에 pointer-events-none 사용 금지 (이중 발화 차단 회피)
            const childDivs = findByName(iterDiv, 'Div');
            for (const child of childDivs) {
                const className: string = child.props?.className ?? '';
                expect(
                    className.includes('pointer-events-none'),
                    `${sectionId} 자식 Div className: "${className}" — pointer-events-none 사용 금지`
                ).toBe(false);
            }

            // Label 안에 Label 중첩 금지 (기존 권한 이름 Label은 Span으로 다운그레이드되어야 함)
            const nestedLabels = findByName(labelChild, 'Label').filter((l: any) => l !== labelChild);
            expect(nestedLabels.length, `${sectionId} Label 안 중첩 Label 금지`).toBe(0);
        }
    });

    it('권한 섹션 헤더(전체 토글)는 Label로 체크박스+텍스트를 감싸고 Input change로 토글한다 (#304)', () => {
        const sectionIds = ['permissions_admin_section', 'permissions_user_section'];

        for (const sectionId of sectionIds) {
            const section = findById(tabBoardSettingsPermissions, sectionId);
            expect(section, `${sectionId} 노드 존재`).toBeDefined();

            // 섹션 헤더는 첫 자식 Label (.section-header-row 시맨틱 자산 적용 + 체크박스 감쌈, #408)
            const headerLabel = section.children.find(
                (c: any) => c.name === 'Label' && (c.props?.className ?? '').includes('section-header-row')
            );
            expect(headerLabel, `${sectionId} 섹션 헤더 Label 존재`).toBeDefined();

            // Label 자체에는 click 액션이 없어야 한다 (HTML 표준이 자식 Input change를 자동 발화)
            expect(headerLabel.actions, `${sectionId} 섹션 헤더 Label에 액션 없음`).toBeUndefined();

            // 헤더 내 체크박스에 change 액션 + pointer-events-none 미사용
            const headerCheckbox = findByName(headerLabel, 'Input').find(
                (c: any) => c.props?.type === 'checkbox'
            );
            expect(headerCheckbox, `${sectionId} 섹션 헤더 체크박스 존재`).toBeDefined();
            expect(
                (headerCheckbox.props.className ?? '').includes('pointer-events-none'),
                `${sectionId} 섹션 헤더 체크박스 pointer-events-none 미사용`
            ).toBe(false);
            const headerChange = headerCheckbox.actions?.find(
                (a: any) => a.type === 'change' && a.handler === 'setState'
            );
            expect(headerChange, `${sectionId} 섹션 헤더 체크박스 change 액션 존재`).toBeDefined();
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

    it('허용 확장자 안내 문구가 descriptions i18n 키를 참조한다 (회귀)', () => {
        // 레이아웃은 안내 문구를 descriptions.allowed_extensions 키로 바인딩해야 한다.
        const descNode = findFirst(
            tabBoardSettingsAttachment,
            (n) => n?.text === '$t:sirsoft-board.admin.settings.fields.descriptions.allowed_extensions'
        );
        expect(descNode).not.toBeNull();
    });

    it('허용 확장자 안내 ko 문구가 "최소 1개" 의미로 갱신되었다 (회귀)', () => {
        // 빈 값 저장 차단으로 정책이 바뀌었으므로 안내 문구도 "최소 1개 입력"이어야 하며,
        // 정반대 의미의 옛 문구("빈 값 ... 모든 확장자")가 남아 있으면 안 된다.
        const desc = (koSettingsLang as any).fields.descriptions.allowed_extensions;
        expect(desc).toContain('최소 1개');
        expect(desc).not.toContain('모든 확장자');
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

    // 회귀(#413-26): 롤백 발생 후 모달을 닫았다가 다시 열면 직전 롤백 안내가
    // 잔존하던 결함 — 모달 열기 sequence 에서 bulkApplyAborted/Board 도 함께 초기화해야 함.
    it('모달 열기 시 직전 롤백 안내 상태를 초기화한다 (saveError + aborted + abortedBoard)', () => {
        const bulkApply = findById(tabBoardSettingsBulkApply, 'section_bulk_apply');
        // openModal 직전의 setState(local) 중 오류/롤백 안내를 초기화하는 액션을 찾는다
        const resetActions = findActions(bulkApply, 'setState').filter(
            (a: any) => a.params?.target === 'local'
                && Object.prototype.hasOwnProperty.call(a.params, 'bulkApplySaveError')
        );
        expect(resetActions.length).toBeGreaterThanOrEqual(1);
        const reset = resetActions[0];
        expect(reset.params.bulkApplySaveError).toBe(false);
        // 롤백 안내도 함께 초기화되어야 재오픈 시 직전 실패 문구가 잔존하지 않는다
        expect(reset.params.bulkApplyAborted).toBe(false);
        expect(reset.params.bulkApplyAbortedBoard).toBeNull();
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
        // sirsoft-admin_basic 의 .admin-card 자산 사용 (#408)
        expect(sections[0].props.className).toContain('admin-card');
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

    it('신고 알림 활성화 토글이 제거되었다 (알림 정의 활성화는 알림 설정 메뉴에서 관리)', () => {
        // 활성화 토글은 백엔드에서 항상 true 강제 저장되므로 화면에서 제거됨.
        const fields = findFormFields(tabReportPolicy);
        expect(fields).not.toContain('report_policy.notify_admin_on_report');
        expect(fields).not.toContain('report_policy.notify_author_on_report_action');

        // 두 알림 카드(헤더)는 유지된다 (제목/설명 표시)
        expect(findById(tabReportPolicy, 'field_notify_admin_on_report')).toBeDefined();
        expect(findById(tabReportPolicy, 'field_notify_author_on_report_action')).toBeDefined();
    });

    it('토글 제거로 발송 범위 라디오가 항상 표시된다 (if 가드 제거)', () => {
        // notify_admin_on_report === true 가드가 없어도 scope RadioGroup 이 렌더되어야 한다.
        const scopeField = findById(tabReportPolicy, 'field_notify_admin_on_report_scope');
        expect(scopeField).toBeDefined();
        const radioGroups = findByName(scopeField, 'RadioGroup');
        expect(radioGroups).toHaveLength(1);
        // 가드 Div 의 if 표현식에 토글 종속 조건이 없어야 한다
        const adminField = findById(tabReportPolicy, 'field_notify_admin_on_report');
        const guardWithToggle = findFirst(
            adminField,
            (n: any) => typeof n.if === 'string' && n.if.includes('notify_admin_on_report === true'),
        );
        expect(guardWithToggle).toBeNull();
    });

    it('채널 직접 선택 TagInput 대신 템플릿별 활성 상태 표시로 대체되었다', () => {
        // 코어 알림 인프라 도입 후, 채널은 알림 설정에서 관리.
        // 신고정책 탭은 대응 definition 의 채널별 템플릿 활성 상태만 읽기전용 표시한다.
        const fields = findFormFields(tabReportPolicy);
        expect(fields).not.toContain('report_policy.notify_admin_on_report_channels');
        expect(fields).not.toContain('report_policy.notify_author_on_report_action_channels');

        // 신고 접수: report_received_admin definition 템플릿 상태 블록 + 이동 버튼
        const adminStatus = findById(tabReportPolicy, 'report_received_admin_template_status');
        expect(adminStatus).toBeDefined();
        const adminIteration = findFirst(
            adminStatus,
            (n: any) => n.iteration && String(n.iteration.source).includes("'report_received_admin'"),
        );
        expect(adminIteration).toBeTruthy();
        expect(adminIteration.iteration.item_var).toBe('tpl');
        // 각 행이 "어떤 템플릿인지" 알 수 있도록 채널명 + 템플릿 제목(subject)을 표시
        const adminSubject = findFirst(adminIteration, (n: any) => n.name === 'Span' && String(n.text ?? '').includes('tpl.subject'));
        expect(adminSubject).toBeTruthy();
        expect(findById(tabReportPolicy, 'go_to_notification_settings_admin')).toBeDefined();

        // 신고 처리: report_action definition 템플릿 상태 블록 + 이동 버튼
        const authorStatus = findById(tabReportPolicy, 'report_action_template_status');
        expect(authorStatus).toBeDefined();
        const authorIteration = findFirst(
            authorStatus,
            (n: any) => n.iteration && String(n.iteration.source).includes("'report_action'"),
        );
        expect(authorIteration).toBeTruthy();
        const authorSubject = findFirst(authorIteration, (n: any) => n.name === 'Span' && String(n.text ?? '').includes('tpl.subject'));
        expect(authorSubject).toBeTruthy();
        expect(findById(tabReportPolicy, 'go_to_notification_settings_author')).toBeDefined();
    });

    it('admin-card 안 4 섹션 description 이 .card-description 시맨틱이고 card-title 의 직계 sibling 이다 (#408)', () => {
        // _tab_report_policy.json 의 4 섹션 (자동 숨김, abuse_prevention, permissions, notification)
        // description 이 stack 안에 묻혀있으면 .card-title:has(+ .card-description) 셀렉터 불발 →
        // card-title 의 하단 여백이 의도(mb-1)와 다르게 mb-6 으로 적용됨 (보고 #408)
        //
        // 기대: admin-card 직계 자식 순서가 [card-title, card-description, stack, ...] 이고,
        //       card-description 의 className 에 'card-description' 시맨틱 자산이 포함되어야 함.
        function collectAdminCards(node: any, out: any[] = []): any[] {
            if (!node || typeof node !== 'object') return out;
            if (Array.isArray(node)) { node.forEach((c) => collectAdminCards(c, out)); return out; }
            const cls = node.props?.className;
            if (typeof cls === 'string' && /\badmin-card\b/.test(cls)) out.push(node);
            for (const v of Object.values(node)) if (v && typeof v === 'object') collectAdminCards(v, out);
            return out;
        }

        const cards = collectAdminCards(tabReportPolicy);
        expect(cards.length, '_tab_report_policy admin-card 개수').toBeGreaterThan(0);

        for (const card of cards) {
            const children = card.children ?? [];
            // card-title 찾기 (H1~H4/Span/P 중 className 에 card-title 포함)
            const titleIdx = children.findIndex(
                (c: any) => typeof c?.props?.className === 'string' &&
                            /\bcard-title\b/.test(c.props.className)
            );
            if (titleIdx === -1) continue; // card-title 없는 admin-card 는 검사 대상 아님

            // card-title 의 다음 형제가 stack 인 경우 — stack 첫 자식이 description-like 패턴이면 위반
            const sibling = children[titleIdx + 1];
            if (!sibling || sibling.name !== 'Div') continue;
            const sibCls = typeof sibling.props?.className === 'string' ? sibling.props.className : '';
            const isStack = /\b(stack|stack-tight|stack-flush)\b/.test(sibCls);
            if (!isStack) continue;

            const stackChildren = sibling.children ?? [];
            // stack 첫 자식 (직접 OR inert wrapper Div 안)
            let first = stackChildren[0];
            const isInert = (n: any) =>
                n && n.type === 'basic' && n.name === 'Div' && !n.id && !n.if && !n.iteration && !n.actions && !n.lifecycle &&
                (!n.props || !n.props.className || n.props.className.trim() === '');
            if (isInert(first) && Array.isArray(first.children) && first.children.length === 1) {
                first = first.children[0];
            }

            if (!first || (first.name !== 'Span' && first.name !== 'P')) continue;
            const fCls = typeof first.props?.className === 'string' ? first.props.className : '';
            const isDescLike =
                /\btext-label-subtle\b/.test(fCls) ||
                /\bform-hint\b/.test(fCls) ||
                (/\btext-tertiary\b/.test(fCls) && /\bblock\b/.test(fCls));

            if (isDescLike) {
                // 위반 — stack 첫 자식 description 이 hoist 되지 않음
                throw new Error(
                    `admin-card 안 description 이 stack 에 묻혀있음. card-title 의 직계 sibling 으로 hoist + .card-description 시맨틱 적용 필요. (className="${fCls}")`,
                );
            }
        }
    });

    it('admin_board_settings 메인 레이아웃에 id="main_content" 가 없어야 한다 — _admin_base 의 슬롯 id 와 중복 금지 (HTML 스펙) (#408)', () => {
        function findAllIds(node: any, out: Map<string, number> = new Map()): Map<string, number> {
            if (!node || typeof node !== 'object') return out;
            if (Array.isArray(node)) { node.forEach((c) => findAllIds(c, out)); return out; }
            if (typeof node.id === 'string' && node.id !== '') {
                out.set(node.id, (out.get(node.id) ?? 0) + 1);
            }
            for (const v of Object.values(node)) if (v && typeof v === 'object') findAllIds(v, out);
            return out;
        }
        const ids = findAllIds(mainLayout);
        // 메인 레이아웃 (admin_board_settings.json) 자체에는 main_content id 가 있으면 안 됨.
        // _admin_base.json 슬롯 컨테이너가 이미 #main_content 를 가지고 있어 페이지에 중복 발생.
        expect(ids.get('main_content'), 'admin_board_settings 안쪽에 id="main_content" 가 있으면 _admin_base 슬롯과 중복').toBeUndefined();
        // 대체 id (탭 컨텐츠 컨테이너) 가 존재해야 함
        expect(ids.has('tab_content'), '탭 컨텐츠 컨테이너 id="tab_content" 가 존재해야 함').toBe(true);
    });

    it('board layout 의 모든 .card-description 은 Div 태그여야 한다 — Span/P 금지 (#408)', () => {
        // 표준: span.card-description / p.card-description 는 잘못된 시맨틱 HTML
        //          → div.card-description 만 허용.
        const layouts: Array<[string, any]> = [
            ['mainLayout', mainLayout],
            ['tabBoardSettingsBasic', tabBoardSettingsBasic],
            ['tabBoardSettingsPermissions', tabBoardSettingsPermissions],
            ['tabBoardSettingsList', tabBoardSettingsList],
            ['tabBoardSettingsPost', tabBoardSettingsPost],
            ['tabBoardSettingsReply', tabBoardSettingsReply],
            ['tabBoardSettingsComment', tabBoardSettingsComment],
            ['tabBoardSettingsAttachment', tabBoardSettingsAttachment],
            ['tabBoardSettingsNotification', tabBoardSettingsNotification],
            ['tabBoardSettingsBulkApply', tabBoardSettingsBulkApply],
            ['tabReportPolicy', tabReportPolicy],
            ['tabSpamSecurity', tabSpamSecurity],
            ['bulkApplyModal', bulkApplyModal],
            ['tabGeneral', tabGeneral],
            ['tabSeo', tabSeo],
        ];

        const violations: string[] = [];
        function check(node: any, layoutName: string): void {
            if (!node || typeof node !== 'object') return;
            if (Array.isArray(node)) { node.forEach((c) => check(c, layoutName)); return; }
            const cls = node.props?.className;
            if (typeof cls === 'string' && /\bcard-description\b/.test(cls) && node.name !== 'Div') {
                violations.push(`${layoutName}: ${node.name}.card-description (text="${(node.text ?? '').slice(0, 60)}")`);
            }
            for (const v of Object.values(node)) if (v && typeof v === 'object') check(v, layoutName);
        }
        layouts.forEach(([name, l]) => check(l, name));

        expect(violations, '비-Div 태그를 사용하는 .card-description 가 있으면 안 됨').toEqual([]);
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

    it('Input 에러 시 input-error 시맨틱 클래스가 분기 적용된다', () => {
        // Input 디폴트 외형은 Input 컴포넌트가 자체 제공 (시맨틱 .input).
        // 호출처 className 표현식은 422 검증 에러 시 input-error 시맨틱을 토글하는 분기만 담당 (#369).
        const inputs = findByName(tabReportPolicy, 'Input').filter(
            (c: any) => c.props?.type === 'number'
        );
        expect(inputs.length).toBeGreaterThan(0);

        for (const input of inputs) {
            expect(input.props.className).toMatch(/_local\.errors/);
            expect(input.props.className).toContain('input-error');
        }
    });

    it('신고 관리 권한 TagInput 에러 시 input-error 분기 + 인라인 에러 Span 이 존재한다 (#413 item 8)', () => {
        // 신고 권한 필드(view_roles/manage_roles)는 빈 배열 제출 시 FormRequest 가 차단한다.
        // 그 422 응답을 화면에 표시하기 위해, 다른 number Input 필드와 동일한 패턴으로
        //   1) TagInput control 에 input-error 시맨틱 토글 (빨간 테두리)
        //   2) TagInput 직후 형제로 인라인 에러 메시지 Span
        // 두 가지가 모두 있어야 한다. (#408 시맨틱화 유지 — 원시 Tailwind 풀스택 금지)
        const permissionFields = ['report_permissions.view_roles', 'report_permissions.manage_roles'];

        // TagInput 과 그 바로 뒤 형제 Span 을 함께 보기 위해, 부모의 children 배열을 순회한다.
        function collectSiblingPairs(node: any, out: Array<{ tag: any; next: any }> = []): Array<{ tag: any; next: any }> {
            if (!node || typeof node !== 'object') return out;
            const children = Array.isArray(node.children) ? node.children : [];
            for (let i = 0; i < children.length; i++) {
                const c = children[i];
                if (c?.name === 'TagInput' && permissionFields.includes(c.props?.name)) {
                    out.push({ tag: c, next: children[i + 1] ?? null });
                }
                collectSiblingPairs(c, out);
            }
            return out;
        }

        const pairs = collectSiblingPairs(tabReportPolicy);
        // view_roles + manage_roles 두 필드 모두 검출되어야 함
        expect(pairs.map((p) => p.tag.props.name).sort()).toEqual([...permissionFields].sort());

        for (const { tag, next } of pairs) {
            const field = tag.props.name;

            // 1) className 이 에러 시 input-error 를 토글하는 분기여야 함
            expect(tag.props.className, `${field} className`).toMatch(/_local\.errors/);
            expect(tag.props.className, `${field} className`).toContain('input-error');

            // 2) TagInput 직후 형제가 인라인 에러 Span 이어야 함 (if + 빨간 텍스트 + 해당 필드 바인딩)
            expect(next, `${field} 뒤 에러 Span`).not.toBeNull();
            expect(next.name).toBe('Span');
            expect(next.if).toContain(`_local.errors?.['${field}']`);
            expect(next.props.className).toContain('text-red-600');
            expect(next.text).toContain(`_local.errors`);
            expect(next.text).toContain(field);
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

        // bulk-apply onSuccess: 공통(진행해제) + 성공분기(if !rolled_back) 4개 + 롤백분기(if rolled_back) 2개 = 7개
        expect(bulkApiCall.onSuccess).toHaveLength(7);

        // [0] 성공/롤백 공통: isBulkApplying=false (조건 없음)
        expect(bulkApiCall.onSuccess[0].handler).toBe('setState');
        expect(bulkApiCall.onSuccess[0].params.isBulkApplying).toBe(false);
        expect(bulkApiCall.onSuccess[0].if).toBeUndefined();

        // 성공 분기(rolled_back=false): 상태초기화 → snapshot=null → closeModal → success 토스트
        const successActions = bulkApiCall.onSuccess.filter(
            (a: any) => a.if === '{{!response?.data?.rolled_back}}'
        );
        expect(successActions).toHaveLength(4);
        expect(successActions.some((a: any) => a.handler === 'closeModal')).toBe(true);
        const successToast = successActions.find((a: any) => a.handler === 'toast');
        expect(successToast).toBeDefined();
        expect(successToast.params.type).toBe('success');
        // 성공 시 global snapshot 정리
        const successSnapshotReset = successActions.find(
            (a: any) => a.handler === 'setState' && a.params.target === 'global'
        );
        expect(successSnapshotReset.params.bulkApplySnapshot).toBeNull();

        // 롤백 분기(rolled_back=true): 안내상태 setState + error 토스트 (closeModal 없음 → 모달 유지)
        const rollbackActions = bulkApiCall.onSuccess.filter(
            (a: any) => a.if === '{{response?.data?.rolled_back}}'
        );
        expect(rollbackActions).toHaveLength(2);
        // 롤백 분기에는 closeModal이 없어야 모달이 닫히지 않고 안내 박스가 노출된다
        expect(rollbackActions.some((a: any) => a.handler === 'closeModal')).toBe(false);
        const rollbackSetState = rollbackActions.find((a: any) => a.handler === 'setState');
        expect(rollbackSetState.params.target).toBe('$parent._local');
        expect(rollbackSetState.params.bulkApplyAborted).toBe(true);
        // 실패 게시판명을 응답에서 받아 안내 문구 분기에 사용
        expect(rollbackSetState.params.bulkApplyAbortedBoard).toBe(
            '{{response?.data?.board?.name ?? null}}'
        );
        const rollbackToast = rollbackActions.find((a: any) => a.handler === 'toast');
        expect(rollbackToast.params.type).toBe('error');

        // bulk-apply onError(네트워크/예외): setState(isBulkApplying=false) → setState(snapshot=null) → toast(error)
        expect(bulkApiCall.onError).toHaveLength(3);
        expect(bulkApiCall.onError[0].handler).toBe('setState');
        expect(bulkApiCall.onError[0].params.isBulkApplying).toBe(false);
        expect(bulkApiCall.onError[1].handler).toBe('setState');
        expect(bulkApiCall.onError[1].params.bulkApplySnapshot).toBeNull();
        expect(bulkApiCall.onError[2].handler).toBe('toast');
        expect(bulkApiCall.onError[2].params.type).toBe('error');

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

    it('일괄 적용 중단(전체 롤백) 안내 박스가 조건부로 표시된다 (오류=red 톤)', () => {
        // bulkApplyAborted가 true일 때만 노출되는 오류(danger) 안내 박스
        const abortedBox = findByName(bulkApplyModal, 'Div').find(
            (d: any) => d.if === '{{$parent._local.bulkApplyAborted}}'
        );
        expect(abortedBox).toBeDefined();
        // 롤백은 오류이므로 amber(warning)가 아닌 red(danger) 톤
        expect(abortedBox.props.className).toContain('alert-danger');
        // 오류 아이콘도 red 계열
        const icon = (abortedBox.children ?? []).find((c: any) => c.name === 'Icon');
        expect(icon.props.className).toContain('text-red-600');
    });

    it('롤백 안내 문구가 게시판명 유무로 분기된다 (권한 실패=게시판명 강조 / generic)', () => {
        const abortedBox = findByName(bulkApplyModal, 'Div').find(
            (d: any) => d.if === '{{$parent._local.bulkApplyAborted}}'
        );
        const spans = (abortedBox.children ?? []).filter((c: any) => c.name === 'Span');

        // 권한 실패(게시판명 있음): 게시판명을 굵게(font-bold) 강조한 Span + suffix 문구 Span
        const boardSpan = spans.find(
            (s: any) => s.if === '{{$parent._local.bulkApplyAbortedBoard}}'
        );
        expect(boardSpan).toBeDefined();
        const innerSpans = (boardSpan.children ?? []).filter((c: any) => c.name === 'Span');
        // 첫 Span: 게시판명, 굵게 강조
        const nameSpan = innerSpans.find((s: any) => s.props?.className?.includes('font-bold'));
        expect(nameSpan).toBeDefined();
        expect(nameSpan.text).toContain('$parent._local.bulkApplyAbortedBoard');
        // 둘째 Span: suffix 문구
        const suffixSpan = innerSpans.find((s: any) =>
            typeof s.text === 'string' && s.text.includes('aborted_message_suffix')
        );
        expect(suffixSpan).toBeDefined();

        // 기타 실패(게시판명 없음): generic 문구
        const genericSpan = spans.find(
            (s: any) => s.if === '{{!$parent._local.bulkApplyAbortedBoard}}'
        );
        expect(genericSpan).toBeDefined();
        expect(genericSpan.text).toContain('bulk_apply_modal.aborted_message_generic');
    });

    it('확인 버튼 진입 시 직전 롤백 안내 상태를 초기화한다 (재시도 대비)', () => {
        const confirmBtn = findById(bulkApplyModal, 'bulk_apply_confirm_button');
        const firstSetState = confirmBtn.actions[0].actions[0];
        expect(firstSetState.handler).toBe('setState');
        expect(firstSetState.params.bulkApplyAborted).toBe(false);
        expect(firstSetState.params.bulkApplyAbortedBoard).toBeNull();
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

    it('저장 body에 notification channels 포함 로직이 있다', () => {
        const content = mainLayout.slots.content[0];
        const saveBtn = findById(content, 'footer_save_button');
        const apiCallAction = saveBtn.actions[0].params.actions[1];

        // body 표현식 안에서 notifications.channels 구조를 조립한다
        // (notification_definitions 탭 진입 시 form.notifications.channels 를 그대로 전송)
        const body = apiCallAction.params.body;
        expect(body).toContain('notifications');
        expect(body).toContain('channels');
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
        const saveBtn = findById(content, 'footer_save_button');
        const apiCallAction = saveBtn.actions[0].params.actions[1];
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

    it('메타 설정 섹션 헤더가 존재한다 (section-header)', () => {
        const header = findById(tabSeo, 'meta_settings_header');
        expect(header).not.toBeNull();
        expect(header.props.className).toBe('section-header');
    });

    it('페이지별 admin-card 3개가 정의되어 있다 (boards/board/post)', () => {
        const cards = [
            'boards_page_card',
            'board_page_card',
            'post_page_card',
        ];
        for (const id of cards) {
            const node = findById(tabSeo, id);
            expect(node, `${id} 카드가 없다`).not.toBeNull();
            expect(node.props.className).toBe('admin-card');
            expect(node.name).toBe('SectionLayout');
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
        // _admin_base 슬롯 #main_content 와 중복 회피 — tab_content 로 변경 (#408)
        const tabContent = findById(content, 'tab_content');
        const partialPaths = tabContent.children
            .filter((c: any) => c.partial)
            .map((p: any) => p.partial as string);
        expect(partialPaths.some((p) => p.includes('_tab_seo.json'))).toBe(true);
    });

    it('저장 body에 seo 탭 분기 로직이 있다', () => {
        const content = mainLayout.slots.content[0];
        const saveBtn = findById(content, 'footer_save_button');
        const apiCallAction = saveBtn.actions[0].params.actions[1];
        const body = apiCallAction.params.body as string;
        expect(body).toContain("'seo'");
        expect(body).toContain('seo:');
    });
});
