// @vitest-environment jsdom
import '@testing-library/jest-dom';

// e2e:allow 설정 화면 상태별 표시를 실물 레이아웃 렌더로 고정한다 — 실제 CloudFront 계정이 없어 브라우저 실측으로는 재현할 수 없는 응답(거절, 배포 꺼짐, 현황 403)을 모의 응답으로 잰다
/**
 * @file plugin-settings-render.test.tsx
 * @description CloudFront 설정 화면 — 연결 시험·무효화 대기 현황·저장 실패의 상태별 표시
 *
 * 실물 레이아웃(plugin_settings.json)과 실물 ko 언어 파일을 렌더 도구에 올린다. 버튼은 실제 클릭으로 누르고 응답만 모의한다.
 *
 * @scenario-manifest tests/scenarios/plugin-settings-render.yaml
 */
import React from 'react';
import { afterEach, describe, expect, it, type Mock } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

import layout from '../../../layouts/admin/plugin_settings.json';
import ko from '../../../lang/ko.json';

const SETTINGS = {
    data: {
        cdn_base_url: 'https://cdn.example.test',
        distribution_id: 'E0TESTDISTRIBUTION',
        access_key: '********',
        secret_key: '********',
        purge_enabled: true,
        abilities: { can_update: true },
    },
};

const STATUS = {
    data: {
        pending: 2,
        abandoned: 1,
        last_error: null,
        last_failed_at: null,
        last_success_at: null,
        purge_ready: true,
    },
};

const label = ko.settings;

/**
 * 태그 그대로 그리는 시험용 컴포넌트를 만든다.
 *
 * @param tag HTML 태그
 * @returns 컴포넌트
 */
const Passthrough = (tag: keyof React.JSX.IntrinsicElements) => {
    const Comp: React.FC<any> = ({ className, children, text, id, disabled, type, onClick, name, value, role }) =>
        React.createElement(
            tag,
            tag === 'input' ? { className, id, disabled, type, name, value: value ?? '', readOnly: true } : { className, id, disabled, type, onClick, role },
            tag === 'input' ? undefined : (children ?? text),
        );

    return Comp;
};

/** 덮어쓰기 전 레지스트리 원본 (afterEach 에서 되돌린다) */
let savedRegistry: unknown = null;

/**
 * 이 화면이 쓰는 컴포넌트만 등록한 레지스트리를 만든다.
 *
 * @returns 테스트 레지스트리
 */
function setupRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();
    savedRegistry ??= (registry as any).registry;
    const entry = (tag: keyof React.JSX.IntrinsicElements, name: string, type = 'basic') => ({
        [name]: { component: Passthrough(tag), metadata: { name, type } },
    });
    (registry as any).registry = {
        ...entry('div', 'Div'),
        ...entry('p', 'P'),
        ...entry('span', 'Span'),
        ...entry('label', 'Label'),
        ...entry('a', 'A'),
        ...entry('h3', 'H3'),
        ...entry('ul', 'Ul'),
        ...entry('li', 'Li'),
        ...entry('button', 'Button'),
        ...entry('input', 'Input'),
        ...entry('div', 'Toggle', 'composite'),
        ...entry('div', 'PageHeader', 'composite'),
        Icon: { component: () => null, metadata: { name: 'Icon', type: 'basic' } },
        Fragment: { component: Passthrough('div'), metadata: { name: 'Fragment', type: 'layout' } },
    };

    return registry;
}

/**
 * 주소에 이 조각이 든 요청의 응답을 정한다 (나머지 요청은 렌더 도구의 모의가 그대로 받는다).
 *
 * @param urlPart 주소 조각
 * @param status HTTP 상태
 * @param body 응답 본문
 */
function respondTo(urlPart: string, status: number, body: Record<string, unknown>): void {
    const fetchMock = globalThis.fetch as unknown as Mock;
    const fallback = fetchMock.getMockImplementation();

    fetchMock.mockImplementation(async (url: string, init?: RequestInit) => {
        if (!String(url).includes(urlPart)) {
            return fallback?.(url, init);
        }

        return {
            ok: status < 400,
            status,
            json: async () => body,
            text: async () => JSON.stringify(body),
            headers: new Headers({ 'Content-Type': 'application/json' }),
        };
    });
}

