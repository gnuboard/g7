/**
 * 상품 상세정보 탭 레이아웃 구조 검증 테스트
 *
 * @description
 * - _tab_detail.json의 상품정보제공고시 iteration 구조 검증
 * - 공통정보 text/html 모드 분기 검증
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import tabDetail from '../../../../../../templates/sirsoft-basic/layouts/partials/shop/detail/_tab_detail.json';

describe('상품 상세정보 탭 구조 검증', () => {
    const children = tabDetail.children;

    describe('상품정보제공고시 섹션', () => {
        const noticeSection = children[1]; // 두 번째 child가 상품정보제공고시

        it('상품정보제공고시 조건이 notice.values 존재 여부로 판단해야 함', () => {
            expect(noticeSection.if).toContain('product.data?.notice');
            expect(noticeSection.if).toContain('values');
        });

        it('iteration이 notice.values를 순회해야 함', () => {
            const tableContainer = noticeSection.children[1]; // 테이블 컨테이너
            const iterationDiv = tableContainer.children[1]; // divide-y Div
            expect(iterationDiv.iteration).toBeDefined();
            expect(iterationDiv.iteration.source).toContain('product.data?.notice?.values');
            expect(iterationDiv.iteration.item_var).toBe('noticeItem');
        });

        it('각 행이 label과 value를 바인딩해야 함', () => {
            const tableContainer = noticeSection.children[1];
            const iterationDiv = tableContainer.children[1];
            const row = iterationDiv.children[0];
            const labelCell = row.children[0];
            const valueCell = row.children[1];

            expect(labelCell.text).toContain('noticeItem.label');
            expect(valueCell.text).toContain('noticeItem.value');
        });
    });

    describe('공통정보 섹션', () => {
        const commonInfoSection = children[2]; // 세 번째 child가 공통정보

        it('공통정보 조건이 common_info.content 존재 여부로 판단해야 함', () => {
            expect(commonInfoSection.if).toContain('product.data?.common_info');
            expect(commonInfoSection.if).toContain('content');
        });

        it('HTML 모드용 HtmlContent가 content_mode === html 조건으로 렌더링되어야 함', () => {
            const htmlContent = commonInfoSection.children.find(
                (c: any) => c.name === 'HtmlContent' && c.if?.includes("content_mode === 'html'"),
            );
            expect(htmlContent).toBeDefined();
            expect(htmlContent.props.content).toContain('product.data?.common_info?.content');
        });

        it('Text 모드용 Div가 whitespace-pre-line 스타일로 렌더링되어야 함', () => {
            const textDiv = commonInfoSection.children.find(
                (c: any) => c.name === 'Div' && c.if?.includes("content_mode !== 'html'"),
            );
            expect(textDiv).toBeDefined();
            expect(textDiv.props.className).toContain('whitespace-pre-line');
            expect(textDiv.text).toContain('product.data?.common_info?.content');
        });
    });
});
