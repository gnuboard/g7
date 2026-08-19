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
    };
    visit(node);
    return result;
}

describe('스케줄 폼 모달 — 저장 버튼 폼 연결', () => {
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
        const submit = forms[0].actions.find((a: any) => a.type === 'submit');
        const api = submit.actions.find((a: any) => a.handler === 'apiCall');
        return api.params.body;
    }

    it('커스텀 Select 는 value 로 제어된다 (defaultValue 는 무시되어 빈 표시 + 폼 미반영)', () => {
        const selects = collectNodes(formModal, (n) => n.type === 'composite' && n.name === 'Select');
        expect(selects.length).toBe(2);

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

    it('Toggle 은 change 액션으로 boolean 을 상태에 기록한다 (자동바인딩은 "on" 문자열을 보내 422)', () => {
        const toggles = collectNodes(formModal, (n) => n.type === 'composite' && n.name === 'Toggle');
        expect(toggles.length).toBe(3);

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

    it('submit body 의 Select/Toggle 값은 form.* 이 아니라 상태에서 읽는다', () => {
        const body = submitBody();

        for (const field of ['type', 'frequency', 'without_overlapping', 'run_in_maintenance', 'is_active']) {
            expect(
                body[field],
                `body.${field} 가 form.* 를 읽는다 — 커스텀 Select/Toggle 값은 FormData 에 실리지 않거나 "on" 으로 실린다`
            ).not.toBe(`{{form.${field}}}`);
        }
    });

    it('submit body 의 expression 은 비-custom 주기에서도 유효한 cron 을 보낸다', () => {
        const body = submitBody();
        // custom 이 아니면 expression 입력이 렌더되지 않아 form.expression 이 비어 서버 422 가 된다.
        expect(body.expression).not.toBe('{{form.expression}}');
        expect(body.expression).toContain("'custom'");
    });
});
