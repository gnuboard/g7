/**
 * @file mypage-inquiries-toggle-name-i18n.test.ts
 * @description 마이페이지 문의 목록 펼침 단추의 이름 키가 사용자 템플릿 언어 파일에 있는지 잠근다 (7.1.0 검증 후속)
 *
 * 결함: 펼침 단추에 `aria-label="{{… ? '$t:common.collapse' : '$t:common.expand'}}"` 를 달았는데
 *   `common.expand`·`common.collapse` 는 관리자 템플릿에만 있고 사용자 템플릿(sirsoft-basic)에는 없었다.
 *   `$t:` 는 키가 없으면 키 문자열을 그대로 내므로 화면 읽기 프로그램이 「common.expand」 를 읽는다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const TEMPLATE_ROOT = path.resolve(__dirname, '../..');
const REPO_ROOT = path.resolve(TEMPLATE_ROOT, '../../..');
const LIST_PARTIAL = path.join(TEMPLATE_ROOT, 'layouts/partials/mypage/inquiries/_list.json');

/**
 * 템플릿 lang partial 을 읽습니다.
 *
 * @param file 파일 경로
 * @returns 파싱 결과
 */
function readJson(file: string): Record<string, unknown> {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
}

describe('마이페이지 문의 목록 펼침 단추 이름 다국어', () => {
    /**
     * @scenario control=inquiry_toggle
     * @effects inquiry_toggle_accessible_name_translated
     */
    it('펼침 단추가 common.expand·common.collapse 를 이름으로 쓴다', () => {
        const source = fs.readFileSync(LIST_PARTIAL, 'utf8');

        expect(source).toContain('$t:common.collapse');
        expect(source).toContain('$t:common.expand');
    });

    /**
     * @scenario control=inquiry_toggle
     * @effects inquiry_toggle_accessible_name_translated
     */
    it('ko·en 언어 파일과 번들 ja 언어팩에 두 키가 있다', () => {
        const files = [
            path.join(TEMPLATE_ROOT, 'lang/partial/ko/common.json'),
            path.join(TEMPLATE_ROOT, 'lang/partial/en/common.json'),
            path.join(REPO_ROOT, 'lang-packs/_bundled/g7-template-sirsoft-basic-ja/frontend/partial/common.json'),
        ];

        for (const file of files) {
            const common = readJson(file);
            expect(typeof common.expand, `${file} common.expand`).toBe('string');
            expect(typeof common.collapse, `${file} common.collapse`).toBe('string');
            expect(String(common.expand).trim(), `${file} common.expand`).not.toBe('');
            expect(String(common.collapse).trim(), `${file} common.collapse`).not.toBe('');
        }
    });
});
