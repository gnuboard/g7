/**
 * inlineBindingUtils.test.ts — 텍스트 보간 조각 유틸 단위 테스트
 *
 * split/join 무손실, 조각 교체(위치 보존), 끝에 추가, 해제(공백 정리), 복합 디그레이드,
 * 토큰 불변 가드 — 9-a 권고 설계 각 축을 잠근다.
 */

import { describe, it, expect } from 'vitest';
import {
  splitInlineSegments,
  extractBindingSegments,
  hasInlineBinding,
  replaceBindingSegment,
  removeBindingSegment,
  appendBindingSegment,
  insertBindingSegment,
  nextParamName,
  insertPlaceholderAt,
  appendPlaceholder,
  movePlaceholder,
  toInlineBindingRows,
  sameBindingTokenSet,
  stripBindingTokens,
  buildParamizedKeyText,
  buildParamizedKeyValue,
  extractParamBindings,
  isParamizedKeyText,
  paramPlaceholderTokens,
  sameParamPlaceholderSet,
  replaceParamBinding,
  removeParamBinding,
  removePlaceholderFromKeyValue,
  resolveLangLiterals,
  deriveChipModel,
  buildKeyTextFromChipModel,
  bindingChipLabel,
  hasSettingsRef,
  settingsRefLabel,
  hasValueChipContent,
  toValueChipTokens,
} from '../../spec/inlineBindingUtils';

describe('splitInlineSegments — 무손실 분해', () => {
  it('순수 평문은 literal 1개', () => {
    const segs = splitInlineSegments('안녕하세요');
    expect(segs).toHaveLength(1);
    expect(segs[0]).toMatchObject({ kind: 'literal', raw: '안녕하세요' });
  });

  it('순수 보간은 binding 1개 + parsed 인지', () => {
    const segs = splitInlineSegments('{{user.name}}');
    expect(segs).toHaveLength(1);
    expect(segs[0].kind).toBe('binding');
    expect(segs[0].parsed).toMatchObject({ sourceId: 'user', path: 'name' });
    expect(segs[0].bindingIndex).toBe(0);
  });

  it('평문+보간 혼합 — literal/binding 순서·위치 보존', () => {
    const text = 'v{{module.version}} 출시';
    const segs = splitInlineSegments(text);
    expect(segs.map((s) => s.kind)).toEqual(['literal', 'binding', 'literal']);
    // raw 를 이으면 원문과 동일(무손실).
    expect(segs.map((s) => s.raw).join('')).toBe(text);
    expect(segs[1].start).toBe(1);
    expect(segs[1].end).toBe(1 + '{{module.version}}'.length);
  });

  it('멀티 보간 — bindingIndex 0,1 순차', () => {
    const segs = extractBindingSegments('{{a.b}} / {{c.d}}');
    expect(segs).toHaveLength(2);
    expect(segs[0].bindingIndex).toBe(0);
    expect(segs[1].bindingIndex).toBe(1);
  });

  it('빈 문자열 → 빈 배열', () => {
    expect(splitInlineSegments('')).toEqual([]);
  });

  it('보간 내부 중괄호(객체 리터럴/함수 본문) — 닫는 }} 까지 정확히 흡수', () => {
    // 종전 `[^{}]*` 는 보간 내부 단일 `{`/`}` 를 전면 금지해, 실재하는 객체 리터럴 보간을 인식
    // 못했다. 닫는 `}}` 가 아닌 한 단일 `}` 도 흡수해야 한 토큰으로 잡힌다.
    const t1 = '{{JSON.stringify(row.properties ?? {}, null, 2)}}';
    const s1 = splitInlineSegments(t1);
    expect(s1).toHaveLength(1);
    expect(s1[0].kind).toBe('binding');
    expect(s1[0].raw).toBe(t1);

    const t2 = "변경: {{handler('getDisplayPrice', { product: product.data })}} 원";
    const s2 = splitInlineSegments(t2);
    expect(s2.map((s) => s.kind)).toEqual(['literal', 'binding', 'literal']);
    expect(s2.map((s) => s.raw).join('')).toBe(t2); // 무손실.
    expect(s2[1].raw).toBe("{{handler('getDisplayPrice', { product: product.data })}}");

    // 멀티 객체리터럴 보간도 각각 분리(`{}` 뒤 다른 문자가 오는 현실 형태 — 닫는 `}}` 만 경계).
    const t3 = '{{Object.keys(a ?? {}).length}} / {{Object.entries(b ?? {}).length}}';
    const s3 = extractBindingSegments(t3);
    expect(s3).toHaveLength(2);
    expect(s3[0].raw).toBe('{{Object.keys(a ?? {}).length}}');
    expect(s3[1].raw).toBe('{{Object.entries(b ?? {}).length}}');

    // hasInlineBinding / stripBindingTokens 도 동일 SSoT 로 인식·제거.
    expect(hasInlineBinding(t1)).toBe(true);
    expect(stripBindingTokens(t2)).toBe('변경: 원');
  });
});

describe('hasInlineBinding', () => {
  it('보간 있으면 true, 없으면 false', () => {
    expect(hasInlineBinding('x {{a.b}}')).toBe(true);
    expect(hasInlineBinding('순수 평문')).toBe(false);
    expect(hasInlineBinding(123)).toBe(false);
  });
});

