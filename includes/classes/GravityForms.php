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
			$result['is_valid'] = false;
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
}
