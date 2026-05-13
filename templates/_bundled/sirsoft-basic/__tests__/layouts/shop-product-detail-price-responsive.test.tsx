/**
 * @file shop-product-detail-price-responsive.test.tsx
 * @description 상품 상세 페이지 가격 표시 반응형 테스트
 *
 * 테스트 대상:
 * - templates/.../layouts/shop/show.json (상품 상세 메인 레이아웃)
 * - templates/.../partials/shop/detail/_price_mobile.json (모바일 전용 가격)
 * - templates/.../partials/shop/detail/_info_summary.json (가격 섹션 - 데스크톱 전용)
 *
 * 검증 항목:
 * - 모바일 가격(_price_mobile.json)은 모바일에서만 표시
 * - 정보 요약 가격(_info_summary.json)은 데스크톱에서만 표시
 * - 가격이 모바일에서 중복 표시되지 않음
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

// ========== JSON 파일 로드 ==========

const layoutsDir = path.resolve(__dirname, '../../layouts');

function loadLayout(relativePath: string) {
  const filePath = path.join(layoutsDir, relativePath);
  return JSON.parse(fs.readFileSync(filePath, 'utf-8'));
}

const showLayout = loadLayout('shop/show.json');
const priceMobile = loadLayout('partials/shop/detail/_price_mobile.json');
const infoSummary = loadLayout('partials/shop/detail/_info_summary.json');

// ========== 헬퍼 함수 ==========

/**
 * 중첩된 children에서 comment로 노드를 찾는다
 */
function findByComment(layout: any, commentSubstring: string): any {
  if (layout.comment?.includes(commentSubstring)) return layout;
  if (layout.children) {
    for (const child of layout.children) {
      const found = findByComment(child, commentSubstring);
      if (found) return found;
    }
  }
  if (layout.slots) {
    for (const slotChildren of Object.values(layout.slots)) {
      for (const child of slotChildren as any[]) {
        const found = findByComment(child, commentSubstring);
        if (found) return found;
      }
    }
  }
  return null;
}

// ========== 테스트 ==========

describe('상품 상세 페이지 가격 표시 반응형', () => {
  describe('모바일 전용 가격 (show.json → _price_mobile.json)', () => {
    it('모바일 전용 가격 컨테이너가 존재해야 한다', () => {
      const mobilePriceWrapper = findByComment(showLayout, '모바일 전용: 가격 표시');
      expect(mobilePriceWrapper).not.toBeNull();
    });

    it('기본 if가 true(모바일 표시)이어야 한다', () => {
      const mobilePriceWrapper = findByComment(showLayout, '모바일 전용: 가격 표시');
      expect(mobilePriceWrapper.if).toBe('{{true}}');
    });

    it('desktop responsive에서 if가 false(데스크톱 숨김)이어야 한다', () => {
      const mobilePriceWrapper = findByComment(showLayout, '모바일 전용: 가격 표시');
      expect(mobilePriceWrapper.responsive?.desktop?.if).toBe('{{false}}');
    });
  });

  describe('정보 요약 가격 (_info_summary.json)', () => {
    it('가격 섹션이 존재해야 한다', () => {
      const priceSection = findByComment(infoSummary, '가격 섹션');
      expect(priceSection).not.toBeNull();
    });

    it('기본 if가 false(모바일 숨김)이어야 한다', () => {
      const priceSection = findByComment(infoSummary, '가격 섹션');
      expect(priceSection.if).toBe('{{false}}');
    });

    it('desktop responsive에서 if가 true(데스크톱 표시)이어야 한다', () => {
      const priceSection = findByComment(infoSummary, '가격 섹션');
      expect(priceSection.responsive?.desktop?.if).toBe('{{true}}');
    });
  });

  describe('가격 중복 방지 구조 검증', () => {
    it('모바일 가격과 정보 요약 가격의 responsive 조건이 상호 배타적이어야 한다', () => {
      const mobilePriceWrapper = findByComment(showLayout, '모바일 전용: 가격 표시');
      const priceSection = findByComment(infoSummary, '가격 섹션');

      // 모바일 가격: 기본 표시, 데스크톱 숨김
      expect(mobilePriceWrapper.if).toBe('{{true}}');
      expect(mobilePriceWrapper.responsive?.desktop?.if).toBe('{{false}}');

      // 정보 요약 가격: 기본 숨김, 데스크톱 표시
      expect(priceSection.if).toBe('{{false}}');
      expect(priceSection.responsive?.desktop?.if).toBe('{{true}}');
    });

    it('_price_mobile.json에 할인율 표시 요소가 있어야 한다', () => {
      expect(priceMobile.children).toBeDefined();
      expect(priceMobile.children.length).toBeGreaterThan(0);

      // 할인율 표시 요소 존재 확인
      const discountSection = findByComment(priceMobile, '할인율 + 판매가');
      expect(discountSection).not.toBeNull();
      expect(discountSection.if).toContain('discount_rate');

      // 할인율 텍스트에 % 포함
      const discountRate = discountSection.children.find(
        (child: any) => child.text?.includes('discount_rate')
      );
      expect(discountRate).toBeDefined();
      expect(discountRate.text).toContain('%');
    });

    it('_price_mobile.json에 판매가 표시 요소가 있어야 한다', () => {
      // 할인 시 판매가
      const discountSection = findByComment(priceMobile, '할인율 + 판매가');
      const sellingPrice = discountSection.children.find(
        (child: any) => child.text?.includes('selling_price')
      );
      expect(sellingPrice).toBeDefined();

      // 비할인 시 판매가
      const noDiscountSection = findByComment(priceMobile, '판매가 (할인 없을 때)');
      expect(noDiscountSection).not.toBeNull();
      expect(noDiscountSection.text).toContain('selling_price');
    });

    it('_info_summary.json 가격 섹션에 할인율/판매가 표시 요소가 있어야 한다', () => {
      const priceSection = findByComment(infoSummary, '가격 섹션');
      expect(priceSection.children).toBeDefined();
      expect(priceSection.children.length).toBeGreaterThan(0);

      // 할인 관련 요소 존재 확인
      const discountElement = findByComment(priceSection, '할인');
      expect(discountElement).not.toBeNull();
    });
  });
});
