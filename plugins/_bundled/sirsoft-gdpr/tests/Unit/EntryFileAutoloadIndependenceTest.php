<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit;

use Plugins\Sirsoft\Gdpr\Plugin;
use Plugins\Sirsoft\Gdpr\Support\NecessaryAllowlist;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 진입 파일(plugin.php)은 자기 src/ 클래스의 오토로드에 의존하지 않는다.
 *
 * 신규 설치 흐름에서는 plugin.php 가 로드되어 getConfigValues() 가 호출되는 시점에 이 플러그인의
 * PSR-4 매핑이 아직 없다. 진입 파일이 src/ 클래스를 실제로 호출(정적 메서드·new·상수)하면
 * "Class not found" 로 설치가 중단되며, 업그레이드 경로는 기설치본 매핑이 있어 재현되지 않는다.
 * (7.0.10 신규 설치에서 NecessaryAllowlist::locked() 호출로 실제 발생)
 */
class EntryFileAutoloadIndependenceTest extends PluginTestCase
{
    /**
     * plugin.php 가 use 한 자기 네임스페이스 클래스는 ::class 참조로만 쓰인다.
     */
    public function test_entry_file_does_not_invoke_own_src_classes(): void
    {
        $file = dirname(__DIR__, 2).'/plugin.php';
        $src = file_get_contents($file);

        preg_match_all('/^use\s+Plugins\\\\Sirsoft\\\\Gdpr\\\\([A-Za-z\\\\]+?)(?:\s+as\s+([A-Za-z]+))?;/m', $src, $uses, PREG_SET_ORDER);
        $this->assertNotEmpty($uses, 'plugin.php 의 자기 네임스페이스 use 문을 찾지 못했습니다');

        $violations = [];
        foreach (explode("\n", $src) as $i => $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '*') || str_starts_with($t, '/*') || str_starts_with($t, '//') || str_starts_with($t, 'use ')) {
                continue;
            }
            foreach ($uses as $u) {
                $short = ! empty($u[2]) ? $u[2] : substr($u[1], strrpos('\\'.$u[1], '\\'));
                if (preg_match('/(?<![A-Za-z\\\\])'.preg_quote($short, '/').'::(?!class\b)|new\s+'.preg_quote($short, '/').'\b/', $line)) {
                    $violations[] = ($i + 1).': '.$t;
                }
            }
        }

        $this->assertSame([], $violations, "plugin.php 가 src/ 클래스를 직접 호출합니다 (설치 시 오토로드 미등록):\n".implode("\n", $violations));
    }

    /**
     * 잠금 집합은 진입 파일이 소유하고 Support 클래스는 같은 값을 돌려준다.
     */
    public function test_locked_set_is_owned_by_entry_file_and_mirrored_by_support(): void
    {
        $fromEntry = Plugin::lockedNecessaryStorage();

        $this->assertSame($fromEntry, NecessaryAllowlist::locked());
        $this->assertSame($fromEntry, (new Plugin)->getConfigValues()['necessary_storage_locked']);
        $this->assertSame(['auth_token'], $fromEntry['localStorage']);
        $this->assertContains('XSRF-TOKEN', $fromEntry['cookie']);
        $this->assertContains('gdpr_session', $fromEntry['cookie']);
        $this->assertContains((string) config('session.cookie'), $fromEntry['cookie']);
    }
}
