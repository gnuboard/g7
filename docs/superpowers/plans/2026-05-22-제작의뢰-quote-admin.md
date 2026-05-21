# 제작의뢰 모듈 — 견적·결제·어드민 (Plan 3/4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Plan 1+2 위에 견적 발행/철회/수락/거절 흐름, sirsoft-ecommerce 결제 연동(선택적), 운영자 어드민 화면(목록·상세·견적 작성), 마지막 단계 전이(mark_paid_offline / mark_completed / operator cancel)를 추가하여 의뢰 라이프사이클 전 과정을 한 모듈에서 처리 가능한 상태로 만든다.

**Architecture:** Spec §11.1 의 견적 accept/reject 2개 + §11.2 어드민 6개 라우트 = 총 8개 신규 라우트. `Admin\InquiryController` / `Admin\InquiryQuoteController` / `User\InquiryQuoteController` 신설. `InquiryPaymentBridge` 서비스가 ecommerce 모듈 클래스 존재 시에만 Order 생성·결제 후 listener 등록. 어드민 layout 3개 + 위험 액션 모달 partial 4개. `inquiry:expire-quotes` cron 명령 + lazy expiry. ecommerce 연동이 실패해도 `mark_paid_offline` 경로로 인한 진행 가능.

**Tech Stack:** Laravel 11 / Sanctum / Eloquent / sirsoft-board admin layout DSL 패턴 / sirsoft-ecommerce Order 모델 / Schedule.

**Spec:** `docs/superpowers/specs/2026-05-20-제작의뢰-design.md`
**Plan 1:** `docs/superpowers/plans/2026-05-21-제작의뢰-backend-foundation.md`
**Plan 2:** `docs/superpowers/plans/2026-05-21-제작의뢰-api-frontend.md`

---

## Critical Facts (확정 사실)

### ecommerce 모듈 의존성

ecommerce 모듈에는 명시적 `OrderPaid` event class가 없다 (`find modules/_bundled/sirsoft-ecommerce/src/Events` 빈 디렉터리 / 디렉터리 없음). 결제 완료 신호는 다른 메커니즘:
- `EcommerceNotificationDataListener` 안의 `order_completed` 키 처리 흐름
- `Order` 모델의 상태 변경 시점

따라서 본 plan의 `InquiryPaymentBridge` 는 두 가지 접근을 가진다:

1. **Best-effort 자동 연동**: ecommerce 코드를 implementer가 검사하여 Order 완료 시 dispatch되는 실제 이벤트/observer/hook을 찾고 거기에 listener 등록. 시그니처 불명확하면 Order::saved observer (status가 'paid' 또는 'completed' 로 전이될 때) 사용.
2. **수동 안전망**: 운영자가 어드민에서 `mark_paid_offline` 액션으로 직접 진행. ecommerce 연동이 실패해도 의뢰 라이프사이클은 막히지 않음.

본 plan은 (2)를 항상 보장하고, (1)은 best-effort로 구현. (1)이 실패하면 `DONE_WITH_CONCERNS` 보고하고 (2)만 동작하는 상태로 마무리한다.

### 권한 (`inquiry.manage`)

Plan 1 의 `InquiryPolicy::isOperator()` 가 `$user->can('inquiry.manage')` 호출. 어드민 layouts/API 는 모두 `inquiry.manage` 권한이 필요한 미들웨어 그룹에 위치.

### 어드민 layout 위치

어드민 layouts 는 board 모듈 패턴(`modules/_bundled/sirsoft-board/resources/layouts/admin/`)을 따른다. inquiry 어드민 layouts도 동일하게 `modules/_bundled/sirsoft-inquiry/resources/layouts/admin/` 에 둔다. ModuleManager 가 자동 발견.

---

## File Structure

```
modules/_bundled/sirsoft-inquiry/
  src/
    Http/
      Controllers/
        User/
          InquiryQuoteController.php          # accept / reject (client)
        Admin/
          InquiryController.php                # index / show
          InquiryQuoteController.php           # issue / revoke
          InquiryActionController.php          # mark_paid_offline / mark_completed / cancel
      Requests/
        IssueQuoteRequest.php
        StoreQuoteItemRequest.php (no — inline within IssueQuoteRequest)
        MarkPaidOfflineRequest.php (optional, can use base FormRequest)
    Services/
      InquiryPaymentBridge.php                  # ecommerce 선택적 의존
    Listeners/
      HandleOrderPaid.php                       # best-effort
    Providers/
      InquiryServiceProvider.php                # MODIFY: listener registration
    Console/Commands/
      ExpireQuotesCommand.php                   # inquiry:expire-quotes
    routes/
      api.php                                   # MODIFY: add admin + quote routes
  resources/layouts/admin/
    admin_inquiry_index.json
    admin_inquiry_detail.json
    admin_inquiry_quote_form.json
    partials/
      _modal_quote_revoke.json
      _modal_mark_paid_offline.json
      _modal_inquiry_complete.json
      _modal_inquiry_cancel_operator.json

tests/Feature/Modules/Inquiry/Api/
  AdminInquiryTest.php
  AdminQuoteIssueTest.php
  PublicQuoteAcceptRejectTest.php
  PaymentBridgeTest.php
  ExpireQuotesCommandTest.php

templates/_bundled/sirsoft-basic/
  src/components/composite/
    QuoteCard.tsx
    QuotePayButton.tsx
    index.ts                                    # MODIFY: exports
  layouts/inquiry/
    show.json                                   # MODIFY: activate quote actions
    partials/
      _modal_quote_reject.json
```

---

## Pre-check

- [ ] **Pre-1: Plan 2 회귀**

```bash
git branch --show-current
php artisan test --filter="Modules\\\\Inquiry" 2>&1 | tail -5
```

기대: 52 tests pass.

- [ ] **Pre-2: ecommerce 통합 지점 조사**

```bash
ls modules/_bundled/sirsoft-ecommerce/src/Events 2>&1 | head -5
ls modules/_bundled/sirsoft-ecommerce/src/Listeners 2>&1 | head -10
grep -rn "OrderPaid\|order.paid\|orderCompleted\|'paid'" modules/_bundled/sirsoft-ecommerce/src/Models/Order.php modules/_bundled/sirsoft-ecommerce/src/Listeners 2>/dev/null | head -10
grep -rn "static::saved\|saved(function\|booted()" modules/_bundled/sirsoft-ecommerce/src/Models/Order.php 2>/dev/null | head -5
ls modules/_bundled/sirsoft-ecommerce/src/Models/Order.php 2>/dev/null
```

결과를 기록 — Task 5 (HandleOrderPaid Listener) 구현 방식 결정 자료.

- [ ] **Pre-3: board admin route 등록 패턴**

```bash
grep -n "admin\|Admin" modules/_bundled/sirsoft-board/src/routes/api.php | head -10
```

어드민 라우트는 `auth:sanctum` + `inquiry.manage` 권한 미들웨어. board의 admin 그룹과 동일 패턴.

---

## Task 1: Admin\InquiryController (list + show)

**Files:**
- Create: `src/Http/Controllers/Admin/InquiryController.php`
- Append routes to `src/routes/api.php`
- Create: `tests/Feature/Modules/Inquiry/Api/AdminInquiryTest.php`

- [ ] **Step 1: 실패 테스트**

