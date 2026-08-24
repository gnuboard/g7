// e2e:allow 구조 단언 + 조건식 실평가 Vitest — 브라우저 흐름은 플러그인(sirsoft-message_bizppurio)
// tests/Playwright/specs/admin/template-lifecycle-matrix.spec.ts 가 코어 면 대표로 담당한다.
/**
 * 코어 알림 템플릿 [편집] 모달 — 확장 슬롯 2종 + hidden_template_editor 게이트 (범용 seam)
 *
 * 채널을 소유한 확장이 그 채널 전용 편집 UI 를 같은 모달에 녹일 수 있도록:
 *  - 본문: `notification_template_form_sections` (수신자 규칙 다음, 코어 제목/본문 앞)
 *  - 푸터: `notification_template_form_footer_actions` ([취소] 와 코어 [저장] 사이)
 *  - 채널 메타 `hidden_template_editor: true` 이면 코어 언어탭/제목/본문/클릭 URL/변수 안내/미리보기/[저장]을 숨긴다
 *    (확장이 본문 편집과 저장을 대신한다). 플래그가 없는 채널(메일·사이트내 알림)은 종전과 동일.
 *
 * 조건은 문자열 동일성이 아니라 실평가로 판정한다 — 게이트 식을 잘못 고쳐도 리터럴 단언은
 * 기대값을 함께 고치면 통과한다.
 *
 * @effects core_modal_hides_editor_for_hidden_template_editor_channel, edit_modal_hosts_alimtalk_and_sms_sections
 */

import { describe, it, expect } from 'vitest';
import modal from '../../layouts/partials/admin_settings/_modal_notification_template_form.json';

type Node = Record<string, any>;
const PREFIX = '';
const STATE_KEY = 'notification_template_form_modal';
const MODAL_ID = 'modal_notification_template_form';
const REFETCH_ID = 'notificationDefinitions';

const findById = (node: unknown, id: string): Node | null => {
    if (Array.isArray(node)) { for (const n of node) { const r = findById(n, id); if (r) return r; } return null; }
    if (node && typeof node === 'object') {
        if ((node as Node).id === id) return node as Node;
        for (const v of Object.values(node as Node)) { const r = findById(v, id); if (r) return r; }
    }
    return null;
};
const findParentArray = (node: unknown, id: string): { arr: Node[]; idx: number } | null => {
    if (Array.isArray(node)) {
        const idx = node.findIndex((n) => n && typeof n === 'object' && n.id === id);
        if (idx >= 0) return { arr: node, idx };
        for (const n of node) { const r = findParentArray(n, id); if (r) return r; }
        return null;
    }
    if (node && typeof node === 'object') {
        for (const v of Object.values(node as Node)) { const r = findParentArray(v, id); if (r) return r; }
    }
    return null;
};
const evalIf = (expr: string, scope: Record<string, unknown>): boolean => {
    const body = String(expr).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
    const names = Object.keys(scope);
    // eslint-disable-next-line no-new-func
    return Boolean(new Function(...names, `return (${body});`)(...names.map((n) => scope[n])));
};
const scope = (channel: string, hidden: boolean | undefined, extra: Record<string, unknown> = {}) => ({
    availableChannels: { data: { channels: [{ id: channel, ...(hidden === undefined ? {} : { hidden_template_editor: hidden }) }] } },
    _global: { [STATE_KEY]: { template: { channel }, definition: { variables: [{ key: 'name' }] }, ...extra } },
});

const EP_PROPS = ['definition', 'template', 'channel', 'modalId', 'stateKey', 'saveEndpoint', 'refetchDataSourceId'];

