/**
 * thumb 파이프 인자가 따옴표 안에서도 리터럴 낱말로 바뀌는 엔진 제약 — 썸네일 규격 이름 예약의 근거 시험
 *
 * 파이프 인자 해석(`parsePipeExpression` → `parseArgValue`)은 따옴표를 벗긴 뒤 `true`, `false`, `null`, `undefined` 를
 * 불리언, null, undefined 로 바꾼다. 봇 화면 짝(`App\Seo\PipeRegistry::parseArgValue`)도 같다. 그래서 규격 이름이 이 네 낱말이면
 * `thumb('true')` 는 규격 대신 원본을, `thumb('undefined')` 는 기본 규격 sm 을 낸다. 코어는 이 네 이름을 규격 이름으로
 * 받지 않는다(`ThumbnailPresetRegistry::LITERAL_NAMES`, 저장 검증 422). 이 시험은 그 예약이 왜 필요한지를 잠근다 —
 * 파이프 해석이 따옴표 문자열을 문자열로 남기도록 바뀌면 이 시험이 깨지고, 그때 예약을 풀지 다시 판단한다.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { DataBindingEngine } from '../DataBindingEngine';
import { parsePipeExpression, pipeRegistry } from '../PipeRegistry';

describe('thumb 파이프와 리터럴 낱말 규격 이름', () => {
  let engine: DataBindingEngine;

  beforeEach(() => {
    engine = new DataBindingEngine();
    pipeRegistry.clearCustomPipes();
    (window as any).G7Config = {
      settings: {
        upload: {
          thumbnail_presets_public: [
            { name: 'sm', width: 160, height: 160, fit: 'cover', numeric: false },
            { name: 'banner', width: 1000, height: 300, fit: 'cover', numeric: false },
          ],
          thumbnail_url_patterns: ['/api/attachment/*'],
        },
      },
    };
  });

  afterEach(() => {
    delete (window as any).G7Config;
  });

  /**
   * @scenario layer=pipe_parse
   * @effects pipe_quoted_literal_word_loses_string_type
   */
  it.each([
    ['true', true],
    ['false', false],
    ['null', null],
    ['undefined', undefined],
  ])("따옴표로 감싼 '%s' 도 문자열이 아닌 값으로 넘어간다", (word, expected) => {
    const parsed = parsePipeExpression(`thumb('${word}')`);

    expect(parsed.name).toBe('thumb');
    expect(parsed.args).toHaveLength(1);
    expect(parsed.args[0]).toBe(expected);
  });

  /**
   * @scenario layer=pipe_parse
   * @effects literal_word_preset_name_never_reaches_size_query
   */
  it.each([
    ['true', '/api/attachment/abc'],
    ['false', '/api/attachment/abc'],
    ['null', '/api/attachment/abc'],
    ['undefined', '/api/attachment/abc?size=sm'],
  ])("thumb('%s') 는 size=%s 를 붙이지 못한다", (word, expected) => {
    const context = { product: { thumbnail_url: '/api/attachment/abc' } };

    expect(engine.resolveBindings(`{{product.thumbnail_url | thumb('${word}')}}`, context)).toBe(expected);
  });

  /**
   * @scenario layer=pipe_parse
   * @effects literal_word_preset_name_never_reaches_size_query
   */
  it('리터럴 낱말이 아닌 운영자 규격 이름은 그대로 size 로 붙는다', () => {
    const context = { product: { thumbnail_url: '/api/attachment/abc' } };

    expect(engine.resolveBindings("{{product.thumbnail_url | thumb('banner')}}", context)).toBe(
      '/api/attachment/abc?size=banner',
    );
  });
});
