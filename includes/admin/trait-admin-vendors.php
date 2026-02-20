<?php
if (!defined('ABSPATH')) exit;

trait AdminVendorsTrait {

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

            <!-- Toggle Auto-Ativação Seu Souza (Frontend) -->
            <?php $auto_activate_enabled_fe = get_option('hapvida_auto_activate_seu_souza', false); ?>
            <?php $daily_limit_fe = get_option('hapvida_seu_souza_daily_limit', 30); ?>
            <div class="vm-auto-activate-box">
                <div class="vm-auto-activate-row">
                    <div class="vm-auto-activate-info">
                        <span class="vm-auto-activate-title">Auto-Ativação Seu Souza</span>
                        <span class="vm-auto-activate-desc">Dias úteis 08h-12h</span>
                    </div>
                    <div class="vm-auto-activate-toggle">
                        <label class="vm-switch">
                            <input type="checkbox" id="auto-activate-toggle-fe"
                                <?php checked($auto_activate_enabled_fe, true); ?>>
                            <span class="vm-switch-track" style="background-color: <?php echo $auto_activate_enabled_fe ? '#22c55e' : '#d1d5db'; ?>;">
                                <span class="vm-switch-thumb" style="left: <?php echo $auto_activate_enabled_fe ? '22px' : '2px'; ?>;"></span>
                            </span>
                        </label>
                        <span id="auto-activate-label-fe" style="font-size:12px; font-weight:600; color: <?php echo $auto_activate_enabled_fe ? '#22c55e' : '#94a3b8'; ?>;">
                            <?php echo $auto_activate_enabled_fe ? 'ON' : 'OFF'; ?>
                        </span>
                    </div>
                </div>
                <div class="vm-daily-limit-row">
                    <div class="vm-daily-limit-info">
                        <span class="vm-auto-activate-title">Limite Diário (Seu Souza)</span>
                        <span class="vm-auto-activate-desc">Desativa ao atingir este número de leads</span>
                    </div>
                    <div class="vm-daily-limit-control">
                        <button class="vm-limit-btn vm-limit-minus" id="limit-minus-btn">-</button>
                        <span class="vm-limit-value" id="daily-limit-value-fe"><?php echo intval($daily_limit_fe); ?></span>
                        <button class="vm-limit-btn vm-limit-plus" id="limit-plus-btn">+</button>
                    </div>
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

