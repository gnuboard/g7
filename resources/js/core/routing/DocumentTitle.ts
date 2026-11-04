// e2e:allow 편집기 「검색 제목」 후보(engine-v1.87.1)를 E2E 로 밟으려면 저장된 레이아웃(DB)을 고쳐야 한다 — Chrome MCP 실측(로컬 상품 목록에 값을 넣고 봇 <title>·SPA 이동·새로고침 탭 제목 확인 뒤 sha1 원복)과 후보 순서 단위 시험(DocumentTitle.parity·TemplateApp.documentTitle)으로 잠근다.
/**
 * 브라우저 탭 제목(document.title)
 *
 * 사람 방문자의 탭 제목을 검색엔진(봇) `<title>` 과 **같은 규칙**으로 정한다. 규칙의 서버 쪽 원본은
 * `app/Seo/SeoTitleRule.php`(+ `SeoMetaResolver`·`SeoRenderer::resolveSeoContext`)이고, 두 구현은
 * `tests/fixtures/seo/title-rule-cases.json` 으로 같은 답을 내는지 잠근다 — 한쪽만 고치지 않는다.
 *
 * 제목 후보 순서: 리소스 `meta_title` → 페이지 `seo_meta.title` → 레이아웃 편집기 제목(`meta.seo.title`) → 확장 제목 틀(`_seo.{page_type}.title`) →
 * `meta.seo.og.title` → 라우트 `meta.title`. 사이트 이름만 되풀이하는 후보는 건너뛰고, 사이트 이름은 코어 접미사
 * (`seo.meta_title_suffix`, 비면 ` - {site_name}`)로만 붙인다. 사이트 첫 화면(`/`)은 사이트 이름만 쓴다.
 *
 * 계산은 화면이 이미 가진 데이터로만 한다(이동마다 서버에 묻지 않는다). 서버가 넘기는 값은 부팅 때
 * `window.G7Config.seoTitle`(SeoTitleRuleExporter) 하나다.
 *
 * @since engine-v1.86.0
 */

import { DataBindingEngine } from '../template-engine/DataBindingEngine';
import { pickLocalized } from '../support/localeTag';

/** 확장 하나의 제목 계산 값 (SeoTitleRuleExporter) */
export interface SeoTitleExtensionPayload {
  /** `seoVariables()` 선언 — page_type(·`_common`) → 변수명 → 정의 */
  variables?: Record<string, Record<string, { source?: string; key?: string } | unknown> | unknown[]>;
  /** page_type → { title, description } 틀 원값 */
  templates?: Record<string, { title?: unknown; description?: unknown }>;
  /** `source: setting` 변수가 읽는 설정 원값 (키 → 값) */
  settings?: Record<string, unknown>;
}

/** 서버가 부팅 때 넘기는 제목 계산 값 (`G7Config.seoTitle`) */
export interface SeoTitlePayload {
  /** 코어 제목 접미사 원값 */
  suffix?: unknown;
  /** 사이트 이름 원값 (문자열 또는 다국어 객체) */
  site_name?: unknown;
  /** `source: core_setting` 변수·`$core_settings:` 가 읽는 코어 설정 원값 */
  core_settings?: Record<string, unknown>;
  /** `module:{id}`·`plugin:{id}` → 확장 제목 계산 값 */
  extensions?: Record<string, SeoTitleExtensionPayload>;
}

/** 제목 규칙 입력 (서버 `SeoTitleRule::resolve` 와 같은 키) */
export interface TitleRuleInput {
  /** 순서대로 시험할 후보 — 문자열·다국어 객체·(앞 후보가 비었을 때만 계산할) 함수 */
  tiers?: unknown[];
  site_name?: unknown;
  suffix?: unknown;
  is_home?: boolean;
  locale?: string | null;
}

/** 제목 규칙 결과 */
export interface TitleRuleResult {
  /** 고른 제목 */
  title: string;
  /** 제목 뒤에 붙일 접미사 */
  suffix: string;
  /** 탭에 쓸 전체 제목 */
  full: string;
}

