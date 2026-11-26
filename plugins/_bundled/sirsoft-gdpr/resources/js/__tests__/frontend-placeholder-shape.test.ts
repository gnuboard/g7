// e2e:allow Vitest 언어 파일 정적 계약 — 화면 문구 JSON 의 자리표시자 모양만 본다(치환 동작은 엔진 TranslationEngine 시험이 덮는다).
/**
 * GDPR 플러그인 화면 문구 — 서버 문구식 자리표시자 `:word` 를 쓰지 않는다.
 *
 * 화면 번역 엔진(`TranslationEngine::replaceParams`)은 `{key}`·`{{key}}` 만 치환한다. `:key` 는
 * 서버 문구(`lang/*.php`) 전용이라 화면에서는 글자 그대로 보인다.
 * 서버는 이 JSON 을 읽지 않는다(모듈 `src/lang`, 플러그인 `lang` 의 PHP 만 읽는다).
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const root = path.resolve(__dirname, '../../..');
const repoRoot = path.resolve(__dirname, '../../../../../..');
const dirs = [path.join(root, 'resources/lang'), path.resolve(root, '../../../lang-packs/_bundled/g7-plugin-sirsoft-gdpr-ja/frontend')];

/** 서버 문구식 자리표시자 — 콜론 앞이 영숫자·콜론·슬래시면 낱말 사이 콜론(`role:admin`, URL, `10:30`)이라 제외. */
const COLON_PLACEHOLDER = /(^|[^A-Za-z0-9:/]):([a-z][a-z_]*)/;

/**
 * 디렉토리 아래 JSON 파일을 모두 모은다.
 *
 * @param dir 디렉토리
 * @returns 파일 경로 목록
 */
function jsonFiles(dir: string): string[] {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return jsonFiles(full);

        return entry.name.endsWith('.json') ? [full] : [];
    });
}

/**
 * 문자열 값마다 콜론 자리표시자를 찾는다.
 *
 * @param node JSON 노드
 * @param prefix 점 경로 접두
 * @returns `키: 값` 목록
 */
function offenders(node: unknown, prefix = ''): string[] {
    if (typeof node === 'string') return COLON_PLACEHOLDER.test(node) ? [`${prefix}: ${node}`] : [];
    if (!node || typeof node !== 'object') return [];

    return Object.entries(node).flatMap(([key, value]) => offenders(value, prefix ? `${prefix}.${key}` : key));
}

describe('GDPR 플러그인 화면 문구 자리표시자', () => {
    // @scenario source=bundled|ja_pack
    // @effects no_colon_placeholder
    it.each(dirs.map((dir) => [path.relative(repoRoot, dir).split(path.sep).join('/'), dir]))('%s 의 화면 문구에 :word 자리표시자가 없다', (_name, dir) => {
        const found = jsonFiles(dir).flatMap((file) =>
            offenders(JSON.parse(fs.readFileSync(file, 'utf8'))).map((line) => `${path.relative(dir, file)} ${line}`),
        );

        expect(found).toEqual([]);
    });
});
