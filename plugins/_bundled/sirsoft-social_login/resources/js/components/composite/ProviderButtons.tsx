import React from 'react';
import { ProviderSymbol, type ProviderBrand } from '../../support/ProviderSymbol';

/** 버튼이 하는 일 — 로그인·가입은 시작 주소로 이동, 연결·재확인은 로그인 회원의 API 를 거친다. */
export const PROVIDER_BUTTON_INTENTS = ['login', 'register', 'link', 'reauth'] as const;

/** 버튼이 하는 일 */
export type ProviderButtonsIntent = (typeof PROVIDER_BUTTON_INTENTS)[number];

/** 공개 제공자 목록(`GET /api/plugins/sirsoft-social_login/providers`)의 한 항목 */
export interface ProviderButtonItem {
  /** 제공자 키 */
  key?: string;

  /** 연결 목록 응답의 제공자 키 */
  provider_key?: string;

  /** 표시 이름 */
  label?: string;

  /** 버튼 모양 계열 */
  brand?: ProviderBrand;

  /** 커스텀 제공자 버튼 색 */
  style?: { bg?: string; fg?: string; border?: string } | null;

  /** 로그인 시작 경로 (같은 출처) */
  start_url?: string;
}

export interface ProviderButtonsProps {
  /** 제공자 목록 */
  providers?: ProviderButtonItem[];

  /** 버튼이 하는 일 */
  intent?: ProviderButtonsIntent;

  /** 로그인·가입 뒤 돌아갈 같은 출처 경로 */
  redirect?: string;

  /** 버튼 크기 */
  size?: 'md' | 'sm';

  /** 버튼 비활성 */
  disabled?: boolean;

  /** 사용자 정의 클래스 */
  className?: string;

  /** DOM id */
  id?: string;

  /** 레이아웃 편집기가 주입하는 표식(편집 모드 전용) */
  editorAttrs?: Record<string, unknown>;
}

/** 플러그인 식별자 — 문구 키와 핸들러 이름 앞에 붙는다. */
const PLUGIN = 'sirsoft-social_login';

/** 내장 버튼 모양 계열 */
const BRANDS: ProviderBrand[] = ['kakao', 'naver', 'google', 'facebook'];

/**
 * 플러그인 문구를 꺼냅니다 — 번역기가 없거나 키를 못 찾으면 기본값.
 *
 * @param key 플러그인 안의 문구 키
 * @param fallback 기본값
 * @param params 치환 값
 * @returns 문구
 */
function t(key: string, fallback: string, params?: Record<string, string>): string {
  const core = (window as { G7Core?: { t?: (k: string, p?: Record<string, string>) => string } }).G7Core;
  const full = `${PLUGIN}.${key}`;
  const translated = core?.t?.(full, params);

  if (translated && translated !== full && !translated.startsWith(`${PLUGIN}.`)) {
    return translated;
  }

  return Object.entries(params ?? {}).reduce((text, [name, value]) => text.replace(`{{${name}}}`, value), fallback);
}

/**
 * 버튼 문구를 고릅니다 — 내장 제공자는 각 사 버튼 가이드의 문구, 그 밖은 제공자 이름을 넣은 문구.
 * 카카오 모양 버튼은 카카오 디자인 가이드가 문구를 정해 두었으므로 연결·재확인 버튼도 로그인 문구를 쓴다.
 *
 * @param intent 버튼이 하는 일
 * @param brand 모양 계열
 * @param label 제공자 이름
 * @returns 문구
 */
function buttonText(intent: ProviderButtonsIntent, brand: ProviderBrand, label: string): string {
  const params = { provider: label };
  const builtin = BRANDS.includes(brand) ? brand : 'custom';

  if (brand === 'kakao' && (intent === 'link' || intent === 'reauth')) {
    return t('buttons.login_with.kakao', '카카오 로그인', params);
  }

  switch (intent) {
    case 'register':
      return t(`buttons.register_with.${builtin}`, '{{provider}}(으)로 가입', params);
    case 'link':
      return t('buttons.link_with', '{{provider}} 연결', params);
    case 'reauth':
      return t('buttons.reauth_with', '{{provider}}(으)로 다시 확인', params);
    default:
      return t(`buttons.login_with.${builtin}`, '{{provider}}(으)로 로그인', params);
  }
}

