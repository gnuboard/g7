/**
 * @file admin-board-bulk-members-render.test.tsx
 * @description 게시판 환경설정 「기본값」 탭 — 관리자·스텝 일괄 지정 카드 렌더 (#708)
 *
 * 실제 파셜 JSON(`_section_bulk_members.json`)을 렌더해, 카드가 화면에 무엇을 그리는지 본다.
 * - 「기본값」 탭에서만 그리고, 제목·라벨은 실제 문구로 풀린다(키가 그대로 새지 않는다)
 * - 「고른 게시판」 일 때만 게시판 칸이 나온다
 * - 「적용」 은 회원을 고르고, 「고른 게시판」 이면 게시판까지 골라야 눌린다
 * - 읽기 전용 운영자에게는 입력·적용이 모두 잠긴다
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';

import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
    screen,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import section from '../../../layouts/admin/partials/admin_board_settings/_section_bulk_members.json';
import koSettings from '../../../lang/partial/ko/admin/settings.json';

const TestRadioGroup: React.FC<{ name?: string; value?: string; disabled?: boolean; options?: Array<{ label: string; value: string }> }> = ({
    name,
    value,
    disabled,
    options,
}) => (
    <fieldset data-testid={`radio-${name}`} data-value={value} disabled={disabled}>
        {(options ?? []).map((option) => (
            <label key={option.value}>
                <input type="radio" name={name} value={option.value} checked={option.value === value} disabled={disabled} readOnly />
                {option.label}
            </label>
        ))}
    </fieldset>
);
const TestTagInput: React.FC<{ id?: string; disabled?: boolean }> = ({ id, disabled }) => (
    <div data-testid={id} data-disabled={String(Boolean(disabled))} />
);
const TestIcon: React.FC<{ name?: string }> = ({ name }) => <i data-icon={name} />;

/**
 * 카드 파셜 하나를 주어진 전역 상태·읽기 전용 여부로 렌더합니다.
 *
 * 카드가 읽는 `_computed.isReadOnly`·`_computed.boardOptions` 는 운영에서 설정 화면 본체의 computed 를
 * TemplateApp 이 계산해 데이터 문맥에 싣는다. 하네스는 그 계산을 하지 않으므로 같은 이름의 값을
 * `_computed` 로 직접 싣는다(admin_basic `admin-settings-readonly` 테스트와 같은 방식).
 *
 * @param global 전역 상태 (activeBoardSettingsTab · boardBulkMembers)
 * @param readOnly 읽기 전용 운영자 여부
 * @returns 렌더 유틸
 */
async function renderSection(global: Record<string, any>, readOnly = false) {
    const registry = createMockComponentRegistryWithBasics();
    registry.register('composite', 'RadioGroup', TestRadioGroup);
    registry.register('composite', 'TagInput', TestTagInput);
    registry.register('basic', 'Icon', TestIcon);

    const utils = createLayoutTest(
        {
            version: '1.0.0',
            layout_name: 'admin_board_bulk_members_render_probe',
            data_sources: [],
            initGlobal: global,
            components: [section],
        } as never,
        {
            componentRegistry: registry as never,
            locale: 'ko',
            templateId: 'test-template',
            translations: { 'sirsoft-board': { admin: { settings: koSettings } } },
            initialData: { _computed: { isReadOnly: readOnly, bulkMembersLocked: readOnly, bulkMembersNoPermission: readOnly, boardOptions: [{ value: 1, label: '자유게시판' }] } },
        }
    );

    await utils.render();

    return utils;
}

const bulk = (koSettings as any).bulk_members;

