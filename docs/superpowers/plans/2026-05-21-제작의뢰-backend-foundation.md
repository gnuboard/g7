# 제작의뢰 모듈 — Backend Foundation 구현 계획 (Plan 1/4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** sirsoft-inquiry 모듈의 백엔드 도메인 기반(마이그레이션·모델·상태머신·정책·저장 서비스)을 만들어, API/UI 없이도 PHP 단위·기능 테스트로 의뢰 라이프사이클을 검증할 수 있는 상태에 도달한다.

**Architecture:** Laravel 모듈 패턴(`modules/_bundled/sirsoft-inquiry`, PSR-4 + `BaseModuleServiceProvider`)을 board 모듈 패턴 그대로 채택. 도메인 객체 5종(Inquiry/Quote/QuoteItem/Message/Attachment) + 상태머신 1개(`InquiryStateMachine`) + 정책 1개(`InquiryPolicy`) + 저장 서비스 1개(`InquiryAttachmentStorage`). 모든 상태 전이는 단일 진입점을 강제하며, 트랜잭션 안에서 시스템 메시지 삽입 + 이벤트 dispatch(after-commit)까지 수행.

**Tech Stack:** PHP 8.2+, Laravel 11, Pest/PHPUnit, MySQL/MariaDB, Laravel Storage.

**Spec:** `docs/superpowers/specs/2026-05-20-제작의뢰-design.md`

---

## File Structure

```
modules/_bundled/sirsoft-inquiry/
  composer.json
  module.json
  config/
    inquiry.php
  src/
    Providers/
      InquiryServiceProvider.php
    Enums/
      InquiryStatus.php
      QuoteStatus.php
      SenderRole.php
      TransitionEvent.php
    Models/
      Inquiry.php
      InquiryQuote.php
      InquiryQuoteItem.php
      InquiryMessage.php
      InquiryAttachment.php
    Repositories/
      Contracts/
        InquiryRepositoryInterface.php
        InquiryQuoteRepositoryInterface.php
        InquiryMessageRepositoryInterface.php
        InquiryAttachmentRepositoryInterface.php
      InquiryRepository.php
      InquiryQuoteRepository.php
      InquiryMessageRepository.php
      InquiryAttachmentRepository.php
    Policies/
      InquiryPolicy.php
    Services/
      InquiryStateMachine.php
      InquiryAttachmentStorage.php
    Events/
      InquiryStatusTransitioned.php
      InquiryMessagePosted.php
    Exceptions/
      InvalidStateTransitionException.php
      InquiryNotFoundException.php
      QuoteNotFoundException.php
    lang/
      ko/system.php
      en/system.php
  database/migrations/
    2026_05_21_100001_create_inquiries_table.php
    2026_05_21_100002_create_inquiry_quotes_table.php
    2026_05_21_100003_create_inquiry_quote_items_table.php
    2026_05_21_100004_create_inquiry_messages_table.php
    2026_05_21_100005_create_inquiry_attachments_table.php
tests/Feature/Modules/Inquiry/
  ModuleBootstrapTest.php
  ModelRelationshipTest.php
  StateMachineTest.php
  AttachmentStorageTest.php
  PolicyTest.php
```

**파일 책임 요약**
- `Enums/*`: 상태·역할·이벤트 식별자. 라벨/색상은 lang 파일과 합쳐서 다국어 처리.
- `Models/*`: Eloquent — 관계와 캐스팅, 쿼리 scope. 비즈니스 로직 없음.
- `Repositories/*`: 쿼리·persistence. 컨트롤러가 직접 Eloquent 안 만지게 격리.
- `Services/InquiryStateMachine`: 상태 전이 단일 진입점. 트랜잭션 + 시스템 메시지 + 이벤트.
- `Services/InquiryAttachmentStorage`: 업로드 정책 검증 + Storage 저장.
- `Policies/InquiryPolicy`: 권한 매트릭스(스펙 §6).

---

## 사전 확인 (작업 전 1회) — 2026-05-21 보정

> **모듈 시스템 패턴 (확정)** — 이 plan 작성 후 실제 코드를 다시 확인하여 다음 사실들을 확인했다. 본 plan의 모든 task는 이 패턴을 전제로 한다.
>
> - **`BaseModuleServiceProvider::register()` 가 자동 처리**: `$repositories` 배열의 모든 interface→impl 바인딩, `$storageServices` / `$cacheServices` 의 컨텍스트 바인딩. 자식은 배열만 정의.
> - **`BaseModuleServiceProvider::boot()` 가 자동 처리**: `loadModuleMigrations()` (실제로는 빈 메서드 — `php artisan migrate` 와 분리) + `loadModuleTranslations()` (`{module}/src/lang` 에서 자동 로드).
> - **모듈 마이그레이션은 `ModuleManager::runMigrations()` 가 실행** — `php artisan module:install <id>` / `module:activate <id>` 명령으로 트리거. `php artisan migrate` 로는 모듈 마이그레이션이 실행되지 않음.
> - **모듈 인식**: `_bundled` 디렉터리에 원본을 두고, install 명령이 `modules/<id>/` 로 활성화시키며 `bootstrap/cache/autoload-extensions.php` 가 갱신됨. PSR-4 autoload는 install 명령이 처리.
> - **다국어 자동 로드 경로**: `modules/_bundled/<id>/src/lang/{locale}/<file>.php` → 키 prefix `<id>::file.key` (예: `inquiry::system.status.received`).
>
> 따라서 본 plan의 Task 2(`InquiryServiceProvider`)에서 `boot()` 메서드를 재정의하지 않으며, `loadMigrationsFrom` / `loadTranslationsFrom` 직접 호출도 제외한다. config 머지가 필요하면 `register()` 안에서 한다.

- [ ] **Pre-1: BaseModuleServiceProvider / ModuleManager 패턴 재확인 (참고 자료 읽기)**

```bash
sed -n '50,90p' app/Extension/BaseModuleServiceProvider.php
sed -n '155,170p' app/Extension/BaseModuleServiceProvider.php
grep -n "runMigrations" app/Extension/ModuleManager.php 2>/dev/null | head -5
```

기대: parent `register()` 와 `boot()` 가 모든 공통 처리를 한다는 사실 확인.

- [ ] **Pre-2: 테스트 환경(.env.testing) 준비**

```bash
test -f .env.testing || cp .env.testing.example .env.testing
grep "^APP_KEY=" .env.testing | grep -v "APP_KEY=$" >/dev/null || php artisan key:generate --env=testing
php artisan test --filter=NothingMatchesThisTest 2>&1 | tail -5
```

기대: 마지막 명령은 "No tests executed" 같은 깨끗한 결과(에러 아님). `.env.testing` 키 정상.

- [ ] **Pre-3: `module:install` / `module:activate` 흐름 확인**

```bash
php artisan module:list 2>&1 | head -10
php artisan list 2>&1 | grep -E "module:(install|activate|uninstall|composer)" | head -10
```

기대: board/page/ecommerce 등 기존 모듈이 보임. install/activate 명령 존재 확인.

---

## Task 1: 모듈 스캐폴드 (디렉터리 + manifest)

**Files:**
- Create: `modules/_bundled/sirsoft-inquiry/module.json`
- Create: `modules/_bundled/sirsoft-inquiry/composer.json`
- Create: 위에 명시된 디렉터리 트리(빈 폴더는 `.gitkeep`)

- [ ] **Step 1: 디렉터리 생성**

```bash
mkdir -p modules/_bundled/sirsoft-inquiry/{config,src/{Providers,Enums,Models,Repositories/Contracts,Policies,Services,Events,Exceptions,lang/ko,lang/en},database/migrations,tests}
```

- [ ] **Step 2: module.json 작성**

```json
{
    "identifier": "sirsoft-inquiry",
    "vendor": "sirsoft",
    "name": {
        "ko": "제작의뢰",
        "en": "Inquiry"
    },
    "version": "1.0.0-alpha.1",
    "license": "MIT",
    "description": {
        "ko": "제작의뢰 라이프사이클(접수·견적·진행·완료) 관리 모듈",
        "en": "Inquiry lifecycle module (received / quoted / in_progress / completed)"
    },
    "g7_version": ">=7.0.0-beta.5",
    "dependencies": {
        "modules": {},
        "plugins": {}
    }
}
```

- [ ] **Step 3: composer.json 작성**

```json
{
    "name": "modules/sirsoft-inquiry",
    "description": "Inquiry module for Gnuboard7",
    "type": "library",
    "version": "1.0.0-alpha.1",
    "license": "MIT",
    "autoload": {
        "psr-4": {
            "Modules\\Sirsoft\\Inquiry\\": "src/",
            "Modules\\Sirsoft\\Inquiry\\Database\\Seeders\\": "database/seeders/",
            "Modules\\Sirsoft\\Inquiry\\Database\\Factories\\": "database/factories/"
        }
    },
    "require": {
        "php": "^8.2"
    }
}
```

- [ ] **Step 4: 루트 composer autoload 갱신**

```bash
composer dump-autoload 2>&1 | tail -5
```

기대: "Generated autoload files" 메시지. 에러 없음.

- [ ] **Step 5: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/module.json modules/_bundled/sirsoft-inquiry/composer.json modules/_bundled/sirsoft-inquiry/
git commit -m "feat(inquiry): scaffold module skeleton"
```

---

## Task 2: ServiceProvider 골격 + Bootstrap 테스트

**Files:**
- Create: `modules/_bundled/sirsoft-inquiry/src/Providers/InquiryServiceProvider.php`
- Create: `tests/Feature/Modules/Inquiry/ModuleBootstrapTest.php`

- [ ] **Step 1: 실패 테스트 작성**

`tests/Feature/Modules/Inquiry/ModuleBootstrapTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Inquiry;

use Modules\Sirsoft\Inquiry\Providers\InquiryServiceProvider;
use Tests\TestCase;

class ModuleBootstrapTest extends TestCase
{
    public function test_service_provider_is_resolvable(): void
    {
        $provider = $this->app->make(InquiryServiceProvider::class, ['app' => $this->app]);
        $this->assertInstanceOf(InquiryServiceProvider::class, $provider);
    }

    public function test_module_identifier_matches_manifest(): void
    {
        $provider = $this->app->make(InquiryServiceProvider::class, ['app' => $this->app]);
        $reflection = new \ReflectionClass($provider);
        $prop = $reflection->getProperty('moduleIdentifier');
        $prop->setAccessible(true);
        $this->assertSame('sirsoft-inquiry', $prop->getValue($provider));
    }
}
```

- [ ] **Step 2: 테스트 실행 (실패 확인)**

```bash
php artisan test --filter=ModuleBootstrapTest 2>&1 | tail -10
```

기대: "Class Modules\Sirsoft\Inquiry\Providers\InquiryServiceProvider not found"

- [ ] **Step 3: ServiceProvider 작성**

`BaseModuleServiceProvider` 가 마이그레이션·번역 로드, repository binding을 모두 자동 처리하므로 `boot()` / `register()` 재정의 불필요. 자식은 배열만 정의. **config 머지가 필요하면 `register()` 안에서 처리** (Task 3에서 추가):

```php
<?php

