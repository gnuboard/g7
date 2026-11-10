# 히어로섹션 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
템플릿은 자리(확장점)만, 플러그인은 데이터·관리 화면·슬라이더 컴포넌트를 소유한다 — 특정 템플릿에 묶이지 않게 하려는 것이다(#810). 노출 상태는 저장하지 않고 조회 시점에 기간으로 계산한다(cron 없음). 사용자 화면 응답은 위치·언어별로 캐시하되 기간 경계를 넘기지 않는다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
Controller → FormRequest(검증 SSoT) → Service(훅 발행·트랜잭션·캐시 무효화) → RepositoryInterface → Repository(모든 쿼리) → Model. 공개 노출: 확장점 조각의 data-source → 공개 API → 노출 서비스(캐시) → Repository.

위치 목록: 필터 `sirsoft-hero_section.position.registered_positions` → `PositionRegistry`(검증·정렬, 요청 단위 공유) — 내장 위치는 `BuiltinPositionListener` 가 넣고(게시판·이커머스 모듈이 켜져 있으면 동적 위치), 대상 해석은 `Support/Targets/*TargetResolver`(모듈 저장소 계약만 사용), 대상이 바뀌면 `TargetChangeListener` 가 모듈 훅을 받아 공개 API 의 해석 기억을 버린다. 관리자 응답의 대상 이름은 `TargetLabels` 가 위치마다 한 번에 푼다. 검증(`RegisteredPosition`·`ValidTarget`)·저장소(`Placement` = 위치 + 대상)·공개 캐시(위치 버전 + 대상 버전)·첫 로드 힌트(정적 위치만)·미리보기 틀이 모두 레지스트리를 따른다.
<!-- @intent END -->

## 디렉토리

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `plugin.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `plugin.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Http/Controllers/` | 컨트롤러 | API 표면 변경 시 `api:docgen` 재실행 |
| `src/Http/Requests/` | FormRequest (검증 SSoT) | 검증 규칙은 Service 가 아니라 여기에 둔다 |
| `src/Http/Resources/` | API 리소스 | 목록 응답은 화면이 실제로 그리는 것만 싣는다 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Repositories/` | 데이터 접근 | 목록 쿼리는 컬럼 프루닝·정렬 화이트리스트 확인 |
| `src/Models/` | Eloquent 모델 | 스키마 변경 시 마이그레이션 + 업그레이드 스텝 동반 |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/Enums/` | 상태·타입·분류 | 문자열 리터럴 대신 Enum 을 SSoT 로 둔다 |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `database/migrations/` | 마이그레이션 | 한국어 comment + `down()` 필수, 기설치본은 업그레이드 스텝으로 백필 |
| `resources/layouts/` | 레이아웃 소스 `.g7.html` (짝 `.json` 은 굽기 산출. 컴포넌트 종류는 `data-type`, 컴파일은 템플릿과 무관) | `php artisan plugin:update sirsoft-hero_section --force` (빌드 불필요) |
| `resources/routes.json` | 라우트 → 레이아웃 매핑 | `php artisan plugin:update sirsoft-hero_section --force` |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-hero_section --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-hero_section --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-hero_section --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 작성자가 읽는 props 계약) | `php artisan plugin:update sirsoft-hero_section --force` |
| `assets/icon.svg` | 확장 아이콘 (관리자 목록·설치 마법사 카드) | 정사각·100KB 이하·스크립트 없는 SVG — 교체 후 `php artisan plugin:update sirsoft-hero_section --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->
