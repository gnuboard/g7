# Tailwind Safelist 가이드

> **상위 문서**: [프론트엔드 가이드 인덱스](README.md)

Tailwind CSS는 빌드 시 사용된 클래스만 포함합니다. JSON 레이아웃에서 동적으로 사용하는 클래스는 safelist에 등록해야 합니다.

---

## TL;DR (5초 요약)

```text
1. Tailwind는 빌드 시 사용된 클래스만 CSS에 포함
2. JSON 레이아웃의 클래스는 소스코드 스캔에서 누락됨
3. 동적 클래스는 main.css의 @utility safelist에 등록 필수
4. 등록 전 기존 safelist 또는 사용 중인 클래스 확인
5. 빌드 후 적용 확인 필수
6. **`@import` 는 `@plugin`·`@config` 보다 먼저** — 뒤에 두면 그 파일이 통째로 사라진다 (§7)
7. 템플릿 빌드는 `php artisan template:build` → `template:update --force` (npm 직접 호출 아님)
```

---

## 목차

1. [Tailwind CSS 빌드 원리](#1-tailwind-css-빌드-원리)
2. [Safelist가 필요한 이유](#2-safelist가-필요한-이유)
3. [Safelist 위치 및 구조](#3-safelist-위치-및-구조)
4. [Safelist 등록 방법](#4-safelist-등록-방법)
5. [작업 체크리스트](#5-작업-체크리스트)
6. [주의사항](#6-주의사항)

---

## 1. Tailwind CSS 빌드 원리

Tailwind CSS는 **사용된 클래스만** 최종 CSS에 포함합니다:

```
소스코드 스캔 → 사용된 클래스 추출 → CSS 생성
```

**스캔 대상**:
- TSX/JSX 컴포넌트 파일
- CSS 파일 내 `@apply` 지시문
- safelist에 명시된 클래스

**스캔 제외**:
- 레이아웃 파일 — 소스 SSoT `*.g7.html` 과 런타임 계약 `*.json` **양쪽 모두**
  (템플릿 `main.css` 가 `@source` 로 명시 앵커한 경우만 예외 — 두 번들 템플릿의 배선이 서로 다르다)
- 데이터베이스에서 가져온 동적 클래스
- JavaScript로 런타임에 생성된 클래스

> `.g7.html` 만 고치고 safelist 를 잊으면 새 클래스가 CSS 에서 누락된다. 소스의 `class` 도
> 여기서 말하는 "스캔 제외" 대상이다 — 확장자만 바뀌었을 뿐 성질은 같다.

---

## 2. Safelist가 필요한 이유

### 문제 상황

JSON 레이아웃에서 클래스를 사용해도 Tailwind가 인식하지 못함:

```json
// 레이아웃 JSON에서 bg-yellow-600 사용
{
  "props": {
    "className": "bg-yellow-600"  // TSX에서 미사용 시 빌드에 포함 안 됨!
  }
}
```

### 해결 방법

#### 1단계: 빌드된 CSS에서 먼저 확인 (권장)

```bash
# 빌드된 CSS에서 사용 가능한 클래스 확인
grep "\.w-32" templates/sirsoft-admin_basic/dist/css/components.css
```

이미 빌드된 CSS에 클래스가 있으면 safelist 추가 없이 바로 사용 가능합니다.

#### 2단계: 없는 경우에만 safelist에 등록

```css
@utility safelist {
  @apply bg-yellow-600;  /* 이제 빌드에 포함됨 */
}
```

---

## 3. Safelist 위치 및 구조

### 파일 위치

```
templates/sirsoft-admin_basic/src/styles/main.css
```

### 구조

```css
/* Safelist - JSON 레이아웃에서 동적으로 사용되는 클래스 */
@utility safelist {
  /* 카테고리별로 그룹화하여 관리 */

  /* UserStatus 상태별 버튼 색상 */
  @apply bg-yellow-600 hover:bg-yellow-700;

  /* 2열 레이아웃용 반응형 클래스 */
  @apply lg:flex-row lg:basis-2/5 lg:basis-3/5 lg:flex-shrink-0;

  /* 사이드바 로고 이미지 크기 제한 */
  @apply max-h-10 max-h-8;
}
```

---

## 4. Safelist 등록 방법

### Step 1: 기존 클래스 확인

새 클래스를 추가하기 전에 **반드시** 확인:

1. safelist에 이미 등록된 클래스인지
2. TSX/CSS 파일에서 이미 사용 중인 클래스인지

```bash
# safelist 확인
grep -n "원하는클래스" templates/sirsoft-admin_basic/src/styles/main.css

# TSX 파일에서 사용 여부 확인
grep -r "원하는클래스" templates/sirsoft-admin_basic/src/components/

# CSS 파일에서 사용 여부 확인
grep -r "원하는클래스" templates/sirsoft-admin_basic/src/styles/
```

### Step 2: Safelist에 등록

기존에 없는 클래스라면 safelist에 추가:

```css
@utility safelist {
  /* 기존 클래스들... */

  /* 새로 추가하는 클래스 - 용도 주석 필수 */
  @apply 새클래스;
}
```

### Step 3: 빌드

```bash
php artisan template:build sirsoft-admin_basic
```

### Step 4: 적용 확인

브라우저에서 해당 클래스가 적용되는지 확인합니다.

---

## 5. 작업 체크리스트

JSON 레이아웃에서 새 Tailwind 클래스 사용 시:

```
□ 1. 기존 safelist에 해당 클래스가 있는지 확인
□ 2. TSX/CSS 파일에서 이미 사용 중인지 확인
□ 3. 없으면 main.css safelist에 추가 (용도 주석 포함)
□ 4. 템플릿 빌드 실행
□ 5. 브라우저에서 적용 확인
```

---

## 6. 주의사항

### 주의 사항

```
❌ safelist 확인 없이 JSON에 새 Tailwind 클래스 사용
❌ 용도 주석 없이 safelist에 클래스 추가
❌ 빌드 없이 적용 확인 시도
```

### 권장 사항

```
✅ 가능하면 이미 사용 중인 클래스 활용
✅ safelist 추가 시 카테고리별로 그룹화
✅ 추가한 클래스의 용도를 주석으로 명시
✅ 변경 후 반드시 템플릿 빌드 실행
```

### CSS 커스텀 클래스 vs Safelist

| 상황 | 권장 방법 |
|------|----------|
| 여러 곳에서 반복 사용 | CSS 커스텀 클래스 정의 (예: `.filter-label`) |
| 특정 레이아웃에서만 오버라이드 | Safelist + JSON에서 클래스 추가 |
| 일회성 스타일링 | Safelist + JSON에서 직접 사용 |

---

## 7. `@import` 는 `@plugin`·`@config` 보다 먼저

`main.css` 에 at-rule 을 더할 때 **순서가 곧 정합성**이다. CSS 명세상 `@import` 는 다른
at-rule 뒤에 오면 무시되고 Tailwind v4 도 그대로 따르므로, `@plugin` 아래에 있는
`@import` 는 **그 파일이 통째로 빌드에서 사라진다**.

```css
/* ❌ 잘못됨 — 아래 두 @import 가 통째로 유실된다 */
@import "tailwindcss";
@plugin "@tailwindcss/typography";
@import "./components/dashboard.css";
@import "./layout/_admin_base.css";

/* ✅ 올바름 — @import 를 모두 위로 */
@import "tailwindcss";
@import "./components/dashboard.css";
@import "./layout/_admin_base.css";
@plugin "@tailwindcss/typography";
```

**이 결함이 위험한 이유는 아무도 알려주지 않는다는 점이다.** 빌드는 성공하고 경고도
없다. Vitest 도 레이아웃 짝 파일 검사도 통과한다 — 사람이 브라우저로 화면을 열어야만
드러난다.

실제 사고(2026-09-18): admin_basic 의 `main.css` 에서 `@plugin "@tailwindcss/typography"`
가 두 `@import` 위로 올라가 `dashboard.css` 와 `_admin_base.css` 가 빌드에서 빠졌다.
결과는 **관리자 셸 붕괴**였다 — `.left_sidebar_area` · `.admin_layout_root` 가 산출물에
0건이 되어 사이드바가 세로로 늘어지고 본문이 그 아래로 밀렸다.

확장 개발자가 자기 화면에 필요한 플러그인 하나를 추가하는 것만으로 **남의 CSS 가
조용히 사라지는** 구조다. 새 `@import` 는 반드시 `@plugin`·`@config` 위 구간에 둔다.

### 검증

빌드 산출물에 그 파일의 클래스가 실렸는지 직접 확인한다.

```bash
php artisan template:build sirsoft-admin_basic
grep -c "left_sidebar_area" templates/_bundled/sirsoft-admin_basic/dist/css/components.css  # 1 이어야 함
```

0 이 나오면 `@import` 가 유실된 것이다 — `main.css` 의 at-rule 순서를 먼저 본다.

> 템플릿 빌드는 `php artisan template:build` → `php artisan template:update {id} --force`
> 가 표준이다. `npm run build` 를 직접 부르면 캐시 버전 갱신과 설치본 반영이 빠진다.

---

## 참고 자료

- [Tailwind CSS Safelist 공식 문서](https://tailwindcss.com/docs/content-configuration#safelisting-classes)
- [다크 모드 가이드](dark-mode.md)
- [레이아웃 JSON 가이드](layout-json.md)
