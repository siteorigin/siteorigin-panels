<?php
global $post;
$layouts = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );
?>

<script type="text/template" id="siteorigin-panels-builder">

	<div class="siteorigin-panels-builder">

		<div class="so-builder-toolbar">

			<a class="so-tool-button so-widget-add" title="<?php esc_attr_e( 'Add Widget', 'siteorigin-panels' ); ?>" tabindex="0">
				<span class="so-panels-icon so-panels-icon-add-widget"></span>
				<span class="so-button-text"><?php esc_html_e( 'Add Widget', 'siteorigin-panels' ); ?></span>
			</a>

			<a class="so-tool-button so-row-add" title="<?php esc_attr_e( 'Add Row', 'siteorigin-panels' ); ?>" tabindex="0">
				<span class="so-panels-icon so-panels-icon-add-row"></span>
				<span class="so-button-text"><?php esc_html_e( 'Add Row', 'siteorigin-panels' ); ?></span>
			</a>

			<a class="so-tool-button so-prebuilt-add" title="<?php esc_attr_e( 'Prebuilt Layouts', 'siteorigin-panels' ); ?>" tabindex="0">
				<span class="so-panels-icon so-panels-icon-layouts"></span>
				<span class="so-button-text"><?php esc_html_e( 'Layouts', 'siteorigin-panels' ); ?></span>
			</a>

			<?php
			if (
				! empty( $post ) ||
				( function_exists( 'get_current_screen' ) && get_current_screen()->base == 'widgets' && get_current_screen()->is_block_editor )
			) {
				?>

				<a class="so-tool-button so-history" style="display: none" title="<?php esc_attr_e( 'Edit History', 'siteorigin-panels' ); ?>" tabindex="0">
					<span class="so-panels-icon so-panels-icon-history"></span>
					<span class="so-button-text"><?php _e( 'History', 'siteorigin-panels' ); ?></span>
				</a>

				<a class="so-tool-button so-live-editor" style="display: none" title="<?php esc_html_e( 'Live Editor', 'siteorigin-panels' ); ?>" tabindex="0">
					<span class="so-panels-icon so-panels-icon-live-editor"></span>
					<span class="so-button-text"><?php _e( 'Live Editor', 'siteorigin-panels' ); ?></span>
				</a>

			<?php } ?>

			<?php if ( SiteOrigin_Panels::display_premium_teaser() ) { ?>
				<a class="so-tool-button so-learn" title="<?php esc_attr_e( 'Page Builder Addons', 'siteorigin-panels' ); ?>" href="<?php echo esc_url( SiteOrigin_Panels::premium_url() ); ?>" target="_blank" rel="noopener noreferrer" style="margin-left: 10px;" tabindex="0">
					<span class="so-panels-icon so-panels-icon-addons"></span>
					<span class="so-button-text"><?php esc_html_e( 'Addons', 'siteorigin-panels' ); ?></span>
				</a>
			<?php } ?>
			
			<a class="so-switch-to-standard" tabindex="0"><?php _e( 'Revert to Editor', 'siteorigin-panels' ); ?></a>

		</div>

		<div class="so-rows-container">

		</div>

		<div class="so-panels-welcome-message">
			<div class="so-message-wrapper">
				<?php
				printf(
					__( 'Add a %s, %s or %s to get started. Read our %s if you need help.', 'siteorigin-panels' ),
					"<a href='#' class='so-tool-button so-widget-add'>" . __( 'Widget', 'siteorigin-panels' ) . '</a>',
					"<a href='#' class='so-tool-button so-row-add'>" . __( 'Row', 'siteorigin-panels' ) . '</a>',
					"<a href='#' class='so-tool-button so-prebuilt-add'>" . __( 'Prebuilt Layout', 'siteorigin-panels' ) . '</a>',
					"<a href='https://siteorigin.com/page-builder/documentation/' target='_blank' rel='noopener noreferrer'>" . __( 'documentation', 'siteorigin-panels' ) . '</a>'
				);
				?>
			</div>

			<?php if ( SiteOrigin_Panels::display_premium_teaser() ) { ?>
				<div class="so-tip-wrapper">
					<strong><?php _e( 'Pro Tip', 'siteorigin-panels' ); ?>: </strong>
					<?php SiteOrigin_Panels_Admin::display_footer_premium_link(); ?>
				</div>
			<?php } ?>
		</div>

	</div>

