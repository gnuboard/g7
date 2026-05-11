/**
 * @file admin-base-transition-overlay.test.tsx
 * @description 어드민 베이스 레이아웃의 transition_overlay 설정 및 폼 컨테이너 blur 제거 회귀 테스트
 *
 * 이슈 #245 — 어드민 환경설정 진입 시 데이터 로드 전 폼 조작으로 인한 저장 실패 방지를 위해
 * 베이스 레이아웃에 spinner 오버레이를 도입하고, 컨테이너 레벨의 전체 페이지 blur 처리를 제거한다.
 */

import * as fs from 'node:fs';
import * as path from 'node:path';
import { describe, it, expect } from 'vitest';

import adminBase from '../../layouts/_admin_base.json';
import adminSettings from '../../layouts/admin_settings.json';
import adminUserForm from '../../layouts/admin_user_form.json';
import adminUserDetail from '../../layouts/admin_user_detail.json';
import adminRoleForm from '../../layouts/admin_role_form.json';
import adminTemplateLayoutEdit from '../../layouts/admin_template_layout_edit.json';
import adminMenuPanelForm from '../../layouts/partials/admin_menu_list/_panel_form.json';

const LAYOUTS_DIR = path.resolve(__dirname, '../../layouts');

type LayoutNode = {
    id?: string;
    children?: LayoutNode[];
    blur_until_loaded?: unknown;
    [key: string]: unknown;
};

function findById(input: LayoutNode | LayoutNode[] | undefined, id: string): LayoutNode | undefined {
    if (!input) return undefined;
    const nodes = Array.isArray(input) ? input : [input];
    for (const node of nodes) {
        if (node.id === id) return node;
        const slots = (node as any).slots as Record<string, LayoutNode[]> | undefined;
        if (slots) {
            for (const slotKey of Object.keys(slots)) {
                const found = findById(slots[slotKey], id);
                if (found) return found;
            }
        }
        const found = findById(node.children, id);
        if (found) return found;
    }
    return undefined;
}

function rootNodes(layout: any): LayoutNode | LayoutNode[] {
    if (layout && Array.isArray(layout.components)) return layout.components as LayoutNode[];
    return layout as LayoutNode;
}

describe('어드민 베이스 transition_overlay (이슈 #245)', () => {
    it('_admin_base.json 에 spinner 오버레이가 활성화되어 있어야 한다', () => {
        const overlay = (adminBase as any).transition_overlay;
        expect(overlay).toBeDefined();
        expect(overlay.enabled).toBe(true);
        expect(overlay.style).toBe('spinner');
        expect(overlay.target).toBe('right_content_area');
        expect(overlay.spinner?.component).toBe('PageLoading');
    });

    it('베이스의 target 으로 지정된 right_content_area 가 main_content 의 부모 wrapper 여야 한다', () => {
        // spinner target 은 slot 컨테이너(main_content) 자체가 아닌 부모 wrapper 여야 함
        // 사용자 베이스의 main_content_area 와 동일 패턴 — 외부 DOM mount 가 React reconcile 에 영향 없음
        const wrapper = findById((adminBase as any).components, 'right_content_area');
        expect(wrapper).toBeDefined();
        const inner = findById(wrapper!.children, 'main_content');
        expect(inner).toBeDefined();
    });
});

