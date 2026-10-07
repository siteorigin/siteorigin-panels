/**
 * Layout Slider frames contain Page Builder layouts. Check a stretched row in
 * a Layout Builder widget inside a frame, including a frame that starts hidden
 * and is then made visible by the slider.
 * This integration run mounts Widgets Bundle in WordPress Playground.
 */
const { expect, test } = require( '@playwright/test' );
const { adminLogin, createPost, deletePost, seedLayout, siteUrl } = require( './builder-helpers' );
const { layoutBlock } = require( './helpers' );

test.skip( ! process.env.PANELS_E2E_WIDGETS_BUNDLE_ROOT, 'Requires Widgets Bundle.' );

const gridCells = [ { grid: 0, index: 0, weight: 1, style: {} } ];
const frameLayout = ( label ) => {
	const deepLayout = {
		widgets: [ {
			text: label,
			panels_info: { class: 'Panels_E2E_Text_Widget', grid: 0, cell: 0, id: 0, style: {} },
		} ],
		grids: [ { cells: 1, style: { row_stretch: 'full-width-stretch' } } ],
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

const outerLayout = {
	widgets: [ {
		frames: [ { content: frameLayout( 'Slide one' ) }, { content: frameLayout( 'Slide two' ) } ],
		controls: { pagination: true, arrows: true, autoplay: false },
		layout: { desktop: { height: '400px', width: '600px' } },
		panels_info: { class: 'SiteOrigin_Widget_LayoutSlider_Widget', grid: 0, cell: 0, id: 0, style: {} },
	} ],
	grids: [ { cells: 1, style: {} } ],
	grid_cells: gridCells,
};

for ( const source of [ 'classic', 'block' ] ) {
	test( `${ source } Layout Slider keeps nested stretched rows full width after changing frames`, async ( { browser } ) => {
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
				...( source === 'block' ? { content: layoutBlock( outerLayout ) } : {} ),
			} );
			if ( source === 'classic' ) {
				await seedLayout( admin, id, outerLayout );
			}

			const target = siteUrl( `?page_id=${ id }&panels_e2e_legacy_container=1` );
			await context.route( target, async ( route ) => {
				const response = await route.fetch();
				const html = ( await response.text() ).replace(
					'</head>',
					'<style>.panel-layout{max-width:600px!important;margin:0 auto!important}.panels-e2e-text{height:120px}</style></head>'
				);
				await route.fulfill( { response, body: html } );
			} );
			const page = await context.newPage();
			const errors = [];
			page.on( 'pageerror', ( error ) => errors.push( error.message ) );
			await page.goto( target, { waitUntil: 'load' } );
			const slider = page.locator( '.sow-slider-base' ).first();
			await expect( slider ).toBeVisible();
			const frames = slider.locator( '.sow-slider-image' );
			const inspect = () => frames.evaluateAll( ( elements ) => elements.map( ( element ) => ( {
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
			await expect.poll( async () => ( await inspect() ).some( ( frame ) => frame.text === 'Slide two' && frame.visibility === 'visible' ) ).toBe( true );
			const active = ( await inspect() ).find( ( frame ) => frame.text === 'Slide two' && frame.visibility === 'visible' );
			expect( active.rows ).toHaveLength( 2 );
			for ( const row of active.rows ) {
				expect( Math.abs( row.left ) ).toBeLessThanOrEqual( 1 );
				expect( Math.abs( row.right - row.viewport ) ).toBeLessThanOrEqual( 1 );
			}
			expect( errors ).toEqual( [] );
		} finally {
			await context.close();
			if ( id ) {
				await deletePost( admin, 'page', id );
			}
			await admin.context.dispose();
		}
	} );
}
