<?php
/**
 * Host-served opaque HTTP preview — serving contract v2 route registrar.
 *
 * Serves the eXeLearning *editor preview* of untrusted author content over HTTP
 * in an opaque origin, the same isolation posture the plugin already gives
 * *published* content (ExeLearning_Content_Proxy), applied to a short-lived,
 * capability-addressed preview session.
 *
 * MIRRORS eXe core: doc/development/preview-serving-contract.md — the canonical
 * source of truth, and src/routes/preview-session.ts + src/services/
 * preview-serving.ts. The sandbox-first CSP emitted on scriptable document
 * types MUST stay BYTE-IDENTICAL to that document
 * (ExeLearning_Preview_Http_Headers::SANDBOX_CSP).
 *
 * This class is the thin registrar: it wires the two REST surfaces and the
 * cleanup cron to focused controllers that share one session store and one
 * fixed-resource resolver.
 *
 * - Authless serving route (`GET {rest}/exelearning/v1/preview/{previewId}/{file}`,
 *   permission_callback __return_true) -> {@see ExeLearning_Preview_Serving_Controller}.
 * - Authenticated, owner-scoped management API
 *   (`current_user_can('upload_files')` + per-session ownership) ->
 *   {@see ExeLearning_Preview_Management_Controller}.
 *
 * The atomic revisions / budgets / TTL live in ExeLearning_Preview_Session_Store;
 * the fixed layer in ExeLearning_Preview_Fixed_Resources; the CSP / MIME / header
 * knowledge in ExeLearning_Preview_Http_Headers.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Preview_Proxy.
 *
 * Registers the serving + management routes and the cleanup cron, delegating
 * every request to a serving or management controller.
 */
class ExeLearning_Preview_Proxy {

	/**
	 * REST namespace, shared with ExeLearning_REST_API.
	 *
	 * @var string
	 */
	const NAMESPACE = 'exelearning/v1';

	/**
	 * Canonical previewId shape (RFC-4122-style UUID). Anything else is a 404.
	 *
	 * @var string
	 */
	const PREVIEW_ID_REGEX = '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';

	/**
	 * Loose route-level previewId matcher (exact shape re-validated in the store).
	 *
	 * @var string
	 */
	const PREVIEW_ID_ROUTE = '[0-9a-f-]{36}';

	/**
	 * WP-Cron hook that runs the idle-session sweep.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'exelearning_preview_cleanup';

	/**
	 * Custom WP-Cron recurrence (15 minutes) for the sweep.
	 *
	 * @var string
	 */
	const CRON_SCHEDULE = 'exelearning_preview_15min';

	/**
	 * Owner-scoped management controller.
	 *
	 * @var ExeLearning_Preview_Management_Controller
	 */
	private $management;

	/**
	 * Authless serving controller.
	 *
	 * @var ExeLearning_Preview_Serving_Controller
	 */
	private $serving;

	/**
	 * Constructor. Builds the two controllers over a shared store + resolver,
	 * then registers routes, the cron schedule and the cleanup hook.
	 *
	 * The default store is deliberately outside `wp-content/uploads`: materialized
	 * author HTML/SVG/XML must never gain a direct same-origin web-server path that
	 * bypasses the serving controller's sandbox CSP. The filter is intended for a
	 * different PRIVATE directory only.
	 *
	 * @param ExeLearning_Preview_Session_Store|null   $store Injectable store,
	 *                                                        shared by both
	 *                                                        controllers.
	 * @param ExeLearning_Preview_Fixed_Resources|null $fixed Injectable resolver.
	 */
	public function __construct( $store = null, $fixed = null ) {
		if ( null === $store ) {
			$store = new ExeLearning_Preview_Session_Store( self::default_store_dir() );
		}
		if ( null === $fixed ) {
			$fixed = new ExeLearning_Preview_Fixed_Resources();
		}

		$headers          = new ExeLearning_Preview_Http_Headers();
		$this->management = new ExeLearning_Preview_Management_Controller( $store, $fixed );
		$this->serving    = new ExeLearning_Preview_Serving_Controller( $store, $fixed, $headers );

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedule' ) );
		add_action( self::CRON_HOOK, array( $this->management, 'run_cleanup' ) );
	}

