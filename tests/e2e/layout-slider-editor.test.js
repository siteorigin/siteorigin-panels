/** Exercise real widget forms and production saves, rather than seeded instances. */
const fs = require( 'fs' );
const { expect, test } = require( '@playwright/test' );
const {
	addRow, addWidget, adminLogin, closeDialog, createPost, deletePost,
	newLoggedInPage, openClassicBuilder, openDialog, openWidgetDialog,
	rawStorage, seedLayout, setWidgetText, siteUrl, sliderInstances,
} = require( './builder-helpers' );

test.skip( ! process.env.PANELS_E2E_WIDGETS_BUNDLE_ROOT, 'Requires Widgets Bundle.' );

// Independently created forms/widgets have different identities. Classic saves
// also add sidebar-emulator bookkeeping; none of these keys are widget settings.
const settings = ( value ) => {
	if ( Array.isArray( value ) ) {
		return value.map( settings );
	}
	if ( value && typeof value === 'object' ) {
		return Object.fromEntries( Object.entries( value )
			.filter( ( [ key ] ) => ! [ '_sow_form_timestamp', '_sow_form_id', 'builder_id', 'widget_id', 'option_name', 'so_sidebar_emulator_id' ].includes( key ) )
			.map( ( [ key, item ] ) => [ key, settings( item ) ] ) );
	}
	return value;
};

const save = async ( page, source, first ) => {
	if ( source === 'classic' ) {
		await Promise.all( [
			page.waitForResponse( ( response ) => response.request().method() === 'POST' && new URL( response.url() ).pathname.endsWith( '/post.php' ), { timeout: 60000 } ),
			page.locator( '#publish' ).click(),
		] );
		await page.waitForURL( /message=\d+/, { timeout: 60000 } );
		await page.waitForLoadState( 'load' );
	} else {
		await page.waitForFunction( () => ! wp.data.select( 'core/editor' ).isPostSavingLocked() );
		await page.locator( '.editor-post-publish-panel__toggle, .editor-post-publish-button' ).first().click();
		if ( first ) {
			await page.locator( '.editor-post-publish-panel__header-publish-button button, .editor-post-publish-panel__header-publish-button .components-button' ).first().click();
		}
		await page.waitForFunction( () => {
			const editor = wp.data.select( 'core/editor' );
			return ! editor.isSavingPost() && editor.didPostSaveRequestSucceed() && ! editor.isEditedPostDirty();
		}, null, { timeout: 30000 } );
	}
};

