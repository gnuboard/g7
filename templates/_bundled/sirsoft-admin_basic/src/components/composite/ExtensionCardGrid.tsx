import React, { useMemo, useState, useCallback, useEffect, useRef } from 'react';
import { Div } from '../basic/Div';
import { P } from '../basic/P';
import { Span } from '../basic/Span';
import { Img } from '../basic/Img';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { Pagination } from './Pagination';
import { ActionMenu, ActionMenuItem } from './ActionMenu';
import { Toggle } from './Toggle';
import type { EditorAttrs } from '../../types';

// G7Core.t() 번역 함수 참조 (부트 전에도 키를 그대로 돌려주는 폴백)
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

/** 보기 방식 — 갤러리(카드)와 목록(행)은 서로 다른 DOM 을 그린다. */
export type ExtensionViewMode = 'gallery' | 'list';

/** 확장 의존성 한 건. */
export interface ExtensionDependency {
  type?: string;
  name?: string;
}

/** 확장 배지 한 건 — 상태·호환성처럼 이름 옆에 붙는 표식. */
export interface ExtensionBadgeSpec {
  label: string;
  tone?: 'neutral' | 'success' | 'info' | 'warning' | 'danger' | 'accent';
  title?: string;
  srText?: string;
  icon?: IconName;
}

/** 확장 카드 하나가 그리는 데이터. 레이아웃이 아니라 이 컴포넌트가 형태를 정한다. */
export interface ExtensionCardItem {
  id: string;
  /** 원본 행 — 콜백이 그대로 되돌려 준다. 레이아웃이 다시 찾아 오지 않게 한다. */
  row?: any;
  name: string;
  version?: string;
  description?: string;
  vendor?: string;
  iconUrl?: string;
  fallbackIcon?: IconName;
  /**
   * 아이콘 오른쪽 아래에 얹는 짧은 표식(언어팩의 로케일). 언어팩은 대개 자기 그림이 없어
   * 대상 확장·코어의 아이콘을 빌려 쓰므로, 무엇의 어떤 언어인지 이 표식으로 구분한다.
   */
  iconBadge?: { text: string; srLabel?: string };
  dependencies?: ExtensionDependency[];
  badges?: ExtensionBadgeSpec[];
  toggle?: {
    checked: boolean;
    disabled?: boolean;
    label?: string;
  };
  primaryAction?: {
    label: string;
    icon?: IconName;
    variant?: 'solid' | 'outline';
    disabled?: boolean;
    title?: string;
  };
  secondaryAction?: {
    label: string;
    icon?: IconName;
    disabled?: boolean;
    title?: string;
  };
  actions?: ActionMenuItem[];
}

export interface ExtensionCardGridProps {
  items?: ExtensionCardItem[];
  viewMode?: ExtensionViewMode;

  /** 카드 최소 폭(px). 열 수는 컨테이너 폭 나누기 이 값으로 정해진다. */
  minCardWidth?: number;
  /** 열 수 상한 — 초대형 화면에서 카드가 끝없이 잘게 쪼개지는 것을 막는다. */
  maxColumns?: number;

  emptyMessage?: string;

  pagination?: boolean;
  currentPage?: number;
  /**
   * 전체 페이지 수. **null 이면 '모름'** 이다 — 총 건수를 상한까지만 센 목록에서
   * 서버가 null 을 주며, 1 로 접으면 화면이 "1페이지뿐" 이라고 잘못 말한다.
   * 이때는 `hasMorePages` 가 '다음' 이동을 연다.
   */
  totalPages?: number | null;
  hasMorePages?: boolean;
  alwaysShowPagination?: boolean;
  paginationInfoText?: string;
  onPageChange?: (page: number) => void;

  onItemClick?: (row: any) => void;
  onToggle?: (checked: boolean, row: any) => void;
  onPrimaryAction?: (row: any) => void;
  onSecondaryAction?: (row: any) => void;
  onAction?: (actionId: string | number, row: any) => void;

