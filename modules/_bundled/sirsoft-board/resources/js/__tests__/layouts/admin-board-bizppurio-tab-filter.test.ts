// e2e:allow 레이아웃 조건식 실평가 전용 — 브라우저 흐름은 비즈뿌리오 플러그인 spec 이 담당한다.
/**
 * 게시판 알림 설정 — 채널 서브탭 필터 조합 실평가 (#597 §14.2 T8)
 *
 * 이 면의 서브탭 필터는 admin_basic·이커머스와 같은 식을 공유한다. 그 동일성은
 * admin_basic 쪽 패리티 테스트가 고정하지만, 그 테스트는 **admin_basic 템플릿의
 * 러너에서만** 수집된다 — 게시판 모듈만 단독으로 Vitest 를 돌리면 이 면의 필터는
 * 한 번도 평가되지 않는다. 그래서 각 면이 자기 파일을 자기 러너에서 평가한다.
 *
 * 검증 대상: 확장이 선언한 통합 탭 메타(hidden_tab / tab_channels)를 필터가 실제로
 * 해석하는가. 문자열 동일성 단언은 `!==` 를 `===` 로 바꾸는 오타를 잡지 못한다.
 *
 * @scenario resource=notification_definitions_tab,endpoint=admin_board_settings,observation=tab_filter
 *
 * @effects bizppurio_tab_replaces_sms_and_alimtalk_tabs, tab_visible_when_any_of_tab_channels_active
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

const TAB_LAYOUT = path.resolve(
    __dirname,
    '../../../layouts/admin/partials/admin_board_settings/_tab_notification_definitions.json',
);

/** availableChannels 응답 형태 (코어 2 + 비즈뿌리오 2) */
const CHANNELS = [
    { id: 'mail', source: 'core', name: '메일' },
    { id: 'database', source: 'core', name: '사이트내 알림' },
    { id: 'sms', source: 'sirsoft-message_bizppurio', name: '비즈뿌리오 문자', hidden_tab: true },
    {
        id: 'alimtalk',
        source: 'sirsoft-message_bizppurio',
        name: '비즈뿌리오 알림톡',
        tab_channels: ['sms', 'alimtalk'],
        tab_label_key: 'sirsoft-message_bizppurio.channels.bizppurio_tab',
    },
];

/**
 * id 가 *channel_sub_tabs 로 끝나는 서브탭 컨테이너를 찾습니다.
 *
 * @param node 탐색할 JSON 노드
 * @returns 서브탭 컨테이너 노드 (없으면 null)
 */
function findSubTabsContainer(node: unknown): Record<string, any> | null {
    if (Array.isArray(node)) {
        for (const child of node) {
            const found = findSubTabsContainer(child);
            if (found) return found;
        }
        return null;
    }
    if (!node || typeof node !== 'object') return null;

    const record = node as Record<string, any>;
    if (typeof record.id === 'string' && record.id.endsWith('channel_sub_tabs')) return record;

    for (const value of Object.values(record)) {
        const found = findSubTabsContainer(value);
        if (found) return found;
    }
    return null;
}

/**
 * 서브탭 컨테이너 안에서 iteration 을 가진 탭 버튼 노드를 찾습니다.
 *
 * @param node 탐색할 JSON 노드
 * @returns iteration 보유 노드 (없으면 null)
 */
function findIterationNode(node: unknown): Record<string, any> | null {
    if (Array.isArray(node)) {
        for (const child of node) {
            const found = findIterationNode(child);
            if (found) return found;
        }
        return null;
    }
    if (!node || typeof node !== 'object') return null;

    const record = node as Record<string, any>;
    if (record.iteration && typeof record.iteration.source === 'string') return record;

    for (const value of Object.values(record)) {
        const found = findIterationNode(value);
        if (found) return found;
    }
    return null;
}

describe('게시판 알림 설정 — 채널 서브탭 필터 실평가 (#597)', () => {
    const layout = JSON.parse(fs.readFileSync(TAB_LAYOUT, 'utf-8'));
    const container = findSubTabsContainer(layout);
    const tabNode = container ? findIterationNode(container) : null;
    const expr = String(tabNode?.iteration?.source ?? '').replace(/^\{\{|\}\}$/g, '');

    /**
     * 필터 표현식 원문을 실행해 노출 탭 id 목록을 반환합니다.
     *
     * @param saved _local.form.notifications.channels 저장값
     * @param channels availableChannels 채널 목록
     * @returns 필터를 통과한 채널 id 배열
     */
    function visibleTabs(
        saved: Array<{ id: string; is_active: boolean }>,
        channels: Array<Record<string, any>> = CHANNELS,
    ): string[] {
        // eslint-disable-next-line no-new-func
        const fn = new Function('availableChannels', '_local', `return ${expr};`);
        const result = fn(
            { data: { channels } },
            { form: { notifications: { channels: saved } } },
        ) as Array<{ id: string }>;
        return result.map((c) => c.id);
    }

    it('서브탭 필터 표현식을 레이아웃에서 추출할 수 있다', () => {
        expect(container, '*channel_sub_tabs 컨테이너를 찾지 못했다').toBeTruthy();
        expect(expr, '탭 iteration source 가 비어 있다').not.toBe('');
    });

    it('sms·alimtalk 모두 활성 → 통합 탭 1개만 노출, sms 개별 탭은 숨김', () => {
        expect(visibleTabs([
            { id: 'sms', is_active: true },
            { id: 'alimtalk', is_active: true },
        ])).toEqual(['mail', 'database', 'alimtalk']);
    });

    it('sms 만 활성(alimtalk 비활성) → tab_channels 규칙으로 통합 탭 노출', () => {
        expect(visibleTabs([
            { id: 'sms', is_active: true },
            { id: 'alimtalk', is_active: false },
        ])).toEqual(['mail', 'database', 'alimtalk']);
    });

    it('sms·alimtalk 모두 비활성 → 통합 탭 미노출', () => {
        expect(visibleTabs([
            { id: 'sms', is_active: false },
            { id: 'alimtalk', is_active: false },
        ])).toEqual(['mail', 'database']);
    });

    it('확장 채널 저장값 자체가 없으면(미저장=opt-in 전) 통합 탭 미노출', () => {
        expect(visibleTabs([])).toEqual(['mail', 'database']);
    });

    it('코어 채널은 저장값이 명시적 false 일 때만 숨는다 (기본 노출)', () => {
        expect(visibleTabs([{ id: 'mail', is_active: false }])).toEqual(['database']);
    });

    it('tab_channels 미선언 확장 채널은 자기 자신의 활성 저장 기준으로 판정된다', () => {
        const withPlain = [...CHANNELS, { id: 'push', source: 'some-plugin', name: '푸시' }];
        expect(visibleTabs([{ id: 'push', is_active: true }], withPlain)).toEqual(['mail', 'database', 'push']);
        expect(visibleTabs([{ id: 'push', is_active: false }], withPlain)).toEqual(['mail', 'database']);
    });
});
