/**
 * Heading Color on a column and a widget colours h1 to h6 and the links in them, below a widget's
 * own heading or title link colour and below Page Builder's Link Color. The nearest column or
 * widget setting wins, whatever order the rules print in. Runs on Twenty Twenty-Five with
 * Widgets Bundle (PANELS_E2E_WIDGETS_BUNDLE_ROOT), with the flex and the legacy renderer.
 */
const { expect, test } = require( '@playwright/test' );
const { adminLogin, createPost, deletePost, seedLayout, siteUrl, uiOption } = require( './builder-helpers' );

const CELL = '#abcdef';
const WIDGET = '#fedcba';
const OWN = '#c81e1e';
const LINK = '#1e8c1e';

const LEVELS = [ 1, 2, 3, 4, 5, 6 ];
const linkedHeadings = LEVELS.map( ( n ) => `<h${ n } class="hc-h${ n }">Heading ${ n } <a href="/">link ${ n }</a></h${ n }>` ).join( '' );

const blocks = [
	'<!-- wp:heading {"textColor":"accent-3"} --><h2 class="wp-block-heading hc-preset has-accent-3-color has-text-color">Preset colour</h2><!-- /wp:heading -->',
	'<!-- wp:cover {"customOverlayColor":"#333333","textColor":"accent-3","isDark":true} --><div class="wp-block-cover is-dark has-accent-3-color has-text-color"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-100 has-background-dim" style="background-color:#333333"></span><div class="wp-block-cover__inner-container"><!-- wp:heading --><h2 class="wp-block-heading hc-cover">Cover heading</h2><!-- /wp:heading --></div></div><!-- /wp:cover -->',
	'<!-- wp:heading {"style":{"elements":{"link":{"color":{"text":"#ff8800"}}}}} --><h2 class="wp-block-heading hc-block-link has-link-color">Block <a href="/">link</a></h2><!-- /wp:heading -->',
	'<!-- wp:group {"style":{"elements":{"heading":{"color":{"text":"#ff8800"}}}}} --><div class="wp-block-group"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading hc-group">Group heading</h3><!-- /wp:heading --></div><!-- /wp:group -->',
].join( '' );

const info = ( grid, cell, style = {} ) => ( { grid, cell, style } );
const editor = ( title, text, grid, cell, style ) => ( { title, text, text_selected_editor: 'html', autop: false, panels_info: { class: 'SiteOrigin_Widget_Editor_Widget', ...info( grid, cell, style ) } } );
const headline = ( cls, color, grid, cell ) => ( { headline: { text: cls, tag: 'h2', color, destination_url: '/' }, sub_headline: { text: '' }, panels_info: { class: 'SiteOrigin_Widget_Headline_Widget', ...info( grid, cell, { class: cls } ) } } );
const cta = ( cls, color, grid, cell ) => ( { title: cls, sub_title: 'Sub', design: { colors: { title_color: color } }, panels_info: { class: 'SiteOrigin_Widget_Cta_Widget', ...info( grid, cell, { class: cls } ) } } );
const features = ( cls, color, grid, cell ) => ( { features: [ { title: cls, text: 'Text', more_url: '/', icon_title: '' } ], title_link: true, fonts: { title_options: { color } }, panels_info: { class: 'SiteOrigin_Widget_Features_Widget', ...info( grid, cell, { class: cls } ) } } );
const hero = ( cls, color, grid, cell ) => ( { frames: [ { content: `<h2>Hero <a href="/">link</a></h2>`, buttons: [], background: { color: '#333333' } } ], design: color ? { heading_color: color } : {}, panels_info: { class: 'SiteOrigin_Widget_Hero_Widget', ...info( grid, cell, { class: cls } ) } } );
const recent = ( cls, color, grid, cell, style = {} ) => ( { title: '', post_title: true, recent_design: color ? { title: { color } } : {}, panels_info: { class: 'SiteOrigin_Widget_Recent_Posts_Widget', ...info( grid, cell, { class: cls, ...style } ) } } );
const author = ( cls, color, grid, cell, style = {} ) => ( { settings: { link_name: true }, design: color ? { name: { color } } : {}, panels_info: { class: 'SiteOrigin_Widget_Author_Box_Widget', ...info( grid, cell, { class: cls, ...style } ) } } );
const probe = ( grid, cell ) => ( { text: '<div class="test-widget"><div class="test-wrap"><h3 class="test-title weak">Weak</h3><h3 class="test-title strong">Strong</h3></div></div>', panels_info: { class: 'Panels_E2E_Probe_Widget', ...info( grid, cell ) } } );
const block = ( grid, cell ) => ( { content: blocks, panels_info: { class: 'WP_Widget_Block', ...info( grid, cell ) } } );

