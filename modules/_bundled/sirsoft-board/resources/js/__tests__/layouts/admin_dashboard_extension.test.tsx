/**
 * 관리자 대시보드 모듈 layout extension JSON 구조 검증 테스트
 *
 * @description
 * - quick_menu extension: 게시판/신고 버튼 2개 + 슬롯/모드/priority
 * - community extension: data_sources 4개 + ExtensionBadge + 카드 4종(오늘 배지/추세 그래프/최신글/미처리 신고)
 * - 데이터 바인딩 경로 (board_overview / board_post_graph / board_recent_posts / board_pending_reports)
 * - 다국어 키 (sirsoft-board.admin.dashboard.*)
 * - iteration 변수명 (item_var/index_var)
 * - "전체 보기" 링크 (/admin/boards, /admin/boards/reports)
 */

import { describe, it, expect } from 'vitest';

import quickMenuExt from '../../../extensions/admin_dashboard_quick_menu.json';
import communityExt from '../../../extensions/admin_dashboard_community.json';

/**
 * 컴포넌트 트리에서 특정 ID 를 가진 노드를 재귀적으로 찾습니다.
 * extension JSON 의 components/children 양쪽을 모두 탐색합니다.
 */
function findById(node: any, id: string): any | null {
    if (!node) return null;
    if (Array.isArray(node)) {
        for (const item of node) {
            const found = findById(item, id);
            if (found) return found;
        }
        return null;
    }
    if (typeof node !== 'object') return null;
    if (node.id === id) return node;
    for (const key of ['components', 'children']) {
        if (node[key] && Array.isArray(node[key])) {
            const found = findById(node[key], id);
            if (found) return found;
        }
    }
    return null;
}

/**
 * 컴포넌트 트리를 순회하여 특정 name 을 가진 모든 컴포넌트를 찾습니다.
 */
function findByName(node: any, name: string): any[] {
    const results: any[] = [];
    if (!node) return results;
    if (Array.isArray(node)) {
        for (const item of node) results.push(...findByName(item, name));
        return results;
    }
    if (typeof node !== 'object') return results;
    if (node.name === name) results.push(node);
    for (const key of ['components', 'children']) {
        if (node[key] && Array.isArray(node[key])) {
            results.push(...findByName(node[key], name));
        }
    }
    return results;
}

/**
 * 텍스트 트리에서 모든 $t: 키를 추출합니다.
 */
function extractTKeys(node: any): string[] {
    const keys = new Set<string>();
    const walk = (n: any) => {
        if (!n) return;
        if (typeof n === 'string') {
            const m = n.match(/\$t:([a-zA-Z0-9._-]+)/g);
            if (m) m.forEach(k => keys.add(k.slice(3)));
            return;
        }
        if (Array.isArray(n)) {
            n.forEach(walk);
            return;
        }
        if (typeof n === 'object') {
            for (const k of Object.keys(n)) walk(n[k]);
        }
    };
    walk(node);
    return [...keys].sort();
}

describe('admin_dashboard_quick_menu.json - quick_menu 슬롯 주입', () => {
    it('extension_point / mode / priority 가 올바르다', () => {
        expect(quickMenuExt.extension_point).toBe('admin_dashboard_quick_menu');
        expect(quickMenuExt.mode).toBe('append');
        expect(quickMenuExt.priority).toBe(50);
    });

    it('게시판/신고 버튼 2개를 주입한다', () => {
        expect(quickMenuExt.components).toHaveLength(2);
        const ids = quickMenuExt.components.map((c: any) => c.id);
        expect(ids).toEqual(['qm_boards', 'qm_reports']);
    });

    it('각 버튼은 올바른 href 를 가진다', () => {
        const qmBoards = findById(quickMenuExt as any, 'qm_boards');
        const qmReports = findById(quickMenuExt as any, 'qm_reports');
        expect(qmBoards.props.href).toBe('/admin/boards');
        expect(qmReports.props.href).toBe('/admin/boards/reports');
    });

    it('각 버튼은 Icon + Span 자식을 가진다', () => {
        for (const btnId of ['qm_boards', 'qm_reports']) {
            const btn = findById(quickMenuExt as any, btnId);
            const icons = findByName(btn, 'Icon');
            const spans = findByName(btn, 'Span');
            expect(icons).toHaveLength(1);
            expect(spans).toHaveLength(1);
        }
    });

    it('다국어 키는 sirsoft-board 네임스페이스를 사용한다', () => {
        const tKeys = extractTKeys(quickMenuExt);
        expect(tKeys).toContain('sirsoft-board.admin.dashboard.quick_menu.boards');
        expect(tKeys).toContain('sirsoft-board.admin.dashboard.quick_menu.reports');
    });
});

