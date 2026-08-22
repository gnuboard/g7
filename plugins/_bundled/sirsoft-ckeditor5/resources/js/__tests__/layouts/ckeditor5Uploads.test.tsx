/**
 * CKEditor5 업로드 관리 화면 — 계약 + 렌더링 테스트 (공개 #115)
 *
 * @description
 * 이 화면은 사용자 파일을 실제로 파기하므로, 화면이 지키기로 한 계약을 코드에 고정한다.
 *
 *  - 일괄 삭제가 달린 목록이므로 선택 범위는 반드시 `page` (화면 밖 선택이 대상에 실리면
 *    운영자가 보지도 체크하지도 않은 파일이 지워진다)
 *  - 목록 클러스터 안의 이동은 목록 상태를 승계(mergeQuery)하고, 의도적 리셋만 예외
 *  - 정렬 선택지는 서버 게이트(IndexUploadsRequest)의 부분집합이어야 한다
 *
 * @effects admin_ui_selection_scope_page, admin_ui_merge_query, admin_ui_sort_subset,
 *          admin_ui_reference_badge
 */

import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

import uploadsLayout from '../../../layouts/admin/ckeditor5_uploads.json';
import routesJson from '../../../routes.json';

// ─── 헬퍼 ───

/**
 * 플러그인 루트(plugin.json 기준)를 위로 훑어 찾는다.
 *
 * @returns 플러그인 루트 절대경로
 */
function pluginRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 10; depth++) {
        if (fs.existsSync(path.join(current, 'plugin.json'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('plugin.json 을 가진 플러그인 루트를 찾지 못했습니다.');
}

/**
 * 레이아웃 트리에서 조건을 만족하는 첫 노드를 찾는다 (slots/modals 포함).
 *
 * @param node 탐색 시작 노드
 * @param predicate 노드 판정 함수
 * @returns 찾은 노드 또는 null
 */
function findNode(node: any, predicate: (n: any) => boolean): any {
    if (!node || typeof node !== 'object') return null;
    if (predicate(node)) return node;

    const groups: any[] = [
        node.children ?? [],
        node.modals ?? [],
        ...(node.slots ? Object.values(node.slots) : []),
    ];

    for (const group of groups) {
        for (const child of group as any[]) {
            const found = findNode(child, predicate);
            if (found) return found;
        }
    }

    return null;
}

/**
 * 레이아웃 트리의 모든 navigate 액션을 수집한다.
 *
 * @param node 탐색 시작 노드
 * @param collected 누적 배열
 * @returns navigate 액션 목록
 */
function collectNavigateActions(node: any, collected: any[] = []): any[] {
    if (!node || typeof node !== 'object') return collected;

    if (Array.isArray(node)) {
        node.forEach((child) => collectNavigateActions(child, collected));
        return collected;
    }

    if (node.handler === 'navigate') {
        collected.push(node);
    }

    Object.values(node).forEach((value) => {
        if (value && typeof value === 'object') {
            collectNavigateActions(value, collected);
        }
    });

    return collected;
}

const dataGrid = findNode(uploadsLayout, (n) => n.id === 'ckeditor5_uploads_datagrid');

describe('업로드 관리 화면 — 목록 계약', () => {
    it('일괄 삭제 목록이므로 선택 범위가 page 로 고정돼 있다', () => {
        expect(dataGrid).not.toBeNull();
        expect(dataGrid.props.selectionScope).toBe('page');
    });

    it('선택 상태 변경이 전용 전역 키에 저장되고 일괄 삭제가 같은 키를 읽는다', () => {
        const selectionAction = (dataGrid.actions ?? []).find(
            (a: any) => a.event === 'onSelectionChange',
        );

        expect(selectionAction?.params?.selectedIds_ckeditor5_uploads).toBe('{{$args[0]}}');

        const bulkModal = findNode(uploadsLayout, (n) => n.id === 'bulk_delete_confirm_modal');
        expect(JSON.stringify(bulkModal)).toContain('_global.selectedIds_ckeditor5_uploads');
    });

    it('삭제 API 가 플러그인 네임스페이스 경로를 호출한다', () => {
        const payload = JSON.stringify(uploadsLayout);

        expect(payload).toContain('/api/plugins/sirsoft-ckeditor5/admin/uploads/{{_global.modal_data.id}}');
        expect(payload).toContain('/api/plugins/sirsoft-ckeditor5/admin/uploads/bulk-delete');
    });

    it('참조 상태 배지가 참조/미참조 두 갈래로 렌더된다', () => {
        const column = dataGrid.props.columns.find((c: any) => c.field === 'referenced');

        expect(column).toBeDefined();
        const conditions = column.cellChildren.map((c: any) => c.if);
        expect(conditions).toContain('{{row.referenced === true}}');
        expect(conditions).toContain('{{row.referenced !== true}}');
    });
});

describe('업로드 관리 화면 — 목록 컨텍스트 왕복', () => {
    it('클러스터 안 이동은 mergeQuery 로 목록 상태를 승계한다', () => {
        const navigates = collectNavigateActions(uploadsLayout).filter(
            (a) => (a.params?.path ?? '').includes('/admin/plugins/sirsoft-ckeditor5/uploads'),
        );

        expect(navigates.length).toBeGreaterThan(0);

        navigates.forEach((action) => {
            const isIntentionalReset = JSON.stringify(action).includes('audit:allow')
                || Object.keys(action.params?.query ?? {}).length === 0;

            if (!isIntentionalReset) {
                expect(action.params.mergeQuery).toBe(true);
            }
        });
    });

    it('검색은 페이지를 되돌리면서 바뀐 값만 직접 넘긴다', () => {
        const search = (uploadsLayout as any).named_actions.searchUploads;

        expect(search.params.mergeQuery).toBe(true);
        expect(search.params.query.page).toBe('');
        expect(search.params.query.search).toContain('_local.filter.search');
    });

    it('필터 초기화는 병합하지 않으며 그 의도를 코드에 남긴다', () => {
        const clear = (uploadsLayout as any).named_actions.clearFilters;
        const navigate = clear.params.actions.find((a: any) => a.handler === 'navigate');

        expect(navigate.params.mergeQuery).toBeUndefined();
        expect(navigate.comment).toContain('audit:allow layout-list-context-navigate-merge-query');
    });
});

describe('업로드 관리 화면 — 서버 계약 정합', () => {
    it('라우트가 플러그인 네임스페이스 안에 선언되고 조회 권한을 요구한다', () => {
        const route = (routesJson as any).routes.find(
            (r: any) => r.layout === 'ckeditor5_uploads',
        );

        expect(route.path).toContain('/admin/plugins/sirsoft-ckeditor5/uploads');
        expect(route.meta.permission).toBe('sirsoft-ckeditor5.uploads.read');
        expect(route.auth_required).toBe(true);
    });

    it('화면 정렬 선택지가 서버 게이트의 부분집합이다', () => {
        const gate = fs.readFileSync(
            path.join(pluginRoot(), 'src/Http/Requests/IndexUploadsRequest.php'),
            'utf-8',
        );
        const allowed = /public const SORTABLE = \[([^\]]+)\]/
            .exec(gate)![1]
            .split(',')
            .map((s) => s.trim().replace(/'/g, ''))
            .filter(Boolean);

        const screenSortable = dataGrid.props.columns
            .filter((c: any) => c.sortable === true)
            .map((c: any) => c.field);

        expect(screenSortable.length).toBeGreaterThan(0);
        screenSortable.forEach((field: string) => expect(allowed).toContain(field));
    });

    it('레이아웃이 요구하는 권한이 플러그인 권한 선언에 존재한다', () => {
        const pluginSource = fs.readFileSync(path.join(pluginRoot(), 'plugin.php'), 'utf-8');

        expect(pluginSource).toContain("'identifier' => 'uploads'");
        expect(pluginSource).toContain("'action' => 'read'");
        expect(pluginSource).toContain("'action' => 'delete'");
    });
});

// ─── 렌더링 ───

const TestDiv: React.FC<any> = ({ className, children }) => <div className={className}>{children}</div>;
const TestSpan: React.FC<any> = ({ className, children, text }) => (
    <span className={className}>{children || text}</span>
);
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

describe('업로드 관리 화면 — 안내 배너 렌더링', () => {
    let registry: ComponentRegistry;
    let testUtils: ReturnType<typeof createLayoutTest> | null = null;

    beforeEach(() => {
        registry = ComponentRegistry.getInstance();
        (registry as any).registry = {
            Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
            P: { component: TestSpan, metadata: { name: 'P', type: 'basic' } },
            Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
            Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
        };
    });

    afterEach(() => {
        testUtils?.cleanup();
        testUtils = null;
        (registry as any).registry = {};
    });

    /**
     * 스캔 상한 안내 배너를 지정 상태로 렌더한다.
     *
     * @param scanLimited 상한 도달 여부
     * @returns createLayoutTest 유틸
     */
    function makeUtils() {
        return createLayoutTest(
            {
                version: '1.0.0',
                layout_name: 'test_ckeditor5_uploads_notice',
                components: [findNode(uploadsLayout, (n) => n.id === 'scan_limited_notice')],
                // 실제 화면의 데이터소스 선언을 그대로 쓴다 — 배너 조건이 이 응답을 읽는다.
                data_sources: (uploadsLayout as any).data_sources,
            } as any,
            {
                auth: {
                    isAuthenticated: true,
                    user: { id: 1, name: 'Admin', role: 'super_admin' },
                    authType: 'admin',
                },
                translations: {
                    'sirsoft-ckeditor5': {
                        admin: {
                            uploads: {
                                scan_limited_notice: '참조 상태 필터는 최근 :count건을 기준으로 판정합니다.',
                            },
                        },
                    },
                },
                locale: 'ko',
            },
        );
    }

    it('스캔 상한에 걸리면 안내 배너가 노출된다', async () => {
        testUtils = makeUtils();
        testUtils.mockApi('ckeditor5Uploads', {
            // 화면은 `ckeditor5Uploads?.data?.meta` 를 읽으므로 응답 봉투의 data 계층을 유지한다.
            response: {
                data: {
                    data: [],
                    pagination: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
                    meta: { scan_limited: true, scan_window: 500 },
                },
            },
        });

        const { container } = await testUtils.render();

        expect(container.textContent ?? '').toContain('참조 상태 필터는');
    });

    it('배너 문구가 스캔 상한 값을 파라미터로 바인딩한다', () => {
        const notice = findNode(uploadsLayout, (n) => n.id === 'scan_limited_notice');
        const text = findNode(notice, (n) => typeof n.text === 'string' && n.text.includes('scan_limited_notice')).text;

        expect(text).toContain('|count=');
        expect(text).toContain('meta?.scan_window');
    });

    it('상한에 걸리지 않으면 배너가 렌더되지 않는다', async () => {
        testUtils = makeUtils();
        testUtils.mockApi('ckeditor5Uploads', {
            response: {
                data: {
                    data: [],
                    pagination: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
                    meta: { scan_limited: false, scan_window: 500 },
                },
            },
        });

        const { container } = await testUtils.render();

        expect(container.textContent ?? '').not.toContain('참조 상태 필터는');
    });
});

describe('업로드 관리 화면 — 파라미터 치환 문구', () => {
    /**
     * 프론트 다국어 파라미터는 `{{name}}` 규약이다. Laravel 스타일 `:name` 을 쓰면 치환되지
     * 않아 운영자에게 원시 placeholder 가 그대로 보인다 — 파괴적 삭제 확인 모달에서도 마찬가지다
     * (브라우저 실측 — "선택한 :count건의 파일과 기록이 함께 삭제됩니다").
     */
    it.each(['ko', 'en'])('%s 문구가 :param 대신 {{param}} 을 쓴다', (locale) => {
        const raw = fs.readFileSync(path.join(pluginRoot(), `resources/lang/${locale}.json`), 'utf-8');
        const messages = JSON.parse(raw);
        const offenders: string[] = [];

        const walk = (node: unknown, keyPath: string): void => {
            if (typeof node === 'string') {
                // `:count` 처럼 낱말이 이어지는 형태만 파라미터로 본다 (URL·명령어의 콜론은 제외).
                if (/(^|\s):[a-z_]+/.test(node)) offenders.push(`${keyPath} = ${node}`);
                return;
            }
            if (node && typeof node === 'object') {
                for (const [key, value] of Object.entries(node as Record<string, unknown>)) {
                    walk(value, keyPath ? `${keyPath}.${key}` : key);
                }
            }
        };

        walk(messages.admin?.uploads ?? {}, 'admin.uploads');
        walk(messages.settings?.cleanup ?? {}, 'settings.cleanup');

        expect(offenders).toEqual([]);
    });

    it('레이아웃이 넘기는 파라미터 이름이 문구의 placeholder 와 일치한다', () => {
        const messages = JSON.parse(
            fs.readFileSync(path.join(pluginRoot(), 'resources/lang/ko.json'), 'utf-8'),
        );

        expect(messages.admin.uploads.bulk_delete.selected).toContain('{{count}}');
        expect(messages.admin.uploads.bulk_delete.confirm_message).toContain('{{count}}');
        expect(messages.admin.uploads.scan_limited_notice).toContain('{{count}}');
    });
});
