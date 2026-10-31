// e2e:allow 사전·번역 엔진 단언 — 화면 검증은 en 편집기 실측(R-A 결과 문서)으로 한다.
/**
 * 코어 편집기 en 사전의 단수·복수 정리 (7.1.0 R-A — P20 후속)
 *
 * 번역 엔진은 `count` 인자가 수이면 en 사전의 `키_one` 을 고른다(engine-v1.78.7). 「{count} unsaved edit(s)」
 * 같은 `(s)` 표기는 기본 키 = 복수형·`_one` = 단수형으로 나누고, `{count} lines`·`({diff} chars)` 처럼 수 뒤에
 * 복수 명사가 오는 문구는 `_one` 을 둔다. 문자 수 차이(`+1`·`-1`)는 부호가 붙은 글자라 수가 아니므로
 * 버전 기록 창이 절댓값을 `count` 로도 넘긴다.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { TranslationEngine } from '../../../TranslationEngine';

const repoRoot = resolve(__dirname, '../../../../../../..');
const load = (rel: string) => JSON.parse(readFileSync(resolve(repoRoot, rel), 'utf-8'));

function flat(o: Record<string, unknown>, p = '', out: Record<string, string> = {}): Record<string, string> {
  for (const [k, v] of Object.entries(o)) {
    const kp = p ? `${p}.${k}` : k;
    if (v && typeof v === 'object') flat(v as Record<string, unknown>, kp, out);
    else if (typeof v === 'string') out[kp] = v;
  }
  return out;
}

const en = flat(load('lang/partial/en/layout_editor.json'), 'layout_editor');
const NOT_NOUN = /^(is|was|has|does|this|its|us|as|yes|plus|status|always|across|less|address|access|success|process|progress|news|thus|previous|ago|remains|exceeds|matches|requires|contains|includes|uses|selected)$/i;
const COUNT_THEN_PLURAL = /\{\{?\s*count\s*\}?\}\s+(?:[A-Za-z-]+\s+){0,2}?([A-Za-z]+s)\b/g;

describe('코어 편집기 en 사전 — 단수·복수', () => {
  // @scenario count_form=numeric
  // @effects dictionary_has_singular_variant
  it('`(s)` 표기가 없다', () => {
    expect(Object.entries(en).filter(([, v]) => /[A-Za-z]\((?:s|es)\)|\(ies\)/.test(v)).map(([k]) => k)).toEqual([]);
  });

  // @scenario count_form=numeric
  // @effects dictionary_has_singular_variant
  it('`{count}` 뒤에 복수 명사가 오는 문구는 `_one` 이 있다', () => {
    const missing: string[] = [];
    for (const [k, v] of Object.entries(en)) {
      if (/_(one|two|few|many|zero)$/.test(k)) continue;
      const nouns = [...v.matchAll(COUNT_THEN_PLURAL)].map((m) => m[1]).filter((n) => !NOT_NOUN.test(n));
      if (nouns.length > 0 && !(`${k}_one` in en)) missing.push(k);
    }
    expect(missing).toEqual([]);
  });

  // @scenario count_form=numeric
  // @effects dictionary_has_singular_variant
  it('실제 사전으로 1 이면 단수형, 2 면 복수형이 나온다', () => {
    const engine = new TranslationEngine({ defaultLocale: 'en', fallbackLocale: 'en' });
    for (const [k, v] of Object.entries(en)) engine.setTranslationValue('core', 'en', k, v);
    const ctx = { templateId: 'core', locale: 'en' };
    expect(engine.translate('layout_editor.custom_assets.discard_confirm', ctx, '|count=1')).toBe('You have 1 unsaved edit. Discard it and continue?');
    expect(engine.translate('layout_editor.custom_assets.discard_confirm', ctx, '|count=2')).toBe('You have 2 unsaved edits. Discard them and continue?');
    expect(engine.translate('layout_editor.version_history.added', ctx, '|count=1')).toBe('+1 line');
    expect(engine.translate('layout_editor.version_history.chars', ctx, '|diff=+1|count=1')).toBe('(+1 char)');
    expect(engine.translate('layout_editor.version_history.chars', ctx, '|diff=-12|count=12')).toBe('(-12 chars)');
  });

  // @scenario count_form=numeric
  // @effects call_site_passes_count
  it('버전 기록 창이 문자 수 차이의 절댓값을 count 로 넘긴다', () => {
    const src = readFileSync(resolve(__dirname, '../../components/VersionHistoryModal.tsx'), 'utf-8');
    expect(src).toContain('count: Math.abs(charDiff)');
  });
});
