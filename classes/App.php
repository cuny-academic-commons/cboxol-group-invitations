<?php
/**
 * Main application class.
 *
 * @package CBOX\OL\GroupInvitations
 */

namespace CBOX\OL\GroupInvitations;

/**
 * App singleton.
 *
 * Bootstraps the plugin by registering hooks for the BP template stack
 * and asset enqueueing.
 */
class App {

	/**
	 * Singleton instance.
	 *
	 * @var App|null
	 */
	private static ?App $instance = null;

	/**
	 * Private constructor — use App::init() instead.
	 */
	private function __construct() {}

	/**
	 * Initialise the plugin and return the singleton instance.
	 *
	 * @return self
	 */
	public static function init(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->setup();
		}

		return self::$instance;
	}

	/**
	 * Register all plugin hooks.
	 *
	 * @return void
	 */
	private function setup(): void {
		// Prepend our templates directory to the BuddyPress template stack so that
		// bp_get_template_part() checks this plugin's /templates directory before
		// the active theme. This lets us override specific theme BP templates.
		add_filter( 'bp_get_template_stack', [ $this, 'add_template_dir_to_stack' ] );

		// Register the Invitations group nav tab and suppress Invite Anyone's.
		add_action( 'bp_setup_nav', [ $this, 'register_group_nav' ], 20 );

		// Redirect direct visits to the old invite-anyone group URL to our page.
		add_action( 'bp_screens', [ $this, 'redirect_invite_anyone' ] );

		// Apply 'current-menu-item' class when the Invitations tab is active.
		add_filter( 'bp_get_options_nav_invitations', [ $this, 'filter_invitations_nav' ] );

		// Ensure the 'Membership' tab is highlighted when on the Invitations page.
		add_filter( 'bp_get_options_nav_members', [ $this, 'filter_members_nav_current' ], 20 );

		// Enqueue built assets on the front end.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Register the REST API endpoints for member autosuggest and address validation.
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		// Allow the cboxol_match_users_by_email_address capability to be granted via
		// filters or role assignments without requiring a hard-coded role check here.
		add_filter( 'map_meta_cap', [ $this, 'map_meta_cap' ], 10, 2 );
	}

	/**
	 * Prepend the plugin's templates directory to the BuddyPress template stack.
	 *
	 * Prepending (rather than appending) ensures the plugin's templates are
	 * checked before the active theme, allowing selective overrides of specific
	 * theme BP template parts.
	 *
	 * @param string[] $stack Ordered list of template directories.
	 * @return string[]
	 */
	public function add_template_dir_to_stack( array $stack ): array {
		array_unshift( $stack, CBOXOL_GROUP_INVITATIONS_TEMPLATES_DIR );
		return $stack;
	}

	/**
	 * Register the Invitations subnav tab on group pages and suppress Invite Anyone's tab.
	 *
	 * Runs at priority 20 on bp_setup_nav so that Invite Anyone (priority default/10)
	 * has already registered its tab before we remove it.
	 *
	 * @return void
	 */
	public function register_group_nav(): void {
		if ( ! bp_is_group() ) {
			return;
		}

		$current_group_id = bp_get_current_group_id();
		$group_link       = bp_get_group_url( $current_group_id );
		$group_slug       = bp_get_current_group_slug();

		bp_core_new_subnav_item(
			[
				'name'            => __( 'Invite New Members', 'cboxol-group-invitations' ),
				'slug'            => 'invitations',
				'parent_slug'     => $group_slug,
				'parent_url'      => $group_link,
				'screen_function' => [ $this, 'render_invitations_screen' ],
				'position'        => 71,
				'user_has_access' => groups_is_user_admin( bp_loggedin_user_id(), $current_group_id ) || groups_is_user_mod( bp_loggedin_user_id(), $current_group_id ),
			]
		);

		// Suppress Invite Anyone's group tab if the plugin is active.
		if ( defined( 'BP_INVITE_ANYONE_SLUG' ) ) {
			bp_core_remove_subnav_item( $group_slug, BP_INVITE_ANYONE_SLUG );
		}
	}

	/**
	 * Screen function for the Invitations group tab.
	 *
	 * Tells BuddyPress which template to load when the tab is active.
	 *
	 * @return void
	 */
	public function render_invitations_screen(): void {
		add_action( 'bp_template_content', [ $this, 'render_invitations_template' ] );
		bp_core_load_template( [ 'groups/single/plugins' ] );
	}

	/**
	 * Renders the content for the Invitations group tab.
	 *
	 * Called by the bp_template_content action in render_invitations_screen().
	 *
	 * @return void
	 */
	public function render_invitations_template(): void {
		bp_get_template_part( 'groups/single/invitations' );
	}

	/**
	 * Redirect direct visits to the Invite Anyone group URL to our Invitations page.
	 *
	 * Handles the case where someone bookmarked or navigates to the old URL.
	 *
	 * @return void
	 */
	public function redirect_invite_anyone(): void {
		if ( ! bp_is_group() || ! bp_is_current_action( 'invite-anyone' ) ) {
			return;
		}

		$group = groups_get_current_group();
	//	bp_core_redirect( bp_get_group_url( $group, bp_groups_get_path_chunks( [ 'invitations' ] ) ) );
	}

	/**
	 * Swap BP's 'current selected' class for 'current-menu-item' on the Invitations tab.
	 *
	 * The theme applies this same transform to every other group nav item; without
	 * it our tab would never be styled as active.
	 *
	 * @param string $subnav_item Nav item HTML.
	 * @return string
	 */
	public function filter_invitations_nav( string $subnav_item ): string {
		return str_replace( 'current selected', 'current-menu-item', $subnav_item );
	}

	/**
	 * Mark the 'Membership' tab as current when the Invitations page is active.
	 *
	 * The theme's openlab_filter_subnav_members() only checks for 'invite-anyone';
	 * this filter runs after it (priority 20) and adds the class for our slug.
	 *
	 * @param string $subnav_item Nav item HTML already processed by the theme.
	 * @return string
	 */
	public function filter_members_nav_current( string $subnav_item ): string {
		if ( bp_is_group() && bp_is_current_action( 'invitations' ) ) {
			$subnav_item = str_replace(
				'id="members-groups-li"',
				'id="members-groups-li" class="current-menu-item"',
				$subnav_item
			);
		}

		return $subnav_item;
	}

	/**
	 * Enqueue compiled JS and CSS assets.
	 *
	 * Assets are built by `@wordpress/scripts` into the /build directory.
	 * The generated asset manifest (build/index.asset.php) provides
	 * dependency and version information.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$asset_file = CBOXOL_GROUP_INVITATIONS_DIR . 'build/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		/*
		 * @var array{dependencies: string[], version: string} $asset Built asset info
		 */
		$asset = require $asset_file;

		wp_enqueue_script(
			'cboxol-group-invitations',
			CBOXOL_GROUP_INVITATIONS_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Pass REST endpoint URLs, nonce, and user capability flags to the front-end.
		wp_localize_script(
			'cboxol-group-invitations',
			'cboxolGroupInvitations',
			[
				'restEndpoint'     => rest_url( 'cboxol-group-invitations/v1/suggest-members' ),
				'validateEndpoint' => rest_url( 'cboxol-group-invitations/v1/validate-address' ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'allowedDomains'   => self::get_allowed_email_domains(),
				'matchByEmail'     => current_user_can( 'cboxol_match_users_by_email_address' ),
			]
		);

		$style_file = CBOXOL_GROUP_INVITATIONS_DIR . 'build/index.css';
		if ( file_exists( $style_file ) ) {
			wp_enqueue_style(
				'cboxol-group-invitations',
				CBOXOL_GROUP_INVITATIONS_URL . 'build/index.css',
				[],
				$asset['version']
			);
		}
	}

	/**
	 * Returns the site's allowed email domains from the `limited_email_domains`
	 * site option set by cbox-openlab-core.
	 *
	 * An empty array means no domain restriction is configured.
	 *
	 * @return string[]
	 */
	private static function get_allowed_email_domains(): array {
		$raw = get_site_option( 'limited_email_domains' );

		if ( ! is_array( $raw ) ) {
			return [];
		}

		return array_values( array_filter( array_map( 'strval', $raw ) ) );
	}

	/**
	 * Allows the cboxol_match_users_by_email_address capability to be mapped
	 * (e.g. granted to specific roles via a filter added elsewhere).
	 *
	 * By default the cap maps to itself, meaning only users/roles that have been
	 * explicitly granted it (or filtered to have it) will pass the check.
	 *
	 * @param string[] $caps    Primitive caps required.
	 * @param string   $cap     Meta cap being checked.
	 * @return string[]
	 */
	public function map_meta_cap( array $caps, string $cap ): array {
		if ( 'cboxol_match_users_by_email_address' === $cap ) {
			return [ 'cboxol_match_users_by_email_address' ];
		}
		return $caps;
	}

	/**
	 * Register REST API routes for this plugin.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'cboxol-group-invitations/v1',
			'/suggest-members',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_suggest_members' ],
				'permission_callback' => static fn() => is_user_logged_in(),
				'args'                => [
					'query' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn( $v ) => strlen( trim( $v ) ) >= 2,
					],
				],
			]
		);

		register_rest_route(
			'cboxol-group-invitations/v1',
			'/validate-address',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_validate_address' ],
				'permission_callback' => static fn() => is_user_logged_in(),
				'args'                => [
					'email' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => 'is_email',
					],
				],
			]
		);
	}

	/**
	 * REST callback: search community members by name / username / (privileged) email.
	 *
	 * Privileged users (cboxol_match_users_by_email_address) get results that
	 * include the matched email address; unprivileged users get results filtered
	 * to BP friends only, with no email address in the response.
	 *
	 * Response shape (privileged):
	 *   [{ "value": "jane@example.com", "userId": 5, "displayName": "Jane Smith",
	 *      "userNicename": "jsmith" }, …]
	 *
	 * Response shape (unprivileged):
	 *   [{ "value": "jsmith", "userId": 5, "displayName": "Jane Smith",
	 *      "userNicename": "jsmith" }, …]
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function rest_suggest_members( \WP_REST_Request $request ): \WP_REST_Response {
		$query         = $request->get_param( 'query' );
		$match_by_email = current_user_can( 'cboxol_match_users_by_email_address' );

		$search_columns = [ 'display_name', 'user_nicename' ];
		if ( $match_by_email ) {
			$search_columns[] = 'user_email';
		}

		$users = get_users(
			[
				'search'         => '*' . $query . '*',
				'search_columns' => $search_columns,
				'number'         => 20, // Fetch more to allow for friend filtering.
				'fields'         => [ 'ID', 'display_name', 'user_nicename', 'user_email' ],
				'exclude'        => [ get_current_user_id() ],
			]
		);

		// Unprivileged: filter to BP friends of the current user.
		if ( ! $match_by_email ) {
			$users = $this->filter_to_friends( $users );
		}

		// Cap results after filtering.
		$users = array_slice( $users, 0, 10 );

		$suggestions = array_map(
			static function ( $user ) use ( $match_by_email ) {
				$item = [
					'value'        => $match_by_email ? $user->user_email : $user->user_nicename,
					'userId'       => $user->ID,
					'displayName'  => $user->display_name,
					'userNicename' => $user->user_nicename,
				];

				if ( $match_by_email ) {
					$item['email'] = $user->user_email;
				}

				return $item;
			},
			$users
		);

		return rest_ensure_response( array_values( $suggestions ) );
	}

	/**
	 * REST callback: validate a single email address.
	 *
	 * Looks up the corresponding WP user and applies privacy rules:
	 *
	 * Privileged: returns full user data including email if the user exists.
	 * Unprivileged: returns user data (no email) only if the user is a BP friend
	 *   of the current user; returns {found: false} otherwise (including when the
	 *   user exists but is not a friend, to avoid leaking account existence).
	 *
	 * Response (found, privileged):
	 *   { "found": true, "userId": 5, "displayName": "Jane", "userNicename": "jsmith",
	 *     "email": "jane@example.com" }
	 *
	 * Response (found, unprivileged friend):
	 *   { "found": true, "userId": 5, "displayName": "Jane", "userNicename": "jsmith" }
	 *
	 * Response (not found / not accessible):
	 *   { "found": false }
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function rest_validate_address( \WP_REST_Request $request ): \WP_REST_Response {
		$email          = $request->get_param( 'email' );
		$match_by_email = current_user_can( 'cboxol_match_users_by_email_address' );

		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			return rest_ensure_response( [ 'found' => false ] );
		}

		// Unprivileged: only expose users who are BP friends; don't leak the
		// existence of other users' accounts.
		if ( ! $match_by_email ) {
			if ( ! $this->is_bp_friend( $user->ID ) ) {
				return rest_ensure_response( [ 'found' => false ] );
			}

			return rest_ensure_response(
				[
					'found'        => true,
					'userId'       => $user->ID,
					'displayName'  => $user->display_name,
					'userNicename' => $user->user_nicename,
					// No email — unprivileged users must not learn the email address.
				]
			);
		}

		return rest_ensure_response(
			[
				'found'        => true,
				'userId'       => $user->ID,
				'displayName'  => $user->display_name,
				'userNicename' => $user->user_nicename,
				'email'        => $user->user_email,
			]
		);
	}

	/**
	 * Filters an array of user objects to those who are BP friends of the
	 * current user.
	 *
	 * Falls back to returning all users if the BP Friends component is not active.
	 *
	 * @param object[] $users
	 * @return object[]
	 */
	private function filter_to_friends( array $users ): array {
		if ( ! function_exists( 'friends_get_friend_user_ids' ) ) {
			return $users;
		}

		$friend_ids = friends_get_friend_user_ids( get_current_user_id() );

		if ( empty( $friend_ids ) ) {
			return [];
		}

		return array_values(
			array_filter( $users, static fn( $u ) => in_array( (int) $u->ID, array_map( 'intval', $friend_ids ), true ) )
		);
	}

	/**
	 * Checks whether a user is a BP friend of the current user.
	 *
	 * Returns true if the BP Friends component is not active (graceful fallback).
	 *
	 * @param int $user_id
	 * @return bool
	 */
	private function is_bp_friend( int $user_id ): bool {
		if ( ! function_exists( 'friends_check_friendship' ) ) {
			return true;
		}
		return friends_check_friendship( get_current_user_id(), $user_id );
	}
}
