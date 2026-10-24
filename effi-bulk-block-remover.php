<?php
/**
 * Plugin Name:       Effi Bulk Block Remover
 * Plugin URI:        https://github.com/effi10/effi-bulk-block-remover
 * Description:       Retire en masse un bloc Gutenberg (y compris le bloc d'une extension désinstallée) des contenus d'un type donné, avec simulation, sauvegarde de chaque contenu modifié et restauration.
 * Version:           1.1.0
 * Requires at least: 5.3.1
 * Requires PHP:      7.4
 * Author:            Cédric Girard
 * Author URI:        https://www.effi10.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       effi-bulk-block-remover
 * Update URI:        https://github.com/effi10/effi-bulk-block-remover
 *
 * @package Effi_Bulk_Block_Remover
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EFFI_BBR_VERSION', '1.1.0' );
define( 'EFFI_BBR_FILE', __FILE__ );

/**
 * Logique pure de retrait de blocs : travaille sur des chaînes et des tableaux en mémoire,
 * sans jamais lire ni écrire en base.
 */
final class Effi_BBR_Blocks {

	/**
	 * Format accepté pour un nom de bloc (celui qu'impose le registre des blocs de WordPress).
	 * Modificateur D : « $ » n'accepte pas de saut de ligne final.
	 */
	const NAME_PATTERN = '#^[a-z0-9-]+/[a-z0-9-]+$#D';

	/**
	 * Expression du cœur (WP_Block_Parser::next_token()) qui repère les délimiteurs de blocs.
	 */
	const DELIMITER_PATTERN = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Indique si un nom de bloc est valide (ex. « core/paragraph », « acme/mon-bloc »).
	 *
	 * @param mixed $name Nom à vérifier.
	 * @return bool
	 */
	public static function is_valid_block_name( $name ) {
		return is_string( $name ) && 1 === preg_match( self::NAME_PATTERN, $name );
	}

	/**
	 * Indique si le contenu peut contenir le bloc (test rapide, sans analyse).
	 *
	 * Un bloc « core/xxx » peut être écrit « wp:xxx » ou « wp:core/xxx » ; les autres « wp:extension/xxx ».
	 *
	 * @param string $content    Contenu brut.
	 * @param string $block_name Nom complet du bloc.
	 * @return bool
	 */
	public static function may_contain( $content, $block_name ) {
		if ( false !== strpos( $content, 'wp:' . $block_name ) ) {
			return true;
		}
		return 0 === strpos( $block_name, 'core/' ) && false !== strpos( $content, 'wp:' . substr( $block_name, 5 ) );
	}

