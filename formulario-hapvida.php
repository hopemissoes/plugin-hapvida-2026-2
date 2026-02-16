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

// *** NOVO: Carrega o sistema de tracking de leads COM PROTEÇÃO ***
if (!class_exists('Formulario_Hapvida_Lead_Tracking')) {
    require_once plugin_dir_path(__FILE__) . 'lead-tracking.php';
}

// *** NOVO: Carrega o sistema de integração com API LeadP3 ***
if (!class_exists('Formulario_Hapvida_LeadP3_Integration')) {
    require_once plugin_dir_path(__FILE__) . 'leadp3-integration-FINAL.php';
}

class Formulario_Hapvida
{

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


        // *** NOVO: Carrega o sistema de tracking de leads ***
        add_action('init', array($this, 'init_lead_tracking'));


        $this->ensure_timezone_configured();

        // *** CORREÇÃO CRÃTICA: Adiciona actions AJAX para frontend ***
        add_action('wp_ajax_adjust_submission_count', array($this, 'ajax_adjust_submission_count'));
        add_action('wp_ajax_nopriv_adjust_submission_count', array($this, 'ajax_adjust_submission_count'));

        add_action('wp_ajax_get_pending_webhooks', array($this, 'ajax_get_pending_webhooks'));
        add_action('wp_ajax_nopriv_get_pending_webhooks', array($this, 'ajax_get_pending_webhooks'));

        add_action('wp_ajax_retry_webhook_frontend', array($this, 'ajax_retry_webhook_frontend'));
        add_action('wp_ajax_nopriv_retry_webhook_frontend', array($this, 'ajax_retry_webhook_frontend'));

        add_action('admin_init', array($this, 'handle_admin_debug_actions'));

        add_action('wp_ajax_delete_expired_leads', array($this, 'ajax_delete_expired_leads'));

        add_action('hapvida_send_webhook_background', array($this, 'process_webhook_background'), 10, 2);

        add_shortcode('hapvida_dashboard', array($this, 'render_dashboard_shortcode'));
        add_shortcode('contagem_hapvida', array($this, 'render_dashboard_shortcode')); // Alias para compatibilidade

        add_action('rest_api_init', array($this, 'register_rest_routes'));


        add_action('hapvida_process_webhook_queue', array($this, 'process_webhook_queue_async'));
        add_action('wp_ajax_hapvida_process_webhook_queue_async', array($this, 'process_webhook_queue_async'));
        add_action('wp_ajax_nopriv_hapvida_process_webhook_queue_async', array($this, 'process_webhook_queue_async'));

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


    public function register_rest_routes()
    {
        register_rest_route('hapvida/v1', '/recent-leads', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_recent_leads'),
            'permission_callback' => '__return_true', // Permite acesso público
        ));

