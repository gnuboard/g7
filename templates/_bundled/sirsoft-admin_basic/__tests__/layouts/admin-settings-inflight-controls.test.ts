/**
 * @file admin-settings-inflight-controls.test.ts
 * @description 환경설정 화면의 「왕복 중」 계약 — 누른 버튼이 응답이 올 때까지 잠기는가.
 *
 * 이 계층이 필요한 이유는 **브라우저 테스트가 닿는 버튼이 소수**이기 때문이다. E2E 는
 * 실제로 눌러 보는 대신 한두 자리만 표본으로 재고, 나머지 30여 개는 아무도 보지 않는다.
 * 여기서는 반대로 화면 전체를 훑되 "그 자리에 계약이 있는가" 만 구조로 잠근다.
 *
 * 잠그는 것 셋:
 *
 * 1. **왕복이 걸린 버튼은 전부 `props.disabled` 를 갖는다.** 없으면 연타가 그대로
 *    두 번의 호출이 된다 — 번호 재발급처럼 되돌릴 수 없는 조작에서는 그 연타가 곧
 *    사고다(방금 등록한 주소가 그 자리에서 다시 무효가 된다).
 * 2. **잠그는 식과 도는 식이 같다.** `disabled` 는 A 를 보는데 스피너는 B 를 보면,
 *    버튼은 잠겼는데 돌지 않거나 돌면서 눌린다 — 둘 다 "지금 뭘 하는 중인지" 를
 *    운영자에게 거짓으로 알린다.
 * 3. **레이아웃이 소유한 플래그는 되돌릴 자리가 둘 다 있다.** `onSuccess` 에만 두면
 *    실패한 순간 버튼이 영영 잠긴 채 남아 화면을 새로 고치는 것 말고는 길이 없다.
 *
 * 핸들러가 플래그를 소유하는 버튼(`_global.coreUpdateBusy` ·
 * `asset_url_mode_detect_status`)은 3 의 대상이 아니다 — 되돌리는 코드가 레이아웃이
 * 아니라 그 핸들러 안에 있고, 거기는 그 핸들러의 단위 테스트가 잰다.
 *
 * @scenario web-cron-runner
 * @axes caller=panel
 * @effects in_flight_controls_are_blocked_until_the_call_returns
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const LAYOUT_DIR = path.resolve(__dirname, '../../layouts');
const PARTIAL_DIR = path.join(LAYOUT_DIR, 'partials', 'admin_settings');

/**
 * 엔진이 그 자리에서 끝내는 핸들러 — 이것만 걸린 버튼은 왕복이 아니다.
 *
 * `refetchDataSource` 계열이 여기 있는 이유: 그 왕복의 진행 표시는 버튼이 아니라
 * 데이터소스 계층(`blur_until_loaded` · 전환 오버레이)이 소유한다. 목록이 통째로
 * 흐려지는 화면에서 버튼까지 따로 돌면 같은 사실을 두 번 말하는 것이다.
 *
 * 목록에 없는 핸들러는 모두 왕복으로 본다 — 새 등록 핸들러가 생기면 이 테스트가
 * 먼저 빨개져서 "그 버튼에 진행 표시가 필요한가" 를 묻게 된다.
 */
const INSTANT_HANDLERS = new Set([
    'navigate', 'navigateBack', 'openWindow', 'refresh',
    'openModal', 'closeModal', 'toast', 'confirm',
    'setState', 'setError', 'clearError', 'submitForm', 'remount', 'scrollTo',
    'copyToClipboard', 'emitEvent', 'sequence',
    'refetchDataSource', 'updateDataSource', 'appendDataSource',
]);

type Json = Record<string, any>;

/** 환경설정 화면을 이루는 레이아웃 파일 전부 (본체 + partial). */
function settingsLayoutFiles(): string[] {
    const partials = fs
        .readdirSync(PARTIAL_DIR)
        .filter((name) => name.endsWith('.json'))
        .map((name) => path.join(PARTIAL_DIR, name));

    return [path.join(LAYOUT_DIR, 'admin_settings.json'), ...partials];
}

