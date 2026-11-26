/**
 * TemplateMenuField — 템플릿 설정 `menu` 칸 편집기: 사용자 화면 메뉴를 끌어서 놓아 짠다
 *
 * 관리자 메뉴 관리와 같은 두 판이다. 왼쪽은 끌어서 놓는 트리(가로로 끌면 하위 메뉴로 들어가고, 왼쪽으로 끌면 상위로 나온다),
 * 오른쪽은 고른 항목의 편집 판(이름·주소·새 창·보이기·사용). 값은 칸 값 그대로(항목 트리)이고 바뀌면 `onChange(next)` 로 올린다.
 *
 * - 2단까지: 하위 메뉴의 부모는 링크 항목뿐이고, 하위 메뉴가 있는 항목은 다른 항목 아래로 들어가지 못한다.
 * - 자동 항목(예: 게시판 전체)은 메뉴에 한 번만 — 이미 있으면 추가 단추가 꺼진다. 템플릿이 그 자리에 무엇을 그릴지 안다.
 * - 끌기 없이도 같은 일을 한다: 편집 판의 위로·아래로·하위로·상위로 단추(키보드 사용자).
 * - 서버 검증 오류는 항목 위치(`{칸}.{i}` · `{칸}.{i}.children.{j}` · 그 아래 `.url` 등)로 오고, 그 항목 줄에 표시가 붙는다.
 */

import React, { useEffect, useMemo, useState } from 'react';
import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  DragEndEvent,
  DragMoveEvent,
  DragStartEvent,
} from '@dnd-kit/core';
import { arrayMove, SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Div } from '../components/basic/Div';
import { Span } from '../components/basic/Span';
import { Button } from '../components/basic/Button';
import { Icon } from '../components/basic/Icon';
import { Input } from '../components/basic/Input';
import { Select } from '../components/basic/Select';
import { Checkbox } from '../components/basic/Checkbox';
import { Label } from '../components/basic/Label';
import { P } from '../components/basic/P';
import { Toggle } from './parts/Toggle';
import { MultilingualInput } from './parts/MultilingualInput';
import {
  LINK_KIND,
  TemplateMenuNode,
  TemplateMenuRow,
  buildMenu,
  countMenuItems,
  flattenMenu,
  indentMenuItem,
  locateMenuItem,
  menuItemPath,
  moveMenuItem,
  newMenuItemId,
  outdentMenuItem,
  pickMenuLabel,
  projectDepth,
  removeMenuItem,
  updateMenuItem,
} from './templateMenuTree';
import { translate } from '../support/translate';
import { BREAKPOINTS, useViewportWidth } from '../components/layout/viewportWidth';

// G7Core.t() 번역 함수 참조 — 엔진 밖(단위 테스트)이면 대체 문구를 쓴다
const t = (key: string, fallback: string, params?: Record<string, string | number>): string => {
  const translated = translate(key, params);

  return typeof translated === 'string' && translated !== key ? translated : fallback;
};

/** 한 단 들여쓰기 폭(px) — 끌 때 이만큼 가로로 움직이면 하위로 들어간다 */
const INDENT_WIDTH = 28;

/** 자동 항목 서술 — 조회 응답 `fields[].auto_items[]` */
export interface TemplateMenuAutoItem {
  key: string;
  label: string;
  hint?: string | null;
}

/** 역할 선택지 — `/api/admin/roles/active` 의 행 */
export interface TemplateMenuRoleOption {
  identifier: string;
  name?: string | Record<string, string>;
}

export interface TemplateMenuFieldProps {
  /** 칸 값 (항목 트리) */
  value?: TemplateMenuNode[] | null;
  /** 바뀌면 트리 전체 */
  onChange?: (next: TemplateMenuNode[]) => void;
  /** 칸 경로 (`섹션.키`) — 오류 위치를 찾는 데 쓴다 */
  path: string;
  /** 칸 이름 (접근성 이름) */
  label?: string;
  /** 전체 항목 수 상한 */
  maxItems?: number;
  /** 허용 깊이 (1 또는 2) */
  maxDepth?: number;
  /** 자동 항목 */
  autoItems?: TemplateMenuAutoItem[];
  /** 역할 선택지 */
  roleOptions?: TemplateMenuRoleOption[];
  /** 서버 검증 오류 */
  errors?: Record<string, string[] | string> | null;
  /** 읽기 전용 */
  disabled?: boolean;
}

const currentLocale = (): string => (typeof document !== 'undefined' ? document.documentElement.lang : '') || 'ko';

