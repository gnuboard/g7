/**
 * @file admin-settings-bizppurio-tab-filter.test.ts
 * @description 알림 정의 탭 — 확장 채널 통합 탭 필터/라벨 규칙 회귀 테스트 (#597)
 *
 * 배경: 비즈뿌리오 플러그인이 sms·alimtalk 개별 탭을 숨기고(hidden_tab) '비즈뿌리오' 통합
 * 탭 하나로 노출하는 구조로 전환됐다. 탭 필터 표현식은 세 규칙을 모두 담아야 한다:
 *
 * 1. hidden_tab 제외 — 확장이 hidden_tab:true 로 선언한 채널(sms/alimtalk 개별 탭)은
 *    목록에서 빠진다.
 * 2. 코어 채널 — 저장값(is_active)이 명시적으로 false 가 아니면 노출(기본 노출).
 * 3. 확장 채널 — tab_channels(통합 탭이 대표하는 채널 집합, 미선언 시 자기 자신) 중
 *    하나라도 활성 저장(is_active === true)이면 노출. 전부 꺼져 있으면 탭 자체가 사라진다.
 *
 * 탭 라벨은 확장이 준 tab_label_key 가 있으면 $t 로 해석하고, 없으면 name → id 폴백이다.
 * (tab_label_key 를 $t 없이 원문 출력하면 화면에 i18n 키가 그대로 노출된다.)
 *
 * 이 규칙은 코어/게시판/이커머스 3면 partial 이 문자열 수준으로 동일해야 한다 —
 * 한 면만 고치면 나머지 면에서 통합 탭이 조용히 사라지거나 라벨이 키로 노출된다.
 *
 * @scenario resource=notification_definitions_tab,endpoint=admin_settings,observation=tab_filter
 * @effects bizppurio_tab_replaces_sms_and_alimtalk_tabs, tab_visible_when_any_of_tab_channels_active
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

const ADMIN_BASIC_TAB = path.resolve(
    __dirname,
    '../../layouts/partials/admin_settings/_tab_notification_definitions.json',
);
const BOARD_TAB = path.resolve(
    __dirname,
    '../../../../../modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_tab_notification_definitions.json',
);
const ECOMMERCE_TAB = path.resolve(
    __dirname,
    '../../../../../modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_tab_notification_definitions.json',
);

/**
 * 레이아웃 파일을 읽어 파싱합니다.
 *
 * @param filePath 레이아웃 파일 절대 경로
 * @returns 파싱된 JSON 객체
 */
function readLayout(filePath: string): Record<string, any> {
    return JSON.parse(fs.readFileSync(filePath, 'utf-8'));
}

/**
 * id 가 *channel_sub_tabs 로 끝나는 서브탭 컨테이너를 찾습니다.
 * (면 접두: 코어 없음 / board_ / ecommerce_)
 *
 * @param node 탐색할 JSON 노드
 * @returns 서브탭 컨테이너 노드 (없으면 null)
 */
function findSubTabsContainer(node: unknown): Record<string, any> | null {
    if (!node || typeof node !== 'object') return null;
    if (Array.isArray(node)) {
        for (const child of node) {
            const found = findSubTabsContainer(child);
            if (found) return found;
        }
        return null;
    }
    const n = node as Record<string, any>;
    if (typeof n.id === 'string' && n.id.endsWith('channel_sub_tabs')) {
        return n;
    }
    for (const value of Object.values(n)) {
        const found = findSubTabsContainer(value);
        if (found) return found;
    }
    return null;
}

/**
 * 서브탭 컨테이너 안의 채널 탭 반복(iteration) 버튼 노드를 찾습니다.
 *
 * @param node 탐색할 JSON 노드
 * @returns 탭 버튼 iteration 노드 (없으면 null)
 */
function findChannelTabIteration(node: unknown): Record<string, any> | null {
    const container = findSubTabsContainer(node);
    if (!container) return null;
    const walk = (n: unknown): Record<string, any> | null => {
        if (!n || typeof n !== 'object') return null;
        if (Array.isArray(n)) {
            for (const child of n) {
                const found = walk(child);
                if (found) return found;
            }
            return null;
        }
        const obj = n as Record<string, any>;
        if (typeof obj.iteration?.source === 'string' && obj.iteration.source.includes('availableChannels')) {
            return obj;
        }
        for (const value of Object.values(obj)) {
            const found = walk(value);
            if (found) return found;
        }
        return null;
    };
    return walk(container);
}

describe('알림 정의 탭 필터 — 확장 채널 통합 탭 규칙 (#597)', () => {
    const tabNode = findChannelTabIteration(readLayout(ADMIN_BASIC_TAB));
    const source: string = tabNode?.iteration?.source ?? '';

    it('채널 탭 반복 노드가 존재한다 (0건이면 아래 단언은 공회전한다)', () => {
        expect(tabNode).toBeTruthy();
        expect(source.length).toBeGreaterThan(0);
    });

    it('hidden_tab:true 채널을 목록에서 제외한다 (sms·alimtalk 개별 탭 숨김)', () => {
        expect(source).toContain('c.hidden_tab !== true');
    });

    it('코어 채널은 저장값이 명시적 false 가 아니면 노출된다 (기본 노출)', () => {
        expect(source).toContain(
            "c.source === 'core' ? (_local.form?.notifications?.channels ?? []).find(nc => nc.id === c.id)?.is_active !== false",
        );
    });

    it('확장 채널은 tab_channels 중 하나라도 활성 저장이면 노출된다 (전부 꺼지면 탭 소멸)', () => {
        expect(source).toContain(
            "(c.tab_channels ?? [c.id]).filter(tc => (_local.form?.notifications?.channels ?? []).find(nc => nc.id === tc)?.is_active === true).length > 0",
        );
    });

    it('탭 라벨은 tab_label_key 를 $t 로 해석하고 name → id 로 폴백한다', () => {
        const raw = JSON.stringify(tabNode);
        expect(raw).toContain('{{ch.tab_label_key ? $t(ch.tab_label_key) : (ch.name ?? ch.id)}}');
    });
});

