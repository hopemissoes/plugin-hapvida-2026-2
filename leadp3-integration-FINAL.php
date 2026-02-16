<?php
/**
 * Integração com API LeadP3 - VERSÃO SIMPLIFICADA COM DEBUG DETALHADO
 * 
 * Envio assíncrono SEM sistema de retry
 * 
 * @version 2.1 - Com logs de status HTTP
 */

if (!defined('ABSPATH')) {
    exit;
}

class Formulario_Hapvida_LeadP3_Integration {
    
    private $api_url = 'https://dremvendas.com.br/api/leadsp3/salvar';
    private $api_token = 'augnLRE2lWYtb6a1zU6IJ6iNyF6wcL95d2C1d3QKpm8AMUYe9EHVpX6j2jjODPtd';
    private $log_file;
    
    public function __construct() {
        $this->log_file = WP_CONTENT_DIR . '/hapvida_leadp3_integration.log';
        
        // APENAS processamento de fila simples
        add_action('hapvida_process_leadp3_queue', array($this, 'process_leadp3_queue'));
        
    }
    
    /**
     * Envia dados para API LeadP3 (sempre assíncrono)
     */
    public function send_to_leadp3($form_data, $vendedor, $force_sync = false) {
        try {
            $lead_id = $form_data['lead_id'] ?? 'N/A';
            $this->log("🚀 [LEAD: {$lead_id}] Iniciando envio para LeadP3");
            
            $leadp3_data = $this->prepare_leadp3_data($form_data, $vendedor);
            
            if (!$this->validate_leadp3_data($leadp3_data)) {
                throw new Exception('Dados obrigatórios ausentes');
            }
            
            $this->log("📋 [LEAD: {$lead_id}] Dados preparados: " . json_encode($leadp3_data, JSON_UNESCAPED_UNICODE));
            
            // SEMPRE assíncrono (a menos que force_sync seja true)
            if (!$force_sync) {
                $this->enqueue_for_processing($form_data, $vendedor);
                $this->send_request_non_blocking($leadp3_data, $lead_id);
                
                if (!wp_next_scheduled('hapvida_process_leadp3_queue')) {
                    wp_schedule_single_event(time() + 5, 'hapvida_process_leadp3_queue');
                }
                
                $this->log("📋 [LEAD: {$lead_id}] Enfileirado com sucesso (fire-and-forget)");
                return true;
            }
            
            // Envio síncrono (somente se force_sync = true)
            $this->log("⚙️ [LEAD: {$lead_id}] Modo SÍNCRONO ativado");
            $response = $this->send_request($leadp3_data, $lead_id);
            
            if ($response['success']) {
                $this->log("✅ [LEAD: {$lead_id}] Enviado com sucesso - Status: {$response['status']}");
                return true;
            }
            
            throw new Exception($response['error'] ?? 'Erro desconhecido');
            
        } catch (Exception $e) {
            $this->log("❌ [LEAD: {$lead_id}] ERRO: " . $e->getMessage());
            $this->send_failure_email($form_data, $vendedor, $e->getMessage());
            return false;
        }
    }
    
    /**
     * Enfileira para processamento
     */
    private function enqueue_for_processing($form_data, $vendedor) {
        $queue = get_option('hapvida_leadp3_queue', array());
        
        $queue[] = array(
            'form_data' => $form_data,
            'vendedor' => $vendedor,
            'timestamp' => current_time('timestamp')
        );
        
        // Limita a 100 itens
        if (count($queue) > 100) {
            $queue = array_slice($queue, -100);
        }
        
        update_option('hapvida_leadp3_queue', $queue, false);
    }
    
    /**
     * Envio não-bloqueante (fire and forget)
     */
    private function send_request_non_blocking($data, $lead_id) {
        $this->log("🌐 [LEAD: {$lead_id}] Disparando requisição não-bloqueante para: " . $this->api_url);
        
        wp_remote_post($this->api_url, array(
            'method'    => 'POST',
            'timeout'   => 0.01,
            'blocking'  => false,
            'headers'   => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'FormularioHapvida/2.1'
            ),
            'body'      => json_encode($data, JSON_UNESCAPED_UNICODE),
            'sslverify' => false
        ));
        
