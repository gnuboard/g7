/**
 * 드라이버 탭 — 웹서버 파일 위임 카드 테스트
 *
 * @description
 * 「웹서버 파일 위임」 카드는 저장소 카드들(공개 자산 스토리지 → 비공개 첨부 저장소) 바로 뒤의 형제 카드다. 셀렉트는 저장 키(drivers.file_delegation)와
 * 서버 카탈로그(available_drivers.file_delegation)에 묶이고, nginx 를 고를 때만 내부 위치 접두사 칸이 보인다.
 * 방식별 웹서버 설정 안내는 `_meta.file_delegation`(설치 위치 실경로)으로 채워지고, 「설정 점검」(test-driver)과
 * 「실제 전송 확인」(file-delegation-probe) 버튼은 위임을 쓰는 동안에만 보인다.
 *
 * 계약 단언(배포 JSON 구조 + 저장 규칙 대조)에 더해 실제 카드 서브트리를 createLayoutTest 로 렌더링해
 * 조건부 노출과 안내 경로를 확인한다.
 */

import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

import tabDrivers from '../../layouts/partials/admin_settings/_tab_drivers.json';

// ─── 테스트용 컴포넌트 ───

const TestDiv: React.FC<any> = ({ className, children, 'data-testid': testId }) => (
    <div className={className} data-testid={testId}>{children}</div>
);
const TestH3: React.FC<any> = ({ className, children, text }) => <h3 className={className}>{children || text}</h3>;
const TestLabel: React.FC<any> = ({ className, children, text }) => <label className={className}>{children || text}</label>;
const TestSpan: React.FC<any> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const TestP: React.FC<any> = ({ className, children, text, 'data-testid': testId }) => (
    <p className={className} data-testid={testId}>{children || text}</p>
);
const TestInput: React.FC<any> = ({ name, placeholder }) => <input data-name={name} placeholder={placeholder} />;
const TestButton: React.FC<any> = ({ children, text, 'data-testid': testId }) => (
    <button type="button" data-testid={testId}>{children || text}</button>
);
const TestIcon: React.FC<any> = ({ name }) => <i data-icon={name} />;
const TestBadge: React.FC<any> = ({ text }) => <span data-badge="true">{text}</span>;
const TestSelect: React.FC<any> = ({ name, value, options }) => (
    <select data-name={name} value={value} onChange={() => undefined}>
        {options?.map((o: any) => <option key={String(o.value)} value={o.value}>{o.label}</option>)}
    </select>
);

// 렌더러가 컴포넌트 목록을 Fragment 로 감싸므로 반드시 등록해야 한다(미등록이면 "없어야 한다" 단언이 거짓 통과한다).
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

/**
 * 테스트용 컴포넌트 레지스트리를 구성한다.
 *
 * @returns 구성된 레지스트리
 */
function setupTestRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
        Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
        H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
        Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
        Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
        P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
        Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
        Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
        Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
        Badge: { component: TestBadge, metadata: { name: 'Badge', type: 'composite' } },
        Select: { component: TestSelect, metadata: { name: 'Select', type: 'composite' } },
        Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    };
    return registry;
}

// ─── 다국어 (카드가 쓰는 키) ───

const translations = {
    admin: {
        settings: {
            env_locked_badge: '.env 고정',
            drivers: {
                testing: '테스트 중...',
                test_failed: '연결 실패',
                common: {
                    unavailable_saved_value_prefix: '저장된 값',
                    unavailable_saved_value_suffix: '(현재 사용할 수 없음)',
                },
                file_delegation: {
                    title: '웹서버 파일 위임',
                    desc: '서버에 있는 파일을 웹서버가 직접 보내게 합니다.',
                    mode: '위임 방식',
                    accel_prefix: '내부 위치 접두사',
                    accel_prefix_desc: 'nginx internal location 경로입니다.',
                    apache_guide: 'Apache 안내',
                    nginx_guide: 'nginx 안내',
                    saved_differs: '화면의 값이 아직 적용되지 않았습니다.',
                    test_hint: '설정 점검 안내',
                    test_button: '설정 점검',
                    test_mapping: '앱이 보낼 매핑(X-Accel-Mapping): {{mapping}}',
                    probe_button: '실제 전송 확인',
                    probe_ok: '웹서버가 파일을 보냈습니다.',
                    probe_failed: '파일 본문을 받지 못했습니다.',
                    probe_inactive: '적용 중인 방식이 「사용 안 함」이라 PHP 가 보냈습니다.',
                    probe_skipped: '이 파일은 위임 경로 밖이라 PHP 가 보냈습니다.',
                    recover_hint: '복구 안내',
                },
            },
        },
    },
};

