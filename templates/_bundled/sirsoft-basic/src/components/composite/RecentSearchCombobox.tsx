/**
 * RecentSearchCombobox 컴포넌트
 *
 * 최근 검색어 펼침 목록을 가진 검색창입니다. 빈 입력에 초점을 두면 같은 검색 범위(scope)의 최근 검색어가
 * 아래로 펼쳐지고, 제출(Enter·검색 단추·항목 선택)할 때 그 검색어를 최근 검색어에 기록한 뒤 `onSearch` 를 부릅니다.
 *
 * - 목록·기록은 코어 엔진 `G7Core.useSearchHistory(scope)` 가 맡습니다(회원은 서버, 비회원은 브라우저).
 *   같은 범위를 쓰는 검색창끼리(머리글·모바일 서랍·통합검색 화면) 목록을 공유합니다.
 * - 기록은 기다리지 않습니다 — 회원 기록은 서버 응답을 기다리므로 검색 이동을 그 뒤에 두면 한 번 늦어집니다.
 * - 주소로 들어온 검색어(`value`)는 기록하지 않습니다. 제출할 때만 기록합니다.
 * - 엔진이 그 범위를 쓸 수 없다고 답하면(기능 꺼짐·미선언 범위·관리자 화면·레이아웃 편집기 미리보기·옛 엔진)
 *   펼침 목록 없이 평범한 검색창으로 동작합니다.
 *
 * 접근성: 입력칸 role=combobox(aria-expanded·aria-controls·aria-activedescendant), 목록 role=listbox,
 * 항목 role=option. 키보드 ↑/↓ 이동, Enter 는 고른 항목 또는 입력한 검색어로 검색, Esc 는 닫고 초점 유지,
 * Delete 는 고른 항목 삭제. 누름 영역은 44px 이상.
 *
 * @example
 * ```html
 * <recent-search-combobox data-type="composite" scope="core.site" name="q" placeholder="$t:search.placeholder">
 *   <on event="onSearch">
 *     <navigate path="/search"><query q="{{$args[0]}}"/></navigate>
 *   </on>
 * </recent-search-combobox>
 * ```
 */

import React, { useCallback, useEffect, useId, useRef, useState } from 'react';

import { Div } from '../basic/Div';
import { Form } from '../basic/Form';
import { Input } from '../basic/Input';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { Span } from '../basic/Span';
import { Ul } from '../basic/Ul';
import { Li } from '../basic/Li';
import type { EditorAttrs } from '../../types';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

/** 최근 검색어 한 건 (엔진 계약) */
export interface RecentSearchItem {
  id: number | null;
  keyword: string;
  searchedAt: number;
}

/** `G7Core.useSearchHistory(scope)` 반환 모양 (engine-v1.87.0) */
interface SearchHistoryHookResult {
  items: RecentSearchItem[];
  autoSave: boolean;
  enabled: boolean;
  loading: boolean;
  maxCount: number;
  record: (keyword: string) => Promise<boolean>;
  remove: (target: RecentSearchItem | number | string) => Promise<boolean>;
  clear: () => Promise<boolean>;
  setAutoSave: (on: boolean) => Promise<boolean>;
  refresh: () => Promise<void>;
}

/** 엔진에 훅이 없을 때(옛 엔진) — 기능을 끈 상태 */
const DISABLED_HISTORY: SearchHistoryHookResult = {
  items: [],
  autoSave: true,
  enabled: false,
  loading: false,
  maxCount: 0,
  record: async () => false,
  remove: async () => false,
  clear: async () => false,
  setAutoSave: async () => false,
  refresh: async () => undefined,
};

/**
 * 엔진 훅을 부릅니다. 훅이 있는지는 앱 수명 동안 바뀌지 않으므로(엔진이 G7Core 를 먼저 세운다)
 * 렌더마다 같은 갈래를 타 훅 호출 순서가 흔들리지 않습니다.
 *
 * @param scope 검색 범위
 * @returns 최근 검색어 상태·동작
 */
function useRecentSearches(scope: string): SearchHistoryHookResult {
  const hook = (window as any).G7Core?.useSearchHistory as ((s: string) => SearchHistoryHookResult) | undefined;

  return typeof hook === 'function' ? hook(scope) : DISABLED_HISTORY;
}

/**
 * 레이아웃 편집기 미리보기 화면인가 — 엔진은 미리보기에서 `_global.__isPreview` 를 세우고 기록 핸들러(recordSearchKeyword)를
 * 막지만, 이 검색창은 훅으로 직접 기록하므로 여기서 거른다(미리보기에서 관리자 계정·브라우저에 검색어가 쌓이지 않게).
 *
 * @returns 미리보기면 true
 */
