/**
 * @file template-settings-layout.test.ts
 * @description 템플릿이 공급한 설정 화면 레이아웃과 템플릿 목록 「설정」 메뉴를 잠근다 (#434 D28·D32).
 *
 * - 번들 4종은 각자 `settings/template_settings.g7.html`·부품 매니페스트를 두고, 관리자 템플릿 공용 화면은 없다
 * - 화면은 그 템플릿 부품 하나(`{id}.TemplateSettingsScreen`)가 그린다 — 관리자 템플릿 부품에 기대지 않는다
 * - 조회 데이터소스가 응답의 settings 를 폼 값으로 넣고, 저장은 업로드 신호 → 지금 탭 섹션만 PUT
 * - 기본값으로는 POST reset → 다시 받기, 저장하지 않은 변경을 버린 탭 이동은 다시 받기 (확인 창은 부품 몫)
 * - 목록 두 탭 더보기 메뉴에 「설정」이 있고 설정 화면이 없으면 흐리다
 *
 * 시나리오: case=settings_layout
 *
 * 효과: settings_layout_saves_template_settings
 * 효과: list_exposes_settings_route
 * 효과: read_only_without_settings_permission
 * 효과: settings_reset_to_defaults
 * 효과: settings_tabs_per_section
 * 효과: no_supplied_screen_means_no_route
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * 템플릿 루트(template.json 기준)를 위로 훑어 찾는다.
 *
 * @returns 템플릿 루트 절대경로
 */
function templateRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 10; depth++) {
        if (fs.existsSync(path.join(current, 'template.json'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('template.json 을 가진 템플릿 루트를 찾지 못했습니다.');
}

/**
 * 레이아웃 트리를 깊이 우선으로 훑는다.
 *
 * @param node 탐색 시작 노드
 * @param visit 노드마다 부르는 함수
 */
function walk(node: unknown, visit: (n: Record<string, any>) => void): void {
    if (Array.isArray(node)) {
        node.forEach((child) => walk(child, visit));
        return;
    }
    if (!node || typeof node !== 'object') return;

    visit(node as Record<string, any>);
    Object.values(node as Record<string, unknown>).forEach((value) => walk(value, visit));
}

/**
 * 조건을 만족하는 첫 노드를 찾는다.
 *
 * @param tree 레이아웃 트리
 * @param predicate 조건
 * @returns 노드 (없으면 null)
 */
function find(tree: unknown, predicate: (n: Record<string, any>) => boolean): Record<string, any> | null {
    let found: Record<string, any> | null = null;
    walk(tree, (n) => {
        if (!found && predicate(n)) found = n;
    });

    return found;
}

const root = templateRoot();
const bundled = path.dirname(root);

/** 번들 템플릿 4종 — 각자 설정 화면 레이아웃과 화면 부품을 공급한다 (#434 D28·D32) */
const TEMPLATES = ['sirsoft-admin_basic', 'sirsoft-basic', 'gnuboard7-hello_user_template', 'gnuboard7-hello_admin_template'];

/**
 * 템플릿이 공급한 설정 화면 레이아웃(짝 JSON)을 읽는다.
 *
 * @param id 템플릿 식별자
 * @returns 레이아웃 JSON
 */
const settingsLayout = (id: string): Record<string, any> =>
    JSON.parse(fs.readFileSync(path.join(bundled, id, 'settings/template_settings.json'), 'utf-8'));

describe('템플릿이 공급한 설정 화면 (#434 D28·D32)', () => {
    // @scenario case=settings_layout
    // @effects settings_layout_saves_template_settings, no_supplied_screen_means_no_route
    it('번들 4종 모두 자기 설정 화면·부품 매니페스트를 두고, 관리자 템플릿 공용 화면은 없다', () => {
        expect(fs.existsSync(path.join(root, 'layouts/admin_template_settings.json'))).toBe(false);
        expect(fs.existsSync(path.join(root, 'layouts/admin_template_settings.g7.html'))).toBe(false);

        for (const id of TEMPLATES) {
            expect(fs.existsSync(path.join(bundled, id, 'settings/template_settings.g7.html'))).toBe(true);
            const manifest = JSON.parse(fs.readFileSync(path.join(bundled, id, 'components.settings.json'), 'utf-8'));
            expect(manifest.identifier).toBe(id);
            expect(manifest.components.composite.map((c: any) => c.name)).toContain('TemplateSettingsScreen');
            expect(manifest.lang).toContain('settings');
            // 레이아웃 폴더 밖 — 안이면 그 템플릿 자기 화면으로도 등록돼 사용자 템플릿에 관리자 화면이 섞인다
            expect(fs.existsSync(path.join(bundled, id, 'layouts/template_settings.json'))).toBe(false);
        }
    });

    for (const id of TEMPLATES) {
        // @scenario case=settings_layout
        // @effects read_only_without_settings_permission, settings_tabs_per_section
        it(`${id} — 조회 응답을 폼 값으로 넣고, 읽기 전용·지금 섹션은 응답과 주소 ?tab= 에서 정한다`, () => {
            const layout = settingsLayout(id);
            const source = layout.data_sources.find((s: any) => s.id === 'template_settings');

            expect(layout.extends).toBe('_admin_base');
            expect(source.endpoint).toBe('/api/admin/templates/{{route.identifier}}/settings');
            expect(source.initLocal).toEqual({ form: '{{data?.settings ?? null}}' });
            expect(layout.computed.isReadOnly).toBe('{{template_settings?.data?.abilities?.can_update !== true}}');
            expect(layout.computed.activeSection).toBe('{{(template_settings?.data?.sections ?? []).some(s => s.key === _local.activeTab) ? _local.activeTab : (template_settings?.data?.sections?.[0]?.key ?? null)}}');
            expect(JSON.stringify(layout.init_actions ?? layout.init)).toContain('{{query.tab ?? null}}');
            expect(layout.permissions).toEqual(['core.templates.read']);
            expect(layout.meta.leave_guard).toBe('{{_local.hasChanges === true}}');
        });

        // @scenario case=settings_layout
        // @effects settings_layout_saves_template_settings
        it(`${id} — 화면은 그 템플릿 부품 하나가 그리고, 관리자 템플릿 부품(모달·토스트 틀·탭 줄)을 부르지 않는다`, () => {
            const layout = settingsLayout(id);
            const screen = find(layout, (n) => n.id === 'template_settings_screen');
            const names = new Set<string>();
            walk(layout.slots ?? layout.components, (n) => {
                if (typeof n.name === 'string' && typeof n.type === 'string') names.add(n.name);
            });

            expect(screen!.name).toBe(`${id}.TemplateSettingsScreen`);
            expect(screen!.props.data).toBe('{{template_settings?.data ?? null}}');
            expect(screen!.props.value).toBe('{{_local.form ?? {}}}');
            expect(screen!.props.readOnly).toBe('{{_computed.isReadOnly}}');
            expect(screen!.props.activeSection).toBe('{{_computed.activeSection}}');
            // 모든 관리자 템플릿에 있는 기본 부품(Div)과 이 템플릿 부품만
            expect([...names].every((name) => name === 'Div' || name.startsWith(`${id}.`))).toBe(true);
            expect(layout.modals ?? []).toEqual([]);
        });

        // @scenario case=settings_layout
        // @effects settings_layout_saves_template_settings, settings_reset_to_defaults, settings_tabs_per_section
        it(`${id} — 저장은 업로드 신호 → 지금 탭 섹션만 PUT, 되돌리기는 POST reset 뒤 다시 받기, 버린 탭 이동은 다시 받기`, () => {
            const screen = find(settingsLayout(id), (n) => n.id === 'template_settings_screen');
            const action = (event: string) => (screen!.actions as any[]).find((a) => a.event === event);
            const steps = (event: string): string[] => {
                const out: string[] = [];
                walk(action(event), (n) => {
                    if (n.handler === 'emitEvent') out.push('emit:' + n.params.event);
                    if (n.handler === 'apiCall') out.push(n.params.method + ' ' + n.target);
                    if (n.handler === 'refetchDataSource') out.push('refetch:' + n.params.dataSourceId);
                    if (n.handler === 'replaceUrl') out.push('url');
                });

                return out;
            };

            expect(steps('onSave')).toEqual(['emit:upload:template_settings', 'PUT /api/admin/templates/{{route.identifier}}/settings', 'refetch:template_settings']);
            const put = find(action('onSave'), (n) => n.handler === 'apiCall');
            expect(put!.params.body).toBe('{{Object.fromEntries([[_computed.activeSection, (_local.form ?? {})[_computed.activeSection] ?? {}]])}}');
            expect(steps('onReset')).toEqual(['POST /api/admin/templates/{{route.identifier}}/settings/reset', 'refetch:template_settings']);
            expect(steps('onTabChange')).toEqual(['url', 'refetch:template_settings']);
            expect(JSON.stringify(action('onTabChange'))).toContain('{{$args[1] === true}}');
            expect(action('onChange').params.form).toBe('{{$args[0]}}');
            expect(action('onChange').params.hasChanges).toBe(true);
            expect(JSON.stringify(action('onBack'))).toContain('/admin/templates');
        });

        // @scenario case=settings_layout
        // @effects settings_save_stops_on_upload_failure
        it(`${id} — 올리기가 하나라도 실패하면 PUT 을 보내지 않고 실패 문구를 남긴다(성공 안내로 덮지 않는다)`, () => {
            const screen = find(settingsLayout(id), (n) => n.id === 'template_settings_screen');
            const save = (screen!.actions as any[]).find((a) => a.event === 'onSave');
            const list: any[] = save.actions ?? save.params?.actions ?? [];
            const emit = list.findIndex((a) => a.handler === 'emitEvent');
            const count = list.findIndex((a, i) => i > emit && a.handler === 'setState' && typeof a.params?.uploadFailedCount === 'string');
            const put = list.findIndex((a) => a.handler === 'apiCall');

            // 지난 결과 안내는 올리기 전에 걷는다 — 올리기 실패 문구를 뒤에서 지우지 않게
            const clearIdx = list.findIndex((a) => a.handler === 'setState' && Object.prototype.hasOwnProperty.call(a.params ?? {}, 'saveStatus') && a.params.saveStatus === null);
            expect(clearIdx).toBeGreaterThanOrEqual(0);
            expect(clearIdx).toBeLessThan(emit);
            // 업로더 결과(`_eventResult.data[].failedCount`)를 세고, 실패가 있으면 PUT 을 건너뛴다
            expect(count).toBeGreaterThan(emit);
            expect(list[count].params.uploadFailedCount).toContain('failedCount');
            expect(put).toBeGreaterThan(count);
            expect(list[put].if).toBe('{{(_local.uploadFailedCount ?? 0) === 0}}');
            const stop = list.find((a, i) => i > count && i < put && a.handler === 'setState' && a.if === '{{(_local.uploadFailedCount ?? 0) > 0}}');
            expect(stop?.params?.saveStatus).toContain("type: 'error'");
            expect(stop?.params?.saveStatus).toContain('core.template_settings.upload_failed_save_stopped');
        });
    }
});

describe('템플릿 목록 더보기 「설정」 (#434)', () => {
    for (const tab of ['_tab_user', '_tab_admin']) {
        // @scenario case=settings_layout
        // @effects list_exposes_settings_route
        it(`${tab} 에 설정 항목과 이동 갈래가 있다`, () => {
            const json = fs.readFileSync(path.join(root, `layouts/partials/admin_template_list/${tab}.json`), 'utf-8');

            expect(json).toContain("id: 'settings', label: p.abilities?.can_update_settings === true ? '$t:admin.templates.actions.settings' : '$t:admin.templates.actions.settings_read_only'");
            expect(json).toContain("disabled: !p.has_settings || p.status === 'uninstalled' || ['installing', 'uninstalling', 'updating'].includes(p.status)");
            // 저장 권한이 없으면 자물쇠 아이콘·「읽기 전용」 라벨(S35), 설정 화면이 없어 잠기면 사유를 보인다(S28)
            expect(json).toContain("iconName: p.abilities?.can_update_settings === true ? 'cog' : 'lock'");
            expect(json).toContain("disabledReason: !p.has_settings && p.status !== 'uninstalled' ? '$t:admin.templates.actions.settings_unavailable' : null");
            const listLayout = JSON.parse(json);
            const navigate = find(listLayout, (n) => n.handler === 'navigate' && typeof n.params?.path === 'string' && n.params.path.includes('settings_route'));
            expect(navigate).not.toBeNull();
            expect(navigate!.params.fallbackPath).toBe('/admin/templates');
        });
    }
});