	/**
	 * Compte les blocs nommés présents dans une liste de blocs analysés, à tous les niveaux d'imbrication.
	 *
	 * @param array $blocks Blocs issus de parse_blocks().
	 * @param array $names  Compteur (nom => occurrences), complété par référence.
	 * @return void
	 */
	public static function collect_block_names( array $blocks, array &$names ) {
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$name           = $block['blockName'];
				$names[ $name ] = ( isset( $names[ $name ] ) ? $names[ $name ] : 0 ) + 1;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::collect_block_names( $block['innerBlocks'], $names );
			}
		}
	}

	/**
	 * Retire (en mémoire) toutes les occurrences d'un bloc d'un contenu et vérifie que le résultat est fiable.
	 *
	 * Le contenu est reconstruit avec serialize_blocks() : les autres blocs gardent leurs délimiteurs,
	 * leurs attributs et leur HTML enregistré (aucun rendu, contrairement à render_block()).
	 *
	 * @param string     $content    Contenu brut (post_content).
	 * @param string     $block_name Nom du bloc à retirer.
	 * @param array|null $blocks     Résultat de parse_blocks( $content ) s'il est déjà disponible.
	 * @return array {
	 *     @type int    $found   Occurrences du bloc retirées (y compris celles imbriquées dans un bloc retiré).
	 *     @type int    $inner   Autres blocs supprimés avec eux (contenus dans un bloc retiré).
	 *     @type string $content Nouveau contenu ; contenu d'origine si rien n'est retiré ou en cas d'erreur.
	 *     @type string $error   '' si le contenu peut être enregistré, sinon un code d'erreur.
	 *     @type bool   $empty   Le contenu serait vide après le retrait.
	 * }
	 */
	public static function remove_from_content( $content, $block_name, $blocks = null ) {
		$result = array(
			'found'   => 0,
			'inner'   => 0,
			'content' => $content,
			'error'   => '',
			'empty'   => false,
		);

		if ( '' === $content || ! self::is_valid_block_name( $block_name ) || ! self::may_contain( $content, $block_name ) ) {
			return $result;
		}

		if ( null === $blocks ) {
			$blocks = parse_blocks( $content );
		}

		$stats = array(
			'found'         => 0,
			'inner'         => 0,
			'invalid_attrs' => 0,
		);

		$new_blocks = self::filter_top_level( $blocks, $block_name, $stats );

		$result['found'] = $stats['found'];
		$result['inner'] = $stats['inner'];

		if ( 0 === $stats['found'] ) {
			return $result;
		}

		// Un bloc conservé dont les attributs JSON sont illisibles perdrait ses attributs à la reconstruction.
		if ( $stats['invalid_attrs'] > 0 ) {
			$result['error'] = 'invalid_attrs';
			return $result;
		}

		// Bloc non fermé ou fermeture orpheline : le parseur « répare » la structure, on ne prend pas ce risque.
		if ( ! self::delimiters_balanced( $content ) ) {
			$result['error'] = 'unbalanced';
			return $result;
		}

		$new_content = self::serialize( $new_blocks );

		// Contrôle final : le nouveau contenu, relu par le parseur, doit donner exactement l'arbre attendu.
		if ( self::canonical( parse_blocks( $new_content ) ) !== self::canonical( $new_blocks ) ) {
			$result['error'] = 'verification';
			return $result;
		}

		$result['content'] = $new_content;
		$result['empty']   = '' === trim( $new_content );

		return $result;
	}

	/**
	 * serialize_blocks() avec serialize_precision à -1 : json_encode() suit ce réglage, et certaines
	 * configurations (17) réécriraient l'attribut 4.6 des blocs voisins en 4.5999999999999996.
	 *
	 * @param array $blocks Blocs à sérialiser.
	 * @return string
	 */
	public static function serialize( array $blocks ) {
		$precision = ini_get( 'serialize_precision' );
		$changed   = false !== $precision && '-1' !== $precision && function_exists( 'ini_set' ) && false !== ini_set( 'serialize_precision', '-1' );

		$content = serialize_blocks( $blocks );

		if ( $changed ) {
			ini_set( 'serialize_precision', $precision ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}

		return $content;
	}

	/**
	 * Vérifie que chaque bloc ouvert est fermé et qu'aucune fermeture n'est orpheline.
	 *
	 * @param string $content Contenu brut.
	 * @return bool
	 */
	public static function delimiters_balanced( $content ) {
		$matches = array();
		$count   = preg_match_all( self::DELIMITER_PATTERN, $content, $matches );

		if ( false === $count ) {
			return false; // Échec PCRE (limite de backtracking) : dans le doute, on refuse.
		}

		$depth = 0;
		foreach ( array_keys( $matches[0] ) as $i ) {
			if ( '' !== $matches['void'][ $i ] ) {
				continue;
			}
			if ( '' !== $matches['closer'][ $i ] ) {
				--$depth;
				if ( $depth < 0 ) {
					return false;
				}
				continue;
			}
			++$depth;
		}

		return 0 === $depth;
	}

	/**
	 * Forme canonique d'un arbre de blocs (nom, attributs, HTML et blocs internes dans l'ordre),
	 * qui permet de comparer deux arbres indépendamment du découpage des chaînes.
	 *
	 * @param array $blocks Blocs (format parse_blocks()).
	 * @return array
	 */
	public static function canonical( array $blocks ) {
		$items = array();
		foreach ( $blocks as $block ) {
			$items[] = null === $block['blockName'] ? (string) $block['innerHTML'] : $block;
		}
		return self::canonical_sequence( $items );
	}

	/**
	 * Forme canonique d'une séquence de chaînes et de blocs.
	 *
	 * @param array $items Chaînes HTML et blocs.
	 * @return array
	 */
	private static function canonical_sequence( array $items ) {
		$out = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				$out[] = array(
					'name'    => $item['blockName'],
					'attrs'   => $item['attrs'],
					'content' => self::canonical_sequence( self::block_to_items( $item ) ),
				);
				continue;
			}
			if ( '' === $item ) {
				continue;
			}
			$last = count( $out ) - 1;
			if ( $last >= 0 && is_string( $out[ $last ] ) ) {
				$out[ $last ] .= $item;
			} else {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * Remplace chaque marqueur null de innerContent par le bloc interne correspondant.
	 *
	 * @param array $block Bloc analysé.
	 * @return array Chaînes HTML et blocs internes, dans l'ordre du contenu.
	 */
	private static function block_to_items( array $block ) {
		$items = array();
		$index = 0;
		foreach ( (array) $block['innerContent'] as $chunk ) {
			if ( is_string( $chunk ) ) {
				$items[] = $chunk;
				continue;
			}
			if ( isset( $block['innerBlocks'][ $index ] ) ) {
				$items[] = $block['innerBlocks'][ $index ];
			}
			++$index;
		}
		return $items;
	}

	/**
	 * Retire le bloc ciblé au premier niveau (les blocs « libres », sans nom, sont traités comme du HTML).
	 *
	 * @param array  $blocks     Blocs de premier niveau.
	 * @param string $block_name Bloc à retirer.
	 * @param array  $stats      Compteurs, complétés par référence.
	 * @return array Nouveaux blocs de premier niveau.
	 */
	private static function filter_top_level( array $blocks, $block_name, array &$stats ) {
		$items = array();
		foreach ( $blocks as $block ) {
			$items[] = null === $block['blockName'] ? (string) $block['innerHTML'] : $block;
		}

		$new_blocks = array();
		foreach ( self::filter_sequence( $items, $block_name, $stats ) as $item ) {
			if ( is_array( $item ) ) {
				$new_blocks[] = $item;
				continue;
			}
			$new_blocks[] = array(
				'blockName'    => null,
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => $item,
				'innerContent' => array( $item ),
			);
		}
		return $new_blocks;
	}

	/**
	 * Retire le bloc ciblé des blocs internes d'un bloc conservé (récursif).
	 *
	 * Retirer un bloc interne revient à retirer son marqueur null de innerContent et son entrée de innerBlocks.
	 *
	 * @param array  $block      Bloc conservé.
	 * @param string $block_name Bloc à retirer.
	 * @param array  $stats      Compteurs, complétés par référence.
	 * @return array Bloc mis à jour.
	 */
	private static function filter_block( array $block, $block_name, array &$stats ) {
		if ( null !== $block['blockName'] && ! is_array( $block['attrs'] ) ) {
			++$stats['invalid_attrs']; // attrs vaut null quand le JSON du délimiteur est invalide.
		}

		if ( empty( $block['innerBlocks'] ) ) {
			return $block;
		}

		$inner_blocks  = array();
		$inner_content = array();
		$inner_html    = '';

		foreach ( self::filter_sequence( self::block_to_items( $block ), $block_name, $stats ) as $item ) {
			if ( is_array( $item ) ) {
				$inner_blocks[]  = $item;
				$inner_content[] = null;
			} else {
				$inner_content[] = $item;
				$inner_html     .= $item;
			}
		}

		$block['innerBlocks']  = $inner_blocks;
		$block['innerHTML']    = $inner_html;
		$block['innerContent'] = $inner_content;

		return $block;
	}

	/**
	 * Filtre une séquence de chaînes HTML et de blocs : retire le bloc ciblé et descend dans les autres.
	 *
	 * Là où un bloc a été retiré, deux chaînes deviennent voisines : si l'une n'est que du blanc
	 * (séparateur « \n\n » entre deux blocs), elle est retirée ; sinon les blancs qui se touchent
	 * à la jonction sont réduits à ceux de la première. Seuls des blancs voisins du bloc retiré
	 * sont concernés : le reste du HTML n'est jamais modifié.
	 *
	 * @param array  $items      Chaînes HTML et blocs.
	 * @param string $block_name Bloc à retirer.
	 * @param array  $stats      Compteurs, complétés par référence.
	 * @return array
	 */
	private static function filter_sequence( array $items, $block_name, array &$stats ) {
		$out  = array();
		$hole = false; // Vrai juste après un bloc retiré.

		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				if ( isset( $item['blockName'] ) && $block_name === $item['blockName'] ) {
					self::count_removed( $item, $block_name, $stats );
					$hole = true;
					continue;
				}
				$out[] = self::filter_block( $item, $block_name, $stats );
				$hole  = false;
				continue;
			}

			$item = (string) $item;
			if ( '' === $item ) {
				continue;
			}

			$last_index = count( $out ) - 1;
			$last       = $last_index >= 0 ? $out[ $last_index ] : null;

			if ( $hole ) {
				if ( null === $last ) {
					if ( self::is_blank( $item ) ) {
						continue; // Blanc laissé en tête par le retrait.
					}
					$item = ltrim( $item );
				} elseif ( is_string( $last ) ) {
					if ( self::is_blank( $item ) ) {
						continue; // Séparateur devenu inutile.
					}
					if ( self::is_blank( $last ) ) {
						$out[ $last_index ] = $item; // Séparateur précédent devenu inutile.
						$hole               = false;
						continue;
					}
					if ( rtrim( $last ) !== $last ) {
						$item = ltrim( $item ); // Un seul jeu de blancs à la jonction.
					}
				}
			}

			if ( is_string( $last ) ) {
				$out[ $last_index ] .= $item;
			} else {
				$out[] = $item;
			}
			$hole = false;
		}

		if ( $hole ) {
			$last_index = count( $out ) - 1;
			if ( $last_index >= 0 && is_string( $out[ $last_index ] ) ) {
				if ( self::is_blank( $out[ $last_index ] ) ) {
					array_pop( $out ); // Blanc laissé en fin par le retrait.
				} else {
					$out[ $last_index ] = rtrim( $out[ $last_index ] );
				}
			}
		}

		return $out;
	}

	/**
	 * Compte un bloc retiré et ce qu'il contient.
	 *
	 * @param array  $block      Bloc retiré (ou bloc contenu dans un bloc retiré).
	 * @param string $block_name Bloc ciblé.
	 * @param array  $stats      Compteurs, complétés par référence.
	 * @return void
	 */
	private static function count_removed( array $block, $block_name, array &$stats ) {
		if ( $block_name === $block['blockName'] ) {
			++$stats['found'];
		} elseif ( null !== $block['blockName'] ) {
			++$stats['inner'];
		}
		foreach ( (array) $block['innerBlocks'] as $inner ) {
			self::count_removed( $inner, $block_name, $stats );
		}
	}

	/**
	 * Indique si une chaîne ne contient que des blancs.
	 *
	 * @param string $text Chaîne.
	 * @return bool
	 */
	private static function is_blank( $text ) {
		return '' === trim( $text );
	}
}

/**
 * Page d'outil, actions AJAX par lots, sauvegardes et restauration.
 */
final class Effi_BBR_Plugin {

	const PAGE_SLUG         = 'effi-bulk-block-remover';
	const CAPABILITY        = 'manage_options';
	const NONCE_ACTION      = 'effi_bbr_ajax';
	const OPTION_OPERATIONS = 'effi_bbr_operations';
	const OPTION_LOCK       = 'effi_bbr_lock';
	const META_PREFIX       = '_effi_bbr_backup_';
	const BATCH_SIZE        = 25;
	const LOCK_TIMEOUT      = 300;
	const MAX_OPERATIONS    = 30;
	const HISTORY_SIZE      = 10;
	const STATUSES          = array( 'publish', 'draft', 'pending', 'future', 'private' );

	/**
	 * Instance unique.
	 *
	 * @var Effi_BBR_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Suffixe de la page d'outil (pour ne charger le script que sur elle).
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Vrai quand la requête courante détient le verrou de traitement.
	 *
	 * @var bool
	 */
	private $has_lock = false;

	/**
	 * Dates de modification à imposer, par identifiant de contenu (filtre wp_insert_post_data).
	 *
	 * @var array
	 */
	private $forced_dates = array();

