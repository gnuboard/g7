// e2e:allow 레이아웃 편집기 텍스트 데이터 연결=키화 API — contentEditable/칩 드래그/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스 + 단위(inlineBindingApi.test.ts S9-N4 포함)로 검증 (InlineBindingSection.tsx 와 동일 정책)
/**
 * inlineBindingApi.ts — param 데이터 연결 해제 시 전 로케일 키 값 동기
 *
 * param 데이터 연결을 '해제'하면 node.text 의 `|pN=` 토큰 제거(레이아웃 JSON)와 함께, 그 키의
 * **전 로케일** 키 값에서 `{pN}` 자리표시를 제거해야 한다(custom_translations) — 소스 자체를
 * 끊는 것이므로 로케일 무관 일괄("칩 제거(데이터 연결 해제)"). 본 모듈은 그
 * 전 로케일 키 값 동기 PUT 만 담당한다(node.text 패치는 호출자가 onPatchNode 로 수행).
 *
 * 키 → 행 해석은 useInlineEdit 의 `findCustomKeyRow`(SSoT) 를 재사용한다. PUT 은 단일 요청에
 * 전 로케일 values 를 함께 보낸다(updateCustomKeyValue 는 단일 로케일 전용이라 부적합).
 *
 * @since engine-v1.50.0
 */

import {
  findCustomKeyRow,
  createCustomKey,
  EDITOR_TRANSLATIONS_REFRESHED_EVENT,
} from '../../hooks/useInlineEdit';
import {
  setPendingValue,
  setPendingValues,
  getPendingValue,
  getPendingValues,
} from '../../hooks/pendingCustomTranslations';
import {
  removePlaceholderFromKeyValue,
  appendPlaceholder,
  extractBindingSegments,
  insertPlaceholderAt,
  buildParamizedKeyText,
  buildParamizedKeyValue,
  buildKeyTextFromChipModel,
  deriveChipModel,
  extractParamBindings,
  nextParamName,
  paramPlaceholderTokens,
  stripBindingTokens,
} from '../../spec/inlineBindingUtils';
import { buildBindingExpression } from '../../spec/bindingCandidates';
import { TranslationEngine } from '../../../TranslationEngine';

export { findCustomKeyRow };

/**
 * 데이터 삽입 = 즉시 키화.
 *
 * 비키화 텍스트 노드(평문 또는 평문+raw 보간)에 데이터를 삽입할 때, raw `{{}}` 만 붙이는 대신
 * **param 정규화**해 그 즉시 `$t:custom.X|pN={{소스}}` 키 노드로 만든다. 이렇게 해야:
 *  - 번역 탭이 즉시 활성(키 인지) → 다국어 문자열 + 칩 편집 가능.
 *  - 보간 위치가 키 값의 `{pN}` 자리표시로 보존 → 칩 드래그 재배치 가능.
 *
 * 처리:
 *  1. 원본 text 에 `charIndex` 위치로 새 보간을 끼운다(`insertBindingSegment`) → 위치 보존 원본.
 *  2. 그 원본을 param 정규화 → 키 값(자리표시 문장) + 키 텍스트(`$t:K|pN={{}}`).
 *  3. createCustomKey 로 키 생성(키 값 = 자리표시 문장) → 응답 키로 키 텍스트 완성.
 *
 * @param templateIdentifier 템플릿 식별자
 * @param layoutName 편집 중 레이아웃명(키 네이밍 출처)
 * @param locale 편집 로케일
 * @param currentText 현재 node.text(평문 또는 평문+raw 보간)
 * @param charIndex 새 보간을 끼울 문자 위치(끝이면 currentText.length)
 * @param sourceId 데이터 소스 식별자
 * @param sourcePath scope/소스 루트 이하 점 경로
 * @param shape 데이터 shape
 * @param resolveLang `$t:` lang 키를 현재 로케일 평문으로 해석하는 콜백(선택) — 노드 text 가
 *   `$t:auth.email` 같은 lang 키면 키 값에 raw `$t:` 토큰이 박히지 않도록 평문("이메일")으로
 *   먼저 치환한다. 미전달 시 currentText 를 그대로 사용(순수 평문 노드).
 * @param translate `$t:` 키 → 현재 로케일 lang **값**(이름 자리표시 `{{name}}` 포함 가능) 해석기
 *   (선택) — lang **named-param** Shape(`$t:user.*|count={{}}`)를 deriveChipModel 로 분해하는 데
 *   필요하다. 미전달 시 resolveLang 기반 기존 경로(순수 평문/Shape A)만 처리.
 * @returns 성공 시 새 param 키 텍스트(`$t:custom.X|pN={{}}`) + 생성된 커스텀 키, 실패 시 error.
 *   `key` 는 G-2 충돌 차단용 — 호출자(오버레이)가 인라인 편집 상태/칩 편집기 activeKeyRef 에
 *   전파해, 저장 시 keyifyChipValue 가 같은 노드를 **재키화**하지 않게 한다(둘째 키 생성 방지).
 */
