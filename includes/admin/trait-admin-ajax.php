<?php
if (!defined('ABSPATH')) exit;

trait AdminAjaxTrait {

    public function ajax_add_vendedor()
    {
        // Verifica se o usuário tem permissão
        if (!current_user_can('manage_options')) {
            wp_die('Permissão negada', 403);
        }

        // Verifica o nonce
        if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'save_vendedores')) {
            wp_die('Nonce inválido', 403);
        }

        // Obtém os parâmetros
        $index = isset($_POST['index']) ? sanitize_text_field($_POST['index']) : uniqid();
        $grupo = isset($_POST['grupo']) ? sanitize_text_field($_POST['grupo']) : 'drv';

        // Novo vendedor sempre começa ativo e com campos vazios incluindo o ID
        ob_start();
        $this->render_vendedor_row($index, array(
            'vendedor_id' => '', // NOVO CAMPO
            'nome' => '',
            'telefone' => '',
            'categoria' => ($grupo === 'drv' ? 'fixo' : ''),
            'status' => 'ativo'
        ), $grupo);
        $html = ob_get_clean();

        // Retorna o HTML
        echo $html;

        wp_die(); // Importante: termina a execução adequadamente
    }

    public function ajax_toggle_vendedor_status()
    {
        check_ajax_referer('save_vendedores', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        $index = isset($_POST['index']) ? sanitize_text_field($_POST['index']) : '';
        $new_status = isset($_POST['new_status']) ? sanitize_text_field($_POST['new_status']) : '';

        if (empty($index) || !in_array($new_status, array('ativo', 'inativo'))) {
            wp_send_json_error('Dados inválidos');
        }

        // Aqui você poderia atualizar diretamente no banco se necessário
        // Por ora, retornamos sucesso para o JavaScript atualizar a interface

        wp_send_json_success(array(
            'message' => 'Status alterado com sucesso',
            'new_status' => $new_status,
            'index' => $index
        ));
    }

    public function ajax_clear_submission_stats()
    {
        check_ajax_referer('clear_submission_stats_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        // Remove as estatísticas diárias e mensais
        delete_option($this->daily_submissions_option);
        delete_option($this->monthly_submissions_option);

        wp_send_json_success(array(
            'message' => 'Estatísticas de submissão removidas com sucesso'
        ));
    }

    public function ajax_adjust_daily_count()
    {
        // Verifica nonce
        if (!wp_verify_nonce($_POST['security'], 'adjust_daily_count_nonce')) {
            wp_send_json_error('Nonce inválido');
            return;
        }

        $adjustment = intval($_POST['adjustment']); // 1 ou -1
        $count_type = sanitize_text_field($_POST['count_type']); // 'daily' ou 'monthly'

        $today = current_time('Y-m-d');
        $current_month = current_time('Y-m');

        $daily_submissions = get_option($this->daily_submissions_option, array());
        $monthly_submissions = get_option($this->monthly_submissions_option, array());

        // Ajusta contagem diária
        if ($count_type === 'daily' || $count_type === 'both') {
            $current_daily = isset($daily_submissions[$today]) ? $daily_submissions[$today] : 0;
            $new_daily = max(0, $current_daily + $adjustment); // Não permite valores negativos
            $daily_submissions[$today] = $new_daily;
            update_option($this->daily_submissions_option, $daily_submissions);
        }

        // Ajusta contagem mensal
        if ($count_type === 'monthly' || $count_type === 'both') {
            $current_monthly = isset($monthly_submissions[$current_month]) ? $monthly_submissions[$current_month] : 0;
            $new_monthly = max(0, $current_monthly + $adjustment); // Não permite valores negativos
            $monthly_submissions[$current_month] = $new_monthly;
            update_option($this->monthly_submissions_option, $monthly_submissions);
        }

        // Para ajustes diários, também ajusta o mensal automaticamente
        if ($count_type === 'daily') {
            $current_monthly = isset($monthly_submissions[$current_month]) ? $monthly_submissions[$current_month] : 0;
            $new_monthly = max(0, $current_monthly + $adjustment);
            $monthly_submissions[$current_month] = $new_monthly;
            update_option($this->monthly_submissions_option, $monthly_submissions);
        }

        // Retorna as contagens atualizadas
        $final_daily = isset($daily_submissions[$today]) ? $daily_submissions[$today] : 0;
        $final_monthly = isset($monthly_submissions[$current_month]) ? $monthly_submissions[$current_month] : 0;

        wp_send_json_success(array(
            'new_daily_count' => $final_daily,
            'new_monthly_count' => $final_monthly,
            'adjustment' => $adjustment,
            'count_type' => $count_type
        ));
    }

    public function ajax_get_vendors_list_frontend()
    {
        // Não verifica nonce para permitir uso sem login

        $vendedores = get_option($this->vendedores_option, array());
        $formatted_vendors = array();

        foreach ($vendedores as $grupo => $grupo_vendedores) {
            foreach ($grupo_vendedores as $vendedor) {
                $formatted_vendors[] = array(
                    'id' => md5($vendedor['nome'] . $vendedor['telefone']),
                    'nome' => $vendedor['nome'],
                    'telefone' => $vendedor['telefone'],
                    'grupo' => $grupo,
                    'categoria' => isset($vendedor['categoria']) ? $vendedor['categoria'] : 'fixo',
                    'status' => isset($vendedor['status']) ? $vendedor['status'] : 'ativo'
                );
            }
        }

        wp_send_json_success(array('vendors' => $formatted_vendors));
    }

    // NOVA FUNÇÃO: Toggle vendedor status para frontend (sem login)
    public function ajax_toggle_vendor_status_frontend()
    {
        // Não verifica nonce para permitir uso sem login

        $vendedor_id = isset($_POST['vendedor_id']) ? sanitize_text_field($_POST['vendedor_id']) : '';
        $grupo = isset($_POST['grupo']) ? sanitize_text_field($_POST['grupo']) : '';
        $action = isset($_POST['vendor_action']) ? sanitize_text_field($_POST['vendor_action']) : '';

        if (empty($vendedor_id) || empty($grupo) || empty($action)) {
            wp_send_json_error('Dados inválidos');
            return;
        }

        // Busca vendedores atuais
        $vendedores = get_option($this->vendedores_option, array());

        if (!isset($vendedores[$grupo])) {
            wp_send_json_error('Grupo não encontrado');
            return;
        }

        // Encontra e atualiza o vendedor
        $updated = false;
        foreach ($vendedores[$grupo] as $index => &$vendedor) {
            if (
                sanitize_key($vendedor['nome']) === $vendedor_id ||
                md5($vendedor['nome'] . $vendedor['telefone']) === $vendedor_id
            ) {

                if ($action === 'toggle') {
                    $vendedor['status'] = ($vendedor['status'] === 'ativo') ? 'inativo' : 'ativo';
                } elseif (in_array($action, array('ativo', 'inativo'))) {
                    $vendedor['status'] = $action;
                }

                $updated = true;
                break;
            }
        }

        if ($updated) {
            update_option($this->vendedores_option, $vendedores);

            // Conta vendedores ativos e inativos
            $stats = array(
                'total_ativos' => 0,
                'total_inativos' => 0,
                'drv_ativos' => 0,
                'drv_inativos' => 0,
                'seu_souza_ativos' => 0,
                'seu_souza_inativos' => 0
            );

            foreach ($vendedores as $grupo_key => $grupo_vendedores) {
                foreach ($grupo_vendedores as $v) {
                    if ($v['status'] === 'ativo') {
                        $stats['total_ativos']++;
                        $stats[$grupo_key . '_ativos']++;
                    } else {
                        $stats['total_inativos']++;
                        $stats[$grupo_key . '_inativos']++;
                    }
                }
            }

            wp_send_json_success(array(
                'message' => 'Status do vendedor atualizado com sucesso',
                'new_status' => $vendedor['status'],
                'stats' => $stats
            ));
        } else {
            wp_send_json_error('Vendedor não encontrado');
        }
    }

    // FUNÇÃO PARA CONTAGEM EM TEMPO REAL
    public function ajax_get_live_counts()
    {

        $today = current_time('Y-m-d');
        $current_month = current_time('Y-m');

        $daily_submissions = get_option($this->daily_submissions_option, array());
        $monthly_submissions = get_option($this->monthly_submissions_option, array());

        $daily_count = isset($daily_submissions[$today]) ? $daily_submissions[$today] : 0;
        $monthly_count = isset($monthly_submissions[$current_month]) ? $monthly_submissions[$current_month] : 0;

        wp_send_json_success(array(
            'daily_count' => $daily_count,
            'monthly_count' => $monthly_count,
            'timestamp' => current_time('mysql')
        ));
    }

    public function ajax_get_recent_leads()
    {
        try {
            // Busca todos os webhooks salvos
            $all_webhooks = get_option($this->failed_webhooks_option, array());

            // Adiciona IDs se não existirem
            foreach ($all_webhooks as $index => &$webhook) {
                if (!isset($webhook['id']) || empty($webhook['id'])) {
                    $webhook['id'] = 'webhook_' . $index;
                }
            }

            // Ordena por data de criação (mais recentes primeiro)
            usort($all_webhooks, function ($a, $b) {
                $timeA = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
                $timeB = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
                return $timeB - $timeA;
            });

            // Pega apenas os 10 últimos
            $recent_leads = array_slice($all_webhooks, 0, 10);

            // Formata os dados para o frontend
            $formatted_leads = array();
            foreach ($recent_leads as $index => $webhook) {
                $webhook_data = isset($webhook['data']) ? $webhook['data'] : array();

                $formatted_leads[] = array(
                    'id' => isset($webhook['id']) ? $webhook['id'] : 'webhook_' . $index,
                    'created_at' => isset($webhook['created_at']) ? date('d/m/Y H:i', strtotime($webhook['created_at'])) : 'N/A',
                    'client_name' => isset($webhook_data['nome']) ? $webhook_data['nome'] : 'N/A',
                    'grupo' => isset($webhook_data['grupo']) ? strtoupper($webhook_data['grupo']) : 'N/A',
                    'status' => isset($webhook['status']) ? $webhook['status'] : 'pending',
                    'phone' => isset($webhook_data['telefone']) ? $webhook_data['telefone'] : 'N/A',
                    'city' => isset($webhook_data['cidade']) ? $webhook_data['cidade'] : 'N/A',
                    'vendor' => isset($webhook_data['vendedor']) ? $webhook_data['vendedor'] :
                        (isset($webhook_data['atendente']) ? $webhook_data['atendente'] : 'N/A')
                );
            }

            // IMPORTANTE: Retorna APENAS o array de leads, não um objeto com 'data'
            wp_send_json_success($formatted_leads);

        } catch (Exception $e) {
            error_log("HAPVIDA ERROR: Erro em ajax_get_recent_leads: " . $e->getMessage());
            wp_send_json_error('Erro ao buscar leads: ' . $e->getMessage());
        }
    }

    // FUNÇÃO PARA EXPORTAR TODOS OS LEADS
    public function ajax_get_all_leads_for_export()
    {
        // Só permite para usuários logados
        if (!is_user_logged_in()) {
            wp_send_json_error('Acesso negado');
            return;
        }

        try {
            $all_webhooks = get_option($this->failed_webhooks_option, array());

            $formatted_leads = array();
            foreach ($all_webhooks as $webhook) {
                $webhook_data = isset($webhook['data']) ? $webhook['data'] : array();

                $formatted_leads[] = array(
                    'created_at' => isset($webhook['created_at']) ? date('d/m/Y H:i', strtotime($webhook['created_at'])) : '',
                    'client_name' => isset($webhook_data['nome']) ? $webhook_data['nome'] : '',
                    'phone' => isset($webhook_data['telefone']) ? $webhook_data['telefone'] : '',
                    'city' => isset($webhook_data['cidade']) ? $webhook_data['cidade'] : '',
                    'grupo' => isset($webhook_data['grupo']) ? strtoupper($webhook_data['grupo']) : '',
                    'vendor' => isset($webhook_data['vendedor']) ? $webhook_data['vendedor'] :
                        (isset($webhook_data['atendente']) ? $webhook_data['atendente'] : ''),
                    'status' => isset($webhook['status']) ? $webhook['status'] : 'pending',
                    'plano' => isset($webhook_data['tipo_de_plano']) ? $webhook_data['tipo_de_plano'] :
                        (isset($webhook_data['qual_plano']) ? $webhook_data['qual_plano'] : ''),
                    'qtd_pessoas' => isset($webhook_data['quantidade_de_pessoas']) ? $webhook_data['quantidade_de_pessoas'] :
                        (isset($webhook_data['qtd_pessoas']) ? $webhook_data['qtd_pessoas'] : '1')
                );
            }

            wp_send_json_success(array('leads' => $formatted_leads));

        } catch (Exception $e) {
            wp_send_json_error('Erro ao exportar: ' . $e->getMessage());
        }
    }

    public function ajax_adjust_submission_count()
    {
        // CORREÇÃO: Torna nonce opcional para usuários não logados
        if (is_user_logged_in()) {
            if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'adjust_daily_count_nonce')) {
                wp_send_json_error('Nonce inválido');
                return;
            }
        }

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

        // Para ajustes diários, também ajusta o mensal automaticamente
        if ($count_type === 'daily') {
            $current_monthly = isset($monthly_submissions[$current_month]) ? $monthly_submissions[$current_month] : 0;
            $new_monthly = max(0, $current_monthly + $adjustment);
            $monthly_submissions[$current_month] = $new_monthly;
            update_option($this->monthly_submissions_option, $monthly_submissions);
        }

        // Retorna as contagens atualizadas
        $updated_daily = isset($daily_submissions[$today]) ? $daily_submissions[$today] : 0;
        $updated_monthly = isset($monthly_submissions[$current_month]) ? $monthly_submissions[$current_month] : 0;

        wp_send_json_success(array(
            'daily_count' => $updated_daily,
            'monthly_count' => $updated_monthly,
            'message' => 'Contagem atualizada com sucesso'
        ));
    }

    public function ajax_get_counts()
    {
        // CORREÇÃO: Torna nonce opcional para usuários não logados
        if (is_user_logged_in()) {
            if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'get_counts_nonce')) {
                wp_send_json_error('Nonce inválido');
                return;
            }
        }

        $today = current_time('Y-m-d');
        $current_month = current_time('Y-m');

        $daily_submissions = get_option($this->daily_submissions_option, array());
        $monthly_submissions = get_option($this->monthly_submissions_option, array());

        $today_count = isset($daily_submissions[$today]) ? $daily_submissions[$today] : 0;
        $monthly_count = isset($monthly_submissions[$current_month]) ? $monthly_submissions[$current_month] : 0;

        wp_send_json_success(array(
            'daily_count' => $today_count,
            'monthly_count' => $monthly_count
        ));
    }

    /**
     * CORREÇÃO: ajax_get_pending_webhooks_frontend()
     * ARQUIVO: admin-page.php
     * LOCALIZAÇÃO: Dentro da classe Formulario_Hapvida_Admin
     * PROBLEMA: Verificação de nonce falha para usuários não logados
     */
    public function ajax_get_pending_webhooks_frontend()
    {
        // *** CORREÇÃO: Remove verificação de nonce para permitir acesso público ***

        global $wpdb;
        $table_name = $wpdb->prefix . 'hapvida_webhooks';

        // Verifica se a tabela existe
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
            wp_send_json_success(array('webhooks' => array()));
            return;
        }

        try {
            // Busca webhooks pendentes
            $pending_webhooks = $wpdb->get_results(
                "SELECT * FROM {$table_name}
             WHERE status != 'enviado'
             ORDER BY criado_em DESC
             LIMIT 50"
            );

            $formatted_webhooks = array();
            $timezone = new DateTimeZone('America/Sao_Paulo');
            $now = new DateTime('now', $timezone);

            foreach ($pending_webhooks as $webhook) {
                // Decodifica dados se necessário
                $webhook_data = is_string($webhook->webhook_data) ?
                    json_decode($webhook->webhook_data, true) : $webhook->webhook_data;

                if (!is_array($webhook_data)) {
                    continue;
                }

                // Calcula tempo desde criação
                $criado_em = new DateTime($webhook->criado_em, $timezone);
                $tempo_decorrido = $now->getTimestamp() - $criado_em->getTimestamp();

                // Define urgência
                $urgencia = 'normal';
                if ($tempo_decorrido > 3600) { // 1 hora
                    $urgencia = 'urgent';
                } elseif ($tempo_decorrido > 1800) { // 30 minutos
                    $urgencia = 'warning';
                }

                // Formata tempo decorrido
                $horas = floor($tempo_decorrido / 3600);
                $minutos = floor(($tempo_decorrido % 3600) / 60);
                $tempo_formatado = '';

                if ($horas > 0) {
                    $tempo_formatado = "{$horas}h {$minutos}min";
                } else {
                    $tempo_formatado = "{$minutos} min";
                }

                $formatted_webhooks[] = array(
                    'id' => $webhook->id,
                    'webhook_url' => $webhook->webhook_url,
                    'status' => $webhook->status,
                    'tentativas' => $webhook->tentativas,
                    'criado_em' => $webhook->criado_em,
                    'tempo_decorrido' => $tempo_formatado,
                    'urgency' => $urgencia,
                    'cliente_nome' => $webhook_data['nome'] ?? 'N/A',
                    'vendedor_nome' => $webhook_data['vendedor_nome'] ?? 'N/A',
                    'erro' => $webhook->ultimo_erro
                );
            }

            wp_send_json_success(array(
                'webhooks' => $formatted_webhooks,
                'total' => count($formatted_webhooks)
            ));

        } catch (Exception $e) {
            error_log('HAPVIDA ERROR: ' . $e->getMessage());
            wp_send_json_error('Erro ao buscar webhooks: ' . $e->getMessage());
        }
    }

    public function ajax_get_webhook_lead_details_public()
    {
        // NÃO verifica nonce nem login para permitir acesso público

        $webhook_id = isset($_POST['webhook_id']) ? sanitize_text_field($_POST['webhook_id']) : '';

        if (empty($webhook_id)) {
            wp_send_json_error('ID do webhook não fornecido');
            return;
        }

        $webhooks = get_option($this->failed_webhooks_option, array());

        // Busca o webhook específico
        $webhook_found = null;

        foreach ($webhooks as $index => $webhook) {
            if (isset($webhook['id']) && $webhook['id'] == $webhook_id) {
                $webhook_found = $webhook;
                break;
            }
            // Tenta também com prefixo webhook_
            if ('webhook_' . $index == $webhook_id) {
                $webhook_found = $webhook;
                if (!isset($webhook_found['id'])) {
                    $webhook_found['id'] = $webhook_id;
                }
                break;
            }
        }

        // Se não encontrou, tenta pelo índice numérico
        if (!$webhook_found) {
            $index = str_replace('webhook_', '', $webhook_id);
            if (is_numeric($index) && isset($webhooks[$index])) {
                $webhook_found = $webhooks[$index];
                if (!isset($webhook_found['id'])) {
                    $webhook_found['id'] = $webhook_id;
                }
            }
        }

        if (!$webhook_found) {
            wp_send_json_error('Lead não encontrado com ID: ' . $webhook_id);
            return;
        }

        // Prepara os dados para exibição
        $data = isset($webhook_found['data']) ? $webhook_found['data'] : array();

        // Procura o ID real do lead em vários campos possíveis
        $lead_id_real = null;
        if (isset($data['lead_id'])) {
            $lead_id_real = $data['lead_id'];
        } elseif (isset($data['id_lead'])) {
            $lead_id_real = $data['id_lead'];
        } elseif (isset($data['id'])) {
            $lead_id_real = $data['id'];
        } elseif (isset($data['codigo'])) {
            $lead_id_real = $data['codigo'];
        } elseif (isset($data['protocolo'])) {
            $lead_id_real = $data['protocolo'];
        }

        $lead_details = array(
            'nome' => isset($data['nome']) ? $data['nome'] : 'N/A',
            'telefone' => isset($data['telefone']) ? $data['telefone'] : 'N/A',
            'cidade' => isset($data['cidade']) ? $data['cidade'] : 'N/A',
            'grupo' => isset($data['grupo']) ? strtoupper($data['grupo']) : 'N/A',
            'vendedor' => isset($data['vendedor']) ? $data['vendedor'] :
                (isset($data['atendente']) ? $data['atendente'] : 'N/A'),
            'plano' => isset($data['qual_plano']) ? $data['qual_plano'] :
                (isset($data['tipo_de_plano']) ? $data['tipo_de_plano'] : 'N/A'),
            'qtd_pessoas' => isset($data['qtd_pessoas']) ? $data['qtd_pessoas'] : '1',
            'idades' => isset($data['idades']) ? $data['idades'] : '',
            'created_at' => isset($webhook_found['created_at']) ?
                date('d/m/Y H:i', strtotime($webhook_found['created_at'])) : 'N/A',
            'status' => isset($webhook_found['status']) ? $webhook_found['status'] : 'pending',
            'lead_id' => $lead_id_real ? $lead_id_real : 'N/A', // Usa o ID real do lead
            'webhook_id' => $webhook_id, // Mantém também o ID do webhook para referência
            'observacoes' => isset($data['observacoes']) ? $data['observacoes'] : ''
        );

        // Se idades for array, converte para string
        if (is_array($lead_details['idades'])) {
            $lead_details['idades'] = implode(', ', $lead_details['idades']);
        }

        // Log para debug
        error_log("Lead ID Real: " . $lead_id_real);
        error_log("Dados completos do lead: " . json_encode($data));

        wp_send_json_success($lead_details);
    }

    public function ajax_adicionar_rota_consultor()
    {
        // Verifica nonce de segurança
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'url_consultores_nonce')) {
            wp_send_json_error('Erro de segurança');
            return;
        }

        // Verifica permissões
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sem permissão');
            return;
        }

        // Valida dados
        if (!isset($_POST['url']) || !isset($_POST['vendedor_numero'])) {
            wp_send_json_error('Dados incompletos');
            return;
        }

        $url = sanitize_text_field($_POST['url']);
        $vendedor_numero = sanitize_text_field($_POST['vendedor_numero']);

        if (empty($url) || empty($vendedor_numero)) {
            wp_send_json_error('URL ou número do consultor vazio');
            return;
        }

        // Busca configurações atuais
        $url_consultores = get_option('formulario_hapvida_url_consultores', array());

        if (!is_array($url_consultores)) {
            $url_consultores = array();
        }

        // Adiciona nova rota
        $url_consultores[] = array(
            'url' => $url,
            'vendedor_numero' => $vendedor_numero
        );

        // Salva
        update_option('formulario_hapvida_url_consultores', $url_consultores);

        wp_send_json_success('Rota adicionada com sucesso');
    }

    public function ajax_remover_rota_consultor()
    {
        // Verifica nonce de segurança
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'url_consultores_nonce')) {
            wp_send_json_error('Erro de segurança');
            return;
        }

        // Verifica permissões
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sem permissão');
            return;
        }

        // Valida dados
        if (!isset($_POST['index'])) {
            wp_send_json_error('Índice não fornecido');
            return;
        }

        $index = intval($_POST['index']);

        // Busca configurações atuais
        $url_consultores = get_option('formulario_hapvida_url_consultores', array());

        if (!is_array($url_consultores)) {
            wp_send_json_error('Nenhuma configuração encontrada');
            return;
        }

        // Remove o item
        if (isset($url_consultores[$index])) {
            unset($url_consultores[$index]);
            // Reindexar array
            $url_consultores = array_values($url_consultores);

            // Salva
            update_option('formulario_hapvida_url_consultores', $url_consultores);

            wp_send_json_success('Rota removida com sucesso');
        } else {
            wp_send_json_error('Rota não encontrada');
        }
    }
}
