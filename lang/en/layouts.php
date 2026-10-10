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
];
