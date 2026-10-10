/**
 * @file adminProductThumbnailFallback.test.ts
 * @description 상품 이미지가 없는 상품도 관리자 화면에서 깨진 그림 없이 보이는지
 *
 * 상품 이미지를 올리지 않은 상품은 API 가 `thumbnail_url: null` 을 준다. 관리자 주문 상세의 상품 줄과
 * 상품평 목록의 상품 칸(데스크톱 칸·모바일 카드)이 `<img src="{{…thumbnail_url…}}">` 를 조건 없이 그려
 * 깨진 그림이 보였다(이커머스 전수 실측 S5, 2026-10-07 — 편집기 주문 상세 표본에서 발견).
 * 상품 목록(`_partial_product_datagrid`)처럼 주소가 있을 때만 그림을, 없으면 그림 아이콘을 그려야 한다.
 *
 * 관리자 레이아웃 원본(.g7.html) 전체에서 상품 그림 주소(`thumbnail_url`)를 src 로 쓰는 `<img>` 를 모두 찾아,
 * 그 태그나 감싸는 요소가 같은 주소를 조건(data-if)으로 걸고, 같은 파일에 주소가 없을 때의 아이콘이 있는지 본다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, resolve, relative } from 'node:path';

const ADMIN_LAYOUTS = resolve(__dirname, '../../../layouts/admin');

/**
 * 디렉터리 아래 레이아웃 원본 파일 경로를 모두 모읍니다.
 *
 * @param dir 시작 디렉터리
 * @param acc 누적 목록
 * @return .g7.html 파일 경로 목록
 */
function markupFiles(dir: string, acc: string[] = []): string[] {
    for (const entry of readdirSync(dir)) {
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) {
            markupFiles(full, acc);
        } else if (full.endsWith('.g7.html')) {
            acc.push(full);
        }
    }

    return acc;
}

/**
 * 비교용으로 식을 정규화합니다(빈 값 대체·옵셔널 체이닝·이중 부정 차이를 없앤다).
 *
 * @param expr 식
 * @return 정규화한 식
 */
function norm(expr: string): string {
    return expr
        .replace(/\s*\?\?\s*(''|null)\s*$/, '')
        .replace(/^!!/, '')
        .replace(/\?\./g, '.')
        .trim();
}

/**
 * 태그의 data-if 식을 돌려줍니다.
 *
 * @param tag 여는 태그 문자열
 * @return data-if 식(없으면 null)
 */
function dataIf(tag: string): string | null {
    return tag.match(/\bdata-if="\{\{((?:(?!\}\}).)*)\}\}"/)?.[1] ?? null;
}

/**
 * 위치 앞의 열린(아직 닫히지 않은) 조상 태그들을 돌려줍니다.
 *
 * @param text 파일 본문
 * @param at 기준 위치
 * @return 바깥→안쪽 순서의 여는 태그 목록
 */
function ancestorsAt(text: string, at: number): string[] {
    const stack: string[] = [];
    const head = text.slice(0, at).replace(/<!--[\s\S]*?-->/g, '');
    for (const m of head.matchAll(/<(\/?)([a-zA-Z][\w-]*)\b[^>]*?(\/?)>/g)) {
        if (m[1]) {
            const i = stack.map((t) => t.match(/^<([\w-]+)/)?.[1]).lastIndexOf(m[2]);
            if (i >= 0) stack.splice(i);
        } else if (!m[3]) {
            stack.push(m[0]);
        }
    }

    return stack;
}

type ImgCase = { file: string; src: string; guards: string[] };

const cases: ImgCase[] = [];
for (const file of markupFiles(ADMIN_LAYOUTS)) {
    const text = readFileSync(file, 'utf8');
    for (const m of text.matchAll(/<img\b[^>]*>/g)) {
        const src = m[0].match(/\bsrc="\{\{((?:(?!\}\}).)*)\}\}"/)?.[1];
        if (src && /thumbnail_url/.test(src)) {
            const guards = [m[0], ...ancestorsAt(text, m.index ?? 0)].map(dataIf).filter((g): g is string => g !== null);
            cases.push({ file: relative(ADMIN_LAYOUTS, file).replace(/\\/g, '/'), src, guards });
        }
    }
}

describe('관리자 화면 — 상품 그림이 없을 때 깨진 그림 대신 아이콘', () => {
    /**
     * @scenario case=c01
     * @effects missing_thumbnail_shows_placeholder
     */
    it('검사할 상품 그림 자리가 있다(주문 상세·상품평 목록 포함)', () => {
        const files = new Set(cases.map((c) => c.file));
        expect(files).toContain('partials/admin_ecommerce_order_detail/_partial_order_info.g7.html');
        expect(files).toContain('admin_ecommerce_product_review_index.g7.html');
    });

    /**
     * @scenario case=c02
     * @effects missing_thumbnail_shows_placeholder
     */
    it.each(cases)('$file 의 그림($src)은 주소가 있을 때만 그린다', ({ src, guards }) => {
        expect(guards.map(norm), `조건: ${JSON.stringify(guards)}`).toContain(norm(src));
    });

    /**
     * @scenario case=c03
     * @effects missing_thumbnail_shows_placeholder
     */
    it.each(cases)('$file 은 그림($src)이 없을 때 아이콘을 그린다', ({ file, src }) => {
        const text = readFileSync(join(ADMIN_LAYOUTS, file), 'utf8');
        const fallbacks = [...text.matchAll(/data-if="\{\{!((?:(?!\}\}).)*)\}\}"[^>]*>\s*<icon\b/g), ...text.matchAll(/<icon\b[^>]*data-if="\{\{!((?:(?!\}\}).)*)\}\}"/g)].map((m) => norm(m[1]));
        expect(fallbacks, file).toContain(norm(src));
    });
});