/** 제목 계산에 쓰는 라우트 부분 */
export interface TitleRoute {
  path?: string;
  layout?: string;
  params?: Record<string, unknown>;
  meta?: { title?: unknown } | null;
}

const DEFAULT_SUFFIX = ' - {site_name}';
const SITE_NAME_SEPARATORS = [' - ', ' | ', ' · ', ' – ', ' — '];
const LEADING_SEPARATOR = /^[\s|·:/\u2013\u2014-]+/u;
const EMPTY_SENTINEL = '\u0000EMPTYVAR\u0000';

/**
 * 다국어 객체·스칼라를 글자로 바꾼다 (서버 `resolveLocalizedValue` 와 같은 규칙 — 체인에서 못 찾으면 빈 값).
 *
 * @param value 대상 값
 * @param locale 고를 언어
 * @returns 글자
 */
export function localizeTitleValue(value: unknown, locale?: string | null): string {
  if (typeof value === 'string') return value;
  if (value === null || value === undefined) return '';
  if (typeof value === 'boolean') return value ? '1' : '';
  if (typeof value === 'object') return pickLocalized(value, locale, false) ?? '';

  return String(value);
}

const NAMED_ENTITIES: Record<string, string> = {
  amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: '\u00a0', middot: '\u00b7', ndash: '\u2013', mdash: '\u2014', hellip: '\u2026',
};

/**
 * HTML 엔티티를 푼다 (숫자 엔티티 + 자주 쓰는 이름 엔티티).
 *
 * @param text 대상 글자
 * @returns 엔티티를 푼 글자
 */
function decodeEntities(text: string): string {
  return text.replace(/&(#x[0-9a-fA-F]+|#[0-9]+|[a-zA-Z]+);/g, (match, body: string) => {
    if (body[0] === '#') {
      const code = body[1] === 'x' || body[1] === 'X' ? parseInt(body.slice(2), 16) : parseInt(body.slice(1), 10);
      return Number.isFinite(code) && code > 0 && code <= 0x10ffff ? String.fromCodePoint(code) : match;
    }
    return NAMED_ENTITIES[body] ?? match;
  });
}

/**
 * 제목 후보를 글자로 정리한다 — 태그 제거 · 엔티티 해제 · 공백 한 칸 · 앞뒤 공백 제거.
 *
 * @param text 제목 후보
 * @returns 정리한 글자
 */
export function normalizeTitleText(text: string): string {
  if (text === '') return '';

  return decodeEntities(text.replace(/<[^>]*>/g, '')).replace(/\s+/gu, ' ').trim();
}

/**
 * 접미사 앞머리의 구분자·공백을 떼고 앞뒤 공백을 지운다.
 *
 * @param suffix 접미사
 * @returns 구분자를 뗀 글자
 */
export function stripLeadingSeparators(suffix: string): string {
  return suffix.replace(LEADING_SEPARATOR, '').trim();
}

/**
 * 코어 접미사 원값을 붙일 접미사로 만든다 (비면 ` - {site_name}`).
 *
 * @param rawSuffix 접미사 원값 (현재 언어 글자)
 * @param siteName 사이트 이름
 * @returns 붙일 접미사 (없으면 빈 문자열)
 */
export function assembleTitleSuffix(rawSuffix: string, siteName: string): string {
  const template = rawSuffix.trim() === '' ? DEFAULT_SUFFIX : rawSuffix;
  const assembled = template.split('{site_name}').join(siteName);

  return stripLeadingSeparators(assembled) === '' ? '' : assembled;
}

/**
 * 제목이 사이트 이름만 되풀이하는지 판정한다.
 *
 * @param title 정리한 제목 후보
 * @param siteName 사이트 이름
 * @param assembledSuffix assembleTitleSuffix 결과
 * @returns 사이트 이름만이면 true
 */
export function isSiteNameOnlyTitle(title: string, siteName: string, assembledSuffix: string): boolean {
  const normalized = title.trim();
  if (normalized === '') return false;
  const suffixName = stripLeadingSeparators(assembledSuffix);

  return (siteName !== '' && normalized === siteName) || (suffixName !== '' && normalized === suffixName);
}

