<?php
/**
 * Dump the admin GraphQL schema as SDL to schema/admin.graphql — the input genql codegen reads.
 * The schema is static (no per-site variance), so this output is committed and regenerated only
 * when the schema changes. Run inside booted WordPress:
 *
 *   wp eval-file wp-content/plugins/<slug>/bin/dump-schema.php   (or `make schema`)
 */

use Djinn\GraphQL\Admin\AdminSchema;
use GraphQL\Utils\SchemaPrinter;

$schema = AdminSchema::build();
$schema->assertValid();

$path = dirname( __DIR__ ) . '/schema/admin.graphql';
if ( ! is_dir( dirname( $path ) ) ) {
	mkdir( dirname( $path ), 0755, true );
}
file_put_contents( $path, SchemaPrinter::doPrint( $schema ) . "\n" );
fwrite( STDERR, "Wrote schema/admin.graphql\n" );
