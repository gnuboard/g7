/**
 * @file admin-settings-system-tab.test.tsx
 * @description 환경설정 「시스템」 탭(W5)의 레이아웃 렌더링 테스트
 *
 * 레이아웃 JSON 을 **파일에서 그대로 읽어** 렌더한다. 테스트 안에 구조를 베껴 두면
 * 원본이 바뀌어도 사본만 계속 통과해, 화면이 깨진 채로 초록이 유지된다.
 *
 * 이 계층이 잡는 것:
 *
 * 1. **호출 주소가 기본으로 가려져 있다 (D15).** 화면 최초 로드 응답에는 번호가 실리지
 *    않으므로, 가림 상태에서 진짜 주소가 보이면 그것은 어딘가에서 새어 나온 값이다.
 * 2. **[보기]·[복사]·[번호 재발급]이 읽기 전용 사용자에게 보이지 않는다.** 그 번호는
 *    인증 없는 실행 자격증명이라 서버가 권한을 요구하는데, 화면이 버튼을 계속 보여
 *    주면 운영자는 눌러 보고 403 만 받는다.
 * 3. **판정 근거가 실제로 렌더된다.** 차단된 함수 목록과 PHP 실행 파일 확인 결과는
 *    "왜 이 모드인가" 를 설명하는 유일한 통로다.
 * 4. **탭 조건이 성립한다.** 다른 탭에서 이 트리가 렌더되면 화면이 겹친다.
 *
 * @scenario vector=browser_reveal, actor_permission=settings_read, actor_permission=settings_update
 * @axes vector=browser_reveal, actor_permission=settings_read, actor_permission=settings_update
 * @effects cron_url_is_masked_until_revealed, execution_reason_is_shown_on_screen
 */

import React from 'react';
import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { ActionDispatcher } from '@core/template-engine/ActionDispatcher';
import * as fs from 'fs';
import * as path from 'path';

const PARTIAL_PATH = path.resolve(
    __dirname,
    '../../layouts/partials/admin_settings/_tab_system.json',
);

const MODAL_PATH = path.resolve(
    __dirname,
    '../../layouts/partials/admin_settings/_modal_cron_token_rotate.json',
);

const SETTINGS_LAYOUT_PATH = path.resolve(
    __dirname,
    '../../layouts/admin_settings.json',
);

/** 실제 레이아웃 파일을 읽어 온다 (사본 금지 — 원본이 SSoT). */
function loadPartial(fullPath: string): any {
    return JSON.parse(fs.readFileSync(fullPath, 'utf-8'));
}

const TestDiv: React.FC<any> = ({ className, children, 'data-testid': testId, id }) => (
    <div className={className} data-testid={testId} id={id}>{children}</div>
);

const TestSpan: React.FC<any> = ({ className, children, text }) => (
    <span className={className}>{children || text}</span>
);

const TestP: React.FC<any> = ({ className, children, text, id }) => (
    <p className={className} data-testid={id}>{children || text}</p>
);

const TestH3: React.FC<any> = ({ className, children, text }) => (
    <h3 className={className}>{children || text}</h3>
);

const TestLabel: React.FC<any> = ({ className, children, text }) => (
    <label className={className}>{children || text}</label>
);

const TestButton: React.FC<any> = ({ className, type, children, text, onClick, id }) => (
    <button className={className} type={type} onClick={onClick} data-testid={id}>{children || text}</button>
);

const TestInput: React.FC<any> = ({ name, value, type, id, readOnly }) => (
    <input name={name} value={value ?? ''} type={type} readOnly={readOnly} data-testid={id ?? `input-${name}`} onChange={() => undefined} />
);

const TestTextarea: React.FC<any> = ({ name, value }) => (
    <textarea name={name} value={value ?? ''} data-testid={`textarea-${name}`} onChange={() => undefined} />
);

const TestIcon: React.FC<any> = ({ name, className }) => (
    <i className={className} data-icon={name} />
);

