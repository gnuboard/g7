/**
 * shop-soldout-computed.test.ts — 상품상세 옵션 품절 표시 (computed 인라인 방식)
 *
 * MP07 §2: 재고 없는 옵션을 옵션 드롭다운에서 "(품절)" 비활성으로 표시한다. $call 헬퍼
 * 없이, show.json 의 `computed.optionChoices`(순수 인라인 배열식)가 백엔드 is_sold_out
 * 플래그(ProductOptionResource)를 읽어 그룹별 선택지를 만들고, _purchase_card.json 의
 * 옵션 select 가 `$computed.optionChoices?.[groupIndex]` 로 참조한다.
 *
 * 본 테스트는 (1) show.json 의 computed 표현식 정본을 실제 DataBindingEngine 으로 평가해
 * 품절 판정(보수적: 호환 조합 전부 품절일 때만 disabled)이 맞는지, (2) _purchase_card.json
 * 의 select options 표현식이 $computed.optionChoices 를 참조해 그 결과를 펼치는지 — 를
 * 실제 표현식으로 검증한다(레이아웃 JSON 정본과 동일 문자열 사용 — 회귀 가드).
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { DataBindingEngine } from '@/core/template-engine/DataBindingEngine';
import showLayout from '../../../layouts/shop/show.json';
import purchaseCard from '../../../layouts/partials/shop/detail/_purchase_card.json';

// 레이아웃 정본에서 표현식을 직접 추출 — 정본이 바뀌면 테스트도 따라가 회귀를 잡는다.
const COMPUTED_EXPR: string = (showLayout as any).computed.optionChoices.replace(/^\{\{|\}\}$/g, '');

// _purchase_card.json 의 옵션 select options 표현식 (iteration 안의 Select)
function findOptionsExpr(node: any): string | null {
  if (node && typeof node === 'object') {
    if (node.name === 'Select' && node.props?.options) return node.props.options;
    for (const k of Object.keys(node)) {
      const v = (node as any)[k];
      if (Array.isArray(v)) {
        for (const c of v) {
          const r = findOptionsExpr(c);
          if (r) return r;
        }
      } else if (v && typeof v === 'object') {
        const r = findOptionsExpr(v);
        if (r) return r;
      }
    }
  }
  return null;
}
const OPTIONS_EXPR: string = (findOptionsExpr(purchaseCard) ?? '').replace(/^\{\{|\}\}$/g, '');

const colorGroup = { name_localized: '색상', values_localized: ['빨강', '파랑'] };
const sizeGroup = { name_localized: '사이즈', values_localized: ['S', 'L'] };

function opt(localized: Record<string, string>, soldOut: boolean): any {
  return {
    option_values_localized: localized,
    stock_quantity: soldOut ? 0 : 10,
    is_active: true,
    is_sold_out: soldOut,
  };
}

describe('상품상세 옵션 품절 표시 — computed 인라인 방식', () => {
  let engine: DataBindingEngine;
  beforeEach(() => {
    engine = new DataBindingEngine();
  });

  function evalComputed(option_groups: any[], options: any[], currentSelection: Record<string, string> = {}) {
    return engine.evaluateExpression(COMPUTED_EXPR, {
      product: { data: { option_groups, options } },
      _local: { currentSelection },
    } as any) as any[];
  }

  it('show.json computed: 단일 그룹 — 재고0 옵션 값이 disabled + "(품절)" 라벨', () => {
    const choices = evalComputed([colorGroup], [
      opt({ 색상: '빨강' }, false),
      opt({ 색상: '파랑' }, true),
    ]);
    // choices[0] = 색상 그룹 선택지
    const red = choices[0].find((c: any) => c.value === '빨강');
    const blue = choices[0].find((c: any) => c.value === '파랑');
    expect(red.disabled).toBe(false);
    expect(blue.disabled).toBe(true);
    // 품절 값은 라벨에 접미가 붙는다(원본 값보다 길다). 테스트 환경엔 $t 사전이 없어
    // sold_out_suffix 키가 그대로 반환되므로 "품절" 문자열 자체가 아니라 접미 부착을 검증한다.
    expect(blue.label).not.toBe('파랑');
    expect(blue.label.startsWith('파랑 ')).toBe(true);
    expect(red.label).toBe('빨강'); // 정상 값은 접미 없음
  });

  it('show.json computed: 다중 그룹 보수적 판정 — 일부만 품절이면 선택 가능, 전부 품절이면 비활성', () => {
    // 빨강-S(품절) 빨강-L(정상) 파랑-S(품절) 파랑-L(품절)
    const options = [
      opt({ 색상: '빨강', 사이즈: 'S' }, true),
      opt({ 색상: '빨강', 사이즈: 'L' }, false),
      opt({ 색상: '파랑', 사이즈: 'S' }, true),
      opt({ 색상: '파랑', 사이즈: 'L' }, true),
    ];
    const choices = evalComputed([colorGroup, sizeGroup], options);
    const colorChoices = choices[0];
    expect(colorChoices.find((c: any) => c.value === '빨강').disabled).toBe(false); // 빨강-L 정상
    expect(colorChoices.find((c: any) => c.value === '파랑').disabled).toBe(true); // 파랑 전부 품절
  });

  it('show.json computed: 하위 그룹 — 상위 선택(빨강) 호환 조합 기준 S 품절/L 정상', () => {
    const options = [
      opt({ 색상: '빨강', 사이즈: 'S' }, true),
      opt({ 색상: '빨강', 사이즈: 'L' }, false),
      opt({ 색상: '파랑', 사이즈: 'S' }, false),
      opt({ 색상: '파랑', 사이즈: 'L' }, false),
    ];
    const choices = evalComputed([colorGroup, sizeGroup], options, { 색상: '빨강' });
    const sizeChoices = choices[1];
    expect(sizeChoices.find((c: any) => c.value === 'S').disabled).toBe(true);
    expect(sizeChoices.find((c: any) => c.value === 'L').disabled).toBe(false);
  });

  it('_purchase_card.json select options: $computed.optionChoices 를 참조해 품절 결과를 펼친다', () => {
    // computed 결과를 미리 만들어 $computed 로 주입
    const optionChoices = evalComputed([colorGroup], [
      opt({ 색상: '빨강' }, false),
      opt({ 색상: '파랑' }, true),
    ]);
    const result = engine.evaluateExpression(OPTIONS_EXPR, {
      group: colorGroup,
      groupIndex: 0,
      product: { data: { option_groups: [colorGroup] } },
      _local: { currentSelection: {} },
      _computed: { optionChoices },
    } as any) as any[];
    // [0]=placeholder, 이후 색상 선택지(품절 반영)
    expect(result[0].value).toBe('');
    expect(result.find((c) => c.value === '파랑')?.disabled).toBe(true);
    expect(result.find((c) => c.value === '빨강')?.disabled).toBe(false);
  });

  it('_purchase_card.json select options: computed 부재 시 평문 폴백(품절 표시 없이 깨지지 않음)', () => {
    const result = engine.evaluateExpression(OPTIONS_EXPR, {
      group: colorGroup,
      groupIndex: 0,
      product: { data: { option_groups: [colorGroup] } },
      _local: { currentSelection: {} },
      _computed: {},
    } as any) as any[];
    expect(result.find((c) => c.value === '빨강')).toBeDefined();
    expect(result.find((c) => c.value === '빨강')?.disabled).toBeUndefined();
  });
});
