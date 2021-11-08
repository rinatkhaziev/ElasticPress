<?php
/**
 * Template for ElasticPress sync page
 *
 * @since  3.6.0
 * @package elasticpress
 */

use ElasticPress\Elasticsearch as Elasticsearch;

require_once __DIR__ . '/header.php';
?>

<div class="wrap">
	<h1><?php esc_html_e( 'Sync Settings', 'elasticpress' ); ?></h1>

	<textarea id="ep-sync-output" cols="30" rows="10" class="widefat" readonly></textarea>

	<div class="card sync-box">
		<div class="sync-box__description">
			<p class="sync-box__description_text">
				<?php esc_html_e( 'If you are missing data in your search results or have recently added custom content types to your site, you should run a sync to reflect these changes.', 'elasticpress' ); ?>
			</p>

			<!-- <p class="submit"><button class="button button-primary button-large start-sync"><?php esc_html_e( 'Let&rsquo;s go!', 'elasticpress' ); ?></button></p> -->
			<div class="last-sync">
				<p class="last-sync__title">
					<?php echo esc_html__( 'Last sync:', 'elasticpress' ); ?>
				</p>
				<img width="16" src="<?php echo esc_url( plugins_url( '/images/thumbsup.svg', dirname( __DIR__ ) ) ); ?>" />
				<?php
					echo sprintf( __( '<span class="last-sync__status">Sync success on</span><span class="last-sync__date">%s</span>' ), 'Wed, September 29, 2021 14:13' );
				?>
			</div>
			<p>
				<a href="#">
					<?php echo esc_html__( 'Show log', 'elasticpress' ); ?>
				</a>
			</p>

		</div>
		<div class="sync-box__action">
			<button type="button" class="button button-primary sync-box__button start-sync">
				<span class="dashicons dashicons-update-alt sync-box__icon-button"></span> <?php echo esc_html__( 'Sync Now', 'elasticpress' ); ?>
			</button>
			<a class="sync-box__learn-more-link" href="#">
				<?php echo esc_html__( 'Learn more', 'elasticpress' ); ?>
			</a>
		</div>
	</div>

	<div class="delete-data-and-sync">
		<h2 class="delete-data-and-sync__title">
			<?php esc_html_e( 'Delete All Data and Sync', 'elasticpress' ); ?>
		</h2>
		<div class="card sync-box">
			<div class="sync-box__description">
				<p class="sync-box__description_text">
					<?php esc_html_e( 'If you are still having issues with your search results, you may need to do a completely fresh sync.', 'elasticpress' ); ?>
				</p>

				<button type="button" class="button button-large delete-data-and-sync__button">
					<?php echo esc_html__( 'Delete all Data and Start a Fresh Sync ', 'elasticpress' ); ?>
				</button>

				<div class="delete-data-and-sync__warning">
					<img
						class="delete-data-and-sync__warning-icon"
						width="19"
						src="<?php echo esc_url( plugins_url( '/images/warning.svg', dirname( __DIR__ ) ) ); ?>"
					/>
					<p>
						<?php esc_html_e( 'All indexed data on ElasticPress will be deleted without affecting anything on your WordPress website. This may take a few hours depending on the amount of content that needs to be synced and indexed. While this is happenening, searches will use the default WordPress results', 'elasticpress' ); ?>
					</p>
				</div>
			</div>
		</div>
	</div>
</div>
