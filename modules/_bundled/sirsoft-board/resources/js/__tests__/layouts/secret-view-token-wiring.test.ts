/**
 * 비밀글 열람 확인 토큰 배선 검증 테스트
 *
 * @description
 * 비밀번호로 비밀글 원문을 연 사용자는 그 사실을 다음 요청으로 넘겨야 댓글·답글·신고를
 * 남길 수 있다. 서버는 그 사실을 검증 응답 하나에만 담으므로, 화면이 ①응답의 토큰을
 * 보관하고 ②게시판 API 요청에 헤더로 실어야 흐름이 성립한다.
 *
 * 이 배선은 빠져도 예외도 콘솔 오류도 남기지 않는다 — 원문이 열린 화면에서 댓글 버튼을
 * 눌렀을 때 "열람 권한이 없는 비밀글" 로 거부되는 것이 유일한 증상이고, 서버 게이트는
 * 정상 동작 중이라 백엔드 테스트로는 드러나지 않는다. 그래서 구조로 잠근다.
 */

import { describe, it, expect } from 'vitest';

import userBase from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/_user_base.json';
import basicShow from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/board/types/basic/show.json';
import boardForm from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/board/form.json';

const HEADER = 'X-Board-Secret-View-Token';
const MODIFY_HEADER = 'X-Board-Post-Verify-Token';
const BOARD_API_PATTERN = '/api/modules/sirsoft-board/*';

/**
 * 레이아웃 트리를 평탄화해 모든 노드를 돌려줍니다.
 */
function walk(node: unknown, out: Record<string, unknown>[] = []): Record<string, unknown>[] {
    if (Array.isArray(node)) {
        node.forEach((child) => walk(child, out));

        return out;
    }

    if (node && typeof node === 'object') {
        out.push(node as Record<string, unknown>);
        Object.values(node as Record<string, unknown>).forEach((value) => walk(value, out));
    }

    return out;
}

describe('비밀글 열람 확인 토큰 배선', () => {
    it('_user_base 의 globalHeaders 가 게시판 API 에 열람 토큰 헤더를 주입한다', () => {
        const rules = (userBase as Record<string, any>).globalHeaders;

        expect(Array.isArray(rules)).toBe(true);

        const boardRule = rules.find((rule: any) => rule?.pattern === BOARD_API_PATTERN);

        expect(
            boardRule,
            `globalHeaders 에 ${BOARD_API_PATTERN} 규칙이 없습니다 — 열람 토큰이 요청에 실리지 않아 원문을 연 사용자의 댓글·답글·신고가 전부 거부됩니다.`
        ).toBeDefined();

        expect(boardRule.headers?.[HEADER]).toBe('{{_global.secretViewToken}}');
    });

    it('비밀번호 검증 성공 시 응답의 토큰을 _global.secretViewToken 에 보관한다', () => {
        const nodes = walk(basicShow);

        const verifyCall = nodes.find(
            (node) =>
                typeof node.target === 'string' &&
                node.target.includes('/verify-password') &&
                !node.target.includes('verify-password-for-modify')
        );

        expect(verifyCall, '비밀글 비밀번호 검증 apiCall 을 찾지 못했습니다.').toBeDefined();

        const stored = walk(verifyCall!.onSuccess).some(
            (node) =>
                node.handler === 'setState' &&
                (node.params as Record<string, unknown> | undefined)?.target === 'global' &&
                typeof (node.params as Record<string, unknown> | undefined)?.secretViewToken === 'string'
        );

        expect(
            stored,
            'verify-password 의 onSuccess 가 secret_view_token 을 _global.secretViewToken 에 보관하지 않습니다 — 토큰이 없으면 globalHeaders 가 빈 값을 보내 헤더 자체가 붙지 않습니다.'
        ).toBe(true);
    });
});

describe('게시글 수정 검증 토큰 전송로', () => {
    const sources = (boardForm as Record<string, any>).data_sources as Record<string, any>[];

    it.each(['form_data', 'form_meta'])(
        '%s 는 검증 토큰을 헤더로 보낸다 (쿼리 파라미터 금지)',
        (id) => {
            const source = sources.find((entry) => entry?.id === id);

            expect(source, `${id} 데이터소스를 찾지 못했습니다.`).toBeDefined();

            expect(
                source!.params?.verification_token,
                `${id} 가 검증 토큰을 쿼리 파라미터로 보냅니다 — 자격증명이 주소에 실려 웹서버 접근 기록과 Referer 에 그대로 남습니다.`
            ).toBeUndefined();

            expect(
                source!.headers?.[MODIFY_HEADER],
                `${id} 가 검증 토큰 헤더를 보내지 않습니다 — 비회원 작성자가 자기 글을 수정할 수 없게 됩니다.`
            ).toBe("{{_local.verificationToken ?? ''}}");
        }
    );

    it('검증 토큰을 담는 엔드포인트가 GET 인지 확인한다 (전송로 판단 근거)', () => {
        sources
            .filter((source) => source?.headers?.[MODIFY_HEADER])
            .forEach((source) => {
                expect(
                    String(source.method).toUpperCase(),
                    '헤더 전송이 필요한 이유는 GET 이기 때문입니다 — 메서드가 바뀌면 이 계약을 다시 판단해야 합니다.'
                ).toBe('GET');
            });
    });
});
