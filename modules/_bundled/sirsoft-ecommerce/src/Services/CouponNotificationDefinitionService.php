<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Contracts\Repositories\NotificationDefinitionRepositoryInterface;
use App\Contracts\Repositories\NotificationTemplateRepositoryInterface;
use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Listeners\NotificationHookListener; // audit:allow listener-not-from-controller-service reason: 새로 켠 알림 정의를 이 요청 안에서 구독시키는 등록 인자(구독 대상 클래스·큐 옵션)로만 쓴다 — 리스너를 호출하지 않는다
use App\Models\NotificationDefinition;
use App\Services\NotificationDefinitionService;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponRepositoryInterface;

/**
 * 쿠폰별 발급 알림 종류의 수명 관리
 *
 * 발급 알림을 켠 쿠폰마다 알림 종류 `coupon_issued.{쿠폰ID}` 를 G7 알림 템플릿 시스템에 만든다. 채널 켜기/끄기·제목·본문·
 * 미리보기·기본값 복원·문자/알림톡 템플릿은 기존 알림 템플릿 시스템과 커머스 편집 창을 그대로 쓴다.
 *
 * - 켜면 정의·템플릿을 upsert 한다(운영자가 고친 칸 `user_overrides` 는 보존). 쿠폰명이 바뀌면 정의 이름만 갱신된다.
 * - 끄면 정의를 비활성으로 둔다 — 문구는 남아 다시 켜면 그대로 돌아온다.
 * - 쿠폰을 지우면 정의와 템플릿을 함께 지운다.
 * - 모듈 선언(`Module::getNotificationDefinitions()`)도 이 서비스의 `declaredDefinitions()` 를 붙여, 확장 업데이트의
 *   「선언에 없는 정의 정리」 가 쿠폰 정의를 지우지 않게 한다.
 *
 * 기본 템플릿은 사이트 알림만 켜고 메일은 끈다(새 회원 알림 공통 기본값).
 *
 * 메일 기본 본문의 틀은 `resources/mail/coupon_issued.html` 이다(메일 클라이언트는 외부 CSS 를 읽지 않아 인라인 서식이
 * 필요하므로 코드 밖에 둔다). 틀의 `[[키]]` 는 모듈 언어 파일 `messages.coupon.notification.*` 문구로 채우며,
 * 번역이 있는 지원 언어마다 만든다.
 */
class CouponNotificationDefinitionService
{
    /** 확장 소유자 식별자 */
    private const EXTENSION_IDENTIFIER = 'sirsoft-ecommerce';

    /** 메일 기본 본문 틀(언어 문구 자리 `[[키]]`) */
    private const MAIL_TEMPLATE_PATH = __DIR__.'/../../resources/mail/coupon_issued.html';

    /** @var array<string, true> 이 요청에서 동적 훅을 등록한 알림 종류 (중복 등록 방지) */
    private static array $registeredInRequest = [];

    /**
     * @param  NotificationSyncHelper  $syncHelper  알림 정의 동기화 헬퍼
     * @param  NotificationDefinitionRepositoryInterface  $definitionRepository  알림 정의 저장소
     * @param  NotificationTemplateRepositoryInterface  $templateRepository  알림 템플릿 저장소
     * @param  NotificationDefinitionService  $definitionService  알림 정의 서비스 (캐시 무효화)
     * @param  CouponRepositoryInterface  $couponRepository  쿠폰 저장소
     */
    public function __construct(
        protected NotificationSyncHelper $syncHelper,
        protected NotificationDefinitionRepositoryInterface $definitionRepository,
        protected NotificationTemplateRepositoryInterface $templateRepository,
        protected NotificationDefinitionService $definitionService,
        protected CouponRepositoryInterface $couponRepository,
    ) {}

