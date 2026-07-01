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

    // ─── Issue #280: 유저 페이지 이동 링크 ─────────────

    it('title 컬럼의 제목이 Span 컴포넌트로 렌더링됨 (링크 없음)', () => {
        const grids = findComponentsByName(adminPageList, 'DataGrid');
        const titleColumn = grids[0].props.columns.find((c: any) => c.field === 'title');
        const firstChild = titleColumn?.cellChildren?.[0]?.children?.[0];
        expect(firstChild?.name).toBe('Span');
    });

    it('title 컬럼의 /page/{slug} 링크가 A 컴포넌트로 렌더링됨', () => {
        const grids = findComponentsByName(adminPageList, 'DataGrid');
        const titleColumn = grids[0].props.columns.find((c: any) => c.field === 'title');
        const aComponent = titleColumn?.cellChildren?.[0]?.children?.[1];
        expect(aComponent?.name).toBe('A');
        expect(aComponent?.props?.href).toContain('/page/');
        expect(aComponent?.props?.href).toContain('row.slug');
    });

    it('title 링크 A 컴포넌트가 새 탭(_blank)으로 열림', () => {
        const grids = findComponentsByName(adminPageList, 'DataGrid');
        const titleColumn = grids[0].props.columns.find((c: any) => c.field === 'title');
        const aComponent = titleColumn?.cellChildren?.[0]?.children?.[1];
        expect(aComponent?.props?.target).toBe('_blank');
    });

    it('slug 컬럼이 A 컴포넌트로 렌더링되고 /page/{slug}를 가리킴', () => {
        const grids = findComponentsByName(adminPageList, 'DataGrid');
        const slugColumn = grids[0].props.columns.find((c: any) => c.field === 'slug');
        const aComponent = slugColumn?.cellChildren?.[0];
        expect(aComponent?.name).toBe('A');
        expect(aComponent?.props?.href).toContain('/page/');
        expect(aComponent?.props?.href).toContain('row.slug');
        expect(aComponent?.props?.target).toBe('_blank');
    });

    // ─── 발행 상태 필터: list-toolbar Select 정합 ───

    it('list_options 의 published_filter_select 가 composite Select 로 정의됨', () => {
        const select = findById(adminPageList, 'published_filter_select');
        expect(select).not.toBeNull();
        expect(select.type).toBe('composite');
        expect(select.name).toBe('Select');
    });

    it('published_filter_select 가 list_options 안에서 sort_select 보다 앞에 위치함', () => {
        const listOptions = findById(adminPageList, 'list_options');
        expect(listOptions).not.toBeNull();
        const childIds = (listOptions.children ?? []).map((c: any) => c.id);
        const publishedIdx = childIds.indexOf('published_filter_select');
        const sortIdx = childIds.indexOf('sort_select');
        expect(publishedIdx).toBeGreaterThanOrEqual(0);
        expect(sortIdx).toBeGreaterThanOrEqual(0);
        expect(publishedIdx).toBeLessThan(sortIdx);
    });

    it("published_filter_select 가 query.published 를 value 로 바인딩하고 nullish fallback '' 을 사용함", () => {
        const select = findById(adminPageList, 'published_filter_select');
        expect(select.props.value).toContain('query.published');
        expect(select.props.value).toMatch(/\?\?\s*''/);
    });

    it("published_filter_select options 가 ''/'1'/'0' 세 값과 published_filter 다국어 라벨을 가짐", () => {
        const select = findById(adminPageList, 'published_filter_select');
        const values = (select.props.options ?? []).map((o: any) => o.value);
        expect(values).toEqual(['', '1', '0']);
        const labels = (select.props.options ?? []).map((o: any) => o.label);
        expect(labels).toContain('$t:sirsoft-page.admin.page.published_filter.all');
        expect(labels).toContain('$t:sirsoft-page.admin.page.published_filter.published');
        expect(labels).toContain('$t:sirsoft-page.admin.page.published_filter.unpublished');
    });

    it("published_filter_select change 가 navigate 로 published 쿼리를 갱신하고 페이지를 1 로 리셋함", () => {
        const select = findById(adminPageList, 'published_filter_select');
        const changeAction = (select.actions ?? []).find((a: any) => a.type === 'change');
        expect(changeAction).toBeDefined();
        expect(changeAction.handler).toBe('navigate');
        expect(changeAction.params.path).toBe('/admin/pages');
        expect(changeAction.params.mergeQuery).toBe(true);
        expect(changeAction.params.query.published).toContain('$event.target.value');
        expect(changeAction.params.query.page).toBe(1);
    });

    it('기존 좌측 카드 필터(filter_tabs_area / published_filter_tabs) 가 더 이상 존재하지 않음', () => {
        expect(findById(adminPageList, 'filter_tabs_area')).toBeNull();
        expect(findById(adminPageList, 'published_filter_tabs')).toBeNull();
        expect(findById(adminPageList, 'published_all')).toBeNull();
        expect(findById(adminPageList, 'published_true')).toBeNull();
        expect(findById(adminPageList, 'published_false')).toBeNull();
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

    it('footer_save_button의 액션에 setState, emitEvent, apiCall이 포함됨', () => {
        const btn = findById(adminPageForm, 'footer_save_button');
        expect(btn).not.toBeNull();
        const handlers = extractHandlersFromActions(btn.actions);
        expect(handlers).toContain('setState');
        expect(handlers).toContain('emitEvent');
        expect(handlers).toContain('apiCall');
    });

    it('emitEvent가 upload:page_attachments 이벤트를 발행함', () => {
        const btn = findById(adminPageForm, 'footer_save_button');
        const btnStr = JSON.stringify(btn);
        expect(btnStr).toContain('upload:page_attachments');
    });

    it('apiCall body에 temp_key가 포함됨', () => {
        const btn = findById(adminPageForm, 'footer_save_button');
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
        const saveBtn = findById(adminPageForm, 'footer_save_button');
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

    // ─── Issue #280: 수정 모드 슬러그 편집 가능 ─────────

    it('slug_input의 disabled 조건이 isReadOnly만 참조함 (route.id 제외)', () => {
        const slugInput = findById(adminPageForm, 'slug_input');
        expect(slugInput).not.toBeNull();
        expect(slugInput.props.disabled).not.toContain('route?.id');
        expect(slugInput.props.disabled).not.toContain('route.id');
        expect(slugInput.props.disabled).toContain('isReadOnly');
    });

    it('slug_check_button이 isReadOnly만으로 조건부 표시됨 (route.id 제외)', () => {
        const checkBtn = findById(adminPageForm, 'slug_check_button');
        expect(checkBtn).not.toBeNull();
        expect(checkBtn.if).not.toContain('route?.id');
        expect(checkBtn.if).not.toContain('!route');
        expect(checkBtn.if).toContain('isReadOnly');
    });

    it('저장 버튼의 slugChecked 차단 조건에 route.id가 포함되지 않음 (생성·수정 공통)', () => {
        const saveBtn = findById(adminPageForm, 'footer_save_button');
        const clickAction = saveBtn?.actions?.find((a: any) => a.type === 'click');
        const blockCondition = clickAction?.conditions?.[0];
        expect(blockCondition).toBeDefined();
        expect(blockCondition.if).toContain('slugChecked');
        const hasOldPattern =
            blockCondition.if.includes('route?.id && !_local.slugChecked') ||
            blockCondition.if.includes('!route?.id && !_local.slugChecked');
        expect(hasOldPattern).toBe(false);
    });

    it('check-slug API 호출 body에 exclude_id가 route.id를 전달함', () => {
        const checkBtn = findById(adminPageForm, 'slug_check_button');
        const btnStr = JSON.stringify(checkBtn);
        expect(btnStr).toContain('exclude_id');
        expect(btnStr).toContain('route?.id');
    });

    it('init_actions에 수정 모드 slugChecked 초기화 액션이 있음', () => {
        const initActions = (adminPageForm as any).init_actions;
        const initStr = JSON.stringify(initActions);
        expect(initStr).toContain('slugChecked');
        expect(initStr).toContain('slugAvailable');
    });

    it('slug_input의 change 액션이 slugChecked: false로 리셋함', () => {
        const slugInput = findById(adminPageForm, 'slug_input');
        const inputStr = JSON.stringify(slugInput);
        expect(inputStr).toContain('slugChecked');
        expect(inputStr).toContain('false');
    });

    // ─── 슬러그 + 발행여부 그리드: 표준 자산 정합 ───

    it('slug_published_grid 가 grid-2col-responsive 자산을 단독 사용함 (호출처 gap-* 토큰 제거)', () => {
        const grid = findById(adminPageForm, 'slug_published_grid');
        expect(grid).not.toBeNull();
        expect(grid.props.className).toBe('grid-2col-responsive');
        // 자산이 반응형을 포함하므로 호출처 responsive override 없어야 함
        expect(grid.responsive).toBeUndefined();
    });

    it('slug_field / published_field 의 Label 이 form-label 자산을 사용함', () => {
        const slugField = findById(adminPageForm, 'slug_field');
        const publishedField = findById(adminPageForm, 'published_field');
        const slugLabel = (slugField.children ?? []).find((c: any) => c.name === 'Label');
        const publishedLabel = (publishedField.children ?? []).find((c: any) => c.name === 'Label');
        expect(slugLabel?.props?.className).toBe('form-label');
        expect(publishedLabel?.props?.className).toBe('form-label');
    });

    it('slug_field 가 className 부재 — form-label 의 mb-1 만으로 라벨↔입력 간격 처리 (stack-tight 회귀 차단)', () => {
        const slugField = findById(adminPageForm, 'slug_field');
        // stack-tight 의 gap-2 가 form-label 의 mb-1 위에 중복 적용되어 라벨↔입력 간격이
        // 4px + 8px = 12px (발행 측의 3배) 로 비대칭이 되던 회귀를 차단
        expect(slugField.props?.className).toBeUndefined();
    });

    it('slug_field 의 안내 Span 2종이 form-hint block 자산으로 통일 (mt-1 내장으로 입력행 간 시각 간격 확보)', () => {
        const slugField = findById(adminPageForm, 'slug_field');
        const directSpans = (slugField.children ?? []).filter((c: any) => c.name === 'Span');
        // URL 미리보기 안내 + 영문 소문자 안내 — 둘 다 form-hint block 자산 사용
        const formHintSpans = directSpans.filter(
            (s: any) => s.props?.className === 'form-hint block',
        );
        expect(formHintSpans.length).toBeGreaterThanOrEqual(2);
        // text-tertiary / text-hint 톤 분기 회귀 차단 (현재는 form-hint 로 통일)
        const oldToneSpans = directSpans.filter(
            (s: any) =>
                s.props?.className === 'text-tertiary block' ||
                s.props?.className === 'text-hint block',
        );
        expect(oldToneSpans.length).toBe(0);
        // 직속 자식 중에 className 이 'row-stack' 인 Div wrapper 가 더 이상 없어야 함
        const nestedRowStack = (slugField.children ?? []).find(
            (c: any) => c.name === 'Div' && c.props?.className === 'row-stack',
        );
        expect(nestedRowStack).toBeUndefined();
    });

    it('published_field 가 className 부재 (admin/settings 필드 셀 패턴) 로 Label + Select 를 직속 자식으로 보유', () => {
        const publishedField = findById(adminPageForm, 'published_field');
        // 필드 셀에는 className 부재 — form-label 의 mb-1 이 라벨↔Select 간격 담당
        expect(publishedField.props?.className).toBeUndefined();
        const childNames = (publishedField.children ?? []).map((c: any) => c.name);
        expect(childNames).toEqual(['Label', 'Select']);
        // published_select 가 published_field 직속 자식인지 확인 (id 위치 보존)
        const selectChild = publishedField.children.find((c: any) => c.id === 'published_select');
        expect(selectChild).toBeDefined();
    });

    // ─── 기본 정보 / SEO 필드 셀 정합 (#408 grid 셀 직속 row-stack 회귀 차단) ───

    it('basic_info_fields / seo_fields 는 다중 row 컨테이너로 row-stack 유지', () => {
        // 다중 입력 row 리스트 컨테이너는 row-stack 가 의도된 사용 (행 사이 divide-y)
        const basic = findById(adminPageForm, 'basic_info_fields');
        const seo = findById(adminPageForm, 'seo_fields');
        expect(basic.props.className).toBe('row-stack');
        expect(seo.props.className).toBe('row-stack');
    });

    it('title_field / content_field 가 className 부재 + form-label 표준 패턴 (stack-tight 중복 간격 회귀 차단)', () => {
        const titleField = findById(adminPageForm, 'title_field');
        const contentField = findById(adminPageForm, 'content_field');
        // form-label 의 mb-1 만으로 라벨↔입력 간격 처리 (admin/settings · admin_user_form 표준)
        expect(titleField.props?.className).toBeUndefined();
        expect(contentField.props?.className).toBeUndefined();

        const titleLabel = (titleField.children ?? []).find((c: any) => c.name === 'Label');
        const contentLabel = (contentField.children ?? []).find((c: any) => c.name === 'Label');
        expect(titleLabel?.props?.className).toBe('form-label');
        expect(contentLabel?.props?.className).toBe('form-label');
    });

    it('seo_* 필드 셀이 className 부재 + 빈 Div wrapper 없이 form-label-muted Label + 입력을 직속 자식으로 보유', () => {
        const ids = ['seo_title_field', 'seo_description_field', 'seo_keywords_field'];
        for (const id of ids) {
            const field = findById(adminPageForm, id);
            expect(field, id).not.toBeNull();
            // SEO 필드 셀 자체에는 className 부재 (단일 필드 셀 표준 패턴)
            expect(field.props?.className, id).toBeUndefined();
            // 빈 Div wrapper 가 더 이상 없어야 함 — Label 이 직속 자식
            const directLabel = (field.children ?? []).find((c: any) => c.name === 'Label');
            expect(directLabel, id).toBeDefined();
            // 보조 라벨 톤 자산화 — form-label-muted (text-secondary block text-sm font-medium 원자 토큰 흡수)
            expect(directLabel.props.className, id).toBe('form-label-muted');
            // 빈 className Div wrapper 미존재 확인
            const emptyDivWrapper = (field.children ?? []).find(
                (c: any) => c.name === 'Div' && !c.props,
            );
            expect(emptyDivWrapper, id).toBeUndefined();
        }
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

    it("_local.lang fallback이 하드코딩 'ko' 가 아닌 $locale 을 사용함 (새로고침 시 탭/콘텐츠 locale 불일치 회귀 방지)", () => {
        const layoutStr = JSON.stringify(adminPageDetail);
        // 하드코딩 'ko' fallback 금지 — 새로고침 직후 _local.lang 이 undefined 인 동안
        // 탭은 $locale 로 강조되지만 콘텐츠가 'ko' 로 표시되어 locale 불일치 발생
        expect(layoutStr).not.toMatch(/_local\.lang \?\? 'ko'/);
        // $locale 로 fallback 하는 표현식이 존재해야 함 (탭 + 콘텐츠 표현식)
        expect(layoutStr).toMatch(/_local\.lang \?\? \$locale/);
    });
});
