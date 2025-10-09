<?php

/**
 * The class allows to easily adding options page
 * to the WordPress plugins, themes, etc.
 * Example of usage see in the readme.txt
 *
 * PHP: 7.1+
 *
 * @changlog https://github.com/makaravich/wptOptions/blob/main/changelog.md
 *
 * @version 0.0.5
 *
 */

class WPT_Options {
	/**
	 * @var array
	 */
	protected array $model;

	/**
	 * @var bool
	 */
	protected bool $model_from_file = false;

	public function __construct( $model = [] ) {

		if ( is_string( $model ) && file_exists( $model ) ) {
			$model                 = json_decode( file_get_contents( $model ), true );
			$this->model_from_file = true;
		}

		$this->model = $model;

		if ( isset( $model['tabs'] ) ) {
			$this->settings_page_with_tabs();
		} else {
			$this->single_settings_page();
		}

	}

	public function settings_page_with_tabs(): void {
		// The Tabs feature did not implement yet
		// @todo Tab support
	}

	/**
	 * Adds a settings page
	 *
	 * @param $model
	 *
	 * @return void
	 */
	public function single_settings_page(): void {

		add_action( 'admin_menu', [ $this, 'add_options_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings_page' ] );
	}

	/**
	 * Defines the options page
	 *
	 * @return void
	 */
	public function add_options_page(): void {
		add_submenu_page(
			'options-general.php',
			$this->model['page_title'],
			$this->model['menu_title'],
			'manage_options',
			$this->model['id'] . '-options',
			[ $this, 'options_page_output' ]
		);
	}

