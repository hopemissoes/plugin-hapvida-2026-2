<?php
if (!defined('ABSPATH')) exit;

trait FormHandlerTrait {

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

            // Formata telefone ANTES da verificação de duplicata (para hash consistente)
            $form_data['telefone'] = $this->format_phone_number($form_data['telefone']);

            // Verifica se já foi processado - BLOQUEIA duplicatas
            if ($this->is_form_processed($form_data)) {
                error_log("HAPVIDA DUPLICATA BLOQUEADA: Telefone {$form_data['telefone']} - Nome: {$form_data['name']}");
                $this->log(">>> DUPLICATA BLOQUEADA: Telefone {$form_data['telefone']}");
                throw new Exception("Você já enviou uma solicitação recentemente. Aguarde alguns minutos antes de tentar novamente.");
            }

            // Marca como processado
            $this->mark_form_as_processed($form_data);

            // Processa dados básicos
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
                    // *** ENVIO BLOQUEANTE COM RETRY - GARANTE ENTREGA ***
                    error_log("HAPVIDA WEBHOOK: Iniciando envio para lead {$form_data['lead_id']} - grupo {$grupo}");

                    $webhook_body = json_encode($webhook_data);
                    $max_tentativas = 2;
                    $webhook_response = null;
                    $ultimo_erro = '';

                    for ($tentativa = 1; $tentativa <= $max_tentativas; $tentativa++) {
                        error_log("HAPVIDA WEBHOOK: Tentativa {$tentativa}/{$max_tentativas} para lead {$form_data['lead_id']}");

                        $webhook_config = array(
                            'timeout' => 5,
                            'blocking' => true,
                            'body' => $webhook_body,
                            'headers' => array('Content-Type' => 'application/json'),
                            'sslverify' => false,
                            'httpversion' => '1.1',
                        );

                        $webhook_response = wp_remote_post($webhook_url, $webhook_config);

                        if (is_wp_error($webhook_response)) {
                            $ultimo_erro = $webhook_response->get_error_message();
                            error_log("HAPVIDA WEBHOOK: ERRO tentativa {$tentativa} - {$ultimo_erro}");

                            if ($tentativa < $max_tentativas) {
                                sleep($tentativa);
                            }
                            continue;
                        }

                        $response_code = wp_remote_retrieve_response_code($webhook_response);

                        if ($response_code >= 200 && $response_code < 300) {
                            error_log("HAPVIDA WEBHOOK: SUCESSO na tentativa {$tentativa} - HTTP {$response_code} - lead {$form_data['lead_id']}");
                            $webhook_success = true;
                            $this->save_webhook_entry($webhook_data, 'sent', "Enviado com sucesso na tentativa {$tentativa} - HTTP {$response_code}");
                            break;
                        }

                        $ultimo_erro = "HTTP {$response_code}";
                        $response_body_text = wp_remote_retrieve_body($webhook_response);
                        error_log("HAPVIDA WEBHOOK: HTTP {$response_code} na tentativa {$tentativa} - Body: " . substr($response_body_text, 0, 200));

                        if ($response_code >= 400 && $response_code < 500 && $response_code !== 408 && $response_code !== 429) {
                            error_log("HAPVIDA WEBHOOK: Erro definitivo HTTP {$response_code} - abortando retries");
                            break;
                        }

                        if ($tentativa < $max_tentativas) {
                            sleep($tentativa);
                        }
                    }

                    if (!$webhook_success) {
                        error_log("HAPVIDA WEBHOOK: FALHA TOTAL apos {$max_tentativas} tentativas para lead {$form_data['lead_id']} - Ultimo erro: {$ultimo_erro}");
                        $this->log(">>> WEBHOOK FALHOU: Lead {$form_data['lead_id']} - {$ultimo_erro} apos {$max_tentativas} tentativas");
                        $this->save_webhook_entry($webhook_data, 'failed', "Falhou apos {$max_tentativas} tentativas: {$ultimo_erro}");

                        $webhook_id = 'webhook_' . time() . '_' . wp_rand(1000, 9999);
                        $this->send_definitive_failure_notification($webhook_data, $webhook_id, $max_tentativas);
                    }

                    // Registra entrega pendente para monitoramento via Evolution API
                    global $hapvida_delivery_tracking;
                    if ($hapvida_delivery_tracking) {
                        $vendedor['grupo'] = $grupo;
                        $hapvida_delivery_tracking->register_pending_delivery($vendedor, isset($form_data['lead_id']) ? $form_data['lead_id'] : uniqid('lead_'));
                    }

                } else {
                    error_log("HAPVIDA WEBHOOK: URL NAO CONFIGURADA para grupo {$grupo} - lead {$form_data['lead_id']} SEM WEBHOOK!");
                    $this->log(">>> WEBHOOK SEM URL: grupo {$grupo} nao tem webhook configurado - lead {$form_data['lead_id']}");
                    $this->save_webhook_entry($webhook_data, 'failed', "URL do webhook nao configurada para grupo {$grupo}");
                    $webhook_id = 'webhook_' . time() . '_' . wp_rand(1000, 9999);
                    $this->send_definitive_failure_notification($webhook_data, $webhook_id, 0);
                }


            } catch (Exception $e) {
                error_log("HAPVIDA WEBHOOK: EXCECAO no webhook para lead " . (isset($form_data['lead_id']) ? $form_data['lead_id'] : 'unknown') . ": " . $e->getMessage());
                $this->log(">>> WEBHOOK EXCECAO: " . $e->getMessage());
                $webhook_success = false;

                // Salva webhook entry com falha para rastreabilidade
                if (isset($webhook_data)) {
                    $this->save_webhook_entry($webhook_data, 'failed', 'Excecao: ' . $e->getMessage());
                    $webhook_id = 'webhook_' . time() . '_' . wp_rand(1000, 9999);
                    $this->send_definitive_failure_notification($webhook_data, $webhook_id, 0);
                }
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
                'webhook_status' => $webhook_success ? 'sent_confirmed' : 'failed',
                'business_hours' => $is_business_hours,
                'tracking_enabled' => false,
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

            return new WP_REST_Response(array(
                'success' => false,
                'message' => $e->getMessage()
            ), 400);
        }
    }

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

    private function mark_form_as_processed($form_data)
    {
        $telefone = isset($form_data['telefone']) ? $form_data['telefone'] : '';
        if (!empty($telefone)) {
            // Normaliza telefone
            $telefone_clean = preg_replace('/[^0-9]/', '', $telefone);

            if (!empty($telefone_clean)) {
                $processed_key = 'processed_phone_' . md5($telefone_clean);

                // Bloqueia mesmo telefone por 30 minutos (1800 segundos)
                set_transient($processed_key, time(), 1800);

                error_log("HAPVIDA DUPLICATA: Telefone {$telefone} marcado como processado por 30 minutos");
            }
        }
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
}