namespace Modules\Sirsoft\Inquiry\Providers;

use App\Extension\BaseModuleServiceProvider;

class InquiryServiceProvider extends BaseModuleServiceProvider
{
    protected string $moduleIdentifier = 'sirsoft-inquiry';

    /**
     * Repository 인터페이스 → 구현체 매핑.
     * Task 16-19 에서 채워짐.
     */
    protected array $repositories = [];

    protected array $cacheServices = [];
    protected array $storageServices = [];
}
```

- [ ] **Step 4: 모듈 등록 (ModuleManager 통한 install 흐름)**

ServiceProvider를 `config/app.php` 의 `providers` 에 직접 추가하지 않는다. 대신 ModuleManager가 활성화 시점에 자동 등록한다. 이번 step에서는 모듈이 _bundled 디렉터리에서 인식되도록 install 흐름을 실행:

```bash
php artisan module:list 2>&1 | grep sirsoft-inquiry
# 없으면 install:
php artisan module:install sirsoft-inquiry 2>&1 | tail -10
php artisan module:activate sirsoft-inquiry 2>&1 | tail -5
php artisan module:list 2>&1 | grep sirsoft-inquiry
```

기대: 마지막 명령에 sirsoft-inquiry 가 activated 상태로 출력.

`module:install` 실패 시 board 모듈의 활성화 패턴을 비교해 누락된 파일(예: `module.php`)을 보강:

```bash
ls modules/_bundled/sirsoft-board/module.php modules/sirsoft-board/module.php 2>&1
# 필요 시 동일 구조로 modules/_bundled/sirsoft-inquiry/module.php 생성
```

- [ ] **Step 5: 테스트 실행 (성공 확인)**

```bash
php artisan test --filter=ModuleBootstrapTest 2>&1 | tail -10
```

기대: "OK (2 tests, 2 assertions)" 또는 동등.

- [ ] **Step 6: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/Providers/InquiryServiceProvider.php \
        tests/Feature/Modules/Inquiry/ModuleBootstrapTest.php \
        config/app.php  # 또는 ServiceProvider 등록 위치
git commit -m "feat(inquiry): add ServiceProvider skeleton + bootstrap test"
```

---

## Task 3: config/inquiry.php

**Files:**
- Create: `modules/_bundled/sirsoft-inquiry/config/inquiry.php`

- [ ] **Step 1: config 파일 작성**

```php
<?php

return [
    'attachment' => [
        'disk' => env('INQUIRY_ATTACHMENT_DISK', 'local'),
        'max_size_inquiry' => env('INQUIRY_ATTACHMENT_MAX_INQUIRY', 50 * 1024 * 1024), // 50MB
        'max_size_message' => env('INQUIRY_ATTACHMENT_MAX_MESSAGE', 20 * 1024 * 1024), // 20MB
        'allowed_mimes' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'application/pdf',
            'application/zip',
            'application/x-zip-compressed',
        ],
        'orphan_cleanup_after_minutes' => 30,
    ],

    'categories' => [
        'web',
        'design',
        'maintenance',
        'consulting',
        'etc',
    ],

    'quote' => [
        'currency' => 'KRW',
        'default_valid_days' => 14,
    ],

    'permissions' => [
        'manage' => 'inquiry.manage',
        'notify' => 'inquiry.notify',
    ],
];
```

- [ ] **Step 2: ServiceProvider의 register() 안에서 config 머지**

`InquiryServiceProvider` 에 `register()` 메서드를 추가해 `parent::register()` 호출 + config 머지:

```php
public function register(): void
{
    parent::register();

    $this->mergeConfigFrom(
        $this->getProviderPath() . '/../../config/inquiry.php',
        'inquiry'
    );
}
```

- [ ] **Step 3: config 로드 검증 테스트 추가**

`ModuleBootstrapTest::test_config_is_merged()`:

```php
public function test_config_is_merged(): void
{
    $this->assertSame('KRW', config('inquiry.quote.currency'));
    $this->assertContains('image/jpeg', config('inquiry.attachment.allowed_mimes'));
}
```

- [ ] **Step 4: 테스트 실행**

```bash
php artisan test --filter=ModuleBootstrapTest 2>&1 | tail -10
```

기대: 모든 테스트 PASS.

- [ ] **Step 5: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/config/inquiry.php \
        modules/_bundled/sirsoft-inquiry/src/Providers/InquiryServiceProvider.php \
        tests/Feature/Modules/Inquiry/ModuleBootstrapTest.php
git commit -m "feat(inquiry): add module config (attachment/quote/permissions)"
```

---

## Task 4: Enums 4종

**Files:**
- Create: `src/Enums/InquiryStatus.php`
- Create: `src/Enums/QuoteStatus.php`
- Create: `src/Enums/SenderRole.php`
- Create: `src/Enums/TransitionEvent.php`

- [ ] **Step 1: 실패 테스트 작성**

`tests/Feature/Modules/Inquiry/EnumsTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Inquiry;

use Modules\Sirsoft\Inquiry\Enums\InquiryStatus;
use Modules\Sirsoft\Inquiry\Enums\QuoteStatus;
use Modules\Sirsoft\Inquiry\Enums\SenderRole;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use Tests\TestCase;

class EnumsTest extends TestCase
{
    public function test_inquiry_status_values(): void
    {
        $this->assertSame('received', InquiryStatus::Received->value);
        $this->assertSame('quoted', InquiryStatus::Quoted->value);
        $this->assertSame('in_progress', InquiryStatus::InProgress->value);
        $this->assertSame('completed', InquiryStatus::Completed->value);
        $this->assertSame('canceled', InquiryStatus::Canceled->value);
        $this->assertCount(5, InquiryStatus::cases());
    }

    public function test_quote_status_values(): void
    {
        $this->assertSame('draft', QuoteStatus::Draft->value);
        $this->assertSame('issued', QuoteStatus::Issued->value);
        $this->assertSame('accepted', QuoteStatus::Accepted->value);
        $this->assertSame('rejected', QuoteStatus::Rejected->value);
        $this->assertSame('expired', QuoteStatus::Expired->value);
    }

    public function test_sender_role_values(): void
    {
        $this->assertSame('client', SenderRole::Client->value);
        $this->assertSame('operator', SenderRole::Operator->value);
        $this->assertSame('system', SenderRole::System->value);
    }

    public function test_transition_event_values(): void
    {
        $events = array_map(fn ($e) => $e->value, TransitionEvent::cases());
        $this->assertEqualsCanonicalizing([
            'issue_quote',
            'revoke_quote',
            'reject_quote',
            'accept_and_pay',
            'mark_paid_offline',
            'mark_completed',
            'cancel',
        ], $events);
    }
}
```

- [ ] **Step 2: 테스트 실행 (실패 확인)**

```bash
php artisan test --filter=EnumsTest 2>&1 | tail -10
```

기대: "Class ... not found" 4건.

- [ ] **Step 3: Enums 작성**

`src/Enums/InquiryStatus.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Enums;

enum InquiryStatus: string
{
    case Received = 'received';
    case Quoted = 'quoted';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return __('inquiry::system.status.' . $this->value);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Canceled], true);
    }
}
```

`src/Enums/QuoteStatus.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Enums;

enum QuoteStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function isActive(): bool
    {
        return $this === self::Issued;
    }
}
```

`src/Enums/SenderRole.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Enums;

enum SenderRole: string
{
    case Client = 'client';
    case Operator = 'operator';
    case System = 'system';
}
```

`src/Enums/TransitionEvent.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Enums;

enum TransitionEvent: string
{
    case IssueQuote = 'issue_quote';
    case RevokeQuote = 'revoke_quote';
    case RejectQuote = 'reject_quote';
    case AcceptAndPay = 'accept_and_pay';
    case MarkPaidOffline = 'mark_paid_offline';
    case MarkCompleted = 'mark_completed';
    case Cancel = 'cancel';
}
```

- [ ] **Step 4: 테스트 실행 (성공)**

```bash
php artisan test --filter=EnumsTest 2>&1 | tail -10
```

기대: PASS.

- [ ] **Step 5: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/Enums/ tests/Feature/Modules/Inquiry/EnumsTest.php
git commit -m "feat(inquiry): add Status/Role/Event enums"
```

---

## Task 5: Migration — inquiries

**Files:**
- Create: `database/migrations/2026_05_21_100001_create_inquiries_table.php`

- [ ] **Step 1: 마이그레이션 작성**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('content');
            $table->string('category', 50)->nullable();
            $table->string('budget_range', 100)->nullable();
            $table->date('desired_due_at')->nullable();
            $table->string('status', 20)->default('received')->index();
            $table->unsignedBigInteger('accepted_quote_id')->nullable();
            $table->string('payment_id', 64)->nullable();
            $table->json('extra_data')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
```

- [ ] **Step 2: 마이그레이션 실행 검증**

```bash
php artisan migrate --path=modules/_bundled/sirsoft-inquiry/database/migrations 2>&1 | tail -5
php artisan tinker --execute="dump(\Schema::hasTable('inquiries'));"
```

기대: `true` 출력.

- [ ] **Step 3: rollback도 동작하는지 확인**

```bash
php artisan migrate:rollback --path=modules/_bundled/sirsoft-inquiry/database/migrations --step=1 2>&1 | tail -5
php artisan tinker --execute="dump(\Schema::hasTable('inquiries'));"
php artisan migrate --path=modules/_bundled/sirsoft-inquiry/database/migrations
```

기대: rollback 후 `false`, 재실행 후 정상.

- [ ] **Step 4: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/database/migrations/2026_05_21_100001_create_inquiries_table.php
git commit -m "feat(inquiry): add inquiries migration"
```

---

## Task 6: Migration — inquiry_quotes

**Files:**
- Create: `database/migrations/2026_05_21_100002_create_inquiry_quotes_table.php`

- [ ] **Step 1: 마이그레이션 작성**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_quotes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('inquiry_id')->constrained('inquiries')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->decimal('total_amount', 12, 0);
            $table->decimal('tax_amount', 12, 0)->default(0);
            $table->string('currency', 3)->default('KRW');
            $table->date('valid_until')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->unique(['inquiry_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_quotes');
    }
};
```

- [ ] **Step 2: 마이그레이션 실행 + 테이블 존재 확인**

```bash
php artisan migrate --path=modules/_bundled/sirsoft-inquiry/database/migrations 2>&1 | tail -5
php artisan tinker --execute="dump(\Schema::hasTable('inquiry_quotes'));"
```

기대: `true`.

- [ ] **Step 3: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/database/migrations/2026_05_21_100002_create_inquiry_quotes_table.php
git commit -m "feat(inquiry): add inquiry_quotes migration"
```

