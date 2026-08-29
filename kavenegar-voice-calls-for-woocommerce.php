<?php
/**
 * Plugin Name:       Kavenegar Voice Calls for WooCommerce
 * Plugin URI:        https://github.com/amirrezashf/Kavenegar-Voice-Calls-for-WooCommerce
 * Description:       Send configurable Kavenegar voice verification calls when WooCommerce order statuses change, with per-status templates, token mapping, HPOS support, audit logs, and per-order call results.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Amirreza Shayesteh Far
 * Author URI:        https://github.com/amirrezashf
 * License:           GPL-3.0
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       kavenegar-voice-calls-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WCKVC_Order_Voice_Status_Call' ) ) :

final class WCKVC_Order_Voice_Status_Call {

	const OPTION_KEY       = 'wckvc_order_voice_status_settings';
	const API_KEY_OPTION   = 'wckvc_kavenegar_api_key';
	const OPTION_VERSION   = 'wckvc_order_voice_status_db_version';
	const DB_VERSION       = '1.5.0';
	const TABLE_SUFFIX     = 'wckvc_order_voice_logs';
	const PER_PAGE         = 20;
	const META_LAST_RESULT = '_wckvc_last_voice_call_result';

	public function __construct() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'maybe_show_missing_woocommerce_notice' ) );
		add_action( 'init', array( $this, 'maybe_install' ) );

		add_action( 'admin_menu', array( $this, 'register_admin_pages' ), 99 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_footer', array( $this, 'render_order_voice_box_in_footer' ) );

		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_changed' ), 20, 4 );

		add_action( 'add_meta_boxes', array( $this, 'register_order_metaboxes' ) );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', array( $this, 'register_hpos_order_metaboxes' ) );

		add_action( 'admin_notices', array( $this, 'maybe_show_admin_notice' ) );
	}

	public function declare_hpos_compatibility() {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}

	public function maybe_show_missing_woocommerce_notice() {
		if ( class_exists( 'WooCommerce' ) || ! is_admin() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		add_action( 'admin_notices', static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Kavenegar Voice Calls for WooCommerce requires WooCommerce to be installed and active.', 'kavenegar-voice-calls-for-woocommerce' ) . '</p></div>';
		} );
	}

	/*--------------------------------------------------------------*/
	/* API Key
	/*--------------------------------------------------------------*/

	private function get_kavenegar_api_key() {
		$key = get_option( self::API_KEY_OPTION, '' );
		$key = is_string( $key ) ? trim( $key ) : '';
		return preg_replace( '/\s+/u', '', $key );
	}

	private function has_valid_api_key() {
		$key = $this->get_kavenegar_api_key();

		if ( '' === $key ) {
			return false;
		}

		if ( 'YOUR_API_KEY_HERE' === $key || 'PASTE_YOUR_REAL_KAVENEGAR_API_KEY_HERE' === $key ) {
			return false;
		}

		return (bool) preg_match( '/^[A-Fa-f0-9]{32,}$/', $key );
	}

	/*--------------------------------------------------------------*/
	/* Helpers
	/*--------------------------------------------------------------*/

	private function current_user_can_manage_page() {
		return current_user_can( 'manage_woocommerce' );
	}

	private function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	private function get_now_mysql() {
		return current_time( 'mysql' );
	}

	private function format_wp_local_datetime( $mysql_datetime ) {
		if ( empty( $mysql_datetime ) || ! is_string( $mysql_datetime ) ) {
			return '';
		}

		$tz = wp_timezone();
		$dt = date_create_from_format( 'Y-m-d H:i:s', $mysql_datetime, $tz );

		if ( ! $dt ) {
			return $mysql_datetime;
		}

		return wp_date( 'j F Y - H:i', $dt->getTimestamp(), $tz );
	}

	private function get_order_edit_link( $order_id ) {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . absint( $order_id ) );
		}
		return admin_url( 'post.php?post=' . absint( $order_id ) . '&action=edit' );
	}

	private function get_statuses_with_slug() {
		$statuses = wc_get_order_statuses();
		$out      = array();

		foreach ( $statuses as $key => $label ) {
			$slug         = ( 0 === strpos( $key, 'wc-' ) ) ? substr( $key, 3 ) : $key;
			$out[ $slug ] = $label;
		}

		return $out;
	}

	private function get_status_label( $status_slug ) {
		$statuses = wc_get_order_statuses();
		$key      = 'wc-' . $status_slug;

		return isset( $statuses[ $key ] ) ? $statuses[ $key ] : $status_slug;
	}

	private function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );
		return is_array( $settings ) ? $settings : array();
	}

	private function get_status_rule( $to_status ) {
		$settings = $this->get_settings();
		return isset( $settings[ $to_status ] ) && is_array( $settings[ $to_status ] ) ? $settings[ $to_status ] : array();
	}

	private function has_active_template_for_status( $status_slug ) {
		$rule = $this->get_status_rule( $status_slug );

		return ! empty( $rule['enabled'] ) && ! empty( $rule['template'] );
	}

	private function get_active_status_templates_map() {
		$settings = $this->get_settings();
		$out      = array();

		foreach ( $settings as $status_slug => $row ) {
			$enabled  = ! empty( $row['enabled'] );
			$template = isset( $row['template'] ) ? trim( (string) $row['template'] ) : '';

			$out[ $status_slug ] = array(
				'enabled'  => $enabled ? 1 : 0,
				'template' => $template,
				'has_rule' => ( $enabled && '' !== $template ) ? 1 : 0,
			);
		}

		return $out;
	}

	private function get_token_sources() {
		return array(
			''                    => '— انتخاب کنید —',
			'order_id'            => 'شناسه سفارش',
			'order_number'        => 'شماره سفارش',
			'status_label'        => 'عنوان وضعیت جدید',
			'customer_first_name' => 'نام مشتری',
			'customer_last_name'  => 'نام خانوادگی مشتری',
			'customer_full_name'  => 'نام و نام خانوادگی مشتری',
			'billing_phone'       => 'شماره موبایل',
			'billing_email'       => 'ایمیل',
			'order_total'         => 'مبلغ سفارش',
			'site_name'           => 'نام سایت',
		);
	}

	private function get_current_order_for_admin_screen() {
		if ( ! is_admin() ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return false;
		}

		if ( isset( $_GET['id'] ) ) {
			$order = wc_get_order( absint( $_GET['id'] ) );
			if ( $order ) {
				return $order;
			}
		}

		if ( isset( $_GET['post'] ) ) {
			$order = wc_get_order( absint( $_GET['post'] ) );
			if ( $order ) {
				return $order;
			}
		}

		global $post;
		if ( is_object( $post ) && ! empty( $post->ID ) ) {
			$order = wc_get_order( $post->ID );
			if ( $order ) {
				return $order;
			}
		}

		return false;
	}

	private function get_user_display_name( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return 'نامشخص';
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return 'نامشخص';
		}

		if ( ! empty( $user->display_name ) ) {
			return $user->display_name;
		}

		return ! empty( $user->user_login ) ? $user->user_login : 'نامشخص';
	}

	private function get_recent_logs_for_order( $order_id, $limit = 10 ) {
		global $wpdb;

		$order_id = absint( $order_id );
		$limit    = max( 1, absint( $limit ) );

		if ( ! $order_id ) {
			return array();
		}

		$table = $this->get_table_name();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE order_id = %d ORDER BY id DESC LIMIT %d",
				$order_id,
				$limit
			)
		);
	}

	private function is_duplicate_recent_log( $data, $within_seconds = 120 ) {
		global $wpdb;

		$within_seconds = max( 30, absint( $within_seconds ) );
		$table          = $this->get_table_name();

		$order_id      = isset( $data['order_id'] ) ? absint( $data['order_id'] ) : 0;
		$from_status   = isset( $data['from_status'] ) ? sanitize_text_field( $data['from_status'] ) : '';
		$to_status     = isset( $data['to_status'] ) ? sanitize_text_field( $data['to_status'] ) : '';
		$created_by    = isset( $data['created_by'] ) ? absint( $data['created_by'] ) : 0;
		$success       = ! empty( $data['success'] ) ? 1 : 0;
		$template_name = isset( $data['template_name'] ) ? sanitize_text_field( $data['template_name'] ) : '';
		$error_text    = isset( $data['error_text'] ) ? sanitize_text_field( $data['error_text'] ) : '';

		if ( ! $order_id || '' === $to_status ) {
			return false;
		}

		$sql = $wpdb->prepare(
			"SELECT id
			 FROM {$table}
			 WHERE order_id = %d
			   AND from_status = %s
			   AND to_status = %s
			   AND created_by = %d
			   AND success = %d
			   AND template_name = %s
			   AND error_text = %s
			   AND created_at >= DATE_SUB(%s, INTERVAL %d SECOND)
			 ORDER BY id DESC
			 LIMIT 1",
			$order_id,
			$from_status,
			$to_status,
			$created_by,
			$success,
			$template_name,
			$error_text,
			$this->get_now_mysql(),
			$within_seconds
		);

		$found_id = $wpdb->get_var( $sql );

		return ! empty( $found_id );
	}

	/*--------------------------------------------------------------*/
	/* Install DB
	/*--------------------------------------------------------------*/

	public function maybe_install() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		if ( get_option( self::OPTION_VERSION ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		$table_name      = $this->get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_number varchar(50) NOT NULL DEFAULT '',
			from_status varchar(100) NOT NULL DEFAULT '',
			to_status varchar(100) NOT NULL DEFAULT '',
			phone varchar(30) NOT NULL DEFAULT '',
			template_name varchar(190) NOT NULL DEFAULT '',
			token varchar(255) NOT NULL DEFAULT '',
			token10 varchar(255) NOT NULL DEFAULT '',
			token20 varchar(255) NOT NULL DEFAULT '',
			local_id varchar(190) NOT NULL DEFAULT '',
			api_http_code int(11) NOT NULL DEFAULT 0,
			api_status int(11) NOT NULL DEFAULT 0,
			api_message varchar(255) NOT NULL DEFAULT '',
			api_message_id varchar(100) NOT NULL DEFAULT '',
			api_statustext varchar(255) NOT NULL DEFAULT '',
			success tinyint(1) NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			error_text longtext NULL,
			raw_response longtext NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY success (success),
			KEY created_at (created_at),
			KEY to_status (to_status),
			KEY template_name (template_name)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::OPTION_VERSION, self::DB_VERSION );
	}

	/*--------------------------------------------------------------*/
	/* Settings
	/*--------------------------------------------------------------*/

	public function register_settings() {
		register_setting(
			'wckvc_order_voice_status_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);

		register_setting(
			'wckvc_order_voice_status_group',
			self::API_KEY_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_api_key' ),
				'default'           => '',
			)
		);
	}

	public function sanitize_api_key( $value ) {
		if ( ! $this->current_user_can_manage_page() ) {
			return $this->get_kavenegar_api_key();
		}
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = preg_replace( '/\s+/u', '', trim( $value ) );
		return sanitize_text_field( $value );
	}

	public function sanitize_settings( $input ) {
		$output = array();

		if ( ! $this->current_user_can_manage_page() ) {
			return $this->get_settings();
		}

		$statuses = $this->get_statuses_with_slug();

		foreach ( $statuses as $slug => $label ) {
			$row = isset( $input[ $slug ] ) && is_array( $input[ $slug ] ) ? $input[ $slug ] : array();

			$output[ $slug ] = array(
				'enabled'        => ! empty( $row['enabled'] ) ? 1 : 0,
				'template'       => isset( $row['template'] ) ? sanitize_text_field( $row['template'] ) : '',
				'token_source'   => isset( $row['token_source'] ) ? sanitize_key( $row['token_source'] ) : '',
				'token10_source' => isset( $row['token10_source'] ) ? sanitize_key( $row['token10_source'] ) : '',
				'token20_source' => isset( $row['token20_source'] ) ? sanitize_key( $row['token20_source'] ) : '',
			);
		}

		add_settings_error( self::OPTION_KEY, 'saved', 'تنظیمات ذخیره شد.', 'updated' );
		return $output;
	}

	/*--------------------------------------------------------------*/
	/* Admin Pages
	/*--------------------------------------------------------------*/

	public function register_admin_pages() {
		if ( ! $this->current_user_can_manage_page() ) {
			return;
		}

		add_menu_page(
			'تماس صوتی وضعیت سفارش',
			'تماس صوتی سفارش',
			'manage_woocommerce',
			'wckvc-order-voice-status',
			array( $this, 'render_settings_page' ),
			'dashicons-megaphone',
			56
		);

		add_submenu_page(
			'wckvc-order-voice-status',
			'تماس صوتی وضعیت سفارش',
			'تنظیمات وضعیت‌ها',
			'manage_woocommerce',
			'wckvc-order-voice-status',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'wckvc-order-voice-status',
			'لاگ تماس های سفارشات',
			'لاگ تماس ها',
			'manage_woocommerce',
			'wckvc-order-voice-logs',
			array( $this, 'render_logs_page' )
		);
	}

	public function render_settings_page() {
		if ( ! $this->current_user_can_manage_page() ) {
			wp_die( 'شما دسترسی لازم را ندارید.' );
		}

		$statuses      = $this->get_statuses_with_slug();
		$settings      = $this->get_settings();
		$token_sources = $this->get_token_sources();
		$debug_key     = $this->get_kavenegar_api_key();
		?>
		<div class="wrap">
			<h1>تماس صوتی وضعیت سفارش</h1>

			 settings_errors( self::OPTION_KEY ); ?>

			<div class="notice notice-info">
				<p><strong>وضعیت کلید API:</strong> <?php echo $this->has_valid_api_key() ? 'ثبت شده' : 'ثبت نشده یا نامعتبر'; ?></p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'wckvc_order_voice_status_group' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wckvc_kavenegar_api_key">Kavenegar API Key</label></th>
						<td>
							<input type="password" class="regular-text" autocomplete="new-password" id="wckvc_kavenegar_api_key" name="<?php echo esc_attr( self::API_KEY_OPTION ); ?>" value="<?php echo esc_attr( $debug_key ); ?>">
							<p class="description">کلید API در تنظیمات وردپرس ذخیره می‌شود و داخل فایل افزونه Hardcode نیست.</p>
						</td>
					</tr>
				</table>

				<table class="widefat striped" style="margin-top:15px;">
					<thead>
						<tr>
							<th style="width:180px;">وضعیت سفارش</th>
							<th style="width:90px;">فعال</th>
							<th style="width:220px;">شناسه اعتبارسنجی</th>
							<th style="width:190px;">منبع token</th>
							<th style="width:190px;">منبع token10</th>
							<th style="width:190px;">منبع token20</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $statuses as $slug => $label ) : ?>
						<?php $row = isset( $settings[ $slug ] ) ? $settings[ $slug ] : array(); ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $label ); ?></strong>
								<div style="opacity:.7;margin-top:4px;"><code><?php echo esc_html( $slug ); ?></code></div>
							</td>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $slug ); ?>][enabled]" value="1" <?php checked( ! empty( $row['enabled'] ) ); ?>>
									فعال
								</label>
							</td>
							<td>
								<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $slug ); ?>][template]" value="<?php echo esc_attr( isset( $row['template'] ) ? $row['template'] : '' ); ?>" placeholder="مثلاً NewVoice">
							</td>
							<td>
								<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $slug ); ?>][token_source]">
									<?php foreach ( $token_sources as $value => $label_item ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( isset( $row['token_source'] ) ? $row['token_source'] : '', $value ); ?>><?php echo esc_html( $label_item ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $slug ); ?>][token10_source]">
									<?php foreach ( $token_sources as $value => $label_item ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( isset( $row['token10_source'] ) ? $row['token10_source'] : '', $value ); ?>><?php echo esc_html( $label_item ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $slug ); ?>][token20_source]">
									<?php foreach ( $token_sources as $value => $label_item ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( isset( $row['token20_source'] ) ? $row['token20_source'] : '', $value ); ?>><?php echo esc_html( $label_item ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button( 'ذخیره تنظیمات' ); ?>
			</form>
		</div>
		<?php
	}

	public function render_logs_page() {
		if ( ! $this->current_user_can_manage_page() ) {
			wp_die( 'شما دسترسی لازم را ندارید.' );
		}

		global $wpdb;
		$table = $this->get_table_name();

		$current_page = isset( $_GET['paged_no'] ) ? max( 1, absint( $_GET['paged_no'] ) ) : 1;
		$per_page     = self::PER_PAGE;
		$offset       = ( $current_page - 1 ) * $per_page;

		$filters = array(
			'order_id'   => isset( $_GET['f_order_id'] ) ? absint( $_GET['f_order_id'] ) : 0,
			'phone'      => isset( $_GET['f_phone'] ) ? sanitize_text_field( wp_unslash( $_GET['f_phone'] ) ) : '',
			'template'   => isset( $_GET['f_template'] ) ? sanitize_text_field( wp_unslash( $_GET['f_template'] ) ) : '',
			'to_status'  => isset( $_GET['f_to_status'] ) ? sanitize_key( wp_unslash( $_GET['f_to_status'] ) ) : '',
			'success'    => isset( $_GET['f_success'] ) ? sanitize_text_field( wp_unslash( $_GET['f_success'] ) ) : '',
		);

		$where  = array( '1=1' );
		$params = array();

		if ( $filters['order_id'] ) {
			$where[]  = 'order_id = %d';
			$params[] = $filters['order_id'];
		}

		if ( '' !== $filters['phone'] ) {
			$where[]  = 'phone LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $filters['phone'] ) . '%';
		}

		if ( '' !== $filters['template'] ) {
			$where[]  = 'template_name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $filters['template'] ) . '%';
		}

		if ( '' !== $filters['to_status'] ) {
			$where[]  = 'to_status = %s';
			$params[] = $filters['to_status'];
		}

		if ( '0' === $filters['success'] || '1' === $filters['success'] ) {
			$where[]  = 'success = %d';
			$params[] = absint( $filters['success'] );
		}

		$where_sql   = implode( ' AND ', $where );
		$count_sql   = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total_items = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : (int) $wpdb->get_var( $count_sql );

		$list_sql      = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$list_params   = $params;
		$list_params[] = $per_page;
		$list_params[] = $offset;

		$rows        = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) );
		$total_pages = max( 1, (int) ceil( $total_items / $per_page ) );
		$statuses    = $this->get_statuses_with_slug();

		$base_url_args = array(
			'page'        => 'wckvc-order-voice-logs',
			'f_order_id'  => $filters['order_id'] ? $filters['order_id'] : null,
			'f_phone'     => '' !== $filters['phone'] ? $filters['phone'] : null,
			'f_template'  => '' !== $filters['template'] ? $filters['template'] : null,
			'f_to_status' => '' !== $filters['to_status'] ? $filters['to_status'] : null,
			'f_success'   => '' !== $filters['success'] ? $filters['success'] : null,
		);
		?>
		<div class="wrap">
			<h1>لاگ تماس های سفارشات</h1>

			<form method="get" style="margin:15px 0 20px;">
				<input type="hidden" name="page" value="wckvc-order-voice-logs">

				<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
					<div>
						<label style="display:block;margin-bottom:4px;">شناسه سفارش</label>
						<input type="number" name="f_order_id" value="<?php echo esc_attr( $filters['order_id'] ); ?>" class="small-text">
					</div>

					<div>
						<label style="display:block;margin-bottom:4px;">شماره موبایل</label>
						<input type="text" name="f_phone" value="<?php echo esc_attr( $filters['phone'] ); ?>" class="regular-text">
					</div>

					<div>
						<label style="display:block;margin-bottom:4px;">شناسه اعتبارسنجی</label>
						<input type="text" name="f_template" value="<?php echo esc_attr( $filters['template'] ); ?>" class="regular-text">
					</div>

					<div>
						<label style="display:block;margin-bottom:4px;">وضعیت مقصد</label>
						<select name="f_to_status">
							<option value="">همه</option>
							<?php foreach ( $statuses as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['to_status'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div>
						<label style="display:block;margin-bottom:4px;">نتیجه</label>
						<select name="f_success">
							<option value="">همه</option>
							<option value="1" <?php selected( $filters['success'], '1' ); ?>>موفق</option>
							<option value="0" <?php selected( $filters['success'], '0' ); ?>>ناموفق</option>
						</select>
					</div>

					<div>
						<?php submit_button( 'فیلتر', 'secondary', '', false ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wckvc-order-voice-logs' ) ); ?>" class="button" style="margin-right:6px;">پاک کردن فیلترها</a>
					</div>
				</div>
			</form>

			<p style="margin-bottom:12px;">
				<strong>تعداد نتایج:</strong> <?php echo esc_html( number_format_i18n( $total_items ) ); ?>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>زمان</th>
						<th>سفارش</th>
						<th>تغییر وضعیت</th>
						<th>شماره</th>
						<th>الگو</th>
						<th>توکن‌ها</th>
						<th>ثبت‌کننده</th>
						<th>نتیجه</th>
						<th>جزئیات</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! empty( $rows ) ) : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $this->format_wp_local_datetime( $row->created_at ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( $this->get_order_edit_link( $row->order_id ) ); ?>" target="_blank" rel="noopener">
									#<?php echo esc_html( $row->order_number ? $row->order_number : $row->order_id ); ?>
								</a>
							</td>
							<td>
								<?php echo esc_html( $this->get_status_label( $row->from_status ) ); ?>
								<span style="opacity:.6;">←</span>
								<?php echo esc_html( $this->get_status_label( $row->to_status ) ); ?>
							</td>
							<td><?php echo esc_html( $row->phone ); ?></td>
							<td><code><?php echo esc_html( $row->template_name ); ?></code></td>
							<td>
								<div><strong>token:</strong> <?php echo esc_html( $row->token ); ?></div>
								<div><strong>token10:</strong> <?php echo esc_html( $row->token10 ); ?></div>
								<div><strong>token20:</strong> <?php echo esc_html( $row->token20 ); ?></div>
							</td>
							<td><?php echo esc_html( $this->get_user_display_name( $row->created_by ) ); ?></td>
							<td>
								<?php if ( ! empty( $row->success ) ) : ?>
									<span style="color:#0f7a2a;font-weight:700;">موفق</span>
								<?php else : ?>
									<span style="color:#b42318;font-weight:700;">ناموفق</span>
								<?php endif; ?>
							</td>
							<td>
								<div><strong>HTTP:</strong> <?php echo esc_html( $row->api_http_code ); ?></div>
								<div><strong>API:</strong> <?php echo esc_html( $row->api_status ); ?></div>
								<div><strong>پیام:</strong> <?php echo esc_html( $row->api_message ); ?></div>
								<?php if ( ! empty( $row->api_message_id ) ) : ?>
									<div><strong>MessageID:</strong> <?php echo esc_html( $row->api_message_id ); ?></div>
								<?php endif; ?>
								<?php if ( ! empty( $row->error_text ) ) : ?>
									<div style="margin-top:6px;color:#b42318;"><?php echo esc_html( $row->error_text ); ?></div>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="9">موردی پیدا نشد.</td>
					</tr>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav" style="margin-top:14px;">
					<div class="tablenav-pages">
						<?php
						echo paginate_links( array(
							'base'      => add_query_arg( array_merge( $base_url_args, array( 'paged_no' => '%#%' ) ), admin_url( 'admin.php' ) ),
							'format'    => '',
							'current'   => $current_page,
							'total'     => $total_pages,
							'prev_text' => '«',
							'next_text' => '»',
						) );
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/*--------------------------------------------------------------*/
	/* Order page dynamic box
	/*--------------------------------------------------------------*/

	public function render_order_voice_box_in_footer() {
		if ( ! is_admin() || ! $this->current_user_can_manage_page() ) {
			return;
		}

		$order = $this->get_current_order_for_admin_screen();
		if ( ! $order ) {
			return;
		}
		?>
		<div id="wckvc-voice-order-box" style="display:none;">
			<div class="wckvc-voice-order-box__inner">
				<div class="wckvc-voice-order-box__title">ارسال پیام صوتی</div>

				<div class="wckvc-voice-order-box__enabled" style="display:none;">
					<label class="wckvc-voice-order-box__checkbox-row">
						<input type="checkbox" id="wckvc_send_voice_status_call" name="wckvc_send_voice_status_call" value="1" checked="checked">
						<span>برای وضعیت جدید، تماس صوتی ارسال شود</span>
					</label>
				</div>

				<div class="wckvc-voice-order-box__disabled" style="display:none;">
					برای این وضعیت در حال حاضر تماس صوتی ارسال نمیشود
				</div>

				<input type="hidden" id="wckvc_current_order_status" value="<?php echo esc_attr( $order->get_status() ); ?>">
				<?php wp_nonce_field( 'wckvc_send_voice_status_call_nonce', 'wckvc_send_voice_status_call_nonce' ); ?>
			</div>
		</div>
		<?php
	}

	public function enqueue_admin_assets( $hook ) {
		if ( ! is_admin() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}

		$status_rules = $this->get_active_status_templates_map();

		$css = '
		#wckvc-voice-order-box{
			width:100%;
			clear:both;
			margin-top:12px;
		}
		#wckvc-voice-order-box .wckvc-voice-order-box__inner{
			display:block;
			width:100%;
			box-sizing:border-box;
			background:#f6f7f7;
			border:1px solid #dcdcde;
			border-radius:8px;
			padding:14px 16px;
		}
		#wckvc-voice-order-box .wckvc-voice-order-box__title{
			font-size:14px;
			font-weight:600;
			line-height:1.7;
			color:#1d2327;
			margin:0 0 10px 0;
		}
		#wckvc-voice-order-box .wckvc-voice-order-box__checkbox-row{
			display:inline-flex;
			align-items:center;
			gap:8px;
			margin:0;
			font-size:13px;
			line-height:1.8;
			color:#1d2327;
		}
		#wckvc-voice-order-box .wckvc-voice-order-box__checkbox-row input{
			margin:0;
		}
		#wckvc-voice-order-box .wckvc-voice-order-box__disabled{
			font-size:13px;
			line-height:1.8;
			color:#6b7280;
		}
		';

		wp_register_style( 'wckvc-order-voice-inline-style', false );
		wp_enqueue_style( 'wckvc-order-voice-inline-style' );
		wp_add_inline_style( 'wckvc-order-voice-inline-style', $css );

		wp_add_inline_script( 'jquery-core', 'window.wckvcVoiceStatusRules = ' . wp_json_encode( $status_rules ) . ';', 'before' );

		$js = <<<JS