const TestRadioGroup: React.FC<any> = ({ name, options, value }) => (
    <div data-testid={`radio-${name}`} data-value={value ?? ''}>
        {(options ?? []).map((o: any) => (
            <label key={o.value}>
                <input type="radio" name={name} value={o.value} readOnly checked={String(value) === String(o.value)} />
                {o.label}
            </label>
        ))}
    </div>
);

const TestToggle: React.FC<any> = ({ name, checked }) => (
    <input type="checkbox" name={name} checked={!!checked} readOnly data-testid={`toggle-${name}`} />
);

const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

/**
 * 레이아웃 테스트용 컴포넌트 레지스트리.
 *
 * Fragment 를 빠뜨리면 자식 트리가 통째로 렌더되지 않아 부정 단언이 거짓 통과한다.
 */
function setupTestRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();

    (registry as any).registry = {
        Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
        Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
        P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
        H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
        Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
        Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
        Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
        Textarea: { component: TestTextarea, metadata: { name: 'Textarea', type: 'basic' } },
        Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
        RadioGroup: { component: TestRadioGroup, metadata: { name: 'RadioGroup', type: 'composite' } },
        Toggle: { component: TestToggle, metadata: { name: 'Toggle', type: 'composite' } },
        Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    };

    return registry;
}

/** 서버 상태 응답의 최소 형태 (실제 응답과 같은 경로) */
const STATUS_PAYLOAD = {
    execution: {
        effective_mode: 'inprocess',
        configured_mode: 'auto',
        detection: {
            mode: 'inprocess',
            blocked_functions: ['exec', 'proc_open'],
            php_probe: {
                status: 'unavailable',
                message_key: 'php_probe_exec_unavailable',
                message_localized: 'PHP 실행 파일을 확인하지 못했습니다.',
            },
        },
    },
    cron: {
        mode: 'auto',
        min_interval_minutes: 5,
        visit_fallback_enabled: false,
        token_issued: true,
        last_run: null,
        stale: false,
    },
};

/**
 * 시스템 탭 partial 을 렌더 가능한 레이아웃으로 감싼다.
 *
 * 읽기 전용 판정식은 부모 레이아웃(admin_settings.json)에서 **그대로 읽어 온다** —
 * 여기에 베껴 두면 부모의 판정이 바뀌어도 이 테스트만 계속 통과한다.
 */
function buildLayout(): any {
    return {
        version: '1.0',
        layout_name: 'test_system_tab',
        computed: loadPartial(SETTINGS_LAYOUT_PATH).computed,
        components: [loadPartial(PARTIAL_PATH)],
    };
}

/**
 * 환경설정을 수정할 수 있는 사용자의 전역 상태.
 *
 * 부모 레이아웃(admin_settings.json)의 읽기 전용 판정식이 이 값을 소비한다.
 * 읽기 전용 쪽은 렌더 계층에서 잴 수 없어 구조 계약으로 잠근다 (아래 참조).
 */
function editableUser(): Record<string, any> {
    return { settingsAbilities: { can_update: true } };
}

let active: ReturnType<typeof createLayoutTest> | null = null;

afterEach(() => {
    active?.cleanup();
    active = null;
});

