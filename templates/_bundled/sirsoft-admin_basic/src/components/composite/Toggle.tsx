import React, { useState, useEffect, useRef, useId } from 'react';
import { Icon } from '../basic/Icon';
import { linkAdjacentLabel } from '../../hooks/useAdjacentLabel';
import type { EditorAttrs } from '../../types';

/**
 * G7Core 전역 객체 접근 헬퍼
 * 런타임에 접근하여 테스트 환경에서도 모킹이 적용되도록 함
 */
const getG7Core = () => (window as any).G7Core;

export interface ToggleProps {
  /** 체크 상태 */
  checked?: boolean;
  /** 체크 상태 변경 콜백 (boolean 또는 ChangeEvent 받음) */
  onChange?: ((checked: boolean) => void) | ((e: React.ChangeEvent<HTMLInputElement>) => void);
  /** 비활성화 여부 */
  disabled?: boolean;
  /** 라벨 텍스트 */
  label?: string;
  /**
   * 보이는 라벨 없이 스위치에만 붙일 접근 가능한 이름(aria-label).
   * 표의 열 머리글·옆 문구처럼 라벨이 스위치 밖에 있을 때 쓴다. 없으면 `label` → 곁 제목(aria-labelledby) → `name` 순으로 쓴다.
   */
  ariaLabel?: string;
  /** 설명 텍스트 */
  description?: string;
  /** 토글 크기 */
  size?: 'sm' | 'md' | 'lg';
  /** 추가 className */
  className?: string;
  /** name 속성 */
  name?: string;
  /** 값 (checked의 대체) */
  value?: boolean;
  /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
  /**
   * 바뀐 값이 확정되기를 기다리는지 — true 면 눌러도 스위치가 스스로 바뀌지 않고 `onChange` 로 다음 값만 알린다.
   * 스위치는 바깥이 `checked` 를 바꿔 줄 때만 바뀐다(서버 작업이 끝난 뒤 목록 값으로). 기본 false(누르면 바로 바뀜).
   */
  awaitConfirmation?: boolean;
  /**
   * 바깥 작업이 진행 중인지 — true 면 잠그고(누를 수 없음, 금지 커서·흐린 트랙) 손잡이 자리에 스피너를 보인다(`aria-busy`).
   */
  pending?: boolean;
  /**
   * 스위치에 마우스를 올렸을 때 보일 안내(title) — 잠긴 스위치가 왜 잠겼는지(예: 검수 서버에서는 바꿀 수 없음) 알릴 때 쓴다.
   */
  title?: string;
}

/** 확정을 기다리는 스위치가 한 번 알린 뒤 다시 알리지 않는 시간(ms) — 바깥의 진행 중 표시가 늦거나 오지 않을 때의 상한 */
const AWAIT_GUARD_MS = 3000;

/**
 * Toggle 스위치 컴포넌트
 *
 * Flowbite 스타일의 토글 스위치입니다.
 * 라이트/다크 모드를 모두 지원합니다.
 * 반응형으로 prop 변경을 추적합니다.
 *
 * 접근성: 실제 checkbox 는 sr-only 로 숨기고 보이는 트랙이 조작 대상이므로, 그 트랙에
 * `role="switch"` + `aria-checked` + `tabIndex` 를 부여하고 Space/Enter 키를 처리합니다.
 * 이 처리가 없으면 마우스 없이는 토글을 전혀 조작할 수 없고 보조기기에도 스위치로 읽히지
 * 않습니다. 옆 라벨은 sibling 이라 `label[for]` 로 연결되지 않으므로 `aria-label` 로 이름을
 * 직접 부여합니다.
 */
