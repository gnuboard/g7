import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Button } from '../basic/Button';
import { Input } from '../basic/Input';
import { Textarea } from '../basic/Textarea';
import { FileInput } from '../basic/FileInput';
import { P } from '../basic/P';
import { IconSelect, defaultIconOptions } from './IconSelect';
import type { IconOption } from './IconSelect';
import { MenuIcon, MENU_ICON_CLASS_PATTERN } from './MenuIcon';
import type { MenuIconSpecData } from './MenuIcon';
import { extractErrorMessage } from './FileUploader/utils';
import { translate } from '../../utils/translate';
import type { EditorAttrs } from '../../types';

/** 편집 탭 */
export type MenuIconFieldTab = 'pick' | 'input' | 'upload' | 'svg';

/** 탭 순서 */
const TABS: MenuIconFieldTab[] = ['pick', 'input', 'upload', 'svg'];

/** 문구 키 접두 */
const KEY = 'admin.menus.icon_field';

/** 직접 입력으로 이미지 경로를 칠 때 미리보기를 바꾸기 전 기다리는 시간(ms) */
const PREVIEW_TYPING_DELAY_MS = 400;

/**
 * 값의 비교 키를 만듭니다.
 *
 * @param value 값
 * @returns JSON 문자열 (없으면 "null")
 */
const valueKeyOf = (value: MenuIconSpecData | null | undefined): string => JSON.stringify(value ?? null);

/**
 * 문구를 번역합니다 (못 찾으면 키 그대로).
 *
 * @param key icon_field 아래 키
 * @param params 치환 값
 * @returns 번역 문구
 */
const t = (key: string, params?: Record<string, string | number>): string =>
  translate(`${KEY}.${key}`, params) ?? `${KEY}.${key}`;

export interface MenuIconFieldProps {
  /** 현재 값 — 구조형 아이콘(`icon_spec`) 또는 null */
  value?: MenuIconSpecData | null;
  /** 값 변경 — 엔진 폼 바인딩이 읽는 가짜 이벤트(`{ target: { name, value } }`)로 내보낸다 */
  onChange?: (event: { target: { name?: string; value: MenuIconSpecData | null } }) => void;
  /** 폼 필드 이름 (기본 icon_spec) */
  name?: string;
  /** 「선택」 탭 아이콘 목록 (기본 20개) */
  options?: IconOption[];
  /** 「선택」 탭 플레이스홀더 */
  placeholder?: string;
  /** 「선택」 탭 검색 플레이스홀더 */
  searchPlaceholder?: string;
  /** 「선택」 탭 검색 결과 없음 문구 */
  noResultsText?: string;
  /** 업로드 API 주소 */
  uploadEndpoint?: string;
  /** 비활성 */
  disabled?: boolean;
  /** 서버 검증 오류 문구 (필드 아래 표시) */
  error?: string;
  /** 사용자 정의 클래스 */
  className?: string;
  /** DOM id 속성 */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용) */
  editorAttrs?: EditorAttrs;
}

/**
 * 값에 맞는 첫 탭을 고릅니다.
 *
 * @param value 현재 값
 * @param options 선택 탭 목록
 * @returns 탭
 */
export function initialTab(value: MenuIconSpecData | null | undefined, options: IconOption[] = defaultIconOptions): MenuIconFieldTab {
  if (!value) {
    return 'pick';
  }

  if (value.type === 'svg') {
    return 'svg';
  }

  if (value.type === 'image') {
    return value.source === 'site' ? 'input' : 'upload';
  }

  const glyph = String(value.value ?? '').split(/\s+/).find((token) => token.startsWith('fa-') && !['fa-solid', 'fa-regular', 'fa-light', 'fa-duotone', 'fa-brands'].includes(token));
  const inOptions = glyph !== undefined && options.some((opt) => `fa-${opt.faIcon}` === glyph);

  return inOptions ? 'pick' : 'input';
}

/**
 * 직접 입력 문자열을 구조형 값으로 해석합니다.
 *
 * `/` 로 시작하면 사이트 이미지 경로, `http:`·`https:`·`//`·`data:`·`javascript:` 는 거부(오류 키),
 * 그 밖은 FA 클래스.
 *
 * @param raw 입력 문자열
 * @returns 해석 결과 (값 또는 오류 키)
 */
export function parseDirectInput(raw: string): { spec: MenuIconSpecData | null; errorKey: string | null } {
  const text = raw.trim();

  if (text === '') {
    return { spec: null, errorKey: null };
  }

  // `//`(프로토콜 상대) 와 `scheme:`(http:·https:·data:·javascript: 등) — FA 클래스에는 `:` 가 없다
  if (/^\/\//.test(text) || /^[a-z][a-z0-9+.-]*:/i.test(text)) {
    return { spec: null, errorKey: 'input_external_blocked' };
  }

  if (text.startsWith('/')) {
    return { spec: { type: 'image', source: 'site', path: text, url: text }, errorKey: null };
  }

  return { spec: { type: 'class', value: text.replace(/\s+/g, ' ') }, errorKey: null };
}

