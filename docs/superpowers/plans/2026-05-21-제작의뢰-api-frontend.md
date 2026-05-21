# 제작의뢰 모듈 — Backend API + 사용자 프론트 (Plan 2/4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Plan 1 백엔드 기반 위에 의뢰자(클라이언트)가 사용하는 Public REST API와 sirsoft-basic 템플릿의 사용자 화면(목록·작성·상세+채팅) 세 페이지를 추가하여, 의뢰 접수와 메시지·첨부 소통이 실제로 동작하는 상태에 도달.

**Architecture:** Spec §11.1 의 Public 라우트 11개 중 견적 accept/reject 2개(Plan 3)를 제외한 9개를 구현. Controller 3개(Inquiry/Message/Attachment) + Form Request 4개 + Resource 5개 + Frontend composite 3개 + Layout JSON 3개. 모든 상태 변경은 Plan 1 의 `InquiryStateMachine` / `InquiryPolicy` / Repositories 를 통과. 견적 카드는 read-only placeholder(Plan 3에서 활성).

**Tech Stack:** Laravel 11 / Sanctum / Eloquent API Resource / React 18 + sirsoft-basic composite components / layout JSON DSL.

**Spec:** `docs/superpowers/specs/2026-05-20-제작의뢰-design.md`
**Plan 1:** `docs/superpowers/plans/2026-05-21-제작의뢰-backend-foundation.md` (완료, PR #42)

---

## Module Routing Pattern (확정 사실)

`app/Providers/ModuleRouteServiceProvider.php:110` 가 모든 모듈 routes/api.php 를 `api/modules/{module}` prefix 안에서 group 등록한다. 따라서 inquiry 모듈의 `src/routes/api.php` 안에서는 `Route::prefix('inquiries')->...` 만 선언하면 실제 URL은 `/api/modules/sirsoft-inquiry/inquiries/...` 가 된다.

`module.php` 에서 `getRoutes()` 메서드로 routes 파일 경로를 노출해야 ModuleRouteServiceProvider 가 발견한다. board 모듈 패턴:

```php
public function getRoutes(): array
{
    return [
        'api' => $this->getModulePath() . '/src/routes/api.php',
    ];
}
```

Plan 1에서 만든 `modules/_bundled/sirsoft-inquiry/module.php` 는 빈 골격(getRoutes 미정의). Task 9에서 이 메서드를 추가한다.

---

## File Structure

```
modules/_bundled/sirsoft-inquiry/
  module.php                            # MODIFY: add getRoutes()
  src/
    Http/
      Controllers/
        User/
          InquiryController.php          # index/show/store/update/cancel
          InquiryMessageController.php   # index/store
          InquiryAttachmentController.php # upload (inquiry body & message), download
      Requests/
        StoreInquiryRequest.php
        UpdateInquiryRequest.php
        StoreInquiryMessageRequest.php
        UploadInquiryAttachmentRequest.php
      Resources/
        InquiryResource.php
        InquiryQuoteResource.php
        InquiryQuoteItemResource.php
        InquiryMessageResource.php
        InquiryAttachmentResource.php
    routes/
      api.php                            # Public route group

tests/Feature/Modules/Inquiry/Api/
  InquiryCrudTest.php
  InquiryMessageTest.php
  InquiryAttachmentTest.php
  InquiryFlowTest.php

templates/_bundled/sirsoft-basic/
  src/components/composite/
    InquiryStatusBar.tsx
    InquiryCard.tsx
    InquiryMessageThread.tsx
    index.ts                             # MODIFY: export new composites
  layouts/inquiry/
    index.json
    new.json
    show.json
    partials/
      _modal_inquiry_cancel.json
  routes.json                            # MODIFY: register 3 inquiry routes
```

**파일 책임 요약**
- `Controllers/User/Inquiry*`: HTTP 진입점. Form Request에서 검증 후 Repository/StateMachine 위임. 직접 Eloquent 호출 금지.
- `Requests/*`: 입력 검증 + Policy 호출.
- `Resources/*`: 직렬화. 사용자 권한에 따라 표시 필드 결정(`is_owner`, `abilities.*`).
- Composites: stateless presentational + 최소 local state. 데이터·액션은 layout JSON DSL이 props로 주입.
- Layouts: 데이터 소스 + 슬롯 구성 + 액션 시퀀스. 직접 fetch 금지(서버 통신은 `dataSource` + `apiCall`).

---

## Pre-check (작업 전 1회)

- [ ] **Pre-1: Plan 1 commit 위에서 시작**

```bash
git branch --show-current
git log --oneline | head -5
```

기대: 현재 branch가 `feature/sirsoft-inquiry-foundation` 의 head(또는 그 위) 거나, 별도 branch 생성된 경우 그 base 가 같음.

- [ ] **Pre-2: 테스트 환경 확인**

```bash
php artisan test --filter="Modules\\\\Inquiry" 2>&1 | tail -3
```

기대: 31 tests pass (Plan 1 회귀).

- [ ] **Pre-3: board User controller·Form Request·Resource·routes 예시 미리 훑기**

작업 중 참고할 파일들:
- `modules/_bundled/sirsoft-board/src/Http/Controllers/User/BoardController.php`
- `modules/_bundled/sirsoft-board/src/Http/Requests/StorePostRequest.php`
- `modules/_bundled/sirsoft-board/src/Http/Resources/PostResource.php`
- `modules/_bundled/sirsoft-board/src/routes/api.php`

특히 인증 미들웨어(`auth:sanctum` vs `optional.sanctum`), throttle, Policy 호출 시점 패턴을 동일하게 따른다.

---

## Task 1: Resources — Message + Attachment

**Files:**
- Create: `src/Http/Resources/InquiryMessageResource.php`
- Create: `src/Http/Resources/InquiryAttachmentResource.php`

- [ ] **Step 1: 실패 테스트 작성**

`tests/Feature/Modules/Inquiry/Api/ResourceShapeTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Inquiry\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Sirsoft\Inquiry\Http\Resources\InquiryAttachmentResource;
use Modules\Sirsoft\Inquiry\Http\Resources\InquiryMessageResource;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Tests\TestCase;

class ResourceShapeTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = ['modules/_bundled/sirsoft-inquiry'];

    private function makeInquiry(): Inquiry
    {
        $user = User::factory()->create();
        return Inquiry::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'title' => 'X', 'content' => 'Y',
            'status' => 'received',
        ]);
    }

    public function test_message_resource_shape(): void
    {
        $inquiry = $this->makeInquiry();
        $msg = $inquiry->messages()->create([
            'sender_user_id' => $inquiry->user_id,
            'sender_role' => 'client',
            'body' => '안녕하세요',
        ]);

        $array = (new InquiryMessageResource($msg))->resolve();

        $this->assertSame($msg->id, $array['id']);
        $this->assertSame('client', $array['sender_role']);
        $this->assertSame('안녕하세요', $array['body']);
        $this->assertArrayHasKey('created_at', $array);
        $this->assertNull($array['meta']);
    }

    public function test_attachment_resource_shape(): void
    {
        $inquiry = $this->makeInquiry();
        $att = $inquiry->attachments()->create([
            'uploader_user_id' => $inquiry->user_id,
            'disk' => 'local',
            'path' => 'inquiries/x/plan.pdf',
            'original_name' => 'plan.pdf',
            'mime' => 'application/pdf',
            'size' => 1234,
        ]);

        $array = (new InquiryAttachmentResource($att))->resolve();

        $this->assertSame($att->id, $array['id']);
        $this->assertSame('plan.pdf', $array['original_name']);
        $this->assertSame('application/pdf', $array['mime']);
        $this->assertSame(1234, $array['size']);
        $this->assertStringContainsString("/api/modules/sirsoft-inquiry/attachments/{$att->id}", $array['download_url']);
    }
}
```

- [ ] **Step 2: 테스트 실행 — 실패 확인**

```bash
php artisan test --filter=ResourceShapeTest 2>&1 | tail -10
```

기대: "Class ... not found" (2건).

- [ ] **Step 3: Resource 작성**

`src/Http/Resources/InquiryMessageResource.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sirsoft\Inquiry\Models\InquiryMessage;

/**
 * @mixin InquiryMessage
 */
class InquiryMessageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'inquiry_id' => $this->inquiry_id,
            'sender_user_id' => $this->sender_user_id,
            'sender_role' => $this->sender_role?->value,
            'body' => $this->body,
            'meta' => $this->meta,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'attachments' => InquiryAttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
```

`src/Http/Resources/InquiryAttachmentResource.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sirsoft\Inquiry\Models\InquiryAttachment;

/**
 * @mixin InquiryAttachment
 */
class InquiryAttachmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'inquiry_id' => $this->inquiry_id,
            'message_id' => $this->message_id,
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
            'download_url' => url("/api/modules/sirsoft-inquiry/attachments/{$this->id}"),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: 테스트 실행 — 성공**

```bash
php artisan test --filter=ResourceShapeTest 2>&1 | tail -10
```

기대: 2 passes.

- [ ] **Step 5: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/Http/Resources/InquiryMessageResource.php \
        modules/_bundled/sirsoft-inquiry/src/Http/Resources/InquiryAttachmentResource.php \
        tests/Feature/Modules/Inquiry/Api/ResourceShapeTest.php
git commit -m "feat(inquiry): add Message + Attachment API resources"
```

---

## Task 2: Resources — Quote + QuoteItem (read-only for Plan 2)

**Files:**
- Create: `src/Http/Resources/InquiryQuoteItemResource.php`
- Create: `src/Http/Resources/InquiryQuoteResource.php`

견적 발행/수락 액션은 Plan 3 범위이지만, 의뢰 상세 응답에 견적 이력을 read-only 로 노출해 두면 Plan 3에서 액션만 추가하면 된다.

- [ ] **Step 1: 테스트 추가** (`ResourceShapeTest::test_quote_resource_shape`):

```php
public function test_quote_resource_shape(): void
{
    $inquiry = $this->makeInquiry();
    $quote = $inquiry->quotes()->create([
        'version' => 1,
        'total_amount' => 1000000,
        'tax_amount' => 0,
        'currency' => 'KRW',
        'status' => 'issued',
        'issued_at' => now(),
    ]);
    $quote->items()->create([
        'position' => 1,
        'name' => '메인 페이지 디자인',
        'qty' => 1,
        'unit_price' => 1000000,
        'amount' => 1000000,
    ]);

    $array = (new \Modules\Sirsoft\Inquiry\Http\Resources\InquiryQuoteResource($quote->load('items')))->resolve();

    $this->assertSame(1, $array['version']);
    $this->assertSame('issued', $array['status']);
    $this->assertSame('1000000', (string) $array['total_amount']);
    $this->assertCount(1, $array['items']);
    $this->assertSame('메인 페이지 디자인', $array['items'][0]['name']);
}
```

```bash
php artisan test --filter=test_quote_resource_shape 2>&1 | tail -10
```

기대: 실패.

- [ ] **Step 2: Resource 작성**

`src/Http/Resources/InquiryQuoteItemResource.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sirsoft\Inquiry\Models\InquiryQuoteItem;

/**
 * @mixin InquiryQuoteItem
 */
class InquiryQuoteItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'name' => $this->name,
            'description' => $this->description,
            'qty' => (string) $this->qty,
            'unit_price' => (string) $this->unit_price,
            'amount' => (string) $this->amount,
        ];
    }
}
```

`src/Http/Resources/InquiryQuoteResource.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sirsoft\Inquiry\Models\InquiryQuote;

/**
 * @mixin InquiryQuote
 */
class InquiryQuoteResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'inquiry_id' => $this->inquiry_id,
            'version' => $this->version,
            'total_amount' => (string) $this->total_amount,
            'tax_amount' => (string) $this->tax_amount,
            'currency' => $this->currency,
            'valid_until' => $this->valid_until?->toDateString(),
            'note' => $this->note,
            'status' => $this->status?->value,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'items' => InquiryQuoteItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
```

- [ ] **Step 3: 테스트 PASS + Commit**

```bash
php artisan test --filter=ResourceShapeTest 2>&1 | tail -10
git add modules/_bundled/sirsoft-inquiry/src/Http/Resources/InquiryQuoteResource.php \
        modules/_bundled/sirsoft-inquiry/src/Http/Resources/InquiryQuoteItemResource.php \
        tests/Feature/Modules/Inquiry/Api/ResourceShapeTest.php
git commit -m "feat(inquiry): add Quote + QuoteItem API resources (read-only)"
```

---

## Task 3: Resource — Inquiry

**Files:**
- Create: `src/Http/Resources/InquiryResource.php`

Inquiry 응답에는 다음을 포함: 본문 + 견적 이력(read-only) + 본문 첨부 + abilities/is_owner 메타.

- [ ] **Step 1: 테스트 추가** (`ResourceShapeTest`):

```php
public function test_inquiry_resource_shape_for_owner(): void
{
    $inquiry = $this->makeInquiry(); // makeInquiry() always uses $user
    $inquiry->load(['quotes.items', 'attachments']);

    $request = \Illuminate\Http\Request::create('/');
    $request->setUserResolver(fn () => $inquiry->user); // owner

    $array = (new \Modules\Sirsoft\Inquiry\Http\Resources\InquiryResource($inquiry))->toArray($request);

    $this->assertSame($inquiry->uuid, $array['uuid']);
    $this->assertSame('received', $array['status']);
    $this->assertTrue($array['is_owner']);
    $this->assertIsArray($array['abilities']);
    $this->assertTrue($array['abilities']['update']);
    $this->assertTrue($array['abilities']['cancel']);
    $this->assertArrayHasKey('quotes', $array);
    $this->assertArrayHasKey('attachments', $array);
}
```

```bash
php artisan test --filter=test_inquiry_resource_shape_for_owner 2>&1 | tail -10
```

기대: 실패.

- [ ] **Step 2: InquiryResource 작성**

`src/Http/Resources/InquiryResource.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sirsoft\Inquiry\Models\Inquiry;

/**
 * @mixin Inquiry
 */
class InquiryResource extends JsonResource
{
    public function toArray($request): array
    {
        $user = $request->user();
        $isOwner = $user && $user->id === $this->user_id;

        return [
            'uuid' => $this->uuid,
            'id' => $this->id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'content' => $this->content,
            'category' => $this->category,
            'budget_range' => $this->budget_range,
            'desired_due_at' => $this->desired_due_at?->toDateString(),
            'status' => $this->status?->value,
            'accepted_quote_id' => $this->accepted_quote_id,
            'payment_id' => $this->payment_id,
            'received_at' => $this->received_at?->toIso8601String(),
            'quoted_at' => $this->quoted_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'canceled_at' => $this->canceled_at?->toIso8601String(),
            'is_owner' => $isOwner,
            'abilities' => [
                'update' => $user ? $user->can('update', $this->resource) : false,
                'cancel' => $user ? $user->can('cancel', $this->resource) : false,
                'postMessage' => $user ? $user->can('postMessage', $this->resource) : false,
                'acceptQuote' => $user ? $user->can('acceptQuote', $this->resource) : false,
                'rejectQuote' => $user ? $user->can('rejectQuote', $this->resource) : false,
            ],
            'quotes' => InquiryQuoteResource::collection($this->whenLoaded('quotes')),
            'attachments' => InquiryAttachmentResource::collection(
                $this->whenLoaded('attachments', fn () => $this->attachments->whereNull('message_id'))
            ),
        ];
    }
}
```

- [ ] **Step 3: 테스트 PASS + Commit**

```bash
php artisan test --filter=ResourceShapeTest 2>&1 | tail -10
git add modules/_bundled/sirsoft-inquiry/src/Http/Resources/InquiryResource.php \
        tests/Feature/Modules/Inquiry/Api/ResourceShapeTest.php
git commit -m "feat(inquiry): add Inquiry API resource with abilities meta"
```

---

## Task 4: Form Requests — Store/Update Inquiry

**Files:**
- Create: `src/Http/Requests/StoreInquiryRequest.php`
- Create: `src/Http/Requests/UpdateInquiryRequest.php`

- [ ] **Step 1: Request 작성**

`src/Http/Requests/StoreInquiryRequest.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // 회원만 (v1 spec)
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'content' => ['required', 'string'],
            'category' => ['nullable', 'string', 'in:' . implode(',', config('inquiry.categories', []))],
            'budget_range' => ['nullable', 'string', 'max:100'],
            'desired_due_at' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
```

`src/Http/Requests/UpdateInquiryRequest.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Inquiry\Models\Inquiry;

class UpdateInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $inquiry = $this->route('inquiry');
        if (! $inquiry instanceof Inquiry) {
            return false;
        }
        return $this->user()?->can('update', $inquiry) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'content' => ['sometimes', 'required', 'string'],
            'category' => ['nullable', 'string', 'in:' . implode(',', config('inquiry.categories', []))],
            'budget_range' => ['nullable', 'string', 'max:100'],
            'desired_due_at' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
```

- [ ] **Step 2: Syntax check + Commit**

```bash
php -l modules/_bundled/sirsoft-inquiry/src/Http/Requests/StoreInquiryRequest.php
php -l modules/_bundled/sirsoft-inquiry/src/Http/Requests/UpdateInquiryRequest.php
git add modules/_bundled/sirsoft-inquiry/src/Http/Requests/Store* \
        modules/_bundled/sirsoft-inquiry/src/Http/Requests/Update*
git commit -m "feat(inquiry): add Inquiry Store/Update form requests"
```

---

## Task 5: Form Requests — Message + Attachment

**Files:**
- Create: `src/Http/Requests/StoreInquiryMessageRequest.php`
- Create: `src/Http/Requests/UploadInquiryAttachmentRequest.php`

- [ ] **Step 1: 작성**

`src/Http/Requests/StoreInquiryMessageRequest.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Inquiry\Models\Inquiry;

class StoreInquiryMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $inquiry = $this->route('inquiry');
        if (! $inquiry instanceof Inquiry) {
            return false;
        }
        return $this->user()?->can('postMessage', $inquiry) ?? false;
    }

    public function rules(): array
    {
        return [
            'body' => ['required_without:attachment_ids', 'nullable', 'string', 'max:10000'],
            'attachment_ids' => ['nullable', 'array', 'max:10'],
            'attachment_ids.*' => ['integer', 'exists:inquiry_attachments,id'],
        ];
    }
}
```

`src/Http/Requests/UploadInquiryAttachmentRequest.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Inquiry\Models\Inquiry;

class UploadInquiryAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $inquiry = $this->route('inquiry');
        if (! $inquiry instanceof Inquiry) {
            return false;
        }
        return $this->user()?->can('uploadAttachment', $inquiry) ?? false;
    }

    public function rules(): array
    {
        $context = $this->route('inquiryMessage') ? 'message' : 'inquiry';
        $maxBytes = (int) config(
            $context === 'message'
                ? 'inquiry.attachment.max_size_message'
                : 'inquiry.attachment.max_size_inquiry'
        );

        return [
            'file' => [
                'required',
                'file',
                'max:' . (int) ($maxBytes / 1024), // Laravel expects KB
            ],
        ];
    }
}
```

- [ ] **Step 2: Syntax check + Commit**

```bash
php -l modules/_bundled/sirsoft-inquiry/src/Http/Requests/StoreInquiryMessageRequest.php
php -l modules/_bundled/sirsoft-inquiry/src/Http/Requests/UploadInquiryAttachmentRequest.php
git add modules/_bundled/sirsoft-inquiry/src/Http/Requests/StoreInquiryMessageRequest.php \
        modules/_bundled/sirsoft-inquiry/src/Http/Requests/UploadInquiryAttachmentRequest.php
git commit -m "feat(inquiry): add Message/Attachment form requests"
```

---

## Task 6: Controller — InquiryController (index + show)

**Files:**
- Create: `src/Http/Controllers/User/InquiryController.php`

- [ ] **Step 1: 실패 테스트 작성**

`tests/Feature/Modules/Inquiry/Api/InquiryCrudTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Inquiry\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Tests\TestCase;

class InquiryCrudTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = ['modules/_bundled/sirsoft-inquiry'];

    public function test_index_returns_only_my_inquiries(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $me->id, 'title' => 'Mine', 'content' => 'x', 'status' => 'received']);
        Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $other->id, 'title' => 'Theirs', 'content' => 'x', 'status' => 'received']);

        Sanctum::actingAs($me);
        $res = $this->getJson('/api/modules/sirsoft-inquiry/inquiries');
        $res->assertOk();
        $titles = array_column($res->json('data'), 'title');
        $this->assertContains('Mine', $titles);
        $this->assertNotContains('Theirs', $titles);
    }

    public function test_show_returns_inquiry_for_owner(): void
    {
        $me = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $me->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

        Sanctum::actingAs($me);
        $res = $this->getJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}");
        $res->assertOk();
        $res->assertJsonPath('data.uuid', $inquiry->uuid);
        $res->assertJsonPath('data.is_owner', true);
    }

    public function test_show_returns_403_for_others(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $owner->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

        Sanctum::actingAs($other);
        $this->getJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}")
            ->assertForbidden();
    }

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/modules/sirsoft-inquiry/inquiries')
            ->assertUnauthorized();
    }
}
```

```bash
php artisan test --filter=InquiryCrudTest 2>&1 | tail -15
```

기대: 4 fails (route not found / class not found).

- [ ] **Step 2: Controller 작성 (index + show 만)**

`src/Http/Controllers/User/InquiryController.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Http\Controllers\User;

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
        $status = $request->query('status');
        $perPage = (int) $request->query('per_page', 20);

        $paginator = $this->inquiries->listByUser($request->user()->id, $status ?: null, $perPage);

        return InquiryResource::collection($paginator);
    }

    public function show(Request $request, Inquiry $inquiry)
    {
        $this->authorize('view', $inquiry);

        $inquiry->load(['quotes.items', 'attachments']);

        return new InquiryResource($inquiry);
    }
}
```

(`Inquiry` 모델은 implicit binding으로 `{inquiry:uuid}` URL 파라미터에서 자동 해석 — route 정의에서 `->whereUuid()` 또는 explicit binding 사용. Task 8에서 명시.)

- [ ] **Step 3: routes/api.php 임시 등록 (Task 8에서 정식 정리)**

이번 Task 6 단계에서는 라우트가 아직 없어 테스트가 404로 실패한다. Task 8에서 정식 등록할 때까지 일단 commit 후 다음 task에서 라우트 등록 + 테스트 PASS 확인. 즉 Step 4는 commit만:

```bash
git add modules/_bundled/sirsoft-inquiry/src/Http/Controllers/User/InquiryController.php \
        tests/Feature/Modules/Inquiry/Api/InquiryCrudTest.php
git commit -m "feat(inquiry): add InquiryController (index + show)"
```

테스트는 Task 8 완료 후 일괄 PASS 확인.

---

## Task 7: Controller — store + update + cancel

**Files:**
- Modify: `src/Http/Controllers/User/InquiryController.php`

- [ ] **Step 1: 테스트 추가** (`InquiryCrudTest`)

```php
public function test_store_creates_inquiry(): void
{
    $me = User::factory()->create();
    Sanctum::actingAs($me);

    $res = $this->postJson('/api/modules/sirsoft-inquiry/inquiries', [
        'title' => '홈페이지 리뉴얼',
        'content' => '기존 사이트를 모던하게 개편 부탁드립니다.',
        'category' => 'web',
        'budget_range' => '300-500만원',
        'desired_due_at' => now()->addMonth()->toDateString(),
    ]);

    $res->assertCreated();
    $res->assertJsonPath('data.title', '홈페이지 리뉴얼');
    $res->assertJsonPath('data.status', 'received');
    $res->assertJsonPath('data.is_owner', true);

    $this->assertDatabaseHas('inquiries', [
        'user_id' => $me->id,
        'title' => '홈페이지 리뉴얼',
        'status' => 'received',
    ]);
}

public function test_update_only_in_received_state(): void
{
    $me = User::factory()->create();
    $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $me->id, 'title' => 'old', 'content' => 'old', 'status' => 'received']);

    Sanctum::actingAs($me);
    $this->patchJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}", ['title' => 'new'])
        ->assertOk()
        ->assertJsonPath('data.title', 'new');

    $inquiry->update(['status' => 'quoted', 'quoted_at' => now()]);
    $this->patchJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}", ['title' => 'newer'])
        ->assertForbidden();
}

public function test_cancel_transitions_status(): void
{
    $me = User::factory()->create();
    $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $me->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

    Sanctum::actingAs($me);
    $this->postJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'canceled');

    $this->assertNotNull($inquiry->fresh()->canceled_at);
}
```

- [ ] **Step 2: Controller 확장**

`src/Http/Controllers/User/InquiryController.php` 의 use 절·생성자·메서드 추가:

```php
use Illuminate\Support\Str;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use Modules\Sirsoft\Inquiry\Http\Requests\StoreInquiryRequest;
use Modules\Sirsoft\Inquiry\Http\Requests\UpdateInquiryRequest;
use Modules\Sirsoft\Inquiry\Services\InquiryStateMachine;
```

생성자 시그니처:
```php
public function __construct(
    private readonly InquiryRepositoryInterface $inquiries,
    private readonly InquiryStateMachine $stateMachine,
) {}
```

메서드 추가:

```php
public function store(StoreInquiryRequest $request)
{
    $inquiry = $this->inquiries->create([
        'uuid' => (string) Str::uuid(),
        'user_id' => $request->user()->id,
        'title' => $request->string('title'),
        'content' => $request->string('content'),
        'category' => $request->input('category'),
        'budget_range' => $request->input('budget_range'),
        'desired_due_at' => $request->input('desired_due_at'),
        'status' => 'received',
    ]);

    return (new InquiryResource($inquiry->load(['quotes.items', 'attachments'])))
        ->response()
        ->setStatusCode(201);
}

public function update(UpdateInquiryRequest $request, Inquiry $inquiry)
{
    $this->inquiries->update($inquiry, $request->validated());
    return new InquiryResource($inquiry->fresh()->load(['quotes.items', 'attachments']));
}

public function cancel(Request $request, Inquiry $inquiry)
{
    $this->authorize('cancel', $inquiry);

    $this->stateMachine->transition(
        $inquiry,
        TransitionEvent::Cancel,
        actorUserId: $request->user()->id,
        payload: ['actor' => 'client'],
    );

    return new InquiryResource($inquiry->fresh()->load(['quotes.items', 'attachments']));
}
```

- [ ] **Step 3: Commit (테스트는 Task 8 후 일괄)**

```bash
git add modules/_bundled/sirsoft-inquiry/src/Http/Controllers/User/InquiryController.php \
        tests/Feature/Modules/Inquiry/Api/InquiryCrudTest.php
git commit -m "feat(inquiry): add Inquiry store/update/cancel endpoints"
```

---

## Task 8: routes/api.php + module.php::getRoutes()

**Files:**
- Create: `src/routes/api.php`
- Modify: `module.php`

- [ ] **Step 1: routes/api.php 작성**

`src/routes/api.php`:

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Inquiry\Http\Controllers\User\InquiryController;
use Modules\Sirsoft\Inquiry\Models\Inquiry;

Route::bind('inquiry', fn ($value) => Inquiry::where('uuid', $value)->firstOrFail());

Route::prefix('inquiries')
    ->middleware(['auth:sanctum', 'throttle:600,1'])
    ->name('inquiries.')
    ->group(function () {
        Route::get('/', [InquiryController::class, 'index'])->name('index');
        Route::post('/', [InquiryController::class, 'store'])->name('store');
        Route::get('/{inquiry}', [InquiryController::class, 'show'])->name('show');
        Route::patch('/{inquiry}', [InquiryController::class, 'update'])->name('update');
        Route::post('/{inquiry}/cancel', [InquiryController::class, 'cancel'])->name('cancel');
    });
```

- [ ] **Step 2: module.php에 getRoutes() 추가**

`modules/_bundled/sirsoft-inquiry/module.php` 의 클래스 안에 추가:

```php
public function getRoutes(): array
{
    return [
        'api' => $this->getModulePath() . '/src/routes/api.php',
    ];
}
```

(`getModulePath()` 는 `AbstractModule` 의 메서드. board 모듈도 동일.)

- [ ] **Step 3: 라우트 캐시 + 테스트 실행**

```bash
php artisan route:clear
php artisan test --filter=InquiryCrudTest 2>&1 | tail -15
```

기대: 7 passes (Task 6의 4개 + Task 7의 3개).

- [ ] **Step 4: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/routes/api.php \
        modules/_bundled/sirsoft-inquiry/module.php
git commit -m "feat(inquiry): register Inquiry API routes via module.php"
```

---

## Task 9: InquiryMessageController + 라우트

**Files:**
- Create: `src/Http/Controllers/User/InquiryMessageController.php`
- Modify: `src/routes/api.php`

- [ ] **Step 1: 실패 테스트**

`tests/Feature/Modules/Inquiry/Api/InquiryMessageTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Inquiry\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Tests\TestCase;

class InquiryMessageTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = ['modules/_bundled/sirsoft-inquiry'];

    private function setupInquiry(): array
    {
        $user = User::factory()->create();
        $inquiry = Inquiry::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id,
            'title' => 'X', 'content' => 'Y', 'status' => 'received',
        ]);
        return [$user, $inquiry];
    }

    public function test_post_message_creates_record(): void
    {
        [$user, $inquiry] = $this->setupInquiry();
        Sanctum::actingAs($user);

        $res = $this->postJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}/messages", [
            'body' => '추가 자료 첨부합니다',
        ]);

        $res->assertCreated();
        $res->assertJsonPath('data.body', '추가 자료 첨부합니다');
        $res->assertJsonPath('data.sender_role', 'client');

        $this->assertDatabaseHas('inquiry_messages', [
            'inquiry_id' => $inquiry->id,
            'body' => '추가 자료 첨부합니다',
            'sender_role' => 'client',
        ]);
    }

    public function test_index_returns_messages_ordered(): void
    {
        [$user, $inquiry] = $this->setupInquiry();
        $inquiry->messages()->create(['sender_user_id' => $user->id, 'sender_role' => 'client', 'body' => 'first', 'created_at' => now()->subHour()]);
        $inquiry->messages()->create(['sender_user_id' => $user->id, 'sender_role' => 'client', 'body' => 'second', 'created_at' => now()]);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}/messages");

        $res->assertOk();
        $bodies = array_column($res->json('data'), 'body');
        $this->assertSame(['first', 'second'], $bodies);
    }

    public function test_post_message_marks_opposite_role_messages_as_read(): void
    {
        [$user, $inquiry] = $this->setupInquiry();
        $op = User::factory()->create();
        $inquiry->messages()->create(['sender_user_id' => $op->id, 'sender_role' => 'operator', 'body' => 'hi', 'read_at' => null]);

        Sanctum::actingAs($user);
        $this->postJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}/messages", ['body' => 'reply']);

        $this->assertNotNull($inquiry->fresh()->messages()->where('sender_role', 'operator')->first()->read_at);
    }

    public function test_index_requires_owner(): void
    {
        [, $inquiry] = $this->setupInquiry();
        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);
        $this->getJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}/messages")
            ->assertForbidden();
    }
}
```

```bash
php artisan test --filter=InquiryMessageTest 2>&1 | tail -15
```

기대: 4 fails.

- [ ] **Step 2: Controller 작성**

`src/Http/Controllers/User/InquiryMessageController.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Sirsoft\Inquiry\Enums\SenderRole;
use Modules\Sirsoft\Inquiry\Events\InquiryMessagePosted;
use Modules\Sirsoft\Inquiry\Http\Requests\StoreInquiryMessageRequest;
use Modules\Sirsoft\Inquiry\Http\Resources\InquiryMessageResource;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryAttachmentRepositoryInterface;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryMessageRepositoryInterface;

class InquiryMessageController extends Controller
{
    public function __construct(
        private readonly InquiryMessageRepositoryInterface $messages,
        private readonly InquiryAttachmentRepositoryInterface $attachments,
    ) {}

    public function index(Request $request, Inquiry $inquiry)
    {
        $this->authorize('view', $inquiry);

        // 상대 역할 메시지 읽음 처리
        $myRole = $request->user()->id === $inquiry->user_id
            ? SenderRole::Client
            : SenderRole::Operator;
        $opposite = $myRole === SenderRole::Client ? SenderRole::Operator : SenderRole::Client;
        $this->messages->markReadFor($inquiry, $opposite);

        $perPage = (int) $request->query('per_page', 50);
        $paginator = $this->messages->listForInquiry($inquiry, $perPage);

        return InquiryMessageResource::collection($paginator);
    }

    public function store(StoreInquiryMessageRequest $request, Inquiry $inquiry)
    {
        $role = $request->user()->id === $inquiry->user_id
            ? SenderRole::Client
            : SenderRole::Operator;

        $msg = $this->messages->append(
            $inquiry,
            $request->user()->id,
            $role,
            $request->string('body', '')
        );

        $attachmentIds = $request->input('attachment_ids', []);
        foreach ($attachmentIds as $attId) {
            $att = $this->attachments->findOrFail((int) $attId);
            if ($att->inquiry_id !== $inquiry->id) {
                abort(422, 'Attachment does not belong to this inquiry');
            }
            $this->attachments->attachToMessage($att, $msg);
        }

        // 상대 역할 이전 메시지 읽음 처리
        $opposite = $role === SenderRole::Client ? SenderRole::Operator : SenderRole::Client;
        $this->messages->markReadFor($inquiry, $opposite);

        InquiryMessagePosted::dispatch($msg);

        return (new InquiryMessageResource($msg->load('attachments')))
            ->response()
            ->setStatusCode(201);
    }
}
```

- [ ] **Step 3: 라우트 추가**

`src/routes/api.php` 의 inquiries 그룹 안에 추가:

```php
Route::get('/{inquiry}/messages', [\Modules\Sirsoft\Inquiry\Http\Controllers\User\InquiryMessageController::class, 'index'])->name('messages.index');
Route::post('/{inquiry}/messages', [\Modules\Sirsoft\Inquiry\Http\Controllers\User\InquiryMessageController::class, 'store'])->name('messages.store');
```

- [ ] **Step 4: 테스트 PASS + Commit**

```bash
php artisan route:clear
php artisan test --filter=InquiryMessageTest 2>&1 | tail -15
git add modules/_bundled/sirsoft-inquiry/src/Http/Controllers/User/InquiryMessageController.php \
        modules/_bundled/sirsoft-inquiry/src/routes/api.php \
        tests/Feature/Modules/Inquiry/Api/InquiryMessageTest.php
git commit -m "feat(inquiry): add InquiryMessageController (index/store) + routes"
```

---

## Task 10: InquiryAttachmentController — upload

**Files:**
- Create: `src/Http/Controllers/User/InquiryAttachmentController.php`
- Modify: `src/routes/api.php`

- [ ] **Step 1: 실패 테스트**

`tests/Feature/Modules/Inquiry/Api/InquiryAttachmentTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Inquiry\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Tests\TestCase;

class InquiryAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = ['modules/_bundled/sirsoft-inquiry'];

    public function test_upload_inquiry_body_attachment(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $inquiry = Inquiry::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id,
            'title' => 'X', 'content' => 'Y', 'status' => 'received',
        ]);

        Sanctum::actingAs($user);
        $file = UploadedFile::fake()->create('plan.pdf', 100, 'application/pdf');

        $res = $this->postJson(
            "/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}/attachments",
            ['file' => $file]
        );

        $res->assertCreated();
        $res->assertJsonPath('data.original_name', 'plan.pdf');
        $res->assertJsonPath('data.mime', 'application/pdf');
        $this->assertDatabaseHas('inquiry_attachments', [
            'inquiry_id' => $inquiry->id,
            'message_id' => null,
            'original_name' => 'plan.pdf',
        ]);
    }

    public function test_upload_rejects_disallowed_mime(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $inquiry = Inquiry::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id,
            'title' => 'X', 'content' => 'Y', 'status' => 'received',
        ]);

        Sanctum::actingAs($user);
        $file = UploadedFile::fake()->create('bad.exe', 10, 'application/x-msdownload');

        $this->postJson(
            "/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}/attachments",
            ['file' => $file]
        )->assertStatus(422);
    }

    public function test_upload_requires_owner(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $inquiry = Inquiry::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $owner->id,
            'title' => 'X', 'content' => 'Y', 'status' => 'received',
        ]);

        Sanctum::actingAs($other);
        $this->postJson(
            "/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}/attachments",
            ['file' => UploadedFile::fake()->create('a.pdf', 1, 'application/pdf')]
        )->assertForbidden();
    }
}
```

- [ ] **Step 2: Controller 작성**

`src/Http/Controllers/User/InquiryAttachmentController.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Sirsoft\Inquiry\Http\Requests\UploadInquiryAttachmentRequest;
use Modules\Sirsoft\Inquiry\Http\Resources\InquiryAttachmentResource;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryAttachment;
use Modules\Sirsoft\Inquiry\Services\InquiryAttachmentStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InquiryAttachmentController extends Controller
{
    public function __construct(
        private readonly InquiryAttachmentStorage $storage,
    ) {}

    public function uploadInquiryBody(UploadInquiryAttachmentRequest $request, Inquiry $inquiry)
    {
        try {
            $att = $this->storage->store(
                $inquiry,
                $request->user()->id,
                $request->file('file'),
                context: 'inquiry',
            );
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return (new InquiryAttachmentResource($att))
            ->response()
            ->setStatusCode(201);
    }

    public function uploadMessage(UploadInquiryAttachmentRequest $request, Inquiry $inquiry)
    {
        try {
            $att = $this->storage->store(
                $inquiry,
                $request->user()->id,
                $request->file('file'),
                context: 'message',
            );
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return (new InquiryAttachmentResource($att))
            ->response()
            ->setStatusCode(201);
    }

    public function download(Request $request, InquiryAttachment $attachment): StreamedResponse
    {
        $inquiry = $attachment->inquiry;
        $this->authorize('viewAttachment', $inquiry);

        return \Illuminate\Support\Facades\Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime]
        );
    }
}
```

- [ ] **Step 3: 라우트 추가**

`src/routes/api.php` 에 추가:

```php
Route::post('/{inquiry}/attachments', [\Modules\Sirsoft\Inquiry\Http\Controllers\User\InquiryAttachmentController::class, 'uploadInquiryBody'])
    ->name('attachments.inquiry-body');
Route::post('/{inquiry}/messages/attachments', [\Modules\Sirsoft\Inquiry\Http\Controllers\User\InquiryAttachmentController::class, 'uploadMessage'])
    ->name('attachments.message');
```

그리고 inquiries 그룹 **밖에** (별도 그룹) 다운로드 라우트 추가:

```php
Route::middleware(['auth:sanctum', 'throttle:600,1'])
    ->name('inquiry-attachments.')
    ->group(function () {
        Route::get('/attachments/{attachment}', [\Modules\Sirsoft\Inquiry\Http\Controllers\User\InquiryAttachmentController::class, 'download'])
            ->name('download');
    });
```

`attachment` 바인딩(implicit binding on id) 은 기본 동작 사용.

- [ ] **Step 4: 테스트 PASS + Commit**

```bash
php artisan route:clear
php artisan test --filter=InquiryAttachmentTest 2>&1 | tail -15
git add modules/_bundled/sirsoft-inquiry/src/Http/Controllers/User/InquiryAttachmentController.php \
        modules/_bundled/sirsoft-inquiry/src/routes/api.php \
        tests/Feature/Modules/Inquiry/Api/InquiryAttachmentTest.php
git commit -m "feat(inquiry): add InquiryAttachmentController upload endpoints"
```

---

## Task 11: Attachment download test + 권한 검증

**Files:**
- Modify: `tests/Feature/Modules/Inquiry/Api/InquiryAttachmentTest.php`

- [ ] **Step 1: 다운로드 테스트 추가**

```php
public function test_download_returns_file_for_owner(): void
{
    Storage::fake('local');
    $user = User::factory()->create();
    $inquiry = Inquiry::create([
        'uuid' => (string) Str::uuid(), 'user_id' => $user->id,
        'title' => 'X', 'content' => 'Y', 'status' => 'received',
    ]);
    Sanctum::actingAs($user);
    $upload = $this->postJson(
        "/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}/attachments",
        ['file' => UploadedFile::fake()->create('plan.pdf', 100, 'application/pdf')]
    );
    $attId = $upload->json('data.id');

    $res = $this->get("/api/modules/sirsoft-inquiry/attachments/{$attId}");
    $res->assertOk();
    $res->assertHeader('content-type', 'application/pdf');
}

public function test_download_forbidden_for_strangers(): void
{
    Storage::fake('local');
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $inquiry = Inquiry::create([
        'uuid' => (string) Str::uuid(), 'user_id' => $owner->id,
        'title' => 'X', 'content' => 'Y', 'status' => 'received',
    ]);
    Sanctum::actingAs($owner);
    $upload = $this->postJson(
        "/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}/attachments",
        ['file' => UploadedFile::fake()->create('plan.pdf', 100, 'application/pdf')]
    );
    $attId = $upload->json('data.id');

    Sanctum::actingAs($other);
    $this->get("/api/modules/sirsoft-inquiry/attachments/{$attId}")
        ->assertForbidden();
}
```

- [ ] **Step 2: 테스트 실행 + Commit**

```bash
php artisan test --filter=InquiryAttachmentTest 2>&1 | tail -15
git add tests/Feature/Modules/Inquiry/Api/InquiryAttachmentTest.php
git commit -m "test(inquiry): cover attachment download permission"
```

---

## Task 12: E2E flow test

**Files:**
- Create: `tests/Feature/Modules/Inquiry/Api/InquiryFlowTest.php`

사용자 시나리오 골든패스를 한 테스트로 묶는다.

- [ ] **Step 1: 테스트 작성**

```php
<?php

namespace Tests\Feature\Modules\Inquiry\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InquiryFlowTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = ['modules/_bundled/sirsoft-inquiry'];

    public function test_full_client_flow_create_message_cancel(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // 1) Create inquiry
        $created = $this->postJson('/api/modules/sirsoft-inquiry/inquiries', [
            'title' => '쇼핑몰 리뉴얼',
            'content' => '디자인 + 결제 연동',
            'category' => 'web',
        ])->assertCreated()->json('data');
        $uuid = $created['uuid'];

        // 2) Upload inquiry body attachment
        $upload = $this->postJson(
            "/api/modules/sirsoft-inquiry/inquiries/{$uuid}/attachments",
            ['file' => UploadedFile::fake()->create('brief.pdf', 50, 'application/pdf')]
        )->assertCreated()->json('data');

        // 3) Post message
        $this->postJson("/api/modules/sirsoft-inquiry/inquiries/{$uuid}/messages", [
            'body' => '추가 안내 드립니다',
        ])->assertCreated();

        // 4) List inquiries — should appear
        $list = $this->getJson('/api/modules/sirsoft-inquiry/inquiries')->assertOk()->json('data');
        $this->assertTrue(collect($list)->contains('uuid', $uuid));

        // 5) Show with attachments + messages embedded
        $show = $this->getJson("/api/modules/sirsoft-inquiry/inquiries/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'received')
            ->json('data');
        $this->assertCount(1, $show['attachments']);

        $msgs = $this->getJson("/api/modules/sirsoft-inquiry/inquiries/{$uuid}/messages")
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $msgs);

        // 6) Cancel
        $this->postJson("/api/modules/sirsoft-inquiry/inquiries/{$uuid}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled');

        // 7) After cancel, system message appended
        $msgs = $this->getJson("/api/modules/sirsoft-inquiry/inquiries/{$uuid}/messages")->json('data');
        $systemMsg = collect($msgs)->firstWhere('sender_role', 'system');
        $this->assertNotNull($systemMsg);
        $this->assertSame('inquiry::system.message.canceled_by_client', $systemMsg['meta']['key']);
    }
}
```

- [ ] **Step 2: 실행 + Commit**

```bash
php artisan test --filter=InquiryFlowTest 2>&1 | tail -15
git add tests/Feature/Modules/Inquiry/Api/InquiryFlowTest.php
git commit -m "test(inquiry): cover end-to-end client flow (create→msg→cancel)"
```

기대: 1 test PASS, 12+ assertions.

---

## Task 13: Composite — InquiryStatusBar

**Files:**
- Create: `templates/_bundled/sirsoft-basic/src/components/composite/InquiryStatusBar.tsx`
- Modify: `templates/_bundled/sirsoft-basic/src/components/composite/index.ts`

- [ ] **Step 1: 컴포넌트 작성**

`InquiryStatusBar.tsx`:

```tsx
import React from 'react';
import { Div, Span } from '../basic';

export interface InquiryStatusBarProps {
  /** 현재 의뢰 상태 */
  status: 'received' | 'quoted' | 'in_progress' | 'completed' | 'canceled';
  className?: string;
}

const STEPS: Array<{ key: string; label: string }> = [
  { key: 'received', label: '접수' },
  { key: 'quoted', label: '견적' },
  { key: 'in_progress', label: '진행' },
  { key: 'completed', label: '완료' },
];

const stepIndex = (status: string): number => {
  if (status === 'canceled') return -1;
  return STEPS.findIndex((s) => s.key === status);
};

const InquiryStatusBar: React.FC<InquiryStatusBarProps> = ({ status, className = '' }) => {
  const current = stepIndex(status);
  const canceled = status === 'canceled';

  return (
    <Div className={`inquiry-status-bar w-full ${className}`}>
      {canceled ? (
        <Div className="px-4 py-2 rounded-md bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 font-medium">
          취소된 의뢰
        </Div>
      ) : (
        <Div className="flex items-center gap-2">
          {STEPS.map((step, i) => {
            const active = i <= current;
            const isCurrent = i === current;
            return (
              <React.Fragment key={step.key}>
                <Div
                  className={`flex items-center gap-2 px-3 py-1.5 rounded-full text-sm ${
                    active
                      ? 'bg-blue-600 text-white dark:bg-blue-500'
                      : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'
                  } ${isCurrent ? 'ring-2 ring-blue-300 dark:ring-blue-700' : ''}`}
                >
                  <Span className="font-semibold">{i + 1}</Span>
                  <Span>{step.label}</Span>
                </Div>
                {i < STEPS.length - 1 && (
                  <Div
                    className={`flex-1 h-0.5 ${
                      i < current ? 'bg-blue-600 dark:bg-blue-500' : 'bg-gray-200 dark:bg-gray-700'
                    }`}
                  />
                )}
              </React.Fragment>
            );
          })}
        </Div>
      )}
    </Div>
  );
};

export default InquiryStatusBar;
```

- [ ] **Step 2: index.ts 에 export 추가**

`templates/_bundled/sirsoft-basic/src/components/composite/index.ts` 에 라인 추가:

```ts
export { default as InquiryStatusBar } from './InquiryStatusBar';
```

- [ ] **Step 3: 빌드 검증 + Commit**

```bash
cd templates/_bundled/sirsoft-basic && npm run build 2>&1 | tail -10
cd ../../..
git add templates/_bundled/sirsoft-basic/src/components/composite/InquiryStatusBar.tsx \
        templates/_bundled/sirsoft-basic/src/components/composite/index.ts
git commit -m "feat(inquiry): add InquiryStatusBar composite"
```

기대: 빌드 에러 없음.

---

## Task 14: Composite — InquiryCard

**Files:**
- Create: `templates/_bundled/sirsoft-basic/src/components/composite/InquiryCard.tsx`
- Modify: `templates/_bundled/sirsoft-basic/src/components/composite/index.ts`

목록 화면에서 사용. 상태 뱃지 + 제목 + 안 읽은 메시지 수 + 받은 날짜 + 클릭 시 상세 이동.

- [ ] **Step 1: 작성**

```tsx
import React from 'react';
import { A, Div, H3, Span } from '../basic';

export interface InquiryCardProps {
  uuid: string;
  title: string;
  status: 'received' | 'quoted' | 'in_progress' | 'completed' | 'canceled';
  category?: string;
  receivedAt?: string;
  unreadCount?: number;
  className?: string;
}

const STATUS_STYLES: Record<string, string> = {
  received: 'bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-300',
  quoted: 'bg-yellow-50 text-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-300',
  in_progress: 'bg-purple-50 text-purple-700 dark:bg-purple-900/20 dark:text-purple-300',
  completed: 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-300',
  canceled: 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300',
};

const STATUS_LABEL: Record<string, string> = {
  received: '접수',
  quoted: '견적',
  in_progress: '진행',
  completed: '완료',
  canceled: '취소',
};

const InquiryCard: React.FC<InquiryCardProps> = ({
  uuid,
  title,
  status,
  category,
  receivedAt,
  unreadCount = 0,
  className = '',
}) => (
  <A
    href={`/inquiry/${uuid}`}
    className={`block bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:shadow-md transition-shadow ${className}`}
  >
    <Div className="flex items-start justify-between gap-3 mb-2">
      <H3 className="text-base font-semibold text-gray-900 dark:text-white line-clamp-1">{title}</H3>
      <Span
        className={`shrink-0 px-2 py-0.5 rounded-full text-xs font-medium ${STATUS_STYLES[status] || ''}`}
      >
        {STATUS_LABEL[status] || status}
      </Span>
    </Div>
    <Div className="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
      {category && <Span>{category}</Span>}
      {receivedAt && <Span>{new Date(receivedAt).toLocaleDateString('ko-KR')}</Span>}
      {unreadCount > 0 && (
        <Span className="ml-auto inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300 font-medium">
          새 메시지 {unreadCount}
        </Span>
      )}
    </Div>
  </A>
);

export default InquiryCard;
```

- [ ] **Step 2: index.ts 갱신 + 빌드 + Commit**

```ts
// composite/index.ts 에 추가
export { default as InquiryCard } from './InquiryCard';
```

```bash
cd templates/_bundled/sirsoft-basic && npm run build 2>&1 | tail -10
cd ../../..
git add templates/_bundled/sirsoft-basic/src/components/composite/InquiryCard.tsx \
        templates/_bundled/sirsoft-basic/src/components/composite/index.ts
git commit -m "feat(inquiry): add InquiryCard composite for list view"
```

---

## Task 15: Composite — InquiryMessageThread

**Files:**
- Create: `templates/_bundled/sirsoft-basic/src/components/composite/InquiryMessageThread.tsx`
- Modify: `templates/_bundled/sirsoft-basic/src/components/composite/index.ts`

채팅형 메시지 스레드 + 입력창. 데이터·전송 액션은 layout JSON DSL 에서 props 로 주입.

- [ ] **Step 1: 작성**

```tsx
import React, { useState } from 'react';
import { Button, Div, P, Span, Textarea } from '../basic';

export interface InquiryMessage {
  id: number;
  sender_role: 'client' | 'operator' | 'system';
  body: string | null;
  meta?: { key?: string; params?: Record<string, unknown> } | null;
  created_at?: string;
}

export interface InquiryMessageThreadProps {
  messages: InquiryMessage[];
  /** 현재 사용자 역할 (대개 'client') */
  myRole?: 'client' | 'operator';
  /** 메시지 전송 콜백 — layout JSON 에서 onSend 액션으로 바인딩 */
  onSend?: (body: string) => void;
  /** 전송 중 비활성화 */
  submitting?: boolean;
  /** placeholder 텍스트 */
  placeholder?: string;
  className?: string;
}

const renderSystemBody = (meta?: InquiryMessage['meta']): string => {
  if (!meta?.key) return '시스템 메시지';
  // 간단한 키 표시 — i18n 보간은 향후 강화
  const keySuffix = meta.key.split('.').pop() || '';
  const params = meta.params || {};
  switch (keySuffix) {
    case 'quote_issued':
      return `운영자가 견적을 발행했습니다 (회차 #${params.version ?? '?'}, 합계 ${params.total ?? '-'}원)`;
    case 'quote_revoked':
      return `운영자가 견적을 철회했습니다 (회차 #${params.version ?? '?'})`;
    case 'quote_rejected':
      return `의뢰자가 견적을 거절했습니다 (회차 #${params.version ?? '?'})`;
    case 'payment_confirmed':
      return '결제가 확인되었습니다';
    case 'payment_confirmed_offline':
      return '운영자가 결제를 수동 확인했습니다';
    case 'completed':
      return '의뢰가 완료되었습니다';
    case 'canceled_by_client':
      return '의뢰자가 의뢰를 취소했습니다';
    case 'canceled_by_operator':
      return '운영자가 의뢰를 취소했습니다';
    default:
      return meta.key;
  }
};

