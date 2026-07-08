/**
 * @file page-attachment-download-handler.test.tsx
 * @description 사용자 페이지 상세(page/show)의 첨부 다운로드가 토큰 동반 핸들러로 전환됐는지 회귀
 *   (별건: 관리자 상세 첨부 썸네일·다운로드 미노출 — 사용자 화면도 동일 표준으로 정렬).
 *
 * 검증 방식: 레이아웃 JSON 트리 직접 분석 (DOM 비의존).
 *
 * 결함: 다운로드가 <A href="{{att_item.download_url}}"> 브라우저 직접 링크였다.
 *   download_url 은 권한 게이트(미발행)를 가진 공개 라우트이며, <a> 네비게이션에는
 *   토큰이 실리지 않아 권한 관리자의 미발행 첨부 다운로드가 404 로 막혔다.
 *
 * 수정: 다운로드 버튼을 downloadAttachment 핸들러(토큰 동반 fetch → blob)로 전환.
 *
 * @scenario surface=page_show
 * @effects download_uses_handler_not_anchor
 */

import { describe, it, expect } from 'vitest';

import pageShow from '../../layouts/page/show.json';

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

describe('별건 — 사용자 페이지 상세(page/show) 첨부 다운로드 핸들러 전환', () => {
    it('첨부 다운로드는 download_url 을 가리키는 <A href> 직접 링크가 아니다', () => {
        const anchors = collectNodes(
            pageShow,
            (n) =>
                n.name === 'A' &&
                typeof (n.props as Record<string, unknown> | undefined)?.href === 'string' &&
                String((n.props as Record<string, unknown>).href).includes('download_url'),
        );

        expect(anchors.length).toBe(0);
    });

    it('첨부 다운로드는 downloadAttachment 핸들러 click 액션을 사용한다', () => {
        const handlerActions = collectNodes(pageShow, (n) => {
            const actions = (n.actions ?? []) as Array<Record<string, unknown>>;
            return (
                Array.isArray(actions) &&
                actions.some((a) => a.type === 'click' && a.handler === 'downloadAttachment')
            );
        });

        expect(handlerActions.length).toBeGreaterThan(0);

        const node = handlerActions[0];
        const actions = (node.actions ?? []) as Array<Record<string, unknown>>;
        const clickAction = actions.find(
            (a) => a.type === 'click' && a.handler === 'downloadAttachment',
        );
        const params = (clickAction?.params ?? {}) as Record<string, unknown>;
        expect(params.url).toContain('download_url');
        expect(params.filename).toContain('original_filename');
    });
});

describe('별건 — 사용자 페이지 상세(page/show) 이미지 첨부 썸네일', () => {
    it('이미지 첨부는 preview_url 을 src 로 하는 <Img> 썸네일을 렌더한다', () => {
        const thumbs = collectNodes(pageShow, (n) => {
            const props = (n.props ?? {}) as Record<string, unknown>;
            return (
                n.name === 'Img' &&
                typeof props.src === 'string' &&
                String(props.src).includes('att_item') &&
                String(props.src).includes('preview_url')
            );
        });

        expect(thumbs.length).toBeGreaterThan(0);
    });

    it('썸네일은 is_image 조건으로만 렌더된다 (일반 파일은 아이콘)', () => {
        // preview_url <Img> 를 감싸는 노드는 is_image 참일 때만 렌더되어야 한다
        const guarded = collectNodes(pageShow, (n) => {
            const ifCond = typeof n.if === 'string' ? (n.if as string) : '';
            const hasImgChild =
                collectNodes(n.children, (c) => {
                    const props = (c.props ?? {}) as Record<string, unknown>;
                    return (
                        c.name === 'Img' &&
                        typeof props.src === 'string' &&
                        String(props.src).includes('preview_url')
                    );
                }).length > 0;
            return hasImgChild && ifCond.includes('att_item') && ifCond.includes('is_image');
        });

        expect(guarded.length).toBeGreaterThan(0);
    });
});
