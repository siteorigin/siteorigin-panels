/**
 * Layout Slider frames contain Page Builder layouts. Check a stretched row in
 * a Layout Builder widget inside a frame, including a frame that starts hidden
 * and is then made visible by the slider.
 *
 * Full Width rows in a narrow slider, and Full Width Stretched rows in a slider
 * that spans the viewport, must keep the slide text inside the slider. A Full
 * Width Stretched row in a narrow slider starts its content at the viewport
 * edge, where the slider clips it; that case is not supported, so only its row
 * geometry is checked.
 * This integration run mounts Widgets Bundle in WordPress Playground.
 */
const { expect, test } = require( '@playwright/test' );
const { adminLogin, createPost, deletePost, seedLayout, siteUrl } = require( './builder-helpers' );
const { layoutBlock } = require( './helpers' );

test.skip( ! process.env.PANELS_E2E_WIDGETS_BUNDLE_ROOT, 'Requires Widgets Bundle.' );

const gridCells = [ { grid: 0, index: 0, weight: 1, style: {} } ];
const frameLayout = ( label, nestedStretch ) => {
	const deepLayout = {
		widgets: [ {
			text: label,
			panels_info: { class: 'Panels_E2E_Text_Widget', grid: 0, cell: 0, id: 0, style: {} },
		} ],
		grids: [ { cells: 1, style: { row_stretch: nestedStretch } } ],
		grid_cells: gridCells,
	};
	return {
		widgets: [ {
			panels_data: deepLayout,
			builder_id: `sliderdeep${ label.replace( /\s/g, '' ) }`,
			panels_info: { class: 'SiteOrigin_Panels_Widgets_Layout', raw: false, grid: 0, cell: 0, id: 0, style: {} },
		} ],
		grids: [ { cells: 1, style: { row_stretch: 'full' } } ],
		grid_cells: gridCells,
	};
};

const outerLayout = ( sliderRowStretch, nestedStretch ) => ( {
	widgets: [ {
		frames: [
			{ content: frameLayout( 'Slide one', nestedStretch ) },
			{ content: frameLayout( 'Slide two', nestedStretch ) },
		],
		controls: { pagination: true, arrows: true, autoplay: false },
		layout: { desktop: { height: '400px', width: '600px' } },
		panels_info: { class: 'SiteOrigin_Widget_LayoutSlider_Widget', grid: 0, cell: 0, id: 0, style: {} },
	} ],
	grids: [ { cells: 1, style: sliderRowStretch ? { row_stretch: sliderRowStretch } : {} } ],
	grid_cells: gridCells,
} );

const fixtures = [
	{
		title: 'Layout Slider with Full Width rows keeps slide content visible after changing frames',
		sliderRowStretch: '',
		nestedStretch: 'full',
		checkVisible: true,
	},
	{
		title: 'Layout Slider in a Full Width Stretched row keeps slide content visible after changing frames',
		sliderRowStretch: 'full-width-stretch',
		nestedStretch: 'full-width-stretch',
		checkVisible: true,
	},
	{
		title: 'Layout Slider with Full Width Stretched rows spans the viewport after changing frames; content leaves the slider frame',
		sliderRowStretch: '',
		nestedStretch: 'full-width-stretch',
		checkVisible: false,
	},
];

// Playwright's visibility checks ignore clipping by an ancestor, so compare
// the text's own box with the slider's clipping box and the viewport. Cycle
// marks the incoming frame visible while the outgoing frame still covers it
// during a fade, so also check the text is what the browser hits at its centre
// and is fully opaque.
const textOnScreen = ( slides, label ) => slides.evaluateAll( ( elements, text ) => {
	const copy = ( rect ) => ( { left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom } );
	const matches = elements.filter( ( element ) => element.textContent.trim() === text && getComputedStyle( element ).visibility === 'visible' );
	const element = matches.length === 1 ? matches[ 0 ].querySelector( '.panels-e2e-text' ) : null;
	if ( ! element ) {
		return { found: false, inside: false };
	}
	const range = document.createRange();
	range.selectNodeContents( element );
	const rects = Array.from( range.getClientRects() ).filter( ( rect ) => rect.width > 0 && rect.height > 0 );
	if ( ! rects.length ) {
		return { found: true, lines: 0, inside: false };
	}
	const box = copy( rects[ 0 ] );
	const slider = matches[ 0 ].closest( '.sow-slider-base' ).getBoundingClientRect();
	const clip = {
		left: Math.max( slider.left, 0 ),
		right: Math.min( slider.right, document.documentElement.clientWidth ),
		top: Math.max( slider.top, 0 ),
		bottom: Math.min( slider.bottom, document.documentElement.clientHeight ),
	};
	const hit = document.elementFromPoint( ( box.left + box.right ) / 2, ( box.top + box.bottom ) / 2 );
	let opacity = 1;
	for ( let node = element; node; node = node.parentElement ) {
		opacity *= Number( getComputedStyle( node ).opacity );
	}
	return {
		found: true,
		lines: rects.length,
		text: box,
		clip,
		inside: box.left >= clip.left - 1 && box.right <= clip.right + 1 && box.top >= clip.top - 1 && box.bottom <= clip.bottom + 1,
		hit: !! hit && hit.closest( '.panels-e2e-text' ) === element,
		opaque: opacity >= 0.99,
	};
}, label );

