/**
 * expressionValueTree.test.ts — 표현식 분해 트리 파서/직렬화 골든
 *
 *  검증: 양 템플릿 전 레이아웃에서 추출한 실표본(F/G 134 + concat 29)으로 round-trip
 * 골든 테스트(파싱→직렬화 의미 동일). **손상 0 원칙**: 모든 실표본은 둘 중 하나여야 한다 —
 *  (a) 분해 성공 시 직렬화 식이 원문과 의미 동일(exprEquivalent),
 *  (b) 분해 실패 시 raw 노드로 원문 보존(절대 의미 변경 없음).
 *
 * 추가: shape 분류(A~G)·조건 파서(단순/복합)·디그레이드 안전 단위.
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect } from 'vitest';
import {
  parseExpressionValue,
  serializeValueNode,
  classifyValueShape,
  parseConditionFromExpr,
  exprEquivalent,
  hasDecomposableExpressionSegment,
  previewSegments,
  seedExpressionFromPlain,
  seedFallbackFromValue,
  seedConcatFromValue,
  extractFirstLeafText,
  reduceExpressionToPlain,
  type ValueNode,
} from '../../spec/expressionValueTree';
import { bindingChipLabel } from '../../spec/inlineBindingUtils';
import corpus from './expressionValueTree.corpus.json';

/** 트리 내 모든 리프가 의미를 잃지 않았는지(분해 성공 표본의 직렬화 비교는 호출부에서) */
function leafTexts(node: ValueNode): string[] {
  switch (node.kind) {
    case 'leaf':
      return [node.text];
    case 'raw':
      return [];
    case 'concat':
      return node.parts.flatMap(leafTexts);
    case 'fallback':
      return [...leafTexts(node.primary), ...leafTexts(node.fallback)];
    case 'conditional':
      return [...leafTexts(node.then), ...leafTexts(node.else)];
    default:
      return [];
  }
}