const firstError = (errors: TemplateMenuFieldProps['errors'], key: string): string | undefined => {
  const entry = errors?.[key];

  return Array.isArray(entry) ? entry[0] : (entry ?? undefined);
};

interface RowProps {
  row: TemplateMenuRow;
  depth: number;
  title: string;
  isAuto: boolean;
  selected: boolean;
  hasError: boolean;
  disabled: boolean;
  onSelect: () => void;
}

/**
 * 트리 한 줄 — 손잡이로 끌고, 줄을 누르면 고른다.
 */
const MenuRow: React.FC<RowProps> = ({ row, depth, title, isAuto, selected, hasError, disabled, onSelect }) => {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: row.node.id, disabled });
  const node = row.node;
  const visibility = node.visibility ?? 'all';

  return (
    <Div
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition, paddingLeft: depth * INDENT_WIDTH }}
      className={isDragging ? 'opacity-60' : ''}
      data-testid="template-menu-row"
      data-menu-id={node.id}
      data-depth={depth}
    >
      <Div
        role="button"
        tabIndex={0}
        aria-pressed={selected}
        onClick={onSelect}
        onKeyDown={(e: React.KeyboardEvent) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            onSelect();
          }
        }}
        className={`flex items-center gap-2 rounded-lg border px-2 py-2 cursor-pointer transition-colors ${selected ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30 dark:border-blue-400' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700'} ${node.is_active === false ? 'opacity-60' : ''}`}
      >
        {!disabled && (
          <Span
            {...attributes}
            {...listeners}
            className="flex-center w-6 h-6 text-gray-400 dark:text-gray-500 cursor-grab active:cursor-grabbing"
            aria-label={t('settings.screen.menu.drag_handle', 'Drag to move', { name: title })}
            onClick={(e: React.MouseEvent) => e.stopPropagation()}
          >
            <Icon name="grip-vertical" className="w-4 h-4" />
          </Span>
        )}
        <Icon name={isAuto ? 'bolt' : 'link'} className={`w-4 h-4 ${isAuto ? 'text-amber-500 dark:text-amber-400' : 'text-gray-500 dark:text-gray-400'}`} />
        <Span className="flex-1 min-w-0 truncate text-sm text-gray-900 dark:text-white">{title}</Span>
        {isAuto && <Span className="badge badge-warning text-xs">{t('settings.screen.menu.badge_auto', 'Auto')}</Span>}
        {node.new_tab && <Icon name="external-link-alt" className="w-3 h-3 text-gray-400 dark:text-gray-500" aria-label={t('settings.screen.menu.new_tab', 'New tab')} />}
        {visibility !== 'all' && (
          <Span className="badge badge-info text-xs">{t(`settings.screen.menu.visibility_${visibility}`, visibility)}</Span>
        )}
        {node.is_active === false && <Span className="badge badge-gray text-xs">{t('settings.screen.menu.badge_inactive', 'Off')}</Span>}
        {hasError && <Icon name="exclamation-circle" className="w-4 h-4 text-red-500 dark:text-red-400" aria-label={t('settings.screen.menu.has_error', 'Has errors')} />}
      </Div>
    </Div>
  );
};

/**
 * 메뉴 칸 편집기
 */
