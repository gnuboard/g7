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
    'decimal' => 'The :attribute field must have :decimal decimal places.',
    'declined' => 'The :attribute field must be declined.',
    'declined_if' => 'The :attribute field must be declined when :other is :value.',
    'different' => 'The :attribute field and :other must be different.',
    'digits' => 'The :attribute field must be :digits digits.',
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
        'array' => 'The :attribute field must have more than :value items.',
        'file' => 'The :attribute field must be greater than :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than :value.',
        'string' => 'The :attribute field must be greater than :value characters.',
    ],
    'gte' => [
        'array' => 'The :attribute field must have :value items or more.',
        'file' => 'The :attribute field must be greater than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than or equal to :value.',
        'string' => 'The :attribute field must be greater than or equal to :value characters.',
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
        'array' => 'The :attribute field must have less than :value items.',
        'file' => 'The :attribute field must be less than :value kilobytes.',
        'numeric' => 'The :attribute field must be less than :value.',
        'string' => 'The :attribute field must be less than :value characters.',
    ],
    'lte' => [
        'array' => 'The :attribute field must not have more than :value items.',
        'file' => 'The :attribute field must be less than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be less than or equal to :value.',
        'string' => 'The :attribute field must be less than or equal to :value characters.',
    ],
    'mac_address' => 'The :attribute field must be a valid MAC address.',
    'max' => [
        'array' => 'The :attribute field must not have more than :max items.',
        'file' => 'The :attribute field must not be greater than :max kilobytes.',
        'numeric' => 'The :attribute field must not be greater than :max.',
        'string' => 'The :attribute field must not be greater than :max characters.',
    ],
    'max_digits' => 'The :attribute field must not have more than :max digits.',
    'mimes' => 'The :attribute field must be a file of type: :values.',
    'mimetypes' => 'The :attribute field must be a file of type: :values.',
    'min' => [
        'array' => 'The :attribute field must have at least :min items.',
        'file' => 'The :attribute field must be at least :min kilobytes.',
        'numeric' => 'The :attribute field must be at least :min.',
        'string' => 'The :attribute field must be at least :min characters.',
    ],
    'min_digits' => 'The :attribute field must have at least :min digits.',
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
        'array' => 'The :attribute field must contain :size items.',
        'file' => 'The :attribute field must be :size kilobytes.',
        'numeric' => 'The :attribute field must be :size.',
        'string' => 'The :attribute field must be :size characters.',
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

    // Layout structure validation messages
    'layout' => [
        'invalid_json' => 'Invalid JSON format.',
        'must_be_array' => 'Layout data must be an array.',
        'required_field_missing' => "Required field ':field' is missing.",
        'version_must_be_string' => 'The version field must be a string.',
        'layout_name_must_be_string' => 'The layout_name field must be a string.',
        'components_must_be_array' => 'The components field must be an array.',
        'components_or_slots_required' => 'Inherited layouts require either components or slots.',
        'component_must_be_array' => 'components[:index] must be an array.',
        'max_depth_exceeded' => 'Component nesting depth exceeds maximum allowed depth (:max).',
        'component_required_field_missing' => "Required field ':field' is missing in components[:index].",
        'component_field_must_be_string' => 'components[:index].component must be a string.',
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
            'auth_required' => [
                'boolean' => 'The meta.auth_required must be a boolean.',
            ],
            'is_base' => [
                'boolean' => 'The meta.is_base must be a boolean.',
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

    // Translatable field validation messages
    'translatable' => [
        'must_be_array' => 'Translatable field must be an array.',
        'unsupported_language' => "Unsupported language code: ':lang'",
        'must_be_string' => "The ':lang' translation must be a string.",
        'max_length' => "The ':lang' translation may not be greater than :max characters.",
        'min_length' => "The ':lang' translation must be at least :min characters.",
        'at_least_one_required' => 'At least one translation is required.',
        'current_locale_required' => 'The :locale language value is required.',
    ],

    // Template validation messages
    'template' => [
        'type' => [
            'in' => 'The type parameter must be either user or admin.',
        ],
        'description' => [
            'string' => 'The template description must be a string.',
            'max' => 'The template description may not be greater than :max characters.',
        ],
        'metadata' => [
            'array' => 'metadata must be an array.',
        ],
        'status' => [
            'in' => 'status must be either active or inactive.',
        ],
    ],

    // Menu validation messages
    'menu' => [
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
        'name' => [
            'required' => 'Please enter a permission name.',
        ],
        'description' => [
            'max' => 'The permission description may not be greater than :max characters.',
        ],
    ],

    // Role validation messages
    'role' => [
        'name' => [
            'required' => 'Please enter a role name.',
        ],
        'description' => [
            'max' => 'The role description may not be greater than :max characters.',
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
    'module_path' => [
        'must_be_string' => 'The path must be a string.',
        'traversal_detected' => 'Path traversal detected: :pattern',
        'absolute_path_not_allowed' => 'Absolute paths are not allowed.',
        'null_byte_detected' => 'NULL byte detected.',
        'outside_base_directory' => 'Access outside the base directory is not allowed.',
    ],

    // Plugin path validation messages
    'plugin_path' => [
        'must_be_string' => 'The path must be a string.',
        'traversal_detected' => 'Path traversal detected: :pattern',
        'absolute_path_not_allowed' => 'Absolute paths are not allowed.',
        'null_byte_detected' => 'NULL byte detected.',
        'outside_base_directory' => 'Access outside the base directory is not allowed.',
    ],

    // Auth validation messages
    'auth' => [
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
            'required' => 'Setting value is required.',
            'string' => 'Setting value must be a string.',
            'max' => 'Setting value may not be greater than :max characters.',
        ],
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
        'seo_cache_enabled_required' => 'Please select the SEO cache setting.',
        'seo_cache_enabled_boolean' => 'SEO cache must be true or false.',
        'seo_cache_ttl_required' => 'Please enter the SEO cache TTL.',
        'seo_cache_ttl_integer' => 'SEO cache TTL must be an integer.',
        'seo_cache_ttl_min' => 'SEO cache TTL must be at least 0 seconds.',
        'seo_cache_ttl_max' => 'SEO cache TTL may not be greater than 14400 seconds (4 hours).',

        // Debug settings
        'debug_mode_required' => 'Please select the debug mode setting.',
        'debug_mode_boolean' => 'Debug mode must be true or false.',
        'sql_query_log_required' => 'Please select the SQL query log setting.',
        'sql_query_log_boolean' => 'SQL query log must be true or false.',

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

        // SEO settings
        'meta_title_suffix_max' => 'Meta title suffix may not be greater than 100 characters.',
        'meta_description_max' => 'Meta description may not be greater than 160 characters.',
        'meta_keywords_max' => 'Meta keywords may not be greater than 255 characters.',
        'google_analytics_id_max' => 'Google Analytics ID may not be greater than 50 characters.',
        'google_site_verification_max' => 'Google site verification code may not be greater than 100 characters.',
        'naver_site_verification_max' => 'Naver site verification code may not be greater than 100 characters.',
        'bot_user_agents_array' => 'Bot user agents must be an array.',
        'bot_user_agents_item_string' => 'Each bot user agent must be a string.',
        'bot_user_agents_item_max' => 'Each bot user agent may not be greater than 100 characters.',
        'bot_detection_enabled_boolean' => 'Bot detection setting must be true or false.',
        'seo_cache_enabled_boolean' => 'SEO cache setting must be true or false.',
        'seo_cache_ttl_integer' => 'SEO cache TTL must be an integer.',
        'seo_cache_ttl_min' => 'SEO cache TTL must be at least 60 seconds.',
        'seo_cache_ttl_max' => 'SEO cache TTL may not be greater than 86400 seconds (24 hours).',
        'sitemap_enabled_boolean' => 'Sitemap generation setting must be true or false.',
        'sitemap_cache_ttl_integer' => 'Sitemap cache TTL must be an integer.',
        'sitemap_cache_ttl_min' => 'Sitemap cache TTL must be at least 3600 seconds (1 hour).',
        'sitemap_cache_ttl_max' => 'Sitemap cache TTL may not be greater than 604800 seconds (7 days).',
        'sitemap_schedule_invalid' => 'Please select a valid sitemap generation schedule.',
        'sitemap_schedule_time_invalid' => 'Sitemap generation time must be in HH:mm format.',

        // Misc
        'session_lifetime_integer' => 'Session lifetime must be an integer.',
        'session_lifetime_min' => 'Session lifetime must be at least 1 minute.',
        'session_lifetime_max' => 'Session lifetime may not be greater than 525600 minutes (1 year).',

        // Driver settings - Storage
        'storage_driver_required' => 'Please select a storage driver.',
        'storage_driver_invalid' => 'Please select a valid storage driver.',
        's3_bucket_required' => 'S3 bucket name is required.',
        's3_bucket_max' => 'S3 bucket name may not be greater than 255 characters.',
        's3_region_required' => 'Please select an S3 region.',
        's3_region_invalid' => 'Please select a valid S3 region.',
        's3_access_key_required' => 'S3 access key is required.',
        's3_access_key_max' => 'S3 access key may not be greater than 255 characters.',
        's3_secret_key_required' => 'S3 secret key is required.',
        's3_secret_key_max' => 'S3 secret key may not be greater than 255 characters.',
        's3_url_url' => 'S3 URL must be a valid URL.',
        's3_url_max' => 'S3 URL may not be greater than 255 characters.',

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
    ],

    // Validation attribute names (validation.attributes)
    'attributes' => [
        'ids' => 'user ID list',
        'user_id' => 'user ID',
        'status' => 'status',
        // Settings fields
        'site_name' => 'site name',
        'site_url' => 'site URL',
        'site_description' => 'site description',
        'admin_email' => 'admin email',
        'timezone' => 'timezone',
        'language' => 'default language',
        // Mail settings
        'mailer' => 'mailer',
        'host' => 'SMTP host',
        'port' => 'SMTP port',
        'username' => 'SMTP username',
        'password' => 'SMTP password',
        'encryption' => 'encryption',
        'from_address' => 'sender email',
        'from_name' => 'sender name',
        // Upload settings
        'max_file_size' => 'max file size',
        'allowed_extensions' => 'allowed extensions',
        'image_max_width' => 'max image width',
        'image_max_height' => 'max image height',
        'image_quality' => 'image quality',
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
    ],
];
