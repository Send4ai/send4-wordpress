<?php
/**
 * Send4 → Dashboard.
 *
 * @package Send4
 *
 * @var Send4_Admin $admin
 * @var array       $data
 */

defined( 'ABSPATH' ) || exit;

$send4_notice = $admin->current_notice();
$send4_range  = $data['range'];
$send4_queue  = $data['queue'];

$send4_pending   = isset( $send4_queue[ Send4_Queue::STATUS_PENDING ] ) ? (int) $send4_queue[ Send4_Queue::STATUS_PENDING ] : 0;
$send4_exhausted = isset( $send4_queue[ Send4_Queue::STATUS_FAILED ] ) ? (int) $send4_queue[ Send4_Queue::STATUS_FAILED ] : 0;
$send4_rejected  = isset( $send4_queue[ Send4_Queue::STATUS_PERMANENT ] ) ? (int) $send4_queue[ Send4_Queue::STATUS_PERMANENT ] : 0;

$send4_outcomes = $data['outcomes'];

$send4_bar_title   = __( 'Dashboard', 'send4' );
$send4_bar_state   = $data['state'];
$send4_bar_account = $data['account'];
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

		<?php if ( 'disconnected' === $data['state'] ) : ?>
			<div class="send4-notice send4-notice--warn">
				<p>
					<strong><?php esc_html_e( 'Send4 is installed but not active yet.', 'send4' ); ?></strong>
					<?php esc_html_e( 'Connect your account to start sending form submissions.', 'send4' ); ?>
					<a href="<?php echo esc_url( $admin->page_url( array(), Send4_Admin::CONNECTION_SLUG ) ); ?>"><?php esc_html_e( 'Connect now', 'send4' ); ?></a>
				</p>
			</div>
		<?php elseif ( 'unreachable' === $data['state'] || 'rejected' === $data['state'] ) : ?>
			<?php
			// Credentials are stored, so this is an outage or a refusal, not a
			// site that was never set up — and "connect your account" here
			// would steer the admin into minting a new credential mid-outage.
			?>
			<div class="send4-notice <?php echo 'rejected' === $data['state'] ? 'send4-notice--error' : 'send4-notice--warn'; ?>">
				<p>
					<strong><?php echo esc_html( Send4_Admin::outage_message( $data['state'] ) ); ?></strong>
					<?php echo esc_html( $data['error'] ); ?>
					<a href="<?php echo esc_url( $admin->page_url( array(), Send4_Admin::CONNECTION_SLUG ) ); ?>"><?php esc_html_e( 'Check the connection', 'send4' ); ?></a>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $data['readiness'] ) ) : ?>
			<?php
			// Send4 accepts a lead whether or not anything can text it, so
			// this card is the only place a site learns why nobody was texted.
			// Drawn from what Send4 said on the handshake, never guessed here.
			?>
			<section class="send4-card send4-card--flush send4-readiness" aria-labelledby="send4-readiness-title">
				<div class="send4-card__head send4-card__head--stacked">
					<h2 id="send4-readiness-title"><?php esc_html_e( 'Before Send4 can text your leads', 'send4' ); ?></h2>
					<p class="send4-xs send4-muted"><?php esc_html_e( 'Forms keep sending to Send4 meanwhile, but nobody is texted until these are done.', 'send4' ); ?></p>
				</div>
				<ul class="send4-list send4-readiness__list">
					<?php foreach ( $data['readiness'] as $send4_check ) : ?>
						<li class="send4-list__row" data-check="<?php echo esc_attr( $send4_check['key'] ); ?>">
							<span class="send4-dot send4-dot--warn" aria-hidden="true"></span>
							<p class="send4-list__main"><?php echo esc_html( $send4_check['message'] ); ?></p>
							<?php if ( '' !== $send4_check['action_url'] ) : ?>
								<a class="send4-btn send4-btn--sm" href="<?php echo esc_url( $send4_check['action_url'] ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Fix in Send4', 'send4' ); ?>
									<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'send4' ); ?></span>
								</a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<div class="send4-pagehead">
			<div>
				<h1><?php esc_html_e( 'Overview', 'send4' ); ?></h1>
				<p class="send4-sub">
					<?php
					printf(
						/* translators: 1: site name, 2: the selected range, e.g. "last 30 days". */
						esc_html__( '%1$s · %2$s', 'send4' ),
						esc_html( get_bloginfo( 'name' ) ),
						esc_html( Send4_Stats::range_label( $send4_range ) )
					);
					?>
				</p>
			</div>
			<div class="send4-segmented" role="group" aria-label="<?php esc_attr_e( 'Time range', 'send4' ); ?>">
				<?php foreach ( Send4_Stats::ranges() as $send4_option ) : ?>
					<a
						class="send4-segmented__item<?php echo $send4_option === $send4_range ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( $admin->page_url( array( 'range' => $send4_option ) ) ); ?>"
						aria-label="<?php echo esc_attr( Send4_Stats::range_label( $send4_option ) ); ?>"
						<?php echo $send4_option === $send4_range ? ' aria-current="true"' : ''; ?>
					><?php echo esc_html( Send4_Stats::range_short_label( $send4_option ) ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="send4-grid send4-grid--4">
			<div class="send4-card send4-stat">
				<p class="send4-stat__label">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"></path></svg>
					<?php esc_html_e( 'Connection', 'send4' ); ?>
				</p>
				<?php
				// Tone, headline and note for each connection state.
				$send4_state_labels = array(
					'connected'    => array( 'ok', __( 'Connected', 'send4' ), '' ),
					'unreachable'  => array( 'warn', __( 'Unreachable', 'send4' ), __( 'Submissions are queued for retry', 'send4' ) ),
					'rejected'     => array( 'bad', __( 'Refused', 'send4' ), __( 'Submissions are not being delivered', 'send4' ) ),
					'unchecked'    => array( 'warn', __( 'Not checked', 'send4' ), '' ),
					'disconnected' => array( 'warn', __( 'Not connected', 'send4' ), __( 'Nothing is being sent', 'send4' ) ),
				);
				$send4_state_label  = isset( $send4_state_labels[ $data['state'] ] ) ? $send4_state_labels[ $data['state'] ] : $send4_state_labels['disconnected'];
				?>
				<p class="send4-stat__value send4-stat__value--sm">
					<span class="send4-dot send4-dot--<?php echo esc_attr( $send4_state_label[0] ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( $send4_state_label[1] ); ?>
				</p>
				<p class="send4-stat__note">
					<?php
					if ( $data['connected'] ) {
						printf(
							/* translators: 1: number of flows listening, 2: number of those that are active. */
							esc_html( _n( '%1$s flow listening · %2$s active', '%1$s flows listening · %2$s active', (int) $data['flows']['total'], 'send4' ) ),
							esc_html( number_format_i18n( (int) $data['flows']['total'] ) ),
							esc_html( number_format_i18n( (int) $data['flows']['active'] ) )
						);
					} else {
						echo esc_html( $send4_state_label[2] );
					}
					?>
				</p>
			</div>

			<div class="send4-card send4-stat">
				<p class="send4-stat__label">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M22 2 11 13"></path><path d="M22 2 15 22l-4-9-9-4Z"></path></svg>
					<?php esc_html_e( 'Submissions sent', 'send4' ); ?>
				</p>
				<p class="send4-stat__value">
					<?php echo esc_html( number_format_i18n( (int) $data['sent'] ) ); ?>
					<?php if ( null !== $data['sent_delta'] ) : ?>
						<?php
						$send4_delta = (int) $data['sent_delta'];

						// No change is not good news, so it is not drawn green.
						if ( 0 === $send4_delta ) {
							$send4_delta_class = 'send4-delta--flat';
							$send4_delta_mark  = '';
						} elseif ( $send4_delta < 0 ) {
							$send4_delta_class = 'send4-delta--down';
							$send4_delta_mark  = '▼ ';
						} else {
							$send4_delta_class = 'send4-delta--up';
							$send4_delta_mark  = '▲ ';
						}
						?>
						<span class="send4-delta <?php echo esc_attr( $send4_delta_class ); ?>">
							<?php
							echo esc_html(
								$send4_delta_mark . sprintf(
									/* translators: %s: a percentage, without its sign. */
									__( '%s%%', 'send4' ),
									number_format_i18n( abs( $send4_delta ) )
								)
							);
							?>
						</span>
					<?php endif; ?>
				</p>
				<p class="send4-stat__note">
					<?php
					echo null === $data['sent_delta']
						? esc_html__( 'Accepted by Send4', 'send4' )
						: esc_html__( 'vs the window before', 'send4' );
					?>
				</p>
			</div>

			<div class="send4-card send4-stat">
				<p class="send4-stat__label">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 12a9 9 0 1 0 9-9"></path><path d="M3 3v6h6"></path></svg>
					<?php esc_html_e( 'Retry queue', 'send4' ); ?>
				</p>
				<p class="send4-stat__value"><?php echo esc_html( number_format_i18n( $send4_pending ) ); ?></p>
				<p class="send4-stat__note">
					<?php
					printf(
						/* translators: 1: gave-up count, 2: rejected count. */
						esc_html__( 'Gave up: %1$s · Rejected: %2$s', 'send4' ),
						esc_html( number_format_i18n( (int) $send4_exhausted ) ),
						esc_html( number_format_i18n( (int) $send4_rejected ) )
					);
					?>
				</p>
			</div>

			<div class="send4-card send4-stat">
				<p class="send4-stat__label">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>
					<?php esc_html_e( 'Last delivery', 'send4' ); ?>
				</p>
				<?php if ( null === $data['last'] ) : ?>
					<p class="send4-stat__value send4-stat__value--sm send4-muted"><?php esc_html_e( 'Nothing yet', 'send4' ); ?></p>
					<p class="send4-stat__note"><?php esc_html_e( 'No form has submitted since you installed Send4', 'send4' ); ?></p>
				<?php else : ?>
					<p class="send4-stat__value send4-stat__value--sm"><?php echo esc_html( $data['last']['ago'] ); ?></p>
					<p class="send4-stat__note">
						<span class="send4-badge send4-badge--<?php echo esc_attr( $data['last']['tone'] ); ?>"><?php echo esc_html( $data['last']['label'] ); ?></span>
					</p>
				<?php endif; ?>
			</div>
		</div>

		<div class="send4-card send4-card--flush send4-outcomes">
			<div class="send4-card__head send4-card__head--stacked">
				<h2><?php esc_html_e( 'What happened to submissions', 'send4' ); ?></h2>
				<p class="send4-xs send4-muted">
					<?php
					/* translators: %s: the selected range, e.g. "last 30 days". */
					printf( esc_html__( 'Every submission that reached an ending in the %s', 'send4' ), esc_html( Send4_Stats::range_label( $send4_range ) ) );
					?>
				</p>
			</div>

			<?php if ( 0 === $send4_outcomes['total'] ) : ?>
				<div class="send4-empty">
					<p><?php esc_html_e( 'No submissions in this range.', 'send4' ); ?></p>
					<p class="send4-muted"><?php esc_html_e( 'Delivered, unrouted and failed submissions are broken down here once there are some.', 'send4' ); ?></p>
				</div>
			<?php else : ?>
				<div class="send4-outcomes__body">
					<div class="send4-outcomes__rate">
						<p class="send4-stat__value">
							<?php
							/* translators: %s: a percentage, without its sign. */
							echo esc_html( sprintf( __( '%s%%', 'send4' ), number_format_i18n( (int) $data['rate'] ) ) );
							?>
						</p>
						<p class="send4-stat__note"><?php esc_html_e( 'accepted by Send4', 'send4' ); ?></p>
					</div>

					<div class="send4-outcomes__main">
						<div class="send4-bar" aria-hidden="true">
							<?php foreach ( $send4_outcomes['segments'] as $send4_segment ) : ?>
								<?php if ( $send4_segment['count'] > 0 ) : ?>
									<span class="send4-bar__seg send4-bar__seg--<?php echo esc_attr( $send4_segment['tone'] ); ?>" style="flex-grow: <?php echo esc_attr( (string) $send4_segment['count'] ); ?>;"></span>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>

						<ul class="send4-legend">
							<?php foreach ( $send4_outcomes['segments'] as $send4_segment ) : ?>
								<li class="send4-legend__item">
									<span class="send4-legend__swatch send4-bar__seg--<?php echo esc_attr( $send4_segment['tone'] ); ?>" aria-hidden="true"></span>
									<span class="send4-muted"><?php echo esc_html( $send4_segment['label'] ); ?></span>
									<span class="send4-strong"><?php echo esc_html( number_format_i18n( $send4_segment['count'] ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			<?php endif; ?>

			<?php
			$send4_unrouted = 0;

			foreach ( $send4_outcomes['segments'] as $send4_segment ) {
				if ( 'unrouted' === $send4_segment['status'] ) {
					$send4_unrouted = $send4_segment['count'];
				}
			}
			?>
			<?php if ( $send4_unrouted > 0 || $send4_outcomes['refused'] > 0 ) : ?>
				<div class="send4-card__foot send4-outcomes__foot">
					<?php if ( $send4_unrouted > 0 ) : ?>
						<p class="send4-xs">
							<span class="send4-badge send4-badge--warn"><?php echo esc_html( number_format_i18n( $send4_unrouted ) ); ?></span>
							<?php echo esc_html( _n( 'submission reached Send4 but no flow was listening for its form, so nobody was messaged. Add a flow for that form in Send4.', 'submissions reached Send4 but no flow was listening for their form, so nobody was messaged. Add a flow for those forms in Send4.', $send4_unrouted, 'send4' ) ); ?>
						</p>
					<?php endif; ?>
					<?php if ( $send4_outcomes['refused'] > 0 ) : ?>
						<p class="send4-xs">
							<span class="send4-badge send4-badge--neutral"><?php echo esc_html( number_format_i18n( $send4_outcomes['refused'] ) ); ?></span>
							<?php echo esc_html( _n( 'visitor was stopped by the phone check and asked to add a country code. Counted on the server only: numbers the picker catches in the browser never get this far.', 'visitors were stopped by the phone check and asked to add a country code. Counted on the server only: numbers the picker catches in the browser never get this far.', $send4_outcomes['refused'], 'send4' ) ); ?>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="send4-card send4-card--flush send4-formstats">
			<div class="send4-card__head send4-card__head--stacked">
				<h2><?php esc_html_e( 'By form', 'send4' ); ?></h2>
				<p class="send4-xs send4-muted"><?php esc_html_e( 'Where the leads come from, busiest first', 'send4' ); ?></p>
			</div>

			<?php if ( empty( $data['forms'] ) ) : ?>
				<div class="send4-empty">
					<p><?php esc_html_e( 'No form has been submitted in this range.', 'send4' ); ?></p>
				</div>
			<?php else : ?>
				<div class="send4-table-wrap">
					<table class="send4-table send4-table--rows">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Form', 'send4' ); ?></th>
								<th scope="col" class="send4-table__num"><?php esc_html_e( 'Sent', 'send4' ); ?></th>
								<th scope="col" class="send4-table__num"><?php esc_html_e( 'No flow', 'send4' ); ?></th>
								<th scope="col" class="send4-table__num"><?php esc_html_e( 'Failed', 'send4' ); ?></th>
								<th scope="col" class="send4-table__num"><?php esc_html_e( 'Phone check', 'send4' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Last submission', 'send4' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $data['forms'] as $send4_form ) : ?>
								<tr>
									<td>
										<?php if ( $send4_form['exists'] ) : ?>
											<a class="send4-strong" href="<?php echo esc_url( $admin->page_url( array( 'form' => $send4_form['key'] ), Send4_Admin::FORMS_SLUG ) ); ?>"><?php echo esc_html( $send4_form['label'] ); ?></a>
										<?php else : ?>
											<span class="send4-strong"><?php echo esc_html( $send4_form['label'] ); ?></span>
										<?php endif; ?>
										<?php if ( '' !== $send4_form['adapter'] ) : ?>
											<span class="send4-badge send4-badge--neutral"><?php echo esc_html( $send4_form['adapter'] ); ?></span>
										<?php endif; ?>
									</td>
									<td class="send4-table__num"><?php echo esc_html( number_format_i18n( $send4_form['sent'] ) ); ?></td>
									<td class="send4-table__num<?php echo $send4_form['unrouted'] > 0 ? ' send4-table__num--warn' : ''; ?>"><?php echo esc_html( number_format_i18n( $send4_form['unrouted'] ) ); ?></td>
									<td class="send4-table__num<?php echo $send4_form['failed'] > 0 ? ' send4-table__num--bad' : ''; ?>"><?php echo esc_html( number_format_i18n( $send4_form['failed'] ) ); ?></td>
									<td class="send4-table__num"><?php echo esc_html( number_format_i18n( $send4_form['refused'] ) ); ?></td>
									<td>
										<?php if ( '' !== $send4_form['last_label'] ) : ?>
											<span class="send4-badge send4-badge--<?php echo esc_attr( $send4_form['last_tone'] ); ?>"><?php echo esc_html( $send4_form['last_label'] ); ?></span>
											<span class="send4-muted send4-xs"><?php echo esc_html( $send4_form['last_ago'] ); ?></span>
										<?php else : ?>
											<span class="send4-muted">—</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $data['forms_note'] ) : ?>
				<div class="send4-card__foot">
					<p class="send4-xs send4-muted"><?php echo esc_html( $data['forms_note'] ); ?></p>
				</div>
			<?php endif; ?>
		</div>

		<div class="send4-grid send4-grid--3 send4-grid--gap-lg">
			<div class="send4-col-2">
				<div class="send4-card send4-card--flush">
					<div class="send4-card__head">
						<h2><?php esc_html_e( 'Recent activity', 'send4' ); ?></h2>
						<?php if ( ! empty( $data['activity'] ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="send4-inline-form">
								<?php $admin->nonce_field( 'send4_clear_log' ); ?>
								<input type="hidden" name="action" value="send4_clear_log" />
								<button type="submit" class="send4-linkbtn"><?php esc_html_e( 'Clear', 'send4' ); ?></button>
							</form>
						<?php endif; ?>
					</div>

					<?php if ( empty( $data['activity'] ) ) : ?>
						<div class="send4-empty">
							<p><?php esc_html_e( 'Nothing has happened yet.', 'send4' ); ?></p>
							<p class="send4-muted"><?php esc_html_e( 'Once a form you have turned on is submitted, every delivery shows up here.', 'send4' ); ?></p>
						</div>
					<?php else : ?>
						<div class="send4-list">
							<?php foreach ( $data['activity'] as $send4_row ) : ?>
								<div class="send4-list__row">
									<span class="send4-avatar send4-avatar--<?php echo esc_attr( $send4_row['tone'] ); ?>"><?php echo esc_html( $send4_row['initial'] ); ?></span>
									<div class="send4-list__main">
										<div class="send4-list__title">
											<span class="send4-strong"><?php echo esc_html( '' !== $send4_row['label'] ? $send4_row['label'] : __( 'Send4', 'send4' ) ); ?></span>
											<?php if ( '' !== $send4_row['adapter'] ) : ?>
												<span class="send4-badge send4-badge--neutral"><?php echo esc_html( $send4_row['adapter'] ); ?></span>
											<?php endif; ?>
										</div>
										<p class="send4-list__sub"><?php echo esc_html( $send4_row['message'] ); ?></p>
									</div>
									<div class="send4-list__meta">
										<?php if ( '' !== $send4_row['status'] ) : ?>
											<span class="send4-badge send4-badge--<?php echo esc_attr( $send4_row['tone'] ); ?>"><?php echo esc_html( $admin->status_label( $send4_row['status'] ) ); ?></span>
										<?php endif; ?>
										<span class="send4-muted send4-xs"><?php echo esc_html( $send4_row['ago'] ); ?></span>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="send4-col-1 send4-stack">
				<div class="send4-card send4-card--flush">
					<div class="send4-card__head send4-card__head--stacked">
						<h2><?php esc_html_e( 'WordPress triggers', 'send4' ); ?></h2>
						<p class="send4-xs send4-muted"><?php esc_html_e( 'Forms that hand a lead to Send4', 'send4' ); ?></p>
					</div>

					<?php if ( empty( $data['triggers'] ) ) : ?>
						<div class="send4-empty">
							<p class="send4-muted"><?php esc_html_e( 'No form plugins detected.', 'send4' ); ?></p>
						</div>
					<?php else : ?>
						<div class="send4-list">
							<?php foreach ( $data['triggers'] as $send4_trigger ) : ?>
								<div class="send4-list__row">
									<span class="send4-tile send4-tile--<?php echo esc_attr( $send4_trigger['state'] ); ?>" aria-hidden="true">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"></path><path d="M14 2v6h6"></path><path d="M9 13h6"></path><path d="M9 17h4"></path></svg>
									</span>
									<div class="send4-list__main">
										<p class="send4-strong send4-truncate"><?php echo esc_html( $send4_trigger['name'] ); ?></p>
										<p class="send4-xs send4-muted send4-truncate"><?php echo esc_html( $send4_trigger['detail'] ); ?></p>
									</div>
									<?php if ( 'on' === $send4_trigger['state'] ) : ?>
										<span class="send4-badge send4-badge--ok"><?php esc_html_e( 'On', 'send4' ); ?></span>
									<?php elseif ( 'idle' === $send4_trigger['state'] ) : ?>
										<a class="send4-btn send4-btn--sm" href="<?php echo esc_url( $admin->page_url( array(), Send4_Admin::FORMS_SLUG ) ); ?>">
											<?php esc_html_e( 'Set up', 'send4' ); ?>
										</a>
									<?php else : ?>
										<span class="send4-badge send4-badge--neutral"><?php esc_html_e( 'Off', 'send4' ); ?></span>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<div class="send4-card__foot">
						<a class="send4-btn send4-btn--dashed" href="<?php echo esc_url( $admin->page_url( array(), Send4_Admin::FORMS_SLUG ) ); ?>">
							<?php esc_html_e( 'Manage forms & triggers', 'send4' ); ?>
						</a>
					</div>
				</div>

				<div class="send4-card">
					<h2 class="send4-card__title"><?php esc_html_e( 'Flows listening', 'send4' ); ?></h2>
					<div class="send4-triple">
						<div>
							<p class="send4-triple__value"><?php echo esc_html( number_format_i18n( (int) $data['flows']['total'] ) ); ?></p>
							<p class="send4-xs send4-muted"><?php esc_html_e( 'Total', 'send4' ); ?></p>
						</div>
						<div>
							<p class="send4-triple__value send4-triple__value--ok"><?php echo esc_html( number_format_i18n( (int) $data['flows']['active'] ) ); ?></p>
							<p class="send4-xs send4-muted"><?php esc_html_e( 'Active', 'send4' ); ?></p>
						</div>
						<div>
							<p class="send4-triple__value"><?php echo esc_html( number_format_i18n( (int) $data['flows']['catchall'] ) ); ?></p>
							<p class="send4-xs send4-muted"><?php esc_html_e( 'Every form', 'send4' ); ?></p>
						</div>
					</div>
					<p class="send4-xs send4-muted send4-card__note">
						<?php esc_html_e( 'If Send4 is unreachable when someone submits, the submission is queued and retried for about a day and a half. Nothing is lost.', 'send4' ); ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'send4_run_queue' ); ?>
						<input type="hidden" name="action" value="send4_run_queue" />
						<button type="submit" class="send4-btn send4-btn--block"><?php esc_html_e( 'Retry queued now', 'send4' ); ?></button>
					</form>
					<?php if ( $send4_exhausted + $send4_rejected > 0 ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php // Not wp_nonce_field(): it prints a second id="_wpnonce" on this page. ?>
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'send4_requeue' ) ); ?>" />
							<input type="hidden" name="action" value="send4_requeue" />
							<button type="submit" class="send4-btn send4-btn--block">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: number of failed submissions. */
										_n( 'Resend %s failed submission', 'Resend %s failed submissions', $send4_exhausted + $send4_rejected, 'send4' ),
										number_format_i18n( $send4_exhausted + $send4_rejected )
									)
								);
								?>
							</button>
						</form>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</div>
