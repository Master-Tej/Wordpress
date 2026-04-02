<?php
/**
 * Plugin Name:       Must Mail Log
 * Plugin URI:        https://example.com/must-mail-log
 * Description:       Lightweight, advanced WordPress outbound email logging — single file, no Composer. Full headers, body, attachments metadata, failures, and admin UI.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Must Mail Log
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       must-mail-log
 *
 * @package Must_Mail_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MUST_MAIL_LOG_VERSION', '1.0.0' );
define( 'MUST_MAIL_LOG_SLUG', 'must-mail-log' );
define( 'MUST_MAIL_LOG_FILE', __FILE__ );
define( 'MUST_MAIL_LOG_HOOK_PRIORITY', 9999 );

/**
 * Main plugin (static facade).
 */
final class Must_Mail_Log {

	public const TABLE_VERSION = '1.0';

	/** @var int|null */
	private static $last_insert_id = null;

	/**
	 * Bootstrap hooks.
	 */
	public static function init(): void {
		register_activation_hook( MUST_MAIL_LOG_FILE, array( __CLASS__, 'activate' ) );
		register_uninstall_hook( MUST_MAIL_LOG_FILE, array( __CLASS__, 'uninstall_static' ) );

		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade_table' ) );
		add_filter( 'wp_mail', array( __CLASS__, 'capture_wp_mail' ), MUST_MAIL_LOG_HOOK_PRIORITY, 1 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_failed' ), 10, 1 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
			add_action( 'admin_init', array( __CLASS__, 'handle_admin_actions' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		}
	}

	/**
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'must_mail_logs';
	}

	public static function activate(): void {
		self::create_table();
		update_option( 'must_mail_log_db_version', self::TABLE_VERSION );
	}

	public static function maybe_upgrade_table(): void {
		$v = get_option( 'must_mail_log_db_version' );
		if ( $v !== self::TABLE_VERSION ) {
			self::create_table();
			update_option( 'must_mail_log_db_version', self::TABLE_VERSION );
		}
	}

	private static function create_table(): void {
		global $wpdb;
		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			created_at_gmt DATETIME NOT NULL,
			to_email TEXT NOT NULL,
			subject TEXT NOT NULL,
			message LONGTEXT NOT NULL,
			headers LONGTEXT NOT NULL,
			attachments_json LONGTEXT NOT NULL,
			content_type VARCHAR(191) NOT NULL DEFAULT '',
			from_addr VARCHAR(500) NOT NULL DEFAULT '',
			cc TEXT NOT NULL,
			bcc TEXT NOT NULL,
			reply_to TEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'sent',
			error_message LONGTEXT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			caller_note VARCHAR(500) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status),
			KEY subject (subject(100))
		) {$collate};";

		dbDelta( $sql );
	}

	public static function uninstall_static(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			return;
		}
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( 'must_mail_log_db_version' );
		delete_option( 'must_mail_log_settings' );
	}

	/**
	 * @param array $args wp_mail arguments.
	 * @return array Unmodified mail args.
	 */
	public static function capture_wp_mail( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}

		$defaults = array(
			'to'          => '',
			'subject'     => '',
			'message'     => '',
			'headers'     => '',
			'attachments' => array(),
		);
		$args = wp_parse_args( $args, $defaults );

		$to_raw = $args['to'];
		if ( is_array( $to_raw ) ) {
			$to_list = array_filter( array_map( 'trim', $to_raw ) );
		} else {
			$to_list = array_filter( array_map( 'trim', explode( ',', (string) $to_raw ) ) );
		}
		$to_display = implode( ', ', $to_list );

		$attachments_info = self::describe_attachments( $args['attachments'] );
		$parsed           = self::parse_headers( $args['headers'] );

		$caller = self::short_caller();

		global $wpdb;
		$now_local = current_time( 'mysql', false );
		$now_gmt   = current_time( 'mysql', true );
		$user_id   = is_user_logged_in() ? get_current_user_id() : 0;

		$wpdb->insert(
			self::table_name(),
			array(
				'created_at'       => $now_local,
				'created_at_gmt'   => $now_gmt,
				'to_email'         => $to_display,
				'subject'          => wp_strip_all_tags( (string) $args['subject'] ),
				'message'          => (string) $args['message'],
				'headers'          => self::headers_to_store_string( $args['headers'] ),
				'attachments_json' => wp_json_encode( $attachments_info ),
				'content_type'     => $parsed['content_type'],
				'from_addr'        => $parsed['from'],
				'cc'               => $parsed['cc'],
				'bcc'              => $parsed['bcc'],
				'reply_to'         => $parsed['reply_to'],
				'status'           => 'sent',
				'error_message'    => null,
				'user_id'          => $user_id,
				'caller_note'      => $caller,
			),
			array(
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
			)
		);

		if ( $wpdb->insert_id ) {
			self::$last_insert_id = (int) $wpdb->insert_id;
		}

		return $args;
	}

	/**
	 * @param WP_Error $error Error from wp_mail_failed.
	 */
	public static function capture_failed( $error ): void {
		if ( ! is_wp_error( $error ) ) {
			return;
		}
		$msg  = $error->get_error_message();
		$data = $error->get_error_data( 'wp_mail_failed' );
		if ( ! is_array( $data ) ) {
			$data = $error->get_error_data();
			$data = is_array( $data ) ? $data : array();
		}

		global $wpdb;
		$row_id = self::$last_insert_id;

		if ( $row_id ) {
			$wpdb->update(
				self::table_name(),
				array(
					'status'        => 'failed',
					'error_message' => $msg,
				),
				array( 'id' => $row_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			self::$last_insert_id = null;
			return;
		}

		/** Fallback: match latest row with same subject/to in a short window. */
		$to_raw  = isset( $data['to'] ) ? $data['to'] : '';
		$subject = isset( $data['subject'] ) ? $data['subject'] : '';
		if ( $to_raw === '' && $subject === '' ) {
			return;
		}
		if ( is_array( $to_raw ) ) {
			$to_norm = implode( ', ', array_filter( array_map( 'trim', $to_raw ) ) );
		} else {
			$to_norm = implode( ', ', array_filter( array_map( 'trim', explode( ',', (string) $to_raw ) ) ) );
		}
		$table = self::table_name();
		$id    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE subject = %s AND to_email = %s ORDER BY id DESC LIMIT 1",
				wp_strip_all_tags( (string) $subject ),
				$to_norm
			)
		);
		if ( $id ) {
			$wpdb->update(
				self::table_name(),
				array(
					'status'        => 'failed',
					'error_message' => $msg,
				),
				array( 'id' => (int) $id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * @param mixed $attachments wp_mail attachments.
	 * @return array<int, array<string, string>>
	 */
	private static function describe_attachments( $attachments ): array {
		$out = array();
		if ( empty( $attachments ) ) {
			return $out;
		}
		$list = is_array( $attachments ) ? $attachments : array( $attachments );
		foreach ( $list as $path ) {
			if ( ! is_string( $path ) || $path === '' ) {
				continue;
			}
			$out[] = array(
				'path' => $path,
				'name' => basename( $path ),
				'size' => file_exists( $path ) ? (string) filesize( $path ) : '',
			);
		}
		return $out;
	}

	/**
	 * @param mixed $headers wp_mail headers.
	 */
	private static function headers_to_store_string( $headers ): string {
		if ( is_array( $headers ) ) {
			return implode( "\n", array_map( 'strval', $headers ) );
		}
		return (string) $headers;
	}

	/**
	 * @param mixed $headers Raw headers.
	 * @return array{content_type:string,from:string,cc:string,bcc:string,reply_to:string}
	 */
	private static function parse_headers( $headers ): array {
		$out = array(
			'content_type' => '',
			'from'         => '',
			'cc'           => '',
			'bcc'          => '',
			'reply_to'     => '',
		);
		$lines = array();
		if ( is_array( $headers ) ) {
			$lines = $headers;
		} elseif ( is_string( $headers ) && $headers !== '' ) {
			$lines = preg_split( '/\r?\n/', $headers ) ?: array();
		}
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( $line === '' ) {
				continue;
			}
			if ( ! preg_match( '/^([^:]+):\s*(.+)$/i', $line, $m ) ) {
				continue;
			}
			$key = strtolower( $m[1] );
			$val = trim( $m[2] );
			switch ( $key ) {
				case 'content-type':
					$out['content_type'] = $val;
					break;
				case 'from':
					$out['from'] = $val;
					break;
				case 'cc':
					$out['cc'] = $out['cc'] ? $out['cc'] . ', ' . $val : $val;
					break;
				case 'bcc':
					$out['bcc'] = $out['bcc'] ? $out['bcc'] . ', ' . $val : $val;
					break;
				case 'reply-to':
					$out['reply_to'] = $out['reply_to'] ? $out['reply_to'] . ', ' . $val : $val;
					break;
			}
		}
		if ( $out['content_type'] === '' ) {
			$out['content_type'] = 'text/plain';
		}
		return $out;
	}

	private static function short_caller(): string {
		$bt = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 );
		$skip = array( __CLASS__ . '::capture_wp_mail', 'wp_mail' );
		foreach ( $bt as $f ) {
			$fn = '';
			if ( isset( $f['class'], $f['type'], $f['function'] ) ) {
				$fn = $f['class'] . $f['type'] . $f['function'];
			} elseif ( isset( $f['function'] ) ) {
				$fn = $f['function'];
			}
			if ( in_array( $fn, $skip, true ) || $fn === 'wp_mail' ) {
				continue;
			}
			$file = isset( $f['file'] ) ? wp_basename( $f['file'] ) : '?';
			$line = isset( $f['line'] ) ? (string) $f['line'] : '';
			$bit  = $file . ':' . $line . ' ' . $fn;
			return Must_Mail_Log_Admin_Helper::clamp_str( $bit, 500 );
		}
		return '';
	}

	/* --- Admin --- */

	public static function admin_menu(): void {
		$cap = apply_filters( 'must_mail_log_capability', 'manage_options' );
		add_menu_page(
			__( 'Must Mail Log', 'must-mail-log' ),
			__( 'Must Mail Log', 'must-mail-log' ),
			$cap,
			MUST_MAIL_LOG_SLUG,
			array( __CLASS__, 'render_admin' ),
			'dashicons-email-alt',
			58
		);
	}

	public static function admin_assets( string $hook ): void {
		if ( strpos( $hook, MUST_MAIL_LOG_SLUG ) === false ) {
			return;
		}
		$css = '.must-mail-log-settings-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:16px;max-width:960px;margin:16px 0;}@media(min-width:900px){.must-mail-log-settings-grid{grid-template-columns:1fr 340px;align-items:start;}}'
			. '.must-mail-log-box{margin:0!important;}.must-mail-log-box .inside{margin:0;padding:12px 16px 16px;}.must-mail-log-box-danger{border-color:#dba617;background:#fcf9e8;}'
			. '.must-mail-log-prune-form .button{margin-top:4px;}.must-mail-log-danger-button{box-shadow:none!important;border-color:#b32d2e!important;color:#b32d2e!important;}'
			. '.must-mail-log-badge{display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;}'
			. '.must-mail-log-badge-ok{background:#d5f0dd;color:#1e4620;}.must-mail-log-badge-fail{background:#fce8e8;color:#8b2424;}'
			. '.must-mail-log-detail-meta{margin:16px 0;max-width:960px;}'
			. '.must-mail-log-pre{max-height:220px;overflow:auto;background:#f6f7f7;padding:12px;border:1px solid #dcdcde;border-radius:4px;}'
			. '.must-mail-log-pre-tall{max-height:420px;}.must-mail-log-error-cell{color:#8b2424;font-weight:500;}'
			. '.must-mail-log-preview-outer{border:1px solid #dcdcde;border-radius:4px;background:#fff;overflow:hidden;}'
			. '.must-mail-log-preview-frame{width:100%;min-height:480px;border:0;display:block;background:#fff;}'
			. '.must-mail-log-atts{margin:0 0 1em 1.2em;}.must-mail-log-json{font-family:Consolas,Monaco,monospace;}';
		wp_register_style( 'must-mail-log-admin', false, array(), MUST_MAIL_LOG_VERSION );
		wp_enqueue_style( 'must-mail-log-admin' );
		wp_add_inline_style( 'must-mail-log-admin', $css );
	}

	public static function handle_admin_actions(): void {
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== MUST_MAIL_LOG_SLUG ) {
			return;
		}
		$cap = apply_filters( 'must_mail_log_capability', 'manage_options' );
		if ( ! current_user_can( $cap ) ) {
			return;
		}

		if ( ! empty( $_POST['must_mail_log_action'] ) && check_admin_referer( 'must_mail_log_settings' ) ) {
			$settings = self::get_settings();
			if ( isset( $_POST['retention_days'] ) ) {
				$d = absint(wp_unslash($_POST['retention_days']));
				$settings['retention_days'] = max( 0, min( 3650, $d ) );
			}
			if ( isset( $_POST['prune_now'] ) ) {
				if ( $settings['retention_days'] < 1 ) {
					add_settings_error(
						'must_mail_log',
						'prune_skipped',
						__( 'Set “How long to keep logs” to at least 1 day and save before running cleanup.', 'must-mail-log' ),
						'warning'
					);
				} else {
					self::prune_old( $settings['retention_days'] );
					add_settings_error( 'must_mail_log', 'pruned', __( 'Entries older than your retention period were deleted.', 'must-mail-log' ), 'success' );
				}
			}
			update_option( 'must_mail_log_settings', $settings );
		}

		if ( ! empty( $_GET['must_mail_log_delete'] ) && check_admin_referer( 'must_mail_log_delete' ) ) {
			$id = absint(wp_unslash($_GET['must_mail_log_delete']));
			if ( $id ) {
				global $wpdb;
				$wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=' . MUST_MAIL_LOG_SLUG ) );
			exit;
		}

		if ( ! empty( $_GET['must_mail_log_empty'] ) && check_admin_referer( 'must_mail_log_empty' ) ) {
			global $wpdb;
			$wpdb->query( 'TRUNCATE TABLE ' . self::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			wp_safe_redirect( admin_url( 'admin.php?page=' . MUST_MAIL_LOG_SLUG ) );
			exit;
		}
	}

	/**
	 * @return array{retention_days:int}
	 */
	public static function get_settings(): array {
		$defaults = array( 'retention_days' => 0 );
		$opt      = get_option( 'must_mail_log_settings', array() );
		return wp_parse_args( is_array( $opt ) ? $opt : array(), $defaults );
	}

	private static function prune_old( int $days ): void {
		if ( $days <= 0 ) {
			return;
		}
		global $wpdb;
		$table = self::table_name();
		$cut   = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at_gmt < %s", $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function render_admin(): void {
		$cap = apply_filters( 'must_mail_log_capability', 'manage_options' );
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'must-mail-log' ) );
		}

		$view_id = isset( $_GET['view'] ) ? absint( wp_unslash( $_GET['view'] ) ) : 0;
		if ( $view_id ) {
			Must_Mail_Log_Admin_Helper::render_detail( $view_id );
			return;
		}

		settings_errors( 'must_mail_log' );

		$settings = self::get_settings();
		$retention = (int) $settings['retention_days'];
		?>
		<div class="wrap must-mail-log-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Must Mail Log', 'must-mail-log' ); ?></h1>
			<hr class="wp-header-end" />

			<div id="must-mail-log-settings" class="must-mail-log-settings-grid">
				<div class="postbox must-mail-log-box">
					<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Log storage', 'must-mail-log' ); ?></h2></div>
					<div class="inside">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . MUST_MAIL_LOG_SLUG ) ); ?>" class="must-mail-log-storage-form">
							<?php wp_nonce_field( 'must_mail_log_settings' ); ?>
							<input type="hidden" name="must_mail_log_action" value="1" />
							<table class="form-table" role="presentation">
								<tbody>
									<tr>
										<th scope="row">
											<label for="must_mail_log_retention"><?php esc_html_e( 'How long to keep logs', 'must-mail-log' ); ?></label>
										</th>
										<td>
											<input name="retention_days" id="must_mail_log_retention" type="number" min="0" max="3650" step="1"
												value="<?php echo esc_attr( (string) $retention ); ?>" class="small-text" />
											<p class="description">
												<?php esc_html_e( 'Number of days to store each entry (counted from the log date). Set to 0 to keep every entry until you delete it yourself.', 'must-mail-log' ); ?>
											</p>
										</td>
									</tr>
								</tbody>
							</table>
							<p class="submit">
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'must-mail-log' ); ?></button>
							</p>
						</form>
						<hr />
						<h3><?php esc_html_e( 'Free up space using the rule above', 'must-mail-log' ); ?></h3>
						<p class="description">
									<?php
									if ( $retention > 0 ) {
										echo esc_html(
											sprintf(
												/* translators: %d: number of days */
												__( 'This removes entries older than %d day(s) in one go, using the saved number of days.', 'must-mail-log' ),
												$retention
											)
										);
									} else {
										esc_html_e( 'Set “How long to keep logs” to at least 1 day, save, then you can run a cleanup. With “0”, logs are never auto-removed.', 'must-mail-log' );
									}
									?>
								</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . MUST_MAIL_LOG_SLUG ) ); ?>" class="must-mail-log-prune-form">
							<?php wp_nonce_field( 'must_mail_log_settings' ); ?>
							<input type="hidden" name="must_mail_log_action" value="1" />
							<input type="hidden" name="retention_days" value="<?php echo esc_attr( (string) $retention ); ?>" />
							<button type="submit" name="prune_now" value="1" class="button"
								<?php echo $retention < 1 ? ' disabled="disabled"' : ''; ?>>
								<?php esc_html_e( 'Delete old entries now', 'must-mail-log' ); ?>
							</button>
						</form>
					</div>
				</div>

				<div class="postbox must-mail-log-box must-mail-log-box-danger">
					<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Remove everything', 'must-mail-log' ); ?></h2></div>
					<div class="inside">
						<p><?php esc_html_e( 'This clears the full mail log in one step. Use it when you want a completely empty log (for example before handing off a site).', 'must-mail-log' ); ?></p>
						<p><strong><?php esc_html_e( 'This is not the same as cleanup above: it deletes every row, not only “old” ones.', 'must-mail-log' ); ?></strong></p>
						<p>
							<a class="button button-secondary must-mail-log-danger-button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . MUST_MAIL_LOG_SLUG . '&must_mail_log_empty=1' ), 'must_mail_log_empty' ) ); ?>"
								onclick="return confirm('<?php echo esc_js( __( 'Delete all mail log entries permanently?', 'must-mail-log' ) ); ?>');">
								<?php esc_html_e( 'Delete all log entries', 'must-mail-log' ); ?>
							</a>
						</p>
					</div>
				</div>
			</div>

			<h2 class="title" style="margin-top:24px;"><?php esc_html_e( 'Email log', 'must-mail-log' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Search, sort by column headers, and open any row to see full content.', 'must-mail-log' ); ?></p>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( MUST_MAIL_LOG_SLUG ); ?>" />
				<?php $list = new Must_Mail_Log_List_Table(); ?>
				<?php $list->prepare_items(); ?>
				<?php $list->search_box( __( 'Search logs', 'must-mail-log' ), 'must-mail-log-search' ); ?>
				<?php $list->display(); ?>
			</form>
		</div>
		<?php
	}
}

