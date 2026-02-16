<?php
/**
 * Sistema de Rastreamento e Confirmação de Leads - VERSÃO COMPLETA CORRIGIDA
 * Arquivo: lead-tracking.php
 * Versão: 2.1 - Todos os métodos implementados
 */

// Impede acesso direto ao arquivo
if (!defined('ABSPATH')) {
    exit;
}

class Formulario_Hapvida_Lead_Tracking {
    
    private $table_name;
    private $confirmation_timeout = 10; // 10 minutos
    private $max_redistributions = 3;
    private $log_file;
    private $horario_inicio = '07:00';
    private $horario_fim = '23:00';  
    private $timezone = 'America/Sao_Paulo';
    
    private $enable_redistributions = true;
    private $timeout_weekdays = 10;
    private $timeout_weekends = 30;
    
    
    private function debug_log($message) {
        // Só loga se debug estiver explicitamente ativo
        if (defined('HAPVIDA_DEBUG_LOGS') && HAPVIDA_DEBUG_LOGS) {
            $this->log($message);
        }
}
    

// Substituir a função track_vendor_activity() no arquivo lead-tracking.php

private function track_vendor_activity($vendedor_nome, $grupo, $lead_id = null) {
    $activity_option = 'formulario_hapvida_vendor_activity';
    $activities = get_option($activity_option, array());
    
    $vendor_key = sanitize_key($grupo . '_' . $vendedor_nome);
    $today = current_time('Y-m-d');
    
    // Inicialização: Garante estrutura correta
    if (!isset($activities[$vendor_key])) {
        $activities[$vendor_key] = array(
            'nome' => $vendedor_nome,
            'grupo' => $grupo,
            'daily_stats' => array(),
            'total_stats' => array(
                'total_recebidos' => 0,
                'total_confirmados' => 0,
                'total_expirados' => 0
            )
        );
    }
    
    if (!isset($activities[$vendor_key]['daily_stats'][$today])) {
        $activities[$vendor_key]['daily_stats'][$today] = array(
            'total_recebidos' => 0,
            'total_confirmados' => 0,
            'total_expirados' => 0,
            'leads_confirmados' => array(),
            'leads_recebidos' => array()
        );
    }
    
    // Registro: Adiciona confirmação
    $activities[$vendor_key]['daily_stats'][$today]['total_confirmados']++;
    $activities[$vendor_key]['total_stats']['total_confirmados']++;
    
    // Registra ID do lead confirmado se fornecido
    if ($lead_id) {
        if (!isset($activities[$vendor_key]['daily_stats'][$today]['leads_confirmados'])) {
            $activities[$vendor_key]['daily_stats'][$today]['leads_confirmados'] = array();
        }
        
        $activities[$vendor_key]['daily_stats'][$today]['leads_confirmados'][] = array(
            'lead_id' => $lead_id,
            'confirmado_em' => current_time('H:i:s'),
            'timestamp' => current_time('mysql')
        );
    }
    
    // Salva dados atualizados
    update_option($activity_option, $activities);
}

private function track_vendor_expiration($vendedor_nome, $grupo, $lead_id) {
    $activity_option = 'formulario_hapvida_vendor_activity';
    $activities = get_option($activity_option, array());
    
    $vendor_key = sanitize_key($grupo . '_' . $vendedor_nome);
    $today = current_time('Y-m-d');
    
    // *** INICIALIZAÇÃO: Garante estrutura correta ***
    if (!isset($activities[$vendor_key])) {
        $activities[$vendor_key] = array(
            'nome' => $vendedor_nome,
            'grupo' => $grupo,
            'daily_stats' => array(),
            'total_stats' => array(
                'total_recebidos' => 0,
                'total_confirmados' => 0,
                'total_expirados' => 0
            )
        );
    }
    
    if (!isset($activities[$vendor_key]['daily_stats'][$today])) {
        $activities[$vendor_key]['daily_stats'][$today] = array(
            'total_recebidos' => 0,
            'total_confirmados' => 0,
            'total_expirados' => 0,
            'leads_expirados' => array()
        );
    }
    
    // *** REGISTRO: Adiciona expiração ***
    $activities[$vendor_key]['daily_stats'][$today]['total_expirados']++;
    $activities[$vendor_key]['total_stats']['total_expirados']++;
    
    // *** NOVO: Registra ID do lead expirado ***
    if (!isset($activities[$vendor_key]['daily_stats'][$today]['leads_expirados'])) {
        $activities[$vendor_key]['daily_stats'][$today]['leads_expirados'] = array();
    }
    
    $activities[$vendor_key]['daily_stats'][$today]['leads_expirados'][] = array(
        'lead_id' => $lead_id,
        'expirado_em' => current_time('H:i:s'),
        'timestamp' => current_time('mysql')
    );
    
    // *** SALVA: Dados atualizados ***
    $save_result = update_option($activity_option, $activities);
    
    $this->log("🔧 DEBUG: Salvamento atividade expiração - " . ($save_result ? 'SUCESSO' : 'FALHA'));
    
    $this->log("⏰ EXPIRAÇÃO registrada: {$vendedor_nome} ({$grupo}) - Lead {$lead_id} expirado. Total expirados hoje: {$activities[$vendor_key]['daily_stats'][$today]['total_expirados']}");
}


public function count_all_leads() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'hapvida_lead_tracking';
    
    // Verifica se a tabela existe
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    
    if (!$table_exists) {
        return 0;
    }
    
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    
    return $count ? intval($count) : 0;
}


public function count_expired_leads() {
    global $wpdb;
    
    // Garante que table_name está definido
    $this->ensure_table_name();
    
    if (empty($this->table_name)) {
        $this->log("❌ ERRO: table_name vazio ao contar leads expirados");
        return 0;
    }
    
    // Conta leads que não estão aguardando confirmação
    $count = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$this->table_name} 
         WHERE status IN ('confirmado', 'expirado', 'falha_definitiva', 'redistribuido')"
    );
    
    if ($count === null) {
        $this->log("❌ ERRO ao contar leads expirados: " . $wpdb->last_error);
        return 0;
    }
    
    return (int) $count;
}

/**
 * Exclui todos os leads expirados/finalizados do sistema
 * Mantém apenas os leads que estão aguardando confirmação
 * @return array Resultado da operação
 */
public function delete_expired_leads() {
    global $wpdb;
    
    // Garante que table_name está definido
    $this->ensure_table_name();
    
    if (empty($this->table_name)) {
        $this->log("❌ ERRO: table_name vazio ao excluir leads expirados");
        return array(
            'success' => false,
            'message' => 'Erro interno: tabela não encontrada',
            'deleted_count' => 0
        );
    }
    
    $this->log("🗑️ === INICIANDO EXCLUSÃO DE LEADS EXPIRADOS ===");
    
    // Conta antes da exclusão
    $total_before = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
    $expired_before = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$this->table_name} 
         WHERE status IN ('confirmado', 'expirado', 'falha_definitiva', 'redistribuido')"
    );
    $pending_before = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'aguardando'"
    );
    
    $this->log("📊 Contagem antes: Total={$total_before}, Expirados={$expired_before}, Aguardando={$pending_before}");
    
    if ($expired_before == 0) {
        $this->log("ℹ️ Não há leads expirados para excluir");
        return array(
            'success' => true,
            'message' => 'Não há leads expirados para excluir',
            'deleted_count' => 0,
            'remaining_count' => $total_before,
            'pending_count' => $pending_before
        );
    }
    
    // Executa a exclusão
    $result = $wpdb->query(
        "DELETE FROM {$this->table_name} 
         WHERE status IN ('confirmado', 'expirado', 'falha_definitiva', 'redistribuido')"
    );
    
    if ($result === false) {
        $this->log("❌ ERRO ao excluir leads: " . $wpdb->last_error);
        return array(
            'success' => false,
            'message' => 'Erro ao executar exclusão: ' . $wpdb->last_error,
            'deleted_count' => 0
        );
    }
    
    // Conta após a exclusão
    $total_after = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
    $pending_after = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'aguardando'"
    );
    
    $deleted_count = $total_before - $total_after;
    
    $this->log("📊 Contagem depois: Total={$total_after}, Aguardando={$pending_after}");
    $this->log("✅ Leads excluídos: {$deleted_count}");
    
    // Limpa atividades relacionadas
    $this->cleanup_vendor_activities();
    
    $this->log("🗑️ === EXCLUSÃO DE LEADS EXPIRADOS CONCLUÍDA ===");
    
    return array(
        'success' => true,
        'message' => "Exclusão concluída: {$deleted_count} leads removidos",
        'deleted_count' => $deleted_count,
        'remaining_count' => $total_after,
        'pending_count' => $pending_after,
        'details' => array(
            'before_total' => $total_before,
            'before_expired' => $expired_before,
            'before_pending' => $pending_before,
            'after_total' => $total_after,
            'after_pending' => $pending_after
        )
    );
}

/**
 * *** FUNÇÃO ATUALIZADA: cleanup_vendor_activities() ***
 * ARQUIVO: lead-tracking.php
 * LOCALIZAÇÃO: Dentro da classe Formulario_Hapvida_Lead_Tracking
 * DESCRIÇÃO: Limpa automaticamente estatísticas antigas (mais de 7 dias)
 */
public function cleanup_vendor_activities() {
    $this->log("🧹 === LIMPEZA AUTOMÁTICA DE ESTATÍSTICAS ===");
    
    $activity_option = 'formulario_hapvida_vendor_activity';
    $activities = get_option($activity_option, array());
    
    if (empty($activities)) {
        $this->log("ℹ️ Não há atividades para limpar");
        return;
    }
    
    // Define períodos de retenção
    $keep_days = 7; // Mantém estatísticas dos últimos 7 dias
    $cutoff_date = date('Y-m-d', strtotime("-{$keep_days} days"));
    $today = current_time('Y-m-d');
    
    $cleaned_count = 0;
    $stats_before = count($activities);
    
    foreach ($activities as $vendor_key => &$vendor_data) {
        // Limpa estatísticas diárias antigas
        if (isset($vendor_data['daily_stats']) && is_array($vendor_data['daily_stats'])) {
            foreach ($vendor_data['daily_stats'] as $date => $stats) {
                if ($date < $cutoff_date) {
                    unset($vendor_data['daily_stats'][$date]);
                    $cleaned_count++;
                    $this->log("🗑️ Removendo estatísticas de {$vendor_data['nome']} do dia {$date}");
                }
            }
            
            // Se não há mais estatísticas diárias, remove o vendedor completamente
            if (empty($vendor_data['daily_stats'])) {
                unset($activities[$vendor_key]);
                $this->log("🗑️ Removendo vendedor {$vendor_data['nome']} (sem atividade recente)");
            }
        }
        
        // Recalcula totais baseado apenas nos últimos 7 dias
        if (isset($vendor_data['daily_stats'])) {
            $new_totals = array(
                'total_recebidos' => 0,
                'total_confirmados' => 0,
                'total_expirados' => 0
            );
            
            foreach ($vendor_data['daily_stats'] as $date => $daily) {
                $new_totals['total_recebidos'] += isset($daily['total_recebidos']) ? $daily['total_recebidos'] : 0;
                $new_totals['total_confirmados'] += isset($daily['total_confirmados']) ? $daily['total_confirmados'] : 0;
                $new_totals['total_expirados'] += isset($daily['total_expirados']) ? $daily['total_expirados'] : 0;
            }
            
            $vendor_data['total_stats'] = $new_totals;
            
            // Atualiza taxa de confirmação
            if ($new_totals['total_recebidos'] > 0) {
                $vendor_data['confirmation_rate'] = round(($new_totals['total_confirmados'] / $new_totals['total_recebidos']) * 100, 1);
            } else {
                $vendor_data['confirmation_rate'] = 0;
            }
        }
    }
    
    // Salva dados limpos
    if ($cleaned_count > 0 || count($activities) < $stats_before) {
        update_option($activity_option, $activities);
        $this->log("✅ Limpeza concluída: {$cleaned_count} registros antigos removidos");
        $this->log("📊 Estatísticas mantidas: últimos {$keep_days} dias");
        $this->log("📊 Vendedores ativos: " . count($activities));
    } else {
        $this->log("ℹ️ Nenhuma estatística antiga para limpar");
    }
    
    // Agenda próxima limpeza
    if (!wp_next_scheduled('hapvida_daily_cleanup')) {
        wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'hapvida_daily_cleanup');
    }
}

/**
 * *** NOVA FUNÇÃO: run_daily_cleanup() ***
 * ARQUIVO: lead-tracking.php
 * LOCALIZAÇÃO: Dentro da classe Formulario_Hapvida_Lead_Tracking
 * DESCRIÇÃO: Hook para limpeza diária automática
 * ADICIONAR NO CONSTRUTOR: add_action('hapvida_daily_cleanup', array($this, 'run_daily_cleanup'));
 */
public function run_daily_cleanup() {
    $this->log("🔄 === EXECUTANDO LIMPEZA DIÁRIA AUTOMÁTICA ===");
    
    // Limpa estatísticas antigas dos vendedores
    $this->cleanup_vendor_activities();
    
    // Limpa leads muito antigos (opcional - mais de 30 dias)
    global $wpdb;
    $days_to_keep_leads = 30;
    $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_to_keep_leads} days"));
    
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$this->table_name} 
         WHERE created_at < %s 
         AND status IN ('confirmado', 'falha_definitiva')",
        $cutoff_date
    ));
    
    if ($deleted > 0) {
        $this->log("🗑️ {$deleted} leads antigos removidos (mais de {$days_to_keep_leads} dias)");
    }
    
    $this->log("✅ Limpeza diária concluída");
}

private function get_active_vendors($grupo, $hours_threshold = 1) {
    $activity_option = 'formulario_hapvida_vendor_activity';
    $activities = get_option($activity_option, array());
    
    $current_time = current_time('timestamp');
    $threshold_time = $current_time - ($hours_threshold * 3600); // 1 hora = 3600 segundos
    
    $active_vendors = array();
    
    // Busca vendedores do grupo específico nas atividades
    foreach ($activities as $vendor_key => $activity) {
        if ($activity['grupo'] === $grupo && 
            $activity['last_confirmation'] >= $threshold_time) {
            
            $active_vendors[] = array(
                'nome' => $activity['nome'],
                'grupo' => $activity['grupo'],
                'last_confirmation' => $activity['last_confirmation'],
                'confirmations_count' => $activity['confirmations_count'],
                'minutes_ago' => floor(($current_time - $activity['last_confirmation']) / 60)
            );
        }
    }
    
    // Ordena por última confirmação (mais recente primeiro)
    usort($active_vendors, function($a, $b) {
        return $b['last_confirmation'] - $a['last_confirmation'];
    });
    
    return $active_vendors;
}

private function get_next_active_vendor($grupo, $vendedor_atual_nome) {
    // 1. Busca vendedores com melhor performance hoje
    $vendor_performance = $this->get_vendor_performance_today($grupo);
    
    if (!empty($vendor_performance)) {
        // Filtra para não redistribuir para o mesmo vendedor
        $available_vendors = array_filter($vendor_performance, function($vendor) use ($vendedor_atual_nome) {
            return $vendor['nome'] !== $vendedor_atual_nome;
        });
        
        if (!empty($available_vendors)) {
            // Escolhe o vendedor com melhor performance:
            // 1. Maior taxa de confirmação
            // 2. Menos leads expirados
            // 3. Mais confirmações no total
            usort($available_vendors, function($a, $b) {
                // Prioridade 1: Taxa de confirmação
                if ($a['taxa_confirmacao'] != $b['taxa_confirmacao']) {
                    return $b['taxa_confirmacao'] <=> $a['taxa_confirmacao'];
                }
                // Prioridade 2: Menos expirados
                if ($a['expirados'] != $b['expirados']) {
                    return $a['expirados'] <=> $b['expirados'];
                }
                // Prioridade 3: Mais confirmações
                return $b['confirmados'] <=> $a['confirmados'];
            });
            
            $chosen_vendor_name = $available_vendors[0]['nome'];
            
            // Busca dados completos do vendedor na lista principal
            return $this->get_vendor_complete_data($chosen_vendor_name, $grupo);
        }
    }
    
    // 2. Fallback: Busca vendedores ativos nas últimas 1 hora
    $active_vendors = $this->get_active_vendors($grupo, 1);
    
    if (!empty($active_vendors)) {
        $available_vendors = array_filter($active_vendors, function($vendor) use ($vendedor_atual_nome) {
            return $vendor['nome'] !== $vendedor_atual_nome;
        });
        
        if (!empty($available_vendors)) {
            $chosen_vendor_name = $available_vendors[0]['nome'];
            return $this->get_vendor_complete_data($chosen_vendor_name, $grupo);
        }
    }
    
    // 3. Fallback: Busca vendedores ativos nas últimas 6 horas
    $active_vendors_6h = $this->get_active_vendors($grupo, 6);
    
    if (!empty($active_vendors_6h)) {
        $available_vendors = array_filter($active_vendors_6h, function($vendor) use ($vendedor_atual_nome) {
            return $vendor['nome'] !== $vendedor_atual_nome;
        });
        
        if (!empty($available_vendors)) {
            $chosen_vendor_name = $available_vendors[0]['nome'];
            return $this->get_vendor_complete_data($chosen_vendor_name, $grupo);
        }
    }
    
    // 4. Último fallback: Usa lógica original (próximo na fila)
    return $this->get_next_vendor_same_group_original($grupo, $vendedor_atual_nome);
}

// Substituir a função get_vendor_performance_today() no arquivo lead-tracking.php