/**
 * 제목 뒤에 붙일 접미사를 정한다 — 제목이 이미 접미사나 「구분자 + 사이트 이름」으로 끝나면 붙이지 않는다.
 *
 * @param title 고른 제목
 * @param assembledSuffix assembleTitleSuffix 결과
 * @param siteName 사이트 이름
 * @returns 제목 뒤에 이을 글자
 */
export function composeTitleSuffix(title: string, assembledSuffix: string, siteName: string): string {
  if (assembledSuffix.trim() === '') return '';
  if (title === '') return stripLeadingSeparators(assembledSuffix);

  const core = assembledSuffix.trim();
  if (core !== '' && title.endsWith(core)) return '';
  if (siteName !== '' && SITE_NAME_SEPARATORS.some((separator) => title.endsWith(separator + siteName))) return '';

  return /^\s/u.test(assembledSuffix) ? assembledSuffix : ' ' + assembledSuffix;
}

/**
 * 제목 규칙 — 서버 `SeoTitleRule::resolve` 의 짝.
 *
 * @param input 규칙 입력
 * @returns 제목·접미사·전체 제목
 */
export function resolveDocumentTitle(input: TitleRuleInput): TitleRuleResult {
  const locale = typeof input.locale === 'string' && input.locale !== '' ? input.locale : null;
  const siteName = localizeTitleValue(input.site_name ?? '', locale).trim();
  const assembled = assembleTitleSuffix(localizeTitleValue(input.suffix ?? '', locale), siteName);

  let title = '';
  if (!input.is_home) {
    for (let candidate of input.tiers ?? []) {
      if (typeof candidate === 'function') {
        candidate = (candidate as () => unknown)();
      }
      const text = normalizeTitleText(localizeTitleValue(candidate, locale));
      if (text === '' || isSiteNameOnlyTitle(text, siteName, assembled)) continue;
      title = text;
      break;
    }
  }

  const suffix = composeTitleSuffix(title, assembled, siteName);
  if (title === '' && suffix === '') {
    title = siteName;
  }

  return { title, suffix, full: title + suffix };
}

/**
 * SEO 틀의 `{var}` 자리표시자를 바꾼다 — 서버 `SubstitutesSeoVariables::substituteVars` 의 짝.
 *
 * (a) 옵셔널 그룹 `[ ... ]` 안 변수가 하나라도 비면 그룹을 지운다 (b) 빈 변수 옆 구분자(- – — · |)를 지운다.
 *
 * @param template 틀
 * @param vars 변수 값
 * @returns 바꾼 글자
 */
export function substituteSeoVars(template: string, vars: Record<string, unknown>): string {
  if (template === '') return '';

  let result = template.replace(/\[([^[\]]+)\]/g, (_match, group: string) => {
    for (const [, name] of group.matchAll(/\{(\w+)\}/g)) {
      const value = vars[name];
      if (value === undefined || value === null || value === '') return '';
    }
    return group;
  });

  result = result.replace(/\{(\w+)\}/g, (match, name: string) => {
    if (!Object.prototype.hasOwnProperty.call(vars, name)) return match;
    const raw = vars[name];
    const value = raw === null || raw === undefined ? '' : typeof raw === 'boolean' ? (raw ? '1' : '') : String(raw);

    return value === '' ? EMPTY_SENTINEL : value;
  });

  const sep = '[\\-\\u2013\\u2014\\u00B7|]';
  const sentinel = EMPTY_SENTINEL.replace(/[\u0000]/g, '\\u0000');
  result = result
    .replace(new RegExp(`\\s*${sep}\\s*${sentinel}`, 'gu'), '')
    .replace(new RegExp(`${sentinel}\\s*${sep}\\s*`, 'gu'), '')
    .split(EMPTY_SENTINEL).join('')
    .replace(/\s+/gu, ' ');

  return result.trim();
}