/**
 * 값의 유형 라벨을 만듭니다.
 *
 * @param value 현재 값
 * @returns 라벨
 */
function typeLabel(value: MenuIconSpecData | null | undefined): string {
  if (!value) {
    return t('none');
  }

  if (value.type === 'svg') {
    return t('type_svg');
  }

  if (value.type === 'image') {
    if (value.source === 'attachment') {
      return t('uploaded_image');
    }

    if (value.source === 'package') {
      return `${t('package_image')} · ${value.path ?? ''}`;
    }

    return value.path ?? '';
  }

  return value.value ?? '';
}

/**
 * 관리자 메뉴 아이콘 입력 필드.
 *
 * 미리보기 + 유형 라벨 + 탭 4개(선택·직접 입력·업로드·SVG 코드). 확장이 선언한 이미지·SVG 아이콘도
 * 미리보기로 보여 「아이콘 선택」으로 비어 보이지 않는다.
 */
export const MenuIconField: React.FC<MenuIconFieldProps> = ({
  value = null,
  onChange,
  name = 'icon_spec',
  options = defaultIconOptions,
  placeholder,
  searchPlaceholder,
  noResultsText,
  uploadEndpoint = '/api/admin/menus/icon-upload',
  disabled = false,
  error,
  className = '',
  id,
  editorAttrs,
}) => {
  const [tab, setTab] = useState<MenuIconFieldTab>(() => initialTab(value, options));
  const [directText, setDirectText] = useState<string>(() =>
    value?.type === 'class' ? value.value ?? '' : value?.type === 'image' && value.source === 'site' ? value.path ?? '' : '',
  );
  const [directError, setDirectError] = useState<string | null>(null);
  const [svgText, setSvgText] = useState<string>(() => (value?.type === 'svg' ? value.value ?? '' : ''));
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);

  // 이 필드가 마지막으로 내보낸 값 — 폼이 그 값을 되돌려 줄 때(메아리)는 운영자가 친 글자를 건드리지 않는다
  const lastEmittedKeyRef = useRef<string | null>(null);
  // 업로드 회차 — 업로드 중에 값이 바뀌면(지우기·선택·입력·다른 메뉴) 늦게 온 업로드 결과를 버린다
  const uploadSeqRef = useRef(0);

  // 직접 입력·SVG 칸과 오류 문구를 주어진 값에 맞춘다 — 새 값에 해당하지 않는 칸은 비운다
  const syncDirect = useCallback((next: MenuIconSpecData | null | undefined) => {
    setDirectText(
      next?.type === 'class' ? next.value ?? '' : next?.type === 'image' && next.source === 'site' ? next.path ?? '' : '',
    );
    setDirectError(null);
  }, []);
  const syncSvg = useCallback((next: MenuIconSpecData | null | undefined) => {
    setSvgText(next?.type === 'svg' ? next.value ?? '' : '');
  }, []);
  const syncFields = useCallback((next: MenuIconSpecData | null | undefined) => {
    syncDirect(next);
    syncSvg(next);
  }, [syncDirect, syncSvg]);

  // 업로드 동안 전역 `menuIconUploading` 을 켠다 — 레이아웃의 저장 단추가 이 값으로 잠긴다(끝나기 전 저장하면 올린
  // 이미지가 조용히 버려졌다). 필드가 사라지면(취소·다른 화면) 끄고, 늦게 온 결과는 회차로 버린다
  const uploadingRef = useRef(false);
  const setUploadingFlag = useCallback((on: boolean) => {
    uploadingRef.current = on;
    (window as any).G7Core?.state?.update?.(() => ({ menuIconUploading: on }));
  }, []);
  useEffect(
    () => () => {
      uploadSeqRef.current += 1;
      if (uploadingRef.current) {
        setUploadingFlag(false);
      }
    },
    [setUploadingFlag],
  );

  // 미리보기 값 — 직접 입력으로 사이트 이미지 경로를 치는 동안은 입력이 멈춘 뒤에만 바꾼다(글자마다 이미지 요청 방지)
  const [previewValue, setPreviewValue] = useState<MenuIconSpecData | null | undefined>(value);
  const typedImageRef = useRef(false);
  useEffect(() => {
    if (!typedImageRef.current) {
      setPreviewValue(value);

      return undefined;
    }

    typedImageRef.current = false;
    const timer = setTimeout(() => setPreviewValue(value), PREVIEW_TYPING_DELAY_MS);

    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [valueKeyOf(value)]);

  // 다른 메뉴로 바뀌어 값이 바깥에서 갈리면 입력칸을 그 값으로 다시 맞춘다
  const valueKey = useMemo(() => valueKeyOf(value), [value]);
  useEffect(() => {
    if (valueKey === lastEmittedKeyRef.current) {
      return;
    }

    lastEmittedKeyRef.current = null;
    uploadSeqRef.current += 1;
    setUploadError(null);
    syncFields(value);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [valueKey]);

  // 업로드가 끝날 때마다 파일 칸을 새로 그린다 — 고른 파일을 쥐고 있으면 같은 파일을 다시 골라도 change 가 나지 않는다
  const [fileInputKey, setFileInputKey] = useState(0);

  // from: 직접 입력·SVG 칸에서 친 글자로 내보낸 값은 메아리로 건너뛰고 **친 칸**은 그대로 둔다(친 글자 보존) — 다른
  // 칸은 새 값에 맞춘다(한 칸에서 친 값이 다른 칸에 막힌 주소·빨간 문구·옛 SVG 를 남기지 않게). 선택·업로드·지우기로
  // 바꾼 값은 두 칸·오류를 여기서 바로 새 값에 맞춘다 — 메아리에 맡기면 현재와 같은 값일 때 칸 맞춤이 돌지 않아
  // 다른 탭 칸에 바꾸기 전 글자가 남는다. 어느 값이든 내보내면 업로드 오류는 지우고 진행 중인 업로드 결과는
  // 버린다(업로드 결과 자신은 회차 확인 뒤에 내보낸다)
  const emit = useCallback(
    (next: MenuIconSpecData | null, from: 'direct' | 'svg' | null = null) => {
      lastEmittedKeyRef.current = from !== null ? valueKeyOf(next) : null;
      uploadSeqRef.current += 1;
      setUploadError(null);
      if (from === null) {
        syncFields(next);
      } else if (from === 'direct') {
        syncSvg(next);
      } else {
        syncDirect(next);
      }
      onChange?.({ target: { name, value: next } });
    },
    [onChange, name, syncFields, syncSvg, syncDirect],
  );

  const handlePick = (classValue: string) => {
    emit({ type: 'class', value: classValue });
  };

  // 직접 입력을 시작하기 전 값 — 한 글자씩 치는 동안 막히기 전 중간 글자(`https`·`https:/`)가 이미
  // 내보내지므로, 입력이 막히면 이 값으로 되돌린다
  const directBaseRef = useRef<MenuIconSpecData | null>(value);
  // 입력 전 값에서 벗어난 값을 내보냈는지 — 아무것도 내보내지 않았으면 막혀도 내보낼 것이 없다
  const directDirtyRef = useRef(false);
  const captureDirectBase = () => {
    directBaseRef.current = value;
    directDirtyRef.current = false;
  };

  const handleDirectInput = (raw: string) => {
    setDirectText(raw);
    const parsed = parseDirectInput(raw);
    setDirectError(parsed.errorKey);

    if (parsed.errorKey === null) {
      directDirtyRef.current = true;
      typedImageRef.current = parsed.spec?.type === 'image';
      emit(parsed.spec, 'direct');
    } else if (directDirtyRef.current) {
      directDirtyRef.current = false;
      emit(directBaseRef.current, 'direct');
    }
  };

  const handleSvgInput = (raw: string) => {
    setSvgText(raw);
    emit(raw.trim() === '' ? null : { type: 'svg', value: raw }, 'svg');
  };

  const handleUpload = async (file: File | null) => {
    if (!file || uploading) {
      return;
    }

    const api = (window as any).G7Core?.api;

    if (!api?.post) {
      setUploadError(t('upload_failed'));

      return;
    }

    setUploading(true);
    setUploadingFlag(true);
    setUploadError(null);
    const seq = ++uploadSeqRef.current;

    try {
      const formData = new FormData();
      formData.append('file', file);
      const response = await api.post(uploadEndpoint, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });

      // 기다리는 동안 값이 바뀌었으면(지우기·다른 선택·다른 메뉴) 이 결과는 버린다
      if (seq !== uploadSeqRef.current) {
        return;
      }

      const body = response?.data ?? response;
      const spec: MenuIconSpecData | undefined = body?.data?.icon_spec ?? body?.icon_spec;

      if (!spec || typeof spec.type !== 'string') {
        setUploadError(t('upload_failed'));

        return;
      }

      emit(spec);
    } catch (err: unknown) {
      if (seq === uploadSeqRef.current) {
        setUploadError(extractErrorMessage(err) || t('upload_failed'));
      }
    } finally {
      // 필드가 이미 사라졌으면 정리 단계가 표시를 껐다 — 새로 열린 폼의 업로드 표시를 건드리지 않는다
      if (uploadingRef.current) {
        setUploadingFlag(false);
      }
      setUploading(false);
      setFileInputKey((k) => k + 1);
    }
  };

  const directHintKey = useMemo(() => {
    const parsed = parseDirectInput(directText);
    if (parsed.spec?.type === 'image') return 'input_detected_image';
    if (parsed.spec?.type === 'class') {
      return MENU_ICON_CLASS_PATTERN.test(parsed.spec.value ?? '') ? 'input_detected_class' : null;
    }
    return null;
  }, [directText]);

  const pickValue = value?.type === 'class' ? value.value ?? '' : '';
  const svgLooksWrong = svgText.trim() !== '' && !/^\s*(<\?xml[^>]*>\s*)?<svg[\s>]/i.test(svgText);

  return (
    <Div id={id} className={`menu-icon-field space-y-2 ${className}`.trim()} data-name={name} {...editorAttrs}>
      {/* 미리보기 + 유형 라벨 + 지우기 */}
      <Div className="flex items-center gap-3">
        <Div
          className="w-12 h-12 shrink-0 flex items-center justify-center rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-200 text-[2rem]"
          aria-label={t('preview')}
          role="img"
          data-ref="menu_icon_preview"
        >
          <MenuIcon spec={previewValue} fallbackIcon={previewValue ? 'fas fa-circle' : undefined} className="w-8 h-8" />
        </Div>
        <Span className="flex-1 min-w-0 truncate text-sm text-gray-600 dark:text-gray-300" data-ref="menu_icon_type_label">
          {typeLabel(value)}
        </Span>
        {value && !disabled && (
          <Button
            type="button"
            onClick={() => {
              setFileInputKey((k) => k + 1);
              emit(null);
            }}
            className="text-xs px-2 py-1 rounded border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700"
          >
            {t('clear')}
          </Button>
        )}
      </Div>

      {/* 탭 */}
      <Div role="tablist" className="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-700">
        {TABS.map((key) => (
          <Button
            key={key}
            type="button"
            role="tab"
            aria-selected={tab === key}
            disabled={disabled}
            onClick={() => {
              if (key === 'input') {
                captureDirectBase();
              }
              setTab(key);
            }}
            className={`shrink-0 px-3 py-1.5 text-sm border-b-2 -mb-px ${
              tab === key
                ? 'border-blue-600 text-blue-700 dark:border-blue-400 dark:text-blue-300'
                : 'border-transparent text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white'
            }`}
          >
            {t(`tab_${key}`)}
          </Button>
        ))}
      </Div>

      <Div role="tabpanel">
        {tab === 'pick' && (
          <IconSelect
            value={pickValue}
            onChange={handlePick}
            options={options}
            placeholder={placeholder}
            searchPlaceholder={searchPlaceholder}
            noResultsText={noResultsText}
            disabled={disabled}
          />
        )}

        {tab === 'input' && (
          <Div className="space-y-1">
            <Input
              type="text"
              value={directText}
              onFocus={captureDirectBase}
              onChange={(e: React.ChangeEvent<HTMLInputElement>) => handleDirectInput(e.target.value)}
              placeholder={t('input_placeholder')}
              aria-label={t('input_label')}
              disabled={disabled}
              className="w-full"
            />
            <P className="text-xs text-muted">
              {directHintKey ? t(directHintKey) : t('input_hint')}
            </P>
            {directError && (
              <P className="form-error" role="alert">
                {t(directError)}
              </P>
            )}
          </Div>
        )}

        {tab === 'upload' && (
          <Div className="space-y-1">
            <FileInput
              key={fileInputKey}
              accept=".png,.webp,.jpg,.jpeg,.gif,.svg"
              onChange={(event) => handleUpload(event.target.value)}
              buttonText={uploading ? t('uploading') : t('upload_button')}
              disabled={disabled || uploading}
              aria-label={t('upload_button')}
            />
            <P className="text-xs text-muted">{t('upload_hint')}</P>
            {uploadError && (
              <P className="form-error" role="alert">
                {uploadError}
              </P>
            )}
          </Div>
        )}

        {tab === 'svg' && (
          <Div className="space-y-1">
            <Textarea
              value={svgText}
              onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => handleSvgInput(e.target.value)}
              placeholder={t('svg_placeholder')}
              aria-label={t('svg_label')}
              rows={4}
              disabled={disabled}
              className="w-full font-mono text-xs"
            />
            <P className="text-xs text-muted">{t('svg_hint')}</P>
            {svgLooksWrong && (
              <P className="text-xs text-amber-600 dark:text-amber-400">{t('svg_not_svg')}</P>
            )}
          </Div>
        )}
      </Div>

      {error && (
        <P className="form-error" role="alert" data-ref="menu_icon_error">
          {error}
        </P>
      )}
    </Div>
  );
};

export default MenuIconField;
