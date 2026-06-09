<?php
/**
 * Template for the Sent Invitations sub-panel.
 *
 * @package CBOX\OL\GroupInvitations
 */

$group_id           = bp_get_current_group_id();
$user_id            = bp_loggedin_user_id();
$can_match_by_email = current_user_can( 'cboxol_match_users_by_email_address' );

// BP-native group invitations sent by the current user to this group.
// This includes both pending invitations (invite sent, not yet accepted) and
// direct adds (privileged users who bypassed the invite email step). BuddyPress
// removes invitation records upon acceptance, so accepted standard invitations
// are not queryable here.
$bp_invitations = [];
if ( function_exists( 'groups_get_invites' ) ) {
	$bp_invitations = groups_get_invites(
		[
			'inviter_id'  => $user_id,
			'item_id'     => $group_id,
			'invite_sent' => 'sent',
		]
	);
}
if ( ! is_array( $bp_invitations ) ) {
	$bp_invitations = [];
}

// Invite Anyone (site) invitations: stored as ia_invites CPT posts authored by
// the current user and tagged with this group in the ia_invited_groups taxonomy.
$ia_invitations = [];
$ia_post_type   = apply_filters( 'invite_anyone_post_type_name', 'ia_invites' );
$ia_group_tax   = apply_filters( 'invite_anyone_invited_group_tax_name', 'ia_invited_groups' );
$ia_invitee_tax = apply_filters( 'invite_anyone_invitee_tax_name', 'ia_invitees' );

if ( class_exists( 'Invite_Anyone_Invitation' ) ) {
	// Invite_Anyone_Invitation::get() does not support filtering by group, so
	// query the CPT directly with a tax_query. Group IDs are stored as term names.
	$ia_query       = new WP_Query(
		[
			'author'         => $user_id,
			'post_type'      => $ia_post_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => $ia_group_tax,
					'field'    => 'name',
					'terms'    => (string) $group_id,
				],
			],
		]
	);
	$ia_invitations = $ia_query->posts;
	wp_reset_postdata();
}

?>

<div class="form-panel">

