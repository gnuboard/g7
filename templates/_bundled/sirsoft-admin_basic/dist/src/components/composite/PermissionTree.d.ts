import { default as React } from 'react';
import { ExtensionInfo } from './ExtensionBadge';
/**
 * 권한 값 인터페이스 (id + scope_type)
 */
export interface PermissionValue {
    id: number;
    scope_type: string | null;
}
/**
 * 스코프 옵션 인터페이스
 */
export interface ScopeOption {
    value: string | null;
    label: string;
}
/**
 * 권한 노드 인터페이스
 */
export interface PermissionNode {
    id: number;
    identifier: string;
    name: string;
    description?: string;
    module?: string;
    extension_type?: string | null;
    extension_identifier?: string | null;
    is_assignable: boolean;
    leaf_count: number;
    resource_route_key?: string | null;
    owner_key?: string | null;
    children: PermissionNode[];
}
export interface PermissionTreeProps {
    /** 권한 트리 데이터 */
    data?: PermissionNode[];
    /** 선택된 권한 값 배열 [{id, scope_type}] */
    value?: PermissionValue[];
    /** 선택 변경 콜백 */
    onChange?: (e: {
        target: {
            value: PermissionValue[];
        };
    }) => void;
    /** 스코프 옵션 (드롭다운 선택지) */
    scopeOptions?: ScopeOption[];
    /** 추가 클래스명 */
    className?: string;
    /** 비활성화 여부 */
    disabled?: boolean;
    /** PC에서 권한 목록 열 수 */
    desktopColumns?: 1 | 2 | 3;
    /** 설치된 모듈 목록 (_global.installedModules에서 전달) */
    installedModules?: ExtensionInfo[];
    /** 설치된 플러그인 목록 (_global.installedPlugins에서 전달) */
    installedPlugins?: ExtensionInfo[];
}
/**
 * PermissionTree 집합 컴포넌트
 *
 * 계층형 권한 트리를 렌더링하고 체크박스로 권한을 선택할 수 있습니다.
 * 각 권한에 스코프 타입(전체/소유역할/본인만)을 설정할 수 있는 드롭다운을 제공합니다.
 * 내부적으로 Accordion 컴포넌트를 재사용합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "PermissionTree",
 *   "props": {
 *     "data": "{{permissions?.data?.data?.permissions?.[permType]?.permissions ?? []}}",
 *     "value": "{{_local.form.permission_values ?? []}}",
 *     "scopeOptions": "{{permissions?.data?.data?.scope_options ?? []}}"
 *   },
 *   "actions": [
 *     {
 *       "type": "change",
 *       "handler": "setState",
 *       "params": {
 *         "target": "local",
 *         "form": {
 *           "permission_values": "{{$event.target.value}}"
 *         }
 *       }
 *     }
 *   ]
 * }
 */
export declare const PermissionTree: React.FC<PermissionTreeProps>;
