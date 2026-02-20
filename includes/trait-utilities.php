<?php
if (!defined('ABSPATH')) exit;

trait UtilitiesTrait {

    private function log($message)
    {
        // TEMPORARIO: Loga tudo para debug
        // Filtra para logar apenas dados de leads e mensagens de erro
        if (
            strpos($message, 'Dados recebidos:') !== false ||
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

        // Loga mensagens de webhook e debug que começam com >>>, === ou WEBHOOK/AVISO
        if (strpos($message, '>>>') === 0 || strpos($message, '===') === 0 || strpos($message, 'AVISO') === 0 || strpos($message, 'WEBHOOK') !== false) {
            $timezone = new DateTimeZone('America/Fortaleza');
            $timestamp = new DateTime('now', $timezone);
            $log_entry = "[" . $timestamp->format('Y-m-d H:i:s') . "] {$message}" . PHP_EOL;
            error_log($log_entry, 3, $this->log_file);
        }
    }

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
            $this->log("Timezone configurado: " . $timezone_string);
        }

        // Log de debug
        $this->log("Data/Hora atual (WordPress): " . current_time('d/m/Y H:i:s'));
        $this->log("Data/Hora atual (PHP): " . date('d/m/Y H:i:s'));
    }

    private function load_timeout_settings()
    {
        $options = get_option($this->settings_option_name);

        // Define timeouts com base nas opções ou usa padrões
        $this->business_hours_timeout = isset($options['business_hours_timeout']) ? intval($options['business_hours_timeout']) : 10;
        $this->after_hours_timeout = isset($options['after_hours_timeout']) ? intval($options['after_hours_timeout']) : 30;

        $this->log("Timeouts carregados - Comercial: {$this->business_hours_timeout}min, Fora do horário: {$this->after_hours_timeout}min");
    }

    public function is_horario_comercial()
    {
        $current_hour = intval(current_time('H'));
        $is_business = ($current_hour >= 8 && $current_hour < 18);
        return $is_business;
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

    public function update_daily_submissions_count()
    {
        // *** CORREÇÃO: USA current_time() DO WORDPRESS ***
        $today = current_time('Y-m-d');
        $month = current_time('Y-m');

        // Log de debug
        $this->log("Atualizando contagens - Data: {$today}, Mes: {$month}");

        // Diária
        $daily_submissions = get_option($this->daily_submissions_option, array());
        if (!isset($daily_submissions[$today])) {
            $daily_submissions[$today] = 1;
        } else {
            $daily_submissions[$today]++;
        }
        update_option($this->daily_submissions_option, $daily_submissions);

        $this->log("Contagem diaria atualizada: {$daily_submissions[$today]} submissoes em {$today}");

        // Mensal
        $monthly_submissions = get_option($this->monthly_submissions_option, array());
        if (!isset($monthly_submissions[$month])) {
            $monthly_submissions[$month] = 1;
        } else {
            $monthly_submissions[$month]++;
        }
        update_option($this->monthly_submissions_option, $monthly_submissions);

        $this->log("Contagem mensal atualizada: {$monthly_submissions[$month]} submissoes em {$month}");

        // Verifica limite diário para auto-desativação Seu Souza
        $daily_limit = intval(get_option('hapvida_seu_souza_daily_limit', 30));
        if ($daily_limit > 0 && $daily_submissions[$today] >= $daily_limit) {
            $this->deactivate_seu_souza_by_daily_limit($daily_submissions[$today], $daily_limit);
        }
    }

    /**
     * Desativa vendedores Seu Souza quando o limite diário é atingido
     */
    private function deactivate_seu_souza_by_daily_limit($current_count, $limit)
    {
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
            $this->log("LIMITE DIARIO: Vendedores Seu Souza DESATIVADOS (contagem {$current_count} atingiu limite de {$limit})");
        }
    }

    private function get_daily_submission_count()
    {
        $daily_submissions = get_option('formulario_hapvida_daily_submissions', array());
        $today = current_time('Y-m-d');

        return isset($daily_submissions[$today]) ? $daily_submissions[$today] : 0;
    }

    private function get_monthly_submission_count()
    {
        $monthly_submissions = get_option('formulario_hapvida_monthly_submissions', array());
        $current_month = current_time('Y-m');

        return isset($monthly_submissions[$current_month]) ? $monthly_submissions[$current_month] : 0;
    }

    private function get_current_timeout()
    {
        if ($this->is_horario_comercial()) {
            return $this->business_hours_timeout;
        }

        return $this->after_hours_timeout;
    }

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
        $this->log("[CORREÇÃO] ID único gerado: {$lead_id} (último ID era: {$last_id})");
        error_log("HAPVIDA DEBUG: ID único gerado - {$lead_id}");

        return $lead_id;
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

}
