<?php

declare( strict_types=1 );

namespace Djinn\GraphQL\Features;

use Djinn\GraphQL\Feature;
use Djinn\GraphQL\Registry;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\Type;

/**
 * User management: create, change role, and delete. (Listing/search already exists as the core
 * `users` query.) Reuses the shared `User` type.
 */
class UsersFeature implements Feature {

	public function register( Registry $r ): void {
		$user = $r->type( 'User' );
		if ( ! $user ) {
			return; // core User type must exist
		}

		$r->addMutation(
			'createUser',
			array(
				'type'        => $user,
				'description' => 'Create a user. A random password is generated if none is given.',
				'args'        => array(
					'username' => array( 'type' => Type::nonNull( Type::string() ) ),
					'email'    => array( 'type' => Type::nonNull( Type::string() ) ),
					'role'     => array(
						'type'        => Type::string(),
						'description' => 'e.g. subscriber (default), author, editor, administrator.',
					),
					'password' => array( 'type' => Type::string() ),
				),
				'resolve'     => array( $this, 'createUser' ),
			)
		);

		$r->addMutation(
			'updateUser',
			array(
				'type'        => $user,
				'description' => 'Update a user\'s profile: display name, email, first/last name, website, or password.',
				'args'        => array(
					'id'          => array( 'type' => Type::nonNull( Type::id() ) ),
					'displayName' => array( 'type' => Type::string() ),
					'email'       => array( 'type' => Type::string() ),
					'firstName'   => array( 'type' => Type::string() ),
					'lastName'    => array( 'type' => Type::string() ),
					'url'         => array( 'type' => Type::string() ),
					'password'    => array( 'type' => Type::string() ),
				),
				'resolve'     => array( $this, 'updateUser' ),
			)
		);

		$r->addMutation(
			'updateUserRole',
			array(
				'type'        => Type::boolean(),
				'description' => 'Set a user\'s role (replaces existing roles).',
				'args'        => array(
					'id'   => array( 'type' => Type::nonNull( Type::id() ) ),
					'role' => array( 'type' => Type::nonNull( Type::string() ) ),
				),
				'resolve'     => array( $this, 'updateUserRole' ),
			)
		);

		$r->addMutation(
			'deleteUser',
			array(
				'type'        => Type::boolean(),
				'description' => 'Delete a user, optionally reassigning their content to another user.',
				'args'        => array(
					'id'           => array( 'type' => Type::nonNull( Type::id() ) ),
					'reassignToId' => array( 'type' => Type::id() ),
				),
				'resolve'     => array( $this, 'deleteUser' ),
			)
		);
	}

	private function shape( \WP_User $u ): array {
		return array(
			'id'          => (string) $u->ID,
			'displayName' => $u->display_name,
			'email'       => $u->user_email,
			'roles'       => $u->roles,
		);
	}

	private function roleOrFail( string $role ): string {
		if ( ! wp_roles()->is_role( $role ) ) {
			throw new UserError( esc_html( "No such role: '$role'." ) );
		}
		return $role;
	}

	private function assignableRoleOrFail( string $role ): string {
		$role = $this->roleOrFail( $role );
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$editable = get_editable_roles();
		if ( ! is_array( $editable ) || ! array_key_exists( $role, $editable ) ) {
			throw new UserError( esc_html( "You do not have permission to assign the '$role' role." ) );
		}
		return $role;
	}

	/** @param array<string,mixed> $args */
	public function createUser( $root, array $args ): array {
		if ( ! current_user_can( 'create_users' ) ) {
			throw new UserError( esc_html( 'You do not have permission to create users.' ) );
		}
		$role = isset( $args['role'] ) ? (string) $args['role'] : (string) get_option( 'default_role', 'subscriber' );
		$role = $this->assignableRoleOrFail( $role );
		$id   = wp_insert_user(
			array(
				'user_login' => (string) $args['username'],
				'user_email' => (string) $args['email'],
				'user_pass'  => isset( $args['password'] ) && $args['password'] !== '' ? (string) $args['password'] : wp_generate_password( 20 ),
				'role'       => $role,
			)
		);
		if ( is_wp_error( $id ) ) {
			throw new UserError( esc_html( $id->get_error_message() ) );
		}
		return $this->shape( get_userdata( $id ) );
	}

	/** @param array<string,mixed> $args */
	public function updateUser( $root, array $args ): array {
		$id = (int) $args['id'];
		if ( ! current_user_can( 'edit_user', $id ) ) {
			throw new UserError( esc_html( 'You do not have permission to edit this user.' ) );
		}
		if ( ! get_userdata( $id ) ) {
			throw new UserError( esc_html( 'No such user.' ) );
		}
		$data = array( 'ID' => $id );
		$map  = array(
			'displayName' => 'display_name',
			'email'       => 'user_email',
			'firstName'   => 'first_name',
			'lastName'    => 'last_name',
			'url'         => 'user_url',
			'password'    => 'user_pass',
		);
		foreach ( $map as $in => $col ) {
			if ( isset( $args[ $in ] ) && $args[ $in ] !== '' ) {
				$data[ $col ] = (string) $args[ $in ];
			}
		}
		$res = wp_update_user( $data );
		if ( is_wp_error( $res ) ) {
			throw new UserError( esc_html( $res->get_error_message() ) );
		}
		return $this->shape( get_userdata( $id ) );
	}

	/** @param array<string,mixed> $args */
	public function updateUserRole( $root, array $args ): bool {
		if ( ! current_user_can( 'promote_users' ) ) {
			throw new UserError( esc_html( 'You do not have permission to change user roles.' ) );
		}
		$role = $this->assignableRoleOrFail( (string) $args['role'] );
		$user = get_userdata( (int) $args['id'] );
		if ( ! $user ) {
			throw new UserError( esc_html( 'No such user.' ) );
		}
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			throw new UserError( esc_html( 'You do not have permission to edit this user.' ) );
		}
		$user->set_role( $role );
		return true;
	}

	/** @param array<string,mixed> $args */
	public function deleteUser( $root, array $args ): bool {
		if ( ! current_user_can( 'delete_users' ) ) {
			throw new UserError( esc_html( 'You do not have permission to delete users.' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$id       = (int) $args['id'];
		$reassign = isset( $args['reassignToId'] ) ? (int) $args['reassignToId'] : null;
		return (bool) wp_delete_user( $id, $reassign );
	}
}
