/**
 * 확장 제거 결과 화면 — 운영자 파일 사본 경로 노출을 고정한다.
 *
 * 운영자가 확장의 `custom/` 에 넣은 파일은 확장을 지울 때 삭제 직전에 사본이 보관된다.
 * 그 경로를 알릴 통로는 제거 응답의 `preserved_backups` 하나뿐인데, 종전에는 어떤
 * 화면도 그 필드를 읽지 않았다 — 모달은 곧바로 닫히고 일반 성공 토스트만 떴으므로
 * 운영자는 자기 파일이 어디로 갔는지 알 방법이 없었다. 오류도 나지 않는다.
 *
 * 여기서 고정하는 것은 넷이다.
 *
 *  1. 응답의 보관 목록이 상태로 옮겨진다
 *  2. 보관분이 있으면 모달이 닫히지 않고 결과 화면으로 바뀐다 (없으면 종전대로 닫힘)
 *  3. 결과 화면이 실제로 경로를 렌더한다
 *  4. 그 상태가 다음 제거 모달로 이월되지 않는다 — 이월되면 확인 화면이 뜨지 않아
 *     제거 자체가 불가능해진다
 *
 * @scenario custom_source=convention_scan, custom_asset=css
 * @effects custom_backup_path_surfaced_in_admin_ui
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

import moduleList from '../../layouts/admin_module_list.json';
import pluginList from '../../layouts/admin_plugin_list.json';
import templateTabAdmin from '../../layouts/partials/admin_template_list/_tab_admin.json';
import templateTabUser from '../../layouts/partials/admin_template_list/_tab_user.json';
import moduleModal from '../../layouts/partials/admin_module_list/_modal_uninstall.json';
import pluginModal from '../../layouts/partials/admin_plugin_list/_modal_uninstall.json';
import templateModal from '../../layouts/partials/admin_template_list/_modal_uninstall.json';

type Json = Record<string, any>;

/** 트리에서 조건을 만족하는 노드를 모두 모읍니다. */
function collect(node: unknown, predicate: (n: Json) => boolean, out: Json[] = []): Json[] {
    if (Array.isArray(node)) {
        node.forEach((child) => collect(child, predicate, out));

        return out;
    }

    if (node && typeof node === 'object') {
        const object = node as Json;

        if (predicate(object)) {
            out.push(object);
        }

        Object.values(object).forEach((value) => collect(value, predicate, out));
    }

    return out;
}

/** 제거 API 호출 노드를 돌려줍니다 (`uninstall-info` 조회와 구분). */
function uninstallCall(modal: unknown): Json {
    const [call] = collect(
        modal,
        (n) => n.handler === 'apiCall' && typeof n.target === 'string' && n.target.endsWith('/uninstall')
    );

    expect(call, '제거 API 호출을 찾지 못했습니다').toBeDefined();

    return call;
}

const CASES = [
    { name: '모듈', ns: 'modules', modal: moduleModal, list: moduleList, backups: 'moduleUninstallBackups', selected: 'selectedModule' },
    { name: '플러그인', ns: 'plugins', modal: pluginModal, list: pluginList, backups: 'pluginUninstallBackups', selected: 'selectedPlugin' },
    { name: '템플릿(관리자 탭)', ns: 'templates', modal: templateModal, list: templateTabAdmin, backups: 'templateUninstallBackups', selected: 'selectedTemplate' },
    { name: '템플릿(사용자 탭)', ns: 'templates', modal: templateModal, list: templateTabUser, backups: 'templateUninstallBackups', selected: 'selectedTemplate' },
];