/**
 * Admin UI helpers + detail screen.
 */
final class Must_Mail_Log_Admin_Helper {

	public static function clamp_str( string $s, int $max ): string {
		$s = trim( $s );
		if ( strlen( $s ) <= $max ) {
			return $s;
		}
		return substr( $s, 0, $max - 1 ) . '…';
	}

	public static function render_detail( int $id ): void {
		global $wpdb;
		$table = Must_Mail_Log::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Log entry not found.', 'must-mail-log' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . MUST_MAIL_LOG_SLUG ) ) . '">' . esc_html__( 'Back to list', 'must-mail-log' ) . '</a></p></div>';
			return;
		}

		$back = admin_url( 'admin.php?page=' . MUST_MAIL_LOG_SLUG );
		$atts = json_decode( $row['attachments_json'], true );
		if ( ! is_array( $atts ) ) {
			$atts = array();
		}

		$content_type = strtolower( (string) $row['content_type'] );
		$is_html      = strpos( $content_type, 'html' ) !== false;
		$preview_html = $is_html ? wp_kses_post( $row['message'] ) : '<pre>' . esc_html( $row['message'] ) . '</pre>';

		$export = array(
			'id'               => (int) $row['id'],
			'created_at'       => $row['created_at'],
			'created_at_gmt'   => $row['created_at_gmt'],
			'to'               => $row['to_email'],
			'subject'          => $row['subject'],
			'from'             => $row['from_addr'],
			'cc'               => $row['cc'],
			'bcc'              => $row['bcc'],
			'reply_to'         => $row['reply_to'],
			'content_type'     => $row['content_type'],
			'headers'          => $row['headers'],
			'attachments'      => $atts,
			'message'          => $row['message'],
			'status'           => $row['status'],
			'error_message'    => $row['error_message'],
			'user_id'          => (int) $row['user_id'],
			'caller_note'      => $row['caller_note'],
		);
		?>
		<div class="wrap must-mail-log-wrap must-mail-log-detail">
			<p><a href="<?php echo esc_url( $back ); ?>" class="button">&larr; <?php esc_html_e( 'Back to logs', 'must-mail-log' ); ?></a></p>
			<h1><?php echo esc_html( $row['subject'] ?: __( '(No subject)', 'must-mail-log' ) ); ?></h1>

