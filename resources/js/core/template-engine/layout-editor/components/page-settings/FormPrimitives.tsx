/**
 * FormPrimitives.tsx — 페이지 설정 폼 공용 부품
 *
 * 전 탭 폼이 공유하는 두 부품:
 *  - `ToggleSwitch`(D-L): boolean on/off 단일 토글의 시각 통일. 종전 폼마다 plain
 *    checkbox(SEO) / role=switch 둥근 버튼(overlay) 으로 제각각이던 것을 슬라이딩 노브
 *    스위치 한 종류로 묶는다. multi-select(여러 항목 체크)는 대상이 아니다 — boolean 1개
 *    on/off 만.
 *  - `DisabledFieldset`(D-M): "켜야 나타나는 하위 UI 를 숨김 금지 → 항상 표시 + OFF 시
 *    비활성(회색)" 전역 패턴. 종전 `enabled ? <>…</> : null` 조건부 렌더를 이 래퍼로 감싸
 *  OFF 일 때도 DOM 에 남기고 회색+pointerEvents 차단 + aria-disabled 로 잠근다.
 *    회색만, 별도 안내 문구 없음.
 *
 * 편집기 코어 부품 — `g7le-*` + 인라인 스타일만(CSS 라이브러리 비종속).
 *
 * @since engine-v1.50.0
 */

import React from 'react';

export interface ToggleSwitchProps {
  /** 현재 on/off */
  checked: boolean;
  /** 토글 콜백 */
  onChange: (next: boolean) => void;
  /** 라벨(스위치 우측 텍스트). 생략 시 스위치만 */
  label?: React.ReactNode;
  /** data-testid */
  testid?: string;
  /** aria-label(라벨 텍스트 없을 때 접근성) */
  ariaLabel?: string;
  /** 비활성(상위 토글 OFF 등) — 회색 + 클릭 차단 */
  disabled?: boolean;
}

/**
 * boolean on/off 스위치(슬라이딩 노브). D-L 통일 부품.
 *
 * @param props ToggleSwitchProps
 * @return 스위치 엘리먼트
 */
export function ToggleSwitch({
  checked,
  onChange,
  label,
  testid,
  ariaLabel,
  disabled = false,
}: ToggleSwitchProps): React.ReactElement {
  return (
    <label style={{ ...switchWrap, ...(disabled ? { cursor: 'not-allowed' } : {}) }}>
      <button
        type="button"
        data-testid={testid}
        role="switch"
        aria-checked={checked}
        aria-label={ariaLabel}
        disabled={disabled}
        onClick={() => onChange(!checked)}
        style={track(checked, disabled)}
      >
        <span style={knob(checked)} />
      </button>
      {label != null ? <span style={switchLabel(disabled)}>{label}</span> : null}
    </label>
  );
}

export interface DisabledFieldsetProps {
  /** 비활성(OFF) 여부 — true 면 회색 + 클릭 차단 */
  disabled: boolean;
  /** 본문 */
  children: React.ReactNode;
  /** data-testid */
  testid?: string;
  /** 추가 인라인 스타일(레이아웃 — gap/flex 등) */
  style?: React.CSSProperties;
}

/**
 * "항상 표시 + OFF 시 회색 비활성" 래퍼(D-M). 숨기지 않고 잠근다.
 *
 * @param props DisabledFieldsetProps
 * @return 비활성 가능 영역 엘리먼트
 */
export function DisabledFieldset({
  disabled,
  children,
  testid,
  style,
}: DisabledFieldsetProps): React.ReactElement {
  return (
    <div
      data-testid={testid}
      data-disabled={disabled ? 'true' : 'false'}
      aria-disabled={disabled}
      style={{
        ...style,
        ...(disabled
          ? {
              opacity: 0.5,
              pointerEvents: 'none',
              // 키보드 포커스도 막아 잠금 시각과 동작을 일치시킨다(회색만 — 안내 문구 없음).
              userSelect: 'none',
            }
          : {}),
      }}
    >
      {children}
    </div>
  );
}

