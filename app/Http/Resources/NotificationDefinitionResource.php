<?php

namespace App\Http\Resources;

use App\Models\IdentityPolicy;
use App\Models\NotificationDefinition;
use App\Notifications\Identity\IdentityNotificationType;
use App\Services\NotificationDefinitionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationDefinitionResource extends BaseApiResource
{
    /**
     * {@inheritDoc}
     */
    protected function abilityMap(): array
    {
        return [
            'can_update' => 'core.settings.update',
            'can_delete' => 'core.settings.update',
        ];
    }

    /**
     * 권한 맵에 행 조건을 더해 abilities 를 계산합니다.
     *
     * 삭제는 운영자가 추가한 본인인증 정책 문구(category=identity, 선언 기본값 아님)만 허용한다 —
     * 코어·확장이 선언한 정의는 재시딩으로 되살아나므로 지울 수 없고, 일반 알림은 삭제 개념이 없다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, bool> 액션별 허용 여부
     */
    protected function resolveAbilities(Request $request): array
    {
        $abilities = parent::resolveAbilities($request);

        if (array_key_exists('can_delete', $abilities)) {
            // 목록(NotificationDefinitionCollection)은 컬렉션이 이미 이 리소스로 감싼 항목을 다시 감싸므로
            // 모델까지 벗겨서 판정한다 — 감싼 채로 보면 모델 검사가 거짓이 되어 목록 행만 [삭제] 가 사라진다.
            $definition = $this->resource;
            while ($definition instanceof JsonResource) {
                $definition = $definition->resource;
            }

            // 삭제 API 와 같은 판정을 쓴다 — 선언 정의는 문구를 고쳐 「기본」 이 풀려도 지울 수 없다(운영자 추가 정의만).
            $abilities['can_delete'] = $abilities['can_delete']
                && $definition instanceof NotificationDefinition
                && app(NotificationDefinitionService::class)->isDeletable($definition);
        }

        return $abilities;
    }

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 알림 정의 응답 배열
     */
    public function toArray(Request $request): array
    {
        // 컬렉션(NotificationDefinitionCollection)이 이 배열을 그대로 응답에 실어 Laravel 의
        // MissingValue 제거 단계를 거치지 않는다 — 미충족 조건부 필드를 직접 걸러낸다.
        // (걸러내지 않으면 `"templates": {}` 같은 빈 객체가 응답에 남는다)
        return $this->withoutMissing([
            'id' => $this->getValue('id'),
            'type' => $this->getValue('type'),
            'hook_prefix' => $this->getValue('hook_prefix'),
            'extension_type' => $this->getValue('extension_type'),
            'extension_identifier' => $this->getValue('extension_identifier'),
            // 분류 — 'identity'(본인인증 알림) 또는 null(일반 알림). 화면의 분류 칩·수신자 고정 표시가 읽는다.
            'category' => $this->getValue('category'),
            // 본인인증 정책별 문구가 붙은 정책 키 — 화면은 해시로 줄인 type(100자 초과)을 되돌릴 수 없어
            // 「이미 문구가 있는 정책」 판정에 이 값을 쓴다. 정책별 type 이 아니면 null.
            'policy_key' => $this->resolvePolicyKey(),
            'name' => $this->getValue('name'),
            'description' => $this->getValue('description'),
            // 변수 설명은 선언이 한국어 문자열이라 요청 언어로 바꿔 싣는다 — 그대로 실으면 영어·일본어 화면의
            // 편집 창 「사용 가능한 변수」 가 한국어였다(7.1.0 실측 QE-O4).
            'variables' => $this->definitionModel()->localizedVariables((array) ($this->getValue('variables') ?? [])),
            'channels' => $this->getValue('channels'),
            'hooks' => $this->getValue('hooks'),
            // 「관련 사용자」 선택지의 출처. 비면 화면이 그 타입을 감춘다.
            'relations' => $this->getValue('relations') ?? [],
            // 선택지·목록이 `post_author` 같은 키 대신 읽을 이름. 이름이 없으면 키를 그대로 둔다.
            'relation_labels' => $this->relationLabels((array) ($this->getValue('relations') ?? [])),
            'is_active' => (bool) $this->getValue('is_active'),
            'is_default' => (bool) $this->getValue('is_default'),
            'templates' => $this->whenLoaded(
                'templates',
                fn () => NotificationTemplateResource::collection($this->templates)
            ),
            // 목록이 템플릿을 한 채널로 좁혀 싣기 때문에, "커스터마이즈된 템플릿이 있는가" 는
            // 배열을 훑어서는 알 수 없다 (다른 채널만 수정한 경우를 놓친다). 되돌리기 버튼
            // 노출 조건이 이 값에 의존하므로 집계로 내려보낸다.
            'has_customized_templates' => $this->whenHas(
                'customized_templates_count',
                fn () => (int) $this->customized_templates_count > 0
            ),
            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ]);
    }

    /**
     * 관계 키마다 운영자가 읽을 이름을 찾습니다.
     *
     * 관계는 그 알림을 만든 확장이 선언하므로 이름도 그 확장의 언어 파일에서 찾는다 —
     * 확장은 `{식별자}::messages.notification_relations.{관계}`, 코어는 `notification.relations.{관계}`.
     * 이름이 없는 관계는 키를 그대로 돌려준다(선언은 있는데 이름만 빠진 관계도 고를 수는 있어야 한다).
     *
     * @param  array<int, mixed>  $relations  정의가 선언한 관계 키 목록
     * @return array<string, string> 관계 키 => 표시 이름
     */
    private function relationLabels(array $relations): array
    {
        $definition = $this->definitionModel();

        $labels = [];
        foreach ($relations as $relation) {
            if (! is_string($relation) || $relation === '') {
                continue;
            }

            $labels[$relation] = $definition->relationLabel($relation);
        }

        return $labels;
    }

    /**
     * 감싼 알림 정의 모델을 꺼냅니다.
     *
     * 목록은 이 리소스로 감싼 항목을 다시 감싸므로 모델까지 벗긴다(resolveAbilities 와 같은 이유).
     * 배열로 감싼 경우에는 언어 파일 조회에 필요한 칸(type·확장 식별자)만 담은 모델을 만든다.
     *
     * @return NotificationDefinition 알림 정의 모델
     */
    private function definitionModel(): NotificationDefinition
    {
        $definition = $this->resource;
        while ($definition instanceof JsonResource) {
            $definition = $definition->resource;
        }
        if ($definition instanceof NotificationDefinition) {
            return $definition;
        }

        return new NotificationDefinition([
            'type' => (string) ($this->getValue('type') ?? ''),
            'extension_identifier' => (string) ($this->getValue('extension_identifier') ?? ''),
        ]);
    }

    /**
     * 본인인증 정책별 정의의 정책 키를 구합니다.
     *
     * 해시로 줄인 type 일 때만 운영자 정책 키를 조회해 맞춰 본다 — 100자를 넘는 정책 키는 드물어
     * 목록의 대부분 행은 조회 없이 끝난다.
     *
     * @return string|null 정책 키 (정책별 type 이 아니면 null)
     */
    private function resolvePolicyKey(): ?string
    {
        $type = (string) $this->getValue('type');
        if (! str_starts_with($type, IdentityNotificationType::PREFIX.'policy.')) {
            return null;
        }

        $candidates = str_starts_with($type, IdentityNotificationType::PREFIX.'policy.~')
            ? IdentityPolicy::query()->where('source_type', 'admin')->pluck('key')
            : [];

        return IdentityNotificationType::policyKeyFromType($type, $candidates);
    }
}
