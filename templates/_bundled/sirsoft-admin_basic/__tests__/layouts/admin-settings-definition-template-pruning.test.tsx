/**
 * @file admin-settings-definition-template-pruning.test.tsx
 * @description 알림/본인인증 정의 목록 — 템플릿 프루닝 후 화면 계약 회귀 테스트
 *
 * 회귀 배경 (#518 / 공개 #76):
 * 두 정의 목록이 행마다 채널×로케일 템플릿 본문을 전량 싣고 있었다. 계획서는 목록에서
 * `templates` 를 빼고 `withCount` 로 대체하라고 했으나, 소비처를 실측하니 두 화면 모두 템플릿
 * 본문을 실제로 렌더하고 있었다 — 통째로 빼면 기능이 사라진다. 그래서 방식을 바꿨다.
 *
 *  - 알림 정의: 행은 그대로 두고 **각 행의 템플릿을 한 채널로 좁혀** 싣는다(`template_channel`).
 *  - 본인인증 정의: 화면이 `templates[0]` 만 읽으므로 **대표 1건**만 싣는다.
 *
 * 이 방식은 응답을 줄이는 대신 **새로운 방식의 회귀 가능성**을 만든다.
 *
 *  1. 서버가 좁혀 보낸 채널과 화면이 찾는 채널이 어긋나면 → 템플릿이 "없음" 으로 보인다.
 *     예외도 콘솔 경고도 없다. 두 표현식이 각각 옳아도 **서로 다르면** 깨진다.
 *  2. 채널을 가리지 않는 판정(예전의 `templates.some(t => !t.is_default)`)이 남아 있으면 →
 *     한 채널만 실린 배열을 전수라고 오판해, 다른 채널만 수정한 정의를 놓친다.
 *  3. 본인인증 쪽이 `templates[1]` 이상을 읽으면 → 대표 1건만 오는 목록에서 영영 비어 있다.
 *
 * 셋 다 응답만 보는 백엔드 테스트로는 잡히지 않는다 — 화면이 무엇을 찾는지가 조건이기 때문이다.
 *
 * 마킹은 아래 describe 블록마다 따로 붙인다 — 한 docblock 에서는 @scenario/@effects 가
 * 각각 첫 줄만 읽히므로, 여기에 두 리소스를 나열하면 뒤엣것이 조용히 누락된다.
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

const SETTINGS_LAYOUT = path.resolve(__dirname, '../../layouts/admin_settings.json');
const NOTIFICATION_TAB = path.resolve(
    __dirname,
    '../../layouts/partials/admin_settings/_tab_notification_definitions.json',
);
const IDENTITY_TAB = path.resolve(
    __dirname,
    '../../layouts/partials/admin_settings/_tab_identity_messages.json',
);

/**
 * JSON 트리에서 `{{ }}` 바인딩 표현식을 모두 수집합니다.
 *
 * @param node 탐색할 JSON 노드
 * @param acc 수집 결과 누적 배열
 * @returns 수집된 바인딩 표현식 목록
 */
function collectBindings(node: unknown, acc: string[] = []): string[] {
    if (typeof node === 'string') {
        const matches = node.match(/\{\{[\s\S]*?\}\}/g);

        if (matches) {
            acc.push(...matches);
        }

        return acc;
    }

    if (Array.isArray(node)) {
        node.forEach((child) => collectBindings(child, acc));

        return acc;
    }

    if (node && typeof node === 'object') {
        Object.values(node as Record<string, unknown>).forEach((child) =>
            collectBindings(child, acc),
        );
    }

    return acc;
}

/**
 * 공백을 제거해 표현식을 비교 가능한 형태로 만듭니다.
 *
 * 채널 표현식은 데이터소스와 소비자 양쪽에 손으로 적혀 있어 들여쓰기·공백이 다를 수 있다.
 * 의미가 같은지만 보면 되므로 공백을 지우고 비교한다.
 *
 * @param expression 정규화할 표현식
 * @returns 공백이 제거된 표현식
 */
