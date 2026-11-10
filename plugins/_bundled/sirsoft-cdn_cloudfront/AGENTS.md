# 그누보드7 CloudFront CDN 플러그인 — 에이전트 가이드

> 이 문서는 이 플러그인을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 플러그인 (sirsoft-cdn_cloudfront) — CloudFront CDN 디스크 정의와 썸네일 캐시 무효화
2. 확장 방식: 코어 훅 core.thumbnail.after_purge · core.settings.available_public_asset_drivers · core.plugin_settings.update_validation_rules 구독, 디스크 동기화는 core.plugin_settings.after_save · after_reset · core.plugins.after_deactivate (켜진 채 제거도 코어가 먼저 끄므로 이 훅이 맡는다)
3. 건드리면 안 되는 것: 무효화 실패를 예외로 던지기, S3 자격으로 무효화, 비활성일 때 디스크 정의
4. 작업 위치: `plugins/_bundled/sirsoft-cdn_cloudfront` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan plugin:update sirsoft-cdn_cloudfront --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
CloudFront 를 그누보드7 썸네일, 공개 자산의 CDN 으로 쓰게 하는 플러그인이다. 두 가지만 한다.

1. **디스크 정의** — 서비스 프로바이더가 활성일 때만 `filesystems.disks.cloudfront` 를 코어 S3 디스크 설정 복제 + `url` = CDN 주소로 정의하고, 리스너가 필터 `core.settings.available_public_asset_drivers` 에 `{id:'cloudfront', label, provider}` 를 올린다. 코어는 이 카탈로그 하나로 공개 썸네일 디스크, 공개 자산 디스크 선택지를 만든다.
2. **캐시 무효화** — 액션 `core.thumbnail.after_purge`(코어가 요청, 작업 단위로 묶어 한 번 발화) 를 받아 CDN 호스트를 가리키는 경로만 골라 `CreateInvalidation` 을 한 번 보낸다. 실패는 `cloudfront_purges` 대기열 → 5분마다 재시도(최대 5회, 지수 대기).

설계 원칙: 무효화 자격은 S3 자격과 분리(비면 무효화 안 함), CDN 장애가 사용자 작업(삭제 요청)을 실패로 만들지 않음(예외를 밖으로 내지 않음), 꺼지면 디스크가 사라져 코어의 고아 디스크 폴백을 그대로 탄다.

하지 않는 것: 권한 이미지(게이트 원본) 썸네일, 서명 URL, 변환 서비스 위임(코어 필터 `core.thumbnail.filter_url` 몫), 코어 설정 API 변경.
<!-- @intent END -->

## 2. 디렉토리 지도

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `plugin.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `plugin.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Http/Controllers/` | 컨트롤러 | API 표면 변경 시 `api:docgen` 재실행 |
| `src/Http/Requests/` | FormRequest (검증 SSoT) | 검증 규칙은 Service 가 아니라 여기에 둔다 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Repositories/` | 데이터 접근 | 목록 쿼리는 컬럼 프루닝·정렬 화이트리스트 확인 |
| `src/Models/` | Eloquent 모델 | 스키마 변경 시 마이그레이션 + 업그레이드 스텝 동반 |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `database/migrations/` | 마이그레이션 | 한국어 comment + `down()` 필수, 기설치본은 업그레이드 스텝으로 백필 |
| `resources/layouts/` | 레이아웃 소스 `.g7.html` (짝 `.json` 은 굽기 산출. 컴포넌트 종류는 `data-type`, 컴파일은 템플릿과 무관) | `php artisan plugin:update sirsoft-cdn_cloudfront --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-cdn_cloudfront --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-cdn_cloudfront --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 작성자가 읽는 props 계약) | `php artisan plugin:update sirsoft-cdn_cloudfront --force` |
| `assets/icon.svg` | 확장 아이콘 (관리자 목록·설치 마법사 카드) | 정사각·100KB 이하·스크립트 없는 SVG — 교체 후 `php artisan plugin:update sirsoft-cdn_cloudfront --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->

## 3. 핵심 흐름

