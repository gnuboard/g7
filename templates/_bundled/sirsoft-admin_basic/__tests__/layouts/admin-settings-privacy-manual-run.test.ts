/**
 * 환경설정 개인정보 탭 — 관리자 직권 실행(「지금 실행」) 레이아웃 계약 테스트 (이슈 #636 후속)
 *
 * @description
 * 탈퇴 잔여정보 파기와 휴면 네 단계를 스케줄러와 무관하게 큐에 접수하는 버튼·확인 창·상태 줄의 계약을 고정한다.
 * 버튼은 상태 조회가 그 대상을 돌려주면 늘 그리고(2026-10-04 결정 — 보이되 비활성), 실행 권한(`can_run`)이 없거나
 * 저장 안 된 변경이 있거나 같은 대상이 진행 중이면 막는다.
 * 확인 창은 건수를 다시 세고, 휴면 전환·탈퇴의 「대기기간 생략」을 바꾸면 고정값 갈래로 다시 센다.
 * 상태 소스는 진행 중이면 폴링하고, 끝나면 응답값(`finished_since`)만으로 대상 건수를 다시 받는다.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { actionsOf, callbacksOf, findById, flattenNodes } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import privacyTab from '../../layouts/partials/admin_settings/_tab_privacy.json';
import settingsLayout from '../../layouts/admin_settings.json';
import runModal from '../../layouts/partials/admin_settings/_modal_privacy_run.json';

/**
 * template.json 을 가진 확장 루트를 위로 훑어 찾는다.
 *
 * @returns 확장 루트 절대경로
 */
function templateRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 10; depth++) {
        if (fs.existsSync(path.join(current, 'template.json'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('template.json 을 가진 루트를 찾지 못했습니다.');
}

/**
 * 점 경로로 중첩 객체 값을 읽는다.
 *
 * @param source 원본 객체
 * @param dotted 점으로 이은 경로
 * @returns 값 또는 undefined
 */
function readPath(source: any, dotted: string): any {
    return dotted.split('.').reduce((acc, key) => (acc == null ? undefined : acc[key]), source);
}

/**
 * 로케일별 관리자 다국어 묶음을 읽는다.
 *
 * @param locale 로케일
 * @returns 다국어 JSON
 */
function adminLang(locale: string): any {
    return JSON.parse(fs.readFileSync(path.join(templateRoot(), `lang/partial/${locale}/admin.json`), 'utf-8'));
}

/**
 * 버튼의 클릭 액션 시퀀스를 돌려준다.
 *
 * @param button 버튼 노드
 * @returns 시퀀스 안의 액션 목록
 */
function clickSteps(button: any): any[] {
    const click = actionsOf(button).find((a: any) => a.type === 'click');

    return actionsOf(click);
}

const runButtons = [findById(privacyTab, 'run_withdrawn'), findById(privacyTab, 'run_dormant_stage_{{stage}}')];

describe('개인정보 조치 직권 실행 — 버튼 (#636 후속)', () => {
    /**
     * @scenario target_kind=retention_handler, toggle=off, actor=read_only
     *
     * @effects run_button_is_gated_by_run_permission_and_unsaved_changes
     */
    it('실행 버튼은 대상이 있으면 그리되, 실행 권한이 없거나 저장 전 변경·진행 중 작업이 있으면 막는다', () => {
        expect(runButtons.every(Boolean)).toBe(true);

        for (const button of runButtons as any[]) {
            // 설정 보기만 가진 관리자도 버튼을 본다(보안 탭 「지금 실행」과 같은 방식) — 그리기 조건은 대상 존재뿐이고,
            // 실행 권한은 잠금 조건에서 부정으로 본다.
            expect(button.if, button.id).toMatch(/^\{\{!!privacyManualRuns\?\.data\?\.targets\?\.\[[^\]]+\]\}\}$/);
            expect(button.if, button.id).not.toContain('can_run');
            expect(button.props.disabled, button.id).toMatch(/^\{\{!privacyManualRuns\?\.data\?\.targets\?\.\[[^\]]+\]\?\.can_run \|\| /);
            expect(button.props.disabled, button.id).toContain('_local.hasChanges');
            expect(button.props.disabled, button.id).toContain('?.run?.is_active');
        }

        // 휴면 네 단계는 반복 하나가 그린다 — 단계가 빠지면 이 단언이 먼저 붉어진다.
        const stageLoop = flattenNodes(findById(privacyTab, 'dormant_target_list')).find((n: any) => n.iteration?.item_var === 'stage');
        expect(stageLoop?.iteration?.source).toBe("{{['pre_notice', 'convert', 'withdraw_notice', 'withdraw']}}");
    });

    /**
     * @scenario target_kind=dormant_stage, toggle=off, actor=runner
     *
     * @effects target_row_keeps_count_view_run_and_result_together
     */
    it('대상마다 한 행에 건수·대상 보기·지금 실행·결과 줄이 함께 있고, 실행은 채운 강조색을 쓰지 않는다', () => {
        // 2026-09-26 결정 — 실행 버튼이 자기 대상과 떨어져 있으면 어느 버튼이 무엇을 처리하는지 눈으로 짝을 맞춰야 한다.
        const rows = [
            { row: 'withdrawn_target_row', view: 'view_withdrawn_targets', run: 'run_withdrawn', status: 'withdrawn_manual_run_status' },
            { row: 'dormant_target_row_{{stage}}', view: 'view_{{stage}}_targets', run: 'run_dormant_stage_{{stage}}', status: 'dormant_manual_run_status_{{stage}}' },
        ];

        for (const { row, view, run, status } of rows) {
            const rowNode = findById(privacyTab, row) as any;
            const ids = flattenNodes(rowNode).map((n: any) => n.id);

            expect(ids, row).toEqual(expect.arrayContaining([view, run, status]));
            expect(flattenNodes(rowNode).some((n: any) => String(n.props?.className ?? '').includes('badge')), row).toBe(true);
        }

        // 휴면 단계 반복은 행 자체다 — 단계마다 버튼과 결과 줄이 자기 행 안에 있다.
        expect((findById(privacyTab, 'dormant_target_row_{{stage}}') as any)?.iteration?.item_var).toBe('stage');

        for (const button of runButtons as any[]) {
            expect(button.props.className, button.id).not.toMatch(/btn-(warning|primary|danger)\b/);
        }
    });

    /**
     * @scenario target_kind=dormant_stage, toggle=off, actor=runner
     *
     * @effects confirm_modal_recounts_before_queueing
     */
    it('버튼은 대상 상태를 초기화하고 → 확인 창을 연 뒤 → 건수를 다시 센다(창은 스스로 조회하지 않는다)', () => {
        for (const button of runButtons as any[]) {
            const steps = clickSteps(button);

            expect(steps.map((s: any) => s.handler), button.id).toEqual(['setState', 'openModal', 'apiCall']);
            expect(steps[0].params.privacyRun, button.id).toContain('preview: null');
            expect(steps[0].params.privacyRun, button.id).toContain('skipWaiting: false');
            expect(steps[1].target).toBe('privacy_run_modal');
            expect(steps[2].params.method).toBe('GET');
            expect(steps[2].target, button.id).toMatch(/^\/api\/admin\/privacy\/manual-runs\/.+\/preview$/);
        }

        // 생략 지원 여부는 서버 응답을 따른다 — 전환·탈퇴 단계만 체크박스가 보인다.
        expect(clickSteps(findById(privacyTab, 'run_dormant_stage_{{stage}}'))[0].params.privacyRun).toContain('supports_skip_waiting');
        expect(clickSteps(findById(privacyTab, 'run_withdrawn'))[0].params.privacyRun).toContain('supportsSkip: false');
    });

    /**
     * 더블클릭: 실행 버튼 `disabled` 의 submitting 은 다시 그려진 뒤에야 걸려 두 번째 클릭이 한 번 더 접수될 수 있다
     * (#736 S2 P10 — 만료·개인정보 두 확인 창 모두). 버튼이 첫 클릭 즉시 스스로 잠근다.
     *
     * @scenario target_kind=dormant_stage, toggle=off, actor=runner
     *
     * @effects confirm_modal_execute_locks_repeat_click
     */
    it('확인 창 실행 버튼은 연타 잠금을 켜고, 처리 중에는 disabled 로 넘긴다', () => {
        const execute = findById(runModal, 'privacy_run_execute') as any;
        expect(execute?.props?.lockRepeatClick).toBe(true);
        expect(String(execute?.props?.disabled)).toContain('_global.privacyRun?.submitting');
    });

    /**
     * @scenario target_kind=retention_handler, toggle=on, actor=runner
     *
     * @effects card_shows_active_status_and_last_result
     */
    it('카드는 처리 대기·처리 중, 마지막 완료·실패를 구분해 그린다', () => {
        for (const id of ['withdrawn_manual_run_status', 'dormant_manual_run_status_{{stage}}']) {
            const status = findById(privacyTab, id) as any;
            const spans = (status?.children ?? []).map((c: any) => c.if);

            expect(status?.if, id).toContain('?.run');
            expect(spans.some((s: string) => s.includes('is_active')), id).toBe(true);
            expect(spans.some((s: string) => s.includes("status === 'completed'")), id).toBe(true);
            expect(spans.some((s: string) => s.includes("status === 'failed'")), id).toBe(true);
        }
    });
});

describe('[case:state-closure-9] 개인정보 조치 직권 실행 — 상태 소스 폴링 (#636 후속)', () => {
    const dataSource = (settingsLayout as any).data_sources.find((d: any) => d.id === 'privacyManualRuns');

    /**
     * @scenario target_kind=retention_handler, toggle=on, actor=runner
     *
     * @effects card_polls_until_finished_and_refreshes_counts
     */
    it('[case:state-closure-9] 탭의 다섯 대상을 조회하고, 진행 중이면 폴링·끝나면 멈추고 응답값으로만 건수를 다시 받는다', () => {
        expect(dataSource).toBeTruthy();
        expect(dataSource.endpoint).toBe('/api/admin/privacy/manual-runs');
        expect(dataSource.params.targets.split(',')).toEqual([
            'retention:user-withdrawn',
            'dormant:pre_notice',
            'dormant:convert',
            'dormant:withdraw_notice',
            'dormant:withdraw',
        ]);
        expect(dataSource.params.since).toContain('_global.privacyRunWatchSince');

        const handlers = callbacksOf(dataSource, 'onSuccess');
        const start = handlers.find((h: any) => h.handler === 'startInterval');
        const stop = handlers.find((h: any) => h.handler === 'stopInterval');
        const refetch = handlers.find((h: any) => h.handler === 'refetchDataSource');

        expect(start.if).toContain('has_active === true');
        expect(start.params.actions[0].params.dataSourceId).toBe('privacyManualRuns');
        expect(stop.if).toContain('has_active !== true');
        expect(stop.params.id).toBe(start.params.id);
        expect(refetch.params.dataSourceId).toBe('privacyPreview');
        expect(refetch.if).toContain('finished_since === true');

        // 회귀: 성공 핸들러는 직전에 넣은 전역 값을 낡은 값으로 읽는다(브라우저 실측) — 판정은 응답값만 쓴다.
        for (const handler of handlers) {
            expect(String(handler.if ?? ''), handler.handler).not.toContain('_global');
        }
    });
});

describe('[case:state-closure-10] 개인정보 조치 직권 실행 — 확인 창 (#636 후속)', () => {
    /**
     * @scenario target_kind=dormant_stage, toggle=on, actor=runner
     *
     * @effects confirm_modal_recounts_before_queueing
     */
    it('[case:state-closure-10] 「대기기간 생략」은 체크 여부를 분기에서 한 번만 읽고, 갈래마다 고정값으로 다시 센다', () => {
        const checkbox = flattenNodes(runModal).find((n: any) => n.name === 'Checkbox' && n.props?.id === 'privacy_run_skip_waiting_checkbox') as any;
        const change = actionsOf(checkbox).find((a: any) => a.type === 'change');
        const branches = callbacksOf(change, 'conditions');

        expect(findById(runModal, 'privacy_run_skip_waiting')?.if).toContain('supportsSkip');
        expect(branches).toHaveLength(2);
        expect(branches[0].if).toBe('{{$event.target.checked}}');

        const queries = branches.map((b: any) => b.then.find((a: any) => a.handler === 'apiCall'));
        expect(queries[0].params.query.skip_waiting).toBe(1);
        expect(queries[1].params.query.skip_waiting).toBe(0);

        // 회귀: 조회 주소 안의 `$event.target.checked` 는 체크 전 값으로 나갔다(브라우저 실측).
        for (const query of queries) {
            expect(query.target).not.toContain('$event');
        }
    });

    /**
     * @scenario target_kind=retention_handler, toggle=off, actor=runner
     *
     * @effects manual_run_is_queued_and_processed_by_the_job, card_polls_until_finished_and_refreshes_counts
     */
    it('실행은 코어 API 로 접수하고, 성공하면 창을 닫고 상태·건수를 다시 받는다(실패해도 상태는 다시 받는다)', () => {
        const execute = findById(runModal, 'privacy_run_execute') as any;
        const steps = clickSteps(execute);
        const call = steps.find((s: any) => s.handler === 'apiCall');

        expect(execute.props.disabled).toContain('!_global.privacyRun?.preview');
        expect(call.params.method).toBe('POST');
        expect(call.target).toBe('/api/admin/privacy/manual-runs/{{_global.privacyRun?.key}}');
        expect(call.params.body.skip_waiting).toContain('_global.privacyRun?.skipWaiting');

        const success = callbacksOf(call, 'onSuccess');
        expect(success.map((a: any) => a.handler)).toEqual(['setState', 'closeModal', 'toast', 'refetchDataSource', 'refetchDataSource']);
        expect(success.filter((a: any) => a.handler === 'refetchDataSource').map((a: any) => a.params.dataSourceId)).toEqual(['privacyManualRuns', 'privacyPreview']);

        const failure = callbacksOf(call, 'onError');
        expect(failure.some((a: any) => a.handler === 'refetchDataSource' && a.params.dataSourceId === 'privacyManualRuns')).toBe(true);
        expect(JSON.stringify(settingsLayout)).toContain('_modal_privacy_run.json');
    });

    /**
     * @scenario target_kind=dormant_stage, toggle=off, actor=runner
     *
     * @effects confirm_modal_recounts_before_queueing
     */
    it('창·탭이 쓰는 직권 실행 문구가 ko·en 모두 있다', () => {
        const source = JSON.stringify(runModal) + JSON.stringify(privacyTab);
        const keys = new Set<string>();
        for (const match of source.matchAll(/admin\.settings\.privacy\.manual_run\.[a-z_]+(?:\.[a-z_]+)?/g)) {
            keys.add(match[0].replace(/\.$/, ''));
        }
        // 동적 키(`modes.' + mode`)는 알려진 방식으로 펼친다.
        keys.delete('admin.settings.privacy.manual_run.modes');
        ['anonymize', 'delete', 'erase_ip'].forEach((mode) => keys.add(`admin.settings.privacy.manual_run.modes.${mode}`));

        expect(keys.size).toBeGreaterThan(15);
        for (const locale of ['ko', 'en']) {
            const lang = adminLang(locale);
            for (const key of keys) {
                expect(typeof readPath(lang, key.replace(/^admin\./, '')), `${locale}:${key}`).toBe('string');
            }
        }
    });
});
