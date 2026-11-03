# 그누보드7 히어로섹션 플러그인 — 에이전트 가이드

> 이 문서는 이 플러그인을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 플러그인 (sirsoft-hero_section) — 사이트 첫 화면 히어로섹션(메인 배너)의 데이터·관리 화면·사용자 슬라이더 소유
2. 확장 방식: 템플릿은 확장점 `home_hero`·`shop_hero` 를 두면 자동으로 붙고, 디자인은 `HeroSlider` 컴포넌트 오버라이드로 바꾼다
3. 건드리면 안 되는 것: 노출 상태를 저장하거나 PHP 에서 거르기(조회 시점 SQL 판정이 SSoT) · Repository 밖 쿼리 · 템플릿 소유 화면 직접 수정
4. 작업 위치: `plugins/_bundled/sirsoft-hero_section` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan plugin:update sirsoft-hero_section --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
운영자가 개발자 도움 없이 사이트 홈·쇼핑몰 메인 상단 배너를 바꾸고 기간에 맞춰 예약 노출하게 하는 플러그인이다(#810).

- **소유 경계**: 배너 데이터·관리 화면·사용자 슬라이더 컴포넌트는 이 플러그인이 소유한다. 사용자 템플릿은 자리(확장점)만 연다 — sirsoft-basic 은 `home_hero`(사이트 홈 상단)·`shop_hero`(쇼핑몰 메인 상단). 같은 이름의 확장점을 둔 템플릿이면 어디서든 붙는다. 플러그인을 끄면 확장점 주입이 빠져 즉시 사라진다.
- **위치 목록**: 플러그인이 고정 목록으로 갖고(위치 키 = 확장점 이름), 템플릿이 선언하는 방식은 쓰지 않는다.
- **노출 상태**(노출중·예약·종료·비활성)는 저장하지 않는다. 조회 시점에 기간·사용 여부로 계산하고(cron 없음), 목록 필터·정렬과 공개 노출은 같은 SQL 조건을 쓴다. 시간은 UTC 로 저장·비교하고 사이트 시간대 변환은 입력·표시에서만 한다.
- **다국어**: 제목·설명·버튼 문구·대체 텍스트를 언어별로 저장하고, 비운 언어는 사이트 기본 언어로 대체한다.
- **미디어**: 배너마다 이미지·유튜브·영상 파일(`media_type`). PC 이미지는 어느 종류든 대표 이미지로 필수다. 유튜브는 영상 ID 만 저장하고 사이트는 `youtube-nocookie.com` 을 방문자 마케팅 동의(코어 `G7Core.consent`) 뒤에만 부른다. 영상 파일은 이미지 테이블의 `kind=video` 행이고 공개 응답은 범위 요청을 받는다.
- **의도적으로 하지 않는 것**: 노출수·클릭수 통계(2차), 관리자별 소유 범위, 휴지통(삭제는 영구), 이커머스 「메인배너 관리」 시안 정리(별도 이슈).
<!-- @intent END -->

## 2. 디렉토리 지도

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

## 3. 핵심 흐름

<!-- @intent START -->
배너 관리 API·공개 API·활동 로그·공개 캐시, 관리자 화면 3개(목록·등록/수정·환경설정), 사용자 슬라이더와 확장점 주입 조각(`home_hero`·`shop_hero`)이 있다. 대표 흐름:

1. 배너 등록: `BannerController@store` → `StoreBannerRequest`(기본 언어 제목·기간·링크·PC 이미지) → `BannerService::create`(before/after 훅, 위치 맨 뒤 순서, 임시 이미지 연결) → `BannerRepositoryInterface` → `Banner`.
2. 사용자 화면 노출: 확장점 조각 data-source → `HeroController@show` → `PublicHeroService::forPosition`(캐시, 꺼낼 때 기간 재판정) → `BannerRepository` 상태 조건 → 언어 대체 후 응답.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 23개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 24개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 5개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 2개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 0개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 1개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 0개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
- 템플릿: 확장점 `home_hero`·`shop_hero` 를 두면 붙는다. 디자인은 `HeroSlider` 오버라이드로 바꾼다(props 계약은 docs/extension-points.md).
- 다른 확장: `sirsoft-hero_section.banner.*` 훅으로 저장 전후에 끼고, `sirsoft-hero_section.hero.filter_public_banners` 로 노출 목록을 가공한다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan plugin:update sirsoft-hero_section --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=plugin:sirsoft-hero_section` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃은 `.g7.html` 을 고치고 `php artisan layout:decompile <경로> --sync` 로 짝 `.json` 을 다시 굽는다 (짝을 굽지 않으면 화면에 반영되지 않음) — 빌드 없이 update 만, 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] 노출 상태 판정을 바꾸면 모델 접근자·Repository 조건·공개 캐시 만료 계산을 함께 고치고 경계 시각 테스트를 돌린다
- [ ] 확장점 이름(`home_hero`·`shop_hero`)은 다른 템플릿이 쓰는 공개 계약 — 바꾸지 않는다(바꿔야 하면 새 이름 추가 + 옛 이름 유지)

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| 노출 상태를 컬럼에 저장하거나 목록을 받은 뒤 PHP 에서 상태로 거르기 | Repository 의 기간 조건 하나로 필터·정렬·공개 노출을 모두 처리 | 저장값은 cron 없이는 낡고, PHP 필터는 총건수·쪽 수를 어긋나게 한다 |
| Service·Controller 에서 `Banner::query()` | RepositoryInterface 메서드 | 계층 규칙(#787 메인테이너 지적) |
| 공개 캐시 만료를 설정 시간으로만 잡기 | min(설정 시간, 다음 시작·종료 시각) + 꺼낼 때 기간 재판정 | 예약 배너가 시작 뒤에도 안 보이거나 종료 뒤에도 보인다 |
| 링크 검증에서 `/` 시작을 모두 내부 경로로 보기 | `//`·`/\` 시작은 거부 | 프로토콜 상대 주소는 외부 사이트로 샌다 |
| `Storage::disk()` 직접 호출 | `StorageInterface`(플러그인 스토리지 드라이버) | 확장 격리·경로 안전 규칙 |
| 운영자가 넣은 링크 주소(`link_url`)를 그대로 `href` 에 넣기 | `resources/js/apiUrl.ts` 의 `sitePath()` 로 사이트 마운트 접두사를 붙인다(멱등, `http(s)://` 바깥 주소는 그대로) | 하위 폴더 설치(`https://host/g7/`)에서 `/shop` 이 사이트 밖으로 간다. `apiUrl.ts` 는 번들 확장 공통 바이트 동일 사본이다 |
| `ini_get`·`glob` 처럼 호스팅이 막을 수 있는 함수를 바로 부르기 | `ExecutionEnvironment::hasFunction()` 으로 먼저 판정하고 대체 경로를 둔다(`UploadLimit::iniSettingBytes()`) | PHP 8 은 막힌 함수를 함수 표에서 지워 호출이 `Error` 다. `@` 는 가드가 아니다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 20개 | `plugins/_bundled/sirsoft-hero_section/tests` |
| Vitest | 13개 | `vitest.config.ts` |
| Playwright | 16개 | `tests/Playwright` |
| 시나리오 매니페스트 | 17개 | `tests/scenarios` |

기저 TestCase: `tests/PluginTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit plugins/_bundled/sirsoft-hero_section/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd plugins/_bundled/sirsoft-hero_section && powershell -Command "npm run test:run -- <대상>"

# Playwright E2E (확장 디렉토리에서) (Bash)
cd plugins/_bundled/sirsoft-hero_section && npm run test:e2e -- specs/<대상>.spec.ts

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
