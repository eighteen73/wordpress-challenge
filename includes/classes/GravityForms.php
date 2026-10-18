<?php
/**
 * Automatic Gravity Forms protection and editor-placement field.
 *
 * @package Eighteen73\Challenge
 */

namespace Eighteen73\Challenge;

defined( 'ABSPATH' ) || exit;

/** Protect Gravity Forms submissions and register an inline field. */
class GravityForms {
	use Singleton;

	/**
	 * Whether hooks have been registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Failed verification by form for this request.
	 *
	 * @var array<int, bool>
	 */
	private array $failed = [];

	/**
	 * Resume email values retained only for re-rendering a rejected request.
	 *
	 * @var array<int, string>
	 */
	private array $resume_emails = [];

	/** Register hooks after Gravity Forms has loaded. */
	public function setup(): void {
		if ( $this->registered || ! class_exists( 'GF_Fields' ) ) {
			return;
		}
		$this->registered = true;
		\GF_Fields::register( new GravityField() );
		// Let themes transform Gravity Forms' submit control before inserting our widget.
		add_filter( 'gform_submit_button', [ $this, 'submit_button' ], 20, 2 );
		add_filter( 'gform_validation', [ $this, 'validate' ] );
		add_filter( 'gform_validation_message', [ $this, 'validation_message' ], 10, 2 );
		add_filter( 'gform_pre_process', [ $this, 'guard_save' ] );
		add_filter( 'gform_savecontinue_link', [ $this, 'save_button' ], 20, 2 );
		add_filter( 'gform_get_form_save_confirmation_filter', [ $this, 'resume_widget' ], 10, 2 );
		// GF processes legacy resume email requests at priority 9 on these hooks.
		add_action( 'wp', [ $this, 'guard_resume_email' ], 8 );
		add_action( 'admin_init', [ $this, 'guard_resume_email' ], 8 );
		add_action( 'gform_ajax_pre_submit_form', [ $this, 'guard_resume_email' ] );
	}

