/**
 * inlineBindingApi.test.ts — 데이터 삽입=키화 + 저장-지연 버퍼
 *
 * 핵심 재설계: 칩 키 값 변경은 **즉시 PUT 하지 않고** 저장-지연 버퍼에 기록한다.
 * 레이아웃 [저장] 시 flushPending 이 node.text 와 함께 PUT → desync 0. 본 테스트는:
 *  - keyify: 키 생성 POST 는 **평문**(자리표시 없음), 자리표시 문장은 버퍼에.
 *  - insert/putSingle: PUT 없음(버퍼 기록). flushPending 만 PUT.
 */

import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import {
  keyifyWithNewBinding,
  insertBindingIntoParamKey,
  putSingleLocaleKeyValue,
  removeParamPlaceholderAllLocales,
} from '../../components/property-controls/inlineBindingApi';
import {
  getPendingValue,
  setPendingValues,
  flushPending,
  clearPending,
  hasPending,
} from '../../hooks/pendingCustomTranslations';

// 칩 해제 시 미리보기/캔버스 즉시 반영 — TranslationEngine 선반영 호출을 캡처.
const engineSetCalls: Array<{ locale: string; key: string; value: string }> = [];
vi.mock('../../../TranslationEngine', () => ({
  TranslationEngine: {
    getInstance: () => ({
      setTranslationValue: (_tpl: string, locale: string, key: string, value: string) => {
        engineSetCalls.push({ locale, key, value });
      },
    }),
  },
}));

beforeEach(() => { localStorage.setItem('auth_token', 'tok'); clearPending(); engineSetCalls.length = 0; });
afterEach(() => { vi.restoreAllMocks(); clearPending(); });

/** fetch mock: POST createKey / GET index / PUT. 호출 캡처. */
function stub(rowValues: Record<string, string> = { ko: '{p0} 작성', en: '' }) {
  const calls: Array<{ url: string; method: string; body: any }> = [];
  let seq = 0;
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const method = init?.method ?? 'GET';
    const body = init?.body ? JSON.parse(init.body as string) : null;
    calls.push({ url, method, body });
    if (url.includes('/custom-translations') && method === 'POST') {
      seq += 1;
      // 서버는 신규 키 생성 시 전 로케일을 동일 plainBase 로 채운다(실제 백엔드 동작).
      const v = body?.value ?? '';
      return { ok: true, status: 201, json: async () => ({ data: { id: 10 + seq, translation_key: `custom.home.${seq}`, values: { ko: v, en: v, ja: v }, lock_version: 0 } }) } as Response;
    }
    if (url.includes('/custom-translations/') && method === 'PUT') {
      return { ok: true, status: 200, json: async () => ({ data: { id: 7, lock_version: 2 } }) } as Response;
    }
    if (url.includes('/custom-translations') && method === 'GET') {
      // custom.home.5(고정 테스트용) + 키화로 생성된 custom.home.1 둘 다 노출(다중 칩 연속 테스트).
      return { ok: true, status: 200, json: async () => ({ data: [
        { id: 7, translation_key: 'custom.home.5', values: rowValues, lock_version: 1 },
        { id: 11, translation_key: 'custom.home.1', values: { ko: '안녕', en: '안녕', ja: '안녕' }, lock_version: 0 },
      ] }) } as Response;
    }
    return { ok: true, status: 200, json: async () => ({ data: { cache_version: 1 } }) } as Response;
  }));
  return { calls };
}