describe('확장 제거 — 운영자 파일 사본 경로 안내', () => {
    it.each(CASES)('$name: 결과 화면에서는 제목이 완료 문구로 바뀐다', ({ modal, backups, ns }) => {
        // 브라우저 실측에서 드러났다 — 본문은 결과로 바뀌는데 제목만 "제거 확인" 으로
        // 남아 있었다. 제목은 화면에서 가장 먼저 읽히는 줄이라, 끝난 일을 아직
        // 물어보는 것처럼 보인다.
        const title = String((modal as Json).props?.title ?? '');

        expect(title, '제목이 고정 문구입니다 — 결과 화면에서도 "제거 확인" 이 남습니다').toContain(
            backups
        );
        expect(title).toContain(`admin.${ns}.modals.uninstall_done_title`);
        expect(title).toContain(`admin.${ns}.modals.uninstall_title`);
    });

    it.each(CASES)('$name: 응답의 보관 목록을 상태로 옮긴다', ({ modal, backups }) => {
        const seeds = collect(
            uninstallCall(modal).onSuccess,
            (n) => n.handler === 'setState' && n.params && backups in n.params
        );

        expect(seeds.length, `${backups} 를 채우는 setState 가 없습니다`).toBeGreaterThan(0);
        expect(seeds[0].params[backups]).toContain('preserved_backups');
    });

    it.each(CASES)('$name: 보관분이 있으면 모달을 닫지 않는다', ({ modal }) => {
        const closes = collect(uninstallCall(modal).onSuccess, (n) => n.handler === 'closeModal');

        expect(closes.length).toBeGreaterThan(0);
        closes.forEach((close) => {
            expect(close.if, 'closeModal 이 무조건 실행되면 결과 화면을 볼 수 없습니다').toContain(
                'preserved_backups'
            );
        });
    });

    it.each(CASES)('$name: 보관분이 있으면 선택 상태를 비우지 않는다', ({ modal, selected }) => {
        // 결과 화면이 확장 이름을 그대로 쓰므로, 여기서 비우면 화면이 빈 이름으로 뜬다
        const clears = collect(
            uninstallCall(modal).onSuccess,
            (n) => n.handler === 'setState' && n.params && n.params[selected] === null
        );

        expect(clears.length).toBeGreaterThan(0);
        clears.forEach((clear) => {
            expect(clear.if).toContain('preserved_backups');
        });
    });

    it.each(CASES)('$name: 결과 화면이 확인 화면과 배타적으로 렌더된다', ({ modal, backups }) => {
        const [section] = collect(modal, (n) => n.id === 'preserved_backups_section');

        expect(section, '결과 화면 섹션이 없습니다').toBeDefined();
        expect(section.if).toBe(`{{_global.${backups}?.length}}`);

        // 확인 본문은 결과가 뜨면 숨어야 한다 — 이미 지운 확장을 다시 지울 수는 없다
        const children: Json[] = (modal as Json).children;
        const footerIndex = children.findIndex((c) =>
            String(c.props?.className ?? '').startsWith('modal-footer-buttons')
        );

        children.slice(0, footerIndex).forEach((child) => {
            if (child.id === 'preserved_backups_section') {
                return;
            }

            expect(child.if, `확인 본문(${child.id ?? child.name})이 결과 화면에서도 남습니다`).toContain(
                backups
            );
        });
    });

    it.each(CASES)('$name: 결과 화면의 닫기 버튼이 보관 상태를 정리한다', ({ modal, backups }) => {
        const cleanups = collect(
            modal,
            (n) => n.handler === 'setState' && n.params && n.params[backups] === null
        );

        expect(cleanups.length, '보관 상태를 비우는 액션이 없습니다').toBeGreaterThan(0);
    });

    it.each(CASES)('$name: 모달을 여는 시드가 지난 보관 목록을 지운다', ({ list, backups, selected }) => {
        // 지우지 않으면 다음 확장을 제거하려 할 때 지난 경로가 결과 화면으로 이미 떠 있고,
        // 확인 화면이 숨겨져 있어 제거를 진행할 수 없다
        const seeds = collect(
            list,
            (n) =>
                n.handler === 'setState' &&
                n.params &&
                typeof n.params[selected] === 'string' &&
                n.params[selected].includes('{{row}}')
        );
        const modalSeeds = seeds.filter((seed) => `${selected.replace('selected', '').toLowerCase()}` !== '');

        expect(modalSeeds.length).toBeGreaterThan(0);
        expect(
            modalSeeds.some((seed) => seed.params[backups] === null),
            `${backups} 를 해제하는 모달 진입 시드가 없습니다`
        ).toBe(true);
    });
});

