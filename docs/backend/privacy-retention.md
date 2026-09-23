# 개인정보 보유기간 처리 (Privacy Retention)

> 보유기간이 지난 개인정보를 도메인별로 파기·익명화하는 공통 인프라와, 모듈·플러그인이 자기 도메인을 여기에 붙이는 방법

## TL;DR (5초 요약)

```text
1. 확장은 PrivacyRetentionHandlerInterface 를 구현한 백엔드 클래스(핸들러)를 core.privacy.retention_handlers 필터로 등록한다
2. 코어가 수집·실행·건수·대상 명단·권한 검사를 맡는다 — 확장은 "무엇이 대상이고 어떻게 지우는가"만 구현한다
3. isEnabled() 는 자기 설정을 기본 false 로 읽고, process() 는 그 토글을 다시 본다 (되돌릴 수 없는 파기)
4. preview()·targets()·process() 는 같은 조건식 — 화면 건수 = 모달 명단 = 배치가 처리할 대상
5. 건수·명단은 기능이 꺼져 있어도 저장된 기간값으로 보인다 · 명단 권한은 targetPermissions() (설정 읽기 + 도메인 읽기)
```

---

## 1. 왜 이 구조인가

개인정보 보호법은 보유기간이 지난 개인정보를 **파기하거나 익명처리**하도록 요구합니다(익명처리도 파기로 인정됩니다). 그런데 "무엇이 개인정보이고 언제 기간이 끝나는가" 는 도메인마다 다릅니다 — 탈퇴 회원의 잔여 정보, 종결 주문의 주문자·수령인, 게시글의 작성 IP 는 서로 다른 테이블·기준일·처리 방식을 갖습니다.

그래서 코어는 도메인을 알지 못한 채 **공통 절차만** 소유하고, 각 도메인 소유자(코어 회원, 이커머스 모듈, 게시판 모듈 …)가 자기 대상을 스스로 구현합니다. 본인인증 프로바이더(`identity-providers.md`)와 같은 방식입니다.

| 층 | 소유 | 하는 일 |
| --- | --- | --- |
| 핸들러 (백엔드 클래스) | 각 도메인 | 대상 조건식 · 파기/익명화 방법 · 건수 · 대상 명단 · 명단 권한 |
| 수집기 `PrivacyRetentionManager` | 코어 | 필터 훅으로 핸들러를 모으고, 계약 위반·예외를 격리하고, 건수·명단·실행을 중개 |
| 배치 `privacy:process-retention` | 코어 | 매일 새벽 등록된 핸들러 전부를 실행하고 도메인별로 보고 |
| 건수 API | 코어 · 각 모듈 | 설정 화면의 「대상 N건」 배지 |
| 대상 명단 API `GET /api/admin/privacy/retention-targets/{handler}` | 코어 | 「대상 보기」 모달의 무한 스크롤 목록 — 핸들러를 식별자로 찾고 권한을 요청 시점에 검사 |

확장이 핸들러를 등록하는 순간 배치·건수·명단·권한 검사가 코어 코드 수정 없이 따라옵니다. 확장이 비활성이면 등록이 빠지므로 명단 API 는 그 식별자에 404 를 돌려줍니다.

### 관련 기능과의 경계

- **휴면 계정**(사전 안내 → 휴면 전환 → 탈퇴 예고 → 자동 탈퇴)은 코어 전용 생명주기입니다. 이 계약으로 확장할 수 없고, 대상 명단은 별도 경로 `GET /api/admin/privacy/dormant-targets/{stage}` 가 같은 행 모양으로 내려줍니다.
- **연간 개인정보 이용내역 통지**(GDPR 플러그인)는 파기가 아니라 통지라 이 계약 밖입니다. 통지문에 도메인 섹션을 싣는 계약은 `core.privacy.notice_sections`(훅 문서)이고, 통지 대상 명단은 플러그인 API 가 같은 행 모양으로 내려줍니다.

## 2. 인터페이스 구현

