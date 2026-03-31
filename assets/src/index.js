/**
 * Entry point for CBOX OpenLab Group Invitations front-end scripts.
 *
 * Built by @wordpress/scripts (webpack) from assets/src/ into build/.
 * The compiled bundle is enqueued by App::enqueue_assets() in PHP.
 */

// Tagify base styles (extracted to build/index.css by MiniCssExtractPlugin).
import '@yaireo/tagify/dist/tagify.css';

// Plugin-specific style overrides.
import './index.scss';

import EmailTagInput from './email-tag-input';
import { createDomainValidator } from './validators';

document.addEventListener( 'DOMContentLoaded', () => {
	const inputEl = document.getElementById( 'email-tag-input' );

	if ( ! inputEl ) {
		return;
	}

	const {
		restEndpoint   = '',
		nonce          = '',
		allowedDomains = [],
	} = window.cboxolGroupInvitations || {};

	const emailTagInput = new EmailTagInput( inputEl, {
		endpoint: restEndpoint,
		nonce,
	} );

	// Register the domain whitelist validator if restrictions are configured.
	// The factory is a no-op when allowedDomains is empty, but we skip it
	// entirely here to avoid the (negligible) closure overhead on open sites.
	if ( allowedDomains.length ) {
		emailTagInput.addValidator( createDomainValidator( allowedDomains ) );
	}
} );
