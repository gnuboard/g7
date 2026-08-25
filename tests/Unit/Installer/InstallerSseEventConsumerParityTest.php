<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 서버가 보내는 SSE 이벤트 ↔ 화면이 소비하는 이벤트 정합성 (12차 #B27 · #B35).
 *
 * 「살아 있음」의 증거를 세는 목록이 서버 이벤트 이름 전수와 갈라지면, 그 이벤트만 오는 구간이
 * 통째로 침묵으로 세어진다. 번들 추출 구간(`installVendorBundleSSE`)은 `heartbeat` 말고는
 * 아무것도 내보내지 않으므로, 목록에서 그 이름이 빠져 있는 동안 추출이 임계(기본 90초)를
 * 넘기면 **정상 설치가 정지 화면으로 뒤집혔다**. `EventSource` 는 이름 있는 이벤트를 `message`
 * 리스너로 보내지 않아 캡처 단계도 이를 대신하지 못한다.
 *
 * 행위 자체는 vitest 가 잠근다(`installation-monitor-heartbeat.test.ts`). 여기서 잠그는 것은
 * 그 vitest 가 볼 수 없는 축이다 — **서버가 새 이벤트 이름을 추가했을 때** 화면 목록이 따라
 * 오지 않는 드리프트. 목록 방식 자체가 재발원이므로 두 파일을 직접 대조한다.
 *
 * 커버 축: event_direction server_emits·client_listens / event_kind progress·terminal
 *
 * @effects installer_liveness_event_list_covers_every_server_event
 */
class InstallerSseEventConsumerParityTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /**
     * 침묵 감시 목록에서 의도적으로 빼는 이름과 그 근거.
     *
     * 목록이 아니라 **판단**이므로 여기에 적는다. 서버에 새 종료 이벤트가 생기면 이 검사가
     * red 가 되고, 그때 「종료라서 뺀다」와 「빠뜨렸다」를 사람이 한 번 가른다.
     *
     * @var array<string, string>
     */
    private const LIVENESS_EXCLUSIONS = [
        'completed' => '도착 즉시 stop() 한다 — 다음 침묵이 없다',
        'aborted' => '같음 (종료 이벤트)',
        'error' => 'EventSource 가 전송 오류에도 같은 이름을 쓴다 — 넣으면 연결이 끊길 때마다 침묵 시계가 되감긴다',
    ];

    private function installRoot(): string
    {
        return dirname(__DIR__, 3).'/public/install';
    }

    /**
     * 서버가 내보내는 SSE 이벤트 이름 전수.
     *
     * @return array<int, string>
     */
    private function serverEventNames(): array
    {
        $sources = [
            $this->installRoot().'/includes/task-runner.php',
            $this->installRoot().'/includes/progress-emitter.php',
        ];

        $names = [];
        foreach ($sources as $file) {
            // 주석을 지운 뒤 대조한다 — 주석 처리된 emit 호출도 원문에는 이름이 남는다.
            $code = $this->stripComments((string) file_get_contents($file));
            preg_match_all("/(?:sendSSEEvent|->emit)\(\s*'([a-z_]+)'/", $code, $m);
            $names = array_merge($names, $m[1]);
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * 화면이 침묵 감시 대상으로 세는 이벤트 이름.
     *
     * @return array<int, string>
     */
    private function livenessEventNames(): array
    {
        $code = $this->stripJsComments((string) file_get_contents($this->installRoot().'/assets/js/installation-monitor.js'));

        $matched = preg_match('/const SSE_LIVENESS_EVENTS\s*=\s*\[(.*?)\];/s', $code, $m);
        $this->assertSame(
            1,
            $matched,
            '침묵 감시 목록(SSE_LIVENESS_EVENTS)이 이름 있는 상수가 아니다 — 리스너 등록부에 인라인으로 되돌리면 이 검사가 사각이 된다',
        );

        preg_match_all("/'([a-z_]+)'/", $m[1], $names);
        sort($names[1]);

        return $names[1];
    }

    public function test_침묵_감시_목록이_서버가_보내는_이벤트_전수를_덮는다(): void
    {
        $server = $this->serverEventNames();
        $this->assertNotEmpty($server, '서버 이벤트 이름을 하나도 읽지 못했다 — 이 검사가 통째로 사각이 된다');

        $expected = array_values(array_diff($server, array_keys(self::LIVENESS_EXCLUSIONS)));
        sort($expected);

        $missing = array_values(array_diff($expected, $this->livenessEventNames()));

        $this->assertSame(
            [],
            $missing,
            '서버가 보내는데 「살아 있음」으로 세지 않는 이벤트: '.implode(', ', $missing)
            ."\n그 이벤트만 오는 구간(예: 번들 추출은 heartbeat 만 보낸다)이 통째로 침묵으로 세어져,"
            ."\n서버가 정상 동작 중인데도 정지 화면이 뜬다.",
        );
    }

    public function test_감시_목록에_서버가_보내지_않는_이름이_없다(): void
    {
        $unknown = array_values(array_diff($this->livenessEventNames(), $this->serverEventNames()));

        $this->assertSame(
            [],
            $unknown,
            '서버가 보내지 않는 이름이 감시 목록에 있다: '.implode(', ', $unknown)
            ."\n죽은 항목은 목록이 실제 계약과 갈라져 있다는 신호다.",
        );
    }

    /**
     * emitter 를 거치지 않고 **직접 써 내려간** 프레임도 소비자가 있어야 한다 (13차 E5).
     *
     * `worker_superseded` 는 emitter 가 물러나기 직전에 `echo "event: ..."` 로 직접 내보낸다 —
     * 위 두 검사의 모집단(`sendSSEEvent`/`->emit`)에 잡히지 않는 형태다. 이름만 바뀌어도
     * 화면은 조용히 그 프레임을 못 알아듣고, EventSource 가 재연결 → busy → 「중단」 화면을
     * 그린다. 승자 요청은 멀쩡히 도는 중이므로 오류도 로그도 남지 않는다.
     */
    public function test_직접_써_내려간_프레임에도_리스너가_있다(): void
    {
        $sources = [
            $this->installRoot().'/includes/progress-emitter.php',
            $this->installRoot().'/includes/task-runner.php',
        ];

        $names = [];
        foreach ($sources as $file) {
            $code = $this->stripComments((string) file_get_contents($file));
            preg_match_all('/echo\s+["\']event:\s*([a-z_]+)/', $code, $m);
            $names = array_merge($names, $m[1]);
        }

        $names = array_values(array_unique($names));
        $this->assertNotEmpty($names, '직접 써 내려간 프레임을 하나도 읽지 못했다 — 이 검사가 통째로 사각이 된다');

        $client = $this->stripJsComments(
            (string) file_get_contents($this->installRoot().'/assets/js/installation-monitor.js')
        );

        foreach ($names as $name) {
            $this->assertStringContainsString(
                "addEventListener('".$name."'",
                $client,
                '서버가 보내는 '.$name." 프레임을 화면이 듣지 않는다\n"
                .'— EventSource 는 모르는 이름을 무시하고 스트림 종료를 장애로 읽어 재연결하는데,'
                ."\n  그 재연결은 승자 요청이 쥔 lock 에 막혀 「중단」 화면이 된다.",
            );
        }
    }

    public function test_진행값을_실은_heartbeat_는_소비된다(): void
    {
        // 서버가 `done`/`total` 을 싣는 지점이 실재하는지 먼저 확인한다 — 서버가 그만두면
        // 화면 소비 코드가 죽은 코드가 되므로 그 사실을 여기서 드러낸다.
        $runner = $this->stripComments((string) file_get_contents($this->installRoot().'/includes/task-runner.php'));
        $this->assertMatchesRegularExpression(
            "/'heartbeat',\s*\[\s*'done'\s*=>/",
            $runner,
            '서버가 heartbeat 에 진행(done/total)을 싣지 않는다 — 화면의 소비 코드가 죽은 코드가 된다',
        );

        $monitor = $this->stripJsComments((string) file_get_contents($this->installRoot().'/assets/js/installation-monitor.js'));
        $this->assertStringContainsString(
            'onHeartbeat',
            $monitor,
            'heartbeat 의 진행 페이로드에 소비처가 없다 — 최장 단계가 화면에서 정지한 스피너로 남는다 (#B35)',
        );

        $installer = $this->stripJsComments((string) file_get_contents($this->installRoot().'/assets/js/installer.js'));
        $this->assertStringContainsString(
            'onHeartbeat:',
            $installer,
            '모니터가 onHeartbeat 를 부르는데 화면이 그 콜백을 배선하지 않았다',
        );
    }
}