describe('keyifyWithNewBinding — 데이터 삽입=키화 (POST 평문 + 자리표시 버퍼, desync 차단)', () => {
  it('평문 끝 삽입 → POST value=평문(자리표시 없음), 버퍼=자리표시 문장, text=param 키', async () => {
    const { calls } = stub();
    const res = await keyifyWithNewBinding('tpl', 'home', 'ko', '안녕', 2, 'user', 'name', 'scalar');
    expect(res.kind).toBe('ok');
    if (res.kind !== 'ok') return;
    const post = calls.find((c) => c.method === 'POST')!;
    // POST 초기값은 자리표시 없는 평문(미저장 새로고침 시 raw {p0} 노출 0).
    expect(post.body.value).toBe('안녕');
    // 자리표시 문장은 버퍼에(저장 시 flush).
    expect(getPendingValue('custom.home.1', 'ko')).toBe('안녕 {p0}');
    expect(res.text).toBe("$t:custom.home.1|p0={{user?.name ?? ''}}");
  });

  it('$t: lang 키 노드 → resolveLang 평문화(raw $t: 미박힘)', async () => {
    const { calls } = stub();
    const resolveLang = (s: string) =>
      s.replace(/\$t:[a-zA-Z0-9._:-]+/g, (tok) => (tok === '$t:auth.email' ? '이메일' : '')).trim();
    const res = await keyifyWithNewBinding('tpl', 'home', 'ko', '$t:auth.email', 13, 'user', 'name', 'scalar', resolveLang);
    const post = calls.find((c) => c.method === 'POST')!;
    expect(post.body.value).toBe('이메일'); // 평문(자리표시 없음)
    expect(post.body.value).not.toContain('$t:');
    expect(getPendingValue('custom.home.1', 'ko')).toBe('이메일 {p0}');
    if (res.kind === 'ok') expect(res.text).toContain("|p0={{user?.name ?? ''}}");
  });

  it('결함 B 회귀: 첫 키화 시 자리표시를 **전 로케일** 버퍼에 심는다 (다중 칩 다로케일 누락 방지)', async () => {
    // 종전엔 편집 로케일에만 placeholder 를 심어, 다른 로케일은 서버 plainBase 로
    // 남았다. 이후 둘째 칩 추가 시 insertBindingIntoParamKey 가 row.values(plainBase) 기준으로
    // 끝에 {p1} 만 붙여 첫 칩 {p0} 가 en/ja 에서 영영 누락(ko=p0+p1, en/ja=p1)되던 결함.
    const { calls } = stub();
    const res = await keyifyWithNewBinding('tpl', 'home', 'ko', '안녕', 2, 'user', 'name', 'scalar');
    expect(res.kind).toBe('ok');
    // 편집 로케일(ko): 커서 위치 문장.
    expect(getPendingValue('custom.home.1', 'ko')).toBe('안녕 {p0}');
    // 그 외 로케일(en/ja): 서버 plainBase("안녕") 끝에 같은 자리표시 {p0} 추가 — 누락 0.
    expect(getPendingValue('custom.home.1', 'en')).toBe('안녕 {p0}');
    expect(getPendingValue('custom.home.1', 'ja')).toBe('안녕 {p0}');
    expect(calls.some((c) => c.method === 'POST')).toBe(true);
  });

  it('결함 G 회귀: Shape A 라벨 보존 — resolveLang(콜론 제외)이 `발행일:` 을 살려 POST 평문에 반영', async () => {
    // `$t:policy.published_at: {{...}}` 에서 `:` 은 키 뒤 라벨 구분자(평문)다.
    // resolveLang 정규식이 콜론을 키에 삼키면(`policy.published_at:`) t() 미해석 → 라벨 소실 →
    // plainBase 빈값 → POST value 가 자리표시뿐(`{p0}`)이 돼 라벨이 사라진다. 콜론을 키에서
    // 빼면 `policy.published_at` 정상 해석 + `:` 평문 보존 → POST 에 `발행일:` 라벨이 살아난다.
    const { calls } = stub();
    // 호출자(InlineBindingSection)와 동일한 콜론-제외 resolveLang.
    const resolveLang = (s: string) =>
      s
        .replace(/\$t:[a-zA-Z0-9._-]+/g, (tok) => (tok === '$t:policy.published_at' ? '발행일' : ''))
        .replace(/\s+/g, ' ')
        .trim();
    const res = await keyifyWithNewBinding(
      'tpl', 'home', 'ko',
      '$t:policy.published_at: {{published_at | date}}',
      999, 'user', 'name', 'scalar', resolveLang,
    );
    expect(res.kind).toBe('ok');
    const post = calls.find((c) => c.method === 'POST')!;
    // 라벨 `발행일:` 보존(자리표시·보간 없는 순수 평문) — 빈값/`{p0}` 단독 아님.
    expect(post.body.value).toBe('발행일:');
    // 버퍼 키 값은 라벨 + 자리표시 2개(기존 published_at = {p0}, 신규 user.name = {p1}). 기존 보간도
    // 보존되므로 자리표시가 둘이다(결함 G — 기존 칩 소실 방지).
    expect(getPendingValue('custom.home.1', 'ko')).toBe('발행일: {p0} {p1}');
  });

  it('결함 G 회귀: plainBase 가 비면 keyValue 로 POST 폴백(빈 value 거부 회피)', async () => {
    // resolveLang 이 라벨을 못 살려(사전 미로드 엣지) plainBase 가 비어도, keyValue(`{p0}`)를 POST
    // 값으로 폴백해 키 생성이 실패하지 않게 한다(데이터 추가 무반응 회피).
    const { calls } = stub();
    const resolveLang = () => ''; // 라벨 해석 실패 모사 → plainBase 빈값.
    const res = await keyifyWithNewBinding(
      'tpl', 'home', 'ko',
      '$t:policy.published_at: {{published_at | date}}',
      999, 'user', 'name', 'scalar', resolveLang,
    );
    expect(res.kind).toBe('ok'); // error 아님 — 폴백으로 생성 성공.
    const post = calls.find((c) => c.method === 'POST')!;
    expect(post.body.value).not.toBe(''); // 빈 value 아님.
    expect(post.body.value).toContain('{p0}'); // keyValue 폴백.
  });

  it('결함 G 회귀: 기존 데이터 보간이 있는 노드에 데이터 추가 시 기존+신규 보간 둘 다 보존(칩 2개)', async () => {
    // 종전엔 keyifyWithNewBinding 이 stripBindingTokens 로 기존 보간을 통째 날려,
    // `발행일: {{published_at}}` 에 data.id 를 추가하면 기존 published_at 칩이 사라지고 새 칩만 남았다
    // (저장 후 다시 열면 기존 기성 칩 소실). 기존 보간을 보존해 둘 다 `{pN}` 자리표시로 정규화한다.
    const { calls } = stub();
    const resolveLang = (s: string) =>
      s.replace(/\$t:[a-zA-Z0-9._-]+/g, (tok) => (tok === '$t:policy.published_at' ? '발행일' : '')).replace(/\s+/g, ' ').trim();
    const res = await keyifyWithNewBinding(
      'tpl', 'home', 'ko',
      '$t:policy.published_at: {{published_at | date}}',
      999, 'user', 'name', 'scalar', resolveLang,
    );
    expect(res.kind).toBe('ok');
    if (res.kind !== 'ok') return;
    // node.text 에 기존 보간(published_at)과 신규 보간(user.name) **둘 다** param 으로 부착.
    expect(res.text).toContain('published_at');
    expect(res.text).toContain('user');
    // param 이 2개(p0=기존, p1=신규).
    const params = res.text.match(/\|p\d+=/g) ?? [];
    expect(params.length).toBe(2);
    // 키 값에 raw 보간 없음(전부 {pN}) + POST 평문은 라벨만.
    const ko = getPendingValue('custom.home.1', 'ko') ?? '';
    expect(ko).not.toContain('{{');
    expect(ko).toContain('{p0}');
    expect(ko).toContain('{p1}');
    const post = calls.find((c) => c.method === 'POST')!;
    expect(post.body.value).not.toContain('{{');
    expect(post.body.value).not.toContain('{p');
  });

  it('결함 H 회귀: 기성 다국어(Shape A — lang키+raw 보간) 키화 시 raw {{...}} 가 키 값에 박히지 않음', async () => {
    // `$t:policy.published_at: {{published_at | date}}` 처럼 노드 text 에 이미
    // 데이터 보간이 박힌 기성 다국어를 키화할 때, resolveLang 은 `$t:` 만 평문화하고 raw 보간을
    // 남긴다. 그 plainBase 를 그대로 POST 하면 키 값(전 로케일)에 raw 표현식이 영속돼 모든 로케일이
    // 깨졌다. 키화 초기값은 보간 0 인 순수 평문이어야 한다.
    const { calls } = stub();
    const resolveLang = (s: string) =>
      s.replace(/\$t:[a-zA-Z0-9._:-]+/g, (tok) => (tok === '$t:policy.published_at' ? '발행일:' : '')).trim();
    const res = await keyifyWithNewBinding(
      'tpl', 'home', 'ko',
      '$t:policy.published_at: {{published_at | date}}',
      999, 'user', 'name', 'scalar', resolveLang,
    );
    expect(res.kind).toBe('ok');
    const post = calls.find((c) => c.method === 'POST')!;
    // POST 초기값에 raw 보간(`{{`)·자리표시(`{p`) 모두 없어야 한다 — 순수 평문.
    expect(post.body.value).not.toContain('{{');
    expect(post.body.value).not.toContain('| date');
    // 버퍼 키 값에도 raw 표현식 없음 — `{p0}` 자리표시만(새로 추가한 데이터).
    const koVal = getPendingValue('custom.home.1', 'ko') ?? '';
    expect(koVal).not.toContain('{{');
    expect(koVal).toContain('{p0}');
  });

  it('결함 G 회귀: 키화 직후 서버 GET 에 키가 아직 없어도(pending 만 존재) 데이터 추가 성공', async () => {
    // 키화 직후(저장 전)엔 키가 pending 버퍼에만 있고 서버 GET 목록엔 아직 없을 수
    // 있다(응답 캐시/타이밍). 종전엔 findCustomKeyRow 가 행을 못 찾아 즉시 error → 데이터 추가가
    // 무반응이었다(저장·새로고침해야 됨). 서버 행이 없어도 pending 으로 진행해야 한다.
    // GET 응답을 빈 목록으로(서버에 아직 없음) 만들고, pending 에만 키 값을 둔다.
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET';
      if (url.includes('/custom-translations') && method === 'GET') {
        return { ok: true, status: 200, json: async () => ({ data: [] }) } as Response; // 서버에 키 없음
      }
      return { ok: true, status: 200, json: async () => ({ data: {} }) } as Response;
    }));
    // 키화 직후 pending 에 심긴 전 로케일 값을 모사.
    setPendingValues('tpl', 'custom.home.9', { ko: '발행일: {p0}', en: 'Pub {p0}', ja: '発行 {p0}' });
    const res = await insertBindingIntoParamKey(
      'tpl', 'ko', '$t:custom.home.9|p0={{a.b}}', 3, 'cart', 'total', 'scalar',
    );
    expect(res.kind).toBe('ok'); // error 아님 — pending 으로 진행
    if (res.kind !== 'ok') return;
    expect(res.paramName).toBe('p1');
    // 전 로케일이 {p0}(기존) + {p1}(신규) 보유.
    for (const loc of ['ko', 'en', 'ja']) {
      const v = getPendingValue('custom.home.9', loc) ?? '';
      expect(v, `${loc} {p0}`).toContain('{p0}');
      expect(v, `${loc} {p1}`).toContain('{p1}');
    }
  });

  it('S9-N3 회귀: 칩 뒤(끝) 커서 위치 삽입 — charIndex 는 자리표시 문장 좌표(보간 원문을 가르지 않음)', async () => {
    // 미키화 노드(lang키+기성 칩)의 칩 편집기에서 caret 을 **칩 뒤(끝)**에
    // 두고 '+데이터'를 하면, charIndex(칩 편집기 chipValue "발행일: {p0}" 좌표계 — 끝=9)를
    // merged0(보간 원문 포함 raw "발행일: {{termsContent?...| date}}") 좌표로 오용해 보간 토큰
    // **내부**(`{{te|rmsContent`)를 갈라 raw 가 깨져 박혔다(`발행일: {{te 1 rmsContent...}}`).
    // 위치 삽입은 자리표시 문장 좌표에서만 수행해야 한다.
    stub();
    const resolveLang = (s: string) => s.replace(/\$t:policy\.published_at/g, '발행일').replace(/\s+/g,' ').trim();
    const chipValueEnd = '발행일: {p0}'.length; // 칩 편집기 chipValue 끝 좌표(=9)
    const res = await keyifyWithNewBinding(
      'tpl', 'home', 'ko',
      '$t:policy.published_at: {{termsContent?.data?.published_at | date}}',
      chipValueEnd,
      'current_user', 'data.id', 'scalar', resolveLang,
    );
    expect(res.kind).toBe('ok');
    if (res.kind !== 'ok') return;
    // node.text — 기존 보간(파이프 서식 포함) + 신규 보간 둘 다 보존, raw 깨짐 0.
    expect(res.text).toContain('|p0={{termsContent?.data?.published_at | date}}');
    expect(res.text).toContain("|p1={{current_user?.data?.id ?? ''}}");
    // 키 값(편집 로케일 버퍼) — 기존 {p0} 보존 + 신규 {p1} 이 커서(끝) 위치. raw `{{` 절대 없음.
    const ko = getPendingValue(res.key, 'ko') ?? '';
    expect(ko).toBe('발행일: {p0} {p1}');
    expect(ko).not.toContain('{{te');
    expect(ko).not.toContain('rmsContent');
  });

  it('S9-N3 회귀: 칩 앞(평문 중간) 커서 위치 삽입 — 평문 좌표 정합 유지', async () => {
    stub();
    const resolveLang = (s: string) => s.replace(/\$t:policy\.published_at/g, '발행일').replace(/\s+/g,' ').trim();
    const res = await keyifyWithNewBinding(
      'tpl', 'home', 'ko',
      '$t:policy.published_at: {{termsContent?.data?.published_at | date}}',
      2, // "발행" 뒤(칩 앞 평문 중간)
      'current_user', 'data.id', 'scalar', resolveLang,
    );
    expect(res.kind).toBe('ok');
    if (res.kind !== 'ok') return;
    const ko = getPendingValue(res.key, 'ko') ?? '';
    expect(ko).toBe('발행 {p1} 일: {p0}');
    expect(ko).not.toContain('{{');
  });

  it('결함 B 회귀: 첫 키화(전 로케일 seed) 후 둘째 칩 추가 → 전 로케일이 {p0}+{p1} 보유', async () => {
    // 다중 칩 연속 추가 — 첫 칩이 전 로케일에 심겼으므로 둘째 칩도 전 로케일에 정상 누적.
    stub();
    const first = await keyifyWithNewBinding('tpl', 'home', 'ko', '안녕', 2, 'user', 'name', 'scalar');
    expect(first.kind).toBe('ok');
    if (first.kind !== 'ok') return;
    // 둘째 칩 — insertBindingIntoParamKey 는 GET row.values 를 보지만, getPendingValue 가 우선이라
    // 첫 키화로 심은 전 로케일 placeholder(ko/en/ja = "안녕 {p0}") 를 베이스로 {p1} 추가.
    const second = await insertBindingIntoParamKey('tpl', 'ko', first.text, 1, 'cart', 'total', 'scalar');
    expect(second.kind).toBe('ok');
    // 전 로케일이 {p0} 와 {p1} 모두 보유 — 누락 0.
    for (const loc of ['ko', 'en', 'ja']) {
      const v = getPendingValue('custom.home.1', loc) ?? '';
      expect(v, `${loc} 는 {p0} 보유`).toContain('{p0}');
      expect(v, `${loc} 는 {p1} 보유`).toContain('{p1}');
    }
  });

  it('S9-N4 회귀: 미키화 lang **named-param** 노드(`$t:user.*|count={{}}`) 데이터 추가 → `|count=` raw 미박힘 + 칩 분리 0', async () => {
    // 종전 경로(resolveLang + buildParamizedKeyValue)는 lang 키만 lang 값(`남은 시도: {{count}}회`)으로
    // 치환하고 node 의 `|count={{Math.max}}` 토큰을 남겨, merged0 가 `"남은 시도: {{count}}회|count=
    // {{Math.max(...)}}"` 가 됐다. buildParamizedKeyValue 가 `{{count}}`/`{{Math.max}}` 를 **이중**으로
    // `{p0}`/`{p1}` 화 + `|count=` 평문이 키 값에 박혔다(라이브 `남은 시도: {p0}회 {p2} |count={p1}`,
    // POST value `"남은 시도: 회 |count="`). 수정: translate 를 넘기면 deriveChipModel 이 lang 값
    // `{{count}}` 를 그 named param 보간으로 매핑해 올바르게 분해(`남은 시도: {p0}회`, bindings=[Math.max]).
    const { calls } = stub();
    // resolveLang: 라이브(EditorCanvasOverlay)와 동일 — `$t:키` 를 lang **값**(이름 자리표시 `{{count}}`
    // 포함)으로 치환한다(editorAwareT 가 lang 값을 반환). 종전 경로는 이 값의 `{{count}}` 와 node 의
    // `|count={{Math.max}}` 를 **이중** {pN} 화 + `|count=` raw 박힘 → 결함 재현 조건.
    const langValue = '남은 시도: {{count}}회';
    const resolveLang = (s: string) =>
      s.replace(/\$t:[a-zA-Z0-9._-]+/g, (tok) =>
        tok === '$t:user.identity.challenge.remaining_attempts' ? langValue : '',
      ).replace(/\s+/g, ' ').trim();
    const translate = (key: string) =>
      key === 'user.identity.challenge.remaining_attempts' ? langValue : key;
    const original =
      '$t:user.identity.challenge.remaining_attempts|count={{Math.max(0, (_local.maxAttempts ?? 0) - (_local.attempts ?? 0))}}';
    const res = await keyifyWithNewBinding(
      'tpl', '_user_base', 'ko', original,
      '남은 시도: {p0}회'.length, // 칩 편집기 chipValue 끝 좌표
      'current_user', 'data.id', 'scalar', resolveLang, translate,
    );
    expect(res.kind).toBe('ok');
    if (res.kind !== 'ok') return;
    // 핵심 ①: node.text — 기존 count 보간(Math.max)이 p0, 신규 data.id 가 p1. `|count=` raw 텍스트 0.
    expect(res.text).toContain('|p0={{Math.max(0, (_local.maxAttempts ?? 0) - (_local.attempts ?? 0))}}');
    expect(res.text).toContain("|p1={{current_user?.data?.id ?? ''}}");
    // `|count=` 가 키 토큰 뒤에 raw 로 박히면 안 된다(이중 변환 잔재).
    expect(res.text).not.toContain('|count=');
    const params = res.text.match(/\|p\d+=/g) ?? [];
    expect(params.length).toBe(2); // p0(기존)+p1(신규) — count 가 별도 param 으로 분리되지 않음.
    // 핵심 ②: POST value — `|count=` 평문 미포함(라이브 `"남은 시도: 회 |count="` 결함 차단).
    const post = calls.find((c) => c.method === 'POST')!;
    expect(post.body.value).not.toContain('|count=');
    expect(post.body.value).not.toContain('{{');
    // 핵심 ③: 키 값(편집 로케일 버퍼) — `남은 시도: {p0}회` 에 신규 {p1} 추가. raw `|count=` 0.
    const ko = getPendingValue(res.key, 'ko') ?? '';
    expect(ko).not.toContain('|count=');
    expect(ko).not.toContain('{{');
    expect(ko).toContain('{p0}');
    expect(ko).toContain('{p1}');
  });

  it('S9-N4 회귀: 멀티 named-param 노드(`remaining_time|minutes={{}}|seconds={{}}`) 데이터 추가도 raw 미박힘', async () => {
    // 멀티 named param 변종(remaining_time = minutes/seconds 2개). lang 값 `{{minutes}}:{{seconds}}` 의
    // 두 자리표시를 각 named param 보간으로 매핑해야 한다(이중 변환·raw 박힘 0).
    const { calls } = stub();
    const langValue = '남은 시간 {{minutes}}:{{seconds}}';
    const resolveLang = (s: string) =>
      s.replace(/\$t:[a-zA-Z0-9._-]+/g, (tok) =>
        tok === '$t:user.identity.challenge.remaining_time' ? langValue : '',
      ).replace(/\s+/g, ' ').trim();
    const translate = (key: string) =>
      key === 'user.identity.challenge.remaining_time' ? langValue : key;
    const original =
      "$t:user.identity.challenge.remaining_time|minutes={{Math.floor((_local.s ?? 0) / 60)}}|seconds={{String((_local.s ?? 0) % 60).padStart(2, '0')}}";
    const res = await keyifyWithNewBinding(
      'tpl', '_user_base', 'ko', original,
      '남은 시간 {p0}:{p1}'.length,
      'current_user', 'data.id', 'scalar', resolveLang, translate,
    );
    expect(res.kind).toBe('ok');
    if (res.kind !== 'ok') return;
    // minutes→p0, seconds→p1, 신규 data.id→p2. `|minutes=`/`|seconds=` raw 텍스트 0.
    expect(res.text).toContain('|p0={{Math.floor');
    expect(res.text).toContain('|p1={{String');
    expect(res.text).toContain("|p2={{current_user?.data?.id ?? ''}}");
    expect(res.text).not.toContain('|minutes=');
    expect(res.text).not.toContain('|seconds=');
    expect((res.text.match(/\|p\d+=/g) ?? []).length).toBe(3);
    const post = calls.find((c) => c.method === 'POST')!;
    expect(post.body.value).not.toContain('|minutes=');
    expect(post.body.value).not.toContain('{{');
  });
});

