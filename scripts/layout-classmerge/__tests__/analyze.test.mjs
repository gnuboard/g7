/**
 * 구간 판정·마크업 스캔 단위 시험 (PHP 없이).
 */
import { beforeAll, describe, expect, it } from 'vitest';
import { loadMergeEngine } from '../lib/merge-engine.mjs';
import { analyzeOverride, applyConversions, classMapOutcomes, collectOverrides, effectiveClassNames, firstDifference } from '../lib/segments.mjs';
import { applyEdits, attrOf, decodeReferences, encodeForAttribute, scanResponsiveTags } from '../lib/markup.mjs';

let engine;
beforeAll(async () => {
  engine = await loadMergeEngine({ prefixes: ['ecommerce'] });
});

const node = (base, override, extra = {}) => ({ name: 'Div', props: base === undefined ? undefined : { className: base }, responsive: { portable: override }, ...extra });
const judge = (n) => analyzeOverride(collectOverrides(n)[0], engine);

describe('analyzeOverride', () => {
  it('바뀐 토큰만 diff 로 남기면 같은 결과인 구간은 변환 가능', () => {
    const r = judge(node('w-full max-w-md p-8 bg-white rounded-lg shadow', { props: { className: 'w-full max-w-none p-6 bg-white rounded-lg shadow' } }));
    expect(r.status).toBe('convertible');
    expect(r.diff).toBe('max-w-none p-6');
    expect(r.overlap).toBe(0.667);
  });

  it('구간 props 에 className 외 키가 있으면 그 키는 남기고 className 만 옮긴다', () => {
    const doc = node('flex p-4', { props: { className: 'flex p-6', title: '넓음' } });
    const r = judge(doc);
    expect(r.status).toBe('convertible');
    expect(r.otherProps).toEqual(['title']);
    const after = applyConversions(doc, new Map([[r.pointer, r.diff]]));
    expect(after.responsive.portable).toEqual({ props: { title: '넓음' }, classMerge: 'p-6' });
    expect(doc.responsive.portable.props.className).toBe('flex p-6');
  });

  it('className 만 있던 구간은 props 를 지운다', () => {
    const doc = node('flex p-4', { props: { className: 'flex p-6' } });
    const r = judge(doc);
    expect(applyConversions(doc, new Map([[r.pointer, r.diff]])).responsive.portable).toEqual({ classMerge: 'p-6' });
  });

  it.each([
    ['binding', node('flex p-4 {{a}}', { props: { className: 'flex p-2' } })],
    ['binding', node('flex p-4', { props: { className: "flex {{a ? 'p-2' : 'p-3'}}" } })],
    ['non-string', node('flex', { props: { className: { $switch: 'a', $cases: { x: 'p-2' } } } })],
    ['has-class-merge', node('flex p-4', { props: { className: 'flex p-2' }, classMerge: 'p-2' })],
    ['same-as-base', node('flex p-4', { props: { className: 'p-4 flex' } })],
    ['pure-removal', node('flex p-4 gap-2', { props: { className: 'flex p-4' } })],
    ['removed-base-token', node('text-sm text-gray-500 flex', { props: { className: 'text-xs flex' } })],
    ['merge-drops-token', node('grid grid-cols-1 gap-4', { props: { className: 'grid grid-cols-1 grid-cols-2 gap-4' } })],
  ])('건너뜀 사유 %s', (reason, doc) => {
    const r = judge(doc);
    expect(r.status).toBe('skip');
    expect(r.reason).toBe(reason);
  });

  it('removed-base-token 은 남는 기본 토큰을 extra 로 알린다', () => {
    const r = judge(node('text-sm text-gray-500 flex', { props: { className: 'text-xs flex' } }));
    expect(r.extra).toEqual(['text-gray-500']);
  });

  it('구간 className 이 없으면 판정 대상이 아니다', () => {
    expect(judge(node('flex', { props: { title: 'x' } }))).toBeNull();
    expect(judge(node('flex', { if: '{{a}}' }))).toBeNull();
  });

  it('기본 className 이 없으면 빈 기본으로 본다', () => {
    const r = judge(node(undefined, { props: { className: 'w-full' } }));
    expect(r.status).toBe('convertible');
    expect(r.diff).toBe('w-full');
  });

  it('classMap 결과가 diff 와 충돌하면 class-map-mismatch', () => {
    // 단계 없는 색(bg-white)은 엔진 색 그룹에 걸리지 않으므로 단계 있는 색으로 충돌을 만든다
    const doc = node('flex p-4', { props: { className: 'flex p-4 bg-gray-50' } }, { classMap: { key: '{{s}}', variants: { a: 'bg-red-500' }, default: '' } });
    const r = judge(doc);
    expect(r.reason).toBe('class-map-mismatch');
    expect(r.classMapValue).toBe('bg-red-500');
    expect(r.missing).toEqual(['bg-red-500']);
  });

  it('classMap 결과가 diff 와 충돌하지 않으면 변환 가능', () => {
    const doc = node('flex p-4', { props: { className: 'flex p-6' } }, { classMap: { base: 'rounded', key: '{{s}}', variants: { a: 'bg-red-500' } } });
    expect(judge(doc).status).toBe('convertible');
  });

  it('구간 classMap 이 기본 classMap 을 대신한다', () => {
    const doc = node('flex p-4', { props: { className: 'flex p-4 bg-gray-50' }, classMap: { key: '{{s}}', variants: { a: 'text-sm' } } }, { classMap: { key: '{{s}}', variants: { a: 'bg-red-500' } } });
    expect(judge(doc).status).toBe('convertible');
  });

  it('classMap 값에 바인딩이 있으면 class-map-binding', () => {
    const doc = node('flex p-4', { props: { className: 'flex p-6' } }, { classMap: { key: '{{s}}', variants: { a: '{{x}}' } } });
    expect(judge(doc).reason).toBe('class-map-binding');
  });

  it('확장 앞글자 토큰도 엔진 규칙으로 판정한다', () => {
    const r = judge(node('ecommerce:flex ecommerce:p-4', { props: { className: 'ecommerce:flex ecommerce:p-2' } }));
    expect(r.status).toBe('convertible');
    expect(r.diff).toBe('ecommerce:p-2');
  });
});

