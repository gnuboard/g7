<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Http\Requests\Concerns;

use App\Rules\TranslatableField;
use Illuminate\Validation\Validator;

/**
 * 알림톡 템플릿 카카오 등록 페이로드(content) 검증 규칙 (#597 §3.2 매트릭스).
 *
 * Store/Update 가 동일 매트릭스를 공유한다. 문서(부록 A-2·A-3)에 수치가 명시된 제약만
 * 강제하고, 수치 미기재 세부(강조표기 타이틀 길이 등)는 kapi 를 최종 게이트로 위임한다 —
 * 실패 사유 원문이 errors 로 표면화되므로 이중 판정을 만들지 않는다.
 *
 * content 자체는 선택이다(SMS 단독 알림은 알림톡 content 없이 저장 가능). content 를
 * 보냈을 때만 유형별 조건부 필수·길이 제약이 적용된다.
 */
trait ValidatesTemplateContent
{
    /** 버튼 linkType 허용값 (부록 A-3) */
    private const BUTTON_LINK_TYPES = ['WL', 'AL', 'DS', 'BK', 'MD', 'AC', 'BC', 'BT', 'P1', 'P2', 'P3', 'TN', 'MP'];

    /** 바로연결 linkType 허용값 (부록 A-3 — WL/AL/BK/MD/BC/BT 만) */
    private const QUICK_REPLY_LINK_TYPES = ['WL', 'AL', 'BK', 'MD', 'BC', 'BT'];

    /**
     * content 하위 필드의 표시용 라벨을 반환합니다 (FormRequest::attributes 병합용).
     *
     * 미지정 시 Laravel 이 `content.templateItem.list.0.title` 같은 경로를 그대로 노출해
     * 운영자에게 영문 내부 식별자가 보인다. 배열 항목은 `*` 자리표시자로 선언하면
     * 인덱스가 붙은 실제 경로에도 적용된다.
     *
     * @return array<string, string> 필드 경로 → 라벨
     */
    private function contentAttributes(): array
    {
        $label = static fn (string $key): string => __("sirsoft-message_bizppurio::messages.validation.attributes.{$key}");

        return [
            'content' => $label('content'),
            'content.templateName' => $label('template_name'),
            'content.templateMessageType' => $label('message_type'),
            'content.templateEmphasizeType' => $label('emphasize_type'),
            'content.templateContent' => $label('template_content'),
            'content.templatePreviewMessage' => $label('preview_message'),
            'content.categoryCode' => $label('category_code'),
            'content.securityFlag' => $label('security_flag'),
            'content.templateExtra' => $label('extra'),
            'content.templateTitle' => $label('title'),
            'content.templateSubtitle' => $label('subtitle'),
            'content.templateHeader' => $label('header'),
            'content.templateImageName' => $label('image_name'),
            'content.templateImageUrl' => $label('image_url'),
            'content.templateItem' => $label('item'),
            'content.templateItem.list' => $label('item_list'),
            'content.templateItem.list.*.title' => $label('item_title'),
            'content.templateItem.list.*.description' => $label('item_description'),
            'content.templateItem.summary' => $label('summary'),
            'content.templateItem.summary.title' => $label('summary_title'),
            'content.templateItem.summary.description' => $label('summary_description'),
            'content.templateItemHighlight' => $label('highlight'),
            'content.templateItemHighlight.title' => $label('highlight_title'),
            'content.templateItemHighlight.description' => $label('highlight_description'),
            'content.templateItemHighlight.imageUrl' => $label('highlight_image_url'),
            'content.templateRepresentLink' => $label('represent_link'),
            'content.templateRepresentLink.linkMo' => $label('link_mo'),
            'content.templateRepresentLink.linkPc' => $label('link_pc'),
            'content.templateRepresentLink.linkAnd' => $label('link_and'),
            'content.templateRepresentLink.linkIos' => $label('link_ios'),
            'content.buttons' => $label('buttons'),
            'content.buttons.*.name' => $label('button_name'),
            'content.buttons.*.linkType' => $label('button_link_type'),
            'content.buttons.*.linkMo' => $label('link_mo'),
            'content.buttons.*.linkPc' => $label('link_pc'),
            'content.buttons.*.linkAnd' => $label('link_and'),
            'content.buttons.*.linkIos' => $label('link_ios'),
            'content.buttons.*.telNumber' => $label('tel_number'),
            'content.buttons.*.pluginId' => $label('plugin_id'),
            'content.quickReplies' => $label('quick_replies'),
            'content.quickReplies.*.name' => $label('quick_reply_name'),
            'content.quickReplies.*.linkType' => $label('quick_reply_link_type'),
            'content.quickReplies.*.linkMo' => $label('link_mo'),
            'content.quickReplies.*.linkPc' => $label('link_pc'),
            'content.quickReplies.*.linkAnd' => $label('link_and'),
            'content.quickReplies.*.linkIos' => $label('link_ios'),
        ];
    }

