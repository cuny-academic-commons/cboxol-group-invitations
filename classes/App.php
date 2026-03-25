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
		// Add our templates directory to the BuddyPress template stack so that
		// bp_get_template_part() checks this plugin's /templates directory after
		// the active theme.
		add_filter( 'bp_get_template_stack', [ $this, 'add_template_dir_to_stack' ] );

		// Enqueue built assets on the front end.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Append the plugin's templates directory to the BuddyPress template stack.
	 *
	 * @param string[] $stack Ordered list of template directories.
	 * @return string[]
	 */
	public function add_template_dir_to_stack( array $stack ): array {
		$stack[] = CBOXOL_GROUP_INVITATIONS_TEMPLATES_DIR;
		return $stack;
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

		/** @var array{dependencies: string[], version: string} $asset */
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
