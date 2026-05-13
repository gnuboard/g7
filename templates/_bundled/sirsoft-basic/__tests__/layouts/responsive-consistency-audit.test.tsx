/**
 * @file responsive-consistency-audit.test.tsx
 * @description 모바일/PC 반응형 일관성 검증 테스트
 *
 * 테스트 대상:
 * - 주문 상세 아이템 (_items.json): CSS hidden 대신 if+responsive 사용 검증
 * - 장바구니 요약 (_cart_summary.json): 모바일에서 sticky 제거 검증
 * - 상품 상세 가격 (show.json + _info_summary.json + _price_mobile.json): 가격 중복 방지
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

const orderItems = loadLayout('partials/mypage/orders/_items.json');
const cartSummary = loadLayout('partials/shop/_cart_summary.json');

// ========== 헬퍼 함수 ==========

function findByComment(layout: any, commentSubstring: string): any {
  if (layout.comment?.includes(commentSubstring)) return layout;
  if (layout.children) {
    for (const child of layout.children) {
      const found = findByComment(child, commentSubstring);
      if (found) return found;
    }
  }
  return null;
}

function findAllByComment(layout: any, commentSubstring: string, results: any[] = []): any[] {
  if (layout.comment?.includes(commentSubstring)) results.push(layout);
  if (layout.children) {
    for (const child of layout.children) {
      findAllByComment(child, commentSubstring, results);
    }
  }
  return results;
}

/**
 * CSS "hidden" 클래스를 사용하여 표시/숨김 토글하는 노드를 찾는다
 * (responsive 내 className에 "hidden"이 포함되거나, 기본 className이 "hidden"인 경우)
 */
function findCssHiddenToggle(layout: any, results: any[] = []): any[] {
  // 기본 className이 "hidden"이고 responsive로 다른 클래스로 전환하는 경우
  if (layout.props?.className === 'hidden' && layout.responsive) {
    results.push(layout);
  }
  // responsive 내에서 className을 "hidden"으로 설정하는 경우
  if (layout.responsive) {
    for (const [breakpoint, config] of Object.entries(layout.responsive as Record<string, any>)) {
      if (config?.props?.className === 'hidden') {
        results.push(layout);
        break;
      }
    }
  }
  if (layout.children) {
    for (const child of layout.children) {
      findCssHiddenToggle(child, results);
    }
  }
  return results;
}

// ========== 테스트 ==========

describe('Finding 2: 주문 상세 아이템 - 이중 렌더링 해소', () => {
  describe('모바일 전용 블록 (if+responsive.desktop 패턴)', () => {
    it('모바일 상태 뱃지+배송정보 블록이 if 기반 조건부 렌더링을 사용해야 한다', () => {
      const mobileStatus = findByComment(orderItems, '모바일: 상태 뱃지 + 배송정보');
      expect(mobileStatus).not.toBeNull();
      expect(mobileStatus.if).toBe('{{true}}');
      expect(mobileStatus.responsive?.desktop?.if).toBe('{{false}}');
    });

    it('모바일 구매확정/리뷰 버튼 블록이 if 기반 조건부 렌더링을 사용해야 한다', () => {
      const mobileButtons = findByComment(orderItems, '모바일: 구매확정/리뷰 버튼');
      expect(mobileButtons).not.toBeNull();
      expect(mobileButtons.if).toBe('{{true}}');
      expect(mobileButtons.responsive?.desktop?.if).toBe('{{false}}');
    });

    it('모바일 블록에 CSS hidden 클래스가 없어야 한다', () => {
      const mobileStatus = findByComment(orderItems, '모바일: 상태 뱃지 + 배송정보');
      const mobileButtons = findByComment(orderItems, '모바일: 구매확정/리뷰 버튼');
      expect(mobileStatus.props?.className).not.toBe('hidden');
      expect(mobileButtons.props?.className).not.toBe('hidden');
    });
  });

  describe('PC 전용 블록 (if+responsive.desktop 패턴)', () => {
    const pcOnlyComments = [
      '상태 뱃지 (PC만 표시)',
      '배송사 + 운송장번호 (PC만 표시)',
      '구매확정 버튼 (PC)',
      '리뷰작성 버튼 (PC)',
      '리뷰 작성 완료 뱃지 (PC)'
    ];

    it.each(pcOnlyComments)('%s: if 기반 조건부 렌더링을 사용해야 한다', (comment) => {
      const pcBlock = findByComment(orderItems, comment);
      expect(pcBlock).not.toBeNull();
      // 기본 if가 false (모바일 숨김)
      expect(pcBlock.if).toBe('{{false}}');
      // desktop에서 조건부 표시
      expect(pcBlock.responsive?.desktop?.if).toBeDefined();
      expect(pcBlock.responsive?.desktop?.if).not.toBe('{{false}}');
    });

    it.each(pcOnlyComments)('%s: responsive.portable에 className hidden이 없어야 한다', (comment) => {
      const pcBlock = findByComment(orderItems, comment);
      expect(pcBlock.responsive?.portable?.props?.className).not.toBe('hidden');
    });
  });

  describe('CSS hidden 토글 패턴 부재 검증', () => {
    it('주문 아이템 영역에 CSS hidden 토글 패턴이 없어야 한다', () => {
      // 상품 목록 iteration 영역에서만 검사
      const itemsList = findByComment(orderItems, '상품 목록');
      expect(itemsList).not.toBeNull();
      const hiddenToggles = findCssHiddenToggle(itemsList);
      expect(hiddenToggles).toHaveLength(0);
    });
  });
});

describe('Finding 3: 장바구니 요약 패널 - 모바일 sticky 제거', () => {
  it('기본 className에 sticky top-24가 포함되어야 한다 (PC)', () => {
    expect(cartSummary.props.className).toContain('sticky');
    expect(cartSummary.props.className).toContain('top-24');
  });

  it('portable responsive에서 sticky가 제거되어야 한다 (모바일)', () => {
    expect(cartSummary.responsive?.portable?.props?.className).toBeDefined();
    expect(cartSummary.responsive.portable.props.className).not.toContain('sticky');
    expect(cartSummary.responsive.portable.props.className).not.toContain('top-24');
  });

  it('portable에서 기본 스타일(배경, 라운드, 그림자, 패딩)은 유지되어야 한다', () => {
    const portableClass = cartSummary.responsive.portable.props.className;
    expect(portableClass).toContain('bg-white');
    expect(portableClass).toContain('dark:bg-gray-800');
    expect(portableClass).toContain('rounded-lg');
    expect(portableClass).toContain('shadow');
    expect(portableClass).toContain('p-6');
  });
});
