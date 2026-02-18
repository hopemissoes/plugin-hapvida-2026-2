<?php
if (!defined('ABSPATH')) exit;

trait AjaxHandlersTrait {

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
            'webhook_url_drv_confirmation' => 'DRV - Confirmação',
            'webhook_url_seu_souza' => 'Seu Souza - Primeiro Envio',
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

        if (!$required_drv) {
            $debug_info .= "âŒ ERRO CRÃTICO: Nenhuma URL de webhook configurada para DRV!\n";
            $debug_info .= "   É necessário configurar pelo menos uma URL para o grupo DRV.\n";
        } else {
            $debug_info .= "✅ Configurações básicas OK para DRV\n";
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
            $webhook_url = isset($options['webhook_url_drv']) ? $options['webhook_url_drv'] : '';

            $debug_info .= "   - URL DRV: " . (isset($options['webhook_url_drv']) ?
                substr($options['webhook_url_drv'], 0, 50) . "..." : 'NÃO CONFIGURADO') . "\n";

        } elseif ($grupo === 'seu_souza') {
            $webhook_url = isset($options['webhook_url_seu_souza']) ? $options['webhook_url_seu_souza'] : '';

            $debug_info .= "   - URL Seu Souza: " . (isset($options['webhook_url_seu_souza']) ?
                substr($options['webhook_url_seu_souza'], 0, 50) . "..." : 'NÃO CONFIGURADO') . "\n";
        }

        if (!empty($webhook_url)) {
            $debug_info .= "\n✅ URL de webhook encontrada\n";
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

    public function handle_admin_debug_actions()
    {
        // Verifica se é uma ação de debug

        if (isset($_GET['verify_webhook_config']) && current_user_can('manage_options')) {
            $this->verify_webhook_configuration();
        }
    }
}
