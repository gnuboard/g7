/**
 * useInlineEdit.test.ts — 인라인 편집 분류 + 커밋 분기
 *
 * Pure Logic(DataProvider) 검증:
 *  - classifyInlineText: 평문 / `$t:custom.*` 키 / `$t:`(비-custom) / `{{...}}` 바인딩식 /
 *    iteration 데이터 결정 노드 분기.
 *  - isDataBoundNode: iteration 보유 노드 진입 차단.
 *  - hashInlineText: 같은 값 동일 해시(트래커 민감정보 차단 보조).
 *
 * 키 자동 생성 규칙: 노드에 컴포넌트 id 미부여 — 키는 text 값 자체로 연결.
 */

import { describe, it, expect } from 'vitest';
import {
  classifyInlineText,
  isDataBoundNode,
} from '../../hooks/useInlineEdit';
import { hashInlineText } from '../../devtools/editorTrackers';
import type { EditorNode } from '../../utils/layoutTreeUtils';

describe('classifyInlineText', () => {
  it('평문 텍스트 → 편집 가능 + plain_text + 키 없음', () => {
    const node: EditorNode = { name: 'Span', text: '환영합니다' };
    const cls = classifyInlineText(node);
    expect(cls.sourceState).toBe('plain_text');
    expect(cls.editable).toBe(true);
    expect(cls.customKey).toBeNull();
    expect(cls.displayValue).toBe('환영합니다');
  });

  it('$t:custom.* 키 → 편집 가능 + custom_key + 현재 로케일 값 표시', () => {
    const node: EditorNode = { name: 'Span', text: '$t:custom.home.1' };
    const cls = classifyInlineText(node, (key) =>
      key === 'custom.home.1' ? 'Welcome' : '',
    );
    expect(cls.sourceState).toBe('custom_key');
    expect(cls.editable).toBe(true);
    expect(cls.customKey).toBe('custom.home.1');
    expect(cls.displayValue).toBe('Welcome');
  });

  it('$t: 비-custom 키(템플릿/언어팩 lang) → 평문 동격 편집 가능 + 현재 번역값을 시작값으로', () => {
    // 기존 $t: 키 텍스트도 더블클릭하면 편집되어야 한다 — 확정 시 새 커스텀 키로 전환.
    // (계획서 편집 불가 대상은 {{}}·iteration·잠금뿐. $t: 키는 미포함.)
    const node: EditorNode = { name: 'Span', text: '$t:nav.home' };
    const cls = classifyInlineText(node, (k) => (k === 'nav.home' ? '홈' : ''));
    expect(cls.sourceState).toBe('plain_text');
    expect(cls.editable).toBe(true);
    expect(cls.customKey).toBeNull();
    expect(cls.displayValue).toBe('홈'); // 사용자가 보고 있는 현재 번역값을 시작값으로
  });

  it('팔레트 placeholder 키(default_text) → 편집 가능 + placeholder 번역값을 시작값으로', () => {
    const node: EditorNode = { name: 'H2', text: '$t:layout_editor.palette.h2.default_text' };
    const cls = classifyInlineText(node, (k) =>
      k === 'layout_editor.palette.h2.default_text' ? '중간 제목' : '',
    );
    expect(cls.sourceState).toBe('plain_text');
    expect(cls.editable).toBe(true);
    expect(cls.customKey).toBeNull();
    expect(cls.displayValue).toBe('중간 제목'); // placeholder 번역값을 시작값으로
  });

  it('$t: 키 해석 실패(사전에 없음) → 빈 시작값 (raw 키 노출 회피)', () => {
    const node: EditorNode = { name: 'Span', text: '$t:nav.home' };
    const cls = classifyInlineText(node); // translate 미전달 → 미해석
    expect(cls.editable).toBe(true);
    expect(cls.displayValue).toBe('');
  });

  it('평문 + {{...}} 보간 혼합 → plain_with_binding + 편집 가능(평문 조각)', () => {
    const node: EditorNode = { name: 'Span', text: '{{user.name}} 님 환영합니다' };
    const cls = classifyInlineText(node);
    expect(cls.sourceState).toBe('plain_with_binding');
    expect(cls.editable).toBe(true);
    expect(cls.customKey).toBeNull();
    // 시작값 = 보간 제거 평문(편집 대상).
    expect(cls.displayValue).toBe('님 환영합니다');
  });

  it('보간 전용(평문 0) → 편집 비활성 (데이터 연결 행 담당)', () => {
    const node: EditorNode = { name: 'Span', text: '{{user.name}}' };
    const cls = classifyInlineText(node);
    expect(cls.sourceState).toBe('binding_expression');
    expect(cls.editable).toBe(false);
  });

  it('param 부착 custom 키 → custom_key + 편집 가능 + 키 값 표시', () => {
    const node: EditorNode = { name: 'Span', text: '$t:custom.home.5|p0={{user.name}}' };
    const cls = classifyInlineText(node, (k) =>
      k === 'custom.home.5' ? '{p0} 님 환영합니다' : '',
    );
    expect(cls.sourceState).toBe('custom_key');
    expect(cls.editable).toBe(true);
    expect(cls.customKey).toBe('custom.home.5');
    expect(cls.displayValue).toBe('{p0} 님 환영합니다');
  });

  it('비-custom $t: lang 키 + 보간(Shape A, 공통 문구+데이터) → 편집 허용 + lang 평문 시작값', () => {
    // 종전엔 차단(binding_expression)이었으나, "공통 문구 + 데이터" 자리는 문구 편집 허용
    // + 데이터는 칩. lang 키를 평문으로 해석해 plain_with_binding 으로 편집한다(commit 이 이 화면
    // 전용 커스텀 키로 키화). translate 가 lang 키를 해석하면 그 평문이 시작값.
    const node: EditorNode = { name: 'Span', text: '$t:board.title {{post.id}}' };
    const cls = classifyInlineText(node, (k) => (k === 'board.title' ? '게시판' : ''));
    expect(cls.sourceState).toBe('plain_with_binding');
    expect(cls.editable).toBe(true);
    expect(cls.customKey).toBeNull();
    expect(cls.displayValue).toBe('게시판'); // lang 키 평문 해석값 (raw $t: 노출 아님)
  });

  it('D-44: 구분자(:) 붙은 lang 키 + 보간("발행일: {{date}}") → 편집 허용 + 평문 시작값 ("발행일:") — raw 키 노출 0', () => {
    // 재현: _modal_terms.json 발행일 span. 종전 구분자(:) 때문에 stripBindingTokens 결과가
    // "$t:policy.published_at:" 가 되어 T_KEY_RE 불일치 → raw 키가 시작값으로 노출 + 잘못된 평문 키화로
    // 재귀 키 폭증. 수정 후: lang 키 평문화로 "발행일:" 시작값, raw $t: 미노출.
    const node: EditorNode = {
      name: 'Span',
      text: '$t:policy.published_at: {{termsContent?.data?.published_at | date}}',
    };
    const cls = classifyInlineText(node, (k) => (k === 'policy.published_at' ? '발행일' : ''));
    expect(cls.sourceState).toBe('plain_with_binding');
    expect(cls.editable).toBe(true);
    expect(cls.displayValue).toBe('발행일:'); // 구분자 보존, raw $t: 미노출
    expect(cls.displayValue).not.toContain('$t:');
  });

  it('lang 키 해석 실패(사전 미로드) → 편집 허용하되 시작값 비움 (raw 키 노출 회피)', () => {
    const node: EditorNode = {
      name: 'Span',
      text: '$t:policy.published_at: {{x}}',
    };
    const cls = classifyInlineText(node); // translate 미전달 → 미해석
    expect(cls.sourceState).toBe('plain_with_binding');
    expect(cls.editable).toBe(true);
    expect(cls.displayValue).toBe(''); // raw $t: 키를 시작값으로 노출하지 않음
    expect(cls.chipValue).toBeUndefined(); // 미해석이면 칩 값도 없음 → 평문 편집기 폴백
  });

  it('D-44 칩 온 엔트리: "공통문구+데이터" 노드 → chipValue(파생 자리표시 문장) + chipParamLabels(데이터 경로)', () => {
    // 데이터 든 텍스트를 더블클릭하면 데이터가 칩으로 보여야 한다(첫 진입부터). 분류가
    // lang 평문화 + param 정규화한 파생 칩 값과 칩 라벨을 실어 overlay 가 칩 편집기로 분기하게 한다.
    const node: EditorNode = {
      name: 'Span',
      text: '$t:policy.published_at: {{termsContent?.data?.published_at | date}}',
    };
    const cls = classifyInlineText(node, (k) => (k === 'policy.published_at' ? '발행일' : ''));
    expect(cls.sourceState).toBe('plain_with_binding');
    expect(cls.editable).toBe(true);
    expect(cls.customKey).toBeNull(); // 아직 미키화
    expect(cls.chipValue).toBe('발행일: {p0}'); // 데이터 자리에 {p0} 칩 자리표시
    expect(cls.chipParamLabels).toBeDefined();
    expect(cls.chipParamLabels!.p0).toContain('published_at'); // 데이터 친화 라벨(바인딩 경로)
  });

  it('D-44 칩 온 엔트리: 순수 평문+보간("회원 {{user.id}}")도 chipValue 파생', () => {
    const node: EditorNode = { name: 'Span', text: '회원 {{current_user?.data?.id ?? \'\'}}' };
    const cls = classifyInlineText(node);
    expect(cls.sourceState).toBe('plain_with_binding');
    expect(cls.chipValue).toBe('회원 {p0}');
    // 칩 라벨 = 바인딩 경로(parsed.path) — "data.id"(소스 current_user 의 루트 이하 경로).
    expect(cls.chipParamLabels!.p0).toBe('data.id');
  });

  it('칩 온 엔트리: lang 키 미해석(사전 미로드) → chipValue 없음(평문 편집기 폴백, raw 키 노출 회피)', () => {
    const node: EditorNode = { name: 'Span', text: '$t:policy.published_at: {{x}}' };
    const cls = classifyInlineText(node); // 미해석
    expect(cls.chipValue).toBeUndefined();
    expect(cls.chipParamLabels).toBeUndefined();
  });

  it('iteration 데이터 결정 노드 → 편집 비활성', () => {
    const node: EditorNode = { name: 'Div', text: '항목', iteration: { source: '{{items}}' } };
    const cls = classifyInlineText(node);
    expect(cls.editable).toBe(false);
  });

  it('text 미보유 노드 → 편집 비활성', () => {
    const node: EditorNode = { name: 'Div' };
    const cls = classifyInlineText(node);
    expect(cls.editable).toBe(false);
  });

  it('translate 미전달 시 커스텀 키 displayValue 는 키 자체로 폴백', () => {
    const node: EditorNode = { name: 'Span', text: '$t:custom.home.1' };
    const cls = classifyInlineText(node);
    expect(cls.customKey).toBe('custom.home.1');
    expect(cls.displayValue).toBe('custom.home.1');
  });
});

describe('isDataBoundNode', () => {
  it('iteration 보유 노드 → true', () => {
    expect(isDataBoundNode({ iteration: { source: '{{x}}' } })).toBe(true);
  });
  it('iteration 없는 노드 → false', () => {
    expect(isDataBoundNode({ name: 'Span', text: 'hi' })).toBe(false);
  });
  it('null 노드 → true(차단)', () => {
    expect(isDataBoundNode(null)).toBe(true);
  });
});

describe('hashInlineText (민감정보 차단 보조)', () => {
  it('같은 값은 같은 해시', () => {
    expect(hashInlineText('환영합니다')).toBe(hashInlineText('환영합니다'));
  });
  it('다른 값은 (대개) 다른 해시', () => {
    expect(hashInlineText('a')).not.toBe(hashInlineText('b'));
  });
  it('해시는 32-bit 부호 없는 정수', () => {
    const h = hashInlineText('some long plaintext value');
    expect(Number.isInteger(h)).toBe(true);
    expect(h).toBeGreaterThanOrEqual(0);
    expect(h).toBeLessThanOrEqual(0xffffffff);
  });
});
