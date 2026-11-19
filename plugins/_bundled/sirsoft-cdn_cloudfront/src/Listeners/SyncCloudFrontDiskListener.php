<?php

namespace Plugins\Sirsoft\CdnCloudfront\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\CdnCloudfront\Support\CloudFrontDisk;
use Plugins\Sirsoft\CdnCloudfront\Support\CloudFrontSettings;

/**
 * 플러그인 설정·활성 상태가 바뀌면 디스크 정의와 큐 워커를 맞춘다.
 *
 * 디스크는 프로세스 부팅 때 한 번 정의되고, 코어의 작업별 설정 다시 읽기는 설정값만 고칠 뿐 디스크 정의를 다시 만들지
 * 않는다. 그래서 상주 큐 워커는 재시작 전까지 옛 CDN 주소로 URL 을 만들고, 그 URL 은 새 CDN 호스트가 아니라서 무효화
 * 대상에서 조용히 빠진다. 끈 뒤에도 워커는 CDN 주소를 계속 낸다 — 코어의 재시작 신호는 설치·활성화·업데이트·제거에만
 * 나가므로 끄는 사건은 이 리스너가 맡는다. 드라이버 탭(S3 설정) 저장은 코어가 재시작을 보낸다.
 *
 * 제거는 따로 구독하지 않는다 — 코어가 켜진 플러그인을 제거하기 전에 비활성화(훅 포함)를 거치므로 그때 `afterDeactivate`
 * 가 디스크 정의를 한 번 지우고, 제거가 끝나면 코어가 큐 워커에 재시작 신호를 보낸다. 이미 꺼진 플러그인의 제거는 끌 때
 * 정리가 끝나 있다. 제거 완료 훅 때는 플러그인 디렉터리가 이미 지워져 이 클래스를 다시 읽을 수 없다는 점도 이 배치의 근거다.
 *
 * 동기로 돈다 — 큐로 보내면 재시작 신호를 그 워커가 처리하게 되고, CLI 는 끝나 버려 처리할 주체가 없다.
 */
class SyncCloudFrontDiskListener implements HookListenerInterface
{
    /**
     * 디스크 정의를 주입받습니다.
     *
     * @param  CloudFrontDisk  $disk  디스크 정의
     */
    public function __construct(private readonly CloudFrontDisk $disk) {}

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.plugin_settings.after_save' => ['method' => 'afterSave', 'priority' => 10, 'type' => 'action', 'sync' => true],
            'core.plugin_settings.after_reset' => ['method' => 'afterReset', 'priority' => 10, 'type' => 'action', 'sync' => true],
            'core.plugins.after_deactivate' => ['method' => 'afterDeactivate', 'priority' => 10, 'type' => 'action', 'sync' => true],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 이 플러그인 설정을 저장한 뒤 디스크를 다시 정의합니다.
     *
     * @param  string  $identifier  저장된 플러그인 식별자
     * @param  array<string, mixed>  $settings  저장된 설정 (쓰지 않는다)
     * @param  bool  $result  저장 성공 여부
     * @return void 반환값 없음
     */
    public function afterSave(string $identifier, array $settings = [], bool $result = true): void
    {
        if ($identifier === CloudFrontSettings::IDENTIFIER && $result) {
            $this->refresh();
        }
    }

    /**
     * 이 플러그인 설정을 초기화한 뒤 디스크를 다시 정의합니다.
     *
     * @param  string  $identifier  초기화된 플러그인 식별자
     * @return void 반환값 없음
     */
    public function afterReset(string $identifier): void
    {
        if ($identifier === CloudFrontSettings::IDENTIFIER) {
            $this->refresh();
        }
    }

    /**
     * 이 플러그인을 끈 뒤 디스크 정의를 지웁니다 — 켜진 채 제거할 때도 코어가 제거 전에 끄므로 이 경로로 한 번 정리된다.
     *
     * @param  string  $identifier  끈 플러그인 식별자
     * @return void 반환값 없음
     */
    public function afterDeactivate(string $identifier): void
    {
        if ($identifier !== CloudFrontSettings::IDENTIFIER) {
            return;
        }

        $this->disk->unregister();
        $this->restartWorkers();
    }

    /**
     * 이 프로세스의 디스크를 다시 정의하고 워커에 재시작 신호를 보냅니다.
     *
     * @return void 반환값 없음
     */
    private function refresh(): void
    {
        $this->disk->register();
        $this->restartWorkers();
    }

    /**
     * 큐 워커에 재시작 신호를 보냅니다 (코어 설정 저장과 같은 방식).
     *
     * 신호 전송 실패가 설정 저장·비활성화를 되돌리지는 않는다.
     *
     * @return void 반환값 없음
     */
    private function restartWorkers(): void
    {
        try {
            Artisan::call('queue:restart');
        } catch (\Throwable $e) {
            Log::warning('CloudFront 디스크 변경 후 queue:restart 실행 실패', ['error' => $e->getMessage()]);
        }
    }
}
