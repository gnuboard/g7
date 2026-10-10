/**
 * @file mypage-board-reports.test.tsx
 * @description 마이페이지 「내 신고」 서브탭 레이아웃 회귀 (이슈 #525 WP-E)
 *
 * 이 화면이 지키는 계약:
 *  - 취소는 **대기 중인 신고만** 된다(`can_cancel`). 운영자가 이미 검토에 들어갔거나 판정이
 *    끝난 건은 신고자가 되돌릴 수 없고, 그 사실은 상태 배지가 말한다.
 *  - 취소해도 신고 대상(글·댓글)은 그대로다 — 「신고를 넣은 사실」만 없앤다.
 *  - 취소 성공은 목록과 통계 카드를 함께 다시 받는다.
 *
 * 저작본(.g7.html)의 기계 산출물인 `.json` 을 직접 읽는다.
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, it, expect } from 'vitest';

import boardLayout from '../../layouts/mypage/board.json';
import listPartial from '../../layouts/partials/mypage/board/_list.json';
import myReports from '../../layouts/partials/mypage/board/_my_reports.json';
import cancelModal from '../../layouts/partials/mypage/board/_modal_report_cancel.json';

const listJson = JSON.stringify(listPartial);
const json = JSON.stringify(myReports);
const modalJson = JSON.stringify(cancelModal);

describe('내 신고 — 데이터소스와 탭 진입', () => {
    // @scenario case=subtab_lists_reports_with_status_badges
    // @effects mypage_reports_datasource_passes_filters
    it('데이터소스가 게시판·상태·대상·검색·정렬을 전부 싣는다', () => {
        const ds = (boardLayout as any).data_sources.find((d: any) => d.id === 'myReports');

        expect(ds).toBeTruthy();
        expect(ds.endpoint).toContain('/api/modules/sirsoft-board/me/reports');

        for (const key of ['page', 'board_slug', 'search', 'status', 'target_type', 'sort']) {
            expect(ds.endpoint, key).toContain(`${key}=`);
        }

        expect(ds.auth_required).toBe(true);
        // 네 번째 탭이라 열 때 처음 받는다.
        expect(ds.auto_fetch).toBe(false);
    });

    // @scenario case=subtab_lists_reports_with_status_badges
    // @effects mypage_reports_datasource_passes_filters
    it('탭 버튼이 쪽수를 되돌리고 다시 받는다', () => {
        expect(/mypage-subtab-my-reports/.test(listJson)).toBe(true);
        expect(/"boardActivitySubTab":\s*"my-reports"/.test(listJson)).toBe(true);
        expect(/"myReportsPage":\s*1/.test(listJson)).toBe(true);
        expect(/"dataSourceId":\s*"myReports"/.test(listJson)).toBe(true);
    });

    // @scenario case=subtab_lists_reports_with_status_badges
    // @effects mypage_reports_datasource_passes_filters
    it('상태·대상 필터가 화면에 있다', () => {
        expect(/my-reports-status-filter/.test(json)).toBe(true);
        expect(/my-reports-target-filter/.test(json)).toBe(true);
    });

    // @scenario case=subtab_lists_reports_with_status_badges
    // @effects mypage_reports_datasource_passes_filters
    it('초기화가 걸어 둔 조건을 한꺼번에 되돌린다', () => {
        expect(/"myReportsStatus":\s*""/.test(json)).toBe(true);
        expect(/"myReportsTargetType":\s*""/.test(json)).toBe(true);
        expect(/"myReportsBoard":\s*""/.test(json)).toBe(true);
        expect(/"myReportsSearchField":\s*"title_content"/.test(json)).toBe(true);
    });

    // @scenario case=subtab_lists_reports_with_status_badges
    // @effects mypage_reports_search_field_matches_backend
    it('검색 대상 선택이 있고, 보내는 값이 서버가 받는 목록 안에 있다', () => {
        // 모집단은 화면이 아니라 **백엔드 FormRequest 선언**에서 파생한다.
        // 화면을 베끼면 화면이 틀려도 통과한다(이 파일의 상태 필터가 그렇게 4라운드를 통과했다).
        const requestSrc = readFileSync(
            resolve(
                __dirname,
                '../../../../../modules/_bundled/sirsoft-board/src/Http/Requests/User/IndexMyReportRequest.php',
            ),
            'utf-8',
        );
        const declared = (
            requestSrc.match(/public const SEARCH_FIELDS\s*=\s*\[([^\]]+)\]/)?.[1] ?? ''
        )
            .split(',')
            .map((s) => s.trim().replace(/^'|'$/g, ''))
            .filter(Boolean);

        // 파생이 비면 아래 단언이 공회전한다 — 비었는지 자체를 먼저 잰다.
        expect(declared.length).toBeGreaterThan(0);

        // 데이터소스가 파라미터를 실제로 보내야 서버가 읽는다. 셀렉트만 있고 안 실으면 조용히 무시된다.
        const ds = (boardLayout as any).data_sources.find((d: any) => d.id === 'myReports');
        expect(ds.endpoint).toContain('search_field=');

        const sent = [...json.matchAll(/"myReportsSearchField":\s*"([a-z_]+)"/g)].map((m) => m[1]);
        const options = [...json.matchAll(/"value":\s*"([a-z_]+)"/g)]
            .map((m) => m[1])
            .filter((v) => declared.includes(v));

        expect(options.length).toBeGreaterThan(0);
        for (const value of [...sent, ...options]) {
            expect(declared, `화면이 보내는 '${value}' 가 IndexMyReportRequest 에 없다`).toContain(
                value,
            );
        }
    });
});

describe('내 신고 — 상태 표시', () => {
    // @scenario case=subtab_lists_reports_with_status_badges
    // @effects mypage_reports_status_badge_has_four_branches
    it('화면이 보내는 상태 값이 서버 ReportStatus 에 실재한다', () => {
        // 모집단은 손으로 적지 않는다 — 백엔드 enum 선언에서 파생한다.
        // 화면이 쓰는 값을 여기 베껴 적으면 양쪽이 같이 틀려도 green 이 된다(2026-09-16 5차 실측).
        const enumSrc = readFileSync(
            resolve(
                __dirname,
                '../../../../../modules/_bundled/sirsoft-board/src/Enums/ReportStatus.php',
            ),
            'utf-8',
        );
        const serverStatuses = [...enumSrc.matchAll(/case\s+\w+\s*=\s*'([a-z_]+)'/g)].map((m) => m[1]);
        expect(serverStatuses.length).toBeGreaterThan(0); // 파생이 비면 아래 단언이 공회전한다

        // 필터 셀렉트가 실제로 보내는 값 — 서버가 Rule::in 으로 거르므로 없는 값이면 422 다.
        const sentValues = [...json.matchAll(/"value":\s*"([a-z_]+)"[^}]*?reports\.status\./g)].map(
            (m) => m[1],
        );
        const optionValues =
            sentValues.length > 0
                ? sentValues
                : [...json.matchAll(/reports\.status\.([a-z_]+)/g)].map((m) => m[1]);
        expect(optionValues.length).toBeGreaterThan(0);

        for (const value of optionValues) {
            expect(serverStatuses, `화면이 보내는 '${value}' 가 ReportStatus 에 없다`).toContain(value);
        }

        // 배지 색 분기도 서버에 있는 값으로만 갈린다 — 하나로 뭉치면 처리 상태를 눈으로 구분할 수 없다.
        const branchValues = [...json.matchAll(/report\.status === '([a-z_]+)'/g)].map((m) => m[1]);
        expect(branchValues.length).toBeGreaterThanOrEqual(3);
        for (const value of branchValues) {
            expect(serverStatuses, `배지 분기 '${value}' 가 ReportStatus 에 없다`).toContain(value);
        }
    });

    // @scenario case=subtab_lists_reports_with_status_badges
    // @effects mypage_reports_status_badge_has_four_branches
    it('상태 문구는 서버가 준 라벨을 그대로 쓴다', () => {
        // 화면이 따로 매핑하면 서버 판정과 어긋난다.
        expect(/report\.status_label/.test(json)).toBe(true);
        expect(/my-report-status-/.test(json)).toBe(true);
    });

    // @scenario case=subtab_lists_reports_with_status_badges
    // @effects mypage_reports_status_badge_has_four_branches
    it('대상 구분과 게시판을 배지로 낸다', () => {
        expect(/report\.target_type === 'comment'/.test(json)).toBe(true);
        expect(/report\.target_type_label/.test(json)).toBe(true);
        expect(/report\.board_name/.test(json)).toBe(true);
    });

    // @scenario case=subtab_lists_reports_with_status_badges
    // @effects mypage_reports_deleted_target_is_toned_down
    it('신고 대상이 사라졌으면 톤을 낮추고 배지로 말한다', () => {
        expect(/report\.target_deleted/.test(json)).toBe(true);
        expect(/reports\.target_deleted/.test(json)).toBe(true);
        expect(/opacity-60/.test(json)).toBe(true);
    });

    // @scenario case=subtab_lists_reports_with_status_badges
    // @effects mypage_reports_deleted_target_is_toned_down
    it('살아 있는 대상만 눌러서 원글로 간다', () => {
        expect(/\/board\/\{\{report\.board_slug\}\}\/\{\{report\.post_id\}\}/.test(json)).toBe(true);
        // 갈 곳이 없는 행은 이동을 걸지 않는다.
        expect(/!report\.target_deleted/.test(json)).toBe(true);
    });

    // 글 신고의 스냅샷에는 제목과 본문이 함께 있다 — 본문 요약을 먼저 쓰면 형제 탭(내 반응·내 글)과 달리
    // 글 제목이 한 번도 보이지 않는다(실측 2026-10-03). 댓글 스냅샷은 제목이 없어 본문 요약으로 떨어진다.
    // @scenario case=subtab_lists_reports_with_status_badges
    // @effects mypage_reports_post_row_leads_with_title
    it('글 신고는 제목을, 댓글 신고는 본문 요약을 머리줄로 낸다', () => {
        expect(json.includes('report.title ?? report.excerpt')).toBe(true);
        expect(json.includes('report.excerpt ?? report.title')).toBe(false);
        expect(modalJson.includes('report.excerpt ?? report.title')).toBe(false);
        // 제목과 요약은 회원이 쓴 글이다 — 안에 `$t:` 가 있어도 번역하지 않도록 raw 로 그린다.
        expect(json.includes('{{raw:report.title ?? report.excerpt ?? \'\'}}')).toBe(true);
        expect(modalJson.includes('{{raw:_global.reportCancelModal?.title}}')).toBe(true);
    });

    // 취소 창은 목록이 담아 둔 대상을 보여 준다 — 담기만 하고 쓰지 않던 값이었다(실측 2026-10-03).
    // @scenario case=subtab_cancel_only_when_pending
    // @effects mypage_reports_post_row_leads_with_title
    it('취소 창이 거둘 신고의 대상을 보여 준다', () => {
        // 목록의 취소 버튼이 창 상태에 번호와 함께 대상 제목(없으면 본문 요약)을 담는다.
        expect(json.includes('"reportCancelModal":{"id":"{{report.id}}","title":"{{report.title ?? report.excerpt ?? \'\'}}"}')).toBe(true);
        expect(modalJson.includes('report-cancel-target')).toBe(true);
        expect(modalJson.includes('_global.reportCancelModal?.title')).toBe(true);
    });

    // @scenario case=subtab_lists_reports_with_status_badges
    // @effects mypage_reports_status_badge_has_four_branches
    it('내가 고른 사유와 덧붙인 설명을 함께 낸다', () => {
        expect(/report\.reason_type_label/.test(json)).toBe(true);
        expect(/report\.reason_detail/.test(json)).toBe(true);
    });

    // @scenario case=subtab_lists_reports_with_status_badges
    // @effects mypage_reports_datasource_passes_filters
    it('신고가 없으면 빈 상태를 낸다', () => {
        expect(/my-reports-empty/.test(json)).toBe(true);
        expect(/reports\.empty/.test(json)).toBe(true);
    });
});

describe('내 신고 — 취소', () => {
    // @scenario case=subtab_cancel_only_when_pending
    // @effects mypage_reports_cancel_button_requires_can_cancel
    it('취소 버튼은 can_cancel 인 건에만 뜬다', () => {
        // 검토가 시작된 뒤에는 신고자가 되돌릴 수 없다.
        expect(/report\.can_cancel/.test(json)).toBe(true);
        expect(/my-report-cancel-/.test(json)).toBe(true);
    });

    // @scenario case=subtab_cancel_only_when_pending
    // @effects mypage_reports_cancel_modal_calls_delete_and_refetches
    it('취소 창이 그 신고 로그 하나만 DELETE 한다', () => {
        expect(/\/api\/modules\/sirsoft-board\/me\/reports\/\{\{_global\.reportCancelModal\?\.id\}\}/.test(modalJson)).toBe(true);
        expect(/"DELETE"/.test(modalJson)).toBe(true);
        expect(/report-cancel-confirm/.test(modalJson)).toBe(true);
    });

    // @scenario case=subtab_cancel_only_when_pending
    // @effects mypage_reports_cancel_modal_calls_delete_and_refetches
    it('성공하면 목록과 통계 카드를 함께 다시 받는다', () => {
        expect(/"myReports"/.test(modalJson)).toBe(true);
        expect(/"myActivityStats"/.test(modalJson)).toBe(true);
    });

    // @scenario case=subtab_cancel_only_when_pending
    // @effects mypage_reports_cancel_modal_calls_delete_and_refetches
    it('취소해도 신고 대상은 남는다는 사실을 창이 말한다', () => {
        expect(/reports\.cancel_notice/.test(modalJson)).toBe(true);
        expect(/reports\.cancel_confirm/.test(modalJson)).toBe(true);
    });

    // @scenario case=subtab_cancel_only_when_pending
    // @effects mypage_reports_cancel_modal_calls_delete_and_refetches
    it('닫을 때 대상 정보를 비운다 — 여러 행이 이 창을 돌려 쓴다', () => {
        expect(/"reportCancelModal":\s*null/.test(modalJson)).toBe(true);
    });
});

describe('내 신고 — 검색 0건 안내', () => {
    // @scenario case=subtab_lists_reports_with_status_badges
    // @effects mypage_filtered_empty_says_no_results
    it('검색·게시판·상태·대상 중 하나라도 걸려 있으면 검색 결과 없음을 낸다', () => {
        expect(json.includes('!!_global.myReportsSearchApplied || !!_global.myReportsBoard || !!_global.myReportsStatus || !!_global.myReportsTargetType')).toBe(true);
        expect(json.includes('$t:board.no_search_results')).toBe(true);
    });
});

/** 레이아웃 트리에서 조건에 맞는 노드를 모두 모은다. */
function collectReportNodes(node: any, pred: (n: any) => boolean, out: any[] = []): any[] {
    if (!node || typeof node !== 'object') return out;
    if (Array.isArray(node)) {
        node.forEach((child) => collectReportNodes(child, pred, out));
        return out;
    }
    if (pred(node)) out.push(node);
    for (const value of Object.values(node)) collectReportNodes(value, pred, out);
    return out;
}