describe('collectOverrides·effectiveClassNames', () => {
  it('오버라이드 안 children 노드의 구간도 깊이 우선으로 모은다', () => {
    const doc = { name: 'Div', props: { className: 'a' }, responsive: { portable: { children: [node('p-4', { props: { className: 'p-2' } })] } }, children: [node('m-4', { props: { className: 'm-2' } })] };
    expect(collectOverrides(doc).map((e) => e.pointer)).toEqual([
      '/responsive/portable',
      '/responsive/portable/children/0/responsive/portable',
      '/children/0/responsive/portable',
    ]);
  });

  it('classMap 후보는 base+variant, default 가 없으면 base 만 더한다', () => {
    expect(classMapOutcomes({ base: 'x', key: 'k', variants: { a: 'y', b: 'z' } }).values).toEqual(['x y', 'x z', 'x']);
    expect(classMapOutcomes({ key: 'k', variants: { a: 'y' }, default: 'd' }).values).toEqual(['y', 'd']);
    expect(classMapOutcomes(undefined).values).toEqual(['']);
  });

  it('실효 className 은 구간 className 우선, classMerge 는 그 위에 덧붙는다', () => {
    const before = node('flex p-4', { props: { className: 'flex p-6' } });
    const after = node('flex p-4', { classMerge: 'p-6' });
    const eb = effectiveClassNames(collectOverrides(before)[0], engine);
    const ea = effectiveClassNames(collectOverrides(after)[0], engine);
    expect(eb.values).toEqual(['flex p-6']);
    expect(ea.values).toEqual(['flex p-6']);
  });

  it('firstDifference 는 키 순서를 무시하고 처음 어긋난 위치를 준다', () => {
    expect(firstDifference({ a: 1, b: [1, { c: 2 }] }, { b: [1, { c: 2 }], a: 1 })).toBeNull();
    expect(firstDifference({ a: 1, b: [1, { c: 2 }] }, { a: 1, b: [1, { c: 3 }] })).toBe('/b/1/c');
  });
});

describe('마크업 스캔', () => {
  const src = [
    '<!-- <responsive on="portable" class="주석"/> -->',
    '<div class="flex p-4">',
    '  <responsive',
    '    on="portable"',
    "    class='flex p-2' title=\"t\"/>",
    '  <responsive on="desktop" class="a &amp;&amp; b"></responsive>',
    '</div>',
  ].join('\n');

  it('주석 안 태그는 세지 않고 여러 줄 태그·작은따옴표도 읽는다', () => {
    const tags = scanResponsiveTags(src);
    expect(tags).toHaveLength(2);
    expect(attrOf(tags[0], 'on').value).toBe('portable');
    expect(attrOf(tags[0], 'class')).toMatchObject({ value: 'flex p-2', quote: "'" });
    expect(attrOf(tags[1], 'class').value).toBe('a &amp;&amp; b');
  });

  it('속성 하나만 바꾸고 나머지 원문은 그대로 둔다', () => {
    const cls = attrOf(scanResponsiveTags(src)[0], 'class');
    const out = applyEdits(src, [{ start: cls.start, end: cls.end, text: "class-merge='p-2'" }]);
    expect(out).toBe(src.replace("class='flex p-2'", "class-merge='p-2'"));
  });

  it('문자 참조는 세미콜론으로 끝나는 것만 푼다', () => {
    expect(decodeReferences('a &amp;&amp; b &gt; c && d &#39;x&#x27;')).toBe("a && b > c && d 'x'");
    expect(decodeReferences('&unknown;')).toBe('&unknown;');
  });

  it('원문이 문자 참조를 썼으면 쓸 값도 같은 관례로 인코딩한다', () => {
    expect(encodeForAttribute('[&>svg]:w-5', '"', '[&amp;&gt;svg]:w-4 p-4')).toBe('[&amp;&gt;svg]:w-5');
    expect(encodeForAttribute('[&>svg]:w-5', '"', '[&>svg]:w-4 p-4')).toBe('[&>svg]:w-5');
  });
});
