// @scenario apply_type=cssVar, consumer=none, storage_scope=template_layouts, stored_shape=object, widget_output=image_object
// @scenario apply_type=propValue, consumer=none, storage_scope=template_layouts, stored_shape=string, widget_output=scalar_string
// @scenario apply_type=propValue, consumer=none, storage_scope=template_layouts, stored_shape=string, widget_output=image_object_empty_url
// @scenario apply_type=classToken, consumer=none, storage_scope=template_layouts, stored_shape=absent, widget_output=undefined_cleared
/**
 * recipeEngine.test.ts — 컨트롤 레시피 ↔ 노드 패치 변환
 *
 * 검증 매트릭스:
 *  - apply 프리미티브 4종 (classToken / styleProp / cssVar / propValue) 적용
 *  - 택1 컨트롤(옵션) 적용 — group 토큰 교체
 *  - styleProp 다중 속성(배경 이미지) values 묶음
 *  - tokenTemplate 자유값 classToken 합성/역추출
 *  - 기본/미적용(value=undefined) → group 토큰/스타일/prop 제거
 *  - reverseResolve — apply 타입별 현재값 역해석 (className/style/prop 각각)
 *  - 라운드트립 대칭 (apply → reverseResolve)
 *  - 고급값 분류 (matched:false) + group 충돌 (conflict:true)
 *  - 입력 노드 불변
 */

import { describe, it, expect } from 'vitest';
import { applyRecipe, reverseResolve } from '../../spec/recipeEngine';
import type { EditorControlSpec } from '../../spec/specTypes';
import type { EditorNode } from '../../utils/layoutTreeUtils';

const textAlign: EditorControlSpec = {
  widget: 'segmented',
  group: 'text-align',
  options: [
    { value: 'left', apply: { type: 'classToken', tokens: ['text-left'] } },
    { value: 'center', apply: { type: 'classToken', tokens: ['text-center'] } },
    { value: 'right', apply: { type: 'classToken', tokens: ['text-right'] } },
  ],
};

const textColor: EditorControlSpec = {
  widget: 'color',
  group: 'text-color',
  apply: { type: 'styleProp', prop: 'color' },
};

const brandVar: EditorControlSpec = {
  widget: 'color',
  apply: { type: 'cssVar', varName: '--brand' },
};

const widthProp: EditorControlSpec = {
  widget: 'select',
  apply: { type: 'propValue', propKey: 'size' },
};

const bgImage: EditorControlSpec = {
  widget: 'image',
  apply: { type: 'styleProp', props: ['backgroundImage', 'backgroundSize', 'backgroundRepeat'] },
};

const widthArbitrary: EditorControlSpec = {
  widget: 'select',
  group: 'width',
  apply: { type: 'classToken', tokenTemplate: 'w-[{value}]' },
};

describe('applyRecipe — classToken (택1 옵션)', () => {
  it('옵션 선택 시 토큰을 className 에 추가한다', () => {
    const node: EditorNode = { name: 'H1' };
    const next = applyRecipe(node, textAlign, 'center');
    expect(next.props?.className).toBe('text-center');
  });

  it('같은 group 의 기존 토큰을 교체한다', () => {
    const node: EditorNode = { name: 'H1', props: { className: 'text-left font-bold' } };
    const next = applyRecipe(node, textAlign, 'right');
    expect(next.props?.className).toBe('font-bold text-right');
  });

  it('value=undefined(기본) 면 group 토큰만 제거하고 나머지는 보존한다', () => {
    const node: EditorNode = { name: 'H1', props: { className: 'text-center font-bold' } };
    const next = applyRecipe(node, textAlign, undefined);
    expect(next.props?.className).toBe('font-bold');
  });

  it('마지막 group 토큰 제거 시 className 키 자체를 제거한다', () => {
    const node: EditorNode = { name: 'H1', props: { className: 'text-center' } };
    const next = applyRecipe(node, textAlign, undefined);
    expect(next.props?.className).toBeUndefined();
  });
});

describe('applyRecipe — groupTokens (옵션 밖 기본 토큰 교체)', () => {
  // 실제 번들 스펙 형태: 옵션은 normal/semibold/bold 만, groupTokens 로 패밀리 전체 선언.
  const fontWeight: EditorControlSpec = {
    widget: 'segmented',
    group: 'font-weight',
    groupTokens: ['font-thin', 'font-light', 'font-normal', 'font-medium', 'font-semibold', 'font-bold', 'font-black'],
    options: [
      { value: 'font-normal', apply: { type: 'classToken', tokens: ['font-normal'] } },
      { value: 'font-semibold', apply: { type: 'classToken', tokens: ['font-semibold'] } },
      { value: 'font-bold', apply: { type: 'classToken', tokens: ['font-bold'] } },
    ],
  } as unknown as EditorControlSpec;

  it('옵션에 없는 기본 토큰(font-medium)도 같은 group 으로 교체된다 — 핵심 회귀', () => {
    // 노드 기본 className 이 font-medium(옵션 밖). bold 적용 시 font-medium 이 남으면 안 됨.
    const node: EditorNode = { name: 'Span', props: { className: 'text-sm font-medium opacity-90' } };
    const next = applyRecipe(node, fontWeight, 'font-bold');
    const cls = (next.props?.className as string) ?? '';
    expect(cls).toContain('font-bold');
    expect(cls).not.toContain('font-medium'); // groupTokens 로 교체됨
    expect(cls).toContain('text-sm'); // 다른 group 토큰은 보존
    expect(cls).toContain('opacity-90');
  });

  it('해제(undefined) 시 groupTokens 패밀리의 기본 토큰도 제거된다', () => {
    const node: EditorNode = { name: 'Span', props: { className: 'font-medium text-sm' } };
    const next = applyRecipe(node, fontWeight, undefined);
    const cls = (next.props?.className as string) ?? '';
    expect(cls).not.toContain('font-medium');
    expect(cls).toContain('text-sm');
  });

  it('reverseResolve — 옵션 밖 기본 토큰(font-light)은 매칭 안 됨(고급값) 이지만 적용 시 교체된다', () => {
    // 역해석: font-light 는 옵션이 아니라 매칭 안 됨(고급으로 분류). 그래도 새 적용은 교체.
    const node: EditorNode = { name: 'Span', props: { className: 'font-light' } };
    const applied = applyRecipe(node, fontWeight, 'font-bold');
    expect((applied.props?.className as string) ?? '').not.toContain('font-light');
  });
});

