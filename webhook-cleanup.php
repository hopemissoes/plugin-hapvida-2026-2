<?php
/**
 * Sistema de Limpeza Automática de Webhooks
 * Arquivo: webhook-cleanup.php
 * 
 * Este arquivo deve ser incluído no formulario-hapvida.php
 * para gerenciar a limpeza automática de webhooks antigos
 */

// Impede acesso direto ao arquivo
if (!defined('ABSPATH')) {
    exit;
}

class Formulario_Hapvida_Webhook_Cleanup {
    
    private $failed_webhooks_option = 'formulario_hapvida_failed_webhooks';
    private $log_file;

    public function __construct() {
        $this->log_file = WP_CONTENT_DIR . '/formulario_hapvida.log';
        
        // Agenda limpeza diária
        add_action('init', array($this, 'schedule_cleanup'));
        add_action('formulario_hapvida_daily_cleanup', array($this, 'daily_cleanup'));
        
        // Hook para desativar o plugin (limpa cron jobs)
        register_deactivation_hook(__FILE__, array($this, 'deactivate_cleanup'));
    }

    /**
     * Agenda a limpeza diária
     */
    public function schedule_cleanup() {
        if (!wp_next_scheduled('formulario_hapvida_daily_cleanup')) {
            // Executa diariamente à meia-noite
            wp_schedule_event(strtotime('tomorrow'), 'daily', 'formulario_hapvida_daily_cleanup');
        }
    }

    /**
     * Executa limpeza diária dos webhooks
     */
    public function daily_cleanup() {
        $this->log("=== Iniciando limpeza diária de webhooks ===");
        
        $failed_webhooks = get_option($this->failed_webhooks_option, array());
        $original_count = count($failed_webhooks);
        
        if ($original_count === 0) {
            $this->log("Nenhum webhook para limpar");
            return;
        }
        
        $cutoff_time = time() - (7 * 24 * 60 * 60); // 7 dias atrás
        $cleaned_webhooks = array();
        $removed_count = 0;
        
        foreach ($failed_webhooks as $webhook) {
            $created_time = strtotime($webhook['created_at']);
            
            // Mantém webhooks criados nos últimos 7 dias OU que ainda estão pendentes
            if ($created_time > $cutoff_time || $webhook['status'] === 'pending') {
                $cleaned_webhooks[] = $webhook;
            } else {
                $removed_count++;
            }
        }
        
        // Atualiza a opção apenas se houver mudanças
        if ($removed_count > 0) {
            update_option($this->failed_webhooks_option, $cleaned_webhooks);
            $this->log("Limpeza concluída: {$removed_count} webhooks antigos removidos de {$original_count} total");
        } else {
            $this->log("Nenhum webhook antigo encontrado para remoção");
        }
        
        // Gera relatório de status
        $this->generate_cleanup_report($cleaned_webhooks);
    }

    /**
     * Gera relatório de status dos webhooks
     */
    private function generate_cleanup_report($webhooks) {
        $stats = array(
            'total' => count($webhooks),
            'pending' => 0,
            'completed' => 0,
            'failed' => 0,
            'by_group' => array('drv' => 0, 'seu_souza' => 0)
        );
        
        foreach ($webhooks as $webhook) {
            if (isset($webhook['status'])) {
                $stats[$webhook['status']]++;
            }
            
            if (isset($webhook['data']['grupo'])) {
                $grupo = $webhook['data']['grupo'];
                if (isset($stats['by_group'][$grupo])) {
                    $stats['by_group'][$grupo]++;
                }
            }
        }
        
        $this->log("Relatório pós-limpeza: Total={$stats['total']}, Pendentes={$stats['pending']}, " .
                  "Completados={$stats['completed']}, Falharam={$stats['failed']}, " .
                  "DRV={$stats['by_group']['drv']}, Seu Souza={$stats['by_group']['seu_souza']}");
    }

    /**
     * Limpeza manual (pode ser chamada da administração)
     */
    public function manual_cleanup($days_to_keep = 7) {
        $this->log("=== Iniciando limpeza manual de webhooks (manter {$days_to_keep} dias) ===");
        
        $failed_webhooks = get_option($this->failed_webhooks_option, array());
        $original_count = count($failed_webhooks);
        
        if ($original_count === 0) {
            return array('removed' => 0, 'remaining' => 0);
        }
        
        $cutoff_time = time() - ($days_to_keep * 24 * 60 * 60);
        $cleaned_webhooks = array();
        $removed_count = 0;
        
        foreach ($failed_webhooks as $webhook) {
            $created_time = strtotime($webhook['created_at']);
            
            // Mantém webhooks recentes OU pendentes (independente da data)
            if ($created_time > $cutoff_time || $webhook['status'] === 'pending') {
                $cleaned_webhooks[] = $webhook;
            } else {
                $removed_count++;
            }
        }
        
        update_option($this->failed_webhooks_option, $cleaned_webhooks);
        $this->log("Limpeza manual concluída: {$removed_count} webhooks removidos");
        
        return array(
            'removed' => $removed_count,
            'remaining' => count($cleaned_webhooks)
        );
    }

