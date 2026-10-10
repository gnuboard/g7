// e2e:allow Vitest 레이아웃 계약 파일 — 첨부 토글 키보드(Enter·Space)·aria-expanded·고유 id 는 7.1.0 실측 K3 후속(KF3-8)에서 Playwright 로 ko/en/ja 재측정했다.
/**
 * 관리자 게시글 상세 — 첨부 펼침 토글의 버튼 의미와 고유 id.
 *
 * @description
 * 원글·답글 카드의 「첨부파일 (N)」 펼침 토글이 `Div` 였다. 마우스로만 눌리고 키보드로는 닿지
 * 않았으며(역할·tabindex 없음), 펼침 여부를 보조기술에 알리지 않았다. 답글 카드는 반복 안에서
 * 그려지는데 id 가 고정 문자열 `attachments_toggle` 이라, 원글 하나와 답글 둘인 상세에 같은 id 가
 * 세 번 나왔다(7.1.0 실측 K3 관측 O-K3-14).
 *
 * 계약:
 *   ① 토글은 `type="button"` 인 Button 이다 — Enter·Space 로 눌리고 탭 순서에 들어간다.
 *   ② `aria-expanded` 가 펼침 상태(원글 `showAttachments`, 답글 `expandedReplyAttachments[item.id]`)를 따른다.
 *   ③ `aria-controls` 가 펼쳐지는 목록을 가리키고, 그 목록 묶음에 같은 id 가 있다.
 *   ④ 답글 카드의 토글·목록 id 는 답글 식별자를 품어 답글마다 다르다.
 */

import { describe, it, expect } from 'vitest';
import replyCard from '../../../layouts/admin/partials/admin_board_post_detail/_reply_card_content.json';
import postCard from '../../../layouts/admin/partials/admin_board_post_detail/_post_card_content.json';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

/**
 * 트리에서 조건에 맞는 노드를 모은다.
 *
 * @param node 시작 노드
 * @param pred 판정 함수
 * @param out 결과 누적 배열
 * @returns 맞는 노드 목록
 */
function collect(node: any, pred: (n: any) => boolean, out: any[] = []): any[] {
    if (!node || typeof node !== 'object') return out;
    if (Array.isArray(node)) {
        node.forEach((n) => collect(n, pred, out));

        return out;
    }
    if (pred(node)) out.push(node);
    Object.values(node).forEach((v) => collect(v, pred, out));

    return out;
}

/**
 * 첨부 펼침 토글 노드를 찾는다 (첨부 상태를 뒤집는 클릭 동작을 가진 노드).
 *
 * @param root 레이아웃 루트
 * @param stateKey 뒤집는 상태 키
 * @returns 토글 노드
 */
function findToggle(root: any, stateKey: string): any {
    const hits = collect(
        root,
        // 단일 액션은 컴파일러가 배열을 객체로 접으므로 모양 무관 판독(actionsOf)으로 읽는다
        (n) => actionsOf(n).some((a: any) => a?.handler === 'setState' && a?.params && stateKey in a.params),
    );
    expect(hits.length, `${stateKey} 를 뒤집는 토글이 하나여야 한다`).toBe(1);

    return hits[0];
}

describe('관리자 상세 첨부 펼침 토글 — 버튼 의미', () => {
    // @scenario card=post
    // @effects admin_attachments_toggle_is_keyboard_button
    it('원글 카드: type=button 인 Button, aria-expanded·aria-controls 가 목록과 이어진다', () => {
        const toggle = findToggle(postCard, 'showAttachments');
        expect(toggle.name).toBe('Button');
        expect(toggle.props?.type).toBe('button');
        expect(toggle.props?.['aria-expanded']).toBe('{{!!_local.showAttachments}}');

        const controls = toggle.props?.['aria-controls'];
        expect(controls).toBeTruthy();
        expect(collect(postCard, (n) => n.id === controls).length, 'aria-controls 대상 목록이 없다').toBe(1);
    });

    // @scenario card=reply
    // @effects admin_attachments_toggle_is_keyboard_button, admin_attachments_toggle_ids_unique_per_reply
    it('답글 카드: type=button 인 Button, 토글·목록 id 가 답글마다 다르다', () => {
        const toggle = findToggle(replyCard, 'expandedReplyAttachments');
        expect(toggle.name).toBe('Button');
        expect(toggle.props?.type).toBe('button');
        expect(toggle.props?.['aria-expanded']).toBe('{{!!_local.expandedReplyAttachments?.[item.id]}}');

        // 반복 안 노드 — 고정 문자열 id 면 답글 수만큼 같은 id 가 생긴다
        expect(toggle.id).toContain('{{item.id}}');

        const controls = toggle.props?.['aria-controls'];
        expect(controls).toContain('{{item.id}}');
        expect(collect(replyCard, (n) => n.id === controls).length, 'aria-controls 대상 목록이 없다').toBe(1);
    });

    // @scenario card=reply
    // @effects admin_attachments_toggle_ids_unique_per_reply
    it('답글 카드에 고정 문자열 attachments_toggle id 가 남지 않는다', () => {
        expect(collect(replyCard, (n) => n.id === 'attachments_toggle').length).toBe(0);
    });

    // @scenario card=reply
    // @effects admin_attachments_toggle_ids_unique_per_reply
    it('답글 카드의 첨부 묶음·첨부 아이콘 id 도 답글 식별자를 품는다 (같은 id 반복 방지)', () => {
        const fixed = collect(replyCard, (n) => typeof n.id === 'string' && !n.id.includes('{{') && /attach/.test(n.id));
        expect(fixed.map((n) => n.id)).toEqual([]);
    });
});