for ( const fixture of fixtures ) {
	for ( const source of [ 'classic', 'block' ] ) {
		test( `${ source } ${ fixture.title }`, async ( { browser } ) => {
			const layout = outerLayout( fixture.sliderRowStretch, fixture.nestedStretch );
			const admin = await adminLogin();
			const context = await browser.newContext( {
				viewport: { width: 1280, height: 800 },
				storageState: { cookies: [], origins: [] },
			} );
			let id;
			try {
				id = await createPost( admin, 'page', {
					title: `Nested Layout Slider ${ source }`,
					status: 'publish',
					...( source === 'block' ? { content: layoutBlock( layout ) } : {} ),
				} );
				if ( source === 'classic' ) {
					await seedLayout( admin, id, layout );
				}

				const target = siteUrl( `?page_id=${ id }&panels_e2e_legacy_container=1` );
				await context.route( target, async ( route ) => {
					const response = await route.fetch();
					const html = ( await response.text() ).replace(
						'</head>',
						'<style>.panel-layout:not(.panel-layout .panel-layout){max-width:600px!important;margin:0 auto!important}.panels-e2e-text{height:120px}</style></head>'
					);
					await route.fulfill( { response, body: html } );
				} );
				const page = await context.newPage();
				const errors = [];
				const missingAssets = [];
				page.on( 'pageerror', ( error ) => errors.push( error.message ) );
				page.on( 'response', ( response ) => {
					if ( response.status() >= 400 && /\/wp-content\/plugins\/(siteorigin-panels|so-widgets-bundle)\/.*\.(css|js)(\?|$)/.test( response.url() ) ) {
						missingAssets.push( `${ response.status() } ${ response.url() }` );
					}
				} );
				const sliderCss = page.waitForResponse( ( response ) => response.url().includes( '/css/slider/slider.css' ) );
				await page.goto( target, { waitUntil: 'load' } );
				expect( ( await sliderCss ).status() ).toBe( 200 );
				const slider = page.locator( '.sow-slider-base' ).first();
				await expect( slider ).toBeVisible();
				await expect( slider ).toHaveCSS( 'overflow', 'hidden' );
				await slider.scrollIntoViewIfNeeded();
				// Cycle adds a hidden sentinel copy of a slide; leave it out.
				const slides = slider.locator( '.sow-slider-image:not(.cycle-sentinel)' );
				const inspect = () => slides.evaluateAll( ( elements ) => elements.map( ( element ) => ( {
					visibility: getComputedStyle( element ).visibility,
					text: element.textContent.trim(),
					rows: Array.from( element.querySelectorAll( '.siteorigin-panels-stretch.panel-row-style' ) ).map( ( row ) => {
						const rect = row.getBoundingClientRect();
						return { left: rect.left, right: rect.right, viewport: document.documentElement.clientWidth };
					} ),
				} ) ) );
				await expect.poll( async () => ( await inspect() ).some( ( frame ) => frame.text === 'Slide one' && frame.visibility === 'visible' ) ).toBe( true );
				const hidden = ( await inspect() ).find( ( frame ) => frame.text === 'Slide two' && frame.visibility !== 'visible' );
				expect( hidden.rows ).toHaveLength( 2 );
				await page.evaluate( () => window.jQuery( '.sow-slider-images' ).first().cycle( 1 ) );
				// Cycle marks the incoming frame visible before its slide animation
				// finishes. Measure only after it reaches its final position.
				await expect.poll( async () => {
					const settled = ( await inspect() ).some( ( frame ) =>
						frame.text === 'Slide two' && frame.visibility === 'visible' && frame.rows.length === 2 &&
						frame.rows.every( ( row ) => Math.abs( row.left ) <= 1 && Math.abs( row.right - row.viewport ) <= 1 )
					);
					if ( ! settled || ! fixture.checkVisible ) {
						return settled;
					}
					const content = await textOnScreen( slides, 'Slide two' );
					return content.found && content.lines === 1 && content.inside && content.hit && content.opaque;
				} ).toBe( true );
				const active = ( await inspect() ).find( ( frame ) => frame.text === 'Slide two' && frame.visibility === 'visible' );
				expect( active.rows ).toHaveLength( 2 );
				for ( const row of active.rows ) {
					expect( Math.abs( row.left ) ).toBeLessThanOrEqual( 1 );
					expect( Math.abs( row.right - row.viewport ) ).toBeLessThanOrEqual( 1 );
				}
				if ( fixture.checkVisible ) {
					expect( await textOnScreen( slides, 'Slide two' ) ).toMatchObject( { found: true, lines: 1, inside: true, hit: true, opaque: true } );
				}

				const sliderBox = await slider.evaluate( ( element ) => {
					const rect = element.getBoundingClientRect();
					return { left: rect.left, right: rect.right, viewport: document.documentElement.clientWidth };
				} );
				if ( fixture.sliderRowStretch ) {
					expect( Math.abs( sliderBox.left ) ).toBeLessThanOrEqual( 1 );
					expect( Math.abs( sliderBox.right - sliderBox.viewport ) ).toBeLessThanOrEqual( 1 );
				} else {
					expect( Math.abs( sliderBox.right - sliderBox.left - 600 ) ).toBeLessThanOrEqual( 1 );
					expect( Math.abs( sliderBox.left - ( sliderBox.viewport - 600 ) / 2 ) ).toBeLessThanOrEqual( 1 );
				}
				expect( errors ).toEqual( [] );
				expect( missingAssets ).toEqual( [] );
			} finally {
				await context.close();
				if ( id ) {
					await deletePost( admin, 'page', id );
				}
				await admin.context.dispose();
			}
		} );
	}
}