describe('환경설정 시스템 탭 (W5)', () => {
    // @scenario vector=browser_reveal
    // @effects execution_reason_is_shown_on_screen
    it('시스템 탭이 활성일 때 두 카드가 렌더된다', async () => {
        active = createLayoutTest(buildLayout(), {
            componentRegistry: setupTestRegistry(),
            queryParams: { tab: 'system' },
            initialState: { _global: editableUser() },
            initialData: { system_status: { data: STATUS_PAYLOAD } },
        });

        await active.render();

        expect(screen.getByTestId('radio-system.execution_mode')).toBeInTheDocument();
        expect(screen.getByTestId('radio-system.cron_mode')).toBeInTheDocument();
    });

    /** @effects system_tab_tree_renders_only_on_the_system_tab */
    it('다른 탭에서는 이 트리가 렌더되지 않는다', async () => {
        active = createLayoutTest(buildLayout(), {
            componentRegistry: setupTestRegistry(),
            queryParams: { tab: 'general' },
            initialState: { _global: editableUser() },
            initialData: { system_status: { data: STATUS_PAYLOAD } },
        });

        await active.render();

        expect(screen.queryByTestId('radio-system.execution_mode')).not.toBeInTheDocument();
    });

    // @scenario vector=browser_detect
    // @effects execution_reason_is_shown_on_screen
    it('판정 근거(차단 함수 · PHP 확인 결과)가 화면에 나타난다', async () => {
        active = createLayoutTest(buildLayout(), {
            componentRegistry: setupTestRegistry(),
            queryParams: { tab: 'system' },
            initialState: { _global: editableUser() },
            initialData: { system_status: { data: STATUS_PAYLOAD } },
        });

        const { container } = await active.render();
        const text = container.textContent ?? '';

        expect(text).toContain('exec, proc_open');
        expect(text).toContain('PHP 실행 파일을 확인하지 못했습니다.');
    });

    // @scenario vector=browser_reveal, token=valid
    // @effects cron_url_is_masked_until_revealed
    it('호출 주소는 기본으로 가려져 있고 실제 주소가 노출되지 않는다 (D15)', async () => {
        active = createLayoutTest(buildLayout(), {
            componentRegistry: setupTestRegistry(),
            queryParams: { tab: 'system' },
            initialState: { _global: editableUser() },
            initialData: { system_status: { data: STATUS_PAYLOAD } },
        });

        const { container } = await active.render();

        const input = screen.getByTestId('cron_url_value') as HTMLInputElement;

        expect(input.value).not.toContain('token=');
        expect(container.textContent ?? '').not.toContain('/api/system/cron/run?token=');
    });

    // @scenario vector=browser_reveal, token=valid
    // @effects cron_url_is_fetched_on_demand
    it('[보기] 를 눌러 받은 주소가 입력란에 반영된다', async () => {
        active = createLayoutTest(buildLayout(), {
            componentRegistry: setupTestRegistry(),
            queryParams: { tab: 'system' },
            initialState: { _global: editableUser() },
            initialData: { system_status: { data: STATUS_PAYLOAD } },
        });

        await active.render();

        active.setState('cronUrl', 'https://example.test/api/system/cron/run?token=abc', 'local');
        await active.rerender();

        const input = screen.getByTestId('cron_url_value') as HTMLInputElement;

        expect(input.value).toContain('token=abc');
    });

    // @scenario stale_state=never_run
    // @effects system_tab_shows_the_never_run_notice
    it('마지막 실행 기록이 없으면 그 사실을 알리는 문구가 나온다', async () => {
        active = createLayoutTest(buildLayout(), {
            componentRegistry: setupTestRegistry(),
            queryParams: { tab: 'system' },
            initialState: { _global: editableUser() },
            initialData: { system_status: { data: STATUS_PAYLOAD } },
        });

        await active.render();

        expect(screen.getByTestId('cron_last_run_empty')).toBeInTheDocument();
    });

    // 큐로 보낸 작업은 워커가 끝내기 전까지 이력이 실행 중(`running`)으로 남는다. 성공·실패 두 갈래로만
    // 그리면 아직 도는 작업이 「성공」으로 보인다.
    // @scenario event_kind=callback_event
    // @effects recent_history_shows_a_running_entry_as_running
    it('실행 이력의 실행 중 항목은 성공이 아니라 실행 중으로 나온다', async () => {
        const entry = (id: number, name: string, status: string) => ({
            id,
            name,
            expression: '0 2 * * *',
            status,
            trigger_type: 'manual',
            started_at: '2026-11-04T01:38:56+09:00',
            duration: 0,
            error_output: null,
        });

        active = createLayoutTest(buildLayout(), {
            componentRegistry: setupTestRegistry(),
            queryParams: { tab: 'system' },
            initialState: { _global: editableUser() },
            initialData: {
                system_status: {
                    data: {
                        ...STATUS_PAYLOAD,
                        cron: {
                            ...STATUS_PAYLOAD.cron,
                            recent_history: [
                                entry(3, '사이트맵 생성', 'running'),
                                entry(2, '내보내기 파일 정리', 'failed'),
                                entry(1, 'SSO 이력 보관 정리', 'success'),
                            ],
                        },
                    },
                },
            },
        });

        const { container } = await active.render();
        const statusOf = (name: string): HTMLElement => {
            const row = Array.from(container.querySelectorAll('p')).find((p) => p.textContent === name)?.closest('div')?.parentElement;
            const spans = Array.from(row?.querySelectorAll(':scope > span') ?? []) as HTMLElement[];

            return spans[spans.length - 1];
        };

        const running = statusOf('사이트맵 생성');
        const failed = statusOf('내보내기 파일 정리');
        const success = statusOf('SSO 이력 보관 정리');

        expect(running.textContent).toContain('admin.schedules.result.running');
        expect(running.className).toContain('text-yellow-600');
        expect(failed.textContent).toContain('admin.settings.system.cron_history_failed');
        expect(failed.className).toContain('text-red-600');
        expect(success.textContent).toContain('admin.settings.system.cron_history_success');
        expect(success.className).toContain('text-green-600');
    });
});