---

## Task 7: Migration — inquiry_quote_items

**Files:**
- Create: `database/migrations/2026_05_21_100003_create_inquiry_quote_items_table.php`

- [ ] **Step 1: 마이그레이션 작성**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_quote_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('quote_id')->constrained('inquiry_quotes')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->decimal('qty', 10, 2);
            $table->decimal('unit_price', 12, 0);
            $table->decimal('amount', 12, 0);
            $table->timestamps();

            $table->index(['quote_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_quote_items');
    }
};
```

- [ ] **Step 2: 마이그레이션 실행**

```bash
php artisan migrate --path=modules/_bundled/sirsoft-inquiry/database/migrations 2>&1 | tail -5
php artisan tinker --execute="dump(\Schema::hasTable('inquiry_quote_items'));"
```

- [ ] **Step 3: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/database/migrations/2026_05_21_100003_create_inquiry_quote_items_table.php
git commit -m "feat(inquiry): add inquiry_quote_items migration"
```

---

## Task 8: Migration — inquiry_messages

**Files:**
- Create: `database/migrations/2026_05_21_100004_create_inquiry_messages_table.php`

- [ ] **Step 1: 마이그레이션 작성**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('inquiry_id')->constrained('inquiries')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sender_role', 20);
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['inquiry_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_messages');
    }
};
```

- [ ] **Step 2: 마이그레이션 실행 + 검증**

```bash
php artisan migrate --path=modules/_bundled/sirsoft-inquiry/database/migrations 2>&1 | tail -5
php artisan tinker --execute="dump(\Schema::hasTable('inquiry_messages'));"
```

- [ ] **Step 3: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/database/migrations/2026_05_21_100004_create_inquiry_messages_table.php
git commit -m "feat(inquiry): add inquiry_messages migration (with meta column for system msgs)"
```

---

## Task 9: Migration — inquiry_attachments

**Files:**
- Create: `database/migrations/2026_05_21_100005_create_inquiry_attachments_table.php`

- [ ] **Step 1: 마이그레이션 작성**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_attachments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('inquiry_id')->constrained('inquiries')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('inquiry_messages')->cascadeOnDelete();
            $table->foreignId('uploader_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('disk', 20);
            $table->string('path');
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->timestamps();

            $table->index(['inquiry_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_attachments');
    }
};
```

- [ ] **Step 2: 마이그레이션 실행 + 검증**

```bash
php artisan migrate --path=modules/_bundled/sirsoft-inquiry/database/migrations 2>&1 | tail -5
php artisan tinker --execute="dump(\Schema::hasTable('inquiry_attachments'));"
```

- [ ] **Step 3: 전체 마이그레이션 rollback + redo 검증**

```bash
php artisan migrate:rollback --path=modules/_bundled/sirsoft-inquiry/database/migrations --step=5
php artisan migrate --path=modules/_bundled/sirsoft-inquiry/database/migrations
php artisan tinker --execute="dump(['inquiries'=>\Schema::hasTable('inquiries'),'inquiry_quotes'=>\Schema::hasTable('inquiry_quotes'),'inquiry_quote_items'=>\Schema::hasTable('inquiry_quote_items'),'inquiry_messages'=>\Schema::hasTable('inquiry_messages'),'inquiry_attachments'=>\Schema::hasTable('inquiry_attachments')]);"
```

기대: 5개 모두 `true`.

- [ ] **Step 4: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/database/migrations/2026_05_21_100005_create_inquiry_attachments_table.php
git commit -m "feat(inquiry): add inquiry_attachments migration"
```

---

## Task 10: Model — Inquiry

**Files:**
- Create: `src/Models/Inquiry.php`

- [ ] **Step 1: 실패 테스트 작성**

`tests/Feature/Modules/Inquiry/ModelRelationshipTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Inquiry;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sirsoft\Inquiry\Enums\InquiryStatus;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Tests\TestCase;

class ModelRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_inquiry_belongs_to_user_and_casts_status(): void
    {
        $user = User::factory()->create();
        $inquiry = Inquiry::create([
            'uuid' => (string) \Str::uuid(),
            'user_id' => $user->id,
            'title' => '홈페이지 리뉴얼 의뢰',
            'content' => '기존 사이트 개편 부탁드립니다.',
            'status' => InquiryStatus::Received->value,
        ]);

        $this->assertInstanceOf(User::class, $inquiry->user);
        $this->assertSame($user->id, $inquiry->user->id);
        $this->assertInstanceOf(InquiryStatus::class, $inquiry->status);
        $this->assertSame(InquiryStatus::Received, $inquiry->status);
    }
}
```

- [ ] **Step 2: 테스트 실행 (실패)**

```bash
php artisan test --filter=test_inquiry_belongs_to_user_and_casts_status 2>&1 | tail -10
```

기대: "Class Modules\Sirsoft\Inquiry\Models\Inquiry not found".

- [ ] **Step 3: Inquiry Model 작성**

```php
<?php

namespace Modules\Sirsoft\Inquiry\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Sirsoft\Inquiry\Enums\InquiryStatus;

class Inquiry extends Model
{
    protected $table = 'inquiries';

    protected $fillable = [
        'uuid',
        'user_id',
        'title',
        'content',
        'category',
        'budget_range',
        'desired_due_at',
        'status',
        'accepted_quote_id',
        'payment_id',
        'extra_data',
        'received_at',
        'quoted_at',
        'started_at',
        'completed_at',
        'canceled_at',
    ];

    protected $casts = [
        'status' => InquiryStatus::class,
        'extra_data' => 'array',
        'desired_due_at' => 'date',
        'received_at' => 'datetime',
        'quoted_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'canceled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(InquiryQuote::class)->orderBy('version');
    }

    public function acceptedQuote(): BelongsTo
    {
        return $this->belongsTo(InquiryQuote::class, 'accepted_quote_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(InquiryMessage::class)->orderBy('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(InquiryAttachment::class);
    }
}
```

- [ ] **Step 4: 테스트 실행 (성공)**

```bash
php artisan test --filter=test_inquiry_belongs_to_user_and_casts_status 2>&1 | tail -10
```

기대: PASS. (의존 모델은 빈 클래스로 임시 만들거나 다음 task에서 채워질 때까지 관계 호출은 테스트하지 않음.)

- [ ] **Step 5: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/Models/Inquiry.php tests/Feature/Modules/Inquiry/ModelRelationshipTest.php
git commit -m "feat(inquiry): add Inquiry model with relationships and casts"
```

---

## Task 11: Models — InquiryQuote + InquiryQuoteItem

**Files:**
- Create: `src/Models/InquiryQuote.php`
- Create: `src/Models/InquiryQuoteItem.php`

- [ ] **Step 1: 실패 테스트 추가**

`ModelRelationshipTest::test_quote_has_items_and_inquiry()`:

```php
public function test_quote_has_items_and_inquiry(): void
{
    $user = User::factory()->create();
    $inquiry = Inquiry::create([
        'uuid' => (string) \Str::uuid(),
        'user_id' => $user->id,
        'title' => 'X', 'content' => 'Y',
        'status' => 'received',
    ]);
    $quote = $inquiry->quotes()->create([
        'version' => 1,
        'total_amount' => 1000000,
        'status' => 'issued',
    ]);
    $quote->items()->create([
        'position' => 1,
        'name' => '메인 페이지 디자인',
        'qty' => 1,
        'unit_price' => 1000000,
        'amount' => 1000000,
    ]);

    $this->assertSame(1, $quote->items()->count());
    $this->assertSame($inquiry->id, $quote->inquiry->id);
}
```

- [ ] **Step 2: 테스트 실패 확인**

```bash
php artisan test --filter=test_quote_has_items_and_inquiry 2>&1 | tail -10
```

- [ ] **Step 3: 모델 작성**

`src/Models/InquiryQuote.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Sirsoft\Inquiry\Enums\QuoteStatus;

class InquiryQuote extends Model
{
    protected $table = 'inquiry_quotes';

    protected $fillable = [
        'inquiry_id',
        'version',
        'total_amount',
        'tax_amount',
        'currency',
        'valid_until',
        'note',
        'status',
        'issued_at',
        'accepted_at',
        'rejected_at',
    ];

    protected $casts = [
        'status' => QuoteStatus::class,
        'valid_until' => 'date',
        'issued_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'total_amount' => 'decimal:0',
        'tax_amount' => 'decimal:0',
    ];

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InquiryQuoteItem::class, 'quote_id')->orderBy('position');
    }
}
```

`src/Models/InquiryQuoteItem.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryQuoteItem extends Model
{
    protected $table = 'inquiry_quote_items';

    protected $fillable = [
        'quote_id',
        'position',
        'name',
        'description',
        'qty',
        'unit_price',
        'amount',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'unit_price' => 'decimal:0',
        'amount' => 'decimal:0',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(InquiryQuote::class, 'quote_id');
    }
}
```

- [ ] **Step 4: 테스트 실행 (성공)**

```bash
php artisan test --filter=test_quote_has_items_and_inquiry 2>&1 | tail -10
```

- [ ] **Step 5: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/Models/InquiryQuote.php modules/_bundled/sirsoft-inquiry/src/Models/InquiryQuoteItem.php tests/Feature/Modules/Inquiry/ModelRelationshipTest.php
git commit -m "feat(inquiry): add InquiryQuote + InquiryQuoteItem models"
```

---

## Task 12: Models — InquiryMessage + InquiryAttachment

**Files:**
- Create: `src/Models/InquiryMessage.php`
- Create: `src/Models/InquiryAttachment.php`

- [ ] **Step 1: 실패 테스트 추가**

`ModelRelationshipTest::test_message_and_attachment()`:

```php
public function test_message_and_attachment(): void
{
    $user = User::factory()->create();
    $inquiry = Inquiry::create([
        'uuid' => (string) \Str::uuid(),
        'user_id' => $user->id,
        'title' => 'X', 'content' => 'Y',
        'status' => 'received',
    ]);
    $msg = $inquiry->messages()->create([
        'sender_user_id' => $user->id,
        'sender_role' => 'client',
        'body' => '안녕하세요',
    ]);
    $att = $inquiry->attachments()->create([
        'message_id' => $msg->id,
        'uploader_user_id' => $user->id,
        'disk' => 'local',
        'path' => 'inquiries/test.pdf',
        'original_name' => 'test.pdf',
        'mime' => 'application/pdf',
        'size' => 1234,
    ]);

    $this->assertSame('client', $msg->sender_role->value);
    $this->assertSame($msg->id, $att->message->id);
    $this->assertSame(1, $msg->attachments()->count());
}
```