describe('insertBindingIntoParamKey — param 추가(버퍼 기록, PUT 없음)', () => {
  it('다음 빈 번호 부여 + 버퍼에 전 로케일 자리표시(편집=커서/미편집=끝)', async () => {
    const { calls } = stub({ ko: '{p0} 작성', en: 'wrote {p0}' });
    const res = await insertBindingIntoParamKey('tpl', 'ko', '$t:custom.home.5|p0={{user.name}}', 3, 'cart', 'total', 'scalar');
    expect(res.kind).toBe('ok');
    if (res.kind !== 'ok') return;
    expect(res.paramName).toBe('p1');
    expect(res.text).toBe("$t:custom.home.5|p0={{user.name}}|p1={{cart?.total ?? ''}}");
    // 즉시 PUT 없음 — 버퍼만.
    expect(calls.some((c) => c.method === 'PUT')).toBe(false);
    expect(getPendingValue('custom.home.5', 'ko')).toContain('{p1}');
    expect((getPendingValue('custom.home.5', 'en') ?? '').endsWith('{p1}')).toBe(true);
  });

  it('S9-N1 회귀: param 0 단독 custom 키($t:custom.X)도 재키화 없이 그 키에 p0 부착 + 전 로케일 번역 보존', async () => {
    // 결함 — 이미 키화된 노드(param 0)에 속성탭 '텍스트 끝에 데이터 삽입'을 하면 insertDataKeyify
    // (keyifyWithNewBinding)가 **재키화**해 새 키를 만들고, 기존 키의 en/ja 번역(Close-EN/閉じる-JA)
    // 이 orphan 으로 버려졌다(라이브 .68→.69 실측). 단독 custom 키는 그 키를 승계해 |p0= 부착 +
    // 전 로케일 키 값 끝에 {p0} 추가(번역 보존)해야 한다.
    const { calls } = stub({ ko: '닫기3', en: 'Close-EN', ja: '閉じる-JA' });
    const res = await insertBindingIntoParamKey('tpl', 'ko', '$t:custom.home.5', Number.MAX_SAFE_INTEGER, 'current_user', 'data.name', 'scalar');
    expect(res.kind).toBe('ok');
    if (res.kind !== 'ok') return;
    expect(res.paramName).toBe('p0');
    expect(res.text).toBe("$t:custom.home.5|p0={{current_user?.data?.name ?? ''}}");
    // 새 키 POST 0 (재키화 금지 — 키 승계).
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
    // 전 로케일 번역 보존 + {p0} 끝 추가.
    expect(getPendingValue('custom.home.5', 'ko')).toBe('닫기3 {p0}');
    expect(getPendingValue('custom.home.5', 'en')).toBe('Close-EN {p0}');
    expect(getPendingValue('custom.home.5', 'ja')).toBe('閉じる-JA {p0}');
  });
});