describe('목록 페이지 페이지네이션 transition_overlay_target 가드 (이슈 #245)', () => {
    /**
     * DataGrid 사용 + `params.query.page` 를 가진 navigate handler 가 있는 페이지에서,
     * 해당 navigate 가 모두 `transition_overlay_target` 으로 DataGrid ID 를 명시해야 한다.
     * 페이지 진입은 베이스 spinner(right_content_area), 페이지네이션 시는 DataGrid
     * 영역만 spinner 표시 (방식 B).
     *
     * fs 동적 스캔 — 본문 + partials/<name>/* 모두 검사하여 신규 페이지 자동 커버.
     */
    function findDataGridIds(node: any, out: Set<string> = new Set()): Set<string> {
        if (!node || typeof node !== 'object') return out;
        if (node.name === 'DataGrid' && typeof node.id === 'string') out.add(node.id);
        for (const key of Object.keys(node)) {
            const v = node[key];
            if (Array.isArray(v)) for (const item of v) findDataGridIds(item, out);
            else if (v && typeof v === 'object') findDataGridIds(v, out);
        }
        return out;
    }

    function findPageNavigates(node: any, out: any[] = []): any[] {
        if (!node || typeof node !== 'object') return out;
        if (node.handler === 'navigate' && node.params?.query && Object.prototype.hasOwnProperty.call(node.params.query, 'page')) {
            out.push(node);
        }
        for (const key of Object.keys(node)) {
            const v = node[key];
            if (Array.isArray(v)) for (const item of v) findPageNavigates(item, out);
            else if (v && typeof v === 'object') findPageNavigates(v, out);
        }
        return out;
    }

    function loadAllForLayout(name: string): { layout: any; allFiles: any[] } {
        const layoutPath = path.join(LAYOUTS_DIR, `${name}.json`);
        const layout = JSON.parse(fs.readFileSync(layoutPath, 'utf-8'));
        const allFiles: any[] = [layout];
        const partialDir = path.join(LAYOUTS_DIR, 'partials', name);
        if (fs.existsSync(partialDir)) {
            for (const f of fs.readdirSync(partialDir)) {
                if (f.endsWith('.json')) {
                    allFiles.push(JSON.parse(fs.readFileSync(path.join(partialDir, f), 'utf-8')));
                }
            }
        }
        return { layout, allFiles };
    }

    const adminFiles = fs.readdirSync(LAYOUTS_DIR)
        .filter((f) => f.startsWith('admin_') && f.endsWith('.json'))
        .map((f) => path.basename(f, '.json'));

    interface PaginationCase {
        name: string;
        gridIds: string[];
        navigates: any[];
    }

    const cases: PaginationCase[] = adminFiles
        .map((name) => {
            const { allFiles } = loadAllForLayout(name);
            const gridIds = new Set<string>();
            const navigates: any[] = [];
            for (const file of allFiles) {
                findDataGridIds(file, gridIds);
                findPageNavigates(file, navigates);
            }
            return { name, gridIds: Array.from(gridIds), navigates };
        })
        .filter((c) => c.gridIds.length > 0 && c.navigates.length > 0);

    it('스캔 대상 어드민 페이지가 1개 이상이어야 한다 (회귀 방지)', () => {
        expect(cases.length).toBeGreaterThan(0);
    });

    for (const c of cases) {
        it(`${c.name}: 모든 페이지네이션 navigate 에 transition_overlay_target 이 DataGrid body id(\${gridId}__body) 로 명시되어야 한다`, () => {
            // DataGrid root id 는 React tree + 외부 참조용으로 유지되고,
            // spinner mount 대상(pagination 제외 영역)은 DataGrid 내부 body wrapper 의 `${id}__body` 이다.
            const allowed = new Set(c.gridIds.map((id) => `${id}__body`));
            for (let i = 0; i < c.navigates.length; i++) {
                const nav = c.navigates[i];
                const target = nav.params?.transition_overlay_target;
                expect(typeof target, `${c.name} navigate[${i}]: transition_overlay_target 미명시`).toBe('string');
                expect(allowed.has(target), `${c.name} navigate[${i}]: transition_overlay_target="${target}" 가 DataGrid body id 와 일치하지 않음 (기대: ${[...allowed].join('|')})`).toBe(true);
            }
        });
    }
});

describe('채널 서브 탭 transition_overlay_target 가드 (이슈 #245, engine-v1.36.0)', () => {
    /**
     * 환경설정 알림 탭 안의 채널 서브 탭 클릭 시 채널 콘텐츠 영역에만 spinner 가
     * 표시되도록 하는 navigate.params.transition_overlay_target 옵션의 회귀 가드.
     * 두 partial 모두 channel sub-tab navigate handler 에 transition_overlay_target 이 명시되어야 함.
     */
    const channelTabCases = [
        {
            name: 'admin_settings/_tab_notification_definitions',
            partial: JSON.parse(fs.readFileSync(
                path.resolve(LAYOUTS_DIR, 'partials/admin_settings/_tab_notification_definitions.json'),
                'utf-8'
            )),
            wrapperId: 'notif_channel_content',
        },
    ];

    function collectNavigateHandlers(node: any, out: any[] = []): any[] {
        if (!node || typeof node !== 'object') return out;
        if (node.handler === 'navigate' && node.params && typeof node.params === 'object') {
            out.push(node);
        }
        for (const key of Object.keys(node)) {
            const value = node[key];
            if (Array.isArray(value)) {
                for (const item of value) collectNavigateHandlers(item, out);
            } else if (value && typeof value === 'object') {
                collectNavigateHandlers(value, out);
            }
        }
        return out;
    }

    for (const { name, partial, wrapperId } of channelTabCases) {
        it(`${name}: 채널 wrapper (${wrapperId}) 가 존재해야 한다`, () => {
            const found = findById(partial, wrapperId);
            expect(found, `${wrapperId} 미존재`).toBeDefined();
        });

        it(`${name}: 채널 탭 navigate 에 transition_overlay_target=${wrapperId} 가 명시되어야 한다`, () => {
            const navs = collectNavigateHandlers(partial);
            const channelNavs = navs.filter((n) =>
                n.params?.query && Object.prototype.hasOwnProperty.call(n.params.query, 'channel')
            );
            expect(channelNavs.length, '채널 탭 navigate handler 미발견').toBeGreaterThan(0);
            for (const nav of channelNavs) {
                expect(nav.params.transition_overlay_target).toBe(wrapperId);
            }
        });
    }
});

