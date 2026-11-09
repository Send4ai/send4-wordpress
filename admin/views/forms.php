<?php
/**
 * Send4 → Forms & Triggers.
 *
 * @package Send4
 *
 * @var Send4_Admin         $admin
 * @var array|WP_Error|null $handshake
 * @var array               $forms
 * @var array               $adapters
 */

defined( 'ABSPATH' ) || exit;

$send4_notice    = $admin->current_notice();
$send4_state     = Send4_Admin::connection_state( $handshake );
$send4_connected = 'connected' === $send4_state;

$send4_bar_title   = __( 'Forms & Triggers', 'send4' );
$send4_bar_state   = $send4_state;
$send4_bar_account = $send4_connected && isset( $handshake['client']['name'] ) ? (string) $handshake['client']['name'] : '';
?>
<div class="send4-app">
	<?php require SEND4_PLUGIN_DIR . 'admin/views/partials/app-bar.php'; ?>

	<div class="send4-body">
		<?php if ( $send4_notice ) : ?>
			<div class="send4-notice send4-notice--<?php echo esc_attr( $send4_notice['type'] ); ?>" role="<?php echo 'error' === $send4_notice['type'] ? 'alert' : 'status'; ?>">
				<p><?php echo esc_html( $send4_notice['message'] ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( 'disconnected' === $send4_state ) : ?>
			<div class="send4-notice send4-notice--warn">
				<p>
					<?php esc_html_e( 'You can map fields now, but nothing is sent until the site is connected.', 'send4' ); ?>
					<a href="<?php echo esc_url( $admin->page_url( array(), Send4_Admin::CONNECTION_SLUG ) ); ?>"><?php esc_html_e( 'Connect Send4', 'send4' ); ?></a>
				</p>
			</div>
		<?php elseif ( 'unreachable' === $send4_state || 'rejected' === $send4_state ) : ?>
			<div class="send4-notice <?php echo 'rejected' === $send4_state ? 'send4-notice--error' : 'send4-notice--warn'; ?>">
				<p>
					<strong><?php echo esc_html( Send4_Admin::outage_message( $send4_state ) ); ?></strong>
					<?php echo esc_html( $handshake->get_error_message() ); ?>
					<a href="<?php echo esc_url( $admin->page_url( array(), Send4_Admin::CONNECTION_SLUG ) ); ?>"><?php esc_html_e( 'Check the connection', 'send4' ); ?></a>
				</p>
			</div>
		<?php endif; ?>

		<div class="send4-pagehead">
			<div>
				<h1><?php esc_html_e( 'Forms & triggers', 'send4' ); ?></h1>
				<p class="send4-sub"><?php esc_html_e( 'Choose which forms hand their submissions to Send4, and which field holds the phone number.', 'send4' ); ?></p>
			</div>
			<?php if ( $send4_connected ) : ?>
				<?php
				// Forms are created, renamed and deleted in the CMS with no hook
				// this plugin sees, so the automatic syncs cannot cover that on
				// their own — this is the button for "I just added a form".
				$send4_synced_at = Send4_Forms_Sync::last_synced_at();
				?>
				<div class="send4-pagehead__actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php $admin->nonce_field( 'send4_sync_forms' ); ?>
						<input type="hidden" name="action" value="send4_sync_forms" />
						<button type="submit" class="send4-btn send4-btn--sm"><?php esc_html_e( 'Sync forms to Send4', 'send4' ); ?></button>
					</form>
					<p class="send4-xs send4-muted">
						<?php
						if ( $send4_synced_at > 0 ) {
							printf(
								/* translators: %s: human readable time difference, e.g. "5 mins". */
								esc_html__( 'Last sent %s ago', 'send4' ),
								esc_html( human_time_diff( $send4_synced_at ) )
							);
						} else {
							esc_html_e( 'Not sent yet', 'send4' );
						}
						?>
					</p>
				</div>
			<?php endif; ?>
		</div>

		<div class="send4-card send4-card--pad send4-adapters">
			<h2 class="send4-eyebrow"><?php esc_html_e( 'Form plugins', 'send4' ); ?></h2>
			<ul>
				<?php foreach ( $adapters as $send4_adapter ) : ?>
					<li>
						<span class="send4-strong"><?php echo esc_html( $send4_adapter->name() ); ?></span>
						<?php if ( $send4_adapter->is_available() ) : ?>
							<span class="send4-badge send4-badge--ok"><?php esc_html_e( 'Active', 'send4' ); ?></span>
						<?php else : ?>
							<span class="send4-badge send4-badge--neutral"><?php esc_html_e( 'Not installed', 'send4' ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<?php if ( empty( $forms ) ) : ?>
			<div class="send4-card send4-card--pad send4-empty">
				<p class="send4-strong"><?php esc_html_e( 'No forms found yet.', 'send4' ); ?></p>
				<p class="send4-muted"><?php esc_html_e( 'Create a form in any of the plugins above and it will show up here.', 'send4' ); ?></p>
			</div>
		<?php else : ?>
			<div class="send4-card send4-card--flush">
				<div class="send4-table-wrap">
					<table class="send4-table send4-table--rows">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Form', 'send4' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Form key', 'send4' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Sending to Send4', 'send4' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Last submission', 'send4' ); ?></th>
								<th scope="col" class="send4-table__actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'send4' ); ?></span></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $forms as $send4_form_descriptor ) : ?>
							<?php
							$send4_key    = $send4_form_descriptor['key'];
							$send4_state  = Send4_Settings::form( $send4_key );
							$send4_active = Send4_Settings::form_is_active( $send4_key );
							$send4_bound  = $admin->flows_for( $handshake, $send4_key );
							$send4_name   = '' !== $send4_form_descriptor['title'] ? $send4_form_descriptor['title'] : __( '(untitled)', 'send4' );
							// Every row's buttons read the same out of context, so a
							// screen reader listing them is told which form each is for.
							$send4_which = sprintf(
								/* translators: %s: form title. */
								__( '“%s”', 'send4' ),
								$send4_name
							);
							?>
							<tr>
								<td>
									<span class="send4-strong"><?php echo esc_html( $send4_name ); ?></span>
									<p class="send4-xs send4-muted"><?php echo esc_html( $send4_form_descriptor['adapter_name'] ); ?></p>
								</td>
								<td><code><?php echo esc_html( $send4_key ); ?></code></td>
								<td>
									<?php if ( $send4_active ) : ?>
										<span class="send4-badge send4-badge--ok"><?php esc_html_e( 'On', 'send4' ); ?></span>
										<?php if ( Send4_Settings::form_validates_phone( $send4_key ) ) : ?>
											<span class="send4-badge send4-badge--info"><?php esc_html_e( 'Checks phone', 'send4' ); ?></span>
										<?php endif; ?>
										<?php if ( $send4_connected && empty( $send4_bound ) ) : ?>
											<p class="send4-xs send4-warning"><?php esc_html_e( 'No flow is listening for this form.', 'send4' ); ?></p>
										<?php endif; ?>
									<?php else : ?>
										<span class="send4-badge send4-badge--neutral"><?php esc_html_e( 'Off', 'send4' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( '' === $send4_state['last_status'] ) : ?>
										<span class="send4-muted send4-xs"><?php esc_html_e( 'No submissions yet', 'send4' ); ?></span>
									<?php else : ?>
										<span class="send4-badge send4-badge--<?php echo esc_attr( $admin->status_tone( $send4_state['last_status'] ) ); ?>">
											<?php echo esc_html( $admin->status_label( $send4_state['last_status'] ) ); ?>
										</span>
										<p class="send4-xs send4-muted"><?php echo esc_html( $admin->format_utc( $send4_state['last_at'] ) ); ?></p>
										<?php if ( '' !== $send4_state['last_error'] ) : ?>
											<p class="send4-xs send4-warning"><?php echo esc_html( $send4_state['last_error'] ); ?></p>
										<?php endif; ?>
									<?php endif; ?>
								</td>
								<td class="send4-table__actions">
									<a class="send4-btn send4-btn--sm" href="<?php echo esc_url( $admin->page_url( array( 'form' => $send4_key ), Send4_Admin::FORMS_SLUG ) ); ?>">
										<?php echo $send4_active ? esc_html__( 'Edit fields', 'send4' ) : esc_html__( 'Set up', 'send4' ); ?>
										<span class="screen-reader-text"><?php echo esc_html( $send4_which ); ?></span>
									</a>
									<?php if ( $send4_active ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="send4-inline-form">
											<?php $admin->nonce_field( 'send4_disable_form' ); ?>
											<input type="hidden" name="action" value="send4_disable_form" />
											<input type="hidden" name="form_key" value="<?php echo esc_attr( $send4_key ); ?>" />
											<button type="submit" class="send4-linkbtn"><?php esc_html_e( 'Turn off', 'send4' ); ?> <span class="screen-reader-text"><?php echo esc_html( $send4_which ); ?></span></button>
										</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php endif; ?>
	</div>
</div>