<div class="panel panel-default">
	<div class="panel-heading semibold"><?php esc_html_e( 'Sent Invitations', 'cboxol-group-invitations' ); ?></div>
	<div class="panel-body">

		<p class="invite-copy"><?php esc_html_e( 'Below are the invitations to this group that you have sent, and members you have directly added. Standard group invitations are shown as pending until accepted or declined. Members added directly by privileged users are shown as added.', 'cboxol-group-invitations' ); ?></p>

		<h3 class="cboxol-gi-section-heading"><?php esc_html_e( 'Group Invitations', 'cboxol-group-invitations' ); ?></h3>

		<p class="invite-copy"><?php esc_html_e( 'Invitations sent to existing site members to join this group.', 'cboxol-group-invitations' ); ?></p>

		<?php if ( $bp_invitations ) : ?>
			<table class="cboxol-gi-sent-invitations-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Member', 'cboxol-group-invitations' ); ?></th>
						<?php if ( $can_match_by_email ) : ?>
							<th scope="col"><?php esc_html_e( 'Email', 'cboxol-group-invitations' ); ?></th>
						<?php endif; ?>
						<th scope="col"><?php esc_html_e( 'Date', 'cboxol-group-invitations' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'cboxol-group-invitations' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $bp_invitations as $invite ) : ?>
						<?php
						$invitee_user = get_userdata( $invite->user_id );
						if ( ! $invitee_user instanceof WP_User ) {
							continue;
						}

						$display_name = bp_core_get_user_displayname( $invite->user_id );
						if ( ! is_string( $display_name ) || '' === $display_name ) {
							$display_name = $invitee_user->display_name;
						}

						$profile_url   = bp_core_get_user_domain( $invite->user_id );
						$username      = bp_core_get_username( $invite->user_id );
						$date          = date_i18n( get_option( 'date_format' ), strtotime( $invite->date_modified ) );
						$is_member     = groups_is_user_member( $invite->user_id, $group_id );
						$invite_status = $is_member
							? __( 'Added', 'cboxol-group-invitations' )
							: __( 'Pending', 'cboxol-group-invitations' );
						?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $profile_url ); ?>"><?php echo esc_html( $display_name ); ?></a>
								<span class="cboxol-gi-username">(<?php echo esc_html( $username ); ?>)</span>
							</td>
							<?php if ( $can_match_by_email ) : ?>
								<td><?php echo esc_html( $invitee_user->user_email ); ?></td>
							<?php endif; ?>
							<td><?php echo esc_html( $date ); ?></td>
							<td><?php echo esc_html( $invite_status ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="invite-copy"><?php esc_html_e( 'You have no group invitations or direct additions for this group.', 'cboxol-group-invitations' ); ?></p>
		<?php endif; ?>

		<?php if ( class_exists( 'Invite_Anyone_Invitation' ) ) : ?>

			<h3 class="cboxol-gi-section-heading"><?php esc_html_e( 'Site Invitations', 'cboxol-group-invitations' ); ?></h3>

			<p class="invite-copy"><?php esc_html_e( 'Invitations to join the site (and this group) sent to email addresses not yet registered.', 'cboxol-group-invitations' ); ?></p>

			<?php if ( $ia_invitations ) : ?>
				<table class="cboxol-gi-sent-invitations-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Email', 'cboxol-group-invitations' ); ?></th>
							<?php if ( $can_match_by_email ) : ?>
								<th scope="col"><?php esc_html_e( 'Member', 'cboxol-group-invitations' ); ?></th>
							<?php endif; ?>
							<th scope="col"><?php esc_html_e( 'Date Sent', 'cboxol-group-invitations' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Accepted', 'cboxol-group-invitations' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $ia_invitations as $ia_post ) : ?>
							<?php
							if ( ! $ia_post instanceof WP_Post ) {
								continue;
							}

							$email_terms = wp_get_post_terms( $ia_post->ID, $ia_invitee_tax );
							if ( empty( $email_terms ) || is_wp_error( $email_terms ) ) {
								continue;
							}

							// Invite Anyone stores '+' characters in email addresses as '.PLUSSIGN.'.
							$email = str_replace( '.PLUSSIGN.', '+', $email_terms[0]->name );

							$date_sent   = date_i18n( get_option( 'date_format' ), strtotime( $ia_post->post_date ) );
							$accepted_on = get_post_meta( $ia_post->ID, 'bp_ia_accepted', true );
							$status_text = $accepted_on
								? date_i18n( get_option( 'date_format' ), strtotime( $accepted_on ) )
								: __( 'Pending', 'cboxol-group-invitations' );

							$matched_user = $can_match_by_email ? get_user_by( 'email', $email ) : null;
							?>
							<tr>
								<td><?php echo esc_html( $email ); ?></td>
								<?php if ( $can_match_by_email ) : ?>
									<td>
										<?php if ( $matched_user instanceof WP_User ) : ?>
											<?php
											$matched_display = bp_core_get_user_displayname( $matched_user->ID );
											if ( ! is_string( $matched_display ) || '' === $matched_display ) {
												$matched_display = $matched_user->display_name;
											}
											?>
											<a href="<?php echo esc_url( bp_core_get_user_domain( $matched_user->ID ) ); ?>"><?php echo esc_html( $matched_display ); ?></a>
											<span class="cboxol-gi-username">(<?php echo esc_html( bp_core_get_username( $matched_user->ID ) ); ?>)</span>
										<?php else : ?>
											&mdash;
										<?php endif; ?>
									</td>
								<?php endif; ?>
								<td><?php echo esc_html( $date_sent ); ?></td>
								<td><?php echo esc_html( $status_text ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="invite-copy"><?php esc_html_e( 'You have no site invitations for this group.', 'cboxol-group-invitations' ); ?></p>
			<?php endif; ?>

		<?php endif; ?>

	</div>
</div>

</div>
