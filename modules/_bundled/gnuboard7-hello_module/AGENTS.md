# Hello 모듈 — 에이전트 가이드

> 이 문서는 이 모듈을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 모듈 (gnuboard7-hello_module) — 학습용 최소 샘플. 메모(Memo) 하나로 모듈의 전 계층을 1파일씩 시연한다. 실제 업무 기능 없음, `hidden: true`
2. 확장 방식: 발행 훅 1개(`memo.created`) — `gnuboard7-hello_plugin` 이 그것을 구독하고, `gnuboard7-hello_user_template` 은 공개 API 를 소비한다
3. 건드리면 안 되는 것: 샘플에 기능 추가(짧게 유지), `hidden` 제거, 검증을 Service 에 넣기, Repository 구체 클래스 주입 — 샘플은 규약의 본보기다
4. 작업 위치: `modules/_bundled/gnuboard7-hello_module` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan module:update gnuboard7-hello_module --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
**학습용 최소 샘플 모듈**입니다. 실제 업무 기능을 제공하지 않으며, 모듈이 필요로 하는
계층을 **하나씩만** 담아 "모듈은 이런 모양이다" 를 보여주는 것이 유일한 목적입니다.

도메인은 메모(Memo) 하나이고 필드는 셋뿐입니다. 그 위에 Model · Migration · Factory ·
Seeder · Repository(인터페이스 + 구현) · Service · FormRequest · Resource · Controller ·
Listener · Layout · Test · 다국어(백엔드 PHP + 프론트 JSON)가 각 1개씩 있습니다. 실제
모듈은 이 계층을 엔티티 수만큼 늘린 것입니다.

**설계 원칙: 짧게 유지한다.** 샘플의 가치는 완결성이 아니라 **한눈에 읽히는 것**입니다.
여기에 기능을 더하면 계층 구조를 보러 온 사람이 도메인 로직을 읽게 되므로, 새 기능이
필요하면 이 샘플이 아니라 별도 확장을 만듭니다.

`manifest.hidden = true` 라 관리자 UI 의 모듈 목록에 나타나지 않습니다. artisan CLI 로는
정상 설치·활성화됩니다 — 학습용이 운영 화면에 섞이지 않게 하면서도 실제로 동작해 봐야
학습이 되기 때문입니다.

**의도적으로 하지 않는 것**: 검색 색인·SEO·알림·스케줄·미들웨어·브로드캐스트. 각 축의 사용법은
그것을 실제로 쓰는 확장(게시판·이커머스)의 문서가 다룹니다.
<!-- @intent END -->

## 2. 디렉토리 지도

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

## 3. 핵심 흐름

<!-- @intent START -->
계층 하나씩을 지나는 **가장 짧은 CRUD** 가 이 샘플의 전부입니다.

**메모 생성**: `Admin\MemoController::store()` → `MemoRequest`(검증) →
`MemoService::create()` → `MemoRepositoryInterface`(인터페이스 주입) → `MemoRepository` →
`Memo` 모델. 저장 직후 `gnuboard7-hello_module.memo.created` 액션 훅을 발행하고,
`LogMemoCreatedListener` 가 그것을 받아 로그를 남깁니다.

이 한 흐름에 G7 모듈의 규약이 전부 들어 있습니다:

- 검증은 Service 가 아니라 **FormRequest** 에 둔다
- Service 는 구체 Repository 가 아니라 **인터페이스**를 주입받는다
- 부가 작업(로그·알림·집계)은 Service 안이 아니라 **훅 리스너**로 뺀다
- 응답 형태는 컨트롤러가 조립하지 않고 **Resource** 가 정한다

**같은 모듈이 자기 훅을 구독하는 것**도 의도된 예시입니다. 실제로는 다른 확장이 구독하지만,
샘플 하나만 설치해도 훅 흐름이 눈에 보이게 하려고 리스너를 같이 넣었습니다 —
`gnuboard7-hello_plugin` 을 함께 설치하면 **바깥에서 구독하는** 모습도 볼 수 있습니다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 1개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 1개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 1개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 0개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 0개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 0개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 0개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
발행 훅 하나(`memo.created`)가 전부입니다. 실제 모듈이라면 도메인마다
`before_*` → `filter_*_data` → `after_*` 3단을 두지만, 샘플에서는 **훅이 무엇인지**만
보이면 되므로 하나로 줄였습니다.

`gnuboard7-hello_plugin` 이 이 훅을 구독합니다. 두 샘플을 함께 설치하면 "모듈이 발행하고
플러그인이 받는" 확장 시스템의 기본 관계를 실제로 확인할 수 있습니다 — 플러그인이 그
모듈에 `dependencies` 로 묶여 있는 것도 그 관계의 표현입니다.