`tests/Feature/Modules/Inquiry/Api/AdminInquiryTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Inquiry\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Tests\TestCase;

class AdminInquiryTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = ['modules/_bundled/sirsoft-inquiry'];

    protected function setUp(): void
    {
        // Copy ensureInquiryModuleActive() workaround from InquiryCrudTest setUp,
        // then call parent::setUp().
        $this->ensureInquiryModuleActive();
        parent::setUp();
    }

    private function ensureInquiryModuleActive(): void
    {
        // Replicate the PDO-based pre-boot insert from InquiryCrudTest::setUp().
        // See tests/Feature/Modules/Inquiry/Api/InquiryCrudTest.php for the exact code.
    }

    private function makeOperator(): User
    {
        $user = User::factory()->create();
        \Spatie\Permission\Models\Permission::firstOrCreate(['identifier' => 'inquiry.manage', 'guard_name' => 'web']);
        // Or: project's actual permission grant path. See PolicyTest::setUp() in Plan 1 for the exact API.
        $role = \App\Models\Role::firstOrCreate(['identifier' => 'inquiry-test-operator', 'name' => 'Inquiry Operator']);
        $perm = \App\Models\Permission::where('identifier', 'inquiry.manage')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$perm->id => ['granted_at' => now()]]);
        $user->roles()->syncWithoutDetaching([$role->id => ['assigned_at' => now()]]);
        return $user;
    }

    public function test_admin_index_lists_all_inquiries(): void
    {
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $u1->id, 'title' => 'A', 'content' => 'x', 'status' => 'received']);
        Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $u2->id, 'title' => 'B', 'content' => 'x', 'status' => 'received']);

        $op = $this->makeOperator();
        Sanctum::actingAs($op);

        $res = $this->getJson('/api/modules/sirsoft-inquiry/admin/inquiries');
        $res->assertOk();
        $titles = array_column($res->json('data'), 'title');
        $this->assertContains('A', $titles);
        $this->assertContains('B', $titles);
    }

    public function test_admin_show_returns_inquiry(): void
    {
        $owner = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $owner->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

        $op = $this->makeOperator();
        Sanctum::actingAs($op);

        $this->getJson("/api/modules/sirsoft-inquiry/admin/inquiries/{$inquiry->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $inquiry->uuid);
    }

    public function test_admin_routes_require_inquiry_manage_permission(): void
    {
        $plain = User::factory()->create();
        Sanctum::actingAs($plain);
        $this->getJson('/api/modules/sirsoft-inquiry/admin/inquiries')
            ->assertForbidden();
    }
}
```

```bash
php artisan test --filter=AdminInquiryTest 2>&1 | tail -15
```

기대: 3 fails (route not found).

- [ ] **Step 2: Controller 작성**

`src/Http/Controllers/Admin/InquiryController.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Sirsoft\Inquiry\Http\Resources\InquiryResource;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryRepositoryInterface;

class InquiryController extends Controller
{
    public function __construct(
        private readonly InquiryRepositoryInterface $inquiries,
    ) {}

    public function index(Request $request)
    {
        $this->authorizePermission($request);

        $status = $request->query('status');
        $search = $request->query('search');
        $perPage = (int) $request->query('per_page', 20);

        $paginator = $this->inquiries->listForAdmin(
            $status ?: null,
            $search ?: null,
            $perPage
        );

        return InquiryResource::collection($paginator);
    }

    public function show(Request $request, Inquiry $inquiry)
    {
        $this->authorizePermission($request);
        $inquiry->load(['quotes.items', 'attachments', 'user']);
        return new InquiryResource($inquiry);
    }

    private function authorizePermission(Request $request): void
    {
        if (! $request->user()?->can(config('inquiry.permissions.manage', 'inquiry.manage'))) {
            abort(403);
        }
    }
}
```

- [ ] **Step 3: 라우트 등록**

`src/routes/api.php` 의 기존 `Route::bind('inquiry', ...)` 아래에 추가:

```php
Route::prefix('admin')
    ->middleware(['auth:sanctum', 'throttle:600,1'])
    ->name('admin.')
    ->group(function () {
        Route::get('/inquiries', [\Modules\Sirsoft\Inquiry\Http\Controllers\Admin\InquiryController::class, 'index'])->name('inquiries.index');
        Route::get('/inquiries/{inquiry}', [\Modules\Sirsoft\Inquiry\Http\Controllers\Admin\InquiryController::class, 'show'])->name('inquiries.show');
    });
```

- [ ] **Step 4: 테스트 PASS + Commit**

```bash
php artisan route:clear
php artisan test --filter=AdminInquiryTest 2>&1 | tail -15
git add modules/_bundled/sirsoft-inquiry/src/Http/Controllers/Admin/InquiryController.php \
        modules/_bundled/sirsoft-inquiry/src/routes/api.php \
        tests/Feature/Modules/Inquiry/Api/AdminInquiryTest.php
git commit -m "feat(inquiry): add Admin\\InquiryController (index/show) + admin routes"
```

---

## Task 2: IssueQuoteRequest

**Files:**
- Create: `src/Http/Requests/IssueQuoteRequest.php`

- [ ] **Step 1: 작성**

```php
<?php

namespace Modules\Sirsoft\Inquiry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IssueQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(config('inquiry.permissions.manage', 'inquiry.manage')) ?? false;
    }

    public function rules(): array
    {
        return [
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'valid_until' => ['nullable', 'date', 'after:today'],
            'note' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:200'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
```

- [ ] **Step 2: Syntax check + Commit**

```bash
php -l modules/_bundled/sirsoft-inquiry/src/Http/Requests/IssueQuoteRequest.php
git add modules/_bundled/sirsoft-inquiry/src/Http/Requests/IssueQuoteRequest.php
git commit -m "feat(inquiry): add IssueQuoteRequest form request"
```

---

## Task 3: Admin\InquiryQuoteController — issue

**Files:**
- Create: `src/Http/Controllers/Admin/InquiryQuoteController.php`
- Append routes

- [ ] **Step 1: 실패 테스트**

`tests/Feature/Modules/Inquiry/Api/AdminQuoteIssueTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Inquiry\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Tests\TestCase;

class AdminQuoteIssueTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = ['modules/_bundled/sirsoft-inquiry'];

    protected function setUp(): void
    {
        $this->ensureInquiryModuleActive();
        parent::setUp();
    }

    private function ensureInquiryModuleActive(): void
    {
        // Copy from InquiryCrudTest::setUp().
    }

    private function makeOperator(): User
    {
        // Copy from AdminInquiryTest::makeOperator().
    }

    public function test_issue_quote_transitions_to_quoted(): void
    {
        $client = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

        $op = $this->makeOperator();
        Sanctum::actingAs($op);

        $res = $this->postJson("/api/modules/sirsoft-inquiry/admin/inquiries/{$inquiry->uuid}/quotes", [
            'tax_amount' => 0,
            'valid_until' => now()->addDays(14)->toDateString(),
            'items' => [
                ['name' => '메인 페이지', 'qty' => 1, 'unit_price' => 1000000],
                ['name' => '상품 페이지', 'qty' => 3, 'unit_price' => 200000],
            ],
        ]);

        $res->assertCreated();
        $res->assertJsonPath('data.version', 1);
        $res->assertJsonPath('data.status', 'issued');
        $this->assertSame(1600000, (int) $res->json('data.total_amount'));

        $this->assertSame('quoted', $inquiry->fresh()->status->value);
    }

    public function test_issue_quote_increments_version(): void
    {
        $client = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

        $op = $this->makeOperator();
        Sanctum::actingAs($op);

        $this->postJson("/api/modules/sirsoft-inquiry/admin/inquiries/{$inquiry->uuid}/quotes", [
            'items' => [['name' => 'A', 'qty' => 1, 'unit_price' => 1000000]],
        ])->assertCreated();

        // Revoke to get back to received state
        $this->postJson("/api/modules/sirsoft-inquiry/admin/inquiries/{$inquiry->uuid}/quotes/" . $inquiry->fresh()->quotes()->first()->id . "/revoke")
            ->assertOk();

        $res2 = $this->postJson("/api/modules/sirsoft-inquiry/admin/inquiries/{$inquiry->uuid}/quotes", [
            'items' => [['name' => 'B', 'qty' => 1, 'unit_price' => 1200000]],
        ]);
        $res2->assertCreated();
        $this->assertSame(2, (int) $res2->json('data.version'));
    }
}
```

```bash
php artisan test --filter=AdminQuoteIssueTest 2>&1 | tail -15
```

기대: 실패.

- [ ] **Step 2: Controller 작성**