  /**
   * 스위치 재동기 신호 — 값이 바뀌면 카드마다 누른 채 기다리던 스위치 값을 버리고 서버 값을 따른다.
   * 서버가 토글을 거절해 원래 값이 그대로 돌아오는 경로(실패·의존 충돌 창 취소)에서 화면이 올려 준다.
   */
  resyncKey?: string | number;

  loading?: boolean;
  skeletonCount?: number;

  className?: string;
  style?: React.CSSProperties;
  id?: string;
  editorAttrs?: EditorAttrs;
}

/** 갤러리 열 사이 간격(px). 열 수 계산과 실제 grid gap 이 같은 값을 써야 한다. */
const GRID_GAP_PX = 16;

/** 배지 색조 → Tailwind 클래스. 정적 문자열이라 JIT 가 전부 수집한다. */
const BADGE_TONE: Record<string, string> = {
  neutral: 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300',
  success: 'bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300',
  info: 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300',
  warning: 'bg-amber-100 dark:bg-amber-900 text-amber-700 dark:text-amber-300',
  danger: 'bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-300',
  accent: 'bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300',
};

/**
 * 컨테이너 폭을 재어 열 수를 돌려준다.
 *
 * 중단점(sm/md/lg/xl)이 아니라 실제 컨테이너 폭을 기준으로 삼는다. 관리자 화면은
 * 사이드바가 접히고 펼쳐지므로 같은 뷰포트에서도 본문 폭이 달라진다 — 뷰포트 중단점으로는
 * 그 차이를 따라갈 수 없다.
 */
const useColumnCount = (
  ref: React.RefObject<HTMLDivElement | null>,
  minCardWidth: number,
  maxColumns: number,
  enabled: boolean
): number => {
  const [columns, setColumns] = useState(1);

  useEffect(() => {
    if (!enabled) {
      setColumns(1);
      return;
    }
    const el = ref.current;
    if (!el) return;

    const measure = (entries?: ResizeObserverEntry[]) => {
      // 관찰 항목이 준 폭을 먼저 쓴다. `clientWidth` 는 콜백 시점에 따라 직전 값을 돌려줄
      // 때가 있어, 폭이 줄어드는 방향에서 열 수가 한 박자 늦게 따라온다(카드가 최소 폭보다
      // 좁아진 채 남는다).
      const measured = entries?.[0]?.contentRect?.width;
      const width = Math.round(measured && measured > 0 ? measured : el.clientWidth);
      if (!width) return;
      // 열 사이 간격(gap)을 빼고 나눠야 한다. 폭만 minCardWidth 로 나누면 간격이 없는 셈이
      // 되어 실제 카드가 최소 폭보다 좁아진다 — 1232px 에서 5열이 되고 카드가 234px 로
      // 쪼그라들던 것이 이 누락이다. n 열이면 간격은 n-1 개다.
      const fits = (n: number) => (width - GRID_GAP_PX * (n - 1)) / n >= minCardWidth;
      let next = 1;
      for (let n = maxColumns; n >= 1; n -= 1) {
        if (fits(n)) {
          next = n;
          break;
        }
      }
      setColumns((prev) => (prev === next ? prev : next));
    };

    measure();

    if (typeof ResizeObserver === 'undefined') {
      // 창 크기 이벤트는 관찰 항목이 없으므로 인자 없이 잰다(이벤트 객체를 entries 로 넘기지 않게).
      const onResize = () => measure();
      window.addEventListener('resize', onResize);
      return () => window.removeEventListener('resize', onResize);
    }

    const observer = new ResizeObserver((entries) => measure(entries));
    observer.observe(el);
    return () => observer.disconnect();
  }, [ref, minCardWidth, maxColumns, enabled]);

  return columns;
};

/**
 * 확장 아이콘.
 *
 * 글리프를 늘 깔아 두고 이미지가 그 위를 덮는다 — 로드 전에도 자리가 유지되고,
 * 404·손상이면 이미지를 감춰 글리프가 다시 드러난다. 상자는 정사각형이고 크기는
 * 카드가 정해 준다.
 */
