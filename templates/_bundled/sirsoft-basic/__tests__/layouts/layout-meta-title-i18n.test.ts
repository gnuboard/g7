/**
 * 레이아웃 `<meta title>` 이 화면 언어를 따른다.
 *
 * 7.1.1 출시 전 검증: 주문서 레이아웃(`shop/checkout`)의 `<meta title>` 만 한국어 「주문서 작성」 으로
 * 박혀 있었다(7.1.0 부터). 다른 basic 레이아웃은 모두 `$t:` 키다. 이 값은 레이아웃 편집기 페이지 설정 창의 편집 대상
 * 이름(`PageSettingsModal` resolveTargetName)으로 쓰여, 영어·일본어 사이트에서도 한국어로 보였다. 같은 화면 머리말이 이미 쓰는 키(`shop.checkout.title`)로 바꿨다.
 *
 * 레이아웃 전부를 훑어 하드코딩 제목이 다시 들어오지 못하게 막고, 쓰는 키가 ko·en·ja 에 모두 있는지도 함께 본다.
 * 런타임이 읽는 굽기 산출물(짝 .json)의 meta.title 도 원본과 같은지 대조한다(원본만 고치고 굽지 않으면 화면은 옛 제목이다).
 *
 * @scenario-manifest tests/scenarios/layout-meta-title-i18n.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const EXT_ROOT = path.resolve(__dirname, '../..');
const LAYOUT_ROOT = path.join(EXT_ROOT, 'layouts');
const JA_ROOT = path.resolve(EXT_ROOT, '../../../lang-packs/_bundled/g7-template-sirsoft-basic-ja/frontend');

type Json = Record<string, unknown>;

/**
 * 폴더 아래 .g7.html 파일을 모두 모읍니다.
 *
 * @param dir 시작 폴더
 * @return 파일 경로 목록
 */
function collectLayouts(dir: string): string[] {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            return collectLayouts(full);
        }

        return full.endsWith('.g7.html') ? [full] : [];
    });
}

/**
 * 레이아웃의 `<meta title>` 값을 모읍니다.
 *
 * @return 파일(레이아웃 폴더 기준)과 제목 값 목록
 */
function metaTitles(): Array<{ file: string; title: string }> {
    return collectLayouts(LAYOUT_ROOT).flatMap((file) => {
        const source = fs.readFileSync(file, 'utf8');
        const found: Array<{ file: string; title: string }> = [];
        for (const match of source.matchAll(/<meta\b([^>]*)>/g)) {
            const title = match[1].match(/\btitle="([^"]*)"/);
            if (title) {
                found.push({ file: path.relative(LAYOUT_ROOT, file).split(path.sep).join('/'), title: title[1] });
            }
        }

        return found;
    });
}

/**
 * 한 언어에서 번역 키 값을 찾습니다(partial 파일 → 루트 파일 순).
 *
 * @param locale 언어
 * @param key 번역 키(`$t:` 뺀 것)
 * @return 번역 문구(없으면 undefined)
 */
function translate(locale: 'ko' | 'en' | 'ja', key: string): unknown {
    const [file, ...rest] = key.split('.');
    const partial = locale === 'ja'
        ? path.join(JA_ROOT, 'partial', `${file}.json`)
        : path.join(EXT_ROOT, 'lang/partial', locale, `${file}.json`);
    const walk = (node: unknown, parts: string[]): unknown =>
        parts.reduce<unknown>((acc, part) => (acc && typeof acc === 'object' ? (acc as Json)[part] : undefined), node);
    if (fs.existsSync(partial)) {
        const value = walk(JSON.parse(fs.readFileSync(partial, 'utf8')), rest);
        if (value !== undefined) {
            return value;
        }
    }
    const rootFile = locale === 'ja' ? path.join(JA_ROOT, 'ja.json') : path.join(EXT_ROOT, 'lang', `${locale}.json`);

    return fs.existsSync(rootFile) ? walk(JSON.parse(fs.readFileSync(rootFile, 'utf8')), key.split('.')) : undefined;
}

describe('레이아웃 <meta title> 다국어', () => {
    // @scenario surface=all_layouts
    // @effects meta_title_uses_translation_key
    it('모든 레이아웃의 <meta title> 이 번역 키(또는 식)다 — 하드코딩 문구 0', () => {
        const titles = metaTitles();
        expect(titles.length).toBeGreaterThan(0);
        const hardcoded = titles.filter(({ title }) => !/^(\$t:|\{\{)/.test(title));
        expect(hardcoded).toEqual([]);
    });

    // @scenario surface=checkout
    // @effects meta_title_uses_translation_key, meta_title_key_exists_in_all_locales
    it('주문서 레이아웃 제목은 화면 머리말과 같은 키(shop.checkout.title)이고 ko·en·ja 에 모두 있다', () => {
        const checkout = metaTitles().find(({ file }) => file === 'shop/checkout.g7.html');
        expect(checkout?.title).toBe('$t:shop.checkout.title');
        for (const locale of ['ko', 'en', 'ja'] as const) {
            expect(String(translate(locale, 'shop.checkout.title') ?? '').trim(), locale).not.toBe('');
        }
    });

    // @scenario surface=all_layouts
    // @effects meta_title_key_exists_in_all_locales
    it('<meta title> 이 쓰는 정적 번역 키가 ko·en·ja 에 모두 있다', () => {
        const missing: string[] = [];
        for (const { file, title } of metaTitles()) {
            if (!title.startsWith('$t:')) {
                continue;
            }
            const key = title.slice(3);
            for (const locale of ['ko', 'en', 'ja'] as const) {
                if (typeof translate(locale, key) !== 'string') {
                    missing.push(`${file} ${locale} ${key}`);
                }
            }
        }
        expect(missing).toEqual([]);
    });

    // @scenario surface=all_layouts
    // @effects meta_title_baked_json_matches_source
    it('굽기 산출물(짝 .json)의 meta.title 이 원본 <meta title> 과 같다 — 런타임은 .json 을 읽는다', () => {
        const titles = metaTitles();
        const compared: string[] = [];
        const mismatched: string[] = [];
        for (const { file, title } of titles) {
            const jsonPath = path.join(LAYOUT_ROOT, file.replace(/.g7.html$/, '.json'));
            if (!fs.existsSync(jsonPath)) {
                continue;
            }
            const baked = (JSON.parse(fs.readFileSync(jsonPath, 'utf8')) as Json).meta as Json | undefined;
            compared.push(file);
            if (baked?.title !== title) {
                mismatched.push(`${file}: 원본 ${title} / 산출 ${String(baked?.title)}`);
            }
        }
        expect(compared.length).toBeGreaterThan(0);
        expect(compared).toContain('shop/checkout.g7.html');
        expect(mismatched).toEqual([]);
        const checkoutJson = JSON.parse(fs.readFileSync(path.join(LAYOUT_ROOT, 'shop/checkout.json'), 'utf8')) as Json;
        expect((checkoutJson.meta as Json)?.title).toBe('$t:shop.checkout.title');
    });
});