</script>

<script type="text/template" id="siteorigin-panels-builder-row">
	<div class="so-row-container ui-draggable so-row-color-{{%= rowColorLabel %}}">

		<div class="so-row-toolbar">
			{{% if( rowLabel ) { %}}
			<h3 class="so-row-label">{{%= rowLabel %}}</h3>
			{{% } %}}
			<span class="so-row-move so-tool-button"><span class="so-panels-icon so-panels-icon-move"></span></span>

			<span class="so-dropdown-wrapper">
				<a class="so-row-settings so-tool-button" tabindex="0"><span class="so-panels-icon so-panels-icon-settings"></span></a>

				<div class="so-dropdown-links-wrapper">
					<ul>
						<li><a class="so-row-settings"><?php _e( 'Edit Row', 'siteorigin-panels' ); ?></a></li>
						<li><a class="so-row-duplicate"><?php _e( 'Duplicate Row', 'siteorigin-panels' ); ?></a></li>
						<li><a class="so-row-delete so-needs-confirm" data-confirm="<?php esc_attr_e( 'Are you sure?', 'siteorigin-panels' ); ?>"><?php _e( 'Delete Row', 'siteorigin-panels' ); ?></a></li>
						<?php
						$row_colors = SiteOrigin_Panels_Admin::get_row_colors();

						if ( ! empty( $row_colors ) ) {
							?>
							<li class="so-row-colors-container">
								<?php
								foreach ( $row_colors as $id => $color ) {
									$name = ! empty( $color['name'] ) ? sanitize_title( $color['name'] ) : $id;
									?>
									<div data-color-label="<?php echo esc_attr( $name ); ?>"
										class="<?php echo esc_attr( 'so-row-color so-row-color-' . $name ); ?>{{% if( rowColorLabel == '<?php echo esc_attr( $name ); ?>' ) print(' so-row-color-selected'); %}}"
									></div>
								<?php } ?>
							</li>
						<?php } ?>
					</ul>
					<div class="so-pointer"></div>
				</div>
			</span>
		</div>

		<div class="so-cells">

		</div>

	</div>
</script>

<script type="text/template" id="siteorigin-panels-builder-cell">
	<div class="cell">
		<div class="resize-handle"></div>
		<div class="cell-wrapper widgets-container">
		</div>
	</div>
</script>

<script type="text/template" id="siteorigin-panels-builder-widget">
	<div class="so-widget ui-draggable" tabindex="0" data-widget-class="{{%- widget_class %}}">
		<div class="so-widget-wrapper">
			<div class="title">
				<h4>{{%= title %}}</h4>
				<span class="actions">
					<a class="widget-edit" tabindex="0">
						<span class="so-panels-icon so-panels-icon-edit-widget" aria-label="<?php echo esc_attr( 'Edit widget', 'siteorigin-panels' ); ?>"></span>
						<span class="so-button-text">
							<?php _e( 'Edit', 'siteorigin-panels' ); ?>
						</span>
					</a>
					<a class="widget-duplicate" tabindex="0">
						<span class="so-panels-icon so-panels-icon-duplicate-widget" aria-label="<?php echo esc_attr( 'Duplicate widget', 'siteorigin-panels' ); ?>"></span>
						<span class="so-button-text">
							<?php _e( 'Duplicate', 'siteorigin-panels' ); ?>
						</span>
					</a>
					<a class="widget-delete" tabindex="0">
						<span class="so-panels-icon so-panels-icon-delete-widget" aria-label="<?php echo esc_attr( 'Delete widget', 'siteorigin-panels' ); ?>"></span>
						<span class="so-button-text">
							<?php _e( 'Delete', 'siteorigin-panels' ); ?>
						</span>
					</a>
				</span>
			</div>
			<small class="description">{{%= description %}}</small>
		</div>
	</div>
