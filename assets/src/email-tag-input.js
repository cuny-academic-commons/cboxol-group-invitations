/**
 * EmailTagInput — wraps Tagify to provide a bubble-style email entry field.
 *
 * Two user modes, controlled by the `matchByEmail` setting (mirrors the
 * server-side `cboxol_match_users_by_email_address` capability):
 *
 * Privileged  (matchByEmail: true)
 *   - Autosuggest matches display name, user_nicename, AND user_email.
 *   - After a raw email is entered, async validation looks up the WP account
 *     and updates the bubble to "{display name} ({nicename}) <email>".
 *   - Emails that don't resolve to any user are kept as-is (external invites).
 *
 * Unprivileged (matchByEmail: false)
 *   - Autosuggest matches display name and user_nicename only.
 *   - Only BP friends of the current user are returned in suggestions.
 *   - After a raw email is entered, async validation only succeeds if the email
 *     belongs to a BP friend; bubble shows "{display name} ({nicename})".
 *   - Emails that don't validate are marked invalid immediately.
 *
 * Pluggable validators
 * --------------------
 * Pass `validators` (array of async fns) in settings, or call addValidator().
 * Each validator: (tagData) => Promise<true | string>
 * A string return is treated as the error message; `true` means valid.
 * Validators run only on raw email input (tags with a userId are already
 * resolved and skipped).
 *
 * Form submission
 * ---------------
 * Two hidden inputs are kept in sync:
 *   #invite-user-ids-data  — comma-separated WP user IDs (all resolved users)
 *   #invite-emails-data    — comma-separated raw email addresses (privileged
 *                            users only; emails that didn't resolve to a user)
 */

import Tagify from '@yaireo/tagify';

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

