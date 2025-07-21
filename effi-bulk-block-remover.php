<?php
/**
 * Plugin Name: Effi Bulk Block Remover
 * Description: Supprime en masse un type de bloc Gutenberg spécifique dans un type de contenu donné.
 * Version: 1.0.1
 * Author: Cédric Girard
 * Author URI: https://www.effi10.com
 */

if (!defined('ABSPATH')) exit;

class Effi_Bulk_Block_Remover {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_effi_analyze_blocks', [$this, 'analyze_blocks']);
        add_action('wp_ajax_effi_delete_blocks', [$this, 'delete_blocks']);
    }

    public function add_admin_menu() {
        add_submenu_page(
            'tools.php',
            'Suppression de blocs en masse',
            'Suppression de blocs en masse',
            'manage_options',
            'effi-bulk-block-remover',
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'tools_page_effi-bulk-block-remover') return;
        wp_enqueue_script('effi-block-remover', plugin_dir_url(__FILE__) . 'script.js', ['jquery'], null, true);
        wp_localize_script('effi-block-remover', 'effiAjax', ['ajax_url' => admin_url('admin-ajax.php')]);
    }

    public function render_admin_page() {
        $post_types = get_post_types(['public' => true], 'objects');
        $block_types = WP_Block_Type_Registry::get_instance()->get_all_registered();
        
        // Trier les blocs par nom
        uasort($block_types, function($a, $b) {
            return strcmp($a->name, $b->name);
        });
        ?>
        <div class="wrap">
            <h1>Suppression de blocs en masse</h1>
            <table class="form-table">
                <tr>
                    <th scope="row">Type de publication</th>
                    <td>
                        <select id="effi-post-type">
                            <?php foreach ($post_types as $pt): ?>
                                <option value="<?php echo esc_attr($pt->name); ?>"><?php echo esc_html($pt->label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Nom du bloc Gutenberg</th>
                    <td>
                        <select id="effi-block-name">
                            <option value="">Sélectionnez un bloc</option>
                            <?php foreach ($block_types as $block_type): ?>
                                <option value="<?php echo esc_attr($block_type->name); ?>"><?php echo esc_html($block_type->name); ?> (<?php echo esc_html($block_type->title); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <p>
                <button class="button button-primary" id="effi-analyze">Analyser</button>
                <button class="button button-secondary" id="effi-delete">Supprimer ces blocs</button> <span style="font-style:italic;font-size:12px;">(Attention, cette action est irréversible !!!)</span>
            </p>
            <div id="effi-results" style="margin-top:20px;"></div>
        </div>
        <?php
    }

    public function analyze_blocks() {
        if (!current_user_can('manage_options')) wp_send_json_error('Non autorisé');
        $post_type = sanitize_text_field($_POST['post_type']);
        $block_name = sanitize_text_field($_POST['block_name']);

        $posts = get_posts([
            'post_type' => $post_type,
            'posts_per_page' => -1,
            'post_status' => ['publish', 'draft', 'private'],
        ]);

        $count = 0;
        foreach ($posts as $post) {
            $blocks = parse_blocks($post->post_content);
            foreach ($blocks as $block) {
                if ($block['blockName'] === $block_name) {
                    $count++;
                    break;
                }
            }
        }

        wp_send_json_success([
            'found' => $count,
            'total' => count($posts),
        ]);
    }

    public function delete_blocks() {
        if (!current_user_can('manage_options')) wp_send_json_error('Non autorisé');
        $post_type = sanitize_text_field($_POST['post_type']);
        $block_name = sanitize_text_field($_POST['block_name']);

        $posts = get_posts([
            'post_type' => $post_type,
            'posts_per_page' => -1,
            'post_status' => ['publish', 'draft', 'private'],
        ]);

        foreach ($posts as $post) {
            $blocks = parse_blocks($post->post_content);
            $filtered_blocks = array_filter($blocks, function($block) use ($block_name) {
                return $block['blockName'] !== $block_name;
            });

            $new_content = '';
            foreach ($filtered_blocks as $block) {
                $new_content .= render_block($block);
            }

            wp_update_post([
                'ID' => $post->ID,
                'post_content' => $new_content,
            ]);
        }

        wp_send_json_success('Blocs supprimés.');
    }
}

new Effi_Bulk_Block_Remover();
