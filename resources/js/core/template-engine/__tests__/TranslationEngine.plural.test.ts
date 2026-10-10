// e2e:allow 번역 엔진 단위 규칙 잠금 — 화면 검증은 이력 문서의 브라우저 실측(게시판 목록 en 「1 post」)으로 한다.
/**
 * TranslationEngine 복수형 선택 회귀 테스트 (P20, engine-v1.78.7)
 *
 * 영어 화면에서 「1 posts」처럼 수에 맞지 않는 문구가 나오던 문제. 번역 엔진이 `count` 인자의 수로
 * 사전 로케일의 복수 범주(Intl.PluralRules)를 구해 `키_one` 같은 변형을 먼저 고르고, 없으면 기본 키
 * (= other 꼴)를 쓴다.
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { TranslationEngine, type TranslationContext } from '../TranslationEngine';

const T = 'sirsoft-basic';

describe('TranslationEngine 복수형 선택', () => {
  let engine: TranslationEngine;
  const en: TranslationContext = { templateId: T, locale: 'en' };
  const ko: TranslationContext = { templateId: T, locale: 'ko' };
  const ja: TranslationContext = { templateId: T, locale: 'ja' };

  beforeEach(() => {
    engine = new TranslationEngine({ defaultLocale: 'ko', fallbackLocale: 'en' });
    engine.setTranslationValue(T, 'en', 'board.post_count', '{{count}} posts');
    engine.setTranslationValue(T, 'en', 'board.post_count_one', '{{count}} post');
    engine.setTranslationValue(T, 'ko', 'board.post_count', '{{count}}개');
    engine.setTranslationValue(T, 'ko', 'board.post_count_one', '쓰이면 안 되는 값');
  });

  // @scenario count_form=numeric
  // @effects one_category_selects_one_variant
  it('en: count=1 이면 _one 변형을 고른다', () => {
    expect(engine.translate('board.post_count', en, '|count=1')).toBe('1 post');
  });

  // @scenario count_form=numeric
  // @effects other_category_uses_base_key
  it('en: count=0·2·1000 은 기본 키(other 꼴)', () => {
    expect(engine.translate('board.post_count', en, '|count=0')).toBe('0 posts');
    expect(engine.translate('board.post_count', en, '|count=2')).toBe('2 posts');
    expect(engine.translate('board.post_count', en, '|count=1000')).toBe('1000 posts');
  });

  // @scenario count_form=numeric
  // @effects one_category_selects_one_variant
  it('en: 데이터 바인딩으로 들어온 수도 범주를 고른다', () => {
    const out = engine.resolveTranslations('$t:board.post_count|count={{board.posts_count}}', en, {
      board: { posts_count: 1 },
    });
    expect(out).toBe('1 post');
  });

  // @scenario count_form=non_numeric
  // @effects non_numeric_count_uses_base_key
  it('en: 천 단위 구분 기호가 든 수(1,000)는 other', () => {
    expect(engine.translate('board.post_count', en, '|count=1,000')).toBe('1,000 posts');
  });

  // @scenario count_form=non_numeric
  // @effects non_numeric_count_uses_base_key
  it('en: count 가 수가 아니면 기본 키', () => {
    expect(engine.translate('board.post_count', en, '|count=many')).toBe('many posts');
    // 값이 비어 풀린 바인딩('' — Number('') 는 0 이지만 수로 보지 않는다)
    expect(engine.translate('board.post_count', en, '|count={{missing}}', {})).toBe(' posts');
  });

  // @scenario count_form=absent
  // @effects other_category_uses_base_key
  it('count 인자가 없으면 _one 이 있어도 기본 키', () => {
    expect(engine.translate('board.post_count', en)).toBe('{{count}} posts');
    expect(engine.translate('board.post_count', en, '|n=1')).toBe('{{count}} posts');
  });

  // @scenario count_form=numeric
  // @effects category_follows_dictionary_locale
  it('ko: 복수 범주가 other 뿐이라 1 이어도 기본 키', () => {
    expect(engine.translate('board.post_count', ko, '|count=1')).toBe('1개');
  });

  // @scenario count_form=numeric
  // @effects category_follows_dictionary_locale
  it('폴백 사전은 그 사전 로케일(en)의 범주로 고른다 — ja 사전에 키가 없을 때', () => {
    engine.setTranslationValue(T, 'ja', 'board.other_key', 'x');
    expect(engine.translate('board.post_count', ja, '|count=1')).toBe('1 post');
    expect(engine.translate('board.post_count', ja, '|count=4')).toBe('4 posts');
  });

  // @scenario count_form=numeric
  // @effects category_follows_dictionary_locale
  it('현재 로케일에 변형만 없으면 그 로케일 기본 키를 쓴다(폴백 사전 변형으로 넘어가지 않는다)', () => {
    engine.setTranslationValue(T, 'ja', 'board.post_count', '{{count}}件');
    expect(engine.translate('board.post_count', ja, '|count=1')).toBe('1件');
  });

  // @scenario count_form=numeric
  // @effects other_variant_key_is_not_looked_up
  it('_other 는 찾지 않는다 — 기본 키가 other 꼴', () => {
    engine.setTranslationValue(T, 'en', 'board.post_count_other', '쓰이면 안 되는 값');
    expect(engine.translate('board.post_count', en, '|count=5')).toBe('5 posts');
  });

  // @scenario count_form=numeric
  // @effects one_category_selects_one_variant
  it('few·many 범주 로케일(ru)도 같은 규칙으로 변형을 고른다', () => {
    const ru: TranslationContext = { templateId: T, locale: 'ru' };
    engine.setTranslationValue(T, 'ru', 'c', '{{count}} файлов');
    engine.setTranslationValue(T, 'ru', 'c_one', '{{count}} файл');
    engine.setTranslationValue(T, 'ru', 'c_few', '{{count}} файла');
    expect(engine.translate('c', ru, '|count=1')).toBe('1 файл');
    expect(engine.translate('c', ru, '|count=3')).toBe('3 файла');
    expect(engine.translate('c', ru, '|count=5')).toBe('5 файлов');
  });

  // @scenario count_form=numeric
  // @effects one_category_selects_one_variant
  it('여러 인자와 함께 써도 count 로 고른다', () => {
    engine.setTranslationValue(T, 'en', 'x.sel', '{{count}} roles selected in {{name}}');
    engine.setTranslationValue(T, 'en', 'x.sel_one', '{{count}} role selected in {{name}}');
    expect(engine.translate('x.sel', en, '|name=Admin&count=1')).toBe('1 role selected in Admin');
  });

  // @scenario count_form=numeric
  // @effects one_category_selects_one_variant
  it('단일 중괄호 자리표시({count})도 같은 규칙', () => {
    engine.setTranslationValue(T, 'en', 'x.jobs', '{count} jobs queued');
    engine.setTranslationValue(T, 'en', 'x.jobs_one', '{count} job queued');
    expect(engine.translate('x.jobs', en, '|count=1')).toBe('1 job queued');
  });
});