describe('applyRecipe — toggle off 옵션(apply 없는 비-빈 value)', () => {
  // 실제 editor-spec 의 flexWrap 컨트롤 형태 — off 옵션(`nowrap`)은 apply 가 없고
  // on 옵션(`wrap`)만 토큰을 단다. ToggleWidget 은 off 시 off 옵션 value(`nowrap`)를
  // applyRecipe 에 넘긴다(undefined 가 아님). 종전엔 매칭 옵션에 apply 가 없으면
  // "기본/미적용 제거" 분기도, apply 분기도 타지 않아 on 토큰(`flex-wrap`)이 잔존했다.
  const flexWrap: EditorControlSpec = {
    widget: 'toggle',
    group: 'flex-wrap',
    options: [
      { value: 'nowrap' },
      { value: 'wrap', apply: { type: 'classToken', tokens: ['flex-wrap'] } },
    ],
  };

  it('on 옵션 선택 시 group 토큰을 단다', () => {
    const node: EditorNode = { name: 'Div', props: { className: 'flex gap-4' } };
    const next = applyRecipe(node, flexWrap, 'wrap');
    expect(next.props?.className).toBe('flex gap-4 flex-wrap');
  });

  it('off 옵션(apply 없는 value) 선택 시 group 토큰을 제거한다', () => {
    const node: EditorNode = { name: 'Div', props: { className: 'flex gap-4 flex-wrap' } };
    const next = applyRecipe(node, flexWrap, 'nowrap');
    // flex-wrap 토큰이 제거되고 나머지는 보존
    expect(next.props?.className).toBe('flex gap-4');
  });

  it('off 옵션 선택을 reverseResolve 로 역해석하면 미매칭(off)이다', () => {
    const node: EditorNode = { name: 'Div', props: { className: 'flex gap-4' } };
    const r = reverseResolve(node, flexWrap);
    expect(r.matched).toBe(false);
    expect(r.value).toBeUndefined();
  });
});

describe('applyRecipe — styleProp / cssVar / propValue', () => {
  it('styleProp 단일 속성을 props.style 에 설정한다', () => {
    const node: EditorNode = { name: 'P' };
    const next = applyRecipe(node, textColor, '#1a1a1a');
    expect((next.props?.style as Record<string, unknown>).color).toBe('#1a1a1a');
  });

  it('styleProp 다중 속성(배경 이미지) values 묶음을 설정한다', () => {
    const node: EditorNode = { name: 'Div' };
    const next = applyRecipe(node, bgImage, undefined as never);
    // values 없이 단일 value 만 줄 때는 호출자가 values 로 묶어 전달하는 패턴 확인
    const node2: EditorNode = { name: 'Div' };
    const ctrl: EditorControlSpec = {
      widget: 'image',
      apply: {
        type: 'styleProp',
        props: ['backgroundImage', 'backgroundSize'],
        values: { backgroundImage: 'url(/a.png)', backgroundSize: 'cover' },
      },
    };
    const next2 = applyRecipe(node2, ctrl, 'set');
    const style = next2.props?.style as Record<string, unknown>;
    expect(style.backgroundImage).toBe('url(/a.png)');
    expect(style.backgroundSize).toBe('cover');
    void next;
  });

  it('cssVar 를 props.style 에 설정한다', () => {
    const node: EditorNode = { name: 'Div' };
    const next = applyRecipe(node, brandVar, '#0ea5e9');
    expect((next.props?.style as Record<string, unknown>)['--brand']).toBe('#0ea5e9');
  });

  it('propValue 를 props 에 설정한다', () => {
    const node: EditorNode = { name: 'Img' };
    const next = applyRecipe(node, widthProp, 'lg');
    expect(next.props?.size).toBe('lg');
  });

  it('tokenTemplate 자유값을 임의값 클래스로 합성한다', () => {
    const node: EditorNode = { name: 'Div' };
    const next = applyRecipe(node, widthArbitrary, '320px');
    expect(next.props?.className).toBe('w-[320px]');
  });

  it('빈 style 객체는 props.style 키를 남기지 않는다', () => {
    const node: EditorNode = { name: 'P', props: { style: { color: '#000' } } };
    const next = applyRecipe(node, textColor, undefined);
    expect(next.props?.style).toBeUndefined();
  });
});

