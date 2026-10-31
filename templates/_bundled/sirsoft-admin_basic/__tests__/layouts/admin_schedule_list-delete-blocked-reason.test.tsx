/**
 * @file admin_schedule_list-delete-blocked-reason.test.tsx
 * @description 스케줄 관리 행 메뉴 「삭제」 아래 안내 — 막힌 까닭(권한 없음·코어·확장 소유)에 맞는 문구가 행마다 나온다
 *
 * 결함: 「스케줄 조회」 권한만 있는 관리자가 직접 등록한 작업의 행 메뉴를 열면 삭제가 막혀 있는데, 안내가
 * 「직접 등록한 작업만 삭제할 수 있습니다.」로 나왔다. 행의 `abilities.can_delete` 가 권한과 소유 잠김을 한 값으로
 * 합치고, 레이아웃은 그 값 하나에 고정 문구 하나만 붙였기 때문이다.
 *
 * 고침: 행이 `delete_blocked_reason`(owned·permission·null)을 싣고, 삭제 항목의 안내는 그 값을 행마다 읽는 식이다.
 * DataGrid 는 `disabledReason` 을 엔진 선평가에서 빼고(skipBindingKeys) 행마다 푼다 — 행 메뉴 주소(href)와 같은 방식.
 *
 * 렌더 방식(두 단계, 실제 배포 JSON):
 *  1) 엔진 하네스(createLayoutTest)가 배포된 `_partial_schedule_datagrid.json` 의 DataGrid 노드를 그대로 그린다.
 *     DataGrid 자리에는 components.json 의 실제 메타데이터(skipBindingKeys)를 단 수집기를 두어, 엔진이 넘기는 props 를
 *     그대로 받는다 — 엔진이 행 없이 식을 미리 풀어 버리는지가 여기서 드러난다.
 *  2) 받은 props 로 **실제 DataGrid** 를 그리고 행 메뉴를 열어 「삭제」 항목 안내를 읽는다.
 *  하네스의 렌더러(저장소 루트 React)와 이 템플릿의 React 사본이 갈리는 환경에서 실제 DataGrid 의 훅이 돌지 않으므로
 *  (admin-settings-ip-rule-modal-tag-menu.test.tsx 참고) 실제 컴포넌트는 2단계에서 그린다. 2단계에서는 셀 그리기(cellChildren —
 *  엔진의 renderItemChildren 필요)를 이름 열 하나로 줄인다. 행 메뉴(rowActions)는 엔진이 넘긴 값 그대로다.
 *
 * 시나리오 매니페스트: schedule-admin-screen (tests/scenarios — test_files 귀속)
 * @axes case=delete_blocked_reason
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
// 엔진(코어)은 저장소 루트의 react 를, 이 확장의 부품은 확장 node_modules 의 react 를 읽어 사본이 둘이 된다 — 훅을 쓰는
// 실제 부품을 엔진 아래에서 그리면 「Invalid hook call」 이 난다. 설정에서 전역으로 합치면 다른 레이아웃 시험이 깨지므로
// 이 파일에서만 확장 쪽 react 를 루트 사본으로 바꿔 낀다(templates/_bundled/sirsoft-admin_basic/src/__tests__/layouts/shippingPolicyFormLabelNames.test.tsx 와 같은 방식)
vi.mock('react', async () => {
    const p = await import('path');
    const u = await import('url');
    const dir = p.dirname(u.fileURLToPath(import.meta.url));

    return import(/* @vite-ignore */ u.pathToFileURL(p.resolve(dir, '../../../../../node_modules/react/index.js')).href);
});
// 2단계(실제 DataGrid)를 그리는 렌더러도 같은 루트 사본이어야 하므로 testing-library 도 루트 사본으로 바꿔 낀다.
vi.mock('@testing-library/react', async () => {
    const p = await import('path');
    const u = await import('url');
    const dir = p.dirname(u.fileURLToPath(import.meta.url));

    return import(/* @vite-ignore */ u.pathToFileURL(p.resolve(dir, '../../../../../node_modules/@testing-library/react/dist/index.js')).href);
});

import { render, fireEvent, within, cleanup } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { DataGrid } from '../../src/components/composite/DataGrid';

const datagridPartial = require('../../layouts/partials/admin_schedule_list/_partial_schedule_datagrid.json');
const componentsMeta = require('../../components.json');
const koAdmin = require('../../lang/partial/ko/admin.json');
const koAuth = require('../../lang/partial/ko/auth.json');
const koCommon = require('../../lang/partial/ko/common.json');

