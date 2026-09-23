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
import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';
import { TEMPLATE_DIR, collectObjects } from './analyticsLayoutTestUtils';

const LAYOUT_DIR = path.join(TEMPLATE_DIR, 'layouts');

/** `layouts/` 에서 통계 화면 JSON 을 파생한다 — 손으로 적은 목록은 항목이 늘면 조용히 지나간다. */
function analyticsScreens(): string[] {
  return fs
    .readdirSync(LAYOUT_DIR)
    .filter((name) => /^admin_analytics_.+\.json$/.test(name))
    .sort();
}

/** 화면 트리를 읽되 `partial` 참조를 따라가 조각까지 펼친다(조각이 계약을 대신 지는 자리가 있다). */
function expandedObjects(relative: string, seen = new Set<string>()): Record<string, any>[] {
  if (seen.has(relative)) return [];
  seen.add(relative);
  const file = path.join(LAYOUT_DIR, relative);
  if (!fs.existsSync(file)) return [];
  const objects = collectObjects(JSON.parse(fs.readFileSync(file, 'utf8')));
  const nested = objects
    .map((node) => node.partial)
    .filter((ref): ref is string => typeof ref === 'string')
    .flatMap((ref) => expandedObjects(ref, seen));
  return objects.concat(nested);
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
  it('표·차트를 가진 화면마다 blur_until_loaded 가 있다 — 옛 값이 서 있다 툭 바뀌지 않는다', () => {
    // 설정 화면은 폼이라 해당 없다 — 값이 바뀌는 표가 없고 저장 버튼이 자체 잠금을 건다.
    const formOnly = new Set(['admin_analytics_settings.json']);
    const missing: string[] = [];
    for (const screen of screens) {
      if (formOnly.has(screen)) continue;
      const hasBlur = expandedObjects(screen).some((node) => node.blur_until_loaded !== undefined);
      if (!hasBlur) missing.push(screen);
    }
    expect(missing).toEqual([]);
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

  // @scenario screen=visitor_detail, state=normal, viewport=1440, locale=ko
  // @effects deferred_translation_needs_leading_text
  it('미룬 번역 토큰이 값의 전부인 자리가 없다 — prop 자리도 포함', () => {
    // 토큰이 값의 **전부**이면 반복 서브트리에서 해소되지 않아 화면에 키가 그대로 보인다.
    // 앞선 가드(`analyticsVisitors.test.tsx`)는 텍스트 노드만 보고 `props` 를 「다른 경로라
    // 정상 해소된다」며 모집단에서 뺐는데, **브라우저 실측이 그 전제를 뒤집었다** —
    // `<badge text="$t:defer:…conversions_badge|count={{…}}">` 가 전환이 든 방문에서 키를
    // 그대로 노출했다(#682 S3 라운드 8). 그래서 값이 어디에 실렸든 전수로 본다.
    const bare: string[] = [];
    const walk = (node: unknown, where: string): void => {
      if (typeof node === 'string') {
        if (/^\$t:defer:[a-zA-Z0-9._-]+\|/.test(node)) bare.push(`${where} :: ${node.slice(0, 80)}`);
        return;
      }
      if (Array.isArray(node)) { node.forEach((v) => walk(v, where)); return; }
      if (node && typeof node === 'object') {
        for (const [k, v] of Object.entries(node as Record<string, unknown>)) walk(v, `${where}.${k}`);
      }
    };
    for (const screen of screens) walk(expandedObjects(screen), screen);
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
