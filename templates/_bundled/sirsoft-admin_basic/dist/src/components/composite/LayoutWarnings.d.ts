import { default as React } from 'react';
/**
 * 레이아웃 경고 타입
 *
 * 호환성 경고, 시스템 경고 등 다양한 경고 유형을 지원합니다.
 */
export type LayoutWarningType = 'compatibility' | 'deprecation' | 'license' | 'security' | 'system';
/**
 * 레이아웃 경고 레벨
 */
export type LayoutWarningLevel = 'info' | 'warning' | 'error';
/**
 * 레이아웃 경고 인터페이스
 *
 * 백엔드에서 프론트엔드로 전달되는 경고 정보입니다.
 */
export interface LayoutWarning {
    /** 경고 고유 ID (dismiss 처리에 사용) */
    id: string;
    /** 경고 유형 */
    type: LayoutWarningType;
    /** 경고 레벨 */
    level: LayoutWarningLevel;
    /** 사용자에게 표시할 메시지 */
    message: string;
    /** 추가 메타데이터 (경고 유형에 따라 다름) */
    [key: string]: any;
}
/**
 * LayoutWarnings 컴포넌트 Props
 */
export interface LayoutWarningsProps {
    /**
     * 표시할 경고 목록
     */
    warnings?: LayoutWarning[];
    /**
     * 사용자 정의 클래스
     */
    className?: string;
}
/**
 * LayoutWarnings 컴포넌트
 *
 * 레이아웃의 warnings 배열을 받아서 Alert 컴포넌트들을 렌더링합니다.
 * dismiss된 경고는 세션 스토리지에 저장되어 세션 동안 다시 표시되지 않습니다.
 *
 * @example
 * // 레이아웃 JSON에서 사용
 * {
 *   "type": "composite",
 *   "name": "LayoutWarnings",
 *   "props": {
 *     "warnings": "{{_global.layoutWarnings}}"
 *   }
 * }
 */
export declare const LayoutWarnings: React.FC<LayoutWarningsProps>;
