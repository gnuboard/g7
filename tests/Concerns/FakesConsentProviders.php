<?php

namespace Tests\Concerns;

use App\Contracts\Extension\ConsentProviderInterface;
use App\Extension\Consent\ConsentManager;
use App\Extension\Consent\ConsentSnapshot;
use App\Extension\Consent\ConsentSubject;
use App\Extension\HookManager;
use Illuminate\Http\Request;

/**
 * 테스트에서 동의 제공자를 흉내 냅니다.
 *
 * 코어는 어느 확장이 동의를 관리하는지 모르므로, 코어 테스트는 가짜 제공자를 필터 훅
 * `core.consent.registered_providers` 로 등록해 「동의 관리 확장이 켜진 사이트」를 만든다.
 * 등록·선택값은 테스트마다 비운다 — 다른 테스트의 제공자가 남으면 선택 상태가 갈린다.
 */
trait FakesConsentProviders
{
    /**
     * 등록한 제공자와 선택값을 비웁니다. setUp 에서 부릅니다.
     *
     * @return void 반환값 없음
     */
    protected function resetConsentProviders(): void
    {
        HookManager::clearFilter(ConsentManager::REGISTERED_PROVIDERS_FILTER);
        config(['g7_settings.core.'.ConsentManager::SETTING_KEY => '']);
    }

    /**
     * 가짜 동의 제공자를 등록합니다.
     *
     * `answers` 는 주체를 받아 카테고리 맵(또는 예외를 던지는 클로저)을 돌려줍니다. 기본은 모두 거부입니다.
     *
     * @param  string  $id  제공자 id
     * @param  (callable(ConsentSubject): array<string, bool>)|null  $answers  주체별 카테고리 답
     * @param  bool  $available  수집 중인지
     * @param  callable(Request): ?string|null  $subjectKey  게스트 식별자 해석
     * @param  bool  $needsRenewal  재동의 필요 여부
     * @return ConsentProviderInterface 등록한 제공자
     */
    protected function fakeConsentProvider(
        string $id = 'fake-consent',
        ?callable $answers = null,
        bool $available = true,
        ?callable $subjectKey = null,
        bool $needsRenewal = false,
    ): ConsentProviderInterface {
        $provider = new class($id, $answers, $available, $subjectKey, $needsRenewal) implements ConsentProviderInterface
        {
            /** @var callable|null */
            private $answers;

            /** @var callable|null */
            private $subjectKey;

            /**
             * 가짜 제공자를 생성합니다.
             *
             * @param  string  $id  제공자 id
             * @param  callable|null  $answers  주체별 답
             * @param  bool  $available  수집 여부
             * @param  callable|null  $subjectKey  게스트 식별자 해석
             * @param  bool  $needsRenewal  재동의 필요 여부
             */
            public function __construct(
                private readonly string $id,
                ?callable $answers,
                private readonly bool $available,
                ?callable $subjectKey,
                private readonly bool $needsRenewal,
            ) {
                $this->answers = $answers;
                $this->subjectKey = $subjectKey;
            }

            /**
             * 제공자 id 를 반환합니다.
             *
             * @return string 제공자 id
             */
            public function getId(): string
            {
                return $this->id;
            }

            /**
             * 표시 이름을 반환합니다.
             *
             * @return string 표시 이름
             */
            public function getLabel(): string
            {
                return 'Fake '.$this->id;
            }

            /**
             * 수집 여부를 반환합니다.
             *
             * @return bool 수집 여부
             */
            public function isAvailable(): bool
            {
                return $this->available;
            }

            /**
             * 카테고리 목록을 반환합니다.
             *
             * @return array<int, array{key: string, label: string, required: bool}> 카테고리 목록
             */
            public function getCategories(): array
            {
                return [
                    ['key' => 'necessary', 'label' => 'Necessary', 'required' => true],
                    ['key' => 'functional', 'label' => 'Functional', 'required' => false],
                    ['key' => 'analytics', 'label' => 'Analytics', 'required' => false],
                    ['key' => 'marketing', 'label' => 'Marketing', 'required' => false],
                ];
            }

            /**
             * 게스트 식별자를 해석합니다.
             *
             * @param  Request  $request  HTTP 요청
             * @return string|null 게스트 식별자
             */
            public function resolveSubjectKey(Request $request): ?string
            {
                return $this->subjectKey !== null ? ($this->subjectKey)($request) : null;
            }

            /**
             * 주체의 스냅샷을 반환합니다.
             *
             * @param  ConsentSubject  $subject  동의 주체
             * @return ConsentSnapshot 스냅샷
             */
            public function getSnapshot(ConsentSubject $subject): ConsentSnapshot
            {
                $categories = $this->answers !== null ? ($this->answers)($subject) : [];

                return ConsentSnapshot::ready($categories, $this->needsRenewal, '1.0');
            }
        };

        HookManager::addFilter(ConsentManager::REGISTERED_PROVIDERS_FILTER, function (array $providers) use ($provider): array {
            $providers[] = $provider;

            return $providers;
        });

        return $provider;
    }
}
