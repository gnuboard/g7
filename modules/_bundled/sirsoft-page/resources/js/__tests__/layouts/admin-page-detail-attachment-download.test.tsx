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
 * 결함 C: 그 `downloadAttachment` 는 관리자 템플릿(sirsoft-admin_basic)이 등록한 핸들러라, 다른 관리자
 *   템플릿에서는 카드를 눌러도 아무 일도 일어나지 않았다(레이아웃 검사도 「미등록 핸들러」로 경고).
 *   이 모듈이 자기 핸들러(`sirsoft-page.downloadAttachment`)를 두고 실패 시 안내를 띄운다.
 *
 * 시나리오: page-attachment-serving.yaml(카드·아이콘) · admin-attachment-download-handler.yaml(핸들러 소유)
 */

import { describe, it, expect } from 'vitest';

import adminPageDetail from '../../../layouts/admin/admin_page_detail.json';
import { handlerMap } from '../../handlers';
import { downloadAttachmentHandler } from '../../handlers/downloadAttachment';

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
    /**
     * @effects download_card_uses_downloadAttachment_handler_not_anchor
     */
    it('첨부 카드는 <A href> 직접 링크가 아니다 (토큰 미동반 401 회귀 차단)', () => {
        const cards = collectNodes(adminPageDetail, (n) => typeof n.id === 'string' && n.id.startsWith('attachment_card'));
        expect(cards.length).toBeGreaterThan(0);

        for (const card of cards) {
            const props = (card.props ?? {}) as Record<string, unknown>;
            const isAnchorHref = card.name === 'A' && typeof props.href === 'string';
            expect(isAnchorHref).toBe(false);
        }
    });

    /**
     * @scenario outcome=success
     * @effects admin_card_calls_module_owned_download_handler
     * @effects download_card_uses_downloadAttachment_handler_not_anchor
     */
    it('첨부 카드는 click 액션으로 이 모듈이 소유한 downloadAttachment 핸들러를 호출한다', () => {
        const cards = collectNodes(adminPageDetail, (n) => typeof n.id === 'string' && n.id.startsWith('attachment_card'));
        const card = cards[0];

        const actions = (card.actions ?? []) as Array<Record<string, unknown>>;
        const clickAction = actions.find((a) => a.type === 'click');

        expect(clickAction).toBeDefined();
        // 템플릿 핸들러(접두어 없는 downloadAttachment)에 기대지 않는다 — 다른 관리자 템플릿에서 무반응 회귀 차단
        expect(clickAction?.handler).toBe('sirsoft-page.downloadAttachment');
        expect(handlerMap.downloadAttachment).toBe(downloadAttachmentHandler);

        const params = (clickAction?.params ?? {}) as Record<string, unknown>;
        expect(params.url).toContain('download_url');
        expect(params.filename).toContain('original_filename');
    });

    /**
     * @scenario outcome=failure
     * @effects download_failure_shows_error_toast
     */
    it('내려받기에 실패하면 오류 안내를 띄운다', () => {
        const cards = collectNodes(adminPageDetail, (n) => typeof n.id === 'string' && n.id.startsWith('attachment_card'));
        const clickAction = ((cards[0].actions ?? []) as Array<Record<string, unknown>>).find((a) => a.type === 'click');
        const onError = clickAction?.onError as Record<string, unknown> | undefined;

        expect(onError?.handler).toBe('toast');
        expect((onError?.params as Record<string, unknown>).message).toBe('$t:sirsoft-page.admin.page.errors.download_failed');
    });

    /**
     * @effects download_icon_uses_valid_fa_name_not_heroicon
     */
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