describe('replaceBindingSegment — 조각 교체(위치 보존)', () => {
  it('지정 인덱스 토큰만 교체, 라벨/다른 토큰 보존', () => {
    const text = '점수 {{a.x}} 와 {{b.y}}';
    const out = replaceBindingSegment(text, 1, '{{c.z ?? \'\'}}');
    expect(out).toBe('점수 {{a.x}} 와 {{c.z ?? \'\'}}');
  });

  it('같은 경로가 여러 번이어도 인덱스로 정확히 한 조각만', () => {
    const text = '{{a.x}}-{{a.x}}';
    const out = replaceBindingSegment(text, 0, '{{NEW}}');
    expect(out).toBe('{{NEW}}-{{a.x}}');
  });

  it('범위 밖 인덱스는 원문 그대로', () => {
    expect(replaceBindingSegment('{{a.x}}', 5, '{{z}}')).toBe('{{a.x}}');
  });
});

describe('removeBindingSegment — 해제(공백 정리)', () => {
  it('토큰 + 뒤 공백 1개 제거', () => {
    expect(removeBindingSegment('안녕 {{name}} 님', 0)).toBe('안녕 님');
  });

  it('뒤 공백 없으면 앞 공백 1개 흡수', () => {
    expect(removeBindingSegment('합계 {{total}}', 0)).toBe('합계');
  });

  it('라벨 없는 단독 토큰 제거 → 빈 문자열', () => {
    expect(removeBindingSegment('{{x}}', 0)).toBe('');
  });
});

describe('appendBindingSegment — 끝에 신규 추가(9-a §(8))', () => {
  it('평문 끝에 공백 1개로 구분해 안전 형태 추가', () => {
    expect(appendBindingSegment('안녕', 'user', 'name', 'scalar')).toBe(
      "안녕 {{user?.name ?? ''}}",
    );
  });

  it('빈 텍스트면 토큰만', () => {
    expect(appendBindingSegment('', 'user', 'name', 'scalar')).toBe("{{user?.name ?? ''}}");
  });

  it('이미 공백으로 끝나면 추가 공백 없이', () => {
    expect(appendBindingSegment('값: ', 'a', 'b', 'scalar')).toBe("값: {{a?.b ?? ''}}");
  });
});

describe('toInlineBindingRows — 행 모델 / 복합 디그레이드', () => {
  it('parseable 조각은 isComplex=false', () => {
    const rows = toInlineBindingRows('{{a.b}}');
    expect(rows).toHaveLength(1);
    expect(rows[0]).toMatchObject({ bindingIndex: 0, isComplex: false });
    expect(rows[0].parsed).not.toBeNull();
  });

  it('삼항/필터/연산 조각은 isComplex=true (읽기전용)', () => {
    const rows = toInlineBindingRows('{{a ? b : c}} {{x | number}} {{(items).length}}');
    expect(rows).toHaveLength(3);
    expect(rows.every((r) => r.isComplex)).toBe(true);
    expect(rows.every((r) => r.parsed === null)).toBe(true);
  });

  it('옵셔널 체이닝 + 폴백은 parseable (부록6 정규화 재사용)', () => {
    const rows = toInlineBindingRows("{{user?.profile?.name ?? ''}}");
    expect(rows[0].isComplex).toBe(false);
    expect(rows[0].parsed).toMatchObject({ sourceId: 'user', path: 'profile.name' });
  });
});

describe('sameBindingTokenSet — 토큰 불변 가드(쟁점 5)', () => {
  it('토큰 멀티셋 동일하면 true(라벨/순서 무관, 공백 정규화)', () => {
    expect(sameBindingTokenSet('가 {{a.b}} 나', '나 {{ a.b }} 다른라벨')).toBe(true);
  });

  it('토큰 삭제 시 false', () => {
    expect(sameBindingTokenSet('x {{a.b}}', 'x 번역만')).toBe(false);
  });

  it('토큰 변형 시 false', () => {
    expect(sameBindingTokenSet('{{a.b}}', '{{a.c}}')).toBe(false);
  });

  it('원본 토큰 0 인데 새 토큰 추가 시 false (raw 바인딩 작성 방지)', () => {
    expect(sameBindingTokenSet('순수번역', '몰래 {{secret}}')).toBe(false);
  });

  it('둘 다 토큰 0 이면 true (일반 번역 자유 편집 허용)', () => {
    expect(sameBindingTokenSet('안녕', 'Hello')).toBe(true);
  });

  it('멀티 토큰 부분 보존도 멀티셋 비교 (순서 바뀌어도 동일)', () => {
    expect(sameBindingTokenSet('{{a}} {{b}}', '{{b}} 라벨 {{a}}')).toBe(true);
    expect(sameBindingTokenSet('{{a}} {{b}}', '{{a}} only')).toBe(false);
  });
});

// ============================================================================
// param 정규화 — 평문+보간 키화의 위치/개수 보존
// ============================================================================

describe('buildParamizedKeyText — 보간을 |pN= param 으로 키 뒤 부착', () => {
  it('보간 0 → keyToken 단독', () => {
    expect(buildParamizedKeyText('$t:custom.home.5', '순수 평문')).toBe('$t:custom.home.5');
  });

  it('끝 보간 1개', () => {
    expect(buildParamizedKeyText('$t:custom.home.5', '안녕 {{user.name}}')).toBe(
      '$t:custom.home.5|p0={{user.name}}',
    );
  });

  it('앞/중/끝 멀티 보간 — 등장 순서대로 p0,p1,p2', () => {
    expect(
      buildParamizedKeyText('$t:custom.x.1', '{{a}} 작성 {{b}} 끝 {{c}}'),
    ).toBe('$t:custom.x.1|p0={{a}}|p1={{b}}|p2={{c}}');
  });

  it('앞 보간(시작 위치)도 p0', () => {
    expect(buildParamizedKeyText('$t:custom.x.1', '{{author}} 님이 작성')).toBe(
      '$t:custom.x.1|p0={{author}}',
    );
  });
});

