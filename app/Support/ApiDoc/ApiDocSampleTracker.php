<?php

namespace App\Support\ApiDoc;

use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * API 문서 실측 샘플 생성분 기록기
 *
 * docgen 이 상세 GET 실측을 위해 시드하는 동안 만들어진 Eloquent 행을 만든 순서대로 기록했다가,
 * 실측이 끝나면 만든 역순으로 지운다. 샘플 서비스가 직접 만든 행뿐 아니라 시드 중 모델 이벤트·팩토리가
 * 함께 만든 행, 확장 시더가 만든 행도 같은 기록에 들어간다.
 *
 * 실측은 개발 사이트로 실제 HTTP 요청을 보내므로(다른 PHP 프로세스) 시드를 트랜잭션으로 감싸 되돌리는
 * 방식은 쓸 수 없다 — 커밋 전 샘플은 사이트에서 보이지 않는다.
 */
class ApiDocSampleTracker
{
    /**
     * @var array<int, Model> 기록 중 만들어진 모델 (만든 순서)
     */
    private array $created = [];

    /**
     * @var bool 지금 기록 중인지
     */
    private bool $recording = false;

    /**
     * @var bool 생성 이벤트 감시를 등록했는지 (프로세스당 한 번)
     */
    private bool $listening = false;

    /**
     * 콜백을 실행하는 동안 만들어진 Eloquent 행을 기록합니다.
     *
     * @param  callable  $seed  시드 콜백
     * @return mixed 콜백 반환값
     */
    public function track(callable $seed): mixed
    {
        if (! $this->listening) {
            Event::listen('eloquent.created: *', function (string $event, array $payload): void {
                $model = $payload[0] ?? null;

                if ($this->recording && $model instanceof Model) {
                    $this->created[] = $model;
                }
            });
            $this->listening = true;
        }

        $this->recording = true;

        try {
            return $seed();
        } finally {
            $this->recording = false;
        }
    }

    /**
     * 기록한 행을 만든 역순으로 지웁니다.
     *
     * 모델 이벤트를 거치지 않는 직접 삭제다 — 샘플 사용자·역할 삭제가 관찰자를 통해 활동 로그나 알림을
     * 새로 만들지 않게 한다. 코어 다대다 연결(역할-권한, 사용자-역할, 역할-메뉴)은 지우기 전에 명시적으로 끊고,
     * 그 밖의 종속 행은 기록에 함께 있거나 DB 외래키가 정리한다.
     *
     * @return int 지운 행 수
     */
    public function purge(): int
    {
        $created = array_reverse($this->created);
        $this->created = [];

        return (int) DB::transaction(function () use ($created): int {
            $removed = 0;

            foreach ($created as $model) {
                $removed += $this->delete($model);
            }

            return $removed;
        });
    }

    /**
     * 모델 한 행의 코어 다대다 연결을 끊고 직접 지웁니다.
     *
     * @param  Model  $model  지울 모델
     * @return int 지운 행 수 (이미 없으면 0)
     */
    public function delete(Model $model): int
    {
        if ($model->getKey() === null) {
            return 0;
        }

        match (true) {
            $model instanceof Role => $this->detach($model, ['permissions', 'users', 'menus']),
            $model instanceof User => $this->detach($model, ['roles']),
            $model instanceof Permission => $this->detach($model, ['roles']),
            $model instanceof Menu => $this->detach($model, ['roles']),
            default => null,
        };

        return DB::connection($model->getConnectionName())
            ->table($model->getTable())
            ->where($model->getKeyName(), $model->getKey())
            ->delete();
    }

    /**
     * 모델의 다대다 연결을 끊습니다.
     *
     * @param  Model  $model  모델
     * @param  array<int, string>  $relations  관계 이름
     * @return void 반환값 없음
     */
    private function detach(Model $model, array $relations): void
    {
        foreach ($relations as $relation) {
            $model->{$relation}()->detach();
        }
    }
}