const ExtensionIcon: React.FC<{
  item: ExtensionCardItem;
  sizeClass: string;
  glyphClass: string;
}> = ({ item, sizeClass, glyphClass }) => {
  const [broken, setBroken] = useState(false);
  const showImage = Boolean(item.iconUrl) && !broken;

  return (
    <Div
      className={`${sizeClass} aspect-square shrink-0 relative overflow-hidden rounded-xl bg-gray-100 dark:bg-gray-700 flex items-center justify-center`}
      data-ref="ext_icon"
    >
      <Icon
        name={item.fallbackIcon ?? 'puzzle-piece'}
        className={`${glyphClass} text-gray-500 dark:text-gray-400`}
        aria-hidden={true}
      />
      {showImage && (
        <Img
          src={item.iconUrl}
          alt={t('extensions.icon.alt', { name: item.name })}
          loading="lazy"
          decoding="async"
          onError={() => setBroken(true)}
          className="absolute inset-0 w-full h-full object-contain p-1.5"
        />
      )}
      {item.iconBadge && (
        <Span
          className="absolute bottom-0 right-0 px-1 rounded-tl-md rounded-br-xl text-[10px] leading-4 font-semibold uppercase bg-blue-600 dark:bg-blue-500 text-white"
          aria-label={item.iconBadge.srLabel}
          data-ref="ext_icon_badge"
        >
          {item.iconBadge.text}
        </Span>
      )}
    </Div>
  );
};

/** 이름 옆 배지 줄. 갤러리·목록이 함께 쓴다. */
const BadgeRow: React.FC<{ item: ExtensionCardItem }> = ({ item }) => {
  const badges = item.badges ?? [];
  if (badges.length === 0 && !item.version) return null;

  return (
    <Div className="flex items-center gap-1.5 flex-wrap">
      {item.version && (
        <Span className="px-1.5 py-0.5 text-[11px] leading-4 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 whitespace-nowrap">
          v{item.version}
        </Span>
      )}
      {badges.map((badge, i) => (
        <Span
          key={`${badge.label}-${i}`}
          title={badge.title}
          className={`px-1.5 py-0.5 text-[11px] leading-4 rounded whitespace-nowrap inline-flex items-center gap-1 ${
            BADGE_TONE[badge.tone ?? 'neutral'] ?? BADGE_TONE.neutral
          }`}
        >
          {badge.icon && <Icon name={badge.icon} size="xs" />}
          <Span>{badge.label}</Span>
          {badge.srText && <Span className="sr-only">{badge.srText}</Span>}
        </Span>
      ))}
    </Div>
  );
};

/**
 * 작성자·의존성.
 *
 * 갤러리에서는 `stacked` 로 각각 제 줄을 차지하고, 목록에서는 한 줄에 나란히 놓인다.
 */
const MetaBlock: React.FC<{ item: ExtensionCardItem; stacked: boolean }> = ({ item, stacked }) => {
  const deps = item.dependencies ?? [];

  return (
    <Div
      className={`text-xs text-gray-500 dark:text-gray-400 min-w-0 ${
        stacked ? 'flex flex-col gap-1 items-start' : 'flex items-center gap-4'
      }`}
      data-ref="ext_meta"
    >
      {item.vendor && (
        <Span className="truncate max-w-full" data-ref="ext_vendor">
          {t('admin.plugins.vendor', { vendor: item.vendor })}
        </Span>
      )}
      <Span className="flex items-center gap-1 min-w-0 max-w-full" data-ref="ext_deps">
        {/* `no_dependencies` 는 이미 "의존성: 없음" 처럼 라벨을 품은 완성 문구다 —
            없을 때 라벨을 따로 붙이면 라벨이 두 번 나온다. */}
        {deps.length === 0 ? (
          <Span className="truncate">{t('admin.plugins.no_dependencies')}</Span>
        ) : (
          <Span className="flex items-center gap-1.5 flex-wrap min-w-0">
            <Span className="shrink-0">{t('admin.plugins.dependencies_label')}</Span>
            {deps.map((dep, i) => (
              <Span key={`${dep.name}-${i}`} className="inline-flex items-center gap-1 min-w-0">
                <Icon
                  name={dep.type === 'module' ? 'cube' : 'puzzle-piece'}
                  className="text-xs text-gray-400 dark:text-gray-500 shrink-0"
                />
                <Span className="truncate">{dep.name}</Span>
              </Span>
            ))}
          </Span>
        )}
      </Span>
    </Div>
  );
};

