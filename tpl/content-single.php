<?php
/**
 * Template part for displaying posts.
 *
 * @link https://codex.wordpress.org/Template_Hierarchy
 *
 * @package Sakurairo
 */
$post_id = get_the_ID();
?>

<?php
$post = get_post();
if (iro_opt('article_auto_toc', 'true') && check_title_tags($post->post_content)) {
	echo '<div class="has-toc have-toc"></div>';
}
?>

<article id="post-<?php echo esc_attr($post_id); ?>" <?php post_class(); ?>>
	<?php if (should_show_title()) { 
		get_template_part('tpl/single-entry-header');
	} ?>
	<div class="entry-content">
		<?php the_content('', true); ?>
		<?php
			wp_link_pages(array(
				'before' => '<div class="page-links">' . esc_html__('Pages:', 'ondemand'),
				'after'  => '</div>',
			));
		?>
	</div><!-- .entry-content -->
	<?php get_template_part('tpl/section-article-function'); ?>
</article><!-- #post-## -->