/** 제목 입력 계산에 필요한 도구 */
export interface TitleComputeDeps {
  /** 레이아웃 식(`{{…}}`·`$t:`)을 글자로 평가한다 */
  evaluate: (expression: string, context: Record<string, any>) => string;
  /** 현재 화면 언어 */
  locale: string | null;
  /** 서버 제목 계산 값 */
  payload: SeoTitlePayload;
  /** 관리자 화면이면 'admin' — 검색엔진 규칙 대신 레이아웃 화면 제목 → 라우트 제목만 본다 (@since engine-v1.88.0) */
  mode?: 'user' | 'admin';
}

/**
 * 점 경로로 객체 값을 읽는다.
 *
 * @param source 대상
 * @param path 점 경로
 * @returns 값 (없으면 undefined)
 */
function readPath(source: unknown, path: string): unknown {
  let current: any = source;
  for (const part of path.split('.')) {
    if (current === null || current === undefined || typeof current !== 'object') return undefined;
    current = current[part];
  }
  return current;
}

/**
 * 라우트 레이아웃 이름(`{확장}.{레이아웃}`)에서 소속 확장을 찾는다 — 서버 `TemplateRouteResolver::describeRouteLayout` 의 짝.
 *
 * @param routeLayout 라우트 layout 값
 * @param payload 제목 계산 값
 * @returns 모듈·플러그인 식별자
 */
function describeRouteExtension(routeLayout: unknown, payload: SeoTitlePayload): { module: string | null; plugin: string | null } {
  if (typeof routeLayout !== 'string' || !routeLayout.includes('.')) return { module: null, plugin: null };
  const id = routeLayout.slice(0, routeLayout.indexOf('.'));
  if (payload.extensions?.[`module:${id}`]) return { module: id, plugin: null };
  if (payload.extensions?.[`plugin:${id}`]) return { module: null, plugin: id };

  return { module: id, plugin: null };
}

/**
 * 제목 계산 값에서 확장 설정 원값을 읽는다 — 내보낸 값이 없으면 공개 설정(`_global.modules|plugins`)을 본다.
 *
 * @param type 'module' 또는 'plugin'
 * @param id 확장 식별자
 * @param key 설정 키
 * @param payload 제목 계산 값
 * @param context 데이터 컨텍스트
 * @returns 설정 원값
 */
function extensionSetting(type: 'module' | 'plugin', id: string, key: string, payload: SeoTitlePayload, context: Record<string, any>): unknown {
  const exported = payload.extensions?.[`${type}:${id}`]?.settings;
  if (exported && Object.prototype.hasOwnProperty.call(exported, key)) return exported[key];
  const exposed = readPath(context?._global?.[type === 'module' ? 'modules' : 'plugins']?.[id], key);

  return exposed ?? '';
}

/**
 * 제목 계산 값에서 코어 설정 원값을 읽는다 — 내보낸 값이 없으면 공개 설정(`_global.settings`)을 본다.
 *
 * @param key 설정 키
 * @param payload 제목 계산 값
 * @param context 데이터 컨텍스트
 * @returns 설정 원값
 */
function coreSetting(key: string, payload: SeoTitlePayload, context: Record<string, any>): unknown {
  const exported = payload.core_settings;
  if (exported && Object.prototype.hasOwnProperty.call(exported, key)) return exported[key];

  return readPath(context?._global?.settings, key) ?? '';
}

/**
 * `$module_settings:`·`$plugin_settings:` 뒤 나머지를 [식별자, 키] 로 나눈다 — 서버 `parseExtensionSettingsKey` 의 짝.
 *
 * @param rest 접두사 뒤 글자
 * @param contextId 라우트 소속 확장 식별자
 * @returns [식별자, 키]
 */
function parseExtensionSettingsKey(rest: string, contextId: string | null): [string | null, string] {
  const colon = rest.indexOf(':');
  if (colon >= 0) return [rest.slice(0, colon), rest.slice(colon + 1)];

  return [contextId, rest];
}