            /* Auto-Activate Box (Frontend) */
            .vm-auto-activate-box {
                background: #f8f9fb;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                padding: 16px 20px;
                margin-bottom: 24px;
            }
            .vm-auto-activate-row,
            .vm-daily-limit-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
            }
            .vm-daily-limit-row {
                margin-top: 14px;
                padding-top: 14px;
                border-top: 1px solid #e2e8f0;
            }
            .vm-auto-activate-info,
            .vm-daily-limit-info {
                display: flex;
                flex-direction: column;
                gap: 2px;
            }
            .vm-auto-activate-title {
                font-weight: 600;
                font-size: 13px;
                color: #1e293b;
            }
            .vm-auto-activate-desc {
                font-size: 11px;
                color: #94a3b8;
            }
            .vm-auto-activate-toggle {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .vm-switch {
                position: relative;
                display: inline-block;
                cursor: pointer;
            }
            .vm-switch input {
                opacity: 0;
                width: 0;
                height: 0;
                position: absolute;
            }
            .vm-switch-track {
                display: block;
                width: 44px;
                height: 24px;
                border-radius: 12px;
                transition: background-color 0.3s;
                position: relative;
            }
            .vm-switch-thumb {
                display: block;
                width: 20px;
                height: 20px;
                background: white;
                border-radius: 50%;
                position: absolute;
                top: 2px;
                transition: left 0.3s;
                box-shadow: 0 1px 3px rgba(0,0,0,0.2);
            }
            .vm-daily-limit-control {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .vm-limit-btn {
                width: 30px;
                height: 30px;
                border-radius: 8px;
                border: 1px solid #e2e8f0;
                background: white;
                font-size: 16px;
                font-weight: 700;
                color: #475569;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: all 0.2s;
            }
            .vm-limit-btn:hover {
                background: #f1f5f9;
                border-color: #cbd5e1;
            }
            .vm-limit-value {
                font-size: 18px;
                font-weight: 700;
                color: #1e293b;
                min-width: 36px;
                text-align: center;
            }
            @media (max-width: 480px) {
                .vm-auto-activate-row,
                .vm-daily-limit-row {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 8px;
                }
                .vm-auto-activate-toggle,
                .vm-daily-limit-control {
                    align-self: flex-end;
                }
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
                var vendorsAjaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';
                var vendorsData = [];

                // Função para carregar lista de vendedores
                function loadVendorsList() {
                    $.ajax({
                        url: vendorsAjaxUrl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'get_vendors_list_frontend'
                        },
                        success: function (response) {
                            if (response && response.success) {
                                vendorsData = response.data.vendors || [];
                                renderVendors();
                            } else {
                                console.error('Vendedores: resposta inesperada', response);
                            }
                        },
                        error: function (xhr) {
                            console.error('Vendedores: erro AJAX', xhr.status, xhr.responseText);
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
                        url: vendorsAjaxUrl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'toggle_vendor_status_frontend',
                            vendedor_id: vendorId,
                            grupo: grupo,
                            vendor_action: 'toggle'
                        },
                        success: function (response) {
                            if (response && response.success) {
                                loadVendorsList();
                            } else {
                                alert('Erro: ' + (response && response.data ? response.data : 'Resposta inesperada'));
                                loadVendorsList();
                            }
                        },
                        error: function (xhr) {
                            alert('Erro ao atualizar status do vendedor (HTTP ' + xhr.status + ')');
                        },
                        complete: function () {
                            $btn.prop('disabled', false);
                        }
                    });
                });

                // Botão de refresh
                $(document).on('click', '#refresh-vendors-list', function () {
                    var $btn = $(this);
                    $btn.prop('disabled', true);
                    $btn.find('i').addClass('fa-spin');
                    loadVendorsList();
                    setTimeout(function () {
                        $btn.find('i').removeClass('fa-spin');
                        $btn.prop('disabled', false);
                    }, 1500);
                });

                // Toggle auto-ativação Seu Souza (frontend)
                $('#auto-activate-toggle-fe').on('change', function () {
                    var $toggle = $(this);
                    var enabled = $toggle.is(':checked');
                    var $track = $toggle.next('.vm-switch-track');
                    var $thumb = $track.find('.vm-switch-thumb');
                    var $label = $('#auto-activate-label-fe');

                    $track.css('background-color', enabled ? '#22c55e' : '#d1d5db');
                    $thumb.css('left', enabled ? '22px' : '2px');
                    $label.text(enabled ? 'ON' : 'OFF');
                    $label.css('color', enabled ? '#22c55e' : '#94a3b8');

                    $.ajax({
                        url: vendorsAjaxUrl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'toggle_auto_activate_seu_souza_frontend',
                            enabled: enabled ? 'true' : 'false'
                        },
                        success: function (response) {
                            if (response && response.success) {
                                loadVendorsList();
                            }
                        },
                        error: function () {
                            $toggle.prop('checked', !enabled);
                            $track.css('background-color', !enabled ? '#22c55e' : '#d1d5db');
                            $thumb.css('left', !enabled ? '22px' : '2px');
                            $label.text(!enabled ? 'ON' : 'OFF');
                            $label.css('color', !enabled ? '#22c55e' : '#94a3b8');
                            alert('Erro ao salvar. Tente novamente.');
                        }
                    });
                });

                // Controle do limite diário (frontend)
                function updateDailyLimit(newValue) {
                    $.ajax({
                        url: vendorsAjaxUrl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'update_seu_souza_daily_limit_frontend',
                            limit: newValue
                        },
                        success: function (response) {
                            if (response && response.success) {
                                $('#daily-limit-value-fe').text(response.data.limit);
                            }
                        },
                        error: function () {
                            alert('Erro ao salvar limite.');
                        }
                    });
                }

                $('#limit-plus-btn').on('click', function () {
                    var current = parseInt($('#daily-limit-value-fe').text()) || 30;
                    var newVal = current + 5;
                    $('#daily-limit-value-fe').text(newVal);
                    updateDailyLimit(newVal);
                });

                $('#limit-minus-btn').on('click', function () {
                    var current = parseInt($('#daily-limit-value-fe').text()) || 30;
                    var newVal = Math.max(5, current - 5);
                    $('#daily-limit-value-fe').text(newVal);
                    updateDailyLimit(newVal);
                });

                // Carrega vendedores ao iniciar
                loadVendorsList();

                // Auto-refresh a cada 30 segundos
                setInterval(loadVendorsList, 30000);
            });
        </script>
        <?php
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
}
