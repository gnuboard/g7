/**
 * @file adminLocaleInjectionCoverage.test.ts
 * @description `_global.locale` 을 읽는 관리자 레이아웃은 그 값을 주입하는가 (S10 18차)
 *
 * 저장소 관례 `$locale ?? _global.locale ?? 'ko'` 는 **세 항이 모두 채워질 때만** 뜻이 있다.
 * `$locale` 은 `DynamicRenderer` 가 편집 모드에서만 주입하고, `_global.locale` 은 코어가 한 번도
 * 쓰지 않는다 — 그래서 이 모듈의 `initAdminLocale` 핸들러가 **저장소 전체에서 유일한 writer** 다.
 * 주입이 없는 레이아웃에서는 체인이 언제나 마지막 항으로 굳어, 비-ko 운영자에게
 *
 *   - 필터 옵션 라벨(국가명·결제수단명·배송유형명)이 한국어로 나오고,
 *   - 서버가 `…custom_shipping_name.{app()->getLocale()}` 로 보낸 **422 필드 오류가 표시되지 않는다**
 *     (저장은 거절되는데 어느 칸이 문제인지 화면에 없다).
 *
 * 어느 쪽도 예외·경고·로그를 남기지 않는다. 표현식은 문법상 멀쩡하고 폴백이 늘 값을 채우므로
 * 렌더 테스트도 초록이다.
 *
 * **이 검사의 모집단은 저장소에서 파생한다.** 17차가 세운 단언은 설정 레이아웃 **한 파일**만
 * 봤고, 같은 표현식을 쓰는 다른 4개 화면은 검사 자체가 닿지 않아 죽은 채로 남아 있었다
 * (18차 검출). 파일을 손으로 열거하면 다음에 그 체인을 복제한 화면이 또 모집단 밖에 선다.
 *
 * ## 이 검사의 경계 (19차 명시)
 *
 * 파생은 **이 모듈의 관리자 레이아웃 한 디렉토리**(`ADMIN_ROOT`)로 고정돼 있다. 저장소에는
 * 그 밖에서 같은 체인(`_global.locale`)을 읽는 번들 레이아웃이 더 있고(sirsoft-basic ·
 * sirsoft-board · sirsoft-marketing) 그쪽에는 writer 가 없어 체인이 `ko` 로 굳는다. 다만
 * 그 화면들은 **다른 확장의 소유**라 이 모듈의 계약으로 붉힐 대상이 아니다 — 각 확장이
 * 자기 주입 핸들러를 갖거나 코어가 `_global.locale` 을 채우는 것이 그쪽의 해법이고, 이
 * 검사에 그 경로를 얹으면 이 모듈이 고칠 수 없는 실패로 즉시 붉어진다.
 *
 * 요약: **이 검사는 sirsoft-ecommerce 의 admin 레이아웃만 본다. 다른 확장의 같은 결함은
 * 별도 인계 사항이며 여기서 덮지 않는다.**
 *
 * @vitest-environment node
 */

import fs from 'node:fs';
import path from 'node:path';

import { describe, expect, it } from 'vitest';

/** 관리자 레이아웃 루트 */
const ADMIN_ROOT = path.resolve(__dirname, '../../../layouts/admin');

/** 파티션 디렉토리 — `partials/{루트 레이아웃 이름}/*.json` 규약 */
const PARTIALS_DIR = path.join(ADMIN_ROOT, 'partials');

/** 주입 핸들러 이름 */
const INJECTION_HANDLER = 'sirsoft-ecommerce.initAdminLocale';

/** 관례 체인이 읽는 전역 키 */
const LOCALE_READ = /_global\.locale/;

/**
 * 디렉토리 아래 JSON 파일 경로를 모은다.
 *
 * @param dir 시작 디렉토리
 * @returns JSON 파일 절대경로 배열
 */
function jsonFilesUnder(dir: string): string[] {
  if (!fs.existsSync(dir)) {
    return [];
  }

  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);

    if (entry.isDirectory()) {
      return jsonFilesUnder(full);
    }

    return entry.name.endsWith('.json') ? [full] : [];
  });
}

/**
 * `_global.locale` 을 읽는 루트 레이아웃 이름을 저장소에서 도출한다.
 *
 * 루트가 직접 읽는 경우와 그 파티션이 읽는 경우를 모두 그 루트에 귀속시킨다 — 파티션은
 * 독립 화면이 아니라 루트의 일부이고, `init_actions` 는 루트만 갖기 때문이다.
 *
 * @returns 루트 레이아웃 이름 집합 (확장자 없음)
 */
