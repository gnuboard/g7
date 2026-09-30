<!-- 자동 생성 문서입니다. 직접 고치지 마세요. -->

# 규정 준수 검증 (validate)

## 언제

- 사용자가 "검증", "규정 확인", "브랜치 체크", "이슈 N번 검증", "PR 전 검증" 을 지시할 때.
- 코드·레이아웃·마이그레이션·언어 파일을 고치고 완료를 보고하기 전, 커밋·PR 직전.
- 한 작업 단위가 여러 영역에 걸쳤으면 영역을 모두 넘기거나 `branch` 를 쓴다. 한 영역만 보고 통과를 단정하지 않는다.
- 계획서 대비 구현 완전성은 validate-plan 이 소유한다 — 같은 파일을 두 스킬이 다른 기준으로 판정하지 않는다. 커밋 위생(버전·CHANGELOG·커밋 계획)까지 필요하면 `/pre-commit-review`.

## 입력

`validate [area…]` — area 는 `backend` `frontend` `hook` `migration` `i18n` `branch` 중 하나 이상. 범위 인자는 `--working`, `--paths '<glob>'`, base ref(`origin/main`).

인자가 없으면 변경 파일에서 영역을 고른다. 모집단은 `git status --porcelain` 에 미커밋 변경이 있으면 워킹트리, 없으면 `git diff --name-only origin/develop...HEAD`.

| 변경 파일 | 영역 |
| --- | --- |
| `app/**/*.php` 전체(Services·Http·Repositories·DTO·Exceptions·Providers·Rules·Seo·Console·Middleware·Models·Enums·Events·Support·Listeners 포함), `{modules,plugins}/**/src/**/*.php` | backend |
| `**/Listeners/**`, 훅을 발행·등록하는 Service·Provider | hook (Listener 는 backend 도 함께 켠다 — listener 룰 6종은 `--tag backend` 에만 걸려 있다) |
| `**/database/migrations/**`, `**/upgrades/Upgrade_*.php`, `**/seeders/**` | migration |
| `lang/**`, `**/lang/**`, `lang-packs/**` | i18n |
| `**/layouts/**/*.{json,g7.html}`, `**/extensions/**/*.{json,g7.html}`, `**/*.tsx`, 핸들러·`resources/js/core/**` | frontend (`.g7.html` = 소스 SSoT, `.json` = 런타임 계약 — 동일 취급) |
| `resources/js/core/template-engine/**`, `resources/js/core/{TemplateApp,template-engine}.ts`, `resources/js/core/{auth,identity,support}/**` | engine (frontend 에 더해 `/engine-regression` 5단계 기록 · 엔진 CHANGELOG · SEO 렌더러 짝) |
| `module.json`·`plugin.json`·`template.json`·`config/app.php` | version-sync (보조 스크립트) |
| 사용자가 브랜치·이슈 전체를 지시 · 위 표가 2개 이상 영역을 켠 경우 | branch (전 영역) |

engine·plan·harness·version-sync 는 인자 없는 호출에서도 켜진다 — 처리 방법은 branch.md(아래 「branch」 절) 「분류」 표. 단일 영역으로 판정됐다는 이유로 엔진 CHANGELOG·버전 동기를 건너뛰지 않는다.

한 파일이 여러 영역에 속할 수 있다 — 겹치면 모두 켠다. 어느 영역에도 걸리지 않는 변경도 미분류 사유를 적고 audit 와 파일 유형 규정 대조는 수행한다.

## 절차

판정 원칙(의미 검토 1차 · 정적 분석 보조 · 미커버 영역 명시 · 설계 정합성 축 D1~D9 · 최소 범위)은 validation-principle.md, 부수의무는 good-faith-principle.md 를 따른다.

0. **규정 문서 읽기** — 선택된 영역마다 `references/<area>.md` 상단 「규정」 목록의 문서를 이 단계에서 전부 Read 한다. 목록은 참고 링크가 아니라 읽을 목록이다 — 스킬에 적힌 점검 항목은 하한이고, 규정 문서가 SSoT 다.
1. **대상 통독** — 선택된 영역의 변경 파일을 전부 직접 Read 하고 의미·신규 패턴(헬퍼·시그니처·응답 구조·훅 키)·유사 호출처·외부 영향·미등록 룰 영역을 메모한다. 이 메모가 1차 판정 근거다. 대상이 `.md` 계획서면 코드 블록 검증은 plan-code-blocks.md.
2. **audit (보조)** — 범위에 맞는 형태로 한 번 돌린다. 결과는 의미 검토를 반증·보강하는 신호이고 단독 결론 근거가 아니다.

   ```bash
   ```

   severity 를 `error` 로 한정하지 않는다 — frontend warn(`darkmode-class-pair` 등) 회귀 신호가 묵음 처리된다(warn). 초록은 "그 룰이 그 파일을 실제로 판정했다"를 뜻하지 않는다(초록). audit 가 덮는 범위는 하한이지 상한이 아니다(하한).
