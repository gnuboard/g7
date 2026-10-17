// e2e:allow 엔진 식 도우미 단위 규칙 잠금 — 화면 검증은 #757 이름 단계 E2E(행 이름 「3번째 행 판매가」)가 한다.
/**
 * 식 안 `$t(키, 인자)` 회귀 테스트 (#757, engine-v1.80.1)
 *
 * 레이아웃 반복 행에서 「{N}번째 행」 같은 번역 문구를 식 안에서 만들 수 없었다. 식 도우미 `$t` 는 키 하나만
 * 받아 `{{$t('common.row_n', {n: 3})}}` 가 오류 없이 평가되면서 인자를 버리고 「{{n}}번째 행」을 냈다.
 * `$t(키, 인자)` 는 인자 객체를 그대로 번역 엔진에 넘기고, `G7Core.t`·`useTranslation().t` 도 같은 경로를 쓴다.
 *
 * 인자를 `|키=값` 문자열로 이어 붙이면 값에 든 `|`·`&`·`=` 가 구분자로, `{{…}}` 가 식으로, 끝의 ` /` 가
 * 문장 꼬리로 읽혀 값이 잘리거나 사라진다. 치환 단계에서는 값의 `$&`·`$'` 가 바꿈 패턴으로, 다른 인자의
 * 자리표시자(`{{column}}`)가 다음 치환 대상으로 읽혔다. 행 이름은 운영자가 입력한 상품명·역할명이라
 * 이런 글자가 들어올 수 있다.
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render } from '@testing-library/react';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, type TranslationContext } from '../TranslationEngine';
import { TranslationProvider, useTranslation } from '../TranslationContext';

const T = 'sirsoft-admin_basic';
const ko: TranslationContext = { templateId: T, locale: 'ko' };
const en: TranslationContext = { templateId: T, locale: 'en' };
const ja: TranslationContext = { templateId: T, locale: 'ja' };

/** 엔진 싱글톤 사전을 채운다 — 식 도우미 `$t` 는 싱글톤을 쓴다 */
function seedDictionaries(engine: TranslationEngine): void {
  engine.setTranslationValue(T, 'ko', 'common.row_n', '{{n}}번째 행');
  engine.setTranslationValue(T, 'en', 'common.row_n', 'Row {{n}}');
  engine.setTranslationValue(T, 'ja', 'common.row_n', '{{n}}行目');
  engine.setTranslationValue(T, 'ko', 'common.row_cell', '{{row}} {{column}}');
  engine.setTranslationValue(T, 'en', 'common.row_cell', '{{row}} {{column}}');
  engine.setTranslationValue(T, 'ko', 'common.select_item', '{{name}} 선택');
  engine.setTranslationValue(T, 'ko', 'common.confirm', '확인');
  engine.setTranslationValue(T, 'ko', 'product.price', '판매가');
  // 폴백(en) 사전에만 있는 키
  engine.setTranslationValue(T, 'en', 'common.only_en', 'Only {{name}}');
  // 복수형(engine-v1.78.7)
  engine.setTranslationValue(T, 'en', 'board.post_count', '{{count}} posts');
  engine.setTranslationValue(T, 'en', 'board.post_count_one', '{{count}} post');
}