private function get_vendor_performance_today($grupo) {
    global $wpdb;
    
    $today = current_time('Y-m-d');
    
    // Busca vendedores do grupo específico
    $vendedores_option = get_option('formulario_hapvida_vendedores', array());
    
    if (!isset($vendedores_option[$grupo])) {
        return array();
    }
    
    $performance_data = array();
    
    foreach ($vendedores_option[$grupo] as $vendedor) {
        if (!isset($vendedor['status']) || $vendedor['status'] === 'ativo') {
            $vendor_name = $vendedor['nome'];
            
            // Leads confirmados hoje
            $confirmados = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} 
                 WHERE DATE(confirmado_em) = %s 
                 AND status = 'confirmado'
                 AND JSON_EXTRACT(vendedor_atual, '$.nome') = %s",
                $today,
                $vendor_name
            ));
            
            // Leads expirados hoje
            $expirados = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} 
                 WHERE DATE(expira_em) = %s 
                 AND status IN ('redistribuido', 'falha_definitiva')
                 AND JSON_EXTRACT(vendedor_atual, '$.nome') = %s",
                $today,
                $vendor_name
            ));
            
            // Total recebidos hoje
            $total_recebidos = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} 
                 WHERE DATE(enviado_em) = %s 
                 AND JSON_EXTRACT(vendedor_atual, '$.nome') = %s",
                $today,
                $vendor_name
            ));
            
            // Calcula taxa de confirmação
            $taxa_confirmacao = $total_recebidos > 0 ? round(($confirmados / $total_recebidos) * 100, 1) : 0;
            
            // Só inclui vendedores com atividade hoje ou com taxa > 0
            if ($total_recebidos > 0 || $taxa_confirmacao > 0) {
                $performance_data[] = array(
                    'nome' => $vendor_name,
                    'grupo' => $grupo,
                    'confirmados' => (int)$confirmados,
                    'expirados' => (int)$expirados,
                    'total_recebidos' => (int)$total_recebidos,
                    'taxa_confirmacao' => $taxa_confirmacao
                );
            }
        }
    }
    
    return $performance_data;
}

private function get_vendor_complete_data($vendedor_nome, $grupo) {
    $vendedores_option = get_option('formulario_hapvida_vendedores', array());
    
    if (!isset($vendedores_option[$grupo])) {
        return false;
    }
    
    foreach ($vendedores_option[$grupo] as $vendedor) {
        if ($vendedor['nome'] === $vendedor_nome && 
            (!isset($vendedor['status']) || $vendedor['status'] === 'ativo')) {
            
            // Adiciona informações necessárias
            $vendedor['grupo'] = $grupo;
            $vendedor['whatsapp'] = isset($vendedor['whatsapp']) ? 
                                   $vendedor['whatsapp'] : 
                                   'https://wa.me/' . preg_replace('/[^0-9]/', '', $vendedor['telefone']);
            
            $this->log("📋 Dados completos encontrados: {$vendedor_nome} ({$grupo})");
            return $vendedor;
        }
    }
    
    $this->log("❌ Vendedor {$vendedor_nome} não encontrado ou inativo no grupo {$grupo}");
    return false;
}

/**
 * Limpa registros de atividade antigos (executa a cada hora)
 */
public function cleanup_vendor_activity() {
    $activity_option = 'formulario_hapvida_vendor_activity';
    $activities = get_option($activity_option, array());
    
    $current_time = current_time('timestamp');
    $cleanup_threshold = $current_time - (24 * 3600); // Remove atividades > 24 horas
    
    $cleaned_activities = array();
    $removed_count = 0;
    
    foreach ($activities as $vendor_key => $activity) {
        if ($activity['last_confirmation'] >= $cleanup_threshold) {
            $cleaned_activities[$vendor_key] = $activity;
        } else {
            $removed_count++;
        }
    }
    
    update_option($activity_option, $cleaned_activities);
    
    $this->log("🧹 Limpeza de atividades: {$removed_count} registros antigos removidos, " . count($cleaned_activities) . " mantidos");
}

/**
 * Lógica original de redistribuição (mantida como fallback)
 */
private function get_next_vendor_same_group_original($grupo, $vendedor_atual_nome) {
    $this->log("🔄 FALLBACK: Usando lógica original de redistribuição para grupo: {$grupo}");
    
    // [Todo o código original da função get_next_vendor_same_group, só renomeada]
    $vendedores_option = get_option('formulario_hapvida_vendedores', array());
    
    if (!isset($vendedores_option[$grupo])) {
        $this->log("❌ ERRO: Grupo {$grupo} não encontrado na lista de vendedores");
        return false;
    }
    
    $vendedores_grupo = $vendedores_option[$grupo];
    
    if ($grupo === 'drv') {
        $vendedores_fixos = array_filter($vendedores_grupo, function($v) {
            return (isset($v['categoria']) && $v['categoria'] === 'fixo' && 
                   (!isset($v['status']) || $v['status'] === 'ativo'));
        });
        $vendedores_rotativos = array_filter($vendedores_grupo, function($v) {
            return (isset($v['categoria']) && $v['categoria'] === 'rotativo' && 
                   (!isset($v['status']) || $v['status'] === 'ativo'));
        });

        if (count($vendedores_rotativos) === 0) {
            $vendedores_selecionados = $vendedores_fixos;
        } else {
            $vendedores_selecionados = array_merge($vendedores_fixos, $vendedores_rotativos);
        }
        
        $vendedores_selecionados = array_values($vendedores_selecionados);
        
    } else {
        $vendedores_selecionados = array_filter($vendedores_grupo, function($v) {
            return (!isset($v['status']) || $v['status'] === 'ativo');
        });
        $vendedores_selecionados = array_values($vendedores_selecionados);
    }
    
    if (empty($vendedores_selecionados)) {
        $this->log("❌ ERRO: Nenhum vendedor ativo encontrado no grupo {$grupo}");
        return false;
    }
    
    $ultimo_vendedor_info = get_option('formulario_hapvida_ultimo_vendedor_info', array(
        'group' => '',
        'indices' => array('drv' => -1, 'seu_souza' => -1),
    ));
    
    $posicao_atual = -1;
    for ($i = 0; $i < count($vendedores_selecionados); $i++) {
        if ($vendedores_selecionados[$i]['nome'] === $vendedor_atual_nome) {
            $posicao_atual = $i;
            break;
        }
    }
    
    if ($posicao_atual === -1) {
        $grupo_key = ($grupo === 'drv') ? 'drv' : 'seu_souza';
        $posicao_atual = isset($ultimo_vendedor_info['indices'][$grupo_key]) ? 
                        $ultimo_vendedor_info['indices'][$grupo_key] : -1;
    }
    
    $proxima_posicao = ($posicao_atual + 1) % count($vendedores_selecionados);
    
    if ($vendedores_selecionados[$proxima_posicao]['nome'] === $vendedor_atual_nome) {
        $proxima_posicao = ($proxima_posicao + 1) % count($vendedores_selecionados);
    }
    
    if (count($vendedores_selecionados) === 1) {
        $this->log("❌ AVISO: Apenas um vendedor ativo no grupo {$grupo}, não é possível redistribuir");
        return false;
    }
    
    $proximo_vendedor = $vendedores_selecionados[$proxima_posicao];
    $proximo_vendedor['grupo'] = $grupo;
    $proximo_vendedor['whatsapp'] = isset($proximo_vendedor['whatsapp']) ? 
                                   $proximo_vendedor['whatsapp'] : 
                                   'https://wa.me/' . preg_replace('/[^0-9]/', '', $proximo_vendedor['telefone']);
    
    $this->log("🔄 FALLBACK redistribuição: {$vendedor_atual_nome} → {$proximo_vendedor['nome']}");
    
    return $proximo_vendedor;
}
    
/**
 * *** FUNÇÃO CORRIGIDA: is_horario_comercial() ***
 * ARQUIVO: lead-tracking.php
 * LOCALIZAÇÃO: Dentro da classe Formulario_Hapvida_Lead_Tracking
 * DESCRIÇÃO: Corrige a verificação de horário comercial com timezone correto
 */
public function is_horario_comercial() {
    try {
        // *** CORREÇÃO: Usa timezone configurado, com fallback para Fortaleza ***
        $timezone_string = $this->timezone ?? 'America/Fortaleza';
        $timezone = new DateTimeZone($timezone_string);
        $agora = new DateTime('now', $timezone);
        
        $hora_atual = $agora->format('H:i');
        $dia_semana = $agora->format('N'); // 1=segunda, 2=terça, ..., 6=sábado, 7=domingo
        
        // Log para debug
        $this->log("🕐 Verificando horário comercial: {$hora_atual} - Dia: {$dia_semana} - Timezone: {$timezone_string}");
        $this->log("🕐 Configuração: {$this->horario_inicio} às {$this->horario_fim}");
        
        // *** VERIFICA SE É FIM DE SEMANA ***
        if ($dia_semana == 6 || $dia_semana == 7) {
            $nome_dia = ($dia_semana == 6) ? 'Sábado' : 'Domingo';
            
            // *** VERIFICA SE HÁ TIMEOUT CONFIGURADO PARA FINAIS DE SEMANA ***
            $options = get_option('formulario_hapvida_settings', array());
            $timeout_weekends = isset($options['redistribution_timeout_weekends']) ? intval($options['redistribution_timeout_weekends']) : 0;
            
            // Se há timeout configurado para finais de semana E está dentro do horário configurado
            if ($timeout_weekends > 0 && $hora_atual >= $this->horario_inicio && $hora_atual < $this->horario_fim) {
                $this->log("✅ DENTRO do horário comercial - {$nome_dia} COM configuração ativa (timeout: {$timeout_weekends}min)");
                return true;
            } else {
                $this->log("🌙 Fora do horário comercial - {$nome_dia} (timeout: {$timeout_weekends}min, horário: {$hora_atual})");
                return false;
            }
        }
        
        // *** Verifica horário para dias úteis (segunda a sexta) ***
        if ($hora_atual >= $this->horario_inicio && $hora_atual < $this->horario_fim) {
            $this->log("✅ Dentro do horário comercial ({$this->horario_inicio} às {$this->horario_fim}) - Dia útil {$dia_semana}");
            return true;
        } else {
            $this->log("🌙 Fora do horário comercial ({$this->horario_inicio} às {$this->horario_fim}) - Horário atual: {$hora_atual}");
            return false;
        }
        
    } catch (Exception $e) {
        $this->log("❌ Erro ao verificar horário: " . $e->getMessage());
        // Em caso de erro, assume horário comercial para não bloquear o sistema
        return true;
    }
}

public function set_horario_inicio($horario) {
    $this->horario_inicio = sanitize_text_field($horario);
    update_option('formulario_hapvida_horario_inicio', $this->horario_inicio);
    $this->log("⚙️ Horário de início atualizado: {$this->horario_inicio}");
}

/**
 * Define horário de fim
 */
public function set_horario_fim($horario) {
    $this->horario_fim = sanitize_text_field($horario);
    update_option('formulario_hapvida_horario_fim', $this->horario_fim);
    $this->log("⚙️ Horário de fim atualizado: {$this->horario_fim}");
}

/**
 * Define timezone
 */
public function set_timezone($timezone) {
    $this->timezone = sanitize_text_field($timezone);
    update_option('formulario_hapvida_timezone', $this->timezone);
    $this->log("⚙️ Timezone atualizado: {$this->timezone}");
}

/**
 * *** FUNÇÃO CORRIGIDA: reload_settings() ***
 * ARQUIVO: lead-tracking.php
 * LOCALIZAÇÃO: Dentro da classe Formulario_Hapvida_Lead_Tracking
 * DESCRIÇÃO: Recarrega configurações do banco de dados
 */
public function reload_settings() {
    // *** CORREÇÃO 1: Recarrega horários das opções específicas ***
    $this->horario_inicio = get_option('formulario_hapvida_horario_inicio', '08:00');
    $this->horario_fim = get_option('formulario_hapvida_horario_fim', '18:00');
    $this->timezone = get_option('formulario_hapvida_timezone', 'America/Sao_Paulo');
    
    // *** CORREÇÃO 2: Recarrega timeouts da opção principal ***
    $options = get_option('formulario_hapvida_settings', array());
    $this->timeout_weekdays = isset($options['redistribution_timeout_weekdays']) ? 
        (int)$options['redistribution_timeout_weekdays'] : 10;
    $this->timeout_weekends = isset($options['redistribution_timeout_weekends']) ? 
        (int)$options['redistribution_timeout_weekends'] : 30;
    
    // *** CORREÇÃO 3: Recarrega configuração de redistribuições ***
    $this->enable_redistributions = isset($options['enable_redistributions']) ? 
        ($options['enable_redistributions'] === '1') : true;
    
    // *** CORREÇÃO 4: Atualiza timeout atual ***
    $this->confirmation_timeout = $this->get_dynamic_timeout();
    
   
}

private function load_settings() {
    // *** CORREÇÃO: Carrega das opções principais do formulário com nome correto ***
    $options = get_option('formulario_hapvida_settings', array());
    
    // *** CORREÇÃO CRÍTICA: Carrega horários das opções específicas (consistente com save_*) ***
    // Só carrega se ainda não foram definidos no construtor
    if (empty($this->horario_inicio)) {
        $this->horario_inicio = get_option('formulario_hapvida_horario_inicio', '08:00');
    }
    if (empty($this->horario_fim)) {
        $this->horario_fim = get_option('formulario_hapvida_horario_fim', '18:00'); 
    }
    if (empty($this->timezone)) {
        $this->timezone = get_option('formulario_hapvida_timezone', 'America/Sao_Paulo');
    }
    
    // *** DEBUG: Log dos valores carregados ***
    error_log("HAPVIDA DEBUG: load_settings() carregou - Início: {$this->horario_inicio}, Fim: {$this->horario_fim}");
    
    // *** CONFIGURAÇÕES DE TIMEOUT COM FALLBACKS SEGUROS ***
    $this->timeout_weekdays = isset($options['redistribution_timeout_weekdays']) ? 
        (int)$options['redistribution_timeout_weekdays'] : 10;
    $this->timeout_weekends = isset($options['redistribution_timeout_weekends']) ? 
        (int)$options['redistribution_timeout_weekends'] : 30;
    
    // *** CONFIGURAÇÕES DE REDISTRIBUIÇÃO COM VERIFICAÇÃO CORRETA ***
    $this->enable_redistributions = isset($options['enable_redistributions']) ? 
        ($options['enable_redistributions'] === '1' || $options['enable_redistributions'] === true) : true;
    
    // *** DEFINE O TIMEOUT ATUAL BASEADO NO HORÁRIO COMERCIAL ***
    $this->confirmation_timeout = $this->get_dynamic_timeout();
    
    $this->log("⚙️ Configurações carregadas (load_settings):");
    $this->log("   - Horário comercial: {$this->horario_inicio} às {$this->horario_fim}");
    $this->log("   - Timezone: {$this->timezone}");
    $this->log("   - Redistribuições: " . ($this->enable_redistributions ? 'HABILITADO' : 'DESABILITADO'));
    $this->log("   - Timeout horário comercial: {$this->timeout_weekdays} min");
    $this->log("   - Timeout fora do horário: {$this->timeout_weekends} min");
    $this->log("   - Timeout atual: {$this->confirmation_timeout} min");
}


public function get_business_hours_config() {
    $is_business_hours = $this->is_horario_comercial();
    
    return array(
        'ativo' => $is_business_hours,
        'inicio' => $this->horario_inicio,
        'fim' => $this->horario_fim,
        'timezone' => $this->timezone,
        'proximo' => $this->get_proximo_horario_comercial(),
        'timeout_atual' => $this->get_dynamic_timeout()
    );
}

    /**
 * *** ALTERAR ESTA FUNÇÃO: get_proximo_horario_comercial() ***
 * ARQUIVO: lead-tracking.php
 * LOCALIZAÇÃO: Dentro da classe Formulario_Hapvida_Lead_Tracking
 * DESCRIÇÃO: Agora pula fins de semana (sábado e domingo)
 */
private function get_proximo_horario_comercial() {
    try {
        $timezone = new DateTimeZone($this->timezone);
        $agora = new DateTime('now', $timezone);
        $dia_semana = $agora->format('N'); // 1=segunda, 6=sábado, 7=domingo
        $hora_atual = $agora->format('H:i');
        
        // *** Se for fim de semana (sábado ou domingo), vai para próxima segunda ***
        if ($dia_semana == 6 || $dia_semana == 7) {
            $dias_ate_segunda = (8 - $dia_semana) % 7;
            if ($dias_ate_segunda == 0) $dias_ate_segunda = 1; // Se domingo, vai para segunda
            
            $proximo = clone $agora;
            $proximo->add(new DateInterval('P' . $dias_ate_segunda . 'D'));
            $proximo->setTime(
                intval(substr($this->horario_inicio, 0, 2)),
                intval(substr($this->horario_inicio, 3, 2)),
                0
            );
            
            $nome_dia = ($dia_semana == 6) ? 'Sábado' : 'Domingo';
            $this->log("📅 {$nome_dia} detectado, próximo horário: segunda " . $proximo->format('d/m/Y H:i'));
            return $proximo->format('d/m/Y H:i:s');
        }
        
        // *** Se for dia útil mas já passou do horário, vai para próximo dia útil ***
        if ($hora_atual >= $this->horario_fim) {
            $proximo = clone $agora;
            
            // Se for sexta-feira, pula para segunda
            if ($dia_semana == 5) { // Sexta-feira
                $proximo->add(new DateInterval('P3D')); // Pula fim de semana
            } else {
                $proximo->add(new DateInterval('P1D')); // Próximo dia útil
            }
            
            $proximo->setTime(
                intval(substr($this->horario_inicio, 0, 2)),
                intval(substr($this->horario_inicio, 3, 2)),
                0
            );
            
            return $proximo->format('d/m/Y H:i:s');
        }
        
        // *** Se ainda não chegou no horário hoje (dia útil) ***
        if ($hora_atual < $this->horario_inicio) {
            $proximo = clone $agora;
            $proximo->setTime(
                intval(substr($this->horario_inicio, 0, 2)),
                intval(substr($this->horario_inicio, 3, 2)),
                0
            );
            
            return $proximo->format('d/m/Y H:i:s');
        }
        
        // Se está dentro do horário agora (dia útil)
        return 'Agora (horário comercial ativo)';
        
    } catch (Exception $e) {
        return 'Erro ao calcular';
    }
}

    /**
 * *** ALTERAR ESTA FUNÇÃO: extend_leads_for_business_hours() ***
 * ARQUIVO: lead-tracking.php
 * LOCALIZAÇÃO: Dentro da classe Formulario_Hapvida_Lead_Tracking
 * DESCRIÇÃO: Agora considera fins de semana ao calcular próximo horário comercial
 */