/** Escape HTML entities for safe template interpolation. */
function esc( str ) {
return String( str )
.replace( /&/g, '&amp;' )
.replace( /</g, '&lt;' )
.replace( />/g, '&gt;' )
.replace( /"/g, '&quot;' );
}

/**
 * Build the display label shown inside a tag bubble.
 *
 * - Resolved, privileged:   "{displayName} ({userNicename}) <email>"
 * - Resolved, unprivileged: "{displayName} ({userNicename})"
 * - Unresolved / raw email: raw value
 */
function formatTagLabel( tagData ) {
const { displayName, userNicename, email } = tagData;

if ( displayName && userNicename && email ) {
return `${ esc( displayName ) } (${ esc( userNicename ) }) &lt;${ esc( email ) }&gt;`;
}
if ( displayName && userNicename ) {
return `${ esc( displayName ) } (${ esc( userNicename ) })`;
}
return esc( tagData.value );
}

export default class EmailTagInput {
/**
 * @param {HTMLInputElement} el
 * @param {Object}           settings
 * @param {string}           settings.endpoint          Autosuggest REST URL.
 * @param {string}           settings.validateEndpoint  Address-validation REST URL.
 * @param {string}           settings.nonce             WP REST nonce.
 * @param {boolean}          settings.matchByEmail      true = current user has
 *                                                       cboxol_match_users_by_email_address.
 * @param {Function[]}       settings.validators        Async pre-validators run on
 *                                                       raw email input before the
 *                                                       server call.
 */
constructor( el, settings = {} ) {
this.el = el;

this.settings = {
endpoint:         '',
validateEndpoint: '',
nonce:            '',
matchByEmail:     false,
validators:       [],
...settings,
};

/** @type {AbortController|null} */
this.fetchController = null;

this._init();
}

// -------------------------------------------------------------------------
// Initialisation
// -------------------------------------------------------------------------

_init() {
this.tagify = new Tagify( this.el, {
delimiters:      ',|\n|\r\n',
trim:            true,
editTags:        false,
keepInvalidTags: true,   // Keep invalid bubbles visible so users can see why they failed.
validate:        ( tagData ) => this._validateFormat( tagData ),

dropdown: {
enabled:       2,
maxItems:      8,
classname:     'cboxol-gi-suggestions',
searchKeys:    [ 'value', 'displayName', 'userNicename' ],
highlightFirst: true,
closeOnSelect:  true,
},

// Both templates are called by Tagify with its own instance as `this`.
templates: {
tag:          ( tagData ) => this._tagTemplate.call( this.tagify, tagData ),
dropdownItem: ( item )    => this._dropdownItemTemplate.call( this.tagify, item ),
},
} );

this.tagify
.on( 'input',  ( e ) => this._onInput( e ) )
.on( 'add',    ( e ) => this._onAdd( e ) )
.on( 'remove', ()    => this._syncHiddenInputs() );
}

// -------------------------------------------------------------------------
// Format validation (sync, called by Tagify before a tag is created)
// -------------------------------------------------------------------------

_validateFormat( tagData ) {
// Tags from the autosuggest dropdown carry a userId — they are pre-validated
// server-side and don't need a format check.
if ( tagData.userId ) {
return true;
}
if ( ! EMAIL_RE.test( tagData.value.trim() ) ) {
return 'Not a valid email address';
}
return true;
}

// -------------------------------------------------------------------------
// Post-add orchestration (async)
// -------------------------------------------------------------------------

async _onAdd( e ) {
const tagData = e.detail.data;
const tagElm  = e.detail.tag;

// Dropdown selections already have a resolved userId — nothing to do.
if ( tagData.userId ) {
this._syncHiddenInputs();
return;
}

// 1. Run local pre-validators (e.g. domain whitelist) first.
//    These are fast/synchronous-ish checks that don't need a round-trip.
for ( const validator of this.settings.validators ) {
const result = await validator( tagData );
if ( result !== true ) {
const msg = typeof result === 'string' ? result : 'Invalid';
this._markInvalid( tagElm, tagData, msg );
return;
}
}

// 2. No server endpoint configured — stop here (domain check passed).
if ( ! this.settings.validateEndpoint ) {
this._syncHiddenInputs();
return;
}

// 3. Show per-tag loading state.
//    tagify__tag--loading is already styled by Tagify: spinner appears,
//    remove button is hidden so the user can't dismiss mid-flight.
tagElm.classList.add( 'tagify__tag--loading' );

try {
const url = new URL( this.settings.validateEndpoint );
url.searchParams.set( 'email', tagData.value.trim() );

const res = await fetch( url.toString(), {
headers: { 'X-WP-Nonce': this.settings.nonce },
} );

if ( ! res.ok ) {
throw new Error( `HTTP ${ res.status }` );
}

const data = await res.json();

// Guard: user may have removed the tag while the request was in flight.
if ( ! document.body.contains( tagElm ) ) {
return;
}

if ( data.found ) {
// Replace with fully-resolved tag data.
// value = email (privileged) or userNicename (unprivileged) for
// deduplication; the custom tag template renders the rich label.
this.tagify.replaceTag( tagElm, {
value:        data.email ?? data.userNicename,
userId:       data.userId,
displayName:  data.displayName,
userNicename: data.userNicename,
email:        data.email ?? undefined,
} );
} else if ( ! this.settings.matchByEmail ) {
// Unprivileged — unresolved email is invalid.
this._markInvalid( tagElm, tagData, 'No matching community member found.' );
return;
} else {
// Privileged — raw email is fine (external invite); just clear loading.
tagElm.classList.remove( 'tagify__tag--loading' );
}
} catch ( err ) {
if ( document.body.contains( tagElm ) ) {
tagElm.classList.remove( 'tagify__tag--loading' );
}
// eslint-disable-next-line no-console
console.error( '[EmailTagInput] Address validation failed:', err );
}

this._syncHiddenInputs();
}

/**
 * Marks a tag invalid using Tagify's replaceTag mechanism.
 * All validation failures — format, domain, friendship, future checks — flow
 * through here so the visual state is always consistent.
 *
 * @param {HTMLElement} tagElm
 * @param {Object}      tagData
 * @param {string}      message
 */
_markInvalid( tagElm, tagData, message ) {
this.tagify.replaceTag( tagElm, { ...tagData, __isValid: message } );
this._syncHiddenInputs();
}

// -------------------------------------------------------------------------
// Autosuggest
// -------------------------------------------------------------------------

async _onInput( e ) {
const query = e.detail.value;

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
signal:  this.fetchController.signal,
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
// Tag template
// -------------------------------------------------------------------------

/**
 * Custom tag bubble template.
 * Called by Tagify with its own instance as `this`.
 *
 * @param {Object} tagData
 * @return {string}
 */
_tagTemplate( tagData ) {
const label = formatTagLabel( tagData );

return `
<tag
title="${ esc( tagData.value ) }"
contenteditable="false"
spellcheck="false"
tabIndex="${ this.settings.a11y.focusableTags ? 0 : -1 }"
class="${ this.settings.classNames.tag } ${ tagData.class || '' }"
${ this.getAttributes( tagData ) }
>
<x title="" class="${ this.settings.classNames.tagX }" role="button" aria-label="remove tag"></x>
<div>
<span class="${ this.settings.classNames.tagText }">${ label }</span>
</div>
</tag>
`;
}

// -------------------------------------------------------------------------
// Dropdown item template
// -------------------------------------------------------------------------

/**
 * Custom dropdown suggestion template.
 * Called by Tagify with its own instance as `this`.
 *
 * Privileged:   shows display name + email address.
 * Unprivileged: shows display name + @nicename (no email).
 *
 * @param {Object} item
 * @return {string}
 */
_dropdownItemTemplate( item ) {
const nameHtml = item.displayName
? `<span class="cboxol-gi-suggestion__name">${ esc( item.displayName ) }</span>`
: '';

let secondaryHtml;
if ( item.email ) {
secondaryHtml = `<span class="cboxol-gi-suggestion__email">${ esc( item.email ) }</span>`;
} else if ( item.userNicename ) {
secondaryHtml = `<span class="cboxol-gi-suggestion__nicename">@${ esc( item.userNicename ) }</span>`;
} else {
secondaryHtml = `<span class="cboxol-gi-suggestion__email">${ esc( item.value ) }</span>`;
}

return `
<div
class="${ this.classNames.dropdownItem } cboxol-gi-suggestion"
${ this.getAttributes( item ) }
tabindex="0"
role="option"
>
${ nameHtml }
${ secondaryHtml }
</div>
`;
}

// -------------------------------------------------------------------------
// Hidden form input sync
// -------------------------------------------------------------------------

/**
 * Keeps the two hidden form inputs in sync with the current tag state.
 *
 * Skips tags where __isValid is a string (invalid tags stay visible as
 * feedback but don't contribute to form submission).
 */
_syncHiddenInputs() {
const userIds = [];
const emails  = [];

for ( const tag of this.tagify.value ) {
// Skip invalid tags (format errors, domain failures, friend-check failures, etc.)
if ( typeof tag.__isValid === 'string' ) {
continue;
}

if ( tag.userId ) {
userIds.push( String( tag.userId ) );
} else {
// Raw unresolved email — only reaches here for privileged users
// (unprivileged unresolved emails are marked invalid above).
emails.push( tag.value );
}
}

const userIdsInput = document.getElementById( 'invite-user-ids-data' );
const emailsInput  = document.getElementById( 'invite-emails-data' );

if ( userIdsInput ) userIdsInput.value = userIds.join( ',' );
if ( emailsInput )  emailsInput.value  = emails.join( ',' );
}

// -------------------------------------------------------------------------
// Public API
// -------------------------------------------------------------------------

/**
 * Returns resolved user IDs for all valid tags that matched a WP account.
 *
 * @return {number[]}
 */
getResolvedUserIds() {
return this.tagify.value
.filter( ( tag ) => tag.userId && typeof tag.__isValid !== 'string' )
.map( ( tag ) => Number( tag.userId ) );
}

/**
 * Returns unresolved email addresses for valid tags that didn't match a user.
 * Only populated for privileged users (matchByEmail: true).
 *
 * @return {string[]}
 */
getUnresolvedEmails() {
return this.tagify.value
.filter( ( tag ) => ! tag.userId && typeof tag.__isValid !== 'string' )
.map( ( tag ) => tag.value );
}

/**
 * Appends an async validator to the pre-validation chain.
 * Validators run on raw email input only (autosuggest selections are skipped).
 *
 * @param {Function} fn  (tagData) => Promise<true | string>
 * @return {this}
 */
addValidator( fn ) {
this.settings.validators.push( fn );
return this;
}
}
