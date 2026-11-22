/**
 * MobileBuyBar 컴포넌트
 *
 * 좁은 화면(768px 미만) 아래에 고정되는 구매바. 상품 상세에서는 가격과 찜·장바구니·바로구매를,
 * 장바구니에서는 결제 금액과 주문하기를 늘 손 닿는 자리에 둔다. 넓은 화면에서는 숨는다(본문의 단추가 보인다).
 * 좁은지 넓은지는 창 폭(CSS 미디어)이 아니라 엔진 폭(편집기 미리보기에서는 기기 폭)으로 가른다.
 *
 * 단추가 무엇을 하는지는 이 컴포넌트가 알지 못한다 — 레이아웃이 이벤트(`onWish`·`onCart`·`onBuy`·`onPrimary`)에
 * 본문 단추와 같은 동작을 건다.
 *
 * 바가 본문 끝(푸터 마지막 줄)을 가리지 않도록, 좁은 화면에서 보이는 동안 `body` 에 `g7-has-mobile-buybar` 를 남긴다.
 * 스타일시트가 그 표시를 보고 본문 아래 여백을 바 높이만큼 더한다(표시가 곧 「바가 보인다」라 스타일시트는 폭을 보지 않는다).
 */

import React, { useEffect } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import type { EditorAttrs } from '../../types';
import { BREAKPOINTS, useViewportWidth } from '../layout/viewportWidth';

export interface MobileBuyBarProps {
  /** 바 종류 — 상품 상세(product) · 장바구니(cart) */
  variant?: 'product' | 'cart';
  /** 보여 줄 금액 (형식을 갖춘 글자) */
  priceText?: string;
  /** 금액 옆 보조 글자 (수량 등) */
  captionText?: string;
  /** 찜 여부 (상품 바) */
  wished?: boolean;
  /** 찜 단추 표시 여부 (상품 바, 기본 true) */
  showWish?: boolean;
  /** 장바구니 단추 잠금 (상품 바) */
  cartDisabled?: boolean;
  /** 바로구매 단추 잠금 (상품 바) */
  buyDisabled?: boolean;
  /** 주 단추 글자 (장바구니 바) */
  primaryLabel?: string;
  /** 주 단추 잠금 (장바구니 바) */
  primaryDisabled?: boolean;
  /** 진행 중 — 모든 단추를 잠그고 주 단추에 스피너를 보인다 */
  busy?: boolean;
  /** 판매 중이 아닐 때의 상태 글자 (상품 바) — 주면 장바구니·바로구매 대신 이 글자를 보인다 */
  soldOutLabel?: string;
  /** 찜 단추를 눌렀을 때 */
  onWish?: () => void;
  /** 장바구니 단추를 눌렀을 때 */
  onCart?: () => void;
  /** 바로구매 단추를 눌렀을 때 */
  onBuy?: () => void;
  /** 주 단추를 눌렀을 때 (장바구니 바) */
  onPrimary?: () => void;
  /** 추가 CSS 클래스 */
  className?: string;
  /** DOM id 속성 (레이아웃 편집기 코어 일괄 ID) */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
}

const BODY_CLASS = 'g7-has-mobile-buybar';

/** 보이는(좁은 화면) 바의 수 — 마지막 바가 사라지거나 숨을 때만 body 표시를 지운다 */
let mountedBars = 0;

const t = (key: string): string => (window as any).G7Core?.t?.(key) ?? key;

/** 좁은 화면 — 아래에 고정 */
const ROOT_NARROW =
  'fixed inset-x-0 bottom-0 z-30 pb-[env(safe-area-inset-bottom)] bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 shadow-[0_-2px_8px_rgba(0,0,0,0.06)]';
/** 넓은 화면(768px 이상) — 숨는다 */
const ROOT_WIDE =
  'hidden pb-[env(safe-area-inset-bottom)] bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 shadow-[0_-2px_8px_rgba(0,0,0,0.06)]';

const SECONDARY_BUTTON =
  'flex-1 min-w-0 h-11 px-3 inline-flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-sm font-medium text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed';
const PRIMARY_BUTTON =
  'flex-1 min-w-0 h-11 px-3 inline-flex items-center justify-center gap-1.5 rounded-lg text-sm font-medium bg-gray-900 dark:bg-white text-white dark:text-gray-900 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed';