```php
namespace Modules\Vendor\Shop\Privacy;

use App\Extension\HookManager;
use App\Privacy\Contracts\PrivacyRetentionHandlerInterface;
use App\Privacy\PrivacyRetentionTarget;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Vendor\Shop\Models\Inquiry;

class InquiryRetentionHandler implements PrivacyRetentionHandlerInterface
{
    public function id(): string
    {
        return 'vendor-shop.inquiry';            // 전역 유일 — 확장 식별자를 접두사로
    }

    public function label(): string
    {
        return __('vendor-shop::messages.privacy_retention.handler_label');
    }

    public function isEnabled(): bool
    {
        // 기본 false — 설치했다는 사실만으로 데이터가 지워지면 안 된다
        return (bool) module_setting('vendor-shop', 'privacy_retention.enabled', false);
    }

    public function retentionDays(): int
    {
        // 판독부 바닥값 — 저장 검증을 거치지 않은 0·음수가 "오늘 작성분까지 파기" 가 되지 않게
        return max(1, (int) module_setting('vendor-shop', 'privacy_retention.days', 365));
    }

    public function preview(): int
    {
        return $this->targetQuery()->count();
    }

    public function targets(int $page, int $perPage): LengthAwarePaginator
    {
        return $this->targetQuery()
            ->orderBy('created_at')->orderBy('id')         // 가장 먼저 대상이 된 것부터
            ->paginate($perPage, ['id', 'title', 'author_name', 'created_at'], 'page', $page)
            ->through(fn (Inquiry $row) => new PrivacyRetentionTarget(
                id: $row->id,
                title: (string) $row->title,
                subtitle: $row->author_name,
                date: $row->created_at,
                url: '/admin/shop/inquiries/'.$row->id,  // 관리자가 새 탭으로 연다
            ));
    }

    public function targetColumns(): array
    {
        return [
            'title' => __('vendor-shop::messages.privacy_retention.columns.title'),
            'subtitle' => __('vendor-shop::messages.privacy_retention.columns.author'),
            'date' => __('vendor-shop::messages.privacy_retention.columns.created_at'),
        ];
    }

    public function targetPermissions(): array
    {
        // 설정 읽기 + 도메인 읽기 — 명단에 작성자 이름이 그대로 나온다
        return ['vendor-shop.settings.read', 'vendor-shop.inquiries.read'];
    }

    public function process(bool $dryRun): array
    {
        if (! $this->isEnabled()) {                   // 호출자를 믿지 않는다
            return ['count' => 0, 'mode' => 'disabled'];
        }

        if ($dryRun) {                                // dry-run 은 쓰지도, 훅을 발행하지도 않는다
            return ['count' => $this->preview(), 'mode' => 'anonymize'];
        }

        $days = $this->retentionDays();
        HookManager::doAction('vendor-shop.inquiry.before_prune', $days);

        $processed = 0;
        $this->targetQuery()->chunkById(200, function ($rows) use (&$processed) {
            foreach ($rows as $row) {
                try {
                    // 행 단위로 격리 — 한 건의 실패가 나머지를 멈추지 않게
                    Inquiry::whereKey($row->id)->toBase()->update(['author_name' => '***', 'ip_address' => null]);
                    $processed++;
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });

        HookManager::doAction('vendor-shop.inquiry.after_prune', $days, $processed);

        return ['count' => $processed, 'mode' => 'anonymize'];
    }

    /** 미리보기·명단·실행이 함께 쓰는 단 하나의 조건식 (멱등 표지 포함) */
    private function targetQuery(): Builder
    {
        return Inquiry::query()
            ->where('created_at', '<=', now()->subDays($this->retentionDays()))
            ->where(fn (Builder $q) => $q->whereNull('author_name')->orWhere('author_name', '!=', '***'));
    }
}
```

## 3. 계약 규약 (CRITICAL)

