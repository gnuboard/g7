// 시험 파일의 문자열은 빌드 CSS 로 가지 않아야 한다 — 빌드 도구가 `__tests__` 까지 훑으면 아래 이름이 CSS 에 실린다(#869 S7).
export const forbidden = 'paydemo:md:flex-row paydemo:tracking-widest';
