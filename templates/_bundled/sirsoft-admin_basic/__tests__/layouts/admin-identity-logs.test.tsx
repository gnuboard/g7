/**
 * @file admin-identity-logs.test.tsx
 * @description 본인인증 이력 (#297) — 알림 발송 이력과 동일 수준 UI/필터/DataGrid 회귀 테스트
 *
 * 회귀 사례 (#297):
 *  - 검색 input 이 사용자 ID/target 해시 모두로 동작하지 않던 문제
 *    (백엔드: AdminIdentityLogIndexTest 에서 별도 회귀 보장)
 *  - 알림 발송 이력 대비 UI 일관성 부족 (탭/필터/DataGrid/responsive 누락)
 *
 * 검증 포인트:
 *  - data_sources: identityLogs 가 search/search_type/sort_by/sort_order 파라미터 전달
 *  - 응답 바인딩 경로: identityLogs?.data?.data / identityLogs?.data?.pagination?.*
 *  - named_actions.searchIdentityLogs 정의 + filter partial actionRef 참조
 *  - Provider 탭 iteration (identityProviders.data)
 *  - Filter partial: searchType select(auto/user_id/target_hash) + status/purpose 멀티 체크박스
 *  - DataGrid composite: serverSidePagination, expandable, selectable=false (감사 로그 무결성)
 *  - transition_overlay_target: identity_log_datagrid__body 부분 로딩
 *  - 모달 partial 분리
 */

import { describe, it, expect } from 'vitest';

const mainLayout = require('../../layouts/admin_identity_logs.json');
const filterPartial = require('../../layouts/partials/admin_identity_logs/_partial_filter.json');
const datagridPartial = require('../../layouts/partials/admin_identity_logs/_partial_datagrid.json');
const modalPartial = require('../../layouts/partials/admin_identity_logs/_modal_log_detail.json');
const purgeModalPartial = require('../../layouts/partials/admin_identity_logs/_modal_purge_confirm.json');

function collectNodes(node: any, predicate: (n: any) => boolean): any[] {
    const result: any[] = [];
    const visit = (n: any) => {
        if (!n || typeof n !== 'object') return;
        if (Array.isArray(n)) {
            n.forEach(visit);
            return;
        }
        if (predicate(n)) result.push(n);
        if (n.children) visit(n.children);
        if (n.cellChildren) visit(n.cellChildren);
        if (n.expandChildren) visit(n.expandChildren);
        if (n.actions) visit(n.actions);
        if (n.params) visit(n.params);
        if (n.onSuccess) visit(n.onSuccess);
        if (n.onError) visit(n.onError);
        if (n.slots) visit(Object.values(n.slots));
        if (n.modals) visit(n.modals);
    };
    visit(node);
    return result;
}