describe('확장 제거 결과 화면 렌더링', () => {
    let testUtils: ReturnType<typeof createLayoutTest> | null = null;

    /** 반복 렌더는 레지스트리에 Fragment 가 있어야 출력된다 (없으면 무오류 빈 출력). */
    function setupRegistry(): void {
        const registry = ComponentRegistry.getInstance();

        const Div: React.FC<any> = ({ className, children }) => <div className={className}>{children}</div>;
        const Span: React.FC<any> = ({ className, children, text }) => (
            <span className={className}>{children ?? text}</span>
        );
        const P: React.FC<any> = ({ className, children, text }) => <p className={className}>{children ?? text}</p>;
        const Icon: React.FC<any> = ({ name }) => <i data-icon={name} />;
        const Button: React.FC<any> = ({ className, children, disabled }) => (
            <button type="button" className={className} disabled={disabled}>
                {children}
            </button>
        );
        const Checkbox: React.FC<any> = (props) => <input type="checkbox" readOnly checked={!!props.checked} />;
        const Fragment: React.FC<any> = ({ children }) => <>{children}</>;

        (registry as any).registry = {
            Div: { component: Div, metadata: { name: 'Div', type: 'basic' } },
            Span: { component: Span, metadata: { name: 'Span', type: 'basic' } },
            P: { component: P, metadata: { name: 'P', type: 'basic' } },
            Icon: { component: Icon, metadata: { name: 'Icon', type: 'basic' } },
            Button: { component: Button, metadata: { name: 'Button', type: 'basic' } },
            Checkbox: { component: Checkbox, metadata: { name: 'Checkbox', type: 'basic' } },
            Fragment: { component: Fragment, metadata: { name: 'Fragment', type: 'layout' } },
        };
    }

    afterEach(() => {
        testUtils?.cleanup();
        testUtils = null;
    });

    it('보관 경로가 화면에 그대로 나온다', async () => {
        setupRegistry();

        const archive = '/var/www/g7/storage/app/extension-custom-backups/sirsoft-basic-20260826_014500/custom';

        testUtils = createLayoutTest({
            version: '1.0.0',
            layout_name: 'uninstall_result_render_test',
            initGlobal: {
                selectedTemplate: { identifier: 'sirsoft-basic', name: '기본 템플릿' },
                templateUninstallInfo: null,
                templateUninstallBackups: [{ directory: 'custom', archive }],
            },
            components: (templateModal as Json).children,
        } as any);

        await testUtils.render();

        expect(screen.getByText(archive)).toBeInTheDocument();
    });

    it('보관분이 없으면 결과 화면이 뜨지 않는다', async () => {
        setupRegistry();

        testUtils = createLayoutTest({
            version: '1.0.0',
            layout_name: 'uninstall_confirm_render_test',
            initGlobal: {
                selectedTemplate: { identifier: 'sirsoft-basic', name: '기본 템플릿' },
                templateUninstallInfo: null,
                templateUninstallBackups: null,
            },
            components: (templateModal as Json).children,
        } as any);

        await testUtils.render();

        // 확인 화면이 실제로 떠 있는 상태에서의 부재여야 한다 — 아무것도 렌더되지
        // 않아 통과하는 것이면 이 단언은 아무것도 지키지 못한다
        expect(screen.getByRole('checkbox')).toBeInTheDocument();
        expect(screen.queryByText(/extension-custom-backups/)).toBeNull();
    });
});