- [ ] **Step 2: 실패 확인**

```bash
php artisan test --filter=test_message_and_attachment 2>&1 | tail -10
```

- [ ] **Step 3: 모델 작성**

`src/Models/InquiryMessage.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Sirsoft\Inquiry\Enums\SenderRole;

class InquiryMessage extends Model
{
    protected $table = 'inquiry_messages';

    protected $fillable = [
        'inquiry_id',
        'sender_user_id',
        'sender_role',
        'body',
        'meta',
        'read_at',
    ];

    protected $casts = [
        'sender_role' => SenderRole::class,
        'meta' => 'array',
        'read_at' => 'datetime',
    ];

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(InquiryAttachment::class, 'message_id');
    }

    public function isSystem(): bool
    {
        return $this->sender_role === SenderRole::System;
    }
}
```

`src/Models/InquiryAttachment.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryAttachment extends Model
{
    protected $table = 'inquiry_attachments';

    protected $fillable = [
        'inquiry_id',
        'message_id',
        'uploader_user_id',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(InquiryMessage::class, 'message_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_user_id');
    }
}
```

- [ ] **Step 4: 테스트 실행 (성공)**

```bash
php artisan test --filter=test_message_and_attachment 2>&1 | tail -10
```

- [ ] **Step 5: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/Models/InquiryMessage.php modules/_bundled/sirsoft-inquiry/src/Models/InquiryAttachment.php tests/Feature/Modules/Inquiry/ModelRelationshipTest.php
git commit -m "feat(inquiry): add InquiryMessage + InquiryAttachment models"
```

---

## Task 13: Exceptions

**Files:**
- Create: `src/Exceptions/InvalidStateTransitionException.php`
- Create: `src/Exceptions/InquiryNotFoundException.php`
- Create: `src/Exceptions/QuoteNotFoundException.php`

- [ ] **Step 1: 작성**

`src/Exceptions/InvalidStateTransitionException.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Exceptions;

use Modules\Sirsoft\Inquiry\Enums\InquiryStatus;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use RuntimeException;

class InvalidStateTransitionException extends RuntimeException
{
    public function __construct(InquiryStatus $from, TransitionEvent $event)
    {
        parent::__construct(
            "Invalid transition: cannot apply event '{$event->value}' to inquiry in status '{$from->value}'.",
            422
        );
    }
}
```

`src/Exceptions/InquiryNotFoundException.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Exceptions;

use RuntimeException;

class InquiryNotFoundException extends RuntimeException
{
    public function __construct(string $uuid)
    {
        parent::__construct("Inquiry [{$uuid}] not found.", 404);
    }
}
```

`src/Exceptions/QuoteNotFoundException.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Exceptions;

use RuntimeException;

class QuoteNotFoundException extends RuntimeException
{
    public function __construct(int $id)
    {
        parent::__construct("Inquiry quote [{$id}] not found.", 404);
    }
}
```

- [ ] **Step 2: 인스턴스 생성 가능 검증 (간단 테스트)**

`EnumsTest::test_invalid_transition_exception_carries_info()`:

```php
public function test_invalid_transition_exception_carries_info(): void
{
    $ex = new \Modules\Sirsoft\Inquiry\Exceptions\InvalidStateTransitionException(
        \Modules\Sirsoft\Inquiry\Enums\InquiryStatus::Received,
        \Modules\Sirsoft\Inquiry\Enums\TransitionEvent::AcceptAndPay
    );
    $this->assertStringContainsString("received", $ex->getMessage());
    $this->assertStringContainsString("accept_and_pay", $ex->getMessage());
    $this->assertSame(422, $ex->getCode());
}
```

```bash
php artisan test --filter=test_invalid_transition_exception_carries_info 2>&1 | tail -10
```

기대: PASS.

- [ ] **Step 3: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/Exceptions/ tests/Feature/Modules/Inquiry/EnumsTest.php
git commit -m "feat(inquiry): add domain exceptions"
```

---

## Task 14: Events

**Files:**
- Create: `src/Events/InquiryStatusTransitioned.php`
- Create: `src/Events/InquiryMessagePosted.php`

- [ ] **Step 1: 작성**

`src/Events/InquiryStatusTransitioned.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Sirsoft\Inquiry\Enums\InquiryStatus;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use Modules\Sirsoft\Inquiry\Models\Inquiry;

class InquiryStatusTransitioned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Inquiry $inquiry,
        public readonly InquiryStatus $from,
        public readonly InquiryStatus $to,
        public readonly TransitionEvent $event,
        public readonly ?int $actorUserId,
    ) {}
}
```

`src/Events/InquiryMessagePosted.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Sirsoft\Inquiry\Models\InquiryMessage;

class InquiryMessagePosted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly InquiryMessage $message,
    ) {}
}
```

- [ ] **Step 2: 인스턴스 생성 검증 (간단)**

```bash
php artisan tinker --execute="dump(class_exists('Modules\\Sirsoft\\Inquiry\\Events\\InquiryStatusTransitioned'));"
```

기대: `true`.

- [ ] **Step 3: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/Events/
git commit -m "feat(inquiry): add domain events"
```

---

## Task 15: Lang 파일 — 시스템 메시지 키

**Files:**
- Create: `src/lang/ko/system.php`
- Create: `src/lang/en/system.php`

- [ ] **Step 1: ko/system.php 작성**

```php
<?php

return [
    'status' => [
        'received' => '접수',
        'quoted' => '견적',
        'in_progress' => '진행',
        'completed' => '완료',
        'canceled' => '취소',
    ],
    'message' => [
        'quote_issued' => '운영자가 견적을 발행했습니다 (회차 #:version, 합계 :total원).',
        'quote_revoked' => '운영자가 견적을 철회했습니다 (회차 #:version).',
        'quote_rejected' => '의뢰자가 견적을 거절했습니다 (회차 #:version).',
        'payment_confirmed' => '결제가 확인되었습니다.',
        'payment_confirmed_offline' => '운영자가 결제를 수동 확인했습니다.',
        'completed' => '의뢰가 완료되었습니다.',
        'canceled_by_client' => '의뢰자가 의뢰를 취소했습니다.',
        'canceled_by_operator' => '운영자가 의뢰를 취소했습니다.',
    ],
];
```

- [ ] **Step 2: en/system.php 작성**

```php
<?php

return [
    'status' => [
        'received' => 'Received',
        'quoted' => 'Quoted',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'canceled' => 'Canceled',
    ],
    'message' => [
        'quote_issued' => 'Operator issued a quote (version #:version, total :total KRW).',
        'quote_revoked' => 'Operator revoked the quote (version #:version).',
        'quote_rejected' => 'Client rejected the quote (version #:version).',
        'payment_confirmed' => 'Payment has been confirmed.',
        'payment_confirmed_offline' => 'Operator manually confirmed the payment.',
        'completed' => 'Inquiry has been completed.',
        'canceled_by_client' => 'Client canceled the inquiry.',
        'canceled_by_operator' => 'Operator canceled the inquiry.',
    ],
];
```

- [ ] **Step 3: 로드 검증**

```bash
php artisan tinker --execute="dump(__('inquiry::system.status.received', [], 'ko'));"
```

기대: `"접수"`.

- [ ] **Step 4: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/lang/
git commit -m "feat(inquiry): add system message lang files (ko/en)"
```

---

## Task 16: Repository — Inquiry

**Files:**
- Create: `src/Repositories/Contracts/InquiryRepositoryInterface.php`
- Create: `src/Repositories/InquiryRepository.php`

- [ ] **Step 1: 인터페이스 작성**

```php
<?php

namespace Modules\Sirsoft\Inquiry\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Inquiry\Models\Inquiry;

interface InquiryRepositoryInterface
{
    public function findByUuidOrFail(string $uuid): Inquiry;

    public function create(array $data): Inquiry;

    public function update(Inquiry $inquiry, array $data): Inquiry;

    public function listByUser(int $userId, ?string $status = null, int $perPage = 20): LengthAwarePaginator;

    public function listForAdmin(?string $status = null, ?string $search = null, int $perPage = 20): LengthAwarePaginator;
}
```

- [ ] **Step 2: 실패 테스트**

`ModelRelationshipTest::test_repository_find_and_create()`:

```php
public function test_repository_find_and_create(): void
{
    $repo = app(\Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryRepositoryInterface::class);
    $user = User::factory()->create();
    $inquiry = $repo->create([
        'uuid' => (string) \Str::uuid(),
        'user_id' => $user->id,
        'title' => '리뉴얼',
        'content' => '본문',
        'status' => 'received',
    ]);
    $found = $repo->findByUuidOrFail($inquiry->uuid);
    $this->assertTrue($found->is($inquiry));
}
```

```bash
php artisan test --filter=test_repository_find_and_create 2>&1 | tail -10
```

기대: 인터페이스 미바인딩 실패.

- [ ] **Step 3: 구현체 작성**

```php
<?php

namespace Modules\Sirsoft\Inquiry\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Inquiry\Exceptions\InquiryNotFoundException;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryRepositoryInterface;

class InquiryRepository implements InquiryRepositoryInterface
{
    public function findByUuidOrFail(string $uuid): Inquiry
    {
        return Inquiry::where('uuid', $uuid)->firstOr(fn () => throw new InquiryNotFoundException($uuid));
    }

    public function create(array $data): Inquiry
    {
        return Inquiry::create($data);
    }

    public function update(Inquiry $inquiry, array $data): Inquiry
    {
        $inquiry->fill($data)->save();
        return $inquiry;
    }

    public function listByUser(int $userId, ?string $status = null, int $perPage = 20): LengthAwarePaginator
    {
        return Inquiry::query()
            ->where('user_id', $userId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('received_at')
            ->paginate($perPage);
    }

    public function listForAdmin(?string $status = null, ?string $search = null, int $perPage = 20): LengthAwarePaginator
    {
        return Inquiry::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->where('title', 'like', "%{$search}%"))
            ->orderByDesc('received_at')
            ->paginate($perPage);
    }
}
```

- [ ] **Step 4: ServiceProvider $repositories 배열에 추가**

`InquiryServiceProvider::$repositories`:

```php
protected array $repositories = [
    \Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryRepositoryInterface::class
        => \Modules\Sirsoft\Inquiry\Repositories\InquiryRepository::class,
];
```

- [ ] **Step 5: 테스트 실행 (성공)**

```bash
php artisan test --filter=test_repository_find_and_create 2>&1 | tail -10
```

- [ ] **Step 6: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/Repositories/InquiryRepository.php modules/_bundled/sirsoft-inquiry/src/Repositories/Contracts/InquiryRepositoryInterface.php modules/_bundled/sirsoft-inquiry/src/Providers/InquiryServiceProvider.php tests/Feature/Modules/Inquiry/ModelRelationshipTest.php
git commit -m "feat(inquiry): add InquiryRepository"
```

---

## Task 17: Repository — InquiryQuote

**Files:**
- Create: `src/Repositories/Contracts/InquiryQuoteRepositoryInterface.php`
- Create: `src/Repositories/InquiryQuoteRepository.php`

- [ ] **Step 1: 인터페이스 작성**

```php
<?php

