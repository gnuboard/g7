/**
 * 브랜드 관련 핸들러
 *
 * 상품 등록/수정 화면에서 브랜드 정보 조회 기능을 처리합니다.
 */

import type { ActionContext } from '../types';

interface Brand {
    id: number;
    name: Record<string, string>;
    description?: Record<string, string>;
}

interface ActionWithParams {
    handler: string;
    params?: Record<string, any>;
    [key: string]: any;
}

/**
 * 브랜드 ID로 브랜드명을 반환합니다.
 *
 * @param action 액션 객체 (params.brandId, params.brands 필요)
 * @param _context 액션 컨텍스트
 * @returns 브랜드명 문자열
 */
export function getBrandNameHandler(
    action: ActionWithParams,
    _context: ActionContext
): string {
    const params = action.params || {};
    const brandId = params.brandId as number;
    const brands = params.brands as Brand[];

    if (!brandId || !brands || !Array.isArray(brands)) {
        return '';
    }

    const G7Core = (window as any).G7Core;
    const locale = G7Core?.locale?.current?.() || 'ko';

    const brand = brands.find((b) => b.id === brandId);
    return brand ? (brand.name?.[locale] ?? brand.name?.ko ?? '') : '';
}

/**
 * 브랜드 ID로 브랜드 설명을 반환합니다.
 *
 * @param action 액션 객체 (params.brandId, params.brands 필요)
 * @param _context 액션 컨텍스트
 * @returns 브랜드 설명 문자열
 */
export function getBrandDescriptionHandler(
    action: ActionWithParams,
    _context: ActionContext
): string {
    const params = action.params || {};
    const brandId = params.brandId as number;
    const brands = params.brands as Brand[];

    if (!brandId || !brands || !Array.isArray(brands)) {
        return '';
    }

    const G7Core = (window as any).G7Core;
    const locale = G7Core?.locale?.current?.() || 'ko';

    const brand = brands.find((b) => b.id === brandId);
    return brand?.description?.[locale] ?? brand?.description?.ko ?? '';
}