    /**
     * SMS 본문(다국어 맵)의 검증 규칙을 반환합니다 (#597 §14.3).
     *
     * sms_body 는 로케일별 본문 맵이다. 허용 로케일 판정·길이 검사는 코어 TranslatableField
     * 규칙에 위임한다 — 로케일 목록(`app.translatable_locales`)은 활성 언어팩에 따라 부팅마다
     * 달라지는 가변값이고, 그 규칙이 이미 "비활성 언어팩의 기존 번역은 지우지 않는다" 는
     * 판정까지 담고 있다. 여기서 다시 조립하면 같은 판정이 두 벌이 된다.
     *
     * Store/Update/Delivery 세 경로가 같은 규칙을 써야 한다 — 한 곳만 느슨하면 그 경로가
     * 우회로가 된다.
     *
     * @return array<string, array<int, mixed>> 필드 경로 → 규칙
     */
    private function smsBodyRules(): array
    {
        return [
            'sms_body' => ['sometimes', 'nullable', 'array', new TranslatableField(maxLength: 2000)],
        ];
    }

    /**
     * 발송 설정 필드의 표시용 라벨을 반환합니다 (FormRequest::attributes 병합용).
     *
     * @return array<string, string> 필드 경로 → 라벨
     */
    private function deliveryAttributes(): array
    {
        $label = static fn (string $key): string => __("sirsoft-message_bizppurio::messages.validation.attributes.{$key}");

        return [
            'notification_type' => $label('notification_type'),
            'alimtalk_enabled' => $label('alimtalk_enabled'),
            'fallback_sms_enabled' => $label('fallback_sms_enabled'),
            'sms_body' => $label('sms_body'),
            'sms_only' => $label('sms_only'),
            'is_active' => $label('is_active'),
        ];
    }

    /**
     * 유형과 무관한 content 필드를 잘라내 kapi 등록 페이로드를 정돈합니다.
     *
     * 화면(작성 모달)은 폼 상태 전체를 그대로 전송한다 — 유형 전환 잔여값(강조표기
     * 타이틀·이미지 URL·아이템리스트 등)을 클라이언트가 조건부로 빼는 대신, 서버가
     * 선택된 templateMessageType/templateEmphasizeType 기준으로 무관 필드·빈 값을
     * 제거한다. 저장된 content 가 곧 kapi add/update 페이로드이므로(§3.1) 이 정돈이
     * 등록 시 불필요 필드 거부를 예방한다. prepareForValidation 에서 호출한다.
     */
    private function pruneContentPayload(): void
    {
        $content = $this->input('content');
        if (! is_array($content)) {
            return;
        }

        $messageType = (string) ($content['templateMessageType'] ?? '');
        $emphasizeType = (string) ($content['templateEmphasizeType'] ?? '');

        if (! in_array($messageType, ['EX', 'MI'], true)) {
            unset($content['templateExtra']);
        }

        if ($emphasizeType !== 'TEXT') {
            unset($content['templateTitle'], $content['templateSubtitle']);
        }

        if ($emphasizeType !== 'IMAGE') {
            unset($content['templateImageName'], $content['templateImageUrl']);
        }

        if ($emphasizeType !== 'ITEM_LIST') {
            unset($content['templateItem'], $content['templateItemHighlight']);
        }

        // 빈 선택 값 제거 — 빈 문자열/빈 배열을 kapi 에 보내면 등록이 거부될 수 있다.
        foreach (['templatePreviewMessage', 'templateHeader', 'templateExtra', 'templateTitle', 'templateSubtitle', 'templateImageName', 'templateImageUrl'] as $key) {
            if (array_key_exists($key, $content) && trim((string) $content[$key]) === '') {
                unset($content[$key]);
            }
        }

        foreach (['buttons', 'quickReplies'] as $group) {
            if (array_key_exists($group, $content)) {
                $items = is_array($content[$group]) ? array_values(array_filter($content[$group], 'is_array')) : [];
                $items = array_map($this->pruneEmptyStrings(...), $items);

                if ($items === []) {
                    unset($content[$group]);
                } else {
                    $content[$group] = $items;
                }
            }
        }

        if (array_key_exists('templateItemHighlight', $content)) {
            $highlight = is_array($content['templateItemHighlight'])
                ? $this->pruneEmptyStrings($content['templateItemHighlight'])
                : [];
            if ($highlight === []) {
                unset($content['templateItemHighlight']);
            } else {
                $content['templateItemHighlight'] = $highlight;
            }
        }

        if (array_key_exists('templateRepresentLink', $content)) {
            $link = is_array($content['templateRepresentLink'])
                ? $this->pruneEmptyStrings($content['templateRepresentLink'])
                : [];
            if ($link === []) {
                unset($content['templateRepresentLink']);
            } else {
                $content['templateRepresentLink'] = $link;
            }
        }

        if (array_key_exists('templateItem', $content) && is_array($content['templateItem'])) {
            $item = $content['templateItem'];
            if (isset($item['summary']) && is_array($item['summary'])) {
                $summary = $this->pruneEmptyStrings($item['summary']);
                if ($summary === []) {
                    unset($item['summary']);
                } else {
                    $item['summary'] = $summary;
                }
            }
            $content['templateItem'] = $item;
        }

        $this->merge(['content' => $content]);
    }

