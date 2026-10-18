// e2e:allow Vitest 언어 파일 계약 — 관리자 상세 대댓글 버튼 문구는 7.1.0 실측 K3 후속(KF3-6)에서 Playwright 로 ko/en/ja 화면을 열어 클릭까지 재측정했다.
/**
 * 관리자 게시글 상세 — 댓글 아래 대댓글 펼침 버튼 문구.
 *
 * 이 버튼은 댓글의 답글(대댓글)을 펼치고 접는다(`_comment.g7.html` 의 collapsedReplies 토글). 그런데
 * 문구가 「답변글 N개 보기」·「Hide reply posts」 라, 글의 답글(답변글)을 펼치는 것처럼 읽혔다
 * (7.1.0 실측 K3 관측 O-K3-8). 이 모듈은 「답변글」을 글의 답글(use_reply, 답변글 최대 깊이)에,
 * 「대댓글」을 댓글의 답글(max_comment_depth)에 쓰므로 버튼도 대댓글로 부른다. 일본어 언어팩은
 * 같은 구분을 「返信コメント」로 쓴다(max_comment_depth 문구).
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const moduleRoot = path.resolve(__dirname, '../../../..');
const jaPackRoot = path.resolve(moduleRoot, '../../../lang-packs/_bundled/g7-module-sirsoft-board-ja/frontend/partial');

/**
 * JSON 파일에서 점 경로의 값을 읽는다.
 *
 * @param file 파일 경로
 * @param keyPath 점으로 이은 키 경로
 * @returns 문자열 값 (없으면 undefined)
 */
function read(file: string, keyPath: string): string | undefined {
    const json = JSON.parse(fs.readFileSync(file, 'utf8'));

    return keyPath.split('.').reduce((acc: any, key) => (acc == null ? undefined : acc[key]), json);
}

// 같은 키가 두 파일(admin.json 의 admin.posts.detail, admin/posts.json 의 detail)에 있다 — 둘 다 본다.
const sources = (locale: 'ko' | 'en') => [
    { file: path.join(moduleRoot, `resources/lang/partial/${locale}/admin.json`), prefix: 'posts.detail' },
    { file: path.join(moduleRoot, `resources/lang/partial/${locale}/admin/posts.json`), prefix: 'detail' },
];
const jaSources = [
    { file: path.join(jaPackRoot, 'admin.json'), prefix: 'posts.detail' },
    { file: path.join(jaPackRoot, 'admin/posts.json'), prefix: 'detail' },
];

describe('관리자 상세 대댓글 펼침 버튼 문구', () => {
    // @scenario locale=ko
    // @effects admin_comment_replies_toggle_says_comment_replies
    it('한국어는 「대댓글」로 부른다 (답변글 아님)', () => {
        for (const { file, prefix } of sources('ko')) {
            const show = read(file, `${prefix}.show_replies`);
            const hide = read(file, `${prefix}.hide_replies`);
            expect(show, file).toBe('대댓글 {{count}}개 보기');
            expect(hide, file).toBe('대댓글 숨기기');
        }
    });

    // @scenario locale=en
    // @effects admin_comment_replies_toggle_says_comment_replies
    it('영어는 comment replies 로 부른다 (reply posts 아님)', () => {
        for (const { file, prefix } of sources('en')) {
            // 개수 문구는 단수·복수 갈래(`_one`)로 쓴다(PO 결정 「영어 개수 문구」 — `(s)`·괄호 수 표기 대신)
            expect(read(file, `${prefix}.show_replies`), file).toBe('Show {{count}} comment replies');
            expect(read(file, `${prefix}.show_replies_one`), file).toBe('Show {{count}} comment reply');
            expect(read(file, `${prefix}.hide_replies`), file).toBe('Hide comment replies');
        }
    });

    // @scenario locale=ja
    // @effects admin_comment_replies_toggle_says_comment_replies
    it('일본어 언어팩은 返信コメント 로 부른다', () => {
        for (const { file, prefix } of jaSources) {
            expect(read(file, `${prefix}.show_replies`), file).toBe('返信コメント {{count}}件を表示');
            expect(read(file, `${prefix}.hide_replies`), file).toBe('返信コメントを非表示');
        }
    });
});