describe('게시판 관리자·스텝 일괄 지정 카드 — 렌더 (#708)', () => {
    let utils: Awaited<ReturnType<typeof renderSection>> | undefined;

    afterEach(() => {
        utils?.cleanup();
        utils = undefined;
    });

    // @scenario member_type=manager, mode=add, target=all
    // @effects bulk_members_card_renders_on_defaults_tab
    it('「기본값」 탭에서 카드를 실제 문구로 그리고, 회원을 고르기 전에는 「적용」 이 잠긴다', async () => {
        utils = await renderSection({ activeBoardSettingsTab: 'basic_defaults', boardBulkMembers: { all: true, userUuids: [] } });

        // 존재를 먼저 확정한 뒤 문구를 단언한다 (부재 단언은 렌더 전에도 통과한다)
        expect(await screen.findByText(bulk.title)).toBeTruthy();
        expect(document.body.textContent).not.toContain('sirsoft-board.admin.settings.bulk_members');
        expect((screen.getByTestId('radio-board_bulk_members_target') as HTMLFieldSetElement).dataset.value).toBe('all');
        expect(screen.queryByTestId('bulk_members_boards')).toBeNull();

        const apply = document.getElementById('bulk_members_apply_button') as HTMLButtonElement;
        expect(apply).not.toBeNull();
        expect(apply.disabled).toBe(true);
    });

    // @scenario member_type=step, mode=add, target=selected
    // @effects bulk_members_card_renders_on_defaults_tab
    it('「고른 게시판」 이면 게시판 칸이 나오고, 게시판과 회원을 둘 다 골라야 「적용」 이 눌린다', async () => {
        utils = await renderSection({
            activeBoardSettingsTab: 'basic_defaults',
            boardBulkMembers: { all: false, boardIds: [], userUuids: ['uuid-u1'] },
        });

        expect(await screen.findByTestId('bulk_members_boards')).toBeTruthy();
        expect((document.getElementById('bulk_members_apply_button') as HTMLButtonElement).disabled).toBe(true);

        utils.cleanup();
        utils = await renderSection({
            activeBoardSettingsTab: 'basic_defaults',
            boardBulkMembers: { all: false, boardIds: [1], userUuids: ['uuid-u1'] },
        });

        expect(await screen.findByTestId('bulk_members_boards')).toBeTruthy();
        expect((document.getElementById('bulk_members_apply_button') as HTMLButtonElement).disabled).toBe(false);
    });

    // @scenario member_type=manager, mode=remove, target=all
    // @effects bulk_members_card_renders_on_defaults_tab
    it('게시판 수정 권한이 없는 운영자에게는 라디오·회원 칸·「적용」 이 모두 잠기고 이유가 보인다', async () => {
        utils = await renderSection({ activeBoardSettingsTab: 'basic_defaults', boardBulkMembers: { all: true, userUuids: ['uuid-u1'] } }, true);

        expect(await screen.findByText(bulk.title)).toBeTruthy();
        for (const name of ['board_bulk_members_target', 'board_bulk_members_type', 'board_bulk_members_mode']) {
            expect((screen.getByTestId(`radio-${name}`) as HTMLFieldSetElement).disabled, name).toBe(true);
        }
        expect(screen.getByTestId('bulk_members_users_input').dataset.disabled).toBe('true');
        expect((document.getElementById('bulk_members_apply_button') as HTMLButtonElement).disabled).toBe(true);
        expect(screen.getByText(bulk.no_permission)).toBeTruthy();
    });

    // @scenario member_type=manager, mode=add, target=all
    // @effects bulk_members_card_renders_on_defaults_tab
    it('다른 탭에서는 카드를 그리지 않는다', async () => {
        utils = await renderSection({ activeBoardSettingsTab: 'general', boardBulkMembers: { all: true, userUuids: [] } });

        // 다른 탭에서도 무언가는 렌더되었음을 먼저 확정할 수 없으므로, 같은 렌더에서 탭만 바꾼 쪽이 그려지는 것으로 대조한다
        expect(screen.queryByText(bulk.title)).toBeNull();

        utils.cleanup();
        utils = await renderSection({ activeBoardSettingsTab: 'basic_defaults', boardBulkMembers: { all: true, userUuids: [] } });
        expect(await screen.findByText(bulk.title)).toBeTruthy();
    });
});