    /**
     * 쿠폰 하나의 알림 정의 선언 배열을 만듭니다.
     *
     * @param  Coupon  $coupon  쿠폰
     * @return array<string, mixed> 알림 정의 선언 (templates 포함)
     */
    public function definitionFor(Coupon $coupon): array
    {
        $names = [];
        foreach ($this->locales() as $locale) {
            $names[$locale] = $this->text('definition_name', $locale, ['coupon' => $coupon->getLocalizedName($locale)]);
        }

        return [
            'type' => $coupon->notificationType(),
            'hook_prefix' => self::EXTENSION_IDENTIFIER,
            'audience' => 'customer',
            'name' => $names,
            'description' => $this->localized('definition_description'),
            'channels' => ['mail', 'database'],
            'hooks' => [$coupon->notificationHook()],
            'is_active' => (bool) $coupon->notify_enabled && $coupon->issue_method !== CouponIssueMethod::DOWNLOAD,
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트 이름'],
                ['key' => 'ad_prefix', 'description' => '광고성 표시 (광고성 쿠폰일 때만 「(광고) 」, 아니면 빈 값)'],
                ['key' => 'coupon_name', 'description' => '쿠폰명'],
                ['key' => 'coupon_benefit', 'description' => '혜택 (예: 3,000원 할인)'],
                ['key' => 'coupon_min_order_amount', 'description' => '최소 주문 금액'],
                ['key' => 'coupon_expires_date', 'description' => '사용 기한 (없으면 「기한 없음」)'],
                ['key' => 'coupon_code', 'description' => '쿠폰 코드'],
                ['key' => 'issue_source_label', 'description' => '발급 경로 (가입·첫구매·생일·직접발급)'],
                ['key' => 'coupons_url', 'description' => '쿠폰함 URL'],
                ['key' => 'coupons_path', 'description' => '쿠폰함 경로 (사이트 주소 없이)'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'is_active' => false,
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => $this->localized('mail_subject'),
                    'body' => $this->mailBodies(),
                ],
                [
                    'channel' => 'database',
                    'is_active' => true,
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => $this->localized('database_subject'),
                    'body' => $this->localized('database_body'),
                    'click_url' => '{coupons_path}',
                ],
            ],
        ];
    }

    /**
     * 모듈 선언에 붙일 쿠폰 알림 정의 목록을 반환합니다.
     *
     * 알림을 켠 쿠폰과, 알림을 껐어도 정의가 이미 있는 쿠폰(문구 보존용 비활성 정의)을 함께 선언한다.
     * 테이블이 아직 없는 설치 전 시점에는 빈 배열이다.
     *
     * @return array<int, array<string, mixed>> 알림 정의 선언 목록
     */
    public function declaredDefinitions(): array
    {
        try {
            if (! Schema::hasTable('ecommerce_promotion_coupons') || ! Schema::hasColumn('ecommerce_promotion_coupons', 'notify_enabled')) {
                return [];
            }

            $existingIds = $this->existingCouponIds();

            return $this->couponRepository->getNotificationCoupons($existingIds)
                ->map(fn (Coupon $coupon) => $this->definitionFor($coupon))
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('쿠폰 알림 정의 선언 수집 실패', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * 쿠폰 저장(생성·수정) 뒤 그 쿠폰의 알림 정의를 맞춥니다.
     *
     * @param  Coupon  $coupon  저장된 쿠폰
     * @return NotificationDefinition|null 정의 (알림을 켠 적이 없어 만들 것이 없으면 null)
     */
    public function sync(Coupon $coupon): ?NotificationDefinition
    {
        $existing = $this->definitionRepository->findByType($coupon->notificationType());
        $enable = (bool) $coupon->notify_enabled && $coupon->issue_method !== CouponIssueMethod::DOWNLOAD;

        // 알림을 켠 적이 없는 쿠폰은 정의를 만들지 않는다
        if ($existing === null && ! $enable) {
            return null;
        }

        $wasActive = (bool) ($existing?->is_active);

        $data = $this->definitionFor($coupon);
        $data = $this->syncHelper->applySeedDefinitionsFilter([$data], 'module', self::EXTENSION_IDENTIFIER)[0] ?? $data;
        $data['extension_type'] = 'module';
        $data['extension_identifier'] = self::EXTENSION_IDENTIFIER;

        $definition = $this->syncHelper->syncDefinition($data);

        // 켜기/끄기는 쿠폰의 「발급 알림 사용」 이 정한다 — 운영자가 알림 설정에서 바꾼 활성 값(user_overrides)보다 우선한다
        if ((bool) $definition->is_active !== $enable) {
            $definition = $this->definitionRepository->update($definition, ['is_active' => $enable]);
        }

        $channels = [];
        foreach ($data['templates'] ?? [] as $template) {
            $this->syncHelper->syncTemplate($definition->id, $template);
            $channels[] = $template['channel'];
        }
        $this->syncHelper->cleanupStaleTemplates($definition->id, $channels);

        // 정의 목록이 캐시돼 있다 — 비우지 않으면 다음 요청이 새 훅을 등록하지 않는다
        $this->definitionService->invalidateAllCache();

        // 이 요청 안에서 이어지는 발급(직접발급 등)도 알림을 받게, 새로 켠 정의의 훅을 지금 등록한다
        if ($enable && ! $wasActive) {
            $this->registerHooksInCurrentRequest($definition->fresh() ?? $definition);
        }

        return $definition;
    }

    /**
     * 쿠폰 삭제 뒤 그 쿠폰의 알림 정의와 템플릿을 지웁니다.
     *
     * 템플릿을 모델 단위로 먼저 지운다(캐시 무효화 이벤트) — DB CASCADE 에 기대지 않는다. 지운 뒤 코어 표준 삭제 훅
     * (`core.notification_definition.after_delete`)을 발화해, 채널 플러그인(문자·알림톡)이 자기 템플릿 행을 함께 정리하게 한다.
     *
     * @param  int  $couponId  쿠폰 ID
     * @return bool 지운 정의가 있으면 true
     */
    public function remove(int $couponId): bool
    {
        $definition = $this->definitionRepository->findByType(Coupon::NOTIFICATION_TYPE_PREFIX.$couponId);
        if ($definition === null) {
            return false;
        }

        HookManager::doAction('core.notification_definition.before_delete', $definition);

        foreach ($this->templateRepository->getByDefinitionId($definition->id) as $template) {
            $this->templateRepository->delete($template);
        }

        $this->definitionRepository->delete($definition);
        $this->definitionService->invalidateAllCache();

        HookManager::doAction('core.notification_definition.after_delete', $definition);

        return true;
    }

    /**
     * 알림 종류가 쿠폰별 발급 알림인지 판정합니다.
     *
     * @param  string|null  $type  알림 종류
     * @return bool 쿠폰별 발급 알림이면 true
     */
    public static function isCouponType(?string $type): bool
    {
        return is_string($type) && str_starts_with($type, Coupon::NOTIFICATION_TYPE_PREFIX);
    }

    /**
     * 정의가 이미 있는 쿠폰 ID 목록을 반환합니다.
     *
     * @return array<int, int> 쿠폰 ID
     */
    private function existingCouponIds(): array
    {
        if (! Schema::hasTable('notification_definitions')) {
            return [];
        }

        return $this->definitionRepository->getByExtension('module', self::EXTENSION_IDENTIFIER)
            ->filter(fn (NotificationDefinition $definition) => self::isCouponType($definition->type))
            ->map(fn (NotificationDefinition $definition) => (int) substr((string) $definition->type, strlen(Coupon::NOTIFICATION_TYPE_PREFIX)))
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();
    }

    /**
     * 정의의 훅을 이 요청에서 바로 구독합니다. (부팅 때 등록되지 않은 새 정의)
     *
     * @param  NotificationDefinition  $definition  알림 정의
     * @return void 반환값 없음
     */
    private function registerHooksInCurrentRequest(NotificationDefinition $definition): void
    {
        if (isset(self::$registeredInRequest[$definition->type])) {
            return;
        }
        self::$registeredInRequest[$definition->type] = true;

        foreach ($definition->hooks ?? [] as $hook) {
            HookListenerRegistrar::registerDynamicAction(
                hookName: $hook,
                listenerClass: NotificationHookListener::class, // audit:allow listener-not-from-controller-service reason: 동적 구독 등록 인자(호출 아님)
                method: 'dispatchForDefinition',
                boundArgs: [$definition],
                priority: 30,
                queueOptions: NotificationHookListener::QUEUE_OPTIONS, // audit:allow listener-not-from-controller-service reason: 동적 구독 등록 인자(호출 아님)
            );
        }
    }

    /**
     * 메일 본문을 언어마다 만듭니다.
     *
     * @return array<string, string> 언어 => HTML 본문
     */
    private function mailBodies(): array
    {
        $template = trim((string) file_get_contents(self::MAIL_TEMPLATE_PATH));
        $keys = ['mail_heading', 'mail_intro', 'label_coupon', 'label_benefit', 'label_min_order', 'label_expires', 'mail_button', 'mail_footer'];

        $bodies = [];
        foreach ($this->locales() as $locale) {
            $replace = [];
            foreach ($keys as $key) {
                $replace['[['.$key.']]'] = $this->text($key, $locale);
            }
            $bodies[$locale] = strtr($template, $replace);
        }

        return $bodies;
    }

    /**
     * 번역이 있는 언어마다 같은 키의 문구를 모읍니다.
     *
     * @param  string  $key  `messages.coupon.notification.` 아래 키
     * @return array<string, string> 언어 => 문구
     */
    private function localized(string $key): array
    {
        $texts = [];
        foreach ($this->locales() as $locale) {
            $texts[$locale] = $this->text($key, $locale);
        }

        return $texts;
    }

    /**
     * 문구 하나를 그 언어로 번역합니다.
     *
     * @param  string  $key  `messages.coupon.notification.` 아래 키
     * @param  string  $locale  언어
     * @param  array<string, mixed>  $replace  치환값
     * @return string 문구
     */
    private function text(string $key, string $locale, array $replace = []): string
    {
        return (string) __('sirsoft-ecommerce::messages.coupon.notification.'.$key, $replace, $locale);
    }

    /**
     * 문구를 만들 언어 목록 — 지원 언어 중 모듈 번역이 있는 언어입니다. (한국어·영어는 늘 포함)
     *
     * @return array<int, string> 언어 코드
     */
    private function locales(): array
    {
        $supported = (array) config('app.supported_locales', ['ko', 'en']);
        $locales = [];

        foreach ($supported as $locale) {
            if (! is_string($locale)) {
                continue;
            }

            if (in_array($locale, ['ko', 'en'], true)
                || Lang::has('sirsoft-ecommerce::messages.coupon.notification.mail_subject', $locale, false)) {
                $locales[] = $locale;
            }
        }

        return array_values(array_unique(array_merge(['ko', 'en'], $locales)));
    }
}