/**
 * 좁은 화면 하단 구매바
 *
 * @example
 * ```html
 * <mobile-buy-bar data-type="composite" variant="product" priceText="{{…}}" wished="{{…}}">
 *   <on event="onCart"><action-ref name="purchaseAddToCart"/></on>
 * </mobile-buy-bar>
 * ```
 */
export const MobileBuyBar: React.FC<MobileBuyBarProps> = ({
  variant = 'product',
  priceText = '',
  captionText,
  wished = false,
  showWish = true,
  cartDisabled = false,
  buyDisabled = false,
  primaryLabel,
  primaryDisabled = false,
  busy = false,
  soldOutLabel,
  onWish,
  onCart,
  onBuy,
  onPrimary,
  className = '',
  id,
  editorAttrs,
}) => {
  const isNarrow = useViewportWidth() < BREAKPOINTS.md;

  useEffect(() => {
    // 넓은 화면에서는 바가 숨으므로 본문 여백도 더하지 않는다
    if (!isNarrow) return undefined;
    mountedBars += 1;
    document.body.classList.add(BODY_CLASS);
    return () => {
      mountedBars = Math.max(0, mountedBars - 1);
      if (mountedBars === 0) document.body.classList.remove(BODY_CLASS);
    };
  }, [isNarrow]);

  const isProduct = variant !== 'cart';
  const notOnSale = isProduct && !!soldOutLabel;

  return (
    <Div
      data-mobile-buy-bar=""
      id={id}
      className={`${isNarrow ? ROOT_NARROW : ROOT_WIDE} ${className}`.trim()}
      {...(editorAttrs as Record<string, unknown>)}
    >
      <Div className="flex items-center gap-2 px-4 py-2.5">
        {isProduct && showWish && (
          <Button
            type="button"
            data-buy-bar-action="wish"
            aria-label={t(wished ? 'shop.product.remove_from_wishlist' : 'shop.product.add_to_wishlist')}
            aria-pressed={wished}
            onClick={() => onWish?.()}
            className="shrink-0 w-11 h-11 inline-flex items-center justify-center rounded-lg border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 bg-white dark:bg-gray-800 cursor-pointer"
          >
            <Icon name="heart" iconStyle={wished ? 'solid' : 'regular'} className={wished ? 'text-red-500 dark:text-red-400' : ''} />
          </Button>
        )}

        {/* 금액 — 글자가 길어도 단추를 밀어내지 않게 줄인다 */}
        <Div className="min-w-0 shrink flex flex-col justify-center">
          <Span className="truncate text-base font-bold text-gray-900 dark:text-white">{priceText}</Span>
          {captionText && <Span className="truncate text-xs text-muted">{captionText}</Span>}
        </Div>

        <Div className="flex flex-1 min-w-0 items-center justify-end gap-2">
          {notOnSale && (
            <Span data-buy-bar-status="" className="text-sm font-medium text-muted">
              {soldOutLabel}
            </Span>
          )}

          {isProduct && !notOnSale && (
            <>
              <Button type="button" data-buy-bar-action="cart" disabled={busy || cartDisabled} onClick={() => onCart?.()} className={SECONDARY_BUTTON}>
                <Icon name="shopping-cart" />
                <Span className="truncate">{t('shop.product.add_to_cart')}</Span>
              </Button>
              <Button type="button" data-buy-bar-action="buy" disabled={busy || buyDisabled} onClick={() => onBuy?.()} className={PRIMARY_BUTTON}>
                {busy && <Icon name="spinner" className="animate-spin" />}
                <Span className="truncate">{t('shop.product.buy_now')}</Span>
              </Button>
            </>
          )}

          {!isProduct && (
            <Button type="button" data-buy-bar-action="primary" disabled={busy || primaryDisabled} onClick={() => onPrimary?.()} className={PRIMARY_BUTTON}>
              {busy && <Icon name="spinner" className="animate-spin" />}
              <Span className="truncate">{primaryLabel}</Span>
            </Button>
          )}
        </Div>
      </Div>
    </Div>
  );
};

export default MobileBuyBar;