describe.each([
    ['admin_basic', ADMIN_BASIC_TAB],
    ['board', BOARD_TAB],
    ['ecommerce', ECOMMERCE_TAB],
])('알림 정의 탭 필터 — 조합 실평가 [%s] (#597 §14.2 T8)', (_face, layoutPath) => {
    // 문자열 단언은 표현식 오타(예: !== 를 === 로)를 잡지 못한다. 레이아웃 파일에서 추출한
    // 표현식 원문을 그대로 실행해 저장값 조합별 노출 결과를 판정한다.
    //
    // 3면 각각을 실평가한다 — 패리티 블록(문자열 동일성)만으로 1면 평가를 3면으로 확대
    // 해석하면, 패리티 판정이 낡거나 한 면만 손댄 순간 그 면의 조합 결과가 미측정으로
    // 남는다. 세 면의 표현식이 같다면 세 벌 평가는 그 사실을 재확인할 뿐이고, 달라지는
    // 순간에만 비용이 드러난다.
    const tabNode = findChannelTabIteration(readLayout(layoutPath));
    const expr = (tabNode?.iteration?.source ?? '').replace(/^\{\{|\}\}$/g, '');

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
     * 레이아웃의 필터 표현식 원문을 실행해 노출 탭 id 목록을 반환합니다.
     *
     * @param saved _local.form.notifications.channels 저장값
     * @param channels availableChannels 응답 채널 목록 (기본: 코어2 + 비즈뿌리오2)
     * @returns 필터를 통과한 채널 id 배열
     */
    function visibleTabs(
        saved: Array<{ id: string; is_active: boolean }>,
        channels: Array<Record<string, any>> = CHANNELS,
    ): string[] {
        const fn = new Function('availableChannels', '_local', `return ${expr};`);
        const result = fn(
            { data: { channels } },
            { form: { notifications: { channels: saved } } },
        ) as Array<{ id: string }>;
        return result.map((c) => c.id);
    }

    it('sms·alimtalk 모두 활성 → 통합 탭 1개만 노출, sms 개별 탭은 숨김', () => {
        expect(visibleTabs([
            { id: 'sms', is_active: true },
            { id: 'alimtalk', is_active: true },
        ])).toEqual(['mail', 'database', 'alimtalk']);
    });

    it('sms 만 활성(alimtalk 비활성) → tab_channels 규칙으로 통합 탭 노출 (§6.3 1d)', () => {
        expect(visibleTabs([
            { id: 'sms', is_active: true },
            { id: 'alimtalk', is_active: false },
        ])).toEqual(['mail', 'database', 'alimtalk']);
    });

    it('alimtalk 만 활성 → 통합 탭 노출', () => {
        expect(visibleTabs([{ id: 'alimtalk', is_active: true }])).toEqual(['mail', 'database', 'alimtalk']);
    });

    it('sms·alimtalk 모두 비활성 → 통합 탭 미노출 (§6.3 1e)', () => {
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

describe('알림 정의 탭 필터 — 3면 패리티 (#597)', () => {
    /**
     * partial 에서 탭 필터 표현식과 라벨 표현식을 추출합니다.
     *
     * @param filePath partial 절대 경로
     * @returns { source, label } 두 표현식
     */
    function extractExpressions(filePath: string): { source: string; label: string } {
        const node = findChannelTabIteration(readLayout(filePath));
        expect(node, `${path.basename(path.dirname(filePath))} 의 채널 탭 반복 노드를 찾지 못했다`).toBeTruthy();
        const raw = JSON.stringify(node);
        // 라벨 표현식 내부에는 '}' 가 없으므로 비탐욕 매치가 전체 표현식을 정확히 얻는다
        const label = raw.match(/\{\{ch\.tab_label_key[\s\S]*?\}\}/)?.[0] ?? '';
        return { source: node!.iteration.source as string, label };
    }

    const core = extractExpressions(ADMIN_BASIC_TAB);

    it.each([
        ['게시판', BOARD_TAB],
        ['이커머스', ECOMMERCE_TAB],
    ])('%s partial 의 탭 필터 표현식이 admin_basic 과 문자열 동일하다', (label, file) => {
        const other = extractExpressions(file);
        expect(other.source, `${label} 탭 필터 표현식 불일치 — 한 면만 고치면 통합 탭이 그 면에서 사라진다`).toBe(core.source);
    });

    it.each([
        ['게시판', BOARD_TAB],
        ['이커머스', ECOMMERCE_TAB],
    ])('%s partial 의 탭 라벨 표현식이 admin_basic 과 문자열 동일하다', (label, file) => {
        expect(core.label.length, '라벨 표현식 추출 실패 (기준점이 비면 패리티 단언이 공회전한다)').toBeGreaterThan(0);
        const other = extractExpressions(file);
        expect(other.label, `${label} 탭 라벨 표현식 불일치`).toBe(core.label);
    });
});