/**
 * 제목 후보 목록을 만든다 — 서버 SeoRenderer(데이터 컨텍스트·vars·_seo) + SeoMetaResolver(후보 순서)의 짝.
 *
 * SEO 선언이 없거나 꺼진 화면(`meta.seo` 없음·`enabled:false`)은 라우트 제목만 후보다. 확장 SEO 토글
 * (`toggle_setting`)은 보지 않는다 — 봇에게 SEO 화면을 줄지의 설정이지 탭 제목 설정이 아니다.
 *
 * @param layoutJson 화면 레이아웃 JSON (meta.seo·data_sources)
 * @param context 화면 데이터 컨텍스트 (데이터소스 id → 응답, route·query·_global·_local·_computed)
 * @param route 현재 라우트
 * @param deps 평가 도구
 * @returns 제목 규칙 입력
 */
export function computeTitleInput(
  layoutJson: any,
  context: Record<string, any>,
  route: TitleRoute | null,
  deps: TitleComputeDeps,
): TitleRuleInput {
  const { payload, locale } = deps;
  const ctx = context ?? {};
  const localize = (value: unknown): string => localizeTitleValue(value, locale);
  const safeEvaluate = (value: unknown, evalContext: Record<string, any>): string => {
    if (typeof value === 'string') {
      try {
        return deps.evaluate(value, evalContext);
      } catch {
        return '';
      }
    }
    return localize(value);
  };

  const routeTitle = route?.meta?.title;
  const routeTier = (): string =>
    routeTitle === undefined || routeTitle === null || routeTitle === '' ? '' : safeEvaluate(routeTitle, ctx);

  const base: TitleRuleInput = {
    site_name: payload.site_name ?? '',
    suffix: payload.suffix ?? '',
    is_home: route?.path === '/',
    locale,
  };

  // 관리자 화면 — 검색엔진 규칙(SEO 선언·확장 제목 틀)을 쓰지 않는다. 레이아웃이 선언한 화면 제목(`meta.title`) → 라우트 제목.
  // 사이트 첫 화면 규칙도 없다(관리자 홈도 「대시보드 - {사이트 이름} 관리자」). 접미사는 관리자 셸이 준 언어별 값이다.
  if (deps.mode === 'admin') {
    const layoutTitle = layoutJson?.meta?.title;
    const layoutTitleTier = (): string =>
      layoutTitle === undefined || layoutTitle === null || layoutTitle === '' ? '' : safeEvaluate(layoutTitle, ctx);

    return { ...base, is_home: false, tiers: [layoutTitleTier, routeTier] };
  }

  const seo = layoutJson?.meta?.seo;
  if (!seo || typeof seo !== 'object' || !seo.enabled) {
    return { ...base, tiers: [routeTier] };
  }

  // 데이터 컨텍스트 — 서버처럼 meta.seo.data_sources 에 든 소스만, 레이아웃 data_sources 순서로
  const seoIds: string[] = Array.isArray(seo.data_sources) ? seo.data_sources : [];
  const ordered: unknown[] = [];
  const seen = new Set<string>();
  for (const source of Array.isArray(layoutJson?.data_sources) ? layoutJson.data_sources : []) {
    const id = source?.id;
    if (typeof id !== 'string' || seen.has(id) || !seoIds.includes(id)) continue;
    seen.add(id);
    if (ctx[id] !== undefined && ctx[id] !== null) ordered.push(ctx[id]);
  }

  const firstDataField = (path: string): string => {
    for (const source of ordered) {
      const value = readPath(source, path);
      if (value !== null && value !== undefined && value !== '') return localize(value);
    }
    return '';
  };

  const pageType: string | null = typeof seo.page_type === 'string' && seo.page_type !== '' ? seo.page_type : null;
  const routeExtension = describeRouteExtension(route?.layout, payload);
  const routeParams: Record<string, unknown> = route?.params ?? {};

  // meta.seo.vars — 서버 SeoRenderer::resolveSeoVars 의 짝
  const varsDecl: Record<string, unknown> = seo.vars && typeof seo.vars === 'object' && !Array.isArray(seo.vars) ? seo.vars : {};
  const resolveVar = (expr: string): string => {
    if (expr.startsWith('$module_settings:')) {
      const [id, key] = parseExtensionSettingsKey(expr.slice('$module_settings:'.length), routeExtension.module);
      if (id) return localize(extensionSetting('module', id, key, payload, ctx));
      return safeEvaluate(expr, ctx);
    }
    if (expr.startsWith('$plugin_settings:')) {
      const [id, key] = parseExtensionSettingsKey(expr.slice('$plugin_settings:'.length), routeExtension.plugin);
      if (id) return localize(extensionSetting('plugin', id, key, payload, ctx));
      return safeEvaluate(expr, ctx);
    }
    if (expr.startsWith('$core_settings:')) return localize(coreSetting(expr.slice('$core_settings:'.length), payload, ctx));
    if (expr.startsWith('$query:')) return localize(ctx.query?.[expr.slice('$query:'.length)] ?? '');

    return safeEvaluate(expr, ctx);
  };
  let resolvedVarsCache: Record<string, string> | null = null;
  const resolvedVars = (): Record<string, string> => {
    if (resolvedVarsCache === null) {
      resolvedVarsCache = {};
      for (const [name, expr] of Object.entries(varsDecl)) {
        resolvedVarsCache[name] = resolveVar(String(expr ?? ''));
      }
    }
    return resolvedVarsCache;
  };

  // _seo — 서버 SeoRenderer::resolveSeoContext + applySettingsTemplate 의 짝 (값이 있는 page_type 만 싣는다)
  let seoContextCache: Record<string, { title: string; description: string }> | null = null;
  const seoContext = (): Record<string, { title: string; description: string }> => {
    if (seoContextCache !== null) return seoContextCache;
    seoContextCache = {};
    const extensions = Array.isArray(seo.extensions) ? seo.extensions : [];
    if (!pageType || extensions.length === 0) return seoContextCache;

    const allResolved: Record<string, string> = {};
    for (const extension of extensions) {
      const type = extension?.type;
      const id = extension?.id;
      if (!type || !id) continue;
      const entry = payload.extensions?.[`${type}:${id}`];
      if (!entry) continue;
      const definitions: any = entry.variables ?? {};
      const common = definitions._common && typeof definitions._common === 'object' && !Array.isArray(definitions._common) ? definitions._common : {};
      const typed = definitions[pageType] && typeof definitions[pageType] === 'object' && !Array.isArray(definitions[pageType]) ? definitions[pageType] : {};
      const merged: Record<string, any> = { ...common, ...typed };
      if (Object.keys(merged).length === 0) continue;

      for (const [name, definition] of Object.entries(merged)) {
        const source = definition?.source ?? 'data';
        const key = definition?.key ?? name;
        let value = '';
        switch (source) {
          case 'setting':
            value = localize(entry.settings?.[key] ?? '');
            break;
          case 'core_setting':
            value = localize(coreSetting(key, payload, ctx));
            break;
          case 'query':
            value = localize(ctx.query?.[key] ?? '');
            break;
          case 'route':
            value = routeParams[key] === undefined || routeParams[key] === null ? '' : String(routeParams[key]);
            break;
          case 'data':
            value = resolvedVars()[name] ?? '';
            break;
          default:
            value = '';
        }
        allResolved[name] = value;
      }

      const templates = entry.templates?.[pageType] ?? {};
      const title = substituteSeoVars(localize(templates.title ?? ''), allResolved);
      const description = substituteSeoVars(localize(templates.description ?? ''), allResolved);
      if (title !== '' || description !== '') {
        seoContextCache[pageType] = { title, description };
      }
    }
    return seoContextCache;
  };

  // 하위호환 모듈/플러그인 틀 — 서버 SeoMetaResolver::resolveModuleTemplate/resolvePluginTemplate 의 짝
  const legacyTemplate = (type: 'module' | 'plugin', id: string): string => {
    if (!pageType) return '';
    const template = localize(payload.extensions?.[`${type}:${id}`]?.templates?.[pageType]?.title ?? '');
    if (template === '') return '';
    if (Object.keys(varsDecl).length === 0) return template;

    return substituteSeoVars(template, resolvedVars());
  };

  const templateTier = (): string => {
    if (pageType) {
      const title = seoContext()[pageType]?.title;
      if (title !== undefined && title !== null && title !== '') return title;
    }
    if (routeExtension.module) return legacyTemplate('module', routeExtension.module);
    if (routeExtension.plugin) return legacyTemplate('plugin', routeExtension.plugin);

    return '';
  };

  /** 레이아웃 식 하나를 평가한다 — 빈 값(빈 글자·빈 배열)은 후보가 아니다. 식 안에서 `_seo` 도 읽을 수 있다 */
  const layoutValueTier = (value: unknown): string => {
    if (value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0)) return '';
    const seoCtx = seoContext();
    const evalContext = Object.keys(seoCtx).length > 0 ? { ...ctx, _seo: seoCtx } : ctx;

    return safeEvaluate(value, evalContext);
  };

  // 레이아웃 편집기 「검색 제목」(meta.seo.title) — 서버 SeoMetaResolver::resolveLayoutSeoTitle 의 짝.
  // 그 화면 하나에 대한 운영자 설정이라 확장 전체의 제목 틀보다 앞서고, 상품·페이지 자체 값보다는 뒤다.
  const layoutTitleTier = (): string => layoutValueTier(seo.title);
  const ogTier = (): string => layoutValueTier(seo.og?.title);

  return {
    ...base,
    tiers: [
      () => firstDataField('data.meta_title'),
      () => firstDataField('data.seo_meta.title'),
      layoutTitleTier,
      templateTier,
      ogTier,
      routeTier,
    ],
  };
}

