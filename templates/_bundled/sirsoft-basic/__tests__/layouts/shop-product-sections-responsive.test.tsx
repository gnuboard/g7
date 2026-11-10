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
 * - 카드 너비: 기본(모바일) 2개/줄, responsive 640-1023 구간 3개/줄, desktop 구간 4개/줄
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
    { name: '인기상품', layout: popularProducts },
    { name: '신상품', layout: newProducts },
    { name: '최근본상품', layout: recentProducts },
  ];

  /**
   * 기본 disabled 식에서 «세는 대상» 표현식을 뽑아낸다.
   * 데이터소스 이름을 테스트에 박아 두면 바인딩 경로가 바뀔 때마다 stale 이 되므로,
   * 브레이크포인트 오버라이드가 기본값과 «같은 컬렉션» 을 보는지만 확인한다.
   */
  function countedCollection(nextBtn: any): string {
    const match = /\(_isolated\.scrollIdx \+ 4\) >= \((.*)\.length\)/.exec(nextBtn.props.disabled);
    expect(match, `기본 disabled 식에서 대상 컬렉션을 찾지 못함: ${nextBtn.props.disabled}`).not.toBeNull();
    return match![1];
  }

  describe.each(partials)('$name 섹션', ({ layout }) => {
    describe('grid 기반 카드 크기 반응형', () => {
      // @scenario part=card_grid
      // @effects scroll_container_is_grid_flow_col
      it('컨테이너가 grid grid-flow-col을 사용해야 한다', () => {
        const container = findScrollContainer(layout);
        expect(container).not.toBeNull();
        expect(container.props.className).toContain('grid');
        expect(container.props.className).toContain('grid-flow-col');
      });

      // @scenario part=card_grid
      // @effects card_columns_follow_responsive_ranges
      it('모바일 auto-cols 50%, 640-1023 33.333%, 데스크톱 25%가 responsive 구간으로 적용되어야 한다', () => {
        const container = findScrollContainer(layout);
        const tokens = (cls: unknown): string[] => (typeof cls === 'string' ? cls.split(/\s+/) : []);
        // 화면 폭 분기는 Tailwind 반응형 접두 대신 엔진 responsive 로 적는다
        expect(container.props.className).not.toMatch(/(^|\s)(sm|md|lg|xl|2xl):/);
        expect(tokens(container.props.className)).toContain('auto-cols-[calc(50%_-_8px)]');
        expect(tokens(container.responsive?.['640-1023']?.props?.className)).toContain('auto-cols-[calc(33.333%_-_11px)]');
        expect(tokens(container.responsive?.desktop?.props?.className)).toContain('auto-cols-[calc(25%_-_12px)]');
        // 구간 className 은 기본값을 통째로 바꾸므로 그리드 골격도 함께 들고 있어야 한다
        for (const key of ['640-1023', 'desktop']) {
          expect(tokens(container.responsive?.[key]?.props?.className)).toEqual(expect.arrayContaining(['grid', 'grid-flow-col']));
        }
      });

      // @scenario part=card_grid
      // @effects card_has_no_own_width
      it('카드에 별도 너비 클래스가 없어야 한다 (grid가 제어)', () => {
        const card = findIterationCard(layout);
        expect(card.props.className ?? '').not.toContain('w-[');
        expect(card.props.className ?? '').not.toContain('flex-none');
      });
    });

    describe('다음 버튼 responsive 오버라이드', () => {
      // @scenario part=next_button
      // @effects next_button_threshold_per_range
      it('모바일(0-639)에서 +2 기준 disabled 조건이 있어야 한다', () => {
        const nextBtn = findNextButton(layout);
        expect(nextBtn).not.toBeNull();
        expect(nextBtn.responsive['0-639'].props.disabled).toContain('+ 2');
      });

      // @scenario part=next_button
      // @effects next_button_threshold_per_range
      it('태블릿(640-1023)에서 +3 기준 disabled 조건이 있어야 한다', () => {
        const nextBtn = findNextButton(layout);
        expect(nextBtn.responsive['640-1023'].props.disabled).toContain('+ 3');
      });

      // @scenario part=next_button
      // @effects next_button_threshold_per_range
      it('데스크톱 기본값은 +4 기준이어야 한다', () => {
        const nextBtn = findNextButton(layout);
        expect(nextBtn.props.disabled).toContain('+ 4');
        expect(nextBtn.props.className).toContain('+ 4');
      });

      // @scenario part=next_button
      // @effects next_button_counts_same_collection
      it('모바일 disabled 가 기본값과 같은 컬렉션을 세야 한다', () => {
        const nextBtn = findNextButton(layout);
        expect(nextBtn.responsive['0-639'].props.disabled).toContain(countedCollection(nextBtn));
      });

      // @scenario part=next_button
      // @effects next_button_counts_same_collection
      it('태블릿 disabled 가 기본값과 같은 컬렉션을 세야 한다', () => {
        const nextBtn = findNextButton(layout);
        expect(nextBtn.responsive['640-1023'].props.disabled).toContain(countedCollection(nextBtn));
      });
    });
  });

  describe('공통 구조 검증', () => {
    // @scenario part=section_shell
    // @effects section_hides_overflow_and_empty_state
    it.each(partials)('$name: 컨테이너가 overflow-x-hidden이어야 한다', ({ layout }) => {
      const container = findScrollContainer(layout);
      expect(container).not.toBeNull();
      expect(container.props.className).toContain('overflow-x-hidden');
    });

    // @scenario part=section_shell
    // @effects next_button_threshold_per_range
    it.each(partials)('$name: isolatedState에 scrollIdx가 정의되어야 한다', ({ layout }) => {
      expect(layout.isolatedState).toBeDefined();
      expect(layout.isolatedState.scrollIdx).toBe(0);
    });

    // @scenario part=section_shell
    // @effects section_hides_overflow_and_empty_state
    it.each(partials)('$name: if 조건으로 데이터 없을 때 비노출 처리되어야 한다', ({ layout }) => {
      expect(layout.if).toBeDefined();
      expect(layout.if).toContain('.length > 0');
    });
  });
});