let testUtils: ReturnType<typeof createLayoutTest> | null = null;

/**
 * 화면을 그린다.
 *
 * @param prepare 렌더 전 모의 설정
 * @returns 렌더 결과
 */
async function renderScreen(prepare?: (utils: ReturnType<typeof createLayoutTest>) => void): Promise<{ container: HTMLElement }> {
    testUtils = createLayoutTest(layout as any, {
        componentRegistry: setupRegistry(),
        translations: { 'sirsoft-cdn_cloudfront': ko },
        locale: 'ko',
        routeParams: { identifier: 'sirsoft-cdn_cloudfront' },
        auth: { isAuthenticated: true, authType: 'admin' },
        initialState: { _local: { form: { ...SETTINGS.data } } },
    });
    testUtils.mockApi('settings', { response: SETTINGS });
    testUtils.mockApi('cdncfPurgeStatus', { response: STATUS });
    prepare?.(testUtils);

    return testUtils.render();
}

/**
 * 연결 시험 버튼을 누른다.
 *
 * @returns 없음
 */
async function clickTestConnection(): Promise<void> {
    fireEvent.click(screen.getByRole('button', { name: label.connection.button }));
}

afterEach(() => {
    testUtils?.cleanup();
    testUtils = null;

    if (savedRegistry) {
        (ComponentRegistry.getInstance() as any).registry = savedRegistry;
    }
});

describe('연결 시험 결과', () => {
    /**
     * @scenario case=connection_success
     * @effects connection_result_shown_with_localized_status, host_mismatch_warning_shown
     */
    it('성공하면 배포 상태를 번역해 보이고, CDN 주소가 다르면 경고한다', async () => {
        await renderScreen();
        respondTo('test-connection', 200, {
            success: true,
            message: '연결되었습니다',
            data: { ok: true, status: 'Deployed', domain_name: 'd111.cloudfront.net', enabled: true, cdn_host_matches: false },
        });

        await clickTestConnection();

        expect(await screen.findByText(label.connection.status_deployed)).toBeInTheDocument();
        expect(screen.getByText('d111.cloudfront.net')).toBeInTheDocument();
        expect(screen.getByText(label.connection.host_mismatch)).toBeInTheDocument();
        expect(screen.queryByText('Deployed')).not.toBeInTheDocument();
        expect(screen.queryByText(label.connection.disabled)).not.toBeInTheDocument();
        expect(testUtils!.getToasts()).toEqual([expect.objectContaining({ type: 'success', message: '연결되었습니다' })]);
    });

    /**
     * @scenario case=connection_disabled
     * @effects disabled_distribution_warning_shown
     */
    it('배포가 꺼져 있으면 안내하고, 진행 중 상태도 번역한다. 호스트가 맞으면 경고가 없다', async () => {
        await renderScreen();
        respondTo('test-connection', 200, {
            success: true,
            message: 'ok',
            data: { ok: true, status: 'InProgress', domain_name: 'd222.cloudfront.net', enabled: false, cdn_host_matches: true },
        });

        await clickTestConnection();

        expect(await screen.findByText(label.connection.disabled)).toBeInTheDocument();
        expect(screen.getByText(label.connection.status_in_progress)).toBeInTheDocument();
        expect(screen.queryByText(label.connection.host_mismatch)).not.toBeInTheDocument();
    });

    /**
     * @scenario case=connection_failed
     * @effects connection_failure_toasts_without_result
     */
    it('422 면 서버 사유를 토스트로 알리고 결과 칸을 그리지 않으며, 다시 누를 수 있다', async () => {
        await renderScreen();
        respondTo('test-connection', 422, { success: false, message: 'NoSuchDistribution: 없는 배포입니다', errors: { reason: 'request_failed' } });

        await clickTestConnection();

        await waitFor(() => expect(testUtils!.getToasts()).toEqual([expect.objectContaining({ type: 'error', message: 'NoSuchDistribution: 없는 배포입니다' })]));
        expect(screen.queryByText(label.connection.status)).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: label.connection.button })).toBeEnabled();
    });
});