export const TemplateMenuField: React.FC<TemplateMenuFieldProps> = ({
  value,
  onChange,
  path,
  label,
  maxItems = 30,
  maxDepth = 2,
  autoItems = [],
  roleOptions = [],
  errors,
  disabled = false,
}) => {
  // 폭 분기는 엔진 폭으로 고른다 — Tailwind 반응형 접두는 창 폭으로 판정되어 편집기 미리보기·엔진 responsive 와 경계가 갈린다.
  const viewportWidth = useViewportWidth();
  const sideBySide = viewportWidth >= BREAKPOINTS.lg;
  const roleColumns = viewportWidth >= BREAKPOINTS.sm;
  const items = useMemo<TemplateMenuNode[]>(() => (Array.isArray(value) ? value : []), [value]);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [activeId, setActiveId] = useState<string | null>(null);
  const [baseDepth, setBaseDepth] = useState(0);
  const [offsetX, setOffsetX] = useState(0);
  const [confirmingDelete, setConfirmingDelete] = useState(false);
  const locale = currentLocale();

  // 고른 항목이 사라지면(삭제·저장 후 새 값) 고름을 푼다
  useEffect(() => {
    if (selectedId && !locateMenuItem(items, selectedId)) {
      setSelectedId(null);
    }
  }, [items, selectedId]);

  useEffect(() => setConfirmingDelete(false), [selectedId]);

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  const autoByKey = useMemo(() => Object.fromEntries(autoItems.map((auto) => [auto.key, auto])), [autoItems]);
  const activeNode = activeId ? locateMenuItem(items, activeId) : null;
  const activeHasChildren = activeNode && activeNode.parentIndex === null ? (items[activeNode.index]?.children?.length ?? 0) > 0 : false;
  // 상위 메뉴를 끄는 동안에는 그 자식을 목록에서 빼 함께 움직인다
  const rows = useMemo(() => flattenMenu(items, activeHasChildren ? activeId : null), [items, activeId, activeHasChildren]);
  const total = countMenuItems(items);
  const usedAuto = new Set<string>(flattenMenu(items).map((row) => row.node.kind).filter((kind) => kind !== LINK_KIND));

  const commit = (next: TemplateMenuNode[]) => onChange?.(next);

  const titleOf = (node: TemplateMenuNode): string =>
    pickMenuLabel(node.label, locale) || (node.kind !== LINK_KIND ? (autoByKey[node.kind]?.label ?? node.kind) : t('settings.screen.menu.untitled', '(No name)'));

  const errorPrefix = (node: TemplateMenuNode): string | null => {
    const at = menuItemPath(items, node.id);

    return at === null ? null : `${path}.${at}`;
  };

  const rowHasError = (node: TemplateMenuNode): boolean => {
    const prefix = errorPrefix(node);

    if (!prefix || !errors) {
      return false;
    }

    return Object.keys(errors).some((key) => key === prefix || (key.startsWith(`${prefix}.`) && !key.startsWith(`${prefix}.children.`)));
  };

  const addItem = (kind: string, asChildOf: string | null = null) => {
    if (total >= maxItems) {
      return;
    }

    const node: TemplateMenuNode = kind === LINK_KIND
      ? { id: newMenuItemId(), kind, label: {}, url: '', new_tab: false, is_active: true, visibility: 'all', roles: [], children: [] }
      : { id: newMenuItemId(), kind, label: {}, is_active: true, visibility: 'all', roles: [], children: [] };

    const next = asChildOf
      ? items.map((item) => (item.id === asChildOf ? { ...item, children: [...(item.children ?? []), node] } : item))
      : [...items, node];

    commit(next);
    setSelectedId(node.id);
  };

  const handleDragStart = (event: DragStartEvent) => {
    const id = String(event.active.id);
    const at = locateMenuItem(items, id);

    setActiveId(id);
    setBaseDepth(at && at.parentIndex !== null ? 1 : 0);
    setOffsetX(0);
  };

  const handleDragMove = (event: DragMoveEvent) => setOffsetX(event.delta.x);

  const resetDrag = () => {
    setActiveId(null);
    setOffsetX(0);
  };

  const handleDragEnd = (event: DragEndEvent) => {
    const id = String(event.active.id);
    const overId = event.over ? String(event.over.id) : id;
    const from = rows.findIndex((row) => row.node.id === id);
    const to = rows.findIndex((row) => row.node.id === overId);

    if (from < 0 || to < 0) {
      resetDrag();

      return;
    }

    const moved = arrayMove(rows, from, to);
    const depth = projectDepth(moved, to, baseDepth, event.delta.x, INDENT_WIDTH, maxDepth);

    moved[to] = { ...moved[to], depth };
    commit(buildMenu(moved, activeHasChildren ? id : null));
    setSelectedId(id);
    resetDrag();
  };

  // 끄는 동안 놓일 깊이를 미리 보여 준다
  const previewDepth = (row: TemplateMenuRow, index: number): number => {
    if (row.node.id !== activeId) {
      return row.depth;
    }

    return projectDepth(rows, index, baseDepth, offsetX, INDENT_WIDTH, maxDepth);
  };

  const selectedAt = selectedId ? locateMenuItem(items, selectedId) : null;
  const selected = selectedAt
    ? (selectedAt.parentIndex === null ? items[selectedAt.index] : items[selectedAt.parentIndex].children?.[selectedAt.index]) ?? null
    : null;
  const selectedPrefix = selected ? errorPrefix(selected) : null;
  const patchSelected = (patch: Partial<TemplateMenuNode>) => selected && commit(updateMenuItem(items, selected.id, patch));
  const siblings = selectedAt ? (selectedAt.parentIndex === null ? items : items[selectedAt.parentIndex].children ?? []) : [];
  // 들여쓰기 — 상위 항목이고, 바로 위 형제가 링크이고, 자기 하위 메뉴가 없을 때(indentMenuItem 과 같은 조건)
  const canIndent = !!selected && maxDepth >= 2 && selectedAt?.parentIndex === null && selectedAt.index > 0
    && (selected.children?.length ?? 0) === 0 && items[selectedAt.index - 1]?.kind === LINK_KIND;
  const isAutoSelected = !!selected && selected.kind !== LINK_KIND;
  const selectedVisibility = selected?.visibility ?? 'all';

  return (
    <Div className={`grid ${sideBySide ? 'grid-cols-2' : 'grid-cols-1'} gap-4`} data-testid="template-menu-field" aria-label={label}>
      {/* 왼쪽 — 끌어서 놓는 트리 */}
      <Div className="space-y-3">
        <Div className="flex flex-wrap items-center gap-2">
          {!disabled && (
            <>
              <Button type="button" className="btn btn-primary btn-sm flex-center gap-1.5" disabled={total >= maxItems} onClick={() => addItem(LINK_KIND)} data-testid="template-menu-add-link">
                <Icon name="plus" />
                <Span>{t('settings.screen.menu.add_link', 'Add link')}</Span>
              </Button>
              {autoItems.map((auto) => (
                <Button
                  key={auto.key}
                  type="button"
                  className="btn btn-secondary btn-sm flex-center gap-1.5"
                  disabled={usedAuto.has(auto.key) || total >= maxItems}
                  title={usedAuto.has(auto.key) ? t('settings.screen.menu.auto_used', 'Already in the menu') : (auto.hint ?? undefined)}
                  onClick={() => addItem(auto.key)}
                  data-testid={`template-menu-add-auto-${auto.key}`}
                >
                  <Icon name="bolt" />
                  <Span>{auto.label}</Span>
                </Button>
              ))}
            </>
          )}
          <Span className="ml-auto text-xs text-gray-500 dark:text-gray-400">{t('settings.screen.menu.count', `${total} / ${maxItems}`, { current: total, max: maxItems })}</Span>
        </Div>

        {items.length === 0 ? (
          <Div className="rounded-lg border border-dashed border-gray-300 dark:border-gray-600 p-6 text-center" data-testid="template-menu-empty">
            <P className="text-sm text-gray-500 dark:text-gray-400">{t('settings.screen.menu.empty', 'The menu is empty. Add a link to start.')}</P>
          </Div>
        ) : (
          <DndContext sensors={sensors} collisionDetection={closestCenter} onDragStart={handleDragStart} onDragMove={handleDragMove} onDragEnd={handleDragEnd} onDragCancel={resetDrag}>
            <SortableContext items={rows.map((row) => row.node.id)} strategy={verticalListSortingStrategy}>
              <Div className="space-y-1.5" role="list" aria-label={label}>
                {rows.map((row, index) => (
                  <MenuRow
                    key={row.node.id}
                    row={row}
                    depth={previewDepth(row, index)}
                    title={titleOf(row.node)}
                    isAuto={row.node.kind !== LINK_KIND}
                    selected={row.node.id === selectedId}
                    hasError={rowHasError(row.node)}
                    disabled={disabled}
                    onSelect={() => setSelectedId(row.node.id)}
                  />
                ))}
              </Div>
            </SortableContext>
          </DndContext>
        )}
        {maxDepth >= 2 && !disabled && items.length > 0 && (
          <P className="form-hint">{t('settings.screen.menu.drag_hint', 'Drag the handle to reorder. Drag right to make it a sub-menu, left to move it back up.')}</P>
        )}
        {total >= maxItems && <P className="form-hint">{t('settings.screen.fields.max_items_reached', `Up to ${maxItems}`, { max: maxItems })}</P>}
      </Div>

      {/* 오른쪽 — 고른 항목 편집 */}
      <Div className="rounded-lg border border-gray-200 dark:border-gray-700 p-4" data-testid="template-menu-editor">
        {!selected ? (
          <P className="text-sm text-gray-500 dark:text-gray-400 text-center py-6">{t('settings.screen.menu.select_hint', 'Select an item on the left to edit it.')}</P>
        ) : (
          <Div className="space-y-4">
            <Div className="flex items-center gap-2">
              <Icon name={isAutoSelected ? 'bolt' : 'link'} className="w-4 h-4 text-gray-500 dark:text-gray-400" />
              <Span className="font-medium text-gray-900 dark:text-white">{titleOf(selected)}</Span>
              {selectedAt?.parentIndex !== null && <Span className="badge badge-gray text-xs">{t('settings.screen.menu.badge_child', 'Sub-menu')}</Span>}
            </Div>
            {selectedPrefix && firstError(errors, selectedPrefix) && <Span className="form-error" data-testid="template-menu-item-error">{firstError(errors, selectedPrefix)}</Span>}

            {isAutoSelected && autoByKey[selected.kind]?.hint && <P className="form-hint">{autoByKey[selected.kind]?.hint}</P>}

            <Div>
              <Label className="form-label">{t('settings.screen.menu.label', 'Name')}{!isAutoSelected ? ' *' : ''}</Label>
              <MultilingualInput
                value={selected.label && typeof selected.label === 'object' ? selected.label : {}}
                onChange={(e: any) => patchSelected({ label: e?.target?.value ?? {} })}
                layout="tabs"
                maxLength={60}
                placeholder={isAutoSelected ? (autoByKey[selected.kind]?.label ?? '') : ''}
                disabled={disabled}
                ariaLabel={t('settings.screen.menu.label', 'Name')}
                error={selectedPrefix ? firstError(errors, `${selectedPrefix}.label`) : undefined}
              />
              {isAutoSelected && <P className="form-hint mt-1">{t('settings.screen.menu.auto_label_hint', 'Leave empty to use the default name.')}</P>}
            </Div>

            {!isAutoSelected && (
              <>
                <Div>
                  <Label className="form-label">{t('settings.screen.menu.url', 'Link')}</Label>
                  <Input
                    type="text"
                    className="input"
                    value={selected.url ?? ''}
                    placeholder="/board/notice"
                    disabled={disabled}
                    aria-label={t('settings.screen.menu.url', 'Link')}
                    onChange={(e) => patchSelected({ url: e.target.value })}
                    data-testid="template-menu-url"
                  />
                  <P className="form-hint mt-1">{t('settings.screen.menu.url_hint', 'A site path (/board/notice) or a full address (https://…). A parent menu with sub-menus may leave it empty.')}</P>
                  {selectedPrefix && firstError(errors, `${selectedPrefix}.url`) && <Span className="form-error">{firstError(errors, `${selectedPrefix}.url`)}</Span>}
                </Div>
                <Div className="flex items-center justify-between gap-4">
                  <Span className="form-label mb-0">{t('settings.screen.menu.new_tab', 'Open in a new tab')}</Span>
                  <Toggle checked={selected.new_tab === true} onChange={(checked: any) => patchSelected({ new_tab: typeof checked === 'boolean' ? checked : !!checked?.target?.checked })} disabled={disabled} ariaLabel={t('settings.screen.menu.new_tab', 'Open in a new tab')} />
                </Div>
              </>
            )}

            <Div>
              <Label className="form-label">{t('settings.screen.menu.visibility', 'Visible to')}</Label>
              <Select
                value={selectedVisibility}
                disabled={disabled}
                aria-label={t('settings.screen.menu.visibility', 'Visible to')}
                onChange={(e) => patchSelected({ visibility: e.target.value as TemplateMenuNode['visibility'], roles: e.target.value === 'roles' ? (selected.roles ?? []) : [] })}
                options={['all', 'members', 'guests', 'roles'].map((option) => ({ value: option, label: t(`settings.screen.menu.visibility_${option}`, option) }))}
                data-testid="template-menu-visibility"
              />
              {selectedVisibility === 'roles' && (
                <Div className={`mt-2 grid ${roleColumns ? 'grid-cols-2' : 'grid-cols-1'} gap-2`} data-testid="template-menu-roles">
                  {roleOptions.length === 0 && <P className="form-hint">{t('settings.screen.menu.roles_unavailable', 'No roles to choose from.')}</P>}
                  {/* 기본 Checkbox 는 이름표를 그리지 않는다 — Label 로 감싸 이름을 보이고 누르기 영역을 넓힌다 */}
                  {roleOptions.map((role) => (
                    <Label key={role.identifier} className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                      <Checkbox
                        className="checkbox"
                        checked={(selected.roles ?? []).includes(role.identifier)}
                        disabled={disabled}
                        onChange={(e) => {
                          const current = selected.roles ?? [];
                          patchSelected({ roles: e.target.checked ? [...current, role.identifier] : current.filter((r) => r !== role.identifier) });
                        }}
                      />
                      <Span>{pickMenuLabel(role.name as any, locale) || role.identifier}</Span>
                    </Label>
                  ))}
                  {selectedPrefix && firstError(errors, `${selectedPrefix}.roles`) && <Span className={`form-error ${roleColumns ? 'col-span-2' : ''}`}>{firstError(errors, `${selectedPrefix}.roles`)}</Span>}
                </Div>
              )}
              <P className="form-hint mt-1">{t('settings.screen.menu.visibility_hint', 'This only hides the menu item. It does not block access to the page.')}</P>
            </Div>

            <Div className="flex items-center justify-between gap-4">
              <Span className="form-label mb-0">{t('settings.screen.menu.is_active', 'Show this item')}</Span>
              <Toggle checked={selected.is_active !== false} onChange={(checked: any) => patchSelected({ is_active: typeof checked === 'boolean' ? checked : !!checked?.target?.checked })} disabled={disabled} ariaLabel={t('settings.screen.menu.is_active', 'Show this item')} />
            </Div>

            {!disabled && (
              <Div className="flex flex-wrap items-center gap-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                <Button type="button" className="btn btn-ghost btn-sm" aria-label={t('settings.screen.fields.move_up', 'Move up')} disabled={!selectedAt || selectedAt.index === 0} onClick={() => commit(moveMenuItem(items, selected.id, -1))}>
                  <Icon name="arrow-up" />
                </Button>
                <Button type="button" className="btn btn-ghost btn-sm" aria-label={t('settings.screen.fields.move_down', 'Move down')} disabled={!selectedAt || selectedAt.index >= siblings.length - 1} onClick={() => commit(moveMenuItem(items, selected.id, 1))}>
                  <Icon name="arrow-down" />
                </Button>
                {maxDepth >= 2 && (
                  <>
                    <Button type="button" className="btn btn-ghost btn-sm flex-center gap-1" disabled={!canIndent} onClick={() => commit(indentMenuItem(items, selected.id, maxDepth))} data-testid="template-menu-indent">
                      <Icon name="indent" />
                      <Span>{t('settings.screen.menu.indent', 'Make sub-menu')}</Span>
                    </Button>
                    <Button type="button" className="btn btn-ghost btn-sm flex-center gap-1" disabled={selectedAt?.parentIndex === null} onClick={() => commit(outdentMenuItem(items, selected.id))} data-testid="template-menu-outdent">
                      <Icon name="outdent" />
                      <Span>{t('settings.screen.menu.outdent', 'Move up a level')}</Span>
                    </Button>
                    {selected.kind === LINK_KIND && selectedAt?.parentIndex === null && (
                      <Button type="button" className="btn btn-ghost btn-sm flex-center gap-1" disabled={total >= maxItems} onClick={() => addItem(LINK_KIND, selected.id)} data-testid="template-menu-add-child">
                        <Icon name="plus" />
                        <Span>{t('settings.screen.menu.add_child', 'Add sub-menu')}</Span>
                      </Button>
                    )}
                  </>
                )}
                <Div className="ml-auto flex items-center gap-2">
                  {confirmingDelete ? (
                    <>
                      <Span className="text-xs text-red-600 dark:text-red-400">
                        {(selected.children?.length ?? 0) > 0
                          ? t('settings.screen.menu.delete_confirm_children', 'Delete with its sub-menus?', { count: selected.children?.length ?? 0 })
                          : t('settings.screen.menu.delete_confirm', 'Delete this item?')}
                      </Span>
                      <Button type="button" className="btn btn-danger btn-sm" onClick={() => commit(removeMenuItem(items, selected.id))} data-testid="template-menu-delete-confirm">
                        {t('settings.screen.menu.delete', 'Delete')}
                      </Button>
                      <Button type="button" className="btn btn-ghost btn-sm" onClick={() => setConfirmingDelete(false)}>
                        {t('settings.screen.menu.cancel', 'Cancel')}
                      </Button>
                    </>
                  ) : (
                    <Button type="button" className="btn btn-ghost btn-sm text-red-600 dark:text-red-400 flex-center gap-1" onClick={() => setConfirmingDelete(true)} data-testid="template-menu-delete">
                      <Icon name="trash" />
                      <Span>{t('settings.screen.menu.delete', 'Delete')}</Span>
                    </Button>
                  )}
                </Div>
              </Div>
            )}
          </Div>
        )}
      </Div>
    </Div>
  );
};

export default TemplateMenuField;
