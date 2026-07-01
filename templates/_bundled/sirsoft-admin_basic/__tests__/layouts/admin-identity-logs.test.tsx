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
 *  - Provider 탭: TabNavigation tabs prop 표현식 (identityProviders.data concat)
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
        it('전체 탭 + identityProviders.data 를 concat 한 동적 tabs 표현식으로 탭 렌더', () => {
            // 채널/Provider 탭은 iteration 이 아니라 TabNavigation 의 tabs prop 표현식으로 구성된다.
            // [{전체 탭}].concat((identityProviders?.data ?? []).map(...)) 형태.
            const tabNodes = collectNodes(mainLayout, (n) =>
                typeof n.props?.tabs === 'string'
                && /identityProviders\?\.data/.test(n.props.tabs)
                && /concat/.test(n.props.tabs)
            );
            expect(tabNodes.length).toBeGreaterThan(0);
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

    describe('채널 라벨 다국어 변환 회귀 (#415 — 채널 식별자 raw 노출 버그)', () => {
        // 배경: 채널이 'email'/'ipin' 같은 식별자 그대로 표시되던 버그.
        // ProviderResource 가 channel_labels(채널키→다국어라벨) 를 내려주고,
        // 레이아웃 3곳(필터 체크박스 / DataGrid 채널 컬럼 / 상세 모달)이 이를 변환한다.
        // 각 provider 가 getChannelLabels() 로 자기 채널의 다국어 라벨을 제공(언어팩 활성화 대비).

        it('필터 채널 체크박스가 channel_labels 맵으로 라벨 변환 (raw {{ch}} 직출력 금지)', () => {
            const channelIters = collectNodes(filterPartial, (n) =>
                n.iteration?.item_var === 'ch'
            );
            expect(channelIters.length).toBe(1);
            const stringified = JSON.stringify(channelIters[0]);
            // channel_labels 맵을 병합해 ch 키로 라벨 조회
            expect(stringified).toContain('channel_labels');
            // 라벨 부재 시 ch 폴백 유지 (provider 자유 채널 대비)
            expect(stringified).toMatch(/\]\s*\?\?\s*ch/);
            // raw 식별자 직출력(text === "{{ch}}") 잔재 없음
            const rawCh = collectNodes(channelIters[0], (n) => n.text === '{{ch}}');
            expect(rawCh.length).toBe(0);
        });

        it('DataGrid 채널 컬럼이 channel_labels 맵으로 라벨 변환 (raw row.channel 직출력 금지)', () => {
            const channelCol = datagridPartial.props.columns.find((c: any) => c.field === 'channel');
            expect(channelCol).toBeTruthy();
            const stringified = JSON.stringify(channelCol);
            expect(stringified).toContain('channel_labels');
            // 이전 raw 패턴("{{row.channel ?? '-'}}") 잔재 없음
            expect(stringified).not.toContain("{{row.channel ?? '-'}}");
            // 폴백: 라벨 부재 시 row.channel, 채널 없으면 '-'
            expect(stringified).toContain("'-'");
        });

        it('상세 모달 채널 필드가 channel_labels 맵으로 라벨 변환', () => {
            const stringified = JSON.stringify(modalPartial);
            expect(stringified).toContain('channel_labels');
            // 이전 raw 패턴 잔재 없음
            expect(stringified).not.toContain("identity_log_modal_data?.channel ?? '-'");
        });

        it('상세 모달 인증수단 필드가 provider label 로 변환 (raw provider_id 직출력 금지)', () => {
            // 배경(라이브 증거): DataGrid 컬럼/필터/탭은 provider_id 를 label("KG이니시스 본인확인")로
            // 변환하는데, 상세 모달의 "인증 수단" 필드만 raw 'inicis' 를 노출했다.
            // 모달 채널 필드가 이미 identityProviders?.data 에 접근하므로(모달 스코프에서 부모 데이터소스 가시),
            // provider_id 도 동일하게 identityProviders 의 label 로 변환해야 한다.
            const stringified = JSON.stringify(modalPartial);
            // identityProviders 의 label 로 변환 (DataGrid 컬럼과 동일 규약)
            expect(stringified).toContain('identityProviders');
            // 이전 raw 패턴("identity_log_modal_data?.provider_id ?? '-'") 잔재 없음
            expect(stringified).not.toContain("identity_log_modal_data?.provider_id ?? '-'");
        });
    });

    describe('배열 필터 query 키 접근 회귀 (#415 — 상태/목적/채널/발생유형 필터 전부 미적용 버그)', () => {
        // 배경(라이브 증거): 화면에서 "요청됨" 체크 후 검색 → URL 은 ?statuses[]=requested 로 정상 기록되나,
        // 데이터소스가 보낸 실제 API 요청에서 statuses 가 누락되어 필터가 전혀 안 걸러짐(108건 그대로).
        // 근본 원인: DataSourceManager.parseQueryParams 가 배열 URL 파라미터를
        //   query['statuses[]'] (대괄호 포함 키) 로 파싱한다(이커머스 admin_ecommerce_*_list 가 쓰는 정상 규약).
        //   그런데 본인인증 이력 레이아웃만 query.statuses (대괄호 없음 → 항상 undefined) 로 읽어 누락됐다.
        // 영향 4종: statuses / purposes / channels / origin_types (데이터소스 params 4 + init_actions 5).

        const ARRAY_FILTER_QUERY_KEYS = [
            'statuses[]', 'purposes[]', 'channels[]', 'origin_types[]',
        ];

        it("데이터소스 identityLogs 가 배열 필터를 query['key[]'] (대괄호 포함 키) 로 읽는다", () => {
            const ds = mainLayout.data_sources.find((d: any) => d.id === 'identityLogs');
            const params = ds.params;
            // 대괄호 포함 키로 접근해야 parseQueryParams 결과와 매칭됨
            expect(params.statuses).toContain("query['statuses[]']");
            expect(params.purposes).toContain("query['purposes[]']");
            expect(params.channels).toContain("query['channels[]']");
            expect(params.origin_types).toContain("query['origin_types[]']");
            // 대괄호 없는 깨진 접근(query.statuses 등) 잔재 없음
            ['statuses', 'purposes', 'channels', 'origin_types'].forEach((k) => {
                expect(params[k]).not.toMatch(new RegExp(`query\\.${k}(?![\\w'\\[])`));
            });
        });

        it("init_actions(필터 상태 복원) 가 배열 필터를 query['key[]'] 로 복원한다", () => {
            const setStateAction = mainLayout.init_actions.find(
                (a: any) => a.handler === 'setState' && a.params?.filter
            );
            expect(setStateAction).toBeTruthy();
            const filter = setStateAction.params.filter;
            // statuses/purposes/channels/originTypes 복원 표현식이 대괄호 포함 키를 참조
            expect(filter.statuses).toContain("query['statuses[]']");
            expect(filter.purposes).toContain("query['purposes[]']");
            expect(filter.channels).toContain("query['channels[]']");
            expect(filter.originTypes).toContain("query['origin_types[]']");
            // advancedOpen 도 origin_types 배열 키로 판정
            expect(filter.advancedOpen).toContain("query['origin_types[]']");
        });

        it('레이아웃 어디에도 대괄호 없는 배열 필터 query 접근(query.statuses 등) 이 남아있지 않다', () => {
            const allStr = JSON.stringify(mainLayout);
            // query.statuses / query.purposes / query.channels / query.origin_types (대괄호/단어경계 직후가 아닌 경우)
            ['statuses', 'purposes', 'channels', 'origin_types'].forEach((k) => {
                // query.<key> 뒤에 [ 나 영문/숫자/' 가 아닌 경계 → 대괄호 없는 깨진 접근
                expect(allStr).not.toMatch(new RegExp(`query\\.${k}(?![\\w'\\[])`));
            });
        });

        it('이커머스 정상 규약과 동일하게 || [] 폴백 또는 단일값 처리로 안전하다', () => {
            // 배열이 비어있을 때(필터 미선택) undefined 가 아닌 안전한 값으로 처리되는지 — params 는 빈값이면 누락(정상)
            const ds = mainLayout.data_sources.find((d: any) => d.id === 'identityLogs');
            // init_actions 의 statuses 복원은 Array.isArray 가드를 거쳐 [] 폴백
            const filter = mainLayout.init_actions.find(
                (a: any) => a.params?.filter
            ).params.filter;
            ARRAY_FILTER_QUERY_KEYS.forEach((qk) => {
                const accessor = `query['${qk}']`;
                // 적어도 한 곳(init_actions)에서 이 키를 참조
                const inFilter = Object.values(filter).some(
                    (v: any) => typeof v === 'string' && v.includes(accessor)
                );
                const inParams = Object.values(ds.params).some(
                    (v: any) => typeof v === 'string' && v.includes(accessor)
                );
                expect(inFilter || inParams).toBe(true);
            });
        });
    });

    describe('인증수단 탭 전환 시 필터 초기화 회귀 (#420-4 — 탭 전환 후 필터 미적용 버그)', () => {
        // 배경(라이브 증거): 상태 필터(예: '요청됨') 체크 후 인증수단 탭을 전환하면,
        // 체크박스는 체크된 채로 남는데(=_local.filter 유지) 목록은 필터 없는 전체가 노출된다.
        // 근본 원인: onTabChange 가 navigate(replace:true)로 URL 쿼리를 {provider_id,page} 로
        //   전면 교체(→ 조회는 필터 없는 전체)하지만, _local.filter 는 초기화하지 않는다.
        //   replace:true 경로(G7Core.updateQueryParams)는 컴포넌트 리마운트/init_actions 재실행이
        //   없어 _local.filter 가 그대로 살아남아 "UI 체크 / 조회 미적용" 불일치가 발생한다.
        // 수정: onTabChange 를 sequence[ setState(local, filter 리셋) → navigate ] 로 변경
        //   (admin_settings.json onTabChange 와 동일한 검증된 sequence 패턴).

        const FILTER_RESET_KEYS = [
            'searchType', 'search', 'statuses', 'purposes', 'channels',
            'originTypes', 'dateFrom', 'dateTo', 'sourceType',
            'sourceIdentifier', 'advancedOpen',
        ];

        function findTabChangeAction(): any {
            const tabActions = collectNodes(mainLayout, (n) => n.event === 'onTabChange');
            expect(tabActions.length).toBe(1);
            return tabActions[0];
        }

        it('onTabChange 가 sequence 핸들러로 정의된다 (단일 navigate 아님)', () => {
            const action = findTabChangeAction();
            expect(action.handler).toBe('sequence');
            const subActions = action.actions || action.params?.actions;
            expect(Array.isArray(subActions)).toBe(true);
            expect(subActions.length).toBeGreaterThanOrEqual(2);
        });

        it('sequence 첫 단계가 _local.filter 전체를 기본값으로 초기화한다', () => {
            const action = findTabChangeAction();
            const subActions = action.actions || action.params?.actions;
            const resetStep = subActions.find(
                (a: any) => a.handler === 'setState'
                    && a.params?.target === 'local'
                    && a.params?.filter
            );
            expect(resetStep).toBeTruthy();
            const filter = resetStep.params.filter;
            // 11개 필터 필드 전부 기본값으로 리셋 (체크박스/검색어/날짜/고급 전부)
            FILTER_RESET_KEYS.forEach((k) => {
                expect(filter).toHaveProperty(k);
            });
            // 배열 필드는 빈 배열, 토글은 false 로 초기화
            expect(filter.statuses).toEqual([]);
            expect(filter.purposes).toEqual([]);
            expect(filter.channels).toEqual([]);
            expect(filter.originTypes).toEqual([]);
            expect(filter.advancedOpen).toBe(false);
        });

        it('sequence 마지막 단계가 provider_id 로 navigate (replace + transition_overlay 유지)', () => {
            const action = findTabChangeAction();
            const subActions = action.actions || action.params?.actions;
            const navStep = subActions.find((a: any) => a.handler === 'navigate');
            expect(navStep).toBeTruthy();
            expect(navStep.params.path).toBe('/admin/identity/logs');
            expect(navStep.params.replace).toBe(true);
            // 탭 id($args[0])를 provider_id 쿼리로 전달, '전체'(빈 id)면 빈 쿼리
            expect(navStep.params.query).toContain('$args[0]');
            expect(navStep.params.query).toContain('provider_id');
            // 부분 로딩 오버레이 타깃 유지
            expect(navStep.params.transition_overlay_target).toBe('identity_log_datagrid__body');
        });

        it('리셋(setState) 이 navigate 보다 먼저 실행된다 (순서 정합)', () => {
            const action = findTabChangeAction();
            const subActions = action.actions || action.params?.actions;
            const resetIdx = subActions.findIndex(
                (a: any) => a.handler === 'setState' && a.params?.filter
            );
            const navIdx = subActions.findIndex((a: any) => a.handler === 'navigate');
            expect(resetIdx).toBeGreaterThanOrEqual(0);
            expect(navIdx).toBeGreaterThanOrEqual(0);
            expect(resetIdx).toBeLessThan(navIdx);
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
