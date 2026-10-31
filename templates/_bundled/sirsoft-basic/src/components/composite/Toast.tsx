import React, { useEffect, useState, useCallback, useRef } from 'react';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';

/**
 * G7Core.t() 번역 함수 참조
 * 런타임에 G7Core.t를 접근하여 테스트 환경에서도 모킹이 적용되도록 함
 */
const getTranslation = (key: string, params?: Record<string, string | number>) => {
  const G7Core = (window as any).G7Core;
  return G7Core?.t?.(key, params) ?? key;
};

export type ToastType = 'success' | 'error' | 'warning' | 'info';
export type ToastPosition =
  | 'top-left'
  | 'top-center'
  | 'top-right'
  | 'bottom-left'
  | 'bottom-center'
  | 'bottom-right';

export interface ToastItem {
  id: string | number;
  type: ToastType;
  message: string;
  /** 커스텀 아이콘 이름 (지정하지 않으면 타입별 기본 아이콘 사용) */
  icon?: string;
  /** 개별 토스트 자동 닫힘 시간 (ms) */
  duration?: number;
  /**
   * 항목을 만든 시각 (epoch ms, 엔진 toast 핸들러가 넣는다).
   * 있으면 자동 닫힘 시간을 이 시각부터 센다 — 화면이 바뀌어 Toast 가 새로 마운트돼도 같은 항목은 같은 시각에 닫힌다.
   */
  createdAt?: number;
}

export interface ToastProps {
  /** 토스트 배열 (전역 상태 _global.toasts) */
  toasts?: ToastItem[] | null;
  /** 토스트 표시 위치 */
  position?: ToastPosition;
  /** 기본 자동 닫힘 시간 (ms, 기본 3000) */
  duration?: number;
  /** 토스트 제거 콜백 (전역 상태 업데이트용) */
  onRemove?: (id: string | number) => void;
}

// 타입별 기본 아이콘 매핑
const defaultIconMap: Record<ToastType, IconName> = {
  success: IconName.CheckCircle,
  error: IconName.XCircle,
  warning: IconName.ExclamationTriangle,
  info: IconName.InfoCircle,
};

// 타입별 스타일 매핑
const typeStyleMap: Record<ToastType, { bg: string; text: string }> = {
  success: {
    bg: 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800',
    text: 'text-green-800 dark:text-green-300',
  },
  error: {
    bg: 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800',
    text: 'text-red-800 dark:text-red-300',
  },
  warning: {
    bg: 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 dark:border-yellow-800',
    text: 'text-yellow-800 dark:text-yellow-300',
  },
  info: {
    bg: 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800',
    text: 'text-blue-800 dark:text-blue-300',
  },
};

// position별 스타일 매핑
const positionClassMap: Record<ToastPosition, string> = {
  'top-left': 'top-4 left-4',
  'top-center': 'top-4 left-1/2 -translate-x-1/2',
  'top-right': 'top-4 right-4',
  'bottom-left': 'bottom-4 left-4',
  'bottom-center': 'bottom-4 left-1/2 -translate-x-1/2',
  'bottom-right': 'bottom-4 right-4',
};

/** 숨김 애니메이션 길이 (ms) — 이 시간 뒤 전역 상태에서 항목을 뺀다 */
const HIDE_ANIMATION_MS = 300;

/**
 * 항목이 숨기 시작할 시각을 구한다.
 *
 * 만든 시각(`createdAt`)이 있으면 그 시각부터 센다. 로그인 화면처럼 베이스 레이아웃이 다른 화면으로 넘어가면 Toast 가
 * 새로 마운트되는데, 그때마다 처음부터 다시 세면 같은 알림이 정해진 시간보다 오래 남거나 앞 화면 타이머에 갑자기 지워지고,
 * 이미 다 보인 알림이 늦게 그려진 화면에서 다시 나타난다. 만든 시각이 없으면 이 Toast 가 처음 받은 시각부터 센다.
 *
 * @param toast 토스트 항목
 * @param defaultDuration Toast 의 기본 자동 닫힘 시간 (ms)
 * @param fallbackStart 만든 시각이 없을 때 기준 시각 (ms)
 * @returns 숨기 시작할 시각 (ms), 자동으로 닫지 않으면 Infinity
 */