`src/Http/Controllers/Admin/InquiryQuoteController.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use Modules\Sirsoft\Inquiry\Http\Requests\IssueQuoteRequest;
use Modules\Sirsoft\Inquiry\Http\Resources\InquiryQuoteResource;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryQuote;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryQuoteRepositoryInterface;
use Modules\Sirsoft\Inquiry\Services\InquiryStateMachine;

class InquiryQuoteController extends Controller
{
    public function __construct(
        private readonly InquiryQuoteRepositoryInterface $quotes,
        private readonly InquiryStateMachine $stateMachine,
    ) {}

    public function issue(IssueQuoteRequest $request, Inquiry $inquiry)
    {
        $this->ensureOperator($request);

        $items = collect($request->input('items'))
            ->values()
            ->map(fn ($i, $idx) => [
                'position' => $idx + 1,
                'name' => $i['name'],
                'description' => $i['description'] ?? null,
                'qty' => $i['qty'],
                'unit_price' => $i['unit_price'],
                'amount' => round($i['qty'] * $i['unit_price']),
            ])
            ->all();

        $totalAmount = array_sum(array_column($items, 'amount'));
        $taxAmount = (int) ($request->input('tax_amount') ?? 0);

        $quote = $this->quotes->issue(
            $inquiry,
            [
                'total_amount' => $totalAmount,
                'tax_amount' => $taxAmount,
                'currency' => $request->input('currency') ?? config('inquiry.quote.currency', 'KRW'),
                'valid_until' => $request->input('valid_until'),
                'note' => $request->input('note'),
            ],
            $items,
        );

        // Inquiry status transition (only if currently received; if quoted, expireActive already handled)
        if ($inquiry->status->value === 'received') {
            $this->stateMachine->transition(
                $inquiry,
                TransitionEvent::IssueQuote,
                actorUserId: $request->user()->id,
                payload: ['quote_version' => $quote->version, 'quote_total' => $totalAmount],
            );
        }

        return (new InquiryQuoteResource($quote->load('items')))
            ->response()
            ->setStatusCode(201);
    }

    public function revoke(Request $request, Inquiry $inquiry, InquiryQuote $quote)
    {
        $this->ensureOperator($request);

        if ($quote->inquiry_id !== $inquiry->id) {
            abort(404);
        }
        if ($inquiry->accepted_quote_id !== null) {
            abort(409, 'Cannot revoke an accepted quote');
        }

        $this->quotes->markRejected($quote); // re-use markRejected; or add markRevoked if you prefer

        if ($inquiry->status->value === 'quoted') {
            $this->stateMachine->transition(
                $inquiry,
                TransitionEvent::RevokeQuote,
                actorUserId: $request->user()->id,
                payload: ['quote_version' => $quote->version],
            );
        }

        return new InquiryQuoteResource($quote->fresh());
    }

    private function ensureOperator(Request $request): void
    {
        if (! $request->user()?->can(config('inquiry.permissions.manage', 'inquiry.manage'))) {
            abort(403);
        }
    }
}
```

- [ ] **Step 3: 라우트 추가**

admin 그룹 안에:

```php
Route::post('/inquiries/{inquiry}/quotes', [\Modules\Sirsoft\Inquiry\Http\Controllers\Admin\InquiryQuoteController::class, 'issue'])->name('inquiries.quotes.issue');
Route::post('/inquiries/{inquiry}/quotes/{quote}/revoke', [\Modules\Sirsoft\Inquiry\Http\Controllers\Admin\InquiryQuoteController::class, 'revoke'])->name('inquiries.quotes.revoke');
```

- [ ] **Step 4: PASS + Commit**

```bash
php artisan route:clear
php artisan test --filter=AdminQuoteIssueTest 2>&1 | tail -15
git add modules/_bundled/sirsoft-inquiry/src/Http/Controllers/Admin/InquiryQuoteController.php \
        modules/_bundled/sirsoft-inquiry/src/routes/api.php \
        tests/Feature/Modules/Inquiry/Api/AdminQuoteIssueTest.php
git commit -m "feat(inquiry): add Admin\\InquiryQuoteController issue + revoke"
```

---

## Task 4: User\InquiryQuoteController — accept / reject (without payment)

**Files:**
- Create: `src/Http/Controllers/User/InquiryQuoteController.php`
- Append routes

이번 task에서는 reject 와 "accept (결제 없이 운영자 안내)" 만 구현. ecommerce 결제 연동은 Task 5에서.

- [ ] **Step 1: 실패 테스트**

`tests/Feature/Modules/Inquiry/Api/PublicQuoteAcceptRejectTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Inquiry\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Tests\TestCase;

class PublicQuoteAcceptRejectTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = ['modules/_bundled/sirsoft-inquiry'];

    protected function setUp(): void
    {
        $this->ensureInquiryModuleActive();
        parent::setUp();
    }

    private function ensureInquiryModuleActive(): void
    {
        // Copy from InquiryCrudTest.
    }

    private function quotedInquiry(): array
    {
        $user = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'title' => 'X', 'content' => 'Y', 'status' => 'quoted', 'quoted_at' => now()]);
        $quote = $inquiry->quotes()->create(['version' => 1, 'total_amount' => 1000000, 'currency' => 'KRW', 'status' => 'issued', 'issued_at' => now()]);
        return [$user, $inquiry, $quote];
    }

    public function test_reject_transitions_to_received(): void
    {
        [$user, $inquiry, $quote] = $this->quotedInquiry();
        Sanctum::actingAs($user);

        $this->postJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}/quotes/{$quote->id}/reject")
            ->assertOk();

        $this->assertSame('received', $inquiry->fresh()->status->value);
        $this->assertSame('rejected', $quote->fresh()->status->value);
    }

    public function test_accept_returns_payment_url_or_pending(): void
    {
        [$user, $inquiry, $quote] = $this->quotedInquiry();
        Sanctum::actingAs($user);

        $res = $this->postJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}/quotes/{$quote->id}/accept");
        $res->assertOk();

        // ecommerce 가 없으면 422 또는 pending message
        // ecommerce 있으면 redirect_url 반환
        $body = $res->json();
        $this->assertTrue(
            isset($body['data']['redirect_url']) || isset($body['data']['message']),
            'Response should contain redirect_url or pending message'
        );
    }
}
```

```bash
php artisan test --filter=PublicQuoteAcceptRejectTest 2>&1 | tail -15
```

- [ ] **Step 2: Controller**

`src/Http/Controllers/User/InquiryQuoteController.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryQuote;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryQuoteRepositoryInterface;
use Modules\Sirsoft\Inquiry\Services\InquiryPaymentBridge;
use Modules\Sirsoft\Inquiry\Services\InquiryStateMachine;

class InquiryQuoteController extends Controller
{
    public function __construct(
        private readonly InquiryQuoteRepositoryInterface $quotes,
        private readonly InquiryStateMachine $stateMachine,
        private readonly InquiryPaymentBridge $paymentBridge,
    ) {}

    public function accept(Request $request, Inquiry $inquiry, InquiryQuote $quote)
    {
        $this->authorize('acceptQuote', $inquiry);

        if ($quote->inquiry_id !== $inquiry->id) {
            abort(404);
        }
        if ($quote->status->value !== 'issued') {
            abort(409, 'Quote is not currently active');
        }

        $result = $this->paymentBridge->initiate($inquiry, $quote, $request->user());

        // initiate() returns:
        //   ['redirect_url' => '...']  when ecommerce is wired
        //   ['message' => 'Payment module not installed. Contact operator.'] otherwise
        return response()->json(['data' => $result]);
    }

    public function reject(Request $request, Inquiry $inquiry, InquiryQuote $quote)
    {
        $this->authorize('rejectQuote', $inquiry);

        if ($quote->inquiry_id !== $inquiry->id) {
            abort(404);
        }

        $this->quotes->markRejected($quote);

        $this->stateMachine->transition(
            $inquiry,
            TransitionEvent::RejectQuote,
            actorUserId: $request->user()->id,
            payload: ['quote_version' => $quote->version],
        );

        return response()->json(['data' => ['status' => 'rejected']]);
    }
}
```

(InquiryPaymentBridge is in Task 5 — for this task, create a stub that returns the "pending" message so the test passes. Then Task 5 fills it in.)