/**
 * [복사] 의 결과 분기 (D8).
 *
 * 복사는 주소를 받아 온 **뒤**(`apiCall` 성공 블록 안)에서 일어나, 누른 순간의 사용자 동작 허가가 끝났을 수 있다.
 * 그러면 `navigator.clipboard` 도 폴백 `execCommand('copy')` 도 실패하고 핸들러는 false 를 돌려준다. 화면이 그 값을
 * 보지 않고 성공 토스트를 띄우면 운영자는 빈 클립보드를 호스팅 크론 칸에 붙여넣는다.
 *
 * 굽기 산출 JSON 의 클릭 액션을 엔진(`ActionDispatcher`)에 그대로 넣고, 핸들러 결과만 바꿔 토스트를 본다.
 */
describe('[복사] 결과 분기 (D8)', () => {
    const URL_RESPONSE = { success: true, data: { url: 'https://example.test/api/system/cron/run?token=abc' } };

    let dispatcher: ActionDispatcher;
    let global: Record<string, any>;
    let copied: string[];

    beforeEach(() => {
        global = {};
        copied = [];
        dispatcher = new ActionDispatcher({ navigate: vi.fn() });
        dispatcher.setGlobalStateUpdater((updates: any) => {
            global = { ...global, ...updates };
        });
        (window as any).G7Core = { ...(window as any).G7Core, state: { get: () => global } };
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true,
            status: 200,
            headers: new Headers({ 'Content-Type': 'application/json' }),
            json: async () => URL_RESPONSE,
            text: async () => JSON.stringify(URL_RESPONSE),
        })));
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    /** 복사 버튼의 클릭 액션을 핸들러 결과 `outcome` 으로 실행하고 쌓인 토스트를 돌려준다. */
    async function clickCopy(outcome: boolean): Promise<Array<{ type: string; message: string }>> {
        dispatcher.registerHandler('copyToClipboard', async (action: any) => {
            copied.push(action?.params?.text);

            return outcome;
        });

        const button = findById(loadPartial(PARTIAL_PATH), 'cron_url_copy_button');
        expect(button, 'cron_url_copy_button 이 레이아웃에 없습니다.').not.toBeNull();

        const local: Record<string, any> = {};
        await dispatcher.dispatchAction(button.actions[0], {
            data: { _local: local, _global: global },
            state: local,
            setState: (updates: any) => Object.assign(local, updates),
        } as any);

        return (global.toasts ?? []).map((t: any) => ({ type: t.type, message: String(t.message) }));
    }

    // @scenario vector=browser_reveal, token=valid
    // @effects cron_url_copy_reports_clipboard_failure
    it('복사에 실패하면 성공 토스트 대신 실패 토스트를 띄운다', async () => {
        const toasts = await clickCopy(false);

        expect(copied, '받아 온 주소로 복사를 시도해야 한다').toEqual([URL_RESPONSE.data.url]);
        expect(toasts.filter((t) => t.type === 'success'), '복사하지 못했는데 성공을 알린다').toEqual([]);
        expect(toasts.some((t) => t.type === 'error')).toBe(true);
    });

    // @scenario vector=browser_reveal, token=valid
    // @effects cron_url_copy_reports_clipboard_failure
    it('복사에 성공하면 성공 토스트만 띄운다', async () => {
        const toasts = await clickCopy(true);

        expect(toasts).toEqual([{ type: 'success', message: '$t:admin.settings.system.cron_url_copied' }]);
    });
});