// 배경 이미지 미반영 회귀. ImagePickerControl 은 `apply.values` 를 쓰지 않고
// 런타임 객체 `{ url, size, repeat, position }` 를 value 로 넘긴다(editor-spec.json 의
// backgroundImage 컨트롤도 values 미선언). 엔진이 이 객체를 4개 CSS 속성으로 분해
// (url 은 `url(...)` 래핑)하지 못하면 backgroundImage 에 객체가 통째로 들어가 React 가
// 무시 → 캔버스 배경 미표시. 본 describe 는 실제 데이터 흐름을 그대로 재현한다.
describe('applyRecipe — image 위젯 객체값 분해', () => {
  const bgImageReal: EditorControlSpec = {
    widget: 'image',
    group: 'bg-image',
    apply: {
      type: 'styleProp',
      props: ['backgroundImage', 'backgroundSize', 'backgroundRepeat', 'backgroundPosition'],
    },
  };

  it('image 위젯 객체값을 4개 CSS 속성으로 분해하고 url 을 url(...) 로 래핑한다', () => {
    const node: EditorNode = { name: 'Div' };
    const next = applyRecipe(node, bgImageReal, {
      url: 'https://example.com/api/templates/sirsoft-basic/layout-attachments/a.jpg',
      size: 'cover',
      repeat: 'no-repeat',
      position: 'center',
    });
    const style = next.props?.style as Record<string, unknown>;
    expect(style.backgroundImage).toBe(
      'url(https://example.com/api/templates/sirsoft-basic/layout-attachments/a.jpg)',
    );
    expect(style.backgroundSize).toBe('cover');
    expect(style.backgroundRepeat).toBe('no-repeat');
    expect(style.backgroundPosition).toBe('center');
  });

  it('url 미지정 객체는 backgroundImage 를 설정하지 않는다(부분 객체 안전)', () => {
    const node: EditorNode = { name: 'Div' };
    const next = applyRecipe(node, bgImageReal, { size: 'contain' });
    const style = (next.props?.style ?? {}) as Record<string, unknown>;
    expect(style.backgroundImage).toBeUndefined();
    expect(style.backgroundSize).toBe('contain');
  });

  it('이미 url(...) 래핑된 값은 이중 래핑하지 않는다', () => {
    const node: EditorNode = { name: 'Div' };
    const next = applyRecipe(node, bgImageReal, { url: 'url(/b.png)', size: 'cover' });
    const style = next.props?.style as Record<string, unknown>;
    expect(style.backgroundImage).toBe('url(/b.png)');
  });

  it('라운드트립 — 분해 적용 후 reverseResolve 가 객체로 역조립한다', () => {
    const node: EditorNode = { name: 'Div' };
    const applied = applyRecipe(node, bgImageReal, {
      url: '/hero.jpg',
      size: 'cover',
      repeat: 'no-repeat',
      position: 'center',
    });
    const r = reverseResolve(applied, bgImageReal);
    expect(r.matched).toBe(true);
    expect(r.value).toEqual({
      url: '/hero.jpg',
      size: 'cover',
      repeat: 'no-repeat',
      position: 'center',
    });
  });

  // 레거시 손상값 — 수정 이전 저장본은 size/repeat/position 에 image 값 객체가 통째로
  // 들어가 있을 수 있다(브라우저 실측 시 409 payload 의 backgroundPosition:{object}).
  it('레거시 손상값(position 에 객체)이 있던 노드에 새 값 적용 시 4속성 모두 스칼라로 정정', () => {
    const node: EditorNode = {
      name: 'Div',
      props: {
        style: {
          backgroundImage: 'url(/old.png)',
          backgroundSize: 'cover',
          backgroundRepeat: 'no-repeat',
          // 손상: 객체가 통째로 저장돼 있음
          backgroundPosition: { url: '/old.png', size: 'contain', repeat: 'no-repeat', position: 'center' },
        },
      },
    };
    const next = applyRecipe(node, bgImageReal, {
      url: '/new.png',
      size: 'cover',
      repeat: 'no-repeat',
      position: 'center',
    });
    const style = next.props?.style as Record<string, unknown>;
    expect(style.backgroundImage).toBe('url(/new.png)');
    expect(style.backgroundPosition).toBe('center'); // 객체 → 스칼라로 정정
    expect(typeof style.backgroundPosition).toBe('string');
  });

  it('reverseResolve 는 손상된 객체값 position 을 스칼라로 정화하거나 폐기한다', () => {
    const node: EditorNode = {
      name: 'Div',
      props: {
        style: {
          backgroundImage: 'url(/x.png)',
          backgroundSize: 'cover',
          backgroundRepeat: 'no-repeat',
          backgroundPosition: { position: 'center', size: 'cover' }, // 손상 객체
        },
      },
    };
    const r = reverseResolve(node, bgImageReal);
    expect(r.matched).toBe(true);
    const v = r.value as Record<string, unknown>;
    expect(v.url).toBe('/x.png');
    // 객체에서 같은 의미 필드(position)를 복구
    expect(v.position).toBe('center');
    expect(typeof v.position).toBe('string');
  });

  it('value=undefined 면 4개 배경 속성을 모두 제거한다', () => {
    const node: EditorNode = {
      name: 'Div',
      props: {
        style: {
          backgroundImage: 'url(/x.png)',
          backgroundSize: 'cover',
          backgroundRepeat: 'no-repeat',
          backgroundPosition: 'center',
        },
      },
    };
    const next = applyRecipe(node, bgImageReal, undefined);
    expect(next.props?.style).toBeUndefined();
  });
});

