<?php
if (!defined('ABSPATH')) exit;

trait AdminScriptsTrait {

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
                                    <div style="font-size:48px;">❌</div>
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
            <div style="font-size:48px;">❌</div>
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
