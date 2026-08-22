/**
 * dollarGlobals.ts — 편집기 샘플 시드의 `$`-prefixed 엔진 전역 변수 추출
 *
 * 런타임 사이트는 `template-engine.ts` 의 `createGlobalVariables()` 가 `$locale` /
 * `$locales` / `$templateId` / `$templateLocales` 같은 `$`-prefixed 엔진 전역을
 * 렌더 컨텍스트 **최상위**(─ `_global` 아래가 아님 ─)에 주입한다. 레이아웃은
 * `options="{{$locales}}"` 처럼 이 최상위 전역을 직접 바인딩한다.
 *
 * 편집기 미리보기(PreviewCanvas)는 `TemplateApp.init()` 을 거치지 않아 이 주입이
 * 빠져 있다. `DynamicRenderer` 가 편집 모드에서 `$locale`/`$templateId`(단수)만
 * 자동 보강할 뿐, `$locales`(목록)는 채우지 않아 로그인 화면 등의 로케일 선택
 * 드롭다운이 빈 채로 렌더되는 결함이 있었다.
 *
 * 해결: 편집 대상 editor-spec 의 `sampleGlobal` 에 `$locale`/`$locales` 등
 * `$`-prefixed 키를 **샘플 데이터로 선언**하고, 본 헬퍼가 병합된 시드에서
 * 그 키들을 골라 PreviewCanvas 가 렌더 컨텍스트 최상위로 끌어올린다(lift). 이로써
 * 다른 `$`-전역(향후 추가분 포함)도 spec 선언만으로 미리보기에 반영된다.
 *
 * 주의: 본 헬퍼는 `$`-키를 **추출만** 한다(seed 원본은 변경하지 않음). seed 내부에
 * 잔존하는 `$`-키는 `_global.$locales` 경로가 되어 어떤 바인딩도 읽지 않으므로
 * 무해하지만, 혼동을 줄이기 위해 PreviewCanvas 는 lift 후 `_global` 에서 제거한
 * 사본을 사용한다(`stripDollarGlobals` 참조).
 *
 * @since engine-v1.50.0
 */

/** 객체에서 `$`-prefixed 최상위 키만 골라 얕은 복제로 반환. 비객체 입력은 빈 객체. */
export function extractDollarGlobals(
  seed: Record<string, unknown> | null | undefined,
): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  if (!seed || typeof seed !== 'object' || Array.isArray(seed)) return out;
  for (const [key, value] of Object.entries(seed)) {
    if (key.startsWith('$')) {
      out[key] = value;
    }
  }
  return out;
}

/** 객체에서 `$`-prefixed 최상위 키를 제거한 얕은 복제를 반환(원본 불변). */
export function stripDollarGlobals(
  seed: Record<string, unknown> | null | undefined,
): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  if (!seed || typeof seed !== 'object' || Array.isArray(seed)) return out;
  for (const [key, value] of Object.entries(seed)) {
    if (!key.startsWith('$')) {
      out[key] = value;
    }
  }
  return out;
}