    /**
     * 연관 배열에서 빈 문자열 값을 제거합니다.
     *
     * @param  array<string, mixed>  $row  대상 배열
     * @return array<string, mixed> 빈 문자열이 제거된 배열
     */
    private function pruneEmptyStrings(array $row): array
    {
        return array_filter(
            $row,
            static fn ($value) => ! (is_string($value) && trim($value) === ''),
        );
    }

    /**
     * content 하위 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed>
     */
    private function contentRules(): array
    {
        return [
            'content' => ['sometimes', 'nullable', 'array'],
            'content.templateName' => ['required_with:content', 'string', 'max:200'],
            'content.templateMessageType' => ['required_with:content', 'in:BA,EX,AD,MI'],
            'content.templateEmphasizeType' => ['required_with:content', 'in:NONE,TEXT,IMAGE,ITEM_LIST'],
            'content.templateContent' => ['required_with:content', 'string', 'max:1000'],
            'content.templatePreviewMessage' => ['nullable', 'string', 'max:40'],
            'content.categoryCode' => ['required_with:content', 'string', 'max:20'],
            'content.securityFlag' => ['nullable', 'boolean'],
            // EX(부가정보형)·MI(복합형)는 부가정보 필수 (A-2)
            'content.templateExtra' => ['nullable', 'string', 'required_if:content.templateMessageType,EX,MI'],
            // TEXT(강조표기) 필수쌍 — 길이 수치는 문서 미기재라 미강제(kapi 위임)
            'content.templateTitle' => ['nullable', 'string', 'required_if:content.templateEmphasizeType,TEXT'],
            'content.templateSubtitle' => ['nullable', 'string', 'required_if:content.templateEmphasizeType,TEXT'],
            'content.templateHeader' => ['nullable', 'string', 'max:16'],
            // IMAGE(이미지형) 필수쌍 — url 은 업로드 프록시 응답값만 화면이 기입한다
            'content.templateImageName' => ['nullable', 'string', 'required_if:content.templateEmphasizeType,IMAGE'],
            'content.templateImageUrl' => ['nullable', 'string', 'max:500', 'required_if:content.templateEmphasizeType,IMAGE'],
            // ITEM_LIST(아이템리스트) — list 2~10개, 각 title≤6·description≤23 (A-2)
            'content.templateItem' => ['nullable', 'array', 'required_if:content.templateEmphasizeType,ITEM_LIST'],
            'content.templateItem.list' => ['nullable', 'array', 'min:2', 'max:10', 'required_with:content.templateItem'],
            'content.templateItem.list.*.title' => ['required', 'string', 'max:6'],
            'content.templateItem.list.*.description' => ['required', 'string', 'max:23'],
            'content.templateItem.summary' => ['nullable', 'array'],
            'content.templateItem.summary.title' => ['nullable', 'string', 'max:6'],
            'content.templateItem.summary.description' => ['nullable', 'string', 'max:14'],
            'content.templateItemHighlight' => ['nullable', 'array'],
            'content.templateItemHighlight.title' => ['nullable', 'string'],
            'content.templateItemHighlight.description' => ['nullable', 'string'],
            'content.templateItemHighlight.imageUrl' => ['nullable', 'string', 'max:500'],
            'content.templateRepresentLink' => ['nullable', 'array'],
            'content.templateRepresentLink.linkMo' => ['nullable', 'string', 'max:500'],
            'content.templateRepresentLink.linkPc' => ['nullable', 'string', 'max:500'],
            'content.templateRepresentLink.linkAnd' => ['nullable', 'string', 'max:500'],
            'content.templateRepresentLink.linkIos' => ['nullable', 'string', 'max:500'],
            'content.buttons' => ['nullable', 'array', 'max:5'],
            'content.buttons.*.name' => ['required', 'string', 'max:14'],
            'content.buttons.*.linkType' => ['required', 'string', 'in:'.implode(',', self::BUTTON_LINK_TYPES)],
            'content.buttons.*.linkMo' => ['nullable', 'string', 'max:500'],
            'content.buttons.*.linkPc' => ['nullable', 'string', 'max:500'],
            'content.buttons.*.linkAnd' => ['nullable', 'string', 'max:500'],
            'content.buttons.*.linkIos' => ['nullable', 'string', 'max:500'],
            'content.buttons.*.telNumber' => ['nullable', 'string', 'max:20'],
            'content.buttons.*.pluginId' => ['nullable', 'string', 'max:100'],
            'content.quickReplies' => ['nullable', 'array', 'max:10'],
            'content.quickReplies.*.name' => ['required', 'string'],
            'content.quickReplies.*.linkType' => ['required', 'string', 'in:'.implode(',', self::QUICK_REPLY_LINK_TYPES)],
            'content.quickReplies.*.linkMo' => ['nullable', 'string', 'max:500'],
            'content.quickReplies.*.linkPc' => ['nullable', 'string', 'max:500'],
            'content.quickReplies.*.linkAnd' => ['nullable', 'string', 'max:500'],
            'content.quickReplies.*.linkIos' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * 배열 항목별 조건부 필수(linkType 별 링크 필드)와 하이라이트 썸네일 연동 길이를 검증합니다.
     *
     * Laravel 룰 문법으로 표현하기 어려운 항목별 조건을 after 훅에서 판정한다:
     *  - WL → linkMo 필수 / AL → linkAnd+linkIos 둘 다 / TN → telNumber / P1~P3 → pluginId (A-3)
     *  - itemHighlight 는 썸네일(imageUrl) 유무에 따라 title≤30/21 · description≤19/13 (A-2)
     *
     * @param  Validator  $validator  검증기
     */
    private function validateContentConditionals(Validator $validator): void
    {
        $content = $this->input('content');
        if (! is_array($content)) {
            return;
        }

        foreach (['buttons', 'quickReplies'] as $group) {
            $items = $content[$group] ?? null;
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $linkType = (string) ($item['linkType'] ?? '');
                $path = "content.{$group}.{$index}";

                if ($linkType === 'WL' && trim((string) ($item['linkMo'] ?? '')) === '') {
                    $validator->errors()->add("{$path}.linkMo", __('sirsoft-message_bizppurio::messages.validation.link_mo_required'));
                }

                if ($linkType === 'AL') {
                    if (trim((string) ($item['linkAnd'] ?? '')) === '') {
                        $validator->errors()->add("{$path}.linkAnd", __('sirsoft-message_bizppurio::messages.validation.link_and_required'));
                    }
                    if (trim((string) ($item['linkIos'] ?? '')) === '') {
                        $validator->errors()->add("{$path}.linkIos", __('sirsoft-message_bizppurio::messages.validation.link_ios_required'));
                    }
                }

                if ($linkType === 'TN' && trim((string) ($item['telNumber'] ?? '')) === '') {
                    $validator->errors()->add("{$path}.telNumber", __('sirsoft-message_bizppurio::messages.validation.tel_number_required'));
                }

                if (in_array($linkType, ['P1', 'P2', 'P3'], true) && trim((string) ($item['pluginId'] ?? '')) === '') {
                    $validator->errors()->add("{$path}.pluginId", __('sirsoft-message_bizppurio::messages.validation.plugin_id_required'));
                }
            }
        }

        $highlight = $content['templateItemHighlight'] ?? null;
        if (is_array($highlight)) {
            $hasThumbnail = trim((string) ($highlight['imageUrl'] ?? '')) !== '';
            $titleMax = $hasThumbnail ? 21 : 30;
            $descriptionMax = $hasThumbnail ? 13 : 19;

            if (mb_strlen((string) ($highlight['title'] ?? '')) > $titleMax) {
                $validator->errors()->add('content.templateItemHighlight.title', __('sirsoft-message_bizppurio::messages.validation.highlight_title_too_long', ['max' => $titleMax]));
            }

            if (mb_strlen((string) ($highlight['description'] ?? '')) > $descriptionMax) {
                $validator->errors()->add('content.templateItemHighlight.description', __('sirsoft-message_bizppurio::messages.validation.highlight_description_too_long', ['max' => $descriptionMax]));
            }
        }
    }
}