/**
 * 카드 제어부 버튼.
 *
 * `Button` 기본 컴포넌트는 variant 기본값이 제 색을 입히므로 여기서는 쓰지 않고
 * 스타일을 직접 지정한다. `whitespace-nowrap` 과 고정 높이가 핵심이다 — 좁은 칸에서
 * 글자가 한 자씩 끊겨 세로로 쌓이던 파손을 막는 것이 이 둘이다.
 */
const ControlButton: React.FC<{
  label: string;
  icon?: IconName;
  outline?: boolean;
  disabled?: boolean;
  title?: string;
  onClick?: () => void;
}> = ({ label, icon, outline = false, disabled, title, onClick }) => (
  <button
    type="button"
    disabled={disabled}
    title={title}
    onClick={onClick}
    className={`inline-flex items-center justify-center gap-1.5 h-9 px-3 rounded-lg text-sm whitespace-nowrap transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${
      outline
        ? 'border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700'
        : 'bg-gray-900 dark:bg-white text-white dark:text-gray-900 hover:bg-gray-800 dark:hover:bg-gray-100'
    }`}
  >
    {icon && <Icon name={icon} size="sm" />}
    <Span>{label}</Span>
  </button>
);

/**
 * 카드 하단 제어부.
 *
 * 카드 본문 클릭(상세 열기)과 겹치지 않도록 이 영역의 클릭은 전파를 멈춘다.
 */
const ControlRow: React.FC<{
  item: ExtensionCardItem;
  onToggle?: (checked: boolean, row: any) => void;
  onPrimaryAction?: (row: any) => void;
  onSecondaryAction?: (row: any) => void;
  onAction?: (actionId: string | number, row: any) => void;
  resyncKey?: string | number;
  className?: string;
}> = ({ item, onToggle, onPrimaryAction, onSecondaryAction, onAction, resyncKey, className = '' }) => {
  const payload = item.row ?? item;
  const stop = (e: React.SyntheticEvent) => e.stopPropagation();

  // 스위치는 누른 값을 먼저 보여 주되(대기 값), 서버 값이 바뀌거나 화면이 재동기 신호(resyncKey)를 올리면
  // 대기 값을 버리고 서버 값을 따른다. Toggle 은 받은 checked 가 바뀔 때만 내부 값을 맞추므로, 서버가 거절해
  // 값이 그대로인 채 목록이 다시 오면 누른 쪽으로 뒤집힌 채 남았다(의존 충돌 창 취소 — #703 실측).
  // 행 객체는 기준으로 쓸 수 없다 — 엔진이 렌더마다 새 객체를 만들어 대기 값이 곧바로 버려지고, 요청이
  // 끝날 때까지(수 초) 누른 반응이 보이지 않는다.
  const serverChecked = item.toggle?.checked ?? false;
  const [pendingChecked, setPendingChecked] = useState<boolean | null>(null);
  useEffect(() => {
    setPendingChecked(null);
  }, [resyncKey, serverChecked, item.id]);

  return (
    <Div
      className={`flex items-center gap-2 ${className}`}
      onClick={stop}
      onKeyDown={stop}
      data-ref="ext_controls"
    >
      {item.secondaryAction && (
        <ControlButton
          label={item.secondaryAction.label}
          icon={item.secondaryAction.icon}
          outline={true}
          disabled={item.secondaryAction.disabled}
          title={item.secondaryAction.title}
          onClick={() => onSecondaryAction?.(payload)}
        />
      )}
      {item.primaryAction && (
        <ControlButton
          label={item.primaryAction.label}
          icon={item.primaryAction.icon}
          outline={item.primaryAction.variant === 'outline'}
          disabled={item.primaryAction.disabled}
          title={item.primaryAction.title}
          onClick={() => onPrimaryAction?.(payload)}
        />
      )}
      {item.toggle && (
        <Toggle
          checked={pendingChecked ?? serverChecked}
          disabled={item.toggle.disabled}
          name={item.toggle.label ?? item.name}
          // 이름을 명시한다 — 비워 두면 곁 글자를 찾아 바로 앞 「업데이트」·「레이아웃 편집」 단추 글자를 집었다(O-Q2-1).
          ariaLabel={item.toggle.label ?? item.name}
          onChange={(next: any) => {
            const value = typeof next === 'boolean' ? next : Boolean(next?.target?.checked);
            setPendingChecked(value);
            onToggle?.(value, payload);
          }}
        />
      )}
      {item.actions && item.actions.length > 0 && (
        <ActionMenu
          triggerLabel=""
          items={item.actions.map((a) => ({
            ...a,
            onClick: () => onAction?.(a.id, payload),
          }))}
        />
      )}
    </Div>
  );
};