const dark = { background: '#222222' };
const layout = {
	widgets: [
		// Row 0, cell 0: column Heading Color.
		editor( 'Editor title', linkedHeadings, 0, 0, { class: 'hc-editor' } ),
		editor( '', '<h2 class="hc-linkcolor">Linked <a href="/">link</a></h2>', 0, 0, { link_color: LINK } ),
		block( 0, 0 ),
		headline( 'hc-headline-own', OWN, 0, 0 ),
		headline( 'hc-headline', '', 0, 0 ),
		cta( 'hc-cta-own', OWN, 0, 0 ),
		cta( 'hc-cta', '', 0, 0 ),
		features( 'hc-features-own', OWN, 0, 0 ),
		features( 'hc-features', '', 0, 0 ),
		hero( 'hc-hero-default', '', 0, 0 ),
		hero( 'hc-hero-own', OWN, 0, 0 ),
		recent( 'hc-recent-default', '', 0, 0 ),
		recent( 'hc-recent-own', OWN, 0, 0 ),
		author( 'hc-author-default', '', 0, 0 ),
		author( 'hc-author-own', OWN, 0, 0 ),
		probe( 0, 0 ),
		// Row 0, cell 1: no Heading Color on the column; widget Heading Color.
		editor( 'Widget title', '<h2 class="hc-w">Widget <a href="/">link</a></h2>', 0, 1, { heading_color: WIDGET } ),
		recent( 'hc-recent-w', '', 0, 1, { heading_color: WIDGET } ),
		author( 'hc-author-w', '', 0, 1, { heading_color: WIDGET } ),
		// Row 1, cell 0: an earlier column with the widget's colour, so its rule group prints first.
		editor( '', '<h2 class="hc-first">First</h2>', 1, 0 ),
		// Row 1, cell 1: column and widget Heading Color.
		editor( '', '<h2 class="hc-nested">Nested <a href="/">link</a></h2>', 1, 1, { heading_color: WIDGET } ),
		editor( '', '<h2 class="hc-sibling">Sibling</h2>', 1, 1 ),
	],
	grids: [ { cells: 2, style: {} }, { cells: 2, style: {} } ],
	grid_cells: [
		{ grid: 0, index: 0, weight: 0.5, style: { ...dark, heading_color: CELL } },
		{ grid: 0, index: 1, weight: 0.5, style: dark },
		{ grid: 1, index: 0, weight: 0.5, style: { ...dark, heading_color: WIDGET } },
		{ grid: 1, index: 1, weight: 0.5, style: { ...dark, heading_color: CELL } },
	],
};
layout.widgets.forEach( ( widget, i ) => {
	widget.panels_info.id = `hc-${ i }`;
} );

let admin;
let postId;
let activeWidgets;

test.beforeAll( async () => {
	admin = await adminLogin();

	// Widgets Bundle does not load these two from their class names, so turn them on.
	activeWidgets = await uiOption( admin, 'siteorigin_widgets_active' );
	await uiOption( admin, 'siteorigin_widgets_active', { value: { ...( activeWidgets.value || {} ), 'recent-posts': true, 'author-box': true } } );

	postId = await createPost( admin, 'page', { title: 'Heading Color', status: 'publish' } );
	await seedLayout( admin, postId, layout );
} );

test.afterAll( async () => {
	await deletePost( admin, 'page', postId );
	await uiOption( admin, 'siteorigin_widgets_active', activeWidgets.exists ? { value: activeWidgets.value } : { exists: false } );
} );

const rgb = ( hex ) => `rgb(${ [ 1, 3, 5 ].map( ( i ) => parseInt( hex.slice( i, i + 2 ), 16 ) ).join( ', ' ) })`;