/** 컨트롤러 설정 */
export interface DocumentTitleOptions {
  /** 서버 제목 계산 값 (없으면 탭 제목을 바꾸지 않는다) */
  payload: SeoTitlePayload | null | undefined;
  /** 현재 화면 언어 */
  getLocale: () => string | null;
  /** `$t:` 토큰을 현재 번역으로 바꾼다 (번역 엔진이 없으면 null) */
  translate: (text: string, context: Record<string, any>) => string | null;
  /** 편집기 등 탭 제목을 바꾸면 안 되는 화면이면 true */
  isSuppressed?: () => boolean;
  /** 관리자 템플릿이면 'admin' (기본 'user') (@since engine-v1.88.0) */
  mode?: 'user' | 'admin';
}

/**
 * 탭 제목 컨트롤러 — 화면 전환·데이터 도착·언어 전환에 맞춰 document.title 을 다시 쓴다.
 *
 * - `beginRoute(route)`: 이동을 시작한 순간 라우트 제목(+접미사)을 쓰고 앞 화면 레이아웃과의 연결을 끊는다
 *   (앞 화면 제목을 남기지 않는다)
 * - `bind(layout, context)`: 새 화면을 그린 뒤 그 레이아웃에 연결하고 계산한다
 * - `recompute(layout, context)`: 데이터가 바뀔 때마다 — 연결한 레이아웃 객체와 같을 때만 계산한다
 *   (앞 화면의 늦은 데이터가 새 화면 제목을 덮지 않는다)
 * - `showError(title)`: 오류 화면 제목
 *
 * 값이 바뀔 때만 document.title 에 쓴다.
 */
