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

		// Process submissions from the custom invitations form.
		add_action( 'bp_actions', [ $this, 'handle_invitations_submission' ] );

		// Apply 'current-menu-item' class when the Invitations tab is active.
		add_filter( 'bp_get_options_nav_invitations', [ $this, 'filter_invitations_nav' ] );

		// Render the Invite New Members / Sent Invitations sub-nav tabs.
		add_action( 'bp_group_plugin_options_nav', [ $this, 'render_invitations_options_nav' ] );

		// Ensure the 'Membership' tab is highlighted when on the Invitations page.
		add_filter( 'bp_get_options_nav_members', [ $this, 'filter_members_nav_current' ], 20 );

		// Enqueue built assets on the front end.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Register the REST API endpoints for member autosuggest and address validation.
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		// Allow the cboxol_match_users_by_email_address capability to be granted via
		// filters or role assignments without requiring a hard-coded role check here.
		add_filter( 'map_meta_cap', [ $this, 'map_meta_cap' ], 10, 3 );
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
				'user_has_access' => groups_is_user_member( bp_loggedin_user_id(), $current_group_id ),
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
	 * Dispatches to the correct sub-panel based on the first action variable,
	 * or redirects to the default panel when no sub-action is present.
	 *
	 * @return void
	 */
	public function render_invitations_screen(): void {
		$sub_action = bp_action_variable( 0 );

		if ( ! $sub_action ) {
			bp_core_redirect(
				bp_get_group_url(
					groups_get_current_group(),
					bp_groups_get_path_chunks( [ 'invitations', 'invite-new-members' ] )
				)
			);
			return;
		}

		if ( ! in_array( $sub_action, [ 'invite-new-members', 'sent-invitations' ], true ) ) {
			bp_core_redirect(
				bp_get_group_url(
					groups_get_current_group(),
					bp_groups_get_path_chunks( [ 'invitations', 'invite-new-members' ] )
				)
			);
			return;
		}

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
	 * Renders the sub-nav tabs for the Invitations section.
	 *
	 * Hooked onto bp_group_plugin_options_nav, which themes call inside a
	 * <ul class="nav nav-inline"> when no component-specific nav is present.
	 * Returns early if the current action is not 'invitations'.
	 *
	 * @return void
	 */
	public function render_invitations_options_nav(): void {
		if ( ! bp_is_current_action( 'invitations' ) ) {
			return;
		}

		$group      = groups_get_current_group();
		$sub_action = bp_action_variable( 0 );

		if ( ! $group instanceof \BP_Groups_Group ) {
			return;
		}

		$tabs = [];

		if ( bp_is_item_admin() ) {
			$tabs['manage-members'] = [
				'label'      => __( 'Manage Members', 'cboxol-group-invitations' ),
				'url'        => bp_get_group_manage_url( $group, bp_groups_get_path_chunks( [ 'manage-members' ], 'manage' ) ),
				'is_current' => false,
			];
		} else {
			$tabs['membership'] = [
				'label'      => __( 'Membership', 'cboxol-group-invitations' ),
				'url'        => bp_get_group_url( $group, bp_groups_get_path_chunks( [ 'members' ] ) ),
				'is_current' => false,
			];
		}

		if ( bp_is_item_admin() && 'private' === $group->status ) {
			// Pending membership request count.
			$membership_query = new \BP_Group_Member_Query(
				[
					'group_id'        => $group->id,
					'is_confirmed'    => false,
					'inviter_id'      => 0,
					'populate_extras' => false,
				]
			);

			$membership_indicator_class = count( $membership_query->results ) > 0 ? 'has-action-indicator' : '';

			$tabs['membership-requests'] = [
				'label'      => __( 'Membership Requests', 'cboxol-group-invitations' ),
				'url'        => bp_get_group_manage_url( $group, bp_groups_get_path_chunks( [ 'membership-requests' ], 'manage' ) ),
				'is_current' => false,
				'a_class'    => $membership_indicator_class,
			];
		}

		$tabs['invite-new-members'] = [
			'label'      => __( 'Invite New Members', 'cboxol-group-invitations' ),
			'url'        => bp_get_group_url( $group, bp_groups_get_path_chunks( [ 'invitations', 'invite-new-members' ] ) ),
			'is_current' => 'invite-new-members' === $sub_action,
		];

		$tabs['sent-invitations'] = [
			'label'      => __( 'Sent Invitations', 'cboxol-group-invitations' ),
			'url'        => bp_get_group_url( $group, bp_groups_get_path_chunks( [ 'invitations', 'sent-invitations' ] ) ),
			'is_current' => 'sent-invitations' === $sub_action,
		];

		if ( bp_is_item_admin() ) {
			$tabs['email-members'] = [
				'label'      => __( 'Email Members', 'cboxol-group-invitations' ),
				'url'        => bp_get_group_manage_url( $group, bp_groups_get_path_chunks( [ 'notifications' ], 'manage' ) ),
				'is_current' => false,
			];
		}

		$tabs['notifications'] = [
			'label'      => __( 'Your Email Options', 'cboxol-group-invitations' ),
			'url'        => bp_get_group_manage_url( $group, bp_groups_get_path_chunks( [ 'notifications' ], 'manage' ) ),
			'is_current' => false,
		];

		foreach ( $tabs as $slug => $tab ) {
			$class   = $tab['is_current'] ? 'current-menu-item' : '';
			$a_class = isset( $tab['a_class'] ) ? $tab['a_class'] : '';
			printf(
				'<li class="%s"><a href="%s" class="%s">%s</a></li>',
				esc_attr( $class ),
				esc_url( $tab['url'] ),
				esc_attr( $a_class ),
				esc_html( $tab['label'] )
			);
		}
	}

	/**
	 * Returns the current group's stored import results, if present.
	 *
	 * @return array<string, string[]>|null
	 */
	public static function get_current_import_results(): ?array {
		if ( ! bp_is_group() ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$import_id = isset( $_GET['import_id'] ) ? absint( wp_unslash( $_GET['import_id'] ) ) : 0;
		if ( ! $import_id ) {
			return null;
		}

		$results = groups_get_groupmeta( bp_get_current_group_id(), self::get_import_results_meta_key( $import_id ) );
		return is_array( $results ) ? $results : null;
	}

	/**
	 * Determines whether a user should directly add matched members.
	 *
	 * The default behavior preserves backward compatibility with
	 * openlab_user_can_bulk_import_group_members(), but the filter makes it easy
	 * to swap in a different rule later.
	 *
	 * @param int $group_id Group ID.
	 * @param int $user_id  User ID.
	 * @return bool
	 */
	public static function current_user_can_direct_add_members( int $group_id, int $user_id ): bool {
		$can_direct_add = false;

		if ( function_exists( 'openlab_user_can_bulk_import_group_members' ) ) {
			$can_direct_add = openlab_user_can_bulk_import_group_members( $group_id, $user_id );
		}

		/**
		 * Filters whether the current user should directly add matched members.
		 *
		 * @param bool $can_direct_add Whether the user can directly add members.
		 * @param int  $group_id       Group ID.
		 * @param int  $user_id        User ID.
		 */
		return (bool) apply_filters( 'cboxol_group_invitations_user_can_direct_add_members', $can_direct_add, $group_id, $user_id );
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

		// phpcs:ignore
		//bp_core_redirect( bp_get_group_url( $group, bp_groups_get_path_chunks( [ 'invitations' ] ) ) );
	}

	/**
	 * Processes submissions from the custom group invitations form.
	 *
	 * @return void
	 */
	public function handle_invitations_submission(): void {
		if ( ! bp_is_group() || ! bp_is_current_action( 'invitations' ) || ! bp_is_action_variable( 'send', 0 ) ) {
			return;
		}

		if ( ! isset( $_POST['group-import-members-nonce'] ) ) {
			return;
		}

		check_admin_referer( 'group_import_members', 'group-import-members-nonce' );

		$group_id = bp_get_current_group_id();
		$user_id  = bp_loggedin_user_id();

		if ( ! $this->current_user_can_access_invitations_screen( $group_id, $user_id ) ) {
			bp_core_add_message( __( 'You are not allowed to invite members to this group.', 'cboxol-group-invitations' ), 'error' );
			bp_core_redirect( $this->get_invitations_url( $group_id ) );
		}

		$can_direct_add = self::current_user_can_direct_add_members( $group_id, $user_id );
		if ( $can_direct_add ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$import_mode = isset( $_POST['import-existing-members-mode'] ) ? sanitize_text_field( wp_unslash( $_POST['import-existing-members-mode'] ) ) : '';
			if ( ! in_array( $import_mode, [ 'invite', 'direct-add' ], true ) ) {
				bp_core_add_message( __( 'Please choose how existing members should be added.', 'cboxol-group-invitations' ), 'error' );
				bp_core_redirect( $this->get_invitations_url( $group_id ) );
			}

			$can_direct_add = 'direct-add' === $import_mode;
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$is_acknowledged = isset( $_POST['import-acknowledge-checkbox'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['import-acknowledge-checkbox'] ) );
			if ( ! $is_acknowledged ) {
				bp_core_add_message( __( 'Please acknowledge the import statement before continuing.', 'cboxol-group-invitations' ), 'error' );
				bp_core_redirect( $this->get_invitations_url( $group_id ) );
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$user_ids = $this->parse_user_ids( isset( $_POST['invite-user-ids'] ) ? sanitize_text_field( wp_unslash( $_POST['invite-user-ids'] ) ) : '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$emails = $this->parse_emails( isset( $_POST['invite-emails'] ) ? sanitize_text_field( wp_unslash( $_POST['invite-emails'] ) ) : '' );

		if ( [] === $user_ids && [] === $emails ) {
			bp_core_add_message( __( 'Please enter at least one valid member or email address.', 'cboxol-group-invitations' ), 'error' );
			bp_core_redirect( $this->get_invitations_url( $group_id ) );
		}

		$results = [
			'submitted'         => [],
			'added'             => [],
			'invited'           => [],
			'emailed'           => [],
			'already_member'    => [],
			'already_invited'   => [],
			'invalid_address'   => [],
			'illegal_address'   => [],
			'inaccessible_user' => [],
			'failed'            => [],
		];

		$can_match_by_email = current_user_can( 'cboxol_match_users_by_email_address' );
		$invite_scope       = $this->get_group_invite_scope( $group_id, $user_id );
		$pending_invites    = [];

		foreach ( $user_ids as $invited_user_id ) {
			$user = get_userdata( $invited_user_id );
			if ( ! $user instanceof \WP_User ) {
				continue;
			}

			$results['submitted'][] = $this->get_user_submission_label( $user );

			$this->process_matched_user(
				$group_id,
				$user_id,
				$user,
				$can_direct_add,
				$invite_scope,
				$pending_invites,
				$results
			);
		}

		foreach ( $emails as $email ) {
			$results['submitted'][] = $email;

			$classification = $this->classify_email_address( $email );
			if ( 'invalid' === $classification ) {
				$results['invalid_address'][] = $email;
				continue;
			}

			if ( 'illegal' === $classification ) {
				$results['illegal_address'][] = $email;
				continue;
			}

			if ( ! $can_match_by_email ) {
				$results['inaccessible_user'][] = $email;
				continue;
			}

			$user = get_user_by( 'email', $email );
			if ( $user instanceof \WP_User ) {
				$this->process_matched_user(
					$group_id,
					$user_id,
					$user,
					$can_direct_add,
					$invite_scope,
					$pending_invites,
					$results
				);
				continue;
			}

			if ( ! $this->current_user_can_send_email_invites() ) {
				$results['failed'][] = $email;
				continue;
			}

			if ( $this->create_email_invitation( $user_id, $group_id, $email ) ) {
				$results['emailed'][] = $email;
			} else {
				$results['failed'][] = $email;
			}
		}

		if ( $pending_invites ) {
			if ( $this->send_group_invites( $user_id, $group_id ) ) {
				$results['invited'] = array_merge( $results['invited'], $pending_invites );
			} else {
				$results['failed'] = array_merge( $results['failed'], $pending_invites );
			}
		}

		foreach ( $results as &$items ) {
			$items = array_values( array_unique( $items ) );
		}
		unset( $items );

		$timestamp = time();
		groups_update_groupmeta( $group_id, self::get_import_results_meta_key( $timestamp ), $results );

		bp_core_redirect(
			add_query_arg(
				'import_id',
				(string) $timestamp,
				$this->get_invitations_url( $group_id )
			)
		);
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
	 * The cap is granted automatically when the user's member type allows
	 * importing group users (get_can_import_group_users()), mirroring the
	 * user-level portion of openlab_user_can_bulk_import_group_members() without
	 * requiring a group context.
	 *
	 * By default the cap maps to itself, meaning only users/roles that have been
	 * explicitly granted it (or filtered to have it) will pass the check.
	 *
	 * @param string[] $caps    Primitive caps required.
	 * @param string   $cap     Meta cap being checked.
	 * @param int      $user_id User ID performing the check.
	 * @return string[]
	 */
	public function map_meta_cap( array $caps, string $cap, int $user_id ): array {
		if ( 'cboxol_match_users_by_email_address' === $cap ) {
			if ( function_exists( 'cboxol_get_user_member_type' ) ) {
				$member_type = cboxol_get_user_member_type( $user_id );
				if ( $member_type && ! is_wp_error( $member_type ) && $member_type->get_can_import_group_users() ) {
					return [ 'exist' ];
				}
			}
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
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function rest_suggest_members( \WP_REST_Request $request ): \WP_REST_Response {
		$query          = $request->get_param( 'query' );
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
	 * @param \WP_REST_Request $request Request object.
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
	 * @param \WP_User[] $users Array of WP_User objects.
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
	 * @param int $user_id User ID to check.
	 * @return bool
	 */
	private function is_bp_friend( int $user_id ): bool {
		if ( ! function_exists( 'friends_check_friendship' ) ) {
			return true;
		}
		return friends_check_friendship( get_current_user_id(), $user_id );
	}

	/**
	 * Returns the stored groupmeta key for an import results record.
	 *
	 * @param int $import_id Import timestamp.
	 * @return string
	 */
	private static function get_import_results_meta_key( int $import_id ): string {
		return 'cboxol_group_invitations_import_' . $import_id;
	}

	/**
	 * Returns the URL for the current group's Invite New Members screen.
	 *
	 * @param int $group_id Group ID.
	 * @return string
	 */
	private function get_invitations_url( int $group_id ): string {
		return bp_get_group_url( groups_get_group( $group_id ), bp_groups_get_path_chunks( [ 'invitations', 'invite-new-members' ] ) );
	}

	/**
	 * Returns whether the current user may access the custom invitations screen.
	 *
	 * @param int $group_id Group ID.
	 * @param int $user_id  User ID.
	 * @return bool
	 */
	private function current_user_can_access_invitations_screen( int $group_id, int $user_id ): bool {
		return groups_is_user_admin( $user_id, $group_id ) || groups_is_user_mod( $user_id, $group_id );
	}

	/**
	 * Parses a comma-delimited list of user IDs from the request.
	 *
	 * @param string $raw_value Raw submitted value.
	 * @return int[]
	 */
	private function parse_user_ids( string $raw_value ): array {
		$user_ids = preg_split( '/\s*,\s*/', trim( $raw_value ) );
		if ( ! is_array( $user_ids ) ) {
			return [];
		}

		$user_ids = array_map( 'absint', $user_ids );
		$user_ids = array_filter( $user_ids );
		return array_values( array_unique( $user_ids ) );
	}

	/**
	 * Parses a comma-delimited list of email addresses from the request.
	 *
	 * @param string $raw_value Raw submitted value.
	 * @return string[]
	 */
	private function parse_emails( string $raw_value ): array {
		$emails = preg_split( '/\s*,\s*/', trim( $raw_value ) );
		if ( ! is_array( $emails ) ) {
			return [];
		}

		$emails = array_map(
			static fn( string $email ): string => strtolower( trim( $email ) ),
			$emails
		);
		$emails = array_filter( $emails );
		return array_values( array_unique( $emails ) );
	}

	/**
	 * Categorises a submitted email address.
	 *
	 * @param string $email Email address.
	 * @return 'okay'|'invalid'|'illegal'
	 */
	private function classify_email_address( string $email ): string {
		if ( ! is_email( $email ) ) {
			return 'invalid';
		}

		$email_domains = get_site_option( 'limited_email_domains' );
		if ( ! is_array( $email_domains ) ) {
			$email_domains = preg_split( '/[\s,]+/', (string) $email_domains );
			$email_domains = false !== $email_domains ? array_filter( $email_domains ) : [];
		}

		if ( ! empty( $email_domains ) ) {
			$email_domain = strtolower( substr( $email, 1 + strpos( $email, '@' ) ) );
			if ( ! in_array( $email_domain, $email_domains, true ) ) {
				return 'illegal';
			}
		}

		if ( function_exists( 'is_email_address_unsafe' ) && is_email_address_unsafe( $email ) ) {
			return 'illegal';
		}

		return 'okay';
	}

	/**
	 * Returns the group's invite scope according to Invite Anyone.
	 *
	 * @param int $group_id Group ID.
	 * @param int $user_id  User ID.
	 * @return string
	 */
	private function get_group_invite_scope( int $group_id, int $user_id ): string {
		if ( function_exists( 'invite_anyone_group_invite_access_test' ) ) {
			return (string) invite_anyone_group_invite_access_test( $group_id, $user_id );
		}

		if ( function_exists( 'bp_groups_user_can_send_invites' ) && ! bp_groups_user_can_send_invites( $group_id ) ) {
			return 'noone';
		}

		return 'anyone';
	}

	/**
	 * Processes a matched existing user from the submission.
	 *
	 * @param int                          $group_id        Group ID.
	 * @param int                          $inviter_id      Logged-in user ID.
	 * @param \WP_User                     $user            Matched user.
	 * @param bool                         $can_direct_add  Whether matched users are added directly.
	 * @param string                       $invite_scope    Invite Anyone scope: anyone|friends|noone.
	 * @param array<int, string>           &$pending_invites Queued group-invite email addresses.
	 * @param array<string, array<string>> &$results         Result buckets.
	 * @return void
	 */
	private function process_matched_user(
		int $group_id,
		int $inviter_id,
		\WP_User $user,
		bool $can_direct_add,
		string $invite_scope,
		array &$pending_invites,
		array &$results
	): void {
		$email = strtolower( $user->user_email );

		if ( $this->email_already_processed( $email, $results, $pending_invites ) ) {
			return;
		}

		if ( groups_is_user_member( $user->ID, $group_id ) ) {
			$results['already_member'][] = $email;
			return;
		}

		if ( $can_direct_add ) {
			if ( ! groups_join_group( $group_id, $user->ID ) ) {
				$results['failed'][] = $email;
				return;
			}

			// Clear any previously sent or drafted invitation for this new member.
			groups_delete_invite( $user->ID, $group_id );

			$this->send_added_to_group_email( $group_id, $email );
			$results['added'][] = $email;
			return;
		}

		if ( function_exists( 'groups_check_user_has_invite' ) && groups_check_user_has_invite( $user->ID, $group_id ) ) {
			$results['already_invited'][] = $email;
			return;
		}

		if ( 'noone' === $invite_scope ) {
			$results['inaccessible_user'][] = $email;
			return;
		}

		if ( 'friends' === $invite_scope && ! $this->is_bp_friend( $user->ID ) ) {
			$results['inaccessible_user'][] = $email;
			return;
		}

		if ( groups_invite_user(
			[
				'user_id'     => $user->ID,
				'group_id'    => $group_id,
				'inviter_id'  => $inviter_id,
				'send_invite' => true,
			]
		) ) {
			$pending_invites[] = $email;
		} else {
			$results['failed'][] = $email;
		}
	}

	/**
	 * Returns whether an email is already represented in the result buckets.
	 *
	 * @param string                  $email           Email address.
	 * @param array<string, string[]> $results         Result buckets.
	 * @param string[]                $pending_invites Pending group invite emails.
	 * @return bool
	 */
	private function email_already_processed( string $email, array $results, array $pending_invites ): bool {
		$all_processed = [];
		foreach ( $results as $items ) {
			$all_processed = array_merge( $all_processed, $items );
		}

		$all_processed = array_merge( $all_processed, $pending_invites );
		return in_array( $email, $all_processed, true );
	}

	/**
	 * Sends queued BuddyPress group invites for a group.
	 *
	 * @param int $user_id  Inviter user ID.
	 * @param int $group_id Group ID.
	 * @return bool
	 */
	private function send_group_invites( int $user_id, int $group_id ): bool {
		if ( ! function_exists( 'groups_send_invites' ) ) {
			return false;
		}

		$bp_version = defined( 'BP_VERSION' ) ? BP_VERSION : '1.2';
		if ( version_compare( $bp_version, '5.0.0', '>=' ) ) {
			groups_send_invites(
				[
					'user_id'  => $user_id,
					'group_id' => $group_id,
				]
			);
		} else {
			groups_send_invites( $user_id, $group_id );
		}

		// BuddyPress sends queued invites but does not return a status value.
		return true;
	}

	/**
	 * Sends the "added to group" BuddyPress email used by the legacy bulk importer.
	 *
	 * @param int    $group_id Group ID.
	 * @param string $email    Recipient email.
	 * @return void
	 */
	private function send_added_to_group_email( int $group_id, string $email ): void {
		if ( ! function_exists( 'bp_send_email' ) ) {
			return;
		}

		if ( function_exists( 'openlab_maybe_install_added_to_group_email' ) ) {
			openlab_maybe_install_added_to_group_email();
		}

		$group = groups_get_group( $group_id );
		if ( empty( $group->id ) ) {
			return;
		}

		$group_type      = function_exists( 'cboxol_get_group_group_type' ) ? cboxol_get_group_group_type( $group_id ) : null;
		$group_type_slug = $group_type && ! is_wp_error( $group_type ) ? $group_type->get_slug() : '';
		$group_name      = stripslashes( $group->name );

		$email_subject = sprintf(
			// translators: %s is the group name.
			__( 'You are now a member of %s', 'commons-in-a-box' ),
			$group_name
		);

		$email_message = sprintf(
			// translators: %1$s is the group name, %2$s is a link to the group.
			__( '<p>You are now a member of %1$s.</p><p>Visit %2$s to make changes to your membership settings or to leave this course.</p>', 'commons-in-a-box' ),
			$group_name,
			sprintf( '<a href="%s">%s</a>', bp_get_group_url( $group ), $group_name )
		);

		bp_send_email(
			'openlab-added-to-group',
			$email,
			[
				'tokens' => [
					'usermessage'   => $email_message,
					'usersubject'   => $email_subject,
					'ol.group-name' => stripslashes( $group->name ),
					'ol.group-url'  => bp_get_group_url( $group ),
					'ol.group-type' => $group_type_slug,
				],
			]
		);
	}

	/**
	 * Returns whether the current user may create Invite Anyone email invitations.
	 *
	 * @return bool
	 */
	private function current_user_can_send_email_invites(): bool {
		return function_exists( 'invite_anyone_access_test' ) && invite_anyone_access_test();
	}

	/**
	 * Creates and emails an Invite Anyone invitation for an unmatched address.
	 *
	 * @param int    $inviter_id Inviter user ID.
	 * @param int    $group_id   Group ID.
	 * @param string $email      Invitee email address.
	 * @return bool
	 */
	private function create_email_invitation( int $inviter_id, int $group_id, string $email ): bool {
		if (
			! function_exists( 'invite_anyone_invitation_subject' )
			|| ! function_exists( 'invite_anyone_invitation_message' )
			|| ! function_exists( 'invite_anyone_get_accept_url' )
			|| ! function_exists( 'invite_anyone_get_opt_out_url' )
			|| ! function_exists( 'invite_anyone_process_footer' )
			|| ! function_exists( 'invite_anyone_wildcard_replace' )
			|| ! function_exists( 'invite_anyone_record_invitation' )
		) {
			return false;
		}

		$subject        = stripslashes( wp_strip_all_tags( invite_anyone_invitation_subject() ) );
		$custom_message = stripslashes( invite_anyone_invitation_message() );
		$accept_url     = invite_anyone_get_accept_url( $email );
		$opt_out_url    = invite_anyone_get_opt_out_url( $email );
		$footer         = invite_anyone_wildcard_replace( invite_anyone_process_footer(), $email );
		$message        = $custom_message . "\n\n================\n" . $footer;

		$data = [
			'invite_anyone_groups' => [ $group_id ],
		];

		/**
		 * Mirrors Invite Anyone's filter on outgoing invitation email addresses.
		 *
		 * @param string $email Email address.
		 * @param array  $data  Data about the invitation.
		 */
		$to = apply_filters( 'invite_anyone_invitee_email', $email, $data );

		/**
		 * Mirrors Invite Anyone's filter on outgoing invitation subjects.
		 *
		 * @param string $subject The email subject.
		 * @param array  $data    Invitation context.
		 * @param string $email   Invitee email address.
		 */
		$subject = apply_filters( 'invite_anyone_invitation_subject', $subject, $data, $email );

		/**
		 * Mirrors Invite Anyone's filter on outgoing invitation messages.
		 *
		 * @param string $message The email message.
		 * @param array  $data    Invitation context.
		 * @param string $email   Invitee email address.
		 */
		$message = apply_filters( 'invite_anyone_invitation_message', $message, $data, $email );

		$do_bp_email = function_exists( 'bp_send_email' ) && ! apply_filters( 'bp_email_use_wp_mail', false );

		if ( $do_bp_email ) {
			$bp_email_args = [
				'tokens'  => [
					'ia.subject'           => $subject,
					'ia.content'           => $custom_message,
					'ia.content_plaintext' => $message,
					'ia.accept_url'        => $accept_url,
					'ia.opt_out_url'       => $opt_out_url,
					'recipient.name'       => $to,
				],
				'subject' => $subject,
				'content' => $message,
			];

			$salutation_filter = null;
			if ( function_exists( 'invite_anyone_replace_bp_email_salutation' ) ) {
				$salutation_filter = static function ( $salutation, $settings ) {
					return invite_anyone_replace_bp_email_salutation( $salutation, $settings );
				};
				add_filter( 'bp_email_get_salutation', $salutation_filter, 10, 2 );
			}

			bp_send_email( 'invite-anyone-invitation', $to, $bp_email_args );

			if ( $salutation_filter ) {
				remove_filter( 'bp_email_get_salutation', $salutation_filter, 10 );
			}
		} else {
			wp_mail( $to, $subject, $message );
		}

		$record_id = invite_anyone_record_invitation( $inviter_id, $email, $message, [ (string) $group_id ], $subject, false );
		if ( ! $record_id ) {
			return false;
		}

		do_action( 'sent_email_invite', $inviter_id, $email, [ $group_id ] );
		do_action( 'sent_email_invites', $inviter_id, [ $email ], [ $group_id ] );

		return true;
	}

	/**
	 * Formats a matched user for the submitted-values summary.
	 *
	 * @param \WP_User $user Matched user.
	 * @return string
	 */
	private function get_user_submission_label( \WP_User $user ): string {
		$display_name = bp_core_get_user_displayname( $user->ID );
		if ( ! is_string( $display_name ) || '' === $display_name ) {
			$display_name = $user->display_name;
		}

		return sprintf(
			'%1$s (%2$s)',
			$display_name,
			$user->user_email
		);
	}
}
