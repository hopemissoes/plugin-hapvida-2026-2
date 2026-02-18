<?php
if (!defined('ABSPATH')) exit;

trait AdminDeliveryTrait {

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
        $stats['webhook_results'] = $hapvida_delivery_tracking->get_last_processing_results(5);
        $stats['total_webhooks_received'] = count(get_option('hapvida_webhook_debug_log', array()));
        wp_send_json_success($stats);
    }

    // AJAX: Limpar registros de monitoramento de entregas
    public function ajax_clear_delivery_records()
    {
        global $hapvida_delivery_tracking;
        if (!$hapvida_delivery_tracking) {
            wp_send_json_error(array('message' => 'Delivery tracking não disponível'));
            return;
        }

        $hapvida_delivery_tracking->clear_pending_deliveries();
        $hapvida_delivery_tracking->clear_deactivation_log();

        wp_send_json_success(array('message' => 'Registros limpos com sucesso'));
    }

    // AJAX: Confirmar entrega manualmente (sem login)
    public function ajax_confirm_delivery()
    {
        global $hapvida_delivery_tracking;
        if (!$hapvida_delivery_tracking) {
            wp_send_json_error(array('message' => 'Delivery tracking não disponível'));
            return;
        }

        $lead_id = isset($_POST['lead_id']) ? sanitize_text_field($_POST['lead_id']) : '';
        if (empty($lead_id)) {
            wp_send_json_error(array('message' => 'lead_id não fornecido'));
            return;
        }

        $confirmed = $hapvida_delivery_tracking->manual_confirm_delivery($lead_id);
        if ($confirmed) {
            wp_send_json_success(array('message' => 'Entrega confirmada'));
        } else {
            wp_send_json_error(array('message' => 'Lead não encontrado ou já confirmado'));
        }
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
                <button id="clear-delivery-records" class="control-btn danger small" title="Limpar todos os registros de entregas">
                    <i class="fas fa-trash-alt"></i> Limpar
                </button>
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
        <div id="delivery-webhook-diagnostic"></div>

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

                if (!pendingData || pendingData.length === 0) {
                    container.innerHTML = '';
                    return;
                }

                var nowServer = Math.floor(Date.now() / 1000) + serverTimeDiff;
                var html = '<div class="delivery-pending-list"><h3><i class="fas fa-hourglass-half"></i> Entregas aguardando confirmacao</h3>';
                html += '<table class="delivery-table"><thead><tr><th>Vendedor</th><th>Grupo</th><th>Tempo restante</th><th>Status</th><th></th></tr></thead><tbody>';
                pendingData.forEach(function(p) {
                    var elapsed = nowServer - p.enviado_timestamp;
                    var remaining = Math.max(0, deliveryTimeout - elapsed);
                    var info = getStatusInfo(remaining);
                    html += '<tr><td>' + p.vendedor + '</td>';
                    html += '<td><span class="grupo-badge">' + p.grupo.toUpperCase() + '</span></td>';
                    html += '<td class="countdown-cell">' + formatCountdown(remaining) + '</td>';
                    html += '<td><span class="status-badge ' + info.cls + '">' + info.text + '</span></td>';
                    html += '<td><button class="confirm-delivery-btn" data-lead-id="' + p.lead_id + '" title="Confirmar que o vendedor recebeu"><i class="fas fa-check"></i></button></td></tr>';
                });
                html += '</tbody></table></div>';
                container.innerHTML = html;
            }

            function startCountdown() {
                if (countdownInterval) clearInterval(countdownInterval);
                if (pendingData.length > 0) {
                    countdownInterval = setInterval(renderPendingTable, 1000);
                }
            }

            function loadDeliveryStats() {
                var btn = document.getElementById('refresh-delivery-stats');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> Atualizando...';
                }

                var formData = new FormData();
                formData.append('action', 'get_delivery_stats');

                fetch(ajaxUrl, { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-sync-alt"></i> Atualizar';
                    }
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

                    // Diagnóstico de webhooks da Evolution API
                    var diagHtml = '';
                    var totalWh = d.total_webhooks_received || 0;
                    var results = d.webhook_results || [];
                    diagHtml = '<div class="webhook-diagnostic">';
                    diagHtml += '<h3><i class="fas fa-stethoscope"></i> Diagnostico Evolution API</h3>';
                    if (totalWh === 0) {
                        diagHtml += '<div class="diag-alert diag-warn"><i class="fas fa-exclamation-triangle"></i> Nenhum webhook recebido da Evolution API. Verifique se a URL <code>/wp-json/formulario-hapvida/v1/evolution-webhook</code> esta configurada na Evolution API.</div>';
                    } else {
                        diagHtml += '<div class="diag-alert diag-ok"><i class="fas fa-check-circle"></i> ' + totalWh + ' webhook(s) recebido(s) da Evolution API</div>';
                    }
                    if (results.length > 0) {
                        diagHtml += '<table class="delivery-table diag-table"><thead><tr><th>Quando</th><th>Telefone</th><th>Evento</th><th>Resultado</th></tr></thead><tbody>';
                        results.forEach(function(r) {
                            var cls = r.confirmed ? 'diag-row-ok' : 'diag-row-fail';
                            diagHtml += '<tr class="' + cls + '">';
                            diagHtml += '<td>' + r.timestamp + '</td>';
                            diagHtml += '<td><code>' + (r.phone || '-') + '</code></td>';
                            diagHtml += '<td>' + (r.event || '-') + '</td>';
                            diagHtml += '<td>' + r.message + '</td></tr>';
                        });
                        diagHtml += '</tbody></table>';
                    }
                    diagHtml += '</div>';
                    document.getElementById('delivery-webhook-diagnostic').innerHTML = diagHtml;
                })
                .catch(function() {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-sync-alt"></i> Atualizar';
                    }
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

            // Confirmar entrega manualmente
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.confirm-delivery-btn');
                if (!btn) return;
                var leadId = btn.getAttribute('data-lead-id');
                if (!leadId) return;
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                var formData = new FormData();
                formData.append('action', 'confirm_delivery');
                formData.append('lead_id', leadId);
                fetch(ajaxUrl, { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        loadDeliveryStats();
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-check"></i>';
                        alert('Erro: ' + (res.data && res.data.message ? res.data.message : 'Erro desconhecido'));
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check"></i>';
                });
            });

            // Carrega ao abrir e atualiza dados a cada 30 segundos
            loadDeliveryStats();
            setInterval(loadDeliveryStats, 30000);

            var refreshBtn = document.getElementById('refresh-delivery-stats');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', loadDeliveryStats);
            }

            // Limpar registros de entregas
            var clearBtn = document.getElementById('clear-delivery-records');
            if (clearBtn) {
                clearBtn.addEventListener('click', function() {
                    if (!confirm('Tem certeza que deseja limpar todos os registros de monitoramento de entregas?\n\nIsso vai remover:\n- Todas as entregas (pendentes, entregues, expiradas)\n- Todo o log de inativacoes automaticas\n\nEsta acao nao pode ser desfeita.')) return;
                    clearBtn.disabled = true;
                    clearBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Limpando...';
                    var formData = new FormData();
                    formData.append('action', 'clear_delivery_records');
                    fetch(ajaxUrl, { method: 'POST', body: formData })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        clearBtn.disabled = false;
                        clearBtn.innerHTML = '<i class="fas fa-trash-alt"></i> Limpar';
                        if (res.success) {
                            loadDeliveryStats();
                        } else {
                            alert('Erro ao limpar registros');
                        }
                    })
                    .catch(function() {
                        clearBtn.disabled = false;
                        clearBtn.innerHTML = '<i class="fas fa-trash-alt"></i> Limpar';
                        alert('Erro ao limpar registros');
                    });
                });
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

            .confirm-delivery-btn {
                background: #dcfce7;
                color: #166534;
                border: 1px solid #bbf7d0;
                border-radius: 8px;
                padding: 4px 10px;
                cursor: pointer;
                font-size: 13px;
                transition: all 0.2s;
            }
            .confirm-delivery-btn:hover {
                background: #bbf7d0;
                transform: translateY(-1px);
            }
            .confirm-delivery-btn:disabled {
                opacity: 0.5;
                cursor: not-allowed;
                transform: none;
            }

            .control-btn.danger {
                background: #fee2e2;
                color: #dc2626;
                border: 1px solid #fca5a5;
            }

            .control-btn.danger:hover {
                background: #fecaca;
                transform: translateY(-2px);
            }

            .webhook-diagnostic { margin-top: 20px; }
            .webhook-diagnostic h3 { font-size: 15px; color: #475569; margin-bottom: 10px; }
            .webhook-diagnostic h3 i { margin-right: 6px; }
            .diag-alert {
                padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 12px;
                display: flex; align-items: center; gap: 8px;
            }
            .diag-alert code { background: rgba(0,0,0,0.06); padding: 2px 6px; border-radius: 4px; font-size: 11px; }
            .diag-warn { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
            .diag-ok { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
            .diag-table { font-size: 12px; }
            .diag-table code { background: #f1f5f9; padding: 1px 5px; border-radius: 3px; font-size: 11px; }
            .diag-row-ok td { background: #f0fdf4; }
            .diag-row-fail td { background: #fef2f2; }

            @media (max-width: 600px) {
                .delivery-header-actions { flex-direction: column; gap: 10px; align-items: flex-end; }
                .delivery-stats-grid { grid-template-columns: 1fr; }
                .delivery-table { font-size: 12px; }
                .delivery-table th, .delivery-table td { padding: 8px 6px; }
            }
        </style>
        <?php
    }
}
