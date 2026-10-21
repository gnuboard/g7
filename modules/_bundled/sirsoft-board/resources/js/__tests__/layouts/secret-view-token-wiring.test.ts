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
    // @scenario surface=post_detail
    // @effects board_api_requests_carry_secret_view_token
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

    // @scenario surface=post_detail
    // @effects secret_view_token_persists_in_tab_session
    it('_user_base 의 init 이 탭 세션에 보관된 열람 토큰을 복원한다 (initSecretViewToken)', () => {
        const inits = ((userBase as Record<string, any>).init_actions ?? []) as Record<string, unknown>[];

        expect(
            inits.some((action) => action?.handler === 'initSecretViewToken'),
            '_user_base init_actions 에 initSecretViewToken 이 없습니다 — 답변 링크(전체 이동)·새로고침에서 열람 토큰이 사라져, 원글 비밀번호를 맞힌 비회원이 답변 화면에서 다시 잠깁니다.'
        ).toBe(true);
    });

    // @scenario surface=post_detail
    // @effects verify_success_saves_token_and_replaces_post
    it('비밀번호 검증 성공 시 응답의 토큰을 saveSecretViewToken 으로 탭 세션에 보관한다', () => {
        const verifyCall = findApiCall('/verify-password');

        expect(verifyCall, '비밀글 비밀번호 검증 apiCall 을 찾지 못했습니다.').toBeDefined();
        expectStoresToken(verifyCall!, 'verify-password');
    });

    // @scenario surface=reply_detail
    // @effects verify_success_saves_token_and_replaces_post
    it('원글 비밀번호(스레드 비밀번호) 검증 apiCall 이 있고, 성공 시 글을 갈아끼우고 토큰을 보관한다', () => {
        const threadCall = findApiCall('/verify-thread-password');

        expect(
            threadCall,
            '답변 화면의 원글 비밀번호 폼(verify-thread-password apiCall)이 없습니다 — 답변 주소로 바로 들어온 비회원 질문자가 답변을 열 방법이 없습니다.'
        ).toBeDefined();

        expectStoresToken(threadCall!, 'verify-thread-password');

        const replaced = walk(threadCall!.onSuccess).some(
            (node) =>
                node.handler === 'updateDataSource' &&
                (node.params as Record<string, unknown> | undefined)?.dataSourceId === 'post'
        );

        expect(replaced, 'verify-thread-password 성공 시 post 데이터소스를 응답으로 갈아끼우지 않습니다 — 본문이 계속 잠겨 보입니다.').toBe(true);
    });

    // @scenario surface=post_detail
    // @effects secret_view_token_persists_in_tab_session
    it('verify-password 성공 블록에 _global.secretViewToken 을 직접 덮어쓰는 setState 가 남지 않는다 (탭 세션 저장 우회 금지)', () => {
        ['/verify-password', '/verify-thread-password'].forEach((path) => {
            const call = findApiCall(path);
            const direct = walk(call?.onSuccess).some(
                (node) =>
                    node.handler === 'setState' &&
                    (node.params as Record<string, unknown> | undefined)?.target === 'global' &&
                    'secretViewToken' in ((node.params as Record<string, unknown> | undefined) ?? {})
            );

            expect(direct, `${path} 성공 블록이 메모리에만 토큰을 씁니다 — 전체 이동·새로고침에서 사라집니다.`).toBe(false);
        });
    });
});

/**
 * show.json 에서 target 이 주어진 경로로 끝나는 apiCall 노드를 찾습니다(수정용 검증은 제외).
 */
function findApiCall(path: string): Record<string, unknown> | undefined {
    return walk(basicShow).find(
        (node) =>
            typeof node.target === 'string' &&
            (node.target as string).endsWith(path) &&
            !(node.target as string).includes('verify-password-for-modify')
    );
}

/**
 * apiCall 의 onSuccess 가 응답 토큰·만료를 saveSecretViewToken 으로 넘기는지 단언합니다.
 */
function expectStoresToken(call: Record<string, unknown>, label: string): void {
    const save = walk(call.onSuccess).find((node) => node.handler === 'saveSecretViewToken');

    expect(
        save,
        `${label} 의 onSuccess 가 saveSecretViewToken 을 부르지 않습니다 — 토큰이 없으면 globalHeaders 가 빈 값을 보내 헤더 자체가 붙지 않습니다.`
    ).toBeDefined();

    const params = (save!.params ?? {}) as Record<string, unknown>;

    expect(String(params.token ?? '')).toContain('secret_view_token');
    expect(String(params.expiresAt ?? '')).toContain('secret_view_expires_at');
}

describe('게시글 수정 검증 토큰 전송로', () => {
    const sources = (boardForm as Record<string, any>).data_sources as Record<string, any>[];

    // @scenario surface=post_detail
    // @effects post_modify_token_sent_as_header
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

    // @scenario surface=post_detail
    // @effects post_modify_token_sent_as_header
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