test( 'real classic and Layout Block forms preserve frame defaults and intentional blanks through save and reload', async ( { browser }, testInfo ) => {
	const admin = await adminLogin();
	const ids = [];
	const evidence = {};
	let ctx;
	try {
		// Activate the optional slider widget through Widgets Bundle's fixture loader.
		// This contains no editor data: both layouts below are built from empty forms.
		const activationId = await createPost( admin, 'post', { title: 'Activate slider' } );
		ids.push( activationId );
		await seedLayout( admin, activationId, { widgets: [ { panels_info: { class: 'SiteOrigin_Widget_LayoutSlider_Widget' } } ] }, { sanitize: true } );
		for ( const source of [ 'classic', 'block' ] ) {
			ctx = await newLoggedInPage( browser, process.env.WP_USERNAME, process.env.WP_PASSWORD );
			const { page } = ctx;
			let root;
			let id;
			if ( source === 'classic' ) {
				root = await openClassicBuilder( page, { postType: 'post' } );
				id = Number( await page.locator( '#post_ID' ).inputValue() );
				await page.locator( '#title' ).fill( 'Slider classic form' );
			} else {
				await page.goto( siteUrl( 'wp-admin/post-new.php?block-editor' ) );
				await page.waitForFunction( () => window.wp && wp.data.select( 'core/editor' ) && wp.data.select( 'core/editor' ).getCurrentPostId() );
				await page.evaluate( () => {
					wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
					wp.data.dispatch( 'core/preferences' ).set( 'core', 'enableChoosePatternModal', false );
					wp.data.dispatch( 'core/editor' ).editPost( { title: 'Slider block form' } );
					wp.data.dispatch( 'core/block-editor' ).insertBlock( wp.blocks.createBlock( 'siteorigin-panels/layout-block' ) );
				} );
				for ( let i = 0; i < 5 && await page.locator( '.components-modal__screen-overlay' ).count(); i++ ) {
					await page.keyboard.press( 'Escape' );
					await page.waitForTimeout( 500 );
				}
				id = await page.evaluate( () => wp.data.select( 'core/editor' ).getCurrentPostId() );
				const canvas = await page.locator( 'iframe[name="editor-canvas"]' ).count() ? page.frameLocator( 'iframe[name="editor-canvas"]' ) : page;
				root = canvas.locator( '.siteorigin-panels-layout-block-container' );
				await expect( root.locator( '.so-builder-toolbar' ) ).toBeVisible( { timeout: 30000 } );
			}
			ids.push( id );
			const row = await addRow( root, 1, page );
			const widget = await addWidget( root, row.locator( '.so-cells .cell' ).first(), 'SiteOrigin_Widget_LayoutSlider_Widget', page );
			const dialog = await openWidgetDialog( widget, page );
			await expect( dialog.locator( '.so-sidebar .style-section-wrapper' ).first() ).toBeAttached();
			const repeater = dialog.locator( '[data-repeater-name="frames"]' );
			await repeater.locator( ':scope > .siteorigin-widget-field-repeater-add' ).click();
			const frame = repeater.locator( ':scope > .siteorigin-widget-field-repeater-items > .siteorigin-widget-field-repeater-item' ).first();
			await frame.locator( '.siteorigin-widget-field-repeater-item-top' ).click();
			await frame.locator( '.siteorigin-panels-display-builder' ).click();
			const nested = openDialog( page ).locator( '.siteorigin-panels-builder' );
			const nestedRow = await addRow( nested, 1, page );
			const text = await addWidget( nested, nestedRow.locator( '.so-cells .cell' ).first(), 'Panels_E2E_Text_Widget', page );
			await setWidgetText( nested, text, 'Slider frame content', page );
			await closeDialog( page );
			await closeDialog( page );
			await save( page, source, true );

			evidence[ source ] = {};
			for ( const cleared of [ false, true ] ) {
				if ( cleared ) {
					await page.reload();
					if ( source === 'block' ) {
						await page.waitForFunction( () => wp.data.select( 'core/block-editor' ).getBlocks().length > 0 );
						await page.evaluate( () => wp.data.dispatch( 'core/block-editor' ).selectBlock( wp.data.select( 'core/block-editor' ).getBlocks()[ 0 ].clientId ) );
						await page.getByRole( 'button', { name: 'Edit layout.', exact: true } ).click();
					}
					await expect( root.locator( '.so-widget' ).first() ).toBeVisible( { timeout: 30000 } );
					// Reopen the saved form, and deliberately clear the two settings.
					const savedDialog = await openWidgetDialog( root.locator( '.so-widget' ).first(), page );
					const color = savedDialog.locator( 'input.siteorigin-widget-input[name$="[color]"]' ).first();
					const padding = savedDialog.locator( 'input.siteorigin-widget-input[name$="[padding_sides]"]' ).first();
					await expect( color ).toHaveValue( '#333333' );
					await expect( padding ).toHaveValue( '20' );
					// Section fields can be collapsed, but the actual form controls serialize their values.
					await color.evaluate( ( input ) => { input.value = ''; input.dispatchEvent( new Event( 'change', { bubbles: true } ) ); } );
					await padding.evaluate( ( input ) => { input.value = ''; input.dispatchEvent( new Event( 'change', { bubbles: true } ) ); } );
					await closeDialog( page );
					await save( page, source, false );
				}
				const storage = await rawStorage( admin, id );
				const storedLayout = source === 'classic' ? storage.meta : storage.blocks[ 0 ];
				expect( storedLayout, `${ source } ${ cleared ? 'cleared' : 'defaults' } storage: ${ JSON.stringify( storage ) }` ).toBeTruthy();
				const widgets = storedLayout.widgets;
				expect( widgets[ 0 ].frames[ 0 ].background.color ).toBe( cleared ? '' : '#333333' );
				expect( widgets[ 0 ].layout.desktop.padding_sides ).toBe( cleared ? '' : '20px' );
				const frontend = await ctx.context.newPage();
				await frontend.goto( siteUrl( `?p=${ id }&panels_e2e_slider_instance=1` ) );
				await expect( frontend.locator( '.sow-slider-image:not(.cycle-sentinel)' ) ).toHaveCSS( 'background-color', cleared ? 'rgba(0, 0, 0, 0)' : 'rgb(51, 51, 51)' );
				await expect( frontend.locator( '.sow-slider-image-wrapper' ).first() ).toHaveCSS( 'padding-left', cleared ? '10px' : '20px' );
				const beforeLess = await sliderInstances( frontend );
				expect( beforeLess ).toHaveLength( 1 );
				evidence[ source ][ cleared ? 'cleared' : 'defaults' ] = { stored: widgets, beforeLess, hash: await frontend.locator( '.so-widget-sow-layout-slider' ).first().getAttribute( 'class' ).then( ( classes ) => classes.match( /so-widget-sow-layout-slider-default-([a-f0-9]{12})/ )[ 1 ] ) };
				expect( await rawStorage( admin, id ) ).toEqual( storage );
				await frontend.close();
			}
			await ctx.context.close();
			ctx = null;
		}
		const artifact = testInfo.outputPath( 'editor-instances.json' );
		fs.writeFileSync( artifact, JSON.stringify( evidence, null, 2 ) );
		await testInfo.attach( 'editor-instances', { path: artifact, contentType: 'application/json' } );
		expect( settings( evidence.block ) ).toEqual( settings( evidence.classic ) );
	} finally {
		if ( ctx ) {
			await ctx.context.close();
		}
		for ( const id of ids ) {
			await deletePost( admin, 'post', id );
		}
		await admin.context.dispose();
	}
} );
