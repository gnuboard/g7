/**
 * 게시판 모듈 영어 문구 — 수가 붙는 문구가 1 일 때도 읽히게 쓴다.
 *
 * 템플릿 엔진 번역(`$t:`)에는 단수·복수 갈래가 없다(TranslationEngine 에 plural 처리 없음). 그래서
 * 「Settings applied to {{count}} boards.」 처럼 복수 명사를 박아 두면 1 일 때 「1 boards」 로 나온다
 * (7.1.0 실측 K2 후속 관측 O-KF2-1). 이 모듈의 다른 문구가 이미 쓰는 「board(s)」·「Total: {{count}}」 형식으로
 * 맞춘다. 백엔드 문구도 같은 기준이다 — trans_choice 를 쓰는 상대 시각(`단수|복수`)은 제외한다.
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

// 수 자리 바로 뒤에(형용사 하나까지 허용) 「s」로 끝나는 복수 명사가 오는 문구. 「(s)」 를 붙였거나 콜론 뒤에 수를 두면 통과한다.
const FRONT = /\{\{(count|total|updated|skipped|max)\}\}\s*(?:[a-z]+\s)?[a-z]+s\b(?!\()/i;
const BACK = /:(count|limit|days|total|skipped|updated)\s+(?:[a-z]+\s)?[a-z]+s\b(?!\()/i;
// :max 는 대개 고정 상한(글자 수 등)이라 BACK 에 넣지 않는다. 다만 게시판마다 1 로 둘 수 있는 상한(글당 첨부 개수,
// 댓글 깊이)은 「1 files」·「1 levels」 로 나오므로 그 명사만 따로 본다.
const BACK_MAX = /:max\s+(files|levels)\b(?!\()/i;

describe('게시판 모듈 영어 문구 — 수가 1 일 때도 읽힌다', () => {
    // @scenario phrase_kind=frontend_count
    // @effects en_count_phrases_read_correctly_for_one
    it('화면 문구(JSON)에 수 뒤 고정 복수 명사가 없다', () => {
        const files = [path.join(moduleRoot, 'resources/lang/en.json'), ...walk(path.join(moduleRoot, 'resources/lang/partial/en'), '.json')];
        const offenders: string[] = [];
        for (const file of files) {
            if (!fs.existsSync(file)) continue;
            fs.readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
                if (FRONT.test(line)) offenders.push(`${path.relative(moduleRoot, file)}:${i + 1} ${line.trim()}`);
            });
        }
        expect(offenders).toEqual([]);
    });

    // @scenario phrase_kind=backend_count
    // @effects en_count_phrases_read_correctly_for_one
    it('서버 문구(PHP)에 수 뒤 고정 복수 명사가 없다 — 단수|복수 갈래 문구는 제외', () => {
        const offenders: string[] = [];
        for (const file of walk(path.join(moduleRoot, 'src/lang/en'), '.php')) {
            fs.readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
                if (line.includes('|')) return; // trans_choice 단수|복수 문구
                if (BACK.test(line) || BACK_MAX.test(line)) offenders.push(`${path.relative(moduleRoot, file)}:${i + 1} ${line.trim()}`);
            });
        }
        expect(offenders).toEqual([]);
    });
});