describe('reverseResolve — apply 타입별 현재값 역해석', () => {
  it('classToken 택1 — 현재 토큰에서 옵션 value 를 역해석한다', () => {
    const node: EditorNode = { name: 'H1', props: { className: 'font-bold text-right' } };
    const r = reverseResolve(node, textAlign);
    expect(r).toEqual({ value: 'right', matched: true, conflict: undefined });
  });

  it('styleProp — props.style 에서 현재값을 역해석한다', () => {
    const node: EditorNode = { name: 'P', props: { style: { color: '#abc' } } };
    expect(reverseResolve(node, textColor)).toEqual({ value: '#abc', matched: true });
  });

  it('cssVar — props.style 의 변수에서 역해석한다', () => {
    const node: EditorNode = { name: 'Div', props: { style: { '--brand': '#fff' } } };
    expect(reverseResolve(node, brandVar)).toEqual({ value: '#fff', matched: true });
  });

  it('propValue — props 에서 역해석한다', () => {
    const node: EditorNode = { name: 'Img', props: { size: 'sm' } };
    expect(reverseResolve(node, widthProp)).toEqual({ value: 'sm', matched: true });
  });

  it('tokenTemplate — 임의값 클래스에서 값을 역추출한다', () => {
    const node: EditorNode = { name: 'Div', props: { className: 'mx-2 w-[480px]' } };
    expect(reverseResolve(node, widthArbitrary)).toEqual({ value: '480px', matched: true });
  });

  it('매칭 토큰/스타일이 없으면 matched:false (고급값 분류)', () => {
    const node: EditorNode = { name: 'H1', props: { className: 'custom-pipe-class' } };
    expect(reverseResolve(node, textAlign)).toEqual({ value: undefined, matched: false });
    const node2: EditorNode = { name: 'P' };
    expect(reverseResolve(node2, textColor)).toEqual({ value: undefined, matched: false });
  });

  it('같은 group 토큰이 2개 이상이면 conflict:true (첫 매칭 우선)', () => {
    const node: EditorNode = { name: 'H1', props: { className: 'text-left text-right' } };
    const r = reverseResolve(node, textAlign);
    expect(r.matched).toBe(true);
    expect(r.conflict).toBe(true);
    expect(r.value).toBe('left');
  });
});

describe('applyRecipe ↔ reverseResolve 라운드트립 대칭', () => {
  const cases: Array<{ name: string; control: EditorControlSpec; value: unknown }> = [
    { name: 'classToken 택1', control: textAlign, value: 'center' },
    { name: 'styleProp', control: textColor, value: '#123456' },
    { name: 'cssVar', control: brandVar, value: '#654321' },
    { name: 'propValue', control: widthProp, value: 'xl' },
    { name: 'tokenTemplate', control: widthArbitrary, value: '50%' },
  ];

  for (const c of cases) {
    it(`${c.name} — apply 후 reverseResolve 가 같은 값을 돌려준다`, () => {
      const applied = applyRecipe({ name: 'X' }, c.control, c.value);
      const resolved = reverseResolve(applied, c.control);
      expect(resolved.matched).toBe(true);
      expect(resolved.value).toBe(c.value);
    });
  }
});

describe('입력 불변성', () => {
  it('applyRecipe 는 입력 노드를 변경하지 않는다', () => {
    const node: EditorNode = { name: 'H1', props: { className: 'text-left', style: { color: '#000' } } };
    const snapshot = JSON.stringify(node);
    applyRecipe(node, textAlign, 'center');
    applyRecipe(node, textColor, '#fff');
    expect(JSON.stringify(node)).toBe(snapshot);
  });
});

// ============================================================================
// 7 — dimension 위젯 + control-level apply 폴백
//
// 회귀: width/height 컨트롤이 `options`(per-option apply 없음) + control-level
// `apply: styleProp` 형태였을 때, applyRecipe 가 옵션 apply 만 보고 control-level
// apply 를 무시해 **아무 것도 적용되지 않던** 결함(리사이즈/모달 모두 무반응).
// 본 폴백으로 자유값/프리셋 모두 control-level apply 로 적용된다.
// ============================================================================
describe('/7 — dimension width/height (options + control-level apply 폴백)', () => {
  const widthCtrl: EditorControlSpec = {
    widget: 'dimension',
    group: 'width',
    apply: { type: 'styleProp', prop: 'width' },
    options: [
      { value: '100%' },
      { value: '50%' },
    ],
  };

  it('자유 픽셀 값(옵션에 없는 320px) → control-level styleProp 으로 style.width 적용', () => {
    const out = applyRecipe({ name: 'Button' }, widthCtrl, '320px');
    expect((out.props?.style as Record<string, unknown>)?.width).toBe('320px');
  });

  it('프리셋 칩 값(옵션에 있는 100%) → 옵션 apply 부재 시 control-level styleProp 으로 적용', () => {
    const out = applyRecipe({ name: 'Button' }, widthCtrl, '100%');
    expect((out.props?.style as Record<string, unknown>)?.width).toBe('100%');
  });

  it('리사이즈 px 문자열 → reverseResolve 가 동일 값 역해석 (양방향 동기)', () => {
    const applied = applyRecipe({ name: 'Button' }, widthCtrl, '248px');
    const resolved = reverseResolve(applied, widthCtrl);
    expect(resolved.matched).toBe(true);
    expect(resolved.value).toBe('248px');
  });

  it('기본(빈값) → style.width 제거', () => {
    const applied = applyRecipe({ name: 'Button', props: { style: { width: '320px' } } }, widthCtrl, undefined);
    expect((applied.props?.style as Record<string, unknown> | undefined)?.width).toBeUndefined();
  });

  it('per-option apply 가 있는 컨트롤은 폴백 영향 없음 (옵션 apply 우선)', () => {
    const seg: EditorControlSpec = {
      widget: 'segmented',
      group: 'ta',
      apply: { type: 'styleProp', prop: 'textAlign' }, // control-level (있어도)
      options: [{ value: 'c', apply: { type: 'classToken', tokens: ['text-center'] } }],
    };
    const out = applyRecipe({ name: 'P' }, seg, 'c');
    // 옵션 apply(classToken) 가 우선 — control-level styleProp 미적용
    expect(out.props?.className).toBe('text-center');
    expect(out.props?.style).toBeUndefined();
  });
});

