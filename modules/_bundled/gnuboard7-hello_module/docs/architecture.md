# Hello 모듈 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
"모듈이 필요로 하는 계층을 **하나씩만** 담는다" 가 이 확장의 유일한 설계 목표입니다. 도메인은
메모 하나, 필드는 셋뿐이고, 그 위에 Model · Migration · Factory · Seeder · Repository(인터페이스
+ 구현) · Service · FormRequest · Resource · Controller · Listener · Layout · Test · 다국어가
각 1개씩 있습니다. 실제 모듈은 이 계층을 엔티티 수만큼 늘린 것입니다.

**짧게 유지하는 것이 기능보다 우선입니다.** 샘플의 가치는 완결성이 아니라 한눈에 읽히는
것이므로, 기능을 더하면 계층 구조를 보러 온 사람이 도메인 로직을 읽게 됩니다.

`manifest.hidden = true` 는 학습용이 운영 사이트의 모듈 목록에 섞이지 않게 하면서도 CLI 로는
실제로 설치·동작하게 하는 장치입니다 — 읽기만 해서는 학습이 되지 않기 때문입니다.

**의도적으로 하지 않는 것**: 검색 색인·SEO·알림·스케줄·미들웨어·브로드캐스트·설정 화면. 각
축의 사용법은 그것을 실제로 쓰는 확장의 문서가 다룹니다. 설정 화면 예시는 함께 제공되는
`gnuboard7-hello_plugin` 에 있습니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```
module.php                     진입 클래스 — 권한 · 메뉴 · 리스너 선언
     │
Http/Controllers/Admin/MemoController   RESTful CRUD
     │
Http/Requests/Admin/MemoRequest         검증 (Service 가 아니라 여기)
     │
Services/MemoService                    비즈니스 로직 + 훅 발행
     │   Contracts/Repositories/MemoRepositoryInterface  ← 이것을 주입받는다
     ▼
Repositories/MemoRepository             Eloquent 구현
     │
Models/Memo                             gnuboard7_hello_module_memos

Http/Resources/MemoResource             응답 형태 (컨트롤러가 조립하지 않는다)
Listeners/LogMemoCreatedListener        memo.created 구독 — 부가 작업은 여기
resources/layouts/                      admin 2 + user 1
```

이 지도가 곧 **그누보드7 모듈의 규약**입니다 — 검증은 FormRequest, 데이터 접근은 Repository
인터페이스, 부가 작업은 훅 리스너, 응답 형태는 Resource. 샘플이 잘못된 본을 보이면 그것을 따라
한 모듈이 전부 같은 형태가 되므로, 이 네 경계는 편의를 위해서도 흐트러뜨리지 않습니다.

`user_memo_list` 레이아웃 하나가 `user` 그룹인 것에 주의합니다. 실제 도메인 모듈(게시판·
이커머스)은 방문자 화면을 소유하지 않고 템플릿에 맡기지만, 이 샘플은 **모듈도 사용자 레이아웃을
가질 수 있다**는 사실을 보이기 위해 하나를 둡니다.
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
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `src/lang/` | 백엔드 다국어 | ko·en 동시 반영 + 번들 ja 팩 동기화 |
| `database/migrations/` | 마이그레이션 | 한국어 comment + `down()` 필수, 기설치본은 업그레이드 스텝으로 백필 |
| `database/seeders/` | 시더 | composer autoload 등록 + `extension:update-autoload` |
| `resources/layouts/` | 레이아웃 JSON | `php artisan module:update gnuboard7-hello_module --force` (빌드 불필요) |
| `resources/routes.json` | 라우트 → 레이아웃 매핑 | `php artisan module:update gnuboard7-hello_module --force` |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
<!-- @generated:directory-map END -->