export interface OverlaySourceFieldProps {
  /** 값이 채워졌는지(= 이 화면이 덮는 중). false 면 기본값 상속 상태 */
  filled: boolean;
  /** 출처 라벨(비었을 때 칩에 표시). 예 "코어", "공통 레이아웃", "이커머스" */
  sourceLabel?: React.ReactNode;
  /** 되돌리기(이 화면 값 제거 → 기본값 복귀). filled 일 때만 버튼 노출 */
  onRevert?: () => void;
  /** 되돌리기 버튼 라벨(접근성/툴팁) */
  revertLabel?: string;
  /** 출처 칩/되돌리기 호버 안내(툴팁) */
  hint?: string;
  /** 감쌀 입력칸(input/picker) */
  children: React.ReactNode;
  /** data-testid */
  testid?: string;
}

/**
 * 입력칸 우측 안쪽에 출처/되돌리기 어포던스를 겹쳐 띄우는 래퍼.
 *
 * 종전엔 칸 아래 별도 줄에 〔코어 설정〕 배지 + 안내문 + override 버튼이 붙어 세로 공간을
 * 많이 썼다. 이 래퍼는 칸 위에 absolute 로 ① 비었으면(상속) 작은 출처 칩 〔코어〕 ② 값이
 * 있으면(덮음) [↩] 되돌리기 버튼을 띄워 별도 줄을 없앤다. 입력칸은 children 으로 받아
 * 우측 padding 만 확보한다(겹침 방지). 출처 정보가 없으면(sourceLabel 부재) 칩을 안 띄운다.
 *
 * @param props OverlaySourceFieldProps
 * @return 입력칸 + 우측 어포던스 래퍼
 */
export function OverlaySourceField({
  filled,
  sourceLabel,
  onRevert,
  revertLabel,
  hint,
  children,
  testid,
}: OverlaySourceFieldProps): React.ReactElement {
  const showRevert = filled && typeof onRevert === 'function';
  const showSource = !filled && sourceLabel != null && sourceLabel !== '';
  return (
    <div data-testid={testid} style={overlayWrap}>
      {children}
      {showRevert ? (
        <button
          type="button"
          data-testid={testid ? `${testid}-revert` : undefined}
          onClick={onRevert}
          title={hint ?? revertLabel}
          aria-label={revertLabel}
          style={overlayRevertBtn}
        >
          ↩ {revertLabel}
        </button>
      ) : showSource ? (
        <span
          data-testid={testid ? `${testid}-source` : undefined}
          title={hint}
          style={overlaySourceChip}
        >
          {sourceLabel}
        </span>
      ) : null}
    </div>
  );
}

const overlayWrap: React.CSSProperties = { position: 'relative', minWidth: 0, width: '100%' };
const overlaySourceChip: React.CSSProperties = {
  position: 'absolute',
  right: 8,
  top: '50%',
  transform: 'translateY(-50%)',
  fontSize: 10,
  color: '#94a3b8',
  background: '#f1f5f9',
  borderRadius: 4,
  padding: '1px 6px',
  pointerEvents: 'none',
  maxWidth: '45%',
  overflow: 'hidden',
  whiteSpace: 'nowrap',
  textOverflow: 'ellipsis',
};
const overlayRevertBtn: React.CSSProperties = {
  position: 'absolute',
  right: 6,
  top: '50%',
  transform: 'translateY(-50%)',
  fontSize: 10,
  color: '#0369a1',
  background: '#e0f2fe',
  border: '1px solid #bae6fd',
  borderRadius: 4,
  padding: '1px 6px',
  cursor: 'pointer',
  whiteSpace: 'nowrap',
};

const switchWrap: React.CSSProperties = {
  display: 'inline-flex',
  alignItems: 'center',
  gap: 8,
  cursor: 'pointer',
};
const track = (on: boolean, disabled: boolean): React.CSSProperties => ({
  position: 'relative',
  width: 38,
  height: 22,
  flexShrink: 0,
  borderRadius: 999,
  border: 'none',
  padding: 0,
  background: disabled ? '#e2e8f0' : on ? '#2563eb' : '#cbd5e1',
  cursor: disabled ? 'not-allowed' : 'pointer',
  transition: 'background 0.15s ease',
});
const knob = (on: boolean): React.CSSProperties => ({
  position: 'absolute',
  top: 2,
  left: on ? 18 : 2,
  width: 18,
  height: 18,
  borderRadius: '50%',
  background: '#fff',
  boxShadow: '0 1px 2px rgba(0,0,0,0.2)',
  transition: 'left 0.15s ease',
});
const switchLabel = (disabled: boolean): React.CSSProperties => ({
  fontSize: 13,
  fontWeight: 600,
  color: disabled ? '#94a3b8' : '#0f172a',
});
