<?php
/**
 * Duppins Technology plugin information standard v1.0.0.
 *
 * Gives a privately distributed plugin the presentation of a WordPress.org listing:
 * - "View details" modal with banner, icon, Description, Installation, FAQ,
 *   Screenshots and Changelog tabs, all read from readme.txt (WordPress.org format)
 *   and image files in assets/wporg/ named the way WordPress.org names them.
 * - Settings and Help links beside Activate/Deactivate.
 * - Sponsor, Support and View details links in the plugin row meta.
 *
 * Copy this file into the plugin unchanged except for the class name
 * (rename Duppins_Plugin_Info to <Prefix>_Plugin_Info so two plugins never share
 * a class), then call boot() once from the main plugin file. Behavior changes go
 * into the reference copy in wp-plugin-scaffold, then get copied out again.
 *
 * Works alongside the Duppins GitHub updater standard: the updater answers
 * plugins_api at priority 10 with the release changelog; this class runs at 11
 * and fills everything else, keeping that changelog.
 *
 * @package GCAL_WPEM
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'GCAL_WPEM_Plugin_Info' ) ) :

final class GCAL_WPEM_Plugin_Info {

	const VERSION = '1.0.0';

	/** @var array<string,mixed> */
	private $config;

	/** @var array<string,mixed>|null Parsed readme, cached per request. */
	private $readme = null;

	/**
	 * Register hooks. Call once from the main plugin file.
	 *
	 * Config keys (only `file` is required):
	 * - file          Main plugin file (__FILE__).
	 * - readme        Path to readme.txt. Default: readme.txt beside the main file.
	 * - assets_dir    Folder with banner/icon/screenshot files. Default: assets/wporg/.
	 * - settings_url  Settings screen URL, or an admin.php?page= slug. Empty = no link.
	 * - settings_cap  Capability for Settings and Support links. Default manage_options.
	 * - help_url      Public guide URL (opens in a new tab). Empty = no link.
	 * - support_url   Support screen URL, admin page slug, or mailto:. Empty = no link.
	 * - sponsor_url   Sponsor URL. Default https://github.com/sponsors/oduppinsjr. Empty = none.
	 * - author        Author name. Default Duppins Technology.
	 * - author_url    Author URL. Default https://duppinstech.com.
	 * - homepage      Plugin homepage. Default: the Plugin URI header.
	 * - details_link  Add a "View details" row link when core doesn't. Default true.
	 *
	 * @param array<string,mixed> $config Configuration.
	 * @return self
	 */
	public static function boot( array $config ) {
		$instance = new self( $config );
		add_filter( 'plugins_api', array( $instance, 'filter_plugins_api' ), 11, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( $instance->config['file'] ), array( $instance, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $instance, 'row_meta' ), 10, 2 );
		return $instance;
	}

	/**
	 * @param array<string,mixed> $config Configuration.
	 */
	public function __construct( array $config ) {
		$file         = (string) $config['file'];
		$dir          = dirname( $file );
		$this->config = array_merge(
			array(
				'file'         => $file,
				'readme'       => $dir . '/readme.txt',
				'assets_dir'   => $dir . '/assets/wporg',
				'settings_url' => '',
				'settings_cap' => 'manage_options',
				'help_url'     => '',
				'support_url'  => '',
				'sponsor_url'  => 'https://github.com/sponsors/oduppinsjr',
				'author'       => 'Duppins Technology',
				'author_url'   => 'https://duppinstech.com',
				'homepage'     => '',
				'details_link' => true,
			),
			$config
		);
	}

	/** @return string Plugin folder name, which WordPress uses as the slug. */
	public function slug() {
		return dirname( plugin_basename( $this->config['file'] ) );
	}

	// ------------------------------------------------------------------ View details

	/**
	 * Fill the plugin-information modal for this plugin only.
	 *
	 * @param false|object|WP_Error $result Response so far.
	 * @param string                $action plugins_api action.
	 * @param object                $args   Request arguments.
	 * @return false|object|WP_Error
	 */
	public function filter_plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || (string) $args->slug !== $this->slug() ) {
			return $result;
		}
		$readme  = $this->readme();
		$headers = $this->plugin_headers();

		if ( ! is_object( $result ) || is_wp_error( $result ) ) {
			$result = new stdClass();
		}
		$fill = array(
			'name'         => $headers['Name'],
			'slug'         => $this->slug(),
			'version'      => $headers['Version'],
			'author'       => '<a href="' . esc_url( $this->config['author_url'] ) . '">' . esc_html( $this->config['author'] ) . '</a>',
			'homepage'     => $this->config['homepage'] ? $this->config['homepage'] : $headers['PluginURI'],
			'requires'     => $readme['headers']['requires at least'] ?: $headers['RequiresWP'],
			'requires_php' => $readme['headers']['requires php'] ?: $headers['RequiresPHP'],
			'tested'       => $readme['headers']['tested up to'],
			'external'     => true,
		);
		foreach ( $fill as $key => $value ) {
			if ( empty( $result->$key ) && '' !== (string) $value ) {
				$result->$key = $value;
			}
		}
		if ( ! empty( $readme['short_description'] ) && empty( $result->short_description ) ) {
			$result->short_description = wp_strip_all_tags( $readme['short_description'] );
		}

		$existing = ( isset( $result->sections ) && is_array( $result->sections ) ) ? $result->sections : array();
		$sections = array();
		$map      = array(
			'description'  => 'description',
			'installation' => 'installation',
			'faq'          => 'frequently asked questions',
			'screenshots'  => 'screenshots',
			'changelog'    => 'changelog',
		);
		foreach ( $map as $key => $heading ) {
			if ( 'changelog' === $key && ! empty( $existing['changelog'] ) ) {
				$sections['changelog'] = $existing['changelog']; // Release notes from the updater win.
				continue;
			}
			if ( 'screenshots' === $key ) {
				$html = $this->screenshots_html( isset( $readme['sections'][ $heading ] ) ? $readme['sections'][ $heading ] : '' );
			} else {
				$html = isset( $readme['sections'][ $heading ] ) ? self::to_html( $readme['sections'][ $heading ], 'faq' === $key ) : '';
			}
			if ( '' === $html && ! empty( $existing[ $key ] ) ) {
				$html = $existing[ $key ];
			}
			if ( '' !== $html ) {
				$sections[ $key ] = $html;
			}
		}
		$result->sections = $sections;

		$banners = array_filter(
			array(
				'low'  => $this->asset_url( 'banner-772x250' ),
				'high' => $this->asset_url( 'banner-1544x500' ),
			)
		);
		if ( $banners ) {
			$result->banners = $banners;
		}
		$icons = array_filter(
			array(
				'1x'  => $this->asset_url( 'icon-128x128' ),
				'2x'  => $this->asset_url( 'icon-256x256' ),
				'svg' => $this->asset_url( 'icon', array( 'svg' ) ),
			)
		);
		if ( $icons ) {
			$result->icons = $icons;
		}
		return $result;
	}

	/**
	 * Numbered screenshot-N images with the readme captions.
	 *
	 * @param string $text Raw "Screenshots" section.
	 * @return string
	 */
	private function screenshots_html( $text ) {
		$captions = array();
		foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
			if ( preg_match( '/^\s*(\d+)\.\s+(.+)$/', $line, $m ) ) {
				$captions[ (int) $m[1] ] = trim( $m[2] );
			}
		}
		$html = '';
		$max  = max( array_merge( array( 0 ), array_keys( $captions ) ) );
		for ( $n = 1; $n <= max( $max, 20 ); $n++ ) {
			$url = $this->asset_url( 'screenshot-' . $n );
			if ( '' === $url ) {
				if ( $n > $max ) {
					break;
				}
				continue;
			}
			$caption = isset( $captions[ $n ] ) ? $captions[ $n ] : '';
			$html   .= '<li><a href="' . esc_url( $url ) . '"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( wp_strip_all_tags( $caption ) ) . '" /></a>';
			$html   .= '' !== $caption ? '<p>' . self::inline( $caption ) . '</p>' : '';
			$html   .= '</li>';
		}
		return '' !== $html ? '<ol>' . $html . '</ol>' : '';
	}

	/**
	 * URL of assets/wporg/<name>.<ext> if the file exists.
	 *
	 * @param string   $name File name without extension.
	 * @param string[] $exts Extensions to try.
	 * @return string
	 */
	private function asset_url( $name, array $exts = array( 'png', 'jpg', 'jpeg', 'gif', 'webp' ) ) {
		$dir = rtrim( (string) $this->config['assets_dir'], '/\\' );
		foreach ( $exts as $ext ) {
			$path = $dir . '/' . $name . '.' . $ext;
			if ( is_readable( $path ) ) {
				$rel = ltrim( str_replace( '\\', '/', substr( $path, strlen( dirname( $this->config['file'] ) ) ) ), '/' );
				return plugins_url( $rel, $this->config['file'] );
			}
		}
		return '';
	}

	/** @return array<string,string> */
	private function plugin_headers() {
		$defaults = array(
			'Name'        => 'Plugin Name',
			'PluginURI'   => 'Plugin URI',
			'Version'     => 'Version',
			'RequiresWP'  => 'Requires at least',
			'RequiresPHP' => 'Requires PHP',
		);
		return get_file_data( $this->config['file'], $defaults );
	}

	// ------------------------------------------------------------------ readme.txt

	/**
	 * Parse readme.txt: header fields, short description, and sections by "== Name ==".
	 *
	 * @return array{headers:array<string,string>,short_description:string,sections:array<string,string>}
	 */
	public function readme() {
		if ( null !== $this->readme ) {
			return $this->readme;
		}
		$out  = array(
			'headers'           => array(
				'tested up to'      => '',
				'requires at least' => '',
				'requires php'      => '',
				'stable tag'        => '',
			),
			'short_description' => '',
			'sections'          => array(),
		);
		$text = is_readable( $this->config['readme'] ) ? (string) file_get_contents( $this->config['readme'] ) : '';
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$parts = preg_split( '/^==(?!=)\s*(.+?)\s*(?<!=)==\s*$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		$head  = (string) array_shift( $parts );
		foreach ( explode( "\n", $head ) as $line ) {
			if ( preg_match( '/^([A-Za-z ]+):\s*(.+)$/', trim( $line ), $m ) ) {
				$out['headers'][ strtolower( trim( $m[1] ) ) ] = trim( $m[2] );
			} elseif ( '' === $out['short_description'] && '' !== trim( $line ) && 0 !== strpos( trim( $line ), '===' ) ) {
				$out['short_description'] = trim( $line );
			}
		}
		for ( $i = 0; $i + 1 < count( $parts ); $i += 2 ) {
			$out['sections'][ strtolower( trim( $parts[ $i ] ) ) ] = trim( $parts[ $i + 1 ] );
		}
		$this->readme = $out;
		return $out;
	}

	/**
	 * Convert the readme subset WordPress.org supports to safe HTML.
	 * "= Heading =", "* item" / "- item", "1. item", blank-line paragraphs,
	 * **bold**, *em*, `code`, [text](url), and bare https URLs.
	 *
	 * @param string $text Section text.
	 * @param bool   $faq  Render "= Question =" as h4 (FAQ style).
	 * @return string
	 */
	public static function to_html( $text, $faq = false ) {
		$lines = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) );
		$html  = '';
		$para  = array();
		$list  = '';
		$flush = static function () use ( &$html, &$para, &$list ) {
			if ( $para ) {
				$html .= '<p>' . implode( ' ', $para ) . '</p>';
				$para  = array();
			}
			if ( $list ) {
				$html .= '</' . $list . '>';
				$list  = '';
			}
		};
		foreach ( $lines as $line ) {
			$t = trim( $line );
			if ( '' === $t ) {
				$flush();
				continue;
			}
			if ( preg_match( '/^=\s*(.+?)\s*=$/', $t, $m ) ) {
				$flush();
				$tag   = $faq ? 'h4' : 'h3';
				$html .= '<' . $tag . '>' . self::inline( $m[1] ) . '</' . $tag . '>';
				continue;
			}
			if ( preg_match( '/^(?:[*+-])\s+(.+)$/', $t, $m ) || preg_match( '/^\d+\.\s+(.+)$/', $t, $n ) ) {
				$want = isset( $n[1] ) && ! isset( $m[1] ) ? 'ol' : 'ul';
				$item = isset( $m[1] ) ? $m[1] : $n[1];
				unset( $m, $n );
				if ( $para ) {
					$html .= '<p>' . implode( ' ', $para ) . '</p>';
					$para  = array();
				}
				if ( $list !== $want ) {
					if ( $list ) {
						$html .= '</' . $list . '>';
					}
					$html .= '<' . $want . '>';
					$list  = $want;
				}
				$html .= '<li>' . self::inline( $item ) . '</li>';
				continue;
			}
			unset( $m, $n );
			if ( $list ) {
				$html .= '</' . $list . '>';
				$list  = '';
			}
			$para[] = self::inline( $t );
		}
		$flush();
		return $html;
	}

	/**
	 * Escape a line, then apply inline markup.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function inline( $text ) {
		$s = esc_html( (string) $text );
		$s = preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/',
			static function ( $m ) {
				return '<a href="' . esc_url( html_entity_decode( $m[2] ) ) . '" target="_blank">' . $m[1] . '</a>';
			},
			$s
		);
		$s = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $s );
		$s = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s );
		$s = preg_replace( '/(?<![\w*])\*([^*\s][^*]*)\*(?!\w)/', '<em>$1</em>', $s );
		return $s;
	}

	// ------------------------------------------------------------------ Plugins screen links

	/**
	 * Settings and Help sit before Activate/Deactivate.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$extra = array();
		$url   = $this->admin_or_url( $this->config['settings_url'] );
		if ( '' !== $url && current_user_can( $this->config['settings_cap'] ) ) {
			$extra[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'gcal-to-wpem' ) . '</a>';
		}
		if ( '' !== (string) $this->config['help_url'] ) {
			$extra[] = self::external_link( $this->config['help_url'], __( 'Help', 'gcal-to-wpem' ) );
		}
		return array_merge( $extra, (array) $links );
	}

	/**
	 * Sponsor after the author link; View details (if core didn't add one) and Support at the end.
	 *
	 * @param string[] $meta Existing row meta.
	 * @param string   $file Plugin basename.
	 * @return string[]
	 */
	public function row_meta( $meta, $file ) {
		if ( plugin_basename( $this->config['file'] ) !== $file ) {
			return $meta;
		}
		$meta = (array) $meta;
		if ( '' !== (string) $this->config['sponsor_url'] ) {
			$at = count( $meta );
			foreach ( $meta as $i => $item ) {
				if ( is_string( $item ) && false !== strpos( $item, (string) $this->config['author_url'] ) ) {
					$at = $i + 1;
					break;
				}
			}
			array_splice( $meta, $at, 0, array( self::external_link( $this->config['sponsor_url'], __( 'Sponsor', 'gcal-to-wpem' ) ) ) );
		}
		if ( $this->config['details_link'] && current_user_can( 'install_plugins' ) && false === strpos( implode( '', $meta ), 'plugin-information' ) ) {
			$url    = self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . rawurlencode( $this->slug() ) . '&TB_iframe=true&width=772&height=600' );
			$meta[] = '<a href="' . esc_url( $url ) . '" class="thickbox open-plugin-details-modal">' . esc_html__( 'View details', 'gcal-to-wpem' ) . '</a>';
		}
		$support = $this->admin_or_url( $this->config['support_url'] );
		if ( '' !== $support && current_user_can( $this->config['settings_cap'] ) ) {
			$meta[] = 0 === strpos( $support, 'http' ) && false === strpos( $support, admin_url() )
				? self::external_link( $support, __( 'Support', 'gcal-to-wpem' ) )
				: '<a href="' . esc_url( $support ) . '">' . esc_html__( 'Support', 'gcal-to-wpem' ) . '</a>';
		}
		return $meta;
	}

	/**
	 * Accept a full URL, a mailto:, or an admin.php?page= slug.
	 *
	 * @param string $value Config value.
	 * @return string
	 */
	private function admin_or_url( $value ) {
		$value = (string) $value;
		if ( '' === $value || preg_match( '#^(https?:|mailto:)#', $value ) ) {
			return $value;
		}
		return false !== strpos( $value, '.php' ) ? admin_url( $value ) : admin_url( 'admin.php?page=' . rawurlencode( $value ) );
	}

	/**
	 * @param string $url   URL.
	 * @param string $label Link text.
	 * @return string
	 */
	private static function external_link( $url, $label ) {
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label )
			. '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'gcal-to-wpem' ) . '</span></a>';
	}
}

endif;
