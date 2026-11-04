/**
 * @file template-settings-layout.test.ts
 * @description 템플릿 설정 공용 화면(admin_template_settings)과 템플릿 목록 「설정」 메뉴를 잠근다 (#434).
 *
 * - 번들 4종은 직접 쓴 화면 없이 섹션 선언만 두고, 켜진 관리자 템플릿의 공용 화면이 그린다
 * - 조회 데이터소스가 응답의 settings 를 폼 값으로 넣고, 저장은 업로드 신호 → PUT 순서이며 읽기 전용이면 저장 바가 없다
 * - 칸은 합성 컴포넌트가 응답 sections 로 그린다 — 템플릿 언어 키를 화면에 박지 않는다
 * - 섹션은 모듈·코어 설정처럼 탭 하나씩 — 주소 `?tab=`, 변경이 있으면 확인 창, 저장은 지금 탭 섹션만
 * - 기본값으로는 확인 창 → POST reset → 다시 받기
 * - 목록 두 탭 더보기 메뉴에 「설정」이 있고 설정 화면이 없으면 흐리다
 *
 * @scenario case=settings_layout
 *
 * @effects settings_layout_saves_template_settings
 * @effects list_exposes_settings_route
 * @effects read_only_without_settings_permission
 * @effects settings_reset_to_defaults
 * @effects settings_tabs_per_section
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
const layout = JSON.parse(fs.readFileSync(path.join(root, 'layouts/admin_template_settings.json'), 'utf-8'));
const resetModal = JSON.parse(fs.readFileSync(path.join(root, 'layouts/partials/admin_template_settings/_modal_reset_confirm.json'), 'utf-8'));
const unsavedTabModal = JSON.parse(fs.readFileSync(path.join(root, 'layouts/partials/admin_template_settings/_modal_unsaved_tab_switch.json'), 'utf-8'));

describe('템플릿 설정 공용 화면 (#434)', () => {
    // @scenario case=settings_layout
    // @effects settings_layout_saves_template_settings
    it('번들 4종은 직접 쓴 화면 없이 섹션 선언만 두고 공용 화면이 그린다', () => {
        for (const id of ['sirsoft-admin_basic', 'sirsoft-basic', 'gnuboard7-hello_user_template', 'gnuboard7-hello_admin_template']) {
            const declaration = JSON.parse(fs.readFileSync(path.join(bundled, id, 'config/settings/defaults.json'), 'utf-8'));
            expect(declaration._meta.categories[0]).toBe('branding');
            expect(declaration.schema.branding.favicon).toMatchObject({ type: 'attachments', max_files: 1 });
            expect(declaration.frontend_schema.branding.fields.favicon_url.expose).toBe(true);
            expect(fs.existsSync(path.join(bundled, id, 'settings'))).toBe(false);
            expect(fs.existsSync(path.join(bundled, id, 'layouts/template_settings.json'))).toBe(false);
        }
    });

    // @scenario case=settings_layout
    // @effects read_only_without_settings_permission
    it('조회 응답의 settings 를 폼 값으로 넣고 읽기 전용 여부를 응답 권한에서 정한다', () => {
        const source = layout.data_sources.find((s: any) => s.id === 'template_settings');

        expect(layout.layout_name).toBe('admin_template_settings');
        expect(layout.extends).toBe('_admin_base');
        expect(source.endpoint).toBe('/api/admin/templates/{{route.identifier}}/settings');
        expect(source.initLocal).toEqual({ form: '{{data?.settings ?? null}}' });
        expect(layout.computed.isReadOnly).toBe('{{template_settings?.data?.abilities?.can_update !== true}}');
        expect(layout.permissions).toEqual(['core.templates.read']);
    });

    // @scenario case=settings_layout
    // @effects settings_layout_saves_template_settings
    it('칸은 합성 컴포넌트가 응답 sections 중 지금 탭 섹션으로 그리고 바뀐 폼 전체를 받는다', () => {
        const fields = find(layout, (n) => n.id === 'template_settings_fields');

        expect(fields!.name).toBe('TemplateSettingsFields');
        expect(fields!.props.sections).toBe('{{(template_settings?.data?.sections ?? []).filter(s => s.key === _computed.activeSection)}}');
        expect(fields!.props.value).toBe('{{_local.form ?? {}}}');
        expect(fields!.props.disabled).toBe('{{_computed.isReadOnly}}');
        expect(fields!.props.uploadTriggerEvent).toBe('upload:template_settings');
        const change = (fields!.actions as any[]).find((a) => a.event === 'onChange');
        expect(change.params.form).toBe('{{$args[0]}}');
        expect(change.params.hasChanges).toBe(true);
        expect((fields!.actions as any[]).some((a) => a.event === 'onDirty')).toBe(true);
    });

    // @scenario case=settings_layout
    // @effects settings_layout_saves_template_settings, read_only_without_settings_permission
    it('저장은 업로드 신호 → PUT 순서이고 읽기 전용이면 저장 바가 없다', () => {
        const footer = find(layout, (n) => n.id === 'footer_buttons');
        expect(footer!.if).toContain('!_computed.isReadOnly');

        const order: string[] = [];
        walk(find(layout, (n) => n.id === 'save_button'), (n) => {
            if (n.handler === 'emitEvent') order.push('emit:' + n.params.event);
            if (n.handler === 'apiCall') order.push(n.params.method + ' ' + n.target);
        });

        expect(order).toEqual(['emit:upload:template_settings', 'PUT /api/admin/templates/{{route.identifier}}/settings']);
    });

    // @scenario case=settings_layout
    // @effects settings_tabs_per_section
    it('섹션마다 탭 하나 — 주소 ?tab= 로 열고, 없는 섹션이면 첫 섹션, 섹션이 하나여도 탭 줄이 있고 본문은 tab-content 틀이다', () => {
        expect(layout.init_actions ?? layout.init).toBeDefined();
        expect(JSON.stringify(layout.init_actions ?? layout.init)).toContain('{{query.tab ?? null}}');
        expect(layout.computed.activeSection).toBe('{{(template_settings?.data?.sections ?? []).some(s => s.key === _local.activeTab) ? _local.activeTab : (template_settings?.data?.sections?.[0]?.key ?? null)}}');

        const tabs = find(layout, (n) => n.id === 'tab_navigation');
        expect(tabs!.name).toBe('TabNavigation');
        expect(tabs!.if).toBeUndefined();
        const content = find(layout, (n) => n.id === 'template_settings_tab_content');
        expect(content!.props.className).toBe('tab-content');
        expect(find(content, (n) => n.id === 'template_settings_fields')).not.toBeNull();
        expect(find(content, (n) => n.id === 'error_summary')).not.toBeNull();
        expect(tabs!.props.activeTabId).toBe('{{_computed.activeSection}}');
        expect(tabs!.props.tabs).toContain("'/settings?tab=' + s.key");
    });

    // @scenario case=settings_layout
    // @effects settings_tabs_per_section
    it('탭 전환 — 변경이 있으면 확인 창, 없으면 주소만 바꾸고(다시 받지 않는다) 저장은 지금 탭 섹션만 보낸다', () => {
        const change = find(find(layout, (n) => n.id === 'tab_navigation'), (n) => n.event === 'onTabChange');
        const text = JSON.stringify(change);
        expect(text).toContain('_local.hasChanges === true');
        expect(text).toContain('modal_template_settings_unsaved_tab');
        expect(text).toContain('"handler":"replaceUrl"');
        expect(text).not.toContain('refetchDataSource');
        expect(text).not.toContain('"handler":"navigate"');

        const save = find(find(layout, (n) => n.id === 'save_button'), (n) => n.handler === 'apiCall');
        expect(save!.params.body).toBe('{{Object.fromEntries([[_computed.activeSection, (_local.form ?? {})[_computed.activeSection] ?? {}]])}}');
    });

    // @scenario case=settings_layout
    // @effects settings_tabs_per_section
    it('확인 창 [저장하지 않고 이동] 은 두 자리 상태를 끄고 탭을 옮긴 뒤 설정을 다시 받는다, [머무르기] 는 창만 닫는다', () => {
        const leave = find(unsavedTabModal, (n) => n.id === 'template_settings_unsaved_tab_leave');
        const steps: string[] = [];
        walk(leave, (n) => {
            if (n.handler === 'setState') steps.push('set:' + n.params.target);
            if (n.handler === 'closeModal') steps.push('close');
            if (n.handler === 'replaceUrl') steps.push('url');
            if (n.handler === 'refetchDataSource') steps.push('refetch:' + n.params.dataSourceId);
        });
        expect(steps).toEqual(['set:global', 'set:$parent._local', 'close', 'url', 'set:global', 'refetch:template_settings']);

        const stay = JSON.stringify(find(unsavedTabModal, (n) => n.id === 'template_settings_unsaved_tab_stay'));
        expect(stay).toContain('closeModal');
        expect(stay).not.toContain('refetchDataSource');
        expect(JSON.stringify(layout)).toContain('_modal_unsaved_tab_switch');
    });

    // @scenario case=settings_layout
    // @effects settings_reset_to_defaults
    it('기본값으로는 되돌리기 권한이 있을 때만 보이고 확인 창에서 POST reset 뒤 다시 받는다', () => {
        const reset = find(layout, (n) => n.id === 'reset_button');
        expect(reset!.if).toBe('{{template_settings?.data?.abilities?.can_reset === true}}');
        expect(find(reset, (n) => n.handler === 'openModal')!.target ?? find(reset, (n) => n.handler === 'openModal')!.params?.target).toBe('template_settings_reset_modal');

        const calls: string[] = [];
        walk(resetModal, (n) => {
            if (n.handler === 'apiCall') calls.push(n.params.method + ' ' + n.target);
            if (n.handler === 'refetchDataSource') calls.push('refetch:' + n.params.dataSourceId);
        });
        expect(calls).toEqual(['POST /api/admin/templates/{{route.identifier}}/settings/reset', 'refetch:template_settings']);
    });

    // @scenario case=settings_layout
    // @effects settings_layout_saves_template_settings
    it('저장 실패 요약·켜진 템플릿만 「화면에서 보기」·화면 문구는 코어 키만 쓴다', () => {
        expect(find(layout, (n) => n.id === 'error_summary')!.if).toContain('_local.errors');
        expect(find(layout, (n) => n.id === 'view_site_row')!.if).toBe('{{template_settings?.data?.is_active === true}}');

        const text = JSON.stringify(layout);
        expect(text).not.toMatch(/\$t:settings\./);
        expect(text).toContain('$t:core.template_settings.error_summary');
    });

    // @scenario case=settings_layout
    // @effects settings_layout_saves_template_settings
    it('「화면에서 보기」는 템플릿 종류의 첫 화면을 새 창으로 연다', () => {
        const link = find(layout, (n) => n.id === 'view_site_link');

        expect(link!.name).toBe('A');
        expect(link!.props.href).toBe("{{template_settings?.data?.type === 'admin' ? '/admin' : '/'}}");
        expect(link!.props.target).toBe('_blank');
        expect(link!.props.rel).toBe('noopener noreferrer');
    });

    // @scenario case=settings_layout
    // @effects settings_layout_saves_template_settings
    it('꺼진 템플릿이면 「켜진 뒤 적용」 안내를 보인다 — 켜진 템플릿·응답 전에는 숨긴다', () => {
        const notice = find(layout, (n) => n.id === 'inactive_notice');

        expect(notice!.if).toBe('{{template_settings?.data?.is_active === false}}');
        expect(JSON.stringify(notice)).toContain('$t:core.template_settings.inactive_notice');
    });

    // @scenario case=settings_layout
    // @effects settings_layout_saves_template_settings
    it('고친 값이 있으면 다른 화면으로 나갈 때 이탈 경고를 띄우고, 취소 단추만 경고를 건너뛴다', () => {
        expect(layout.meta.leave_guard).toBe('{{_local.hasChanges === true}}');

        const cancel = JSON.stringify(find(layout, (n) => n.id === 'cancel_button'));
        expect(cancel).toContain('bypassLeaveGuard');
        expect(JSON.stringify(find(layout, (n) => n.id === 'save_button'))).not.toContain('bypassLeaveGuard');
    });
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
