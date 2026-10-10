/**
 * @file commonInfoDefaultToggleSave.test.ts
 * @description 공통정보 편집에서 「기본 설정」 을 켜고 확인한 뒤 저장하면 기본값이 실제로 저장되는지
 *
 * 공통정보 편집 패널에서 「기본 설정」 스위치를 켜고 확인 창의 [확인] 을 누른 뒤 저장하면, 화면의 스위치는
 * 켜져 보이지만 저장 요청에는 `is_default: false` 가 나가 기본값이 바뀌지 않았다(이커머스 전수 실측 S5,
 * 2026-10-07 — 같은 저장에서 「활성화」 끔은 반영). 스위치를 켤 때 확인 창만 열고 폼 값 `form.is_default` 를
 * 켜지 않았고, [확인] 도 창만 닫았다. [취소] 는 창 안의 `local` 을 고쳐 패널 폼을 되돌리지 못했다.
 *
 * 켤 때 폼 값을 바로 켜고, [취소] 는 창을 닫기 **전에** 부모 문맥(`$parent._local`) 의 폼 값을 끈다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

const PARTIALS = resolve(__dirname, '../../../layouts/admin/partials/admin_ecommerce_product_common_info_index');

type Node = Record<string, any>;

const panel: Node = JSON.parse(readFileSync(join(PARTIALS, '_panel_form.json'), 'utf8'));
const modal: Node = JSON.parse(readFileSync(join(PARTIALS, '_modal_default_confirm.json'), 'utf8'));

/**
 * 노드 트리에서 조건에 맞는 노드를 모두 찾습니다.
 *
 * @param node 시작 노드
 * @param pred 조건
 * @param acc 누적
 * @return 노드 목록
 */
function findAll(node: any, pred: (n: Node) => boolean, acc: Node[] = []): Node[] {
    if (Array.isArray(node)) {
        node.forEach((n) => findAll(n, pred, acc));

        return acc;
    }
    if (node && typeof node === 'object') {
        if (pred(node)) acc.push(node);
        Object.values(node).forEach((v) => findAll(v, pred, acc));
    }

    return acc;
}

/**
 * 액션 트리를 실행 순서대로 펼칩니다.
 *
 * @param node 액션 노드
 * @return handler·params 목록
 */
function flatActions(node: any): Node[] {
    return findAll(node, (n) => typeof n.handler === 'string');
}

const defaultToggle = findAll(panel, (n) => n.name === 'Toggle' && String(n.props?.checked ?? '').includes('form.is_default'))[0];

describe('공통정보 기본 설정 스위치 — 켜고 확인하면 저장된다', () => {
    /**
     * @scenario case=c01
     * @effects default_toggle_saved_and_cancel_reverts
     */
    it('기본 설정 스위치를 찾았다', () => {
        expect(defaultToggle).toBeDefined();
    });

    /**
     * @scenario case=c02
     * @effects default_toggle_saved_and_cancel_reverts
     */
    it('켤 때(확인 창을 여는 갈래) 폼 값 form.is_default 를 true 로 넣는다', () => {
        const actions = flatActions(defaultToggle.actions);
        const openIdx = actions.findIndex((a) => a.handler === 'openModal' && a.target === 'default_confirm_modal');
        expect(openIdx).toBeGreaterThan(-1);
        const setsTrue = actions.slice(0, openIdx + 1).some((a) => a.handler === 'setState' && a.params?.target === 'local' && (a.params?.['form.is_default'] === true || a.params?.['form.is_default'] === '{{true}}'));
        expect(setsTrue).toBe(true);
    });

    // 창의 `$parent._local` 쓰기는 저장이 읽는 `_global._local` 미러에서 빠지고, `target="$parent._local"` 에
    // 점 찍은 이름을 주면 그 이름이 통째로 한 칸이 된다 — 그래서 첫 수정(점 찍은 이름 한 줄)은 저장 본문에도
    // 스위치에도 닿지 않았다(브라우저 재실측). docs/frontend/modal-usage.md 「부모 폼 커밋」 대로 두 자리에 쓴다.
    /**
     * @scenario case=c03
     * @effects default_toggle_saved_and_cancel_reverts
     */
    it('[취소] 는 창을 닫기 전에 저장이 읽는 자리(global _local)를 false 로 되돌린다', () => {
        const buttons = findAll(modal, (n) => n.name === 'Button');
        const cancel = buttons.find((b) => JSON.stringify(b).includes('button.cancel'));
        const actions = flatActions(cancel?.actions);
        const resetIdx = actions.findIndex((a) => a.handler === 'setState' && a.params?.target === 'global' && a.params?.['_local.form.is_default'] === false);
        const closeIdx = actions.findIndex((a) => a.handler === 'closeModal');
        expect(resetIdx).toBeGreaterThan(-1);
        expect(closeIdx).toBeGreaterThan(resetIdx);
    });

    /**
     * @scenario case=c04
     * @effects default_toggle_saved_and_cancel_reverts
     */
    it('[취소] 는 창을 닫기 전에 화면이 읽는 자리($parent._local.form.is_default)도 false 로 되돌린다', () => {
        const buttons = findAll(modal, (n) => n.name === 'Button');
        const cancel = buttons.find((b) => JSON.stringify(b).includes('button.cancel'));
        const actions = flatActions(cancel?.actions);
        const resetIdx = actions.findIndex((a) => a.handler === 'setState' && a.params?.target === '$parent._local.form.is_default' && a.params?.value === false);
        const closeIdx = actions.findIndex((a) => a.handler === 'closeModal');
        expect(resetIdx).toBeGreaterThan(-1);
        expect(closeIdx).toBeGreaterThan(resetIdx);
    });

    /**
     * @scenario case=c05
     * @effects default_toggle_saved_and_cancel_reverts
     */
    it('[취소] 는 `$parent._local` 에 점 찍은 이름을 쓰지 않는다(한 칸으로 들어가 아무 데도 닿지 않는다)', () => {
        const actions = flatActions(modal);
        const dotted = actions.filter((a) => a.handler === 'setState' && a.params?.target === '$parent._local' && Object.keys(a.params).some((k) => k.includes('.')));
        expect(dotted).toEqual([]);
    });

    /**
     * @scenario case=c06
     * @effects default_toggle_saved_and_cancel_reverts
     */
    it('[확인] 은 폼 값을 건드리지 않고 창만 닫는다(켜진 값이 저장 때 나간다)', () => {
        const buttons = findAll(modal, (n) => n.name === 'Button');
        const confirm = buttons.find((b) => JSON.stringify(b).includes('button.confirm'));
        const actions = flatActions(confirm?.actions);
        expect(actions.some((a) => a.handler === 'closeModal')).toBe(true);
        expect(actions.some((a) => a.handler === 'setState' && 'form.is_default' in (a.params ?? {}) && a.params['form.is_default'] === false)).toBe(false);
    });
});