| 메서드 | 규약 | 지키지 않으면 |
| --- | --- | --- |
| `id()` | 전역 유일, 확장 식별자 접두사. 명단 API 경로·`--handler=` 옵션·보고의 키 | 같은 식별자가 둘이면 먼저 등록된 쪽만 남는다(뒤엣것이 코어 핸들러를 가로채지 못하게) |
| `isEnabled()` | 자기 설정을 **기본 false** 로 읽는다 | 확장을 설치만 해도 다음 새벽 배치가 개인정보를 지운다 |
| `preview()` | 데이터를 바꾸지 않는다. 꺼져 있어도 저장된 기간값으로 센다 | 운영자가 켜기 전에 규모를 볼 수 없다 |
| `targets()` | `preview()` 와 **같은 조건식**. 한 쪽씩 `LengthAwarePaginator`, 항목은 `PrivacyRetentionTarget`. 기준일 오름차순 권장 | 모달에서 본 명단과 실제 처리 대상이 달라진다 |
| `targetColumns()` | `title`·`subtitle`·`context`·`date` 중 쓰는 열만 라벨과 함께 | 빠진 열은 화면이 그리지 않는다(값이 있어도 보이지 않는다) |
| `targetPermissions()` | **설정 읽기 + 그 도메인 읽기** (모두 필요). 코어는 각 권한의 적용 범위가 **전체**일 때만 명단을 연다(`self`·`role` 범위면 거부 — `PrivacyRetentionManager::canViewTargetList()`) | 설정 화면만 볼 수 있는 운영자가 회원·주문 명단을 보는 우회 경로가 생긴다. 빈 배열이면 코어가 거부한다. 범위를 좁혀 위임받은 관리자는 목록 화면에서 본인·같은 역할 것만 보므로, 도메인 전체 명단을 열면 목록보다 넓게 보게 된다 |
| `process(true)` | 건수만 센다 — 쓰기 0, prune 훅 발행 0 | 리허설이 실제 파기가 되거나, 확장 리스너가 돌았다고 착각한다 |
| `process(false)` | 토글 재확인 → `before_prune` → 행 단위 격리 처리 → `after_prune`. 멱등(두 번째 실행 0건) | 한 행의 예외가 나머지 법정 파기를 멈추거나, 같은 행을 매일 다시 처리한다 |

### 대상 명단의 행 (`PrivacyRetentionTarget`)

| 필드 | 뜻 | 예 |
| --- | --- | --- |
| `id` | 목록 안에서 유일 | `12`, `post-2761` |
| `title` | 첫 열 | 회원 이름 · 주문번호 · 게시글 제목 |
| `subtitle` | 둘째 열 (없으면 null) | 이메일 · 주문자 · 작성자 |
| `context` | 셋째 열 (없으면 null) | 게시판 이름 · 글/댓글 구분 · 주문 상태 |
| `date` | 기준일 — 응답에는 사용자 시간대 `Y-m-d` | 탈퇴일 · 종결일 · 작성일 |
| `url` | 상세 화면 경로 (없으면 null) — 새 탭으로 연다 | `/admin/users/{uuid}` |

명단은 **식별에 필요한 최소 열**만 싣습니다. 본문·첨부·연락처처럼 식별 이상의 정보는 싣지 않고, 필요하면 `url` 로 상세 화면을 엽니다(상세 화면은 자기 권한·비밀글 게이트를 그대로 적용합니다).

## 4. 등록

모듈·플러그인은 훅 리스너로 필터에 클래스명을 덧붙입니다. 리스너는 DB 에 접근하지 않고 클래스명만 더합니다 — 켜짐 판정과 실행은 각 핸들러가 스스로 합니다.

```php
class PrivacyRetentionListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            'core.privacy.retention_handlers' => ['method' => 'addHandler', 'type' => 'filter', 'priority' => 20],
        ];
    }

    public function handle(...$args): void {}

    public function addHandler(array $handlers): array
    {
        $handlers[] = InquiryRetentionHandler::class;

        return $handlers;
    }
}
```

수집기는 계약을 구현하지 않았거나 생성·식별자 조회에 실패한 클래스를 **기록 후 건너뜁니다** — 확장 하나의 잘못된 등록이 다른 도메인의 파기를 함께 멈추지 않게 하고, 동시에 조용히 버리지도 않습니다(로그에 남지 않으면 운영자는 그 도메인이 처리되고 있다고 믿습니다).

## 5. 설정과 화면