</script>

<script type="text/template" id="siteorigin-panels-dialog">
	<div class="so-panels-dialog {{% if(typeof left_sidebar != 'undefined') print('so-panels-dialog-has-left-sidebar '); if(typeof right_sidebar != 'undefined') print('so-panels-dialog-has-right-sidebar '); %}}">

		<div class="so-overlay"></div>

		<div class="so-title-bar {{% if ( dialogIcon ) print( 'so-has-icon' ) %}}">
			<a class="so-show-left-sidebar"><span class="so-dialog-icon"></span></a>
			{{% if ( ! _.isEmpty( dialogIcon ) ) { %}}
				<div class="so-panels-icon so-panels-icon-{{%- dialogIcon %}}"></div>
			{{% } %}}
			<h3 class="so-title{{% if ( editableLabel ) print(' so-title-editable')%}}"
			    {{% if ( editableLabel ) print('contenteditable="true" spellcheck="false" tabIndex="0"')%}}
				>{{%= title %}}</h3>
			<div class="so-title-bar-buttons">
				<a class="so-previous so-nav"><span class="so-dialog-icon"></span></a>
				<a class="so-next so-nav"><span class="so-dialog-icon"></span></a>
				<a class="so-show-right-sidebar"><span class="so-dialog-icon"></span></a>
				<a class="so-close" tabindex="0"><span class="so-dialog-icon"></span></a>
			</div>
		</div>

		<div class="so-sidebar so-left-sidebar">
			{{% if(typeof left_sidebar  != 'undefined') print(left_sidebar ); %}}
		</div>

		<div class="so-sidebar so-right-sidebar">
			{{% if(typeof right_sidebar  != 'undefined') print(right_sidebar ); %}}
		</div>

		<div class="so-content panel-dialog">
			{{%= content %}}
		</div>

		<div class="so-toolbar">
			<div class="so-status">{{% if(typeof status != 'undefined') print(status); %}}</div>
			<div class="so-buttons">
				{{%= buttons %}}
			</div>
		</div>

	</div>
</script>

<script type="text/template" id="siteorigin-panels-dialog-builder">
	<div class="dialog-data">

		<h3 class="title"><?php _e( 'Page Builder', 'siteorigin-panels' ); ?></h3>

		<div class="content">
			<div class="siteorigin-panels-builder">

			</div>
		</div>

		<div class="buttons">
			<input type="button" class="button-primary so-close" tabindex="0" value="<?php esc_attr_e( 'Done', 'siteorigin-panels' ); ?>" />
		</div>

	</div>
</script>


<script type="text/template" id="siteorigin-panels-dialog-tab">
	<li><a href="{{% if(typeof tab != 'undefined') { print ( '#' + tab ); } %}}">{{%= title %}}</a></li>
</script>

<script type="text/template" id="siteorigin-panels-dialog-widgets">
	<div class="dialog-data">

		<h3 class="title"><?php printf( __( 'Add New Widget %s', 'siteorigin-panels' ), '<span class="current-tab-title"></span>' ); ?></h3>

		<div class="left-sidebar">

			<input type="text" class="so-sidebar-search" placeholder="<?php esc_attr_e( 'Search Widgets', 'siteorigin-panels' ); ?>" />

			<ul class="so-sidebar-tabs">
			</ul>

		</div>

		<div class="content">
			<ul class="widget-type-list"></ul>
		</div>

		<div class="buttons">
			<input type="button" class="button-primary so-close" tabindex="0" value="<?php esc_attr_e( 'Close', 'siteorigin-panels' ); ?>" />
		</div>

	</div>
