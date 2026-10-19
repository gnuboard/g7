# 소셜 로그인 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 1개 (루트: `resources/layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `admin` | 1개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `plugin_settings` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
플러그인 설정 화면(`plugin_settings`) 하나입니다. 코어 「플러그인 관리 → 설정」 경로가 띄우며, 사이트 전체 정책 15칸을 저장합니다. 제공자별 자격증명 편집 화면은 다음 단계 산출물입니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 액션 핸들러가 없습니다._
<!-- @generated:handlers END -->

<!-- @intent START -->
액션 핸들러가 없습니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_프론트 엔트리포인트가 없습니다._
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
전역 진입점(프론트 번들)이 없습니다. 설정 화면은 코어 액션만 씁니다.
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_프론트 에셋이 없습니다._
<!-- @generated:assets END -->

<!-- @intent START -->
에셋 번들이 없습니다(`plugin.json` 에 assets 를 두지 않음). 테스트 결과 화면과 모의 동의 화면은 서버가 그리는 단독 HTML 입니다.
<!-- @intent END -->
