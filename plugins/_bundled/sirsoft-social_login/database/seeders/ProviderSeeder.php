<?php

namespace Plugins\Sirsoft\SocialLogin\Database\Seeders;

use App\Concerns\Seeder\HasTranslatableSeeder;
use App\Contracts\Seeder\TranslatableSeederInterface;
use Illuminate\Database\Seeder;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\ProviderRepositoryInterface;
use Plugins\Sirsoft\SocialLogin\Repositories\ProviderRepository;

/**
 * 내장 제공자 4행(카카오·네이버·구글·페이스북) 시더.
 *
 * 설치·업데이트 때마다 돌아도 안전하다 — 없는 행만 만들고, 있는 행은 비어 있는 기본값만 채운다. 운영자가 넣은 자격증명,
 * 순서, 켜짐 여부, 표시 이름은 건드리지 않는다. 처음에는 모두 꺼진 상태로 만든다(자격증명을 넣고 테스트한 뒤 켜게).
 * 표시 이름은 ko/en/ja 를 기본으로 두고, 활성 언어팩의 `seed/providers.json` 이 있으면 trait 이 머지한다.
 *
 * @since 1.0.0
 */
class ProviderSeeder extends Seeder implements TranslatableSeederInterface
{
    use HasTranslatableSeeder;

    /**
     * 확장 식별자를 반환합니다.
     *
     * @return string 플러그인 식별자
     */
    public function getExtensionIdentifier(): string
    {
        return 'sirsoft-social_login';
    }

    /**
     * 언어팩 seed 파일의 엔티티 이름을 반환합니다.
     *
     * @return string 엔티티 이름 (seed/providers.json)
     */
    public function getTranslatableEntity(): string
    {
        return 'providers';
    }

    /**
     * 언어팩 항목과 기본값을 잇는 키를 반환합니다.
     *
     * @return string 매칭 키
     */
    public function getMatchKey(): string
    {
        return 'key';
    }

    /**
     * 내장 제공자 기본값을 반환합니다.
     *
     * @return array<int, array<string, mixed>> 기본값 목록
     */
    public function getDefaults(): array
    {
        return [
            ['key' => 'kakao', 'label' => ['ko' => '카카오', 'en' => 'Kakao', 'ja' => 'カカオ'], 'sort_order' => 10],
            ['key' => 'naver', 'label' => ['ko' => '네이버', 'en' => 'Naver', 'ja' => 'ネイバー'], 'sort_order' => 20],
            ['key' => 'google', 'label' => ['ko' => '구글', 'en' => 'Google', 'ja' => 'Google'], 'sort_order' => 30],
            ['key' => 'facebook', 'label' => ['ko' => '페이스북', 'en' => 'Facebook', 'ja' => 'Facebook'], 'sort_order' => 40, 'graph_api_version' => 'v26.0'],
        ];
    }

    /**
     * 시더를 실행합니다.
     *
     * @return void
     */
    public function run(): void
    {
        $repository = app()->bound(ProviderRepositoryInterface::class)
            ? app(ProviderRepositoryInterface::class)
            : new ProviderRepository;

        foreach ($this->resolveTranslatedDefaults() as $provider) {
            $key = $provider['key'];
            unset($provider['key']);

            $repository->seedIfMissing($key, $provider + [
                'driver' => $key,
                'type' => 'builtin',
                'enabled' => false,
                'signup_allowed' => true,
                'email_missing_policy' => 'ask',
                'email_trust' => 'flag',
            ]);
        }
    }
}