namespace Modules\Sirsoft\Inquiry\Repositories\Contracts;

use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryQuote;

interface InquiryQuoteRepositoryInterface
{
    public function issue(Inquiry $inquiry, array $payload, array $items): InquiryQuote;

    public function expireActiveQuotes(Inquiry $inquiry): int;

    public function markAccepted(InquiryQuote $quote): void;

    public function markRejected(InquiryQuote $quote): void;

    public function findActiveForInquiry(Inquiry $inquiry): ?InquiryQuote;

    public function findOrFail(int $id): InquiryQuote;
}
```

- [ ] **Step 2: 실패 테스트**

`ModelRelationshipTest::test_quote_repository_issue_creates_versioned_quote()`:

```php
public function test_quote_repository_issue_creates_versioned_quote(): void
{
    $repo = app(\Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryQuoteRepositoryInterface::class);
    $inquiryRepo = app(\Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryRepositoryInterface::class);
    $user = User::factory()->create();
    $inquiry = $inquiryRepo->create([
        'uuid' => (string) \Str::uuid(),
        'user_id' => $user->id,
        'title' => 'X', 'content' => 'Y', 'status' => 'received',
    ]);

    $q1 = $repo->issue($inquiry, ['total_amount' => 1000000, 'tax_amount' => 0], [
        ['name' => 'A', 'qty' => 1, 'unit_price' => 1000000, 'amount' => 1000000],
    ]);
    $this->assertSame(1, $q1->version);
    $this->assertSame('issued', $q1->status->value);
    $this->assertSame(1, $q1->items()->count());

    $expired = $repo->expireActiveQuotes($inquiry);
    $this->assertSame(1, $expired);
    $this->assertSame('expired', $q1->fresh()->status->value);

    $q2 = $repo->issue($inquiry, ['total_amount' => 1200000], [
        ['name' => 'A', 'qty' => 1, 'unit_price' => 1200000, 'amount' => 1200000],
    ]);
    $this->assertSame(2, $q2->version);
}
```

```bash
php artisan test --filter=test_quote_repository_issue_creates_versioned_quote 2>&1 | tail -10
```

- [ ] **Step 3: 구현체 작성**

```php
<?php

namespace Modules\Sirsoft\Inquiry\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Inquiry\Enums\QuoteStatus;
use Modules\Sirsoft\Inquiry\Exceptions\QuoteNotFoundException;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryQuote;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryQuoteRepositoryInterface;

class InquiryQuoteRepository implements InquiryQuoteRepositoryInterface
{
    public function issue(Inquiry $inquiry, array $payload, array $items): InquiryQuote
    {
        return DB::transaction(function () use ($inquiry, $payload, $items) {
            $this->expireActiveQuotes($inquiry);

            $nextVersion = ($inquiry->quotes()->max('version') ?? 0) + 1;
            $quote = $inquiry->quotes()->create(array_merge($payload, [
                'version' => $nextVersion,
                'status' => QuoteStatus::Issued->value,
                'issued_at' => now(),
                'currency' => $payload['currency'] ?? config('inquiry.quote.currency', 'KRW'),
            ]));

            foreach ($items as $i => $item) {
                $quote->items()->create(array_merge($item, [
                    'position' => $item['position'] ?? $i + 1,
                ]));
            }

            return $quote;
        });
    }

    public function expireActiveQuotes(Inquiry $inquiry): int
    {
        return $inquiry->quotes()
            ->where('status', QuoteStatus::Issued->value)
            ->update(['status' => QuoteStatus::Expired->value]);
    }

    public function markAccepted(InquiryQuote $quote): void
    {
        $quote->update([
            'status' => QuoteStatus::Accepted->value,
            'accepted_at' => now(),
        ]);
    }

    public function markRejected(InquiryQuote $quote): void
    {
        $quote->update([
            'status' => QuoteStatus::Rejected->value,
            'rejected_at' => now(),
        ]);
    }

    public function findActiveForInquiry(Inquiry $inquiry): ?InquiryQuote
    {
        return $inquiry->quotes()
            ->where('status', QuoteStatus::Issued->value)
            ->latest('version')
            ->first();
    }

    public function findOrFail(int $id): InquiryQuote
    {
        return InquiryQuote::find($id) ?? throw new QuoteNotFoundException($id);
    }
}
```

- [ ] **Step 4: ServiceProvider $repositories 배열 갱신**

```php
\Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryQuoteRepositoryInterface::class
    => \Modules\Sirsoft\Inquiry\Repositories\InquiryQuoteRepository::class,
```

- [ ] **Step 5: 테스트 실행**

```bash
php artisan test --filter=test_quote_repository_issue_creates_versioned_quote 2>&1 | tail -10
```

- [ ] **Step 6: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/Repositories/InquiryQuoteRepository.php modules/_bundled/sirsoft-inquiry/src/Repositories/Contracts/InquiryQuoteRepositoryInterface.php modules/_bundled/sirsoft-inquiry/src/Providers/InquiryServiceProvider.php tests/Feature/Modules/Inquiry/ModelRelationshipTest.php
git commit -m "feat(inquiry): add InquiryQuoteRepository (issue/expire/accept)"
```

---

## Task 18: Repository — InquiryMessage

**Files:**
- Create: `src/Repositories/Contracts/InquiryMessageRepositoryInterface.php`
- Create: `src/Repositories/InquiryMessageRepository.php`

- [ ] **Step 1: 인터페이스 작성**

```php
<?php

namespace Modules\Sirsoft\Inquiry\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Inquiry\Enums\SenderRole;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryMessage;

interface InquiryMessageRepositoryInterface
{
    public function append(Inquiry $inquiry, int $senderUserId, SenderRole $role, string $body): InquiryMessage;

    public function appendSystem(Inquiry $inquiry, string $key, array $params = []): InquiryMessage;

    public function listForInquiry(Inquiry $inquiry, int $perPage = 50): LengthAwarePaginator;

    public function markReadFor(Inquiry $inquiry, SenderRole $oppositeRole): int;
}
```

- [ ] **Step 2: 실패 테스트**

`ModelRelationshipTest::test_message_repository_append_and_system()`:

```php
public function test_message_repository_append_and_system(): void
{
    $repo = app(\Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryMessageRepositoryInterface::class);
    $inquiryRepo = app(\Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryRepositoryInterface::class);
    $user = User::factory()->create();
    $inquiry = $inquiryRepo->create([
        'uuid' => (string) \Str::uuid(),
        'user_id' => $user->id,
        'title' => 'X', 'content' => 'Y', 'status' => 'received',
    ]);

    $msg = $repo->append($inquiry, $user->id, \Modules\Sirsoft\Inquiry\Enums\SenderRole::Client, '안녕하세요');
    $this->assertSame('client', $msg->sender_role->value);

    $sys = $repo->appendSystem($inquiry, 'inquiry::system.message.quote_issued', ['version' => 1, 'total' => '1,000,000']);
    $this->assertSame('system', $sys->sender_role->value);
    $this->assertNull($sys->body);
    $this->assertSame('inquiry::system.message.quote_issued', $sys->meta['key']);
    $this->assertSame(1, $sys->meta['params']['version']);
}
```

```bash
php artisan test --filter=test_message_repository_append_and_system 2>&1 | tail -10
```

- [ ] **Step 3: 구현체 작성**

```php
<?php

namespace Modules\Sirsoft\Inquiry\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Inquiry\Enums\SenderRole;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryMessage;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryMessageRepositoryInterface;

class InquiryMessageRepository implements InquiryMessageRepositoryInterface
{
    public function append(Inquiry $inquiry, int $senderUserId, SenderRole $role, string $body): InquiryMessage
    {
        return $inquiry->messages()->create([
            'sender_user_id' => $senderUserId,
            'sender_role' => $role->value,
            'body' => $body,
        ]);
    }

    public function appendSystem(Inquiry $inquiry, string $key, array $params = []): InquiryMessage
    {
        return $inquiry->messages()->create([
            'sender_user_id' => null,
            'sender_role' => SenderRole::System->value,
            'body' => null,
            'meta' => ['key' => $key, 'params' => $params],
        ]);
    }

    public function listForInquiry(Inquiry $inquiry, int $perPage = 50): LengthAwarePaginator
    {
        return $inquiry->messages()->orderBy('created_at')->paginate($perPage);
    }

    public function markReadFor(Inquiry $inquiry, SenderRole $oppositeRole): int
    {
        return $inquiry->messages()
            ->where('sender_role', $oppositeRole->value)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
```

- [ ] **Step 4: ServiceProvider 갱신**

`InquiryMessageRepositoryInterface => InquiryMessageRepository`.

- [ ] **Step 5: 테스트 + Commit**

```bash
php artisan test --filter=test_message_repository_append_and_system 2>&1 | tail -10
git add modules/_bundled/sirsoft-inquiry/src/Repositories/InquiryMessageRepository.php modules/_bundled/sirsoft-inquiry/src/Repositories/Contracts/InquiryMessageRepositoryInterface.php modules/_bundled/sirsoft-inquiry/src/Providers/InquiryServiceProvider.php tests/Feature/Modules/Inquiry/ModelRelationshipTest.php
git commit -m "feat(inquiry): add InquiryMessageRepository (with appendSystem)"
```

---

## Task 19: Repository — InquiryAttachment

**Files:**
- Create: `src/Repositories/Contracts/InquiryAttachmentRepositoryInterface.php`
- Create: `src/Repositories/InquiryAttachmentRepository.php`

- [ ] **Step 1: 인터페이스 + 구현 작성 (단순 CRUD)**

`Contracts/InquiryAttachmentRepositoryInterface.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Repositories\Contracts;

use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryAttachment;
use Modules\Sirsoft\Inquiry\Models\InquiryMessage;

interface InquiryAttachmentRepositoryInterface
{
    public function create(array $data): InquiryAttachment;

    public function attachToMessage(InquiryAttachment $attachment, InquiryMessage $message): void;

    public function findOrFail(int $id): InquiryAttachment;

    public function listOrphansOlderThanMinutes(int $minutes): \Illuminate\Support\Collection;

    public function delete(InquiryAttachment $attachment): void;
}
```