        $this->log("🚀 [LEAD: {$lead_id}] Requisição disparada (não aguarda resposta)");
    }
    
    /**
     * Prepara dados para LeadP3
     */
    private function prepare_leadp3_data($form_data, $vendedor) {
        $vidas = isset($form_data['qtd_pessoas']) ? intval($form_data['qtd_pessoas']) : 1;
        $telefone = isset($form_data['telefone']) ? preg_replace('/[^0-9]/', '', $form_data['telefone']) : '';
        $tipo = (strtolower($vendedor['grupo'] ?? '') === 'drv') ? 'Empresarial' : 'Adesão';
        
        return array(
            'id_leadp3'      => $form_data['lead_id'] ?? uniqid('lead_'),
            'nome'           => $form_data['name'] ?? '',
            'vidas'          => $vidas,
            'telefone'       => $telefone,
            'cidade'         => $form_data['cidade'] ?? '',
            'operadora'      => $form_data['qual_plano'] ?? '',
            'tipo'           => $tipo,
            'id_corretor'    => $vendedor['vendedor_id'] ?? '',
            'nome_corretor'  => $vendedor['nome'] ?? '',
            'aquisicao_lead' => current_time('Y-m-d')
        );
    }
    
    /**
     * Valida dados obrigatórios
     */
    private function validate_leadp3_data($data) {
        return !empty($data['id_leadp3']) && !empty($data['aquisicao_lead']);
    }
    
    /**
     * Envio HTTP síncrono COM LOG DETALHADO DE STATUS
     */
    private function send_request($data, $lead_id) {
        $this->log("🌐 [LEAD: {$lead_id}] Enviando requisição SÍNCRONA para: " . $this->api_url);
        $this->log("📦 [LEAD: {$lead_id}] Tamanho do payload: " . strlen(json_encode($data)) . " bytes");
        
        $start_time = microtime(true);
        
        $response = wp_remote_post($this->api_url, array(
            'method'     => 'POST',
            'timeout'    => 30,
            'blocking'   => true,
            'headers'    => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'FormularioHapvida/2.1'
            ),
            'body'       => json_encode($data, JSON_UNESCAPED_UNICODE),
            'sslverify'  => false
        ));
        
        $elapsed = round((microtime(true) - $start_time) * 1000, 2);
        $this->log("⏱️ [LEAD: {$lead_id}] Tempo de resposta: {$elapsed}ms");
        
        // ERRO WP (timeout, conexão, etc)
        if (is_wp_error($response)) {
            $error_code = $response->get_error_code();
            $error_message = $response->get_error_message();
            
            $this->log("❌ [LEAD: {$lead_id}] ERRO WP: [{$error_code}] {$error_message}");
            
            return array(
                'success' => false,
                'status'  => 'WP_ERROR',
                'error'   => "Erro de conexão: {$error_message}"
            );
        }
        
        // RESPOSTA HTTP RECEBIDA
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_headers = wp_remote_retrieve_headers($response);
        
        // Log detalhado da resposta
        $this->log("📥 [LEAD: {$lead_id}] ========== RESPOSTA DA API ==========");
        $this->log("📊 [LEAD: {$lead_id}] Status HTTP: {$status_code}");
        $this->log("📄 [LEAD: {$lead_id}] Body (primeiros 500 chars): " . substr($response_body, 0, 500));
        
        if (is_array($response_headers) && !empty($response_headers)) {
            $this->log("📋 [LEAD: {$lead_id}] Headers importantes:");
            foreach (['content-type', 'server', 'x-ratelimit-remaining'] as $header) {
                if (isset($response_headers[$header])) {
                    $this->log("   └─ {$header}: {$response_headers[$header]}");
                }
            }
        }
        
        // ANÁLISE DO STATUS CODE
        $status_info = $this->get_status_description($status_code);
        $this->log("🔍 [LEAD: {$lead_id}] Status: {$status_info['emoji']} {$status_info['description']}");
        
        // Sucesso (2xx)
        if ($status_code >= 200 && $status_code < 300) {
            $decoded = json_decode($response_body, true);
            
            if ($decoded && isset($decoded['message'])) {
                $this->log("💬 [LEAD: {$lead_id}] Mensagem da API: {$decoded['message']}");
            }
            
            $this->log("✅ [LEAD: {$lead_id}] ========== ENVIO BEM-SUCEDIDO ==========");
            
            return array(
                'success' => true,
                'status'  => $status_code,
                'body'    => $response_body
            );
        }
        
        // Erro (4xx, 5xx)
        $this->log("❌ [LEAD: {$lead_id}] ========== ENVIO FALHOU ==========");
        
        $error_details = $this->parse_error_response($response_body, $status_code);
        $this->log("🔴 [LEAD: {$lead_id}] Detalhes do erro: {$error_details}");
        
        return array(
            'success' => false,
            'status'  => $status_code,
            'error'   => "{$status_info['description']} - {$error_details}"
        );
    }
    
    /**
     * Retorna descrição amigável do status HTTP
     */
    private function get_status_description($code) {
        $statuses = array(
            // 2xx - Sucesso
            200 => array('emoji' => '✅', 'description' => 'OK - Requisição bem-sucedida'),
            201 => array('emoji' => '✅', 'description' => 'Created - Recurso criado com sucesso'),
            202 => array('emoji' => '✅', 'description' => 'Accepted - Requisição aceita para processamento'),
            204 => array('emoji' => '✅', 'description' => 'No Content - Sucesso sem conteúdo'),
            
            // 4xx - Erro do cliente
            400 => array('emoji' => '⚠️', 'description' => 'Bad Request - Dados inválidos'),
            401 => array('emoji' => '🔒', 'description' => 'Unauthorized - Token inválido ou ausente'),
            403 => array('emoji' => '🚫', 'description' => 'Forbidden - Acesso negado'),
            404 => array('emoji' => '❓', 'description' => 'Not Found - Endpoint não encontrado'),
            405 => array('emoji' => '⚠️', 'description' => 'Method Not Allowed - Método HTTP inválido'),
            408 => array('emoji' => '⏱️', 'description' => 'Request Timeout - Tempo esgotado'),
            409 => array('emoji' => '⚠️', 'description' => 'Conflict - Conflito de dados'),
            422 => array('emoji' => '⚠️', 'description' => 'Unprocessable Entity - Validação falhou'),
            429 => array('emoji' => '🚦', 'description' => 'Too Many Requests - Rate limit excedido'),
            
            // 5xx - Erro do servidor
            500 => array('emoji' => '💥', 'description' => 'Internal Server Error - Erro no servidor'),
            502 => array('emoji' => '🔌', 'description' => 'Bad Gateway - Gateway inválido'),
            503 => array('emoji' => '⛔', 'description' => 'Service Unavailable - Serviço indisponível'),
            504 => array('emoji' => '⏱️', 'description' => 'Gateway Timeout - Timeout no gateway'),
        );
        
        return isset($statuses[$code]) 
            ? $statuses[$code] 
            : array('emoji' => '❓', 'description' => "Código desconhecido: {$code}");
    }
    
    /**
     * Extrai detalhes do erro da resposta
     */
    private function parse_error_response($body, $status_code) {
        $decoded = json_decode($body, true);
        
        if ($decoded) {
            // Tenta extrair mensagem de erro em diferentes formatos
            if (isset($decoded['message'])) {
                $error = $decoded['message'];
                
                // Se houver erros de validação
                if (isset($decoded['errors']) && is_array($decoded['errors'])) {
                    $validation_errors = array();
                    foreach ($decoded['errors'] as $field => $messages) {
                        $validation_errors[] = "{$field}: " . (is_array($messages) ? implode(', ', $messages) : $messages);
                    }
                    $error .= ' | Erros: ' . implode(' | ', $validation_errors);
                }
                
                return $error;
            }
            
            if (isset($decoded['error'])) {
                return $decoded['error'];
            }
            
            if (isset($decoded['detail'])) {
                return $decoded['detail'];
            }
        }
        
        // Se não conseguiu decodificar JSON, retorna primeiros 200 caracteres
        return substr($body, 0, 200);
    }
    
    /**
     * Processa fila (1 tentativa apenas) COM LOGS DETALHADOS
     */
    /**
 * Processa fila (1 tentativa apenas) COM LOGS DETALHADOS
 */