// ============================================================================
// propValue 로 임의 비-스타일 prop 편집
//
// icon-picker(아이콘명 문자열)/options-list(옵션 객체 배열)/text/select/toggle 등
// 속성 탭 위젯이 공유하는 propValue 경로의 round-trip 을 전수 가드한다. 단계 0 의
// propValue 프리미티브가 이미 동작하나, 단계 1-a 위젯이 다루는 **문자열/배열/불리언/
// 빈값 삭제** 케이스를 명시적으로 잠근다.
// ============================================================================
describe('propValue 임의 prop 편집 (icon-picker / options-list / text)', () => {
  const iconName: EditorControlSpec = {
    widget: 'icon-picker',
    apply: { type: 'propValue', propKey: 'name' },
  };
  const optionsCtrl: EditorControlSpec = {
    widget: 'options-list',
    apply: { type: 'propValue', propKey: 'options' },
  };
  const requiredCtrl: EditorControlSpec = {
    widget: 'toggle',
    apply: { type: 'propValue', propKey: 'required' },
  };

  it('icon-picker 아이콘명 문자열을 props.name 에 기록 + 역해석', () => {
    const applied = applyRecipe({ name: 'Icon' }, iconName, 'fa-star');
    expect(applied.props?.name).toBe('fa-star');
    expect(reverseResolve(applied, iconName)).toEqual({ value: 'fa-star', matched: true });
  });

  it('options-list 옵션 배열을 props.options 에 기록 + 역해석 (배열 round-trip)', () => {
    const options = [
      { value: 'a', label: 'A' },
      { value: 'b', label: 'B' },
    ];
    const applied = applyRecipe({ name: 'Select' }, optionsCtrl, options);
    expect(applied.props?.options).toEqual(options);
    const resolved = reverseResolve(applied, optionsCtrl);
    expect(resolved.matched).toBe(true);
    expect(resolved.value).toEqual(options);
  });

  it('toggle 불리언 prop 을 props.required 에 기록 + 역해석', () => {
    const applied = applyRecipe({ name: 'Input' }, requiredCtrl, true);
    expect(applied.props?.required).toBe(true);
    expect(reverseResolve(applied, requiredCtrl)).toEqual({ value: true, matched: true });
  });

  it('빈 문자열/undefined 는 prop 을 삭제한다 (기본/미적용 복원)', () => {
    const seeded: EditorNode = { name: 'Icon', props: { name: 'fa-star' } };
    expect(applyRecipe(seeded, iconName, '').props?.name).toBeUndefined();
    expect(applyRecipe(seeded, iconName, undefined).props?.name).toBeUndefined();
    // 빈 배열도 propValue 빈값 규칙으로 삭제 — applyRecipe 는 빈 배열을 비-빈값으로 보므로
    // 위젯(OptionsListControl)이 length===0 시 undefined 를 넘긴다. 여기선 undefined 경로 확인.
    const seededOpts: EditorNode = { name: 'Select', props: { options: [{ value: 'a' }] } };
    expect(applyRecipe(seededOpts, optionsCtrl, undefined).props?.options).toBeUndefined();
  });

  it('propValue 편집은 다크 scope 에서 no-op (인라인 무손실 보존)', () => {
    const node: EditorNode = { name: 'Icon', props: { name: 'fa-star' } };
    const out = applyRecipe(node, iconName, 'fa-heart', { colorScheme: 'dark', breakpoint: 'base' });
    // 다크 scope 인라인(propValue) 은 short-circuit 으로 원본 반환 (바이트 동일).
    expect(out).toBe(node);
  });
});

