<?php

declare( strict_types=1 );

namespace Djinn;

use Djinn\Admin\AdminPage;
use Djinn\Admin\IndexPage;
use Djinn\Admin\UsagePage;
use Djinn\Rest\Controller;
use Djinn\Store\Repository;

/**
 * Composition root: lights the lamp.
 */
class Plugin {

	public function boot(): void {
		// Create/upgrade tables when the schema version changes (no reactivation needed).
		Repository::maybeUpgrade();

		( new AdminPage() )->register();
		( new IndexPage() )->register();
		( new UsagePage() )->register();
		( new Controller() )->register();
	}
}