/** id 로 노드 하나를 찾습니다. */
function byId(node: any, id: string): any {
    if (!node || typeof node !== 'object') return null;
    if (Array.isArray(node)) {
        for (const child of node) {
            const found = byId(child, id);
            if (found) return found;
        }
        return null;
    }
    if (node.id === id) return node;
    for (const value of Object.values(node)) {
        const found = byId(value, id);
        if (found) return found;
    }
    return null;
}

const OWNED_TEXT = koAdmin.schedules.action.delete_disabled_reason;
const PERMISSION_TEXT = koAuth.permission_denied;

/** 목록 응답 행 — 서버(ScheduleResource)가 싣는 모양 */
function row(id: number, label: string, fields: Record<string, any>): Record<string, any> {
    return {
        id,
        name: `task-${id}`,
        label_localized: label,
        is_owned: false,
        abilities: { can_create: false, can_update: false, can_delete: false, can_run: false },
        delete_blocked_reason: 'permission',
        ...fields,
    };
}

/** 조회 권한만 있는 관리자의 목록 — 직접 등록 행(권한 없음) + 코어 소유 행 */
const READ_ONLY_ROWS = [
    row(1, '직접 등록 작업', { delete_blocked_reason: 'permission' }),
    row(2, '코어 작업', { is_owned: true, delete_blocked_reason: 'owned' }),
];

/** 삭제 권한이 있는 관리자의 목록 — 직접 등록 행(삭제 가능) + 코어 소유 행 */
const DELETER_ROWS = [
    row(1, '직접 등록 작업', { abilities: { can_create: true, can_update: true, can_delete: true, can_run: true }, delete_blocked_reason: null }),
    row(2, '코어 작업', { is_owned: true, abilities: { can_create: true, can_update: true, can_delete: false, can_run: true }, delete_blocked_reason: 'owned' }),
];

const gridMeta = [
    ...(componentsMeta.components?.basic ?? []),
    ...(componentsMeta.components?.composite ?? []),
    ...(componentsMeta.components?.layout ?? []),
].find((c: any) => c.name === 'DataGrid');

/**
 * 1단계: 배포 JSON 의 DataGrid 노드를 엔진으로 그려, 엔진이 DataGrid 에 넘기는 props 를 받습니다.
 *
 * @param rows 목록 응답 행
 * @returns 엔진이 넘긴 props 와 하네스
 */
