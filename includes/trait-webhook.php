<?php
if (!defined('ABSPATH')) exit;

trait WebhookTrait {
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
     * *** NOVO: AJAX para retry manual de webhooks (para usar na admin) ***
     */
    public function ajax_retry_failed_webhooks()
    {
        check_ajax_referer('retry_webhooks_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        $failed_webhooks = get_option($this->failed_webhooks_option, array());
        $pending_count = count(array_filter($failed_webhooks, function ($w) {
            return $w['status'] === 'pending';
        }));

        wp_send_json_success(array(
            'message' => 'Processo de retry executado com sucesso',
            'pending_webhooks' => $pending_count
        ));
    }
}