private function extend_leads_for_business_hours() {
    global $wpdb;
    
    // Busca leads que vão expirar durante a madrugada ou fins de semana
    $leads_para_estender = $wpdb->get_results(
        "SELECT * FROM {$this->table_name} 
         WHERE status = 'aguardando' 
         AND expira_em <= DATE_ADD(NOW(), INTERVAL 8 HOUR)",
        ARRAY_A
    );
    
    if (empty($leads_para_estender)) {
        return;
    }
    
    $this->log("🔄 Estendendo expiração de " . count($leads_para_estender) . " leads para próximo horário comercial");
    
    foreach ($leads_para_estender as $lead) {
        $timezone = new DateTimeZone($this->timezone);
        $agora = new DateTime('now', $timezone);
        $dia_semana = $agora->format('N'); // 1=segunda, 6=sábado, 7=domingo
        $hora_atual = $agora->format('H:i');
        
        // *** Calcula próximo horário comercial considerando fins de semana ***
        if ($dia_semana == 6 || $dia_semana == 7) {
            // Se for fim de semana, vai para próxima segunda
            $dias_ate_segunda = (8 - $dia_semana) % 7;
            if ($dias_ate_segunda == 0) $dias_ate_segunda = 1;
            
            $proximo_comercial = clone $agora;
            $proximo_comercial->add(new DateInterval('P' . $dias_ate_segunda . 'D'));
            $proximo_comercial->setTime(
                intval(substr($this->horario_inicio, 0, 2)),
                intval(substr($this->horario_inicio, 3, 2)) + 10, // +10min de timeout
                0
            );
        } elseif ($hora_atual < $this->horario_inicio) {
            // Dia útil, mas ainda não chegou no horário - usa hoje mesmo
            $proximo_comercial = clone $agora;
            $proximo_comercial->setTime(
                intval(substr($this->horario_inicio, 0, 2)),
                intval(substr($this->horario_inicio, 3, 2)) + 10,
                0
            );
        } else {
            // Dia útil, mas já passou do horário - vai para próximo dia útil
            $proximo_comercial = clone $agora;
            
            if ($dia_semana == 5) { // Se for sexta, pula fim de semana
                $proximo_comercial->add(new DateInterval('P3D'));
            } else {
                $proximo_comercial->add(new DateInterval('P1D'));
            }
            
            $proximo_comercial->setTime(
                intval(substr($this->horario_inicio, 0, 2)),
                intval(substr($this->horario_inicio, 3, 2)) + 10,
                0
            );
        }
        
        $nova_expiracao = $proximo_comercial->format('Y-m-d H:i:s');
        
        $wpdb->update(
            $this->table_name,
            array('expira_em' => $nova_expiracao),
            array('lead_id' => $lead['lead_id']),
            array('%s'),
            array('%s')
        );
        
        $this->log("⏰ Lead {$lead['lead_id']} estendido para {$nova_expiracao}");
    }
}

public function update_business_hours($inicio, $fim, $timezone = 'America/Sao_Paulo') {
    $this->horario_inicio = $inicio;
    $this->horario_fim = $fim;
    $this->timezone = $timezone;
    
    // *** CORREÇÃO: Salva nas opções corretas (mesmas que os métodos set_*) ***
    update_option('formulario_hapvida_horario_inicio', $inicio);
    update_option('formulario_hapvida_horario_fim', $fim);
    update_option('formulario_hapvida_timezone', $timezone);
    
    $this->log("⚙️ Horário comercial atualizado: {$inicio} às {$fim} ({$timezone})");
}
    
    private function ensure_table_name() {
    // *** CORREÇÃO: Só executa se realmente necessário ***
    if (!empty($this->table_name) && $this->table_name !== 'wp_hapvida_lead_tracking') {
        return; // Já está correto
    }
    
    global $wpdb;
    
    // Se table_name está vazio ou wpdb não está disponível
    if (empty($this->table_name) || !$wpdb || empty($wpdb->prefix)) {
        // Tenta novamente
        if ($wpdb && !empty($wpdb->prefix)) {
            $this->table_name = $wpdb->prefix . 'hapvida_lead_tracking';
            $this->log("🔧 Table name corrigido: " . $this->table_name);
        } else {
            // Fallback com prefix padrão
            $this->table_name = 'wp_hapvida_lead_tracking';
            $this->log("⚠️ Usando table name fallback: " . $this->table_name);
        }
    }
    
    // Valida se o nome está correto
    if (empty($this->table_name) || $this->table_name === '_hapvida_lead_tracking') {
        $this->log("❌ ERRO CRÍTICO: Table name ainda está incorreto: '" . $this->table_name . "'");
        
        // Força recriação
        if ($wpdb) {
            $this->table_name = $wpdb->prefix . 'hapvida_lead_tracking';
            $this->log("🔧 Table name forçado: " . $this->table_name);
        }
    }
}

    public function __construct() {
    
    // *** CORREÇÃO: Carrega das opções corretas (com 'formulario_hapvida_') ***
    $this->horario_inicio = get_option('formulario_hapvida_horario_inicio', '08:00');
    $this->horario_fim = get_option('formulario_hapvida_horario_fim', '18:00');
    $this->timezone = get_option('formulario_hapvida_timezone', 'America/Sao_Paulo');
    
    // *** DEBUG: Log dos valores carregados no construtor ***
    error_log("HAPVIDA DEBUG: Construtor carregou - Início: {$this->horario_inicio}, Fim: {$this->horario_fim}");
    
    // *** CARREGA CONFIGURAÇÕES COMPLETAS ANTES DE QUALQUER INICIALIZAÇÃO ***
    $this->load_settings();
    
    // *** CORREÇÃO: Evita duplicação de hooks ***
    $this->ensure_table_name();
    
    add_action('hapvida_daily_cleanup', array($this, 'run_daily_cleanup'));
    
    // *** NOVO: Agenda verificação de leads expirados ***
    if (!wp_next_scheduled('formulario_hapvida_check_expired_leads')) {
        wp_schedule_event(time(), 'five_minutes', 'formulario_hapvida_check_expired_leads');
    }
    add_action('formulario_hapvida_check_expired_leads', array($this, 'process_expired_leads'));
    
    // *** NOVO: Hook para limpeza diária automática ***
    add_action('hapvida_daily_cleanup', array($this, 'run_daily_cleanup'));
    
    // *** NOVO: Define intervalo customizado de 5 minutos ***
    add_filter('cron_schedules', array($this, 'add_cron_intervals'));
    
    // *** HOOKS ÚNICOS COM PROTEÇÃO EXTRA ***
    static $HOOKS_REGISTERED_GLOBAL = false;
    if (!$HOOKS_REGISTERED_GLOBAL) {
        $HOOKS_REGISTERED_GLOBAL = true;
        
        // Hooks essenciais
        add_action('init', array($this, 'init'));
        
        // *** CORREÇÃO: Registra endpoint apenas uma vez ***
        add_action('rest_api_init', array($this, 'register_confirmation_endpoint'), 10);
        
        add_action('formulario_hapvida_check_expired_leads', array($this, 'process_expired_leads'));
        
        // *** CORREÇÃO: REMOVIDO debug_cron_status que não existe ***
        // Método debug_cron_status não existe na classe, causando erro fatal
        
        register_activation_hook(__FILE__, array($this, 'create_tracking_table'));
        
    }
    
    // *** CRON CHECK ÚNICO COM DEBOUNCE ***
    static $CRON_CHECK_COMPLETE = false;
    if (!$CRON_CHECK_COMPLETE) {
        $CRON_CHECK_COMPLETE = true;
        
        // Agenda para mais tarde para evitar conflitos
        add_action('wp_loaded', array($this, 'ensure_cron_scheduled_once'), 30);
    }
    
    if (!wp_next_scheduled('formulario_hapvida_cleanup_activity')) {
        wp_schedule_event(time() + 3600, 'hourly', 'formulario_hapvida_cleanup_activity');
    }
    add_action('formulario_hapvida_cleanup_activity', array($this, 'cleanup_vendor_activity'));
    
    // Registra globalmente
    $GLOBALS['formulario_hapvida_lead_tracking'] = $this;
}

    public function init_table_name_later() {
    // *** PROTEÇÃO: Só executa uma vez ***
    static $executed = false;
    if ($executed) {
        return;
    }
    $executed = true;
    
    global $wpdb;
    if ($wpdb && !empty($wpdb->prefix)) {
        $old_name = $this->table_name;
        $this->table_name = $wpdb->prefix . 'hapvida_lead_tracking';
        if ($old_name !== $this->table_name) {
            $this->log("🔧 Table name atualizado: $old_name → " . $this->table_name);
        }
    }
}


public function add_cron_intervals($schedules) {
    $schedules['five_minutes'] = array(
        'interval' => 300,
        'display' => 'A cada 5 minutos'
    );
    return $schedules;
}


private function get_next_cleanup_time() {
    $next = wp_next_scheduled('hapvida_daily_cleanup');
    if ($next) {
        return date('d/m/Y H:i:s', $next);
    }
    return 'Não agendado';
}

    private function load_dynamic_settings() {
    $options = get_option('formulario_hapvida_settings', array());
    

    $this->enable_redistributions = isset($options['enable_redistributions']) ? 
        (bool)$options['enable_redistributions'] : true;
    $this->timeout_weekdays = isset($options['redistribution_timeout_weekdays']) ? 
        (int)$options['redistribution_timeout_weekdays'] : 10;
    $this->timeout_weekends = isset($options['redistribution_timeout_weekends']) ? 
        (int)$options['redistribution_timeout_weekends'] : 30;
    
    // *** RECALCULA O TIMEOUT DINÂMICO BASEADO NOS HORÁRIOS JÁ CARREGADOS ***
    $this->confirmation_timeout = $this->get_dynamic_timeout();
    
    // *** LOG APENAS DAS CONFIGURAÇÕES DINÂMICAS (NÃO DOS HORÁRIOS) ***
    $this->log("🔄 Configurações dinâmicas recarregadas (load_dynamic_settings):");
    $this->log("   - Redistribuições: " . ($this->enable_redistributions ? 'HABILITADO' : 'DESABILITADO'));
    $this->log("   - Timeout atual recalculado: {$this->confirmation_timeout} min");
}

    public function ensure_cron_scheduled_once() {
        static $already_checked = false;
        if ($already_checked) {
            return;
        }
        $already_checked = true;
        
        $next_cron = wp_next_scheduled('formulario_hapvida_check_expired_leads');
        if (!$next_cron) {
            $this->log("🔧 Cron perdido, reagendando...");
            $this->schedule_expired_check();
        } else {
            // *** REMOVIDO: Log apenas se necessário ***
            // Não loga mais o "Cron OK" para reduzir ruído
        }
    }

    private function log($message) {
        // *** FILTRO: Só logs essenciais em produção ***
        if (!defined('HAPVIDA_DEBUG_VERBOSE') || !HAPVIDA_DEBUG_VERBOSE) {
            $allowed_patterns = [
                'ERRO', 'ERROR', 'CRÍTICO', 'FALHA', 'FAILED',
                'Lead tracking criado', 'Lead confirmado', 'Lead redistribuído',
                'Webhook enviado', 'SUBMISSÃO CONCLUÍDA'
            ];
            
            $is_allowed = false;
            foreach ($allowed_patterns as $pattern) {
                if (stripos($message, $pattern) !== false) {
                    $is_allowed = true;
                    break;
                }
            }
            
            if (!$is_allowed) {
                return; // Não loga
            }
        }
        
        if (!$this->log_file) {
            return;
        }
        
        $timezone = new DateTimeZone('America/Fortaleza');
        $timestamp = new DateTime('now', $timezone);
        $log_entry = "[" . $timestamp->format('Y-m-d H:i:s') . "] {$message}" . PHP_EOL;
        
        error_log($log_entry, 3, $this->log_file);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("HAPVIDA LEAD TRACKING: " . $message);
        }
}
    
    public function ensure_cron_scheduled() {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        
        // Só agenda se não estiver agendado
        if (!wp_next_scheduled('formulario_hapvida_check_expired_leads')) {
            $this->log("🔧 Cron não encontrado, agendando...");
            $this->schedule_expired_check();
        }
    }

    /**
     * Inicialização do sistema
     */
    public function init() {
        // *** CORREÇÃO: Sempre garante table_name correto ***
        $this->ensure_table_name();
        
        // Verifica se a tabela existe, se não, cria (apenas uma vez)
        static $table_checked = false;
        if (!$table_checked) {
            $table_checked = true;
            
            if (!$this->table_exists()) {
                $this->log("Tabela não existe, criando...");
                $this->create_tracking_table();
            }
        }
    }
    
    
    private function table_exists() {
        global $wpdb;
        
        // *** CORREÇÃO: Garante table_name antes de verificar ***
        $this->ensure_table_name();
        
        if (empty($this->table_name)) {
            $this->log("❌ ERRO: table_name vazio ao verificar existência");
            return false;
        }
        
        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") == $this->table_name;
        $this->debug_log("🔍 Verificando tabela {$this->table_name}: " . ($exists ? 'EXISTE' : 'NÃO EXISTE'));
        
        return $exists;
}
    
public function create_tracking_table() {
    global $wpdb;
    
    // *** CORREÇÃO: Garante table_name antes de criar ***
    $this->ensure_table_name();
    
    if (empty($this->table_name)) {
        $this->log("❌ ERRO CRÍTICO: Não é possível criar tabela - table_name vazio");
        return false;
    }
    
    $this->log("🔧 Criando tabela: " . $this->table_name);
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE {$this->table_name} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_id VARCHAR(50) NOT NULL UNIQUE,
        form_data LONGTEXT NOT NULL,
        vendedor_atual LONGTEXT NOT NULL,
        vendedor_historico LONGTEXT,
        status ENUM('aguardando', 'confirmado', 'expirado', 'redistribuido', 'falha_definitiva') DEFAULT 'aguardando',
        enviado_em DATETIME NOT NULL,
        confirmado_em DATETIME NULL,
        expira_em DATETIME NOT NULL,
        tentativas INT DEFAULT 1,
        max_tentativas INT DEFAULT 3,
        token_confirmacao VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_lead_id (lead_id),
        INDEX idx_status (status),
        INDEX idx_expira_em (expira_em),
        INDEX idx_token (token_confirmacao)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $result = dbDelta($sql);
    
    // Verifica se foi criada
    if ($this->table_exists()) {
        $this->log("✅ Tabela de lead tracking criada/atualizada com sucesso: " . $this->table_name);
    } else {
        $this->log("❌ ERRO: Falha ao criar tabela de lead tracking: " . $this->table_name);
        
        // Debug adicional
        $this->log("SQL usado: " . $sql);
        $this->log("dbDelta result: " . print_r($result, true));
        $this->log("wpdb->last_error: " . $wpdb->last_error);
    }
    
    return $result;
}


public function schedule_expired_check() {
    // Remove múltiplos agendamentos para evitar conflitos
    wp_clear_scheduled_hook('formulario_hapvida_check_expired_leads');
    
    $this->log("📅 Agendando novo cron (versão estável)...");
    
    // Adiciona filtro apenas uma vez
    static $filter_added = false;
    if (!$filter_added) {
        $filter_added = true;
        add_filter('cron_schedules', array($this, 'add_custom_cron_interval'), 10, 1);
        $this->log("📅 Filtro de intervalo customizado adicionado");
    }
    
    // Agenda com delay para evitar conflitos (5 minutos a partir de agora)
    $next_time = time() + 300;
    $scheduled = wp_schedule_event($next_time, 'formulario_hapvida_5min', 'formulario_hapvida_check_expired_leads');
    
    if ($scheduled !== false) {
        $next_run = wp_next_scheduled('formulario_hapvida_check_expired_leads');
        if ($next_run) {
            $this->log("✅ Cron agendado com sucesso para: " . date('d/m/Y H:i:s', $next_run));
        } else {
            $this->log("❌ Falha: wp_schedule_event retornou sucesso mas cron não foi encontrado");
            $this->start_alternative_system();
        }
    } else {
        $this->log("❌ ERRO: wp_schedule_event retornou false - iniciando sistema alternativo");
        $this->start_alternative_system();
    }
}

    public function add_custom_cron_interval($schedules) {
        if (!isset($schedules['formulario_hapvida_5min'])) {
            $schedules['formulario_hapvida_5min'] = array(
                'interval' => 5 * 60, // 5 minutos em segundos
                'display'  => 'A cada 5 minutos (Lead Tracking)'
            );
            $this->log("📅 Intervalo customizado de 5min registrado");
        }
        return $schedules;
    }

    /**
 * Sistema alternativo quando cron falha - CORRIGIDO
 */
private function start_alternative_system() {
    $this->log("🚨 Iniciando sistema alternativo robusto...");
    
    // Marca timestamp da ativação
    update_option('formulario_hapvida_alternative_active', time());
    
    // Só adiciona hook uma vez
    static $alternative_active = false;
    if (!$alternative_active) {
        $alternative_active = true;
        add_action('wp_loaded', array($this, 'alternative_check_system'), 25);
        $this->log("✅ Sistema alternativo ativado");
    }
}

/**
 * Sistema alternativo - executa apenas quando necessário - CORRIGIDO
 */
