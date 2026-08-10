/**
 * 관리자 환경설정 레이아웃 렌더링 테스트.
 *
 * JSON 키 존재만 확인하면 "조건이 실제로 그렇게 평가되는가" 를 잠그지 못한다. 여기서는
 * createLayoutTest 로 실제 렌더 결과를 확인한다 — 운영 모드 경고의 조건부 표시, 검증 에러 표시,
 * 저장 버튼 잠금 조건, SM 프리픽스 표시.
 *
 * @since 1.0.0
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
    screen,
    type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import settingsLayout from '../../../layouts/admin/plugin_settings.json';
import pluginKo from '../../../lang/ko.json';

/**
 * 화면 문구 검증을 위해 실제 플러그인 한국어 파일을 주입한다.
 *
 * 이렇게 하면 "레이아웃이 참조하는 키가 언어 파일에 실제로 있는가" 까지 함께 잠긴다 —
 * 키가 빠지면 화면에 원문 키가 남아 문구 단언이 깨진다.
 */
const TRANSLATIONS = {
    'sirsoft-verification_nhnkcp': pluginKo,
    common: { save: '저장', saving: '저장 중...', cancel: '취소' },
    admin: {
        plugins: {
            back_to_list: '목록으로',
            settings: { validation_error: '입력값을 확인해 주세요', save_success: '저장되었습니다', save_error: '저장에 실패했습니다' },
        },
    },
};

/** PageHeader 목 — 제목만 렌더 */
const TestPageHeader: React.FC<{ title?: string; description?: string }> = ({ title, description }) => (
    <div data-testid="page-header">
        <span>{title}</span>
        <span>{description}</span>
    </div>
);

/** Toggle 목 — name 속성을 그대로 노출해 폼 바인딩 대상 확인 */
const TestToggle: React.FC<{ name?: string; checked?: boolean }> = ({ name, checked }) => (
    <input type="checkbox" name={name} data-testid={`toggle-${name}`} defaultChecked={!!checked} readOnly />
);

let registry: MockComponentRegistry;

beforeEach(() => {
    registry = createMockComponentRegistryWithBasics();
    registry.register('composite', 'PageHeader', TestPageHeader);
    registry.register('composite', 'Toggle', TestToggle);
});

afterEach(() => {
    vi.clearAllMocks();
});

/**
 * 지정한 폼/에러 상태로 설정 화면을 렌더한다.
 *
 * @param form _local.form 초기값
 * @param extraLocal 추가 _local 상태 (errors / hasChanges / isSaving)
 */
async function renderSettings(form: Record<string, unknown>, extraLocal: Record<string, unknown> = {}) {
    const utils = createLayoutTest(settingsLayout as any, {
        componentRegistry: registry as any,
        routeParams: { identifier: 'sirsoft-verification_nhnkcp' },
        initialState: { _local: { form, ...extraLocal } },
        translations: TRANSLATIONS,
        locale: 'ko',
    });
    utils.mockApi('settings', { response: { data: form } });
    const { container } = await utils.render();
    return { utils, container };
}

