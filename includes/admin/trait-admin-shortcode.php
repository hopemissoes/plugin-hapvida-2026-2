<?php
if (!defined('ABSPATH')) exit;

trait AdminShortcodeTrait {

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

                                console.log('🎉 Valores atualizados: Daily=' + response.data.daily_count + ', Monthly=' + response.data.monthly_count);
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
                            console.log('📥 Resposta recebida:', response);

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

}