public function alternative_check_system() {
    static $executed = false;
    if ($executed) {
        return;
    }
    $executed = true;
    
    $last_check = get_option('formulario_hapvida_last_check', 0);
    $current_time = time();
    
    // Executa a cada 3 minutos (mais frequente que o cron de 5min)
    if (($current_time - $last_check) >= 180) {
        $this->log("🔄 Sistema alternativo executando verificação (intervalo: 3min)...");
        update_option('formulario_hapvida_last_check', $current_time);
        $this->process_expired_leads();
        
        // Tenta reagendar o cron se ele se perdeu
        $next_cron = wp_next_scheduled('formulario_hapvida_check_expired_leads');
        if (!$next_cron) {
            $this->log("🔧 Tentando restaurar cron via sistema alternativo...");
            $this->schedule_expired_check();
        }
    }
}

    public function force_system_check() {
        $this->log("🔧 VERIFICAÇÃO FORÇADA DO SISTEMA");
        
        // 1. Verifica se cron está funcionando
        $next_cron = wp_next_scheduled('formulario_hapvida_check_expired_leads');
        if (!$next_cron) {
            $this->log("❌ Cron não agendado, reagendando...");
            $this->schedule_expired_check();
        }
        
        // 2. Verifica leads expirados
        $this->process_expired_leads();
        
        // 3. Força próxima verificação em 2 minutos
        update_option('formulario_hapvida_last_check', time() - 180); // 3 minutos atrás = próxima em 2min
        
        $this->log("✅ Verificação forçada concluída");
    }

    /**
     * *** NOVA: Status completo do sistema ***
     */
    public function get_system_status() {
        return array(
            'cron_scheduled' => wp_next_scheduled('formulario_hapvida_check_expired_leads'),
            'cron_next_run' => wp_next_scheduled('formulario_hapvida_check_expired_leads') ? date('d/m/Y H:i:s', wp_next_scheduled('formulario_hapvida_check_expired_leads')) : 'Não agendado',
            'alternative_active' => get_option('formulario_hapvida_last_check', 0) > 0,
            'last_alternative_check' => get_option('formulario_hapvida_last_check', 0) ? date('d/m/Y H:i:s', get_option('formulario_hapvida_last_check', 0)) : 'Nunca',
            'wp_cron_enabled' => !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON),
            'pending_leads_count' => count($this->get_pending_confirmation_leads())
        );
    }
    
    public function register_confirmation_endpoint() {
    // *** CORREÇÃO: Registra endpoint de forma mais robusta ***
    register_rest_route('formulario-hapvida/v1', '/confirmar-lead', array(
        'methods'  => array('GET', 'POST'),
        'callback' => array($this, 'handle_lead_confirmation'),
        'permission_callback' => '__return_true',
        'args' => array(
            'token' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function($param, $request, $key) {
                    return !empty($param);
                }
            ),
        ),
    ));
    
    $this->log("✅ Endpoint /formulario-hapvida/v1/confirmar-lead registrado");
}

    public function force_register_endpoint() {
        // Hook adicional para garantir que endpoint seja registrado
        add_action('rest_api_init', array($this, 'register_confirmation_endpoint'), 20);
        add_action('init', array($this, 'register_confirmation_endpoint'), 20);
        
        $this->log("🔧 Hooks adicionais do endpoint registrados");
    }
    
/**
 * *** MÉTODO FALTANDO: get_dynamic_timeout ***
 */
 
 public function debug_confirmation_system() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $this->log("=== [CORREÇÃO] DEBUG SISTEMA DE CONFIRMAÇÃO ===");
    error_log("HAPVIDA DEBUG: Iniciando debug do sistema de confirmação");
    
    // Verifica se as rotas REST estão registradas
    $routes = rest_get_server()->get_routes();
    $hapvida_routes = array();
    
    foreach ($routes as $route => $handlers) {
        if (strpos($route, 'formulario-hapvida') !== false) {
            $hapvida_routes[$route] = $handlers;
        }
    }
    
    if (!empty($hapvida_routes)) {
        $this->log("✅ [CORREÇÃO] Rotas REST encontradas:");
        foreach ($hapvida_routes as $route => $handlers) {
            $this->log("  - " . $route);
            error_log("HAPVIDA DEBUG: Rota encontrada - " . $route);
        }
    } else {
        $this->log("❌ [CORREÇÃO] NENHUMA rota REST encontrada!");
        error_log("HAPVIDA ERROR: Nenhuma rota REST encontrada");
    }
    
    // Testa URL de confirmação
    $test_url = home_url('/wp-json/formulario-hapvida/v1/confirmar-lead');
    $this->log("🔗 [CORREÇÃO] URL base de confirmação: " . $test_url);
    error_log("HAPVIDA DEBUG: URL base confirmação - " . $test_url);
    
    // Verifica se a classe principal está disponível
    global $formulario_hapvida;
    if ($formulario_hapvida) {
        $this->log("✅ [CORREÇÃO] Classe principal do formulário está disponível");
        error_log("HAPVIDA DEBUG: Classe principal OK");
    } else {
        $this->log("❌ [CORREÇÃO] Classe principal do formulário NÃO está disponível");
        error_log("HAPVIDA ERROR: Classe principal não encontrada");
    }
    
    // Verifica tabela do banco
    global $wpdb;
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") == $this->table_name;
    
    if ($table_exists) {
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $this->log("✅ [CORREÇÃO] Tabela de tracking existe com {$count} registros");
        error_log("HAPVIDA DEBUG: Tabela OK - {$count} registros");
    } else {
        $this->log("❌ [CORREÇÃO] Tabela de tracking NÃO existe!");
        error_log("HAPVIDA ERROR: Tabela não encontrada");
    }
}

private function redistribute_lead($lead) {
    global $wpdb;
    
    $this->log("🔄 REDISTRIBUINDO LEAD: {$lead['lead_id']}");
    
    // Obtém próximo vendedor do mesmo grupo
    $vendedor_atual = $lead['vendedor_atual'];
    $vendedor_atual_nome = isset($vendedor_atual['nome']) ? $vendedor_atual['nome'] : 'N/A';
    $grupo = isset($vendedor_atual['grupo']) ? $vendedor_atual['grupo'] : 'drv';
    
    $this->log("👤 Vendedor atual: {$vendedor_atual_nome} ({$grupo})");
    
    // Busca próximo vendedor
    $novo_vendedor = $this->get_next_vendor_same_group($grupo, $vendedor_atual_nome);
    
    if (!$novo_vendedor) {
        $this->log("❌ Nenhum outro vendedor disponível no grupo {$grupo}");
        $this->mark_lead_as_failed($lead);
        return false;
    }
    
    $this->log("✅ Próximo vendedor selecionado: {$novo_vendedor['nome']} ({$novo_vendedor['telefone']})");
    
    // Atualiza histórico
    $vendedor_historico = $lead['vendedor_historico'];
    if (!is_array($vendedor_historico)) {
        $vendedor_historico = array();
    }
    
    // Adiciona vendedor atual ao histórico
    $vendedor_historico[] = array(
        'vendedor' => $vendedor_atual,
        'tentativa' => intval($lead['tentativas']),
        'expirado_em' => $lead['expira_em'],
        'redistribuido_em' => current_time('mysql')
    );
    
    // Incrementa tentativas
    $novas_tentativas = intval($lead['tentativas']) + 1;
    $max_tentativas = intval($lead['max_tentativas']);
    
    // * CORREÇÃO: Calcula nova expiração usando WordPress *
    $timeout_minutos = $this->get_dynamic_timeout();
    
    // Cria DateTime com timezone do WordPress
    $wp_timezone = wp_timezone();
    $now = new DateTime(current_time('mysql'), $wp_timezone);
    $nova_expiracao_dt = clone $now;
    $nova_expiracao_dt->add(new DateInterval('PT' . $timeout_minutos . 'M'));
    $nova_expiracao = $nova_expiracao_dt->format('Y-m-d H:i:s');
    
    // Gera novo token
    $novo_token = $this->generate_confirmation_token($lead['lead_id']);
    
    $this->log("📝 Atualizando lead no banco de dados...");
    $this->log("   - Novo vendedor: {$novo_vendedor['nome']}");
    $this->log("   - Nova expiração: {$nova_expiracao} ({$timeout_minutos} minutos)");
    $this->log("   - Tentativas: {$novas_tentativas}/{$max_tentativas}");
    
    // Atualiza no banco
    $result = $wpdb->update(
        $this->table_name,
        array(
            'vendedor_atual' => json_encode($novo_vendedor),
            'vendedor_historico' => json_encode($vendedor_historico),
            'tentativas' => $novas_tentativas,
            'token_confirmacao' => $novo_token,
            'expira_em' => $nova_expiracao,
            'enviado_em' => current_time('mysql'),
            'status' => 'aguardando'
        ),
        array('lead_id' => $lead['lead_id']),
        array('%s', '%s', '%d', '%s', '%s', '%s', '%s'),
        array('%s')
    );
    
    if ($result === false) {
        $this->log("❌ ERRO ao atualizar lead no banco: " . $wpdb->last_error);
        return false;
    }
    
    $this->log("✅ Lead atualizado no banco com sucesso");
    
    // Registra estatísticas
    $this->track_vendor_expiration($vendedor_atual_nome, $grupo, $lead['lead_id']);
    $this->track_vendor_received($novo_vendedor['nome'], $novo_vendedor['grupo'], $lead['lead_id']);
    
    // Prepara dados para webhook
    $form_data = $lead['form_data'];
    $form_data['vendedor_anterior'] = $vendedor_atual_nome;
    $form_data['tentativa'] = $novas_tentativas;
    $form_data['motivo_redistribuicao'] = 'Timeout de confirmação expirado';
    
    // Envia webhook para novo vendedor
    global $formulario_hapvida;
    if ($formulario_hapvida && method_exists($formulario_hapvida, 'send_to_webhook_redistribution')) {
        $webhook_success = $formulario_hapvida->send_to_webhook_redistribution($form_data, $novo_vendedor);
        
        if ($webhook_success) {
            $this->log("✅ Webhook de redistribuição enviado com sucesso");
        } else {
            $this->log("⚠️ Falha ao enviar webhook de redistribuição");
        }
    }
    
    $this->log("🎯 Lead {$lead['lead_id']} redistribuído com sucesso para {$novo_vendedor['nome']}");
    
    return true;
}

private function send_redistribution_webhook($lead, $novo_vendedor, $novo_token, $nova_expiracao, $vendedor_anterior_nome) {
    $this->log("📤 === INICIANDO ENVIO DO WEBHOOK DE REDISTRIBUIÇÃO ===");
    $this->log("Lead ID: {$lead['lead_id']}");
    $this->log("Novo vendedor: {$novo_vendedor['nome']} ({$novo_vendedor['grupo']})");
    $this->log("Vendedor anterior: {$vendedor_anterior_nome}");
    
    // *** CORREÇÃO: Acessa o plugin principal através das múltiplas formas disponíveis ***
    $plugin_instance = null;
    
    // Método 1: Variável global
    global $formulario_hapvida_plugin;
    if ($formulario_hapvida_plugin && method_exists($formulario_hapvida_plugin, 'send_to_webhook_redistribution')) {
        $plugin_instance = $formulario_hapvida_plugin;
        $this->log("✅ Plugin principal encontrado via global");
    }
    
    // Método 2: Singleton (se existir)
    if (!$plugin_instance) {
        if (class_exists('Formulario_Hapvida') && method_exists('Formulario_Hapvida', 'get_instance')) {
            $plugin_instance = Formulario_Hapvida::get_instance();
            if ($plugin_instance && method_exists($plugin_instance, 'send_to_webhook_redistribution')) {
                $this->log("✅ Plugin principal encontrado via singleton");
            } else {
                $plugin_instance = null;
            }
        }
    }
    
    // Método 3: Busca na lista de classes instanciadas
    if (!$plugin_instance) {
        foreach (get_declared_classes() as $class_name) {
            if (strpos($class_name, 'Formulario_Hapvida') !== false && $class_name !== get_class($this)) {
                if (method_exists($class_name, 'send_to_webhook_redistribution')) {
                    // Tenta acessar via método estático ou global
                    if (isset($GLOBALS[strtolower($class_name)])) {
                        $plugin_instance = $GLOBALS[strtolower($class_name)];
                        $this->log("✅ Plugin principal encontrado via GLOBALS: {$class_name}");
                        break;
                    }
                }
            }
        }
    }
    
    if (!$plugin_instance) {
        $this->log("❌ ERRO: Plugin principal não disponível para envio de webhook");
        $this->log("🔍 DEBUG: Tentando envio direto via wp_remote_post...");
        
        // *** FALLBACK: Envia webhook diretamente ***
        return $this->send_redistribution_webhook_direct($lead, $novo_vendedor, $novo_token, $nova_expiracao, $vendedor_anterior_nome);
    }
    
    // Prepara dados do formulário para o webhook
    $form_data = $lead['form_data'];
    
    // *** ADICIONA INFORMAÇÕES DA REDISTRIBUIÇÃO ***
    $form_data['redistribuido'] = true;
    $form_data['vendedor_anterior'] = $vendedor_anterior_nome;
    $form_data['tentativa'] = intval($lead['tentativas']) + 1;
    $form_data['motivo_redistribuicao'] = 'Vendedor anterior não confirmou o lead';
    
    // *** ADICIONA DADOS DE TRACKING PARA O NOVO VENDEDOR ***
    $timeout_minutos = $this->get_dynamic_timeout();
    $link_confirmacao = $this->get_confirmation_link($novo_token);
    
    $form_data['lead_tracking'] = array(
        'lead_id' => $lead['lead_id'],
        'token' => $novo_token,
        'link_confirmacao' => $link_confirmacao,
        'expira_em' => $nova_expiracao,
        'timeout_minutos' => $timeout_minutos,
        'instrucoes' => "🔄 *REDISTRIBUIÇÃO*\n\n" .
                        "Esse lead foi redistribuído para você.\n\n" .
                        "⏰ Confirme o recebimento em até *" . $timeout_minutos . " minutos*\n\n" .
                        "⚠️ Caso contrário, passará para outro consultor online."
    );
    
    $this->log("🎯 Preparando envio para webhook de redistribuição");
    $this->log("🔗 Link de confirmação: {$link_confirmacao}");
    $this->log("⏰ Timeout: {$timeout_minutos} minutos");
    
    // *** CHAMA A FUNÇÃO DE REDISTRIBUIÇÃO NO PLUGIN PRINCIPAL ***
    $resultado = $plugin_instance->send_to_webhook_redistribution($form_data, $novo_vendedor);
    
    if ($resultado) {
        $this->log("✅ Webhook de redistribuição enviado com sucesso");
        return true;
    } else {
        $this->log("❌ Falha ao enviar webhook de redistribuição");
        return false;
    }
}

