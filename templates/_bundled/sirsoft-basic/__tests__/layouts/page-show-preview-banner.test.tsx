/**
 * @file page-show-preview-banner.test.tsx
 * @description 미발행 페이지 관리자 미리보기 배너 (이슈 #424-15)
 *
 * 정책: 페이지 조회 권한 관리자가 사용자 화면에서 미발행 페이지를 미리볼 때,
 *   상단에 "미발행 페이지 미리보기 중" 안내 배너를 노출한다. 백엔드(PublicPageResource)가
 *   미발행+권한자 응답에만 is_preview=true 를 내려주므로, 배너의 if 는 그 값에 의존한다.
 *   발행 페이지·게스트 응답은 is_preview=false → 배너 미노출.
 *
 * 검증 방식: 실제 page/show.json 레이아웃 트리 직접 분석 (DOM 비의존).
 *   - 배너 블록이 존재하고 if 가 page.data.is_preview 에 의존하는지 고정
 *   - 배너 문구가 다국어 키($t:user.page.preview_banner)로 렌더되는지 고정
 */

import { describe, it, expect } from 'vitest';

import pageShow from '../../layouts/page/show.json';

type Node = Record<string, unknown> & { children?: unknown };

/**
 * 트리를 재귀 순회하며 조건을 만족하는 모든 노드를 수집한다.
 */
function collectNodes(node: unknown, predicate: (n: Node) => boolean): Node[] {
    const result: Node[] = [];
    const walk = (cur: unknown): void => {
        if (Array.isArray(cur)) {
            cur.forEach(walk);
            return;
        }
        if (cur && typeof cur === 'object') {
            const obj = cur as Node;
            if (predicate(obj)) {
                result.push(obj);
            }
            Object.values(obj).forEach(walk);
        }
    };
    walk(node);
    return result;
}

/**
 * id 로 노드를 찾는다.
 */
function findById(root: unknown, id: string): Node | undefined {
    return collectNodes(root, (n) => n.id === id)[0];
}

describe('이슈 #424-15 — 미발행 페이지 미리보기 배너 (page/show.json)', () => {
    it('미리보기 배너 블록(page_preview_banner)이 존재한다', () => {
        const banner = findById(pageShow, 'page_preview_banner');
        expect(banner).toBeDefined();
    });

    it('배너의 if 가 page.data.is_preview 에 의존한다 (발행/게스트는 미노출)', () => {
        const banner = findById(pageShow, 'page_preview_banner');
        expect(typeof banner?.if).toBe('string');
        expect(banner?.if as string).toContain('is_preview');
        // 단일 바인딩 형태 {{...}} 여야 엔진이 식으로 평가 (if 표현식 규칙)
        expect((banner?.if as string).trim()).toMatch(/^\{\{.*\}\}$/);
    });

    it('배너 문구가 다국어 키로 렌더된다 (하드코딩 금지)', () => {
        const banner = findById(pageShow, 'page_preview_banner');
        const spans = collectNodes(banner, (n) => n.name === 'Span' && typeof n.text === 'string');
        const texts = spans.map((s) => s.text as string);
        expect(texts).toContain('$t:user.page.preview_banner');
    });

    it('배너가 본문 카드(page_content_card) 내부, 제목 영역보다 앞에 위치한다', () => {
        const card = findById(pageShow, 'page_content_card');
        expect(card).toBeDefined();
        const children = card?.children as Node[];
        expect(Array.isArray(children)).toBe(true);
        // 첫 자식이 미리보기 배너여야 함 (제목/발행일보다 위)
        expect(children[0]?.id).toBe('page_preview_banner');
    });
});