			<div class="must-mail-log-detail-meta">
				<table class="widefat striped">
					<tbody>
						<tr><th><?php esc_html_e( 'Date (site)', 'must-mail-log' ); ?></th><td><?php echo esc_html( $row['created_at'] ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Date (GMT)', 'must-mail-log' ); ?></th><td><?php echo esc_html( $row['created_at_gmt'] ); ?></td></tr>
						<tr><th><?php esc_html_e( 'To', 'must-mail-log' ); ?></th><td><?php echo esc_html( $row['to_email'] ); ?></td></tr>
						<tr><th><?php esc_html_e( 'From', 'must-mail-log' ); ?></th><td><?php echo esc_html( $row['from_addr'] ?: '—' ); ?></td></tr>
						<tr><th><?php esc_html_e( 'CC', 'must-mail-log' ); ?></th><td><?php echo esc_html( $row['cc'] ?: '—' ); ?></td></tr>
						<tr><th><?php esc_html_e( 'BCC', 'must-mail-log' ); ?></th><td><?php echo esc_html( $row['bcc'] ?: '—' ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Reply-To', 'must-mail-log' ); ?></th><td><?php echo esc_html( $row['reply_to'] ?: '—' ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Content type', 'must-mail-log' ); ?></th><td><code><?php echo esc_html( $row['content_type'] ); ?></code></td></tr>
						<tr><th><?php esc_html_e( 'Status', 'must-mail-log' ); ?></th><td>
							<span class="<?php echo $row['status'] === 'failed' ? 'must-mail-log-badge must-mail-log-badge-fail' : 'must-mail-log-badge must-mail-log-badge-ok'; ?>">
								<?php echo esc_html( strtoupper( $row['status'] ) ); ?>
							</span>
						</td></tr>
						<?php if ( ! empty( $row['error_message'] ) ) : ?>
							<tr><th><?php esc_html_e( 'Error', 'must-mail-log' ); ?></th><td class="must-mail-log-error-cell"><?php echo esc_html( $row['error_message'] ); ?></td></tr>
						<?php endif; ?>
						<tr><th><?php esc_html_e( 'WP user ID', 'must-mail-log' ); ?></th><td><?php echo esc_html( (string) (int) $row['user_id'] ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Caller', 'must-mail-log' ); ?></th><td><code><?php echo esc_html( $row['caller_note'] ); ?></code></td></tr>
					</tbody>
				</table>
			</div>

			<h2><?php esc_html_e( 'Raw headers', 'must-mail-log' ); ?></h2>
			<pre class="must-mail-log-pre"><?php echo esc_html( $row['headers'] ?: '—' ); ?></pre>

			<h2><?php esc_html_e( 'Attachments', 'must-mail-log' ); ?></h2>
			<?php if ( empty( $atts ) ) : ?>
				<p><?php esc_html_e( 'None.', 'must-mail-log' ); ?></p>
			<?php else : ?>
				<ul class="must-mail-log-atts">
					<?php foreach ( $atts as $a ) : ?>
						<li><code><?php echo esc_html( isset( $a['path'] ) ? $a['path'] : '' ); ?></code>
							<?php if ( ! empty( $a['size'] ) ) : ?>
								<span class="description">(<?php echo esc_html( size_format( (int) $a['size'] ) ); ?>)</span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Message preview', 'must-mail-log' ); ?></h2>
			<p class="description"><?php esc_html_e( 'HTML is sandboxed (scripts disabled).', 'must-mail-log' ); ?></p>
			<div class="must-mail-log-preview-outer">
				<iframe class="must-mail-log-preview-frame" sandbox="allow-same-origin" srcdoc="<?php echo esc_attr( Must_Mail_Log_Admin_Helper::iframe_srcdoc( $preview_html ) ); ?>"></iframe>
			</div>

			<h2><?php esc_html_e( 'Message source', 'must-mail-log' ); ?></h2>
			<pre class="must-mail-log-pre must-mail-log-pre-tall"><?php echo esc_html( $row['message'] ); ?></pre>

			<h2><?php esc_html_e( 'Export (JSON)', 'must-mail-log' ); ?></h2>
			<textarea class="large-text code must-mail-log-json" readonly rows="12"><?php echo esc_textarea( wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></textarea>
		</div>
		<?php
	}

	/**
	 * Minimal hardening for iframe srcdoc (still treat as untrusted; sandbox blocks script).
	 */
	public static function iframe_srcdoc( string $html ): string {
		// Strip null bytes; srcdoc attribute will entity-escape via esc_attr.
		return str_replace( "\0", '', $html );
	}
}

require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

/**
 * Admin list table for mail log entries.
 */
class Must_Mail_Log_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'mail_log',
				'plural'   => 'mail_logs',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Core list tables load columns from `manage_{$screen->id}_columns`; top-level menu pages
	 * have no registered columns, so $columns is empty and no rows/headers render. Build from get_columns().
	 *
	 * @return array{0:array<string,string>,1:string[],2:array,3:string}
	 */
	protected function get_column_info() {
		if ( is_array( $this->_column_headers ) && ! empty( $this->_column_headers ) && 4 === count( $this->_column_headers ) ) {
			return $this->_column_headers;
		}

		$columns  = $this->get_columns();
		$hidden   = array();
		$screen   = $this->screen;
		if ( $screen instanceof WP_Screen ) {
			$hidden = get_hidden_columns( $screen );
		}

		$sortable_columns = $this->get_sortable_columns();
		$_sortable        = ( $screen instanceof WP_Screen )
			? apply_filters( "manage_{$screen->id}_sortable_columns", $sortable_columns )
			: $sortable_columns;

		$sortable = array();
		foreach ( $_sortable as $id => $data ) {
			if ( empty( $data ) ) {
				continue;
			}
			$data = (array) $data;
			if ( ! isset( $data[1] ) ) {
				$data[1] = false;
			}
			if ( ! isset( $data[2] ) ) {
				$data[2] = '';
			}
			if ( ! isset( $data[3] ) ) {
				$data[3] = false;
			}
			if ( ! isset( $data[4] ) ) {
				$data[4] = false;
			}
			$sortable[ $id ] = $data;
		}

		$this->_column_headers = array( $columns, $hidden, $sortable, 'subject' );

		return $this->_column_headers;
	}

	public function get_columns(): array {
		return array(
			'created_at'  => __( 'Date', 'must-mail-log' ),
			'to_email'    => __( 'To', 'must-mail-log' ),
			'subject'     => __( 'Subject', 'must-mail-log' ),
			'status'      => __( 'Status', 'must-mail-log' ),
			'caller_note' => __( 'Caller', 'must-mail-log' ),
			'mml_actions' => __( 'Actions', 'must-mail-log' ),
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'created_at' => array( 'created_at', true ),
			'subject'    => array( 'subject', false ),
			'status'     => array( 'status', false ),
		);
	}

	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? (string) $item[ $column_name ] : '';
	}

