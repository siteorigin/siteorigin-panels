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
const { adminLogin, createPost, deletePost, seedLayout, siteUrl, sliderInstances } = require( './builder-helpers' );
const { layoutBlock, rawStorage } = require( './helpers' );

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

// Saves generate form timestamps and nested Layout Builder IDs independently.
// Every setting, including explicit blanks written by update(), must match.
const withoutSaveIds = ( value ) => {
	if ( Array.isArray( value ) ) {
		return value.map( withoutSaveIds );
	}
	if ( value && typeof value === 'object' ) {
		return Object.fromEntries( Object.entries( value )
			.filter( ( [ key ] ) => key !== '_sow_form_timestamp' && key !== 'builder_id' )
			.map( ( [ key, item ] ) => [ key, withoutSaveIds( item ) ] ) );
	}
	return value;
};

for ( const explicit of [ false, true ] ) {
	test( `Layout Slider classic and block fixtures store and render the same ${ explicit ? 'explicit' : 'omitted' } frame settings`, async ( { browser } ) => {
		const layout = outerLayout( '', 'full' );
		// Explicit values differ from the form defaults (#333333 and 20px), so a
		// default applied at render time cannot pass for a stored value.
		if ( explicit ) {
			layout.widgets[ 0 ].frames.forEach( ( frame ) => {
				frame.background = { color: '#2a6f97' };
			} );
			layout.widgets[ 0 ].layout.desktop.padding_sides = '32px';
		}
		const admin = await adminLogin();
		const context = await browser.newContext( {
			viewport: { width: 1280, height: 800 },
			storageState: { cookies: [], origins: [] },
		} );
		const ids = [];
		const stored = [];
		const rendered = [];
		const beforeLess = [];
		try {
			for ( const source of [ 'classic', 'block' ] ) {
				const id = await createPost( admin, 'page', {
					title: `Layout Slider frame settings ${ source }`,
					status: 'publish',
					...( source === 'block' ? { content: layoutBlock( layout ) } : {} ),
				} );
				ids.push( id );
				if ( source === 'classic' ) {
					await seedLayout( admin, id, layout, { sanitize: true } );
				}
				const storage = await rawStorage( admin, id );
				const widgets = ( source === 'classic' ? storage.meta : storage.blocks[ 0 ] ).widgets;
				stored.push( withoutSaveIds( widgets ) );
				expect( widgets[ 0 ].layout.desktop.padding_sides ).toBe( explicit ? '32px' : '' );
				for ( const frame of widgets[ 0 ].frames ) {
					expect( frame.background.color ).toBe( explicit ? '#2a6f97' : '' );
				}

				const page = await context.newPage();
				await page.goto( siteUrl( `?page_id=${ id }&panels_e2e_legacy_container=1&panels_e2e_slider_instance=1` ), { waitUntil: 'load' } );
				const slider = page.locator( '.sow-slider-base' ).first();
				await expect( slider ).toBeVisible();
				await expect( slider.locator( '.sow-slider-image-wrapper' ).first() ).toHaveCSS( 'padding-left', explicit ? '32px' : '10px' );
				const frames = slider.locator( '.sow-slider-image:not(.cycle-sentinel)' );
				await expect( frames ).toHaveCount( 2 );
				for ( const frame of await frames.all() ) {
					await expect( frame ).toHaveCSS( 'background-color', explicit ? 'rgb(42, 111, 151)' : 'rgba(0, 0, 0, 0)' );
				}
				rendered.push( await slider.evaluate( ( element ) => {
					const wrapper = element.closest( '.so-widget-sow-layout-slider' );
					// Stylesheet handles include the post ID; compare the settings hash.
					return wrapper.className.match( /so-widget-sow-layout-slider-default-([a-f0-9]{12})/ )[ 1 ];
				} ) );
				beforeLess.push( withoutSaveIds( await sliderInstances( page ) ) );
				// Rendering must preserve the save-time instance, including blanks.
				expect( await rawStorage( admin, id ) ).toEqual( storage );
				await page.close();
			}
			expect( stored[ 1 ] ).toEqual( stored[ 0 ] );
			expect( beforeLess[ 0 ] ).toHaveLength( 1 );
			expect( beforeLess[ 1 ] ).toEqual( beforeLess[ 0 ] );
			expect( rendered[ 0 ] ).toMatch( /^[a-f0-9]{12}$/ );
			expect( rendered[ 1 ] ).toBe( rendered[ 0 ] );
		} finally {
			await context.close();
			for ( const id of ids ) {
				await deletePost( admin, 'page', id );
			}
			await admin.context.dispose();
		}
	} );
}

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
					// Direct meta writes skip update(): missing fields get render-time
					// form defaults, whereas block saves store explicit blanks. Seed
					// the same save-time instance on both paths (issue #1431).
					await seedLayout( admin, id, layout, { sanitize: true } );
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
