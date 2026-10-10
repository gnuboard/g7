// e2e:allow 사전 단언 — 화면 검증은 en 설정 화면 실측(R-A 결과 문서)으로 한다.
/**
 * NHN KCP en 사전의 단수·복수 정리 (7.1.0 R-A — P20 후속)
 *
 * 설정 화면 점검 배지 「{{count}} error(s)」를 기본 키 = 복수형·`_one` = 단수형으로 나눈다.
 * 레이아웃은 이미 `|count=` 로 넘기므로 번역 엔진(engine-v1.78.7)이 1 이면 단수형을 고른다.
 */
import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const en = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../../lang/en.json'), 'utf-8'));

describe('NHN KCP en 사전 — 단수·복수', () => {
  // @scenario count_form=numeric
  // @effects dictionary_has_singular_variant
  it('점검 배지 문구가 복수형 기본 키와 단수형 변형으로 나뉜다', () => {
    expect(en.settings.health.error_count).toBe('{{count}} errors');
    expect(en.settings.health.error_count_one).toBe('{{count}} error');
    expect(en.settings.health.warning_count).toBe('{{count}} warnings');
    expect(en.settings.health.warning_count_one).toBe('{{count}} warning');
  });

  // @scenario count_form=numeric
  // @effects dictionary_has_singular_variant
  it('`(s)` 표기가 없다', () => {
    expect(JSON.stringify(en)).not.toMatch(/[A-Za-z]\((?:s|es)\)/);
  });
});
