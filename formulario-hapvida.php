<?php
/*
Plugin Name: Formulário Hapvida
Description: Plugin para manipulação de formulário Hapvida com redirecionamento alternado, webhook e lista de cidades própria.
Version: 2.0 - Com redirecionamento para página de obrigado
Author: P3 Consultoria Digital
*/

// Impede acesso direto ao arquivo
if (!defined('ABSPATH')) {
    exit;
}

// *** CORREÇÃO: Evita carregamento múltiplo do arquivo ***
if (defined('FORMULARIO_HAPVIDA_LOADED')) {
    return;
}
define('FORMULARIO_HAPVIDA_LOADED', true);

// *** Carrega integração com Google Sheets (antes do admin-page que usa a classe) ***
if (!class_exists('Formulario_Hapvida_Google_Sheets')) {
    require_once plugin_dir_path(__FILE__) . 'google-sheets.php';
}

// Carrega a página de administração
require_once plugin_dir_path(__FILE__) . 'admin-page.php';

// Carrega a página de relatórios (shortcode [hapvida_reports])
require_once plugin_dir_path(__FILE__) . 'reports-page.php';

// Sistema de rastreamento de entregas via Evolution API
if (!class_exists('Hapvida_Delivery_Tracking')) {
    require_once plugin_dir_path(__FILE__) . 'delivery-tracking.php';
}

// *** NOVO: Carrega o sistema de integração com API LeadP3 ***
if (!class_exists('Formulario_Hapvida_LeadP3_Integration')) {
    require_once plugin_dir_path(__FILE__) . 'leadp3-integration-FINAL.php';
}

// *** Carrega os traits modulares ***
require_once plugin_dir_path(__FILE__) . 'includes/trait-utilities.php';
require_once plugin_dir_path(__FILE__) . 'includes/trait-vendor-router.php';
require_once plugin_dir_path(__FILE__) . 'includes/trait-rest-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/trait-ajax.php';
require_once plugin_dir_path(__FILE__) . 'includes/trait-webhook.php';
require_once plugin_dir_path(__FILE__) . 'includes/trait-form-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/trait-shortcode-form.php';
require_once plugin_dir_path(__FILE__) . 'includes/trait-auto-activate.php';

class Formulario_Hapvida
{
    use UtilitiesTrait;
    use VendorRouterTrait;
    use RestApiTrait;
    use AjaxHandlersTrait;
    use WebhookTrait;
    use FormHandlerTrait;
    use ShortcodeFormTrait;
    use AutoActivateTrait;

    private $max_webhook_attempts = 3;

    // *** ALTERADO: Removido 'email' dos campos obrigatórios ***
    private $required_fields = ['name', 'telefone'];

    private $default_timeout_minutes = 10;
    private $business_hours_timeout = 10;
    private $after_hours_timeout = 30;

    // Opções e nomes usados no banco
    private $ultimo_vendedor_option_name = 'formulario_hapvida_ultimo_vendedor_info';
    private $settings_option_name = 'formulario_hapvida_settings';
    private $log_file;
    private $processed_forms = 'formulario_hapvida_processed_forms';
    private $vendedores_option = 'formulario_hapvida_vendedores';
    private $daily_submissions_option = 'formulario_hapvida_daily_submissions';
    private $monthly_submissions_option = 'formulario_hapvida_monthly_submissions';
    private $city_vendors_option = 'formulario_hapvida_city_vendors'; // *** ADICIONADO: Vendedores por cidade ***
    private $url_consultores_option = 'formulario_hapvida_url_consultores'; // *** ADICIONADO: URLs de consultores ***

    // *** NOVO: Opção para armazenar webhooks com falha ***
    private $failed_webhooks_option = 'formulario_hapvida_failed_webhooks';


    public function __construct()
    {
        // Caminho do arquivo de log
        $this->log_file = WP_CONTENT_DIR . '/formulario_hapvida.log';

        // *** NOVO: Carrega configurações de timeout ***
        $this->load_timeout_settings();

        // Corrige (se necessário) a opção de último vendedor
        $this->fix_ultimo_vendedor_option();

        // Enfileira scripts e registra REST
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('rest_api_init', array($this, 'register_rest_route'));


        // Shortcode para exibir o formulário
        add_shortcode('formulario_hapvida', array($this, 'shortcode'));
        add_shortcode('formulario_hapvida_sem_titulo', array($this, 'shortcode_sem_titulo'));




        $this->ensure_timezone_configured();

        // *** CORREÇÃO CRÍTICA: Adiciona actions AJAX para frontend ***
        add_action('wp_ajax_adjust_submission_count', array($this, 'ajax_adjust_submission_count'));
        add_action('wp_ajax_nopriv_adjust_submission_count', array($this, 'ajax_adjust_submission_count'));

        add_action('wp_ajax_get_pending_webhooks', array($this, 'ajax_get_pending_webhooks'));
        add_action('wp_ajax_nopriv_get_pending_webhooks', array($this, 'ajax_get_pending_webhooks'));

        add_action('admin_init', array($this, 'handle_admin_debug_actions'));

        add_shortcode('hapvida_dashboard', array($this, 'render_dashboard_shortcode'));
        add_shortcode('contagem_hapvida', array($this, 'render_dashboard_shortcode')); // Alias para compatibilidade

        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // *** AUTO-ATIVAÇÃO Seu Souza: Cron hook ***
        add_action('hapvida_auto_activate_seu_souza', array($this, 'auto_activate_seu_souza'));
        add_action('hapvida_auto_deactivate_seu_souza', array($this, 'auto_deactivate_seu_souza'));
        add_filter('cron_schedules', array($this, 'add_auto_activate_cron_interval'));

        // AJAX para toggle da auto-ativação
        add_action('wp_ajax_hapvida_toggle_auto_activate_seu_souza', array($this, 'ajax_toggle_auto_activate_seu_souza'));

        // Agenda os crons se a funcionalidade estiver ativa
        $this->schedule_auto_activate_seu_souza();

        // Log de inicialização
        $this->log("Plugin Formulário Hapvida inicializado com sistema de IDs únicos e webhook de confirmação");
    }

}

// Singleton para o formulário principal
function get_formulario_hapvida_instance()
{
    static $instance = null;
    static $initialized = false;

    if ($initialized) {
        return $instance;
    }

    if ($instance === null && !isset($GLOBALS['formulario_hapvida'])) {
        $instance = new Formulario_Hapvida();
        $GLOBALS['formulario_hapvida'] = $instance;
        $initialized = true;
        // Instância criada com sucesso
    }

    return $instance;
}

// Inicializa a instância principal do formulário
if (!isset($GLOBALS['formulario_hapvida'])) {
    get_formulario_hapvida_instance();
}

// Inclui o sistema de limpeza automática apenas uma vez
$cleanup_file = plugin_dir_path(__FILE__) . 'webhook-cleanup.php';
if (file_exists($cleanup_file) && !class_exists('Formulario_Hapvida_Webhook_Cleanup')) {
    require_once $cleanup_file;
}