describe('본인인증 이력 — 알림 발송 이력과 동일 수준 UI 회귀 (#297)', () => {
    describe('data_sources — 새 검색/정렬 파라미터 + 알림 이력과 동일 응답 구조', () => {
        it('identityLogs 데이터소스가 search/search_type/sort_by/sort_order 를 전달한다', () => {
            const ds = mainLayout.data_sources.find((d: any) => d.id === 'identityLogs');
            expect(ds).toBeTruthy();
            expect(ds.params).toMatchObject({
                search: expect.stringMatching(/query\.search/),
                search_type: expect.stringMatching(/query\.search_type/),
                sort_by: expect.stringMatching(/query\.sort_by/),
                sort_order: expect.stringMatching(/query\.sort_order/),
            });
        });

        it('progressive 로딩 + 403 errorHandling 적용 (알림 이력과 동일)', () => {
            const ds = mainLayout.data_sources.find((d: any) => d.id === 'identityLogs');
            expect(ds.loading_strategy).toBe('progressive');
            expect(ds.errorHandling?.['403']?.handler).toBe('showErrorPage');
        });

        it('fallback 응답 구조가 {data, pagination, abilities} 형식이다', () => {
            const ds = mainLayout.data_sources.find((d: any) => d.id === 'identityLogs');
            expect(ds.fallback?.data).toMatchObject({
                data: expect.any(Array),
                pagination: expect.any(Object),
                abilities: expect.any(Object),
            });
        });
    });

    describe('named_actions — 검색 액션 재사용', () => {
        it('searchIdentityLogs 가 정의되고 transition_overlay_target 을 사용한다', () => {
            expect(mainLayout.named_actions?.searchIdentityLogs).toBeTruthy();
            expect(mainLayout.named_actions.searchIdentityLogs.params?.transition_overlay_target)
                .toBe('identity_log_datagrid__body');
        });

        it('필터 partial 의 검색 버튼/엔터 키가 actionRef 로 searchIdentityLogs 를 참조한다', () => {
            const refs = collectNodes(filterPartial, (n) => n.actionRef === 'searchIdentityLogs');
            // 검색 input Enter + 검색 버튼 click — 최소 2회
            expect(refs.length).toBeGreaterThanOrEqual(2);
        });
    });

    describe('Provider 탭 (알림 발송 이력의 채널 탭과 동일 패턴)', () => {
        it('전체 탭 + identityProviders.data iteration 으로 동적 탭 렌더', () => {
            const tabIters = collectNodes(mainLayout, (n) =>
                n.iteration?.source && /identityProviders\?\.data/.test(n.iteration.source)
            );
            expect(tabIters.length).toBeGreaterThan(0);
        });
    });

    describe('Filter partial — search type + 상태/Purpose 멀티 체크박스', () => {
        it('searchType select 가 auto/user_id/target_hash 옵션을 가진다', () => {
            const selects = collectNodes(filterPartial, (n) =>
                n.type === 'composite' && n.name === 'Select' && n.props?.name === 'searchType'
            );
            expect(selects.length).toBe(1);
            const values = selects[0].props.options.map((o: any) => o.value);
            expect(values).toEqual(expect.arrayContaining(['auto', 'user_id', 'target_hash']));
        });

        it('상태 멀티 체크박스 — 7개 status iteration', () => {
            const statusIters = collectNodes(filterPartial, (n) =>
                n.iteration?.item_var === 'st'
            );
            expect(statusIters.length).toBe(1);
            expect(statusIters[0].iteration.source).toContain('verified');
            expect(statusIters[0].iteration.source).toContain('policy_violation_logged');
        });

        it('Purpose 멀티 체크박스 — identityPurposes.data iteration', () => {
            const purposeIters = collectNodes(filterPartial, (n) =>
                n.iteration?.item_var === 'pp'
            );
            expect(purposeIters.length).toBe(1);
            expect(purposeIters[0].iteration.source).toContain('identityPurposes');
        });

        it('필터 버튼이 responsive.portable 에서 flex-1 풀너비를 사용한다', () => {
            const buttons = collectNodes(filterPartial, (n) =>
                n.name === 'Button' && n.responsive?.portable?.props?.className?.includes('flex-1')
            );
            // 검색 + 초기화 = 2개
            expect(buttons.length).toBe(2);
        });
    });

    describe('DataGrid partial — composite + serverSidePagination + expandable + selectable=false', () => {
        it('composite DataGrid 가 사용되고 serverSidePagination 활성화', () => {
            expect(datagridPartial.type).toBe('composite');
            expect(datagridPartial.name).toBe('DataGrid');
            expect(datagridPartial.props.serverSidePagination).toBe(true);
        });

        it('selectable=false (감사 로그 무결성 — 일괄/단건 삭제 미지원)', () => {
            expect(datagridPartial.props.selectable).toBe(false);
            expect(datagridPartial.props.rowActions).toBeUndefined();
        });

        it('expandable + expandChildren 으로 행 펼침 인라인 상세 (target_hash/origin/properties/metadata)', () => {
            expect(datagridPartial.props.expandable).toBe(true);
            const expanded = JSON.stringify(datagridPartial.props.expandChildren);
            expect(expanded).toContain('target_hash');
            expect(expanded).toContain('origin_type');
            expect(expanded).toContain('properties');
            expect(expanded).toContain('metadata');
        });

        it('데이터 바인딩 경로가 identityLogs?.data?.data / pagination 형식', () => {
            expect(datagridPartial.props.data).toBe('{{identityLogs?.data?.data}}');
            expect(datagridPartial.props.serverCurrentPage).toContain('pagination?.current_page');
            expect(datagridPartial.props.serverTotalPages).toContain('pagination?.last_page');
        });

        it('onPageChange 가 identity_log_datagrid__body 로 부분 로딩', () => {
            const pageActions = (datagridPartial.actions ?? []).filter(
                (a: any) => a.event === 'onPageChange'
            );
            expect(pageActions.length).toBe(1);
            expect(pageActions[0].params?.transition_overlay_target).toBe('identity_log_datagrid__body');
        });

        it('컬럼 7개 (생성일시/Provider/Purpose/채널/상태/사용자/IP/시도)', () => {
            const fields = datagridPartial.props.columns.map((c: any) => c.field);
            expect(fields).toEqual([
                'created_at', 'provider_id', 'purpose', 'channel',
                'status', 'user_id', 'ip_address', 'attempts',
            ]);
        });
    });

    describe('main layout — 정렬/페이지 사이즈 select + refresh + partial 참조', () => {
        it('정렬 select 가 created_at_desc/asc, attempts_desc 옵션 제공', () => {
            const sortSelect = collectNodes(mainLayout, (n) =>
                n.type === 'composite' && n.name === 'Select' && n.props?.name === 'sortBy'
            );
            expect(sortSelect.length).toBe(1);
            const values = sortSelect[0].props.options.map((o: any) => o.value);
            expect(values).toEqual(expect.arrayContaining([
                'created_at_desc', 'created_at_asc', 'attempts_desc',
            ]));
        });

        it('per_page select 가 10/20/50/100 옵션 제공', () => {
            const perPage = collectNodes(mainLayout, (n) =>
                n.type === 'composite' && n.name === 'Select' && n.props?.name === 'perPage'
            );
            expect(perPage.length).toBe(1);
            const values = perPage[0].props.options.map((o: any) => o.value);
            expect(values).toEqual(['10', '20', '50', '100']);
        });

        it('refresh 버튼이 refetchDataSource(identityLogs) 를 호출', () => {
            const refresh = collectNodes(mainLayout, (n) =>
                n.id === 'refresh_button'
            );
            expect(refresh.length).toBe(1);
            const stringified = JSON.stringify(refresh[0]);
            expect(stringified).toContain('refetchDataSource');
            expect(stringified).toContain('identityLogs');
        });

        it('파기 버튼이 abilities.can_purge 에 따라 표시', () => {
            const purge = collectNodes(mainLayout, (n) => n.id === 'purge_button');
            expect(purge.length).toBe(1);
            expect(purge[0].if).toContain('abilities?.can_purge');
        });

        it('main layout 이 filter/datagrid/modal partial 을 모두 참조', () => {
            const stringified = JSON.stringify(mainLayout);
            expect(stringified).toContain('partials/admin_identity_logs/_partial_filter.json');
            expect(stringified).toContain('partials/admin_identity_logs/_partial_datagrid.json');
            expect(stringified).toContain('partials/admin_identity_logs/_modal_log_detail.json');
        });
    });

    describe('modal partial — composite Modal + isolated 스코프 호환 (_global 경유)', () => {
        it('composite Modal 로 정의됨', () => {
            expect(modalPartial.type).toBe('composite');
            expect(modalPartial.name).toBe('Modal');
            expect(modalPartial.id).toBe('identity_log_detail_modal');
        });

        it('상세 버튼이 _global.identity_log_modal_data 에 row 를 저장한다 (_local 사용 금지 — 모달 isolated)', () => {
            const stringified = JSON.stringify(datagridPartial);
            // 모달 스코프가 isolated 라 _local 접근 불가 → _global namespace 사용 필수
            expect(stringified).toContain('identity_log_modal_data');
            expect(stringified).toContain('"target":"global"');
            expect(stringified).toContain('"identity_log_modal_data":"{{row}}"');
            // 이전 _local.selected 패턴 잔재 없음
            expect(stringified).not.toContain('"selected":"{{row}}"');
        });

        it('모달 본문이 _global.identity_log_modal_data 에서 모든 핵심 필드를 읽는다', () => {
            const stringified = JSON.stringify(modalPartial);
            ['provider_id', 'purpose', 'status', 'channel', 'user_id',
                'ip_address', 'created_at', 'attempts', 'target_hash']
                .forEach((field) => {
                    expect(stringified).toContain(`_global.identity_log_modal_data?.${field}`);
                });
            // _local.selected 잔재 없음
            expect(stringified).not.toContain('_local.selected');
        });
    });

    describe('파기 확인 모달 — confirm 핸들러 금지, 모달 + 스피너 패턴', () => {
        it('purge_button 이 confirm 핸들러를 사용하지 않고 openModal 로 모달을 연다', () => {
            const purge = collectNodes(mainLayout, (n) => n.id === 'purge_button');
            expect(purge.length).toBe(1);
            // confirm 핸들러는 등록되어 있지 않으므로 사용 금지
            const handlers = collectNodes(purge[0], (n) => typeof n.handler === 'string')
                .map((n) => n.handler);
            expect(handlers).not.toContain('confirm');
            expect(handlers).toContain('openModal');
            expect(JSON.stringify(purge[0])).toContain('identity_log_purge_confirm_modal');
        });

        it('main layout 이 purge confirm 모달 partial 을 참조', () => {
            expect(JSON.stringify(mainLayout))
                .toContain('partials/admin_identity_logs/_modal_purge_confirm.json');
        });

        it('purge 모달이 composite Modal + apiCall + isPurgingIdentityLogs 토글 사용', () => {
            expect(purgeModalPartial.type).toBe('composite');
            expect(purgeModalPartial.name).toBe('Modal');
            expect(purgeModalPartial.id).toBe('identity_log_purge_confirm_modal');

            const stringified = JSON.stringify(purgeModalPartial);
            expect(stringified).toContain('apiCall');
            expect(stringified).toContain('/api/admin/identity/logs/purge');
            expect(stringified).toContain('isPurgingIdentityLogs');
            expect(stringified).toContain('refetchDataSource');
        });

        it('진행 중일 때 스피너 아이콘이 노출되고 버튼은 disabled', () => {
            const spinners = collectNodes(purgeModalPartial, (n) =>
                n.name === 'Icon' && n.props?.name === 'fa-solid fa-spinner'
            );
            expect(spinners.length).toBeGreaterThan(0);
            expect(spinners[0].if).toContain('isPurgingIdentityLogs');

            const disabledBtns = collectNodes(purgeModalPartial, (n) =>
                n.name === 'Button' && typeof n.props?.disabled === 'string'
                && n.props.disabled.includes('isPurgingIdentityLogs')
            );
            // 취소 + 파기 버튼 모두 disabled 바인딩
            expect(disabledBtns.length).toBe(2);
        });
    });

    describe('금지 패턴 회귀', () => {
        it('Tailwind 디바이스 분기(hidden md:* / md:hidden) 사용 안 함', () => {
            const allStr = JSON.stringify({ mainLayout, filterPartial, datagridPartial, modalPartial });
            expect(allStr).not.toMatch(/hidden md:block/);
            expect(allStr).not.toMatch(/md:hidden/);
        });

        it('basic Table/Thead/Tr/Td 직접 작성 패턴 제거 (composite DataGrid 사용)', () => {
            const tables = collectNodes({ ...datagridPartial, ...mainLayout }, (n) =>
                n.type === 'basic' && n.name === 'Table'
            );
            expect(tables.length).toBe(0);
        });
    });
});
