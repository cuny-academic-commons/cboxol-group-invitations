<?php
/**
 * Template for the Sent Invitations sub-panel.
 *
 * @package CBOX\OL\GroupInvitations
 */

$group_id           = bp_get_current_group_id();
$user_id            = bp_loggedin_user_id();
$can_match_by_email = current_user_can( 'cboxol_match_users_by_email_address' );
$sent_invitations_url = bp_get_group_url(
	groups_get_current_group(),
	bp_groups_get_path_chunks( [ 'invitations', 'sent-invitations' ] )
);

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$group_sort  = isset( $_GET['cboxol_gi_group_sort'] ) ? sanitize_key( wp_unslash( $_GET['cboxol_gi_group_sort'] ) ) : 'date';
$group_order = isset( $_GET['cboxol_gi_group_order'] ) ? sanitize_key( wp_unslash( $_GET['cboxol_gi_group_order'] ) ) : 'desc';
$site_sort   = isset( $_GET['cboxol_gi_site_sort'] ) ? sanitize_key( wp_unslash( $_GET['cboxol_gi_site_sort'] ) ) : 'accepted';
$site_order  = isset( $_GET['cboxol_gi_site_order'] ) ? sanitize_key( wp_unslash( $_GET['cboxol_gi_site_order'] ) ) : 'desc';
// phpcs:enable WordPress.Security.NonceVerification.Recommended

if ( 'date' !== $group_sort ) {
	$group_sort = 'date';
}
if ( ! in_array( $group_order, [ 'asc', 'desc' ], true ) ) {
	$group_order = 'desc';
}
if ( ! in_array( $site_sort, [ 'date-sent', 'accepted' ], true ) ) {
	$site_sort = 'accepted';
}
if ( ! in_array( $site_order, [ 'asc', 'desc' ], true ) ) {
	$site_order = 'desc';
}

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

usort(
	$bp_invitations,
	static function ( $first, $second ) use ( $group_order ): int {
		$first_date  = isset( $first->date_modified ) ? (int) strtotime( $first->date_modified ) : 0;
		$second_date = isset( $second->date_modified ) ? (int) strtotime( $second->date_modified ) : 0;

		return 'asc' === $group_order ? $first_date <=> $second_date : $second_date <=> $first_date;
	}
);

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

usort(
	$ia_invitations,
	static function ( $first, $second ) use ( $site_sort, $site_order ): int {
		if ( ! $first instanceof WP_Post || ! $second instanceof WP_Post ) {
			return 0;
		}

		if ( 'accepted' === $site_sort ) {
			$first_accepted  = get_post_meta( $first->ID, 'bp_ia_accepted', true );
			$second_accepted = get_post_meta( $second->ID, 'bp_ia_accepted', true );
			$first_pending   = ! $first_accepted;
			$second_pending  = ! $second_accepted;

			if ( $first_pending !== $second_pending ) {
				if ( 'desc' === $site_order ) {
					return $first_pending ? -1 : 1;
				}

				return $first_pending ? 1 : -1;
			}

			$first_date  = $first_pending ? (int) strtotime( $first->post_date ) : (int) strtotime( $first_accepted );
			$second_date = $second_pending ? (int) strtotime( $second->post_date ) : (int) strtotime( $second_accepted );
		} else {
			$first_date  = (int) strtotime( $first->post_date );
			$second_date = (int) strtotime( $second->post_date );
		}

		return 'asc' === $site_order ? $first_date <=> $second_date : $second_date <=> $first_date;
	}
);

$group_date_next_order = 'date' === $group_sort && 'desc' === $group_order ? 'asc' : 'desc';
$site_date_next_order  = 'date-sent' === $site_sort && 'desc' === $site_order ? 'asc' : 'desc';
$site_accepted_next_order = 'accepted' === $site_sort && 'desc' === $site_order ? 'asc' : 'desc';

?>

<div class="form-panel">