private function send_redistribution_webhook_direct($lead, $novo_vendedor, $novo_token, $nova_expiracao, $vendedor_anterior_nome) {
    $this->log("🚀 === ENVIANDO WEBHOOK DIRETO (FALLBACK) ===");
    
    try {
        $options = get_option('formulario_hapvida_settings');
        $grupo = $novo_vendedor['grupo'] ?? 'drv';
        
        // Determina URL do webhook de redistribuição
        if ($grupo === 'drv') {
            $webhook_url = isset($options['webhook_url_drv_redistribution']) ? $options['webhook_url_drv_redistribution'] : '';
            $webhook_type = 'redistribuição DRV';
            
            // Fallback para URL principal
            if (empty($webhook_url)) {
                $webhook_url = isset($options['webhook_url_drv']) ? $options['webhook_url_drv'] : '';
                $webhook_type = 'DRV (principal)';
                $this->log("⚠️ Usando URL principal DRV como fallback");
            }
        } elseif ($grupo === 'seu_souza') {
            $webhook_url = isset($options['webhook_url_seu_souza_redistribution']) ? $options['webhook_url_seu_souza_redistribution'] : '';
            $webhook_type = 'redistribuição Seu Souza';
            
            // Fallback para URL principal
            if (empty($webhook_url)) {
                $webhook_url = isset($options['webhook_url_seu_souza']) ? $options['webhook_url_seu_souza'] : '';
                $webhook_type = 'Seu Souza (principal)';
                $this->log("⚠️ Usando URL principal Seu Souza como fallback");
            }
        } else {
            $this->log("❌ ERRO: Grupo desconhecido: {$grupo}");
            return false;
        }
        
        if (empty($webhook_url)) {
            $this->log("❌ ERRO: URL do webhook não configurada para grupo {$grupo}");
            return false;
        }
        
        $this->log("🌐 URL do webhook ({$webhook_type}): " . substr($webhook_url, 0, 50) . "...");
        
        // Prepara dados do webhook
        $form_data = $lead['form_data'];
        $timeout_minutos = $this->get_dynamic_timeout();
        $link_confirmacao = $this->get_confirmation_link($novo_token);
        
        // *** ESTRUTURA COMPLETA COM TODAS AS INFORMAÇÕES NECESSÁRIAS ***
        $webhook_data = array(
            // *** IDENTIFICAÇÃO ***
            'lead_id' => $lead['lead_id'],
            'webhook_type' => 'redistribution',
            
            // *** DADOS DO CLIENTE ***
            'nome' => isset($form_data['name']) ? $form_data['name'] : (isset($form_data['nome']) ? $form_data['nome'] : 'N/A'),
            'telefone' => isset($form_data['telefone']) ? $form_data['telefone'] : 'N/A',
            'cidade' => isset($form_data['cidade']) ? $form_data['cidade'] : 'N/A',
            
            // *** DADOS DO PLANO COMPLETOS ***
            'tipo_de_plano' => isset($form_data['qual_plano']) ? $form_data['qual_plano'] : 'N/A',
            'quantidade_de_pessoas' => isset($form_data['qtd_pessoas']) ? $form_data['qtd_pessoas'] : (isset($form_data['quantidade_de_pessoas']) ? $form_data['quantidade_de_pessoas'] : 'N/A'),
            'idades' => isset($form_data['ages']) ? (is_array($form_data['ages']) ? implode(', ', $form_data['ages']) : $form_data['ages']) : (isset($form_data['idades']) ? $form_data['idades'] : 'N/A'),
            

            
            // *** DADOS DO VENDEDOR ATUAL ***
            'atendente' => $novo_vendedor['nome'],
            'telefone_vendedor' => isset($novo_vendedor['telefone']) ? $novo_vendedor['telefone'] : 'N/A',
            'grupo' => $novo_vendedor['grupo'],
            
            // *** INFORMAÇÕES DE REDISTRIBUIÇÃO ***
            'redistribuido' => true,
            'vendedor_anterior' => $vendedor_anterior_nome,
            'tentativa' => intval($lead['tentativas']) + 1,
            'motivo_redistribuicao' => 'Vendedor anterior não confirmou o lead',
            
            // *** DADOS TEMPORAIS ***
            'data' => current_time('d/m/Y'),
            'horario' => current_time('H:i'),
            'timestamp' => current_time('timestamp'),
            
            // *** TIMEOUT E TRACKING ***
            'timeout_minutos' => $timeout_minutos,
            'lead_tracking' => array(
                'lead_id' => $lead['lead_id'],
                'token' => $novo_token,
                'link_confirmacao' => $link_confirmacao,
                'expira_em' => $nova_expiracao,
                'timeout_minutos' => $timeout_minutos,
                'instrucoes' => "🔄 *REDISTRIBUIÇÃO*\n\n" .
                                "Esse lead foi redistribuído para você.\n\n" .
                                "👤 Cliente: " . (isset($form_data['name']) ? $form_data['name'] : 'N/A') . "\n" .
                                "📞 Telefone: " . (isset($form_data['telefone']) ? $form_data['telefone'] : 'N/A') . "\n" .
                                "🏙️ Cidade: " . (isset($form_data['cidade']) ? $form_data['cidade'] : 'N/A') . "\n" .
                                "👥 Pessoas: " . (isset($form_data['qtd_pessoas']) ? $form_data['qtd_pessoas'] : 'N/A') . "\n" .
                                "🎂 Idades: " . (isset($form_data['ages']) ? (is_array($form_data['ages']) ? implode(', ', $form_data['ages']) : $form_data['ages']) : 'N/A') . "\n\n" .
                                "⏰ Confirme o recebimento em até *" . $timeout_minutos . " minutos*\n\n" .
                                "⚠️ Caso contrário, passará para outro consultor online."
            )
        );

        // *** LOG DOS DADOS PREPARADOS ***
        $this->log("📋 [FALLBACK] Dados preparados:");
        $this->log("   - Lead ID: {$webhook_data['lead_id']}");
        $this->log("   - Cliente: {$webhook_data['nome']}");
        $this->log("   - Telefone Cliente: {$webhook_data['telefone']}");
        $this->log("   - Vendedor: {$webhook_data['atendente']}");
        $this->log("   - Telefone Vendedor: {$webhook_data['telefone_vendedor']}");
        $this->log("   - Quantidade de Pessoas: {$webhook_data['quantidade_de_pessoas']}");
        $this->log("   - Idades: {$webhook_data['idades']}");
        $this->log("   - Tentativa: {$webhook_data['tentativa']}");

        // *** ENVIO DO WEBHOOK ***
        $json_data = json_encode($webhook_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        if ($json_data === false) {
            $this->log("❌ ERRO: Falha ao converter dados para JSON: " . json_last_error_msg());
            return false;
        }

        $this->log("📤 [FALLBACK] JSON preparado (" . strlen($json_data) . " bytes)");

        $response = wp_remote_post($webhook_url, array(
            'body' => $json_data,
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'Formulario-Hapvida-Lead-Tracking/1.0',
                'X-Webhook-Type' => 'redistribution-fallback',
                'X-Lead-ID' => $webhook_data['lead_id'],
                'X-Vendor-Group' => $grupo,
                'X-Vendor-Phone' => $webhook_data['telefone_vendedor'],
                'X-People-Count' => $webhook_data['quantidade_de_pessoas']
            ),
            'timeout' => 30,
            'blocking' => true,
            'sslverify' => false
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log("❌ [FALLBACK] Erro de conexão: {$error_message}");
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        $this->log("📥 [FALLBACK] HTTP {$response_code}");
        $this->log("📥 [FALLBACK] Resposta: " . substr($response_body, 0, 200) . "...");

        if ($response_code >= 200 && $response_code < 300) {
            $this->log("✅ [FALLBACK] Webhook enviado com sucesso!");
            return true;
        } else {
            $this->log("❌ [FALLBACK] Servidor rejeitou: HTTP {$response_code}");
            return false;
        }
        
    } catch (Exception $e) {
        $this->log("❌ [FALLBACK] ERRO: " . $e->getMessage());
        return false;
    }
}

private function validate_webhook_configuration() {
    $this->log("🔍 === VALIDANDO CONFIGURAÇÕES DE WEBHOOK ===");
    
    $options = get_option('formulario_hapvida_settings');
    
    if (!$options || !is_array($options)) {
        $this->log("❌ ERRO CRÍTICO: Opções do plugin não encontradas!");
        return false;
    }
    
    $webhook_configs = array(
        'webhook_url_drv' => 'DRV - Primeiro Envio',
        'webhook_url_drv_redistribution' => 'DRV - Redistribuição',
        'webhook_url_drv_confirmation' => 'DRV - Confirmação',
        'webhook_url_seu_souza' => 'Seu Souza - Primeiro Envio',
        'webhook_url_seu_souza_redistribution' => 'Seu Souza - Redistribuição',
        'webhook_url_seu_souza_confirmation' => 'Seu Souza - Confirmação'
    );
    
    $valid_configs = 0;
    $total_configs = count($webhook_configs);
    
    foreach ($webhook_configs as $key => $description) {
        $url = isset($options[$key]) ? trim($options[$key]) : '';
        
        if (empty($url)) {
            $this->log("⚠️ {$description}: NÃO CONFIGURADO");
        } else if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->log("❌ {$description}: URL INVÁLIDA - {$url}");
        } else {
            $this->log("✅ {$description}: OK - " . substr($url, 0, 50) . "...");
            $valid_configs++;
        }
    }
    
    $this->log("📊 Resumo: {$valid_configs}/{$total_configs} configurações válidas");
    
    // Valida configurações básicas obrigatórias
    $required_drv = isset($options['webhook_url_drv']) && !empty(trim($options['webhook_url_drv']));
    $required_redistribution_drv = isset($options['webhook_url_drv_redistribution']) && !empty(trim($options['webhook_url_drv_redistribution']));
    
    if (!$required_drv && !$required_redistribution_drv) {
        $this->log("❌ ERRO CRÍTICO: Nenhuma URL de webhook configurada para DRV!");
        return false;
    }
    
    return true;
}


public function get_dynamic_timeout() {
    try {
        $options = get_option('formulario_hapvida_settings', array());
        $timeout_weekdays = isset($options['redistribution_timeout_weekdays']) ? 
            intval($options['redistribution_timeout_weekdays']) : 10;
        $timeout_weekends = isset($options['redistribution_timeout_weekends']) ? 
            intval($options['redistribution_timeout_weekends']) : 30;
        
        // *** VERIFICA SE ESTÁ NO HORÁRIO COMERCIAL (JÁ CONSIDERA FINAIS DE SEMANA) ***
        $is_business_hours = $this->is_horario_comercial();
        
        // *** LOG DETALHADO PARA DEBUG ***
        $timezone = new DateTimeZone($this->timezone ?? 'America/Sao_Paulo');
        $agora = new DateTime('now', $timezone);
        $hora_atual = $agora->format('H:i');
        $dia_semana = $agora->format('N'); // 1=segunda, 7=domingo
        $nome_dia = $agora->format('l'); // Nome do dia em inglês
        
        $this->log("🕐 DEBUG TIMEOUT - Dia: {$nome_dia} ({$dia_semana}), Hora: {$hora_atual}");
        $this->log("🕐 Horário comercial configurado: {$this->horario_inicio} às {$this->horario_fim}");
        $this->log("🕐 Is business hours: " . ($is_business_hours ? 'SIM' : 'NÃO'));
        $this->log("🕐 Timeout weekdays: {$timeout_weekdays}min, Timeout weekends: {$timeout_weekends}min");
        
        // *** NOVA LÓGICA: Se está no horário comercial, usa timeout de dias úteis ***
        // *** Se não está, usa timeout de finais de semana/noturno ***
        if ($is_business_hours) {
            // *** ESPECIAL PARA FINAIS DE SEMANA: Se é sábado/domingo E está funcionando, usa timeout específico ***
            if ($dia_semana == 6 || $dia_semana == 7) {
                $this->log("⏰ Timeout aplicado: {$timeout_weekends} min (FINAL DE SEMANA com funcionamento ativo)");
                return $timeout_weekends;
            } else {
                $this->log("⏰ Timeout aplicado: {$timeout_weekdays} min (horário comercial - dia útil)");
                return $timeout_weekdays;
            }
        } else {
            $this->log("⏰ Timeout aplicado: {$timeout_weekends} min (FORA do horário comercial)");
            return $timeout_weekends;
        }
        
    } catch (Exception $e) {
        $this->log("❌ ERRO ao calcular timeout dinâmico: " . $e->getMessage());
        // Em caso de erro, retorna timeout estendido para segurança
        return 30;
    }
}

private function get_current_timeout() {
    return $this->get_dynamic_timeout();
}

// Substituir a função force_redistribute_lead() no arquivo lead-tracking.php

public function force_redistribute_lead($lead_id) {
    global $wpdb;
    
    // Busca o lead
    $lead = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$this->table_name} WHERE lead_id = %s", $lead_id),
        ARRAY_A
    );
    
    if (!$lead) {
        $this->log("❌ Lead {$lead_id} não encontrado");
        return false;
    }
    
    // Decodifica dados JSON
    $lead['form_data'] = json_decode($lead['form_data'], true);
    $lead['vendedor_atual'] = json_decode($lead['vendedor_atual'], true);
    $lead['vendedor_historico'] = json_decode($lead['vendedor_historico'], true);
    
    if ($lead['status'] !== 'aguardando') {
        return false;
    }
    
    // Chama a função de redistribuição
    $result = $this->redistribute_lead($lead);
    
    if ($result) {
        $this->log("✅ Lead {$lead_id} redistribuído com sucesso (força manual)");
        return true;
    } else {
        $this->log("❌ Falha na redistribuição forçada do lead {$lead_id}");
        return false;
    }
}

private function get_recent_logs($lines = 50) {
    if (!file_exists($this->log_file)) {
        return "Arquivo de log não encontrado.";
    }
    
    $file_lines = file($this->log_file);
    $recent_lines = array_slice($file_lines, -$lines);
    
    return implode('', $recent_lines);
}

/**
 * *** FUNÇÃO AUXILIAR: store_form_origin ***
 * Garante que a página de origem seja preservada
 */
private function store_form_origin($form_data) {
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

/**
 * *** FUNÇÃO AUXILIAR: get_daily_submission_count ***
 * Obtém contagem diária de submissions
 */
private function get_daily_submission_count() {
    $daily_submissions = get_option('formulario_hapvida_daily_submissions', array());
    $today = current_time('Y-m-d');
    
    return isset($daily_submissions[$today]) ? $daily_submissions[$today] : 0;
}

/**
 * *** FUNÇÃO AUXILIAR: get_monthly_submission_count ***
 * Obtém contagem mensal de submissions
 */
private function get_monthly_submission_count() {
    $monthly_submissions = get_option('formulario_hapvida_monthly_submissions', array());
    $current_month = current_time('Y-m');
    
    return isset($monthly_submissions[$current_month]) ? $monthly_submissions[$current_month] : 0;
}

private function get_confirmation_link($token) {
    // *** CORREÇÃO: Usa o endpoint REST correto ***
    $confirmation_url = add_query_arg(array(
        'rest_route' => '/formulario-hapvida/v1/confirmar-lead',
        'token' => urlencode($token)
    ), home_url());
    
    $this->log("🔗 Link de confirmação gerado: " . $confirmation_url);
    return $confirmation_url;
}


    public function create_lead_tracking($form_data, $vendedor) {
    global $wpdb;
    
    try {
        // * CORREÇÃO: Usa ID único do form_data se disponível, senão gera novo *
        $lead_id = isset($form_data['lead_id']) ? $form_data['lead_id'] : $this->generate_unique_lead_id();
        
        // * CORREÇÃO PRINCIPAL: USA APENAS FUNÇÕES DO WORDPRESS *
        $created_at = current_time('mysql'); // Formato: Y-m-d H:i:s no timezone do WP
        
        // * CORREÇÃO: Calcula timeout corretamente *
        $timeout_minutos = $this->get_dynamic_timeout();
        $timeout_segundos = $timeout_minutos * 60;
        
        // * CORREÇÃO CRÍTICA: Usa WordPress para calcular expiração *
        // Cria DateTime com timezone do WordPress
        $wp_timezone = wp_timezone();
        $created_datetime = new DateTime($created_at, $wp_timezone);
        $expira_datetime = clone $created_datetime;
        $expira_datetime->add(new DateInterval('PT' . $timeout_minutos . 'M'));
        $expira_em = $expira_datetime->format('Y-m-d H:i:s');
        
        $token = $this->generate_confirmation_token($lead_id);
        
        $this->log("🔄 Criando tracking para lead: {$lead_id}");
        $this->log("⏰ Timeout calculado dinamicamente: {$timeout_minutos} minutos");
        $this->log("📅 Criado em: {$created_at} (timezone: " . wp_timezone_string() . ")");
        $this->log("📅 Expira em: {$expira_em}");
        $this->log("🔧 DEBUG: Diferença em minutos: " . $created_datetime->diff($expira_datetime)->i);
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'lead_id' => $lead_id,
                'form_data' => json_encode($form_data),
                'vendedor_atual' => json_encode($vendedor),
                'vendedor_historico' => json_encode(array()),
                'status' => 'aguardando',
                'tentativas' => 1,
                'max_tentativas' => $this->max_redistributions,
                'token_confirmacao' => $token,
                'created_at' => $created_at,
                'expira_em' => $expira_em,
                'enviado_em' => $created_at
            ),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s')
        );
        
        if ($result) {
            $this->log("✅ Lead tracking criado: {$lead_id} -> {$vendedor['nome']} ({$vendedor['grupo']})");
            $this->log("✅ Timeout aplicado: {$timeout_minutos} minutos (expira: {$expira_em})");
            
            $this->track_vendor_received($vendedor['nome'], $vendedor['grupo'], $lead_id);
            
            return array(
                'lead_id' => $lead_id,
                'token' => $token,
                'link_confirmacao' => $this->get_confirmation_link($token),
                'expira_em' => $expira_em,
                'timeout_minutos' => $timeout_minutos
            );
        } else {
            $this->log("❌ ERRO: Falha ao criar lead tracking: " . $wpdb->last_error);
            return false;
        }
        
    } catch (Exception $e) {
        $this->log("❌ ERRO CRÍTICO ao criar lead tracking: " . $e->getMessage());
        return false;
    }
}

    private function generate_unique_lead_id() {
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
    
    $this->log("🆔 ID único gerado: {$lead_id}");
    
    return $lead_id;
}

    private function generate_confirmation_token($lead_id) {
        $data = array(
            'lead_id' => $lead_id,
            'timestamp' => time(),
            'nonce' => wp_create_nonce('confirm_lead_' . $lead_id)
        );
        
        // Encode em base64 para URL-safe
        return base64_encode(json_encode($data));
    }
    
private function validate_confirmation_token($token) {
    try {
        $decoded = json_decode(base64_decode($token), true);
        
        if (!$decoded || !isset($decoded['lead_id'], $decoded['timestamp'], $decoded['nonce'])) {
            $this->log("❌ Token inválido: estrutura incorreta");
            return false;
        }
        
        // *** CORREÇÃO: Token válido por 72 horas ***
        if (time() - $decoded['timestamp'] > 72 * 3600) {
            $this->log("❌ Token expirado: " . $decoded['lead_id']);
            return false;
        }
        
        // *** CORREÇÃO: Validação de nonce mais flexível ***
        $expected_nonce = wp_create_nonce('confirm_lead_' . $decoded['lead_id']);
        
        // Verifica nonce atual
        if (hash_equals($decoded['nonce'], $expected_nonce)) {
            $this->log("✅ Token validado com sucesso: " . $decoded['lead_id']);
            return $decoded['lead_id'];
        }
        
        // *** FALLBACK: Se token é recente (menos de 24h), permite sem nonce ***
        if (time() - $decoded['timestamp'] < 24 * 3600) {
            $this->log("⚠️ Token recente aceito sem validação de nonce: " . $decoded['lead_id']);
            return $decoded['lead_id'];
        }
        
        $this->log("❌ Nonce inválido para: " . $decoded['lead_id']);
        return false;
        
    } catch (Exception $e) {
        $this->log("❌ Erro ao validar token: " . $e->getMessage());
        return false;
    }
}

