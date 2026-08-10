/**
 * @file admin-list-context-round-trip.test.ts
 * @description 관리자 코어 목록(회원/역할/로그/확장) 컨텍스트 왕복 시 URL 목록 상태 보존 회귀 (#75)
 *
 * 결함: 회원·역할·활동이력·알림로그·모듈/플러그인 목록에서 상세·폼으로 들어갔다 돌아오면
 *   navigate 가 `mergeQuery` 없이 실행되어 page/검색/필터가 URL 에서 사라졌다.
 *
 * 검증 방식 2단:
 *   1. 실 레이아웃 JSON 전수 분석 — 클러스터 내 navigate 가 모두 `mergeQuery: true` 인지
 *   2. 실 액션을 엔진에 태워 실행(`triggerAction`) — 선언이 실제 목적지 URL 로 이어지는지
 *   선언만 검사하면 "JSON 에 리터럴이 적혀 있다" 까지만 고정되고 병합 결과는 미검증으로 남는다.
 *
 * @scenario leg=row_click|detail_return|form_cancel|filter_apply
 * @effects navigation_preserves_list_query
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach } from 'vitest';
import fs from 'fs';
import path from 'path';

import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

const LAYOUTS_ROOT = path.resolve(__dirname, '../../../layouts');

/** 이 템플릿이 소유한 페이지네이션 목록 라우트 */
const LIST_ROUTES = [
    '/admin/users',
    '/admin/roles',
    '/admin/activity-logs',
    '/admin/identity/logs',
    '/admin/notification-logs',
    '/admin/notification-templates',
    '/admin/modules',
    '/admin/plugins',
    '/admin/schedules',
    '/admin/templates/admin',
    '/admin/templates/user',
];

/** 목록 클러스터에 속하는 레이아웃 파일 */
const CLUSTER_FILES = [
    'admin_user_list.json',
    'admin_user_detail.json',
    'admin_user_form.json',
    'admin_role_list.json',
    'admin_role_form.json',
    'admin_activity_log_list.json',
    'admin_identity_logs.json',
    'admin_module_list.json',
    'admin_notification_log_list.json',
    'admin_plugin_list.json',
    'partials/admin_activity_log_list/_partial_datagrid.json',
    'partials/admin_activity_log_list/_partial_filter.json',
    'partials/admin_identity_logs/_partial_filter.json',
    'partials/admin_notification_log_list/_partial_datagrid.json',
    'partials/admin_notification_log_list/_partial_filter.json',
    'partials/admin_schedule_list/_tab_schedules.json',
    // 템플릿 목록은 LIST_ROUTES 에 있으면서 클러스터 파일 목록에서 빠져 있었다.
    // 그 탓에 검색 submit 이 표시 개수를 떨구는 회귀가 검사망을 그대로 통과했다 (#75).
    'admin_template_list.json',
    'partials/admin_template_list/_tab_admin.json',
    'partials/admin_template_list/_tab_user.json',
];

type NavAction = {
    handler: string;
    comment?: string;
    params: { path?: string; mergeQuery?: unknown; query?: unknown };
};

/**
 * JSON 트리에서 navigate/replaceUrl 액션을 모두 수집합니다.
 *
 * @param node 순회 시작 노드
 * @returns 액션 목록
 */
function collectNavActions(node: unknown): NavAction[] {
    const out: NavAction[] = [];
    const walk = (n: unknown): void => {
        if (Array.isArray(n)) {
            n.forEach(walk);
            return;
        }
        if (!n || typeof n !== 'object') return;
        const obj = n as Record<string, unknown>;
        if (
            (obj.handler === 'navigate' || obj.handler === 'replaceUrl') &&
            obj.params &&
            typeof obj.params === 'object'
        ) {
            out.push(obj as unknown as NavAction);
        }
        Object.values(obj).forEach(walk);
    };
    walk(node);
    return out;
}

/**
 * 목적지 경로를 비교용 표준형(`{{expr}}` → `*`)으로 바꿉니다.
 *
 * @param raw 원본 경로
 * @returns 표준형 경로 (판정 불가 시 null)
 */
function normalizePath(raw: unknown): string | null {
    if (typeof raw !== 'string' || raw === '') return null;
    const p = raw.replace(/\{\{[^}]*\}\}/g, '*').split('?')[0].replace(/\/+$/, '');
    return p.startsWith('/') ? p : null;
}

/**
 * 경로가 목록 라우트와 같은 클러스터인지 판정합니다.
 *
 * @param dest 표준형 목적지
 * @param list 표준형 목록 라우트
 * @returns 같은 클러스터 여부
 */
function inFamily(dest: string, list: string): boolean {
    return dest === list || dest.startsWith(`${list}/`);
}

