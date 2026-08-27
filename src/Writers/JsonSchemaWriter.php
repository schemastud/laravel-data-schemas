<?php

namespace Schemastud\DataSchemas\Writers;

/**
 * @deprecated Use {@see SchemaFileWriter}. Kept because `writer` is a PUBLISHED config key:
 * two hosts in the estate (`~/Herd/fable-legacy`, `~/Herd/schemastud`) name this class in
 * their own `config/data-schemas.php`, and a published config is not something this package
 * can edit. Removing the class turns their `schemas:generate` into a bare "class not found".
 */
class JsonSchemaWriter extends SchemaFileWriter {}