public function handle_lead_confirmation($request) {
    $token = $request->get_param('token');
    
    $this->log("🔍 Processando confirmação com token: " . substr($token, 0, 20) . "...");
    
    if (!$token) {
        return $this->output_confirmation_html('error', 'Token não fornecido');
    }
    
    // Valida token
    $lead_id = $this->validate_confirmation_token($token);
    
    if (!$lead_id) {
        return $this->output_confirmation_html('error', 'Token inválido ou expirado');
    }
    
    // Busca lead no banco
    $lead_data = $this->get_lead_by_id($lead_id);
    
    if (!$lead_data) {
        return $this->output_confirmation_html('error', 'Lead não encontrado');
    }
    
    if ($lead_data['status'] === 'confirmado') {
        return $this->output_confirmation_html('already_confirmed', $lead_data);
    }
    
    if ($lead_data['status'] !== 'aguardando') {
        return $this->output_confirmation_html('error', 'Lead não está aguardando confirmação');
    }
    
    // Confirma o lead
    $confirmacao_sucesso = $this->confirm_lead_receipt($lead_id);
    
    if ($confirmacao_sucesso) {
        // Recarrega dados atualizados
        $lead_data_updated = $this->get_lead_by_id($lead_id);
        return $this->output_confirmation_html('success', $lead_data_updated);
    } else {
        return $this->output_confirmation_html('error', 'Erro interno ao confirmar lead');
    }
}

