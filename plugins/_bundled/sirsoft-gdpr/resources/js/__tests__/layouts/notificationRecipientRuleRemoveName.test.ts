/**
 * @file notificationRecipientRuleRemoveName.test.ts
 * @description 알림 템플릿 편집 창의 수신자 규칙 삭제(×) 버튼이 읽을 수 있는 이름을 갖는지
 *
 * GDPR 플러그인 설정 › 알림 템플릿의 [편집] 창에서 수신자 규칙마다 붙은 × 버튼은 아이콘뿐이라, 화면 낭독기가 버튼 이름을
 * 아이콘 이름 「xmark」 로 읽었다(이커머스 전수 실측 S5, 2026-10-07 — 같은 창이 코어·이커머스·게시판·
 * GDPR·수출입은행 다섯 곳에 같은 꼴로 있다). 어느 규칙을 지우는지 알 수 있게 「수신자 규칙 N 삭제」 를
 * aria-label 로 단다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const MODAL = resolve(__dirname, '../../../layouts/admin/partials/plugin_settings/_modal_notification_template_edit.json');

type Node = Record<string, any>;

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

const modal: Node = JSON.parse(readFileSync(MODAL, 'utf8'));
const removeButtons = findAll(modal, (n) => n.name === 'Button' && JSON.stringify(n.children ?? []).includes('fa-xmark'));

describe('알림 템플릿 편집 창 — 수신자 규칙 삭제 버튼 이름', () => {
    it('수신자 규칙 삭제 버튼을 찾았다', () => {
        expect(removeButtons.length).toBeGreaterThan(0);
    });

    it('삭제 버튼마다 「수신자 규칙 N 삭제」 aria-label 이 있다(아이콘 이름 xmark 로 읽히지 않게)', () => {
        for (const b of removeButtons) {
            expect(b.props?.['aria-label']).toBe('$t:admin.settings.notification_definitions.recipients.remove_rule|index={{rcptIdx + 1}}');
        }
    });
});