/** 트리를 훑어 조건을 만족하는 노드를 모은다. */
function collect(node: unknown, predicate: (obj: Json) => boolean, out: Json[] = []): Json[] {
    if (Array.isArray(node)) {
        node.forEach((child) => collect(child, predicate, out));

        return out;
    }

    if (node && typeof node === 'object') {
        const obj = node as Json;

        if (predicate(obj)) out.push(obj);
        Object.values(obj).forEach((value) => collect(value, predicate, out));
    }

    return out;
}

/** 버튼의 액션 체인에 걸린 왕복 핸들러 이름들 (sequence 중첩 포함). */
function roundTripHandlers(button: Json): string[] {
    const found = new Set<string>();

    collect(button.actions ?? [], (obj) => typeof obj.handler === 'string').forEach((obj) => {
        if (!INSTANT_HANDLERS.has(obj.handler)) found.add(obj.handler);
    });

    return [...found];
}

/** 첫 children 의 스피너 아이콘 — 없으면 null. */
function spinnerIcon(button: Json): Json | null {
    return (button.children ?? []).find(
        (child: Json) => child?.name === 'Icon' && child?.props?.name === 'spinner',
    ) ?? null;
}

interface Row { file: string; label: string; node: Json; handlers: string[] }

/** 환경설정 전체에서 왕복이 걸린 버튼을 모은다. */
const roundTripButtons: Row[] = settingsLayoutFiles().flatMap((file) => {
    const tree = JSON.parse(fs.readFileSync(file, 'utf-8'));
    const buttons = collect(tree, (obj) => obj.name === 'Button' || obj.name === 'IconButton');

    return buttons
        .map((node) => ({ file: path.basename(file), node, handlers: roundTripHandlers(node) }))
        .filter((row) => row.handlers.length > 0)
        .map((row) => ({
            ...row,
            label: `${path.basename(file)} :: ${row.node.id ?? row.node.props?.disabled ?? '(id 없음)'}`,
        }));
});

describe('환경설정 — 왕복 중 조작 차단', () => {
    // @scenario caller=panel
    // @effects in_flight_controls_are_blocked_until_the_call_returns
    it('모집단이 비어 있지 않다 (수집 자체가 깨지면 아래가 전부 공허해진다)', () => {
        expect(roundTripButtons.length).toBeGreaterThan(30);
    });

    // @scenario caller=panel
    // @effects in_flight_controls_are_blocked_until_the_call_returns
    it('왕복이 걸린 버튼은 전부 눌린 뒤 잠긴다', () => {
        const unlocked = roundTripButtons.filter(
            (row) => typeof row.node.props?.disabled !== 'string',
        );

        expect(
            unlocked.map((row) => `${row.label} [${row.handlers.join(',')}]`),
            '이 버튼들은 응답을 기다리는 동안 계속 눌린다',
        ).toEqual([]);
    });

    // @scenario caller=panel
    // @effects in_flight_controls_are_blocked_until_the_call_returns
    it('스피너를 단 버튼은 잠그는 식과 도는 식이 같다', () => {
        const mismatched = roundTripButtons
            .filter((row) => spinnerIcon(row.node) !== null)
            .filter((row) => {
                const disabled = String(row.node.props?.disabled ?? '');
                const spinning = String(spinnerIcon(row.node)?.if ?? '');

                // `disabled` 는 읽기 전용 게이트 같은 다른 조건을 함께 실을 수 있다.
                // 요구는 "스피너가 보는 식이 잠그는 식 **안에** 있다" 이다.
                return !disabled.includes(inner(spinning));
            });

        expect(
            mismatched.map((row) => row.label),
            '잠그는 조건과 도는 조건이 어긋난 버튼',
        ).toEqual([]);
    });

    /**
     * 아직 스피너가 없는 왕복 버튼 — 늘리지 않는다.
     *
     * 이 화면들(메일 템플릿 · SEO · 고급 · 본인인증/알림 템플릿 폼)은 이번 작업이
     * 손대지 않은 자리이고, 전부 `disabled` 로 연타는 이미 막고 있다. 남은 것은
     * "왜 지금 안 눌리는지" 를 알리는 표시뿐이다. 0 으로 만들 때까지 이 수만
     * 내려가고 올라가지 않는지 본다 — 새 버튼이 표시 없이 들어오는 것을 막는다.
     */
    // @scenario caller=panel
    // @effects in_flight_controls_are_blocked_until_the_call_returns
    it('스피너 없는 왕복 버튼이 늘지 않는다', () => {
        const BASELINE = 8;
        const withoutSpinner = roundTripButtons.filter((row) => spinnerIcon(row.node) === null);

        expect(
            withoutSpinner.length,
            `스피너 없는 왕복 버튼: ${withoutSpinner.map((r) => r.label).join(' / ')}`,
        ).toBeLessThanOrEqual(BASELINE);
    });
});