/** 카드·행이 공유하는 키보드 열기 처리. */
const openOnKey = (handler?: () => void) => (e: React.KeyboardEvent) => {
  if (e.key === 'Enter' || e.key === ' ') {
    e.preventDefault();
    handler?.();
  }
};

/**
 * 갤러리 카드 — 세로 조판.
 *
 * 위에서 아래로 아이콘 → 이름·배지 → 설명 → 메타 → 제어. 본문이 `mt-auto` 로 제어부를
 * 바닥에 밀어내므로 한 행의 카드 높이가 서로 어긋나지 않는다. 정렬은 왼쪽이다 —
 * 가운데 정렬은 이름·설명·메타의 시작선을 저마다 어긋나게 한다.
 */
const GalleryCard: React.FC<{
  item: ExtensionCardItem;
  onItemClick?: (row: any) => void;
  onToggle?: (checked: boolean, row: any) => void;
  onPrimaryAction?: (row: any) => void;
  onSecondaryAction?: (row: any) => void;
  onAction?: (actionId: string | number, row: any) => void;
  resyncKey?: string | number;
}> = ({ item, onItemClick, onToggle, onPrimaryAction, onSecondaryAction, onAction, resyncKey }) => {
  const open = () => onItemClick?.(item.row ?? item);

  return (
    <Div
      role="button"
      tabIndex={0}
      aria-label={t('extensions.card.open_detail', { name: item.name })}
      onClick={open}
      onKeyDown={openOnKey(open)}
      data-ref="ext_card"
      className="h-full flex flex-col gap-3 p-4 text-left rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:border-gray-300 dark:hover:border-gray-600 hover:shadow-sm transition-all cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
    >
      <Div className="flex items-start gap-3 min-w-0">
        <ExtensionIcon item={item} sizeClass="w-14 max-w-[28%]" glyphClass="text-2xl" />
        <Div className="min-w-0 flex-1 flex flex-col gap-1.5">
          <Span
            className="font-semibold text-sm text-gray-900 dark:text-white truncate"
            title={item.name}
            data-ref="ext_name"
          >
            {item.name}
          </Span>
          <BadgeRow item={item} />
        </Div>
      </Div>

      <P className="text-xs leading-relaxed text-gray-600 dark:text-gray-400 line-clamp-2 min-h-[2rem]">
        {item.description}
      </P>

      <Div className="mt-auto flex flex-col gap-3">
        <MetaBlock item={item} stacked={true} />
        <Div className="pt-3 border-t border-gray-100 dark:border-gray-700">
          <ControlRow
            item={item}
            onToggle={onToggle}
            onPrimaryAction={onPrimaryAction}
            onSecondaryAction={onSecondaryAction}
            onAction={onAction}
            resyncKey={resyncKey}
            className="justify-start flex-wrap"
          />
        </Div>
      </Div>
    </Div>
  );
};

/**
 * 목록 행 — 가로 조판.
 *
 * 아이콘은 행 높이에 맞춘 정사각형(48px)이고, 본문이 가운데를 채우며 제어부가
 * 오른쪽 끝에 고정된다.
 */