async function engineProps(rows: any[]): Promise<{ props: any; harness: ReturnType<typeof createLayoutTest> }> {
    const captured: { props: any } = { props: null };
    const registry: any = ComponentRegistry.getInstance();
    registry.registry = {
        DataGrid: {
            component: (props: any) => {
                captured.props = props;
                return null;
            },
            metadata: gridMeta,
        },
        // 하네스의 뿌리 노드가 Fragment 다
        Fragment: { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
    };

    const grid = JSON.parse(JSON.stringify(byId(datagridPartial, 'schedules_data_grid')));
    const probe = {
        version: '1.0.0',
        layout_name: 'schedule_delete_reason_probe',
        data_sources: [{ id: 'schedules', type: 'api', endpoint: '/api/admin/schedules', method: 'GET', auto_fetch: true }],
        components: [grid],
    };
    const harness = createLayoutTest(probe as any, {
        componentRegistry: registry,
        translations: { admin: koAdmin, auth: koAuth, common: koCommon },
        locale: 'ko',
    });
    harness.mockApi('schedules', {
        response: { data: { data: rows, abilities: { can_create: false, can_update: false, can_delete: false, can_run: false }, pagination: { current_page: 1, last_page: 1 } } },
    });
    await harness.render();

    return { props: captured.props, harness };
}

/**
 * 2단계: 엔진이 넘긴 props 로 실제 DataGrid 를 그리고, 라벨이 같은 행의 메뉴를 열어 「삭제」 항목을 돌려줍니다.
 *
 * @param props 엔진이 넘긴 props
 * @param label 행 이름
 * @param mobile 좁은 화면(카드 보기)으로 그릴지
 * @returns 「삭제」 메뉴 항목 요소
 */
function openDeleteItem(props: any, label: string, mobile = false): HTMLElement {
    const { container } = render(
        <DataGrid
            columns={[{ field: 'label_localized', header: '작업' }]}
            data={props.data}
            idField={props.idField}
            rowActions={props.rowActions}
            actionsColumnHeader={props.actionsColumnHeader}
            responsiveBreakpoint={mobile ? 100000 : 0}
        />,
    );

    const holder = Array.from(container.querySelectorAll<HTMLElement>(mobile ? 'div.rounded-lg.border' : 'tbody tr')).find((el) =>
        (el.textContent ?? '').includes(label),
    );
    expect(holder, `${label} 행`).toBeTruthy();
    const trigger = holder!.querySelector<HTMLElement>('[aria-haspopup="menu"]');
    expect(trigger, `${label} 행 메뉴 단추`).toBeTruthy();
    fireEvent.click(trigger!);

    const menu = document.body.querySelector<HTMLElement>('[role="menu"]') ?? document.body;
    const item = within(menu)
        .getAllByRole('menuitem')
        .find((el) => (el.textContent ?? '').startsWith(koAdmin.schedules.action.delete));
    expect(item, '삭제 항목').toBeTruthy();

    return item!;
}

describe('스케줄 행 메뉴 「삭제」 안내는 막힌 까닭을 따른다 — 실제 엔진·DataGrid 렌더', () => {
    let savedCore: any;
    let savedApp: any;
    let harness: ReturnType<typeof createLayoutTest> | undefined;

    beforeEach(() => {
        savedCore = (window as any).G7Core;
        savedApp = (window as any).__templateApp;
    });

    afterEach(() => {
        cleanup();
        harness?.cleanup();
        harness = undefined;
        (window as any).G7Core = savedCore;
        (window as any).__templateApp = savedApp;
        (ComponentRegistry.getInstance() as any).registry = {};
    });

    /** 실제 앱처럼 행 해석기(바인딩 엔진)와 화면 언어를 노출합니다. */
    function exposeRuntime(): void {
        const engine = new DataBindingEngine();
        (window as any).G7Core = { ...((window as any).G7Core ?? {}), getDataBindingEngine: () => engine };
        (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale: 'ko' }) };
    }

    // @scenario case=delete_blocked_reason
    // @effects schedule_delete_blocked_reason_splits_permission_and_owner
    it('조회 권한만 있는 관리자: 직접 등록 행은 권한 안내, 코어 소유 행은 「직접 등록한 작업만…」', async () => {
        const result = await engineProps(READ_ONLY_ROWS);
        harness = result.harness;
        exposeRuntime();

        const custom = openDeleteItem(result.props, '직접 등록 작업');
        expect(custom).toHaveAttribute('aria-disabled', 'true');
        expect(custom.textContent).toContain(PERMISSION_TEXT);
        expect(custom.textContent).not.toContain(OWNED_TEXT);
        cleanup();

        const owned = openDeleteItem(result.props, '코어 작업');
        expect(owned).toHaveAttribute('aria-disabled', 'true');
        expect(owned.textContent).toContain(OWNED_TEXT);
    });

    // @scenario case=delete_blocked_reason
    // @effects schedule_delete_blocked_reason_splits_permission_and_owner
    it('삭제 권한이 있는 관리자: 직접 등록 행은 열려 있고 안내가 없으며, 코어 소유 행은 「직접 등록한 작업만…」', async () => {
        const result = await engineProps(DELETER_ROWS);
        harness = result.harness;
        exposeRuntime();

        const custom = openDeleteItem(result.props, '직접 등록 작업');
        expect(custom).not.toHaveAttribute('aria-disabled', 'true');
        expect(custom.textContent).toBe(koAdmin.schedules.action.delete);
        cleanup();

        const owned = openDeleteItem(result.props, '코어 작업');
        expect(owned.textContent).toContain(OWNED_TEXT);
    });

    // @scenario case=delete_blocked_reason
    // @effects schedule_delete_blocked_reason_splits_permission_and_owner
    it('좁은 화면(카드 보기) 행 메뉴도 같은 안내를 보인다', async () => {
        const result = await engineProps(READ_ONLY_ROWS);
        harness = result.harness;
        exposeRuntime();

        const custom = openDeleteItem(result.props, '직접 등록 작업', true);
        expect(custom.textContent).toContain(PERMISSION_TEXT);
        expect(custom.textContent).not.toContain(OWNED_TEXT);
    });

    // @scenario case=delete_blocked_reason
    // @effects schedule_delete_blocked_reason_splits_permission_and_owner
    it('엔진은 행 안내 식을 행 없이 미리 풀지 않고 원문 그대로 넘긴다', async () => {
        const result = await engineProps(READ_ONLY_ROWS);
        harness = result.harness;

        const del = (result.props.rowActions ?? []).find((a: any) => a.id === 'delete');
        expect(del?.disabledField).toBe('abilities.can_delete');
        expect(typeof del?.disabledReason).toBe('string');
        expect(del.disabledReason).toContain('row.delete_blocked_reason');
    });
});