describe('식 도우미 $t(키, 인자) — DataBindingEngine', () => {
  let binding: DataBindingEngine;
  let engine: TranslationEngine;
  const ctx = (locale = 'ko', extra: Record<string, unknown> = {}) => ({ $templateId: T, $locale: locale, ...extra });

  beforeEach(() => {
    TranslationEngine.resetInstance();
    engine = TranslationEngine.getInstance();
    seedDictionaries(engine);
    binding = new DataBindingEngine();
  });

  afterEach(() => {
    TranslationEngine.resetInstance();
  });

  // @scenario arg_shape=key_only, value_chars=plain
  // @effects single_argument_unchanged
  it('인자 하나 호출은 종전과 글자 그대로 같다', () => {
    expect(binding.evaluateExpression("$t('common.confirm')", ctx())).toBe(engine.translate('common.confirm', ko));
    expect(binding.evaluateExpression("$t('common.confirm')", ctx())).toBe('확인');
    // 인자 자리에 객체가 아닌 값(undefined·null·글자·배열)이 오면 인자 없는 호출과 같다
    expect(binding.evaluateExpression("$t('common.row_n', undefined)", ctx())).toBe('{{n}}번째 행');
    expect(binding.evaluateExpression("$t('common.row_n', null)", ctx())).toBe('{{n}}번째 행');
    expect(binding.evaluateExpression("$t('common.row_n', 'n=3')", ctx())).toBe('{{n}}번째 행');
    expect(binding.evaluateExpression("$t('common.row_n', [3])", ctx())).toBe('{{n}}번째 행');
    // 빈 객체는 인자 없는 호출과 같다
    expect(binding.evaluateExpression("$t('common.confirm', {})", ctx())).toBe('확인');
  });

  // @scenario arg_shape=object, value_chars=plain
  // @effects params_substituted_per_locale
  it('인자 객체를 치환한다 — ko·en·ja 어순', () => {
    expect(binding.evaluateExpression("$t('common.row_n', {n: 3})", ctx('ko'))).toBe('3번째 행');
    expect(binding.evaluateExpression("$t('common.row_n', {n: 3})", ctx('en'))).toBe('Row 3');
    expect(binding.evaluateExpression("$t('common.row_n', {n: 3})", ctx('ja'))).toBe('3行目');
    // 식 값(인덱스 + 1)
    expect(binding.evaluateExpression("$t('common.row_n', {n: idx + 1})", ctx('ko', { idx: 4 }))).toBe('5번째 행');
  });

  // @scenario arg_shape=object, value_chars=plain
  // @effects params_substituted_per_locale
  it('{{ }} 바인딩 안에서 쓴다 — 행 이름이 비면 번호 대체', () => {
    const tpl = "{{row.name || $t('common.row_n', {n: index + 1})}}";
    expect(binding.resolveBindings(tpl, ctx('ko', { row: { name: '' }, index: 2 }))).toBe('3번째 행');
    expect(binding.resolveBindings(tpl, ctx('ko', { row: { name: '기본 티셔츠' }, index: 2 }))).toBe('기본 티셔츠');
  });

  // @scenario arg_shape=object, value_chars=plain
  // @effects nested_in_pipe_param
  it('`$t:키|인자={{…}}` 의 인자 식 안에서 쓴다(레이아웃 R3 형식)', () => {
    const attr = "$t:common.row_cell|row={{row.name || $t('common.row_n', {n: index + 1})}}|column={{$t('product.price')}}";
    const data = { row: { name: '' }, index: 0, $templateId: T, $locale: 'ko' };
    expect(engine.resolveTranslations(attr, ko, data)).toBe('1번째 행 판매가');
    expect(engine.resolveTranslations(attr, ko, { ...data, row: { name: '기본 티셔츠' } })).toBe('기본 티셔츠 판매가');
  });

  // @scenario arg_shape=object, value_chars=separators
  // @effects separator_chars_in_value_kept
  it('값에 `|`·`&`·`=`·끝 ` /` 가 들어도 값이 잘리지 않는다', () => {
    const value = 'A|b=c&d /';
    expect(binding.evaluateExpression('$t(\'common.select_item\', {name: v})', ctx('ko', { v: value }))).toBe(`${value} 선택`);
  });

  // @scenario arg_shape=object, value_chars=replacement_tokens
  // @effects value_inserted_literally
  it('값의 `$&`·`$\'`·`{{x}}`·다른 인자 자리표시자를 글자 그대로 넣는다', () => {
    expect(binding.evaluateExpression("$t('common.select_item', {name: v})", ctx('ko', { v: "$& $' $1" }))).toBe("$& $' $1 선택");
    expect(binding.evaluateExpression("$t('common.select_item', {name: v})", ctx('ko', { v: '{{x}}' }))).toBe('{{x}} 선택');
    // row 값에 다른 인자(column)의 자리표시자가 들어도 그 자리에 column 값이 들어가지 않는다
    expect(
      binding.evaluateExpression("$t('common.row_cell', {row: r, column: '판매가'})", ctx('ko', { r: '{{column}}' })),
    ).toBe('{{column}} 판매가');
  });

  // @scenario arg_shape=object, value_chars=plain
  // @effects missing_key_falls_back
  it('없는 키는 키를 그대로, 현재 로케일에 없으면 폴백 사전 문구에 치환한다', () => {
    expect(binding.evaluateExpression("$t('nope.key', {n: 1})", ctx('ko'))).toBe('nope.key');
    expect(binding.evaluateExpression("$t('common.only_en', {name: 'X'})", ctx('ko'))).toBe('Only X');
    // 값이 비면 빈 글자(문자열 경로 `{{undefined}}` 와 같다)
    expect(binding.evaluateExpression("$t('common.select_item', {name: missing})", ctx('ko'))).toBe(' 선택');
  });

  // @scenario arg_shape=object, value_chars=plain
  // @effects plural_count_from_object
  it('count 는 복수형 선택에 쓴다(수·숫자 글자)', () => {
    expect(binding.evaluateExpression("$t('board.post_count', {count: 1})", ctx('en'))).toBe('1 post');
    expect(binding.evaluateExpression("$t('board.post_count', {count: 2})", ctx('en'))).toBe('2 posts');
    expect(binding.evaluateExpression("$t('board.post_count', {count: '1'})", ctx('en'))).toBe('1 post');
  });
});

