/**
 * ProductCard 컴포넌트
 *
 * 상품 목록에서 개별 상품을 표시하는 카드 컴포넌트입니다.
 * 다중 통화를 지원하며, 할인율 뱃지를 표시합니다.
 */

import React from 'react';

// @ts-ignore - DOMPurify 타입 정의 없음
import DOMPurify from 'dompurify';

// 기본 컴포넌트 import
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Span } from '../basic/Span';
import { H3 } from '../basic/H3';
import { Img } from '../basic/Img';

// G7Core 전역 상태 훅
const useGlobalState = (key: string) =>
  (window as any).G7Core?.state?.get?.(key);

// G7Core.dispatch() navigate 헬퍼
const navigate = (path: string) => {
  (window as any).G7Core?.dispatch?.({
    handler: 'navigate',
    params: { path },
  });
};

interface CurrencyPrice {
  value: number;
  formatted: string;
}

interface ProductLabel {
  name: string;
  color: string;
}

interface ProductCardProps {
  product: {
    id: number;
    name?: string | Record<string, string>;
    name_localized?: string;
    thumbnail_url: string;
    primary_category?: string;
    selling_price: number;
    selling_price_formatted: string;
    list_price?: number;
    list_price_formatted?: string;
    discount_rate?: number;
    multi_currency_selling_price?: Record<string, CurrencyPrice>;
    multi_currency_list_price?: Record<string, CurrencyPrice>;
    /** 검색 하이라이트된 상품명 (HTML) */
    name_highlighted?: string;
    /** 검색 하이라이트된 설명 (HTML) */
    description_highlighted?: string;
    /** 짧은 설명 */
    short_description?: string;
    /** 판매 상태 (on_sale, sold_out, suspended, coming_soon) */
    sales_status?: string;
    /** 판매 상태 번역 라벨 */
    sales_status_label?: string;
    /** 브랜드명 */
    brand_name?: string;
    /** 상품 라벨/뱃지 목록 */
    labels?: ProductLabel[];
    /** 평균 별점 (0.0 ~ 5.0, 소수점 1자리) */
    rating_avg?: number;
    /** 전시중 리뷰 수 */
    review_count?: number;
  };
  /** 클릭 시 호출되는 콜백 */
  onClick?: (productId: number) => void;
  /** 쇼핑몰 base 경로 (예: '/shop', '/store', '/') */
  shopBase?: string;
  /** 추가 CSS 클래스 */
  className?: string;
}

/**
 * 상품 카드 컴포넌트
 *
 * @example
 * ```tsx
 * <ProductCard
 *   product={{
 *     id: 1,
 *     name: "프리미엄 울 코트",
 *     thumbnail_url: "/images/product1.jpg",
 *     primary_category: "의류",
 *     selling_price: 189000,
 *     selling_price_formatted: "189,000원",
 *     list_price: 259000,
 *     list_price_formatted: "259,000원",
 *     discount_rate: 27,
 *     multi_currency_selling_price: {
 *       KRW: { value: 189000, formatted: "189,000원" },
 *       USD: { value: 159, formatted: "$159.00" }
 *     }
 *   }}
 * />
 * ```
 *
 * @example
 * ```json
 * // 레이아웃 JSON에서 사용
 * {
 *   "type": "composite",
 *   "name": "ProductCard",
 *   "props": { "product": "{{product}}" }
 * }
 * ```
 */
/**
 * rating_avg(0~5)를 0.5 단위로 반올림하여 별 아이콘 배열을 반환합니다.
 * 반환값: 5개 원소, 각각 'full' | 'half' | 'empty'
 */
const getStarTypes = (avg: number): Array<'full' | 'half' | 'empty'> => {
  const rounded = Math.round(avg * 2) / 2; // 0.5 단위 반올림
  return [1, 2, 3, 4, 5].map((pos) => {
    if (rounded >= pos) return 'full';
    if (rounded >= pos - 0.5) return 'half';
    return 'empty';
  });
};

