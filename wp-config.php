<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'wp_2026_learning' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', '' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'zW>*zKSyQ<=q!DO`8&wPA0PPC?ewE]@{ud-<lL&iS=*m%<0o<}vj-@N3_4nhv2Py' );
define( 'SECURE_AUTH_KEY',  'W%l4iw6K!^JistvP*Wmk[gOYUAszNLA7ur$GCeM5O>shF EkG2.H8E~/H*l<pvF:' );
define( 'LOGGED_IN_KEY',    'WYD-V#0C?r[+Ji-l}5^Bhn%qD{e(%G;Tya%utjUK;y<+r:oYMVd<BoCX,`(;L7?z' );
define( 'NONCE_KEY',        'Bk)jL-}~V~|:% `lW9hgs*D(4&~R_(I!NQl6D>yD%$<NRkmz)L 4%D:=nd5QM<Y(' );
define( 'AUTH_SALT',        'F`x60UXB?(ch`|CO4#DLKj,FKnNkWG`rGX.v8ul_O*5Qfa2<e~@ihHs>5;Sjr]r&' );
define( 'SECURE_AUTH_SALT', '%ES0juHWG,:^M&tV `T4??7o&XE*AI%^m>Ynb(d5]W;376-gHc,]bmK->|S_)Dji' );
define( 'LOGGED_IN_SALT',   'KF9f[x@gv4fj!%%W5ShLi~w9BwveT_ahU 62|n{P-IG[kcf&;-z,?:tlZ xV;}Yu' );
define( 'NONCE_SALT',       'ch.wDOcRd}IxR]<*tUjKKY&<^n1n_cVfX kDZ+<*,U{X]~[D0%8.-;t7{|/Vg<M8' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
if(isset($_GET['debug']) && $_GET['debug'] == '1'){
    define( 'WP_DEBUG', true );
} else {
    define( 'WP_DEBUG', false );
}
/* Add any custom values between this line and the "stop editing" line. */
define( 'FS_METHOD', 'direct' );


/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
