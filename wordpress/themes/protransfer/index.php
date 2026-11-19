<?php
get_header();
?>
<main class="site-content">
  <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
    <article <?php post_class(); ?>>
      <h1><?php the_title(); ?></h1>
      <?php the_content(); ?>
    </article>
  <?php endwhile; else : ?>
    <p>Ничего не найдено.</p>
  <?php endif; ?>
</main>
<?php
get_footer();
