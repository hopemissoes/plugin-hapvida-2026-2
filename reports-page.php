<?php
/**
 * Página de Relatórios de Leads - Formulário Hapvida
 *
 * @package Formulario_Hapvida
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Formulario_Hapvida_Reports
{

    private $session_key = 'hapvida_reports_authenticated';
    private $cookie_name = 'hapvida_reports_auth';
    private $session_timeout = 3600; // 1 hora
    private $current_group = null; // Grupo do usuário logado (drv ou seusouza)

    public function __construct()
    {
        // Hooks para autenticação
        add_action('init', array($this, 'start_session'));
        add_action('wp_ajax_hapvida_reports_login', array($this, 'handle_login'));
        add_action('wp_ajax_nopriv_hapvida_reports_login', array($this, 'handle_login'));
        add_action('wp_ajax_hapvida_reports_logout', array($this, 'handle_logout'));

        // Hooks para dados do dashboard
        add_action('wp_ajax_hapvida_get_dashboard_stats', array($this, 'get_dashboard_stats'));
        add_action('wp_ajax_hapvida_get_leads_by_city', array($this, 'get_leads_by_city'));
        add_action('wp_ajax_hapvida_get_leads_by_vendor', array($this, 'get_leads_by_vendor'));
        add_action('wp_ajax_hapvida_get_leads_by_plan', array($this, 'get_leads_by_plan'));
        add_action('wp_ajax_hapvida_get_leads_timeline', array($this, 'get_leads_timeline'));
        add_action('wp_ajax_hapvida_get_recent_leads_report', array($this, 'get_recent_leads_report'));
        add_action('wp_ajax_hapvida_export_leads_csv', array($this, 'export_leads_csv'));
        add_action('wp_ajax_hapvida_export_invoice', array($this, 'export_invoice'));
        add_action('wp_ajax_hapvida_get_vendors_list', array($this, 'get_vendors_list'));

        // Shortcode para página de relatórios
        add_shortcode('hapvida_reports', array($this, 'render_reports_page'));

        // Debug: Log quando o shortcode for registrado
    }

    /**
     * Inicia sessão PHP
     */
    public function start_session()
    {
        if (!session_id() && !headers_sent()) {
            session_start();
        }
    }

    /**
     * Verifica se usuário está autenticado
     */
    private function is_authenticated()
    {
        // Primeiro verifica cookie
        if (isset($_COOKIE[$this->cookie_name])) {
            $cookie_data = json_decode(stripslashes($_COOKIE[$this->cookie_name]), true);

            if ($cookie_data && isset($cookie_data['time'], $cookie_data['hash'], $cookie_data['group'])) {
                // Verifica se não expirou
                if (time() - $cookie_data['time'] <= $this->session_timeout) {
                    // Valida hash baseado no grupo
                    $settings = get_option('formulario_hapvida_settings', array());
                    $group = $cookie_data['group'];

                    $username = isset($settings[$group . '_username']) ? $settings[$group . '_username'] : '';
                    $password = isset($settings[$group . '_password']) ? $settings[$group . '_password'] : '';

                    $expected_hash = md5($username . $password . $cookie_data['time'] . $group);

                    if ($cookie_data['hash'] === $expected_hash) {
                        // Atualiza cookie com novo timestamp
                        $this->current_group = $group;
                        $this->set_auth_cookie($group);
                        return true;
                    }
                }
            }
        }

        // Fallback: verifica sessão
        if (isset($_SESSION[$this->session_key])) {
            $auth_data = $_SESSION[$this->session_key];

            // Verifica timeout
            if (time() - $auth_data['time'] <= $this->session_timeout) {
                // Atualiza timestamp
                $_SESSION[$this->session_key]['time'] = time();
                $this->current_group = isset($auth_data['group']) ? $auth_data['group'] : null;
                $this->set_auth_cookie($this->current_group);
                return true;
            }
        }

        // Limpa autenticação expirada
        $this->clear_auth();
        return false;
    }

    /**
     * Define cookie de autenticação
     */
    private function set_auth_cookie($group)
    {
        if (!$group) {
            return;
        }

        $settings = get_option('formulario_hapvida_settings', array());
        $username = isset($settings[$group . '_username']) ? $settings[$group . '_username'] : '';
        $password = isset($settings[$group . '_password']) ? $settings[$group . '_password'] : '';

        $time = time();
        $hash = md5($username . $password . $time . $group);

        $cookie_data = json_encode(array(
            'time' => $time,
            'hash' => $hash,
            'group' => $group
        ));

        $cookie_path = defined('COOKIEPATH') ? COOKIEPATH : '/';
        $cookie_domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';

        setcookie(
            $this->cookie_name,
            $cookie_data,
            time() + $this->session_timeout,
            $cookie_path,
            $cookie_domain,
            is_ssl(),
            true // HttpOnly
        );

    }

    /**
     * Limpa autenticação
     */
    private function clear_auth()
    {
        unset($_SESSION[$this->session_key]);

        $cookie_path = defined('COOKIEPATH') ? COOKIEPATH : '/';
        $cookie_domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';

        setcookie($this->cookie_name, '', time() - 3600, $cookie_path, $cookie_domain);
    }

    /**
     * Handle login via AJAX
     */
    public function handle_login()
    {
        // Verifica nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'hapvida_reports_login')) {
            wp_send_json_error(array('message' => 'Requisição inválida'));
            return;
        }

        $username = sanitize_text_field($_POST['username']);
        $password = $_POST['password'];

        // Pega credenciais das configurações
        $settings = get_option('formulario_hapvida_settings', array());

        // Verifica credenciais de cada grupo
        $authenticated_group = null;

        // Verifica DRV
        $drv_username = isset($settings['drv_username']) ? $settings['drv_username'] : '';
        $drv_password = isset($settings['drv_password']) ? $settings['drv_password'] : '';

        if ($username === $drv_username && $password === $drv_password && !empty($drv_username)) {
            $authenticated_group = 'drv';
        }

        // Verifica Seu Souza
        $seusouza_username = isset($settings['seusouza_username']) ? $settings['seusouza_username'] : '';
        $seusouza_password = isset($settings['seusouza_password']) ? $settings['seusouza_password'] : '';

        if ($username === $seusouza_username && $password === $seusouza_password && !empty($seusouza_username)) {
            $authenticated_group = 'seusouza';
        }

        // Valida credenciais
        if ($authenticated_group) {
            $_SESSION[$this->session_key] = array(
                'authenticated' => true,
                'time' => time(),
                'username' => $username,
                'group' => $authenticated_group
            );

            // Define cookie de autenticação
            $this->set_auth_cookie($authenticated_group);

            wp_send_json_success(array(
                'message' => 'Login realizado com sucesso',
                'group' => $authenticated_group
            ));
        } else {
            wp_send_json_error(array('message' => 'Usuário ou senha incorretos'));
        }
    }

    /**
     * Handle logout
     */
    public function handle_logout()
    {
        $this->clear_auth();
        wp_send_json_success(array('message' => 'Logout realizado com sucesso'));
    }

    /**
     * Verifica autenticação para endpoints AJAX
     */
    private function verify_ajax_auth()
    {
        if (!$this->is_authenticated()) {
            wp_send_json_error(array('message' => 'Não autenticado', 'code' => 'not_authenticated'));
            exit;
        }
    }

    /**
     * Retorna o grupo do usuário logado
     */
    private function get_current_group()
    {
        if ($this->current_group) {
            return $this->current_group;
        }

        if (isset($_SESSION[$this->session_key]) && isset($_SESSION[$this->session_key]['group'])) {
            $this->current_group = $_SESSION[$this->session_key]['group'];
            return $this->current_group;
        }

        return null;
    }

    /**
     * Helper: Verifica se lead pertence ao grupo do usuário logado
     */
    private function matches_group_filter($lead)
    {
        $current_group = $this->get_current_group();

        if (!$current_group) {
            return false;
        }

        $lead_data = isset($lead['data']) ? $lead['data'] : array();
        $lead_group = isset($lead_data['grupo']) ? strtolower($lead_data['grupo']) : '';

        // Mapeia grupo do lead para grupo de autenticação
        // DRV -> drv, Seu Souza -> seusouza
        if ($current_group === 'drv') {
            return $lead_group === 'drv';
        } elseif ($current_group === 'seusouza') {
            return $lead_group === 'seu souza' || $lead_group === 'seusouza';
        }

        return false;
    }

    /**
     * Helper: Verifica se lead passa no filtro de vendedor
     */
    private function matches_vendor_filter($lead, $vendor_filter)
    {
        if (empty($vendor_filter)) {
            return true;
        }

        $lead_data = isset($lead['data']) ? $lead['data'] : array();
        $vendor_name = isset($lead_data['vendedor_nome']) ? $lead_data['vendedor_nome'] : '';
        return $vendor_name === $vendor_filter;
    }

    /**
     * Retorna lista de vendedores únicos
     */
    public function get_vendors_list()
    {
        $this->verify_ajax_auth();

        $all_leads = get_option('formulario_hapvida_failed_webhooks', array());
        $vendors = array();

        foreach ($all_leads as $lead) {
            // Filtro por grupo (CRÍTICO)
            if (!$this->matches_group_filter($lead)) {
                continue;
            }

            $lead_data = isset($lead['data']) ? $lead['data'] : array();
            $vendor_name = isset($lead_data['vendedor_nome']) ? $lead_data['vendedor_nome'] : null;
            $vendor_id = isset($lead_data['vendedor_id']) ? $lead_data['vendedor_id'] : null;

            if ($vendor_name && !isset($vendors[$vendor_name])) {
                $vendors[$vendor_name] = array(
                    'id' => $vendor_id ? $vendor_id : $vendor_name,
                    'nome' => $vendor_name
                );
            }
        }

        // Ordena alfabeticamente
        uasort($vendors, function ($a, $b) {
            return strcmp($a['nome'], $b['nome']);
        });

        wp_send_json_success(array_values($vendors));
    }

    /**
     * Pega estatísticas gerais do dashboard
     */
    public function get_dashboard_stats()
    {
        $this->verify_ajax_auth();

        // Busca leads das options do WordPress (onde realmente estão sendo salvos)
        $all_leads = get_option('formulario_hapvida_failed_webhooks', array());

        // Pega filtros
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : date('Y-m-d', strtotime('-30 days'));
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : date('Y-m-d');
        $vendor_filter = isset($_POST['vendor_filter']) ? sanitize_text_field($_POST['vendor_filter']) : '';

        // Debug: Log dos filtros recebidos
        error_log('Dashboard Stats - Filtros: start=' . $start_date . ', end=' . $end_date . ', vendor=' . $vendor_filter);
        error_log('Dashboard Stats - Total de leads no sistema: ' . count($all_leads));

        // Verifica a data do lead mais antigo e mais recente
        $oldest_date = null;
        $newest_date = null;
        foreach ($all_leads as $lead) {
            if (isset($lead['created_at'])) {
                $lead_date = $lead['created_at'];
                if (!$oldest_date || $lead_date < $oldest_date) {
                    $oldest_date = $lead_date;
                }
                if (!$newest_date || $lead_date > $newest_date) {
                    $newest_date = $lead_date;
                }
            }
        }
        error_log('Dashboard Stats - Lead mais antigo: ' . ($oldest_date ?? 'N/A') . ', Lead mais recente: ' . ($newest_date ?? 'N/A'));

        // Converte datas para timestamp
        $start_timestamp = strtotime($start_date . ' 00:00:00');
        $end_timestamp = strtotime($end_date . ' 23:59:59');
        $today_start = strtotime(date('Y-m-d') . ' 00:00:00');
        $today_end = strtotime(date('Y-m-d') . ' 23:59:59');
        $month_start = strtotime(date('Y-m-01') . ' 00:00:00');
        $week_start = strtotime('-7 days', $today_start);

        // Contadores
        $total_leads = 0;
        $leads_by_date = array(); // Para calcular pico

        foreach ($all_leads as $lead) {
            // Filtro por grupo (CRÍTICO)
            if (!$this->matches_group_filter($lead)) {
                continue;
            }

            // Filtro por vendedor
            if (!$this->matches_vendor_filter($lead, $vendor_filter)) {
                continue;
            }

            // Pega timestamp do lead (created_at é string MySQL)
            $lead_timestamp = 0;
            if (isset($lead['created_at'])) {
                $lead_timestamp = strtotime($lead['created_at']);
            }

            // Conta total no período
            if ($lead_timestamp >= $start_timestamp && $lead_timestamp <= $end_timestamp) {
                $total_leads++;

                // Agrupa por data para calcular pico
                $date_key = date('Y-m-d', $lead_timestamp);
                if (!isset($leads_by_date[$date_key])) {
                    $leads_by_date[$date_key] = 0;
                }
                $leads_by_date[$date_key]++;
            }
        }

        // Média de leads por dia no período
        $days_diff = max(1, round(($end_timestamp - $start_timestamp) / 86400) + 1);
        $avg_leads_per_day = round($total_leads / $days_diff, 1);

        // Encontra o pico (dia com mais leads)
        $peak_day_count = 0;
        $peak_day_date = '';
        if (!empty($leads_by_date)) {
            $peak_day_count = max($leads_by_date);
            $peak_day_date = array_search($peak_day_count, $leads_by_date);
        }

        // Debug: Log dos resultados
        error_log('Dashboard Stats - Resultados: total=' . $total_leads . ', média=' . $avg_leads_per_day . ', pico=' . $peak_day_count);

        wp_send_json_success(array(
            'total_leads' => intval($total_leads),
            'avg_leads_per_day' => floatval($avg_leads_per_day),
            'peak_day_count' => intval($peak_day_count),
            'peak_day_date' => $peak_day_date
        ));
    }

    /**
     * Pega leads por cidade
     */
    public function get_leads_by_city()
    {
        $this->verify_ajax_auth();

        $all_leads = get_option('formulario_hapvida_failed_webhooks', array());

        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : date('Y-m-d', strtotime('-30 days'));
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : date('Y-m-d');
        $vendor_filter = isset($_POST['vendor_filter']) ? sanitize_text_field($_POST['vendor_filter']) : '';

        $start_timestamp = strtotime($start_date . ' 00:00:00');
        $end_timestamp = strtotime($end_date . ' 23:59:59');

        $cities = array();

        foreach ($all_leads as $lead) {
            // Filtro por grupo (CRÍTICO)
            if (!$this->matches_group_filter($lead)) {
                continue;
            }

            // Filtro por vendedor
            if (!$this->matches_vendor_filter($lead, $vendor_filter)) {
                continue;
            }

            $lead_timestamp = 0;
            if (isset($lead['created_at'])) {
                $lead_timestamp = strtotime($lead['created_at']);
            }

            // Filtra por data
            if ($lead_timestamp >= $start_timestamp && $lead_timestamp <= $end_timestamp) {
                $lead_data = isset($lead['data']) ? $lead['data'] : array();
                $city = isset($lead_data['cidade']) ? $lead_data['cidade'] : 'Não informada';

                if (!isset($cities[$city])) {
                    $cities[$city] = 0;
                }
                $cities[$city]++;
            }
        }

        // Ordena por quantidade
        arsort($cities);

        wp_send_json_success($cities);
    }

    /**
     * Pega leads por vendedor
     */
    public function get_leads_by_vendor()
    {
        $this->verify_ajax_auth();

        $all_leads = get_option('formulario_hapvida_failed_webhooks', array());

        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : date('Y-m-d', strtotime('-30 days'));
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : date('Y-m-d');

        $start_timestamp = strtotime($start_date . ' 00:00:00');
        $end_timestamp = strtotime($end_date . ' 23:59:59');

        $vendors = array();

        foreach ($all_leads as $lead) {
            // Filtro por grupo (CRÍTICO)
            if (!$this->matches_group_filter($lead)) {
                continue;
            }

            $lead_timestamp = 0;
            if (isset($lead['created_at'])) {
                $lead_timestamp = strtotime($lead['created_at']);
            }

            // Filtra por data
            if ($lead_timestamp >= $start_timestamp && $lead_timestamp <= $end_timestamp) {
                $lead_data = isset($lead['data']) ? $lead['data'] : array();
                $vendor_name = isset($lead_data['vendedor_nome']) ? $lead_data['vendedor_nome'] : 'Não informado';
                $vendor_id = isset($lead_data['vendedor_id']) ? $lead_data['vendedor_id'] : $vendor_name;
                $grupo = isset($lead_data['grupo']) ? $lead_data['grupo'] : 'N/A';

                if (!isset($vendors[$vendor_id])) {
                    $vendors[$vendor_id] = array(
                        'nome' => $vendor_name,
                        'grupo' => $grupo,
                        'total' => 0
                    );
                }

                $vendors[$vendor_id]['total']++;
            }
        }

        // Ordena por total
        uasort($vendors, function ($a, $b) {
            return $b['total'] - $a['total'];
        });

        wp_send_json_success($vendors);
    }

    /**
     * Pega leads por plano
     */
    public function get_leads_by_plan()
    {
        $this->verify_ajax_auth();

        $all_leads = get_option('formulario_hapvida_failed_webhooks', array());

        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : date('Y-m-d', strtotime('-30 days'));
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : date('Y-m-d');
        $vendor_filter = isset($_POST['vendor_filter']) ? sanitize_text_field($_POST['vendor_filter']) : '';

        $start_timestamp = strtotime($start_date . ' 00:00:00');
        $end_timestamp = strtotime($end_date . ' 23:59:59');

        $plans = array(
            'Individual' => 0,
            'Empresarial' => 0,
            'Não informado' => 0
        );

        foreach ($all_leads as $lead) {
            // Filtro por grupo (CRÍTICO)
            if (!$this->matches_group_filter($lead)) {
                continue;
            }

            // Filtro por vendedor
            if (!$this->matches_vendor_filter($lead, $vendor_filter)) {
                continue;
            }

            $lead_timestamp = 0;
            if (isset($lead['created_at'])) {
                $lead_timestamp = strtotime($lead['created_at']);
            }

            // Filtra por data
            if ($lead_timestamp >= $start_timestamp && $lead_timestamp <= $end_timestamp) {
                $lead_data = isset($lead['data']) ? $lead['data'] : array();
                $qtd_pessoas = isset($lead_data['qtd_pessoas']) ? intval($lead_data['qtd_pessoas']) : 0;

                if ($qtd_pessoas === 0) {
                    $plans['Não informado']++;
                } elseif ($qtd_pessoas === 1) {
                    $plans['Individual']++;
                } else {
                    $plans['Empresarial']++;
                }
            }
        }

        wp_send_json_success($plans);
    }

    /**
     * Pega timeline de leads
     */
    public function get_leads_timeline()
    {
        $this->verify_ajax_auth();

        $all_leads = get_option('formulario_hapvida_failed_webhooks', array());

        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : date('Y-m-d', strtotime('-30 days'));
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : date('Y-m-d');
        $vendor_filter = isset($_POST['vendor_filter']) ? sanitize_text_field($_POST['vendor_filter']) : '';

        $start_timestamp = strtotime($start_date . ' 00:00:00');
        $end_timestamp = strtotime($end_date . ' 23:59:59');

        $timeline = array();

        // Inicializa timeline com todas as datas do período
        $current = strtotime($start_date);
        $end = strtotime($end_date);
        while ($current <= $end) {
            $timeline[date('Y-m-d', $current)] = 0;
            $current = strtotime('+1 day', $current);
        }

        // Conta leads por data
        foreach ($all_leads as $lead) {
            // Filtro por grupo (CRÍTICO)
            if (!$this->matches_group_filter($lead)) {
                continue;
            }

            // Filtro por vendedor
            if (!$this->matches_vendor_filter($lead, $vendor_filter)) {
                continue;
            }

            $lead_timestamp = 0;
            if (isset($lead['created_at'])) {
                $lead_timestamp = strtotime($lead['created_at']);
            }

            // Filtra por data
            if ($lead_timestamp >= $start_timestamp && $lead_timestamp <= $end_timestamp) {
                $date_key = date('Y-m-d', $lead_timestamp);
                if (isset($timeline[$date_key])) {
                    $timeline[$date_key]++;
                }
            }
        }

        wp_send_json_success($timeline);
    }

    /**
     * Pega leads recentes para tabela
     */
    public function get_recent_leads_report()
    {
        $this->verify_ajax_auth();

        $all_leads = get_option('formulario_hapvida_failed_webhooks', array());

        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : date('Y-m-d', strtotime('-30 days'));
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : date('Y-m-d');
        $vendor_filter = isset($_POST['vendor_filter']) ? sanitize_text_field($_POST['vendor_filter']) : '';

        $start_timestamp = strtotime($start_date . ' 00:00:00');
        $end_timestamp = strtotime($end_date . ' 23:59:59');

        $filtered_leads = array();

        // Filtra por data
        foreach ($all_leads as $lead) {
            // Filtro por grupo (CRÍTICO)
            if (!$this->matches_group_filter($lead)) {
                continue;
            }

            // Filtro por vendedor
            if (!$this->matches_vendor_filter($lead, $vendor_filter)) {
                continue;
            }

            $lead_timestamp = 0;
            if (isset($lead['created_at'])) {
                $lead_timestamp = strtotime($lead['created_at']);
            }

            if ($lead_timestamp >= $start_timestamp && $lead_timestamp <= $end_timestamp) {
                $filtered_leads[] = $lead;
            }
        }

        // Ordena por timestamp descendente
        usort($filtered_leads, function ($a, $b) {
            $ts_a = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
            $ts_b = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
            return $ts_b - $ts_a;
        });

        // Aplica limit e offset
        $filtered_leads = array_slice($filtered_leads, $offset, $limit);

        $leads = array();

        foreach ($filtered_leads as $lead) {
            $lead_data = isset($lead['data']) ? $lead['data'] : array();
            $leads[] = array(
                'lead_id' => isset($lead['id']) ? $lead['id'] : '',
                'nome' => isset($lead_data['nome']) ? $lead_data['nome'] : (isset($lead_data['name']) ? $lead_data['name'] : ''),
                'telefone' => isset($lead_data['telefone']) ? $lead_data['telefone'] : '',
                'cidade' => isset($lead_data['cidade']) ? $lead_data['cidade'] : '',
                'plano' => isset($lead_data['qual_plano']) ? $lead_data['qual_plano'] : '',
                'qtd_pessoas' => isset($lead_data['qtd_pessoas']) ? $lead_data['qtd_pessoas'] : '',
                'idades' => isset($lead_data['idades']) ? $lead_data['idades'] : '',
                'vendedor' => isset($lead_data['vendedor_nome']) ? $lead_data['vendedor_nome'] : (isset($lead_data['atendente']) ? $lead_data['atendente'] : ''),
                'grupo' => isset($lead_data['grupo']) ? $lead_data['grupo'] : '',
                'criado_em' => isset($lead['created_at']) ? $lead['created_at'] : ''
            );
        }

        wp_send_json_success($leads);
    }

    /**
     * Exporta leads para CSV
     */
    public function export_leads_csv()
    {
        $this->verify_ajax_auth();

        $all_leads = get_option('formulario_hapvida_failed_webhooks', array());

        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : date('Y-m-d', strtotime('-30 days'));
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : date('Y-m-d');
        $vendor_filter = isset($_POST['vendor_filter']) ? sanitize_text_field($_POST['vendor_filter']) : '';

        $start_timestamp = strtotime($start_date . ' 00:00:00');
        $end_timestamp = strtotime($end_date . ' 23:59:59');

        // Prepara CSV
        $csv_data = array();
        $csv_data[] = array(
            'Lead ID',
            'Data/Hora',
            'Nome',
            'Telefone',
            'Cidade',
            'Plano',
            'Qtd Pessoas',
            'Idades',
            'Vendedor',
            'Grupo',
            'Página Origem'
        );

        foreach ($all_leads as $lead) {
            // Filtro por grupo (CRÍTICO)
            if (!$this->matches_group_filter($lead)) {
                continue;
            }

            // Filtro por vendedor
            if (!$this->matches_vendor_filter($lead, $vendor_filter)) {
                continue;
            }

            $lead_timestamp = 0;
            if (isset($lead['created_at'])) {
                $lead_timestamp = strtotime($lead['created_at']);
            }

            // Filtra por data
            if ($lead_timestamp >= $start_timestamp && $lead_timestamp <= $end_timestamp) {
                $lead_data = isset($lead['data']) ? $lead['data'] : array();
                $csv_data[] = array(
                    isset($lead['id']) ? $lead['id'] : '',
                    isset($lead['created_at']) ? $lead['created_at'] : '',
                    isset($lead_data['nome']) ? $lead_data['nome'] : (isset($lead_data['name']) ? $lead_data['name'] : ''),
                    isset($lead_data['telefone']) ? $lead_data['telefone'] : '',
                    isset($lead_data['cidade']) ? $lead_data['cidade'] : '',
                    isset($lead_data['qual_plano']) ? $lead_data['qual_plano'] : '',
                    isset($lead_data['qtd_pessoas']) ? $lead_data['qtd_pessoas'] : '',
                    isset($lead_data['idades']) ? $lead_data['idades'] : '',
                    isset($lead_data['vendedor_nome']) ? $lead_data['vendedor_nome'] : (isset($lead_data['atendente']) ? $lead_data['atendente'] : ''),
                    isset($lead_data['grupo']) ? $lead_data['grupo'] : '',
                    isset($lead_data['pagina_origem']) ? $lead_data['pagina_origem'] : ''
                );
            }
        }

        wp_send_json_success(array('csv_data' => $csv_data));
    }

    /**
     * Exporta invoice HTML com resumo das informações
     */
    public function export_invoice()
    {
        // IMPORTANTE: Apenas para usuários admin
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada. Apenas administradores podem gerar invoices.');
            return;
        }

        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : date('Y-m-d', strtotime('-30 days'));
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : date('Y-m-d');

        // IMPORTANTE: Usa quantidade manual informada pelo usuário
        $total_leads = isset($_POST['quantity']) ? intval($_POST['quantity']) : 0;

        if ($total_leads < 1) {
            wp_send_json_error('Quantidade de leads inválida. Informe um valor maior que zero.');
            return;
        }

        // Grupo selecionado (DRV ou Seu Souza)
        $selected_group = isset($_POST['group']) ? sanitize_text_field($_POST['group']) : 'drv';
        $group_name = ($selected_group === 'drv') ? 'DRV' : 'Seu Souza';

        // Calcula valor (R$ 12,00 por lead)
        $valor_unitario = 12.00;
        $total_value = $total_leads * $valor_unitario;

        // *** PAGAMENTO ANTECIPADO (POR CONTA) ***
        $advance_payment = isset($_POST['advance_payment']) ? floatval($_POST['advance_payment']) : 0;
        $advance_date = isset($_POST['advance_date']) ? sanitize_text_field($_POST['advance_date']) : '';

        // Calcula saldo restante
        $remaining_balance = $total_value - $advance_payment;

        // *** NÚMERO DE INVOICE SEQUENCIAL ***
        // Busca o último número de invoice usado
        $last_invoice_number = get_option('hapvida_last_invoice_number', 0);

        // Incrementa para o próximo número
        $current_invoice_number = $last_invoice_number + 1;

        // Salva o novo número
        update_option('hapvida_last_invoice_number', $current_invoice_number);

        // Formata com zeros à esquerda (0001, 0002, etc)
        $invoice_number = str_pad($current_invoice_number, 4, '0', STR_PAD_LEFT);

        // Datas
        $emission_date = date('d/m/Y');
        $due_date = date('d/m/Y', strtotime('+7 days'));

        // Formata período (ex: "Dez/2025")
        setlocale(LC_TIME, 'pt_BR.UTF-8', 'pt_BR', 'portuguese');
        $period_month = strftime('%b/%Y', strtotime($start_date));

        // Gera HTML do invoice com novo modelo
        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="pt-BR">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Invoice #<?php echo $invoice_number; ?> - P3 Consultoria Digital</title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }

                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
                    line-height: 1.6;
                    color: #333;
                    background: #f5f5f5;
                    padding: 20px;
                }

                .invoice-container {
                    max-width: 800px;
                    margin: 0 auto;
                    background: white;
                    padding: 50px;
                    box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
                }

                h1 {
                    font-size: 48px;
                    font-weight: 700;
                    color: #000;
                    margin-bottom: 10px;
                }

                h2 {
                    font-size: 24px;
                    font-weight: 600;
                    color: #333;
                    margin: 30px 0 15px 0;
                }

                h3 {
                    font-size: 18px;
                    font-weight: 600;
                    color: #333;
                    margin: 25px 0 10px 0;
                }

                .subtitle {
                    font-size: 16px;
                    color: #666;
                    margin-bottom: 5px;
                }

                .separator {
                    border-top: 2px solid #e0e0e0;
                    margin: 20px 0;
                }

                .invoice-meta {
                    display: flex;
                    justify-content: space-between;
                    margin: 20px 0;
                }

                .invoice-meta div {
                    flex: 1;
                }

                .section {
                    margin: 30px 0;
                }

                .company-info p {
                    margin: 5px 0;
                    color: #555;
                }

                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 20px 0;
                }

                table th {
                    background: #f8f9fa;
                    padding: 12px;
                    text-align: left;
                    font-weight: 600;
                    border-bottom: 2px solid #dee2e6;
                }

                table td {
                    padding: 12px;
                    border-bottom: 1px solid #e9ecef;
                }

                .summary-section {
                    margin: 30px 0;
                }

                .summary-line {
                    display: flex;
                    justify-content: space-between;
                    padding: 8px 0;
                    font-size: 16px;
                }

                .total-line {
                    font-size: 32px;
                    font-weight: 700;
                    color: #000;
                    margin-top: 15px;
                    padding-top: 15px;
                    border-top: 2px solid #333;
                }

                .payment-box {
                    background: #f8f9fa;
                    padding: 20px;
                    border-radius: 8px;
                    margin: 20px 0;
                }

                .payment-box strong {
                    display: block;
                    font-size: 18px;
                    margin-bottom: 10px;
                }

                .notes-section {
                    background: #fff9e6;
                    border-left: 4px solid #ffc107;
                    padding: 15px;
                    margin: 30px 0;
                }

                .notes-section ul {
                    list-style-position: inside;
                    margin: 10px 0;
                }

                .notes-section li {
                    margin: 5px 0;
                }

                @media print {
                    body {
                        background: white;
                        padding: 0;
                    }

                    .invoice-container {
                        box-shadow: none;
                        padding: 0;
                    }
                }
            </style>
        </head>

        <body>
            <div class="invoice-container">
                <!-- Cabeçalho -->
                <h1>INVOICE</h1>
                <p class="subtitle"><strong>Agência P3 Consultoria Digital</strong></p>
                <p class="subtitle">Geração de Leads Hapvida</p>

                <div class="separator"></div>

                <!-- Número e Datas -->
                <h2>Invoice #<?php echo $invoice_number; ?></h2>
                <div class="invoice-meta">
                    <div>
                        <strong>Emissão:</strong> <?php echo $emission_date; ?>
                    </div>
                    <div>
                        <strong>Vencimento:</strong> <?php echo $due_date; ?>
                    </div>
                </div>

                <div class="separator"></div>

                <!-- Faturado Por -->
                <div class="section">
                    <h3>Faturado por</h3>
                    <div class="company-info">
                        <p><strong>P3 Consultoria Digital</strong></p>
                        <p>CNPJ: 60.372.769/0001-62</p>
                        <p>📍 Fortaleza – CE, Brasil</p>
                        <p>📞 (85) 99907-9494</p>
                    </div>
                </div>

                <div class="separator"></div>

                <!-- Faturado Para -->
                <div class="section">
                    <h3>Faturado para</h3>
                    <div class="company-info">
                        <p><strong><?php echo esc_html($group_name); ?></strong></p>
                        <p>CNPJ: 11.111.111/0001-11</p>
                        <p>📍 São Paulo – SP, Brasil</p>
                    </div>
                </div>

                <div class="separator"></div>

                <!-- Descrição do Serviço -->
                <h2>Descrição do Serviço</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Serviço</th>
                            <th>Período</th>
                            <th>Qtde</th>
                            <th>Valor Unit.</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Leads qualificados – Plano de Saúde</td>
                            <td><?php echo $period_month; ?></td>
                            <td><?php echo number_format($total_leads, 0, ',', '.'); ?></td>
                            <td>R$ <?php echo number_format($valor_unitario, 2, ',', '.'); ?></td>
                            <td><strong>R$ <?php echo number_format($total_value, 2, ',', '.'); ?></strong></td>
                        </tr>
                    </tbody>
                </table>

                <div class="separator"></div>

                <!-- Resumo -->
                <h2>Resumo</h2>
                <div class="summary-section">
                    <div class="summary-line">
                        <span>• <strong>Subtotal:</strong></span>
                        <span>R$ <?php echo number_format($total_value, 2, ',', '.'); ?></span>
                    </div>
                    <div class="summary-line">
                        <span>• <strong>Impostos:</strong></span>
                        <span>R$ 0,00</span>
                    </div>
                    <?php if ($advance_payment > 0): ?>
                        <div class="summary-line" style="color: #28a745;">
                            <span>• <strong>(-) Por Conta<?php if (!empty($advance_date)): ?>
                                        (<?php echo date('d/m/Y', strtotime($advance_date)); ?>)<?php endif; ?>:</strong></span>
                            <span>- R$ <?php echo number_format($advance_payment, 2, ',', '.'); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="summary-line">
                        <span>• <strong>Total a Pagar:</strong></span>
                        <span></span>
                    </div>
                    <div class="total-line">
                        <div style="text-align: right;">R$ <?php echo number_format($remaining_balance, 2, ',', '.'); ?></div>
                    </div>
                </div>

                <div class="separator"></div>

                <!-- Pagamento -->
                <h2>Pagamento</h2>
                <div class="payment-box">
                    <strong>PIX</strong>
                    <p style="margin-top: 10px;"><strong>Chave PIX:</strong> 60.372.769/0001-62</p>
                </div>

                <div class="separator"></div>

                <!-- Notas -->
                <h3>Notas</h3>
                <div class="notes-section">
                    <ul>
                        <li>Leads entregues entre <strong><?php echo date('d/m/Y', strtotime($start_date)); ?> e
                                <?php echo date('d/m/Y', strtotime($end_date)); ?></strong></li>
                        <li>Pagamento até a data de vencimento</li>
                        <li>Documento válido como comprovante de prestação de serviço</li>
                    </ul>
                </div>
            </div>

            <script>
                // Auto-print opcional
                // window.onload = function() { window.print(); };
            </script>
        </body>

        </html>
        <?php
        $html = ob_get_clean();

        wp_send_json_success(array('html' => $html));
    }
    public function render_reports_page($atts = array())
    {

        // Verifica autenticação
        $is_authenticated = $this->is_authenticated();

        ob_start();

        $template_path = plugin_dir_path(__FILE__) . 'reports-template.php';

        if (file_exists($template_path)) {
            // Passa variável para o template
            $authenticated = $is_authenticated;
            include $template_path;
        } else {
            echo '<div style="padding: 20px; background: #f44336; color: white; margin: 20px 0;">';
            echo '<h2>⚠️ Erro: Template de Relatórios não encontrado</h2>';
            echo '<p><strong>Caminho esperado:</strong> ' . esc_html($template_path) . '</p>';
            echo '<p><strong>Arquivo existe?</strong> ' . (file_exists($template_path) ? 'SIM' : 'NÃO') . '</p>';
            echo '<p><strong>Plugin dir:</strong> ' . esc_html(plugin_dir_path(__FILE__)) . '</p>';
            echo '</div>';
        }

        $content = ob_get_clean();

        return $content;
    }
}

// Inicializa a classe somente se o WordPress estiver carregado
if (function_exists('add_shortcode')) {
    new Formulario_Hapvida_Reports();
}