describe('putSingleLocaleKeyValue — 칩 이동/평문(버퍼 기록, PUT 없음)', () => {
  it('현재 로케일만 버퍼 기록(즉시 PUT 없음)', async () => {
    const { calls } = stub();
    await putSingleLocaleKeyValue('tpl', 'custom.home.5', 'ko', '작성 {p0}');
    expect(calls.some((c) => c.method === 'PUT')).toBe(false);
    expect(getPendingValue('custom.home.5', 'ko')).toBe('작성 {p0}');
    expect(getPendingValue('custom.home.5', 'en')).toBeUndefined(); // 다른 로케일 미기록
  });
});

describe('flushPending — 레이아웃 저장 시 버퍼 PUT (서버 값 위 보류 로케일 덮어쓰기)', () => {
  it('버퍼 키들을 PUT 하고 버퍼 비움', async () => {
    const { calls } = stub({ ko: '{p0} 작성', en: 'wrote {p0}' });
    await putSingleLocaleKeyValue('tpl', 'custom.home.5', 'ko', '작성 {p0}');
    expect(hasPending()).toBe(true);
    const r = await flushPending('tpl');
    expect(r.ok).toBe(1);
    expect(r.failed).toEqual([]);
    const put = calls.find((c) => c.method === 'PUT')!;
    expect(put.body.values.ko).toBe('작성 {p0}'); // 보류값 반영
    expect(put.body.values.en).toBe('wrote {p0}'); // 미편집 로케일은 서버값 보존
    expect(hasPending()).toBe(false); // flush 후 버퍼 비움
  });

  it('보류 없으면 PUT 0', async () => {
    stub();
    const r = await flushPending('tpl');
    expect(r.ok).toBe(0);
  });
});

