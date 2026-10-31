/**
 * 언어 코드 표시 이름
 *
 * 관리자 다국어 입력·탭·언어 선택이 칸 라벨로 쓰는 언어 이름을 한 곳에서 고른다. 언어팩으로 켠 `fil`·
 * `fil-PH`·`zh-Hant` 같은 언어가 내장 표에 없어 `FIL`·`FIL-PH` 처럼 코드로만 보이던 것(#148)을, 서버가
 * 주는 언어팩의 언어 이름(`appConfig.localeNames` — 언어팩 manifest 의 `locale_native_name`)으로 먼저 보인다.
 *
 * 순서: `appConfig.localeNames[code]` → 번역 `common.language_{code}` → 내장 표 → 코드 그대로(대문자로 바꾸지 않는다).
 *
 * @param code 언어 코드
 * @returns 표시 이름
 */
export declare function getLocaleDisplayName(code: string): string;