`InquiryAttachmentRepository.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Repositories;

use Illuminate\Support\Collection;
use Modules\Sirsoft\Inquiry\Models\InquiryAttachment;
use Modules\Sirsoft\Inquiry\Models\InquiryMessage;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryAttachmentRepositoryInterface;

class InquiryAttachmentRepository implements InquiryAttachmentRepositoryInterface
{
    public function create(array $data): InquiryAttachment
    {
        return InquiryAttachment::create($data);
    }

    public function attachToMessage(InquiryAttachment $attachment, InquiryMessage $message): void
    {
        $attachment->update(['message_id' => $message->id]);
    }

    public function findOrFail(int $id): InquiryAttachment
    {
        return InquiryAttachment::findOrFail($id);
    }

    public function listOrphansOlderThanMinutes(int $minutes): Collection
    {
        return InquiryAttachment::query()
            ->whereNull('message_id')
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->get();
    }

    public function delete(InquiryAttachment $attachment): void
    {
        $attachment->delete();
    }
}
```

- [ ] **Step 2: ServiceProvider 갱신 + 간단 검증**

```bash
php artisan tinker --execute="dump(get_class(app(\Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryAttachmentRepositoryInterface::class)));"
```

기대: `"Modules\Sirsoft\Inquiry\Repositories\InquiryAttachmentRepository"`.

- [ ] **Step 3: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/Repositories/InquiryAttachmentRepository.php modules/_bundled/sirsoft-inquiry/src/Repositories/Contracts/InquiryAttachmentRepositoryInterface.php modules/_bundled/sirsoft-inquiry/src/Providers/InquiryServiceProvider.php
git commit -m "feat(inquiry): add InquiryAttachmentRepository"
```

---

## Task 20: StateMachine — happy path (`issue_quote`)

**Files:**
- Create: `src/Services/InquiryStateMachine.php`
- Create: `tests/Feature/Modules/Inquiry/StateMachineTest.php`

- [ ] **Step 1: 실패 테스트 작성**

```php
<?php

namespace Tests\Feature\Modules\Inquiry;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Sirsoft\Inquiry\Enums\InquiryStatus;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use Modules\Sirsoft\Inquiry\Events\InquiryStatusTransitioned;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Services\InquiryStateMachine;
use Tests\TestCase;

class StateMachineTest extends TestCase
{
    use RefreshDatabase;

    private function makeInquiry(array $overrides = []): Inquiry
    {
        $user = User::factory()->create();
        return Inquiry::create(array_merge([
            'uuid' => (string) \Str::uuid(),
            'user_id' => $user->id,
            'title' => 'X', 'content' => 'Y',
            'status' => 'received',
        ], $overrides));
    }

    public function test_issue_quote_transitions_received_to_quoted_and_emits_system_message(): void
    {
        Event::fake([InquiryStatusTransitioned::class]);
        $sm = app(InquiryStateMachine::class);
        $inquiry = $this->makeInquiry();

        $sm->transition($inquiry, TransitionEvent::IssueQuote, actorUserId: 1, payload: ['quote_version' => 1, 'quote_total' => 1000000]);

        $inquiry->refresh();
        $this->assertSame(InquiryStatus::Quoted, $inquiry->status);
        $this->assertNotNull($inquiry->quoted_at);

        $sys = $inquiry->messages()->where('sender_role', 'system')->first();
        $this->assertNotNull($sys);
        $this->assertSame('inquiry::system.message.quote_issued', $sys->meta['key']);

        Event::assertDispatched(InquiryStatusTransitioned::class, fn ($e) =>
            $e->from === InquiryStatus::Received && $e->to === InquiryStatus::Quoted
        );
    }
}
```

```bash
php artisan test --filter=test_issue_quote_transitions_received_to_quoted_and_emits_system_message 2>&1 | tail -10
```

기대: 실패 (Service 미존재).

- [ ] **Step 2: StateMachine 작성 (issue_quote 만)**

```php
<?php

namespace Modules\Sirsoft\Inquiry\Services;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Inquiry\Enums\InquiryStatus;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use Modules\Sirsoft\Inquiry\Events\InquiryStatusTransitioned;
use Modules\Sirsoft\Inquiry\Exceptions\InvalidStateTransitionException;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryMessageRepositoryInterface;

class InquiryStateMachine
{
    /** @var array<string, array{from: array<int, InquiryStatus>, to: InquiryStatus, systemKey: string, timestampColumn: ?string}> */
    private array $rules;

    public function __construct(
        private readonly InquiryMessageRepositoryInterface $messages,
    ) {
        $this->rules = [
            TransitionEvent::IssueQuote->value => [
                'from' => [InquiryStatus::Received],
                'to' => InquiryStatus::Quoted,
                'systemKey' => 'inquiry::system.message.quote_issued',
                'timestampColumn' => 'quoted_at',
            ],
        ];
    }

    public function transition(Inquiry $inquiry, TransitionEvent $event, ?int $actorUserId = null, array $payload = []): Inquiry
    {
        $rule = $this->rules[$event->value]
            ?? throw new InvalidStateTransitionException($inquiry->status, $event);

        if (! in_array($inquiry->status, $rule['from'], true)) {
            throw new InvalidStateTransitionException($inquiry->status, $event);
        }

        $from = $inquiry->status;
        $to = $rule['to'];

        DB::transaction(function () use ($inquiry, $rule, $to, $payload) {
            $inquiry->status = $to->value;
            if ($rule['timestampColumn']) {
                $inquiry->{$rule['timestampColumn']} = now();
            }
            $inquiry->save();

            $params = $this->systemMessageParams($payload);
            $this->messages->appendSystem($inquiry, $rule['systemKey'], $params);
        });

        InquiryStatusTransitioned::dispatch($inquiry, $from, $to, $event, $actorUserId);

        return $inquiry;
    }