describe('removeParamPlaceholderAllLocales — 칩 해제 시 키값 {pN} 제거 + 미리보기 즉시 반영', () => {
  it('전 로케일 키 값에서 {p0} 제거 후 pending 기록', async () => {
    stub({ ko: '상품 정보 {p0}', en: '상품 정보 {p0}', ja: '상품 정보 {p0}' });
    const r = await removeParamPlaceholderAllLocales('tpl', 'custom.home.5', 'p0');
    expect(r.kind).toBe('ok');
    // {p0} 제거된 평문이 버퍼에.
    expect(getPendingValue('custom.home.5', 'ko')).toBe('상품 정보');
    expect(getPendingValue('custom.home.5', 'en')).toBe('상품 정보');
    expect(getPendingValue('custom.home.5', 'ja')).toBe('상품 정보');
  });

  it('TranslationEngine 에 {p0} 제거된 값 선반영 — 미리보기/캔버스가 raw {p0} 노출 안 하도록 (회귀 차단)', async () => {
    engineSetCalls.length = 0;
    stub({ ko: '{p0}상 품 정보', en: '상품 정보 {p0}', ja: '상품 정보 {p0}' });
    await removeParamPlaceholderAllLocales('tpl', 'custom.home.5', 'p0');
    // 엔진 선반영이 custom.home.5 의 전 로케일(ko/en/ja)에 대해 일어나고, 그 값에 {p0} 가 없어야 한다
    // (미리보기 stale {p0} 노출 차단). 선반영 자체가 0 이면 수정 전 회귀(=결함).
    const calls = engineSetCalls.filter((c) => c.key === 'custom.home.5');
    expect(calls.length).toBeGreaterThan(0); // 수정 전: 0 (엔진 미반영) → 결함
    for (const c of calls) expect(c.value).not.toMatch(/\{p0\}/);
    expect(calls.find((c) => c.locale === 'ko')?.value).toBe('상 품 정보');
    expect(calls.some((c) => c.locale === 'en')).toBe(true);
    expect(calls.some((c) => c.locale === 'ja')).toBe(true);
  });
});