describe('환경설정 — 레이아웃이 소유한 진행 플래그', () => {
    /** 레이아웃이 직접 켜고 끄는 플래그 (핸들러 소유분은 제외 — 되돌림이 핸들러 안에 있다). */
    const LAYOUT_OWNED: Array<{ file: string; flag: string }> = [
        { file: '_tab_system.json', flag: 'cronUrlRevealing' },
        { file: '_tab_system.json', flag: 'cronUrlCopying' },
        { file: '_modal_cron_token_rotate.json', flag: 'cronTokenRotating' },
        { file: '_tab_update.json', flag: 'loadingChangelog' },
        { file: '_tab_update.json', flag: 'coreUpdateDismissing' },
        { file: '_modal_core_update_result.json', flag: 'loadingChangelog' },
    ];

    // @scenario caller=panel
    // @effects in_flight_controls_are_blocked_until_the_call_returns
    it.each(LAYOUT_OWNED)('$file 의 $flag 는 켠 자리와 끄는 자리 둘을 모두 갖는다', ({ file, flag }) => {
        const tree = JSON.parse(fs.readFileSync(path.join(PARTIAL_DIR, file), 'utf-8'));
        const button = collect(
            tree,
            (obj) =>
                (obj.name === 'Button' || obj.name === 'IconButton') &&
                String(obj.props?.disabled ?? '').includes(flag),
        )[0];

        expect(button, `${file} 에 ${flag} 로 잠기는 버튼이 없습니다.`).toBeDefined();

        const setsTrue = collect(
            button.actions ?? [],
            (obj) => obj.handler === 'setState' && obj.params?.[flag] === true,
        );

        expect(setsTrue.length, `${flag} 를 켜는 setState 가 없습니다.`).toBeGreaterThan(0);

        // 되돌림은 성공·실패 **양쪽**에 있어야 한다. 한쪽만 있으면 그 경로에서
        // 버튼이 잠긴 채 남는다.
        const call = collect(button.actions ?? [], (obj) => Array.isArray(obj.onSuccess))[0];

        expect(call, `${flag} 를 쓰는 왕복 액션을 찾지 못했습니다.`).toBeDefined();

        for (const branch of ['onSuccess', 'onError'] as const) {
            const clears = collect(
                call[branch] ?? [],
                (obj) => obj.handler === 'setState' && obj.params?.[flag] === false,
            );

            expect(clears.length, `${flag} 가 ${branch} 에서 꺼지지 않습니다.`).toBeGreaterThan(0);
        }
    });

    // @scenario caller=panel
    // @effects in_flight_controls_are_blocked_until_the_call_returns
    it('본체 레이아웃의 지역 플래그는 initLocal 에 거짓으로 선언된다', () => {
        const settings = JSON.parse(
            fs.readFileSync(path.join(LAYOUT_DIR, 'admin_settings.json'), 'utf-8'),
        );

        // 모달(포털)은 자기 _local 을 따로 갖는다 — 본체의 initLocal 대상이 아니다.
        for (const flag of ['cronUrlRevealing', 'cronUrlCopying', 'coreUpdateDismissing', 'loadingChangelog']) {
            expect(settings.initLocal?.[flag], `initLocal 에 ${flag} 선언이 없습니다.`).toBe(false);
        }
    });
});

/**
 * 표현식 껍데기(`{{ }}`)를 벗긴다 — `disabled` 와 스피너 `if` 를 같은 층에서 비교한다.
 *
 * @param expression 레이아웃 표현식
 */
function inner(expression: string): string {
    return expression.replace(/^\{\{/, '').replace(/\}\}$/, '').trim();
}