export class DocumentTitleController {
  private options: DocumentTitleOptions | null = null;
  private route: TitleRoute | null = null;
  private boundLayout: unknown = null;
  private bindingEngine: DataBindingEngine | null = null;

  /**
   * 컨트롤러를 켠다 (사용자 템플릿 부팅 때 한 번).
   *
   * @param options 설정
   * @returns void
   */
  configure(options: DocumentTitleOptions): void {
    this.options = options;
  }

  /**
   * 컨트롤러를 끄고 상태를 비운다 (시험·재부팅용).
   *
   * @returns void
   */
  reset(): void {
    this.options = null;
    this.route = null;
    this.boundLayout = null;
  }

  /**
   * 켜져 있고 쓸 수 있는 상태인지.
   *
   * @returns 쓸 수 있으면 true
   */
  isActive(): boolean {
    const options = this.options;
    if (!options || !options.payload || typeof document === 'undefined') return false;
    // 레이아웃 편집기 미리보기(`/preview/{token}` — 시스템 레이아웃 `__preview__`)는 편집 중인 화면이라 제목을 바꾸지 않는다
    if (this.route?.layout === '__preview__') return false;

    return !(options.isSuppressed?.() ?? false);
  }

  /**
   * 화면 전환 시작 — 라우트 제목(+접미사)을 곧바로 쓰고 레이아웃 연결을 끊는다.
   *
   * @param route 새 라우트
   * @returns void
   */
  beginRoute(route: TitleRoute): void {
    this.route = route;
    this.boundLayout = null;
    if (!this.isActive()) return;
    this.write(computeTitleInput(null, {}, route, this.deps()));
  }

