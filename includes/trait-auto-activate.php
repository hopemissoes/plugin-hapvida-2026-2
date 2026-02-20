<?php
if (!defined('ABSPATH')) exit;

trait AutoActivateTrait {

    public function add_auto_activate_cron_interval($schedules)
    {
        if (!isset($schedules['hapvida_thirty_minutes'])) {
            $schedules['hapvida_thirty_minutes'] = array(
                'interval' => 1800,
                'display' => 'A cada 30 minutos (Hapvida Auto-Ativação)'
            );
        }
        return $schedules;
    }

    public function schedule_auto_activate_seu_souza()
    {
        $auto_activate = get_option('hapvida_auto_activate_seu_souza', false);

        if ($auto_activate) {
            if (!wp_next_scheduled('hapvida_auto_activate_seu_souza')) {
                wp_schedule_event(time(), 'hapvida_thirty_minutes', 'hapvida_auto_activate_seu_souza');
            }
            if (!wp_next_scheduled('hapvida_auto_deactivate_seu_souza')) {
                wp_schedule_event(time(), 'hapvida_thirty_minutes', 'hapvida_auto_deactivate_seu_souza');
            }

            // Fallback: executa verificação direta a cada 10 minutos
            // (WP-Cron depende de visitas ao site e pode falhar)
            $last_check = get_transient('hapvida_auto_activate_last_check');
            if (!$last_check) {
                set_transient('hapvida_auto_activate_last_check', time(), 600); // throttle 10 min
                $this->auto_activate_seu_souza();
                $this->auto_deactivate_seu_souza();
            }
        } else {
            $ts1 = wp_next_scheduled('hapvida_auto_activate_seu_souza');
            if ($ts1) wp_unschedule_event($ts1, 'hapvida_auto_activate_seu_souza');
            $ts2 = wp_next_scheduled('hapvida_auto_deactivate_seu_souza');
            if ($ts2) wp_unschedule_event($ts2, 'hapvida_auto_deactivate_seu_souza');
        }
    }

    public function auto_activate_seu_souza()
    {
        if (!get_option('hapvida_auto_activate_seu_souza', false)) {
            error_log("HAPVIDA AUTO-ATIVAÇÃO: Opção desabilitada, ignorando");
            return;
        }

        $current_hour = (int) current_time('G');
        $current_day = (int) current_time('N');

        error_log("HAPVIDA AUTO-ATIVAÇÃO: Verificando - dia={$current_day} (1=seg,7=dom), hora={$current_hour}h");

        if ($current_day >= 1 && $current_day <= 5 && $current_hour >= 8 && $current_hour < 12) {
            $vendedores = get_option($this->vendedores_option, array('drv' => array(), 'seu_souza' => array()));
            if (!isset($vendedores['seu_souza']) || !is_array($vendedores['seu_souza'])) {
                error_log("HAPVIDA AUTO-ATIVAÇÃO: Grupo seu_souza não encontrado ou vazio");
                return;
            }

            $changed = false;
            $count = 0;
            foreach ($vendedores['seu_souza'] as &$v) {
                if (is_array($v) && isset($v['status']) && $v['status'] === 'inativo') {
                    $v['status'] = 'ativo';
                    $changed = true;
                    $count++;
                    error_log("HAPVIDA AUTO-ATIVAÇÃO: Ativando vendedor: " . ($v['nome'] ?? 'sem nome'));
                }
            }
            unset($v);

            if ($changed) {
                update_option($this->vendedores_option, $vendedores);
                $this->log("AUTO-ATIVAÇÃO: {$count} vendedor(es) Seu Souza ATIVADOS (dia útil, {$current_hour}h)");
            } else {
                error_log("HAPVIDA AUTO-ATIVAÇÃO: Todos vendedores Seu Souza já estão ativos");
            }
        } else {
            error_log("HAPVIDA AUTO-ATIVAÇÃO: Fora do horário de ativação (seg-sex 8h-12h)");
        }
    }

    public function auto_deactivate_seu_souza()
    {
        if (!get_option('hapvida_auto_activate_seu_souza', false)) return;

        $current_hour = (int) current_time('G');
        $current_day = (int) current_time('N');

        $is_weekend = ($current_day >= 6);
        $is_outside_hours = ($current_hour < 8 || $current_hour >= 12);

        if ($is_weekend || $is_outside_hours) {
            $vendedores = get_option($this->vendedores_option, array('drv' => array(), 'seu_souza' => array()));
            if (!isset($vendedores['seu_souza']) || !is_array($vendedores['seu_souza'])) return;

            $changed = false;
            $count = 0;
            foreach ($vendedores['seu_souza'] as &$v) {
                if (is_array($v) && isset($v['status']) && $v['status'] === 'ativo') {
                    $v['status'] = 'inativo';
                    $changed = true;
                    $count++;
                }
            }
            unset($v);

            if ($changed) {
                update_option($this->vendedores_option, $vendedores);
                $reason = $is_weekend ? 'fim de semana' : "fora do horário ({$current_hour}h)";
                $this->log("AUTO-DESATIVAÇÃO: {$count} vendedor(es) Seu Souza DESATIVADOS ({$reason})");
            }
        }
    }

    public function ajax_toggle_auto_activate_seu_souza()
    {
        check_ajax_referer('save_vendedores', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'true';
        update_option('hapvida_auto_activate_seu_souza', $enabled);
        $this->schedule_auto_activate_seu_souza();

        if ($enabled) {
            $this->auto_activate_seu_souza();
            $this->auto_deactivate_seu_souza();
        }

        wp_send_json_success(array(
            'message' => $enabled ? 'Auto-ativação Seu Souza ATIVADA' : 'Auto-ativação Seu Souza DESATIVADA',
            'enabled' => $enabled
        ));
    }
}
