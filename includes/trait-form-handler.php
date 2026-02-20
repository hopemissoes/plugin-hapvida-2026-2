<?php
if (!defined('ABSPATH')) exit;

trait FormHandlerTrait {

    public function handle_form_submission($request)
    {

        // DEBUG TEMPORÁRIO - REMOVER DEPOIS
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        ini_set('log_errors', 1);
        ini_set('error_log', WP_CONTENT_DIR . '/debug_hapvida.log');

        try {
            $start_time = microtime(true);
            $session_id = uniqid('sess_', true);

            // Extração e validação dos dados (MANTENDO ESTRUTURA ORIGINAL)
            $params = $request->get_params();

            // *** EXTRAÇÃO DOS DADOS DO FORMULÁRIO (MANTENDO ESTRUTURA ORIGINAL) ***
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
            $this->log("DADOS DO FORMULARIO: ===== NOVA SUBMISSAO =====");
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
                    $this->log("Lead direcionado por ROTA ESPECIFICA de URL");
                }
            }

            $this->log("Vendedor selecionado: {$vendedor['nome']} ({$vendedor['grupo']})");

            // NOVO LOG: Mostra o ID do vendedor se existir
            if (isset($vendedor['vendedor_id']) && !empty($vendedor['vendedor_id'])) {
                $this->log("ID do Vendedor: {$vendedor['vendedor_id']}");
            }

            // Gera URL do WhatsApp
            $whatsapp_url = $this->generate_whatsapp_url($form_data, $vendedor);

            // Atualiza contadores
            $this->update_submission_counts();

            // *** PROCESSAMENTO DO WEBHOOK - COM RETRY ROBUSTO ***
            $options = get_option($this->settings_option_name);
            $is_business_hours = $this->is_horario_comercial();

            // Tenta enviar webhook
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

                // Adiciona informações sobre roteamento específico
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
                    $this->log("Enviando webhook para lead {$form_data['lead_id']} - grupo {$grupo}");
                    error_log("HAPVIDA WEBHOOK: Iniciando envio para lead {$form_data['lead_id']} - grupo {$grupo}");

                    if (isset($webhook_data['vendedor_id']) && !empty($webhook_data['vendedor_id'])) {
                        $this->log("Webhook incluira ID do vendedor: {$webhook_data['vendedor_id']}");
                    }

                    // *** ENVIO BLOQUEANTE COM RETRIES IMEDIATOS ***
                    $max_immediate_attempts = 3;
                    $last_error = '';
                    $json_body = json_encode($webhook_data);

                    for ($attempt = 1; $attempt <= $max_immediate_attempts; $attempt++) {
                        error_log("HAPVIDA WEBHOOK: Tentativa {$attempt}/{$max_immediate_attempts} para lead {$form_data['lead_id']}");

                        $response = wp_remote_post($webhook_url, array(
                            'timeout' => 15,
                            'blocking' => true,
                            'body' => $json_body,
                            'headers' => array('Content-Type' => 'application/json'),
                            'sslverify' => false
                        ));

                        if (is_wp_error($response)) {
                            $last_error = $response->get_error_message();
                            error_log("HAPVIDA WEBHOOK: ERRO tentativa {$attempt} - {$last_error}");
                        } else {
                            $code = wp_remote_retrieve_response_code($response);
                            if ($code >= 200 && $code < 300) {
                                $webhook_success = true;
                                $this->log("Webhook enviado com sucesso na tentativa {$attempt}");
                                error_log("HAPVIDA WEBHOOK: SUCESSO tentativa {$attempt} - HTTP {$code}");
                                $this->save_webhook_entry($webhook_data, 'success', '', $code);
                                break;
                            } else {
                                $last_error = "HTTP {$code}";
                                error_log("HAPVIDA WEBHOOK: ERRO tentativa {$attempt} - HTTP {$code}");
                            }
                        }

                        // Aguarda antes da próxima tentativa (backoff: 2s, 4s)
                        if ($attempt < $max_immediate_attempts) {
                            $wait = $attempt * 2;
                            sleep($wait);
                        }
                    }