function rootsReadingLocale(): string[] {
  const roots = new Set<string>();

  for (const file of jsonFilesUnder(ADMIN_ROOT)) {
    if (!LOCALE_READ.test(fs.readFileSync(file, 'utf-8'))) {
      continue;
    }

    const rel = path.relative(ADMIN_ROOT, file).split(path.sep);

    // partials/{root}/x.json → root · {root}.json → root
    roots.add(rel[0] === 'partials' ? rel[1] : path.basename(file, '.json'));
  }

  return [...roots].sort();
}

describe('_global.locale 관례 체인 — 읽는 화면은 주입도 한다', () => {
  const roots = rootsReadingLocale();

  it('모집단이 비어 있지 않다 (경로 규약이 바뀌면 이 검사가 통째로 사문이 된다)', () => {
    // 하한은 **실측값**이다. 실측이 5인데 하한이 4면 루트 하나가 `_global.locale` 읽기를 잃는
    // 순간 모집단에서 조용히 빠지고 이 건전성 케이스는 여전히 통과한다 — 그 화면은 주입을
    // 지워도 붉어지지 않는 상태로 남는다.
    expect(
      roots.length,
      `_global.locale 을 읽는 루트가 줄었다 — 현재 도출: ${roots.join(', ') || '(없음)'}`,
    ).toBeGreaterThanOrEqual(5);
  });

  it.each(roots)('%s: init_actions 가 현재 로케일을 주입한다', (root) => {
    const file = path.join(ADMIN_ROOT, `${root}.json`);

    expect(fs.existsSync(file), `${root}.json 루트 레이아웃이 없다`).toBe(true);

    const layout = JSON.parse(fs.readFileSync(file, 'utf-8'));
    const handlers: string[] = (layout.init_actions ?? []).map((a: any) => a?.handler);

    expect(handlers).toContain(INJECTION_HANDLER);
  });

  it.each(roots)('%s: 주입이 다른 init_action 보다 먼저다', (root) => {
    const layout = JSON.parse(fs.readFileSync(path.join(ADMIN_ROOT, `${root}.json`), 'utf-8'));
    const handlers: string[] = (layout.init_actions ?? []).map((a: any) => a?.handler);

    // 뒤에 두면 그보다 앞선 액션의 표현식이 아직 빈 값을 읽는다.
    expect(handlers.indexOf(INJECTION_HANDLER)).toBe(0);
  });

  it('주입 핸들러가 모듈 핸들러 레지스트리에 등록돼 있다', async () => {
    const [prefix, bare, ...rest] = INJECTION_HANDLER.split('.');

    expect(rest, '주입 핸들러 이름이 {모듈 식별자}.{핸들러} 두 마디가 아니다').toEqual([]);

    // 텍스트 정규식(`/\binitAdminLocale\s*:/`)은 "그 문자열이 파일 어딘가에 있다" 까지만 봤다 —
    // 주석 한 줄이나 타입 선언도 만족시키고, 등록 객체(`handlerMap`)에서 빠져도 붉어지지 않는다.
    // 맵을 실제로 읽어 그 키의 값이 호출 가능한지 본다.
    // 핸들러 모듈들은 로드 시점에 `window.G7Core?.createLogger?.()` 로 로거를 잡는다(전부
    // 옵셔널 체이닝). node 환경에는 `window` 자체가 없어 import 가 죽으므로 빈 전역만 세운다 —
    // 값을 흉내 내지 않으므로 이 검사가 보는 것은 여전히 "실제 등록 맵" 이다.
    const hadWindow = 'window' in globalThis;

    if (!hadWindow) {
      (globalThis as any).window = {};
    }

    const { handlerMap } = await import('../../handlers');

    if (!hadWindow) {
      delete (globalThis as any).window;
    }

    expect(Object.keys(handlerMap), `${bare} 가 handlerMap 에 없다 — 등록되지 않는 핸들러다`).toContain(bare);
    expect(
      typeof (handlerMap as Record<string, unknown>)[bare],
      `${bare} 의 등록값이 함수가 아니다`,
    ).toBe('function');

    // 레이아웃이 부르는 **접두 이름**이 실제로 해석되는지는 맵만으로는 알 수 없다.
    // 엔트리포인트가 `{MODULE_IDENTIFIER}.{키}` 로 등록하므로 그 식별자와 등록 규약을 함께 본다 —
    // 어느 한쪽이 갈리면 디스패처가 미등록 이름을 예외 없이 흘려 액션이 조용히 무반응이 된다.
    const entry = fs.readFileSync(path.resolve(__dirname, '../../index.ts'), 'utf-8');

    expect(entry, `엔트리포인트의 모듈 식별자가 '${prefix}' 가 아니다`).toContain(
      `const MODULE_IDENTIFIER = '${prefix}'`,
    );
    expect(entry, '엔트리포인트가 {식별자}.{핸들러} 규약으로 등록하지 않는다').toContain(
      '`${MODULE_IDENTIFIER}.${name}`',
    );
  });
});
