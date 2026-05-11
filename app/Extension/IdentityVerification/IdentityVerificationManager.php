<?php

namespace App\Extension\IdentityVerification;

use App\Contracts\Extension\IdentityVerificationInterface;
use App\Enums\IdentityPolicySourceType;
use App\Enums\IdentityVerificationChannel;
use App\Enums\IdentityVerificationPurpose;
use App\Extension\HookManager;
use InvalidArgumentException;

/**
 * 본인인증 프로바이더 레지스트리 / 매니저.
 *
 * 기존 드라이버 레시피(Interface + CoreServiceProvider 바인딩 + 필터 훅 등록)를 그대로 따릅니다.
 * 플러그인은 `core.identity.registered_providers` 필터 훅으로 프로바이더를 등록합니다.
 *
 * @since 7.0.0-beta.4
 */
class IdentityVerificationManager
{
    /**
     * @var array<string, IdentityVerificationInterface>
     */
    protected array $providers = [];

    /**
     * 확장(모듈/플러그인) 이 선언한 purpose 레지스트리.
     *
     * `AbstractModule::getIdentityPurposes()` / `AbstractPlugin::getIdentityPurposes()`
     * 반환값을 `ModuleManager` / `PluginManager` 가 부팅 시 여기에 병합합니다.
     * DB 에 저장되지 않는 **코드 계약** 입니다.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $declaredPurposes = [];

    /**
     * 코어 기본 purpose 목록.
     *
     * `signup` / `password_reset` / `self_update` / `sensitive_action` — 이 4종은
     * 코어가 계약으로 보장하며 `MailIdentityProvider` 가 모두 지원합니다.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $corePurposes = [
        IdentityVerificationPurpose::Signup->value => [
            'label' => 'identity.purposes.signup.label',
            'description' => 'identity.purposes.signup.description',
            'default_provider' => null,
            'allowed_channels' => [IdentityVerificationChannel::Email->value],
            'source_type' => IdentityPolicySourceType::Core->value,
            'source_identifier' => 'core',
        ],
        IdentityVerificationPurpose::PasswordReset->value => [
            'label' => 'identity.purposes.password_reset.label',
            'description' => 'identity.purposes.password_reset.description',
            'default_provider' => null,
            'allowed_channels' => [IdentityVerificationChannel::Email->value],
            'source_type' => IdentityPolicySourceType::Core->value,
            'source_identifier' => 'core',
        ],
        IdentityVerificationPurpose::SelfUpdate->value => [
            'label' => 'identity.purposes.self_update.label',
            'description' => 'identity.purposes.self_update.description',
            'default_provider' => null,
            'allowed_channels' => [IdentityVerificationChannel::Email->value],
            'source_type' => IdentityPolicySourceType::Core->value,
            'source_identifier' => 'core',
        ],
        IdentityVerificationPurpose::SensitiveAction->value => [
            'label' => 'identity.purposes.sensitive_action.label',
            'description' => 'identity.purposes.sensitive_action.description',
            'default_provider' => null,
            'allowed_channels' => [IdentityVerificationChannel::Email->value],
            'source_type' => IdentityPolicySourceType::Core->value,
            'source_identifier' => 'core',
        ],
    ];

    /**
     * 기본 프로바이더 id (설정에 의해 덮어쓰기 가능).
     */
    protected string $defaultId = 'g7:core.mail';

    /**
     * 프로바이더를 등록합니다.
     *
     * @param  IdentityVerificationInterface  $provider  IDV 프로바이더 인스턴스
     * @return void
     */
    public function register(IdentityVerificationInterface $provider): void
    {
        $this->providers[$provider->getId()] = $provider;
    }

    /**
     * 프로바이더 등록을 해제합니다.
     *
     * @param  string  $id  프로바이더 식별자 (예: g7:core.mail)
     * @return void
     */
    public function unregister(string $id): void
    {
        unset($this->providers[$id]);
    }

    /**
     * 특정 id 의 프로바이더가 등록되어 있는지 확인합니다.
     *
     * @param  string  $id  프로바이더 식별자
     * @return bool 등록 여부
     */
    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    /**
     * 특정 id 의 프로바이더를 반환합니다.
     *
     * @param  string  $id  프로바이더 식별자
     * @return IdentityVerificationInterface 등록된 provider
     *
     * @throws InvalidArgumentException 프로바이더 미등록 시
     */
    public function get(string $id): IdentityVerificationInterface
    {
        $providers = $this->all();
        if (! isset($providers[$id])) {
            throw new InvalidArgumentException("Identity verification provider not found: {$id}");
        }

        return $providers[$id];
    }

    /**
     * 등록된 전체 프로바이더 목록 (필터 훅 통과 후).
     *
     * @return array<string, IdentityVerificationInterface>
     */
    public function all(): array
    {
        $merged = HookManager::applyFilters('core.identity.registered_providers', $this->providers);

        if (! is_array($merged)) {
            return $this->providers;
        }

        $valid = [];
        foreach ($merged as $key => $provider) {
            if ($provider instanceof IdentityVerificationInterface) {
                $valid[$provider->getId()] = $provider;
            }
        }

        return $valid;
    }

    /**
     * 기본 프로바이더를 반환합니다.
     *
     * 우선순위: settings.identity.default_provider → 코어 기본 (g7:core.mail) → 등록된 첫 provider.
     *
     * @return IdentityVerificationInterface 기본 provider
     *
     * @throws InvalidArgumentException 등록된 provider 가 하나도 없을 때
     */
    public function default(): IdentityVerificationInterface
    {
        $configured = (string) config('settings.identity.default_provider', $this->defaultId);
        $providers = $this->all();

        if (isset($providers[$configured])) {
            return $providers[$configured];
        }

        if (isset($providers[$this->defaultId])) {
            return $providers[$this->defaultId];
        }

        if (empty($providers)) {
            throw new InvalidArgumentException('No identity verification provider is registered.');
        }

        return $providers[array_key_first($providers)];
    }