</script>

<script type="text/template" id="siteorigin-panels-dialog-widgets-widget">
	<li class="widget-type">
		<div class="widget-type-wrapper" tabindex="0">
			<h3>{{%= title %}}</h3>
			<small class="description">{{%= description %}}</small>
		</div>
	</li>
</script>

<script type="text/template" id="siteorigin-panels-dialog-widget">
	<div class="dialog-data">

		<h3 class="title"><span class="widget-name"></span></h3>

		<div class="right-sidebar"></div>

		<div class="content">

			<div class="widget-form">
			</div>

		</div>

		<div class="buttons">
			<div class="action-buttons">
				<a class="so-delete" tabindex="0"><?php _e( 'Delete', 'siteorigin-panels' ); ?></a>
				<a class="so-duplicate" tabindex="0"><?php _e( 'Duplicate', 'siteorigin-panels' ); ?></a>
			</div>

			<input type="button" class="button-primary so-close" tabindex="0" value="<?php esc_attr_e( 'Done', 'siteorigin-panels' ); ?>" />
		</div>

	</div>
</script>

<script type="text/template" id="siteorigin-panels-dialog-widget-sidebar-widget">
	<div class="so-widget">
		<h3>{{%= title %}}</h3>
		<small class="description">
			{{%= description %}}
		</small>
	</div>
</script>

<script type="text/template" id="siteorigin-panels-dialog-row">
	<div class="dialog-data">

		<h3 class="title">
			{{%= title %}}
		</h3>

		<div class="right-sidebar"></div>

		<div class="content">

			<div class="row-set-form">
				<?php
				$cells_field = apply_filters( 'siteorigin_panels_row_column_count_input', '<input type="number" min="1" max="12" name="cells" class="so-row-field" value="2" />' );
				$ratios = apply_filters( 'siteorigin_panels_column_ratios', array(
					'Even' => 1,
					'Golden' => 0.61803398,
					'Halves' => 0.5,
					'Thirds' => 0.33333333,
					'Diagon' => 0.41421356,
					'Hecton' => 0.73205080,
					'Hemidiagon' => 0.11803398,
					'Penton' => 0.27201964,
					'Trion' => 0.15470053,
					'Quadriagon' => 0.207,
					'Biauron' => 0.30901699,
					'Bipenton' => 0.46,
				) );
				$ratio_field = '<select name="ratio" class="so-row-field">';

				foreach ( $ratios as $name => $value ) {
					$ratio_field .= '<option value="' . esc_attr( $value ) . '">' . esc_html( $name . ' (' . round( $value, 3 ) . ')' ) . '</option>';
				}
				$ratio_field .= '</select>';

				$direction_field = '<select name="ratio_direction" class="so-row-field">';
				$direction_field .= '<option value="right">' . esc_html__( 'Left to Right', 'siteorigin-panels' ) . '</option>';
				$direction_field .= '<option value="left">' . esc_html__( 'Right to Left', 'siteorigin-panels' ) . '</option>';
				$direction_field .= '</select>';

				printf(
					preg_replace(
						array(
							'/1\{ *(.*?) *\}/',
						),
						array(
							'<strong>$1</strong>',
						),
						__( '1{Set row layout}: %1$s columns with a ratio of %2$s going from %3$s', 'siteorigin-panels' )
					),
					$cells_field,
					$ratio_field,
					$direction_field
				);
				echo '<button class="button-secondary set-row">' . esc_html__( 'Set', 'siteorigin-panels' ) . '</button>';
				?>
			</div>

			<div class="row-preview">

			</div>

		</div>

		<div class="buttons">
			{{% if( dialogType == 'edit' ) { %}}
				<div class="action-buttons">
					<a class="so-delete" tabindex="0"><?php _e( 'Delete', 'siteorigin-panels' ); ?></a>
					<a class="so-duplicate" tabindex="0"><?php _e( 'Duplicate', 'siteorigin-panels' ); ?></a>
				</div>
			{{% } %}}

			{{% if( dialogType == 'create' ) { %}}
				<input type="button" class="button-primary so-insert" tabindex="0" value="<?php esc_attr_e( 'Insert', 'siteorigin-panels' ); ?>" />
			{{% } else { %}}
				<input type="button" class="button-primary so-save" tabindex="0" value="<?php esc_attr_e( 'Done', 'siteorigin-panels' ); ?>" />
			{{% } %}}
		</div>

	</div>