| 항목 | 규약 |
| --- | --- |
| 설정 키 | 자기 설정 카테고리에 `privacy_retention.enabled`(기본 false)와 기간·방식. 기간은 판독부에서 바닥값을 둔다 |
| 법정 하한 | 법이 최소 보존 기간을 정한 도메인(예: 전자상거래 5년)은 저장 검증과 판독부 모두에서 하한을 강제한다 |
| 처리 방식 | 익명화/삭제를 고를 수 있으면 기본은 되돌릴 수 있는 쪽에 가까운 **익명화**. 삭제는 무엇이 함께 사라지는지(리뷰·영수증 조회 등) 경고 문구로 알린다 |
| 건수 배지 | 자기 설정 화면에 「대상 N건」 — 기능이 꺼져 있어도 보인다. 건수 API 는 `enabled`·건수·`can_view_targets` 를 함께 돌려준다 |
| 「대상 보기」 | `can_view_targets` 이고 건수가 0 보다 클 때만 그린다. 누르면 목록 상태를 첫 쪽으로 되돌리고 코어 명단 API 를 다시 받아 모달을 연다 |
| 명단 모달 | 한 쪽 20건, 스크롤 끝에서 다음 쪽을 이어 붙인다(`has_more_pages` 가 false 면 멈춘다). 비면 빈 상태 문구 |
| 사전 안내 | 설정 화면에 「켜기 전에 개인정보 처리방침에 보유기간을 적고 공지하라」는 고지를 둔다 — 법은 사전 안내 후 운영을 요구한다 |

## 6. 실행과 운영

```bash
php artisan privacy:process-retention --dry-run                        # 전 핸들러 대상 건수만
php artisan privacy:process-retention --dry-run --handler=vendor-shop.inquiry
php artisan privacy:process-retention                                   # 실제 처리 (켜진 핸들러만)
```

- 매일 새벽(05:05) 서버 한 대에서 예약 실행됩니다. 꺼진 핸들러는 「꺼져 있어 실행하지 않았습니다」로, 켜져 있으나 대상이 없으면 「0건」으로 **구분해** 보고합니다.
- `--handler=` 에 빈 값을 주면 전 핸들러를 돌리지 않고 실패합니다(오타·빈 변수가 모든 도메인의 파기로 번지지 않게).
- 한 도메인이 실패해도 나머지는 처리되고, 커맨드는 실패 종료 코드로 알립니다. 실패한 행은 표지가 찍히지 않아 다음 실행이 다시 잡습니다.

## 7. prune 훅

각 핸들러는 실제 처리 전후로 자기 접두사의 prune 훅을 발행합니다 — 인자는 `(보존일)` / `(보존일, 처리건수)` 이고 대상을 ID 로 지목하지 않습니다. **`--dry-run` 에서는 발행하지 않습니다.** 훅 이름은 리터럴로 적어야 문서 생성기와 이름 검색이 찾습니다. 번들 핸들러의 접두사 전량은 [훅 문서](../extension/hooks.md)의 「보존 기간 자동 파기」 카탈로그에 있습니다.

익명화한 행에 확장이 따로 둔 사본(결제 대행사 메타 등)이 있으면, 도메인 소유자가 행 한 건마다 발행하는 액션 훅(예: `sirsoft-ecommerce.order.after_anonymize`)을 구독해 그 사본을 지웁니다. 이 훅은 익명화 트랜잭션 안에서 발행되므로 구독 리스너는 동기(`sync`)로 등록합니다 — 리스너가 실패하면 표지까지 롤백되어 다음 실행이 다시 처리합니다.

## 8. 핸들러 추가 후 검증 매트릭스 (MANDATORY)

