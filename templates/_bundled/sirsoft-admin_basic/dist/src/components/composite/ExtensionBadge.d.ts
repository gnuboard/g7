import { default as React } from 'react';
export type ExtensionType = 'module' | 'plugin';
/**
 * 설치된 모듈/플러그인 정보 인터페이스
 */
export interface ExtensionInfo {
    identifier: string;
    name: string;
    description?: string;
    version?: string;
    status?: string;
}
export interface ExtensionBadgeProps {
    /** 확장 타입 (module: 모듈, plugin: 플러그인) */
    type: ExtensionType;
    /** 확장 식별자 (예: sirsoft-ecommerce) - 이 값으로 이름을 자동 조회 */
    identifier?: string;
    /** 확장 이름 (직접 지정 시 사용, identifier보다 우선) */
    name?: string;
    /** 설치된 모듈 목록 (_global.installedModules에서 전달) */
    installedModules?: ExtensionInfo[];
    /** 설치된 플러그인 목록 (_global.installedPlugins에서 전달) */
    installedPlugins?: ExtensionInfo[];
    /** 추가 클래스명 */
    className?: string;
    /** 인라인 스타일 */
    style?: React.CSSProperties;
}
/**
 * ExtensionBadge 집합 컴포넌트
 *
 * 모듈 또는 플러그인에 의해 추가된 섹션임을 표시하는 배지 컴포넌트입니다.
 * 카드 또는 섹션의 우측 상단에 배치하여 확장 출처를 표시합니다.
 *
 * identifier를 전달하면 installedModules/installedPlugins에서 이름을 자동으로 조회합니다.
 *
 * 기본 컴포넌트 조합: Span + Icon
 *
 * @example
 * // 레이아웃 JSON 사용 예시 (identifier로 이름 자동 조회)
 * {
 *   "name": "ExtensionBadge",
 *   "props": {
 *     "type": "module",
 *     "identifier": "sirsoft-ecommerce",
 *     "installedModules": "{{_global.installedModules}}"
 *   }
 * }
 *
 * @example
 * // 이름 직접 지정
 * {
 *   "name": "ExtensionBadge",
 *   "props": {
 *     "type": "plugin",
 *     "name": "결제"
 *   }
 * }
 */
export declare const ExtensionBadge: React.FC<ExtensionBadgeProps>;