describe('알림 템플릿 편집 모달 — 확장 슬롯 2종', () => {
    it('본문 슬롯은 수신자 규칙 바로 다음, 언어 탭 앞에 놓인다', () => {
        const loc = findParentArray(modal, `${PREFIX}template_form_sections_ext`);
        expect(loc).toBeTruthy();
        const { arr, idx } = loc!;
        expect(arr[idx].type).toBe('extension_point');
        expect(arr[idx].name).toBe('notification_template_form_sections');
        expect(arr[idx - 1].id).toBe(`${PREFIX}template_recipients_section`);
        expect(arr[idx + 1].id).toBe(`${PREFIX}template_lang_tabs`);
    });

    it('푸터 슬롯은 [취소] 와 코어 [저장] 사이에 놓인다', () => {
        const footer = findById(modal, `${PREFIX}template_modal_footer`)!;
        const right = footer.children[1].children as Node[];
        expect(right.map((n: Node) => n.type ?? n.name)).toEqual(['basic', 'extension_point', 'basic']);
        expect(right[1].name).toBe('notification_template_form_footer_actions');
        expect(JSON.stringify(right[0])).toContain('$t:common.cancel');
        expect(JSON.stringify(right[2])).toContain('$t:common.save');
    });

    it.each([`${PREFIX}template_form_sections_ext`, `${PREFIX}template_form_footer_ext`])('%s 은 확장이 필요한 props 7종을 면 전용 값으로 전달한다', (id) => {
        const ep = findById(modal, id)!;
        expect(Object.keys(ep.props).sort()).toEqual([...EP_PROPS].sort());
        expect(ep.props.modalId).toBe(MODAL_ID);
        expect(ep.props.stateKey).toBe(STATE_KEY);
        expect(ep.props.refetchDataSourceId).toBe(REFETCH_ID);
        expect(ep.props.channel).toBe(`{{_global.${STATE_KEY}?.template?.channel ?? ''}}`);
        expect(ep.props.saveEndpoint).toBe(`{{'/api/admin/notification-templates/' + _global.${STATE_KEY}?.template?.id}}`);
        expect(ep.props.definition).toBe(`{{_global.${STATE_KEY}?.definition}}`);
        expect(ep.props.template).toBe(`{{_global.${STATE_KEY}?.template}}`);
    });
});

describe('알림 템플릿 편집 모달 — hidden_template_editor 게이트 실평가', () => {
    const GATED = ['template_lang_tabs', 'template_subject_input', 'template_body_input', 'template_variables_info'];

    it.each(GATED)('%s: 플래그 채널이면 숨김, 플래그 없음/false 채널이면 노출', (id) => {
        const node = findById(modal, `${PREFIX}${id}`)!;
        expect(node.if, `${id} if`).toBeTruthy();
        expect(evalIf(node.if, scope('alimtalk', true))).toBe(false);
        expect(evalIf(node.if, scope('mail', undefined))).toBe(true);
        expect(evalIf(node.if, scope('sms', false))).toBe(true);
    });

    it('클릭 URL 입력은 기존 조건(database 채널)과 게이트를 AND 로 결합한다', () => {
        const node = findById(modal, `${PREFIX}template_click_url_input`)!;
        expect(evalIf(node.if, scope('database', undefined))).toBe(true);
        expect(evalIf(node.if, scope('mail', undefined))).toBe(false);
        expect(evalIf(node.if, scope('database', true))).toBe(false);
    });

    it('변수 안내는 기존 조건(변수 보유)과 게이트를 AND 로 결합한다', () => {
        const node = findById(modal, `${PREFIX}template_variables_info`)!;
        const noVars = { ...scope('mail', undefined) } as Node;
        noVars._global[STATE_KEY].definition = { variables: [] };
        expect(evalIf(node.if, noVars)).toBe(false);
    });

    it('푸터의 코어 [미리보기]·[저장]은 게이트로 숨고 [취소]는 항상 남는다', () => {
        const footer = findById(modal, `${PREFIX}template_modal_footer`)!;
        const preview = footer.children[0].children[0] as Node;
        const right = footer.children[1].children as Node[];
        const cancel = right[0];
        const save = right[2];
        for (const b of [preview, save]) {
            expect(evalIf(b.if, scope('alimtalk', true))).toBe(false);
            expect(evalIf(b.if, scope('mail', undefined))).toBe(true);
        }
        expect(cancel.if).toBeUndefined();
    });

    it('수신자 규칙·유형/채널 헤더·검증 오류 블록은 게이트와 무관하게 남는다', () => {
        for (const id of ['template_recipients_section', 'template_type_channel', 'template_validation_error']) {
            const node = findById(modal, `${PREFIX}${id}`)!;
            expect(node, id).toBeTruthy();
            expect(String(node.if ?? '')).not.toContain('hidden_template_editor');
        }
    });
});
