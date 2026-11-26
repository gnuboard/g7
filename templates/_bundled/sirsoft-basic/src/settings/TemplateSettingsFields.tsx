/**
 * TemplateSettingsFields — sirsoft-basic 설정 화면의 칸 그리기
 *
 * 이 템플릿 설정 화면 부품(`TemplateSettingsScreen`)이 쓴다 — 설정 화면 묶음(`dist/settings/*`)에 묶여 어느 관리자 템플릿에서나
 * 뜬다. 조회 응답의 섹션 서술(`sections`)대로 섹션마다 카드 하나, 칸마다 종류별 입력을 그리고, 이 템플릿의 헤더 메뉴
 * 칸(`menu`)은 끌어서 놓는 편집기(`TemplateMenuField`)로 그린다. 칸마다 다른 경로(`{섹션}.{키}`)에 쓰는 동적 바인딩이라 선언형
 * 레이아웃 대신 컴포넌트로 둔다.
 *
 * - 값은 섹션 중첩 객체(`{섹션: {키: 값}}`)이고, 바뀌면 폼 전체를 `onChange(next)` 로 올린다. 업로더 여러 개가 연달아
 *   끝나도 앞 결과가 덮이지 않게 최신 값을 ref 로 이어 붙인다.
 * - 첨부 칸은 지연 업로드다 — 저장 버튼이 보내는 `uploadTriggerEvent`(기본 `upload:template_settings`) 하나로 모든
 *   업로더가 올린다(`emitEvent` 는 받는 쪽 전부를 기다린다). 파일만 고른 상태는 `onDirty()` 로 알린다.
 * - 서버 검증 오류는 `errors` 의 `{섹션}.{키}`(목록 행은 `{섹션}.{키}.{행}.{칸}`) 첫 문구를 칸 아래에 보인다.
 */

import React, { useCallback, useEffect, useRef } from 'react';
import { Div } from '../components/basic/Div';
import { Span } from '../components/basic/Span';
import { Button } from '../components/basic/Button';
import { Icon } from '../components/basic/Icon';
import { Input } from '../components/basic/Input';
import { Select } from '../components/basic/Select';
import { Textarea } from '../components/basic/Textarea';
import { Label } from '../components/basic/Label';
import { P } from '../components/basic/P';
import { H3 } from '../components/basic/H3';
import { Toggle } from './parts/Toggle';
import { RadioGroup } from './parts/RadioGroup';
import { MultilingualInput } from './parts/MultilingualInput';
import { FileUploader } from '../components/composite/FileUploader';
import { TemplateMenuField, TemplateMenuAutoItem, TemplateMenuRoleOption } from './TemplateMenuField';
import type { EditorAttrs } from '../types';
import { translate } from '../support/translate';
import { BREAKPOINTS, useViewportWidth } from '../components/layout/viewportWidth';

// G7Core.t() 번역 함수 참조 — 엔진 밖(단위 테스트)이면 대체 문구를 쓴다
const t = (key: string, fallback: string, params?: Record<string, string | number>): string => {
  const translated = translate(key, params);

  return typeof translated === 'string' && translated !== key ? translated : fallback;
};

/** 설정 화면은 관리자 화면이다 — 이 템플릿 업로더 기본 주소(사용자 화면용 `/api/attachments`) 대신 관리자 첨부 API 를 쓴다 */
const ADMIN_ATTACHMENT_ENDPOINTS = {
  upload: '/api/admin/attachments',
  delete: '/api/admin/attachments/:id',
  reorder: '/api/admin/attachments/reorder',
};

/** 선택지 서술 */
export interface TemplateSettingOption {
  value: string;
  label: string;
}

/** 칸 서술 — 조회 응답 `sections[].fields[]` */
export interface TemplateSettingField {
  key: string;
  type: string;
  label: string;
  hint?: string | null;
  placeholder?: string | null;
  required?: boolean;
  sensitive?: boolean;
  min?: number;
  max?: number;
  step?: number;
  max_length?: number;
  max_items?: number;
  options?: TemplateSettingOption[];
  collection?: string;
  max_files?: number;
  max_size_kb?: number | null;
  accept?: string;
  visible_if?: { field: string; equals?: unknown; in?: unknown[] };
  item_fields?: TemplateSettingField[];
  /** 메뉴 칸 — 허용 깊이 */
  max_depth?: number;
  /** 메뉴 칸 — 템플릿이 그리는 자동 항목 */
  auto_items?: TemplateMenuAutoItem[];
}

/** 섹션 서술 */
export interface TemplateSettingSection {
  key: string;
  label: string;
  hint?: string | null;
  fields: TemplateSettingField[];
}

export type TemplateSettingsValue = Record<string, Record<string, any>>;

