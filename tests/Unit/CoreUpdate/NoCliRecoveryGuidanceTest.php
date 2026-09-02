<?php

namespace Tests\Unit\CoreUpdate;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 무CLI 호스팅에서 읽히는 복구 안내가 SSH 를 무조건으로 지시하지 않는지 잠근다.
 *
 * 코어 업데이트의 실패·복구 화면에 닿는 유일한 경로가 웹 업데이트이고, 그 기능의 존재
 * 이유가 SSH 를 쓸 수 없는 호스팅이다. 그래서 그 자리의 안내가 SSH 만 가리키면 안내가
 * 가리키는 수단이 그 환경에 없다 — 오류는 나지 않고 사이트가 점검 모드에 남는다.
 *
 * 모집단은 손으로 열거하지 않는다. 로케일 파일을 디스크에서 읽어 「실패·복구」 성격의
 * 키를 도출하므로, 같은 성격의 키가 새로 생기면 이 검사가 자동으로 그것까지 본다.
 */
class NoCliRecoveryGuidanceTest extends TestCase
{
    /** 실패·복구 성격의 키 — 운영자가 「무언가 잘못된 뒤」 읽는 자리 */
    private const RECOVERY_KEY_PATTERN = '/(_failed|_not_writable|rollback_manual)$/';

    /** SSH 를 가리키는 표현 */
    private const CLI_PATTERN = '/SSH|php artisan/i';

    /** SSH 가 아닌 수단을 함께 제시했다는 표시 (ko·en·ja 공통 리터럴) */
    private const NON_CLI_PATTERN = '/FTP/i';

    /** 명령줄이 **유일한** 수단이라고 단정하는 표현 */
    private const CLI_EXCLUSIVITY_PATTERN = '/터미널에서만|콘솔에서만|명령어로만|명령줄로만|SSH\s*에서만|ターミナルからのみ|コマンドからのみ|コンソールからのみ|only be (performed|run|executed|done) (from|via|through|in) the (terminal|command line|console|shell)/iu';

    /**
     * 로케일 문구가 사는 **채널**.
     *
     * 앞 판본은 파일 6개를 이름으로 들고 있어, 같은 결함이 그 6개 밖에 있으면 구조적으로
     * 보이지 않았다(실제로 확장·인스톨러·다른 템플릿 채널이 통째로 사각이었다). 여기서
     * 드는 것은 파일이 아니라 **문구가 놓이는 자리의 모양**이고, 그 자리에 파일이 하나
     * 늘면 자동으로 모집단에 들어온다.
     *
     * 채널이 하나 비면 아래 하한 검사가 red 가 된다 — 「전부 통과」와 「그 채널을 읽지
     * 못했다」가 구분되지 않는 상태를 막는다.
     */
    private const LOCALE_FILE_GLOBS = [
        'lang/{ko,en}/*.php',
        'lang/{ko,en}.json',
        'lang/partial/{ko,en}/*.json',
        'public/install/lang/{ko,en}.php',
        'templates/_bundled/*/lang/*.json',
        'templates/_bundled/*/lang/partial/{ko,en}/*.json',
        'modules/_bundled/*/src/lang/{ko,en}/*.php',
        'modules/_bundled/*/resources/lang/*.json',
        'modules/_bundled/*/resources/lang/partial/{ko,en}/*.json',
        'plugins/_bundled/*/lang/{ko,en}/*.php',
        'plugins/_bundled/*/resources/lang/*.json',
        'lang-packs/_bundled/*/backend/*/*.php',
        'lang-packs/_bundled/*/frontend/*.json',
        // 팩마다 partial 깊이가 다르다 — 한 형태만 적으면 나머지 팩이 통째로 사각이 된다.
        // 실제로 두 단계 형태만 적었다가 admin_basic ja 팩(한 단계)을 놓쳤고, 그 사각은
        // 되돌림 red 확인에서 ja 갈래만 발화하지 않는 것으로 드러났다.
        'lang-packs/_bundled/*/frontend/partial/*.json',
        'lang-packs/_bundled/*/frontend/partial/*/*.json',
        'lang-packs/_bundled/*/seed/*.json',
    ];

    /**
     * 명령줄 지시만 담아도 되는 복구 안내와 그 사유.
     *
     * 등재됐는데 실제로는 위반이 아니면 이 검사가 실패한다 — 낡은 면제가 남으면 그
     * 자리에 결함이 다시 들어와도 조용히 통과한다.
     *
     * @var array<string, string>
     */
    private const EXEMPT_RECOVERY_PATHS = [
        // 확장 정적 게시 실패 안내 — 코어 업데이트 복구 흐름 밖이다.
        // 2026-09-02 PO 범위 결정: 이번 라운드는 코어 업데이트 화면 두 곳만 고친다.
        'alerts.static_publish_failed_parent_not_writable' => '확장 정적 게시 안내 — 코어 업데이트 복구 흐름 밖 (PO 범위 결정 2026-09-02)',
        // 인스톨러 **로그** 문구다(화면 안내가 아니다). 이미 비-CLI 대안을 앞에 병기한다 —
        // 「이 파일을 직접 지우거나 …」. 리터럴 FTP 가 없을 뿐이다.
        'log_finalize_env_config_cache_delete_failed' => '인스톨러 로그 문구 — 파일 직접 삭제라는 비-CLI 대안을 이미 병기한다',
    ];

