/**
 * @file responsiveClass.ts
 * @description 레이아웃 노드의 폭 구간 실효 className 을 엔진 규칙으로 계산하는 시험 도우미 (#399, engine-v1.98.0).
 *
 * 폭 구간은 `responsive.{key}.props.className`(통째 교체) 대신 `responsive.{key}.classMerge`(기본 className 위에 덧붙임,
 * 같은 축 유틸은 교체)로 적을 수 있다. 화면에 실제로 붙는 값은 엔진 `applyResponsiveClassMerge(구간 className ?? 기본 className, classMerge)` 다.
 */

import { applyResponsiveClassMerge } from '@core/template-engine/helpers/StyleHelpers';

type ResponsiveNode = {
    props?: { className?: unknown };
    responsive?: Record<string, { props?: { className?: unknown }; classMerge?: string } | undefined>;
};

/**
 * 폭 구간의 실효 className. 구간이 없으면 빈 문자열.
 *
 * @param node 레이아웃 노드
 * @param key responsive 구간 키(`mobile`, `portable`, `0-767` 등)
 * @returns 실효 className
 */
export function rangeClassName(node: ResponsiveNode | null | undefined, key: string): string {
    const range = node?.responsive?.[key];
    if (!range) return '';
    const own = typeof range.props?.className === 'string' ? range.props.className : undefined;
    const base = typeof node?.props?.className === 'string' ? node.props.className : undefined;
    return applyResponsiveClassMerge(own ?? base, range.classMerge);
}
