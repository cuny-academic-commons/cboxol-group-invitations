<?php
/**
 * Template for the Invite New Members group tab.
 *
 * @package CBOX\OL\GroupInvitations
 */

$group_type = cboxol_get_group_group_type( bp_get_current_group_id() );

// @todo Probably change this to my own form handler.
$form_action = bp_get_group_url(
	groups_get_current_group(),
	bp_groups_get_path_chunks( [ 'invite-anyone', 'send' ] )
);

?>

<form method="post" id="import-members-form" class="form-panel" action="<?php echo esc_url( $form_action ); ?>">

<div id="topgroupinvite" class="panel panel-default">
	<div class="panel-heading semibold"><?php esc_html_e( 'Invite New Members', 'cboxol-group-invitations' ); ?></div>
	<div class="panel-body">

		<?php do_action( 'template_notices' ); ?>

		<?php $show_submit_border = false; ?>

		<?php if ( $import_results ) : ?>
			<?php if ( ! empty( $import_results['success'] ) ) : ?>
				<?php
				$user_links = [];
				foreach ( $import_results['success'] as $success_email ) {
					$success_user = get_user_by( 'email', $success_email );
					if ( ! $success_user ) {
						continue;
					}

					$user_links[] = sprintf(
						'<li><a href="%s">%s</a> (%s)</li>',
						esc_attr( bp_core_get_user_domain( $success_user->ID ) ),
						esc_html( bp_core_get_user_displayname( $success_user->ID ) ),
						esc_html( $success_email )
					);
				}
				?>

				<?php if ( $user_links ) : ?>
					<div class="import-results-section import-results-section-success">
						<p class="invite-copy">
							<?php esc_html_e( 'The following OpenLab members were successfully added.', 'commons-in-a-box' ); ?>
							<?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>
							<ul><?php echo implode( '', $user_links ); ?></ul>
						</p>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( ! empty( $import_results['illegal_address'] ) ) : ?>
				<?php $show_submit_border = true; ?>
				<div class="import-results-section import-results-section-illegal">
					<p class="invite-copy"><?php esc_html_e( 'The following email addresses are not valid for this community.', 'commons-in-a-box' ); ?></p>

					<label for="illegal-addresses" class="sr-only"><?php esc_html_e( 'Illegal addresses', 'commons-in-a-box' ); ?></label>
					<textarea name="illegal-addresses" class="form-control" id="illegal-addresses"><?php echo esc_textarea( implode( ', ', $import_results['illegal_address'] ) ); ?></textarea>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $import_results['invalid_address'] ) ) : ?>
				<?php
				$invalid = [];
				foreach ( $import_results['invalid_address'] as $invalid_address ) {
					$invalid[] = sprintf(
						'<strong>%s</strong>',
						esc_html( $invalid_address )
					);
				}
				?>

				<?php if ( $invalid ) : ?>
					<?php $show_submit_border = true; ?>
					<div class="import-results-section import-results-section-invalid">
						<p class="invite-copy"><?php esc_html_e( 'The following don\'t appear to be valid email addresses. Please verify and resubmit.', 'commons-in-a-box' ); ?></p>
						<?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>
						<p class="invite-copy"><?php echo implode( ', ', $invalid ); ?></p>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( ! empty( $import_results['not_found'] ) ) : ?>
				<?php $show_submit_border = true; ?>
				<div class="import-results-section import-results-section-not-found">
					<p class="invite-copy"><?php esc_html_e( 'The following email addresses are valid, but no corresponding community members were found. The link below wil take you to My Invitations > Invite New Members, where you may invite the following to join the community and this group.', 'commons-in-a-box' ); ?></p>

					<?php
					$invite_link = bp_members_get_user_url(
						bp_loggedin_user_id(),
						bp_members_get_path_chunks( [ BP_INVITE_ANYONE_SLUG ] )
					);
					$invite_link = add_query_arg(
						[
							'emails'   => $import_results['not_found'],
							'group_id' => bp_get_current_group_id(),
						],
						$invite_link
					);
					?>

					<p class="invite-new-members-link"><span class="fa fa-chevron-circle-right" aria-hidden="true"></span> <a href="<?php echo esc_attr( $invite_link ); ?>"><?php esc_html_e( 'Invite the following to join the community', 'commons-in-a-box' ); ?></a></p>

					<label for="not-found-addresses" class="sr-only"><?php esc_html_e( 'Addresses not found in the system', 'commons-in-a-box' ); ?></label>
					<textarea name="not-found-addresses" class="form-control" id="not-found-addresses"><?php echo esc_textarea( implode( ', ', $import_results['not_found'] ) ); ?></textarea>
				</div>
			<?php endif; ?>

			<?php
			$submit_border_class    = $show_submit_border ? ' import-results-section-submit-show-border' : '';
			$group_invite_permalink = bp_get_group_url(
				groups_get_current_group(),
				bp_groups_get_path_chunks( [ BP_INVITE_ANYONE_SLUG ] )
			);
			?>

			<div class="import-results-section import-results-section-submit <?php echo esc_attr( $submit_border_class ); ?>">
				<p><a class="btn btn-primary no-deco" href="<?php echo esc_attr( $group_invite_permalink ); ?>"><?php esc_html_e( 'Perform a new import', 'commons-in-a-box' ); ?></a></p>
			</div>

		<?php else : ?>

			<p class="invite-copy"><?php esc_html_e( 'Add community members to this group in bulk by entering a list of email addresses below. Existing community members corresponding to this list will be added automatically to the group and will receive notification via email.', 'commons-in-a-box' ); ?></p>

			<p class="invite-copy import-acknowledge"><label><input type="checkbox" name="import-acknowledge-checkbox" id="import-acknowledge-checkbox" value="1" /> <?php esc_html_e( 'I acknowledge that the following individuals are officially associated with this group or have approved this action.', 'commons-in-a-box' ); ?></label></p>

			<label class="sr-only" for="email-tag-input"><?php esc_html_e( 'Enter email addresses', 'cboxol-group-invitations' ); ?></label>
			<div class="cboxol-gi-field-wrapper">
				<input
					type="text"
					id="email-tag-input"
					placeholder="<?php esc_attr_e( 'Type a name or email address, or paste a comma-separated list…', 'cboxol-group-invitations' ); ?>"
				/>
			</div>

			<?php // Hidden inputs carry resolved user IDs and unresolved emails on form submit. ?>
			<input type="hidden" name="invite-user-ids" id="invite-user-ids-data" />
			<input type="hidden" name="invite-emails"   id="invite-emails-data" />

			<p><input type="submit" class="btn btn-primary no-deco" value="<?php esc_attr_e( 'Import', 'commons-in-a-box' ); ?>" /></p>
		<?php endif; ?>

		<?php wp_nonce_field( 'group_import_members', 'group-import-members-nonce' ); ?>

	</div>
</div>

<!-- Don't leave out this sweet field -->
<?php if ( ! bp_get_new_group_id() ) : ?>
	<input type="hidden" name="group_id" id="group_id" value="<?php bp_group_id(); ?>" />
<?php else : ?>
	<input type="hidden" name="group_id" id="group_id" value="<?php bp_new_group_id(); ?>" />
<?php endif; ?>