	/**
	 * Retourne l'instance unique.
	 *
	 * @return Effi_BBR_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Enregistre les crochets.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		$actions = array( 'scan', 'start_removal', 'removal_batch', 'start_restore', 'restore_batch', 'purge' );
		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_effi_bbr_' . $action, array( $this, 'ajax_' . $action ) );
		}
	}

	/**
	 * Ajoute la page dans le menu Outils.
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		$this->hook_suffix = (string) add_submenu_page(
			'tools.php',
			__( 'Suppression de blocs en masse', 'effi-bulk-block-remover' ),
			__( 'Suppression de blocs en masse', 'effi-bulk-block-remover' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Charge le script sur la seule page de l'outil.
	 *
	 * @param string $hook_suffix Page courante.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'effi-bbr-admin',
			plugins_url( 'script.js', EFFI_BBR_FILE ),
			array( 'jquery' ),
			EFFI_BBR_VERSION,
			true
		);

		wp_localize_script(
			'effi-bbr-admin',
			'effiBbr',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
				'canWrite' => current_user_can( 'unfiltered_html' ) ? '1' : '',
				'state'    => $this->get_state(),
				'i18n'     => $this->get_js_strings(),
			)
		);
	}

	/**
	 * Affiche la page de l'outil.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Vous n’avez pas les droits nécessaires pour accéder à cette page.', 'effi-bulk-block-remover' ) );
		}

		$post_types  = $this->get_allowed_post_types();
		$block_types = WP_Block_Type_Registry::get_instance()->get_all_registered();
		ksort( $block_types );
		?>
		<div class="wrap effi-bbr">
			<h1><?php esc_html_e( 'Suppression de blocs en masse', 'effi-bulk-block-remover' ); ?></h1>

			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Avertissement : opération en masse qui modifie vos contenus.', 'effi-bulk-block-remover' ); ?></strong>
					<?php esc_html_e( 'Cet outil réécrit directement en base le contenu de tous les éléments du type choisi qui contiennent le bloc ciblé.', 'effi-bulk-block-remover' ); ?>
				</p>
				<ul style="list-style:disc;margin-left:2em;">
					<li><strong><?php esc_html_e( 'Faites une sauvegarde complète de la base de données avant toute suppression.', 'effi-bulk-block-remover' ); ?></strong> <?php esc_html_e( 'La sauvegarde intégrée à l’outil (copie de chaque contenu modifié) ne la remplace pas.', 'effi-bulk-block-remover' ); ?></li>
					<li><?php esc_html_e( 'Testez d’abord en mode simulation : il liste les contenus concernés sans rien modifier.', 'effi-bulk-block-remover' ); ?></li>
					<li><?php esc_html_e( 'Idéalement, faites d’abord l’opération sur une préproduction (copie du site), vérifiez les pages concernées, puis reproduisez-la en production.', 'effi-bulk-block-remover' ); ?></li>
				</ul>
			</div>

			<?php if ( ! current_user_can( 'unfiltered_html' ) ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'Votre compte n’a pas la capacité « unfiltered_html » (cas d’un multisite ou de la constante DISALLOW_UNFILTERED_HTML). Enregistrés avec ce compte, les contenus seraient filtrés par WordPress (iframes, scripts, formulaires supprimés) : la suppression et la restauration sont donc désactivées. L’inventaire et la simulation restent possibles.', 'effi-bulk-block-remover' ); ?></p>
				</div>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="effi-bbr-post-type"><?php esc_html_e( 'Type de contenu', 'effi-bulk-block-remover' ); ?></label></th>
					<td>
						<select id="effi-bbr-post-type">
							<?php foreach ( $post_types as $post_type ) : ?>
								<option value="<?php echo esc_attr( $post_type->name ); ?>"><?php echo esc_html( $post_type->labels->name . ' (' . $post_type->name . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Statuts traités : publié, brouillon, en attente de relecture, programmé et privé.', 'effi-bulk-block-remover' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="effi-bbr-block-select"><?php esc_html_e( 'Bloc à retirer', 'effi-bulk-block-remover' ); ?></label></th>
					<td>
						<select id="effi-bbr-block-select">
							<option value=""><?php esc_html_e( '— Choisissez un bloc —', 'effi-bulk-block-remover' ); ?></option>
							<optgroup id="effi-bbr-registered-blocks" label="<?php esc_attr_e( 'Blocs enregistrés sur le site', 'effi-bulk-block-remover' ); ?>">
								<?php
								foreach ( $block_types as $block_type ) :
									$label = $block_type->name;
									if ( ! empty( $block_type->title ) ) {
										$label .= ' — ' . $block_type->title;
									}
									?>
									<option value="<?php echo esc_attr( $block_type->name ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</optgroup>
							<option value="__custom"><?php esc_html_e( 'Autre : saisir le nom du bloc…', 'effi-bulk-block-remover' ); ?></option>
						</select>
						<p id="effi-bbr-custom-wrap" hidden>
							<label for="effi-bbr-block-custom" class="screen-reader-text"><?php esc_html_e( 'Nom du bloc', 'effi-bulk-block-remover' ); ?></label>
							<input type="text" id="effi-bbr-block-custom" class="regular-text code" placeholder="extension/nom-du-bloc" autocomplete="off" spellcheck="false" />
						</p>
						<p class="description"><?php esc_html_e( 'Bloc d’une extension désinstallée (non enregistré) : lancez l’inventaire, la liste proposera alors les blocs réellement présents dans les contenus du type choisi. Vous pouvez aussi saisir son nom (format extension/nom-du-bloc, en minuscules).', 'effi-bulk-block-remover' ); ?></p>
						<p class="description"><?php esc_html_e( 'Le bloc est retiré avec tout ce qu’il contient, y compris les blocs placés à l’intérieur.', 'effi-bulk-block-remover' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'effi-bulk-block-remover' ); ?></th>
					<td>
						<label for="effi-bbr-preserve-date">
							<input type="checkbox" id="effi-bbr-preserve-date" value="1" checked="checked" />
							<?php esc_html_e( 'Conserver la date de modification des contenus', 'effi-bulk-block-remover' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Recommandé : la date « modifié le » (sitemaps, mentions « mis à jour le ») reste celle d’avant le retrait.', 'effi-bulk-block-remover' ); ?></p>
					</td>
				</tr>
			</table>

			<p>
				<button type="button" class="button" id="effi-bbr-inventory"><?php esc_html_e( 'Inventorier les blocs présents', 'effi-bulk-block-remover' ); ?></button>
				<button type="button" class="button button-secondary" id="effi-bbr-simulate"><?php esc_html_e( 'Simuler la suppression', 'effi-bulk-block-remover' ); ?></button>
				<button type="button" class="button button-primary" id="effi-bbr-remove" disabled="disabled"><?php esc_html_e( 'Retirer les blocs', 'effi-bulk-block-remover' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'Le bouton « Retirer les blocs » s’active après une simulation qui trouve le bloc. Avant chaque modification, l’ancien contenu est sauvegardé : l’opération peut ensuite être restaurée.', 'effi-bulk-block-remover' ); ?></p>

			<div id="effi-bbr-progress" hidden>
				<p><span id="effi-bbr-progress-label"></span> <span id="effi-bbr-progress-text"></span></p>
				<progress id="effi-bbr-progress-bar" style="width:100%;max-width:640px;"></progress>
			</div>

			<div id="effi-bbr-results" aria-live="polite"></div>

			<h2><?php esc_html_e( 'Opérations et restauration', 'effi-bulk-block-remover' ); ?></h2>
			<div id="effi-bbr-operations"></div>

			<noscript><p><?php esc_html_e( 'Cet outil nécessite JavaScript.', 'effi-bulk-block-remover' ); ?></p></noscript>
		</div>
		<?php
	}

	/*
	 * ---------------------------------------------------------------------
	 * Actions AJAX
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Analyse un lot de contenus sans rien modifier : inventaire des blocs présents
	 * et, si un bloc est indiqué, simulation détaillée de son retrait.
	 *
	 * @return void
	 */
	public function ajax_scan() {
		$this->verify_request();

		$post_type  = $this->read_post_type();
		$block_name = $this->read_block_name( false );
		$cursor     = self::read_int( 'cursor' );
		$registry   = WP_Block_Type_Registry::get_instance();
		$ids        = $this->get_batch_ids( $post_type, $cursor );

		$data = array(
			'scanned' => count( $ids ),
			'cursor'  => $cursor,
			'done'    => count( $ids ) < self::BATCH_SIZE,
			'matches' => array(),
			'blocks'  => array(),
		);
		if ( 0 === $cursor ) {
			$data['total'] = $this->count_posts( $post_type );
		}

		foreach ( $ids as $id ) {
			$data['cursor'] = $id;
			$post           = get_post( $id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$blocks = parse_blocks( $post->post_content );
			$names  = array();
			Effi_BBR_Blocks::collect_block_names( $blocks, $names );

			foreach ( $names as $name => $occurrences ) {
				if ( ! isset( $data['blocks'][ $name ] ) ) {
					$data['blocks'][ $name ] = array(
						'posts'       => 0,
						'occurrences' => 0,
						'registered'  => $registry->is_registered( $name ),
						'valid'       => Effi_BBR_Blocks::is_valid_block_name( $name ),
					);
				}
				++$data['blocks'][ $name ]['posts'];
				$data['blocks'][ $name ]['occurrences'] += $occurrences;
			}

			if ( '' !== $block_name && isset( $names[ $block_name ] ) ) {
				$analysis          = Effi_BBR_Blocks::remove_from_content( $post->post_content, $block_name, $blocks );
				$issues            = $this->get_issues( $post, $analysis );
				$data['matches'][] = $this->describe_post( $post ) + array(
					'found'    => $analysis['found'],
					'inner'    => $analysis['inner'],
					'issues'   => $issues,
					'blocking' => $this->has_blocking_issue( $issues ),
				);
			}
		}

		wp_send_json_success( $data );
	}

	/**
	 * Crée une opération de suppression (le traitement se fait ensuite par lots).
	 *
	 * @return void
	 */
	public function ajax_start_removal() {
		$this->verify_request();
		$this->require_unfiltered_html();

		$post_type     = $this->read_post_type();
		$block_name    = $this->read_block_name( true );
		$preserve_date = '1' === self::read_string( 'preserve_date' );

		$this->lock_or_fail();
		$result = $this->create_operation( $post_type, $block_name, $preserve_date );
		$this->release_lock();

		$this->respond( $result );
	}

	/**
	 * Traite le lot suivant d'une opération de suppression.
	 *
	 * @return void
	 */
	public function ajax_removal_batch() {
		$this->verify_request();
		$this->require_unfiltered_html();

		$operation_id = $this->read_operation_id();

		$this->lock_or_fail();
		$result = $this->process_removal_batch( $operation_id );
		$this->release_lock();

		$this->respond( $result );
	}

	/**
	 * Prépare (ou reprend) la restauration de la dernière opération.
	 *
	 * @return void
	 */
	public function ajax_start_restore() {
		$this->verify_request();
		$this->require_unfiltered_html();

		$operation_id = $this->read_operation_id();

		$this->lock_or_fail();
		$result = $this->begin_restore( $operation_id );
		$this->release_lock();

		$this->respond( $result );
	}

	/**
	 * Restaure le lot suivant de contenus sauvegardés.
	 *
	 * @return void
	 */
	public function ajax_restore_batch() {
		$this->verify_request();
		$this->require_unfiltered_html();

		$operation_id = $this->read_operation_id();

		$this->lock_or_fail();
		$result = $this->process_restore_batch( $operation_id );
		$this->release_lock();

		$this->respond( $result );
	}

	/**
	 * Supprime la sauvegarde de la dernière opération (elle ne pourra plus être restaurée).
	 *
	 * @return void
	 */
	public function ajax_purge() {
		$this->verify_request();

		$operation_id = $this->read_operation_id();

		$this->lock_or_fail();
		$result = $this->purge_operation( $operation_id );
		$this->release_lock();

		$this->respond( $result );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Traitements
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Crée et enregistre une nouvelle opération.
	 *
	 * @param string $post_type     Type de contenu (validé).
	 * @param string $block_name    Bloc à retirer (validé).
	 * @param bool   $preserve_date Conserver la date de modification.
	 * @return array|WP_Error
	 */
	private function create_operation( $post_type, $block_name, $preserve_date ) {
		$operations = $this->get_operations();

		foreach ( $operations as $operation ) {
			if ( in_array( $operation['status'], array( 'running', 'restoring' ), true ) ) {
				return new WP_Error(
					'unfinished',
					__( 'Une opération précédente n’est pas terminée : reprenez-la, restaurez-la ou supprimez sa sauvegarde (section « Opérations et restauration ») avant d’en lancer une autre.', 'effi-bulk-block-remover' )
				);
			}
		}

		$id  = gmdate( 'Ymd_His' ) . '_' . strtolower( wp_generate_password( 6, false, false ) );
		$now = time();

		$operation = array(
			'id'             => $id,
			'created'        => $now,
			'updated'        => $now,
			'user_id'        => get_current_user_id(),
			'post_type'      => $post_type,
			'block_name'     => $block_name,
			'preserve_date'  => (bool) $preserve_date,
			'status'         => 'running',
			'cursor'         => 0,
			'total'          => $this->count_posts( $post_type ),
			'processed'      => 0,
			'modified'       => 0,
			'removed'        => 0,
			'errors'         => 0,
			'restore_cursor' => 0,
			'restored'       => 0,
			'skipped'        => 0,
			'restore_errors' => 0,
		);

		$operations[ $id ] = $operation;
		$this->save_operations( $operations );

		return array(
			'operation' => $this->summarize( $operation ),
			'state'     => $this->get_state(),
		);
	}

	/**
	 * Traite un lot de contenus pour une opération de suppression.
	 *
	 * Le curseur (dernier ID traité) est conservé côté serveur : un traitement interrompu
	 * reprend exactement là où il s'est arrêté.
	 *
	 * @param string $operation_id Identifiant de l'opération.
	 * @return array|WP_Error
	 */
	private function process_removal_batch( $operation_id ) {
		$operation = $this->get_operation( $operation_id );
		if ( ! $operation ) {
			return new WP_Error( 'not_found', __( 'Opération introuvable.', 'effi-bulk-block-remover' ) );
		}
		if ( 'running' !== $operation['status'] ) {
			return new WP_Error( 'not_running', __( 'Cette opération n’est plus en cours (terminée, restaurée ou clôturée).', 'effi-bulk-block-remover' ) );
		}
		if ( ! post_type_exists( $operation['post_type'] ) || ! Effi_BBR_Blocks::is_valid_block_name( $operation['block_name'] ) ) {
			return new WP_Error( 'invalid_operation', __( 'Le type de contenu de cette opération n’existe plus.', 'effi-bulk-block-remover' ) );
		}

		$ids    = $this->get_batch_ids( $operation['post_type'], (int) $operation['cursor'] );
		$report = array(
			'modified' => array(),
			'errors'   => array(),
		);

		$this->forced_dates = array();
		add_filter( 'wp_insert_post_data', array( $this, 'filter_forced_dates' ), 9999, 2 );

		try {
			foreach ( $ids as $id ) {
				$outcome = $this->remove_in_post( $id, $operation );

				$operation['cursor'] = $id;
				++$operation['processed'];

				if ( 'modified' === $outcome['status'] ) {
					++$operation['modified'];
					$operation['removed'] += $outcome['removed'];
					$report['modified'][]  = $outcome['post'] + array( 'removed' => $outcome['removed'] );
				} elseif ( 'error' === $outcome['status'] ) {
					++$operation['errors'];
					$report['errors'][] = $outcome['post'] + array( 'message' => $outcome['message'] );
				}

				if ( 'unchanged' !== $outcome['status'] ) {
					// Enregistré après chaque contenu touché : l'état reste juste même si la requête est interrompue.
					$operation['updated'] = time();
					$this->save_operation( $operation );
				}
			}
		} finally {
			// Filtre temporaire : retiré à la fin de chaque lot, quoi qu'il arrive.
			remove_filter( 'wp_insert_post_data', array( $this, 'filter_forced_dates' ), 9999 );
			$this->forced_dates = array();
		}

		$done = count( $ids ) < self::BATCH_SIZE;
		if ( $done ) {
			$operation['status'] = 'done';
		}
		$operation['updated'] = time();
		$this->save_operation( $operation );

		return array(
			'done'      => $done,
			'processed' => count( $ids ),
			'report'    => $report,
			'operation' => $this->summarize( $operation ),
			'state'     => $this->get_state(),
		);
	}

	/**
	 * Retire le bloc d'un contenu : vérifications, sauvegarde, puis enregistrement.
	 *
	 * @param int   $id        Identifiant du contenu.
	 * @param array $operation Opération en cours.
	 * @return array Statut « unchanged », « modified » ou « error ».
	 */
	private function remove_in_post( $id, array $operation ) {
		$outcome = array(
			'status'  => 'unchanged',
			'removed' => 0,
			'message' => '',
			'post'    => array(),
		);

		$post = get_post( $id );
		if ( ! $post instanceof WP_Post ) {
			return $outcome;
		}

		$analysis = Effi_BBR_Blocks::remove_from_content( $post->post_content, $operation['block_name'] );
		if ( 0 === $analysis['found'] ) {
			return $outcome; // Bloc absent : le contenu n'est pas touché.
		}

		$outcome['post'] = $this->describe_post( $post );
		$issues          = $this->get_issues( $post, $analysis );

		if ( $this->has_blocking_issue( $issues ) ) {
			$outcome['status']  = 'error';
			$outcome['message'] = $this->issues_to_text( $issues, true );
			return $outcome;
		}

		$meta_key = self::META_PREFIX . $operation['id'];
		$existing = get_post_meta( $id, $meta_key, true );
		$created  = false;

		// Sauvegarde AVANT modification. Si une sauvegarde de cette opération existe déjà (lot repris
		// après une interruption), elle contient la version d'origine : on la garde.
		if ( ! is_array( $existing ) || ! isset( $existing['content'] ) ) {
			$backup = array(
				'content'      => $post->post_content,
				'modified'     => $post->post_modified,
				'modified_gmt' => $post->post_modified_gmt,
				'hash'         => md5( $analysis['content'] ),
				'saved_at'     => time(),
			);

			// wp_slash() : add_post_meta() retire un niveau d'antislash.
			$added = add_post_meta( $id, $meta_key, wp_slash( $backup ), true );
			$check = $added ? get_post_meta( $id, $meta_key, true ) : null;

			if ( ! is_array( $check ) || ! isset( $check['content'] ) || $check['content'] !== $post->post_content ) {
				if ( $added ) {
					delete_post_meta( $id, $meta_key );
				}
				$outcome['status']  = 'error';
				$outcome['message'] = __( 'La sauvegarde du contenu a échoué : contenu non modifié.', 'effi-bulk-block-remover' );
				return $outcome;
			}
			$created = true;
		}

		if ( $operation['preserve_date'] ) {
			$this->forced_dates[ $id ] = array( $post->post_modified, $post->post_modified_gmt );
		}

		// wp_slash() : wp_update_post() retire un niveau d'antislash (attributs JSON, <, chemins...).
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_content' => $analysis['content'],
				)
			),
			true
		);

