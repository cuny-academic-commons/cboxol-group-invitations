<?php
/**
 * Plugin constants.
 *
 * Defined in a separate file for better compatibility with PHPStan
 * (avoids conditional constant definition issues in the main loader).
 *
 * @package CBOX\OL\GroupInvitations
 */

define( 'CBOXOL_GROUP_INVITATIONS_VERSION', '1.0.0' );
define( 'CBOXOL_GROUP_INVITATIONS_DIR', plugin_dir_path( __FILE__ ) );
define( 'CBOXOL_GROUP_INVITATIONS_URL', plugin_dir_url( __FILE__ ) );
define( 'CBOXOL_GROUP_INVITATIONS_TEMPLATES_DIR', CBOXOL_GROUP_INVITATIONS_DIR . 'templates' );