// ============================================================================
// image 위젯 × 단일 값 슬롯 — 값 축약 / 역조립 (공개 #135)
//
// `image` 위젯은 배경 이미지용으로 설계되어 `{url,size,repeat,position}` **객체**를
// 내보낸다. 그 값을 노드에 기록하는 apply 4종 중 `styleProp` 의 다중 props(배경 묶음)
// 만 객체를 4속성으로 분해했고, `propValue`·`cssVar`·단일 `styleProp` 은 객체를 그대로
// 기록했다 → 소비 컴포넌트가 `<Img src={객체}>` 로 받아 `[object Object]` 가 URL 이 됐다.
//
// 게이트는 **위젯 이름**(`widget === 'image'`)이며 값 형태 sniffing 이 아니다 —
// `isImageValueObject` 는 4키 중 하나만 있어도 참이라, 값만 보면 `{position:'left'}`
// 같은 정당한 객체 prop 을 이미지로 오인해 삭제한다(1-6/1-7 이 그 가드다).
// ============================================================================
describe('image 위젯 × 단일 값 슬롯 (공개 #135)', () => {
  /** 실물 `hdrLogo` — sirsoft-basic 헤더 「로고 이미지」 */
  const logoCtrl: EditorControlSpec = {
    widget: 'image',
    apply: { type: 'propValue', propKey: 'logo' },
  };
  const bgBundle: EditorControlSpec = {
    widget: 'image',
    group: 'bg-image',
    apply: {
      type: 'styleProp',
      props: ['backgroundImage', 'backgroundSize', 'backgroundRepeat', 'backgroundPosition'],
    },
  };

  /** @effects image_object_narrows_to_url_string_in_propvalue_slot */
  it('1-1 값 객체를 url 문자열로 축약해 props 에 기록한다', () => {
    const next = applyRecipe({ name: 'Header' }, logoCtrl, {
      url: '/a.png',
      size: 'cover',
      repeat: 'no-repeat',
      position: 'center',
    });
    expect(next.props?.logo).toBe('/a.png');
    expect(typeof next.props?.logo).toBe('string');
  });

  /** @effects legacy_css_url_wrapping_is_stripped_for_component_prop_sink */
  it('1-2 레거시 url(...) 래핑을 벗긴다 (컴포넌트 prop 은 CSS 문맥이 아니다)', () => {
    const next = applyRecipe({ name: 'Header' }, logoCtrl, { url: 'url(/a.png)' });
    expect(next.props?.logo).toBe('/a.png');
  });

  /** @effects image_object_without_url_or_with_empty_url_deletes_the_prop */
  it('1-3 url 없이 모드만 담긴 값은 prop 을 삭제한다 (기존 빈값 술어에 위임)', () => {
    const seeded: EditorNode = { name: 'Header', props: { logo: '/old.png' } };
    expect(applyRecipe(seeded, logoCtrl, { size: 'contain' }).props?.logo).toBeUndefined();
  });

  it('1-4 빈 url 은 prop 을 삭제한다', () => {
    expect(applyRecipe({ name: 'Header' }, logoCtrl, { url: '' }).props?.logo).toBeUndefined();
  });

  it('1-5 undefined(기본으로 되돌리기) 는 prop 을 삭제한다', () => {
    const seeded: EditorNode = { name: 'Header', props: { logo: '/old.png' } };
    expect(applyRecipe(seeded, logoCtrl, undefined).props?.logo).toBeUndefined();
  });

  /** @effects non_image_widget_object_and_array_props_are_preserved_intact */
  it('1-6 오탐 가드 — 비-image 위젯의 객체 prop 은 통째로 보존한다', () => {
    // `{position:'left'}` 는 isImageValueObject 를 통과한다(4키 중 하나 보유).
    // 값 sniffing 으로 게이트했다면 여기서 정당한 prop 이 삭제됐을 것이다.
    const tooltipCtrl: EditorControlSpec = {
      widget: 'select',
      apply: { type: 'propValue', propKey: 'tooltip' },
    };
    const next = applyRecipe({ name: 'Button' }, tooltipCtrl, { position: 'left' });
    expect(next.props?.tooltip).toEqual({ position: 'left' });
  });

  it('1-7 오탐 가드 — options-list 배열은 그대로 보존한다', () => {
    const optionsCtrl: EditorControlSpec = {
      widget: 'options-list',
      apply: { type: 'propValue', propKey: 'options' },
    };
    const next = applyRecipe({ name: 'Select' }, optionsCtrl, [{ value: 'a' }]);
    expect(next.props?.options).toEqual([{ value: 'a' }]);
  });

  /** @effects image_object_narrows_to_wrapped_css_url_in_cssvar_and_single_styleprop */
  it('1-8 cssVar 슬롯은 CSS 문맥이므로 url(...) 로 감싼다', () => {
    const heroVar: EditorControlSpec = {
      widget: 'image',
      apply: { type: 'cssVar', varName: '--hero' },
    };
    const next = applyRecipe({ name: 'Div' }, heroVar, { url: '/a.png', size: 'cover' });
    expect((next.props?.style as Record<string, unknown>)['--hero']).toBe('url(/a.png)');
  });

  it('1-9 단일 styleProp 슬롯도 CSS 문맥이므로 url(...) 로 감싼다', () => {
    const singleBg: EditorControlSpec = {
      widget: 'image',
      apply: { type: 'styleProp', prop: 'backgroundImage' },
    };
    const next = applyRecipe({ name: 'Div' }, singleBg, { url: '/a.png', size: 'cover' });
    expect((next.props?.style as Record<string, unknown>).backgroundImage).toBe('url(/a.png)');
  });

  /** @effects styleprop_bundle_four_property_decomposition_is_unchanged */
  it('1-10 bundle(다중 props) 경로는 무회귀 — 4속성 분해가 그대로', () => {
    const value = { url: '/a.png', size: 'cover', repeat: 'no-repeat', position: 'center' };
    const style = applyRecipe({ name: 'Div' }, bgBundle, value).props?.style as Record<
      string,
      unknown
    >;
    expect(style).toEqual({
      backgroundImage: 'url(/a.png)',
      backgroundSize: 'cover',
      backgroundRepeat: 'no-repeat',
      backgroundPosition: 'center',
    });
  });

  /** @effects apply_fixed_value_wins_over_control_value_then_gets_narrowed */
  it('1-11 apply.value 고정값이 값보다 우선하고, 그 뒤에 축약이 적용된다', () => {
    const fixed: EditorControlSpec = {
      widget: 'image',
      apply: { type: 'propValue', propKey: 'logo', value: '/fixed.png' },
    };
    expect(applyRecipe({ name: 'Header' }, fixed, { url: '/x.png' }).props?.logo).toBe(
      '/fixed.png',
    );
  });

  /** @effects stored_string_is_rewrapped_as_url_object_for_the_widget */
  it('2-1 저장된 문자열을 위젯이 이해하는 객체로 되감는다', () => {
    const node: EditorNode = { name: 'Header', props: { logo: '/a.png' } };
    expect(reverseResolve(node, logoCtrl)).toEqual({ value: { url: '/a.png' }, matched: true });
  });

  it('2-2 값이 없으면 미매칭', () => {
    expect(reverseResolve({ name: 'Header', props: {} }, logoCtrl)).toEqual({
      value: undefined,
      matched: false,
    });
  });

  /** @effects binding_expression_is_rewrapped_so_one_click_upload_cannot_silently_lose_it */
  it('2-3 표현식 문자열도 감싼다 — 빈 피커로 보여 1클릭에 소실되지 않도록', () => {
    const expr = '{{_global.settings?.general?.site_logo_url}}';
    const node: EditorNode = { name: 'Header', props: { logo: expr } };
    expect(reverseResolve(node, logoCtrl)).toEqual({ value: { url: expr }, matched: true });
  });

  it('2-4 레거시 url(...) 저장값은 언래핑해 되감는다', () => {
    const node: EditorNode = { name: 'Header', props: { logo: 'url(/a.png)' } };
    expect(reverseResolve(node, logoCtrl)).toEqual({ value: { url: '/a.png' }, matched: true });
  });

  /** @effects legacy_object_stored_value_is_returned_losslessly */
  it('2-5 레거시 객체 저장값은 무손실로 그대로 돌려준다', () => {
    const legacy = { url: '/a.png', size: 'cover' };
    const node: EditorNode = { name: 'Header', props: { logo: legacy } };
    expect(reverseResolve(node, logoCtrl)).toEqual({ value: legacy, matched: true });
  });

  /** @effects non_image_widget_values_are_not_rewrapped */
  it('2-6 비-image 위젯은 감싸지 않는다 (문자열 그대로)', () => {
    const widthProp: EditorControlSpec = {
      widget: 'select',
      apply: { type: 'propValue', propKey: 'size' },
    };
    const node: EditorNode = { name: 'Button', props: { size: 'sm' } };
    expect(reverseResolve(node, widthProp)).toEqual({ value: 'sm', matched: true });
  });

  /** @effects node_axis_roundtrip_is_a_fixed_point_after_first_pass */
  it('2-7 노드 축 고정점 — apply → reverse → apply 2회차가 1회차와 동일', () => {
    const first = applyRecipe({ name: 'Header' }, logoCtrl, {
      url: '/a.png',
      size: 'cover',
      repeat: 'no-repeat',
      position: 'center',
    });
    const back = reverseResolve(first, logoCtrl);
    const second = applyRecipe(first, logoCtrl, back.value);
    expect(second).toEqual(first);
  });

  /** @effects dark_scope_stays_readonly_for_inline_apply */
  it('2-8 다크 scope 는 인라인이라 읽기 전용', () => {
    const node: EditorNode = { name: 'Header', props: { logo: '/a.png' } };
    expect(reverseResolve(node, logoCtrl, { colorScheme: 'dark', breakpoint: 'base' })).toEqual({
      value: undefined,
      matched: false,
      darkReadonly: true,
    });
  });
});