export interface TemplateSettingsFieldsProps {
  /** 섹션·칸 서술 (조회 응답 `data.sections`) */
  sections?: TemplateSettingSection[];
  /** 현재 값 (섹션 중첩) */
  value?: TemplateSettingsValue | null;
  /** 서버 검증 오류 (`{경로: [문구]}`) */
  errors?: Record<string, string[] | string> | null;
  /** 읽기 전용 */
  disabled?: boolean;
  /** 첨부 칸 업로드 신호 이름 */
  uploadTriggerEvent?: string;
  /** 값이 바뀌면 폼 전체 */
  onChange?: (next: TemplateSettingsValue) => void;
  /** 파일만 고르고 아직 올리지 않은 상태 등 — 저장 버튼을 풀어 주는 표시용 */
  onDirty?: () => void;
  /** 업로드 실패 문구 */
  onUploadError?: (message: string) => void;
  /** 메뉴 칸의 「특정 역할만」 선택지 (`/api/admin/roles/active`) */
  roleOptions?: TemplateMenuRoleOption[];
  /** DOM id (레이아웃 편집기 코어 일괄 ID) */
  id?: string;
  /** 레이아웃 편집기 주입 속성 */
  editorAttrs?: EditorAttrs;
}

/**
 * 오류 문구 하나를 꺼냅니다.
 *
 * @param errors 오류 맵
 * @param path 경로
 * @returns 첫 문구 (없으면 undefined)
 */
const errorAt = (errors: TemplateSettingsFieldsProps['errors'], path: string): string | undefined => {
  const entry = errors?.[path];

  return Array.isArray(entry) ? entry[0] : (entry ?? undefined);
};

/**
 * 같은 섹션 값으로 칸 보임 조건을 판정합니다.
 *
 * @param field 칸 서술
 * @param sectionValue 그 섹션 값
 * @returns 보이면 true
 */
const isVisible = (field: TemplateSettingField, sectionValue: Record<string, any>): boolean => {
  const condition = field.visible_if;

  if (!condition) {
    return true;
  }

  const current = sectionValue?.[condition.field];

  return Array.isArray(condition.in) ? condition.in.includes(current) : current === condition.equals;
};

/**
 * 템플릿 설정 칸 묶음
 */