export async function keyifyWithNewBinding(
  templateIdentifier: string,
  layoutName: string | null,
  locale: string,
  currentText: string,
  charIndex: number,
  sourceId: string,
  sourcePath: string,
  shape: 'scalar' | 'array' | 'object',
  resolveLang?: (text: string) => string,
  translate?: (key: string) => string,
): Promise<{ kind: 'ok'; text: string; key: string } | { kind: 'error'; message?: string }> {
  // S9-N4 (identity/challenge 모달 `남은 시도: {{count}}회` 노드 '+데이터' 시 `|count=` raw
  // 박힘 + 칩 분리): node.text 가 **lang named-param** Shape(`$t:user.*remaining_attempts|count={{}}`)
  // 면, 종전 경로(resolveLang + buildParamizedKeyValue)는 lang 키만 lang **값**(`남은 시도: {{count}}회`)
  // 으로 치환하고 node 의 `|count={{Math.max}}` 토큰은 **그대로 남겨** merged0 가
  // `"남은 시도: {{count}}회|count={{Math.max(...)}}"` 가 됐다. buildParamizedKeyValue 가 그 안의
  // `{{count}}`/`{{Math.max}}` 를 **이중으로** `{p0}`/`{p1}` 화하고 `|count=` 평문이 키 값에 박혔다
  // (라이브 `남은 시도: {p0}회 {p2} |count={p1}` 재현). deriveChipModel 은 lang 값의 `{{count}}` 를
  // 그 named param 의 보간으로 **매핑**해 이 Shape 를 올바르게 분해(`chipValue:"남은 시도: {p0}회"`,
  // `bindings:[{{Math.max}}]`)하므로, 인라인 칩 편집(keyifyChipValue)·classify 와 동일 SSoT 로 통일한다.
  // deriveChipModel 이 처리 못하는 Shape(평문+보간 / Shape A / lang 미해석)는 keyifiable:false 를
  // 돌려주므로, 그 경우 아래 기존 merged0 경로로 폴백한다(기존 동작 보존 — 회귀 차단).
  const chip = translate ? deriveChipModel(currentText, translate) : null;
  // 0. `$t:` lang 키 평문화 — 노드 text 가 lang 키면 키 값에 raw `$t:` 가 박히는 것을 막는다.
  //    `{{...}}` 보간은 **보존**한다(아래 buildParamizedKeyValue 가 전부 `{pN}` 로 정규화하므로
  //    raw 가 키 값에 박히지 않는다). lang named-param 은 deriveChipModel 결과(chip.chipValue/bindings)
  //    가 SSoT — 이미 `{pN}` 자리표시 문장이라 buildParamizedKeyValue 재정규화 불필요(이중변환 차단).
  const useChip = !!(chip && chip.keyifiable);
  const merged0 = resolveLang ? resolveLang(currentText) : currentText;
  // 1. 위치 삽입은 **자리표시 문장 좌표계**에서만 수행한다.
  //    호출자(칩 편집기/속성탭)가 주는 charIndex 는 칩 편집기 표시 문장(chipValue, 자리표시
  //    `"발행일: {p0}"`)의 커서 좌표다. 종전엔 이 좌표를 merged0(보간 **원문** 포함 raw,
  //    `"발행일: {{termsContent?...| date}}"`)에 그대로 써서, 칩 뒤(끝) 커서가 보간 토큰
  //    **내부**(`{{te|rmsContent`)를 갈라 raw 가 깨져 박혔다(`발행일: {{te 1 rmsContent...}}`).
  //    절차: ① 자리표시 문장(lang named-param=chip.chipValue / 그 외=buildParamizedKeyValue(merged0))을
  //    SSoT 로 ② 그 문장의 charIndex 위치에 **새 자리표시**(`{pN}`, 다음 번호)를 삽입 ③ node.text 는
  //    기존 보간 `|p0=..` + 새 보간 `|pN=..` 부착(이름 기반 치환 — 위치는 키 값만 관리).
  const baseKeyValue = useChip ? chip!.chipValue : buildParamizedKeyValue(merged0);
  const existingCount = useChip ? chip!.bindings.length : extractBindingSegments(merged0).length;
  const newParamName = `p${existingCount}`;
  const effIndex = Math.max(0, Math.min(charIndex, baseKeyValue.length));
  const keyValue = insertPlaceholderAt(baseKeyValue, effIndex, newParamName);
  // 3. 키 생성 — desync 방지: 초기 POST 값은 **자리표시·보간 없는 순수 평문**(라벨만)으로.
  //    keyValue 에서 `{pN}` 자리표시를 제거한 평문(`발행일:`)을 POST 한다(stripBindingTokens 는 keyValue
  //    에 raw 보간이 없으므로 자리표시만 정리). 자리표시 문장(`발행일: {p0} {p1}`)은 저장-지연 버퍼에
  //    둬 레이아웃 저장 시 node.text(`|pN=`)와 함께 영속(저장 전 raw {pN} 노출 0). plainBase 가 비면
  //    (라벨 없는 순수 데이터 노드) keyValue 폴백(빈 value 백엔드 거부 회피 — 결함 G).
  const plainBase = stripBindingTokens(keyValue)
    .replace(/\{\{?p\d+\}?\}/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  const postValue = plainBase.length > 0 ? plainBase : keyValue;
  const created = await createCustomKey(templateIdentifier, layoutName, locale, postValue);
  if (created.kind === 'error' || !created.resource) {
    return { kind: 'error', message: created.message };
  }
  const key = created.resource.translation_key;
  // 자리표시 문장은 **전 로케일** 버퍼에 — 저장 시 flush. 캔버스는 seed 로 즉시 반영(라이브).
  //
  // 결함: 종전엔 편집 로케일에만 placeholder 문장을
  // 버퍼링했다(`setPendingValue(key, locale, keyValue)`). 그러면 다른 로케일(en/ja)은 서버
  // plainBase(자리표시 없음)로 남고, 이어서 둘째 칩을 추가하면 insertBindingIntoParamKey 가
  // row.values(서버=plainBase) 기준으로 끝에 `{pN}` 만 붙여 **첫 칩의 `{p0}` 가 영영 누락**된다
  // (ko 만 p0+p1, en/ja 는 p1 만). 첫 키화 시점에 전 로케일에 placeholder 를 심어야 한다 —
  // 편집 로케일은 커서 위치 문장(keyValue), 그 외는 그 로케일 plainBase(서버 값) 끝에 같은
  // 자리표시들을 추가(어순은 번역가가 드래그로 조정 — 미편집 로케일 규칙).
  const paramNames = paramPlaceholderTokens(keyValue); // keyValue 의 {pN} 자리표시 이름들.
  const serverValues = (created.resource.values ?? {}) as Record<string, string>;
  const seeded: Record<string, string> = { [locale]: keyValue };
  for (const loc of Object.keys(serverValues)) {
    if (loc === locale) continue;
    let v = serverValues[loc] ?? '';
    for (const pn of paramNames) v = appendPlaceholder(v, pn); // 같은 자리표시들 끝에 추가.
    seeded[loc] = v;
  }
  setPendingValues(templateIdentifier, key, seeded);
  // node.text — 기존 보간을 부착하고 새 보간을 마지막(pN)에 더한다. 위치는 키 값의 `{pN}` 자리표시가
  // 관리하므로(이름 기반 치환) text 의 param 순서는 위치와 무관.
  //  - lang named-param(useChip): 기존 보간은 deriveChipModel 의 bindings(SSoT — lang 값 `{{count}}` 를
  //    그 named param 의 보간으로 정확히 매핑). buildParamizedKeyText(merged0) 를 쓰면 merged0 의
  //  `{{count}}`(lang 값 자리표시)까지 별도 param 으로 이중 부착돼 `|count=` raw 가 박힌다.
  //  - 그 외(평문/Shape A): 기존대로 merged0 의 보간을 등장 순서로 부착.
  const newExpr = buildBindingExpression(sourceId, sourcePath, shape);
  const baseKeyText = useChip
    ? buildKeyTextFromChipModel(`$t:${key}`, chip!)
    : buildParamizedKeyText(`$t:${key}`, merged0);
  const text = `${baseKeyText}|${newParamName}=${newExpr}`;
  return { kind: 'ok', text, key };
}

/**
 * 이미 param 키인 노드에 새 보간을 임의 위치로 삽입한다.
 *
 * node.text 에 다음 빈 번호 param(`|pN={{소스}}`)을 추가하고, 편집 로케일 키 값의 커서 위치에
 * `{pN}` 자리표시를 삽입한다. 미편집 로케일은 문장 끝에 자동 추가(`appendParamPlaceholderAllLocales`
 * 와 동일 규칙 — 번역가가 칩을 올바른 자리로 드래그). 키 값 PUT 은 호출자가 별도 수행하거나,
 * 본 함수가 편집 로케일만 즉시 PUT 하고 미편집은 끝 추가한다.
 *
 * @param templateIdentifier 템플릿 식별자
 * @param locale 편집 로케일
 * @param nodeText 현재 param 키 텍스트(`$t:K|p0={{}}`)
 * @param keyValueCharIndex 편집 로케일 키 값에서 자리표시를 끼울 문자 위치
 * @param sourceId 데이터 소스 식별자
 * @param sourcePath 점 경로
 * @param shape shape
 * @returns 성공 시 새 node.text(param 추가됨) + 부여된 param 이름, 실패 시 error
 */
export async function insertBindingIntoParamKey(
  templateIdentifier: string,
  locale: string,
  nodeText: string,
  keyValueCharIndex: number,
  sourceId: string,
  sourcePath: string,
  shape: 'scalar' | 'array' | 'object',
): Promise<{ kind: 'ok'; text: string; paramName: string } | { kind: 'error'; message?: string }> {
  let parsed = extractParamBindings(nodeText);
  // S9-N1 — param 0 **단독 custom 키**(`$t:custom.X`, `|pN=` 없음)도 그 키를 승계해
  // param 을 부착한다. 종전엔 param 키가 아니라고 error 를 반환해, 호출자(속성탭 insertDataKeyify)
  // 가 keyifyWithNewBinding 으로 **재키화** → 새 키가 ko 평문 기반 전 로케일 시드 → 기존 키의
  // en/ja 번역이 orphan 으로 소실됐다(라이브 .68→.69 실측 — Close-EN/閉じる-JA 소실).
  if (!parsed && typeof nodeText === 'string') {
    const bare = /^\s*\$t:(custom\.[A-Za-z0-9._-]+)\s*$/.exec(nodeText);
    if (bare) parsed = { key: bare[1], params: [] };
  }
  if (!parsed) return { kind: 'error', message: 'not a param key' };
  // S9-N4 방어 가드 — 키 승계(param 부착)는 **custom 키일 때만**.
  // extractParamBindings(PARAMIZED_KEY_RE)는 custom/lang 키를 구분하지 않아 **미키화 lang named-param**
  // (`$t:user.*|count={{}}`)도 parsed 로 통과시킨다. 그런 노드에 `|pN=` 을 부착하면 lang 키가 오염되고
  // 키화가 안 된다. custom 키가 아니면 error 를 돌려 호출자(속성탭/오버레이)가 keyifyWithNewBinding
  // (신규 custom 키화) 폴백을 타게 한다. (상위 분기[classify/InlineBindingSection]가 이미 lang named-param
  // 을 비키화로 라우팅하므로 정상 흐름에선 도달하지 않지만, 어느 경로로든 도달해도 오염을 막는 안전망.)
  if (!parsed.key.startsWith('custom.')) return { kind: 'error', message: 'not a custom param key' };
  const name = nextParamName(parsed.params.map((p) => p.name));
  const expr = buildBindingExpression(sourceId, sourcePath, shape);
  const newText = `${nodeText.trim()}|${name}=${expr}`;
  // 전 로케일 키 값 갱신(저장-지연 버퍼) — 편집 로케일은 커서 위치, 그 외는 문장 끝.
  // 로케일 집합의 SSoT: 서버 행(row.values) ∪ 보류 버퍼(pending). 키화 **직후**(저장 전)에는 키가
  // pending 버퍼에만 있고 서버 GET 목록엔 아직 없을 수 있다(응답 캐시/타이밍). 종전엔 서버 행이
  // 없으면 즉시 error 로 빠져 데이터 추가가 무반응이었다( G: "키화 직후 데이터 추가 안 됨,
  // 저장·새로고침해야 됨"). 서버 행이 없어도 pending 으로 진행한다.
  const row = await findCustomKeyRow(templateIdentifier, parsed.key);
  const pending = getPendingValues(parsed.key) ?? {};
  const locales = new Set<string>([
    ...Object.keys(row?.values ?? {}),
    ...Object.keys(pending),
    locale,
  ]);
  if (locales.size === 0) return { kind: 'error', message: 'custom key not found' };
  const next: Record<string, string> = {};
  for (const loc of locales) {
    const base = getPendingValue(parsed.key, loc) ?? row?.values?.[loc] ?? '';
    next[loc] = loc === locale ? insertPlaceholderAt(base, keyValueCharIndex, name) : appendPlaceholder(base, name);
  }
  // 즉시 PUT 폐기. 버퍼 기록(레이아웃 저장 시 node.text 와 함께 영속 → desync 0).
  setPendingValues(templateIdentifier, parsed.key, next);
  return { kind: 'ok', text: newText, paramName: name };
}

/**
 * 단일 로케일 키 값을 버퍼에 기록한다.
 *
 * 칩 드래그 이동·평문 편집은 **해당 로케일 키 값만** 바꾼다(다른 로케일 불변 — 어순 독립).
 * 기존 다른 로케일 값을 보존한 채 현재 로케일만 덮어쓴다.
 *
 * @param templateIdentifier 템플릿 식별자
 * @param customKey 커스텀 키
 * @param locale 편집 로케일
 * @param value 새 키 값(자리표시 문장)
 * @returns 성공/실패
 */
export async function putSingleLocaleKeyValue(
  templateIdentifier: string,
  customKey: string,
  locale: string,
  value: string,
): Promise<{ kind: 'ok' | 'error'; message?: string }> {
  // 즉시 PUT 폐기. 키 값 변경을 저장-지연 버퍼에 기록(+캔버스 라이브 seed)하고,
  // 레이아웃 [저장] 시 flushPending 이 node.text 와 함께 영속한다(desync 원천 차단).
  setPendingValue(templateIdentifier, customKey, locale, value);
  return { kind: 'ok' };
}

/**
 * 커스텀 키의 전 로케일 키 값을 변환 함수로 일괄 갱신하고 PUT 한다.
 *
 * 데이터 연결 해제(자리표시 제거)·신규 보간 추가(자리표시 끝 추가)는 모두 "전 로케일 키 값에
 * 동일 자리표시 연산을 적용 후 단일 PUT" 패턴이라 본 함수로 통합한다. `updateCustomKeyValue`
 * (단일 로케일 전용)와 달리 전 로케일 values 를 함께 보낸다.
 *
 * @param templateIdentifier 템플릿 식별자
 * @param customKey 커스텀 키(`custom.*`)
 * @param transform 각 로케일 값에 적용할 변환(자리표시 제거/추가 등)
 * @returns 성공/실패
 */
async function mutateAllLocaleValues(
  templateIdentifier: string,
  customKey: string,
  transform: (value: string) => string,
): Promise<{ kind: 'ok' | 'error'; message?: string }> {
  // 즉시 PUT 폐기. 전 로케일에 변환을 적용한 결과를 저장-지연 버퍼에 기록한다.
  // 현재 유효값(서버 값 위에 이미 보류 중인 값을 덮은 것)을 베이스로 변환해 누적 일관성 유지.
  const row = await findCustomKeyRow(templateIdentifier, customKey);
  if (!row) return { kind: 'error', message: 'custom key not found' };
  const next: Record<string, string> = {};
  for (const [locale, serverVal] of Object.entries(row.values ?? {})) {
    const effective = getPendingValue(customKey, locale) ?? serverVal;
    next[locale] = transform(effective);
  }
  setPendingValues(templateIdentifier, customKey, next);
  // 캔버스/속성 미리보기 즉시 반영 — pending 만 갱신하면 TranslationEngine(미리보기
  // `translate(key)` 의 출처)은 옛 값을 들고 있어, 마지막 칩 X 제거 후 평문 미리보기가 제거된 `{pN}` 을
  // 그대로 노출했다(서버 PUT 은 저장 시점이라 미반영). 변환된 전 로케일 값을 엔진에 선반영해 표시를 맞춘다.
  try {
    const engine = TranslationEngine.getInstance();
    for (const [locale, value] of Object.entries(next)) {
      engine.setTranslationValue(templateIdentifier, locale, customKey, value);
    }
  } catch {
    /* 선반영 실패는 무시 — 레이아웃 저장 시 flushPending 이 최종 보정 */
  }
  return { kind: 'ok' };
}

/**
 * 커스텀 키의 **전 로케일** 키 값에서 `{pN}` 자리표시를 제거하고 일괄 PUT 한다.
 *
 * @param templateIdentifier 템플릿 식별자
 * @param customKey 커스텀 키(`custom.*`)
 * @param paramName 제거할 param 이름(`p0`/`p1`…)
 * @returns 성공/실패
 */
export function removeParamPlaceholderAllLocales(
  templateIdentifier: string,
  customKey: string,
  paramName: string,
): Promise<{ kind: 'ok' | 'error'; message?: string }> {
  return mutateAllLocaleValues(templateIdentifier, customKey, (v) =>
    removePlaceholderFromKeyValue(v, paramName),
  );
}

/**
 * 캔버스/번역탭/칸자리/키관리 즉시 반영 — 사전 재fetch + pending 재읽기 신호 발화.
 * InlineBindingSection 의 `fireRefresh` 와 동일 신호(EDITOR_TRANSLATIONS_REFRESHED_EVENT) — 칩 X
 * 해제·칩 이동 등 키 값 변경 직후 모든 칩 위젯이 pending 을 다시 읽고 캔버스가 재seed 한다.
 *
 * @param templateIdentifier 템플릿 식별자
 * @param locale 현재 편집 로케일(이벤트 detail — 수신측 필터용, 선택)
 */
export function fireTranslationsRefreshed(templateIdentifier: string, locale?: string): void {
  if (typeof window === 'undefined') return;
  try {
    window.dispatchEvent(
      new CustomEvent(EDITOR_TRANSLATIONS_REFRESHED_EVENT, { detail: { templateIdentifier, locale } }),
    );
  } catch {
    /* 무해 — 다음 진입 시 최신 사전 로드 */
  }
}

/**
 * 데이터 칩 '해제' 공용 — param 의 전 로케일 `{pN}` 자리표시 제거 + 즉시 동기화 신호.
 *
 * 칩 우측 X·[속성]탭 해제 버튼이 공유하는 SSoT. **node.text 의 `|pN=` 제거는 호출자**가
 * 수행한다(node 보유 컨텍스트만 — 칸자리/인라인은 onChange, 펼침은 onRemoveParam→모달 node patch).
 * 본 함수는 로케일 무관 키 값 동기 PUT + 캔버스/위젯 재읽기 신호만 담당한다. node.text 패치 후 이
 * 함수를 호출하면 전 로케일 자리표시 제거와 화면 동기화가 한 번에 끝난다.
 *
 * @param templateIdentifier 템플릿 식별자
 * @param customKey 커스텀 키(`custom.*`)
 * @param paramName 해제할 param 이름(`p0`/`p1`…)
 * @param locale 현재 편집 로케일(동기화 신호 detail, 선택)
 * @returns 성공/실패
 */
export async function disconnectParamAllLocales(
  templateIdentifier: string,
  customKey: string,
  paramName: string,
  locale?: string,
): Promise<{ kind: 'ok' | 'error'; message?: string }> {
  const res = await removeParamPlaceholderAllLocales(templateIdentifier, customKey, paramName);
  fireTranslationsRefreshed(templateIdentifier, locale);
  return res;
}

/**
 * 커스텀 키의 **전 로케일** 키 값 끝에 `{pN}` 자리표시를 추가하고 일괄 PUT 한다.
 *
 * 데이터 연결 영역에서 새 보간을 추가하면(위치 미지정) 전 로케일 문장 끝에 자리표시를 단다 —
 * 번역가가 인라인/번역 탭에서 칩을 올바른 자리로 드래그한다.
 *
 * @param templateIdentifier 템플릿 식별자
 * @param customKey 커스텀 키(`custom.*`)
 * @param paramName 추가할 param 이름(`p0`/`p1`…)
 * @returns 성공/실패
 */
export function appendParamPlaceholderAllLocales(
  templateIdentifier: string,
  customKey: string,
  paramName: string,
): Promise<{ kind: 'ok' | 'error'; message?: string }> {
  return mutateAllLocaleValues(templateIdentifier, customKey, (v) =>
    appendPlaceholder(v, paramName),
  );
}