/**
 * 저장소 루트를 위로 훑어 찾는다.
 *
 * @returns 저장소 루트 절대경로
 */
function repositoryRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 10; depth++) {
        if (fs.existsSync(path.join(current, 'config/core.php'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('저장소 루트를 찾지 못했습니다.');
}

/**
 * 레이아웃 트리에서 조건을 만족하는 첫 노드를 찾는다.
 *
 * @param node 탐색 시작 노드
 * @param predicate 노드 판정 함수
 * @returns 찾은 노드 또는 null
 */
function findNode(node: any, predicate: (n: any) => boolean): any {
    if (!node || typeof node !== 'object') return null;
    if (predicate(node)) return node;

    for (const child of node.children ?? []) {
        const found = findNode(child, predicate);
        if (found) return found;
    }

    return null;
}

/**
 * 노드의 click 액션에서 apiCall 을 찾는다.
 *
 * @param node 버튼 노드
 * @returns apiCall 액션 또는 undefined
 */
function apiCallOf(node: any): any {
    const click = (node?.actions ?? []).find((a: any) => a.type === 'click');

    return (click?.actions ?? []).find((a: any) => a.handler === 'apiCall');
}

// 배포 JSON 의 카드 서브트리 자체가 렌더 대상이다 — 사본 표현식을 두지 않는다.
const card = (tabDrivers as any).children.find((c: any) => c.id === 'card_file_delegation_settings');

const CATALOG = [
    { id: 'none', label: { ko: '사용 안 함 (PHP 가 전송)', en: 'Disabled' } },
    { id: 'x-sendfile', label: { ko: 'X-Sendfile (Apache mod_xsendfile)', en: 'X-Sendfile' } },
    { id: 'x-accel-redirect', label: { ko: 'X-Accel-Redirect (nginx)', en: 'X-Accel-Redirect' } },
];

/**
 * 카드 렌더링용 테스트 유틸을 만든다.
 *
 * @param drivers 폼의 drivers 상태
 * @param appliedMode `_meta.file_delegation.mode` (적용 중인 방식)
 * @param extraLocal `_local` 에 더할 상태
 * @returns createLayoutTest 유틸
 */
function makeUtils(drivers: Record<string, unknown>, appliedMode = 'none', extraLocal: Record<string, unknown> = {}) {
    return createLayoutTest(
        {
            version: '1.0.0',
            layout_name: 'test_file_delegation_card',
            components: [card],
        } as any,
        {
            auth: {
                isAuthenticated: true,
                user: { id: 1, name: 'Admin', role: 'super_admin' },
                authType: 'admin',
            },
            translations,
            locale: 'ko',
            initialState: {
                _local: {
                    form: {
                        available_drivers: { file_delegation: CATALOG },
                        drivers,
                        _meta: {
                            file_delegation: {
                                mode: appliedMode,
                                base_path: '/srv/g7',
                                accel_prefix: '/_g7_sendfile/',
                                accel_mapping: '/srv/g7/=/_g7_sendfile/',
                            },
                        },
                    },
                    errors: null,
                    ...extraLocal,
                },
            },
        },
    );
}

describe('드라이버 탭 웹서버 파일 위임 카드 — 계약', () => {
    // @scenario case=admin_layout
    // @effects drivers_tab_renders_file_delegation_card
    it('저장소 카드들(공개 자산 → 비공개 첨부 저장소) 바로 뒤의 형제 카드다', () => {
        const ids = (tabDrivers as any).children.map((c: any) => c.id);

        expect(card).toBeDefined();
        expect(ids.indexOf('card_private_attachment_settings')).toBe(ids.indexOf('card_public_asset_settings') + 1);
        expect(ids.indexOf('card_file_delegation_settings')).toBe(ids.indexOf('card_private_attachment_settings') + 1);
    });

    // @scenario case=admin_layout
    // @effects drivers_tab_renders_file_delegation_card
    it('셀렉트가 저장 키·카탈로그·.env 잠금에 묶이고 죽은 저장값 안내가 있다', () => {
        const select = findNode(card, (n) => n.name === 'Select');

        expect(select.props.name).toBe('drivers.file_delegation');
        expect(select.props.options).toContain('available_drivers?.file_delegation');
        expect(select.props.disabled).toContain("envLocked?.['drivers.file_delegation']");
        expect(findNode(card, (n) => n.id === 'unavailable_driver_notice_file_delegation')).not.toBeNull();
    });

    // @scenario case=admin_layout
    // @effects drivers_tab_renders_file_delegation_card
    it('접두사 칸은 nginx 를 고를 때만 보인다', () => {
        const field = findNode(card, (n) => n.id === 'field_file_delegation_accel_prefix');

        expect(field.if).toContain("file_delegation === 'x-accel-redirect'");
        expect(findNode(field, (n) => n.name === 'Input').props.name).toBe('drivers.file_delegation_accel_prefix');
    });

    // @scenario case=admin_layout
    // @effects drivers_tab_renders_file_delegation_card
    it('설정 점검은 test-driver 로, 실제 전송 확인은 프로브 주소로 간다', () => {
        const test = apiCallOf(findNode(card, (n) => n.props?.['data-testid'] === 'file-delegation-test-button'));
        const probe = apiCallOf(findNode(card, (n) => n.props?.['data-testid'] === 'file-delegation-probe-button'));

        expect(test.target).toBe('/api/admin/settings/test-driver');
        expect(Object.keys(test.params.body)).toEqual(['file_delegation', 'file_delegation_accel_prefix']);
        expect(probe.target).toBe('/api/admin/settings/file-delegation-probe');
        expect(probe.params.method).toBe('GET');
        expect(JSON.stringify(probe.onSuccess)).toContain("result?.data?.probe === 'g7-file-delegation'");
        // 위임 없이 PHP 가 보냈다는 서버 응답(reason)을 상태에 싣는다 — 본문 수신만으로 성공을 정하지 않는다
        expect(JSON.stringify(probe.onSuccess)).toContain('result?.data?.reason');
    });

    // @scenario case=admin_layout
    // @effects drivers_tab_renders_file_delegation_card
    it('저장 규칙·프로브 라우트가 같은 키와 주소를 쓴다', () => {
        const root = repositoryRoot();
        const requestSource = fs.readFileSync(path.join(root, 'app/Http/Requests/Settings/SaveSettingsRequest.php'), 'utf-8');
        const routes = fs.readFileSync(path.join(root, 'routes/api.php'), 'utf-8');
        const probe = JSON.parse(fs.readFileSync(path.join(root, 'resources/probes/file-delegation-probe.json'), 'utf-8'));

        expect(requestSource).toContain("'drivers.file_delegation'");
        expect(requestSource).toContain("'drivers.file_delegation_accel_prefix'");
        expect(routes).toContain("'file-delegation-probe'");
        expect(probe.data.probe).toBe('g7-file-delegation');
    });
});

describe('드라이버 탭 웹서버 파일 위임 카드 — 렌더링', () => {
    let registry: ComponentRegistry;
    let testUtils: ReturnType<typeof createLayoutTest> | null = null;

    beforeEach(() => {
        registry = setupTestRegistry();
    });

    afterEach(() => {
        testUtils?.cleanup();
        testUtils = null;
        (registry as any).registry = {};
    });

    // @scenario case=admin_layout
    // @effects drivers_tab_renders_file_delegation_card
    it('사용 안 함이면 셀렉트만 있고 안내·점검 구역이 없다', async () => {
        testUtils = makeUtils({ file_delegation: 'none' });
        const { container } = await testUtils.render();

        const select = container.querySelector('select[data-name="drivers.file_delegation"]');
        expect(select).not.toBeNull();
        expect(Array.from(select!.querySelectorAll('option')).map((o) => o.getAttribute('value'))).toEqual([
            'none',
            'x-sendfile',
            'x-accel-redirect',
        ]);
        expect(container.querySelector('input[data-name="drivers.file_delegation_accel_prefix"]')).toBeNull();
        expect(container.querySelector('[data-testid="file-delegation-test-button"]')).toBeNull();
        expect(container.textContent ?? '').not.toContain('XSendFilePath');
    });

    // @scenario case=admin_layout
    // @effects drivers_tab_renders_file_delegation_card
    it('X-Sendfile 이면 Apache 안내에 설치 위치 실경로가 찍힌다', async () => {
        testUtils = makeUtils({ file_delegation: 'x-sendfile' }, 'x-sendfile');
        const { container } = await testUtils.render();

        expect(container.querySelector('[data-testid="file-delegation-apache-path"]')?.textContent).toBe('XSendFilePath /srv/g7');
        expect(container.querySelector('input[data-name="drivers.file_delegation_accel_prefix"]')).toBeNull();
        expect(container.querySelector('[data-testid="file-delegation-test-button"]')).not.toBeNull();
        expect(container.querySelector('[data-testid="file-delegation-probe-button"]')).not.toBeNull();
        expect(container.textContent ?? '').not.toContain('화면의 값이 아직 적용되지 않았습니다.');
    });

    // @scenario case=admin_layout
    // @effects drivers_tab_renders_file_delegation_card
    it('X-Accel-Redirect 면 접두사 칸과 nginx location 안내가 화면 값으로 그려진다', async () => {
        testUtils = makeUtils({ file_delegation: 'x-accel-redirect', file_delegation_accel_prefix: '/protected-files/' });
        const { container } = await testUtils.render();

        expect(container.querySelector('input[data-name="drivers.file_delegation_accel_prefix"]')).not.toBeNull();
        expect(container.querySelector('[data-testid="file-delegation-nginx-location"]')?.textContent).toBe('location /protected-files/ {');
        expect(container.querySelector('[data-testid="file-delegation-nginx-alias"]')?.textContent).toBe('alias /srv/g7/;');
        // nginx 내부 위치는 앱 응답의 nosniff 를 넘기지 않으므로 안내에 다시 붙이는 줄이 있어야 한다
        expect(container.querySelector('[data-testid="file-delegation-nginx-nosniff"]')?.textContent).toBe('add_header X-Content-Type-Options "nosniff" always;');
        // CSP(공개 스토리지·본문 미디어에 앱이 붙이는 정책)도 내부 이동에서 빠지므로 원 응답 값을 다시 붙인다
        expect(container.querySelector('[data-testid="file-delegation-nginx-csp"]')?.textContent).toBe('add_header Content-Security-Policy $upstream_http_content_security_policy always;');
        // 화면 값(x-accel-redirect)과 적용 중인 값(none)이 다르면 저장을 먼저 하라고 알린다
        expect(container.textContent ?? '').toContain('화면의 값이 아직 적용되지 않았습니다.');
    });

    // @scenario case=admin_layout
    // @effects drivers_tab_renders_file_delegation_card
    it('점검 결과와 실제 전송 확인 결과를 그린다', async () => {
        testUtils = makeUtils({ file_delegation: 'x-sendfile' }, 'x-sendfile', {
            driverTestResults: { file_delegation: { success: false, message: 'mod_xsendfile 이 없습니다' } },
            fileDelegationProbe: { success: true },
        });
        const { container } = await testUtils.render();

        expect(container.textContent ?? '').toContain('mod_xsendfile 이 없습니다');
        expect(container.textContent ?? '').toContain('웹서버가 파일을 보냈습니다.');
        // X-Sendfile 은 매핑을 쓰지 않으므로 매핑 줄이 없다
        expect(container.querySelector('[data-testid="file-delegation-test-mapping"]')).toBeNull();
    });

    // @scenario case=admin_layout
    // @effects drivers_tab_renders_file_delegation_card, probe_reports_php_delivery_instead_of_success
    it.each([
        ['inactive', '적용 중인 방식이 「사용 안 함」이라 PHP 가 보냈습니다.'],
        ['skipped', '이 파일은 위임 경로 밖이라 PHP 가 보냈습니다.'],
    ])('실제 전송 확인이 위임 없이 PHP 가 보낸 결과(%s)면 성공 문구 대신 그 사유를 보인다', async (reason, message) => {
        testUtils = makeUtils({ file_delegation: 'x-accel-redirect' }, 'none', {
            fileDelegationProbe: { success: false, reason },
        });
        const { container } = await testUtils.render();
        const text = container.textContent ?? '';

        expect(text).toContain(message);
        expect(text).not.toContain('웹서버가 파일을 보냈습니다.');
        expect(text).not.toContain('파일 본문을 받지 못했습니다.');
    });

    // @scenario case=admin_layout
    // @effects drivers_tab_renders_file_delegation_card
    it('nginx 점검 결과에 앱이 만들 매핑 문자열을 함께 보인다', async () => {
        testUtils = makeUtils({ file_delegation: 'x-accel-redirect', file_delegation_accel_prefix: '/protected-files/' }, 'none', {
            driverTestResults: {
                file_delegation: {
                    success: true,
                    message: 'nginx 로 보입니다.',
                    details: { mode: 'x-accel-redirect', accel_mapping: '/srv/g7/=/protected-files/' },
                },
            },
        });
        const { container } = await testUtils.render();

        expect(container.querySelector('[data-testid="file-delegation-test-mapping"]')?.textContent).toContain('/srv/g7/=/protected-files/');
    });
});
