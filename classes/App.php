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
		bp_core_redirect( bp_get_group_url( $group, bp_groups_get_path_chunks( [ 'invitations' ] ) ) );
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
}