	/**
	 * Renders the options page
	 *
	 * @return void
	 */
	public function options_page_output(): void {
		?>
		<div class="wrap">
			<h2><?php echo get_admin_page_title() ?></h2>

			<form action="options.php" method="POST" class="wpt-form">
				<?php
				settings_fields( $this->model['id'] . '_options_group' ); // hidden protection fields
				do_settings_sections( $this->model['id'] . '_settings_page' ); // Sections with options. We have only single 'woi_section_general'
				submit_button( $this->model['save_button'] ?? __( 'Save', 'wpt' ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Register Settings.
	 * All options will be stored in Array. Not one value - one option
	 */
	function register_settings_page(): void {
		foreach ( $this->model['groups'] as $group_id => $group ) {
			//Whole Setting's Group
			register_setting(
				$this->model['id'] . '_options_group',
				$this->model['id'],
				'sanitize_callback'
			);
			foreach ( $group['sections'] as $section_id => $section ) {
				//Register Sections
				add_settings_section(
					$this->model['id'] . '_' . $group_id . '_' . $section_id . '_section',
					$section['title'],
					'',
					$this->model['id'] . '_settings_page'
				);
				foreach ( $section['fields'] as $field_id => $field ) {
					// Fields for General Settings section
					add_settings_field(
						$field_id,
						$field['title'],
						[ $this, 'fill_settings_field' ],
						$this->model['id'] . '_settings_page',
						$this->model['id'] . '_' . $group_id . '_' . $section_id . '_section',
						[
							'id'         => $field_id,
							'long_id'    => $group_id . '_' . $section_id . '_' . $field_id,
							'type'       => $field['type'] ?? 'text',
							'attributes' => $field['attributes'] ?? null,
							'options'    => $field['options'] ?? [],
						]
					);

				}
			}
		}
	}

	/**
	 * Call different methods of render field depends on field type
	 *
	 * @param $args
	 */
	public function fill_settings_field( $args ): void {
		$type_func = 'fill_' . strtolower( $args['type'] );

		if ( is_callable( [ $this, $type_func ] ) ) {
			call_user_func( [ $this, $type_func ], $args );
		}
	}

	/**
	 * Renders field with text input
	 *
	 * @param $args
	 */
	private function fill_text( $args ): void {

		$val = $this->get_option( $args['long_id'] );
		?>
		<input class="<?php echo $this->model['id'] ?>" type="text"
		       name="<?php echo $this->model['id'] ?>[<?php echo $args['long_id'] ?>]"
		       value="<?php echo esc_attr( $val ) ?>"/>
		<?php
	}

	/**
	 * Renders field with text area
	 *
	 * @param $args
	 */
	private function fill_textarea( $args ): void {

		$val        = $this->get_option( $args['long_id'] );
		$attributes = $args['attributes'] ?? '';
		?>
		<textarea class="<?php echo $this->model['id'] ?>-input" type="text" <?php echo $attributes ?>
                  name="<?php echo $this->model['id'] ?>[<?php echo $args['long_id'] ?>]"><?php echo esc_attr( $val ) ?></textarea>
		<?php
	}

	/**
	 * Renders field with WP Editor
	 *
	 * @param $args
	 */
	private function fill_wp_editor( $args ): void {

		$val        = $this->get_option( $args['long_id'] );
		$attributes = $args['attributes'] ?? '';

		$params = [
			'wpautop'          => 1,
			'media_buttons'    => 1,
			'textarea_name'    => $this->model['id'] . '[' . $args['long_id'] . ']',
			'textarea_rows'    => 20,
			'tabindex'         => null,
			'editor_css'       => '',
			'editor_class'     => '',
			'teeny'            => 0,
			'dfw'              => 0,
			'tinymce'          => 1,
			'quicktags'        => 1,
			'drag_drop_upload' => false,
		];

		wp_editor( $val, wp_unique_id( $this->model['id'] ), $params );

	}

	/**
	 * Renders field with email
	 *
	 * @param $args
	 */
	private function fill_email( $args ): void {
		$val = $this->get_option( $args['long_id'] );
		?>
		<input class="<?php echo $this->model['id'] ?>" type="email"
		       name="<?php echo $this->model['id'] ?>[<?php echo $args['long_id'] ?>]"
		       value="<?php echo esc_attr( $val ) ?>"/>
		<?php
	}

	/**
	 * Renders field with select
	 *
	 * @param $args
	 */
	private function fill_select( $args ): void {

		$val        = $this->get_option( $args['long_id'] );
		$attributes = $args['attributes'] ?? '';
		$options    = $args['options'] ?? [];

		?>
		<select class="<?php echo $this->model['id'] ?>-select" <?php echo $attributes ?>
		        name="<?php echo $this->model['id'] ?>[<?php echo $args['long_id'] ?>]">
			<?php
			foreach ( $options as $key => $option ) {
				$selected = $key == $val ? ' selected="selected" ' : '';
				?>
				<option value="<?php echo $key ?>" <?php echo $selected ?>>
					<?php echo $option ?>
				</option>
				<?php
			}
			?>

		</select>
		<?php
	}

	/**
	 * Renders field with checkbox
	 *
	 * @param $args
	 */
	private function fill_checkbox( $args ): void {
		$val = $this->get_option( $args['long_id'] );
		?>
		<input class="<?php echo $this->model['id'] ?>-input" type="checkbox"
		       name="<?php echo $this->model['id'] ?>[<?php echo $args['long_id'] ?>]" <?php echo checked( 'on', $val ) ?> />
		<?php
	}

	/**
	 * Renders field with password input
	 *
	 * @param $args
	 */
	private function fill_password( $args ): void {

		$val = $this->get_option( $args['long_id'] );
		?>
		<input class="<?php echo $this->model['id'] ?>-input" type="password"
		       name="<?php echo $this->model['id'] ?>[<?php echo $args['long_id'] ?>]"
		       value="<?php echo esc_attr( $val ) ?>"/>
		<?php
	}

	/**
	 * Returns an option with name, passed in $option
	 *
	 * @param $option
	 *
	 * @return mixed|null
	 */
	public function get_option( $option ): mixed {
		$val = get_option( $this->model['id'] );

		return $val[ $option ] ?? null;
	}


}