	protected function column_created_at( $item ): string {
		$local = isset( $item['created_at'] ) ? $item['created_at'] : '';
		$gmt   = isset( $item['created_at_gmt'] ) ? $item['created_at_gmt'] : '';
		return '<abbr title="' . esc_attr( 'GMT: ' . $gmt ) . '">' . esc_html( $local ) . '</abbr>';
	}

	protected function column_to_email( $item ): string {
		$s = isset( $item['to_email'] ) ? $item['to_email'] : '';
		return esc_html( Must_Mail_Log_Admin_Helper::clamp_str( $s, 80 ) );
	}

	protected function column_subject( $item ): string {
		$s = isset( $item['subject'] ) ? $item['subject'] : '';
		return '<strong>' . esc_html( Must_Mail_Log_Admin_Helper::clamp_str( $s, 70 ) ) . '</strong>';
	}

	protected function column_status( $item ): string {
		$st  = isset( $item['status'] ) ? $item['status'] : '';
		$cls = $st === 'failed' ? 'must-mail-log-badge must-mail-log-badge-fail' : 'must-mail-log-badge must-mail-log-badge-ok';
		return '<span class="' . esc_attr( $cls ) . '">' . esc_html( strtoupper( $st ) ) . '</span>';
	}

	protected function column_caller_note( $item ): string {
		$s = isset( $item['caller_note'] ) ? $item['caller_note'] : '';
		return '<code>' . esc_html( Must_Mail_Log_Admin_Helper::clamp_str( $s, 60 ) ) . '</code>';
	}