    private function systemMessageParams(array $payload): array
    {
        return array_filter([
            'version' => $payload['quote_version'] ?? null,
            'total' => isset($payload['quote_total']) ? number_format($payload['quote_total']) : null,
            'order' => $payload['order_uuid'] ?? null,
            'actor' => $payload['actor'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
```

- [ ] **Step 3: 테스트 실행 (성공)**

```bash
php artisan test --filter=test_issue_quote_transitions_received_to_quoted_and_emits_system_message 2>&1 | tail -10
```

- [ ] **Step 4: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/Services/InquiryStateMachine.php tests/Feature/Modules/Inquiry/StateMachineTest.php
git commit -m "feat(inquiry): add StateMachine with issue_quote transition"
```

---

## Task 21: StateMachine — 나머지 전이 추가

**Files:**
- Modify: `src/Services/InquiryStateMachine.php`
- Modify: `tests/Feature/Modules/Inquiry/StateMachineTest.php`

- [ ] **Step 1: 실패 테스트 추가 (6개 전이)**

```php
public function test_revoke_quote_back_to_received(): void
{
    $sm = app(InquiryStateMachine::class);
    $inquiry = $this->makeInquiry(['status' => 'quoted', 'quoted_at' => now()]);
    $sm->transition($inquiry, TransitionEvent::RevokeQuote, 1, ['quote_version' => 1]);
    $this->assertSame(InquiryStatus::Received, $inquiry->refresh()->status);
}

public function test_reject_quote_back_to_received(): void
{
    $sm = app(InquiryStateMachine::class);
    $inquiry = $this->makeInquiry(['status' => 'quoted', 'quoted_at' => now()]);
    $sm->transition($inquiry, TransitionEvent::RejectQuote, 1, ['quote_version' => 1]);
    $this->assertSame(InquiryStatus::Received, $inquiry->refresh()->status);
}

public function test_accept_and_pay_to_in_progress(): void
{
    $sm = app(InquiryStateMachine::class);
    $inquiry = $this->makeInquiry(['status' => 'quoted', 'quoted_at' => now()]);
    $sm->transition($inquiry, TransitionEvent::AcceptAndPay, null, ['order_uuid' => 'order-xyz']);
    $this->assertSame(InquiryStatus::InProgress, $inquiry->refresh()->status);
    $this->assertNotNull($inquiry->started_at);
}

public function test_mark_paid_offline_to_in_progress(): void
{
    $sm = app(InquiryStateMachine::class);
    $inquiry = $this->makeInquiry(['status' => 'quoted', 'quoted_at' => now()]);
    $sm->transition($inquiry, TransitionEvent::MarkPaidOffline, 1);
    $this->assertSame(InquiryStatus::InProgress, $inquiry->refresh()->status);
}

public function test_mark_completed_from_in_progress(): void
{
    $sm = app(InquiryStateMachine::class);
    $inquiry = $this->makeInquiry(['status' => 'in_progress', 'started_at' => now()]);
    $sm->transition($inquiry, TransitionEvent::MarkCompleted, 1);
    $this->assertSame(InquiryStatus::Completed, $inquiry->refresh()->status);
    $this->assertNotNull($inquiry->completed_at);
}

public function test_cancel_from_any_active_state(): void
{
    $sm = app(InquiryStateMachine::class);
    foreach (['received', 'quoted', 'in_progress'] as $from) {
        $inquiry = $this->makeInquiry(['status' => $from]);
        $sm->transition($inquiry, TransitionEvent::Cancel, 1, ['actor' => 'client']);
        $this->assertSame(InquiryStatus::Canceled, $inquiry->refresh()->status, "from {$from}");
        $this->assertNotNull($inquiry->canceled_at);
    }
}
```

```bash
php artisan test --filter=StateMachineTest 2>&1 | tail -20
```

기대: 새 6개 테스트 실패 (`Invalid transition` 또는 nullpointer).

- [ ] **Step 2: rules 배열 확장**

`InquiryStateMachine::__construct()` 의 `$this->rules` 를 다음으로 교체:

```php
$this->rules = [
    TransitionEvent::IssueQuote->value => [
        'from' => [InquiryStatus::Received],
        'to' => InquiryStatus::Quoted,
        'systemKey' => 'inquiry::system.message.quote_issued',
        'timestampColumn' => 'quoted_at',
    ],
    TransitionEvent::RevokeQuote->value => [
        'from' => [InquiryStatus::Quoted],
        'to' => InquiryStatus::Received,
        'systemKey' => 'inquiry::system.message.quote_revoked',
        'timestampColumn' => null,
    ],
    TransitionEvent::RejectQuote->value => [
        'from' => [InquiryStatus::Quoted],
        'to' => InquiryStatus::Received,
        'systemKey' => 'inquiry::system.message.quote_rejected',
        'timestampColumn' => null,
    ],
    TransitionEvent::AcceptAndPay->value => [
        'from' => [InquiryStatus::Quoted],
        'to' => InquiryStatus::InProgress,
        'systemKey' => 'inquiry::system.message.payment_confirmed',
        'timestampColumn' => 'started_at',
    ],
    TransitionEvent::MarkPaidOffline->value => [
        'from' => [InquiryStatus::Quoted],
        'to' => InquiryStatus::InProgress,
        'systemKey' => 'inquiry::system.message.payment_confirmed_offline',
        'timestampColumn' => 'started_at',
    ],
    TransitionEvent::MarkCompleted->value => [
        'from' => [InquiryStatus::InProgress],
        'to' => InquiryStatus::Completed,
        'systemKey' => 'inquiry::system.message.completed',
        'timestampColumn' => 'completed_at',
    ],
    TransitionEvent::Cancel->value => [
        'from' => [InquiryStatus::Received, InquiryStatus::Quoted, InquiryStatus::InProgress],
        'to' => InquiryStatus::Canceled,
        'systemKey' => 'inquiry::system.message.canceled_by_client', // payload['actor']로 분기는 systemMessageParams에서
        'timestampColumn' => 'canceled_at',
    ],
];
```

`transition()` 안에서 `Cancel` 이벤트일 때 `actor` payload에 따라 systemKey 분기:

```php
$systemKey = $rule['systemKey'];
if ($event === TransitionEvent::Cancel && ($payload['actor'] ?? null) === 'operator') {
    $systemKey = 'inquiry::system.message.canceled_by_operator';
}
```

`appendSystem` 호출을 `$systemKey` 변수 사용으로 변경.

- [ ] **Step 3: 테스트 실행 (성공)**

```bash
php artisan test --filter=StateMachineTest 2>&1 | tail -20
```

기대: 모든 전이 테스트 PASS.

- [ ] **Step 4: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/Services/InquiryStateMachine.php tests/Feature/Modules/Inquiry/StateMachineTest.php
git commit -m "feat(inquiry): complete StateMachine transitions (revoke/reject/accept/offline/complete/cancel)"
```

---

## Task 22: StateMachine — 불법 전이 거부

**Files:**
- Modify: `tests/Feature/Modules/Inquiry/StateMachineTest.php`

- [ ] **Step 1: 불법 전이 테스트 추가**

```php
public function test_invalid_transition_throws(): void
{
    $sm = app(InquiryStateMachine::class);
    $inquiry = $this->makeInquiry(['status' => 'received']);

    $this->expectException(\Modules\Sirsoft\Inquiry\Exceptions\InvalidStateTransitionException::class);
    $sm->transition($inquiry, TransitionEvent::AcceptAndPay);
}

public function test_cannot_transition_from_terminal_states(): void
{
    $sm = app(InquiryStateMachine::class);
    foreach (['completed', 'canceled'] as $terminal) {
        $inquiry = $this->makeInquiry(['status' => $terminal]);
        try {
            $sm->transition($inquiry, TransitionEvent::IssueQuote);
            $this->fail("Expected exception from terminal state {$terminal}");
        } catch (\Modules\Sirsoft\Inquiry\Exceptions\InvalidStateTransitionException $e) {
            $this->assertSame(422, $e->getCode());
        }
    }
}
```

- [ ] **Step 2: 실행 (이미 통과해야 함 — Task 20 의 from 검증으로)**

```bash
php artisan test --filter=test_invalid_transition_throws 2>&1 | tail -10
php artisan test --filter=test_cannot_transition_from_terminal_states 2>&1 | tail -10
```

기대: PASS. 실패 시 `transition()` 의 `from` 검증 로직을 점검.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Modules/Inquiry/StateMachineTest.php
git commit -m "test(inquiry): cover illegal state transitions"
```

---

## Task 23: AttachmentStorage 서비스

**Files:**
- Create: `src/Services/InquiryAttachmentStorage.php`
- Create: `tests/Feature/Modules/Inquiry/AttachmentStorageTest.php`

- [ ] **Step 1: 실패 테스트 작성**

```php
<?php

namespace Tests\Feature\Modules\Inquiry;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Services\InquiryAttachmentStorage;
use Tests\TestCase;

class AttachmentStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_attachment_record_and_persists_file(): void
    {
        Storage::fake('local');
        $svc = app(InquiryAttachmentStorage::class);
        $user = User::factory()->create();
        $inquiry = Inquiry::create([
            'uuid' => (string) \Str::uuid(),
            'user_id' => $user->id,
            'title' => 'X', 'content' => 'Y', 'status' => 'received',
        ]);
        $file = UploadedFile::fake()->create('plan.pdf', 100, 'application/pdf');

        $att = $svc->store($inquiry, $user->id, $file, context: 'message');

        $this->assertSame('application/pdf', $att->mime);
        $this->assertSame('plan.pdf', $att->original_name);
        Storage::disk('local')->assertExists($att->path);
    }

    public function test_store_rejects_disallowed_mime(): void
    {
        Storage::fake('local');
        $svc = app(InquiryAttachmentStorage::class);
        $user = User::factory()->create();
        $inquiry = Inquiry::create([
            'uuid' => (string) \Str::uuid(),
            'user_id' => $user->id,
            'title' => 'X', 'content' => 'Y', 'status' => 'received',
        ]);
        $file = UploadedFile::fake()->create('bad.exe', 10, 'application/x-msdownload');

        $this->expectException(\InvalidArgumentException::class);
        $svc->store($inquiry, $user->id, $file, context: 'message');
    }

    public function test_store_rejects_oversize_file(): void
    {
        config(['inquiry.attachment.max_size_message' => 1024]); // 1KB
        Storage::fake('local');
        $svc = app(InquiryAttachmentStorage::class);
        $user = User::factory()->create();
        $inquiry = Inquiry::create([
            'uuid' => (string) \Str::uuid(),
            'user_id' => $user->id,
            'title' => 'X', 'content' => 'Y', 'status' => 'received',
        ]);
        $file = UploadedFile::fake()->create('big.pdf', 10, 'application/pdf'); // 10KB

        $this->expectException(\InvalidArgumentException::class);
        $svc->store($inquiry, $user->id, $file, context: 'message');
    }
}
```

```bash
php artisan test --filter=AttachmentStorageTest 2>&1 | tail -20
```

기대: 3개 실패.

- [ ] **Step 2: 서비스 작성**

```php
<?php

namespace Modules\Sirsoft\Inquiry\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryAttachment;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryAttachmentRepositoryInterface;

class InquiryAttachmentStorage
{
    public function __construct(
        private readonly InquiryAttachmentRepositoryInterface $attachments,
    ) {}

    /**
     * @param 'inquiry'|'message' $context
     */
    public function store(Inquiry $inquiry, int $uploaderUserId, UploadedFile $file, string $context = 'message'): InquiryAttachment
    {
        $mime = $file->getMimeType() ?? $file->getClientMimeType();
        $allowed = config('inquiry.attachment.allowed_mimes', []);
        if (! in_array($mime, $allowed, true)) {
            throw new InvalidArgumentException("Disallowed mime: {$mime}");
        }

        $maxKey = $context === 'inquiry' ? 'max_size_inquiry' : 'max_size_message';
        $maxBytes = (int) config("inquiry.attachment.{$maxKey}");
        if ($file->getSize() > $maxBytes) {
            throw new InvalidArgumentException("File too large: {$file->getSize()} > {$maxBytes}");
        }

        $disk = config('inquiry.attachment.disk', 'local');
        $path = $file->store("inquiries/{$inquiry->uuid}", $disk);

        return $this->attachments->create([
            'inquiry_id' => $inquiry->id,
            'message_id' => null,
            'uploader_user_id' => $uploaderUserId,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $mime,
            'size' => $file->getSize(),
        ]);
    }
}
```

- [ ] **Step 3: 테스트 실행 (성공)**

```bash
php artisan test --filter=AttachmentStorageTest 2>&1 | tail -20
```

- [ ] **Step 4: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/Services/InquiryAttachmentStorage.php tests/Feature/Modules/Inquiry/AttachmentStorageTest.php
git commit -m "feat(inquiry): add InquiryAttachmentStorage service (mime/size validation)"
```

---

## Task 24: InquiryPolicy

**Files:**
- Create: `src/Policies/InquiryPolicy.php`
- Create: `tests/Feature/Modules/Inquiry/PolicyTest.php`

- [ ] **Step 1: 실패 테스트 작성**

```php
<?php

namespace Tests\Feature\Modules\Inquiry;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Tests\TestCase;

class PolicyTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserAndInquiry(string $status = 'received'): array
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $operator = User::factory()->create();
        // 운영자 권한 부여 — 프로젝트의 권한 부여 방식에 맞춰 구현.
        // ex) Spatie/Permission: $operator->givePermissionTo('inquiry.manage');
        $operator->givePermissionTo('inquiry.manage');

        $inquiry = Inquiry::create([
            'uuid' => (string) \Str::uuid(),
            'user_id' => $owner->id,
            'title' => 'X', 'content' => 'Y',
            'status' => $status,
        ]);

        return compact('owner', 'other', 'operator', 'inquiry');
    }

    public function test_owner_can_view_others_cannot(): void
    {
        ['owner' => $owner, 'other' => $other, 'inquiry' => $inquiry] = $this->makeUserAndInquiry();
        $this->assertTrue($owner->can('view', $inquiry));
        $this->assertFalse($other->can('view', $inquiry));
    }

    public function test_operator_can_view(): void
    {
        ['operator' => $op, 'inquiry' => $inquiry] = $this->makeUserAndInquiry();
        $this->assertTrue($op->can('view', $inquiry));
    }

    public function test_owner_can_update_only_in_received(): void
    {
        ['owner' => $owner, 'inquiry' => $inquiry] = $this->makeUserAndInquiry('received');
        $this->assertTrue($owner->can('update', $inquiry));

        $inquiry->update(['status' => 'quoted']);
        $this->assertFalse($owner->can('update', $inquiry->fresh()));
    }

    public function test_only_operator_can_issue_quote(): void
    {
        ['owner' => $owner, 'operator' => $op, 'inquiry' => $inquiry] = $this->makeUserAndInquiry('received');
        $this->assertFalse($owner->can('issueQuote', $inquiry));
        $this->assertTrue($op->can('issueQuote', $inquiry));
    }

    public function test_owner_can_accept_quote_only_in_quoted(): void
    {
        ['owner' => $owner, 'inquiry' => $inquiry] = $this->makeUserAndInquiry('received');
        $this->assertFalse($owner->can('acceptQuote', $inquiry));
        $inquiry->update(['status' => 'quoted']);
        $this->assertTrue($owner->can('acceptQuote', $inquiry->fresh()));
    }
}
```

> 권한 부여 메서드(`givePermissionTo`)는 프로젝트의 권한 시스템에 따라 다를 수 있다. 작업 전 `grep -rn "givePermissionTo\|assignRole" app/` 로 실제 메서드 확인. 다르면 본 테스트와 Policy의 `userIsOperator()` 헬퍼 모두 그 패턴에 맞추어 보정.

```bash
php artisan test --filter=PolicyTest 2>&1 | tail -20
```

기대: 실패 (Policy 미존재 또는 권한 시스템 미연동).

- [ ] **Step 2: Policy 작성**

```php
<?php

namespace Modules\Sirsoft\Inquiry\Policies;

use App\Models\User;
use Modules\Sirsoft\Inquiry\Enums\InquiryStatus;
use Modules\Sirsoft\Inquiry\Models\Inquiry;

class InquiryPolicy
{
    public function view(User $user, Inquiry $inquiry): bool
    {
        return $this->isOwner($user, $inquiry) || $this->isOperator($user);
    }

    public function update(User $user, Inquiry $inquiry): bool
    {
        if ($this->isOperator($user)) {
            return true;
        }
        return $this->isOwner($user, $inquiry) && $inquiry->status === InquiryStatus::Received;
    }

    public function cancel(User $user, Inquiry $inquiry): bool
    {
        if ($this->isOperator($user)) {
            return ! in_array($inquiry->status, [InquiryStatus::Completed, InquiryStatus::Canceled], true);
        }
        return $this->isOwner($user, $inquiry)
            && in_array($inquiry->status, [InquiryStatus::Received, InquiryStatus::Quoted], true);
    }

    public function issueQuote(User $user, Inquiry $inquiry): bool
    {
        return $this->isOperator($user);
    }

    public function revokeQuote(User $user, Inquiry $inquiry): bool
    {
        return $this->isOperator($user) && $inquiry->accepted_quote_id === null;
    }

    public function acceptQuote(User $user, Inquiry $inquiry): bool
    {
        return $this->isOwner($user, $inquiry) && $inquiry->status === InquiryStatus::Quoted;
    }

    public function rejectQuote(User $user, Inquiry $inquiry): bool
    {
        return $this->acceptQuote($user, $inquiry);
    }

    public function markPaidOffline(User $user, Inquiry $inquiry): bool
    {
        return $this->isOperator($user);
    }

    public function markCompleted(User $user, Inquiry $inquiry): bool
    {
        return $this->isOperator($user);
    }

    public function postMessage(User $user, Inquiry $inquiry): bool
    {
        return $this->view($user, $inquiry);
    }

    public function viewAttachment(User $user, Inquiry $inquiry): bool
    {
        return $this->view($user, $inquiry);
    }

    public function uploadAttachment(User $user, Inquiry $inquiry): bool
    {
        return $this->view($user, $inquiry);
    }

    private function isOwner(User $user, Inquiry $inquiry): bool
    {
        return $user->id === $inquiry->user_id;
    }

    private function isOperator(User $user): bool
    {
        return $user->can(config('inquiry.permissions.manage', 'inquiry.manage'));
    }
}
```

- [ ] **Step 3: ServiceProvider 에 Policy 등록**

`InquiryServiceProvider::boot()` 에 추가:

```php
\Illuminate\Support\Facades\Gate::policy(
    \Modules\Sirsoft\Inquiry\Models\Inquiry::class,
    \Modules\Sirsoft\Inquiry\Policies\InquiryPolicy::class,
);
```

- [ ] **Step 4: 권한 시드(테스트용)**

기존 권한 등록 패턴에 맞춰 `inquiry.manage`, `inquiry.notify` 권한을 시드/마이그레이션 또는 `database/seeders/InquiryPermissionsSeeder.php`로 생성. 본 plan은 다음 plan(Phase B)으로 미루지 않고 테스트 setUp 안에서 수동 생성:

`tests/Feature/Modules/Inquiry/PolicyTest::setUp()`:

```php
protected function setUp(): void
{
    parent::setUp();
    // 프로젝트가 Spatie/Permission을 쓴다면:
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'inquiry.manage']);
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'inquiry.notify']);
}
```

- [ ] **Step 5: 테스트 실행 (성공)**

```bash
php artisan test --filter=PolicyTest 2>&1 | tail -20
```

기대: 5개 모두 PASS.

- [ ] **Step 6: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/Policies/ modules/_bundled/sirsoft-inquiry/src/Providers/InquiryServiceProvider.php tests/Feature/Modules/Inquiry/PolicyTest.php
git commit -m "feat(inquiry): add InquiryPolicy with permission matrix"
```

