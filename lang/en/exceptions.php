<?php

return [
    // User related exceptions
    'cannot_delete_super_admin' => 'Super admin cannot be deleted.',

    'circular_reference' => 'Layout circular reference detected: :trace',
    'max_depth_exceeded' => 'Layout nesting depth exceeds maximum allowed depth (:max).',
    'template_file_copy_failed' => 'Template file copy failed: :source → :destination',
    'template_build_directory_creation_failed' => 'Template build directory creation failed: :path',
    'template_dist_directory_not_found' => 'Template dist directory not found: :path',
    'template_not_found' => 'Template not found: :identifier',
    'template_not_active' => 'Template is not active: :identifier (status: :status)',

    // Optimistic lock — concurrent save conflict
    'concurrent_modification' => 'Another user has saved first. (resource: :resource, current version: :current, your version: :expected)',

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
        'circular_include' => 'Circular include detected: :trace',
        'max_include_depth_exceeded' => 'Maximum include depth exceeded (max: :max)',
        'include_outside_directory' => 'Include path outside allowed directory: :path (allowed: :allowed_dir)',
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
        'extension_id_tag_collision' => ':first and :second collapse to the same tag prefix (:prefix). Their components cannot be told apart by tag.',

        // Tree structure
        'unexpected_end_tag' => 'End tag with no open tag: </:tag>',
        'mismatched_end_tag' => '<:open> (line :open_line) is open but was closed with </:tag>.',
        'unclosed_tag' => '<:tag> was never closed. Add </:tag> or write <:tag/>.',
        'mixed_text_and_children' => '<:tag> cannot hold text and child tags at once: ":found" — wrap the text in a child tag.',
        'text_outside_element' => 'Text outside any tag is never rendered: ":found"',
        'consecutive_comments' => 'A tag cannot be preceded by more than one comment. Use line breaks inside a single <!-- -->.',
        'dangling_comment' => 'The comment has no tag to describe. A comment documents the tag that follows it.',

        // Tag resolution
        'unknown_tag' => '<:tag> is not registered in the :template template.',
        'unknown_tag_with_suggestion' => '<:tag> is not a registered tag: did you mean <:suggestion>?',
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
        'modal_src_with_body' => '<modal src="..."> references a fragment, so it cannot also have a body. Write an inline modal as a node without src.',
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

        // Install integration (S7) — coexistence provenance for compiled JSON
        'hand_authored_duplicate' => 'The :json next to :source is not compiled from it (:reason). Two sources of truth cannot coexist: remove the .json, or run layout:compile to regenerate it.',
        'uncompiled_markup_source' => ':source has no compiled .json beside it. Installation reads the compiled JSON, so run layout:compile first.',
        'fragment_coexistence_failed' => 'The .g7.html fragments of :identifier failed coexistence validation:',

        // Fills the :reason slot of hand_authored_duplicate — keeps the phrase out of the
        // middle of the localized sentence.
        'provenance_reason' => [
            'missing' => 'no authoring marker — it looks hand-written',
            'stale' => 'the source has changed since it was compiled',
        ],
    ],

    // .g7.html static lint warnings (S6) — non-fatal warnings that do not stop compilation
    'layout_markup_lint' => [
        'unregistered_handler' => 'Unregistered handler: :handler — not a core, template, or extension handler.',
        'plugin_complete_layout' => 'A plugin defined a complete layout: :layout — plugins are expected to provide only layout_extensions / settings screens.',
        'nesting_violation' => ':parent is not declared to accept :child as a child.',
        'responsive_breakpoint_mix' => 'The portable preset is mixed with the mobile/tablet presets — overlapping ranges make behavior hard to predict.',
        'standalone_missing_global_host' => 'A standalone layout fires toast/modal actions but does not mount a Toast/Modal host — nothing appears on screen.',
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
];
