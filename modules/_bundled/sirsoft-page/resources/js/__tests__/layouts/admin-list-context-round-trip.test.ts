/**
 * @file admin-list-context-round-trip.test.ts
 * @description 관리자 페이지 목록 컨텍스트 왕복 시 URL 목록 상태 보존 회귀 (#75)
 *
 * 결함: 페이지 목록(`/admin/pages`) 에서 상세·수정 폼으로 들어갔다 돌아오면 navigate 가
 *   `mergeQuery` 없이 실행되어 page/검색 필터가 URL 에서 사라지고 1페이지로 되돌아갔다.
 *
 * 검증 방식 2단:
 *   1. 실 레이아웃 JSON 전수 분석 — 클러스터 내 navigate 가 모두 `mergeQuery: true` 인지
 *   2. 실 액션을 엔진에 태워 실행(`triggerAction`) — 선언이 실제 목적지 URL 로 이어지는지
 *   선언만 검사하면 "JSON 에 리터럴이 적혀 있다" 까지만 고정되고 병합 결과는 미검증으로 남는다.
 *
 * @scenario leg=row_click|detail_return|form_cancel
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

const LAYOUTS_ROOT = path.resolve(__dirname, '../../../layouts/admin');

/** 이 확장이 소유한 페이지네이션 목록 라우트 */
const LIST_ROUTES = ['/admin/pages'];

/** 목록 클러스터에 속하는 레이아웃 파일 */
const CLUSTER_FILES = ['admin_page_list.json', 'admin_page_detail.json', 'admin_page_form.json'];

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

describe('#75 관리자 페이지 목록 컨텍스트 왕복 — mergeQuery 전수', () => {
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

    it("페이지 상세의 '목록' 버튼이 목록 상태를 복원한다", () => {
        const backs = collectNavActions(readLayout('admin_page_detail.json')).filter(
            (a) => a.params.path === '/admin/pages'
        );
        expect(backs.length).toBeGreaterThan(0);
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

/**
 * 실 JSON 에서 목적지 경로가 일치하는 navigate 액션을 찾습니다.
 *
 * @param json 레이아웃 JSON
 * @param destPath 목적지 경로 (원문 그대로)
 * @returns 액션
 */
function findNavAction(json: unknown, destPath: string): NavAction {
    const action = collectNavActions(json).find((a) => a.params.path === destPath);
    expect(action, `navigate 액션(${destPath})을 실 JSON 에서 찾지 못함`).toBeTruthy();
    return action as NavAction;
}

describe('#75 관리자 페이지 목록 컨텍스트 왕복 — 실행 결과 URL', () => {
    afterEach(() => {
        window.history.replaceState({}, '', '/');
    });

    it('목록 행 클릭이 목록 상태를 상세 URL 로 나른다', async () => {
        window.history.replaceState({}, '', '/admin/pages?page=3&per_page=2&sort_by=title');

        const q = queryOf(await runNav(findNavAction(readLayout('admin_page_list.json'), '/admin/pages/{{$args[1].id}}')));

        expect(q.get('page')).toBe('3');
        expect(q.get('per_page')).toBe('2');
        expect(q.get('sort_by')).toBe('title');
    });

    it("상세의 '목록' 버튼이 상세 URL 의 목록 상태를 목록으로 복귀시킨다", async () => {
        window.history.replaceState({}, '', '/admin/pages/7?page=3&per_page=2&sort_by=title');

        const q = queryOf(await runNav(findNavAction(readLayout('admin_page_detail.json'), '/admin/pages')));

        expect(q.get('page')).toBe('3');
        expect(q.get('per_page')).toBe('2');
        expect(q.get('sort_by')).toBe('title');
    });

    it('상세 → 수정 이동에서도 목록 상태가 이어진다', async () => {
        window.history.replaceState({}, '', '/admin/pages/7?page=3&per_page=2');

        const q = queryOf(
            await runNav(findNavAction(readLayout('admin_page_detail.json'), '/admin/pages/{{route?.id}}/edit'))
        );

        expect(q.get('page')).toBe('3');
        expect(q.get('per_page')).toBe('2');
    });
});
