/**
 * 클래스 값 분석기 테스트 — 표현식 리터럴 판정과 파일 형식별 스캐너
 */
import { describe, it, expect } from 'vitest';
import {
  analyzeClassValue, scanJsonClassSlots, scanHtmlClassSlots, scanPhpClassSlots, applyEdits,
} from '../lib/class-tokens.mjs';

/**
 * 변환 대상 토큰(조립형 제외) 목록.
 *
 * @param {string} value 값
 * @returns {string[]} 토큰
 */
const classTokens = (value) => analyzeClassValue(value).tokens.filter((t) => !t.assembled).map((t) => t.token);

/**
 * 조립형 토큰 목록.
 *
 * @param {string} value 값
 * @returns {string[]} 토큰
 */
const assembled = (value) => analyzeClassValue(value).tokens.filter((t) => t.assembled).map((t) => t.token);

/**
 * 제외 리터럴 종류 목록.
 *
 * @param {string} value 값
 * @returns {string[]} `종류:원문`
 */
const excluded = (value) => analyzeClassValue(value).excluded.map((x) => `${x.kind}:${x.text}`);

describe('정적 토큰과 표현식 리터럴', () => {
  it('정적 부분은 공백으로 나눈다', () => {
    expect(classTokens('  flex  items-center\tgap-2 ')).toEqual(['flex', 'items-center', 'gap-2']);
  });

  it('삼항의 결과 리터럴은 클래스, 비교 피연산자는 양쪽 모두 제외', () => {
    const v = "px-4 {{row.status === 'done' ? 'bg-green-100 text-green-800' : 'bg-gray-100'}}";
    expect(classTokens(v)).toEqual(['px-4', 'bg-green-100', 'text-green-800', 'bg-gray-100']);
    expect(excluded(v)).toEqual(["compare:'done'"]);
    expect(excluded("{{'done' !== row.status ? 'a' : 'b'}}")).toEqual(["compare:'done'"]);
    expect(excluded("{{row.n == 'x' || row.m != 'y' ? 'a' : ''}}")).toEqual(["compare:'x'", "compare:'y'"]);
  });

  it('괄호 그룹이 비교되면 그룹 안 값도 비교 피연산자다', () => {
    const v = "{{(form.mode ?? 'standard') === 'standard' ? 'border-blue-500' : 'border-gray-200'}}";
    expect(classTokens(v)).toEqual(['border-blue-500', 'border-gray-200']);
    expect(excluded(v)).toEqual(["compare:'standard'", "compare:'standard'"]);
  });

  it('메서드 인자와 대괄호 속성 접근은 제외', () => {
    const v = "{{row.tags.includes('hidden') || row.map['block'] || name.startsWith('a.' + i) ? 'hidden' : 'block'}}";
    expect(classTokens(v)).toEqual(['hidden', 'block']);
    expect(excluded(v)).toEqual(["argument:'hidden'", "index:'block'", "argument:'a.'"]);
  });

  it('객체 리터럴의 키는 제외하고 값은 클래스로 본다', () => {
    const v = "{{ {'pending': 'bg-yellow-100', done: 'text-gray-500', 'x-y': c ? 'a' : 'b'}[row.status] }}";
    expect(classTokens(v)).toEqual(['bg-yellow-100', 'text-gray-500', 'a', 'b']);
    expect(excluded(v)).toEqual(["object-key:'pending'", "object-key:'x-y'"]);
  });

  it('삼항 `? \'a\' :` 는 객체 키로 오인하지 않는다', () => {
    expect(classTokens("{{ {a: c ? 'flex' : 'block'}.a }}")).toEqual(['flex', 'block']);
  });

  it('조립형은 붙은 쪽 토큰만 수동 확인으로 돌린다', () => {
    const v = "bg-{{row.color}}-500 {{'text-' + row.tone}} {{'base ' + (c ? 'a b' : 'c')}}";
    expect(assembled(v)).toEqual(['bg-', '-500', 'text-']);
    expect(classTokens(v)).toEqual(['base', 'a', 'b', 'c']);
  });

  it('공백으로 끝나지 않는 상대와 이어 붙은 그룹 값은 조립형이다', () => {
    const v = "{{'fi fi-' + (code ?? 'xx') + ' fis'}}";
    expect(assembled(v)).toEqual(['fi-', 'xx']);
    expect(classTokens(v)).toEqual(['fi', 'fis']);
  });

  it('템플릿 리터럴: 정적 조각은 토큰, `${}` 에 붙은 조각은 조립형, 안쪽 식은 다시 분석', () => {
    const v = "{{`px-2 ${on ? 'bg-blue-100' : ''} bg-${tone}`}}";
    expect(classTokens(v)).toEqual(['px-2', 'bg-blue-100']);
    expect(assembled(v)).toEqual(['bg-']);
  });

  it('리터럴 뒤에 메서드가 붙으면 판정 불가로 보고', () => {
    const r = analyzeClassValue("{{'a b'.split(' ')[0]}}");
    expect(r.manual.map((m) => m.kind)).toEqual(['undecidable']);
    expect(r.tokens).toEqual([]);
  });

  it('닫히지 않은 바인딩은 수동 확인', () => {
    expect(analyzeClassValue('flex {{row.a').manual.map((m) => m.kind)).toEqual(['unclosed-binding']);
  });

  it('토큰 위치로 치환하면 나머지 원문이 그대로 남는다', () => {
    const v = "px-4 {{a === 'x' ? 'flex' : 'block'}}";
    const edits = analyzeClassValue(v).tokens.map((t) => ({ start: t.start, end: t.end, text: `p:${t.token}` }));
    expect(applyEdits(v, edits)).toBe("p:px-4 {{a === 'x' ? 'p:flex' : 'p:block'}}");
  });
});

