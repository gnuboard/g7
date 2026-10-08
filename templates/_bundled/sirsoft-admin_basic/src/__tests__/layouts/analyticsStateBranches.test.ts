/**
 * 방문자 통계 화면의 **상태 분기 전수 가드** — 계획서 §4.10 「상태 분기(모든 화면)」.
 *
 * 화면 하나를 고치고 닫으면 다음에 같은 표현식을 쓰는 나머지 화면에서 같은 구멍이 다시 열린다.
 * 그래서 모집단을 손으로 적지 않고 `layouts/admin_analytics_*.json` 을 읽어 파생하고, 조각
 * (`partial`) 참조까지 따라가 화면이 실제로 그리는 트리 전체를 본다.
 *
 * 재는 것은 둘이다.
 *  - 실패해도 흰 화면이 되지 않는가 — api 데이터소스마다 `fallback`.
 *  - 다시 받아오는 동안 옛 값이 서 있다 툭 바뀌지 않는가 — 표·차트를 가진 화면마다 `blur_until_loaded`.
 *
 * @scenario screen=overview, state=loading, viewport=1440, locale=ko
 * @effects every_analytics_screen_has_fallback_and_blur
 */
import { describe, it, expect } from 'vitest';
import {
  analyticsScreens,
  analyticsLayoutFiles,
  expandedObjects,
  loadLayout,
  loadLocale,
  localeNames,
  langValue,
  translationKeys,
} from './analyticsLayoutTestUtils';

/**
 * `blur_until_loaded` 가 실제로 켜지는가 — 선언만 있고 꺼진 값(`false`·`{enabled:false}`·빈 식)은 흐림이 없다.
 *
 * @returns 켜졌으면 가리키는 데이터소스 id 목록(객체형이 아니면 빈 배열), 꺼졌으면 null
 */
function effectiveBlur(value: unknown): string[] | null {
  if (value === true) return [];
  if (typeof value === 'string') return value.trim() !== '' && value.trim() !== '{{false}}' ? [] : null;
  if (value && typeof value === 'object' && !Array.isArray(value)) {
    const config = value as { enabled?: unknown; data_sources?: unknown };
    if (config.enabled === false) return null;
    const sources = config.data_sources;
    if (Array.isArray(sources)) return sources.map(String);
    return typeof sources === 'string' && sources !== '' ? sources.split(',').map((s) => s.trim()).filter(Boolean) : [];
  }
  return null;
}

/** 화면이 스스로 그리는 api 데이터소스(조각은 자기 소스를 갖지 않는다). */
function apiSources(objects: Record<string, any>[]): Record<string, any>[] {
  return objects.filter((node) => node.type === 'api' && typeof node.endpoint === 'string' && typeof node.id === 'string');
}

