<?php

return [
    // User related exceptions
    'cannot_delete_super_admin' => 'Super admin cannot be deleted.',
    'cannot_modify_super_admin' => 'You do not have permission to modify a super admin account or role.',
    'cannot_grant_unheld_permission' => 'You cannot grant permissions you do not hold or a broader scope than your own.',
    'cannot_modify_protected_role' => 'You do not have permission to modify a system or extension-owned role.',
    'cannot_delete_self' => 'You cannot delete your own account from the admin screen. Withdraw from My Page instead.',
    'role_active_state_locked' => 'The administrator role cannot be turned off. Turning it off would revoke the admin permissions of every operator.',

    'circular_reference' => 'Layout circular reference detected: :trace',
    'max_depth_exceeded' => 'Layout nesting depth exceeds maximum allowed depth (:max).',
    'template_file_copy_failed' => 'Template file copy failed: :source → :destination',
    'template_build_directory_creation_failed' => 'Template build directory creation failed: :path',
    'template_dist_directory_not_found' => 'Template dist directory not found: :path',
    'template_not_found' => 'Template not found: :identifier',
    'template_not_active' => 'Template is not active: :identifier (status: :status)',

    // Privacy manual run — one job per target
    'privacy_manual_run_conflict' => 'A manual run for this target is already queued or running. Try again after it finishes.',
    // Target that requires its toggle (password expiry) is turned off
    'privacy_manual_run_disabled' => 'This feature is turned off, so it cannot be run now. Turn it on in the settings, save, and run it again.',

    // Optimistic lock — concurrent save conflict
    'concurrent_modification' => 'Another user has saved first. (current version: :current, your version: :expected)',

    // Layout related exceptions
    'layout' => [
        'duplicate_data_source_id' => 'Duplicate data_sources ID: :id',
        'duplicate_data_source_id_in_file' => 'Duplicate data_sources ID in layout file: :ids (file: :file)',
        'duplicate_data_source_id_extends' => 'Duplicate data_sources ID in extends inheritance: :ids (child: :child, parent: :parent)',
        'not_found' => 'Layout not found: :name',
        'parent_not_found' => 'Parent layout not found: :parent (requested layout: :child)',

        // Include related exceptions
        'include_file_not_found' => 'Include file not found: :path (resolved: :resolved)',
        'invalid_include_json' => 'Invalid JSON in include file: :path (error: :error)',
        'invalid_include_markup' => 'Failed to compile include authoring source (.g7.html): :path (error: :error)',
        'circular_include' => 'Circular include detected: :trace',
        'max_include_depth_exceeded' => 'Maximum include depth exceeded (max: :max)',
        'include_outside_directory' => 'Include path outside allowed directory: :path (allowed: :allowed_dir)',
        // A broken partial reference in a 7.0 JSON layout no longer blocks installation — the slot is left empty (warning)
        'include_skipped' => 'Could not load partial :partial in JSON layout :file; it was registered as an empty slot (:reason)',
    ],

    // Layout version related exceptions
    'layout_version' => [
        'save_failed_after_retries' => 'Failed to save layout version after :attempts attempts.',
        'save_failed_unexpected' => 'Unexpected error occurred while saving layout version.',
    ],

    // Layout authoring format (.g7.html) compile exceptions
    'layout_markup' => [
        // Location prefix (common envelope for every compile error)
        'error_at' => ':file (line :line, column :column): :detail [:code]',
        'error_at_inline' => 'line :line, column :column: :detail [:code]',
        'error_in_file' => ':file: :detail [:code]',

        // Markup structure
        'unsupported_markup' => 'Unsupported markup: :found — .g7.html only uses elements, text and comments (<!-- -->).',
        'unescaped_lt' => 'A < in text is parsed as the start of a tag. Write &lt; for a literal character.',
        'eof_in_tag' => 'Tag <:tag> was not closed before the end of file. Add > or />.',
        'eof_in_comment' => 'Comment was not closed before the end of file. Add -->.',

        // Tag name
        'empty_tag_name' => 'Tag name is empty.',
        'tag_uppercase' => 'Tag names are lowercase: <:found> → <:suggestion>',
        'invalid_tag_name' => 'Invalid tag name: <:found> — use lowercase letters and hyphens only (e.g. <data-grid>).',
        'end_tag_self_closing' => 'End tags cannot be self-closing: </:tag/> → </:tag>',
        'end_tag_with_attributes' => 'End tags cannot have attributes: </:tag>',
        'void_end_tag' => '<:tag> cannot have content, so it has no end tag: remove </:tag>.',

        // Attributes
        'invalid_attribute_name' => 'Invalid attribute name: :found',
        'unquoted_attribute_value' => 'Attribute values are always quoted: :attr=:found → :attr=":found"',
        'unterminated_attribute_value' => 'The value of attribute :attr was not closed. Match the opening quote (:quote).',
        'duplicate_attribute' => 'Attribute :attr is duplicated on <:tag>.',
        'missing_attribute_separator' => 'Attributes must be separated by whitespace: add a space before :attr on <:tag>.',

        // Character references
        'unknown_entity' => 'Unknown character reference: :found — write &amp; for a literal ampersand.',
        'invalid_entity' => 'Invalid character reference: :found',

        // Registry (components.json)
        'manifest_unreadable' => 'Cannot read the component registry file.',
        'manifest_invalid_json' => 'The component registry is not valid JSON: :reason',
        'manifest_invalid' => 'The component registry is missing a required field or has the wrong shape: :field',
        'ambiguous_component_tag' => 'Two components resolve to <:tag>: :first, :second — give them distinct names in the registry.',
        'manifest_owner_ambiguous' => 'The component registry declares both templateId and identifier. Keep templateId for a template, or identifier for a module/plugin.',
        'manifest_owner_missing' => 'The component registry has no owner. Declare templateId for a template, or identifier for a module/plugin.',
        'extension_overrides_forbidden' => ':extension is an extension and cannot declare overrides. Only a template may replace another extension\'s component; to ship a template-specific edition of your own component, use for.',
        'template_for_variant_forbidden' => 'A template cannot declare for. Only an extension uses for, to ship a template-specific edition of its own component.',
        'override_target_component_unknown' => 'The :target extension has no component named :component. Check the name, or fix the targetVersion constraint.',
        'duplicate_override_target' => ':component is overridden twice. Declare only one replacement.',
        'for_variant_component_unknown' => 'for.:template names :component, which this extension does not provide. A template-specific edition needs an original.',

        // Tree structure
        'unexpected_end_tag' => 'End tag with no open tag: </:tag>',
        'mismatched_end_tag' => '<:open> (line :open_line) is open but was closed with </:tag>.',
        'unclosed_tag' => '<:tag> was never closed. Add </:tag> or write <:tag/>.',
        'mixed_text_and_children' => '<:tag> cannot hold text and child tags at once: ":found" — wrap the text in a child tag.',
        'text_outside_element' => 'Text outside any tag is never rendered: ":found"',
        'consecutive_comments' => 'A tag cannot be preceded by more than one comment. Use line breaks inside a single <!-- -->.',
        'dangling_comment' => 'The comment has no tag to describe. A comment documents the tag that follows it.',
        'comment_not_attachable' => 'A comment before <:tag> has no place in the compiled JSON and would be dropped. :hint',
        'comment_hint' => [
            'on' => 'Describe the event on the first action inside <on>; if there are two or more actions, wrap them in <sequence> and comment that.',
            'default' => 'Move it directly before the tag you are describing — tags that carry values or structure have no comment slot.',
        ],

        // Tag resolution
        'invalid_component_type' => 'data-type must be one of :allowed written literally (got: :value). Omit it for basic.',
        'root_tag_in_node_position' => '<:tag> is only used at the outermost level of a document.',
        'tag_not_yet_supported' => '<:tag> is :owner syntax and is not handled at this stage yet.',

        // Fills the :owner slot of tag_not_yet_supported — keeping the word in code would embed
        // Korean in the middle of English and Japanese sentences.
        'tag_owner' => [
            'action' => 'action',
            'embedding' => 'embedding',
            'document_section' => 'document section',
        ],

        // Attributes
        'foreign_idiom' => ':found is not G7 syntax: use :suggestion instead.',
        'invalid_type_literal' => 'The value of ":attr" is not JSON: :found — for expressions and strings drop the colon and write :suggestion="...".',
        'boolean_attribute_false' => ':attr is true when present and false when omitted: write :attr for true, or remove it for false. To pass the value itself, write ::attr="false".',
        'directive_needs_value' => ':attr needs a value.',
        'directive_rejects_type_literal' => 'Do not write ::attr. Its value has a single fixed type, so write :suggestion="..." instead.',
        'directive_needs_integer' => 'The value of :attr must be an integer: :found',
        'invalid_each_syntax' => 'data-each takes the form "item[, index] in source": :found',
        'mixed_if_expression' => ':attr must wrap the whole expression in {{ }}: :found — wrapping only part of it yields a string, so the condition is always true.',
        'condition_boolean_literal' => 'Write :suggestion=":found" instead of :attr=":found" — that is the form that passes the true/false value itself.',
        'node_key_as_attribute' => ':attr is a node setting, not an attribute: write :suggestion instead. As an attribute it is merely handed to the component and does nothing.',
        'attribute_not_supported' => 'The :attr attribute is not supported. The tag name itself picks the component, so it is not needed.',

        // Structural tags
        'unexpected_structural_child' => '<:tag> is only valid inside <:parent>.',
        'missing_required_attribute' => '<:tag> requires the :attr attribute.',
        'duplicate_node_key' => ':key was specified twice.',
        'invalid_json_body' => 'The content of <:tag> is not valid JSON: :reason',
        'field_value_and_children' => '<field name=":name"> cannot have both a value and nested fields.',

        // Actions (S3)
        'action_tag_in_node_position' => '<:tag> handles events and cannot sit where a component is expected. Wrap it in <on event="..."> inside a component.',
        'action_trigger_missing' => '<on> needs an event: use event= for a component event, type= for a DOM event, or listen= to subscribe to an event from elsewhere.',
        'action_trigger_conflict' => 'The event notation on <on> conflicts: listen cannot be combined with event/type, and mount/unmount cannot be combined with type.',
        'empty_action_block' => '<:tag> has no action to run.',
        'unexpected_action_child' => '<:tag> cannot appear here inside <:parent>.',
        'unknown_action_tag' => '<:tag> is not a built-in handler. Use <action handler="name"> for extension or dynamic handlers.',
        'unknown_action_tag_with_suggestion' => '<:tag> is not a built-in handler: did you mean <:suggestion>? (extension/dynamic handlers use <action handler="name">)',
        'duplicate_case_key' => 'The :case case was specified twice.',
        'duplicate_action_key' => ':key was specified twice. Check that an attribute and a tag are not competing for the same slot.',
        'confirm_not_at_root' => 'confirm only prompts on the action bound directly to the event. confirm on a nested action is ignored, so move it to the entry action.',
        'init_action_key_forbidden' => ':key cannot be used on an init action — it is silently dropped during initialization. Put follow-up work inside <success> or reference a named action.',

        // Document structure (S4)
        'section_tag_in_node_position' => '<:tag> is a top-level document section and cannot be used in a component position. Place it directly under the document envelope.',
        'empty_document' => 'The document is empty. Wrap it in one of <layout>, <partial>, <extension>, or <override>.',
        'multiple_document_roots' => 'A file holds a single document envelope: move <:tag> into its own file.',
        'unknown_document_root' => '<:tag> is not a document envelope. Use one of <layout>, <partial>, <extension>, or <override>.',
        'unknown_root_attribute' => 'The :attr attribute cannot be used on <:tag>.',
        'duplicate_section' => 'The :tag section is specified twice.',
        'unexpected_document_child' => '<:tag> cannot appear inside <:parent>.',
        'partial_root_not_single_node' => '<partial> has a single root node. Keep exactly one root component for the fragment.',
        'partial_root_meta_not_allowed' => 'The meta of a fragment is written as <partial> attributes. Move the <meta> inside the root node onto <partial> (e.g. editor_label="...").',
        'modal_src_with_body' => '<modal src="..."> references a fragment, so it cannot also have a body. Write an inline modal as a node without src.',
        'modal_name_with_src' => '<modal src="..."> cannot carry name. name is the layout_name of an inline modal (the name installation attaches when it inlines a fragment), so it is only meaningful on <modal name="..."> with a body.',
        'partial_src_not_authoring' => '<:tag src=":src"> must point at an authoring source: write it as src=":suggestion" with the .g7.html extension. The compiler emits the .json notation in the generated JSON.',
        'modal_src_in_node_position' => '<modal src=":src"> is only valid where the document declares its modals. In a node position nothing reads src, so no dialog ever opens — move it directly under the document envelope.',
        'modal_inline_not_single_node' => 'An inline <modal> holds a single node.',
        'fragment_target_conflict' => '<extension> cannot use both target-layout and extension-point. Use target-layout to inject into a specific layout, or extension-point to inject at an extension point.',
        'fragment_target_missing' => '<extension> requires either target-layout or extension-point.',
        'unexpected_fragment_child' => '<:tag> cannot appear in this fragment form. Use <inject> for the target-layout form and component nodes for the extension-point form.',
        'fragment_inject_ambiguous' => '<inject> either merges attributes via <props> or injects component nodes. It cannot do both.',

        // State initialization (S4)
        'state_source_needs_key' => '<from-source> inside <:tag> needs a state key to hold it: wrap it with <:tag key="...">.',
        'state_merge_needs_key' => 'merge only applies to a block wrapped with key: write <:tag key="..." merge="...">.',
        'state_field_source_mixed' => '<:tag> cannot mix <field> and <from-source>. Use <field> for literal values and <from-source> for values pulled from data.',
        'state_merge_needs_mapping' => 'merge only applies to a <field> mapping object. A <from-source> value cannot carry a merge strategy.',
        'state_source_value_and_key' => '<from-source> cannot have both a body (string source) and key/path attributes: ":found"',

        // Embedding (S5)
        'embedding_tag_wrong_parent' => '<:tag> may only be used inside <:parent> (it is currently inside <:found>).',
        'embedding_single_node_required' => '<:tag> holds exactly one node.',

        // Strong validation (S6) — semantic/security checks on compiled JSON
        'layout_structure_invalid' => 'The compiled layout structure is invalid: :reason',
        'fragment_structure_invalid' => 'The extension fragment structure is invalid: :reason',
        'endpoint_not_whitelisted' => 'The API endpoint is outside the allowed paths: :reason',
        'external_url_forbidden' => 'External URLs / dangerous schemes are not allowed: :reason',
        'component_not_in_registry' => 'Component is not in the registry: :name',
        'wait_for_source_never_resolves' => "transition_overlay's wait_for points at a data source that never signals completion (:reason): :id",
        'script_src_not_allowed' => 'This external JS cannot be loaded via <script> (host not allowlisted): :src',

        // Install integration — target template registry missing when compiling .g7.html at install time
    ],

    // .g7.html static lint warnings (S6) — non-fatal warnings that do not stop compilation
    'layout_markup_lint' => [
        'unregistered_handler' => 'Unregistered handler: :handler — not a core, template, or extension handler.',
        'nesting_violation' => ':parent is not declared to accept :child as a child.',
        'responsive_breakpoint_mix' => 'The portable preset is mixed with the mobile/tablet presets — overlapping ranges make behavior hard to predict.',
        'standalone_missing_global_host' => 'A standalone layout fires :handler but nothing reads its global state (:state) — nothing appears on screen. Extend a base layout or add a component that reads that state.',
        'switch_value_not_consumed' => '<switch value="…"> is not read by the engine — write the branch subject as <switch on="…"> so it reaches params.value.',
        'action_param_misplaced_key' => 'The :key of a :handler action landed in params — :handler never reads that key, so it is silently ignored. Use data-if for conditions, <on event="…"> for triggers, and otherwise the argument name the handler actually reads.',
        'hoist_prop_not_supported_by_template' => ':component in the :template template does not declare :prop — anything authored with <:tag> is dropped instead of rendered.',
        'component_type_mismatch' => ':component is written as :written but the :template template declares it as :declared — check data-type (omitted means basic).',
        'extension_component_not_installed' => 'No installed extension provides this component: :name — the :extension extension may have been removed or the name is mistyped. Saving is allowed, but it will not render unless that extension is present.',
        'template_component_kept' => 'This template does not list the component :name — it was already in the saved content, so saving is allowed, but it may not render with this template.',
        'nested_action_debounce_ignored' => 'The debounce on the :handler action has no effect — actions nested inside conditions, sequences or result callbacks run immediately on every event. Put debounce on the enclosing outer action (<conditions :debounce>, <sequence :debounce>).',
        'mixed_debounce_flushed' => ':event on :name mixes debounced actions with immediate ones — every time an immediate action runs, the waiting debounced actions run right away, so they run on every input. To keep the delay, debounce every action on this event.',
    ],

    // Settings related exceptions
    'settings' => [
        'backup_creation_failed' => 'Failed to create settings backup file.',
        'restore_failed' => 'Failed to restore settings.',
        'category_not_found' => 'Settings category not found: :category',
        'save_failed' => 'Failed to save settings: :category',
        'json_parse_failed' => 'Failed to parse JSON file: :path (:error)',
        'add_category_module_only' => 'addCategory is only available for modules.',
    ],

    // Core update related exceptions
    'core_update' => [
        'handoff' => 'Upgrade handoff: completed up to :after_version — :reason (resume with: :resume_command)',
    ],

    // Vendor bundle / installation related exceptions
    'vendor' => [
        'composer_not_available' => 'Composer cannot be executed in this environment. Use bundled mode instead.',
        'composer_not_available_for_build' => 'Building vendor-bundle requires Composer execution. Install/configure Composer in the development environment and retry.',
        'bundle_build_composer_failed' => 'composer install failed while building vendor-bundle (exit :exit): :message',
        'bundle_build_promote_failed' => 'failed to move vendor-bundle build result to its final path (:from → :to)',
        'composer_execution_failed' => 'Composer execution failed: :message',
        'bundle_zip_missing' => 'vendor-bundle.zip not found: :path',
        'bundle_manifest_missing' => 'vendor-bundle.json not found: :path',
        'bundle_manifest_invalid' => 'vendor-bundle.json could not be read: :error',
        'bundle_integrity_failed' => 'Bundle integrity check failed: :details',
        'bundle_schema_unsupported' => 'Unsupported vendor-bundle.json schema version: :version',
        'zip_archive_not_available' => 'PHP ZipArchive extension is not installed.',
        'zip_hash_mismatch' => 'vendor-bundle.zip hash mismatch (expected: :expected, actual: :actual).',
        'composer_json_sha_mismatch' => 'composer.json has changed since the bundle was built. Rebuild with vendor-bundle:build.',
        'composer_lock_sha_mismatch' => 'composer.lock has changed since the bundle was built. Rebuild with vendor-bundle:build.',
        'bundle_contains_unsafe_path' => 'Bundle zip contains unsafe file path: :path',
        'extraction_failed' => 'Failed to extract bundle zip: :message',
        'no_vendor_strategy_available' => 'Cannot determine vendor install strategy. Neither Composer nor bundle zip is available.',
        'source_dir_not_found' => 'Installation source directory not found: :path',
        'target_not_writable' => 'Target directory is not writable: :path',
        'target_not_writable_owner_hint' => "Current user (:current_user) differs from directory owner (:owner). Run 'chown -R :current_user_name :path' and retry, or SSH in as the owner (:owner_name) before running this command.",
        'composer_json_not_found' => 'composer.json not found: :path',
        'no_composer_lock' => 'composer.lock is required. Run composer install first: :path',
        'vendor_dir_not_found' => 'vendor/ directory does not exist. Run composer install first: :path',
        'composer_binary_not_found' => 'Composer binary not found. Set the COMPOSER_BINARY environment variable or add composer to your PATH.',
    ],

    // 스토리지 드라이버 경로 가드 예외
    'storage' => [
        'unsafe_path' => 'The storage path is not allowed.',
        'destructive_on_root' => 'The top-level storage path cannot be deleted this way.',
        'local_safe_option_package' => 'The :option option of a local-safe disk requires the :package package.',
    ],

    // SEO / Sitemap 관련 예외
    'seo' => [
        'sitemap_write_failed' => 'Failed to write sitemap file: :path',
        'sitemap_gzip_failed' => 'Failed to compress sitemap child file: :file',
        'sitemap_temp_file_unreadable' => 'Cannot read sitemap temporary file: :file',
        'sitemap_stat_group_column_unsupported' => 'Unsupported group-by column: :column',
    ],
];