3. **영역별 의미 검토** — 영역이 여럿이면 backend → migration → hook → i18n → frontend 순서로 순차 적용한다(병렬 금지 — 결과 의존). 점검 항목·규정 문서·예시는 `references/<area>.md` 에 있다. 파일 유형별 규정 목록은 [AGENTS.md](../../../AGENTS.md#파일-유형별-규정-확인). 0단계에서 읽은 규정 문서에서 검증 패턴을 직접 추출한다 — 규정이 바뀌면 검증도 따라 바뀌어야 하므로 references 표로 규정을 대신하지 않는다.

   | 규정 문서에서 | 검증 유형 | 판정 |
   | --- | --- | --- |
   | `❌`·「잘못된」 코드 블록 | 금지 패턴 — `grep -rn "<패턴>" <대상>` 으로 검사 | 발견 시 error + 규정 절 안내 |
   | `✅`·「올바른」 코드 블록 | 필수 패턴 — 그 맥락에서 쓰여야 하는데 없는지 | 누락 시 warn + 올바른 예시 안내 |
   | `⚠️ 절대 금지`·`CRITICAL` 강조 규칙 | 필수 검증 항목 | 누락 없이 전부 판정 |
   | `TL;DR` 핵심 포인트 | 우선 검증 항목 | 가장 먼저 판정 |
4. **테스트** — 건드린 코드에 한해 `--filter` 로 좁혀 돌린다. 전체 스위트는 돌리지 않는다(범위).

   ```bash
   php artisan test --filter=<변경 클래스명>
   powershell -Command "npm run test:run -- <변경 영역>"
   ```

   실패는 「작성 → red → 수정 → green」으로 닫는다. 실행 규율과 명령 상세는 `/run-tests`.
5. **보고** — 아래 표로 낸다.

   | # | 영역 | 결과 | error/warn | 미커버 영역 | 근거 file:line |
   | --- | --- | --- | --- | --- | --- |
   | 1 | backend | 통과/위반/해당 없음 | N/M | 스크립트가 못 본 항목 | … |

   표에 이어 audit 룰별 위반 건수(`룰 id: error N / warn M`), D1~D9 판정표, 영역별 부수의무 결과, 이번 변경이 도입한 위반과 변경 파일 밖의 기존 위반을 분리해 적는다. 변경 파일 안의 위반은 선행 부채라도 이 세션에서 고친다(동시 수정).

   마지막 줄은 **최종 판정 3단** 중 하나와 사유다 — `✅ 커밋/PR 가능`(error 0, 의미 검토 위반 0, 테스트 green) · `⚠️ 조건부`(warn·실측 대기·사용자 결정 대기 항목이 남음 — 조건을 나열) · `❌ 차단`(error 또는 의미 검토 위반 잔존). 판정 없이 표만 내고 끝내지 않는다.

## 완료 조건

- 선택된 영역 전부에서 0~5 단계를 수행했고, 각 영역의 규정 문서와 대상 파일을 통독했다.
- 보고 마지막 줄에 최종 판정 3단과 룰별 건수가 있다.
- audit 를 범위에 맞게 돌려 error 와 warn 을 모두 봤고, 스크립트가 판정하지 못한 영역을 보고에 별도로 적었다.
- 위반마다 file:line 과 규정 출처가 있고, 수정했거나 사용자 결정으로 이월했다.
- D1~D9 판정표와 부수의무 점검 결과를 첨부했다. 등록 룰 위반 0 만으로 통과를 선언하지 않는다.
- 건드린 코드의 테스트가 green 이다.
- record 에 검사 범위(영역·모집단·비교 기준)·판정·남은 항목을 남겼다.

## 참조

- references/backend.md(아래 「backend」 절) — Controller/Service/Repository/FormRequest/Resource/DTO/Exception/Console, ResponseHelper, PHPDoc
- references/frontend.md(아래 「frontend」 절) — 레이아웃 JSON·컴포넌트 props·아이콘·이중 저장소·DevTools/WYSIWYG 연동
- references/hook.md(아래 「hook」 절) — 훅 네이밍·실행 순서·Listener·격리 원칙
- references/migration.md(아래 「migration」 절) — 컬럼 comment·down()·외래키·업그레이드 스텝
- references/i18n.md(아래 「i18n」 절) — 하드코딩·ko/en 동기·모듈 접두사·번들 언어팩
- references/branch.md(아래 「branch」 절) — 브랜치 범위 결정·카테고리 분류·보조 스크립트·통합 보고
- 원칙: validation-principle.md, good-faith-principle.md, [AGENTS.md](../../../AGENTS.md#파일-유형별-규정-확인)
- 관련 스킬: `/validate-plan`, `/pre-commit-review`, `/run-tests`, `/troubleshooting`, `/i18n sync`

---

## backend

`validate backend` 의 0·3단계에서 쓴다. 대상: `app/**/*.php` 전체(Services·Http·Repositories·DTO·Exceptions·Providers·Rules·Seo·Console·Middleware·Models·Enums·Events·Support·Listeners)와 확장의 `{modules,plugins}/**/src/**/*.php`. 계획서 안 PHP 코드 블록은 plan-code-blocks.md.

규정: service-repository.md · controllers.md · routing.md · validation.md · api-resources.md · dto.md · enum.md · exceptions.md · middleware.md · response-helper.md · service-provider.md · notification-system.md · broadcasting.md · core-config.md · console-confirm.md · permissions.md(Policy 사용 금지) · storage-driver.md · 요약 backend-misc.md. · SEO seo-system.md · Listener hooks.md.

파일 경로별로 우선 적용할 규정:

| 경로 | 우선 규정 |
| --- | --- |
| `Services/**`·`Repositories/**` | service-repository.md |
| `Http/Requests/**`·`Rules/**` | validation.md |
| `Http/Controllers/**` | controllers.md · routing.md |
| `Http/Resources/**` | api-resources.md |
| `**/DTO/**` | dto.md |
| `Http/Middleware/**` | middleware.md |
| `Providers/**` | service-provider.md |
| `Exceptions/**` | exceptions.md |
| `Console/Commands/**` | console-confirm.md |
| `Enums/**` | enum.md |
| `Seo/**` | seo-system.md |
| `**/Listeners/**` | hooks.md + backend-misc.md「Listener 데이터 접근」 |
| `Events/**`·브로드캐스트 | broadcasting.md |

### 자동 검출 룰 (`--tag backend`)

`phpdoc-method-contract`(구 phpdoc-public-method — 가시성 무관 + void 포함 PHPDoc, 이름별 @param/@return) · `formrequest-authorize-true` · `repository-interface-injection` · `service-direct-data-access`(Service 의 DB facade·Eloquent 직접 호출) · `service-validator-make` · `service-direct-throw-base-exception` · `response-helper-arg-order` · `no-storage-disk-direct` · `no-cache-facade-direct` · `hook-broadcast-direct` · `i18n-throw-hardcoded-korean` · `manifest-version-cross-sync` · Listener 계열(`listener-must-implement-hooklistenerinterface` · `listener-direct-db-facade` · `listener-savequietly-direct` · `listener-formrequest-bypass` · `listener-not-from-controller-service` · `activity-log-listener-i18n-coverage` · `filter-hook-type-marker`) — Listener 가 hook 영역으로만 분류돼도 `--tag backend` 를 함께 돌려야 이 룰들이 실행된다. 전체 목록은 `audit-branch.cjs --list-rules`.

훅 실행 순서 의도 · abilityMap 정확성 · DTO 패턴 선택 · 예외 계약은 룰이 보지 못한다 — 아래 항목으로 직접 판정한다.

### 계층별 의미 검토 항목

| 계층 | 확인 |
| --- | --- |
| Controller | Service 주입만(Repository 직접 주입 금지) · 검증은 FormRequest · 비즈니스 로직은 Service 위임 · 응답은 ResponseHelper. 권한은 permission 미들웨어 체인이고 Policy 는 쓰지 않는다 |
| FormRequest | `authorize()` 는 `return true` 고정 · `rules()` 는 배열 형식 · `exists`/`unique` 는 `Rule::exists(Model::class, 'col')` 형태 · `messages()` 는 `__()` 키. 검증을 프론트에만 두지 않는다(백엔드 검증) |
| Service | 생성자에 `RepositoryInterface` 주입(구체 클래스 타입힌트 금지) · 검증 로직(`Validator`/`validator()`/`request->validate`) 없음 · SPL 베이스 `Exception` 직접 throw 금지 · DB facade·Eloquent 직접 호출 금지 · 훅 순서는 hook.md(아래 「hook」 절) |
| Repository | Interface 구현 · 모델 주입 · 비즈니스 로직 없음(Service 로 이동) |
| API Resource | 커스텀 메서드에서 `$this->when()` 금지 — 삼항 연산자. 손으로 조립한 응답 블록은 shape 이 늘면 깨진다(조립) |
| Exception | 다국어 키 사용 · 응답 detail 노출 범위 확인(detail) |
| Middleware | 인증 미들웨어 전역 `append()` 금지 → `appendToGroup('api')`. `prepend` 는 스택 앞에 쌓인다(prepend) |
| ServiceProvider | DB 접근 전 `.env` 와 테이블 존재 확인. 코어 캐시는 컨테이너 바인딩에 기대지 않는다(캐시) · config 파일 리터럴은 실효값이 아니다(실효값) |
| Storage·Facade | `Storage::disk()` 직접 호출 금지 → `StorageInterface`. `\Log::info()`·`auth()->user()` 대신 `use Facades\Log;` 후 `Log::info()` |
| 삭제 경로 | DB CASCADE 단독 의존 금지 → Service 에서 명시적 삭제(훅·파일·로깅 보장). 상세는 migration.md(아래 「migration」 절) |
| Console Command | yes/no 프롬프트는 `HasUnifiedConfirm`/`ConsoleConfirm` 로 통일 |
| 로그아웃 | 토큰 삭제 → 세션 무효화 → `Auth::logout()` 3단계 |
| 공유 헬퍼 | 부수효과를 바꾸면 호출처를 전부 실측한다(호출처) · 계약을 넓히면 소비자도 넓힌다(확장) |

### ResponseHelper

메시지 키가 첫 인수다. 위치 인수로 `($data, $message)` 를 넘기면 키와 데이터가 뒤바뀐다.

```php
// ✅ 올바른 패턴
return ResponseHelper::success(
    data: new ProductResource($product),
    messageKey: 'messages.product.created'
);

// ❌ 잘못된 패턴
return response()->json(['data' => $product]);  // ResponseHelper 미사용
```

메시지를 한국어 리터럴로 넘기면 i18n 룰 위반이다 — 키만 넘긴다.

```php
// ✅ 키만 넘기는 형태
return ResponseHelper::success(
    messageKey: 'messages.product.created'
);
```

### Service 훅 예시

```php
class ProductService implements ProductServiceInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository
    ) {}

    public function create(array $data): Product
    {
        HookManager::doAction('sirsoft-ecommerce.product.before_create', $data);
        $data = HookManager::applyFilters('sirsoft-ecommerce.product.filter_create_data', $data);
        $result = $this->productRepository->create($data);
        HookManager::doAction('sirsoft-ecommerce.product.after_create', $result);

        return $result;
    }
}
```

### DTO

dto.md 는 두 패턴을 정의한다 — 한 패턴을 강제하지 않는다. VO 인지 Carrier 인지의 선택은 도메인 본질 판단이라 자동 판정 대상이 아니고, 아래 공통 규칙 위반만 적발한다.

- **Value Object**: `final readonly class` + constructor promotion + `toArray()`.
- **Data Carrier**: 일반 `class` + public mutable 속성 + 헬퍼 메서드 + `static fromArray()`.

| # | 항목 | 금지 | 올바름 |
| --- | --- | --- | --- |
| 1 | 외부 라이브러리 | `Spatie\LaravelData`, `Spatie\DataTransferObject` | 순수 readonly class + constructor promotion |
| 2 | 네이밍 | `XxxDto`·`XxxData`·`XxxObject` | 의미 단어만(`VerificationChallenge`, `OrderCalculationResult`) |
| 3 | 위치 | `app/Models/Dto/`, 도메인 분류 없는 임의 경로 | `app/{도메인}/DTO/`, `app/Extension/{영역}/DTO/`, `app/DTO/`, `{확장 루트}/src/DTO/` |
| 4 | 직렬화 | `toArray()` 누락 | 모든 DTO 가 `toArray(): array` 정의 |
| 5 | 검증 혼입 | 생성자 본문의 비즈니스 검증 | 검증은 FormRequest — DTO 는 타입힌트만 |
| 6 | ApiResource 중첩 | DTO 를 ApiResource 로 감싸거나 그 반대 | 모델 → ApiResource, 값 묶음 → DTO |

패턴 일관성 신호(경고 수준, 위반 아님): VO 에 `with()` 3개 이상 또는 `static fromArray()` 추가 → Carrier 전환 검토 · Carrier 인데 변형·헬퍼 0개 → VO 로 단순화 검토 · Carrier 인데 전 속성 readonly → 패턴을 하나로 결정(혼합 금지). 자동 분류가 안 되면 "패턴 식별 불가 — 사람의 판단 필요" 로 보고하고 dto.md 의 패턴 선택 기준 표를 안내한다. 강제 분류하지 않는다.

### PHPDoc

### 부수의무 (backend)

로직·데이터만 바꾸고 그것을 넣고 보여 주는 UI·다국어·검증·테스트·문서가 빠지지 않았는가. 같은 작업 단위가 프론트·마이그레이션·언어 파일까지 걸쳤는데 backend 만 보고 통과를 단정하지 않았는가. 테스트 변경은 매니페스트 변경 여부와 무관하게 소유 시나리오와 각 신규 테스트의 매핑을 확인한다. 검증 도구의 실행 실패·빈 결과·룰 미적용을 통과로 기록하지 않는다.

### 보고 항목

계층별 금지 패턴 위반 · 필수 패턴 누락 · ResponseHelper 인수 형태 · DTO 공통 규칙(패턴 식별 결과 포함) · PHPDoc · 테스트 결과 — 각각 통과/위반과 file:line, 그리고 규정 문서의 해당 절.

---

## branch

`validate branch [base]` 또는 인자 없는 `validate`(워킹트리)에서 쓴다. 이슈 브랜치·PR 직전 작업 단위 전체를 모든 영역에 대해 한 번에 본다. 커밋 위생(버전·CHANGELOG·누적 규율 기록·커밋 계획)은 `/pre-commit-review` 가 이 위에 얹는다.

### 범위 결정

| 모드 | 명령 | 모집단 |
| --- | --- | --- |
| 기본 | `validate branch` | `git diff --name-only origin/develop...HEAD` |
| base 지정 | `validate branch origin/main` | `<base>...HEAD` |
| 워킹트리 | `validate --working` / 인자 없음 | `git status --porcelain` (staged + unstaged + untracked) |
| 경로 | `validate branch --paths 'app/**,modules/**'` | glob |

미커밋 작업이 있으면 모집단은 워킹트리다 — HEAD 비교는 그것을 통째로 빠뜨린다(워킹트리). `origin/develop` 이 낡으면 게이트가 조용히 통과한다 — fetch 시점을 확인(낡은 ref). 배포 산출물은 git 추적 파일 밖(활성 확장 디렉터리)에도 있다(활성 디렉터리).

### 분류

SKILL.md 자동 선택 표의 카테고리 처리 방법이다. engine·plan·harness·version-sync 는 브랜치 검증 전용이 아니다 — 인자 없는 `validate` 가 단일 영역으로 판정돼도 해당 파일이 있으면 켠다.

| 파일 | 카테고리 | 처리 |
| --- | --- | --- |
| `module.json`·`plugin.json`·`template.json`·`config/app.php` | version-sync | 아래 보조 스크립트 |

활성 카테고리가 없는 변경도 미분류 사유를 적고 공통 audit 와 파일 유형 규정 대조를 수행한다. 어느 영역에도 해당하지 않는다는 이유로 검증을 끝내지 않는다.

### 순서

1. **전수 통독** — diff 또는 워킹트리 변경분을 직접 Read 해 작업 단위·신규 패턴·외부 영향을 메모하고, 영역 라우팅 결과를 그 메모와 교차 검토한다.
3. **영역별 의미 검토** — backend → migration → hook → i18n → frontend 순서로 각 `references/<area>.md` 를 순차 적용한다(병렬 금지 — 결과 의존). 각 영역은 변경 파일에 한정한다. 미활성 영역은 표에 「해당 없음」.
4. **보조 스크립트**

| 트리거 | 스크립트 |
| --- | --- |

5. **테스트** — 변경 클래스별 `php artisan test --filter=<클래스명>`, 프론트 `powershell -Command "npm run test:run -- <영역>"`. 통과는 완료의 필요조건이고, 의미 검토·필수 산출물·적용 범위의 미완료도 함께 판정한다. 실패는 「작성 → red → 수정 → green」으로 닫는다.
6. **통합 보고** — SKILL.md 5단계 표에 카테고리 행(backend/frontend/migration/i18n/hook/engine/plan/harness/보조 스크립트/테스트)을 채우고 audit 룰별 위반 건수를 붙이고, 각 영역의 미커버 영역과 부수의무 결과를 종합한다. 이번 브랜치가 도입한 위반과 변경 파일 밖의 기존 위반을 분리해 사용자가 회귀 차단과 별도 이슈를 결정하게 한다 — 단, 변경 파일 안의 위반은 선행이라도 이 세션에서 고친다. 마지막 줄은 최종 판정 3단(`✅ 커밋/PR 가능` · `⚠️ 조건부` · `❌ 차단`)과 사유·후속 조치다.

### 부수의무 (branch)

브랜치가 한 영역(예: 백엔드 로직)만 바꾸고 그 기능이 작동하는 데 필요한 다른 영역(입력/표시 UI·다국어·마이그레이션 백필·테스트·문서)이 빠지지 않았는가. 횡단 지시가 같은 도메인의 전 표면에 전수 적용됐는가(횡단). 각 영역의 부수의무 결과를 한 표로 모은다.

---

## frontend

`validate frontend` 의 0·3단계에서 쓴다. 대상: `resources/layouts/**/*.{json,g7.html}`, `templates/**/layouts/**/*.{json,g7.html}`, `{modules,plugins}/**/resources/layouts/**/*.{json,g7.html}`, `(templates|modules|plugins)/**/extensions/**/*.{json,g7.html}`(확장 주입 fragment), `templates/**/src/components/**/*.tsx`, 커스텀 핸들러, 엔진 범위(`resources/js/core/template-engine/**` · `resources/js/core/{TemplateApp,template-engine}.ts` · `resources/js/core/{auth,identity,support}/**`). 작성 SSoT 는 `.g7.html`, 짝 `.json` 은 그 굽기(`layout:decompile` = `.g7.html` → `.json`) 산출인 런타임·편집기 계약이라 동일 취급한다 — `.g7.html` 을 볼 때는 layout-markup.md(태그·속성 3분할·구조 태그·액션 top-level)가 1차 기준이고 `layout-json.md` 는 산출 JSON 키 계약이다. 마크업이 규정 위반인지 애매하면 `php artisan layout:decompile {path}` 로 짝 `.json` 을 구운 뒤 같은 규정으로 본다(역방향 `layout:compile` 은 `.json` → `.g7.html`). 계획서 안 JSON·TSX 코드 블록은 plan-code-blocks.md.

규정: layout-json.md · layout-json-features.md · layout-json-components.md · layout-json-inheritance.md · data-binding.md · data-binding-i18n.md · data-sources.md · actions.md · actions-handlers.md · state-management.md · components.md(인덱스: types/patterns/advanced) · component-props-composite.md · dark-mode.md · responsive-layout.md · modal-usage.md · layout-json-features-error.md · security.md · editors.md.

### 자동 검출 룰 (`--tag frontend`)

레이아웃 문법/구조 `layout-grammar` · `layout-html-lowercase-tag` · `layout-iteration-keys` · `layout-globalheaders-shape` · `layout-partial-no-state` · `layout-richtexteditor-deprecated` / 데이터 바인딩 `layout-binding-event-value` · `layout-binding-response-prefix` · `layout-binding-error-shape` · `layout-if-expression-wrap` · `layout-route-query-path` / 핸들러·액션 `layout-handler-deprecated` · `layout-handler-call-expression` · `layout-apicall-params-nesting` · `layout-refetchdatasource-params-key` · `layout-setstate-static-keys` / 컴포넌트 props `layout-form-button-type` · `layout-icon-classname-size` / 인증·보안 `auth-login-path-same-origin` · `auth-update-config-template-only` · `g7core-deprecated-api` / 스타일 `darkmode-class-pair`(warn).

`--severity error` 로 한정하지 않는다 — `darkmode-class-pair` 가 warn 이라 조용히 빠진다.

### 의미 검토 항목 (룰 미커버)

| 항목 | 확인 |
| --- | --- |
| 컴포넌트 props 실재 여부 | 레이아웃에서 쓴 컴포넌트명을 뽑아 `templates/{vendor-template}/src/components/{basic,composite,layout}/{Name}.tsx` 의 Props 인터페이스와 대조한다. 문서 대조는 소스 대조를 면제하지 않는다. 미등록 컴포넌트는 렌더되지 않는다(미등록) |
| 속성 정의 존재 | 레이아웃 최상위 필드·컴포넌트 정의 필드(`id`/`type`/`name`/`props`/`children`/`actions`/`text`/`iteration`/`if`/`slots`/`responsive`)·액션 정의 필드·데이터소스 필드가 규정에 있는 것인가. `if`·`actions` 는 `props` 가 아니라 노드 최상위다(구조 키) |
| 액션 흐름 의도 | 핸들러 조합이 의도한 순서로 도는가. `apiCall` 의 대상은 endpoint 가 아니라 target 이다(target) · `navigate` 는 path 가 필요하다(path) · 핸들러 catch 는 서버 검증 메시지를 표면화해야 한다(메시지) |
| 상태 경로 | `_local` 은 저장소 A(React `localDynamicState`)와 B(`_global._local`)에 이중 저장되고 B 가 정본이다. 한쪽만 갱신하는 경로는 예외도 콘솔 에러도 없이 화면과 요청이 다른 값을 보게 만든다 — 아래 절 참조. `await` 뒤에는 `G7Core.state.getLocal()` 로 재조회한다(stale) |
| 레이아웃 상속 | 부모·자식 `data_sources` ID 중복 금지, Partial 은 컴포넌트 치환만. 대상 레이아웃은 partial 을 따라가지 않는다(partial) · 서빙 경로가 DB 확장과 파일 플러그인 둘이다(두 경로) |
| 다국어 | 하드코딩 라벨·placeholder, 모듈 접두사 누락은 i18n.md(아래 「i18n」 절) |
| 다크모드·반응형 | 색상 클래스에 `dark:` 동반(warn 룰). show/hide·그리드 전환은 Tailwind 반응형 클래스보다 `responsive` 속성. iteration 노드의 responsive override 는 내부 wrapper 로 간다(override) |
| 화면 패리티 | 같은 도메인의 목록↔상세↔폼, 유저↔관리자, 모바일↔PC 가 함께 고쳐졌는가(목록 패리티 · 모바일) |
| Font Awesome | 아래 절 |
| TSX 등록 | 새 컴포넌트는 `index.ts` export · `components.json` 등록 · 테스트 파일 존재(매니페스트) |

### Font Awesome 아이콘

기준: fontawesome-free-icons.md(solid 1,390 · regular 163 · brands 472).

1. 레이아웃 JSON 의 `Icon` 컴포넌트 `props.name`, TSX 의 `<Icon name=…>`·`IconName` 매핑에서 아이콘명을 전부 뽑는다. `iconNameMap` 을 거치면 매핑된 결과값으로 검사한다.
2. Pro 전용 스타일은 오류: `fa-light`/`fal` · `fa-thin`/`fat` · `fa-duotone`/`fad` · `fa-sharp`/`fass`/`fasr`/`fasl`.
3. 스타일 검사만으로 끝내지 않는다 — `fa-solid fa-user` → 스타일 `solid` + `fa-user`, 단축형 `user` → `solid` 기본으로 파싱해 해당 스타일의 Free 목록에 실제로 있는지 대조한다. regular 는 163개뿐이라 나머지는 solid 로 대체한다. 없는 아이콘은 오류로 보고하고 유사 Free 아이콘을 제안한다.
4. `hidden` 클래스는 Font Awesome 의 display 에 진다(hidden).

### 이중 저장소(A/B) 쓰기 경로를 건드렸다면

대상: `ActionDispatcher.ts` · `DynamicRenderer.tsx` · `G7CoreGlobals.ts` · `FormContext.tsx`.

| 확인 | 방법 |
| --- | --- |
| 미러가 그 쓰기를 지배하는 분기 안에 있는가 | 형제 분기(`else`/`else if`)의 미러는 이 분기를 면죄하지 않는다 |
| 계약 테스트가 양방향 쌍인가 | `dual-store-mirror-contract.test.tsx` 의 `[이중저장소 A→B]` / `[이중저장소 B→A]` |
| 하네스가 실제 writer 를 거치는가 | `globalState._local` 을 손으로 채우면 결함이 시험에 등장하지 않는다 |

A 에만 있는 키와 그 B 소비자를 함께 본다(A 전용 키). 관련 사례: 트러블슈팅 40(B 통째 교체) · 41(B→A 축) · 42(A→B 축).

### 엔진 기능을 추가했다면

새 핸들러·컴포넌트 속성·바인딩 문법·데이터소스 옵션을 넣었으면 연동까지 본다.

| 항목 | 확인 위치 |
| --- | --- |
| DevTools 추적 | `resources/js/core/template-engine/devtools/G7DevToolsCore.ts` |
| WYSIWYG 편집 | `resources/js/core/template-engine/wysiwyg/components/PropertyPanel/*.tsx` — 위젯 규칙은 editor-widgets.md |
| 규정 문서 | `docs/frontend/*.md` 에 새 기능 문서화 |

### 런타임 확인

브라우저 상태 덤프가 가능하면 G7 DevTools MCP 로 먼저 실측한다 — `g7-state`(`_global`/`_local` 값) · `g7-expressions`(표현식 경고·잘못된 바인딩) · `g7-form`(바인딩 상태·누락 `name`) · `g7-actions`(핸들러 실행 오류). 코드 독해로 브라우저 실동작을 판정하지 않는다.

### 테스트

```bash
powershell -Command "npm run test:run -- ComponentName"
cd templates/sirsoft-admin_basic && powershell -Command "npm run test:run"
cd modules/_bundled/sirsoft-ecommerce && powershell -Command "npm run test:run"
```

Windows 에서는 PowerShell 래퍼로 돌린다. 레이아웃 렌더 테스트의 false green 주의(fragment).

### 부수의무 (frontend)

happy path 만 다루고 다크모드·반응형·빈/로딩/에러 상태·다국어를 빠뜨리지 않았는가. 같은 도메인의 다른 화면 패리티가 필요한데 한 화면만 고치고 통과를 단정하지 않았는가. 레이아웃 최상위에 새 속성을 넣었으면 `UpdateLayoutContentRequest` 등 백엔드 수용까지 갔는가.

### 보고 항목

룰 위반(error/warn 분리) · 컴포넌트 props 소스 대조 결과 · 미정의 속성·핸들러 · 아이콘 검증 · 이중 저장소 미러 · 엔진 연동(DevTools/WYSIWYG/문서) · 테스트 결과 — 각각 통과/위반과 file:line.

### Playwright E2E 동반 의무

프론트엔드(레이아웃 `.g7.html`·`.json`, 컴포넌트 TSX, 엔진 TS, 핸들러 TS) 변경은 Playwright spec 동반 추가·수정이 함께 요구된다. 단위 테스트(Vitest)만으로 완료를 선언하지 않는다 — 위지윅 발행 회귀 #238 은 단위 4건 green 상태에서 브라우저 재현으로만 드러났다.

자동 차단: audit 룰 `frontend-change-requires-e2e`(변경셋에 spec 미동반 시 error). 면제는 변경 파일 헤더의 `// e2e:allow <사유>` 또는 시나리오 매니페스트(`tests/scenarios/*.yaml`) 동반 갱신.

---

## hook

`validate hook` 의 0·3단계에서 쓴다. 대상: `**/Listeners/**/*.php`, 훅을 발행하는 `**/Services/**`, 훅을 등록하는 `**/Providers/**`, `{modules,plugins}/_bundled/**/src/{Services,Listeners}/**`. 계획서 안 훅 코드 블록은 plan-code-blocks.md.

규정: hooks.md(훅 타입·네이밍·리스너·큐 자동 실행·정적 캐시) · service-repository.md · plugin-development.md(격리 원칙) · Listener 데이터 접근은 backend-misc.md · 채널 네이밍은 broadcasting.md.

### 자동 검출 룰

룰이 보지 못하는 것 — 훅 실행 순서 의도, 채널 프리픽스 컨벤션, 리스너 등록 경로 — 은 아래 표로 직접 판정한다.

### 의미 검토 항목

| 항목 | 확인 |
| --- | --- |
| 네이밍 | `[vendor-module].[entity].[action]_[timing]`. `product.create`(vendor-module 누락)·`beforeCreateProduct`(형식 위반)는 오류 |
| 실행 순서 (Service) | before → applyFilters → 실제 동작 → after. 훅 없이 바로 실행하거나 순서가 뒤집히면 오류 |
| Listener | `HookListenerInterface` 구현 · `public static function getSubscribedHooks(): array` 가 `'훅.이름' => ['method' => …, 'priority' => …]` 맵을 반환(인터페이스 원본 `app/Contracts/Extension/HookListenerInterface.php`) · Filter 훅은 `'type' => 'filter'` 명시 · Filter 메서드는 값을 반환(`void` 금지) · `handle(...$args): void` 구현 |
| 리스너 등록 경로 | 코어 `app/Listeners/**` 는 `CoreServiceProvider` 가 재귀 스캔해 자동 등록한다. 모듈·플러그인 리스너는 자동 발견되지 않는다 — `module.php`/`plugin.php` 의 `getHookListeners()` 배열에 클래스가 올라 있는지 확인한다(빠지면 훅이 조용히 안 돈다). 규정: hooks.md · 모듈 등록 |
| 채널 프리픽스 | `HookManager::broadcast()` 채널과 `getChannels()` 키가 소스별 접두사를 따르는가 — 코어 `core.*` · 모듈 `module.{identifier}.*` · 플러그인 `plugin.{identifier}.*`. 다른 소스의 접두사를 쓰면 채널명 충돌·인증 우회가 생긴다 |
| 비동기 기본값 | Action 훅은 기본 큐 실행이고 래핑 주체는 `HookListenerRegistrar` 다(기본 비동기). 캐시 재생성 같은 인프라 부수효과를 즉시 반영해야 하면 sync 지정(sync) |
| 정적 캐시 | 확장 update 직후 그 확장의 신규 훅은 hooks.php 캐시에서 빠진다 — 재생성 여부 확인(캐시 누락) |
| 페이로드 | 새 훅 키·페이로드 구조·구독자 영향을 통독 메모에 적는다. 공개 훅 키/페이로드를 바꿨으면 의존 확장의 버전 제약 상향을 사용자에게 확인(의존 상향) |
| _bundled | 확장 수정은 `_bundled/` 에서만. 활성 `modules/{id}/`·`plugins/{id}/` 편집은 오류(_bundled) |
| 확장 간 협력 | 훅(코어 계약 훅·상대 확장이 문서화한 훅 키)으로 협력한다. `dependencies` 미선언 확장의 네임스페이스(`Modules\`·`Plugins\`) import·API 경로·번역 키 사용은 오류 — 책임 경계 |

### 예시

```php
// 발행 — 순서와 이름
HookManager::doAction('sirsoft-ecommerce.product.before_create', $data);
$data = HookManager::applyFilters('sirsoft-ecommerce.product.filter_create_data', $data);
$result = $this->productRepository->create($data);
HookManager::doAction('sirsoft-ecommerce.product.after_create', $result);

// 구독 — Filter 는 type 과 반환값
class ProductCacheListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.product.after_create' => ['method' => 'clearProductCache', 'priority' => 10],
            'sirsoft-ecommerce.product.filter_list_data' => ['method' => 'addCategoryInfo', 'priority' => 10, 'type' => 'filter'],
        ];
    }

    public function handle(...$args): void {}

    public function addCategoryInfo(array $data): array
    {
        return $data;
    }
}
```

### 부수의무 (hook)

훅 발행/구독만 추가하고 그 페이로드를 소비하는 구독자·다국어 라벨·활동 로그 키·테스트가 빠지지 않았는가. 발행과 구독은 양방향으로 Grep 해 확인한다 — 한쪽만 있으면 통과가 아니다.

### 보고 항목

훅 네이밍 · 실행 순서 · Listener 인터페이스 · Filter type 필드 · Filter 반환값 · 리스너 등록 경로 · 채널 프리픽스 · 격리/_bundled · `--tag backend` 룰별 건수 — 각각 통과/위반과 file:line.

---

## i18n

`validate i18n` 의 0·3단계에서 쓴다. 대상: `lang/{ko,en}/*.php`, `**/lang/**`, `**/resources/lang/**/*.json`, `templates/**/lang/*.json`, `lang-packs/_bundled/g7-*-{locale}/**`, 그리고 문자열을 표시하는 PHP·TSX·레이아웃 소스(`.g7.html`)와 런타임 계약(`.json`)(`{resources,templates/_bundled/*,modules/_bundled/*/resources,plugins/_bundled/*/resources}/{layouts,extensions}/**`). `.g7.html` 은 소스 SSoT 라 `$t:` 소비처로 `.json` 과 동일 취급한다. 계획서 안 코드 블록은 plan-code-blocks.md. 키 추출은 `/i18n extract`, 언어팩 동기화는 `/i18n sync`.

규정: database-guide.md 다국어 · validation.md · exceptions.md · module-i18n.md · data-binding-i18n.md · components-patterns.md(`G7Core.t`) · language-packs.md.

파일 유형별로 우선 적용할 규정:

| 파일 유형 | 우선 규정 |
| --- | --- |
| `lang/**/*.php`, 확장 `lang/{ko,en}/*.php` | database-guide.md 「다국어 (Backend)」 |
| `**/resources/lang/**/*.json`(모듈·플러그인) | module-i18n.md |
| `templates/**/lang/*.json`, 레이아웃 `$t:` | data-binding-i18n.md |
| `app/Http/Requests/**` | validation.md(`messages()` 키) |
| `app/Exceptions/**` | exceptions.md |
| `**/*.tsx` | components-patterns.md(`G7Core.t`) |
| `lang-packs/_bundled/**` | language-packs.md |

### 자동 검출 룰

### 의미 검토 항목

| 항목 | 확인 |
| --- | --- |
| 하드코딩 | 사용자에게 보이는 문자열은 PHP `__()`, 레이아웃 `$t:`/`$t:defer:`, TSX `G7Core.t()`. 라벨·placeholder·버튼 텍스트도 대상 |
| 키 위치 | 레이아웃 `$t:` 키는 프론트 JSON lang 파일이지 PHP `messages.php` 가 아니다(JSON). 프론트 핸들러가 백엔드 lang 경로를 부르면 키 문자열이 그대로 노출된다 — `G7Core.t()` 는 폴백이 없다(폴백) |
| 모듈 접두사 | 모듈 레이아웃은 `$t:{module-id}.admin.product.name`. `$t:common.xxx` 는 코어 키를 찾으므로 모듈 다국어와 매칭되지 않는다 |
| row 컨텍스트 | DataGrid/CardGrid `cellChildren` 의 row 참조는 `$t:defer:`(defer) · 동적 파라미터는 `textParams` 가 아니라 파이프 문법(파이프) |
| 로케일 목록 | `['ko', 'en']` 나열·`foreach (['ko', 'en'] …)` 금지 → `config('app.supported_locales')`. config 파일 리터럴은 실효값이 아니다(ServiceProvider 가 boot 에서 덮어쓴다 — 실효값). 표현식의 `context.$locale \|\| 'ko'` 폴백은 로케일 미전달을 ko 로 오역한다(폴백) |
| 시더·모델 | 다국어 필드는 `['ko' => …, 'en' => …]` 배열. 일괄/인라인 수정 API 의 per-locale 검증은 nullable(nullable) |
| 신규 i18n SSoT 블록 | `config/core.php`·`module.php`·`plugin.php` 에 새 블록을 넣었으면 언어팩 seed 대상인지 판단(seed) |

### 번들 언어팩 정합성

ko/en 키를 바꿨으면 대응 번들 팩(`lang-packs/_bundled/g7-*-{locale}/`)도 동기화한다. 빠지면 그 로케일 화면에 미번역 fallback 이 노출된다.

- 누락(ko/en 에 있고 번들에 없음) → 증분 빌드 · 잉여(번들에만 있음) → ko 에서 제거된 키의 잔류, 수동 제거 · 번들 파일 부재 → 빌드 누락 또는 디렉터리 구조 어긋남.
- 이 게이트는 ko↔대상 로케일 키 파리티를 보지 않는다 — 신규 키는 직접 대조(파리티). 기존 번역을 `--force` 로 날리지 않는다(증분).
- 매칭 4 scope: 코어 `lang/{ko,en}/*.php` ⇔ `g7-core-{locale}/backend/{locale}/*.php` · 모듈 backend `modules/_bundled/{mod}/{src,resources}/lang/{ko,en}/*.php`(union) ⇔ `g7-module-{mod}-{locale}/backend/{locale}/*.php` · 모듈 frontend `resources/lang/partial/{ko,en}/*.json` ⇔ `frontend/partial/*.json` · 플러그인 `plugins/_bundled/{pl}/lang/{ko,en}/*.php` ⇔ `g7-plugin-{pl}-{locale}/backend/{locale}/*.php` · 템플릿 `templates/_bundled/{tpl}/lang/{ko,en}.json` + `lang/partial/{ko,en}/*.json` ⇔ `g7-template-{tpl}-{locale}/frontend/{locale}.json` + `frontend/partial/*.json`.
- 미출시 언어팩은 키 추가가 있어도 CHANGELOG 항목을 만들지 않는다(미출시).

### 부수의무 (i18n)

ko 키만 추가하고 en·ja·번들 팩을 빠뜨리지 않았는가. 새 문구가 쓰이는 표면(레이아웃·알림·예외·검증 메시지·활동 로그) 전체에 키가 닿는가. 한 로케일만 보고 통과를 단정하지 않는다.

### 보고 항목

하드코딩(PHP/JSON/TSX 각각 file:line) · ko/en 동기(ko 에만/en 에만 있는 키 목록) · 모듈 접두사 · 로케일 하드코딩 · 시더 배열 · 번들 팩 동기(누락/잉여 건수).

---

## migration

`validate migration` 의 0·3단계에서 쓴다. 대상: `database/migrations/**`, `{modules,plugins}/**/database/migrations/**`, `**/upgrades/Upgrade_*.php`, `**/seeders/**`. 계획서 안 마이그레이션 코드 블록은 plan-code-blocks.md. 설계 단계는 `/plan-migration`.

규정: database-guide.md(마이그레이션·comment 와 FK 순서·멱등성·down()·외래 키 삭제 정책·시더·로케일) · upgrade-step-guide.md · extension-update-system.md · DB 규칙 요약 backend-misc.md.

### 자동 검출 룰 (`--tag migration`)

`migration-korean-comment`(up() 컬럼의 한국어 `->comment()`, 시스템 컬럼·FK constrained 면제) · `migration-down-implemented`(빈 down() 금지). 컬럼 타입 적합성·인덱스/유니크 정책·외래키 onDelete 의 도메인 적합성은 의미 검토다.

### 의미 검토 항목

| 항목 | 확인 |
| --- | --- |
| 파일명 | `create_products_table` · `add_price_and_stock_to_products_table` · `remove_category_from_products_table` 처럼 동작이 드러나야 한다. `update_products`·`products_migration` 은 오류 |
| 컬럼 comment | 모든 컬럼에 한국어 comment. boolean/enum 은 값 설명까지(`'활성화 여부 (true: 활성, false: 비활성)'`). FK 컬럼의 `->comment()` 는 `->constrained()`/`->references()` 앞에 둔다 — 뒤에 두면 FK 정의에 붙어 조용히 사라진다 |
| down() | 완전한 롤백(`Schema::dropIfExists` 또는 추가한 컬럼·인덱스 제거). 빈 본문은 오류 |
| 외래키 | `$table->foreignId('x')->constrained()` 형태. `unsignedBigInteger` + `->foreign()->references()->on()` 구식 문법은 오류 |
| CASCADE | `->onDelete('cascade')` 만으로 연관 데이터를 정리하면 훅·파일 정리·로깅이 빠진다. Service 에 명시적 삭제가 있는 경우에만 허용하고, 없으면 `restrict`/`set null` + Service 삭제 |
| 재실행 가드 | 멱등 가드를 컬럼에만 걸면 뒤따르는 인덱스·FK 단계에서 영구 실패(가드) |
| raw SQL | `DB::statement`·DB 종속 SQL 금지 — `Schema::table` + `->change()`(raw SQL · DB 종속) |
| 순회 갱신 | 필터 걸린 쿼리를 순회하며 update/delete 하면 `chunkById()`. `chunk()/each()/lazy()` 는 OFFSET 이라 처리된 행만큼 커서가 밀린다 |
| 로케일 | `['ko', 'en']` 직접 나열 금지 → `config('app.supported_locales')` — 상세는 i18n.md(아래 「i18n」 절) |
| 시더 | 다국어 필드는 배열(`'name' => ['ko' => …, 'en' => …]`). 로직 변경과 시더·팩토리 동기(시더) |

### 업그레이드 경로

기설치본은 마이그레이션이 재실행되지 않는다. 소스 교정만으로는 낫지 않으므로 상황별로 가른다.

| 상황 | 필요 작업 |
| --- | --- |
| 신규 테이블 | 마이그레이션만 |
| 기존 테이블 스키마 변경 | 마이그레이션 + `upgrades/` 의 `Upgrade_X_Y_Z` 클래스 (`X_Y_Z` = 대상 버전) |
| 기존 데이터 백필·변환 | 업그레이드 스텝만(마이그레이션 아님) |

### 부수의무 (migration)

컬럼만 추가하고 값을 채우는 백필·모델 cast·운영자 입력/표시 UI·확장 업그레이드 스텝이 빠지지 않았는가. 스키마 변경이 닿는 화면·시더·테스트·API 문서까지 같은 작업 단위에 있는가. 로컬 MySQL 은 `migrate:fresh` 의 DDL 에서 죽을 수 있다(OOM).

### 보고 항목

파일명 · 컬럼 comment(boolean/enum 값 설명 포함) · down() · 외래키 문법 · CASCADE 정책 · 업그레이드 스텝 필요 여부 — 각각 통과/위반과 file:line.