	/**
	 * Resolve the private, site-scoped default session directory.
	 *
	 * `sys_get_temp_dir()` is used instead of `wp_upload_dir()` so untrusted
	 * materialized documents are private by construction on Apache, nginx, Caddy,
	 * CDN-backed uploads and managed hosting alike. The site key prevents two
	 * WordPress installations sharing the same system temp directory from sharing
	 * preview capabilities or budgets.
	 *
	 * @return string Absolute directory path without a trailing slash.
	 */
	public static function default_store_dir() {
		$site_identity = wp_normalize_path( ABSPATH ) . '|' . home_url( '/' );
		$site_key      = substr( hash( 'sha256', $site_identity ), 0, 16 );
		$default       = trailingslashit( sys_get_temp_dir() ) . 'exelearning-preview-' . $site_key;

		/**
		 * Filter the private preview-session directory.
		 *
		 * The selected directory must not be web-served. A custom public uploads
		 * path would reintroduce an unsandboxed route to authored documents.
		 *
		 * @param string $default Private site-scoped default path.
		 */
		$filtered = apply_filters( 'exelearning_preview_store_dir', $default );
		return untrailingslashit( (string) $filtered );
	}

	/**
	 * The owner-scoped management controller (write side).
	 *
	 * @return ExeLearning_Preview_Management_Controller
	 */
	public function management() {
		return $this->management;
	}

	/**
	 * The authless serving controller (read side).
	 *
	 * @return ExeLearning_Preview_Serving_Controller
	 */
	public function serving() {
		return $this->serving;
	}

	/**
	 * Register the authless serving route plus the authenticated management API,
	 * delegating each callback to its controller.
	 */
	public function register_routes() {
		// Serving: GET /preview/{previewId}/{file}  (authless capability URL).
		// The `file` default is empty so a bare capability URL is distinguishable
		// from an explicit .../index.html and redirects instead of serving bytes.
		register_rest_route(
			self::NAMESPACE,
			'/preview/(?P<previewId>' . self::PREVIEW_ID_ROUTE . ')(?:/(?P<file>.*))?',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this->serving, 'serve_preview' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'previewId' => array(
						'required' => true,
						'type'     => 'string',
					),
					'file'      => array(
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					),
				),
			)
		);

		// Management: create a session.
		register_rest_route(
			self::NAMESPACE,
			'/preview-session',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->management, 'create_session' ),
				'permission_callback' => array( $this->management, 'check_manage_permission' ),
			)
		);

		// Management: upload assets to a session.
		register_rest_route(
			self::NAMESPACE,
			'/preview-session/(?P<previewId>' . self::PREVIEW_ID_ROUTE . ')/assets',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->management, 'upload_assets' ),
				'permission_callback' => array( $this->management, 'check_manage_permission' ),
			)
		);

		// Management: publish a revision.
		register_rest_route(
			self::NAMESPACE,
			'/preview-session/(?P<previewId>' . self::PREVIEW_ID_ROUTE . ')/revisions',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->management, 'publish_revision' ),
				'permission_callback' => array( $this->management, 'check_manage_permission' ),
			)
		);

		// Management: delete a session.
		register_rest_route(
			self::NAMESPACE,
			'/preview-session/(?P<previewId>' . self::PREVIEW_ID_ROUTE . ')',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this->management, 'delete_session' ),
				'permission_callback' => array( $this->management, 'check_manage_permission' ),
			)
		);
	}

	/**
	 * Register the custom 15-minute cron recurrence used by the sweep.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function register_cron_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 minutes (eXeLearning preview cleanup)', 'exelearning' ),
			);
		}
		return $schedules;
	}
}