const isLayoutPreview = (): boolean => !!(window as any).G7Core?.state?.get?.()?.__isPreview;

/** 입력칸 기본 모양 (왼쪽 아이콘 자리 포함) */
const DEFAULT_INPUT_CLASS =
  'w-full pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500';

/** 검색 단추 기본 모양 */
const DEFAULT_BUTTON_CLASS =
  'px-6 py-3 min-h-[44px] bg-gray-900 dark:bg-white text-white dark:text-gray-900 rounded-lg font-medium hover:bg-gray-800 dark:hover:bg-gray-100 active:scale-95 active:bg-gray-700 dark:active:bg-gray-200 transition-all cursor-pointer whitespace-nowrap';

export interface RecentSearchComboboxProps {
  /** 최근 검색어 범위 (코어 통합검색 `core.site`, 확장은 `{확장ID}.{이름}`) — 같은 범위끼리 목록을 공유한다 */
  scope?: string;
  /** 입력칸 name */
  name?: string;
  /** 처음 보일 검색어(예: 주소의 `q`). 바뀌면 입력칸이 따라간다 — 기록하지 않는다 */
  value?: string | null;
  /** 안내 문구 */
  placeholder?: string;
  /** 입력칸 접근성 이름 (없으면 안내 문구) */
  ariaLabel?: string;
  /** 검색 단추를 보이는가 */
  showButton?: boolean;
  /** 검색 단추 글자 (없으면 「검색」) */
  buttonText?: string;
  /** 입력칸 왼쪽 돋보기 아이콘을 보이는가 */
  showIcon?: boolean;
  /** 루트 클래스 */
  className?: string;
  /** 입력칸 클래스 (주면 기본 모양을 대신한다) */
  inputClassName?: string;
  /** 검색 단추 클래스 (주면 기본 모양을 대신한다) */
  buttonClassName?: string;
  /** 검색 — 인자: 다듬은 검색어. 레이아웃은 `<on event="onSearch">` 와 `$args[0]` 으로 받는다 */
  onSearch?: (keyword: string) => void;
  /** 루트 시험 식별자 */
  'data-testid'?: string;
  /** DOM id (레이아웃 편집기 코어 일괄 ID) */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
}

/**
 * 최근 검색어 펼침 목록을 가진 검색창
 */
