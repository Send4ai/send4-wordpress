<?php
/**
 * Send4 → Forms & Triggers, one form's field mapping.
 *
 * @package Send4
 *
 * @var Send4_Admin         $admin
 * @var array               $descriptor
 * @var array               $form
 * @var string              $form_key
 * @var array|WP_Error|null $handshake
 */

defined( 'ABSPATH' ) || exit;

$send4_notice = $admin->current_notice();
$send4_fields = $descriptor['fields'];
$send4_mapped = array_filter( array( $form['mapping']['phone'], $form['mapping']['name'], $form['mapping']['email'] ) );
$send4_title  = '' !== $descriptor['title'] ? $descriptor['title'] : __( '(untitled form)', 'send4' );

// A Gravity Forms phone field in its "standard" format accepts US numbers only,
// so neither phone check runs on it. Said here, where they are switched on.
$send4_us_only = false;

foreach ( $send4_fields as $send4_field ) {
	if ( ! empty( $send4_field['us_only'] ) && (string) $send4_field['id'] === (string) $form['mapping']['phone'] ) {
		$send4_us_only = true;
	}
}

$send4_bar_title   = __( 'Forms & Triggers', 'send4' );
$send4_bar_state   = Send4_Admin::connection_state( $handshake );
$send4_bar_account = 'connected' === $send4_bar_state && isset( $handshake['client']['name'] ) ? (string) $handshake['client']['name'] : '';
?>
<div class="send4-app">
	<?php require SEND4_PLUGIN_DIR . 'admin/views/partials/app-bar.php'; ?>

	<div class="send4-body">
		<?php if ( $send4_notice ) : ?>
			<div class="send4-notice send4-notice--<?php echo esc_attr( $send4_notice['type'] ); ?>" role="<?php echo 'error' === $send4_notice['type'] ? 'alert' : 'status'; ?>">
				<p><?php echo esc_html( $send4_notice['message'] ); ?></p>
			</div>
		<?php endif; ?>

		<p class="send4-backlink">
			<a href="<?php echo esc_url( $admin->page_url( array(), Send4_Admin::FORMS_SLUG ) ); ?>">&larr; <?php esc_html_e( 'Forms & triggers', 'send4' ); ?></a>
		</p>

		<div class="send4-pagehead">
			<div>
				<h1><?php echo esc_html( $send4_title ); ?></h1>
				<p class="send4-sub">
					<?php echo esc_html( $descriptor['adapter_name'] ); ?> ·
					<code><?php echo esc_html( $form_key ); ?></code>
				</p>
			</div>
		</div>

		<?php if ( empty( $send4_fields ) ) : ?>
			<div class="send4-notice send4-notice--warn">
				<p><?php esc_html_e( 'This form has no fields Send4 can read yet. Add a field and reload.', 'send4' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'send4_save_mapping' ); ?>
			<input type="hidden" name="action" value="send4_save_mapping" />
			<input type="hidden" name="form_key" value="<?php echo esc_attr( $form_key ); ?>" />

			<div class="send4-grid send4-grid--3 send4-grid--gap-lg">
				<div class="send4-col-2 send4-stack">

					<div class="send4-card send4-card--pad">
						<h2 class="send4-card__title send4-card__title--sub"><?php esc_html_e( 'Who is this lead?', 'send4' ); ?></h2>

						<div class="send4-field">
							<label for="send4-map-phone">
								<?php esc_html_e( 'Phone number', 'send4' ); ?>
								<span class="send4-badge send4-badge--ok"><?php esc_html_e( 'Required', 'send4' ); ?></span>
							</label>
							<select name="map_phone" id="send4-map-phone" class="send4-input">
								<option value=""><?php esc_html_e( '— choose a field —', 'send4' ); ?></option>
								<?php foreach ( $send4_fields as $send4_field ) : ?>
									<option value="<?php echo esc_attr( $send4_field['id'] ); ?>" <?php selected( $form['mapping']['phone'], $send4_field['id'] ); ?>>
										<?php echo esc_html( ( '' !== $send4_field['label'] ? $send4_field['label'] : $send4_field['id'] ) . ' (' . $send4_field['type'] . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="send4-xs send4-muted">
								<?php esc_html_e( 'Send4 follows up by SMS, so a submission without a phone number has nobody to reach.', 'send4' ); ?>
							</p>
						</div>

						<div class="send4-grid send4-grid--2">
							<div class="send4-field">
								<label for="send4-map-name"><?php esc_html_e( 'Name', 'send4' ); ?></label>
								<select name="map_name" id="send4-map-name" class="send4-input">
									<option value=""><?php esc_html_e( '— not mapped —', 'send4' ); ?></option>
									<?php foreach ( $send4_fields as $send4_field ) : ?>
										<option value="<?php echo esc_attr( $send4_field['id'] ); ?>" <?php selected( $form['mapping']['name'], $send4_field['id'] ); ?>>
											<?php echo esc_html( '' !== $send4_field['label'] ? $send4_field['label'] : $send4_field['id'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="send4-field">
								<label for="send4-map-email"><?php esc_html_e( 'Email', 'send4' ); ?></label>
								<select name="map_email" id="send4-map-email" class="send4-input">
									<option value=""><?php esc_html_e( '— not mapped —', 'send4' ); ?></option>
									<?php foreach ( $send4_fields as $send4_field ) : ?>
										<option value="<?php echo esc_attr( $send4_field['id'] ); ?>" <?php selected( $form['mapping']['email'], $send4_field['id'] ); ?>>
											<?php echo esc_html( '' !== $send4_field['label'] ? $send4_field['label'] : $send4_field['id'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
					</div>

					<div class="send4-card send4-card--pad">
						<h2 class="send4-card__title send4-card__title--sub"><?php esc_html_e( 'Check the phone number', 'send4' ); ?></h2>
						<p class="send4-xs send4-muted">
							<?php esc_html_e( 'Send4 can only text a number that includes its country code. These checks ask the visitor to fix the number while they are still on the form. Both are off until you turn them on.', 'send4' ); ?>
						</p>

						<?php if ( $send4_us_only ) : ?>
							<div class="send4-notice send4-notice--warn">
								<p><?php esc_html_e( 'This phone field uses the Gravity Forms "Standard" format, which only accepts US numbers written (###) ###-####. Neither check below runs on it: Gravity Forms would refuse every number they let through. To use them, set the field\'s Phone Format to International in Gravity Forms.', 'send4' ); ?></p>
							</div>
						<?php endif; ?>

						<div class="send4-field">
							<label class="send4-check send4-check--top">
								<input type="checkbox" name="phone_picker" value="1" <?php checked( $form['phone_field']['picker'] ); ?> />
								<span><?php esc_html_e( 'Add a country picker to the phone field', 'send4' ); ?></span>
							</label>
							<p class="send4-xs send4-muted">
								<?php esc_html_e( 'The visitor chooses their country from a list, and the number is sent with its country code already on it.', 'send4' ); ?>
							</p>
						</div>

						<div class="send4-field">
							<label for="send4-phone-default-country"><?php esc_html_e( 'Country selected to start with', 'send4' ); ?></label>
							<select name="phone_default_country" id="send4-phone-default-country" class="send4-input" data-send4-locale="<?php echo esc_attr( str_replace( '_', '-', get_user_locale() ) ); ?>">
								<option value=""><?php esc_html_e( '— none: the visitor chooses —', 'send4' ); ?></option>
								<?php foreach ( Send4_Phone_Field::countries() as $send4_country ) : ?>
									<option value="<?php echo esc_attr( $send4_country ); ?>" <?php selected( $form['phone_field']['default_country'], $send4_country ); ?>>
										<?php echo esc_html( strtoupper( $send4_country ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="send4-xs send4-muted">
								<?php esc_html_e( 'Pick the country most of your visitors call from. Left empty, the site language\'s country is used when it has one. A wrong guess is worse than none: the picker limits the number to that country\'s length.', 'send4' ); ?>
							</p>
						</div>

						<div class="send4-field">
							<label class="send4-check send4-check--top">
								<input type="checkbox" name="validate_phone" value="1" <?php checked( $form['validate_phone'] ); ?> />
								<span><?php esc_html_e( 'Refuse a submission whose phone number has no country code', 'send4' ); ?></span>
							</label>
							<p class="send4-xs send4-muted">
								<?php esc_html_e( 'The visitor sees a message and cannot send the form until the number starts with + and a country code. Turn this on knowing the trade: a refused visitor who gives up is a lead you never see, and it is not counted anywhere except the activity log. Visitors whose browser runs no JavaScript get no picker, so they will be asked to type the country code themselves. An empty, optional phone field is never refused.', 'send4' ); ?>
							</p>
						</div>

						<p class="send4-xs send4-muted">
							<?php esc_html_e( 'If your site uses a page cache, clear it after changing these, or cached pages keep the old setting.', 'send4' ); ?>
						</p>
					</div>

					<div class="send4-card send4-card--pad">
						<h2 class="send4-card__title send4-card__title--sub"><?php esc_html_e( 'Turn it on', 'send4' ); ?></h2>
						<label class="send4-check">
							<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $form['enabled'] ) ); ?> />
							<span><?php esc_html_e( 'Send submissions from this form to Send4', 'send4' ); ?></span>
						</label>
						<div class="send4-actions send4-actions--end">
							<a class="send4-linkbtn" href="<?php echo esc_url( $admin->page_url( array(), Send4_Admin::FORMS_SLUG ) ); ?>"><?php esc_html_e( 'Discard', 'send4' ); ?></a>
							<button type="submit" class="send4-btn send4-btn--primary"><?php esc_html_e( 'Save changes', 'send4' ); ?></button>
						</div>
					</div>
				</div>

				<div class="send4-col-1 send4-stack">
					<div class="send4-card send4-card--pad">
						<h2 class="send4-eyebrow"><?php esc_html_e( 'Everything else', 'send4' ); ?></h2>
						<p class="send4-xs send4-muted">
							<?php esc_html_e( 'Every other field is sent as context for the AI, so it can answer questions about what the person asked for. Nothing is dropped.', 'send4' ); ?>
						</p>
						<ul class="send4-passthrough">
							<?php foreach ( $send4_fields as $send4_field ) : ?>
								<?php if ( in_array( $send4_field['id'], $send4_mapped, true ) ) : ?>
									<?php continue; ?>
								<?php endif; ?>
								<li>
									<span class="send4-truncate"><?php echo esc_html( '' !== $send4_field['label'] ? $send4_field['label'] : $send4_field['id'] ); ?></span>
									<code><?php echo esc_html( $send4_field['id'] ); ?></code>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>

					<div class="send4-card send4-card--pad">
						<h2 class="send4-eyebrow"><?php esc_html_e( 'Bind a flow', 'send4' ); ?></h2>
						<p class="send4-xs send4-muted">
							<?php esc_html_e( 'Paste this form key into the flow\'s WordPress trigger in Send4 to bind it to this form. Leave the trigger blank to catch every form on the site.', 'send4' ); ?>
						</p>
						<p><code class="send4-code-block"><?php echo esc_html( $form_key ); ?></code></p>
					</div>
				</div>
			</div>
		</form>
	</div>
</div>