/**
 * 레이아웃 파일을 읽어 파싱합니다.
 *
 * @param rel 레이아웃 루트 기준 상대 경로
 * @returns 파싱된 JSON
 */
function readLayout(rel: string): unknown {
    return JSON.parse(fs.readFileSync(path.join(LAYOUTS_ROOT, rel), 'utf8'));
}

describe('#75 관리자 코어 목록 컨텍스트 왕복 — mergeQuery 전수', () => {
    it.each(CLUSTER_FILES)('%s 의 클러스터 내 navigate 는 모두 mergeQuery: true', (rel) => {
        const offenders = collectNavActions(readLayout(rel))
            .filter((a) => {
                if (typeof a.comment === 'string' && a.comment.includes('audit:allow')) return false;
                const dest = normalizePath(a.params.path);
                if (!dest) return false;
                if (!LIST_ROUTES.some((l) => inFamily(dest, l))) return false;
                return a.params.mergeQuery !== true;
            })
            .map((a) => `${a.params.path} (mergeQuery=${JSON.stringify(a.params.mergeQuery)})`);

        expect(offenders, `목록 상태를 떨구는 navigate: \n  ${offenders.join('\n  ')}`).toEqual([]);
    });

    // 회원 상세(admin_user_detail)에는 '목록' 복귀 navigate 가 없다 — 브라우저 뒤로가기로 돌아간다.
    // 상세에서 '수정' 으로 넘어가는 leg 은 위 전수 검사가 덮는다.
    it("회원 등록/수정 폼의 '목록' 복귀가 목록 상태를 복원한다", () => {
        const backs = collectNavActions(readLayout('admin_user_form.json')).filter(
            (a) => a.params.path === '/admin/users'
        );
        expect(backs.length, "admin_user_form.json 에 '/admin/users' 복귀 navigate 없음").toBeGreaterThan(0);
        backs.forEach((a) => expect(a.params.mergeQuery).toBe(true));
    });
});

// ---------------------------------------------------------------------------
// 실행 검증 — 실 액션을 엔진에 태워 목적지 URL 을 확인한다
// ---------------------------------------------------------------------------

/**
 * 실 JSON 액션을 현재 URL 상태에서 실행하고 목적지를 돌려줍니다.
 *
 * @param action 실 레이아웃 JSON 에서 뽑은 navigate 액션
 * @returns navigate 목적지 경로
 */
async function runNav(action: NavAction): Promise<string> {
    const utils = createLayoutTest(
        {
            version: '1.0.0',
            layout_name: 'nav_action_probe',
            data_sources: [],
            components: [{ type: 'basic', name: 'Div', props: { 'data-testid': 'probe' } }],
        } as never,
        { componentRegistry: createMockComponentRegistryWithBasics() as never, locale: 'ko' }
    );
    await utils.render();
    await utils.triggerAction({ type: 'click', ...action } as never);
    const dest = utils.getNavigationHistory()[0];
    utils.cleanup();
    return dest;
}

/**
 * 목적지 URL 의 쿼리스트링을 파싱합니다.
 *
 * @param dest navigate 목적지
 * @returns URLSearchParams
 */
function queryOf(dest: string): URLSearchParams {
    return new URLSearchParams(dest.includes('?') ? dest.slice(dest.indexOf('?')) : '');
}