export const TemplateSettingsFields: React.FC<TemplateSettingsFieldsProps> = ({
  sections = [],
  value,
  errors,
  disabled = false,
  uploadTriggerEvent = 'upload:template_settings',
  onChange,
  onDirty,
  onUploadError,
  roleOptions = [],
  id,
  editorAttrs,
}) => {
  // 폭 분기는 엔진 폭으로 고른다 — Tailwind 반응형 접두는 창 폭으로 판정되어 편집기 미리보기·엔진 responsive 와 경계가 갈린다.
  const twoColumns = useViewportWidth() >= BREAKPOINTS.md;

  // 업로더 콜백이 연달아 와도 직전 결과 위에 쌓이게 최신 값을 따로 들고 있는다
  const latestRef = useRef<TemplateSettingsValue>(value ?? {});

  useEffect(() => {
    latestRef.current = value ?? {};
  }, [value]);

  const setField = useCallback((section: string, key: string, next: unknown) => {
    const current = latestRef.current ?? {};
    const updated: TemplateSettingsValue = { ...current, [section]: { ...(current[section] ?? {}), [key]: next } };

    latestRef.current = updated;
    onChange?.(updated);
  }, [onChange]);

  /**
   * 칸 종류별 입력을 그립니다(목록 행 안 칸도 같은 함수).
   */
  const renderControl = (
    field: TemplateSettingField,
    current: any,
    change: (next: unknown) => void,
    path: string,
  ): React.ReactNode => {
    const error = errorAt(errors, path);
    const common = { disabled, 'aria-label': field.label, 'aria-invalid': error ? true : undefined };

    switch (field.type) {
      case 'boolean':
        return <Toggle checked={current === true} onChange={(checked: any) => change(typeof checked === 'boolean' ? checked : !!checked?.target?.checked)} disabled={disabled} ariaLabel={field.label} />;

      case 'enum': {
        const options = (field.options ?? []).map((option) => ({ value: String(option.value), label: option.label }));

        if (options.length <= 4) {
          return <RadioGroup name={path} options={options} value={current == null ? '' : String(current)} onChange={(e) => change(e.target.value)} disabled={disabled} inline />;
        }

        return <Select options={options} value={current == null ? '' : String(current)} onChange={(e) => change(e.target.value)} {...common} />;
      }

      case 'integer':
        return (
          <Input
            type="number"
            className="input w-32"
            value={current ?? ''}
            min={field.min}
            max={field.max}
            step={field.step ?? 1}
            onChange={(e) => change(e.target.value === '' ? null : Number(e.target.value))}
            {...common}
          />
        );

      case 'text':
        return <Textarea className="textarea" rows={4} value={current ?? ''} maxLength={field.max_length} placeholder={field.placeholder ?? undefined} onChange={(e) => change(e.target.value)} {...common} />;

      case 'color':
        return (
          <Div className="flex items-center gap-2">
            <Input type="color" className="h-9 w-12 cursor-pointer rounded border border-gray-300 dark:border-gray-600" value={/^#[0-9a-fA-F]{6}$/.test(current ?? '') ? current : '#000000'} onChange={(e) => change(e.target.value)} {...common} />
            <Input type="text" className="input w-32" value={current ?? ''} onChange={(e) => change(e.target.value)} {...common} />
          </Div>
        );

      case 'multilingual':
        return (
          <MultilingualInput
            value={current && typeof current === 'object' ? current : {}}
            onChange={(e: any) => change(e?.target?.value ?? {})}
            layout="tabs"
            maxLength={field.max_length}
            placeholder={field.placeholder ?? ''}
            disabled={disabled}
            ariaLabel={field.label}
            error={error}
          />
        );

      case 'attachments':
      case 'image': {
        const files: any[] = Array.isArray(current) ? current : [];
        const maxFiles = field.max_files ?? 1;

        return (
          <Div className={disabled ? 'pointer-events-none opacity-60' : undefined} aria-disabled={disabled || undefined}>
          <FileUploader
            collection={field.collection}
            maxFiles={maxFiles}
            maxSize={field.max_size_kb ? Math.max(field.max_size_kb / 1024, 0.01) : undefined}
            accept={field.accept}
            autoUpload={false}
            apiEndpoints={ADMIN_ATTACHMENT_ENDPOINTS}
            uploadTriggerEvent={uploadTriggerEvent}
            initialFiles={files}
            confirmBeforeRemove
            onFilesChange={(pending: unknown) => {
              // 이 템플릿 업로더는 고른 파일을 입력 이벤트 모양(`{target: {value}}`)으로 넘긴다
              const picked = (pending as { target?: { value?: unknown[] } })?.target?.value;

              if (picked && picked.length > 0) {
                onDirty?.();
              }
            }}
            onUploadComplete={(uploaded) => {
              // 1개짜리 칸은 교체, 여러 개 칸은 이어 붙인다 — 빠진 첨부는 저장 때 서버가 파기한다
              const previous = Array.isArray(latestRef.current?.[path.split('.')[0]]?.[field.key]) ? latestRef.current[path.split('.')[0]][field.key] : files;
              change(maxFiles === 1 ? uploaded : [...previous, ...uploaded].slice(-maxFiles));
            }}
            onUploadError={(message) => onUploadError?.(message)}
            onRemove={(removed) => change(files.filter((item) => (item.hash ?? item.id) !== removed && item.id !== removed))}
          />
          </Div>
        );
      }

      case 'list':
        return renderList(field, current, change, path);

      case 'menu':
        return (
          <TemplateMenuField
            value={Array.isArray(current) ? current : []}
            onChange={(next) => change(next)}
            path={path}
            label={field.label}
            maxItems={field.max_items}
            maxDepth={field.max_depth}
            autoItems={field.auto_items ?? []}
            roleOptions={Array.isArray(roleOptions) ? roleOptions : []}
            errors={errors}
            disabled={disabled}
          />
        );

      case 'url':
      case 'link':
      case 'email':
      case 'string':
      default:
        return (
          <Input
            type={field.type === 'email' ? 'email' : field.type === 'url' ? 'url' : 'text'}
            className="input"
            value={current ?? ''}
            maxLength={field.max_length}
            placeholder={field.placeholder ?? undefined}
            autoComplete={field.sensitive ? 'off' : undefined}
            onChange={(e) => change(e.target.value)}
            {...common}
          />
        );
    }
  };

  /**
   * 목록 칸 — 행마다 행 칸들, 위·아래·삭제 단추, 끝에 추가 단추(최대 개수까지).
   */
  const renderList = (field: TemplateSettingField, current: any, change: (next: unknown) => void, path: string): React.ReactNode => {
    const rows: Record<string, any>[] = Array.isArray(current) ? current : [];
    const maxItems = field.max_items ?? 20;
    const itemFields = field.item_fields ?? [];

    const updateRow = (index: number, key: string, next: unknown) => change(rows.map((row, i) => (i === index ? { ...row, [key]: next } : row)));
    const move = (index: number, delta: number) => {
      const target = index + delta;

      if (target < 0 || target >= rows.length) {
        return;
      }

      const reordered = [...rows];
      [reordered[index], reordered[target]] = [reordered[target], reordered[index]];
      change(reordered);
    };

    return (
      <Div className="space-y-3">
        {rows.map((row, index) => (
          <Div key={index} className="rounded-lg border border-gray-200 dark:border-gray-700 p-3 space-y-3" data-ref="template_settings_list_row">
            <Div className="flex items-center justify-between">
              <Span className="text-sm font-medium text-gray-700 dark:text-gray-300">{t('settings.screen.fields.row_title', `#${index + 1}`, { number: index + 1 })}</Span>
              {!disabled && (
                <Div className="flex items-center gap-1">
                  <Button type="button" className="btn btn-ghost btn-sm" aria-label={t('settings.screen.fields.move_up', 'Move up')} disabled={index === 0} onClick={() => move(index, -1)}>
                    <Icon name="arrow-up" />
                  </Button>
                  <Button type="button" className="btn btn-ghost btn-sm" aria-label={t('settings.screen.fields.move_down', 'Move down')} disabled={index === rows.length - 1} onClick={() => move(index, 1)}>
                    <Icon name="arrow-down" />
                  </Button>
                  <Button type="button" className="btn btn-ghost btn-sm" aria-label={t('settings.screen.fields.remove_item', 'Remove')} onClick={() => change(rows.filter((_, i) => i !== index))}>
                    <Icon name="trash" />
                  </Button>
                </Div>
              )}
            </Div>
            <Div className={`grid ${twoColumns ? 'grid-cols-2' : 'grid-cols-1'} gap-3`}>
              {itemFields.map((itemField) => {
                const itemPath = `${path}.${index}.${itemField.key}`;
                const itemError = errorAt(errors, itemPath);

                return (
                  <Div key={itemField.key} className={itemField.type === 'multilingual' && twoColumns ? 'col-span-2' : ''}>
                    <Label className="form-label">{itemField.label}{itemField.required ? ' *' : ''}</Label>
                    {renderControl(itemField, row?.[itemField.key], (next) => updateRow(index, itemField.key, next), itemPath)}
                    {itemError && itemField.type !== 'multilingual' && <Span className="form-error">{itemError}</Span>}
                  </Div>
                );
              })}
            </Div>
          </Div>
        ))}
        {!disabled && (
          <Button
            type="button"
            className="btn btn-secondary btn-sm flex-center gap-1.5"
            disabled={rows.length >= maxItems}
            onClick={() => change([...rows, Object.fromEntries(itemFields.map((itemField) => [itemField.key, itemField.type === 'boolean' ? false : itemField.type === 'multilingual' ? {} : '']))])}
          >
            <Icon name="plus" />
            <Span>{t('settings.screen.fields.add_item', 'Add', { current: rows.length, max: maxItems })}</Span>
          </Button>
        )}
        {rows.length >= maxItems && <P className="form-hint">{t('settings.screen.fields.max_items_reached', `Up to ${maxItems}`, { max: maxItems })}</P>}
      </Div>
    );
  };

  return (
    <Div id={id} className="space-y-6" data-ref="template_settings_fields" {...(editorAttrs ?? {})}>
      {sections.map((section) => {
        const sectionValue = (value ?? {})[section.key] ?? {};

        return (
          <Div key={section.key} id={`template_settings_section_${section.key}`} className="admin-card" data-ref={`template_settings_section_${section.key}`}>
            <H3 className="card-title">{section.label}</H3>
            {section.hint && <P className="text-tertiary mb-4">{section.hint}</P>}
            <Div className="space-y-5">
              {section.fields.filter((field) => isVisible(field, sectionValue)).map((field) => {
                const path = `${section.key}.${field.key}`;
                // 메뉴 칸의 항목 오류(`{칸}.{i}…`)는 편집기가 그 항목에 단다 — 여기는 칸 전체 오류만
                const error = field.type === 'menu' ? errorAt(errors, path) : (errorAt(errors, path) ?? errorAt(errors, `${path}.0`));

                return (
                  <Div key={field.key} id={`template_settings_field_${section.key}_${field.key}`} data-ref={`template_settings_field_${section.key}_${field.key}`}>
                    {field.type !== 'boolean' && <Label className="form-label">{field.label}{field.required ? ' *' : ''}</Label>}
                    {field.type === 'boolean' ? (
                      <Div className="flex items-center justify-between gap-4">
                        <Span className="form-label mb-0">{field.label}</Span>
                        {renderControl(field, sectionValue[field.key], (next) => setField(section.key, field.key, next), path)}
                      </Div>
                    ) : (
                      renderControl(field, sectionValue[field.key], (next) => setField(section.key, field.key, next), path)
                    )}
                    {field.hint && <P className="form-hint mt-1">{field.hint}</P>}
                    {error && field.type !== 'multilingual' && <Span className="form-error">{error}</Span>}
                  </Div>
                );
              })}
            </Div>
          </Div>
        );
      })}
    </Div>
  );
};

export default TemplateSettingsFields;
