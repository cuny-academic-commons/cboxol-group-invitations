/**
 * EmailTagInput — wraps Tagify to provide a bubble-style email entry field.
 *
 * Features
 * --------
 * - Paste parsing: comma- or newline-separated addresses become individual bubbles.
 * - Autosuggest: typing queries a WP REST endpoint and populates a dropdown.
 * - Pluggable async validation: pass `validators` (array of async fns) in settings.
 *   Each validator has the signature:
 *     (tagData: { value: string, displayName?: string }) => Promise<true | string>
 *   A string return is treated as the error message; `true` means valid.
 *   Validators run in sequence and short-circuit on the first failure.
 *
 * Adding a validator later (e.g. domain whitelist):
 *   emailTagInput.addValidator( async ( tag ) => {
 *     const allowed = [ 'cuny.edu', 'citytech.cuny.edu' ];
 *     const domain  = tag.value.split( '@' )[ 1 ];
 *     return allowed.includes( domain ) || `${ domain } is not an allowed domain.`;
 *   } );
 */

import Tagify from '@yaireo/tagify';

/** Basic RFC-5321-ish email regex used for synchronous format checks. */
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

export default class EmailTagInput {
	/**
	 * @param {HTMLInputElement} el          The input element to enhance.
	 * @param {Object}           settings
	 * @param {string}           settings.endpoint   WP REST endpoint URL for member suggestions.
	 * @param {string}           settings.nonce      WP REST nonce (`X-WP-Nonce` header).
	 * @param {Function[]}       settings.validators Array of async validator functions.
	 */
	constructor( el, settings = {} ) {
		this.el = el;

		this.settings = {
			endpoint: '',
			nonce: '',
			validators: [],
			...settings,
		};

		/** @type {AbortController|null} In-flight fetch; aborted when query changes. */
		this.fetchController = null;

		this._init();
	}

	// -------------------------------------------------------------------------
	// Initialisation
	// -------------------------------------------------------------------------

	_init() {
		this.tagify = new Tagify( this.el, {
			// Accept comma, newline, or carriage-return+newline as delimiters so
			// that a pasted block of addresses is split into individual tags.
			delimiters: ',|\n|\r\n',
			trim: true,
			editTags: false,

			// Synchronous format validation.  Async validators (e.g. server-side
			// checks, domain whitelists) are wired up via _onAdd() below.
			validate: ( tagData ) => this._validateFormat( tagData ),

			dropdown: {
				enabled: 2,           // Show suggestions after 2 characters.
				maxItems: 8,
				classname: 'cboxol-gi-suggestions',
				searchKeys: [ 'value', 'displayName' ],
				highlightFirst: true,
				closeOnSelect: true,
			},

			// Custom dropdown item template — shows display name + email address.
			// Note: Tagify calls template functions with its own instance as `this`.
			templates: {
				dropdownItem: ( item ) => this._dropdownItemTemplate.call( this.tagify, item ),
			},
		} );

		this.tagify
			.on( 'input',  ( e ) => this._onInput( e ) )
			.on( 'add',    ( e ) => this._onAdd( e ) )
			.on( 'change', ()  => this._syncHiddenInput() );
	}

	// -------------------------------------------------------------------------
	// Synchronous validation
	// -------------------------------------------------------------------------

	_validateFormat( tagData ) {
		if ( ! EMAIL_RE.test( tagData.value.trim() ) ) {
			return 'Not a valid email address';
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Async validation (runs after a tag is successfully added)
	// -------------------------------------------------------------------------

	async _onAdd( e ) {
		if ( ! this.settings.validators.length ) {
			return;
		}

		const tagData = e.detail.data;
		let errorMsg  = null;

		for ( const validator of this.settings.validators ) {
			const result = await validator( tagData );

			if ( result !== true ) {
				errorMsg = typeof result === 'string' ? result : 'Invalid';
				break;
			}
		}

		if ( errorMsg ) {
			// Mark the tag as invalid without removing it so the user can see
			// what went wrong.  They can dismiss it manually or correct it.
			this.tagify.replaceTag( e.detail.tag, {
				...tagData,
				__isValid: errorMsg,
			} );
		}
	}

	// -------------------------------------------------------------------------
	// Autosuggest
	// -------------------------------------------------------------------------

	async _onInput( e ) {
		const query = e.detail.value;

		// Cancel any in-flight request.
		if ( this.fetchController ) {
			this.fetchController.abort();
		}

		if ( query.length < 2 || ! this.settings.endpoint ) {
			this.tagify.whitelist = [];
			return;
		}

		this.fetchController = new AbortController();
		this.tagify.loading( true );

		try {
			const url = new URL( this.settings.endpoint );
			url.searchParams.set( 'query', query );

			const res = await fetch( url.toString(), {
				signal: this.fetchController.signal,
				headers: { 'X-WP-Nonce': this.settings.nonce },
			} );

			if ( ! res.ok ) {
				throw new Error( `HTTP ${ res.status }` );
			}

			const suggestions = await res.json();
			this.tagify.whitelist = suggestions;
			this.tagify.loading( false ).dropdown.show( query );
		} catch ( err ) {
			if ( err.name !== 'AbortError' ) {
				// eslint-disable-next-line no-console
				console.error( '[EmailTagInput] Suggestions fetch failed:', err );
			}
			this.tagify.loading( false );
		}
	}

	// -------------------------------------------------------------------------
	// Dropdown template
	// -------------------------------------------------------------------------

	/**
	 * Custom dropdown item showing the member's display name above their address.
	 *
	 * Called by Tagify with its own instance as `this`, which gives access to
	 * `this.classNames` and `this.getAttributes()`.
	 *
	 * @param {{ value: string, displayName?: string }} item
	 * @return {string} HTML string for the dropdown item.
	 */
	_dropdownItemTemplate( item ) {
		const nameHtml = item.displayName
			? `<span class="cboxol-gi-suggestion__name">${ item.displayName }</span>`
			: '';

		return `
			<div
				class="${ this.classNames.dropdownItem } cboxol-gi-suggestion"
				${ this.getAttributes( item ) }
				tabindex="0"
				role="option"
			>
				${ nameHtml }
				<span class="cboxol-gi-suggestion__email">${ item.value }</span>
			</div>
		`;
	}

	// -------------------------------------------------------------------------
	// Hidden form input sync
	// -------------------------------------------------------------------------

	_syncHiddenInput() {
		const hidden = document.getElementById( 'email-addresses-to-import-data' );
		if ( hidden ) {
			hidden.value = this.getEmails().join( ', ' );
		}
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Returns the current confirmed email addresses.
	 *
	 * @return {string[]}
	 */
	getEmails() {
		return this.tagify.value.map( ( tag ) => tag.value );
	}

	/**
	 * Appends an async validator to the chain.
	 *
	 * @param {Function} fn  (tagData) => Promise<true | string>
	 * @return {this}
	 */
	addValidator( fn ) {
		this.settings.validators.push( fn );
		return this;
	}
}
