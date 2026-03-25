<?php
/**
 * 'Membership' tabs.
 *
 * Overrides the theme's version to point 'Invite New Members' at the
 * plugin's /invitations/ route instead of Invite Anyone's /invite-anyone/.
 *
 * @package CBOX\OL\GroupInvitations
 */

$group        = groups_get_current_group();
$group_status = ( $group instanceof \BP_Groups_Group ) ? $group->status : null;

$current_tab = bp_action_variable( 0 );

?>

<?php if ( bp_is_item_admin() ) : ?>
	<li class="<?php echo 'manage-members' === $current_tab ? 'current-menu-item' : ''; ?>"><a href="<?php echo esc_attr( bp_get_group_manage_url( $group, bp_groups_get_path_chunks( [ 'manage-members' ], 'manage' ) ) ); ?>"><?php esc_html_e( 'Membership', 'cboxol-group-invitations' ); ?></a></li>

	<?php if ( 'private' === $group_status ) : ?>
		<li class="<?php echo 'membership-requests' === $current_tab ? 'current-menu-item' : ''; ?>"><a href="<?php echo esc_attr( bp_get_group_manage_url( $group, bp_groups_get_path_chunks( [ 'membership-requests' ], 'manage' ) ) ); ?>"><?php esc_html_e( 'Member Requests', 'cboxol-group-invitations' ); ?></a></li>
	<?php endif; ?>
<?php else : ?>
	<li class="<?php echo bp_is_current_action( 'members' ) ? 'current-menu-item' : ''; ?>"><a href="<?php echo esc_attr( bp_get_group_url( $group, bp_groups_get_path_chunks( [ 'members' ] ) ) ); ?>"><?php esc_html_e( 'Membership', 'cboxol-group-invitations' ); ?></a></li>
<?php endif; ?>

<?php if ( bp_group_is_member() && invite_anyone_access_test() && openlab_is_admin_truly_member() ) : ?>
	<li class="<?php echo bp_is_current_action( 'invitations' ) ? 'current-menu-item' : ''; ?>"><a href="<?php echo esc_attr( bp_get_group_url( $group, bp_groups_get_path_chunks( [ 'invitations' ] ) ) ); ?>"><?php esc_html_e( 'Invite New Members', 'cboxol-group-invitations' ); ?></a></li>
<?php endif; ?>

<?php if ( bp_is_item_admin() ) : ?>
	<li class="<?php echo 'notifications' === $current_tab ? 'current-menu-item' : ''; ?>"><a href="<?php echo esc_attr( bp_get_group_manage_url( $group, bp_groups_get_path_chunks( [ 'notifications' ], 'manage' ) ) ); ?>"><?php esc_html_e( 'Email Members', 'cboxol-group-invitations' ); ?></a></li>
<?php endif; ?>

<?php if ( bp_group_is_member() && openlab_is_admin_truly_member() ) : ?>
	<li class="<?php echo bp_is_current_action( 'notifications' ) ? 'current-menu-item' : ''; ?>"><a href="<?php echo esc_attr( bp_get_group_url( $group, bp_groups_get_path_chunks( [ 'notifications' ] ) ) ); ?>"><?php esc_html_e( 'Your Email Options', 'cboxol-group-invitations' ); ?></a></li>
<?php endif; ?>
