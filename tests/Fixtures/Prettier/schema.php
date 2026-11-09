<?php

/*
 * A schema that exercises every case of prettier's JSON layout (tabWidth 4, printWidth 80). `schema.json` beside it
 * is prettier 3.6.2's own output for this value, so the writer is held to prettier, not to a reading of it.
 */
return [
    '$schema' => 'http://json-schema.org/draft-07/schema#',
    '$id' => 'https://schemas.example.test/App/Data/UserData',
    'type' => 'object',
    'required' => ['id', 'name', 'roles'],
    'properties' => [
        'id' => ['type' => 'string'],
        'name' => ['type' => ['string', 'null']],
        'roles' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['owner', 'admin', 'member', 'viewer', 'billing-contact', 'guest-collaborator']]],
        'scores' => ['type' => 'array', 'items' => ['type' => 'integer'], 'examples' => [[1, 2, 3]]],
        'meta' => ['type' => 'object', 'properties' => new stdClass, 'default' => []],
        'choice' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
        'flags' => ['type' => 'array', 'default' => [true, false, null, 1.5]],
        'numbers' => ['enum' => range(1, 30)],
        'pairs' => ['examples' => [[1, 2], [3, 4]]],
        'exact' => ['examples' => [str_repeat('x', 53)]],
        'fits' => ['examples' => [str_repeat('y', 52)]],
        'mixed' => ['examples' => [1, 'a', [], [[]]]],
        'unicode' => ['title' => 'Café/Bar'],
    ],
];