`gnuboard7-hello_user_template` 도 이 모듈에 의존합니다. 그쪽은 훅이 아니라 **공개 API 를
`data_sources` 로 소비**하는 관계이며, 모듈이 데이터를, 템플릿이 화면을 담당하는 경계를
보여줍니다.

미들웨어·브로드캐스트 채널·스케줄·알림은 없습니다. 샘플에 넣으면 계층 구조를 보러 온 사람이
읽어야 할 코드가 늘어납니다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan module:update gnuboard7-hello_module --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 스키마 변경 시 마이그레이션(한국어 comment + `down()`) + 기설치본 백필용 업그레이드 스텝
- [ ] 발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=module:gnuboard7-hello_module` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃 JSON 변경 시 빌드 없이 update 만 — 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] 계층을 늘리기 전에 "이것이 샘플에 필요한가" 를 먼저 묻는다 — 샘플의 가치는 한눈에 읽히는 것이다
- [ ] `manifest.hidden = true` 를 유지 (복제본에서만 제거)
- [ ] 규약(FormRequest 검증 · Repository 인터페이스 주입 · 훅으로 부가작업 분리)이 흐트러지지 않았는지 확인 — 이 코드는 본보기로 읽힌다
- [ ] `docs/extension/sample-extensions.md` 의 계층 표와 어긋나지 않는지 확인 (파일을 추가·삭제했다면 그 표도 갱신)
- [ ] 발행 훅 이름을 바꾸면 `gnuboard7-hello_plugin` 의 구독이 조용히 끊긴다

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| 이 샘플에 기능을 더해 "쓸모 있게" 만들기 | 짧게 유지하고, 필요한 기능은 별도 확장으로 | 샘플의 가치는 한눈에 읽히는 것이다. 계층을 보러 온 사람이 도메인 로직을 읽게 되면 목적이 사라진다 |
| `manifest.hidden` 을 제거 | 그대로 둔다 (복제본에서만 제거) | 학습용 모듈이 운영 사이트의 모듈 목록에 섞인다 |
| 복제해 새 모듈을 만들면서 `hidden` 을 남겨 두기 | 복제본에서는 제거하거나 `false` | 새 모듈이 관리자 UI 에 나타나지 않는다 |
| 복제 후 식별자·네임스페이스를 부분만 치환 | `gnuboard7-hello_module` · `Gnuboard7\HelloModule` · `hello_module` · `Memo` 계열을 **전부** 치환 | 남은 옛 이름이 오토로드 실패나 테이블 이름 충돌로 나타난다 |
| 검증 로직을 `MemoService` 에 넣기 | `MemoRequest` (FormRequest) | 샘플이 잘못된 본을 보이면 그것을 따라 한 모듈이 전부 같은 형태가 된다 |
| `MemoService` 가 `MemoRepository` 구체 클래스를 타입힌트 | `MemoRepositoryInterface` | 위와 같은 이유 — 샘플은 규약의 본보기다 |
| 훅 발행 없이 Service 안에서 로그·알림을 직접 수행 | 훅 발행 + 리스너 | 부가 작업이 Service 에 쌓이면 그 Service 를 재사용할 수 없다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 3개 | `modules/_bundled/gnuboard7-hello_module/tests` |
| Vitest | 0개 | — |
| Playwright | 0개 | — |
| 시나리오 매니페스트 | 0개 | — |

기저 TestCase: `tests/ModuleTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit modules/_bundled/gnuboard7-hello_module/tests --filter='<대상클래스>'

```

무필터 전체 실행은 금지되어 있습니다 — 변경 범위에 걸리는 대상만 지정해 실행합니다.
<!-- @generated:test-commands END -->

## 8. 문서 목차

<!-- @generated:docs-index START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 문서 | 내용 | 상태 |
|---|---|---|
| [docs/README.md](docs/README.md) | 문서 통합 목차와 실측 집계 | ✅ |
| [docs/architecture.md](docs/architecture.md) | 설계 의도·계층 지도·디렉토리 맵 | ✅ |
| [docs/extension-points.md](docs/extension-points.md) | 발행/구독 훅·미들웨어·채널·스케줄 | ✅ |
| [docs/data-model.md](docs/data-model.md) | 모델·소유 테이블·마이그레이션·Enum | ✅ |
| [docs/settings.md](docs/settings.md) | 설정 스키마·권한·메뉴·라우트·의존 관계 | ✅ |
| [docs/frontend.md](docs/frontend.md) | 레이아웃·액션 핸들러·전역 진입점·에셋 | ✅ |
| [docs/api/](docs/api/README.md) | API 레퍼런스 (엔드포인트별 파라미터·응답 필드) | ✅ |
| [CHANGELOG.md](CHANGELOG.md) | 변경 이력 | ✅ |
<!-- @generated:docs-index END -->