const ASSERTIONS = [
	// AC 2: Editor headings, their links and the widget title follow the column.
	...LEVELS.flatMap( ( n ) => [ [ `.hc-editor .hc-h${ n }`, CELL ], [ `.hc-editor .hc-h${ n } a`, CELL ] ] ),
	[ '.hc-editor .widget-title', CELL ],
	// Page Builder Link Color wins over a heading's colour.
	[ '.hc-linkcolor', CELL ],
	[ '.hc-linkcolor a', LINK ],
	// Core blocks, as in the benchmark builders: the block's inherited text colour and its generated
	// link and heading element colours lose to Heading Color.
	[ '.hc-cover', CELL ],
	[ '.hc-block-link', CELL ],
	[ '.hc-block-link a', CELL ],
	[ '.hc-group', CELL ],
	// AC 3: a widget's own heading or title colour wins, set or at its form default.
	[ '.hc-headline-own .sow-headline', OWN ],
	[ '.hc-headline-own .sow-headline a', OWN ],
	[ '.hc-headline .sow-headline', CELL ],
	[ '.hc-headline .sow-headline a', CELL ],
	[ '.hc-cta-own .sow-cta-title', OWN ],
	[ '.hc-cta .sow-cta-title', CELL ],
	[ '.hc-features-own .sow-features-feature-title', OWN ],
	[ '.hc-features-own .sow-features-feature-title a', OWN ],
	[ '.hc-features .sow-features-feature-title', CELL ],
	[ '.hc-features .sow-features-feature-title a', CELL ],
	[ '.hc-hero-default .sow-slider-image h2', '#ffffff' ],
	[ '.hc-hero-default .sow-slider-image h2 a', '#ffffff' ],
	[ '.hc-hero-own .sow-slider-image h2', OWN ],
	[ '.hc-hero-own .sow-slider-image h2 a', OWN ],
	[ '.hc-recent-default .sow-recent-posts-title a', '#2d2d2d' ],
	[ '.hc-recent-own .sow-recent-posts-title a', OWN ],
	[ '.hc-recent-w .sow-recent-posts-title a', '#2d2d2d' ],
	[ '.hc-author-default .sow-author-box :is(h1,h2,h3,h4,h5,h6) a', '#2d2d2d' ],
	[ '.hc-author-own .sow-author-box :is(h1,h2,h3,h4,h5,h6) a', OWN ],
	[ '.hc-author-w .sow-author-box :is(h1,h2,h3,h4,h5,h6) a', '#2d2d2d' ],
	// Third-party boundary: a widget rule below 0,2,1 loses, a stronger one keeps its colour.
	[ '.test-widget .weak', CELL ],
	[ '.test-widget .strong', '#654321' ],
	// AC 4: the nearest setting wins, in either print order.
	[ '.hc-w', WIDGET ],
	[ '.hc-w a', WIDGET ],
	[ '#panel-{id}-0-1-0 .widget-title', WIDGET ],
	[ '.hc-first', WIDGET ],
	[ '.hc-nested', WIDGET ],
	[ '.hc-nested a', WIDGET ],
	[ '.hc-sibling', CELL ],
];

for ( const legacy of [ false, true ] ) {
	test( `headings take the nearest Heading Color${ legacy ? ' with the legacy renderer' : '' }`, async ( { page } ) => {
		await page.goto( siteUrl( `?page_id=${ postId }${ legacy ? '&panels_e2e_legacy_renderer=1' : '' }` ) );
		await expect( page.locator( '#siteorigin-panels-front-css' ) ).toHaveAttribute( 'href', legacy ? /front-legacy/ : /front-flex/ );
		await page.addStyleTag( { content: '.test-widget .weak { color: #123456 } .test-widget .test-wrap .strong { color: #654321 }' } );

		// A preset text colour on a block still wins (its class carries !important).
		const preset = await page.evaluate( () => {
			const probe = document.createElement( 'span' );
			probe.style.color = 'var(--wp--preset--color--accent-3)';
			document.body.append( probe );
			const color = getComputedStyle( probe ).color;
			probe.remove();
			return color;
		} );
		expect( preset ).not.toBe( rgb( CELL ) );
		await expect( page.locator( '.hc-preset' ) ).toHaveCSS( 'color', preset );

		for ( const [ selector, color ] of ASSERTIONS ) {
			const target = page.locator( selector.replace( '{id}', postId ) );
			await expect( target, selector ).toHaveCount( 1 );
			await expect.soft( target, selector ).toHaveCSS( 'color', rgb( color ) );
		}
	} );
}
