<?php
// Verifique se este arquivo está sendo acessado diretamente
if (!defined('ABSPATH')) {
    exit;
}

// *** Carrega os traits administrativos ***
require_once plugin_dir_path(__FILE__) . 'includes/admin/trait-admin-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/trait-admin-utilities.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/trait-admin-vendors.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/trait-admin-ajax.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/trait-admin-scripts.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/trait-admin-shortcode.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/trait-admin-delivery.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/trait-admin-page-renderer.php';

class Formulario_Hapvida_Admin
{
    use AdminSettingsTrait;
    use AdminUtilitiesTrait;
    use AdminVendorsTrait;
    use AdminAjaxTrait;
    use AdminScriptsTrait;
    use AdminShortcodeTrait;
    use AdminDeliveryTrait;
    use AdminPageRendererTrait;

    private $option_name = 'formulario_hapvida_settings';
    private $vendedores_option = 'formulario_hapvida_vendedores';
    private $daily_submissions_option = 'formulario_hapvida_daily_submissions';
    private $monthly_submissions_option = 'formulario_hapvida_monthly_submissions';

    // *** NOVO: Opção para webhooks com falha ***
    private $failed_webhooks_option = 'formulario_hapvida_failed_webhooks';


    public function __construct()
    {
        // *** CORREÇÃO: Garante que option_name seja sempre definida ***
        $this->option_name = 'formulario_hapvida_settings';
        $this->vendedores_option = 'formulario_hapvida_vendedores';
        $this->daily_submissions_option = 'formulario_hapvida_daily_submissions';
        $this->monthly_submissions_option = 'formulario_hapvida_monthly_submissions';
        $this->failed_webhooks_option = 'formulario_hapvida_failed_webhooks';

        // Hooks administrativos
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_save_vendedores', array($this, 'handle_save_vendedores'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // AJAX para backend (admin) - apenas para usuários logados
        add_action('wp_ajax_add_vendedor', array($this, 'ajax_add_vendedor'));
        add_action('wp_ajax_toggle_vendedor_status', array($this, 'ajax_toggle_vendedor_status'));
        add_action('wp_ajax_clear_submission_stats', array($this, 'ajax_clear_submission_stats'));
        add_action('wp_ajax_adjust_daily_count', array($this, 'ajax_adjust_daily_count'));
        add_action('wp_ajax_clear_vendor_stats', array($this, 'ajax_clear_vendor_stats'));

        // *** NOVO: AJAX para rotas de consultores ***
        add_action('wp_ajax_adicionar_rota_consultor', array($this, 'ajax_adicionar_rota_consultor'));
        add_action('wp_ajax_remover_rota_consultor', array($this, 'ajax_remover_rota_consultor'));

        // *** CRÍTICO: AJAX para frontend (shortcode público) ***
        // Actions que precisam funcionar COM e SEM login (nopriv é ESSENCIAL!)

        // Contagens
        add_action('wp_ajax_get_counts', array($this, 'ajax_get_counts'));
        add_action('wp_ajax_nopriv_get_counts', array($this, 'ajax_get_counts'));

        // Contagens em tempo real
        add_action('wp_ajax_get_live_counts', array($this, 'ajax_get_live_counts'));
        add_action('wp_ajax_nopriv_get_live_counts', array($this, 'ajax_get_live_counts'));

        // *** NOVO: BUSCAR ÚLTIMOS LEADS (ESSENCIAL PARA FUNCIONAR SEM LOGIN) ***
        add_action('wp_ajax_get_recent_leads', array($this, 'ajax_get_recent_leads'));
        add_action('wp_ajax_nopriv_get_recent_leads', array($this, 'ajax_get_recent_leads'));

        // Exportar todos os leads
        add_action('wp_ajax_get_all_leads_for_export', array($this, 'ajax_get_all_leads_for_export'));
        add_action('wp_ajax_nopriv_get_all_leads_for_export', array($this, 'ajax_get_all_leads_for_export'));

        // Ajustar contagem de submissões
        add_action('wp_ajax_adjust_submission_count', array($this, 'ajax_adjust_submission_count'));
        add_action('wp_ajax_nopriv_adjust_submission_count', array($this, 'ajax_adjust_submission_count'));

        // Webhooks pendentes frontend
        add_action('wp_ajax_get_pending_webhooks_frontend', array($this, 'ajax_get_pending_webhooks_frontend'));
        add_action('wp_ajax_nopriv_get_pending_webhooks_frontend', array($this, 'ajax_get_pending_webhooks_frontend'));


        // Detalhes do webhook/lead

        add_action('wp_ajax_get_webhook_lead_details_public', array($this, 'ajax_get_webhook_lead_details_public'));
        add_action('wp_ajax_nopriv_get_webhook_lead_details_public', array($this, 'ajax_get_webhook_lead_details_public'));


        add_action('wp_ajax_toggle_vendor_status_frontend', array($this, 'ajax_toggle_vendor_status_frontend'));
        add_action('wp_ajax_nopriv_toggle_vendor_status_frontend', array($this, 'ajax_toggle_vendor_status_frontend'));

        add_action('wp_ajax_get_vendors_list_frontend', array($this, 'ajax_get_vendors_list_frontend'));
        add_action('wp_ajax_nopriv_get_vendors_list_frontend', array($this, 'ajax_get_vendors_list_frontend'));

        add_action('wp_ajax_get_delivery_stats', array($this, 'ajax_get_delivery_stats'));
        add_action('wp_ajax_nopriv_get_delivery_stats', array($this, 'ajax_get_delivery_stats'));

        add_action('wp_ajax_toggle_auto_deactivation', array($this, 'ajax_toggle_auto_deactivation'));
        add_action('wp_ajax_nopriv_toggle_auto_deactivation', array($this, 'ajax_toggle_auto_deactivation'));

        add_action('wp_ajax_clear_delivery_records', array($this, 'ajax_clear_delivery_records'));
        add_action('wp_ajax_nopriv_clear_delivery_records', array($this, 'ajax_clear_delivery_records'));

        add_action('wp_ajax_confirm_delivery', array($this, 'ajax_confirm_delivery'));
        add_action('wp_ajax_nopriv_confirm_delivery', array($this, 'ajax_confirm_delivery'));

    }

}



$formulario_hapvida_admin = new Formulario_Hapvida_Admin();