describe('admin_settings 탭 콘텐츠 영역 spinner (이슈 #245)', () => {
    /**
     * 환경설정 탭 클릭 시 탭 콘텐츠 영역(settings_tab_content)에만 spinner 가 표시되어야 한다.
     * 사용자 마이페이지(target: mypage_tab_content)와 동일 패턴.
     * 베이스의 target=right_content_area 를 admin_settings 가 settings_tab_content 로 override.
     */
    it('admin_settings.transition_overlay.target 이 settings_tab_content 여야 한다', () => {
        const overlay = (adminSettings as any).transition_overlay;
        expect(overlay?.target).toBe('settings_tab_content');
    });

    it('admin_settings.transition_overlay.fallback_target 이 right_content_area 여야 한다', () => {
        const overlay = (adminSettings as any).transition_overlay;
        expect(overlay?.fallback_target).toBe('right_content_area');
    });

    it('admin_settings 의 settings_content 안에 settings_tab_content wrapper 가 존재해야 한다', () => {
        const settingsContent = findById((adminSettings as any).slots?.content ?? [], 'settings_content');
        expect(settingsContent, 'settings_content 미존재').toBeDefined();
        const tabContent = findById(settingsContent!.children, 'settings_tab_content');
        expect(tabContent, 'settings_tab_content wrapper 미존재').toBeDefined();
    });

    it('settings_tab_content 안에 10개 탭 partial 이 모두 들어 있어야 한다', () => {
        const settingsContent = findById((adminSettings as any).slots?.content ?? [], 'settings_content');
        const tabContent = findById(settingsContent!.children, 'settings_tab_content');
        const partials = (tabContent!.children ?? []).filter((c: any) => c.partial?.includes('partials/admin_settings/_tab_'));
        expect(partials.length).toBe(10);
    });
});