describe('무효화 대기 현황', () => {
    /**
     * @scenario case=status_forbidden
     * @effects status_forbidden_hides_stats_keeps_form
     */
    it('403 이면 안내만 보이고 통계는 숨기며 설정 폼은 그대로 둔다', async () => {
        await renderScreen((utils) => utils.mockApiError('cdncfPurgeStatus', 403, '권한 없음'));

        expect(await screen.findByText(label.purge_status.forbidden)).toBeInTheDocument();
        expect(screen.queryByText(label.purge_status.pending)).not.toBeInTheDocument();
        expect(screen.getByText(label.fields.cdn_base_url.label)).toBeInTheDocument();
    });

    /**
     * @scenario case=status_not_ready
     * @effects status_not_ready_notice_shown
     */
    it('purge_ready=false 면 무효화를 보내지 않는다고 알리고 대기 건수는 계속 보인다', async () => {
        await renderScreen((utils) => utils.mockApi('cdncfPurgeStatus', { response: { data: { ...STATUS.data, purge_ready: false } } }));

        expect(await screen.findByText(label.purge_status.not_ready)).toBeInTheDocument();
        expect(screen.getByText('2')).toBeInTheDocument();
    });

    /**
     * @scenario case=status_ready
     * @effects status_not_ready_notice_shown
     */
    it('무효화할 수 있으면 안내가 없고, 마지막 오류가 있으면 사유를 보인다', async () => {
        await renderScreen((utils) => utils.mockApi('cdncfPurgeStatus', { response: { data: { ...STATUS.data, last_error: 'AccessDenied: 거부됨' } } }));

        expect(await screen.findByText('AccessDenied: 거부됨')).toBeInTheDocument();
        expect(screen.queryByText(label.purge_status.not_ready)).not.toBeInTheDocument();
    });

    /**
     * @scenario case=status_empty
     * @effects status_empty_queue_shows_zero
     */
    it('대기열이 비어 있으면 대기·중단 칸이 0 을 보이고, 오류·안내 문구는 없다', async () => {
        await renderScreen((utils) => utils.mockApi('cdncfPurgeStatus', { response: { data: { ...STATUS.data, pending: 0, abandoned: 0 } } }));

        // 값 칸은 라벨 바로 다음 형제다(레이아웃 `cdncf-stat__label` → `cdncf-stat__value`)
        const pending = (await screen.findByText(label.purge_status.pending)).nextElementSibling;
        const abandoned = screen.getByText(label.purge_status.abandoned).nextElementSibling;
        expect(pending?.textContent).toBe('0');
        expect(abandoned?.textContent).toBe('0');
        expect(screen.queryByText(label.purge_status.not_ready)).not.toBeInTheDocument();
        expect(screen.queryByText(label.purge_status.forbidden)).not.toBeInTheDocument();
    });
});

describe('설정 저장 실패', () => {
    /**
     * @scenario case=save_failed
     * @effects save_failure_shows_field_errors
     */
    it('422 의 errors 를 화면 상단 목록과 해당 칸에 보이고 토스트로 알린다', async () => {
        await renderScreen();
        testUtils!.setState('hasChanges', true, 'local');
        await testUtils!.rerender();
        const message = 'CDN 주소 형식이 올바르지 않습니다.';
        respondTo('/settings', 422, { success: false, message: '입력값을 확인하세요', errors: { cdn_base_url: [message] } });

        fireEvent.click(screen.getByRole('button', { name: 'common.save' }));

        await waitFor(() => expect(screen.getAllByText(message)).toHaveLength(2));
        expect(testUtils!.getToasts()).toEqual([expect.objectContaining({ type: 'error', message: '입력값을 확인하세요' })]);
    });
});
