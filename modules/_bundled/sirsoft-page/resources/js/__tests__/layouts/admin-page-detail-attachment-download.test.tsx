/**
 * @file admin-page-detail-attachment-download.test.tsx
 * @description 관리자 페이지 상세 첨부 카드가 토큰 동반 핸들러로 다운로드하고, 다운로드 아이콘이
 *   유효한 Font Awesome 이름을 쓰는지 회귀 (별건: 관리자 상세 첨부 썸네일·다운로드 미노출).
 *
 * 검증 방식: 레이아웃 JSON 트리 직접 분석 (DOM 비의존).
 *
 * 결함 A: 첨부 카드(id: "attachment_card")가 <A href="{{...download_url}}"> 브라우저 직접
 *   링크였다. 커밋 d48c684 로 download_url 이 auth:sanctum 보호 admin 라우트가 되어 <a>
 *   네비게이션에 토큰이 실리지 않아 401 로 깨졌다.
 * 결함 B: 다운로드 아이콘 name="arrow-down-tray"(Heroicons)는 FA 에 없어 조용히 미렌더.
 *
 * 수정: 카드를 Div + actions:[{click → downloadAttachment}] 로 전환(토큰 동반),
 *   아이콘 name 을 유효한 FA 이름 "download" 로 교체.
 *
 * @scenario surface=admin_page_detail
 * @effects download_card_uses_handler_not_anchor
 * @effects download_icon_valid_fa_name
 */

import { describe, it, expect } from 'vitest';

import adminPageDetail from '../../../layouts/admin/admin_page_detail.json';

type Node = Record<string, unknown> & { children?: unknown; actions?: unknown };

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

// Heroicons 계열 이름(FA 미존재) — 조용히 미렌더 회귀 차단용 블랙리스트
const HEROICON_NAMES = ['arrow-down-tray', 'arrow-up-tray', 'arrow-down-circle'];

describe('별건 — 관리자 페이지 상세 첨부 다운로드 카드', () => {
    it('첨부 카드는 <A href> 직접 링크가 아니다 (토큰 미동반 401 회귀 차단)', () => {
        const cards = collectNodes(adminPageDetail, (n) => typeof n.id === 'string' && n.id.startsWith('attachment_card'));
        expect(cards.length).toBeGreaterThan(0);

        for (const card of cards) {
            const props = (card.props ?? {}) as Record<string, unknown>;
            const isAnchorHref = card.name === 'A' && typeof props.href === 'string';
            expect(isAnchorHref).toBe(false);
        }
    });

    it('첨부 카드는 click 액션으로 downloadAttachment 핸들러를 호출한다', () => {
        const cards = collectNodes(adminPageDetail, (n) => typeof n.id === 'string' && n.id.startsWith('attachment_card'));
        const card = cards[0];

        const actions = (card.actions ?? []) as Array<Record<string, unknown>>;
        const clickAction = actions.find((a) => a.type === 'click');

        expect(clickAction).toBeDefined();
        expect(clickAction?.handler).toBe('downloadAttachment');

        const params = (clickAction?.params ?? {}) as Record<string, unknown>;
        expect(params.url).toContain('download_url');
        expect(params.filename).toContain('original_filename');
    });

    it('다운로드 아이콘은 유효한 Font Awesome 이름을 쓴다 (arrow-down-tray 미렌더 회귀 차단)', () => {
        const icons = collectNodes(adminPageDetail, (n) => n.name === 'Icon');
        const iconNames = icons
            .map((n) => (n.props as Record<string, unknown> | undefined)?.name)
            .filter((v): v is string => typeof v === 'string');

        for (const blacklisted of HEROICON_NAMES) {
            expect(iconNames).not.toContain(blacklisted);
        }

        // 다운로드 아이콘이 실제로 존재하고 FA 유효 이름("download")을 쓴다
        expect(iconNames).toContain('download');
    });
});
