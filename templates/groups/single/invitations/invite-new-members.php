<?php
/**
 * Template for the Invite New Members sub-panel.
 *
 * @package CBOX\OL\GroupInvitations
 */

$group_id = bp_get_current_group_id();

$import_results = \CBOX\OL\GroupInvitations\App::get_current_import_results();
$can_direct_add = \CBOX\OL\GroupInvitations\App::current_user_can_direct_add_members( $group_id, bp_loggedin_user_id() );

$build_user_result_items = static function ( array $emails ): array {
	$user_links = [];

	foreach ( $emails as $email ) {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			continue;
		}

		$display_name = bp_core_get_user_displayname( $user->ID );
		if ( ! is_string( $display_name ) || '' === $display_name ) {
			$display_name = $email;
		}

		$user_links[] = sprintf(
			'<li><a href="%s">%s</a> (%s)</li>',
			esc_attr( bp_core_get_user_domain( $user->ID ) ),
			esc_html( $display_name ),
			esc_html( $email )
		);
	}

	return $user_links;
};

$build_text_result_items = static function ( array $items ): array {
	return array_map(
		static function ( string $item ): string {
			return sprintf( '<li>%s</li>', esc_html( $item ) );
		},
		$items
	);
};

$form_action = bp_get_group_url(
	groups_get_current_group(),
	bp_groups_get_path_chunks( [ 'invitations', 'send' ] )
);

?>

<form method="post" id="import-members-form" class="form-panel" action="<?php echo esc_url( $form_action ); ?>">