	/**
	 * Check whether editors placed a Challenge field.
	 *
	 * @param array $form Gravity Forms form.
	 * @return bool Whether an inline field exists.
	 */
	private function has_field( array $form ): bool {
		foreach ( $form['fields'] ?? [] as $field ) {
			if ( 'form_challenge' === $field->type ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check the global default and any explicit form field.
	 *
	 * @param array $form Gravity Forms form.
	 * @return bool Whether the form requires verification.
	 */
	private function protected( array $form ): bool {
		return ! empty( Settings::options()['gf_all'] ) || $this->has_field( $form );
	}

	/**
	 * Enqueue provider assets only for protected forms.
	 *
	 * @param array $form Gravity Forms form.
	 */
	public function enqueue( array $form ): void {
		if ( $this->protected( $form ) ) {
			Challenge::instance()->enqueue();
		}
	}

	/**
	 * Add an automatic widget when no inline field was selected.
	 *
	 * @param string $button Submit button HTML.
	 * @param array  $form   Gravity Forms form.
	 * @return string Updated markup.
	 */
	public function submit_button( string $button, array $form ): string {
		if ( $this->has_field( $form ) || ! $this->protected( $form ) || ! Challenge::instance()->active() ) {
			return $button;
		}
		return Challenge::instance()->driver()->render( 'gf_' . (int) $form['id'] ) . $button;
	}

	/**
	 * Verify only the final form submission, not intermediate pages.
	 *
	 * @param array $result Gravity Forms validation result.
	 * @return array Updated validation result.
	 */
	public function validate( array $result ): array {
		$id = (int) $result['form']['id'];
		if ( ! empty( $this->failed[ $id ] ) ) {
			$result['is_valid'] = false;
			return $result;
		}
		if ( ! $result['is_valid'] || ! Challenge::instance()->active() || ! $this->protected( $result['form'] ) ) {
			return $result;
		}
		$form = $result['form'];
		$id   = (int) $form['id'];
		// Use Gravity Forms' resolved target, including out-of-range and conditionally hidden pages.
		if ( ! \GFFormDisplay::is_last_page( $form ) ) {
			return $result;
		}
		if ( ! Challenge::instance()->verify( 'gf_' . $id ) ) {
			$this->failed[ $id ] = true;
			$result['is_valid']  = false;
			if ( $this->has_field( $form ) ) {
				foreach ( $form['fields'] as $field ) {
					if ( 'form_challenge' === $field->type ) {
						$field->failed_validation  = true;
						$field->validation_message = __( 'Verification failed. Please try again.', 'challenge' );
						break;
					}
				}
			}
			$result['form'] = $form;
		}
		return $result;
	}

	/**
	 * Verify before GF's save-for-later path bypasses normal validation.
	 *
	 * @param array $form Submitted form.
	 * @return array Unchanged form metadata.
	 */
	public function guard_save( array $form ): array {
		if ( empty( $_POST['gform_save'] ) || ! Challenge::instance()->active() || ! $this->protected( $form ) ) {
			return $form;
		}
		$id = (int) $form['id'];
		if ( ! Challenge::instance()->verify( 'gf_' . $id ) ) {
			$this->failed[ $id ] = true;
			// Re-enter GF's validation/re-render path, never its draft or entry save paths.
			unset( $_POST['gform_save'] );
			$_POST[ 'gform_target_page_number_' . $id ] = \GFFormDisplay::get_source_page( $id );
		}
		return $form;
	}

	/** Guard the separate resume-email path before it can send notifications. */
	public function guard_resume_email(): void {
		if ( isset( $_POST['gform_send_resume_link'] ) && ! is_scalar( $_POST['gform_send_resume_link'] ) ) {
			// GF casts non-empty arrays to form ID 1; malformed IDs must not skip protection.
			$_POST['gform_resume_email'] = '';
			return;
		}
		$id = isset( $_POST['gform_send_resume_link'] ) && is_scalar( $_POST['gform_send_resume_link'] ) ? absint( $_POST['gform_send_resume_link'] ) : 0;
		if ( ! $id || isset( $this->resume_emails[ $id ] ) || ! Challenge::instance()->active() ) {
			return;
		}
		$form = \GFAPI::get_form( $id );
		if ( ! is_array( $form ) || ! $this->protected( $form ) || Challenge::instance()->verify( 'gf_' . $id ) ) {
			return;
		}
		$this->resume_emails[ $id ] = isset( $_POST['gform_resume_email'] ) && is_string( $_POST['gform_resume_email'] ) ? sanitize_email( wp_unslash( $_POST['gform_resume_email'] ) ) : '';
		// GF has no resume-email validation filter. An empty email prevents both sending
		// and draft mutation, and makes GF re-render its existing resume form.
		$_POST['gform_resume_email'] = '';
	}

	/**
	 * Give intermediate pages a widget for saving before the final-page widget exists.
	 *
	 * @param string $button Save button markup.
	 * @param array  $form   Form metadata.
	 * @return string Protected save control.
	 */
	public function save_button( string $button, array $form ): string {
		if ( '' === trim( $button ) || str_contains( $button, '_footer' ) || ! Challenge::instance()->active() || ! $this->protected( $form ) ) {
			return $button;
		}
		return Challenge::instance()->driver()->render( 'gf_' . (int) $form['id'] ) . $button;
	}

	/**
	 * Add a fresh challenge to the resume-email form, including validation retries.
	 *
	 * @param string $markup Save confirmation containing the resume form.
	 * @param array  $form   Form metadata.
	 * @return string Updated confirmation markup.
	 */
	public function resume_widget( string $markup, array $form ): string {
		if ( ! Challenge::instance()->active() || ! $this->protected( $form ) ) {
			return $markup;
		}
		Challenge::instance()->enqueue();
		$id     = (int) $form['id'];
		$widget = Challenge::instance()->driver()->render( 'gf_' . $id );
		if ( isset( $this->resume_emails[ $id ] ) ) {
			$markup = preg_replace( '~<div\b[^>]*\bid=([\'\"])email-validation-error\1[^>]*>.*?</div>~s', '', $markup );
			$tags   = new \WP_HTML_Tag_Processor( $markup );
			while ( $tags->next_tag( 'INPUT' ) ) {
				if ( 'gform_resume_email' === $tags->get_attribute( 'name' ) ) {
					$tags->set_attribute( 'value', $this->resume_emails[ $id ] );
				}
			}
			$markup = $tags->get_updated_html();
			$widget = $this->error_markup() . $widget;
		}
		return preg_replace_callback( '~<(?:input|button)\b[^>]*\bname=([\'\"])gform_send_resume_link_button\1[^>]*>~i', static fn( $matches ) => $widget . $matches[0], $markup );
	}

	/**
	 * Explain automatic-widget and draft-save failures in GF's validation summary.
	 *
	 * @param string $message Existing validation summary.
	 * @param array  $form    Form metadata.
	 * @return string Validation summary with an actionable error.
	 */
	public function validation_message( string $message, array $form ): string {
		return ! empty( $this->failed[ (int) $form['id'] ] ) ? $message . $this->error_markup() : $message;
	}

	/** Return an accessible server-side verification error. */
	private function error_markup(): string {
		return '<p class="form-challenge-error gfield_validation_message" role="alert" tabindex="-1">' . esc_html__( 'Verification failed. Please complete verification and try again.', 'challenge' ) . '</p>';
	}
}
