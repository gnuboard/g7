# Tailwind Safelist 가이드

> **상위 문서**: [프론트엔드 가이드 인덱스](index.md)

Tailwind CSS는 빌드 시 사용된 클래스만 포함합니다. JSON 레이아웃에서 동적으로 사용하는 클래스는 safelist에 등록해야 합니다.

---

## TL;DR (5초 요약)

```text
1. Tailwind는 빌드 시 사용된 클래스만 CSS에 포함
2. JSON 레이아웃의 클래스는 소스코드 스캔에서 누락됨
3. 동적 클래스는 main.css의 @utility safelist에 등록 필수
4. 등록 전 기존 safelist 또는 사용 중인 클래스 확인
5. 빌드 후 적용 확인 필수
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
- JSON 레이아웃 파일 (`*.json`)
- 데이터베이스에서 가져온 동적 클래스
- JavaScript로 런타임에 생성된 클래스

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

## 참고 자료

- [Tailwind CSS Safelist 공식 문서](https://tailwindcss.com/docs/content-configuration#safelisting-classes)
- [다크 모드 가이드](dark-mode.md)
- [레이아웃 JSON 가이드](layout-json.md)