        register_rest_route('hapvida/v1', '/live-counts', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_live_counts'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('hapvida/v1', '/lead-details/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_lead_details'),
            'permission_callback' => '__return_true', // Permite acesso público
            'args' => array(
                'id' => array(
                    'validate_callback' => function ($param, $request, $key) {
                        return !empty($param);
                    }
                ),
            ),
        ));
    }

    public function rest_get_lead_details($request)
    {
        $lead_id = $request->get_param('id');

        error_log("ðŸ” [REST API] Buscando detalhes do lead: " . $lead_id);

        $all_webhooks = get_option($this->failed_webhooks_option, array());

        // Busca o lead específico
        $lead_found = null;
        foreach ($all_webhooks as $webhook) {
            if (
                (isset($webhook['id']) && $webhook['id'] == $lead_id) ||
                (isset($webhook['webhook_id']) && $webhook['webhook_id'] == $lead_id)
            ) {
                $lead_found = $webhook;
                break;
            }
        }

        if (!$lead_found) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Lead não encontrado'
            ), 404);
        }

        // Formata os dados do lead
        $data = isset($lead_found['data']) ? $lead_found['data'] : array();

        $formatted_lead = array(
            'lead_id' => $lead_id,
            'nome' => isset($data['nome']) ? $data['nome'] :
                (isset($data['name']) ? $data['name'] : 'N/A'),
            'telefone' => isset($data['telefone']) ? $data['telefone'] : 'N/A',
            'cidade' => isset($data['cidade']) ? $data['cidade'] : 'N/A',
            'plano' => isset($data['tipo_de_plano']) ? $data['tipo_de_plano'] :
                (isset($data['qual_plano']) ? $data['qual_plano'] : 'N/A'),
            'qtd_pessoas' => isset($data['quantidade_de_pessoas']) ? $data['quantidade_de_pessoas'] :
                (isset($data['qtd_pessoas']) ? $data['qtd_pessoas'] : '1'),
            'idades' => isset($data['idades']) ? $data['idades'] :
                (isset($data['ages']) && is_array($data['ages']) ? implode(', ', $data['ages']) : 'N/A'),
            'vendedor' => isset($data['vendedor']) ? $data['vendedor'] :
                (isset($data['atendente']) ? $data['atendente'] :
                    (isset($data['vendedor_nome']) ? $data['vendedor_nome'] : 'N/A')),
            'vendedor_telefone' => isset($data['vendedor_telefone']) ? $data['vendedor_telefone'] :
                (isset($data['telefone_vendedor']) ? $data['telefone_vendedor'] : 'N/A'),
            'grupo' => isset($data['grupo']) ? strtoupper($data['grupo']) : 'N/A',
            'created_at' => isset($lead_found['created_at']) ? $lead_found['created_at'] : 'N/A',
            'status' => isset($lead_found['status']) ? $lead_found['status'] : 'pending',
            'attempts' => isset($lead_found['attempts']) ? $lead_found['attempts'] : 0,
            'last_error' => isset($lead_found['error']) ? $lead_found['error'] : '',
            'pagina_origem' => isset($data['pagina_origem']) ? $data['pagina_origem'] : 'N/A',
            'ip' => isset($data['ip']) ? $data['ip'] : 'N/A',
            'observacoes' => isset($data['observacoes']) ? $data['observacoes'] : ''
        );

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $formatted_lead
        ), 200);
    }

    // FUNÇÃO REST API PARA BUSCAR LEADS
    public function rest_get_recent_leads()
    {
        error_log("🔍 [REST API] Buscando leads recentes");

        $all_webhooks = get_option($this->failed_webhooks_option, array());

        // Ordena por data
        usort($all_webhooks, function ($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        // Pega os 10 últimos
        $recent_leads = array_slice($all_webhooks, 0, 10);

        // Formata os dados
        $formatted_leads = array();
        foreach ($recent_leads as $webhook) {
            $data = isset($webhook['data']) ? $webhook['data'] : array();
            $formatted_leads[] = array(
                'id' => $webhook['id'] ?? uniqid(),
                'created_at' => date('d/m/Y H:i', strtotime($webhook['created_at'])),
                'client_name' => $data['nome'] ?? 'N/A',
                'grupo' => strtoupper($data['grupo'] ?? 'N/A'),
                'status' => $webhook['status'] ?? 'pending',
                'phone' => $data['telefone'] ?? 'N/A',
                'city' => $data['cidade'] ?? 'N/A',
                'vendor' => $data['vendedor'] ?? $data['atendente'] ?? 'N/A'
            );
        }

        // Calcula estatísticas
        $stats = array(
            'total' => count($all_webhooks),
            'completed' => 0,
            'pending' => 0,
            'failed' => 0
        );

        foreach ($all_webhooks as $w) {
            $status = $w['status'] ?? 'pending';
            if ($status === 'success' || $status === 'completed') {
                $stats['completed']++;
            } elseif ($status === 'pending') {
                $stats['pending']++;
            } elseif ($status === 'failed') {
                $stats['failed']++;
            }
        }

        return new WP_REST_Response(array(
            'success' => true,
            'leads' => $formatted_leads,
            'stats' => $stats,
            'timestamp' => current_time('mysql')
        ), 200);
    }

    // FUNÇÃO REST API PARA CONTAGENS
    public function rest_get_live_counts()
    {
        $today = current_time('Y-m-d');
        $current_month = current_time('Y-m');

        $daily_submissions = get_option($this->daily_submissions_option, array());
        $monthly_submissions = get_option($this->monthly_submissions_option, array());

        return new WP_REST_Response(array(
            'success' => true,
            'daily_count' => $daily_submissions[$today] ?? 0,
            'monthly_count' => $monthly_submissions[$current_month] ?? 0
        ), 200);
    }

    // VERSÃO SIMPLIFICADA
    private function send_to_webhook_with_tracking($form_data, $vendedor)
    {
        try {
            // Prepara dados do webhook
            $webhook_data = $this->prepare_webhook_data($form_data, $vendedor);

            // Salva lead no tracking
            global $formulario_hapvida_lead_tracking;
            if ($formulario_hapvida_lead_tracking) {
                $lead_id = $formulario_hapvida_lead_tracking->create_lead(
                    $form_data,
                    $vendedor,
                    $this->get_dynamic_timeout()
                );

                if ($lead_id) {
                    $this->log("✅ Lead salvo no tracking: {$lead_id}");
                    // Adiciona informações de tracking aos dados
                    $webhook_data['lead_tracking'] = array(
                        'lead_id' => $lead_id,
                        'tracking_enabled' => true
                    );
                }
            }

            // Em vez de enviar diretamente, enfileira
            $this->save_webhook_entry($webhook_data, 'pending', 'Aguardando processamento');
            return true; // Sempre retorna true pois foi enfileirado

        } catch (Exception $e) {
            $this->log("âš ï¸ Erro no tracking: " . $e->getMessage());
            return false;
        }
    }

    public function render_dashboard_shortcode($atts)
    {
        // Instancia a classe admin se não existir
        global $formulario_hapvida_admin;
        if (!$formulario_hapvida_admin) {
            $formulario_hapvida_admin = new Formulario_Hapvida_Admin();
        }

        // Chama a função existente render_contagem_shortcode() da classe admin
        return $formulario_hapvida_admin->render_contagem_shortcode();
    }

    /**
     * Atualiza contagens diária e mensal usando timezone correto
     */
    public function update_daily_submissions_count()
    {
        // *** CORREÇÃO: USA current_time() DO WORDPRESS ***
        $today = current_time('Y-m-d');
        $month = current_time('Y-m');

        // Log de debug
        $this->log("ðŸ“Š Atualizando contagens - Data: {$today}, Mês: {$month}");

        // Diária
        $daily_submissions = get_option($this->daily_submissions_option, array());
        if (!isset($daily_submissions[$today])) {
            $daily_submissions[$today] = 1;
        } else {
            $daily_submissions[$today]++;
        }
        update_option($this->daily_submissions_option, $daily_submissions);

        $this->log("ðŸ“Š Contagem diária atualizada: {$daily_submissions[$today]} submissões em {$today}");

        // Mensal
        $monthly_submissions = get_option($this->monthly_submissions_option, array());
        if (!isset($monthly_submissions[$month])) {
            $monthly_submissions[$month] = 1;
        } else {
            $monthly_submissions[$month]++;
        }
        update_option($this->monthly_submissions_option, $monthly_submissions);

        $this->log("ðŸ“Š Contagem mensal atualizada: {$monthly_submissions[$month]} submissões em {$month}");
    }

    private function ensure_timezone_configured()
    {
        // Obtém o timezone configurado no WordPress
        $timezone_string = get_option('timezone_string');

        // Se não houver timezone configurado, usa São Paulo como padrão
        if (empty($timezone_string)) {
            // Tenta usar o offset GMT
            $gmt_offset = get_option('gmt_offset');

            // GMT-3 = São Paulo
            if ($gmt_offset == -3) {
                update_option('timezone_string', 'America/Sao_Paulo');
                $timezone_string = 'America/Sao_Paulo';
            } else {
                // Define São Paulo como padrão
                update_option('timezone_string', 'America/Sao_Paulo');
                update_option('gmt_offset', -3);
                $timezone_string = 'America/Sao_Paulo';
            }

            $this->log("âš™ï¸ Timezone configurado automaticamente para: America/Sao_Paulo");
        }

        // Define o timezone padrão do PHP para corresponder ao WordPress
        if (!empty($timezone_string)) {
            date_default_timezone_set($timezone_string);
            $this->log("ðŸ• Timezone configurado: " . $timezone_string);
        }

        // Log de debug
        $this->log("ðŸ• Data/Hora atual (WordPress): " . current_time('d/m/Y H:i:s'));
        $this->log("ðŸ• Data/Hora atual (PHP): " . date('d/m/Y H:i:s'));
    }

    public function ajax_delete_expired_leads()
    {
        // Redireciona para a função da classe admin
        global $formulario_hapvida_admin;
        if ($formulario_hapvida_admin && method_exists($formulario_hapvida_admin, 'ajax_delete_expired_leads')) {
            $formulario_hapvida_admin->ajax_delete_expired_leads();
        } else {
            wp_send_json_error('Função de administração não disponível');
        }
    }

    public function ajax_force_redistribute_with_debug()
    {
        if (!wp_verify_nonce($_POST['security'], 'force_redistribute_debug_nonce')) {
            wp_die('Nonce verification failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $lead_id = sanitize_text_field($_POST['lead_id']);

        // Usa instância do lead tracking se disponível
        global $formulario_hapvida_lead_tracking;
        if ($formulario_hapvida_lead_tracking) {
            $result = $formulario_hapvida_lead_tracking->force_redistribute_lead($lead_id);

            if ($result) {
                $debug_info = "✅ Redistribuição executada com sucesso para o lead {$lead_id}";
            } else {
                $debug_info = "âŒ Falha na redistribuição do lead {$lead_id}";
            }
        } else {
            $debug_info = "âŒ Sistema de lead tracking não está disponível";
            $result = false;
        }

        wp_send_json(array(
            'success' => $result,
            'data' => array(
                'debug_info' => $debug_info,
                'lead_id' => $lead_id,
                'timestamp' => current_time('d/m/Y H:i:s')
            )
        ));
    }




    public function ajax_validate_webhook_config()
    {
        if (!wp_verify_nonce($_POST['security'], 'validate_config_nonce')) {
            wp_die('Nonce verification failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $debug_info = "=== VALIDAÇÃO DE CONFIGURAÇÃ•ES ===\n\n";

        $options = get_option('formulario_hapvida_settings');

        if (!$options || !is_array($options)) {
            $debug_info .= "âŒ ERRO CRÃTICO: Opções do plugin não encontradas!\n";
            wp_send_json_success(array('validation_info' => $debug_info));
            return;
        }

        $webhook_configs = array(
            'webhook_url_drv' => 'DRV - Primeiro Envio',
            'webhook_url_drv_redistribution' => 'DRV - Redistribuição',
            'webhook_url_drv_confirmation' => 'DRV - Confirmação',
            'webhook_url_seu_souza' => 'Seu Souza - Primeiro Envio',
            'webhook_url_seu_souza_redistribution' => 'Seu Souza - Redistribuição',
            'webhook_url_seu_souza_confirmation' => 'Seu Souza - Confirmação'
        );

        $valid_configs = 0;
        $total_configs = count($webhook_configs);

        foreach ($webhook_configs as $key => $description) {
            $url = isset($options[$key]) ? trim($options[$key]) : '';

            if (empty($url)) {
                $debug_info .= "âš ï¸ {$description}: NÃO CONFIGURADO\n";
            } else if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $debug_info .= "âŒ {$description}: URL INVÃLIDA - {$url}\n";
            } else {
                $debug_info .= "✅ {$description}: OK - " . substr($url, 0, 50) . "...\n";
                $valid_configs++;
            }
        }

        $debug_info .= "\nðŸ“Š RESUMO: {$valid_configs}/{$total_configs} configurações válidas\n\n";

        // Valida configurações obrigatórias
        $required_drv = isset($options['webhook_url_drv']) && !empty(trim($options['webhook_url_drv']));
        $required_redistribution_drv = isset($options['webhook_url_drv_redistribution']) && !empty(trim($options['webhook_url_drv_redistribution']));

        if (!$required_drv && !$required_redistribution_drv) {
            $debug_info .= "âŒ ERRO CRÃTICO: Nenhuma URL de webhook configurada para DRV!\n";
            $debug_info .= "   É necessário configurar pelo menos uma URL para o grupo DRV.\n";
        } else {
            $debug_info .= "✅ Configurações básicas OK para redistribuição DRV\n";
        }

        wp_send_json_success(array('validation_info' => $debug_info));
    }


    public function ajax_debug_lead_process()
    {
        if (!wp_verify_nonce($_POST['security'], 'debug_lead_nonce')) {
            wp_die('Nonce verification failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $lead_id = sanitize_text_field($_POST['lead_id']);

        // Busca dados do lead no banco
        global $wpdb;
        $table_name = $wpdb->prefix . 'hapvida_leads';

        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE lead_id = %s",
            $lead_id
        ), ARRAY_A);

        if (!$lead) {
            wp_send_json_error("Lead {$lead_id} não encontrado no banco de dados");
            return;
        }

        // Monta informações de debug
        $debug_info = "=== DEBUG DO LEAD {$lead_id} ===\n\n";
        $debug_info .= "ðŸ“‹ DADOS DO LEAD:\n";
        $debug_info .= "   - ID: {$lead['lead_id']}\n";
        $debug_info .= "   - Vendedor atual: {$lead['vendedor_nome']}\n";
        $debug_info .= "   - Grupo: {$lead['grupo']}\n";
        $debug_info .= "   - Status: {$lead['status']}\n";
        $debug_info .= "   - Tentativas: {$lead['tentativas']}/3\n";
        $debug_info .= "   - Expira em: {$lead['expira_em']}\n";
        $debug_info .= "   - Criado em: {$lead['criado_em']}\n\n";

        // Valida configurações de webhook
        $options = get_option('formulario_hapvida_settings');
        $grupo = $lead['grupo'];

        $debug_info .= "ðŸ”§ CONFIGURAÇÃ•ES DE WEBHOOK:\n";

        if ($grupo === 'drv') {
            $webhook_url = isset($options['webhook_url_drv_redistribution']) ?
                $options['webhook_url_drv_redistribution'] :
                (isset($options['webhook_url_drv']) ? $options['webhook_url_drv'] : '');

            $debug_info .= "   - URL DRV Redistribuição: " . (isset($options['webhook_url_drv_redistribution']) ?
                substr($options['webhook_url_drv_redistribution'], 0, 50) . "..." : 'NÃO CONFIGURADO') . "\n";
            $debug_info .= "   - URL DRV Principal: " . (isset($options['webhook_url_drv']) ?
                substr($options['webhook_url_drv'], 0, 50) . "..." : 'NÃO CONFIGURADO') . "\n";

        } elseif ($grupo === 'seu_souza') {
            $webhook_url = isset($options['webhook_url_seu_souza_redistribution']) ?
                $options['webhook_url_seu_souza_redistribution'] :
                (isset($options['webhook_url_seu_souza']) ? $options['webhook_url_seu_souza'] : '');

            $debug_info .= "   - URL Seu Souza Redistribuição: " . (isset($options['webhook_url_seu_souza_redistribution']) ?
                substr($options['webhook_url_seu_souza_redistribution'], 0, 50) . "..." : 'NÃO CONFIGURADO') . "\n";
            $debug_info .= "   - URL Seu Souza Principal: " . (isset($options['webhook_url_seu_souza']) ?
                substr($options['webhook_url_seu_souza'], 0, 50) . "..." : 'NÃO CONFIGURADO') . "\n";
        }

        if (!empty($webhook_url)) {
            $debug_info .= "\n✅ URL de webhook encontrada para redistribuição\n";
            $debug_info .= "🔍 URL que será usada: " . substr($webhook_url, 0, 50) . "...\n";
        } else {
            $debug_info .= "\nâŒ ERRO: Nenhuma URL de webhook configurada para grupo {$grupo}\n";
        }

        wp_send_json_success(array('debug_info' => $debug_info));
    }

    public function ajax_get_pending_webhooks()
    {
        // *** VERIFICAÇÃO DE NONCE FLEXÃVEL ***
        $security_valid = false;

        if (isset($_POST['security'])) {
            $security_valid = wp_verify_nonce($_POST['security'], 'get_pending_webhooks_nonce');
        }

        // Para usuários não logados, permite em ambiente de desenvolvimento
        if (!$security_valid && !is_user_logged_in() && defined('WP_DEBUG') && WP_DEBUG) {
            error_log("âš ï¸ DEBUG: Permitindo acesso a webhooks sem nonce (frontend)");
            $security_valid = true;
        }

        if (!$security_valid) {
            wp_send_json_error('Acesso negado');
            return;
        }

        try {
            $failed_webhooks = get_option($this->failed_webhooks_option, array());
            $pending_webhooks = array_filter($failed_webhooks, function ($webhook) {
                return isset($webhook['status']) && $webhook['status'] === 'pending';
            });

            $formatted_webhooks = array();

            foreach ($pending_webhooks as $webhook) {
                $webhook_data = isset($webhook['data']) ? $webhook['data'] : array();

                $formatted_webhooks[] = array(
                    'webhook_id' => isset($webhook['id']) ? $webhook['id'] : uniqid('webhook_'),
                    'client_name' => $webhook_data['nome'] ?? 'N/A',
                    'client_phone' => $webhook_data['telefone'] ?? 'N/A',
                    'vendor_name' => $webhook_data['atendente'] ?? 'N/A',
                    'vendor_group' => strtoupper($webhook_data['grupo'] ?? 'N/A'),
                    'attempts' => $webhook['attempts'] ?? 0,
                    'max_attempts' => $webhook['max_attempts'] ?? 3,
                    'created_at' => date('d/m H:i', strtotime($webhook['created_at'])),
                    'error_message' => isset($webhook['error']) ? substr($webhook['error'], 0, 100) : 'N/A'
                );
            }

            wp_send_json_success(array(
                'webhooks' => $formatted_webhooks,
                'total_count' => count($formatted_webhooks)
            ));

        } catch (Exception $e) {
            error_log('Erro ao obter webhooks: ' . $e->getMessage());
            wp_send_json_error('Erro interno do servidor');
        }
    }

    public function ajax_adjust_submission_count()
    {
        $adjustment = intval($_POST['adjustment']); // 1 ou -1
        $count_type = sanitize_text_field($_POST['count_type']); // 'daily' ou 'monthly'

        $today = current_time('Y-m-d');
        $current_month = current_time('Y-m');

        $daily_submissions = get_option($this->daily_submissions_option, array());
        $monthly_submissions = get_option($this->monthly_submissions_option, array());

        // Ajusta contagem diária
        if ($count_type === 'daily' || $count_type === 'both') {
            $current_daily = isset($daily_submissions[$today]) ? $daily_submissions[$today] : 0;
            $new_daily = max(0, $current_daily + $adjustment);
            $daily_submissions[$today] = $new_daily;
            update_option($this->daily_submissions_option, $daily_submissions);
        }

        // Ajusta contagem mensal
        if ($count_type === 'monthly' || $count_type === 'both') {
            $current_monthly = isset($monthly_submissions[$current_month]) ? $monthly_submissions[$current_month] : 0;
            $new_monthly = max(0, $current_monthly + $adjustment);
            $monthly_submissions[$current_month] = $new_monthly;
            update_option($this->monthly_submissions_option, $monthly_submissions);
        }

        // Retorna as contagens atualizadas
        wp_send_json_success(array(
            'daily_count' => isset($daily_submissions[$today]) ? $daily_submissions[$today] : 0,
            'monthly_count' => isset($monthly_submissions[$current_month]) ? $monthly_submissions[$current_month] : 0
        ));
    }

    public function set_business_hours_timeout($minutes)
    {
        $this->business_hours_timeout = intval($minutes);
    }

    public function set_after_hours_timeout($minutes)
    {
        $this->after_hours_timeout = intval($minutes);
    }

    public function get_business_hours_timeout()
    {
        return $this->business_hours_timeout;
    }

    public function get_after_hours_timeout()
    {
        return $this->after_hours_timeout;
    }

    public function is_horario_comercial()
    {
        // Usa a verificação do lead tracking (que funciona corretamente)
        global $formulario_hapvida_lead_tracking;
        if ($formulario_hapvida_lead_tracking && method_exists($formulario_hapvida_lead_tracking, 'is_horario_comercial')) {
            $result = $formulario_hapvida_lead_tracking->is_horario_comercial();
            $this->log("ðŸ• Horário comercial (via lead tracking): " . ($result ? 'DENTRO' : 'FORA'));
            return $result;
        }

        // Fallback
        $this->log("âš ï¸ Lead tracking indisponível, usando fallback");
        return false;
    }

    public function save_timeout_settings()
    {
        if (isset($_POST['hapvida_timeout_settings']) && wp_verify_nonce($_POST['hapvida_timeout_nonce'], 'save_timeout_settings')) {
            $options = get_option($this->settings_option_name, array());

            if (isset($_POST['business_hours_timeout'])) {
                $options['business_hours_timeout'] = max(5, intval($_POST['business_hours_timeout']));
            }

            if (isset($_POST['after_hours_timeout'])) {
                $options['after_hours_timeout'] = max(10, intval($_POST['after_hours_timeout']));
            }

            update_option($this->settings_option_name, $options);

            // Atualiza propriedades da instância
            $this->load_timeout_settings();

            add_action('admin_notices', function () {
                echo '<div class="notice notice-success"><p>Configurações de timeout salvas com sucesso!</p></div>';
            });
        }
    }


    public function register_rest_route()
    {
        // *** CORREÇÃO: Remove verificação que estava causando erro ***

        // Endpoint para submissão do formulário
        register_rest_route('formulario-hapvida/v1', '/submit-form', array(
            'methods' => array('POST', 'GET'),
            'callback' => array($this, 'handle_form_submission'),
            'permission_callback' => '__return_true',
            'args' => array(),
        ));

        // Endpoint para cron externo
        register_rest_route('formulario-hapvida/v1', '/process-webhooks', array(
            'methods' => array('GET', 'POST'),
            'callback' => array($this, 'handle_external_cron_request'),
            'permission_callback' => array($this, 'verify_cron_request')
        ));

        register_rest_route('formulario-hapvida/v1', '/cleanup', array(
            'methods' => array('GET', 'POST'),
            'callback' => array($this, 'handle_external_cron_cleanup'),
            'permission_callback' => '__return_true',
        ));

        // Log apenas em debug
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('HAPVIDA DEBUG: Rotas REST registradas');
        }
    }


    public function verify_cron_request($request)
    {
        // Opção 1: Verificar por chave secreta (RECOMENDADO)
        $secret_key = defined('HAPVIDA_CRON_SECRET') ? HAPVIDA_CRON_SECRET : 'webhook-retry-2024';
        $provided_key = $request->get_param('secret') ?: $request->get_header('X-Cron-Secret');

        if ($provided_key === $secret_key) {
            return true;
        }

        // Opção 2: Verificar por IP (se necessário)
        $allowed_ips = array(
            '127.0.0.1',        // localhost
            '::1',              // IPv6 localhost
            // Adicione IPs do seu servidor de cron aqui
        );

        $client_ip = $this->get_client_ip();
        if (in_array($client_ip, $allowed_ips)) {
            return true;
        }

        // Log de tentativa não autorizada
        $this->log("âŒ Tentativa não autorizada de acesso ao cron: IP {$client_ip}, Key: {$provided_key}");

        return false;
    }

    /**
     * *** NOVO: Obtém IP do cliente ***
     */
    private function get_client_ip()
    {
        $ip_headers = array(
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        );

        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Pega o primeiro IP se houver múltiplos
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    public function handle_form_submission($request)
    {

        // DEBUG TEMPORÃRIO - REMOVER DEPOIS
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        ini_set('log_errors', 1);
        ini_set('error_log', WP_CONTENT_DIR . '/debug_hapvida.log');

        try {
            $start_time = microtime(true);
            $session_id = uniqid('sess_', true);

            // Extração e validação dos dados (MANTENDO ESTRUTURA ORIGINAL)
            $params = $request->get_params();

            // *** EXTRAÇÃO DOS DADOS DO FORMULÃRIO (MANTENDO ESTRUTURA ORIGINAL) ***
            $form_data = array();

            // Verifica se os dados vêm de form_fields[] ou diretamente
            if (isset($params['form_fields']) && is_array($params['form_fields'])) {
                $form_data['name'] = isset($params['form_fields']['name']) ? $params['form_fields']['name'] : '';
                $form_data['telefone'] = isset($params['form_fields']['telefone']) ? $params['form_fields']['telefone'] : '';
                $form_data['cidade'] = isset($params['form_fields']['cidade']) ? $params['form_fields']['cidade'] : '';
                $form_data['qual_plano'] = isset($params['form_fields']['qual_plano']) ? $params['form_fields']['qual_plano'] : '';
                $form_data['qtd_pessoas'] = isset($params['form_fields']['qtd_pessoas']) ? $params['form_fields']['qtd_pessoas'] : '1';
                $form_data['ages'] = isset($params['form_fields']['ages']) ? $params['form_fields']['ages'] : array();
            } else {
                $form_data['name'] = isset($params['name']) ? $params['name'] : '';
                $form_data['telefone'] = isset($params['telefone']) ? $params['telefone'] : '';
                $form_data['cidade'] = isset($params['cidade']) ? $params['cidade'] : '';
                $form_data['qual_plano'] = isset($params['qual_plano']) ? $params['qual_plano'] : '';
                $form_data['qtd_pessoas'] = isset($params['qtd_pessoas']) ? $params['qtd_pessoas'] : '1';
                $form_data['ages'] = isset($params['ages']) ? $params['ages'] : array();
            }

            // Defaults
            $defaults = array(
                'name' => '',
                'telefone' => '',
                'cidade' => '',
                'qual_plano' => '',
                'qtd_pessoas' => '1',
                'ages' => array(),
                'pagina_origem' => (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : home_url())
            );

            $form_data = array_merge($defaults, $form_data);

            // Validação de campos obrigatórios
            foreach ($this->required_fields as $field) {
                if (empty($form_data[$field])) {
                    throw new Exception("Campo obrigatório ausente: {$field}");
                }
            }

            // Verifica se já foi processado (apenas log, NÃO bloqueia o webhook)
            if ($this->is_form_processed($form_data)) {
                $this->log("AVISO DUPLICATA: Telefone {$form_data['telefone']} já enviado recentemente, mas webhook será enviado normalmente.");
            }

            // Marca como processado
            $this->mark_form_as_processed($form_data);

            // Formata e processa dados básicos
            $form_data['telefone'] = $this->format_phone_number($form_data['telefone']);
            $form_data['data'] = current_time('d/m/Y');
            $form_data['hora'] = current_time('H:i:s');
            $form_data['timestamp'] = current_time('timestamp');
            $form_data['ages'] = $this->extract_ages_from_request($params);
            $form_data['lead_id'] = $this->generate_unique_lead_id();

            // Log dos dados
            $this->log("ðŸ“‹ DADOS DO FORMULÃRIO: ===== NOVA SUBMISSÃO =====");
            $this->log("Lead ID: {$form_data['lead_id']}");
            $this->log("Nome: {$form_data['name']}");
            $this->log("Telefone: {$form_data['telefone']}");
            $this->log("Cidade: {$form_data['cidade']}");

            // Obtém próximo vendedor (passa a cidade e página de origem para verificar vendedor específico)
            $vendedor = $this->get_next_vendedor($form_data['cidade'], $form_data['pagina_origem']);
            if (!$vendedor) {
                throw new Exception('Nenhum vendedor disponível no momento.');
            }

            // Verifica se foi roteamento por URL específica
            $roteamento_url = false;
            if (!empty($form_data['pagina_origem'])) {
                $vendedor_por_url = $this->get_vendedor_por_url($form_data['pagina_origem']);
                if ($vendedor_por_url) {
                    $roteamento_url = true;
                    $this->log("🎯 Lead direcionado por ROTA ESPECÍFICA de URL");
                }
            }

            $this->log("👤 Vendedor selecionado: {$vendedor['nome']} ({$vendedor['grupo']})");

            // NOVO LOG: Mostra o ID do vendedor se existir
            if (isset($vendedor['vendedor_id']) && !empty($vendedor['vendedor_id'])) {
                $this->log("ID do Vendedor: {$vendedor['vendedor_id']}");
            }

            // Gera URL do WhatsApp
            $whatsapp_url = $this->generate_whatsapp_url($form_data, $vendedor);

            // Atualiza contadores
            $this->update_submission_counts();
            //$this->update_ultimo_vendedor($vendedor);

            // *** PROCESSAMENTO DO WEBHOOK - VERSÃO OTIMIZADA ***
            $options = get_option($this->settings_option_name);
            $is_business_hours = $this->is_horario_comercial();
            $enable_redistributions = isset($options['enable_lead_redistribution']) &&
                $options['enable_lead_redistribution'] === 'yes';

            // Tenta enviar webhook com timeout reduzido
            $webhook_success = false;

            try {
                // Prepara dados do webhook
                $webhook_data = $form_data; // Copia os dados

                // Adiciona dados do vendedor
                $webhook_data['vendedor_nome'] = $vendedor['nome'];
                $webhook_data['vendedor_telefone'] = $vendedor['telefone'];
                $webhook_data['vendedor_id'] = isset($vendedor['vendedor_id']) ? $vendedor['vendedor_id'] : '';
                $webhook_data['grupo'] = isset($vendedor['grupo']) ? $vendedor['grupo'] : 'drv';
                $webhook_data['atendente'] = $vendedor['nome'];

                // *** NOVO: Adiciona informações sobre roteamento específico ***
                $webhook_data['roteamento_especifico'] = $roteamento_url ? 'sim' : 'nao';
                $webhook_data['tipo_roteamento'] = $roteamento_url ? 'url_consultor' : 'round_robin';
                $webhook_data['url_origem'] = $form_data['pagina_origem'];

                // Adiciona contagens
                $daily_submissions = get_option($this->daily_submissions_option, array());
                $monthly_submissions = get_option($this->monthly_submissions_option, array());
                $today = current_time('Y-m-d');
                $current_month = current_time('Y-m');

                $webhook_data['contagem_diaria'] = isset($daily_submissions[$today]) ?
                    $daily_submissions[$today] : 0;
                $webhook_data['contagem_mensal'] = isset($monthly_submissions[$current_month]) ?
                    $monthly_submissions[$current_month] : 0;

                // Formata campos específicos para o webhook
                $webhook_data['nome'] = $form_data['name'];
                $webhook_data['quantidade_de_pessoas'] = $form_data['qtd_pessoas'];
                $webhook_data['tipo_de_plano'] = $form_data['qual_plano'];
                $webhook_data['idades'] = is_array($form_data['ages']) ?
                    implode(', ', $form_data['ages']) : $form_data['ages'];

                // Determina URL do webhook
                $grupo = isset($vendedor['grupo']) ? $vendedor['grupo'] : 'drv';
                if ($grupo === 'drv') {
                    $webhook_url = isset($options['webhook_url_drv']) ? $options['webhook_url_drv'] : '';
                } else {
                    $webhook_url = isset($options['webhook_url_seu_souza']) ? $options['webhook_url_seu_souza'] : '';
                }

                if (!empty($webhook_url)) {
                    // *** ENVIO ASSÃNCRONO COM TIMEOUT REDUZIDO ***
                    $this->log("ðŸ“¤ Enviando webhook de forma assíncrona...");

                    // LOG DO ID DO VENDEDOR NO WEBHOOK
                    if (isset($webhook_data['vendedor_id']) && !empty($webhook_data['vendedor_id'])) {
                        $this->log("Webhook incluirá ID do vendedor: {$webhook_data['vendedor_id']}");
                    }

                    // Configuração otimizada com timeout reduzido
                    $webhook_config = array(
                        'timeout' => 5,  // Apenas 5 segundos
                        'blocking' => false, // Não bloqueia
                        'body' => json_encode($webhook_data),
                        'headers' => array('Content-Type' => 'application/json'),
                        'sslverify' => false
                    );

                    // Envia sem esperar resposta
                    wp_remote_post($webhook_url, $webhook_config);

                    // Salva para retry posterior se necessário
                    $this->save_webhook_entry($webhook_data, 'pending', 'Enviado assincronamente');

                    $webhook_success = true;
                    $this->log("✅ Webhook enviado de forma assíncrona");

                    // Se está em horário comercial e redistribuição está ativa, salva no tracking
                    if ($enable_redistributions && $is_business_hours) {
                        global $formulario_hapvida_lead_tracking;
                        if ($formulario_hapvida_lead_tracking && method_exists($formulario_hapvida_lead_tracking, 'create_lead')) {
                            try {
                                $lead_id = $formulario_hapvida_lead_tracking->create_lead(
                                    $form_data,
                                    $vendedor,
                                    $this->get_dynamic_timeout()
                                );
                                $this->log("✅ Lead salvo no sistema de tracking: {$lead_id}");
                            } catch (Exception $e) {
                                $this->log("âš ï¸ Erro ao salvar no tracking: " . $e->getMessage());
                            }
                        }
                    }
                } else {
                    $this->log("âš ï¸ URL do webhook não configurada para o grupo {$grupo}");
                }

            } catch (Exception $e) {
                $this->log("âš ï¸ Erro no webhook: " . $e->getMessage());
                $webhook_success = false;
            }

            // *** NOVO: ENVIA DADOS PARA API LEADP3 (NÃO-BLOQUEANTE) ***
            try {
                // CORRIGIDO: Verifica se a classe existe antes de usar
                // $leadp3_integration = get_leadp3_integration_instance();
                $leadp3_integration = null;
                if (class_exists('Formulario_Hapvida_LeadP3_Integration')) {
                    global $formulario_hapvida_leadp3;
                    $leadp3_integration = $formulario_hapvida_leadp3;
                }
                if ($leadp3_integration) {
                    // Envio assíncrono - retorna instantaneamente
                    $leadp3_integration->send_to_leadp3($form_data, $vendedor);
                    // â†‘ NÃO bloqueia - continua imediatamente
                }
            } catch (Exception $e) {
                // Apenas registra erro - não afeta formulário
                error_log("LeadP3: " . $e->getMessage());
            }

            // Prepara resposta de sucesso
            $response = array(
                'success' => true,
                'message' => 'Formulário processado com sucesso! Redirecionando...',
                'redirect' => $whatsapp_url, // *** MANTÉM COMO ESTAVA ***
                'whatsapp_url' => $whatsapp_url, // *** ADICIONA APENAS ESTA LINHA EXTRA ***
                'webhook_status' => $webhook_success ? 'sent_async' : 'queued_for_retry',
                'business_hours' => $is_business_hours,
                'tracking_enabled' => $enable_redistributions && $is_business_hours,
                'vendor_info' => array(
                    'name' => $vendedor['nome'],
                    'group' => $vendedor['grupo'],
                    'phone' => $vendedor['telefone'],
                    'id' => isset($vendedor['vendedor_id']) ? $vendedor['vendedor_id'] : ''
                ),
                'processed_data' => array(
                    'phone_formatted' => $form_data['telefone'],
                    'submission_time' => $form_data['data'] . ' ' . $form_data['hora'],
                    'ages_extracted' => $form_data['ages'],
                    'lead_id' => $form_data['lead_id']
                )
            );

            $execution_time = (microtime(true) - $start_time) * 1000;
            $this->log("â±ï¸ Tempo de execução: {$execution_time}ms");
            $this->log("ðŸ“‹ DADOS DO FORMULÃRIO: ===== FIM DA SUBMISSÃO =====");

            return new WP_REST_Response($response, 200);

        } catch (Exception $e) {
            error_log("HAPVIDA ERROR: " . $e->getMessage());
            $this->log("âŒ ERRO: " . $e->getMessage());

            // Em caso de erro, remove a marcação de processado
            if (isset($form_data)) {
                $this->unmark_form_as_processed($form_data);
            }

            return new WP_REST_Response(array(
                'success' => false,
                'message' => $e->getMessage()
            ), 400);
        }
    }

    private function update_ultimo_vendedor($vendedor)
    {
        $ultimo_vendedor_info = array(
            'vendedor' => $vendedor,
            'timestamp' => current_time('timestamp'),
            'data' => current_time('d/m/Y H:i:s')
        );

        update_option($this->ultimo_vendedor_option_name, $ultimo_vendedor_info);

        $this->log("👤 Último vendedor atualizado: {$vendedor['nome']}");
    }

    private function update_submission_counts()
    {
        // Atualiza contador diário
        $daily_submissions = get_option($this->daily_submissions_option, array());
        $today = current_time('Y-m-d');

        if (!isset($daily_submissions[$today])) {
            $daily_submissions[$today] = 0;
        }
        $daily_submissions[$today]++;

        update_option($this->daily_submissions_option, $daily_submissions);

        // Atualiza contador mensal
        $monthly_submissions = get_option($this->monthly_submissions_option, array());
        $current_month = current_time('Y-m');

        if (!isset($monthly_submissions[$current_month])) {
            $monthly_submissions[$current_month] = 0;
        }
        $monthly_submissions[$current_month]++;

        update_option($this->monthly_submissions_option, $monthly_submissions);

        $this->log("ðŸ“Š Contadores atualizados - Diário: {$daily_submissions[$today]}, Mensal: {$monthly_submissions[$current_month]}");
    }

    private function extract_ages_from_request($params)
    {
        $ages = array();

        // Verifica se ages vem como array direto
        if (isset($params['ages']) && is_array($params['ages'])) {
            return $params['ages'];
        }

        // Verifica dentro de form_fields
        if (isset($params['form_fields']['ages']) && is_array($params['form_fields']['ages'])) {
            return $params['form_fields']['ages'];
        }

        // Tenta extrair de campos individuais age_1, age_2, etc
        for ($i = 1; $i <= 10; $i++) {
            if (isset($params["age_$i"]) && !empty($params["age_$i"])) {
                $ages[] = $params["age_$i"];
            } elseif (isset($params['form_fields']["age_$i"]) && !empty($params['form_fields']["age_$i"])) {
                $ages[] = $params['form_fields']["age_$i"];
            }
        }

        // Se ainda não tem idades, tenta campo único 'idade'
        if (empty($ages)) {
            if (isset($params['idade']) && !empty($params['idade'])) {
                $ages[] = $params['idade'];
            } elseif (isset($params['form_fields']['idade']) && !empty($params['form_fields']['idade'])) {
                $ages[] = $params['form_fields']['idade'];
            }
        }

        return $ages;
    }

    private function format_phone_number($phone)
    {
        // Remove todos os caracteres não numéricos
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Se tem 11 dígitos (com DDD e 9 dígito)
        if (strlen($phone) == 11) {
            return sprintf(
                '(%s) %s-%s',
                substr($phone, 0, 2),
                substr($phone, 2, 5),
                substr($phone, 7)
            );
        }
        // Se tem 10 dígitos (com DDD sem 9 dígito)
        elseif (strlen($phone) == 10) {
            return sprintf(
                '(%s) %s-%s',
                substr($phone, 0, 2),
                substr($phone, 2, 4),
                substr($phone, 6)
            );
        }
        // Se tem 9 dígitos (celular sem DDD)
        elseif (strlen($phone) == 9) {
            return sprintf(
                '%s-%s',
                substr($phone, 0, 5),
                substr($phone, 5)
            );
        }
        // Se tem 8 dígitos (fixo sem DDD)
        elseif (strlen($phone) == 8) {
            return sprintf(
                '%s-%s',
                substr($phone, 0, 4),
                substr($phone, 4)
            );
        }

        // Retorna o telefone original se não se encaixa em nenhum formato
        return $phone;
    }

    private function enqueue_webhook_for_async_processing($form_data, $vendedor)
    {
        try {
            // Prepara dados para o webhook
            $webhook_data = array(
                'form_data' => $form_data,
                'vendedor' => $vendedor,
                'timestamp' => current_time('timestamp'),
                'priority' => 'high'
            );

            // Salva na fila para processamento imediato
            $queue = get_option('hapvida_webhook_queue', array());
            array_unshift($queue, $webhook_data); // Adiciona no início para processar primeiro

            // Limita a fila a 500 itens para evitar crescimento excessivo
            if (count($queue) > 500) {
                $queue = array_slice($queue, 0, 500);
            }

            update_option('hapvida_webhook_queue', $queue);

            // Agenda processamento imediato via WP-Cron
            if (!wp_next_scheduled('hapvida_process_webhook_queue')) {
                wp_schedule_single_event(time(), 'hapvida_process_webhook_queue');
            }

            // Tenta processar via requisição não-bloqueante
            $this->trigger_async_webhook_processing();

            $this->log("ðŸ“‹ Webhook enfileirado para processamento assíncrono - Lead ID: " . $form_data['lead_id']);

        } catch (Exception $e) {
            $this->log("âš ï¸ Erro ao enfileirar webhook, tentando envio direto: " . $e->getMessage());
            // Em caso de erro, tenta envio direto com timeout reduzido
            $this->send_webhook_with_reduced_timeout($form_data, $vendedor);
        }
    }

    /**
     * Envia webhook com timeout reduzido para casos de emergência
     */
    private function send_webhook_with_reduced_timeout($form_data, $vendedor)
    {
        $options = get_option($this->settings_option_name);
        $grupo = isset($vendedor['grupo']) ? $vendedor['grupo'] : 'drv';

        if ($grupo === 'drv') {
            $webhook_url = isset($options['webhook_url_drv']) ? $options['webhook_url_drv'] : '';
        } else {
            $webhook_url = isset($options['webhook_url_seu_souza']) ? $options['webhook_url_seu_souza'] : '';
        }

        if (empty($webhook_url)) {
            return false;
        }

        // Prepara dados mínimos
        $webhook_data = $this->prepare_webhook_data($form_data, $vendedor);

        // Configuração ultra-rápida
        $quick_config = array(
            'timeout' => 3, // Apenas 3 segundos
            'blocking' => false, // Não bloqueia
            'body' => json_encode($webhook_data),
            'headers' => array('Content-Type' => 'application/json'),
            'sslverify' => false
        );

        wp_remote_post($webhook_url, $quick_config);

        // Salva para retry posterior se necessário
        $this->save_webhook_entry($webhook_data, 'pending', 'Enviado com timeout reduzido');

        return true;
    }

    /**
     * Dispara processamento assíncrono de webhooks
     */
    private function trigger_async_webhook_processing()
    {
        $url = admin_url('admin-ajax.php');
        $args = array(
            'timeout' => 0.01,
            'blocking' => false,
            'body' => array(
                'action' => 'hapvida_process_webhook_queue_async',
                'security' => wp_create_nonce('hapvida_async_webhook')
            ),
            'cookies' => $_COOKIE,
            'sslverify' => false
        );

        wp_remote_post($url, $args);
    }

    private function is_form_already_submitted_fast($form_data)
    {
        $telefone = isset($form_data['telefone']) ? $form_data['telefone'] : '';
        if (empty($telefone))
            return false;

        $telefone_clean = preg_replace('/[^0-9]/', '', $telefone);
        if (empty($telefone_clean))
            return false;

        // Usa a mesma chave que a versão principal para consistência
        $processed_key = 'processed_phone_' . md5($telefone_clean);

        // Primeiro verifica no cache de memória (mais rápido)
        $cache_key = 'hapvida_' . $processed_key;
        $cached = wp_cache_get($cache_key, 'hapvida_submissions');

        if ($cached !== false) {
            $elapsed = time() - $cached;
            if ($elapsed < 180) { // 3 minutos
                $this->log("âš ï¸ [CACHE] Telefone {$telefone} já processado (cache)");
                return true;
            }
        }

        // Se não encontrou no cache, verifica no transient
        $transient = get_transient($processed_key);
        if ($transient) {
            // Adiciona ao cache para próximas verificações
            wp_cache_set($cache_key, $transient, 'hapvida_submissions', 180);
            $this->log("âš ï¸ [TRANSIENT] Telefone {$telefone} já processado (transient)");
            return true;
        }

        return false;
    }

    // ==================================================================
// 3. MARCA SUBMISSÃO EM CACHE RÃPIDO
// ==================================================================
    private function mark_form_as_processed_fast($form_data)
    {
        $telefone = isset($form_data['telefone']) ? $form_data['telefone'] : '';
        if (!empty($telefone)) {
            $telefone_clean = preg_replace('/[^0-9]/', '', $telefone);

            if (!empty($telefone_clean)) {
                $processed_key = 'processed_phone_' . md5($telefone_clean);
                $cache_key = 'hapvida_' . $processed_key;
                $current_time = time();

                // Salva em cache de memória (mais rápido)
                wp_cache_set($cache_key, $current_time, 'hapvida_submissions', 180);

                // IMPORTANTE: Também salva em transient para persistência
                set_transient($processed_key, $current_time, 180);

                $this->log("✅ [FAST] Telefone {$telefone} marcado como processado (cache + transient)");
            }
        }
    }

    // ==================================================================
// 4. VALIDAÇÃO OTIMIZADA
// ==================================================================
    private function validate_required_fields_fast($form_data)
    {
        // Validação apenas dos campos essenciais
        if (empty($form_data['name']) || strlen(trim($form_data['name'])) < 3) {
            return array('valid' => false, 'message' => 'Por favor, informe seu nome completo.');
        }

        if (empty($form_data['telefone'])) {
            return array('valid' => false, 'message' => 'Por favor, informe seu WhatsApp.');
        }

        // Validação básica do telefone
        $phone_clean = preg_replace('/[^0-9]/', '', $form_data['telefone']);
        if (strlen($phone_clean) < 10 || strlen($phone_clean) > 11) {
            return array('valid' => false, 'message' => 'Número de WhatsApp inválido.');
        }

        return array('valid' => true);
    }

    // ==================================================================
// 5. GERA URL WHATSAPP OTIMIZADA
// ==================================================================
    private function generate_whatsapp_url_optimized($vendedor_telefone, $form_data)
    {
        // Remove caracteres não numéricos
        $telefone_clean = preg_replace('/[^0-9]/', '', $vendedor_telefone);

        // Adiciona código do Brasil se necessário
        if (strlen($telefone_clean) === 10 || strlen($telefone_clean) === 11) {
            $telefone_clean = '55' . $telefone_clean;
        }

        // Monta mensagem simplificada
        $nome = $form_data['name'] ?? '';
        $cidade = $form_data['cidade'] ?? '';
        $plano = $form_data['qual_plano'] ?? '';
        $qtd = $form_data['qtd_pessoas'] ?? '1';

        // Processa idades
        $idades = '';
        if (isset($form_data['ages'])) {
            $idades = is_array($form_data['ages'])
                ? implode(', ', $form_data['ages'])
                : $form_data['ages'];
        }

        // Mensagem formatada
        $mensagem = "Olá, meu nome é *{$nome}*, gostaria de uma cotação para:\n\n";
        $mensagem .= "*Cidade:* {$cidade}\n";
        $mensagem .= "*Quantidade:* {$qtd} pessoa(s)\n";
        $mensagem .= "*Plano:* {$plano}\n";
        if (!empty($idades)) {
            $mensagem .= "*Idades:* {$idades}\n";
        }

        // Codifica e retorna URL
        return "https://wa.me/{$telefone_clean}?text=" . urlencode($mensagem);
    }

    // ==================================================================
// 6. WEBHOOK ASSÃNCRONO (NÃO BLOQUEIA)
// ==================================================================
    private function schedule_webhook_async($form_data, $vendedor)
    {
        // Agenda para execução imediata em background
        wp_schedule_single_event(time(), 'hapvida_send_webhook_background', array($form_data, $vendedor));

        // Alternativa: usar wp_remote_post com blocking => false
        $this->send_webhook_non_blocking($form_data, $vendedor);
    }

    private function send_webhook_non_blocking($form_data, $vendedor)
    {
        $options = get_option($this->settings_option_name);
        $grupo = strtolower($vendedor['grupo']);

        // Determina URL do webhook
        $webhook_url = '';
        if ($grupo === 'drv') {
            $webhook_url = $options['webhook_url_drv'] ?? '';
        } elseif ($grupo === 'seu_souza') {
            $webhook_url = $options['webhook_url_seu_souza'] ?? '';
        }

        if (empty($webhook_url)) {
            return;
        }

        // Verifica se foi roteamento por URL específica
        $roteamento_url = false;
        $pagina_origem = $form_data['pagina_origem'] ?? '';
        if (!empty($pagina_origem)) {
            $vendedor_por_url = $this->get_vendedor_por_url($pagina_origem);
            if ($vendedor_por_url) {
                $roteamento_url = true;
            }
        }

        // Prepara dados do webhook - CORREÇÃO: Incluindo ID e telefone do vendedor
        $webhook_data = array(
            'lead_id' => $form_data['lead_id'] ?? $this->generate_unique_lead_id(),
            'nome' => $form_data['name'] ?? '',
            'telefone' => $form_data['telefone'] ?? '',
            'cidade' => $form_data['cidade'] ?? '',
            'tipo_de_plano' => $form_data['qual_plano'] ?? '',
            'quantidade_de_pessoas' => $form_data['qtd_pessoas'] ?? 1,
            'idades' => is_array($form_data['ages']) ? implode(', ', $form_data['ages']) : ($form_data['ages'] ?? 'N/A'),
            'grupo' => strtoupper($grupo),
            'atendente' => $vendedor['nome'] ?? '',

            // CORREÇÃO: Adicionando ID e telefone do vendedor
            'telefone_vendedor' => $vendedor['telefone'] ?? 'N/A',
            'vendedor_telefone' => $vendedor['telefone'] ?? 'N/A',
            'vendedor_nome' => $vendedor['nome'] ?? 'N/A',
            'vendedor_id' => $vendedor['vendedor_id'] ?? '', // NOVO CAMPO

            // *** NOVO: Adiciona informações sobre roteamento específico ***
            'roteamento_especifico' => $roteamento_url ? 'sim' : 'nao',
            'tipo_roteamento' => $roteamento_url ? 'url_consultor' : 'round_robin',
            'url_origem' => $pagina_origem,

            'data_envio' => date('d-m-Y'),
            'hora_submissao' => date('H:i:s'),
            'ip' => $form_data['ip'] ?? '',

            // Adiciona contagens diárias e mensais
            'contagem_diaria' => $this->get_today_submission_count(),
            'contagem_mensal' => $this->get_monthly_submission_count(),
            'pagina_origem' => $form_data['pagina_origem'] ?? home_url()
        );

        // Envia webhook sem bloquear (não aguarda resposta)
        wp_remote_post($webhook_url, array(
            'body' => json_encode($webhook_data),
            'headers' => array('Content-Type' => 'application/json'),
            'timeout' => 0.01, // Timeout mínimo
            'blocking' => false, // NÃO BLOQUEIA
            'sslverify' => false
        ));

        // Log assíncrono
        $this->log("ðŸš€ Webhook disparado em background para {$grupo} - ID vendedor: " . ($vendedor['vendedor_id'] ?? 'N/A'));
    }

    // ==================================================================
// 8. FUNÇÃO PARA PROCESSAR WEBHOOK EM BACKGROUND
// ==================================================================
    public function process_webhook_background($form_data, $vendedor)
    {
        $options = get_option($this->settings_option_name);
        $grupo = strtolower($vendedor['grupo']);

        // Determina URL do webhook
        $webhook_url = '';
        if ($grupo === 'drv') {
            $webhook_url = $options['webhook_url_drv'] ?? '';
        } elseif ($grupo === 'seu_souza') {
            $webhook_url = $options['webhook_url_seu_souza'] ?? '';
        }

        if (empty($webhook_url)) {
            return;
        }

        // Prepara dados
        $webhook_data = array(
            'lead_id' => $form_data['lead_id'],
            'nome' => $form_data['name'],
            'telefone' => $form_data['telefone'],
            'cidade' => $form_data['cidade'] ?? '',
            'plano' => $form_data['qual_plano'] ?? '',
            'qtd_pessoas' => $form_data['qtd_pessoas'] ?? 1,
            'ages' => $form_data['ages'] ?? array(),
            'grupo' => strtoupper($grupo),
            'atendente' => $vendedor['nome'],
            'data' => $form_data['data'],
            'ip' => $form_data['ip']
        );

        // Tenta enviar com retry
        $max_attempts = 3;
        $attempt = 0;
        $success = false;

        while ($attempt < $max_attempts && !$success) {
            $attempt++;

            $response = wp_remote_post($webhook_url, array(
                'body' => json_encode($webhook_data),
                'headers' => array('Content-Type' => 'application/json'),
                'timeout' => 10,
                'blocking' => true,
                'sslverify' => false
            ));

            if (!is_wp_error($response)) {
                $code = wp_remote_retrieve_response_code($response);
                if ($code >= 200 && $code < 300) {
                    $success = true;
                    $this->log("✅ Webhook enviado com sucesso (tentativa {$attempt})");
                }
            }

            if (!$success && $attempt < $max_attempts) {
                sleep(2); // Aguarda 2 segundos entre tentativas
            }
        }

        if (!$success) {
            // Salva para retry posterior
            $this->save_failed_webhook($webhook_data, $webhook_url);
        }
    }


    public function handle_external_cron_request($request)
    {
        $start_time = microtime(true);

        $this->log("=== ðŸ• CRON EXTERNO EXECUTADO ===");
        $this->log("Horário: " . current_time('d/m/Y H:i:s'));
        $this->log("IP do cliente: " . $this->get_client_ip());

        try {
            // Processa webhooks com falha
            $this->process_failed_webhooks();

            // *** NOVO: Processa também leads expirados se o sistema estiver ativo ***
            global $formulario_hapvida_lead_tracking;
            if ($formulario_hapvida_lead_tracking && method_exists($formulario_hapvida_lead_tracking, 'process_expired_leads')) {
                $this->log("ðŸ”„ Processando leads expirados via cron externo...");
                $formulario_hapvida_lead_tracking->process_expired_leads();
            }

            $execution_time = round((microtime(true) - $start_time) * 1000, 2);
            $this->log("✅ Cron externo concluído em {$execution_time}ms");

            // Busca estatísticas para retorno
            $failed_webhooks = get_option($this->failed_webhooks_option, array());
            $pending_count = count(array_filter($failed_webhooks, function ($w) {
                return $w['status'] === 'pending';
            }));

            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Cron executado com sucesso',
                'execution_time_ms' => $execution_time,
                'timestamp' => current_time('Y-m-d H:i:s'),
                'pending_webhooks' => $pending_count,
                'total_webhooks' => count($failed_webhooks)
            ), 200);

        } catch (Exception $e) {
            $execution_time = round((microtime(true) - $start_time) * 1000, 2);
            $this->log("âŒ ERRO no cron externo: " . $e->getMessage());

            return new WP_REST_Response(array(
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time_ms' => $execution_time,
                'timestamp' => current_time('Y-m-d H:i:s')
            ), 500);
        }
    }
    private function fix_ultimo_vendedor_option()
    {
        $ultimo_vendedor = get_option($this->ultimo_vendedor_option_name);

        if (empty($ultimo_vendedor) || !is_array($ultimo_vendedor) || !isset($ultimo_vendedor['indices'])) {
            update_option($this->ultimo_vendedor_option_name, array(
                'group' => '',
                'indices' => array('drv' => -1, 'seu_souza' => -1)
            ));
            $this->log("Opção de último vendedor corrigida/inicializada");
        }
    }

    public function init_lead_tracking()
    {
        global $formulario_hapvida_lead_tracking;

        if (class_exists('Formulario_Hapvida_Lead_Tracking') && !$formulario_hapvida_lead_tracking) {
            $formulario_hapvida_lead_tracking = new Formulario_Hapvida_Lead_Tracking();
            $this->log("Sistema de Lead Tracking inicializado");
        }
    }


    private function log($message)
    {
        // TEMPORARIO: Loga tudo para debug
        // Filtra para logar apenas dados de leads e mensagens de erro
        if (
            strpos($message, 'ðŸ“¥ Dados recebidos:') === 0 ||
            strpos($message, 'âŒ') === 0 ||
            strpos($message, 'âš ï¸') === 0
        ) {
            $timezone = new DateTimeZone('America/Fortaleza');
            $timestamp = new DateTime('now', $timezone);
            $log_entry = "[" . $timestamp->format('Y-m-d H:i:s') . "] {$message}" . PHP_EOL;

            error_log($log_entry, 3, $this->log_file);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("HAPVIDA FORMULARIO: " . $message);
            }
        }

        // TEMPORARIO: Loga mensagens de debug que começam com >>> ou ===
        if (strpos($message, '>>>') === 0 || strpos($message, '===') === 0) {
            $timezone = new DateTimeZone('America/Fortaleza');
            $timestamp = new DateTime('now', $timezone);
            $log_entry = "[" . $timestamp->format('Y-m-d H:i:s') . "] {$message}" . PHP_EOL;
            error_log($log_entry, 3, $this->log_file);
        }
    }

    public function enqueue_scripts()
    {
        // Carrega FontAwesome
        wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css');

        // Carrega jQuery
        wp_enqueue_script('jquery');

        // *** CORREÇÃO CRÃTICA: Localiza AJAX para frontend ***
        wp_localize_script('jquery', 'hapvida_ajax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hapvida_frontend_nonce')
        ));
    }



    /**
     * Verifica se o formulário já foi processado recentemente (por telefone)
     */
    private function is_form_processed($form_data)
    {
        $telefone = isset($form_data['telefone']) ? $form_data['telefone'] : '';
        if (empty($telefone)) {
            return false; // Sem telefone, não tem como verificar
        }

        // *** NOVO: Normaliza o telefone (remove formatação) ***
        $telefone_clean = preg_replace('/[^0-9]/', '', $telefone);

        if (empty($telefone_clean)) {
            return false;
        }

        // *** VERIFICAÇÃO POR TELEFONE ***
        $processed_key = 'processed_phone_' . md5($telefone_clean);
        $processed = get_transient($processed_key);

        if ($processed) {
            $this->log("Verificação duplicação: Telefone {$telefone} já foi processado recentemente");
            return true;
        }

        $this->log("Verificação duplicação: Telefone {$telefone} OK para nova submissão");
        return false;
    }

    /**
     * Marca formulário como processado (por telefone)
     */
    private function mark_form_as_processed($form_data)
    {
        $telefone = isset($form_data['telefone']) ? $form_data['telefone'] : '';
        if (!empty($telefone)) {
            // Normaliza telefone
            $telefone_clean = preg_replace('/[^0-9]/', '', $telefone);

            if (!empty($telefone_clean)) {
                $processed_key = 'processed_phone_' . md5($telefone_clean);

                // CORREÇÃO: Tempo alterado para 3 minutos (180 segundos)
                set_transient($processed_key, time(), 180);

                $this->log("✅ Telefone {$telefone} marcado como processado por 3 minutos");
            }
        }
    }




    public function handle_admin_debug_actions()
    {
        // Verifica se é uma ação de debug

        if (isset($_GET['verify_webhook_config']) && current_user_can('manage_options')) {
            $this->verify_webhook_configuration();
        }

        // Debug do sistema de confirmação
        global $formulario_hapvida_lead_tracking;
        if (isset($_GET['debug_confirmation_system']) && current_user_can('manage_options') && $formulario_hapvida_lead_tracking) {
            $formulario_hapvida_lead_tracking->debug_confirmation_system();
        }
    }


    private function save_webhook_entry($webhook_data, $status = 'pending', $error_message = '', $response_code = null)
    {
        try {
            error_log("ðŸ’¾ [DEBUG] Salvando webhook entry - Status: $status");

            $failed_webhooks = get_option($this->failed_webhooks_option, array());

            // Garante que sempre tem um ID único
            $webhook_id = 'webhook_' . time() . '_' . wp_rand(1000, 9999);

            $entry = array(
                'id' => $webhook_id,  // IMPORTANTE: sempre incluir o ID
                'webhook_id' => $webhook_id,
                'data' => $webhook_data,
                'status' => $status,
                'attempts' => 0,
                'max_attempts' => 3,
                'created_at' => current_time('mysql'),
                'last_attempt' => null,
                'error' => $error_message,
                'response_code' => $response_code,
                'next_retry' => date('Y-m-d H:i:s', strtotime('+10 minutes'))
            );

            // Adiciona no início do array para aparecer primeiro
            array_unshift($failed_webhooks, $entry);

            // Limita a 5000 leads para manter histórico adequado (aprox. 6 meses)
            if (count($failed_webhooks) > 5000) {
                $failed_webhooks = array_slice($failed_webhooks, 0, 5000);
            }

            $result = update_option($this->failed_webhooks_option, $failed_webhooks);

            error_log("✅ [DEBUG] Webhook salvo - ID: {$webhook_id}, Resultado: " . ($result ? 'sucesso' : 'falha'));


            return true;

        } catch (Exception $e) {
            error_log("âŒ [DEBUG] Erro ao salvar webhook: " . $e->getMessage());
            return false;
        }
    }

    // Adicione esta função para incrementar contadores
    private function increment_submission_count()
    {
        $today = current_time('Y-m-d');
        $current_month = current_time('Y-m');

        // Incrementa contador diário
        $daily_submissions = get_option($this->daily_submissions_option, array());
        $daily_submissions[$today] = isset($daily_submissions[$today]) ? $daily_submissions[$today] + 1 : 1;
        update_option($this->daily_submissions_option, $daily_submissions);

        // Incrementa contador mensal
        $monthly_submissions = get_option($this->monthly_submissions_option, array());
        $monthly_submissions[$current_month] = isset($monthly_submissions[$current_month]) ?
            $monthly_submissions[$current_month] + 1 : 1;
        update_option($this->monthly_submissions_option, $monthly_submissions);

        error_log("✅ [DEBUG] Contadores incrementados - Daily: " . $daily_submissions[$today] .
            ", Monthly: " . $monthly_submissions[$current_month]);
    }

    private function save_failed_webhook($webhook_data, $webhook_url, $error_message)
    {
        $failed_webhooks = get_option($this->failed_webhooks_option, array());

        $failed_webhook = array(
            'webhook_id' => uniqid('webhook_'),
            'data' => $webhook_data,
            'url' => $webhook_url,
            'error' => $error_message,
            'created_at' => current_time('mysql'),
            'attempts' => 0,
            'max_attempts' => $this->max_webhook_attempts,
            'status' => 'pending'
        );

        $failed_webhooks[] = $failed_webhook;
        update_option($this->failed_webhooks_option, $failed_webhooks);

        $this->log("ðŸ’¾ Webhook salvo para retry posterior - ID: " . $failed_webhook['webhook_id']);
    }

    private function prepare_webhook_data($form_data, $vendedor)
    {
        // Copia dados do formulário
        $webhook_data = $form_data;

        // Adiciona informações do vendedor
        $webhook_data['vendedor_nome'] = $vendedor['nome'];
        $webhook_data['vendedor_telefone'] = $vendedor['telefone'];
        $webhook_data['vendedor_id'] = isset($vendedor['vendedor_id']) ? $vendedor['vendedor_id'] : ''; // NOVO CAMPO
        $webhook_data['grupo'] = isset($vendedor['grupo']) ? $vendedor['grupo'] : 'drv';
        $webhook_data['atendente'] = $vendedor['nome'];

        // Adiciona contagens
        $daily_submissions = get_option($this->daily_submissions_option, array());
        $monthly_submissions = get_option($this->monthly_submissions_option, array());
        $today = current_time('Y-m-d');
        $current_month = current_time('Y-m');

        $webhook_data['contagem_diaria'] = isset($daily_submissions[$today]) ?
            $daily_submissions[$today] : 0;
        $webhook_data['contagem_mensal'] = isset($monthly_submissions[$current_month]) ?
            $monthly_submissions[$current_month] : 0;

        // Formata dados específicos
        $webhook_data['nome'] = $form_data['name'];
        $webhook_data['quantidade_de_pessoas'] = $form_data['qtd_pessoas'];
        $webhook_data['tipo_de_plano'] = $form_data['qual_plano'];
        $webhook_data['idades'] = is_array($form_data['ages']) ?
            implode(', ', $form_data['ages']) : $form_data['ages'];

        return $webhook_data;
    }

    // VERSÃO SIMPLIFICADA - Agora apenas reagenda processamento
    public function process_failed_webhooks()
    {
        $this->log("ðŸ“‹ Reagendando processamento de webhooks falhos");

        // Apenas agenda o processamento da fila
        if (!wp_next_scheduled('hapvida_process_webhook_queue')) {
            wp_schedule_single_event(time() + 5, 'hapvida_process_webhook_queue');
        }

        return true;
    }

    private function send_definitive_failure_notification($webhook_data, $webhook_id, $total_attempts)
    {
        try {
            // Busca email configurado nas opções ou usa padrão
            $options = get_option($this->settings_option_name);
            $notification_email = isset($options['notification_email']) ? $options['notification_email'] : 'netoppcem@gmail.com';

            // Dados do cliente
            $cliente_nome = isset($webhook_data['nome']) ? $webhook_data['nome'] : 'N/A';
            $cliente_telefone = isset($webhook_data['telefone']) ? $webhook_data['telefone'] : 'N/A';
            $cliente_cidade = isset($webhook_data['cidade']) ? $webhook_data['cidade'] : 'N/A';
            $tipo_plano = isset($webhook_data['tipo_de_plano']) ? $webhook_data['tipo_de_plano'] : 'N/A';
            $qtd_pessoas = isset($webhook_data['quantidade_de_pessoas']) ? $webhook_data['quantidade_de_pessoas'] : 'N/A';
            $idades = isset($webhook_data['idades']) ? $webhook_data['idades'] : 'N/A';

            // Dados do vendedor
            $vendedor_nome = isset($webhook_data['atendente']) ? $webhook_data['atendente'] :
                (isset($webhook_data['vendedor_nome']) ? $webhook_data['vendedor_nome'] : 'N/A');
            $vendedor_telefone = isset($webhook_data['telefone_vendedor']) ? $webhook_data['telefone_vendedor'] :
                (isset($webhook_data['vendedor_telefone']) ? $webhook_data['vendedor_telefone'] : 'N/A');
            $grupo = isset($webhook_data['grupo']) ? strtoupper($webhook_data['grupo']) : 'N/A';

            // Data e hora
            $data_envio = isset($webhook_data['data_envio']) ? $webhook_data['data_envio'] : date('d-m-Y');
            $hora_submissao = isset($webhook_data['hora_submissao']) ? $webhook_data['hora_submissao'] : date('H:i:s');

            // Assunto do email
            $subject = "🚨 URGENTE: Lead PERDIDO após {$total_attempts} tentativas - {$cliente_nome} - {$cliente_telefone}";

            // Corpo do email em HTML
            $message = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                h1 { color: #dc3545; font-size: 24px; margin-bottom: 20px; }
                h2 { color: #333; font-size: 20px; margin-top: 30px; margin-bottom: 15px; border-bottom: 2px solid #dc3545; padding-bottom: 10px; }
                h3 { color: #666; font-size: 18px; margin-top: 20px; margin-bottom: 10px; }
                .alert { background-color: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 5px solid #dc3545; }
                .warning { background-color: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 5px solid #ffc107; }
                .info-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                .info-table th { background-color: #f8f9fa; padding: 10px; text-align: left; font-weight: bold; border-bottom: 2px solid #dee2e6; }
                .info-table td { padding: 10px; border-bottom: 1px solid #dee2e6; }
                .status-failed { color: #dc3545; font-weight: bold; }
                .urgent { background-color: #dc3545; color: white; padding: 20px; border-radius: 5px; margin: 20px 0; text-align: center; }
                .urgent strong { font-size: 18px; }
                .actions-list { background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin: 20px 0; }
                .actions-list ul { margin: 10px 0; padding-left: 20px; }
                .actions-list li { margin: 10px 0; }
                code { background-color: #f8f9fa; padding: 2px 5px; border-radius: 3px; font-family: monospace; }
                a { color: #007bff; text-decoration: none; }
                a:hover { text-decoration: underline; }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>🚨 FALHA CRÃTICA: Lead Perdido no Sistema Hapvida</h1>
                
                <div class="alert">
                    <strong>âš ï¸ ATENÇÃO URGENTE:</strong> O sistema tentou enviar este lead {$total_attempts} vezes sem sucesso. 
                    O cliente pode estar aguardando contato há mais de 30 minutos!
                </div>
                
                <h2>👤 Dados do Cliente (CONTATAR URGENTE)</h2>
                <table class="info-table">
                    <tr>
                        <th>Nome:</th>
                        <td><strong>' . esc_html($cliente_nome) . '</strong></td>
                    </tr>
                    <tr>
                        <th>Telefone:</th>
                        <td><strong style="font-size: 18px; color: #dc3545;">' . esc_html($cliente_telefone) . '</strong></td>
                    </tr>
                    <tr>
                        <th>Cidade:</th>
                        <td>' . esc_html($cliente_cidade) . '</td>
                    </tr>
                    <tr>
                        <th>Tipo de Plano:</th>
                        <td>' . esc_html($tipo_plano) . '</td>
                    </tr>
                    <tr>
                        <th>Quantidade de Pessoas:</th>
                        <td>' . esc_html($qtd_pessoas) . '</td>
                    </tr>
                    <tr>
                        <th>Idades:</th>
                        <td>' . esc_html($idades) . '</td>
                    </tr>
                </table>
                
                <h2>ðŸ‘¨â€ðŸ’¼ Vendedor Designado</h2>
                <table class="info-table">
                    <tr>
                        <th>Nome:</th>
                        <td><strong>' . esc_html($vendedor_nome) . '</strong></td>
                    </tr>
                    <tr>
                        <th>Telefone:</th>
                        <td><strong>' . esc_html($vendedor_telefone) . '</strong></td>
                    </tr>
                    <tr>
                        <th>Grupo:</th>
                        <td><strong>' . esc_html($grupo) . '</strong></td>
                    </tr>
                    <tr>
                        <th>Data/Hora da Submissão:</th>
                        <td>' . esc_html($data_envio) . ' Ã s ' . esc_html($hora_submissao) . '</td>
                    </tr>
                </table>
                
                <h3>ðŸ”§ Informações Técnicas</h3>
                <table class="info-table">
                    <tr>
                        <th>ID do Webhook:</th>
                        <td><code>' . esc_html($webhook_id) . '</code></td>
                    </tr>
                    <tr>
                        <th>Lead ID:</th>
                        <td><code>' . esc_html($webhook_data['lead_id'] ?? 'N/A') . '</code></td>
                    </tr>
                    <tr>
                        <th>Tentativas Realizadas:</th>
                        <td><span class="status-failed">' . $total_attempts . ' / ' . $total_attempts . '</span></td>
                    </tr>
                    <tr>
                        <th>Status Final:</th>
                        <td><span class="status-failed">âŒ FALHOU DEFINITIVAMENTE</span></td>
                    </tr>
                    <tr>
                        <th>Data/Hora da Falha Final:</th>
                        <td>' . date('d/m/Y H:i:s') . ' (Horário de Brasília)</td>
                    </tr>
                    <tr>
                        <th>Site:</th>
                        <td><a href="' . get_site_url() . '">' . get_site_url() . '</a></td>
                    </tr>
                </table>
                
                <div class="urgent">
                    <strong>ðŸ“ž AÇÃO IMEDIATA NECESSÃRIA!</strong><br>
                    Este cliente demonstrou interesse e está aguardando contato.<br>
                    <strong>LIGUE AGORA: ' . esc_html($cliente_telefone) . '</strong>
                </div>
                
                <div class="actions-list">
                    <h3>ðŸ“‹ AÇÃ•ES NECESSÃRIAS:</h3>
                    <ul>
                        <li><strong>1. CONTATO IMEDIATO:</strong> Ligue para <strong>' . esc_html($cliente_telefone) . '</strong> agora mesmo</li>
                        <li><strong>2. WhatsApp Direto:</strong> <a href="https://wa.me/' . preg_replace('/[^0-9]/', '', $cliente_telefone) . '?text=Olá ' . urlencode($cliente_nome) . ', sou ' . urlencode($vendedor_nome) . ' da Hapvida. Vi que você demonstrou interesse em nossos planos. Posso ajudar?" target="_blank">Clique aqui para abrir WhatsApp com mensagem pronta</a></li>
                        <li><strong>3. Notificar Vendedor:</strong> Entre em contato com ' . esc_html($vendedor_nome) . ' no telefone ' . esc_html($vendedor_telefone) . '</li>
                        <li><strong>4. Verificar Sistema:</strong> Teste manualmente o webhook do grupo ' . esc_html($grupo) . '</li>
                        <li><strong>5. Painel Admin:</strong> <a href="' . admin_url('options-general.php?page=formulario-hapvida-admin') . '">Acessar painel para verificar outros leads pendentes</a></li>
                    </ul>
                </div>
                
                <div class="warning">
                    <strong>ðŸ’¡ IMPORTANTE:</strong> Este email indica uma falha crítica no sistema. 
                    O webhook falhou completamente após múltiplas tentativas. É essencial:
                    <ul>
                        <li>Contatar o cliente imediatamente</li>
                        <li>Verificar a configuração do webhook</li>
                        <li>Testar a conectividade com o servidor de destino</li>
                        <li>Verificar se há outros leads com o mesmo problema</li>
                    </ul>
                </div>
                
                <p style="text-align: center; color: #666; margin-top: 30px;">
                    Este é um email automático do sistema Formulário Hapvida.<br>
                    Gerado em: ' . date('d/m/Y H:i:s') . ' (Horário de Brasília)
                </p>
            </div>
        </body>
        </html>';

            // Headers para email HTML
            $headers = array(
                'Content-Type: text/html; charset=UTF-8',
                'From: Sistema Hapvida <' . get_option('admin_email') . '>',
                'Reply-To: ' . get_option('admin_email')
            );

            // Envia o email
            $email_sent = wp_mail($notification_email, $subject, $message, $headers);

            if ($email_sent) {
                $this->log("ðŸ“§ Email de falha definitiva enviado para: {$notification_email}");
            } else {
                $this->log("âŒ ERRO ao enviar email de falha definitiva");
                error_log("HAPVIDA CRITICAL: Falha ao enviar email de notificação para {$notification_email}");
            }

            // Log adicional no erro_log para garantir visibilidade
            error_log("HAPVIDA CRITICAL: Lead PERDIDO - Cliente: {$cliente_nome}, Tel: {$cliente_telefone}, Vendedor: {$vendedor_nome}");

        } catch (Exception $e) {
            $this->log("âŒ ERRO CRÃTICO ao enviar notificação de falha definitiva: " . $e->getMessage());
            error_log("HAPVIDA CRITICAL ERROR: " . $e->getMessage());
        }
    }

    private function cleanup_old_webhooks()
    {
        try {
            $failed_webhooks = get_option($this->failed_webhooks_option, array());

            if (empty($failed_webhooks)) {
                return;
            }

            $original_count = count($failed_webhooks);
            $cutoff_time = strtotime('-24 hours');
            $cleaned_webhooks = array();
            $removed_count = 0;

            foreach ($failed_webhooks as $webhook) {
                $created_at = isset($webhook['created_at']) ? strtotime($webhook['created_at']) : 0;

                // Mantém apenas webhooks das últimas 24 horas que ainda estão pendentes
                if ($created_at > $cutoff_time && $webhook['status'] === 'pending') {
                    $cleaned_webhooks[] = $webhook;
                } else if ($webhook['status'] === 'pending') {
                    // Webhook muito antigo e ainda pendente - marca como falhou
                    $this->log("ðŸ—‘ï¸ Removendo webhook antigo: ID {$webhook['webhook_id']} - Cliente: " .
                        ($webhook['data']['nome'] ?? 'N/A'));
                    $removed_count++;

                    // Envia notificação final se ainda não foi enviada
                    if (isset($webhook['data']) && !isset($webhook['final_notification_sent'])) {
                        $this->send_definitive_failure_notification(
                            $webhook['data'],
                            $webhook['webhook_id'],
                            $webhook['max_attempts']
                        );
                    }
                }
            }

            if ($removed_count > 0) {
                update_option($this->failed_webhooks_option, $cleaned_webhooks);
                $this->log("ðŸ§¹ Limpeza concluída: {$removed_count} webhooks antigos removidos");
                error_log("HAPVIDA: Limpeza de webhooks - {$removed_count} removidos de {$original_count} total");
            }

        } catch (Exception $e) {
            $this->log("âŒ Erro na limpeza de webhooks: " . $e->getMessage());
        }
    }

    private function should_retry_webhook($response, $response_body = '')
    {
        // Se é um WP_Error, verifica mensagens específicas
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $error_code = $response->get_error_code();

            $this->log("ðŸ” Analisando erro WP_Error - Código: {$error_code}, Mensagem: {$error_message}");

            // *** LISTA EXPANDIDA: Padrões de erro que devem acionar retry ***
            $retry_patterns = array(
                // Timeouts gerais
                'timed out',
                'timeout',
                'operation timed out',
                'exceeded the allotted timeout',
                'connect timed out',
                'read timed out',
                'gateway time-out',
                'request timeout',

                // *** NOVO: Erro 28 específico (CURLE_OPERATION_TIMEDOUT) ***
                'error 28',
                'cURL error 28',
                'resolving timed out after \d+ milliseconds',
                'curle_operation_timedout',
                'curle_operation_timeout',
                'operation_timedout',
                'CURLE_OPERATION_TIMEDOUT',

                // Conexão e rede
                'connection reset',
                'connection refused',
                'could not resolve host',
                'ssl connection timeout',
                'connection timed out',
                'network is unreachable',
                'connection aborted',
                'broken pipe',
                'no route to host',
                'connection closed',

                // MongoDB e outros serviços específicos
                'mongodb.*exception.*socket',
                'mongoSocketOpenException',
                'SocketTimeoutException',
                'sheets.googleapis.com.*timeout',

                // Erros temporários de servidor
                'service unavailable',
                'bad gateway',
                'gateway timeout',
                '502 bad gateway',
                '503 service unavailable',
                '504 gateway timeout',
                'upstream timed out',
                'cloudflare.*timeout',

                // Erros de rate limit
                'rate limit',
                'too many requests',
                '429 too many requests',

                // Erros de DNS
                'dns.*timeout',
                'could not resolve',
                'name resolution',

                // Erros SSL temporários
                'ssl.*timeout',
                'tls.*timeout',
                'handshake.*timeout'
            );

            // Verifica cada padrão
            foreach ($retry_patterns as $pattern) {
                if (preg_match('/' . $pattern . '/i', $error_message)) {
                    $this->log("✅ Erro identificado como temporário: corresponde ao padrão '{$pattern}'");
                    return true;
                }
            }

            // *** NOVO: Verifica especificamente o erro 28 no código ***
            if (strpos($error_message, '28') !== false || $error_code === 'http_request_failed') {
                $this->log("✅ Possível erro 28 detectado - permitindo retry");
                return true;
            }

            $this->log("âŒ Erro não identificado como temporário - NÃO será feito retry");
            return false;
        }

        // Se não é WP_Error mas tem response_code
        $response_code = wp_remote_retrieve_response_code($response);

        if ($response_code) {
            $this->log("ðŸ” Analisando código HTTP: {$response_code}");

            // Lista de códigos HTTP que devem acionar retry
            $retry_codes = array(
                408, // Request Timeout
                429, // Too Many Requests
                500, // Internal Server Error
                502, // Bad Gateway
                503, // Service Unavailable
                504, // Gateway Timeout
                520, // Cloudflare: Unknown Error
                521, // Cloudflare: Web Server Is Down
                522, // Cloudflare: Connection Timed Out
                523, // Cloudflare: Origin Is Unreachable
                524, // Cloudflare: A Timeout Occurred
                525, // Cloudflare: SSL Handshake Failed
                526, // Cloudflare: Invalid SSL Certificate
                527, // Cloudflare: Railgun Error
            );

            if (in_array($response_code, $retry_codes)) {
                $this->log("✅ Código HTTP {$response_code} identificado como temporário - retry permitido");
                return true;
            }

            // Verifica o corpo da resposta para mensagens de erro temporário
            if (!empty($response_body)) {
                $temp_error_patterns = array(
                    'temporarily unavailable',
                    'try again later',
                    'service is busy',
                    'under maintenance',
                    'rate limit exceeded',
                    'quota exceeded',
                    'too many connections'
                );

                foreach ($temp_error_patterns as $pattern) {
                    if (stripos($response_body, $pattern) !== false) {
                        $this->log("✅ Mensagem de erro temporário detectada no corpo: '{$pattern}'");
                        return true;
                    }
                }
            }

            // Códigos definitivos que NÃO devem ter retry
            $no_retry_codes = array(
                400, // Bad Request
                401, // Unauthorized
                403, // Forbidden
                404, // Not Found
                405, // Method Not Allowed
                406, // Not Acceptable
                409, // Conflict
                410, // Gone
                422, // Unprocessable Entity
            );

            if (in_array($response_code, $no_retry_codes)) {
                $this->log("âŒ Código HTTP {$response_code} é definitivo - NÃO será feito retry");
                return false;
            }
        }

        // Por padrão, não faz retry
        $this->log("âš ï¸ Caso não identificado - por segurança, NÃO será feito retry");
        return false;
    }

    private function get_webhook_timeout_config($is_retry = false)
    {
        // *** REDUÇÃO SIGNIFICATIVA DOS TIMEOUTS ***
        $base_timeout = $is_retry ? 15 : 10; // Reduzido de 75/60 para 15/10

        // Configuração otimizada
        return array(
            'timeout' => $base_timeout,
            'httpversion' => '1.1',
            'redirection' => 2,
            'blocking' => true, // Será false quando usado em processamento assíncrono
            'sslverify' => false,
            'compress' => true, // Habilita compressão para respostas mais rápidas
            'stream' => false,
            'decompress' => true,
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'Formulario-Hapvida/2.0-Optimized',
                'Connection' => 'close',
                'Accept' => 'application/json',
                'Cache-Control' => 'no-cache'
            )
        );
    }

    /**
     * Processa fila de webhooks de forma assíncrona
     */
    public function process_webhook_queue_async()
    {
        // Verifica nonce se chamado via AJAX
        if (defined('DOING_AJAX') && DOING_AJAX) {
            check_ajax_referer('hapvida_async_webhook', 'security');
        }

        // Define limite de tempo para processamento
        @set_time_limit(30);

        $queue = get_option('hapvida_webhook_queue', array());
        if (empty($queue)) {
            return;
        }

        $processed = array();
        $failed = array();
        $max_process = 5; // Processa até 5 webhooks por vez

        foreach ($queue as $index => $webhook_data) {
            if ($index >= $max_process) {
                break;
            }

            $form_data = $webhook_data['form_data'];
            $vendedor = $webhook_data['vendedor'];

            // Determina se deve usar tracking baseado em configurações
            $options = get_option($this->settings_option_name);
            $is_business_hours = $this->is_business_hours();
            $enable_redistributions = isset($options['enable_lead_redistribution']) &&
                $options['enable_lead_redistribution'] === 'yes';

            if ($enable_redistributions && $is_business_hours) {
                $success = $this->send_to_webhook_with_tracking($form_data, $vendedor);
            } else {
                $success = $this->send_to_webhook_without_tracking($form_data, $vendedor);
            }

            if ($success) {
                $processed[] = $index;
            } else {
                // Mantém para retry posterior
                $webhook_data['retry_count'] = isset($webhook_data['retry_count']) ?
                    $webhook_data['retry_count'] + 1 : 1;
                if ($webhook_data['retry_count'] < 3) {
                    $failed[] = $webhook_data;
                }
            }
        }

        // Remove processados e atualiza fila
        foreach (array_reverse($processed) as $index) {
            unset($queue[$index]);
        }

        // Adiciona falhas de volta Ã  fila para retry
        $queue = array_merge($failed, array_values($queue));

        update_option('hapvida_webhook_queue', $queue);

        // Se ainda há itens na fila, agenda próximo processamento
        if (!empty($queue)) {
            wp_schedule_single_event(time() + 10, 'hapvida_process_webhook_queue');
        }

        $this->log("✅ Processamento assíncrono: " . count($processed) . " webhooks enviados, " .
            count($failed) . " para retry");

        if (defined('DOING_AJAX') && DOING_AJAX) {
            wp_die();
        }
    }

    // VERSÃO SIMPLIFICADA
    private function send_to_webhook_without_tracking($form_data, $vendedor)
    {
        try {
            // Prepara dados
            $webhook_data = $this->prepare_webhook_data($form_data, $vendedor);

            // Enfileira para processamento
            $this->save_webhook_entry($webhook_data, 'pending', 'Aguardando processamento');

            return true; // Sempre retorna true pois foi enfileirado

        } catch (Exception $e) {
            $this->log("âš ï¸ Erro ao enfileirar: " . $e->getMessage());
            return false;
        }
    }


    /**
     * *** NOVO: AJAX para retry manual de webhooks (para usar na admin) ***
     */
    public function ajax_retry_failed_webhooks()
    {
        check_ajax_referer('retry_webhooks_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        $this->process_failed_webhooks();

        $failed_webhooks = get_option($this->failed_webhooks_option, array());
        $pending_count = count(array_filter($failed_webhooks, function ($w) {
            return $w['status'] === 'pending';
        }));

        wp_send_json_success(array(
            'message' => 'Processo de retry executado com sucesso',
            'pending_webhooks' => $pending_count
        ));
    }


    private function unmark_form_as_processed($form_data)
    {
        $telefone = isset($form_data['telefone']) ? $form_data['telefone'] : '';
        if (!empty($telefone)) {
            $telefone_clean = preg_replace('/[^0-9]/', '', $telefone);
            if (!empty($telefone_clean)) {
                $processed_key = 'processed_phone_' . md5($telefone_clean);
                delete_transient($processed_key);
                $this->log("ðŸ”“ Marcação de processado removida para telefone: " . $telefone);
            }
        }
    }


    public function cleanup_expired_submissions()
    {
        global $wpdb;

        // Limpa transients expirados relacionados a submissões
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE %s 
             AND option_name LIKE %s",
                '_transient_timeout_processed_phone_%',
                '%%'
            )
        );

        $this->log("ðŸ§¹ Limpeza de submissões expiradas executada");
    }


    private function is_form_already_submitted($form_data)
    {
        $telefone = isset($form_data['telefone']) ? $form_data['telefone'] : '';
        if (empty($telefone)) {
            return false; // Sem telefone, não tem como verificar
        }

        // Normaliza o telefone (remove formatação)
        $telefone_clean = preg_replace('/[^0-9]/', '', $telefone);

        if (empty($telefone_clean)) {
            return false;
        }

        // Verificação por telefone
        $processed_key = 'processed_phone_' . md5($telefone_clean);
        $processed = get_transient($processed_key);

        if ($processed) {
            $this->log("âš ï¸ Verificação duplicação: Telefone {$telefone} já foi processado recentemente");
            return true;
        }

        $this->log("✅ Verificação duplicação: Telefone {$telefone} OK para nova submissão");
        return false;
    }


    private function get_user_ip()
    {
        $ip_headers = array(
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );

        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];

                // Se houver múltiplos IPs (comum em X-Forwarded-For), pega o primeiro
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }

                // Valida o IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        // Fallback para REMOTE_ADDR
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function generate_whatsapp_url($form_data, $vendedor)
    {
        // Número do vendedor sem formatação
        $vendedor_phone = preg_replace('/[^0-9]/', '', $vendedor['telefone']);

        // Se não tem código do país, adiciona +55
        if (!str_starts_with($vendedor_phone, '55')) {
            $vendedor_phone = '55' . $vendedor_phone;
        }

        // Monta mensagem para WhatsApp
        $message = "Olá! Meu nome é *{$form_data['name']}*.\n\n";
        $message .= "Gostaria de informações sobre o plano *{$form_data['qual_plano']}*.\n\n";
        $message .= "Cidade: {$form_data['cidade']}\n";
        $message .= "Quantidade de pessoas: {$form_data['qtd_pessoas']}\n";

        if (!empty($form_data['ages'])) {
            $ages_text = is_array($form_data['ages']) ? implode(', ', $form_data['ages']) : $form_data['ages'];
            $message .= "dades: {$ages_text}\n";
        }

        $message .= "\n Meu telefone: {$form_data['telefone']}\n";
        $message .= "Data do contato: {$form_data['data']} as {$form_data['hora']}\n";
        $message .= "\n Id do Lead: {$form_data['lead_id']}";

        // URL do WhatsApp
        $whatsapp_url = 'https://wa.me/' . $vendedor_phone . '?text=' . urlencode($message);

        $this->log("WhatsApp URL gerada para vendedor: {$vendedor['nome']}");

        return $whatsapp_url;
    }

    private function validate_brazilian_phone($telefone)
    {
        // Remove todos os caracteres não numéricos
        $clean_phone = preg_replace('/[^0-9]/', '', $telefone);

        $result = array(
            'valid' => false,
            'clean' => $clean_phone,
            'formatted' => $telefone,
            'type' => null,
            'error_message' => ''
        );

        $length = strlen($clean_phone);

        // Aceita 10 ou 11 dígitos
        if ($length >= 10 && $length <= 11) {
            $ddd = substr($clean_phone, 0, 2);

            // DDD deve estar entre 11 e 99
            if (intval($ddd) >= 11 && intval($ddd) <= 99) {
                $result['valid'] = true;

                if ($length === 10) {
                    $result['type'] = 'residencial';
                    $result['formatted'] = sprintf(
                        '(%s) %s-%s',
                        substr($clean_phone, 0, 2),
                        substr($clean_phone, 2, 4),
                        substr($clean_phone, 6)
                    );
                } else { // 11 dígitos
                    $result['type'] = 'celular';
                    $result['formatted'] = sprintf(
                        '(%s) %s-%s',
                        substr($clean_phone, 0, 2),
                        substr($clean_phone, 2, 5),
                        substr($clean_phone, 7)
                    );
                }
            } else {
                $result['error_message'] = 'DDD inválido. Use um DDD entre 11 e 99. Ex: (11) 1234-5678';
            }
        } elseif ($length === 0) {
            $result['error_message'] = 'Por favor, digite seu número de telefone com DDD';
        } elseif ($length < 10) {
            $result['error_message'] = sprintf(
                'Número muito curto (%d dígitos). Digite DDD + número (mínimo 10 dígitos)',
                $length
            );
        } elseif ($length > 11) {
            $result['error_message'] = sprintf(
                'Número muito longo (%d dígitos). Máximo: 11 dígitos com DDD',
                $length
            );
        } else {
            $result['error_message'] = 'Formato inválido. Use: (DD) XXXX-XXXX';
        }

        return $result;
    }

    public function test_phone_validation()
    {
        $test_phones = array(
            '11999887766',           // Válido - celular
            '(11) 99988-7766',       // Válido - celular formatado
            '11987654321',           // Válido - celular
            '1133334444',            // Válido - fixo
            '(11) 3333-4444',        // Válido - fixo formatado
            '119988776',             // Inválido - muito curto
            '21987654321',           // Válido - RJ celular
            '11887654321',           // Inválido - fixo com 11 dígitos
            '11099887766',           // Inválido - começa com 0
            '11199887766',           // Inválido - começa com 1
            '09987654321',           // Inválido - DDD inválido
            '119999999999',          // Inválido - muito longo
        );

        $this->log("=== TESTE DE VALIDAÇÃO DE TELEFONES ===");

        foreach ($test_phones as $phone) {
            $result = $this->validate_brazilian_phone($phone);
            $status = $result['valid'] ? '✅ VÃLIDO' : 'âŒ INVÃLIDO';
            $message = $result['valid'] ?
                "Tipo: {$result['type']}, Formatado: {$result['formatted']}" :
                "Erro: {$result['error_message']}";

            $this->log("Teste: {$phone} -> {$status} - {$message}");
        }
    }

    private function get_next_vendedor($cidade = '', $pagina_origem = '')
    {
        // *** NOVA LÓGICA: Verifica se a página tem consultor específico ***
        if (!empty($pagina_origem)) {
            $vendedor_especifico = $this->get_vendedor_por_url($pagina_origem);
            if ($vendedor_especifico) {
                return $vendedor_especifico;
            }
        }

        // *** NOVA LÓGICA: Verifica se a cidade tem vendedor específico ***
        if (!empty($cidade)) {
            $vendedor_especifico = $this->get_vendedor_por_cidade($cidade);
            if ($vendedor_especifico) {
                return $vendedor_especifico;
            }
        }

        // Verifica se as configurações foram carregadas corretamente
        if (method_exists($this, 'load_timeout_settings')) {
            $this->load_timeout_settings();
        }

        // Recupera as infos do último vendedor com estrutura correta
        $ultimo_vendedor_info = get_option($this->ultimo_vendedor_option_name, array(
            'group' => '',
            'indices' => array('drv' => -1, 'seu_souza' => -1),
        ));

        // Usa o nome correto da opção de vendedores
        $vendedores = get_option($this->vendedores_option, array('drv' => array(), 'seu_souza' => array()));

        // Verifica se a estrutura de vendedores está correta
        if (!is_array($vendedores) || (!isset($vendedores['drv']) && !isset($vendedores['seu_souza']))) {
            $this->log("ERRO: Estrutura de vendedores invalida");
            return null;
        }

        // Filtra apenas vendedores ativos com verificação robusta
        $vendedores_ativos = array();
        foreach ($vendedores as $grupo => $vendedores_grupo) {
            if (!is_array($vendedores_grupo)) {
                $vendedores_ativos[$grupo] = array();
                continue;
            }

            $vendedores_ativos[$grupo] = array_filter($vendedores_grupo, function ($vendedor) {
                // VERIFICAÇÃO: Considera apenas vendedores ATIVOS
                return is_array($vendedor) &&
                    isset($vendedor['nome']) &&
                    !empty($vendedor['nome']) &&
                    (!isset($vendedor['status']) || $vendedor['status'] === 'ativo');
            });

            // IMPORTANTE: Reindexar arrays após filtrar
            $vendedores_ativos[$grupo] = array_values($vendedores_ativos[$grupo]);
        }

        $count_drv = count($vendedores_ativos['drv']);
        $count_seu_souza = count($vendedores_ativos['seu_souza']);
        $this->log("Total de vendedores ATIVOS: DRV={$count_drv}, Seu Souza={$count_seu_souza}");

        // Se não houver nenhum ativo, retorna null
        if ($count_drv === 0 && $count_seu_souza === 0) {
            $this->log("ERRO: Nenhum vendedor ATIVO cadastrado no sistema");
            return null;
        }

        $ultimo_grupo = isset($ultimo_vendedor_info['group']) ? $ultimo_vendedor_info['group'] : '';
        $this->log("Ultimo grupo utilizado: '{$ultimo_grupo}'");
        $this->log("Indices atuais - DRV: {$ultimo_vendedor_info['indices']['drv']}, Seu Souza: {$ultimo_vendedor_info['indices']['seu_souza']}");

        // Lógica de alternância mais robusta
        $proximo_grupo = ($ultimo_grupo === 'drv') ? 'seu_souza' : 'drv';

        // Verifica se o próximo grupo tem vendedores ativos
        if (count($vendedores_ativos[$proximo_grupo]) === 0) {
            // Se não tem, usa o outro grupo
            $proximo_grupo = ($proximo_grupo === 'drv') ? 'seu_souza' : 'drv';
            $this->log("Grupo alterado para {$proximo_grupo} por falta de vendedores no grupo anterior");
        }

        // Se ainda assim não tem vendedores, retorna null
        if (count($vendedores_ativos[$proximo_grupo]) === 0) {
            $this->log("ERRO: Nenhum vendedor ativo disponivel em nenhum grupo");
            return null;
        }

        // Obtém o índice do último vendedor usado do grupo
        $ultimo_indice = isset($ultimo_vendedor_info['indices'][$proximo_grupo]) ?
            $ultimo_vendedor_info['indices'][$proximo_grupo] : -1;

        $this->log("Ultimo indice do grupo {$proximo_grupo}: {$ultimo_indice}");

        // Calcula o próximo índice
        $proximo_indice = ($ultimo_indice + 1) % count($vendedores_ativos[$proximo_grupo]);

        $this->log("Proximo indice calculado: {$proximo_indice}");

        // Obtém o vendedor
        $vendedor = $vendedores_ativos[$proximo_grupo][$proximo_indice];

        // IMPORTANTE: Adiciona o grupo ao vendedor e garante que o ID está presente
        $vendedor['grupo'] = $proximo_grupo;

        // Garante que o vendedor_id existe no retorno
        if (!isset($vendedor['vendedor_id'])) {
            $vendedor['vendedor_id'] = ''; // Define como vazio se não existir
            $this->log("Vendedor sem ID definido: {$vendedor['nome']}");
        } else {
            $this->log("Vendedor com ID: {$vendedor['vendedor_id']}");
        }

        // CORREÇÃO CRÃTICA: ATUALIZA E SALVA OS NOVOS ÃNDICES
        $ultimo_vendedor_info['group'] = $proximo_grupo;
        $ultimo_vendedor_info['indices'][$proximo_grupo] = $proximo_indice;

        // SALVA NO BANCO DE DADOS
        update_option($this->ultimo_vendedor_option_name, $ultimo_vendedor_info);

        $this->log("Indices atualizados e salvos - Grupo: {$proximo_grupo}, Indice: {$proximo_indice}");
        $this->log("Vendedor selecionado: {$vendedor['nome']} (Grupo: {$proximo_grupo}, ID: {$vendedor['vendedor_id']})");

        return $vendedor;
    }

    // ===========================================================================
// NOVA FUNÇÃO: get_vendedor_por_cidade
// ADICIONAR após a função get_next_vendedor
// ===========================================================================

    private function get_vendedor_por_cidade($cidade)
    {
        // Remove acentos e converte para minúsculas para comparação
        $cidade_normalizada = $this->normalizar_cidade($cidade);

        // Busca as configurações de vendedores por cidade
        $city_vendors = get_option($this->city_vendors_option, array());

        if (empty($city_vendors) || !is_array($city_vendors)) {
            return null;
        }

        // Procura por uma configuração para esta cidade
        foreach ($city_vendors as $config) {
            if (!isset($config['cidade']) || !isset($config['vendedor_grupo']) || !isset($config['vendedor_index'])) {
                continue;
            }

            $cidade_config_normalizada = $this->normalizar_cidade($config['cidade']);

            if ($cidade_config_normalizada === $cidade_normalizada) {
                // Encontrou! Busca o vendedor específico
                $vendedores = get_option($this->vendedores_option, array('drv' => array(), 'seu_souza' => array()));
                $grupo = $config['vendedor_grupo'];
                $index = $config['vendedor_index'];

                if (isset($vendedores[$grupo][$index])) {
                    $vendedor = $vendedores[$grupo][$index];

                    // Verifica se o vendedor está ativo
                    if (isset($vendedor['status']) && $vendedor['status'] !== 'ativo') {
                        $this->log("Vendedor especifico para {$cidade} esta inativo");
                        return null;
                    }

                    // Adiciona informações do grupo
                    $vendedor['grupo'] = $grupo;

                    // Garante que o vendedor_id existe
                    if (!isset($vendedor['vendedor_id'])) {
                        $vendedor['vendedor_id'] = '';
                    }

                    $this->log("Vendedor especifico para {$cidade}: {$vendedor['nome']} (Grupo: {$grupo})");
                    return $vendedor;
                }
            }
        }

        return null;
    }

    // ===========================================================================
// NOVA FUNÇÃO: normalizar_cidade
// ADICIONAR após a função get_vendedor_por_cidade
// ===========================================================================

    private function normalizar_cidade($cidade)
    {
        // Remove acentos
        $cidade = remove_accents($cidade);
        // Converte para minúsculas
        $cidade = strtolower($cidade);
        // Remove espaços extras
        $cidade = trim($cidade);
        // Remove caracteres especiais
        $cidade = preg_replace('/[^a-z0-9\s]/', '', $cidade);

        return $cidade;
    }

    // ===========================================================================
// NOVA FUNÇÃO: get_vendedor_por_url
// Retorna vendedor específico baseado na URL de origem
// ===========================================================================

    private function get_vendedor_por_url($pagina_origem)
    {
        // Normaliza a URL para comparação
        $url_normalizada = $this->normalizar_url($pagina_origem);

        // Busca as configurações de URLs de consultores
        $url_consultores = get_option($this->url_consultores_option, array());

        if (empty($url_consultores) || !is_array($url_consultores)) {
            return null;
        }

        // Procura por uma configuração para esta URL
        foreach ($url_consultores as $config) {
            if (!isset($config['url']) || !isset($config['vendedor_numero'])) {
                continue;
            }

            $url_config_normalizada = $this->normalizar_url($config['url']);

            // Verifica se a URL de origem contém a URL configurada
            if (strpos($url_normalizada, $url_config_normalizada) !== false) {
                // Encontrou! Busca o vendedor pelo número
                $vendedor = $this->get_vendedor_por_numero($config['vendedor_numero']);

                if ($vendedor) {
                    return $vendedor;
                }
            }
        }

        return null;
    }

    // ===========================================================================
// NOVA FUNÇÃO: normalizar_url
// Normaliza URL para comparação
// ===========================================================================

    private function normalizar_url($url)
    {
        // Remove protocolo
        $url = preg_replace('/^https?:\/\//', '', $url);
        // Remove www
        $url = preg_replace('/^www\./', '', $url);
        // Converte para minúsculas
        $url = strtolower($url);
        // Remove barra final
        $url = rtrim($url, '/');

        return $url;
    }

    // ===========================================================================
// NOVA FUNÇÃO: get_vendedor_por_numero
// Retorna vendedor específico baseado no número de telefone
// ===========================================================================

    private function get_vendedor_por_numero($numero)
    {
        // Remove caracteres especiais do número para comparação
        $numero_limpo = preg_replace('/[^0-9]/', '', $numero);

        // Busca todos os vendedores
        $vendedores = get_option($this->vendedores_option, array('drv' => array(), 'seu_souza' => array()));

        // Procura em ambos os grupos
        foreach ($vendedores as $grupo => $vendedores_grupo) {
            if (!is_array($vendedores_grupo)) {
                continue;
            }

            foreach ($vendedores_grupo as $vendedor) {
                if (!is_array($vendedor)) {
                    continue;
                }

                // Tenta pegar o número de diferentes campos possíveis
                $vendedor_numero = '';
                if (isset($vendedor['numero']) && !empty($vendedor['numero'])) {
                    $vendedor_numero = $vendedor['numero'];
                } elseif (isset($vendedor['telefone']) && !empty($vendedor['telefone'])) {
                    $vendedor_numero = $vendedor['telefone'];
                } else {
                    continue;
                }

                // Limpa o número do vendedor
                $vendedor_numero_limpo = preg_replace('/[^0-9]/', '', $vendedor_numero);

                // Compara os números
                if ($vendedor_numero_limpo === $numero_limpo) {
                    // Verifica se o vendedor está ativo
                    if (isset($vendedor['status']) && $vendedor['status'] !== 'ativo') {
                        return null;
                    }

                    // Adiciona informações do grupo
                    $vendedor['grupo'] = $grupo;

                    // Garante que o vendedor_id existe
                    if (!isset($vendedor['vendedor_id'])) {
                        $vendedor['vendedor_id'] = '';
                    }

                    return $vendedor;
                }
            }
        }

        return null;
    }

    public function generate_unique_lead_id()
    {
        // Busca o último ID usado
        $last_id_option = 'formulario_hapvida_last_lead_id';
        $last_id = get_option($last_id_option, 0);

        // Incrementa para o próximo ID
        $next_id = $last_id + 1;

        // Garante que tenha 5 dígitos
        $id_number = str_pad($next_id, 5, '0', STR_PAD_LEFT);

        // Cria o ID final no formato P3-XXXXX
        $lead_id = 'P3-' . $id_number;

        // Salva o último ID usado
        update_option($last_id_option, $next_id);

        // LOG DETALHADO
        $this->log("ðŸ†” [CORREÇÃO] ID único gerado: {$lead_id} (último ID era: {$last_id})");
        error_log("HAPVIDA DEBUG: ID único gerado - {$lead_id}");

        return $lead_id;
    }

    private function get_dynamic_timeout()
    {
        $options = get_option($this->settings_option_name);

        // Verifica se é horário comercial
        if ($this->is_business_hours()) {
            return isset($options['redistribution_timeout_weekdays']) ?
                intval($options['redistribution_timeout_weekdays']) : 10;
        } else {
            return isset($options['redistribution_timeout_weekends']) ?
                intval($options['redistribution_timeout_weekends']) : 30;
        }
    }

    private function load_timeout_settings()
    {
        $options = get_option($this->settings_option_name);

        // Define timeouts com base nas opções ou usa padrões
        $this->business_hours_timeout = isset($options['redistribution_timeout']) ? intval($options['redistribution_timeout']) : 10;
        $this->after_hours_timeout = isset($options['redistribution_timeout_after_hours']) ? intval($options['redistribution_timeout_after_hours']) : 30;

        $this->log("Timeouts carregados - Comercial: {$this->business_hours_timeout}min, Fora do horário: {$this->after_hours_timeout}min");
    }

    private function send_to_webhook($form_data, $vendedor)
    {
        $options = get_option($this->settings_option_name);

        if (!isset($form_data['lead_id'])) {
            $form_data['lead_id'] = $this->generate_unique_lead_id();
        }

        $this->log("ðŸ“¤ [CORREÇÃO] Enviando para webhook - Lead ID: {$form_data['lead_id']}, Origem: " . $form_data['pagina_origem']);

        // Descobre grupo do vendedor
        $grupo = isset($vendedor['grupo']) ? $vendedor['grupo'] : $this->discover_group_from_phone($vendedor['telefone']);

        // Define URL do webhook baseada no grupo
        if ($grupo === 'seu_souza') {
            $webhook_url = isset($options['webhook_url_seu_souza']) ? $options['webhook_url_seu_souza'] : '';
        } else {
            $webhook_url = isset($options['webhook_url_drv']) ? $options['webhook_url_drv'] : '';
        }

        if (empty($webhook_url)) {
            $this->log("âŒ URL do webhook não configurada para o grupo: {$grupo}");
            return false;
        }

        // *** CORREÇÃO DO TIMEZONE - SEMPRE USA current_time() DO WORDPRESS ***
        $data_envio = current_time('d-m-Y');
        $hora_envio = current_time('H:i:s');

        // *** BUSCA CONTAGENS ATUAIS USANDO current_time ***
        $today = current_time('Y-m-d');
        $current_month = current_time('Y-m');

        // Obtém contagens das opções do WordPress
        $daily_submissions = get_option('formulario_hapvida_daily_submissions', array());
        $monthly_submissions = get_option('formulario_hapvida_monthly_submissions', array());

        $contagem_diaria = isset($daily_submissions[$today]) ? $daily_submissions[$today] : 0;
        $contagem_mensal = isset($monthly_submissions[$current_month]) ? $monthly_submissions[$current_month] : 0;

        $this->log("ðŸ“Š [CORREÇÃO] Contagens obtidas - Diária: {$contagem_diaria}, Mensal: {$contagem_mensal}");

        // Prepara dados para envio
        $filtered_form_data = array(
            'lead_id' => $form_data['lead_id'],
            'nome' => $form_data['name'],
            'telefone' => $form_data['telefone'],
            'cidade' => $form_data['cidade'],
            'tipo_de_plano' => $form_data['qual_plano'],
            'quantidade_de_pessoas' => $form_data['qtd_pessoas'],
            'idades' => is_array($form_data['ages']) ? implode(', ', $form_data['ages']) : $form_data['ages'],
            'vendedor_nome' => $vendedor['nome'],
            'vendedor_telefone' => $vendedor['telefone'],
            'atendente' => $vendedor['nome'],
            'telefone_vendedor' => $vendedor['telefone'],
            'grupo' => $grupo,
            'data_envio' => $data_envio,
            'hora_submissao' => $hora_envio,
            'contagem_diaria' => $contagem_diaria,
            'contagem_mensal' => $contagem_mensal,
            'data_contagem' => array(
                'dia' => $today,
                'mes' => $current_month
            ),
            'pagina_origem' => $form_data['pagina_origem']
        );

        // *** VERIFICA REDISTRIBUIÇÃ•ES ***
        $enable_redistributions = isset($options['enable_redistributions']) ?
            ($options['enable_redistributions'] === '1' || $options['enable_redistributions'] === true) : true;

        $this->log("ðŸ”§ [CORREÇÃO] Redistribuições habilitadas: " . ($enable_redistributions ? 'SIM' : 'NÃO'));

        // *** SE REDISTRIBUIÇÃ•ES HABILITADAS - CRIA LEAD TRACKING ***
        if ($enable_redistributions) {
            global $formulario_hapvida_lead_tracking;

            if ($formulario_hapvida_lead_tracking && method_exists($formulario_hapvida_lead_tracking, 'create_lead_tracking')) {
                $this->log("✅ Criando lead tracking para sistema de confirmação");

                $tracking_result = $formulario_hapvida_lead_tracking->create_lead_tracking($form_data, $vendedor);

                if ($tracking_result && isset($tracking_result['link_confirmacao'])) {
                    $filtered_form_data['link_confirmacao'] = $tracking_result['link_confirmacao'];
                    $filtered_form_data['expira_em'] = $tracking_result['expira_em'];
                    $filtered_form_data['timeout_minutos'] = $tracking_result['timeout_minutos'];
                    $filtered_form_data['sistema_confirmacao_ativo'] = true;

                    $this->log("ðŸ”— Link de confirmação gerado: " . $tracking_result['link_confirmacao']);
                    $this->log("â° Timeout aplicado: " . $tracking_result['timeout_minutos'] . " minutos");
                }
            } else {
                $this->log("âš ï¸ Lead tracking não disponível - enviando sem sistema de confirmação");
                $filtered_form_data['sistema_confirmacao_ativo'] = false;
                $filtered_form_data['motivo_sem_confirmacao'] = 'Sistema de lead tracking não disponível';
            }
        } else {
            $this->log("ðŸš« Redistribuições desabilitadas - enviando sem link de confirmação");
            $filtered_form_data['sistema_confirmacao_ativo'] = false;
            $filtered_form_data['motivo_sem_confirmacao'] = 'Redistribuições desabilitadas nas configurações';
        }

        // *** ENVIO DO WEBHOOK ***
        $this->log("ðŸ“¤ Enviando webhook para: " . substr($webhook_url, 0, 50) . "...");
        $this->log("ðŸ“Š Data/Hora no webhook: {$data_envio} {$hora_envio}");

        $webhook_config = $this->get_webhook_timeout_config();
        $webhook_config['body'] = json_encode($filtered_form_data);

        $response = wp_remote_post($webhook_url, $webhook_config);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log("âŒ Erro ao enviar webhook: {$error_message}");
            $this->save_webhook_entry($filtered_form_data, 'pending', $error_message);
            $this->send_first_failure_notification($filtered_form_data, $error_message);
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        $this->log("ðŸ“¥ Resposta do webhook - Código: {$response_code}");

        if ($response_code >= 200 && $response_code < 300) {
            $this->log("✅ Webhook enviado com sucesso!");
            $this->save_webhook_entry($filtered_form_data, 'success', '', $response_code);
            return true;
        } else {
            $error_message = "HTTP {$response_code}";
            $this->log("âŒ Webhook retornou erro: {$error_message}");
            $this->save_webhook_entry($filtered_form_data, 'pending', $error_message, $response_code);
            $this->send_first_failure_notification($filtered_form_data, $error_message);
            return false;
        }
    }

    public function send_confirmation_webhook($lead_data)
    {
        try {
            // *** LOG DE INÃCIO ***
            $this->log("ðŸ“¤ [CORREÇÃO] Iniciando send_confirmation_webhook");
            error_log("HAPVIDA DEBUG: send_confirmation_webhook iniciado");

            $options = get_option($this->settings_option_name);

            // *** VALIDAÇÃO DOS DADOS ***
            if (!$lead_data || !isset($lead_data['lead_id'])) {
                $this->log("âŒ [CORREÇÃO] Dados do lead inválidos para webhook de confirmação");
                error_log("HAPVIDA ERROR: Dados lead inválidos para confirmação");
                return false;
            }

            // *** EXTRAI DADOS DO LEAD ***
            $form_data = isset($lead_data['form_data']) ? $lead_data['form_data'] : array();
            $vendedor_atual = isset($lead_data['vendedor_atual']) ? $lead_data['vendedor_atual'] : array();
            $grupo = isset($vendedor_atual['grupo']) ? strtolower($vendedor_atual['grupo']) : 'drv';

            // *** DETERMINA URL DO WEBHOOK ***
            $webhook_url = '';
            if ($grupo === 'drv') {
                $webhook_url = isset($options['webhook_url_drv_confirmation']) ?
                    trim($options['webhook_url_drv_confirmation']) : '';
            } else {
                $webhook_url = isset($options['webhook_url_seu_souza_confirmation']) ?
                    trim($options['webhook_url_seu_souza_confirmation']) : '';
            }

            if (empty($webhook_url)) {
                $this->log("âš ï¸ [CORREÇÃO] URL de confirmação não configurada para grupo {$grupo}");
                return false;
            }

            // *** OBTÉM CONTAGENS ***
            $today = current_time('Y-m-d');
            $current_month = current_time('Y-m');
            $daily_submissions = get_option($this->daily_submissions_option, array());
            $monthly_submissions = get_option($this->monthly_submissions_option, array());
            $today_count = isset($daily_submissions[$today]) ? $daily_submissions[$today] : 0;
            $month_count = isset($monthly_submissions[$current_month]) ? $monthly_submissions[$current_month] : 0;

            // *** PÃGINA DE ORIGEM ***
            $pagina_origem = isset($form_data['pagina_origem']) ? $form_data['pagina_origem'] :
                (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : home_url());

            // *** MONTA DADOS DO WEBHOOK ***
            $now = new DateTime('now', new DateTimeZone($this->get_wp_timezone()));
            $webhook_data = array(
                'lead_id' => $lead_data['lead_id'],
                'nome' => isset($form_data['nome']) ? $form_data['nome'] : (isset($form_data['name']) ? $form_data['name'] : 'N/A'),
                'telefone' => isset($form_data['telefone']) ? $form_data['telefone'] : 'N/A',
                'cidade' => isset($form_data['cidade']) ? $form_data['cidade'] : 'N/A',
                'tipo_de_plano' => isset($form_data['tipo_de_plano']) ? $form_data['tipo_de_plano'] :
                    (isset($form_data['qual_plano']) ? $form_data['qual_plano'] : 'N/A'),
                'quantidade_de_pessoas' => isset($form_data['quantidade_de_pessoas']) ? $form_data['quantidade_de_pessoas'] :
                    (isset($form_data['qtd_pessoas']) ? $form_data['qtd_pessoas'] : '1'),
                'idades' => isset($form_data['idades']) ? $form_data['idades'] :
                    (isset($form_data['ages']) ? (is_array($form_data['ages']) ? implode(', ', $form_data['ages']) : $form_data['ages']) : 'N/A'),
                'atendente' => isset($vendedor_atual['nome']) ? $vendedor_atual['nome'] : 'N/A',
                'telefone_vendedor' => isset($vendedor_atual['telefone']) ? $vendedor_atual['telefone'] : 'N/A',
                'vendedor_id' => isset($vendedor_atual['vendedor_id']) ? $vendedor_atual['vendedor_id'] : '', // NOVO CAMPO
                'grupo' => $grupo,
                'data_envio' => $now->format('d-m-Y'),
                'hora_submissao' => $now->format('H:i:s'),
                'status' => 'confirmado',
                'confirmado_em' => isset($lead_data['confirmado_em']) ? $lead_data['confirmado_em'] : $now->format('Y-m-d H:i:s'),
                'webhook_type' => 'confirmation',
                'was_redistributed' => isset($lead_data['tentativas']) && $lead_data['tentativas'] > 0,
                'total_attempts' => isset($lead_data['tentativas']) ? ($lead_data['tentativas'] + 1) : 1,
                'created_at' => isset($lead_data['created_at']) ? $lead_data['created_at'] : '',
                'contagem_diaria' => $today_count,
                'contagem_mensal' => $month_count,
                'pagina_origem' => $pagina_origem,
            );

            $json_data = json_encode($webhook_data);
            $this->log("ðŸ“ [CORREÇÃO] JSON de confirmação (primeiros 500 chars): " . substr($json_data, 0, 500) . (strlen($json_data) > 500 ? '...' : ''));
            error_log("HAPVIDA DEBUG: Enviando JSON confirmação com tamanho: " . strlen($json_data) . " bytes - ID vendedor incluído");

            // *** ENVIA WEBHOOK DE CONFIRMAÇÃO ***
            $response = wp_remote_post($webhook_url, array(
                'body' => json_encode($webhook_data),
                'headers' => array('Content-Type' => 'application/json'),
                'timeout' => 15,
                'blocking' => true,
                'sslverify' => false
            ));

            // *** LOG DA RESPOSTA ***
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $this->log("âŒ [CORREÇÃO] ERRO webhook de confirmação ({$grupo}): " . $error_message);
                error_log("HAPVIDA ERROR: Webhook confirmação falhou - " . $error_message);

                // Salva webhook falho para retry
                $this->save_webhook_entry($webhook_data, 'pending', $error_message);
                return false;
            } else {
                $response_code = wp_remote_retrieve_response_code($response);
                $response_body = wp_remote_retrieve_body($response);

                if ($response_code >= 200 && $response_code < 300) {
                    $this->log("✅ [CORREÇÃO] Webhook de confirmação enviado com sucesso - Lead ID: {$lead_data['lead_id']} - HTTP {$response_code}");
                    $this->log("✅ [CORREÇÃO] ID do vendedor enviado: {$webhook_data['vendedor_id']}");
                    error_log("HAPVIDA SUCCESS: Webhook confirmação enviado - HTTP {$response_code}, ID vendedor: {$webhook_data['vendedor_id']}");

                    // Salva webhook como concluído
                    $this->save_webhook_entry($webhook_data, 'completed', '', $response_code);
                    return true;
                } else {
                    $this->log("âŒ [CORREÇÃO] Webhook de confirmação falhou - Lead ID: {$lead_data['lead_id']} - HTTP {$response_code}");
                    error_log("HAPVIDA ERROR: Webhook confirmação falhou - HTTP {$response_code}");

                    // Salva webhook falho para retry
                    $this->save_webhook_entry($webhook_data, 'pending', "HTTP {$response_code}", $response_code);
                    return false;
                }
            }

        } catch (Exception $e) {
            $this->log("âŒ [CORREÇÃO] ERRO CRÃTICO na função send_confirmation_webhook: " . $e->getMessage());
            error_log("HAPVIDA CRITICAL ERROR: send_confirmation_webhook - " . $e->getMessage());
            return false;
        }
    }

    private function calculate_time_to_confirmation($created_at, $confirmado_em)
    {
        try {
            $created = new DateTime($created_at);
            $confirmed = new DateTime($confirmado_em);
            $interval = $created->diff($confirmed);

            $total_minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;

            return array(
                'total_minutes' => $total_minutes,
                'formatted' => $interval->format('%d dias, %h horas e %i minutos'),
                'human_readable' => $this->format_time_human_readable($total_minutes)
            );
        } catch (Exception $e) {
            return array(
                'total_minutes' => 0,
                'formatted' => 'Erro no cálculo',
                'human_readable' => 'Tempo indisponível'
            );
        }
    }

    private function format_time_human_readable($minutes)
    {
        if ($minutes < 1) {
            return 'Menos de 1 minuto';
        } elseif ($minutes < 60) {
            return $minutes . ' minuto' . ($minutes > 1 ? 's' : '');
        } elseif ($minutes < 1440) { // Menos de 24 horas
            $hours = floor($minutes / 60);
            $remaining_minutes = $minutes % 60;
            $result = $hours . ' hora' . ($hours > 1 ? 's' : '');
            if ($remaining_minutes > 0) {
                $result .= ' e ' . $remaining_minutes . ' minuto' . ($remaining_minutes > 1 ? 's' : '');
            }
            return $result;
        } else {
            $days = floor($minutes / 1440);
            $remaining_hours = floor(($minutes % 1440) / 60);
            $result = $days . ' dia' . ($days > 1 ? 's' : '');
            if ($remaining_hours > 0) {
                $result .= ' e ' . $remaining_hours . ' hora' . ($remaining_hours > 1 ? 's' : '');
            }
            return $result;
        }
    }


    private function get_vendor_priority($vendedor_nome, $grupo)
    {
        global $formulario_hapvida_lead_tracking;

        if (!$formulario_hapvida_lead_tracking) {
            return 'unknown';
        }

        try {
            // Busca estatísticas do vendedor para determinar prioridade
            $vendor_stats = $formulario_hapvida_lead_tracking->get_vendor_priority_info($vendedor_nome, $grupo);

            if ($vendor_stats) {
                $confirmation_rate = $vendor_stats['confirmation_rate'] ?? 0;

                if ($confirmation_rate >= 80) {
                    return 'high';
                } elseif ($confirmation_rate >= 60) {
                    return 'medium';
                } elseif ($confirmation_rate >= 40) {
                    return 'low';
                } else {
                    return 'very_low';
                }
            }

            return 'unknown';
        } catch (Exception $e) {
            $this->log("Erro ao obter prioridade do vendedor: " . $e->getMessage());
            return 'unknown';
        }
    }

    private function retry_webhook_with_url($webhook_data, $webhook_url, $webhook_type)
    {
        $this->log("ðŸ“¤ Tentando reenviar webhook de {$webhook_type} para: " . substr($webhook_url, 0, 50) . "...");

        // Configurações mais robustas para o retry
        $response = wp_remote_post($webhook_url, array(
            'body' => json_encode($webhook_data),
            'headers' => array('Content-Type' => 'application/json'),
            'timeout' => 30, // Aumenta timeout para 30 segundos
            'blocking' => true,
            'sslverify' => false // Para casos de problemas com SSL
        ));

        if (is_wp_error($response)) {
            $this->log("Retry de {$webhook_type} falhou: " . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code >= 200 && $response_code < 300) {
            $this->log("Retry de {$webhook_type} bem-sucedido (HTTP {$response_code})");
            return true;
        } else {
            $this->log("Retry de {$webhook_type} falhou (HTTP {$response_code})");
            return false;
        }
    }


    private function append_text_to_whatsapp_url($base_url, $form_data)
    {
        $nome = isset($form_data['name']) ? $form_data['name'] : '';
        $cidade = isset($form_data['cidade']) ? $form_data['cidade'] : '';
        $qtd_pessoas = isset($form_data['qtd_pessoas']) ? $form_data['qtd_pessoas'] : '';
        $plano = isset($form_data['qual_plano']) ? $form_data['qual_plano'] : '';

        // *** TRATAMENTO SIMPLES DAS IDADES (baseado na versão anterior) ***
        $idades = isset($form_data['ages'])
            ? (is_array($form_data['ages'])
                ? implode(', ', $form_data['ages'])
                : $form_data['ages'])
            : '';

        $text = "Olá, meu nome é *{$nome}*, gostaria de uma cotação para a cidade de *{$cidade}*:\n\n" .
            "*Quantidade de pessoas* = {$qtd_pessoas}\n" .
            "*Tipo do Plano* = {$plano}\n" .
            "*Idades* = {$idades}\n";

        $encoded_text = urlencode($text);
        return $base_url . '?text=' . $encoded_text;
    }

    public function shortcode_sem_titulo($atts)
    {
        $atts = is_array($atts) ? $atts : array();
        $atts['sem_titulo'] = 'true';
        return $this->shortcode($atts);
    }

    public function shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'form_id' => 'hapvida-form',
            'sem_titulo' => 'false'
        ), $atts);

        $sem_titulo = ($atts['sem_titulo'] === 'true');

        // Obtém as cidades do plugin
        $options = get_option($this->settings_option_name, array());
        $cities_text = isset($options['cidades']) ? $options['cidades'] : '';
        $city_list = array_filter(array_map('trim', explode("\n", $cities_text)));

        ob_start();
        ?>
        <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
        <style>
            /* Reset e base */
            .hapvida-form-container * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            /* ===== GLASSMORPHISM CARD ===== */
            .hapvida-form-container {
                width: 100%;
                max-width: 460px;
                margin: 0 auto 25px;
                font-family: 'Open Sans', sans-serif;
                background: linear-gradient(160deg, rgba(0,84,184,0.18) 0%, rgba(0,84,184,0.08) 25%, rgba(255,255,255,0.45) 50%, rgba(0,84,184,0.1) 70%, rgba(0,84,184,0.2) 100%);
                backdrop-filter: blur(24px);
                -webkit-backdrop-filter: blur(24px);
                border-radius: 24px;
                border: 1px solid rgba(0,84,184,0.15);
                padding: 40px 32px;
                box-shadow: 0 20px 60px rgba(0,84,184,0.1), inset 0 1px 0 rgba(255,255,255,0.7);
                position: relative;
                overflow: hidden;
            }

            /* Decorative blur orbs */
            .hapvida-form-container::before {
                content: '';
                position: absolute;
                width: 220px;
                height: 220px;
                border-radius: 50%;
                background: radial-gradient(circle, rgba(0,84,184,0.2), transparent 70%);
                top: -70px;
                right: -50px;
                filter: blur(40px);
                pointer-events: none;
            }

            .hapvida-form-container::after {
                content: '';
                position: absolute;
                width: 180px;
                height: 180px;
                border-radius: 50%;
                background: radial-gradient(circle, rgba(0,84,184,0.16), transparent 70%);
                bottom: -50px;
                left: -40px;
                filter: blur(35px);
                pointer-events: none;
            }

            /* ===== HEADER ===== */
            .hapvida-form-header {
                text-align: center;
                margin-bottom: 28px;
                position: relative;
                z-index: 1;
            }

            .hapvida-form-title {
                color: #0a2540;
                font-size: 24px;
                font-weight: 900;
                letter-spacing: -0.02em;
                margin-bottom: 8px;
                line-height: 1.4;
            }

            .hapvida-form-subtitle {
                color: #2c4a63;
                font-size: 14px;
                font-weight: 500;
                line-height: 1.5;
            }

            .hap-tag {
                background: #ff6600;
                color: #fff;
                padding: 2px 10px;
                border-radius: 6px;
                display: inline-block;
                transform: skew(-5deg);
                margin: 0 2px;
                line-height: 1.4;
            }

            .hap-tag span {
                display: inline-block;
                transform: skew(5deg);
                font-weight: 700;
            }

            .accent-orange {
                color: #ff6600;
                font-weight: 700;
            }

            /* ===== FORM FIELDS ===== */
            .hapvida-form {
                display: flex;
                flex-direction: column;
                gap: 14px;
                position: relative;
                z-index: 1;
            }

            .hapvida-field-row {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 14px;
            }

            .hapvida-field {
                display: flex;
                align-items: center;
                gap: 12px;
                background: rgba(255,255,255,0.6);
                border: 1.5px solid rgba(0,84,184,0.15);
                border-radius: 14px;
                padding: 0 16px;
                height: 52px;
                transition: all 0.3s ease;
            }

            .hapvida-field:focus-within {
                border-color: #0054B8;
                background: rgba(255,255,255,0.85);
                box-shadow: 0 0 0 3px rgba(0,84,184,0.08);
            }

            .hapvida-field-icon {
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
                color: #0054B8;
                font-size: 16px;
                opacity: 0.9;
            }

            .hapvida-field-icon svg {
                stroke: #0054B8;
            }

            .hapvida-field:focus-within .hapvida-field-icon {
                opacity: 1;
            }

            .hapvida-field input,
            .hapvida-field select {
                flex: 1;
                background: none;
                border: none;
                outline: none;
                color: #0a2540;
                font-size: 14px;
                font-family: 'Open Sans', sans-serif;
                font-weight: 500;
                width: 100%;
                min-width: 0;
                height: auto !important;
                padding: 0 !important;
                box-shadow: none !important;
            }

            .hapvida-field input::placeholder {
                color: rgba(0,84,184,0.45);
            }

            .hapvida-field select {
                appearance: none;
                -webkit-appearance: none;
                cursor: pointer;
                color: rgba(0,84,184,0.45);
            }

            .hapvida-field select option {
                color: #0a2540;
            }

            .hapvida-field select:valid:not([value=""]) {
                color: #0a2540;
            }

            .hapvida-chevron {
                flex-shrink: 0;
            }

            .hapvida-chevron svg {
                stroke: rgba(0,84,184,0.35);
            }

            /* ===== CONTAINER DE IDADES ===== */
            .age-inputs {
                display: grid;
                gap: 10px;
            }

            .age-inputs .hapvida-field {
                display: flex;
                align-items: center;
                gap: 12px;
                background: rgba(255,255,255,0.6);
                border: 1.5px solid rgba(0,84,184,0.1);
                border-radius: 14px;
                padding: 0 16px;
                height: 52px;
                transition: all 0.3s ease;
            }

            .age-inputs .hapvida-field:focus-within {
                border-color: #0054B8;
                background: rgba(255,255,255,0.85);
                box-shadow: 0 0 0 3px rgba(0,84,184,0.08);
            }

            .age-inputs input[name="form_fields[ages][]"] {
                flex: 1 !important;
                width: 100% !important;
                min-width: 0 !important;
                height: auto !important;
                padding: 0 !important;
                border: none !important;
                border-radius: 0 !important;
                font-family: 'Open Sans', sans-serif !important;
                font-size: 14px !important;
                font-weight: 500 !important;
                color: #0a2540 !important;
                background: none !important;
                outline: none !important;
                box-shadow: none !important;
            }

            .age-inputs input[name="form_fields[ages][]"]::placeholder {
                color: rgba(0,84,184,0.45) !important;
                font-weight: 400 !important;
                opacity: 1 !important;
            }

            .age-inputs .hapvida-field-icon {
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                flex-shrink: 0 !important;
                color: #0054B8 !important;
                font-size: 16px !important;
                opacity: 0.9 !important;
                pointer-events: none !important;
            }

            .age-inputs .hapvida-field:focus-within .hapvida-field-icon {
                opacity: 1 !important;
            }

            /* ===== BOTÃO PRINCIPAL ===== */
            .hapvida-submit-btn {
                margin-top: 8px;
                width: 100%;
                height: 54px;
                border: none;
                border-radius: 14px;
                background: linear-gradient(135deg, #0054B8, #0078dc);
                color: #fff;
                font-size: 16px;
                font-weight: 700;
                cursor: pointer;
                font-family: 'Open Sans', sans-serif;
                letter-spacing: 0.04em;
                box-shadow: 0 8px 28px rgba(0,84,184,0.3);
                transition: all 0.25s ease;
                text-transform: uppercase;
                position: relative;
                overflow: hidden;
            }

            .hapvida-submit-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 12px 36px rgba(0,84,184,0.4);
            }

            .hapvida-submit-btn:active {
                transform: translateY(0);
            }

            .hapvida-submit-btn:disabled {
                background: #9ca3af;
                cursor: not-allowed;
                box-shadow: none;
                transform: none;
            }

            .hapvida-submit-btn.loading {
                background: linear-gradient(135deg, #6b7280, #4b5563);
                cursor: wait;
                transform: none;
            }

            .hapvida-submit-btn.loading .hapvida-btn-text {
                opacity: 0;
            }

            .hapvida-submit-btn.loading::after {
                content: '';
                position: absolute;
                width: 20px;
                height: 20px;
                margin: auto;
                border: 3px solid transparent;
                border-top-color: white;
                border-radius: 50%;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
            }

            /* ===== SECURE NOTICE ===== */
            .hapvida-secure-notice {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                margin-top: 12px;
            }

            .hapvida-secure-notice svg {
                stroke: #0054B8;
                opacity: 0.4;
                flex-shrink: 0;
            }

            .hapvida-secure-notice span {
                color: #8ea4b8;
                font-size: 11px;
                font-weight: 500;
                font-family: 'Open Sans', sans-serif;
            }

            /* ===== VALIDATION STATES ===== */
            .hapvida-field.error {
                border-color: #ef4444 !important;
                background-color: rgba(239, 68, 68, 0.05) !important;
                box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1) !important;
            }

            .hapvida-field.success {
                border-color: #10b981 !important;
                background-color: rgba(16, 185, 129, 0.05) !important;
                box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1) !important;
            }

            /* ========================================
                                           NOVO MODAL DE SUCESSO MELHORADO
                                           ======================================== */
            .hapvida-modal-success {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 999999;
                align-items: center;
                justify-content: center;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94),
                    visibility 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            }

            .hapvida-modal-success.show {
                display: flex;
                opacity: 1;
                visibility: visible;
            }

            /* Overlay com blur elegante */
            .hapvida-modal-success::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: linear-gradient(135deg,
                        rgba(0, 84, 184, 0.95) 0%,
                        rgba(0, 61, 133, 0.95) 50%,
                        rgba(0, 41, 89, 0.95) 100%);
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
            }

            /* Container do conteúdo */
            .hapvida-modal-success-content {
                position: relative;
                background: linear-gradient(145deg, #ffffff 0%, #f8fbff 100%);
                border-radius: 20px;
                padding: 0;
                width: 90%;
                max-width: 480px;
                overflow: hidden;
                box-shadow: 0 30px 60px rgba(0, 0, 0, 0.3),
                    0 0 100px rgba(0, 84, 184, 0.2);
                transform: scale(0.8) translateY(30px);
                transition: transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            }

            .hapvida-modal-success.show .hapvida-modal-success-content {
                transform: scale(1) translateY(0);
            }

            /* Header do modal */
            .hapvida-modal-success-header {
                background: #0054B8;
                padding: 35px 25px 30px;
                text-align: center;
                position: relative;
                overflow: hidden;
            }

            /* Padrão decorativo no header */
            .hapvida-modal-success-header::before {
                content: '';
                position: absolute;
                top: -50%;
                right: -50%;
                width: 200%;
                height: 200%;
                background: repeating-linear-gradient(45deg,
                        transparent,
                        transparent 10px,
                        rgba(255, 255, 255, 0.03) 10px,
                        rgba(255, 255, 255, 0.03) 20px);
                /*animation: slidePattern 20s linear infinite;*/
            }

            @keyframes slidePattern {
                0% {
                    transform: translate(0, 0);
                }

                100% {
                    transform: translate(50px, 50px);
                }
            }

            /* Ãcone de sucesso animado */
            .hapvida-success-check {
                width: 80px;
                height: 80px;
                margin: 0 auto 20px;
                position: relative;
                z-index: 2;
            }

            .hapvida-success-check-circle {
                width: 80px;
                height: 80px;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.15);
                border: 3px solid rgba(255, 255, 255, 0.3);
                display: flex;
                align-items: center;
                justify-content: center;
                position: relative;
                /* animation: successPulse 1.5s ease-in-out;*/
            }

            .hapvida-success-check-icon {
                font-size: 40px;
                color: #ffffff;
                /*animation: successScale 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275) 0.2s both;*/
            }

            @keyframes successPulse {
                0% {
                    transform: scale(0);
                    opacity: 0;
                }

                50% {
                    transform: scale(1.1);
                }

                100% {
                    transform: scale(1);
                    opacity: 1;
                }
            }

            @keyframes successScale {
                0% {
                    transform: scale(0) rotate(-45deg);
                    opacity: 0;
                }

                100% {
                    transform: scale(1) rotate(0);
                    opacity: 1;
                }
            }

            /* Título do sucesso */
            .hapvida-modal-success-title {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                font-size: 24px;
                font-weight: 700;
                color: #ffffff;
                margin: 0;
                position: relative;
                z-index: 2;
                letter-spacing: -0.5px;
                text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
                /*animation: fadeInUp 0.6s ease 0.3s both;*/
            }

            @keyframes fadeInUp {
                0% {
                    transform: translateY(20px);
                    opacity: 0;
                }

                100% {
                    transform: translateY(0);
                    opacity: 1;
                }
            }

            /* Botão de fechar elegante */
            .hapvida-modal-success-close {
                position: absolute;
                top: 15px;
                right: 15px;
                width: 36px;
                height: 36px;
                background: rgba(255, 255, 255, 0.1);
                border: 2px solid rgba(255, 255, 255, 0.2);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                transition: all 0.3s ease;
                z-index: 3;
            }

            .hapvida-modal-success-close:hover {
                background: rgba(255, 255, 255, 0.2);
                transform: rotate(90deg) scale(1.1);
                border-color: rgba(255, 255, 255, 0.3);
            }

            .hapvida-modal-success-close i {
                color: #ffffff;
                font-size: 18px;
            }

            /* Body do modal */
            .hapvida-modal-success-body {
                padding: 35px 30px;
                text-align: center;
                background: #ffffff;
                /*animation: fadeIn 0.6s ease 0.4s both;*/
            }

            @keyframes fadeIn {
                0% {
                    opacity: 0;
                }

                100% {
                    opacity: 1;
                }
            }

            /* Mensagem principal */
            .hapvida-success-main-message {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                font-size: 17px;
                color: #1e293b;
                line-height: 1.6;
                margin-bottom: 25px;
            }

            .hapvida-success-main-message strong {
                color: #0054B8;
                font-weight: 600;
            }

            .hapvida-success-highlight {
                color: #10b981 !important;
                font-weight: 700;
            }

            /* Container de redirecionamento com WhatsApp */
            .hapvida-whatsapp-redirect {
                background: #ffffff !important;
                /* Força branco */
                background-image: none !important;
                /* Remove gradiente */
                border: 2px solid #25D366 !important;
                border-radius: 16px;
                padding: 20px;
                margin-top: 20px;
                position: relative;
                overflow: hidden;
            }

            /* Estilo para o link alternativo do WhatsApp */
            #hapvida-whatsapp-link-container {
                animation: slideIn 0.4s ease-out;
            }

            #hapvida-whatsapp-link:hover {
                background: #20ba5a;
                transform: translateY(-2px);
                box-shadow: 0 6px 16px rgba(37, 211, 102, 0.4);
            }

            #hapvida-whatsapp-link:active {
                transform: translateY(0);
            }

            /* Responsivo para mobile */
            @media (max-width: 480px) {
                #hapvida-whatsapp-link {
                    padding: 10px 20px !important;
                    font-size: 14px !important;
                }

                #hapvida-whatsapp-link-container p {
                    font-size: 13px !important;
                }
            }

            @keyframes slideIn {
                0% {
                    transform: translateY(20px);
                    opacity: 0;
                }

                100% {
                    transform: translateY(0);
                    opacity: 1;
                }
            }

            .hapvida-whatsapp-redirect::before {
                display: none;
                /* Remove animação de fundo */
            }

            @keyframes shimmer {
                0% {
                    background-position: 0% 50%;
                }

                50% {
                    background-position: 100% 50%;
                }

                100% {
                    background-position: 0% 50%;
                }
            }

            .hapvida-whatsapp-content {
                position: relative;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 12px;
                font-size: 15px;
                font-weight: 600;
                color: #1b5e20;
            }

            .hapvida-whatsapp-icon {
                font-size: 28px;
                color: #25D366;
                /*animation: bounce 2s infinite;*/
            }

            @keyframes bounce {

                0%,
                20%,
                50%,
                80%,
                100% {
                    transform: translateY(0);
                }

                40% {
                    transform: translateY(-10px);
                }

                60% {
                    transform: translateY(-5px);
                }
            }

            /* Loading dots animados */
            .hapvida-loading-dots {
                display: inline-flex;
                gap: 4px;
                margin-left: 8px;
            }

            .hapvida-loading-dots span {
                width: 8px;
                height: 8px;
                background: #25D366;
                border-radius: 50%;
                /*animation: loadingDot 1.4s ease-in-out infinite;*/
            }


            @keyframes loadingDot {

                0%,
                60%,
                100% {
                    transform: scale(1);
                    opacity: 1;
                }

                30% {
                    transform: scale(1.3);
                    opacity: 0.8;
                }
            }

            /* Timer countdown */
            .hapvida-countdown {
                margin-top: 20px;
                padding: 12px;
                background: rgba(0, 84, 184, 0.08);
                border-radius: 12px;
                font-size: 14px;
                color: #475569;
                font-weight: 500;
            }

            .hapvida-countdown-number {
                display: inline-block;
                min-width: 20px;
                padding: 2px 8px;
                background: #0054B8;
                color: white;
                border-radius: 6px;
                font-weight: 700;
                margin: 0 5px;
                animation: countPulse 1s ease-in-out infinite;
            }

            @keyframes countPulse {

                0%,
                100% {
                    transform: scale(1);
                }

                50% {
                    transform: scale(1.05);
                }
            }

            @keyframes modalZoomIn {
                0% {
                    transform: scale(0.5) translateY(100px);
                    opacity: 0;
                }

                75% {
                    transform: scale(1.02) translateY(-5px);
                }

                100% {
                    transform: scale(1) translateY(0);
                    opacity: 1;
                }
            }

            .hapvida-modal-success.zoom-entrance .hapvida-modal-success-content {
                animation: modalZoomIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            }

            /* Melhorias de acessibilidade */
            .hapvida-field input:focus,
            .hapvida-field select:focus {
                outline: none;
            }

            .hapvida-submit-btn:focus {
                outline: 3px solid rgba(245, 158, 11, 0.4);
                outline-offset: 2px;
            }

            .hapvida-modal-success-close:focus {
                outline: 3px solid rgba(255, 255, 255, 0.5);
                outline-offset: 2px;
            }

            /* Previne scroll quando modal está aberto */
            body.modal-open {
                overflow: hidden;
            }

            /* Responsividade */
            @media (max-width: 768px) {
                .hapvida-form-container {
                    margin: 0 16px 20px;
                    border-radius: 20px;
                    max-width: 95%;
                    padding: 32px 20px;
                }

                .hapvida-form-title {
                    font-size: 20px;
                }

                .hapvida-form-subtitle {
                    font-size: 13px;
                }

                .hapvida-form-header {
                    margin-bottom: 22px;
                }

                .hapvida-field-row {
                    grid-template-columns: 1fr 1fr;
                    gap: 10px;
                }

                .hapvida-field,
                .age-inputs .hapvida-field {
                    height: 48px;
                }

                .hapvida-submit-btn {
                    height: 50px;
                    font-size: 15px;
                    border-radius: 14px;
                }

                /* Modal de sucesso responsivo */
                .hapvida-modal-success-content {
                    width: 95%;
                    margin: 20px;
                    border-radius: 20px;
                }

                .hapvida-modal-success-header {
                    padding: 30px 20px 25px;
                }

                .hapvida-success-check {
                    width: 70px;
                    height: 70px;
                }

                .hapvida-success-check-circle {
                    width: 70px;
                    height: 70px;
                }

                .hapvida-success-check-icon {
                    font-size: 35px;
                }

                .hapvida-modal-success-title {
                    font-size: 23px;
                    color: #ffffff;
                }

                .hapvida-modal-success-body {
                    padding: 25px 20px;
                }

                .hapvida-success-main-message {
                    font-size: 15px;
                }

                .hapvida-whatsapp-redirect {
                    padding: 16px;
                }

                .hapvida-whatsapp-content {
                    font-size: 14px;
                    flex-direction: column;
                    gap: 8px;
                }

                .hapvida-whatsapp-icon {
                    font-size: 24px;
                }
            }

            @media (max-width: 500px) {
                .hapvida-form-container {
                    padding: 32px 20px;
                    border-radius: 20px;
                }

                .hapvida-form-title {
                    font-size: 20px;
                }

                .hapvida-form-subtitle {
                    font-size: 13px;
                }

                .hapvida-field-row {
                    grid-template-columns: 1fr;
                }

                .hapvida-field,
                .age-inputs .hapvida-field {
                    height: 48px;
                    border-radius: 12px;
                }

                .hapvida-submit-btn {
                    height: 48px;
                    font-size: 14px;
                    border-radius: 12px;
                    margin-top: 6px;
                }

                /* Modal de sucesso em mobile pequeno */
                .hapvida-modal-success-content {
                    border-radius: 16px;
                }

                .hapvida-modal-success-header {
                    padding: 25px 16px 20px;
                }

                .hapvida-success-check {
                    width: 60px;
                    height: 60px;
                    margin-bottom: 15px;
                }

                .hapvida-success-check-circle {
                    width: 60px;
                    height: 60px;
                    border-width: 2px;
                }

                .hapvida-success-check-icon {
                    font-size: 30px;
                }

                .hapvida-modal-success-title {
                    font-size: 18px;
                    color: #ffffff;
                }

                .hapvida-modal-success-body {
                    padding: 20px 16px;
                }

                .hapvida-success-main-message {
                    font-size: 14px;
                    margin-bottom: 20px;
                }

                .hapvida-whatsapp-redirect {
                    padding: 14px;
                    border-radius: 12px;
                }

                .hapvida-whatsapp-content {
                    font-size: 13px;
                }

                .hapvida-countdown {
                    font-size: 13px;
                    padding: 10px;
                }
            }
                </style>

                <!-- HTML DO FORMULARIO -->
                <div class="hapvida-form-container no-lazy">
                    <?php if (!$sem_titulo): ?>
                    <div class="hapvida-form-header">
                        <div class="hapvida-form-title">
                            <span class="hap-tag"><span>Faça uma cotação</span></span><br>
                            <span style="color:#0054B8;">em menos de</span> <span class="accent-orange">1 minuto</span>
                        </div>
                        <div class="hapvida-form-subtitle">
                            Apenas assuntos para <span class="hap-tag"><span>CONTRATAÇÃO</span></span><br>
                            de um novo plano Hapvida
                        </div>
                    </div>
                    <?php endif; ?>
                    <form class="hapvida-form no-lazy" id="hapvida-main-form">
                        <!-- Nome Completo -->
                        <div class="hapvida-field" id="hapvida-field-name">
                            <span class="hapvida-field-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                            </span>
                            <input type="text" id="hapvida-name" name="form_fields[name]"
                                placeholder="Seu nome completo" required autocomplete="off">
                        </div>

                        <!-- Telefone (WhatsApp) -->
                        <div class="hapvida-field" id="hapvida-field-telefone">
                            <span class="hapvida-field-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/>
                                </svg>
                            </span>
                            <input type="tel" id="hapvida-telefone" name="form_fields[telefone]"
                                data-real-name="form_fields[telefone]"
                                placeholder="(00) 00000-0000" required autocomplete="new-password" readonly="readonly"
                                data-form-type="other" spellcheck="false">
                        </div>

                        <!-- Cidade e Tipo de Plano -->
                        <div class="hapvida-field-row">
                            <div class="hapvida-field" id="hapvida-field-cidade">
                                <span class="hapvida-field-icon">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                                        <circle cx="12" cy="10" r="3"/>
                                    </svg>
                                </span>
                                <select id="hapvida-cidade" name="form_fields[cidade]" required autocomplete="off">
                                    <option value="">Cidade</option>
                                    <?php foreach ($city_list as $city): ?>
                                        <option value="<?php echo esc_attr($city); ?>">
                                            <?php echo esc_html($city); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="hapvida-chevron">
                                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2.5">
                                        <polyline points="6 9 12 15 18 9"/>
                                    </svg>
                                </span>
                            </div>
                            <div class="hapvida-field" id="hapvida-field-tipo-plano">
                                <span class="hapvida-field-icon">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                        <polyline points="14 2 14 8 20 8"/>
                                        <line x1="12" y1="18" x2="12" y2="12"/>
                                        <line x1="9" y1="15" x2="15" y2="15"/>
                                    </svg>
                                </span>
                                <select id="hapvida-tipo-plano" name="form_fields[qual_plano]" required autocomplete="off">
                                    <option value="">Tipo de Plano</option>
                                    <option value="individual">Individual/Familiar</option>
                                    <option value="empresarial">Empresarial</option>
                                </select>
                                <span class="hapvida-chevron">
                                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2.5">
                                        <polyline points="6 9 12 15 18 9"/>
                                    </svg>
                                </span>
                            </div>
                        </div>

                        <!-- Quantidade de Pessoas e Idades -->
                        <div class="hapvida-field-row">
                            <div class="hapvida-field" id="hapvida-field-qtd-pessoas">
                                <span class="hapvida-field-icon">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                        <circle cx="9" cy="7" r="4"/>
                                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                                    </svg>
                                </span>
                                <input type="number" id="hapvida-qtd-pessoas" name="form_fields[qtd_pessoas]"
                                    placeholder="Nº Pessoas" value="1" min="1" max="20" required autocomplete="off">
                            </div>
                            <div class="age-inputs no-lazy" id="hapvida-age-inputs">
                                <div class="hapvida-field">
                                    <span class="hapvida-field-icon">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                            <line x1="16" y1="2" x2="16" y2="6"/>
                                            <line x1="8" y1="2" x2="8" y2="6"/>
                                            <line x1="3" y1="10" x2="21" y2="10"/>
                                        </svg>
                                    </span>
                                    <input type="number" name="form_fields[ages][]"
                                        placeholder="Idade 1" min="0" max="120" required autocomplete="off">
                                </div>
                            </div>
                        </div>

                        <!-- Campos ocultos -->
                        <input type="hidden" name="form_fields[data]" value="<?php echo date('Y-m-d H:i:s'); ?>">
                        <input type="hidden" name="form_fields[atendente]" value="">

                        <!-- Botão de Envio -->
                        <button type="submit" class="hapvida-submit-btn no-lazy" id="hapvida-submit-btn">
                            <span class="hapvida-btn-text">Solicitar Cotação</span>
                        </button>

                        <!-- Secure Notice -->
                        <div class="hapvida-secure-notice">
                            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2.5">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                <path d="M9 12l2 2 4-4"/>
                            </svg>
                            <span>Fique tranquilo, seus dados estão seguros</span>
                        </div>
                    </form>
                </div>

                <!-- NOVO MODAL DE SUCESSO MELHORADO -->
                <div id="hapvida-success-modal" class="hapvida-modal-success no-lazy">
                    <div class="hapvida-modal-success-content">
                        <div class="hapvida-modal-success-header">
                            <div class="hapvida-success-check">
                                <div class="hapvida-success-check-circle">
                                    <i class="fas fa-check hapvida-success-check-icon no-lazy"></i>
                                </div>
                            </div>
                            <h3 class="hapvida-modal-success-title" style="color: white;">
                                Formulário Enviado com Sucesso!
                            </h3>

                            <button type="button" class="hapvida-modal-success-close no-lazy" id="hapvida-modal-close">
                                <i class="fas fa-times no-lazy"></i>
                            </button>
                        </div>

                        <div class="hapvida-modal-success-body">
                            <p class="hapvida-success-main-message">
                                <strong>Parabéns!</strong> Seus dados foram recebidos.<br>
                                Você <span class="hapvida-success-highlight">receberá sua cotação</span>
                                através de um dos nossos consultores especializados em instantes!
                            </p>

                            <div class="hapvida-whatsapp-redirect">
                                <div class="hapvida-whatsapp-content">
                                    <i class="fab fa-whatsapp hapvida-whatsapp-icon no-lazy"></i>
                                    <span id="hapvida-redirect-text">
                                        Redirecionando para WhatsApp
                                        <span class="hapvida-loading-dots">
                                            <span></span>
                                            <span></span>
                                            <span></span>
                                        </span>
                                    </span>
                                </div>

                                <!-- NOVO: Link clicável para WhatsApp -->
                                <div id="hapvida-whatsapp-link-container" style="display: none; margin-top: 15px; text-align: center;">
                                    <p style="font-size: 14px; color: #666; margin-bottom: 10px;">
                                        <strong>Não redirecionou automaticamente?</strong><br>
                                        Clique no botão abaixo para falar com nosso consultor:
                                    </p>
                                    <a href="#" id="hapvida-whatsapp-link" target="_blank" style="display: inline-block; background: #25D366; color: white; 
                              padding: 12px 24px; border-radius: 12px; text-decoration: none; 
                              font-weight: 600; font-size: 15px; transition: all 0.3s ease;
                              box-shadow: 0 4px 12px rgba(37, 211, 102, 0.3);">
                                        <i class="fab fa-whatsapp" style="margin-right: 8px;"></i>
                                        Abrir WhatsApp
                                    </a>
                                </div>
                            </div>

                            <div class="hapvida-countdown">
                                Redirecionamento em <span class="hapvida-countdown-number">5</span> segundos
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                            (function ($) {
                                   'use strict';

                                // Evita múltipla inicialização
                                if (window.hapvidaPopupReady) return;
                                window.hapvidaPopupReady = true;

                                var isSubmitted = false;

                                // ====================================================================
                                // NOVA FUNÇÃO DO MODAL DE SUCESSO MELHORADO
                                // ====================================================================
                                window.showSuccessModal = function (redirectUrl) {
                                    console.log('ðŸŽ‰ Mostrando modal de sucesso melhorado');

                                    // Remove qualquer modal anterior
                                    $('.hapvida-modal-success').removeClass('show zoom-entrance');

                                    // Adiciona classe ao body para prevenir scroll
                                    $('body').addClass('modal-open');

                                    // Pega o modal
                                    var $modal = $('#hapvida-success-modal');

                                    // Verifica se o modal existe
                                    if ($modal.length === 0) {
                                        console.error('âŒ Modal de sucesso não encontrado no DOM');
                                        // Fallback: redireciona direto
                                        if (redirectUrl) {
                                            setTimeout(function () {
                                                window.open(redirectUrl, '_blank');
                                            }, 500);
                                        }
                                        return;
                                    }

                                    // Configura o link do WhatsApp
                                    $('#hapvida-whatsapp-link').attr('href', redirectUrl);

                                    // Adiciona classes para mostrar com animação
                                    setTimeout(function () {
                                        $modal.addClass('show zoom-entrance');
                                    }, 10);

                                    // Configuração do countdown
                                    var countdown = 2;
                                    var $countdownNumber = $('.hapvida-countdown-number');
                                    var popupBlocked = false;

                                    // Limpa qualquer interval anterior
                                    if (window.hapvidaCountdownInterval) {
                                        clearInterval(window.hapvidaCountdownInterval);
                                    }

                                    // Atualiza countdown
                                    window.hapvidaCountdownInterval = setInterval(function () {
                                        countdown--;

                                        if (countdown > 0) {
                                            $countdownNumber.text(countdown);

                                            // Adiciona efeito de pulse no número
                                            $countdownNumber.css('transform', 'scale(1.2)');
                                            setTimeout(function () {
                                                $countdownNumber.css('transform', 'scale(1)');
                                            }, 200);
                                        } else {
                                            // Para o countdown
                                            clearInterval(window.hapvidaCountdownInterval);

                                            // Tenta redirecionar para WhatsApp
                                            if (redirectUrl) {
                                                console.log('ðŸ“± Tentando abrir WhatsApp:', redirectUrl);

                                                // Tenta abrir o popup
                                                var newWindow = window.open(redirectUrl, '_blank');

                                                // Detecta se o popup foi bloqueado
                                                setTimeout(function () {
                                                    try {
                                                        if (!newWindow || newWindow.closed || typeof newWindow.closed === 'undefined') {
                                                            // Popup foi bloqueado
                                                            popupBlocked = true;
                                                            console.log('ðŸš« Popup bloqueado - mostrando link alternativo');

                                                            // Mostra mensagem alternativa
                                                            $('#hapvida-redirect-text').html(
                                                                '<i class="fas fa-exclamation-circle" style="color: #f59e0b; margin-right: 8px;"></i>' +
                                                                '<strong style="color: #dc2626;">Popup bloqueado pelo navegador</strong>'
                                                            );

                                                            // Esconde o countdown
                                                            $('.hapvida-countdown').fadeOut(300);

                                                            // Mostra o link clicável
                                                            $('#hapvida-whatsapp-link-container').slideDown(400);

                                                            // NÃO fecha o modal - mantém aberto para o usuário clicar
                                                            console.log('✅ Modal mantido aberto para clique manual');

                                                        } else {
                                                            // Popup abriu com sucesso
                                                            console.log('✅ WhatsApp aberto com sucesso');

                                                            // Fecha o modal após redirecionamento bem-sucedido
                                                            setTimeout(function () {
                                                                closeSuccessModal();
                                                            }, 5000);
                                                        }
                                                    } catch (e) {
                                                        // Erro ao verificar popup - assume bloqueio
                                                        popupBlocked = true;
                                                        console.log('âš ï¸ Erro ao verificar popup - assumindo bloqueio');

                                                        $('#hapvida-redirect-text').html(
                                                            '<i class="fas fa-exclamation-circle" style="color: #f59e0b; margin-right: 8px;"></i>' +
                                                            '<strong style="color: #dc2626;">Não foi possível abrir automaticamente</strong>'
                                                        );

                                                        $('.hapvida-countdown').fadeOut(300);
                                                        $('#hapvida-whatsapp-link-container').slideDown(400);
                                                    }
                                                }, 100);
                                            }
                                        }
                                    }, 1000);

                                    // Som de sucesso (opcional)
                                    try {
                                        playSuccessSound();
                                    } catch (e) {
                                        // Ignora erro de áudio
                                    }

                                    // Vibração no mobile (opcional)
                                    if ('vibrate' in navigator) {
                                        navigator.vibrate([100, 50, 100]);
                                    }
                                };

                                // Função para fechar o modal de sucesso
                                window.closeSuccessModal = function () {
                                    console.log('ðŸ”š Fechando modal de sucesso');

                                    var $modal = $('#hapvida-success-modal');

                                    // Para o countdown se estiver rodando
                                    if (window.hapvidaCountdownInterval) {
                                        clearInterval(window.hapvidaCountdownInterval);
                                    }

                                    // Remove classes
                                    $modal.removeClass('show');
                                    $('body').removeClass('modal-open');

                                    // Remove completamente após animação
                                    setTimeout(function () {
                                        $modal.removeClass('zoom-entrance');
                                        // Reset do countdown
                                        $('.hapvida-countdown-number').text('3');
                                    }, 400);
                                };

                                // Som de sucesso (opcional)
                                function playSuccessSound() {
                                    var audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBSl+zPPTgjMGHm7A7+OZURE');
                                    audio.volume = 0.1;
                                    audio.play().catch(function () {
                                        // Ignora erro se autoplay for bloqueado
                                    });
                                }

                                // ====================================================================
                                // VALIDAÇÃO DE TELEFONE SIMPLIFICADA
                                // ====================================================================
                                function validatePhoneNumber(phone) {
                                    const cleanPhone = phone.replace(/\D/g, '');

                                    if (cleanPhone.length >= 10 && cleanPhone.length <= 11) {
                                        const ddd = cleanPhone.substring(0, 2);

                                        if (parseInt(ddd) >= 11 && parseInt(ddd) <= 99) {
                                            return {
                                                valid: true,
                                                format: cleanPhone.length === 11 ? 'celular' : 'residencial',
                                                clean: cleanPhone
                                            };
                                        }
                                    }

                                    return { valid: false, format: null, clean: cleanPhone };
                                }

                                function getPhoneValidationMessage(phoneResult) {
                                    if (phoneResult.valid) return '';

                                    const length = phoneResult.clean.length;

                                    if (length === 0) {
                                        return 'Por favor, digite seu número de telefone';
                                    } else if (length < 10) {
                                        return `Número muito curto. Digite o DDD + número (faltam ${10 - length} dígitos)`;
                                    } else if (length > 11) {
                                        return `Número muito longo (${length} dígitos). Máximo: 11 dígitos`;
                                    } else {
                                        const ddd = phoneResult.clean.substring(0, 2);
                                        if (parseInt(ddd) < 11 || parseInt(ddd) > 99) {
                                            return 'DDD inválido. Use um DDD entre 11 e 99';
                                        }
                                    }

                                    return 'Formato inválido. Use: (DD) XXXX-XXXX';
                                }

                                function formatPhoneDisplay(phone) {
                                    const clean = phone.replace(/\D/g, '');

                                    if (clean.length >= 10) {
                                        if (clean.length === 10) {
                                            return `(${clean.substring(0, 2)}) ${clean.substring(2, 6)}-${clean.substring(6)}`;
                                        } else if (clean.length === 11) {
                                            return `(${clean.substring(0, 2)}) ${clean.substring(2, 7)}-${clean.substring(7)}`;
                                        }
                                    }

                                    return phone;
                                }

                                // ====================================================================
                                // MENSAGENS DE ERRO MELHORADAS
                                // ====================================================================
                                function getImprovedErrorMessage(errorMessage) {
                                    if (errorMessage.includes('telefone já enviou um formulário') ||
                                        errorMessage.includes('já foi processado recentemente') ||
                                        errorMessage.includes('já está sendo processado')) {

                                        return {
                                            title: 'ðŸ“ž Formulário Já Enviado',
                                            message: `
                    <div style="text-align: center; padding: 20px; font-family: Arial, sans-serif;">
                        <div style="font-size: 48px; color: #0054B8; margin-bottom: 15px;">â°</div>
                        <h3 style="color: #0054B8; margin-bottom: 15px;">
                            Seus dados já foram enviados com sucesso!
                        </h3>
                        <p style="font-size: 16px; color: #333; line-height: 1.5; margin-bottom: 20px;">
                            <strong>Não se preocupe!</strong> Suas informações já estão com nossa equipe de consultores.
                        </p>
                        <div style="background: #f8f9ff; border: 2px solid #0054B8; border-radius: 12px; padding: 20px; margin: 20px 0;">
                            <p style="margin: 0; color: #0054B8; font-weight: bold; font-size: 18px;">
                                ðŸŽ¯ Em instantes, um de nossos consultores especializados entrará em contato pelo WhatsApp!
                            </p>
                        </div>
                        <p style="font-size: 14px; color: #666; margin-bottom: 15px;">
                            <strong>Tempo médio de resposta:</strong> 5 a 15 minutos
                        </p>
                        <p style="font-size: 14px; color: #666;">
                            Se não receber contato em 30 minutos, pode enviar o formulário novamente.
                        </p>
                    </div>
                `,
                                            type: 'info'
                                        };
                                    }

                                    return {
                                        title: 'âŒ Erro',
                                        message: `<div style="text-align: center; padding: 20px;">${errorMessage}</div>`,
                                        type: 'error'
                                    };
                                }

                                function showImprovedModal(config) {
                                    const { title, message, type = 'info' } = config;

                                    const colors = {
                                        'info': { bg: '#0054B8', text: '#ffffff' },
                                        'error': { bg: '#dc3545', text: '#ffffff' },
                                        'warning': { bg: '#ffc107', text: '#000000' },
                                        'success': { bg: '#28a745', text: '#ffffff' }
                                    };

                                    const color = colors[type] || colors.info;

                                    // Remove modal anterior
                                    $('#hapvida-improved-modal').remove();

                                    const modalHtml = `
            <div id="hapvida-improved-modal" class="hapvida-modal-success show no-lazy" style="z-index: 999999;">
                <div class="hapvida-modal-success-content" style="max-width: 500px;">
                    <div class="hapvida-modal-success-header" style="background: ${color.bg}; color: ${color.text};">
                        <h3 style="margin: 0; font-size: 18px;">${title}</h3>
                        <button type="button" class="hapvida-modal-success-close hapvida-close-btn no-lazy">
                            <i class="fas fa-times no-lazy"></i>
                        </button>
                    </div>
                    <div class="hapvida-modal-success-body">
                        ${message}
                        <div style="text-align: center; margin-top: 20px;">
                            <button type="button" class="hapvida-close-btn no-lazy" 
                                    style="background: ${color.bg}; color: ${color.text}; border: none; padding: 12px 30px; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 14px;">
                                Entendi
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

                                    $('body').append(modalHtml);

                                    // Event delegation para fechar modal
                                    $(document).off('click.hapvidaModal').on('click.hapvidaModal', '.hapvida-close-btn', function (e) {
                                        e.preventDefault();
                                        e.stopPropagation();
                                        $('#hapvida-improved-modal').remove();
                                    });

                                    // Fechar clicando fora do modal
                                    $(document).off('click.hapvidaModalBg').on('click.hapvidaModalBg', '#hapvida-improved-modal', function (e) {
                                        if (e.target === this) {
                                            $(this).remove();
                                        }
                                    });

                                    // Fechar com ESC
                                    $(document).off('keydown.hapvidaModal').on('keydown.hapvidaModal', function (e) {
                                        if (e.keyCode === 27) {
                                            $('#hapvida-improved-modal').remove();
                                        }
                                    });
                                }

                                // ====================================================================
                                // SETUP DO CAMPO DE TELEFONE
                                // ====================================================================
                                $(document).on('focus', '#hapvida-telefone', function () {
                                    $(this).removeAttr('readonly');
                                });

                                function setupPhoneFormatting() {
                                    $(document).on('input', '#hapvida-telefone', function () {
                                        let value = $(this).val().replace(/\D/g, '');
                                        let formatted = '';

                                        if (value.length >= 2) {
                                            formatted = `(${value.substring(0, 2)}`;

                                            if (value.length > 2) {
                                                if (value.length <= 6) {
                                                    formatted += `) ${value.substring(2)}`;
                                                } else if (value.length <= 10) {
                                                    formatted += `) ${value.substring(2, 6)}-${value.substring(6)}`;
                                                } else if (value.length === 11) {
                                                    formatted += `) ${value.substring(2, 7)}-${value.substring(7)}`;
                                                } else {
                                                    value = value.substring(0, 11);
                                                    formatted += `) ${value.substring(2, 7)}-${value.substring(7)}`;
                                                }
                                            }
                                        } else {
                                            formatted = value;
                                        }

                                        $(this).val(formatted);
                                        $(this).closest('.hapvida-field').removeClass('error');
                                        $(this).closest('.hapvida-field').next('.phone-error-message').remove();
                                    });

                                    $(document).on('focus', '#hapvida-telefone', function () {
                                        if (!$(this).val()) {
                                            $(this).attr('placeholder', '(11) 1234-5678');
                                        }
                                    });

                                    $(document).on('blur', '#hapvida-telefone', function () {
                                        if (!$(this).val()) {
                                            $(this).attr('placeholder', '(00) 00000-0000');
                                        }
                                    });
                                }

                                function preventPhoneAutofill() {
                                    $(document).on('focus blur change', '#hapvida-telefone', function () {
                                        var $field = $(this);

                                        if ($field.val() && !$field.data('user-typed')) {
                                            setTimeout(function () {
                                                $field.val('').removeAttr('readonly');
                                                $field.attr('placeholder', 'Digite seu WhatsApp');
                                            }, 100);
                                        }
                                    });

                                    $(document).on('keydown input', '#hapvida-telefone', function () {
                                        $(this).data('user-typed', true);
                                    });

                                    setTimeout(function () {
                                        var $phone = $('#hapvida-telefone');
                                        if ($phone.length && $phone.val() && !$phone.data('user-typed')) {
                                            $phone.val('').removeAttr('readonly');
                                        }
                                    }, 1000);
                                }

                                // ====================================================================
                                // EVENT DELEGATION GLOBAL - FUNCIONA COM POPUPS
                                // ====================================================================
                                $(document).on('submit', '#hapvida-main-form', function (e) {
                                    e.preventDefault();

                                    if (isSubmitted) {
                                        return false;
                                    }

                                    var $form = $(this);

                                    if (!validateFormImproved($form)) {
                                        return false;
                                    }

                                    isSubmitted = true;
                                    submitFormImproved($form);
                                });

                                $(document).on('change input', '#hapvida-qtd-pessoas, [name="form_fields[qtd_pessoas]"]', function () {
                                    updateAgeFields($(this));
                                });

                                // Event listeners para o novo modal de sucesso
                                $(document).on('click', '#hapvida-modal-close', function (e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    closeSuccessModal();
                                });

                                $(document).on('click', '#hapvida-success-modal', function (e) {
                                    if ($(e.target).hasClass('hapvida-modal-success')) {
                                        closeSuccessModal();
                                    }
                                });

                                // Fechar com ESC
                                $(document).keyup(function (e) {
                                    if (e.key === "Escape" && $('#hapvida-success-modal').hasClass('show')) {
                                        closeSuccessModal();
                                    }
                                });

                                // Observer para detectar formulários em popups
                                var observer = new MutationObserver(function (mutations) {
                                    mutations.forEach(function (mutation) {
                                        if (mutation.type === 'childList') {
                                            mutation.addedNodes.forEach(function (node) {
                                                if (node.nodeType === 1) {
                                                    var $forms = $(node).find('#hapvida-main-form, form[id*="hapvida"]');
                                                    if ($forms.length) {
                                                        $forms.each(function () {
                                                            var $form = $(this);
                                                            if (!$form.data('popup-ready')) {
                                                                initializeForm($form);

                                                                setTimeout(function () {
                                                                    var $qtdField = $form.find('#hapvida-qtd-pessoas, [name="form_fields[qtd_pessoas]"]');
                                                                    if ($qtdField.length) {
                                                                        updateAgeFields($qtdField);
                                                                    }
                                                                }, 100);
                                                            }
                                                        });
                                                    }

                                                    if ((node.id === 'hapvida-main-form' || node.tagName === 'FORM') &&
                                                        !$(node).data('popup-ready')) {
                                                        initializeForm($(node));

                                                        setTimeout(function () {
                                                            var $qtdField = $(node).find('#hapvida-qtd-pessoas, [name="form_fields[qtd_pessoas]"]');
                                                            if ($qtdField.length) {
                                                                updateAgeFields($qtdField);
                                                            }
                                                        }, 100);
                                                    }
                                                }
                                            });
                                        }
                                    });
                                });

                                observer.observe(document.body, {
                                    childList: true,
                                    subtree: true
                                });

                                // ====================================================================
                                // INICIALIZAÇÃO DE FORMULÃRIO
                                // ====================================================================
                                function initializeForm($form) {
                                    $form.data('popup-ready', true);

                                    var $qtdPessoas = $form.find('#hapvida-qtd-pessoas');
                                    if (!$qtdPessoas.length) {
                                        $qtdPessoas = $form.find('[name="form_fields[qtd_pessoas]"]');
                                    }

                                    if ($qtdPessoas.length) {
                                        if ($qtdPessoas.val()) {
                                            updateAgeFields($qtdPessoas);
                                        } else {
                                            $qtdPessoas.val('1');
                                            updateAgeFields($qtdPessoas);
                                        }
                                    }
                                }

                                // ====================================================================
                                // VALIDAÇÃO MELHORADA DO FORMULÃRIO
                                // ====================================================================
                                function validateFormImproved($form) {
                                    let isValid = true;
                                    let firstErrorField = null;

                                    $form.find('[required]').each(function () {
                                        const $field = $(this);
                                        const fieldValue = $field.val().trim();

                                        $field.closest('.hapvida-field').removeClass('error');

                                        if (!fieldValue) {
                                            $field.closest('.hapvida-field').addClass('error');
                                            if (!firstErrorField) firstErrorField = $field;
                                            isValid = false;
                                        }
                                    });

                                    const $phoneField = $form.find('#hapvida-telefone');
                                    if ($phoneField.length) {
                                        const phoneValue = $phoneField.val().trim();
                                        const phoneResult = validatePhoneNumber(phoneValue);

                                        if (!phoneResult.valid) {
                                            $phoneField.closest('.hapvida-field').addClass('error');
                                            const message = getPhoneValidationMessage(phoneResult);

                                            $phoneField.closest('.hapvida-field').find('.phone-error-message').remove();

                                            const errorHtml = `
                    <div class="phone-error-message" style="
                        color: #dc3545; 
                        font-size: 12px; 
                        margin-top: 5px; 
                        padding: 8px 12px;
                        background: rgba(220, 53, 69, 0.1);
                        border-radius: 8px;
                        border-left: 3px solid #dc3545;
                    ">
                        <i class="fas fa-exclamation-triangle" style="margin-right: 8px;"></i>
                        ${message}
                    </div>
                `;
                                            $phoneField.closest('.hapvida-field').after(errorHtml);

                                            if (!firstErrorField) firstErrorField = $phoneField;
                                            isValid = false;
                                        } else {
                                            $phoneField.closest('.hapvida-field').next('.phone-error-message').remove();

                                            const formattedPhone = formatPhoneDisplay(phoneValue);
                                            if (formattedPhone !== phoneValue) {
                                                $phoneField.val(formattedPhone);
                                            }
                                        }
                                    }

                                    if (!isValid && firstErrorField) {
                                        firstErrorField.focus();

                                        if (firstErrorField.offset()) {
                                            $('html, body').animate({
                                                scrollTop: firstErrorField.offset().top - 100
                                            }, 300);
                                        }
                                    }

                                    if (isValid) {
                                        const qtdPessoas = parseInt($form.find('#hapvida-qtd-pessoas').val()) || 1;
                                        let filledAges = 0;

                                        $form.find('[name="form_fields[ages][]"]').each(function () {
                                            if ($(this).val().trim()) filledAges++;
                                        });

                                        if (filledAges < qtdPessoas) {
                                            showImprovedModal({
                                                title: 'ðŸ‘¨â€ðŸ‘©â€ðŸ‘§â€ðŸ‘¦ Idades Incompletas',
                                                message: `
                        <div style="text-align: center; padding: 20px;">
                            <div style="font-size: 48px; color: #ffc107; margin-bottom: 15px;">âš ï¸</div>
                            <h3 style="color: #ffc107; margin-bottom: 15px;">Informação Incompleta</h3>
                            <p style="font-size: 16px; margin-bottom: 20px;">
                                Você selecionou <strong>${qtdPessoas} pessoa(s)</strong>, 
                                mas preencheu apenas <strong>${filledAges} idade(s)</strong>.
                            </p>
                            <div style="background: #fff3cd; border: 2px solid #ffc107; border-radius: 12px; padding: 15px;">
                                <p style="margin: 0; color: #856404; font-weight: bold;">
                                    ðŸ“ Por favor, preencha a idade de todas as pessoas para uma cotação precisa!
                                </p>
                            </div>
                        </div>
                    `,
                                                type: 'warning'
                                            });
                                            isValid = false;
                                        }
                                    }

                                    return isValid;
                                }

                                // ====================================================================
                                // ENVIO MELHORADO DO FORMULÃRIO COM NOVO MODAL
                                // ====================================================================
                                function submitFormImproved($form) {
                                    var $btn = $form.find('#hapvida-submit-btn');
                                    var originalText = $btn.html();

                                    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Enviando...');

                                    var formData = {};

                                    $form.serializeArray().forEach(function (field) {
                                        if (field.name.includes('[]')) {
                                            var cleanName = field.name.replace('[]', '');
                                            if (!formData[cleanName]) {
                                                formData[cleanName] = [];
                                            }
                                            if (field.value && field.value.trim() !== '') {
                                                formData[cleanName].push(field.value.trim());
                                            }
                                        } else {
                                            formData[field.name] = field.value;
                                        }
                                    });

                                    const phoneField = formData['form_fields[telefone]'];
                                    if (phoneField) {
                                        formData['form_fields[telefone]'] = phoneField.replace(/\D/g, '');
                                    }

                                    // Envia o formulário
                                    $.ajax({
                                        url: '<?php echo esc_url_raw(rest_url('formulario-hapvida/v1/submit-form')); ?>',
                                        method: 'POST',
                                        data: formData,
                                        timeout: 30000,

                                        success: function (response) {
                                            if (response.success && response.whatsapp_url) {
                                                // *** NOVO: Redireciona para página de obrigado em vez de mostrar modal ***
                                                console.log('✅ Formulário enviado com sucesso, redirecionando...');

                                                // Armazena URL do WhatsApp no sessionStorage
                                                sessionStorage.setItem('hapvida_whatsapp_url', response.whatsapp_url);

                                                // Armazena informações do vendedor
                                                if (response.vendor_info) {
                                                    sessionStorage.setItem('hapvida_vendor_info', JSON.stringify(response.vendor_info));
                                                }

                                                // Redireciona para página de obrigado COM URL do WhatsApp como parâmetro GET
                                                // Página de produção
                                                var thankYouUrl = 'https://tabelaplanos.com.br/obrigado/?whatsapp=' + encodeURIComponent(response.whatsapp_url);
                                                window.location.href = thankYouUrl;

                                            } else {
                                                handleImprovedError(response.message || 'Erro desconhecido');
                                            }

                                            $btn.prop('disabled', false).html(originalText);
                                            setTimeout(function () {
                                                isSubmitted = false;
                                            }, 2000);
                                        },

                                        error: function (xhr, status, error) {
                                            let serverMessage = 'Erro de conexão. Tente novamente.';
                                            if (xhr.responseJSON && xhr.responseJSON.message) {
                                                serverMessage = xhr.responseJSON.message;
                                            }

                                            handleImprovedError(serverMessage);
                                            $btn.prop('disabled', false).html(originalText);
                                            setTimeout(function () {
                                                isSubmitted = false;
                                            }, 2000);
                                        }
                                    });
                                }

                                // ====================================================================
                                // TRATAMENTO DE ERRO
                                // ====================================================================
                                function handleImprovedError(errorMessage) {
                                    const improvedError = getImprovedErrorMessage(errorMessage);
                                    showImprovedModal(improvedError);

                                    isSubmitted = false;
                                }

                                // ====================================================================
                                // ATUALIZAR CAMPOS DE IDADE
                                // ====================================================================
                                function updateAgeFields($qtdInput) {
                                    var qtd = parseInt($qtdInput.val()) || 1;

                                    var $container = $qtdInput.closest('form').find('#hapvida-age-inputs');

                                    if (!$container.length) {
                                        $container = $qtdInput.closest('form').find('.age-inputs');
                                    }

                                    if (!$container.length) {
                                        $container = $('#hapvida-age-inputs');
                                        if (!$container.length) {
                                            $container = $('.age-inputs');
                                        }
                                    }

                                    if (!$container.length) {
                                        return;
                                    }

                                    $container.empty();

                                    for (var i = 1; i <= qtd; i++) {
                                        var $wrapper = $('<div class="hapvida-field">');
                                        var ageSvg = '<span class="hapvida-field-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>';
                                        $wrapper.append(ageSvg);
                                        var $input = $('<input type="number" name="form_fields[ages][]" min="0" max="120" required autocomplete="off">');
                                        $input.attr('placeholder', 'Idade ' + i);
                                        $wrapper.append($input);
                                        $container.append($wrapper);
                                    }
                                }

                                // ====================================================================
                                // INICIALIZAÇÃO COMPLETA
                                // ====================================================================
                                $(document).ready(function () {
                                    setupPhoneFormatting();
                                    preventPhoneAutofill();

                                    var $existingForm = $('#hapvida-main-form');
                                    if ($existingForm.length) {
                                        initializeForm($existingForm);
                                    }

                                    var attempts = 0;
                                    var maxAttempts = 50;

                                    var polling = setInterval(function () {
                                        attempts++;
                                        var $forms = $('#hapvida-main-form, form[id*="hapvida"]');

                                        $forms.each(function () {
                                            var $form = $(this);
                                            if (!$form.data('popup-ready')) {
                                                initializeForm($form);

                                                setTimeout(function () {
                                                    var $qtdField = $form.find('#hapvida-qtd-pessoas, [name="form_fields[qtd_pessoas]"]');
                                                    if ($qtdField.length) {
                                                        $qtdField.val('1');
                                                        updateAgeFields($qtdField);
                                                    }
                                                }, 200);
                                            }
                                        });

                                        if ($forms.length > 0 || attempts >= maxAttempts) {
                                            clearInterval(polling);
                                        }
                                    }, 100);
                                });

                            })(jQuery);
                        </script>

                        <?php
                        return ob_get_clean();
    }
    // FIM DO SHORTCODE

    // ====================================================================
    // AUTO-ATIVAÇÃO SEU SOUZA (Dias úteis, 08h-12h)
    // ====================================================================

    public function add_auto_activate_cron_interval($schedules)
    {
        if (!isset($schedules['hapvida_thirty_minutes'])) {
            $schedules['hapvida_thirty_minutes'] = array(
                'interval' => 1800,
                'display' => 'A cada 30 minutos (Hapvida Auto-Ativação)'
            );
        }
        return $schedules;
    }

    public function schedule_auto_activate_seu_souza()
    {
        $auto_activate = get_option('hapvida_auto_activate_seu_souza', false);

        if ($auto_activate) {
            if (!wp_next_scheduled('hapvida_auto_activate_seu_souza')) {
                wp_schedule_event(time(), 'hapvida_thirty_minutes', 'hapvida_auto_activate_seu_souza');
            }
            if (!wp_next_scheduled('hapvida_auto_deactivate_seu_souza')) {
                wp_schedule_event(time(), 'hapvida_thirty_minutes', 'hapvida_auto_deactivate_seu_souza');
            }
        } else {
            $ts1 = wp_next_scheduled('hapvida_auto_activate_seu_souza');
            if ($ts1) wp_unschedule_event($ts1, 'hapvida_auto_activate_seu_souza');
            $ts2 = wp_next_scheduled('hapvida_auto_deactivate_seu_souza');
            if ($ts2) wp_unschedule_event($ts2, 'hapvida_auto_deactivate_seu_souza');
        }
    }

    public function auto_activate_seu_souza()
    {
        if (!get_option('hapvida_auto_activate_seu_souza', false)) return;

        $current_hour = (int) current_time('G');
        $current_day = (int) current_time('N');

        if ($current_day >= 1 && $current_day <= 5 && $current_hour >= 8 && $current_hour < 12) {
            $vendedores = get_option($this->vendedores_option, array('drv' => array(), 'seu_souza' => array()));
            if (!isset($vendedores['seu_souza']) || !is_array($vendedores['seu_souza'])) return;

            $changed = false;
            foreach ($vendedores['seu_souza'] as &$v) {
                if (is_array($v) && isset($v['status']) && $v['status'] === 'inativo') {
                    $v['status'] = 'ativo';
                    $changed = true;
                }
            }
            unset($v);

            if ($changed) {
                update_option($this->vendedores_option, $vendedores);
                $this->log("AUTO-ATIVAÇÃO: Vendedores Seu Souza ATIVADOS (dia útil, {$current_hour}h)");
            }
        }
    }

    public function auto_deactivate_seu_souza()
    {
        if (!get_option('hapvida_auto_activate_seu_souza', false)) return;

        $current_hour = (int) current_time('G');
        $current_day = (int) current_time('N');

        $is_weekend = ($current_day >= 6);
        $is_outside_hours = ($current_hour < 8 || $current_hour >= 12);

        if ($is_weekend || $is_outside_hours) {
            $vendedores = get_option($this->vendedores_option, array('drv' => array(), 'seu_souza' => array()));
            if (!isset($vendedores['seu_souza']) || !is_array($vendedores['seu_souza'])) return;

            $changed = false;
            foreach ($vendedores['seu_souza'] as &$v) {
                if (is_array($v) && isset($v['status']) && $v['status'] === 'ativo') {
                    $v['status'] = 'inativo';
                    $changed = true;
                }
            }
            unset($v);

            if ($changed) {
                update_option($this->vendedores_option, $vendedores);
                $reason = $is_weekend ? 'fim de semana' : "fora do horário ({$current_hour}h)";
                $this->log("AUTO-DESATIVAÇÃO: Vendedores Seu Souza DESATIVADOS ({$reason})");
            }
        }
    }

    public function ajax_toggle_auto_activate_seu_souza()
    {
        check_ajax_referer('vendedores_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'true';
        update_option('hapvida_auto_activate_seu_souza', $enabled);
        $this->schedule_auto_activate_seu_souza();

        if ($enabled) {
            $this->auto_activate_seu_souza();
            $this->auto_deactivate_seu_souza();
        }

        wp_send_json_success(array(
            'message' => $enabled ? 'Auto-ativação Seu Souza ATIVADA' : 'Auto-ativação Seu Souza DESATIVADA',
            'enabled' => $enabled
        ));
    }

    private function get_daily_submission_count()
    {
        $daily_submissions = get_option('formulario_hapvida_daily_submissions', array());
        $today = current_time('Y-m-d');

        return isset($daily_submissions[$today]) ? $daily_submissions[$today] : 0;
    }

    /**
     * *** MÉTODO AUXILIAR: get_monthly_submission_count ***
     */
    private function get_monthly_submission_count()
    {
        $monthly_submissions = get_option('formulario_hapvida_monthly_submissions', array());
        $current_month = current_time('Y-m');

        return isset($monthly_submissions[$current_month]) ? $monthly_submissions[$current_month] : 0;
    }

    /**
     * *** MÉTODO AUXILIAR: get_current_timeout ***
     */
    private function get_current_timeout()
    {
        $options = get_option('formulario_hapvida_settings');

        // Verifica horário comercial via lead tracking se disponível
        global $formulario_hapvida_lead_tracking;
        if ($formulario_hapvida_lead_tracking && method_exists($formulario_hapvida_lead_tracking, 'is_horario_comercial')) {
            $is_business_hours = $formulario_hapvida_lead_tracking->is_horario_comercial();
        } else {
            // Fallback simples - considera horário comercial das 8h Ã s 18h
            $current_hour = intval(current_time('H'));
            $is_business_hours = ($current_hour >= 8 && $current_hour < 18);
        }

        if ($is_business_hours) {
            return isset($options['redistribution_timeout_weekdays']) ?
                intval($options['redistribution_timeout_weekdays']) : 10;
        } else {
            return isset($options['redistribution_timeout_weekends']) ?
                intval($options['redistribution_timeout_weekends']) : 30;
        }
    }

    /**
     * *** MÉTODO AUXILIAR: store_form_origin ***
     */
    private function store_form_origin($form_data)
    {
        if (isset($form_data['pagina_origem'])) {
            return $form_data['pagina_origem'];
        }

        // Tenta detectar origem baseada no HTTP_REFERER
        $origem = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';

        if (empty($origem)) {
            $origem = home_url();
        }

        return $origem;
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
        error_log("=== INSTÃ‚NCIA ÚNICA DO FORMULÃRIO HAPVIDA CRIADA ===");
    }

    return $instance;
}

// Singleton para o lead tracking

// Substitua esta parte no final do formulario-hapvida.php
function get_lead_tracking_instance()
{
    static $instance = null;
    static $initialized = false;

    // PROTEÇÃO: Se já foi inicializado, retorna a instância existente
    if ($initialized) {
        return $instance;
    }

    // PROTEÇÃO: Verifica se já existe globalmente
    if (isset($GLOBALS['formulario_hapvida_lead_tracking'])) {
        $initialized = true;
        return $GLOBALS['formulario_hapvida_lead_tracking'];
    }

    if ($instance === null) {
        $lead_tracking_file = plugin_dir_path(__FILE__) . 'lead-tracking.php';
        if (file_exists($lead_tracking_file)) {
            if (!class_exists('Formulario_Hapvida_Lead_Tracking')) {
                require_once $lead_tracking_file;
            }
            $instance = new Formulario_Hapvida_Lead_Tracking();
            $GLOBALS['formulario_hapvida_lead_tracking'] = $instance;
            $initialized = true;
            error_log("=== INSTÃ‚NCIA ÚNICA DO LEAD TRACKING CRIADA ===");
        }
    }

    return $instance;
}

// CORREÇÃO: Inicializa apenas se não existir
if (!isset($GLOBALS['formulario_hapvida_lead_tracking'])) {
    $GLOBALS['formulario_hapvida_lead_tracking'] = get_lead_tracking_instance();
}

// Inicializa as instâncias apenas uma vez
if (!isset($GLOBALS['formulario_hapvida'])) {
    get_formulario_hapvida_instance();
}

if (!isset($GLOBALS['formulario_hapvida_lead_tracking'])) {
    get_lead_tracking_instance();
}

// Inclui o sistema de limpeza automática apenas uma vez
$cleanup_file = plugin_dir_path(__FILE__) . 'webhook-cleanup.php';
if (file_exists($cleanup_file) && !class_exists('Formulario_Hapvida_Webhook_Cleanup')) {
    require_once $cleanup_file;
}


add_action('rest_api_init', function () {
    global $formulario_hapvida_lead_tracking;
    if ($formulario_hapvida_lead_tracking && method_exists($formulario_hapvida_lead_tracking, 'register_confirmation_endpoint')) {
        $formulario_hapvida_lead_tracking->register_confirmation_endpoint();
    }
}, 5); // Prioridade alta

// REMOVIDO: Registro duplicado no hook 'init' causava erro
// Rotas REST devem ser registradas apenas em 'rest_api_init'