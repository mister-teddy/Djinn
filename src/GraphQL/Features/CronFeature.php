<?php

declare( strict_types=1 );

namespace Djinn\GraphQL\Features;

use Djinn\GraphQL\Feature;
use Djinn\GraphQL\Registry;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * WP-Cron visibility and cleanup: list scheduled events and clear a stuck/unwanted hook. Gated on
 * manage_options. (Scheduling arbitrary hooks isn't exposed — without a handler it does nothing —
 * so this stays read + clear, the useful maintenance operations.)
 */
class CronFeature implements Feature {

	public function register( Registry $r ): void {
		$event = new ObjectType(
			[
				'name'        => 'ScheduledEvent',
				'description' => 'A WP-Cron scheduled event.',
				'fields'      => [
					'hook'     => [ 'type' => Type::string() ],
					'schedule' => [ 'type' => Type::string(), 'description' => 'Recurrence name (hourly, daily, …) or "single".' ],
					'nextRun'  => [ 'type' => Type::string(), 'description' => 'Next run time (ISO 8601, UTC).' ],
					'interval' => [ 'type' => Type::int(), 'description' => 'Recurrence interval in seconds, if recurring.' ],
				],
			]
		);
		$r->setType( 'ScheduledEvent', $event );

		$r->addQuery( 'scheduledEvents', [
			'type'        => Type::listOf( $event ),
			'description' => 'List WP-Cron scheduled events.',
			'resolve'     => [ $this, 'scheduledEvents' ],
		] );

		$r->addMutation( 'unscheduleHook', [
			'type'        => Type::int(),
			'description' => 'Clear all scheduled events for a hook. Returns how many were cleared.',
			'args'        => [ 'hook' => [ 'type' => Type::nonNull( Type::string() ) ] ],
			'resolve'     => [ $this, 'unscheduleHook' ],
		] );
	}

	private function gate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new UserError( 'You do not have permission to manage scheduled events.' );
		}
	}

	/** @return array<int,array<string,mixed>> */
	public function scheduledEvents(): array {
		$this->gate();
		$cron = _get_cron_array();
		if ( ! is_array( $cron ) ) {
			return [];
		}
		$out = [];
		foreach ( $cron as $ts => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				foreach ( (array) $events as $e ) {
					$out[] = [
						'hook'     => (string) $hook,
						'schedule' => ! empty( $e['schedule'] ) ? (string) $e['schedule'] : 'single',
						'nextRun'  => gmdate( 'c', (int) $ts ),
						'interval' => isset( $e['interval'] ) ? (int) $e['interval'] : null,
					];
				}
			}
		}
		return $out;
	}

	/** @param array<string,mixed> $args */
	public function unscheduleHook( $root, array $args ): int {
		$this->gate();
		$cleared = wp_clear_scheduled_hook( (string) $args['hook'] );
		if ( is_wp_error( $cleared ) ) {
			throw new UserError( $cleared->get_error_message() );
		}
		return (int) $cleared;
	}
}
