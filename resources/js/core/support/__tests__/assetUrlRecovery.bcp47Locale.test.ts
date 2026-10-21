// e2e:allow 정적→API 역변환 규칙의 순수 로직 단위. 브라우저 계층은 asset-url-mode.spec.ts 가 커버한다.
/**
 * 정적 게시 언어 파일 역변환 — 언어팩 규칙(BCP 47) 로케일 (#148, engine-v1.81.0)
 *
 * 역변환 정규식이 `lang\/([a-zA-Z-]+)` 라 숫자 지역(`es-419`)을 놓쳐, 그 언어의 정적 언어 파일이 404 일 때
 * API 주소로 복구하지 못했다. TS `staticToLegacy` 와 blade 인라인 복구기 두 사본이 같은 결과를 내는지도 본다.
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { staticToLegacy } from '../assetUrl';

/**
 * blade 파샬의 인라인 `staticToLegacy` 를 추출해 실행 가능한 함수로 만든다.
 *
 * @returns 인라인 역변환 함수
 */
function loadInlineStaticToLegacy(): (url: string) => string | null {
    const bladePath = resolve(__dirname, '../../../../views/partials/asset-url-recovery.blade.php');
    const source = readFileSync(bladePath, 'utf-8');

    const start = source.indexOf('function staticToLegacy(url) {');
    expect(start, 'blade 파샬에서 staticToLegacy 를 찾지 못했다').toBeGreaterThan(-1);

    let depth = 0;
    let end = start;
    for (let i = source.indexOf('{', start); i < source.length; i += 1) {
        if (source[i] === '{') depth += 1;
        else if (source[i] === '}') {
            depth -= 1;
            if (depth === 0) {
                end = i + 1;
                break;
            }
        }
    }

    // eslint-disable-next-line no-new-func
    return new Function(`${source.slice(start, end)}\nreturn staticToLegacy;`)() as (url: string) => string | null;
}

describe('정적 게시 언어 파일 역변환 — BCP 47 로케일 (#148)', () => {
    // @scenario shape=numeric_region
    // @effects asset_recovery_handles_numeric_region_locale
    it('숫자 지역 로케일(es-419) 언어 파일도 API 주소로 역변환한다', () => {
        expect(staticToLegacy('/build/ext/1234/templates/sirsoft-basic/lang/es-419.json'))
            .toBe('/api/templates/sirsoft-basic/lang/es-419.json?v=1234');
        expect(loadInlineStaticToLegacy()('/build/ext/1234/templates/sirsoft-basic/lang/zh-Hant-TW.json'))
            .toBe('/api/templates/sirsoft-basic/lang/zh-Hant-TW.json?v=1234');
    });

    // @scenario shape=three_letter
    // @effects asset_recovery_handles_numeric_region_locale
    it('세 글자·숫자 지역·문자 표기 로케일에서 두 구현의 결과가 같다', () => {
        const inline = loadInlineStaticToLegacy();

        for (const url of [
            '/build/ext/1234/templates/sirsoft-basic/lang/fil.json',
            '/build/ext/1234/templates/sirsoft-basic/lang/es-419.json',
            '/build/ext/1234/templates/sirsoft-basic/lang/zh-Hant-TW.json',
        ]) {
            expect(inline(url), `blade 인라인 역변환기가 ${url} 을 변환하지 못했다`).not.toBeNull();
            expect(inline(url), `역변환 규칙 드리프트: ${url}`).toBe(staticToLegacy(url));
        }
    });
});
