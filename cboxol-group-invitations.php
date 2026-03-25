<?php
/**
 * Plugin Name:       CBOX OpenLab Group Invitations
 * Plugin URI:        https://github.com/cuny-academic-commons/cboxol-group-invitations
 * Description:       Replaces the BuddyPress group invitation interface with a custom-built one for Commons In A Box OpenLab.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Commons In A Box team
 * Author URI:        https://commons-in-a-obx
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       cboxol-group-invitations
 * Domain Path:       /languages
 *
 * @package CBOX\OL\GroupInvitations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/vendor/autoload.php';

add_action(
	'plugins_loaded',
	static function (): void {
		\CBOX\OL\GroupInvitations\App::init();
	}
);