describe('호출 번호 재발급 확인 모달', () => {
    // @scenario token=rotated
    // @effects token_rotate_modal_warns_and_calls_the_rotate_api
    it('경고 문구와 재발급 호출을 함께 갖는다', () => {
        const modal = loadPartial(MODAL_PATH);
        const serialized = JSON.stringify(modal);

        expect(modal.id).toBe('cron_token_rotate_modal');
        expect(modal.name).toBe('Modal');
        expect(serialized).toContain('cron_token_rotate_warning');
        expect(serialized).toContain('/api/admin/settings/system/cron-token/rotate');
    });

    /**
     * 재발급 성공 시 **부모 화면의** 주소칸을 갱신한다.
     *
     * 이 모달은 modals 섹션(포털) 소속이라 `target: 'local'` 은 모달 자신의 `_local` 로
     * 간다 — 부모의 `_local.cronUrl` 은 그대로 남는다. 그러면 재발급 뒤에도 **무효가 된
     * 옛 주소**가 화면에 남아, 운영자가 그것을 복사해 호스팅에 등록하고 예약 작업은
     * 계속 멈춘 채로 남는다. 오류도 토스트도 나지 않아 화면만 보면 성공으로 보인다.
     */
    // @scenario token=rotated
    // @effects token_rotate_modal_refreshes_the_parent_call_url
    it('주소칸 갱신을 부모 스코프에 쓴다', () => {
        const modal = loadPartial(MODAL_PATH);
        const serialized = JSON.stringify(modal);

        const writes = [...serialized.matchAll(/"target":"([^"]*)"[^}]*"cronUrl"/g)].map((m) => m[1]);

        expect(writes.length).toBeGreaterThan(0);

        for (const target of writes) {
            expect(target).toBe('$parent._local');
        }
    });
});

describe('대시보드 예약 미실행 경고 (D19 · D30)', () => {
    const DASHBOARD_PATH = path.resolve(__dirname, '../../layouts/admin_dashboard.json');

    /**
     * 경고가 **경고 색**으로 렌더된다.
     *
     * 종전에는 화면이 경고 종류를 **이름으로 하나씩** 알아보는 구조여서, 목록에 없는 새
     * 경고는 회색 중립 스타일로 떨어졌다 — 예약이 멈춘 사이트의 경고가 일반 정보와 같은
     * 무게로 보여 운영자가 지나친다. 그 열거 구조 자체가 결함으로 판정되어(공개 #124 W10)
     * 스타일 판정이 **심각도(`alert.type`)** 기준으로 바뀌었고, 이 알림은 리스너가
     * `type => 'warning'` 을 실어 보내므로 이름이 목록에 없어도 amber 로 나온다.
     *
     * 그래서 여기서는 이름이 아니라 **판정 기준**을 잠근다 — 이름 열거로 되돌아가면
     * 그 순간 목록에 없는 다음 경고가 다시 회색으로 묻히기 때문이다. 실제로 이 알림이
     * amber 로 렌더되는지는 `admin-dashboard-alert-severity.test.tsx` 가 렌더로 재고,
     * 리스너가 경고 심각도를 싣는지는 `ScheduleHealthAlertListenerTest` 가 잰다.
     */
    // @scenario stale_state=stale
    // @effects dashboard_alert_color_follows_severity_not_name
    it('경고 색 분기가 이름 열거가 아니라 심각도를 본다', () => {
        const serialized = JSON.stringify(loadPartial(DASHBOARD_PATH));

        // 정적 amber 클래스(상단 배너 틀·버튼)는 판정 대상이 아니다 — 알림마다 색을
        // 고르는 것은 `alert.*` 를 읽는 **조건식**뿐이다.
        const branches = [...serialized.matchAll(/[^"]*amber-50[^"]*/g)]
            .map((m) => m[0])
            .filter((b) => b.includes('alert.'));

        expect(branches.length).toBeGreaterThan(0);
        expect(branches.every((b) => b.includes("alert.type === 'warning'"))).toBe(true);
        expect(branches.some((b) => b.includes('alert.subtype'))).toBe(false);
    });

    /**
     * 리스너가 실어 보내는 조치 링크(`action_path`)를 화면이 **읽는다**.
     *
     * 읽는 노드가 없으면 경고 문구만 뜨고 조치 화면으로 가는 길이 없어, 운영자가
     * 환경설정의 어느 탭인지를 스스로 찾아야 한다.
     */
    // @scenario stale_state=stale
    // @effects alert_carries_the_settings_path
    it('action_path 를 읽는 노드가 있다', () => {
        const serialized = JSON.stringify(loadPartial(DASHBOARD_PATH));

        expect(serialized).toContain('alert.action_path');
        expect(serialized).toContain('alert.action_label');
    });
});

