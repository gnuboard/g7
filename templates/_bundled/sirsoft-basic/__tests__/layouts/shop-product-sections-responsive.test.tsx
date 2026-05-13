/**
 * @file shop-product-sections-responsive.test.tsx
 * @description 인기상품/신상품/최근본상품 섹션 모바일 반응형 테스트
 *
 * 테스트 대상:
 * - templates/.../partials/shop/list/_popular_products.json
 * - templates/.../partials/shop/list/_new_products.json
 * - templates/.../partials/shop/list/_recent_products.json
 *
 * 검증 항목:
 * - 카드 너비: 모바일 2개/줄 + 데스크톱 4개/줄 클래스 공존
 * - 다음 버튼: portable responsive에서 +2 기준 비활성화
 * - responsive 속성 portable 프리셋 사용
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

// ========== JSON 파일 로드 ==========

const partialsDir = path.resolve(__dirname, '../../layouts/partials/shop/list');

function loadPartial(filename: string) {
  const filePath = path.join(partialsDir, filename);
  return JSON.parse(fs.readFileSync(filePath, 'utf-8'));
}

const popularProducts = loadPartial('_popular_products.json');
const newProducts = loadPartial('_new_products.json');
const recentProducts = loadPartial('_recent_products.json');

// ========== 헬퍼 함수 ==========

/**
 * 중첩된 children에서 iteration이 있는 카드 컨테이너를 찾는다
 */
function findIterationCard(layout: any): any {
  if (layout.iteration) return layout;
  if (layout.children) {
    for (const child of layout.children) {
      const found = findIterationCard(child);
      if (found) return found;
    }
  }
  return null;
}

/**
 * 중첩된 children에서 grid 스크롤 컨테이너를 찾는다
 */
function findScrollContainer(layout: any): any {
  if (layout.props?.className?.includes('grid-flow-col')) return layout;
  if (layout.children) {
    for (const child of layout.children) {
      const found = findScrollContainer(child);
      if (found) return found;
    }
  }
  return null;
}

/**
 * 중첩된 children에서 responsive 속성이 있는 Button을 찾는다 (다음 버튼)
 */
function findNextButton(layout: any): any {
  if (layout.name === 'Button' && layout.responsive?.['0-639']) return layout;
  if (layout.children) {
    for (const child of layout.children) {
      const found = findNextButton(child);
      if (found) return found;
    }
  }
  return null;
}

// ========== 테스트 ==========

describe('인기상품/신상품/최근본상품 섹션 모바일 반응형', () => {
  const partials = [
    { name: '인기상품', layout: popularProducts, dataSource: 'popularProducts' },
    { name: '신상품', layout: newProducts, dataSource: 'newProducts' },
    { name: '최근본상품', layout: recentProducts, dataSource: 'recentProducts' },
  ];

  describe.each(partials)('$name 섹션', ({ layout, dataSource }) => {
    describe('grid 기반 카드 크기 반응형', () => {
      it('컨테이너가 grid grid-flow-col을 사용해야 한다', () => {
        const container = findScrollContainer(layout);
        expect(container).not.toBeNull();
        expect(container.props.className).toContain('grid');
        expect(container.props.className).toContain('grid-flow-col');
      });

      it('모바일 auto-cols 50%, sm 33.333%, lg 25%가 적용되어야 한다', () => {
        const container = findScrollContainer(layout);
        expect(container.props.className).toContain('auto-cols-[calc(50%_-_8px)]');
        expect(container.props.className).toContain('sm:auto-cols-[calc(33.333%_-_11px)]');
        expect(container.props.className).toContain('lg:auto-cols-[calc(25%_-_12px)]');
      });

      it('카드에 별도 너비 클래스가 없어야 한다 (grid가 제어)', () => {
        const card = findIterationCard(layout);
        expect(card.props.className ?? '').not.toContain('w-[');
        expect(card.props.className ?? '').not.toContain('flex-none');
      });
    });

    describe('다음 버튼 responsive 오버라이드', () => {
      it('모바일(0-639)에서 +2 기준 disabled 조건이 있어야 한다', () => {
        const nextBtn = findNextButton(layout);
        expect(nextBtn).not.toBeNull();
        expect(nextBtn.responsive['0-639'].props.disabled).toContain('+ 2');
      });

      it('태블릿(640-1023)에서 +3 기준 disabled 조건이 있어야 한다', () => {
        const nextBtn = findNextButton(layout);
        expect(nextBtn.responsive['640-1023'].props.disabled).toContain('+ 3');
      });

      it('데스크톱 기본값은 +4 기준이어야 한다', () => {
        const nextBtn = findNextButton(layout);
        expect(nextBtn.props.disabled).toContain('+ 4');
        expect(nextBtn.props.className).toContain('+ 4');
      });

      it('모바일 disabled에 올바른 데이터소스를 참조해야 한다', () => {
        const nextBtn = findNextButton(layout);
        expect(nextBtn.responsive['0-639'].props.disabled).toContain(`${dataSource}.data.length`);
      });

      it('태블릿 disabled에 올바른 데이터소스를 참조해야 한다', () => {
        const nextBtn = findNextButton(layout);
        expect(nextBtn.responsive['640-1023'].props.disabled).toContain(`${dataSource}.data.length`);
      });
    });
  });

  describe('공통 구조 검증', () => {
    it.each(partials)('$name: 컨테이너가 overflow-x-hidden이어야 한다', ({ layout }) => {
      const container = findScrollContainer(layout);
      expect(container).not.toBeNull();
      expect(container.props.className).toContain('overflow-x-hidden');
    });

    it.each(partials)('$name: isolatedState에 scrollIdx가 정의되어야 한다', ({ layout }) => {
      expect(layout.isolatedState).toBeDefined();
      expect(layout.isolatedState.scrollIdx).toBe(0);
    });

    it.each(partials)('$name: if 조건으로 데이터 없을 때 비노출 처리되어야 한다', ({ layout }) => {
      expect(layout.if).toBeDefined();
      expect(layout.if).toContain('.data');
      expect(layout.if).toContain('.length > 0');
    });
  });
});
