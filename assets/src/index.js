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
	const invitationTables = document.querySelectorAll(
		'table[data-cboxol-gi-sortable]'
	);

	const updateInvitationSortLinks = ( table, sort, order ) => {
		const sortParam = table.dataset.sortParam;
		const orderParam = table.dataset.orderParam;

		table.querySelectorAll( '.cboxol-gi-sort-link' ).forEach( ( link ) => {
			const isActive = link.dataset.sort === sort;
			const nextOrder = isActive && order === 'desc' ? 'asc' : 'desc';
			const url = new URL( window.location.href );
			let ariaSort = 'none';

			if ( isActive ) {
				ariaSort = order === 'asc' ? 'ascending' : 'descending';
			}

			link.classList.toggle( 'is-active', isActive );
			link.dataset.order = nextOrder;
			link.closest( 'th' ).setAttribute( 'aria-sort', ariaSort );
			url.searchParams.set( sortParam, link.dataset.sort );
			url.searchParams.set( orderParam, nextOrder );
			link.href = url.toString();
		} );
	};

	const sortInvitationTable = ( table, sort, order ) => {
		const rows = [ ...table.tBodies[ 0 ].rows ].map( ( row, index ) => ( {
			row,
			index,
		} ) );

		rows.sort( ( first, second ) => {
			if ( sort === 'accepted' ) {
				const firstPending = first.row.dataset.sortPending === '1';
				const secondPending = second.row.dataset.sortPending === '1';

				if ( firstPending !== secondPending ) {
					const pendingFirst = firstPending ? -1 : 1;

					return order === 'desc' ? pendingFirst : -pendingFirst;
				}
			}

			const attribute = `sort${ sort
				.split( '-' )
				.map(
					( part ) => part.charAt( 0 ).toUpperCase() + part.slice( 1 )
				)
				.join( '' ) }`;
			const firstValue = Number( first.row.dataset[ attribute ] || 0 );
			const secondValue = Number( second.row.dataset[ attribute ] || 0 );
			const difference =
				order === 'asc'
					? firstValue - secondValue
					: secondValue - firstValue;

			return difference || first.index - second.index;
		} );

		rows.forEach( ( { row } ) => table.tBodies[ 0 ].appendChild( row ) );
		updateInvitationSortLinks( table, sort, order );
	};

	invitationTables.forEach( ( table ) => {
		table.addEventListener( 'click', ( event ) => {
			const link = event.target.closest( '.cboxol-gi-sort-link' );

			if ( ! link || ! table.contains( link ) ) {
				return;
			}

			event.preventDefault();
			const sort = link.dataset.sort;
			const order = link.dataset.order;

			sortInvitationTable( table, sort, order );
			const url = new URL( window.location.href );
			url.searchParams.set( table.dataset.sortParam, sort );
			url.searchParams.set( table.dataset.orderParam, order );
			window.history.replaceState( {}, '', url );
		} );
	} );

	const updateInvitationFilterLinks = ( controls, filter ) => {
		const table = document.querySelector(
			`table[data-filter-table="${ controls.dataset.filterTable }"]`
		);
		const filterParam = table.dataset.filterParam;

		controls
			.querySelectorAll( '.cboxol-gi-invitation-filter' )
			.forEach( ( link ) => {
				const isActive = link.dataset.filter === filter;
				const url = new URL( window.location.href );

				link.classList.toggle( 'is-active', isActive );

				if ( isActive ) {
					link.setAttribute( 'aria-current', 'page' );
				} else {
					link.removeAttribute( 'aria-current' );
				}

				url.searchParams.set( filterParam, link.dataset.filter );
				link.href = url.toString();
			} );
	};

	const filterInvitationTable = ( table, filter ) => {
		let hasVisibleRows = false;

		[ ...table.tBodies[ 0 ].rows ].forEach( ( row ) => {
			const isHidden =
				filter !== 'all' && row.dataset.filterStatus !== filter;

			row.hidden = isHidden;
			hasVisibleRows ||= ! isHidden;
		} );

		table.parentElement.querySelector( '.cboxol-gi-filter-empty' ).hidden =
			hasVisibleRows;
	};

	document
		.querySelectorAll( '[data-cboxol-gi-invitation-filters]' )
		.forEach( ( controls ) => {
			controls.addEventListener( 'click', ( event ) => {
				const link = event.target.closest(
					'.cboxol-gi-invitation-filter'
				);

				if ( ! link || ! controls.contains( link ) ) {
					return;
				}

				event.preventDefault();
				const filter = link.dataset.filter;
				const table = document.querySelector(
					`table[data-filter-table="${ controls.dataset.filterTable }"]`
				);

				filterInvitationTable( table, filter );
				updateInvitationFilterLinks( controls, filter );

				const url = new URL( window.location.href );
				url.searchParams.set( table.dataset.filterParam, filter );
				window.history.replaceState( {}, '', url );
			} );
		} );

	const directAddModeEl = document.getElementById(
		'import-existing-members-mode-direct-add'
	);
	const directAddAcknowledgementEl = document.getElementById(
		'import-direct-add-acknowledgement'
	);
	const importModeEls = document.querySelectorAll(
		'input[name="import-existing-members-mode"]'
	);

	if (
		directAddModeEl &&
		directAddAcknowledgementEl &&
		importModeEls.length
	) {
		const updateDirectAddAcknowledgement = () => {
			directAddAcknowledgementEl.hidden = ! directAddModeEl.checked;
		};

		importModeEls.forEach( ( importModeEl ) =>
			importModeEl.addEventListener(
				'change',
				updateDirectAddAcknowledgement
			)
		);
		updateDirectAddAcknowledgement();
	}

	const inputEl = document.getElementById( 'email-tag-input' );

	if ( ! inputEl ) {
		return;
	}

	const {
		restEndpoint      = '',
		validateEndpoint  = '',
		nonce             = '',
		allowedDomains    = [],
		matchByEmail      = false,
	} = window.cboxolGroupInvitations || {};

	const emailTagInput = new EmailTagInput( inputEl, {
		endpoint:         restEndpoint,
		validateEndpoint,
		nonce,
		matchByEmail,
	} );

	if ( allowedDomains.length ) {
		emailTagInput.addValidator( createDomainValidator( allowedDomains ) );
	}
} );
