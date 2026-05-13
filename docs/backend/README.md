# 백엔드 개발 가이드

> 그누보드7 백엔드 개발을 위한 종합 가이드입니다.

---

## 핵심 원칙

```text
필수: FormRequest + Custom Rule 사용 (Service에 검증 로직 금지)
필수: FormRequest + Custom Rule 패턴 사용
✅ 필수: __() 함수를 사용한 다국어 처리
✅ 필수: 상태/타입/분류는 Enum으로 정의
```

---

<!-- AUTO-GENERATED-START: backend-readme-docs -->
## 문서 목록

| 문서 | 제목 | 핵심 내용 |
|------|------|----------|
| [activity-log-hooks.md](activity-log-hooks.md) | 활동 로그 훅 레퍼런스 (Activity Log Hooks Reference) | 코어 66훅 + 이커머스 92훅 + 게시판 32훅 + 페이지 8훅 = 총 198훅 |
| [activity-log.md](activity-log.md) | 활동 로그 시스템 (Activity Log System) | Monolog 기반: Service 훅 → Listener → Log::channel... |
| [admin-settings-access.md](admin-settings-access.md) | Admin 환경설정 값 접근 (`g7_core_settings` vs `config()`) | 동기화 SSoT: storage/app/settings/*.json → Setting... |
| [api-resources.md](api-resources.md) | API 리소스 | Resource: BaseApiResource 상속 필수 / Collection: B... |
| [authentication.md](authentication.md) | 인증 및 세션 처리 | Laravel Sanctum 토큰 전용 인증 (Bearer 토큰만 사용) |
| [broadcasting.md](broadcasting.md) | Broadcasting (실시간 이벤트) | Laravel Reverb 사용 (WebSocket) |
| [console-confirm.md](console-confirm.md) | 콘솔 yes/no 프롬프트 (ConsoleConfirm) | 콘솔 커맨드의 yes/no 프롬프트는 $this->unifiedConfirm() 사용... |
| [controllers.md](controllers.md) | 컨트롤러 계층 구조 | AdminBaseController / AuthBaseController / Publ... |
| [core-config.md](core-config.md) | 코어 설정 (config/core.php) | config/core.php = 코어 권한/역할/메뉴/메일템플릿의 SSoT (Sing... |
| [core-update-system.md](core-update-system.md) | 코어 업데이트 시스템 (Core Update System) | 코어 업그레이드 스텝: upgrades/ 디렉토리 (프로젝트 루트), 네임스페이스 A... |
| [data-sync-helpers.md](data-sync-helpers.md) | 데이터 동기화 Helper (Data Sync Helpers) | 모든 데이터 동기화는 Service/Seeder 가 Helper 를 호출해 수행 (직... |
| [dto.md](dto.md) | DTO (Data Transfer Object) 사용 규칙 | DTO 두 패턴 — Value Object(불변 1회 전달) vs Data Carri... |
| [enum.md](enum.md) | Enum 사용 규칙 | 상태/타입/분류 = Enum 필수 (PHP 8.1+ Backed Enum) |
| [exceptions.md](exceptions.md) | Custom Exception 다국어 처리 | 예외 메시지 하드코딩 금지 → __() 함수 필수 |
| [geoip.md](geoip.md) | GeoIP 시스템 (MaxMind GeoLite2) | MaxMind GeoLite2-City DB 기반 IP → 타임존 감지 (SetTim... |
| [identity-messages.md](identity-messages.md) | 본인인증 메시지 템플릿 시스템 (Identity Messages) | 알림 시스템(notification_*)과 완전 분리된 IDV 전용 템플릿 인프라 |
| [identity-policies.md](identity-policies.md) | 본인인증 정책 시스템 (Identity Policies) | - |
| [identity-providers.md](identity-providers.md) | IDV Provider 작성 가이드 (Identity Verification Providers) | VerificationProviderInterface 구현 + IdentityProv... |
| [language-pack-service.md](language-pack-service.md) | LanguagePackService (백엔드 Service 레이어) | LanguagePackService 가 install/activate/deactiva... |
| [middleware.md](middleware.md) | 미들웨어 등록 규칙 | 인증 필요 미들웨어 → 전역 등록 금지! |
| [notification-system.md](notification-system.md) | 알림 시스템 (Notification System) | GenericNotification 범용 클래스 1개로 모든 알림 처리 (개별 클래스... |
| [response-helper.md](response-helper.md) | API 응답 규칙 (ResponseHelper) | 모든 API 응답은 ResponseHelper 사용 |
| [routing.md](routing.md) | 라우트 네이밍 및 경로 | 모든 라우트는 name() 필수: ->name('api.users.index') |
| [search-system.md](search-system.md) | Scout 검색 엔진 시스템 (Search System) | Laravel Scout + DatabaseFulltextEngine: MySQL F... |
| [seo-system.md](seo-system.md) | SEO 페이지 생성기 시스템 (SEO Page Generator) | SeoMiddleware: 봇 요청 감지 → ?locale= 파라미터 해석 → Seo... |
| [service-provider.md](service-provider.md) | 서비스 프로바이더 안전성 | DB 접근 전 .env 파일 존재 확인 필수 |
| [service-repository.md](service-repository.md) | Service-Repository 패턴 | RepositoryInterface 주입 필수 (구체 클래스 직접 주입 금지) |
| [settings-multilingual-enrichment.md](settings-multilingual-enrichment.md) | Settings 카탈로그 다국어 자동 보강 | settings JSON 의 다국어 카탈로그 라벨(_cached_name 등)은 카탈... |
| [translatable-seeders.md](translatable-seeders.md) | 다국어 시더 인터페이스 (Translatable Seeders) | 다국어 JSON 컬럼(name 등)을 시드하는 확장 entity 시더는 Transla... |
| [user-overrides.md](user-overrides.md) | 사용자 수정 보존 (HasUserOverrides Trait) | 모델에 `use HasUserOverrides;` + `protected array ... |
| [validation.md](validation.md) | 검증 (Validation) | 필수: FormRequest에서 검증 (Service에 검증 로직 배치 금지) |

<!-- AUTO-GENERATED-END: backend-readme-docs -->

---

## 아키텍처 개요

### 계층 분리

```
Controller → Request → Service → Repository → Model
```

### 컨트롤러 계층 구조

```
BaseApiController (최상위)
├── AdminBaseController (관리자 전용)
├── AuthBaseController (인증된 사용자)
└── PublicBaseController (공개 API)
```

### Service-Repository 패턴

```php
// Service에서 훅 실행
HookManager::doAction('module.entity.before_create', $data);
$data = HookManager::applyFilters('module.entity.filter_data', $data);
$result = $this->repository->create($data);
HookManager::doAction('module.entity.after_create', $result);
```

---

## 파사드 사용 규칙

```php
// ✅ DO: 파사드 앞 역슬래시 제거
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

Log::info('메시지');
Auth::user();

// ❌ DON'T: 역슬래시 사용
\Log::info('메시지');
auth()->user();
```

---

## 핵심 규칙 요약

### 1. 검증 로직 위치

- ❌ Service 클래스에서 검증 금지
- ✅ FormRequest에서 기본 검증
- ✅ Custom Rule에서 복잡한 검증

### 2. 다국어 처리

- ❌ 하드코딩된 메시지 금지
- ✅ `__()` 함수 사용 필수
- ✅ `lang/ko/`, `lang/en/` 파일 관리

### 3. Enum 사용

- ❌ 문자열/숫자 상수 직접 사용 금지
- ✅ Backed Enum 정의
- ✅ 타입 힌트 활용

### 4. 미들웨어 등록

- ❌ 인증이 필요한 미들웨어를 글로벌 등록 금지
- ✅ 그룹별 적절한 위치에 등록
- ✅ 실행 순서 고려

### 5. 서비스 프로바이더 안전성

- ❌ .env 없이 실패하는 코드 금지
- ✅ 환경 검증 후 로직 실행
- ✅ 인스톨러 안정성 확보

### 6. 외부 HTTP 호출

- ❌ `file_get_contents($url)` / `fopen($url, ...)` / `stream_context_create([...])` 로 원격 URL 직접 호출 금지
- ✅ `Illuminate\Support\Facades\Http` (Laravel Http 파사드) 사용
- ✅ GitHub 연동은 `App\Extension\Helpers\GithubHelper` 재사용
- **이유**: 공유 호스팅은 `allow_url_fopen=Off` 설정인 경우가 많아 URL 스트림 래퍼 기반 호출이 전부 실패합니다. Http 파사드는 cURL 기반이라 해당 설정의 영향을 받지 않습니다.

---

## 빠른 참조

### 자주 사용하는 클래스

| 클래스 | 위치 | 용도 |
|--------|------|------|
| ResponseHelper | `app/Helpers/ResponseHelper.php` | API 응답 표준화 |
| BaseApiResource | `app/Http/Resources/BaseApiResource.php` | API 리소스 기본 클래스 |
| AdminBaseController | `app/Http/Controllers/Api/Admin/AdminBaseController.php` | 관리자 컨트롤러 |

### 훅 네이밍 규칙

```
[vendor-module].[entity].[action]_[timing]

예시:
sirsoft-ecommerce.product.before_create
sirsoft-ecommerce.product.after_update
sirsoft-ecommerce.product.filter_create_data
```

---

## 관련 문서

- [AGENTS.md](../../../AGENTS.md) - 프로젝트 개발 가이드
- [database-guide.md](../database-guide.md) - 데이터베이스 규칙
- [extension/](../extension/) - 확장 시스템 (훅, 모듈, 플러그인)
- [testing-guide.md](../testing-guide.md) - 테스트 규칙