| 축 | 단언 |
| --- | --- |
| 기본 꺼짐 | 설정 없이 `isEnabled()` false, `process(false)` 가 아무것도 바꾸지 않는다 |
| 같은 모집단 | `preview()` = `targets()->total()` = `process(true)['count']`, 명단 id 집합 = 대상 쿼리 id 집합 |
| 기간 경계 | 기간 안의 행은 남고 밖의 행만 처리된다. 기간 0·음수가 바닥값으로 막힌다 |
| dry-run | 쓰기 0 · prune 훅 발행 0 |
| 멱등 | 두 번째 실행 0건 |
| 격리 | 한 행의 예외가 나머지 행·다른 핸들러를 멈추지 않는다 |
| 명단 권한 | 설정 읽기만 가진 관리자는 명단 API 403, 건수 API 의 `can_view_targets` false |
| 명단 페이지 | 마지막 쪽에서 `has_more_pages` false, 쪽이 겹치거나 빠지지 않는다 |
| 코어 연결 | 핸들러가 코어 명단 API 에 식별자로 붙는다(등록 경로 그대로) |

## 9. 잘못된 패턴 (DO NOT)

| ❌ 금지 | ✅ 올바른 패턴 |
| --- | --- |
| 미리보기·명단·실행에 조건식을 따로 적는다 | 대상 쿼리 메서드 하나를 셋이 함께 쓴다 |
| 기능이 꺼져 있으면 건수·명단을 비운다 | 저장된 기간값으로 세고, 꺼짐은 `enabled` 로 알린다 |
| `targetPermissions()` 에 설정 읽기만 넣는다 | 설정 읽기 + 그 도메인 읽기 |
| 명단 행에 본문·연락처·첨부를 싣는다 | 식별 최소 열 + 상세 `url` |
| OFFSET 페이지로 대상을 순회하며 처리한다 | `chunkById` — 처리하면서 조건에서 빠지는 행이 커서를 밀지 않게 |
| 확장 핸들러가 이메일을 코어의 파기 완료 표지(`@removed.invalid`)로 바꾼다 | 코어 회원 행은 건드리지 않는다 — 그 표지를 쓰면 코어 탈퇴 잔여 파기 대상에서 영구히 빠진다 |
| 파기 완료를 `updated_at` 을 바꾸는 Eloquent update 로 기록한다 | 값만 지우고 수정 시각은 보존한다(`toBase()` update) — 파기는 글의 수정이 아니다 |
| 삭제 모드에서 DB CASCADE 에 기대 연관 행을 지운다 | 서비스가 연관 행을 명시적으로 지운다 |

## 10. 관련 코드 진입점

- **계약**: `app/Privacy/Contracts/PrivacyRetentionHandlerInterface.php`
- **명단 행**: `app/Privacy/PrivacyRetentionTarget.php`
- **수집기**: `app/Privacy/PrivacyRetentionManager.php`
- **배치**: `app/Console/Commands/ProcessPrivacyRetentionCommand.php` (`routes/console.php` 예약 등록)
- **명단 API**: `app/Http/Controllers/Api/Admin/PrivacyTargetController.php` · `app/Http/Resources/PrivacyTargetCollection.php`
- **코어 참조 구현**: `app/Privacy/Handlers/WithdrawnUserRetentionHandler.php` (탈퇴 회원 잔여 정보)
- **휴면**: `app/Privacy/DormantAccountService.php` (코어 전용)
- **번들 확장 구현**: 이커머스 `modules/_bundled/sirsoft-ecommerce/src/Privacy/OrderRetentionHandler.php` · 게시판 `modules/_bundled/sirsoft-board/src/Privacy/BoardIpRetentionHandler.php`(가장 단순한 예) · `BoardGuestRetentionHandler.php`
- **회귀 테스트**: `tests/Feature/Privacy/PrivacyRetentionManagerTest.php` · `tests/Feature/Privacy/PrivacyTargetApiTest.php`

## 11. 관련 문서

- [../extension/hooks.md](../extension/hooks.md) — `core.privacy.retention_handlers` 필터 계약, prune 훅 카탈로그, 휴면 알림 훅
- [api/privacy.md](api/privacy.md) — 대상 명단 API 레퍼런스
- [api/settings.md](api/settings.md) — 개인정보 탭 건수 API(`privacy-preview`)
- [identity-providers.md](identity-providers.md) — 같은 방식(계약 구현 + 훅 등록)의 형제 인프라
- [notification-system.md](notification-system.md) — 휴면 안내 알림 정의