describe('buildParamizedKeyValue — 보간 자리를 {pN} 자리표시로 (위치 보존)', () => {
  it('보간 0 → 원본 평문 그대로', () => {
    expect(buildParamizedKeyValue('순수 평문')).toBe('순수 평문');
  });

  it('끝 보간 → {p0} 끝', () => {
    expect(buildParamizedKeyValue('안녕 {{user.name}}')).toBe('안녕 {p0}');
  });

  it('앞/중/끝 멀티 → {p0}..{p2} (어순/위치 보존)', () => {
    expect(buildParamizedKeyValue('{{a}} 작성 {{b}} 끝 {{c}}')).toBe('{p0} 작성 {p1} 끝 {p2}');
  });

  it('앞 보간 → {p0} 으로 시작', () => {
    expect(buildParamizedKeyValue('{{author}} 님이 작성')).toBe('{p0} 님이 작성');
  });
});

describe('param 정규화 round-trip — 키 텍스트 ↔ 역분해', () => {
  it.each([
    ['끝', '안녕 {{user.name}}'],
    ['앞', '{{author}} 님이 작성'],
    ['중간', 'v{{module.version}} 출시'],
    ['멀티', '{{a}} 작성 {{b}} 끝 {{c}}'],
  ])('%s 위치 — buildParamizedKeyText → extractParamBindings 가 보간을 그대로 복원', (_pos, original) => {
    const keyText = buildParamizedKeyText('$t:custom.x.1', original);
    const parsed = extractParamBindings(keyText);
    expect(parsed).not.toBeNull();
    expect(parsed!.key).toBe('custom.x.1');
    const tokens = extractBindingSegments(original).map((s) => s.raw);
    expect(parsed!.params.map((p) => p.expression)).toEqual(tokens);
    expect(parsed!.params.map((p) => p.name)).toEqual(tokens.map((_t, i) => `p${i}`));
  });
});

describe('extractParamBindings / isParamizedKeyText', () => {
  it('param 부착 키 인지 + 키/param 분해', () => {
    const r = extractParamBindings("$t:custom.home.5|p0={{a.b}}|p1={{c?.d ?? ''}}");
    expect(r).not.toBeNull();
    expect(r!.key).toBe('custom.home.5');
    expect(r!.params).toHaveLength(2);
    expect(r!.params[0]).toMatchObject({ name: 'p0', expression: '{{a.b}}' });
    expect(r!.params[0].parsed).toMatchObject({ sourceId: 'a', path: 'b' });
    expect(r!.params[1].parsed).toMatchObject({ sourceId: 'c', path: 'd' });
  });

  it('복합 param 은 parsed=null (읽기전용 디그레이드)', () => {
    const r = extractParamBindings('$t:custom.x.1|p0={{a ? b : c}}');
    expect(r!.params[0].parsed).toBeNull();
  });

  it('param 미부착 단일 키 → null', () => {
    expect(extractParamBindings('$t:custom.x.1')).toBeNull();
    expect(isParamizedKeyText('$t:custom.x.1')).toBe(false);
    expect(isParamizedKeyText('$t:custom.x.1|p0={{a}}')).toBe(true);
    expect(isParamizedKeyText('평문 {{a}}')).toBe(false);
  });

  // 결함 D 회귀 — param 값 보간이 파이프 필터(`| date`)를 포함하면 그 `|` 가 토큰 구분자로
  // 오인돼 PARAMIZED_KEY_RE 전체 불일치 → extractParamBindings null → 키화 노드인데 칩 편집기로
  // 분기 못 하고 빈 평문 편집기 진입(raw 키 누출). 값 경계를 다음 `|<이름>=` 으로 잡아 해소.
  it('param 값 보간에 파이프 필터(| date)가 있어도 정확히 파싱', () => {
    const text = '$t:custom.auth_register.38|p0={{privacyContent?.data?.published_at | date}}';
    expect(isParamizedKeyText(text)).toBe(true);
    const r = extractParamBindings(text);
    expect(r).not.toBeNull();
    expect(r!.key).toBe('custom.auth_register.38');
    expect(r!.params).toHaveLength(1);
    expect(r!.params[0]).toMatchObject({
      name: 'p0',
      expression: '{{privacyContent?.data?.published_at | date}}',
    });
  });

  it('파이프 필터 + 다중 param 혼합도 토큰 경계 정확', () => {
    const text = '$t:custom.x.9|p0={{a.b | number}}|p1={{c.d | date}}';
    const r = extractParamBindings(text);
    expect(r!.params).toHaveLength(2);
    expect(r!.params[0].expression).toBe('{{a.b | number}}');
    expect(r!.params[1].expression).toBe('{{c.d | date}}');
  });
});

