// e2e:allow 레이아웃 노드 계약 단위 — 실제 모달 본문 표시는 브라우저 실측(이력 문서 §8 「T1 B1」 행)이 덮는다.
/**
 * 알림 발송 로그 상세 모달 본문 = HtmlContent (#158 부수 결함 B1)
 *
 * 종전 본문 Div 는 `dangerouslySetInnerHTML` 에 문자열을 넘겼다 — React 는 `{__html}` 객체만 받으므로 본문이
 * 보이지 않거나 렌더 오류가 났고, 정화(DOMPurify)도 없었다. 목록 행 펼침(`_partial_datagrid`)과 같은
 * HtmlContent(isHtml)로 그린다.
 */
import { describe, it, expect } from 'vitest';
import modalLayout from '../../../layouts/partials/admin_notification_log_list/_modal_log_detail.json';

type Node = { name?: string; props?: Record<string, unknown>; children?: unknown };

function collect(node: unknown, out: Node[] = []): Node[] {
    if (Array.isArray(node)) {
        node.forEach((child) => collect(child, out));
    } else if (node && typeof node === 'object') {
        out.push(node as Node);
        Object.values(node as Record<string, unknown>).forEach((value) => collect(value, out));
    }
    return out;
}

describe('알림 발송 로그 상세 모달 본문 (#158 B1)', () => {
    // @scenario surface=notification_log_modal
    // @effects notification_log_body_uses_sanitized_html_content
    it('본문은 isHtml HtmlContent 로 그리고 dangerouslySetInnerHTML 을 쓰지 않는다', () => {
        const nodes = collect(modalLayout);

        const body = nodes.find((n) => n.name === 'HtmlContent' && String(n.props?.content ?? '').includes('selectedLog?.body'));
        expect(body, '본문 HtmlContent 노드가 없다').toBeDefined();
        expect(body!.props?.isHtml).toBe(true);

        const raw = nodes.filter((n) => n.props && Object.prototype.hasOwnProperty.call(n.props, 'dangerouslySetInnerHTML'));
        expect(raw).toEqual([]);
    });
});
