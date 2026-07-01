/**
 * @file shop-additional-option-custom-text.test.tsx
 * @description 추가옵션 직접입력(custom_text) + 목록 요약 레이아웃 구조 검증
 *
 * 검증 대상:
 * - _purchase_card.json: 선택된 선택지가 allow_custom_text 일 때 조건부 Input 노출 + payload custom_text + 필수 가드
 * - _cart_item.json: custom_text 표시 병기 + 모달 시드 additionalCustomTexts
 * - _modal_cart_option_change.json: 직접입력 재선택 Input + PATCH body custom_text
 * - mypage/orders/_list.json: additional_options_summary("외 N건") 노출
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const layoutsDir = path.resolve(__dirname, '../../layouts');
const read = (rel: string) => fs.readFileSync(path.join(layoutsDir, rel), 'utf-8');

const purchaseCard = read('partials/shop/detail/_purchase_card.json');
const cartItem = read('partials/shop/_cart_item.json');
const optionChangeModal = read('partials/shop/_modal_cart_option_change.json');
const mypageList = read('partials/mypage/orders/_list.json');

describe('상품 상세 — 직접입력 조건부 Input + payload', () => {
  it('선택된 선택지의 allow_custom_text 일 때만 Input 을 노출하는 조건부 가드가 있다', () => {
    expect(purchaseCard).toContain('allow_custom_text');
    // 조건부 Input if: find(...).allow_custom_text === true
    expect(purchaseCard).toContain('?.allow_custom_text === true');
  });

  it('직접입력 Input 변경 시 setBlockAdditionalOption 에 customText 를 전달한다', () => {
    expect(purchaseCard).toContain('"customText": "{{$event.target.value}}"');
  });

  it('담기/바로구매 payload 에 custom_text 가 포함된다', () => {
    expect(purchaseCard).toContain('custom_text: item.additionalOptionCustomTexts');
  });

  it('직접입력 미입력 차단 가드는 필수 그룹(is_required)에만 적용된다', () => {
    expect(purchaseCard).toContain('shop.additional_option_custom_text_required');
    // 가드 조건에 g.is_required && ... allow_custom_text 결합 (비필수 그룹은 빈값 허용)
    expect(purchaseCard).toContain('g.is_required && v?.allow_custom_text === true');
  });

  it('직접입력 칸은 라벨 + 입력란 테두리/배경 스타일로 명확히 구분된다', () => {
    // 입력란임을 알리는 라벨
    expect(purchaseCard).toContain('shop.additional_option_custom_text_label');
    // Input 에 테두리/배경 스타일 (placeholder 만 떠서 설명처럼 보이는 결함 회피)
    expect(purchaseCard).toContain('border border-gray-300');
    expect(purchaseCard).toContain('placeholder-gray-400');
  });
});

describe('장바구니 — custom_text 표시 + 모달 시드', () => {
  it('장바구니 추가옵션 표시에 custom_text 를 병기한다', () => {
    expect(cartItem).toContain('addOpt.custom_text');
  });

  it('옵션변경 모달 시드에 additionalCustomTexts 맵을 전달한다', () => {
    expect(cartItem).toContain('additionalCustomTexts');
  });
});

describe('옵션변경 모달 — 직접입력 재선택 + PATCH', () => {
  it('모달에 직접입력 Input(allow_custom_text 조건)이 있다', () => {
    expect(optionChangeModal).toContain('allow_custom_text === true');
    expect(optionChangeModal).toContain('additional_option_custom_text_placeholder');
  });

  it('모달 직접입력 칸도 라벨 + 테두리/배경 스타일로 명확히 구분된다', () => {
    expect(optionChangeModal).toContain('shop.additional_option_custom_text_label');
    expect(optionChangeModal).toContain('border border-gray-300');
  });

  it('PATCH body 에 custom_text 가 포함된다', () => {
    expect(optionChangeModal).toContain('custom_text: $parent._local.additionalCustomTexts');
  });
});

describe('마이페이지 주문리스트 — 추가옵션 요약', () => {
  it('additional_options_summary.label + "외 N건"(extra_count) 을 노출한다 (Q-E2)', () => {
    expect(mypageList).toContain('additional_options_summary?.label');
    expect(mypageList).toContain('additional_options_summary.extra_count');
    expect(mypageList).toContain('외 ');
  });
});
