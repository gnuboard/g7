/**
 * @file admin-ecommerce-notification-channel-single-source.test.ts
 * @description 커머스 환경설정 알림 정의 탭 — 채널 표현식 단일 출처 회귀 테스트
 *
 * 회귀 배경 (#518 3차 실측 결함 #1):
 * 알림 정의 탭의 채널은 URL(`query.channel`)과 페이지 로컬 상태(`_local.activeNotificationChannel`)
 * 두 곳에서 읽히고 있었다. 두 값은 갱신 시점이 다르다 — 다른 설정 탭에 갔다 돌아오면 URL 의
 * channel 은 사라지지만(탭 전환은 목록 상태를 승계하지 않는다) `_local` 은 이전 채널로 남는다.
 *
 * 코어 설정 화면은 그 사이에 목록을 기본 채널로 다시 불러오므로, 남아 있는 이전 채널로 템플릿을
 * 찾다가 행마다 「이 채널에 대한 템플릿이 없습니다」 를 띄웠다.
 *
 * 그래서 조건은 "두 표현식이 같은가" 가 아니라 "출처가 하나인가" 다.
 *
 * 2차 회귀 (#518 계획서 검증 브라우저 실측, 2026-08-05):
 * 목록 프루닝으로 서버가 `template_channel` 을 받은 요청에만 `templates` 를 싣게 됐는데
 * (`NotificationDefinitionRepository::getPaginated`), 코어 화면만 그 파라미터를 보내도록
 * 고쳐지고 커머스 탭 데이터소스는 빠졌다. 그 결과 10건 전 행이 「이 채널에 대한 템플릿이
 * 없습니다」 로 표시되고 수신자·활성 토글·편집 모달 초기값이 통째로 비었다.
 *
 * 조용히 깨지는 종류다 — `(def.templates ?? [])` 가드 뒤라 예외도 콘솔 경고도 없고,
 * 응답은 200 이며 행 수도 정상이라 "템플릿을 아직 안 만들었나 보다" 로 읽힌다.
 * 그래서 아래 두 단언은 소비와 전송을 **함께** 묶는다: templates 를 읽는 화면이면
 * 그 채널을 서버에 넘겨야 한다.
 *
 * @scenario resource=ecommerce_notification_definition,endpoint=admin_settings,observation=consumer_screen
 * @effects ecommerce_notification_channel_reads_single_source, ecommerce_notification_list_requests_template_channel, ecommerce_notification_reset_button_uses_server_aggregate
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

const SETTINGS_LAYOUT = path.resolve(
    __dirname,
    '../../../layouts/admin/admin_ecommerce_settings.json',
);
const NOTIFICATION_TAB = path.resolve(
    __dirname,
    '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_notification_definitions.json',
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
 * 레이아웃 파일을 읽어 파싱합니다.
 *
 * @param filePath 레이아웃 파일 절대 경로
 * @returns 파싱된 JSON 객체
 */
function readLayout(filePath: string): Record<string, any> {
    return JSON.parse(fs.readFileSync(filePath, 'utf-8'));
}

describe('커머스 알림 정의 탭 — 채널 단일 출처 (#518)', () => {
    it('채널 표현식이 URL 만 읽는다', () => {
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

    it('채널 전환은 URL 만 갱신한다 (로컬 상태에 복제하지 않는다)', () => {
        const raw = fs.readFileSync(NOTIFICATION_TAB, 'utf-8');

        expect(
            raw.includes('activeNotificationChannel'),
            '채널을 로컬 상태에 복제하면 URL 과 어긋날 수 있는 두 번째 출처가 다시 생긴다',
        ).toBe(false);

        expect(
            fs.readFileSync(SETTINGS_LAYOUT, 'utf-8').includes('activeNotificationChannel'),
            'initLocal 에 남겨두면 소비자가 다시 참조하게 된다',
        ).toBe(false);
    });
});

describe('커머스 알림 정의 탭 — 서버 채널 필터 정합 (#518)', () => {
    /**
     * 알림 정의 목록 데이터소스를 찾습니다.
     *
     * @returns 데이터소스 정의 객체
     */
    function findDefinitionsDataSource(): Record<string, any> {
        const layout = readLayout(SETTINGS_LAYOUT);
        const source = (layout.data_sources ?? []).find((ds: Record<string, any>) =>
            typeof ds.endpoint === 'string' && ds.endpoint.includes('/api/admin/notification-definitions'),
        );

        expect(source, '알림 정의 목록 데이터소스를 찾지 못했다').toBeTruthy();

        return source;
    }

    it('templates 를 그리는 화면이면 목록 요청에 template_channel 을 보낸다', () => {
        const consumesTemplates = collectBindings(readLayout(NOTIFICATION_TAB)).some((binding) =>
            /def\.templates/.test(binding),
        );

        expect(
            consumesTemplates,
            'templates 소비가 0건이면 이 단언은 공회전한다 (탭 구조가 바뀌었는지 확인)',
        ).toBe(true);

        // 서버는 `template_channel` 을 받은 요청에만 templates 를 싣는다. 화면이 templates 를
        // 읽으면서 이 파라미터를 빼면 전 행이 「이 채널에 대한 템플릿이 없습니다」 가 된다.
        expect(
            findDefinitionsDataSource().params?.template_channel,
            'templates 를 소비하는 목록이 채널을 서버에 넘기지 않으면 응답에 templates 키 자체가 없다',
        ).toBeDefined();
    });

    it('서버에 넘기는 채널과 화면이 찾는 채널이 같은 표현식이다', () => {
        const sent = findDefinitionsDataSource().params.template_channel;

        // 화면이 템플릿을 고를 때 쓰는 채널 표현식을 추출한다.
        const screenChannel = collectBindings(readLayout(NOTIFICATION_TAB))
            .find((binding) => /t\.channel === /.test(binding))
            ?.match(/t\.channel === \(([^)]+)\)/)?.[1];

        expect(screenChannel, '화면의 채널 비교 표현식을 찾지 못했다').toBeTruthy();

        // 예: 전송 "{{query.channel ?? 'mail'}}" ↔ 화면 "query.channel ?? 'mail'"
        expect(
            sent.replace(/^\{\{|\}\}$/g, '').trim(),
            '두 채널이 어긋나면 서버가 실어 보낸 채널과 화면이 찾는 채널이 달라 빈 값이 된다',
        ).toBe(screenChannel!.trim());
    });

    it('되돌리기 버튼은 서버 집계(has_customized_templates)로 판정한다', () => {
        const raw = fs.readFileSync(NOTIFICATION_TAB, 'utf-8');

        // templates 는 한 채널로 좁혀 실리므로, 배열을 순회해서는 "다른 채널에서 커스터마이즈됨"
        // 을 알 수 없다. 채널을 가리지 않는 판정은 서버 집계가 유일한 출처다.
        expect(
            /def\.templates[\s\S]{0,80}?\.some\(/.test(raw),
            '되돌리기 조건을 templates 배열 순회로 판정하면 다른 채널의 커스터마이즈를 놓친다',
        ).toBe(false);

        expect(
            raw.includes('def.has_customized_templates'),
            '되돌리기 노출 조건이 서버 집계를 읽어야 한다',
        ).toBe(true);
    });
});