const getHideAt = (toast: ToastItem, defaultDuration: number, fallbackStart: number): number => {
  const effectiveDuration = toast.duration || defaultDuration;
  if (!(effectiveDuration > 0)) return Infinity;
  const start = typeof toast.createdAt === 'number' && Number.isFinite(toast.createdAt)
    ? toast.createdAt
    : fallbackStart;
  return start + effectiveDuration;
};

/**
 * Toast 컴포넌트
 *
 * 전역 상태(_global.toasts)를 구독하여 토스트 알림을 스택으로 표시하는 컴포넌트.
 * ActionDispatcher의 toast 핸들러와 연동됩니다.
 *
 * 기능:
 * - 다중 토스트 스택 지원
 * - 타입별 스타일 (success, error, warning, info)
 * - 커스텀 아이콘 지정 가능
 * - 개별 토스트 자동 닫힘 시간 설정
 * - 수동 닫기 버튼
 *
 * @example
 * // 레이아웃 JSON 사용 예시 (_user_base.g7.html)
 * {
 *   "id": "global_toast",
 *   "type": "composite",
 *   "name": "Toast",
 *   "props": {
 *     "toasts": "{{_global.toasts}}",
 *     "position": "bottom-center",
 *     "duration": 3000
 *   }
 * }
 *
 * @example
 * // toast 핸들러 호출 예시 (레이아웃 JSON)
 * {
 *   "handler": "toast",
 *   "params": {
 *     "type": "success",
 *     "message": "$t:common.save_success",
 *     "icon": "check",
 *     "duration": 5000
 *   }
 * }
 */
export const Toast: React.FC<ToastProps> = ({
  toasts,
  position = 'bottom-center',
  duration = 3000,
  onRemove,
}) => {
  // 내부적으로 표시할 토스트 상태 관리 (애니메이션용)
  const [visibleToasts, setVisibleToasts] = useState<Map<string | number, boolean>>(new Map());
  // 이 Toast 가 맡은 항목의 숨김 시각과 타이머 — 언마운트 때 타이머를 모두 거둔다
  const trackedRef = useRef<Map<string | number, { hideAt: number; timer: ReturnType<typeof setTimeout> | null }>>(new Map());
  // 숨기기를 시작했거나(닫기·시간 만료) 표시 시간이 이미 지나 상태에서 빼기를 요청한 항목
  const removedRef = useRef<Set<string | number>>(new Set());

  // 전역 상태에서 항목 제거
  const removeFromState = useCallback((id: string | number) => {
    if (onRemove) {
      onRemove(id);
      return;
    }
    // onRemove가 없으면 G7Core.state.update를 통해 전역 상태 업데이트
    const G7Core = (window as any).G7Core;
    if (G7Core?.state?.update) {
      G7Core.state.update((prevState: Record<string, any>) => {
        const currentToasts = Array.isArray(prevState.toasts) ? prevState.toasts : [];
        return {
          toasts: currentToasts.filter((t: ToastItem) => t.id !== id),
        };
      });
    }
  }, [onRemove]);

  // 토스트 제거 핸들러 (닫기 버튼·자동 닫힘 공용, 항목마다 한 번)
  const handleRemove = useCallback((id: string | number) => {
    const tracked = trackedRef.current.get(id);
    if (tracked?.timer) {
      clearTimeout(tracked.timer);
      tracked.timer = null;
    }
    if (removedRef.current.has(id)) return;
    removedRef.current.add(id);

    // 먼저 숨김 애니메이션 시작
    setVisibleToasts(prev => {
      const next = new Map(prev);
      next.set(id, false);
      return next;
    });

    // 애니메이션 후 실제 제거
    setTimeout(() => removeFromState(id), HIDE_ANIMATION_MS);
  }, [removeFromState]);

  // 새 토스트가 들어오면 visible 상태와 남은 시간만큼의 자동 제거 타이머를 설정한다
  useEffect(() => {
    const list = toasts ?? [];
    const present = new Set(list.map(t => t.id));

    // 상태에서 빠진 항목은 추적을 끝낸다
    trackedRef.current.forEach((entry, id) => {
      if (present.has(id)) return;
      if (entry.timer) clearTimeout(entry.timer);
      trackedRef.current.delete(id);
    });
    removedRef.current.forEach(id => {
      if (!present.has(id)) removedRef.current.delete(id);
    });

    const now = Date.now();
    const shown: Array<string | number> = [];
    const expired: Array<string | number> = [];

    list.forEach(toast => {
      // 이미 처리된 토스트는 건너뜀
      if (trackedRef.current.has(toast.id) || removedRef.current.has(toast.id)) return;

      const hideAt = getHideAt(toast, duration, now);
      if (hideAt <= now) {
        // 표시 시간이 이미 지났다(앞 화면에서 다 보였다) — 다시 그리지 않고 정리한다
        expired.push(toast.id);
        return;
      }

      const timer = Number.isFinite(hideAt)
        ? setTimeout(() => handleRemove(toast.id), hideAt - now)
        : null;
      trackedRef.current.set(toast.id, { hideAt, timer });
      shown.push(toast.id);
    });

    if (shown.length > 0) {
      // visible 상태 추가
      setVisibleToasts(prev => {
        const next = new Map(prev);
        shown.forEach(id => next.set(id, true));
        return next;
      });
    }

    expired.forEach(id => {
      removedRef.current.add(id);
      removeFromState(id);
    });
  }, [toasts, duration, handleRemove, removeFromState]);

  // 언마운트 때 타이머를 거둔다 — 다음 화면의 Toast 가 같은 항목을 남은 시간만큼 이어 맡는다
  useEffect(() => {
    const tracked = trackedRef.current;
    return () => {
      tracked.forEach(entry => {
        if (entry.timer) clearTimeout(entry.timer);
      });
      tracked.clear();
    };
  }, []);

  // 맡은 항목과, 아직 표시 시간이 남은 새 항목만 그린다 (이미 지난 항목은 위 효과가 정리한다)
  const renderNow = Date.now();
  const renderable = (toasts ?? []).filter(toast =>
    trackedRef.current.has(toast.id)
    || (!removedRef.current.has(toast.id) && getHideAt(toast, duration, renderNow) > renderNow)
  );

  // 토스트가 없으면 렌더링하지 않음
  if (renderable.length === 0) {
    return null;
  }

  return (
    <Div
      className={`fixed z-[9999] flex flex-col gap-2 ${positionClassMap[position]}`}
      aria-live="polite"
      aria-atomic="true"
    >
      {renderable.map((toast) => (
        <ToastItemComponent
          key={toast.id}
          toast={toast}
          position={position}
          isVisible={visibleToasts.get(toast.id) ?? true}
          onClose={() => handleRemove(toast.id)}
        />
      ))}
    </Div>
  );
};