const ProductCard: React.FC<ProductCardProps> = ({
  product,
  onClick,
  shopBase = '/shop',
  className = '',
}) => {
  const preferredCurrency = useGlobalState('preferredCurrency') || 'KRW';

  /**
   * 다중 통화 가격을 가져옵니다.
   * 선호 통화가 없으면 기본 포맷 가격을 반환합니다.
   */
  const getPrice = (field: 'selling_price' | 'list_price'): string | undefined => {
    const multiCurrencyField = `multi_currency_${field}` as keyof typeof product;
    const multiCurrencyData = product[multiCurrencyField] as Record<string, CurrencyPrice> | undefined;

    if (multiCurrencyData && multiCurrencyData[preferredCurrency]) {
      return multiCurrencyData[preferredCurrency].formatted;
    }

    const formattedField = `${field}_formatted` as keyof typeof product;
    return product[formattedField] as string | undefined;
  };

  const displayName = product.name_localized
    ?? (typeof product.name === 'string' ? product.name : '')
    ?? '';
  const sellingPrice = getPrice('selling_price');
  const listPrice = getPrice('list_price');
  const hasDiscount = product.discount_rate && product.discount_rate > 0;
  const isNotOnSale = product.sales_status && product.sales_status !== 'on_sale';
  const labels = product.labels ?? [];

  /**
   * HTML 하이라이트가 있으면 sanitize하여 반환합니다.
   * mark 태그만 허용하여 XSS를 방지합니다.
   */
  const sanitizeHighlight = (html: string): string => {
    return DOMPurify.sanitize(html, {
      ALLOWED_TAGS: ['mark'],
      ALLOWED_ATTR: [],
    }) as unknown as string;
  };

  const nameHighlighted = product.name_highlighted;
  const descriptionHtml = product.description_highlighted ?? product.short_description;

  const handleClick = () => {
    if (onClick) {
      onClick(product.id);
    } else {
      const base = shopBase === '/' ? '' : shopBase;
      navigate(`${base}/products/${product.id}`);
    }
  };

  return (
    <Button
      onClick={handleClick}
      className={`block w-full text-left group bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-lg transition-shadow cursor-pointer ${className}`}
    >
      {/* 이미지 영역 */}
      <Div className="relative aspect-square overflow-hidden bg-gray-100 dark:bg-gray-700">
        {/* 상품 이미지 */}
        <Img
          src={product.thumbnail_url}
          alt={displayName}
          className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-200"
          loading="lazy"
        />

        {/* 판매 상태 오버레이 */}
        {isNotOnSale && (
          <Div className="absolute inset-0 bg-black/50 flex items-center justify-center">
            <Span className={`text-lg font-bold ${
              product.sales_status === 'sold_out' ? 'text-red-300' :
              product.sales_status === 'suspended' ? 'text-amber-300' :
              'text-blue-300'
            }`} style={{ textShadow: '0 1px 4px rgba(0,0,0,0.6)' }}>
              {product.sales_status_label ?? product.sales_status}
            </Span>
          </Div>
        )}

        {/* 할인율 뱃지 (좌상단) - 품절/판매중단 시 숨김 */}
        {hasDiscount && product.sales_status !== 'sold_out' && product.sales_status !== 'suspended' && (
          <Span className="absolute top-2 left-2 px-2 py-1 bg-red-500 text-white text-xs font-bold rounded">
            {product.discount_rate}%
          </Span>
        )}

        {/* 상품 라벨/뱃지 (우하단) */}
        {labels.length > 0 && (
          <Div className="absolute bottom-2 right-2 flex gap-1 flex-wrap justify-end">
            {labels.map((label, idx) => (
              <Span
                key={idx}
                className="text-xs text-white font-medium px-1.5 py-0.5 rounded"
                style={{ backgroundColor: label.color || '#6b7280' }}
              >
                {label.name}
              </Span>
            ))}
          </Div>
        )}
      </Div>

      {/* 상품 정보 */}
      <Div className="p-4">
        {/* 카테고리 및 브랜드 */}
        <Div className="flex items-center gap-1.5 flex-wrap">
          {product.primary_category && (
            <Span className="inline-block text-xs font-medium text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/30 px-2 py-0.5 rounded">
              {product.primary_category}
            </Span>
          )}
          {product.brand_name && (
            <Span className="text-xs text-gray-500 dark:text-gray-400">
              {product.brand_name}
            </Span>
          )}
        </Div>

        {/* 상품명 */}
        {nameHighlighted ? (
          <Div
            className="mt-1 font-medium text-gray-900 dark:text-white line-clamp-1 [&_mark]:bg-yellow-200 [&_mark]:dark:bg-yellow-500/40 [&_mark]:px-0.5 [&_mark]:rounded"
            dangerouslySetInnerHTML={{ __html: sanitizeHighlight(nameHighlighted) }}
          />
        ) : (
          <H3 className="mt-1 font-medium text-gray-900 dark:text-white line-clamp-1">
            {displayName}
          </H3>
        )}

        {/* 상품 설명 (검색 결과 등에서 표시) */}
        {descriptionHtml && (
          <Div
            className="mt-1 text-sm text-gray-600 dark:text-gray-400 line-clamp-2 [&_mark]:bg-yellow-200 [&_mark]:dark:bg-yellow-500/40 [&_mark]:px-0.5 [&_mark]:rounded"
            dangerouslySetInnerHTML={{ __html: sanitizeHighlight(descriptionHtml) }}
          />
        )}

        {/* 별점 영역 */}
        <Div className="mt-1.5 flex items-center gap-1">
          {getStarTypes(product.rating_avg ?? 0).map((type, idx) => (
            <Span
              key={idx}
              className={`text-xs ${type === 'empty' ? 'text-gray-300 dark:text-gray-600' : 'text-yellow-400'}`}
            >
              {type === 'full' && <i className="fa-solid fa-star" />}
              {type === 'half' && <i className="fa-solid fa-star-half-stroke" />}
              {type === 'empty' && <i className="fa-regular fa-star" />}
            </Span>
          ))}
          <Span className="text-xs text-gray-600 dark:text-gray-400 ml-0.5">
            {(product.rating_avg ?? 0).toFixed(1)}
          </Span>
          <Span className="text-xs text-gray-400 dark:text-gray-500">
            ({product.review_count ?? 0})
          </Span>
        </Div>

        {/* 가격 영역 */}
        <Div className="mt-2 flex items-baseline gap-2">
          <Span className="font-bold text-gray-900 dark:text-white">
            {sellingPrice}
          </Span>
          {listPrice && hasDiscount && (
            <Span className="text-sm text-gray-500 dark:text-gray-400 line-through">
              {listPrice}
            </Span>
          )}
        </Div>
      </Div>
    </Button>
  );
};

export default ProductCard;
