/**
 * MP10 쿠폰 관리자 레이아웃 구조 검증 (A14 / A16②③ / A18②)
 *
 * @description
 * - A14: 할인값 Input min 정합 (정률/정액 모두 1)
 * - A16②: 상품검색 query 가 search_keyword + search_field ?? 'all' 사용
 * - A16③: 직접발급 행 액션 + 모달 + user_uuids POST 구조
 * - A18②: 목록 init_actions 가 상세필터 쿼리 존재 시 showDetailFilter 복원
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import benefitSettings from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_form/_partial_benefit_settings.json';
import usageConditions from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_form/_partial_usage_conditions.json';
import couponList from '../../../layouts/admin/admin_ecommerce_promotion_coupon_list.json';
import couponDatagrid from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_partial_coupon_datagrid.json';
import directIssueModal from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_modal_direct_issue.json';
import issueHistoryModal from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_modal_issue_history.json';
import cancelIssueModal from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_modal_cancel_issue_confirm.json';
import basicInfo from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_form/_partial_basic_info.json';

function findAll(node: any, predicate: (n: any) => boolean, acc: any[] = []): any[] {
    if (!node || typeof node !== 'object') return acc;
    if (predicate(node)) acc.push(node);
    for (const key of Object.keys(node)) {
        const val = node[key];
        if (Array.isArray(val)) val.forEach((v) => findAll(v, predicate, acc));
        else if (val && typeof val === 'object') findAll(val, predicate, acc);
    }
    return acc;
}

function jsonText(node: any): string {
    return JSON.stringify(node);
}

describe('A14 — 할인값 min 정합', () => {
    it('discount_value Input 의 min 이 1 (정액 0 미사용)', () => {
        const inputs = findAll(benefitSettings, (n) => n?.name === 'Input' && n?.props?.name === 'discount_value');
        expect(inputs.length).toBeGreaterThan(0);
        expect(inputs[0].props.min).toBe(1);
    });
});

describe('A16② — 상품검색 query 키', () => {
    it('상품검색 apiCall query 가 search_keyword 를 사용하고 keyword 를 쓰지 않는다', () => {
        const text = jsonText(usageConditions);
        // 잘못된 keyword 파라미터가 query 키로 남아있지 않아야 한다
        expect(text).not.toContain('"keyword":"{{_local.productSearchKeyword}}"');
        expect(text).toContain('"search_keyword":"{{_local.productSearchKeyword}}"');
        // search_field 폴백
        expect(text).toContain("_local.productSearchField ?? 'all'");
    });
});

describe('A16③ — 직접발급 행 액션 + 모달', () => {
    it('행 액션 메뉴에 direct_issue 항목이 있다', () => {
        const items = findAll(couponDatagrid, (n) => n?.id === 'direct_issue' && typeof n?.label === 'string');
        expect(items.length).toBeGreaterThan(0);
    });

    it('direct_issue 케이스가 modal_direct_issue 를 연다', () => {
        const text = jsonText(couponDatagrid);
        expect(text).toContain('modal_direct_issue');
        expect(text).toContain('directIssueCoupon');
    });

    it('목록 레이아웃 modals 에 _modal_direct_issue 가 등록되어 있다', () => {
        const text = jsonText(couponList);
        expect(text).toContain('_modal_direct_issue.json');
        expect((couponList as any).initGlobal.directIssueCoupon).toBeNull();
    });

    it('직접발급 모달이 user_uuids 를 POST 하고 issue-direct 엔드포인트를 호출한다', () => {
        const text = jsonText(directIssueModal);
        expect(text).toContain('/issue-direct');
        expect(text).toContain('user_uuids');
        // 회원 검색은 admin users search keyword 사용 (실시간 검색: 입력값 즉시 전달)
        expect(text).toContain('/api/admin/users/search');
        expect(text).toContain('"keyword":"{{$event.target.value}}"');
    });

    it('발급 버튼이 제출 중 스피너 + 텍스트 변경 + 비활성화 처리한다 (다른 모달과 일관)', () => {
        // 발급 버튼: disabled 에 directIssueSubmitting, 스피너 Icon(if), 텍스트 삼항
        const issueBtn = findAll(
            directIssueModal,
            (n) => n?.name === 'Button' && jsonText(n).includes('directIssueSubmitting') && jsonText(n).includes('/issue-direct')
        )[0];
        expect(issueBtn).toBeTruthy();
        expect(issueBtn.props.disabled).toContain('directIssueSubmitting');
        const spinner = findAll(
            issueBtn,
            (n) => n?.name === 'Icon' && n?.props?.name === 'spinner' && n?.if === '{{_global.directIssueSubmitting}}'
        )[0];
        expect(spinner).toBeTruthy();
        // 로딩 중 텍스트 변경 (삼항)
        expect(jsonText(issueBtn.children)).toContain('directIssueSubmitting ?');
    });

    it('발급 onSuccess 가 coupons 데이터그리드를 refetch 하여 발급건수를 갱신한다', () => {
        const text = jsonText(directIssueModal);
        // onSuccess 에서 부모 목록(coupons) refetch — 발급수량 컬럼 갱신
        expect(text).toContain('"dataSourceId":"coupons"');
    });
});

describe('A16③ 회귀 — 데스크탑(PC) DataGrid rowActions 에도 발급내역/직접발급 노출', () => {
    // 배경: 행 액션이 모바일 카드뷰 ActionMenu 에만 있고 데스크탑 DataGrid rowActions 에는
    // edit/delete 만 있어, 관리자(데스크탑)에서 직접발급/발급내역에 도달 불가했던 결함.
    function pcDataGrid(): any {
        return findAll(
            couponDatagrid,
            (n) => n?.name === 'DataGrid' && Array.isArray(n?.props?.rowActions)
        )[0];
    }

    it('PC DataGrid 의 rowActions 에 issue_history 와 direct_issue 가 포함된다', () => {
        const grid = pcDataGrid();
        expect(grid).toBeTruthy();
        const ids = grid.props.rowActions.map((a: any) => a.id);
        expect(ids).toContain('issue_history');
        expect(ids).toContain('direct_issue');
        expect(ids).toContain('edit');
        expect(ids).toContain('delete');
    });

    it('PC DataGrid 의 onRowAction switch 가 issue_history/direct_issue 케이스를 처리한다', () => {
        const grid = pcDataGrid();
        const onRowAction = (grid.actions || []).find((a: any) => a.event === 'onRowAction');
        expect(onRowAction).toBeTruthy();
        expect(onRowAction.cases).toHaveProperty('issue_history');
        expect(onRowAction.cases).toHaveProperty('direct_issue');
        expect(jsonText(onRowAction.cases.direct_issue)).toContain('modal_direct_issue');
        expect(jsonText(onRowAction.cases.issue_history)).toContain('modal_issue_history');
    });
});

describe('발급내역 회귀 — refetch 핸들러/파라미터/그리드 바인딩', () => {
    // 배경: 발급내역 트리거/모달이 등록되지 않은 핸들러 fetchDataSource + 잘못된 param id 를 써서
    // 데이터를 한 번도 못 불러왔고, DataGrid data 바인딩도 한 단계 얕아 렌더 실패하던 결함.
    it('couponIssues 재조회는 refetchDataSource + dataSourceId 를 쓴다 (fetchDataSource/id 금지)', () => {
        const dgText = jsonText(couponDatagrid);
        const modalText = jsonText(issueHistoryModal);
        // 미등록 핸들러 fetchDataSource 가 남아있으면 안 됨
        expect(dgText).not.toContain('"fetchDataSource"');
        expect(modalText).not.toContain('"fetchDataSource"');
        // refetchDataSource 는 dataSourceId 파라미터 사용 (id 키 금지)
        expect(dgText).not.toContain('"id":"couponIssues"');
        expect(modalText).not.toContain('"id":"couponIssues"');
        expect(dgText).toContain('"dataSourceId":"couponIssues"');
        expect(modalText).toContain('"dataSourceId":"couponIssues"');
    });

    it('발급내역 모달 DataGrid 가 couponIssues.data.data 배열을 바인딩한다', () => {
        const grid = findAll(
            issueHistoryModal,
            (n) => n?.name === 'DataGrid' && typeof n?.props?.data === 'string'
        )[0];
        expect(grid).toBeTruthy();
        // 페이지네이션 응답({data:[...],pagination})의 내부 배열을 가리켜야 함
        expect(grid.props.data).toContain('couponIssues?.data?.data');
    });
});

describe('A18② — 상세필터 자동 펼침', () => {
    it('init_actions setState 가 상세필터 쿼리 존재 시 showDetailFilter 를 복원한다', () => {
        const text = jsonText(couponList.init_actions);
        expect(text).toContain('showDetailFilter');
        // 상세필터 대표 쿼리 키들이 표현식에 포함
        expect(text).toContain('query.discount_type');
        expect(text).toContain('query.issue_method');
        expect(text).toContain('query.issue_condition');
        expect(text).toContain('query.created_by');
    });
});

describe('직접발급 모달 — 실시간 검색(디바운스) 전환', () => {
    it('회원 검색 Input 이 debounce + onChange(type:input) 로 실시간 검색한다', () => {
        const input = findAll(
            directIssueModal,
            (n) => n?.name === 'Input' && n?.props?.name === 'directIssueSearchKeyword'
        )[0];
        expect(input).toBeTruthy();
        // 디바운스 적용
        expect(input.props.debounce).toBe(250);
        // onChange(input) 액션이 users/search 를 호출
        const actionText = jsonText(input.actions);
        expect(actionText).toContain('"type":"input"');
        expect(actionText).toContain('/api/admin/users/search');
    });

    it('별도 검색 버튼(search_btn 텍스트)이 제거되었다', () => {
        const text = jsonText(directIssueModal);
        // 검색 버튼 라벨 키가 더 이상 모달에 존재하지 않음
        expect(text).not.toContain('modal.direct_issue.search_btn');
    });
});

describe('발급내역 모달 — 크기/중복/배지/사용처/취소', () => {
    it('모달이 size 대신 width(px) 로 확대된다', () => {
        expect((issueHistoryModal as any).props.width).toBeTruthy();
        // size prop 은 Modal 컴포넌트가 무시하므로 width 로 제어
        expect(String((issueHistoryModal as any).props.width)).toMatch(/px$/);
    });

    it('중복된 subtitle("총 N건의 발급 이력") 텍스트가 제거되었다', () => {
        const text = jsonText(issueHistoryModal);
        expect(text).not.toContain('modal.issue_history.subtitle');
    });

    it('상태 컬럼 배지 래퍼가 items-start 로 셀 100% 점유를 방지한다', () => {
        const statusCol = findAll(
            issueHistoryModal,
            (n) => n?.field === 'status' && Array.isArray(n?.cellChildren)
        )[0];
        expect(statusCol).toBeTruthy();
        expect(jsonText(statusCol.cellChildren)).toContain('items-start');
    });

    it('사용처(order_number) 컬럼이 주문번호 링크로 노출된다', () => {
        const col = findAll(
            issueHistoryModal,
            (n) => n?.field === 'order_number' && Array.isArray(n?.cellChildren)
        )[0];
        expect(col).toBeTruthy();
        const text = jsonText(col.cellChildren);
        expect(text).toContain('row.order_number');
        expect(text).toContain('/admin/ecommerce/orders/');
    });

    it('작업 컬럼의 발급취소 버튼은 is_cancellable 건만 노출하고 확인 모달을 연다', () => {
        const col = findAll(
            issueHistoryModal,
            (n) => n?.field === 'actions' && Array.isArray(n?.cellChildren)
        )[0];
        expect(col).toBeTruthy();
        const cancelBtn = findAll(
            col,
            (n) => n?.name === 'Button' && n?.if === '{{row.is_cancellable}}'
        )[0];
        expect(cancelBtn).toBeTruthy();
        // openModal 은 target 으로 모달 id 를 지정해야 한다 (params.id 는 무시되어 모달 미표시 — modal-usage.md §모달 열기)
        const openModalAction = findAll(
            cancelBtn,
            (n) => n?.handler === 'openModal'
        )[0];
        expect(openModalAction).toBeTruthy();
        expect(openModalAction.target).toBe('modal_cancel_issue_confirm');
        expect(openModalAction.params?.id).toBeUndefined();
    });

    it('목록 modals 에 _modal_cancel_issue_confirm 이 등록되어 있다', () => {
        expect(jsonText(couponList)).toContain('_modal_cancel_issue_confirm.json');
    });
});

describe('발급취소 확인 모달 — DELETE 호출/refetch', () => {
    it('확인 버튼이 issues/{issueId} 로 DELETE 하고 couponIssues 를 refetch 한다', () => {
        const text = jsonText(cancelIssueModal);
        expect(text).toContain('"method":"DELETE"');
        expect(text).toContain('/issues/');
        expect(text).toContain('"dataSourceId":"couponIssues"');
    });
});

describe('발급수량 컬럼 — 숫자 클릭 시 발급내역 모달', () => {
    it('issue_count_formatted 컬럼이 클릭 가능한 링크 외형 버튼으로 발급내역 모달을 연다', () => {
        const col = findAll(
            couponDatagrid,
            (n) => n?.field === 'issue_count_formatted' && Array.isArray(n?.cellChildren)
        )[0];
        expect(col).toBeTruthy();
        const btn = findAll(col, (n) => n?.name === 'Button')[0];
        expect(btn).toBeTruthy();
        // 링크 외형: 파란색 + hover 밑줄 + cursor-pointer
        expect(btn.props.className).toContain('text-blue-600');
        expect(btn.props.className).toContain('hover:underline');
        expect(btn.props.className).toContain('cursor-pointer');
        // 클릭 시 issueHistoryCoupon 세팅 + 발급내역 모달(target) 오픈
        const actionText = jsonText(btn.actions);
        expect(actionText).toContain('issueHistoryCoupon');
        expect(actionText).toContain('"target":"modal_issue_history"');
        expect(actionText).toContain('"dataSourceId":"couponIssues"');
    });
});

describe('쿠폰 폼 — 직접발급 안내 문구', () => {
    it('issue_method=direct 일 때만 안내 Div 가 표시된다', () => {
        const guide = findAll(
            basicInfo,
            (n) => n?.id === 'direct_issue_guide'
        )[0];
        expect(guide).toBeTruthy();
        expect(guide.if).toContain("issue_method === 'direct'");
        expect(jsonText(guide)).toContain('form.hint.direct_issue_guide');
    });
});