                    // Se todas as tentativas imediatas falharam, salva na fila para retry posterior
                    if (!$webhook_success) {
                        error_log("HAPVIDA WEBHOOK: Falha nas {$max_immediate_attempts} tentativas imediatas para lead {$form_data['lead_id']}");
                        $this->log("Webhook falhou {$max_immediate_attempts}x - salvando na fila de retry automatico");

                        // Salva como PENDING (não failed!) para o cron reprocessar
                        $this->save_webhook_entry_for_retry(
                            $webhook_data,
                            $webhook_url,
                            $last_error,
                            $max_immediate_attempts
                        );

                        // NÃO marca como lead perdido - o cron vai tentar novamente
                        error_log("HAPVIDA WEBHOOK: Lead {$form_data['lead_id']} na fila de retry - NAO esta perdido ainda");
                    }

                    // Registra entrega pendente para monitoramento via Evolution API
                    global $hapvida_delivery_tracking;
                    if ($hapvida_delivery_tracking) {
                        $vendedor['grupo'] = $grupo;
                        $hapvida_delivery_tracking->register_pending_delivery($vendedor, isset($form_data['lead_id']) ? $form_data['lead_id'] : uniqid('lead_'));
                    }

                } else {
                    $this->log("URL do webhook nao configurada para o grupo {$grupo}");
                }

            } catch (Exception $e) {
                $this->log("Erro no webhook: " . $e->getMessage());
                $webhook_success = false;
            }

            // *** ENVIA DADOS PARA API LEADP3 (NÃO-BLOQUEANTE) ***
            try {
                $leadp3_integration = null;
                if (class_exists('Formulario_Hapvida_LeadP3_Integration')) {
                    global $formulario_hapvida_leadp3;
                    $leadp3_integration = $formulario_hapvida_leadp3;
                }
                if ($leadp3_integration) {
                    $leadp3_integration->send_to_leadp3($form_data, $vendedor);
                }
            } catch (Exception $e) {
                error_log("LeadP3: " . $e->getMessage());
            }

            // Prepara resposta de sucesso
            $response = array(
                'success' => true,
                'message' => 'Formulário processado com sucesso! Redirecionando...',
                'redirect' => $whatsapp_url,
                'whatsapp_url' => $whatsapp_url,
                'webhook_status' => $webhook_success ? 'sent' : 'queued_for_retry',
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
            $this->log("Tempo de execucao: " . round($execution_time, 2) . "ms");
            $this->log("DADOS DO FORMULARIO: ===== FIM DA SUBMISSAO =====");

            return new WP_REST_Response($response, 200);

        } catch (Exception $e) {
            error_log("HAPVIDA ERROR: " . $e->getMessage());
            $this->log("ERRO: " . $e->getMessage());

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
            return false;
        }

        $telefone_clean = preg_replace('/[^0-9]/', '', $telefone);

        if (empty($telefone_clean)) {
            return false;
        }

        $processed_key = 'processed_phone_' . md5($telefone_clean);
        $processed = get_transient($processed_key);

        if ($processed) {
            $this->log("Verificacao duplicacao: Telefone {$telefone} ja foi processado recentemente");
            return true;
        }

        $this->log("Verificacao duplicacao: Telefone {$telefone} OK para nova submissao");
        return false;
    }

    private function mark_form_as_processed($form_data)
    {
        $telefone = isset($form_data['telefone']) ? $form_data['telefone'] : '';
        if (!empty($telefone)) {
            $telefone_clean = preg_replace('/[^0-9]/', '', $telefone);

            if (!empty($telefone_clean)) {
                $processed_key = 'processed_phone_' . md5($telefone_clean);
                set_transient($processed_key, time(), 180);
                $this->log("Telefone {$telefone} marcado como processado por 3 minutos");
            }
        }
    }

    private function extract_ages_from_request($params)
    {
        $ages = array();

        if (isset($params['ages']) && is_array($params['ages'])) {
            return $params['ages'];
        }

        if (isset($params['form_fields']['ages']) && is_array($params['form_fields']['ages'])) {
            return $params['form_fields']['ages'];
        }

        for ($i = 1; $i <= 10; $i++) {
            if (isset($params["age_$i"]) && !empty($params["age_$i"])) {
                $ages[] = $params["age_$i"];
            } elseif (isset($params['form_fields']["age_$i"]) && !empty($params['form_fields']["age_$i"])) {
                $ages[] = $params['form_fields']["age_$i"];
            }
        }

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
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($phone) == 11) {
            return sprintf(
                '(%s) %s-%s',
                substr($phone, 0, 2),
                substr($phone, 2, 5),
                substr($phone, 7)
            );
        } elseif (strlen($phone) == 10) {
            return sprintf(
                '(%s) %s-%s',
                substr($phone, 0, 2),
                substr($phone, 2, 4),
                substr($phone, 6)
            );
        } elseif (strlen($phone) == 9) {
            return sprintf(
                '%s-%s',
                substr($phone, 0, 5),
                substr($phone, 5)
            );
        } elseif (strlen($phone) == 8) {
            return sprintf(
                '%s-%s',
                substr($phone, 0, 4),
                substr($phone, 4)
            );
        }

        return $phone;
    }

    private function validate_brazilian_phone($telefone)
    {
        $clean_phone = preg_replace('/[^0-9]/', '', $telefone);

        $result = array(
            'valid' => false,
            'clean' => $clean_phone,
            'formatted' => $telefone,
            'type' => null,
            'error_message' => ''
        );

        $length = strlen($clean_phone);

        if ($length >= 10 && $length <= 11) {
            $ddd = substr($clean_phone, 0, 2);

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
                } else {
                    $result['type'] = 'celular';
                    $result['formatted'] = sprintf(
                        '(%s) %s-%s',
                        substr($clean_phone, 0, 2),
                        substr($clean_phone, 2, 5),
                        substr($clean_phone, 7)
                    );
                }
            } else {
                $result['error_message'] = 'DDD invalido. Use um DDD entre 11 e 99.';
            }
        } elseif ($length === 0) {
            $result['error_message'] = 'Por favor, digite seu numero de telefone com DDD';
        } elseif ($length < 10) {
            $result['error_message'] = sprintf(
                'Numero muito curto (%d digitos). Digite DDD + numero (minimo 10 digitos)',
                $length
            );
        } elseif ($length > 11) {
            $result['error_message'] = sprintf(
                'Numero muito longo (%d digitos). Maximo: 11 digitos com DDD',
                $length
            );
        } else {
            $result['error_message'] = 'Formato invalido. Use: (DD) XXXX-XXXX';
        }

        return $result;
    }

    private function update_ultimo_vendedor($vendedor)
    {
        $ultimo_vendedor_info = array(
            'vendedor' => $vendedor,
            'timestamp' => current_time('timestamp'),
            'data' => current_time('d/m/Y H:i:s')
        );

        update_option($this->ultimo_vendedor_option_name, $ultimo_vendedor_info);

        $this->log("Ultimo vendedor atualizado: {$vendedor['nome']}");
    }

    private function update_submission_counts()
    {
        $daily_submissions = get_option($this->daily_submissions_option, array());
        $today = current_time('Y-m-d');

        if (!isset($daily_submissions[$today])) {
            $daily_submissions[$today] = 0;
        }
        $daily_submissions[$today]++;

        update_option($this->daily_submissions_option, $daily_submissions);

        $monthly_submissions = get_option($this->monthly_submissions_option, array());
        $current_month = current_time('Y-m');

        if (!isset($monthly_submissions[$current_month])) {
            $monthly_submissions[$current_month] = 0;
        }
        $monthly_submissions[$current_month]++;

        update_option($this->monthly_submissions_option, $monthly_submissions);

        $this->log("Contadores atualizados - Diario: {$daily_submissions[$today]}, Mensal: {$monthly_submissions[$current_month]}");
    }
}
