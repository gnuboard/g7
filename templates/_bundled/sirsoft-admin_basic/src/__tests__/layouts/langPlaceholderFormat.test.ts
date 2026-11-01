/**
 * 화면 문구 치환자 형식 가드 — 이 템플릿의 프런트 다국어는 `{{이름}}` 만 치환한다.
 *
 * 백엔드(Laravel)식 `:이름` 을 쓰면 화면에 「요구: :names」처럼 치환자가 그대로 나온다(#151 정보 탭에서 실측된 회귀).
 * 모집단은 손으로 적지 않고 `lang/partial/{ko,en}/*.json` 전 파일의 모든 문자열 값에서 파생한다.
 *
 * @scenario surface=frontend_lang, locale=ko
 * @effects frontend_lang_uses_brace_placeholders
 */
import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const LANG_ROOT = resolve(__dirname, '../../../lang/partial');

/**
 * 중첩 객체의 문자열 값을 점 경로와 함께 모읍니다.
 *
 * @param value 다국어 JSON 값
 * @param path 현재 점 경로
 * @returns [경로, 문자열] 목록
 */
function strings(value: unknown, path = ''): Array<[string, string]> {
    if (typeof value === 'string') return [[path, value]];
    if (!value || typeof value !== 'object') return [];
    return Object.entries(value as Record<string, unknown>).flatMap(([k, v]) => strings(v, path ? `${path}.${k}` : k));
}

describe('프런트 다국어 치환자 형식', () => {
    // @scenario surface=frontend_lang, locale=ko
    // @effects frontend_lang_uses_brace_placeholders
    it('ko·en 문구에 Laravel 식 `:이름` 치환자가 없다', () => {
        const hits: string[] = [];
        let total = 0;
        for (const locale of ['ko', 'en']) {
            for (const file of readdirSync(join(LANG_ROOT, locale)).filter((f) => f.endsWith('.json'))) {
                const json = JSON.parse(readFileSync(join(LANG_ROOT, locale, file), 'utf8'));
                for (const [key, text] of strings(json)) {
                    total++;
                    if (/(^|[\s(（「])\:[a-z_]+\b/.test(text)) hits.push(`${locale}/${file} ${key} = ${text}`);
                }
            }
        }
        expect(total).toBeGreaterThan(1000);
        expect(hits).toEqual([]);
    });

    // @scenario surface=frontend_lang, locale=ko
    // @effects frontend_lang_uses_brace_placeholders
    it('정보 탭의 「요구:」 줄은 {{names}} 로 치환한다', () => {
        for (const locale of ['ko', 'en']) {
            const admin = JSON.parse(readFileSync(join(LANG_ROOT, locale, 'admin.json'), 'utf8'));
            expect(admin.settings.info.requested_by_line).toContain('{{names}}');
        }
    });
});