describe('내 신고 — K2 실측(2026-10-06) 후속', () => {
    // @scenario case=subtab_lists_reports_with_status_badges
    // @effects mypage_reports_search_input_shrinks_on_narrow_screen
    it('검색 입력칸이 줄어들 수 있어 좁은 화면(390px)에서 검색 줄이 칸 밖으로 밀리지 않는다', () => {
        // 입력칸의 고유 최소 폭 때문에 「검색」·「초기화」가 화면 밖(오른쪽 449px)으로 밀리고 페이지가 가로로 스크롤됐다.
        const inputs = collectReportNodes(myReports, (n) => n?.name === 'Input'
            && n?.props?.placeholder === '$t:mypage.board_activities.search_placeholder');

        expect(inputs.length).toBe(1);
        expect(inputs[0].props.className).toContain('min-w-0');
    });

    // @scenario case=subtab_cancel_only_when_pending
    // @effects mypage_reports_cancel_confirm_shows_pointer
    it('신고 취소 창의 「신고 취소」 버튼이 형제 「취소」 처럼 손가락 커서를 쓴다', () => {
        const confirm = collectReportNodes(cancelModal, (n) => n?.props?.['data-testid'] === 'report-cancel-confirm');

        expect(confirm.length).toBe(1);
        expect(confirm[0].props.className).toContain('cursor-pointer');
    });
});