describe('paramPlaceholderTokens / sameParamPlaceholderSet — 자리표시 가드', () => {
  it('{pN} 와 {{pN}} 모두 인지(이름만 추출, 정렬)', () => {
    expect(paramPlaceholderTokens('{p1} 작성 {p0}')).toEqual(['p0', 'p1']);
    expect(paramPlaceholderTokens('{{p0}} 와 {p1}')).toEqual(['p0', 'p1']);
  });

  it('자리표시 보존 시 동일(어순/문장 자유)', () => {
    expect(sameParamPlaceholderSet('{p0} 작성 {p1}', '{p1} wrote by {p0}')).toBe(true);
  });

  it('자리표시 삭제/변형 시 false', () => {
    expect(sameParamPlaceholderSet('{p0} 작성 {p1}', '작성 {p1}')).toBe(false);
    expect(sameParamPlaceholderSet('{p0}', '{p2}')).toBe(false);
  });
});

describe('replaceParamBinding / removeParamBinding / removePlaceholderFromKeyValue', () => {
  it('지정 param 값만 교체(키·다른 param·자리표시 보존)', () => {
    expect(
      replaceParamBinding('$t:custom.x.1|p0={{a}}|p1={{b}}', 'p1', "{{c?.d ?? ''}}"),
    ).toBe("$t:custom.x.1|p0={{a}}|p1={{c?.d ?? ''}}");
  });

  it('이름 경계 — p1 교체가 p10 등을 건드리지 않음', () => {
    // p1 정확히 매칭(다음 | 전까지). p10 미존재 케이스는 원문 유지.
    expect(replaceParamBinding('$t:custom.x.1|p0={{a}}', 'p9', '{{z}}')).toBe(
      '$t:custom.x.1|p0={{a}}',
    );
  });

  it('param 제거 — 토큰만(키·다른 param 보존)', () => {
    expect(removeParamBinding('$t:custom.x.1|p0={{a}}|p1={{b}}', 'p0')).toBe(
      '$t:custom.x.1|p1={{b}}',
    );
  });

  it('키 값 자리표시 제거(공백 정리)', () => {
    expect(removePlaceholderFromKeyValue('{p0} 작성 {p1}', 'p0')).toBe('작성 {p1}');
    expect(removePlaceholderFromKeyValue('합계 {p0}', 'p0')).toBe('합계');
  });

  // 결함 D 회귀 — 값 보간에 파이프 필터가 있어도 토큰 경계가 정확해야 교체/제거가 깨지지 않는다.
  it('파이프 필터 포함 param 값 교체 — 보간 전체 치환(| date 보존)', () => {
    expect(
      replaceParamBinding('$t:custom.x.1|p0={{a | date}}|p1={{b}}', 'p0', '{{c | number}}'),
    ).toBe('$t:custom.x.1|p0={{c | number}}|p1={{b}}');
  });

  it('파이프 필터 포함 param 제거 — 토큰 전체 제거(잔재 없음)', () => {
    expect(removeParamBinding('$t:custom.x.1|p0={{a | date}}|p1={{b}}', 'p0')).toBe(
      '$t:custom.x.1|p1={{b}}',
    );
  });
});

// ============================================================================
// 위치 편집 — 임의 위치 삽입 + 칩 이동 + 미편집 로케일 추가
// ============================================================================

describe('insertBindingSegment — 임의 위치 토큰 삽입(앞/중/끝)', () => {
  it('끝 삽입 = appendBindingSegment 동등', () => {
    const text = '안녕';
    expect(insertBindingSegment(text, text.length, 'user', 'name', 'scalar')).toBe(
      appendBindingSegment(text, 'user', 'name', 'scalar'),
    );
  });

  it('앞(0) 삽입 — 뒤 평문과 공백 1개 구분', () => {
    expect(insertBindingSegment('님 환영', 0, 'user', 'name', 'scalar')).toBe(
      "{{user?.name ?? ''}} 님 환영",
    );
  });

  it('중간 삽입 — 양옆 공백 1개씩', () => {
    expect(insertBindingSegment('총합원', 2, 'cart', 'total', 'scalar')).toBe(
      "총합 {{cart?.total ?? ''}} 원",
    );
  });

  it('빈 텍스트면 토큰만', () => {
    expect(insertBindingSegment('', 0, 'a', 'b', 'scalar')).toBe("{{a?.b ?? ''}}");
  });

  it('범위 밖 인덱스는 끝으로 클램프', () => {
    expect(insertBindingSegment('값', 999, 'a', 'b', 'scalar')).toBe("값 {{a?.b ?? ''}}");
  });
});

describe('nextParamName — 불연속 허용 빈 번호', () => {
  it('빈 목록 → p0', () => {
    expect(nextParamName([])).toBe('p0');
  });
  it('p0,p1 → p2', () => {
    expect(nextParamName(['p0', 'p1'])).toBe('p2');
  });
  it('불연속(p0,p2) → p1 (이름 기반 치환이라 연속성 불필요)', () => {
    expect(nextParamName(['p0', 'p2'])).toBe('p1');
  });
});

describe('insertPlaceholderAt / appendPlaceholder — 키 값 자리표시 삽입', () => {
  it('앞(0) 삽입 — 뒤 평문 공백 구분', () => {
    expect(insertPlaceholderAt('님이 작성', 0, 'p0')).toBe('{p0} 님이 작성');
  });
  it('중간 삽입 — 양옆 공백', () => {
    expect(insertPlaceholderAt('작성함', 2, 'p1')).toBe('작성 {p1} 함');
  });
  it('끝 추가(append) — 문장 끝 공백 1개', () => {
    expect(appendPlaceholder('작성함', 'p2')).toBe('작성함 {p2}');
  });
  it('빈 값 끝 추가 → 자리표시 단독', () => {
    expect(appendPlaceholder('', 'p0')).toBe('{p0}');
  });
  it('이미 있는 자리표시는 중복 추가 안 함', () => {
    expect(appendPlaceholder('{p0} 작성', 'p0')).toBe('{p0} 작성');
  });
});