const InquiryMessageThread: React.FC<InquiryMessageThreadProps> = ({
  messages,
  myRole = 'client',
  onSend,
  submitting = false,
  placeholder = '메시지를 입력하세요',
  className = '',
}) => {
  const [draft, setDraft] = useState('');

  const handleSend = () => {
    const trimmed = draft.trim();
    if (!trimmed) return;
    onSend?.(trimmed);
    setDraft('');
  };

  return (
    <Div className={`inquiry-message-thread flex flex-col bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg ${className}`}>
      <Div className="flex-1 overflow-y-auto p-4 space-y-3">
        {messages.map((msg) => {
          if (msg.sender_role === 'system') {
            return (
              <Div key={msg.id} className="flex justify-center">
                <Span className="px-3 py-1 rounded-full bg-gray-100 dark:bg-gray-700 text-xs text-gray-600 dark:text-gray-400">
                  {renderSystemBody(msg.meta)}
                </Span>
              </Div>
            );
          }
          const mine = msg.sender_role === myRole;
          return (
            <Div
              key={msg.id}
              className={`flex ${mine ? 'justify-end' : 'justify-start'}`}
            >
              <Div
                className={`max-w-[80%] px-3 py-2 rounded-lg ${
                  mine
                    ? 'bg-blue-600 text-white dark:bg-blue-500'
                    : 'bg-gray-100 text-gray-900 dark:bg-gray-700 dark:text-gray-100'
                }`}
              >
                {msg.body && <P className="whitespace-pre-wrap break-words text-sm">{msg.body}</P>}
              </Div>
            </Div>
          );
        })}
        {messages.length === 0 && (
          <Div className="text-center text-sm text-gray-400 dark:text-gray-500 py-8">
            아직 메시지가 없습니다
          </Div>
        )}
      </Div>

      <Div className="border-t border-gray-200 dark:border-gray-700 p-3 flex items-end gap-2">
        <Textarea
          value={draft}
          onChange={(e: any) => setDraft(e.target.value)}
          placeholder={placeholder}
          rows={2}
          className="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 resize-none"
          disabled={submitting}
        />
        <Button
          type="button"
          onClick={handleSend}
          disabled={submitting || !draft.trim()}
          className="px-4 py-2 bg-blue-600 text-white rounded-md font-medium hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {submitting ? '전송 중' : '전송'}
        </Button>
      </Div>
    </Div>
  );
};

