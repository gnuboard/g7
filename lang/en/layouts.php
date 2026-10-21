<?php

return [
    'title' => 'Layout Management',

    'messages' => [
        'retrieved' => 'Layout information retrieved successfully.',
        'created' => 'Layout file created.',
        'renamed' => 'Layout renamed.',
        'deleted' => 'Layout file deleted.',
    ],

    'errors' => [
        'not_found' => 'Layout not found.',

        'invalid_name' => 'That name cannot be used: :name — use letters, digits, underscore (_), hyphen (-), and slash (/) for folders.',
        'name_taken' => 'A layout with that name already exists: :name',

        'delete_blocked_source_type' => 'This layout is provided by the :source ":identifier" and cannot be deleted. Deactivate or remove that extension instead.',
        'rename_blocked_source_type' => 'This layout is provided by the :source ":identifier" and cannot be renamed.',

        'delete_blocked_by_reference' => ':count layout references this file, so it cannot be deleted. Clear that reference first.|:count layouts reference this file, so it cannot be deleted. Clear those references first.',
        'rename_blocked_by_reference' => ':count layout references this file, so it cannot be renamed. Clear that reference first.|:count layouts reference this file, so it cannot be renamed. Clear those references first.',

        'delete_requires_confirmation' => 'This layout is wired to the :route screen. Deleting it will make that screen unreachable.',
    ],

    'reference_kinds' => [
        'extends' => 'Inheritance (extends)',
        'partial' => 'Fragment reference (include / modal)',
        'route' => 'Route binding',
    ],

    'repair_empty_objects' => [
        'description' => 'Find layout empty objects ({}) that editor saves or version restores turned into [] and restore them from the original',
        'no_templates' => 'No target templates.',
        'header_table' => 'Table',
        'header_id' => 'id',
        'header_name' => 'Name',
        'header_count' => 'Positions to fix',
        'header_paths' => 'Example paths',
        'nothing' => 'Nothing to fix.',
        'summary' => 'Total — rows :rows · positions :positions',
        'skipped' => 'Rows skipped without a reference (no original, or the owning row is deleted): :count',
        'preview_hint' => 'This is a preview. Add --apply to fix.',
        'applied' => 'Rows fixed: :rows',
    ],

    // `layout:compile`·`layout:decompile`·`layout:check-installed` 명령 출력
    'console' => [
        'path_or_all' => 'Specify a path or use --all.',
        'compile_no_targets' => 'No target .json layouts.',
        'budget_none_layouts' => '<json> escape hatch used 0 times — every layout round-trips in first-class syntax.',
        'budget_none_rows' => '<json> escape hatch used 0 times — every row round-trips in first-class syntax.',
        'budget_header_inventory' => '=== &lt;json&gt; escape budget (checked against the defect inventory) ===',
        'budget_header' => '=== &lt;json&gt; escape budget ===',
        'budget_count' => ':count',
        'budget_total_files' => 'Total :count / :files files',
        'budget_total' => 'Total :count',
        'compile_error' => 'Compile error',
        'roundtrip_summary' => 'Round trip — :total targets, :failed mismatched, :skipped skipped.',
        'not_json' => 'Not JSON: :file',
        'self_check_failed' => 'Self-check failed — output not written: :error',
        'compile_done' => 'Done — :files files, :failed failed.',
        'sync_check_exclusive' => '--sync and --check cannot be used together (--check only compares without writing).',
        'decompile_no_targets' => 'No .g7.html files to decompile.',
        'decompile_done' => 'Done — :files files, :failed failed, :warned with warnings.',
        'bake_summary' => 'Bake — :created created, :updated updated, :unchanged unchanged.',
        'bake_stale' => 'Sibling .json differs from the compiled output (:count) — re-bake with `layout:decompile --all --sync`:',
        'bake_up_to_date' => 'Bake check — all :count siblings up to date.',
        'file_not_found' => 'File not found: :file',
        'sibling_created' => '(sibling .json created)',
        'sibling_updated' => '(sibling .json updated)',
        'warning_count' => '(:count warnings)',
        'check_no_templates' => 'No templates to check.',
        'check_headers' => [
            'kind' => 'Kind',
            'template' => 'Template',
            'name' => 'Name',
            'verdict' => 'Verdict',
            'escape' => 'escape',
            'warnings' => 'Warnings',
            'ms' => 'ms',
        ],
        'check_summary' => 'Installed layout check — :templates templates, :rows rows (ok :ok · fail :fail · mismatch :mismatch), registration — inapplicable :inapplicable · missing :missing · orphan :orphan · unreadable :unreadable, missing JSON fragment references :include_warnings, plugin user screens without a route :unrouted.',
        'include_skipped' => 'Fragment reference check skipped — :source :identifier: :error',
        'include_header' => '=== Missing JSON fragment references (registered as empty slots) ===',
        'include_headers' => [
            'source' => 'Source',
            'identifier' => 'Identifier',
            'file' => 'File',
            'partial' => 'Fragment',
            'reason' => 'Reason',
        ],
        'unrouted_header' => '=== Plugin user screens not connected to a route ===',
        'unrouted_headers' => [
            'plugin' => 'Plugin',
            'layout' => 'Screen',
        ],
        'unrouted_hint' => 'For customers to reach these screens, the plugin must inject user routes with the `core.routes.filter_merged` filter. Ignore this if they are only used as modals or partial screens.',
        // `layout:audit-expressions`, `layout-versions:recalculate-diffs`, `layout-previews:cleanup` command output
        'audit_expressions' => [
            'invalid_source' => '--source must be one of db|files|all.',
            'summary' => 'Layouts scanned: :scanned · Violations: :violations',
            'headers' => [
                'source' => 'Source',
                'target' => 'Target',
                'violation' => 'Violation',
            ],
            'more' => 'and :count more (use --limit or --json to see all)',
            'action_hint' => 'Action: fix the value in the editor or restore a previous version. This command does not change any values.',
        ],
        'versions_recalculate_diffs' => [
            'start' => 'Recalculating layout version change summaries...',
            'done' => 'Recalculated change summaries for :count versions.',
        ],
        'previews_cleanup' => [
            'done' => 'Deleted :count expired previews.',
        ],
        // `layout:generate-fixture` command output
        'generate_fixture' => [
            'layout_not_found' => 'Layout file not found: :path',
            'loading' => 'Loading layout: :path',
            'source_not_found' => 'Layout source not found: :path',
            'loading_source' => 'Loading from source: :path',
            'compile_failed' => 'Source compilation failed: :error',
            'json_parse_failed' => 'JSON parsing failed: :error',
            'partial_merge_failed' => 'Partial merge failed: :error',
            'directory_created' => 'Created directory: :path',
            'done' => 'Fixture created: :path',
            'file_size' => 'File size: :size bytes',
        ],
    ],
];