describe('movePlaceholder — 칩 드래그 이동(단일 로케일, 로케일 독립)', () => {
  it('끝 → 앞 이동', () => {
    // "작성 {p0}" 의 {p0} 를 인덱스 0 으로 → "{p0} 작성"
    expect(movePlaceholder('작성 {p0}', 'p0', 0)).toBe('{p0} 작성');
  });

  it('앞 → 끝 이동(제거 보정 — 목표 인덱스가 제거 구간 뒤)', () => {
    // "{p0} 작성함" 의 {p0} 를 끝(원문 길이) 으로.
    expect(movePlaceholder('{p0} 작성함', 'p0', '{p0} 작성함'.length)).toBe('작성함 {p0}');
  });

  it('자리표시 없으면 원문 그대로', () => {
    expect(movePlaceholder('작성함', 'p0', 0)).toBe('작성함');
  });

  it('멀티 자리표시 — 지정 param 만 이동(다른 자리표시 불변)', () => {
    // "{p0} 와 {p1}" 에서 p1 을 맨 앞으로.
    expect(movePlaceholder('{p0} 와 {p1}', 'p1', 0)).toBe('{p1} {p0} 와');
  });
});

describe('stripBindingTokens — 평문 추출(키화 진입 시작값)', () => {
  it('보간 제거 + 공백 정리', () => {
    expect(stripBindingTokens('비밀번호 test {{current_user?.data?.uuid}}')).toBe(
      '비밀번호 test',
    );
    expect(stripBindingTokens('{{a}} 작성 {{b}}')).toBe('작성');
  });
});

describe('resolveLangLiterals — 평문 영역 lang 키 평문화', () => {
  const translate = (k: string): string =>
    ({ 'policy.published_at': '발행일', 'nav.home': '홈' })[k] ?? '';

  it('구분자(:) 붙은 lang 키 + 보간 → lang 키만 평문화, 보간/구분자 보존', () => {
    // "$t:policy.published_at: {{date}}" 의 lang 키를 "발행일" 로 치환.
    expect(
      resolveLangLiterals('$t:policy.published_at: {{termsContent?.data?.published_at | date}}', translate),
    ).toBe('발행일: {{termsContent?.data?.published_at | date}}');
  });

  it('구분자 없는 lang 키 + 보간 → 평문화', () => {
    expect(resolveLangLiterals('$t:nav.home {{x}}', translate)).toBe('홈 {{x}}');
  });

  it('보간 토큰 내부의 $t: 류는 건드리지 않음(literal 조각만 치환)', () => {
    // 보간 안에는 $t: 가 없지만, 보간 토큰 자체가 보존되는지 확인.
    expect(resolveLangLiterals('{{policy.x}} 발행일', translate)).toBe('{{policy.x}} 발행일');
  });

  it('custom.* 동적 키는 보존(평문화 대상 아님)', () => {
    expect(resolveLangLiterals('$t:custom.auth_register.1 {{x}}', translate)).toBe(
      '$t:custom.auth_register.1 {{x}}',
    );
  });

  it('해석 실패(사전 미로드) → 원문 토큰 보존(잘못된 평문화 회피)', () => {
    expect(resolveLangLiterals('$t:policy.unknown: {{x}}', translate)).toBe(
      '$t:policy.unknown: {{x}}',
    );
  });

  it('translate 미전달 → 원문 그대로(항등)', () => {
    expect(resolveLangLiterals('$t:policy.published_at: {{x}}')).toBe(
      '$t:policy.published_at: {{x}}',
    );
  });

  it('순수 평문/일반 보간 → 무영향(항등)', () => {
    expect(resolveLangLiterals('회원 {{user.id}}', translate)).toBe('회원 {{user.id}}');
    expect(resolveLangLiterals('안녕하세요', translate)).toBe('안녕하세요');
  });

  it('재귀 차단 검증: 평문화 후 buildParamizedKeyValue 에 raw $t: 미박힘', () => {
    // 재귀 폭증의 근본 — 평문화 없이 키값을 만들면 "$t:policy.published_at: {p0}" (lang 키 참조).
    // 평문화하면 "발행일: {p0}" 가 되어 lang 키 참조가 사라진다.
    const resolved = resolveLangLiterals(
      '$t:policy.published_at: {{termsContent?.data?.published_at | date}}',
      translate,
    );
    const keyValue = buildParamizedKeyValue(resolved);
    expect(keyValue).toBe('발행일: {p0}');
    expect(keyValue).not.toContain('$t:'); // raw lang 키 참조 0 — 재귀 끊김.
  });
});

