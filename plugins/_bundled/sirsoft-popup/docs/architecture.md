# 팝업 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
팝업은 페이지 **위**에 덮는 레이어다(페이지 **안** 고정 구좌는 히어로섹션). 그래서 템플릿에 자리를 새로 요구하지 않고, 모든 사용자 화면에 이미 있는 공통 확장점 `user_global_overlay` 에 주입 조각 하나로 붙는다. 서버는 사용 여부·기간·노출 대상만 판정해 대상·언어 단위로 캐시하고, 노출 페이지·기기·「보지 않기」·이번 페이지에서 닫음은 브라우저(`PopupLayer`)가 판정한다 — SPA 경로 이동마다 서버에 다시 묻지 않기 위해서다. 노출 상태는 저장하지 않고 조회 시점에 SQL 로 계산한다(cron 없음). 표시 방식은 팝업마다가 아니라 전역(플러그인 설정)이다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
Controller(`Admin\PopupController`·`PopupImageController`·`DisplaySettingsController`, `Public\PopupController`·`PopupImageController`) → FormRequest(`StorePopupRequest`·`UpdatePopupRequest` 가 `PopupFieldRules` 공유, Rule `SafeLinkUrl`·`PopupRoute`·`SanitizedBodyNotEmpty`·`SelectablePopupImage` 등) → Service(`PopupService` 훅·트랜잭션·복사·정화 호출, `PopupImageService` 파일, `PublicPopupService` 캐시, `PopupHtmlSanitizer`) → RepositoryInterface → Repository(`PopupRepository` 상태 SQL 판정 한 곳) → Model(`Popup`·`PopupImage`). 예외는 메시지 키를 들고 다닌다(`PopupImageAttachedException` 422, `PopupImageNotOwnedException` 다른 관리자의 임시 이미지 삭제 403, `PopupImageCopyException` 복사 실패, `PopupImageUploadException` 업로드 저장 실패 500). 판정 규칙의 공유 지점: 상태 = `PopupStatus::resolve` ↔ `PopupRepository::applyStatusFilter`, 경로 = `Support\RoutePath` ↔ `resources/js/lib/popupVisibility.ts`.
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
| `resources/layouts/` | 레이아웃 소스 `.g7.html` (짝 `.json` 은 굽기 산출. 컴포넌트 종류는 `data-type`, 컴파일은 템플릿과 무관) | `php artisan plugin:update sirsoft-popup --force` (빌드 불필요) |
| `resources/routes.json` | 라우트 → 레이아웃 매핑 | `php artisan plugin:update sirsoft-popup --force` |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-popup --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-popup --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-popup --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 작성자가 읽는 props 계약) | `php artisan plugin:update sirsoft-popup --force` |
| `assets/icon.svg` | 확장 아이콘 (관리자 목록·설치 마법사 카드) | 정사각·100KB 이하·스크립트 없는 SVG — 교체 후 `php artisan plugin:update sirsoft-popup --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->

## 파일 정리 대장과 예약 갱신

`PendingImageFiles`는 이미지 쓰기 전 로컬 `cleanup/pending-images`에 고유 해시·상대경로·디스크·시각을 기록합니다. 로컬 대장 기록 실패면 쓰기를 시작하지 않습니다. 성공 행 또는 파일 삭제를 확인하면 지우며, 보존기간이 지난 항목은 기존 정리 명령이 이미지 행을 재확인한 뒤 기록된 디스크에서 삭제합니다. 손상 항목은 로그로 알리고 자동 삭제하지 않습니다. 대장 로컬 볼륨을 보존해야 하며, 여러 웹 노드를 운영하면 각 노드의 대장을 정리 명령이 읽을 수 있어야 합니다. DB 스키마 변경은 없습니다.

`usePopupRefresh`는 공개 응답의 다음 기간 경계·서버 시각으로 타이머 하나를 유지합니다. 0건이어도 구독하고, 탭 복귀에서 공개 데이터 소스만 재조회합니다. 코어·템플릿 변경 없이 기존 닫음·보지 않기 판정을 계속 사용합니다.