/**
 * 시작 주소에 복귀 주소를 붙입니다 (같은 출처 상대 경로만 — 서버가 다시 검사한다).
 *
 * @param startUrl 시작 경로
 * @param redirect 복귀 경로
 * @returns 이동 주소
 */
function startHref(startUrl: string, redirect?: string): string {
  if (!redirect || !redirect.startsWith('/') || redirect.startsWith('//')) {
    return startUrl;
  }

  return `${startUrl}${startUrl.includes('?') ? '&' : '?'}redirect=${encodeURIComponent(redirect)}`;
}

/**
 * 제공자 버튼 목록.
 *
 * 로그인·가입 버튼은 `<a href>` 다(새 탭·키보드·스크린리더가 링크로 다룬다) — 이 사이트의 시작 주소로 가고, 제공자
 * 화면으로 보내는 일은 서버가 한다. 연결·재확인 버튼은 로그인 회원의 API 를 거쳐야 하므로 플러그인 핸들러를 부른다.
 * 모양(색·심볼·문구)은 `brand` 로 고르고, 커스텀 제공자는 운영자가 정한 색과 이름 첫 글자를 쓴다.
 *
 * ```html
 * <sirsoft-social_login.provider-buttons data-type="composite" providers="{{socialProviders?.data ?? []}}" intent="login" redirect="{{query.redirect ?? '/'}}"/>
 * ```
 */
export const ProviderButtons: React.FC<ProviderButtonsProps> = ({
  providers = [],
  intent = 'login',
  redirect,
  size = 'md',
  disabled = false,
  className = '',
  id,
  editorAttrs,
}) => {
  const items = Array.isArray(providers) ? providers : [];

  if (items.length === 0) {
    return null;
  }

  const dispatch = (handler: string, params: Record<string, unknown>): void => {
    const core = (window as { G7Core?: { dispatch?: (a: unknown) => unknown } }).G7Core;
    void core?.dispatch?.({ handler: `${PLUGIN}.${handler}`, params });
  };

  return (
    <div id={id} className={`sl-buttons sl-buttons--${size} ${className}`.trim()} {...editorAttrs}>
      {items.map((item) => {
        const key = String(item.key ?? item.provider_key ?? '');
        const brand: ProviderBrand = BRANDS.includes(item.brand as ProviderBrand) ? (item.brand as ProviderBrand) : 'custom';
        const label = String(item.label ?? key);
        const text = buttonText(intent, brand, label);
        const style: React.CSSProperties | undefined =
          brand === 'custom' && item.style
            ? { backgroundColor: item.style.bg, color: item.style.fg, borderColor: item.style.border }
            : undefined;
        const common = {
          className: `sl-button sl-button--${brand}${disabled ? ' sl-button--disabled' : ''}`,
          style,
          'data-social-provider': key,
          'aria-label': text,
        };
        const content = (
          <>
            <ProviderSymbol brand={brand} label={label} />
            <span className="sl-button__text">{text}</span>
          </>
        );

        if (intent === 'link' || intent === 'reauth') {
          return (
            <button
              key={key}
              type="button"
              disabled={disabled}
              onClick={() =>
                intent === 'link'
                  ? dispatch('startLink', { provider: key })
                  : dispatch('startReauth', { provider: key, return: typeof window !== 'undefined' ? window.location.pathname : '/' })
              }
              {...common}
            >
              {content}
            </button>
          );
        }

        return (
          <a
            key={key}
            href={disabled ? undefined : startHref(String(item.start_url ?? ''), redirect)}
            aria-disabled={disabled || undefined}
            {...common}
          >
            {content}
          </a>
        );
      })}
    </div>
  );
};

ProviderButtons.displayName = 'ProviderButtons';
