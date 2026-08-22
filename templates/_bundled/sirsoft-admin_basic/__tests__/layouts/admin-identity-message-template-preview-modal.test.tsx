/**
 * @file admin-identity-message-template-preview-modal.test.tsx
 * @description IDV 메시지 템플릿 미리보기 모달 — 정적 회귀 테스트
 *
 * 회귀 배경 (2026-06-16 Chrome MCP 검수, 20260616_1813 이력):
 *  - 결함 A: 본문(body)을 basic Div + innerHTML prop 으로 렌더 → React 는 innerHTML 속성을 무시하여
 *            본문 영역 완전 공백. 알림 미리보기 모달(_modal_notification_template_preview)과 동일하게
 *            composite HtmlContent + content + isHtml:true 로 렌더해야 함.
 *  - 결함 B: 닫기 버튼 라벨이 $t:admin.common.close (미존재 키) 참조 → 키 원문 노출.
 *            $t:common.close 로 교체해야 함 (알림 미리보기 모달 패리티).
 */

import { describe, it, expect } from 'vitest';

const previewModal = require('../../layouts/partials/admin_settings/_modal_identity_message_template_preview.json');

function collectNodes(node: any, predicate: (n: any) => boolean): any[] {
    const result: any[] = [];
    const visit = (n: any) => {
        if (!n || typeof n !== 'object') return;
        if (Array.isArray(n)) {
            n.forEach(visit);
            return;
        }
        if (predicate(n)) result.push(n);
        if (n.children) visit(n.children);
        if (n.actions) visit(n.actions);
        if (n.params) visit(n.params);
        if (n.onSuccess) visit(n.onSuccess);
        if (n.onError) visit(n.onError);
        if (n.iteration) visit(n.iteration);
    };
    visit(node);
    return result;
}

describe('IDV 메시지 템플릿 — 미리보기 모달', () => {
    it('Modal id + size=lg', () => {
        expect(previewModal.id).toBe('modal_identity_message_template_preview');
        expect(previewModal.props.size).toBe('lg');
    });

    it('제목(subject) 미리보기 — 치환 결과 바인딩', () => {
        const subjectNodes = collectNodes(previewModal, (n) =>
            typeof n.text === 'string' &&
            n.text.includes('_global.identity_message_template_preview_modal?.subject')
        );
        expect(subjectNodes.length).toBe(1);
    });

    // 결함 A 회귀 — 본문은 HtmlContent 로 렌더되어야 한다 (basic Div + innerHTML 금지)
    it('본문(body)은 composite HtmlContent + content + isHtml:true 로 렌더', () => {
        const htmlContents = collectNodes(previewModal, (n) =>
            n.type === 'composite' &&
            n.name === 'HtmlContent' &&
            typeof n.props?.content === 'string' &&
            n.props.content.includes('_global.identity_message_template_preview_modal?.body') &&
            n.props.isHtml === true
        );
        expect(
            htmlContents.length,
            '본문은 composite HtmlContent + content + isHtml:true 로 렌더되어야 합니다 (결함 A).'
        ).toBe(1);
    });

    it('본문을 basic Div + innerHTML prop 으로 렌더하지 않는다 (결함 A 금지 패턴)', () => {
        const innerHtmlDivs = collectNodes(previewModal, (n) =>
            n.type === 'basic' &&
            n.name === 'Div' &&
            typeof n.props?.innerHTML === 'string'
        );
        expect(
            innerHtmlDivs.length,
            'basic Div 의 innerHTML prop 은 React 가 무시하여 본문이 렌더되지 않습니다 (결함 A).'
        ).toBe(0);
    });

    // 결함 B 회귀 — 닫기 버튼은 $t:common.close 사용 ($t:admin.common.close 미존재 키 금지)
    it('닫기 버튼은 $t:common.close 키 사용', () => {
        const closeButtons = collectNodes(previewModal, (n) =>
            n.name === 'Button' && n.text === '$t:common.close'
        );
        expect(closeButtons.length).toBe(1);
        expect(closeButtons[0].actions?.[0]?.handler).toBe('closeModal');
        expect(closeButtons[0].actions?.[0]?.target).toBe('modal_identity_message_template_preview');
    });

    it('admin.common.* 미존재 키를 참조하지 않는다 (결함 B 금지 패턴)', () => {
        const adminCommonRefs = collectNodes(previewModal, (n) =>
            typeof n.text === 'string' && n.text.includes('$t:admin.common.')
        );
        expect(
            adminCommonRefs.length,
            'admin.common.close 는 미존재 키로 키 원문이 노출됩니다 (결함 B).'
        ).toBe(0);
    });
});
