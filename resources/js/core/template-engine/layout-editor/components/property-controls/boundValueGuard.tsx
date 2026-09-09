/**
 * boundValueGuard.tsx — 데이터 연결 값(바인딩 표현식) 보호 공용 프리미티브
 *
 * 레이아웃의 prop 자리에는 `{{_global.settings?.general?.site_logo_url}}` 같은 **표현식
 * 문자열**이 저장돼 있을 수 있다. 그 값을 편집기 위젯이 그대로 읽으면 위젯은 그것을
 * 해석하지 못해 **빈 컨트롤**로 보이고, 운영자가 무심코 조작하는 순간 그 연결이
 * 소리 없이 사라진다 — 값 하나가 아니라 **환경설정과의 연결**이 끊기고, 원문이 화면
 * 어디에도 남지 않아 되돌릴 수단조차 없다.
 *
 * 그래서 바인딩 값은 ① 원문을 배지로 보여 주고 ② 파괴적 조작을 잠그고
 * ③ 「직접 지정으로 바꾸기」로만 명시적으로 열고 ④ 「되돌리기」로 원래 연결을 복구한다.
 *
 * **이 판정과 UI 를 위젯마다 복붙하지 않는다.** 복붙하면 한 곳이 빠져도 오류가 나지
 * 않고, 그 한 곳이 조용한 우회로가 된다(공개 #135 후속 실측에서 실제로 「이미지 관리」
 * 진입 하나만 열려 있었다). 새 위젯을 만들 때도 본 훅만 쓴다.
 *
 * 선례: `CoreIdControl`(chipEditing) · `I18nTextField`(settingsEditing) 이 같은 형태의
 * 디그레이드를 이미 갖고 있다. 본 모듈은 그 패턴을 위젯 공용으로 승격한 것이다.
 *
 * @since engine-v1.66.0
 */

import React, { useCallback, useState } from 'react';
import { hasInlineBinding, hasSettingsRef } from '../../spec/inlineBindingUtils';

/** 다국어 해석 함수 — `WidgetProps.t` 와 동일 시그니처 */
export type TranslateFn = (key: string, params?: Record<string, string | number>) => string;

/**
 * 값이 데이터 연결(바인딩/설정 참조) 문자열인지 판정한다.
 *
 * 인라인 바인딩(`{{...}}`)과 설정 참조를 모두 본다 — 둘 다 위젯이 해석하지 못하고
 * 덮어쓰면 소실되는 형태다.
 *
 * @param value 판정할 값 (문자열이 아니면 false)
 * @return 데이터 연결 문자열이면 true
 */
export function isBoundValue(value: unknown): value is string {
  return typeof value === 'string' && (hasInlineBinding(value) || hasSettingsRef(value));
}

/** `useBoundValueGuard` 반환 계약 */
export interface BoundValueGuard {
  /** 지금 보호 상태인가 — true 면 파괴적 조작을 잠그고 배지를 렌더한다 */
  readonly bound: boolean;
  /** 보호를 해제하고 편집 중인가 (「직접 지정으로 바꾸기」 이후) */
  readonly replacing: boolean;
  /** 보호 해제 시점에 붙잡아 둔 원문 — 「되돌리기」의 복구 대상 */
  readonly original: string | null;
  /** 「직접 지정으로 바꾸기」 — 원문을 붙잡고 편집을 연다 */
  readonly beginReplace: () => void;
  /** 「되돌리기」 — 원문을 복구하고 보호 상태로 되돌린다 */
  readonly restore: () => void;
}

/**
 * 바인딩 값 보호 상태를 관리한다.
 *
 * 위젯의 값이 객체인 경우(예 `image` 는 `{url,…}`)를 위해 문자열 추출자를 받는다.
 *
 * @param rawValue   현재 값 (위젯이 받은 원본)
 * @param onRestore  원문 복구를 위해 호출할 쓰기 함수. 값이 이미 원문과 같으면 호출하지
 *                   않는다 — 바꾼 것이 없는데 history push 를 만들지 않기 위함이다.
 * @param extract    값에서 판정 대상 문자열을 뽑는다 (기본: 값 자신)
 * @return 보호 상태와 조작
 */