jQuery(function($){
	function wckvcGetStatusField() {
		var \$select = $('#order_status');
		if (!\$select.length) {
			\$select = $('select[name="order_status"]');
		}
		return \$select;
	}

	function wckvcGetRule(status) {
		status = String(status || '').replace(/^wc-/, '');
		if (!window.wckvcVoiceStatusRules || !window.wckvcVoiceStatusRules[status]) {
			return null;
		}
		return window.wckvcVoiceStatusRules[status];
	}

	function wckvcGetAnchorField() {
		var \$select = wckvcGetStatusField();
		if (!\$select.length) return $();

		var \$field = \$select.closest('p.form-field, .form-field, .wc-order-status');
		if (!\$field.length) {
			\$field = \$select.parent();
		}
		return \$field;
	}

	function wckvcMoveVoiceBox() {
		var \$box = $('#wckvc-voice-order-box');
		var \$anchor = wckvcGetAnchorField();

		if (!\$box.length || !\$anchor.length) return;

		if (!\$box.prev().is(\$anchor)) {
			\$box.insertAfter(\$anchor);
		}
	}

	function wckvcToggleVoiceBox() {
		var \$box = $('#wckvc-voice-order-box');
		var \$enabled = \$box.find('.wckvc-voice-order-box__enabled');
		var \$disabled = \$box.find('.wckvc-voice-order-box__disabled');
		var \$checkbox = $('#wckvc_send_voice_status_call');
		var \$select = wckvcGetStatusField();

		if (!\$box.length || !\$select.length) return;

		var currentStatus  = String($('#wckvc_current_order_status').val() || '').replace(/^wc-/, '');
		var selectedStatus = String(\$select.val() || '').replace(/^wc-/, '');
		var changed        = currentStatus && selectedStatus && currentStatus !== selectedStatus;
		var rule           = wckvcGetRule(selectedStatus);

		wckvcMoveVoiceBox();

		if (!changed) {
			\$box.hide();
			\$checkbox.prop('checked', true);
			\$enabled.hide();
			\$disabled.hide();
			return;
		}

		\$box.show();

		if (rule && parseInt(rule.has_rule, 10) === 1) {
			\$enabled.show();
			\$disabled.hide();
		} else {
			\$enabled.hide();
			\$disabled.show();
			\$checkbox.prop('checked', true);
		}
	}

	$(document).on('change', '#order_status, select[name="order_status"]', wckvcToggleVoiceBox);

	setTimeout(wckvcToggleVoiceBox, 100);
	setTimeout(wckvcToggleVoiceBox, 400);
	setTimeout(wckvcToggleVoiceBox, 900);
	setTimeout(wckvcToggleVoiceBox, 1500);
});
JS;

		wp_add_inline_script( 'jquery-core', $js );
	}

	/*--------------------------------------------------------------*/
	/* Main process
	/*--------------------------------------------------------------*/

	public function handle_order_status_changed( $order_id, $from_status, $to_status, $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		$rule = $this->get_status_rule( $to_status );

		if ( empty( $rule['enabled'] ) || empty( $rule['template'] ) ) {
			$this->save_order_last_voice_result(
				$order,
				array(
					'success' => 0,
					'text'    => 'برای این وضعیت، تماس صوتی فعال نیست یا شناسه اعتبارسنجی تنظیم نشده است.',
					'time'    => $this->get_now_mysql(),
				)
			);
			return;
		}

		$should_send = true;

		if ( is_admin() && isset( $_POST['wckvc_send_voice_status_call_nonce'] ) ) {
			$nonce_ok = wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['wckvc_send_voice_status_call_nonce'] ) ),
				'wckvc_send_voice_status_call_nonce'
			);

			if ( $nonce_ok ) {
				$should_send = ! empty( $_POST['wckvc_send_voice_status_call'] );
			}
		}

		if ( ! $should_send ) {
			$text = 'ارسال تماس صوتی توسط همکار غیرفعال شد.';

			$log_data = array(
				'order_id'       => $order->get_id(),
				'order_number'   => $order->get_order_number(),
				'from_status'    => $from_status,
				'to_status'      => $to_status,
				'phone'          => (string) $order->get_billing_phone(),
				'template_name'  => isset( $rule['template'] ) ? $rule['template'] : '',
				'success'        => 0,
				'created_by'     => get_current_user_id(),
				'created_at'     => $this->get_now_mysql(),
				'error_text'     => $text,
			);

			if ( ! $this->is_duplicate_recent_log( $log_data ) ) {
				$this->insert_log( $log_data );
			}

			$this->save_order_last_voice_result(
				$order,
				array(
					'success' => 0,
					'text'    => $text,
					'time'    => $this->get_now_mysql(),
				)
			);
			return;
		}

		$phone = $order->get_billing_phone();
		$phone = $this->normalize_iran_mobile( $phone );

		if ( empty( $phone ) ) {
			$text = 'تماس صوتی ارسال نشد: شماره موبایل معتبر نیست.';

			$log_data = array(
				'order_id'       => $order->get_id(),
				'order_number'   => $order->get_order_number(),
				'from_status'    => $from_status,
				'to_status'      => $to_status,
				'phone'          => '',
				'template_name'  => $rule['template'],
				'success'        => 0,
				'created_by'     => get_current_user_id(),
				'created_at'     => $this->get_now_mysql(),
				'error_text'     => 'شماره موبایل سفارش معتبر نیست.',
			);

			if ( ! $this->is_duplicate_recent_log( $log_data ) ) {
				$this->insert_log( $log_data );
			}

			$this->save_order_last_voice_result(
				$order,
				array(
					'success' => 0,
					'text'    => $text,
					'time'    => $this->get_now_mysql(),
				)
			);
			return;
		}

		$token   = $this->sanitize_lookup_token( $this->resolve_token_source_value( isset( $rule['token_source'] ) ? $rule['token_source'] : '', $order, $to_status ), 'token' );
		$token10 = $this->sanitize_lookup_token( $this->resolve_token_source_value( isset( $rule['token10_source'] ) ? $rule['token10_source'] : '', $order, $to_status ), 'token10' );
		$token20 = $this->sanitize_lookup_token( $this->resolve_token_source_value( isset( $rule['token20_source'] ) ? $rule['token20_source'] : '', $order, $to_status ), 'token20' );

		$local_id = 'order-' . $order->get_id() . '-status-' . $to_status . '-time-' . time();

		$response = $this->send_lookup_call(
			array(
				'receptor' => $phone,
				'template' => $rule['template'],
				'token'    => $token,
				'token10'  => $token10,
				'token20'  => $token20,
			)
		);

		$log_data = array(
			'order_id'       => $order->get_id(),
			'order_number'   => $order->get_order_number(),
			'from_status'    => $from_status,
			'to_status'      => $to_status,
			'phone'          => $phone,
			'template_name'  => $rule['template'],
			'token'          => $token,
			'token10'        => $token10,
			'token20'        => $token20,
			'local_id'       => $local_id,
			'api_http_code'  => isset( $response['http_code'] ) ? absint( $response['http_code'] ) : 0,
			'api_status'     => isset( $response['api_status'] ) ? absint( $response['api_status'] ) : 0,
			'api_message'    => isset( $response['api_message'] ) ? $response['api_message'] : '',
			'api_message_id' => isset( $response['api_message_id'] ) ? $response['api_message_id'] : '',
			'api_statustext' => isset( $response['api_statustext'] ) ? $response['api_statustext'] : '',
			'success'        => ! empty( $response['success'] ) ? 1 : 0,
			'created_by'     => get_current_user_id(),
			'created_at'     => $this->get_now_mysql(),
			'error_text'     => isset( $response['error_text'] ) ? $response['error_text'] : '',
			'raw_response'   => '',
		);

		if ( ! $this->is_duplicate_recent_log( $log_data ) ) {
			$this->insert_log( $log_data );
		}

		$text = ! empty( $response['success'] ) ? 'تماس صوتی با موفقیت ثبت شد.' : 'تماس صوتی ناموفق بود.';

		if ( ! empty( $response['api_message_id'] ) ) {
			$text .= ' شناسه پیام: ' . $response['api_message_id'];
		}

		if ( empty( $response['success'] ) && ! empty( $response['api_message'] ) ) {
			$text .= ' ' . $response['api_message'];
		}

		if ( empty( $response['success'] ) && ! empty( $response['error_text'] ) ) {
			$text .= ' ' . $response['error_text'];
		}

		$this->save_order_last_voice_result(
			$order,
			array(
				'success' => ! empty( $response['success'] ) ? 1 : 0,
				'text'    => $text,
				'time'    => $this->get_now_mysql(),
			)
		);
	}

	private function resolve_token_source_value( $source, $order, $to_status ) {
		switch ( sanitize_key( $source ) ) {
			case 'order_id':
				return (string) $order->get_id();

			case 'order_number':
				return (string) $order->get_order_number();

			case 'status_label':
				return wp_strip_all_tags( $this->get_status_label( $to_status ) );

			case 'customer_first_name':
				return (string) $order->get_billing_first_name();

			case 'customer_last_name':
				return (string) $order->get_billing_last_name();

			case 'customer_full_name':
				return trim( $order->get_formatted_billing_full_name() );

			case 'billing_phone':
				return (string) $order->get_billing_phone();

			case 'billing_email':
				return (string) $order->get_billing_email();

			case 'order_total':
				return wc_format_decimal( $order->get_total(), 0 );

			case 'site_name':
				return wp_strip_all_tags( get_bloginfo( 'name' ) );
		}

		return '';
	}

	private function sanitize_lookup_token( $value, $type = 'token' ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = wp_strip_all_tags( $value );
		$value = trim( preg_replace( '/[\r\n\t]+/u', ' ', $value ) );

		if ( 'token' === $type ) {
			$value = preg_replace( '/\s+/u', '', $value );
		} else {
			$value = preg_replace( '/\s{2,}/u', ' ', $value );
		}

		return mb_substr( $value, 0, 100 );
	}

	private function normalize_iran_mobile( $phone ) {
		$phone = (string) $phone;
		$phone = preg_replace( '/[^0-9+]/', '', $phone );

		if ( preg_match( '/^09\d{9}$/', $phone ) ) return $phone;
		if ( preg_match( '/^9\d{9}$/', $phone ) ) return '0' . $phone;
		if ( preg_match( '/^\+989\d{9}$/', $phone ) ) return '0' . substr( $phone, 3 );
		if ( preg_match( '/^00989\d{9}$/', $phone ) ) return '0' . substr( $phone, 4 );

		return '';
	}

	private function send_lookup_call( $args ) {
		$api_key = $this->get_kavenegar_api_key();

		if ( ! $this->has_valid_api_key() ) {
			return array(
				'success'        => 0,
				'http_code'      => 0,
				'api_status'     => 0,
				'api_message'    => '',
				'api_message_id' => '',
				'api_statustext' => '',
				'error_text'     => 'کلید API کاوه‌نگار در تنظیمات افزونه ثبت نشده یا معتبر نیست.',
				'raw_response'   => '',
			);
		}

		$endpoint = 'https://api.kavenegar.com/v1/' . rawurlencode( $api_key ) . '/verify/lookup.json';

		$body = array(
			'receptor' => isset( $args['receptor'] ) ? $args['receptor'] : '',
			'template' => isset( $args['template'] ) ? $args['template'] : '',
			'token'    => isset( $args['token'] ) ? $args['token'] : '',
			'token10'  => isset( $args['token10'] ) ? $args['token10'] : '',
			'token20'  => isset( $args['token20'] ) ? $args['token20'] : '',
			'type'     => 'call',
		);

		$body = array_filter(
			$body,
			function( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		$request = wp_remote_post(
			$endpoint,
			array(
				'timeout'   => 20,
				'sslverify' => true,
				'headers'   => array(
					'Accept' => 'application/json',
				),
				'body'      => $body,
			)
		);

		if ( is_wp_error( $request ) ) {
			return array(
				'success'        => 0,
				'http_code'      => 0,
				'api_status'     => 0,
				'api_message'    => '',
				'api_message_id' => '',
				'api_statustext' => '',
				'error_text'     => $request->get_error_message(),
				'raw_response'   => '',
			);
		}

		$http_code = (int) wp_remote_retrieve_response_code( $request );
		$raw_body  = wp_remote_retrieve_body( $request );
		$data      = json_decode( $raw_body, true );

		$api_status  = isset( $data['return']['status'] ) ? (int) $data['return']['status'] : 0;
		$api_message = isset( $data['return']['message'] ) ? (string) $data['return']['message'] : '';
		$entries     = isset( $data['entries'] ) ? $data['entries'] : array();

		$entry = array();
		if ( is_array( $entries ) ) {
			if ( isset( $entries[0] ) && is_array( $entries[0] ) ) {
				$entry = $entries[0];
			} elseif ( isset( $entries['messageid'] ) ) {
				$entry = $entries;
			}
		}

		$success = ( 200 === $http_code && 200 === $api_status );

		return array(
			'success'        => $success ? 1 : 0,
			'http_code'      => $http_code,
			'api_status'     => $api_status,
			'api_message'    => $api_message,
			'api_message_id' => isset( $entry['messageid'] ) ? (string) $entry['messageid'] : '',
			'api_statustext' => isset( $entry['statustext'] ) ? (string) $entry['statustext'] : '',
			'error_text'     => $success ? '' : 'درخواست ناموفق بود.',
			'raw_response'   => $raw_body,
		);
	}

	/*--------------------------------------------------------------*/
	/* Logs + Metabox
	/*--------------------------------------------------------------*/

	private function insert_log( $data ) {
		global $wpdb;
		$table = $this->get_table_name();

		$defaults = array(
			'order_id'       => 0,
			'order_number'   => '',
			'from_status'    => '',
			'to_status'      => '',
			'phone'          => '',
			'template_name'  => '',
			'token'          => '',
			'token10'        => '',
			'token20'        => '',
			'local_id'       => '',
			'api_http_code'  => 0,
			'api_status'     => 0,
			'api_message'    => '',
			'api_message_id' => '',
			'api_statustext' => '',
			'success'        => 0,
			'created_by'     => 0,
			'created_at'     => $this->get_now_mysql(),
			'error_text'     => '',
			'raw_response'   => '',
		);

		$data = wp_parse_args( $data, $defaults );

		$wpdb->insert(
			$table,
			array(
				'order_id'       => absint( $data['order_id'] ),
				'order_number'   => sanitize_text_field( $data['order_number'] ),
				'from_status'    => sanitize_text_field( $data['from_status'] ),
				'to_status'      => sanitize_text_field( $data['to_status'] ),
				'phone'          => sanitize_text_field( $data['phone'] ),
				'template_name'  => sanitize_text_field( $data['template_name'] ),
				'token'          => sanitize_text_field( $data['token'] ),
				'token10'        => sanitize_text_field( $data['token10'] ),
				'token20'        => sanitize_text_field( $data['token20'] ),
				'local_id'       => sanitize_text_field( $data['local_id'] ),
				'api_http_code'  => absint( $data['api_http_code'] ),
				'api_status'     => absint( $data['api_status'] ),
				'api_message'    => sanitize_text_field( $data['api_message'] ),
				'api_message_id' => sanitize_text_field( $data['api_message_id'] ),
				'api_statustext' => sanitize_text_field( $data['api_statustext'] ),
				'success'        => ! empty( $data['success'] ) ? 1 : 0,
				'created_by'     => absint( $data['created_by'] ),
				'created_at'     => sanitize_text_field( $data['created_at'] ),
				'error_text'     => is_scalar( $data['error_text'] ) ? (string) $data['error_text'] : '',
				'raw_response'   => is_scalar( $data['raw_response'] ) ? (string) $data['raw_response'] : '',
			),
			array(
				'%d','%s','%s','%s','%s','%s','%s','%s','%s','%s',
				'%d','%d','%s','%s','%s','%d','%d','%s','%s','%s'
			)
		);
	}

	private function save_order_last_voice_result( $order, $payload ) {
		$order->update_meta_data( self::META_LAST_RESULT, $payload );
		$order->save_meta_data();
	}

	public function register_order_metaboxes() {
		$this->add_order_metabox( 'shop_order' );
	}

	public function register_hpos_order_metaboxes() {
		$this->add_order_metabox( 'woocommerce_page_wc-orders' );
	}

	private function add_order_metabox( $screen ) {
		add_meta_box(
			'wckvc-last-voice-call-result',
			'وضعیت تماس صوتی سفارش',
			array( $this, 'render_order_metabox' ),
			$screen,
			'side',
			'low'
		);
	}

	public function render_order_metabox( $post_or_order_object ) {
		$order = $this->get_order_from_metabox_context( $post_or_order_object );

		if ( ! $order ) {
			echo '<p>اطلاعات سفارش قابل دریافت نیست.</p>';
			return;
		}

		$data = $order->get_meta( self::META_LAST_RESULT, true );

		if ( ! empty( $data ) && is_array( $data ) ) {
			$success = ! empty( $data['success'] );
			$text    = isset( $data['text'] ) ? $data['text'] : '';
			$time    = isset( $data['time'] ) ? $data['time'] : '';

			echo '<div style="line-height:1.9;margin-bottom:12px;">';
			echo '<div style="margin-bottom:8px;"><strong>آخرین وضعیت:</strong> ';
			echo $success
				? '<span style="color:#0f7a2a;font-weight:700;">ارسال شده</span>'
				: '<span style="color:#b42318;font-weight:700;">ارسال نشده / ناموفق</span>';
			echo '</div>';

			if ( $time ) {
				echo '<div style="margin-bottom:8px;"><strong>زمان:</strong> ' . esc_html( $this->format_wp_local_datetime( $time ) ) . '</div>';
			}

			if ( $text ) {
				echo '<div style="padding:8px 10px;background:#f6f7f7;border:1px solid #e5e7eb;border-radius:8px;">' . esc_html( $text ) . '</div>';
			}
			echo '</div>';
		} else {
			echo '<p>هنوز تماسی برای این سفارش ثبت نشده است.</p>';
		}

		$logs = $this->get_recent_logs_for_order( $order->get_id(), 10 );

		if ( empty( $logs ) ) {
			return;
		}

		echo '<hr style="margin:12px 0;">';
		echo '<div><strong>۱۰ لاگ اخیر</strong></div>';
		echo '<div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;">';

		foreach ( $logs as $log ) {
			$helper_name = $this->get_user_display_name( $log->created_by );
			$result_text = ! empty( $log->success ) ? 'موفق' : 'ناموفق';

			$detail = '';
			if ( ! empty( $log->error_text ) ) {
				$detail = $log->error_text;
			} elseif ( ! empty( $log->api_message ) ) {
				$detail = $log->api_message;
			} elseif ( ! empty( $log->api_statustext ) ) {
				$detail = $log->api_statustext;
			}

			echo '<div style="padding:8px 10px;background:#f6f7f7;border:1px solid #e5e7eb;border-radius:8px;font-size:12px;line-height:1.9;">';
			echo '<div><strong>زمان:</strong> ' . esc_html( $this->format_wp_local_datetime( $log->created_at ) ) . '</div>';
			echo '<div><strong>همکار:</strong> ' . esc_html( $helper_name ) . '</div>';
			echo '<div><strong>وضعیت:</strong> ' . esc_html( $this->get_status_label( $log->from_status ) ) . ' ← ' . esc_html( $this->get_status_label( $log->to_status ) ) . '</div>';
			echo '<div><strong>نتیجه:</strong> ' . esc_html( $result_text ) . '</div>';

			if ( '' !== $detail ) {
				echo '<div><strong>جزئیات:</strong> ' . esc_html( $detail ) . '</div>';
			}

			echo '</div>';
		}

		echo '</div>';
	}

	private function get_order_from_metabox_context( $context ) {
		if ( is_a( $context, 'WC_Order' ) ) {
			return $context;
		}

		if ( is_object( $context ) && ! empty( $context->ID ) ) {
			$order = wc_get_order( $context->ID );
			if ( $order ) {
				return $order;
			}
		}

		if ( isset( $_GET['id'] ) ) {
			$order = wc_get_order( absint( $_GET['id'] ) );
			if ( $order ) {
				return $order;
			}
		}

		if ( isset( $_GET['post'] ) ) {
			$order = wc_get_order( absint( $_GET['post'] ) );
			if ( $order ) {
				return $order;
			}
		}

		return false;
	}

	/*--------------------------------------------------------------*/
	/* Admin Notice
	/*--------------------------------------------------------------*/

	public function maybe_show_admin_notice() {
		if ( ! $this->current_user_can_manage_page() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		$allowed_screens = array(
			'toplevel_page_wckvc-order-voice-status',
			'voice-status_page_wckvc-order-voice-logs',
			'shop_order',
			'woocommerce_page_wc-orders',
		);

		if ( ! in_array( $screen->id, $allowed_screens, true ) ) {
			return;
		}

		if ( $this->has_valid_api_key() ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>کلید API کاوه‌نگار ثبت نشده یا معتبر نیست. از صفحه تنظیمات افزونه، کلید API را وارد و ذخیره کنید.</p></div>';
	}
}

new WCKVC_Order_Voice_Status_Call();

endif;
