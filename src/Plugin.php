<?php

declare( strict_types=1 );

namespace Djinn;

use Djinn\Admin\AdminPage;
use Djinn\Rest\Controller;

/**
 * Composition root: lights the lamp.
 */
class Plugin {

	public function boot(): void {
		( new AdminPage() )->register();
		( new Controller() )->register();
	}
}