</script>

<script type="text/template" id="siteorigin-panels-dialog-row-cell-preview">
	<div class="preview-cell" style="width: {{%- weight*100 %}}%">
		<div class="preview-cell-in">
			<div class="preview-cell-weight" tabIndex="0">{{% print(Math.round(weight * 1000) / 10) %}}</div>
		</div>
	</div>
</script>

<script type="text/template" id="siteorigin-panels-dialog-prebuilt">
	<div class="dialog-data">

		<h3 class="title"><?php _e( 'Page Builder Layouts', 'siteorigin-panels' ); ?></h3>

		<div class="left-sidebar">

			<input type="text" class="so-sidebar-search" placeholder="<?php esc_attr_e( 'Search', 'siteorigin-panels' ); ?>" />

			<ul class="so-sidebar-tabs">
				<?php
				$tabs = array();
				if ( ! empty( $layouts ) ) {
					$tabs['prebuilt'] = __( 'Prebuilt Layouts', 'siteorigin-panels' );
				}

				$directories = SiteOrigin_Panels_Admin_Layouts::single()->get_directories();

				foreach ( $directories as $id => $directory ) {
					$tabs[ 'directory-' . urlencode( $id ) ] = $directory['title'];
				}
				$tabs['import'] = __( 'Import/Export', 'siteorigin-panels' );

				$post_types = siteorigin_panels_setting( 'post-types' );
				foreach( $post_types as $post_type ) {
					$type = get_post_type_object( $post_type );
					if ( empty( $type ) ) {
						continue;
					}

					$tabs[ 'clone_' . $post_type ] = sprintf( __( 'Clone: %s', 'siteorigin-panels' ), $type->labels->name );
				}

				$tabs = apply_filters( 'siteorigin_panels_layout_tabs', $tabs );
				foreach ( $tabs as $id => $tab ) {
					?>
					<li>
						<a href="#<?php echo esc_html( $id ); ?>"><?php echo esc_html( $tab ); ?></a>
					</li>
					<?php
				}
				?>
			</ul>

		</div>

		<div class="content">
		</div>

		<div class="buttons">
			<span class="so-dropdown-wrapper">
				<input type="button" class="button-primary so-dropdown-button so-import-layout disabled" value="<?php esc_attr_e( 'Insert', 'siteorigin-panels' ); ?>" disabled="disabled"/>

				<div class="so-dropdown-links-wrapper hidden">
					<ul class="so-layout-position">
						<li><a class="so-toolbar-button" data-value="after" tabindex="0"><?php esc_html_e( 'Insert after', 'siteorigin-panels' ); ?></a></li>
						<li><a class="so-toolbar-button" data-value="before" tabindex="0"><?php esc_html_e( 'Insert before', 'siteorigin-panels' ); ?></a></li>
						<li><a class="so-toolbar-button so-needs-confirm" data-value="replace" data-confirm="<?php esc_attr_e( 'Are you sure?', 'siteorigin-panels' ); ?>" tabindex="0"><?php esc_html_e( 'Replace current', 'siteorigin-panels' ); ?></a></li>
					</ul>
				</div>
			</span>
		</div>

	</div>
</script>

<script type="text/template" id="siteorigin-panels-directory-enable">
	<div class="so-enable-prebuilt">
		<?php _e( 'Do you want to browse the Prebuilt Layouts directory?', 'siteorigin-panels' ); ?>
		<button class="button-primary so-panels-enable-directory"><?php _e( 'Enable', 'siteorigin-panels' ); ?></button>
	</div>