interface ToastItemComponentProps {
  toast: ToastItem;
  position: ToastPosition;
  isVisible: boolean;
  onClose: () => void;
}

const ToastItemComponent: React.FC<ToastItemComponentProps> = ({ toast, position, isVisible, onClose }) => {
  const typeStyle = typeStyleMap[toast.type] || typeStyleMap.info;

  // 아이콘 결정: 커스텀 아이콘 > 타입별 기본 아이콘
  const iconName = toast.icon
    ? (toast.icon as IconName)
    : defaultIconMap[toast.type];

  // position에 따라 숨김 애니메이션 방향 결정 (top → 위로, bottom → 아래로)
  const hideTranslate = position.startsWith('bottom') ? 'translate-y-2' : '-translate-y-2';

  return (
    <Div
      className={`
        flex items-center gap-3 p-4 rounded-lg border shadow-lg
        min-w-[300px] max-w-md
        transition-all duration-300
        ${typeStyle.bg}
        ${isVisible ? 'opacity-100 translate-y-0' : `opacity-0 ${hideTranslate}`}
      `}
      role="alert"
    >
      <Icon
        name={iconName}
        className={`w-5 h-5 flex-shrink-0 ${typeStyle.text}`}
      />
      <Span className={`flex-1 text-sm font-medium ${typeStyle.text}`}>
        {toast.message}
      </Span>
      <Button
        type="button"
        onClick={onClose}
        className={`flex-shrink-0 p-1 rounded hover:bg-black/10 dark:hover:bg-white/10 ${typeStyle.text} focus:outline-none`}
        aria-label={getTranslation('common.close')}
      >
        <Icon name={IconName.Times} className="w-4 h-4" />
      </Button>
    </Div>
  );
};

export default Toast;
