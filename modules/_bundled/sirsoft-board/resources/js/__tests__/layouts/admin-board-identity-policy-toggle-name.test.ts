/**
 * @file admin-board-identity-policy-toggle-name.test.ts
 * @description 게시판 환경설정 › 본인인증 정책 — 행 스위치 접근 이름이 정책 키만이 아닌지 (7.1.1 출시 전 점검)
 *
 * 코어 환경설정 정책 표의 사본이라 같은 결함이 있었다 — 행 이름(`common.row_cell` 의 row)을 정책 키로만 줘서 화면 읽기
 * 프로그램이 스위치를 「sirsoft-board.post.user_create 활성」으로 읽었다(관찰). 코어 판(admin_basic)처럼 같은 행에
 * 보이는 「인증 목적」 이름을 앞에 두고 키는 괄호로 잇는다. 표(PC)·카드(좁은 화면) 두 판 모두.
 *
 * @scenario-manifest tests/scenarios/control-accessible-names.yaml
 */

import { describe, it, expect, beforeAll } from 'vitest';
import { TranslationEngine } from '@core/template-engine/TranslationEngine';
import policiesPartial from '../../../layouts/admin/partials/admin_board_settings/_tab_identity_policies.json';

/**
 * 트리에서 조건을 만족하는 노드를 모두 모은다.
 *
 * @param node 시작 노드
 * @param predicate 판정
 * @param out 담을 배열
 * @returns out
 */
function collect(node: unknown, predicate: (n: any) => boolean, out: any[] = []): any[] {
    if (Array.isArray(node)) {
        node.forEach((child) => collect(child, predicate, out));
        return out;
    }
    if (node && typeof node === 'object') {
        if (predicate(node)) out.push(node);
        Object.values(node as Record<string, unknown>).forEach((v) => collect(v, predicate, out));
    }
    return out;
}

const templateId = 'sirsoft-admin_basic';
const toggles = collect(policiesPartial, (n) => n.name === 'Toggle' && typeof n.props?.ariaLabel === 'string' && n.props.ariaLabel.includes('row_cell'));
const purposes = { data: [{ id: 'board_write', label: '게시글 작성 본인 확인' }, { id: 'sensitive_action', label: '민감 작업' }] };

describe('게시판 본인인증 정책 행 스위치 이름', () => {
    let engine: TranslationEngine;

    beforeAll(() => {
        engine = TranslationEngine.getInstance();
        // 관리자 템플릿 공통 문구 두 개만 싣는다(실제 값과 같은 모양)
        (engine as any).translations.set(`${templateId}:ko`, {
            common: { row_cell: '{{row}} {{column}}' },
            admin: { identity: { policies: { col: { enabled: '활성' } } } },
        });
    });

    const nameOf = (ariaLabel: string, policy: Record<string, unknown>) =>
        engine.resolveTranslations(ariaLabel, { templateId, locale: 'ko' }, { policy, boardIdentityPurposes: purposes, $templateId: templateId, $locale: 'ko' } as any);

    /**
     * @scenario surface=admin_row
     */
    it('표·카드 두 판에 행 스위치가 있다', () => {
        expect(toggles.length).toBe(2);
    });

    /**
     * @scenario surface=admin_row
     * @effects row_controls_read_row_and_column
     */
    it.each([0, 1])('%i번째 판: 「{인증 목적} ({정책 키}) 활성」으로 읽힌다 — 키만이 아니다', (i) => {
        const name = nameOf(toggles[i].props.ariaLabel as string, { key: 'sirsoft-board.post.user_create', purpose: 'board_write' });

        expect(name).toBe('게시글 작성 본인 확인 (sirsoft-board.post.user_create) 활성');
    });

    /**
     * @scenario surface=admin_row
     * @effects row_controls_read_row_and_column
     */
    it('목적 이름을 모르면 목적 값, 그것도 없으면 키만 쓴다', () => {
        const label = toggles[0].props.ariaLabel as string;

        expect(nameOf(label, { key: 'vendor.x.y', purpose: 'unknown_purpose' })).toBe('unknown_purpose (vendor.x.y) 활성');
        expect(nameOf(label, { key: 'vendor.x.y', purpose: null })).toBe('vendor.x.y 활성');
    });
});