export const Toggle: React.FC<ToggleProps> = ({
  checked: checkedProp,
  value: valueProp,
  onChange,
  disabled = false,
  label,
  ariaLabel,
  description,
  size = 'md',
  className = '',
  name,
  id,
  editorAttrs,
  awaitConfirmation = false,
  pending = false,
  title,
}) => {
  // 진행 중이면 누를 수 없다 — 같은 스위치의 연속 클릭이 같은 작업을 거듭 보내지 않게 한다
  const locked = disabled || pending;

  // 안쪽 checkbox 의 DOM id — 같은 name 의 스위치가 화면에 여럿이면(목록 행마다 is_active 등) name 을 그대로 쓰면 id 가 겹친다(#408).
  // 인스턴스마다 다른 useId 를 앞에 붙이고, name 에 섞인 공백·한글(확장 표시 이름 등)은 id 에서 걷어 낸다. 짝 참조(label for)는 없다.
  const reactId = useId().replace(/[^a-zA-Z0-9_-]/g, '');
  const safeName = name ? name.replace(/[^a-zA-Z0-9_-]/g, '') : '';
  const inputId = name ? (safeName ? `${reactId}-${safeName}` : reactId) : undefined;

  // 내부 상태로 관리
  const [checked, setChecked] = useState(() => checkedProp ?? valueProp ?? false);

  // 확정을 기다리는 스위치의 연타 가드 — 눌러서 알린 뒤 바깥이 진행 중(pending)을 그려 주기까지는 시간이 걸린다(행이 많은
  // 표는 다시 그리는 데 1초를 넘기기도 한다). 그 사이의 클릭이 같은 작업을 거듭 보내지 않게, 알린 시각을 ref 에 적어 두고
  // 진행 중 표시가 도착하거나 값이 바뀌거나 일정 시간이 지날 때까지 다시 알리지 않는다. ref 라 렌더를 기다리지 않는다.
  const emittedAtRef = useRef<number | null>(null);
  const isAwaitingOutside = () =>
    awaitConfirmation && emittedAtRef.current !== null && Date.now() - emittedAtRef.current < AWAIT_GUARD_MS;
  useEffect(() => {
    // 바깥이 진행 중을 그렸거나(이제 pending 이 잠근다) 값을 바꿨으면 가드를 푼다
    emittedAtRef.current = null;
  }, [pending, checkedProp, valueProp]);

  // 이름: ariaLabel → 옆 라벨(label) → 행 제목(곁 라벨, aria-labelledby) → 마지막 수단으로 name.
  // 종전에는 곁 제목을 보지 않고 곧장 name(「security.force_https」 같은 필드 키)을 이름으로 달았다(7.1.0 실측).
  const switchRef = useRef<HTMLDivElement>(null);
  const [useNameFallback, setUseNameFallback] = useState(false);
  useEffect(() => {
    if (ariaLabel || label) return;
    const el = switchRef.current;
    if (!el) return;
    // name 은 곁 제목을 못 찾았을 때의 임시 이름이다. 그대로 두면 「이미 이름 있음」으로 보여 늦게 그려진 제목을
    // 다시 찾지 않으므로, 찾는 동안만 떼었다가 못 찾으면 되돌린다(다시 찾는 횟수는 linkAdjacentLabel 이 제한한다).
    const fallbackName = useNameFallback ? el.getAttribute('aria-label') : null;
    if (fallbackName !== null) el.removeAttribute('aria-label');
    // 라벨 탐색은 이 부품의 바깥 상자(toggle-container)에서 시작한다 — 스위치는 한 겹 안쪽이다
    linkAdjacentLabel(el, false, el.parentElement ?? null, true);
    const linked = !!el.getAttribute('aria-labelledby');
    if (!linked && fallbackName !== null) el.setAttribute('aria-label', fallbackName);
    setUseNameFallback(!linked);
  });

  // prop 변경 시 내부 상태 동기화
  useEffect(() => {
    const newValue = checkedProp ?? valueProp ?? false;
    setChecked(newValue);
  }, [checkedProp, valueProp]);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const newChecked = e.target.checked;

    // 내부 상태 즉시 업데이트 — 확정을 기다리는 스위치는 바깥이 checked 를 바꿀 때까지 그대로 둔다
    if (!awaitConfirmation) {
      setChecked(newChecked);
    }

    if (onChange && !locked && !isAwaitingOutside()) {
      if (awaitConfirmation) emittedAtRef.current = Date.now();
      // 템플릿 엔진의 actions는 이벤트 객체를 받으므로 항상 이벤트로 전달
      (onChange as any)(e);
    }
  };

  // 크기별 클래스
  const sizeClasses = {
    sm: {
      track: 'w-9 h-5',
      thumb: 'after:h-4 after:w-4',
      spinner: 'h-4 w-4',
    },
    md: {
      track: 'w-11 h-6',
      thumb: 'after:h-5 after:w-5',
      spinner: 'h-5 w-5',
    },
    lg: {
      track: 'w-14 h-7',
      thumb: 'after:h-6 after:w-6',
      spinner: 'h-6 w-6',
    },
  };

  const currentSize = sizeClasses[size];

  const handleToggleClick = () => {
    if (!locked && !isAwaitingOutside()) {
      const newChecked = !checked;
      if (!awaitConfirmation) {
        setChecked(newChecked);
      }
      if (onChange) {
        const G7Core = getG7Core();
        if (G7Core?.createChangeEvent) {
          // 실제로 알릴 때만 가드를 세운다 — 알리지 못한 클릭이 뒤따르는 알림을 막으면 안 된다
          if (awaitConfirmation) emittedAtRef.current = Date.now();
          (onChange as any)(G7Core.createChangeEvent({ checked: newChecked, name }));
        }
      }
    }
  };

  /**
   * 키보드 조작 — Space / Enter 로 전환한다 (WAI-ARIA switch 규약).
   *
   * 실제 checkbox 는 sr-only + tabIndex=-1 이라 초점을 받지 않으므로, 초점을 받는 래퍼가
   * 키 입력을 처리해야 한다. 이 처리가 없으면 마우스 없이는 토글을 전혀 쓸 수 없다.
   *
   * @param e 키보드 이벤트
   */
  const handleToggleKeyDown = (e: React.KeyboardEvent<HTMLDivElement>) => {
    if (locked) return;
    if (e.key !== ' ' && e.key !== 'Enter' && e.key !== 'Spacebar') return;

    // Space 는 기본 동작이 스크롤이므로 막는다.
    e.preventDefault();
    handleToggleClick();
  };

  return (
    <div className={`toggle-container ${className}`} id={id} {...editorAttrs}>
      {/* 토글 스위치 */}
      {/* 진행 중에도 비활성 모양으로 보인다(금지 커서·흐린 트랙) — 스피너만 흐리지 않아 작업 중임이 드러난다 */}
      <div
        className={`toggle-switch-wrapper ${
          disabled ? 'cursor-not-allowed opacity-50' : pending ? 'cursor-not-allowed' : 'cursor-pointer'
        }`}
        ref={switchRef}
        role="switch"
        aria-checked={checked}
        aria-disabled={locked || undefined}
        aria-busy={pending || undefined}
        aria-label={ariaLabel ?? label ?? (useNameFallback ? name : undefined)}
        title={title}
        tabIndex={locked ? -1 : 0}
        onKeyDown={handleToggleKeyDown}
        onClick={(e) => {
          e.preventDefault();
          e.stopPropagation();
          handleToggleClick();
        }}
      >
        <input
          type="checkbox"
          id={inputId}
          name={name}
          checked={checked}
          onChange={handleChange}
          disabled={locked}
          className="sr-only peer"
          tabIndex={-1}
          // 스위치 역할은 바깥 래퍼(role=switch)가 맡는다 — 안쪽 체크박스가 접근성 트리에 남으면
          // 이름 없는 「체크박스」 가 스위치마다 하나씩 더 읽혔다(7.1.0 실측).
          aria-hidden="true"
        />
        <div className={`toggle-switch-track ${currentSize.track} ${currentSize.thumb}${pending && !disabled ? ' opacity-50' : ''}`} />
        {/* 진행 중 — 손잡이 자리(현재 값 쪽)에 스피너를 겹친다. 스위치 크기·배치는 그대로다 */}
        {pending && (
          <span
            className={`toggle-switch-spinner ${checked ? 'toggle-switch-spinner-on' : 'toggle-switch-spinner-off'} ${currentSize.spinner}`}
            data-testid="toggle-pending-spinner"
            aria-hidden="true"
          >
            <Icon name="spinner" className="spinner-sm" />
          </span>
        )}
      </div>

      {/* 라벨 (토글 옆) */}
      {label && (
        <span
          className={`toggle-label ${
            disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'
          }`}
          onClick={handleToggleClick}
        >
          {label}
        </span>
      )}

      {/* 디스크립션 (다음 줄) */}
      {description && <p className="toggle-description">{description}</p>}
    </div>
  );
};
