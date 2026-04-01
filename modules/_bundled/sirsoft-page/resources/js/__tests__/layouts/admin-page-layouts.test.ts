/**
 * sirsoft-page 모듈 레이아웃 JSON 구조 검증 테스트
 */

import { describe, it, expect } from 'vitest';

import adminPageList from '../../../layouts/admin/admin_page_list.json';
import adminPageForm from '../../../layouts/admin/admin_page_form.json';
import adminPageDetail from '../../../layouts/admin/admin_page_detail.json';

// ─────────────────────────────────────────────
// 유틸리티
// ─────────────────────────────────────────────

function findById(node: any, id: string): any | null {
    if (!node) return null;
    if (node.id === id) return node;

    for (const child of node.children ?? []) {
        const found = findById(child, id);
        if (found) return found;
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

    // modals
    for (const modal of node.modals ?? []) {
        const found = findById(modal, id);
        if (found) return found;
    }

    return null;
}

function findComponentsByName(node: any, name: string): any[] {
    const results: any[] = [];
    if (!node) return results;

    if (node.name === name) results.push(node);

    for (const child of node.children ?? []) {
        results.push(...findComponentsByName(child, name));
    }

    if (node.slots) {
        for (const slotChildren of Object.values(node.slots)) {
            if (Array.isArray(slotChildren)) {
                for (const child of slotChildren) {
                    results.push(...findComponentsByName(child as any, name));
                }
            }
        }
    }

    return results;
}

/**
 * sequence 핸들러 내부의 실제 실행 핸들러들을 추출 (conditions 중첩 포함)
 */
function extractHandlersFromActions(actions: any[]): string[] {
    const handlers: string[] = [];
    for (const action of actions) {
        if (action.handler === 'sequence' && action.actions) {
            handlers.push(...extractHandlersFromActions(action.actions));
        } else if (action.handler === 'conditions' && action.conditions) {
            for (const cond of action.conditions) {
                if (cond.then) handlers.push(...extractHandlersFromActions(cond.then));
            }
        } else if (action.handler) {
            handlers.push(action.handler);
        }
    }
    return handlers;
}

// ─────────────────────────────────────────────
// admin_page_list.json
// ─────────────────────────────────────────────

describe('admin_page_list.json', () => {
    it('extends _admin_base', () => {
        expect(adminPageList.extends).toBe('_admin_base');
    });

    it('data_sources에 pages API가 있음', () => {
        const ds = (adminPageList as any).data_sources.find((d: any) => d.id === 'pages');
        expect(ds).toBeDefined();
        expect(ds.endpoint).toContain('/api/modules/sirsoft-page/admin/pages');
    });

    it('pages data_sources에 page, per_page 파라미터가 있음', () => {
        const ds = (adminPageList as any).data_sources.find((d: any) => d.id === 'pages');
        expect(ds.params.page).toBeDefined();
        expect(ds.params.per_page).toBeDefined();
    });

    it('DataGrid 컴포넌트가 존재함', () => {
        const grids = findComponentsByName(adminPageList, 'DataGrid');
        expect(grids.length).toBeGreaterThan(0);
    });

    it('DataGrid에 serverSidePagination이 true임', () => {
        const grids = findComponentsByName(adminPageList, 'DataGrid');
        expect(grids[0].props.serverSidePagination).toBe(true);
    });

    it('DataGrid에 selectable이 true임', () => {
        const grids = findComponentsByName(adminPageList, 'DataGrid');
        expect(grids[0].props.selectable).toBe(true);
    });

    it('DataGrid data 바인딩이 pages?.data?.data를 참조함', () => {
        const grids = findComponentsByName(adminPageList, 'DataGrid');
        expect(grids[0].props.data).toContain('pages?.data?.data');
    });

    it('삭제 확인 모달(delete_confirm_modal)이 정의되어 있음', () => {
        const modal = (adminPageList as any).modals?.find((m: any) => m.id === 'delete_confirm_modal');
        expect(modal).toBeDefined();
    });

    it('일괄 발행 모달(bulk_publish_modal)이 정의되어 있음', () => {
        const modal = (adminPageList as any).modals?.find((m: any) => m.id === 'bulk_publish_modal');
        expect(modal).toBeDefined();
    });

    it('bulk_publish_modal에서 bulk-publish API를 호출함', () => {
        const modal = (adminPageList as any).modals?.find((m: any) => m.id === 'bulk_publish_modal');
        const modalStr = JSON.stringify(modal);
        expect(modalStr).toContain('bulk-publish');
    });

    it('등록 버튼이 /admin/pages/create로 navigate함', () => {
        const createBtn = findById(adminPageList, 'add_page_button');
        expect(createBtn).not.toBeNull();
        const navAction = createBtn.actions.find((a: any) => a.handler === 'navigate');
        expect(navAction.params.path).toBe('/admin/pages/create');
    });

    it('DataGrid columns의 cellChildren에서 row 바인딩을 사용함', () => {
        const grids = findComponentsByName(adminPageList, 'DataGrid');
        const columns = grids[0]?.props?.columns;
        expect(columns).toBeDefined();
        expect(columns.length).toBeGreaterThan(0);
        // 각 column의 cellChildren 내부에서 row 변수를 사용
        const columnsStr = JSON.stringify(columns);
        expect(columnsStr).toContain('row.');
    });

    // ─── 권한 기반 UI 제어 ─────────────────────────

    it('등록 버튼에 collection abilities 기반 disabled가 설정됨', () => {
        const createBtn = findById(adminPageList, 'add_page_button');
        expect(createBtn).not.toBeNull();
        expect(createBtn.props.disabled).toContain('abilities');
        expect(createBtn.props.disabled).toContain('can_create');
    });

    it('일괄 발행 버튼에 abilities 기반 disabled가 설정됨', () => {
        const bulkPublish = findById(adminPageList, 'bulk_publish');
        expect(bulkPublish).not.toBeNull();
        expect(bulkPublish.props.disabled).toContain('can_update');
    });

    it('일괄 미발행 버튼에 abilities 기반 disabled가 설정됨', () => {
        const bulkUnpublish = findById(adminPageList, 'bulk_unpublish');
        expect(bulkUnpublish).not.toBeNull();
        expect(bulkUnpublish.props.disabled).toContain('can_update');
    });

    it('DataGrid rowActions에 disabledField가 설정됨', () => {
        const grids = findComponentsByName(adminPageList, 'DataGrid');
        const rowActions = grids[0]?.props?.rowActions;
        expect(rowActions).toBeDefined();

        // 편집 액션에 abilities.can_update 기반 disabledField
        const editAction = rowActions.find((a: any) => a.label?.includes('edit') || a.label?.includes('수정') || a.disabledField?.includes('can_update'));
        if (editAction) {
            expect(editAction.disabledField).toContain('can_update');
        }

        // 삭제 액션에 abilities.can_delete 기반 disabledField
        const deleteAction = rowActions.find((a: any) => a.label?.includes('delete') || a.label?.includes('삭제') || a.disabledField?.includes('can_delete'));
        if (deleteAction) {
            expect(deleteAction.disabledField).toContain('can_delete');
        }
    });

    it('pages data_source에 403 errorHandling이 설정됨', () => {
        const ds = (adminPageList as any).data_sources.find((d: any) => d.id === 'pages');
        expect(ds.errorHandling).toBeDefined();
        expect(ds.errorHandling['403']).toBeDefined();
    });
});

// ─────────────────────────────────────────────
// admin_page_form.json
// ─────────────────────────────────────────────

describe('admin_page_form.json', () => {
    it('extends _admin_base', () => {
        expect(adminPageForm.extends).toBe('_admin_base');
    });

    it('init_actions에 tempKey 생성 액션이 있음', () => {
        const initActions = (adminPageForm as any).init_actions;
        expect(initActions).toBeDefined();
        expect(initActions.length).toBeGreaterThan(0);
        const hasTemp = JSON.stringify(initActions).includes('tempKey');
        expect(hasTemp).toBe(true);
    });

    it('pageData data_source가 route.id 조건으로 fetch함', () => {
        const ds = (adminPageForm as any).data_sources.find((d: any) => d.id === 'pageData');
        expect(ds).toBeDefined();
        // if 조건에 route?.id 참조
        const dsStr = JSON.stringify(ds);
        expect(dsStr).toContain('route');
    });

    it('header_save_button의 액션에 setState, emitEvent, apiCall이 포함됨', () => {
        const btn = findById(adminPageForm, 'header_save_button');
        expect(btn).not.toBeNull();
        const handlers = extractHandlersFromActions(btn.actions);
        expect(handlers).toContain('setState');
        expect(handlers).toContain('emitEvent');
        expect(handlers).toContain('apiCall');
    });

    it('emitEvent가 upload:page_attachments 이벤트를 발행함', () => {
        const btn = findById(adminPageForm, 'header_save_button');
        const btnStr = JSON.stringify(btn);
        expect(btnStr).toContain('upload:page_attachments');
    });

    it('apiCall body에 temp_key가 포함됨', () => {
        const btn = findById(adminPageForm, 'header_save_button');
        const btnStr = JSON.stringify(btn);
        expect(btnStr).toContain('temp_key');
        expect(btnStr).toContain('_local.tempKey');
    });

    it('FileUploader에 autoUpload: false, uploadTriggerEvent 설정됨', () => {
        const uploaders = findComponentsByName(adminPageForm, 'FileUploader');
        expect(uploaders.length).toBeGreaterThan(0);
        expect(uploaders[0].props.autoUpload).toBe(false);
        expect(uploaders[0].props.uploadTriggerEvent).toBe('upload:page_attachments');
    });

    it('FileUploader apiEndpoints.upload이 admin/attachments를 가리킴', () => {
        const uploaders = findComponentsByName(adminPageForm, 'FileUploader');
        expect(uploaders[0].props.apiEndpoints.upload).toContain('/api/modules/sirsoft-page/admin/attachments');
    });

    it('슬러그 중복확인이 check-slug API를 호출함', () => {
        const formStr = JSON.stringify(adminPageForm);
        expect(formStr).toContain('check-slug');
    });

    it('page_form_content에 dataKey가 설정되어 있음', () => {
        const wrapper = findById(adminPageForm, 'page_form_content');
        expect(wrapper).not.toBeNull();
        expect(wrapper.dataKey).toBe('form');
    });

    // ─── 권한 기반 UI 제어 (isReadOnly 패턴) ───────

    it('computed에 isReadOnly가 정의됨', () => {
        const computed = (adminPageForm as any).computed;
        expect(computed).toBeDefined();
        expect(computed.isReadOnly).toBeDefined();
        expect(computed.isReadOnly).toContain('route');
        expect(computed.isReadOnly).toContain('can_update');
    });

    it('저장 버튼이 isReadOnly일 때 숨겨짐', () => {
        const saveBtn = findById(adminPageForm, 'header_save_button');
        expect(saveBtn).not.toBeNull();
        expect(saveBtn.if).toContain('isReadOnly');
    });

    it('읽기전용 배너가 isReadOnly 조건으로 표시됨', () => {
        const banner = findById(adminPageForm, 'read_only_banner');
        expect(banner).not.toBeNull();
        expect(banner.if).toContain('isReadOnly');
    });

    it('발행 셀렉트가 isReadOnly일 때 disabled됨', () => {
        const select = findById(adminPageForm, 'published_select');
        expect(select).not.toBeNull();
        expect(select.props.disabled).toContain('isReadOnly');
    });

    it('제목 입력이 isReadOnly일 때 disabled됨', () => {
        const titleInput = findById(adminPageForm, 'title_input');
        expect(titleInput).not.toBeNull();
        expect(titleInput.props.disabled).toContain('isReadOnly');
    });

    it('본문 에디터가 isReadOnly일 때 disabled됨', () => {
        const editor = findById(adminPageForm, 'content_editor');
        expect(editor).not.toBeNull();
        expect(editor.props.disabled).toContain('isReadOnly');
    });

    it('첨부파일 업로더가 isReadOnly일 때 disabled됨', () => {
        const uploader = findComponentsByName(adminPageForm, 'FileUploader');
        expect(uploader.length).toBeGreaterThan(0);
        expect(uploader[0].props.disabled).toContain('isReadOnly');
    });

    it('pageData data_source에 403 errorHandling이 설정됨', () => {
        const ds = (adminPageForm as any).data_sources.find((d: any) => d.id === 'pageData');
        expect(ds).toBeDefined();
        expect(ds.errorHandling).toBeDefined();
        expect(ds.errorHandling['403']).toBeDefined();
    });
});

// ─────────────────────────────────────────────
// admin_page_detail.json
// ─────────────────────────────────────────────

describe('admin_page_detail.json', () => {
    it('extends _admin_base', () => {
        expect(adminPageDetail.extends).toBe('_admin_base');
    });

    it('page, versions 두 개의 data_sources가 있음', () => {
        const ids = (adminPageDetail as any).data_sources.map((d: any) => d.id);
        expect(ids).toContain('page');
        expect(ids).toContain('versions');
    });

    it('versions API가 올바른 endpoint를 사용함', () => {
        const ds = (adminPageDetail as any).data_sources.find((d: any) => d.id === 'versions');
        expect(ds.endpoint).toContain('/versions');
        expect(ds.endpoint).toContain('route');
    });

    it('deletePageModal이 정의되어 있음', () => {
        const modal = (adminPageDetail as any).modals?.find((m: any) => m.id === 'deletePageModal');
        expect(modal).toBeDefined();
    });

    it('deletePageModal에서 DELETE 메서드를 사용함', () => {
        const modal = (adminPageDetail as any).modals?.find((m: any) => m.id === 'deletePageModal');
        const modalStr = JSON.stringify(modal);
        expect(modalStr).toContain('DELETE');
    });

    it('restoreVersionModal이 정의되어 있음', () => {
        const modal = (adminPageDetail as any).modals?.find((m: any) => m.id === 'restoreVersionModal');
        expect(modal).toBeDefined();
    });

    it('restoreVersionModal이 /versions/.../restore를 호출함', () => {
        const modal = (adminPageDetail as any).modals?.find((m: any) => m.id === 'restoreVersionModal');
        const modalStr = JSON.stringify(modal);
        expect(modalStr).toContain('/versions/');
        expect(modalStr).toContain('/restore');
    });

    it('첨부파일 iteration이 item_var, index_var 네이밍 규칙을 따름', () => {
        const detailStr = JSON.stringify(adminPageDetail);
        // item_var, index_var 사용 확인
        expect(detailStr).toContain('"item_var"');
        expect(detailStr).toContain('"index_var"');
        // "item": 또는 "index": 형태의 잘못된 네이밍 금지
        expect(detailStr).not.toContain('"item_var":"item"');
        expect(detailStr).not.toContain('"index_var":"index"');
    });

    it('수정 버튼이 /admin/pages/{id}/edit로 이동함', () => {
        const editBtn = findById(adminPageDetail, 'header_edit_button');
        expect(editBtn).not.toBeNull();
        const navAction = editBtn.actions.find((a: any) => a.handler === 'navigate');
        expect(navAction.params.path).toContain('/admin/pages/');
        expect(navAction.params.path).toContain('/edit');
    });

    it('목록 버튼이 /admin/pages로 이동함', () => {
        const backBtn = findById(adminPageDetail, 'header_back_button');
        expect(backBtn).not.toBeNull();
        const navAction = backBtn.actions.find((a: any) => a.handler === 'navigate');
        expect(navAction.params.path).toBe('/admin/pages');
    });

    it('발행토글 버튼이 PATCH /publish API를 호출함', () => {
        // 발행/미발행 두 개의 버튼 중 하나에서 확인
        const publishBtn = findById(adminPageDetail, 'publish_btn_active')
            || findById(adminPageDetail, 'publish_btn_inactive');
        expect(publishBtn).not.toBeNull();
        const apiAction = publishBtn.actions.find((a: any) => a.handler === 'apiCall');
        // target 또는 params.endpoint에서 /publish 확인
        const actionStr = JSON.stringify(apiAction);
        expect(actionStr).toContain('/publish');
        expect(apiAction.params.method).toBe('PATCH');
    });

    // ─── 권한 기반 UI 제어 ─────────────────────────

    it('page data_source에 403 errorHandling이 설정됨', () => {
        const ds = (adminPageDetail as any).data_sources.find((d: any) => d.id === 'page');
        expect(ds.errorHandling).toBeDefined();
        expect(ds.errorHandling['403']).toBeDefined();
    });

    it('page data_source에 404 errorHandling이 설정됨', () => {
        const ds = (adminPageDetail as any).data_sources.find((d: any) => d.id === 'page');
        expect(ds.errorHandling['404']).toBeDefined();
    });

    it('수정 버튼에 abilities 기반 disabled가 설정됨', () => {
        const editBtn = findById(adminPageDetail, 'header_edit_button');
        expect(editBtn).not.toBeNull();
        expect(editBtn.props.disabled).toContain('can_update');
    });

    it('삭제 버튼에 abilities 기반 disabled가 설정됨', () => {
        const deleteBtn = findById(adminPageDetail, 'header_delete_button');
        expect(deleteBtn).not.toBeNull();
        expect(deleteBtn.props.disabled).toContain('can_delete');
    });

    it('발행 전환 버튼에 abilities 기반 disabled가 설정됨', () => {
        const publishBtnActive = findById(adminPageDetail, 'publish_btn_active');
        const publishBtnInactive = findById(adminPageDetail, 'publish_btn_inactive');
        // 둘 다 disabled에 can_update 포함
        if (publishBtnActive) {
            expect(publishBtnActive.props.disabled).toContain('can_update');
        }
        if (publishBtnInactive) {
            expect(publishBtnInactive.props.disabled).toContain('can_update');
        }
    });

    it('삭제 확인 모달의 삭제 버튼에 abilities 기반 disabled가 설정됨', () => {
        const modal = (adminPageDetail as any).modals?.find((m: any) => m.id === 'deletePageModal');
        expect(modal).toBeDefined();
        const modalStr = JSON.stringify(modal);
        expect(modalStr).toContain('can_delete');
    });

    it('버전 복원 모달의 복원 버튼에 abilities 기반 disabled가 설정됨', () => {
        const modal = (adminPageDetail as any).modals?.find((m: any) => m.id === 'restoreVersionModal');
        expect(modal).toBeDefined();
        const modalStr = JSON.stringify(modal);
        expect(modalStr).toContain('can_update');
    });

    it('버전 미리보기 모달의 복원 버튼에 abilities 기반 disabled가 설정됨', () => {
        const modal = (adminPageDetail as any).modals?.find((m: any) => m.id === 'versionPreviewModal');
        expect(modal).toBeDefined();
        const modalStr = JSON.stringify(modal);
        expect(modalStr).toContain('can_update');
    });
});
