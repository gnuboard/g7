import { default as React } from 'react';
import { EditorAttrs } from '../../types';
import { ExtensionInfo } from './ExtensionBadge';
/**
 * 메뉴 노드 (관리 목록 응답 `menus.data.data` 계층)
 */
export interface MenuAccessNode {
    id: number;
    name: string | Record<string, string>;
    is_active?: boolean;
    extension_type?: string | null;
    extension_identifier?: string | null;
    children?: MenuAccessNode[];
}
export interface MenuAccessTreeProps {
    /** 메뉴 트리 (최상위 + children) */
    data?: MenuAccessNode[];
    /** 선택된 메뉴 ID 배열 */
    value?: number[];
    /** 선택 변경 콜백 (e.target.value = 메뉴 ID 배열) */
    onChange?: (e: {
        target: {
            value: number[];
        };
    }) => void;
    /** 비활성화 (권한 없음 — 볼 수만 있음) */
    disabled?: boolean;
    /** 전부 체크된 읽기 전용 (관리자 역할) */
    lockedAll?: boolean;
    /** 설치된 모듈 목록 (확장 배지 이름 조회) */
    installedModules?: ExtensionInfo[];
    /** 설치된 플러그인 목록 (확장 배지 이름 조회) */
    installedPlugins?: ExtensionInfo[];
    /** 추가 클래스명 */
    className?: string;
    /** DOM id 속성 (레이아웃 편집기 코어 일괄 ID) */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
}
/**
 * MenuAccessTree 집합 컴포넌트
 *
 * 역할 폼 「접근 가능 메뉴」 — 관리자 메뉴 트리를 체크박스로 고른다. 메뉴는 부모도 할당 대상이라
 * PermissionTree(리프만 할당) 모델로는 표현할 수 없어 전용 컴포넌트로 둔다.
 *
 * 부모·자식 규칙(서버와 같다): 자식을 체크하면 부모도 체크되고, 부모를 해제하면 자식도 해제된다.
 * 자식 메뉴는 부모를 거쳐서만 사이드바에 나오므로 따로 두면 보이지 않는 할당이 되기 때문이다.
 * 부모 체크박스는 부모와 자식 전체를 함께 토글하고, 자식이 일부만 골라져 있으면 indeterminate 로 보인다.
 *
 * 트리에 없는 ID(액터가 관리할 수 없는 메뉴)가 value 에 있으면 건드리지 않고 그대로 돌려준다.
 *
 * @example
 * <menu-access-tree data="{{role_menus_tree?.data?.data ?? []}}" value="{{_local.form.menu_ids ?? []}}">
 *   <on type="change"><set-state target="local"><field name="form"><field name="menu_ids" value="{{$event.target.value}}"/></field></set-state></on>
 * </menu-access-tree>
 */
export declare const MenuAccessTree: React.FC<MenuAccessTreeProps>;