    /**
     * 검사 대상 로케일 파일을 디스크에서 도출합니다.
     *
     * @return array<string, array<string, mixed>> 라벨 => 중첩 배열
     */
    private function sources(): array
    {
        $out = [];

        foreach (self::LOCALE_FILE_GLOBS as $glob) {
            foreach (glob(base_path($glob), GLOB_BRACE) ?: [] as $path) {
                $data = str_ends_with($path, '.php')
                    ? require $path
                    : json_decode((string) file_get_contents($path), true);

                if (is_array($data)) {
                    $label = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
                    $out[$label] = $data;
                }
            }
        }

        return $out;
    }

    /**
     * 채널마다 파일이 실제로 잡히는지 잰다 — 하나가 0이면 그 채널이 통째로 사각이다.
     */
    #[Test]
    public function test_every_locale_channel_yields_files(): void
    {
        $empty = [];

        foreach (self::LOCALE_FILE_GLOBS as $glob) {
            if ((glob(base_path($glob), GLOB_BRACE) ?: []) === []) {
                $empty[] = $glob;
            }
        }

        $this->assertSame([], $empty, '로케일 채널이 파일을 하나도 잡지 못한다: '.implode(', ', $empty));

        $this->assertGreaterThanOrEqual(
            400,
            count($this->sources()),
            '로케일 파일 모집단이 알려진 규모보다 크게 적다 — 파생이 무너졌는지 확인할 것'
        );
    }

    /**
     * 명령줄이 **유일한** 수단이라고 단정하지 않는다.
     *
     * 이 안이 웹 코어 업데이트를 만든 이상 그 단정은 사실이 아니며, 되돌리기 직후
     * 열리는 안내 모달이 정확히 그렇게 적혀 있었다 — 웹 업데이트가 실패한 운영자가
     * 그 옆 버튼을 눌러 「터미널에서만 실행할 수 있습니다」를 읽었다.
     */
    #[Test]
    public function test_no_message_claims_the_command_line_is_the_only_way(): void
    {
        $violations = [];

        foreach ($this->sources() as $label => $data) {
            foreach ($this->flatten($data) as $path => $text) {
                if (preg_match(self::CLI_EXCLUSIVITY_PATTERN, $text)) {
                    $violations[] = "{$label} :: {$path}\n    {$text}";
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            '명령줄이 유일한 수단이라고 단정하는 문구가 있다 — 웹 업데이트가 존재하므로 사실이 아니고, '
                ."그 화면에 닿는 운영자는 SSH 를 쓸 수 없는 사람이다:\n".implode("\n", $violations)
        );
    }

    /**
     * 낡은 면제를 남기지 않는다.
     */
    #[Test]
    public function test_every_recovery_exemption_is_still_needed(): void
    {
        $stillViolating = [];

        foreach ($this->sources() as $data) {
            foreach ($this->flatten($data) as $path => $text) {
                $leaf = substr($path, (int) strrpos($path, '.') + 1);

                if (! preg_match(self::RECOVERY_KEY_PATTERN, $leaf)) {
                    continue;
                }
                if (! preg_match(self::CLI_PATTERN, $text) || preg_match(self::NON_CLI_PATTERN, $text)) {
                    continue;
                }

                $stillViolating[$path] = true;
            }
        }

        foreach (self::EXEMPT_RECOVERY_PATHS as $path => $reason) {
            $this->assertNotSame('', trim($reason), "면제에 사유가 없다: {$path}");
            $this->assertArrayHasKey(
                $path,
                $stillViolating,
                "면제가 낡았다: {$path} — 더 이상 위반이 아니다. 표에서 지워야 그 자리에 결함이 "
                    .'다시 들어올 때 red 가 된다'
            );
        }
    }

    /**
     * 중첩 배열을 점 표기 경로의 문자열 목록으로 폅니다.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $flat += $this->flatten($value, $path);
            } elseif (is_string($value)) {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    #[Test]
    public function test_recovery_guidance_never_points_only_at_ssh(): void
    {
        $population = [];
        $violations = [];

        foreach ($this->sources() as $label => $data) {
            foreach ($this->flatten($data) as $path => $text) {
                $leaf = substr($path, (int) strrpos($path, '.') + 1);

                if (! preg_match(self::RECOVERY_KEY_PATTERN, $leaf)) {
                    continue;
                }
                if (! preg_match(self::CLI_PATTERN, $text)) {
                    continue;
                }

                $population[] = "{$label} :: {$path}";

                if (array_key_exists($path, self::EXEMPT_RECOVERY_PATHS)) {
                    continue;
                }

                if (! preg_match(self::NON_CLI_PATTERN, $text)) {
                    $violations[] = "{$label} :: {$path}\n    {$text}";
                }
            }
        }

        $this->assertNotEmpty(
            $population,
            'SSH 를 언급하는 복구 안내를 하나도 찾지 못했다 — 모집단이 비면 이 검사는 아무것도 재지 않는다.'
        );

        $this->assertGreaterThanOrEqual(
            9,
            count($population),
            '복구 안내 모집단이 알려진 하한(3키 × ko·en·ja)보다 적다 — 로케일 파일이나 키 경로가 사라졌는지 확인할 것. 실제: '
                .implode(', ', $population)
        );

        $this->assertSame(
            [],
            $violations,
            "무CLI 호스팅에서 실행할 수 없는 복구 안내가 있다 (SSH 만 가리키고 FTP 대안이 없음):\n"
                .implode("\n", $violations)
        );
    }
}
