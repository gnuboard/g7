/**
 * e2e:allow 굽기 산출의 빈 값 모양(`{}` vs `[]`)만 단언한 구조 테스트다 — 두 값 모두 소비처가
 * 옵셔널 속성 접근이라 렌더 결과가 동일해 브라우저에서 잴 표면 변화가 없다(#599 15차 병합
 * 전수 보존 검사 실측). 옵션 선택 흐름 자체는 기존 shop spec 이 담당한다.
 *
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

    /** @scenario surface=product_detail_entry
     *  @effects entry_closes_modal_left_open_by_previous_screen */
    it('init_actions에 closeModal이 포함되어야 함 (이전 페이지에서 열린 모달 닫기)', () => {
        const closeModalAction = initActions.find(
            (a: any) => a.handler === 'closeModal',
        );
        expect(closeModalAction).toBeDefined();
    });

    /** @scenario surface=product_detail_entry
     *  @effects entry_resets_option_selection_state */
    it('init_actions에 옵션 상태 초기화 setState가 포함되어야 함', () => {
        const resetAction = initActions.find(
            (a: any) => a.handler === 'setState' && a.params?.target === 'local' && 'selectedOptionItems' in (a.params ?? {}),
        );
        expect(resetAction).toBeDefined();
    });

    /** @scenario surface=product_detail_entry
     *  @effects option_reset_uses_array_for_ordered_list_and_map_for_keyed_selection */
    it('옵션 상태 초기화가 모든 관련 상태를 리셋해야 함', () => {
        const resetAction = initActions.find(
            (a: any) => a.handler === 'setState' && a.params?.selectedOptionItems !== undefined,
        );
        expect(resetAction).toBeDefined();
        expect(resetAction.params.selectedOptionItems).toEqual([]);
        // 이 둘은 **이름표로 값을 꺼내는 자리**라 빈 값도 map(`{}`)이다 — 옵션 그룹 이름으로
        // `_local.currentSelection?.[그룹명]` 을 읽고, 선택을 지울 때도 같은 이름으로 덮어쓴다.
        //
        // 종전 판본은 `[]` 를 단언하며 「마크업 소스가 `[]` 로 선언하므로 정본은 `[]` 이고,
        // 설치는 소스를 읽으니 실제로 돌던 값은 처음부터 `[]` 였다」고 적어 두었는데 그 전제가
        // 사실과 다르다 — 이 화면의 짝 `.g7.html` 은 저작 트랙 이관 커밋(#476 `66aa7d7d0`)에서
        // 처음 생겼고, 그 전에는 설치가 `.json` 을 읽었으므로 실제로 돌던 값은 `{}` 였다.
        // 즉 `[]` 는 이관이 바꾼 값이고 이 단언이 그것을 뒤늦게 잠근 것이다(#599 15차 병합
        // 전수 보존 검사 실측, 운영 결정 2026-09-21 「22곳 전부 되돌린다」).
        expect(resetAction.params.currentSelection).toEqual({});
        expect(resetAction.params.noOptionQuantity).toBe(1);
        expect(resetAction.params.additionalOptions).toEqual({});
    });
});

describe('상품 상세 페이지 blur_until_loaded 검증', () => {
    /** @scenario surface=product_detail_entry
     *  @effects product_area_blurs_until_product_datasource_resolves */
    it('상품 정보 영역에 blur_until_loaded가 product 데이터 소스 기준으로 설정되어야 함', () => {
        const json = JSON.stringify(showLayout);

        // blur_until_loaded가 product 데이터 소스와 연결되어 있어야 함
        expect(json).toContain('"blur_until_loaded"');
        expect(json).toContain('"data_sources":"product"');
    });
});