const ListRow: React.FC<{
  item: ExtensionCardItem;
  onItemClick?: (row: any) => void;
  onToggle?: (checked: boolean, row: any) => void;
  onPrimaryAction?: (row: any) => void;
  onSecondaryAction?: (row: any) => void;
  onAction?: (actionId: string | number, row: any) => void;
  resyncKey?: string | number;
}> = ({ item, onItemClick, onToggle, onPrimaryAction, onSecondaryAction, onAction, resyncKey }) => {
  const open = () => onItemClick?.(item.row ?? item);

  return (
    <Div
      role="button"
      tabIndex={0}
      aria-label={t('extensions.card.open_detail', { name: item.name })}
      onClick={open}
      onKeyDown={openOnKey(open)}
      data-ref="ext_card"
      className="flex items-center gap-4 p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:border-gray-300 dark:hover:border-gray-600 transition-colors cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
    >
      <ExtensionIcon item={item} sizeClass="w-12" glyphClass="text-xl" />

      <Div className="flex-1 min-w-0 flex flex-col gap-1">
        <Div className="flex items-center gap-2 flex-wrap min-w-0">
          <Span
            className="font-semibold text-sm text-gray-900 dark:text-white truncate"
            title={item.name}
            data-ref="ext_name"
          >
            {item.name}
          </Span>
          <BadgeRow item={item} />
        </Div>
        <P className="text-xs text-gray-600 dark:text-gray-400 truncate">{item.description}</P>
        <MetaBlock item={item} stacked={false} />
      </Div>

      <ControlRow
        item={item}
        onToggle={onToggle}
        onPrimaryAction={onPrimaryAction}
        onSecondaryAction={onSecondaryAction}
        onAction={onAction}
        resyncKey={resyncKey}
        className="shrink-0"
      />
    </Div>
  );
};

/** 로딩 자리표시자 — 실제 카드와 같은 골격이라 전환 시 화면이 튀지 않는다. */
const Skeleton: React.FC<{ gallery: boolean }> = ({ gallery }) => (
  <Div
    className={`rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 animate-pulse ${
      gallery ? 'flex flex-col gap-3' : 'flex items-center gap-4'
    }`}
    data-ref="ext_skeleton"
  >
    <Div
      className={`${gallery ? 'w-14' : 'w-12'} aspect-square shrink-0 rounded-xl bg-gray-200 dark:bg-gray-700`}
    />
    <Div className="flex-1 min-w-0 flex flex-col gap-2">
      <Div className="h-3.5 w-1/2 rounded bg-gray-200 dark:bg-gray-700" />
      <Div className="h-3 w-full rounded bg-gray-200 dark:bg-gray-700" />
      <Div className="h-3 w-2/3 rounded bg-gray-200 dark:bg-gray-700" />
    </Div>
  </Div>
);

/**
 * ExtensionCardGrid — 확장 목록 전용 그리드.
 *
 * 갤러리와 목록을 각각 독립한 DOM 으로 그린다. 한 트리에 두 모드를 겹쳐 놓고 클래스만
 * 바꾸던 방식은 카드 안쪽에서 보기 상태를 볼 수 없어 성립하지 않았다 — 카드 셀의 렌더
 * 컨텍스트에는 전역 상태가 없기 때문이다. 이 컴포넌트는 `viewMode` 를 prop 으로 직접
 * 받아 그 분기를 React 안에서 끝낸다.
 *
 * 열 수는 중단점이 아니라 컨테이너 실측 폭으로 정한다 — 사이드바 개폐까지 따라간다.
 */