`src/Services/InquiryPaymentBridge.php` (stub for now):

```php
<?php

namespace Modules\Sirsoft\Inquiry\Services;

use App\Models\User;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryQuote;

class InquiryPaymentBridge
{
    public function initiate(Inquiry $inquiry, InquiryQuote $quote, User $user): array
    {
        // Task 5 will wire ecommerce here.
        return ['message' => 'Payment module not installed. Contact operator for manual confirmation.'];
    }
}
```

- [ ] **Step 3: 라우트 추가**

기존 `inquiries` 그룹 안에:

```php
Route::post('/{inquiry}/quotes/{quote}/accept', [\Modules\Sirsoft\Inquiry\Http\Controllers\User\InquiryQuoteController::class, 'accept'])->name('quotes.accept');
Route::post('/{inquiry}/quotes/{quote}/reject', [\Modules\Sirsoft\Inquiry\Http\Controllers\User\InquiryQuoteController::class, 'reject'])->name('quotes.reject');
```

- [ ] **Step 4: 테스트 PASS + Commit**

```bash
php artisan route:clear
php artisan test --filter=PublicQuoteAcceptRejectTest 2>&1 | tail -15
git add modules/_bundled/sirsoft-inquiry/src/Http/Controllers/User/InquiryQuoteController.php \
        modules/_bundled/sirsoft-inquiry/src/Services/InquiryPaymentBridge.php \
        modules/_bundled/sirsoft-inquiry/src/routes/api.php \
        tests/Feature/Modules/Inquiry/Api/PublicQuoteAcceptRejectTest.php
git commit -m "feat(inquiry): add User Quote accept/reject endpoints (stub bridge)"
```

---

## Task 5: InquiryPaymentBridge + ecommerce listener (best-effort)

**Files:**
- Modify: `src/Services/InquiryPaymentBridge.php`
- Create: `src/Listeners/HandleOrderPaid.php`
- Modify: `src/Providers/InquiryServiceProvider.php` (register listener if ecommerce present)

**ecommerce 통합 전략 결정** — Pre-2 의 조사 결과에 따라 둘 중 하나:

A. ecommerce Order 모델에 `static::saved()` observer 등록되어 있고 status='paid' 로 전이될 때 시그널이 발생하는 패턴이면, 그 시그널에 listener.

B. ecommerce가 명시적 event class를 발행하면 그걸 구독.

C. 둘 다 어려우면, `Order::observe()` 를 직접 등록하는 listener 사용. status 변경 감지.

가장 안정적: ecommerce 모듈이 설치된 경우 `\Modules\Sirsoft\Ecommerce\Models\Order::class` 가 존재. 그 모델의 `saved` event 에 listener 등록:

- [ ] **Step 1: PaymentBridge 본체**

`src/Services/InquiryPaymentBridge.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Services;

use App\Models\User;
use Modules\Sirsoft\Inquiry\Enums\QuoteStatus;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryQuote;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryQuoteRepositoryInterface;

class InquiryPaymentBridge
{
    public function __construct(
        private readonly InquiryQuoteRepositoryInterface $quotes,
        private readonly InquiryStateMachine $stateMachine,
    ) {}

    /**
     * Returns ['redirect_url' => '...'] when ecommerce module is installed,
     * or ['message' => '...'] otherwise.
     */
    public function initiate(Inquiry $inquiry, InquiryQuote $quote, User $user): array
    {
        $orderClass = '\\Modules\\Sirsoft\\Ecommerce\\Models\\Order';
        if (! class_exists($orderClass)) {
            return ['message' => 'Payment module not installed. Contact operator for manual confirmation.'];
        }

        // ecommerce-specific order creation. Field names depend on the Order schema.
        // Typical pattern (adjust based on actual model):
        $order = $orderClass::create([
            'user_id' => $user->id,
            'currency' => $quote->currency,
            'total_amount' => (int) $quote->total_amount + (int) $quote->tax_amount,
            'status' => 'pending',
            'meta' => json_encode([
                'inquiry_id' => $inquiry->id,
                'quote_id' => $quote->id,
                'inquiry_uuid' => $inquiry->uuid,
            ]),
        ]);

        return [
            'redirect_url' => url('/checkout/' . ($order->uuid ?? $order->id)),
        ];
    }

    /**
     * Invoked by HandleOrderPaid listener when ecommerce signals payment completion.
     */
    public function handleOrderPaid(object $order): void
    {
        $meta = is_string($order->meta ?? null) ? json_decode($order->meta, true) : ($order->meta ?? []);
        $inquiryId = $meta['inquiry_id'] ?? null;
        $quoteId = $meta['quote_id'] ?? null;

        if (! $inquiryId || ! $quoteId) {
            return; // Order not tied to an inquiry
        }

        $inquiry = Inquiry::find($inquiryId);
        $quote = InquiryQuote::find($quoteId);
        if (! $inquiry || ! $quote) {
            return;
        }
        if ($inquiry->status->value !== 'quoted') {
            return; // already transitioned
        }

        $this->quotes->markAccepted($quote);
        $inquiry->update([
            'accepted_quote_id' => $quote->id,
            'payment_id' => (string) ($order->uuid ?? $order->id),
        ]);

        $this->stateMachine->transition(
            $inquiry,
            TransitionEvent::AcceptAndPay,
            actorUserId: $inquiry->user_id,
            payload: ['order_uuid' => (string) ($order->uuid ?? $order->id)],
        );
    }
}
```

- [ ] **Step 2: HandleOrderPaid listener**

`src/Listeners/HandleOrderPaid.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Listeners;

use Modules\Sirsoft\Inquiry\Services\InquiryPaymentBridge;

class HandleOrderPaid
{
    public function __construct(
        private readonly InquiryPaymentBridge $bridge,
    ) {}

    public function handle(object $event): void
    {
        $order = $event->order ?? $event; // event payload shape varies
        $status = $order->status ?? null;
        if (in_array($status, ['paid', 'completed', 'PAID', 'COMPLETED'], true)) {
            $this->bridge->handleOrderPaid($order);
        }
    }
}
```

- [ ] **Step 3: ServiceProvider 에 listener 등록 (조건부)**

`InquiryServiceProvider::register()` 또는 `boot()` 안에 추가:

```php
// At end of register() — after parent and config merge:
if (class_exists('\Modules\Sirsoft\Ecommerce\Models\Order')) {
    \Modules\Sirsoft\Ecommerce\Models\Order::saved(function ($order) {
        if (in_array($order->status, ['paid', 'completed'], true)) {
            $this->app->make(\Modules\Sirsoft\Inquiry\Listeners\HandleOrderPaid::class)
                ->handle((object) ['order' => $order]);
        }
    });
}
```

(주의: Order::saved 가 정확한 hook 인지 ecommerce 코드 확인. 다르면 적절한 hook 으로 변경.)

- [ ] **Step 4: 테스트 추가 (PaymentBridgeTest — ecommerce mock)**

`tests/Feature/Modules/Inquiry/Api/PaymentBridgeTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Inquiry\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Services\InquiryPaymentBridge;
use Tests\TestCase;

class PaymentBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = ['modules/_bundled/sirsoft-inquiry'];

    protected function setUp(): void
    {
        $this->ensureInquiryModuleActive();
        parent::setUp();
    }

    private function ensureInquiryModuleActive(): void
    {
        // Copy from InquiryCrudTest.
    }

    public function test_handle_order_paid_transitions_to_in_progress(): void
    {
        $user = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'title' => 'X', 'content' => 'Y', 'status' => 'quoted', 'quoted_at' => now()]);
        $quote = $inquiry->quotes()->create(['version' => 1, 'total_amount' => 1000000, 'currency' => 'KRW', 'status' => 'issued', 'issued_at' => now()]);

        $bridge = app(InquiryPaymentBridge::class);

        $fakeOrder = (object) [
            'id' => 999,
            'uuid' => 'order-test-uuid',
            'status' => 'paid',
            'meta' => json_encode([
                'inquiry_id' => $inquiry->id,
                'quote_id' => $quote->id,
            ]),
        ];

        $bridge->handleOrderPaid($fakeOrder);

        $this->assertSame('in_progress', $inquiry->fresh()->status->value);
        $this->assertSame('accepted', $quote->fresh()->status->value);
        $this->assertSame('order-test-uuid', $inquiry->fresh()->payment_id);
    }

    public function test_handle_order_paid_ignores_unrelated_orders(): void
    {
        $bridge = app(InquiryPaymentBridge::class);
        $fakeOrder = (object) ['status' => 'paid', 'meta' => json_encode(['unrelated' => true])];
        $bridge->handleOrderPaid($fakeOrder); // no exception
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 5: Commit**

```bash
php artisan test --filter=PaymentBridgeTest 2>&1 | tail -10
git add modules/_bundled/sirsoft-inquiry/src/Services/InquiryPaymentBridge.php \
        modules/_bundled/sirsoft-inquiry/src/Listeners/HandleOrderPaid.php \
        modules/_bundled/sirsoft-inquiry/src/Providers/InquiryServiceProvider.php \
        tests/Feature/Modules/Inquiry/Api/PaymentBridgeTest.php