</script>

<script type="text/template" id="siteorigin-panels-directory-items">
	<div class="so-directory-items">

		<div class="so-directory-browse">
		</div>

		<div class="so-directory-items-wrapper">
			{{% if(items.length === 0) { %}}
				<div class="so-no-results">
					<?php _e( "Your search didn't return any results", 'siteorigin-panels' ); ?>
				</div>
			{{% } else { %}}
				{{% _.each(items, function(item) { %}}
					<div class="so-directory-item" data-layout-id="{{%- item.id %}}" data-layout-type="{{%- item.type %}}">
						<div class="so-directory-item-wrapper" tabindex="0">
							<div class="so-screenshot" data-src="{{%- item.screenshot %}}">
								<div class="so-panels-loading so-screenshot-wrapper"></div>
							</div>
							<div class="so-description">{{%- item.description %}}</div>

							<div class="so-bottom">
								<h4 class="so-title">{{%= item.title %}}</h4>
								{{% if( item.preview ) { %}}
									<div class="so-buttons">
										<a href="{{%- item.preview %}}" class="button-secondary so-button-preview" target="_blank" rel="noopener noreferrer">Preview</a>
									</div>
								{{% } %}}
							</div>
						</div>
					</div>
				{{% }); %}}
			{{% } %}}
		</div>

		<div class="clear"></div>

		<div class="so-directory-pages">
			<a class="so-previous button-secondary" data-direction="prev"><?php _e( 'Previous', 'siteorigin-panels' ); ?></a>
			<a class="so-next button-secondary" data-direction="next"><?php _e( 'Next', 'siteorigin-panels' ); ?></a>
		</div>
	</div>
</script>

<script type="text/template" id="siteorigin-panels-dialog-prebuilt-importexport">
	<div class="import-export">
		<div class="import-upload-ui hide-if-no-js">
			<div class="drag-upload-area">

				<h2 class="drag-drop-message"><?php _e( 'Drop import file here', 'siteorigin-panels' ); ?></h2>
				<p class="drag-drop-message"><?php _e( 'Or', 'siteorigin-panels' ); ?></p>

				<p class="drag-drop-buttons">
					<input type="button" value="<?php esc_attr_e( 'Select Import File', 'siteorigin-panels' ); ?>" class="file-browse-button button" />
				</p>

				<p class="drag-drop-message js-so-selected-file"></p>

				<div class="progress-bar">
					<div class="progress-percent"></div>
				</div>
			</div>
		</div>

		<div class="export-file-ui">
			<iframe id="siteorigin-panels-export-iframe" style="display: none;" name="siteorigin-panels-export-iframe"></iframe>
			<form action="<?php echo esc_url( admin_url( 'admin-ajax.php?action=so_panels_export_layout' ) ); ?>" target="siteorigin-panels-export-iframe" class="so-export" method="post">
				<input type="submit" value="<?php esc_attr_e( 'Download Layout', 'siteorigin-panels' ); ?>" class="button-primary" />
				<input type="hidden" name="panels_export_data" value="" />
				<?php wp_nonce_field( 'panels_action', '_panelsnonce' ); ?>
			</form>
		</div>
	</div>
</script>

<script type="text/template" id="siteorigin-panels-dialog-history">
	<div class="dialog-data">

		<h3 class="title"><?php _e( 'Page Builder Change History', 'siteorigin-panels' ); ?></h3>

		<div class="left-sidebar">
			<div class="history-entries"></div>
		</div>

		<div class="content">
			<form method="post" action="" target="siteorigin-panels-history-iframe-{{%= cid %}}" class="history-form">
				<input type="hidden" name="live_editor_panels_data" value="">
				<input type="hidden" name="live_editor_post_ID" value="">
			</form>
			<iframe class="siteorigin-panels-history-iframe" name="siteorigin-panels-history-iframe-{{%= cid %}}" src=""></iframe>
		</div>

		<div class="buttons">
			<input type="button" class="button-primary so-restore" value="<?php esc_attr_e( 'Restore Version', 'siteorigin-panels' ); ?>" />
		</div>

	</div>
