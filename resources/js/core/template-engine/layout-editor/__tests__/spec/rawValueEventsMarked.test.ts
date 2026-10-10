// e2e:allow 번들 역량 스펙 계약 — 화면 검증은 이력 문서의 편집기 [동작] 탭 실측으로 한다.
/**
 * 원시값 onChange 부품 표식 계약 (7.1.0 O-I2F-4, engine-v1.78.7)
 *
 * [동작] 탭은 역량 `rawValueEvents` 에 든 칸의 새 동작을 `event` 표기로 적는다. 표식이 빠지면 그 부품의
 * 「값이 바뀌면」 동작이 저장은 되는데 실행되지 않고, 표식이 잘못 붙으면(마운트·리렌더 때 onChange 를
 * 부르는 부품) 사용자 조작 없이 동작이 실행된다(사례 26).
 *
 *  - 표식 대상: onChange(값) 을 사용자 조작 때만 부르는 부품 — admin SearchableDropdown·TagSelect·IconSelect·
 *    FilterGroup(onChange(필터ID, 값)), basic QuantitySelector·RichTextEditor
 *  - 표식 없음: `{ target: { value } }` 로 부르는 부품(PermissionTree·admin FileInput — type 경로가 실행),
 *    onChange 칸이 없는 부품(DynamicFieldList — onItemAdd/onItemRemove), 편집기 팔레트에 없는 부품(basic FileInput)
 *  - 표식 부품의 소스는 onChange 를 useEffect 안에서 부르지 않는다
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const ROOT = resolve(__dirname, '../../../../../../..');
const caps = (tpl: string): Record<string, { events?: string[]; rawValueEvents?: string[] }> =>
  JSON.parse(readFileSync(resolve(ROOT, `templates/_bundled/${tpl}/editor-spec/componentCapabilities.json`), 'utf8'));

const EXPECTED: Record<string, Record<string, string>> = {
  'sirsoft-admin_basic': {
    SearchableDropdown: 'src/components/composite/SearchableDropdown.tsx',
    TagSelect: 'src/components/composite/TagSelect.tsx',
    IconSelect: 'src/components/composite/IconSelect.tsx',
    FilterGroup: 'src/components/composite/FilterGroup.tsx',
  },
  'sirsoft-basic': {
    QuantitySelector: 'src/components/composite/QuantitySelector.tsx',
    RichTextEditor: 'src/components/composite/RichTextEditor.tsx',
  },
};

describe('원시값 onChange 부품 표식(rawValueEvents)', () => {
  for (const [tpl, components] of Object.entries(EXPECTED)) {
    // @scenario event_notation=event
    // @effects raw_value_slot_writes_event_notation
    it(`${tpl}: 표식이 붙은 부품이 정확히 기대 목록과 같고, 모두 events 에 onChange 가 있다`, () => {
      const spec = caps(tpl);
      const marked = Object.entries(spec)
        .filter(([, v]) => v && typeof v === 'object' && Array.isArray(v.rawValueEvents))
        .map(([k]) => k)
        .sort();
      expect(marked).toEqual(Object.keys(components).sort());
      for (const name of marked) {
        expect(spec[name].rawValueEvents).toEqual(['onChange']);
        expect(spec[name].events).toContain('onChange');
      }
    });

    // @scenario event_notation=event
    // @effects raw_value_slot_writes_event_notation
    it(`${tpl}: 표식 부품의 소스는 onChange 를 useEffect 안에서 부르지 않는다(마운트·리렌더 호출 없음)`, () => {
      for (const [name, rel] of Object.entries(components)) {
        const src = readFileSync(resolve(ROOT, `templates/_bundled/${tpl}`, rel), 'utf8');
        const effects = src.match(/useEffect\(\(\) => \{[\s\S]*?\n {2}\}, \[[^\]]*\]\);/g) ?? [];
        for (const body of effects) {
          expect(/\bonChange\??\.?\(/.test(body), `${name} useEffect 안 onChange 호출`).toBe(false);
        }
      }
    });
  }

  // @scenario event_notation=event
  // @effects raw_value_slot_writes_event_notation
  it('event 모양 onChange 부품(PermissionTree·admin FileInput)에는 표식이 없다 — type 경로가 이미 실행한다', () => {
    const spec = caps('sirsoft-admin_basic');
    expect(spec.PermissionTree?.rawValueEvents).toBeUndefined();
    expect(spec.FileInput?.rawValueEvents).toBeUndefined();
  });

  // @scenario event_notation=event
  // @effects raw_value_slot_shows_args_hint
  it('값이 둘째 인자인 FilterGroup(onChange(filterId, value))만 rawValueArgIndex 로 위치를 알린다', () => {
    const spec = caps('sirsoft-admin_basic') as Record<string, { rawValueArgIndex?: Record<string, number> }>;
    expect(spec.FilterGroup?.rawValueArgIndex).toEqual({ onChange: 1 });
    const src = readFileSync(resolve(ROOT, 'templates/_bundled/sirsoft-admin_basic/src/components/composite/FilterGroup.tsx'), 'utf8');
    expect(src).toMatch(/onChange\?\.\(filterId, /);
    const others = Object.entries(spec).filter(([k, v]) => k !== 'FilterGroup' && v && typeof v === 'object' && v.rawValueArgIndex);
    expect(others).toEqual([]);
  });
});
