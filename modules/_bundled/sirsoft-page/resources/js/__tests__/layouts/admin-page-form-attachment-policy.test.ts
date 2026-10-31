/**
 * 관리자 페이지 폼 첨부 업로더의 첨부 정책 참조 검증 (#703)
 *
 * 회귀 배경: 업로더가 10MB·확장자 7종·5개를 리터럴로 박아 두어, 모듈 설정(attachment.*)을 바꾸면
 * 화면과 서버 검증(UploadPageAttachmentRequest·PageAttachmentService)의 상한이 어긋났다.
 * 업로더 props 는 서버 공개 설정 데이터 소스(`attachmentSettings` — PageAttachmentService::publicLimits())를
 * 읽는다. MIME → 확장자 변환(`accept`)과 「0 = 무제한」 해석은 서버가 하므로 화면은 받은 값을 그대로 쓴다.
 * 제한 없음·빈 형식 목록의 경계는 adminPageAttachmentUploaderProps.test.ts 가 잠근다.
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
 * `{{ ... }}` 표현식을 데이터 소스 `attachmentSettings` 하나로 평가한다.
 *
 * @param raw 표현식 문자열
 * @param settings 데이터 소스 응답(없으면 undefined)
 * @returns 평가 결과
 */
function evaluate(raw: string, settings: Record<string, any> | undefined): any {
    const expr = String(raw).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
    // eslint-disable-next-line no-new-func
    return new Function('attachmentSettings', `return ${expr};`)(settings);
}

describe('admin_page_form 첨부 업로더 — 모듈 첨부 정책 참조 (#703)', () => {
    const uploader = findComponent(adminPageForm, 'FileUploader');
    const withSettings = (data: Record<string, any>) => ({ data });

    /**
     * @scenario surface=admin_page_form_uploader, setting=attachment_policy
     *
     * @effects page_uploader_reads_attachment_policy
     */
    it('용량·개수·형식을 설정에서 읽는다', () => {
        const g = withSettings({ max_count: 3, max_size_mb: 25, allowed_types: ['image/png', 'application/pdf', 'text/csv'], accept: '.png,.pdf,.csv' });
        expect(evaluate(uploader.props.maxSize, g)).toBe(25);
        expect(evaluate(uploader.props.maxFiles, g)).toBe(3);
        expect(evaluate(uploader.props.accept, g)).toBe('.png,.pdf,.csv');
    });

    /**
     * @scenario surface=admin_page_form_uploader, setting=attachment_policy
     *
     * @effects page_uploader_reads_attachment_policy
     */
    it('설정이 내려오지 않으면 모듈 기본값과 같은 값으로 폴백한다', () => {
        // 설정을 받기 전에는 모듈 기본값(용량 10MB·기본 형식 7종)을 쓰고, 개수는 컴포넌트 기본값에 맡긴다
        expect(evaluate(uploader.props.maxSize, undefined)).toBe(10);
        expect(evaluate(uploader.props.maxFiles, undefined)).toBeUndefined();
        expect(evaluate(uploader.props.accept, undefined)).toBe('.jpg,.jpeg,.png,.gif,.webp,.pdf,.zip');
    });
});
