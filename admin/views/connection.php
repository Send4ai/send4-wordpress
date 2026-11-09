<?php
/**
 * Send4 → Connection.
 *
 * @package Send4
 *
 * @var Send4_Admin         $admin
 * @var array               $connection
 * @var array|WP_Error|null $handshake
 */

defined( 'ABSPATH' ) || exit;

$send4_notice    = $admin->current_notice();
$send4_state     = Send4_Admin::connection_state( $handshake );
$send4_connected = 'connected' === $send4_state;
$send4_failing   = 'unreachable' === $send4_state || 'rejected' === $send4_state;
$send4_secret    = $connection['client_secret'];
$send4_hint      = '' === $send4_secret ? '' : str_repeat( '•', 8 ) . substr( $send4_secret, -4 );
$send4_account   = $send4_connected && isset( $handshake['client']['name'] ) ? (string) $handshake['client']['name'] : '';

$send4_bar_title   = __( 'Connection', 'send4' );
$send4_bar_state   = $send4_state;
$send4_bar_account = $send4_account;
?>
<div class="send4-app">
	<?php require SEND4_PLUGIN_DIR . 'admin/views/partials/app-bar.php'; ?>

	<div class="send4-body">
		<?php if ( $send4_notice ) : ?>
			<div class="send4-notice send4-notice--<?php echo esc_attr( $send4_notice['type'] ); ?>" role="<?php echo 'error' === $send4_notice['type'] ? 'alert' : 'status'; ?>">
				<p><?php echo esc_html( $send4_notice['message'] ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( ! Send4_Settings::base_url_is_allowed() ) : ?>
			<div class="send4-notice send4-notice--error">
				<p>
					<?php
					printf(
						/* translators: %s: the configured URL. */
						esc_html__( 'Send4 is configured to send to %s, which is not a Send4 address. Nothing will be sent until that is corrected.', 'send4' ),
						'<code>' . esc_html( Send4_Settings::base_url() ) . '</code>'
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( 'disconnected' === $send4_state ) : ?>
			<div class="send4-notice send4-notice--warn">
				<p>
					<strong><?php esc_html_e( 'Send4 is installed but not active yet.', 'send4' ); ?></strong>
					<?php esc_html_e( 'Connect your account below to start handing form submissions to a flow.', 'send4' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<div class="send4-pagehead">
			<div>
				<h1><?php esc_html_e( 'Connect Send4 to your site', 'send4' ); ?></h1>
				<p class="send4-sub">
					<?php
					printf(
						/* translators: %s: site name. */
						esc_html__( 'Two steps to send form submissions from %s to Send4.', 'send4' ),
						esc_html( get_bloginfo( 'name' ) )
					);
					?>
				</p>
			</div>
		</div>

		<div class="send4-grid send4-grid--3 send4-grid--gap-lg">
			<div class="send4-col-2">
				<div class="send4-card send4-card--pad">

					<?php // ── Step 1 ─────────────────────────────────────────── ?>
					<div class="send4-step">
						<div class="send4-step__rail">
							<span class="send4-step__num <?php echo $send4_connected ? 'is-done' : 'is-active'; ?>">
								<?php if ( $send4_connected ) : ?>
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m20 6-11 11-5-5"></path></svg>
									<span class="screen-reader-text"><?php esc_html_e( 'Step 1, done', 'send4' ); ?></span>
								<?php else : ?>
									1
								<?php endif; ?>
							</span>
							<span class="send4-step__line" aria-hidden="true"></span>
						</div>
						<div class="send4-step__body">
							<h2><?php esc_html_e( 'Connect your Send4 account', 'send4' ); ?></h2>

							<?php if ( $send4_connected ) : ?>
								<p class="send4-sub">
									<?php
									printf(
										/* translators: %s: Send4 account name. */
										esc_html__( 'Connected to %s.', 'send4' ),
										'<strong>' . esc_html( $send4_account ) . '</strong>'
									);
									?>
								</p>
								<?php if ( '' !== $admin->format_utc( $connection['connected_at'] ) ) : ?>
									<p class="send4-xs send4-muted">
										<?php
										printf(
											/* translators: %s: date and time the site was connected, in the site's own format. */
											esc_html__( 'Since %s', 'send4' ),
											esc_html( $admin->format_utc( $connection['connected_at'] ) )
										);
										?>
									</p>
								<?php endif; ?>
							<?php elseif ( $send4_failing ) : ?>
								<?php
								// Credentials are stored: this is an outage or a refusal, so
								// the way out is Test connection, not a fresh Connect — which
								// would mint a new credential while the old one may be fine.
								?>
								<div class="send4-notice <?php echo 'rejected' === $send4_state ? 'send4-notice--error' : 'send4-notice--warn'; ?> send4-notice--inline">
									<p><strong><?php echo esc_html( Send4_Admin::outage_message( $send4_state ) ); ?></strong></p>
									<p><?php echo esc_html( $handshake->get_error_message() ); ?></p>
								</div>
							<?php endif; ?>

							<?php if ( $send4_connected || $send4_failing ) : ?>
								<div class="send4-actions">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="send4-inline-form">
										<?php $admin->nonce_field( 'send4_test' ); ?>
										<input type="hidden" name="action" value="send4_test" />
										<button type="submit" class="send4-btn"><?php esc_html_e( 'Test connection', 'send4' ); ?></button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="send4-inline-form">
										<?php $admin->nonce_field( 'send4_disconnect' ); ?>
										<input type="hidden" name="action" value="send4_disconnect" />
										<button type="submit" class="send4-linkbtn send4-linkbtn--danger"><?php esc_html_e( 'Disconnect', 'send4' ); ?></button>
									</form>
								</div>
							<?php endif; ?>

							<?php if ( ! $send4_connected && 'unreachable' !== $send4_state ) : ?>
								<?php if ( 'rejected' === $send4_state ) : ?>
									<p class="send4-sub"><?php esc_html_e( 'If this site\'s key was revoked or replaced in Send4, connect again. The current credentials are kept until new ones are proven to work.', 'send4' ); ?></p>
								<?php else : ?>
									<p class="send4-sub"><?php esc_html_e( 'You will approve this on Send4 and come straight back. Nothing to copy.', 'send4' ); ?></p>
								<?php endif; ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="send4-step__form">
									<?php $admin->nonce_field( 'send4_start_connect' ); ?>
									<input type="hidden" name="action" value="send4_start_connect" />
									<button type="submit" class="send4-btn send4-btn--primary"><?php esc_html_e( 'Connect with Send4', 'send4' ); ?></button>
								</form>

								<details class="send4-manual">
									<summary><?php esc_html_e( 'Connect manually — for self-hosted, local or password-protected sites', 'send4' ); ?></summary>
									<p class="send4-xs send4-muted">
										<?php esc_html_e( 'The button above needs Send4 to be able to send your browser back here. If this site is behind a password, on your own machine, or otherwise unreachable, create an API key in Send4 with the "WordPress plugin" permission and paste it instead.', 'send4' ); ?>
									</p>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php $admin->nonce_field( 'send4_connect' ); ?>
										<input type="hidden" name="action" value="send4_connect" />
										<div class="send4-field">
											<label for="send4-client-id"><?php esc_html_e( 'Client ID', 'send4' ); ?></label>
											<input name="client_id" id="send4-client-id" type="text" class="send4-input" autocomplete="off"
												value="<?php echo esc_attr( $connection['client_id'] ); ?>" />
										</div>
										<div class="send4-field">
											<label for="send4-client-secret"><?php esc_html_e( 'Client secret', 'send4' ); ?></label>
											<input name="client_secret" id="send4-client-secret" type="password" class="send4-input" autocomplete="off"
												placeholder="<?php echo esc_attr( $send4_hint ); ?>" />
											<p class="send4-xs send4-muted">
												<?php
												echo '' === $send4_hint
													? esc_html__( 'Shown only once when you create the key in Send4.', 'send4' )
													: esc_html__( 'A secret is already stored. Leave this blank to keep it.', 'send4' );
												?>
											</p>
										</div>
										<button type="submit" class="send4-btn"><?php esc_html_e( 'Save credentials', 'send4' ); ?></button>
									</form>
								</details>
							<?php endif; ?>
						</div>
					</div>

					<?php // ── Step 2 ─────────────────────────────────────────── ?>
					<div class="send4-step send4-step--last">
						<div class="send4-step__rail">
							<span class="send4-step__num <?php echo $send4_connected ? 'is-active' : ''; ?>">2</span>
						</div>
						<div class="send4-step__body">
							<h2><?php esc_html_e( 'Confirm what is listening', 'send4' ); ?></h2>
							<p class="send4-sub"><?php esc_html_e( 'A Send4 flow with a "WordPress form" trigger is what picks the lead up and follows it over SMS.', 'send4' ); ?></p>

							<?php if ( ! $send4_connected ) : ?>
								<div class="send4-locked">
									<span class="send4-muted">
										<?php
										echo $send4_failing
											? esc_html__( 'Available once Send4 accepts the connection again', 'send4' )
											: esc_html__( 'Available once your account is connected', 'send4' );
										?>
									</span>
								</div>
							<?php elseif ( empty( $handshake['flows'] ) ) : ?>
								<div class="send4-notice send4-notice--warn send4-notice--inline">
									<p><?php esc_html_e( 'No Send4 flow is listening for WordPress forms yet. Create one in your Send4 dashboard with a "WordPress form" trigger, then test the connection again.', 'send4' ); ?></p>
								</div>
							<?php else : ?>
								<div class="send4-table-wrap">
									<table class="send4-table">
										<thead>
											<tr>
												<th scope="col"><?php esc_html_e( 'Flow', 'send4' ); ?></th>
												<th scope="col"><?php esc_html_e( 'Status', 'send4' ); ?></th>
												<th scope="col"><?php esc_html_e( 'Listening for', 'send4' ); ?></th>
											</tr>
										</thead>
										<tbody>
										<?php foreach ( $handshake['flows'] as $send4_flow ) : ?>
											<tr>
												<td class="send4-strong"><?php echo esc_html( isset( $send4_flow['flow_name'] ) ? $send4_flow['flow_name'] : '' ); ?></td>
												<td>
													<?php $send4_flow_status = isset( $send4_flow['flow_status'] ) ? (string) $send4_flow['flow_status'] : ''; ?>
													<span class="send4-badge send4-badge--<?php echo 'active' === $send4_flow_status ? 'ok' : 'neutral'; ?>">
														<?php echo esc_html( $admin->flow_status_label( $send4_flow_status ) ); ?>
													</span>
												</td>
												<td>
													<?php if ( empty( $send4_flow['form_key'] ) ) : ?>
														<em class="send4-muted"><?php esc_html_e( 'Every form on this site', 'send4' ); ?></em>
													<?php else : ?>
														<code><?php echo esc_html( $send4_flow['form_key'] ); ?></code>
													<?php endif; ?>
												</td>
											</tr>
										<?php endforeach; ?>
										</tbody>
									</table>
								</div>
								<p class="send4-xs send4-muted send4-card__note">
									<a href="<?php echo esc_url( $admin->page_url( array(), Send4_Admin::FORMS_SLUG ) ); ?>"><?php esc_html_e( 'Choose which forms to send →', 'send4' ); ?></a>
								</p>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<p class="send4-xs send4-muted send4-footnote">
					<?php esc_html_e( 'Sending to', 'send4' ); ?>
					<code><?php echo esc_html( Send4_Settings::base_url() ); ?></code> —
					<?php esc_html_e( 'submissions and credentials only ever go to Send4.', 'send4' ); ?>
				</p>
			</div>

			<div class="send4-col-1 send4-stack">
				<div class="send4-card send4-card--pad">
					<h2 class="send4-eyebrow"><?php esc_html_e( "What you'll unlock", 'send4' ); ?></h2>
					<ul class="send4-ticks">
						<li><?php esc_html_e( 'Every form submission handed straight to a flow', 'send4' ); ?></li>
						<li><?php esc_html_e( 'Contact Form 7, WPForms, Gravity Forms and Elementor Pro', 'send4' ); ?></li>
						<li><?php esc_html_e( 'AI replies that qualify the lead over SMS', 'send4' ); ?></li>
						<li><?php esc_html_e( 'Nothing lost when Send4 is briefly unreachable', 'send4' ); ?></li>
					</ul>
				</div>

				<div class="send4-card send4-card--pad">
					<div class="send4-promo">
						<span class="send4-tile" aria-hidden="true">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"></path></svg>
						</span>
						<div>
							<p class="send4-strong"><?php esc_html_e( 'New to Send4?', 'send4' ); ?></p>
							<a class="send4-link" href="https://send4.ai/integrations/wordpress" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Read the setup guide →', 'send4' ); ?>
							</a>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