    /**
     * 특정 purpose 에 사용할 프로바이더를 해석합니다.
     *
     * 해석 순서:
     * 1. `settings.identity.purpose_providers.{purpose}` 에 명시적 지정 + 해당 프로바이더가 purpose 지원 → 사용
     * 2. 기본 프로바이더가 purpose 지원 → 사용
     * 3. 등록된 프로바이더 중 purpose 지원하는 첫 번째 → 사용
     * 4. 없으면 mail (코어 기본) 반환 — mail 은 모든 purpose 지원 계약
     *
     * 정책의 provider_id 가 우선되므로 (IdentityPolicyService::resolveRenderHint 참조), 본 메서드는
     * 정책에 provider_id 가 명시되지 않은 경우의 fallback 으로 사용됩니다.
     *
     * @param  string  $purpose  IDV 목적 (signup, password_reset, sensitive_action 등)
     * @return IdentityVerificationInterface 해석된 provider
     */
    public function resolveForPurpose(string $purpose): IdentityVerificationInterface
    {
        $providers = $this->all();

        $explicitId = (string) config("settings.identity.purpose_providers.{$purpose}", '');
        if ($explicitId !== '' && isset($providers[$explicitId]) && $providers[$explicitId]->supportsPurpose($purpose)) {
            return $providers[$explicitId];
        }

        $default = $this->default();
        if ($default->supportsPurpose($purpose)) {
            return $default;
        }

        foreach ($providers as $provider) {
            if ($provider->supportsPurpose($purpose)) {
                return $provider;
            }
        }

        if (isset($providers[$this->defaultId])) {
            return $providers[$this->defaultId];
        }

        throw new InvalidArgumentException("No identity verification provider supports purpose: {$purpose}");
    }

    /**
     * 확장(모듈/플러그인) 이 선언한 purpose 들을 레지스트리에 등록합니다.
     *
     * `ModuleManager::bootModules()` / `PluginManager::bootPlugins()` 가
     * 활성화된 확장의 `getIdentityPurposes()` 결과를 순회하며 호출합니다.
     *
     * 같은 key 로 중복 등록되면 나중 호출이 이전 값을 덮어씁니다
     * (확장 로드 순서 결정성 보장은 ExtensionManager 책임).
     *
     * @param  array<string, array<string, mixed>>  $purposes  key => metadata 매핑
     * @param  string|null  $sourceType  'module' | 'plugin' | 'admin' (미명시 시 'admin' 으로 마킹)
     * @param  string|null  $sourceIdentifier  source 식별자 (module/plugin id; 미명시 시 'admin')
     * @return void
     */
    public function registerDeclaredPurposes(array $purposes, ?string $sourceType = null, ?string $sourceIdentifier = null): void
    {
        $resolvedType = $sourceType ?? 'admin';
        $resolvedIdentifier = $sourceIdentifier ?? 'admin';

        foreach ($purposes as $key => $meta) {
            if (! is_string($key) || $key === '' || ! is_array($meta)) {
                continue;
            }

            // legacy `label_key` / `description_key` 명명을 표준 `label` / `description` 으로 정규화.
            // controller 의 resolvePurposeText 는 `label` / `description` 만 인식하므로, 미정규화
            // meta 가 등록되면 응답에서 라벨이 raw 키로 노출되는 회귀 발생.
            if (! isset($meta['label']) && isset($meta['label_key'])) {
                $meta['label'] = $meta['label_key'];
            }
            if (! isset($meta['description']) && isset($meta['description_key'])) {
                $meta['description'] = $meta['description_key'];
            }

            $meta['source_type'] = $resolvedType;
            $meta['source_identifier'] = $resolvedIdentifier;
            $this->declaredPurposes[$key] = $meta;
        }
    }

    /**
     * 확장이 선언한 purpose 를 한 개 등록합니다.
     *
     * @param  string  $key  purpose 식별자
     * @param  array<string, mixed>  $meta  label/description/allowed_channels 등 메타데이터
     * @param  string|null  $sourceType  'module' | 'plugin' | 'admin' (미명시 시 'admin')
     * @param  string|null  $sourceIdentifier  source 식별자 (미명시 시 'admin')
     * @return void
     */
    public function registerPurpose(string $key, array $meta, ?string $sourceType = null, ?string $sourceIdentifier = null): void
    {
        $meta['source_type'] = $sourceType ?? 'admin';
        $meta['source_identifier'] = $sourceIdentifier ?? 'admin';
        $this->declaredPurposes[$key] = $meta;
    }

    /**
     * 등록된 전체 purpose 목록을 반환합니다.
     *
     * 병합 순서: 코어 기본 4종 → 확장 getter 선언분 → `core.identity.purposes` filter 훅.
     * 같은 key 가 충돌하면 나중 소스가 이전을 덮어씁니다 (filter 훅이 최종 결정권).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllPurposes(): array
    {
        $merged = $this->corePurposes;

        foreach ($this->declaredPurposes as $key => $meta) {
            $merged[$key] = $meta;
        }

        $filtered = HookManager::applyFilters('core.identity.purposes', $merged);
        if (! is_array($filtered)) {
            return $merged;
        }

        return $filtered;
    }

    /**
     * 특정 purpose 가 등록되어 있는지 확인합니다 (코어·확장·filter 훅 모두 포함).
     *
     * @param  string  $key  purpose 식별자
     * @return bool 존재 여부
     */
    public function hasPurpose(string $key): bool
    {
        $all = $this->getAllPurposes();

        return isset($all[$key]);
    }
}