function normalize(expression: string): string {
    return expression.replace(/\s+/g, '');
}

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
 * 표현식에서 `{{ }}` 와 겉을 감싼 괄호를 벗겨 알맹이만 남깁니다.
 *
 * 데이터소스는 `{{...}}` 로, 소비자 표현식은 `(...)` 로 감싸 쓰므로 표기가 다르다.
 * 비교해야 하는 것은 표기가 아니라 채널을 고르는 식 자체다.
 *
 * @param expression 벗겨낼 표현식
 * @returns 공백·래핑이 제거된 표현식
 */
function unwrap(expression: string): string {
    let inner = normalize(expression).replace(/^\{\{/, '').replace(/\}\}$/, '');

    while (inner.startsWith('(') && inner.endsWith(')')) {
        inner = inner.slice(1, -1);
    }

    return inner;
}

/**
 * 소비자 표현식에서 채널 선택식을 뽑아냅니다.
 *
 * `t.channel === (...)` 의 괄호 안이 화면이 "이 채널의 템플릿" 이라고 판단하는 기준이다.
 * 상수로 손으로 적어두면 화면 쪽이 바뀌어도 테스트가 계속 통과하므로 실제 값에서 뽑는다.
 *
 * @param bindings 수집된 바인딩 표현식 목록
 * @returns 채널 선택식 (없으면 null)
 */
function extractConsumerChannel(bindings: string[]): string | null {
    for (const binding of bindings) {
        const match = normalize(binding).match(/t\.channel===(\([^)]*\)|[^)]+?)\)/);

        if (match) {
            return unwrap(match[1]);
        }
    }

    return null;
}

/**
 * @scenario resource=notification_definition,endpoint=list,observation=consumer_screen
 * @effects definition_list_channel_scope_matches_consumer, definition_list_cross_channel_judgment_uses_aggregate
 */
