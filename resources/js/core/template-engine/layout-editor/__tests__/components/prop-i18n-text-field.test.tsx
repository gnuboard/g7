// e2e:allow 부록7 7-a 인프라 단위 검증. 캔버스/실사용자화면 언어별 반영 E2E 는 7-b 세션 귀속.
/**
 * prop-i18n-text-field.test.tsx — 텍스트 propControl/label_key 동적 다국어 RTL
 *
 * 검증:
 *  - classifyCustomText(순수): 평문 / `$t:custom.*` 키 / 임의 `$t:` 키 / `{{...}}` 바인딩 분류.
 *  - I18nTextField — A.접힌 미리보기(현재 로케일 해석값) + 평문 blur → commitText → 키 생성 토큰 onChange.
 *  - 기존 커스텀 키 → 미리보기 input 에 해석값, blur 시 현재 로케일 값만 PUT(토큰 무변경).
 *  - C.바인딩식(`{{...}}`) → 읽기전용 배지(input 없음, onChange 미발화).
 *  - B.펼침(🌐) → ko/en/ja 일괄 폼(커스텀 키일 때 행 로드).
 *  - ControlRenderer — widget=i18n-text + apply=propValue → I18nTextField 분기, 그 외 위젯 무변경.
 *
 * @effects prop_text_control_promoted_to_dynamic_i18n_when_propvalue, plain_prop_text_blur_creates_custom_key_token, existing_custom_key_prop_blur_updates_current_locale_value, binding_expression_prop_degrades_readonly, label_key_input_uses_dynamic_i18n_field, text_propvalue_promoted_to_value_chip_input_no_keyify, i18n_propvalue_decomposable_expression_enters_collapsed_tree, recipe_param_text_without_apply_stays_bare_widget
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, waitFor } from '@testing-library/react';

// useLayoutEditor — layoutName/locale/templateIdentifier 공급 모킹(공통 hook 이 읽음).
vi.mock('../../LayoutEditorContext', () => ({
  useLayoutEditor: () => ({
    state: {
      templateIdentifier: 'sirsoft-basic',
      locale: 'ko',
      selectedRoute: { path: '/login', layoutName: 'login' },
    },
  }),
}));

// 커스텀 키 CRUD — 네트워크 없이 결과 주입.
const createCustomKey = vi.fn();
const updateCustomKeyValue = vi.fn();
const findCustomKeyRow = vi.fn();
const bustTranslationCache = vi.fn().mockResolvedValue(undefined);
vi.mock('../../hooks/useInlineEdit', () => ({
  createCustomKey: (...a: unknown[]) => createCustomKey(...a),
  updateCustomKeyValue: (...a: unknown[]) => updateCustomKeyValue(...a),
  findCustomKeyRow: (...a: unknown[]) => findCustomKeyRow(...a),
  bustTranslationCache: (...a: unknown[]) => bustTranslationCache(...a),
  // 펼침=통합 TranslationField. 그 동기화 구독이 읽는 이벤트명 export.
  EDITOR_TRANSLATIONS_REFRESHED_EVENT: 'g7le:editor-translations-refreshed',
}));

// TranslationEngine — `$t:custom.login.1` 를 현재 로케일 값으로 해석(미리보기 시작값).
vi.mock('../../../TranslationEngine', () => ({
  TranslationEngine: {
    getInstance: () => ({
      translate: (key: string) =>
        key === 'custom.login.1'
          ? '이메일'
          : key === 'auth.login.email'
            ? '이메일(코어)'
            : key === 'board.edit_post'
              ? '게시글 수정'
              : key === 'board.new_post'
                ? '게시글 작성'
                : key,
    }),
  },
}));

// 활성 로케일 — readSupportedLocales 모킹(펼침 폼 행).
vi.mock('../../components/LocaleSwitcher', async (orig) => {
  const actual = (await orig()) as Record<string, unknown>;
  return { ...actual, readSupportedLocales: () => ['ko', 'en', 'ja'] };
});

// 칸자리 칩/+데이터 키화 API (inlineBindingApi). 네트워크 없이 결과 주입.
const apiFindCustomKeyRow = vi.fn();
const putSingleLocaleKeyValue = vi.fn().mockResolvedValue({ kind: 'ok' });
const keyifyWithNewBinding = vi.fn();
const insertBindingIntoParamKey = vi.fn();
vi.mock('../../components/property-controls/inlineBindingApi', () => ({
  findCustomKeyRow: (...a: unknown[]) => apiFindCustomKeyRow(...a),
  putSingleLocaleKeyValue: (...a: unknown[]) => putSingleLocaleKeyValue(...a),
  keyifyWithNewBinding: (...a: unknown[]) => keyifyWithNewBinding(...a),
  insertBindingIntoParamKey: (...a: unknown[]) => insertBindingIntoParamKey(...a),
}));

import { classifyCustomText } from '../../hooks/useCustomTranslation';
import { I18nTextField } from '../../components/property-controls/I18nTextField';
import { ControlRenderer } from '../../components/property-controls/ControlRenderer';
import { registerCoreWidgets } from '../../spec/registerCoreWidgets';
import type { EditorControlSpec } from '../../spec/specTypes';
import type { EditorNode } from '../../utils/layoutTreeUtils';

const t = (k: string, p?: Record<string, string | number>) =>
  p ? `${k}:${Object.values(p).join(',')}` : k;

afterEach(() => cleanup());
beforeEach(() => {
  createCustomKey.mockReset();
  updateCustomKeyValue.mockReset();
  findCustomKeyRow.mockReset();
  bustTranslationCache.mockClear();
  apiFindCustomKeyRow.mockReset();
  putSingleLocaleKeyValue.mockReset().mockResolvedValue({ kind: 'ok' });
  keyifyWithNewBinding.mockReset();
  insertBindingIntoParamKey.mockReset();
});

describe('classifyCustomText (순수 분류)', () => {
  const translate = (k: string) =>
    k === 'custom.login.1'
      ? '이메일'
      : k === 'auth.login.email'
        ? '이메일(코어)'
        : k === 'custom.form.placeholder'
          ? '최소 {p0}자 입력'
          : '';

  it('평문 → customKey null, binding false, paramKey false, displayValue=원문', () => {
    expect(classifyCustomText('안내 문구', translate)).toEqual({
      customKey: null,
      binding: false,
      paramKey: false,
      displayValue: '안내 문구',
    });
  });

  it('$t:custom.* 키 → customKey 추출 + 해석값 미리보기', () => {
    expect(classifyCustomText('$t:custom.login.1', translate)).toEqual({
      customKey: 'custom.login.1',
      binding: false,
      paramKey: false,
      displayValue: '이메일',
    });
  });

  it('임의 $t: 키(코어/언어팩) → customKey null(평문 동격), 시작값=해석값', () => {
    expect(classifyCustomText('$t:auth.login.email', translate)).toEqual({
      customKey: null,
      binding: false,
      paramKey: false,
      displayValue: '이메일(코어)',
    });
  });

  it('{{...}} 바인딩식 → binding true(편집 비대상)', () => {
    const r = classifyCustomText('{{user.email}}', translate);
    expect(r.binding).toBe(true);
    expect(r.customKey).toBeNull();
    expect(r.paramKey).toBe(false);
  });

  it('해석 실패 키 → 빈 displayValue(raw 키 노출 회피)', () => {
    expect(classifyCustomText('$t:unknown.key', translate).displayValue).toBe('');
  });

  // param 정규화 custom 키는 binding 보다 먼저 분기(raw 노출 차단).
  it('param 정규화 custom 키($t:custom.X|pN={{}}) → paramKey true, customKey 추출, binding false', () => {
    const r = classifyCustomText('$t:custom.form.placeholder|p0={{user.minLength}}', translate);
    expect(r).toEqual({
      customKey: 'custom.form.placeholder',
      binding: false,
      paramKey: true,
      displayValue: '최소 {p0}자 입력',
    });
  });

  it('param custom 키 — param 값 보간(|p0={{}})이 binding 으로 오분류되지 않음', () => {
    // 종전 결함: `|p0={{...}}` 의 `{{...}}` 가 BINDING_RE 에 먼저 걸려 binding:true → raw 코드 배지 노출.
    const r = classifyCustomText('$t:custom.x|p0={{a.b}}|p1={{c.d}}', translate);
    expect(r.binding).toBe(false);
    expect(r.paramKey).toBe(true);
    expect(r.customKey).toBe('custom.x');
  });

  it('lang named-param($t:user.*|count={{}}) → custom 키 아님 → paramKey false(키화 전 폴백)', () => {
    // custom. 접두가 아니므로 param 키 분기 제외 → BINDING_RE → binding(키화 전 코드 배지/raw 폴백).
    const r = classifyCustomText('$t:user.identity.remaining|count={{Math.max(0,3)}}', translate);
    expect(r.paramKey).toBe(false);
    expect(r.customKey).toBeNull();
    expect(r.binding).toBe(true);
  });
});

describe('I18nTextField — A.미리보기 + 커밋', () => {
  it('평문 값 → 미리보기 input 에 평문, 🌐 뱃지에 + 힌트(키 미생성)', () => {
    render(<I18nTextField value="안내" onChange={vi.fn()} t={t} />);
    expect((screen.getByTestId('g7le-i18n-text-field-preview') as HTMLInputElement).value).toBe('안내');
    expect(screen.getByTestId('g7le-i18n-text-field-toggle').textContent).toContain('+');
  });

  it('$t:custom.* 키 → 미리보기에 해석값(이메일), raw 키 미노출', () => {
    render(<I18nTextField value="$t:custom.login.1" onChange={vi.fn()} t={t} />);
    const input = screen.getByTestId('g7le-i18n-text-field-preview') as HTMLInputElement;
    expect(input.value).toBe('이메일');
    expect(input.value).not.toContain('$t:');
  });

  it('평문 입력 blur → createCustomKey 호출 후 토큰을 onChange', async () => {
    createCustomKey.mockResolvedValue({ kind: 'ok', resource: { id: 7, translation_key: 'custom.login.7', values: { ko: '비밀번호' }, lock_version: 0 } });
    const onChange = vi.fn();
    render(<I18nTextField value="" onChange={onChange} t={t} />);
    const input = screen.getByTestId('g7le-i18n-text-field-preview');
    fireEvent.change(input, { target: { value: '비밀번호' } });
    fireEvent.blur(input);
    await waitFor(() => expect(createCustomKey).toHaveBeenCalled());
    expect(createCustomKey).toHaveBeenCalledWith('sirsoft-basic', 'login', 'ko', '비밀번호');
    expect(onChange).toHaveBeenCalledWith('$t:custom.login.7');
  });

  it('기존 커스텀 키 값 변경 blur → updateCustomKeyValue(현재 로케일), onChange 미발화(토큰 유지)', async () => {
    updateCustomKeyValue.mockResolvedValue({ kind: 'ok' });
    const onChange = vi.fn();
    render(<I18nTextField value="$t:custom.login.1" onChange={onChange} t={t} />);
    const input = screen.getByTestId('g7le-i18n-text-field-preview');
    fireEvent.change(input, { target: { value: 'E-Mail' } });
    fireEvent.blur(input);
    await waitFor(() => expect(updateCustomKeyValue).toHaveBeenCalled());
    expect(updateCustomKeyValue).toHaveBeenCalledWith('sirsoft-basic', 'custom.login.1', 'ko', 'E-Mail');
    expect(onChange).not.toHaveBeenCalled();
  });

  it('변경 없음 blur → CRUD 미호출(noop)', async () => {
    const onChange = vi.fn();
    render(<I18nTextField value="$t:custom.login.1" onChange={onChange} t={t} />);
    const input = screen.getByTestId('g7le-i18n-text-field-preview');
    fireEvent.blur(input); // draft null
    await Promise.resolve();
    expect(createCustomKey).not.toHaveBeenCalled();
    expect(updateCustomKeyValue).not.toHaveBeenCalled();
  });

  it('C.바인딩식 → 읽기전용 배지(input 없음)', () => {
    render(<I18nTextField value="{{user.email}}" onChange={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-i18n-text-field-binding')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-i18n-text-field-preview')).not.toBeInTheDocument();
  });

  // 표현식 분해 트리는 opt-in(enableExpressionTree). 기본(미전달)은 종전 읽기전용 배지.
  // 최상위(expressionTreeCollapsible)는 접힌 미리보기 + [수정]/[접기].
  // 최상위 빌더는 **단일식이라도 SegmentedValueEditor**(조각 추가 버튼
  // 보유)로 띄운다. 종전엔 단일식이 ConditionalValueEditor(추가 버튼 없음)로 가서 일반→표현식 전환 후
  // 고정글자/조건분기/데이터 조각을 추가할 수 없었다. SegmentedValueEditor 가 단일식을 expression 조각
  // 1개로 분해 → 그 조각 내부는 ConditionalValueEditor(분기 트리) 재귀.
  it('C.표현식+다국어(F) + collapsible → 접힌 미리보기, [수정] → 세그먼트 편집기(조각 추가 버튼)↔[접기]', () => {
    render(
      <I18nTextField
        value="{{route.id ? '$t:edit' : '$t:create'}}"
        onChange={vi.fn()}
        t={t}
        enableExpressionTree
        expressionTreeCollapsible
      />,
    );
    // 기본 접힘 — 미리보기 + [수정], 빌더 미렌더.
    expect(screen.getByTestId('g7le-i18n-text-field-preview')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-i18n-text-field-seg')).toBeNull();
    // [수정] 클릭 → SegmentedValueEditor(조각 추가 버튼 3종) + [접기] 버튼.
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-preview-edit'));
    expect(screen.getByTestId('g7le-i18n-text-field-seg')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-i18n-text-field-seg-add-text')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-i18n-text-field-seg-add-expression')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-i18n-text-field-seg-add-data')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-i18n-text-field-builder-collapse')).toBeInTheDocument();
    // [접기] 클릭 → 다시 접힌 미리보기.
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-builder-collapse'));
    expect(screen.getByTestId('g7le-i18n-text-field-preview')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-i18n-text-field-seg')).toBeNull();
  });

  // collapsible 미전달(중첩 위젯 — 세그먼트 카드 내부) → 접힘 없이 ConditionalValueEditor 직접(이중
  // 세그먼트화 방지). 단일식 카드 value 는 SegmentedValueEditor 가 이미 한 조각으로 쪼갰으므로, 그
  // 조각 내부 I18nTextField 는 트리(분기)만 그려야 한다(또 SegmentedValueEditor 면 무한 중첩).
  it('C.표현식 + enableExpressionTree(collapsible 미전달) → ConditionalValueEditor 직접(이중세그먼트 방지)', () => {
    render(
      <I18nTextField
        value="{{route.id ? '$t:edit' : '$t:create'}}"
        onChange={vi.fn()}
        t={t}
        enableExpressionTree
      />,
    );
    // 접힘 미리보기 미렌더 — 트리(ConditionalValueEditor) 직접.
    expect(screen.queryByTestId('g7le-i18n-text-field-preview')).toBeNull();
    expect(screen.getByTestId('g7le-i18n-text-field-tree')).toBeInTheDocument();
    // 중첩이라 세그먼트 편집기/[접기] 헤더 없음.
    expect(screen.queryByTestId('g7le-i18n-text-field-seg')).toBeNull();
    expect(screen.queryByTestId('g7le-i18n-text-field-builder-collapse')).toBeNull();
  });

  // 다중 세그먼트(`{{식}} 평문 {{바인딩}}`) + 표현식 조각은
  // [수정] 후 SegmentedValueEditor 로 세그먼트별 분해(종전 raw 노출 결함 해소).
  it('C.다중 세그먼트 + collapsible → 접힘 미리보기, [수정] → 세그먼트 편집기', () => {
    render(
      <I18nTextField
        value="{{route.id ? '$t:edit' : '$t:create'}} - {{form_meta?.data?.board?.name || ''}}"
        onChange={vi.fn()}
        t={t}
        enableExpressionTree
        expressionTreeCollapsible
      />,
    );
    expect(screen.getByTestId('g7le-i18n-text-field-preview')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-i18n-text-field-seg')).toBeNull();
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-preview-edit'));
    expect(screen.getByTestId('g7le-i18n-text-field-seg')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-i18n-text-field-binding')).not.toBeInTheDocument();
  });

  it('C.다중 세그먼트라도 enableExpressionTree 미전달이면 종전 읽기전용 배지', () => {
    render(
      <I18nTextField
        value="{{route.id ? '$t:edit' : '$t:create'}} - {{form_meta?.data?.board?.name || ''}}"
        onChange={vi.fn()}
        t={t}
      />,
    );
    expect(screen.getByTestId('g7le-i18n-text-field-binding')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-i18n-text-field-seg')).not.toBeInTheDocument();
  });

  it('C.표현식이라도 enableExpressionTree 미전달이면 종전 읽기전용 배지(opt-in 게이트)', () => {
    render(<I18nTextField value="{{route.id ? '$t:edit' : '$t:create'}}" onChange={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-i18n-text-field-binding')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-i18n-text-field-tree')).not.toBeInTheDocument();
  });

  // 모드 전환: 일반 이름 ↔ 표현식.
  //  · 일반(평문/키) + collapsible → [+표현식] 버튼 → 현재 값을 then 분기로 seed → onChange(`{{...}}`).
  //  · enableExpressionTree 미전달/collapsible 미전달이면 버튼 미렌더(최상위 진입점 전용).
  it('A.일반(평문) + collapsible → [+표현식] 버튼 → 조건 분기 seed onChange', () => {
    const onChange = vi.fn();
    render(
      <I18nTextField value="안녕" onChange={onChange} t={t} enableExpressionTree expressionTreeCollapsible />,
    );
    const btn = screen.getByTestId('g7le-i18n-text-field-to-expr');
    fireEvent.click(btn);
    // 현재 값(안녕)을 then 분기로, else 빈 칸. 기준 값(조건)은 빈 채로 시작(route.id 하드코딩 금지,
    // 빈 조건은 중립 토큰 `false` 로 직렬화된다.
    expect(onChange).toHaveBeenCalledWith("{{false ? '안녕' : ''}}");
  });

  it('A.일반(단일 다국어키) + collapsible → [+표현식] → 키 보존 seed(빈 조건)', () => {
    const onChange = vi.fn();
    render(
      <I18nTextField value="$t:board.title" onChange={onChange} t={t} enableExpressionTree expressionTreeCollapsible />,
    );
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-to-expr'));
    expect(onChange).toHaveBeenCalledWith("{{false ? '$t:board.title' : ''}}");
  });

  it('A.빈 값 + collapsible → [+표현식] → 빈 분기 seed(빈 조건)', () => {
    const onChange = vi.fn();
    render(
      <I18nTextField value="" onChange={onChange} t={t} enableExpressionTree expressionTreeCollapsible />,
    );
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-to-expr'));
    expect(onChange).toHaveBeenCalledWith("{{false ? '' : ''}}");
  });

  it('A.일반 + enableExpressionTree 미전달이면 [+표현식] 버튼 미렌더(opt-in 게이트)', () => {
    render(<I18nTextField value="안녕" onChange={vi.fn()} t={t} />);
    expect(screen.queryByTestId('g7le-i18n-text-field-to-expr')).toBeNull();
  });

  it('A.일반 + collapsible 미전달이면 [+표현식] 버튼 미렌더(최상위 진입점 전용)', () => {
    render(<I18nTextField value="안녕" onChange={vi.fn()} t={t} enableExpressionTree />);
    expect(screen.queryByTestId('g7le-i18n-text-field-to-expr')).toBeNull();
  });

  // 표현식 빌더 헤더 → [일반 이름으로] 버튼 → 확인 대화 → 첫 분기 값으로 환원 onChange.
  it('C.표현식 빌더 → [일반 이름으로] → 확인 대화 → 첫 분기 값 환원 onChange', () => {
    const onChange = vi.fn();
    render(
      <I18nTextField
        value="{{route.id ? '$t:board.edit_post' : '$t:board.new_post'}}"
        onChange={onChange}
        t={t}
        enableExpressionTree
        expressionTreeCollapsible
      />,
    );
    // [수정]으로 빌더 펼침 → [일반 이름으로] 버튼.
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-preview-edit'));
    const revertBtn = screen.getByTestId('g7le-i18n-text-field-to-plain');
    fireEvent.click(revertBtn);
    // 확인 대화 표시(아직 onChange 미발화).
    expect(screen.getByTestId('g7le-i18n-text-field-to-plain-confirm')).toBeInTheDocument();
    expect(onChange).not.toHaveBeenCalled();
    // [되돌리기] 확정 → 첫 분기(참) 키 값으로 환원.
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-to-plain-confirm-ok'));
    expect(onChange).toHaveBeenCalledWith('$t:board.edit_post');
  });

  // 되돌리기 확인 대화의 미리보기 값은 raw `$t:` 키가 아니라 **다국어
  // 해석값**으로 보여야 한다(사용자가 어떤 글자가 남는지 알 수 있게). 종전엔 reduceExpressionToPlain
  // 의 raw 토큰(`$t:board.edit_post`)을 그대로 표시했다.
  it('C.[일반 이름으로] 확인 대화 미리보기 → $t: 키를 해석한 값 표시(raw 키 아님, B1)', () => {
    render(
      <I18nTextField
        value="{{route.id ? '$t:board.edit_post' : '$t:board.new_post'}}"
        onChange={vi.fn()}
        t={t}
        enableExpressionTree
        expressionTreeCollapsible
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-preview-edit'));
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-to-plain'));
    const confirm = screen.getByTestId('g7le-i18n-text-field-to-plain-confirm');
    // 미리보기에 해석값 "게시글 수정"(TranslationEngine mock) 노출, raw `$t:board.edit_post` 미노출.
    expect(confirm).toHaveTextContent('게시글 수정');
    expect(confirm.textContent).not.toContain('$t:board.edit_post');
    // 확정 onChange 는 여전히 키(저장값) 보존 — 표시만 해석.
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-to-plain-confirm-ok'));
  });

  it('C.표현식 빌더 → [일반 이름으로] → [취소] → 환원 안 함(표현식 유지)', () => {
    const onChange = vi.fn();
    render(
      <I18nTextField
        value="{{route.id ? '$t:board.edit_post' : '$t:board.new_post'}}"
        onChange={onChange}
        t={t}
        enableExpressionTree
        expressionTreeCollapsible
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-preview-edit'));
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-to-plain'));
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-to-plain-confirm-cancel'));
    // 확인 닫힘 + onChange 미발화(표현식 유지).
    expect(screen.queryByTestId('g7le-i18n-text-field-to-plain-confirm')).toBeNull();
    expect(onChange).not.toHaveBeenCalled();
  });

  it('C.D(단일 바인딩) + 후보 없음 → 읽기전용 배지(데이터 피커 불가)', () => {
    render(<I18nTextField value="{{user.email}}" onChange={vi.fn()} t={t} enableExpressionTree />);
    expect(screen.getByTestId('g7le-i18n-text-field-binding')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-i18n-text-field-tree')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-i18n-text-field-data')).toBeNull();
  });

  // D 단일 바인딩 + 후보 풀
  // → 데이터 칩 + [데이터 바꾸기] 피커(읽기전용 배지 대체). 선택 시 buildBindingExpression 으로 onChange.
  it('C.D(단일 바인딩) + 후보 보유 + enableExpressionTree → 데이터 칩 + [데이터 바꾸기](배지 미렌더)', () => {
    render(
      <I18nTextField
        value="{{user.email}}"
        onChange={vi.fn()}
        t={t}
        enableExpressionTree
        candidates={[
          { expression: '{{user.email}}', source: 'data_source', sourceId: 'user', path: 'email', shape: 'scalar', preview: 'a@b.c' },
          { expression: '{{user.name}}', source: 'data_source', sourceId: 'user', path: 'name', shape: 'scalar', preview: '홍길동' },
        ]}
      />,
    );
    expect(screen.getByTestId('g7le-i18n-text-field-data')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-i18n-text-field-data-chip')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-i18n-text-field-data-change')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-i18n-text-field-binding')).not.toBeInTheDocument();
  });

  // [일반 이름으로] 되돌려 단일 데이터 바인딩이 된 값은, 최상위
  // collapsible 진입점이어도 **표현식 트리(SegmentedValueEditor)로 펼치지 말고** 설명처럼 데이터 칩 +
  // [데이터 바꾸기] 로 보여야 한다. 단일 순수 바인딩은 분해할 표현식 구조가 없으므로 트리가 부적합.
  it('C.D(단일 바인딩) + collapsible 까지 켜도 표현식 트리 아닌 데이터 칩', () => {
    render(
      <I18nTextField
        value="{{product.title}}"
        onChange={vi.fn()}
        t={t}
        enableExpressionTree
        expressionTreeCollapsible
        candidates={[
          { expression: '{{product.title}}', source: 'data_source', sourceId: 'product', path: 'title', shape: 'scalar', preview: '티셔츠' },
        ]}
      />,
    );
    // 데이터 칩 + [데이터 바꾸기] — 설명 칸과 동일.
    expect(screen.getByTestId('g7le-i18n-text-field-data')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-i18n-text-field-data-change')).toBeInTheDocument();
    // 표현식 트리(조각/조건 빌더)는 미렌더.
    expect(screen.queryByTestId('g7le-i18n-text-field-seg')).toBeNull();
    expect(screen.queryByTestId('g7le-i18n-text-field-tree')).toBeNull();
  });

  // 회귀 — 데이터 선택은 **폴백 없는 순수
  // 바인딩**(`{{src?.path}}`)을 흘려야 한다. `?? ''` 폴백을 붙이면 리프가 fallback 노드로 재분해돼
  // 데이터 바꿀 때마다 "기본값 > 기본값 > …" 폴백이 무한 중첩된다.
  it('C.D 데이터 바꾸기 선택 → 폴백 없는 순수 바인딩(`?? ` 없음, 중첩 회귀 방지)', () => {
    const onChange = vi.fn();
    render(
      <I18nTextField
        value="{{user.email}}"
        onChange={onChange}
        t={t}
        enableExpressionTree
        candidates={[
          { expression: '{{user.name}}', source: 'data_source', sourceId: 'user', path: 'name', shape: 'scalar', preview: '홍길동' },
        ]}
      />,
    );
    // [데이터 바꾸기] → 피커 펼침 → 후보 선택.
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-data-change'));
    const opt = screen.getByTestId('g7le-inline-binding-candidate-{{user.name}}');
    fireEvent.click(opt);
    const out = onChange.mock.calls.at(-1)![0] as string;
    expect(out).toBe('{{user?.name}}'); // 폴백 없음.
    expect(out).not.toContain('??'); // `?? ''` 금지(중첩 유발).
  });

  it('C.D + 후보 보유라도 enableExpressionTree 미전달이면 읽기전용 배지(opt-in 게이트)', () => {
    render(
      <I18nTextField
        value="{{user.email}}"
        onChange={vi.fn()}
        t={t}
        candidates={[
          { expression: '{{user.email}}', source: 'data_source', sourceId: 'user', path: 'email', shape: 'scalar', preview: 'a@b.c' },
        ]}
      />,
    );
    expect(screen.getByTestId('g7le-i18n-text-field-binding')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-i18n-text-field-data')).toBeNull();
  });

  it('C.복잡 식(함수/산술)은 enableExpressionTree 켜도 읽기전용 배지(손상 0 폴백)', () => {
    render(
      <I18nTextField
        value="{{(items ?? []).reduce((a,b)=>a+b,0).toLocaleString()}}"
        onChange={vi.fn()}
        t={t}
        enableExpressionTree
      />,
    );
    expect(screen.getByTestId('g7le-i18n-text-field-binding')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-i18n-text-field-tree')).not.toBeInTheDocument();
  });
});

// 설정 참조($core_settings:/$module_settings:/$plugin_settings:)가 평문 input 에
// raw 로 노출되던 결함의 회귀 가드. classify 가 binding 으로 못 잡는 설정참조를 칩으로 시각화.
describe('I18nTextField — 설정 참조($*_settings:) 칩 시각화', () => {
  it('단독 $core_settings: → 칩 시각화(raw 미노출) + [수정] 버튼', () => {
    render(<I18nTextField value="$core_settings:general.site_name" onChange={vi.fn()} t={t} />);
    const chips = screen.getByTestId('g7le-i18n-text-field-settings-ref-chips');
    expect(chips).toBeInTheDocument();
    // 친화 라벨(site_name) 칩, raw `$core_settings:` 문자열은 노출 안 됨.
    expect(chips.textContent).toContain('site_name');
    expect(chips.textContent).not.toContain('$core_settings:');
    expect(screen.getByTestId('g7le-i18n-text-field-settings-ref-edit')).toBeInTheDocument();
    // 평문 미리보기 input 은 칩 모드라 미렌더.
    expect(screen.queryByTestId('g7le-i18n-text-field-preview')).not.toBeInTheDocument();
  });

  it('$module_settings:/$plugin_settings: 도 칩 시각화', () => {
    const { rerender } = render(<I18nTextField value="$module_settings:sirsoft-ecommerce:shop.name" onChange={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-i18n-text-field-settings-ref-chips').textContent).toContain('name');
    rerender(<I18nTextField value="$plugin_settings:gdpr.consent_text" onChange={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-i18n-text-field-settings-ref-chips').textContent).toContain('consent_text');
  });

  it('평문+설정참조 혼합 → 평문/칩 분리 시각화(raw 미노출)', () => {
    render(<I18nTextField value="우리 $core_settings:general.site_name 쇼핑몰" onChange={vi.fn()} t={t} />);
    const chips = screen.getByTestId('g7le-i18n-text-field-settings-ref-chips');
    expect(chips.textContent).toContain('우리');
    expect(chips.textContent).toContain('쇼핑몰');
    expect(chips.textContent).toContain('site_name');
    expect(chips.textContent).not.toContain('$core_settings:');
  });

  it('[수정] 클릭 → 평문 input 으로 전환(raw 직접 편집 가능)', () => {
    render(<I18nTextField value="$core_settings:general.site_name" onChange={vi.fn()} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-settings-ref-edit'));
    const input = screen.getByTestId('g7le-i18n-text-field-preview') as HTMLInputElement;
    expect(input).toBeInTheDocument();
    expect(input.value).toBe('$core_settings:general.site_name');
  });

  it('순수 평문/단일키는 설정참조 칩 분기 미진입(회귀 0)', () => {
    const { rerender } = render(<I18nTextField value="안내 문구" onChange={vi.fn()} t={t} />);
    expect(screen.queryByTestId('g7le-i18n-text-field-settings-ref-chips')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-i18n-text-field-preview')).toBeInTheDocument();
    rerender(<I18nTextField value="$t:custom.login.1" onChange={vi.fn()} t={t} />);
    expect(screen.queryByTestId('g7le-i18n-text-field-settings-ref-chips')).not.toBeInTheDocument();
  });
});

describe('I18nTextField — B.펼침 ko/en/ja', () => {
  // 펼침은 [번역] 탭과 동일 컴포넌트(TranslationField) 공유. 펼침부 testid 는
  // 통합 컴포넌트의 `g7le-translation-*` 를 쓴다(ExpandedLocaleForm 제거).
  it('🌐 펼침 + 커스텀 키 → 통합 TranslationField 로드 후 활성 로케일 행', async () => {
    findCustomKeyRow.mockResolvedValue({ id: 1, translation_key: 'custom.login.1', values: { ko: '이메일', en: 'Email' }, lock_version: 3 });
    render(<I18nTextField value="$t:custom.login.1" onChange={vi.fn()} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-toggle'));
    await waitFor(() => expect(screen.getByTestId('g7le-translation-field')).toBeInTheDocument());
    expect((screen.getByTestId('g7le-translation-input-ko') as HTMLInputElement).value).toBe('이메일');
    expect((screen.getByTestId('g7le-translation-input-en') as HTMLInputElement).value).toBe('Email');
    // 미번역(ja) 회색 + 마크.
    expect(screen.getByTestId('g7le-translation-missing-ja')).toBeInTheDocument();
  });

  it('🌐 펼침 + 평문(키 미생성) → 생성 안내', () => {
    render(<I18nTextField value="안내" onChange={vi.fn()} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-toggle'));
    expect(screen.getByTestId('g7le-i18n-text-field-expand-hint')).toBeInTheDocument();
  });

  // 회귀 가드 — 같은 위젯 인스턴스가 노드 전환(value prop 변경)으로
  // 재사용될 때(ControlRenderer key=controlKey 라 언마운트 안 됨), 펼침이 이전 노드의 키 폼으로
  // 잔존하면 stale id/lock 으로 저장이 발화해 다른 노드 번역행을 덮어쓴다. value 가 평문으로
  // 바뀌면 cls.customKey 가 null → 통합 TranslationField 가 사라지고 "키 먼저 생성" 힌트만 남아
  // 이전 키 폼 잔존이 차단된다.
  it('펼친 상태에서 value(노드/컨트롤) 변경 → 이전 키 폼 잔존 금지', async () => {
    findCustomKeyRow.mockResolvedValue({ id: 1, translation_key: 'custom.login.1', values: { ko: '이메일' }, lock_version: 3 });
    const { rerender } = render(<I18nTextField value="$t:custom.login.1" onChange={vi.fn()} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-toggle'));
    await waitFor(() => expect(screen.getByTestId('g7le-translation-field')).toBeInTheDocument());
    // 다른 노드(평문)로 전환 — 같은 위젯 인스턴스 재사용(key 동일).
    rerender(<I18nTextField value="다른 노드 평문" onChange={vi.fn()} t={t} />);
    // 이전 키의 통합 번역 폼이 사라지고(잔존 금지) 평문 상태의 "키 먼저 생성" 힌트만 남는다.
    expect(screen.queryByTestId('g7le-translation-field')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-translation-input-ko')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-i18n-text-field-expand-hint')).toBeInTheDocument();
    // 미리보기는 새 노드 평문 시작값.
    expect((screen.getByTestId('g7le-i18n-text-field-preview') as HTMLInputElement).value).toBe('다른 노드 평문');
  });
});

describe('ControlRenderer — propValue + i18n-text 분기', () => {
  const i18nPropControl: EditorControlSpec = {
    label: '$t:editor.control.input_placeholder.label',
    widget: 'i18n-text',
    apply: { type: 'propValue', propKey: 'placeholder' },
  } as never;

  it('widget=i18n-text + apply=propValue → I18nTextField 렌더(prop_i18n testid)', () => {
    const node: EditorNode = { type: 'basic', name: 'Input', props: { placeholder: '$t:custom.login.1' } };
    render(
      <ControlRenderer controlKey="inputPlaceholder" control={i18nPropControl} node={node} t={t} onPatch={vi.fn()} />,
    );
    expect(screen.getByTestId('g7le-prop-i18n-inputPlaceholder')).toBeInTheDocument();
    // 미리보기에 해석값.
    expect((screen.getByTestId('g7le-prop-i18n-inputPlaceholder-preview') as HTMLInputElement).value).toBe('이메일');
  });

  it('평문 placeholder 입력 blur → createCustomKey → onPatch 로 props.placeholder 토큰 기록', async () => {
    createCustomKey.mockResolvedValue({ kind: 'ok', resource: { id: 9, translation_key: 'custom.login.9', values: {}, lock_version: 0 } });
    const onPatch = vi.fn();
    const node: EditorNode = { type: 'basic', name: 'Input', props: {} };
    render(
      <ControlRenderer controlKey="inputPlaceholder" control={i18nPropControl} node={node} t={t} onPatch={onPatch} />,
    );
    const input = screen.getByTestId('g7le-prop-i18n-inputPlaceholder-preview');
    fireEvent.change(input, { target: { value: '이메일을 입력하세요' } });
    fireEvent.blur(input);
    await waitFor(() => expect(createCustomKey).toHaveBeenCalled());
    await waitFor(() => expect(onPatch).toHaveBeenCalled());
    const patched = onPatch.mock.calls[0][0] as EditorNode;
    expect((patched.props as Record<string, unknown>).placeholder).toBe('$t:custom.login.9');
  });

  // text+propValue 는
  // i18n 키화는 안 하되(다국어 키 미생성) 평문 input 대신 DataChipValueInput(값 전용 데이터칩)으로
  // 승격한다. 종전("일반 text 위젯 유지")에서 동작이 의도적으로 바뀐 지점(테스트 의미 갱신).
  it('text + propValue → DataChipValueInput 승격(값 전용 데이터칩, i18n 키화 없음)', () => {
    registerCoreWidgets();
    const textControl: EditorControlSpec = {
      label: 'imageSrc',
      widget: 'text',
      apply: { type: 'propValue', propKey: 'src' },
    } as never;
    const node: EditorNode = { type: 'basic', name: 'Image', props: { src: 'https://x/y.png' } };
    render(<ControlRenderer controlKey="imgSrc" control={textControl} node={node} t={t} onPatch={vi.fn()} />);
    // 값 전용 칩 입력기(DataChipValueInput) 진입 — i18n 키화 위젯(prop-i18n)도 bare text 위젯도 아님.
    expect(screen.getByTestId('g7le-prop-value-imgSrc')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-prop-i18n-imgSrc')).not.toBeInTheDocument();
    // 평문 URL 은 평문 input 에 그대로(키화 0, 회귀 0).
    expect((screen.getByTestId('g7le-prop-value-imgSrc-input') as HTMLInputElement).value).toBe('https://x/y.png');
  });

  it('text + propValue 평문값 변경 → onPatch 로 prop 기록(다국어 키 미생성)', () => {
    registerCoreWidgets();
    const textControl: EditorControlSpec = {
      label: 'imageSrc',
      widget: 'text',
      apply: { type: 'propValue', propKey: 'src' },
    } as never;
    const onPatch = vi.fn();
    const node: EditorNode = { type: 'basic', name: 'Image', props: {} };
    render(<ControlRenderer controlKey="imgSrc" control={textControl} node={node} t={t} onPatch={onPatch} />);
    const input = screen.getByTestId('g7le-prop-value-imgSrc-input');
    fireEvent.change(input, { target: { value: '/img/logo.png' } });
    expect(createCustomKey).not.toHaveBeenCalled(); // 값 전용 — 다국어 키 생성 없음.
    const patched = onPatch.mock.calls.at(-1)![0] as EditorNode;
    expect((patched.props as Record<string, unknown>).src).toBe('/img/logo.png');
  });

  // text + propValue 가 아닌(apply 없는) recipe/computed/condition 파라미터 text 위젯은 ControlRenderer
  // 를 거치지 않으므로 본 분기 무관 — bare text 위젯 그대로(식별자/셀렉터 보존, 부작용 0).
  it('text 위젯 + apply 없음(recipe 파라미터형) → bare text 위젯 유지(DataChipValueInput 미승격)', () => {
    registerCoreWidgets();
    const recipeTextControl: EditorControlSpec = { label: 'selector', widget: 'text' } as never;
    const node: EditorNode = { type: 'basic', name: 'X', props: {} };
    render(<ControlRenderer controlKey="selector" control={recipeTextControl} node={node} t={t} onPatch={vi.fn()} />);
    expect(screen.queryByTestId('g7le-prop-value-selector')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-widget-text')).toBeInTheDocument();
  });

  // i18n-text propValue 도 표현식 분해 트리 opt-in 이 켜져, 분해 가능식(`{{cond ? a: b}}`)은
  // 접힌 미리보기([수정])로 진입한다(종전 읽기전용 배지 대체). 못 푸는 복잡식만 배지(손상 0).
  it('i18n-text + propValue + 분해 가능식 → 표현식 접힌 미리보기(읽기전용 배지 아님)', () => {
    const node: EditorNode = { type: 'basic', name: 'Input', props: { placeholder: "{{route?.id ? '$t:auth.login.email' : '$t:board.new_post'}}" } };
    render(
      <ControlRenderer controlKey="inputPlaceholder" control={i18nPropControl} node={node} t={t} onPatch={vi.fn()} />,
    );
    // 접힌 미리보기 진입점(expressionTreeCollapsible) — 종전 raw 읽기전용 배지(-binding)가 아니다.
    expect(screen.getByTestId('g7le-prop-i18n-inputPlaceholder-preview')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-prop-i18n-inputPlaceholder-binding')).not.toBeInTheDocument();
  });
});

// 칸자리 칩 입력 + `+데이터` 키화.
describe('I18nTextField — 칸자리 칩(데이터 든 param 키) + +데이터 키화', () => {
  const scalarCandidate = {
    expression: '{{user.name}}',
    source: 'data_source' as const,
    sourceId: 'user',
    path: 'name',
    shape: 'scalar' as const,
    preview: '홍길동',
  };

  it('param 키 값($t:custom.X|p0={{}}) → 미리보기 input 아닌 칸자리 칩 입력기 상시 렌더', async () => {
    apiFindCustomKeyRow.mockResolvedValue({ id: 1, translation_key: 'custom.x', values: { ko: '{p0} 님' }, lock_version: 1 });
    render(<I18nTextField value="$t:custom.x|p0={{user.name}}" onChange={vi.fn()} t={t} candidates={[scalarCandidate]} />);
    // 칸자리는 평문 input 이 아니라 칩 합성 위젯.
    expect(screen.queryByTestId('g7le-i18n-text-field-preview')).not.toBeInTheDocument();
    await waitFor(() => expect(screen.getByTestId('g7le-i18n-text-field-chipfield')).toBeInTheDocument());
    // {p0} 는 원자 칩으로 렌더(PlaceholderChipInput).
    await waitFor(() =>
      expect(screen.getByTestId('g7le-chip-g7le-i18n-text-field-field-p0')).toBeInTheDocument(),
    );
  });

  // lang 키 라벨(`$t:shop.tabs.x`)을 키화할 때 raw `$t:` 가 키
  // 값에 박히지 않도록, +데이터 키화가 keyifyWithNewBinding 에 **lang 키를 평문화하는 resolveLang**
  // 을 넘겨야 한다(종전 항등 함수 → `$t:shop.tabs.detail_info ⠿🔗data.name` raw 누출 라이브 재현).
  it('lang 키 라벨 +데이터 키화 → resolveLang 인자가 $t: 키를 평문화(항등 함수 아님)', async () => {
    keyifyWithNewBinding.mockResolvedValue({ kind: 'ok', text: '$t:custom.new|p0={{user.name}}', key: 'custom.new' });
    // shop.tabs.detail_info → "상품 정보" 로 해석하는 t.
    const langT = (k: string) => (k === 'shop.tabs.detail_info' ? '상품 정보' : k);
    render(<I18nTextField value="$t:shop.tabs.detail_info" onChange={vi.fn()} t={langT} candidates={[scalarCandidate]} />);
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-plus-data-btn'));
    const opt = await screen.findByTestId('g7le-inline-binding-candidate-{{user.name}}');
    fireEvent.click(opt);
    await waitFor(() => expect(keyifyWithNewBinding).toHaveBeenCalled());
    // 9번째 인자(index 8) = resolveLang. 항등 함수면 raw `$t:` 가 그대로 반환된다(결함).
    const resolveLangArg = keyifyWithNewBinding.mock.calls[0][8] as (s: string) => string;
    expect(typeof resolveLangArg).toBe('function');
    // lang 키를 평문으로 치환(raw `$t:` 미잔존).
    expect(resolveLangArg('$t:shop.tabs.detail_info')).toBe('상품 정보');
    expect(resolveLangArg('$t:shop.tabs.detail_info')).not.toContain('$t:');
  });

  it('평문 + candidates → +데이터 버튼 → 후보 선택 → keyifyWithNewBinding → onChange(param 키 텍스트)', async () => {
    keyifyWithNewBinding.mockResolvedValue({ kind: 'ok', text: '$t:custom.new|p0={{user.name}}', key: 'custom.new' });
    const onChange = vi.fn();
    render(<I18nTextField value="안녕" onChange={onChange} t={t} candidates={[scalarCandidate]} />);
    // 평문 → 미리보기 input + +데이터 버튼.
    expect(screen.getByTestId('g7le-i18n-text-field-preview')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-plus-data-btn'));
    // 피커 열림 → 후보 선택(InlineBindingScalarPicker 의 후보 testid = expression 기반).
    const opt = await screen.findByTestId('g7le-inline-binding-candidate-{{user.name}}');
    fireEvent.click(opt);
    await waitFor(() => expect(keyifyWithNewBinding).toHaveBeenCalled());
    await waitFor(() => expect(onChange).toHaveBeenCalledWith('$t:custom.new|p0={{user.name}}'));
  });

  it('param 키 칸자리 +데이터 → insertBindingIntoParamKey(키 승계) → onChange(param 추가 텍스트)', async () => {
    apiFindCustomKeyRow.mockResolvedValue({ id: 1, translation_key: 'custom.x', values: { ko: '{p0} 님' }, lock_version: 1 });
    insertBindingIntoParamKey.mockResolvedValue({ kind: 'ok', text: '$t:custom.x|p0={{user.name}}|p1={{user.email}}', paramName: 'p1' });
    const onChange = vi.fn();
    render(<I18nTextField value="$t:custom.x|p0={{user.name}}" onChange={onChange} t={t} candidates={[scalarCandidate]} />);
    await waitFor(() => expect(screen.getByTestId('g7le-i18n-text-field-chipfield')).toBeInTheDocument());
    // 데이터 칩 모드 '+데이터' — 평문 모드와 동일하게 입력칸 우측 액션 행(g7le-...-plus-data-btn).
    // 내부 칩 위젯 버튼은 숨겨지고(hideInsertButton) 커서 위치 삽입은 caretRef 로 보존.
    expect(screen.queryByTestId('g7le-chip-insert-g7le-i18n-text-field-field')).not.toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-plus-data-btn'));
    const opt = await screen.findByTestId('g7le-inline-binding-candidate-{{user.name}}');
    fireEvent.click(opt);
    await waitFor(() => expect(insertBindingIntoParamKey).toHaveBeenCalled());
    await waitFor(() => expect(onChange).toHaveBeenCalledWith('$t:custom.x|p0={{user.name}}|p1={{user.email}}'));
  });

  it('데이터 칩 모드 액션 버튼 일관성 — +데이터·ƒx 표현식이 입력칸 우측 행에 노출', async () => {
    apiFindCustomKeyRow.mockResolvedValue({ id: 1, translation_key: 'custom.x', values: { ko: '{p0} 님' }, lock_version: 1 });
    render(
      <I18nTextField
        value="$t:custom.x|p0={{user.name}}"
        onChange={vi.fn()}
        t={t}
        candidates={[scalarCandidate]}
        enableExpressionTree
        expressionTreeCollapsible
      />,
    );
    await waitFor(() => expect(screen.getByTestId('g7le-i18n-text-field-chipfield')).toBeInTheDocument());
    // 평문 모드와 동일한 testid 의 +데이터 / ƒx 표현식 버튼이 칩 모드에서도 노출(버튼 사라짐 결함 회귀 차단).
    expect(screen.getByTestId('g7le-i18n-text-field-plus-data-btn')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-i18n-text-field-to-expr')).toBeInTheDocument();
  });

  it('데이터 칩 모드 ƒx 표현식 → 조건 분기 표현식으로 승격(칩 보존, onChange)', async () => {
    apiFindCustomKeyRow.mockResolvedValue({ id: 1, translation_key: 'custom.x', values: { ko: '{p0} 님' }, lock_version: 1 });
    const onChange = vi.fn();
    render(
      <I18nTextField
        value="$t:custom.x|p0={{user.name}}"
        onChange={onChange}
        t={t}
        candidates={[scalarCandidate]}
        enableExpressionTree
        expressionTreeCollapsible
      />,
    );
    await waitFor(() => expect(screen.getByTestId('g7le-i18n-text-field-to-expr')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('g7le-i18n-text-field-to-expr'));
    // 조건 분기 표현식(`{{... ? '값' : ''}}`)으로 승격 — 현재 칩 값을 then 분기에 보존.
    await waitFor(() => expect(onChange).toHaveBeenCalled());
    const seeded = onChange.mock.calls[onChange.mock.calls.length - 1][0];
    expect(typeof seeded).toBe('string');
    expect(seeded).toContain('?'); // 조건 분기 표현식.
    expect(seeded).toContain('$t:custom.x|p0={{user.name}}'); // 칩 보존.
  });

  it('candidates 미전달 → +데이터 버튼 숨김(디그레이드, 칩/키화 외 동작은 유지)', () => {
    render(<I18nTextField value="안녕" onChange={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-i18n-text-field-preview')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-i18n-text-field-plus-data-btn')).not.toBeInTheDocument();
  });
});