		unset( $this->forced_dates[ $id ] );

		if ( is_wp_error( $result ) || ! $result ) {
			if ( $created ) {
				delete_post_meta( $id, $meta_key ); // Rien n'a changé : la sauvegarde est inutile.
			}
			$outcome['status']  = 'error';
			$outcome['message'] = is_wp_error( $result )
				? $result->get_error_message()
				: __( 'WordPress a refusé l’enregistrement du contenu.', 'effi-bulk-block-remover' );
			return $outcome;
		}

		// Empreinte du contenu réellement enregistré (une autre extension a pu le retoucher) :
		// la restauration vérifiera que le contenu n'a pas été modifié depuis.
		$saved_content = (string) get_post_field( 'post_content', $id, 'raw' );
		$backup        = get_post_meta( $id, $meta_key, true );
		if ( is_array( $backup ) ) {
			$backup['hash'] = md5( $saved_content );
			update_post_meta( $id, $meta_key, wp_slash( $backup ) );
		}

		$outcome['status']  = 'modified';
		$outcome['removed'] = $analysis['found'];
		return $outcome;
	}

	/**
	 * Démarre (ou reprend) la restauration de l'opération restaurable.
	 *
	 * @param string $operation_id Identifiant de l'opération.
	 * @return array|WP_Error
	 */
	private function begin_restore( $operation_id ) {
		$operations = $this->get_operations();

		if ( ! isset( $operations[ $operation_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Opération introuvable.', 'effi-bulk-block-remover' ) );
		}
		if ( $this->get_restorable_id( $operations ) !== $operation_id ) {
			return new WP_Error( 'not_last', __( 'Seule la dernière opération peut être restaurée.', 'effi-bulk-block-remover' ) );
		}

		$operation = $operations[ $operation_id ];
		if ( 'restoring' !== $operation['status'] ) {
			$operation['restored'] = 0;
		}
		$operation['status']         = 'restoring';
		$operation['restore_cursor'] = 0;
		$operation['skipped']        = 0;
		$operation['restore_errors'] = 0;
		$operation['updated']        = time();
		$this->save_operation( $operation );

		return array(
			'operation' => $this->summarize( $operation ),
			'state'     => $this->get_state(),
		);
	}

	/**
	 * Restaure un lot de contenus d'une opération.
	 *
	 * @param string $operation_id Identifiant de l'opération.
	 * @return array|WP_Error
	 */
	private function process_restore_batch( $operation_id ) {
		global $wpdb;

		$operation = $this->get_operation( $operation_id );
		if ( ! $operation ) {
			return new WP_Error( 'not_found', __( 'Opération introuvable.', 'effi-bulk-block-remover' ) );
		}
		if ( 'restoring' !== $operation['status'] ) {
			return new WP_Error( 'not_restoring', __( 'Aucune restauration n’est en cours pour cette opération.', 'effi-bulk-block-remover' ) );
		}

		$meta_key = self::META_PREFIX . $operation['id'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id ASC LIMIT %d",
				$meta_key,
				(int) $operation['restore_cursor'],
				self::BATCH_SIZE
			)
		);
		$ids = array_map( 'intval', (array) $ids );

		$report = array(
			'restored' => array(),
			'skipped'  => array(),
			'errors'   => array(),
		);

		$this->forced_dates = array();
		add_filter( 'wp_insert_post_data', array( $this, 'filter_forced_dates' ), 9999, 2 );

		try {
			foreach ( $ids as $id ) {
				$outcome = $this->restore_post( $id, $meta_key );

				$operation['restore_cursor'] = $id;
				if ( 'restored' === $outcome['status'] ) {
					++$operation['restored'];
					$report['restored'][] = $outcome['post'];
				} elseif ( 'skipped' === $outcome['status'] ) {
					++$operation['skipped'];
					$report['skipped'][] = $outcome['post'] + array( 'message' => $outcome['message'] );
				} else {
					++$operation['restore_errors'];
					$report['errors'][] = $outcome['post'] + array( 'message' => $outcome['message'] );
				}

				$operation['updated'] = time();
				$this->save_operation( $operation );
			}
		} finally {
			remove_filter( 'wp_insert_post_data', array( $this, 'filter_forced_dates' ), 9999 );
			$this->forced_dates = array();
		}

		$done = count( $ids ) < self::BATCH_SIZE;
		if ( $done ) {
			$operation['status'] = $this->count_backups( $operation['id'] ) > 0 ? 'partial' : 'restored';
		}
		$operation['updated'] = time();
		$this->save_operation( $operation );

		return array(
			'done'      => $done,
			'processed' => count( $ids ),
			'report'    => $report,
			'operation' => $this->summarize( $operation ),
			'state'     => $this->get_state(),
		);
	}

	/**
	 * Remet en place la version sauvegardée d'un contenu (contenu et dates de modification d'origine).
	 *
	 * @param int    $id       Identifiant du contenu.
	 * @param string $meta_key Clé de la sauvegarde.
	 * @return array Statut « restored », « skipped » ou « error ».
	 */
	private function restore_post( $id, $meta_key ) {
		$outcome = array(
			'status'  => 'error',
			'message' => '',
			'post'    => array(
				'id'        => $id,
				'title'     => '#' . $id,
				'status'    => '',
				'edit_link' => '',
			),
		);

		$post = get_post( $id );
		if ( ! $post instanceof WP_Post ) {
			$outcome['message'] = __( 'Contenu introuvable.', 'effi-bulk-block-remover' );
			return $outcome;
		}
		$outcome['post'] = $this->describe_post( $post );

		$backup = get_post_meta( $id, $meta_key, true );
		if ( ! is_array( $backup ) || ! isset( $backup['content'], $backup['hash'] ) || ! is_string( $backup['content'] ) ) {
			$outcome['message'] = __( 'Sauvegarde illisible : contenu non restauré.', 'effi-bulk-block-remover' );
			return $outcome;
		}

		if ( md5( $post->post_content ) !== $backup['hash'] ) {
			$outcome['status']  = 'skipped';
			$outcome['message'] = __( 'Contenu modifié depuis l’opération : non restauré pour ne pas écraser ces modifications (sauvegarde conservée).', 'effi-bulk-block-remover' );
			return $outcome;
		}

		$issues = $this->get_issues( $post, null );
		if ( $this->has_blocking_issue( $issues ) ) {
			$outcome['message'] = $this->issues_to_text( $issues, true );
			return $outcome;
		}

		if ( ! empty( $backup['modified'] ) && ! empty( $backup['modified_gmt'] ) ) {
			$this->forced_dates[ $id ] = array( (string) $backup['modified'], (string) $backup['modified_gmt'] );
		}

		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_content' => $backup['content'],
				)
			),
			true
		);

		unset( $this->forced_dates[ $id ] );

		if ( is_wp_error( $result ) || ! $result ) {
			$outcome['message'] = is_wp_error( $result )
				? $result->get_error_message()
				: __( 'WordPress a refusé l’enregistrement du contenu.', 'effi-bulk-block-remover' );
			return $outcome;
		}

		delete_post_meta( $id, $meta_key );

		$outcome['status'] = 'restored';
		return $outcome;
	}

	/**
	 * Supprime la sauvegarde de l'opération restaurable et la clôture.
	 *
	 * @param string $operation_id Identifiant de l'opération.
	 * @return array|WP_Error
	 */
	private function purge_operation( $operation_id ) {
		$operations = $this->get_operations();

		if ( ! isset( $operations[ $operation_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Opération introuvable.', 'effi-bulk-block-remover' ) );
		}
		if ( $this->get_restorable_id( $operations ) !== $operation_id ) {
			return new WP_Error( 'not_last', __( 'Seule la sauvegarde de la dernière opération peut être supprimée ici.', 'effi-bulk-block-remover' ) );
		}

		delete_post_meta_by_key( self::META_PREFIX . $operation_id );

		$operation            = $operations[ $operation_id ];
		$operation['status']  = 'purged';
		$operation['updated'] = time();
		$this->save_operation( $operation );

		return array(
			'operation' => $this->summarize( $operation ),
			'state'     => $this->get_state(),
		);
	}

	/**
	 * Impose les dates de modification prévues pour les contenus traités par le lot en cours.
	 *
	 * Filtre wp_insert_post_data ajouté au début de chaque lot et retiré à la fin ; il ne touche
	 * que les contenus de la liste (pas les révisions, ni les contenus modifiés par d'autres extensions).
	 *
	 * @param array $data    Données qui vont être enregistrées.
	 * @param array $postarr Données transmises à wp_insert_post().
	 * @return array
	 */
	public function filter_forced_dates( $data, $postarr ) {
		$id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( $id > 0 && isset( $this->forced_dates[ $id ] ) ) {
			$data['post_modified']     = $this->forced_dates[ $id ][0];
			$data['post_modified_gmt'] = $this->forced_dates[ $id ][1];
		}
		return $data;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Contrôles
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Problèmes connus pour un contenu (bloquants ou simples avertissements).
	 *
	 * @param WP_Post    $post     Contenu.
	 * @param array|null $analysis Résultat de Effi_BBR_Blocks::remove_from_content(), null pour une restauration.
	 * @return array Liste de problèmes (code, message, blocking).
	 */
	private function get_issues( WP_Post $post, $analysis ) {
		$issues = array();

		if ( is_array( $analysis ) ) {
			$messages = array(
				'invalid_attrs' => __( 'Un bloc conservé a des attributs illisibles (JSON invalide) : reconstruire le contenu les effacerait. Contenu à corriger à la main.', 'effi-bulk-block-remover' ),
				'unbalanced'    => __( 'Délimiteurs de blocs déséquilibrés (bloc non fermé ?) : contenu à corriger à la main.', 'effi-bulk-block-remover' ),
				'verification'  => __( 'La reconstruction du contenu ne correspond pas à l’analyse : contenu laissé intact par sécurité.', 'effi-bulk-block-remover' ),
			);
			if ( '' !== $analysis['error'] ) {
				$issues[] = array(
					'code'     => $analysis['error'],
					'message'  => isset( $messages[ $analysis['error'] ] ) ? $messages[ $analysis['error'] ] : $analysis['error'],
					'blocking' => true,
				);
			}
			if ( ! empty( $analysis['empty'] ) ) {
				$issues[] = array(
					'code'     => 'empty',
					'message'  => __( 'Le contenu sera vide après le retrait.', 'effi-bulk-block-remover' ),
					'blocking' => false,
				);
			}
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			$issues[] = array(
				'code'     => 'cannot_edit',
				'message'  => __( 'Vous n’avez pas le droit de modifier ce contenu.', 'effi-bulk-block-remover' ),
				'blocking' => true,
			);
		}

		// Un contenu « programmé » dont la date est passée serait publié par wp_update_post().
		if ( 'future' === $post->post_status && (int) get_post_time( 'U', true, $post ) - time() < MINUTE_IN_SECONDS ) {
			$issues[] = array(
				'code'     => 'future_past',
				'message'  => __( 'Programmé à une date déjà passée : l’enregistrer le publierait. Corrigez sa date ou publiez-le, puis relancez.', 'effi-bulk-block-remover' ),
				'blocking' => true,
			);
		}

		if ( function_exists( 'wp_check_post_lock' ) ) {
			$editor_id = wp_check_post_lock( $post->ID );
			if ( $editor_id ) {
				$editor   = get_userdata( $editor_id );
				$issues[] = array(
					'code'     => 'locked',
					'message'  => sprintf(
						/* translators: %s: nom de l'utilisateur qui modifie le contenu. */
						__( 'En cours de modification par %s : son enregistrement écraserait le changement. Réessayez quand l’éditeur sera fermé.', 'effi-bulk-block-remover' ),
						$editor ? $editor->display_name : '#' . $editor_id
					),
					'blocking' => true,
				);
			}
		}

		return $issues;
	}

	/**
	 * Indique si une liste de problèmes contient un problème bloquant.
	 *
	 * @param array $issues Problèmes.
	 * @return bool
	 */
	private function has_blocking_issue( array $issues ) {
		foreach ( $issues as $issue ) {
			if ( $issue['blocking'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Concatène les messages d'une liste de problèmes.
	 *
	 * @param array $issues        Problèmes.
	 * @param bool  $blocking_only Ne garder que les problèmes bloquants.
	 * @return string
	 */
	private function issues_to_text( array $issues, $blocking_only ) {
		$messages = array();
		foreach ( $issues as $issue ) {
			if ( ! $blocking_only || $issue['blocking'] ) {
				$messages[] = $issue['message'];
			}
		}
		return implode( ' ', $messages );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Requêtes et lecture des paramètres
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Vérifie le jeton de sécurité et les droits (toutes les actions AJAX).
	 *
	 * @return void
	 */
	private function verify_request() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Vous n’avez pas les droits nécessaires.', 'effi-bulk-block-remover' ) ), 403 );
		}
	}

	/**
	 * Refuse l'action si l'utilisateur n'a pas unfiltered_html (kses viderait iframes, scripts...).
	 *
	 * @return void
	 */
	private function require_unfiltered_html() {
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Opération refusée : votre compte n’a pas la capacité « unfiltered_html ». WordPress filtrerait les contenus enregistrés (iframes, scripts, formulaires supprimés). Utilisez un compte super-administrateur ou retirez DISALLOW_UNFILTERED_HTML.', 'effi-bulk-block-remover' ) ),
				403
			);
		}
	}

	/**
	 * Prend le verrou de traitement ou répond par une erreur.
	 *
	 * @return void
	 */
	private function lock_or_fail() {
		if ( ! $this->acquire_lock() ) {
			wp_send_json_error( array( 'message' => __( 'Un autre traitement est en cours (autre onglet ou autre administrateur). Réessayez dans quelques instants.', 'effi-bulk-block-remover' ) ) );
		}
	}

	/**
	 * Répond en JSON.
	 *
	 * @param array|WP_Error $result Résultat.
	 * @return void
	 */
	private function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Lit une chaîne dans $_POST (absente ou non textuelle : chaîne vide).
	 *
	 * @param string $key Clé.
	 * @return string
	 */
	private static function read_string( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Jeton vérifié dans verify_request(), valeur assainie ci-dessous.
		$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
		if ( ! is_string( $value ) ) {
			return '';
		}
		return trim( sanitize_text_field( $value ) );
	}

	/**
	 * Lit un entier positif dans $_POST (absent : 0).
	 *
	 * @param string $key Clé.
	 * @return int
	 */
	private static function read_int( $key ) {
		$value = self::read_string( $key );
		return ctype_digit( $value ) ? (int) $value : 0;
	}

	/**
	 * Lit et valide le type de contenu (liste blanche des types publics existants).
	 *
	 * @return string
	 */
	private function read_post_type() {
		$post_type  = self::read_string( 'post_type' );
		$post_types = $this->get_allowed_post_types();
		if ( '' === $post_type || ! isset( $post_types[ $post_type ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Type de contenu non autorisé.', 'effi-bulk-block-remover' ) ) );
		}
		return $post_type;
	}

	/**
	 * Lit et valide le nom du bloc.
	 *
	 * @param bool $required Le bloc est obligatoire.
	 * @return string
	 */
	private function read_block_name( $required ) {
		$block_name = self::read_string( 'block_name' );
		if ( '' === $block_name && ! $required ) {
			return '';
		}
		if ( ! Effi_BBR_Blocks::is_valid_block_name( $block_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Nom de bloc vide ou invalide (format attendu : extension/nom-du-bloc, en minuscules).', 'effi-bulk-block-remover' ) ) );
		}
		return $block_name;
	}

	/**
	 * Lit et valide l'identifiant d'opération.
	 *
	 * @return string
	 */
	private function read_operation_id() {
		$operation_id = self::read_string( 'operation' );
		if ( 1 !== preg_match( '/^[0-9]{8}_[0-9]{6}_[a-z0-9]{6}$/D', $operation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Identifiant d’opération invalide.', 'effi-bulk-block-remover' ) ) );
		}
		return $operation_id;
	}

	/**
	 * Types de contenu autorisés : types publics, hors fichiers médias.
	 *
	 * @return WP_Post_Type[] Indexés par nom.
	 */
	private function get_allowed_post_types() {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $post_types['attachment'] );
		return $post_types;
	}

	/**
	 * Identifiants du lot suivant (seuls les ID sont chargés), après le curseur.
	 *
	 * Requête directe plutôt que WP_Query : elle n'est pas filtrée par d'autres extensions
	 * (langue courante, restrictions d'accès...), ce qui garantit que tous les contenus sont parcourus.
	 *
	 * @param string $post_type Type de contenu.
	 * @param int    $cursor    Dernier ID traité.
	 * @return int[]
	 */
	private function get_batch_ids( $post_type, $cursor ) {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( self::STATUSES ), '%s' ) );
		$params       = array_merge( array( $post_type ), self::STATUSES, array( (int) $cursor, self::BATCH_SIZE ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ($placeholders) AND ID > %d ORDER BY ID ASC LIMIT %d", $params ) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Nombre de contenus traités pour un type.
	 *
	 * @param string $post_type Type de contenu.
	 * @return int
	 */
	private function count_posts( $post_type ) {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( self::STATUSES ), '%s' ) );
		$params       = array_merge( array( $post_type ), self::STATUSES );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ($placeholders)", $params ) );
	}

	/**
	 * Nombre de sauvegardes restantes pour une opération.
	 *
	 * @param string $operation_id Identifiant de l'opération.
	 * @return int
	 */
	private function count_backups( $operation_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_PREFIX . $operation_id ) );
	}

	/**
	 * Informations affichables d'un contenu (texte brut, échappé à l'affichage par le script).
	 *
	 * @param WP_Post $post Contenu.
	 * @return array
	 */
	private function describe_post( WP_Post $post ) {
		$title = trim( html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $title ) {
			$title = __( '(sans titre)', 'effi-bulk-block-remover' );
		}
		$status = get_post_status_object( $post->post_status );

		return array(
			'id'        => (int) $post->ID,
			'title'     => $title,
			'status'    => $status ? $status->label : $post->post_status,
			'edit_link' => (string) get_edit_post_link( $post->ID, 'raw' ),
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Registre des opérations et verrou
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Toutes les opérations, indexées par identifiant (de la plus ancienne à la plus récente).
	 *
	 * @return array
	 */
	private function get_operations() {
		$operations = get_option( self::OPTION_OPERATIONS, array() );
		return is_array( $operations ) ? $operations : array();
	}

	/**
	 * Une opération.
	 *
	 * @param string $operation_id Identifiant.
	 * @return array|null
	 */
	private function get_operation( $operation_id ) {
		$operations = $this->get_operations();
		return isset( $operations[ $operation_id ] ) ? $operations[ $operation_id ] : null;
	}

	/**
	 * Enregistre une opération.
	 *
	 * @param array $operation Opération.
	 * @return void
	 */
	private function save_operation( array $operation ) {
		$operations                     = $this->get_operations();
		$operations[ $operation['id'] ] = $operation;
		$this->save_operations( $operations );
	}

	/**
	 * Enregistre le registre (option non chargée automatiquement), en oubliant les plus anciennes
	 * opérations qui n'ont plus de sauvegarde.
	 *
	 * @param array $operations Opérations.
	 * @return void
	 */
	private function save_operations( array $operations ) {
		$excess = count( $operations ) - self::MAX_OPERATIONS;
		if ( $excess > 0 ) {
			foreach ( $operations as $id => $operation ) {
				if ( $excess <= 0 ) {
					break;
				}
				if ( in_array( $operation['status'], array( 'restored', 'purged' ), true )
					|| ( 'done' === $operation['status'] && 0 === $this->count_backups( $id ) ) ) {
					unset( $operations[ $id ] );
					--$excess;
				}
			}
		}
		update_option( self::OPTION_OPERATIONS, $operations, false );
	}

	/**
	 * Opération restaurable : la plus récente qui n'est ni restaurée ni clôturée et qui a encore
	 * des sauvegardes (ou un traitement en cours). Les restaurations se font donc dans l'ordre inverse.
	 *
	 * @param array $operations Opérations.
	 * @return string|null
	 */
	private function get_restorable_id( array $operations ) {
		foreach ( array_reverse( $operations, true ) as $id => $operation ) {
			if ( in_array( $operation['status'], array( 'running', 'restoring', 'partial' ), true ) ) {
				return (string) $id;
			}
			if ( 'done' === $operation['status'] && $this->count_backups( $id ) > 0 ) {
				return (string) $id;
			}
		}
		return null;
	}

	/**
	 * État affiché dans la section « Opérations et restauration ».
	 *
	 * @return array
	 */
	private function get_state() {
		$operations    = $this->get_operations();
		$restorable_id = $this->get_restorable_id( $operations );
		$history       = array();

		foreach ( array_slice( array_reverse( $operations, true ), 0, self::HISTORY_SIZE, true ) as $operation ) {
			$history[] = $this->summarize( $operation );
		}

		return array(
			'operations' => $history,
			'restorable' => null !== $restorable_id ? $this->summarize( $operations[ $restorable_id ] ) : null,
		);
	}

	/**
	 * Résumé affichable d'une opération.
	 *
	 * @param array $operation Opération.
	 * @return array
	 */
	private function summarize( array $operation ) {
		$labels      = array(
			'running'   => __( 'Suppression en cours ou interrompue', 'effi-bulk-block-remover' ),
			'done'      => __( 'Terminée', 'effi-bulk-block-remover' ),
			'restoring' => __( 'Restauration en cours ou interrompue', 'effi-bulk-block-remover' ),
			'partial'   => __( 'Restaurée en partie', 'effi-bulk-block-remover' ),
			'restored'  => __( 'Restaurée', 'effi-bulk-block-remover' ),
			'purged'    => __( 'Clôturée (sauvegarde supprimée)', 'effi-bulk-block-remover' ),
		);
		$user        = get_userdata( (int) $operation['user_id'] );
		$type_object = get_post_type_object( $operation['post_type'] );

		return array(
			'id'              => $operation['id'],
			'date'            => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $operation['created'] ),
			'updated_ago'     => human_time_diff( (int) $operation['updated'], time() ),
			'user'            => $user ? $user->display_name : '',
			'post_type'       => $operation['post_type'],
			'post_type_label' => $type_object ? $type_object->labels->name : $operation['post_type'],
			'block_name'      => $operation['block_name'],
			'preserve_date'   => (bool) $operation['preserve_date'],
			'status'          => $operation['status'],
			'status_label'    => isset( $labels[ $operation['status'] ] ) ? $labels[ $operation['status'] ] : $operation['status'],
			'total'           => (int) $operation['total'],
			'processed'       => (int) $operation['processed'],
			'modified'        => (int) $operation['modified'],
			'removed'         => (int) $operation['removed'],
			'errors'          => (int) $operation['errors'],
			'restored'        => (int) $operation['restored'],
			'skipped'         => (int) $operation['skipped'],
			'restore_errors'  => (int) $operation['restore_errors'],
			'backups'         => $this->count_backups( $operation['id'] ),
		);
	}

	/**
	 * Prend le verrou de traitement (une seule requête de suppression ou de restauration à la fois).
	 *
	 * INSERT IGNORE est atomique, contrairement à add_option() qui écrase une ligne existante
	 * (ON DUPLICATE KEY UPDATE). Un verrou abandonné (requête tuée) est repris au bout de LOCK_TIMEOUT secondes.
	 *
	 * @return bool
	 */
	private function acquire_lock() {
		global $wpdb;

		if ( $this->has_lock ) {
			return true;
		}

		$now = (string) time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::OPTION_LOCK, $now ) );

		if ( ! $inserted ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$locked_at = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_LOCK ) );
			if ( null === $locked_at || time() - (int) $locked_at < self::LOCK_TIMEOUT ) {
				return false;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $now, self::OPTION_LOCK, $locked_at ) );
			if ( 1 !== (int) $taken ) {
				return false;
			}
		}

		$this->has_lock = true;
		register_shutdown_function( array( $this, 'release_lock' ) );
		return true;
	}

	/**
	 * Libère le verrou (appelée aussi à l'arrêt du script, en cas d'erreur fatale).
	 *
	 * @return void
	 */
	public function release_lock() {
		global $wpdb;

		if ( ! $this->has_lock ) {
			return;
		}
		$this->has_lock = false;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_LOCK ) );
	}

	/**
	 * Chaînes du script, traduisibles.
	 *
	 * @return array
	 */
	private function get_js_strings() {
		return array(
			'chooseBlock'       => __( 'Choisissez un bloc dans la liste ou saisissez son nom.', 'effi-bulk-block-remover' ),
			'invalidBlock'      => __( 'Nom de bloc invalide : format attendu extension/nom-du-bloc (minuscules, chiffres et tirets).', 'effi-bulk-block-remover' ),
			'foundGroup'        => __( 'Blocs présents dans les contenus (inventaire)', 'effi-bulk-block-remover' ),
			/* translators: 1: nom du bloc, 2: nombre de contenus. */
			'foundOption'       => __( '%1$s — dans %2$s contenu(s)', 'effi-bulk-block-remover' ),
			'notRegistered'     => __( 'non enregistré', 'effi-bulk-block-remover' ),
			'unsupportedName'   => __( 'nom non pris en charge', 'effi-bulk-block-remover' ),
			'scanning'          => __( 'Analyse en cours…', 'effi-bulk-block-remover' ),
			'removing'          => __( 'Suppression en cours… Ne fermez pas cette page.', 'effi-bulk-block-remover' ),
			'restoring'         => __( 'Restauration en cours… Ne fermez pas cette page.', 'effi-bulk-block-remover' ),
			/* translators: 1: contenus traités, 2: total. */
			'progress'          => __( '%1$s / %2$s', 'effi-bulk-block-remover' ),
			'leaveWarning'      => __( 'Un traitement est en cours : quitter la page l’interrompra.', 'effi-bulk-block-remover' ),
			'unknownError'      => __( 'Réponse inattendue du serveur.', 'effi-bulk-block-remover' ),
			'nonceError'        => __( 'Jeton de sécurité expiré ou invalide : rechargez la page.', 'effi-bulk-block-remover' ),
			'networkError'      => __( 'Le serveur ne répond pas (connexion interrompue ?).', 'effi-bulk-block-remover' ),
			/* translators: %s: code HTTP. */
			'httpError'         => __( 'Erreur du serveur (HTTP %s).', 'effi-bulk-block-remover' ),
			/* translators: %s: message d'erreur. */
			'scanFailed'        => __( 'Analyse interrompue : %s', 'effi-bulk-block-remover' ),
			/* translators: 1: nombre de contenus analysés, 2: nombre de types de blocs. */
			'inventorySummary'  => __( 'Inventaire terminé : %1$s contenu(s) analysé(s), %2$s type(s) de blocs trouvé(s). Aucun contenu n’a été modifié.', 'effi-bulk-block-remover' ),
			'inventoryEmpty'    => __( 'Aucun bloc trouvé dans les contenus de ce type.', 'effi-bulk-block-remover' ),
			'colBlock'          => __( 'Bloc', 'effi-bulk-block-remover' ),
			'colRegistered'     => __( 'Enregistré', 'effi-bulk-block-remover' ),
			'colPosts'          => __( 'Contenus', 'effi-bulk-block-remover' ),
			'colOccurrences'    => __( 'Occurrences', 'effi-bulk-block-remover' ),
			'colContent'        => __( 'Contenu', 'effi-bulk-block-remover' ),
			'colStatus'         => __( 'Statut', 'effi-bulk-block-remover' ),
			'colFound'          => __( 'Blocs trouvés', 'effi-bulk-block-remover' ),
			'colInner'          => __( 'Blocs internes supprimés avec', 'effi-bulk-block-remover' ),
			'colRemarks'        => __( 'Remarques', 'effi-bulk-block-remover' ),
			'colRemoved'        => __( 'Blocs retirés', 'effi-bulk-block-remover' ),
			'colMessage'        => __( 'Message', 'effi-bulk-block-remover' ),
			'colDate'           => __( 'Date', 'effi-bulk-block-remover' ),
			'colUser'           => __( 'Par', 'effi-bulk-block-remover' ),
			'colType'           => __( 'Type', 'effi-bulk-block-remover' ),
			'colModified'       => __( 'Contenus modifiés', 'effi-bulk-block-remover' ),
			'colErrors'         => __( 'Erreurs', 'effi-bulk-block-remover' ),
			'colBackups'        => __( 'Sauvegardes', 'effi-bulk-block-remover' ),
			'yes'               => __( 'oui', 'effi-bulk-block-remover' ),
			'no'                => __( 'non', 'effi-bulk-block-remover' ),
			'select'            => __( 'Choisir', 'effi-bulk-block-remover' ),
			'willBeSkipped'     => __( 'Ne sera pas modifié :', 'effi-bulk-block-remover' ),
			/* translators: 1: nom du bloc, 2: contenus concernés, 3: contenus analysés, 4: occurrences. */
			'simulationSummary' => __( 'Simulation terminée : le bloc %1$s est présent dans %2$s contenu(s) sur %3$s analysé(s), %4$s occurrence(s) au total. Aucun contenu n’a été modifié.', 'effi-bulk-block-remover' ),
			/* translators: %s: nombre de blocs. */
			'simulationInner'   => __( '%s autre(s) bloc(s), placé(s) à l’intérieur des blocs retirés, seront supprimés avec eux.', 'effi-bulk-block-remover' ),
			/* translators: %s: nombre de contenus. */
			'simulationBlocked' => __( '%s contenu(s) ne seront pas modifiés (voir les remarques).', 'effi-bulk-block-remover' ),
			/* translators: %s: nom du bloc. */
			'simulationNone'    => __( 'Simulation terminée : aucun contenu de ce type ne contient le bloc %s.', 'effi-bulk-block-remover' ),
			/* translators: 1: nom du bloc, 2: nombre de contenus, 3: type de contenu. */
			'confirmRemoval'    => __( 'Retirer le bloc %1$s des contenus « %3$s » (%2$s contenu(s) concerné(s) lors de la simulation) ?', 'effi-bulk-block-remover' ),
			'confirmReminder'   => __( 'Chaque contenu modifié est sauvegardé avant modification, mais faites aussi une sauvegarde complète de la base de données.', 'effi-bulk-block-remover' ),
			/* translators: 1: contenus modifiés, 2: blocs retirés, 3: erreurs, 4: contenus analysés. */
			'removalSummary'    => __( 'Suppression terminée : %1$s contenu(s) modifié(s), %2$s bloc(s) retiré(s), %3$s erreur(s), sur %4$s contenu(s) analysé(s).', 'effi-bulk-block-remover' ),
			/* translators: %s: message d'erreur. */
			'removalStopped'    => __( 'Suppression interrompue : %s', 'effi-bulk-block-remover' ),
			/* translators: 1: contenus modifiés, 2: blocs retirés, 3: erreurs, 4: contenus analysés. */
			'removalPartial'    => __( 'Avant l’interruption : %1$s contenu(s) modifié(s), %2$s bloc(s) retiré(s), %3$s erreur(s), sur %4$s contenu(s) analysé(s).', 'effi-bulk-block-remover' ),
			'removalResume'     => __( 'Les contenus déjà modifiés sont sauvegardés. Vous pouvez reprendre la suppression ou la restaurer depuis la section « Opérations et restauration ».', 'effi-bulk-block-remover' ),
			'modifiedTitle'     => __( 'Contenus modifiés', 'effi-bulk-block-remover' ),
			'errorsTitle'       => __( 'Contenus non modifiés (erreurs)', 'effi-bulk-block-remover' ),
			'confirmRestore'    => __( 'Restaurer cette opération ? Chaque contenu retrouvera sa version et sa date de modification d’avant la suppression, sauf ceux modifiés depuis (ils seront signalés).', 'effi-bulk-block-remover' ),
			/* translators: 1: contenus restaurés, 2: contenus ignorés, 3: erreurs. */
			'restoreSummary'    => __( 'Restauration terminée : %1$s contenu(s) restauré(s), %2$s ignoré(s) car modifié(s) depuis, %3$s erreur(s).', 'effi-bulk-block-remover' ),
			/* translators: %s: message d'erreur. */
			'restoreStopped'    => __( 'Restauration interrompue : %s', 'effi-bulk-block-remover' ),
			'skippedTitle'      => __( 'Contenus non restaurés', 'effi-bulk-block-remover' ),
			'confirmPurge'      => __( 'Supprimer définitivement la sauvegarde de cette opération ? Elle ne pourra plus être restaurée.', 'effi-bulk-block-remover' ),
			'purgeDone'         => __( 'Sauvegarde supprimée : l’opération est clôturée.', 'effi-bulk-block-remover' ),
			'noOperation'       => __( 'Aucune opération pour le moment.', 'effi-bulk-block-remover' ),
			'noRestorable'      => __( 'Aucune opération à restaurer.', 'effi-bulk-block-remover' ),
			/* translators: 1: date, 2: nom du bloc, 3: type de contenu. */
			'restorableTitle'   => __( 'Dernière opération restaurable : %1$s — bloc %2$s, contenus « %3$s ».', 'effi-bulk-block-remover' ),
			/* translators: 1: statut, 2: sauvegardes restantes, 3: durée depuis la dernière activité (ex. « 5 minutes »). */
			'restorableDetails' => __( 'Statut : %1$s. Sauvegardes disponibles : %2$s. Dernière activité il y a %3$s.', 'effi-bulk-block-remover' ),
			'resumeRemoval'     => __( 'Reprendre la suppression', 'effi-bulk-block-remover' ),
			'restoreLast'       => __( 'Restaurer la dernière opération', 'effi-bulk-block-remover' ),
			'resumeRestore'     => __( 'Reprendre la restauration', 'effi-bulk-block-remover' ),
			'purge'             => __( 'Supprimer la sauvegarde', 'effi-bulk-block-remover' ),
			'needsUnfiltered'   => __( 'Reprise et restauration désactivées : votre compte n’a pas la capacité « unfiltered_html ».', 'effi-bulk-block-remover' ),
			'historyTitle'      => __( 'Historique (10 dernières opérations)', 'effi-bulk-block-remover' ),
		);
	}
}

if ( is_admin() ) {
	Effi_BBR_Plugin::instance();
}
