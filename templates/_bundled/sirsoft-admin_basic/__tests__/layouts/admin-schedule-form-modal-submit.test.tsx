/**
 * @file admin-schedule-form-modal-submit.test.tsx
 * @description 스케줄 생성/수정 모달 — 저장 버튼의 폼 연결 회귀
 *
 * 배경:
 *  footer 저장 버튼은 Form 밖에 있어 `props.form="{form id}"` (HTML form 속성)으로
 *  연결된다. 참조 대상 Form 에 `props.id` 가 없으면 브라우저는 버튼을 어떤 폼에도
 *  연결하지 않아 저장 클릭이 아무 일도 하지 않는다 — 예외도 콘솔 오류도 없이
 *  스케줄 등록/수정 저장만 조용히 불능이 된다 (menu_form 패턴 참조: 버튼
 *  `form: "menu_form"` ↔ Form `id: "menu_form"`).
 */

import { describe, it, expect } from 'vitest';
import { actionsOf, callbacksOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const formModal = require('../../layouts/partials/admin_schedule_list/_modal_form.json');

function collectNodes(node: any, predicate: (n: any) => boolean): any[] {
    const result: any[] = [];
    const visit = (n: any) => {
        if (!n || typeof n !== 'object') return;
        if (Array.isArray(n)) {
            n.forEach(visit);
            return;
        }
        if (predicate(n)) result.push(n);
        if (n.children) visit(n.children);
        if (n.props?.footer) visit(n.props.footer);
    };
    visit(node);
    return result;
}

describe('스케줄 폼 모달 — 저장 버튼 폼 연결', () => {
    // @scenario case=form_modal
    // @effects schedule_form_modal_binds_registry_fields
    it('submit 버튼의 form 참조마다 같은 id 의 Form 이 존재한다', () => {
        const submitButtons = collectNodes(
            formModal,
            (n) => n.name === 'Button' && n.props?.type === 'submit' && typeof n.props?.form === 'string'
        );
        expect(submitButtons.length, 'footer 저장 버튼(form 참조)이 있어야 한다').toBeGreaterThan(0);

        const formIds = collectNodes(formModal, (n) => n.name === 'Form').map((n) => n.props?.id);

        for (const button of submitButtons) {
            expect(
                formIds,
                `저장 버튼이 참조하는 form="${button.props.form}" 에 해당하는 Form id 가 없다 — 클릭이 무반응이 된다`
            ).toContain(button.props.form);
        }
    });
});

describe('스케줄 폼 모달 — 폼 값 바인딩', () => {
    function submitBody(): Record<string, string> {
        const forms = collectNodes(formModal, (n) => n.name === 'Form' && Array.isArray(n.actions));
        const submit = actionsOf(forms[0]).find((a: any) => a.type === 'submit');
        const api = callbacksOf(submit, 'actions').find((a: any) => a.handler === 'apiCall');
        return api.params.body;
    }

    // @scenario case=form_modal
    // @effects schedule_form_modal_binds_registry_fields
    it('커스텀 Select 는 value 로 제어된다 (defaultValue 는 무시되어 빈 표시 + 폼 미반영)', () => {
        // type 은 레지스트리(components.json)가 정한다 — admin_basic 의 Select 는 basic 으로
        // 분류되며 굽기가 그 분류를 산출한다. 노드 type 을 함께 단언하면 레지스트리 재분류마다
        // 깨지므로 이름으로만 고른다 (D47).
        const selects = collectNodes(formModal, (n) => n.name === 'Select');
        // 7.2.0: 유형·주기 + 이력 기록 방식·실행 방식·큐 연결·큐
        expect(selects.map((s: any) => s.props.name).sort()).toEqual(
            ['dispatch_mode', 'frequency', 'history_mode', 'queue_connection', 'queue_name', 'type'].sort()
        );

        for (const select of selects) {
            expect(
                select.props.value,
                `Select(${select.props.name}) 는 value 로 제어해야 한다 — 커스텀 Select 는 defaultValue 를 렌더하지 않는다`
            ).toBeTruthy();
            expect(select.props.defaultValue, `Select(${select.props.name}) 의 defaultValue 는 죽은 prop 이다`).toBeUndefined();

            const change = (select.actions ?? []).find((a: any) => a.type === 'change');
            expect(change, `Select(${select.props.name}) 는 change 액션으로 상태에 기록해야 한다`).toBeTruthy();
        }
    });

    // @scenario case=form_modal
    // @effects schedule_form_modal_binds_registry_fields
    it('Toggle 은 change 액션으로 boolean 을 상태에 기록한다 (자동바인딩은 "on" 문자열을 보내 422)', () => {
        const toggles = collectNodes(formModal, (n) => n.type === 'composite' && n.name === 'Toggle');
        // 7.2.0: 중복 방지(켜기/끄기) · 한 서버 · 실패 알림 · 점검 모드 · 사용 여부
        expect(toggles.map((t: any) => t.props.name).sort()).toEqual(
            ['is_active', 'notify_on_failure', 'on_one_server', 'overlapEnabled', 'run_in_maintenance'].sort()
        );

        for (const toggle of toggles) {
            const change = (toggle.actions ?? []).find((a: any) => a.type === 'change');
            expect(change, `Toggle(${toggle.props.name}) 에 change 액션이 없다`).toBeTruthy();
            const written = JSON.stringify(change.params);
            expect(
                written,
                `Toggle(${toggle.props.name}) 은 $event.target.checked (boolean) 를 기록해야 한다`
            ).toContain('$event.target.checked');
        }
    });

    // @scenario case=form_modal
    // @effects schedule_form_modal_binds_registry_fields
    it('submit body 의 Select/Toggle 값은 form.* 이 아니라 상태에서 읽는다', () => {
        const body = submitBody();

        for (const field of [
            'type', 'frequency', 'without_overlapping', 'run_in_maintenance', 'is_active',
            'label', 'description', 'on_one_server', 'dispatch_mode', 'queue_connection', 'queue_name',
            'history_mode', 'notify_on_failure',
        ]) {
            expect(
                body[field],
                `body.${field} 가 form.* 를 읽는다 — 커스텀 Select/Toggle 값은 FormData 에 실리지 않거나 "on" 으로 실린다`
            ).not.toBe(`{{form.${field}}}`);
        }
    });

    // @scenario case=form_modal
    // @effects schedule_form_modal_binds_registry_fields
    it('submit body 의 expression 은 비-custom 주기에서도 유효한 cron 을 보낸다', () => {
        const body = submitBody();
        // custom 이 아니면 expression 입력이 렌더되지 않아 form.expression 이 비어 서버 422 가 된다.
        expect(body.expression).not.toBe('{{form.expression}}');
        expect(body.expression).toContain("'custom'");
    });
});

describe('스케줄 폼 모달 — 7.2.0 DB 등록제 필드', () => {
    function submitBody(): Record<string, string> {
        const forms = collectNodes(formModal, (n) => n.name === 'Form' && Array.isArray(n.actions));
        const submit = actionsOf(forms[0]).find((a: any) => a.type === 'submit');
        const api = callbacksOf(submit, 'actions').find((a: any) => a.handler === 'apiCall');
        return api.params.body;
    }

    // @scenario case=form_modal
    // @effects schedule_form_modal_binds_registry_fields
    it('중복 실행 방지는 끄면 0분, 켜면 입력한 분(기본 1440)을 보낸다 — 옛 boolean 을 보내지 않는다', () => {
        const body = submitBody();
        expect(body.without_overlapping).toContain('_global.scheduleForm?.overlapEnabled');
        expect(body.without_overlapping).toContain(': 0');
        expect(body.without_overlapping).toContain('1440');
        expect(body.without_overlapping).not.toContain('?? true');
    });

    // @scenario case=form_modal
    // @effects schedule_form_modal_binds_registry_fields
    it('30초 주기는 cron 으로 나타낼 수 없어 식을 매분으로 채워 보낸다', () => {
        const body = submitBody();
        expect(body.expression).toContain('everyThirtySeconds');
    });

    // @scenario case=form_modal
    // @effects schedule_form_modal_binds_registry_fields
    it('큐 연결·큐는 「큐로 보내기」일 때만 싣는다', () => {
        const body = submitBody();
        expect(body.queue_connection).toContain("_global.scheduleForm?.dispatch_mode === 'queue'");
        expect(body.queue_name).toContain("_global.scheduleForm?.dispatch_mode === 'queue'");
    });

    // @scenario case=form_modal
    // @effects schedule_form_modal_binds_registry_fields
    it('명칭·설명은 다국어 입력이고 값은 전역 폼 상태로 기록된다', () => {
        const inputs = collectNodes(formModal, (n) => n.name === 'MultilingualInput');
        expect(inputs.map((i: any) => i.props.name).sort()).toEqual(['description', 'label']);
        for (const input of inputs) {
            const change = (input.actions ?? []).find((a: any) => a.type === 'change');
            expect(JSON.stringify(change?.params), `${input.props.name} change`).toContain('$event.target.value');
        }
    });

    // @scenario case=form_modal
    // @effects schedule_form_modal_binds_registry_fields
    it('설명은 여러 줄 입력이다 — MultilingualInput 에 없는 multiline 대신 inputType="textarea"', () => {
        // 7.2.0 전 설명 칸은 textarea 였다. MultilingualInput 은 `multiline` prop 을 모르고 inputType 으로만
        // textarea 를 그리므로, multiline 을 주면 한 줄 입력으로 떨어지고 rows 도 무시된다.
        const description = collectNodes(formModal, (n) => n.name === 'MultilingualInput' && n.props?.name === 'description');
        expect(description.length).toBe(1);
        expect(description[0].props.inputType).toBe('textarea');
        expect(description[0].props).not.toHaveProperty('multiline');
    });

    // @scenario case=form_modal
    // @effects schedule_form_modal_binds_registry_fields
    it('소유 행의 잠금 필드(이름·유형·명령)는 locked_fields 로 비활성화된다', () => {
        // 명령은 여러 줄 칸(Textarea)이다
        for (const name of ['name', 'type', 'command']) {
            const nodes = collectNodes(formModal, (n) => n.props?.name === name && ['Input', 'Select', 'Textarea'].includes(n.name));
            expect(nodes.length, name).toBe(1);
            expect(String(nodes[0].props.disabled), name).toContain(`locked_fields ?? []).includes('${name}')`);
        }
    });
});