export default InquiryMessageThread;
```

- [ ] **Step 2: index.ts 갱신 + 빌드 + Commit**

```ts
export { default as InquiryMessageThread } from './InquiryMessageThread';
```

```bash
cd templates/_bundled/sirsoft-basic && npm run build 2>&1 | tail -10
cd ../../..
git add templates/_bundled/sirsoft-basic/src/components/composite/InquiryMessageThread.tsx \
        templates/_bundled/sirsoft-basic/src/components/composite/index.ts
git commit -m "feat(inquiry): add InquiryMessageThread composite"
```

---

## Task 16: Layout — /inquiry (index)

**Files:**
- Create: `templates/_bundled/sirsoft-basic/layouts/inquiry/index.json`

- [ ] **Step 1: 작성**

```json
{
  "version": "1.0.0",
  "layout_name": "inquiry/index",
  "permissions": ["auth"],
  "extends": "_user_base",
  "meta": {
    "seo": {
      "enabled": true,
      "page_type": "default",
      "vars": { "site_name": "$core_settings:general.site_name" }
    }
  },
  "data_sources": [
    {
      "id": "inquiries",
      "type": "api",
      "endpoint": "/api/modules/sirsoft-inquiry/inquiries",
      "method": "GET",
      "auto_fetch": true,
      "auth_mode": "required"
    }
  ],
  "slots": {
    "content": [
      {
        "type": "layout",
        "name": "Container",
        "props": { "className": "py-8 max-w-3xl mx-auto" },
        "children": [
          {
            "type": "basic",
            "name": "Div",
            "props": { "className": "flex items-center justify-between mb-6" },
            "children": [
              {
                "type": "basic",
                "name": "H1",
                "props": { "className": "text-2xl font-bold text-gray-900 dark:text-white" },
                "text": "내 제작의뢰"
              },
              {
                "type": "basic",
                "name": "A",
                "props": {
                  "href": "/inquiry/new",
                  "className": "px-4 py-2 bg-blue-600 text-white rounded-md font-medium hover:bg-blue-700"
                },
                "text": "새 의뢰 작성"
              }
            ]
          },
          {
            "type": "basic",
            "name": "Div",
            "props": { "className": "space-y-3" },
            "children": [
              {
                "type": "for",
                "items": "{{inquiries.data ?? []}}",
                "as": "inquiry",
                "render": {
                  "type": "composite",
                  "name": "InquiryCard",
                  "props": {
                    "uuid": "{{$item.uuid}}",
                    "title": "{{$item.title}}",
                    "status": "{{$item.status}}",
                    "category": "{{$item.category}}",
                    "receivedAt": "{{$item.received_at}}"
                  }
                }
              }
            ]
          },
          {
            "type": "basic",
            "name": "Div",
            "if": "{{(inquiries.data ?? []).length === 0}}",
            "props": { "className": "text-center text-gray-500 dark:text-gray-400 py-12" },
            "text": "등록한 의뢰가 없습니다. 첫 의뢰를 작성해 보세요."
          }
        ]
      }
    ]
  }
}
```

- [ ] **Step 2: JSON 파싱 확인**

```bash
python3 -c "import json; json.load(open('templates/_bundled/sirsoft-basic/layouts/inquiry/index.json')); print('OK')"
```

- [ ] **Step 3: Commit**

```bash
git add templates/_bundled/sirsoft-basic/layouts/inquiry/index.json
git commit -m "feat(inquiry): add /inquiry index layout"
```

---

## Task 17: Layout — /inquiry/new

**Files:**
- Create: `templates/_bundled/sirsoft-basic/layouts/inquiry/new.json`

- [ ] **Step 1: 작성**

```json
{
  "version": "1.0.0",
  "layout_name": "inquiry/new",
  "permissions": ["auth"],
  "extends": "_user_base",
  "meta": {
    "seo": {
      "enabled": true,
      "page_type": "default",
      "vars": { "site_name": "$core_settings:general.site_name" }
    }
  },
  "init_actions": [
    {
      "handler": "setState",
      "params": {
        "target": "local",
        "form": { "title": "", "content": "", "category": "", "budget_range": "", "desired_due_at": "" },
        "submitting": false,
        "errorMessage": ""
      }
    }
  ],
  "slots": {
    "content": [
      {
        "type": "layout",
        "name": "Container",
        "props": { "className": "py-8 max-w-2xl mx-auto" },
        "children": [
          {
            "type": "basic",
            "name": "H1",
            "props": { "className": "text-2xl font-bold text-gray-900 dark:text-white mb-6" },
            "text": "새 제작의뢰"
          },
          {
            "type": "basic",
            "name": "Div",
            "props": { "className": "bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-6 space-y-4" },
            "children": [
              {
                "type": "basic", "name": "Div",
                "children": [
                  { "type": "basic", "name": "Label", "props": { "className": "block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" }, "text": "제목" },
                  { "type": "basic", "name": "Input",
                    "props": {
                      "type": "text",
                      "value": "{{_local.form?.title ?? ''}}",
                      "placeholder": "예: 홈페이지 리뉴얼 의뢰",
                      "className": "input w-full"
                    },
                    "actions": [
                      { "event": "onChange", "type": "change", "handler": "setState",
                        "params": { "target": "local", "form": { "title": "{{$event.target.value}}" } } }
                    ] }
                ]
              },
              {
                "type": "basic", "name": "Div",
                "children": [
                  { "type": "basic", "name": "Label", "props": { "className": "block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" }, "text": "본문" },
                  { "type": "basic", "name": "Textarea",
                    "props": {
                      "value": "{{_local.form?.content ?? ''}}",
                      "rows": 8,
                      "placeholder": "프로젝트 개요, 요구사항, 참고 자료 등을 자유롭게 작성해 주세요.",
                      "className": "input w-full"
                    },
                    "actions": [
                      { "event": "onChange", "type": "change", "handler": "setState",
                        "params": { "target": "local", "form": { "content": "{{$event.target.value}}" } } }
                    ] }
                ]
              },
              {
                "type": "basic", "name": "Div",
                "props": { "className": "grid grid-cols-1 md:grid-cols-2 gap-4" },
                "children": [
                  {
                    "type": "basic", "name": "Div",
                    "children": [
                      { "type": "basic", "name": "Label", "props": { "className": "block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" }, "text": "예산 범위 (선택)" },
                      { "type": "basic", "name": "Input",
                        "props": { "type": "text", "value": "{{_local.form?.budget_range ?? ''}}", "placeholder": "예: 300-500만원", "className": "input w-full" },
                        "actions": [
                          { "event": "onChange", "type": "change", "handler": "setState",
                            "params": { "target": "local", "form": { "budget_range": "{{$event.target.value}}" } } }
                        ] }
                    ]
                  },
                  {
                    "type": "basic", "name": "Div",
                    "children": [
                      { "type": "basic", "name": "Label", "props": { "className": "block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" }, "text": "희망 완료일 (선택)" },
                      { "type": "basic", "name": "Input",
                        "props": { "type": "date", "value": "{{_local.form?.desired_due_at ?? ''}}", "className": "input w-full" },
                        "actions": [
                          { "event": "onChange", "type": "change", "handler": "setState",
                            "params": { "target": "local", "form": { "desired_due_at": "{{$event.target.value}}" } } }
                        ] }
                    ]
                  }
                ]
              },
              {
                "type": "basic", "name": "Div",
                "if": "{{_local.errorMessage}}",
                "props": { "className": "text-sm text-red-500" },
                "text": "{{_local.errorMessage}}"
              },
              {
                "type": "basic", "name": "Div",
                "props": { "className": "flex justify-end gap-3 pt-2" },
                "children": [
                  { "type": "basic", "name": "A",
                    "props": { "href": "/inquiry", "className": "px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-50 dark:hover:bg-gray-700" },
                    "text": "취소" },
                  { "type": "basic", "name": "Button",
                    "props": {
                      "type": "button",
                      "disabled": "{{_local.submitting || !_local.form?.title || !_local.form?.content}}",
                      "className": "px-4 py-2 bg-blue-600 text-white rounded-md font-medium hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
                    },
                    "text": "{{_local.submitting ? '제출 중…' : '의뢰 제출'}}",
                    "actions": [
                      {
                        "event": "onClick", "type": "click", "handler": "sequence",
                        "actions": [
                          { "handler": "setState", "params": { "target": "local", "submitting": true, "errorMessage": "" } },
                          {
                            "handler": "apiCall",
                            "target": "/api/modules/sirsoft-inquiry/inquiries",
                            "auth_required": true,
                            "params": {
                              "method": "POST",
                              "body": "{{_local.form}}"
                            },
                            "onSuccess": [
                              { "handler": "toast", "params": { "type": "success", "message": "의뢰가 접수되었습니다." } },
                              { "handler": "navigate", "params": { "path": "/inquiry/{{response.data.uuid}}" } }
                            ],
                            "onError": [
                              { "handler": "setState", "params": { "target": "local", "submitting": false, "errorMessage": "{{error.message}}" } },
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
    ]
  }
}
```

- [ ] **Step 2: JSON 파싱 + Commit**

```bash
python3 -c "import json; json.load(open('templates/_bundled/sirsoft-basic/layouts/inquiry/new.json')); print('OK')"
git add templates/_bundled/sirsoft-basic/layouts/inquiry/new.json
git commit -m "feat(inquiry): add /inquiry/new layout with submit flow"
```

---

## Task 18: Modal partial — cancel

**Files:**
- Create: `templates/_bundled/sirsoft-basic/layouts/inquiry/partials/_modal_inquiry_cancel.json`

- [ ] **Step 1: 작성**

```json
{
  "meta": {
    "is_partial": true,
    "description": "의뢰 취소 확인 모달"
  },
  "id": "inquiry_cancel_modal",
  "type": "composite",
  "name": "Modal",
  "props": {
    "id": "inquiry_cancel_modal",
    "title": "의뢰 취소",
    "icon": "alert-triangle",
    "iconClassName": "text-red-500",
    "size": "sm"
  },
  "children": [
    {
      "type": "basic", "name": "Div",
      "props": { "className": "space-y-4" },
      "children": [
        {
          "type": "basic", "name": "P",
          "props": { "className": "text-gray-600 dark:text-gray-400" },
          "text": "이 의뢰를 취소하시겠습니까? 진행 중이던 견적·결제가 있다면 운영자에게 별도 안내가 필요할 수 있습니다."
        },
        {
          "type": "basic", "name": "Div",
          "props": { "className": "flex justify-end gap-3" },
          "children": [
            {
              "type": "basic", "name": "Button",
              "props": { "className": "px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700" },
              "text": "닫기",
              "actions": [
                { "event": "onClick", "type": "click", "handler": "closeModal" }
              ]
            },
            {
              "type": "basic", "name": "Button",
              "props": {
                "disabled": "{{_local.cancelSubmitting}}",
                "className": "px-4 py-2 bg-red-600 text-white rounded-lg font-medium hover:bg-red-700 disabled:opacity-50"
              },
              "text": "{{_local.cancelSubmitting ? '처리 중…' : '의뢰 취소'}}",
              "actions": [
                {
                  "event": "onClick", "type": "click", "handler": "sequence",
                  "actions": [
                    { "handler": "setState", "params": { "target": "local", "cancelSubmitting": true } },
                    {
                      "handler": "apiCall",
                      "target": "/api/modules/sirsoft-inquiry/inquiries/{{route.uuid}}/cancel",
                      "auth_required": true,
                      "params": { "method": "POST" },
                      "onSuccess": [
                        { "handler": "setState", "params": { "target": "local", "cancelSubmitting": false } },
                        { "handler": "toast", "params": { "type": "success", "message": "의뢰가 취소되었습니다." } },
                        { "handler": "closeModal" },
                        { "handler": "refetchDataSource", "params": { "dataSourceId": "inquiry" } },
                        { "handler": "refetchDataSource", "params": { "dataSourceId": "messages" } }
                      ],
                      "onError": [
                        { "handler": "setState", "params": { "target": "local", "cancelSubmitting": false } },
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

- [ ] **Step 2: JSON 파싱 + Commit**

```bash
python3 -c "import json; json.load(open('templates/_bundled/sirsoft-basic/layouts/inquiry/partials/_modal_inquiry_cancel.json')); print('OK')"
git add templates/_bundled/sirsoft-basic/layouts/inquiry/partials/_modal_inquiry_cancel.json
git commit -m "feat(inquiry): add cancel confirmation modal partial"
```

---

## Task 19: Layout — /inquiry/{uuid} (show)

**Files:**
- Create: `templates/_bundled/sirsoft-basic/layouts/inquiry/show.json`

상단 상태바 + 좌측 의뢰 요약 + 견적 카드(read-only placeholder) + 우측 메시지 스레드 + 취소 모달.

- [ ] **Step 1: 작성**

```json
{
  "version": "1.0.0",
  "layout_name": "inquiry/show",
  "permissions": ["auth"],
  "extends": "_user_base",
  "meta": {
    "seo": {
      "enabled": true,
      "page_type": "default",
      "vars": { "site_name": "$core_settings:general.site_name" }
    }
  },
  "data_sources": [
    {
      "id": "inquiry",
      "type": "api",
      "endpoint": "/api/modules/sirsoft-inquiry/inquiries/{{route.uuid}}",
      "method": "GET",
      "auto_fetch": true,
      "auth_mode": "required"
    },
    {
      "id": "messages",
      "type": "api",
      "endpoint": "/api/modules/sirsoft-inquiry/inquiries/{{route.uuid}}/messages",
      "method": "GET",
      "auto_fetch": true,
      "auth_mode": "required"
    }
  ],
  "slots": {
    "content": [
      {
        "type": "layout", "name": "Container",
        "props": { "className": "py-8 max-w-5xl mx-auto space-y-6" },
        "children": [
          {
            "type": "basic", "name": "Div",
            "props": { "className": "flex items-center justify-between gap-3" },
            "children": [
              {
                "type": "basic", "name": "A",
                "props": { "href": "/inquiry", "className": "text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200" },
                "text": "← 의뢰 목록"
              },
              {
                "type": "basic", "name": "Button",
                "if": "{{inquiry.data?.abilities?.cancel}}",
                "props": { "className": "px-3 py-1.5 text-sm border border-red-300 dark:border-red-700 text-red-600 dark:text-red-400 rounded-md hover:bg-red-50 dark:hover:bg-red-900/20" },
                "text": "의뢰 취소",
                "actions": [
                  { "event": "onClick", "type": "click", "handler": "openModal", "target": "inquiry_cancel_modal" }
                ]
              }
            ]
          },
          {
            "type": "composite", "name": "InquiryStatusBar",
            "props": { "status": "{{inquiry.data?.status ?? 'received'}}" }
          },
          {
            "type": "basic", "name": "Div",
            "props": { "className": "grid grid-cols-1 lg:grid-cols-3 gap-6" },
            "children": [
              {
                "type": "basic", "name": "Div",
                "props": { "className": "lg:col-span-1 space-y-4" },
                "children": [
                  {
                    "type": "basic", "name": "Div",
                    "props": { "className": "bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-5 space-y-3" },
                    "children": [
                      { "type": "basic", "name": "H2", "props": { "className": "text-lg font-bold text-gray-900 dark:text-white" }, "text": "{{inquiry.data?.title ?? ''}}" },
                      { "type": "basic", "name": "P", "props": { "className": "text-sm text-gray-600 dark:text-gray-400 whitespace-pre-wrap" }, "text": "{{inquiry.data?.content ?? ''}}" },
                      {
                        "type": "basic", "name": "Div",
                        "props": { "className": "pt-2 border-t border-gray-200 dark:border-gray-700 grid grid-cols-2 gap-2 text-xs text-gray-500 dark:text-gray-400" },
                        "children": [
                          { "type": "basic", "name": "Div", "text": "분류" },
                          { "type": "basic", "name": "Div", "props": { "className": "text-right" }, "text": "{{inquiry.data?.category ?? '-'}}" },
                          { "type": "basic", "name": "Div", "text": "예산" },
                          { "type": "basic", "name": "Div", "props": { "className": "text-right" }, "text": "{{inquiry.data?.budget_range ?? '-'}}" },
                          { "type": "basic", "name": "Div", "text": "희망 완료일" },
                          { "type": "basic", "name": "Div", "props": { "className": "text-right" }, "text": "{{inquiry.data?.desired_due_at ?? '-'}}" }
                        ]
                      }
                    ]
                  },
                  {
                    "type": "basic", "name": "Div",
                    "if": "{{(inquiry.data?.quotes ?? []).length > 0}}",
                    "props": { "className": "bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-5" },
                    "children": [
                      { "type": "basic", "name": "H3", "props": { "className": "text-sm font-semibold text-gray-900 dark:text-white mb-2" }, "text": "견적 이력" },
                      {
                        "type": "for",
                        "items": "{{inquiry.data?.quotes ?? []}}",
                        "as": "quote",
                        "render": {
                          "type": "basic", "name": "Div",
                          "props": { "className": "py-2 border-b border-gray-100 dark:border-gray-700 last:border-b-0 text-sm text-gray-700 dark:text-gray-300" },
                          "children": [
                            { "type": "basic", "name": "Div", "text": "회차 #{{$item.version}} · 상태 {{$item.status}} · {{$item.total_amount}}원" }
                          ]
                        }
                      },
                      { "type": "basic", "name": "P", "props": { "className": "text-xs text-gray-400 mt-2" }, "text": "견적 수락/결제는 향후 업데이트에서 활성화됩니다." }
                    ]
                  }
                ]
              },
              {
                "type": "basic", "name": "Div",
                "props": { "className": "lg:col-span-2 h-[600px]" },
                "children": [
                  {
                    "type": "composite", "name": "InquiryMessageThread",
                    "props": {
                      "messages": "{{messages.data ?? []}}",
                      "myRole": "client",
                      "submitting": "{{_local.messageSubmitting ?? false}}",
                      "className": "h-full"
                    },
                    "actions": [
                      {
                        "event": "onSend", "type": "send", "handler": "sequence",
                        "actions": [
                          { "handler": "setState", "params": { "target": "local", "messageSubmitting": true } },
                          {
                            "handler": "apiCall",
                            "target": "/api/modules/sirsoft-inquiry/inquiries/{{route.uuid}}/messages",
                            "auth_required": true,
                            "params": { "method": "POST", "body": { "body": "{{$event}}" } },
                            "onSuccess": [
                              { "handler": "setState", "params": { "target": "local", "messageSubmitting": false } },
                              { "handler": "refetchDataSource", "params": { "dataSourceId": "messages" } }
                            ],
                            "onError": [
                              { "handler": "setState", "params": { "target": "local", "messageSubmitting": false } },
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
    ]
  },
  "modals": [
    { "partial": "inquiry/partials/_modal_inquiry_cancel.json" }
  ]
}
```

- [ ] **Step 2: JSON 파싱 + Commit**

```bash
python3 -c "import json; json.load(open('templates/_bundled/sirsoft-basic/layouts/inquiry/show.json')); print('OK')"
git add templates/_bundled/sirsoft-basic/layouts/inquiry/show.json
git commit -m "feat(inquiry): add /inquiry/{uuid} detail layout (status + summary + thread)"
```

---

## Task 20: routes.json 등록 + 최종 빌드

**Files:**
- Modify: `templates/_bundled/sirsoft-basic/routes.json`

- [ ] **Step 1: routes.json 의 routes 배열에 3개 항목 추가**

기존 `routes.json` 안의 `routes` 배열에 다음 3개 객체를 추가(다른 인접 라우트와 스타일 맞춰서):

```json
{
  "path": "/inquiry",
  "layout": "inquiry/index",
  "auth": true,
  "meta": { "title": "내 제작의뢰" }
},
{
  "path": "/inquiry/new",
  "layout": "inquiry/new",
  "auth": true,
  "meta": { "title": "새 제작의뢰" }
},
{
  "path": "/inquiry/:uuid",
  "layout": "inquiry/show",
  "auth": true,
  "meta": { "title": "제작의뢰 상세" }
}
```

배치 위치는 board 관련 라우트 뒤가 자연스럽다. 파일이 큰 경우 grep 으로 적절 위치 찾기:
```bash
grep -n "/board" templates/_bundled/sirsoft-basic/routes.json | head -5
```

- [ ] **Step 2: JSON 파싱 + 빌드 검증**

```bash
python3 -c "import json; json.load(open('templates/_bundled/sirsoft-basic/routes.json')); print('OK')"
cd templates/_bundled/sirsoft-basic && npm run build 2>&1 | tail -10
cd ../../..
```

- [ ] **Step 3: 통합 회귀 — 백엔드 테스트 전체 통과**

```bash
php artisan test --filter="Modules\\\\Inquiry" 2>&1 | tail -10
```

기대: Plan 1의 31 tests + Plan 2의 신규 테스트(약 15+개) 모두 PASS. 합계 45+ tests.

- [ ] **Step 4: Commit**

```bash
git add templates/_bundled/sirsoft-basic/routes.json
git commit -m "feat(inquiry): register /inquiry routes (index/new/show)"
```

---

## Task 21: Phase 2 회고 (no commit)

- [ ] **Step 1: 최종 점검**

```bash
git log --oneline f43ed65..HEAD | head -40
git diff --stat 28fca3b..HEAD | tail -3
php artisan test --filter="Modules\\\\Inquiry" 2>&1 | tail -5
```

- [ ] **Step 2: 수동 UI sanity (선택)**

`npm run dev` 으로 dev server 띄우고 브라우저에서:
1. `/inquiry` 접근 — 빈 상태 안내 표시
2. `/inquiry/new` — 폼 입력 후 제출 → `/inquiry/{uuid}` 로 redirect
3. 상세 페이지에서 메시지 입력 → 스레드 갱신
4. 의뢰 취소 모달 → 상태바가 "취소된 의뢰" 로 변경

UI 검증 어려우면 백엔드 API 직접 curl 검증으로 대체.

- [ ] **Step 3: Plan 3 진입 준비**

Plan 3 범위 미리 기억: 견적 발행·수락 + ecommerce 결제 브리지 + 어드민 화면. `QuoteCard` / `QuotePayButton` 컴포넌트 + 어드민 layouts + `InquiryPaymentBridge` 서비스 + ecommerce listener.

---

## 부록 A — 자주 발생하는 문제

**라우트가 404로 잡힐 때**
- `php artisan route:clear` 실행. 모듈 라우트는 `ModuleRouteServiceProvider` 가 `api/modules/{module}` prefix 안에 등록. `php artisan route:list | grep inquiry` 로 확인.
- `module.php` 의 `getRoutes()` 가 추가되지 않았으면 라우트 미발견. Task 8에서 추가 필요.

**Sanctum 인증이 401**
- 테스트에서 `Sanctum::actingAs($user)` 호출. board 모듈의 `tests/Feature/Modules/Board/*Test.php` 도 동일 패턴.
- `auth_required: true` 가 layout JSON dataSource 에 있어야 프론트에서 토큰 자동 첨부.

**Policy `view` 가 false 반환 (testing)**
- Plan 1의 `InquiryPolicy::isOperator()` 는 `$user->can('inquiry.manage')` 호출. testing 환경에서 owner test 는 영향 없음(`isOwner` 통과). operator test가 필요한 경우 PolicyTest 의 setUp 패턴 참조.

**JSON dataSource binding `{{response.data.uuid}}`**
- new.json 의 `apiCall.onSuccess` 안에서 응답을 `{{response.data.uuid}}` 로 참조. 실제 키는 engine 구현에 따라 다를 수 있음. 작동 안 하면 `{{$result.data.uuid}}` 또는 `{{result.data.uuid}}` 도 시도.

**InquiryMessageThread 의 onSend 액션 매핑**
- composite 컴포넌트의 `onSend?: (body: string) => void` 콜백을 layout JSON 의 actions 배열로 어떻게 연결할지는 본 프로젝트의 ActionDispatcher 패턴에 의존. board의 비슷한 콜백 패턴(예: 검색바 onSearch) 참고.

## 부록 B — Plan 3-4 와의 인터페이스

Plan 3에서 추가될 부분:
- 견적 카드 컴포넌트(`QuoteCard`, `QuotePayButton`) — `inquiry.data.quotes` 의 각 견적을 받아 본인이고 `status='quoted'` 일 때 결제 트리거.
- `accept_and_pay` / `reject_quote` 액션을 부르는 modal partial.
- 어드민 화면 (`/admin/inquiry/*`) + `Admin\InquiryController`, `Admin\InquiryQuoteController`.
- `InquiryPaymentBridge` 서비스 + ecommerce `OrderPaid` event listener.

Plan 4에서 추가될 부분:
- Notification 클래스 6종 + database/mail 채널.
- `DispatchInquiryNotifications` Listener — `InquiryStatusTransitioned` / `InquiryMessagePosted` 이벤트 구독.
- `inquiry:cleanup-orphan-attachments` Schedule 명령.
- E2E 알림 테스트.