describe('방문자 통계 화면 — 상태 분기 전수', () => {
  const screens = analyticsScreens();

  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects every_analytics_screen_has_fallback_and_blur
  it('통계 화면 모집단이 비어 있지 않다', () => {
    // 모집단이 비면 아래 두 단언이 전부 조용히 통과한다 — 그 자체를 먼저 잰다.
    expect(screens.length).toBeGreaterThanOrEqual(14);
  });

  // @scenario screen=overview, state=load_failed, viewport=1440, locale=ko
  // @effects every_analytics_screen_has_fallback_and_blur
  it('모든 화면의 api 데이터소스가 fallback 을 갖는다 — 실패해도 흰 화면이 되지 않는다', () => {
    const missing: string[] = [];
    for (const screen of screens) {
      for (const source of apiSources(expandedObjects(screen))) {
        if (!source.fallback) missing.push(`${screen}:${source.id}`);
      }
    }
    expect(missing).toEqual([]);
  });

  // @scenario screen=overview, state=loading, viewport=1440, locale=ko
  // @effects every_analytics_screen_has_fallback_and_blur
  it('모든 화면에 켜진 blur_until_loaded 가 있고 그 흐림은 화면이 가진 데이터소스를 기다린다 — 옛 값이 서 있다 툭 바뀌지 않는다', () => {
    // 예외 목록을 두지 않는다. 종전에는 설정 화면을 「폼이라 해당 없음」으로 손으로 뺐지만, 설정 화면도
    // 첫 응답 전 입력을 막으려고 탭 본문을 흐린다(`analytics_settings_tab_content`) — 예외가 낡은 채 남아
    // 그 흐림이 빠져도 이 검사가 모를 자리였다(#682 S3 R2-D8).
    //
    // 선언이 있다는 것만으로는 모자란다. `{enabled:false}`·`false` 는 선언이지만 흐림이 없고(R5-D7),
    // 객체형이 가리키는 소스가 화면에 없으면 영영 풀리지 않거나 아무것도 기다리지 않는다 — 그래서
    // 켜졌는지와 **기다리는 방향**(화면의 데이터소스 id)까지 잰다.
    const missing: string[] = [];
    const dangling: string[] = [];
    let blurs = 0;
    for (const screen of screens) {
      const objects = expandedObjects(screen);
      const sourceIds = new Set(objects.filter((node) => typeof node.type === 'string' && typeof node.id === 'string' && (node.type === 'api' || node.type === 'websocket')).map((node) => String(node.id)));
      const effective = objects
        .filter((node) => node.blur_until_loaded !== undefined)
        .map((node) => ({ id: String(node.id ?? '?'), sources: effectiveBlur(node.blur_until_loaded) }))
        .filter((entry): entry is { id: string; sources: string[] } => entry.sources !== null);
      if (effective.length === 0) missing.push(screen);
      for (const { id, sources } of effective) {
        blurs += 1;
        for (const source of sources) if (!sourceIds.has(source)) dangling.push(`${screen}:${id} → ${source}`);
      }
    }
    expect(blurs, '켜진 흐림 자리').toBeGreaterThanOrEqual(screens.length);
    expect(missing, '켜진 흐림이 한 자리도 없는 화면').toEqual([]);
    expect(dangling, '화면에 없는 소스를 기다리는 흐림').toEqual([]);
  });

  // @scenario screen=overview, state=loading, viewport=1440, locale=ko
  // @effects every_analytics_screen_has_fallback_and_blur
  it('흐림 판정기는 꺼진 선언을 흐림으로 세지 않는다', () => {
    expect(effectiveBlur({ enabled: false, data_sources: 'x' })).toBeNull();
    expect(effectiveBlur(false)).toBeNull();
    expect(effectiveBlur('')).toBeNull();
    expect(effectiveBlur(true)).toEqual([]);
    expect(effectiveBlur({ enabled: true, data_sources: 'a, b' })).toEqual(['a', 'b']);
  });

  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects every_analytics_screen_surfaces_the_site_timezone_notice
  it('모든 화면이 시간대 안내에 닿는다 — 공통 조각의 Alert 이든 자기 배지든', () => {
    // 통계의 모든 시각은 사이트 표준시로 나간다(§4.10). 보는 사람의 시간대가 다르면 그 사실을
    // 알려야 하는데, 라운드 6 은 공통 조각에만 넣어 **진단·설정 두 화면이 빠졌다** — 진단은
    // 시각을 여섯 자리 찍으면서 기준을 한 줄도 안 밝히고 있었다(#682 S3 라운드 7).
    //
    // 두 화면은 공통 조각을 쓸 수 없다(「설정에서 켜세요」가 순환 안내가 된다). 그래서 수단은
    // 묻지 않고 **도달만** 잰다 — 조각의 `alert_site_timezone` 이거나 자기 `site_timezone_badge`.
    const REFS = ['alert_site_timezone', 'site_timezone_badge'];
    const missing: string[] = [];
    for (const screen of screens) {
      const objects = expandedObjects(screen);
      const reachable = objects.some((node) => REFS.includes(String(node.id ?? '')));
      if (!reachable) missing.push(screen);
    }
    expect(missing).toEqual([]);
  });

  // @scenario screen=overview, state=normal, viewport=1440, locale=en
  // @effects site_timezone_notice_names_the_account_timezone
  it('시간대 안내는 비교 대상을 「계정 시간대」로 말한다 — 컴퓨터 시간대가 아니다', () => {
    // 서버는 보는 사람의 **계정 시간대**(users.timezone)와 사이트 표준시를 비교한다. 안내가 「내 컴퓨터
    // 시간대와 다릅니다」라고 하면, 컴퓨터는 서울인데 계정만 뉴욕인 사람에게 사실과 다른 말을 한다
    // (7.1.0 실측 E-D8). 모든 로케일(ko·en·ja)에서 「컴퓨터/端末」 대신 계정을 가리킨다.
    const locales = localeNames();
    expect(locales).toContain('ja');
    const wrong: string[] = [];
    for (const locale of locales) {
      const text = String(langValue(loadLocale(locale, 'admin'), 'admin.analytics.alerts.site_timezone') ?? '');
      expect(text, `${locale} 문구가 있어야 한다`).not.toBe('');
      if (/컴퓨터|computer|端末/i.test(text) || !/계정|account|アカウント/i.test(text)) wrong.push(`${locale}: ${text}`);
    }
    expect(wrong).toEqual([]);
  });

  // @scenario screen=visitor_detail, state=normal, viewport=1440, locale=ko
  // @effects deferred_translation_needs_leading_text
  it('미룬 번역 토큰이 값의 전부인 자리가 없다 — prop 자리도 포함', () => {
    // 토큰이 값의 **전부**이면 반복 서브트리에서 해소되지 않아 화면에 키가 그대로 보인다.
    // 앞선 가드(`analyticsVisitors.test.tsx`)는 텍스트 노드만 보고 `props` 를 「다른 경로라
    // 정상 해소된다」며 모집단에서 뺐는데, **브라우저 실측이 그 전제를 뒤집었다** —
    // `<badge text="$t:defer:…conversions_badge|count={{…}}">` 가 전환이 든 방문에서 키를
    // 그대로 노출했다(#682 S3 라운드 8). 그래서 값이 어디에 실렸든 전수로 본다.
    const bare: string[] = [];
    let deferred = 0;
    const walk = (node: unknown, where: string): void => {
      if (typeof node === 'string') {
        if (node.includes('$t:defer:')) deferred += 1;
        if (/^\$t:defer:[a-zA-Z0-9._-]+\|/.test(node)) bare.push(`${where} :: ${node.slice(0, 80)}`);
        return;
      }
      if (Array.isArray(node)) { node.forEach((v) => walk(v, where)); return; }
      if (node && typeof node === 'object') {
        for (const [k, v] of Object.entries(node as Record<string, unknown>)) walk(v, `${where}.${k}`);
      }
    };
    for (const screen of screens) walk(expandedObjects(screen), screen);
    // 모집단이 비면 아래 비교는 빈 배열끼리라 조용히 통과한다 — 미룬 번역 토큰을 실제로 만났는지 먼저 잰다.
    expect(deferred, '훑은 미룬 번역 토큰').toBeGreaterThan(0);
    expect(bare).toEqual([]);
  });

  // @scenario screen=overview, state=consent_fallback, viewport=1440, locale=ko
  // @effects anonymous_fallback_is_surfaced_whatever_the_chosen_mode
  it('익명 폴백 안내가 운영자가 고른 모드로 갇히지 않는다', () => {
    // 서버는 `consent_plugin_blocks_cookies` 를 **`full` 모드에서만** 만든다
    // (`app/Analytics/AnalyticsConfig.php` — full + GDPR 이면 방문자 쿠키가 막혀 익명으로 내린다).
    // 종전 게이트는 `mode === 'consent'` 를 함께 요구해 그 사유와 서로 배타적이었고, 그래서
    // 「전체 수집 + GDPR」 사이트는 실제로 익명 수집 중인데 안내가 한 면도 뜨지 않았다
    // (#682 S3 라운드 8 실측). 사유가 실리면 띄운다 — 고른 모드로 자르지 않는다.
    const gates = expandedObjects('admin_analytics_overview.json')
      .filter((node) => String(node.id ?? '') === 'alert_consent_fallback')
      .map((node) => String(node.if ?? ''));
    expect(gates.length, '동의 폴백 Alert 자리').toBeGreaterThanOrEqual(1);
    for (const gate of gates) {
      expect(gate, '사유를 봐야 한다').toContain('anonymous_fallback_reason');
      expect(gate, "고른 모드로 가두면 `consent_plugin_blocks_cookies` 는 영영 못 뜬다").not.toContain("=== 'consent'");
    }
  });

  // @scenario screen=settings, state=queue_backlog, viewport=1440, locale=ko
  // @effects declared_status_fields_are_actually_drawn_on_every_screen
  it('진단 상태를 받아 두고 안 그리는 화면이 없다 — 선언한 필드는 읽는 자리가 있다', () => {
    // §4.10: 공통 조각 `_partial_status_alerts` 를 쓰지 않는 설정·진단 두 화면은 「각자의 배지·
    // 상태 카드로 **같은 사실**을 보인다」. 그런데 설정 화면은 진단 API 의 `queue.backlogged` 를
    // fallback 에 선언해 받아 두고도 읽는 마크업이 없어, 잡이 밀린 사이트에서 설정만 연 운영자가
    // 그 사실을 못 봤다(#682 S3 라운드 9).
    //
    // 모집단을 「설정 화면」으로 손으로 적지 않는다 — `analytics_status` 의 fallback 이 선언한
    // 잎 필드를 화면마다 파생해, 그 이름을 읽는 표현식이 같은 화면 트리 안에 있는지 전수로 본다.
    // 필드를 하나 더 선언하면 그 필드도 자동으로 검사 대상이 된다.
    const leaves = (node: unknown, prefix: string[] = []): string[][] => {
      if (!node || typeof node !== 'object' || Array.isArray(node)) return [prefix];
      const entries = Object.entries(node as Record<string, unknown>);
      if (entries.length === 0) return [prefix];
      return entries.flatMap(([k, v]) => leaves(v, prefix.concat(k)));
    };

    const unread: string[] = [];
    let checked = 0;
    for (const screen of screens) {
      const objects = expandedObjects(screen);
      const status = objects.find((node) => String(node.id ?? '') === 'analytics_status');
      if (!status?.fallback?.data) continue;
      const text = JSON.stringify(objects);
      for (const leafPath of leaves(status.fallback.data)) {
        const field = leafPath[leafPath.length - 1];
        if (!field) continue;
        checked += 1;
        // 표현식은 `?.` 를 끼워 쓰므로 마지막 마디 이름으로 찾는다(`queue?.backlogged`).
        const readSite = new RegExp(`[.?]\\s*${field}\\b`).test(text.replace(/"fallback":\{[^]*?\}\}/, ''));
        if (!readSite) unread.push(`${screen} :: ${leafPath.join('.')}`);
      }
    }
    // 모집단이 비면 아래 단언이 조용히 통과한다 — 그 자체를 먼저 잰다.
    expect(checked, '검사한 상태 필드 수').toBeGreaterThan(0);
    expect(unread).toEqual([]);
  });
});