describe('deriveChipModel — 데이터 든 텍스트 전 Shape 칩 모델', () => {
  // 실제 번들 lang 값(이름 자리표시 포함)을 흉내낸 해석기.
  const translate = (k: string): string =>
    ({
      'policy.published_at': '발행일',
      'user.identity.challenge.remaining_attempts': '남은 시도: {{count}}회',
      'user.identity.challenge.remaining_time': '남은 시간: {{minutes}}:{{seconds}}',
      'shop.cart.insufficient_stock_item': '{{name}} ({{option}}) - 재고: {{stock}}개, 요청: {{quantity}}개',
      'board.views_count': '조회 {{count}}',
      'shop.total_count': '전체 {{count}}개',
    })[k] ?? '';

  it('Shape 1 순수 평문+보간: "회원 {{user.id}}" → "회원 {p0}"', () => {
    const m = deriveChipModel('회원 {{current_user?.data?.id ?? \'\'}}', translate);
    expect(m.keyifiable).toBe(true);
    expect(m.chipValue).toBe('회원 {p0}');
    expect(m.bindings).toEqual(["{{current_user?.data?.id ?? ''}}"]);
    expect(m.paramLabels.p0).toBe('data.id');
  });

  it('Shape 2 lang키+구분자+보간: "$t:policy.published_at: {{date}}" → "발행일: {p0}"', () => {
    const m = deriveChipModel('$t:policy.published_at: {{termsContent?.data?.published_at | date}}', translate);
    expect(m.keyifiable).toBe(true);
    expect(m.chipValue).toBe('발행일: {p0}');
    expect(m.bindings).toEqual(['{{termsContent?.data?.published_at | date}}']);
    expect(m.chipValue).not.toContain('$t:');
    // 결함 — 파이프 필터(`| date`)가 있는 보간의 칩 라벨이 raw 표현식 전체로
    // 박히던 결함(parseBindingExpression 이 파이프 보간에 null → expression fallback). 칩 라벨은
    // 데이터 경로(`data.published_at`)여야 하며 raw `{{`/`?.`/`| date` 가 노출되면 안 된다.
    expect(m.paramLabels.p0).toBe('data.published_at');
    expect(m.paramLabels.p0).not.toContain('{{');
    expect(m.paramLabels.p0).not.toContain('|');
  });

  it('Shape 3 lang키+이름param: remaining_attempts|count={{Math.max(...)}} → "남은 시도: {p0}회" (칩 안 깨짐)', () => {
    // identity/challenge 결함 — 종전 lang값 {{count}} 와 노드 |count={{}} 이중 변환으로 깨졌다.
    const m = deriveChipModel(
      '$t:user.identity.challenge.remaining_attempts|count={{Math.max(0, (_local.maxAttempts ?? 0) - (_local.attempts ?? 0))}}',
      translate,
    );
    expect(m.keyifiable).toBe(true);
    // lang값 "남은 시도: {{count}}회" 의 {{count}} 가 그 param 보간 → {p0}. "회|count=" raw 노출 0.
    expect(m.chipValue).toBe('남은 시도: {p0}회');
    expect(m.chipValue).not.toContain('count=');
    expect(m.chipValue).not.toContain('$t:');
    expect(m.bindings).toEqual(['{{Math.max(0, (_local.maxAttempts ?? 0) - (_local.attempts ?? 0))}}']);
    expect(m.bindings.length).toBe(1); // 칩 1개(p1 중복 생성 안 함).
  });

  it('Shape 4 이름param 2개 + 구분자: remaining_time|minutes=|seconds= ("{{minutes}}:{{seconds}}") → "남은 시간: {p0}:{p1}"', () => {
    // identity/challenge 타이머 — lang값에 param 사이 `:` 구분자. 라이브는 게이트(remainingSeconds>0)
    // 뒤라 편집기 샘플 미렌더 → exact-string 단위로 잠금(변종 전수 — 단일 케이스 단정 금지 규정).
    const m = deriveChipModel(
      "$t:user.identity.challenge.remaining_time|minutes={{Math.floor((_local.remainingSeconds ?? 0) / 60)}}|seconds={{String((_local.remainingSeconds ?? 0) % 60).padStart(2, '0')}}",
      translate,
    );
    expect(m.keyifiable).toBe(true);
    expect(m.chipValue).toBe('남은 시간: {p0}:{p1}');
    expect(m.bindings.length).toBe(2);
    expect(m.chipValue).not.toContain('minutes=');
    expect(m.chipValue).not.toContain('$t:');
  });

  it('Shape 4 다중 이름param: insufficient_stock_item|name=|option=|stock=|quantity= → 4칩 정확 매핑', () => {
    const m = deriveChipModel(
      '$t:shop.cart.insufficient_stock_item|name={{u.name}}|option={{u.option}}|stock={{u.stock}}|quantity={{u.quantity}}',
      translate,
    );
    expect(m.keyifiable).toBe(true);
    // lang값 "{{name}} ({{option}}) - 재고: {{stock}}개, 요청: {{quantity}}개" 의 각 자리표시 → {pN}.
    expect(m.chipValue).toBe('{p0} ({p1}) - 재고: {p2}개, 요청: {p3}개');
    expect(m.bindings).toEqual(['{{u.name}}', '{{u.option}}', '{{u.stock}}', '{{u.quantity}}']);
    expect(m.chipValue).not.toContain('$t:');
    expect(m.chipValue).not.toContain('|');
  });

  it('S9 결함 — named param 값에 `||` 논리연산: bulk_deactivate_confirm|count={{(_global.x || []).length}}', () => {
    // 미키화 named param 의 값 보간이 `||`(논리 OR)를 포함하면, 종전
    // LEADING_T_KEY_RE/NAMED_PARAM_RE 의 `[^|]*` 경계가 첫 `|`(=`||` 의 첫 파이프)에서 값을 잘라
    // 칩 라벨이 `(_global.x` 까지만, 나머지 `|| []).length}}` 가 평문으로 새어나왔다. PARAM_VALUE_BODY
    // (negative lookahead) SSoT 공유로 키화 경로(extractParamBindings)와 동일하게 보간 내부 파이프 보존.
    translate; // (lang값에 자리표시 1개를 둔 흉내)
    const m = deriveChipModel(
      '$t:admin.users.modals.bulk_deactivate_confirm|count={{(_global.selectedIds_admin_users || []).length}}',
      (k: string) =>
        k === 'admin.users.modals.bulk_deactivate_confirm' ? '{{count}}명의 사용자를 비활성 상태로 변경합니다.' : '',
    );
    expect(m.keyifiable).toBe(true);
    expect(m.chipValue).toBe('{p0}명의 사용자를 비활성 상태로 변경합니다.');
    expect(m.bindings).toEqual(['{{(_global.selectedIds_admin_users || []).length}}']);
    // 칩 라벨/값에 raw `|| []).length}}` 평문 누출 0.
    expect(m.chipValue).not.toContain('|| []');
    expect(m.chipValue).not.toContain('}}');
    // 칩 라벨은 식 끝 식별자(`.length`)만, raw `||`/`{{` 미노출.
    expect(m.paramLabels.p0).toBe('length');
    expect(m.paramLabels.p0).not.toContain('||');
    expect(m.paramLabels.p0).not.toContain('{{');
  });

  it('S9 결함 — multi-param 중 첫 값에 `||`: page=.. || 1 |last=.. (두번째 param 누락 방지)', () => {
    // LEADING_T_KEY_RE 의 group `(?:\\|name=[^|]*)*` 가 첫 param 값의 `||` 에서 끊기면 그 그룹이
    // 조기 종료해 두번째 param(|last=) 이 통째 누락 → 칩 1개만 잡히던 잠재 결함. PARAM_VALUE_BODY 로
    // 다음 `|<이름>=` 가 나올 때까지 값을 먹어 multi-param 전부 보존.
    const m = deriveChipModel(
      '$t:admin.identity.logs.total_count|count={{identityLogs?.data?.pagination?.total || 0}}',
      (k: string) => (k === 'admin.identity.logs.total_count' ? '전체 {{count}}건' : ''),
    );
    expect(m.keyifiable).toBe(true);
    expect(m.chipValue).toBe('전체 {p0}건');
    expect(m.bindings).toEqual(['{{identityLogs?.data?.pagination?.total || 0}}']);
  });

  it('S9 결함 — 2개 param 모두 `||`/`-` 연산 포함: page=..||1 |last=..||1 → 2칩 전부 보존', () => {
    const m = deriveChipModel(
      '$t:admin.identity.policies.page_indicator|page={{policies?.data?.meta?.current_page ?? 1}}|last={{policies?.data?.meta?.last_page ?? 1}}',
      (k: string) => (k === 'admin.identity.policies.page_indicator' ? '{{page}} / {{last}}' : ''),
    );
    expect(m.keyifiable).toBe(true);
    expect(m.chipValue).toBe('{p0} / {p1}');
    expect(m.bindings.length).toBe(2);
    expect(m.bindings).toEqual([
      '{{policies?.data?.meta?.current_page ?? 1}}',
      '{{policies?.data?.meta?.last_page ?? 1}}',
    ]);
  });

  it('lang 키 미해석(사전 미로드) → keyifiable:false (raw 키 노출 회피, 평문 편집기 폴백)', () => {
    const m = deriveChipModel('$t:policy.published_at: {{x}}'); // translate 미전달
    expect(m.keyifiable).toBe(false);
    expect(m.chipValue).toBe('');
  });

  it('이름param lang값 미해석 → keyifiable:false (이중 변환 방지 폴백)', () => {
    const m = deriveChipModel('$t:user.identity.challenge.remaining_attempts|count={{x}}'); // 미해석
    expect(m.keyifiable).toBe(false);
  });

  it('데이터(보간) 0 → keyifiable:false (칩 대상 아님)', () => {
    expect(deriveChipModel('순수 평문만', translate).keyifiable).toBe(false);
    expect(deriveChipModel('$t:policy.published_at', translate).keyifiable).toBe(false);
  });

  it('buildKeyTextFromChipModel: 칩 모델 보간 순서대로 |pN= 부착', () => {
    const m = deriveChipModel(
      '$t:user.identity.challenge.remaining_attempts|count={{cnt}}',
      translate,
    );
    const keyText = buildKeyTextFromChipModel('$t:custom.x.1', m);
    expect(keyText).toBe('$t:custom.x.1|p0={{cnt}}');
  });

  it('키화 후 라운드트립: chipValue 자리표시 멀티셋 ↔ bindings 개수 일치', () => {
    const m = deriveChipModel(
      '$t:shop.cart.insufficient_stock_item|name={{a}}|option={{b}}|stock={{c}}|quantity={{d}}',
      translate,
    );
    expect(paramPlaceholderTokens(m.chipValue)).toEqual(['p0', 'p1', 'p2', 'p3']);
    expect(m.bindings.length).toBe(4);
  });
});

