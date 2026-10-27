<?php

namespace Plugins\Sirsoft\Sso\Tests\Unit\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Symfony\Component\Finder\Finder;

/**
 * 회원 이메일 조회 규율(§7 G-1) — 플러그인은 코어 정확 일치 조회(`UserRepositoryInterface::findByEmail`)를 쓰지 않고 대소문자를 무시하는
 * `SsoAccountRepositoryInterface::findUserByEmail()` 로만 찾는다. 운영 DB 가 `DB_COLLATION` 으로 `_bin` 을 써도 `Hong@Corp.com` 이 다른
 * 회원으로 갈리지 않게 한다.
 *
 * @since 1.0.0
 */
class EmailLookupCaseTest extends PluginTestCase
{
    /**
     * 플러그인 src 에 코어 정확 일치 `->findByEmail(` 호출이 없고, 대소문자 무시 조회 호출은 있다(모집단이 비면 red).
     *
     * @scenario scan=src
     *
     * @effects exact_email_lookup_zero, case_insensitive_lookup_present
     *
     * @return void 반환값 없음
     */
    public function test_plugin_never_uses_exact_email_lookup(): void
    {
        $exact = [];
        $insensitive = 0;
        $scanned = 0;

        foreach ((new Finder)->files()->in(dirname(__DIR__, 3).'/src')->name('*.php') as $file) {
            $scanned++;
            foreach (preg_split('/\R/', $file->getContents()) ?: [] as $no => $line) {
                if (preg_match('/->findByEmail\(/', $line) === 1) {
                    $exact[] = $file->getRelativePathname().':'.($no + 1);
                }
                $insensitive += preg_match_all('/->findUserByEmail\(/', $line);
            }
        }

        $this->assertGreaterThan(0, $scanned);
        $this->assertGreaterThan(0, $insensitive, '대소문자 무시 회원 조회 호출이 하나도 없다');
        $this->assertSame([], $exact);
    }

    /**
     * 저장소 조회는 대소문자·앞뒤 공백을 무시하고 `LOWER(email) = ?` 로 묻는다.
     *
     * @scenario email=upper_with_spaces
     *
     * @effects member_found, lower_email_query
     *
     * @return void 반환값 없음
     */
    public function test_repository_lookup_is_case_insensitive(): void
    {
        $user = User::factory()->create(['email' => 'case'.random_int(1000, 9999).'@corp798.test']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $found = app(SsoAccountRepositoryInterface::class)->findUserByEmail('  '.mb_strtoupper($user->email).' ');
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertSame($user->id, $found?->id);
        $this->assertStringContainsStringIgnoringCase('lower(email) = ?', implode("\n", $queries));
    }
}
