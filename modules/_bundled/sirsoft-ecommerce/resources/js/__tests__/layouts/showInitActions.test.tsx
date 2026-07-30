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

import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import showLayout from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/shop/show.json';

describe('상품 상세 페이지 init_actions 검증', () => {
    // **모양이 아니라 뜻으로 읽는다** (D67 ⓐ). 컴파일러 정본은 액션이 1건이면 배열을 접으므로
    // `init_actions` 는 배열일 수도 객체일 수도 있다. `?? []` 폴백은 이 자리를 못 막는다 —
    // 접힘은 부재가 아니라 **객체**라서 폴백이 발동하지 않고 `.find` 가 그대로 터진다.
    const initActions = asArray<any>(showLayout.init_actions);

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
        // 저작본이 `currentSelection="[]"`·`additionalOptions="[]"` 로 선언하므로 정본은 `[]` 다.
        // 종전 짝 `.json` 에는 `{}` 가 있었고 이 단언이 그것을 보고 있었는데, **설치는 저작본을 읽으므로
        // 실제로 돌던 값은 처음부터 `[]`** 였다 — 이 단언은 한 번도 실동작을 보지 않았다.
        expect(resetAction.params.currentSelection).toEqual([]);
        expect(resetAction.params.noOptionQuantity).toBe(1);
        expect(resetAction.params.additionalOptions).toEqual([]);
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
