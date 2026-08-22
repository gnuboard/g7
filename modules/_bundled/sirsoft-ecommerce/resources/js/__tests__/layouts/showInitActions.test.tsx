/**
 * 상품 상세 페이지 init_actions 및 레이아웃 구조 검증 테스트
 *
 * @description
 * - show.json의 init_actions에서 모달 닫기 검증
 * - show.json의 init_actions에서 옵션 상태 초기화 검증
 * - show.json의 상품 정보 영역에 blur_until_loaded 검증
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import showLayout from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/shop/show.json';

describe('상품 상세 페이지 init_actions 검증', () => {
    const initActions = showLayout.init_actions;

    it('init_actions에 closeModal이 포함되어야 함 (이전 페이지에서 열린 모달 닫기)', () => {
        const closeModalAction = initActions.find(
            (a: any) => a.handler === 'closeModal',
        );
        expect(closeModalAction).toBeDefined();
    });

    it('init_actions에 옵션 상태 초기화 setState가 포함되어야 함', () => {
        const resetAction = initActions.find(
            (a: any) => a.handler === 'setState' && a.params?.target === 'local' && 'selectedOptionItems' in (a.params ?? {}),
        );
        expect(resetAction).toBeDefined();
    });

    it('옵션 상태 초기화가 모든 관련 상태를 리셋해야 함', () => {
        const resetAction = initActions.find(
            (a: any) => a.handler === 'setState' && a.params?.selectedOptionItems !== undefined,
        );
        expect(resetAction).toBeDefined();
        expect(resetAction.params.selectedOptionItems).toEqual([]);
        expect(resetAction.params.currentSelection).toEqual({});
        expect(resetAction.params.noOptionQuantity).toBe(1);
        expect(resetAction.params.additionalOptions).toEqual({});
    });
});

describe('상품 상세 페이지 blur_until_loaded 검증', () => {
    it('상품 정보 영역에 blur_until_loaded가 product 데이터 소스 기준으로 설정되어야 함', () => {
        const json = JSON.stringify(showLayout);

        // blur_until_loaded가 product 데이터 소스와 연결되어 있어야 함
        expect(json).toContain('"blur_until_loaded"');
        expect(json).toContain('"data_sources":"product"');
    });
});
