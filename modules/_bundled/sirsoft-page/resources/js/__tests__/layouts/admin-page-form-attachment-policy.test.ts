/**
 * 관리자 페이지 폼 첨부 업로더의 첨부 정책 참조 검증 (#703)
 *
 * 회귀 배경: 업로더가 10MB·확장자 7종·5개를 리터럴로 박아 두어, 모듈 설정(attachment.*)을 바꾸면
 * 화면과 서버 검증(UploadPageAttachmentRequest·PageAttachmentService)의 상한이 어긋났다.
 * 업로더 props 는 `_global.modules['sirsoft-page'].attachment` 를 읽고, 설정의 MIME 목록을 업로더가
 * 거르는 확장자 목록으로 옮겨야 한다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import adminPageForm from '../../../layouts/admin/admin_page_form.json';

/**
 * 트리에서 이름이 일치하는 첫 컴포넌트를 찾는다.
 *
 * @param node 탐색 시작 노드
 * @param name 컴포넌트 이름
 * @returns 찾은 노드 또는 null
 */
function findComponent(node: any, name: string): any {
    if (!node || typeof node !== 'object') return null;
    if (node.name === name) return node;
    for (const value of Object.values(node)) {
        const found = Array.isArray(value)
            ? value.map((v) => findComponent(v, name)).find(Boolean)
            : findComponent(value, name);
        if (found) return found;
    }
    return null;
}

/**
 * `{{ ... }}` 표현식을 `_global` 하나로 평가한다.
 *
 * @param raw 표현식 문자열
 * @param global 전역 상태
 * @returns 평가 결과
 */
function evaluate(raw: string, global: Record<string, any>): any {
    const expr = String(raw).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
    // eslint-disable-next-line no-new-func
    return new Function('_global', `return ${expr};`)(global);
}

describe('admin_page_form 첨부 업로더 — 모듈 첨부 정책 참조 (#703)', () => {
    const uploader = findComponent(adminPageForm, 'FileUploader');
    const withSettings = (attachment: Record<string, any>) => ({ modules: { 'sirsoft-page': { attachment } } });

    /**
     * @scenario surface=admin_page_form_uploader, setting=attachment_policy
     *
     * @effects page_uploader_reads_attachment_policy
     */
    it('용량·개수·형식을 설정에서 읽는다', () => {
        const g = withSettings({ max_count: 3, max_size_mb: 25, allowed_types: ['image/png', 'application/pdf', 'text/csv'] });
        expect(evaluate(uploader.props.maxSize, g)).toBe(25);
        expect(evaluate(uploader.props.maxFiles, g)).toBe(3);
        expect(evaluate(uploader.props.accept, g)).toBe('.png,.pdf,.csv');
    });

    /**
     * @scenario surface=admin_page_form_uploader, setting=attachment_policy
     *
     * @effects page_uploader_reads_attachment_policy
     */
    it('빈 형식 목록은 형식 제한 없음, 개수 0 은 무제한으로 옮긴다', () => {
        const g = withSettings({ max_count: 0, max_size_mb: 10, allowed_types: [] });
        expect(evaluate(uploader.props.accept, g)).toBe('');
        expect(evaluate(uploader.props.maxFiles, g)).toBeGreaterThan(100);
    });

    /**
     * @scenario surface=admin_page_form_uploader, setting=attachment_policy
     *
     * @effects page_uploader_reads_attachment_policy
     */
    it('설정이 내려오지 않으면 모듈 기본값과 같은 값으로 폴백한다', () => {
        expect(evaluate(uploader.props.maxSize, {})).toBe(10);
        expect(evaluate(uploader.props.maxFiles, {})).toBe(5);
        expect(evaluate(uploader.props.accept, {})).toBe('.jpg,.jpeg,.png,.gif,.webp,.pdf,.zip');
    });
});
