<?php
/**
 * Dispatcher for the Invitations group tab sub-panels.
 *
 * @package CBOX\OL\GroupInvitations
 */

$sub_action = bp_action_variable( 0 );

if ( 'invite-new-members' === $sub_action ) {
	bp_get_template_part( 'groups/single/invitations/invite-new-members' );
} elseif ( 'sent-invitations' === $sub_action ) {
	bp_get_template_part( 'groups/single/invitations/sent-invitations' );
}