describe('bindingChipLabel — 칩 친화 라벨 추출', () => {
  it('순수 점 경로 보간 → 데이터 경로 라벨', () => {
    expect(bindingChipLabel('{{current_user?.data?.id ?? \'\'}}')).toBe('data.id');
    expect(bindingChipLabel('{{termsContent?.data?.published_at}}')).toBe('data.published_at');
  });

  it('파이프 필터(| date) 보간 → 파이프 앞 경로 라벨 (raw 미노출)', () => {
    // S9 핵심 결함 — parseBindingExpression 이 파이프 보간에 null 을 반환해, 칩 라벨이 raw 표현식
    // 전체(`{{termsContent?.data?.published_at | date}}`)로 박히던 결함. 파이프 필터는 표시 변환일
    // 뿐 데이터 경로와 무관하므로, 라벨은 파이프 앞 경로(`data.published_at`)를 추출해야 한다.
    const label = bindingChipLabel('{{termsContent?.data?.published_at | date}}');
    expect(label).toBe('data.published_at');
    expect(label).not.toContain('{{');
    expect(label).not.toContain('|');
    expect(label).not.toContain('?.');
  });

  it('다중 파이프 필터 → 첫 파이프 앞 경로', () => {
    expect(bindingChipLabel('{{shop.price | number | currency}}')).toBe('price');
  });

  it('상태 scope 보간 → scope 제외 경로(경로 없으면 scope)', () => {
    expect(bindingChipLabel('{{_local.remainingSeconds ?? 0}}')).toBe('remainingSeconds');
    expect(bindingChipLabel('{{route.id}}')).toBe('id');
  });

  it('복합식(함수/연산) → 정리된 표현식(raw `{{` 미노출, 최후 폴백)', () => {
    // Math.max 등 진짜 복합식은 경로 추출 불가 → 중괄호/공백만 정리한 식을 라벨로(차선).
    const label = bindingChipLabel('{{Math.max(0, (_local.a ?? 0) - (_local.b ?? 0))}}');
    expect(label).not.toContain('{{');
    expect(label.length).toBeGreaterThan(0);
    // `)` 로 끝나는 함수 호출식은 마지막-식별자 추출 대상 아님 — 식 전체 정리 라벨.
    expect(label).toContain('Math.max');
  });

  it('복합식이 `.identifier` 로 끝나면 마지막 식별자 라벨 (`(x || []).length` 깨짐)', () => {
    // `(_global.x || []).length` 류는 경로 추출 불가(논리연산 포함)지만 식 끝이 `.length` 접근이라
    // 칩 라벨로 통째 박히면 깨져 보인다. 식 끝 `.identifier` 를 라벨로 추출(`length`).
    expect(bindingChipLabel('{{(_global.selectedIds_admin_users || []).length}}')).toBe('length');
    expect(bindingChipLabel('{{(items ?? []).length}}')).toBe('length');
    expect(bindingChipLabel('{{foo?.bar?.pagination?.total || 0}}')).toBe('total');
  });
});