export function useBoundValueGuard(
  rawValue: unknown,
  onRestore: (original: string) => void,
  extract: (value: unknown) => unknown = (v) => v,
): BoundValueGuard {
  const [replacing, setReplacing] = useState(false);
  const [original, setOriginal] = useState<string | null>(null);

  const current = extract(rawValue);
  const bound = isBoundValue(current) && !replacing;

  const beginReplace = useCallback(() => {
    // 해제 시점의 원문을 붙잡는다 — 이후 값이 바뀌어도 복구 대상은 이것이다.
    setOriginal(isBoundValue(current) ? current : null);
    setReplacing(true);
  }, [current]);

  const restore = useCallback(() => {
    if (original !== null && current !== original) onRestore(original);
    setReplacing(false);
    setOriginal(null);
  }, [original, current, onRestore]);

  return { bound, replacing, original, beginReplace, restore };
}

const noticeWrap: React.CSSProperties = {
  display: 'flex',
  flexDirection: 'column',
  gap: 4,
  padding: '8px 10px',
  border: '1px dashed #cbd5e1',
  borderRadius: 6,
  background: '#f8fafc',
};

const noticeCode: React.CSSProperties = {
  fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
  fontSize: 11,
  color: '#0f172a',
  wordBreak: 'break-all',
};

const noticeLink: React.CSSProperties = {
  alignSelf: 'flex-start',
  background: 'none',
  border: 'none',
  padding: 0,
  fontSize: 11,
  color: '#2563eb',
  cursor: 'pointer',
  textDecoration: 'underline',
};

/**
 * 보호 상태에서 원문과 해제 경로를 보여 주는 공용 배지.
 *
 * 미리보기·입력칸을 대신해 렌더한다 — 위젯이 해석하지 못하는 값을 흉내 내면 거짓
 * 미리보기가 되기 때문이다.
 *
 * @param expression 표시할 원문
 * @param t          다국어 해석 함수
 * @param onReplace  「직접 지정으로 바꾸기」 클릭 핸들러
 * @param testIdPrefix testid 접두사 (위젯별 구분 — 예 `g7le-image`)
 */
export function BoundValueNotice({
  expression,
  t,
  onReplace,
  testIdPrefix,
}: {
  expression: string;
  t: TranslateFn;
  onReplace: () => void;
  testIdPrefix: string;
}): React.ReactElement {
  return (
    <div style={noticeWrap}>
      <code data-testid={`${testIdPrefix}-expression`} style={noticeCode}>
        {expression}
      </code>
      <span style={{ fontSize: 11, color: '#64748b' }}>
        {t('layout_editor.control.bound_value.notice')}
      </span>
      <button
        type="button"
        data-testid={`${testIdPrefix}-expression-replace`}
        onClick={onReplace}
        style={noticeLink}
      >
        {t('layout_editor.control.bound_value.replace')}
      </button>
    </div>
  );
}

/**
 * 보호를 해제한 동안 노출하는 「되돌리기」 어포던스.
 *
 * 해제 직후(아직 안 바꿈)에는 취소로, URL 등을 이미 넣은 뒤에는 원래 연결값 복구로
 * 동작한다 — 둘 다 같은 버튼이다. 이 경로가 없으면 「직접 지정으로 바꾸기」가 편도가
 * 되어, 실수로 한 번 누른 운영자가 원문을 되찾을 방법이 없다.
 *
 * @param t            다국어 해석 함수
 * @param onRestore    「되돌리기」 클릭 핸들러
 * @param testIdPrefix testid 접두사
 */
export function BoundValueRestore({
  t,
  onRestore,
  testIdPrefix,
}: {
  t: TranslateFn;
  onRestore: () => void;
  testIdPrefix: string;
}): React.ReactElement {
  return (
    <button
      type="button"
      data-testid={`${testIdPrefix}-expression-restore`}
      onClick={onRestore}
      style={noticeLink}
    >
      {t('layout_editor.control.bound_value.restore')}
    </button>
  );
}