describe('admin_dashboard_community.json - community 슬롯 주입', () => {
    it('extension_point / mode / priority 가 올바르다', () => {
        expect(communityExt.extension_point).toBe('admin_dashboard_community');
        expect(communityExt.mode).toBe('append');
        expect(communityExt.priority).toBe(100);
    });

    it('data_sources 4개(overview/post-graph/recent-posts/pending-reports)를 정의한다', () => {
        expect(communityExt.data_sources).toHaveLength(4);
        const ids = communityExt.data_sources.map((d: any) => d.id);
        expect(ids).toEqual([
            'board_overview',
            'board_post_graph',
            'board_recent_posts',
            'board_pending_reports',
        ]);
    });

    it('각 data_source 는 모듈 API 엔드포인트를 호출한다', () => {
        const endpoints = communityExt.data_sources.map((d: any) => d.endpoint);
        expect(endpoints).toEqual([
            '/api/modules/sirsoft-board/admin/dashboard/overview',
            '/api/modules/sirsoft-board/admin/dashboard/post-graph',
            '/api/modules/sirsoft-board/admin/dashboard/recent-posts',
            '/api/modules/sirsoft-board/admin/dashboard/pending-reports',
        ]);
    });

    it('각 data_source 는 auth_required=true 와 fallback 을 가진다', () => {
        for (const ds of communityExt.data_sources) {
            expect(ds.auth_required).toBe(true);
            expect(ds.method).toBe('GET');
            expect(ds.fallback).toBeDefined();
        }
    });

    it('community_section_wrapper 안에 ExtensionBadge + 카드 4종을 주입한다', () => {
        const wrapper = findById(communityExt as any, 'community_section_wrapper');
        expect(wrapper).not.toBeNull();
        const badges = findByName(wrapper, 'ExtensionBadge');
        expect(badges).toHaveLength(1);
        expect(badges[0].props.type).toBe('module');
        expect(badges[0].props.identifier).toBe('sirsoft-board');
    });

    it('카드 4종(오늘 배지/추세 그래프/최신글/미처리 신고) 이 존재한다', () => {
        expect(findById(communityExt as any, 'post_graph_today_summary')).not.toBeNull();
        expect(findById(communityExt as any, 'post_graph_card')).not.toBeNull();
        expect(findById(communityExt as any, 'latest_posts_card')).not.toBeNull();
        expect(findById(communityExt as any, 'report_management_card')).not.toBeNull();
    });

    it('오늘 배지는 board_overview 응답을 바인딩한다', () => {
        const today = findById(communityExt as any, 'today_new_posts_badge');
        expect(today.text).toContain('board_overview?.data?.today_posts');
        const comments = findById(communityExt as any, 'today_new_comments_badge');
        expect(comments.text).toContain('board_overview?.data?.today_comments');
    });

    it('총 게시글/댓글 값은 board_post_graph 응답을 바인딩한다', () => {
        const totalPosts = findById(communityExt as any, 'total_posts_value');
        expect(totalPosts.text).toContain('board_post_graph?.data?.total_posts');
        const totalComments = findById(communityExt as any, 'total_comments_value');
        expect(totalComments.text).toContain('board_post_graph?.data?.total_comments');
    });

    it('변화율은 데이터 부족 시 — 폴백을 가진다', () => {
        const postsChange = findById(communityExt as any, 'total_posts_change');
        expect(postsChange.text).toContain("'—'");
        expect(postsChange.text).toContain('posts_change');
        const commentsChange = findById(communityExt as any, 'total_comments_change');
        expect(commentsChange.text).toContain("'—'");
        expect(commentsChange.text).toContain('comments_change');
    });

    it('BarChart 는 board_post_graph.days 를 변환하여 labels/datasets 을 생성한다', () => {
        const chart = findById(communityExt as any, 'post_graph_chart');
        expect(chart.name).toBe('BarChart');
        expect(chart.props.labels).toContain('board_post_graph?.data?.days');
        expect(chart.props.datasets).toContain('post_count');
        expect(chart.props.datasets).toContain('comment_count');
    });

    it('최신 게시글 항목(latest_post_item)에 iteration 이 정의된다', () => {
        // iteration 은 wrapper(latest_posts_list) 가 아니라 item(latest_post_item) 에 둔다.
        // wrapper 에 iteration 을 두면 wrapper 가 각 행마다 통째로 복제되어 divide-y > * 가
        // 형제 관계를 잃고 구분선이 사라진다.
        // Controller 가 ResponseHelper::moduleSuccess(MODULE, key, RecentPostResource::collection($posts)) 로 호출하면
        // Laravel 은 ResourceCollection 을 평면 배열로 toArray() 하여 data 키 아래 그대로 넣는다.
        // 따라서 실제 응답은 { success, message, data: [...] } 이고, 바인딩은 data.data 가 아닌 data 다.
        const list = findById(communityExt as any, 'latest_posts_list');
        expect(list.iteration).toBeUndefined();
        const item = findById(communityExt as any, 'latest_post_item');
        expect(item.iteration).toBeDefined();
        expect(item.iteration.source).toBe('board_recent_posts?.data');
        expect(item.iteration.item_var).toBe('post');
        expect(item.iteration.index_var).toBe('i');
    });

    it('미처리 신고 항목(report_item)에 iteration 이 정의된다', () => {
        const list = findById(communityExt as any, 'report_management_list');
        expect(list.iteration).toBeUndefined();
        const item = findById(communityExt as any, 'report_item');
        expect(item.iteration).toBeDefined();
        expect(item.iteration.source).toBe('board_pending_reports?.data?.items');
        expect(item.iteration.item_var).toBe('report');
        expect(item.iteration.index_var).toBe('i');
    });

    it('빈 상태 메시지는 length === 0 조건으로 노출된다', () => {
        const postsEmpty = findById(communityExt as any, 'latest_posts_empty');
        expect(postsEmpty.if).toBe('{{(board_recent_posts?.data ?? []).length === 0}}');
        const reportsEmpty = findById(communityExt as any, 'report_management_empty');
        expect(reportsEmpty.if).toBe('{{(board_pending_reports?.data?.items ?? []).length === 0}}');
    });

    it('"전체 보기" 링크가 정정된 경로 + 도착지 명칭(게시판 관리 / 신고 관리)을 사용한다', () => {
        const postsViewAll = findById(communityExt as any, 'latest_posts_view_all');
        expect(postsViewAll.props.href).toBe('/admin/boards');
        expect(postsViewAll.text).toBe('$t:sirsoft-board.admin.dashboard.community.go_to_boards');
        const reportsViewAll = findById(communityExt as any, 'report_management_view_all');
        expect(reportsViewAll.props.href).toBe('/admin/boards/reports');
        expect(reportsViewAll.text).toBe('$t:sirsoft-board.admin.dashboard.community.go_to_reports');
    });

    it('최신 게시글 항목은 클릭 시 게시글 상세로 navigate 한다', () => {
        const item = findById(communityExt as any, 'latest_post_item');
        expect(Array.isArray(item.actions)).toBe(true);
        const click = item.actions.find((a: any) => a.type === 'click' && a.handler === 'navigate');
        expect(click).toBeDefined();
        expect(click.params.path).toBe('/admin/board/{{post?.board_slug}}/post/{{post?.id}}');
    });

    it('신고 항목은 클릭 시 신고 상세로 navigate 한다', () => {
        const item = findById(communityExt as any, 'report_item');
        expect(Array.isArray(item.actions)).toBe(true);
        const click = item.actions.find((a: any) => a.type === 'click' && a.handler === 'navigate');
        expect(click).toBeDefined();
        expect(click.params.path).toBe('/admin/boards/reports/{{report?.id}}');
    });

    it('신고 카드는 신고 처리상태 배지(report_status_badge)를 노출하고 신고현황과 동일한 색상으로 분기한다', () => {
        // 카드는 Pending/Review 신고만 표시한다 (ReportRepository::getPendingAcrossBoards).
        // 배지는 신고 대상(게시글/댓글)의 상태가 아니라 신고 건 자체의 처리상태(status)를 보여준다.
        // 색상은 신고현황 페이지(admin_board_reports_index 의 status 컬럼) 와 동일한 매핑을 사용한다.
        // PO 시각 검증 피드백: "대상 상태가 아닌 처리상태가 보여져야 한다" + "처리상태는 게시판 신고현황과 동일한 색상으로".
        const statusBadge = findById(communityExt as any, 'report_status_badge');
        expect(statusBadge).not.toBeNull();
        expect(statusBadge.if).toBe('{{!!report?.status_label}}');
        expect(statusBadge.text).toBe('{{report?.status_label ?? \'\'}}');

        const cls = statusBadge.props.className;
        // 신고현황 페이지와 동일한 5색 + 기본 회색 매핑
        expect(cls).toContain("bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200"); // pending
        expect(cls).toContain("bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200"); // review
        expect(cls).toContain("bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200"); // rejected
        expect(cls).toContain("bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200"); // suspended
        expect(cls).toContain("bg-gray-600 text-white dark:bg-gray-600 dark:text-gray-100"); // deleted

        // 기존 대상상태(target_status) 분기 흔적이 남아 있지 않아야 한다 (회귀 가드)
        expect(cls).not.toContain('target_status');
        expect(cls).not.toContain('published');
        expect(cls).not.toContain('blinded');
    });

    it('신고 카드의 타입 배지(report_target_type_badge)는 댓글/게시글 색상이 다르다', () => {
        const typeBadge = findById(communityExt as any, 'report_target_type_badge');
        expect(typeBadge.props.className).toContain("comment");
        expect(typeBadge.props.className).toContain('bg-purple-900');
        expect(typeBadge.props.className).toContain('bg-blue-900');
    });

    it('3 카드(post_graph_card / latest_posts_card / report_management_card) 가 h-full 로 동일 높이를 보장한다', () => {
        for (const id of ['post_graph_card', 'latest_posts_card', 'report_management_card']) {
            const card = findById(communityExt as any, id);
            expect(card.props.className, `${id} 에 h-full 누락`).toContain('h-full');
        }
    });

    it('갱신 시각 캡션은 updated_at_display 가 있을 때만 노출되고 백엔드 포맷 결과를 그대로 표시한다', () => {
        // 캡션은 게시판 환경설정(display.date_display_format) 을 백엔드에서 적용한 결과(updated_at_display) 를 그대로 표시한다.
        // 프론트에서 slice/format 가공을 하지 않는다 — 게시글 created_at 표시 규칙과 동일 컨벤션 유지.
        const caption = findById(communityExt as any, 'post_graph_updated_at_caption');
        expect(caption).not.toBeNull();
        expect(caption.if).toBe('{{!!board_post_graph?.data?.updated_at_display}}');
        expect(caption.text).toBe(
            "$t:sirsoft-board.admin.dashboard.community.updated_at|time={{board_post_graph?.data?.updated_at_display ?? ''}}",
        );
    });

    it('모든 다국어 키는 sirsoft-board 네임스페이스 또는 코어 공용 키를 사용한다', () => {
        const tKeys = extractTKeys(communityExt);
        for (const key of tKeys) {
            const ok =
                key.startsWith('sirsoft-board.') ||
                key === 'admin.dashboard.stats.today';
            expect(ok, `다국어 키 형식 위반: ${key}`).toBe(true);
        }
    });
});