	protected function column_mml_actions( $item ): string {
		$id   = (int) $item['id'];
		$view = admin_url( 'admin.php?page=' . MUST_MAIL_LOG_SLUG . '&view=' . $id );
		$del  = wp_nonce_url(
			admin_url( 'admin.php?page=' . MUST_MAIL_LOG_SLUG . '&must_mail_log_delete=' . $id ),
			'must_mail_log_delete'
		);
		return '<a class="button button-small" href="' . esc_url( $view ) . '">' . esc_html__( 'View', 'must-mail-log' ) . '</a> '
			. '<a class="button button-small" href="' . esc_url( $del ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this log entry?', 'must-mail-log' ) ) . '\');">' . esc_html__( 'Delete', 'must-mail-log' ) . '</a>';
	}

	public function prepare_items(): void {
		global $wpdb;
		$table    = Must_Mail_Log::table_name();
		$per_page = 25;
		$paged    = max( 1, absint( isset( $_GET['paged'] ) ? wp_unslash( $_GET['paged'] ) : 1 ) );
		$offset   = ( $paged - 1 ) * $per_page;
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at';
		$order   = isset( $_GET['order'] ) && strtolower( wp_unslash( $_GET['order'] ) ) === 'asc' ? 'ASC' : 'DESC';
		$allowed = array( 'created_at', 'subject', 'status', 'id' );
		if ( ! in_array( $orderby, $allowed, true ) ) {
			$orderby = 'created_at';
		}

		$where  = '1=1';
		$params = array();
		if ( $search !== '' ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND (subject LIKE %s OR to_email LIKE %s OR message LIKE %s OR headers LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql_count = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$sql_items = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		if ( $params ) {
			$total    = (int) $wpdb->get_var( $wpdb->prepare( $sql_count, $params ) );
			$params[] = $per_page;
			$params[] = $offset;
			$items    = $wpdb->get_results( $wpdb->prepare( $sql_items, $params ), ARRAY_A );
		} else {
			$total = (int) $wpdb->get_var( $sql_count );
			$items = $wpdb->get_results( $wpdb->prepare( $sql_items, $per_page, $offset ), ARRAY_A );
		}

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);

		$this->items = is_array( $items ) ? $items : array();
	}

	public function no_items(): void {
		esc_html_e( 'No emails logged yet. They will appear here when WordPress sends mail.', 'must-mail-log' );
	}
}

Must_Mail_Log::init();

/* Add settings link in plugin list */
add_filter(
	'plugin_action_links_' . plugin_basename( MUST_MAIL_LOG_FILE ),
	function( $links ) {
		$settings_link = '<a href="' . admin_url( 'admin.php?page=' . MUST_MAIL_LOG_SLUG ) . '">' . __( 'Settings', 'must-mail-log' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
);