export const ExtensionCardGrid: React.FC<ExtensionCardGridProps> = ({
  items,
  viewMode = 'gallery',
  // 기본값은 4화면이 실제로 넘기는 값과 같게 둔다 — 달라 두면 prop 이 빠진 경로(편집기 캔버스)에서
  // 열 수가 실측표와 어긋난다(1920/1440/1280/834/390 → 5/4/3/2/1 은 250 기준으로 잰 값이다).
  minCardWidth = 250,
  maxColumns = 5,
  emptyMessage,
  pagination = true,
  currentPage = 1,
  totalPages = null,
  hasMorePages = false,
  alwaysShowPagination = false,
  paginationInfoText,
  onPageChange,
  onItemClick,
  onToggle,
  onPrimaryAction,
  onSecondaryAction,
  onAction,
  resyncKey,
  loading = false,
  skeletonCount,
  className = '',
  style,
  id,
  editorAttrs,
}) => {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const isGallery = viewMode !== 'list';
  const columns = useColumnCount(containerRef, minCardWidth, maxColumns, isGallery);

  // 스켈레톤 개수는 실제 열 수를 따른다(갤러리 = 열 수 · 목록 = 1) — 선행 컴포넌트 CardGrid 의
  // `skeletonCount ?? gridColumns` 규약과 같다. 고정값을 쓰면 목록 보기·1열 화면에서 로딩 중
  // 카드가 실제보다 많이 깔려 화면이 튄다. 명시 전달값이 있으면 그것을 존중한다.
  const effectiveSkeletonCount = skeletonCount ?? (isGallery ? columns : 1);

  const handleAction = useCallback(
    (actionId: string | number, row: any) => onAction?.(actionId, row),
    [onAction]
  );

  const gridStyle = useMemo<React.CSSProperties>(
    () =>
      isGallery
        ? { display: 'grid', gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))`, gap: `${GRID_GAP_PX}px` }
        : { display: 'flex', flexDirection: 'column', gap: '0.75rem' },
    [isGallery, columns]
  );

  // 자리표시자는 **줄 데이터가 아직 없을 때만** 그린다. `loading` 하나만 보면, 데이터소스를
  // 부모 화면이 들고 조각이 값만 빌려 쓰는 배치(템플릿 탭)에서 그 깃발이 내려오지 않아
  // 목록이 영영 자리표시자로 남는다 — 이미 받은 줄이 있으면 그것을 그리는 쪽이 옳다.
  const hasRows = Array.isArray(items);
  const showSkeleton = !hasRows || (loading && items.length === 0);
  const isEmpty = !showSkeleton && hasRows && items.length === 0;
  // 총 페이지가 null(모름)이어도 페이지네이션은 살려 둔다 — `hasMorePages` 가 '다음' 을 연다.
  const isBounded = totalPages !== null && totalPages !== undefined;
  const showPagination =
    pagination &&
    !isEmpty &&
    (alwaysShowPagination || hasMorePages || (isBounded && (totalPages as number) > 1));

  return (
    <Div ref={containerRef} className={className} style={style} id={id} {...editorAttrs}>
      {isEmpty ? (
        <Div className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-12">
          <P className="text-center text-sm text-gray-600 dark:text-gray-400">
            {emptyMessage ?? t('common.no_data')}
          </P>
        </Div>
      ) : (
        <Div
          className="grid"
          style={gridStyle}
          data-view-mode={isGallery ? 'gallery' : 'list'}
          data-columns={isGallery ? columns : 1}
        >
          {showSkeleton
            ? Array.from({ length: effectiveSkeletonCount }).map((_, i) => (
                <Skeleton key={`sk-${i}`} gallery={isGallery} />
              ))
            : (items ?? []).map((item) =>
                isGallery ? (
                  <GalleryCard
                    key={item.id}
                    item={item}
                    onItemClick={onItemClick}
                    onToggle={onToggle}
                    onPrimaryAction={onPrimaryAction}
                    onSecondaryAction={onSecondaryAction}
                    onAction={handleAction}
                    resyncKey={resyncKey}
                  />
                ) : (
                  <ListRow
                    key={item.id}
                    item={item}
                    onItemClick={onItemClick}
                    onToggle={onToggle}
                    onPrimaryAction={onPrimaryAction}
                    onSecondaryAction={onSecondaryAction}
                    onAction={handleAction}
                    resyncKey={resyncKey}
                  />
                )
              )}
        </Div>
      )}

      {showPagination && (
        <Div className="mt-6 flex flex-col items-center gap-3">
          <Pagination
            currentPage={currentPage}
            totalPages={totalPages}
            onPageChange={(p: number) => onPageChange?.(p)}
            showFirstLast={false}
            maxVisiblePages={5}
          />
          {paginationInfoText && (
            <Div className="text-sm text-gray-600 dark:text-gray-400">{paginationInfoText}</Div>
          )}
        </Div>
      )}
    </Div>
  );
};

export default ExtensionCardGrid;
