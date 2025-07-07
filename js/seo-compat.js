/* global jQuery, YoastSEO,rankMathEditor,  _, panelsOptions */

jQuery( function( $ ) {

	const SiteOriginSeoCompat = () => ({
		allowedTags: [
			'p', 'a', 'img', 'caption', 'br',
			'blockquote', 'cite',
			'em', 'strong', 'i', 'b',
			'q',
			'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
			'ul', 'ol', 'li',
			'table', 'tr', 'th', 'td'
		].join( ',' ),

		isClassicEditorPanelsEnabled: function() {
			return $( '#so-panels-panels.attached-to-editor' ).is( ':visible' );
		},

		isBlockEditorPanelsEnabled: function() {
			return typeof window.soPanelsBuilderView !== 'undefined' && $( '.block-editor-page' ).length
		},

		/**
		 * Find SiteOrigin layout blocks in the provided data.
		 *
		 * This function searches the provided data for SiteOrigin
		 * layout blocks using a regular expression.
		 * It matches block with the siteorigin-panels/layout-block name.
		 *
		 * @param {string} data - The data to search for SiteOrigin layout blocks.
		 *
		 * @returns {Array|null} An array of matched SiteOrigin layout blocks, or null if no matches are found.
		 */
		findSoLayoutBlocks: function( data ) {
			return data.match(
				/<!--\s*wp:siteorigin-panels\/layout-block[\s\S]*?\/-->/g
			);
		},

		/**
		 * Determine how to process the modified content based on context.
		 *
		 * This function is called by SEO plugins after a content modification.
		 * It determines if the content is from the Classic Editor or the Block
		 * Editor, and then processes it accordingly.
		 *
		 * @param {string} data - The content data to be modified.
		 *
		 * @returns {string} The modified content data.
		 */
		seoContentChange: function( data ) {
			if ( ! data ) {
				return data;
			}

			const isClassicEditor = this.isClassicEditorPanelsEnabled();
			const isBlockEditor = this.isBlockEditorPanelsEnabled();

			// If Page Builder isn't set up for this page,
			// return the data as is.
			if (
				! isClassicEditor &&
				! isBlockEditor
			) {
				return data;
			}

			// Is this the Classic Editor?
			if (
				isClassicEditor &&
				! isBlockEditor
			) {
				return this.contentModification( data );
			}

			// The current context has to be the Block Editor.
			return this.processBlocks( data );
		},

		/**
		 * Process SiteOrigin layout blocks in the content.
		 *
		 * This function searches for SiteOrigin layout blocks in the
		 * content and replaces them with the rendered contents. This allows SEO
		 * plugins to correctly analyze the content of the SO Layout blocks.
		 *
		 * @param {string} data - The content data to be processed.
		 *
		 * @returns {string} The processed content data.
		 */
		processBlocks: function( data ) {
			const soBlocks = this.findSoLayoutBlocks( data );

			// If there are no SO Layout blocks, return the data as is.
			if ( ! soBlocks ) {
				return data;
			}

			// Replace any found SO Layout blocks with the rendered contents.
			soBlocks.forEach( function( block ) {
				data = data.replace( block, this.contentModification( block ) );
			}, this );

			return data;
		},

		/**
		 * Extract text from the provided data.
		 *
		 * This function extracts content from the provided data by removing
		 * elements that have no content analysis value and filtering out
		 * everything else that's not in the allowed tags list.
		 *
		 * @param {string} data - The content data to be extracted.
		 *
		 * @returns {string} The extracted content data.
		 */
		extractContent: function( data ) {
			const $data = $( data );

			if ( $data.find( '.so-panel' ).length === 0 ) {
				// Skip this for empty pages
				return data;
			}

			// Remove elements that have no content analysis value.
			$data.find( 'iframe, script, style, link' ).remove();

			// Filter out everything else that's not in the allowed tags list.
			$data.find( '*' ) .not( this.allowedTags ).each( function() {
				const $$ = $( this );

				$$.replaceWith(
					$$.contents()
				);
			} );

			return $data.html();
		},

		/**
		 * Modify the content for SEO analysis.
		 *
		 * This function modifies the content for SEO analysis by
		 * extracting content from the SiteOrigin Panels Builder view.
		 *
		 * @param {string} data - The content data to be modified.
		 *
		 * @returns {string} The modified content data.
		 */
		contentModification: function( data ) {
			if ( ! Array.isArray( window.soPanelsBuilderView ) ) {
				return this.extractContent( window.soPanelsBuilderView.contentPreview );
			}

			data = null;
			window.soPanelsBuilderView.forEach( function( panel ) {
				data += this.extractContent( panel.contentPreview );
			}, this );

			return data;
		},

		init: function() {
			if ( typeof YoastSEO !== 'undefined' ) {
				YoastSEO.app.registerPlugin( 'SiteOriginSeoCompat', { status: 'ready' } );
				YoastSEO.app.registerModification( 'content', this.seoContentChange.bind( this ), 'SiteOriginSeoCompat', 5 );
			}

			if ( typeof rankMathEditor !== 'undefined' ) {
				wp.hooks.addFilter( 'rank_math_content', 'SiteOriginSeoCompat', this.seoContentChange.bind( this ) );
			}
		}
	} );

	if ( typeof rankMathEditor !== 'undefined' ) {
		SiteOriginSeoCompat().init();
	}

	$( window ).on( 'YoastSEO:ready', () => {
		SiteOriginSeoCompat().init();
	} );
} );
