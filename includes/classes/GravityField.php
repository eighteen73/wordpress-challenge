<?php
/**
 * Optional inline field in the Gravity Forms editor.
 *
 * @package Eighteen73\Challenge
 */

namespace Eighteen73\Challenge;

defined( 'ABSPATH' ) || exit;

/** Editor field for explicit inline placement. */
class GravityField extends \GF_Field {
	/**
	 * Gravity Forms field type.
	 *
	 * @var string
	 */
	public $type = 'form_challenge';

	/** Return the editor field title. */
	public function get_form_editor_field_title(): string {
		return __( 'Challenge', 'challenge' );
	}

	/** Return the editor field button. */
	public function get_form_editor_button(): array {
		return [
			'group' => 'advanced_fields',
			'text'  => $this->get_form_editor_field_title(),
		];
	}

	/** Return the available field controls. */
	public function get_form_editor_field_settings(): array {
		return [ 'label_setting' ];
	}

	/**
	 * Hide the redundant frontend field label; keep it in the editor.
	 *
	 * @param bool  $force_frontend_label Whether Gravity Forms requires the label.
	 * @param mixed $value                Current field value.
	 * @return string The field label in the editor, otherwise empty.
	 */
	public function get_field_label( $force_frontend_label = true, $value = '' ): string {
		if ( $this->is_form_editor() || $this->is_entry_detail() ) {
			return (string) parent::get_field_label( $force_frontend_label, $value );
		}

		return '';
	}

	/**
	 * Render the selected driver's widget in the field's position.
	 *
	 * @param array $form  Gravity Forms form.
	 * @param mixed $value Existing field value.
	 * @param mixed $entry Existing entry.
	 * @return string Widget markup.
	 */
	public function get_field_input( $form, $value = '', $entry = null ): string {
		if ( $this->is_form_editor() || ! Challenge::instance()->active() ) {
			return '';
		}
		return Challenge::instance()->driver()->render( 'gf_' . (int) $form['id'], true );
	}
}