describe('번역 엔진 인자 객체 경로 — TranslationEngine.translate', () => {
  let engine: TranslationEngine;

  beforeEach(() => {
    engine = new TranslationEngine({ defaultLocale: 'ko', fallbackLocale: 'en' });
    seedDictionaries(engine);
  });

  // @scenario arg_shape=object, value_chars=separators
  // @effects separator_chars_in_value_kept
  it('세 번째 인자로 객체를 받으면 문자열로 풀지 않고 그대로 치환한다', () => {
    expect(engine.translate('common.select_item', ko, { name: 'Tom & Jerry|x=1' })).toBe('Tom & Jerry|x=1 선택');
    expect(engine.translate('common.row_n', en, { n: 7 })).toBe('Row 7');
    expect(engine.translate('common.row_n', ja, { n: 7 })).toBe('7行目');
  });

  // @scenario arg_shape=pipe_string, value_chars=replacement_tokens
  // @effects value_inserted_literally
  it('`|키=값` 문자열 경로도 값의 `$\'` 를 글자 그대로 넣는다', () => {
    // `&` 는 이 경로의 구분자라 `$'`(바꿈 패턴: 일치 뒤 글자)로 본다
    expect(engine.translate('common.select_item', ko, "|name=$'")).toBe("$' 선택");
    expect(engine.translate('common.row_cell', ko, '|row={{r}}|column=판매가', { r: '{{column}}' })).toBe('{{column}} 판매가');
  });

  // @scenario arg_shape=pipe_string, value_chars=plain
  // @effects single_argument_unchanged
  it('`|키=값` 문자열 경로의 보통 값은 종전과 같다', () => {
    expect(engine.translate('common.row_n', ko, '|n=3')).toBe('3번째 행');
    expect(engine.translate('board.post_count', en, '|count=1')).toBe('1 post');
  });
});

describe('컴포넌트 번역 함수 — useTranslation().t', () => {
  // @scenario arg_shape=object, value_chars=separators
  // @effects component_t_shares_object_path
  it('인자 값에 `&`·`|` 가 들어도 잘리지 않는다', () => {
    const engine = new TranslationEngine({ defaultLocale: 'ko', fallbackLocale: 'en' });
    seedDictionaries(engine);
    let output = '';
    const Probe: React.FC = () => {
      const { t } = useTranslation();
      output = t('common.select_item', { name: 'A&B|C' });
      return null;
    };
    render(
      <TranslationProvider translationEngine={engine} translationContext={ko}>
        <Probe />
      </TranslationProvider>,
    );
    expect(output).toBe('A&B|C 선택');
  });
});