describe('방문자 통계 레이아웃 — 번역 키 전수(모든 로케일)', () => {
  // @scenario screen=overview, state=normal, viewport=1440, locale=ja
  // @scenario screen=settings, state=normal, viewport=390, locale=en
  // @effects every_translation_key_exists_in_ko_and_en
  it('통계 레이아웃·조각이 쓰는 모든 $t: 키가 모든 로케일(템플릿 ko·en + ja 언어팩)에 있다', () => {
    // 모집단을 손으로 적지 않는다. 종전 가드는 개요·설정 두 화면과 조각 몇 장만 ko·en 으로 쟀고
    // (`loadLang` 시그니처가 ja 를 타입에서 뺐다), 나머지 화면의 키는 사람 눈으로만 봤다
    // (#682 S3 R1-D2·R3-D2·R12-D1). 레이아웃은 디렉터리에서, 로케일은 `lang/partial/` 디렉터리와
    // 언어팩에서, 네임스페이스는 키의 첫 마디에서 판다 — 화면·로케일·네임스페이스가 늘면 같이 걸린다.
    const files = analyticsLayoutFiles();
    const locales = localeNames();
    expect(files.length, '훑은 레이아웃·조각').toBeGreaterThan(20);
    expect(locales, '로케일 모집단 — ja 가 빠지면 일본어 사이트만 영어로 떨어져도 모른다').toContain('ja');
    expect(locales.length, '로케일 모집단').toBeGreaterThanOrEqual(3);

    const owners = new Map<string, string>();
    for (const file of files) {
      for (const key of translationKeys(loadLayout(file))) if (!owners.has(key)) owners.set(key, file);
    }
    // 키가 비면 아래 비교는 빈 배열끼리라 조용히 통과한다.
    expect(owners.size, '추출한 번역 키').toBeGreaterThan(300);

    const trees = new Map<string, any>();
    const tree = (locale: string, ns: string): any => {
      const id = `${locale}/${ns}`;
      if (!trees.has(id)) trees.set(id, loadLocale(locale, ns));
      return trees.get(id);
    };
    const missing: string[] = [];
    for (const [key, file] of owners) {
      const ns = key.split('.')[0];
      for (const locale of locales) {
        const value = langValue(tree(locale, ns), key);
        if (typeof value !== 'string' || value === '') missing.push(`${locale}:${key} (${file})`);
      }
    }
    expect(missing).toEqual([]);
  });
});