export const RecentSearchCombobox: React.FC<RecentSearchComboboxProps> = ({
  scope: scopeProp,
  name = 'q',
  value,
  placeholder,
  ariaLabel,
  showButton = false,
  buttonText,
  showIcon = true,
  className = '',
  inputClassName,
  buttonClassName,
  onSearch,
  'data-testid': testId = 'recent-search-combobox',
  id,
  editorAttrs,
}) => {
  // 편집기 캔버스는 풀지 않은 바인딩을 null 로 넘긴다 — 기본값은 undefined 만 막으므로 여기서 다시 맞춘다
  const scope = scopeProp || 'core.site';
  const history = useRecentSearches(scope);

  const [text, setText] = useState<string>(value ?? '');
  const [focused, setFocused] = useState(false);
  const [dismissed, setDismissed] = useState(false);
  const [activeIndex, setActiveIndex] = useState(-1);
  const inputRef = useRef<HTMLInputElement>(null);

  const baseId = useId().replace(/:/g, '');
  const listboxId = `${id ?? `rsc-${baseId}`}-listbox`;
  const optionId = (index: number) => `${listboxId}-option-${index}`;

  // 주소의 검색어가 바뀌면 입력칸도 따라간다(기록은 하지 않는다)
  useEffect(() => {
    if (value !== undefined) setText(value ?? '');
  }, [value]);

  // 레이아웃 편집 모드(편집 대상을 가리지 않게)와 편집기 미리보기 화면(기록하지 않게)에서는 펼침 목록을 열지 않는다
  const inEditor = (editorAttrs !== undefined && editorAttrs !== null) || isLayoutPreview();
  const { items, autoSave, enabled, loading } = history;
  const open = enabled && !inEditor && focused && !dismissed && text.trim() === '';
  const itemCount = autoSave ? items.length : 0;

  // 목록이 줄면 고른 자리를 안쪽으로 맞춘다(삭제·자동 저장 끄기)
  useEffect(() => {
    if (activeIndex >= itemCount) setActiveIndex(itemCount - 1);
  }, [activeIndex, itemCount]);

  /**
   * 검색합니다 — 최근 검색어 기록은 기다리지 않고 바로 `onSearch` 를 부릅니다.
   *
   * @param raw 입력값
   */
  const submit = useCallback(
    (raw: string) => {
      const keyword = raw.replace(/\s+/g, ' ').trim();
      if (keyword === '') return;
      setDismissed(true);
      setActiveIndex(-1);
      // 엔진 record 는 던지지 않지만, 확장 구독자 예외 등으로 거부가 새어도 검색은 막지 않는다
      if (!isLayoutPreview()) {
        void Promise.resolve()
          .then(() => history.record(keyword))
          .catch(() => undefined);
      }
      onSearch?.(keyword);
    },
    [history, onSearch],
  );

  /** 항목을 골라 그 검색어로 검색한다 */
  const choose = (item: RecentSearchItem) => {
    setText(item.keyword);
    submit(item.keyword);
  };

  /** 항목 하나를 지운다 — 초점은 입력칸에 둔다 */
  const removeItem = (item: RecentSearchItem) => {
    void history.remove(item);
    inputRef.current?.focus();
  };

  const clearAll = () => {
    void history.clear();
    setActiveIndex(-1);
    inputRef.current?.focus();
  };

  const toggleAutoSave = (on: boolean) => {
    void history.setAutoSave(on);
    setActiveIndex(-1);
    inputRef.current?.focus();
  };

  const handleKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
    // 한글 조합 중의 키(Enter 확정 등)는 입력기 몫이다
    if ((event.nativeEvent as KeyboardEvent).isComposing || event.keyCode === 229) return;

    switch (event.key) {
      case 'ArrowDown':
        if (!open) {
          if (dismissed && text.trim() === '') {
            event.preventDefault();
            setDismissed(false);
          }
          return;
        }
        if (itemCount === 0) return;
        event.preventDefault();
        setActiveIndex((current) => (current + 1) % itemCount);
        return;
      case 'ArrowUp':
        if (!open || itemCount === 0) return;
        event.preventDefault();
        setActiveIndex((current) => (current <= 0 ? itemCount - 1 : current - 1));
        return;
      case 'Enter':
        if (open && activeIndex >= 0 && activeIndex < itemCount) {
          event.preventDefault();
          choose(items[activeIndex]);
        }
        // 고른 항목이 없으면 양식 제출(입력한 검색어)로 넘긴다
        return;
      case 'Escape':
        if (!open) return;
        event.preventDefault();
        event.stopPropagation();
        setDismissed(true);
        setActiveIndex(-1);
        return;
      case 'Delete':
        if (open && activeIndex >= 0 && activeIndex < itemCount) {
          event.preventDefault();
          removeItem(items[activeIndex]);
        }
        return;
      default:
    }
  };

  const handleSubmit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    // 조합이 막 끝난 한글이 상태보다 먼저 DOM 에 있으므로 DOM 값을 읽는다
    submit(inputRef.current?.value ?? text);
  };

  const handleTextChange = (next: string) => {
    setText(next);
    setActiveIndex(-1);
    if (next.trim() === '') setDismissed(false);
  };

  // 펼침 목록 안을 누를 때 입력칸 초점을 지킨다(단추를 눌러도 목록이 닫히지 않게)
  const keepFocus = (event: React.MouseEvent) => event.preventDefault();

  // 초점이 이 검색창 밖으로 나갈 때만 닫는다(펼침 목록의 단추로 Tab 이동하면 열린 채)
  const handleBlur = (event: React.FocusEvent<HTMLDivElement>) => {
    const next = event.relatedTarget as Node | null;
    if (next && event.currentTarget.contains(next)) return;
    setFocused(false);
    setDismissed(false);
    setActiveIndex(-1);
  };

  // 펼침 목록의 단추에서 Esc — 닫고 입력칸으로 돌아간다
  const handlePanelKeyDown = (event: React.KeyboardEvent<HTMLDivElement>) => {
    if (event.key !== 'Escape') return;
    event.preventDefault();
    event.stopPropagation();
    setDismissed(true);
    setActiveIndex(-1);
    inputRef.current?.focus();
  };

  const listLabel = t('search.history.title');
  const activeId = open && activeIndex >= 0 && activeIndex < itemCount ? optionId(activeIndex) : undefined;

  return (
    <Div
      className={`relative ${className}`}
      id={id}
      data-testid={testId}
      onFocus={() => setFocused(true)}
      onBlur={handleBlur}
      {...((editorAttrs ?? {}) as Record<string, never>)}
    >
      <Form role="search" onSubmit={handleSubmit} className={`relative w-full ${showButton ? 'flex gap-2' : ''}`}>
        <Div className="relative flex-1 min-w-0">
          {showIcon && (
            <Icon
              name="search"
              className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500 dark:text-gray-400 pointer-events-none"
            />
          )}
          <Input
            ref={inputRef}
            type="text"
            name={name}
            value={text}
            placeholder={placeholder}
            aria-label={ariaLabel ?? placeholder ?? t('search.title')}
            role="combobox"
            aria-autocomplete="list"
            aria-expanded={open}
            aria-controls={open ? listboxId : undefined}
            aria-activedescendant={activeId}
            autoComplete="off"
            onChange={(event: React.ChangeEvent<HTMLInputElement>) => handleTextChange(event.target.value)}
            onInput={(event: React.FormEvent<HTMLInputElement>) => handleTextChange(event.currentTarget.value)}
            onKeyDown={handleKeyDown}
            className={inputClassName ?? DEFAULT_INPUT_CLASS}
          />
        </Div>
        {showButton && (
          <Button type="submit" className={buttonClassName ?? DEFAULT_BUTTON_CLASS}>
            {buttonText ?? t('search.button')}
          </Button>
        )}
      </Form>

      {open && (
        <Div
          data-testid="recent-search-panel"
          className="absolute left-0 right-0 top-full mt-1 z-50 w-full overflow-hidden bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg text-left"
          onMouseDown={keepFocus}
          onKeyDown={handlePanelKeyDown}
        >
          {autoSave ? (
            <>
              <Div className="px-4 pt-3 pb-1 text-xs font-semibold text-gray-500 dark:text-gray-400">{listLabel}</Div>
              {itemCount > 0 ? (
                <Ul id={listboxId} role="listbox" aria-label={listLabel} className="max-h-72 overflow-y-auto py-1">
                  {items.map((item, index) => (
                    <Li
                      key={`${item.id ?? 'g'}-${item.keyword}`}
                      id={optionId(index)}
                      role="option"
                      aria-selected={index === activeIndex}
                      aria-label={item.keyword}
                      className={`flex items-center gap-2 min-h-[44px] pl-4 pr-1 cursor-pointer text-sm text-gray-800 dark:text-gray-100 ${
                        index === activeIndex ? 'bg-gray-100 dark:bg-gray-700' : 'hover:bg-gray-50 dark:hover:bg-gray-700/60'
                      }`}
                      onMouseEnter={() => setActiveIndex(index)}
                      onClick={() => choose(item)}
                    >
                      <Icon name="clock" className="w-4 h-4 shrink-0 text-gray-400 dark:text-gray-500" />
                      <Span className="flex-1 min-w-0 truncate">{item.keyword}</Span>
                      <Button
                        type="button"
                        tabIndex={-1}
                        aria-label={t('search.history.delete', { keyword: item.keyword })}
                        className="shrink-0 inline-flex items-center justify-center min-w-[44px] min-h-[44px] rounded-lg text-gray-400 dark:text-gray-500 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600 cursor-pointer"
                        onClick={(event: React.MouseEvent) => {
                          event.stopPropagation();
                          removeItem(item);
                        }}
                      >
                        <Icon name="x" className="w-3.5 h-3.5" />
                      </Button>
                    </Li>
                  ))}
                </Ul>
              ) : (
                <Div className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400" role="status">
                  {loading ? t('search.history.loading') : t('search.history.empty')}
                </Div>
              )}
              <Div className="flex items-center justify-between gap-2 px-2 border-t border-gray-100 dark:border-gray-700">
                {itemCount > 0 ? (
                  <Button
                    type="button"
                    className="min-h-[44px] px-2 text-xs text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-100 cursor-pointer"
                    onClick={clearAll}
                  >
                    {t('search.history.clear_all')}
                  </Button>
                ) : (
                  <Span />
                )}
                <Button
                  type="button"
                  className="min-h-[44px] px-2 text-xs text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-100 cursor-pointer"
                  onClick={() => toggleAutoSave(false)}
                >
                  {t('search.history.auto_save_off')}
                </Button>
              </Div>
            </>
          ) : (
            <Div className="flex items-center justify-between gap-2 pl-4 pr-2 py-1">
              <Span className="text-sm text-gray-600 dark:text-gray-300" role="status">
                {t('search.history.auto_save_off_notice')}
              </Span>
              <Button
                type="button"
                className="shrink-0 min-h-[44px] px-3 text-sm font-medium text-blue-600 dark:text-blue-400 hover:underline cursor-pointer"
                onClick={() => toggleAutoSave(true)}
              >
                {t('search.history.auto_save_on')}
              </Button>
            </Div>
          )}
        </Div>
      )}
    </Div>
  );
};

export default RecentSearchCombobox;