    /**
     * Remove webhooks por status específico
     */
    public function cleanup_by_status($status_to_remove = 'completed') {
        $this->log("=== Limpando webhooks com status: {$status_to_remove} ===");
        
        $failed_webhooks = get_option($this->failed_webhooks_option, array());
        $original_count = count($failed_webhooks);
        
        $cleaned_webhooks = array_filter($failed_webhooks, function($webhook) use ($status_to_remove) {
            return $webhook['status'] !== $status_to_remove;
        });
        
        $removed_count = $original_count - count($cleaned_webhooks);
        
        if ($removed_count > 0) {
            update_option($this->failed_webhooks_option, $cleaned_webhooks);
            $this->log("Removidos {$removed_count} webhooks com status '{$status_to_remove}'");
        }
        
        return array(
            'removed' => $removed_count,
            'remaining' => count($cleaned_webhooks)
        );
    }

    /**
     * Obtém estatísticas detalhadas dos webhooks
     */
    public function get_detailed_stats() {
        $failed_webhooks = get_option($this->failed_webhooks_option, array());
        
        $stats = array(
            'total' => count($failed_webhooks),
            'by_status' => array('pending' => 0, 'completed' => 0, 'failed' => 0),
            'by_group' => array('drv' => 0, 'seu_souza' => 0),
            'by_age' => array(
                'last_24h' => 0,
                'last_week' => 0,
                'older' => 0
            ),
            'total_attempts' => 0,
            'oldest_webhook' => null,
            'newest_webhook' => null
        );
        
        $now = time();
        $oldest_time = $now;
        $newest_time = 0;
        
        foreach ($failed_webhooks as $webhook) {
            // Status
            if (isset($webhook['status'])) {
                $stats['by_status'][$webhook['status']]++;
            }
            
            // Grupo
            if (isset($webhook['data']['grupo'])) {
                $grupo = $webhook['data']['grupo'];
                if (isset($stats['by_group'][$grupo])) {
                    $stats['by_group'][$grupo]++;
                }
            }
            
            // Idade
            $created_time = strtotime($webhook['created_at']);
            $age_hours = ($now - $created_time) / 3600;
            
            if ($age_hours <= 24) {
                $stats['by_age']['last_24h']++;
            } elseif ($age_hours <= 168) { // 7 dias
                $stats['by_age']['last_week']++;
            } else {
                $stats['by_age']['older']++;
            }
            
            // Tentativas
            if (isset($webhook['attempts'])) {
                $stats['total_attempts'] += $webhook['attempts'];
            }
            
            // Mais antigo e mais novo
            if ($created_time < $oldest_time) {
                $oldest_time = $created_time;
                $stats['oldest_webhook'] = $webhook['created_at'];
            }
            if ($created_time > $newest_time) {
                $newest_time = $created_time;
                $stats['newest_webhook'] = $webhook['created_at'];
            }
        }
        
        return $stats;
    }

    /**
     * Desativa os cron jobs quando o plugin é desativado
     */
    public function deactivate_cleanup() {
        wp_clear_scheduled_hook('formulario_hapvida_daily_cleanup');
        wp_clear_scheduled_hook('formulario_hapvida_retry_webhooks');
    }

    /**
     * Log simplificado
     */
    private function log($message) {
    // *** CORREÇÃO: Usar sempre o mesmo timezone ***
    $timezone = new DateTimeZone('America/Fortaleza');
    $timestamp = new DateTime('now', $timezone);
    $log_entry = "[" . $timestamp->format('Y-m-d H:i:s') . "] [CLEANUP] {$message}" . PHP_EOL;
    
    error_log($log_entry, 3, $this->log_file);
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("HAPVIDA CLEANUP: " . $message);
    }
}

    /**
     * Método público para acessar estatísticas (usado na administração)
     */
    public function get_stats_for_admin() {
        $stats = $this->get_detailed_stats();
        
        return array(
            'total_webhooks' => $stats['total'],
            'pending_webhooks' => $stats['by_status']['pending'],
            'completed_webhooks' => $stats['by_status']['completed'],
            'failed_webhooks' => $stats['by_status']['failed'],
            'drv_webhooks' => $stats['by_group']['drv'],
            'seu_souza_webhooks' => $stats['by_group']['seu_souza'],
            'recent_webhooks' => $stats['by_age']['last_24h'],
            'total_attempts' => $stats['total_attempts'],
            'oldest_webhook' => $stats['oldest_webhook'],
            'newest_webhook' => $stats['newest_webhook']
        );
    }
}

// Instancia a classe de limpeza
$formulario_hapvida_cleanup = new Formulario_Hapvida_Webhook_Cleanup();