  /**
   * 새 화면을 그린 뒤 레이아웃에 연결하고 계산한다.
   *
   * @param layoutJson 그린 레이아웃 JSON (렌더러가 쥔 객체와 같은 참조)
   * @param context 화면 데이터 컨텍스트
   * @returns void
   */
  bind(layoutJson: unknown, context: Record<string, any>): void {
    if (this.route === null) return;
    this.boundLayout = layoutJson;
    this.recompute(layoutJson, context);
  }

  /**
   * 데이터가 바뀌었을 때 다시 계산한다 — 연결한 레이아웃이 아니면 아무것도 하지 않는다.
   *
   * @param layoutJson 지금 렌더러가 쥔 레이아웃 JSON
   * @param context 화면 데이터 컨텍스트
   * @returns void
   */
  recompute(layoutJson: unknown, context: Record<string, any>): void {
    if (this.boundLayout === null || layoutJson !== this.boundLayout || !this.isActive()) return;
    this.write(computeTitleInput(layoutJson, context, this.route, this.deps()));
  }

  /**
   * 오류 화면 제목 — 레이아웃 연결을 끊고 주어진 제목(+접미사)을 쓴다. 제목이 비면 사이트 이름.
   *
   * @param title 오류 제목 (`$t:` 키 허용)
   * @returns void
   */
  showError(title: string): void {
    this.boundLayout = null;
    if (!this.isActive()) return;
    this.write(computeTitleInput(null, {}, { path: undefined, meta: { title } }, this.deps()));
  }

  /**
   * 평가 도구를 만든다.
   *
   * @returns 평가 도구
   */
  private deps(): TitleComputeDeps {
    const options = this.options!;
    this.bindingEngine ??= new DataBindingEngine();
    const engine = this.bindingEngine;
    const locale = options.getLocale();

    return {
      payload: options.payload ?? {},
      locale,
      mode: options.mode ?? 'user',
      evaluate: (expression, context) => {
        let text: string;
        const single = /^\s*\{\{([\s\S]+?)\}\}\s*$/.exec(expression);
        if (single && !single[1].includes('{{')) {
          let value: unknown;
          try {
            value = engine.evaluateExpression(single[1].trim(), context, { skipCache: true });
          } catch {
            value = engine.resolveBindings(expression, context, { skipCache: true });
          }
          text = value !== null && typeof value === 'object' ? localizeTitleValue(value, locale) : value === null || value === undefined ? '' : String(value);
        } else {
          text = expression.includes('{{') ? engine.resolveBindings(expression, context, { skipCache: true }) : expression;
        }
        if (text.includes('$t:')) {
          const tokenOnly = /^\$t:(?:defer:)?([\w.-]+)$/.exec(text.trim());
          const translated = options.translate(text, context);
          if (translated === null) return '';
          // 번역이 없으면 번역기가 키를 그대로 돌려준다 — 키를 제목으로 쓰지 않는다
          if (tokenOnly && translated.trim() === tokenOnly[1]) return '';
          text = translated;
        }
        return text;
      },
    };
  }

  /**
   * 계산한 제목을 쓴다 (바뀔 때만).
   *
   * @param input 제목 규칙 입력
   * @returns void
   */
  private write(input: TitleRuleInput): void {
    const { full } = resolveDocumentTitle(input);
    if (full === '' || document.title === full) return;
    document.title = full;
  }
}

/** 엔진 전역 탭 제목 컨트롤러 */
export const documentTitle = new DocumentTitleController();