<div id="topgroupinvite" class="panel panel-default">
	<div class="panel-heading semibold"><?php esc_html_e( 'Invite New Members', 'commons-in-a-box' ); ?></div>
	<div class="panel-body">

		<?php do_action( 'template_notices' ); ?>

		<?php if ( ! empty( $import_results ) ) : ?>
			<?php
			$submitted_values       = $import_results['submitted'] ?? [];
			$success_count          = count( $import_results['added'] ?? [] ) + count( $import_results['invited'] ?? [] ) + count( $import_results['emailed'] ?? [] );
			$group_invite_permalink = bp_get_group_url(
				groups_get_current_group(),
				bp_groups_get_path_chunks( [ 'invitations', 'invite-new-members' ] )
			);
			?>

			<div class="bp-template-notice updated cboxol-gi-results-notice">
				<p>
					<?php esc_html_e( 'Your submission was processed. Detailed results are below.', 'commons-in-a-box' ); ?>
				</p>

				<?php if ( $submitted_values ) : ?>
					<h3 class="cboxol-gi-results__heading cboxol-gi-results__heading--notice"><?php esc_html_e( 'Submitted values', 'commons-in-a-box' ); ?></h3>
					<div class="cboxol-gi-scrollbox">
						<ul class="cboxol-gi-result-values">
							<?php foreach ( $submitted_values as $submitted_value ) : ?>
								<li><?php echo esc_html( $submitted_value ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			</div>

			<div class="cboxol-gi-results">
				<h3 class="cboxol-gi-results__heading"><?php esc_html_e( 'Results', 'commons-in-a-box' ); ?></h3>

				<ol class="cboxol-gi-results__list">
					<?php if ( ! empty( $import_results['added'] ) ) : ?>
						<?php $added_items = $build_user_result_items( $import_results['added'] ); ?>
						<?php if ( $added_items ) : ?>
							<li class="cboxol-gi-results__item">
								<h4 class="cboxol-gi-results__item-title"><?php esc_html_e( 'Added directly', 'commons-in-a-box' ); ?></h4>
								<p class="invite-copy"><?php esc_html_e( 'The following OpenLab members were added directly to the group.', 'commons-in-a-box' ); ?></p>
								<div class="cboxol-gi-scrollbox">
									<ul class="cboxol-gi-result-values">
										<?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>
										<?php echo implode( '', $added_items ); ?>
									</ul>
								</div>
							</li>
						<?php endif; ?>
					<?php endif; ?>

					<?php if ( ! empty( $import_results['invited'] ) ) : ?>
						<?php $invited_items = $build_user_result_items( $import_results['invited'] ); ?>
						<?php if ( $invited_items ) : ?>
							<li class="cboxol-gi-results__item">
								<h4 class="cboxol-gi-results__item-title"><?php esc_html_e( 'Existing users', 'commons-in-a-box' ); ?></h4>
								<p class="invite-copy"><?php esc_html_e( 'The following email addresses matched users who are already members of the community. Group invitations have been sent to these members.', 'commons-in-a-box' ); ?></p>
								<div class="cboxol-gi-scrollbox">
									<ul class="cboxol-gi-result-values">
										<?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>
										<?php echo implode( '', $invited_items ); ?>
									</ul>
								</div>
							</li>
						<?php endif; ?>
					<?php endif; ?>

					<?php if ( ! empty( $import_results['emailed'] ) ) : ?>
						<li class="cboxol-gi-results__item">
							<h4 class="cboxol-gi-results__item-title"><?php esc_html_e( 'New user Invitations', 'commons-in-a-box' ); ?></h4>
							<p class="invite-copy"><?php esc_html_e( 'The following email addresses were not found in the system. Invitations to join the site have been sent to these addresses. After accepting the invitation, users will be added to the group.', 'commons-in-a-box' ); ?></p>
							<div class="cboxol-gi-scrollbox">
								<ul class="cboxol-gi-result-values">
									<?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>
									<?php echo implode( '', $build_text_result_items( $import_results['emailed'] ) ); ?>
								</ul>
							</div>
						</li>
					<?php endif; ?>

					<?php if ( ! empty( $import_results['already_member'] ) ) : ?>
						<li class="cboxol-gi-results__item">
							<h4 class="cboxol-gi-results__item-title"><?php esc_html_e( 'Already in the group', 'commons-in-a-box' ); ?></h4>
							<p class="invite-copy"><?php esc_html_e( 'The following invited users are already members of the group.', 'commons-in-a-box' ); ?></p>
							<div class="cboxol-gi-scrollbox">
								<ul class="cboxol-gi-result-values">
									<?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>
									<?php echo implode( '', $build_text_result_items( $import_results['already_member'] ) ); ?>
								</ul>
							</div>
						</li>
					<?php endif; ?>

					<?php if ( ! empty( $import_results['already_invited'] ) ) : ?>
						<li class="cboxol-gi-results__item">
							<h4 class="cboxol-gi-results__item-title"><?php esc_html_e( 'Already invited', 'commons-in-a-box' ); ?></h4>
							<p class="invite-copy"><?php esc_html_e( 'The following users have already received invitations to join the group.', 'commons-in-a-box' ); ?></p>
							<div class="cboxol-gi-scrollbox">
								<ul class="cboxol-gi-result-values">
									<?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>
									<?php echo implode( '', $build_text_result_items( $import_results['already_invited'] ) ); ?>
								</ul>
							</div>
						</li>
					<?php endif; ?>

					<?php if ( ! empty( $import_results['illegal_address'] ) ) : ?>
						<li class="cboxol-gi-results__item">
							<h4 class="cboxol-gi-results__item-title"><?php esc_html_e( 'Not permitted for this community', 'commons-in-a-box' ); ?></h4>
							<p class="invite-copy"><?php esc_html_e( 'The following email addresses are not valid for this community.', 'commons-in-a-box' ); ?></p>
							<div class="cboxol-gi-scrollbox">
								<ul class="cboxol-gi-result-values">
									<?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>
									<?php echo implode( '', $build_text_result_items( $import_results['illegal_address'] ) ); ?>
								</ul>
							</div>
						</li>
					<?php endif; ?>

					<?php if ( ! empty( $import_results['invalid_address'] ) ) : ?>
						<li class="cboxol-gi-results__item">
							<h4 class="cboxol-gi-results__item-title"><?php esc_html_e( 'Invalid email addresses', 'commons-in-a-box' ); ?></h4>
							<p class="invite-copy"><?php esc_html_e( 'The following don\'t appear to be valid email addresses. Please verify and resubmit.', 'commons-in-a-box' ); ?></p>
							<div class="cboxol-gi-scrollbox">
								<ul class="cboxol-gi-result-values">
									<?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>
									<?php echo implode( '', $build_text_result_items( $import_results['invalid_address'] ) ); ?>
								</ul>
							</div>
						</li>
					<?php endif; ?>

					<?php if ( ! empty( $import_results['inaccessible_user'] ) ) : ?>
						<li class="cboxol-gi-results__item">
							<h4 class="cboxol-gi-results__item-title"><?php esc_html_e( 'Not available with your permissions', 'commons-in-a-box' ); ?></h4>
							<p class="invite-copy"><?php esc_html_e( 'The following addresses could not be processed with your current invitation permissions.', 'commons-in-a-box' ); ?></p>
							<div class="cboxol-gi-scrollbox">
								<ul class="cboxol-gi-result-values">
									<?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>
									<?php echo implode( '', $build_text_result_items( $import_results['inaccessible_user'] ) ); ?>
								</ul>
							</div>
						</li>
					<?php endif; ?>

					<?php if ( ! empty( $import_results['failed'] ) ) : ?>
						<li class="cboxol-gi-results__item">
							<h4 class="cboxol-gi-results__item-title"><?php esc_html_e( 'Processing failures', 'commons-in-a-box' ); ?></h4>
							<p class="invite-copy"><?php esc_html_e( 'The following addresses could not be processed because the invitation step failed.', 'commons-in-a-box' ); ?></p>
							<div class="cboxol-gi-scrollbox">
								<ul class="cboxol-gi-result-values">
									<?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>
									<?php echo implode( '', $build_text_result_items( $import_results['failed'] ) ); ?>
								</ul>
							</div>
						</li>
					<?php endif; ?>
				</ol>
			</div>

			<div class="import-results-section import-results-section-submit import-results-section-submit-show-border">
				<p><a class="btn btn-primary no-deco" href="<?php echo esc_attr( $group_invite_permalink ); ?>"><?php esc_html_e( 'Perform a new import', 'commons-in-a-box' ); ?></a></p>
			</div>

		<?php else : ?>
			<p class="invite-copy"><?php esc_html_e( 'Add community members to this group in bulk by entering a list of email addresses below', 'commons-in-a-box' ); ?></p>

			<ul>
				<?php if ( $can_direct_add ) : ?>
					<li><?php esc_html_e( 'If an email address matches an existing member, the member will be added directly to this group.', 'commons-in-a-box' ); ?></li>
					<li><?php esc_html_e( 'If no account exists for an email address, an invitation will be sent to that address with instructions to join the site and the group.', 'commons-in-a-box' ); ?></li>
				<?php else : ?>
					<li><?php esc_html_e( 'If an email address matches an existing member, that member will receive an invitation to join this group.', 'commons-in-a-box' ); ?></li>
					<li><?php esc_html_e( 'If no account exists for an email address, an invitation will be sent to that address with instructions to join the site and the group.', 'commons-in-a-box' ); ?></li>
				<?php endif; ?>
			</ul>

			<p class="invite-copy import-acknowledge"><label><input type="checkbox" name="import-acknowledge-checkbox" id="import-acknowledge-checkbox" value="1" /> <?php esc_html_e( 'I acknowledge that the following individuals are officially associated with this group or have approved this action.', 'commons-in-a-box' ); ?></label></p>

			<label class="sr-only" for="email-tag-input"><?php esc_html_e( 'Enter email addresses', 'commons-in-a-box' ); ?></label>
			<div class="cboxol-gi-field-wrapper">
				<input
					type="text"
					id="email-tag-input"
					placeholder="<?php esc_attr_e( 'Type a name or email address, or paste a comma-separated list…', 'commons-in-a-box' ); ?>"
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

<?php if ( ! bp_get_new_group_id() ) : ?>
	<input type="hidden" name="group_id" id="group_id" value="<?php bp_group_id(); ?>" />
<?php else : ?>
	<input type="hidden" name="group_id" id="group_id" value="<?php bp_new_group_id(); ?>" />
<?php endif; ?>

</form>
