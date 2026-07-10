<?php

namespace Tests\Feature\Console;

use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Models\LanguagePack;
use App\Models\User;
use App\Services\LanguagePack\LanguagePackRegistry;
use App\Support\ApiDoc\ApiDocSampleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * API 문서 실측용 완전 샘플 시더 Feature 테스트.
 *
 * 도메인별 완전 샘플이 생성되고, 재실행 시 중복 없이 멱등하며,
 * 완전 사용자 샘플의 프로필 필드가 채워지는지 검증한다.
 *
 * 문서용 샘플은 조회 예시를 만들기 위한 것이지 시스템 동작을 바꿀 자격이 없다.
 * 샘플 언어팩이 전역 지원 로케일 목록을 넓히지 않는지 함께 검증한다.
 */
class ApiDocSampleServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 도메인별_대표_샘플_맵을_반환한다(): void
    {
        $map = (new ApiDocSampleService)->seed();

        $this->assertArrayHasKey('users', $map);
        $this->assertArrayHasKey('roles', $map);
        $this->assertArrayHasKey('menus', $map);
        $this->assertArrayHasKey('schedules', $map);

        // 각 항목은 model/key/value 구조
        $this->assertSame(User::class, $map['users']['model']);
        $this->assertArrayHasKey('key', $map['users']);
        $this->assertNotEmpty($map['users']['value']);
    }

    #[Test]
    public function 완전_사용자_샘플은_프로필_필드가_채워진다(): void
    {
        (new ApiDocSampleService)->seed();

        $user = User::where('email', 'apidoc-sample-user@example.com')->first();

        $this->assertNotNull($user);
        $this->assertNotNull($user->nickname);
        $this->assertNotNull($user->mobile);
        $this->assertSame('KR', $user->country);
        $this->assertSame('ko', $user->language);
        $this->assertNotNull($user->bio);
        $this->assertGreaterThan(0, $user->roles()->count());
    }

    #[Test]
    public function 재실행_시_샘플이_중복_생성되지_않는다(): void
    {
        $service = new ApiDocSampleService;

        $service->seed();
        $countAfterFirst = User::where('email', 'apidoc-sample-user@example.com')->count();

        $service->seed();
        $countAfterSecond = User::where('email', 'apidoc-sample-user@example.com')->count();

        $this->assertSame(1, $countAfterFirst);
        $this->assertSame(1, $countAfterSecond);
    }

    #[Test]
    public function 샘플_언어팩은_활성_코어_로케일_목록을_넓히지_않는다(): void
    {
        // 활성(active) 상태의 코어(core) 언어팩은 그 locale 이 전역 지원 로케일로 승격된다.
        // 문서용 샘플이 이 경로를 타면 시스템 전체의 번역 가능 로케일이 바뀌고,
        // 그 상태에서 시드된 다국어 데이터에 존재하지 않는 로케일 키가 박힌다.
        $before = app(LanguagePackRegistry::class)->getActiveCoreLocales();

        (new ApiDocSampleService)->seed();

        // 레지스트리는 요청 스코프 캐시를 들고 있으므로 새 인스턴스로 재조회
        $after = (new LanguagePackRegistry(app(LanguagePackRepositoryInterface::class)))
            ->getActiveCoreLocales();

        sort($before);
        sort($after);

        $this->assertSame($before, $after, '샘플 시드가 전역 활성 코어 로케일 목록을 변경했다');
    }

    #[Test]
    public function 샘플_언어팩은_전역_활성_코어_팩으로_생성되지_않는다(): void
    {
        (new ApiDocSampleService)->seed();

        $pack = LanguagePack::where('identifier', 'apidoc-sample-lang')->first();
        $this->assertNotNull($pack, '언어팩 도메인의 문서 샘플은 존재해야 한다');

        // scope=core + status=active 조합만이 전역 로케일 승격 경로다.
        $this->assertFalse(
            $pack->scope === 'core' && $pack->status === 'active',
            '문서 샘플이 전역 활성 코어 언어팩으로 생성되었다 — 시스템 지원 로케일이 오염된다'
        );
    }
}