git commit -m "feat(inquiry): wire InquiryPaymentBridge with ecommerce Order saved hook"
```

만약 ecommerce 모듈 통합이 막히면 (Order 모델 시그니처 다름) `handleOrderPaid` 단위 테스트만 PASS 시키고 ecommerce 자동 연결은 미커밋. `mark_paid_offline` 경로로 fallback. 보고서에 명시.

---

## Task 6: Admin\InquiryActionController — mark_paid_offline / mark_completed / cancel

**Files:**
- Create: `src/Http/Controllers/Admin/InquiryActionController.php`
- Append routes

- [ ] **Step 1: 실패 테스트 — `AdminInquiryTest` 에 추가**

```php
public function test_mark_paid_offline_transitions_to_in_progress(): void
{
    $client = User::factory()->create();
    $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'quoted', 'quoted_at' => now()]);
    $inquiry->quotes()->create(['version' => 1, 'total_amount' => 1000000, 'currency' => 'KRW', 'status' => 'issued', 'issued_at' => now()]);

    Sanctum::actingAs($this->makeOperator());
    $this->postJson("/api/modules/sirsoft-inquiry/admin/inquiries/{$inquiry->uuid}/mark-paid-offline")
        ->assertOk();
    $this->assertSame('in_progress', $inquiry->fresh()->status->value);
}

public function test_mark_completed_from_in_progress(): void
{
    $client = User::factory()->create();
    $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'in_progress', 'started_at' => now(), 'payment_id' => 'p1']);

    Sanctum::actingAs($this->makeOperator());
    $this->postJson("/api/modules/sirsoft-inquiry/admin/inquiries/{$inquiry->uuid}/complete")
        ->assertOk();
    $this->assertSame('completed', $inquiry->fresh()->status->value);
}

public function test_admin_cancel_marks_canceled_by_operator(): void
{
    $client = User::factory()->create();
    $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

    Sanctum::actingAs($this->makeOperator());
    $this->postJson("/api/modules/sirsoft-inquiry/admin/inquiries/{$inquiry->uuid}/cancel")
        ->assertOk();
    $this->assertSame('canceled', $inquiry->fresh()->status->value);

    $sys = $inquiry->fresh()->messages()->where('sender_role', 'system')->latest()->first();
    $this->assertSame('inquiry::system.message.canceled_by_operator', $sys->meta['key']);
}
```

- [ ] **Step 2: Controller**

`src/Http/Controllers/Admin/InquiryActionController.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use Modules\Sirsoft\Inquiry\Http\Resources\InquiryResource;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Services\InquiryStateMachine;

class InquiryActionController extends Controller
{
    public function __construct(
        private readonly InquiryStateMachine $stateMachine,
    ) {}

    public function markPaidOffline(Request $request, Inquiry $inquiry)
    {
        $this->ensureOperator($request);

        $this->stateMachine->transition(
            $inquiry,
            TransitionEvent::MarkPaidOffline,
            actorUserId: $request->user()->id,
        );

        return new InquiryResource($inquiry->fresh()->load(['quotes.items', 'attachments']));
    }

    public function complete(Request $request, Inquiry $inquiry)
    {
        $this->ensureOperator($request);

        $this->stateMachine->transition(
            $inquiry,
            TransitionEvent::MarkCompleted,
            actorUserId: $request->user()->id,
        );

        return new InquiryResource($inquiry->fresh()->load(['quotes.items', 'attachments']));
    }

    public function cancel(Request $request, Inquiry $inquiry)
    {
        $this->ensureOperator($request);

        $this->stateMachine->transition(
            $inquiry,
            TransitionEvent::Cancel,
            actorUserId: $request->user()->id,
            payload: ['actor' => 'operator'],
        );

        return new InquiryResource($inquiry->fresh()->load(['quotes.items', 'attachments']));
    }

    private function ensureOperator(Request $request): void
    {
        if (! $request->user()?->can(config('inquiry.permissions.manage', 'inquiry.manage'))) {
            abort(403);
        }
    }
}
```

- [ ] **Step 3: 라우트 추가**

admin 그룹 안에:

```php
Route::post('/inquiries/{inquiry}/mark-paid-offline', [\Modules\Sirsoft\Inquiry\Http\Controllers\Admin\InquiryActionController::class, 'markPaidOffline']);
Route::post('/inquiries/{inquiry}/complete', [\Modules\Sirsoft\Inquiry\Http\Controllers\Admin\InquiryActionController::class, 'complete']);
Route::post('/inquiries/{inquiry}/cancel', [\Modules\Sirsoft\Inquiry\Http\Controllers\Admin\InquiryActionController::class, 'cancel']);
```

- [ ] **Step 4: PASS + Commit**

```bash
php artisan test --filter=AdminInquiryTest 2>&1 | tail -15
git add modules/_bundled/sirsoft-inquiry/src/Http/Controllers/Admin/InquiryActionController.php \
        modules/_bundled/sirsoft-inquiry/src/routes/api.php \
        tests/Feature/Modules/Inquiry/Api/AdminInquiryTest.php
git commit -m "feat(inquiry): add Admin action controller (paid_offline/complete/cancel)"
```

---

## Task 7: Quote expiry — lazy + scheduled command

**Files:**
- Create: `src/Console/Commands/ExpireQuotesCommand.php`
- Modify: `src/Providers/InquiryServiceProvider.php` — register command + schedule

- [ ] **Step 1: 실패 테스트**

`tests/Feature/Modules/Inquiry/Api/ExpireQuotesCommandTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Inquiry\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Tests\TestCase;

class ExpireQuotesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = ['modules/_bundled/sirsoft-inquiry'];

    protected function setUp(): void
    {
        $this->ensureInquiryModuleActive();
        parent::setUp();
    }

    private function ensureInquiryModuleActive(): void { /* copy */ }

    public function test_command_expires_past_valid_until_quotes(): void
    {
        $user = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'title' => 'X', 'content' => 'Y', 'status' => 'quoted']);
        $past = $inquiry->quotes()->create(['version' => 1, 'total_amount' => 1, 'currency' => 'KRW', 'status' => 'issued', 'issued_at' => now()->subDays(20), 'valid_until' => now()->subDay()->toDateString()]);
        $future = $inquiry->quotes()->create(['version' => 2, 'total_amount' => 1, 'currency' => 'KRW', 'status' => 'issued', 'issued_at' => now(), 'valid_until' => now()->addDay()->toDateString()]);

        $this->artisan('inquiry:expire-quotes')->assertExitCode(0);

        $this->assertSame('expired', $past->fresh()->status->value);
        $this->assertSame('issued', $future->fresh()->status->value);
    }
}
```

- [ ] **Step 2: Command**

`src/Console/Commands/ExpireQuotesCommand.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sirsoft\Inquiry\Enums\QuoteStatus;
use Modules\Sirsoft\Inquiry\Models\InquiryQuote;

class ExpireQuotesCommand extends Command
{
    protected $signature = 'inquiry:expire-quotes';
    protected $description = 'Mark inquiry quotes as expired when valid_until has passed.';