// ============================================================================
// number 위젯 값 — propValue 숫자 기록 (A1)
//
// `applyPropValue` 는 값을 가공하지 않으므로 위젯이 `number` 를 내보내야 한다.
// `0` 은 유효값이며 빈값 술어(`''|null|undefined`)에 걸리지 않는다.
// ============================================================================
describe('number 위젯 값 — propValue 숫자 기록', () => {
  const maxBoards: EditorControlSpec = {
    widget: 'number',
    apply: { type: 'propValue', propKey: 'maxVisibleBoards' },
  };

  it('4-1 숫자를 그대로 props 에 기록한다 (문자열로 변질되지 않음)', () => {
    const next = applyRecipe({ name: 'Header' }, maxBoards, 5);
    expect(next.props?.maxVisibleBoards).toBe(5);
    expect(typeof next.props?.maxVisibleBoards).toBe('number');
  });

  it('4-2 0 은 유효값이라 삭제되지 않는다', () => {
    const next = applyRecipe({ name: 'Header' }, maxBoards, 0);
    expect(next.props?.maxVisibleBoards).toBe(0);
  });

  it('4-3 빈 문자열·undefined 는 prop 을 삭제한다', () => {
    const seeded: EditorNode = { name: 'Header', props: { maxVisibleBoards: 5 } };
    expect(applyRecipe(seeded, maxBoards, '').props?.maxVisibleBoards).toBeUndefined();
    expect(applyRecipe(seeded, maxBoards, undefined).props?.maxVisibleBoards).toBeUndefined();
  });

  it('4-4 라운드트립 — 숫자 그대로 역해석', () => {
    const applied = applyRecipe({ name: 'Header' }, maxBoards, 7);
    expect(reverseResolve(applied, maxBoards)).toEqual({ value: 7, matched: true });
    // 0 도 역해석된다(undefined 와 구분).
    const zero = applyRecipe({ name: 'Header' }, maxBoards, 0);
    expect(reverseResolve(zero, maxBoards)).toEqual({ value: 0, matched: true });
  });
});

