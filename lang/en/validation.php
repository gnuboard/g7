<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Laravel Default Validation Messages
    |--------------------------------------------------------------------------
    |
    | Default validation messages for Laravel's validation rules.
    |
    */

    'accepted' => 'The :attribute field must be accepted.',
    'accepted_if' => 'The :attribute field must be accepted when :other is :value.',
    'active_url' => 'The :attribute field must be a valid URL.',
    'after' => 'The :attribute field must be a date after :date.',
    'after_or_equal' => 'The :attribute field must be a date after or equal to :date.',
    'alpha' => 'The :attribute field must only contain letters.',
    'alpha_dash' => 'The :attribute field must only contain letters, numbers, dashes, and underscores.',
    'alpha_num' => 'The :attribute field must only contain letters and numbers.',
    'array' => 'The :attribute field must be an array.',
    'ascii' => 'The :attribute field must only contain single-byte alphanumeric characters and symbols.',
    'before' => 'The :attribute field must be a date before :date.',
    'before_or_equal' => 'The :attribute field must be a date before or equal to :date.',
    'between' => [
        'array' => 'The :attribute field must have between :min and :max items.',
        'file' => 'The :attribute field must be between :min and :max kilobytes.',
        'numeric' => 'The :attribute field must be between :min and :max.',
        'string' => 'The :attribute field must be between :min and :max characters.',
    ],
    'boolean' => 'The :attribute field must be true or false.',
    'can' => 'The :attribute field contains an unauthorized value.',
    'confirmed' => 'The :attribute field confirmation does not match.',
    'contains' => 'The :attribute field is missing a required value.',
    'current_password' => 'The password is incorrect.',
    'date' => 'The :attribute field must be a valid date.',
    'date_equals' => 'The :attribute field must be a date equal to :date.',
    'date_format' => 'The :attribute field must match the format :format.',
    'decimal' => 'The :attribute field must have :decimal decimal place.|The :attribute field must have :decimal decimal places.',
    'declined' => 'The :attribute field must be declined.',
    'declined_if' => 'The :attribute field must be declined when :other is :value.',
    'different' => 'The :attribute field and :other must be different.',
    'digits' => 'The :attribute field must be :digits digit.|The :attribute field must be :digits digits.',
    'digits_between' => 'The :attribute field must be between :min and :max digits.',
    'dimensions' => 'The :attribute field has invalid image dimensions.',
    'distinct' => 'The :attribute field has a duplicate value.',
    'doesnt_end_with' => 'The :attribute field must not end with one of the following: :values.',
    'doesnt_start_with' => 'The :attribute field must not start with one of the following: :values.',
    'email' => 'The :attribute field must be a valid email address.',
    'ends_with' => 'The :attribute field must end with one of the following: :values.',
    'enum' => 'The selected :attribute is invalid.',
    'exists' => 'The selected :attribute is invalid.',
    'extensions' => 'The :attribute field must have one of the following extensions: :values.',
    'file' => 'The :attribute field must be a file.',
    'filled' => 'The :attribute field must have a value.',
    'gt' => [
        'array' => 'The :attribute field must have more than :value item.|The :attribute field must have more than :value items.',
        'file' => 'The :attribute field must be greater than :value kilobyte.|The :attribute field must be greater than :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than :value.',
        'string' => 'The :attribute field must be greater than :value character.|The :attribute field must be greater than :value characters.',
    ],
    'gte' => [
        'array' => 'The :attribute field must have :value item or more.|The :attribute field must have :value items or more.',
        'file' => 'The :attribute field must be greater than or equal to :value kilobyte.|The :attribute field must be greater than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than or equal to :value.',
        'string' => 'The :attribute field must be greater than or equal to :value character.|The :attribute field must be greater than or equal to :value characters.',
    ],
    'hex_color' => 'The :attribute field must be a valid hexadecimal color.',
    'image' => 'The :attribute field must be an image.',
    'in' => 'The selected :attribute is invalid.',
    'in_array' => 'The :attribute field must exist in :other.',
    'integer' => 'The :attribute field must be an integer.',
    'ip' => 'The :attribute field must be a valid IP address.',
    'ipv4' => 'The :attribute field must be a valid IPv4 address.',
    'ipv6' => 'The :attribute field must be a valid IPv6 address.',
    'json' => 'The :attribute field must be a valid JSON string.',
    'list' => 'The :attribute field must be a list.',
    'lowercase' => 'The :attribute field must be lowercase.',
    'lt' => [
        'array' => 'The :attribute field must have less than :value item.|The :attribute field must have less than :value items.',
        'file' => 'The :attribute field must be less than :value kilobyte.|The :attribute field must be less than :value kilobytes.',
        'numeric' => 'The :attribute field must be less than :value.',
        'string' => 'The :attribute field must be less than :value character.|The :attribute field must be less than :value characters.',
    ],
    'lte' => [
        'array' => 'The :attribute field must not have more than :value item.|The :attribute field must not have more than :value items.',
        'file' => 'The :attribute field must be less than or equal to :value kilobyte.|The :attribute field must be less than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be less than or equal to :value.',
        'string' => 'The :attribute field must be less than or equal to :value character.|The :attribute field must be less than or equal to :value characters.',
    ],
    'mac_address' => 'The :attribute field must be a valid MAC address.',
    'max' => [
        'array' => 'The :attribute field must not have more than :max item.|The :attribute field must not have more than :max items.',
        'file' => 'The :attribute field must not be greater than :max kilobyte.|The :attribute field must not be greater than :max kilobytes.',
        'numeric' => 'The :attribute field must not be greater than :max.',
        'string' => 'The :attribute field must not be greater than :max character.|The :attribute field must not be greater than :max characters.',
    ],
    'max_digits' => 'The :attribute field must not have more than :max digit.|The :attribute field must not have more than :max digits.',
    'mimes' => 'The :attribute field must be a file of type: :values.',
    'mimetypes' => 'The :attribute field must be a file of type: :values.',
    'min' => [
        'array' => 'The :attribute field must have at least :min item.|The :attribute field must have at least :min items.',
        'file' => 'The :attribute field must be at least :min kilobyte.|The :attribute field must be at least :min kilobytes.',
        'numeric' => 'The :attribute field must be at least :min.',
        'string' => 'The :attribute field must be at least :min character.|The :attribute field must be at least :min characters.',
    ],
    'min_digits' => 'The :attribute field must have at least :min digit.|The :attribute field must have at least :min digits.',
    'missing' => 'The :attribute field must be missing.',
    'missing_if' => 'The :attribute field must be missing when :other is :value.',
    'missing_unless' => 'The :attribute field must be missing unless :other is :value.',
    'missing_with' => 'The :attribute field must be missing when :values is present.',
    'missing_with_all' => 'The :attribute field must be missing when :values are present.',
    'multiple_of' => 'The :attribute field must be a multiple of :value.',
    'not_in' => 'The selected :attribute is invalid.',
    'not_regex' => 'The :attribute field format is invalid.',
    'numeric' => 'The :attribute field must be a number.',
    'password' => [
        'letters' => 'The :attribute field must contain at least one letter.',
        'mixed' => 'The :attribute field must contain at least one uppercase and one lowercase letter.',
        'numbers' => 'The :attribute field must contain at least one number.',
        'symbols' => 'The :attribute field must contain at least one symbol.',
        'uncompromised' => 'The given :attribute has appeared in a data leak. Please choose a different :attribute.',
    ],
    'present' => 'The :attribute field must be present.',
    'present_if' => 'The :attribute field must be present when :other is :value.',
    'present_unless' => 'The :attribute field must be present unless :other is :value.',
    'present_with' => 'The :attribute field must be present when :values is present.',
    'present_with_all' => 'The :attribute field must be present when :values are present.',
    'prohibited' => 'The :attribute field is prohibited.',
    'prohibited_if' => 'The :attribute field is prohibited when :other is :value.',
    'prohibited_unless' => 'The :attribute field is prohibited unless :other is in :values.',
    'prohibits' => 'The :attribute field prohibits :other from being present.',
    'regex' => 'The :attribute field format is invalid.',
    'required' => 'The :attribute field is required.',
    'required_array_keys' => 'The :attribute field must contain entries for: :values.',
    'required_if' => 'The :attribute field is required when :other is :value.',
    'required_if_accepted' => 'The :attribute field is required when :other is accepted.',
    'required_if_declined' => 'The :attribute field is required when :other is declined.',
    'required_unless' => 'The :attribute field is required unless :other is in :values.',
    'required_with' => 'The :attribute field is required when :values is present.',
    'required_with_all' => 'The :attribute field is required when :values are present.',
    'required_without' => 'The :attribute field is required when :values is not present.',
    'required_without_all' => 'The :attribute field is required when none of :values are present.',
    'same' => 'The :attribute field must match :other.',
    'size' => [
        'array' => 'The :attribute field must contain :size item.|The :attribute field must contain :size items.',
        'file' => 'The :attribute field must be :size kilobyte.|The :attribute field must be :size kilobytes.',
        'numeric' => 'The :attribute field must be :size.',
        'string' => 'The :attribute field must be :size character.|The :attribute field must be :size characters.',
    ],
    'starts_with' => 'The :attribute field must start with one of the following: :values.',
    'string' => 'The :attribute field must be a string.',
    'timezone' => 'The :attribute field must be a valid timezone.',
    'unique' => 'The :attribute has already been taken.',
    'uploaded' => 'The :attribute failed to upload.',
    'uppercase' => 'The :attribute field must be uppercase.',
    'url' => 'The :attribute field must be a valid URL.',
    'ulid' => 'The :attribute field must be a valid ULID.',
    'uuid' => 'The :attribute field must be a valid UUID.',

    /*
    |--------------------------------------------------------------------------
    | Project Validation Messages
    |--------------------------------------------------------------------------
    */

    // Custom translation (dynamic i18n key) validation messages
    'custom_translation' => [
        'layout_name' => [
            'required' => 'The layout name is required.',
            'string' => 'The layout name must be a string.',
            'max' => 'The layout name may not be greater than :max characters.',
        ],
        'locale' => [
            'required' => 'The locale is required.',
            'string' => 'The locale must be a string.',
        ],
        'value' => [
            'required' => 'The translation value is required.',
            'string' => 'The translation value must be a string.',
        ],
        'values' => [
            'required' => 'The translation values are required.',
            'array' => 'The translation values must be a locale-keyed object.',
        ],
        'status' => [
            'in' => 'The status must be either active or orphaned.',
        ],
        'expected_lock_version' => [
            'required' => 'expected_lock_version is missing from the save request.',
            'integer' => 'expected_lock_version must be an integer.',
            'min' => 'expected_lock_version must be 0 or greater.',
        ],
        'ids' => [
            'required' => 'Select at least one translation key to delete.',
            'array' => 'The deletion target must be an array of IDs.',
            'min' => 'Select at least one translation key to delete.',
            'integer' => 'Translation key ID must be an integer.',
            'exists' => 'The request contains a translation key that does not exist.',
        ],
    ],

    // Layout structure validation messages
    'layout' => [
        // Optimistic lock
        'expected_lock_version' => [
            'required' => 'expected_lock_version is missing from the save request.',
            'integer' => 'expected_lock_version must be an integer.',
            'min' => 'expected_lock_version must be 0 or greater.',
        ],

        'invalid_json' => 'Invalid JSON format.',
        'must_be_array' => 'Layout data must be an array.',
        'dangerous_expression' => 'The layout contains a disallowed expression: :snippet',
        'external_resource_url' => 'External resource URLs are not allowed (same-origin paths only): :url',
        'required_field_missing' => "Required field ':field' is missing.",
        'version_must_be_string' => 'The version field must be a string.',
        'layout_name_must_be_string' => 'The layout_name field must be a string.',
        'components_must_be_array' => 'The components field must be an array.',
        'components_or_slots_required' => 'Inherited layouts require either components or slots.',
        'component_must_be_array' => 'components[:index] must be an array.',
        'max_depth_exceeded' => 'Component nesting depth exceeds maximum allowed depth (:max).',
        'component_required_field_missing' => "Required field ':field' is missing in components[:index].",
        'component_field_must_be_string' => 'components[:index].component must be a string.',
        'component_name_must_be_string' => 'components[:index].name must be a string.',
        'component_type_invalid' => 'components[:index].type must be one of basic, composite, layout.',
        'props_must_be_object' => 'components[:index].props must be an object (array).',
        'children_must_be_array' => 'components[:index].children must be an array.',
        'permissions_must_be_array' => 'components[:index].permissions must be an array.',
        'permissions_must_be_array_or_object' => 'permissions must be an array or a structured object (or/and).',
        'permissions_invalid_operator' => 'Only "or" and "and" operators are allowed in permissions structure. Exactly one key is required.',
        'permissions_operator_must_be_array' => 'The value of ":operator" operator in permissions must be an array.',
        'permissions_operator_min_items' => 'The ":operator" operator in permissions requires at least :min items.',
        'permissions_max_depth_exceeded' => 'Permissions structure exceeds maximum nesting depth (:max).',
        'permission_must_be_string' => 'components[:index].permissions[:perm_index] must be a string.',
        'permission_must_be_string_or_group' => 'Item [:index] in permissions must be a string (permission identifier) or a structured object (or/and).',
        'permission_invalid_format' => '":permission" in components[:index].permissions is not a valid permission identifier format.',
        'actions_must_be_array' => 'components[:index].actions must be an array.',
        'action_must_be_array' => 'components[:index].actions[:actionIndex] must be an array.',
        'action_type_missing' => "Required field 'type' is missing in components[:index].actions[:actionIndex].",
        'action_type_or_event_missing' => "Either 'type' or 'event' is required in components[:index].actions[:actionIndex].",
        'action_type_must_be_string' => 'components[:index].actions[:actionIndex].type must be a string.',
        'action_event_must_be_string' => 'components[:index].actions[:actionIndex].event must be a string.',
        // UpdateLayoutRequest validation messages
        'content' => [
            'required' => 'The layout content is required.',
            'array' => 'The layout content must be an array.',
            'markup_compile_failed' => 'The layout markup (.g7.html) could not be compiled: :detail',
        ],
        'markup' => [
            'required' => 'The markup (.g7.html) to compile is required.',
            'string' => 'The markup must be a string.',
        ],
        'version' => [
            'required' => 'The layout version is required.',
            'string' => 'The layout version must be a string.',
        ],
        'layout_name' => [
            'required' => 'The layout name is required.',
            'string' => 'The layout name must be a string.',
            'max' => 'The layout name may not be greater than :max characters.',
        ],
        'endpoint' => [
            'required' => 'The API endpoint is required.',
            'string' => 'The API endpoint must be a string.',
        ],
        'extends' => [
            'string' => 'The extends field must be a string.',
        ],
        'slots' => [
            'array' => 'The slots field must be an array.',
        ],
        'components' => [
            'required' => 'The components array is required.',
            'array' => 'The components field must be an array.',
        ],
        'data_sources' => [
            'array' => 'The data_sources field must be an array.',
        ],
        'metadata' => [
            'array' => 'The metadata field must be an array.',
        ],
        'meta' => [
            'array' => 'The meta field must be an array.',
            'title' => [
                'string' => 'The meta.title must be a string.',
            ],
            'description' => [
                'string' => 'The meta.description must be a string.',
            ],
            'keywords' => [
                'string' => 'The meta.keywords must be a string.',
            ],
            'auth_required' => [
                'boolean' => 'The meta.auth_required must be a boolean.',
            ],
            'is_base' => [
                'boolean' => 'The meta.is_base must be a boolean.',
            ],
            'guest_only' => [
                'boolean' => 'The meta.guest_only must be a boolean.',
            ],
            'is_error_layout' => [
                'boolean' => 'The meta.is_error_layout must be a boolean.',
            ],
            'error_code' => [
                'integer' => 'The meta.error_code must be an integer.',
            ],
            'seo' => [
                'array' => 'The meta.seo must be an array.',
                'enabled' => [
                    'boolean' => 'The meta.seo.enabled must be a boolean.',
                ],
                'data_sources' => [
                    'array' => 'The meta.seo.data_sources must be an array.',
                    'string' => 'Each item in meta.seo.data_sources must be a string.',
                ],
                'priority' => [
                    'numeric' => 'The meta.seo.priority must be numeric.',
                    'min' => 'The meta.seo.priority must be at least 0.',
                    'max' => 'The meta.seo.priority must not exceed 1.',
                ],
                'changefreq' => [
                    'string' => 'The meta.seo.changefreq must be a string.',
                    'in' => 'The meta.seo.changefreq must be one of always, hourly, daily, weekly, monthly, yearly, never.',
                ],
                'og' => [
                    'array' => 'The meta.seo.og must be an array.',
                ],
                'structured_data' => [
                    'array' => 'The meta.seo.structured_data must be an array.',
                ],
                'page_type' => [
                    'string' => 'The meta.seo.page_type must be a string.',
                ],
                'toggle_setting' => [
                    'string' => 'The meta.seo.toggle_setting must be a string.',
                ],
                'vars' => [
                    'array' => 'The meta.seo.vars must be an array.',
                ],
                'extensions' => [
                    'array' => 'The meta.seo.extensions must be an array.',
                ],
            ],
        ],
        'modals' => [
            'array' => 'The modals field must be an array.',
        ],
        'state' => [
            'array' => 'The state field must be an array.',
        ],
        'init_actions' => [
            'array' => 'The init_actions field must be an array.',
        ],
        'defines' => [
            'array' => 'The defines field must be an array.',
        ],
        'init_state' => [
            'array' => 'The init_state field must be an array.',
        ],
        'initLocal' => [
            'array' => 'The initLocal field must be an array.',
        ],
        'initGlobal' => [
            'array' => 'The initGlobal field must be an array.',
        ],
        'global_state' => [
            'array' => 'The global_state field must be an array.',
        ],
        'errorHandling' => [
            'array' => 'The errorHandling field must be an array.',
        ],
        'actions' => [
            'array' => 'The actions field must be an array.',
        ],
        'pageConfig' => [
            'array' => 'The pageConfig field must be an array.',
        ],
        'schema' => [
            'array' => 'The schema field must be an array.',
        ],
        'routes' => [
            'array' => 'The routes field must be an array.',
        ],
        'computed' => [
            'array' => 'The computed field must be an array.',
        ],
        'permissions' => [
            'array' => 'The permissions field must be an array.',
            'string' => 'Each permission identifier must be a string.',
            'regex' => 'Invalid permission identifier format. (e.g., module.entity.action)',
        ],
        'globalHeaders' => [
            'array' => 'The globalHeaders field must be an array.',
            'pattern' => [
                'required' => 'Each globalHeaders rule requires a pattern field.',
                'string' => 'The globalHeaders pattern must be a string.',
            ],
            'headers' => [
                'required' => 'Each globalHeaders rule requires a headers field.',
                'array' => 'The globalHeaders headers must be an array.',
                'string' => 'Header values must be strings.',
            ],
        ],
        'transition_overlay' => [
            'enabled' => [
                'boolean' => 'The transition overlay enabled must be true or false.',
            ],
            'style' => [
                'string' => 'The transition overlay style must be a string.',
                'in' => 'The transition overlay style must be one of: opaque, blur, fade, skeleton.',
            ],
            'target' => [
                'string' => 'The transition overlay target must be a string.',
                'max' => 'The transition overlay target must not exceed :max characters.',
            ],
            'fallback_target' => [
                'string' => 'The transition overlay fallback target must be a string.',
                'max' => 'The transition overlay fallback target must not exceed :max characters.',
            ],
            'skeleton' => [
                'array' => 'The skeleton configuration must be an array.',
                'component' => [
                    'string' => 'The skeleton component name must be a string.',
                    'max' => 'The skeleton component name must not exceed :max characters.',
                ],
                'animation' => [
                    'string' => 'The skeleton animation must be a string.',
                    'in' => 'The skeleton animation must be one of: pulse, wave, none.',
                ],
                'iteration_count' => [
                    'integer' => 'The skeleton iteration count must be an integer.',
                    'min' => 'The skeleton iteration count must be at least :min.',
                    'max' => 'The skeleton iteration count must not exceed :max.',
                ],
            ],
            'wait_for' => [
                'background' => 'wait_for cannot reference background data sources (cannot block the user): :id',
                'websocket' => 'wait_for cannot reference websocket data sources (no fetch completion event): :id',
            ],
        ],
    ],

    // Layout extension validation messages
    'layout_extension' => [
        // Optimistic lock
        'expected_lock_version' => [
            'required' => 'expected_lock_version is missing from the save request.',
            'integer' => 'expected_lock_version must be an integer.',
            'min' => 'expected_lock_version must be 0 or greater.',
        ],

        'invalid_json' => 'Invalid JSON format.',
        'must_be_array' => 'Layout extension data must be an array.',
        'target_required' => "An extension definition requires either 'extension_point' or 'target_layout'.",
        'target_exclusive' => "'extension_point' and 'target_layout' cannot be specified together.",
        'extension_point_invalid' => 'The extension_point field must be a non-empty string.',
        'target_layout_invalid' => 'The target_layout field must be a non-empty string.',
        'components_must_be_array' => 'The components field must be an array.',
        'contribution_required' => 'An overlay extension requires at least one of injections, modals, data_sources, scripts, or init_actions.',
        'injections_must_be_array' => 'The injections field must be an array.',
        'injection_must_be_array' => 'injections[:index] must be an array.',
        'injection_target_id_required' => 'injections[:index] requires the target_id field.',
        'injection_position_invalid' => 'injections[:index].position is invalid.',
        'injection_components_must_be_array' => 'injections[:index].components must be an array.',
        'injection_props_must_be_array' => 'injections[:index].props must be an array.',
        'section_must_be_array' => 'The :section field must be an array.',
        'max_depth_exceeded' => 'Component nesting depth exceeds the maximum allowed depth (:max).',
        'component_must_be_array' => 'components[:index] must be an array.',
        'component_required_field_missing' => "components[:index] is missing the required field ':field'.",
        'component_name_must_be_string' => 'components[:index].name must be a string.',
        'component_type_invalid' => 'components[:index].type must be one of basic, composite, layout.',
        'props_must_be_object' => 'components[:index].props must be an object (array).',
        'children_must_be_array' => 'components[:index].children must be an array.',
        'content' => [
            'required' => 'Extension content is required.',
            'array' => 'Extension content must be an array.',
            'markup_compile_failed' => 'The extension markup (.g7.html) could not be compiled: :detail',
        ],
        'priority' => [
            'integer' => 'priority must be an integer.',
            'min' => 'priority must be at least 0.',
            'max' => 'priority must not exceed 9999.',
        ],
        'data_sources' => [
            'array' => 'data_sources must be an array.',
        ],
        'preview_layout' => [
            'string' => 'The preview layout name must be a string.',
            'max' => 'The preview layout name must not exceed 255 characters.',
            'required' => 'An extension point preview requires selecting a representative layout.',
        ],
    ],

    // API endpoint validation messages
    'endpoint' => [
        'must_be_string' => 'The API endpoint must be a string.',
        'external_url_not_allowed' => 'External URLs are not allowed.',
        'not_whitelisted' => 'API endpoint is not whitelisted. Allowed pattern: :pattern',
        'path_traversal_detected' => 'Path traversal attack detected.',
    ],

    // External URL blocking messages
    'external_url' => [
        'detected_in_props' => 'External URL detected in props: :url',
        'detected_in_actions' => 'External URL detected in actions: :url',
        'http_not_allowed' => 'HTTP protocol URLs are not allowed.',
        'https_not_allowed' => 'HTTPS protocol URLs are not allowed.',
        'data_uri_not_allowed' => 'Data URI scheme is not allowed.',
        'javascript_uri_not_allowed' => 'JavaScript URI scheme is not allowed.',
        'dangerous_scheme_detected' => 'Dangerous URI scheme detected: :scheme',
    ],

    // Outbound URL (external API, schedule, etc.) validation messages
    'outbound_url' => [
        'invalid' => 'This is not a valid URL. Enter an address starting with http or https.',
        'internal_not_allowed' => 'Internal network addresses (private IPs, localhost, etc.) are not allowed. Enter a publicly reachable address.',
    ],

    // Schedule command (shell/Artisan) validation messages — these run on the server
    'schedule_command' => [
        'shell_not_allowed' => 'This shell command is not allowed. Only executables registered on the server may be used, and special characters such as pipes (|) or semicolons (;) are not permitted.',
        'shell_interpreter_denied' => 'You cannot run inline commands/code through an interpreter or point it at an unsafe path. After an interpreter, specify only an absolute-path script file.',
        'artisan_denied' => 'This Artisan command cannot be run as a schedule for security reasons.',
        'artisan_not_allowlisted' => 'This Artisan command is not allowed to run on a schedule. Only maintenance commands such as cache clearing and queue processing can be registered.',
        'artisan_malformed' => 'The Artisan command format is invalid. Quotes, backslashes, and short options (-v) are not allowed; use "command --option[=value]" only.',
        'artisan_option_denied' => 'This Artisan command contains an option that is not allowed.',
        'artisan_argument_denied' => 'This Artisan command does not accept additional arguments.',
    ],

    // Component existence validation messages
    'component' => [
        'template_id_required' => 'template_id is required for component validation.',
        'manifest_not_found' => 'Component manifest (components.json) not found for template :templateId.',
        'name_empty' => 'components[:index].component name cannot be empty.',
        'not_found' => "Component ':component' is not registered in the template. (components[:index])",
    ],

    // FormRequest field validation messages
    'request' => [
        'template_id' => [
            'required' => 'Template ID is required.',
            'integer' => 'Template ID must be an integer.',
            'exists' => 'Template does not exist.',
        ],
        'layout_name' => [
            'required' => 'Layout name is required.',
            'string' => 'Layout name must be a string.',
            'max' => 'Layout name may not be greater than :max characters.',
            'unique' => 'This layout name already exists.',
        ],
        'content' => [
            'required' => 'Layout content is required.',
            'array' => 'Layout content must be an array (object).',
        ],
    ],

    // Layout inheritance validation messages
    'layout_inheritance' => [
        // Parent layout validation
        'parent_not_found' => 'Parent layout ":parent" not found.',
        'parent_not_in_same_template' => 'Parent layout must be in the same template.',
        'circular_reference' => 'Circular layout reference detected: :trace',
        'max_depth_exceeded' => 'Layout inheritance depth exceeds maximum allowed depth (:max).',
        'extends_must_be_string' => 'The extends field must be a string.',

        // Slot validation
        'slots_must_be_object' => 'The slots field must be an object.',
        'slot_name_must_be_string' => 'Slot name must be a string.',
        'slot_value_must_be_array' => 'slots[:slotName] must be an array.',
        'slot_not_defined_in_parent' => 'Slot ":slotName" is not defined in parent layout.',
        'parent_has_no_slots' => 'Parent layout has no slots defined.',

        // Data source merge validation
        'data_source_id_duplicate' => 'Duplicate ID ":id" found in data_sources.',
        'data_sources_must_be_array' => 'data_sources must be an array.',
        'data_source_must_have_id' => 'Required field "id" is missing in data_sources[:index].',
        'data_source_id_must_be_string' => 'data_sources[:index].id must be a string.',
    ],

    // Notification channel validation messages
    'notification_channel' => [
        'invalid' => 'The notification channel identifier is invalid.',
        'unavailable' => "Unavailable notification channel: ':value'",
    ],

    // Translatable field validation messages
    'translatable' => [
        'must_be_array' => 'Translatable field must be an array.',
        'unsupported_language' => "Unsupported language code: ':lang'",
        'must_be_string' => "The ':lang' translation must be a string.",
        'max_length' => "The ':lang' translation may not be greater than :max characters.",
        'min_length' => "The ':lang' translation must be at least :min characters.",
        'at_least_one_required' => 'At least one translation is required.',
        'current_locale_required' => 'The :language (:locale) value of the :attribute field is required.',
        'current_locale_required_unnamed' => 'The :language (:locale) value is required.',
    ],

    // Menu validation messages
    'menu' => [
        // Display names of multilingual fields — used as the field name in the required-locale message
        'attributes' => [
            'name' => 'menu name',
        ],
        'roles' => [
            'array' => 'The roles must be an array.',
            'integer' => 'Each role ID must be an integer.',
            'exists' => 'The selected role could not be found.',
        ],
        'name' => [
            'required' => 'Please enter a menu name.',
        ],
        'slug' => [
            'required' => 'Please enter a slug.',
            'unique' => 'This slug is already in use.',
            'max' => 'The slug may not be greater than :max characters.',
        ],
        'url' => [
            'required' => 'The URL is required.',
            'max' => 'The URL may not be greater than :max characters.',
        ],
        'icon' => [
            'max' => 'The icon may not be greater than :max characters.',
        ],
        'order' => [
            'integer' => 'The order must be an integer.',
            'min' => 'The order must be at least :min.',
        ],
        'parent_id' => [
            'exists' => 'The parent menu does not exist.',
        ],
        'is_active' => [
            'boolean' => 'The active status must be a true or false value.',
        ],
        'extension_type' => [
            'in' => 'The extension type must be one of: core, module, plugin.',
        ],
        'extension_identifier' => [
            'max' => 'The extension identifier must not exceed 255 characters.',
            'must_be_string' => 'The extension identifier must be a string.',
            'min_parts' => 'The extension identifier must be in vendor-name format (e.g., sirsoft-board).',
            'empty_part' => 'The extension identifier has an empty part. Hyphens cannot be consecutive or at the edges.',
            'invalid_characters' => 'The extension identifier may only contain lowercase letters, numbers, and underscores (_).',
            'empty_word' => 'The extension identifier has consecutive or edge underscores.',
            'word_starts_with_digit' => 'Each word in the extension identifier must not start with a digit.',
        ],
        'parent_menus' => [
            'required' => 'The parent menu order array is required.',
            'array' => 'The parent menu order must be an array.',
            'min' => 'At least one parent menu is required.',
            'id' => [
                'required' => 'Each parent menu ID is required.',
                'integer' => 'The parent menu ID must be an integer.',
                'exists' => 'Invalid parent menu ID found.',
            ],
            'order' => [
                'required' => 'Each parent menu order is required.',
                'integer' => 'The parent menu order must be an integer.',
                'min' => 'The menu order must be at least 1.',
            ],
        ],
        'child_menus' => [
            'array' => 'The child menu order must be an array.',
            'id' => [
                'required' => 'Each child menu ID is required.',
                'integer' => 'The child menu ID must be an integer.',
                'exists' => 'Invalid child menu ID found.',
            ],
            'order' => [
                'required' => 'Each child menu order is required.',
                'integer' => 'The child menu order must be an integer.',
                'min' => 'The menu order must be at least 1.',
            ],
        ],
        'moved_items' => [
            'array' => 'The moved items must be an array.',
            'id' => [
                'required' => 'Each moved menu ID is required.',
                'integer' => 'The menu ID must be an integer.',
                'exists' => 'Invalid menu ID found.',
            ],
            'new_parent_id' => [
                'integer' => 'The new parent menu ID must be an integer.',
                'exists' => 'Invalid parent menu ID.',
            ],
        ],
    ],

    // Permission validation messages
    'permission' => [
        // Display names of multilingual fields — used as the field name in the required-locale message
        'attributes' => [
            'name' => 'permission name',
            'description' => 'permission description',
        ],
        'name' => [
            'required' => 'Please enter a permission name.',
        ],
        'description' => [
            'max' => 'The permission description may not be greater than :max characters.',
        ],
    ],

    // Role validation messages
    'role' => [
        // Display names of multilingual fields — used as the field name in the required-locale message
        'attributes' => [
            'name' => 'role name',
            'description' => 'role description',
        ],
        'name' => [
            'required' => 'Please enter a role name.',
        ],
        'description' => [
            'max' => 'The role description may not be greater than :max characters.',
        ],
        'menu_ids' => [
            'array' => 'Accessible menus must be an array.',
            'integer' => 'Menu IDs must be numbers.',
            'exists' => 'The selected menu could not be found.',
            'unmanageable' => 'You cannot add menus you are not allowed to manage.',
        ],
    ],

    // Template validation messages
    'template' => [
        'name' => [
            'max' => 'The template name may not be greater than :max characters.',
        ],
        'description' => [
            'max' => 'The template description may not be greater than :max characters.',
        ],
        'metadata' => [
            'array' => 'metadata must be an array.',
        ],
        'status' => [
            'in' => 'status must be either active or inactive.',
        ],
    ],

    // Module validation messages
    'module' => [
        'status' => [
            'in' => 'status must be one of: active, inactive, installed, uninstalled.',
        ],
    ],

    // Plugin validation messages
    'plugin' => [
        'status' => [
            'in' => 'status must be one of: active, inactive, installed, uninstalled.',
        ],
    ],

    // Template path validation messages
    'template_path' => [
        'must_be_string' => 'The template path must be a string.',
        'traversal_detected' => 'Path traversal pattern detected: :pattern',
        'absolute_path_not_allowed' => 'Absolute paths are not allowed.',
        'null_byte_detected' => 'NULL byte attack detected.',
        'outside_base_directory' => 'Paths outside the base directory are not allowed.',
        'file_type_not_allowed' => 'File type not allowed. Extension: :extension (Allowed: :allowed)',
    ],

    // Module path validation messages
    // Extension identifier validation messages (ValidExtensionIdentifier Rule)
    'extension_identifier' => [
        'max' => 'The extension identifier must not exceed 255 characters.',
        'must_be_string' => 'The extension identifier must be a string.',
        'min_parts' => 'The extension identifier must be in vendor-name format (e.g., sirsoft-board).',
        'empty_part' => 'The extension identifier has an empty part. Hyphens cannot be consecutive or at the edges.',
        'invalid_characters' => 'The extension identifier may only contain lowercase letters, numbers, and underscores (_).',
        'empty_word' => 'The extension identifier has consecutive or edge underscores.',
        'word_starts_with_digit' => 'Each word in the extension identifier must not start with a digit.',
    ],

    'module_path' => [
        'file_type_not_allowed' => 'File type not allowed. Extension: :extension (Allowed: :allowed)',
        'must_be_string' => 'The path must be a string.',
        'traversal_detected' => 'Path traversal detected: :pattern',
        'absolute_path_not_allowed' => 'Absolute paths are not allowed.',
        'null_byte_detected' => 'NULL byte detected.',
        'outside_base_directory' => 'Access outside the base directory is not allowed.',
    ],

    // Plugin path validation messages
    'plugin_path' => [
        'file_type_not_allowed' => 'File type not allowed. Extension: :extension (Allowed: :allowed)',
        'must_be_string' => 'The path must be a string.',
        'traversal_detected' => 'Path traversal detected: :pattern',
        'absolute_path_not_allowed' => 'Absolute paths are not allowed.',
        'null_byte_detected' => 'NULL byte detected.',
        'outside_base_directory' => 'Access outside the base directory is not allowed.',
    ],

    // Auth validation messages
    'auth' => [
        'two_factor' => [
            'challenge_required' => 'Verification request is missing. Please sign in again.',
            'challenge_invalid' => 'Verification request is not valid.',
            'code_required' => 'Enter the verification code.',
        ],
        // Sign-in password change step (#736)
        'password_change' => [
            'challenge_required' => 'Password change step is missing. Please sign in again.',
            'challenge_invalid' => 'Password change step is not valid.',
            'password_confirmation_required' => 'Please confirm the new password.',
        ],
        'email' => [
            'required' => 'Email is required.',
            'email' => 'Please enter a valid email address.',
            'exists' => 'This email is not registered.',
            'unique' => 'This email is already in use.',
        ],
        'password' => [
            'required' => 'Password is required.',
            'min' => 'Password must be at least :min characters.',
            'confirmed' => 'Password confirmation does not match.',
        ],
        'name' => [
            'required' => 'Name is required.',
            'min' => 'Name must be at least :min characters.',
            'max' => 'Name may not be greater than :max characters.',
        ],
        'nickname' => [
            'max' => 'Nickname may not be greater than :max characters.',
        ],
        'mobile' => [
            'max' => 'Mobile number may not be greater than :max characters.',
            'regex' => 'Mobile number may only contain digits and -, +, (), spaces.',
        ],
        'phone' => [
            'max' => 'Phone number may not be greater than :max characters.',
            'regex' => 'Phone number may only contain digits and -, +, (), spaces.',
        ],
        'agree_terms' => [
            'accepted' => 'You must agree to the Terms of Service.',
        ],
        'agree_privacy' => [
            'accepted' => 'You must agree to the Privacy Policy.',
        ],
        'token' => [
            'required' => 'Token is required.',
            'string' => 'Token must be a string.',
        ],
    ],

    // Asset validation messages
    'asset' => [
        'identifier' => [
            'required' => 'Identifier is required.',
            'string' => 'Identifier must be a string.',
        ],
        'path' => [
            'required' => 'Path is required.',
            'string' => 'Path must be a string.',
        ],
    ],

    // Setting value validation messages
    'setting' => [
        'value' => [
            'present' => 'The value field must be present in the request.',
            'boolean' => 'This setting only accepts an on/off value.',
            'integer' => 'This setting only accepts an integer.',
            'numeric' => 'This setting only accepts a number.',
            'type' => 'The setting value has an unsupported format.',
            'max' => 'Setting value may not be greater than :max characters.',
            'array_max' => 'Setting value may not be larger than :max characters.',
            'server_only' => 'This setting is recorded by the server and cannot be saved directly.',
        ],
    ],

    // Password composition and reuse rules (only the rules enabled in security settings apply)
    'password_policy' => [
        'char_types' => 'The password must mix at least two of letters, numbers, and special characters.',
        'contains_identifier' => 'The password may not contain the user ID (the part of the email before @).',
        'repeated_chars' => 'The password may not repeat the same character :count or more times in a row.',
        'sequential_chars' => 'The password may not contain :count or more sequential characters (such as 1234, abcd, qwer).',
        'reused' => 'This password was used recently. Your last :count password cannot be reused.|This password was used recently. Your last :count passwords cannot be reused.',
        'same_as_current' => 'Please enter a password different from your current one.',
    ],

    // User validation messages
    'exclude_current_user' => 'The currently logged in user cannot be included in bulk changes.',

    // Hierarchy validation messages
    'not_self_parent' => 'Cannot set self as parent.',
    'not_circular_parent' => 'Cannot move a menu under its own descendant.',

    // Settings validation messages
    'settings' => [
        // General settings - Required fields
        'site_name_required' => 'Please enter the site name.',
        'site_name_max' => 'Site name may not be greater than 100 characters.',
        'site_url_required' => 'Please enter the site URL.',
        'site_url_invalid' => 'Invalid URL format.',
        'site_url_max' => 'Site URL may not be greater than 255 characters.',
        'site_description_max' => 'Site description may not be greater than 500 characters.',
        'admin_email_required' => 'Please enter the admin email.',
        'admin_email_invalid' => 'Invalid email format.',
        'admin_email_max' => 'Admin email may not be greater than 255 characters.',
        'timezone_required' => 'Please select a timezone.',
        'timezone_invalid' => 'Please select a valid timezone.',
        'language_required' => 'Please select a default language.',
        'language_invalid' => 'Please select a valid language.',
        'currency_max' => 'Currency code may not be greater than 10 characters.',
        'maintenance_mode_boolean' => 'Maintenance mode must be true or false.',

        // Security settings
        'force_https_required' => 'Please select the Force HTTPS setting.',
        'force_https_boolean' => 'Force HTTPS must be true or false.',
        'allow_internal_outbound_urls_boolean' => 'Allow internal network address calls must be true or false.',
        // IP access restriction
        'ip_restriction_enabled_boolean' => 'IP access restriction must be true or false.',
        'ip_or_cidr_invalid' => '":value" is not an IP address or CIDR range (e.g. 203.0.113.0/24).',
        'admin_allowed_ips_array' => 'The admin area allowed IPs must be a list.',
        'admin_allowed_ips_max' => 'You can register up to :max admin area allowed IPs.',
        'ip_restriction_rules_array' => 'The route rules must be a list.',
        'ip_restriction_rules_max' => 'You can register up to :max route rules.',
        'ip_restriction_rule_pattern_required' => 'Please enter the URI pattern of the rule.',
        'ip_restriction_rule_pattern_max' => 'The URI pattern of the rule may not be greater than :max characters.',
        'ip_restriction_rule_pattern_invalid' => 'The URI pattern of the rule cannot contain spaces, ? or #. Use a path and * only (e.g. /api/admin/users*).',
        'ip_restriction_rule_ips_required' => 'Please enter at least one allowed IP for each rule.',
        'ip_restriction_rule_ips_max' => 'You can register up to :max allowed IPs per rule.',
        'ip_restriction_rule_memo_max' => 'The rule memo may not be greater than :max characters.',
        'ip_restriction_rule_enabled_boolean' => 'The rule enabled flag must be true or false.',
        'ip_restriction_self_lockout' => 'Your current IP (:ip) is not in the admin area allowed list. Saving now would lock you out of the admin area, including this screen — add your current IP to the list and save again.',
        'ip_restriction_self_lockout_rule' => 'The rule ":pattern" blocks your current IP (:ip). Saving now would lock you out of this screen — add your current IP to that rule or turn the rule off, then save again.',
        'login_attempt_enabled_required' => 'Please select the login attempt limit setting.',
        'login_attempt_enabled_boolean' => 'Login attempt limit must be true or false.',
        'auth_token_lifetime_integer' => 'Auth token lifetime must be an integer.',
        'auth_token_lifetime_min' => 'Auth token lifetime must be at least 0.',
        'auth_token_lifetime_max' => 'Auth token lifetime may not be greater than 3600 minutes (60 hours).',
        'auth_token_lifetime_range' => 'Auth token lifetime must be 0 (unlimited) or between 30 and 3600 minutes.',
        'max_login_attempts_integer' => 'Max login attempts must be an integer.',
        'max_login_attempts_min' => 'Max login attempts must be at least 0.',
        'max_login_attempts_max' => 'Max login attempts may not be greater than 100.',
        'login_lockout_time_integer' => 'Login lockout time must be an integer.',
        'login_lockout_time_min' => 'Login lockout time must be at least 0.',
        'login_lockout_time_max' => 'Login lockout time may not be greater than 1440 minutes (24 hours).',

        // Cache settings
        'cache_enabled_required' => 'Please select the cache enabled setting.',
        'cache_enabled_boolean' => 'Cache enabled must be true or false.',
        'layout_cache_enabled_required' => 'Please select the layout cache setting.',
        'layout_cache_enabled_boolean' => 'Layout cache must be true or false.',
        'layout_cache_ttl_required' => 'Please enter the layout cache TTL.',
        'layout_cache_ttl_integer' => 'Layout cache TTL must be an integer.',
        'layout_cache_ttl_min' => 'Layout cache TTL must be at least 0 seconds.',
        'layout_cache_ttl_max' => 'Layout cache TTL may not be greater than 14400 seconds (4 hours).',
        'stats_cache_enabled_required' => 'Please select the stats cache setting.',
        'stats_cache_enabled_boolean' => 'Stats cache must be true or false.',
        'stats_cache_ttl_required' => 'Please enter the stats cache TTL.',
        'stats_cache_ttl_integer' => 'Stats cache TTL must be an integer.',
        'stats_cache_ttl_min' => 'Stats cache TTL must be at least 0 seconds.',
        'stats_cache_ttl_max' => 'Stats cache TTL may not be greater than 14400 seconds (4 hours).',
        // Advanced tab only — the SEO tab override field (seo_cache_*) allows a different range.
        'advanced_seo_cache_enabled_required' => 'Please select the SEO cache setting.',
        'advanced_seo_cache_enabled_boolean' => 'SEO cache must be true or false.',
        'advanced_seo_cache_ttl_required' => 'Please enter the SEO cache TTL.',
        'advanced_seo_cache_ttl_integer' => 'SEO cache TTL must be an integer.',
        'advanced_seo_cache_ttl_min' => 'SEO cache TTL must be at least 0 seconds.',
        'advanced_seo_cache_ttl_max' => 'SEO cache TTL may not be greater than 14400 seconds (4 hours).',
        'seo_sitemap_cache_ttl_integer' => 'Sitemap cache TTL must be an integer.',
        'seo_sitemap_cache_ttl_min' => 'Sitemap cache TTL must be at least 3600 seconds (1 hour).',
        'seo_sitemap_cache_ttl_max' => 'Sitemap cache TTL may not be greater than 604800 seconds (7 days).',

        // Debug settings
        'debug_mode_required' => 'Please select the debug mode setting.',
        'debug_mode_boolean' => 'Debug mode must be true or false.',
        'sql_query_log_required' => 'Please select the SQL query log setting.',
        'sql_query_log_boolean' => 'SQL query log must be true or false.',

        // Outbound HTTP proxy
        'outbound_proxy_required' => 'Please enter the outbound proxy address.',
        'outbound_proxy_string' => 'The outbound proxy address must be a string.',
        'outbound_proxy_max' => 'The outbound proxy address may not be greater than :max characters.',
        'outbound_proxy_invalid' => 'The outbound proxy address format is invalid. Supported schemes: :schemes (e.g. socks5h://127.0.0.1:1080)',
        'outbound_proxy_bypass_array' => 'The proxy bypass list must be an array.',
        'outbound_proxy_bypass_item_string' => 'Each proxy bypass entry must be a string.',
        'outbound_proxy_bypass_item_max' => 'Each proxy bypass entry may not be greater than :max characters.',

        // List limits
        'pagination_result_cap_integer' => 'The total count cap must be a number.',
        'pagination_result_cap_min' => 'The total count cap must be at least :min. (0 = unlimited)',
        'pagination_result_cap_max' => 'The total count cap may not be greater than :max.',
        'pagination_max_page_integer' => 'The maximum page number must be a number.',
        'pagination_max_page_min' => 'The maximum page number must be at least :min. (0 = unlimited)',
        'pagination_max_page_max' => 'The maximum page number may not be greater than :max.',

        // Core update settings
        'core_update_github_url_invalid' => 'The GitHub repository URL format is invalid.',
        'core_update_github_url_max' => 'The GitHub repository URL may not be greater than 500 characters.',
        'core_update_github_token_max' => 'The GitHub access token may not be greater than 500 characters.',

        // Mail settings
        'mailer_required' => 'Please select a mailer.',
        'mailer_invalid' => 'Please select a valid mailer.',
        'host_required' => 'Please enter the SMTP host.',
        'host_max' => 'SMTP host may not be greater than 255 characters.',
        'port_required' => 'Please enter the port number.',
        'port_integer' => 'SMTP port must be an integer.',
        'port_min' => 'SMTP port must be at least 1.',
        'port_max' => 'SMTP port may not be greater than 65535.',
        'username_max' => 'SMTP username may not be greater than 255 characters.',
        'password_max' => 'SMTP password may not be greater than 255 characters.',
        'encryption_invalid' => 'Please select a valid encryption type.',
        'from_address_required' => 'Please enter the sender email address.',
        'from_address_invalid' => 'Please enter a valid sender email address.',
        'from_address_max' => 'Sender email address may not be greater than 255 characters.',
        'from_name_required' => 'Please enter the sender name.',
        'from_name_max' => 'Sender name may not be greater than 255 characters.',

        // Upload settings
        'max_file_size_required' => 'Please enter the max file size.',
        'max_file_size_integer' => 'Max file size must be an integer.',
        'max_file_size_min' => 'Max file size must be at least 1MB.',
        'max_file_size_max' => 'Max file size may not be greater than 1024MB.',
        'allowed_extensions_required' => 'Please enter the allowed extensions.',
        'allowed_extensions_max' => 'Allowed extensions may not be greater than 500 characters.',
        'allowed_extensions_invalid_type' => 'Allowed extensions must be a string or an array.',
        'image_max_width_integer' => 'Max image width must be an integer.',
        'image_max_width_min' => 'Max image width must be at least 100 pixels.',
        'image_max_width_max' => 'Max image width may not be greater than 10000 pixels.',
        'image_max_height_integer' => 'Max image height must be an integer.',
        'image_max_height_min' => 'Max image height must be at least 100 pixels.',
        'image_max_height_max' => 'Max image height may not be greater than 10000 pixels.',
        'image_quality_integer' => 'Image quality must be an integer.',
        'image_quality_min' => 'Image quality must be at least 1.',
        'image_quality_max' => 'Image quality may not be greater than 100.',
        'orphan_cleanup_enabled_boolean' => 'Orphan attachment cleanup must be enabled or disabled.',
        'orphan_retention_days_integer' => 'Orphan attachment retention period must be an integer.',
        'orphan_retention_days_min' => 'Orphan attachment retention period must be at least 1 day.',
        'orphan_retention_days_max' => 'Orphan attachment retention period may not be greater than 3650 days.',

        // SEO settings
        'meta_title_suffix_max' => 'Meta title suffix may not be greater than 100 characters.',
        'meta_description_max' => 'Meta description may not be greater than 160 characters.',
        'meta_keywords_max' => 'Meta keywords may not be greater than 255 characters.',
        'google_analytics_id_max' => 'Google Analytics ID may not be greater than 50 characters.',
        'google_site_verification_max' => 'Google site verification code may not be greater than 100 characters.',
        'naver_site_verification_max' => 'Naver site verification code may not be greater than 100 characters.',
        'generator_enabled_boolean' => 'Generator tag toggle must be true or false.',
        'generator_content_string' => 'Generator content must be a string.',
        'generator_content_max' => 'Generator content may not be greater than 200 characters.',
        'bot_user_agents_array' => 'Bot user agents must be an array.',
        'bot_user_agents_item_string' => 'Each bot user agent must be a string.',
        'bot_user_agents_item_max' => 'Each bot user agent may not be greater than 100 characters.',
        'bot_detection_enabled_boolean' => 'Bot detection setting must be true or false.',
        'bot_detection_library_enabled_boolean' => 'Bot detection library setting must be true or false.',
        'og_default_site_name_string' => 'OG site name default must be a string.',
        'og_default_site_name_max' => 'OG site name default may not exceed 200 characters.',
        'og_image_default_width_integer' => 'OG image default width must be an integer.',
        'og_image_default_width_min' => 'OG image default width must be 0 or greater.',
        'og_image_default_width_max' => 'OG image default width may not exceed 8000.',
        'og_image_default_height_integer' => 'OG image default height must be an integer.',
        'og_image_default_height_min' => 'OG image default height must be 0 or greater.',
        'og_image_default_height_max' => 'OG image default height may not exceed 8000.',
        'twitter_default_card_string' => 'Twitter card default must be a string.',
        'twitter_default_card_in' => 'Twitter card default must be one of summary, summary_large_image, app, player.',
        'twitter_default_site_string' => 'Twitter site handle must be a string.',
        'twitter_default_site_max' => 'Twitter site handle may not exceed 50 characters.',
        'seo_cache_enabled_boolean' => 'SEO cache setting must be true or false.',
        'seo_cache_ttl_integer' => 'SEO cache TTL must be an integer.',
        'seo_cache_ttl_min' => 'SEO cache TTL must be at least 60 seconds.',
        'seo_cache_ttl_max' => 'SEO cache TTL may not be greater than 86400 seconds (24 hours).',
        'sitemap_enabled_boolean' => 'Sitemap generation setting must be true or false.',
        'sitemap_cache_ttl_integer' => 'Sitemap cache TTL must be an integer.',
        'sitemap_cache_ttl_min' => 'Sitemap cache TTL must be at least 3600 seconds (1 hour).',
        'sitemap_cache_ttl_max' => 'Sitemap cache TTL may not be greater than 604800 seconds (7 days).',
        'sitemap_urls_per_file_integer' => 'URLs per sitemap file must be an integer.',
        'sitemap_urls_per_file_min' => 'URLs per sitemap file must be at least 1000.',
        'sitemap_urls_per_file_max' => 'URLs per sitemap file may not be greater than 50000.',
        'sitemap_gzip_boolean' => 'Sitemap compression setting must be true or false.',
        'sitemap_serve_stale_on_miss_boolean' => 'Serving the previous sitemap setting must be true or false.',
        'sitemap_max_urls_per_contributor_integer' => 'Maximum URLs per contributor must be an integer.',
        'sitemap_max_urls_per_contributor_min' => 'Maximum URLs per contributor must be at least 0.',
        'sitemap_hreflang_enabled_boolean' => 'The sitemap hreflang alternate links setting must be true or false.',
        'sitemap_schedule_invalid' => 'Please select a valid sitemap generation schedule.',
        'sitemap_schedule_time_invalid' => 'Sitemap generation time must be a valid HH:mm time between 00:00 and 23:59.',

        // Misc
        'session_lifetime_integer' => 'Session lifetime must be an integer.',
        'session_lifetime_min' => 'Session lifetime must be at least 1 minute.',
        'session_lifetime_max' => 'Session lifetime may not be greater than 525600 minutes (1 year).',

        // Driver settings - Storage
        'storage_driver_required' => 'Please select a storage driver.',
        'storage_driver_invalid' => 'Please select a valid storage driver.',
        's3_bucket_required' => 'S3 bucket name is required.',
        's3_bucket_max' => 'S3 bucket name may not be greater than 255 characters.',
        's3_region_invalid' => 'S3 region may only contain lowercase letters, numbers, and hyphens. (e.g. ap-northeast-2, or auto for Cloudflare R2)',
        's3_region_max' => 'S3 region may not be greater than 64 characters.',
        's3_access_key_required' => 'S3 access key is required.',
        's3_access_key_max' => 'S3 access key may not be greater than 255 characters.',
        's3_secret_key_required' => 'S3 secret key is required.',
        's3_secret_key_max' => 'S3 secret key may not be greater than 255 characters.',
        's3_url_url' => 'S3 URL must be a valid URL.',
        's3_url_invalid' => 'S3 URL is not a valid URL format.',
        's3_url_max' => 'S3 URL may not be greater than 500 characters.',
        's3_endpoint_invalid' => 'S3 endpoint is not a valid URL format.',
        's3_endpoint_max' => 'S3 endpoint may not be greater than 500 characters.',
        's3_use_path_style_boolean' => 'Path-style addressing must be true or false.',
        'driver_unusable' => "The ':driver' driver cannot be used on this server. :reason",

        // Driver settings - Cache
        'cache_driver_required' => 'Please select a cache driver.',
        'cache_driver_invalid' => 'Please select a valid cache driver.',
        'redis_host_required' => 'Redis host is required.',
        'redis_host_max' => 'Redis host may not be greater than 255 characters.',
        'redis_port_required' => 'Redis port is required.',
        'redis_port_integer' => 'Redis port must be an integer.',
        'redis_port_min' => 'Redis port must be at least 1.',
        'redis_port_max' => 'Redis port may not be greater than 65535.',
        'redis_password_max' => 'Redis password may not be greater than 255 characters.',
        'redis_database_required' => 'Redis database number is required.',
        'redis_database_integer' => 'Redis database must be an integer.',
        'redis_database_min' => 'Redis database must be at least 0.',
        'redis_database_max' => 'Redis database may not be greater than 15.',
        'memcached_host_required' => 'Memcached host is required.',
        'memcached_host_max' => 'Memcached host may not be greater than 255 characters.',
        'memcached_port_required' => 'Memcached port is required.',
        'memcached_port_integer' => 'Memcached port must be an integer.',
        'memcached_port_min' => 'Memcached port must be at least 1.',
        'memcached_port_max' => 'Memcached port may not be greater than 65535.',

        // Driver settings - Session
        'session_driver_required' => 'Please select a session driver.',
        'session_driver_invalid' => 'Please select a valid session driver.',
        'session_lifetime_required' => 'Session lifetime is required.',

        // Driver settings - Queue
        'queue_driver_required' => 'Please select a queue driver.',
        'queue_driver_invalid' => 'Please select a valid queue driver.',

        // Driver settings - WebSocket
        'websocket_enabled_boolean' => 'WebSocket enabled must be true or false.',
        'websocket_app_id_required' => 'WebSocket app ID is required when WebSocket is enabled.',
        'websocket_app_id_max' => 'WebSocket app ID may not be greater than 255 characters.',
        'websocket_app_key_required' => 'WebSocket app key is required when WebSocket is enabled.',
        'websocket_app_key_max' => 'WebSocket app key may not be greater than 255 characters.',
        'websocket_app_secret_required' => 'WebSocket app secret is required when WebSocket is enabled.',
        'websocket_app_secret_max' => 'WebSocket app secret may not be greater than 255 characters.',
        'websocket_host_required' => 'WebSocket host is required.',
        'websocket_host_max' => 'WebSocket host may not be greater than 255 characters.',
        'websocket_port_required' => 'WebSocket port is required.',
        'websocket_port_integer' => 'WebSocket port must be an integer.',
        'websocket_port_min' => 'WebSocket port must be at least 1.',
        'websocket_port_max' => 'WebSocket port may not be greater than 65535.',
        'websocket_scheme_required' => 'Please select a WebSocket scheme.',
        'websocket_scheme_invalid' => 'Please select a valid WebSocket scheme.',
        'websocket_verify_ssl_boolean' => 'WebSocket SSL verification must be true or false.',
        'websocket_server_host_max' => 'WebSocket server host may not be greater than 255 characters.',
        'websocket_server_port_integer' => 'WebSocket server port must be an integer.',
        'websocket_server_port_min' => 'WebSocket server port must be at least 1.',
        'websocket_server_port_max' => 'WebSocket server port may not be greater than 65535.',
        'websocket_server_scheme_invalid' => 'Please select a valid WebSocket server scheme.',
        'search_engine_driver_invalid' => 'Please select a valid search engine driver.',

        // Log driver settings
        'log_driver_required' => 'Please select a log driver.',
        'log_driver_invalid' => 'Please select a valid log driver.',
        'log_level_required' => 'Please select a log level.',
        'log_level_invalid' => 'Please select a valid log level.',
        'log_days_integer' => 'Log retention days must be an integer.',
        'log_days_min' => 'Log retention days must be at least 1.',
        'log_days_max' => 'Log retention days may not be greater than 365.',

        // Public asset disk settings
        'public_asset_disk_invalid' => 'Please select a valid public asset disk.',

        // Identity verification (IDV) settings
        'identity_default_provider_string' => 'Default provider must be a string.',
        'identity_default_provider_max' => 'Default provider identifier may not be longer than 100 characters.',
        'identity_purpose_providers_array' => 'Purpose-to-provider mapping must be an array.',
        'identity_purpose_provider_string' => 'Purpose provider identifier must be a string.',
        'identity_purpose_provider_max' => 'Purpose provider identifier may not be longer than 100 characters.',
        'identity_challenge_ttl_required' => 'Please enter the challenge TTL.',
        'identity_challenge_ttl_integer' => 'Challenge TTL must be an integer.',
        'identity_challenge_ttl_min' => 'Challenge TTL must be at least 1 minute.',
        'identity_challenge_ttl_max' => 'Challenge TTL may not be greater than 1440 minutes (24 hours).',
        'identity_max_attempts_required' => 'Please enter the maximum number of attempts.',
        'identity_max_attempts_integer' => 'Maximum attempts must be an integer.',
        'identity_max_attempts_min' => 'Maximum attempts must be at least 1.',
        'identity_max_attempts_max' => 'Maximum attempts may not be greater than 20.',

        // Privacy retention / dormant accounts
        'privacy_days_integer' => 'The period must be an integer number of days.',
        'privacy_retention_days_min' => 'The retention period must be at least 1 day.',
        'privacy_retention_days_max' => 'The retention period may not be greater than 3650 days (10 years).',
        'privacy_dormant_days_min' => 'The dormancy period must be at least 30 days.',
        'privacy_dormant_notice_days_min' => 'The advance notice period must be at least 1 day.',
        'privacy_dormant_notice_days_max' => 'The advance notice period may not be greater than 365 days.',
        'privacy_dormant_notice_days_lt_dormant_days' => 'The advance notice period must be shorter than the dormancy period. If it is equal or longer, the notice and the transition happen on the same day, leaving no advance notice at all.',
        // Password policy (#736)
        'password_policy_boolean' => 'The :attribute must be on or off.',
        'password_policy_integer' => 'The :attribute must be an integer.',
        'password_policy_min' => 'The :attribute must be at least :min.',
        'password_policy_max' => 'The :attribute may not be greater than :max.',
        'activity_log_retention_days_min' => 'The :attribute must be at least the legal minimum of :min days.',
        'password_expiry_scope_in' => 'The scope must be all members, administrators only, or selected roles and members.',
        'password_expiry_action_in' => 'The expiry action must be either require change to sign in or allow changing later.',
        'password_expiry_notice_days_lt_expiry_days' => 'The advance notice must be shorter than the expiry period. If it is equal or longer, the notice and the expiry happen on the same day, leaving no advance notice at all.',
        'privacy_consent_provider_unknown' => 'The selected consent management provider could not be found. Choose one from the list again.',
    ],

    // Identity verification policy validation messages
    'identity_policy' => [
        'key_required' => 'Please enter the policy key.',
        'key_max' => 'Policy key may not be longer than 120 characters.',
        'key_unique' => 'This policy key is already in use.',
        'scope_required' => 'Please select a scope.',
        'scope_invalid' => 'Scope must be one of route, hook, custom.',
        'target_required' => 'Please enter the target (route or hook name).',
        'target_max' => 'Target may not be longer than 255 characters.',
        'purpose_required' => 'Please select a purpose.',
        'purpose_max' => 'Purpose may not be longer than 64 characters.',
        'provider_id_max' => 'Provider identifier may not be longer than 64 characters.',
        'grace_minutes_required' => 'Please enter the grace minutes.',
        'grace_minutes_integer' => 'Grace minutes must be an integer.',
        'grace_minutes_min' => 'Grace minutes must be at least 0.',
        'grace_minutes_max' => 'Grace minutes may not be greater than 43200 (30 days).',
        'enabled_boolean' => 'Enabled must be true or false.',
        'priority_integer' => 'Priority must be an integer.',
        'priority_min' => 'Priority must be at least 0.',
        'priority_max' => 'Priority may not be greater than 65535.',
        'priority_duplicate' => 'An active policy with priority :priority already exists for the same location (:target). Since the order of enforcement would be undefined, please use a different priority or disable the existing policy.',
        'conditions_array' => 'Conditions must be an array.',
        'applies_to_required' => 'Please select applies-to.',
        'applies_to_invalid' => 'Applies-to must be one of self, admin, both.',
        'fail_mode_required' => 'Please select a fail mode.',
        'fail_mode_invalid' => 'Fail mode must be block or log_only.',
    ],

    // Identity notifications — Notification settings > Identity "Add policy message"
    'identity_notification' => [
        'policy_key_not_admin_policy' => 'Select the key of an identity policy added by an administrator.',
        'definition_already_exists' => 'This policy already has an identity verification message.',
        'attributes' => [
            'category' => 'category',
            'policy_key' => 'policy',
            'description' => 'description',
            'templates' => 'mail template',
            'name' => 'message name',
            'subject' => 'subject',
            'body' => 'body',
        ],
    ],

    // Layout version list
    'layout_version' => [
        'limit' => [
            'integer' => 'The limit must be a number.',
            'min' => 'The limit must be at least 1.',
            'max' => 'The limit may not be greater than :max.',
        ],
    ],

    // Privacy target list
    'privacy_targets' => [
        'page' => [
            'integer' => 'The page number must be a number.',
            'min' => 'The page number must be at least 1.',
        ],
        'per_page' => [
            'integer' => 'The page size must be a number.',
            'min' => 'The page size must be at least 1.',
            'max' => 'The page size may not exceed :max.',
        ],
    ],

    'privacy_manual_run' => [
        'since' => [
            'date' => 'The reference time is not a valid date.',
        ],
        'skip_waiting' => [
            'boolean' => 'The skip-waiting value must be true or false.',
        ],
        'targets' => [
            'required' => 'Specify the targets to look up.',
            'string' => 'The target list must be a comma-separated string.',
            'max' => 'The target list is too long (max :max characters).',
        ],
    ],

    // Validation attribute names (validation.attributes)
    // Analytics beacon validation messages
    'analytics' => [
        'type_required' => 'An action type is required.',
        'type_invalid' => 'Unknown action type.',
        'url_required' => 'A visit URL is required.',
        'url_too_long' => 'The visit URL is too long.',
        'resolution_invalid' => 'The screen resolution format is invalid.',
        'dimensions_too_many' => 'At most 20 breakdown values may be sent.',
        'range_too_long' => 'You can query at most :days days at a time.',
        'report_unknown' => 'Unknown report.',
        'subject_required' => 'Specify at least one of IP address, member id or visitor key.',
        'confirm_required' => 'This cannot be undone — tick the confirmation box to proceed.',
        'anonymize_target_required' => 'Specify either a mask octet count or hashing.',
        'bucket_bounds' => 'Bucket bounds must be comma-separated integers between :min and :max, in increasing order without duplicates.',
    ],

    // 검증 실패 요약(422 응답 message)의 나머지 오류 수 꼬리 — Laravel 원문 「(and :count more error)」(단수)와 「…errors)」(복수)를 대신한다
    'summary_more_error' => '(and :count more error)',
    'summary_more_errors' => '(and :count more errors)',

    'attributes' => [
        'since' => 'reference time',
        'skip_waiting' => 'skip waiting period',
        'targets' => 'targets',
        'page' => 'page number',
        'per_page' => 'page size',
        'ids' => 'ID list',
        'user_ids' => 'user ID list',
        'user_id' => 'user ID',
        'status' => 'status',
        'menu_ids' => 'menu list',
        'role_ids' => 'role list',
        'mode' => 'action',
        'uuids' => 'member list',
        // Settings fields
        'site_name' => 'site name',
        'site_url' => 'site URL',
        'site_description' => 'site description',
        'admin_email' => 'admin email',
        'timezone' => 'timezone',
        'language' => 'language',
        'default_language' => 'default language',
        // Identity verification (IDV) fields
        'identity_default_provider' => 'default provider',
        'identity_purpose_providers' => 'purpose-to-provider mapping',
        'identity_challenge_ttl_minutes' => 'challenge TTL',
        'identity_max_attempts' => 'maximum attempts',
        'privacy_consent_provider' => 'consent management provider',
        'privacy_withdrawn_retention_enabled' => 'withdrawn member data erasure',
        'privacy_withdrawn_retention_days' => 'withdrawn member data retention period',
        'privacy_dormant_enabled' => 'dormant account transition',
        'privacy_dormant_days' => 'dormancy period',
        'privacy_dormant_notice_days' => 'dormancy advance notice period',
        'privacy_dormant_withdraw_enabled' => 'automatic withdrawal of dormant accounts',
        'privacy_dormant_withdraw_days' => 'period before withdrawing a dormant account',
        // Identity verification policy fields
        'identity_policy_key' => 'policy key',
        'identity_policy_scope' => 'scope',
        'identity_policy_target' => 'target',
        'identity_policy_purpose' => 'purpose',
        'identity_policy_provider_id' => 'provider',
        'identity_policy_grace_minutes' => 'grace minutes',
        'identity_policy_enabled' => 'enabled',
        'identity_policy_applies_to' => 'applies to',
        'identity_policy_fail_mode' => 'fail mode',
        // Mail settings
        'mailer' => 'mailer',
        'host' => 'host',
        'port' => 'port',
        'username' => 'username',
        'password' => 'password',
        'smtp_host' => 'SMTP host',
        'smtp_port' => 'SMTP port',
        'smtp_username' => 'SMTP username',
        'smtp_password' => 'SMTP password',
        'encryption' => 'encryption',
        'from_address' => 'sender email',
        'from_name' => 'sender name',
        // Upload settings
        'max_file_size' => 'max file size',
        'allowed_extensions' => 'allowed extensions',
        'image_max_width' => 'max image width',
        'image_max_height' => 'max image height',
        'image_quality' => 'image quality',
        'orphan_cleanup_enabled' => 'orphan attachment cleanup',
        'orphan_retention_days' => 'orphan attachment retention period',
        // SEO settings
        'meta_title_suffix' => 'meta title suffix',
        'meta_description' => 'meta description',
        'meta_keywords' => 'meta keywords',
        'google_analytics_id' => 'Google Analytics ID',
        'google_site_verification' => 'Google site verification',
        'naver_site_verification' => 'Naver site verification',
        // Driver settings
        'storage_driver' => 'storage driver',
        's3_bucket' => 'S3 bucket',
        's3_region' => 'S3 region',
        's3_access_key' => 'S3 access key',
        's3_secret_key' => 'S3 secret key',
        's3_url' => 'S3 URL',
        's3_endpoint' => 'S3 endpoint URL',
        's3_use_path_style' => 'path-style addressing',
        'cache_driver' => 'cache driver',
        'redis_host' => 'Redis host',
        'redis_port' => 'Redis port',
        'redis_password' => 'Redis password',
        'redis_database' => 'Redis database',
        'memcached_host' => 'Memcached host',
        'memcached_port' => 'Memcached port',
        'session_driver' => 'session driver',
        'session_lifetime' => 'session lifetime',
        'queue_driver' => 'queue driver',
        'websocket_enabled' => 'WebSocket enabled',
        'websocket_app_key' => 'WebSocket app key',
        'websocket_host' => 'WebSocket host',
        'websocket_port' => 'WebSocket port',
        'websocket_scheme' => 'WebSocket scheme',
        // Changelog fields
        'from_version' => 'start version',
        'to_version' => 'end version',
        'layout' => 'layout',
        'module' => 'module',
        'token' => 'token',
        'claim_secret' => 'verification claim value',
        'mailgun_domain' => 'Mailgun domain',
        'mailgun_secret' => 'Mailgun secret',
        'ses_key' => 'SES access key',
        'ses_secret' => 'SES secret key',
        'ses_region' => 'SES region',
        'mailgun_endpoint' => 'Mailgun endpoint',
        // General settings (additional)
        'channels' => 'channels',
        'notification_channels' => 'notification channels',
        // Settings tab containers — used when the tab payload itself is malformed.
        'settings_tab' => 'settings tab',
        'settings_tab_general' => 'general settings',
        'settings_tab_mail' => 'mail settings',
        'settings_tab_upload' => 'upload settings',
        'settings_tab_seo' => 'SEO settings',
        'settings_tab_security' => 'security settings',
        'settings_tab_drivers' => 'driver settings',
        'settings_tab_advanced' => 'advanced settings',
        'settings_tab_notifications' => 'notification settings',
        'settings_tab_identity' => 'identity verification settings',
        'settings_tab_analytics' => 'visitor analytics settings',
        'settings_tab_privacy' => 'privacy settings',
        'notification_channel_id' => 'notification channel id',
        'notification_channel_is_active' => 'notification channel active flag',
        'notification_channel_sort_order' => 'notification channel sort order',
        'currency' => 'currency',
        'default_currency' => 'default currency',
        'maintenance_mode' => 'maintenance mode',
        'asset_url_mode' => 'asset URL mode',
        'site_logo' => 'site logo',
        'og_image_default' => 'default share image',
        // SEO settings (additional)
        'bot_user_agents' => 'bot user agents',
        'bot_detection_enabled' => 'bot detection',
        'bot_detection_library_enabled' => 'bot detection library',
        'og_default_site_name' => 'OG default site name',
        'og_image_default_width' => 'OG image default width',
        'og_image_default_height' => 'OG image default height',
        'twitter_default_card' => 'Twitter default card type',
        'twitter_default_site' => 'Twitter default account',
        'seo_page_cache_enabled' => 'SEO page cache',
        'cache_ttl' => 'cache lifetime',
        'seo_page_cache_ttl' => 'SEO page cache lifetime',
        'sitemap_enabled' => 'sitemap',
        'sitemap_cache_ttl' => 'sitemap cache lifetime',
        'sitemap_urls_per_file' => 'sitemap URLs per file',
        'sitemap_gzip' => 'sitemap compression',
        'sitemap_serve_stale_on_miss' => 'serve stale sitemap on miss',
        'sitemap_max_urls_per_contributor' => 'sitemap max URLs per contributor',
        'sitemap_hreflang_enabled' => 'sitemap hreflang links',
        'sitemap_schedule' => 'sitemap schedule',
        'sitemap_schedule_time' => 'sitemap schedule time',
        'generator_enabled' => 'SEO page generator',
        'generator_content' => 'SEO page generator content',
        // Security settings (additional)
        'force_https' => 'force HTTPS',
        'login_attempt_enabled' => 'login attempt limit',
        'auth_token_lifetime' => 'auth token lifetime',
        'max_login_attempts' => 'max login attempts',
        'login_lockout_time' => 'login lockout time',
        'password_min_length' => 'minimum password length',
        'require_password_special_char' => 'require special character in password',
        'password_require_char_types' => 'require two or more character types',
        'password_block_identifier' => 'block ID in password',
        'password_block_repeated_chars' => 'block repeated characters',
        'password_repeated_chars_length' => 'repeated character length',
        'password_block_sequential_chars' => 'block sequential characters',
        'password_sequential_chars_length' => 'sequential character length',
        'password_reuse_prevention_enabled' => 'password reuse prevention',
        'password_reuse_count' => 'number of passwords that cannot be reused',
        'password_expiry_enabled' => 'password expiry',
        'password_expiry_days' => 'password expiry period',
        'password_expiry_notice_days' => 'expiry advance notice days',
        'password_expiry_scope' => 'password expiry scope',
        'password_expiry_action' => 'password expiry action',
        'password_expiry_remind_days' => 'change postponement period',
        'password_expiry_batch_size' => 'notices per run limit',
        'activity_log_retention_days' => 'activity log retention period',
        'password_expiry_policy' => 'password expiry',
        'current_password' => 'current password',
        'password_confirmation' => 'password confirmation',
        'challenge_id' => 'password change step ID',
        'two_factor_auth' => 'two-factor authentication',
        'two_factor_scope' => 'two-factor authentication scope',
        'allow_internal_outbound_urls' => 'allow internal outbound URLs',
        'ip_restriction_enabled' => 'IP access restriction',
        'admin_allowed_ips' => 'admin area allowed IPs',
        'ip_restriction_rules' => 'route access rules',
        'ip_restriction_rule_pattern' => 'rule URI pattern',
        'ip_restriction_rule_ips' => 'rule allowed IPs',
        'ip_restriction_rule_memo' => 'rule memo',
        'ip_restriction_rule_enabled' => 'rule enabled',
        // Advanced settings (additional)
        'advanced_cache_enabled' => 'cache',
        'layout_cache_enabled' => 'layout cache',
        'layout_cache_ttl' => 'layout cache lifetime',
        'stats_cache_enabled' => 'statistics cache',
        'stats_cache_ttl' => 'statistics cache lifetime',
        'seo_cache_enabled' => 'SEO cache',
        'seo_cache_ttl' => 'SEO cache lifetime',
        'seo_sitemap_cache_ttl' => 'SEO sitemap cache lifetime',
        'debug_mode' => 'debug mode',
        'sql_query_log' => 'SQL query log',
        'outbound_proxy' => 'outbound proxy address',
        'outbound_proxy_bypass' => 'proxy bypass list',
        'core_update_github_url' => 'core update GitHub URL',
        'core_update_github_token' => 'core update GitHub token',
        'geoip_enabled' => 'GeoIP',
        'geoip_license_key' => 'GeoIP license key',
        'geoip_auto_update_enabled' => 'GeoIP auto update',
        'pagination_result_cap' => 'list total count cap',
        'pagination_max_page' => 'list maximum page number',
        // Driver settings (additional)
        'websocket_app_id' => 'WebSocket app ID',
        'websocket_app_secret' => 'WebSocket app secret',
        'websocket_verify_ssl' => 'WebSocket SSL verification',
        'websocket_server_host' => 'WebSocket server host',
        'websocket_server_port' => 'WebSocket server port',
        'websocket_server_scheme' => 'WebSocket server scheme',
        'search_engine_driver' => 'search engine driver',
        'log_driver' => 'log driver',
        'log_level' => 'log level',
        'log_days' => 'log retention days',
        'public_asset_disk' => 'public asset disk',
    ],
];