<!-- @intent START -->
1. **무효화** — 코어 `ThumbnailStore::purgePrefix()` → `ThumbnailPurgeBuffer` 가 모은 항목을 요청 끝에 `core.thumbnail.after_purge` 로 발화 → `PurgeThumbnailsListener::purge()` → `PurgeService::purgeItems()` → `InvalidationPaths::fromItems()`(와일드카드, 원본 경로(디렉터리째 삭제면 와일드카드), 호스트 필터, 요청당 와일드카드 10개를 넘으면 디렉터리만 공통 상위로 접기) → `CloudFrontClientFactory::make()->createInvalidation()`. 실패 시 `PurgeQueueRepositoryInterface::create()`(첫 시도의 호출자 참조 `caller_reference` 를 함께 저장, 대기열 쓰기 실패는 `Log::warning` 만).
2. **재시도** — 스케줄 `retry-purges`(5분) → `RetryPurgesCommand` → `PurgeService::retryDue()` → `PurgeQueueRepository::due()` → 시각이 된 건들의 경로를 `InvalidationPaths::fold()` 로 합쳐 한 번만 재전송(한 건이면 첫 시도와 같은 CallerReference `g7-{caller_reference}`, 여러 건이면 참조들로 정해지는 `g7-merge-{sha1}`) → 성공은 삭제, 실패는 `recordFailure()`(5회면 next_attempt_at=null). `TooManyInvalidationsInProgress`, Throttling 류는 시도 횟수를 올리지 않고 5분 뒤로 미룬다.
3. **설정 화면 보조 API** — `GET admin/purge-status`(core.plugins.read) → `CloudFrontAdminController::purgeStatus()` → `PurgeService::status()`. `POST admin/test-connection`(core.plugins.update) → `PurgeService::testConnection()` → `GetDistribution`.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 0개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 6개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 4개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 0개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 0개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 1개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 0개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
이 플러그인은 훅을 발행하지 않는다. 동작을 바꾸려면 코어 훅을 잡는다 — 무효화 대상 항목은 코어 `core.thumbnail.after_purge` 를 함께 구독해 볼 수 있고, 썸네일 주소 자체를 바꾸려면 코어 `core.thumbnail.filter_url` 을 쓴다. 디스크 카탈로그 항목은 같은 필터(`core.settings.available_public_asset_drivers`)를 더 늦은 우선순위로 잡아 고칠 수 있다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan plugin:update sirsoft-cdn_cloudfront --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 스키마 변경 시 마이그레이션(한국어 comment + `down()`) + 기설치본 백필용 업그레이드 스텝
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=plugin:sirsoft-cdn_cloudfront` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃은 `.g7.html` 을 고치고 `php artisan layout:decompile <경로> --sync` 로 짝 `.json` 을 다시 굽는다 (짝을 굽지 않으면 화면에 반영되지 않음) — 빌드 없이 update 만, 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] 코어 퍼지 항목 모양(`core.thumbnail.after_purge`)이 바뀌면 `InvalidationPaths` 와 시험을 함께 고친다
- [ ] 자격 증명·버킷명·배포 ID 실값을 코드·시험·문서 어디에도 쓰지 않는다(시험은 `example.test`·가짜 처리기)

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| 서비스 프로바이더 `boot()` 에서 바로 디스크를 정의 | `booted()` 콜백 + 활성 플러그인 확인 | 코어 S3 설정이 부팅 중에 채워지고, 프로바이더는 비활성 플러그인도 등록된다 |
| 무효화 실패를 예외로 던짐 | 대기열에 넣고 `Log::warning` | 코어가 삭제 요청 안에서 훅을 부른다 — CDN 장애가 게시글 삭제를 실패로 만든다 |
| S3 자격으로 무효화 | 무효화용 키를 따로 받고 비면 보내지 않는다 | 업로드 권한과 배포 무효화 권한을 한 키에 몰지 않는다 |
| 항목마다 CreateInvalidation | 묶음 하나에 한 번 | 요청당 요금, 와일드카드 동시 진행 한도(배포 전체 합계 15) |
| 재시도를 대기 건마다 따로 보냄 | 시각이 된 건을 합쳐 한 번 | 진행 중 합계 한도라 앞 건들이 한도를 채우면 나머지가 거절돼 시도 횟수만 쓴다 |
| 한도 접기에 원본 정확 경로를 포함 | 디렉터리 와일드카드만 접는다 | 파생본 트리와 원본 트리의 공통 상위가 `/` 라 배포 전체를 지운다 |
| 무효화 경로를 `rawurlencode` 로 인코딩 | RFC 1738 unsafe 문자와 비 ASCII 만 | 괄호, `+` 까지 인코딩하면 캐시된 경로와 달라 무효화가 안 된다 |
| 오류 문구에 요청 서명, 자격 저장 | AWS 오류 코드, 메시지만 | 설정 화면에 그대로 보인다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 6개 | `plugins/_bundled/sirsoft-cdn_cloudfront/tests` |
| Vitest | 5개 | `vitest.config.ts` |
| Playwright | 0개 | — |
| 시나리오 매니페스트 | 9개 | `tests/scenarios` |

기저 TestCase: `tests/PluginTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit plugins/_bundled/sirsoft-cdn_cloudfront/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd plugins/_bundled/sirsoft-cdn_cloudfront && powershell -Command "npm run test:run -- <대상>"

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
| [docs/editor-spec.md](docs/editor-spec.md) | 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 | ✅ |
| [docs/api/](docs/api/README.md) | API 레퍼런스 (엔드포인트별 파라미터·응답 필드) | ✅ |
| [CHANGELOG.md](CHANGELOG.md) | 변경 이력 | ✅ |
<!-- @generated:docs-index END -->