<div class="panel panel-default">
	<div class="panel-heading semibold"><?php esc_html_e( 'Sent Invitations', 'cboxol-group-invitations' ); ?></div>
	<div class="panel-body">

		<p class="invite-copy"><?php esc_html_e( 'Below are the invitations to this group that you have sent, and members you have directly added. Standard group invitations are shown as pending until accepted or declined. Members added directly by privileged users are shown as added.', 'cboxol-group-invitations' ); ?></p>

		<h3 class="cboxol-gi-section-heading"><?php esc_html_e( 'Group Invitations', 'cboxol-group-invitations' ); ?></h3>

		<p class="invite-copy"><?php esc_html_e( 'Invitations sent to existing site members to join this group.', 'cboxol-group-invitations' ); ?></p>

		<?php if ( $bp_invitations ) : ?>
			<table
				class="cboxol-gi-sent-invitations-table"
				data-cboxol-gi-sortable
				data-sort-param="cboxol_gi_group_sort"
				data-order-param="cboxol_gi_group_order"
			>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Member', 'cboxol-group-invitations' ); ?></th>
						<?php if ( $can_match_by_email ) : ?>
							<th scope="col"><?php esc_html_e( 'Email', 'cboxol-group-invitations' ); ?></th>
						<?php endif; ?>
						<th scope="col" aria-sort="<?php echo esc_attr( 'asc' === $group_order ? 'ascending' : 'descending' ); ?>">
							<a
								class="cboxol-gi-sort-link is-active"
								href="<?php echo esc_url( add_query_arg( [ 'cboxol_gi_group_sort' => 'date', 'cboxol_gi_group_order' => $group_date_next_order ], $sent_invitations_url ) ); ?>"
								data-sort="date"
								data-order="<?php echo esc_attr( $group_date_next_order ); ?>"
							><?php esc_html_e( 'Date', 'cboxol-group-invitations' ); ?></a>
						</th>
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
						<tr data-sort-date="<?php echo esc_attr( (string) strtotime( $invite->date_modified ) ); ?>">
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
				<table
					class="cboxol-gi-sent-invitations-table"
					data-cboxol-gi-sortable
					data-sort-param="cboxol_gi_site_sort"
					data-order-param="cboxol_gi_site_order"
				>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Email', 'cboxol-group-invitations' ); ?></th>
							<?php if ( $can_match_by_email ) : ?>
								<th scope="col"><?php esc_html_e( 'Member', 'cboxol-group-invitations' ); ?></th>
							<?php endif; ?>
							<th scope="col" aria-sort="<?php echo esc_attr( 'date-sent' === $site_sort ? ( 'asc' === $site_order ? 'ascending' : 'descending' ) : 'none' ); ?>">
								<a
									class="cboxol-gi-sort-link<?php echo 'date-sent' === $site_sort ? ' is-active' : ''; ?>"
									href="<?php echo esc_url( add_query_arg( [ 'cboxol_gi_site_sort' => 'date-sent', 'cboxol_gi_site_order' => $site_date_next_order ], $sent_invitations_url ) ); ?>"
									data-sort="date-sent"
									data-order="<?php echo esc_attr( $site_date_next_order ); ?>"
								><?php esc_html_e( 'Date Sent', 'cboxol-group-invitations' ); ?></a>
							</th>
							<th scope="col" aria-sort="<?php echo esc_attr( 'accepted' === $site_sort ? ( 'asc' === $site_order ? 'ascending' : 'descending' ) : 'none' ); ?>">
								<a
									class="cboxol-gi-sort-link<?php echo 'accepted' === $site_sort ? ' is-active' : ''; ?>"
									href="<?php echo esc_url( add_query_arg( [ 'cboxol_gi_site_sort' => 'accepted', 'cboxol_gi_site_order' => $site_accepted_next_order ], $sent_invitations_url ) ); ?>"
									data-sort="accepted"
									data-order="<?php echo esc_attr( $site_accepted_next_order ); ?>"
								><?php esc_html_e( 'Accepted', 'cboxol-group-invitations' ); ?></a>
							</th>
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
							<tr
								data-sort-date-sent="<?php echo esc_attr( (string) strtotime( $ia_post->post_date ) ); ?>"
								data-sort-accepted="<?php echo esc_attr( $accepted_on ? (string) strtotime( $accepted_on ) : '' ); ?>"
								data-sort-pending="<?php echo esc_attr( $accepted_on ? '0' : '1' ); ?>"
							>
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