describe('expressionValueTree — round-trip 골든(실표본 손상 0)', () => {
  const allSamples: string[] = [...corpus.fg, ...corpus.concat];

  it('실표본 추출 건수 확인(회귀 가드)', () => {
    expect(corpus.fg.length).toBeGreaterThanOrEqual(130);
    expect(corpus.concat.length).toBeGreaterThanOrEqual(25);
  });

  // 손상 0 — 각 실표본은 (a) 분해 성공+의미동일 또는 (b) raw 폴백+원문보존.
  it.each(allSamples)('손상 0 round-trip: %s', (sample) => {
    const parsed = parseExpressionValue(sample);
    const inner = sample.trim().replace(/^\{\{/, '').replace(/\}\}$/, '').trim();

    if (parsed.decomposed) {
      // (a) 분해 성공 — 직렬화 식이 원문 안쪽 식과 의미 동일.
      const reExpr = serializeValueNode(parsed.node, true);
      expect(exprEquivalent(inner, reExpr)).toBe(true);
      // node.text 형태 직렬화도 원문과 의미 동일(전체 래핑).
      const reText = serializeValueNode(parsed.node, false);
      expect(exprEquivalent(sample, reText)).toBe(true);
    } else {
      // (b) 분해 실패 — raw 노드로 원문 보존(직렬화가 원문 안쪽 식 복원).
      expect(['raw', 'leaf']).toContain(parsed.node.kind);
      const reText = serializeValueNode(parsed.node, false);
      // raw 폴백은 원문과 의미 동일해야(손상 0). leaf(단일 바인딩 D)도 동일.
      expect(exprEquivalent(sample, reText)).toBe(true);
    }
  });

  it('분해 성공 표본은 1건 이상(파서가 무력하지 않음을 보장)', () => {
    const decomposed = corpus.fg.filter((s) => parseExpressionValue(s).decomposed);
    // F/G 134건 중 단순/중첩 삼항·`??` 다수가 분해돼야 함(단순삼항 54% 등).
    expect(decomposed.length).toBeGreaterThan(60);
  });

  it('분해 성공 표본의 모든 리프는 raw `{{` 없는 값 또는 단일 바인딩(코드 통째 노출 0)', () => {
    for (const s of corpus.fg) {
      const parsed = parseExpressionValue(s);
      if (!parsed.decomposed) continue;
      for (const leaf of leafTexts(parsed.node)) {
        // 리프는 `$t:`·평문·단일 `{{경로}}` 만 — 삼항/`+`/`?:` 가 리프에 raw 로 남으면 안 됨.
        const isWrappedBinding = /^\{\{[^{}]*\}\}$/.test(leaf.trim());
        const isTKey = leaf.startsWith('$t:');
        const isPlain = !/\{\{/.test(leaf);
        expect(isWrappedBinding || isTKey || isPlain).toBe(true);
      }
    }
  });
});

describe('classifyValueShape — A~G 분류', () => {
  it('빈 값 → empty', () => {
    expect(classifyValueShape('')).toBe('empty');
    expect(classifyValueShape('   ')).toBe('empty');
    expect(classifyValueShape(null)).toBe('empty');
  });

  it('A 평문 → simple', () => {
    expect(classifyValueShape('안녕하세요')).toBe('simple');
  });

  it('B 단일 다국어키 → simple', () => {
    expect(classifyValueShape('$t:board.title')).toBe('simple');
  });

  it('C 다국어+칩 → simple', () => {
    expect(classifyValueShape('$t:board.greeting|p0={{user.name}}')).toBe('simple');
  });

  it('D 단일 바인딩 → simple(기존 위젯/readonly)', () => {
    expect(classifyValueShape('{{product.data.name}}')).toBe('simple');
    expect(classifyValueShape('{{product?.data?.name ?? \'\'}}')).toBe('simple');
  });

  it('E 평문+칩 → simple', () => {
    expect(classifyValueShape('회원 {{user.id}}')).toBe('simple');
  });

  it("F 표현식+다국어 → expression", () => {
    expect(classifyValueShape("{{route.id ? '$t:board.edit' : '$t:board.new'}}")).toBe('expression');
  });

  it('복잡 식(함수/산술) → raw', () => {
    expect(classifyValueShape('{{(items ?? []).reduce((a,b)=>a+b,0).toLocaleString()}}')).toBe('raw');
  });
});

describe('parseExpressionValue — 대표 구조 분해', () => {
  it('단순 삼항 — 조건 simple + 분기 2개 리프', () => {
    const r = parseExpressionValue("{{route.id ? '$t:board.edit' : '$t:board.new'}}");
    expect(r.decomposed).toBe(true);
    expect(r.node.kind).toBe('conditional');
    if (r.node.kind === 'conditional') {
      expect(r.node.condition.kind).toBe('simple');
      if (r.node.condition.kind === 'simple') {
        expect(r.node.condition.left).toBe('route.id');
        expect(r.node.condition.op).toBe('truthy');
      }
      expect(r.node.then).toEqual({ kind: 'leaf', text: '$t:board.edit' });
      expect(r.node.else).toEqual({ kind: 'leaf', text: '$t:board.new' });
    }
  });

  it('비교 조건 — left/op/right 구조화', () => {
    const r = parseExpressionValue(
      "{{_global.deleteModal.type === 'comment' ? '$t:a' : '$t:b'}}",
    );
    expect(r.decomposed).toBe(true);
    if (r.node.kind === 'conditional' && r.node.condition.kind === 'simple') {
      expect(r.node.condition.left).toBe('_global.deleteModal.type');
      expect(r.node.condition.op).toBe('===');
      expect(r.node.condition.right).toBe("'comment'");
    }
  });

  it('중첩 삼항 — then/else 재귀 conditional', () => {
    const r = parseExpressionValue(
      "{{_local?.isSaving ? '$t:common.saving' : (route?.id ? '$t:common.save' : '$t:board.form.submit')}}",
    );
    expect(r.decomposed).toBe(true);
    if (r.node.kind === 'conditional') {
      expect(r.node.then.kind).toBe('leaf');
      expect(r.node.else.kind).toBe('conditional');
    }
  });

  it('?? 폴백 — fallback 노드', () => {
    const r = parseExpressionValue("{{product.data?.common_info?.name ?? '$t:shop.product.common_info'}}");
    expect(r.decomposed).toBe(true);
    expect(r.node.kind).toBe('fallback');
    if (r.node.kind === 'fallback') {
      expect(r.node.op).toBe('??');
      expect(r.node.primary).toEqual({ kind: 'leaf', text: '{{product.data?.common_info?.name}}' });
      expect(r.node.fallback).toEqual({ kind: 'leaf', text: '$t:shop.product.common_info' });
    }
  });

  it('베어 $t: 키(따옴표 없음) 분기도 리프로', () => {
    const r = parseExpressionValue(
      '{{_global.identityChallenge?.render_hint === \'link\' ? $t:user.identity.challenge.resend_link : $t:user.identity.challenge.resend}}',
    );
    expect(r.decomposed).toBe(true);
    if (r.node.kind === 'conditional') {
      expect(r.node.then).toEqual({ kind: 'leaf', text: '$t:user.identity.challenge.resend_link' });
      expect(r.node.else).toEqual({ kind: 'leaf', text: '$t:user.identity.challenge.resend' });
    }
  });

  it('단순 이어붙이기 — concat 노드', () => {
    const r = parseExpressionValue("{{'[' + coupon.localized_name + ']'}}");
    expect(r.decomposed).toBe(true);
    expect(r.node.kind).toBe('concat');
    if (r.node.kind === 'concat') {
      expect(r.node.parts.length).toBe(3);
    }
  });

  // 회귀 — 분기 리프가 G 형태(다국어 키 + 데이터 칩, `$t:key|p0={{x ?? ''}}`)
  // 면, 분기 문자열 리터럴 안의 칩 보간(`{{... ?? ''}}`)에 든 따옴표가 토크나이저의 문자열 경계를
  // 깨뜨려 분해 실패(raw 디그레이드)하던 결함. 문자열 안의 `{{...}}` 는 통째 보존(내부 따옴표 무시).
  it('G 분기 리프(다국어 키 + 데이터 칩) 삼항도 분해 — 칩 폴백 따옴표가 경계 깨지 않음', () => {
    const r = parseExpressionValue(
      "{{route.id ? '$t:custom.board_form.3|p0={{current_user?.data?.id ?? ''}}' : '$t:board.new_post'}}",
    );
    expect(r.decomposed).toBe(true);
    expect(r.node.kind).toBe('conditional');
    if (r.node.kind === 'conditional') {
      // 참 분기 = G 리프(키+칩) 통째 보존.
      expect(r.node.then).toEqual({ kind: 'leaf', text: "$t:custom.board_form.3|p0={{current_user?.data?.id ?? ''}}" });
      expect(r.node.else).toEqual({ kind: 'leaf', text: '$t:board.new_post' });
    }
  });

  it('G 분기 리프 — 칩 안에 따옴표 폴백 여러 개여도 분해', () => {
    const r = parseExpressionValue(
      "{{x ? '$t:a|p0={{u.name ?? ''}}|p1={{u.email ?? ''}}' : '$t:b'}}",
    );
    expect(r.decomposed).toBe(true);
    expect(r.node.kind).toBe('conditional');
  });
});

describe('parseConditionFromExpr — 단순 비교만 구조화', () => {
  it('존재여부 — truthy', () => {
    expect(parseConditionFromExpr('route.id')).toEqual({ kind: 'simple', left: 'route.id', op: 'truthy', right: '' });
  });
  it('부정 — falsy', () => {
    expect(parseConditionFromExpr('!route.id')).toEqual({ kind: 'simple', left: 'route.id', op: 'falsy', right: '' });
  });
  it('=== 리터럴 비교', () => {
    expect(parseConditionFromExpr("type === 'comment'")).toEqual({
      kind: 'simple', left: 'type', op: '===', right: "'comment'",
    });
  });
  it('옵셔널 체이닝 경로도 simple', () => {
    const c = parseConditionFromExpr('_local?.isSaving');
    expect(c.kind).toBe('simple');
    if (c.kind === 'simple') expect(c.left).toBe('_local?.isSaving');
  });
  it('논리 연산(&&/||) → raw(코드에서 수정)', () => {
    expect(parseConditionFromExpr('a && b').kind).toBe('raw');
    expect(parseConditionFromExpr('a || b').kind).toBe('raw');
  });
  it('함수 호출 조건 → raw', () => {
    expect(parseConditionFromExpr('(items ?? []).filter(c => c.x).length > 0').kind).toBe('raw');
  });
});

describe('디그레이드 — 손상 0 폴백', () => {
  it('함수 호출/IIFE → raw 노드, 원문 보존', () => {
    const src = '{{(function() { return x; })()}}';
    const r = parseExpressionValue(src);
    expect(r.decomposed).toBe(false);
    expect(r.node.kind).toBe('raw');
    expect(exprEquivalent(src, serializeValueNode(r.node, false))).toBe(true);
  });

  it('산술/reduce → raw', () => {
    const src = '{{items.reduce((a,b) => a + b.price, 0)}}';
    const r = parseExpressionValue(src);
    expect(r.decomposed).toBe(false);
    expect(r.node.kind).toBe('raw');
  });

  it('A~E 비식 값은 decomposed:false leaf(기존 위젯 위임)', () => {
    for (const v of ['평문', '$t:x', '$t:x|p0={{y}}', '{{a.b}}', '회원 {{u.id}}']) {
      const r = parseExpressionValue(v);
      expect(r.decomposed).toBe(false);
      expect(r.node.kind).toBe('leaf');
      expect(serializeValueNode(r.node, false)).toBe(v);
    }
  });
});

describe('hasDecomposableExpressionSegment — 다중 세그먼트 + 표현식 조각', () => {
  // 양 템플릿 실측 9건(다중 세그먼트 + 진짜 삼항 분기 토큰) — PO board/form 제목 케이스 포함.
  const REAL_MULTISEGMENT = [
    "{{route.id ? '$t:board.edit_post' : '$t:board.new_post'}} - {{form_meta?.data?.board?.name || ''}}",
    "{{_local.collapsedReplies?.[comment?.id] === false ? '$t:board.hide_replies' : '$t:board.show_replies'}} ({{comment?.replies_count}})",
    "({{template.type === 'admin' ? '$t:admin.templates.tabs.admin' : '$t:admin.templates.tabs.user'}})",
  ];

  it.each(REAL_MULTISEGMENT)('다중 세그먼트 + 표현식 조각 → true: %s', (v) => {
    expect(hasDecomposableExpressionSegment(v)).toBe(true);
  });

  it('단일 `{{식}}`(설명 케이스) → false(ConditionalValueEditor 직접)', () => {
    expect(hasDecomposableExpressionSegment("{{route.id ? '$t:edit' : '$t:new'}}")).toBe(false);
  });

  it('평문/단일키/D/E → false(기존 위젯)', () => {
    expect(hasDecomposableExpressionSegment('평문')).toBe(false);
    expect(hasDecomposableExpressionSegment('$t:x')).toBe(false);
    expect(hasDecomposableExpressionSegment('{{a.b}}')).toBe(false);
    expect(hasDecomposableExpressionSegment('회원 {{u.id}}')).toBe(false); // E — 표현식 아님
    expect(hasDecomposableExpressionSegment('')).toBe(false);
  });

  it('다중 세그먼트지만 표현식 조각 없음(전부 D/E) → false', () => {
    // `{{x ?? ''}} × {{y}}` 는 폴백+단일바인딩 = 표현식 분해 대상 아님(칩 위젯 소관).
    expect(hasDecomposableExpressionSegment("{{item.price ?? ''}} × {{item.qty}}")).toBe(false);
  });
});

describe('previewSegments — 접힌 미리보기 토큰("한 값만 해석")', () => {
  // 키 해석기 — `board.edit_post`→"게시글 수정" 등.
  const resolve = (k: string): string =>
    ({ 'board.edit_post': '게시글 수정', 'board.new_post': '게시글 작성' }[k] ?? '');
  const chipLabel = (b: string): string => b.replace(/[{}]/g, '').replace(/\?\./g, '.').split('.').pop() ?? b;

  it('조건 분기 → 참 분기 해석값 + ⋯(한 값만)', () => {
    const toks = previewSegments("{{route.id ? '$t:board.edit_post' : '$t:board.new_post'}}", resolve, chipLabel);
    expect(toks[0]).toEqual({ kind: 'text', text: '게시글 수정' });
    expect(toks[1]).toEqual({ kind: 'ellipsis', text: '⋯' });
  });

  it('PO board/form 다중 세그먼트 → 게시글 수정⋯ + 평문 + 데이터 칩', () => {
    const toks = previewSegments(
      "{{route.id ? '$t:board.edit_post' : '$t:board.new_post'}} - {{form_meta?.data?.board?.name || ''}}",
      resolve,
      chipLabel,
    );
    // 조건 첫 분기 해석 + ⋯ + 평문 " - " + 데이터 칩.
    expect(toks.find((t) => t.kind === 'text' && t.text === '게시글 수정')).toBeTruthy();
    expect(toks.find((t) => t.kind === 'ellipsis')).toBeTruthy();
    expect(toks.find((t) => t.kind === 'text' && t.text === ' - ')).toBeTruthy();
    expect(toks.find((t) => t.kind === 'chip')).toBeTruthy();
  });

  it('단일 데이터 바인딩 → 칩', () => {
    const toks = previewSegments('{{user.email}}', resolve, chipLabel);
    expect(toks).toEqual([{ kind: 'chip', text: 'email' }]);
  });

  it('평문 → 텍스트 그대로', () => {
    expect(previewSegments('안녕', resolve, chipLabel)).toEqual([{ kind: 'text', text: '안녕' }]);
  });

  it('빈 값 → 빈 배열', () => {
    expect(previewSegments('', resolve, chipLabel)).toEqual([]);
  });
});

// SEO 다국어 추출 함수(`$localized`) 칩 — 검색엔진 탭 페이지 설정에서 SEO 메타값을 입력하면
// 그 값이 `{{$localized(product.data.meta_title) ?? $localized(product.data.name) ?? ''}}`
// 형태로 저장된다. 종전엔 함수 호출이라 raw(편집 잠금/식 문자열 노출)로 떨어져 편집기가
// 친화 데이터 칩을 못 보였다. 파서가 `$localized(<경로>)` 를 단일 바인딩
// 리프로 인지해 분해/칩 라벨을 복원하는지 회귀 가드.
describe('expressionValueTree — SEO $localized 추출 함수 칩 분해', () => {
  // 실제 bindingChipLabel SSoT 로 칩 라벨까지 검증(친화 라벨 = 인자 경로 path).
  const chipViaSSoT = (b: string): string => bindingChipLabel(b);

  it('단일 $localized(<경로>) → 데이터 칩(인자 경로 라벨)', () => {
    const r = parseExpressionValue('{{$localized(product.data.meta_title)}}');
    // 단일 바인딩 리프 — 기존 D(칩) 위젯 경로(decomposed:false, leaf 1개).
    expect(r.node.kind).toBe('leaf');
    expect(classifyValueShape('{{$localized(product.data.meta_title)}}')).toBe('simple');
    // 칩 라벨은 인자 경로(meta_title) — raw `$localized(...)` 식 미노출.
    const toks = previewSegments('{{$localized(product.data.meta_title)}}', () => '', chipViaSSoT);
    expect(toks).toEqual([{ kind: 'chip', text: 'data.meta_title' }]);
  });

  it('meta 우선/name 폴백 체인 → fallback 트리로 분해(각 분기 $localized 칩)', () => {
    const expr = "{{$localized(product.data.meta_title) ?? $localized(product.data.name) ?? ''}}";
    const r = parseExpressionValue(expr);
    expect(r.decomposed).toBe(true);
    expect(r.node.kind).toBe('fallback');
    // 직렬화 → 원문 의미 동일(round-trip 자기검증 통과 = 손상 0).
    expect(exprEquivalent(expr, serializeValueNode(r.node, true))).toBe(true);
    // 첫 결과(primary 체인 좌단) 칩은 meta_title 경로.
    const toks = previewSegments(expr, () => '', chipViaSSoT);
    expect(toks.some((t) => t.kind === 'chip' && t.text === 'data.meta_title')).toBe(true);
  });

  it('미등록 함수 호출(Math.max 등)은 종전대로 raw(손상 0)', () => {
    expect(classifyValueShape('{{Math.max(product.data.a, 0)}}')).toBe('raw');
    const r = parseExpressionValue('{{Math.max(product.data.a, 0)}}');
    expect(r.decomposed).toBe(false);
    expect(r.node.kind).toBe('raw');
  });
});

// 모드 전환 — 일반 이름 ↔ 표현식
//  · 일반→표현식: 현재 값을 then 분기로 넣은 조건 노드 seed(else 빈 칸). 곧바로 분해 빌더가 열림.
//  · 표현식→일반: 첫 결과 분기 리프 텍스트(원본 `$t:키`/평문/칩 보존) 추출.
describe('seedExpressionFromPlain — 일반 이름 → 조건 분기 표현식 승격', () => {
  it('$t:키 값 → then 분기에 키 보존(else 빈 칸), 곧바로 분해 가능', () => {
    const seeded = seedExpressionFromPlain('$t:board.title');
    // 조건 분기 식으로 — then=현재 키, else 빈 칸, 조건 route.id 존재여부.
    const parsed = parseExpressionValue(seeded);
    expect(parsed.decomposed).toBe(true);
    if (parsed.node.kind !== 'conditional') throw new Error('expected conditional');
    expect(parsed.node.then).toEqual({ kind: 'leaf', text: '$t:board.title' });
    expect(parsed.node.else).toEqual({ kind: 'leaf', text: '' });
    // 기준 값(조건 left)은 빈 채로 시작 — route.id 등 하드코딩 금지.
    expect(parsed.node.condition).toEqual({ kind: 'simple', left: '', op: 'truthy', right: '' });
  });

  it('평문 값 → then 분기에 평문 보존', () => {
    const seeded = seedExpressionFromPlain('안녕하세요');
    const parsed = parseExpressionValue(seeded);
    expect(parsed.decomposed).toBe(true);
    if (parsed.node.kind !== 'conditional') throw new Error('expected conditional');
    expect(parsed.node.then).toEqual({ kind: 'leaf', text: '안녕하세요' });
  });

  it('빈 값 → then/else 모두 빈 리프(빈 조건 분기 시작)', () => {
    const seeded = seedExpressionFromPlain('');
    const parsed = parseExpressionValue(seeded);
    expect(parsed.decomposed).toBe(true);
    if (parsed.node.kind !== 'conditional') throw new Error('expected conditional');
    expect(parsed.node.then).toEqual({ kind: 'leaf', text: '' });
    expect(parsed.node.else).toEqual({ kind: 'leaf', text: '' });
  });

  it('데이터 칩(`$t:키|pN={{}}`) 값 → then 분기에 칩 보존', () => {
    const seeded = seedExpressionFromPlain("$t:board.greeting|p0={{user.name}}");
    const parsed = parseExpressionValue(seeded);
    expect(parsed.decomposed).toBe(true);
    if (parsed.node.kind !== 'conditional') throw new Error('expected conditional');
    // round-trip: then 리프 텍스트가 칩 보존(칩의 `{{}}` 가 문자열 안에서 깨지지 않음).
    expect(parsed.node.then).toEqual({ kind: 'leaf', text: "$t:board.greeting|p0={{user.name}}" });
  });

  // 평문 + 데이터칩(`$t:` 키 아님, 예 엔드포인트 URL) 값을 표현식으로
  // 승격할 때, 칩(`{{route.id}}`)이 문자열 리터럴 안에 그대로 박혀 `{{false ? '/api/.../{{route.id}}'
  // : ''}}` 처럼 **중첩 `{{}}`** 가 만들어졌다(파서가 raw 로 떨궈 편집 잠금). 평문+칩은 따옴표 통째가
  // 아니라 **이어붙이기(`'평문' + route.id`)**로 분해해 칩을 표현식 항으로 보존해야 한다.
  it('평문 + 데이터칩(URL) 값 → 중첩 {{}} 없이 then 분기에 이어붙이기로 칩 보존', () => {
    const original = '/api/modules/sirsoft-ecommerce/products/{{route.id}}';
    const seeded = seedExpressionFromPlain(original);
    // 결함 재현 가드 — 직렬화 식에 중첩 보간(`{{ ... {{ ... }} ... }}`)이 없어야 한다.
    expect(seeded).not.toMatch(/\{\{[^}]*\{\{/);
    const parsed = parseExpressionValue(seeded);
    // raw(코드 잠금)로 떨어지지 않고 분해 가능해야 한다(편집 가능).
    expect(parsed.decomposed).toBe(true);
    if (parsed.node.kind !== 'conditional') throw new Error('expected conditional');
    // then 분기 = 평문 리프 + 데이터 바인딩 리프의 이어붙이기(칩 보존).
    expect(parsed.node.then).toEqual({
      kind: 'concat',
      parts: [
        { kind: 'leaf', text: '/api/modules/sirsoft-ecommerce/products/' },
        { kind: 'leaf', text: '{{route.id}}' },
      ],
    });
  });

  it('평문 + 데이터칩(혼합 한글) 값 → 이어붙이기로 평문/칩 보존', () => {
    const seeded = seedExpressionFromPlain('회원 {{user.name}} 님');
    expect(seeded).not.toMatch(/\{\{[^}]*\{\{/);
    const parsed = parseExpressionValue(seeded);
    expect(parsed.decomposed).toBe(true);
    if (parsed.node.kind !== 'conditional') throw new Error('expected conditional');
    expect(parsed.node.then).toEqual({
      kind: 'concat',
      parts: [
        { kind: 'leaf', text: '회원 ' },
        { kind: 'leaf', text: '{{user.name}}' },
        { kind: 'leaf', text: ' 님' },
      ],
    });
  });

  // 기준 값(조건 left)은 빈 채로 시작(route.id 하드코딩 금지). 빈 조건은
  // 중립 토큰 `false` 로 직렬화되고 round-trip 으로 빈 SimpleCondition 으로 복원된다(잠금 안 됨).
  it('빈 조건 seed → 직렬화/재파싱 round-trip 으로 빈 SimpleCondition 보존(잠금 아님)', () => {
    const seeded = seedExpressionFromPlain('$t:board.title');
    // 직렬화 식에 route.id 등 특정 경로 하드코딩이 없어야 한다.
    expect(seeded).not.toContain('route.id');
    const parsed = parseExpressionValue(seeded);
    expect(parsed.decomposed).toBe(true);
    if (parsed.node.kind !== 'conditional') throw new Error('expected conditional');
    // raw(코드에서 수정 잠금) 가 아니라 편집 가능한 빈 SimpleCondition.
    expect(parsed.node.condition).toEqual({ kind: 'simple', left: '', op: 'truthy', right: '' });
  });
});

// 폴백/이어붙이기 양식 추가 — 사용자가 표현식 편집기에서 모든 조합을 정의할 수 있어야.
// `[+폴백]` 추가 시 현재 값을 기본값(primary)으로, 비었을 때 대신(fallback)은 빈 칸으로 두는 `?? ''` 식을
// 시드한다. `[+이어붙이기]`(concat)는 현재 값 + 빈 조각으로 시드.
describe('seedFallbackFromValue — 값 → 폴백(?? 빈값) 양식 승격', () => {
  it('$t:키 값 → primary 분기에 키 보존(fallback 빈 칸), 곧바로 분해 가능', () => {
    const seeded = seedFallbackFromValue('$t:board.title');
    const parsed = parseExpressionValue(seeded);
    expect(parsed.decomposed).toBe(true);
    if (parsed.node.kind !== 'fallback') throw new Error('expected fallback');
    expect(parsed.node.op).toBe('??');
    expect(parsed.node.primary).toEqual({ kind: 'leaf', text: '$t:board.title' });
    expect(parsed.node.fallback).toEqual({ kind: 'leaf', text: '' });
  });

  it('데이터 바인딩 값 → primary 가 바인딩 리프 + fallback 빈 칸(데이터 없을 때 대신 편집 가능)', () => {
    // `{{board.name}}` 단일 바인딩에 폴백을 붙이면 D 변형(단일바인딩 ?? 리터럴)이 되지만, fallback 이
    // 빈 리터럴('')이 아니라 편집 가능해야 하므로 분해 트리로 떠야 한다. 빈 fallback 은 `''` 리터럴.
    const seeded = seedFallbackFromValue('{{board.name}}');
    // 시드 식에 `?? ''` 폴백이 들어있다.
    expect(seeded).toContain('??');
  });

  it('빈 값 → primary/fallback 모두 빈 리프', () => {
    const seeded = seedFallbackFromValue('');
    const parsed = parseExpressionValue(seeded);
    expect(parsed.decomposed).toBe(true);
    if (parsed.node.kind !== 'fallback') throw new Error('expected fallback');
    expect(parsed.node.primary).toEqual({ kind: 'leaf', text: '' });
    expect(parsed.node.fallback).toEqual({ kind: 'leaf', text: '' });
  });

  // 평문+데이터칩 값에 폴백을 붙일 때도 중첩 `{{}}` 가 생기면 안 된다.
  it('평문 + 데이터칩(URL) 값 → 중첩 {{}} 없이 primary 에 이어붙이기로 칩 보존', () => {
    const seeded = seedFallbackFromValue('/api/modules/sirsoft-ecommerce/products/{{route.id}}');
    expect(seeded).not.toMatch(/\{\{[^}]*\{\{/);
    const parsed = parseExpressionValue(seeded);
    expect(parsed.decomposed).toBe(true);
    if (parsed.node.kind !== 'fallback') throw new Error('expected fallback');
    expect(parsed.node.primary).toEqual({
      kind: 'concat',
      parts: [
        { kind: 'leaf', text: '/api/modules/sirsoft-ecommerce/products/' },
        { kind: 'leaf', text: '{{route.id}}' },
      ],
    });
  });
});

describe('seedConcatFromValue — 값 → 이어붙이기(+ 빈 조각) 양식 승격', () => {
  it('$t:키 값 → 첫 조각에 키 보존 + 빈 둘째 조각, 곧바로 분해 가능', () => {
    const seeded = seedConcatFromValue('$t:board.title');
    const parsed = parseExpressionValue(seeded);
    expect(parsed.decomposed).toBe(true);
    if (parsed.node.kind !== 'concat') throw new Error('expected concat');
    expect(parsed.node.parts.length).toBe(2);
    expect(parsed.node.parts[0]).toEqual({ kind: 'leaf', text: '$t:board.title' });
    expect(parsed.node.parts[1]).toEqual({ kind: 'leaf', text: '' });
  });
});

// 조건 빌더 round-trip — 연산자 전환/우변 빈값
//  · 비교 연산자(===/!==/>/</>=/<=)로 막 바꾸면 우변이 빈 상태가 된다. `route.id > ''`(빈 리터럴
//    우변)로 직렬화되어 round-trip 시 SimpleCondition(right:'') 으로 복원되어야 한다(잠금 아님).
//  · 종전엔 `route.id > `(우변 없음)로 직렬화되어 RawCondition(코드에서 수정 잠금)으로 떨어졌다.
describe('parseConditionFromExpr — 빈 조건/우변 빈 비교(B4 잠금 회귀 가드)', () => {
  it('빈 조건 중립 토큰 false → 빈 SimpleCondition(left 빈값)', () => {
    expect(parseConditionFromExpr('false')).toEqual({ kind: 'simple', left: '', op: 'truthy', right: '' });
  });

  it.each(['===', '!==', '>', '<', '>=', '<='])('우변 빈 비교 %s → SimpleCondition(right:\'\') (잠금 아님)', (op) => {
    // ConditionBuilder 가 연산자만 바꾼 직후 직렬화하는 형태(우변 빈 리터럴).
    const cond = parseConditionFromExpr(`route.id ${op} ''`);
    expect(cond.kind).toBe('simple');
    if (cond.kind === 'simple') {
      expect(cond.left).toBe('route.id');
      expect(cond.op).toBe(op);
      expect(cond.right).toBe('');
    }
  });

  it('연산자 전환 round-trip — 트리에서 op 만 바꿔 직렬화→재파싱 시 SimpleCondition 유지', () => {
    // 조건 left=route.id, op=truthy 식에서 op 를 > 로 바꾼 트리.
    const node: ValueNode = {
      kind: 'conditional',
      condition: { kind: 'simple', left: 'route.id', op: '>', right: '' },
      then: { kind: 'leaf', text: '$t:a' },
      else: { kind: 'leaf', text: '$t:b' },
    };
    const serialized = serializeValueNode(node, false);
    // route.id > '' 형태(우변 빈 리터럴) — 유효식.
    expect(serialized).toContain("route.id >");
    const reparsed = parseExpressionValue(serialized);
    expect(reparsed.decomposed).toBe(true);
    if (reparsed.node.kind !== 'conditional') throw new Error('expected conditional');
    expect(reparsed.node.condition).toEqual({ kind: 'simple', left: 'route.id', op: '>', right: '' });
  });
});

describe('extractFirstLeafText / reduceExpressionToPlain — 표현식 → 일반 이름 되돌리기', () => {
  it('조건 분기 → then(참) 분기 리프 텍스트(원본 키 보존)', () => {
    const parsed = parseExpressionValue("{{route.id ? '$t:board.edit_post' : '$t:board.new_post'}}");
    expect(extractFirstLeafText(parsed.node)).toBe('$t:board.edit_post');
  });

  it('reduceExpressionToPlain — 단일 조건 식 → 첫 분기 키', () => {
    expect(reduceExpressionToPlain("{{route.id ? '$t:board.edit_post' : '$t:board.new_post'}}")).toBe('$t:board.edit_post');
  });

  // 첫 결과(기본값 분기)가 **데이터**라면 되돌리기 시 빈 값이 아니라
  // 그 **데이터 칩으로 복구**되어야 한다(되돌리기 미리보기가 🔗 칩을 보여 주는데 적용은 빈 값이던
  // 불일치 결함). 종전 "데이터 바인딩 → 빈 값" 동작을 정정.
  it('폴백 식 → primary(기본값) 리프가 데이터면 그 데이터 바인딩으로 복구', () => {
    const parsed = parseExpressionValue("{{product.data?.name ?? '$t:shop.untitled'}}");
    // 첫 분기(primary) = 데이터 바인딩.
    expect(extractFirstLeafText(parsed.node)).toBe('{{product.data?.name}}');
    // reduceExpressionToPlain 도 그 데이터 바인딩을 그대로 남긴다(데이터 칩 복구) — 빈 값 아님.
    expect(reduceExpressionToPlain("{{product.data?.name ?? '$t:shop.untitled'}}")).toBe('{{product.data?.name}}');
  });

  // og.title `{{route.id ? '$t:edit': product.title}}` 류에서 첫 결과(then)가
  // 데이터인 경우. 미리보기 "기본 값 → 🔗 product.title" 과 적용이 일치해야 한다.
  it('조건식 첫 분기가 데이터면 그 데이터 바인딩으로 복구(PO og.title 케이스)', () => {
    expect(reduceExpressionToPlain("{{route.id ? product.title : '$t:shop.new'}}")).toBe('{{product.title}}');
  });

  // 제보 + 합의(2026-06-13) — 다중 세그먼트를 일반 이름으로 되돌릴 때는 **첫 조각의 대표값
  // 하나만** 남긴다(뒤 평문 연결·데이터 조각 전부 제거). 종전엔 세그먼트를 이어붙여 바인딩 조각
  // (`{{board.name}}`)이 남아 결과가 여전히 `{{}}` 포함 = 표현식으로 재취급되던 결함. "표현식을 다
  // 제거하고 일반 이름으로" 본래 의도와 일치. 결과는 `{{}}` 없는 순수 일반 이름이어야 한다.
  it('reduceExpressionToPlain — 다중 세그먼트: 첫 조각 대표값만(뒤 평문·데이터 제거)', () => {
    const out = reduceExpressionToPlain(
      "{{route.id ? '$t:board.edit_post' : '$t:board.new_post'}} - {{form_meta?.data?.board?.name || ''}}",
    );
    // 첫 세그먼트(조건식)의 첫 분기 키만 — 뒤 " - "·데이터 조각은 떨군다.
    expect(out).toBe('$t:board.edit_post');
    // 결과에 바인딩이 남지 않는다(일반 이름 보장).
    expect(out).not.toContain('{{');
  });

  it('reduceExpressionToPlain — 첫 조각이 평문이면 그 평문만', () => {
    const out = reduceExpressionToPlain("회원 {{user.id}}");
    // 첫 조각(평문 "회원 ")만 — 뒤 데이터 칩 제거. (단일 식 아닌 평문+칩도 일반 이름 환원)
    expect(out).toBe('회원 ');
    expect(out).not.toContain('{{');
  });

  it('reduceExpressionToPlain — 첫 조각이 데이터 바인딩이면 그 데이터로 복구', () => {
    // `{{데이터}} 평문` → 첫 조각이 데이터 → 그 데이터 바인딩으로 복구(빈 값 아님). 뒤 평문은 떨군다.
    const out = reduceExpressionToPlain("{{user.name}} 님");
    expect(out).toBe('{{user.name}}');
  });

  it('reduceExpressionToPlain — 빈 값 → 빈 문자열', () => {
    expect(reduceExpressionToPlain('')).toBe('');
  });

  it('일반→표현식→일반 왕복: 원본 값 복원(첫 분기)', () => {
    const original = '$t:board.title';
    const seeded = seedExpressionFromPlain(original);
    expect(reduceExpressionToPlain(seeded)).toBe(original);
  });
});