---

## Task 25: ServiceProvider 최종 정리 + 통합 검증

**Files:**
- Modify: `src/Providers/InquiryServiceProvider.php`

- [ ] **Step 1: 모든 바인딩이 등록되었는지 최종 확인**

```php
protected array $repositories = [
    \Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryRepositoryInterface::class
        => \Modules\Sirsoft\Inquiry\Repositories\InquiryRepository::class,
    \Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryQuoteRepositoryInterface::class
        => \Modules\Sirsoft\Inquiry\Repositories\InquiryQuoteRepository::class,
    \Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryMessageRepositoryInterface::class
        => \Modules\Sirsoft\Inquiry\Repositories\InquiryMessageRepository::class,
    \Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryAttachmentRepositoryInterface::class
        => \Modules\Sirsoft\Inquiry\Repositories\InquiryAttachmentRepository::class,
];
```

- [ ] **Step 2: 통합 검증 — 전체 fresh + 전체 테스트**

```bash
php artisan migrate:fresh --path=modules/_bundled/sirsoft-inquiry/database/migrations
php artisan test --filter="Modules\\\\Inquiry" 2>&1 | tail -30
```

기대: 모든 테스트 PASS (ModuleBootstrap, Enums, ModelRelationship, StateMachine, AttachmentStorage, Policy).

- [ ] **Step 3: ServiceProvider 와 컨테이너 lookup 빠른 sanity check**

```bash
php artisan tinker --execute='
$bindings = [
    \Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryRepositoryInterface::class,
    \Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryQuoteRepositoryInterface::class,
    \Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryMessageRepositoryInterface::class,
    \Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryAttachmentRepositoryInterface::class,
    \Modules\Sirsoft\Inquiry\Services\InquiryStateMachine::class,
    \Modules\Sirsoft\Inquiry\Services\InquiryAttachmentStorage::class,
];
foreach ($bindings as $b) { dump([$b, get_class(app($b))]); }
'
```

기대: 각 인터페이스가 구체 클래스로 해소됨.

- [ ] **Step 4: 최종 Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/Providers/InquiryServiceProvider.php
git commit -m "feat(inquiry): finalize ServiceProvider bindings — Phase 1 (foundation) done"
```

---

## Task 26: Phase 1 회고 + Phase 2 진입 준비

- [ ] **Step 1: 전체 commit 로그 확인**

```bash
git log --oneline | head -30
```

기대: 25-30개 commit (Phase 1 단계).

- [ ] **Step 2: 변경 통계**

```bash
git diff --stat $(git log --oneline | grep "feat(inquiry): scaffold module skeleton" | awk '{print $1}')^..HEAD
```

- [ ] **Step 3: 다음 Plan으로 이동 신호**

이 Plan의 모든 task가 통과하면 Plan 2 (Backend API + 사용자 프론트)로 진입할 수 있다. Plan 2의 첫 task는 `Resources` (InquiryResource, InquiryMessageResource, InquiryAttachmentResource) + 의뢰 CRUD 컨트롤러부터 시작한다.

---

## 부록 A — 자주 발생할 수 있는 문제

**모듈 마이그레이션 실행 절차 (Task 5-9, 25 공통)**

이 프로젝트의 모듈 마이그레이션은 `php artisan migrate` 가 자동으로 잡지 않는다. `ModuleManager::runMigrations()` 가 `module:install` / `module:activate` / `module:update` 시점에 실행한다. 따라서 본 plan Task 5-9 의 "마이그레이션 실행 검증" 단계는 다음 패턴 중 환경에 맞는 것을 사용한다:

```bash
# 패턴 A — 매 마이그레이션 추가마다 재설치 (idempotent하다면)
php artisan module:uninstall sirsoft-inquiry 2>&1 | tail -3
php artisan module:install sirsoft-inquiry 2>&1 | tail -3
php artisan module:activate sirsoft-inquiry 2>&1 | tail -3

# 패턴 B — 마이그레이션 작성 시점엔 syntax 만 검증, 실제 실행은 Task 9에서 한 번에
php -l modules/_bundled/sirsoft-inquiry/database/migrations/<file>.php
# Task 9 끝에서 한 번에 install/activate

# 패턴 C — module:update 가 새 마이그레이션을 감지한다면
php artisan module:update sirsoft-inquiry 2>&1 | tail -5

# 테이블 존재 검증
php artisan tinker --execute="dump(\Schema::hasTable('inquiries'));"
```

implementer는 Pre-3에서 발견한 실제 명령 패턴(`module:list` 출력 / `module:update` 존재 여부)에 따라 위 중 하나를 선택한다. Task 5의 첫 마이그레이션 검증에서 선택한 패턴을 Task 6-9, 25 에서 일관 적용.

**모듈 인식 자체가 안 될 때**
- `bootstrap/cache/autoload-extensions.php` 갱신 누락 가능. `php artisan module:composer-install sirsoft-inquiry` 또는 `composer dump-autoload` 실행.
- `modules/_bundled/sirsoft-inquiry/module.json` 의 `identifier` 와 디렉터리명이 일치해야 함.
- board 모듈에 `module.php` 같은 부가 manifest가 있는지 비교: `ls modules/_bundled/sirsoft-board/module.php`. 있으면 동일 구조로 inquiry에도 추가.

**Spatie/Permission 미사용 환경**
- 권한 부여 메서드가 다를 수 있음. `$user->permissions()->attach($id)` 같은 패턴. `InquiryPolicy::isOperator()` 안의 `$user->can(...)` 가 동작하는지가 핵심 — 작동하면 권한 시스템에 상관없이 통과.

**`uuid` 컬럼 인덱스 충돌**
- 일부 MySQL 버전에서 `uuid` + `unique` 가 길이 문제로 실패. 그럴 경우 `$table->char('uuid', 36)->unique()` 로 변경.

**`Illuminate\Support\Str::uuid()` import**
- 테스트에서 `\Str::uuid()` 사용 시 helper 미등록. `use Illuminate\Support\Str;` 후 `Str::uuid()` 또는 `(string) Str::uuid()` 사용.

## 부록 B — Plan vs 코드 불일치 처리 가이드 (implementer/reviewer 공통)

본 plan 작성 후 `BaseModuleServiceProvider` / `ModuleManager` 패턴을 재검증하여 다음 사실을 plan 본문에 반영했다:

1. ServiceProvider는 `boot()`/`register()` 재정의 불필요 (Task 2). config 머지가 필요할 때만 `register()` 안에서 처리 (Task 3).
2. 마이그레이션 실행은 `module:install`/`activate` 흐름. `php artisan migrate --path=...` 사용 금지.
3. 다국어는 자동 로드(`{module}/src/lang`). `loadTranslationsFrom` 직접 호출 금지.

spec reviewer / code reviewer 는 위 3가지를 plan 의 정답으로 간주하고, plan 본문의 옛 코드 블록 중 위와 충돌하는 부분이 발견되면 본 부록 B 를 따른다.
