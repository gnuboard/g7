/**
 * 게시판 모듈 레이아웃 — 활성 언어팩 locale fallback 회귀 테스트
 *
 * 이슈 #263 후속 — 활성 언어팩(ja 등) 환경에서 새로고침 직후 _global.locale 미동기 시점에
 * `?? 'ko'` 하드코딩 fallback 으로 인해 ja 환경에서도 ko 라벨이 표시되던 회귀 차단.
 *
 * 검증 대상 (4 파일):
 *   - _tab_basic.json (게시판 폼 — board_types 옵션 라벨)
 *   - _tab_board_settings_basic.json (게시판 설정 — board_types 옵션 라벨)
 *   - _board_type_manage_modal.json (게시판 타입 관리 모달 — 이름 표시)
 *   - _tab_basic_defaults.json (기본값 탭 — board_types 옵션 라벨)
 *
 * 참조 패턴: sirsoft-page admin_page_detail.json 의 `_local.lang ?? $locale ?? 'ko'`
 */

import { describe, it, expect } from 'vitest';

import tabBasic from '../../../layouts/admin/partials/admin_board_form/_tab_basic.json';
import tabBoardSettingsBasic from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_basic.json';
import boardTypeManageModal from '../../../layouts/admin/partials/admin_board_form/_board_type_manage_modal.json';
import tabBasicDefaults from '../../../layouts/admin/partials/admin_board_settings/_tab_basic_defaults.json';

const layouts: Array<[string, unknown]> = [
    ['_tab_basic.json', tabBasic],
    ['_tab_board_settings_basic.json', tabBoardSettingsBasic],
    ['_board_type_manage_modal.json', boardTypeManageModal],
    ['_tab_basic_defaults.json', tabBasicDefaults],
];

describe('게시판 모듈 — locale fallback 하드코딩 회귀 가드', () => {
    it.each(layouts)("%s — _global.locale fallback 이 정적 'ko' 가 아닌 $locale 을 우선 사용함", (_name, layout) => {
        const layoutStr = JSON.stringify(layout);
        // 단순 `_global.locale ?? 'ko'` 형태 금지 — 활성 언어팩 환경에서 ja 가 아닌 ko 라벨로 표시되는 회귀 발생
        expect(layoutStr).not.toMatch(/_global\.locale \?\? 'ko'/);
    });
});