describe('알림 정의 목록 — 채널 스코프 (#518 / 공개 #76)', () => {
    it('데이터소스가 요청하는 채널과 화면이 찾는 채널이 같다', () => {
        const layout = readLayout(SETTINGS_LAYOUT);

        const source = (layout.data_sources ?? []).find((ds: any) =>
            String(ds.endpoint ?? '').includes('/admin/notification-definitions'),
        );

        expect(source, '알림 정의 데이터소스가 있어야 한다').toBeDefined();

        const requested = source.params?.template_channel;

        expect(
            requested,
            '목록이 한 채널만 싣도록 template_channel 을 보내야 한다 (안 보내면 templates 키 자체가 없다)',
        ).toBeDefined();

        const consumerChannel = extractConsumerChannel(collectBindings(readLayout(NOTIFICATION_TAB)));

        // 추출이 실패해 양쪽이 나란히 빈 문자열이면 아래 동치 단언은 통과해 버린다.
        // 뽑아낸 값이 실제 채널식인지부터 확정한다.
        expect(
            consumerChannel,
            '화면이 템플릿을 고를 때 쓰는 채널 표현식을 찾아야 한다',
        ).toContain('query.channel');

        // 서버가 좁혀 보낸 채널과 화면이 찾는 채널이 다르면, 각 표현식이 따로따로는 옳아도
        // 템플릿이 "없음" 으로 보인다. 그래서 두 실제 값을 직접 대조한다.
        expect(unwrap(String(requested))).toBe(consumerChannel);
    });

    it('채널 표현식이 URL 단일 출처다 (_local 을 섞지 않는다)', () => {
        // 회귀 배경 (#518 3차 실측 결함 #1):
        // 종전 표현식은 `_local.activeNotificationChannel ?? query.channel ?? 'mail'` 이었다.
        // 데이터소스와 소비자에 **글자까지 같은** 식이 적혀 있었으므로 위의 동치 단언은 통과했지만,
        // 두 출처는 갱신 시점이 다르다. 다른 설정 탭에 갔다 돌아오면 URL 의 channel 은 사라지고
        // (탭 전환은 목록 상태를 승계하지 않는다) `_local` 만 이전 채널로 남는다. 그 사이 목록은
        // 기본 채널로 다시 불려 오고, 화면은 남아 있는 이전 채널로 템플릿을 찾는다 —
        // 행마다 「이 채널에 대한 템플릿이 없습니다」 가 뜨고 예외도 콘솔 경고도 남지 않는다.
        //
        // 두 표현식이 같은지가 아니라, **출처가 하나인지**가 조건이다.
        const bindings = [
            ...collectBindings(readLayout(SETTINGS_LAYOUT)),
            ...collectBindings(readLayout(NOTIFICATION_TAB)),
        ];

        const channelReads = bindings.filter((binding) => /query\.channel/.test(binding));

        expect(
            channelReads.length,
            '채널을 읽는 표현식이 있어야 한다 (0건이면 이 단언은 공회전한다)',
        ).toBeGreaterThan(0);

        const dualSourced = channelReads.filter((binding) => /_local\./.test(binding));

        expect(
            dualSourced,
            'URL 이 나르지 않는 값(_local)을 채널 출처로 섞으면 데이터와 화면이 서로 다른 채널을 본다',
        ).toEqual([]);
    });

    it('템플릿을 고르는 모든 표현식이 채널로 좁혀져 있다', () => {
        const bindings = collectBindings(readLayout(NOTIFICATION_TAB));

        expect(bindings.length, '바인딩이 수집되어야 한다').toBeGreaterThan(20);

        const templateReads = bindings.filter((binding) => /\bdef\.templates\b/.test(binding));

        // 모집단 확인 — 템플릿을 읽는 표현식이 0건이면 아래 단언은 공회전한다.
        expect(
            templateReads.length,
            '화면이 템플릿을 실제로 읽고 있어야 한다 (읽지 않는다면 프루닝 방식 자체를 재검토해야 한다)',
        ).toBeGreaterThan(0);

        const unscoped = templateReads.filter(
            (binding) => !/t\.channel\s*===/.test(binding),
        );

        expect(
            unscoped,
            '채널로 좁히지 않고 templates 를 읽으면, 한 채널만 실린 배열을 전수로 오판한다',
        ).toEqual([]);
    });

    it('채널을 가리지 않는 판정은 서버 집계를 쓴다', () => {
        const bindings = collectBindings(readLayout(NOTIFICATION_TAB));

        expect(
            bindings.some((binding) => /\bdef\.has_customized_templates\b/.test(binding)),
            '"수정된 템플릿이 있는가" 는 전 채널 기준이라 집계로만 판정할 수 있다',
        ).toBe(true);

        // 예전 구현. 한 채널만 실린 배열에 쓰면 다른 채널만 수정한 정의를 놓친다.
        const legacy = bindings.filter((binding) =>
            /def\.templates[\s\S]*\.some\s*\(/.test(binding),
        );

        expect(legacy, '전 채널 판정을 배열 순회로 되돌리면 안 된다').toEqual([]);
    });
});

/**
 * @scenario resource=identity_message_definition,endpoint=list,observation=consumer_screen
 * @effects identity_definition_list_uses_representative_template
 */
describe('본인인증 메시지 정의 목록 — 대표 템플릿 (#518 / 공개 #76)', () => {
    it('화면이 대표 1건만 읽는다 (목록이 그 이상을 싣지 않는다)', () => {
        const bindings = collectBindings(readLayout(IDENTITY_TAB));
        const templateReads = bindings.filter((binding) => /\bdef\.templates\b/.test(binding));

        expect(
            templateReads.length,
            '본인인증 정의 화면이 템플릿을 읽고 있어야 한다',
        ).toBeGreaterThan(0);

        // `templates[0]` 이외의 인덱스나 순회를 쓰면, 대표 1건만 오는 목록에서 영영 비어 있다.
        const beyondFirst = templateReads.filter((binding) =>
            /def\.templates\s*\??\.?\[\s*(?!0\s*\])/.test(binding),
        );

        expect(beyondFirst, '대표 1건을 넘어서는 참조가 있으면 목록에서 값이 비어 있다').toEqual(
            [],
        );

        const iterated = templateReads.filter((binding) =>
            /def\.templates[\s\S]*\.(map|forEach|filter|some|every|find)\s*\(/.test(binding),
        );

        expect(iterated, '목록에서 전량 순회는 성립하지 않는다 (대표 1건만 실린다)').toEqual([]);
    });
});