</script>

<script type="text/template" id="siteorigin-panels-dialog-history-entry">
	<div class="history-entry" tabindex="0">
		<h3>{{%= title %}}{{% if( count > 1 ) { %}} <span class="count">({{%= count %}})</span>{{% } %}}</h3>
		<div class="timesince"></div>
	</div>
</script>

<script type="text/template" id="siteorigin-panels-live-editor">
	<div class="so-panels-live-editor">

		<div class="live-editor-collapse">
			<div class="collapse-icon"></div>
		</div>

		<div class="so-sidebar-tools">
			<button
				class="live-editor-save button-primary"
				data-save="<?php esc_html_e( 'Save Draft', 'siteorigin-panels' ); ?>"
				data-update="<?php esc_html_e( 'Update', 'siteorigin-panels' ); ?>"
			><?php esc_html_e( 'Update', 'siteorigin-panels' ); ?></button>
			<button class="live-editor-close button-secondary"><?php esc_html_e( 'Close', 'siteorigin-panels' ); ?></button>

			<a class="live-editor-mode live-editor-desktop so-active" title="<?php esc_attr_e( 'Toggle desktop mode', 'siteorigin-panels' ); ?>" data-mode="desktop" data-width="100%"  tabindex="0">
				<span class="dashicons dashicons-desktop"></span>
			</a>
			<a class="live-editor-mode live-editor-tablet" title="<?php esc_attr_e( 'Toggle tablet mode', 'siteorigin-panels' ); ?>" data-mode="tablet" data-width="720px" tabindex="0">
				<span class="dashicons dashicons-tablet"></span>
			</a>
			<a class="live-editor-mode live-editor-mobile" title="<?php esc_attr_e( 'Toggle mobile mode', 'siteorigin-panels' ); ?>" data-mode="mobile" data-width="320px" tabindex="0">
				<span class="dashicons dashicons-smartphone"></span>
			</a>

		</div>

		<div class="so-sidebar">
			<div class="so-live-editor-builder"></div>
		</div>

		<div class="so-preview"></div>

		<div class="so-preview-overlay">
			<div class="so-loading-container"><div class="so-loading-bar"></div></div>
		</div>

	</div>
</script>

<script type="text/template" id="siteorigin-panels-context-menu">
	<div class="so-panels-contextual-menu"></div>
</script>

<script type="text/template" id="siteorigin-panels-context-menu-section">
	<div class="so-section">
		<h5>{{%- settings.sectionTitle %}}</h5>

		{{% if( settings.search ) { %}}
			<div class="so-search-wrapper">
				<input type="text" placeholder="{{%- settings.searchPlaceholder %}}" />
			</div>
		{{% } %}}
		<ul class="so-items">
			{{% for( var k in items ) { %}}
				<li data-key="{{%- k %}}" class="so-item {{% if( !_.isUndefined( items[k].confirm ) && items[k].confirm ) { print( 'so-confirm' ); } %}}">{{%= items[k][settings.titleKey] %}}</li>
			{{% } %}}
		</ul>
		{{% if( settings.search ) { %}}
		<div class="so-no-results">
			<?php _e( 'No Results', 'siteorigin-panels' ); ?>
		</div>
		{{% } %}}
	</div>
</script>

<script type="text/template" id="siteorigin-panels-add-layout-block-button">
	<div class="siteorigin-panels-add-layout-block wp-block">
		<button class="components-button is-button is-primary">
			<span class="siteorigin-panels-block-icon white"></span>
			<?php _e( 'Add SiteOrigin Layout Block', 'siteorigin-panels' ); ?>
		</button>
	</div>
</script>