describe('어드민 자식 레이아웃 wait_for 일괄 가드 (이슈 #245, engine-v1.30.0+)', () => {
    /**
     * _admin_base 를 extends 하는 모든 어드민 페이지에 대해:
     *  1. progressive 데이터소스가 1개 이상 존재하면 transition_overlay.wait_for 명시 필수
     *  2. wait_for 에는 해당 페이지의 모든 progressive(api, non-background) 데이터소스가 포함되어야 함
     *  3. wait_for ID 는 모두 data_sources 에 존재해야 하며 background/websocket 이 아니어야 함
     *
     * fs 기반 동적 스캔으로 신규 페이지 추가 시 자동 검증.
     */
    const adminFiles = fs.readdirSync(LAYOUTS_DIR)
        .filter((f) => f.startsWith('admin_') && f.endsWith('.json'))
        .map((f) => path.join(LAYOUTS_DIR, f));

    interface PageInfo {
        name: string;
        layout: any;
        progressiveIds: string[];
    }

    const pages: PageInfo[] = adminFiles
        .map((file) => {
            const layout = JSON.parse(fs.readFileSync(file, 'utf-8'));
            const dataSources: any[] = layout.data_sources ?? [];
            const progressiveIds = dataSources
                .filter((s) => {
                    const type = s.type ?? 'api';
                    const strategy = s.loading_strategy ?? 'progressive';
                    return type !== 'websocket' && strategy !== 'blocking' && strategy !== 'background';
                })
                .map((s) => s.id as string);
            return { name: path.basename(file, '.json'), layout, progressiveIds };
        })
        .filter((p) => p.layout.extends === '_admin_base' && p.progressiveIds.length > 0);

    it('스캔 대상 페이지가 1개 이상이어야 한다 (회귀 방지)', () => {
        expect(pages.length).toBeGreaterThan(0);
    });

    for (const page of pages) {
        it(`${page.name}: transition_overlay.wait_for 가 명시되어야 한다`, () => {
            const waitFor = page.layout.transition_overlay?.wait_for;
            expect(waitFor, `${page.name}: wait_for 미명시`).toBeDefined();
            expect(Array.isArray(waitFor)).toBe(true);
            expect((waitFor as string[]).length).toBeGreaterThan(0);
        });

        it(`${page.name}: wait_for 가 모든 progressive 데이터소스를 포함해야 한다`, () => {
            const waitFor: string[] = page.layout.transition_overlay?.wait_for ?? [];
            for (const id of page.progressiveIds) {
                expect(
                    waitFor.includes(id),
                    `${page.name}: progressive 데이터소스 "${id}" 가 wait_for 에 누락`
                ).toBe(true);
            }
        });

        it(`${page.name}: wait_for ID 는 모두 data_sources 에 존재하고 background/websocket 이 아니어야 한다`, () => {
            const waitFor: string[] = page.layout.transition_overlay?.wait_for ?? [];
            const byId = new Map<string, any>((page.layout.data_sources ?? []).map((s: any) => [s.id, s]));
            for (const id of waitFor) {
                const source = byId.get(id);
                expect(source, `${page.name}: data_sources 에 ${id} 미존재`).toBeDefined();
                expect(source.type === 'websocket', `${page.name}: ${id} 가 websocket`).toBe(false);
                expect((source.loading_strategy ?? 'progressive') === 'background', `${page.name}: ${id} 가 background`).toBe(false);
            }
        });
    }
});

describe('어드민 자식 레이아웃 베이스 상속 (이슈 #245)', () => {
    /**
     * 베이스의 transition_overlay 가 자식 레이아웃에 자동 전파되는지 보장하기 위해,
     * 어드민 폼/디테일 레이아웃이 _admin_base 를 extends 하는지 검증한다.
     * (LayoutService 는 부모-자식 transition_overlay 병합을 child 우선/parent fallback 으로 처리)
     */
    const inheritanceCases: Array<{ name: string; layout: any }> = [
        { name: 'admin_settings', layout: adminSettings },
        { name: 'admin_user_form', layout: adminUserForm },
        { name: 'admin_user_detail', layout: adminUserDetail },
        { name: 'admin_role_form', layout: adminRoleForm },
        { name: 'admin_template_layout_edit', layout: adminTemplateLayoutEdit },
    ];

    for (const { name, layout } of inheritanceCases) {
        it(`${name} 은 _admin_base 를 extends 해야 한다`, () => {
            expect(layout.extends).toBe('_admin_base');
        });
    }
});

describe('어드민 폼 컨테이너 전체 페이지 blur 제거 (이슈 #245)', () => {
    const cases: Array<{ name: string; layout: any; containerId: string }> = [
        { name: 'admin_settings#settings_content', layout: adminSettings, containerId: 'settings_content' },
        { name: 'admin_user_form#user_form_content', layout: adminUserForm, containerId: 'user_form_content' },
        { name: 'admin_user_detail#user_detail_content', layout: adminUserDetail, containerId: 'user_detail_content' },
        { name: 'admin_role_form#role_form', layout: adminRoleForm, containerId: 'role_form' },
        { name: 'admin_template_layout_edit#main_grid', layout: adminTemplateLayoutEdit, containerId: 'main_grid' },
        { name: 'admin_template_layout_edit#mobile_file_selector', layout: adminTemplateLayoutEdit, containerId: 'mobile_file_selector' },
        { name: 'admin_menu_list/_panel_form#menu_form', layout: adminMenuPanelForm, containerId: 'menu_form' },
    ];

    for (const { name, layout, containerId } of cases) {
        it(`${name} 컨테이너에 blur_until_loaded 가 정의되어 있지 않아야 한다`, () => {
            const node = findById(layout.components ?? layout, containerId);
            expect(node, `${containerId} 컨테이너를 찾지 못함`).toBeDefined();
            expect(node!.blur_until_loaded).toBeUndefined();
        });
    }
});
