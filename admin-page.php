<?php
// Verifique se este arquivo está sendo acessado diretamente
if (!defined('ABSPATH')) {
    exit;
}

class Formulario_Hapvida_Admin
{
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

    }


    // AJAX: Retorna stats de delivery tracking (sem login)
    public function ajax_get_delivery_stats()
    {
        global $hapvida_delivery_tracking;
        if (!$hapvida_delivery_tracking) {
            wp_send_json_error(array('message' => 'Delivery tracking não disponível'));
            return;
        }

        $stats = $hapvida_delivery_tracking->get_stats_summary();
        $settings = get_option('formulario_hapvida_settings', array());
        $stats['server_time'] = time();
        $stats['delivery_timeout'] = 7200;
        $stats['auto_deactivation_enabled'] = isset($settings['enable_auto_deactivation']) ? $settings['enable_auto_deactivation'] : '1';
        $stats['is_horario_comercial'] = $hapvida_delivery_tracking->is_horario_comercial();
        wp_send_json_success($stats);
    }

    // AJAX: Toggle auto-deactivation setting (sem login)
    public function ajax_toggle_auto_deactivation()
    {
        $enabled = isset($_POST['enabled']) ? sanitize_text_field($_POST['enabled']) : '1';
        $settings = get_option('formulario_hapvida_settings', array());
        $settings['enable_auto_deactivation'] = $enabled;
        update_option('formulario_hapvida_settings', $settings);
        wp_send_json_success(array('enabled' => $enabled));
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

    // NOVA FUNÇÃO: Busca lista de vendedores para o frontend
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
    public function enqueue_admin_scripts($hook)
    {
        // Verifica se está na página do plugin
        if ($hook !== 'settings_page_formulario_hapvida') {
            return;
        }

        // Enfileira jQuery (já está por padrão, mas garante)
        wp_enqueue_script('jquery');

        // Adicione aqui outros scripts se necessário
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

    public function add_admin_menu()
    {
        // Menu principal
        add_options_page(
            'Formulário Hapvida',
            'Formulário Hapvida',
            'manage_options',
            'formulario-hapvida-admin',
            array($this, 'render_admin_page')
        );
    }

    public function register_settings()
    {
        register_setting('formulario_hapvida_settings', $this->option_name, array($this, 'sanitize_settings'));

        // Seção principal de configurações
        add_settings_section(
            'formulario_hapvida_general',
            'Configurações do Plugin',
            array($this, 'section_general_callback'),
            'formulario-hapvida-admin'
        );

        // Campo URL do Webhook (DRV) - Primeiro Envio
        add_settings_field(
            'webhook_url_drv',
            'URL do Webhook DRV (Primeiro Envio)',
            array($this, 'webhook_url_drv_callback'),
            'formulario-hapvida-admin',
            'formulario_hapvida_general'
        );

        // Campo URL do Webhook (Seu Souza) - Primeiro Envio
        add_settings_field(
            'webhook_url_seu_souza',
            'URL do Webhook Seu Souza (Primeiro Envio)',
            array($this, 'webhook_url_seu_souza_callback'),
            'formulario-hapvida-admin',
            'formulario_hapvida_general'
        );

        // Campo Lista de Cidades
        add_settings_field(
            'cidades',
            'Lista de Cidades',
            array($this, 'cidades_callback'),
            'formulario-hapvida-admin',
            'formulario_hapvida_general'
        );

        // redirect_obrigado tem form dedicado na aba de configurações, não registra aqui para evitar duplicação

    }

    /**
     * Sanitiza e mescla configurações para evitar perda de dados
     *
     * IMPORTANTE: Como temos 2 formulários separados (Relatórios e Webhooks)
     * salvando na mesma option, precisamos mesclar os dados novos com os existentes
     * para não perder configurações ao salvar um dos formulários.
     */
    public function sanitize_settings($input)
    {
        // Busca as configurações atuais
        $current_settings = get_option($this->option_name, array());

        // Mescla os dados novos com os existentes
        // array_merge() vai sobrescrever apenas os campos presentes em $input
        // mantendo os campos que não estão em $input
        $merged_settings = array_merge($current_settings, $input);

        // Sanitiza todos os campos
        $sanitized = array();

        foreach ($merged_settings as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = array_map('sanitize_text_field', $value);
            } else {
                // URLs especiais
                if (strpos($key, 'webhook_url') !== false) {
                    $sanitized[$key] = esc_url_raw($value);
                }
                // Senhas (não sanitizar para não quebrar caracteres especiais)
                elseif (strpos($key, 'password') !== false) {
                    $sanitized[$key] = $value;
                }
                // Campo de cidades (textarea com múltiplas linhas)
                elseif ($key === 'cidades') {
                    $sanitized[$key] = sanitize_textarea_field($value);
                }
                // Outros campos de texto
                else {
                    $sanitized[$key] = sanitize_text_field($value);
                }
            }
        }

        return $sanitized;
    }


    private function render_settings_section()
    {
        echo '<form method="post" action="options.php">';
        settings_fields('formulario_hapvida_group');
        do_settings_sections('formulario_hapvida');
        submit_button('💾 Salvar Configurações');
        echo '</form>';
    }

    private function render_all_leads_section()
    {
        // Busca TODOS os webhooks salvos
        $all_webhooks = get_option($this->failed_webhooks_option, array());

        // Adiciona IDs únicos se não existirem
        foreach ($all_webhooks as $index => &$webhook) {
            if (!isset($webhook['id']) || empty($webhook['id'])) {
                $webhook['id'] = 'webhook_' . $index . '_' . time();
            }
        }

        echo '<div class="hapvida-card">';
        echo '<h2><i class="dashicons dashicons-groups"></i> Todos os Leads Recebidos</h2>';

        // Calcula estatísticas
        $stats = array(
            'total' => count($all_webhooks),
            'pending' => 0,
            'completed' => 0,
            'failed' => 0
        );

        foreach ($all_webhooks as $webhook) {
            if (isset($webhook['status'])) {
                $stats[$webhook['status']]++;
            }
        }

        // Cards de estatísticas
        echo '<div class="webhook-stats">';
        echo '<div class="webhook-stat-card total">';
        echo '<div class="webhook-stat-number status-total">' . $stats['total'] . '</div>';
        echo '<div class="webhook-stat-label">Total de Leads</div>';
        echo '</div>';

        echo '</div>';

        // Botões de ação
        echo '<div class="webhook-actions">';
        echo '<button type="button" id="export-all-leads" class="button button-primary" style="background: #16a34a; border: none; border-radius: 8px;">';
        echo '<i class="dashicons dashicons-download"></i> Exportar Todos os Leads';
        echo '</button>';

        if ($stats['total'] > 0) {
            echo '<button type="button" id="clear-all-leads" class="button button-secondary" style="margin-left: 10px;">';
            echo '<i class="dashicons dashicons-trash"></i> Limpar Histórico';
            echo '</button>';
        }
        echo '</div>';

        // Lista dos 10 ÚLTIMOS leads apenas
        if (!empty($all_webhooks)) {
            // Ordena por data de criação (mais recentes primeiro)
            usort($all_webhooks, function ($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });

            // Pega apenas os 10 últimos
            $recent_leads = array_slice($all_webhooks, 0, 10);

            echo '<h3 style="color: #ff6b00; margin-top: 30px; margin-bottom: 15px;">Últimos 10 Leads Recebidos</h3>';
            echo '<div class="webhook-history">';
            echo '<div class="webhook-table-container">';
            echo '<table class="webhook-table">';
            echo '<thead>';
            echo '<tr>';
            echo '<th class="col-datetime">Data/Hora</th>';
            echo '<th class="col-client">Cliente</th>';
            echo '<th class="col-group">Grupo</th>';
            echo '<th class="col-phone">Telefone</th>';
            echo '<th class="col-city">Cidade</th>';
            echo '<th class="col-vendor">Vendedor</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            foreach ($recent_leads as $index => $webhook) {
                $webhook_id = isset($webhook['id']) ? $webhook['id'] : 'webhook_' . $index;
                $created_at = date('d/m/Y H:i', strtotime($webhook['created_at']));
                $client_name = isset($webhook['data']['nome']) ? $webhook['data']['nome'] : 'N/A';
                $grupo = isset($webhook['data']['grupo']) ? strtoupper($webhook['data']['grupo']) : 'N/A';
                $status = isset($webhook['status']) ? $webhook['status'] : 'pending';
                $phone = isset($webhook['data']['telefone']) ? $webhook['data']['telefone'] : 'N/A';
                $city = isset($webhook['data']['cidade']) ? $webhook['data']['cidade'] : 'N/A';
                $vendor = isset($webhook['data']['vendedor']) ? $webhook['data']['vendedor'] :
                    (isset($webhook['data']['atendente']) ? $webhook['data']['atendente'] : 'N/A');

                // Badge do grupo com cores
                $grupo_badge = '<span class="grupo-badge grupo-' . strtolower($grupo) . '">' . esc_html($grupo) . '</span>';

                // Armazena os dados do webhook em um input hidden
                $webhook_json = htmlspecialchars(json_encode($webhook), ENT_QUOTES, 'UTF-8');

                echo '<tr>';
                echo '<td class="col-datetime">' . esc_html($created_at) . '</td>';
                echo '<td class="col-client">';
                echo '<input type="hidden" id="webhook-data-' . esc_attr($webhook_id) . '" value="' . $webhook_json . '">';
                echo '<a href="#" class="lead-name-admin" data-lead-id="' . esc_attr($webhook_id) . '">';
                echo '<i class="dashicons dashicons-admin-users"></i> ';
                echo '<span>' . esc_html($client_name) . '</span>';
                echo '</a>';
                echo '</td>';

                echo '<td class="col-group">' . $grupo_badge . '</td>';
                echo '<td class="col-phone">' . esc_html($phone) . '</td>';
                echo '<td class="col-city">' . esc_html($city) . '</td>';
                echo '<td class="col-vendor">' . esc_html($vendor) . '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
            echo '</div>';
            echo '</div>';

            // Mensagem informativa
            if ($stats['total'] > 10) {
                echo '<p style="text-align: center; color: #666; margin-top: 15px; font-style: italic;">';
                echo '📊 Mostrando os 10 leads mais recentes de um total de ' . $stats['total'] . ' leads.';
                echo '</p>';
            }

        } else {
            echo '<div class="no-webhooks-message">';
            echo '<i class="dashicons dashicons-admin-generic"></i>';
            echo '<p><strong>Nenhum lead registrado ainda.</strong></p>';
            echo '<p>Os leads aparecerão aqui após as submissões do formulário.</p>';
            echo '</div>';
        }

        echo '</div>';

        // Modal para admin
        ?>
        <div id="lead-details-modal-admin" class="hapvida-lead-modal" style="display: none;">
            <div class="hapvida-lead-modal-overlay"></div>
            <div class="hapvida-lead-modal-content">

                <div class="hapvida-lead-modal-header">
                    <h3>📋 Detalhes do Lead</h3>
                    <button type="button" class="hapvida-lead-modal-close">
                        <i class="dashicons dashicons-no-alt"></i>
                    </button>
                </div>

                <div class="hapvida-lead-modal-body" id="lead-modal-body-admin">
                    <!-- Conteúdo será preenchido via JavaScript -->
                </div>

                <div class="hapvida-lead-modal-footer">
                    <button type="button" class="button button-secondary copy-lead-info-admin" style="float: left;">📋
                        Copiar</button>
                    <button type="button" class="button button-primary hapvida-lead-modal-close">Fechar</button>
                </div>

            </div>
        </div>


        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                console.log('HAPVIDA ADMIN: Script de modal iniciado');

                // Função para abrir modal no admin
                $(document).on('click', '.lead-name-admin', function (e) {
                    e.preventDefault();
                    e.stopPropagation();

                    console.log('Lead clicado no admin!');

                    var leadId = $(this).data('lead-id');
                    var webhookDataElement = $('#webhook-data-' + leadId);

                    if (webhookDataElement.length === 0) {
                        console.error('Elemento de dados não encontrado para ID:', leadId);
                        alert('Dados do lead não disponíveis');
                        return;
                    }

                    var webhookData;
                    try {
                        webhookData = JSON.parse(webhookDataElement.val());
                        console.log('Dados parseados:', webhookData);
                    } catch (e) {
                        console.error('Erro ao parsear JSON:', e);
                        alert('Erro ao carregar dados do lead');
                        return;
                    }

                    // Prepara os dados
                    var data = webhookData.data || {};
                    var lead = {
                        nome: data.nome || 'N/A',
                        telefone: data.telefone || 'N/A',
                        cidade: data.cidade || 'N/A',
                        grupo: (data.grupo || 'N/A').toUpperCase(),
                        vendedor: data.vendedor || data.atendente || 'N/A',
                        plano: data.qual_plano || data.tipo_de_plano || 'N/A',
                        qtd_pessoas: data.qtd_pessoas || '1',
                        idades: data.idades || data.ages || '',
                        created_at: webhookData.created_at || 'N/A',
                        status: webhookData.status || 'pending',
                        attempts: webhookData.attempts || '0',
                        last_error: webhookData.last_error || '',
                        lead_id: data.lead_id || webhookData.id || leadId,
                        observacoes: data.observacoes || ''
                    };

                    // Se idades for array, converte para string
                    if (Array.isArray(lead.idades)) {
                        lead.idades = lead.idades.join(', ');
                    }

                    // Cria o modal se não existir
                    if ($('#lead-details-modal-admin').length === 0) {
                        var modalHtml = `
                <div id="lead-details-modal-admin" class="hapvida-lead-modal" style="display: none;">
                    <div class="hapvida-lead-modal-overlay"></div>
                    <div class="hapvida-lead-modal-content">
                        <div class="hapvida-lead-modal-header">
                            <h3>📋 Detalhes do Lead</h3>
                            <button type="button" class="hapvida-lead-modal-close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="hapvida-lead-modal-body" id="lead-modal-body-admin"></div>
                        <div class="hapvida-lead-modal-footer">
                            <button type="button" class="button button-secondary copy-lead-info-admin">📋 Copiar</button>
                            <button type="button" class="button button-primary hapvida-lead-modal-close">Fechar</button>
                        </div>
                    </div>
                </div>
            `;
                        $('body').append(modalHtml);
                    }

                    var modal = $('#lead-details-modal-admin');
                    var modalBody = $('#lead-modal-body-admin');

                    // Monta o HTML com as informações
                    var html = `
            <div class="lead-details-grid">
                <div class="lead-detail-section">
                    <h4>👤 Informações do Cliente</h4>
                    <div class="lead-detail-item"><strong>ID:</strong> ${lead.lead_id}</div>
                    <div class="lead-detail-item"><strong>Nome:</strong> ${lead.nome}</div>
                    <div class="lead-detail-item"><strong>Telefone:</strong> ${lead.telefone}</div>
                    <div class="lead-detail-item"><strong>Cidade:</strong> ${lead.cidade}</div>
                </div>
                
                <div class="lead-detail-section">
                    <h4>📊 Detalhes do Plano</h4>
                    <div class="lead-detail-item"><strong>Plano:</strong> ${lead.plano}</div>
                    <div class="lead-detail-item"><strong>Qtd Pessoas:</strong> ${lead.qtd_pessoas}</div>
                    ${lead.idades && lead.idades !== 'N/A' ? `<div class="lead-detail-item"><strong>Idades:</strong> ${lead.idades}</div>` : ''}
                </div>
                
                <div class="lead-detail-section">
                    <h4>👥 Atribuição</h4>
                    <div class="lead-detail-item"><strong>Grupo:</strong> ${lead.grupo}</div>
                    <div class="lead-detail-item"><strong>Vendedor:</strong> ${lead.vendedor}</div>
                </div>
                
                <div class="lead-detail-section">
                    <h4>⚙️ Status</h4>
                    <div class="lead-detail-item"><strong>Status:</strong> ${lead.status}</div>
                    <div class="lead-detail-item"><strong>Tentativas:</strong> ${lead.attempts}</div>
                    <div class="lead-detail-item"><strong>Criado em:</strong> ${lead.created_at}</div>
                    ${lead.last_error ? `<div class="lead-detail-item"><strong>Último Erro:</strong> ${lead.last_error}</div>` : ''}
                </div>
                
                ${lead.observacoes ? `
                <div class="lead-detail-section full-width">
                    <h4>📝 Observações</h4>
                    <div class="lead-detail-item">${lead.observacoes}</div>
                </div>
                ` : ''}
            </div>
        `;

                    modalBody.html(html);
                    modalBody.data('lead-info', lead);

                    // Mostra o modal
                    modal.fadeIn(300);
                });

                // Fechar modal
                $(document).on('click', '.hapvida-lead-modal-close, .hapvida-lead-modal-overlay', function () {
                    $('#lead-details-modal-admin').fadeOut(300);
                });

                // Copiar informações
                $(document).on('click', '.copy-lead-info-admin', function () {
                    var leadInfo = $('#lead-modal-body-admin').data('lead-info');
                    if (leadInfo) {
                        var textToCopy = `Lead ID: ${leadInfo.lead_id}\n`;
                        textToCopy += `Nome: ${leadInfo.nome}\n`;
                        textToCopy += `Telefone: ${leadInfo.telefone}\n`;
                        textToCopy += `Cidade: ${leadInfo.cidade}\n`;
                        textToCopy += `Plano: ${leadInfo.plano}\n`;
                        textToCopy += `Qtd Pessoas: ${leadInfo.qtd_pessoas}\n`;
                        if (leadInfo.idades && leadInfo.idades !== 'N/A') {
                            textToCopy += `Idades: ${leadInfo.idades}\n`;
                        }
                        textToCopy += `Grupo: ${leadInfo.grupo}\n`;
                        textToCopy += `Vendedor: ${leadInfo.vendedor}`;

                        // Cria textarea temporária
                        var tempTextarea = $('<textarea>');
                        $('body').append(tempTextarea);
                        tempTextarea.val(textToCopy).select();
                        document.execCommand('copy');
                        tempTextarea.remove();

                        // Feedback visual
                        var $btn = $(this);
                        var originalText = $btn.text();
                        $btn.text('✅ Copiado!');
                        setTimeout(function () {
                            $btn.text(originalText);
                        }, 2000);
                    }
                });
            });
        </script>

        <style>
            .hapvida-lead-modal {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 999999;
                display: none;
            }

            .hapvida-lead-modal.show {
                display: flex !important;
            }

            .hapvida-lead-modal-overlay {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.6);
                backdrop-filter: blur(5px);
            }

            .hapvida-lead-modal-content {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: white;
                border-radius: 20px;
                box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
                max-width: 800px;
                width: 90%;
                max-height: 80vh;
                overflow: hidden;
            }

            .hapvida-lead-modal-header {
                background: #ff6b00;
                color: white;
                padding: 20px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .hapvida-lead-modal-header h3 {
                margin: 0;
                font-size: 20px;
                color: white;
            }

            .hapvida-lead-modal-close {
                background: none;
                border: none;
                color: white;
                font-size: 28px;
                cursor: pointer;
                padding: 0;
                width: 30px;
                height: 30px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 4px;
                transition: background 0.3s;
            }

            .hapvida-lead-modal-close:hover {
                background: rgba(255, 255, 255, 0.1);
                border-radius: 4px;
            }

            .hapvida-lead-modal-body {
                padding: 20px;
                max-height: 60vh;
                overflow-y: auto;
            }

            .hapvida-lead-modal-footer {
                padding: 15px 20px;
                background: #f8f9fa;
                border-top: 1px solid #dee2e6;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .lead-details-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
            }

            .lead-detail-section {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 16px;
                border: 1px solid #e2e8f0;
            }

            .lead-detail-section.full-width {
                grid-column: span 2;
            }

            .lead-detail-section h4 {
                margin: 0 0 15px 0;
                color: #ff6b00;
                font-size: 14px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .lead-detail-item {
                padding: 8px 0;
                border-bottom: 1px solid #e9ecef;
                font-size: 13px;
            }

            .lead-detail-item:last-child {
                border-bottom: none;
            }

            .lead-detail-item strong {
                color: #495057;
                margin-right: 8px;
            }

            .badge-grupo,
            .badge-status {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 8px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }

            .badge-grupo {
                background: #16a34a;
                color: white;
            }

            .badge-status {
                background: #f59e0b;
                color: #1a202c;
            }

            /* Responsividade */
            @media (max-width: 768px) {
                .hapvida-lead-modal-content {
                    width: 95%;
                    max-height: 90vh;
                }

                .lead-details-grid {
                    grid-template-columns: 1fr;
                }

                .lead-detail-section.full-width {
                    grid-column: span 1;
                }
            }

            /* Animação */
            @keyframes modalFadeIn {
                from {
                    opacity: 0;
                    transform: translate(-50%, -50%) scale(0.9);
                }

                to {
                    opacity: 1;
                    transform: translate(-50%, -50%) scale(1);
                }
            }

            .hapvida-lead-modal.show .hapvida-lead-modal-content {
                animation: modalFadeIn 0.3s ease-out;
            }
        </style>
        <?php
    }

    public function admin_styles()
    {
        $screen = get_current_screen();
        if ($screen->id !== 'settings_page_formulario_hapvida') {
            return;
        }

        echo '<style>
    .hapvida-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 20px;
        padding: 24px;
        margin: 20px 0;
        box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    }
    
    .hapvida-card h2 {
        margin-top: 0;
        color: #1a202c;
        border-bottom: 2px solid #ff6b00;
        padding-bottom: 12px;
        font-size: 18px;
        font-weight: 700;
    }
    
    .business-hours-status {
        margin: 15px 0;
        padding: 15px;
        border-radius: 12px;
    }
    
    .status-active {
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        color: #166534;
        padding: 10px;
        border-radius: 12px;
        font-weight: 600;
    }
    
    .status-inactive {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
        padding: 10px;
        border-radius: 12px;
        font-weight: 600;
    }
    
    .tab-content {
        margin-top: 20px;
    }
    
    .nav-tab-wrapper {
        border-bottom: 2px solid #e2e8f0;
        margin: 20px 0 0 0;
    }
    
    .nav-tab {
        font-size: 14px;
        font-weight: 600;
        border-radius: 12px 12px 0 0;
        border: 1px solid transparent;
        padding: 10px 18px;
        color: #64748b;
        transition: color 0.2s ease;
    }
    
    .nav-tab:hover {
        color: #ff6b00;
    }
    
    .nav-tab-active,
    .nav-tab-active:hover {
        color: #ff6b00;
        border-color: #e2e8f0 #e2e8f0 #fff;
        border-bottom: 2px solid #ff6b00;
        background: #fff;
    }
    
    .form-table th {
        width: 200px;
        font-weight: 600;
        color: #1a202c;
    }
    
    .button-primary {
        background: #ff6b00;
        border-color: #ff6b00;
        font-weight: 600;
        border-radius: 8px;
    }
    
    .button-primary:hover {
        background: #e65c00;
        border-color: #e65c00;
    }
    
    .description {
        font-style: italic;
        color: #64748b;
    }
    
    .hapvida-debug {
        background: #f8f9fa;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 12px;
        font-family: Monaco, Consolas, monospace;
        font-size: 11px;
        white-space: pre-wrap;
        max-height: 300px;
        overflow-y: auto;
        margin: 10px 0;
    }
    </style>';
    }


    public function section_general_callback()
    {

    }

    public function webhook_url_drv_callback()
    {
        $options = get_option($this->option_name);
        $webhook_url_drv = isset($options['webhook_url_drv']) ? $options['webhook_url_drv'] : '';
        echo "<input type='url' class='regular-text' name='{$this->option_name}[webhook_url_drv]' value='{$webhook_url_drv}' data-label='URL do Webhook DRV (Primeiro Envio)' />";
        echo "<p class='description'>URL usada para o <strong>primeiro envio</strong> de leads do grupo DRV.</p>";
    }

    public function webhook_url_seu_souza_callback()
    {
        $options = get_option($this->option_name);
        $webhook_url_seu_souza = isset($options['webhook_url_seu_souza']) ? $options['webhook_url_seu_souza'] : '';
        echo "<input type='url' class='regular-text' name='{$this->option_name}[webhook_url_seu_souza]' value='{$webhook_url_seu_souza}' data-label='URL do Webhook Seu Souza (Primeiro Envio)' />";
        echo "<p class='description'>URL usada para o <strong>primeiro envio</strong> de leads do grupo Seu Souza.</p>";
    }

    public function cidades_callback()
    {
        $options = get_option($this->option_name);
        $cidades = isset($options['cidades']) ? $options['cidades'] : '';

        echo '<div style="margin-bottom: 15px;">';
        echo "<textarea name='{$this->option_name}[cidades]' rows='8' cols='60' style='width: 100%; min-height: 200px; padding: 10px; border: 2px solid #ddd; border-radius: 6px; font-family: monospace; font-size: 14px; line-height: 1.8; white-space: pre-wrap; resize: vertical;' placeholder='Digite uma cidade por linha...'>" . esc_textarea($cidades) . "</textarea>";
        echo '</div>';

        echo '<div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 15px; margin-top: 10px;">';
        echo '<h4 style="margin: 0 0 10px 0; color: #495057;">📍 Como adicionar cidades:</h4>';
        echo '<p style="margin: 5px 0; font-size: 14px;">Informe <strong>uma cidade por linha</strong>. Exemplos:</p>';
        echo '<div style="background: #ffffff; border: 1px solid #ddd; border-radius: 4px; padding: 10px; margin: 10px 0; font-family: monospace; font-size: 13px; color: #333;">';
        echo 'Fortaleza<br>';
        echo 'Recife<br>';
        echo 'Salvador<br>';
        echo 'João Pessoa<br>';
        echo 'Natal<br>';
        echo 'Maceió';
        echo '</div>';
        echo '<p style="margin: 5px 0; font-size: 13px; color: #6c757d;"><strong>Dica:</strong> As cidades aparecerão na mesma ordem que você digitá-las aqui.</p>';
        echo '</div>';
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

    // ---------------------------------------------------------------
    // Migração da estrutura de vendedores
    public function migrate_vendedores_structure()
    {
        $vendedores = get_option($this->vendedores_option, array());

        if (isset($vendedores['drv']) || isset($vendedores['seu_souza'])) {
            return; // Estrutura já migrada
        }

        $vendedores_migrated = array(
            'drv' => $vendedores,
            'seu_souza' => array()
        );
        update_option($this->vendedores_option, $vendedores_migrated);
    }

    public function render_contagem_shortcode()
    {

        ob_start();

        // Gera nonces para JavaScript
        $get_counts_nonce = wp_create_nonce('get_counts_nonce');
        $adjust_daily_count_nonce = wp_create_nonce('adjust_daily_count_nonce');
        $get_pending_webhooks_nonce = wp_create_nonce('get_pending_webhooks_nonce');
        $toggle_vendor_nonce = wp_create_nonce('toggle_vendor_nonce'); // NOVO


        ?>
        <!-- Campos hidden para nonces -->
        <input type="hidden" id="get-counts-nonce" value="<?php echo $get_counts_nonce; ?>" />
        <input type="hidden" id="adjust-daily-count-nonce" value="<?php echo $adjust_daily_count_nonce; ?>" />
        <input type="hidden" id="webhook-nonce" value="<?php echo $get_pending_webhooks_nonce; ?>" />
        <input type="hidden" id="toggle-vendor-nonce" value="<?php echo $toggle_vendor_nonce; ?>" />

        <div class="hapvida-dashboard">

            <!-- ⭐ PRIMEIRA SEÇÃO: TABELA DE TODOS OS LEADS (MOVIDA PARA CIMA) -->
            <div class="dashboard-section all-leads-section">
                <?php $this->render_all_leads_section_frontend(); ?>
            </div>

            <!-- SEGUNDA SEÇÃO: ESTATÍSTICAS DE SUBMISSÕES (MOVIDA PARA BAIXO) -->
            <div class="dashboard-section stats-section">
                <div class="section-header">
                    <h2><i class="fas fa-chart-line"></i> Estatísticas de Submissões</h2>
                </div>

                <div class="stats-grid">
                    <!-- Submissões Diárias -->
                    <div class="stat-card daily-card">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-label">Submissões Hoje</div>
                            <div class="stat-value">
                                <span class="daily-count">
                                    <?php
                                    $today = current_time('Y-m-d');
                                    $daily_submissions = get_option($this->daily_submissions_option, array());
                                    echo isset($daily_submissions[$today]) ? $daily_submissions[$today] : 0;
                                    ?>
                                </span>
                            </div>
                            <div class="stat-controls">
                                <button class="adjust-btn adjust-daily-minus" data-type="daily" data-adjustment="-1">
                                    <i class="fas fa-minus"></i>
                                </button>
                                <button class="adjust-btn adjust-daily-plus" data-type="daily" data-adjustment="1">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Submissões Mensais -->
                    <div class="stat-card monthly-card">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-label">Submissões no Mês</div>
                            <div class="stat-value">
                                <span class="monthly-count">
                                    <?php
                                    $current_month = current_time('Y-m');
                                    $monthly_submissions = get_option($this->monthly_submissions_option, array());
                                    echo isset($monthly_submissions[$current_month]) ? $monthly_submissions[$current_month] : 0;
                                    ?>
                                </span>
                            </div>
                            <div class="stat-controls">
                                <button class="adjust-btn adjust-monthly-minus" data-type="monthly" data-adjustment="-1">
                                    <i class="fas fa-minus"></i>
                                </button>
                                <button class="adjust-btn adjust-monthly-plus" data-type="monthly" data-adjustment="1">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SEÇÃO: MONITORAMENTO DE ENTREGAS -->
            <div class="dashboard-section delivery-tracking-section">
                <?php $this->render_delivery_tracking_frontend(); ?>
            </div>

            <!-- ⭐ NOVA SEÇÃO: GERENCIAMENTO DE VENDEDORES -->
            <div class="dashboard-section vendors-management-section">
                <?php $this->render_vendors_management_frontend(); ?>
            </div>

        </div>

        <!-- CSS COMPLETO PARA TODAS AS SEÇÕES -->
        <style>
            /* =================================================================
                                                                       CSS COMPLETO - DASHBOARD HAPVIDA
                                                                       ================================================================= */

            /* Container Principal */
            .hapvida-dashboard {
                max-width: 1200px;
                margin: 0 auto;
                padding: 20px;
                font-family: 'Open Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: #f8f9fa;
                min-height: 100vh;
            }

            /* Seções do Dashboard */
            .dashboard-section {
                background: white;
                border-radius: 20px;
                padding: 30px;
                margin-bottom: 25px;
                box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
                border: 1px solid #e2e8f0;
                position: relative;
                overflow: hidden;
            }

            /* Header das Seções */
            .section-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                padding-bottom: 15px;
                border-bottom: 2px solid #e2e8f0;
            }

            .section-header h2 {
                font-size: 22px;
                color: #1a202c;
                margin: 0;
                display: flex;
                align-items: center;
                gap: 10px;
                font-weight: 700;
            }

            /* ======================== SEÇÃO 1: TODOS OS LEADS ======================== */
            .all-leads-section .webhook-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin-bottom: 30px;
            }

            .all-leads-section .webhook-stat-card {
                background: #ff6b00;
                padding: 20px;
                border-radius: 20px;
                color: white;
                text-align: center;
                box-shadow: 0 4px 12px rgba(255, 107, 0, 0.2);
                transition: transform 0.3s;
            }

            .all-leads-section .webhook-stat-card:hover {
                transform: translateY(-3px);
            }

            .all-leads-section .webhook-stat-number {
                font-size: 2.5em;
                font-weight: bold;
                margin-bottom: 5px;
            }

            .all-leads-section .webhook-stat-label {
                font-size: 0.9em;
                opacity: 0.9;
                text-transform: uppercase;
                letter-spacing: 1px;
            }

            /* Cores específicas para cada card */
            .all-leads-section .webhook-stat-card.total {
                background: #ff6b00;
            }

            .all-leads-section .webhook-stat-card.completed {
                background: #16a34a;
            }

            .all-leads-section .webhook-stat-card.pending {
                background: #f59e0b;
            }

            .all-leads-section .webhook-stat-card.failed {
                background: #ef4444;
            }

            /* Tabela de Webhooks */
            .all-leads-section .webhook-history {
                margin-top: 20px;
            }

            .all-leads-section .webhook-table-container {
                overflow-x: auto;
                background: white;
                border-radius: 16px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            }

            .all-leads-section .webhook-table {
                width: 100%;
                border-collapse: collapse;
            }

            .all-leads-section .webhook-table thead {
                background: #ff6b00;
                color: white;
            }

            .all-leads-section .webhook-table th {
                padding: 15px;
                text-align: left;
                font-weight: 600;
                text-transform: uppercase;
                font-size: 0.85em;
                letter-spacing: 0.5px;
            }

            .all-leads-section .webhook-table tbody tr {
                border-bottom: 1px solid #f0f0f0;
                transition: background 0.2s;
            }

            .all-leads-section .webhook-table tbody tr:hover {
                background: #f8f9fa;
                cursor: pointer;
            }

            .all-leads-section .webhook-table td {
                padding: 12px 15px;
                color: #495057;
            }

            /* Badges de Status */
            .status-badge {
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 0.85em;
                font-weight: 600;
                display: inline-block;
            }

            .status-badge.status-success,
            .status-badge.status-completed {
                background: #d4edda;
                color: #155724;
            }

            .status-badge.status-pending {
                background: #fff3cd;
                color: #856404;
            }

            .status-badge.status-failed {
                background: #f8d7da;
                color: #721c24;
            }

            /* Badges de Grupo */
            .grupo-badge {
                padding: 4px 10px;
                border-radius: 4px;
                font-size: 0.85em;
                font-weight: bold;
                text-transform: uppercase;
            }

            .grupo-badge.grupo-drv {
                background: #e3f2fd;
                color: #1976d2;
            }

            .grupo-badge.grupo-seu-souza {
                background: #fce4ec;
                color: #c2185b;
            }

            /* ======================== SEÇÃO 2: ESTATÍSTICAS ======================== */
            .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 25px;
            }

            .stat-card {
                background: #ff6b00;
                border-radius: 20px;
                padding: 25px;
                color: white;
                position: relative;
                overflow: hidden;
                box-shadow: 0 4px 12px rgba(255, 107, 0, 0.2);
                transition: all 0.3s;
            }

            .stat-card:hover {
                transform: translateY(-3px);
                box-shadow: 0 8px 20px rgba(255, 107, 0, 0.3);
            }

            .stat-card.daily-card {
                background: #ff6b00;
            }

            .stat-card.monthly-card {
                background: #e65c00;
            }

            .stat-icon {
                position: absolute;
                top: 20px;
                right: 20px;
                font-size: 3em;
                opacity: 0.3;
            }

            .stat-content {
                position: relative;
                z-index: 1;
            }

            .stat-label {
                font-size: 14px;
                opacity: 0.9;
                margin-bottom: 10px;
                text-transform: uppercase;
                letter-spacing: 1px;
            }

            .stat-value {
                font-size: 48px;
                font-weight: bold;
                line-height: 1;
                margin-bottom: 15px;
            }

            .stat-controls {
                display: flex;
                gap: 10px;
            }

            .adjust-btn {
                width: 40px;
                height: 40px;
                border: 2px solid rgba(255, 255, 255, 0.3);
                background: rgba(255, 255, 255, 0.1);
                color: white;
                border-radius: 12px;
                cursor: pointer;
                transition: all 0.3s;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 18px;
            }

            .adjust-btn:hover {
                background: rgba(255, 255, 255, 0.2);
                border-color: rgba(255, 255, 255, 0.5);
                transform: scale(1.05);
            }


            /* ======================== BOTÕES DE CONTROLE ======================== */
            .control-btn {
                padding: 10px 20px;
                border: none;
                border-radius: 12px;
                cursor: pointer;
                font-weight: 600;
                transition: all 0.3s;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                font-size: 14px;
            }

            .control-btn.secondary {
                background: #e2e8f0;
                color: #475569;
            }

            .control-btn.secondary:hover {
                background: #cbd5e1;
                transform: translateY(-2px);
            }

            .control-btn.small {
                padding: 8px 16px;
                font-size: 13px;
            }

            #force-update-leads {
                background: #ff6b00;
                color: white;
                box-shadow: 0 4px 12px rgba(255, 107, 0, 0.2);
            }

            #force-update-leads:hover {
                background: #e65c00;
                box-shadow: 0 6px 16px rgba(255, 107, 0, 0.3);
                transform: translateY(-2px);
            }

            #force-update-leads:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }

            /* ======================== MODAL DE LEADS ======================== */
            .hapvida-lead-modal-frontend {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 999999;
            }

            .hapvida-lead-modal-overlay-frontend {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.7);
            }

            .hapvida-lead-modal-content-frontend {
                position: relative;
                background: white;
                width: 90%;
                max-width: 800px;
                max-height: 90vh;
                margin: 50px auto;
                border-radius: 20px;
                box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
                overflow: hidden;
                display: flex;
                flex-direction: column;
            }

            .hapvida-lead-modal-header-frontend {
                background: #ff6b00;
                color: white;
                padding: 20px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .hapvida-lead-modal-header-frontend h3 {
                margin: 0;
                font-size: 22px;
            }

            .hapvida-lead-modal-close-frontend {
                background: none;
                border: none;
                color: white;
                cursor: pointer;
                padding: 0;
                width: 30px;
                height: 30px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 50%;
                transition: background 0.3s;
            }

            .hapvida-lead-modal-close-frontend:hover {
                background: rgba(255, 255, 255, 0.2);
            }

            .hapvida-lead-modal-body-frontend {
                padding: 30px;
                overflow-y: auto;
                flex: 1;
            }

            .hapvida-lead-modal-footer-frontend {
                padding: 20px;
                background: #f8f9fa;
                border-top: 1px solid #dee2e6;
                display: flex;
                justify-content: space-between;
            }

            .lead-details-container {
                font-size: 15px;
            }

            .lead-detail-section {
                margin-bottom: 25px;
            }

            .detail-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 15px;
            }

            .detail-item {
                padding: 10px;
                background: #f8f9fa;
                border-left: 3px solid #ff6b00;
                border-radius: 12px;
            }

            .detail-item.full-width {
                grid-column: 1 / -1;
            }

            .detail-item strong {
                color: #495057;
                display: inline-block;
                margin-right: 10px;
            }

            .button-copy-lead,
            .button-close-modal {
                padding: 10px 20px;
                border: none;
                border-radius: 12px;
                cursor: pointer;
                font-size: 14px;
                transition: all 0.3s;
            }

            .button-copy-lead {
                background: #16a34a;
                color: white;
            }

            .button-copy-lead:hover {
                background: #15803d;
                transform: translateY(-2px);
            }

            .button-close-modal {
                background: #64748b;
                color: white;
            }

            .button-close-modal:hover {
                background: #475569;
            }

            /* ======================== RESPONSIVO ======================== */
            @media (max-width: 768px) {
                .hapvida-dashboard {
                    padding: 10px;
                }

                .dashboard-section {
                    padding: 20px;
                    margin-bottom: 15px;
                }

                .stats-grid,
                .lead-stats-grid {
                    grid-template-columns: 1fr;
                }

                .all-leads-section .webhook-stats {
                    grid-template-columns: repeat(2, 1fr);
                }

                .hapvida-lead-modal-content-frontend {
                    width: 95%;
                    margin: 20px auto;
                }

                .detail-grid {
                    grid-template-columns: 1fr;
                }

                .hapvida-lead-modal-footer-frontend {
                    flex-direction: column;
                    gap: 10px;
                }

                .button-copy-lead,
                .button-close-modal {
                    width: 100%;
                }
            }

            @media (max-width: 480px) {
                .all-leads-section .webhook-stats {
                    grid-template-columns: 1fr;
                }

                .all-leads-section .webhook-stat-number {
                    font-size: 2em;
                }
            }

            /* Cursor pointer na linha da tabela */
            .webhook-row {
                cursor: pointer !important;
            }

            .webhook-row:hover {
                background-color: #f0f8ff !important;
            }
        </style>

        <!-- JAVASCRIPT COMPLETO DO DASHBOARD -->
        <script type="text/javascript">
            (function ($) {
                console.log('🚀 Iniciando script de contagem Hapvida - Versão Completa');

                // Configuração global do ajaxurl para frontend
                if (typeof ajaxurl === 'undefined') {
                    window.ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
                    console.log('✅ ajaxurl configurado:', window.ajaxurl);
                }

                var autoRefreshInterval = null;
                var refreshInterval = 30000; // 30 segundos

                // Função para atualizar as contagens
                function updateCounts() {
                    console.log('📊 Atualizando contagens...');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'get_counts',
                            security: $('#get-counts-nonce').val()
                        },
                        success: function (response) {
                            console.log('✅ Contagens recebidas:', response);

                            if (response.success) {
                                $('.daily-count').text(response.data.daily_count);
                                $('.monthly-count').text(response.data.monthly_count);

                                console.log('📈 Valores atualizados: Daily=' + response.data.daily_count + ', Monthly=' + response.data.monthly_count);
                            }
                        },
                        error: function (xhr, status, error) {
                            console.error('❌ Erro ao obter contagens:', error);
                        }
                    });
                }

                // Função para ajustar contagem
                function adjustCount(type, adjustment) {
                    console.log('🔧 Ajustando contagem:', { type: type, adjustment: adjustment });

                    var nonce = $('#adjust-daily-count-nonce').val();

                    if (!nonce) {
                        console.error('❌ Nonce não encontrado!');
                        alert('Erro: Token de segurança não encontrado. Recarregue a página.');
                        return;
                    }

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'adjust_submission_count',
                            count_type: type,
                            adjustment: adjustment,
                            security: nonce
                        },
                        success: function (response) {
                            console.log('✅ Resposta recebida:', response);

                            if (response.success) {
                                $('.daily-count').text(response.data.daily_count);
                                $('.monthly-count').text(response.data.monthly_count);

                                console.log('🎉 Valores atualizados: Daily=' + response.data.daily_count + ', Monthly=' + response.data.monthly_count);

                                // Feedback visual
                                var $button = $('.adjust-' + type + '-' + (adjustment > 0 ? 'plus' : 'minus'));
                                $button.css('background', '#4ade80');
                                setTimeout(function () {
                                    $button.css('background', '');
                                }, 300);
                            } else {
                                console.error('❌ Erro na resposta:', response);
                                alert('Erro: ' + (response.data || 'Erro desconhecido'));
                            }
                        },
                        error: function (xhr, status, error) {
                            console.error('❌ Erro AJAX:', error);
                            console.error('Response:', xhr.responseText);
                        }
                    });
                }

                // CONFIGURAÇÃO DOS EVENTOS
                $(document).ready(function () {
                    console.log('📋 DOM pronto - configurando eventos...');

                    // Verifica elementos
                    console.log('🔍 Verificação de elementos:');
                    console.log('- Botões daily plus:', $('.adjust-daily-plus').length);
                    console.log('- Botões daily minus:', $('.adjust-daily-minus').length);

                    // Event delegation para botões de ajuste
                    $(document).on('click', '.adjust-btn', function (e) {
                        e.preventDefault();
                        e.stopPropagation();

                        var $btn = $(this);
                        var type = $btn.data('type');
                        var adjustment = parseInt($btn.data('adjustment'));

                        console.log('🖱️ Botão clicado:', { type: type, adjustment: adjustment });

                        if (type && adjustment) {
                            adjustCount(type, adjustment);
                        } else {
                            console.error('❌ Dados do botão inválidos');
                        }
                    });

                    // Inicialização
                    console.log('🎯 Inicializando carregamento de dados...');

                    // Carrega dados iniciais
                    updateCounts();

                    // Auto-refresh a cada 30 segundos
                    autoRefreshInterval = setInterval(function () {
                        console.log('⏰ Auto-refresh...');
                        updateCounts();
                    }, refreshInterval);

                    console.log('✅ Dashboard Hapvida inicializado com sucesso!');
                });
            })(jQuery);
        </script>

        <?php

        // Adiciona os scripts do dashboard frontend
        $this->render_frontend_dashboard_scripts();

        return ob_get_clean();
    }

    private function render_delivery_tracking_frontend()
    {
        ?>
        <div class="section-header">
            <h2><i class="fas fa-satellite-dish"></i> Monitoramento de Entregas</h2>
            <div class="delivery-header-actions">
                <div class="auto-deactivation-toggle">
                    <label class="toggle-switch" title="Inativacao automatica apos 2h sem confirmacao">
                        <input type="checkbox" id="toggle-auto-deactivation" checked>
                        <span class="toggle-slider"></span>
                    </label>
                    <span class="toggle-label" id="auto-deactivation-label">Inativar auto</span>
                </div>
                <button id="refresh-delivery-stats" class="control-btn secondary small">
                    <i class="fas fa-sync-alt"></i> Atualizar
                </button>
            </div>
        </div>

        <div class="delivery-stats-grid">
            <div class="delivery-stat-card pending-card">
                <div class="delivery-stat-icon"><i class="fas fa-clock"></i></div>
                <div class="delivery-stat-value" id="delivery-pendentes">--</div>
                <div class="delivery-stat-label">Pendentes</div>
            </div>
            <div class="delivery-stat-card success-card">
                <div class="delivery-stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="delivery-stat-value" id="delivery-entregues">--</div>
                <div class="delivery-stat-label">Entregues</div>
            </div>
            <div class="delivery-stat-card expired-card">
                <div class="delivery-stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="delivery-stat-value" id="delivery-expirados">--</div>
                <div class="delivery-stat-label">Expirados</div>
            </div>
        </div>

        <div id="delivery-pending-list-container"></div>
        <div id="delivery-deactivation-log-container"></div>

        <script>
        (function() {
            var ajaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';
            var pendingData = [];
            var serverTimeDiff = 0;
            var deliveryTimeout = 7200;
            var countdownInterval = null;
            var isHorarioComercial = false;

            function formatCountdown(remainingSec) {
                if (remainingSec <= 0) return '<span class="countdown-expired">EXPIRADO</span>';
                var h = Math.floor(remainingSec / 3600);
                var m = Math.floor((remainingSec % 3600) / 60);
                var s = remainingSec % 60;
                var parts = [];
                if (h > 0) parts.push(h + 'h');
                parts.push(('0' + m).slice(-2) + 'min');
                parts.push(('0' + s).slice(-2) + 's');
                return parts.join(' ');
            }

            function getStatusInfo(remainingSec) {
                if (remainingSec <= 0) return { cls: 'status-danger', text: 'EXPIRADO' };
                if (remainingSec <= 1800) return { cls: 'status-warning', text: 'ALERTA' };
                return { cls: 'status-ok', text: 'Aguardando' };
            }

            function renderPendingTable() {
                var container = document.getElementById('delivery-pending-list-container');

                // Fora do horario comercial: nao mostra pendentes
                if (!isHorarioComercial || !pendingData || pendingData.length === 0) {
                    container.innerHTML = '';
                    return;
                }

                var nowServer = Math.floor(Date.now() / 1000) + serverTimeDiff;
                var html = '<div class="delivery-pending-list"><h3><i class="fas fa-hourglass-half"></i> Entregas aguardando confirmacao</h3>';
                html += '<table class="delivery-table"><thead><tr><th>Vendedor</th><th>Grupo</th><th>Tempo restante</th><th>Status</th></tr></thead><tbody>';
                pendingData.forEach(function(p) {
                    var elapsed = nowServer - p.enviado_timestamp;
                    var remaining = Math.max(0, deliveryTimeout - elapsed);
                    var info = getStatusInfo(remaining);
                    html += '<tr><td>' + p.vendedor + '</td>';
                    html += '<td><span class="grupo-badge">' + p.grupo.toUpperCase() + '</span></td>';
                    html += '<td class="countdown-cell">' + formatCountdown(remaining) + '</td>';
                    html += '<td><span class="status-badge ' + info.cls + '">' + info.text + '</span></td></tr>';
                });
                html += '</tbody></table></div>';
                container.innerHTML = html;
            }

            function startCountdown() {
                if (countdownInterval) clearInterval(countdownInterval);
                if (isHorarioComercial && pendingData.length > 0) {
                    countdownInterval = setInterval(renderPendingTable, 1000);
                }
            }

            function loadDeliveryStats() {
                var btn = document.getElementById('refresh-delivery-stats');
                if (btn) btn.classList.add('spinning');

                var formData = new FormData();
                formData.append('action', 'get_delivery_stats');

                fetch(ajaxUrl, { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (btn) btn.classList.remove('spinning');
                    if (!res.success) return;
                    var d = res.data;

                    // Calcula diferenca entre tempo do servidor e do navegador
                    serverTimeDiff = d.server_time - Math.floor(Date.now() / 1000);
                    deliveryTimeout = d.delivery_timeout || 7200;
                    isHorarioComercial = !!d.is_horario_comercial;

                    document.getElementById('delivery-pendentes').textContent = d.pendentes;
                    document.getElementById('delivery-entregues').textContent = d.entregues;
                    document.getElementById('delivery-expirados').textContent = d.expirados;

                    // Toggle + horario comercial
                    var toggleEl = document.getElementById('toggle-auto-deactivation');
                    var labelEl = document.getElementById('auto-deactivation-label');
                    var toggleContainer = document.querySelector('.auto-deactivation-toggle');

                    if (toggleEl) {
                        toggleEl.checked = (d.auto_deactivation_enabled === '1');
                    }

                    if (labelEl) {
                        if (!isHorarioComercial) {
                            labelEl.textContent = 'Fora do horario comercial';
                            labelEl.style.color = '#94a3b8';
                        } else if (toggleEl && !toggleEl.checked) {
                            labelEl.textContent = 'Inativar auto (OFF)';
                            labelEl.style.color = '#ef4444';
                        } else {
                            labelEl.textContent = 'Inativar auto';
                            labelEl.style.color = '#475569';
                        }
                    }

                    // Guarda dados e renderiza com countdown
                    pendingData = d.pendentes_list || [];
                    renderPendingTable();
                    startCountdown();

                    // Renderiza log de inativacoes (sempre mostra, independente do horario)
                    var logHtml = '';
                    if (d.inativacoes_recentes && d.inativacoes_recentes.length > 0) {
                        logHtml = '<div class="delivery-deactivation-log"><h3><i class="fas fa-ban"></i> Inativacoes automaticas recentes</h3>';
                        logHtml += '<table class="delivery-table"><thead><tr><th>Vendedor</th><th>Grupo</th><th>Inativado em</th><th>Motivo</th></tr></thead><tbody>';
                        d.inativacoes_recentes.forEach(function(log) {
                            logHtml += '<tr><td>' + log.vendedor_nome + '</td>';
                            logHtml += '<td><span class="grupo-badge">' + log.grupo.toUpperCase() + '</span></td>';
                            logHtml += '<td>' + log.inativado_em + '</td>';
                            logHtml += '<td>' + log.motivo + '</td></tr>';
                        });
                        logHtml += '</tbody></table></div>';
                    }
                    document.getElementById('delivery-deactivation-log-container').innerHTML = logHtml;
                })
                .catch(function() {
                    if (btn) btn.classList.remove('spinning');
                });
            }

            // Toggle auto-deactivation
            document.getElementById('toggle-auto-deactivation').addEventListener('change', function() {
                var enabled = this.checked ? '1' : '0';
                var labelEl = document.getElementById('auto-deactivation-label');
                if (labelEl) {
                    if (this.checked) {
                        labelEl.textContent = 'Inativar auto';
                        labelEl.style.color = '#475569';
                    } else {
                        labelEl.textContent = 'Inativar auto (OFF)';
                        labelEl.style.color = '#ef4444';
                    }
                }

                var formData = new FormData();
                formData.append('action', 'toggle_auto_deactivation');
                formData.append('enabled', enabled);
                fetch(ajaxUrl, { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res.success) {
                        alert('Erro ao salvar configuracao');
                    }
                });
            });

            // Carrega ao abrir e atualiza dados a cada 30 segundos
            loadDeliveryStats();
            setInterval(loadDeliveryStats, 30000);

            var refreshBtn = document.getElementById('refresh-delivery-stats');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', loadDeliveryStats);
            }
        })();
        </script>

        <style>
            .delivery-header-actions {
                display: flex;
                align-items: center;
                gap: 15px;
            }
            .auto-deactivation-toggle {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .toggle-switch {
                position: relative;
                display: inline-block;
                width: 44px;
                height: 24px;
                cursor: pointer;
            }
            .toggle-switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }
            .toggle-slider {
                position: absolute;
                top: 0; left: 0; right: 0; bottom: 0;
                background-color: #cbd5e1;
                border-radius: 24px;
                transition: .3s;
            }
            .toggle-slider:before {
                content: "";
                position: absolute;
                height: 18px;
                width: 18px;
                left: 3px;
                bottom: 3px;
                background-color: white;
                border-radius: 50%;
                transition: .3s;
            }
            .toggle-switch input:checked + .toggle-slider {
                background-color: #22c55e;
            }
            .toggle-switch input:checked + .toggle-slider:before {
                transform: translateX(20px);
            }
            .toggle-label {
                font-size: 13px;
                font-weight: 600;
                color: #475569;
            }

            .delivery-stats-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 15px;
                margin-bottom: 25px;
            }
            .delivery-stat-card {
                text-align: center;
                padding: 20px 15px;
                border-radius: 12px;
                background: #f8f9fa;
                border: 1px solid #e2e8f0;
            }
            .delivery-stat-card .delivery-stat-icon {
                font-size: 24px;
                margin-bottom: 8px;
            }
            .delivery-stat-card .delivery-stat-value {
                font-size: 32px;
                font-weight: 700;
                line-height: 1.2;
            }
            .delivery-stat-card .delivery-stat-label {
                font-size: 13px;
                color: #64748b;
                margin-top: 4px;
            }
            .delivery-stat-card.pending-card .delivery-stat-icon,
            .delivery-stat-card.pending-card .delivery-stat-value { color: #eab308; }
            .delivery-stat-card.success-card .delivery-stat-icon,
            .delivery-stat-card.success-card .delivery-stat-value { color: #22c55e; }
            .delivery-stat-card.expired-card .delivery-stat-icon,
            .delivery-stat-card.expired-card .delivery-stat-value { color: #ef4444; }

            .delivery-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
                font-size: 14px;
            }
            .delivery-table th {
                background: #f1f5f9;
                padding: 10px 12px;
                text-align: left;
                font-weight: 600;
                color: #475569;
                border-bottom: 2px solid #e2e8f0;
            }
            .delivery-table td {
                padding: 10px 12px;
                border-bottom: 1px solid #f1f5f9;
            }
            .countdown-cell {
                font-family: 'Courier New', monospace;
                font-weight: 700;
                font-size: 15px;
                color: #334155;
            }
            .countdown-expired {
                color: #ef4444;
                font-weight: 700;
            }
            .grupo-badge {
                background: #0054B8;
                color: white;
                padding: 2px 8px;
                border-radius: 4px;
                font-size: 11px;
                font-weight: 600;
            }
            .status-badge {
                padding: 3px 10px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 600;
            }
            .status-ok { background: #dcfce7; color: #166534; }
            .status-warning { background: #fef9c3; color: #854d0e; }
            .status-danger { background: #fef2f2; color: #991b1b; }

            .delivery-pending-list h3,
            .delivery-deactivation-log h3 {
                font-size: 16px;
                color: #334155;
                margin: 20px 0 10px 0;
            }
            .delivery-pending-list h3 i,
            .delivery-deactivation-log h3 i {
                margin-right: 6px;
            }

            @media (max-width: 600px) {
                .delivery-header-actions { flex-direction: column; gap: 10px; align-items: flex-end; }
                .delivery-stats-grid { grid-template-columns: 1fr; }
                .delivery-table { font-size: 12px; }
                .delivery-table th, .delivery-table td { padding: 8px 6px; }
            }
        </style>
        <?php
    }

    private function render_vendors_management_frontend()
    {
        ?>
        <div class="hapvida-card vendors-management-section">
            <div class="section-header">
                <h2><i class="fas fa-users-cog"></i> Gerenciar Vendedores</h2>
                <button id="refresh-vendors-list" class="control-btn secondary small">
                    <i class="fas fa-sync-alt"></i> Atualizar
                </button>
            </div>

            <!-- Stats minimalistas -->
            <div class="vm-stats-row">
                <div class="vm-stat-pill vm-stat-active">
                    <span class="vm-stat-dot vm-dot-active"></span>
                    <span class="vm-stat-count" id="total-vendors-active">0</span>
                    <span class="vm-stat-text">Ativos</span>
                </div>
                <div class="vm-stat-pill vm-stat-inactive">
                    <span class="vm-stat-dot vm-dot-inactive"></span>
                    <span class="vm-stat-count" id="total-vendors-inactive">0</span>
                    <span class="vm-stat-text">Inativos</span>
                </div>
            </div>

            <div class="vendors-list-container">
                <div class="vendors-group" id="vendors-drv">
                    <div class="vm-group-header">
                        <span class="vm-group-label">DRV</span>
                        <span class="vm-group-count" id="drv-count">0</span>
                    </div>
                    <div class="vendors-grid" id="drv-vendors-grid">
                        <!-- Vendedores serão carregados via AJAX -->
                    </div>
                </div>

                <div class="vendors-group" id="vendors-seu-souza">
                    <div class="vm-group-header">
                        <span class="vm-group-label">Seu Souza</span>
                        <span class="vm-group-count" id="seu-souza-count">0</span>
                    </div>
                    <div class="vendors-grid" id="seu-souza-vendors-grid">
                        <!-- Vendedores serão carregados via AJAX -->
                    </div>
                </div>
            </div>
        </div>

        <style>
            /* ======================== VENDORS MANAGEMENT - MODERN MINIMAL ======================== */
            .vendors-management-section {
                margin: 20px 0;
            }

            /* Stats Row */
            .vm-stats-row {
                display: flex;
                gap: 12px;
                margin: 0 0 28px 0;
            }

            .vm-stat-pill {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 8px 16px;
                border-radius: 100px;
                background: #f8f9fb;
                border: 1px solid #edf0f4;
                font-size: 13px;
            }

            .vm-stat-dot {
                width: 8px;
                height: 8px;
                border-radius: 50%;
                flex-shrink: 0;
            }

            .vm-dot-active { background: #22c55e; }
            .vm-dot-inactive { background: #94a3b8; }

            .vm-stat-count {
                font-weight: 700;
                color: #1e293b;
                font-size: 14px;
            }

            .vm-stat-text {
                color: #64748b;
                font-weight: 500;
            }

            /* Group Header */
            .vm-group-header {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 14px;
            }

            .vm-group-label {
                font-size: 13px;
                font-weight: 600;
                color: #1e293b;
                text-transform: uppercase;
                letter-spacing: 0.6px;
            }

            .vm-group-count {
                font-size: 11px;
                font-weight: 600;
                color: #64748b;
                background: #f1f5f9;
                padding: 2px 8px;
                border-radius: 100px;
            }

            /* Vendors Group */
            .vendors-group {
                margin: 0 0 24px 0;
            }

            .vendors-group:last-child {
                margin-bottom: 0;
            }

            /* Vendors Grid */
            .vendors-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
                gap: 10px;
            }

            /* Vendor Card */
            .vendor-card {
                display: flex;
                align-items: center;
                gap: 12px;
                background: #fff;
                border: 1px solid #edf0f4;
                border-radius: 12px;
                padding: 14px 16px;
                position: relative;
                transition: border-color 0.2s, box-shadow 0.2s;
            }

            .vendor-card:hover {
                border-color: #cbd5e1;
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
            }

            .vendor-card.inactive {
                opacity: 1;
                background: #fafbfc;
            }

            .vendor-card.inactive .vendor-name {
                color: #94a3b8;
            }

            .vendor-card.inactive .vendor-phone {
                color: #cbd5e1;
            }

            /* Avatar */
            .vendor-avatar {
                width: 38px;
                height: 38px;
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 700;
                font-size: 14px;
                color: #fff;
                flex-shrink: 0;
                text-transform: uppercase;
            }

            .vendor-avatar.avatar-active {
                background: linear-gradient(135deg, #ff6b00, #ff8534);
            }

            .vendor-avatar.avatar-inactive {
                background: #cbd5e1;
            }

            /* Vendor Info */
            .vendor-info {
                flex: 1;
                min-width: 0;
            }

            .vendor-card .vendor-name {
                font-weight: 600;
                font-size: 14px;
                color: #1e293b;
                margin: 0 0 2px 0;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .vendor-card .vendor-phone {
                color: #94a3b8;
                font-size: 12px;
                margin: 0;
            }

            .vendor-card .vendor-category {
                display: inline-block;
                background: #f1f5f9;
                color: #64748b;
                padding: 2px 6px;
                border-radius: 4px;
                font-size: 11px;
                margin-top: 4px;
                font-weight: 500;
            }

            /* Status Indicator */
            .vendor-card .vendor-status {
                position: static;
                width: 0;
                height: 0;
                display: none;
            }

            /* Toggle Switch */
            .vm-toggle-wrap {
                flex-shrink: 0;
            }

            .vendor-card .vendor-action-btn {
                position: relative;
                width: 44px;
                height: 24px;
                border-radius: 100px;
                border: none;
                cursor: pointer;
                transition: background 0.25s;
                padding: 0;
                font-size: 0;
                color: transparent;
                outline: none;
            }

            .vendor-action-btn.toggle-status {
                background: #22c55e;
            }

            .vendor-action-btn.toggle-status::after {
                content: '';
                position: absolute;
                top: 3px;
                left: 23px;
                width: 18px;
                height: 18px;
                border-radius: 50%;
                background: #fff;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
                transition: left 0.25s;
            }

            .vendor-card.inactive .vendor-action-btn.toggle-status {
                background: #d1d5db;
            }

            .vendor-card.inactive .vendor-action-btn.toggle-status::after {
                left: 3px;
            }

            .vendor-action-btn.toggle-status:hover {
                filter: brightness(0.95);
            }

            .vendor-action-btn.toggle-status:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }

            /* Vendor Actions container hidden (we use toggle in card flow) */
            .vendor-card .vendor-actions {
                display: contents;
            }

            @media (max-width: 768px) {
                .vendors-grid {
                    grid-template-columns: 1fr;
                }

                .vm-stats-row {
                    flex-wrap: wrap;
                }
            }
        </style>

        <script>
            jQuery(document).ready(function ($) {
                var vendorsData = [];

                // Função para carregar lista de vendedores
                function loadVendorsList() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'get_vendors_list_frontend'
                        },
                        success: function (response) {
                            if (response.success) {
                                vendorsData = response.data.vendors;
                                renderVendors();
                            }
                        },
                        error: function () {
                            console.error('Erro ao carregar vendedores');
                        }
                    });
                }

                // Função auxiliar para obter iniciais
                function getInitials(name) {
                    var parts = name.trim().split(/\s+/);
                    if (parts.length >= 2) {
                        return parts[0].charAt(0) + parts[parts.length - 1].charAt(0);
                    }
                    return parts[0].charAt(0);
                }

                // Função para renderizar vendedores
                function renderVendors() {
                    var drvGrid = $('#drv-vendors-grid');
                    var seuSouzaGrid = $('#seu-souza-vendors-grid');

                    drvGrid.empty();
                    seuSouzaGrid.empty();

                    var stats = {
                        total_ativos: 0,
                        total_inativos: 0,
                        drv_count: 0,
                        seu_souza_count: 0
                    };

                    vendorsData.forEach(function (vendor) {
                        var isActive = vendor.status === 'ativo';

                        if (isActive) {
                            stats.total_ativos++;
                        } else {
                            stats.total_inativos++;
                        }

                        if (vendor.grupo === 'drv') {
                            stats.drv_count++;
                        } else {
                            stats.seu_souza_count++;
                        }

                        var initials = getInitials(vendor.nome);

                        var vendorCard = $('<div class="vendor-card ' + (isActive ? '' : 'inactive') + '">' +
                            '<div class="vendor-status ' + (isActive ? 'active' : 'inactive') + '"></div>' +
                            '<div class="vendor-avatar ' + (isActive ? 'avatar-active' : 'avatar-inactive') + '">' + initials + '</div>' +
                            '<div class="vendor-info">' +
                            '<div class="vendor-name">' + vendor.nome + '</div>' +
                            '<div class="vendor-phone">' + vendor.telefone + '</div>' +
                            (vendor.categoria ? '<div class="vendor-category">' + vendor.categoria + '</div>' : '') +
                            '</div>' +
                            '<div class="vendor-actions">' +
                            '<div class="vm-toggle-wrap">' +
                            '<button class="vendor-action-btn toggle-status" data-vendor-id="' + vendor.id + '" data-grupo="' + vendor.grupo + '" title="' + (isActive ? 'Desativar' : 'Ativar') + '">' +
                            (isActive ? 'Desativar' : 'Ativar') +
                            '</button>' +
                            '</div>' +
                            '</div>' +
                            '</div>');

                        if (vendor.grupo === 'drv') {
                            drvGrid.append(vendorCard);
                        } else {
                            seuSouzaGrid.append(vendorCard);
                        }
                    });

                    // Atualiza estatísticas
                    $('#total-vendors-active').text(stats.total_ativos);
                    $('#total-vendors-inactive').text(stats.total_inativos);
                    $('#drv-count').text(stats.drv_count);
                    $('#seu-souza-count').text(stats.seu_souza_count);
                }

                // Toggle status do vendedor
                $(document).on('click', '.vendor-action-btn.toggle-status', function () {
                    var $btn = $(this);
                    var vendorId = $btn.data('vendor-id');
                    var grupo = $btn.data('grupo');

                    $btn.prop('disabled', true).text('Processando...');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'toggle_vendor_status_frontend',
                            vendedor_id: vendorId,
                            grupo: grupo,
                            vendor_action: 'toggle'
                        },
                        success: function (response) {
                            if (response.success) {
                                loadVendorsList(); // Recarrega a lista
                            } else {
                                alert('Erro: ' + response.data);
                            }
                        },
                        error: function () {
                            alert('Erro ao atualizar status do vendedor');
                        },
                        complete: function () {
                            $btn.prop('disabled', false);
                        }
                    });
                });

                // Botão de refresh
                $('#refresh-vendors-list').on('click', function () {
                    var $btn = $(this);
                    $btn.find('i').addClass('fa-spin');
                    loadVendorsList();
                    setTimeout(function () {
                        $btn.find('i').removeClass('fa-spin');
                    }, 1000);
                });

                // Carrega vendedores ao iniciar
                loadVendorsList();

                // Auto-refresh a cada 30 segundos
                setInterval(loadVendorsList, 30000);
            });
        </script>
        <?php
    }

    private function render_all_leads_section_frontend()
    {
        // Busca TODOS os webhooks salvos
        $all_webhooks = get_option($this->failed_webhooks_option, array());

        // Adiciona IDs únicos se não existirem
        foreach ($all_webhooks as $index => &$webhook) {
            if (!isset($webhook['id']) || empty($webhook['id'])) {
                $webhook['id'] = 'webhook_' . $index;
            }
        }

        echo '<div class="section-header">';
        echo '<h2><i class="fas fa-users"></i> Todos os Leads Recebidos</h2>';
        echo '<button id="force-update-leads" class="control-btn secondary small" style="margin-left: auto;">';
        echo '<i class="fas fa-sync-alt"></i> Atualizar Agora';
        echo '</button>';
        echo '</div>';

        // Calcula estatísticas
        $stats = array(
            'total' => count($all_webhooks),
            'pending' => 0,
            'completed' => 0,
            'failed' => 0
        );

        foreach ($all_webhooks as $webhook) {
            if (isset($webhook['status'])) {
                if ($webhook['status'] === 'success' || $webhook['status'] === 'completed') {
                    $stats['completed']++;
                } elseif ($webhook['status'] === 'pending') {
                    $stats['pending']++;
                } elseif ($webhook['status'] === 'failed') {
                    $stats['failed']++;
                }
            }
        }

        // Tabela de últimos leads
        echo '<div class="webhook-history">';
        echo '<div class="webhook-table-container">';
        echo '<table class="webhook-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Data/Hora</th>';
        echo '<th>Cliente</th>';
        echo '<th>Grupo</th>';
        echo '<th>Telefone</th>';
        echo '<th>Cidade</th>';
        echo '<th>Vendedor</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody id="leads-table-body">';

        // Mensagem de carregamento
        echo '<tr><td colspan="6" style="text-align: center;">Carregando leads...</td></tr>';

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
        echo '</div>';


        // Cards de estatísticas
        echo '<div class="webhook-stats">';
        echo '<div class="webhook-stat-card total">';
        echo '<div class="webhook-stat-number status-total">' . $stats['total'] . '</div>';
        echo '<div class="webhook-stat-label">Total de Leads</div>';
        echo '</div>';

        echo '</div>';

        echo '</div>'; // Fecha a seção


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

    private function render_daily_submissions_section()
    {
        $daily_submissions = get_option($this->daily_submissions_option, array());
        $monthly_submissions = get_option($this->monthly_submissions_option, array());
        $today = current_time('Y-m-d');
        $current_month = current_time('Y-m');
        $today_count = isset($daily_submissions[$today]) ? $daily_submissions[$today] : 0;
        $monthly_count = isset($monthly_submissions[$current_month]) ? $monthly_submissions[$current_month] : 0;

        echo '<div class="hapvida-card">';
        echo '<h2><i class="dashicons dashicons-chart-line"></i> Estatísticas de Submissões</h2>';

        // Cards de estatísticas modernos
        echo '<div class="submissions-overview">';
        echo '<div class="submission-stat-card">';
        echo '<div class="submission-stat-number">' . esc_html($today_count) . '</div>';
        echo '<div class="submission-stat-label">Hoje</div>';
        echo '</div>';
        echo '<div class="submission-stat-card">';
        echo '<div class="submission-stat-number">' . esc_html($monthly_count) . '</div>';
        echo '<div class="submission-stat-label">Este Mês</div>';
        echo '</div>';
        echo '</div>';


        // Tabela responsiva moderna
        if (!empty($daily_submissions)) {
            echo '<h3 style="color: #0054B8; margin-bottom: 15px;">Histórico Detalhado</h3>';
            echo '<div class="modern-table-container">';
            echo '<table class="modern-table">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>Data</th>';
            echo '<th>Submissões</th>';
            echo '<th>Dia da Semana</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            krsort($daily_submissions);
            $count = 0;
            foreach ($daily_submissions as $date => $submissions) {
                if ($count >= 15)
                    break; // Limita a 15 registros mais recentes

                $day_name = date('l', strtotime($date));
                $day_names = array(
                    'Monday' => 'Segunda-feira',
                    'Tuesday' => 'Terça-feira',
                    'Wednesday' => 'Quarta-feira',
                    'Thursday' => 'Quinta-feira',
                    'Friday' => 'Sexta-feira',
                    'Saturday' => 'Sábado',
                    'Sunday' => 'Domingo'
                );
                $day_pt = isset($day_names[$day_name]) ? $day_names[$day_name] : $day_name;

                echo '<tr>';
                echo '<td><strong>' . date('d/m/Y', strtotime($date)) . '</strong></td>';
                echo '<td><span style="background: #0054B8; color: white; padding: 4px 12px; border-radius: 15px; font-weight: 600;">' . esc_html($submissions) . '</span></td>';
                echo '<td style="color: #666;">' . $day_pt . '</td>';
                echo '</tr>';
                $count++;
            }

            echo '</tbody>';
            echo '</table>';
            echo '</div>';
        } else {
            echo '<div style="text-align: center; padding: 40px; color: #666; background: #f8f9ff; border-radius: 8px; border: 2px dashed #0054B8;">';
            echo '<i class="dashicons dashicons-chart-line" style="font-size: 48px; opacity: 0.3; margin-bottom: 15px;"></i>';
            echo '<p style="margin: 0; font-size: 16px;"><em>Nenhuma submissão registrada ainda.</em></p>';
            echo '<p style="margin: 5px 0 0 0; font-size: 14px; opacity: 0.7;">As estatísticas aparecerão aqui após as primeiras submissões.</p>';
            echo '</div>';
        }

        echo '</div>';
    }

    private function render_vendedor_row($index, $vendedor, $grupo = 'drv')
    {
        $status = isset($vendedor['status']) ? $vendedor['status'] : 'ativo';
        ?>
        <tr data-index="<?php echo esc_attr($index); ?>"
            class="vendedor-row <?php echo $status === 'inativo' ? 'vendedor-inativo' : ''; ?>">
            <td data-label="Grupo">
                <select name="vendedores[<?php echo esc_attr($index); ?>][grupo]" class="grupo-select" required>
                    <option value="drv" <?php selected($grupo, 'drv'); ?>>DRV</option>
                    <option value="seu_souza" <?php selected($grupo, 'seu_souza'); ?>>Seu Souza</option>
                </select>
            </td>
            <td data-label="Categoria">
                <?php if ($grupo === 'drv'): ?>
                    <select name="vendedores[<?php echo esc_attr($index); ?>][categoria]" class="categoria-select" required>
                        <option value="fixo" <?php selected(isset($vendedor['categoria']) ? $vendedor['categoria'] : '', 'fixo'); ?>>Fixo</option>
                        <option value="rotativo" <?php selected(isset($vendedor['categoria']) ? $vendedor['categoria'] : '', 'rotativo'); ?>>Rotativo</option>
                    </select>
                <?php else: ?>
                    <input type="hidden" name="vendedores[<?php echo esc_attr($index); ?>][categoria]" value="fixo">
                    <span>N/A</span>
                <?php endif; ?>
            </td>
            <!-- NOVO CAMPO: ID do Vendedor -->
            <td data-label="ID">
                <input type="text" name="vendedores[<?php echo esc_attr($index); ?>][vendedor_id]"
                    value="<?php echo isset($vendedor['vendedor_id']) ? esc_attr($vendedor['vendedor_id']) : ''; ?>"
                    placeholder="ID único" required>
            </td>
            <td data-label="Nome">
                <input type="text" name="vendedores[<?php echo esc_attr($index); ?>][nome]"
                    value="<?php echo isset($vendedor['nome']) ? esc_attr($vendedor['nome']) : ''; ?>" required>
            </td>
            <td data-label="Telefone">
                <input type="text" name="vendedores[<?php echo esc_attr($index); ?>][telefone]"
                    value="<?php echo isset($vendedor['telefone']) ? esc_attr($vendedor['telefone']) : ''; ?>" required>
            </td>
            <!-- COLUNA: Status -->
            <td data-label="Status">
                <select name="vendedores[<?php echo esc_attr($index); ?>][status]" class="status-select" required>
                    <option value="ativo" <?php selected($status, 'ativo'); ?>>✅ Ativo</option>
                    <option value="inativo" <?php selected($status, 'inativo'); ?>>❌ Inativo</option>
                </select>
            </td>
            <td data-label="Ações">
                <div class="vendedor-actions">
                    <!-- Botão Toggle Status -->
                    <button type="button" class="button button-small toggle-status-btn"
                        data-index="<?php echo esc_attr($index); ?>" data-current-status="<?php echo esc_attr($status); ?>"
                        title="<?php echo $status === 'ativo' ? 'Desativar vendedor' : 'Ativar vendedor'; ?>">
                        <?php if ($status === 'ativo'): ?>
                            <i class="dashicons dashicons-hidden"></i> Desativar
                        <?php else: ?>
                            <i class="dashicons dashicons-visibility"></i> Ativar
                        <?php endif; ?>
                    </button>

                    <!-- Botão Remover -->
                    <button type="button" class="button button-secondary remove-vendedor"
                        data-index="<?php echo esc_attr($index); ?>" title="Remover vendedor permanentemente">
                        <i class="dashicons dashicons-trash"></i> Remover
                    </button>

                    <?php
                    // Botoes Google Sheets (Criar, Atualizar, Ver)
                    $vendor_nome = isset($vendedor['nome']) ? $vendedor['nome'] : '';
                    Formulario_Hapvida_Google_Sheets::render_vendor_buttons($vendor_nome);
                    ?>
                </div>
            </td>
        </tr>
        <?php
    }

    // ---------------------------------------------------------------
    public function handle_save_vendedores()
    {
        if (!isset($_POST['vendedores_nonce']) || !wp_verify_nonce($_POST['vendedores_nonce'], 'save_vendedores')) {
            wp_die('Ação não autorizada.');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Você não tem permissão para realizar esta ação.');
        }

        $vendedores = isset($_POST['vendedores']) ? $_POST['vendedores'] : array();
        $vendedores_sanitized = array('drv' => array(), 'seu_souza' => array());

        foreach ($vendedores as $index => $vendedor) {
            if (!empty($vendedor['nome']) && !empty($vendedor['telefone']) && !empty($vendedor['grupo']) && !empty($vendedor['vendedor_id'])) {
                $grupo = sanitize_text_field($vendedor['grupo']);
                $categoria = ($grupo === 'drv' && isset($vendedor['categoria']))
                    ? sanitize_text_field($vendedor['categoria'])
                    : 'fixo';

                // Inclui o status do vendedor
                $status = isset($vendedor['status']) ? sanitize_text_field($vendedor['status']) : 'ativo';

                // NOVO: Inclui o ID do vendedor
                $vendedor_data = array(
                    'vendedor_id' => sanitize_text_field($vendedor['vendedor_id']), // NOVO CAMPO
                    'nome' => sanitize_text_field($vendedor['nome']),
                    'telefone' => sanitize_text_field($vendedor['telefone']),
                    'categoria' => $categoria,
                    'status' => $status,
                );
                $vendedores_sanitized[$grupo][] = $vendedor_data;
            }
        }

        update_option($this->vendedores_option, $vendedores_sanitized);

        // Conta vendedores ativos e inativos para feedback
        $contadores = array('drv_ativo' => 0, 'drv_inativo' => 0, 'seu_souza_ativo' => 0, 'seu_souza_inativo' => 0);
        foreach ($vendedores_sanitized as $grupo => $vendedores_grupo) {
            foreach ($vendedores_grupo as $vendedor) {
                $key = $grupo . '_' . $vendedor['status'];
                if (isset($contadores[$key])) {
                    $contadores[$key]++;
                }
            }
        }

        $message = sprintf(
            'Vendedores salvos com sucesso! DRV: %d ativos, %d inativos | Seu Souza: %d ativos, %d inativos',
            $contadores['drv_ativo'],
            $contadores['drv_inativo'],
            $contadores['seu_souza_ativo'],
            $contadores['seu_souza_inativo']
        );

        wp_redirect(admin_url('options-general.php?page=formulario-hapvida-admin&tab=vendedores&message=' . urlencode($message)));
        exit;
    }

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
        check_ajax_referer('vendedores_nonce', 'security');

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



    /**
     * *** FUNÇÃO CORRIGIDA: format_time_remaining - Formatação consistente de tempo ***
     * ARQUIVO: admin-page.php
     * LOCALIZAÇÃO: Dentro da classe Formulario_Hapvida_Admin, substitua a função format_time_remaining() existente
     * OU ADICIONE se não existir
     */
    private function format_time_remaining($tempo_restante_segundos)
    {
        // *** CORREÇÃO: Se já expirou ***
        if ($tempo_restante_segundos <= 0) {
            $tempo_passado = abs($tempo_restante_segundos);
            if ($tempo_passado < 60) {
                return '🚨 Expirado há ' . $tempo_passado . 's';
            } elseif ($tempo_passado < 3600) {
                $minutos = floor($tempo_passado / 60);
                $segundos_restantes = $tempo_passado % 60;
                return '🚨 Expirado há ' . $minutos . 'm ' . $segundos_restantes . 's';
            } else {
                $horas = floor($tempo_passado / 3600);
                $minutos = floor(($tempo_passado % 3600) / 60);
                return '🚨 Expirado há ' . $horas . 'h ' . $minutos . 'm';
            }
        }

        // *** CORREÇÃO: Se ainda não expirou ***
        if ($tempo_restante_segundos < 60) {
            return $tempo_restante_segundos . 's';
        } elseif ($tempo_restante_segundos < 3600) {
            $minutes = floor($tempo_restante_segundos / 60);
            $remaining_seconds = $tempo_restante_segundos % 60;
            return $minutes . 'm ' . $remaining_seconds . 's';
        } else {
            $hours = floor($tempo_restante_segundos / 3600);
            $minutes = floor(($tempo_restante_segundos % 3600) / 60);
            return $hours . 'h ' . $minutes . 'm';
        }
    }

    /**
     * *** FUNÇÃO AUXILIAR: get_urgency_status - Para calcular status de urgência ***
     * ADICIONE esta função também na classe Formulario_Hapvida_Admin
     */
    private function get_urgency_status($tempo_restante, $timeout_total_minutos = 10)
    {
        $timeout_total_segundos = $timeout_total_minutos * 60;

        if ($tempo_restante <= 0) {
            return 'expired';
        } elseif ($tempo_restante <= ($timeout_total_segundos * 0.3)) { // 30% restante = urgente
            return 'urgent';
        } elseif ($tempo_restante <= ($timeout_total_segundos * 0.6)) { // 60% restante = aviso
            return 'warning';
        } else {
            return 'normal';
        }
    }








    public function clear_excessive_logs()
    {
        $log_file = WP_CONTENT_DIR . '/formulario_hapvida.log';

        if (file_exists($log_file)) {
            $lines = file($log_file);
            $total_lines = count($lines);

            if ($total_lines > 10000) {
                // Mantém apenas as últimas 5000 linhas
                $keep_lines = array_slice($lines, -5000);
                file_put_contents($log_file, implode('', $keep_lines));

                // Log da limpeza usando timezone correto
                $timezone = new DateTimeZone('America/Fortaleza');
                $timestamp = new DateTime('now', $timezone);
                $log_entry = "[" . $timestamp->format('Y-m-d H:i:s') . "] Log limpo: mantidas 5000 de {$total_lines} linhas" . PHP_EOL;
                error_log($log_entry, 3, $log_file);
            }
        }
    }


    public function render_admin_page()
    {

        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['force_check_leads'])) {
            $this->force_check_leads_admin();
            return;
        }


        settings_errors('formulario_hapvida_messages');

        ?>
        <div class="wrap hapvida-admin">

            <div class="hapvida-admin-header">
                <div class="hapvida-admin-title">
                    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
                    <span class="hapvida-version">v2.0</span>
                </div>
            </div>

            <nav class="hapvida-tabs">
                <button class="hapvida-tab active" data-tab="leads">
                    <span class="dashicons dashicons-groups"></span>
                    <span>Leads</span>
                </button>
                <button class="hapvida-tab" data-tab="vendedores">
                    <span class="dashicons dashicons-businesswoman"></span>
                    <span>Vendedores</span>
                </button>
                <button class="hapvida-tab" data-tab="rotas">
                    <span class="dashicons dashicons-admin-site"></span>
                    <span>Rotas</span>
                </button>
                <button class="hapvida-tab" data-tab="config">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <span>Configurações</span>
                </button>
                <button class="hapvida-tab" data-tab="invoice">
                    <span class="dashicons dashicons-media-spreadsheet"></span>
                    <span>Invoice</span>
                </button>
                <button class="hapvida-tab" data-tab="stats">
                    <span class="dashicons dashicons-chart-bar"></span>
                    <span>Estatísticas</span>
                </button>
            </nav>

            <div class="hapvida-container">

                <!-- TAB: LEADS -->
                <div class="hapvida-tab-panel active" data-tab="leads">
                <div class="hapvida-row">
                    <div class="hapvida-column full-width">
                        <?php $this->render_all_leads_section(); ?>
                    </div>
                </div>
                </div>

                <!-- TAB: VENDEDORES -->
                <div class="hapvida-tab-panel" data-tab="vendedores">
                <div class="hapvida-row">
                    <div class="hapvida-column full-width">
                        <div class="hapvida-card">
                            <h2><i class="dashicons dashicons-businesswoman"></i> Gerenciar Vendedores</h2>
                            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"
                                id="vendedores-form">
                                <?php wp_nonce_field('save_vendedores', 'vendedores_nonce'); ?>
                                <input type="hidden" name="action" value="save_vendedores">
                                <div class="vendedores-section">
                                    <!-- AUTO-ATIVAÇÃO SEU SOUZA -->
                                    <?php $auto_activate_enabled = get_option('hapvida_auto_activate_seu_souza', false); ?>
                                    <div class="hapvida-auto-activate-box">
                                        <div class="hapvida-auto-activate-info">
                                            <h3 class="hapvida-auto-activate-title">
                                                <span class="dashicons dashicons-clock"></span>
                                                Auto-Ativação Seu Souza
                                            </h3>
                                            <p class="hapvida-auto-activate-desc">
                                                Ativa automaticamente os vendedores do grupo <strong>Seu Souza</strong> nos <strong>dias úteis das 08h às 12h</strong>.<br>
                                                Fora desse horário e nos fins de semana, eles serão desativados automaticamente.
                                            </p>
                                        </div>
                                        <div class="hapvida-auto-activate-toggle">
                                            <label class="hapvida-switch">
                                                <input type="checkbox" id="auto-activate-seu-souza-toggle"
                                                    <?php checked($auto_activate_enabled, true); ?>>
                                                <span class="hapvida-switch-slider" style="background-color: <?php echo $auto_activate_enabled ? '#0054B8' : '#ccc'; ?>;">
                                                    <span class="hapvida-switch-dot" style="left: <?php echo $auto_activate_enabled ? '27px' : '3px'; ?>;"></span>
                                                </span>
                                            </label>
                                            <span id="auto-activate-status-label" class="hapvida-auto-activate-label" style="color: <?php echo $auto_activate_enabled ? '#0054B8' : '#999'; ?>;">
                                                <?php echo $auto_activate_enabled ? 'Ativado' : 'Desativado'; ?>
                                            </span>
                                        </div>
                                    </div>

                                    <script>
                                    (function($) {
                                        $('#auto-activate-seu-souza-toggle').on('change', function() {
                                            var $toggle = $(this);
                                            var enabled = $toggle.is(':checked');
                                            var $slider = $toggle.next('.hapvida-switch-slider');
                                            var $dot = $slider.find('span');
                                            var $label = $('#auto-activate-status-label');

                                            // Atualiza visual imediatamente
                                            $slider.css('background-color', enabled ? '#0054B8' : '#ccc');
                                            $dot.css('left', enabled ? '27px' : '3px');
                                            $label.text(enabled ? 'Ativado' : 'Desativado');
                                            $label.css('color', enabled ? '#0054B8' : '#999');

                                            $.ajax({
                                                url: ajaxurl,
                                                method: 'POST',
                                                data: {
                                                    action: 'hapvida_toggle_auto_activate_seu_souza',
                                                    security: $('#vendedores_nonce').val() || $('input[name="vendedores_nonce"]').val(),
                                                    enabled: enabled ? 'true' : 'false'
                                                },
                                                success: function(response) {
                                                    if (response.success) {
                                                        // Feedback visual breve
                                                        var $box = $toggle.closest('.hapvida-auto-activate-box');
                                                        $box.css('border-color', enabled ? '#0054B8' : '#e2e8f0');
                                                        setTimeout(function() {
                                                            $box.css('border-color', '#e2e8f0');
                                                        }, 1500);
                                                    }
                                                },
                                                error: function() {
                                                    // Reverte em caso de erro
                                                    $toggle.prop('checked', !enabled);
                                                    $slider.css('background-color', !enabled ? '#0054B8' : '#ccc');
                                                    $dot.css('left', !enabled ? '27px' : '3px');
                                                    $label.text(!enabled ? 'Ativado' : 'Desativado');
                                                    $label.css('color', !enabled ? '#0054B8' : '#999');
                                                    alert('Erro ao salvar. Tente novamente.');
                                                }
                                            });
                                        });
                                    })(jQuery);
                                    </script>

                                    <!-- Google Sheets: area de configuracao -->
                                    <?php Formulario_Hapvida_Google_Sheets::render_config_area(); ?>

                                    <!-- Dentro da seção de vendedores da página admin -->
                                    <div class="vendedores-table-wrapper">
                                        <table class="vendedores-table" id="vendedores-table">
                                            <thead>
                                                <tr>
                                                    <th class="col-grupo">Grupo</th>
                                                    <th class="col-categoria">Categoria</th>
                                                    <th class="col-id">ID</th> <!-- NOVO CAMPO -->
                                                    <th class="col-nome">Nome</th>
                                                    <th class="col-telefone">Telefone</th>
                                                    <th class="col-status">Status</th>
                                                    <th class="col-acoes">Ações</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $vendedores = get_option($this->vendedores_option, array());
                                                if (empty($vendedores)) {
                                                    $this->render_vendedor_row(uniqid(), array('nome' => '', 'telefone' => '', 'vendedor_id' => '', 'categoria' => ''), 'drv');
                                                } else {
                                                    foreach ($vendedores as $grupo => $vendedores_grupo) {
                                                        foreach ($vendedores_grupo as $vendedor) {
                                                            $index = uniqid();
                                                            $this->render_vendedor_row($index, $vendedor, $grupo);
                                                        }
                                                    }
                                                }
                                                ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="vendedores-actions">
                                        <button type="button" id="add-vendedor" class="button button-secondary">
                                            <i class="dashicons dashicons-plus-alt"></i> Adicionar Vendedor
                                        </button>
                                        <?php submit_button('Salvar Vendedores', 'primary', 'submit', false); ?>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                </div>

                <!-- TAB: ROTAS -->
                <div class="hapvida-tab-panel" data-tab="rotas">
                <div class="hapvida-row">
                    <div class="hapvida-column full-width">
                        <div class="hapvida-card">
                            <h2><i class="dashicons dashicons-admin-site"></i> Rotas de Consultores</h2>
                            <p class="hapvida-auto-activate-desc" style="margin-bottom: 16px;">
                                Configure quais URLs devem direcionar leads para consultores específicos.
                                Quando um lead vier de uma página configurada aqui, ele será enviado automaticamente para o
                                consultor correspondente.
                            </p>

                            <?php $this->render_url_consultores_content(); ?>
                        </div>
                    </div>
                </div>
                </div>

                <!-- TAB: CONFIGURAÇÕES (Relatórios + Webhooks) -->
                <div class="hapvida-tab-panel" data-tab="config">
                <!-- Seção de Configurações de Relatórios -->
                <div class="hapvida-row">
                    <div class="hapvida-column full-width">
                        <div class="hapvida-card">
                            <h2><i class="dashicons dashicons-chart-bar"></i> Configurações de Relatórios de Leads</h2>
                            <p class="hapvida-auto-activate-desc" style="margin-bottom: 16px;">
                                Configure as credenciais de acesso para a página de relatórios. Após configurar, adicione o
                                shortcode
                                <code style="background: #f1f5f9; padding: 2px 8px; border-radius: 4px; font-size: 12px;">[hapvida_reports]</code>
                                em qualquer página para exibir o dashboard de relatórios.
                            </p>

                            <?php
                            $options = get_option($this->option_name);
                            $has_drv_username = !empty($options['drv_username']);
                            $has_drv_password = !empty($options['drv_password']);
                            $has_seusouza_username = !empty($options['seusouza_username']);
                            $has_seusouza_password = !empty($options['seusouza_password']);

                            if (!$has_drv_username || !$has_drv_password || !$has_seusouza_username || !$has_seusouza_password) {
                                echo '<div class="hapvida-alert warning">';
                                echo '<strong>⚠️ Atenção:</strong> Configure os usuários e senhas para ambos os grupos (DRV e Seu Souza) para habilitar o acesso aos relatórios.';
                                echo '</div>';
                            } else {
                                echo '<div class="hapvida-alert success">';
                                echo '<strong>✅ Configurado!</strong> Use o shortcode <code>[hapvida_reports]</code> em uma página para acessar os relatórios.';
                                echo '</div>';
                            }
                            ?>

                            <form action="options.php" method="post">
                                <?php settings_fields('formulario_hapvida_settings'); ?>

                                <table class="form-table" role="presentation">
                                    <tr>
                                        <th colspan="2" style="background: #eff6ff; padding: 10px; font-size: 14px; font-weight: 600; color: #1e40af; border-radius: 6px;">
                                            🔵 Grupo DRV
                                        </th>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="drv_username">Usuário DRV</label>
                                        </th>
                                        <td>
                                            <?php
                                            $drv_username = isset($options['drv_username']) ? esc_attr($options['drv_username']) : '';
                                            echo "<input type='text' id='drv_username' class='regular-text' name='{$this->option_name}[drv_username]' value='{$drv_username}' placeholder='Digite o usuário DRV' />";
                                            echo "<p class='description'>Usuário para acessar relatórios do grupo DRV.</p>";
                                            ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="drv_password">Senha DRV</label>
                                        </th>
                                        <td>
                                            <?php
                                            $drv_password = isset($options['drv_password']) ? esc_attr($options['drv_password']) : '';
                                            echo "<input type='password' id='drv_password' class='regular-text' name='{$this->option_name}[drv_password]' value='{$drv_password}' placeholder='Digite uma senha forte' autocomplete='new-password' />";
                                            echo "<p class='description'>Senha para acessar os relatórios do grupo DRV.</p>";
                                            ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th colspan="2" style="background: #fff7ed; padding: 10px; font-size: 14px; font-weight: 600; color: #c2410c; border-radius: 6px;">
                                            🟠 Grupo Seu Souza
                                        </th>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="seusouza_username">Usuário Seu Souza</label>
                                        </th>
                                        <td>
                                            <?php
                                            $seusouza_username = isset($options['seusouza_username']) ? esc_attr($options['seusouza_username']) : '';
                                            echo "<input type='text' id='seusouza_username' class='regular-text' name='{$this->option_name}[seusouza_username]' value='{$seusouza_username}' placeholder='Digite o usuário Seu Souza' />";
                                            echo "<p class='description'>Usuário para acessar relatórios do grupo Seu Souza.</p>";
                                            ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="seusouza_password">Senha Seu Souza</label>
                                        </th>
                                        <td>
                                            <?php
                                            $seusouza_password = isset($options['seusouza_password']) ? esc_attr($options['seusouza_password']) : '';
                                            echo "<input type='password' id='seusouza_password' class='regular-text' name='{$this->option_name}[seusouza_password]' value='{$seusouza_password}' placeholder='Digite uma senha forte' autocomplete='new-password' />";
                                            echo "<p class='description'>Senha para acessar os relatórios do grupo Seu Souza.</p>";
                                            ?>
                                        </td>
                                    </tr>
                                </table>

                                <?php submit_button('💾 Salvar Configurações de Relatórios', 'primary', 'submit', false); ?>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="hapvida-row">
                    <div class="hapvida-column full-width">
                        <div class="hapvida-card">
                            <h2><i class="dashicons dashicons-migrate"></i> Redirecionamento após Envio</h2>
                            <p class="hapvida-auto-activate-desc" style="margin-bottom: 16px;">
                                Configure o comportamento do redirecionamento após o envio do formulário.
                            </p>

                            <?php
                            $options_redirect = get_option($this->option_name);
                            $redirect_ativo = isset($options_redirect['redirect_obrigado']) && $options_redirect['redirect_obrigado'] === '1';
                            ?>

                            <?php if ($redirect_ativo): ?>
                                <div class="hapvida-alert success">
                                    <strong>Ativo:</strong> O lead será redirecionado para a página de obrigado antes de ir ao WhatsApp.
                                </div>
                            <?php else: ?>
                                <div class="hapvida-alert warning">
                                    <strong>Desativado:</strong> O lead será redirecionado direto para o WhatsApp do vendedor.
                                </div>
                            <?php endif; ?>

                            <form action="options.php" method="post">
                                <?php settings_fields('formulario_hapvida_settings'); ?>
                                <input type="hidden" name="<?php echo $this->option_name; ?>[redirect_obrigado]" value="0" />
                                <table class="form-table" role="presentation">
                                    <tr>
                                        <th scope="row">
                                            <label for="redirect_obrigado">Página de Obrigado</label>
                                        </th>
                                        <td>
                                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                                <input type="checkbox" id="redirect_obrigado" name="<?php echo $this->option_name; ?>[redirect_obrigado]" value="1" <?php checked($redirect_ativo, true); ?> />
                                                Redirecionar para página de obrigado antes do WhatsApp
                                            </label>
                                            <p class="description">Se ativado, o lead passa pela página de obrigado antes de abrir o WhatsApp. Se desativado, vai direto para o WhatsApp.</p>
                                        </td>
                                    </tr>
                                </table>
                                <?php submit_button('💾 Salvar Configuração de Redirecionamento', 'primary', 'submit', false); ?>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- MONITORAMENTO DE ENTREGAS (Evolution API) -->
                <div class="hapvida-row">
                    <div class="hapvida-column full-width">
                        <div class="hapvida-card">
                            <h2><i class="dashicons dashicons-visibility"></i> Monitoramento de Entregas (Evolution API)</h2>
                            <p class="hapvida-auto-activate-desc" style="margin-bottom: 16px;">
                                Monitora se os vendedores estão recebendo as mensagens via WhatsApp.
                                Vendedores que não receberem confirmação de entrega em <strong>2 horas</strong> são inativados automaticamente.
                            </p>

                            <?php
                            $settings_delivery = get_option($this->option_name, array());
                            $auto_deact_enabled = isset($settings_delivery['enable_auto_deactivation']) ? $settings_delivery['enable_auto_deactivation'] : '1';
                            ?>
                            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px; padding: 14px 18px; background: <?php echo $auto_deact_enabled === '1' ? '#f0fdf4' : '#fef2f2'; ?>; border: 1px solid <?php echo $auto_deact_enabled === '1' ? '#bbf7d0' : '#fecaca'; ?>; border-radius: 10px;">
                                <label style="position: relative; display: inline-block; width: 50px; height: 26px; cursor: pointer;">
                                    <input type="checkbox" id="admin-toggle-auto-deactivation" <?php checked($auto_deact_enabled, '1'); ?> style="opacity: 0; width: 0; height: 0;">
                                    <span style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: <?php echo $auto_deact_enabled === '1' ? '#22c55e' : '#cbd5e1'; ?>; border-radius: 26px; transition: .3s;"></span>
                                    <span style="position: absolute; content: ''; height: 20px; width: 20px; left: <?php echo $auto_deact_enabled === '1' ? '26px' : '3px'; ?>; bottom: 3px; background-color: white; border-radius: 50%; transition: .3s;"></span>
                                </label>
                                <div>
                                    <strong style="color: <?php echo $auto_deact_enabled === '1' ? '#166534' : '#991b1b'; ?>;" id="admin-auto-deact-label">
                                        <?php echo $auto_deact_enabled === '1' ? 'Inativacao automatica ATIVADA' : 'Inativacao automatica DESATIVADA'; ?>
                                    </strong>
                                    <p style="margin: 2px 0 0; font-size: 12px; color: #64748b;">
                                        Vendedores sem confirmacao de entrega em 2h serao inativados automaticamente (horario comercial).
                                    </p>
                                </div>
                            </div>
                            <script>
                            (function(){
                                var toggle = document.getElementById('admin-toggle-auto-deactivation');
                                if (!toggle) return;
                                toggle.addEventListener('change', function(){
                                    var enabled = this.checked ? '1' : '0';
                                    var container = this.closest('div[style*="display: flex"]');
                                    var label = document.getElementById('admin-auto-deact-label');
                                    var slider = this.nextElementSibling;
                                    var knob = slider.nextElementSibling;

                                    if (this.checked) {
                                        container.style.background = '#f0fdf4';
                                        container.style.borderColor = '#bbf7d0';
                                        slider.style.backgroundColor = '#22c55e';
                                        knob.style.left = '26px';
                                        label.style.color = '#166534';
                                        label.textContent = 'Inativacao automatica ATIVADA';
                                    } else {
                                        container.style.background = '#fef2f2';
                                        container.style.borderColor = '#fecaca';
                                        slider.style.backgroundColor = '#cbd5e1';
                                        knob.style.left = '3px';
                                        label.style.color = '#991b1b';
                                        label.textContent = 'Inativacao automatica DESATIVADA';
                                    }

                                    var formData = new FormData();
                                    formData.append('action', 'toggle_auto_deactivation');
                                    formData.append('enabled', enabled);
                                    fetch('<?php echo admin_url("admin-ajax.php"); ?>', { method: 'POST', body: formData });
                                });
                            })();
                            </script>

                            <?php
                            $pending_deliveries = get_option('hapvida_pending_deliveries', array());
                            $deactivation_log = get_option('hapvida_auto_deactivation_log', array());

                            $count_pendentes = 0;
                            $count_entregues = 0;
                            $count_expirados = 0;
                            foreach ($pending_deliveries as $d) {
                                switch ($d['status']) {
                                    case 'pendente': $count_pendentes++; break;
                                    case 'entregue': $count_entregues++; break;
                                    case 'expirado': $count_expirados++; break;
                                }
                            }
                            ?>

                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px;">
                                <div style="background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; padding: 16px; text-align: center;">
                                    <div style="font-size: 28px; font-weight: 700; color: #d97706;"><?php echo $count_pendentes; ?></div>
                                    <div style="font-size: 13px; color: #92400e;">Pendentes</div>
                                </div>
                                <div style="background: #d1fae5; border: 1px solid #10b981; border-radius: 8px; padding: 16px; text-align: center;">
                                    <div style="font-size: 28px; font-weight: 700; color: #059669;"><?php echo $count_entregues; ?></div>
                                    <div style="font-size: 13px; color: #065f46;">Entregues</div>
                                </div>
                                <div style="background: #fee2e2; border: 1px solid #ef4444; border-radius: 8px; padding: 16px; text-align: center;">
                                    <div style="font-size: 28px; font-weight: 700; color: #dc2626;"><?php echo $count_expirados; ?></div>
                                    <div style="font-size: 13px; color: #991b1b;">Expirados (Vendedor Inativado)</div>
                                </div>
                            </div>

                            <?php if (!empty($deactivation_log)): ?>
                                <h3 style="margin: 20px 0 10px; font-size: 15px; color: #dc2626;">Inativações Automáticas Recentes</h3>
                                <table class="widefat striped" style="font-size: 13px;">
                                    <thead>
                                        <tr>
                                            <th>Vendedor</th>
                                            <th>Telefone</th>
                                            <th>Grupo</th>
                                            <th>Lead</th>
                                            <th>Enviado em</th>
                                            <th>Inativado em</th>
                                            <th>Motivo</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_reverse(array_slice($deactivation_log, -10)) as $log_entry): ?>
                                        <tr>
                                            <td><strong><?php echo esc_html($log_entry['vendedor_nome']); ?></strong></td>
                                            <td><?php echo esc_html($log_entry['vendedor_telefone']); ?></td>
                                            <td><span style="background: <?php echo $log_entry['grupo'] === 'drv' ? '#dbeafe' : '#fff7ed'; ?>; padding: 2px 8px; border-radius: 4px; font-size: 11px;"><?php echo esc_html(strtoupper($log_entry['grupo'])); ?></span></td>
                                            <td><code><?php echo esc_html($log_entry['lead_id']); ?></code></td>
                                            <td><?php echo esc_html($log_entry['enviado_em']); ?></td>
                                            <td><?php echo esc_html($log_entry['inativado_em']); ?></td>
                                            <td style="color: #dc2626;"><?php echo esc_html($log_entry['motivo']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="hapvida-alert success">
                                    <strong>Nenhuma inativação automática registrada.</strong>
                                </div>
                            <?php endif; ?>

                            <?php if ($count_pendentes > 0): ?>
                                <h3 style="margin: 20px 0 10px; font-size: 15px; color: #d97706;">Entregas Pendentes</h3>
                                <table class="widefat striped" style="font-size: 13px;">
                                    <thead>
                                        <tr>
                                            <th>Vendedor</th>
                                            <th>Telefone</th>
                                            <th>Lead</th>
                                            <th>Enviado em</th>
                                            <th>Tempo restante</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_deliveries as $delivery):
                                            if ($delivery['status'] !== 'pendente') continue;
                                            $elapsed = time() - $delivery['enviado_timestamp'];
                                            $remaining = 7200 - $elapsed;
                                            $remaining_min = max(0, round($remaining / 60));
                                        ?>
                                        <tr>
                                            <td><strong><?php echo esc_html($delivery['vendedor_nome']); ?></strong></td>
                                            <td><?php echo esc_html($delivery['vendedor_telefone']); ?></td>
                                            <td><code><?php echo esc_html($delivery['lead_id']); ?></code></td>
                                            <td><?php echo esc_html($delivery['enviado_em']); ?></td>
                                            <td>
                                                <?php if ($remaining_min > 0): ?>
                                                    <span style="color: #d97706; font-weight: 600;"><?php echo $remaining_min; ?> min</span>
                                                <?php else: ?>
                                                    <span style="color: #dc2626; font-weight: 600;">Expirado (aguardando cron)</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>

                            <div style="margin-top: 16px; padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;">
                                <p style="margin: 0 0 8px; font-size: 13px; font-weight: 600;">Endpoint para Evolution API:</p>
                                <code style="display: block; padding: 8px; background: #1e293b; color: #22d3ee; border-radius: 4px; font-size: 12px; word-break: break-all;">
                                    POST <?php echo esc_html(rest_url('formulario-hapvida/v1/evolution-webhook')); ?>
                                </code>
                                <p style="margin: 8px 0 0; font-size: 12px; color: #64748b;">
                                    Configure este endpoint na sua Evolution API ou n8n para receber confirmações de entrega de mensagens.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="hapvida-row">
                    <div class="hapvida-column full-width">
                        <div class="hapvida-card">
                            <h2><i class="dashicons dashicons-admin-settings"></i> Configurações de Webhooks</h2>



                            <form action="options.php" method="post">
                                <?php
                                settings_fields('formulario_hapvida_settings');
                                do_settings_sections('formulario-hapvida-admin');
                                submit_button('Salvar Configurações de Webhooks');
                                ?>
                            </form>
                        </div>
                    </div>
                </div>
                </div>

                <!-- TAB: INVOICE -->
                <div class="hapvida-tab-panel" data-tab="invoice">
                <div class="hapvida-row">
                    <div class="hapvida-column full-width">
                        <div class="hapvida-card">
                            <h2><i class="dashicons dashicons-media-spreadsheet"></i> Geração de Invoice</h2>
                            <p class="hapvida-auto-activate-desc">Gere invoices profissionais para cobrança de leads por período e grupo.</p>

                            <div class="hapvida-invoice-grid">
                                <div class="hapvida-invoice-field">
                                    <label for="invoice_start_date">Data Inicial:</label>
                                    <input type="date" id="invoice_start_date" value="<?php echo date('Y-m-01'); ?>">
                                    <p class="field-hint">Para referência no invoice</p>
                                </div>

                                <div class="hapvida-invoice-field">
                                    <label for="invoice_end_date">Data Final:</label>
                                    <input type="date" id="invoice_end_date" value="<?php echo date('Y-m-d'); ?>">
                                    <p class="field-hint">Para referência no invoice</p>
                                </div>

                                <div class="hapvida-invoice-field">
                                    <label for="invoice_quantity">Quantidade de Leads: <span style="color: #ef4444;">*</span></label>
                                    <input type="number" id="invoice_quantity" min="1" value="100" style="width: 120px;">
                                    <p class="field-hint">Quantidade para faturar</p>
                                </div>

                                <div class="hapvida-invoice-field">
                                    <label for="invoice_advance_payment">Valor Por Conta (R$):</label>
                                    <input type="number" id="invoice_advance_payment" min="0" step="0.01" value="0" placeholder="0,00" style="width: 140px;">
                                    <p class="field-hint">Valor já pago antecipadamente</p>
                                </div>

                                <div class="hapvida-invoice-field">
                                    <label for="invoice_advance_date">Data do Pagamento Antecipado:</label>
                                    <input type="date" id="invoice_advance_date">
                                    <p class="field-hint">Quando foi pago (opcional)</p>
                                </div>

                                <div class="hapvida-invoice-field">
                                    <label for="invoice_group">Grupo:</label>
                                    <select id="invoice_group" style="min-width: 150px;">
                                        <option value="drv">DRV</option>
                                        <option value="seusouza">Seu Souza</option>
                                    </select>
                                </div>

                                <div class="hapvida-invoice-field">
                                    <label>&nbsp;</label>
                                    <button type="button" id="generate_invoice_btn" class="button button-primary">
                                        <i class="dashicons dashicons-media-spreadsheet"></i> Gerar Invoice
                                    </button>
                                </div>
                            </div>

                            <div class="hapvida-invoice-notice">
                                <p style="margin: 0;"><strong>ℹ️ Informação:</strong> A quantidade de leads
                                    será usada para calcular o valor total (Quantidade × R$ 12,00). As datas são apenas para
                                    referência no invoice.</p>
                            </div>

                            <div id="invoice_status" style="margin-top: 12px;"></div>
                        </div>
                    </div>
                </div>

                </div>

                <!-- TAB: ESTATÍSTICAS -->
                <div class="hapvida-tab-panel" data-tab="stats">
                <div class="hapvida-row">
                    <div class="hapvida-column full-width">
                        <?php $this->render_daily_submissions_section(); ?>
                    </div>
                </div>
                </div>

            </div>

        </div>

        <!-- CSS COMPLETO RESPONSIVO - v2 MINIMALISTA -->
        <style>
            /* ===== RESET & BASE ===== */
            .hapvida-admin {
                max-width: 1200px;
                margin: 0 auto;
                padding: 0 20px 40px;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: #f8fafc;
                color: #1a1a2e;
            }

            /* ===== ADMIN HEADER ===== */
            .hapvida-admin-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 24px 0 16px;
            }

            .hapvida-admin-title {
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .hapvida-admin-title h1 {
                margin: 0;
                padding: 0;
                font-size: 22px;
                font-weight: 700;
                color: #1e293b;
                line-height: 1;
            }

            .hapvida-version {
                background: #FF6B00;
                color: #fff;
                font-size: 11px;
                font-weight: 600;
                padding: 2px 8px;
                border-radius: 6px;
                letter-spacing: 0.3px;
            }

            /* ===== TAB NAVIGATION ===== */
            .hapvida-tabs {
                display: flex;
                gap: 4px;
                padding: 4px;
                background: #e2e8f0;
                border-radius: 10px;
                margin-bottom: 24px;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .hapvida-tabs::-webkit-scrollbar {
                display: none;
            }

            .hapvida-tab {
                display: flex;
                align-items: center;
                gap: 6px;
                padding: 10px 16px;
                border: none;
                background: transparent;
                color: #64748b;
                font-size: 13px;
                font-weight: 500;
                border-radius: 8px;
                cursor: pointer;
                transition: all 0.2s ease;
                white-space: nowrap;
                font-family: inherit;
            }

            .hapvida-tab .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
                line-height: 16px;
            }

            .hapvida-tab:hover {
                color: #1e293b;
                background: rgba(255, 255, 255, 0.5);
            }

            .hapvida-tab.active {
                background: #fff;
                color: #1e293b;
                font-weight: 600;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            }

            /* ===== TAB PANELS ===== */
            .hapvida-tab-panel {
                display: none;
            }

            .hapvida-tab-panel.active {
                display: block;
            }

            /* ===== CONTAINER & GRID ===== */
            .hapvida-container {
                display: flex;
                flex-direction: column;
                gap: 20px;
            }

            .hapvida-row {
                display: flex;
                flex-wrap: wrap;
                gap: 20px;
            }

            .hapvida-column {
                flex: 1;
                min-width: 300px;
            }

            .hapvida-column.full-width {
                width: 100%;
                min-width: 100%;
                flex: none;
            }

            /* ===== CARDS ===== */
            .hapvida-card {
                background: #fff;
                border: 1px solid #e2e8f0;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
                padding: 24px;
                border-radius: 12px;
                transition: box-shadow 0.2s ease;
                width: 100%;
                box-sizing: border-box;
            }

            .hapvida-card:hover {
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            }

            .hapvida-card h2 {
                margin: 0 0 20px 0;
                padding: 0 0 12px 0;
                border-bottom: 1px solid #e2e8f0;
                color: #1e293b;
                font-size: 17px;
                font-weight: 600;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .hapvida-card h2 i,
            .hapvida-card h2 .dashicons {
                font-size: 18px;
                width: 18px;
                height: 18px;
                color: #FF6B00;
            }

            /* ===== FORM STYLES ===== */
            .hapvida-card form table.form-table th {
                color: #475569;
                font-weight: 600;
                padding: 12px 10px;
                width: 200px;
                vertical-align: top;
                font-size: 13px;
            }

            .hapvida-card form table.form-table td {
                padding: 12px 10px;
            }

            .hapvida-card form input[type="url"],
            .hapvida-card form input[type="email"],
            .hapvida-card form input[type="text"],
            .hapvida-card form input[type="password"],
            .hapvida-card form textarea {
                border: 1px solid #d1d5db;
                border-radius: 8px;
                padding: 10px 12px;
                transition: all 0.2s ease;
                font-size: 14px;
                background: #fff;
            }

            .hapvida-card form input[type="url"]:focus,
            .hapvida-card form input[type="email"]:focus,
            .hapvida-card form input[type="text"]:focus,
            .hapvida-card form input[type="password"]:focus,
            .hapvida-card form textarea:focus {
                border-color: #FF6B00;
                box-shadow: 0 0 0 3px rgba(255, 107, 0, 0.1);
                outline: none;
            }

            .hapvida-card form .description {
                font-size: 13px;
                color: #64748b;
                margin-top: 6px;
                font-style: normal;
                line-height: 1.4;
            }

            .hapvida-card form .description a {
                background: #FF6B00;
                color: white !important;
                padding: 4px 10px;
                border-radius: 6px;
                text-decoration: none !important;
                font-weight: 600;
                font-size: 11px;
                letter-spacing: 0.3px;
                transition: all 0.2s ease;
                display: inline-block;
                margin-top: 4px;
            }

            .hapvida-card form .description a:hover {
                background: #e65c00;
                transform: translateY(-1px);
            }

            /* ===== BUTTONS ===== */
            .hapvida-card .button-primary,
            .hapvida-admin .button-primary {
                background: #FF6B00 !important;
                border: none !important;
                border-radius: 8px !important;
                padding: 10px 20px !important;
                font-weight: 600 !important;
                font-size: 13px !important;
                letter-spacing: 0.3px;
                transition: all 0.2s ease !important;
                color: #fff !important;
                cursor: pointer;
                line-height: 1.4 !important;
                height: auto !important;
            }

            .hapvida-card .button-primary:hover,
            .hapvida-admin .button-primary:hover {
                background: #e65c00 !important;
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(255, 107, 0, 0.25) !important;
            }

            /* ===== DELETE BUTTON ===== */
            #delete-expired-leads {
                background: #ef4444 !important;
                border-color: #ef4444 !important;
                color: white !important;
                transition: all 0.2s ease;
                font-weight: 500;
                border-radius: 8px !important;
            }

            #delete-expired-leads:hover:not(:disabled) {
                background: #dc2626 !important;
                border-color: #dc2626 !important;
                transform: translateY(-1px);
                box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3);
            }

            #delete-expired-leads:disabled {
                opacity: 0.5;
                cursor: not-allowed;
                transform: none;
                box-shadow: none;
            }

            /* ===== SPINNER ===== */
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }

            .spinning {
                animation: spin 1s linear infinite;
            }

            /* ===== ACTIONS SECTION ===== */
            .hapvida-actions-section {
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 10px;
                padding: 16px;
                margin-bottom: 20px;
            }

            .hapvida-actions-section h3 {
                color: #1e293b;
                margin-top: 0;
                margin-bottom: 12px;
                font-size: 15px;
                font-weight: 600;
            }

            .hapvida-action-buttons {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
                align-items: center;
                margin-bottom: 12px;
            }

            .hapvida-action-info {
                background: #fefce8;
                border: 1px solid #fde047;
                border-radius: 8px;
                padding: 10px 14px;
                font-size: 13px;
                color: #854d0e;
                line-height: 1.5;
            }

            .hapvida-action-info .dashicons {
                vertical-align: middle;
                margin-right: 4px;
            }

            /* ===== ALERTS ===== */
            .hapvida-alert {
                padding: 10px 14px;
                border-radius: 8px;
                margin: 10px 0;
                border-left: 3px solid;
                font-size: 13px;
            }

            .hapvida-alert.success { background-color: #f0fdf4; border-color: #22c55e; color: #166534; }
            .hapvida-alert.error { background-color: #fef2f2; border-color: #ef4444; color: #991b1b; }
            .hapvida-alert.info { background-color: #eff6ff; border-color: #3b82f6; color: #1e40af; }
            .hapvida-alert.warning { background-color: #fefce8; border-color: #eab308; color: #854d0e; }

            /* ===== BUSINESS HOURS STATUS ===== */
            .business-hours-status {
                margin: 16px 0;
                padding: 12px;
                border-radius: 8px;
                text-align: center;
            }

            .status-active {
                background: #f0fdf4;
                color: #166534;
                font-weight: 600;
                font-size: 14px;
                padding: 10px;
                border-radius: 8px;
                border: 1px solid #bbf7d0;
            }

            .status-inactive {
                background: #fef2f2;
                color: #991b1b;
                font-weight: 600;
                font-size: 14px;
                padding: 10px;
                border-radius: 8px;
                border: 1px solid #fecaca;
            }

            /* ===== WEBHOOK EXPLANATION ===== */
            .webhook-explanation {
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-left: 3px solid #FF6B00;
                border-radius: 10px;
                padding: 20px;
                margin-bottom: 24px;
                position: relative;
                overflow: hidden;
            }

            .webhook-explanation::before {
                content: '';
                position: absolute;
                top: -50px;
                right: -50px;
                width: 100px;
                height: 100px;
                background: rgba(255, 107, 0, 0.04);
                border-radius: 50%;
                z-index: 0;
            }

            .webhook-explanation h3 {
                color: #1e293b;
                margin-top: 0;
                margin-bottom: 16px;
                font-size: 16px;
                font-weight: 700;
                position: relative;
                z-index: 1;
            }

            .webhook-explanation h4 {
                font-size: 14px;
                margin-bottom: 6px;
            }

            .webhook-explanation p {
                line-height: 1.5;
            }

            .webhook-explanation .webhook-types {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 16px;
                margin-top: 12px;
                position: relative;
                z-index: 1;
            }

            .webhook-type-card {
                background: white;
                padding: 16px;
                border-radius: 10px;
                border-left: 3px solid;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
                transition: transform 0.2s ease;
            }

            .webhook-type-card:hover { transform: translateY(-1px); }
            .webhook-type-card.first-send { border-left-color: #22c55e; }

            .webhook-type-card h4 {
                margin-top: 0;
                margin-bottom: 8px;
                font-size: 14px;
                font-weight: 600;
            }

            .webhook-type-card.first-send h4 { color: #16a34a; }

            .webhook-type-card p {
                margin: 0;
                font-size: 13px;
                color: #64748b;
                line-height: 1.5;
            }

            @media (max-width: 768px) {
                .webhook-explanation .webhook-types { grid-template-columns: 1fr; }
                .webhook-explanation { padding: 16px; }
            }

            /* ===== VENDEDORES TABLE ===== */
            .vendedores-section {
                width: 100%;
                overflow: hidden;
            }

            .vendedores-table-wrapper {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin-bottom: 16px;
                border-radius: 10px;
                border: 1px solid #e2e8f0;
            }

            .vendedores-table {
                width: 100%;
                min-width: 800px;
                border-collapse: collapse;
                background: #fff;
                font-size: 13px;
            }

            .vendedores-table thead {
                background: #1e293b;
            }

            .vendedores-table th {
                color: white;
                padding: 12px;
                text-align: left;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                font-size: 11px;
                white-space: nowrap;
                position: sticky;
                top: 0;
                z-index: 1;
            }

            .vendedores-table td {
                padding: 10px 12px;
                border-bottom: 1px solid #f1f5f9;
                vertical-align: middle;
            }

            .vendedores-table tr:hover {
                background-color: #f8fafc;
            }

            .col-grupo { width: 120px; }
            .col-categoria { width: 120px; }
            .col-id { width: 100px; }
            .col-nome { width: 200px; }
            .col-telefone { width: 150px; }
            .col-status { width: 120px; }
            .col-acoes { width: 180px; }

            .vendedores-table input[name*="[vendedor_id]"] {
                width: 100%;
                max-width: 100px;
            }

            .vendedores-table input[type="text"],
            .vendedores-table select {
                width: 100%;
                min-width: 100px;
                border: 1px solid #d1d5db;
                border-radius: 6px;
                padding: 6px 8px;
                transition: border-color 0.2s ease;
                box-sizing: border-box;
                font-size: 13px;
            }

            .vendedores-table input[type="text"]:focus,
            .vendedores-table select:focus {
                border-color: #FF6B00;
                outline: none;
                box-shadow: 0 0 0 2px rgba(255, 107, 0, 0.1);
            }

            /* ===== VENDEDOR ACTIONS ===== */
            .vendedor-actions {
                display: flex;
                flex-direction: column;
                gap: 4px;
                align-items: stretch;
                width: 100%;
            }

            .vendedor-actions .button {
                padding: 4px 8px !important;
                font-size: 11px !important;
                font-weight: 600 !important;
                border-radius: 6px !important;
                transition: all 0.2s ease !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 4px !important;
                border: none !important;
                cursor: pointer !important;
                white-space: nowrap;
                min-height: 28px;
            }

            .toggle-status-btn {
                background: #fbbf24 !important;
                color: #1e293b !important;
            }

            .toggle-status-btn:hover {
                background: #f59e0b !important;
                transform: translateY(-1px) !important;
            }

            .toggle-status-btn.status-inativo {
                background: #22c55e !important;
                color: white !important;
            }

            .remove-vendedor {
                background: #ef4444 !important;
                color: #fff !important;
            }

            .remove-vendedor:hover {
                background: #dc2626 !important;
                transform: translateY(-1px) !important;
            }

            .vendedor-row.vendedor-inativo {
                background-color: rgba(239, 68, 68, 0.03) !important;
                opacity: 0.65;
            }

            .vendedores-actions {
                margin-top: 16px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 12px;
                padding: 12px 16px;
                background: #f8fafc;
                border-radius: 10px;
                border: 1px solid #e2e8f0;
            }

            .vendedores-actions .button {
                border-radius: 8px !important;
                padding: 10px 16px !important;
                font-weight: 600 !important;
                transition: all 0.2s ease !important;
                display: flex !important;
                align-items: center !important;
                gap: 6px !important;
            }

            .vendedores-actions .button-secondary {
                background: #64748b !important;
                color: white !important;
                border: none !important;
            }

            .vendedores-actions .button-secondary:hover {
                background: #475569 !important;
            }

            /* ===== WEBHOOK TABLE ===== */
            .webhook-table-container {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                border-radius: 10px;
                border: 1px solid #e2e8f0;
                max-height: 500px;
                overflow-y: auto;
            }

            .webhook-table {
                width: 100%;
                min-width: 800px;
                border-collapse: collapse;
                font-size: 13px;
                background: white;
            }

            .webhook-table thead {
                background: #1e293b;
                position: sticky;
                top: 0;
                z-index: 2;
            }

            .webhook-table th {
                color: white;
                padding: 12px 10px;
                text-align: left;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                font-size: 11px;
                white-space: nowrap;
            }

            .webhook-table td {
                padding: 10px;
                border-bottom: 1px solid #f1f5f9;
                vertical-align: middle;
            }

            .webhook-table tr:hover {
                background-color: #f8fafc;
            }

            .col-datetime { width: 120px; }
            .col-client { width: 200px; }
            .col-group { width: 100px; }
            .col-attempts { width: 100px; }
            .col-response { width: 200px; }

            /* ===== STATUS BADGES ===== */
            .status-badge {
                padding: 3px 8px;
                border-radius: 6px;
                font-size: 10px;
                font-weight: 600;
                text-transform: uppercase;
                white-space: nowrap;
            }

            .status-completed { background: #dcfce7; color: #166534; }
            .status-pending { background: #fef9c3; color: #854d0e; }
            .status-failed { background: #fef2f2; color: #991b1b; }
            .status-unknown { background: #f1f5f9; color: #475569; }

            .group-badge {
                padding: 2px 8px;
                border-radius: 6px;
                font-size: 10px;
                font-weight: 600;
                white-space: nowrap;
            }

            .group-drv { background: #FF6B00; color: white; }
            .group-seu-souza { background: #0054B8; color: white; }

            .attempts-badge {
                background: #f1f5f9;
                padding: 2px 6px;
                border-radius: 6px;
                font-size: 11px;
                font-weight: 600;
            }

            .client-name-link {
                color: #FF6B00;
                font-weight: 600;
                text-decoration: none;
                cursor: pointer;
                display: flex;
                align-items: center;
                gap: 6px;
                padding: 4px 8px;
                border-radius: 6px;
                transition: all 0.2s ease;
            }

            .client-name-link:hover {
                color: #e65c00;
                background: rgba(255, 107, 0, 0.06);
                text-decoration: none;
            }

            .client-name {
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .error-text { color: #ef4444; font-size: 12px; }

            .no-webhooks-message {
                text-align: center;
                padding: 40px;
                color: #64748b;
                background: #f8fafc;
                border-radius: 10px;
                border: 1px dashed #cbd5e1;
            }

            .no-webhooks-message i {
                font-size: 40px;
                opacity: 0.25;
                margin-bottom: 12px;
            }

            /* ===== LEAD TRACKING STATS ===== */
            .lead-tracking-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
                gap: 16px;
                margin-bottom: 20px;
            }

            .lead-stat-card {
                background: white;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                padding: 16px;
                text-align: center;
                transition: all 0.2s ease;
                position: relative;
                overflow: hidden;
            }

            .lead-stat-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            }

            .lead-stat-card.today { border-left: 3px solid #FF6B00; }
            .lead-stat-card.confirmed { border-left: 3px solid #22c55e; }
            .lead-stat-card.pending { border-left: 3px solid #eab308; }
            .lead-stat-card.failed { border-left: 3px solid #ef4444; }
            .lead-stat-card.rate { border-left: 3px solid #8b5cf6; }

            .lead-stat-number {
                font-size: 28px;
                font-weight: 700;
                margin-bottom: 4px;
            }

            .lead-stat-label {
                font-size: 12px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: #64748b;
            }

            .lead-stat-card.today .lead-stat-number { color: #FF6B00; }
            .lead-stat-card.confirmed .lead-stat-number { color: #22c55e; }
            .lead-stat-card.pending .lead-stat-number { color: #eab308; }
            .lead-stat-card.failed .lead-stat-number { color: #ef4444; }
            .lead-stat-card.rate .lead-stat-number { color: #8b5cf6; }

            /* ===== PENDING LEAD CARDS ===== */
            .pending-leads-container {
                display: grid;
                gap: 16px;
                margin-top: 16px;
            }

            .pending-lead-card {
                background: white;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                padding: 20px;
                transition: all 0.2s ease;
                position: relative;
                overflow: hidden;
            }

            .pending-lead-card:hover {
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            }

            .pending-lead-card.urgent {
                border-left: 3px solid #ef4444;
                background: #fefefe;
            }

            .pending-lead-card.warning {
                border-left: 3px solid #eab308;
                background: #fefefe;
            }

            .pending-lead-card.normal {
                border-left: 3px solid #22c55e;
                background: #fefefe;
            }

            .lead-card-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 12px;
                padding-bottom: 12px;
                border-bottom: 1px solid #f1f5f9;
            }

            .lead-client-info h4 {
                margin: 0 0 4px 0;
                color: #1e293b;
                font-size: 16px;
                font-weight: 700;
            }

            .lead-phone {
                margin: 0;
                color: #64748b;
                font-size: 13px;
            }

            .lead-time-info { text-align: right; }

            .time-remaining {
                font-size: 14px;
                margin-bottom: 4px;
            }

            .tentativa-info {
                font-size: 11px;
                color: #64748b;
                background: #f1f5f9;
                padding: 2px 8px;
                border-radius: 6px;
                display: inline-block;
            }

            .lead-card-details {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 12px;
                margin: 12px 0;
            }

            .lead-detail-item {
                background: #f8fafc;
                padding: 10px 14px;
                border-radius: 8px;
                border-left: 3px solid #FF6B00;
                font-size: 13px;
            }

            .lead-card-actions {
                display: flex;
                gap: 8px;
                margin-top: 16px;
                flex-wrap: wrap;
            }

            .btn-force-confirm,
            .btn-resend-webhook,
            .btn-redirect-vendor {
                flex: 1;
                min-width: 140px;
                padding: 10px 16px;
                border: none;
                border-radius: 8px;
                font-weight: 600;
                font-size: 13px;
                cursor: pointer;
                transition: all 0.2s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                letter-spacing: 0.3px;
            }

            .btn-force-confirm {
                background: #22c55e;
                color: white;
            }

            .btn-force-confirm:hover {
                background: #16a34a;
                transform: translateY(-1px);
                box-shadow: 0 4px 8px rgba(34, 197, 94, 0.25);
            }

            /* ===== SUBMISSION STATS ===== */
            .submissions-overview {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 16px;
                margin-bottom: 20px;
            }

            .submission-stat-card {
                background: #fff;
                border: 1px solid #e2e8f0;
                border-left: 4px solid #FF6B00;
                color: #1e293b;
                padding: 20px;
                border-radius: 12px;
                text-align: center;
                position: relative;
                overflow: hidden;
            }

            .submission-stat-number {
                font-size: 28px;
                font-weight: 700;
                margin-bottom: 4px;
                color: #FF6B00;
                position: relative;
                z-index: 1;
            }

            .submission-stat-label {
                font-size: 13px;
                color: #64748b;
                font-weight: 500;
                position: relative;
                z-index: 1;
            }

            .submissions-actions {
                display: flex;
                justify-content: flex-end;
                margin-bottom: 20px;
            }

            #clear-submission-stats {
                background: #ef4444 !important;
                color: white !important;
                border: none !important;
                border-radius: 8px !important;
                padding: 10px 16px !important;
                font-weight: 600 !important;
                transition: all 0.2s ease !important;
                display: flex !important;
                align-items: center !important;
                gap: 6px !important;
            }

            #clear-submission-stats:hover {
                background: #dc2626 !important;
                transform: translateY(-1px) !important;
                box-shadow: 0 4px 8px rgba(239, 68, 68, 0.25) !important;
            }

            /* ===== MODERN TABLE ===== */
            .modern-table-container {
                background: white;
                border-radius: 10px;
                overflow: hidden;
                border: 1px solid #e2e8f0;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .modern-table {
                width: 100%;
                min-width: 600px;
                border-collapse: collapse;
                font-size: 13px;
            }

            .modern-table th {
                background: #1e293b;
                color: white;
                padding: 12px;
                text-align: left;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                font-size: 11px;
                white-space: nowrap;
            }

            .modern-table td {
                padding: 12px;
                border-bottom: 1px solid #f1f5f9;
                vertical-align: middle;
                white-space: nowrap;
            }

            .modern-table tr:hover { background-color: #f8fafc; }
            .modern-table tr:last-child td { border-bottom: none; }

            /* ===== WEBHOOK STATS ===== */
            .webhook-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 16px;
                margin-bottom: 20px;
            }

            .webhook-stat-card {
                background: white;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                padding: 16px;
                text-align: center;
                transition: all 0.2s ease;
                position: relative;
                overflow: hidden;
            }

            .webhook-stat-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            }

            .webhook-stat-card.total { border-left: 3px solid #FF6B00; }
            .webhook-stat-card.pending { border-left: 3px solid #eab308; }
            .webhook-stat-card.completed { border-left: 3px solid #22c55e; }
            .webhook-stat-card.failed { border-left: 3px solid #ef4444; }

            .webhook-stat-number {
                font-size: 28px;
                font-weight: 700;
                margin-bottom: 4px;
            }

            .webhook-stat-label {
                font-size: 12px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: #64748b;
            }

            .status-pending { color: #eab308; }
            .status-completed { color: #22c55e; }
            .status-failed { color: #ef4444; }
            .status-total { color: #FF6B00; }

            .webhook-actions {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
                margin-bottom: 20px;
            }

            .webhook-actions .button {
                border-radius: 8px !important;
                padding: 10px 16px !important;
                font-weight: 600 !important;
                transition: all 0.2s ease !important;
                display: flex !important;
                align-items: center !important;
                gap: 6px !important;
            }

            .button-retry {
                background: #eab308 !important;
                border: none !important;
                color: #1e293b !important;
                border-radius: 8px !important;
            }

            .button-retry:hover {
                background: #ca8a04 !important;
                transform: translateY(-1px) !important;
            }

            .button-clear {
                background: #64748b !important;
                border: none !important;
                color: #fff !important;
                border-radius: 8px !important;
            }

            .button-clear:hover {
                background: #475569 !important;
                transform: translateY(-1px) !important;
            }

            /* ===== MODAL ===== */
            .hapvida-lead-modal {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 999999;
                display: none;
                align-items: center;
                justify-content: center;
                opacity: 0;
                visibility: hidden;
                transition: all 0.2s ease;
            }

            .hapvida-lead-modal.show {
                display: flex !important;
                opacity: 1;
                visibility: visible;
            }

            .hapvida-lead-modal-overlay {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(15, 23, 42, 0.5);
                backdrop-filter: blur(4px);
                -webkit-backdrop-filter: blur(4px);
            }

            .hapvida-lead-modal-content {
                position: relative;
                background: white;
                border-radius: 12px;
                width: 90%;
                max-width: 500px;
                max-height: 85vh;
                overflow: hidden;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
                transform: scale(0.95);
                transition: transform 0.2s ease;
            }

            .hapvida-lead-modal.show .hapvida-lead-modal-content {
                transform: scale(1);
            }

            .hapvida-lead-modal-header {
                background: #1e293b;
                color: white;
                padding: 16px 20px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .hapvida-lead-modal-header h3 {
                margin: 0;
                font-size: 16px;
                font-weight: 600;
            }

            .hapvida-lead-modal-close {
                background: rgba(255, 255, 255, 0.15);
                border: none;
                color: white;
                width: 30px;
                height: 30px;
                border-radius: 6px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 14px;
                cursor: pointer;
                transition: background 0.2s ease;
            }

            .hapvida-lead-modal-close:hover {
                background: rgba(255, 255, 255, 0.25);
            }

            .hapvida-lead-modal-body {
                padding: 20px;
                max-height: 70vh;
                overflow-y: auto;
            }

            .lead-detail-item {
                display: flex;
                align-items: flex-start;
                gap: 10px;
                margin-bottom: 12px;
                padding: 10px 14px;
                background: #f8fafc;
                border-radius: 8px;
                border-left: 3px solid #FF6B00;
            }

            .lead-detail-item:last-child { margin-bottom: 0; }

            /* ===== RESPONSIVE ===== */
            @media (max-width: 1024px) {
                .hapvida-admin { padding: 0 12px 30px; }

                .submissions-overview,
                .webhook-stats,
                .lead-tracking-stats {
                    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
                }

                .vendedores-table { min-width: 700px; }
                .webhook-table { min-width: 700px; }
            }

            @media (max-width: 782px) {
                .hapvida-admin { padding: 0 8px 20px; }
                .hapvida-card { padding: 16px; }
                .hapvida-row { flex-direction: column; }
                .hapvida-column { min-width: 100%; }

                .hapvida-tabs {
                    gap: 2px;
                    padding: 3px;
                }

                .hapvida-tab {
                    padding: 8px 12px;
                    font-size: 12px;
                }

                .hapvida-tab .dashicons {
                    font-size: 14px;
                    width: 14px;
                    height: 14px;
                }

                .vendedores-table-wrapper { font-size: 12px; }
                .vendedores-table { min-width: 600px; }

                .vendedores-table th,
                .vendedores-table td { padding: 8px 6px; }

                .vendedores-table input[type="text"],
                .vendedores-table select {
                    min-width: 80px;
                    padding: 4px 6px;
                    font-size: 12px;
                }

                .vendedor-actions .button {
                    padding: 3px 6px !important;
                    font-size: 10px !important;
                    min-height: 24px;
                }

                .webhook-table-container { font-size: 11px; }
                .webhook-table { min-width: 600px; }

                .webhook-table th,
                .webhook-table td { padding: 6px 4px; }

                .col-attempts,
                .col-response { display: none; }

                .submissions-overview,
                .webhook-stats,
                .lead-tracking-stats {
                    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
                    gap: 10px;
                }

                .submissions-actions,
                .webhook-actions,
                .vendedores-actions {
                    flex-direction: column;
                    align-items: stretch;
                }

                .webhook-actions .button,
                .vendedores-actions .button {
                    width: 100% !important;
                    margin-bottom: 8px;
                }

                .lead-card-header {
                    flex-direction: column;
                    text-align: left;
                }

                .lead-time-info {
                    text-align: left;
                    margin-top: 8px;
                }

                .lead-card-details {
                    grid-template-columns: 1fr;
                    gap: 8px;
                }

                .lead-card-actions { flex-direction: column; }

                .btn-force-confirm,
                .btn-resend-webhook,
                .btn-redirect-vendor { min-width: auto; }
            }

            @media (max-width: 480px) {
                .hapvida-admin-header { padding: 16px 0 12px; }

                .hapvida-admin-title h1 { font-size: 18px; }

                .hapvida-tab span:not(.dashicons) { display: none; }
                .hapvida-tab { padding: 8px 12px; }

                .hapvida-card h2 { font-size: 15px; }

                .submission-stat-number,
                .webhook-stat-number,
                .lead-stat-number { font-size: 22px; }

                .col-group,
                .col-categoria { display: none; }

                .vendedores-table { min-width: 400px; }
                .webhook-table { min-width: 400px; }

                .modern-table th:nth-child(3),
                .modern-table td:nth-child(3) { display: none; }

                .vendedor-actions {
                    flex-direction: column;
                    gap: 3px;
                }

                .vendedor-actions .button {
                    width: 100% !important;
                    font-size: 9px !important;
                    padding: 3px 4px !important;
                }

                .hapvida-lead-modal-content {
                    width: 95%;
                    margin: 10px;
                    max-height: 90vh;
                }

                .hapvida-lead-modal-header { padding: 14px 16px; }
                .hapvida-lead-modal-header h3 { font-size: 15px; }
                .hapvida-lead-modal-body { padding: 16px; }
                .lead-detail-item { padding: 8px 10px; margin-bottom: 10px; }
            }

            @media (max-width: 768px) {
                .vendedores-table td[data-label="ID"]:before {
                    content: "ID: ";
                    font-weight: bold;
                }
            }

            /* ===== AUTO-ACTIVATE BOX ===== */
            .hapvida-auto-activate-box {
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 10px;
                padding: 16px 20px;
                margin-bottom: 16px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                flex-wrap: wrap;
            }

            .hapvida-auto-activate-info {
                flex: 1;
                min-width: 250px;
            }

            .hapvida-auto-activate-title {
                margin: 0 0 6px;
                font-size: 14px;
                color: #1e293b;
                display: flex;
                align-items: center;
                gap: 8px;
                font-weight: 600;
            }

            .hapvida-auto-activate-title .dashicons {
                color: #0054B8;
                font-size: 16px;
                width: 16px;
                height: 16px;
            }

            .hapvida-auto-activate-desc {
                margin: 0;
                color: #64748b;
                font-size: 13px;
                line-height: 1.5;
            }

            .hapvida-auto-activate-toggle {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .hapvida-switch {
                position: relative;
                display: inline-block;
                width: 48px;
                height: 26px;
            }

            .hapvida-switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }

            .hapvida-switch-slider {
                position: absolute;
                cursor: pointer;
                top: 0; left: 0; right: 0; bottom: 0;
                transition: 0.3s;
                border-radius: 26px;
            }

            .hapvida-switch-dot {
                position: absolute;
                height: 20px;
                width: 20px;
                bottom: 3px;
                background-color: white;
                transition: 0.3s;
                border-radius: 50%;
                box-shadow: 0 1px 3px rgba(0,0,0,0.2);
            }

            .hapvida-auto-activate-label {
                font-size: 13px;
                font-weight: 600;
                min-width: 70px;
            }

            /* ===== INVOICE FORM ===== */
            .hapvida-invoice-grid {
                display: flex;
                gap: 16px;
                align-items: flex-end;
                flex-wrap: wrap;
                margin: 16px 0;
            }

            .hapvida-invoice-field label {
                display: block;
                margin-bottom: 4px;
                font-weight: 600;
                font-size: 13px;
                color: #475569;
            }

            .hapvida-invoice-field input,
            .hapvida-invoice-field select {
                padding: 8px 12px;
                border: 1px solid #d1d5db;
                border-radius: 8px;
                font-size: 13px;
            }

            .hapvida-invoice-field input:focus,
            .hapvida-invoice-field select:focus {
                border-color: #FF6B00;
                outline: none;
                box-shadow: 0 0 0 2px rgba(255, 107, 0, 0.1);
            }

            .hapvida-invoice-field .field-hint {
                margin: 4px 0 0;
                font-size: 11px;
                color: #94a3b8;
            }

            .hapvida-invoice-notice {
                background: #fefce8;
                border-left: 3px solid #eab308;
                padding: 10px 14px;
                margin-top: 16px;
                border-radius: 8px;
                font-size: 13px;
                color: #854d0e;
            }
        </style>

                <!-- TAB SWITCHING JS -->
                <script type="text/javascript">
                (function() {
                    document.addEventListener('DOMContentLoaded', function() {
                        var tabs = document.querySelectorAll('.hapvida-tab');
                        var panels = document.querySelectorAll('.hapvida-tab-panel');
                        var storageKey = 'hapvida_active_tab';

                        function switchTab(tabName) {
                            tabs.forEach(function(t) {
                                t.classList.toggle('active', t.getAttribute('data-tab') === tabName);
                            });
                            panels.forEach(function(p) {
                                p.classList.toggle('active', p.getAttribute('data-tab') === tabName);
                            });
                            try { localStorage.setItem(storageKey, tabName); } catch(e) {}
                        }

                        tabs.forEach(function(tab) {
                            tab.addEventListener('click', function(e) {
                                e.preventDefault();
                                switchTab(this.getAttribute('data-tab'));
                            });
                        });

                        // Restaurar aba salva
                        try {
                            var saved = localStorage.getItem(storageKey);
                            if (saved && document.querySelector('.hapvida-tab-panel[data-tab="' + saved + '"]')) {
                                switchTab(saved);
                            }
                        } catch(e) {}
                    });
                })();
                </script>

                <?php
                // Google Sheets: CSS + Modal + JS
                Formulario_Hapvida_Google_Sheets::render_css();
                Formulario_Hapvida_Google_Sheets::render_modal();
                Formulario_Hapvida_Google_Sheets::render_js();
                ?>

                <script type="text/javascript">
                    (function ($) {
                        // Configuração global do ajaxurl para frontend
                        if (typeof ajaxurl === 'undefined') {
                            window.ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
                        }

                        var autoRefreshInterval = null;
                        var refreshInterval = 30000; // 30 segundos

                        function updateCounts() {
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'get_counts',
                                    security: $('#get-counts-nonce').val()
                                },
                                success: function (response) {
                                    if (response.success) {
                                        $('.daily-count').text(response.data.daily_count);
                                        $('.monthly-count').text(response.data.monthly_count);
                                    }
                                },
                                error: function () {
                                    console.error('Erro ao obter contagens');
                                }
                            });
                        }

                        function adjustCount(type, adjustment) {
                            var nonce = $('#adjust-daily-count-nonce').val();

                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'adjust_submission_count',
                                    count_type: type,
                                    adjustment: adjustment,
                                    security: nonce
                                },
                                success: function (response) {
                                    if (response.success) {
                                        $('.daily-count').text(response.data.daily_count);
                                        $('.monthly-count').text(response.data.monthly_count);
                                    }
                                },
                                error: function () {
                                    console.error('Erro ao ajustar contagem');
                                }
                            });
                        }

                        function showMessage(message, type) {
                            var messageDiv = $('<div class="hapvida-message ' + type + '">' + message + '</div>');
                            $('.hapvida-dashboard').prepend(messageDiv);

                            setTimeout(function () {
                                messageDiv.fadeOut(300, function () {
                                    $(this).remove();
                                });
                            }, 3000);
                        }




                        function fetchPendingWebhooks() {
                            $('#pending-webhooks-container').html('<div class="loading-state"><i class="fas fa-spinner fa-spin"></i><span>Carregando webhooks pendentes...</span></div>');

                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'get_pending_webhooks_frontend',
                                    security: $('#webhook-nonce').val()
                                },
                                success: function (response) {
                                    if (response.success) {
                                        renderPendingWebhooks(response.data.webhooks);
                                    } else {
                                        $('#pending-webhooks-container').html('<div class="message error">Erro: ' + (response.data || 'Erro desconhecido') + '</div>');
                                    }
                                },
                                error: function (xhr, status, error) {
                                    console.error('Erro AJAX ao buscar webhooks pendentes:', error);
                                    $('#pending-webhooks-container').html('<div class="message error">Erro de conexão ao carregar webhooks</div>');
                                }
                            });
                        }


                        public function ajax_clear_vendor_stats() {
                            // Verifica nonce
                            if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'clear_vendor_stats_nonce')) {
                                wp_send_json_error('Nonce inválido');
                                return;
                            }

                            // Verifica permissões
                            if (!current_user_can('manage_options')) {
                                wp_send_json_error('Permissão negada');
                                return;
                            }

                            // Limpa a option de atividades
                            $activity_option = 'formulario_hapvida_vendor_activity';
                            $activities_before = get_option($activity_option, array());
                            $count_before = count($activities_before);

                            // Remove a option
                            $result = delete_option($activity_option);

                            // Limpa do cache
                            wp_cache_delete($activity_option, 'options');

                            // Log da ação
                            error_log("HAPVIDA: Estatísticas de vendedores limpas - {$count_before} registros removidos");

                            if ($result || $count_before == 0) {
                                wp_send_json_success(array(
                                    'message' => "Estatísticas limpas com sucesso! {$count_before} registros removidos.",
                                    'removed_count' => $count_before
                                ));
                            } else {
                                wp_send_json_error('Falha ao limpar estatísticas');
                            }
                        }


                        function renderPendingWebhooks(webhooks) {
                            var container = $('#pending-webhooks-container');

                            if (!webhooks || webhooks.length === 0) {
                                container.html('<div class="no-webhooks-state"><i class="fas fa-check-circle"></i><span>✅ Nenhum webhook pendente!</span></div>');
                                return;
                            }

                            var html = '';
                            webhooks.forEach(function (webhook) {
                                var urgencyClass = webhook.urgency || 'normal';
                                var timeColor = webhook.urgency === 'urgent' ? '#dc3545' : (webhook.urgency === 'warning' ? '#ffc107' : '#28a745');

                                html += '<div class="webhook-item ' + urgencyClass + '" data-webhook-id="' + webhook.webhook_id + '">';
                                html += '  <div class="webhook-header">';
                                html += '    <div class="webhook-client-name">' + (webhook.client_name || 'N/A') + '</div>';
                                html += '    <div class="webhook-next-attempt" style="color: ' + timeColor + '">' + (webhook.next_attempt || 'N/A') + '</div>';
                                html += '  </div>';
                                html += '  <div class="webhook-details">';
                                html += '    <div class="webhook-detail"><strong>📱 Telefone:</strong> ' + (webhook.client_phone || 'N/A') + '</div>';
                                html += '    <div class="webhook-detail"><strong>🏙️ Cidade:</strong> ' + (webhook.client_city || 'N/A') + '</div>';
                                html += '    <div class="webhook-detail"><strong>👤 Vendedor:</strong> ' + (webhook.vendor_name || 'N/A') + '</div>';
                                html += '    <div class="webhook-detail"><strong>🏢 Grupo:</strong> ' + (webhook.vendor_group || 'N/A') + '</div>';
                                html += '    <div class="webhook-detail"><strong>🔄 Tentativas:</strong> ' + (webhook.attempts || 0) + '/' + (webhook.max_attempts || 3) + '</div>';
                                html += '    <div class="webhook-detail"><strong>⏰ Última tentativa:</strong> ' + (webhook.last_attempt || 'N/A') + '</div>';
                                html += '  </div>';

                                if (webhook.error_message && webhook.error_message !== 'N/A') {
                                    html += '  <div class="webhook-error">';
                                    html += '    <strong>❌ Erro:</strong> ' + webhook.error_message;
                                    html += '  </div>';
                                }

                                html += '</div>';
                            });

                            container.html(html);
                        }

                        function refreshAllData() {
                            updateCounts();
                            fetchPendingWebhooks();
                        }


                        $(document).ready(function () {
                            updateCounts();

                            $('#daily-increment').on('click', function () {
                                adjustCount('daily', 1);
                            });

                            $('#daily-decrement').on('click', function () {
                                adjustCount('daily', -1);
                            });

                            $('#monthly-increment').on('click', function () {
                                adjustCount('monthly', 1);
                            });

                            $('#monthly-decrement').on('click', function () {
                                adjustCount('monthly', -1);
                            });

                        });

                        $(window).on('beforeunload', function () {
                            if (autoRefreshInterval) {
                                clearInterval(autoRefreshInterval);
                            }
                        });

                    })(jQuery);
                </script>

                <?php

                ?>

                <script>

                    function deleteExpiredLeads() {
                        // Verifica se o nonce está disponível
                        if (!window.hapvidaLeadNonces || !window.hapvidaLeadNonces.deleteExpiredLeads) {
                            alert('❌ Erro de segurança: Nonce não encontrado. Recarregue a página e tente novamente.');
                            return;
                        }

                        // Primeira confirmação
                        if (!confirm('🚨 ATENÇÃO MÁXIMA!\n\nVocê está prestes a EXCLUIR TODOS OS LEADS do sistema!\n\nEsta ação irá remover:\n✅ TODOS os leads aguardando\n✅ TODOS os leads confirmados\n✅ TODOS os leads expirados\n✅ TODO o histórico\n✅ TODAS as atividades dos vendedores\n\n🚨 ESTA AÇÃO É IRREVERSÍVEL!\n🚨 TODO O SISTEMA FICARÁ ZERADO!\n\nTEM CERTEZA ABSOLUTA?')) {
                            return;
                        }

                        // Segunda confirmação
                        if (!confirm('🔥 ÚLTIMA CONFIRMAÇÃO!\n\nVocê tem CERTEZA ABSOLUTA que quer APAGAR TUDO?\n\nTodo o sistema será resetado para o estado inicial.\n\nEsta é sua última chance de cancelar.\n\nTEM CERTEZA?')) {
                            return;
                        }

                        var button = document.getElementById('delete-expired-leads');
                        if (!button) {
                            alert('❌ Botão não encontrado!');
                            return;
                        }

                        var originalText = button.innerHTML;

                        // Desabilita botão e mostra loading
                        button.disabled = true;
                        button.innerHTML = '<i class="dashicons dashicons-update"></i> 🗑️ APAGANDO TUDO...';

                        // Dados da requisição
                        var ajaxData = {
                            action: 'delete_expired_leads',
                            security: window.hapvidaLeadNonces.deleteExpiredLeads
                        };

                        var adminAjaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';

                        jQuery.ajax({
                            url: adminAjaxUrl,
                            type: 'POST',
                            data: ajaxData,
                            timeout: 30000,
                            success: function (response) {
                                if (response.success) {
                                    var message = response.data.message || 'Operação concluída';
                                    var deletedCount = response.data.deleted_count || 0;

                                    alert('🗑️ SISTEMA COMPLETAMENTE LIMPO!\n\n✅ ' + message + '\n\n📊 Total de leads excluídos: ' + deletedCount + '\n\n🔄 A página será recarregada em 3 segundos...');

                                    setTimeout(function () {
                                        window.location.reload();
                                    }, 3000);

                                } else {
                                    var errorMsg = response.data || 'Erro desconhecido';
                                    alert('❌ Erro ao excluir leads: ' + errorMsg);

                                    // Restaura o botão
                                    button.disabled = false;
                                    button.innerHTML = originalText;
                                }
                            },
                            error: function (xhr, status, error) {
                                var errorMessage = 'Erro de comunicação: ' + error;
                                if (xhr.responseText) {
                                    errorMessage += '\n\nDetalhes: ' + xhr.responseText.substring(0, 200);
                                }

                                alert('❌ ' + errorMessage);

                                // Restaura o botão
                                button.disabled = false;
                                button.innerHTML = originalText;
                            }
                        });
                    }

                    // Registra o evento do botão
                    jQuery(document).ready(function ($) {
                        $(document).off('click', '#delete-expired-leads').on('click', '#delete-expired-leads', function (e) {
                            e.preventDefault();
                            deleteExpiredLeads();
                        });
                    });

                    // Registra o evento do botão quando o DOM estiver pronto
                    jQuery(document).ready(function ($) {

                        var deleteButton = $('#delete-expired-leads');

                        if (deleteButton.length === 0) {

                            setTimeout(function () {
                                var delayedButton = $('#delete-expired-leads');

                                if (delayedButton.length > 0) {
                                    delayedButton.off('click.hapvidaDebug').on('click.hapvidaDebug', function (e) {
                                        e.preventDefault();
                                        deleteExpiredLeads();
                                    });
                                }
                            }, 2000);
                        }

                        // Remove listeners antigos e adiciona novo
                        $(document).off('click', '#delete-expired-leads').on('click', '#delete-expired-leads', function (e) {
                            e.preventDefault();
                            deleteExpiredLeads();
                        });

                        // Debug dos nonces
                        setTimeout(function () {
                            if (window.hapvidaLeadNonces && window.hapvidaLeadNonces.deleteExpiredLeads) {
                            } else {

                            }
                        }, 1000);
                    });


                    // Registra o evento do botão quando o DOM estiver pronto
                    jQuery(document).ready(function ($) {

                        var deleteButton = $('#delete-expired-leads');

                        if (deleteButton.length === 0) {

                            setTimeout(function () {
                                var delayedButton = $('#delete-expired-leads');

                                if (delayedButton.length > 0) {
                                    delayedButton.off('click.hapvidaDebug').on('click.hapvidaDebug', function (e) {
                                        e.preventDefault();
                                        deleteExpiredLeads();
                                    });
                                }
                            }, 2000);
                        }

                        // Remove listeners antigos e adiciona novo
                        $(document).off('click', '#delete-expired-leads').on('click', '#delete-expired-leads', function (e) {
                            e.preventDefault();
                            deleteExpiredLeads();
                        });

                        // Debug dos nonces
                        setTimeout(function () {
                            if (window.hapvidaLeadNonces && window.hapvidaLeadNonces.deleteExpiredLeads) {
                            } else {
                            }
                        }, 1000);
                    });

                </script>

                <!-- ADICIONE ESTE CÓDIGO NO ARQUIVO admin-page.php -->
                <!-- PROCURE POR </style> (final dos estilos CSS) -->
                <!-- E ADICIONE ESTE CÓDIGO LOGO APÓS -->

                <script type="text/javascript">
                    jQuery(document).ready(function ($) {
                        console.log('🚀 Iniciando script de vendedores Hapvida...');

                        // Verifica se o nonce existe
                        var vendedoresNonce = $('#vendedores_nonce').val();
                        if (!vendedoresNonce) {
                            console.error('❌ Nonce de vendedores não encontrado!');
                            return;
                        }

                        console.log('✅ Nonce encontrado:', vendedoresNonce.substring(0, 10) + '...');

                        // =====================================================
                        // GERENCIAMENTO DE VENDEDORES
                        // =====================================================

                        // Função para adicionar novo vendedor
                        $('#add-vendedor').off('click').on('click', function (e) {
                            e.preventDefault();
                            console.log('➕ Botão adicionar vendedor clicado');

                            var $button = $(this);
                            var originalText = $button.html();

                            // Desabilita o botão e mostra loading
                            $button.prop('disabled', true).html('<i class="dashicons dashicons-update spinning"></i> Adicionando...');

                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'add_vendedor',
                                    security: vendedoresNonce,
                                    index: 'vendedor_' + Date.now(),
                                    grupo: 'drv' // Grupo padrão
                                },
                                success: function (response) {
                                    console.log('✅ Vendedor adicionado com sucesso');

                                    // Adiciona a nova linha na tabela
                                    $('.vendedores-table tbody').append(response);

                                    // Reativa eventos para a nova linha
                                    attachVendedorEvents();

                                    // Scroll suave até o novo vendedor
                                    var $newRow = $('.vendedores-table tbody tr:last');
                                    if ($newRow.length) {
                                        $('html, body').animate({
                                            scrollTop: $newRow.offset().top - 100
                                        }, 500);

                                        // Destaca a nova linha
                                        $newRow.css('background-color', '#e8f5e9');
                                        setTimeout(function () {
                                            $newRow.css('transition', 'background-color 1s');
                                            $newRow.css('background-color', '');
                                        }, 1000);
                                    }
                                },
                                error: function (xhr, status, error) {
                                    console.error('❌ Erro ao adicionar vendedor:', error);
                                    console.error('Response:', xhr.responseText);
                                    alert('❌ Erro ao adicionar vendedor. Por favor, tente novamente.');
                                },
                                complete: function () {
                                    // Restaura o botão
                                    $button.prop('disabled', false).html(originalText);
                                }
                            });
                        });

                        // Função para anexar eventos aos elementos dos vendedores
                        function attachVendedorEvents() {
                            console.log('🔄 Anexando eventos aos vendedores...');

                            // Remove vendedor
                            $('.remove-vendedor').off('click').on('click', function (e) {
                                e.preventDefault();

                                var $button = $(this);
                                var $row = $button.closest('tr');
                                var vendedorNome = $row.find('input[name*="[nome]"]').val() || 'este vendedor';

                                if (confirm('⚠️ Tem certeza que deseja remover ' + vendedorNome + '?\n\nEsta ação não pode ser desfeita!')) {
                                    $row.fadeOut(400, function () {
                                        $row.remove();
                                        updateVendorCount();
                                    });
                                }
                            });

                            // Toggle status do vendedor
                            $('.toggle-status-btn').off('click').on('click', function (e) {
                                e.preventDefault();

                                var $button = $(this);
                                var $row = $button.closest('tr');
                                var currentStatus = $button.data('current-status');
                                var newStatus = (currentStatus === 'ativo') ? 'inativo' : 'ativo';
                                var index = $button.data('index');

                                console.log('🔄 Alterando status:', currentStatus, '->', newStatus);

                                // Atualiza visualmente
                                if (newStatus === 'inativo') {
                                    $row.addClass('vendedor-inativo');
                                    $button.html('<i class="dashicons dashicons-visibility"></i> Ativar');
                                    $button.attr('title', 'Ativar vendedor');
                                } else {
                                    $row.removeClass('vendedor-inativo');
                                    $button.html('<i class="dashicons dashicons-hidden"></i> Desativar');
                                    $button.attr('title', 'Desativar vendedor');
                                }

                                // Atualiza o select de status
                                $row.find('.status-select').val(newStatus);

                                // Atualiza o data-attribute
                                $button.data('current-status', newStatus);

                                // Envia via AJAX (opcional)
                                $.ajax({
                                    url: ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'toggle_vendedor_status',
                                        security: vendedoresNonce,
                                        index: index,
                                        new_status: newStatus
                                    },
                                    success: function (response) {
                                        if (response.success) {
                                            console.log('✅ Status atualizado com sucesso');
                                        }
                                    },
                                    error: function () {
                                        console.error('❌ Erro ao atualizar status via AJAX');
                                    }
                                });

                                updateVendorCount();
                            });

                            // Mudança de grupo
                            $('.grupo-select').off('change').on('change', function () {
                                var $select = $(this);
                                var $row = $select.closest('tr');
                                var grupo = $select.val();
                                var rowIndex = $row.data('index') || $row.find('input[name*="[nome]"]').attr('name').match(/vendedores\[([^\]]+)\]/)[1];

                                console.log('🔄 Mudando grupo para:', grupo);

                                if (grupo === 'drv') {
                                    // Mostra categoria select para DRV
                                    var categoriaHtml = '<select name="vendedores[' + rowIndex + '][categoria]" class="categoria-select" required>' +
                                        '<option value="fixo">Fixo</option>' +
                                        '<option value="rotativo">Rotativo</option>' +
                                        '</select>';
                                    $row.find('td:eq(1)').html(categoriaHtml);
                                } else {
                                    // Esconde categoria para Seu Souza
                                    var hiddenHtml = '<input type="hidden" name="vendedores[' + rowIndex + '][categoria]" value="fixo">' +
                                        '<span>N/A</span>';
                                    $row.find('td:eq(1)').html(hiddenHtml);
                                }

                                updateVendorCount();
                            });

                            // Máscara para telefone
                            $('.vendedores-table input[name*="[telefone]"]').off('input').on('input', function () {
                                var $input = $(this);
                                var value = $input.val().replace(/\D/g, '');

                                if (value.length <= 11) {
                                    if (value.length <= 10) {
                                        // Formato: (XX) XXXX-XXXX
                                        value = value.replace(/(\d{2})(\d{4})(\d{4})/, '($1) $2-$3');
                                    } else {
                                        // Formato: (XX) XXXXX-XXXX
                                        value = value.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
                                    }
                                }

                                $input.val(value);
                            });
                        }

                        // Função para atualizar contagem de vendedores
                        function updateVendorCount() {
                            var totalVendedores = $('.vendedor-row').length;
                            var ativosTotal = $('.vendedor-row:not(.vendedor-inativo)').length;
                            var inativosTotal = $('.vendedor-row.vendedor-inativo').length;

                            var drvAtivos = 0;
                            var drvInativos = 0;
                            var seuSouzaAtivos = 0;
                            var seuSouzaInativos = 0;

                            $('.vendedor-row').each(function () {
                                var $row = $(this);
                                var grupo = $row.find('.grupo-select').val();
                                var isInativo = $row.hasClass('vendedor-inativo');

                                if (grupo === 'drv') {
                                    if (isInativo) {
                                        drvInativos++;
                                    } else {
                                        drvAtivos++;
                                    }
                                } else {
                                    if (isInativo) {
                                        seuSouzaInativos++;
                                    } else {
                                        seuSouzaAtivos++;
                                    }
                                }
                            });

                            console.log('📊 Estatísticas de vendedores:', {
                                total: totalVendedores,
                                ativos: ativosTotal,
                                inativos: inativosTotal,
                                drv: { ativos: drvAtivos, inativos: drvInativos },
                                seuSouza: { ativos: seuSouzaAtivos, inativos: seuSouzaInativos }
                            });
                        }

                        // Validação do formulário antes de salvar
                        $('#vendedores-form').on('submit', function (e) {
                            console.log('📝 Validando formulário de vendedores...');

                            var hasError = false;
                            var vendedorCount = 0;
                            var errorMessages = [];

                            // Remove classes de erro anteriores
                            $('.vendedores-table input').removeClass('error');

                            $('.vendedor-row').each(function () {
                                var $row = $(this);
                                var nome = $row.find('input[name*="[nome]"]').val();
                                var telefone = $row.find('input[name*="[telefone]"]').val();

                                // Se a linha tem algum dado, valida todos os campos
                                if (nome.trim() !== '' || telefone.trim() !== '') {
                                    vendedorCount++;

                                    if (nome.trim() === '') {
                                        $row.find('input[name*="[nome]"]').addClass('error');
                                        hasError = true;
                                        errorMessages.push('Nome é obrigatório');
                                    }

                                    if (telefone.trim() === '') {
                                        $row.find('input[name*="[telefone]"]').addClass('error');
                                        hasError = true;
                                        errorMessages.push('Telefone é obrigatório');
                                    }
                                }
                            });

                            if (hasError) {
                                e.preventDefault();
                                alert('❌ Por favor, corrija os erros:\n\n' + [...new Set(errorMessages)].join('\n'));

                                // Scroll para o primeiro campo com erro
                                var $firstError = $('.vendedores-table input.error:first');
                                if ($firstError.length) {
                                    $('html, body').animate({
                                        scrollTop: $firstError.offset().top - 100
                                    }, 500);
                                    $firstError.focus();
                                }

                                return false;
                            }

                            if (vendedorCount === 0) {
                                e.preventDefault();
                                alert('⚠️ Adicione pelo menos um vendedor antes de salvar.');
                                $('#add-vendedor').focus();
                                return false;
                            }

                            // Mostra loading no botão de submit
                            var $submitButton = $(this).find('button[type="submit"], input[type="submit"]');
                            $submitButton.prop('disabled', true);

                            if ($submitButton.is('button')) {
                                $submitButton.html('<i class="dashicons dashicons-update spinning"></i> Salvando...');
                            } else {
                                $submitButton.val('Salvando...');
                            }

                            console.log('✅ Formulário válido, enviando...');
                        });

                        // Adiciona estilo para campos com erro
                        if (!$('#vendedor-error-style').length) {
                            $('<style id="vendedor-error-style">')
                                .html(`
                .vendedores-table input.error { 
                    border-color: #dc3545 !important; 
                    background-color: #fff5f5 !important; 
                }
                .vendedores-table input.error:focus { 
                    box-shadow: 0 0 0 2px rgba(220, 53, 69, 0.25) !important; 
                }
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                .spinning {
                    animation: spin 1s linear infinite;
                    display: inline-block;
                }
            `)
                                .appendTo('head');
                        }

                        // Inicializa eventos para elementos existentes
                        attachVendedorEvents();
                        updateVendorCount();

                        // Monitora mudanças para feedback em tempo real
                        $('.vendedores-table').on('input change', 'input, select', function () {
                            var $field = $(this);

                            // Remove erro quando o usuário começa a digitar
                            if ($field.hasClass('error') && $field.val().trim() !== '') {
                                $field.removeClass('error');
                            }
                        });

                        console.log('✅ Script de vendedores carregado e pronto!');
                    });
                </script>

                <!-- FIM DO CÓDIGO JAVASCRIPT DE VENDEDORES -->

                <?php
    }



    public function admin_username_callback()
    {
        $options = get_option($this->option_name);
        $admin_username = isset($options['admin_username']) ? esc_attr($options['admin_username']) : '';

        echo "<input type='text' class='regular-text' name='{$this->option_name}[admin_username]' value='{$admin_username}' data-label='Usuário Admin (Relatórios)' />";
        echo "<p class='description'>Usuário para acessar a página de relatórios de leads. <strong>Importante:</strong> Configure este usuário e senha para proteger o acesso aos relatórios.</p>";
    }

    public function admin_password_callback()
    {
        $options = get_option($this->option_name);
        $admin_password = isset($options['admin_password']) ? esc_attr($options['admin_password']) : '';

        echo "<input type='password' class='regular-text' name='{$this->option_name}[admin_password]' value='{$admin_password}' data-label='Senha Admin (Relatórios)' autocomplete='new-password' />";
        echo "<p class='description'>Senha para acessar a página de relatórios de leads. <strong>Use uma senha forte!</strong> ";
        echo "Para acessar os relatórios, adicione o shortcode <code>[hapvida_reports]</code> em qualquer página.</p>";
    }


    public function render_frontend_dashboard_scripts()
    {
        ?>
                <script type="text/javascript">
                    // Remove qualquer script base64 injetado
                    document.querySelectorAll('script[src*="base64"]').forEach(function (el) {
                        el.remove();
                    });

                    // SCRIPT PRINCIPAL HAPVIDA
                    (function () {
                        // Espera o DOM carregar completamente
                        if (document.readyState === 'loading') {
                            document.addEventListener('DOMContentLoaded', initHapvida);
                        } else {
                            initHapvida();
                        }

                        function initHapvida() {
                            console.log('🚀 Hapvida System - Iniciando VERSÃO FIXA');

                            // Verifica jQuery
                            if (typeof jQuery === 'undefined') {
                                console.error('❌ jQuery não está carregado!');
                                setTimeout(initHapvida, 500); // Tenta novamente em 500ms
                                return;
                            }

                            jQuery(document).ready(function ($) {
                                console.log('✅ [HAPVIDA] Sistema iniciado com sucesso!');
                                console.log('💡 [HAPVIDA] Comandos disponíveis no console:');
                                console.log('  hapvidaDebug.updateNow() - Força atualização');
                                console.log('  hapvidaDebug.checkTable() - Verifica tabelas');
                                console.log('  hapvidaDebug.testRest() - Testa REST API');
                                console.log('  hapvidaDebug.status() - Status do sistema');
                                console.log('  hapvidaDebug.testModal() - Testa o modal');
                                console.log('  hapvidaDebug.createModalManually() - Cria modal manualmente');

                                // Configurações
                                var ajaxurl = '<?php echo admin_url("admin-ajax.php"); ?>';
                                var restUrl = '<?php echo get_rest_url(null, "hapvida/v1/"); ?>';
                                var updateInterval = null;
                                var isUpdating = false;

                                console.log('📍 AJAX URL:', ajaxurl);
                                console.log('📍 REST URL:', restUrl);

                                // Função para criar modal
                                function createModal() {
                                    if ($('#hapvida-lead-modal').length > 0) {
                                        console.log('ℹ️ Modal já existe');
                                        return;
                                    }

                                    var modalHtml = `
                    <div id="hapvida-lead-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:99999;">
                        <div style="background:white;padding:30px;margin:50px auto;width:600px;max-width:90%;border-radius:10px;max-height:80vh;overflow-y:auto;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                                <h2 style="margin:0;color:#0054B8;">📋 Detalhes do Lead</h2>
                                <button onclick="jQuery('#hapvida-lead-modal').fadeOut()" style="background:none;border:none;font-size:24px;cursor:pointer;">&times;</button>
                            </div>
                            <div id="modal-content" style="min-height:200px;">
                                <div style="text-align:center;padding:40px;">
                                    <div style="font-size:48px;">⏳</div>
                                    <p>Carregando...</p>
                                </div>
                            </div>
                            <div style="margin-top:20px;padding-top:20px;border-top:1px solid #ddd;text-align:right;">
                                <button onclick="jQuery('#hapvida-lead-modal').fadeOut()" style="padding:10px 20px;background:#6c757d;color:white;border:none;border-radius:5px;cursor:pointer;">Fechar</button>
                            </div>
                        </div>
                    </div>`;

                                    $('body').append(modalHtml);
                                    console.log('✅ Modal criado com sucesso!');
                                }

                                // Função para abrir modal
                                // Função para abrir modal
                                function openModal(leadId) {
                                    console.log('🔓 Abrindo modal para lead:', leadId);

                                    if (!leadId) {
                                        console.error('❌ ID do lead não fornecido');
                                        return;
                                    }

                                    createModal();
                                    $('#hapvida-lead-modal').fadeIn();

                                    // Mostra loading
                                    $('#modal-content').html(`
        <div style="text-align:center;padding:40px;">
            <div style="font-size:48px;">⏳</div>
            <p>Carregando detalhes...</p>
        </div>
    `);

                                    // Busca dados via AJAX
                                    $.ajax({
                                        url: ajaxurl,
                                        type: 'POST',
                                        dataType: 'json',
                                        data: {
                                            action: 'get_webhook_lead_details_public',
                                            webhook_id: leadId
                                        },
                                        success: function (response) {
                                            console.log('✅ Dados do modal recebidos:', response);

                                            if (response && response.success && response.data) {
                                                var lead = response.data;

                                                // Armazena os dados do lead para a função de copiar
                                                window.currentLeadData = lead;

                                                var html = `
                <div style="display:grid;gap:15px;">
                    <!-- BOTÃO DE COPIAR NO TOPO -->
                    <div style="text-align:center;padding-bottom:20px;border-bottom:2px solid #0054B8;">
                        <button id="copy-lead-data" style="padding:14px 40px;background:linear-gradient(135deg, #28a745 0%, #20c997 100%);color:white;border:none;border-radius:8px;cursor:pointer;font-size:18px;font-weight:bold;box-shadow:0 4px 15px rgba(40,167,69,0.3);transition:all 0.3s;">
                            📋 Copiar Dados do Lead
                        </button>
                    </div>
                    
                    <div style="padding:15px;background:#f8f9fa;border-radius:5px;">
                        <h4 style="margin:0 0 10px 0;color:#0054B8;">Informações do Cliente</h4>
                        <p><strong>ID:</strong> ${leadId || 'N/A'}</p>
                        <p><strong>Nome:</strong> ${lead.nome || 'N/A'}</p>
                        <p><strong>Telefone:</strong> ${lead.telefone || 'N/A'}</p>
                        <p><strong>Cidade:</strong> ${lead.cidade || 'N/A'}</p>
                        <p><strong>Plano:</strong> ${lead.plano || 'N/A'}</p>
                        <p><strong>Qtd Pessoas:</strong> ${lead.qtd_pessoas || '1'}</p>
                        ${lead.idades ? `<p><strong>Idades:</strong> ${lead.idades}</p>` : ''}
                    </div>
                    
                    <div style="padding:15px;background:#f8f9fa;border-radius:5px;">
                        <h4 style="margin:0 0 10px 0;color:#0054B8;">Informações do Atendimento</h4>
                        <p><strong>Vendedor:</strong> ${lead.vendedor || 'N/A'}</p>
                        <p><strong>Grupo:</strong> ${lead.grupo || 'N/A'}</p>
                        <p><strong>Status:</strong> ${lead.status || 'N/A'}</p>
                        <p><strong>Data:</strong> ${lead.created_at || 'N/A'}</p>
                    </div>
                </div>`;

                                                $('#modal-content').html(html);

                                                // Adiciona evento de clique ao botão de copiar com hover effect
                                                $('#copy-lead-data')
                                                    .off('click').on('click', function () {
                                                        copyLeadData(lead, leadId);
                                                    })
                                                    .hover(
                                                        function () { $(this).css('transform', 'translateY(-2px)').css('box-shadow', '0 6px 20px rgba(40,167,69,0.4)'); },
                                                        function () { $(this).css('transform', 'translateY(0)').css('box-shadow', '0 4px 15px rgba(40,167,69,0.3)'); }
                                                    );

                                            } else {
                                                $('#modal-content').html(`
                    <div style="text-align:center;padding:40px;color:#dc3545;">
                        <div style="font-size:48px;">❌</div>
                        <p>Erro ao carregar detalhes do lead</p>
                        <p style="font-size:14px;">${response.data || 'Tente novamente'}</p>
                    </div>
                `);
                                            }
                                        },
                                        error: function (xhr, status, error) {
                                            console.error('❌ Erro ao buscar detalhes:', { xhr: xhr, status: status, error: error });
                                            $('#modal-content').html(`
                <div style="text-align:center;padding:40px;color:#dc3545;">
                    <div style="font-size:48px;">❌</div>
                    <p>Erro de conexão</p>
                    <p style="font-size:14px;">Verifique sua conexão e tente novamente</p>
                </div>
            `);
                                        }
                                    });
                                }

                                // Função para copiar dados do lead
                                function copyLeadData(lead, leadId) {
                                    console.log('📋 Copiando dados do lead...');

                                    // Obtém a data atual
                                    var hoje = new Date();
                                    var dia = String(hoje.getDate()).padStart(2, '0');
                                    var mes = String(hoje.getMonth() + 1).padStart(2, '0');
                                    var ano = hoje.getFullYear();
                                    var dataFormatada = dia + '-' + mes + '-' + ano;

                                    // Usa o lead_id real do sistema (não o webhook_id)
                                    var idReal = lead.lead_id || lead.id_lead || leadId;

                                    // Se o ID ainda começar com "webhook_", remove essa parte
                                    if (idReal && idReal.toString().includes('webhook_')) {
                                        // Tenta extrair apenas o número do ID ou usar um ID padrão
                                        idReal = 'N/A';
                                    }

                                    // Formata o texto para copiar (formato WhatsApp)
                                    var textoCopiar = 'Novo lead - Data: ' + dataFormatada + '\n\n';
                                    textoCopiar += '*- Id: ' + idReal + '*\n\n';
                                    textoCopiar += '*- Nome:* ' + (lead.nome || 'N/A') + '\n';
                                    textoCopiar += '*- Telefone:* ' + (lead.telefone || 'N/A') + '\n';
                                    textoCopiar += '*- Cidade:* ' + (lead.cidade || 'N/A') + '\n';
                                    textoCopiar += '*- Plano:* ' + (lead.plano || 'N/A') + '\n';
                                    textoCopiar += '*- Qde de Pessoas:* ' + (lead.qtd_pessoas || '1') + '\n';

                                    if (lead.idades && lead.idades !== 'N/A' && lead.idades !== '') {
                                        textoCopiar += '*- Idades:* ' + lead.idades + '\n';
                                    }

                                    // Cria um elemento textarea temporário
                                    var $tempTextarea = $('<textarea>');
                                    $('body').append($tempTextarea);
                                    $tempTextarea.val(textoCopiar).select();

                                    try {
                                        // Tenta copiar o texto
                                        var successful = document.execCommand('copy');

                                        if (successful) {
                                            console.log('✅ Dados copiados com sucesso!');

                                            // Feedback visual - muda o botão temporariamente
                                            var $btn = $('#copy-lead-data');
                                            var textoOriginal = $btn.html();
                                            var bgOriginal = $btn.css('background');

                                            $btn.html('✅ Copiado com Sucesso!')
                                                .css('background', '#218838');

                                            setTimeout(function () {
                                                $btn.html(textoOriginal)
                                                    .css('background', bgOriginal);
                                            }, 2000);

                                            // Mostra mensagem de sucesso
                                            showCopyNotification('Dados copiados para a área de transferência!');

                                        } else {
                                            console.error('❌ Falha ao copiar');
                                            alert('Não foi possível copiar. Por favor, selecione e copie manualmente.');
                                        }
                                    } catch (err) {
                                        console.error('❌ Erro ao copiar:', err);

                                        // Fallback: mostra os dados em um modal para copiar manualmente
                                        showManualCopyModal(textoCopiar);
                                    }

                                    // Remove o textarea temporário
                                    $tempTextarea.remove();
                                }

                                // Função para mostrar notificação de cópia
                                function showCopyNotification(message) {
                                    // Remove notificações anteriores
                                    $('.copy-notification').remove();

                                    var notification = $(`
        <div class="copy-notification" style="position:fixed;top:20px;right:20px;background:#28a745;color:white;padding:15px 20px;border-radius:5px;box-shadow:0 2px 10px rgba(0,0,0,0.2);z-index:100000;display:none;">
            <i class="fas fa-check-circle"></i> ${message}
        </div>
    `);

                                    $('body').append(notification);
                                    notification.fadeIn(300);

                                    setTimeout(function () {
                                        notification.fadeOut(300, function () {
                                            $(this).remove();
                                        });
                                    }, 3000);
                                }

                                // Função para mostrar modal de cópia manual (fallback)
                                function showManualCopyModal(text) {
                                    var modalHtml = `
        <div id="manual-copy-modal" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:100001;display:flex;align-items:center;justify-content:center;">
            <div style="background:white;padding:20px;border-radius:10px;max-width:500px;width:90%;">
                <h3 style="margin:0 0 15px 0;">Copie o texto abaixo:</h3>
                <textarea style="width:100%;height:200px;padding:10px;border:1px solid #ddd;border-radius:5px;font-family:monospace;" readonly>${text}</textarea>
                <div style="margin-top:15px;text-align:right;">
                    <button onclick="jQuery('#manual-copy-modal').remove()" style="padding:10px 20px;background:#6c757d;color:white;border:none;border-radius:5px;cursor:pointer;">Fechar</button>
                </div>
            </div>
        </div>
    `;

                                    $('body').append(modalHtml);
                                    $('#manual-copy-modal textarea').select();
                                }

                                // Função para atualizar leads - CORRIGIDA
                                function updateLeads() {
                                    if (isUpdating) {
                                        console.log('⏳ Atualização já em andamento...');
                                        return;
                                    }

                                    isUpdating = true;
                                    console.log('🔄 Atualizando leads...');

                                    $.ajax({
                                        url: ajaxurl,
                                        type: 'POST',
                                        dataType: 'json',
                                        data: {
                                            action: 'get_recent_leads'
                                        },
                                        success: function (response) {
                                            console.log('📥 Resposta recebida:', response);

                                            if (response && response.success) {
                                                var tbody = $('#leads-table-body');

                                                if (tbody.length === 0) {
                                                    console.warn('⚠️ Tabela não encontrada!');
                                                    isUpdating = false;
                                                    return;
                                                }

                                                tbody.empty();

                                                // CORREÇÃO: Verifica se data existe e é um array
                                                var leads = response.data;

                                                if (!leads || !Array.isArray(leads)) {
                                                    console.warn('⚠️ Dados não são um array:', leads);
                                                    tbody.html('<tr><td colspan="6" style="text-align:center;">Nenhum lead encontrado</td></tr>');
                                                    isUpdating = false;
                                                    return;
                                                }

                                                if (leads.length === 0) {
                                                    tbody.html('<tr><td colspan="6" style="text-align:center;">Nenhum lead registrado</td></tr>');
                                                } else {
                                                    leads.forEach(function (lead, index) {
                                                        console.log('Lead ' + (index + 1) + ':', lead);

                                                        var row = `
                                        <tr class="webhook-row" data-webhook-id="${lead.id}" style="cursor:pointer;">
                                            <td>${lead.created_at}</td>
                                            <td style="color:#0054B8;font-weight:500;">${lead.client_name}</td>
                                            <td><span style="padding:2px 8px;background:#e3f2fd;border-radius:3px;">${lead.grupo}</span></td>
                                            <td>${lead.phone}</td>
                                            <td>${lead.city}</td>
                                            <td>${lead.vendor}</td>
                                        </tr>`;

                                                        tbody.append(row);
                                                    });

                                                    console.log('✅ Tabela atualizada com ' + leads.length + ' leads');
                                                }
                                            } else {
                                                console.error('❌ Resposta inválida:', response);
                                            }

                                            isUpdating = false;
                                        },
                                        error: function (xhr, status, error) {
                                            console.error('❌ Erro ao buscar leads:', {
                                                status: status,
                                                error: error,
                                                response: xhr.responseText
                                            });

                                            $('#leads-table-body').html('<tr><td colspan="6" style="text-align:center;color:#dc3545;">Erro ao carregar leads</td></tr>');
                                            isUpdating = false;
                                        },
                                        complete: function () {
                                            isUpdating = false;
                                        }
                                    });
                                }

                                // Event handlers
                                $(document).on('click', '.webhook-row', function (e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    var leadId = $(this).data('webhook-id');
                                    console.log('🖱️ Click no lead:', leadId);
                                    if (leadId) {
                                        openModal(leadId);
                                    }
                                });

                                $(document).on('click', '#force-update-leads', function (e) {
                                    e.preventDefault();
                                    console.log('🔄 Atualização manual solicitada');
                                    var btn = $(this);
                                    var originalText = btn.html();
                                    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Atualizando...');
                                    updateLeads();
                                    setTimeout(function () {
                                        btn.prop('disabled', false).html(originalText);
                                    }, 1000);
                                });

                                // Objeto de debug global
                                window.hapvidaDebug = {
                                    updateNow: function () {
                                        console.log('🔄 Forçando atualização...');
                                        updateLeads();
                                    },
                                    checkTable: function () {
                                        var table = $('#leads-table-body');
                                        console.log('📊 Tabela:', {
                                            existe: table.length > 0,
                                            linhas: table.find('tr').length,
                                            elemento: table[0]
                                        });
                                        return table.length > 0;
                                    },
                                    testModal: function (leadId) {
                                        if (!leadId) {
                                            var firstRow = $('.webhook-row').first();
                                            leadId = firstRow.data('webhook-id');
                                            if (!leadId) {
                                                console.error('❌ Nenhum lead encontrado para testar');
                                                return;
                                            }
                                        }
                                        console.log('🧪 Testando modal com lead:', leadId);
                                        openModal(leadId);
                                    },
                                    createModalManually: function () {
                                        console.log('🔨 Criando modal manualmente...');
                                        createModal();
                                        $('#hapvida-lead-modal').fadeIn();
                                    },
                                    status: function () {
                                        var status = {
                                            ajaxurl: ajaxurl,
                                            restUrl: restUrl,
                                            jQuery: typeof jQuery !== 'undefined',
                                            tabela: $('#leads-table-body').length > 0,
                                            linhas: $('#leads-table-body tr').length,
                                            modal: $('#hapvida-lead-modal').length > 0,
                                            isUpdating: isUpdating
                                        };
                                        console.log('📊 Status do sistema:', status);
                                        return status;
                                    },
                                    testRest: function () {
                                        console.log('🧪 Testando REST API...');
                                        $.get(restUrl + 'recent-leads')
                                            .done(function (data) {
                                                console.log('✅ REST OK:', data);
                                            })
                                            .fail(function (xhr) {
                                                console.error('❌ REST Erro:', xhr);
                                            });
                                    },
                                    debug: true
                                };

                                // Inicialização
                                console.log('🎯 Inicializando sistema...');

                                // Cria modal no início
                                createModal();

                                // Primeira atualização
                                setTimeout(function () {
                                    console.log('📊 Executando primeira atualização...');
                                    updateLeads();
                                }, 500);

                                // Auto-atualização a cada 10 segundos
                                updateInterval = setInterval(function () {
                                    if (!isUpdating) {
                                        console.log('⏰ Auto-atualização...');
                                        updateLeads();
                                    }
                                }, 10000);

                                // Para auto-atualização quando sair da página
                                $(window).on('beforeunload', function () {
                                    if (updateInterval) {
                                        clearInterval(updateInterval);
                                    }
                                });

                                console.log('🎉 SISTEMA HAPVIDA TOTALMENTE INICIALIZADO!');
                            });
                        }
                    })();
                </script>
                <?php
    }

    // ===========================================================================
// NOVA FUNÇÃO: ajax_adicionar_rota_consultor
// Handler AJAX para adicionar nova rota de consultor
// ===========================================================================

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

    // ===========================================================================
// NOVA FUNÇÃO: ajax_remover_rota_consultor
// Handler AJAX para remover rota de consultor
// ===========================================================================

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

    // ===========================================================================
// NOVA FUNÇÃO: render_url_consultores_content
// Renderiza o conteúdo da seção de rotas de consultores
// ===========================================================================

    private function render_url_consultores_content()
    {
        global $formulario_hapvida;

        // Busca as configurações atuais
        $url_consultores = get_option('formulario_hapvida_url_consultores', array());

        // Busca todos os vendedores para o dropdown
        $vendedores = get_option('formulario_hapvida_vendedores', array('drv' => array(), 'seu_souza' => array()));

        ?>
                <div style="max-width: 100%;">
                    <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                        <h3 style="margin-top: 0;">➕ Adicionar Nova Rota</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="nova_url">URL da Página</label>
                                </th>
                                <td>
                                    <input type="text" id="nova_url" class="regular-text"
                                        placeholder="https://tabelaplanos.com.br/sobre_nos/victor_castro/">
                                    <p class="description">Digite a URL completa da página do consultor</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="novo_vendedor_numero">Número do Consultor</label>
                                </th>
                                <td>
                                    <select id="novo_vendedor_numero" class="regular-text">
                                        <option value="">Selecione um consultor</option>
                                        <?php
                                        $total_vendedores = 0;

                                        // Lista vendedores do grupo DRV
                                        if (isset($vendedores['drv']) && is_array($vendedores['drv'])) {
                                            if (count($vendedores['drv']) > 0) {
                                                echo '<optgroup label="Grupo DRV">';
                                                foreach ($vendedores['drv'] as $vendedor) {
                                                    // Tenta pegar o número de diferentes campos possíveis
                                                    $numero_vendedor = '';
                                                    if (isset($vendedor['numero']) && !empty($vendedor['numero'])) {
                                                        $numero_vendedor = $vendedor['numero'];
                                                    } elseif (isset($vendedor['telefone']) && !empty($vendedor['telefone'])) {
                                                        $numero_vendedor = $vendedor['telefone'];
                                                    }

                                                    $nome_vendedor = isset($vendedor['nome']) ? $vendedor['nome'] : 'Sem nome';

                                                    if (!empty($numero_vendedor) && !empty($nome_vendedor)) {
                                                        $numero_limpo = preg_replace('/[^0-9]/', '', $numero_vendedor);
                                                        echo '<option value="' . esc_attr($numero_limpo) . '">' .
                                                            esc_html($nome_vendedor) . ' - ' . esc_html($numero_vendedor) .
                                                            '</option>';
                                                        $total_vendedores++;
                                                    }
                                                }
                                                echo '</optgroup>';
                                            }
                                        }

                                        // Lista vendedores do grupo Seu Souza
                                        if (isset($vendedores['seu_souza']) && is_array($vendedores['seu_souza'])) {
                                            if (count($vendedores['seu_souza']) > 0) {
                                                echo '<optgroup label="Grupo Seu Souza">';
                                                foreach ($vendedores['seu_souza'] as $vendedor) {
                                                    // Tenta pegar o número de diferentes campos possíveis
                                                    $numero_vendedor = '';
                                                    if (isset($vendedor['numero']) && !empty($vendedor['numero'])) {
                                                        $numero_vendedor = $vendedor['numero'];
                                                    } elseif (isset($vendedor['telefone']) && !empty($vendedor['telefone'])) {
                                                        $numero_vendedor = $vendedor['telefone'];
                                                    }

                                                    $nome_vendedor = isset($vendedor['nome']) ? $vendedor['nome'] : 'Sem nome';

                                                    if (!empty($numero_vendedor) && !empty($nome_vendedor)) {
                                                        $numero_limpo = preg_replace('/[^0-9]/', '', $numero_vendedor);
                                                        echo '<option value="' . esc_attr($numero_limpo) . '">' .
                                                            esc_html($nome_vendedor) . ' - ' . esc_html($numero_vendedor) .
                                                            '</option>';
                                                        $total_vendedores++;
                                                    }
                                                }
                                                echo '</optgroup>';
                                            }
                                        }

                                        // Se não encontrou nenhum vendedor, mostra mensagem
                                        if ($total_vendedores === 0) {
                                            echo '<option value="" disabled>Nenhum consultor cadastrado</option>';
                                        }
                                        ?>
                                    </select>
                                    <p class="description">Selecione o consultor que receberá os leads desta URL</p>
                                    <?php if ($total_vendedores === 0): ?>
                                            <p style="color: #d63638; font-weight: bold;">⚠️ Nenhum consultor encontrado. Cadastre consultores
                                                na seção "Gerenciar Vendedores" acima.</p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <button type="button" id="adicionar_rota" class="button button-primary">Adicionar Rota</button>
                        </p>
                    </div>

                    <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
                        <h3 style="margin-top: 0;">📋 Rotas Configuradas</h3>
                        <table class="wp-list-table widefat fixed striped" id="tabela_rotas">
                            <thead>
                                <tr>
                                    <th style="width: 50%;">URL da Página</th>
                                    <th style="width: 30%;">Consultor</th>
                                    <th style="width: 20%;">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if (!empty($url_consultores) && is_array($url_consultores)) {
                                    foreach ($url_consultores as $index => $config) {
                                        if (!isset($config['url']) || !isset($config['vendedor_numero'])) {
                                            continue;
                                        }

                                        // Busca o nome do vendedor pelo número
                                        $vendedor_nome = 'Desconhecido';
                                        $vendedor_numero_formatado = $config['vendedor_numero'];

                                        foreach ($vendedores as $grupo => $vendedores_grupo) {
                                            if (!is_array($vendedores_grupo))
                                                continue;

                                            foreach ($vendedores_grupo as $vendedor) {
                                                if (!isset($vendedor['numero']))
                                                    continue;

                                                $numero_limpo = preg_replace('/[^0-9]/', '', $vendedor['numero']);
                                                if ($numero_limpo === $config['vendedor_numero']) {
                                                    $vendedor_nome = $vendedor['nome'];
                                                    $vendedor_numero_formatado = $vendedor['numero'];
                                                    break 2;
                                                }
                                            }
                                        }

                                        echo '<tr data-index="' . esc_attr($index) . '">';
                                        echo '<td>' . esc_html($config['url']) . '</td>';
                                        echo '<td>' . esc_html($vendedor_nome) . ' (' . esc_html($vendedor_numero_formatado) . ')</td>';
                                        echo '<td><button type="button" class="button button-small remover_rota" data-index="' . esc_attr($index) . '">Remover</button></td>';
                                        echo '</tr>';
                                    }
                                } else {
                                    echo '<tr><td colspan="3" style="text-align: center;">Nenhuma rota configurada</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <script type="text/javascript">
                    jQuery(document).ready(function ($) {
                        // Adicionar nova rota
                        $('#adicionar_rota').on('click', function () {
                            var url = $('#nova_url').val().trim();
                            var vendedor_numero = $('#novo_vendedor_numero').val();

                            if (!url) {
                                alert('Por favor, digite uma URL');
                                return;
                            }

                            if (!vendedor_numero) {
                                alert('Por favor, selecione um consultor');
                                return;
                            }

                            // Envia via AJAX
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'adicionar_rota_consultor',
                                    url: url,
                                    vendedor_numero: vendedor_numero,
                                    nonce: '<?php echo wp_create_nonce('url_consultores_nonce'); ?>'
                                },
                                success: function (response) {
                                    if (response.success) {
                                        alert('Rota adicionada com sucesso!');
                                        location.reload();
                                    } else {
                                        alert('Erro ao adicionar rota: ' + response.data);
                                    }
                                },
                                error: function () {
                                    alert('Erro ao comunicar com o servidor');
                                }
                            });
                        });

                        // Remover rota
                        $('.remover_rota').on('click', function () {
                            if (!confirm('Tem certeza que deseja remover esta rota?')) {
                                return;
                            }

                            var index = $(this).data('index');

                            // Envia via AJAX
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'remover_rota_consultor',
                                    index: index,
                                    nonce: '<?php echo wp_create_nonce('url_consultores_nonce'); ?>'
                                },
                                success: function (response) {
                                    if (response.success) {
                                        alert('Rota removida com sucesso!');
                                        location.reload();
                                    } else {
                                        alert('Erro ao remover rota: ' + response.data);
                                    }
                                },
                                error: function () {
                                    alert('Erro ao comunicar com o servidor');
                                }
                            });
                        });
                    });

                    // *** GERAÇÃO DE INVOICE ***
                    jQuery(document).ready(function ($) {
                        $('#generate_invoice_btn').on('click', function () {
                            const startDate = $('#invoice_start_date').val();
                            const endDate = $('#invoice_end_date').val();
                            const quantity = $('#invoice_quantity').val();
                            const advancePayment = $('#invoice_advance_payment').val();
                            const advanceDate = $('#invoice_advance_date').val();
                            const group = $('#invoice_group').val();
                            const statusDiv = $('#invoice_status');

                            if (!startDate || !endDate) {
                                statusDiv.html('<div style="background: #ffebee; border-left: 4px solid #f44336; padding: 10px; border-radius: 4px; color: #c62828;">Por favor, selecione as datas inicial e final.</div>');
                                return;
                            }

                            if (!quantity || quantity < 1) {
                                statusDiv.html('<div style="background: #ffebee; border-left: 4px solid #f44336; padding: 10px; border-radius: 4px; color: #c62828;">Por favor, informe a quantidade de leads (mínimo 1).</div>');
                                return;
                            }

                            statusDiv.html('<div style="background: #e3f2fd; border-left: 4px solid #2196f3; padding: 10px; border-radius: 4px; color: #1976d2;"><i class="dashicons dashicons-update-alt" style="animation: rotation 1s infinite linear;"></i> Gerando invoice...</div>');

                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'hapvida_export_invoice',
                                    start_date: startDate,
                                    end_date: endDate,
                                    quantity: quantity,
                                    advance_payment: advancePayment,
                                    advance_date: advanceDate,
                                    group: group
                                },
                                success: function (response) {
                                    if (response.success) {
                                        statusDiv.html('<div style="background: #e8f5e9; border-left: 4px solid #4caf50; padding: 10px; border-radius: 4px; color: #2e7d32;"><i class="dashicons dashicons-yes-alt"></i> Invoice gerado com sucesso!</div>');

                                        // Abre invoice em nova janela
                                        const invoiceWindow = window.open('', '_blank');
                                        invoiceWindow.document.write(response.data.html);
                                        invoiceWindow.document.close();

                                        // Aguarda e abre diálogo de impressão
                                        setTimeout(() => {
                                            invoiceWindow.print();
                                        }, 500);
                                    } else {
                                        statusDiv.html('<div style="background: #ffebee; border-left: 4px solid #f44336; padding: 10px; border-radius: 4px; color: #c62828;"><i class="dashicons dashicons-warning"></i> Erro: ' + (response.data || 'Erro ao gerar invoice') + '</div>');
                                    }
                                },
                                error: function (xhr, status, error) {
                                    statusDiv.html('<div style="background: #ffebee; border-left: 4px solid #f44336; padding: 10px; border-radius: 4px; color: #c62828;"><i class="dashicons dashicons-warning"></i> Erro de conexão ao gerar invoice.</div>');
                                    console.error('Erro:', error);
                                }
                            });
                        });
                    });
                </script>

                <style>
                    @keyframes rotation {
                        from {
                            transform: rotate(0deg);
                        }

                        to {
                            transform: rotate(360deg);
                        }
                    }
                </style>
                <?php
    }

}



$formulario_hapvida_admin = new Formulario_Hapvida_Admin();