describe('#75 회원/역할 목록 컨텍스트 왕복 — 실행 결과 URL', () => {
    afterEach(() => {
        window.history.replaceState({}, '', '/');
    });

    it('회원 목록 행의 상세 이동이 검색/페이지를 상세 URL 로 나른다', async () => {
        window.history.replaceState({}, '', '/admin/users?page=3&search=홍길동&per_page=20');

        const action = collectNavActions(readLayout('admin_user_list.json')).find((a) =>
            /^\/admin\/users\/\{\{/.test(String(a.params.path ?? ''))
        );
        expect(action, '회원 목록 행 이동 navigate 를 찾지 못했습니다.').toBeTruthy();

        const q = queryOf(await runNav(action as NavAction));

        expect(q.get('page')).toBe('3');
        expect(q.get('search')).toBe('홍길동');
        expect(q.get('per_page')).toBe('20');
    });

    // 검색은 조건을 바꾸는 액션이므로 page 를 되돌린다. mergeQuery 가 현재 페이지까지 나르기 때문에
    // 되돌리지 않으면 3페이지를 보던 중 검색했을 때 결과의 3페이지로 이동해 빈 화면이 열린다.
    // 표시 개수(per_page)·정렬은 사용자가 고른 값이므로 그대로 유지한다 (#75).
    it('회원 목록 검색은 page 를 떨구고 표시 개수는 유지한다', async () => {
        window.history.replaceState({}, '', '/admin/users?page=3&per_page=50&sort_by=created_at');

        const search = (readLayout('admin_user_list.json') as {
            named_actions: { searchUsers: NavAction };
        }).named_actions.searchUsers;

        const q = queryOf(await runNav(search));

        expect(q.get('page')).toBeNull();
        expect(q.get('per_page')).toBe('50');
        expect(q.get('sort_by')).toBe('created_at');
    });

    it("회원 폼의 '목록' 복귀가 목록 상태를 복원한다", async () => {
        window.history.replaceState({}, '', '/admin/users/9/edit?page=3&search=홍길동');

        const action = collectNavActions(readLayout('admin_user_form.json')).find(
            (a) => a.params.path === '/admin/users'
        );

        const q = queryOf(await runNav(action as NavAction));

        expect(q.get('page')).toBe('3');
        expect(q.get('search')).toBe('홍길동');
    });

    // 검색은 조건을 "바꾸는" 액션이지 리셋이 아니다. 검색어와 페이지만 새로 정하고
    // 사용자가 고른 표시 개수(per_page)·정렬은 그대로 둔다. 같은 파일의 페이지네이션은
    // 이미 병합하고 있어서, 검색만 비병합이면 한 화면 안에서 규약이 엇갈린다 (#75).
    it.each([
        ['admin', 'partials/admin_template_list/_tab_admin.json'],
        ['user', 'partials/admin_template_list/_tab_user.json'],
    ])('템플릿(%s) 목록 검색이 표시 개수를 유지한다', async (kind, rel) => {
        window.history.replaceState({}, '', `/admin/templates/${kind}?page=3&per_page=48`);

        const action = collectNavActions(readLayout(rel)).find(
            (a) => a.params.path === `/admin/templates/${kind}`
        );
        expect(action, `${rel} 의 검색 navigate 를 찾지 못했습니다.`).toBeTruthy();

        const q = queryOf(await runNav(action as NavAction));

        expect(q.get('per_page')).toBe('48');
        expect(q.get('page')).toBeNull();
    });

    it("역할 폼의 '목록' 복귀가 목록 상태를 복원한다", async () => {
        window.history.replaceState({}, '', '/admin/roles/4/edit?page=2&search=관리');

        const action = collectNavActions(readLayout('admin_role_form.json')).find(
            (a) => a.params.path === '/admin/roles'
        );
        expect(action, "역할 폼의 '/admin/roles' 복귀 navigate 를 찾지 못했습니다.").toBeTruthy();

        const q = queryOf(await runNav(action as NavAction));

        expect(q.get('page')).toBe('2');
        expect(q.get('search')).toBe('관리');
    });
});

// ---------------------------------------------------------------------------
// 반대 방향 — 탭 전환은 상태를 승계하지 않는다
//
// 템플릿 목록은 탭마다 라우트가 갈리고(`/admin/templates/user` ↔ `/admin/templates/admin`)
// 각 탭이 자기 검색어·페이지를 갖는 별개의 목록이다. 여기에 병합을 붙이면 다른 탭의
// 검색 결과로 걸러진 페이지가 열린다 (#75 수정 중 실제로 한 번 들어갔던 회귀).
// ---------------------------------------------------------------------------

describe('#75 탭 전환은 이전 탭의 목록 상태를 물려받지 않는다', () => {
    afterEach(() => {
        window.history.replaceState({}, '', '/');
    });

    it('템플릿 목록 탭 전환 navigate 에 mergeQuery 가 없다', () => {
        const tabNav = collectNavActions(readLayout('admin_template_list.json')).find((a) =>
            String(a.params.path ?? '').startsWith('/admin/templates/{{$args[0]')
        );
        expect(tabNav, '템플릿 목록 탭 전환 navigate 를 찾지 못했습니다.').toBeTruthy();
        expect((tabNav as NavAction).params.mergeQuery).not.toBe(true);
        expect(String((tabNav as NavAction).comment ?? '')).toContain('audit:allow');
    });

    it('탭을 바꾸면 이전 탭의 검색어·페이지가 따라오지 않는다', async () => {
        window.history.replaceState({}, '', '/admin/templates/user?page=3&search=sirsoft');

        const tabNav = collectNavActions(readLayout('admin_template_list.json')).find((a) =>
            String(a.params.path ?? '').startsWith('/admin/templates/{{$args[0]')
        );

        const dest = await runNav({
            ...(tabNav as NavAction),
            params: { ...(tabNav as NavAction).params, path: '/admin/templates/admin' },
        });
        const q = queryOf(dest);

        expect(dest.startsWith('/admin/templates/admin')).toBe(true);
        expect(q.get('search')).toBeNull();
        expect(q.get('page')).toBeNull();
    });
});
