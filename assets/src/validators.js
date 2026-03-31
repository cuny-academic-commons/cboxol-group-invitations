/**
 * Validator factory functions for EmailTagInput.
 *
 * Each factory returns an async function with the signature:
 *   (tagData: { value: string, [key: string]: any }) => Promise<true | string>
 *
 * `true`   → tag is valid.
 * string   → tag is invalid; the string is shown as the error message.
 *
 * Pass the result to emailTagInput.addValidator().
 */

/**
 * Creates a validator that checks the email's domain against an allowed list.
 *
 * This is a client-side mirror of cboxol_wildcard_email_domain_check() in
 * cbox-openlab-core/includes/registration.php.  It supports the same wildcard
 * syntax (e.g. *.cuny.edu) using the same escaping rules.
 *
 * If `allowedDomains` is empty the validator is a no-op (all domains pass),
 * which matches the server-side behaviour when no restriction has been
 * configured.
 *
 * @param {string[]} allowedDomains  Value of the `limited_email_domains` site
 *                                   option, as passed via wp_localize_script.
 * @return {Function}
 */
export function createDomainValidator( allowedDomains ) {
	const domains = ( allowedDomains || [] ).filter( Boolean );

	// No restriction configured — every domain is allowed.
	if ( domains.length === 0 ) {
		return async () => true;
	}

	// Pre-compile patterns once so repeated keystrokes don't rebuild them.
	const patterns = domains.map( ( raw ) => {
		const normalised = raw.trim().toLowerCase();

		if ( normalised.includes( '*' ) ) {
			// Mirror the PHP escaping from cboxol_wildcard_email_domain_check():
			//   str_replace( '.', '\.', $domain )            → escape literal dots
			//   str_replace( '*', '[-_\.a-zA-Z0-9]+', ... )  → expand wildcard
			//   '/^' . $pattern . '/'                         → anchor to start
			const escaped = normalised
				.replace( /\./g, '\\.' )
				.replace( /\*/g, '[-_\\.a-zA-Z0-9]+' );
			return { re: new RegExp( '^' + escaped ), literal: null };
		}

		return { re: null, literal: normalised };
	} );

	/**
	 * @param {{ value: string }} tagData
	 * @return {Promise<true|string>}
	 */
	return async ( tagData ) => {
		const atIndex = tagData.value.lastIndexOf( '@' );

		// Malformed — let the format validator handle this, not us.
		if ( atIndex === -1 ) {
			return true;
		}

		const domain = tagData.value.slice( atIndex + 1 ).toLowerCase();

		const allowed = patterns.some( ( p ) =>
			p.re ? p.re.test( domain ) : p.literal === domain
		);

		if ( ! allowed ) {
			return `${ domain } is not a permitted email domain.`;
		}

		return true;
	};
}