// `$core_settings:`/`$module_settings:`/`$plugin_settings:` 설정 참조 칩 SSoT.
// I18nTextField 가 설정참조를 평문으로 떨궈 raw `$core_settings:general.site_name` 가 노출되던 결함의
// 회귀 가드(공용 유틸이 세 형태를 모두 칩으로 인지하는지).
describe('설정 참조($*_settings:) 칩 시각화', () => {
  it('hasSettingsRef — core/module/plugin 세 형태 모두 인지', () => {
    expect(hasSettingsRef('$core_settings:general.site_name')).toBe(true);
    expect(hasSettingsRef('$module_settings:sirsoft-ecommerce:shop.name')).toBe(true);
    expect(hasSettingsRef('$plugin_settings:gdpr.consent_text')).toBe(true);
    // 평문/바인딩은 설정참조 아님.
    expect(hasSettingsRef('일반 평문')).toBe(false);
    expect(hasSettingsRef('{{product.name}}')).toBe(false);
    expect(hasSettingsRef(null)).toBe(false);
  });

  it('settingsRefLabel — 마지막 경로 세그먼트 친화 라벨', () => {
    expect(settingsRefLabel('$core_settings:general.site_name')).toBe('site_name');
    expect(settingsRefLabel('$module_settings:sirsoft-ecommerce:shop.name')).toBe('name');
    expect(settingsRefLabel('$plugin_settings:gdpr.consent_text')).toBe('consent_text');
  });

  it('hasValueChipContent — 바인딩 또는 설정참조 포함 판정', () => {
    expect(hasValueChipContent('$core_settings:general.site_name')).toBe(true);
    expect(hasValueChipContent('{{product.name}}')).toBe(true);
    expect(hasValueChipContent('회원 {{name}}')).toBe(true);
    expect(hasValueChipContent('순수 평문')).toBe(false);
  });

  it('toValueChipTokens — 단독 설정참조 → 칩 1개(raw `$core_settings:` 미노출)', () => {
    const tokens = toValueChipTokens('$core_settings:general.site_name');
    expect(tokens).toEqual([{ kind: 'chip', label: 'site_name' }]);
    // raw 토큰 문자열이 어떤 토큰에도 노출되지 않음.
    expect(tokens.some((t) => /\$core_settings:/.test(t.label))).toBe(false);
  });

  it('toValueChipTokens — 평문+설정참조 혼합 → 평문/칩 분리', () => {
    const tokens = toValueChipTokens('우리 $core_settings:general.site_name 쇼핑몰');
    expect(tokens).toEqual([
      { kind: 'text', label: '우리 ' },
      { kind: 'chip', label: 'site_name' },
      { kind: 'text', label: ' 쇼핑몰' },
    ]);
  });

  it('toValueChipTokens — 바인딩 + 설정참조 혼합(둘 다 칩)', () => {
    const tokens = toValueChipTokens('{{product.name}} / $plugin_settings:gdpr.consent_text');
    const chips = tokens.filter((t) => t.kind === 'chip').map((t) => t.label);
    expect(chips).toEqual(['name', 'consent_text']);
  });
});
