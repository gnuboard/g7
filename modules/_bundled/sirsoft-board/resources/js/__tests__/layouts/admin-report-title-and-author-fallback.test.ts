/**
 * 게시판 관리자 레이아웃 — 신고 목록 글 제목 링크·작성자 이름 대체값 잠금 시험 (7.1.1 출시 전 점검)
 *
 * @description
 * - 글 제목 링크: 신고 목록의 글 제목 칸이 `<span>` 이라 가운데 클릭·Ctrl+클릭·「새 탭에서 열기」가 되지 않았다(반응·스크랩 목록은 링크).
 *   board 1.2.1 은 「신고·반응·스크랩 목록의 글 제목」을 링크로 약속했다.
 * - 답변 안내의 작성자 이름: 계정이 남지 않은 작성자의 댓글에 「답변」을 열면 안내와 입력칸 이름이 「{{name}}님에게 답변 작성 중...」 —
 *   작성자 이름이 null 이면 `$t:…|name={{…}}` 가 치환되지 않는다. 작성자 이름을 번역 인자로 넣는 자리는 모두
 *   「알 수 없음」(unknown_author) 대체값을 가져야 한다(레이아웃 전수 규칙).
 * - 신고 목록의 작성자 이름: 신고 목록의 작성자 이름이 비면 빈 칸이었다 — 계정이 남지 않은 회원은 서버가 이름 null·비회원 아님으로 내보내고
 *   화면이 「알 수 없음」으로 대신 보인다(관리자 글 목록과 같은 표시).
 * 검증 방식: 굽기 산출물(JSON) 정적 판정.
 *
 * @scenario-manifest tests/scenarios/admin-report-title-author-fallback.yaml
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/** 확장 루트 */
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');
/** 신고 목록 레이아웃 */
const REPORTS_INDEX = path.join(ROOT, 'resources/layouts/admin/admin_board_reports_index.json');
/** 「알 수 없음」 번역 키 */
const UNKNOWN_AUTHOR = 'sirsoft-board.admin.posts.unknown_author';

/**
 * 디렉터리 아래 .json 을 모두 모은다.
 *
 * @param dir 시작 디렉터리
 * @returns .json 절대경로 목록
 */
function collectJson(dir: string): string[] {
    if (!fs.existsSync(dir)) return [];
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return collectJson(full);
        return entry.name.endsWith('.json') ? [full] : [];
    });
}

/**
 * JSON 안의 모든 문자열을 모은다.
 *
 * @param x 시작 값
 * @param acc 담을 배열
 * @returns acc
 */
function stringsIn(x: unknown, acc: string[] = []): string[] {
    if (typeof x === 'string') acc.push(x);
    else if (Array.isArray(x)) x.forEach((y) => stringsIn(y, acc));
    else if (x && typeof x === 'object') Object.values(x).forEach((y) => stringsIn(y, acc));
    return acc;
}

/**
 * 조건에 맞는 객체를 처음 하나 찾는다.
 *
 * @param x 시작 값
 * @param pred 판정
 * @returns 찾은 객체 또는 null
 */
function findFirst(x: unknown, pred: (o: Record<string, any>) => boolean): Record<string, any> | null {
    if (Array.isArray(x)) {
        for (const y of x) {
            const r = findFirst(y, pred);
            if (r) return r;
        }
        return null;
    }
    if (!x || typeof x !== 'object') return null;
    const o = x as Record<string, any>;
    if (pred(o)) return o;
    for (const v of Object.values(o)) {
        const r = findFirst(v, pred);
        if (r) return r;
    }
    return null;
}

describe('신고 목록 — 글 제목 링크', () => {
    const json = JSON.parse(fs.readFileSync(REPORTS_INDEX, 'utf8'));
    const column = findFirst(json, (o) => o.field === 'snapshot_target_title' && Array.isArray(o.cellChildren));

    /**
     * @scenario surface=reports_index_title
     * @effects report_title_is_link
     */
    it('글 제목 칸에 실제 주소를 가진 링크(A)가 있다', () => {
        expect(column).not.toBeNull();
        const link = findFirst(column!.cellChildren, (o) => o.name === 'A');
        expect(link, '신고 목록 글 제목이 링크가 아니다 — 가운데 클릭·새 탭이 안 된다').not.toBeNull();
        expect(link!.props?.href).toBe('/admin/board/{{row.board?.slug}}/post/{{row.post_id}}');
        expect(JSON.stringify(link!.actions ?? [])).toContain('"handler":"navigate"');
        expect(link!.text).toBe('{{row.board?.title}}');
    });

    /**
     * 링크 갈래의 짝 — 주소를 만들 수 없는 행은 글자로 남는다.
     * @scenario surface=reports_index_title
     */
    it('게시판이 없는 행은 링크 대신 글자로 둔다', () => {
        const span = findFirst(column!.cellChildren, (o) => o.name === 'Span' && o.text === '{{row.board?.title}}');
        expect(span?.if).toBe('{{!row.board?.slug || !row.post_id}}');
    });
});

describe('신고 목록 — 작성자 이름 대체값', () => {
    /**
     * @scenario surface=reports_index_author
     * @effects report_author_unknown_fallback
     */
    it('작성자 이름이 없으면 「알 수 없음」으로 보인다', () => {
        const json = JSON.parse(fs.readFileSync(REPORTS_INDEX, 'utf8'));
        const column = findFirst(json, (o) => o.field === 'snapshot_author_name' && Array.isArray(o.cellChildren));
        expect(column).not.toBeNull();
        const texts = stringsIn(column!.cellChildren).filter((s) => s.includes('row.author?.name'));
        expect(texts.length).toBeGreaterThan(0);
        texts.filter((s) => /^\{\{row\.author\?\.name/.test(s)).forEach((s) => expect(s).toContain(UNKNOWN_AUTHOR));
    });
});

describe('작성자 이름을 번역 인자로 넣는 자리 — 대체값', () => {
    // `$t:키|이름={{…author…name…}}` — 작성자 이름 인자. 이름이 null 이면 자리표시자가 그대로 보인다
    const PARAM = /\|[a-z_]+=\{\{([^}]*author[^}]*)\}\}/g;
    const files = collectJson(path.join(ROOT, 'resources/layouts'));

    /**
     * @scenario surface=author_name_t_params
     */
    it('모집단이 비어 있지 않다', () => {
        const hits = files.flatMap((f) => stringsIn(JSON.parse(fs.readFileSync(f, 'utf8'))).flatMap((s) => [...s.matchAll(PARAM)]));
        expect(hits.length).toBeGreaterThanOrEqual(3);
    });

    /**
     * @scenario surface=author_name_t_params
     * @effects author_param_unknown_fallback
     */
    it('작성자 이름 인자는 모두 「알 수 없음」 대체값을 가진다', () => {
        const missing: string[] = [];
        for (const f of files) {
            for (const s of stringsIn(JSON.parse(fs.readFileSync(f, 'utf8')))) {
                for (const m of s.matchAll(PARAM)) {
                    if (!m[1].includes(UNKNOWN_AUTHOR)) missing.push(`${path.relative(ROOT, f).split(path.sep).join('/')}: ${m[0]}`);
                }
            }
        }
        expect(missing).toEqual([]);
    });
});
