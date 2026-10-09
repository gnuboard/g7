/**
 * 게시판 모듈 영어 문구 — 수가 붙는 문구가 1 일 때도 읽히게 쓴다.
 *
 * 「Settings applied to {{count}} boards.」 처럼 복수 명사를 박아 두고 단수 갈래가 없으면 1 일 때 「1 boards」 로
 * 나온다(7.1.0 실측 K2 후속 관측 O-KF2-1). 영어 개수 문구는 PO 결정(po-decisions 7.1.0 비이커머스 전수 실측
 * 「영어 개수 문구」)대로 1 이면 단수, 그 밖은 복수로 쓰고 `(s)` 표기를 쓰지 않는다. 화면 문구는 같은 파일에
 * `키_one` 단수 갈래를 두고(TranslationEngine 이 `$t:키|count=N` 으로 고른다, engine-v1.78.7), 서버 문구는
 * `단수|복수` 로 써서 trans_choice·trans_count·검증 치환기가 고르게 한다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const moduleRoot = path.resolve(__dirname, '../../../..');

/**
 * 디렉터리 아래 파일을 재귀로 모은다.
 *
 * @param dir 시작 디렉터리
 * @param ext 확장자
 * @returns 파일 경로 목록
 */
function walk(dir: string, ext: string): string[] {
    if (!fs.existsSync(dir)) return [];
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return walk(full, ext);
        return entry.name.endsWith(ext) ? [full] : [];
    });
}

// 수 자리 바로 뒤에(형용사 하나까지 허용) 「s」로 끝나는 복수 명사가 오는 문구. 같은 파일에 `키_one` 단수 갈래가 있으면 통과한다.
const FRONT = /\{\{(count|total|updated|skipped|max)\}\}\s*(?:[a-z]+\s)?[a-z]+s\b/i;
// `(s)` 표기는 PO 결정으로 쓰지 않는다.
const PAREN_S = /[a-z]\(s\)/i;
const BACK = /:(count|limit|days|total|skipped|updated)\s+(?:[a-z]+\s)?[a-z]+s\b/i;
// :max 는 대개 고정 상한(글자 수 등)이라 BACK 에 넣지 않는다. 다만 게시판마다 1 로 둘 수 있는 상한(글당 첨부 개수,
// 댓글 깊이)은 「1 files」·「1 levels」 로 나오므로 그 명사만 따로 본다.
const BACK_MAX = /:max\s+(files|levels)\b/i;

/**
 * 중첩 JSON 을 「점 경로 => 값」 으로 펼친다.
 *
 * @param node JSON 노드
 * @param prefix 상위 경로
 * @param out 결과 누적
 * @returns 점 경로 => 문자열 값
 */
function flatten(node: unknown, prefix = '', out: Record<string, string> = {}): Record<string, string> {
    if (node && typeof node === 'object') {
        for (const [key, value] of Object.entries(node as Record<string, unknown>)) {
            flatten(value, prefix ? `${prefix}.${key}` : key, out);
        }
    } else if (typeof node === 'string') {
        out[prefix] = node;
    }
    return out;
}

describe('게시판 모듈 영어 문구 — 수가 1 일 때도 읽힌다', () => {
    // @scenario phrase_kind=frontend_count
    // @effects en_count_phrases_read_correctly_for_one
    it('화면 문구(JSON)에 수 뒤 고정 복수 명사가 없다', () => {
        const files = [path.join(moduleRoot, 'resources/lang/en.json'), ...walk(path.join(moduleRoot, 'resources/lang/partial/en'), '.json')];
        const offenders: string[] = [];
        for (const file of files) {
            if (!fs.existsSync(file)) continue;
            const entries = flatten(JSON.parse(fs.readFileSync(file, 'utf8')));
            for (const [key, text] of Object.entries(entries)) {
                const where = `${path.relative(moduleRoot, file)} ${key}`;
                if (PAREN_S.test(text)) offenders.push(`${where} — (s) 표기: ${text}`);
                if (!FRONT.test(text) || key.endsWith('_one')) continue;
                if (!(`${key}_one` in entries)) offenders.push(`${where} — 단수 갈래(_one) 없음: ${text}`);
            }
        }
        expect(offenders).toEqual([]);
    });

    // @scenario phrase_kind=backend_count
    // @effects en_count_phrases_read_correctly_for_one
    it('서버 문구(PHP)에 수 뒤 고정 복수 명사가 없다 — 단수|복수 갈래 문구는 제외', () => {
        const offenders: string[] = [];
        for (const file of walk(path.join(moduleRoot, 'src/lang/en'), '.php')) {
            fs.readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
                if (PAREN_S.test(line)) offenders.push(`${path.relative(moduleRoot, file)}:${i + 1} (s) 표기: ${line.trim()}`);
                if (line.includes('|')) return; // 단수|복수 문구
                if (BACK.test(line) || BACK_MAX.test(line)) offenders.push(`${path.relative(moduleRoot, file)}:${i + 1} ${line.trim()}`);
            });
        }
        expect(offenders).toEqual([]);
    });
});