private function output_confirmation_html($status, $data = null) {
    // Log para debug
    $this->log("=== RENDERIZANDO PÁGINA DE CONFIRMAÇÃO ===");
    $this->log("Status: " . $status);
    
    // *** CORREÇÃO: Extração segura do nome do cliente ***
    $cliente_nome = 'Cliente';
    if (is_array($data)) {
        if (isset($data['form_data']['name'])) {
            $cliente_nome = $data['form_data']['name'];
        } elseif (isset($data['form_data']['nome'])) {
            $cliente_nome = $data['form_data']['nome'];
        }
    }
    
    // *** CORREÇÃO PRINCIPAL: Mostra o vendedor que confirmou (vendedor_atual) ***
    $vendedor_nome = 'Vendedor';
    if (is_array($data) && isset($data['vendedor_atual']['nome'])) {
        $vendedor_nome = $data['vendedor_atual']['nome'];
    }
    
    // *** CORREÇÃO PRINCIPAL: Usar timezone do WordPress ***
    $current_wp_time = current_time('d/m/Y H:i:s');
    
    // Log dos dados extraídos
    $this->log("Cliente: " . $cliente_nome);
    $this->log("Vendedor: " . $vendedor_nome);
    
    // Limpa qualquer output anterior
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Headers corretos
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Confirmação de Lead - Hapvida</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .container {
                background: white;
                max-width: 500px;
                width: 100%;
                border-radius: 20px;
                box-shadow: 0 20px 40px rgba(0,0,0,0.1);
                overflow: hidden;
                text-align: center;
            }

            .header {
                padding: 40px 30px 30px;
                background: linear-gradient(135deg, #0054B8 0%, #003d8a 100%);
                color: white;
            }

            .header.success {
                background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            }

            .header.error {
                background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            }

            .header.warning {
                background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
                color: #212529;
            }

            .icon {
                font-size: 4rem;
                margin-bottom: 15px;
                opacity: 0.9;
            }

            .header h1 {
                font-size: 1.8rem;
                margin-bottom: 10px;
                font-weight: 600;
            }

            .subtitle {
                font-size: 1rem;
                opacity: 0.9;
                font-weight: 300;
            }

            .content {
                padding: 40px 30px;
            }

            .message {
                font-size: 1.1rem;
                line-height: 1.6;
                margin-bottom: 30px;
                color: #555;
            }

            .lead-info {
                background: #f8f9fa;
                border-radius: 12px;
                padding: 25px;
                margin-bottom: 25px;
                border-left: 4px solid #0054B8;
            }

            .lead-info h3 {
                color: #0054B8;
                margin-bottom: 15px;
                font-size: 1.2rem;
            }

            .lead-info-row {
                display: flex;
                justify-content: space-between;
                margin-bottom: 10px;
                padding: 8px 0;
                border-bottom: 1px solid #dee2e6;
            }

            .lead-info-row:last-child {
                border-bottom: none;
                margin-bottom: 0;
            }

            .contact-info {
                background: #e3f2fd;
                border-radius: 12px;
                padding: 20px;
                margin-bottom: 25px;
            }

            .contact-info h3 {
                color: #1976d2;
                margin-bottom: 15px;
                font-size: 1.1rem;
            }

            .close-button {
                background: linear-gradient(135deg, #0054B8 0%, #003d8a 100%);
                color: white;
                border: none;
                padding: 12px 30px;
                border-radius: 25px;
                font-size: 1rem;
                cursor: pointer;
                transition: all 0.3s ease;
                text-decoration: none;
                display: inline-block;
            }

            .close-button:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 20px rgba(0,84,184,0.3);
            }

            .footer-note {
                margin-top: 30px;
                padding: 20px;
                background: #fff3cd;
                border-radius: 8px;
                color: #856404;
                font-size: 0.9rem;
            }

            @media (max-width: 600px) {
                .container {
                    margin: 10px;
                    border-radius: 15px;
                }
                
                .header {
                    padding: 30px 20px 20px;
                }
                
                .content {
                    padding: 30px 20px;
                }
                
                .icon {
                    font-size: 3rem;
                }
                
                .header h1 {
                    font-size: 1.5rem;
                }
            }
        </style>
    </head>
    <body>
        <div class="container <?php echo $status; ?>">
            
            <?php if ($status === 'success'): ?>
                <div class="header success">
                    <div class="icon">✅</div>
                    <h1>Lead Confirmado!</h1>
                    <p class="subtitle">Confirmação realizada com sucesso</p>
                </div>
                <div class="content">
                    <p class="message">
                        <strong>Parabéns!</strong> O lead foi confirmado e contabilizado em sua planilha.
                    </p>
                    <div class="lead-info">
                        <h3>📋 Informações do Lead</h3>
                        <div class="lead-info-row">
                            <strong>👤 Cliente:</strong>
                            <span><?php echo esc_html($cliente_nome); ?></span>
                        </div>
                        <div class="lead-info-row">
                            <strong>🏢 Vendedor:</strong>
                            <span><?php echo esc_html($vendedor_nome); ?></span>
                        </div>
                        <div class="lead-info-row">
                            <strong>✅ Confirmado em:</strong>
                            <span><?php echo $current_wp_time; ?></span>
                        </div>
                    </div>
                    <div class="contact-info">
                        <h3>ℹ️ Informação</h3>
                        <p>Lead foi contabilizado em sua planilha.</p>
                    </div>
                    <div style="text-align: center;">
                        <button onclick="window.close()" class="close-button">
                            <i class="fas fa-times"></i> Fechar Janela
                        </button>
                    </div>
                </div>
            
            <?php elseif ($status === 'already_confirmed'): ?>
                <div class="header warning">
                    <div class="icon">⚠️</div>
                    <h1>Lead Já Confirmado</h1>
                    <p class="subtitle">Este lead já foi confirmado anteriormente</p>
                </div>
                <div class="content">
                    <p class="message">
                        Este lead já foi confirmado e contabilizado em sua planilha.
                    </p>
                    <?php if (is_array($data)): ?>
                    <div class="lead-info">
                        <h3>📋 Informações do Lead</h3>
                        <div class="lead-info-row">
                            <strong>👤 Cliente:</strong>
                            <span><?php echo esc_html($cliente_nome); ?></span>
                        </div>
                        <div class="lead-info-row">
                            <strong>🏢 Vendedor:</strong>
                            <span><?php echo esc_html($vendedor_nome); ?></span>
                        </div>
                        <div class="lead-info-row">
                            <strong>✅ Confirmado em:</strong>
                            <span><?php echo isset($data['confirmado_em']) ? $data['confirmado_em'] : $current_wp_time; ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                  
                    <div style="text-align: center;">
                        <button onclick="window.close()" class="close-button">
                            <i class="fas fa-times"></i> Fechar Janela
                        </button>
                    </div>
                </div>
            
            <?php else: // error ?>
                <div class="header error">
                    <div class="icon">❌</div>
                    <h1>Erro na Confirmação</h1>
                    <p class="subtitle">Não foi possível confirmar o lead</p>
                </div>
                <div class="content">
                    <p class="message">
                        <strong>Oops!</strong> Ocorreu um erro ao tentar confirmar o lead.<br>
                        <?php echo is_string($data) ? esc_html($data) : 'Erro desconhecido na confirmação'; ?>
                    </p>
                    <div class="contact-info">
                        <h3>🛠️ O que fazer agora?</h3>
                        <p><strong>1.</strong> Verifique se o link não expirou</p>
                        <p><strong>2.</strong> Tente acessar o link novamente</p>
                        <p><strong>3.</strong> Se o problema persistir, contate o suporte</p>
                    </div>
                    <div style="text-align: center;">
                        <button onclick="window.location.reload()" class="close-button" style="background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%); margin-right: 10px;">
                            <i class="fas fa-redo"></i> Tentar Novamente
                        </button>
                        <button onclick="window.close()" class="close-button" style="background: linear-gradient(135deg, #6c757d 0%, #545b62 100%);">
                            <i class="fas fa-times"></i> Fechar
                        </button>
                    </div>
                    <div class="footer-note">
                        <i class="fas fa-exclamation-triangle"></i>
                        Se o problema persistir, entre em contato com o administrador do sistema.
                    </div>
                </div>
            <?php endif; ?>
            
        </div>
        
        <script>
            // Auto-close para casos de sucesso após 30 segundos
            if (document.querySelector(".container.success")) {
                setTimeout(function() {
                    if (confirm("Confirmação realizada com sucesso! Deseja fechar esta janela?")) {
                        window.close();
                    }
                }, 30000);
            }
            
            // Adiciona funcionalidade de fechar com ESC
            document.addEventListener("keydown", function(e) {
                if (e.key === "Escape") {
                    window.close();
                }
            });
            
            // Log para debug
            console.log("Página de confirmação carregada:", {
                status: "<?php echo esc_js($status); ?>",
                cliente: "<?php echo esc_js($cliente_nome); ?>",
                vendedor: "<?php echo esc_js($vendedor_nome); ?>",
                timestamp: "<?php echo $current_wp_time; ?>"
            });
        </script>
    </body>
    </html>
    <?php
    
    // *** IMPORTANTE: Para e encerra o script para não haver conflitos ***
    exit;
}

public function test_confirmation_link($lead_id = null) {
    if (!current_user_can('manage_options')) {
        return false;
    }
    
    // Usa um lead existente ou cria um teste
    if (!$lead_id) {
        $pending_leads = $this->get_pending_confirmation_leads();
        if (!empty($pending_leads)) {
            $lead_id = $pending_leads[0]['lead_id'];
        } else {
            return 'Nenhum lead pendente para testar';
        }
    }
    
    $lead_data = $this->get_lead_by_id($lead_id);
    if (!$lead_data) {
        return 'Lead não encontrado: ' . $lead_id;
    }
    
    $token = $lead_data['token_confirmacao'];
    $link = $this->get_confirmation_link($token);
    
    // Testa se o endpoint responde
    $response = wp_remote_get($link, array(
        'timeout' => 10,
        'sslverify' => false
    ));
    
    if (is_wp_error($response)) {
        return 'ERRO: ' . $response->get_error_message();
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    
    return array(
        'lead_id' => $lead_id,
        'token' => substr($token, 0, 20) . '...',
        'link' => $link,
        'status_code' => $status_code,
        'response_size' => strlen($body),
        'contains_html' => (strpos($body, '<html') !== false),
        'status' => ($status_code == 200 && strpos($body, '<html') !== false) ? 'FUNCIONANDO' : 'COM PROBLEMA'
    );
}

private function render_confirmation_page($status, $data = null) {
    // *** CORREÇÃO: Extração segura do nome do cliente ***
    $cliente_nome = 'Cliente';
    if (is_array($data)) {
        if (isset($data['form_data']['name'])) {
            $cliente_nome = $data['form_data']['name'];
        } elseif (isset($data['form_data']['nome'])) {
            $cliente_nome = $data['form_data']['nome'];
        } elseif (is_string($data)) {
            // Se data é uma string (caso de erro), usa valor padrão
            $cliente_nome = 'Cliente';
        }
    }
    
    $vendedor_nome = 'Vendedor';
    if (is_array($data) && isset($data['vendedor_atual']['nome'])) {
        $vendedor_nome = $data['vendedor_atual']['nome'];
    }
    
    $html = '<!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Confirmação de Lead - Hapvida</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}
body { 
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    margin: 0;
    padding: 20px;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
}
.container { 
    max-width: 600px;
    width: 100%;
    background: white;
    border-radius: 20px;
    padding: 0;
    box-shadow: 0 20px 40px rgba(0,0,0,0.1);
    overflow: hidden;
    animation: slideUp 0.6s ease-out;
}
@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
.header {
    padding: 40px 30px;
    text-align: center;
    color: white;
    position: relative;
    overflow: hidden;
}
.header::before {
    content: "";
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: repeating-linear-gradient(
        45deg,
        transparent,
        transparent 10px,
        rgba(255,255,255,0.05) 10px,
        rgba(255,255,255,0.05) 20px
    );
    animation: float 20s linear infinite;
}
@keyframes float {
    0% { transform: translateX(-50px) translateY(-50px) rotate(0deg); }
    100% { transform: translateX(-50px) translateY(-50px) rotate(360deg); }
}
.success .header { background: linear-gradient(135deg, #00C851 0%, #007E33 100%); }
.error .header { background: linear-gradient(135deg, #ff4444 0%, #CC0000 100%); }
.already-confirmed .header { background: linear-gradient(135deg, #ffbb33 0%, #FF8800 100%); }

.container.already-confirmed .header h1,
.already-confirmed .header h1,
.header h1.already-confirmed-title {
    color: #dc3545 !important;
    text-shadow: 0 2px 4px rgba(220, 53, 69, 0.3) !important;
}
.already-confirmed .subtitle {
    color: #ffffff !important;
}

.icon { 
    font-size: 64px;
    margin-bottom: 20px;
    position: relative;
    z-index: 2;
    display: inline-block;
    animation: bounce 2s infinite;
}
@keyframes bounce {
    0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
    40% { transform: translateY(-10px); }
    60% { transform: translateY(-5px); }
}

h1 { 
    margin-bottom: 10px;
    position: relative;
    z-index: 2;
    font-size: 28px;
    font-weight: 700;
    text-shadow: 0 2px 4px rgba(0,0,0,0.3);
}
.subtitle {
    position: relative;
    z-index: 2;
    opacity: 0.9;
    font-size: 16px;
    font-weight: 400;
}

.content {
    padding: 40px 30px;
}

.lead-info { 
    background: linear-gradient(135deg, #f8f9ff 0%, #e3f2fd 100%);
    padding: 25px;
    border-radius: 15px;
    margin: 25px 0;
    border-left: 5px solid #0054B8;
    box-shadow: 0 4px 15px rgba(0,84,184,0.1);
}
.lead-info strong { 
    color: #0054B8;
    display: inline-block;
    min-width: 140px;
    font-weight: 600;
}
.lead-info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding: 8px 0;
    border-bottom: 1px solid rgba(0,84,184,0.1);
}
.lead-info-row:last-child {
    margin-bottom: 0;
    border-bottom: none;
}

.message {
    text-align: center;
    font-size: 18px;
    line-height: 1.6;
    color: #333;
    margin-bottom: 20px;
}

.footer-note {
    text-align: center;
    margin-top: 30px;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 10px;
    color: #666;
    font-size: 14px;
    border: 2px dashed #dee2e6;
}

.close-button {
    display: inline-block;
    background: linear-gradient(135deg, #0054B8 0%, #003d85 100%);
    color: white;
    padding: 15px 30px;
    border: none;
    border-radius: 50px;
    font-weight: 600;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    margin-top: 20px;
    box-shadow: 0 4px 15px rgba(0,84,184,0.3);
}
.close-button:hover {
    background: linear-gradient(135deg, #003d85 0%, #002a5c 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,84,184,0.4);
    color: white;
    text-decoration: none;
}

.contact-info {
    background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
    border: 2px solid #ffc107;
    border-radius: 15px;
    padding: 20px;
    margin: 20px 0;
    text-align: center;
}
.contact-info h3 {
    color: #856404;
    margin-bottom: 10px;
    font-size: 18px;
}
.contact-info p {
    color: #856404;
    margin: 5px 0;
    font-weight: 500;
}

@media (max-width: 600px) {
    body { padding: 10px; }
    .container { border-radius: 15px; }
    .header { padding: 30px 20px; }
    .content { padding: 30px 20px; }
    h1 { font-size: 24px; }
    .icon { font-size: 48px; }
    .lead-info { padding: 20px; margin: 20px 0; }
    .lead-info-row { flex-direction: column; align-items: flex-start; }
    .lead-info strong { min-width: auto; margin-bottom: 5px; }
}
</style>


    </head>
    <body>
        <div class="container ' . $status . '">';

    switch ($status) {
        case 'success':
            $html .= '
            <div class="header">
                <div class="icon">✅</div>
                <h1>Lead Confirmado com Sucesso!</h1>
                <p class="subtitle">O recebimento foi registrado com sucesso</p>
            </div>
            <div class="content">
                <p class="message">
                    <strong>Parabéns!</strong> Você confirmou o recebimento do lead com sucesso.<br>
                    O sistema registrou sua confirmação e o lead não será redistribuído.
                </p>
                <div class="lead-info">
                    <div class="lead-info-row">
                        <strong>👤 Cliente:</strong>
                        <span>' . esc_html($cliente_nome) . '</span>
                    </div>
                    <div class="lead-info-row">
                        <strong>🏢 Vendedor:</strong>
                        <span>' . esc_html($vendedor_nome) . '</span>
                    </div>
                    <div class="lead-info-row">
                        <strong>✅ Confirmado em:</strong>
                        <span>' . date('d/m/Y H:i:s') . '</span>
                    </div>
                </div>
                <div class="contact-info">
                    <h3>📞 Próximos Passos</h3>
                    <p><strong>Entre em contato com o cliente o quanto antes!</strong></p>
                    <p>Tempo médio recomendado: até 5 minutos após confirmação</p>
                </div>
                <div style="text-align: center;">
                    <button onclick="window.close()" class="close-button">
                        <i class="fas fa-check"></i> Entendi, pode fechar
                    </button>
                </div>
                <div class="footer-note">
                    <i class="fas fa-info-circle"></i>
                    Esta janela pode ser fechada. O lead já está confirmado em nosso sistema.
                </div>
            </div>';
            break;
            
        case 'already_confirmed':
            $confirmado_em = 'Data não disponível';
            if (is_array($data) && isset($data['confirmado_em']) && $data['confirmado_em']) {
                $confirmado_em = date('d/m/Y H:i:s', strtotime($data['confirmado_em']));
            }
            
            $html .= '
         <div class="header" style="padding-bottom: 20px !important;">
    <div class="icon">⚠️</div>
    <h1 style="color: #dc3545 !important; text-shadow: 0 2px 4px rgba(220, 53, 69, 0.3) !important; margin-bottom: 0 !important;">Lead Já Confirmado</h1>
</div>
            <div class="content">
                <p class="message">
                    Tempo de 10 minutos expirados!!!<br>
                    <strong>Lead já confirmado por outro consultor.</strong>
                </p>
                <div class="lead-info">
                    <div class="lead-info-row">
                        <strong>👤 Cliente:</strong>
                        <span>' . esc_html($cliente_nome) . '</span>
                    </div>
                    <div class="lead-info-row">
                        <strong>🏢 Vendedor:</strong>
                        <span>' . esc_html($vendedor_nome) . '</span>
                    </div>
                    <div class="lead-info-row">
                        <strong>✅ Confirmado em:</strong>
                        <span>' . $confirmado_em . '</span>
                    </div>
                </div>
                <div class="contact-info">
                    <h3>ℹ️ Informação</h3>
                    <p>Lead foi contabilizado em sua planilha.</p>
                </div>
                <div style="text-align: center;">
                    <button onclick="window.close()" class="close-button">
                        <i class="fas fa-times"></i> Fechar Janela
                    </button>
                </div>
                
            </div>';
            break;
            
        case 'error':
        default:
            $error_message = is_string($data) ? $data : 'Erro desconhecido na confirmação';
            
            $html .= '
            <div class="header">
                <div class="icon">❌</div>
                <h1>Erro na Confirmação</h1>
                <p class="subtitle">Não foi possível confirmar o lead</p>
            </div>
            <div class="content">
                <p class="message">
                    <strong>Oops!</strong> Ocorreu um erro ao tentar confirmar o lead.<br>
                    ' . esc_html($error_message) . '
                </p>
                <div class="contact-info">
                    <h3>🛠️ O que fazer agora?</h3>
                    <p><strong>1.</strong> Verifique se o link não expirou</p>
                    <p><strong>2.</strong> Tente acessar o link novamente</p>
                    <p><strong>3.</strong> Se o problema persistir, contate o suporte</p>
                </div>
                <div style="text-align: center;">
                    <button onclick="window.location.reload()" class="close-button" style="background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%); margin-right: 10px;">
                        <i class="fas fa-redo"></i> Tentar Novamente
                    </button>
                    <button onclick="window.close()" class="close-button" style="background: linear-gradient(135deg, #6c757d 0%, #545b62 100%);">
                        <i class="fas fa-times"></i> Fechar
                    </button>
                </div>
                <div class="footer-note">
                    <i class="fas fa-exclamation-triangle"></i>
                    Se o problema persistir, entre em contato com o administrador do sistema.
                </div>
            </div>';
            break;
    }

    $html .= '
        </div>
        <script>
            // Auto-close para casos de sucesso após 30 segundos
            if (document.querySelector(".container.success")) {
                setTimeout(function() {
                    if (confirm("Confirmação realizada com sucesso! Deseja fechar esta janela?")) {
                        window.close();
                    }
                }, 30000);
            }
            
            // Adiciona funcionalidade de fechar com ESC
            document.addEventListener("keydown", function(e) {
                if (e.key === "Escape") {
                    window.close();
                }
            });
            
            // Log para debug
            console.log("Página de confirmação carregada:", {
                status: "' . $status . '",
                cliente: "' . esc_js($cliente_nome) . '",
                vendedor: "' . esc_js($vendedor_nome) . '",
                timestamp: "' . date('Y-m-d H:i:s') . '"
            });
        </script>
    </body>
    </html>';
    
    // *** CORREÇÃO: Headers corretos para garantir que o HTML seja exibido ***
    // Limpa qualquer output anterior
    if (ob_get_level()) {
        ob_clean();
    }
    
    // Define headers corretos
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Log para debug
    $this->log("=== RENDERIZANDO PÁGINA DE CONFIRMAÇÃO ===");
    $this->log("Status: " . $status);
    $this->log("Cliente: " . $cliente_nome);
    $this->log("Vendedor: " . $vendedor_nome);
    $this->log("Tamanho do HTML: " . strlen($html) . " caracteres");
    
    // Retorna resposta HTTP com o HTML
    return new WP_REST_Response($html, 200, array(
        'Content-Type' => 'text/html; charset=UTF-8',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        'Pragma' => 'no-cache',
        'Expires' => '0'
    ));
}
 
    public function confirm_lead_receipt($lead_id) {
    global $wpdb;
    
    // LOG DE INÍCIO
    $this->log("🔄 [CORREÇÃO] Iniciando confirmação para Lead ID: {$lead_id}");
    error_log("HAPVIDA DEBUG: Confirmando lead - {$lead_id}");
    
    $timezone = new DateTimeZone('America/Fortaleza');
    $agora_local = new DateTime('now', $timezone);
    $confirmado_em_local = $agora_local->format('Y-m-d H:i:s');
    
    // Busca dados do lead ANTES da confirmação
    $lead_data = $this->get_lead_by_id($lead_id);
    
    if (!$lead_data) {
        $this->log("❌ [CORREÇÃO] Lead não encontrado: {$lead_id}");
        error_log("HAPVIDA ERROR: Lead não encontrado - {$lead_id}");
        return false;
    }
    
    // LOG DOS DADOS DO LEAD
    $cliente_nome = $lead_data['form_data']['name'] ?? 'N/A';
    $vendedor_nome = $lead_data['vendedor_atual']['nome'] ?? 'N/A';
    $this->log("👤 [CORREÇÃO] Confirmando lead - Cliente: {$cliente_nome}, Vendedor: {$vendedor_nome}");
    error_log("HAPVIDA DEBUG: Confirmação - Cliente: {$cliente_nome}, Vendedor: {$vendedor_nome}");
    
    // Atualiza status no banco
    $result = $wpdb->update(
        $this->table_name,
        array(
            'status' => 'confirmado',
            'confirmado_em' => $confirmado_em_local
        ),
        array('lead_id' => $lead_id),
        array('%s', '%s'),
        array('%s')
    );
    
    if ($result) {
        $this->log("✅ [CORREÇÃO] Lead confirmado no banco: {$lead_id}");
        error_log("HAPVIDA SUCCESS: Lead confirmado no banco - {$lead_id}");
        
        // Registra atividade do vendedor
        if ($lead_data && isset($lead_data['vendedor_atual'])) {
            $vendedor_nome = $lead_data['vendedor_atual']['nome'];
            $vendedor_grupo = $lead_data['vendedor_atual']['grupo'];
            
            $this->track_vendor_activity($vendedor_nome, $vendedor_grupo, $lead_id);
            $this->log("📊 [CORREÇÃO] Atividade do vendedor registrada");
            error_log("HAPVIDA DEBUG: Atividade vendedor registrada");
        }
        
        // *** ENVIO DO WEBHOOK DE CONFIRMAÇÃO ***
        global $formulario_hapvida;
        if ($formulario_hapvida && $lead_data) {
            $this->log("📤 [CORREÇÃO] Iniciando envio do webhook de confirmação para lead {$lead_id}");
            error_log("HAPVIDA DEBUG: Enviando webhook confirmação - {$lead_id}");
            
            try {
                $confirmation_success = $formulario_hapvida->send_confirmation_webhook($lead_data);
                
                if ($confirmation_success) {
                    $this->log("✅ [CORREÇÃO] Webhook de confirmação enviado com sucesso para lead {$lead_id}");
                    error_log("HAPVIDA SUCCESS: Webhook confirmação enviado - {$lead_id}");
                } else {
                    $this->log("❌ [CORREÇÃO] ERRO: Falha ao enviar webhook de confirmação para lead {$lead_id}");
                    error_log("HAPVIDA ERROR: Webhook confirmação falhou - {$lead_id}");
                }
            } catch (Exception $e) {
                $this->log("❌ [CORREÇÃO] EXCEÇÃO no webhook de confirmação: " . $e->getMessage());
                error_log("HAPVIDA ERROR: Exceção webhook confirmação - " . $e->getMessage());
            }
        } else {
            $this->log("❌ [CORREÇÃO] Classe principal do formulário não disponível ou dados do lead inválidos");
            error_log("HAPVIDA ERROR: Classe principal não disponível para webhook confirmação");
        }
        
        do_action('formulario_hapvida_lead_confirmed', $lead_id);
        return true;
    } else {
        $error_msg = "ERRO: Falha ao confirmar lead: {$lead_id} - " . $wpdb->last_error;
        $this->log("❌ [CORREÇÃO] " . $error_msg);
        error_log("HAPVIDA ERROR: " . $error_msg);
        return false;
    }
}

    /**
 * *** MÉTODO GET_LEAD_BY_ID - IMPLEMENTAÇÃO COMPLETA ***
 */
public function get_lead_by_id($lead_id) {
    global $wpdb;
    
    $result = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$this->table_name} WHERE lead_id = %s", $lead_id),
        ARRAY_A
    );
    
    if ($result) {
        // Decodifica JSONs
        $result['form_data'] = json_decode($result['form_data'], true);
        $result['vendedor_atual'] = json_decode($result['vendedor_atual'], true);
        $result['vendedor_historico'] = json_decode($result['vendedor_historico'], true);
    }
    
    return $result;
}


public function get_vendor_priority_info($vendedor_nome, $grupo) {
    $activity_option = 'formulario_hapvida_vendor_activity';
    $activities = get_option($activity_option, array());
    
    $vendor_key = sanitize_key($grupo . '_' . $vendedor_nome);
    
    if (!isset($activities[$vendor_key])) {
        return array(
            'total_recebidos' => 0,
            'total_confirmados' => 0,
            'total_expirados' => 0,
            'confirmation_rate' => 0,
            'priority_level' => 'unknown'
        );
    }
    
    $vendor_data = $activities[$vendor_key];
    $total_stats = $vendor_data['total_stats'];
    
    $total_recebidos = $total_stats['total_recebidos'] ?? 0;
    $total_confirmados = $total_stats['total_confirmados'] ?? 0;
    $total_expirados = $total_stats['total_expirados'] ?? 0;
    
    // Calcula taxa de confirmação
    $confirmation_rate = ($total_recebidos > 0) ? round(($total_confirmados / $total_recebidos) * 100, 1) : 0;
    
    // Determina nível de prioridade
    $priority_level = 'unknown';
    if ($total_recebidos >= 5) { // Só calcula prioridade se tiver pelo menos 5 leads
        if ($confirmation_rate >= 80) {
            $priority_level = 'high';
        } elseif ($confirmation_rate >= 60) {
            $priority_level = 'medium';
        } elseif ($confirmation_rate >= 40) {
            $priority_level = 'low';
        } else {
            $priority_level = 'very_low';
        }
    }
    
    return array(
        'total_recebidos' => $total_recebidos,
        'total_confirmados' => $total_confirmados,
        'total_expirados' => $total_expirados,
        'confirmation_rate' => $confirmation_rate,
        'priority_level' => $priority_level,
        'vendor_name' => $vendedor_nome,
        'grupo' => $grupo
    );
}
    
   public function get_pending_confirmation_leads() {
    global $wpdb;
    
    if (empty($this->table_name)) {
        $this->ensure_table_name();
    }
    
    // * CORREÇÃO: Usa current_time() do WordPress *
    $current_time = current_time('mysql');
    
    $sql = "SELECT * FROM {$this->table_name} 
            WHERE status = 'aguardando' 
            AND expira_em > %s 
            ORDER BY created_at DESC";
    
    $results = $wpdb->get_results(
        $wpdb->prepare($sql, $current_time),
        ARRAY_A
    );
    
    $this->log("📋 Buscando leads pendentes. Hora atual: {$current_time}, Leads encontrados: " . count($results));
    
    // Debug dos primeiros 3 leads
    if (!empty($results)) {
        for ($i = 0; $i < min(3, count($results)); $i++) {
            $lead = $results[$i];
            $this->log("Lead {$i}: ID={$lead['lead_id']}, Expira={$lead['expira_em']}, Status={$lead['status']}");
        }
    }
    
    return $results;
}

    public function process_expired_leads() {
    global $wpdb;
    
    $this->log("=== INICIANDO VERIFICAÇÃO DE LEADS EXPIRADOS ===");
    
    // * CORREÇÃO: Usa current_time() do WordPress *
    $current_time = current_time('mysql');
    $this->log("🕐 Hora atual (WordPress): {$current_time}");
    
    // Busca leads expirados
    $sql = "SELECT * FROM {$this->table_name} 
            WHERE status = 'aguardando' 
            AND expira_em <= %s 
            ORDER BY created_at ASC";
    
    $expired_leads = $wpdb->get_results(
        $wpdb->prepare($sql, $current_time),
        ARRAY_A
    );
    
    $total_expired = count($expired_leads);
    $this->log("📊 Leads expirados encontrados: {$total_expired}");
    
    if ($total_expired === 0) {
        $this->log("✅ Nenhum lead expirado no momento");
        return;
    }
    
    foreach ($expired_leads as $lead) {
        // Decodifica dados JSON
        $lead['form_data'] = json_decode($lead['form_data'], true);
        $lead['vendedor_atual'] = json_decode($lead['vendedor_atual'], true);
        $lead['vendedor_historico'] = json_decode($lead['vendedor_historico'], true);
        
        $this->log("📋 Processando lead expirado: {$lead['lead_id']}");
        $this->log("   - Criado em: {$lead['created_at']}");
        $this->log("   - Expirou em: {$lead['expira_em']}");
        $this->log("   - Tentativas: {$lead['tentativas']}/{$lead['max_tentativas']}");
        
        // Verifica se ainda há tentativas disponíveis
        if ($lead['tentativas'] < $lead['max_tentativas']) {
            // Redistribui para próximo vendedor
            $this->redistribute_lead($lead);
        } else {
            // Marca como falha definitiva
            $this->mark_lead_as_failed($lead);
        }
    }
    
    $this->log("=== VERIFICAÇÃO DE LEADS EXPIRADOS CONCLUÍDA ===");
    
    // Atualiza timestamp da última verificação
    update_option('formulario_hapvida_last_check', time());
}

    public function force_check_expired_leads() {
        $this->process_expired_leads();
        
        // Reagenda o próximo cron se necessário
        if (!wp_next_scheduled('formulario_hapvida_check_expired_leads')) {
            $this->log("🔧 Reagendando cron que estava perdido");
            $this->schedule_expired_check();
        }
    }
    
private function get_expired_unconfirmed_leads() {
    global $wpdb;
    
    // * CORREÇÃO: Usa current_time() do WordPress *
    $current_time = current_time('mysql');
    
    // Busca leads que passaram do tempo de expiração
    $sql = "SELECT * FROM {$this->table_name} 
            WHERE status = 'aguardando' 
            AND expira_em <= %s 
            ORDER BY created_at ASC";
            
    $results = $wpdb->get_results(
        $wpdb->prepare($sql, $current_time),
        ARRAY_A
    );
    
    $this->log("🔍 Buscando leads expirados. Hora atual: {$current_time}, Encontrados: " . count($results));
    
    // Decodifica JSONs
    foreach ($results as &$lead) {
        $lead['form_data'] = json_decode($lead['form_data'], true);
        $lead['vendedor_atual'] = json_decode($lead['vendedor_atual'], true);
        $lead['vendedor_historico'] = json_decode($lead['vendedor_historico'], true);
    }
    
    return $results;
}

private function get_next_vendor_same_group($grupo, $vendedor_atual_nome) {
    // Usa sistema de vendedores ativos
    $novo_vendedor = $this->get_next_active_vendor($grupo, $vendedor_atual_nome);
    
    if ($novo_vendedor) {
        return $novo_vendedor;
    }
    
    // Se falhar, retorna false para tratamento posterior
    return false;
}

    private function mark_lead_as_failed($lead) {
        global $wpdb;
        
        $this->log("💀 Marcando lead como falha definitiva: {$lead['lead_id']}");
        
        $result = $wpdb->update(
            $this->table_name,
            array(
                'status' => 'falha_definitiva',
                'updated_at' => current_time('mysql')
            ),
            array('lead_id' => $lead['lead_id']),
            array('%s', '%s'),
            array('%s')
        );
        
        if ($result) {
            $this->log("✅ Lead {$lead['lead_id']} marcado como falha definitiva");
            
            // Dispara hook para notificações
            do_action('formulario_hapvida_lead_failed', $lead);
            
            return true;
        } else {
            $this->log("❌ ERRO: Falha ao marcar lead como definitivo: {$lead['lead_id']} - " . $wpdb->last_error);
            return false;
        }
    }


    // Substituir a função track_vendor_received() no arquivo lead-tracking.php

private function track_vendor_received($vendedor_nome, $grupo, $lead_id) {
    $activity_option = 'formulario_hapvida_vendor_activity';
    $activities = get_option($activity_option, array());
    
    $vendor_key = sanitize_key($grupo . '_' . $vendedor_nome);
    $today = current_time('Y-m-d');
    
    // Inicialização: Garante estrutura correta
    if (!isset($activities[$vendor_key])) {
        $activities[$vendor_key] = array(
            'nome' => $vendedor_nome,
            'grupo' => $grupo,
            'daily_stats' => array(),
            'total_stats' => array(
                'total_recebidos' => 0,
                'total_confirmados' => 0,
                'total_expirados' => 0
            )
        );
    }
    
    if (!isset($activities[$vendor_key]['daily_stats'][$today])) {
        $activities[$vendor_key]['daily_stats'][$today] = array(
            'total_recebidos' => 0,
            'total_confirmados' => 0,
            'total_expirados' => 0,
            'leads_recebidos' => array()
        );
    }
    
    // Registro: Adiciona lead recebido
    $activities[$vendor_key]['daily_stats'][$today]['total_recebidos']++;
    $activities[$vendor_key]['total_stats']['total_recebidos']++;
    
    // Registra ID do lead
    $activities[$vendor_key]['daily_stats'][$today]['leads_recebidos'][] = array(
        'lead_id' => $lead_id,
        'recebido_em' => current_time('H:i:s'),
        'timestamp' => current_time('mysql')
    );
    
    // Salva dados atualizados
    update_option($activity_option, $activities);
}

    public function force_confirm_lead($lead_id) {
        return $this->confirm_lead_receipt($lead_id);
    }

    public function debug_vendor_activity() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $today = current_time('Y-m-d');
    
    // *** DEBUG: Verifica dados brutos primeiro ***
    $activity_option = 'formulario_hapvida_vendor_activity';
    $raw_activities = get_option($activity_option, array());
    
    echo '<div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 15px; margin-bottom: 20px;">';
    echo '<h4 style="margin: 0 0 10px 0; color: #856404;">🔍 Debug - Dados Brutos da Opção:</h4>';
    echo '<div style="font-size: 13px;">';
    echo '<strong>Nome da opção:</strong> ' . $activity_option . '<br>';
    echo '<strong>Total de registros:</strong> ' . count($raw_activities) . '<br>';
    echo '<strong>Data atual:</strong> ' . $today . '<br>';
    
    if (!empty($raw_activities)) {
        echo '<details style="margin-top: 10px;">';
        echo '<summary style="cursor: pointer; font-weight: bold;">📋 Ver dados brutos (clique para expandir)</summary>';
        echo '<pre style="background: #f8f9fa; padding: 10px; border-radius: 4px; overflow: auto; font-size: 11px; margin-top: 10px;">';
        echo htmlspecialchars(print_r($raw_activities, true));
        echo '</pre>';
        echo '</details>';
    }
    echo '</div>';
    echo '</div>';
    
    $vendor_stats = $this->get_vendor_daily_stats($today);
    
    echo '<div class="vendor-activity-table-container">';
    echo '<h3 style="color: #0054B8; margin-bottom: 20px;">📊 Relatório de Performance dos Vendedores - ' . date('d/m/Y', strtotime($today)) . '</h3>';
    
    // *** DEBUG: Mostra informações de debug do banco ***
    global $wpdb;
    $table_name = $wpdb->prefix . 'hapvida_lead_tracking';
    
    $debug_info = array(
        'total_leads_hoje' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE DATE(enviado_em) = %s", $today)),
        'confirmados_hoje' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE DATE(confirmado_em) = %s AND status = 'confirmado'", $today)),
        'redistribuidos_hoje' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE DATE(updated_at) = %s AND status IN ('redistribuido', 'falha_definitiva')", $today)),
        'aguardando_hoje' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE DATE(enviado_em) = %s AND status = 'aguardando'", $today))
    );
    
    echo '<div style="background: #f0f6ff; border: 1px solid #0054B8; border-radius: 8px; padding: 15px; margin-bottom: 20px;">';
    echo '<h4 style="margin: 0 0 10px 0; color: #0054B8;">🔍 Debug - Dados do Sistema Hoje:</h4>';
    echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; font-size: 13px;">';
    echo '<div><strong>Total de leads:</strong> ' . $debug_info['total_leads_hoje'] . '</div>';
    echo '<div><strong>Confirmados:</strong> ' . $debug_info['confirmados_hoje'] . '</div>';
    echo '<div><strong>Redistribuídos/Falharam:</strong> ' . $debug_info['redistribuidos_hoje'] . '</div>';
    echo '<div><strong>Aguardando:</strong> ' . $debug_info['aguardando_hoje'] . '</div>';
    echo '</div>';
    echo '</div>';
    
    if (empty($vendor_stats) && empty($raw_activities)) {
        echo '<div style="text-align: center; padding: 40px; background: #f8f9ff; border: 2px dashed #0054B8; border-radius: 8px;">';
        echo '<i class="dashicons dashicons-chart-bar" style="font-size: 48px; opacity: 0.3; color: #0054B8;"></i>';
        echo '<p style="margin: 10px 0 0 0; color: #666;"><strong>Nenhuma atividade registrada hoje</strong></p>';
        echo '<p style="margin: 5px 0 0 0; color: #666; font-size: 14px;">Os dados aparecerão quando vendedores começarem a confirmar leads.</p>';
        
        // *** NOVO: Botão para forçar criação de dados de teste ***
        echo '<div style="margin-top: 20px;">';
        echo '<button type="button" id="create-test-data" class="button button-secondary" style="background: #ffc107; border-color: #ffc107; color: #212529;">';
        echo '<i class="dashicons dashicons-admin-tools"></i> Criar Dados de Teste';
        echo '</button>';
        echo '</div>';
        
        echo '</div>';
        echo '</div>';
        return;
    }
    
    // Resto do código da tabela...
    // [Continue com o código da tabela que já estava funcionando]
}

    /**
 * *** NOVA: AJAX para criar dados de teste ***
 */
 
    /**
 * Método público para limpar todas as atividades dos vendedores
 * Usado pelo admin para resetar o sistema
 */
    /**
 * Método público para limpar todas as atividades dos vendedores
 * Usado pelo admin para resetar o sistema
 */
public function clear_all_vendor_activities() {
    $activity_option = 'formulario_hapvida_vendor_activity';
    
    // Verifica se há dados antes de tentar limpar
    $activities = get_option($activity_option, array());
    $count_before = count($activities);
    
    $this->log("🗑️ LIMPEZA COMPLETA: Removendo todas as atividades dos vendedores ({$count_before} registros)");
    
    if ($count_before == 0) {
        $this->log("🗑️ Não há registros para limpar - sistema já está limpo");
        return true; // Retorna true porque o objetivo (ter 0 registros) foi alcançado
    }
    
    // Remove a option completamente
    $result = delete_option($activity_option);
    
    // Limpa do cache também
    wp_cache_delete($activity_option, 'options');
    
    // Remove outros dados relacionados
    delete_option('formulario_hapvida_last_check');
    delete_option('formulario_hapvida_alternative_active');
    
    // Verifica se realmente foi limpo
    $activities_after = get_option($activity_option, array());
    $count_after = count($activities_after);
    
    $success = ($count_after < $count_before) || ($count_after == 0);
    
    $this->log("🗑️ Resultado da limpeza: " . ($success ? 'SUCESSO' : 'FALHA') . 
               " - Antes: {$count_before}, Depois: {$count_after}");
    
    return $success;
}

/**
 * Método público para limpar dados da tabela de lead tracking
 * @param bool $today_only Se true, limpa apenas dados de hoje
 * @return array Resultado da limpeza
 */
public function clear_lead_tracking_data($today_only = false) {
    global $wpdb;
    
    // Garante que table_name está correto
    $this->ensure_table_name();
    
    $count_before = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
    
    if ($today_only) {
        $today = current_time('Y-m-d');
        $this->log("🗑️ Limpando dados de lead tracking de hoje ({$today})...");
        
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE DATE(created_at) = %s",
            $today
        ));
    } else {
        $this->log("🗑️ Limpando TODOS os dados de lead tracking...");
        
        // Tenta TRUNCATE primeiro (mais rápido)
        $result = $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        
        if ($result === false) {
            // Se TRUNCATE falhar, usa DELETE
            $result = $wpdb->query("DELETE FROM {$this->table_name}");
        }
    }
    
    $count_after = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
    $cleared = $count_before - $count_after;
    
    $this->log("🗑️ Limpeza concluída - Removidos: {$cleared} registros");
    
    return array(
        'success' => $result !== false,
        'before' => $count_before,
        'after' => $count_after,
        'cleared' => $cleared
    );
}

    public function get_lead_tracking_stats() {
        global $wpdb;
        
        $today = current_time('Y-m-d');
        
        $stats = array();
        
        // Total hoje
        $stats['total_hoje'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE DATE(created_at) = %s",
                $today
            )
        );
        
        // Confirmados hoje
        $stats['confirmados_hoje'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE DATE(confirmado_em) = %s",
                $today
            )
        );
        
        // Pendentes
        $stats['pendentes'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'aguardando'"
        );
        
        // Redistribuídos hoje
        $stats['redistribuidos_hoje'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE DATE(updated_at) = %s AND tentativas > 1",
                $today
            )
        );
        
        // Falhas definitivas
        $stats['falhas_definitivas'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'falha_definitiva'"
        );
        
        // Taxa de confirmação
        if ($stats['total_hoje'] > 0) {
            $stats['taxa_confirmacao'] = round(($stats['confirmados_hoje'] / $stats['total_hoje']) * 100, 1);
        } else {
            $stats['taxa_confirmacao'] = 0;
        }
        
        return $stats;
    }
}

// ============================================================================
// CÓDIGOS DE DEBUG TEMPORÁRIOS - PARA TESTAR E DIAGNOSTICAR
// ============================================================================

// Hook que executa depois do WordPress carregar completamente
add_action('wp_loaded', function() {
    
    // *** VERIFICAÇÃO SEGURA DE LEADS ***
    if (isset($_GET['check_leads_safe']) && function_exists('current_user_can') && current_user_can('manage_options')) {
        header('Content-Type: text/html; charset=UTF-8');
        
        echo '<html><head><title>Debug Leads</title>';
        echo '<style>body{font-family:Arial;padding:20px;background:#f5f5f5;} .box{background:white;padding:15px;margin:10px 0;border-radius:8px;border-left:4px solid #0054B8;} .error{border-left-color:#dc3545;} .success{border-left-color:#28a745;}</style>';
        echo '</head><body>';
        
        echo '<h1>🔍 Verificação Segura de Leads</h1>';
        
        try {
            global $formulario_hapvida_lead_tracking, $wpdb;
            
            if (!$formulario_hapvida_lead_tracking) {
                echo '<div class="box error">❌ Sistema de lead tracking não carregado</div>';
            } else {
                echo '<div class="box success">✅ Sistema de lead tracking OK</div>';
                
                // Verifica tabela
                $table_name = $wpdb->prefix . 'hapvida_lead_tracking';
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
                
                if ($table_exists) {
                    echo '<div class="box success">✅ Tabela existe: ' . $table_name . '</div>';
                    
                    // Conta leads
                    $total_leads = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
                    $aguardando = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'aguardando'");
                    
                    echo '<div class="box">📊 Total de leads: ' . $total_leads . '</div>';
                    echo '<div class="box">⏰ Aguardando confirmação: ' . $aguardando . '</div>';
                    
                    if ($aguardando > 0) {
                        // Lista leads aguardando
                        $leads = $wpdb->get_results("SELECT * FROM $table_name WHERE status = 'aguardando' ORDER BY created_at DESC LIMIT 5", ARRAY_A);
                        
                        echo '<h3>📋 Últimos 5 leads aguardando:</h3>';
                        foreach ($leads as $lead) {
                            $form_data = json_decode($lead['form_data'], true);
                            $cliente = $form_data['name'] ?? 'N/A';
                            $tempo_restante = strtotime($lead['expira_em']) - time();
                            $status_tempo = $tempo_restante > 0 ? '✅ Válido por ' . floor($tempo_restante/60) . ' min' : '🚨 Expirado há ' . floor(abs($tempo_restante)/60) . ' min';
                            
                            echo '<div class="box">';
                            echo '<strong>Cliente:</strong> ' . esc_html($cliente) . '<br>';
                            echo '<strong>Lead ID:</strong> ' . esc_html($lead['lead_id']) . '<br>';
                            echo '<strong>Criado:</strong> ' . $lead['created_at'] . '<br>';
                            echo '<strong>Expira:</strong> ' . $lead['expira_em'] . '<br>';
                            echo '<strong>Tentativas:</strong> ' . $lead['tentativas'] . '/' . $lead['max_tentativas'] . '<br>';
                            echo '<strong>Status:</strong> ' . $status_tempo . '<br>';
                            echo '</div>';
                        }
                    }
                    
                } else {
                    echo '<div class="box error">❌ Tabela não existe: ' . $table_name . '</div>';
                    echo '<div class="box">🔧 <a href="?create_table_safe=1">Criar tabela agora</a></div>';
                }
            }
            
            // Verifica cron
            $next_cron = wp_next_scheduled('formulario_hapvida_check_expired_leads');
            if ($next_cron) {
                $tempo_ate_cron = $next_cron - time();
                echo '<div class="box success">✅ Cron agendado para: ' . date('d/m/Y H:i:s', $next_cron) . ' (em ' . floor($tempo_ate_cron/60) . ' minutos)</div>';
            } else {
                echo '<div class="box error">❌ Cron não está agendado</div>';
                echo '<div class="box">🔧 <a href="?fix_cron_safe=1">Reagendar cron agora</a></div>';
            }
            
        } catch (Exception $e) {
            echo '<div class="box error">❌ Erro: ' . esc_html($e->getMessage()) . '</div>';
        }
        
        echo '<hr>';
        echo '<h3>🔧 Ações:</h3>';
        echo '<a href="?process_expired_safe=1" style="background:#dc3545;color:white;padding:10px;text-decoration:none;border-radius:5px;margin:5px;">🔄 Processar Expirados</a> ';
        echo '<a href="' . admin_url('options-general.php?page=formulario-hapvida-admin') . '" style="background:#6c757d;color:white;padding:10px;text-decoration:none;border-radius:5px;margin:5px;">← Admin</a>';
        
        echo '</body></html>';
        exit;
    }

    // *** PROCESSAMENTO SEGURO ***
    if (isset($_GET['process_expired_safe']) && function_exists('current_user_can') && current_user_can('manage_options')) {
        header('Content-Type: text/html; charset=UTF-8');
        
        echo '<html><head><title>Processar Leads</title>';
        echo '<style>body{font-family:Arial;padding:20px;} .log{background:#f0f0f0;padding:10px;margin:5px 0;border-radius:5px;}</style>';
        echo '</head><body>';
        
        echo '<h1>🔄 Processando Leads Expirados</h1>';
        
        try {
            global $formulario_hapvida_lead_tracking;
            
            if ($formulario_hapvida_lead_tracking) {
                echo '<div class="log">Iniciando processamento...</div>';
                flush();
                
                $formulario_hapvida_lead_tracking->process_expired_leads();
                
                echo '<div class="log">✅ Processamento concluído!</div>';
                echo '<div class="log">📄 Verifique o log: wp-content/formulario_hapvida.log</div>';
            } else {
                echo '<div class="log">❌ Sistema não disponível</div>';
            }
            
        } catch (Exception $e) {
            echo '<div class="log">❌ Erro: ' . esc_html($e->getMessage()) . '</div>';
        }
        
        echo '<br><a href="?check_leads_safe=1">← Voltar</a>';
        echo '</body></html>';
        exit;
    }

    // *** CRIAR TABELA MANUALMENTE ***
    if (isset($_GET['create_table_safe']) && function_exists('current_user_can') && current_user_can('manage_options')) {
        header('Content-Type: text/html; charset=UTF-8');
        
        echo '<html><head><title>Criar Tabela</title></head><body style="font-family:Arial;padding:20px;">';
        echo '<h1>🔧 Criando Tabela de Lead Tracking</h1>';
        
        try {
            global $formulario_hapvida_lead_tracking;
            
            if ($formulario_hapvida_lead_tracking) {
                $formulario_hapvida_lead_tracking->create_tracking_table();
                echo '<p>✅ Tentativa de criação executada!</p>';
                echo '<p><a href="?check_leads_safe=1">← Verificar resultado</a></p>';
            } else {
                echo '<p>❌ Sistema não disponível</p>';
            }
            
        } catch (Exception $e) {
            echo '<p>❌ Erro: ' . esc_html($e->getMessage()) . '</p>';
        }
        
        echo '</body></html>';
        exit;
    }

    // *** REAGENDAR CRON ***
    if (isset($_GET['fix_cron_safe']) && function_exists('current_user_can') && current_user_can('manage_options')) {
        header('Content-Type: text/html; charset=UTF-8');
        
        echo '<html><head><title>Reagendar Cron</title></head><body style="font-family:Arial;padding:20px;">';
        echo '<h1>⏰ Reagendando Cron</h1>';
        
        try {
            global $formulario_hapvida_lead_tracking;
            
            // Remove crons antigos
            wp_clear_scheduled_hook('formulario_hapvida_check_expired_leads');
            echo '<p>✅ Crons antigos removidos</p>';
            
            if ($formulario_hapvida_lead_tracking) {
                $formulario_hapvida_lead_tracking->schedule_expired_check();
                echo '<p>✅ Novo cron agendado</p>';
                
                // Verifica se funcionou
                $next_cron = wp_next_scheduled('formulario_hapvida_check_expired_leads');
                if ($next_cron) {
                    echo '<p>✅ Confirmado para: ' . date('d/m/Y H:i:s', $next_cron) . '</p>';
                } else {
                    echo '<p>❌ Falha ao agendar</p>';
                }
            }
            
        } catch (Exception $e) {
            echo '<p>❌ Erro: ' . esc_html($e->getMessage()) . '</p>';
        }
        
        echo '<p><a href="?check_leads_safe=1">← Voltar</a></p>';
        echo '</body></html>';
        exit;
    }

}); // FIM do add_action('wp_loaded')

// Instancia a classe
if (!isset($GLOBALS['formulario_hapvida_lead_tracking'])) {
    $GLOBALS['formulario_hapvida_lead_tracking'] = new Formulario_Hapvida_Lead_Tracking();
}