    public function handle(): int
    {
        $affected = InquiryQuote::query()
            ->where('status', QuoteStatus::Issued->value)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now()->toDateString())
            ->update(['status' => QuoteStatus::Expired->value]);

        $this->info("Expired {$affected} quote(s).");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 3: ServiceProvider 등록**

`InquiryServiceProvider::boot()` (override 필요 시 add) 안:

```php
if ($this->app->runningInConsole()) {
    $this->commands([
        \Modules\Sirsoft\Inquiry\Console\Commands\ExpireQuotesCommand::class,
    ]);
}
```

스케줄 (선택 — `app/Console/Kernel.php` 의 `schedule()` 가 모듈 schedule을 받는 방식 확인. 보통 ServiceProvider 의 `callAfterResolving(Schedule::class, ...)` 패턴):

```php
$this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function ($schedule) {
    $schedule->command('inquiry:expire-quotes')->daily();
});
```

- [ ] **Step 4: PASS + Commit**

```bash
php artisan test --filter=ExpireQuotesCommandTest 2>&1 | tail -10
git add modules/_bundled/sirsoft-inquiry/src/Console/Commands/ExpireQuotesCommand.php \
        modules/_bundled/sirsoft-inquiry/src/Providers/InquiryServiceProvider.php \
        tests/Feature/Modules/Inquiry/Api/ExpireQuotesCommandTest.php
git commit -m "feat(inquiry): add inquiry:expire-quotes command + daily schedule"
```

---

## Task 8: Composite — QuoteCard

**Files:**
- Create: `templates/_bundled/sirsoft-basic/src/components/composite/QuoteCard.tsx`
- Modify: `templates/_bundled/sirsoft-basic/src/components/composite/index.ts`

- [ ] **Step 1: 작성**

```tsx
import React from 'react';
import { Button, Div, H3, P, Span, Table, Tbody, Td, Th, Thead, Tr } from '../basic';

export interface QuoteItem {
  id: number;
  name: string;
  description?: string;
  qty: number | string;
  unit_price: number | string;
  amount: number | string;
}

export interface QuoteCardProps {
  version: number;
  status: 'draft' | 'issued' | 'accepted' | 'rejected' | 'expired';
  totalAmount: string | number;
  taxAmount?: string | number;
  currency?: string;
  validUntil?: string;
  note?: string;
  items: QuoteItem[];
  canAccept?: boolean;
  canReject?: boolean;
  onAccept?: () => void;
  onReject?: () => void;
  submitting?: boolean;
  className?: string;
}

const STATUS_LABEL: Record<string, string> = {
  draft: '초안',
  issued: '발행됨',
  accepted: '수락',
  rejected: '거절',
  expired: '만료',
};

const STATUS_STYLES: Record<string, string> = {
  draft: 'bg-gray-100 text-gray-700',
  issued: 'bg-yellow-100 text-yellow-700',
  accepted: 'bg-green-100 text-green-700',
  rejected: 'bg-red-100 text-red-700',
  expired: 'bg-gray-200 text-gray-500',
};

const formatKRW = (v: string | number | undefined): string => {
  if (v === undefined || v === null) return '-';
  const num = typeof v === 'string' ? parseInt(v, 10) : v;
  return Number.isFinite(num) ? num.toLocaleString('ko-KR') + '원' : '-';
};

const QuoteCard: React.FC<QuoteCardProps> = ({
  version,
  status,
  totalAmount,
  taxAmount,
  currency = 'KRW',
  validUntil,
  note,
  items,
  canAccept = false,
  canReject = false,
  onAccept,
  onReject,
  submitting = false,
  className = '',
}) => (
  <Div className={`bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-5 space-y-4 ${className}`}>
    <Div className="flex items-center justify-between">
      <H3 className="text-base font-semibold text-gray-900 dark:text-white">견적 #{version}</H3>
      <Span className={`px-2 py-0.5 text-xs rounded-full ${STATUS_STYLES[status] || ''} dark:bg-opacity-30`}>
        {STATUS_LABEL[status] || status}
      </Span>
    </Div>

    {items.length > 0 && (
      <Table className="w-full text-sm">
        <Thead>
          <Tr className="border-b border-gray-200 dark:border-gray-700">
            <Th className="text-left py-1.5 text-xs text-gray-500 dark:text-gray-400 font-normal">항목</Th>
            <Th className="text-right py-1.5 text-xs text-gray-500 dark:text-gray-400 font-normal">수량</Th>
            <Th className="text-right py-1.5 text-xs text-gray-500 dark:text-gray-400 font-normal">단가</Th>
            <Th className="text-right py-1.5 text-xs text-gray-500 dark:text-gray-400 font-normal">금액</Th>
          </Tr>
        </Thead>
        <Tbody>
          {items.map((it) => (
            <Tr key={it.id} className="border-b border-gray-100 dark:border-gray-700/50 last:border-b-0">
              <Td className="py-1.5 text-gray-700 dark:text-gray-300">{it.name}</Td>
              <Td className="py-1.5 text-right text-gray-700 dark:text-gray-300">{it.qty}</Td>
              <Td className="py-1.5 text-right text-gray-700 dark:text-gray-300">{formatKRW(it.unit_price)}</Td>
              <Td className="py-1.5 text-right text-gray-700 dark:text-gray-300 font-medium">{formatKRW(it.amount)}</Td>
            </Tr>
          ))}
        </Tbody>
      </Table>
    )}

    <Div className="border-t border-gray-200 dark:border-gray-700 pt-3 space-y-1">
      <Div className="flex justify-between text-sm">
        <Span className="text-gray-500 dark:text-gray-400">세금</Span>
        <Span className="text-gray-700 dark:text-gray-300">{formatKRW(taxAmount ?? 0)}</Span>
      </Div>
      <Div className="flex justify-between text-base font-semibold">
        <Span className="text-gray-900 dark:text-white">합계</Span>
        <Span className="text-gray-900 dark:text-white">{formatKRW(totalAmount)}</Span>
      </Div>
    </Div>

    {validUntil && (
      <P className="text-xs text-gray-500 dark:text-gray-400">유효기간: {validUntil}</P>
    )}
    {note && (
      <P className="text-xs text-gray-600 dark:text-gray-300 whitespace-pre-wrap">{note}</P>
    )}

    {(canAccept || canReject) && status === 'issued' && (
      <Div className="flex gap-2 pt-2">
        {canReject && (
          <Button
            type="button"
            onClick={onReject}
            disabled={submitting}
            className="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50"
          >
            거절
          </Button>
        )}
        {canAccept && (
          <Button
            type="button"
            onClick={onAccept}
            disabled={submitting}
            className="flex-1 px-3 py-2 bg-blue-600 text-white rounded-md font-medium hover:bg-blue-700 disabled:opacity-50"
          >
            {submitting ? '진행 중…' : '수락 및 결제'}
          </Button>
        )}
      </Div>
    )}
  </Div>
);

export default QuoteCard;
```

(Table/Thead/Tbody/Tr/Th/Td 가 basic 에 없다면 `Div`로 대체 — 본 프로젝트의 `../basic/index.ts` 를 먼저 확인하여 실제 export 된 이름을 사용. 단순화하려면 `Div`-기반 그리드로 재구성.)

- [ ] **Step 2: export + 빌드 + Commit**

```ts
// composite/index.ts 에 추가:
export { default as QuoteCard } from './QuoteCard';
```

```bash
cd templates/_bundled/sirsoft-basic && npm run build 2>&1 | tail -10
cd ../../..
git add templates/_bundled/sirsoft-basic/src/components/composite/QuoteCard.tsx \
        templates/_bundled/sirsoft-basic/src/components/composite/index.ts
git commit -m "feat(inquiry): add QuoteCard composite"
```

---

## Task 9: show.json — 견적 카드 활성

**Files:**
- Modify: `templates/_bundled/sirsoft-basic/layouts/inquiry/show.json`
- Create: `templates/_bundled/sirsoft-basic/layouts/inquiry/partials/_modal_quote_reject.json`

Plan 2 의 show.json 에는 견적 이력만 텍스트로 나열되었고 read-only 였다. 이제 `QuoteCard` 로 교체하고 accept/reject 액션 연결.

- [ ] **Step 1: 견적 거절 모달 partial**

`templates/_bundled/sirsoft-basic/layouts/inquiry/partials/_modal_quote_reject.json`:

```json
{
  "meta": { "is_partial": true, "description": "견적 거절 확인" },
  "id": "quote_reject_modal",
  "type": "composite",
  "name": "Modal",
  "props": { "id": "quote_reject_modal", "title": "견적 거절", "icon": "x-octagon", "iconClassName": "text-red-500", "size": "sm" },
  "children": [
    {
      "type": "basic", "name": "Div",
      "props": { "className": "space-y-4" },
      "children": [
        {
          "type": "basic", "name": "P",
          "props": { "className": "text-gray-600 dark:text-gray-400" },
          "text": "이 견적을 거절하시겠습니까? 운영자가 새로운 견적을 발행하면 다시 검토할 수 있습니다."
        },
        {
          "type": "basic", "name": "Div",
          "props": { "className": "flex justify-end gap-3" },
          "children": [
            { "type": "basic", "name": "Button",
              "props": { "className": "px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700" },
              "text": "닫기",
              "actions": [{ "event": "onClick", "type": "click", "handler": "closeModal" }] },
            { "type": "basic", "name": "Button",
              "props": { "disabled": "{{_local.rejectSubmitting}}", "className": "px-4 py-2 bg-red-600 text-white rounded-lg font-medium hover:bg-red-700 disabled:opacity-50" },
              "text": "{{_local.rejectSubmitting ? '처리 중…' : '견적 거절'}}",
              "actions": [
                {
                  "event": "onClick", "type": "click", "handler": "sequence",
                  "actions": [
                    { "handler": "setState", "params": { "target": "local", "rejectSubmitting": true } },
                    {
                      "handler": "apiCall",
                      "target": "/api/modules/sirsoft-inquiry/inquiries/{{route.uuid}}/quotes/{{_global.quoteRejectId}}/reject",
                      "auth_required": true,
                      "params": { "method": "POST" },
                      "onSuccess": [
                        { "handler": "setState", "params": { "target": "local", "rejectSubmitting": false } },
                        { "handler": "toast", "params": { "type": "success", "message": "견적을 거절했습니다." } },
                        { "handler": "closeModal" },
                        { "handler": "refetchDataSource", "params": { "dataSourceId": "inquiry" } },
                        { "handler": "refetchDataSource", "params": { "dataSourceId": "messages" } }
                      ],
                      "onError": [
                        { "handler": "setState", "params": { "target": "local", "rejectSubmitting": false } },
                        { "handler": "toast", "params": { "type": "error", "message": "{{error.message}}" } }
                      ]
                    }
                  ]
                }
              ]
            }
          ]
        }
      ]
    }
  ]
}
```

- [ ] **Step 2: show.json 의 견적 영역 교체**

기존 show.json 의 `<견적 이력>` 블록(`if: (inquiry.data?.quotes ?? []).length > 0` 인 Div) 안의 텍스트 목록을 `QuoteCard` 반복으로 교체. 정확한 부분 grep:

```bash
grep -n "견적 이력\|quotes ?? \[\]" templates/_bundled/sirsoft-basic/layouts/inquiry/show.json
```

해당 블록을 다음으로 교체:

```json
{
  "type": "basic", "name": "Div",
  "if": "{{(inquiry.data?.quotes ?? []).length > 0}}",
  "props": { "className": "space-y-3" },
  "children": [
    {
      "type": "for",
      "items": "{{inquiry.data?.quotes ?? []}}",
      "as": "quote",
      "render": {
        "type": "composite",
        "name": "QuoteCard",
        "props": {
          "version": "{{$item.version}}",
          "status": "{{$item.status}}",
          "totalAmount": "{{$item.total_amount}}",
          "taxAmount": "{{$item.tax_amount}}",
          "currency": "{{$item.currency}}",
          "validUntil": "{{$item.valid_until}}",
          "note": "{{$item.note}}",
          "items": "{{$item.items ?? []}}",
          "canAccept": "{{inquiry.data?.abilities?.acceptQuote && $item.status === 'issued'}}",
          "canReject": "{{inquiry.data?.abilities?.rejectQuote && $item.status === 'issued'}}",
          "submitting": "{{_local.acceptSubmitting ?? false}}"
        },
        "actions": [
          {
            "event": "onAccept", "type": "accept", "handler": "sequence",
            "actions": [
              { "handler": "setState", "params": { "target": "local", "acceptSubmitting": true } },
              {
                "handler": "apiCall",
                "target": "/api/modules/sirsoft-inquiry/inquiries/{{route.uuid}}/quotes/{{$item.id}}/accept",
                "auth_required": true,
                "params": { "method": "POST" },
                "onSuccess": [
                  { "handler": "setState", "params": { "target": "local", "acceptSubmitting": false } },
                  {
                    "if": "{{response.data.redirect_url}}",
                    "handler": "navigate",
                    "params": { "path": "{{response.data.redirect_url}}" }
                  },
                  {
                    "if": "{{response.data.message}}",
                    "handler": "toast",
                    "params": { "type": "info", "message": "{{response.data.message}}" }
                  }
                ],
                "onError": [
                  { "handler": "setState", "params": { "target": "local", "acceptSubmitting": false } },
                  { "handler": "toast", "params": { "type": "error", "message": "{{error.message}}" } }
                ]
              }
            ]
          },
          {
            "event": "onReject", "type": "reject", "handler": "sequence",
            "actions": [
              { "handler": "setState", "params": { "target": "global", "quoteRejectId": "{{$item.id}}" } },
              { "handler": "openModal", "target": "quote_reject_modal" }
            ]
          }
        ]
      }
    }
  ]
}
```

- [ ] **Step 3: show.json 의 modals 배열에 reject 모달 추가**

```json
"modals": [
  { "partial": "inquiry/partials/_modal_inquiry_cancel.json" },
  { "partial": "inquiry/partials/_modal_quote_reject.json" }
]
```

- [ ] **Step 4: JSON 파싱 + 빌드 + Commit**

```bash
python3 -c "import json; json.load(open('templates/_bundled/sirsoft-basic/layouts/inquiry/show.json')); json.load(open('templates/_bundled/sirsoft-basic/layouts/inquiry/partials/_modal_quote_reject.json')); print('OK')"
cd templates/_bundled/sirsoft-basic && npm run build 2>&1 | tail -5
cd ../../..
git add templates/_bundled/sirsoft-basic/layouts/inquiry/show.json \
        templates/_bundled/sirsoft-basic/layouts/inquiry/partials/_modal_quote_reject.json
git commit -m "feat(inquiry): activate QuoteCard accept/reject in /inquiry/{uuid}"
```

---

## Task 10-12: 어드민 layouts (index, detail, quote_form)

각 layout 은 board 의 admin layout 패턴 참고:
- `modules/_bundled/sirsoft-board/resources/layouts/admin/admin_board_index.json` (목록)
- `modules/_bundled/sirsoft-board/resources/layouts/admin/admin_board_post_detail.json` (상세)
- `modules/_bundled/sirsoft-board/resources/layouts/admin/admin_board_post_form.json` (작성 폼)

위치: `modules/_bundled/sirsoft-inquiry/resources/layouts/admin/`. 모듈 layout 자동 등록 메커니즘은 board와 동일.

### Task 10: admin_inquiry_index.json

- [ ] **Step 1: 작성**

```bash
# board admin layout 의 보일러플레이트 참고
head -50 modules/_bundled/sirsoft-board/resources/layouts/admin/admin_board_posts_index.json
```

`modules/_bundled/sirsoft-inquiry/resources/layouts/admin/admin_inquiry_index.json` 작성. 핵심 요소:
- `data_sources`: `/api/modules/sirsoft-inquiry/admin/inquiries` (status/search 필터 query)
- 상태 필터 셀렉트 (received/quoted/in_progress/completed/canceled)
- 검색 input (title)
- DataGrid 컴포넌트 (board 패턴)
- 행 클릭 → `/admin/inquiry/{uuid}` 이동

(layout 전체 JSON 길어서 본 plan에서는 골격만 명시. implementer가 board 의 `admin_board_posts_index.json` 을 본받아 작성. 핵심 차이만 적용:
- endpoint: `/api/modules/sirsoft-inquiry/admin/inquiries`
- columns: uuid (link), title, user.email, status, received_at)

- [ ] **Step 2: JSON 파싱 + Commit**

```bash
python3 -c "import json; json.load(open('modules/_bundled/sirsoft-inquiry/resources/layouts/admin/admin_inquiry_index.json')); print('OK')"
git add modules/_bundled/sirsoft-inquiry/resources/layouts/admin/admin_inquiry_index.json
git commit -m "feat(inquiry): add admin_inquiry_index layout"
```

### Task 11: admin_inquiry_detail.json

- [ ] **Step 1: 작성** (board admin_board_post_detail 참고)

핵심 요소:
- `data_sources`: inquiry (admin endpoint), messages
- 상단: 의뢰 요약 + 상태 + 운영자 액션 버튼 (견적 발행 / 견적 철회 / 결제 수동 확인 / 완료 / 취소)
- 좌측: 의뢰 본문 + 견적 이력 (read-only — issue 는 별도 페이지)
- 우측: 메시지 스레드 (운영자로 입력)
- modals: revoke, mark_paid_offline, complete, cancel (Task 13-16에서 만든 partial들 등록)

- [ ] **Step 2: JSON 파싱 + Commit**

```bash
python3 -c "import json; json.load(open('modules/_bundled/sirsoft-inquiry/resources/layouts/admin/admin_inquiry_detail.json')); print('OK')"
git add modules/_bundled/sirsoft-inquiry/resources/layouts/admin/admin_inquiry_detail.json
git commit -m "feat(inquiry): add admin_inquiry_detail layout"
```

### Task 12: admin_inquiry_quote_form.json

- [ ] **Step 1: 작성** (board admin_board_post_form 참고)

핵심 요소:
- 견적 항목 동적 추가/삭제 (initial 1개)
- 각 항목: name, description, qty, unit_price → amount 자동 계산
- 세금·유효기간·메모 필드
- "발행" 버튼 → POST `/api/modules/sirsoft-inquiry/admin/inquiries/{uuid}/quotes`
- onSuccess → `/admin/inquiry/{uuid}` 로 navigate

- [ ] **Step 2: JSON 파싱 + Commit**

```bash
python3 -c "import json; json.load(open('modules/_bundled/sirsoft-inquiry/resources/layouts/admin/admin_inquiry_quote_form.json')); print('OK')"
git add modules/_bundled/sirsoft-inquiry/resources/layouts/admin/admin_inquiry_quote_form.json
git commit -m "feat(inquiry): add admin_inquiry_quote_form layout"
```

---

## Tasks 13-16: 어드민 모달 partials

각 partial 은 의뢰 상세 페이지에서 위험 액션 트리거. 패턴은 board 의 `partials/admin_board_post_detail/_modal_delete.json` 와 동일.

### Task 13: _modal_quote_revoke.json

`modules/_bundled/sirsoft-inquiry/resources/layouts/admin/partials/_modal_quote_revoke.json`:

API: `POST /api/modules/sirsoft-inquiry/admin/inquiries/{uuid}/quotes/{quoteId}/revoke`
글로벌 state: `_global.quoteRevokeId`

### Task 14: _modal_mark_paid_offline.json

API: `POST /api/modules/sirsoft-inquiry/admin/inquiries/{uuid}/mark-paid-offline`

### Task 15: _modal_inquiry_complete.json

API: `POST /api/modules/sirsoft-inquiry/admin/inquiries/{uuid}/complete`

### Task 16: _modal_inquiry_cancel_operator.json

API: `POST /api/modules/sirsoft-inquiry/admin/inquiries/{uuid}/cancel`

각 task 동일 패턴:

- [ ] **Step 1: partial 작성** (앞의 _modal_inquiry_cancel.json 패턴 그대로)

- [ ] **Step 2: JSON 파싱 + Commit (per task)**

```bash
python3 -c "import json; json.load(open('<file>')); print('OK')"
git add <file>
git commit -m "feat(inquiry): add <modal name> modal partial"
```

총 4 commits (Tasks 13-16).

---

## Task 17: 어드민 라우트 등록 + 최종 통합 회귀

**Files:**
- Modify: `templates/_bundled/sirsoft-basic/routes.json` (옵션 — 어드민 routes 가 별도 시스템이면 skip)
- 또는 ModuleManager 의 어드민 route 등록 메커니즘 확인

어드민 화면은 보통 별도 admin 템플릿 또는 admin 라우트 시스템에 등록. board 모듈이 어떻게 어드민 URL `/admin/board/posts` 등을 노출하는지 확인:

```bash
grep -rn "admin/board\|admin/inquiry" templates/_bundled/sirsoft-admin_basic/routes.json templates/_bundled/sirsoft-basic/routes.json 2>/dev/null | head -10
```

발견한 어드민 routes 시스템에 inquiry routes 추가:
- `/admin/inquiry` → admin_inquiry_index
- `/admin/inquiry/{uuid}` → admin_inquiry_detail
- `/admin/inquiry/{uuid}/quote/new` → admin_inquiry_quote_form

- [ ] **Step 1: routes.json 갱신 + JSON 파싱 + Commit**

```bash
python3 -c "import json; json.load(open('<routes file>')); print('OK')"
git add <routes file>
git commit -m "feat(inquiry): register admin /admin/inquiry routes"
```

- [ ] **Step 2: 최종 통합 회귀**

```bash
php artisan test --filter="Modules\\\\Inquiry" 2>&1 | tail -10
cd templates/_bundled/sirsoft-basic && npm run build 2>&1 | tail -5
cd ../../..
git push fork feature/sirsoft-inquiry-foundation 2>&1 | tail -3
```

기대: 백엔드 60+ tests pass (Plan 2의 52 + Plan 3의 신규). 프론트 빌드 success.

---

## Task 18: Plan 3 회고 (no commit)

```bash
git log --oneline 131e306..HEAD | head -40
git diff --stat 131e306..HEAD | tail -3
php artisan test --filter="Modules\\\\Inquiry" 2>&1 | tail -5
```

Plan 4 (알림·정리) 진입 준비 정보 정리.

---

## 부록 A — ecommerce 통합 실패 시 대응

`InquiryPaymentBridge::handleOrderPaid()` 가 호출되지 않는 가장 흔한 원인:

1. ecommerce `Order` 모델의 hook 시점이 `saved` 가 아니라 `updated` 또는 별도 `updateStatus()` 메서드. → `Pre-2` 결과 확인 후 hook 위치 변경.
2. `Order::meta` 컬럼이 없거나 다른 이름. → `meta` 대신 별도 mapping 테이블(`inquiry_orders`) 신설하거나, ecommerce 가 제공하는 metadata field 사용.
3. ecommerce status 값이 'paid'/'completed' 가 아닐 수도. → 실제 status 상수 확인.

이 모든 경우, 본 plan의 `mark_paid_offline` 경로는 **항상 동작**. 운영자가 어드민에서 결제 사실 확인 후 수동 진행.

## 부록 B — Plan 4 와의 인터페이스

Plan 4 (알림·정리) 에서 추가될 부분:

- `Notifications/*` 클래스 7개 (Inquiry Received / Quote Issued / Quote Revoked / Payment Confirmed / Completed / Canceled / NewMessage).
- `Listeners/DispatchInquiryNotifications` — `InquiryStatusTransitioned` / `InquiryMessagePosted` 이벤트 구독, 적절한 Notification 발송.
- `inquiry:cleanup-orphan-attachments` 명령 + Schedule.
- E2E 알림 검증 테스트 (Notification fake).
- 이메일 템플릿 (Laravel notification mail).
