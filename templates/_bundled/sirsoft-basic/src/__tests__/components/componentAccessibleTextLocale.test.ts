/**
 * @file componentAccessibleTextLocale.test.ts
 * @description 컴포넌트가 읽기 이름·안내 글자(aria-label·title·placeholder·alt)를 한국어로 박아 두지 않는지
 *
 * 영어 화면 장바구니에서 수량 칸·버튼의 읽기 이름이 「수량」·「수량 감소」·「수량 증가」 로 남아 있었다
 * (이커머스 전수 실측 S5, 2026-10-07). 같은 꼴이 모바일 메뉴 「메뉴 닫기」, 페이지 전환 표시
 * 「페이지 전환 중」·「페이지 로딩 중」 에도 있었다. 화면 낭독기가 다른 언어 화면에서 한국어를 읽으므로
 * 이런 글자는 `G7Core.t()` 번역 키로 내야 한다.
 *
 * 컴포넌트 원본(.tsx, 시험 제외)에서 주석을 걷어 낸 뒤 이 네 속성의 글자 값에 한글이 있는지 본다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, resolve, relative } from 'node:path';

const COMPONENTS = resolve(__dirname, '../../components');

/**
 * 디렉터리 아래 컴포넌트 원본 경로를 모두 모읍니다(시험 폴더 제외).
 *
 * @param dir 시작 디렉터리
 * @param acc 누적 목록
 * @return .tsx 파일 경로 목록
 */
function sources(dir: string, acc: string[] = []): string[] {
    for (const entry of readdirSync(dir)) {
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) {
            if (entry !== '__tests__') {
                sources(full, acc);
            }
        } else if (full.endsWith('.tsx')) {
            acc.push(full);
        }
    }

    return acc;
}

/**
 * 블록·줄 주석을 걷어 냅니다(문서 주석의 사용 예시는 검사 대상이 아니다).
 *
 * @param text 원본
 * @return 주석을 뺀 원본
 */
function stripComments(text: string): string {
    return text.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');
}

const files = sources(COMPONENTS);

describe('컴포넌트 읽기 이름·안내 글자 다국어', () => {
    /**
     * @scenario case=c01
     * @effects accessible_text_follows_locale
     */
    it('검사할 컴포넌트가 있다', () => {
        expect(files.length).toBeGreaterThan(20);
    });

    /**
     * @scenario case=c02
     * @effects accessible_text_follows_locale
     */
    it.each(files.map((f) => relative(COMPONENTS, f).replace(/\\/g, '/')))('%s 에 한국어로 박힌 aria-label·title·placeholder·alt 가 없다', (file) => {
        const code = stripComments(readFileSync(join(COMPONENTS, file), 'utf8'));
        const hits = [...code.matchAll(/\b(aria-label|title|placeholder|alt)="([^"]*[가-힣][^"]*)"/g)].map((m) => `${m[1]}="${m[2]}"`);
        expect(hits).toEqual([]);
    });
});