describe('레이아웃 구조 계약', () => {
    // @scenario setting_save_path=bulk
    // @effects system_tab_save_persists_to_the_catalog
    it('입력 필드가 시스템 카테고리 이름으로 폼에 묶인다', () => {
        const serialized = JSON.stringify(loadPartial(PARTIAL_PATH));

        for (const name of [
            'system.execution_mode',
            'system.cron_mode',
            'system.cron_allowed_ips',
            'system.cron_min_interval',
            'system.cron_visit_fallback',
        ]) {
            expect(serialized).toContain(`"${name}"`);
        }
    });

    // @scenario actor_permission=settings_read, actor_permission=settings_update
    // @effects token_reveal_requires_update_permission
    it('번호를 다루는 버튼 전부가 읽기 전용 게이트를 갖는다', () => {
        // 이 축은 렌더 계층에서 잴 수 없다 — `_computed` 는 부모 레이아웃
        // (admin_settings.json)이 소유하는데, 테스트 하네스는 partial 만 렌더할 때
        // 그 값을 평가하지 않아 어떤 사용자로 렌더해도 게이트가 언제나 거짓이 된다.
        // 그래서 게이트의 **존재**를 구조로 잠근다. 부모의 판정식 자체는 그 레이아웃의
        // 테스트가 따로 잰다.
        const partial = loadPartial(PARTIAL_PATH);

        for (const id of [
            'cron_url_reveal_button',
            'cron_url_copy_button',
            'cron_token_rotate_button',
            'cron_run_now_button',
        ]) {
            const node = findById(partial, id);

            expect(node, `${id} 가 레이아웃에 없습니다.`).not.toBeNull();
            expect(node.if, `${id} 에 읽기 전용 게이트가 없습니다.`).toContain('!_computed.isReadOnly');
        }
    });

    // @scenario actor_permission=settings_read
    // @effects system_tab_inputs_disable_when_read_only
    it('입력 요소 전부가 읽기 전용일 때 비활성화된다', () => {
        const partial = loadPartial(PARTIAL_PATH);

        for (const id of ['execution_mode_radio', 'cron_mode_radio']) {
            const node = findById(partial, id);

            expect(node, `${id} 가 레이아웃에 없습니다.`).not.toBeNull();
            expect(node.props.disabled).toContain('_computed.isReadOnly');
        }
    });

    /** @effects cron_url_field_is_excluded_from_form_binding */
    it('호출 주소 입력란은 폼 자동 바인딩 대상이 아니다', () => {
        const partial = loadPartial(PARTIAL_PATH);
        const node = findById(partial, 'cron_url_value');

        expect(node).not.toBeNull();
        expect(node.props.autoBinding).toBe(false);
        expect(node.props.name).toBeUndefined();
    });
});

/**
 * 컴포넌트 트리에서 id 로 노드를 찾는다.
 *
 * @param node 탐색 시작 노드
 * @param id 찾을 id
 */
function findById(node: any, id: string): any {
    if (!node || typeof node !== 'object') return null;
    if (node.id === id) return node;

    for (const child of node.children ?? []) {
        const found = findById(child, id);
        if (found) return found;
    }

    return null;
}
