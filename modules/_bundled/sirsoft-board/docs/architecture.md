# 게시판 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
게시판마다 완결된 권한·역할 체계를 갖게 하면서도, 새 게시판을 만드는 데 코드 변경이 필요 없게
하는 것이 이 모듈의 핵심 설계 목표입니다. `boards` 테이블 한 행이 하나의 확장처럼 동작하도록,
권한·역할·메뉴는 모두 **런타임에 게시판 데이터로부터 파생**됩니다(`getDynamicPermissionIdentifiers()`
등 3개 메서드가 코드가 아니라 DB 를 읽어 계산). 그 대가로 이 세 메서드는 게시판이 하나
추가/삭제될 때마다 정확해야 하고, 어긋나면 stale 권한이 남거나 존재하는 게시판의 권한이
정리 대상으로 오판됩니다.

또한 "관리자 백엔드 모듈 + 방문자 화면은 템플릿" 분리를 의도적으로 유지합니다. 방문자 화면을
이 모듈 안에 두면 템플릿마다 디자인이 다른 게시판 UI 를 이 모듈이 전부 알아야 하는데,
API 로만 노출하면 템플릿 쪽에서 자유롭게 화면을 구성할 수 있습니다. 이 경계 때문에
"레이아웃 확장"·"레이아웃"에는 오직 관리자 화면만 나타나며, 그것이 정상입니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```
Http/Controllers (Admin/ 관리자, User/ 공개 API)
        │
        ▼
FormRequest (검증 + *_validation_rules 필터 훅으로 게시판별 규칙 확장 지점)
        │
        ▼
Services (BoardService/PostService/CommentService/ReportService/AttachmentService 등)
        │  before_*  →  filter_*_data  →  실행  →  after_*  (전 도메인 공통 3단 훅 패턴)
        ▼
Repositories (RepositoryInterface 경유, 정렬 화이트리스트·컬럼 프루닝)
        │
        ▼
Models (SoftDeletes 적용 — Post/Comment/Attachment/Report)
```

Listeners(`src/Listeners/`)는 이 흐름과 별도 레인입니다 — Service 가 발행한 훅을 받아 카운트
동기화(`*CountSyncListener`)·활동 로그·SEO 캐시·검색 색인·알림 데이터 추출을 수행하며, Service
자신은 이 부가효과를 알지 못합니다. 이 분리 덕분에 새 부가효과(예: 신규 카운트 컬럼 동기화)는
Service 를 건드리지 않고 리스너 추가만으로 끝납니다.
<!-- @intent END -->

## 디렉토리

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `module.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `module.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Http/Controllers/` | 컨트롤러 | API 표면 변경 시 `api:docgen` 재실행 |
| `src/Http/Requests/` | FormRequest (검증 SSoT) | 검증 규칙은 Service 가 아니라 여기에 둔다 |
| `src/Http/Resources/` | API 리소스 | 목록 응답은 화면이 실제로 그리는 것만 싣는다 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Repositories/` | 데이터 접근 | 목록 쿼리는 컬럼 프루닝·정렬 화이트리스트 확인 |
| `src/Models/` | Eloquent 모델 | 스키마 변경 시 마이그레이션 + 업그레이드 스텝 동반 |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/Enums/` | 상태·타입·분류 | 문자열 리터럴 대신 Enum 을 SSoT 로 둔다 |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `src/lang/` | 백엔드 다국어 | ko·en 동시 반영 + 번들 ja 팩 동기화 |
| `database/migrations/` | 마이그레이션 | 한국어 comment + `down()` 필수, 기설치본은 업그레이드 스텝으로 백필 |
| `database/seeders/` | 시더 | composer autoload 등록 + `extension:update-autoload` |
| `upgrades/` | 업그레이드 스텝 | DB·설정 구조 변경 시 작성 (모듈/플러그인 전용) |
| `resources/layouts/` | 레이아웃 JSON | `php artisan module:update sirsoft-board --force` (빌드 불필요) |
| `resources/routes/` | 라우트 → 레이아웃 매핑 (분할) | `php artisan module:update sirsoft-board --force` |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan module:build` → `php artisan module:update sirsoft-board --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan module:update sirsoft-board --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan module:update sirsoft-board --force` |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
<!-- @generated:directory-map END -->