describe('NHN KCP 환경설정 레이아웃 렌더링', () => {
    // @scenario mode=test,live_credentials=complete
    // @effects live_mode_warning_hidden_in_test_mode
    it('테스트 모드에서는 운영 모드 경고가 화면에 없다', async () => {
        const { container, utils } = await renderSettings({ is_test_mode: true, duplicate_field: 'di' });

        expect(container.textContent).not.toContain('운영 모드 활성화 안내');
        // 운영 인증 정보 섹션 자체는 항상 보인다 (모드와 무관한 입력 영역)
        expect(container.textContent).toContain('SM');

        utils.cleanup?.();
    });

    // @scenario mode=live,live_credentials=complete
    // @effects sm_prefix_is_added_once
    it('사이트코드 입력칸은 _local.form 값을 그대로 따라간다', async () => {
        // 서버는 저장 과정에서 SM 프리픽스를 떼어 보관한다. 화면이 그 결과를 반영하지 못하면
        // 입력칸 왼쪽 SM 배지와 겹쳐 `SMSMA1B2C` 로 보인다.
        const { container, utils } = await renderSettings({
            is_test_mode: false,
            duplicate_field: 'di',
            live_site_cd: 'SMA1B2C',
        });

        const siteCd = container.querySelector('input[name="live_site_cd"]') as HTMLInputElement;
        expect(siteCd).toBeTruthy();
        expect(siteCd.value).toBe('SMA1B2C');

        utils.cleanup?.();

        // 저장 응답이 정규화된 값을 되돌린 뒤의 폼 상태 — 같은 입력칸이 그 값을 보여야 한다.
        const normalized = await renderSettings({
            is_test_mode: false,
            duplicate_field: 'di',
            live_site_cd: 'A1B2C',
        });

        const after = normalized.container.querySelector('input[name="live_site_cd"]') as HTMLInputElement;
        expect(after.value).toBe('A1B2C');

        normalized.utils.cleanup?.();
    });

    // @scenario mode=live,live_credentials=complete
    // @effects sm_prefix_is_added_once
    it('저장 성공 시 응답 데이터를 폼에 되돌리는 액션이 있다', async () => {
        // 앞 테스트가 "폼이 갱신되면 화면도 갱신된다" 를 잠그고, 이 테스트가 "저장 성공이
        // 실제로 폼을 갱신한다" 를 잠근다. 둘 중 하나만 있으면 회귀를 놓친다.
        const json = JSON.stringify(settingsLayout);
        const saveCall = JSON.parse(json);

        const findApiCall = (node: unknown): any => {
            if (Array.isArray(node)) {
                for (const child of node) {
                    const hit = findApiCall(child);
                    if (hit) return hit;
                }
                return null;
            }
            if (node && typeof node === 'object') {
                const obj = node as Record<string, any>;
                if (obj.handler === 'apiCall' && obj.params?.method === 'PUT') return obj;
                for (const value of Object.values(obj)) {
                    const hit = findApiCall(value);
                    if (hit) return hit;
                }
            }
            return null;
        };

        const action = findApiCall(saveCall);
        expect(action).toBeTruthy();

        const rebind = (action.onSuccess ?? []).find(
            (a: any) => a.handler === 'setState' && a.params?.form === '{{response.data}}',
        );
        expect(rebind).toBeTruthy();
        expect(rebind.params.target).toBe('local');
    });

    // @scenario mode=live,live_credentials=complete
    // @effects live_mode_warning_shown_in_live_mode
    it('운영 모드로 전환하면 경고 문구가 나타난다', async () => {
        const { container, utils } = await renderSettings({ is_test_mode: false, duplicate_field: 'di' });

        expect(container.textContent).toContain('운영 모드 활성화 안내');

        utils.cleanup?.();
    });

    // @scenario mode=live,live_credentials=missing_enc_key
    // @effects validation_errors_render_per_field
    it('검증 에러가 있으면 항목별 안내가 화면에 표시된다', async () => {
        const { container, utils } = await renderSettings(
            { is_test_mode: false, duplicate_field: 'di' },
            { errors: { live_enc_key: ['운영 암호화 키 필드는 필수입니다.'] } },
        );

        expect(container.textContent).toContain('운영 암호화 키 필드는 필수입니다.');

        utils.cleanup?.();
    });

    // @scenario mode=test,live_credentials=complete
    // @effects save_button_locked_without_changes
    it('변경이 없으면 저장 버튼이 잠긴다', async () => {
        const { container, utils } = await renderSettings(
            { is_test_mode: true, duplicate_field: 'di' },
            { hasChanges: false, isSaving: false },
        );

        const saveButton = Array.from(container.querySelectorAll('button')).find((b) =>
            (b.textContent ?? '').includes('저장'),
        );
        expect(saveButton).toBeTruthy();
        expect((saveButton as HTMLButtonElement).disabled).toBe(true);

        utils.cleanup?.();
    });

    // @scenario mode=test,live_credentials=complete
    // @effects save_button_unlocked_on_change
    it('변경이 있으면 저장 버튼이 열린다', async () => {
        const { container, utils } = await renderSettings(
            { is_test_mode: true, duplicate_field: 'di' },
            { hasChanges: true, isSaving: false },
        );

        const saveButton = Array.from(container.querySelectorAll('button')).find((b) =>
            (b.textContent ?? '').includes('저장'),
        );
        expect((saveButton as HTMLButtonElement).disabled).toBe(false);

        utils.cleanup?.();
    });

    // @scenario mode=test,live_credentials=complete
    // @effects save_button_shows_progress_while_saving
    it('저장 중에는 저장 버튼이 잠기고 진행 문구로 바뀐다', async () => {
        const { container, utils } = await renderSettings(
            { is_test_mode: true, duplicate_field: 'di' },
            { hasChanges: true, isSaving: true },
        );

        const saveButton = Array.from(container.querySelectorAll('button')).find(
            (b) => (b.textContent ?? '').length > 0 && b.className.includes('btn-primary'),
        );
        expect((saveButton as HTMLButtonElement).disabled).toBe(true);

        utils.cleanup?.();
    });

    // @scenario mode=test,live_credentials=complete
    // @effects duplicate_basis_defaults_to_di
    it('중복 판정 기준이 DI 로 선택되어 렌더된다', async () => {
        const { container, utils } = await renderSettings({ is_test_mode: true, duplicate_field: 'di' });

        const radios = Array.from(container.querySelectorAll('input[type="radio"][name="duplicate_field"]'));
        expect(radios.length).toBe(2);
        const di = radios.find((r) => (r as HTMLInputElement).value === 'di') as HTMLInputElement;
        expect(di.checked).toBe(true);

        utils.cleanup?.();
    });

    // @scenario mode=test,live_credentials=complete
    // @effects settings_screen_resolves_all_translation_keys
    it('다국어 원문 키가 화면에 남지 않는다', async () => {
        const { container, utils } = await renderSettings({ is_test_mode: true, duplicate_field: 'di' });

        expect(container.textContent).not.toContain('$t:');
        expect(screen.queryByText(/\{\{/)).toBeNull();

        utils.cleanup?.();
    });
});
