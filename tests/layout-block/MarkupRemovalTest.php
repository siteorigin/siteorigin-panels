<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SiteOrigin_Panels_Markup_Removal as Removal;

/**
 * Unit tests for SiteOrigin_Panels_Markup_Removal: the element and attribute
 * counts, the attribution rules and the action (#1454).
 */
class MarkupRemovalTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once dirname( dirname( __DIR__ ) ) . '/inc/markup-removal.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Before and after strings, and whether the change is a removal.
	 */
	public static function removal_cases() {
		return array(
			'P1 iframe stripped'             => array( '<p>a</p><iframe src=x></iframe>', '<p>a</p>', true ),
			'P2 attribute stripped'          => array( '<a href=x onclick=y>l</a>', '<a href=x>l</a>', true ),
			'P3 one of two iframes'          => array( '<iframe src=a></iframe><iframe src=b></iframe>', '<iframe src=a></iframe>', true ),
			'P4 case folded'                 => array( '<IFRAME SRC=x></IFRAME>', '', true ),
			'P7 quoted gt'                   => array( '<a title="x>y" onclick=z>l</a>', '<a title="x>y">l</a>', true ),
			'P8 single-quoted gt'            => array( "<a title='x>y' onclick=z>l</a>", "<a title='x>y'>l</a>", true ),
			'P9 self-closing'                => array( '<img src=x onerror=y/>', '<img src=x/>', true ),
			'P10 script element'             => array( '<script>a</script>b', 'b', true ),
			'P11 quoted spaces'              => array( '<a title="a b onclick" onclick=x>l</a>', '<a title="a b onclick">l</a>', true ),
			'P12 scan resumes after end tag' => array( '<textarea>t</textarea><iframe src=x></iframe>', '<textarea>t</textarea>', true ),
			'P13 scan resumes after comment' => array( '<!-- c --><iframe src=x></iframe>', '<!-- c -->', true ),
			'N1 ampersand'                   => array( 'a & b', 'a &amp; b', false ),
			'N2 tag closed'                  => array( '<p>a', '<p>a</p>', false ),
			'N3 paragraphs added'            => array( "a\n\nb", '<p>a</p><p>b</p>', false ),
			'N4 value only'                  => array( '<a href="javascript:x">l</a>', '<a href="x">l</a>', false ),
			'N7 escaped text'                => array( '&lt;iframe&gt;', '&lt;iframe&gt;', false ),
			'N8 br and attribute order'      => array( '<br><a b=1 c=2>l</a>', '<br /><a c=2 b=1>l</a>', false ),
			'N9 RCDATA text'                 => array( '<textarea><iframe></textarea>', '<textarea></textarea>', false ),
			'N10 not an end tag'             => array( '<textarea></textareax><iframe></textarea>', '<textarea></textarea>', false ),
			'N11 comment text'               => array( '<p>a</p><!-- <iframe src=x> -->', '<p>a</p>', false ),
		);
	}

	#[DataProvider( 'removal_cases' )]
	public function test_lost_reports_removals_and_ignores_harmless_changes( $before, $after, $expected ) {
		$this->assertSame( $expected, array() !== Removal::losses( Removal::inventory( $before ), Removal::inventory( $after ) ) );
	}

	public function test_inventory_counts_elements_and_attributes() {
		$this->assertSame(
			array(
				't:a'       => 1,
				'a:a@title' => 1,
				'a:a@href'  => 1,
				't:img'     => 2,
				'a:img@src' => 2,
			),
			Removal::inventory( '<A TITLE="x > y" href=\'/\'>l</A><img src=1><img src="2"/>' )
		);
	}

	public function test_inventory_skips_panels_info_and_non_strings() {
		$widget = array(
			'text'        => '<b>x</b>',
			'count'       => 3,
			'panels_info' => array( 'label' => '<iframe src=x></iframe>' ),
			'nested'      => array( 'panels_info' => array( 'label' => '<script></script>' ), 'html' => '<i>y</i>' ),
		);

		$this->assertSame( array( 't:b' => 1, 't:i' => 1 ), Removal::inventory( $widget ) );
	}

	public function test_n5_n6_timestamp_and_label_changes_do_not_report() {
		$before = array( array( 0 => $this->widget( 'w1', '<b>x</b>', array( 'label' => '<b>L</b>' ) ) ) );
		$after  = $before;
		$after[0][0]['_sow_form_timestamp'] = 123;
		$after[0][0]['panels_info']['label'] = 'L';

		$this->assertSame( array(), Removal::attribute( $before, $after, 'page_builder' ) );
	}

	public function test_attribute_names_the_widget_that_lost_markup() {
		$before = array( array( 0 => $this->widget( 'w1', '<p>a</p><iframe src=x></iframe>' ), 1 => $this->widget( 'w2', 'Tom & Jerry' ) ) );
		$after  = array( array( 0 => $this->widget( 'w1', '<p>a</p>' ), 1 => $this->widget( 'w2', 'Tom &amp; Jerry' ) ) );

		$this->assertSame(
			array( $this->finding( 3, 0, 'w1', 'WP_Widget_Custom_HTML', 'page_builder' ) ),
			Removal::attribute( $before, $after, 'page_builder', array( 0 => 3 ) )
		);
	}

	public function test_p5_nested_layout_widget_is_reported_under_the_outer_widget() {
		$outer = $this->widget( 'outer', '' );
		$outer['panels_data'] = array( 'widgets' => array( $this->widget( 'inner', '<iframe src=x></iframe>' ) ) );
		$stripped = $outer;
		$stripped['panels_data']['widgets'][0]['content'] = '';

		$findings = Removal::attribute( array( array( 0 => $outer ) ), array( array( 0 => $stripped ) ), 'page_builder' );

		$this->assertCount( 1, $findings );
		$this->assertSame( 'outer', $findings[0]['widget_id'] );
	}

	public function test_p6_dropped_key_holding_markup_reports() {
		$before = $this->widget( 'w1', 'ok' );
		$before['undeclared'] = '<iframe src=x></iframe>';

		$findings = Removal::attribute( array( array( 0 => $before ) ), array( array( 0 => $this->widget( 'w1', 'ok' ) ) ), 'page_builder' );

		$this->assertSame( 'w1', $findings[0]['widget_id'] );
	}

	public function test_two_widgets_that_each_lose_their_iframe_are_both_named() {
		$before = array( array( 0 => $this->widget( 'a', '<iframe src=1></iframe>' ), 1 => $this->widget( 'b', '<iframe src=2></iframe>' ) ) );
		$after  = array( array( 0 => $this->widget( 'a', '' ), 1 => $this->widget( 'b', '' ) ) );

		$this->assertSame(
			array( 'a', 'b' ),
			array_column( Removal::attribute( $before, $after, 'page_builder' ), 'widget_id' )
		);
	}

	public function test_markup_moved_between_widgets_without_loss_does_not_report() {
		$before = array( array( 0 => $this->widget( 'a', '<iframe src=1></iframe>' ), 1 => $this->widget( 'b', 'x' ) ) );
		$after  = array( array( 0 => $this->widget( 'a', '' ), 1 => $this->widget( 'b', 'x<iframe src=1></iframe>' ) ) );

		$this->assertSame( array(), Removal::attribute( $before, $after, 'post_save_filters' ) );
	}

	public function test_move_plus_removal_does_not_name_the_wrong_widget() {
		// B's iframe moves to A, and A's own iframe is removed. A keeps one
		// iframe, so naming B would be wrong; the finding is unattributed.
		$before = array( array( 0 => $this->widget( 'a', '<iframe src=a></iframe>' ), 1 => $this->widget( 'b', '<iframe src=b></iframe>' ) ) );
		$after  = array( array( 0 => $this->widget( 'a', '<iframe src=b></iframe>' ), 1 => $this->widget( 'b', '' ) ) );

		$this->assertSame(
			array( $this->finding( null, null, null, null, 'post_save_filters' ) ),
			Removal::attribute( $before, $after, 'post_save_filters' )
		);
	}

	public function test_a_widget_whose_only_change_is_metadata_does_not_block_naming() {
		// process_raw_widgets() drops panels_info.raw from every widget, and a
		// widget's update() can add a timestamp. B keeps its own iframe as it was.
		$b_before = $this->widget( 'b', '<iframe src=b></iframe>', array( 'raw' => true ) );
		$b_after  = $this->widget( 'b', '<iframe src=b></iframe>' );
		$b_after['_sow_form_timestamp'] = 123;

		$before = array( array( 0 => $this->widget( 'a', '<iframe src=a></iframe>', array( 'raw' => true ) ), 1 => $b_before ) );
		$after  = array( array( 0 => $this->widget( 'a', '' ), 1 => $b_after ) );

		$this->assertSame(
			array( $this->finding( 0, 0, 'a', 'WP_Widget_Custom_HTML', 'page_builder' ) ),
			Removal::attribute( $before, $after, 'page_builder' )
		);
	}

	public function test_a_legacy_widget_with_info_is_named() {
		// process_raw_widgets() accepts an older widget whose metadata is under
		// `info` and stores it under panels_info.
		$before = array( 'content' => '<iframe src=x></iframe>', 'info' => array( 'class' => 'WP_Widget_Custom_HTML', 'widget_id' => 'legacy' ) );
		$after  = $this->widget( 'legacy', '' );

		$this->assertSame(
			array( $this->finding( 0, 0, 'legacy', 'WP_Widget_Custom_HTML', 'page_builder' ) ),
			Removal::attribute( array( array( 0 => $before ) ), array( array( 0 => $after ) ), 'page_builder' )
		);
	}

	public function test_a_string_field_named_info_is_content() {
		$before = $this->widget( 'w1', 'plain' );
		$before['info'] = '<iframe src=x></iframe>';
		$after = $this->widget( 'w1', 'plain' );
		$after['info'] = '';

		$this->assertSame( 'w1', Removal::attribute( array( array( 0 => $before ) ), array( array( 0 => $after ) ), 'page_builder' )[0]['widget_id'] );
	}

	public function test_markup_that_may_have_moved_into_another_widgets_nested_info_is_not_attributed() {
		// A loses two iframes; one may have moved into B's settings.info. The
		// total falls by one, but naming A would be a guess.
		$a_before = $this->widget( 'a', '<iframe src=1></iframe><iframe src=2></iframe>' );
		$b_before = $this->widget( 'b', 'plain' );
		$b_before['settings'] = array( 'info' => array( 'embed' => '' ) );
		$b_after = $this->widget( 'b', 'plain' );
		$b_after['settings'] = array( 'info' => array( 'embed' => '<iframe src=2></iframe>' ) );

		$this->assertSame(
			array( $this->finding( null, null, null, null, 'page_builder' ) ),
			Removal::attribute( array( array( 0 => $a_before, 1 => $b_before ) ), array( array( 0 => $this->widget( 'a', '' ), 1 => $b_after ) ), 'page_builder' )
		);
	}

	public function test_a_setting_shaped_like_a_layout_is_content() {
		// An ordinary widget setting with a panels_data-like shape is counted
		// like any other content, info keys included.
		$before = $this->widget( 'w1', 'plain' );
		$before['settings'] = array( 'panels_data' => array( 'widgets' => array( array( 'info' => array( 'embed' => '<iframe src=x></iframe>' ) ) ) ) );
		$after = $this->widget( 'w1', 'plain' );
		$after['settings'] = array( 'panels_data' => array( 'widgets' => array( array( 'info' => array( 'embed' => '' ) ) ) ) );

		$this->assertSame( 'w1', Removal::attribute( array( array( 0 => $before ) ), array( array( 0 => $after ) ), 'page_builder' )[0]['widget_id'] );
	}

	public function test_legacy_info_moved_to_panels_info_by_the_save_does_not_report() {
		$before = array( 'content' => 'plain', 'info' => array( 'class' => 'WP_Widget_Custom_HTML', 'widget_id' => 'legacy', 'extra' => '<b>x</b>' ) );
		$after  = $this->widget( 'legacy', 'plain', array( 'extra' => '<b>x</b>' ) );

		$this->assertSame( array(), Removal::attribute( array( array( 0 => $before ) ), array( array( 0 => $after ) ), 'page_builder' ) );
	}

	public function test_an_info_array_the_save_drops_beside_panels_info_reports() {
		// With panels_info present, the save drops info outright, so markup in it is removed.
		$before = $this->widget( 'w1', 'plain' );
		$before['info'] = array( 'note' => '<iframe src=x></iframe>' );

		$this->assertSame( 'w1', Removal::attribute( array( array( 0 => $before ) ), array( array( 0 => $this->widget( 'w1', 'plain' ) ) ), 'page_builder' )[0]['widget_id'] );
	}

	public function test_identified_and_unidentified_losses_give_a_named_and_a_null_finding() {
		$unidentified = $this->widget( '', '<iframe src=x></iframe>' );
		$before = array( array( 0 => $this->widget( 'w1', '<iframe src=x></iframe>' ), 1 => $unidentified ) );
		$after  = array( array( 0 => $this->widget( 'w1', '' ), 1 => $this->widget( '', '' ) ) );

		$this->assertSame(
			array( 'w1', null ),
			array_column( Removal::attribute( $before, $after, 'page_builder' ), 'widget_id' )
		);
	}

	public function test_an_unidentified_loss_of_element_and_attribute_gives_one_null_finding() {
		$before = array( array( 0 => $this->widget( '', '<iframe src=x></iframe>' ) ) );
		$after  = array( array( 0 => $this->widget( '', '' ) ) );

		$this->assertCount( 1, Removal::attribute( $before, $after, 'page_builder' ) );
	}

	public function test_duplicate_widget_ids_are_never_named() {
		$before = array( array( 0 => $this->widget( 'same', '<iframe src=1></iframe>' ), 1 => $this->widget( 'same', 'x' ) ) );
		$after  = array( array( 0 => $this->widget( 'same', '' ), 1 => $this->widget( 'same', 'x' ) ) );

		$this->assertSame(
			array( $this->finding( null, null, null, null, 'page_builder' ) ),
			Removal::attribute( $before, $after, 'page_builder' )
		);
	}

	public function test_a_swap_of_keys_names_the_widget_by_its_id_at_its_new_key() {
		$before = array( array( 0 => $this->widget( 'a', '<iframe src=1></iframe>' ), 1 => $this->widget( 'b', 'plain' ) ) );
		$after  = array( array( 0 => $this->widget( 'b', 'plain' ), 1 => $this->widget( 'a', '' ) ) );

		$findings = Removal::attribute( $before, $after, 'page_builder' );

		$this->assertSame( array( $this->finding( 0, 1, 'a', 'WP_Widget_Custom_HTML', 'page_builder' ) ), $findings );
	}

	public function test_reordered_blocks_name_the_after_side_block() {
		$first  = array( 0 => $this->widget( 'w1', '<iframe src=1></iframe>' ) );
		$second = array( 0 => $this->widget( 'w2', 'text' ) );
		$after  = array( array( 0 => $this->widget( 'w2', 'text' ) ), array( 0 => $this->widget( 'w1', '' ) ) );

		$findings = Removal::attribute( array( $first, $second ), $after, 'post_save_filters' );

		$this->assertSame( 1, $findings[0]['block'] );
		$this->assertSame( 'w1', $findings[0]['widget_id'] );
	}

	public function test_a_removed_block_reports_one_unattributed_finding() {
		$before = array( array( 0 => $this->widget( 'w1', '<iframe src=1></iframe>' ) ) );

		$this->assertSame(
			array( $this->finding( null, null, null, null, 'post_save_filters' ) ),
			Removal::attribute( $before, array(), 'post_save_filters' )
		);
	}

	public function test_identifiers_are_normalised() {
		$widget = $this->widget( 'w<1>', '<iframe src=1></iframe>' );
		$widget['panels_info']['class'] = 'My\\Widget<script>';
		$after = $widget;
		$after['content'] = '';

		$findings = Removal::attribute( array( array( '<b>x' => $widget ) ), array( array( '<b>x' => $after ) ), 'page_builder' );

		$this->assertNull( $findings[0]['widget'] );
		$this->assertSame( 'w1', $findings[0]['widget_id'] );
		$this->assertSame( 'My\\Widgetscript', $findings[0]['class'] );
	}

	public function test_block_index_is_passed_through_for_the_ability() {
		$findings = Removal::attribute(
			array( array( 0 => $this->widget( 'w1', '<iframe src=1></iframe>' ) ) ),
			array( array( 0 => $this->widget( 'w1', '' ) ) ),
			'page_builder',
			array( 0 => null ),
			2
		);

		$this->assertNull( $findings[0]['block'] );
		$this->assertSame( 2, $findings[0]['block_index'] );
	}

	public function test_fire_does_nothing_without_findings() {
		Actions\expectDone( Removal::ACTION )->never();

		Removal::fire( 5, 'rest_pre_insert', array() );
	}

	public function test_fire_passes_one_array() {
		$finding = $this->finding( 0, 0, 'w1', 'WP_Widget_Custom_HTML', 'page_builder' );
		Actions\expectDone( Removal::ACTION )->once()->with(
			array(
				'post_id' => 5,
				'context' => 'rest_pre_insert',
				'widgets' => array( $finding ),
			)
		);

		Removal::fire( '5', 'rest_pre_insert', array( 3 => $finding ) );
	}

	public function test_a_throwing_listener_propagates_like_any_action() {
		Actions\expectDone( Removal::ACTION )->once()->whenHappen(
			function () {
				throw new \RuntimeException( 'listener failed' );
			}
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'listener failed' );

		Removal::fire( 5, 'ability', array( $this->finding( 0, 0, 'w1', null, 'page_builder' ) ) );
	}

	public function test_an_info_array_inside_widget_content_is_content() {
		$before = $this->widget( 'w1', 'plain' );
		$before['settings'] = array( 'info' => array( 'embed' => '<iframe src=x></iframe>' ) );
		$after = $this->widget( 'w1', 'plain' );
		$after['settings'] = array( 'info' => array( 'embed' => '' ) );

		$this->assertSame( 'w1', Removal::attribute( array( array( 0 => $before ) ), array( array( 0 => $after ) ), 'page_builder' )[0]['widget_id'] );
	}

	/**
	 * A Custom HTML widget with a widget_id.
	 */
	private function widget( $id, $content, $info = array() ) {
		return array(
			'title'       => '',
			'content'     => $content,
			'panels_info' => array_merge(
				array( 'class' => 'WP_Widget_Custom_HTML', 'grid' => 0, 'cell' => 0, 'id' => 0, 'widget_id' => $id ),
				$info
			),
		);
	}

	/**
	 * One finding in the reported shape.
	 */
	private function finding( $block, $widget, $widget_id, $class, $source, $block_index = null ) {
		return array(
			'block'       => $block,
			'block_index' => $block_index,
			'widget'      => $widget,
			'widget_id'   => $widget_id,
			'class'       => $class,
			'source'      => $source,
		);
	}
}
