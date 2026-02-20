<?php
if (!defined('ABSPATH')) exit;

trait AdminPageRendererTrait {

    // Backend leads section
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

    // Main admin page
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
                <button class="hapvida-tab" data-tab="monitoramento">
                    <span class="dashicons dashicons-visibility"></span>
                    <span>Monitoramento</span>
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

                <!-- TAB: CONFIGURAÇÕES (Accordion limpo) -->
                <div class="hapvida-tab-panel" data-tab="config">

                <div class="hapvida-accordion-list">

                    <!-- 1. Webhooks -->
                    <div class="hapvida-accordion open">
                        <button type="button" class="hapvida-accordion-header">
                            <span class="hapvida-accordion-title"><span class="dashicons dashicons-admin-settings"></span> Webhooks</span>
                            <span class="hapvida-accordion-arrow dashicons dashicons-arrow-down-alt2"></span>
                        </button>
                        <div class="hapvida-accordion-body">
                            <form action="options.php" method="post">
                                <?php
                                settings_fields('formulario_hapvida_settings');
                                do_settings_sections('formulario-hapvida-admin');
                                submit_button('Salvar Webhooks');
                                ?>
                            </form>
                        </div>
                    </div>

                    <!-- 2. Redirecionamento -->
                    <?php
                    $options_redirect = get_option($this->option_name);
                    $redirect_ativo = isset($options_redirect['redirect_obrigado']) && $options_redirect['redirect_obrigado'] === '1';
                    ?>
                    <div class="hapvida-accordion">
                        <button type="button" class="hapvida-accordion-header">
                            <span class="hapvida-accordion-title">
                                <span class="dashicons dashicons-migrate"></span> Redirecionamento
                                <span class="hapvida-accordion-badge <?php echo $redirect_ativo ? 'badge-on' : 'badge-off'; ?>"><?php echo $redirect_ativo ? 'Ativo' : 'Off'; ?></span>
                            </span>
                            <span class="hapvida-accordion-arrow dashicons dashicons-arrow-down-alt2"></span>
                        </button>
                        <div class="hapvida-accordion-body" style="display:none;">
                            <form action="options.php" method="post">
                                <?php settings_fields('formulario_hapvida_settings'); ?>
                                <input type="hidden" name="<?php echo $this->option_name; ?>[redirect_obrigado]" value="0" />
                                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 12px 0;">
                                    <input type="checkbox" id="redirect_obrigado" name="<?php echo $this->option_name; ?>[redirect_obrigado]" value="1" <?php checked($redirect_ativo, true); ?> style="width:18px;height:18px;" />
                                    <span>Redirecionar para pagina de obrigado antes do WhatsApp</span>
                                </label>
                                <p class="description" style="margin-bottom: 14px;">Se desativado, o lead vai direto para o WhatsApp do vendedor.</p>
                                <?php submit_button('Salvar', 'primary', 'submit', false); ?>
                            </form>
                        </div>
                    </div>

                    <!-- 3. Relatórios de Leads -->
                    <?php
                    $options = get_option($this->option_name);
                    $has_drv = !empty($options['drv_username']) && !empty($options['drv_password']);
                    $has_souza = !empty($options['seusouza_username']) && !empty($options['seusouza_password']);
                    $reports_configured = $has_drv && $has_souza;
                    ?>
                    <div class="hapvida-accordion">
                        <button type="button" class="hapvida-accordion-header">
                            <span class="hapvida-accordion-title">
                                <span class="dashicons dashicons-chart-bar"></span> Relatorios
                                <span class="hapvida-accordion-badge <?php echo $reports_configured ? 'badge-on' : 'badge-off'; ?>"><?php echo $reports_configured ? 'OK' : 'Pendente'; ?></span>
                            </span>
                            <span class="hapvida-accordion-arrow dashicons dashicons-arrow-down-alt2"></span>
                        </button>
                        <div class="hapvida-accordion-body" style="display:none;">
                            <p style="color:#64748b;margin-bottom:12px;">Shortcode: <code style="background:#f1f5f9;padding:2px 8px;border-radius:4px;font-size:12px;">[hapvida_reports]</code></p>
                            <form action="options.php" method="post">
                                <?php settings_fields('formulario_hapvida_settings'); ?>
                                <div class="hapvida-credentials-grid">
                                    <div class="hapvida-cred-group" style="border-left:3px solid #3b82f6;">
                                        <strong style="color:#1e40af;">DRV</strong>
                                        <?php
                                        $drv_u = isset($options['drv_username']) ? esc_attr($options['drv_username']) : '';
                                        $drv_p = isset($options['drv_password']) ? esc_attr($options['drv_password']) : '';
                                        ?>
                                        <input type="text" class="regular-text" name="<?php echo $this->option_name; ?>[drv_username]" value="<?php echo $drv_u; ?>" placeholder="Usuario" />
                                        <input type="password" class="regular-text" name="<?php echo $this->option_name; ?>[drv_password]" value="<?php echo $drv_p; ?>" placeholder="Senha" autocomplete="new-password" />
                                    </div>
                                    <div class="hapvida-cred-group" style="border-left:3px solid #f97316;">
                                        <strong style="color:#c2410c;">Seu Souza</strong>
                                        <?php
                                        $sz_u = isset($options['seusouza_username']) ? esc_attr($options['seusouza_username']) : '';
                                        $sz_p = isset($options['seusouza_password']) ? esc_attr($options['seusouza_password']) : '';
                                        ?>
                                        <input type="text" class="regular-text" name="<?php echo $this->option_name; ?>[seusouza_username]" value="<?php echo $sz_u; ?>" placeholder="Usuario" />
                                        <input type="password" class="regular-text" name="<?php echo $this->option_name; ?>[seusouza_password]" value="<?php echo $sz_p; ?>" placeholder="Senha" autocomplete="new-password" />
                                    </div>
                                </div>
                                <div style="margin-top:14px;">
                                    <?php submit_button('Salvar Credenciais', 'primary', 'submit', false); ?>
                                </div>
                            </form>
                        </div>
                    </div>