public function process_leadp3_queue() {
    $queue = get_option('hapvida_leadp3_queue', array());
    
    if (empty($queue)) {
        $this->log("📭 Fila vazia - nenhum lead para processar");
        return;
    }
    
    $this->log("📄 ========== PROCESSAMENTO DA FILA INICIADO ==========");
    $this->log("📊 Total de leads na fila: " . count($queue));
    
    $processed = array();
    $success_count = 0;
    $error_count = 0;
    $max_process = 10;
    
    foreach ($queue as $index => $item) {
        if ($index >= $max_process) break;
        
        $lead_id = $item['form_data']['lead_id'] ?? 'N/A';
        $current_position = $index + 1;
        $this->log("🔄 [{$current_position}/{$max_process}] Processando lead da fila: {$lead_id}");
        
        $leadp3_data = $this->prepare_leadp3_data($item['form_data'], $item['vendedor']);
        $response = $this->send_request($leadp3_data, $lead_id);
        
        if ($response['success']) {
            $success_count++;
            $this->log("✅ [LEAD: {$lead_id}] Processado com SUCESSO - Status HTTP: {$response['status']}");
        } else {
            $error_count++;
            $this->log("❌ [LEAD: {$lead_id}] FALHOU - Status: {$response['status']} - Erro: {$response['error']}");
            //$this->send_failure_email($item['form_data'], $item['vendedor'], $response['error']);
        }
        
        // SEMPRE remove (não tenta novamente)
        $processed[] = $index;
    }
    
    // Remove processados
    foreach (array_reverse($processed) as $index) {
        unset($queue[$index]);
    }
    
    update_option('hapvida_leadp3_queue', array_values($queue), false);
    
    $this->log("📊 ========== PROCESSAMENTO FINALIZADO ==========");
    $this->log("✅ Sucessos: {$success_count}");
    $this->log("❌ Erros: {$error_count}");
    $this->log("📋 Restantes na fila: " . count($queue));
}
    
    /**
     * Notificação de falha por email
     */
    private function send_failure_email($form_data, $vendedor, $error) {
        $to = get_option('admin_email');
        $lead_id = $form_data['lead_id'] ?? 'N/A';
        $subject = "⚠️ Falha LeadP3 - {$lead_id}";
        
        $message = "Falha ao enviar lead para API LeadP3\n\n";
        $message .= "Lead ID: {$lead_id}\n";
        $message .= "Nome: " . ($form_data['name'] ?? 'N/A') . "\n";
        $message .= "Telefone: " . ($form_data['telefone'] ?? 'N/A') . "\n";
        $message .= "Vendedor: " . ($vendedor['nome'] ?? 'N/A') . "\n";
        $message .= "Erro: {$error}\n";
        $message .= "\nHorário: " . current_time('d/m/Y H:i:s') . "\n";
        
        wp_mail($to, $subject, $message);
        $this->log("📧 [LEAD: {$lead_id}] Email de falha enviado para: {$to}");
    }
    
    /**
     * Log
     */
    private function log($message) {
        $timestamp = current_time('Y-m-d H:i:s');
        error_log("[{$timestamp}] {$message}\n", 3, $this->log_file);
    }
}

// Singleton
function get_leadp3_integration_instance() {
    static $instance = null;
    if ($instance === null) {
        $instance = new Formulario_Hapvida_LeadP3_Integration();
    }
    return $instance;
}

get_leadp3_integration_instance();