describe('파일 형식별 스캐너', () => {
  it('JSON: className·*ClassName·classMap(base·default·variants)만 잡는다', () => {
    const text = JSON.stringify({
      className: 'a',
      props: { cellClassName: 'b', label: 'flex', classMap: { key: '{{x}}', base: 'c', variants: { on: 'd' }, default: 'e' } },
      list: [{ className: 'f' }],
      comment: 'className 이라는 낱말',
    }, null, 4);
    const values = scanJsonClassSlots(text).map((s) => text.slice(s.start, s.end));
    expect(values).toEqual(['a', 'b', 'c', 'd', 'e', 'f']);
  });

  it('JSON: 값이 속한 컴포넌트 이름을 돌려준다(name 키 순서·props.name 과 무관)', () => {
    const text = JSON.stringify({
      name: 'Div',
      props: { className: 'a', name: 'field' },
      children: [
        { props: { name: 'fa-solid fa-x', className: 'b' }, name: 'Icon' },
        { name: 'sirsoft-demo.memo-card', props: { className: 'c' } },
        { name: 'Icon', responsive: { portable: { props: { className: 'd' } } } },
      ],
    }, null, 2);
    expect(scanJsonClassSlots(text).map((s) => `${text.slice(s.start, s.end)}:${s.component}`))
      .toEqual(['a:Div', 'b:Icon', 'c:sirsoft-demo.memo-card', 'd:Icon']);
  });

  it('JSON: 이스케이프된 따옴표가 있어도 위치가 맞다', () => {
    const text = '{"className": "a {{x === \\"y\\" ? \'b\' : \'\'}}"}';
    const [slot] = scanJsonClassSlots(text);
    expect(JSON.parse(`"${text.slice(slot.start, slot.end)}"`)).toBe('a {{x === "y" ? \'b\' : \'\'}}');
  });

  it('.g7.html: 주석은 건너뛰고 class·*ClassName·class-map 을 잡는다', () => {
    const text = [
      '<!-- <div class="nope"> -->',
      '<div class="a" data-if="{{x > 1}}" label="b"><grid cellClassName="c"/>',
      '<class-map key="{{k}}" base="d" default="e"><variant when="on">f g</variant></class-map>',
      '<default>{{not}}</default>',
      '</div>',
    ].join('\n');
    const values = scanHtmlClassSlots(text).map((s) => text.slice(s.start, s.end));
    expect(values).toEqual(['a', 'c', 'd', 'e', 'f g']);
  });

  it('.g7.html: 데이터 필드 <field name="className|*ClassName" value> 의 value 를 잡는다', () => {
    const text = [
      '<field name="className" value="p-4 border-t"/>',
      "<field value='gap-2' name='rowClassName'/>",
      '<field name="label" value="not-a-class"/>',
      '<item className="x"><field name="title" value="y"/></item>',
    ].join('\n');
    const values = scanHtmlClassSlots(text).map((s) => text.slice(s.start, s.end));
    expect(values).toEqual(['p-4 border-t', 'gap-2', 'x']);
  });

  it('PHP: class·className·*ClassName 키의 문자열 값과 뒤따르는 기호', () => {
    const text = "['class' => 'a b', \"className\" => \"c\", 'iconClassName' => 'd ' . $x, 'label' => 'flex']";
    const slots = scanPhpClassSlots(text);
    expect(slots.map((s) => text.slice(s.start, s.end))).toEqual(['a b', 'c', 'd ']);
    expect(slots[2].after).toBe('.');
  });
});