                </div>

                <style>
                    .hapvida-accordion-list { display: flex; flex-direction: column; gap: 8px; }
                    .hapvida-accordion { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
                    .hapvida-accordion-header {
                        width: 100%; display: flex; justify-content: space-between; align-items: center;
                        padding: 14px 20px; background: #fff; border: none; cursor: pointer;
                        font-size: 15px; font-weight: 600; color: #1a202c; transition: background 0.2s;
                    }
                    .hapvida-accordion-header:hover { background: #f8fafc; }
                    .hapvida-accordion-title { display: flex; align-items: center; gap: 8px; }
                    .hapvida-accordion-title .dashicons { color: #ff6b00; font-size: 18px; width: 18px; height: 18px; }
                    .hapvida-accordion-arrow { transition: transform 0.3s; color: #94a3b8; }
                    .hapvida-accordion.open .hapvida-accordion-arrow { transform: rotate(180deg); }
                    .hapvida-accordion-body { padding: 0 20px 20px; }
                    .hapvida-accordion-badge {
                        font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px; margin-left: 6px;
                    }
                    .hapvida-accordion-badge.badge-on { background: #dcfce7; color: #166534; }
                    .hapvida-accordion-badge.badge-off { background: #fef3c7; color: #92400e; }
                    .hapvida-credentials-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
                    .hapvida-cred-group {
                        display: flex; flex-direction: column; gap: 8px;
                        padding: 12px 16px; background: #f8fafc; border-radius: 8px;
                    }
                    .hapvida-cred-group input { width: 100% !important; max-width: 100% !important; }
                    @media (max-width: 768px) { .hapvida-credentials-grid { grid-template-columns: 1fr; } }
                </style>

                <script>
                (function(){
                    document.querySelectorAll('.hapvida-accordion-header').forEach(function(btn){
                        btn.addEventListener('click', function(){
                            var acc = this.closest('.hapvida-accordion');
                            var body = acc.querySelector('.hapvida-accordion-body');
                            var isOpen = acc.classList.contains('open');
                            if (isOpen) {
                                acc.classList.remove('open');
                                body.style.display = 'none';
                            } else {
                                acc.classList.add('open');
                                body.style.display = 'block';
                            }
                        });
                    });
                })();
                </script>

                </div>

                <!-- TAB: MONITORAMENTO -->
                <div class="hapvida-tab-panel" data-tab="monitoramento">

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

                            <?php if (($count_pendentes + $count_entregues + $count_expirados) > 0 || !empty($deactivation_log)): ?>
                            <div style="margin-bottom: 20px;">
                                <button type="button" id="admin-clear-delivery-records" class="button button-secondary" style="color: #dc2626; border-color: #fca5a5; background: #fff;">
                                    <span class="dashicons dashicons-trash" style="margin-top: 3px;"></span> Limpar Registros de Entregas
                                </button>
                            </div>
                            <script>
                            (function(){
                                var btn = document.getElementById('admin-clear-delivery-records');
                                if (!btn) return;
                                btn.addEventListener('click', function(){
                                    if (!confirm('Tem certeza que deseja limpar todos os registros de monitoramento de entregas?\n\nIsso vai remover:\n- Todas as entregas (pendentes, entregues, expiradas)\n- Todo o log de inativacoes automaticas\n\nEsta acao nao pode ser desfeita.')) return;
                                    btn.disabled = true;
                                    btn.textContent = 'Limpando...';
                                    var formData = new FormData();
                                    formData.append('action', 'clear_delivery_records');
                                    fetch('<?php echo admin_url("admin-ajax.php"); ?>', { method: 'POST', body: formData })
                                    .then(function(r) { return r.json(); })
                                    .then(function(res) {
                                        if (res.success) {
                                            location.reload();
                                        } else {
                                            alert('Erro ao limpar registros');
                                            btn.disabled = false;
                                            btn.innerHTML = '<span class="dashicons dashicons-trash" style="margin-top: 3px;"></span> Limpar Registros de Entregas';
                                        }
                                    })
                                    .catch(function() {
                                        alert('Erro ao limpar registros');
                                        btn.disabled = false;
                                        btn.innerHTML = '<span class="dashicons dashicons-trash" style="margin-top: 3px;"></span> Limpar Registros de Entregas';
                                    });
                                });
                            })();
                            </script>
                            <?php endif; ?>

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

}
