<?php
if (!defined('ABSPATH')) exit;

trait RestApiTrait {

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

    public function handle_external_cron_request($request)
    {
        $start_time = microtime(true);

        $this->log("=== ðŸ• CRON EXTERNO EXECUTADO ===");
        $this->log("Horário: " . current_time('d/m/Y H:i:s'));
        $this->log("IP do cliente: " . $this->get_client_ip());

        try {

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
}