// ============================================================================
// nodeKey apply — 노드 최상위 구조키 패치 (A2)
//
// `coreProps.ts` 가 `{type:'nodeKey', nodeKey:'dataKey'}` 를 선언했는데 엔진 switch 에
// case 가 없어 **무음 no-op** 이었다 — 값을 넣어도 아무 일도 일어나지 않고 역해석도
// 항상 undefined 였다. props 로 흘리면 `props.dataKey` 가 돼 런타임이 영영 읽지 않는다.
// ============================================================================
describe('nodeKey apply — 노드 최상위 구조키 (A2)', () => {
  const dataKeyCtrl: EditorControlSpec = {
    widget: 'core-datakey',
    apply: { type: 'nodeKey', nodeKey: 'dataKey' } as unknown as string,
  };

  it('5-1 노드 최상위에 기록하고 props 를 오염시키지 않는다', () => {
    const next = applyRecipe({ name: 'Form' }, dataKeyCtrl, 'orderer') as EditorNode &
      Record<string, unknown>;
    expect(next.dataKey).toBe('orderer');
    expect(next.props?.dataKey).toBeUndefined();
  });

  it('5-2 빈 문자열은 키를 삭제한다', () => {
    const seeded = { name: 'Form', dataKey: 'orderer' } as EditorNode;
    const next = applyRecipe(seeded, dataKeyCtrl, '') as EditorNode & Record<string, unknown>;
    expect('dataKey' in next).toBe(false);
  });

  it('5-3 undefined 도 키를 삭제한다', () => {
    const seeded = { name: 'Form', dataKey: 'orderer' } as EditorNode;
    const next = applyRecipe(seeded, dataKeyCtrl, undefined) as EditorNode &
      Record<string, unknown>;
    expect('dataKey' in next).toBe(false);
  });

  it('5-4 역해석 — 노드 최상위에서 읽는다', () => {
    const node = { name: 'Form', dataKey: 'orderer' } as EditorNode;
    expect(reverseResolve(node, dataKeyCtrl)).toEqual({ value: 'orderer', matched: true });
  });

  it('5-5 역해석 — 값이 없으면 미매칭', () => {
    expect(reverseResolve({ name: 'Form' }, dataKeyCtrl)).toEqual({
      value: undefined,
      matched: false,
    });
  });

  it('5-6 예약키 가드 — nodeKey:"children" 은 no-op (노드 파괴 차단)', () => {
    const evil: EditorControlSpec = {
      widget: 'text',
      apply: { type: 'nodeKey', nodeKey: 'children' } as unknown as string,
    };
    const node: EditorNode = { name: 'Div', children: [{ name: 'Span' }] };
    const out = applyRecipe(node, evil, 'boom');
    expect(out).toBe(node); // 참조 동일 — 사본조차 만들지 않는다
    expect(node.children).toEqual([{ name: 'Span' }]);
    expect(reverseResolve(node, evil)).toEqual({ value: undefined, matched: false });
  });

  it('5-7 디바이스 scope 도 최상위에 쓴다 (responsive 브랜치는 런타임이 안 읽는다)', () => {
    const next = applyRecipe({ name: 'Form' }, dataKeyCtrl, 'orderer', {
      colorScheme: 'light',
      breakpoint: 'mobile',
    }) as EditorNode & Record<string, unknown>;
    expect(next.dataKey).toBe('orderer');
    expect(next.responsive).toBeUndefined();
  });

  it('5-8 디바이스 scope 역해석도 최상위에서 — placeholder 흐림을 만들지 않는다', () => {
    const node = { name: 'Form', dataKey: 'orderer' } as EditorNode;
    expect(
      reverseResolve(node, dataKeyCtrl, { colorScheme: 'light', breakpoint: 'mobile' }),
    ).toEqual({ value: 'orderer', matched: true });
  });

  it('5-9 다크 scope 는 no-op (인라인 무손실 보존)', () => {
    const node = { name: 'Form', dataKey: 'orderer' } as EditorNode;
    expect(applyRecipe(node, dataKeyCtrl, 'other', { colorScheme: 'dark', breakpoint: 'base' })).toBe(
      node,
    );
  });

  it('5-10 입력 노드는 변경되지 않는다 (불변)', () => {
    const node = { name: 'Form' } as EditorNode;
    const snapshot = JSON.stringify(node);
    applyRecipe(node, dataKeyCtrl, 'orderer');
    expect(JSON.stringify(node)).toBe(snapshot);
  });

  it('5-11 비-문자열/빈 nodeKey 는 no-op', () => {
    const bad: EditorControlSpec = {
      widget: 'text',
      apply: { type: 'nodeKey', nodeKey: 42 } as unknown as string,
    };
    const node: EditorNode = { name: 'Form' };
    expect(applyRecipe(node, bad, 'x')).toBe(node);
    const empty: EditorControlSpec = {
      widget: 'text',
      apply: { type: 'nodeKey', nodeKey: '' } as unknown as string,
    };
    expect(applyRecipe(node, empty, 'x')).toBe(node);
  });
});
